<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Alerts;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\ErrorLog;
use Saqf\Core\Mailer;
use Saqf\Core\Policy;
use Saqf\Integration\Integrations;
use Saqf\Integration\Sync;
use Saqf\Core\Throttle;
use Saqf\Security\SecurityCenter;
use Throwable;

/**
 * Background heartbeat. In production `php bin/tick.php` runs every 5 minutes (the Docker
 * entrypoint does this; elsewhere use cron). As a safety net the web app also triggers it
 * when the heartbeat has stopped. Each run:
 *  - SIS (hourly): academic calendar and teaching assignments; starts a term automatically on its
 *    start date (the previous term is closed and its reports frozen)
 *  - LMS: imports newly published results (event-driven pipeline follows); remote LMS APIs every 30 min
 *  - daily: re-syncs the institutional catalogue, re-checks date-driven rules and program patterns
 *  - e-mails people about notifications that need them, and delivers the outgoing mail queue
 *  - daily: verifies the audit hash chain and prunes expired rate-limit counters
 * A failing step is logged (admin → Error log) and never stops the other steps. Steps that keep
 * failing (two runs in a row), a failed or missing backup and a broken audit chain raise IT alerts,
 * which clear themselves once the check passes again (see Alerts).
 */
final class Scheduler
{
    /** Alert kinds whose steps ran in this tick => list of error messages (empty = all passed). */
    private static array $checked = [];

    private const ALERTS = [
        'connector.sis' => 'Student information system sync is failing',
        'connector.lms' => 'LMS results import is failing',
        'connector.catalog' => 'Institutional catalogue sync is failing',
        'engine.rules' => 'Automatic quality checks are failing',
        'mail.delivery' => 'E-mail delivery is failing',
    ];

    public static function tick(bool $force = false, int $minInterval = 300): array
    {
        $last = self::setting('scheduler.last_tick');
        $now = Clock::now();
        if (!$force && $last && (strtotime((string) $last) > $now->getTimestamp() - $minInterval)) {
            return ['skipped' => true];
        }
        if (!self::lock()) {
            return ['skipped' => true];
        }
        try {
            self::put('scheduler.last_tick', $now->format('Y-m-d H:i:s'));
            $stats = ['lms_batches' => 0, 'offerings_checked' => 0, 'programs_checked' => 0, 'sis_terms' => 0, 'assignments' => 0,
                'workspaces_created' => 0, 'term_activated' => null, 'emails' => 0, 'errors' => 0];
            self::$checked = [];
            if (PHP_SAPI === 'cli') {
                Alerts::resolve('scheduler.stalled'); // the real scheduler (container loop or cron) is running
            }
            Audit::asSystem(static function () use (&$stats, $now, $force) {
                $live = !Integrations::simulated();

                // SIS: calendar, automatic semester rollover, teaching assignments ------------
                $lastSis = self::setting('scheduler.last_sis');
                if ($live && Policy::get('integration.sis_autosync') && ($force || !$lastSis || strtotime((string) $lastSis) <= $now->getTimestamp() - 3600)) {
                    self::put('scheduler.last_sis', $now->format('Y-m-d H:i:s'));
                    self::step($stats, static function () use (&$stats) {
                        $stats['sis_terms'] = Sync::terms();
                    }, 'connector.sis');
                    self::step($stats, static function () use (&$stats) {
                        $stats['term_activated'] = self::rollover();
                    });
                    self::step($stats, static function () use (&$stats) {
                        $active = Db::val('SELECT code FROM terms WHERE status = "active" ORDER BY sequence DESC LIMIT 1');
                        if ($active) {
                            $a = Sync::assignments((string) $active);
                            $stats['assignments'] = $a['assignments'];
                            $stats['workspaces_created'] += $a['created'];
                        }
                    }, 'connector.sis');
                }

                // LMS results (remote LMS APIs are polled every SAQF_LMS_POLL_MINUTES, default 30) ---------
                $offerings = Db::col('SELECT o.id FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE t.status = "active"');
                $poll = (int) (Config::get('SAQF_LMS_POLL_MINUTES') ?: (in_array(Integrations::lmsKind(), ['moodle', 'blackboard'], true) ? 30 : 0));
                $lastLms = self::setting('scheduler.last_lms');
                if (Policy::get('integration.lms_autosync') && ($force || $poll === 0 || !$lastLms || strtotime((string) $lastLms) <= $now->getTimestamp() - $poll * 60)) {
                    self::put('scheduler.last_lms', $now->format('Y-m-d H:i:s'));
                    foreach ($offerings as $oid) {
                        self::step($stats, static function () use (&$stats, $oid) {
                            $stats['lms_batches'] += Achievement::syncFromLms((int) $oid);
                        }, 'connector.lms');
                    }
                }

                // Daily ----------------------------------------------------------------------
                $lastDaily = self::setting('scheduler.last_daily');
                if (!$lastDaily || substr((string) $lastDaily, 0, 10) !== $now->format('Y-m-d')) {
                    self::put('scheduler.last_daily', $now->format('Y-m-d H:i:s'));
                    if ($live && Policy::get('integration.catalog_autosync')) {
                        self::step($stats, static fn() => Sync::institution(), 'connector.catalog');
                    }
                    foreach ($offerings as $oid) {
                        self::step($stats, static fn() => Engine::evaluateOffering((int) $oid), 'engine.rules');
                        $stats['offerings_checked']++;
                    }
                    foreach (Db::col('SELECT id FROM programs') as $pid) {
                        self::step($stats, static fn() => Engine::evaluateProgram((int) $pid), 'engine.rules');
                        $stats['programs_checked']++;
                    }
                    // Tamper evidence: the whole audit chain is re-verified every night.
                    self::step($stats, static function () {
                        $v = Audit::verify();
                        self::put('audit.last_verify', (string) json_encode(['at' => Clock::stamp(), 'ok' => $v['ok'], 'checked' => $v['checked'], 'message' => $v['message']]));
                        $v['ok'] ? Alerts::resolve('audit.chain')
                            : Alerts::raise('audit.chain', 'critical', 'Audit log integrity check failed', $v['message'] . ' Restore the audit_log table from the last good backup and investigate database access.');
                    });
                    // The protections are tried for real every night; a failure is an IT alert.
                    self::step($stats, static function () {
                        $r = \Saqf\Security\SelfTest::runAndStore();
                        $failed = array_map(static fn($t) => $t['name'], array_filter($r['tests'], static fn($t) => !$t['ok']));
                        $failed ? Alerts::raise('security.selftest', 'critical', 'Security self-test failed', implode('; ', $failed) . '. Open Administration → Security center and press "Run security self-test" for details.')
                            : Alerts::resolve('security.selftest');
                    });
                    self::step($stats, static function () {
                        $p = \Saqf\Security\AccessReview::progress();
                        $p['due'] ? Alerts::raise('security.access_review', 'warning', 'Access review overdue', $p['due'] . ' of ' . $p['total'] . ' account(s) have not been confirmed by an administrator in the last ' . $p['days'] . ' days. Open Administration → Access review.')
                            : Alerts::resolve('security.access_review');
                    });
                    self::step($stats, static fn() => Throttle::prune());
                }

                // Password guessing: the alert clears after an hour without throttled attempts.
                self::step($stats, static function () {
                    $hour = Clock::now()->modify('-1 hour')->format('Y-m-d H:i:s');
                    if (!(int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE reason = "ip_throttled" AND created_at >= ?', [$hour])) {
                        Alerts::resolve('security.bruteforce');
                    }
                });

                // Backups (written by the backup service; checked on every run) ----------------
                self::step($stats, static function () {
                    $ok = SecurityCenter::backupOk();
                    if ($ok === false) {
                        Alerts::raise('backup.failed', 'critical', 'Database backup missing or failed', SecurityCenter::backupLine());
                    } else {
                        Alerts::resolve('backup.failed');
                    }
                });

                // E-mail ---------------------------------------------------------------------
                if (Mailer::enabled()) {
                    self::step($stats, static function () use (&$stats) {
                        Mailer::queueNotificationDigests();
                        $stats['emails'] = Mailer::flush();
                        if (Mailer::$lastFailures && !$stats['emails']) {
                            throw new \RuntimeException(Mailer::$lastFailures . ' e-mail(s) could not be sent: ' . Mailer::$lastError);
                        }
                    }, 'mail.delivery');
                }
                self::reportAlerts();
            });
            return $stats;
        } finally {
            self::unlock();
        }
    }

    /**
     * Automatic semester rollover driven by the SIS calendar: once a term's start date has
     * arrived it is activated (previous term closed, reports frozen, workspaces created from
     * the SIS assignments). Terms the calendar has already moved past are closed unused.
     * @return string|null name of the term activated
     */
    public static function rollover(): ?string
    {
        if (!Policy::get('term.auto_activate')) {
            return null;
        }
        $next = Db::one('SELECT * FROM terms WHERE status = "upcoming" AND starts_on <= ? ORDER BY sequence DESC LIMIT 1', [Clock::today()]);
        if (!$next) {
            return null;
        }
        $active = Db::one('SELECT * FROM terms WHERE status = "active" ORDER BY sequence DESC LIMIT 1');
        if ($active && (int) $active['sequence'] >= (int) $next['sequence']) {
            return null;
        }
        Workspaces::activateTerm((int) $next['id']);
        foreach (Db::all('SELECT id, name FROM terms WHERE status = "upcoming" AND sequence < ?', [$next['sequence']]) as $skipped) {
            Db::update('terms', ['status' => 'closed'], 'id = ?', [$skipped['id']]);
            Audit::record('term.skipped', 'term', $skipped['id'], "{$skipped['name']} closed without activation (the SIS calendar has moved on to {$next['name']})");
        }
        return (string) $next['name'];
    }

    private static function step(array &$stats, callable $fn, ?string $alert = null): void
    {
        if ($alert !== null) {
            self::$checked[$alert] ??= [];
        }
        try {
            $fn();
        } catch (Throwable $e) {
            $stats['errors']++;
            ErrorLog::record($e, 'error');
            if ($alert !== null) {
                self::$checked[$alert][] = $e->getMessage();
            }
        }
    }

    /**
     * Raises an alert for a step that failed on two runs in a row (one network blip is not worth
     * waking IT for) and clears it as soon as the step succeeds again.
     */
    private static function reportAlerts(): void
    {
        foreach (self::$checked as $kind => $errors) {
            $streakKey = 'alert.streak.' . $kind;
            if (!$errors) {
                if (self::setting($streakKey) !== null) {
                    Db::exec('DELETE FROM system_settings WHERE setting_key = ?', [$streakKey]);
                }
                Alerts::resolve($kind);
                continue;
            }
            $streak = (int) self::setting($streakKey) + 1;
            self::put($streakKey, (string) $streak);
            if ($streak >= 2) {
                $unique = array_values(array_unique($errors));
                Alerts::raise($kind, 'warning', self::ALERTS[$kind] ?? $kind, count($errors) . ' failure(s) in the last scheduler run, ' . $streak . ' runs in a row. '
                    . mb_strimwidth(implode(' · ', array_slice($unique, 0, 3)), 0, 900, '…') . ' Details: System administration → Error log.');
            }
        }
    }

    /**
     * Called by the web safety net: when the real scheduler (container loop or cron) has not run
     * for 30 minutes in production, IT is alerted — the web keeps things moving meanwhile.
     */
    public static function checkHeartbeat(): void
    {
        if (\Saqf\Core\Config::demoMode() || \Saqf\Core\Config::env() !== 'production') {
            return;
        }
        $last = self::setting('scheduler.last_tick');
        if ($last && strtotime($last) < Clock::now()->getTimestamp() - 1800) {
            Alerts::raise('scheduler.stalled', 'warning', 'Background scheduler has stopped', 'The scheduler last ran at ' . $last . '. Check the app container (it runs bin/tick.php every 5 minutes) or the server cron job. Pages keep triggering it as a safety net meanwhile.');
        }
    }

    private static function setting(string $key): ?string
    {
        $v = Db::val('SELECT value FROM system_settings WHERE setting_key = ?', [$key]);
        return $v === null ? null : (string) $v;
    }

    private static function put(string $key, string $value): void
    {
        Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$key, $value, Clock::stamp()]);
    }

    /** One scheduler at a time (cron, container loop and web safety net may overlap). */
    private static function lock(): bool
    {
        try {
            return (int) Db::val('SELECT GET_LOCK(?, 0)', [self::lockName()]) === 1;
        } catch (Throwable $e) {
            return true; // engines without named locks: the last_tick guard still applies
        }
    }

    private static function unlock(): void
    {
        try {
            Db::val('SELECT RELEASE_LOCK(?)', [self::lockName()]);
        } catch (Throwable $e) {
        }
    }

    private static function lockName(): string
    {
        return 'saqf.scheduler.' . Db::val('SELECT DATABASE()');
    }
}

<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\ErrorLog;
use Saqf\Core\Mailer;
use Saqf\Core\Policy;
use Saqf\Integration\Integrations;
use Saqf\Integration\Sync;
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
 * A failing step is logged (admin → Error log) and never stops the other steps.
 */
final class Scheduler
{
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
            Audit::asSystem(static function () use (&$stats, $now, $force) {
                $live = !Integrations::simulated();

                // SIS: calendar, automatic semester rollover, teaching assignments ------------
                $lastSis = self::setting('scheduler.last_sis');
                if ($live && Policy::get('integration.sis_autosync') && ($force || !$lastSis || strtotime((string) $lastSis) <= $now->getTimestamp() - 3600)) {
                    self::put('scheduler.last_sis', $now->format('Y-m-d H:i:s'));
                    self::step($stats, static function () use (&$stats) {
                        $stats['sis_terms'] = Sync::terms();
                    });
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
                    });
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
                        });
                    }
                }

                // Daily ----------------------------------------------------------------------
                $lastDaily = self::setting('scheduler.last_daily');
                if (!$lastDaily || substr((string) $lastDaily, 0, 10) !== $now->format('Y-m-d')) {
                    self::put('scheduler.last_daily', $now->format('Y-m-d H:i:s'));
                    if ($live && Policy::get('integration.catalog_autosync')) {
                        self::step($stats, static fn() => Sync::institution());
                    }
                    foreach ($offerings as $oid) {
                        self::step($stats, static fn() => Engine::evaluateOffering((int) $oid));
                        $stats['offerings_checked']++;
                    }
                    foreach (Db::col('SELECT id FROM programs') as $pid) {
                        self::step($stats, static fn() => Engine::evaluateProgram((int) $pid));
                        $stats['programs_checked']++;
                    }
                }

                // E-mail ---------------------------------------------------------------------
                if (Mailer::enabled()) {
                    self::step($stats, static function () use (&$stats) {
                        Mailer::queueNotificationDigests();
                        $stats['emails'] = Mailer::flush();
                    });
                }
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

    private static function step(array &$stats, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $stats['errors']++;
            ErrorLog::record($e, 'error');
        }
    }

    private static function setting(string $key): ?string
    {
        $v = Db::val('SELECT value FROM system_settings WHERE setting_key = ?', [$key]);
        return $v === null ? null : (string) $v;
    }

    private static function put(string $key, string $value): void
    {
        Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$key, $value, $value]);
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

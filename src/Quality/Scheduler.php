<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Policy;

/**
 * Background heartbeat. In production run `php bin/tick.php` from cron every 5 minutes.
 * As a safety net the web app also triggers it opportunistically (at most every 5 minutes).
 *  - imports newly published LMS results (event-driven pipeline follows)
 *  - re-checks date-driven rules (overdue results / improvement deadlines)
 *  - re-evaluates program-level patterns daily
 */
final class Scheduler
{
    public static function tick(bool $force = false): array
    {
        $last = Db::val('SELECT value FROM system_settings WHERE setting_key = "scheduler.last_tick"');
        $now = Clock::now();
        if (!$force && $last && (strtotime((string) $last) > $now->getTimestamp() - 300)) {
            return ['skipped' => true];
        }
        Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("scheduler.last_tick", ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]);
        $stats = ['lms_batches' => 0, 'offerings_checked' => 0, 'programs_checked' => 0];
        Audit::asSystem(static function () use (&$stats, $now) {
            $offerings = Db::col('SELECT o.id FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE t.status = "active"');
            if (Policy::get('integration.lms_autosync')) {
                foreach ($offerings as $oid) {
                    $stats['lms_batches'] += Achievement::syncFromLms((int) $oid);
                }
            }
            $lastDaily = Db::val('SELECT value FROM system_settings WHERE setting_key = "scheduler.last_daily"');
            if (!$lastDaily || substr((string) $lastDaily, 0, 10) !== $now->format('Y-m-d')) {
                foreach ($offerings as $oid) {
                    Engine::evaluateOffering((int) $oid);
                    $stats['offerings_checked']++;
                }
                foreach (Db::col('SELECT id FROM programs') as $pid) {
                    Engine::evaluateProgram((int) $pid);
                    $stats['programs_checked']++;
                }
                Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("scheduler.last_daily", ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]);
            }
        });
        return $stats;
    }
}

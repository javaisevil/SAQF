<?php
declare(strict_types=1);

namespace Saqf\Core;

use Saqf\Integration\Http;
use Throwable;

/**
 * Operational alerts for IT: a connector that keeps failing, a stalled scheduler, missing or failed
 * backups, a broken audit chain, mail that cannot be delivered, blocked malware. One row per kind;
 * an alert re-opens when the problem returns and resolves itself when the check passes again.
 *
 * Administrators are told in SAQF (and by e-mail through the notification digest) when an alert
 * opens and again every 6 hours while it stays open. SAQF_ALERT_WEBHOOK, when set, also receives a
 * JSON POST {"text": "..."} (Microsoft Teams and Slack incoming webhooks accept this format).
 */
final class Alerts
{
    public const REMIND_HOURS = 6;

    public static function raise(string $kind, string $severity, string $title, string $detail = ''): void
    {
        try {
            $now = Clock::stamp();
            $row = Db::one('SELECT * FROM alerts WHERE kind = ?', [$kind]);
            if (!$row) {
                Db::insert('alerts', ['kind' => $kind, 'severity' => $severity, 'title' => mb_substr($title, 0, 200), 'detail' => mb_substr($detail, 0, 2000), 'first_at' => $now, 'last_at' => $now, 'occurrences' => 1]);
            } elseif ($row['resolved_at'] !== null) {
                Db::update('alerts', ['severity' => $severity, 'title' => mb_substr($title, 0, 200), 'detail' => mb_substr($detail, 0, 2000), 'first_at' => $now, 'last_at' => $now, 'occurrences' => 1, 'notified_at' => null, 'resolved_at' => null], 'id = ?', [$row['id']]);
            } else {
                Db::exec('UPDATE alerts SET last_at = ?, occurrences = occurrences + 1, severity = ?, title = ?, detail = ? WHERE id = ?', [$now, $severity, mb_substr($title, 0, 200), mb_substr($detail, 0, 2000), $row['id']]);
            }
            self::deliver($kind);
        } catch (Throwable $e) {
            error_log('SAQF alert could not be recorded: ' . $e->getMessage());
        }
    }

    public static function resolve(string $kind): void
    {
        try {
            $row = Db::one('SELECT * FROM alerts WHERE kind = ? AND resolved_at IS NULL', [$kind]);
            if ($row) {
                Db::update('alerts', ['resolved_at' => Clock::stamp()], 'id = ?', [$row['id']]);
                Audit::asSystem(static fn() => Audit::record('alert.resolved', 'system', $kind, "Alert cleared automatically: {$row['title']}"));
            }
        } catch (Throwable $e) {
            // alerts table may not exist yet during an upgrade
        }
    }

    /** @return list<array> open alerts, most severe first */
    public static function open(): array
    {
        try {
            return Db::all('SELECT * FROM alerts WHERE resolved_at IS NULL ORDER BY FIELD(severity, "critical", "warning", "info"), last_at DESC');
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return list<array> */
    public static function recent(int $limit = 20): array
    {
        return Db::all('SELECT * FROM alerts ORDER BY COALESCE(resolved_at, last_at) DESC LIMIT ' . max(1, $limit));
    }

    /** Notifies administrators about a new alert, and reminds them while it stays open. */
    private static function deliver(string $kind): void
    {
        $a = Db::one('SELECT * FROM alerts WHERE kind = ?', [$kind]);
        if (!$a || $a['resolved_at'] !== null) {
            return;
        }
        if ($a['notified_at'] !== null && strtotime((string) $a['notified_at']) > Clock::now()->getTimestamp() - self::REMIND_HOURS * 3600) {
            return;
        }
        Db::update('alerts', ['notified_at' => Clock::stamp()], 'id = ?', [$a['id']]);
        Notify::role('admin', null, null, 'alert', 'IT alert: ' . $a['title'], mb_strimwidth((string) $a['detail'], 0, 380, '…'), 'admin.php?tab=alerts', 'alert:' . $kind . ':' . $a['first_at'] . ':' . Clock::stamp());
        Audit::asSystem(static fn() => Audit::record('alert.raised', 'system', $kind, ucfirst($a['severity']) . " alert: {$a['title']}", null, ['occurrences' => (int) $a['occurrences']]));
        $hook = trim((string) Config::get('SAQF_ALERT_WEBHOOK', ''));
        if ($hook !== '') {
            try {
                Http::request('POST', $hook, ['Content-Type' => 'application/json'], (string) json_encode(['text' => 'SAQF ' . strtoupper($a['severity']) . ': ' . $a['title'] . ($a['detail'] ? "\n" . mb_strimwidth((string) $a['detail'], 0, 600, '…') : '')]), 10);
            } catch (Throwable $e) {
                error_log('SAQF alert webhook failed: ' . $e->getMessage());
            }
        }
    }
}

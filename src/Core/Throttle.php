<?php
declare(strict_types=1);

namespace Saqf\Core;

use RuntimeException;

/**
 * Fixed-window rate limits stored in the database (works across PHP workers and containers).
 * Used for API changes, uploads and exports, on top of the sign-in throttling in Auth.
 * Windows run on real time, never the demo clock.
 */
final class Throttle
{
    /** Records a hit; returns false when the limit for the window is already reached. */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $bucket = mb_substr($key, 0, 120);
        $now = time();
        return (bool) Db::tx(static function () use ($bucket, $max, $windowSeconds, $now) {
            $row = Db::one('SELECT * FROM rate_limits WHERE bucket = ? FOR UPDATE', [$bucket]);
            if (!$row || strtotime((string) $row['window_start']) <= $now - $windowSeconds) {
                Db::exec('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE window_start = VALUES(window_start), hits = 1', [$bucket, date('Y-m-d H:i:s', $now)]);
                return true;
            }
            if ((int) $row['hits'] >= $max) {
                return false;
            }
            Db::exec('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
            return true;
        });
    }

    /** Throws (and records a security event) when over the limit. */
    public static function check(string $key, int $max, int $windowSeconds, string $message): void
    {
        if (!self::hit($key, $max, $windowSeconds)) {
            Audit::record('security.rate_limited', 'access', null, 'Rate limit reached: ' . mb_substr($key, 0, 80), null, ['limit' => $max, 'window_s' => $windowSeconds]);
            throw new RuntimeException($message);
        }
    }

    /** Housekeeping: windows older than a day are dropped (scheduler, daily). */
    public static function prune(): int
    {
        return Db::exec('DELETE FROM rate_limits WHERE window_start < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    }
}

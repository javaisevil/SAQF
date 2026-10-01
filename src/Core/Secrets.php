<?php
declare(strict_types=1);

namespace Saqf\Core;

/**
 * Installation secret used to derive pseudonymous student keys (HMAC). Set SAQF_APP_KEY
 * in the environment to manage it externally; otherwise one is generated on first use
 * and kept in the database, so keys stay stable across restarts and upgrades.
 */
final class Secrets
{
    private static ?string $key = null;

    public static function appKey(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $env = (string) Config::get('SAQF_APP_KEY', '');
        if ($env !== '') {
            return self::$key = $env;
        }
        $stored = Db::val('SELECT value FROM system_settings WHERE setting_key = "app.key"');
        if (!$stored) {
            $stored = bin2hex(random_bytes(32));
            Db::exec('INSERT IGNORE INTO system_settings (setting_key, value, updated_at) VALUES ("app.key", ?, ?)', [$stored, Clock::stamp()]);
            $stored = Db::val('SELECT value FROM system_settings WHERE setting_key = "app.key"');
        }
        return self::$key = (string) $stored;
    }

    /** Stable pseudonymous key for a person in an external system (never reversible). */
    public static function pseudonym(string $system, string $id): string
    {
        return 'S' . strtoupper(substr(hash_hmac('sha256', $system . ':' . $id, self::appKey()), 0, 15));
    }
}

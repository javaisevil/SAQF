<?php
declare(strict_types=1);

namespace Saqf\Core;

/**
 * Installation secret used to derive pseudonymous student keys (HMAC) and to encrypt stored secrets
 * (two-step verification keys). Set SAQF_APP_KEY
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

    /** Encrypts a small secret for storage (AES-256-GCM, key derived from the application key). */
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = (string) openssl_encrypt($plain, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    /** @return string|null null when the value cannot be decrypted (wrong key or tampered) */
    public static function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, 'v1:')) {
            return null;
        }
        $raw = (string) base64_decode(substr($stored, 3), true);
        if (strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    private static function encryptionKey(): string
    {
        return hash_hmac('sha256', 'saqf:encryption:v1', self::appKey(), true);
    }

    /** Stable pseudonymous key for a person in an external system (never reversible). */
    public static function pseudonym(string $system, string $id): string
    {
        return 'S' . strtoupper(substr(hash_hmac('sha256', $system . ':' . $id, self::appKey()), 0, 15));
    }
}

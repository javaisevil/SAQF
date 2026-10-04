<?php
declare(strict_types=1);

namespace Saqf\Core;

use RuntimeException;

/**
 * Installation secret used to derive pseudonymous student keys (HMAC), to sign robot-check puzzles
 * and e-mailed codes, and to encrypt stored secrets (two-step verification keys).
 *
 * Production: the key MUST come from the environment (SAQF_APP_KEY, at least 32 random characters,
 * e.g. `openssl rand -hex 32`), kept in the university's password vault and backed up separately
 * from the database. SAQF never generates or stores a production key. An installation that still
 * has a key stored in the database by an older version keeps working (pseudonyms and encrypted
 * secrets depend on it) but is reported as not ready until the key is moved out (bin/app_key.php).
 *
 * Local/demo: when SAQF_APP_KEY is unset, a random key is generated on first use and kept in the
 * database, so a developer machine works without setup.
 *
 * Rotating the key is NOT supported in place: it changes every student pseudonym and makes stored
 * two-step verification keys unreadable (see docs/OPERATIONS.md, "Application key").
 */
final class Secrets
{
    public const MIN_KEY_LENGTH = 32;

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
        $stored = self::storedKey();
        if ($stored !== null) {
            return self::$key = $stored; // legacy or development key; production readiness reports it
        }
        if (Config::env() === 'production') {
            throw new RuntimeException('SAQF_APP_KEY is not set. In production the application key must be supplied from the environment (generate one with: php bin/app_key.php generate, keep it in the password vault) — SAQF does not create one.');
        }
        Db::exec('INSERT IGNORE INTO system_settings (setting_key, value, updated_at) VALUES ("app.key", ?, ?)', [bin2hex(random_bytes(32)), Clock::stamp()]);
        return self::$key = (string) self::storedKey();
    }

    /** Forgets the cached key (tests, and after the configuration changes in a long-running process). */
    public static function reset(): void
    {
        self::$key = null;
    }

    /** Key kept in the database by local/demo mode or by SAQF before 2.5, if any. */
    public static function storedKey(): ?string
    {
        $v = Db::val('SELECT value FROM system_settings WHERE setting_key = "app.key"');
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Why a key is not acceptable for production (null when it is). */
    public static function keyProblem(string $key): ?string
    {
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            return 'it is shorter than ' . self::MIN_KEY_LENGTH . ' characters';
        }
        if (count(array_unique(str_split($key))) < 10) {
            return 'it repeats too few different characters to be random';
        }
        if (preg_match('/change.?me|example|placeholder|your.?key|secret.?key/i', $key)) {
            return 'it looks like a placeholder';
        }
        return null;
    }

    /**
     * Where the key comes from and whether that is acceptable here. Never returns the key.
     * @return array{source:string,ok:?bool,message:string}
     *   source: environment | database | none; ok: true ready, false not ready, null development
     */
    public static function keyStatus(): array
    {
        $prod = Config::env() === 'production';
        $env = (string) Config::get('SAQF_APP_KEY', '');
        if ($env !== '') {
            $problem = self::keyProblem($env);
            if ($problem !== null) {
                return ['source' => 'environment', 'ok' => $prod ? false : null, 'message' => 'SAQF_APP_KEY is set but too weak: ' . $problem . '.'];
            }
            $stored = self::storedKey();
            if ($stored !== null && !hash_equals($stored, $env)) {
                return ['source' => 'environment', 'ok' => $prod ? false : null, 'message' => 'SAQF_APP_KEY differs from the key an earlier installation stored in the database: student pseudonyms and two-step keys made with that key will no longer match. Use the stored key (php bin/app_key.php export-stored) rather than a new one.'];
            }
            $copy = $stored !== null ? ' A copy is still stored in the database: remove it with php bin/app_key.php forget-stored.' : '';
            return ['source' => 'environment', 'ok' => $copy === '' ? true : ($prod ? false : null), 'message' => 'Supplied from the environment (SAQF_APP_KEY); not stored by SAQF.' . $copy];
        }
        if (self::storedKey() !== null) {
            return $prod
                ? ['source' => 'database', 'ok' => false, 'message' => 'The key is stored in the application database (an older installation). Move it to SAQF_APP_KEY and the password vault: php bin/app_key.php (see docs/OPERATIONS.md).']
                : ['source' => 'database', 'ok' => null, 'message' => 'Development key generated by SAQF and kept in the database (fine for a demo; production needs SAQF_APP_KEY).'];
        }
        return $prod
            ? ['source' => 'none', 'ok' => false, 'message' => 'SAQF_APP_KEY is not set: sign-in, pseudonymisation and two-step verification cannot work. Generate one with php bin/app_key.php generate.']
            : ['source' => 'none', 'ok' => null, 'message' => 'No key yet; a development key is generated on first use.'];
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

    /** Stable pseudonymous key for a person in an external system (never reversible without the key). */
    public static function pseudonym(string $system, string $id): string
    {
        return 'S' . strtoupper(substr(hash_hmac('sha256', $system . ':' . $id, self::appKey()), 0, 15));
    }
}

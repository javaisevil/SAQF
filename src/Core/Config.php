<?php
declare(strict_types=1);

namespace Saqf\Core;

/**
 * Reads configuration from environment variables first, then config.local.php.
 * Secrets never live in the repository.
 */
final class Config
{
    private static array $local = [];

    public static function load(): void
    {
        $file = SAQF_ROOT . '/config.local.php';
        if (is_file($file)) {
            $data = require $file;
            if (is_array($data)) {
                self::$local = $data;
            }
        }
    }

    /**
     * Settings that may be supplied as a file instead of a value (the Docker / Kubernetes secrets
     * pattern): SAQF_DB_PASS_FILE=/run/secrets/saqf_db_pass keeps the secret out of the process
     * environment, `docker inspect` output and process listings. An explicit value wins over the file.
     */
    public const SECRET_KEYS = [
        'SAQF_APP_KEY', 'SAQF_DB_PASS', 'SAQF_OIDC_CLIENT_SECRET', 'SAQF_SIS_TOKEN', 'SAQF_SIS_CLIENT_SECRET', 'SAQF_LMS_TOKEN', 'SAQF_LMS_CLIENT_SECRET', 'SAQF_MOODLE_TOKEN',
        'SAQF_BLACKBOARD_KEY', 'SAQF_BLACKBOARD_SECRET', 'SAQF_MAIL_PASSWORD', 'SAQF_BACKUP_PASSPHRASE', 'SAQF_ALERT_WEBHOOK',
    ];

    public static function get(string $key, $default = null)
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        if (in_array($key, self::SECRET_KEYS, true) && ($fromFile = self::fromFile($key)) !== null) {
            return $fromFile;
        }
        return array_key_exists($key, self::$local) ? self::$local[$key] : $default;
    }

    /** Content of the file named by <KEY>_FILE (trailing newline removed), or null. Regular, small files only. */
    private static function fromFile(string $key): ?string
    {
        $path = getenv($key . '_FILE');
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path) || filesize($path) > 8192) {
            return null;
        }
        $v = rtrim((string) file_get_contents($path), "\r\n");
        return $v === '' ? null : $v;
    }

    /** True when the setting is supplied from a secrets file (for the Security center; never the value). */
    public static function isFromFile(string $key): bool
    {
        $env = getenv($key);
        return ($env === false || $env === '') && in_array($key, self::SECRET_KEYS, true) && self::fromFile($key) !== null;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, null);
        if ($v === null) {
            return $default;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function env(): string
    {
        return (string) self::get('APP_ENV', 'local');
    }

    /** Demo mode exposes demo accounts and the integration simulator. Never on in production. */
    public static function demoMode(): bool
    {
        return self::env() !== 'production' && self::bool('SAQF_DEMO', true);
    }

    public static function debug(): bool
    {
        return self::bool('APP_DEBUG', self::env() === 'local');
    }
}

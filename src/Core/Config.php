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

    public static function get(string $key, $default = null)
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return array_key_exists($key, self::$local) ? self::$local[$key] : $default;
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

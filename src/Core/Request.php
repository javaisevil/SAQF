<?php
declare(strict_types=1);

namespace Saqf\Core;

final class Request
{
    private static string $id = 'cli';

    public static function boot(): void
    {
        self::$id = substr(bin2hex(random_bytes(8)), 0, 12);
    }

    public static function id(): string
    {
        return self::$id;
    }

    public static function ip(): string
    {
        // Behind a trusted reverse proxy set SAQF_TRUST_PROXY=true to use X-Forwarded-For.
        if (Config::bool('SAQF_TRUST_PROXY') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (Config::bool('SAQF_TRUST_PROXY') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = $_GET[$key] ?? $_POST[$key] ?? null;
        $f = filter_var($v, FILTER_VALIDATE_INT);
        return $f === false || $f === null ? $default : (int) $f;
    }

    public static function str(string $key, string $default = '', int $max = 5000): string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        if (!is_string($v)) {
            return $default;
        }
        $v = trim($v);
        return mb_substr($v, 0, $max);
    }

    public static function enum(string $key, array $allowed, string $default): string
    {
        $v = self::str($key, $default, 60);
        return in_array($v, $allowed, true) ? $v : $default;
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    }

    public static function base(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
        return rtrim($dir, '/');
    }

    public static function url(string $path = ''): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }
}

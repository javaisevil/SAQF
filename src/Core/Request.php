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

    /**
     * Client address. Behind a reverse proxy, X-Forwarded-For is used only when the connection comes
     * from a trusted proxy: SAQF_TRUSTED_PROXIES (addresses/CIDR ranges, e.g. the HTTPS proxy
     * container) or, for older set-ups, SAQF_TRUST_PROXY=true (trust any).
     */
    public static function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        if (self::fromTrustedProxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // The right-most address not belonging to a trusted proxy is the client.
            $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
            $trusted = (string) Config::get('SAQF_TRUSTED_PROXIES', '');
            foreach ($chain as $hop) {
                if (filter_var($hop, FILTER_VALIDATE_IP) && ($trusted === '' || !self::inRanges($hop, $trusted))) {
                    return $hop;
                }
            }
        }
        return $remote;
    }

    public static function fromTrustedProxy(): bool
    {
        $trusted = (string) Config::get('SAQF_TRUSTED_PROXIES', '');
        if ($trusted !== '') {
            return self::inRanges((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $trusted);
        }
        return Config::bool('SAQF_TRUST_PROXY');
    }

    /** True when $ip is in a comma-separated list of addresses / CIDR ranges (IPv4 and IPv6). */
    public static function inRanges(string $ip, string $ranges): bool
    {
        $addr = @inet_pton($ip);
        if ($addr === false) {
            return false;
        }
        foreach (array_filter(array_map('trim', explode(',', $ranges))) as $range) {
            [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
            $netAddr = @inet_pton((string) $net);
            if ($netAddr === false || strlen($netAddr) !== strlen($addr)) {
                continue;
            }
            $bits = $bits === null ? strlen($addr) * 8 : max(0, min(strlen($addr) * 8, (int) $bits));
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($addr, 0, $bytes) !== substr($netAddr, 0, $bytes)) {
                continue;
            }
            if ($rest === 0 || ((ord($addr[$bytes]) ^ ord($netAddr[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (self::fromTrustedProxy() && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
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

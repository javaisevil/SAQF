<?php
declare(strict_types=1);

namespace Saqf\Integration;

use RuntimeException;

/**
 * Minimal HTTPS client for the university-system connectors and SSO (curl, TLS verification
 * always on, bounded timeouts). Errors raise RuntimeException with a message safe to show
 * administrators — credentials and tokens are never included.
 */
final class Http
{
    /** @return array{status:int,body:string} */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('Connector URL must start with https:// (or http:// for a local test server).');
        }
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'SAQF/' . (defined('SAQF_VERSION') ? SAQF_VERSION : '2') . ' (+academic quality automation)',
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Could not reach ' . self::host($url) . ': ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $resp];
    }

    /** GET/POST expecting JSON; non-2xx or invalid JSON raise RuntimeException. */
    public static function json(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $r = self::request($method, $url, $headers + ['Accept' => 'application/json'], $body);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new RuntimeException(self::host($url) . ' answered HTTP ' . $r['status'] . self::detail($r['body']));
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException(self::host($url) . ' did not return JSON.');
        }
        return $data;
    }

    public static function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: 'remote system');
    }

    private static function detail(string $body): string
    {
        $data = json_decode($body, true);
        $msg = is_array($data) ? ($data['message'] ?? $data['error_description'] ?? $data['error'] ?? null) : null;
        return is_string($msg) ? ' (' . mb_substr($msg, 0, 160) . ')' : '';
    }
}

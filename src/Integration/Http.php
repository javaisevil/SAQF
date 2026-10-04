<?php
declare(strict_types=1);

namespace Saqf\Integration;

use RuntimeException;
use Saqf\Core\Config;

/**
 * Minimal HTTPS client for the university-system connectors, SSO and the alert webhook (curl, TLS
 * verification always on, no redirects, bounded timeouts). Errors raise RuntimeException with a
 * message safe to show administrators — credentials and tokens are never included.
 *
 * Production (APP_ENV=production) accepts https:// only, for every request: the SIS and LMS APIs,
 * the identity provider (including the endpoints its discovery document names) and the webhook.
 * Local and demo mode also accept http:// so the stand-in servers in tests/mock can be used.
 */
final class Http
{
    /** Largest answer SAQF will read from a remote system. */
    public const MAX_BYTES = 33554432;

    /** Settings holding outbound addresses, checked by readiness() (labels for the administrator). */
    public const URL_SETTINGS = [
        'SAQF_SIS_URL' => 'SIS integration API',
        'SAQF_LMS_URL' => 'LMS integration API',
        'SAQF_MOODLE_URL' => 'Moodle',
        'SAQF_BLACKBOARD_URL' => 'Blackboard Learn',
        'SAQF_OIDC_ISSUER' => 'University sign-in (identity provider)',
        'SAQF_ALERT_WEBHOOK' => 'IT alert webhook',
        'SAQF_BASE_URL' => 'SAQF public address',
    ];

    /** Why SAQF refuses to call this address here (null when it is acceptable). */
    public static function urlProblem(string $url, ?bool $production = null): ?string
    {
        $production ??= Config::env() === 'production';
        if (!preg_match('#^https?://[^/\s]+#i', $url)) {
            return 'it must start with https://';
        }
        if ($production && stripos($url, 'https://') !== 0) {
            return 'plain http:// is not allowed in production (use https://)';
        }
        return null;
    }

    /**
     * Configured outbound addresses that would be refused or are unsafe in production.
     * @return list<array{setting:string,label:string,problem:string}>
     */
    public static function readiness(): array
    {
        $out = [];
        foreach (self::URL_SETTINGS as $key => $label) {
            $url = (string) Config::get($key, '');
            if ($url === '') {
                continue;
            }
            $problem = self::urlProblem($url, true); // judged by the production rule, whatever the mode
            if ($problem !== null) {
                $out[] = ['setting' => $key, 'label' => $label, 'problem' => $problem];
            }
        }
        return $out;
    }

    /** @return array{status:int,body:string} */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $production = Config::env() === 'production';
        if (($problem = self::urlProblem($url, $production)) !== null) {
            throw new RuntimeException('Refused to contact ' . self::host($url) . ': the address is not acceptable (' . $problem . ').');
        }
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        // A remote system can never make SAQF hold more than MAX_BYTES of one answer in memory.
        $resp = '';
        $tooBig = false;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$resp, &$tooBig): int {
                $resp .= $chunk;
                if (strlen($resp) > self::MAX_BYTES) {
                    $tooBig = true;
                    return 0;
                }
                return strlen($chunk);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'SAQF/' . (defined('SAQF_VERSION') ? SAQF_VERSION : '2') . ' (+academic quality automation)',
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_PROTOCOLS => $production ? CURLPROTO_HTTPS : (CURLPROTO_HTTPS | CURLPROTO_HTTP),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $done = curl_exec($ch);
        if ($tooBig) {
            curl_close($ch);
            throw new RuntimeException(self::host($url) . ' answered with more than ' . (self::MAX_BYTES / 1048576) . ' MB, which SAQF refuses to read.');
        }
        if ($done === false) {
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

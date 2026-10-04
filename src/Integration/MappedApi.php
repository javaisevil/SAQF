<?php
declare(strict_types=1);

namespace Saqf\Integration;

use RuntimeException;
use Saqf\Core\Config;

/**
 * The calling half of a mapped connector (the transformation half is Mapping): builds the request from the mapping,
 * authenticates, follows paging, and hands back the raw rows. Read-only by construction: only GET requests are sent
 * to the university's API (the single POST is the OAuth2 token request, when the mapping asks for one).
 *
 * Settings (SYS is SIS or LMS):  SAQF_SYS_MAPPING  path of the mapping file
 *                                SAQF_SYS_URL      base address of the API (https:// in production)
 *                                SAQF_SYS_TOKEN    bearer token or API key        (auth.type bearer | header)
 *                                SAQF_SYS_CLIENT_ID / SAQF_SYS_CLIENT_SECRET      (auth.type oauth2)
 * Every secret may also be given as a file (…_FILE); see docs/OPERATIONS.md.
 */
final class MappedApi
{
    private const MAX_ROWS = 200000;

    /** @var array<string,array{token:string,until:int}> OAuth2 tokens, per process */
    private static array $tokens = [];

    private function __construct(private string $sys, private array $mapping, private string $base)
    {
    }

    public static function forSystem(string $sys, ?string $mappingPath = null, ?string $base = null): self
    {
        $sys = strtoupper($sys);
        $path = $mappingPath ?? (string) Config::get("SAQF_{$sys}_MAPPING", '');
        if ($path === '') {
            throw new RuntimeException("SAQF_{$sys}_MAPPING is not set: name the mapping file for this university's API (see docs/INTEGRATIONS.md).");
        }
        if ($path[0] !== '/') {
            $path = SAQF_ROOT . '/' . $path;
        }
        $mapping = Mapping::load($path, $sys === 'SIS' ? 'sis' : 'lms');
        return new self($sys, $mapping, rtrim($base ?? (string) Config::get("SAQF_{$sys}_URL", ''), '/'));
    }

    public function mapping(): array
    {
        return $this->mapping;
    }

    public function name(): string
    {
        return (string) $this->mapping['name'] . (!empty($this->mapping['simulated']) ? ' — SIMULATED API, synthetic data' : '');
    }

    public function host(): string
    {
        return $this->base !== '' ? Http::host($this->base) : "SAQF_{$this->sys}_URL not set";
    }

    /**
     * All raw rows of one mapped section, across pages. $vars fill the {placeholders} of the path.
     * @return list<array>|null null when the API answers with one of the section's "empty_statuses" (e.g. 404: no such course)
     */
    public function rows(string $name, array $vars = []): ?array
    {
        if ($this->base === '') {
            throw new RuntimeException("SAQF_{$this->sys}_URL is not set.");
        }
        $section = $this->mapping[$name];
        $path = (string) preg_replace_callback('/\{(\w+)\}/', static fn($m) => rawurlencode((string) ($vars[$m[1]] ?? '')), $section['path']);
        $query = $section['query'] ?? [];
        $pg = $section['pagination'] ?? ['type' => 'none'];
        $type = $pg['type'] ?? 'none';
        $size = (int) ($pg['size'] ?? 100);
        $max = (int) ($pg['max_pages'] ?? 100);
        $all = [];
        $seen = [];
        $page = (int) ($pg['start'] ?? 1);
        $offset = 0;
        $cursor = null;
        $next = null;
        for ($n = 0; $n < $max; $n++) {
            $q = $query;
            $url = $this->base . $path;
            if ($type === 'page') {
                $q[$pg['param'] ?? 'page'] = $page;
                if (isset($pg['size_param'])) {
                    $q[$pg['size_param']] = $size;
                }
            } elseif ($type === 'offset') {
                $q[$pg['param'] ?? 'offset'] = $offset;
                $q[$pg['size_param'] ?? 'limit'] = $size;
            } elseif ($type === 'cursor' && $cursor !== null) {
                $q[$pg['param']] = $cursor;
            } elseif ($type === 'next_link' && $next !== null) {
                $url = $this->sameHost($next);
                $q = [];
            }
            $resp = $this->get($url . ($q ? (str_contains($url, '?') ? '&' : '?') . http_build_query($q) : ''), $section);
            if ($resp === null) {
                return $n === 0 ? null : $all;
            }
            $hash = sha1((string) json_encode($resp));
            if (isset($seen[$hash])) {
                throw new RuntimeException('The API sent the same page twice for "' . $name . '": the pagination settings of the mapping do not match how the API pages.');
            }
            $seen[$hash] = true;
            $items = Mapping::items($resp, $section, $name);
            array_push($all, ...$items);
            if (count($all) > self::MAX_ROWS) {
                throw new RuntimeException('"' . $name . '" has more than ' . self::MAX_ROWS . ' rows; SAQF refuses to read so much in one go.');
            }
            $more = false;
            switch ($type) {
                case 'page':
                    $page++;
                    $more = $items && (!isset($pg['size_param']) || count($items) >= $size);
                    break;
                case 'offset':
                    $offset += count($items);
                    $more = $items && count($items) >= $size;
                    break;
                case 'cursor':
                    $c = Mapping::get($resp, (string) $pg['next_path']);
                    $cursor = is_scalar($c) && (string) $c !== '' ? (string) $c : null;
                    $more = $cursor !== null;
                    break;
                case 'next_link':
                    $l = Mapping::get($resp, (string) $pg['next_path']);
                    $next = is_string($l) && $l !== '' ? $l : null;
                    $more = $next !== null;
                    break;
            }
            if (!$more) {
                return $all;
            }
        }
        throw new RuntimeException('"' . $name . '" was still paging after ' . $max . ' pages; SAQF stopped so that nothing is silently left out. Raise pagination.max_pages if that many pages is expected.');
    }

    /** One request to the API's own "check" path, or null when the mapping has none. @return array{ok:bool,message:string}|null */
    public function ping(): ?array
    {
        if (empty($this->mapping['check']['path'])) {
            return null;
        }
        if ($this->base === '') {
            throw new RuntimeException("SAQF_{$this->sys}_URL is not set.");
        }
        $this->get($this->base . $this->mapping['check']['path'], ['empty_statuses' => []]);
        return ['ok' => true, 'message' => 'The API at ' . $this->host() . ' answered the check request (' . $this->mapping['name'] . ').'];
    }

    /** Authenticated GET; the answer as an array, or null for an "empty" status. */
    private function get(string $url, array $section): ?array
    {
        $r = Http::request('GET', $url, $this->headers() + ['Accept' => 'application/json']);
        if (in_array($r['status'], (array) ($section['empty_statuses'] ?? []), true)) {
            return null;
        }
        if ($r['status'] === 401 || $r['status'] === 403) {
            throw new RuntimeException(Http::host($url) . ' refused the credentials (HTTP ' . $r['status'] . '). Check the token or client settings for ' . $this->sys . '.');
        }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new RuntimeException(Http::host($url) . ' answered HTTP ' . $r['status'] . '.');
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException(Http::host($url) . ' did not return JSON.');
        }
        return $data;
    }

    /** Pagination links are followed only on the host SAQF was configured to call (no credentials sent elsewhere). */
    private function sameHost(string $link): string
    {
        $b = parse_url($this->base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if ($link !== '' && $link[0] === '/' && !str_starts_with($link, '//')) {
            return $origin . $link;
        }
        $l = parse_url($link);
        if (!$l || ($l['scheme'] ?? '') !== ($b['scheme'] ?? '') || ($l['host'] ?? '') !== ($b['host'] ?? '') || ($l['port'] ?? null) !== ($b['port'] ?? null)) {
            throw new RuntimeException('The API named a next page on another host; SAQF does not follow it (credentials would leave the configured system).');
        }
        return $link;
    }

    private function headers(): array
    {
        $auth = $this->mapping['auth'] ?? ['type' => 'bearer'];
        $token = (string) Config::get("SAQF_{$this->sys}_TOKEN", '');
        switch ($auth['type'] ?? 'bearer') {
            case 'none':
                return [];
            case 'header':
                $this->need($token !== '', "SAQF_{$this->sys}_TOKEN");
                return [$auth['name'] => $token];
            case 'oauth2':
                return ['Authorization' => 'Bearer ' . $this->oauthToken($auth)];
            default:
                $this->need($token !== '', "SAQF_{$this->sys}_TOKEN");
                return ['Authorization' => 'Bearer ' . $token];
        }
    }

    private function need(bool $present, string ...$settings): void
    {
        if (!$present) {
            throw new RuntimeException(implode(' and ', $settings) . (count($settings) > 1 ? ' are' : ' is') . ' not set.');
        }
    }

    /** OAuth2 client-credentials token, kept in memory until shortly before it expires. */
    private function oauthToken(array $auth): string
    {
        $id = (string) Config::get("SAQF_{$this->sys}_CLIENT_ID", '');
        $secret = (string) Config::get("SAQF_{$this->sys}_CLIENT_SECRET", '');
        $this->need($id !== '' && $secret !== '', "SAQF_{$this->sys}_CLIENT_ID", "SAQF_{$this->sys}_CLIENT_SECRET");
        $key = sha1($auth['token_url'] . "\0" . $id);
        if (isset(self::$tokens[$key]) && self::$tokens[$key]['until'] > time() + 30) {
            return self::$tokens[$key]['token'];
        }
        $form = ['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret] + (isset($auth['scope']) ? ['scope' => $auth['scope']] : []);
        $resp = Http::json('POST', $auth['token_url'], ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($form));
        $token = $resp['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException(Http::host($auth['token_url']) . ' did not return an access token.');
        }
        self::$tokens[$key] = ['token' => $token, 'until' => time() + max(60, min(3600, (int) ($resp['expires_in'] ?? 300)))];
        return $token;
    }

    public static function forgetTokens(): void
    {
        self::$tokens = [];
    }
}

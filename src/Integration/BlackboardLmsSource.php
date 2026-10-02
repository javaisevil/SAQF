<?php
declare(strict_types=1);

namespace Saqf\Integration;

use RuntimeException;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Secrets;

/**
 * Blackboard Learn connector (public REST API, OAuth2 client credentials).
 * Settings: SAQF_BLACKBOARD_URL, SAQF_BLACKBOARD_KEY, SAQF_BLACKBOARD_SECRET (a REST application
 * registered in the Developer Portal and enabled by the Learn administrator with read access to
 * courses and gradebook), SAQF_LMS_COURSE_KEY (pattern for the course externalId).
 * Gradebook columns are converted to percentages of their points possible; students are pseudonymised.
 */
final class BlackboardLmsSource implements LmsSource
{
    private string $base;
    private string $key;
    private string $secret;
    private ?string $token = null;
    private int $tokenExpires = 0;

    public function __construct(?string $base = null, ?string $key = null, ?string $secret = null)
    {
        $this->base = rtrim($base ?? (string) Config::get('SAQF_BLACKBOARD_URL', ''), '/');
        $this->key = $key ?? (string) Config::get('SAQF_BLACKBOARD_KEY', '');
        $this->secret = $secret ?? (string) Config::get('SAQF_BLACKBOARD_SECRET', '');
    }

    public function label(): string
    {
        return 'Blackboard Learn (' . ($this->base !== '' ? Http::host($this->base) : 'SAQF_BLACKBOARD_URL not set') . ')';
    }

    public function batches(string $termCode, string $courseCode, ?string $section = null): array
    {
        $key = Integrations::lmsCourseKey($termCode, $courseCode, $section);
        try {
            $course = $this->get('/learn/api/public/v3/courses/externalId:' . rawurlencode($key));
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return []; // course not in Learn (yet)
            }
            throw $e;
        }
        $courseId = rawurlencode((string) $course['id']);
        $results = [];
        foreach ($this->paged("/learn/api/public/v2/courses/$courseId/gradebook/columns") as $col) {
            $possible = (float) ($col['score']['possible'] ?? 0);
            $name = trim((string) ($col['name'] ?? ''));
            if ($possible <= 0 || $name === '' || !empty($col['externalGrade']) || ($col['availability']['available'] ?? 'Yes') === 'No') {
                continue;
            }
            foreach ($this->paged("/learn/api/public/v2/courses/$courseId/gradebook/columns/" . rawurlencode((string) $col['id']) . '/users') as $g) {
                if (!isset($g['score']) || ($g['status'] ?? 'Graded') !== 'Graded') {
                    continue;
                }
                $results[$name][Secrets::pseudonym('blackboard', (string) $g['userId'])] = round(max(0.0, min(100.0, (float) $g['score'] / $possible * 100)), 2);
            }
        }
        if (!$results) {
            return [];
        }
        ksort($results);
        return [[
            'ref' => 'BB-' . substr(sha1((string) $course['id']), 0, 10) . '-' . substr(sha1(json_encode($results)), 0, 20),
            'published_at' => Clock::stamp(),
            'label' => 'Blackboard gradebook (' . $key . ')',
            'results' => $results,
        ]];
    }

    public function pending(): array
    {
        return [];
    }

    public function check(): array
    {
        try {
            $v = $this->get('/learn/api/public/v1/system/version');
            $learn = $v['learn'] ?? [];
            return ['ok' => true, 'message' => 'Connected to Blackboard Learn ' . implode('.', array_filter([$learn['major'] ?? null, $learn['minor'] ?? null, $learn['patch'] ?? null], 'is_numeric'))];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function paged(string $path): array
    {
        $out = [];
        $next = $path . (str_contains($path, '?') ? '&' : '?') . 'limit=200';
        for ($page = 0; $next && $page < 50; $page++) {
            $data = $this->get($next);
            array_push($out, ...($data['results'] ?? []));
            $next = $data['paging']['nextPage'] ?? null;
        }
        return $out;
    }

    private function get(string $path): array
    {
        return Http::json('GET', $this->base . $path, ['Authorization' => 'Bearer ' . $this->token()]);
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExpires) {
            return $this->token;
        }
        if ($this->base === '' || $this->key === '' || $this->secret === '') {
            throw new RuntimeException('SAQF_BLACKBOARD_URL, SAQF_BLACKBOARD_KEY and SAQF_BLACKBOARD_SECRET must be configured.');
        }
        $data = Http::json('POST', $this->base . '/learn/api/public/v1/oauth2/token', [
            'Authorization' => 'Basic ' . base64_encode($this->key . ':' . $this->secret),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], 'grant_type=client_credentials');
        if (empty($data['access_token'])) {
            throw new RuntimeException('Blackboard did not issue an access token.');
        }
        $this->token = (string) $data['access_token'];
        $this->tokenExpires = time() + max(60, (int) ($data['expires_in'] ?? 3600)) - 30;
        return $this->token;
    }
}

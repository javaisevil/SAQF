<?php
declare(strict_types=1);

namespace Saqf\Integration;

use RuntimeException;
use Saqf\Core\Config;

/**
 * SIS connector over HTTPS/JSON for universities with an integration layer (e.g. Ellucian
 * Ethos, MuleSoft or an in-house API gateway in front of Banner/PeopleSoft). Contract:
 *   GET {SAQF_SIS_URL}/terms                     → [ {code,name,academic_year,sequence,starts_on,ends_on,grades_due_on}, … ]
 *   GET {SAQF_SIS_URL}/terms/{code}/assignments  → [ {course,instructor_id,instructor_name,instructor_email,department,sections,enrolled}, … ]
 * Both may also wrap the list as {"terms": […]} / {"assignments": […]}.
 * Authentication: Authorization: Bearer {SAQF_SIS_TOKEN}.
 */
final class RestSisSource implements SisSource
{
    private string $base;
    private string $token;

    public function __construct(?string $base = null, ?string $token = null)
    {
        $this->base = rtrim($base ?? (string) Config::get('SAQF_SIS_URL', ''), '/');
        $this->token = $token ?? (string) Config::get('SAQF_SIS_TOKEN', '');
    }

    public function label(): string
    {
        return 'SIS API (' . ($this->base !== '' ? Http::host($this->base) : 'SAQF_SIS_URL not set') . ')';
    }

    public function terms(): array
    {
        $data = $this->get('/terms');
        return array_map([FileSisSource::class, 'term'], $data['terms'] ?? $data);
    }

    public function assignments(string $termCode): array
    {
        $data = $this->get('/terms/' . rawurlencode($termCode) . '/assignments');
        return array_map(static fn($r) => FileSisSource::assignment($r + ['term' => $termCode]), $data['assignments'] ?? $data);
    }

    public function check(): array
    {
        try {
            return ['ok' => true, 'message' => count($this->terms()) . ' term(s) available from ' . Http::host($this->base)];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function get(string $path): array
    {
        if ($this->base === '') {
            throw new RuntimeException('SAQF_SIS_URL is not configured.');
        }
        $headers = $this->token !== '' ? ['Authorization' => 'Bearer ' . $this->token] : [];
        $data = Http::json('GET', $this->base . $path, $headers);
        $list = $data['terms'] ?? $data['assignments'] ?? $data;
        if (!array_is_list($list)) {
            throw new RuntimeException('SIS API returned an unexpected shape for ' . $path . ' (expected a list).');
        }
        return $data;
    }
}

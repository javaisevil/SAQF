<?php
declare(strict_types=1);

namespace Saqf\Integration;

use RuntimeException;

/**
 * SIS connector for a university API whose JSON does not look like SAQF's own contract: a mapping file says where
 * terms and teaching assignments are in the answers (see Mapping, docs/INTEGRATIONS.md, docs/mappings/). The same
 * strict normalisation as every other SIS source runs afterwards (dates, course codes, section codes).
 *   SAQF_SIS_SOURCE=mapped  SAQF_SIS_MAPPING=…  SAQF_SIS_URL=…  SAQF_SIS_TOKEN=…
 */
final class MappedSisSource implements SisSource
{
    private ?MappedApi $api = null;

    public function __construct(private ?string $mappingPath = null, private ?string $base = null)
    {
    }

    public function label(): string
    {
        try {
            return 'SIS API through mapping "' . $this->api()->name() . '" (' . $this->api()->host() . ')';
        } catch (RuntimeException $e) {
            return 'SIS API through a mapping file (not usable yet)';
        }
    }

    public function terms(): array
    {
        $raw = $this->api()->rows('terms') ?? [];
        return array_map([FileSisSource::class, 'term'], Mapping::mapRows($this->api()->mapping(), 'terms', $raw));
    }

    public function assignments(string $termCode): array
    {
        $raw = $this->api()->rows('assignments', ['term' => $termCode]) ?? [];
        return array_map(static fn(array $r) => FileSisSource::assignment($r + ['term' => $termCode]), Mapping::mapRows($this->api()->mapping(), 'assignments', $raw));
    }

    public function check(): array
    {
        try {
            $ping = $this->api()->ping();
            $n = count($this->terms());
            return ['ok' => true, 'message' => ($ping['message'] ?? '') . ($ping ? ' ' : '') . $n . ' term(s) read and mapped from ' . $this->api()->host() . '.'];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function api(): MappedApi
    {
        return $this->api ??= MappedApi::forSystem('SIS', $this->mappingPath, $this->base);
    }
}

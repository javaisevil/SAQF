<?php
declare(strict_types=1);

namespace Saqf\Integration;

use RuntimeException;
use Saqf\Core\Clock;

/**
 * LMS connector for a university API that exposes a gradebook as rows of student × assessment × mark, described by a
 * mapping file (see Mapping, docs/INTEGRATIONS.md, docs/mappings/). Student identifiers are replaced by keyed
 * pseudonyms while the rows are read (Mapping::grades), the same key as the manual upload and the folder source, so
 * all grade routes agree and none can store a student number.
 *   SAQF_LMS_SOURCE=mapped  SAQF_LMS_MAPPING=…  SAQF_LMS_URL=…  SAQF_LMS_TOKEN=…  SAQF_LMS_COURSE_KEY={term}-{code_nospace}
 */
final class MappedLmsSource implements LmsSource
{
    private ?MappedApi $api = null;

    public function __construct(private ?string $mappingPath = null, private ?string $base = null)
    {
    }

    public function label(): string
    {
        try {
            return 'LMS API through mapping "' . $this->api()->name() . '" (' . $this->api()->host() . ')';
        } catch (RuntimeException $e) {
            return 'LMS API through a mapping file (not usable yet)';
        }
    }

    public function batches(string $termCode, string $courseCode, ?string $section = null): array
    {
        $vars = [
            'term' => $termCode, 'code' => $courseCode, 'code_nospace' => str_replace(' ', '', $courseCode),
            'course_key' => Integrations::lmsCourseKey($termCode, $courseCode, $section), 'section' => (string) $section,
        ];
        $raw = $this->api()->rows('grades', $vars);
        if (!$raw) {
            return [];
        }
        $g = Mapping::grades($this->api()->mapping(), $raw);
        if (!$g['results']) {
            return [];
        }
        return [[
            'ref' => 'MAP-' . substr(sha1($vars['course_key'] . json_encode($g['results'])), 0, 28),
            'published_at' => Clock::stamp(),
            'label' => 'Gradebook through mapping "' . $this->api()->mapping()['name'] . '" (' . $vars['course_key'] . ')',
            'results' => $g['results'],
            'sections' => $g['sections'],
        ]];
    }

    public function pending(): array
    {
        return [];
    }

    public function check(): array
    {
        try {
            $ping = $this->api()->ping();
            if ($ping === null) {
                return ['ok' => false, 'message' => 'The mapping is valid, but the connection cannot be tested: add "check": {"path": "/…"} (a harmless endpoint such as /health) to the mapping.'];
            }
            return $ping;
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function api(): MappedApi
    {
        return $this->api ??= MappedApi::forSystem('LMS', $this->mappingPath, $this->base);
    }
}

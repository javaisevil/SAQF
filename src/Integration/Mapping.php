<?php
declare(strict_types=1);

namespace Saqf\Integration;

use DateTimeImmutable;
use RuntimeException;
use Saqf\Core\Secrets;
use Saqf\Quality\Sections;

/**
 * Declarative mapping of a university's own JSON API onto SAQF's contract (docs/INTEGRATIONS.md, "Mapped API").
 * A mapping is DATA, never code: where each list lives in the response, which JSON path feeds which SAQF field,
 * and a short whitelist of conversions (date formats, upper/lower case, grades to percentages, value tables).
 * Nothing in a mapping file is executed, and the same strict checks as every other source run after it
 * (dates, course codes, section codes), so a mapping can describe an API but cannot widen what SAQF accepts.
 *
 * This class is pure: no network, no database. MappedSisSource and MappedLmsSource do the calling;
 * bin/mapping_check.php runs a mapping against a saved sample response without any network at all.
 */
final class Mapping
{
    public const MAX_FILE_BYTES = 65536;
    public const AUTH_TYPES = ['bearer', 'header', 'oauth2', 'none'];
    public const PAGINATION = ['none', 'page', 'offset', 'cursor', 'next_link'];
    public const TRANSFORMS = ['trim', 'upper', 'lower', 'int', 'float', 'bool', 'course', 'digits'];

    /** Fields each section must (or may) map. */
    public const FIELDS = [
        'terms' => ['required' => ['code', 'name', 'academic_year', 'sequence', 'starts_on', 'ends_on', 'grades_due_on'], 'optional' => []],
        'assignments' => ['required' => ['course'], 'optional' => ['instructor_id', 'instructor_name', 'instructor_email', 'department', 'sections', 'enrolled', 'section', 'coordinator']],
        'grades' => ['required' => ['student', 'assessment'], 'optional' => ['score', 'max', 'percent', 'section']],
    ];

    /** Placeholders a request path may use, per section (all values are URL-encoded when substituted). */
    public const PLACEHOLDERS = ['terms' => [], 'assignments' => ['term'], 'grades' => ['term', 'code', 'code_nospace', 'course_key', 'section'], 'check' => []];

    private const RESERVED_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection', 'cookie', 'set-cookie', 'proxy-authorization', 'te', 'upgrade'];

    /** Loads and validates a mapping file for "sis" (terms + assignments) or "lms" (grades). */
    public static function load(string $path, string $system): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The mapping file ' . basename($path) . ' cannot be read.');
        }
        clearstatcache(true, $path);
        if (filesize($path) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('The mapping file ' . basename($path) . ' is larger than ' . (self::MAX_FILE_BYTES / 1024) . ' KB.');
        }
        $m = json_decode((string) file_get_contents($path), true);
        if (!is_array($m)) {
            throw new RuntimeException('The mapping file ' . basename($path) . ' is not valid JSON (' . json_last_error_msg() . ').');
        }
        $problems = self::problems($m, $system);
        if ($problems) {
            throw new RuntimeException('The mapping ' . basename($path) . ' has ' . count($problems) . ' problem(s): ' . implode(' ', array_slice($problems, 0, 5)));
        }
        return $m;
    }

    /** @return list<string> every problem found (empty = usable) */
    public static function problems(array $m, string $system): array
    {
        $p = [];
        if (($m['version'] ?? null) !== 1) {
            $p[] = '"version" must be 1.';
        }
        if (!is_string($m['name'] ?? null) || trim($m['name']) === '' || mb_strlen($m['name']) > 120) {
            $p[] = '"name" is required (up to 120 characters).';
        }
        $auth = $m['auth'] ?? ['type' => 'bearer'];
        if (!is_array($auth) || !in_array($auth['type'] ?? '', self::AUTH_TYPES, true)) {
            $p[] = '"auth.type" must be one of: ' . implode(', ', self::AUTH_TYPES) . ' (a token in the address is not supported).';
        } elseif ($auth['type'] === 'header' && (!is_string($auth['name'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9-]{0,39}$/', $auth['name']) || in_array(strtolower($auth['name']), self::RESERVED_HEADERS, true))) {
            $p[] = '"auth.name" must be a plain header name such as X-Api-Key.';
        } elseif ($auth['type'] === 'oauth2') {
            $why = is_string($auth['token_url'] ?? null) ? Http::urlProblem($auth['token_url']) : 'it is missing';
            if ($why !== null) {
                $p[] = '"auth.token_url" must be an https:// address (' . $why . ').';
            }
            if (isset($auth['scope']) && (!is_string($auth['scope']) || !preg_match('/^[\x21\x23-\x5B\x5D-\x7E ]{1,200}$/', $auth['scope']))) {
                $p[] = '"auth.scope" is not a valid scope string.';
            }
        }
        $sections = $system === 'lms' ? ['grades'] : ['terms', 'assignments'];
        foreach ($sections as $name) {
            $s = $m[$name] ?? null;
            if (!is_array($s)) {
                $p[] = "\"$name\" is required for a " . strtoupper($system) . ' mapping.';
                continue;
            }
            self::sectionProblems($name, $s, $p);
        }
        if (isset($m['check'])) {
            if (!is_array($m['check']) || !self::pathOk($m['check']['path'] ?? null, 'check')) {
                $p[] = '"check.path" must be a path on the API such as /health.';
            }
        }
        return $p;
    }

    private static function sectionProblems(string $name, array $s, array &$p): void
    {
        if (!self::pathOk($s['path'] ?? null, $name)) {
            $p[] = "\"$name.path\" must be a path on the API (starting with /) using only " . ($name === 'terms' ? 'no placeholders' : '{' . implode('}, {', self::PLACEHOLDERS[$name]) . '}') . '.';
        }
        if (!self::jsonPathOk($s['list'] ?? '')) {
            $p[] = "\"$name.list\" must be a dotted path such as data.items (empty when the response is the list itself).";
        }
        if (isset($s['query'])) {
            $okQuery = is_array($s['query']);
            foreach ((array) $s['query'] as $k => $v) {
                $okQuery = $okQuery && is_string($k) && preg_match('/^[A-Za-z0-9_.\-\[\]]{1,40}$/', $k) && (is_string($v) || is_int($v)) && !preg_match('/(token|secret|key|password|auth)/i', $k);
            }
            if (!$okQuery) {
                $p[] = "\"$name.query\" must be plain name/value pairs (credentials never go in the address).";
            }
        }
        $fields = $s['fields'] ?? null;
        if (!is_array($fields)) {
            $p[] = "\"$name.fields\" is required.";
        } else {
            $allowed = array_merge(self::FIELDS[$name]['required'], self::FIELDS[$name]['optional']);
            foreach (self::FIELDS[$name]['required'] as $f) {
                if (!array_key_exists($f, $fields)) {
                    $p[] = "\"$name.fields.$f\" is required.";
                }
            }
            foreach ($fields as $f => $spec) {
                if (!in_array($f, $allowed, true)) {
                    $p[] = "\"$name.fields.$f\" is not a SAQF field (allowed: " . implode(', ', $allowed) . ').';
                } elseif ($spec !== null && ($why = self::specProblem($spec)) !== null) {
                    $p[] = "\"$name.fields.$f\": $why";
                }
            }
            if ($name === 'grades' && empty($fields['percent']) && (empty($fields['score']) || empty($fields['max']))) {
                $p[] = '"grades.fields" needs "percent", or "score" together with "max", to work out a percentage.';
            }
        }
        $pg = $s['pagination'] ?? ['type' => 'none'];
        if (!is_array($pg) || !in_array($pg['type'] ?? 'none', self::PAGINATION, true)) {
            $p[] = "\"$name.pagination.type\" must be one of: " . implode(', ', self::PAGINATION) . '.';
        } else {
            $type = $pg['type'] ?? 'none';
            foreach (['param', 'size_param'] as $k) {
                if (isset($pg[$k]) && !preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', (string) $pg[$k])) {
                    $p[] = "\"$name.pagination.$k\" is not a valid parameter name.";
                }
            }
            if (isset($pg['size']) && (!is_int($pg['size']) || $pg['size'] < 1 || $pg['size'] > 1000)) {
                $p[] = "\"$name.pagination.size\" must be a whole number from 1 to 1000.";
            }
            if (isset($pg['max_pages']) && (!is_int($pg['max_pages']) || $pg['max_pages'] < 1 || $pg['max_pages'] > 500)) {
                $p[] = "\"$name.pagination.max_pages\" must be a whole number from 1 to 500.";
            }
            if (in_array($type, ['cursor', 'next_link'], true) && !self::jsonPathOk($pg['next_path'] ?? '', false)) {
                $p[] = "\"$name.pagination.next_path\" is required for $type paging (where the response names the next page).";
            }
            if ($type === 'cursor' && !isset($pg['param'])) {
                $p[] = "\"$name.pagination.param\" is required for cursor paging.";
            }
        }
        if ($name === 'grades' && isset($s['skip_assessments']) && (!is_array($s['skip_assessments']) || count($s['skip_assessments']) > 50 || array_filter($s['skip_assessments'], static fn($v) => !is_string($v) || trim($v) === '' || mb_strlen($v) > 160))) {
            $p[] = '"grades.skip_assessments" must list up to 50 column names to leave out (for example the LMS\'s own total column).';
        }
        if (isset($s['empty_statuses']) && (!is_array($s['empty_statuses']) || array_diff($s['empty_statuses'], [204, 404]))) {
            $p[] = "\"$name.empty_statuses\" may only list 204 and/or 404 (the answers that mean \"nothing there\").";
        }
    }

    private static function pathOk($path, string $section): bool
    {
        if (!is_string($path) || !preg_match('#^/[A-Za-z0-9._~\-/{}%]*$#', $path) || str_contains($path, '..') || strlen($path) > 200) {
            return false;
        }
        preg_match_all('/\{([^}]*)\}/', $path, $m);
        return !array_diff($m[1], self::PLACEHOLDERS[$section] ?? []) && substr_count($path, '{') === count($m[1]) && substr_count($path, '}') === count($m[1]);
    }

    private static function jsonPathOk($path, bool $allowEmpty = true): bool
    {
        if ($path === '' || $path === null) {
            return $allowEmpty;
        }
        return is_string($path) && strlen($path) <= 120 && preg_match('/^[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+|\[\d{1,4}\])*(?:\[\d{1,4}\])*$/', $path) === 1;
    }

    /** Why a field specification is wrong (null when fine). */
    private static function specProblem($spec): ?string
    {
        if (is_string($spec)) {
            return self::jsonPathOk($spec, false) ? null : "\"$spec\" is not a valid path (use dots, e.g. staff.id).";
        }
        if (!is_array($spec)) {
            return 'must be a path or an object.';
        }
        $known = ['path', 'const', 'join', 'sep', 'default', 'transform', 'map', 'required', 'format'];
        if ($unknown = array_diff(array_keys($spec), $known)) {
            return 'unknown key ' . implode(', ', array_map('strval', $unknown)) . '.';
        }
        if (isset($spec['path']) && !self::jsonPathOk($spec['path'], false)) {
            return 'its "path" is not valid.';
        }
        if (isset($spec['join'])) {
            if (!is_array($spec['join']) || !$spec['join'] || count($spec['join']) > 6) {
                return '"join" must list up to 6 paths.';
            }
            foreach ($spec['join'] as $j) {
                if (!self::jsonPathOk($j, false)) {
                    return '"join" contains an invalid path.';
                }
            }
        }
        if (!isset($spec['path']) && !isset($spec['join']) && !array_key_exists('const', $spec)) {
            return 'needs a "path", a "join" or a "const".';
        }
        foreach ((array) ($spec['transform'] ?? []) as $t) {
            if (!is_string($t)) {
                return 'a transform must be text.';
            }
            if (str_starts_with($t, 'date:')) {
                if (!preg_match('/^date:[dDjmnYyHisMF\/\-\. :T]{3,30}$/', $t)) {
                    return "\"$t\" is not a supported date format (letters d m Y y j n H i s and separators / - . : space T).";
                }
            } elseif (!in_array($t, self::TRANSFORMS, true)) {
                return "unknown transform \"$t\" (allowed: " . implode(', ', self::TRANSFORMS) . ', date:<format>).';
            }
        }
        if (isset($spec['map']) && (!is_array($spec['map']) || count($spec['map']) > 200 || array_filter($spec['map'], static fn($v) => !is_scalar($v) && $v !== null))) {
            return '"map" must be a table of up to 200 plain values.';
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------------------
    // Reading a response

    /** The value at a dotted path ("a.b[0].c"), or null when anything along the way is missing. */
    public static function get($data, string $path)
    {
        if ($path === '') {
            return $data;
        }
        foreach (preg_split('/\.|(?=\[)/', $path, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $seg) {
            $key = preg_match('/^\[(\d+)\]$/', $seg, $m) ? (int) $m[1] : $seg;
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return $data;
    }

    /** The list a section's rows live in. @return list<array> */
    public static function items(array $response, array $section, string $name): array
    {
        $list = self::get($response, (string) ($section['list'] ?? ''));
        if (!is_array($list) || ($list && !array_is_list($list))) {
            throw new RuntimeException("The response has no list at \"$name.list\" (" . (($section['list'] ?? '') === '' ? 'the top level' : $section['list']) . '). Check the mapping against a real response with bin/mapping_check.php.');
        }
        foreach ($list as $i => $row) {
            if (!is_array($row)) {
                throw new RuntimeException("Row " . ($i + 1) . " of \"$name\" is not an object.");
            }
        }
        return $list;
    }

    /** Applies a field specification to one row. Returns null when the value is absent. */
    public static function field(array $row, $spec)
    {
        if ($spec === null) {
            return null;
        }
        if (is_string($spec)) {
            $spec = ['path' => $spec];
        }
        if (array_key_exists('const', $spec)) {
            $v = $spec['const'];
        } elseif (isset($spec['join'])) {
            $parts = [];
            foreach ($spec['join'] as $path) {
                $x = self::get($row, $path);
                if (is_scalar($x) && trim((string) $x) !== '') {
                    $parts[] = trim((string) $x);
                }
            }
            $v = $parts ? implode((string) ($spec['sep'] ?? ' '), $parts) : null;
        } else {
            $v = self::get($row, (string) $spec['path']);
        }
        if (is_array($v)) {
            $v = null; // objects and lists are never a value
        }
        if (is_bool($v)) {
            $v = $v ? 'true' : 'false';
        }
        if ($v === null || (is_string($v) && trim($v) === '')) {
            $v = $spec['default'] ?? null;
        }
        if ($v === null) {
            return null;
        }
        if (isset($spec['map'])) {
            $key = (string) $v;
            $v = array_key_exists($key, $spec['map']) ? $spec['map'][$key] : $v;
        }
        foreach ((array) ($spec['transform'] ?? []) as $t) {
            $v = self::transform($t, $v);
            if ($v === null) {
                return null;
            }
        }
        return $v;
    }

    private static function transform(string $t, $v)
    {
        $s = is_scalar($v) ? trim((string) $v) : '';
        switch (true) {
            case $t === 'trim':
                return $s;
            case $t === 'upper':
                return mb_strtoupper($s);
            case $t === 'lower':
                return mb_strtolower($s);
            case $t === 'int':
                return is_numeric($s) ? (int) round((float) $s) : null;
            case $t === 'float':
                return is_numeric($s) ? (float) $s : null;
            case $t === 'bool':
                return in_array(strtolower($s), ['1', 'y', 'yes', 'true', 'x', 't'], true) ? 'yes' : 'no';
            case $t === 'digits':
                return preg_replace('/\D+/', '', $s);
            case $t === 'course':
                return FileSisSource::courseCode($s);
            case str_starts_with($t, 'date:'):
                $fmt = substr($t, 5);
                $d = DateTimeImmutable::createFromFormat('!' . $fmt, $s);
                $err = DateTimeImmutable::getLastErrors();
                if (!$d || ($err && ($err['warning_count'] || $err['error_count']))) {
                    throw new RuntimeException('A date did not match the format ' . $fmt . ' (' . mb_substr($s, 0, 20) . ').');
                }
                return $d->format('Y-m-d');
        }
        return $v;
    }

    /** Maps one section's response into rows keyed by SAQF field (absent fields are left out). @return list<array<string,mixed>> */
    public static function rows(array $mapping, string $name, array $response): array
    {
        return self::mapRows($mapping, $name, self::items($response, $mapping[$name], $name));
    }

    /** @param list<array> $raw rows as the API sent them @return list<array<string,mixed>> */
    public static function mapRows(array $mapping, string $name, array $raw): array
    {
        $section = $mapping[$name];
        $out = [];
        foreach ($raw as $i => $row) {
            $mapped = [];
            foreach ($section['fields'] as $f => $spec) {
                try {
                    $v = self::field($row, $spec);
                } catch (RuntimeException $e) {
                    throw new RuntimeException('Row ' . ($i + 1) . " of \"$name\", field \"$f\": " . $e->getMessage());
                }
                if ($v === null && in_array($f, self::FIELDS[$name]['required'], true)) {
                    throw new RuntimeException('Row ' . ($i + 1) . " of \"$name\" has no value for \"$f\"" . (is_string($spec) ? " (path $spec)" : '') . '.');
                }
                if ($v !== null) {
                    $mapped[$f] = $v;
                }
            }
            $out[] = $mapped;
        }
        return $out;
    }

    /**
     * Raw grade rows (one student × assessment each, as the API sent them) into SAQF's batch shape. Student identifiers are replaced by keyed
     * pseudonyms HERE, so nothing downstream, and nothing printed by bin/mapping_check.php, ever holds one.
     * @return array{results:array<string,array<string,float>>,sections:array<string,string>,skipped:int,rows:int}
     */
    public static function grades(array $mapping, array $raw): array
    {
        $results = [];
        $sections = [];
        $skipped = 0;
        $skip = array_map(static fn($x) => mb_strtolower(trim((string) $x)), (array) ($mapping['grades']['skip_assessments'] ?? []));
        $rows = self::mapRows($mapping, 'grades', $raw);
        foreach ($rows as $r) {
            $student = trim((string) ($r['student'] ?? ''));
            $assessment = mb_substr(trim((string) ($r['assessment'] ?? '')), 0, 160);
            if ($student === '' || $assessment === '' || in_array(mb_strtolower($assessment), $skip, true)) {
                $skipped++;
                continue;
            }
            if (isset($r['percent']) && is_numeric($r['percent'])) {
                $pct = (float) $r['percent'];
            } elseif (isset($r['score'], $r['max']) && is_numeric($r['score']) && is_numeric($r['max']) && (float) $r['max'] > 0) {
                $pct = (float) $r['score'] / (float) $r['max'] * 100;
            } else {
                $skipped++; // not graded yet
                continue;
            }
            $ref = Secrets::pseudonym(Gradebook::SYSTEM, $student);
            $results[$assessment][$ref] = round(max(0.0, min(100.0, $pct)), 2);
            if (isset($r['section']) && ($code = Sections::code((string) $r['section'])) !== null) {
                $sections[$ref] = $code;
            }
        }
        ksort($results);
        return ['results' => $results, 'sections' => $sections, 'skipped' => $skipped, 'rows' => count($rows)];
    }
}

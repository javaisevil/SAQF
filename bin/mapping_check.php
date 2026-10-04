<?php
declare(strict_types=1);

/**
 * Checks a mapping file for a university's JSON API (docs/INTEGRATIONS.md, "Mapped API") BEFORE it is connected, with
 * no network and no database. Without samples it validates the mapping. With one or more saved API responses it runs
 * the mapping on them and prints what SAQF would read, so an integration engineer can paste a real (anonymised) answer
 * from the SIS or LMS and see the result at once. Student identifiers are replaced by pseudonyms before anything is
 * printed, and instructor e-mail addresses are masked.
 *
 *   php bin/mapping_check.php <mapping.json> --system=sis [--sample=terms=<response.json>] [--sample=assignments=<response.json>] [--term=2026-1]
 *   php bin/mapping_check.php <mapping.json> --system=lms [--sample=grades=<response.json>]
 * A section the API pages can have several samples (--sample=grades=page1.json --sample=grades=page2.json): their rows are read together.
 * Exit code: 0 = mapping valid and every sample maps cleanly, 1 = problems, 2 = usage.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Saqf\Integration\FileSisSource;
use Saqf\Integration\Mapping;

$file = $argv[1] ?? '';
$opts = ['system' => '', 'term' => '', 'sample' => []];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--system=(sis|lms)$/', $a, $m)) {
        $opts['system'] = $m[1];
    } elseif (preg_match('/^--term=(.{1,20})$/', $a, $m)) {
        $opts['term'] = $m[1];
    } elseif (preg_match('/^--sample=(terms|assignments|grades)=(.+)$/', $a, $m)) {
        $opts['sample'][$m[1]][] = $m[2];
    } else {
        fwrite(STDERR, "unknown argument: $a\n");
        exit(2);
    }
}
if ($file === '' || $opts['system'] === '') {
    fwrite(STDERR, "usage: php bin/mapping_check.php <mapping.json> --system=sis|lms [--sample=<section>=<response.json> …]\n");
    exit(2);
}
$say = static fn(string $s = '') => print($s . "\n");
$bad = 0;

$say("Mapping check: $file (" . strtoupper($opts['system']) . ' mapping; no network, nothing is written)');
$raw = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (!is_array($raw)) {
    $say('CANNOT READ: the file is missing or is not valid JSON.');
    exit(1);
}
$problems = Mapping::problems($raw, $opts['system']);
if ($problems) {
    $say('  ✗ ' . count($problems) . ' problem(s) in the mapping:');
    foreach ($problems as $p) {
        $say('    - ' . $p);
    }
    exit(1);
}
$mapping = $raw;
$say('  ✓ the mapping is valid: "' . $mapping['name'] . '"' . (!empty($mapping['simulated']) ? ' (marked SIMULATED)' : ''));
$say('    authentication: ' . ($mapping['auth']['type'] ?? 'bearer') . ' · sections: ' . implode(', ', array_intersect(['terms', 'assignments', 'grades'], array_keys($mapping))));
$expected = $opts['system'] === 'sis' ? ['terms', 'assignments'] : ['grades'];
foreach ($opts['sample'] as $section => $path) {
    if (!in_array($section, $expected, true)) {
        $say("  ✗ --sample=$section is not a section of a " . strtoupper($opts['system']) . ' mapping.');
        $bad++;
    }
}
foreach ($expected as $section) {
    if (!isset($opts['sample'][$section])) {
        $say("  - no sample for \"$section\": pass --sample=$section=<saved response.json> to see what SAQF would read.");
        continue;
    }
    $items = [];
    foreach ($opts['sample'][$section] as $path) {
        $resp = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($resp)) {
            $say("  ✗ $section: the sample $path is missing or is not valid JSON.");
            $bad++;
            continue 2;
        }
        try {
            array_push($items, ...Mapping::items($resp, $mapping[$section], $section));
        } catch (Throwable $e) {
            $say('  ✗ ' . $section . ': ' . $e->getMessage());
            $bad++;
            continue 2;
        }
    }
    try {
        if ($section === 'grades') {
            $g = Mapping::grades($mapping, $items);
            $students = [];
            foreach ($g['results'] as $scores) {
                $students += $scores;
            }
            $say(sprintf('  ✓ grades: %d row(s) read → %d assessment(s), %d student(s), %d value(s) kept, %d row(s) skipped (ungraded, skipped column or no id)', $g['rows'], count($g['results']), count($students), array_sum(array_map('count', $g['results'])), $g['skipped']));
            foreach ($g['results'] as $name => $scores) {
                $say(sprintf('      %-28s %3d students, average %.1f%%', $name, count($scores), $scores ? array_sum($scores) / count($scores) : 0));
            }
            if ($g['sections']) {
                $say('      sections found: ' . implode(', ', array_values(array_unique($g['sections']))));
            }
            $say('      Student identifiers are shown nowhere: they became keyed pseudonyms as the rows were read.');
            $say('      Assessment names must equal the course specification\'s names; use "map" in the mapping for coded columns.');
        } else {
            $rows = Mapping::mapRows($mapping, $section, $items);
            $clean = [];
            foreach ($rows as $i => $r) {
                try {
                    $clean[] = $section === 'terms' ? FileSisSource::term($r) : FileSisSource::assignment($r + ['term' => $opts['term'] !== '' ? $opts['term'] : '(term)']);
                } catch (Throwable $e) {
                    $say('  ✗ ' . $section . ' row ' . ($i + 1) . ': ' . $e->getMessage());
                    $bad++;
                }
            }
            $say(sprintf('  %s %s: %d row(s) read, %d accepted by the same checks the live connector applies', count($clean) === count($rows) ? '✓' : '✗', $section, count($rows), count($clean)));
            foreach (array_slice($clean, 0, 3) as $c) {
                if (isset($c['instructor_email']) && str_contains((string) $c['instructor_email'], '@')) {
                    $c['instructor_email'] = substr($c['instructor_email'], 0, 1) . '***' . strstr($c['instructor_email'], '@');
                }
                $say('      ' . json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    } catch (Throwable $e) {
        $say('  ✗ ' . $section . ': ' . $e->getMessage());
        $bad++;
    }
}
$say();
$say($bad ? "Result: $bad problem(s). Fix the mapping and run this again." : 'Result: the mapping is usable. Next: set the settings in docs/INTEGRATIONS.md and press "Test connections" in Administration → Go-live.');
exit($bad ? 1 : 0);

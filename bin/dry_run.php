<?php
declare(strict_types=1);

/**
 * Dry run of a SIS or LMS export BEFORE it is connected: reads the files exactly as the connectors
 * would and prints row counts, what would be accepted and every rejected row with its reason.
 * Nothing is written to the database and no student identifier is printed (students are counted
 * only, after pseudonymisation). Use it on the anonymised staging sample of the integration contract.
 *
 *   php bin/dry_run.php sis <folder>            terms.csv + assignments.csv (or sis.json)
 *   php bin/dry_run.php lms <folder> [term]     <folder>/<term>/<COURSE>/*.csv gradebook exports
 * Exit code: 0 = every row clean, 1 = some rows/files would be rejected or need attention, 2 = the export cannot be read.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Saqf\Core\Db;
use Saqf\Integration\FileSisSource;
use Saqf\Integration\Gradebook;

[$kind, $dir, $only] = [$argv[1] ?? '', rtrim((string) ($argv[2] ?? ''), '/'), $argv[3] ?? null];
if (!in_array($kind, ['sis', 'lms'], true) || $dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: php bin/dry_run.php sis <folder> | lms <folder> [term]\n");
    exit(2);
}
$courses = array_flip(Db::col('SELECT code FROM courses'));
$rejected = 0;
$say = static fn(string $s) => print($s . "\n");

if ($kind === 'sis') {
    try {
        $src = new FileSisSource($dir);
        $terms = $src->terms();
    } catch (Throwable $e) {
        $say('CANNOT READ: ' . $e->getMessage());
        exit(2);
    }
    $say("SIS export: $dir (dry run, nothing is written)");
    $say(sprintf('  %d term(s)', count($terms)));
    $codes = [];
    foreach ($terms as $t) {
        $codes[] = $t['code'];
        if (!preg_match('/^[A-Za-z0-9\-]{1,20}$/', $t['code'])) {
            $say("  REJECTED term '{$t['code']}': the code must be letters, digits or dashes");
            $rejected++;
        }
    }
    $seen = [];
    foreach ($codes as $code) {
        $rows = $src->assignments($code);
        [$ok, $skipped, $warned] = [0, 0, 0];
        foreach ($rows as $i => $a) {
            $where = sprintf('%s row %d (%s%s)', $code, $i + 2, $a['course'], $a['section'] ? ' section ' . $a['section'] : '');
            if (!isset($courses[$a['course']])) {
                // Sync::assignments skips these rows and counts them as unknown courses.
                $say("  REJECTED $where: course {$a['course']} is not in the Registrar catalogue, so the import skips the row");
                $rejected++;
                $skipped++;
                continue;
            }
            $warn = [];
            if ($a['instructor'] === null && $a['instructor_name'] === null) {
                $warn[] = 'no instructor_id or instructor_name: the course is created without an instructor and flagged for the Head of Department';
            }
            $key = $a['course'] . '|' . ($a['section'] ?? '');
            if (isset($seen[$code][$key])) {
                $warn[] = 'duplicate of an earlier row for the same course' . ($a['section'] ? ' and section' : '') . ': fix it in the export';
            }
            $seen[$code][$key] = true;
            if ($warn) {
                $say("  WARNING  $where: " . implode('; ', $warn));
                $rejected++;
                $warned++;
            } else {
                $ok++;
            }
        }
        $say(sprintf('  %s: %d assignment row(s): %d clean, %d with a warning, %d rejected', $code, count($rows), $ok, $warned, $skipped));
    }
} else {
    $say("LMS gradebook exports: $dir (dry run, nothing is written; students are counted, never listed)");
    foreach (glob("$dir/*", GLOB_ONLYDIR) ?: [] as $termDir) {
        $term = basename($termDir);
        if ($only !== null && $term !== $only) {
            continue;
        }
        foreach (glob("$termDir/*", GLOB_ONLYDIR) ?: [] as $courseDir) {
            $course = FileSisSource::courseCode(preg_replace('/^([A-Za-z]+)(\d)/', '$1 $2', basename($courseDir)));
            $spec = Db::one('SELECT o.spec_version_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = ? AND t.code = ?', [$course, $term]);
            $known = $spec && $spec['spec_version_id'] ? array_map('mb_strtolower', Db::col('SELECT name FROM assessments WHERE spec_version_id = ?', [$spec['spec_version_id']])) : null;
            foreach (glob("$courseDir/*.csv") ?: [] as $file) {
                $label = "$term/" . basename($courseDir) . '/' . basename($file);
                try {
                    $parsed = Gradebook::parse($file);
                } catch (InvalidArgumentException $e) {
                    $say("  REJECTED $label: " . $e->getMessage() . ' (the whole file would be skipped)');
                    $rejected++;
                    continue;
                }
                $students = [];
                foreach ($parsed['results'] as $scores) {
                    $students += $scores;
                }
                $columns = array_keys($parsed['results']);
                if ($known === null) {
                    $say(sprintf('  REJECTED %s: no %s offering with an approved specification in %s', $label, $course, $term));
                    $rejected++;
                    continue;
                }
                $matched = array_values(array_filter($columns, static fn($c) => in_array(mb_strtolower(trim($c)), $known, true)));
                $ignored = array_values(array_diff($columns, $matched));
                $say(sprintf('  %s: %d student(s), %d column(s) match the specification (%s)%s', $label, count($students), count($matched), implode(', ', $matched) ?: 'none', $ignored ? '; ignored: ' . implode(', ', $ignored) : ''));
                if (!$matched) {
                    $rejected++;
                }
            }
        }
    }
}
$say($rejected ? "\n$rejected problem(s): fix the export (or the catalogue) before connecting it." : "\nEverything in this export is usable.");
exit($rejected ? 1 : 0);

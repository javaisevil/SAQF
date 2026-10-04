<?php
declare(strict_types=1);

namespace Saqf\Integration;

use Saqf\Web\Zip;

/**
 * The university "data pack": the Registrar catalogue (colleges, departments, programs, study
 * plans, courses, prerequisites, outcomes) in the JSON layout of data/yu. This class
 *  - checks a pack completely before SAQF uses it (so a bad export can never half-load),
 *  - packages the bundled pack as the template IT replaces with the Registrar's own export,
 *  - writes the SIS and LMS file templates from the demo feeds, in the formats the file connectors read.
 */
final class DataPack
{
    public const COURSE_TYPES = ['required', 'elective'];

    /**
     * Checks a pack on disk. Errors stop SAQF from using it; warnings are things to know about.
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,summary:array<string,int|string>}
     */
    public static function inspect(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $errors = [];
        $read = static function (string $file) use (&$errors) {
            if (!is_file($file)) {
                $errors[] = basename(dirname($file)) . '/' . basename($file) . ' is missing.';
                return null;
            }
            try {
                $v = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $errors[] = basename($file) . ' is not valid JSON (' . $e->getMessage() . ').';
                return null;
            }
            return is_array($v) ? $v : null;
        };
        $base = $read($dir . '/institution.json');
        if ($base === null) {
            return ['ok' => false, 'errors' => $errors ?: ['institution.json is empty.'], 'warnings' => [], 'summary' => []];
        }
        $programs = [];
        foreach ((array) ($base['programs'] ?? []) as $code) {
            if (!is_string($code) || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
                $errors[] = 'institution.json lists a program code that is not a plain code: ' . json_encode($code) . '.';
                continue;
            }
            $p = $read($dir . '/programs/' . strtolower($code) . '.json');
            if ($p !== null) {
                $programs[] = $p;
            }
        }
        $base['programs'] = $programs;
        if (is_file($dir . '/arabic.json')) {
            $ar = $read($dir . '/arabic.json');
            if ($ar !== null) {
                $base['arabic'] = $ar;
            }
        }
        $report = self::validate($base);
        $report['errors'] = array_merge($errors, $report['errors']);
        $report['ok'] = !$report['errors'];
        return $report;
    }

    /**
     * Checks a loaded snapshot (the shape InstitutionSource::snapshot() returns).
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,summary:array<string,int|string>}
     */
    public static function validate(array $snap): array
    {
        $errors = [];
        $warnings = [];
        $say = static function (array &$list, string $msg) {
            if (count($list) < 60) {
                $list[] = $msg;
            } elseif (count($list) === 60) {
                $list[] = 'More problems of this kind were left out of this list.';
            }
        };
        $inst = (array) ($snap['institution'] ?? []);
        foreach (['code', 'name'] as $k) {
            if (trim((string) ($inst[$k] ?? '')) === '') {
                $say($errors, "institution.$k is missing.");
            }
        }

        $colleges = [];
        foreach ((array) ($snap['colleges'] ?? []) as $c) {
            $code = trim((string) ($c['code'] ?? ''));
            if ($code === '' || trim((string) ($c['name'] ?? '')) === '') {
                $say($errors, 'A college needs a code and a name.');
            } elseif (isset($colleges[$code])) {
                $say($errors, "College $code appears twice.");
            }
            $colleges[$code] = true;
        }
        if (!$colleges) {
            $say($errors, 'No colleges are listed.');
        }

        $departments = [];
        foreach ((array) ($snap['departments'] ?? []) as $d) {
            $code = trim((string) ($d['code'] ?? ''));
            if ($code === '' || trim((string) ($d['name'] ?? '')) === '') {
                $say($errors, 'A department needs a code and a name.');
                continue;
            }
            if (isset($departments[$code])) {
                $say($errors, "Department $code appears twice.");
            }
            if (!isset($colleges[(string) ($d['college'] ?? '')])) {
                $say($errors, "Department $code belongs to college '" . ($d['college'] ?? '') . "', which is not in the college list.");
            }
            $departments[$code] = true;
        }
        if (!$departments) {
            $say($errors, 'No departments are listed.');
        }

        $prefixes = [];
        foreach ((array) ($snap['ownership'] ?? []) as $o) {
            $prefix = trim((string) ($o['prefix'] ?? ''));
            if ($prefix === '' || !isset($departments[(string) ($o['department'] ?? '')])) {
                $say($errors, "Course prefix '$prefix' is assigned to a department that is not in the department list.");
                continue;
            }
            $prefixes[] = $prefix;
        }
        usort($prefixes, static fn($a, $b) => strlen($b) <=> strlen($a));

        $programCodes = [];
        $allCourses = [];
        $rowsTotal = 0;
        $plos = 0;
        $withoutPlos = [];
        $missingOwner = [];
        $titleless = 0;
        foreach ((array) ($snap['programs'] ?? []) as $p) {
            $pc = trim((string) ($p['code'] ?? ''));
            if ($pc === '' || trim((string) ($p['name'] ?? '')) === '') {
                $say($errors, 'A program needs a code and a name.');
                continue;
            }
            if (isset($programCodes[$pc])) {
                $say($errors, "Program $pc appears twice.");
            }
            $programCodes[$pc] = true;
            if (!isset($departments[(string) ($p['department'] ?? '')])) {
                $say($errors, "Program $pc belongs to department '" . ($p['department'] ?? '') . "', which is not in the department list.");
            }
            if (trim((string) ($p['level'] ?? '')) === '') {
                $say($errors, "Program $pc has no level (Undergraduate or Postgraduate).");
            }
            if (!isset($p['courses']) || !is_array($p['courses']) || !$p['courses']) {
                $say($errors, "Program $pc has no study plan (courses).");
                continue;
            }
            $seen = [];
            foreach ($p['courses'] as $i => $row) {
                $code = trim((string) ($row['code'] ?? ''));
                if ($code === '') {
                    continue; // placeholder rows (elective slots) carry no code
                }
                $rowsTotal++;
                $where = "$pc row " . ($i + 1) . " ($code)";
                if (isset($seen[$code])) {
                    $say($warnings, "$pc lists $code more than once.");
                }
                $seen[$code] = true;
                $credits = $row['credits'] ?? null;
                if (!is_numeric($credits) || $credits < 0 || $credits > 12) {
                    $say($errors, "$where: credit hours must be a number from 0 to 12.");
                }
                if (trim((string) ($row['title'] ?? '')) === '') {
                    $titleless++;
                }
                if (isset($row['type']) && !in_array($row['type'], self::COURSE_TYPES, true)) {
                    $say($errors, "$where: type must be required or elective.");
                }
                $owned = false;
                foreach ($prefixes as $pf) {
                    if (str_starts_with($code, $pf)) {
                        $owned = true;
                        break;
                    }
                }
                if (!$owned) {
                    $missingOwner[$code] = true;
                }
                $allCourses[$code] = ($allCourses[$code] ?? 0) + 1;
            }
            foreach ((array) ($p['plos'] ?? []) as $plo) {
                if (trim((string) ($plo['code'] ?? '')) === '' || trim((string) ($plo['text'] ?? '')) === '') {
                    $say($errors, "Program $pc has an outcome without a code or text.");
                } else {
                    $plos++;
                }
            }
            if (!($p['plos'] ?? [])) {
                $withoutPlos[] = $pc;
            }
        }
        if (!$programCodes) {
            $say($errors, 'No programs are listed.');
        }
        if ($titleless) {
            $say($errors, "$titleless course row(s) have no title.");
        }
        if ($missingOwner) {
            $say($errors, count($missingOwner) . ' course(s) have a code prefix with no owning department (ownership list), so no Head of Department would be responsible: ' . implode(', ', array_slice(array_keys($missingOwner), 0, 8)) . (count($missingOwner) > 8 ? ' …' : '') . '.');
        }

        $unknownReq = [];
        foreach ((array) ($snap['programs'] ?? []) as $p) {
            foreach ((array) ($p['courses'] ?? []) as $row) {
                foreach (array_merge((array) ($row['prerequisites'] ?? []), (array) ($row['corequisites'] ?? [])) as $req) {
                    if (is_string($req) && $req !== '' && !isset($allCourses[$req])) {
                        $unknownReq[$req] = true;
                    }
                }
            }
        }
        if ($unknownReq) {
            $say($warnings, count($unknownReq) . ' prerequisite course(s) are not in any study plan (SAQF raises them as data exceptions for the Registrar): ' . implode(', ', array_slice(array_keys($unknownReq), 0, 8)) . (count($unknownReq) > 8 ? ' …' : '') . '.');
        }
        if ($withoutPlos) {
            $say($warnings, 'No program outcomes for ' . implode(', ', $withoutPlos) . ': SAQF flags these programs until the Head of Department enters them.');
        }

        // Arabic coverage: how much of the catalogue already has an Arabic name.
        $arCourses = (array) ($snap['arabic']['courses'] ?? []);
        $covered = count(array_filter(array_keys($allCourses), static fn($c) => isset($arCourses[$c]) && $arCourses[$c] !== ''));
        $coverage = $allCourses ? (int) round(100 * $covered / count($allCourses)) : 0;
        if ($allCourses && $coverage < 100) {
            $say($warnings, "Arabic course titles cover $coverage% of courses; Quality can fill the rest on the Arabic wording page.");
        }

        return [
            'ok' => !$errors,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'institution' => (string) ($inst['name'] ?? ''),
                'colleges' => count($colleges),
                'departments' => count($departments),
                'programs' => count($programCodes),
                'courses' => count($allCourses),
                'plan rows' => $rowsTotal,
                'program outcomes' => $plos,
                'Arabic course titles %' => $coverage,
            ],
        ];
    }

    /** The folder IT can drop a Registrar export into; used automatically when present (see CatalogFileSource). */
    public static function stagingDir(): string
    {
        return SAQF_ROOT . '/storage/inbox/catalog';
    }

    /** ZIP of a pack folder (the bundled snapshot is the template for the Registrar's own export). */
    public static function zip(string $dir): string
    {
        $dir = rtrim($dir, '/');
        $zip = new Zip();
        $zip->add('README.txt', self::readme());
        foreach (array_merge(['institution.json', 'arabic.json'], array_map(static fn($f) => 'programs/' . basename($f), glob($dir . '/programs/*.json') ?: [])) as $rel) {
            if (is_file($dir . '/' . $rel)) {
                $zip->add($rel, (string) file_get_contents($dir . '/' . $rel));
            }
        }
        return $zip->bytes();
    }

    /** The SIS drop-folder files (terms.csv, assignments.csv) built from the demo feed, in the exact columns SAQF reads. @return array<string,string> */
    public static function sisTemplates(?string $file = null): array
    {
        $file ??= SAQF_ROOT . '/data/demo/sis.json';
        $d = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $terms = self::csv(['code', 'name', 'academic_year', 'sequence', 'starts_on', 'ends_on', 'grades_due_on'], (array) ($d['terms'] ?? []));
        $rows = [];
        foreach ((array) ($d['assignments'] ?? []) as $term => $list) {
            foreach ((array) $list as $a) {
                $rows[] = [
                    'term' => $term, 'course' => $a['course'] ?? '', 'instructor_id' => $a['instructor'] ?? '',
                    'instructor_name' => $a['instructor_name'] ?? '', 'instructor_email' => $a['instructor_email'] ?? '',
                    'department' => $a['department'] ?? '', 'sections' => $a['sections'] ?? '', 'enrolled' => $a['enrolled'] ?? '',
                    'section' => $a['section'] ?? '', 'coordinator' => !empty($a['coordinator']) ? '1' : '',
                ];
            }
        }
        $assignments = self::csv(['term', 'course', 'instructor_id', 'instructor_name', 'instructor_email', 'department', 'sections', 'enrolled', 'section', 'coordinator'], $rows);
        return ['terms.csv' => $terms, 'assignments.csv' => $assignments];
    }

    /** A gradebook CSV in the shape of the LMS export folder, made from one demo course (three students). */
    public static function gradebookTemplate(?string $demoFile = null): string
    {
        $demoFile ??= SAQF_ROOT . '/data/demo/lms/2026-1/SWE401.json';
        $batches = is_file($demoFile) ? (json_decode((string) file_get_contents($demoFile), true) ?: []) : [];
        $results = $batches[0]['results'] ?? ['Quiz' => ['S0001' => 80, 'S0002' => 65, 'S0003' => 92]];
        $names = array_keys($results);
        $students = array_slice(array_keys((array) reset($results)), 0, 3);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, array_merge(['student', 'section'], $names));
        foreach ($students as $i => $s) {
            fputcsv($out, array_merge(['2024' . str_pad((string) (101 + $i), 4, '0', STR_PAD_LEFT), '0' . (1 + $i % 2)], array_map(static fn($n) => $results[$n][$s] ?? '', $names)));
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    private static function csv(array $columns, array $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, $columns);
        foreach ($rows as $r) {
            fputcsv($out, array_map(static fn($c) => $r[$c] ?? '', $columns));
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    private static function readme(): string
    {
        return <<<TXT
SAQF data pack: Registrar catalogue
===================================

This folder is the catalogue SAQF reads (colleges, departments, programs, study plans, courses,
prerequisites, program outcomes). The files shipped with SAQF describe Al Yamamah University's
public study plans. To use the Registrar's own data, produce the same layout and either

  1. put it in storage/inbox/catalog/ (SAQF picks it up automatically, after checking it), or
  2. point SAQF_INSTITUTION_DIR at the folder.

Layout
  institution.json     institution, colleges, departments, ownership (course prefix -> department),
                       the list of program codes, optional course descriptions
  programs/<code>.json one file per program: code, name, short_name, degree, level, department,
                       total_credits, plos[], elective_rules[], courses[] (code, title, credits,
                       year, semester, group, type required|elective, prerequisites[], corequisites[])
  arabic.json          optional Arabic names, keyed by code (or inline name_ar / title_ar fields)

Check a pack before using it (nothing is changed):
  php bin/pack.php validate <folder>
or Administration -> Go-live -> Check the catalogue.
TXT;
    }
}

<?php
declare(strict_types=1);

namespace Saqf\Quality;

use DomainException;
use InvalidArgumentException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Notify;
use Saqf\Integration\Csv;
use Saqf\Integration\FileSisSource;
use Throwable;

/**
 * Bulk import of existing course specifications (the university's approved specifications from
 * before SAQF), so a new installation does not start with hundreds of empty courses.
 *
 * One CSV (Excel "CSV UTF-8") with a row per item:
 *   course,type,code,text,category,weight,week,hours,target,links
 *   type = objectives | strategies | clo | assessment | topic | resource
 *   clo:        code (your label, e.g. CLO1), text = statement, category = domain, target %, links = PLO codes
 *               ("SO1;SO2", or "SWE:SO1" to name the program)
 *   assessment: text = name, category = type (quiz, midterm, project…), weight %, week, links = CLO codes
 *   topic:      text, hours
 *   resource:   text, category = essential | supportive | electronic | facility
 *
 * Each course is checked completely before anything is written; a course with any error is skipped
 * and reported with line numbers. Imported specifications go through the same rules engine as
 * specifications written in SAQF, so any weakness becomes a finding for the instructor.
 *   mode baseline: approved immediately as the existing baseline (recorded with the reason)
 *   mode draft:    left as a draft for the course coordinator to review and submit
 */
final class SpecImport
{
    public const COLUMNS = ['course', 'type', 'code', 'text', 'category', 'weight', 'week', 'hours', 'target', 'links'];

    private const DOMAINS = [
        'k' => 'Knowledge and Understanding', 'knowledge' => 'Knowledge and Understanding', 'knowledge and understanding' => 'Knowledge and Understanding',
        'المعرفة' => 'Knowledge and Understanding', 'المعرفة والفهم' => 'Knowledge and Understanding',
        's' => 'Skills', 'skills' => 'Skills', 'skill' => 'Skills', 'المهارات' => 'Skills',
        'v' => 'Values, Autonomy, and Responsibility', 'values' => 'Values, Autonomy, and Responsibility', 'values, autonomy, and responsibility' => 'Values, Autonomy, and Responsibility',
        'values, autonomy and responsibility' => 'Values, Autonomy, and Responsibility', 'القيم' => 'Values, Autonomy, and Responsibility', 'القيم والاستقلالية والمسؤولية' => 'Values, Autonomy, and Responsibility',
    ];

    private const RESOURCES = [
        'essential' => 'essential', 'main' => 'essential', 'textbook' => 'essential', 'required' => 'essential',
        'supportive' => 'supportive', 'supporting' => 'supportive', 'reference' => 'supportive', 'references' => 'supportive',
        'electronic' => 'electronic', 'online' => 'electronic', 'e-materials' => 'electronic', 'website' => 'electronic',
        'facility' => 'facility', 'facilities' => 'facility', 'equipment' => 'facility',
    ];

    /** A ready-to-fill template with one complete example course. */
    public static function template(): string
    {
        $rows = [
            self::COLUMNS,
            ['SWE 401', 'objectives', '', 'Develop the ability to plan, perform and justify software quality assurance activities.', '', '', '', '', '', ''],
            ['SWE 401', 'strategies', '', 'Lectures, inspection workshops and a team QA-plan project.', '', '', '', '', '', ''],
            ['SWE 401', 'clo', 'CLO1', 'Describe software quality models and standards.', 'Knowledge and Understanding', '', '', '', '', 'SO1'],
            ['SWE 401', 'clo', 'CLO2', 'Apply review and inspection techniques to software artifacts.', 'Skills', '', '', '', '', 'SO1;SO2'],
            ['SWE 401', 'clo', 'CLO3', 'Design a test and quality assurance plan for a software project.', 'Skills', '', '', '', '75', 'SO2'],
            ['SWE 401', 'assessment', '', 'Midterm exam', 'midterm', '40', '8', '', '', 'CLO1;CLO2'],
            ['SWE 401', 'assessment', '', 'QA plan project', 'project', '30', '13', '', '', 'CLO3'],
            ['SWE 401', 'assessment', '', 'Final exam', 'final', '30', '16', '', '', 'CLO2;CLO3'],
            ['SWE 401', 'topic', '', 'Software quality concepts and models', '', '', '', '6', '', ''],
            ['SWE 401', 'topic', '', 'Reviews and inspections', '', '', '', '9', '', ''],
            ['SWE 401', 'resource', '', 'Galin, D. Software Quality: Concepts and Practice', 'essential', '', '', '', '', ''],
        ];
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF"); // Excel opens UTF-8 (Arabic) correctly with a BOM
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        rewind($fh);
        return (string) stream_get_contents($fh);
    }

    /**
     * @param array|null $courseScope course ids the importer may change (HoD: their department's), null = all
     * @return array{imported:list<string>,skipped:int,errors:list<string>,mode:string}
     */
    public static function run(string $path, string $mode, string $reason, ?array $courseScope = null): array
    {
        if (!in_array($mode, ['baseline', 'draft'], true)) {
            throw new InvalidArgumentException('Choose how to import: as approved baselines or as drafts.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new InvalidArgumentException('Give a reason for the import (e.g. "Specifications approved by the department council in 2025").');
        }
        $out = ['imported' => [], 'skipped' => 0, 'errors' => [], 'mode' => $mode];
        $byCourse = [];
        $line = 1;
        foreach (Csv::rows($path, ['course', 'type', 'text']) as $row) {
            $line++;
            $code = FileSisSource::courseCode((string) ($row['course'] ?? ''));
            if ($code === '') {
                continue;
            }
            $byCourse[$code][] = $row + ['_line' => $line];
            if ($line > 20000) {
                $out['errors'][] = 'Stopped after 20000 rows.';
                break;
            }
        }
        foreach ($byCourse as $code => $rows) {
            $courseId = (int) Db::val('SELECT id FROM courses WHERE code = ?', [$code]);
            if (!$courseId) {
                $out['errors'][] = "$code (line {$rows[0]['_line']}): this course is not in the catalogue.";
                $out['skipped']++;
                continue;
            }
            if ($courseScope !== null && !in_array($courseId, $courseScope, true)) {
                $out['errors'][] = "$code: outside your department — not imported.";
                $out['skipped']++;
                continue;
            }
            [$spec, $errors] = self::validate($courseId, $code, $rows);
            if ($errors) {
                array_push($out['errors'], ...$errors);
                $out['skipped']++;
                continue;
            }
            try {
                self::apply($courseId, $code, $spec, $mode, $reason);
                $out['imported'][] = $code;
            } catch (DomainException | InvalidArgumentException $e) {
                $out['errors'][] = "$code: " . $e->getMessage();
                $out['skipped']++;
            }
        }
        Audit::record('spec.imported', 'course', null, 'Specification import: ' . count($out['imported']) . ' course(s) ' . ($mode === 'baseline' ? 'imported as approved baselines' : 'imported as drafts') . ', ' . $out['skipped'] . ' skipped', null, ['courses' => $out['imported']], $reason);
        return $out;
    }

    /** @return array{0:array,1:list<string>} parsed specification and errors */
    private static function validate(int $courseId, string $code, array $rows): array
    {
        $errors = [];
        $spec = ['objectives' => '', 'strategies' => '', 'clos' => [], 'assessments' => [], 'topics' => [], 'resources' => []];
        $err = static function (array $row, string $msg) use (&$errors, $code) {
            $errors[] = "$code line {$row['_line']}: $msg";
        };
        $plos = [];
        foreach (Catalog::programsFor($courseId) as $p) {
            foreach (Db::all('SELECT id, code FROM plos WHERE program_id = ? AND status = "approved"', [$p['program_id']]) as $pl) {
                $plos[] = ['id' => (int) $pl['id'], 'code' => strtoupper($pl['code']), 'program' => strtoupper($p['code'])];
            }
        }
        foreach ($rows as $r) {
            $type = strtolower(trim((string) $r['type']));
            $text = trim((string) ($r['text'] ?? ''));
            if ($text === '') {
                $err($r, 'the text column is empty.');
                continue;
            }
            switch ($type) {
                case 'objectives':
                case 'objective':
                    $spec['objectives'] = trim($spec['objectives'] . "\n" . $text);
                    break;
                case 'strategies':
                case 'strategy':
                    $spec['strategies'] = trim($spec['strategies'] . "\n" . $text);
                    break;
                case 'clo':
                    $label = strtoupper(trim((string) ($r['code'] ?? ''))) ?: 'CLO' . (count($spec['clos']) + 1);
                    $domain = self::DOMAINS[mb_strtolower(trim((string) ($r['category'] ?? '')))] ?? null;
                    if ($domain === null) {
                        $err($r, "learning domain \"{$r['category']}\" — use Knowledge and Understanding, Skills, or Values, Autonomy, and Responsibility.");
                        continue 2;
                    }
                    $target = trim((string) ($r['target'] ?? ''));
                    if ($target !== '' && (!is_numeric($target) || (float) $target < 0 || (float) $target > 100)) {
                        $err($r, 'target must be a percentage between 0 and 100.');
                        continue 2;
                    }
                    $maps = [];
                    foreach (self::list($r['links'] ?? '') as $ref) {
                        [$prog, $ploCode] = str_contains($ref, ':') ? array_map('trim', explode(':', $ref, 2)) : [null, $ref];
                        $hits = array_filter($plos, static fn($p) => $p['code'] === strtoupper($ploCode) && ($prog === null || $p['program'] === strtoupper($prog)));
                        if (!$hits) {
                            $err($r, "PLO \"$ref\" is not an outcome of a program that includes $code.");
                            continue 3;
                        }
                        foreach ($hits as $h) {
                            $maps[$h['id']] = true;
                        }
                    }
                    if (isset($spec['clos'][$label])) {
                        $err($r, "CLO code $label is used twice.");
                        continue 2;
                    }
                    $spec['clos'][$label] = ['statement' => $text, 'domain' => $domain, 'target_pct' => $target, 'plos' => array_keys($maps)];
                    break;
                case 'assessment':
                    $weight = trim((string) ($r['weight'] ?? ''));
                    if (!is_numeric($weight) || (float) $weight < 0 || (float) $weight > 100) {
                        $err($r, "weight of \"$text\" must be a percentage between 0 and 100.");
                        continue 2;
                    }
                    $week = trim((string) ($r['week'] ?? ''));
                    if ($week !== '' && (!ctype_digit($week) || (int) $week < 1 || (int) $week > 18)) {
                        $err($r, 'week must be a number from 1 to 18.');
                        continue 2;
                    }
                    $kind = strtolower(trim((string) ($r['category'] ?? '')));
                    $kind = isset(Specs::ASSESSMENT_KINDS[$kind]) ? $kind : (array_search(mb_strtolower($kind), array_map('mb_strtolower', Specs::ASSESSMENT_KINDS), true) ?: 'other');
                    if (isset($spec['assessments'][mb_strtolower($text)])) {
                        $err($r, "assessment \"$text\" appears twice.");
                        continue 2;
                    }
                    $spec['assessments'][mb_strtolower($text)] = ['name' => $text, 'kind' => $kind, 'weight_pct' => (float) $weight, 'week' => $week, 'clos' => self::list($r['links'] ?? '', true), '_row' => $r];
                    break;
                case 'topic':
                    $hours = trim((string) ($r['hours'] ?? ''));
                    if ($hours !== '' && !is_numeric($hours)) {
                        $err($r, 'contact hours must be a number.');
                        continue 2;
                    }
                    $spec['topics'][] = ['topic' => $text, 'hours' => $hours];
                    break;
                case 'resource':
                    $cat = self::RESOURCES[strtolower(trim((string) ($r['category'] ?? 'essential')))] ?? null;
                    if ($cat === null) {
                        $err($r, 'resource category must be essential, supportive, electronic or facility.');
                        continue 2;
                    }
                    $spec['resources'][] = ['category' => $cat, 'text' => $text];
                    break;
                default:
                    $err($r, "unknown type \"{$r['type']}\" (use objectives, strategies, clo, assessment, topic or resource).");
            }
        }
        foreach ($spec['assessments'] as $a) {
            foreach ($a['clos'] as $c) {
                if (!isset($spec['clos'][$c])) {
                    $err($a['_row'], "assessment \"{$a['name']}\" measures $c, which is not a CLO of this course in the file.");
                }
            }
        }
        if (!$spec['clos']) {
            $errors[] = "$code: no CLOs in the file — nothing to import.";
        }
        return [$spec, $errors];
    }

    private static function apply(int $courseId, string $code, array $spec, string $mode, string $reason): int
    {
        if (Specs::inFlight($courseId)) {
            throw new DomainException('a revision of this specification is already in progress in SAQF; finish or discard it first.');
        }
        return Db::tx(static function () use ($courseId, $code, $spec, $mode, $reason) {
            $actor = Audit::actor();
            $vid = Db::insert('spec_versions', [
                'course_id' => $courseId,
                'version_no' => (int) Db::val('SELECT COALESCE(MAX(version_no),0)+1 FROM spec_versions WHERE course_id = ?', [$courseId]),
                'status' => 'draft',
                'based_on_id' => Specs::approved($courseId)['id'] ?? null,
                'created_by' => $actor['type'] === 'user' ? $actor['id'] : null,
                'created_at' => Clock::stamp(),
            ]);
            Audit::record('spec.created', 'spec_version', $vid, "$code: specification imported from file", null, null, $reason);
            $cloIds = [];
            foreach ($spec['clos'] as $label => $c) {
                $cloIds[$label] = Specs::saveClo($vid, null, $c);
                foreach ($c['plos'] as $ploId) {
                    Specs::setMapping($cloIds[$label], (int) $ploId, true, 'inherited');
                }
            }
            foreach ($spec['assessments'] as $a) {
                $aid = Specs::saveAssessment($vid, null, $a);
                foreach ($a['clos'] as $c) {
                    Specs::setAssessmentClo($aid, $cloIds[$c], true);
                }
            }
            Specs::saveNarrative($vid, $spec['objectives'], $spec['strategies']);
            if ($spec['topics']) {
                Specs::saveTopics($vid, $spec['topics']);
            }
            if ($spec['resources']) {
                Specs::saveResources($vid, $spec['resources']);
            }
            if ($mode === 'baseline') {
                Specs::approve($vid, 'import', $actor['type'] === 'user' ? $actor['id'] : null, 'Baseline imported: ' . $reason);
            } else {
                $o = Db::one('SELECT o.id, o.instructor_id FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.course_id = ? AND t.status <> "closed" ORDER BY t.sequence DESC LIMIT 1', [$courseId]);
                if ($o && $o['instructor_id']) {
                    Notify::user((int) $o['instructor_id'], 'action', "$code: imported specification ready for your review", 'Your existing specification was imported into SAQF as a draft. Check the outcomes and assessments, then submit it.', 'workspace.php?id=' . $o['id'] . '&tab=structure', 'spec-import:' . $vid);
                }
            }
            return $vid;
        });
    }

    /** "CLO1; CLO2, clo3" → ["CLO1","CLO2","CLO3"] */
    private static function list($value, bool $upper = false): array
    {
        $out = [];
        foreach (preg_split('/[;,|]/', (string) $value) ?: [] as $v) {
            $v = trim($v);
            if ($v !== '') {
                $out[] = $upper ? strtoupper($v) : $v;
            }
        }
        return array_values(array_unique($out));
    }
}

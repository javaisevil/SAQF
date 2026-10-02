<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Notify;
use Saqf\Core\Policy;

/**
 * Course sections. A course offering has one coordinator (course_offerings.instructor_id), who owns
 * the specification, the improvement actions and the course report. Each section can be taught by
 * a different instructor; section instructors see the course, contribute results and evidence for
 * their section, and achievement is broken down per section so differences between sections show.
 */
final class Sections
{
    /** "1", "01", " 1 " → "01"; "A" → "A". */
    public static function code($raw): ?string
    {
        $s = strtoupper(trim((string) $raw));
        if ($s === '') {
            return null;
        }
        if (ctype_digit($s)) {
            return str_pad((string) (int) $s, 2, '0', STR_PAD_LEFT);
        }
        return mb_substr((string) preg_replace('/[^A-Z0-9\-]/', '', $s), 0, 10) ?: null;
    }

    /** @return list<array{section_code:string,instructor_id:?int,instructor_name:?string,enrolled:int}> */
    public static function forOffering(int $offeringId): array
    {
        return array_map(static function ($r) {
            $r['instructor_id'] = $r['instructor_id'] === null ? null : (int) $r['instructor_id'];
            $r['enrolled'] = (int) $r['enrolled'];
            return $r;
        }, Db::all('SELECT s.section_code, s.instructor_id, s.enrolled, u.full_name AS instructor_name FROM offering_sections s LEFT JOIN users u ON u.id = s.instructor_id WHERE s.offering_id = ? ORDER BY s.section_code', [$offeringId]));
    }

    /** Sections of an offering taught by this person. @return list<string> */
    public static function taughtBy(int $offeringId, int $userId): array
    {
        return array_map('strval', Db::col('SELECT section_code FROM offering_sections WHERE offering_id = ? AND instructor_id = ? ORDER BY section_code', [$offeringId, $userId]));
    }

    /**
     * Replaces an offering's sections with what the SIS reports. New section instructors are told
     * about the course; removed sections disappear (their results stay, tagged with the section).
     * @param list<array{section:string,instructor_id:?int,enrolled:int}> $rows
     * @return array{added:int,changed:int,removed:int}
     */
    public static function sync(int $offeringId, array $rows): array
    {
        $o = Db::one('SELECT o.*, c.code, t.name AS term_name, t.status AS term_status FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE o.id = ?', [$offeringId]);
        $existing = [];
        foreach (self::forOffering($offeringId) as $s) {
            $existing[$s['section_code']] = $s;
        }
        $seen = [];
        $stats = ['added' => 0, 'changed' => 0, 'removed' => 0];
        foreach ($rows as $r) {
            $code = self::code($r['section'] ?? '');
            if ($code === null || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $instructor = $r['instructor_id'] ?? null;
            $enrolled = max(0, (int) ($r['enrolled'] ?? 0));
            $old = $existing[$code] ?? null;
            if ($old && $old['instructor_id'] === $instructor && $old['enrolled'] === $enrolled) {
                continue;
            }
            Db::exec(
                'INSERT INTO offering_sections (offering_id, section_code, instructor_id, enrolled, updated_at) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE instructor_id = VALUES(instructor_id), enrolled = VALUES(enrolled), updated_at = VALUES(updated_at)',
                [$offeringId, $code, $instructor, $enrolled, Clock::stamp()]
            );
            $stats[$old ? 'changed' : 'added']++;
            if ($instructor && (!$old || $old['instructor_id'] !== $instructor) && $instructor !== (int) $o['instructor_id'] && $o['term_status'] !== 'closed') {
                $coordinator = $o['instructor_id'] ? Db::val('SELECT full_name FROM users WHERE id = ?', [$o['instructor_id']]) : null;
                Notify::user($instructor, 'info', "You teach section $code of {$o['code']} ({$o['term_name']})", 'The course workspace is ready: outcomes and assessments are shared by every section' . ($coordinator ? "; $coordinator coordinates the course" : '') . '. Results for your section count toward the course and are also shown per section.', 'workspace.php?id=' . $offeringId, 'section:' . $offeringId . ':' . $code . ':' . $instructor);
            }
        }
        foreach (array_diff_key($existing, $seen) as $code => $_) {
            Db::exec('DELETE FROM offering_sections WHERE offering_id = ? AND section_code = ?', [$offeringId, $code]);
            $stats['removed']++;
        }
        if ($stats['added'] || $stats['changed'] || $stats['removed']) {
            Db::update('course_offerings', ['sections' => max(1, count($seen))], 'id = ?', [$offeringId]);
            Audit::record('workspace.sections', 'offering', $offeringId, "{$o['code']} {$o['term_name']}: sections updated from SIS ({$stats['added']} added, {$stats['changed']} changed, {$stats['removed']} removed)", null, ['sections' => array_keys($seen)]);
        }
        return $stats;
    }

    /**
     * CLO achievement per section, with the same method and thresholds as the course figure.
     * @return array<string,array{instructor:?string,students:int,clos:array<int,array{value:float,n:int}>}>
     *         empty unless at least two sections have results
     */
    public static function achievement(int $offeringId): array
    {
        $o = Db::one('SELECT * FROM course_offerings WHERE id = ?', [$offeringId]);
        if (!$o || !$o['spec_version_id']) {
            return [];
        }
        $rows = Db::all('SELECT assessment_id, student_ref, section_code, score_pct FROM assessment_results WHERE offering_id = ? AND section_code IS NOT NULL', [$offeringId]);
        $bySection = [];
        foreach ($rows as $r) {
            $bySection[$r['section_code']][$r['student_ref']][(int) $r['assessment_id']] = (float) $r['score_pct'];
        }
        if (count($bySection) < 2) {
            return [];
        }
        ksort($bySection);
        $spec = Specs::load((int) $o['spec_version_id']);
        $weights = [];
        foreach ($spec['assessments'] as $a) {
            $weights[(int) $a['id']] = (float) $a['weight_pct'];
        }
        $names = [];
        foreach (self::forOffering($offeringId) as $s) {
            $names[$s['section_code']] = $s['instructor_name'];
        }
        $method = Policy::get('achievement.method');
        $threshold = Policy::get('achievement.student_threshold_pct');
        $out = [];
        foreach ($bySection as $code => $scores) {
            $clos = [];
            foreach ($spec['clos'] as $clo) {
                $v = Achievement::cloValue($scores, $clo['assessments'], $weights, $method, $threshold);
                if ($v !== null) {
                    $clos[(int) $clo['id']] = $v;
                }
            }
            $out[(string) $code] = ['instructor' => $names[$code] ?? null, 'students' => count($scores), 'clos' => $clos];
        }
        return $out;
    }

    /** Largest gap between sections per CLO, for the SECTION_GAP rule. @return list<array> */
    public static function gaps(int $offeringId, float $points, int $minStudents): array
    {
        $by = self::achievement($offeringId);
        if (!$by) {
            return [];
        }
        $o = Db::one('SELECT spec_version_id FROM course_offerings WHERE id = ?', [$offeringId]);
        $out = [];
        foreach (Db::all('SELECT id, code, lineage_key FROM clos WHERE spec_version_id = ? ORDER BY sort_order', [$o['spec_version_id']]) as $clo) {
            $vals = [];
            foreach ($by as $code => $s) {
                $v = $s['clos'][(int) $clo['id']] ?? null;
                if ($v !== null && $v['n'] >= $minStudents) {
                    $vals[$code] = $v['value'];
                }
            }
            if (count($vals) < 2) {
                continue;
            }
            arsort($vals);
            $high = array_key_first($vals);
            $low = array_key_last($vals);
            $gap = $vals[$high] - $vals[$low];
            if ($gap > $points + 1e-9) {
                $out[] = ['clo_id' => (int) $clo['id'], 'code' => $clo['code'], 'lineage_key' => $clo['lineage_key'], 'high' => (string) $high, 'high_value' => $vals[$high], 'low' => (string) $low, 'low_value' => $vals[$low], 'gap' => round($gap, 1)];
            }
        }
        return $out;
    }
}

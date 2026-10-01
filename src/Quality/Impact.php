<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Db;

/**
 * Impact analysis — what a significant academic change touches, shown before
 * anyone approves it. Uses the relational curriculum graph:
 * Program ↔ Course ↔ CLO ↔ PLO ↔ Assessment ↔ Results ↔ Improvement.
 */
final class Impact
{
    public static function forPlo(int $ploId): array
    {
        $rows = Db::all(
            'SELECT DISTINCT sv.id AS version_id, sv.status, c.id AS course_id, c.code, c.title, cl.id AS clo_id, cl.code AS clo_code, cl.lineage_key
             FROM clo_plo cp JOIN clos cl ON cl.id = cp.clo_id JOIN spec_versions sv ON sv.id = cl.spec_version_id JOIN courses c ON c.id = sv.course_id
             WHERE cp.plo_id = ? AND sv.status IN ("approved","draft","pending_hod","pending_qa") ORDER BY c.code',
            [$ploId]
        );
        $courses = [];
        $versions = [];
        $cloIds = [];
        $lineages = [];
        foreach ($rows as $r) {
            $courses[$r['code']] = $r['title'];
            $versions[(int) $r['version_id']] = true;
            $cloIds[] = (int) $r['clo_id'];
            $lineages[$r['lineage_key']] = true;
        }
        $assessments = $cloIds ? (int) Db::val('SELECT COUNT(DISTINCT assessment_id) FROM assessment_clo WHERE clo_id IN (' . Db::in($cloIds) . ')', $cloIds) : 0;
        $activeOfferings = $courses ? (int) Db::val('SELECT COUNT(*) FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE t.status <> "closed" AND c.code IN (' . Db::in(array_keys($courses)) . ')', array_keys($courses)) : 0;
        $keys = array_keys($lineages);
        $openActions = $keys ? (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE status IN ("draft","open","in_progress") AND clo_lineage_key IN (' . Db::in($keys) . ')', $keys) : 0;
        $history = (int) Db::val('SELECT COUNT(*) FROM plo_achievement WHERE plo_id = ?', [$ploId]);
        return [
            'courses' => $courses,
            'spec_versions' => array_keys($versions),
            'mappings' => count($rows),
            'assessments' => $assessments,
            'active_offerings' => $activeOfferings,
            'open_actions' => $openActions,
            'achievement_records' => $history,
        ];
    }

    /** Impact of a specification revision (used on the HoD approval screen). */
    public static function forRevision(int $versionId): array
    {
        $v = Specs::version($versionId);
        $out = ['programs' => [], 'coverage_risks' => [], 'offerings' => [], 'history_breaks' => [], 'orphaned_actions' => 0, 'dependents' => []];
        if (!$v) {
            return $out;
        }
        $courseId = (int) $v['course_id'];
        foreach (Catalog::programsFor($courseId) as $p) {
            $out['programs'][] = $p['code'] . ' (' . $p['course_type'] . ', ' . $p['requirement_group'] . ')';
        }
        $out['offerings'] = Db::all('SELECT o.id, t.name AS term_name, u.full_name, (SELECT COUNT(*) FROM assessment_results r WHERE r.offering_id = o.id) AS results FROM course_offerings o JOIN terms t ON t.id = o.term_id LEFT JOIN users u ON u.id = o.instructor_id WHERE o.course_id = ? AND t.status <> "closed"', [$courseId]);
        $out['dependents'] = Catalog::dependents($courseId);
        if (!$v['based_on_id']) {
            return $out;
        }
        $newLineages = Db::col('SELECT lineage_key FROM clos WHERE spec_version_id = ?', [$versionId]);
        $oldLineages = Db::all('SELECT lineage_key, code FROM clos WHERE spec_version_id = ?', [$v['based_on_id']]);
        foreach ($oldLineages as $o) {
            if (!in_array($o['lineage_key'], $newLineages, true)) {
                $records = (int) Db::val('SELECT COUNT(*) FROM clo_achievement WHERE lineage_key = ?', [$o['lineage_key']]);
                $out['history_breaks'][] = "{$o['code']} removed — its $records historical achievement record(s) stay archived but stop trending";
                $out['orphaned_actions'] += (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE clo_lineage_key = ? AND status IN ("draft","open","in_progress")', [$o['lineage_key']]);
            }
        }
        // PLOs that would lose their only contributing course.
        $oldPlos = Db::col('SELECT DISTINCT cp.plo_id FROM clo_plo cp JOIN clos c ON c.id = cp.clo_id WHERE c.spec_version_id = ?', [$v['based_on_id']]);
        $newPlos = Db::col('SELECT DISTINCT cp.plo_id FROM clo_plo cp JOIN clos c ON c.id = cp.clo_id WHERE c.spec_version_id = ?', [$versionId]);
        foreach (array_diff($oldPlos, $newPlos) as $ploId) {
            $others = (int) Db::val(
                'SELECT COUNT(DISTINCT sv.course_id) FROM clo_plo cp JOIN clos c ON c.id = cp.clo_id JOIN spec_versions sv ON sv.id = c.spec_version_id WHERE cp.plo_id = ? AND sv.status = "approved" AND sv.course_id <> ?',
                [$ploId, $courseId]
            );
            $plo = Db::one('SELECT p.code, pr.code AS program FROM plos p JOIN programs pr ON pr.id = p.program_id WHERE p.id = ?', [$ploId]);
            $out['coverage_risks'][] = "{$plo['program']} {$plo['code']} would lose this course's contribution" . ($others === 0 ? ' — and no other course develops it (curriculum gap)' : " ($others other course(s) still contribute)");
        }
        return $out;
    }
}

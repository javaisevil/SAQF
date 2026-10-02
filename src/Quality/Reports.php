<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Policy;

/**
 * Reports are views over structured data — generated, never re-typed. A closed
 * cycle is frozen into an immutable, hashed snapshot for historical evidence.
 */
final class Reports
{
    public static function courseReport(int $offeringId): array
    {
        $o = Db::one(
            'SELECT o.*, c.code, c.title, c.credits, c.description, d.name AS department, col.name AS college, t.name AS term_name, t.code AS term_code,
                    u.full_name AS instructor, sv.version_no, sv.decided_at AS spec_approved_at, sv.decision_route
             FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN departments d ON d.id = c.owner_department_id JOIN colleges col ON col.id = d.college_id
             JOIN terms t ON t.id = o.term_id LEFT JOIN users u ON u.id = o.instructor_id LEFT JOIN spec_versions sv ON sv.id = o.spec_version_id WHERE o.id = ?',
            [$offeringId]
        );
        if (!$o) {
            return [];
        }
        $spec = $o['spec_version_id'] ? Specs::load((int) $o['spec_version_id']) : null;
        $ach = [];
        foreach (Db::all('SELECT * FROM clo_achievement WHERE offering_id = ?', [$offeringId]) as $a) {
            $ach[(int) $a['clo_id']] = $a;
        }
        $clos = [];
        foreach ($spec['clos'] ?? [] as $c) {
            $maps = [];
            foreach ($c['maps'] as $list) {
                foreach ($list as $m) {
                    $maps[] = Db::val('SELECT code FROM programs WHERE id = ?', [$m['program_id']]) . ' ' . $m['code'];
                }
            }
            $a = $ach[(int) $c['id']] ?? null;
            $clos[] = [
                'id' => (int) $c['id'], 'code' => $c['code'], 'statement' => $c['statement'], 'domain' => $c['domain'], 'plos' => $maps,
                'assessments' => array_values(array_map(static fn($aid) => current(array_filter($spec['assessments'], static fn($x) => (int) $x['id'] === $aid))['name'] ?? '', $c['assessments'])),
                'target' => $a ? (float) $a['target_pct'] : (float) ($c['target_pct'] ?? Policy::get('clo.default_target_pct')),
                'target_source' => $c['target_pct'] !== null ? 'course' : 'institutional default',
                'value' => $a ? (float) $a['value_pct'] : null, 'met' => $a ? (bool) $a['met'] : null,
                'students' => $a ? (int) $a['students_assessed'] : null, 'provisional' => $a ? (bool) $a['provisional'] : null,
            ];
        }
        $assessments = [];
        foreach ($spec['assessments'] ?? [] as $a) {
            $stats = Db::one('SELECT COUNT(*) n, AVG(score_pct) mean, MIN(score_pct) mn, MAX(score_pct) mx FROM assessment_results WHERE offering_id = ? AND assessment_id = ?', [$offeringId, $a['id']]);
            $assessments[] = ['name' => $a['name'], 'kind' => $a['kind'], 'weight' => (float) $a['weight_pct'], 'week' => $a['week'], 'n' => (int) $stats['n'], 'mean' => $stats['n'] ? round((float) $stats['mean'], 1) : null];
        }
        $plo = Db::all('SELECT pa.*, p.code AS plo_code, pr.code AS program_code FROM plo_achievement pa JOIN plos p ON p.id = pa.plo_id JOIN programs pr ON pr.id = pa.program_id WHERE pa.offering_id = ? ORDER BY pr.code, p.code', [$offeringId]);
        $narr = [];
        foreach (Db::all('SELECT section_key, content, updated_at FROM offering_narratives WHERE offering_id = ?', [$offeringId]) as $n) {
            $narr[$n['section_key']] = $n['content'];
        }
        $actions = Db::all('SELECT ia.*, u.full_name AS owner_name FROM improvement_actions ia LEFT JOIN users u ON u.id = ia.owner_id WHERE ia.origin_offering_id = ? AND ia.status <> "cancelled"', [$offeringId]);
        $followups = Db::all('SELECT ia.*, t.name AS origin_term FROM improvement_actions ia JOIN course_offerings oo ON oo.id = ia.origin_offering_id JOIN terms t ON t.id = oo.term_id WHERE ia.followup_offering_id = ?', [$offeringId]);
        $batches = Db::all('SELECT source, external_ref, imported_at, rows_count, assessments, checksum FROM result_batches WHERE offering_id = ? ORDER BY imported_at', [$offeringId]);
        return [
            'offering' => $o,
            'programs' => Catalog::programsFor((int) $o['course_id']),
            'clos' => $clos,
            'assessments' => $assessments,
            'plo' => $plo,
            'narrative' => $narr,
            'actions' => $actions,
            'followups' => $followups,
            'evidence' => $batches,
            'evidence_files' => Evidence::forOffering($offeringId),
            'sections' => Sections::forOffering($offeringId),
            'by_section' => Sections::achievement($offeringId),
            'grades' => self::gradeDistribution($offeringId),
            'assessed_students' => (int) Db::val('SELECT COUNT(DISTINCT student_ref) FROM assessment_results WHERE offering_id = ?', [$offeringId]),
            'method' => [
                'achievement' => Policy::get('achievement.method'),
                'student_threshold' => Policy::get('achievement.student_threshold_pct'),
                'default_target' => Policy::get('clo.default_target_pct'),
            ],
            'generated_at' => Clock::stamp(),
        ];
    }

    /** Course grade bands used for the distribution in the course report (confirm against the university's scale). */
    public const GRADE_BANDS = ['A+' => 95, 'A' => 90, 'B+' => 85, 'B' => 80, 'C+' => 75, 'C' => 70, 'D+' => 65, 'D' => 60, 'F' => 0];

    /**
     * Grade distribution once every assessment has results: each student's weighted total over the
     * specification's assessments, banded. @return array<string,int>|null null while results are incomplete
     */
    public static function gradeDistribution(int $offeringId): ?array
    {
        $o = Db::one('SELECT spec_version_id FROM course_offerings WHERE id = ?', [$offeringId]);
        if (!$o || !$o['spec_version_id']) {
            return null;
        }
        $weights = [];
        foreach (Db::all('SELECT id, weight_pct FROM assessments WHERE spec_version_id = ?', [$o['spec_version_id']]) as $a) {
            $weights[(int) $a['id']] = (float) $a['weight_pct'];
        }
        $totalWeight = array_sum($weights);
        if (!$weights || $totalWeight <= 0) {
            return null;
        }
        $scores = [];
        foreach (Db::all('SELECT assessment_id, student_ref, score_pct FROM assessment_results WHERE offering_id = ?', [$offeringId]) as $r) {
            $scores[$r['student_ref']][(int) $r['assessment_id']] = (float) $r['score_pct'];
        }
        $complete = array_filter($scores, static fn($by) => count(array_intersect_key($weights, $by)) === count($weights));
        if (!$complete || count($complete) < count($scores) * 0.8) {
            return null;
        }
        $dist = array_fill_keys(array_keys(self::GRADE_BANDS), 0);
        foreach ($complete as $by) {
            $total = 0.0;
            foreach ($weights as $aid => $w) {
                $total += $by[$aid] * $w / $totalWeight;
            }
            foreach (self::GRADE_BANDS as $band => $min) {
                if ($total >= $min - 1e-9) {
                    $dist[$band]++;
                    break;
                }
            }
        }
        return $dist;
    }

    public static function snapshotCourse(int $offeringId): ?int
    {
        $o = Db::one('SELECT o.*, c.code, t.name AS term_name FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE o.id = ?', [$offeringId]);
        if (!$o || Db::val('SELECT id FROM snapshots WHERE kind = "course_report" AND scope_id = ? AND term_id = ?', [$offeringId, $o['term_id']])) {
            return null;
        }
        $payload = json_encode(self::courseReport($offeringId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $id = Db::insert('snapshots', [
            'kind' => 'course_report', 'scope_id' => $offeringId, 'term_id' => $o['term_id'],
            'title' => "{$o['code']} course report — {$o['term_name']}", 'payload' => $payload, 'sha256' => hash('sha256', $payload),
            'created_by' => $_SESSION['uid'] ?? null, 'created_at' => Clock::stamp(),
        ]);
        Audit::record('snapshot.created', 'snapshot', $id, "Immutable snapshot frozen: {$o['code']} course report — {$o['term_name']}", null, ['sha256' => hash('sha256', $payload)]);
        return $id;
    }

    /** Program intelligence: coverage matrix, achievement by term, gaps, computed KPIs. */
    public static function program(int $programId): array
    {
        $p = Db::one('SELECT p.*, d.name AS department, col.name AS college FROM programs p JOIN departments d ON d.id = p.department_id JOIN colleges col ON col.id = d.college_id WHERE p.id = ?', [$programId]);
        $plos = Db::all('SELECT * FROM plos WHERE program_id = ? AND status = "approved" ORDER BY code', [$programId]);
        $plan = Db::all(
            'SELECT spe.*, c.code, c.title, c.credits AS course_credits,
                    (SELECT sv.version_no FROM spec_versions sv WHERE sv.course_id = c.id AND sv.status = "approved" ORDER BY sv.version_no DESC LIMIT 1) AS spec_version
             FROM study_plan_entries spe LEFT JOIN courses c ON c.id = spe.course_id WHERE spe.program_id = ?
             ORDER BY spe.plan_year IS NULL, spe.plan_year, spe.plan_semester, spe.requirement_group, c.code',
            [$programId]
        );
        $matrix = [];
        foreach (Db::all(
            'SELECT c.code, cp.plo_id, COUNT(*) AS n FROM clo_plo cp JOIN clos cl ON cl.id = cp.clo_id JOIN spec_versions sv ON sv.id = cl.spec_version_id AND sv.status = "approved"
             JOIN courses c ON c.id = sv.course_id JOIN plos pl ON pl.id = cp.plo_id WHERE pl.program_id = ? GROUP BY c.code, cp.plo_id',
            [$programId]
        ) as $r) {
            $matrix[$r['code']][(int) $r['plo_id']] = (int) $r['n'];
        }
        $series = [];
        foreach (Db::all(
            'SELECT pa.plo_id, t.id AS term_id, t.name, t.sequence, AVG(pa.value_pct) v, COUNT(DISTINCT pa.offering_id) n, MAX(pa.provisional) prov
             FROM plo_achievement pa JOIN course_offerings o ON o.id = pa.offering_id JOIN terms t ON t.id = o.term_id
             WHERE pa.program_id = ? GROUP BY pa.plo_id, t.id, t.name, t.sequence ORDER BY t.sequence',
            [$programId]
        ) as $r) {
            $series[(int) $r['plo_id']][] = $r;
        }
        $required = array_filter($plan, static fn($e) => $e['course_id'] && $e['course_type'] === 'required');
        $withSpec = array_filter($required, static fn($e) => $e['spec_version'] !== null);
        $courseIds = array_values(array_unique(array_filter(array_map(static fn($e) => $e['course_id'], $plan))));
        $actions = $courseIds ? Db::all('SELECT status, effect FROM improvement_actions WHERE course_id IN (' . Db::in($courseIds) . ') AND status <> "cancelled"', $courseIds) : [];
        $committed = array_filter($actions, static fn($a) => $a['status'] !== 'draft');
        $completed = array_filter($actions, static fn($a) => $a['status'] === 'completed');
        $evaluated = array_filter($actions, static fn($a) => !in_array($a['effect'], ['pending', 'not_measurable'], true));
        $improved = array_filter($evaluated, static fn($a) => $a['effect'] === 'improved');
        $lastTerm = Db::one('SELECT t.id, t.name FROM clo_achievement ca JOIN course_offerings o ON o.id = ca.offering_id JOIN terms t ON t.id = o.term_id WHERE ca.provisional = 0 AND t.status = "closed" AND o.course_id IN (' . Db::in($courseIds ?: [0]) . ') ORDER BY t.sequence DESC LIMIT 1', $courseIds ?: [0]);
        $met = $lastTerm ? Db::one('SELECT SUM(ca.met) m, COUNT(*) n FROM clo_achievement ca JOIN course_offerings o ON o.id = ca.offering_id WHERE o.term_id = ? AND ca.provisional = 0 AND o.course_id IN (' . Db::in($courseIds) . ')', array_merge([$lastTerm['id']], $courseIds)) : null;
        return [
            'program' => $p,
            'plos' => $plos,
            'plan' => $plan,
            'matrix' => $matrix,
            'series' => $series,
            'kpis' => [
                ['label' => 'Required courses with an approved specification', 'value' => count($required) ? round(count($withSpec) / count($required) * 100) . '%' : '—', 'detail' => count($withSpec) . ' of ' . count($required)],
                ['label' => 'CLO targets met' . ($lastTerm ? ' (' . $lastTerm['name'] . ')' : ''), 'value' => $met && $met['n'] ? round($met['m'] / $met['n'] * 100) . '%' : '—', 'detail' => $met ? ((int) $met['m'] . ' of ' . (int) $met['n'] . ' measured CLOs') : 'no final results yet'],
                ['label' => 'Improvement actions completed', 'value' => $committed ? round(count($completed) / count($committed) * 100) . '%' : '—', 'detail' => count($completed) . ' of ' . count($committed) . ' committed'],
                ['label' => 'Improved at next measurement', 'value' => $evaluated ? round(count($improved) / count($evaluated) * 100) . '%' : '—', 'detail' => count($improved) . ' of ' . count($evaluated) . ' evaluated actions (association, not causation)'],
            ],
            'generated_at' => Clock::stamp(),
        ];
    }
}

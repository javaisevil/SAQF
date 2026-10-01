<?php
declare(strict_types=1);

namespace Saqf\Quality;

use InvalidArgumentException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Events;
use Saqf\Core\Ledger;
use Saqf\Core\Policy;
use Saqf\Integration\Integrations;

/**
 * Achievement pipeline:
 *   assessment results → student CLO scores (weighted by assessment weight)
 *   → CLO achievement (configurable method) → PLO contribution per program.
 * The method and thresholds are institutional policy, not hard-coded formulas.
 */
final class Achievement
{
    /** Pull newly published LMS batches for an offering. Returns number of batches imported. */
    public static function syncFromLms(int $offeringId): int
    {
        $o = Db::one('SELECT o.*, c.code AS course_code, t.code AS term_code FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE o.id = ?', [$offeringId]);
        if (!$o || !$o['spec_version_id']) {
            return 0;
        }
        $n = 0;
        foreach (Integrations::lms()->batches($o['term_code'], $o['course_code']) as $batch) {
            if (Db::val('SELECT 1 FROM result_batches WHERE offering_id = ? AND external_ref = ?', [$offeringId, $batch['ref']])) {
                continue;
            }
            Audit::asSystem(static function () use ($offeringId, $batch, &$n) {
                self::import($offeringId, $batch['results'], 'lms', $batch['ref']);
                $n++;
            }, 'integration', 'LMS integration');
        }
        return $n;
    }

    /**
     * Imports results: assessment name => [student_ref => score_pct].
     * Assessment names are matched to the offering's specification; unknown names are rejected.
     */
    public static function import(int $offeringId, array $results, string $source, ?string $ref = null): array
    {
        $o = Db::one('SELECT * FROM course_offerings WHERE id = ?', [$offeringId]);
        if (!$o || !$o['spec_version_id']) {
            throw new InvalidArgumentException('This offering has no approved specification to attach results to.');
        }
        $assessments = [];
        foreach (Db::all('SELECT id, name FROM assessments WHERE spec_version_id = ?', [$o['spec_version_id']]) as $a) {
            $assessments[mb_strtolower(trim($a['name']))] = (int) $a['id'];
        }
        $unknown = [];
        $rows = 0;
        foreach ($results as $name => $scores) {
            if (!isset($assessments[mb_strtolower(trim((string) $name))])) {
                $unknown[] = $name;
            }
        }
        if ($unknown) {
            throw new InvalidArgumentException('These assessments are not in the course specification: ' . implode(', ', $unknown) . '. Results must use the specification\'s assessment names.');
        }
        $checksum = hash('sha256', json_encode($results));
        $batchId = Db::tx(static function () use ($offeringId, $results, $assessments, $source, $ref, $checksum, &$rows) {
            $batchId = Db::insert('result_batches', [
                'offering_id' => $offeringId, 'source' => $source, 'external_ref' => $ref ?? ('upload-' . substr($checksum, 0, 12)),
                'imported_by' => $_SESSION['uid'] ?? null, 'imported_at' => Clock::stamp(), 'rows_count' => 0,
                'assessments' => mb_substr(implode(', ', array_keys($results)), 0, 400), 'checksum' => $checksum,
            ]);
            foreach ($results as $name => $scores) {
                $aid = $assessments[mb_strtolower(trim((string) $name))];
                foreach ($scores as $student => $score) {
                    $student = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $student);
                    $score = max(0.0, min(100.0, (float) $score));
                    if ($student === '') {
                        continue;
                    }
                    Db::exec(
                        'INSERT INTO assessment_results (offering_id, assessment_id, student_ref, score_pct, batch_id) VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE score_pct = VALUES(score_pct), batch_id = VALUES(batch_id)',
                        [$offeringId, $aid, $student, $score, $batchId]
                    );
                    $rows++;
                }
            }
            Db::update('result_batches', ['rows_count' => $rows], 'id = ?', [$batchId]);
            return $batchId;
        });
        $total = (int) Db::val('SELECT COUNT(*) FROM assessments WHERE spec_version_id = ?', [$o['spec_version_id']]);
        $withResults = (int) Db::val('SELECT COUNT(DISTINCT assessment_id) FROM assessment_results WHERE offering_id = ?', [$offeringId]);
        $status = $withResults >= $total ? 'results_complete' : 'results_partial';
        if ($o['status'] !== 'closed') {
            Db::update('course_offerings', ['status' => $status], 'id = ?', [$offeringId]);
        }
        Audit::record('results.imported', 'offering', $offeringId, "$rows result rows imported from " . strtoupper($source) . ' (' . implode(', ', array_keys($results)) . ')', null, ['batch' => $batchId, 'ref' => $ref]);
        Ledger::add('evidence_linked', $rows, $offeringId, (int) $o['course_id'], 'Assessment results linked to CLOs');
        Events::emit('results.imported', ['offering_id' => $offeringId, 'batch_id' => $batchId]);
        return ['batch_id' => $batchId, 'rows' => $rows, 'status' => $status];
    }

    /** @return array{clos:int} */
    public static function compute(int $offeringId): array
    {
        $o = Db::one('SELECT o.*, t.status AS term_status FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.id = ?', [$offeringId]);
        if (!$o || !$o['spec_version_id']) {
            return ['clos' => 0];
        }
        $spec = Specs::load((int) $o['spec_version_id']);
        $method = Policy::get('achievement.method');
        $studentThreshold = Policy::get('achievement.student_threshold_pct');
        $defaultTarget = Policy::get('clo.default_target_pct');

        $weights = [];
        foreach ($spec['assessments'] as $a) {
            $weights[(int) $a['id']] = (float) $a['weight_pct'];
        }
        // student => assessment => score
        $scores = [];
        foreach (Db::all('SELECT assessment_id, student_ref, score_pct FROM assessment_results WHERE offering_id = ?', [$offeringId]) as $r) {
            $scores[$r['student_ref']][(int) $r['assessment_id']] = (float) $r['score_pct'];
        }
        $now = Clock::stamp();
        $computed = 0;
        $cloValues = [];
        foreach ($spec['clos'] as $clo) {
            $linked = $clo['assessments'];
            $linkedWeight = array_sum(array_map(static fn($id) => $weights[$id] ?? 0, $linked));
            if (!$linked || $linkedWeight <= 0) {
                continue;
            }
            $withData = [];
            foreach ($scores as $byAssessment) {
                foreach ($linked as $aid) {
                    if (isset($byAssessment[$aid])) {
                        $withData[$aid] = true;
                    }
                }
            }
            if (!$withData) {
                Db::exec('DELETE FROM clo_achievement WHERE offering_id = ? AND clo_id = ?', [$offeringId, $clo['id']]);
                continue;
            }
            $coverage = array_sum(array_map(static fn($id) => $weights[$id] ?? 0, array_keys($withData))) / $linkedWeight * 100;
            $studentScores = [];
            foreach ($scores as $byAssessment) {
                $num = 0.0;
                $den = 0.0;
                foreach ($linked as $aid) {
                    if (isset($byAssessment[$aid]) && ($weights[$aid] ?? 0) > 0) {
                        $num += $byAssessment[$aid] * $weights[$aid];
                        $den += $weights[$aid];
                    }
                }
                if ($den > 0) {
                    $studentScores[] = $num / $den;
                }
            }
            $n = count($studentScores);
            if ($n === 0) {
                continue;
            }
            $value = round($method === 'average'
                ? array_sum($studentScores) / $n
                : count(array_filter($studentScores, static fn($s) => $s >= $studentThreshold - 1e-9)) / $n * 100, 2);
            $target = $clo['target_pct'] !== null ? (float) $clo['target_pct'] : $defaultTarget;
            $provisional = $coverage < 99.99 ? 1 : 0;
            Db::exec(
                'INSERT INTO clo_achievement (offering_id, clo_id, lineage_key, method, value_pct, target_pct, met, students_assessed, coverage_pct, provisional, computed_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE method = VALUES(method), value_pct = VALUES(value_pct), target_pct = VALUES(target_pct), met = VALUES(met),
                   students_assessed = VALUES(students_assessed), coverage_pct = VALUES(coverage_pct), provisional = VALUES(provisional), computed_at = VALUES(computed_at)',
                [$offeringId, $clo['id'], $clo['lineage_key'], $method, $value, $target, $value >= $target - 1e-9 ? 1 : 0, $n, round($coverage, 2), $provisional, $now]
            );
            $cloValues[(int) $clo['id']] = ['value' => $value, 'provisional' => $provisional, 'maps' => $clo['maps']];
            $computed++;
        }

        // PLO contribution: mean of the mapped CLO achievements, per program.
        Db::exec('DELETE FROM plo_achievement WHERE offering_id = ?', [$offeringId]);
        $byPlo = [];
        foreach ($cloValues as $cv) {
            foreach ($cv['maps'] as $programId => $list) {
                foreach ($list as $m) {
                    $byPlo[$programId . ':' . $m['id']][] = $cv;
                }
            }
        }
        foreach ($byPlo as $key => $list) {
            [$programId, $ploId] = array_map('intval', explode(':', $key));
            $avg = array_sum(array_column($list, 'value')) / count($list);
            $prov = max(array_column($list, 'provisional'));
            Db::insert('plo_achievement', ['offering_id' => $offeringId, 'program_id' => $programId, 'plo_id' => $ploId, 'value_pct' => round($avg, 2), 'contributing_clos' => count($list), 'provisional' => $prov, 'computed_at' => $now]);
            $computed++;
        }
        Ledger::add('calculation', $computed, $offeringId, (int) $o['course_id'], 'CLO and PLO achievement');
        Audit::asSystem(static fn() => Audit::record('achievement.computed', 'offering', $offeringId, count($cloValues) . ' CLO and ' . count($byPlo) . " PLO achievement values calculated ($method method)"));
        Events::emit('achievement.computed', ['offering_id' => $offeringId]);
        return ['clos' => count($cloValues)];
    }

    /** Achievement history of a CLO lineage across offerings (institutional memory). */
    public static function history(string $lineageKey): array
    {
        return Db::all(
            'SELECT ca.*, t.name AS term_name, t.sequence, o.id AS offering_id, u.full_name AS instructor
             FROM clo_achievement ca JOIN course_offerings o ON o.id = ca.offering_id JOIN terms t ON t.id = o.term_id LEFT JOIN users u ON u.id = o.instructor_id
             WHERE ca.lineage_key = ? ORDER BY t.sequence',
            [$lineageKey]
        );
    }
}

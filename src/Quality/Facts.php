<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Db;
use Saqf\Core\Policy;

/**
 * "Facts for your reading": plain statements worked out from SAQF's records for one course in one term, shown
 * next to the report's "What the results mean" box. They save the instructor the digging; they say WHAT the
 * numbers are, never what they mean, why they happened or what to do about it. That is the instructor's
 * academic judgement, and SAQF does not write it (nothing here is inserted into the report automatically).
 * Every line is deterministic: the same records always give the same sentences.
 */
final class Facts
{
    /** @return list<array{tone:string,text:string}> tone: ok | warn | info */
    public static function forOffering(array $o): array
    {
        $oid = (int) $o['id'];
        $out = [];
        if (!$o['spec_version_id']) {
            return [['tone' => 'info', 'text' => 'There is no approved specification yet, so there is nothing to measure.']];
        }
        $spec = Specs::load((int) $o['spec_version_id']);
        $ach = [];
        foreach (Db::all('SELECT * FROM clo_achievement WHERE offering_id = ?', [$oid]) as $a) {
            $ach[$a['lineage_key']] = $a;
        }
        $term = Db::one('SELECT sequence FROM terms WHERE id = ?', [$o['term_id']]);
        $seq = (int) ($term['sequence'] ?? 0);

        // Grades received -----------------------------------------------------------------------------------
        $graded = array_map('intval', Db::col('SELECT DISTINCT assessment_id FROM assessment_results WHERE offering_id = ?', [$oid]));
        $assessments = $spec['assessments'] ?? [];
        $missing = array_values(array_filter($assessments, static fn($a) => !in_array((int) $a['id'], $graded, true)));
        if (!$assessments) {
            $out[] = ['tone' => 'info', 'text' => 'The assessment plan is empty.'];
        } elseif ($missing) {
            $out[] = ['tone' => 'warn', 'text' => count($assessments) - count($missing) . ' of ' . count($assessments) . ' assessments have grades. Still to come: ' . implode(', ', array_column($missing, 'name')) . '. The figures below are "so far".'];
        } else {
            $out[] = ['tone' => 'ok', 'text' => 'All ' . count($assessments) . ' assessments have grades.'];
        }

        // Each learning outcome ------------------------------------------------------------------------------
        $sections = Sections::achievement($oid);
        $gapPts = (float) Policy::get('section.gap_points');
        foreach ($spec['clos'] as $clo) {
            $a = $ach[$clo['lineage_key']] ?? null;
            $code = $clo['code'];
            if (!$a) {
                $out[] = ['tone' => 'info', 'text' => "$code: no result yet" . ($clo['assessments'] ? '.' : ' (no assessment measures it).')];
                continue;
            }
            $value = (int) round((float) $a['value_pct']);
            $target = (int) round((float) $a['target_pct']);
            $so = (int) $a['provisional'] ? ' so far' : '';
            $line = Policy::get('achievement.method') === 'average'
                ? "$code: the average mark$so is $value% (goal $target%)"
                : "$code: $value% of students met it$so (goal $target%)";
            $line .= (int) $a['met'] ? '' : ', ' . max(1, $target - $value) . ' point' . (($target - $value) === 1 ? '' : 's') . ' short';
            $line .= ' · ' . (int) $a['students_assessed'] . ' students';
            // Compared with the previous term in which this outcome was measured.
            $prev = null;
            foreach (Achievement::history($clo['lineage_key']) as $h) {
                if ((int) $h['sequence'] < $seq && !(int) $h['provisional']) {
                    $prev = $h;
                }
            }
            if ($prev) {
                $d = (int) round((float) $a['value_pct'] - (float) $prev['value_pct']);
                $line .= '; ' . ($d === 0 ? 'the same as' : abs($d) . ' point' . (abs($d) === 1 ? '' : 's') . ($d > 0 ? ' up from' : ' down from')) . ' ' . $prev['term_name'] . ' (' . (int) round((float) $prev['value_pct']) . '%)';
            }
            $out[] = ['tone' => (int) $a['met'] ? 'ok' : 'warn', 'text' => $line . '.'];
            // Section gap for this outcome.
            $vals = [];
            foreach ($sections as $code2 => $s) {
                if (isset($s['clos'][(int) $clo['id']])) {
                    $vals[$code2] = (float) $s['clos'][(int) $clo['id']]['value'];
                }
            }
            if (count($vals) > 1 && max($vals) - min($vals) > $gapPts + 1e-9) {
                $hi = array_search(max($vals), $vals, true);
                $lo = array_search(min($vals), $vals, true);
                $out[] = ['tone' => 'warn', 'text' => "$code: section $lo is " . (int) round(max($vals) - min($vals)) . " points behind section $hi."];
            }
        }

        // Improvement records ------------------------------------------------------------------------------
        $drafts = (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE origin_offering_id = ? AND status = "draft"', [$oid]);
        $planned = (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE origin_offering_id = ? AND status <> "draft" AND status <> "cancelled"', [$oid]);
        if ($drafts) {
            $out[] = ['tone' => 'warn', 'text' => $drafts . ' improvement record' . ($drafts === 1 ? ' is' : 's are') . ' prepared and waiting for your plan.'];
        } elseif ($planned) {
            $out[] = ['tone' => 'info', 'text' => $planned . ' improvement plan' . ($planned === 1 ? ' is' : 's are') . ' in place from this term.'];
        }
        // Last term's actions that this term's results can now judge.
        foreach (Db::all('SELECT ia.title, ia.effect, ia.baseline_pct, ia.followup_pct FROM improvement_actions ia WHERE ia.followup_offering_id = ? AND ia.effect IS NOT NULL', [$oid]) as $ia) {
            if ($ia['followup_pct'] !== null) {
                $out[] = ['tone' => 'info', 'text' => 'Last term\'s action "' . mb_strimwidth((string) $ia['title'], 0, 70, '…') . '": ' . (int) round((float) $ia['baseline_pct']) . '% before, ' . (int) round((float) $ia['followup_pct']) . '% now. Other things may also have played a part.'];
            }
        }
        // Evidence ---------------------------------------------------------------------------------------------
        $ev = (int) Db::val('SELECT COUNT(*) FROM evidence_files WHERE offering_id = ? AND deleted_at IS NULL', [$oid]);
        $out[] = ['tone' => $ev ? 'info' : 'warn', 'text' => $ev ? $ev . ' file' . ($ev === 1 ? '' : 's') . ' in the course file.' : 'The course file has no evidence yet.'];
        return $out;
    }
}

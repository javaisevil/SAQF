<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;

/**
 * Assisted insights — statistical and text-similarity heuristics, clearly separated
 * from deterministic rules. They are suggestions with their reasoning shown, never
 * applied without a person's confirmation, and never presented as fact or as "AI".
 */
final class Intelligence
{
    public const MAPPING_METHOD = 'Text similarity (TF-IDF cosine) between the CLO and PLO statements, plus learning-domain match';

    private static function upsert(string $fingerprint, array $data): void
    {
        $existing = Db::one('SELECT id, status FROM recommendations WHERE fingerprint = ?', [$fingerprint]);
        if ($existing) {
            if ($existing['status'] === 'stale') {
                Db::update('recommendations', ['status' => 'open'] + $data, 'id = ?', [$existing['id']]);
            } elseif ($existing['status'] === 'open') {
                Db::update('recommendations', $data, 'id = ?', [$existing['id']]);
            }
            return; // accepted / dismissed decisions are remembered
        }
        Db::insert('recommendations', $data + ['fingerprint' => $fingerprint, 'status' => 'open', 'created_at' => Clock::stamp()]);
    }

    private static function staleExcept(string $scopeType, int $scopeId, string $kind, array $keep): void
    {
        $params = [$scopeType, $scopeId, $kind];
        $sql = 'UPDATE recommendations SET status = "stale" WHERE scope_type = ? AND scope_id = ? AND kind = ? AND status = "open"';
        if ($keep) {
            $sql .= ' AND fingerprint NOT IN (' . Db::in($keep) . ')';
            $params = array_merge($params, $keep);
        }
        Db::exec($sql, $params);
    }

    /** Mapping suggestions for unmapped CLOs + overlapping CLO pairs. Only for editable drafts. */
    public static function forSpec(int $versionId): void
    {
        $v = Specs::version($versionId);
        if (!$v || $v['status'] !== 'draft') {
            return;
        }
        $s = Specs::load($versionId);
        $keepMap = [];
        foreach ($s['clos'] as $clo) {
            foreach ($s['programs'] as $p) {
                $pid = (int) $p['program_id'];
                if (!empty($clo['maps'][$pid]) || empty($s['plos'][$pid])) {
                    continue;
                }
                $cands = [];
                foreach ($s['plos'][$pid] as $plo) {
                    $cands[(int) $plo['id']] = $plo['statement'];
                }
                $sims = Text::similarity($clo['statement'], $cands);
                $best = null;
                foreach ($s['plos'][$pid] as $plo) {
                    $score = $sims[(int) $plo['id']]['score'] + ($plo['domain'] === $clo['domain'] ? 0.08 : 0);
                    if ($best === null || $score > $best['score']) {
                        $best = ['plo' => $plo, 'score' => $score, 'shared' => $sims[(int) $plo['id']]['shared']];
                    }
                }
                if (!$best || $best['score'] < 0.12) {
                    continue;
                }
                $fp = "map|{$clo['lineage_key']}|{$p['code']}|{$best['plo']['code']}";
                $keepMap[] = $fp;
                $why = [];
                if ($best['shared']) {
                    $why[] = 'shared concepts: ' . implode(', ', array_slice($best['shared'], 0, 5));
                }
                if ($best['plo']['domain'] === $clo['domain']) {
                    $why[] = 'same learning domain (' . $clo['domain'] . ')';
                }
                self::upsert($fp, [
                    'kind' => 'mapping', 'scope_type' => 'spec', 'scope_id' => $versionId,
                    'title' => "{$clo['code']} may align with {$p['code']} {$best['plo']['code']}",
                    'rationale' => ucfirst(implode('; ', $why)) . '. "' . mb_strimwidth($best['plo']['statement'], 0, 140, '…') . '"',
                    'method' => self::MAPPING_METHOD,
                    'confidence' => min(0.99, round($best['score'], 2)),
                    'payload' => ['clo_id' => $clo['id'], 'plo_id' => $best['plo']['id'], 'program' => $p['code']],
                ]);
            }
        }
        self::staleExcept('spec', $versionId, 'mapping', $keepMap);

        $keepOverlap = [];
        $clos = $s['clos'];
        for ($i = 0; $i < count($clos); $i++) {
            for ($j = $i + 1; $j < count($clos); $j++) {
                $sim = Text::similarity($clos[$i]['statement'], [$clos[$j]['statement']])[0];
                if ($sim['score'] >= 0.55 && Text::normalize($clos[$i]['statement']) !== Text::normalize($clos[$j]['statement'])) {
                    $fp = "overlap|{$clos[$i]['lineage_key']}|{$clos[$j]['lineage_key']}";
                    $keepOverlap[] = $fp;
                    self::upsert($fp, [
                        'kind' => 'overlap', 'scope_type' => 'spec', 'scope_id' => $versionId,
                        'title' => "Possible overlap between {$clos[$i]['code']} and {$clos[$j]['code']}",
                        'rationale' => 'The two statements share most of their key terms (' . implode(', ', array_slice($sim['shared'], 0, 5)) . '). Consider whether they assess distinct learning.',
                        'method' => 'Text similarity between CLO statements',
                        'confidence' => $sim['score'],
                        'payload' => ['a' => $clos[$i]['id'], 'b' => $clos[$j]['id']],
                    ]);
                }
            }
        }
        self::staleExcept('spec', $versionId, 'overlap', $keepOverlap);
    }

    /** Trends across offerings and unusual result distributions for one offering. */
    public static function forOffering(int $offeringId): void
    {
        $o = Db::one('SELECT o.*, c.code AS course_code FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE o.id = ?', [$offeringId]);
        if (!$o) {
            return;
        }
        $keep = [];
        foreach (Db::all('SELECT ca.*, cl.code FROM clo_achievement ca JOIN clos cl ON cl.id = ca.clo_id WHERE ca.offering_id = ? AND ca.provisional = 0', [$offeringId]) as $a) {
            $series = array_values(array_filter(Achievement::history($a['lineage_key']), static fn($h) => (int) $h['provisional'] === 0));
            if (count($series) < 3) {
                continue;
            }
            $ys = array_map(static fn($h) => (float) $h['value_pct'], $series);
            $n = count($ys);
            $xMean = ($n - 1) / 2;
            $yMean = array_sum($ys) / $n;
            $num = 0.0;
            $den = 0.0;
            foreach ($ys as $x => $y) {
                $num += ($x - $xMean) * ($y - $yMean);
                $den += ($x - $xMean) ** 2;
            }
            $slope = $den > 0 ? $num / $den : 0;
            if ($slope <= -2.0) {
                $fp = "trend|{$a['lineage_key']}";
                $keep[] = $fp;
                self::upsert($fp, [
                    'kind' => 'trend', 'scope_type' => 'offering', 'scope_id' => $offeringId, 'offering_id' => $offeringId,
                    'title' => "{$o['course_code']} {$a['code']} has declined across $n offerings",
                    'rationale' => 'Measured values ' . implode(' → ', array_map(static fn($h) => $h['term_name'] . ' ' . Rules::fmt((float) $h['value_pct']) . '%', $series)) . ' (about ' . Rules::fmt(abs($slope)) . ' points per offering).',
                    'method' => 'Least-squares trend over historical achievement',
                    'confidence' => null,
                    'payload' => ['lineage' => $a['lineage_key'], 'slope' => round($slope, 2)],
                ]);
            }
        }
        foreach (Db::all('SELECT a.id, a.name, COUNT(*) n, AVG(r.score_pct) m, SUM(r.score_pct < 50) fails FROM assessment_results r JOIN assessments a ON a.id = r.assessment_id WHERE r.offering_id = ? GROUP BY a.id, a.name', [$offeringId]) as $as) {
            if ((int) $as['n'] < 8) {
                continue;
            }
            $failRate = (int) $as['fails'] / (int) $as['n'] * 100;
            $mean = (float) $as['m'];
            if ($mean >= 95 || $failRate >= 50) {
                $fp = "anomaly|$offeringId|{$as['id']}";
                $keep[] = $fp;
                self::upsert($fp, [
                    'kind' => 'anomaly', 'scope_type' => 'offering', 'scope_id' => $offeringId, 'offering_id' => $offeringId,
                    'title' => "Unusual results in \"{$as['name']}\"",
                    'rationale' => $mean >= 95 ? 'Average score ' . Rules::fmt($mean) . '% — the assessment may not discriminate between levels of achievement.' : Rules::fmt($failRate) . '% of students scored below 50% — check the assessment design, timing or marking.',
                    'method' => 'Distribution check on assessment results',
                    'confidence' => null,
                    'payload' => ['assessment_id' => $as['id']],
                ]);
            }
        }
        self::staleExcept('offering', $offeringId, 'trend', array_values(array_filter($keep, static fn($k) => str_starts_with($k, 'trend'))));
        self::staleExcept('offering', $offeringId, 'anomaly', array_values(array_filter($keep, static fn($k) => str_starts_with($k, 'anomaly'))));
    }

    /** Human decision on a recommendation. Accepting a mapping applies it as a faculty action. */
    public static function decide(int $id, array $user, bool $accept): string
    {
        $r = Db::one('SELECT * FROM recommendations WHERE id = ? AND status = "open"', [$id]);
        if (!$r) {
            throw new \DomainException('This suggestion is no longer open.');
        }
        $payload = json_decode((string) $r['payload'], true) ?: [];
        if ($accept && $r['kind'] === 'mapping') {
            Specs::setMapping((int) $payload['clo_id'], (int) $payload['plo_id'], true, 'suggestion');
        }
        Db::update('recommendations', ['status' => $accept ? 'accepted' : 'dismissed', 'decided_by' => $user['id'], 'decided_at' => Clock::stamp()], 'id = ?', [$id]);
        Audit::record($accept ? 'suggestion.accepted' : 'suggestion.dismissed', 'recommendation', $id, ($accept ? 'Accepted' : 'Dismissed') . ' suggestion: ' . $r['title']);
        return $accept ? ($r['kind'] === 'mapping' ? 'Mapping added and re-validated.' : 'Noted.') : 'Suggestion dismissed; it will not be shown again.';
    }
}

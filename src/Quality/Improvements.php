<?php
declare(strict_types=1);

namespace Saqf\Quality;

use DomainException;
use InvalidArgumentException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Events;
use Saqf\Core\Ledger;
use Saqf\Core\Policy;

/**
 * Closing the loop: gap detected → evidence assembled → human academic response
 * (owner, deadline) → tracked → next offering measured → effect evaluated.
 * SAQF never invents the academic solution; it prepares everything around it.
 */
final class Improvements
{
    public const STATUS = ['draft' => 'Waiting for the instructor', 'open' => 'Planned', 'in_progress' => 'Under way', 'completed' => 'Done', 'cancelled' => 'Cancelled'];
    /** Next-term comparison, in plain words. It shows what followed the action, never claims the action caused it. */
    public const EFFECT = [
        'pending' => 'Results next term',
        'improved' => 'Results went up afterwards',
        'similar' => 'Results stayed about the same',
        'declined' => 'Results went down afterwards',
        'not_measurable' => 'Cannot be compared (the outcome is no longer assessed)',
    ];

    public static function draftForGaps(int $offeringId): int
    {
        $o = Db::one('SELECT o.*, c.code AS course_code, t.name AS term_name FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE o.id = ?', [$offeringId]);
        if (!$o) {
            return 0;
        }
        $gaps = Db::all('SELECT ca.*, cl.code, cl.statement FROM clo_achievement ca JOIN clos cl ON cl.id = ca.clo_id WHERE ca.offering_id = ? AND ca.met = 0 AND ca.provisional = 0', [$offeringId]);
        $n = 0;
        foreach ($gaps as $g) {
            $exists = Db::val('SELECT id FROM improvement_actions WHERE origin_offering_id = ? AND clo_lineage_key = ? AND status <> "cancelled"', [$offeringId, $g['lineage_key']]);
            if ($exists) {
                continue;
            }
            $evidence = self::evidence($o, $g);
            $finding = Db::val('SELECT id FROM findings WHERE fingerprint = ?', [Findings::fingerprint('CLO_TARGET_MISSED', 'offering', $offeringId, $g['lineage_key'])]);
            $id = Db::insert('improvement_actions', [
                'course_id' => $o['course_id'],
                'origin_offering_id' => $offeringId,
                'clo_lineage_key' => $g['lineage_key'],
                'finding_id' => $finding ?: null,
                'title' => "Improve {$o['course_code']} {$g['code']} achievement",
                'evidence_summary' => $evidence,
                'owner_id' => $o['instructor_id'],
                'due_on' => Clock::now()->modify('+' . Policy::get('improvement.default_due_days') . ' days')->format('Y-m-d'),
                'status' => 'draft',
                'created_by' => null,
                'created_at' => Clock::stamp(),
                'baseline_pct' => $g['value_pct'],
                'target_pct' => $g['target_pct'],
            ]);
            Audit::asSystem(static fn() => Audit::record('improvement.drafted', 'improvement', $id, "Improvement record drafted for {$o['course_code']} {$g['code']} ({$g['value_pct']}% vs {$g['target_pct']}%) — awaiting academic response"));
            $n++;
        }
        Ledger::add('evidence_linked', $n * 3, $offeringId, (int) $o['course_id'], 'Gap evidence assembled for improvement records');
        return $n;
    }

    private static function evidence(array $o, array $g): string
    {
        $links = Db::col('SELECT CONCAT(a.name, " (", TRIM(TRAILING ".00" FROM a.weight_pct), "%)") FROM assessment_clo ac JOIN assessments a ON a.id = ac.assessment_id WHERE ac.clo_id = ? ORDER BY a.sort_order', [$g['clo_id']]);
        $history = Db::all(
            'SELECT t.name, ca.value_pct, ca.met FROM clo_achievement ca JOIN course_offerings oo ON oo.id = ca.offering_id JOIN terms t ON t.id = oo.term_id
             WHERE ca.lineage_key = ? AND ca.provisional = 0 AND ca.offering_id <> ? AND t.sequence < (SELECT sequence FROM terms WHERE id = ?) ORDER BY t.sequence DESC LIMIT 3',
            [$g['lineage_key'], $o['id'], $o['term_id']]
        );
        $prior = Db::all('SELECT ia.title, ia.action_text, ia.status, ia.effect, t.name FROM improvement_actions ia JOIN course_offerings oo ON oo.id = ia.origin_offering_id JOIN terms t ON t.id = oo.term_id WHERE ia.clo_lineage_key = ? AND ia.origin_offering_id <> ? AND ia.status <> "cancelled" ORDER BY t.sequence DESC LIMIT 2', [$g['lineage_key'], $o['id']]);
        $s = "{$g['code']} reached " . round((float) $g['value_pct']) . "% in {$o['term_name']}; the goal is " . round((float) $g['target_pct']) . '%.';
        $s .= " {$g['code']}: \"" . mb_strimwidth($g['statement'], 0, 140, '…') . "\" ({$g['students_assessed']} students).";
        $s .= ' Measured by: ' . ($links ? implode(', ', $links) : 'no linked assessments') . '.';
        if ($history) {
            $s .= ' Earlier terms: ' . implode('; ', array_map(static fn($h) => $h['name'] . ' ' . round((float) $h['value_pct']) . '%' . ((int) $h['met'] ? ' (met the goal)' : ' (below the goal)'), $history)) . '.';
        } else {
            $s .= ' SAQF has no earlier result for this outcome.';
        }
        if ($prior) {
            $s .= ' Tried before: ' . implode('; ', array_map(static fn($p) => '"' . mb_strimwidth((string) ($p['action_text'] ?: $p['title']), 0, 90, '…') . "\" ({$p['name']}, " . (self::EFFECT[$p['effect']] ?? $p['effect']) . ')', $prior)) . '.';
        }
        return $s;
    }

    /**
     * The facts behind an improvement record, kept apart so each page can lay them out plainly
     * (and the Arabic interface can translate each part): the outcome, its result and goal,
     * the assessments that measure it, earlier terms and anything tried before.
     */
    public static function facts(array $ia): array
    {
        $clo = Db::one('SELECT cl.id, cl.code, cl.statement FROM clos cl JOIN course_offerings o ON o.spec_version_id = cl.spec_version_id WHERE o.id = ? AND cl.lineage_key = ? LIMIT 1', [$ia['origin_offering_id'], $ia['clo_lineage_key']])
            ?: Db::one('SELECT id, code, statement FROM clos WHERE lineage_key = ? ORDER BY id DESC LIMIT 1', [$ia['clo_lineage_key']]);
        $now = Db::one('SELECT value_pct, target_pct, students_assessed FROM clo_achievement WHERE offering_id = ? AND lineage_key = ?', [$ia['origin_offering_id'], $ia['clo_lineage_key']]);
        $assessments = $clo ? Db::all('SELECT a.name, a.weight_pct FROM assessment_clo ac JOIN assessments a ON a.id = ac.assessment_id WHERE ac.clo_id = ? ORDER BY a.sort_order', [$clo['id']]) : [];
        $history = Db::all(
            'SELECT t.name, ca.value_pct, ca.met FROM clo_achievement ca JOIN course_offerings oo ON oo.id = ca.offering_id JOIN terms t ON t.id = oo.term_id
             WHERE ca.lineage_key = ? AND ca.provisional = 0 AND t.sequence < (SELECT t2.sequence FROM course_offerings o2 JOIN terms t2 ON t2.id = o2.term_id WHERE o2.id = ?) ORDER BY t.sequence DESC LIMIT 3',
            [$ia['clo_lineage_key'], $ia['origin_offering_id']]
        );
        $prior = Db::all('SELECT ia.title, ia.action_text, ia.effect, t.name FROM improvement_actions ia JOIN course_offerings oo ON oo.id = ia.origin_offering_id JOIN terms t ON t.id = oo.term_id WHERE ia.clo_lineage_key = ? AND ia.id <> ? AND ia.status <> "cancelled" AND t.sequence < (SELECT t2.sequence FROM course_offerings o2 JOIN terms t2 ON t2.id = o2.term_id WHERE o2.id = ?) ORDER BY t.sequence DESC LIMIT 2', [$ia['clo_lineage_key'], $ia['id'], $ia['origin_offering_id']]);
        return [
            'code' => $clo['code'] ?? ($ia['clo_code'] ?? ''),
            'statement' => $clo['statement'] ?? '',
            'value' => $now['value_pct'] ?? $ia['baseline_pct'],
            'target' => $now['target_pct'] ?? $ia['target_pct'],
            'students' => (int) ($now['students_assessed'] ?? 0),
            'assessments' => $assessments,
            'history' => $history,
            'prior' => $prior,
        ];
    }

    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT ia.*, c.code AS course_code, c.title AS course_title, c.owner_department_id, u.full_name AS owner_name, t.name AS origin_term,
                    ft.name AS followup_term, cl.code AS clo_code
             FROM improvement_actions ia JOIN courses c ON c.id = ia.course_id
             JOIN course_offerings oo ON oo.id = ia.origin_offering_id JOIN terms t ON t.id = oo.term_id
             LEFT JOIN users u ON u.id = ia.owner_id
             LEFT JOIN course_offerings fo ON fo.id = ia.followup_offering_id LEFT JOIN terms ft ON ft.id = fo.term_id
             LEFT JOIN clos cl ON cl.id = (SELECT MAX(id) FROM clos WHERE lineage_key = ia.clo_lineage_key)
             WHERE ia.id = ?',
            [$id]
        );
    }

    public static function commit(int $id, array $user, string $actionText, ?int $ownerId, string $dueOn): void
    {
        $ia = self::find($id);
        if (!$ia || !in_array($ia['status'], ['draft', 'open'], true)) {
            throw new DomainException('This improvement action can no longer be edited.');
        }
        $actionText = trim($actionText);
        if (mb_strlen($actionText) < 15) {
            throw new InvalidArgumentException('Describe the academic response in at least a sentence (what will change, in which activity or assessment).');
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dueOn);
        if (!$date || $date->format('Y-m-d') !== $dueOn) {
            throw new InvalidArgumentException('Choose a valid deadline.');
        }
        $ownerId = $ownerId ?: (int) $user['id'];
        if (!Db::val('SELECT 1 FROM users WHERE id = ? AND status <> "disabled" AND role IN ("faculty","hod")', [$ownerId])) {
            throw new InvalidArgumentException('Choose an owner from the department.');
        }
        $old = ['action_text' => $ia['action_text'], 'owner_id' => $ia['owner_id'], 'due_on' => $ia['due_on'], 'status' => $ia['status']];
        $new = ['action_text' => mb_substr($actionText, 0, 3000), 'owner_id' => $ownerId, 'due_on' => $dueOn, 'status' => 'open', 'committed_at' => $ia['committed_at'] ?? Clock::stamp()];
        Db::update('improvement_actions', $new, 'id = ?', [$id]);
        Audit::record($ia['status'] === 'draft' ? 'improvement.committed' : 'improvement.updated', 'improvement', $id, "{$ia['course_code']}: improvement action " . ($ia['status'] === 'draft' ? 'committed' : 'updated') . " (due $dueOn)", $old, $new);
        Events::emit('improvement.changed', ['offering_id' => (int) $ia['origin_offering_id'], 'action_id' => $id]);
    }

    public static function setStatus(int $id, array $user, string $status, string $note = ''): void
    {
        $ia = self::find($id);
        if (!$ia) {
            throw new InvalidArgumentException('Improvement action not found.');
        }
        $allowed = ['open' => ['in_progress', 'completed', 'cancelled'], 'in_progress' => ['completed', 'cancelled'], 'draft' => ['cancelled']];
        if (!in_array($status, $allowed[$ia['status']] ?? [], true)) {
            throw new DomainException('That status change is not allowed from "' . (self::STATUS[$ia['status']] ?? $ia['status']) . '".');
        }
        if (in_array($status, ['completed', 'cancelled'], true) && mb_strlen(trim($note)) < 5) {
            throw new InvalidArgumentException($status === 'completed' ? 'Briefly record what was done (evidence of completion).' : 'Give the reason for cancelling.');
        }
        $data = ['status' => $status];
        if ($status === 'completed') {
            $data += ['completed_at' => Clock::stamp(), 'completion_note' => mb_substr(trim($note), 0, 2000)];
        }
        Db::update('improvement_actions', $data, 'id = ?', [$id]);
        Audit::record('improvement.status', 'improvement', $id, "{$ia['course_code']}: improvement action → " . (self::STATUS[$status] ?? $status), ['status' => $ia['status']], $data, $note ?: null);
        Events::emit('improvement.changed', ['offering_id' => (int) $ia['origin_offering_id'], 'action_id' => $id]);
    }

    /**
     * When a later offering is measured, compare it with the baseline of earlier actions on the
     * same outcome. Reports association only ("improved following"), never causation.
     */
    public static function evaluateEffectiveness(int $offeringId): int
    {
        $o = Db::one('SELECT o.*, t.sequence FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.id = ?', [$offeringId]);
        if (!$o) {
            return 0;
        }
        $band = Policy::get('effect.similar_band_pct');
        $n = 0;
        $actions = Db::all(
            'SELECT ia.* FROM improvement_actions ia JOIN course_offerings oo ON oo.id = ia.origin_offering_id JOIN terms t ON t.id = oo.term_id
             WHERE ia.course_id = ? AND ia.status IN ("open","in_progress","completed") AND t.sequence < ? AND (ia.followup_offering_id IS NULL OR ia.followup_offering_id = ?)',
            [$o['course_id'], $o['sequence'], $offeringId]
        );
        foreach ($actions as $ia) {
            $now = Db::one('SELECT value_pct, provisional FROM clo_achievement WHERE offering_id = ? AND lineage_key = ?', [$offeringId, $ia['clo_lineage_key']]);
            if (!$now || (int) $now['provisional'] === 1) {
                continue;
            }
            $delta = (float) $now['value_pct'] - (float) $ia['baseline_pct'];
            $effect = $delta > $band ? 'improved' : ($delta < -$band ? 'declined' : 'similar');
            Db::update('improvement_actions', ['followup_offering_id' => $offeringId, 'followup_pct' => $now['value_pct'], 'effect' => $effect, 'evaluated_at' => Clock::stamp()], 'id = ?', [$ia['id']]);
            Audit::asSystem(static fn() => Audit::record('improvement.evaluated', 'improvement', $ia['id'], 'Follow-up measurement: ' . Rules::whole((float) $ia['baseline_pct']) . '% → ' . Rules::whole((float) $now['value_pct']) . '% — ' . self::EFFECT[$effect]));
            $n++;
        }
        return $n;
    }

    public static function forCourse(int $courseId): array
    {
        return Db::all(
            'SELECT ia.*, u.full_name AS owner_name, t.name AS origin_term, ft.name AS followup_term,
                    (SELECT code FROM clos WHERE lineage_key = ia.clo_lineage_key ORDER BY id DESC LIMIT 1) AS clo_code
             FROM improvement_actions ia JOIN course_offerings oo ON oo.id = ia.origin_offering_id JOIN terms t ON t.id = oo.term_id
             LEFT JOIN users u ON u.id = ia.owner_id LEFT JOIN course_offerings fo ON fo.id = ia.followup_offering_id LEFT JOIN terms ft ON ft.id = fo.term_id
             WHERE ia.course_id = ? ORDER BY t.sequence DESC, ia.id DESC',
            [$courseId]
        );
    }
}

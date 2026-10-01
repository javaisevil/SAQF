<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Db;

/**
 * Explainable quality state for a course offering. One primary state, plus every
 * reason behind it — no invented scores.
 */
final class Status
{
    public const STATES = [
        'action_required' => ['Action required', 'red', 'Something needs your input before the record is complete.'],
        'exception' => ['Exception detected', 'amber', 'An exception is with Quality Assurance or the department.'],
        'awaiting_decision' => ['Awaiting academic decision', 'blue', 'A change is waiting for an academic decision.'],
        'improvement' => ['Improvement follow-up', 'violet', 'Improvement actions are being tracked for this course.'],
        'ready' => ['Ready', 'green', 'All deterministic checks pass and nothing needs attention.'],
    ];

    public static function forOffering(array $o): array
    {
        $reasons = [];
        $findings = Findings::forOffering((int) $o['id']);
        $pendingOverrides = [];
        foreach (Db::all('SELECT finding_id FROM overrides WHERE status = "requested"') as $r) {
            $pendingOverrides[(int) $r['finding_id']] = true;
        }
        foreach ($findings as $f) {
            if ($f['status'] !== 'open' || $f['severity'] === 'info') {
                continue;
            }
            if (isset($pendingOverrides[(int) $f['id']])) {
                $reasons[] = ['awaiting_decision', 'Exception requested: ' . $f['title'], 'exceptions.php?finding=' . $f['id']];
            } elseif ($f['owner_role'] === 'faculty') {
                $reasons[] = ['action_required', $f['title'], null];
            } else {
                $reasons[] = ['exception', $f['title'] . ' (with ' . strtoupper($f['owner_role']) . ')', 'exceptions.php?finding=' . $f['id']];
            }
        }
        $inFlight = Specs::inFlight((int) $o['course_id']);
        if ($inFlight) {
            if (in_array($inFlight['status'], ['pending_hod', 'pending_qa'], true)) {
                $reasons[] = ['awaiting_decision', 'Specification v' . $inFlight['version_no'] . ' is ' . ($inFlight['status'] === 'pending_hod' ? 'with the Head of Department' : 'with Quality Assurance'), 'approvals.php?version=' . $inFlight['id']];
            } elseif ($inFlight['decision_route'] && str_starts_with((string) $inFlight['decision_route'], 'returned')) {
                $reasons[] = ['action_required', 'Specification revision returned with comments', null];
            } else {
                $reasons[] = ['action_required', 'Specification revision v' . $inFlight['version_no'] . ' not yet submitted', null];
            }
        }
        $drafts = (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE course_id = ? AND status = "draft"', [$o['course_id']]);
        if ($drafts) {
            $reasons[] = ['action_required', "$drafts improvement record(s) await your academic response", null];
        }
        $openActions = (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE course_id = ? AND status IN ("open","in_progress")', [$o['course_id']]);
        if ($openActions) {
            $reasons[] = ['improvement', "$openActions improvement action(s) in progress — the next results will show whether performance changed", null];
        }
        $state = 'ready';
        foreach (array_keys(self::STATES) as $candidate) {
            foreach ($reasons as $r) {
                if ($r[0] === $candidate) {
                    $state = $candidate;
                    break 2;
                }
            }
        }
        [$label, $tone, $help] = self::STATES[$state];
        return ['state' => $state, 'label' => $label, 'tone' => $tone, 'help' => $help, 'reasons' => $reasons];
    }

    /** Lightweight state for lists (single query per offering kept small). */
    public static function quick(int $offeringId): array
    {
        $o = Db::one('SELECT id, course_id FROM course_offerings WHERE id = ?', [$offeringId]);
        return $o ? self::forOffering($o) : ['state' => 'ready', 'label' => 'Ready', 'tone' => 'green', 'reasons' => []];
    }
}

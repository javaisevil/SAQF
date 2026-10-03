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
        'action_required' => ['Needs you', 'red', 'Something needs your input before the course record is complete.'],
        'exception' => ['With others', 'amber', 'Something is with Quality or the department to sort out.'],
        'awaiting_decision' => ['Waiting for approval', 'blue', 'A change is waiting for someone to approve it.'],
        'improvement' => ['Improving', 'violet', 'An improvement is under way; SAQF will check next term whether it helped.'],
        'ready' => ['All good', 'green', 'Every check passes and nothing needs attention.'],
    ];

    /** Who is handling an issue that is not the instructor's, in plain words. */
    private const WITH = ['hod' => 'with the Head of Department', 'qa' => 'with Quality', 'dean' => 'with the Dean', 'admin' => 'with IT', 'leadership' => 'with university leadership'];

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
                $reasons[] = ['awaiting_decision', $f['title'] . ' — exception asked for', 'exceptions.php?finding=' . $f['id']];
            } elseif ($f['owner_role'] === 'faculty') {
                $reasons[] = ['action_required', $f['title'], null];
            } else {
                $reasons[] = ['exception', $f['title'] . ' — ' . (self::WITH[$f['owner_role']] ?? 'with Quality'), 'exceptions.php?finding=' . $f['id']];
            }
        }
        $inFlight = Specs::inFlight((int) $o['course_id']);
        if ($inFlight) {
            if (in_array($inFlight['status'], ['pending_hod', 'pending_qa'], true)) {
                $reasons[] = ['awaiting_decision', 'Course specification changes are ' . ($inFlight['status'] === 'pending_hod' ? 'with the Head of Department' : 'with Quality'), 'approvals.php?version=' . $inFlight['id']];
            } elseif ($inFlight['decision_route'] && str_starts_with((string) $inFlight['decision_route'], 'returned')) {
                $reasons[] = ['action_required', 'Your specification changes came back with comments', null];
            } else {
                $reasons[] = ['action_required', 'Your specification changes are not submitted yet', null];
            }
        }
        $drafts = (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE course_id = ? AND status = "draft"', [$o['course_id']]);
        if ($drafts) {
            $reasons[] = ['action_required', $drafts === 1 ? 'An improvement plan needs your input' : "$drafts improvement plans need your input", null];
        }
        $openActions = (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE course_id = ? AND status IN ("open","in_progress")', [$o['course_id']]);
        if ($openActions) {
            $reasons[] = ['improvement', ($openActions === 1 ? 'An improvement is under way' : "$openActions improvements are under way") . ' — next term\'s results will show whether it helped', null];
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
        return $o ? self::forOffering($o) : ['state' => 'ready', 'label' => 'All good', 'tone' => 'green', 'reasons' => []];
    }
}

<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Security\Authz;

/**
 * One inbox per role: what needs this person, why, by when, and a direct link.
 * Healthy items never appear here — management by exception.
 */
final class ActionCenter
{
    /** @return list<array{priority:int,kind:string,title:string,reason:string,context:string,due:?string,link:string,cta:string}> */
    public static function forUser(array $user): array
    {
        $items = [];
        switch ($user['role']) {
            case 'faculty':
                $items = self::faculty($user);
                break;
            case 'hod':
                $items = self::hod($user);
                break;
            case 'qa':
                $items = self::qa($user);
                break;
            case 'dean':
                $items = self::dean($user);
                break;
            case 'leadership':
                $items = self::leadership($user);
                break;
        }
        usort($items, static fn($a, $b) => [$a['priority'], $a['due'] ?? '9999'] <=> [$b['priority'], $b['due'] ?? '9999']);
        return $items;
    }

    private static function item(int $priority, string $kind, string $title, string $reason, string $context, ?string $due, string $link, string $cta): array
    {
        return compact('priority', 'kind', 'title', 'reason', 'context', 'due', 'link', 'cta');
    }

    private static function faculty(array $user): array
    {
        $items = [];
        $offerings = Db::all(
            'SELECT o.*, c.code, c.title, t.name AS term_name, t.status AS term_status FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id
             WHERE o.instructor_id = ? AND t.status <> "closed" ORDER BY c.code',
            [$user['id']]
        );
        foreach ($offerings as $o) {
            $ctx = "{$o['code']} · {$o['term_name']}";
            $findings = array_filter(Findings::forOffering((int) $o['id']), static fn($f) => $f['status'] === 'open' && $f['owner_role'] === 'faculty' && $f['severity'] !== 'info');
            $blockers = array_filter($findings, static fn($f) => $f['severity'] === 'blocker');
            if ($findings) {
                $titles = array_slice(array_column($findings, 'title'), 0, 3);
                $items[] = self::item($blockers ? 1 : 2, 'checks', count($findings) . ' quality check(s) need your input', implode(' · ', $titles) . (count($findings) > 3 ? ' …' : ''), $ctx, null, 'workspace.php?id=' . $o['id'], 'Open workspace');
            }
            $returned = Db::one('SELECT * FROM spec_versions WHERE course_id = ? AND status = "draft" AND decision_route LIKE "returned%" ORDER BY id DESC LIMIT 1', [$o['course_id']]);
            if ($returned) {
                $items[] = self::item(1, 'returned', 'Specification revision returned', mb_strimwidth((string) $returned['decision_note'], 0, 160, '…'), $ctx, null, 'workspace.php?id=' . $o['id'] . '&tab=structure', 'Review comments');
            }
            $recs = (int) Db::val('SELECT COUNT(*) FROM recommendations r JOIN spec_versions sv ON sv.id = r.scope_id AND r.scope_type = "spec" WHERE sv.course_id = ? AND sv.status = "draft" AND r.status = "open"', [$o['course_id']]);
            if ($recs) {
                $items[] = self::item(3, 'suggestion', "$recs assisted suggestion(s) to confirm or dismiss", 'Suggested PLO mappings / overlaps with their reasoning. Nothing is applied without you.', $ctx, null, 'workspace.php?id=' . $o['id'] . '&tab=structure', 'Review');
            }
        }
        foreach (Db::all('SELECT ia.*, c.code, t.name AS term_name FROM improvement_actions ia JOIN courses c ON c.id = ia.course_id JOIN course_offerings oo ON oo.id = ia.origin_offering_id JOIN terms t ON t.id = oo.term_id WHERE (ia.owner_id = ? OR oo.instructor_id = ?) AND ia.status = "draft"', [$user['id'], $user['id']]) as $ia) {
            $items[] = self::item(1, 'improvement', 'Write the academic response for a missed target', mb_strimwidth($ia['evidence_summary'], 0, 170, '…'), "{$ia['code']} · {$ia['term_name']}", null, 'workspace.php?id=' . $ia['origin_offering_id'] . '&tab=improve', 'Respond');
        }
        foreach (Db::all('SELECT ia.*, c.code FROM improvement_actions ia JOIN courses c ON c.id = ia.course_id WHERE ia.owner_id = ? AND ia.status IN ("open","in_progress") AND ia.due_on <= ?', [$user['id'], Clock::now()->modify('+30 days')->format('Y-m-d')]) as $ia) {
            $overdue = $ia['due_on'] < Clock::today();
            $items[] = self::item($overdue ? 1 : 2, 'deadline', ($overdue ? 'Overdue: ' : 'Due soon: ') . $ia['title'], mb_strimwidth((string) $ia['action_text'], 0, 160, '…'), $ia['code'], $ia['due_on'], 'improvements.php?id=' . $ia['id'], 'Update status');
        }
        return $items;
    }

    private static function hod(array $user): array
    {
        $items = [];
        foreach (Db::all('SELECT sv.*, c.code, c.title, u.full_name FROM spec_versions sv JOIN courses c ON c.id = sv.course_id LEFT JOIN users u ON u.id = sv.submitted_by WHERE sv.status = "pending_hod" AND c.owner_department_id = ?', [$user['department_id']]) as $v) {
            $n = count(json_decode((string) $v['change_summary'], true) ?: []);
            $items[] = self::item(1, 'approval', "Approve or return {$v['code']} v{$v['version_no']}", "$n change(s) submitted by {$v['full_name']} — validated automatically; your academic judgement is needed.", $v['code'] . ' ' . $v['title'], null, 'approvals.php?version=' . $v['id'], 'Review changes');
        }
        foreach (Db::all('SELECT f.* FROM findings f WHERE f.status = "open" AND f.owner_role = "hod" AND f.department_id = ? ORDER BY FIELD(f.severity,"blocker","warning","info"), f.last_detected_at DESC', [$user['department_id']]) as $f) {
            $items[] = self::item($f['severity'] === 'warning' ? 2 : 3, 'exception', $f['title'], mb_strimwidth($f['detail'], 0, 170, '…'), Rules::meta($f['rule_code'])['category'] === 'quality_risk' ? 'Quality risk' : ucfirst(Rules::meta($f['rule_code'])['category']), null, 'exceptions.php?finding=' . $f['id'], 'Open');
        }
        return $items;
    }

    private static function qa(array $user): array
    {
        $items = [];
        foreach (Db::all('SELECT sv.*, c.code, c.title FROM spec_versions sv JOIN courses c ON c.id = sv.course_id WHERE sv.status = "pending_qa"') as $v) {
            $items[] = self::item(1, 'approval', "Decide on {$v['code']} v{$v['version_no']} (amber)", 'Approved by the HoD; routed to you because warnings remain open.', $v['code'] . ' ' . $v['title'], null, 'approvals.php?version=' . $v['id'], 'Review');
        }
        foreach (Db::all('SELECT o.*, f.title, f.rule_code, u.full_name FROM overrides o JOIN findings f ON f.id = o.finding_id JOIN users u ON u.id = o.requested_by WHERE o.status = "requested"') as $o) {
            $items[] = self::item(1, 'override', 'Policy exception requested: ' . $o['title'], '"' . mb_strimwidth($o['justification'], 0, 150, '…') . '" — ' . $o['full_name'], 'Policy exception', null, 'exceptions.php?finding=' . $o['finding_id'], 'Decide');
        }
        $data = (int) Db::val('SELECT COUNT(*) FROM findings WHERE status = "open" AND owner_role = "qa"');
        if ($data) {
            $items[] = self::item(2, 'exception', "$data data exception(s) from institutional sources", 'Sources disagree or are incomplete; SAQF applied the authoritative value where it safely could.', 'Exception center', null, 'exceptions.php?owner=qa', 'Review');
        }
        $sample = (int) Db::val('SELECT COUNT(*) FROM spec_versions WHERE qa_sampled = 1 AND status = "approved" AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.object_type = "spec_version" AND a.object_id = spec_versions.id AND a.action = "qa.sample_reviewed")');
        if ($sample) {
            $items[] = self::item(3, 'sample', "$sample auto-cleared specification(s) in the QA sample", 'Green records were approved without a QA step; spot-check a sample for governance assurance.', 'Sampling', null, 'approvals.php?view=sample', 'Spot-check');
        }
        return $items;
    }

    private static function dean(array $user): array
    {
        $items = [];
        foreach (Db::all('SELECT f.* FROM findings f WHERE f.status = "open" AND f.owner_role = "dean" AND f.college_id = ?', [$user['scope_college_id']]) as $f) {
            $items[] = self::item(1, 'exception', $f['title'], mb_strimwidth($f['detail'], 0, 170, '…'), 'Persistent program-level issue', null, 'exceptions.php?finding=' . $f['id'], 'Open');
        }
        $stale = (int) Db::val('SELECT COUNT(*) FROM findings f WHERE f.status = "open" AND f.owner_role = "hod" AND f.college_id = ? AND f.first_detected_at < ?', [$user['scope_college_id'], Clock::now()->modify('-30 days')->format('Y-m-d H:i:s')]);
        if ($stale) {
            $items[] = self::item(2, 'escalation', "$stale department-level exception(s) open for more than 30 days", 'These are owned by Heads of Department but have not been resolved.', 'College', null, 'exceptions.php?owner=hod&age=30', 'Review');
        }
        return $items;
    }

    private static function leadership(array $user): array
    {
        $items = [];
        $n = (int) Db::val('SELECT COUNT(*) FROM findings WHERE status = "open" AND rule_code = "PLO_PERSISTENT_BELOW"');
        if ($n) {
            $items[] = self::item(1, 'exception', "$n program outcome(s) persistently below target", 'Below target across consecutive measurement cycles; owned by college deans.', 'Institution', null, 'institution.php#risks', 'View');
        }
        return $items;
    }
}

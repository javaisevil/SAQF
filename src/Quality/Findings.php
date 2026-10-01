<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Ledger;
use Saqf\Core\Notify;

/**
 * Persists rule-engine results. A finding is opened when a rule fails, kept while
 * it keeps failing, and closed automatically ("auto_resolved") the moment the rule
 * passes — nobody has to clear a deterministic issue by hand.
 */
final class Findings
{
    public static function fingerprint(string $rule, string $scopeType, int $scopeId, string $key = ''): string
    {
        return mb_substr($rule . '|' . $scopeType . '|' . $scopeId . ($key !== '' ? '|' . $key : ''), 0, 191);
    }

    /**
     * @param list<string> $evaluatedRules rule codes that were evaluated for this scope
     * @param list<array>  $violations
     * @return array{opened:int,resolved:int,open:int}
     */
    public static function reconcile(string $scopeType, int $scopeId, array $evaluatedRules, array $violations): array
    {
        $now = Clock::stamp();
        $seen = [];
        $opened = 0;
        foreach ($violations as $v) {
            $meta = Rules::meta($v['rule']);
            $fp = self::fingerprint($v['rule'], $scopeType, $scopeId, (string) ($v['key'] ?? ''));
            $seen[$fp] = true;
            $existing = Db::one('SELECT * FROM findings WHERE fingerprint = ?', [$fp]);
            $severity = $v['severity'] ?? $meta['severity'];
            $data = [
                'title' => mb_substr($v['title'], 0, 200),
                'detail' => $v['detail'],
                'why' => $v['why'] ?? $meta['why'],
                'remedy' => $v['remedy'] ?? null,
                'context' => $v['context'] ?? null,
                'severity' => $severity,
                'last_detected_at' => $now,
            ];
            if ($existing === null) {
                $id = Db::insert('findings', $data + [
                    'fingerprint' => $fp,
                    'rule_code' => $v['rule'],
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'course_id' => $v['course_id'] ?? null,
                    'offering_id' => $v['offering_id'] ?? null,
                    'program_id' => $v['program_id'] ?? null,
                    'department_id' => $v['department_id'] ?? null,
                    'college_id' => $v['college_id'] ?? null,
                    'category' => $meta['category'],
                    'owner_role' => $v['owner'] ?? $meta['owner'],
                    'status' => 'open',
                    'first_detected_at' => $now,
                ]);
                $opened++;
                Audit::asSystem(fn() => Audit::record('finding.detected', 'finding', $id, '[' . $v['rule'] . '] ' . $v['title']));
                self::notifyOwner($id);
            } elseif ($existing['status'] === 'overridden') {
                Db::update('findings', ['last_detected_at' => $now], 'id = ?', [$existing['id']]);
            } elseif ($existing['status'] === 'open') {
                Db::update('findings', $data, 'id = ?', [$existing['id']]);
            } else {
                Db::update('findings', $data + ['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'resolution_note' => null, 'occurrences' => (int) $existing['occurrences'] + 1], 'id = ?', [$existing['id']]);
                $opened++;
                Audit::asSystem(fn() => Audit::record('finding.reopened', 'finding', $existing['id'], '[' . $v['rule'] . '] reopened: ' . $v['title']));
                self::notifyOwner((int) $existing['id']);
            }
        }

        $resolved = 0;
        if ($evaluatedRules) {
            $open = Db::all(
                'SELECT id, fingerprint, rule_code, title FROM findings WHERE scope_type = ? AND scope_id = ? AND status IN ("open","overridden") AND rule_code IN (' . Db::in($evaluatedRules) . ')',
                array_merge([$scopeType, $scopeId], $evaluatedRules)
            );
            foreach ($open as $f) {
                if (isset($seen[$f['fingerprint']])) {
                    continue;
                }
                Db::update('findings', ['status' => 'auto_resolved', 'resolved_at' => $now, 'resolved_by' => null, 'resolution_note' => 'Rule passes on re-evaluation'], 'id = ?', [$f['id']]);
                Audit::asSystem(fn() => Audit::record('finding.auto_resolved', 'finding', $f['id'], '[' . $f['rule_code'] . '] cleared automatically: ' . $f['title']));
                $resolved++;
            }
            Ledger::add('auto_resolved', $resolved, $scopeType === 'offering' ? $scopeId : null, null, "$scopeType #$scopeId");
        }
        $openCount = (int) Db::val('SELECT COUNT(*) FROM findings WHERE scope_type = ? AND scope_id = ? AND status = "open"', [$scopeType, $scopeId]);
        return ['opened' => $opened, 'resolved' => $resolved, 'open' => $openCount];
    }

    /** Close all open findings of a scope (e.g. a superseded specification version). */
    public static function closeScope(string $scopeType, int $scopeId, string $note): void
    {
        Db::exec('UPDATE findings SET status = "auto_resolved", resolved_at = ?, resolution_note = ? WHERE scope_type = ? AND scope_id = ? AND status IN ("open","overridden")', [Clock::stamp(), $note, $scopeType, $scopeId]);
    }

    /** Notify the person who must act — only for human-owned issues of real severity. */
    private static function notifyOwner(int $findingId): void
    {
        $f = Db::one('SELECT * FROM findings WHERE id = ?', [$findingId]);
        if (!$f || $f['severity'] === 'info' || $f['owner_role'] === 'system') {
            return;
        }
        $link = 'exceptions.php?finding=' . $f['id'];
        switch ($f['owner_role']) {
            case 'faculty':
                // A missing first specification is announced once, with guidance, when the workspace is created.
                if ($f['offering_id'] && $f['rule_code'] !== 'OFFERING_NO_SPEC') {
                    $instructor = Db::val('SELECT instructor_id FROM course_offerings WHERE id = ? AND status <> "closed"', [$f['offering_id']]);
                    if ($instructor) {
                        Notify::user((int) $instructor, 'action', $f['title'], 'SAQF found something that needs your academic input.', 'workspace.php?id=' . $f['offering_id'], 'finding:' . $f['id'] . ':' . $f['first_detected_at']);
                    }
                }
                break;
            case 'hod':
                Notify::role('hod', $f['department_id'] ? (int) $f['department_id'] : null, null, 'exception', $f['title'], 'Needs a department-level decision.', $link, 'finding:' . $f['id']);
                break;
            case 'dean':
                Notify::role('dean', null, $f['college_id'] ? (int) $f['college_id'] : null, 'exception', $f['title'], 'Persistent program-level issue.', $link, 'finding:' . $f['id']);
                break;
            case 'qa':
                Notify::role('qa', null, null, 'exception', $f['title'], 'Routed to the Quality exception center.', $link, 'finding:' . $f['id']);
                break;
        }
    }

    public static function forOffering(int $offeringId, bool $openOnly = true): array
    {
        $specId = (int) Db::val('SELECT COALESCE((SELECT id FROM spec_versions WHERE course_id = o.course_id AND status IN ("draft","pending_hod","pending_qa") ORDER BY version_no DESC LIMIT 1), o.spec_version_id) FROM course_offerings o WHERE o.id = ?', [$offeringId]);
        $status = $openOnly ? ' AND f.status IN ("open","overridden")' : '';
        return Db::all(
            "SELECT f.* FROM findings f WHERE ((f.scope_type = 'offering' AND f.scope_id = ?) OR (f.scope_type = 'spec' AND f.scope_id = ?))$status
             ORDER BY FIELD(f.status,'open','overridden','auto_resolved','resolved'), FIELD(f.severity,'blocker','warning','info'), f.id",
            [$offeringId, $specId]
        );
    }
}

<?php
declare(strict_types=1);

namespace Saqf\Quality;

use DomainException;
use InvalidArgumentException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Notify;

/**
 * Safe overrides: a human may set aside an automated rule only with a recorded
 * justification, by an authorised role, and every step is audited. Rules are never
 * bypassed silently.
 */
final class Overrides
{
    public static function request(int $findingId, array $user, string $justification): int
    {
        $f = Db::one('SELECT * FROM findings WHERE id = ?', [$findingId]);
        if (!$f || $f['status'] !== 'open') {
            throw new DomainException('Only open findings can have an exception requested.');
        }
        if (!in_array($f['category'], ['policy', 'quality_risk', 'evidence'], true)) {
            throw new DomainException('Structural validation rules cannot be waived; fix the record instead.');
        }
        if (mb_strlen(trim($justification)) < 20) {
            throw new InvalidArgumentException('Give a justification Quality Assurance can evaluate (at least a sentence).');
        }
        if (Db::val('SELECT 1 FROM overrides WHERE finding_id = ? AND status = "requested"', [$findingId])) {
            throw new DomainException('An exception request for this finding is already waiting for QA.');
        }
        $id = Db::insert('overrides', ['finding_id' => $findingId, 'rule_code' => $f['rule_code'], 'scope_type' => $f['scope_type'], 'scope_id' => $f['scope_id'], 'requested_by' => $user['id'], 'requested_at' => Clock::stamp(), 'justification' => trim($justification), 'status' => 'requested']);
        Audit::record('override.requested', 'finding', $findingId, "Exception requested for [{$f['rule_code']}] {$f['title']}", null, null, trim($justification));
        Notify::role('qa', null, null, 'decision', 'Policy exception requested: ' . $f['title'], mb_strimwidth(trim($justification), 0, 200, '…'), 'exceptions.php?finding=' . $findingId, 'override:' . $id);
        return $id;
    }

    public static function decide(int $overrideId, array $user, bool $approve, string $note): void
    {
        $o = Db::one('SELECT * FROM overrides WHERE id = ?', [$overrideId]);
        if (!$o || $o['status'] !== 'requested') {
            throw new DomainException('This request was already decided.');
        }
        if (mb_strlen(trim($note)) < 5) {
            throw new InvalidArgumentException('Record the reason for your decision.');
        }
        Db::update('overrides', ['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $user['id'], 'decided_at' => Clock::stamp(), 'decision_note' => trim($note)], 'id = ?', [$overrideId]);
        if ($approve) {
            Db::update('findings', ['status' => 'overridden', 'resolved_at' => Clock::stamp(), 'resolved_by' => $user['id'], 'resolution_note' => 'Override approved by QA: ' . mb_strimwidth(trim($note), 0, 300, '…')], 'id = ?', [$o['finding_id']]);
        }
        $f = Db::one('SELECT * FROM findings WHERE id = ?', [$o['finding_id']]);
        Audit::record($approve ? 'override.approved' : 'override.rejected', 'finding', $o['finding_id'], ($approve ? 'Override approved' : 'Override rejected') . " for [{$f['rule_code']}] {$f['title']}", null, ['override_id' => $overrideId], trim($note));
        Notify::user((int) $o['requested_by'], 'decision', ($approve ? 'Exception approved: ' : 'Exception not approved: ') . $f['title'], mb_strimwidth(trim($note), 0, 300, '…'), $f['offering_id'] ? 'workspace.php?id=' . $f['offering_id'] : 'index.php', 'override-decided:' . $overrideId);
    }

    /** QA sets aside a finding directly (e.g. an accepted data discrepancy). */
    public static function direct(int $findingId, array $user, string $reason): void
    {
        $f = Db::one('SELECT * FROM findings WHERE id = ?', [$findingId]);
        if (!$f || $f['status'] !== 'open') {
            throw new DomainException('Only open findings can be overridden.');
        }
        if ($f['category'] === 'validation' && $f['severity'] === 'blocker') {
            throw new DomainException('Structural blockers cannot be overridden; the record must be corrected.');
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new InvalidArgumentException('Record why this rule is being set aside.');
        }
        $id = Db::insert('overrides', ['finding_id' => $findingId, 'rule_code' => $f['rule_code'], 'scope_type' => $f['scope_type'], 'scope_id' => $f['scope_id'], 'requested_by' => $user['id'], 'requested_at' => Clock::stamp(), 'justification' => trim($reason), 'status' => 'approved', 'decided_by' => $user['id'], 'decided_at' => Clock::stamp(), 'decision_note' => trim($reason)]);
        Db::update('findings', ['status' => 'overridden', 'resolved_at' => Clock::stamp(), 'resolved_by' => $user['id'], 'resolution_note' => 'Overridden by QA: ' . mb_strimwidth(trim($reason), 0, 300, '…')], 'id = ?', [$findingId]);
        Audit::record('override.direct', 'finding', $findingId, "QA override of [{$f['rule_code']}] {$f['title']}", null, ['override_id' => $id], trim($reason));
    }

    /** Mark a human-owned exception as resolved with a note (e.g. data confirmed by Registrar). */
    public static function resolve(int $findingId, array $user, string $note): void
    {
        $f = Db::one('SELECT * FROM findings WHERE id = ?', [$findingId]);
        if (!$f || $f['status'] !== 'open') {
            throw new DomainException('Only open findings can be resolved.');
        }
        if (in_array($f['category'], ['validation'], true)) {
            throw new DomainException('Validation findings close automatically once the record is fixed.');
        }
        if (mb_strlen(trim($note)) < 5) {
            throw new InvalidArgumentException('Record how it was resolved.');
        }
        Db::update('findings', ['status' => 'resolved', 'resolved_at' => Clock::stamp(), 'resolved_by' => $user['id'], 'resolution_note' => mb_substr(trim($note), 0, 400)], 'id = ?', [$findingId]);
        Audit::record('finding.resolved', 'finding', $findingId, "Resolved: {$f['title']}", null, null, trim($note));
    }
}

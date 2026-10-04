<?php
declare(strict_types=1);

namespace Saqf\Security;

use DomainException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Policy;

/**
 * Periodic access review (the "who still needs access, and is it the right access?" control
 * auditors ask for). An administrator other than the person concerned confirms or removes each
 * account's access at least every `security.access_review_days` days. Nobody reviews their own access.
 */
final class AccessReview
{
    /** Active accounts with their last review. @return list<array<string,mixed>> */
    public static function rows(): array
    {
        $days = max(1, (int) Policy::get('security.access_review_days'));
        $cutoff = Clock::now()->modify("-$days days")->format('Y-m-d H:i:s');
        $rows = Db::all(
            'SELECT u.id, u.username, u.full_name, u.role, u.status, u.auth_source, u.last_login_at, u.mfa_enabled_at, d.code AS department_code,
                    (SELECT MAX(r.reviewed_at) FROM access_reviews r WHERE r.user_id = u.id AND r.decision = "confirmed") AS reviewed_at,
                    (SELECT rr.role_at_review FROM access_reviews rr WHERE rr.user_id = u.id AND rr.decision = "confirmed" ORDER BY rr.reviewed_at DESC, rr.id DESC LIMIT 1) AS reviewed_role,
                    (SELECT ru.full_name FROM access_reviews r2 JOIN users ru ON ru.id = r2.reviewer_id WHERE r2.user_id = u.id AND r2.decision = "confirmed" ORDER BY r2.reviewed_at DESC, r2.id DESC LIMIT 1) AS reviewer
             FROM users u LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.status <> "disabled" ORDER BY u.role = "admin" DESC, u.role, u.full_name'
        );
        $admins = count(array_filter($rows, static fn($r) => $r['role'] === 'admin'));
        foreach ($rows as &$r) {
            // The only administrator cannot be reviewed by anyone else; the Security center advises adding a second one.
            $r['solo'] = $r['role'] === 'admin' && $admins === 1;
            $r['role_changed'] = $r['reviewed_role'] !== null && $r['reviewed_role'] !== $r['role'];
            $r['due'] = !$r['solo'] && ($r['reviewed_at'] === null || $r['reviewed_at'] < $cutoff || $r['role_changed']);
        }
        return $rows;
    }

    /** @return array{total:int,current:int,due:int,days:int} */
    public static function progress(): array
    {
        $rows = self::rows();
        $due = count(array_filter($rows, static fn($r) => $r['due']));
        return ['total' => count($rows), 'current' => count($rows) - $due, 'due' => $due, 'days' => (int) Policy::get('security.access_review_days')];
    }

    /** @param list<int> $userIds @return int accounts confirmed */
    public static function confirm(array $userIds, array $reviewer, ?string $note = null): int
    {
        $n = 0;
        foreach (array_unique(array_map('intval', $userIds)) as $id) {
            if ($id === (int) $reviewer['id']) {
                throw new DomainException('Another administrator has to review your own access.');
            }
            $u = Db::one('SELECT id, username, role FROM users WHERE id = ? AND status <> "disabled"', [$id]);
            if (!$u) {
                continue;
            }
            Db::insert('access_reviews', ['user_id' => $id, 'reviewer_id' => $reviewer['id'], 'decision' => 'confirmed', 'role_at_review' => $u['role'], 'note' => $note ? mb_substr($note, 0, 255) : null, 'reviewed_at' => Clock::stamp()]);
            Audit::record('security.access_confirmed', 'user', $id, "Access of {$u['username']} ({$u['role']}) confirmed in the access review", null, ['role' => $u['role']], $note);
            $n++;
        }
        return $n;
    }

    /** Removes the person's access: account disabled, every session ended, decision recorded. */
    public static function remove(int $userId, array $reviewer, string $reason): void
    {
        if ($userId === (int) $reviewer['id']) {
            throw new DomainException('You cannot remove your own access.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException('Give a reason (recorded in the audit log).');
        }
        $u = Db::one('SELECT id, username, role, status FROM users WHERE id = ? AND status <> "disabled"', [$userId]);
        if (!$u) {
            throw new DomainException('Unknown or already disabled account.');
        }
        Db::update('users', ['status' => 'disabled'], 'id = ?', [$userId]);
        Sessions::endAll($userId, 'access removed in the access review');
        Db::insert('access_reviews', ['user_id' => $userId, 'reviewer_id' => $reviewer['id'], 'decision' => 'removed', 'role_at_review' => $u['role'], 'note' => mb_substr($reason, 0, 255), 'reviewed_at' => Clock::stamp()]);
        Audit::record('security.access_removed', 'user', $userId, "Access of {$u['username']} removed in the access review", ['status' => $u['status']], ['status' => 'disabled'], $reason);
    }
}

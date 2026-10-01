<?php
declare(strict_types=1);

namespace Saqf\Core;

/**
 * Action-only notifications. A notification is created only when a person must act;
 * successful background automation never notifies anyone. The dedupe key prevents
 * repeated notifications for the same situation.
 */
final class Notify
{
    public static function user(int $userId, string $kind, string $title, ?string $body, ?string $link, string $dedupeKey): void
    {
        $exists = Db::val('SELECT id FROM notifications WHERE user_id = ? AND dedupe_key = ?', [$userId, $dedupeKey]);
        if ($exists) {
            return;
        }
        Db::insert('notifications', [
            'user_id' => $userId,
            'kind' => $kind,
            'title' => mb_substr($title, 0, 200),
            'body' => $body === null ? null : mb_substr($body, 0, 400),
            'link' => $link,
            'dedupe_key' => mb_substr($dedupeKey, 0, 120),
            'created_at' => Clock::stamp(),
        ]);
    }

    /** Notify every active user holding a role within an optional department/college scope. */
    public static function role(string $role, ?int $departmentId, ?int $collegeId, string $kind, string $title, ?string $body, ?string $link, string $dedupeKey): int
    {
        $sql = 'SELECT id FROM users WHERE role = ? AND status = "active"';
        $params = [$role];
        if ($departmentId !== null && $role === 'hod') {
            $sql .= ' AND department_id = ?';
            $params[] = $departmentId;
        }
        if ($collegeId !== null && $role === 'dean') {
            $sql .= ' AND college_id = ?';
            $params[] = $collegeId;
        }
        $n = 0;
        foreach (Db::col($sql, $params) as $uid) {
            self::user((int) $uid, $kind, $title, $body, $link, $dedupeKey);
            $n++;
        }
        return $n;
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }
}

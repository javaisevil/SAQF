<?php
declare(strict_types=1);

namespace Saqf\Core;

/**
 * Tamper-evident audit trail. Every row carries SHA-256(prev_hash + canonical row),
 * so editing or deleting any historical row breaks the chain and verify() reports it.
 * The table is also append-only at database level (triggers installed by bin/install.php).
 */
final class Audit
{
    /** @var array{type:string,id:?int,name:string,role:?string}|null */
    private static ?array $actor = null;

    public static function actAs(string $type, ?int $id, string $name, ?string $role = null): void
    {
        self::$actor = ['type' => $type, 'id' => $id, 'name' => $name, 'role' => $role];
    }

    public static function actor(): array
    {
        if (self::$actor !== null) {
            return self::$actor;
        }
        if (!empty($_SESSION['uid'])) {
            return ['type' => 'user', 'id' => (int) $_SESSION['uid'], 'name' => (string) ($_SESSION['name'] ?? 'user'), 'role' => $_SESSION['role'] ?? null];
        }
        return ['type' => 'system', 'id' => null, 'name' => 'SAQF automation', 'role' => null];
    }

    /** Run a block attributed to SAQF automation (or an integration), then restore the actor. */
    public static function asSystem(callable $fn, string $type = 'system', string $name = 'SAQF automation')
    {
        $previous = self::$actor;
        self::$actor = ['type' => $type, 'id' => null, 'name' => $name, 'role' => null];
        try {
            return $fn();
        } finally {
            self::$actor = $previous;
        }
    }

    public static function record(
        string $action,
        string $objectType,
        $objectId,
        string $summary,
        $old = null,
        $new = null,
        ?string $reason = null
    ): void {
        $actor = self::actor();
        $row = [
            'occurred_at' => Clock::stamp(),
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'],
            'actor_name' => mb_substr($actor['name'], 0, 160),
            'actor_role' => $actor['role'],
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId === null ? null : (string) $objectId,
            'summary' => mb_substr($summary, 0, 400),
            'old_value' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'new_value' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'reason' => $reason,
            'ip' => PHP_SAPI === 'cli' ? 'cli' : Request::ip(),
            'request_id' => Request::id(),
        ];
        // Serialize writers so the hash chain stays linear under concurrency.
        Db::tx(static function () use ($row) {
            $prev = Db::val('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1 FOR UPDATE') ?: str_repeat('0', 64);
            $row['prev_hash'] = $prev;
            $row['hash'] = hash('sha256', $prev . '|' . self::canonical($row));
            Db::insert('audit_log', $row);
        });
    }

    public static function canonical(array $row): string
    {
        $fields = ['occurred_at', 'actor_type', 'actor_id', 'actor_name', 'actor_role', 'action', 'object_type', 'object_id', 'summary', 'old_value', 'new_value', 'reason', 'ip', 'request_id'];
        $parts = [];
        foreach ($fields as $f) {
            $v = $row[$f] ?? null;
            $parts[] = $f . '=' . ($v === null ? '∅' : (string) $v);
        }
        return implode("\x1f", $parts);
    }

    /** @return array{ok:bool,checked:int,broken_at:?int,message:string} */
    public static function verify(): array
    {
        $prev = str_repeat('0', 64);
        $checked = 0;
        $lastId = 0;
        do {
            $rows = Db::all('SELECT * FROM audit_log WHERE id > ? ORDER BY id LIMIT 2000', [$lastId]);
            foreach ($rows as $r) {
                $checked++;
                $lastId = (int) $r['id'];
                if ($r['prev_hash'] !== $prev) {
                    return ['ok' => false, 'checked' => $checked, 'broken_at' => $lastId, 'message' => "Chain link broken at entry #$lastId (previous hash mismatch — an earlier entry was removed or altered)."];
                }
                $expected = hash('sha256', $prev . '|' . self::canonical($r));
                if (!hash_equals($expected, $r['hash'])) {
                    return ['ok' => false, 'checked' => $checked, 'broken_at' => $lastId, 'message' => "Entry #$lastId content does not match its hash (the row was modified)."];
                }
                $prev = $r['hash'];
            }
        } while (count($rows) === 2000);
        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'message' => "All $checked audit entries verified; the hash chain is intact."];
    }
}

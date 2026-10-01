<?php
declare(strict_types=1);

namespace Saqf\Core;

/**
 * Minimal synchronous event bus. Domain services emit events ("results.imported",
 * "spec.changed", "term.activated" ...) and registered reactions run immediately.
 * Every event and its outcome is stored, so automation is traceable after the fact.
 */
final class Events
{
    /** @var array<string, list<callable>> */
    private static array $handlers = [];
    private static int $depth = 0;

    public static function on(string $type, callable $handler): void
    {
        self::$handlers[$type][] = $handler;
    }

    public static function emit(string $type, array $payload = []): void
    {
        $actor = Audit::actor();
        $id = Db::insert('events', [
            'type' => $type,
            'payload' => $payload,
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'],
            'created_at' => Clock::stamp(),
        ]);
        if (self::$depth > 6) {
            Db::update('events', ['handled_at' => Clock::stamp(), 'outcome' => 'Skipped: event chain too deep'], 'id = ?', [$id]);
            return;
        }
        $outcomes = [];
        self::$depth++;
        try {
            foreach (self::$handlers[$type] ?? [] as $handler) {
                $result = $handler($payload);
                if (is_string($result) && $result !== '') {
                    $outcomes[] = $result;
                }
            }
        } finally {
            self::$depth--;
        }
        Db::update('events', ['handled_at' => Clock::stamp(), 'outcome' => $outcomes ? mb_substr(implode(' · ', $outcomes), 0, 2000) : 'No reaction required'], 'id = ?', [$id]);
    }

    public static function reset(): void
    {
        self::$handlers = [];
    }
}

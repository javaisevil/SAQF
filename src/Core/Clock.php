<?php
declare(strict_types=1);

namespace Saqf\Core;

use DateTimeImmutable;

/**
 * Single source of "now". The demo seeder replays past semesters by moving the
 * clock, so historical records get realistic timestamps produced by the real engine.
 *
 * Demo clock: in demo mode the installer anchors the scenario to its story date
 * (Fall 2026, week 6). Time then runs forward normally from the moment of installation,
 * so the demo behaves the same whenever it is installed. Production (or
 * SAQF_DEMO_CLOCK=real) always uses the real clock.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;
    private static ?int $offset = null;

    public static function now(): DateTimeImmutable
    {
        if (self::$frozen !== null) {
            return self::$frozen;
        }
        $now = new DateTimeImmutable('now');
        $offset = self::offset();
        return $offset === 0 ? $now : $now->modify(($offset > 0 ? '+' : '') . $offset . ' seconds');
    }

    public static function set(?string $datetime): void
    {
        self::$frozen = $datetime === null ? null : new DateTimeImmutable($datetime);
    }

    /** Seconds between the demo's virtual time and real time (0 outside an anchored demo). */
    public static function offset(): int
    {
        if (self::$offset !== null) {
            return self::$offset;
        }
        self::$offset = 0;
        if (!Config::demoMode() || Config::get('SAQF_DEMO_CLOCK') === 'real') {
            return 0;
        }
        try {
            $anchor = json_decode((string) Db::val('SELECT value FROM system_settings WHERE setting_key = "demo.clock_anchor"'), true);
        } catch (\Throwable $e) {
            return 0; // not installed yet
        }
        if (is_array($anchor) && isset($anchor['real'], $anchor['virtual'])) {
            self::$offset = (int) (strtotime((string) $anchor['virtual']) - strtotime((string) $anchor['real']));
        }
        return self::$offset;
    }

    /** Anchors the demo clock: "now" becomes $virtual and runs forward from here. */
    public static function anchorDemo(string $virtual): void
    {
        $real = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        Db::exec(
            'INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("demo.clock_anchor", ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [json_encode(['real' => $real, 'virtual' => $virtual]), $real]
        );
        self::$offset = null;
    }

    public static function stamp(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }
}

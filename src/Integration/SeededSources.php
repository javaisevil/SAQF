<?php
declare(strict_types=1);

namespace Saqf\Integration;

use Saqf\Core\Clock;
use Saqf\Core\Db;

/**
 * Seeded institution source: a structured snapshot of Al Yamamah University's
 * PUBLIC study plans (yu.edu.sa PDFs). It is not a live connection.
 */
final class SeededInstitutionSource implements InstitutionSource
{
    public function __construct(private string $dir = SAQF_ROOT . '/data/yu')
    {
    }

    public function label(): string
    {
        return 'Seeded snapshot of YU public study plans (data/yu) — not a live Registrar connection';
    }

    public function snapshot(): array
    {
        $base = json_decode((string) file_get_contents($this->dir . '/institution.json'), true, 512, JSON_THROW_ON_ERROR);
        $programs = [];
        foreach ($base['programs'] as $code) {
            $programs[] = json_decode((string) file_get_contents($this->dir . '/programs/' . strtolower($code) . '.json'), true, 512, JSON_THROW_ON_ERROR);
        }
        $base['programs'] = $programs;
        return $base;
    }
}

/** Seeded SIS: academic calendar and teaching assignments from data/demo/sis.json. */
final class SeededSisSource implements SisSource
{
    private ?array $data = null;

    public function __construct(private string $file = SAQF_ROOT . '/data/demo/sis.json')
    {
    }

    public function label(): string
    {
        return 'Seeded SIS feed (data/demo/sis.json) — simulates Registrar term calendar and teaching assignments';
    }

    private function data(): array
    {
        if ($this->data === null) {
            $this->data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR) : ['terms' => [], 'assignments' => []];
        }
        return $this->data;
    }

    public function terms(): array
    {
        return $this->data()['terms'];
    }

    public function assignments(string $termCode): array
    {
        $rows = $this->data()['assignments'][$termCode] ?? [];
        // Simulator: assignments released later by the demo console.
        foreach ($this->released('sis.assignment.') as $payload) {
            if (($payload['term'] ?? '') === $termCode) {
                $rows[] = $payload;
            }
        }
        return $rows;
    }

    public function pendingAssignments(): array
    {
        return $this->data()['pending_assignments'] ?? [];
    }

    private function released(string $prefix): array
    {
        $out = [];
        foreach (Db::all('SELECT setting_key, value FROM system_settings WHERE setting_key LIKE ?', [$prefix . '%']) as $r) {
            $out[] = json_decode((string) $r['value'], true) ?: [];
        }
        return $out;
    }
}

/**
 * Seeded LMS: assessment results per offering (pseudonymous student keys only).
 * A batch is "published" once its published_at date has passed, or when the demo
 * simulator releases it early.
 */
final class SeededLmsSource implements LmsSource
{
    public function __construct(private string $dir = SAQF_ROOT . '/data/demo/lms')
    {
    }

    public function label(): string
    {
        return 'Seeded LMS gradebook export (data/demo/lms) — simulates grade publication';
    }

    private function file(string $termCode, string $courseCode): string
    {
        return $this->dir . '/' . $termCode . '/' . str_replace(' ', '', $courseCode) . '.json';
    }

    private function all(string $termCode, string $courseCode): array
    {
        $f = $this->file($termCode, $courseCode);
        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    public function batches(string $termCode, string $courseCode): array
    {
        $now = Clock::stamp();
        $out = [];
        foreach ($this->all($termCode, $courseCode) as $batch) {
            $released = (bool) Db::val('SELECT 1 FROM system_settings WHERE setting_key = ?', ['lms.released.' . $batch['ref']]);
            if ($batch['published_at'] <= $now || $released) {
                $out[] = $batch;
            }
        }
        return $out;
    }

    public function pending(): array
    {
        $now = Clock::stamp();
        $out = [];
        if (!is_dir($this->dir)) {
            return [];
        }
        foreach (glob($this->dir . '/*/*.json') ?: [] as $f) {
            $term = basename(dirname($f));
            foreach (json_decode((string) file_get_contents($f), true) ?: [] as $batch) {
                $released = (bool) Db::val('SELECT 1 FROM system_settings WHERE setting_key = ?', ['lms.released.' . $batch['ref']]);
                if ($batch['published_at'] > $now && !$released) {
                    $out[] = ['term' => $term, 'course' => $batch['course'], 'ref' => $batch['ref'], 'label' => $batch['label'], 'published_at' => $batch['published_at']];
                }
            }
        }
        return $out;
    }
}

/** Registry so the rest of SAQF never instantiates a concrete adapter directly. */
final class Integrations
{
    private static ?InstitutionSource $institution = null;
    private static ?SisSource $sis = null;
    private static ?LmsSource $lms = null;

    public static function institution(): InstitutionSource
    {
        return self::$institution ??= new SeededInstitutionSource();
    }

    public static function sis(): SisSource
    {
        return self::$sis ??= new SeededSisSource();
    }

    public static function lms(): LmsSource
    {
        return self::$lms ??= new SeededLmsSource();
    }

    public static function use(?InstitutionSource $i = null, ?SisSource $s = null, ?LmsSource $l = null): void
    {
        self::$institution = $i ?? self::$institution;
        self::$sis = $s ?? self::$sis;
        self::$lms = $l ?? self::$lms;
    }
}

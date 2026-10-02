<?php
declare(strict_types=1);

namespace Saqf\Integration;

/** SIS not connected (SAQF_SIS_SOURCE=none): terms and assignments are managed in SAQF only. */
final class NullSisSource implements SisSource
{
    public function label(): string
    {
        return 'Not connected (SAQF_SIS_SOURCE=none) — terms are added under Integrations → Academic calendar; Heads of Department assign courses';
    }

    public function terms(): array
    {
        return [];
    }

    public function assignments(string $termCode): array
    {
        return [];
    }

    public function check(): array
    {
        return ['ok' => true, 'message' => 'No SIS connector configured'];
    }
}

/** LMS not connected (SAQF_LMS_SOURCE=none): instructors upload gradebook CSVs in the course workspace. */
final class NullLmsSource implements LmsSource
{
    public function label(): string
    {
        return 'Not connected (SAQF_LMS_SOURCE=none) — results are uploaded in each course workspace';
    }

    public function batches(string $termCode, string $courseCode, ?string $section = null): array
    {
        return [];
    }

    public function pending(): array
    {
        return [];
    }

    public function check(): array
    {
        return ['ok' => true, 'message' => 'No LMS connector configured'];
    }
}

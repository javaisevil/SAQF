<?php
declare(strict_types=1);

namespace Saqf\Integration;

/**
 * Integration seams. Production deployments implement these against the real
 * Registrar/SIS, HR and LMS systems; the prototype ships seeded implementations
 * that read structured snapshots from /data. Nothing else in SAQF knows which
 * implementation is active.
 */
interface InstitutionSource
{
    /** Human-readable name shown in the admin console (must say if it is seeded). */
    public function label(): string;

    /**
     * @return array{institution:array,snapshot:array,colleges:array,departments:array,ownership:array,
     *               descriptions:array,programs:array}
     */
    public function snapshot(): array;
}

interface SisSource
{
    public function label(): string;

    /** @return list<array{code:string,name:string,academic_year:string,sequence:int,starts_on:string,ends_on:string,grades_due_on:string}> */
    public function terms(): array;

    /** Teaching assignments for a term: course code, instructor external id, sections, enrolment. */
    public function assignments(string $termCode): array;
}

interface LmsSource
{
    public function label(): string;

    /**
     * Result batches the LMS has published for a course offering.
     * @return list<array{ref:string,published_at:string,label:string,results:array<string,array<string,float>>}>
     *   results: assessment name => [student_ref => score_pct]
     */
    public function batches(string $termCode, string $courseCode): array;

    /** Batches not yet published (demo simulator only). */
    public function pending(): array;
}

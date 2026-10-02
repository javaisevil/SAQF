<?php
declare(strict_types=1);

namespace Saqf\Integration;

/**
 * Integration seams. Nothing else in SAQF knows which implementation is active; the
 * Integrations registry picks one per system from configuration (see docs/INTEGRATIONS.md):
 *   Registrar/catalogue  CatalogFileSource (data/yu or SAQF_INSTITUTION_DIR)
 *   SIS                  FileSisSource · RestSisSource · SeededSisSource (demo)
 *   LMS                  MoodleLmsSource · BlackboardLmsSource · FileLmsSource · SeededLmsSource (demo)
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

    /**
     * Teaching assignments for a term.
     * @return list<array{course:string,instructor:?string,instructor_name?:string,instructor_email?:string,
     *                    department?:string,sections?:int,enrolled?:int,section?:?string,coordinator?:bool}>
     *   instructor is the SIS/HR identifier (users.external_id); name/email let SAQF provision the account.
     *   Multi-section courses: one row per section (section code, its instructor and enrolment); the row
     *   marked coordinator owns the course record (otherwise the current coordinator, else the first section).
     */
    public function assignments(string $termCode): array;

    /** Connection test for the admin console. @return array{ok:bool,message:string} */
    public function check(): array;
}

interface LmsSource
{
    public function label(): string;

    /**
     * Result batches the LMS has published for a course offering (or one section of it, when the
     * university runs one LMS course per section — SAQF_LMS_COURSE_KEY containing {section}).
     * @return list<array{ref:string,published_at:string,label:string,results:array<string,array<string,float>>,sections?:array<string,string>}>
     *   results: assessment name => [student_ref => score_pct]; student_ref is pseudonymous
     *   sections (optional): student_ref => section code
     */
    public function batches(string $termCode, string $courseCode, ?string $section = null): array;

    /** Batches not yet published (demo simulator only; real connectors return []). */
    public function pending(): array;

    /** Connection test for the admin console. @return array{ok:bool,message:string} */
    public function check(): array;
}

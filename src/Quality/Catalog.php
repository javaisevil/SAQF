<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Db;

/**
 * Study-plan intelligence: everything SAQF can derive from the institutional
 * catalog so nobody has to type it (programs containing a course, required vs
 * elective per program, level, prerequisites, applicable PLOs, contact hours).
 */
final class Catalog
{
    private static array $cache = [];

    public static function course(int $courseId): ?array
    {
        return self::$cache['c' . $courseId] ??= Db::one(
            'SELECT c.*, d.name AS department_name, d.code AS department_code, d.college_id, col.name AS college_name
             FROM courses c JOIN departments d ON d.id = c.owner_department_id JOIN colleges col ON col.id = d.college_id WHERE c.id = ?',
            [$courseId]
        );
    }

    /** Programs whose study plan contains the course, with how the course appears there. */
    public static function programsFor(int $courseId): array
    {
        return self::$cache['p' . $courseId] ??= Db::all(
            'SELECT p.id AS program_id, p.code, p.short_name, p.name, p.level, p.department_id, d.college_id,
                    spe.course_type, spe.requirement_group, spe.plan_year, spe.plan_semester, spe.level_no, spe.credit_threshold,
                    (SELECT COUNT(*) FROM plos WHERE program_id = p.id AND status = "approved") AS plo_count
             FROM study_plan_entries spe JOIN programs p ON p.id = spe.program_id JOIN departments d ON d.id = p.department_id
             WHERE spe.course_id = ? AND p.status = "active"
             ORDER BY FIELD(spe.course_type, "required", "elective"), p.level, p.code',
            [$courseId]
        );
    }

    /** Approved PLOs of every program containing the course, keyed by program id. */
    public static function plosFor(int $courseId): array
    {
        $out = [];
        foreach (self::programsFor($courseId) as $p) {
            $out[(int) $p['program_id']] = Db::all('SELECT * FROM plos WHERE program_id = ? AND status = "approved" ORDER BY code', [$p['program_id']]);
        }
        return $out;
    }

    /** Prerequisites per program (they can legitimately differ between study plans). */
    public static function requisitesFor(int $courseId): array
    {
        return Db::all(
            'SELECT r.program_id, p.code AS program_code, r.kind, rc.code, rc.title
             FROM course_requisites r JOIN programs p ON p.id = r.program_id JOIN courses rc ON rc.id = r.requires_course_id
             WHERE r.course_id = ? ORDER BY p.code, r.kind, rc.code',
            [$courseId]
        );
    }

    /** Courses that depend on this course (used for impact analysis). */
    public static function dependents(int $courseId): array
    {
        return Db::all(
            'SELECT DISTINCT c.code, c.title FROM course_requisites r JOIN courses c ON c.id = r.course_id WHERE r.requires_course_id = ? ORDER BY c.code',
            [$courseId]
        );
    }

    /** Contact hours derived from credits (policy weeks per term) — never typed. */
    public static function contactHours(float $credits): float
    {
        return $credits * \Saqf\Core\Policy::get('contact.weeks_per_term');
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}

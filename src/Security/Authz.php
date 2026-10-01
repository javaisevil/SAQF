<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Audit;
use Saqf\Core\Db;
use Saqf\Core\Request;

/**
 * Server-side authorization. Every page and API call resolves the object first and
 * then checks scope here — hiding buttons is never the only control.
 *
 * Scope model
 *   faculty    — offerings they teach (edit while the term is open)
 *   hod        — courses owned by their department (decide) + courses in their programs (view)
 *   dean       — everything in their college (view)
 *   qa         — institution-wide quality data (view, override, policy)
 *   leadership — institution-wide (view)
 *   admin      — system operations only (users, audit, integrations); no academic decisions
 */
final class Authz
{
    public static function deny(string $what, int $code = 403): void
    {
        $user = $_SESSION['uid'] ?? null;
        Audit::record('security.access_denied', 'access', null, 'Access denied to ' . mb_substr($what, 0, 200) . ' (role ' . ($_SESSION['role'] ?? 'anonymous') . ')', null, ['uri' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 300), 'user_id' => $user]);
        http_response_code($code);
        if (str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 'api.php')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'You do not have access to this item.']);
            exit;
        }
        $home = htmlspecialchars(Request::url('index.php'));
        echo '<!doctype html><meta charset="utf-8"><title>Access denied — SAQF</title><div style="font-family:system-ui;max-width:520px;margin:90px auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px"><h2 style="margin:0 0 8px">Access denied</h2><p>You do not have permission to open this item. The attempt was recorded in the audit log.</p><p><a href="' . $home . '">Return to your dashboard</a></p></div>';
        exit;
    }

    public static function isInstitutionWide(array $user): bool
    {
        return in_array($user['role'], ['qa', 'leadership'], true);
    }

    /** SQL predicate limiting course rows (alias c) to what the user may view. */
    public static function courseScope(array $user, string $c = 'c'): array
    {
        switch ($user['role']) {
            case 'qa':
            case 'leadership':
                return ['1=1', []];
            case 'hod':
                return ["($c.owner_department_id = ? OR $c.id IN (SELECT spe.course_id FROM study_plan_entries spe JOIN programs p ON p.id = spe.program_id WHERE p.department_id = ? AND spe.course_id IS NOT NULL))", [$user['department_id'], $user['department_id']]];
            case 'dean':
                return ["($c.owner_department_id IN (SELECT id FROM departments WHERE college_id = ?) OR $c.id IN (SELECT spe.course_id FROM study_plan_entries spe JOIN programs p ON p.id = spe.program_id JOIN departments d ON d.id = p.department_id WHERE d.college_id = ? AND spe.course_id IS NOT NULL))", [$user['scope_college_id'], $user['scope_college_id']]];
            case 'faculty':
                return ["$c.id IN (SELECT course_id FROM course_offerings WHERE instructor_id = ?)", [$user['id']]];
            default:
                return ['1=0', []];
        }
    }

    /** SQL predicate limiting offering rows (alias o, joined course alias c). */
    public static function offeringScope(array $user, string $o = 'o', string $c = 'c'): array
    {
        if ($user['role'] === 'faculty') {
            return ["$o.instructor_id = ?", [$user['id']]];
        }
        return self::courseScope($user, $c);
    }

    public static function programScope(array $user, string $p = 'p'): array
    {
        switch ($user['role']) {
            case 'qa':
            case 'leadership':
                return ['1=1', []];
            case 'hod':
                return ["$p.department_id = ?", [$user['department_id']]];
            case 'dean':
                return ["$p.department_id IN (SELECT id FROM departments WHERE college_id = ?)", [$user['scope_college_id']]];
            case 'faculty':
                return ["$p.id IN (SELECT spe.program_id FROM study_plan_entries spe JOIN course_offerings o ON o.course_id = spe.course_id WHERE o.instructor_id = ?)", [$user['id']]];
            default:
                return ['1=0', []];
        }
    }

    /** Loads an offering with context, or denies. $mode: view | edit */
    public static function offering(array $user, int $offeringId, string $mode = 'view'): array
    {
        $o = Db::one(
            'SELECT o.*, c.code AS course_code, c.title AS course_title, c.credits, c.owner_department_id, c.description, c.description_source,
                    d.name AS department_name, d.college_id, col.name AS college_name, t.name AS term_name, t.code AS term_code, t.status AS term_status,
                    t.starts_on, t.ends_on, t.grades_due_on, u.full_name AS instructor_name
             FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN departments d ON d.id = c.owner_department_id
             JOIN colleges col ON col.id = d.college_id JOIN terms t ON t.id = o.term_id LEFT JOIN users u ON u.id = o.instructor_id
             WHERE o.id = ?',
            [$offeringId]
        );
        if (!$o) {
            self::deny("offering #$offeringId", 404);
        }
        if (!self::canViewCourse($user, (int) $o['course_id']) && !($user['role'] === 'faculty' && (int) $o['instructor_id'] === $user['id'])) {
            self::deny("offering #$offeringId");
        }
        if ($user['role'] === 'faculty' && (int) $o['instructor_id'] !== $user['id']) {
            self::deny("offering #$offeringId (not your course)");
        }
        if ($mode === 'edit' && !self::canEditOffering($user, $o)) {
            self::deny("editing offering #$offeringId");
        }
        return $o;
    }

    public static function canEditOffering(array $user, array $offering): bool
    {
        return $user['role'] === 'faculty'
            && (int) $offering['instructor_id'] === $user['id']
            && $offering['term_status'] !== 'closed';
    }

    public static function canViewCourse(array $user, int $courseId): bool
    {
        [$sql, $params] = self::courseScope($user, 'c');
        return (bool) Db::val("SELECT 1 FROM courses c WHERE c.id = ? AND $sql", array_merge([$courseId], $params));
    }

    /** HoD of the owning department decides on specification changes for the course. */
    public static function canDecideCourse(array $user, int $courseId): bool
    {
        return $user['role'] === 'hod'
            && (int) Db::val('SELECT owner_department_id FROM courses WHERE id = ?', [$courseId]) === $user['department_id'];
    }

    public static function program(array $user, int $programId): array
    {
        $p = Db::one('SELECT p.*, d.name AS department_name, d.college_id, c.name AS college_name FROM programs p JOIN departments d ON d.id = p.department_id JOIN colleges c ON c.id = d.college_id WHERE p.id = ?', [$programId]);
        if (!$p) {
            self::deny("program #$programId", 404);
        }
        [$sql, $params] = self::programScope($user, 'p');
        if (!Db::val("SELECT 1 FROM programs p WHERE p.id = ? AND $sql", array_merge([$programId], $params))) {
            self::deny("program {$p['code']}");
        }
        return $p;
    }

    public static function canManageProgram(array $user, array $program): bool
    {
        return $user['role'] === 'hod' && (int) $program['department_id'] === $user['department_id'];
    }
}

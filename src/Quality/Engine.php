<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Db;
use Saqf\Core\Events;
use Saqf\Core\Ledger;

/**
 * Orchestrates evaluation and wires the event-driven reactions:
 *
 *   spec.changed         → re-validate the specification + the course's open offerings
 *   spec.approved        → re-validate offerings + programs containing the course
 *   results.imported     → recalculate achievement → detect gaps → draft improvements
 *   achievement.computed → re-validate offering, evaluate improvement effectiveness, programs
 *   offering.assigned    → initialise the course quality workspace
 *   plo.changed          → impact analysis + revalidate affected specifications
 *   improvement.changed  → re-validate offering
 */
final class Engine
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        Events::on('spec.changed', static function (array $p): string {
            $n = 0;
            if (!empty($p['version_id'])) {
                $n += self::evaluateSpec((int) $p['version_id']);
                Intelligence::forSpec((int) $p['version_id']);
            }
            foreach (self::openOfferings((int) $p['course_id']) as $oid) {
                $n += self::evaluateOffering($oid);
            }
            return "Re-validated ($n checks)";
        });

        Events::on('spec.approved', static function (array $p): string {
            foreach (self::openOfferings((int) $p['course_id']) as $oid) {
                self::evaluateOffering($oid);
            }
            $programs = array_column(Catalog::programsFor((int) $p['course_id']), 'program_id');
            foreach ($programs as $pid) {
                self::evaluateProgram((int) $pid);
            }
            return 'Offerings and ' . count($programs) . ' program(s) re-evaluated';
        });

        Events::on('results.imported', static function (array $p): string {
            $r = Achievement::compute((int) $p['offering_id']);
            return "Achievement recalculated for {$r['clos']} CLO(s)";
        });

        Events::on('achievement.computed', static function (array $p): string {
            $oid = (int) $p['offering_id'];
            $drafted = Improvements::draftForGaps($oid);
            $evaluated = Improvements::evaluateEffectiveness($oid);
            self::evaluateOffering($oid);
            Intelligence::forOffering($oid);
            $courseId = (int) Db::val('SELECT course_id FROM course_offerings WHERE id = ?', [$oid]);
            foreach (array_column(Catalog::programsFor($courseId), 'program_id') as $pid) {
                self::evaluateProgram((int) $pid);
            }
            return "Gap drafts: $drafted · effectiveness evaluated: $evaluated";
        });

        Events::on('improvement.changed', static function (array $p): string {
            self::evaluateOffering((int) $p['offering_id']);
            return 'Offering re-validated';
        });

        Events::on('plo.changed', static function (array $p): string {
            $impact = Impact::forPlo((int) $p['plo_id']);
            foreach ($impact['spec_versions'] as $vid) {
                self::evaluateSpec((int) $vid);
            }
            self::evaluateProgram((int) $p['program_id']);
            return 'Impact: ' . count($impact['courses']) . ' course(s), ' . $impact['mappings'] . ' mapping(s) re-validated';
        });

        Events::on('offering.assigned', static function (array $p): string {
            $r = Workspaces::initialize((int) $p['course_id'], (int) $p['term_id'], $p['instructor_id'] ? (int) $p['instructor_id'] : null, (int) ($p['sections'] ?? 1), (int) ($p['enrolled'] ?? 0), $p['source'] ?? 'sis', $p['section_rows'] ?? null);
            return $r['message'];
        });
    }

    /** @return int number of checks run */
    public static function evaluateSpec(int $versionId): int
    {
        $v = Specs::version($versionId);
        if (!$v || in_array($v['status'], ['superseded', 'rejected'], true)) {
            return 0;
        }
        $s = Specs::load($versionId);
        $r = Rules::checkSpec($s);
        Findings::reconcile('spec', $versionId, Rules::codesFor('spec'), $r['violations']);
        Ledger::add('check_run', $r['checks'], null, (int) $v['course_id'], "Specification v{$v['version_no']}");
        return $r['checks'];
    }

    public static function evaluateOffering(int $offeringId): int
    {
        $o = self::offeringContext($offeringId);
        if (!$o) {
            return 0;
        }
        $r = Rules::checkOffering($o);
        Findings::reconcile('offering', $offeringId, Rules::codesFor('offering'), $r['violations']);
        Ledger::add('check_run', $r['checks'], $offeringId, (int) $o['course_id'], 'Offering checks');
        return $r['checks'];
    }

    public static function evaluateProgram(int $programId): int
    {
        $p = Db::one('SELECT p.*, d.college_id FROM programs p JOIN departments d ON d.id = p.department_id WHERE p.id = ?', [$programId]);
        if (!$p) {
            return 0;
        }
        $r = Rules::checkProgram($p);
        Findings::reconcile('program', $programId, Rules::codesFor('program'), $r['violations']);
        return $r['checks'];
    }

    /** Re-evaluate everything for a course: working spec + open offerings. */
    public static function evaluateCourse(int $courseId): void
    {
        $w = Specs::working($courseId);
        if ($w) {
            self::evaluateSpec((int) $w['id']);
        }
        foreach (self::openOfferings($courseId) as $oid) {
            self::evaluateOffering($oid);
        }
    }

    public static function offeringContext(int $offeringId): ?array
    {
        return Db::one(
            'SELECT o.*, c.code AS course_code, c.title AS course_title, c.owner_department_id, d.college_id, t.name AS term_name, t.grades_due_on, t.ends_on, t.status AS term_status
             FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN departments d ON d.id = c.owner_department_id JOIN terms t ON t.id = o.term_id WHERE o.id = ?',
            [$offeringId]
        );
    }

    /** @return list<int> */
    public static function openOfferings(int $courseId): array
    {
        return array_map('intval', Db::col('SELECT o.id FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.course_id = ? AND t.status <> "closed"', [$courseId]));
    }
}

<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Ledger;
use Saqf\Core\Notify;

/**
 * Course quality workspaces and the semester cycle.
 *  - A SIS teaching assignment creates (or updates) the workspace automatically.
 *  - The approved specification is inherited; semester evidence starts empty.
 *  - Closing a term finalises achievement and freezes an immutable report snapshot.
 *  - Activating a term rolls everything forward with zero re-entry.
 */
final class Workspaces
{
    /**
     * @param list<array{section:string,instructor_id:?int,enrolled:int}>|null $sectionRows sections from the SIS
     *        (multi-section courses); $instructorId is then the course coordinator
     * @return array{offering_id:int,message:string,created:bool}
     */
    public static function initialize(int $courseId, int $termId, ?int $instructorId, int $sections = 1, int $enrolled = 0, string $source = 'sis', ?array $sectionRows = null): array
    {
        $course = Catalog::course($courseId);
        $term = Db::one('SELECT * FROM terms WHERE id = ?', [$termId]);
        $existing = Db::one('SELECT * FROM course_offerings WHERE course_id = ? AND term_id = ?', [$courseId, $termId]);
        if ($existing) {
            $changes = [];
            if ((int) $existing['instructor_id'] !== (int) $instructorId) {
                $changes['instructor_id'] = $instructorId;
            }
            if ((int) $existing['enrolled'] !== $enrolled && $enrolled > 0) {
                $changes['enrolled'] = $enrolled;
            }
            if ($changes) {
                Db::update('course_offerings', $changes, 'id = ?', [$existing['id']]);
                Audit::record('workspace.updated', 'offering', $existing['id'], "{$course['code']} {$term['name']}: assignment updated from SIS", array_intersect_key($existing, $changes), $changes);
                if (isset($changes['instructor_id']) && $instructorId) {
                    Notify::user($instructorId, 'info', "{$course['code']} {$term['name']} is now assigned to you", 'Your course quality workspace is ready; previous structure and history are already loaded.', 'workspace.php?id=' . $existing['id'], 'assigned:' . $existing['id'] . ':' . $instructorId);
                    if (!$existing['spec_version_id'] && $term['status'] !== 'closed') {
                        Notify::user($instructorId, 'action', "{$course['code']}: one academic task needs your input", 'This course has no approved specification yet. Define its outcomes and assessment plan once; SAQF reuses them every term.', 'workspace.php?id=' . $existing['id'], 'first-spec:' . $existing['id'] . ':' . $instructorId);
                    }
                }
            }
            $sectionChanges = $sectionRows !== null ? array_sum(Sections::sync((int) $existing['id'], $sectionRows)) : 0;
            if ($changes || $sectionChanges) {
                Engine::evaluateOffering((int) $existing['id']);
            }
            return ['offering_id' => (int) $existing['id'], 'message' => "{$course['code']} {$term['name']} already initialised" . ($changes || $sectionChanges ? ' (assignment updated)' : ''), 'created' => false];
        }

        $approved = Specs::approved($courseId);
        $offeringId = Db::insert('course_offerings', [
            'course_id' => $courseId,
            'term_id' => $termId,
            'instructor_id' => $instructorId,
            'sections' => max(1, $sections),
            'enrolled' => max(0, $enrolled),
            'spec_version_id' => $approved['id'] ?? null,
            'status' => 'active',
            'source' => $source,
            'initialized_by' => $source === 'manual' ? 'user' : 'system',
            'initialized_at' => Clock::stamp(),
        ]);
        if ($sectionRows !== null) {
            Sections::sync($offeringId, $sectionRows);
        }

        // Automation transparency: count what was populated or inherited, not typed.
        $programs = Catalog::programsFor($courseId);
        $fields = 9 + count($programs) * 3 + count(Catalog::requisitesFor($courseId));
        Ledger::add('field_populated', $fields, $offeringId, $courseId, 'Course identity, ownership, study-plan placement, prerequisites, instructor, term');
        $inherited = 0;
        if ($approved) {
            $inherited = (int) Db::val('SELECT COUNT(*) FROM clos WHERE spec_version_id = ?', [$approved['id']])
                + (int) Db::val('SELECT COUNT(*) FROM clo_plo cp JOIN clos c ON c.id = cp.clo_id WHERE c.spec_version_id = ?', [$approved['id']])
                + (int) Db::val('SELECT COUNT(*) FROM assessments WHERE spec_version_id = ?', [$approved['id']])
                + (int) Db::val('SELECT COUNT(*) FROM assessment_clo ac JOIN assessments a ON a.id = ac.assessment_id WHERE a.spec_version_id = ?', [$approved['id']]);
            Ledger::add('record_inherited', $inherited, $offeringId, $courseId, "Approved specification v{$approved['version_no']} (CLOs, mappings, assessments, links)");
        }
        $carried = (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE course_id = ? AND status IN ("open","in_progress")', [$courseId]);
        Ledger::add('record_inherited', $carried, $offeringId, $courseId, 'Open improvement actions carried forward for follow-up');

        $msg = "{$course['code']} {$term['name']} workspace initialised" . ($approved ? " — inherited v{$approved['version_no']} ($inherited records)" : ' — first specification needed');
        Audit::record('workspace.initialized', 'offering', $offeringId, $msg, null, ['instructor_id' => $instructorId, 'source' => $source]);
        Engine::evaluateCourse($courseId);

        if ($instructorId && !$approved && $term['status'] !== 'closed') {
            Notify::user($instructorId, 'action', "{$course['code']}: one academic task needs your input", 'This course has no approved specification yet. Define its outcomes and assessment plan once; SAQF reuses them every term.', 'workspace.php?id=' . $offeringId, 'first-spec:' . $offeringId);
        }
        return ['offering_id' => $offeringId, 'message' => $msg, 'created' => true];
    }

    /** Finalise a term: final evaluation, immutable report snapshots, closed status. */
    public static function closeTerm(int $termId): array
    {
        $term = Db::one('SELECT * FROM terms WHERE id = ?', [$termId]);
        $offerings = Db::col('SELECT id FROM course_offerings WHERE term_id = ?', [$termId]);
        $snapshots = 0;
        foreach ($offerings as $oid) {
            Engine::evaluateOffering((int) $oid);
            if (Db::val('SELECT 1 FROM clo_achievement WHERE offering_id = ?', [$oid])) {
                Reports::snapshotCourse((int) $oid);
                $snapshots++;
            }
            Db::update('course_offerings', ['status' => 'closed', 'closed_at' => Clock::stamp()], 'id = ?', [$oid]);
        }
        Db::update('terms', ['status' => 'closed'], 'id = ?', [$termId]);
        Audit::record('term.closed', 'term', $termId, "{$term['name']} closed: " . count($offerings) . " workspaces finalised, $snapshots report snapshots frozen");
        return ['offerings' => count($offerings), 'snapshots' => $snapshots];
    }

    /** Activate a term: SIS assignments create every workspace automatically (rollover). */
    public static function activateTerm(int $termId): array
    {
        $term = Db::one('SELECT * FROM terms WHERE id = ?', [$termId]);
        foreach (Db::col('SELECT id FROM terms WHERE status = "active" AND id <> ?', [$termId]) as $prev) {
            self::closeTerm((int) $prev);
        }
        Db::update('terms', ['status' => 'active'], 'id = ?', [$termId]);
        $stats = \Saqf\Integration\Sync::assignments($term['code']);
        Audit::record('term.activated', 'term', $termId, "{$term['name']} activated: {$stats['created']} workspaces created from SIS assignments, {$stats['inherited']} inherited an approved specification");
        return $stats;
    }
}

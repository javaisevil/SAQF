<?php
declare(strict_types=1);

namespace Saqf\Integration;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Events;
use Saqf\Core\Ledger;
use Saqf\Quality\Catalog;
use Saqf\Quality\Engine;
use Saqf\Quality\Findings;
use Saqf\Security\Users;

/**
 * Synchronises institutional master data into SAQF (one-way: the university's
 * systems are the source of truth). Detects contradictions between sources,
 * resolves them from the authoritative owner when it safely can, and raises a
 * data exception for Quality/Registrar when it cannot.
 */
final class Sync
{
    public static function institution(?InstitutionSource $source = null): array
    {
        $source = $source ?? Integrations::institution();
        $runId = Db::insert('sync_runs', ['source' => 'institution', 'started_at' => Clock::stamp(), 'status' => 'running']);
        $stats = ['colleges' => 0, 'departments' => 0, 'programs' => 0, 'courses' => 0, 'plan_entries' => 0, 'requisites' => 0, 'plos' => 0, 'plo_changes' => 0, 'conflicts' => 0, 'auto_corrected' => 0];
        try {
            $snap = $source->snapshot();
            $now = Clock::stamp();
            Audit::asSystem(static function () use ($snap, $now, &$stats) {
                Db::tx(static function () use ($snap, $now, &$stats) {
                    // Colleges & departments --------------------------------------------
                    $collegeIds = [];
                    foreach ($snap['colleges'] as $c) {
                        Db::exec('INSERT INTO colleges (code, name, name_ar, synced_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), name_ar = VALUES(name_ar), synced_at = VALUES(synced_at)', [$c['code'], $c['name'], $c['name_ar'] ?? null, $now]);
                        $collegeIds[$c['code']] = (int) Db::val('SELECT id FROM colleges WHERE code = ?', [$c['code']]);
                        $stats['colleges']++;
                    }
                    $deptIds = [];
                    foreach ($snap['departments'] as $d) {
                        Db::exec('INSERT INTO departments (code, college_id, name, synced_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE college_id = VALUES(college_id), name = VALUES(name), synced_at = VALUES(synced_at)', [$d['code'], $collegeIds[$d['college']], $d['name'], $now]);
                        $deptIds[$d['code']] = (int) Db::val('SELECT id FROM departments WHERE code = ?', [$d['code']]);
                        $stats['departments']++;
                    }
                    $ownership = $snap['ownership'];
                    usort($ownership, static fn($a, $b) => strlen($b['prefix']) <=> strlen($a['prefix']));
                    $ownerOf = static function (string $code) use ($ownership, $deptIds): ?int {
                        foreach ($ownership as $o) {
                            if (str_starts_with($code, $o['prefix'])) {
                                return $deptIds[$o['department']] ?? null;
                            }
                        }
                        return null;
                    };

                    // Collect every course variant across program plans -------------------
                    $variants = [];
                    $programDept = [];
                    foreach ($snap['programs'] as $p) {
                        $programDept[$p['code']] = $deptIds[$p['department']];
                        foreach ($p['courses'] as $row) {
                            if (!$row['code']) {
                                continue;
                            }
                            $variants[$row['code']][] = ['title' => $row['title'], 'credits' => (float) $row['credits'], 'program' => $p['code'], 'where' => $p['code'] . ' study plan'];
                        }
                        foreach ($p['source_variants'] ?? [] as $sv) {
                            if ($sv['field'] === 'credits') {
                                $base = $variants[$sv['code']][0] ?? ['title' => $sv['code']];
                                $variants[$sv['code']][] = ['title' => $base['title'], 'credits' => (float) $sv['value'], 'program' => $p['code'], 'where' => $p['code'] . ': ' . $sv['where']];
                            }
                        }
                    }

                    $violations = [];
                    foreach ($variants as $code => $list) {
                        $owner = $ownerOf($code);
                        if ($owner === null) {
                            continue;
                        }
                        $prefix = strtok($code, ' ');
                        // Authority: the plan of the program that owns the prefix, else any plan
                        // from the owning department, else the first source.
                        $auth = null;
                        foreach ($list as $v) {
                            if ($v['program'] === $prefix) {
                                $auth = $v;
                                break;
                            }
                        }
                        if (!$auth) {
                            foreach ($list as $v) {
                                if (($programDept[$v['program']] ?? null) === $owner) {
                                    $auth = $v;
                                    break;
                                }
                            }
                        }
                        $auth = $auth ?? $list[0];

                        $credits = array_values(array_unique(array_map(static fn($v) => $v['credits'], $list)));
                        if (count($credits) > 1) {
                            $authoritySources = array_filter($list, static fn($v) => $v['program'] === $auth['program']);
                            $authCredits = array_unique(array_map(static fn($v) => $v['credits'], $authoritySources));
                            $stats['conflicts']++;
                            $desc = implode('; ', array_map(static fn($v) => $v['where'] . ': ' . rtrim(rtrim(number_format($v['credits'], 1), '0'), '.') . ' CR', $list));
                            $resolvable = count($authCredits) === 1;
                            $violations[] = [
                                'rule' => 'COURSE_CREDIT_CONFLICT', 'key' => $code, 'department_id' => $owner,
                                'title' => "$code: credit hours differ between institutional sources",
                                'detail' => "Sources disagree — $desc. " . ($resolvable
                                    ? 'SAQF uses ' . $auth['credits'] . ' CR from the owning program\'s plan (' . $auth['program'] . ').'
                                    : 'The owning program\'s own plan is internally inconsistent, so SAQF cannot pick an authoritative value; it uses ' . $auth['credits'] . ' CR from the semester grid until the Registrar confirms.'),
                                'remedy' => 'Registrar / program coordinator: correct the published study plan so all sources agree.',
                                'context' => ['variants' => $list],
                            ];
                        }
                        $titles = array_unique(array_map(static fn($v) => \Saqf\Quality\Text::normalize($v['title']), $list));
                        if (count($titles) > 1) {
                            $stats['auto_corrected']++;
                        }
                        $graduate = (int) (preg_match('/\d+/', $code, $m) && (int) $m[0] >= 500);
                        $desc = $snap['descriptions'][$code] ?? null;
                        $existing = Db::one('SELECT id, title, credits FROM courses WHERE code = ?', [$code]);
                        Db::exec(
                            'INSERT INTO courses (code, title, credits, owner_department_id, is_graduate, description, description_source, synced_at) VALUES (?,?,?,?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE title = VALUES(title), credits = VALUES(credits), owner_department_id = VALUES(owner_department_id), is_graduate = VALUES(is_graduate),
                               description = COALESCE(VALUES(description), description), description_source = COALESCE(VALUES(description_source), description_source), synced_at = VALUES(synced_at)',
                            [$code, $auth['title'], $auth['credits'], $owner, $graduate, $desc['text'] ?? null, $desc['source'] ?? null, $now]
                        );
                        if ($existing && ((float) $existing['credits'] !== (float) $auth['credits'] || $existing['title'] !== $auth['title'])) {
                            Audit::record('masterdata.course_updated', 'course', $existing['id'], "$code updated from institutional source", ['title' => $existing['title'], 'credits' => $existing['credits']], ['title' => $auth['title'], 'credits' => $auth['credits']]);
                        }
                        $stats['courses']++;
                    }
                    Findings::reconcile('institution', 1, ['COURSE_CREDIT_CONFLICT'], $violations);
                    $courseIds = [];
                    foreach (Db::all('SELECT id, code FROM courses') as $c) {
                        $courseIds[$c['code']] = (int) $c['id'];
                    }

                    // Programs, plans, requisites, elective rules, PLOs ---------------------
                    $prereqViolations = [];
                    foreach ($snap['programs'] as $p) {
                        Db::exec(
                            'INSERT INTO programs (code, name, short_name, degree, level, department_id, total_credits, plan_version, plan_date, source_url, plo_source, accreditation_note, synced_at)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), short_name = VALUES(short_name), degree = VALUES(degree), level = VALUES(level),
                             department_id = VALUES(department_id), total_credits = VALUES(total_credits), plan_version = VALUES(plan_version), plan_date = VALUES(plan_date),
                             source_url = VALUES(source_url), plo_source = VALUES(plo_source), accreditation_note = VALUES(accreditation_note), synced_at = VALUES(synced_at)',
                            [$p['code'], $p['name'], $p['short_name'], $p['degree'], $p['level'], $deptIds[$p['department']], $p['total_credits'], $p['plan_version'], $p['plan_date'], $p['source_url'], $p['plo_source'], $p['accreditation_note'] ?: null, $now]
                        );
                        $pid = (int) Db::val('SELECT id FROM programs WHERE code = ?', [$p['code']]);
                        $stats['programs']++;
                        Db::exec('DELETE FROM study_plan_entries WHERE program_id = ?', [$pid]);
                        Db::exec('DELETE FROM course_requisites WHERE program_id = ?', [$pid]);
                        Db::exec('DELETE FROM elective_rules WHERE program_id = ?', [$pid]);
                        $sum = 0.0;
                        foreach ($p['courses'] as $row) {
                            $cid = $row['code'] ? ($courseIds[$row['code']] ?? null) : null;
                            if ($row['code'] && !$cid) {
                                continue;
                            }
                            $level = ($row['year'] && $row['semester'] && $row['semester'] <= 2) ? ($row['year'] - 1) * 2 + $row['semester'] : null;
                            Db::exec(
                                'INSERT IGNORE INTO study_plan_entries (program_id, course_id, slot_title, plan_year, plan_semester, level_no, requirement_group, course_type, credits, credit_threshold, preparatory)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                                [$pid, $cid, $row['slot'] ? $row['title'] : null, $row['year'] === null ? null : (int) $row['year'], $row['semester'] ?: null, $level, $row['group'], $row['type'], $row['credits'], $row['credit_threshold'], $row['preparatory'] ? implode(', ', $row['preparatory']) : null]
                            );
                            $stats['plan_entries']++;
                            if ($row['slot'] || ($row['type'] === 'required' && ($row['year'] ?? 0) > 0)) {
                                $sum += (float) $row['credits'];
                            }
                            foreach (['prerequisites' => 'prerequisite', 'corequisites' => 'corequisite'] as $field => $kind) {
                                foreach ($row[$field] ?? [] as $req) {
                                    if (!isset($courseIds[$req])) {
                                        $prereqViolations[] = ['rule' => 'PREREQ_UNKNOWN', 'key' => $p['code'] . ':' . $row['code'] . ':' . $req, 'program_id' => $pid, 'department_id' => $deptIds[$p['department']],
                                            'title' => "{$row['code']} requires unknown course $req ({$p['code']})", 'detail' => "The {$p['code']} plan lists $req as a $kind of {$row['code']}, but $req is not in the synced catalog.", 'remedy' => 'Correct the study-plan source or add the missing course to the catalog.'];
                                        continue;
                                    }
                                    Db::exec('INSERT IGNORE INTO course_requisites (program_id, course_id, requires_course_id, kind) VALUES (?,?,?,?)', [$pid, $cid, $courseIds[$req], $kind]);
                                    $stats['requisites']++;
                                }
                            }
                        }
                        foreach ($p['elective_rules'] ?? [] as $r) {
                            Db::insert('elective_rules', ['program_id' => $pid, 'group_name' => $r['group'], 'courses_required' => $r['courses'] ?? 0, 'credits_required' => $r['credits'] ?? 0, 'condition_text' => $r['condition'] ?? null]);
                        }
                        // Plan integrity: do listed courses add up to the published total?
                        $planViolations = [];
                        if ($p['total_credits'] && abs($sum - $p['total_credits']) > 0.01) {
                            $planViolations[] = ['rule' => 'PLAN_CREDIT_MISMATCH', 'key' => '', 'program_id' => $pid, 'department_id' => $deptIds[$p['department']],
                                'title' => "{$p['code']}: study plan lists " . rtrim(rtrim(number_format($sum, 1), '0'), '.') . " of {$p['total_credits']} published credits",
                                'detail' => 'Required courses and elective slots in the synced plan total ' . rtrim(rtrim(number_format($sum, 1), '0'), '.') . " CR, but the plan publishes {$p['total_credits']} CR.",
                                'remedy' => 'Check the source plan for a missing row (e.g. a 1-credit exam-review course) and correct the Registrar record.'];
                        }
                        Findings::reconcile('program', $pid, ['PLAN_CREDIT_MISMATCH'], $planViolations);

                        foreach ($p['plos'] as $plo) {
                            $old = Db::one('SELECT * FROM plos WHERE program_id = ? AND code = ?', [$pid, $plo['code']]);
                            if (!$old) {
                                Db::insert('plos', ['program_id' => $pid, 'code' => $plo['code'], 'domain' => $plo['domain'], 'statement' => $plo['text'], 'status' => 'approved', 'source' => 'institution', 'synced_at' => $now, 'updated_at' => $now]);
                                $stats['plos']++;
                            } elseif ($old['source'] === 'institution' && (\Saqf\Quality\Text::normalize($old['statement']) !== \Saqf\Quality\Text::normalize($plo['text']) || $old['domain'] !== $plo['domain'])) {
                                Db::update('plos', ['statement' => $plo['text'], 'domain' => $plo['domain'], 'version' => (int) $old['version'] + 1, 'status' => 'approved', 'synced_at' => $now, 'updated_at' => $now], 'id = ?', [$old['id']]);
                                Audit::record('plo.changed', 'plo', $old['id'], "{$p['code']} {$plo['code']} statement changed in the institutional source", ['statement' => $old['statement']], ['statement' => $plo['text']]);
                                Events::emit('plo.changed', ['plo_id' => (int) $old['id'], 'program_id' => $pid]);
                                $stats['plo_changes']++;
                            }
                        }
                    }
                    Findings::reconcile('institution', 2, ['PREREQ_UNKNOWN'], $prereqViolations);
                });
            }, 'integration', 'Registrar sync');
            Catalog::flush();
            Ledger::add('data_corrected', $stats['auto_corrected'], null, null, 'Course titles normalised to the owning program\'s plan');
            Ledger::add('field_populated', $stats['courses'] * 4 + $stats['plan_entries'] * 5 + $stats['requisites'], null, null, 'Institutional master data synchronised');
            foreach (Db::col('SELECT id FROM programs') as $pid) {
                Engine::evaluateProgram((int) $pid);
            }
            Db::update('sync_runs', ['finished_at' => Clock::stamp(), 'status' => 'ok', 'stats' => $stats, 'message' => $source->label()], 'id = ?', [$runId]);
            Audit::asSystem(static fn() => Audit::record('integration.sync', 'sync_run', $runId, "Institutional data synchronised: {$stats['programs']} programs, {$stats['courses']} courses, {$stats['plan_entries']} plan entries, {$stats['conflicts']} conflict(s) detected", null, $stats), 'integration', 'Registrar sync');
        } catch (\Throwable $e) {
            Db::update('sync_runs', ['finished_at' => Clock::stamp(), 'status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 2000)], 'id = ?', [$runId]);
            throw $e;
        }
        return $stats;
    }

    public static function terms(?SisSource $sis = null): int
    {
        $sis = $sis ?? Integrations::sis();
        $n = 0;
        foreach ($sis->terms() as $t) {
            Db::exec(
                'INSERT INTO terms (code, name, academic_year, sequence, starts_on, ends_on, grades_due_on, status) VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), academic_year = VALUES(academic_year), sequence = VALUES(sequence), starts_on = VALUES(starts_on), ends_on = VALUES(ends_on), grades_due_on = VALUES(grades_due_on)',
                [$t['code'], $t['name'], $t['academic_year'], $t['sequence'], $t['starts_on'], $t['ends_on'], $t['grades_due_on'], 'upcoming']
            );
            $n++;
        }
        return $n;
    }

    /** Pull SIS teaching assignments for a term; each one initialises a workspace via an event. */
    public static function assignments(string $termCode, ?SisSource $sis = null): array
    {
        $sis = $sis ?? Integrations::sis();
        $term = Db::one('SELECT * FROM terms WHERE code = ?', [$termCode]);
        $stats = ['assignments' => 0, 'created' => 0, 'inherited' => 0, 'unknown_courses' => 0, 'instructors_provisioned' => 0, 'unknown_instructors' => 0];
        if (!$term) {
            return $stats;
        }
        Audit::asSystem(static function () use ($sis, $termCode, $term, &$stats) {
            foreach ($sis->assignments($termCode) as $a) {
                $stats['assignments']++;
                $courseId = Db::val('SELECT id FROM courses WHERE code = ?', [$a['course']]);
                if (!$courseId) {
                    $stats['unknown_courses']++;
                    continue;
                }
                $instructor = $a['instructor'] ? Db::val('SELECT id FROM users WHERE external_id = ?', [$a['instructor']]) : null;
                if ($a['instructor'] && !$instructor) {
                    // New instructor: link an existing account by e-mail, or create one so the workspace has an owner.
                    $dept = !empty($a['department']) ? Db::val('SELECT id FROM departments WHERE code = ?', [strtoupper((string) $a['department'])]) : null;
                    $dept = $dept ?: Db::val('SELECT owner_department_id FROM courses WHERE id = ?', [$courseId]);
                    $instructor = Users::provisionInstructor((string) $a['instructor'], $a['instructor_name'] ?? null, $a['instructor_email'] ?? null, $dept ? (int) $dept : null);
                    $stats[$instructor ? 'instructors_provisioned' : 'unknown_instructors']++;
                }
                $before = (int) Db::val('SELECT COUNT(*) FROM course_offerings WHERE term_id = ?', [$term['id']]);
                Events::emit('offering.assigned', ['course_id' => (int) $courseId, 'term_id' => (int) $term['id'], 'instructor_id' => $instructor ? (int) $instructor : null, 'sections' => $a['sections'] ?? 1, 'enrolled' => $a['enrolled'] ?? 0, 'source' => 'sis']);
                $after = (int) Db::val('SELECT COUNT(*) FROM course_offerings WHERE term_id = ?', [$term['id']]);
                if ($after > $before) {
                    $stats['created']++;
                    if (Db::val('SELECT spec_version_id FROM course_offerings WHERE course_id = ? AND term_id = ?', [$courseId, $term['id']])) {
                        $stats['inherited']++;
                    }
                }
            }
        }, 'integration', 'SIS integration');
        Db::insert('sync_runs', ['source' => 'sis.assignments', 'started_at' => Clock::stamp(), 'finished_at' => Clock::stamp(), 'status' => 'ok', 'stats' => $stats, 'message' => $termCode]);
        return $stats;
    }
}

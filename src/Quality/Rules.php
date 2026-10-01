<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Db;
use Saqf\Core\Ledger;
use Saqf\Core\Policy;

/**
 * The central quality rules engine. Every deterministic quality rule lives here
 * (never scattered through UI code). Each rule states what is wrong, why it
 * matters and how to fix it, and who must act. Thresholds come from Policy.
 *
 * Categories: validation (structure), data (sources disagree), academic (needs
 * judgement), policy (rule conflict / override), evidence, workflow, quality_risk.
 */
final class Rules
{
    public const META = [
        // ---------- specification structure (scope: spec version) ----------
        'SPEC_NO_CLOS' => ['validation', 'blocker', 'faculty', 'Course learning outcomes define what the course is accountable for; nothing can be assessed, mapped or reported without them.'],
        'CLO_VAGUE_VERB' => ['validation', 'blocker', 'faculty', 'Outcomes that start with verbs like "understand" or "know" cannot be measured, so achievement cannot be calculated objectively.'],
        'CLO_VERB_UNRECOGNISED' => ['validation', 'info', 'faculty', 'SAQF could not confirm the outcome starts with an observable action verb; reviewers may ask for a clearer verb.'],
        'CLO_DUPLICATE' => ['validation', 'blocker', 'faculty', 'Two identical outcomes double-count the same learning and distort achievement.'],
        'CLO_UNMAPPED' => ['validation', 'blocker', 'faculty', 'Every CLO must contribute to the program outcomes of each program that requires this course, or PLO achievement for that program has gaps.'],
        'CLO_UNMAPPED_ELECTIVE' => ['validation', 'info', 'hod', 'The course is an elective in this program; mapping lets its results count toward that program\'s PLOs too.'],
        'CLO_NOT_ASSESSED' => ['validation', 'blocker', 'faculty', 'An outcome with no linked assessment can never produce achievement evidence.'],
        'ASSESSMENT_NO_CLO' => ['validation', 'blocker', 'faculty', 'Marks from an assessment that measures no outcome cannot be used as quality evidence.'],
        'ASSESSMENT_WEIGHT_TOTAL' => ['validation', 'blocker', 'faculty', 'Assessment weights must form the complete course grade; otherwise achievement and grading do not match.'],
        'ASSESSMENT_ZERO_WEIGHT' => ['validation', 'blocker', 'faculty', 'An assessment with no weight contributes nothing to grades or achievement.'],
        'ASSESSMENT_SINGLE_WEIGHT' => ['policy', 'warning', 'faculty', 'Institutional policy limits how much of the grade one assessment can carry. Exceptions (e.g. capstones) need a justified, QA-approved override.'],
        'MAPPING_EXCESSIVE' => ['validation', 'warning', 'faculty', 'A CLO mapped to many PLOs usually means the mapping is not specific enough to be meaningful evidence.'],
        'SPEC_OBJECTIVES_MISSING' => ['validation', 'warning', 'faculty', 'The course specification needs a short statement of the course\'s main objective.'],
        'CLO_DOMAIN_NARROW' => ['academic', 'info', 'faculty', 'All outcomes sit in one learning domain; consider whether skills or values are intended.'],
        // ---------- offering (scope: offering) ----------
        'OFFERING_NO_INSTRUCTOR' => ['data', 'warning', 'hod', 'The SIS has no instructor for this offering, so nobody owns its quality record.'],
        'OFFERING_NO_SPEC' => ['workflow', 'blocker', 'faculty', 'This course has no approved specification yet. Outcomes and assessments must be defined once; afterwards they are inherited every term.'],
        'RESULTS_OVERDUE' => ['evidence', 'warning', 'faculty', 'Grades were due but no complete results reached SAQF, so achievement cannot be finalised.'],
        'RESULTS_MISSING_ASSESSMENT' => ['evidence', 'info', 'faculty', 'Some planned assessments have no results; achievement for their CLOs is based on partial evidence.'],
        'CLO_TARGET_MISSED' => ['academic', 'warning', 'faculty', 'The measured outcome is below its target. Policy requires an academic interpretation and an improvement action.'],
        'CLO_EARLY_WARNING' => ['quality_risk', 'warning', 'faculty', 'Provisional results (part of the term\'s assessments) show this CLO trending below target while there is still time to intervene.'],
        'GAP_RECURRING' => ['quality_risk', 'warning', 'hod', 'The same outcome has missed its target in consecutive offerings — this is a pattern, not a one-off.'],
        'IMPROVEMENT_MISSING' => ['workflow', 'warning', 'faculty', 'Policy requires a committed improvement action (owner, deadline, academic response) for every missed target.'],
        'IMPROVEMENT_OVERDUE' => ['workflow', 'warning', 'hod', 'An improvement action passed its deadline without being completed.'],
        'INTERPRETATION_MISSING' => ['academic', 'info', 'faculty', 'The course report needs the instructor\'s interpretation of the results that missed their targets.'],
        'LOW_SAMPLE' => ['evidence', 'info', 'faculty', 'Too few students were assessed for the percentage to be statistically reliable.'],
        // ---------- program (scope: program) ----------
        'PROGRAM_NO_PLOS' => ['data', 'warning', 'hod', 'Without approved PLOs, course outcomes cannot be mapped and program achievement cannot be calculated.'],
        'PLO_NOT_COVERED' => ['academic', 'warning', 'hod', 'No course in the curriculum currently develops this PLO — a curriculum gap.'],
        'PLO_THIN_COVERAGE' => ['quality_risk', 'info', 'hod', 'Only one course contributes to this PLO; the program depends on a single offering for its evidence.'],
        'PLO_BELOW_TARGET' => ['academic', 'warning', 'hod', 'Program-level achievement for this PLO is below target in the latest measured term.'],
        'PLO_PERSISTENT_BELOW' => ['quality_risk', 'warning', 'dean', 'This PLO has stayed below target across consecutive measurement cycles.'],
        'PLAN_CREDIT_MISMATCH' => ['data', 'warning', 'qa', 'The courses listed in the study-plan source do not add up to the published total credit hours.'],
        // ---------- institution / data sync (scope: institution) ----------
        'COURSE_CREDIT_CONFLICT' => ['data', 'warning', 'qa', 'Institutional sources disagree on a course\'s credit hours; downstream calculations need one authoritative value.'],
        'PREREQ_UNKNOWN' => ['data', 'info', 'qa', 'A prerequisite refers to a course code that is not in the synced catalog.'],
    ];

    public static function meta(string $code): array
    {
        $m = self::META[$code] ?? ['validation', 'warning', 'faculty', ''];
        return ['category' => $m[0], 'severity' => $m[1], 'owner' => $m[2], 'why' => $m[3]];
    }

    public static function codesFor(string $prefixGroup): array
    {
        $groups = [
            'spec' => ['SPEC_NO_CLOS', 'CLO_VAGUE_VERB', 'CLO_VERB_UNRECOGNISED', 'CLO_DUPLICATE', 'CLO_UNMAPPED', 'CLO_UNMAPPED_ELECTIVE', 'CLO_NOT_ASSESSED', 'ASSESSMENT_NO_CLO', 'ASSESSMENT_WEIGHT_TOTAL', 'ASSESSMENT_ZERO_WEIGHT', 'ASSESSMENT_SINGLE_WEIGHT', 'MAPPING_EXCESSIVE', 'SPEC_OBJECTIVES_MISSING', 'CLO_DOMAIN_NARROW'],
            'offering' => ['OFFERING_NO_INSTRUCTOR', 'OFFERING_NO_SPEC', 'RESULTS_OVERDUE', 'RESULTS_MISSING_ASSESSMENT', 'CLO_TARGET_MISSED', 'CLO_EARLY_WARNING', 'GAP_RECURRING', 'IMPROVEMENT_MISSING', 'IMPROVEMENT_OVERDUE', 'INTERPRETATION_MISSING', 'LOW_SAMPLE'],
            'program' => ['PROGRAM_NO_PLOS', 'PLO_NOT_COVERED', 'PLO_THIN_COVERAGE', 'PLO_BELOW_TARGET', 'PLO_PERSISTENT_BELOW'],
        ];
        return $groups[$prefixGroup] ?? [];
    }

    // =====================================================================
    // SPEC VERSION RULES
    // =====================================================================

    /** @return array{violations:list<array>,checks:int} */
    public static function checkSpec(array $s): array
    {
        $v = [];
        $checks = 0;
        $course = $s['course'];
        $base = ['course_id' => (int) $course['id'], 'department_id' => (int) $course['owner_department_id'], 'college_id' => (int) $course['college_id']];
        $add = static function (string $rule, string $key, string $title, string $detail, ?string $remedy = null, array $context = []) use (&$v, $base) {
            $v[] = $base + ['rule' => $rule, 'key' => $key, 'title' => $title, 'detail' => $detail, 'remedy' => $remedy, 'context' => $context];
        };

        $checks++;
        if (!$s['clos']) {
            $add('SPEC_NO_CLOS', '', $course['code'] . ': no course learning outcomes', 'The specification has no CLOs yet.', 'Add the course learning outcomes (usually 3–6), each starting with an observable verb.');
        }

        $seen = [];
        $domains = [];
        $electiveGaps = [];
        $maxPlos = Policy::get('mapping.max_plos_per_clo');
        foreach ($s['clos'] as $clo) {
            $label = $course['code'] . ' ' . $clo['code'];
            $domains[$clo['domain']] = true;
            $checks += 4;
            $quality = Text::verbQuality($clo['statement']);
            $firstWord = strtok(Text::firstVerb($clo['statement']), ' ') ?: '';
            if ($quality === 'vague') {
                $add('CLO_VAGUE_VERB', $clo['lineage_key'], "$label starts with a non-measurable verb", "\"{$clo['statement']}\" begins with \"$firstWord\", which cannot be observed or measured.", 'Rewrite it with an observable verb, e.g. "Explain…", "Design…", "Evaluate…".', ['clo_id' => $clo['id']]);
            } elseif ($quality === 'unknown') {
                $add('CLO_VERB_UNRECOGNISED', $clo['lineage_key'], "$label: verb \"$firstWord\" not recognised", 'SAQF could not confirm this outcome starts with a measurable action verb.', 'Check the outcome begins with a verb describing observable performance.', ['clo_id' => $clo['id']]);
            }
            $norm = Text::normalize($clo['statement']);
            if (isset($seen[$norm])) {
                $add('CLO_DUPLICATE', $clo['lineage_key'], "$label duplicates {$seen[$norm]}", 'Two CLOs have the same statement.', 'Merge them or make each outcome distinct.', ['clo_id' => $clo['id']]);
            }
            $seen[$norm] = $clo['code'];

            foreach ($s['programs'] as $p) {
                if ((int) $p['plo_count'] === 0) {
                    continue; // reported once at program level (PROGRAM_NO_PLOS)
                }
                $checks++;
                $mapped = $clo['maps'][(int) $p['program_id']] ?? [];
                if (!$mapped) {
                    // Blocker when the course is required in the program, or the program belongs to the
                    // department that owns the course (its "home" program). Electives offered to other
                    // departments' programs are advisory and belong to that program's HoD.
                    $isRequired = $p['course_type'] === 'required' || (int) $p['department_id'] === (int) $course['owner_department_id'];
                    if ($isRequired) {
                        $add(
                            'CLO_UNMAPPED',
                            $clo['lineage_key'] . '@' . $p['code'],
                            "$label is not mapped to any {$p['code']} PLO",
                            "{$course['code']} is " . ($p['course_type'] === 'required' ? 'a required course' : 'an elective owned by this department') . " in {$p['short_name']}, but {$clo['code']} contributes to none of its PLOs.",
                            "Select the {$p['code']} PLO(s) this outcome develops. SAQF only offers PLOs of programs whose study plan contains this course.",
                            ['clo_id' => $clo['id'], 'program_id' => $p['program_id']]
                        );
                    } else {
                        $electiveGaps[(int) $p['program_id']]['program'] = $p;
                        $electiveGaps[(int) $p['program_id']]['clos'][] = $clo['code'];
                    }
                } elseif (count($mapped) > $maxPlos) {
                    $add('MAPPING_EXCESSIVE', $clo['lineage_key'] . '@' . $p['code'], "$label maps to " . count($mapped) . " {$p['code']} PLOs", "Mapped to " . implode(', ', array_column($mapped, 'code')) . ". Policy flags more than $maxPlos as possibly over-mapped.", 'Keep only the PLOs this outcome substantially develops and assesses.', ['clo_id' => $clo['id']]);
                }
            }
            if (!$clo['assessments']) {
                $add('CLO_NOT_ASSESSED', $clo['lineage_key'], "$label has no assessment", "No assessment measures \"" . mb_strimwidth($clo['statement'], 0, 90, '…') . '"', 'Link at least one assessment to this CLO in the assessment plan.', ['clo_id' => $clo['id']]);
            }
        }

        foreach ($electiveGaps as $pid => $gap) {
            $p = $gap['program'];
            $v[] = ['course_id' => (int) $course['id'], 'department_id' => (int) $p['department_id'], 'college_id' => (int) $p['college_id'], 'program_id' => $pid,
                'rule' => 'CLO_UNMAPPED_ELECTIVE', 'key' => $p['code'],
                'title' => "{$course['code']} (elective in {$p['code']}) is not mapped to {$p['code']} PLOs",
                'detail' => "{$course['code']} appears in {$p['short_name']} under \"{$p['requirement_group']}\". " . implode(', ', $gap['clos']) . " contribute to none of its PLOs, so results from students of that program are not counted toward its outcomes.",
                'remedy' => "Program coordinator: map {$course['code']}'s CLOs to {$p['code']} PLOs (or confirm the course should not count toward them)."];
        }

        if (count($s['clos']) >= 3 && count($domains) === 1) {
            $add('CLO_DOMAIN_NARROW', '', $course['code'] . ': all CLOs in one domain', 'All ' . count($s['clos']) . ' CLOs are classified as "' . array_key_first($domains) . '".', 'Confirm whether skills or values outcomes are intended for this course.');
        }

        $total = 0.0;
        $maxSingle = Policy::get('assessment.max_single_weight_pct');
        foreach ($s['assessments'] as $a) {
            $total += (float) $a['weight_pct'];
            $checks += 3;
            if (!$a['clos']) {
                $add('ASSESSMENT_NO_CLO', $a['lineage_key'], "{$course['code']} assessment \"{$a['name']}\" measures no CLO", "\"{$a['name']}\" (" . self::fmt((float) $a['weight_pct']) . "%) is not linked to any CLO.", 'Link it to the CLO(s) it actually assesses.', ['assessment_id' => $a['id']]);
            }
            if ((float) $a['weight_pct'] <= 0) {
                $add('ASSESSMENT_ZERO_WEIGHT', $a['lineage_key'], "{$course['code']} assessment \"{$a['name']}\" has no weight", 'Weight is 0%.', 'Give the assessment its share of the course grade or remove it.', ['assessment_id' => $a['id']]);
            }
            if ((float) $a['weight_pct'] > $maxSingle) {
                $add('ASSESSMENT_SINGLE_WEIGHT', $a['lineage_key'], "{$course['code']} \"{$a['name']}\" carries " . self::fmt((float) $a['weight_pct']) . '% of the grade', "Policy allows at most $maxSingle% for a single assessment.", 'Split the assessment into components, or request a justified exception from Quality Assurance.', ['assessment_id' => $a['id']]);
            }
        }
        $required = Policy::get('assessment.weight_total_pct');
        $checks++;
        if ($s['assessments'] && abs($total - $required) >= 0.01) {
            $diff = $required - $total;
            $add('ASSESSMENT_WEIGHT_TOTAL', '', $course['code'] . ': assessment weights total ' . self::fmt($total) . '%', 'Weights add up to ' . self::fmt($total) . "% instead of $required% (" . ($diff > 0 ? self::fmt($diff) . '% unallocated' : self::fmt(-$diff) . '% over') . ').', 'Adjust the weights so they total exactly ' . self::fmt($required) . '%.', ['total' => $total]);
        } elseif (!$s['assessments'] && $s['clos']) {
            $add('ASSESSMENT_WEIGHT_TOTAL', '', $course['code'] . ': no assessment plan', 'No assessments are defined, so the grade structure totals 0%.', 'Add the assessments that make up the course grade.');
        }

        $checks++;
        if (trim((string) $s['version']['objectives']) === '') {
            $add('SPEC_OBJECTIVES_MISSING', '', $course['code'] . ': main objective missing', 'The course main objective is empty.', 'Write one or two sentences on what the course aims to achieve.');
        }

        // Remove policy findings that QA has overridden for this course.
        return ['violations' => $v, 'checks' => $checks];
    }

    // =====================================================================
    // OFFERING RULES
    // =====================================================================

    public static function checkOffering(array $o): array
    {
        $v = [];
        $checks = 0;
        $base = ['course_id' => (int) $o['course_id'], 'offering_id' => (int) $o['id'], 'department_id' => (int) $o['owner_department_id'], 'college_id' => (int) $o['college_id']];
        $add = static function (string $rule, string $key, string $title, string $detail, ?string $remedy = null, array $context = [], ?string $severity = null) use (&$v, $base) {
            $row = $base + ['rule' => $rule, 'key' => $key, 'title' => $title, 'detail' => $detail, 'remedy' => $remedy, 'context' => $context];
            if ($severity) {
                $row['severity'] = $severity;
            }
            $v[] = $row;
        };
        $label = $o['course_code'] . ' (' . $o['term_name'] . ')';

        $checks++;
        if (!$o['instructor_id']) {
            $add('OFFERING_NO_INSTRUCTOR', '', "$label has no instructor in the SIS", 'The teaching assignment feed has no instructor for this offering.', 'Ask the Registrar to complete the assignment, or assign the course manually.');
        }
        $checks++;
        if (!$o['spec_version_id']) {
            $add('OFFERING_NO_SPEC', '', "$label needs its first specification", 'There is no approved specification to inherit for this course.', 'Define the CLOs, their PLO mapping and the assessment plan once; SAQF reuses them every following term.');
            return ['violations' => $v, 'checks' => $checks];
        }

        $today = \Saqf\Core\Clock::today();
        $assessments = Db::all('SELECT a.id, a.name, a.weight_pct, (SELECT COUNT(*) FROM assessment_results r WHERE r.offering_id = ? AND r.assessment_id = a.id) AS n FROM assessments a WHERE a.spec_version_id = ? ORDER BY a.sort_order', [$o['id'], $o['spec_version_id']]);
        $withResults = array_filter($assessments, static fn($a) => (int) $a['n'] > 0);
        $checks++;
        if ($today > $o['grades_due_on'] && count($withResults) < count($assessments)) {
            if (!$withResults) {
                $add('RESULTS_OVERDUE', '', "$label: results overdue", "Grades were due on {$o['grades_due_on']} but no results have arrived from the LMS.", 'Publish the gradebook in the LMS (SAQF imports it automatically) or upload the results file.');
            } else {
                $missing = array_column(array_filter($assessments, static fn($a) => (int) $a['n'] === 0), 'name');
                $add('RESULTS_MISSING_ASSESSMENT', '', "$label: " . count($missing) . ' assessment(s) without results', 'No results for: ' . implode(', ', $missing) . '.', 'Publish the missing marks in the LMS, or confirm the assessment was not conducted.');
            }
        }

        $ach = Db::all('SELECT ca.*, cl.code, cl.statement FROM clo_achievement ca JOIN clos cl ON cl.id = ca.clo_id WHERE ca.offering_id = ? ORDER BY cl.sort_order', [$o['id']]);
        $minStudents = Policy::get('results.min_students');
        $recurrence = Policy::get('gap.recurrence_cycles');
        $gapsRequireAction = Policy::get('improvement.required_on_gap');
        $hasGap = false;
        foreach ($ach as $a) {
            $checks += 3;
            $clabel = $o['course_code'] . ' ' . $a['code'];
            if ((int) $a['students_assessed'] < $minStudents) {
                $add('LOW_SAMPLE', $a['lineage_key'], "$clabel: only {$a['students_assessed']} students assessed", "Below the policy minimum of $minStudents students.", 'Interpret this percentage with caution.');
            }
            if ((int) $a['met'] === 1) {
                continue;
            }
            if ((int) $a['provisional'] === 1) {
                $add('CLO_EARLY_WARNING', $a['lineage_key'], "$clabel trending below target (provisional)", 'Provisional achievement ' . self::fmt((float) $a['value_pct']) . '% vs target ' . self::fmt((float) $a['target_pct']) . '%, based on ' . self::fmt((float) $a['coverage_pct']) . '% of the CLO\'s assessment weight.', 'Consider an in-term intervention before the remaining assessments.', ['clo_id' => $a['clo_id']]);
                continue;
            }
            $hasGap = true;
            $committed = (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE origin_offering_id = ? AND clo_lineage_key = ? AND status IN ("open","in_progress","completed")', [$o['id'], $a['lineage_key']]);
            if (!$committed) {
                $add('CLO_TARGET_MISSED', $a['lineage_key'], "$clabel below target: " . self::fmt((float) $a['value_pct']) . '% vs ' . self::fmt((float) $a['target_pct']) . '%', "\"" . mb_strimwidth($a['statement'], 0, 110, '…') . "\" — " . $a['students_assessed'] . ' students assessed.', 'Add your interpretation in the course report and commit an improvement action.', ['clo_id' => $a['clo_id']]);
            }

            // Recurrence: consecutive missed offerings for the same CLO lineage.
            $history = Db::all(
                'SELECT ca.met FROM clo_achievement ca JOIN course_offerings oo ON oo.id = ca.offering_id JOIN terms t ON t.id = oo.term_id
                 WHERE ca.lineage_key = ? AND ca.provisional = 0 AND t.sequence <= (SELECT sequence FROM terms WHERE id = ?) ORDER BY t.sequence DESC LIMIT 6',
                [$a['lineage_key'], $o['term_id']]
            );
            $streak = 0;
            foreach ($history as $h) {
                if ((int) $h['met'] === 1) {
                    break;
                }
                $streak++;
            }
            $checks++;
            if ($streak >= $recurrence) {
                $add('GAP_RECURRING', $a['lineage_key'], "$clabel missed its target $streak offerings in a row", "Recurring gap: below target in each of the last $streak measured offerings.", 'Review whether previous improvement actions addressed the cause; consider curriculum or assessment changes.', ['clo_id' => $a['clo_id'], 'streak' => $streak]);
            }

            if ($gapsRequireAction && !$committed) {
                $checks++;
                $add('IMPROVEMENT_MISSING', $a['lineage_key'], "$clabel needs an improvement action", 'SAQF drafted an improvement record with the evidence; it needs your academic response, owner and deadline.', 'Open the Improvement tab, write the action and commit it.', ['clo_id' => $a['clo_id']]);
            }
        }

        $checks++;
        if ($hasGap && $o['status'] === 'results_complete' || ($hasGap && $o['status'] === 'closed')) {
            $hasNarrative = Db::val('SELECT 1 FROM offering_narratives WHERE offering_id = ? AND section_key = "interpretation" AND TRIM(content) <> ""', [$o['id']]);
            if (!$hasNarrative) {
                $add('INTERPRETATION_MISSING', '', "$label: results need your interpretation", 'At least one CLO missed its target and the course report has no instructor interpretation yet.', 'Write a short interpretation of the results in the Report tab.');
            }
        }

        $overdue = Db::all('SELECT id, title, due_on FROM improvement_actions WHERE course_id = ? AND status IN ("open","in_progress") AND due_on IS NOT NULL AND due_on < ?', [$o['course_id'], $today]);
        foreach ($overdue as $ia) {
            $checks++;
            $add('IMPROVEMENT_OVERDUE', 'ia' . $ia['id'], "{$o['course_code']} improvement action overdue", "\"{$ia['title']}\" was due on {$ia['due_on']}.", 'Complete the action or agree a revised deadline with the owner.', ['action_id' => $ia['id']]);
        }

        return ['violations' => $v, 'checks' => $checks];
    }

    // =====================================================================
    // PROGRAM RULES
    // =====================================================================

    public static function checkProgram(array $p): array
    {
        $v = [];
        $checks = 0;
        $base = ['program_id' => (int) $p['id'], 'department_id' => (int) $p['department_id'], 'college_id' => (int) $p['college_id']];
        $add = static function (string $rule, string $key, string $title, string $detail, ?string $remedy = null, array $context = []) use (&$v, $base) {
            $v[] = $base + ['rule' => $rule, 'key' => $key, 'title' => $title, 'detail' => $detail, 'remedy' => $remedy, 'context' => $context];
        };

        $plos = Db::all('SELECT * FROM plos WHERE program_id = ? AND status = "approved" ORDER BY code', [$p['id']]);
        $checks++;
        if (!$plos) {
            $add('PROGRAM_NO_PLOS', '', "{$p['code']}: no approved PLOs", "The institutional source has no approved PLO statements for {$p['short_name']}.", 'Upload the approved PLOs (Program page → PLOs), or confirm them with the Registrar feed.');
            return ['violations' => $v, 'checks' => $checks];
        }

        // Coverage from approved specifications of courses in this program's plan.
        $coverage = Db::all(
            'SELECT cp.plo_id, COUNT(DISTINCT sv.course_id) AS courses, GROUP_CONCAT(DISTINCT c.code ORDER BY c.code SEPARATOR ", ") AS codes
             FROM clo_plo cp JOIN clos cl ON cl.id = cp.clo_id JOIN spec_versions sv ON sv.id = cl.spec_version_id AND sv.status = "approved"
             JOIN courses c ON c.id = sv.course_id JOIN plos pl ON pl.id = cp.plo_id
             WHERE pl.program_id = ? AND sv.course_id IN (SELECT course_id FROM study_plan_entries WHERE program_id = ? AND course_id IS NOT NULL)
             GROUP BY cp.plo_id',
            [$p['id'], $p['id']]
        );
        $byPlo = [];
        foreach ($coverage as $c) {
            $byPlo[(int) $c['plo_id']] = $c;
        }
        $specCount = (int) Db::val('SELECT COUNT(DISTINCT sv.course_id) FROM spec_versions sv WHERE sv.status = "approved" AND sv.course_id IN (SELECT course_id FROM study_plan_entries WHERE program_id = ? AND course_id IS NOT NULL)', [$p['id']]);
        $minCourses = Policy::get('program.min_courses_per_plo');
        if ($specCount >= 3) {
            foreach ($plos as $plo) {
                $checks += 2;
                $cov = $byPlo[(int) $plo['id']] ?? null;
                if (!$cov) {
                    $add('PLO_NOT_COVERED', $plo['code'], "{$p['code']} {$plo['code']} is not developed by any course", "None of the $specCount courses with approved specifications maps a CLO to {$plo['code']}.", 'Identify which courses should develop this PLO and map their CLOs, or review the PLO.', ['plo_id' => $plo['id']]);
                } elseif ((int) $cov['courses'] < $minCourses) {
                    $add('PLO_THIN_COVERAGE', $plo['code'], "{$p['code']} {$plo['code']} depends on a single course ({$cov['codes']})", "Only {$cov['courses']} course(s) contribute evidence to {$plo['code']}.", 'Consider mapping a second course so the PLO is not measured by one offering only.', ['plo_id' => $plo['id']]);
                }
            }
        }

        // Achievement by term (final, non-provisional values only).
        $target = Policy::get('plo.target_pct');
        $recurrence = Policy::get('gap.recurrence_cycles');
        foreach ($plos as $plo) {
            $series = Db::all(
                'SELECT t.id AS term_id, t.name, t.sequence, AVG(pa.value_pct) AS v, COUNT(*) AS n
                 FROM plo_achievement pa JOIN course_offerings o ON o.id = pa.offering_id JOIN terms t ON t.id = o.term_id
                 WHERE pa.plo_id = ? AND pa.program_id = ? AND pa.provisional = 0 GROUP BY t.id, t.name, t.sequence ORDER BY t.sequence DESC',
                [$plo['id'], $p['id']]
            );
            if (!$series) {
                continue;
            }
            $checks += 2;
            $latest = $series[0];
            if ((float) $latest['v'] < $target) {
                $add('PLO_BELOW_TARGET', $plo['code'], "{$p['code']} {$plo['code']} at " . self::fmt((float) $latest['v']) . "% ({$latest['name']})", "Average of {$latest['n']} course contribution(s) in {$latest['name']} is below the $target% PLO target.", 'Review the contributing courses\' gaps and improvement actions.', ['plo_id' => $plo['id']]);
                $streak = 0;
                foreach ($series as $s) {
                    if ((float) $s['v'] >= $target) {
                        break;
                    }
                    $streak++;
                }
                if ($streak >= $recurrence) {
                    $add('PLO_PERSISTENT_BELOW', $plo['code'], "{$p['code']} {$plo['code']} below target for $streak consecutive terms", 'Terms: ' . implode(', ', array_map(static fn($s) => $s['name'] . ' ' . self::fmt((float) $s['v']) . '%', array_slice($series, 0, $streak))) . '.', 'Program-level review: curriculum, assessment design and improvement effectiveness.', ['plo_id' => $plo['id'], 'streak' => $streak]);
                }
            }
        }
        return ['violations' => $v, 'checks' => $checks];
    }

    public static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}

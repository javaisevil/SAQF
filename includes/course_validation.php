<?php

function aqmsTableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function aqmsColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tableName` LIKE ?");
        $stmt->execute([$columnName]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function aqmsHasMeasurableVerb(string $text): bool
{
    $text = strtolower(trim($text));
    if ($text === '') {
        return false;
    }

    $weakVerbs = ['understand', 'know', 'learn', 'be familiar with', 'be aware of'];
    foreach ($weakVerbs as $verb) {
        if (preg_match('/^' . preg_quote($verb, '/') . '\b/', $text)) {
            return false;
        }
    }

    $measurableVerbs = [
        'adapt', 'analyze', 'apply', 'assemble', 'assess', 'audit', 'build', 'calculate',
        'classify', 'choose', 'compare', 'configure', 'conduct', 'construct', 'create',
        'critique', 'debug', 'define', 'demonstrate', 'describe', 'design', 'develop',
        'differentiate', 'evaluate', 'examine', 'explain', 'formulate', 'generate',
        'identify', 'implement', 'integrate', 'interpret', 'justify', 'list', 'manage',
        'measure', 'model', 'monitor', 'operate', 'organize', 'plan', 'prepare', 'present',
        'produce', 'propose', 'recognize', 'select', 'solve', 'summarize', 'synthesize',
        'test', 'use', 'validate', 'verify', 'write'
    ];

    foreach ($measurableVerbs as $verb) {
        if (preg_match('/^' . preg_quote($verb, '/') . '\b/', $text)) {
            return true;
        }
    }

    return false;
}

function aqmsValidateCourseSpecification(PDO $pdo, array $course, bool $strict = true): array
{
    $errors = [];
    $courseId = (int)($course['course_id'] ?? 0);
    $programId = (int)($course['program_id'] ?? 0);

    $requiredCourseFields = [
        'course_title' => 'Course title is required.',
        'course_code' => 'Course code is required.',
        'program_id' => 'Program is required.',
        'credit_hours' => 'Credit hours are required.',
        'course_level' => 'Course level/year is required.',
        'course_type' => 'At least one course type A category is required.',
        'required_elective' => 'Course type B is required.',
        'course_description' => 'Course general description is required.',
        'objectives' => 'Course main objectives are required.'
    ];

    foreach ($requiredCourseFields as $field => $message) {
        if (!isset($course[$field]) || trim((string)$course[$field]) === '') {
            $errors[] = $message;
        }
    }

    if ((float)($course['credit_hours'] ?? 0) <= 0) {
        $errors[] = 'Credit hours must be greater than zero.';
    }
    if ((int)($course['course_level'] ?? 0) <= 0) {
        $errors[] = 'Course level/year must be greater than zero.';
    }

    if ($courseId <= 0) {
        $errors[] = 'Course record could not be identified.';
        return array_values(array_unique($errors));
    }

    if ($programId > 0 && !empty($course['course_code']) && aqmsTableExists($pdo, 'course_specs')) {
        $duplicate = $pdo->prepare(
            'SELECT COUNT(*) FROM course_specs
             WHERE program_id = ? AND UPPER(TRIM(course_code)) = UPPER(TRIM(?)) AND course_id <> ?'
        );
        $duplicate->execute([$programId, $course['course_code'], $courseId]);
        if ((int)$duplicate->fetchColumn() > 0) {
            $errors[] = 'Course code must be unique within the selected program.';
        }
    }

    if (!aqmsTableExists($pdo, 'teaching_modes')) {
        $errors[] = 'Teaching-mode data is unavailable. Apply the current database migration.';
    } else {
        $teachingModes = $pdo->prepare('SELECT COUNT(*) FROM teaching_modes WHERE course_id = ?');
        $teachingModes->execute([$courseId]);
        if ((int)$teachingModes->fetchColumn() === 0) {
            $errors[] = 'At least one teaching mode is required.';
        }
    }

    if (!aqmsTableExists($pdo, 'contact_hours')) {
        $errors[] = 'Contact-hour data is unavailable. Apply the current database migration.';
    } else {
        $contactHours = $pdo->prepare('SELECT COUNT(*) FROM contact_hours WHERE course_id = ? AND hours IS NOT NULL AND hours > 0');
        $contactHours->execute([$courseId]);
        if ((int)$contactHours->fetchColumn() === 0) {
            $errors[] = 'Contact hours must be entered.';
        }
    }

    if (!aqmsTableExists($pdo, 'course_topics')) {
        $errors[] = 'Course-content data is unavailable. Apply the current database migration.';
    } else {
        $topics = $pdo->prepare('SELECT COUNT(*) FROM course_topics WHERE course_id = ? AND TRIM(topic_text) <> ""');
        $topics->execute([$courseId]);
        if ((int)$topics->fetchColumn() === 0) {
            $errors[] = 'At least one course content topic is required.';
        }
    }

    $cloRows = [];
    if (!aqmsTableExists($pdo, 'course_learning_outcomes')) {
        $errors[] = 'CLO data is unavailable. Apply the current database migration.';
    } else {
        $clos = $pdo->prepare('SELECT clo_id, clo_code, description FROM course_learning_outcomes WHERE course_id = ?');
        $clos->execute([$courseId]);
        $cloRows = $clos->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($cloRows)) {
        $errors[] = 'At least one CLO is required.';
    } else {
        $duplicateCodes = $pdo->prepare(
            'SELECT clo_code FROM course_learning_outcomes
             WHERE course_id = ? AND TRIM(clo_code) <> ""
             GROUP BY clo_code HAVING COUNT(*) > 1'
        );
        $duplicateCodes->execute([$courseId]);
        foreach ($duplicateCodes->fetchAll(PDO::FETCH_COLUMN) as $duplicateCode) {
            $errors[] = 'CLO code ' . $duplicateCode . ' is repeated. Each CLO code must be unique.';
        }

        $mapCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM clo_plo_mapping m
             INNER JOIN program_learning_outcomes p ON p.plo_id = m.plo_id
             WHERE m.clo_id = ? AND p.program_id = ?'
        );
        foreach ($cloRows as $clo) {
            $label = trim((string)($clo['clo_code'] ?? '')) ?: 'A CLO';
            if (!aqmsHasMeasurableVerb((string)$clo['description'])) {
                $errors[] = $label . ' must start with a measurable verb, not a vague verb such as understand/know/learn.';
            }
            $mapCheck->execute([$clo['clo_id'], $programId]);
            if ((int)$mapCheck->fetchColumn() === 0) {
                $errors[] = $label . ' must map to at least one PLO from the selected program.';
            }
        }
    }

    if (!aqmsTableExists($pdo, 'assessments')) {
        $errors[] = 'Assessment data is unavailable. Apply the current database migration.';
    } else {
        $hasAssessmentTiming = aqmsColumnExists($pdo, 'assessments', 'assessment_timing');
        $hasProportion = aqmsColumnExists($pdo, 'assessments', 'proportion_of_total');
        $hasRubric = aqmsColumnExists($pdo, 'assessments', 'rubric');
        $hasPerformanceTask = aqmsColumnExists($pdo, 'assessments', 'performance_task');

        $assessmentFields = 'id, activity_name, timing_week, percentage';
        if ($hasAssessmentTiming) $assessmentFields .= ', assessment_timing';
        if ($hasProportion) $assessmentFields .= ', proportion_of_total';
        if ($hasRubric) $assessmentFields .= ', rubric';
        if ($hasPerformanceTask) $assessmentFields .= ', performance_task';

        $assessments = $pdo->prepare("SELECT $assessmentFields FROM assessments WHERE course_id = ?");
        $assessments->execute([$courseId]);
        $assessmentRows = $assessments->fetchAll(PDO::FETCH_ASSOC);

        if (empty($assessmentRows)) {
            $errors[] = 'At least one assessment activity is required.';
        } else {
            $total = 0;
            $linkCheck = $pdo->prepare('SELECT COUNT(*) FROM assessment_clo WHERE assessment_id = ?');
            foreach ($assessmentRows as $assessment) {
                $name = trim((string)$assessment['activity_name']) ?: 'An assessment activity';
                $weight = $hasProportion ? $assessment['proportion_of_total'] : $assessment['percentage'];
                $total += (float)$weight;

                if (empty($assessment['timing_week'])) {
                    $errors[] = $name . ' must include assessment timing.';
                }
                if ($assessment['percentage'] === null || $assessment['percentage'] === '') {
                    $errors[] = $name . ' must include percentage of total assessment.';
                }
                if ($hasAssessmentTiming && trim((string)$assessment['assessment_timing']) === '') {
                    $errors[] = $name . ' must include a readable assessment timing label.';
                }
                if ($hasProportion && ($assessment['proportion_of_total'] === null || $assessment['proportion_of_total'] === '')) {
                    $errors[] = $name . ' must include its proportion of the total assessment score.';
                }
                if ($hasRubric && trim((string)$assessment['rubric']) === '') {
                    $errors[] = $name . ' must include rubric criteria.';
                }
                if ($hasPerformanceTask && trim((string)$assessment['performance_task']) === '') {
                    $errors[] = $name . ' must include a performance task or evidence description.';
                }

                $linkCheck->execute([$assessment['id']]);
                if ((int)$linkCheck->fetchColumn() === 0) {
                    $errors[] = $name . ' must be linked to at least one CLO.';
                }
            }
            if (abs($total - 100) >= 0.01) {
                $errors[] = 'Assessment percentages must total 100%.';
            }
        }
    }

    if (!aqmsTableExists($pdo, 'resources')) {
        $errors[] = 'Learning-resource data is unavailable. Apply the current database migration.';
    } else {
        $resources = $pdo->prepare('SELECT COUNT(*) FROM resources WHERE course_id = ? AND TRIM(resource_text) <> ""');
        $resources->execute([$courseId]);
        if ((int)$resources->fetchColumn() === 0) {
            $errors[] = 'At least one learning resource is required.';
        }
    }

    if (!aqmsTableExists($pdo, 'course_facilities')) {
        $errors[] = 'Facilities data is unavailable. Apply the current database migration.';
    } else {
        $facilities = $pdo->prepare('SELECT COUNT(*) FROM course_facilities WHERE course_id = ? AND TRIM(resources) <> ""');
        $facilities->execute([$courseId]);
        if ((int)$facilities->fetchColumn() === 0) {
            $errors[] = 'Required facilities and equipment must be entered.';
        }
    }

    if (!aqmsTableExists($pdo, 'course_quality')) {
        $errors[] = 'Course-quality data is unavailable. Apply the current database migration.';
    } else {
        $quality = $pdo->prepare(
            'SELECT COUNT(*) FROM course_quality
             WHERE course_id = ? AND TRIM(assessor) <> "" AND TRIM(assessment_method) <> ""'
        );
        $quality->execute([$courseId]);
        if ((int)$quality->fetchColumn() === 0) {
            $errors[] = 'Assessment of course quality must be completed.';
        }
    }

    if ($strict) {
        if (!aqmsTableExists($pdo, 'course_approval')) {
            $errors[] = 'Specification-approval data is unavailable. Apply the current database migration.';
        } else {
            $approval = $pdo->prepare(
                'SELECT COUNT(*) FROM course_approval
                 WHERE course_id = ? AND TRIM(council_committee) <> ""
                 AND TRIM(reference_no) <> "" AND approval_date IS NOT NULL'
            );
            $approval->execute([$courseId]);
            if ((int)$approval->fetchColumn() === 0) {
                $errors[] = 'Specification approval data must be completed.';
            }
        }
    }

    if (!aqmsTableExists($pdo, 'course_pdca')) {
        $errors[] = 'PDCA data is unavailable. Apply the current database migration.';
    } else {
        $contentColumn = aqmsColumnExists($pdo, 'course_pdca', 'content') ? 'content' : 'description';
        $pdca = $pdo->prepare(
            "SELECT COUNT(*) FROM course_pdca
             WHERE course_id = ? AND `$contentColumn` IS NOT NULL AND TRIM(`$contentColumn`) <> ''"
        );
        $pdca->execute([$courseId]);
        if ((int)$pdca->fetchColumn() === 0) {
            $errors[] = 'At least one PDCA improvement entry is required.';
        }
    }

    return array_values(array_unique($errors));
}

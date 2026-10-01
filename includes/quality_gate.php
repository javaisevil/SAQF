<?php

require_once __DIR__ . '/course_validation.php';

function aqmsTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function aqmsEvaluateQualityGate(PDO $pdo, array $course, bool $strict = true): array
{
    $errors = aqmsValidateCourseSpecification($pdo, $course, $strict);
    $warnings = [];
    $courseId = (int)($course['course_id'] ?? 0);
    $programId = (int)($course['program_id'] ?? 0);

    if ($courseId > 0 && aqmsTableExists($pdo, 'course_specs') && !empty($course['course_code'])) {
        $duplicate = $pdo->prepare(
            'SELECT COUNT(*) FROM course_specs
             WHERE program_id = ? AND UPPER(TRIM(course_code)) = UPPER(TRIM(?)) AND course_id <> ?'
        );
        $duplicate->execute([$programId, $course['course_code'], $courseId]);
        if ((int)$duplicate->fetchColumn() > 0 && !in_array('Course code must be unique within the selected program.', $errors, true)) {
            $warnings[] = 'Another course in this program uses the same course code.';
        }
    }

    if (aqmsTextLength(trim((string)($course['course_description'] ?? ''))) < 80) {
        $warnings[] = 'The course description is brief. Add enough context for reviewers and accreditation evidence.';
    }
    if (aqmsTextLength(trim((string)($course['objectives'] ?? ''))) < 40) {
        $warnings[] = 'The course objectives are brief. Add a clear statement of the intended student achievement.';
    }

    if ($courseId > 0 && aqmsTableExists($pdo, 'course_learning_outcomes')) {
        $cloCount = $pdo->prepare('SELECT COUNT(*) FROM course_learning_outcomes WHERE course_id = ?');
        $cloCount->execute([$courseId]);
        if ((int)$cloCount->fetchColumn() === 1) {
            $warnings[] = 'Only one CLO is defined. Confirm that the course is fully represented across its knowledge, skills, and values domains.';
        }
    }

    if ($courseId > 0 && aqmsTableExists($pdo, 'course_pdca')) {
        $phaseColumn = aqmsColumnExists($pdo, 'course_pdca', 'phase') ? 'phase' : 'stage';
        $pdca = $pdo->prepare("SELECT DISTINCT `$phaseColumn` FROM course_pdca WHERE course_id = ? AND `$phaseColumn` IS NOT NULL");
        $pdca->execute([$courseId]);
        $phases = array_filter(array_map('trim', $pdca->fetchAll(PDO::FETCH_COLUMN)));
        if (count($phases) < 2) {
            $warnings[] = 'The PDCA log has limited phase coverage. Consider recording the full improvement cycle.';
        }
    }

    if ($courseId > 0 && aqmsTableExists($pdo, 'assessments')
        && aqmsColumnExists($pdo, 'assessments', 'rubric')
        && aqmsColumnExists($pdo, 'assessments', 'performance_task')) {
        $assessmentEvidence = $pdo->prepare(
            'SELECT activity_name, rubric, performance_task FROM assessments WHERE course_id = ?'
        );
        $assessmentEvidence->execute([$courseId]);
        foreach ($assessmentEvidence->fetchAll(PDO::FETCH_ASSOC) as $assessment) {
            $name = trim((string)$assessment['activity_name']) ?: 'An assessment activity';
            if (preg_match('/^Rubric criteria for /i', trim((string)$assessment['rubric']))) {
                $warnings[] = $name . ' still has the default rubric placeholder. Add criteria a reviewer can actually apply.';
            }
            if (preg_match('/^Performance task for /i', trim((string)$assessment['performance_task']))) {
                $warnings[] = $name . ' still has the default performance-task placeholder. Add the evidence students will produce.';
            }
        }
    }

    $checks = [];
    foreach ($errors as $error) {
        $checks[] = [
            'code' => 'hard_validation',
            'severity' => 'red',
            'title' => 'Correction required',
            'detail' => $error
        ];
    }
    foreach ($warnings as $warning) {
        $checks[] = [
            'code' => 'review_note',
            'severity' => 'amber',
            'title' => 'Review note',
            'detail' => $warning
        ];
    }

    if (!empty($errors)) {
        $result = 'red';
        $summary = count($errors) . ' correction' . (count($errors) === 1 ? '' : 's') . ' required before this record can move forward.';
    } elseif (!empty($warnings)) {
        $result = 'amber';
        $summary = 'Deterministic checks passed, with ' . count($warnings) . ' review note' . (count($warnings) === 1 ? '' : 's') . ' for governance review.';
    } else {
        $result = 'green';
        $summary = 'All deterministic quality checks passed. This record is eligible for streamlined governance review.';
    }

    $score = $result === 'red'
        ? max(0, 100 - (count($errors) * 15) - (count($warnings) * 5))
        : max(75, 100 - (count($warnings) * 5));

    return [
        'result' => $result,
        'score' => $score,
        'summary' => $summary,
        'errors' => array_values($errors),
        'warnings' => array_values($warnings),
        'checks' => $checks,
        'ran_at' => date('Y-m-d H:i:s')
    ];
}

function aqmsPersistQualityGate(PDO $pdo, int $courseId, array $gate, ?int $userId = null): ?int
{
    if (!aqmsTableExists($pdo, 'quality_gate_runs')) {
        return null;
    }

    $run = $pdo->prepare(
        'INSERT INTO quality_gate_runs
         (course_id, triggered_by, result, score, summary, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $run->execute([
        $courseId,
        $userId ?: null,
        $gate['result'],
        $gate['score'],
        $gate['summary']
    ]);
    $runId = (int)$pdo->lastInsertId();

    if (aqmsTableExists($pdo, 'quality_gate_results')) {
        $resultInsert = $pdo->prepare(
            'INSERT INTO quality_gate_results
             (run_id, check_code, severity, title, detail, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        foreach ($gate['checks'] as $check) {
            $resultInsert->execute([
                $runId,
                $check['code'],
                $check['severity'],
                $check['title'],
                $check['detail']
            ]);
        }
    }

    if (aqmsColumnExists($pdo, 'course_specs', 'quality_gate_status')) {
        $hasRunId = aqmsColumnExists($pdo, 'course_specs', 'quality_gate_run_id');
        $hasScore = aqmsColumnExists($pdo, 'course_specs', 'quality_gate_score');
        $hasSample = aqmsColumnExists($pdo, 'course_specs', 'qa_sample_required');
        $fields = ['quality_gate_status = ?'];
        $values = [$gate['result']];

        if ($hasScore) {
            $fields[] = 'quality_gate_score = ?';
            $values[] = $gate['score'];
        }
        if ($hasRunId) {
            $fields[] = 'quality_gate_run_id = ?';
            $values[] = $runId;
        }
        if ($hasSample) {
            $fields[] = 'qa_sample_required = ?';
            $values[] = $gate['result'] === 'green' ? 1 : 0;
        }

        $values[] = $courseId;
        $pdo->prepare('UPDATE course_specs SET ' . implode(', ', $fields) . ' WHERE course_id = ?')->execute($values);
    }

    return $runId > 0 ? $runId : null;
}

function aqmsQualityGateLabel(string $result): string
{
    return [
        'green' => 'Green: deterministic checks passed',
        'amber' => 'Amber: exception or review note',
        'red' => 'Red: correction required'
    ][$result] ?? 'Not evaluated';
}

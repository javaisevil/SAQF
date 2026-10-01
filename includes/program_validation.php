<?php

function aqmsProgramTableExists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function aqmsProgramSectionValue(PDO $pdo, int $programId, string $key): string {
    $stmt = $pdo->prepare('SELECT section_value FROM program_tp151_sections WHERE program_id = ? AND section_key = ? LIMIT 1');
    $stmt->execute([$programId, $key]);
    return trim((string)$stmt->fetchColumn());
}

function aqmsProgramCount(PDO $pdo, string $sql, int $programId): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$programId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function aqmsValidateProgramSpecification(PDO $pdo, array $program): array {
    $errors = [];
    $programId = intval($program['program_id'] ?? 0);

    foreach ([
        'program_name' => 'Program name is required.',
        'program_code' => 'Program code is required.',
        'college' => 'College is required.',
        'department' => 'Department is required.',
        'credit_hours' => 'Credit hours are required.',
        'qualification_level' => 'Qualification level is required.',
        'mission' => 'Program mission is required.',
        'goals' => 'Program goals are required.',
        'program_aims' => 'Program aims are required.',
        'program_structure' => 'Program structure is required.'
    ] as $field => $message) {
        if (!isset($program[$field]) || trim((string)$program[$field]) === '') $errors[] = $message;
    }

    if (!aqmsProgramTableExists($pdo, 'program_tp151_sections')) {
        $errors[] = 'TP-151 program section table is missing.';
        return $errors;
    }

    $requiredSections = [
        'main_location' => 'Main program location is required.',
        'branches' => 'Program branches section is required.',
        'partnerships' => 'Program partnerships section is required.',
        'professions_jobs' => 'Professions/jobs section is required.',
        'professional_sectors' => 'Professional sectors section is required.',
        'tracks' => 'Tracks/pathways section is required.',
        'exit_points' => 'Exit points section is required.',
        'admission_requirements' => 'Student admission requirements are required.',
        'orientation_programs' => 'Orientation programs are required.',
        'student_counseling' => 'Student counseling/support section is required.',
        'special_support' => 'Special support section is required.',
        'learning_resources' => 'Program learning resources are required.',
        'facilities_equipment' => 'Program facilities/equipment are required.',
        'safety_procedures' => 'Safety procedures are required.',
        'qa_system' => 'Program quality assurance system is required.',
        'course_monitoring' => 'Course quality monitoring procedures are required.',
        'branch_consistency' => 'Branch consistency procedures are required.',
        'plo_assessment_plan' => 'PLO assessment plan is required.'
    ];

    foreach ($requiredSections as $key => $message) {
        if (aqmsProgramSectionValue($pdo, $programId, $key) === '') $errors[] = $message;
    }

    $checks = [
        'SELECT COUNT(*) FROM program_learning_outcomes WHERE program_id = ?' => 'At least one PLO is required.',
        'SELECT COUNT(*) FROM program_kpis WHERE program_id = ?' => 'At least one KPI is required.',
        'SELECT COUNT(*) FROM program_curriculum_structure WHERE program_id = ?' => 'Curriculum structure is required.',
        'SELECT COUNT(*) FROM program_course_plan WHERE program_id = ?' => 'Program course plan is required.',
        'SELECT COUNT(*) FROM program_staffing WHERE program_id = ?' => 'Faculty and administrative staffing data is required.',
        'SELECT COUNT(*) FROM program_evaluation_matrix WHERE program_id = ?' => 'Program evaluation matrix is required.',
        'SELECT COUNT(*) FROM program_approval WHERE program_id = ? AND TRIM(council_committee) != "" AND TRIM(reference_no) != "" AND approval_date IS NOT NULL' => 'Program specification approval data is required.'
    ];

    foreach ($checks as $sql => $message) {
        if (aqmsProgramCount($pdo, $sql, $programId) === 0) $errors[] = $message;
    }

    if (!aqmsProgramTableExists($pdo, 'program_plo_course_mapping')) {
        $errors[] = 'Program PLO course mapping matrix table is missing.';
    } else {
        $planCount = aqmsProgramCount($pdo, 'SELECT COUNT(*) FROM program_course_plan WHERE program_id = ?', $programId);
        $mapCount = aqmsProgramCount($pdo, 'SELECT COUNT(*) FROM program_plo_course_mapping WHERE program_id = ?', $programId);
        if ($planCount > 0 && $mapCount === 0) {
            $errors[] = 'Program PLO course mapping matrix is required.';
        }
    }

    return array_values(array_unique($errors));
}

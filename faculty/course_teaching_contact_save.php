<?php
require_once __DIR__ . '/../includes/auth_check.php';
requireRole('faculty');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'message' => 'POST is required.']);
}

if (!aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    respond(403, ['ok' => false, 'message' => 'The form expired. Refresh the page and try again.']);
}

$courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$courseId) {
    respond(422, ['ok' => false, 'message' => 'A valid course is required.']);
}

$stmt = $pdo->prepare(
    'SELECT course_id FROM course_specs
     WHERE course_id = ? AND faculty_id = ?
       AND status IN ("draft", "returned_by_hod", "returned_by_qa")'
);
$stmt->execute([$courseId, $_SESSION['user_id']]);
if (!$stmt->fetchColumn()) {
    respond(404, ['ok' => false, 'message' => 'Course not found or not editable.']);
}

$modes = $_POST['mode'] ?? [];
$hours = $_POST['hours'] ?? [];
if (!is_array($modes) || !is_array($hours)) {
    respond(422, ['ok' => false, 'message' => 'Invalid teaching data.']);
}

try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM teaching_modes WHERE course_id = ?')->execute([$courseId]);
    $pdo->prepare('DELETE FROM contact_hours WHERE course_id = ?')->execute([$courseId]);

    $insertMode = $pdo->prepare(
        'INSERT INTO teaching_modes (course_id, mode_type, contact_hours, percentage) VALUES (?, ?, ?, ?)'
    );
    foreach ($modes as $row) {
        if (!is_array($row) || empty($row['selected'])) continue;
        $modeType = trim((string)($row['mode_type'] ?? ''));
        $contactInput = trim((string)($row['contact_hours'] ?? ''));
        $percentageInput = trim((string)($row['percentage'] ?? ''));
        $contactHours = $contactInput === '' ? null : filter_var($contactInput, FILTER_VALIDATE_FLOAT);
        $percentage = $percentageInput === '' ? null : filter_var($percentageInput, FILTER_VALIDATE_FLOAT);
        if ($modeType === '' || strlen($modeType) > 100 || $contactHours === false || ($contactHours !== null && $contactHours < 0) || $percentage === false || ($percentage !== null && ($percentage < 0 || $percentage > 100))) {
            throw new InvalidArgumentException('One or more teaching modes have invalid values.');
        }
        $insertMode->execute([$courseId, $modeType, $contactHours, $percentage]);
    }

    $insertHours = $pdo->prepare(
        'INSERT INTO contact_hours (course_id, activity_type, hours) VALUES (?, ?, ?)'
    );
    foreach ($hours as $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('One or more contact-hour entries are invalid.');
        }
        $activity = trim((string)($row['activity_type'] ?? ''));
        $hoursInput = trim((string)($row['hours'] ?? ''));
        $value = $hoursInput === '' ? null : filter_var($hoursInput, FILTER_VALIDATE_FLOAT);
        if ($activity === '' || strlen($activity) > 100 || $value === false || ($value !== null && $value < 0)) {
            throw new InvalidArgumentException('One or more contact-hour entries have invalid values.');
        }
        $insertHours->execute([$courseId, $activity, $value]);
    }

    $pdo->commit();
    respond(200, ['ok' => true]);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(422, ['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('AQMS teaching/contact save failed: ' . $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'The course data could not be saved. Please try again.']);
}

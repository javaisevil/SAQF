<?php
require_once '../includes/auth_check.php';
requireRole('faculty');
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/quality_gate.php';

$course_id = intval($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['submit_errors'] = ['The submission form expired. Please return to the review step and try again.'];
    header('Location: course_edit.php?id=' . $course_id . '&step=8');
    exit();
}

$stmt = $pdo->prepare('SELECT * FROM course_specs WHERE course_id = ? AND faculty_id = ? AND status IN ("draft", "returned_by_hod", "returned_by_qa")');
$stmt->execute([$course_id, $_SESSION['user_id']]);
$course = $stmt->fetch();

if (!$course) {
    header('Location: dashboard.php');
    exit();
}

$qualityGate = aqmsEvaluateQualityGate($pdo, $course, true);
aqmsPersistQualityGate($pdo, $course_id, $qualityGate, (int)$_SESSION['user_id']);

if ($qualityGate['result'] === 'red') {
    $_SESSION['submit_errors'] = $qualityGate['errors'];
    header('Location: course_edit.php?id=' . $course_id . '&step=8');
    exit();
}

$from_status = $course['status'];
$deadline_status = 'not_due';
if (!empty($course['due_date'])) {
    $deadline_status = date('Y-m-d') <= $course['due_date'] ? 'on_time' : 'late';
}

try {
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE course_specs SET status = "pending_hod", submitted_at = NOW(), deadline_status = ? WHERE course_id = ?')
        ->execute([$deadline_status, $course_id]);

    $pdo->prepare(
        'INSERT INTO approval_log (course_id, user_id, from_status, to_status, comment) VALUES (?, ?, ?, "pending_hod", "Submitted by faculty for HoD review")'
    )->execute([$course_id, $_SESSION['user_id'], $from_status]);
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['submit_errors'] = [APP_DEBUG ? $e->getMessage() : 'The course could not be submitted. Check the database migration and try again.'];
    header('Location: course_edit.php?id=' . $course_id . '&step=8');
    exit();
}

header('Location: dashboard.php?submitted=1');
exit();

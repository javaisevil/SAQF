<?php
require_once '../includes/auth_check.php';
requireRole('qa');
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/quality_gate.php';

$course_id = intval($_GET['id'] ?? 0);
if (!$course_id) { header('Location: dashboard.php'); exit(); }

$stmt = $pdo->prepare('SELECT cs.*, ps.program_name, u.full_name as faculty_name FROM course_specs cs LEFT JOIN program_specs ps ON cs.program_id = ps.program_id LEFT JOIN user u ON cs.faculty_id = u.user_id WHERE cs.course_id = ?');
$stmt->execute([$course_id]);
$course = $stmt->fetch();
if (!$course) { header('Location: dashboard.php'); exit(); }

$page_title = 'QA Review: ' . $course['course_title'];
$msg = '';
$error = '';
$quality_gate = aqmsEvaluateQualityGate($pdo, $course, true);
$validation_errors = $quality_gate['errors'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'The review form expired. Refresh the page and try again.';
    }
    $action = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    if (!$error && $action === 'approve' && $course['status'] === 'pending_qa') {
        if (!empty($validation_errors)) {
            $error = 'This specification still has validation issues and cannot be finally approved.';
        } else {
            $pdo->prepare('UPDATE course_specs SET status = "approved" WHERE course_id = ?')->execute([$course_id]);
            $pdo->prepare('INSERT INTO approval_log (course_id, user_id, from_status, to_status, comment) VALUES (?, ?, "pending_qa", "approved", ?)')
                ->execute([$course_id, $_SESSION['user_id'], $comment ?: 'Final approved by QA']);
            $msg = 'Course specification approved.';
            $course['status'] = 'approved';
        }
    } elseif (!$error && $action === 'reject' && $course['status'] === 'pending_qa') {
        if (!$comment) {
            $error = 'A comment is required when returning a course to faculty.';
        } else {
            $pdo->prepare('UPDATE course_specs SET status = "returned_by_qa" WHERE course_id = ?')->execute([$course_id]);
            $pdo->prepare('INSERT INTO approval_log (course_id, user_id, from_status, to_status, comment) VALUES (?, ?, "pending_qa", "returned_by_qa", ?)')
                ->execute([$course_id, $_SESSION['user_id'], $comment]);
            $msg = 'Course returned to faculty.';
            $course['status'] = 'returned_by_qa';
        }
    }
}

$clos = $pdo->prepare('SELECT cl.*, GROUP_CONCAT(p.plo_code ORDER BY p.plo_code SEPARATOR ", ") as plo_codes FROM course_learning_outcomes cl LEFT JOIN clo_plo_mapping m ON cl.clo_id = m.clo_id LEFT JOIN program_learning_outcomes p ON m.plo_id = p.plo_id WHERE cl.course_id = ? GROUP BY cl.clo_id ORDER BY cl.category, cl.clo_code');
$clos->execute([$course_id]);
$clos = $clos->fetchAll();

$assessments = $pdo->prepare('SELECT * FROM assessments WHERE course_id = ? ORDER BY timing_week');
$assessments->execute([$course_id]);
$assessments = $assessments->fetchAll();

$logs = $pdo->prepare('SELECT al.*, u.full_name, u.role FROM approval_log al JOIN user u ON al.user_id = u.user_id WHERE al.course_id = ? ORDER BY al.created_at DESC');
$logs->execute([$course_id]);
$logs = $logs->fetchAll();

include '../includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if (!empty($validation_errors)): ?>
<div class="alert alert-warning"><strong>Validation issues:</strong><ul><?php foreach ($validation_errors as $item): ?><li><?php echo htmlspecialchars($item); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="quality-gate quality-gate--<?php echo htmlspecialchars($quality_gate['result']); ?>">
    <div class="quality-gate-heading"><div><span class="quality-gate-kicker">Automated quality gate</span><h3><?php echo htmlspecialchars(aqmsQualityGateLabel($quality_gate['result'])); ?></h3></div><strong class="quality-gate-score"><?php echo (int)$quality_gate['score']; ?>%</strong></div>
    <p><?php echo htmlspecialchars($quality_gate['summary']); ?></p>
    <?php if (!empty($quality_gate['checks'])): ?><ul class="quality-gate-list"><?php foreach ($quality_gate['checks'] as $check): ?><li><span class="quality-dot quality-dot--<?php echo htmlspecialchars($check['severity']); ?>"></span><?php echo htmlspecialchars($check['detail']); ?></li><?php endforeach; ?></ul><?php else: ?><p class="quality-gate-pass">✓ No deterministic exceptions found.</p><?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2><?php echo htmlspecialchars($course['course_title']); ?></h2>
            <small class="text-muted"><?php echo htmlspecialchars($course['course_code']); ?> · Faculty: <?php echo htmlspecialchars($course['faculty_name']); ?> · Program: <?php echo htmlspecialchars($course['program_name'] ?? '—'); ?></small>
        </div>
        <span class="status-badge status-<?php echo $course['status']; ?>"><?php echo ucwords(str_replace('_', ' ', $course['status'])); ?></span>
    </div>
    <table>
        <tr><td>Due Date</td><td><?php echo htmlspecialchars($course['due_date'] ?? '—'); ?></td></tr>
        <tr><td>Submitted At</td><td><?php echo htmlspecialchars($course['submitted_at'] ?? '—'); ?></td></tr>
        <tr><td>Deadline Status</td><td><?php echo htmlspecialchars(str_replace('_', ' ', $course['deadline_status'] ?? 'not_due')); ?></td></tr>
        <tr><td>Level</td><td><?php echo htmlspecialchars($course['course_level']); ?></td></tr>
        <tr><td>Credit Hours</td><td><?php echo htmlspecialchars($course['credit_hours']); ?></td></tr>
    </table>
</div>

<div class="card">
    <div class="card-header"><h2>CLOs and PLO Alignment</h2></div>
    <table>
        <thead><tr><th>Code</th><th>Description</th><th>Domain</th><th>PLOs</th><th>Teaching Strategy</th><th>Assessment</th></tr></thead>
        <tbody>
        <?php foreach ($clos as $clo): ?>
            <tr><td><?php echo htmlspecialchars($clo['clo_code']); ?></td><td><?php echo htmlspecialchars($clo['description']); ?></td><td><?php echo htmlspecialchars($clo['category']); ?></td><td><?php echo $clo['plo_codes'] ? htmlspecialchars($clo['plo_codes']) : 'Not mapped'; ?></td><td><?php echo htmlspecialchars($clo['teaching_strategies']); ?></td><td><?php echo htmlspecialchars($clo['assessment_methods']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <div class="card-header"><h2>Assessment Plan</h2></div>
    <table>
        <thead><tr><th>Activity</th><th>Week</th><th>Weight (%)</th></tr></thead>
        <tbody>
        <?php $total = 0; foreach ($assessments as $a): $total += $a['percentage']; ?>
            <tr><td><?php echo htmlspecialchars($a['activity_name']); ?></td><td><?php echo htmlspecialchars($a['timing_week']); ?></td><td><?php echo htmlspecialchars($a['percentage']); ?>%</td></tr>
        <?php endforeach; ?>
        <tr><td colspan="2"><strong>Total</strong></td><td><strong><?php echo $total; ?>%</strong></td></tr>
        </tbody>
    </table>
</div>

<div class="card">
    <div class="card-header"><h2>QA Decision</h2></div>
    <?php if ($course['status'] === 'pending_qa'): ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(aqmsCsrfToken()); ?>">
        <div class="form-group"><label for="comment">Comment</label><textarea id="comment" name="comment" rows="5" placeholder="Optional for approval. Required if returning to faculty."></textarea></div>
        <button type="submit" name="action" value="approve" class="btn btn-success" <?php echo !empty($validation_errors) ? 'disabled' : ''; ?>>Final Approve</button>
        <button type="submit" name="action" value="reject" class="btn btn-danger">Return to Faculty</button>
    </form>
    <?php elseif ($course['status'] === 'approved'): ?>
        <p class="text-muted">This course specification has been fully approved.</p>
    <?php else: ?>
        <p class="text-muted">No QA action required for this status.</p>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2>Activity Log</h2></div>
    <?php if (empty($logs)): ?><p class="text-muted">No activity yet.</p><?php else: ?>
    <ul class="timeline">
    <?php foreach ($logs as $log): ?>
        <li><div class="timeline-date"><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?> · <span class="role-badge role-<?php echo $log['role']; ?>"><?php echo strtoupper($log['role']); ?></span></div><div class="timeline-text"><?php echo htmlspecialchars(str_replace('_', ' ', $log['from_status'] ?: 'Created')); ?> → <?php echo htmlspecialchars(str_replace('_', ' ', $log['to_status'])); ?></div><?php if ($log['comment']): ?><div class="timeline-comment">"<?php echo htmlspecialchars($log['comment']); ?>"</div><?php endif; ?></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<div style="display:flex; gap:10px; margin-bottom:20px;">
    <a href="<?php echo BASE_URL; ?>/course/view.php?id=<?php echo $course_id; ?>" class="btn btn-ghost" target="_blank">View Full Spec</a>
    <a href="dashboard.php" class="btn btn-ghost">Back</a>
</div>

<?php include '../includes/footer.php'; ?>

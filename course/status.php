<?php
require_once '../includes/auth_check.php';
require_once '../db.php';

$course_id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT cs.*, u.full_name as faculty_name FROM course_specs cs JOIN user u ON cs.faculty_id = u.user_id WHERE cs.course_id = ?');
$stmt->execute([$course_id]);
$course = $stmt->fetch();

if (!$course) {
    header('Location: ../index.php');
    exit();
}

$logs = $pdo->prepare('SELECT al.*, u.full_name, u.role FROM approval_log al JOIN user u ON al.user_id = u.user_id WHERE al.course_id = ? ORDER BY al.created_at ASC');
$logs->execute([$course_id]);
$logs = $logs->fetchAll();

$page_title = 'Status: ' . $course['course_title'];
include '../includes/header.php';
?>

<div class="card" style="max-width:850px;">
    <div class="card-header">
        <div>
            <h2><?php echo htmlspecialchars($course['course_title']); ?></h2>
            <small style="color:var(--text-muted);">
                <?php echo htmlspecialchars($course['course_code']); ?> - Faculty: <?php echo htmlspecialchars($course['faculty_name']); ?>
            </small>
        </div>
        <span class="status-badge status-<?php echo $course['status']; ?>" style="font-size:13px; padding:5px 14px;">
            <?php echo ucwords(str_replace('_', ' ', $course['status'])); ?>
        </span>
    </div>

    <table style="margin-bottom:18px;">
        <tr><td style="width:190px; color:var(--text-muted);">Current Status</td><td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $course['status']))); ?></td></tr>
        <tr><td style="color:var(--text-muted);">Due Date</td><td><?php echo htmlspecialchars($course['due_date'] ?? 'Not set'); ?></td></tr>
        <tr><td style="color:var(--text-muted);">Submitted At</td><td><?php echo htmlspecialchars($course['submitted_at'] ?? 'Not submitted'); ?></td></tr>
        <tr><td style="color:var(--text-muted);">Deadline Status</td><td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $course['deadline_status'] ?? 'not_due'))); ?></td></tr>
        <tr><td style="color:var(--text-muted);">Last Updated</td><td><?php echo htmlspecialchars($course['updated_at']); ?></td></tr>
    </table>

    <div class="card-header" style="padding-left:0;"><h2>Status History</h2></div>
    <?php if (empty($logs)): ?>
        <p style="color:var(--text-muted); padding:20px 0;">No activity recorded yet.</p>
    <?php else: ?>
        <ul class="timeline" style="margin-top:16px;">
            <?php foreach ($logs as $log): ?>
            <li>
                <div class="timeline-date">
                    <?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?>
                    - <?php echo htmlspecialchars($log['full_name']); ?>
                    <span class="role-badge role-<?php echo $log['role']; ?>" style="margin-left:6px;"><?php echo strtoupper($log['role']); ?></span>
                </div>
                <div class="timeline-text">
                    <?php echo htmlspecialchars($log['from_status'] ? ucwords(str_replace('_', ' ', $log['from_status'])) : 'Created'); ?>
                    to
                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['to_status']))); ?>
                </div>
                <?php if ($log['comment']): ?>
                    <div class="timeline-comment">"<?php echo htmlspecialchars($log['comment']); ?>"</div>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div style="margin-top:20px; display:flex; gap:10px;">
        <a href="../course/view.php?id=<?php echo $course_id; ?>" class="btn btn-outline btn-sm" target="_blank">View Full Spec</a>
        <a href="javascript:history.back()" class="btn btn-sm" style="background:#f3f4f6; color:var(--text);">Back</a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
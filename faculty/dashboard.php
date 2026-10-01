<?php
require_once '../includes/auth_check.php';
requireRole('faculty');
require_once '../db.php';

$page_title = 'My Course Specifications';

$stmt = $pdo->prepare('SELECT * FROM course_specs WHERE faculty_id = ? ORDER BY updated_at DESC');
$stmt->execute([$_SESSION['user_id']]);
$courses = $stmt->fetchAll();

$counts = ['draft' => 0, 'pending_hod' => 0, 'pending_qa' => 0, 'approved' => 0, 'returned_by_hod' => 0, 'returned_by_qa' => 0];
foreach ($courses as $c) {
    $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
}

// Recent feedback from HoD or QA when a specification is returned for revision.
$stmt2 = $pdo->prepare(
    'SELECT al.*, cs.course_title, cs.course_code, u.full_name as reviewer, u.role as reviewer_role
     FROM approval_log al
     JOIN course_specs cs ON al.course_id = cs.course_id
     JOIN user u ON al.user_id = u.user_id
     WHERE cs.faculty_id = ?
       AND al.comment IS NOT NULL AND al.comment != ""
       AND al.to_status IN ("returned_by_hod", "returned_by_qa")
     ORDER BY al.created_at DESC LIMIT 5'
);
$stmt2->execute([$_SESSION['user_id']]);
$feedback = $stmt2->fetchAll();

include '../includes/header.php';
?>

<div class="welcome-banner">
    <h2>Welcome back, <span class="accent"><?php echo htmlspecialchars($_SESSION['username']); ?></span></h2>
    <p>Manage your course specifications, map outcomes, and resolve quality-gate feedback before review.</p>
</div>

<div class="stats-row">
    <div class="stat-card">
        <div class="stat-num"><?php echo count($courses); ?></div>
        <div class="stat-label">Total Courses</div>
    </div>
    <div class="stat-card stat-card--grey">
        <div class="stat-num"><?php echo $counts['draft']; ?></div>
        <div class="stat-label">Drafts</div>
    </div>
    <div class="stat-card stat-card--warning">
        <div class="stat-num"><?php echo $counts['pending_hod']; ?></div>
        <div class="stat-label">Pending HoD</div>
    </div>
    <div class="stat-card stat-card--info">
        <div class="stat-num"><?php echo $counts['pending_qa']; ?></div>
        <div class="stat-label">Pending QA</div>
    </div>
    <div class="stat-card stat-card--success">
        <div class="stat-num"><?php echo $counts['approved']; ?></div>
        <div class="stat-label">Approved</div>
    </div>
</div>

<?php if (!empty($feedback)): ?>
<div class="card">
    <div class="card-header">
        <h2>Recent Feedback — Action Required</h2>
    </div>
    <?php foreach ($feedback as $f): ?>
    <div class="feedback-item">
        <div class="feedback-header">
            <strong><?php echo htmlspecialchars($f['course_code'] . ' — ' . $f['course_title']); ?></strong>
            <span class="role-badge role-<?php echo $f['reviewer_role']; ?>">
                <?php echo strtoupper($f['reviewer_role']); ?>
            </span>
            <span class="text-muted">· <?php echo htmlspecialchars($f['reviewer']); ?></span>
            <span class="text-muted">· <?php echo date('M d, Y', strtotime($f['created_at'])); ?></span>
        </div>
        <div class="feedback-comment">"<?php echo htmlspecialchars($f['comment']); ?>"</div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Course Specifications</h2>
        <a href="course_create.php" class="btn btn-primary btn-sm">+ New Course Specification</a>
    </div>

    <?php if (empty($courses)): ?>
        <p class="empty-state">
            You haven't created any course specifications yet.<br>
            Click <strong>+ New Course Specification</strong> to get started.
        </p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Level</th>
                    <th>Credits</th>
                    <th>Status</th>
                    <th>Quality Gate</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $course): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                    <td><?php echo htmlspecialchars($course['course_level'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($course['credit_hours'] ?: '—'); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $course['status']; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $course['status'])); ?>
                        </span>
                    </td>
                    <td><?php if (!empty($course['quality_gate_status']) && $course['quality_gate_status'] !== 'not_run'): ?><span class="status-badge status-<?php echo htmlspecialchars($course['quality_gate_status']); ?>"><?php echo htmlspecialchars(ucfirst($course['quality_gate_status'])); ?></span><?php else: ?><span class="text-muted">Not run</span><?php endif; ?></td>
                    <td><?php echo date('M d, Y', strtotime($course['updated_at'])); ?></td>
                    <td class="table-actions">
                        <?php if (in_array($course['status'], ['draft', 'returned_by_hod', 'returned_by_qa'], true)): ?>
                            <a href="course_edit.php?id=<?php echo $course['course_id']; ?>&step=1" class="btn btn-sm btn-primary">Edit</a>
                        <?php else: ?>
                            <a href="course_edit.php?id=<?php echo $course['course_id']; ?>&step=1" class="btn btn-sm btn-ghost">View</a>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>/course/status.php?id=<?php echo $course['course_id']; ?>" class="btn btn-sm btn-ghost">History</a>
                        <a href="<?php echo BASE_URL; ?>/course/view.php?id=<?php echo $course['course_id']; ?>" target="_blank" class="btn btn-sm btn-outline">Preview</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>

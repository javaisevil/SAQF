<?php
require_once '../includes/auth_check.php';
requireRole('hod');
require_once '../db.php';

$page_title = 'HoD Review Queue';

$filter = $_GET['status'] ?? 'pending_hod';
$allowed = ['all', 'draft', 'pending_hod', 'returned_by_hod', 'pending_qa', 'returned_by_qa', 'approved', 'archived'];
if (!in_array($filter, $allowed)) $filter = 'pending_hod';

if ($filter === 'all') {
    $courses = $pdo->query('SELECT cs.*, u.full_name as faculty_name FROM course_specs cs JOIN user u ON cs.faculty_id = u.user_id ORDER BY cs.updated_at DESC')->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT cs.*, u.full_name as faculty_name FROM course_specs cs JOIN user u ON cs.faculty_id = u.user_id WHERE cs.status = ? ORDER BY cs.updated_at DESC');
    $stmt->execute([$filter]);
    $courses = $stmt->fetchAll();
}

$counts_stmt = $pdo->query('SELECT status, COUNT(*) as cnt FROM course_specs GROUP BY status')->fetchAll();
$counts = ['draft' => 0, 'pending_hod' => 0, 'returned_by_hod' => 0, 'pending_qa' => 0, 'returned_by_qa' => 0, 'approved' => 0, 'archived' => 0];
foreach ($counts_stmt as $row) $counts[$row['status']] = (int)$row['cnt'];

include '../includes/header.php';
?>

<div class="welcome-banner">
    <h2><span class="accent">Head of Department</span> Dashboard</h2>
    <p>Review submitted course specifications. The quality gate blocks red errors, auto-clears green records after sign-off, and sends only amber exceptions to QA.</p>
    <div style="margin-top:12px;"><a href="course_assign.php" class="btn btn-primary btn-sm">Assign Course Specification</a></div>
</div>

<div class="stats-row">
    <a href="?status=all" class="stat-card <?php echo $filter === 'all' ? 'stat-active' : ''; ?>"><div class="stat-num"><?php echo array_sum($counts); ?></div><div class="stat-label">All Courses</div></a>
    <a href="?status=pending_hod" class="stat-card stat-card--warning <?php echo $filter === 'pending_hod' ? 'stat-active' : ''; ?>"><div class="stat-num"><?php echo $counts['pending_hod']; ?></div><div class="stat-label">Awaiting Review</div></a>
    <a href="?status=returned_by_hod" class="stat-card stat-card--grey <?php echo $filter === 'returned_by_hod' ? 'stat-active' : ''; ?>"><div class="stat-num"><?php echo $counts['returned_by_hod']; ?></div><div class="stat-label">Returned by HoD</div></a>
    <a href="?status=pending_qa" class="stat-card stat-card--info <?php echo $filter === 'pending_qa' ? 'stat-active' : ''; ?>"><div class="stat-num"><?php echo $counts['pending_qa']; ?></div><div class="stat-label">QA Exceptions</div></a>
    <a href="?status=approved" class="stat-card stat-card--success <?php echo $filter === 'approved' ? 'stat-active' : ''; ?>"><div class="stat-num"><?php echo $counts['approved']; ?></div><div class="stat-label">Approved</div></a>
</div>

<div class="card">
    <div class="card-header"><h2><?php echo $filter === 'all' ? 'All Submissions' : ucwords(str_replace('_', ' ', $filter)); ?> <span class="text-muted" style="font-size:13px; font-weight:400; margin-left:6px;">(<?php echo count($courses); ?>)</span></h2></div>

    <?php if (empty($courses)): ?>
        <p class="empty-state">No courses found for this filter.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Code</th><th>Title</th><th>Faculty</th><th>Due Date</th><th>Deadline</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($courses as $course): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                    <td><?php echo htmlspecialchars($course['faculty_name']); ?></td>
                    <td><?php echo htmlspecialchars($course['due_date'] ?? 'Not set'); ?></td>
                    <td><?php echo htmlspecialchars(str_replace('_', ' ', $course['deadline_status'] ?? 'not due')); ?></td>
                    <td><span class="status-badge status-<?php echo $course['status']; ?>"><?php echo ucwords(str_replace('_', ' ', $course['status'])); ?></span></td>
                    <td><?php echo date('M d, Y', strtotime($course['updated_at'])); ?></td>
                    <td class="table-actions">
                        <a href="course_review_clean.php?id=<?php echo $course['course_id']; ?>" class="btn btn-sm btn-primary">Review</a>
                        <a href="<?php echo BASE_URL; ?>/course/status.php?id=<?php echo $course['course_id']; ?>" class="btn btn-sm btn-ghost">History</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>

<?php
require_once '../includes/auth_check.php';
requireRole('dean');
require_once '../db.php';

$page_title = 'Department Dean Oversight';
$department = $_SESSION['department'] ?? '';

if ($department) {
    $stmt = $pdo->prepare('SELECT cs.*, u.full_name AS faculty_name, ps.program_name FROM course_specs cs LEFT JOIN user u ON cs.faculty_id = u.user_id LEFT JOIN program_specs ps ON cs.program_id = ps.program_id WHERE cs.department = ? OR u.department = ? ORDER BY cs.updated_at DESC');
    $stmt->execute([$department, $department]);
    $courses = $stmt->fetchAll();
} else {
    $courses = $pdo->query('SELECT cs.*, u.full_name AS faculty_name, ps.program_name FROM course_specs cs LEFT JOIN user u ON cs.faculty_id = u.user_id LEFT JOIN program_specs ps ON cs.program_id = ps.program_id ORDER BY cs.updated_at DESC')->fetchAll();
}

$counts = ['draft' => 0, 'pending_hod' => 0, 'returned_by_hod' => 0, 'pending_qa' => 0, 'returned_by_qa' => 0, 'approved' => 0, 'archived' => 0];
foreach ($courses as $course) {
    $counts[$course['status']] = ($counts[$course['status']] ?? 0) + 1;
}

include '../includes/header.php';
?>

<div class="welcome-banner">
    <h2><span class="accent">Department Dean</span> Oversight Dashboard</h2>
    <p>View course specifications, workflow status, and printable NCAAA outputs for department-level monitoring.</p>
</div>

<div class="stats-row">
    <div class="stat-card"><div class="stat-num"><?php echo count($courses); ?></div><div class="stat-label">Total Courses</div></div>
    <div class="stat-card stat-card--warning"><div class="stat-num"><?php echo $counts['pending_hod']; ?></div><div class="stat-label">Pending HoD</div></div>
    <div class="stat-card stat-card--info"><div class="stat-num"><?php echo $counts['pending_qa']; ?></div><div class="stat-label">Pending QA</div></div>
    <div class="stat-card stat-card--success"><div class="stat-num"><?php echo $counts['approved']; ?></div><div class="stat-label">Approved</div></div>
</div>

<div class="card">
    <div class="card-header"><h2>Department Course Specifications</h2></div>
    <?php if (empty($courses)): ?>
        <p class="empty-state">No courses found.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>Code</th><th>Title</th><th>Program</th><th>Faculty</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($courses as $course): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                <td><?php echo htmlspecialchars($course['program_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($course['faculty_name'] ?? ''); ?></td>
                <td><span class="status-badge status-<?php echo $course['status']; ?>"><?php echo ucwords(str_replace('_', ' ', $course['status'])); ?></span></td>
                <td><?php echo htmlspecialchars(date('M d, Y', strtotime($course['updated_at']))); ?></td>
                <td class="table-actions">
                    <a href="<?php echo BASE_URL; ?>/course/view.php?id=<?php echo $course['course_id']; ?>" target="_blank" class="btn btn-sm btn-outline">Print View</a>
                    <a href="<?php echo BASE_URL; ?>/course/status.php?id=<?php echo $course['course_id']; ?>" class="btn btn-sm btn-ghost">History</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>

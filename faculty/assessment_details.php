<?php
require_once '../includes/auth_check.php';
requireRole('faculty');
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/course_validation.php';

$course_id = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM course_specs WHERE course_id = ? AND faculty_id = ?');
$stmt->execute([$course_id, $_SESSION['user_id']]);
$course = $stmt->fetch();
if (!$course) { header('Location: dashboard.php'); exit(); }

$editable = in_array($course['status'], ['draft', 'returned_by_hod', 'returned_by_qa'], true);
$page_title = 'Assessment Details: ' . $course['course_title'];
$msg = '';
$err = '';
$hasRubric = aqmsColumnExists($pdo, 'assessments', 'rubric');
$hasTask = aqmsColumnExists($pdo, 'assessments', 'performance_task');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $editable && !aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $err = 'The form expired. Refresh the page and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $editable && aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    if (!$hasRubric || !$hasTask) {
        $err = 'Run database/quality_gate_migration.sql before editing rubric details.';
    } else {
        foreach (($_POST['assessment'] ?? []) as $id => $row) {
            $pdo->prepare('UPDATE assessments SET rubric = ?, performance_task = ? WHERE id = ? AND course_id = ?')
                ->execute([trim($row['rubric'] ?? ''), trim($row['performance_task'] ?? ''), intval($id), $course_id]);
        }
        $msg = 'Assessment rubrics and performance tasks saved.';
    }
}

$fields = 'a.id, a.activity_name, a.timing_week, a.percentage';
if ($hasRubric) $fields .= ', a.rubric';
if ($hasTask) $fields .= ', a.performance_task';

$q = $pdo->prepare("SELECT $fields, GROUP_CONCAT(cl.clo_code ORDER BY cl.clo_code SEPARATOR ', ') AS clo_codes FROM assessments a LEFT JOIN assessment_clo ac ON a.id = ac.assessment_id LEFT JOIN course_learning_outcomes cl ON ac.clo_id = cl.clo_id WHERE a.course_id = ? GROUP BY a.id ORDER BY a.timing_week, a.id");
$q->execute([$course_id]);
$assessments = $q->fetchAll();

include '../includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
<?php if (!$hasRubric || !$hasTask): ?><div class="alert alert-warning">Database update required: run <code>database/quality_gate_migration.sql</code> before editing rubric details.</div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Assessment Rubrics and Performance Tasks</h2>
        <a href="course_edit.php?id=<?php echo $course_id; ?>&step=4" class="btn btn-outline btn-sm">Back to Step 4</a>
    </div>

    <?php if (empty($assessments)): ?>
        <p class="empty-state">Save assessment activities in Step 4 first.</p>
    <?php else: ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(aqmsCsrfToken()); ?>">
        <fieldset <?php echo (!$editable || !$hasRubric || !$hasTask) ? 'disabled' : ''; ?> style="border:0;padding:0;margin:0;">
        <?php foreach ($assessments as $a): ?>
            <div class="card" style="box-shadow:none;border:1px solid var(--border);margin-bottom:16px;">
                <h3><?php echo htmlspecialchars($a['activity_name']); ?></h3>
                <p class="text-muted">Week: <?php echo htmlspecialchars($a['timing_week'] ?? ''); ?> | Weight: <?php echo htmlspecialchars($a['percentage'] ?? ''); ?>% | CLOs: <?php echo htmlspecialchars($a['clo_codes'] ?: 'None'); ?></p>
                <div class="form-group"><label>Rubric / Criteria</label><textarea name="assessment[<?php echo $a['id']; ?>][rubric]" rows="4"><?php echo htmlspecialchars($a['rubric'] ?? ''); ?></textarea></div>
                <div class="form-group"><label>Performance Task</label><textarea name="assessment[<?php echo $a['id']; ?>][performance_task]" rows="3"><?php echo htmlspecialchars($a['performance_task'] ?? ''); ?></textarea></div>
            </div>
        <?php endforeach; ?>
        <?php if ($editable && $hasRubric && $hasTask): ?><button type="submit" class="btn btn-primary">Save Details</button><?php endif; ?>
        </fieldset>
    </form>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>

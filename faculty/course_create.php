<?php
require_once '../includes/auth_check.php';
requireRole('faculty');
require_once '../db.php';
require_once '../includes/security.php';

$page_title = 'New Course Specification';

$yu_courses = require __DIR__ . '/../includes/yu_courses.php';
$yu_academics = require __DIR__ . '/../includes/yu_academics.php';
$institution = $yu_academics['institution'];
$programs = $pdo->query('SELECT * FROM program_specs ORDER BY college, qualification_level, program_name')->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $error = 'The form expired. Refresh the page and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $title      = trim($_POST['course_title'] ?? '');
    $code       = trim($_POST['course_code'] ?? '');
    $program_id = intval($_POST['program_id'] ?? 0);
    $level      = intval($_POST['course_level'] ?? 0);
    $credits    = floatval($_POST['credit_hours'] ?? 0);
    $required   = $_POST['required_elective'] ?? null;
    $types      = $_POST['course_type'] ?? [];
    $course_type = is_array($types) ? implode(', ', $types) : trim($types);

    $program = null;
    if ($program_id) {
        $p = $pdo->prepare('SELECT * FROM program_specs WHERE program_id = ?');
        $p->execute([$program_id]);
        $program = $p->fetch();
    }

    if (!$title || !$code || !$program) {
        $error = 'Course title, code, and program are required.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO course_specs (program_id, faculty_id, course_title, course_code, department, college, institution, course_level, credit_hours, course_type, required_elective, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")'
        );
        $stmt->execute([
            $program_id,
            $_SESSION['user_id'],
            $title,
            $code,
            $program['department'] ?? '',
            $program['college'] ?? '',
            $institution,
            $level ?: null,
            $credits ?: null,
            $course_type ?: null,
            $required
        ]);
        $new_id = $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO approval_log (course_id, user_id, from_status, to_status, comment) VALUES (?, ?, NULL, "draft", "Course specification created")'
        )->execute([$new_id, $_SESSION['user_id']]);

        header('Location: course_edit.php?id=' . $new_id . '&step=1');
        exit();
    }
}

include '../includes/header.php';
?>

<div style="max-width:760px;">
    <div class="card">
        <div class="card-header"><h2>Start a New Course Specification</h2></div>

        <p class="text-muted" style="margin-bottom:20px;">
            Enter the official course identification details first. College, department, and institution are filled from the selected YU program.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(aqmsCsrfToken()); ?>">
            <div class="form-group">
                <label>YU study plan course</label>
                <select id="yu_course_select" onchange="fillYuCourse(this)">
                    <option value="">-- Select course --</option>
                    <?php foreach ($yu_courses as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['code']); ?>"
                                data-code="<?php echo htmlspecialchars($c['code']); ?>"
                                data-title="<?php echo htmlspecialchars($c['title']); ?>"
                                data-level="<?php echo htmlspecialchars($c['level']); ?>"
                                data-credits="<?php echo htmlspecialchars($c['credits']); ?>">
                            <?php echo htmlspecialchars($c['code'] . ' - ' . $c['title'] . ' (' . $c['credits'] . ' cr)'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Course Title *</label>
                    <input type="text" id="course_title" name="course_title" required value="<?php echo htmlspecialchars($_POST['course_title'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Course Code *</label>
                    <input type="text" id="course_code" name="course_code" required value="<?php echo htmlspecialchars($_POST['course_code'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Program</label>
                    <select name="program_id" id="program_id" required onchange="fillProgramInfo(this)">
                        <option value="">-- Select program --</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?php echo $p['program_id']; ?>"
                                    data-college="<?php echo htmlspecialchars($p['college'] ?? ''); ?>"
                                    data-department="<?php echo htmlspecialchars($p['department'] ?? ''); ?>"
                                    data-institution="<?php echo htmlspecialchars($institution); ?>"
                                <?php echo (($_POST['program_id'] ?? '') == $p['program_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($p['program_code'] ? $p['program_code'] . ' - ' : '') . $p['program_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Institution</label>
                    <input type="text" id="institution" value="<?php echo htmlspecialchars($institution); ?>" readonly>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group"><label>College</label><input type="text" id="college" readonly></div>
                <div class="form-group"><label>Department</label><input type="text" id="department" readonly></div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Level/year at which this course is offered</label>
                    <input type="number" id="course_level" name="course_level" min="1" max="8" value="<?php echo htmlspecialchars($_POST['course_level'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Credit hours</label>
                    <input type="number" id="credit_hours" name="credit_hours" step="0.5" min="0" value="<?php echo htmlspecialchars($_POST['credit_hours'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Course type - A</label>
                <?php foreach (['University','College','Department','Track','Others'] as $type): ?>
                    <label style="display:inline-flex;gap:5px;margin-right:14px;font-weight:400;"><input type="checkbox" name="course_type[]" value="<?php echo $type; ?>"> <?php echo $type; ?></label>
                <?php endforeach; ?>
            </div>

            <div class="form-group">
                <label>Course type - B</label>
                <?php foreach (['Required','Elective'] as $type): ?>
                    <label style="display:inline-flex;gap:5px;margin-right:14px;font-weight:400;"><input type="radio" name="required_elective" value="<?php echo $type; ?>"> <?php echo $type; ?></label>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; gap:10px; margin-top:18px;">
                <button type="submit" class="btn btn-primary">Create & Continue</button>
                <a href="dashboard.php" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function fillYuCourse(select) {
    const item = select.options[select.selectedIndex];
    if (!item || !item.dataset.code) return;
    document.getElementById('course_title').value = item.dataset.title;
    document.getElementById('course_code').value = item.dataset.code;
    document.getElementById('course_level').value = item.dataset.level;
    document.getElementById('credit_hours').value = item.dataset.credits;
}
function fillProgramInfo(select) {
    const item = select.options[select.selectedIndex];
    document.getElementById('college').value = item.dataset.college || '';
    document.getElementById('department').value = item.dataset.department || '';
    document.getElementById('institution').value = item.dataset.institution || 'Al Yamamah University';
}
document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('program_id');
    if (select) fillProgramInfo(select);
});
</script>

<?php include '../includes/footer.php'; ?>

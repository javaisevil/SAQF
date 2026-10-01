<?php
require_once '../includes/auth_check.php';
requireRole('faculty');
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/quality_gate.php';

$yu_courses = require __DIR__ . '/../includes/yu_courses.php';
$yu_academics = require __DIR__ . '/../includes/yu_academics.php';
$institution = $yu_academics['institution'];

$course_id = intval($_GET['id'] ?? 0);
$step = intval($_GET['step'] ?? 1);
if ($step < 1 || $step > 8) $step = 1;

$stmt = $pdo->prepare('SELECT * FROM course_specs WHERE course_id = ? AND faculty_id = ?');
$stmt->execute([$course_id, $_SESSION['user_id']]);
$course = $stmt->fetch();

if (!$course) {
    header('Location: dashboard.php');
    exit();
}

$editable_statuses = ['draft', 'returned_by_hod', 'returned_by_qa'];
$readonly = !in_array($course['status'], $editable_statuses, true);
$page_title = ($readonly ? 'View' : 'Edit') . ': ' . $course['course_title'];
$msg = '';
$err = '';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function checkedInList($list, $value) {
    $items = array_map('trim', explode(',', (string)$list));
    return in_array($value, $items, true) ? 'checked' : '';
}

function optionSelected($saved, $value) {
    return (string)$saved === (string)$value ? 'selected' : '';
}

function valueOrOther($saved, $allowed) {
    $saved = (string)($saved ?? '');
    if ($saved === '') return ['', ''];
    return in_array($saved, $allowed, true) ? [$saved, ''] : ['Other', $saved];
}

$programs = $pdo->query('SELECT * FROM program_specs ORDER BY college, qualification_level, program_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readonly && !aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $err = 'The form expired. Refresh the page and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readonly && aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    if ($step === 1) {
        $program_id = intval($_POST['program_id'] ?? $course['program_id']);
        $p = $pdo->prepare('SELECT * FROM program_specs WHERE program_id = ?');
        $p->execute([$program_id]);
        $program = $p->fetch();

        $course_types = $_POST['course_type'] ?? [];
        $course_type = is_array($course_types) ? implode(', ', $course_types) : trim($course_types);

        $pdo->prepare('UPDATE course_specs SET program_id=?, course_title=?, course_code=?, department=?, college=?, institution=?, version=?, last_revision_date=?, credit_hours=?, course_type=?, required_elective=?, course_level=?, prerequisites=?, corequisites=?, course_description=?, objectives=? WHERE course_id=?')
            ->execute([
                $program_id ?: null,
                trim($_POST['course_title'] ?? ''),
                trim($_POST['course_code'] ?? ''),
                $program['department'] ?? '',
                $program['college'] ?? '',
                $institution,
                trim($_POST['version'] ?? '1.0'),
                $_POST['last_revision_date'] ?: null,
                floatval($_POST['credit_hours'] ?? 0) ?: null,
                $course_type,
                $_POST['required_elective'] ?? null,
                intval($_POST['course_level'] ?? 0) ?: null,
                trim($_POST['prerequisites'] ?? ''),
                trim($_POST['corequisites'] ?? ''),
                trim($_POST['course_description'] ?? ''),
                trim($_POST['objectives'] ?? ''),
                $course_id
            ]);

        $pdo->prepare('DELETE FROM teaching_modes WHERE course_id = ?')->execute([$course_id]);
        $pdo->prepare('DELETE FROM contact_hours WHERE course_id = ?')->execute([$course_id]);

        foreach (($_POST['mode'] ?? []) as $row) {
            if (empty($row['selected'])) continue;
            $pdo->prepare('INSERT INTO teaching_modes (course_id, mode_type, contact_hours, percentage) VALUES (?, ?, ?, ?)')
                ->execute([$course_id, $row['mode_type'], floatval($row['contact_hours'] ?? 0) ?: null, floatval($row['percentage'] ?? 0) ?: null]);
        }

        foreach (($_POST['hours'] ?? []) as $row) {
            $pdo->prepare('INSERT INTO contact_hours (course_id, activity_type, hours) VALUES (?, ?, ?)')
                ->execute([$course_id, $row['activity_type'], floatval($row['hours'] ?? 0) ?: null]);
        }

        $msg = 'General information saved.';
    } elseif ($step === 2) {
        $pdo->prepare('DELETE FROM clo_plo_mapping WHERE clo_id IN (SELECT clo_id FROM course_learning_outcomes WHERE course_id = ?)')->execute([$course_id]);
        $pdo->prepare('DELETE FROM jahiziah_skills WHERE course_id = ?')->execute([$course_id]);
        $pdo->prepare('DELETE FROM course_learning_outcomes WHERE course_id = ?')->execute([$course_id]);

        foreach (($_POST['clo'] ?? []) as $row) {
            $desc = trim($row['description'] ?? '');
            if (!$desc) continue;
            $ins = $pdo->prepare('INSERT INTO course_learning_outcomes (course_id, clo_code, description, category, teaching_strategies, assessment_methods) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute([
                $course_id,
                trim($row['code'] ?? ''),
                $desc,
                $row['category'] ?? 'Knowledge and Understanding',
                trim($row['teaching_strategies'] ?? ''),
                trim($row['assessment_methods'] ?? '')
            ]);
            $clo_id = $pdo->lastInsertId();

            foreach (($row['jahiziah'] ?? []) as $skill) {
                $pdo->prepare('INSERT INTO jahiziah_skills (course_id, clo_id, skill_type) VALUES (?, ?, ?)')->execute([$course_id, $clo_id, $skill]);
            }
            foreach (($row['plos'] ?? []) as $plo_id) {
                $pdo->prepare('INSERT IGNORE INTO clo_plo_mapping (clo_id, plo_id) VALUES (?, ?)')->execute([$clo_id, intval($plo_id)]);
            }
        }
        $msg = 'CLOs and mappings saved.';
    } elseif ($step === 3) {
        $pdo->prepare('DELETE FROM course_topics WHERE course_id = ?')->execute([$course_id]);
        $order = 0;
        foreach (($_POST['topic'] ?? []) as $row) {
            $txt = trim($row['topic_text'] ?? '');
            if (!$txt) continue;
            $pdo->prepare('INSERT INTO course_topics (course_id, topic_text, contact_hours, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$course_id, $txt, floatval($row['contact_hours'] ?? 0) ?: null, $order++]);
        }
        $msg = 'Course content saved.';
    } elseif ($step === 4) {
        $pdo->prepare('DELETE FROM assessment_clo WHERE assessment_id IN (SELECT id FROM assessments WHERE course_id = ?)')->execute([$course_id]);
        $pdo->prepare('DELETE FROM assessments WHERE course_id = ?')->execute([$course_id]);

        foreach (($_POST['assessment'] ?? []) as $row) {
            $choice = trim($row['activity_choice'] ?? '');
            $name = $choice === 'Other' ? trim($row['activity_other'] ?? '') : $choice;
            if (!$name) continue;
            $week = intval($row['timing_week'] ?? 0) ?: null;
            $percentage = floatval($row['percentage'] ?? 0) ?: null;
            $ins = $pdo->prepare(
                'INSERT INTO assessments
                 (course_id, activity_name, timing_week, percentage, assessment_timing,
                  proportion_of_total, rubric, performance_task)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $course_id,
                $name,
                $week,
                $percentage,
                $week ? 'Week ' . $week : 'Not specified',
                $percentage,
                'Rubric criteria for ' . $name,
                'Performance task for ' . $name
            ]);
            $a_id = $pdo->lastInsertId();
            foreach (($row['clos'] ?? []) as $clo_id) {
                $pdo->prepare('INSERT INTO assessment_clo (assessment_id, clo_id) VALUES (?, ?)')->execute([$a_id, intval($clo_id)]);
            }
        }
        $msg = 'Assessment activities saved.';
    } elseif ($step === 5) {
        $pdo->prepare('DELETE FROM resources WHERE course_id = ?')->execute([$course_id]);
        $pdo->prepare('DELETE FROM course_facilities WHERE course_id = ?')->execute([$course_id]);

        foreach (($_POST['resource'] ?? []) as $row) {
            $txt = trim($row['resource_text'] ?? '');
            if (!$txt) continue;
            $pdo->prepare('INSERT INTO resources (course_id, category, resource_text) VALUES (?, ?, ?)')->execute([$course_id, $row['category'], $txt]);
        }
        foreach (($_POST['facility'] ?? []) as $row) {
            $choice = trim($row['resources_choice'] ?? '');
            $resources = $choice === 'Other' ? trim($row['resources_other'] ?? '') : $choice;
            $pdo->prepare('INSERT INTO course_facilities (course_id, item, resources) VALUES (?, ?, ?)')->execute([$course_id, $row['item'], $resources]);
        }
        $msg = 'Learning resources and facilities saved.';
    } elseif ($step === 6) {
        $pdo->prepare('DELETE FROM course_quality WHERE course_id = ?')->execute([$course_id]);
        $pdo->prepare('DELETE FROM course_approval WHERE course_id = ?')->execute([$course_id]);

        foreach (($_POST['quality'] ?? []) as $row) {
            $assessor_choice = trim($row['assessor_choice'] ?? '');
            $assessor = $assessor_choice === 'Other' ? trim($row['assessor_other'] ?? '') : $assessor_choice;
            $method = trim($row['assessment_method'] ?? '');
            $pdo->prepare('INSERT INTO course_quality (course_id, assessment_area, assessor, assessment_method) VALUES (?, ?, ?, ?)')
                ->execute([$course_id, $row['assessment_area'], $assessor, $method]);
        }
        $pdo->prepare('INSERT INTO course_approval (course_id, council_committee, reference_no, approval_date) VALUES (?, ?, ?, ?)')
            ->execute([$course_id, trim($_POST['council_committee'] ?? ''), trim($_POST['reference_no'] ?? ''), $_POST['approval_date'] ?: null]);
        $msg = 'Quality assessment and approval data saved.';
    } elseif ($step === 7) {
        $pdo->prepare('DELETE FROM course_pdca WHERE course_id = ?')->execute([$course_id]);
        foreach (($_POST['pdca'] ?? []) as $row) {
            $content = trim($row['content'] ?? '');
            if (!$content) continue;
            $pdo->prepare('INSERT INTO course_pdca (course_id, phase, content, created_at) VALUES (?, ?, ?, NOW())')->execute([$course_id, $row['phase'], $content]);
        }
        $msg = 'PDCA saved.';
    }

    if (isset($_POST['next'])) {
        header('Location: course_edit.php?id=' . $course_id . '&step=' . min($step + 1, 8));
        exit();
    }
    $stmt->execute([$course_id, $_SESSION['user_id']]);
    $course = $stmt->fetch();
}

$clos_stmt = $pdo->prepare('SELECT * FROM course_learning_outcomes WHERE course_id = ? ORDER BY category, clo_code');
$clos_stmt->execute([$course_id]);
$clos = $clos_stmt->fetchAll();

$clo_ids = array_column($clos, 'clo_id');
$clo_maps = [];
if ($clo_ids) {
    $in = implode(',', array_fill(0, count($clo_ids), '?'));
    $map = $pdo->prepare("SELECT * FROM clo_plo_mapping WHERE clo_id IN ($in)");
    $map->execute($clo_ids);
    foreach ($map->fetchAll() as $row) $clo_maps[$row['clo_id']][$row['plo_id']] = true;
}

$plos = [];
if ($course['program_id']) {
    $ps = $pdo->prepare('SELECT * FROM program_learning_outcomes WHERE program_id = ? ORDER BY category, plo_code');
    $ps->execute([$course['program_id']]);
    $plos = $ps->fetchAll();
}

$assessments = $pdo->prepare('SELECT a.*, GROUP_CONCAT(ac.clo_id) as clo_ids FROM assessments a LEFT JOIN assessment_clo ac ON a.id = ac.assessment_id WHERE a.course_id = ? GROUP BY a.id');
$assessments->execute([$course_id]);
$assessments = $assessments->fetchAll();

$modes = $pdo->prepare('SELECT * FROM teaching_modes WHERE course_id = ?');
$modes->execute([$course_id]);
$modes = $modes->fetchAll();
$mode_data = [];
foreach ($modes as $m) $mode_data[$m['mode_type']] = $m;

$chours = $pdo->prepare('SELECT * FROM contact_hours WHERE course_id = ?');
$chours->execute([$course_id]);
$chours = $chours->fetchAll();
$hour_data = [];
foreach ($chours as $h) $hour_data[$h['activity_type']] = $h;

$topics = $pdo->prepare('SELECT * FROM course_topics WHERE course_id = ? ORDER BY sort_order');
$topics->execute([$course_id]);
$topics = $topics->fetchAll();

$res = $pdo->prepare('SELECT * FROM resources WHERE course_id = ? ORDER BY category');
$res->execute([$course_id]);
$res = $res->fetchAll();

$facilities = $pdo->prepare('SELECT * FROM course_facilities WHERE course_id = ?');
$facilities->execute([$course_id]);
$facilities = $facilities->fetchAll();
$facility_data = [];
foreach ($facilities as $f) $facility_data[$f['item']] = $f['resources'];

$qualities = $pdo->prepare('SELECT * FROM course_quality WHERE course_id = ?');
$qualities->execute([$course_id]);
$qualities = $qualities->fetchAll();
$quality_data = [];
foreach ($qualities as $q) $quality_data[$q['assessment_area']] = $q;

$approval = $pdo->prepare('SELECT * FROM course_approval WHERE course_id = ?');
$approval->execute([$course_id]);
$approval = $approval->fetch() ?: [];

$jahiziah = [];
$j = $pdo->prepare('SELECT clo_id, skill_type FROM jahiziah_skills WHERE course_id = ?');
$j->execute([$course_id]);
foreach ($j->fetchAll() as $row) $jahiziah[$row['clo_id']][] = $row['skill_type'];

$step_labels = [
    1 => 'A. General Information',
    2 => 'B. CLOs, Strategies & Assessment',
    3 => 'C. Course Content',
    4 => 'D. Student Assessment Activities',
    5 => 'E. Learning Resources & Facilities',
    6 => 'F/G. Quality & Approval',
    7 => 'Additional PDCA Log',
    8 => 'Review & Submit'
];

$assessment_options = ['Written test', 'Oral test', 'Oral presentation', 'Group project', 'Essay', 'Other'];
$facility_options = ['Classrooms', 'Laboratories', 'Exhibition rooms', 'Simulation rooms', 'Projector', 'Smart board', 'Software', 'Other'];
$assessor_options = ['Students', 'Faculty', 'Program Leaders', 'Peer Reviewers', 'Other'];
$method_options = ['Direct', 'Indirect'];

include '../includes/header.php';
?>


<style id="course-edit-inline-fixes">
.other-box {
    display: none;
    margin-top: 8px;
}
.other-box.show {
    display: block;
}
.clo-link-warning {
    display: block;
    color: #b45309;
    font-size: 12px;
    line-height: 1.4;
}
</style>

<?php if ($readonly): ?>
<div class="alert alert-info"><strong>Read-only mode.</strong> Current status: <?php echo h(str_replace('_', ' ', $course['status'])); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['submit_errors'])): ?>
<div class="alert alert-danger">
    <?php foreach ($_SESSION['submit_errors'] as $e): ?><div><?php echo h($e); ?></div><?php endforeach; unset($_SESSION['submit_errors']); ?>
</div>
<?php endif; ?>

<div class="card" style="padding:14px 22px;">
    <div class="tabs" style="margin-bottom:0;">
        <?php foreach ($step_labels as $i => $label): ?>
            <a href="course_edit.php?id=<?php echo $course_id; ?>&step=<?php echo $i; ?>" class="tab-link <?php echo $step === $i ? 'active' : ''; ?>"><?php echo $i . '. ' . $label; ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?php echo h($msg); ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?php echo h($err); ?></div><?php endif; ?>

<div class="card">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?php echo h(aqmsCsrfToken()); ?>">
<fieldset <?php echo $readonly ? 'disabled' : ''; ?> style="border:none; padding:0; margin:0;">

<?php if ($step === 1): ?>
<div class="card-header"><h2>A. General information about the course</h2></div>
<div class="form-group">
    <label>YU study plan course</label>
    <select id="yu_course_select" onchange="fillYuCourse(this)">
        <option value="">-- Select course --</option>
        <?php foreach ($yu_courses as $c): ?>
        <option value="<?php echo h($c['code']); ?>" data-code="<?php echo h($c['code']); ?>" data-title="<?php echo h($c['title']); ?>" data-level="<?php echo h($c['level']); ?>" data-credits="<?php echo h($c['credits']); ?>">
            <?php echo h($c['code'] . ' - ' . $c['title'] . ' (' . $c['credits'] . ' cr)'); ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>
<div class="form-row">
    <div class="form-group"><label>Course Title *</label><input type="text" id="course_title" name="course_title" required value="<?php echo h($course['course_title']); ?>"></div>
    <div class="form-group"><label>Course Code *</label><input type="text" id="course_code" name="course_code" required value="<?php echo h($course['course_code']); ?>"></div>
</div>
<div class="form-row">
    <div class="form-group"><label>Program</label><select name="program_id" id="program_id" onchange="fillProgramInfo(this)"><?php foreach ($programs as $p): ?><option value="<?php echo $p['program_id']; ?>" data-college="<?php echo h($p['college']); ?>" data-department="<?php echo h($p['department']); ?>" <?php echo ($course['program_id'] == $p['program_id']) ? 'selected' : ''; ?>><?php echo h(($p['program_code'] ? $p['program_code'] . ' - ' : '') . $p['program_name']); ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Institution</label><input type="text" value="<?php echo h($institution); ?>" readonly></div>
</div>
<div class="form-row">
    <div class="form-group"><label>Department</label><input type="text" id="department_display" value="<?php echo h($course['department'] ?? ''); ?>" readonly></div>
    <div class="form-group"><label>College</label><input type="text" id="college_display" value="<?php echo h($course['college'] ?? ''); ?>" readonly></div>
</div>
<div class="form-row">
    <div class="form-group"><label>Version</label><input type="text" name="version" value="<?php echo h($course['version']); ?>"></div>
    <div class="form-group"><label>Last Revision Date</label><input type="date" name="last_revision_date" value="<?php echo h($course['last_revision_date'] ?? ''); ?>"></div>
</div>
<div class="form-row">
    <div class="form-group"><label>Credit hours</label><input type="number" id="credit_hours" name="credit_hours" step="0.5" value="<?php echo h($course['credit_hours']); ?>"></div>
    <div class="form-group"><label>Level/year at which this course is offered</label><input type="number" id="course_level" name="course_level" min="1" max="8" value="<?php echo h($course['course_level']); ?>"></div>
</div>
<div class="form-group">
    <label>Course type - A</label>
    <?php foreach (['University','College','Department','Track','Others'] as $type): ?>
    <label style="display:inline-flex;gap:5px;margin-right:14px;font-weight:400;"><input type="checkbox" name="course_type[]" value="<?php echo $type; ?>" <?php echo checkedInList($course['course_type'], $type); ?>> <?php echo $type; ?></label>
    <?php endforeach; ?>
</div>
<div class="form-group">
    <label>Course type - B</label>
    <?php foreach (['Required','Elective'] as $type): ?>
    <label style="display:inline-flex;gap:5px;margin-right:14px;font-weight:400;"><input type="radio" name="required_elective" value="<?php echo $type; ?>" <?php echo (($course['required_elective'] ?? '') === $type) ? 'checked' : ''; ?>> <?php echo $type; ?></label>
    <?php endforeach; ?>
</div>
<div class="form-group"><label>Course General Description</label><textarea name="course_description" rows="4"><?php echo h($course['course_description']); ?></textarea></div>
<div class="form-row">
    <div class="form-group"><label>Pre-requirements for this course</label><input type="text" name="prerequisites" value="<?php echo h($course['prerequisites']); ?>"></div>
    <div class="form-group"><label>Co-requisites for this course</label><input type="text" name="corequisites" value="<?php echo h($course['corequisites']); ?>"></div>
</div>
<div class="form-group"><label>Course Main Objective(s)</label><textarea name="objectives" rows="3"><?php echo h($course['objectives']); ?></textarea></div>

<h3>2. Teaching mode</h3>
<table><thead><tr><th>No</th><th>Mode of Instruction</th><th>Contact Hours</th><th>Percentage</th></tr></thead><tbody>
<?php foreach (['Traditional classroom','E-learning','Hybrid','Distance learning'] as $i => $mode): $m = $mode_data[$mode] ?? []; ?>
<tr><td><?php echo $i + 1; ?></td><td><label style="font-weight:600;"><input type="checkbox" name="mode[<?php echo $i; ?>][selected]" value="1" <?php echo $m ? 'checked' : ''; ?>> <?php echo $mode; ?></label><?php if ($mode === 'Hybrid'): ?><br><small style="margin-left:24px;">Traditional classroom + E-learning</small><?php endif; ?><input type="hidden" name="mode[<?php echo $i; ?>][mode_type]" value="<?php echo $mode; ?>"></td><td><input type="number" name="mode[<?php echo $i; ?>][contact_hours]" step="0.5" value="<?php echo h($m['contact_hours'] ?? ''); ?>"></td><td><input type="number" name="mode[<?php echo $i; ?>][percentage]" step="0.5" value="<?php echo h($m['percentage'] ?? ''); ?>"></td></tr>
<?php endforeach; ?>
</tbody></table>

<h3>3. Contact Hours</h3>
<table><thead><tr><th>No</th><th>Activity</th><th>Contact Hours</th></tr></thead><tbody>
<?php foreach (['Lectures','Laboratory/Studio','Field','Tutorial','Others'] as $i => $activity): $hrow = $hour_data[$activity] ?? []; ?>
<tr><td><?php echo $i + 1; ?>.</td><td><strong><?php echo $activity; ?><?php echo $activity === 'Others' ? ' (specify)' : ''; ?></strong><input type="hidden" name="hours[<?php echo $i; ?>][activity_type]" value="<?php echo $activity; ?>"></td><td><input type="number" name="hours[<?php echo $i; ?>][hours]" step="0.5" value="<?php echo h($hrow['hours'] ?? ''); ?>"></td></tr>
<?php endforeach; ?>
<tr><td colspan="2"><strong>Total</strong></td><td><strong><?php echo h(array_sum(array_column($chours, 'hours'))); ?></strong></td></tr>
</tbody></table>
<script>
function fillYuCourse(select) {
    var item = select.options[select.selectedIndex];
    if (!item || !item.dataset.code) return;
    document.getElementById('course_title').value = item.dataset.title;
    document.getElementById('course_code').value = item.dataset.code;
    document.getElementById('course_level').value = item.dataset.level;
    document.getElementById('credit_hours').value = item.dataset.credits;
}
function fillProgramInfo(select) {
    var item = select.options[select.selectedIndex];
    document.getElementById('department_display').value = item.dataset.department || '';
    document.getElementById('college_display').value = item.dataset.college || '';
}
</script>

<?php elseif ($step === 2): ?>
<div class="card-header"><h2>B. Course Learning Outcomes, Teaching Strategies and Assessment Methods</h2><?php if (!$readonly): ?><button type="button" class="btn btn-outline btn-sm" onclick="addCloRow()">+ Add CLO</button><?php endif; ?></div>
<div style="overflow-x:auto;"><table class="matrix-table" id="clo-table"><thead><tr><th>Code</th><th>Course Learning Outcomes</th><th>Code of PLOs aligned with the program</th><th>Teaching Strategies</th><th>Assessment Methods</th><th>Jahiziah Skills</th><?php foreach ($plos as $plo): ?><th title="<?php echo h($plo['description']); ?>"><?php echo h($plo['plo_code']); ?></th><?php endforeach; ?><?php if (!$readonly): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php $rows = $clos ?: [[]]; foreach ($rows as $i => $clo): ?>
<tr>
<td><input type="text" name="clo[<?php echo $i; ?>][code]" value="<?php echo h($clo['clo_code'] ?? ''); ?>"></td>
<td><input type="text" name="clo[<?php echo $i; ?>][description]" value="<?php echo h($clo['description'] ?? ''); ?>"><select name="clo[<?php echo $i; ?>][category]" style="margin-top:6px;"><?php foreach (['Knowledge and Understanding','Skills','Values, Autonomy, and Responsibility'] as $cat): ?><option <?php echo (($clo['category'] ?? '') === $cat) ? 'selected' : ''; ?>><?php echo $cat; ?></option><?php endforeach; ?></select></td>
<td><?php foreach ($plos as $plo): ?><label style="display:inline-flex;gap:4px;margin-right:10px;font-weight:400;"><input type="checkbox" name="clo[<?php echo $i; ?>][plos][]" value="<?php echo $plo['plo_id']; ?>" <?php echo (!empty($clo['clo_id']) && isset($clo_maps[$clo['clo_id']][$plo['plo_id']])) ? 'checked' : ''; ?>><?php echo h($plo['plo_code']); ?></label><?php endforeach; ?></td>
<td><input type="text" name="clo[<?php echo $i; ?>][teaching_strategies]" value="<?php echo h($clo['teaching_strategies'] ?? ''); ?>"></td>
<td><input type="text" name="clo[<?php echo $i; ?>][assessment_methods]" value="<?php echo h($clo['assessment_methods'] ?? ''); ?>"></td>
<td><?php foreach (['Digital','Communication','Teamwork','Ethics'] as $skill): ?><label style="display:block;font-weight:400;"><input type="checkbox" name="clo[<?php echo $i; ?>][jahiziah][]" value="<?php echo $skill; ?>" <?php echo (!empty($clo['clo_id']) && !empty($jahiziah[$clo['clo_id']]) && in_array($skill, $jahiziah[$clo['clo_id']])) ? 'checked' : ''; ?>> <?php echo $skill; ?></label><?php endforeach; ?></td>
<?php if (!$readonly): ?><td><button type="button" class="icon-btn" onclick="this.closest('tr').remove()">✕</button></td><?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<script>
var cloCount = <?php echo max(count($clos), 1); ?>;
var ploList = <?php echo json_encode(array_map(fn($p) => ['id' => $p['plo_id'], 'code' => $p['plo_code']], $plos)); ?>;
function addCloRow(){
    var i = cloCount++;
    var ploBoxes = ploList.map(p => '<label style="display:inline-flex;gap:4px;margin-right:10px;font-weight:400;"><input type="checkbox" name="clo['+i+'][plos][]" value="'+p.id+'">'+p.code+'</label>').join('');
    var cats = ['Knowledge and Understanding','Skills','Values, Autonomy, and Responsibility'].map(c => '<option>'+c+'</option>').join('');
    var skills = ['Digital','Communication','Teamwork','Ethics'].map(s => '<label style="display:block;font-weight:400;"><input type="checkbox" name="clo['+i+'][jahiziah][]" value="'+s+'"> '+s+'</label>').join('');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="text" name="clo['+i+'][code]"></td><td><input type="text" name="clo['+i+'][description]"><select name="clo['+i+'][category]" style="margin-top:6px;">'+cats+'</select></td><td>'+ploBoxes+'</td><td><input type="text" name="clo['+i+'][teaching_strategies]"></td><td><input type="text" name="clo['+i+'][assessment_methods]"></td><td>'+skills+'</td><td><button type="button" class="icon-btn" onclick="this.closest(\'tr\').remove()">✕</button></td>';
    document.querySelector('#clo-table tbody').appendChild(tr);
}
</script>

<?php elseif ($step === 3): ?>
<div class="card-header"><h2>C. Course Content</h2><?php if (!$readonly): ?><button type="button" class="btn btn-outline btn-sm" onclick="addTopicRow()">+ Add Topic</button><?php endif; ?></div>
<table id="topic-table"><thead><tr><th>No</th><th>List of Topics</th><th>Contact Hours</th><?php if (!$readonly): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php $rows = $topics ?: [[]]; foreach ($rows as $i => $t): ?>
<tr><td><?php echo $i + 1; ?></td><td><input type="text" name="topic[<?php echo $i; ?>][topic_text]" value="<?php echo h($t['topic_text'] ?? ''); ?>"></td><td><input type="number" name="topic[<?php echo $i; ?>][contact_hours]" step="0.5" value="<?php echo h($t['contact_hours'] ?? ''); ?>"></td><?php if (!$readonly): ?><td><button type="button" class="icon-btn" onclick="this.closest('tr').remove()">✕</button></td><?php endif; ?></tr>
<?php endforeach; ?>
</tbody></table>
<script>
var topicCount = <?php echo max(count($topics), 1); ?>;
function addTopicRow(){var i=topicCount++;var tr=document.createElement('tr');tr.innerHTML='<td></td><td><input type="text" name="topic['+i+'][topic_text]"></td><td><input type="number" name="topic['+i+'][contact_hours]" step="0.5"></td><td><button type="button" class="icon-btn" onclick="this.closest(\'tr\').remove()">✕</button></td>';document.querySelector('#topic-table tbody').appendChild(tr);}
</script>

<?php elseif ($step === 4): ?>
<div class="card-header"><h2>D. Students Assessment Activities</h2><div style="display:flex;gap:8px;align-items:center;"><a href="assessment_details.php?id=<?php echo $course_id; ?>" class="btn btn-outline btn-sm">Rubrics &amp; Evidence</a><?php if (!$readonly): ?><button type="button" class="btn btn-outline btn-sm" onclick="addAssessRow()">+ Add Activity</button><?php endif; ?></div></div>
<table id="assess-table" class="official-table">
<thead><tr><th>No</th><th>Assessment Activities</th><th>Assessment timing<br>(in week no)</th><th>Percentage of Total Assessment Score</th><th>Linked CLOs</th><?php if (!$readonly): ?><th></th><?php endif; ?></tr></thead>
<tbody>
<?php $rows = $assessments ?: [[]]; foreach ($rows as $i => $a): $linked = !empty($a['clo_ids']) ? explode(',', $a['clo_ids']) : []; [$choice, $other] = valueOrOther($a['activity_name'] ?? '', $assessment_options); ?>
<tr>
<td><?php echo $i + 1; ?>.</td>
<td>
    <select name="assessment[<?php echo $i; ?>][activity_choice]" onchange="toggleOther(this)">
        <option value="">-- Select activity --</option>
        <?php foreach ($assessment_options as $opt): ?><option value="<?php echo h($opt); ?>" <?php echo optionSelected($choice, $opt); ?>><?php echo h($opt); ?></option><?php endforeach; ?>
    </select>
    <input type="text" name="assessment[<?php echo $i; ?>][activity_other]" value="<?php echo h($other); ?>" placeholder="Please specify" class="other-box <?php echo $choice === 'Other' ? 'show' : ''; ?>" style="<?php echo $choice === 'Other' ? 'display:block;' : 'display:none;'; ?>">
</td>
<td><input type="number" name="assessment[<?php echo $i; ?>][timing_week]" min="1" max="16" value="<?php echo h($a['timing_week'] ?? ''); ?>"></td>
<td><input type="number" name="assessment[<?php echo $i; ?>][percentage]" step="0.5" min="0" max="100" value="<?php echo h($a['percentage'] ?? ''); ?>"></td>
<td>
<?php if (empty($clos)): ?>
    <span class="clo-link-warning">Save Section B CLOs first, then return here to link assessment activities.</span>
<?php else: ?>
    <?php foreach ($clos as $clo): ?>
        <label class="checkbox-pill">
            <input type="checkbox" name="assessment[<?php echo $i; ?>][clos][]" value="<?php echo $clo['clo_id']; ?>" <?php echo in_array($clo['clo_id'], $linked) ? 'checked' : ''; ?>>
            <?php echo h($clo['clo_code'] ?: ('CLO ' . $clo['clo_id'])); ?>
        </label>
    <?php endforeach; ?>
<?php endif; ?>
</td>
<?php if (!$readonly): ?><td><button type="button" class="icon-btn" onclick="this.closest('tr').remove(); numberAssessRows();">✕</button></td><?php endif; ?>
</tr>
<?php endforeach; ?>
<tr class="assessment-total-row"><td colspan="3"><strong>Total</strong></td><td><strong id="assessment-total"><?php echo h(array_sum(array_column($assessments, 'percentage'))); ?>%</strong></td><td colspan="2"></td></tr>
</tbody></table>
<script>
var assessCount = <?php echo max(count($assessments), 1); ?>;
var closForAssess = <?php echo json_encode(array_map(fn($c) => ['id' => $c['clo_id'], 'code' => $c['clo_code']], $clos)); ?>;
var assessmentOptions = <?php echo json_encode($assessment_options); ?>;
function toggleOther(select){
    var box = select.parentElement.querySelector('.other-box');
    if (!box) return;
    if (select.value === 'Other') {
        box.classList.add('show');
        box.style.display = 'block';
    } else {
        box.classList.remove('show');
        box.style.display = 'none';
        box.value = '';
    }
}
function updateAssessmentTotal(){
    var total = 0;
    document.querySelectorAll('#assess-table input[name*="[percentage]"]').forEach(function(input){ total += parseFloat(input.value) || 0; });
    var target = document.getElementById('assessment-total');
    if (target) target.textContent = total + '%';
}
function numberAssessRows(){
    document.querySelectorAll('#assess-table tbody tr:not(.assessment-total-row)').forEach(function(row, index){ row.children[0].textContent = (index + 1) + '.'; });
    updateAssessmentTotal();
}
function addAssessRow(){
    var i = assessCount++;
    var opts = '<option value="">-- Select activity --</option>' + assessmentOptions.map(function(o){ return '<option value="'+o+'">'+o+'</option>'; }).join('');
    var cloBoxes = closForAssess.length
        ? closForAssess.map(function(c){ return '<label class="checkbox-pill"><input type="checkbox" name="assessment['+i+'][clos][]" value="'+c.id+'">'+(c.code || ('CLO '+c.id))+'</label>'; }).join('')
        : '<span class="clo-link-warning">Save Section B CLOs first, then return here to link assessment activities.</span>';
    var tr = document.createElement('tr');
    tr.innerHTML = '<td></td><td><select name="assessment['+i+'][activity_choice]" onchange="toggleOther(this)">'+opts+'</select><input type="text" name="assessment['+i+'][activity_other]" placeholder="Please specify" class="other-box" style="display:none;"></td><td><input type="number" name="assessment['+i+'][timing_week]" min="1" max="16"></td><td><input type="number" name="assessment['+i+'][percentage]" step="0.5" min="0" max="100" oninput="updateAssessmentTotal()"></td><td>'+cloBoxes+'</td><td><button type="button" class="icon-btn" onclick="this.closest(\'tr\').remove(); numberAssessRows();">✕</button></td>';
    document.querySelector('#assess-table .assessment-total-row').before(tr);
    numberAssessRows();
}
document.getElementById('assess-table').addEventListener('input', updateAssessmentTotal);
</script>

<?php elseif ($step === 5): ?>
<div class="card-header"><h2>E. Learning Resources and Facilities</h2></div>
<h3>1. References and Learning Resources</h3>
<table><thead><tr><th>Type</th><th>Resource</th></tr></thead><tbody>
<?php $res_rows = $res ?: array_map(fn($c) => ['category' => $c, 'resource_text' => ''], ['Essential References','Supportive References','Electronic Materials','Other Learning Materials']); foreach ($res_rows as $i => $r): ?>
<tr><td><select name="resource[<?php echo $i; ?>][category]"><?php foreach (['Essential References','Supportive References','Electronic Materials','Other Learning Materials'] as $cat): ?><option <?php echo (($r['category'] ?? '') === $cat) ? 'selected' : ''; ?>><?php echo $cat; ?></option><?php endforeach; ?></select></td><td><input type="text" name="resource[<?php echo $i; ?>][resource_text]" value="<?php echo h($r['resource_text'] ?? ''); ?>" placeholder="Enter reference or learning material"></td></tr>
<?php endforeach; ?>
</tbody></table>
<h3>2. Required Facilities and Equipment</h3>
<table><thead><tr><th>Items</th><th>Resources</th></tr></thead><tbody>
<?php $facility_notes = ['facilities' => '(Classrooms, laboratories, exhibition rooms, simulation rooms, etc.)', 'Technology equipment' => '(projector, smart board, software)', 'Other equipment' => '(depending on the nature of the specialty)']; foreach (['facilities','Technology equipment','Other equipment'] as $i => $item): [$choice, $other] = valueOrOther($facility_data[$item] ?? '', $facility_options); ?>
<tr>
<td><strong><?php echo h($item); ?></strong><br><small><?php echo h($facility_notes[$item]); ?></small><input type="hidden" name="facility[<?php echo $i; ?>][item]" value="<?php echo h($item); ?>"></td>
<td>
    <select name="facility[<?php echo $i; ?>][resources_choice]" onchange="toggleOther(this)">
        <option value="">-- Select resource --</option>
        <?php foreach ($facility_options as $opt): ?><option value="<?php echo h($opt); ?>" <?php echo optionSelected($choice, $opt); ?>><?php echo h($opt); ?></option><?php endforeach; ?>
    </select>
    <input type="text" name="facility[<?php echo $i; ?>][resources_other]" value="<?php echo h($other); ?>" placeholder="Please specify" class="other-box <?php echo $choice === 'Other' ? 'show' : ''; ?>" style="<?php echo $choice === 'Other' ? 'display:block;' : 'display:none;'; ?>">
</td>
</tr>
<?php endforeach; ?>
</tbody></table>

<?php elseif ($step === 6): ?>
<div class="card-header"><h2>F. Assessment of Course Quality + G. Specification Approval</h2></div>
<h3>F. Assessment of Course Quality</h3>
<table><thead><tr><th>Assessment Areas/Issues</th><th>Assessor</th><th>Assessment Methods</th></tr></thead><tbody>
<?php foreach (['Effectiveness of teaching','Effectiveness of Students assessment','Quality of learning resources','The extent to which CLOs have been achieved','Other'] as $i => $area): $q = $quality_data[$area] ?? []; [$assessor_choice, $assessor_other] = valueOrOther($q['assessor'] ?? '', $assessor_options); ?>
<tr>
<td><?php echo h($area); ?><input type="hidden" name="quality[<?php echo $i; ?>][assessment_area]" value="<?php echo h($area); ?>"></td>
<td>
    <select name="quality[<?php echo $i; ?>][assessor_choice]" onchange="toggleOther(this)">
        <option value="">-- Select assessor --</option>
        <?php foreach ($assessor_options as $opt): ?><option value="<?php echo h($opt); ?>" <?php echo optionSelected($assessor_choice, $opt); ?>><?php echo h($opt); ?></option><?php endforeach; ?>
    </select>
    <input type="text" name="quality[<?php echo $i; ?>][assessor_other]" value="<?php echo h($assessor_other); ?>" placeholder="Please specify" class="other-box <?php echo $assessor_choice === 'Other' ? 'show' : ''; ?>" style="<?php echo $assessor_choice === 'Other' ? 'display:block;' : 'display:none;'; ?>">
</td>
<td><select name="quality[<?php echo $i; ?>][assessment_method]"><option value="">-- Select method --</option><?php foreach ($method_options as $method): ?><option value="<?php echo h($method); ?>" <?php echo optionSelected($q['assessment_method'] ?? '', $method); ?>><?php echo h($method); ?></option><?php endforeach; ?></select></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<p class="form-note">Assessment areas are fixed from the template. Assessors are Students, Faculty, Program Leaders, Peer Reviewers, or Other. Methods are Direct or Indirect.</p>
<h3>G. Specification Approval</h3>
<div class="form-row"><div class="form-group"><label>COUNCIL /COMMITTEE</label><input type="text" name="council_committee" value="<?php echo h($approval['council_committee'] ?? ''); ?>"></div><div class="form-group"><label>REFERENCE NO.</label><input type="text" name="reference_no" value="<?php echo h($approval['reference_no'] ?? ''); ?>"></div><div class="form-group"><label>DATE</label><input type="date" name="approval_date" value="<?php echo h($approval['approval_date'] ?? ''); ?>"></div></div>

<?php elseif ($step === 7): ?>
<div class="card-header"><h2>Additional PDCA Quality Improvement Log</h2></div>
<?php $pdca_items = $pdo->prepare('SELECT * FROM course_pdca WHERE course_id = ? ORDER BY id ASC'); $pdca_items->execute([$course_id]); $pdca_items = $pdca_items->fetchAll(); ?>
<table id="pdca-table"><thead><tr><th>Phase</th><th>Content</th><?php if (!$readonly): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php $rows = $pdca_items ?: [[]]; foreach ($rows as $i => $p): ?>
<tr><td><select name="pdca[<?php echo $i; ?>][phase]"><?php foreach (['Plan','Do','Check','Act'] as $ph): ?><option <?php echo (($p['phase'] ?? '') === $ph) ? 'selected' : ''; ?>><?php echo $ph; ?></option><?php endforeach; ?></select></td><td><textarea name="pdca[<?php echo $i; ?>][content]" rows="2"><?php echo h($p['content'] ?? ''); ?></textarea></td><?php if (!$readonly): ?><td><button type="button" onclick="this.closest('tr').remove()">✕</button></td><?php endif; ?></tr>
<?php endforeach; ?>
</tbody></table>
<?php if (!$readonly): ?><button type="button" class="btn btn-outline btn-sm" onclick="addPDCA()">+ Add PDCA Entry</button><?php endif; ?>
<script>
var pdcaCount=<?php echo max(count($pdca_items), 1); ?>;function addPDCA(){var i=pdcaCount++;var tr=document.createElement('tr');tr.innerHTML='<td><select name="pdca['+i+'][phase]"><option>Plan</option><option>Do</option><option>Check</option><option>Act</option></select></td><td><textarea name="pdca['+i+'][content]" rows="2"></textarea></td><td><button type="button" onclick="this.closest(\'tr\').remove()">✕</button></td>';document.querySelector('#pdca-table tbody').appendChild(tr);}
</script>

<?php elseif ($step === 8): ?>
<div class="card-header"><h2>Final Review & Submission</h2></div>
<?php
$quality_gate = aqmsEvaluateQualityGate($pdo, $course, true);
$can_submit = $quality_gate['result'] !== 'red';
$total_pct = array_sum(array_column($assessments, 'percentage'));
?>
<div class="quality-gate quality-gate--<?php echo h($quality_gate['result']); ?>">
    <div class="quality-gate-heading">
        <div>
            <span class="quality-gate-kicker">Automated quality gate</span>
            <h3><?php echo h(aqmsQualityGateLabel($quality_gate['result'])); ?></h3>
        </div>
        <strong class="quality-gate-score"><?php echo (int)$quality_gate['score']; ?>%</strong>
    </div>
    <p><?php echo h($quality_gate['summary']); ?></p>
    <?php if (!empty($quality_gate['checks'])): ?>
        <ul class="quality-gate-list">
        <?php foreach ($quality_gate['checks'] as $check): ?>
            <li><span class="quality-dot quality-dot--<?php echo h($check['severity']); ?>"></span><?php echo h($check['detail']); ?></li>
        <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="quality-gate-pass">✓ Required course, mapping, evidence, and improvement-cycle checks passed.</p>
    <?php endif; ?>
</div>
<table><tr><td>Assessment weights entered</td><td><?php echo number_format((float)$total_pct, 1); ?>%</td></tr><tr><td>Due date</td><td><?php echo h($course['due_date'] ?? 'Not set'); ?></td></tr></table>
<?php if (!$readonly): ?>
<?php if (!$can_submit): ?><div class="alert alert-warning">Fix the red quality-gate items above before submitting to the HoD.</div><?php endif; ?>
<div style="display:flex;gap:12px;flex-wrap:wrap;"><a href="<?php echo BASE_URL; ?>/course/view.php?id=<?php echo $course_id; ?>" class="btn btn-outline" target="_blank">Preview Full Spec</a><?php if ($can_submit): ?><button type="button" class="btn btn-success" onclick="submitCourseToHod()">✓ Submit to HoD</button><?php endif; ?></div>
<?php if ($can_submit): ?><script>
function submitCourseToHod() {
    if (!window.confirm('Submit this course specification to the HoD for review?')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'course_submit.php?id=<?php echo $course_id; ?>';
    var token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'csrf_token';
    token.value = '<?php echo h(aqmsCsrfToken()); ?>';
    form.appendChild(token);
    document.body.appendChild(form);
    form.submit();
}
</script><?php endif; ?>
<?php else: ?><span class="status-badge status-<?php echo $course['status']; ?>"><?php echo h(str_replace('_', ' ', $course['status'])); ?></span><?php endif; ?>
<?php endif; ?>

<?php if ($step < 8 && !$readonly): ?>
<div style="display:flex;gap:10px;margin-top:24px;padding-top:18px;border-top:1px solid var(--border);"><button type="submit" name="save" class="btn btn-outline">Save</button><button type="submit" name="next" class="btn btn-primary">Save & Next</button><a href="dashboard.php" class="btn btn-ghost" style="margin-left:auto;">Back to Dashboard</a></div>
<?php endif; ?>
</fieldset>
</form>
</div>
<?php include '../includes/footer.php'; ?>

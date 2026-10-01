<?php
require_once '../includes/auth_check.php';
requireAnyRole(['hod','dean','qa']);
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/program_validation.php';

$program_id = intval($_GET['id'] ?? 1);
$step = intval($_GET['step'] ?? 1);
if ($step < 1 || $step > 9) $step = 1;
$can_edit = in_array($_SESSION['role'], ['hod'], true);

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function val($arr, $key) { return h($arr[$key] ?? ''); }

$stmt = $pdo->prepare('SELECT * FROM program_specs WHERE program_id = ?');
$stmt->execute([$program_id]);
$program = $stmt->fetch();
if (!$program) { header('Location: ../index.php'); exit(); }

$sectionTitles = [
'main_location' => 'Main Location',
'branches' => 'Branches Offering the Program',
'partnerships' => 'Partnerships with Other Parties',
'professions_jobs' => 'Professions / Jobs for Graduates',
'professional_sectors' => 'Professional Sectors',
'tracks' => 'Major Tracks / Pathways',
'exit_points' => 'Exit Points / Awarded Degree',
'admission_requirements' => 'Admission Requirements',
'orientation_programs' => 'Orientation Programs',
'student_counseling' => 'Student Counseling and Support',
'special_support' => 'Special Support',
'learning_resources' => 'Learning Resources',
'facilities_equipment' => 'Facilities and Equipment',
'safety_procedures' => 'Safety Procedures',
'qa_system' => 'Program Quality Assurance System',
'course_monitoring' => 'Course Monitoring Procedures',
'branch_consistency' => 'Branch Consistency Procedures',
'plo_assessment_plan' => 'PLO Assessment Plan'
];

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && !aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $err = 'The form expired. Refresh the page and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    if ($step === 1) {
        $pdo->prepare('UPDATE program_specs SET program_name=?, program_code=?, college=?, department=?, credit_hours=?, qualification_level=?, mission=?, goals=?, program_aims=?, program_structure=? WHERE program_id=?')
            ->execute([
                trim($_POST['program_name'] ?? ''), trim($_POST['program_code'] ?? ''), trim($_POST['college'] ?? ''), trim($_POST['department'] ?? ''),
                intval($_POST['credit_hours'] ?? 0) ?: null, trim($_POST['qualification_level'] ?? ''), trim($_POST['mission'] ?? ''), trim($_POST['goals'] ?? ''), trim($_POST['program_aims'] ?? ''), trim($_POST['program_structure'] ?? ''), $program_id
            ]);
        $msg = 'Program identification and mission saved.';
    } elseif ($step === 2) {
        foreach ($sectionTitles as $key => $title) {
            if (!isset($_POST['section'][$key])) continue;
            $pdo->prepare('INSERT INTO program_tp151_sections (program_id, section_key, section_title, section_value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE section_title = VALUES(section_title), section_value = VALUES(section_value)')
                ->execute([$program_id, $key, $title, trim($_POST['section'][$key] ?? '')]);
        }
        $msg = 'TP-151 narrative sections saved.';
    } elseif ($step === 3) {
        $pdo->prepare('DELETE FROM program_curriculum_structure WHERE program_id=?')->execute([$program_id]);
        foreach (($_POST['curriculum'] ?? []) as $row) {
            if (trim($row['requirement_type'] ?? '') === '') continue;
            $pdo->prepare('INSERT INTO program_curriculum_structure (program_id, requirement_type, required_hours, elective_hours, total_hours, percentage) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$program_id, trim($row['requirement_type']), floatval($row['required_hours'] ?? 0) ?: null, floatval($row['elective_hours'] ?? 0) ?: null, floatval($row['total_hours'] ?? 0) ?: null, floatval($row['percentage'] ?? 0) ?: null]);
        }
        $msg = 'Curriculum structure saved.';
    } elseif ($step === 4) {
        $pdo->prepare('DELETE FROM program_course_plan WHERE program_id=?')->execute([$program_id]);
        foreach (($_POST['course'] ?? []) as $row) {
            if (trim($row['course_code'] ?? '') === '' && trim($row['course_title'] ?? '') === '') continue;
            $pdo->prepare('INSERT INTO program_course_plan (program_id, level_no, course_code, course_title, required_elective, prerequisites, credit_hours, course_spec_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$program_id, intval($row['level_no'] ?? 0) ?: null, trim($row['course_code'] ?? ''), trim($row['course_title'] ?? ''), trim($row['required_elective'] ?? ''), trim($row['prerequisites'] ?? ''), floatval($row['credit_hours'] ?? 0) ?: null, trim($row['course_spec_url'] ?? '')]);
        }
        $msg = 'Program course plan saved.';
    } elseif ($step === 5) {
        $pdo->prepare('DELETE FROM program_plo_methods WHERE program_id=?')->execute([$program_id]);
        foreach (($_POST['plo'] ?? []) as $plo_id => $row) {
            $pdo->prepare('INSERT INTO program_plo_methods (program_id, plo_id, teaching_strategies, assessment_methods) VALUES (?, ?, ?, ?)')
                ->execute([$program_id, intval($plo_id), trim($row['teaching_strategies'] ?? ''), trim($row['assessment_methods'] ?? '')]);
        }
        $msg = 'PLO strategies and assessment methods saved.';
    } elseif ($step === 6) {
        $pdo->prepare('DELETE FROM program_plo_course_mapping WHERE program_id=?')->execute([$program_id]);
        foreach (($_POST['mapping'] ?? []) as $course_plan_id => $plos) {
            foreach ($plos as $plo_id => $level) {
                $level = trim($level);
                if (!in_array($level, ['I','P','M'], true)) continue;
                $pdo->prepare('INSERT INTO program_plo_course_mapping (program_id, course_plan_id, plo_id, performance_level) VALUES (?, ?, ?, ?)')
                    ->execute([$program_id, intval($course_plan_id), intval($plo_id), $level]);
            }
        }
        $msg = 'PLO course mapping matrix saved.';
    } elseif ($step === 7) {
        $pdo->prepare('DELETE FROM program_staffing WHERE program_id=?')->execute([$program_id]);
        foreach (($_POST['staff'] ?? []) as $row) {
            if (trim($row['academic_rank'] ?? '') === '') continue;
            $pdo->prepare('INSERT INTO program_staffing (program_id, academic_rank, specialty, special_requirements, male_count, female_count, total_count) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$program_id, trim($row['academic_rank']), trim($row['specialty'] ?? ''), trim($row['special_requirements'] ?? ''), intval($row['male_count'] ?? 0) ?: null, intval($row['female_count'] ?? 0) ?: null, intval($row['total_count'] ?? 0) ?: null]);
        }
        $msg = 'Staffing data saved.';
    } elseif ($step === 8) {
        $pdo->prepare('DELETE FROM program_evaluation_matrix WHERE program_id=?')->execute([$program_id]);
        foreach (($_POST['eval'] ?? []) as $row) {
            if (trim($row['evaluation_area'] ?? '') === '') continue;
            $pdo->prepare('INSERT INTO program_evaluation_matrix (program_id, evaluation_area, evaluation_sources, evaluation_methods, evaluation_time) VALUES (?, ?, ?, ?, ?)')
                ->execute([$program_id, trim($row['evaluation_area']), trim($row['evaluation_sources'] ?? ''), trim($row['evaluation_methods'] ?? ''), trim($row['evaluation_time'] ?? '')]);
        }
        $msg = 'Evaluation matrix saved.';
    } elseif ($step === 9) {
        $pdo->prepare('DELETE FROM program_approval WHERE program_id=?')->execute([$program_id]);
        $pdo->prepare('INSERT INTO program_approval (program_id, council_committee, reference_no, approval_date) VALUES (?, ?, ?, ?)')
            ->execute([$program_id, trim($_POST['council_committee'] ?? ''), trim($_POST['reference_no'] ?? ''), $_POST['approval_date'] ?: null]);
        $msg = 'Program approval data saved.';
    }
    if (isset($_POST['next'])) { header('Location: edit.php?id=' . $program_id . '&step=' . min($step + 1, 9)); exit(); }
    $stmt->execute([$program_id]); $program = $stmt->fetch();
}

$sections = [];
$s = $pdo->prepare('SELECT * FROM program_tp151_sections WHERE program_id=?'); $s->execute([$program_id]);
foreach ($s->fetchAll() as $row) $sections[$row['section_key']] = $row['section_value'];

$fetchAll = function($sql) use ($pdo, $program_id) { $q = $pdo->prepare($sql); $q->execute([$program_id]); return $q->fetchAll(); };
$curriculum = $fetchAll('SELECT * FROM program_curriculum_structure WHERE program_id=? ORDER BY id');
$plan = $fetchAll('SELECT * FROM program_course_plan WHERE program_id=? ORDER BY level_no, course_code');
$plos = $fetchAll('SELECT * FROM program_learning_outcomes WHERE program_id=? ORDER BY category, plo_code');
$methods = []; foreach ($fetchAll('SELECT * FROM program_plo_methods WHERE program_id=?') as $m) $methods[$m['plo_id']] = $m;
$staff = $fetchAll('SELECT * FROM program_staffing WHERE program_id=? ORDER BY id');
$evals = $fetchAll('SELECT * FROM program_evaluation_matrix WHERE program_id=? ORDER BY id');
$approval = $fetchAll('SELECT * FROM program_approval WHERE program_id=? LIMIT 1'); $approval = $approval[0] ?? [];
$matrix = [];
if (aqmsProgramTableExists($pdo, 'program_plo_course_mapping')) {
    foreach ($fetchAll('SELECT * FROM program_plo_course_mapping WHERE program_id=?') as $m) $matrix[$m['course_plan_id']][$m['plo_id']] = $m['performance_level'];
}
$errors = aqmsValidateProgramSpecification($pdo, $program);

$labels = [1=>'A/B. Identification',2=>'A/D/E/F/G. Details',3=>'C. Curriculum',4=>'C. Courses',5=>'C. PLO Methods',6=>'C. PLO Matrix',7=>'E. Staff',8=>'G. Evaluation',9=>'H. Approval'];
$page_title = 'Program Specification';
include '../includes/header.php';
?>

<?php if (!$can_edit): ?><div class="alert alert-info">Read-only view for <?php echo h($_SESSION['role']); ?>.</div><?php endif; ?>
<?php if ($msg): ?><div class="alert alert-success"><?php echo h($msg); ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?php echo h($err); ?></div><?php endif; ?>

<div class="card" style="padding:14px 22px;"><div class="tabs" style="margin-bottom:0;">
<?php foreach ($labels as $i=>$label): ?><a class="tab-link <?php echo $step===$i?'active':''; ?>" href="edit.php?id=<?php echo $program_id; ?>&step=<?php echo $i; ?>"><?php echo $i . '. ' . h($label); ?></a><?php endforeach; ?>
</div></div>

<div class="card"><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo h(aqmsCsrfToken()); ?>"><fieldset <?php echo !$can_edit ? 'disabled' : ''; ?> style="border:0;padding:0;margin:0;">
<?php if ($step === 1): ?>
<div class="card-header"><h2>Program Identification, Mission, Goals, and Structure</h2></div>
<div class="form-row"><div class="form-group"><label>Program Name</label><input name="program_name" value="<?php echo val($program,'program_name'); ?>"></div><div class="form-group"><label>Program Code</label><input name="program_code" value="<?php echo val($program,'program_code'); ?>"></div></div>
<div class="form-row"><div class="form-group"><label>College</label><input name="college" value="<?php echo val($program,'college'); ?>"></div><div class="form-group"><label>Department</label><input name="department" value="<?php echo val($program,'department'); ?>"></div></div>
<div class="form-row"><div class="form-group"><label>Total Credit Hours</label><input type="number" name="credit_hours" value="<?php echo val($program,'credit_hours'); ?>"></div><div class="form-group"><label>Qualification Level</label><input name="qualification_level" value="<?php echo val($program,'qualification_level'); ?>"></div></div>
<div class="form-group"><label>Mission</label><textarea name="mission" rows="3"><?php echo val($program,'mission'); ?></textarea></div>
<div class="form-group"><label>Goals</label><textarea name="goals" rows="3"><?php echo val($program,'goals'); ?></textarea></div>
<div class="form-group"><label>Program Aims</label><textarea name="program_aims" rows="3"><?php echo val($program,'program_aims'); ?></textarea></div>
<div class="form-group"><label>Program Structure</label><textarea name="program_structure" rows="3"><?php echo val($program,'program_structure'); ?></textarea></div>

<?php elseif ($step === 2): ?>
<div class="card-header"><h2>TP-151 Narrative Details</h2></div>
<?php foreach ($sectionTitles as $key=>$title): ?><div class="form-group"><label><?php echo h($title); ?></label><textarea name="section[<?php echo h($key); ?>]" rows="3"><?php echo h($sections[$key] ?? ''); ?></textarea></div><?php endforeach; ?>

<?php elseif ($step === 3): ?>
<div class="card-header"><h2>Curriculum Structure</h2></div><table><tr><th>Requirement Type</th><th>Required</th><th>Elective</th><th>Total</th><th>%</th></tr>
<?php $rows=$curriculum ?: array_map(fn($x)=>['requirement_type'=>$x], ['Institution Requirements','College Requirements','Program Requirements','Capstone Course/Project','Field Training/Internship','Others']); foreach($rows as $i=>$r): ?><tr><td><input name="curriculum[<?php echo $i; ?>][requirement_type]" value="<?php echo val($r,'requirement_type'); ?>"></td><td><input type="number" step="0.5" name="curriculum[<?php echo $i; ?>][required_hours]" value="<?php echo val($r,'required_hours'); ?>"></td><td><input type="number" step="0.5" name="curriculum[<?php echo $i; ?>][elective_hours]" value="<?php echo val($r,'elective_hours'); ?>"></td><td><input type="number" step="0.5" name="curriculum[<?php echo $i; ?>][total_hours]" value="<?php echo val($r,'total_hours'); ?>"></td><td><input type="number" step="0.5" name="curriculum[<?php echo $i; ?>][percentage]" value="<?php echo val($r,'percentage'); ?>"></td></tr><?php endforeach; ?>
</table>

<?php elseif ($step === 4): ?>
<div class="card-header"><h2>Program Courses by Level</h2></div><table><tr><th>Level</th><th>Code</th><th>Title</th><th>Req/Elective</th><th>Prereq</th><th>Credits</th><th>Spec URL</th></tr>
<?php $rows=$plan ?: array_fill(0,10,[]); foreach($rows as $i=>$r): ?><tr><td><input type="number" name="course[<?php echo $i; ?>][level_no]" value="<?php echo val($r,'level_no'); ?>"></td><td><input name="course[<?php echo $i; ?>][course_code]" value="<?php echo val($r,'course_code'); ?>"></td><td><input name="course[<?php echo $i; ?>][course_title]" value="<?php echo val($r,'course_title'); ?>"></td><td><input name="course[<?php echo $i; ?>][required_elective]" value="<?php echo val($r,'required_elective'); ?>"></td><td><input name="course[<?php echo $i; ?>][prerequisites]" value="<?php echo val($r,'prerequisites'); ?>"></td><td><input type="number" step="0.5" name="course[<?php echo $i; ?>][credit_hours]" value="<?php echo val($r,'credit_hours'); ?>"></td><td><input name="course[<?php echo $i; ?>][course_spec_url]" value="<?php echo val($r,'course_spec_url'); ?>"></td></tr><?php endforeach; ?>
</table>

<?php elseif ($step === 5): ?>
<div class="card-header"><h2>PLO Teaching Strategies and Assessment Methods</h2></div><table><tr><th>PLO</th><th>Description</th><th>Teaching Strategies</th><th>Assessment Methods</th></tr>
<?php foreach($plos as $p): $m=$methods[$p['plo_id']] ?? []; ?><tr><td><?php echo h($p['plo_code']); ?></td><td><?php echo h($p['description']); ?></td><td><textarea name="plo[<?php echo $p['plo_id']; ?>][teaching_strategies]" rows="2"><?php echo val($m,'teaching_strategies'); ?></textarea></td><td><textarea name="plo[<?php echo $p['plo_id']; ?>][assessment_methods]" rows="2"><?php echo val($m,'assessment_methods'); ?></textarea></td></tr><?php endforeach; ?>
</table>

<?php elseif ($step === 6): ?>
<div class="card-header"><h2>Program Learning Outcomes Mapping Matrix</h2></div>
<div class="tp-note">Use I for Introduced, P for Practiced, and M for Mastered. This completes the TP-151 curriculum mapping requirement.</div>
<table><tr><th>Course</th><?php foreach($plos as $p): ?><th title="<?php echo h($p['description']); ?>"><?php echo h($p['plo_code']); ?></th><?php endforeach; ?></tr>
<?php if(empty($plan)): ?><tr><td colspan="<?php echo count($plos)+1; ?>">Add program courses first.</td></tr><?php else: foreach($plan as $c): ?><tr><td><strong><?php echo h($c['course_code']); ?></strong><br><?php echo h($c['course_title']); ?></td><?php foreach($plos as $p): ?><td><select class="ipm-select" name="mapping[<?php echo $c['id']; ?>][<?php echo $p['plo_id']; ?>]"><option value="">-</option><?php foreach(['I','P','M'] as $level): ?><option value="<?php echo $level; ?>" <?php echo (($matrix[$c['id']][$p['plo_id']] ?? '') === $level) ? 'selected' : ''; ?>><?php echo $level; ?></option><?php endforeach; ?></select></td><?php endforeach; ?></tr><?php endforeach; endif; ?>
</table>

<?php elseif ($step === 7): ?>
<div class="card-header"><h2>Faculty and Administrative Staff</h2></div><table><tr><th>Rank</th><th>Specialty</th><th>Requirements</th><th>Male</th><th>Female</th><th>Total</th></tr>
<?php $rows=$staff ?: array_fill(0,5,[]); foreach($rows as $i=>$r): ?><tr><td><input name="staff[<?php echo $i; ?>][academic_rank]" value="<?php echo val($r,'academic_rank'); ?>"></td><td><input name="staff[<?php echo $i; ?>][specialty]" value="<?php echo val($r,'specialty'); ?>"></td><td><input name="staff[<?php echo $i; ?>][special_requirements]" value="<?php echo val($r,'special_requirements'); ?>"></td><td><input type="number" name="staff[<?php echo $i; ?>][male_count]" value="<?php echo val($r,'male_count'); ?>"></td><td><input type="number" name="staff[<?php echo $i; ?>][female_count]" value="<?php echo val($r,'female_count'); ?>"></td><td><input type="number" name="staff[<?php echo $i; ?>][total_count]" value="<?php echo val($r,'total_count'); ?>"></td></tr><?php endforeach; ?>
</table>

<?php elseif ($step === 8): ?>
<div class="card-header"><h2>Program Evaluation Matrix</h2></div><table><tr><th>Area</th><th>Sources</th><th>Methods</th><th>Time</th></tr>
<?php $rows=$evals ?: array_map(fn($x)=>['evaluation_area'=>$x], ['Program leadership','Course quality','Student experience','Graduate employability','Learning outcomes achievement']); foreach($rows as $i=>$r): ?><tr><td><input name="eval[<?php echo $i; ?>][evaluation_area]" value="<?php echo val($r,'evaluation_area'); ?>"></td><td><input name="eval[<?php echo $i; ?>][evaluation_sources]" value="<?php echo val($r,'evaluation_sources'); ?>"></td><td><input name="eval[<?php echo $i; ?>][evaluation_methods]" value="<?php echo val($r,'evaluation_methods'); ?>"></td><td><input name="eval[<?php echo $i; ?>][evaluation_time]" value="<?php echo val($r,'evaluation_time'); ?>"></td></tr><?php endforeach; ?>
</table>

<?php elseif ($step === 9): ?>
<div class="card-header"><h2>Approval and Final Review</h2></div>
<?php if($errors): ?><div class="alert alert-warning"><strong>Missing before complete TP-151:</strong><ul class="completion-list"><?php foreach($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?></ul></div><?php else: ?><div class="alert alert-success">Program specification is complete.</div><?php endif; ?>
<div class="form-row"><div class="form-group"><label>Council / Committee</label><input name="council_committee" value="<?php echo val($approval,'council_committee'); ?>"></div><div class="form-group"><label>Reference No.</label><input name="reference_no" value="<?php echo val($approval,'reference_no'); ?>"></div><div class="form-group"><label>Date</label><input type="date" name="approval_date" value="<?php echo val($approval,'approval_date'); ?>"></div></div>
<a href="view.php?id=<?php echo $program_id; ?>" target="_blank" class="btn btn-outline">Print Program Specification</a>
<?php endif; ?>

</fieldset><?php if($can_edit): ?><div style="margin-top:18px;"><button class="btn btn-primary" type="submit">Save</button> <button class="btn btn-primary" type="submit" name="next" value="1">Save & Next</button></div><?php endif; ?></form></div>
<?php include '../includes/footer.php'; ?>

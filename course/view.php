<?php
require_once '../includes/auth_check.php';
require_once '../db.php';

$course_id = intval($_GET['id'] ?? 0);

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function safeDate($value, $fallback = '') {
    if (empty($value)) return $fallback;
    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : $fallback;
}

$stmt = $pdo->prepare('SELECT cs.*, ps.program_name, ps.program_code, ps.college AS program_college, ps.department AS program_department, u.full_name AS faculty_name FROM course_specs cs LEFT JOIN program_specs ps ON cs.program_id = ps.program_id LEFT JOIN user u ON cs.faculty_id = u.user_id WHERE cs.course_id = ?');
$stmt->execute([$course_id]);
$course = $stmt->fetch();
if (!$course) { header('Location: ../index.php'); exit(); }

$clos_stmt = $pdo->prepare('SELECT * FROM course_learning_outcomes WHERE course_id = ? ORDER BY category, clo_code');
$clos_stmt->execute([$course_id]);
$clos = $clos_stmt->fetchAll();

$clo_ids = array_column($clos, 'clo_id');
$clo_maps = [];
if ($clo_ids) {
    $in = implode(',', array_fill(0, count($clo_ids), '?'));
    $map = $pdo->prepare("SELECT m.clo_id, p.plo_code FROM clo_plo_mapping m JOIN program_learning_outcomes p ON m.plo_id = p.plo_id WHERE m.clo_id IN ($in) ORDER BY p.plo_code");
    $map->execute($clo_ids);
    foreach ($map->fetchAll() as $row) $clo_maps[$row['clo_id']][] = $row['plo_code'];
}

$jahiziah = [];
$j_stmt = $pdo->prepare('SELECT clo_id, skill_type FROM jahiziah_skills WHERE course_id = ? ORDER BY skill_type');
$j_stmt->execute([$course_id]);
foreach ($j_stmt->fetchAll() as $row) $jahiziah[$row['clo_id']][] = $row['skill_type'];

$assessments_stmt = $pdo->prepare('SELECT a.*, GROUP_CONCAT(cl.clo_code ORDER BY cl.clo_code SEPARATOR ", ") AS clo_codes FROM assessments a LEFT JOIN assessment_clo ac ON a.id = ac.assessment_id LEFT JOIN course_learning_outcomes cl ON ac.clo_id = cl.clo_id WHERE a.course_id = ? GROUP BY a.id ORDER BY a.timing_week, a.id');
$assessments_stmt->execute([$course_id]);
$assessments = $assessments_stmt->fetchAll();

$tables = [
    'modes' => ['teaching_modes', 'SELECT * FROM teaching_modes WHERE course_id = ? ORDER BY id'],
    'hours' => ['contact_hours', 'SELECT * FROM contact_hours WHERE course_id = ? ORDER BY id'],
    'topics' => ['course_topics', 'SELECT * FROM course_topics WHERE course_id = ? ORDER BY sort_order, id'],
    'resources' => ['resources', 'SELECT * FROM resources WHERE course_id = ? ORDER BY category, id'],
    'facilities' => ['course_facilities', 'SELECT * FROM course_facilities WHERE course_id = ? ORDER BY id'],
    'qualities' => ['course_quality', 'SELECT * FROM course_quality WHERE course_id = ? ORDER BY id'],
    'pdca' => ['course_pdca', 'SELECT * FROM course_pdca WHERE course_id = ? ORDER BY id'],
    'logs' => ['approval_log', 'SELECT al.*, u.full_name, u.role FROM approval_log al LEFT JOIN user u ON al.user_id = u.user_id WHERE al.course_id = ? ORDER BY al.created_at ASC']
];

$data = [];
foreach ($tables as $key => $info) {
    try {
        $q = $pdo->prepare($info[1]);
        $q->execute([$course_id]);
        $data[$key] = $q->fetchAll();
    } catch (Exception $e) {
        $data[$key] = [];
    }
}

try {
    $approval_stmt = $pdo->prepare('SELECT * FROM course_approval WHERE course_id = ? LIMIT 1');
    $approval_stmt->execute([$course_id]);
    $approval = $approval_stmt->fetch() ?: [];
} catch (Exception $e) { $approval = []; }

$category_titles = [
    'Knowledge' => '1.0 Knowledge and understanding',
    'Knowledge and Understanding' => '1.0 Knowledge and understanding',
    'Skills' => '2.0 Skills',
    'Values' => '3.0 Values, autonomy, and responsibility',
    'Values, Autonomy, and Responsibility' => '3.0 Values, autonomy, and responsibility'
];

$resource_order = ['Essential References','Supportive References','Electronic Materials','Other Learning Materials','Essential','Supportive','Electronic','Other'];
$resource_grouped = [];
foreach ($data['resources'] as $r) $resource_grouped[$r['category']][] = $r['resource_text'];

$department = $course['department'] ?? $course['program_department'] ?? '';
$college = $course['college'] ?? $course['program_college'] ?? '';
$last_revision = $course['last_revision_date'] ?? $course['updated_at'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Course Specification - <?php echo h($course['course_title']); ?></title>
<style>
body{font-family:"Times New Roman",serif;font-size:12pt;color:#000;background:#fff;margin:0;padding:0}.no-print{font-family:Arial,sans-serif;background:#333;color:white;padding:12px 24px;display:flex;gap:12px}.no-print button,.no-print a{background:#d96f32;color:#fff;border:0;padding:8px 14px;font-weight:700;border-radius:4px;font-size:14px;text-decoration:none}.print-wrapper{max-width:920px;margin:0 auto;padding:30px 40px}h1{font-size:16pt;text-align:center;margin:0 0 4px}h2{font-size:12pt;background:#e9e9e9;color:#000;border:1px solid #555;padding:6px 8px;margin:20px 0 8px}h3{font-size:11pt;margin:14px 0 6px}table{width:100%;border-collapse:collapse;margin-bottom:12px;font-size:10.5pt}th,td{border:1px solid #777;padding:5px 7px;vertical-align:top}th{background:#f1f1f1;text-align:left}ul{margin-top:4px}.center{text-align:center}.muted{color:#555}.section-row td{background:#f5f5f5;font-weight:bold}@media print{.no-print{display:none!important}body{font-size:11pt}.print-wrapper{padding:0}h2,th,.section-row td{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>
<div class="no-print"><button onclick="window.print()">Print / Save as PDF</button><a href="status.php?id=<?php echo $course_id; ?>">View Status</a><a href="../index.php">Back to Dashboard</a></div>
<div class="print-wrapper">
<h1>Course Specification</h1>
<p class="center muted">Al Yamamah University - Academic Quality Management System</p>

<table>
<tr><th>Course Title</th><td><?php echo h($course['course_title']); ?></td><th>Course Code</th><td><?php echo h($course['course_code']); ?></td></tr>
<tr><th>Program</th><td><?php echo h($course['program_name']); ?></td><th>Department</th><td><?php echo h($department); ?></td></tr>
<tr><th>College</th><td><?php echo h($college); ?></td><th>Institution</th><td><?php echo h($course['institution'] ?? 'Al Yamamah University'); ?></td></tr>
<tr><th>Version</th><td><?php echo h($course['version']); ?></td><th>Last Revision Date</th><td><?php echo h(safeDate($last_revision)); ?></td></tr>
<tr><th>Prepared by</th><td><?php echo h($course['faculty_name']); ?></td><th>Status</th><td><?php echo h(ucwords(str_replace('_',' ',$course['status']))); ?></td></tr>
<?php if (!empty($course['quality_gate_status']) && $course['quality_gate_status'] !== 'not_run'): ?><tr><th>Quality Gate</th><td><?php echo h(ucwords($course['quality_gate_status'])); ?></td><th>Gate Score</th><td><?php echo h($course['quality_gate_score'] ?? ''); ?>%</td></tr><?php endif; ?>
</table>

<h2>A. General Information about the Course</h2>
<table>
<tr><th>Credit Hours</th><td><?php echo h($course['credit_hours']); ?></td></tr>
<tr><th>Course Type</th><td><?php echo h(trim(($course['course_type'] ?? '') . ' ' . ($course['required_elective'] ?? ''))); ?></td></tr>
<tr><th>Level / Year Offered</th><td><?php echo h($course['course_level']); ?></td></tr>
<tr><th>Course General Description</th><td><?php echo nl2br(h($course['course_description'])); ?></td></tr>
<tr><th>Pre-requirements</th><td><?php echo h($course['prerequisites']); ?></td></tr>
<tr><th>Co-requisites</th><td><?php echo h($course['corequisites']); ?></td></tr>
<tr><th>Course Main Objective(s)</th><td><?php echo nl2br(h($course['objectives'])); ?></td></tr>
</table>

<h3>Teaching Mode</h3>
<table><tr><th>No.</th><th>Mode of Instruction</th><th>Contact Hours</th><th>Percentage</th></tr>
<?php if(empty($data['modes'])): ?><tr><td colspan="4">No teaching mode recorded</td></tr><?php else: foreach($data['modes'] as $i=>$m): ?><tr><td><?php echo $i+1; ?></td><td><?php echo h($m['mode_type']); ?></td><td><?php echo h($m['contact_hours']); ?></td><td><?php echo h($m['percentage']); ?>%</td></tr><?php endforeach; endif; ?>
</table>

<h3>Contact Hours</h3>
<table><tr><th>No.</th><th>Activity</th><th>Contact Hours</th></tr>
<?php if(empty($data['hours'])): ?><tr><td colspan="3">No contact hours recorded</td></tr><?php else: foreach($data['hours'] as $i=>$row): ?><tr><td><?php echo $i+1; ?></td><td><?php echo h($row['activity_type']); ?></td><td><?php echo h($row['hours']); ?></td></tr><?php endforeach; ?><tr><td colspan="2"><strong>Total</strong></td><td><strong><?php echo h(array_sum(array_column($data['hours'],'hours'))); ?></strong></td></tr><?php endif; ?>
</table>

<h2>B. Course Learning Outcomes, Teaching Strategies and Assessment Methods</h2>
<table><tr><th>Code</th><th>Course Learning Outcomes</th><th>Aligned PLOs</th><th>Teaching Strategies</th><th>Assessment Methods</th><th>Jahiziah Skills</th></tr>
<?php if(empty($clos)): ?><tr><td colspan="6">No CLOs recorded</td></tr><?php else: $last=''; foreach($clos as $clo): ?>
<?php if($clo['category']!==$last): $last=$clo['category']; ?><tr class="section-row"><td colspan="6"><?php echo h($category_titles[$last] ?? $last); ?></td></tr><?php endif; ?>
<tr><td><?php echo h($clo['clo_code']); ?></td><td><?php echo h($clo['description']); ?></td><td><?php echo h(implode(', ', $clo_maps[$clo['clo_id']] ?? [])); ?></td><td><?php echo h($clo['teaching_strategies']); ?></td><td><?php echo h($clo['assessment_methods']); ?></td><td><?php echo h(implode(', ', $jahiziah[$clo['clo_id']] ?? [])); ?></td></tr>
<?php endforeach; endif; ?>
</table>

<h2>C. Course Content</h2>
<table><tr><th>No.</th><th>List of Topics</th><th>Contact Hours</th></tr>
<?php if(empty($data['topics'])): ?><tr><td colspan="3">No course topics recorded</td></tr><?php else: foreach($data['topics'] as $i=>$topic): ?><tr><td><?php echo $i+1; ?></td><td><?php echo h($topic['topic_text']); ?></td><td><?php echo h($topic['contact_hours']); ?></td></tr><?php endforeach; ?><tr><td colspan="2"><strong>Total</strong></td><td><strong><?php echo h(array_sum(array_column($data['topics'],'contact_hours'))); ?></strong></td></tr><?php endif; ?>
</table>

<h2>D. Students Assessment Activities</h2>
<table><tr><th>No.</th><th>Assessment Activity</th><th>Timing</th><th>Weight</th><th>Linked CLOs</th></tr>
<?php if(empty($assessments)): ?><tr><td colspan="5">No assessment activities recorded</td></tr><?php else: foreach($assessments as $i=>$a): ?><tr><td><?php echo $i+1; ?></td><td><?php echo h($a['activity_name']); ?></td><td><?php echo h($a['timing_week']); ?></td><td><?php echo h($a['percentage']); ?>%</td><td><?php echo h($a['clo_codes']); ?></td></tr><?php endforeach; ?><tr><td colspan="3"><strong>Total</strong></td><td><strong><?php echo h(array_sum(array_column($assessments,'percentage'))); ?>%</strong></td><td></td></tr><?php endif; ?>
</table>

<h3>D.1 Assessment Rubrics and Performance Tasks</h3>
<table><tr><th>Assessment Activity</th><th>Rubric / Criteria</th><th>Performance Task</th></tr>
<?php if(empty($assessments)): ?><tr><td colspan="3">No assessment details recorded</td></tr><?php else: foreach($assessments as $a): ?><tr><td><?php echo h($a['activity_name']); ?></td><td><?php echo nl2br(h($a['rubric'] ?? '')); ?></td><td><?php echo nl2br(h($a['performance_task'] ?? '')); ?></td></tr><?php endforeach; endif; ?>
</table>

<h2>E. Learning Resources and Facilities</h2>
<h3>References and Learning Resources</h3>
<table><tr><th>Type</th><th>Resources</th></tr>
<?php $printed=false; foreach($resource_order as $cat): if(empty($resource_grouped[$cat])) continue; $printed=true; ?><tr><td><?php echo h($cat); ?></td><td><ul><?php foreach($resource_grouped[$cat] as $item): ?><li><?php echo h($item); ?></li><?php endforeach; ?></ul></td></tr><?php endforeach; if(!$printed): ?><tr><td colspan="2">No learning resources recorded</td></tr><?php endif; ?>
</table>

<h3>Required Facilities and Equipment</h3>
<table><tr><th>Items</th><th>Resources</th></tr>
<?php if(empty($data['facilities'])): ?><tr><td colspan="2">No facilities recorded</td></tr><?php else: foreach($data['facilities'] as $facility): ?><tr><td><?php echo h($facility['item']); ?></td><td><?php echo nl2br(h($facility['resources'])); ?></td></tr><?php endforeach; endif; ?>
</table>

<h2>F. Assessment of Course Quality</h2>
<table><tr><th>Assessment Areas / Issues</th><th>Assessor</th><th>Assessment Methods</th></tr>
<?php if(empty($data['qualities'])): ?><tr><td colspan="3">No course quality assessment data recorded</td></tr><?php else: foreach($data['qualities'] as $q): ?><tr><td><?php echo h($q['assessment_area']); ?></td><td><?php echo h($q['assessor']); ?></td><td><?php echo h($q['assessment_method']); ?></td></tr><?php endforeach; endif; ?>
</table>

<h2>G. PDCA Quality Improvement Log</h2>
<table><tr><th>Phase</th><th>Content</th></tr>
<?php if(empty($data['pdca'])): ?><tr><td colspan="2">No PDCA entries recorded</td></tr><?php else: foreach($data['pdca'] as $entry): ?><tr><td><?php echo h($entry['phase']); ?></td><td><?php echo nl2br(h($entry['content'])); ?></td></tr><?php endforeach; endif; ?>
</table>

<h2>H. Specification Approval</h2>
<table><tr><th>Council / Committee</th><td><?php echo h($approval['council_committee'] ?? ''); ?></td></tr><tr><th>Reference No.</th><td><?php echo h($approval['reference_no'] ?? ''); ?></td></tr><tr><th>Date</th><td><?php echo h(safeDate($approval['approval_date'] ?? '')); ?></td></tr></table>

<h2>Approval Workflow History</h2>
<table><tr><th>Date</th><th>Role</th><th>Reviewer</th><th>From</th><th>To</th><th>Comment</th></tr>
<?php if(empty($data['logs'])): ?><tr><td colspan="6">No workflow activity recorded</td></tr><?php else: foreach($data['logs'] as $log): ?><tr><td><?php echo h($log['created_at']); ?></td><td><?php echo h(strtoupper($log['role'] ?? '')); ?></td><td><?php echo h($log['full_name'] ?? ''); ?></td><td><?php echo h(str_replace('_',' ',$log['from_status'] ?? '')); ?></td><td><?php echo h(str_replace('_',' ',$log['to_status'] ?? '')); ?></td><td><?php echo h($log['comment']); ?></td></tr><?php endforeach; endif; ?>
</table>
</div>
</body>
</html>

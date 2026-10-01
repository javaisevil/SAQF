<?php
require_once '../includes/auth_check.php';
require_once '../db.php';

$program_id = intval($_GET['id'] ?? 1);
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function rows(PDO $pdo, string $sql, int $id){ $q=$pdo->prepare($sql); $q->execute([$id]); return $q->fetchAll(); }
function tableExists(PDO $pdo, string $table){ $q=$pdo->prepare('SHOW TABLES LIKE ?'); $q->execute([$table]); return (bool)$q->fetchColumn(); }

$stmt=$pdo->prepare('SELECT * FROM program_specs WHERE program_id=?');
$stmt->execute([$program_id]);
$program=$stmt->fetch();
if(!$program){ header('Location: ../index.php'); exit(); }

$sections=[];
foreach(rows($pdo,'SELECT section_key, section_title, section_value FROM program_tp151_sections WHERE program_id=? ORDER BY id',$program_id) as $s){ $sections[$s['section_key']]=$s; }
$plos=rows($pdo,'SELECT * FROM program_learning_outcomes WHERE program_id=? ORDER BY category, plo_code',$program_id);
$kpis=rows($pdo,'SELECT * FROM program_kpis WHERE program_id=? ORDER BY kpi_code, id',$program_id);
$curriculum=rows($pdo,'SELECT * FROM program_curriculum_structure WHERE program_id=? ORDER BY id',$program_id);
$plan=rows($pdo,'SELECT * FROM program_course_plan WHERE program_id=? ORDER BY level_no, course_code',$program_id);
$methods=rows($pdo,'SELECT pm.*, p.plo_code, p.description FROM program_plo_methods pm JOIN program_learning_outcomes p ON pm.plo_id=p.plo_id WHERE pm.program_id=? ORDER BY p.category, p.plo_code',$program_id);
$staff=rows($pdo,'SELECT * FROM program_staffing WHERE program_id=? ORDER BY id',$program_id);
$evals=rows($pdo,'SELECT * FROM program_evaluation_matrix WHERE program_id=? ORDER BY id',$program_id);
$approval=rows($pdo,'SELECT * FROM program_approval WHERE program_id=? LIMIT 1',$program_id); $approval=$approval[0]??[];
$courseSpecs=rows($pdo,'SELECT course_id, course_code, course_title, course_level, credit_hours, status FROM course_specs WHERE program_id=? ORDER BY course_level, course_code',$program_id);
$mapping=[];
if(tableExists($pdo,'program_plo_course_mapping')){
    foreach(rows($pdo,'SELECT * FROM program_plo_course_mapping WHERE program_id=?',$program_id) as $m) $mapping[$m['course_plan_id']][$m['plo_id']]=$m['performance_level'];
}
$ploByCategory=[]; foreach($plos as $p){ $ploByCategory[$p['category']][]=$p; }
function sectionText($sections,$key){ return nl2br(h($sections[$key]['section_value'] ?? '')); }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Program Specification - <?php echo h($program['program_name']); ?></title><style>
body{font-family:"Times New Roman",serif;font-size:12pt;color:#000;background:#fff;margin:0}.no-print{font-family:Arial,sans-serif;background:#333;color:white;padding:12px 24px;display:flex;gap:12px}.no-print button,.no-print a{background:#d96f32;color:#fff;border:0;padding:8px 14px;font-weight:700;border-radius:4px;font-size:14px;text-decoration:none}.print-wrapper{max-width:960px;margin:0 auto;padding:30px 40px}h1{font-size:16pt;text-align:center;margin:0 0 4px}h2{font-size:12pt;background:#e9e9e9;border:1px solid #555;padding:6px 8px;margin:20px 0 8px}h3{font-size:11pt;margin:14px 0 6px}table{width:100%;border-collapse:collapse;margin-bottom:12px;font-size:10.5pt}th,td{border:1px solid #777;padding:5px 7px;vertical-align:top}th{background:#f1f1f1;text-align:left}.center{text-align:center}.muted{color:#555}@media print{.no-print{display:none!important}.print-wrapper{padding:0}h2,th{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style></head><body>
<div class="no-print"><button onclick="window.print()">Print / Save as PDF</button><a href="edit.php?id=<?php echo $program_id; ?>">Edit</a><a href="../index.php">Back</a></div>
<div class="print-wrapper"><h1>Program Specification</h1><p class="center muted">Al Yamamah University - Academic Quality Management System</p>

<h2>A. Program Identification and General Information</h2>
<table><tr><th>Program Name</th><td><?php echo h($program['program_name']); ?></td><th>Program Code</th><td><?php echo h($program['program_code']); ?></td></tr><tr><th>College</th><td><?php echo h($program['college']); ?></td><th>Department</th><td><?php echo h($program['department']); ?></td></tr><tr><th>Total Credit Hours</th><td><?php echo h($program['credit_hours']); ?></td><th>Qualification Level</th><td><?php echo h($program['qualification_level']); ?></td></tr><tr><th>Main Location</th><td colspan="3"><?php echo sectionText($sections,'main_location'); ?></td></tr><tr><th>Branches</th><td colspan="3"><?php echo sectionText($sections,'branches'); ?></td></tr><tr><th>Partnerships</th><td colspan="3"><?php echo sectionText($sections,'partnerships'); ?></td></tr><tr><th>Professions / Jobs</th><td colspan="3"><?php echo sectionText($sections,'professions_jobs'); ?></td></tr><tr><th>Professional Sectors</th><td colspan="3"><?php echo sectionText($sections,'professional_sectors'); ?></td></tr><tr><th>Tracks / Pathways</th><td colspan="3"><?php echo sectionText($sections,'tracks'); ?></td></tr><tr><th>Exit Points</th><td colspan="3"><?php echo sectionText($sections,'exit_points'); ?></td></tr></table>

<h2>B. Mission, Goals, and Program Learning Outcomes</h2>
<table><tr><th>Mission</th><td><?php echo nl2br(h($program['mission'])); ?></td></tr><tr><th>Goals</th><td><?php echo nl2br(h($program['goals'])); ?></td></tr><tr><th>Program Aims</th><td><?php echo nl2br(h($program['program_aims'])); ?></td></tr><tr><th>Program Structure</th><td><?php echo nl2br(h($program['program_structure'])); ?></td></tr></table>
<?php if(empty($plos)): ?><p>No PLOs recorded.</p><?php else: foreach($ploByCategory as $cat=>$items): ?><h3><?php echo h($cat); ?></h3><table><tr><th>Code</th><th>Description</th></tr><?php foreach($items as $p): ?><tr><td><?php echo h($p['plo_code']); ?></td><td><?php echo h($p['description']); ?></td></tr><?php endforeach; ?></table><?php endforeach; endif; ?>

<h2>C. Curriculum</h2>
<h3>Curriculum Structure</h3><table><tr><th>Requirement Type</th><th>Required Hours</th><th>Elective Hours</th><th>Total</th><th>%</th></tr><?php if(empty($curriculum)): ?><tr><td colspan="5">No curriculum structure recorded.</td></tr><?php else: foreach($curriculum as $r): ?><tr><td><?php echo h($r['requirement_type']); ?></td><td><?php echo h($r['required_hours']); ?></td><td><?php echo h($r['elective_hours']); ?></td><td><?php echo h($r['total_hours']); ?></td><td><?php echo h($r['percentage']); ?></td></tr><?php endforeach; endif; ?></table>
<h3>Program Courses by Level</h3><table><tr><th>Level</th><th>Code</th><th>Course Title</th><th>Req/Elective</th><th>Prerequisites</th><th>Credits</th><th>Course Spec Link</th></tr><?php if(empty($plan)): ?><tr><td colspan="7">No course plan recorded.</td></tr><?php else: foreach($plan as $c): ?><tr><td><?php echo h($c['level_no']); ?></td><td><?php echo h($c['course_code']); ?></td><td><?php echo h($c['course_title']); ?></td><td><?php echo h($c['required_elective']); ?></td><td><?php echo h($c['prerequisites']); ?></td><td><?php echo h($c['credit_hours']); ?></td><td><?php echo h($c['course_spec_url']); ?></td></tr><?php endforeach; endif; ?></table>

<h3>Program Learning Outcomes Mapping Matrix</h3>
<table><tr><th>Course code and No.</th><?php foreach($plos as $p): ?><th><?php echo h($p['plo_code']); ?></th><?php endforeach; ?></tr>
<?php if(empty($plan)): ?><tr><td colspan="<?php echo count($plos)+1; ?>">No course plan recorded.</td></tr><?php else: foreach($plan as $c): ?><tr><td><?php echo h($c['course_code'] . ' - ' . $c['course_title']); ?></td><?php foreach($plos as $p): ?><td class="center"><?php echo h($mapping[$c['id']][$p['plo_id']] ?? ''); ?></td><?php endforeach; ?></tr><?php endforeach; endif; ?>
</table>
<p class="muted">I = Introduced, P = Practiced, M = Mastered.</p>

<h3>Existing Course Specifications in the System</h3><table><tr><th>Code</th><th>Title</th><th>Level</th><th>Credits</th><th>Status</th></tr><?php if(empty($courseSpecs)): ?><tr><td colspan="5">No course specs connected.</td></tr><?php else: foreach($courseSpecs as $c): ?><tr><td><?php echo h($c['course_code']); ?></td><td><?php echo h($c['course_title']); ?></td><td><?php echo h($c['course_level']); ?></td><td><?php echo h($c['credit_hours']); ?></td><td><?php echo h(ucwords(str_replace('_',' ',$c['status']))); ?></td></tr><?php endforeach; endif; ?></table>
<h3>PLO Teaching and Assessment Methods</h3><table><tr><th>PLO</th><th>Description</th><th>Teaching Strategies</th><th>Assessment Methods</th></tr><?php if(empty($methods)): ?><tr><td colspan="4">No PLO methods recorded.</td></tr><?php else: foreach($methods as $m): ?><tr><td><?php echo h($m['plo_code']); ?></td><td><?php echo h($m['description']); ?></td><td><?php echo nl2br(h($m['teaching_strategies'])); ?></td><td><?php echo nl2br(h($m['assessment_methods'])); ?></td></tr><?php endforeach; endif; ?></table>

<h2>D. Student Admission and Support</h2>
<table><tr><th>Admission Requirements</th><td><?php echo sectionText($sections,'admission_requirements'); ?></td></tr><tr><th>Orientation Programs</th><td><?php echo sectionText($sections,'orientation_programs'); ?></td></tr><tr><th>Student Counseling and Support</th><td><?php echo sectionText($sections,'student_counseling'); ?></td></tr><tr><th>Special Support</th><td><?php echo sectionText($sections,'special_support'); ?></td></tr></table>

<h2>E. Faculty and Administrative Staff</h2>
<table><tr><th>Academic Rank</th><th>Specialty</th><th>Requirements</th><th>Male</th><th>Female</th><th>Total</th></tr><?php if(empty($staff)): ?><tr><td colspan="6">No staffing data recorded.</td></tr><?php else: foreach($staff as $s): ?><tr><td><?php echo h($s['academic_rank']); ?></td><td><?php echo h($s['specialty']); ?></td><td><?php echo h($s['special_requirements']); ?></td><td><?php echo h($s['male_count']); ?></td><td><?php echo h($s['female_count']); ?></td><td><?php echo h($s['total_count']); ?></td></tr><?php endforeach; endif; ?></table>

<h2>F. Learning Resources, Facilities, and Equipment</h2>
<table><tr><th>Learning Resources</th><td><?php echo sectionText($sections,'learning_resources'); ?></td></tr><tr><th>Facilities and Equipment</th><td><?php echo sectionText($sections,'facilities_equipment'); ?></td></tr><tr><th>Healthy and Safe Learning Environment Procedures</th><td><?php echo sectionText($sections,'safety_procedures'); ?></td></tr></table>

<h2>G. Program Quality Assurance</h2>
<table><tr><th>QA System</th><td><?php echo sectionText($sections,'qa_system'); ?></td></tr><tr><th>Course Monitoring Procedures</th><td><?php echo sectionText($sections,'course_monitoring'); ?></td></tr><tr><th>Branch Consistency Procedures</th><td><?php echo sectionText($sections,'branch_consistency'); ?></td></tr><tr><th>PLO Assessment Plan</th><td><?php echo sectionText($sections,'plo_assessment_plan'); ?></td></tr></table>
<h3>Program Evaluation Matrix</h3><table><tr><th>Evaluation Area</th><th>Sources</th><th>Methods</th><th>Time</th></tr><?php if(empty($evals)): ?><tr><td colspan="4">No evaluation matrix recorded.</td></tr><?php else: foreach($evals as $e): ?><tr><td><?php echo h($e['evaluation_area']); ?></td><td><?php echo h($e['evaluation_sources']); ?></td><td><?php echo h($e['evaluation_methods']); ?></td><td><?php echo h($e['evaluation_time']); ?></td></tr><?php endforeach; endif; ?></table>
<h3>Program KPIs</h3><table><tr><th>KPI Code</th><th>KPI</th><th>Target</th><th>Method</th><th>Time</th><th>Years</th></tr><?php if(empty($kpis)): ?><tr><td colspan="6">No KPIs recorded.</td></tr><?php else: foreach($kpis as $k): ?><tr><td><?php echo h($k['kpi_code']); ?></td><td><?php echo h($k['kpi_text']); ?></td><td><?php echo h($k['target_level']); ?></td><td><?php echo h($k['measurement_method']); ?></td><td><?php echo h($k['measurement_time']); ?></td><td><?php echo h($k['years_to_achieve']); ?></td></tr><?php endforeach; endif; ?></table>

<h2>H. Specification Approval Data</h2>
<table><tr><th>Council / Committee</th><td><?php echo h($approval['council_committee'] ?? ''); ?></td></tr><tr><th>Reference No.</th><td><?php echo h($approval['reference_no'] ?? ''); ?></td></tr><tr><th>Date</th><td><?php echo h($approval['approval_date'] ?? ''); ?></td></tr></table>
</div></body></html>

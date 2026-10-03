<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Audit;
use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Events;
use Saqf\Core\Session;
use Saqf\Quality\Catalog;
use Saqf\Web\View as V;

$user = saqf_page(['hod']);
$dept = (int) $user['department_id'];
$terms = Db::all('SELECT * FROM terms WHERE status IN ("active","upcoming") ORDER BY sequence');
$termId = (int) ($_GET['term'] ?? $_POST['term'] ?? ($terms[0]['id'] ?? 0));
// Only programs whose study plan contains a course this department owns.
$programs = Db::all('SELECT DISTINCT p.id, p.code, p.short_name, p.level FROM programs p JOIN study_plan_entries spe ON spe.program_id = p.id JOIN courses c ON c.id = spe.course_id WHERE c.owner_department_id = ? ORDER BY p.level DESC, p.code', [$dept]);
$programId = (int) ($_GET['program'] ?? $_POST['program'] ?? 0);
if ($programId && !in_array($programId, array_map('intval', array_column($programs, 'id')), true)) {
    $programId = 0;
}
$courseId = (int) ($_GET['course'] ?? $_POST['course'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    saqf_require_post();
    $instructor = (int) ($_POST['instructor'] ?? 0);
    $ok = $programId && $courseId && $termId
        && Db::val('SELECT 1 FROM study_plan_entries spe JOIN courses c ON c.id = spe.course_id WHERE spe.program_id = ? AND spe.course_id = ? AND c.owner_department_id = ?', [$programId, $courseId, $dept])
        && Db::val('SELECT 1 FROM users WHERE id = ? AND role = "faculty" AND status = "active" AND department_id = ?', [$instructor, $dept])
        && Db::val('SELECT 1 FROM terms WHERE id = ? AND status IN ("active","upcoming")', [$termId]);
    if (!$ok) {
        Session::flash('error', 'That combination is not valid. Choose a course from the program\'s study plan that your department owns, and a faculty member of your department.');
    } elseif (Db::val('SELECT 1 FROM course_offerings WHERE course_id = ? AND term_id = ?', [$courseId, $termId])) {
        Session::flash('error', 'This course already has a workspace in that term.');
    } else {
        $enrolled = max(0, min(500, (int) ($_POST['enrolled'] ?? 0)));
        Audit::record('workspace.manual_assignment', 'course', $courseId, 'Manual assignment by HoD (SIS fallback)', null, ['term_id' => $termId, 'instructor_id' => $instructor]);
        Events::emit('offering.assigned', ['course_id' => $courseId, 'term_id' => $termId, 'instructor_id' => $instructor, 'sections' => 1, 'enrolled' => $enrolled, 'source' => 'manual']);
        $oid = (int) Db::val('SELECT id FROM course_offerings WHERE course_id = ? AND term_id = ?', [$courseId, $termId]);
        Session::flash('success', 'Workspace created and pre-filled automatically. The instructor has been notified only if an academic task needs them.');
        saqf_redirect('workspace.php?id=' . $oid);
    }
}

$plan = $programId ? Db::all(
    'SELECT spe.*, c.code, c.title, c.credits, (SELECT u.full_name FROM course_offerings o JOIN users u ON u.id = o.instructor_id WHERE o.course_id = c.id AND o.term_id = ?) AS assigned_to
     FROM study_plan_entries spe JOIN courses c ON c.id = spe.course_id WHERE spe.program_id = ? AND c.owner_department_id = ?
     ORDER BY spe.plan_year IS NULL, spe.plan_year, spe.plan_semester, spe.course_type, c.code',
    [$termId, $programId, $dept]
) : [];
$faculty = Db::all('SELECT u.id, u.full_name, u.title, (SELECT COUNT(*) FROM course_offerings o WHERE o.instructor_id = u.id AND o.term_id = ?) AS load_n FROM users u WHERE u.role = "faculty" AND u.status = "active" AND u.department_id = ? ORDER BY u.full_name', [$termId, $dept]);
$course = $courseId ? Catalog::course($courseId) : null;
V::header('Assign a course', $user, ['subtitle' => 'Only for a course the university timetable missed; normally courses appear by themselves']);
?>
<div class="split"><section class="card"><div class="card-h"><h2>Give a course to an instructor</h2></div><div class="card-b">
  <form method="get" class="grid g2">
    <div class="field"><label>Term</label><select name="term" data-autosubmit><?php foreach ($terms as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $termId ? 'selected' : '' ?>><?= V::h($t['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Program</label><select name="program" data-autosubmit><option value="">Choose a program…</option>
      <?php foreach (['Undergraduate', 'Postgraduate'] as $lvl): ?><optgroup label="<?= V::h($lvl === 'Postgraduate' ? 'Master' : 'Bachelor') ?>"><?php foreach ($programs as $p): if ($p['level'] !== $lvl) { continue; } ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $programId ? 'selected' : '' ?>><?= V::h($p['code'] . ' — ' . $p['short_name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
      <div class="field-help"><?= V::h('Only programs with courses taught by your department.') ?></div></div>
  </form>
  <?php if ($programId): ?>
  <form method="post"><?= Csrf::field() ?><input type="hidden" name="term" value="<?= $termId ?>"><input type="hidden" name="program" value="<?= $programId ?>">
    <div class="field"><label>Course</label><select name="course" required data-nav="assign.php?term=<?= $termId ?>&amp;program=<?= $programId ?>&amp;course="><option value="">Choose a course from this plan…</option>
      <?php $group = null; foreach ($plan as $e): $g = $e['plan_year'] ? ($e['plan_year'] == 0 ? 'Foundation year' : 'Year ' . $e['plan_year'] . ($e['plan_semester'] == 3 ? ' · Summer' : ' · Semester ' . $e['plan_semester'])) : 'Electives · ' . $e['requirement_group']; if ($g !== $group) { echo $group ? '</optgroup>' : ''; echo '<optgroup label="' . V::h($g) . '">'; $group = $g; } ?>
        <option value="<?= (int) $e['course_id'] ?>" <?= (int) $e['course_id'] === $courseId ? 'selected' : '' ?> <?= $e['assigned_to'] ? 'disabled' : '' ?>><?= V::h($e['code'] . ' — ' . $e['title']) ?><?= $e['assigned_to'] ? ' — ' . V::h('already given to') . ' ' . V::h($e['assigned_to']) : '' ?></option>
      <?php endforeach; echo $group ? '</optgroup>' : ''; ?></select>
      <div class="field-help">Only your department's courses in this plan are listed, so a wrong choice is not possible.</div></div>
    <?php if ($course): ?>
      <div class="fieldset small"><strong><?= V::h($course['code']) ?> <?= V::h($course['title']) ?></strong> <?= V::source('institution') ?><br><?= V::h(rtrim(rtrim((string) $course['credits'], '0'), '.') . ' credit hours') ?> · <?= V::h($course['department_name']) ?><br>
        <?= V::h('Part of') ?>: <?php foreach (Catalog::programsFor($courseId) as $cp): ?><span class="tag"><?= V::h($cp['code']) ?> · <?= V::h($cp['course_type'] === 'required' ? 'required course' : 'elective') ?></span><?php endforeach; ?><br>
        <?= V::h('Take first') ?>: <?= V::h(implode(', ', array_unique(array_column(Catalog::requisitesFor($courseId), 'code'))) ?: 'nothing') ?><br>
        <?= V::h('Course specification') ?>: <?= \Saqf\Quality\Specs::approved($courseId) ? V::h('the approved one carries over automatically') : V::h('none yet; the instructor will be asked to write it once') ?></div>
      <div class="grid g2"><div class="field"><label>Instructor</label><select name="instructor" required><?php foreach ($faculty as $f): ?><option value="<?= (int) $f['id'] ?>"><?= V::h($f['full_name']) ?> — <?= V::h((int) $f['load_n'] === 1 ? '1 course this term' : (int) $f['load_n'] . ' courses this term') ?></option><?php endforeach; ?></select><div class="field-help">Only instructors of your department.</div></div>
        <div class="field"><label>Expected number of students (optional)</label><input type="number" name="enrolled" min="0" max="500"></div></div>
      <button class="btn btn-primary" type="submit">Give the course</button>
    <?php endif; ?>
  </form>
  <?php endif; ?>
</div></section>
<aside class="card"><div class="card-h"><h2>What you don't need to enter</h2></div><div class="card-b small">
  <p>The course name, credit hours, department, programs, whether it is required, its semester and what to take first all come from the Registrar's study plans.</p>
  <p>The approved course specification (learning outcomes, links to program outcomes, assessments) and any improvements under way carry over automatically.</p></div></aside></div>
<?php V::footer();

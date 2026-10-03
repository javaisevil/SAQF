<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Web\View as V;

$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership', 'admin']);
$colleges = Db::all('SELECT * FROM colleges ORDER BY name');
$departments = Db::all('SELECT d.*, (SELECT COUNT(*) FROM programs WHERE department_id = d.id) AS programs FROM departments d ORDER BY d.name');
$programs = Db::all('SELECT p.*, d.college_id FROM programs p JOIN departments d ON d.id = p.department_id ORDER BY p.level DESC, p.code');
$level = in_array($_GET['level'] ?? '', ['Undergraduate', 'Postgraduate'], true) ? $_GET['level'] : '';
$collegeId = (int) ($_GET['college'] ?? 0);
$programId = (int) ($_GET['program'] ?? 0);
$type = in_array($_GET['type'] ?? '', ['required', 'elective'], true) ? $_GET['type'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$rows = [];
if ($programId) {
    $sql = 'SELECT spe.*, c.code, c.title, c.credits, c.is_graduate, d.name AS owner FROM study_plan_entries spe LEFT JOIN courses c ON c.id = spe.course_id LEFT JOIN departments d ON d.id = c.owner_department_id WHERE spe.program_id = ?';
    $params = [$programId];
    if ($type) {
        $sql .= ' AND spe.course_type = ?';
        $params[] = $type;
    }
    if ($q !== '') {
        $sql .= ' AND (c.code LIKE ? OR c.title LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    $rows = Db::all($sql . ' ORDER BY spe.plan_year IS NULL, spe.plan_year, spe.plan_semester, spe.requirement_group, c.code', $params);
}
$program = $programId ? Db::one('SELECT p.*, d.name AS dept, c.name AS college FROM programs p JOIN departments d ON d.id = p.department_id JOIN colleges c ON c.id = d.college_id WHERE p.id = ?', [$programId]) : null;
V::header('Study plans', $user, ['subtitle' => 'From the university\'s published study plans; each choice narrows the next']);
?>
<section class="card"><div class="card-b">
<form method="get" class="grid g4" style="align-items:end">
  <div class="field"><label>Level</label><select name="level" id="fLevel"><option value="">All levels</option><option <?= $level === 'Undergraduate' ? 'selected' : '' ?>>Undergraduate</option><option <?= $level === 'Postgraduate' ? 'selected' : '' ?>>Postgraduate</option></select></div>
  <div class="field"><label>College</label><select name="college" id="fCollege"><option value="">All colleges</option><?php foreach ($colleges as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $collegeId ? 'selected' : '' ?>><?= V::h($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Program</label><select name="program" id="fProgram" data-filter-parent="#fCollege"><option value="">Choose a program…</option>
    <?php foreach ($programs as $p): ?><option value="<?= (int) $p['id'] ?>" data-parent="<?= (int) $p['college_id'] ?>" data-level="<?= V::h($p['level']) ?>" <?= (int) $p['id'] === $programId ? 'selected' : '' ?>><?= V::h($p['code'] . ' — ' . $p['short_name'] . ' (' . ($p['level'] === 'Postgraduate' ? 'Master' : 'Bachelor') . ')') ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Course type</label><select name="type"><option value="">Required & elective</option><option value="required" <?= $type === 'required' ? 'selected' : '' ?>>Required only</option><option value="elective" <?= $type === 'elective' ? 'selected' : '' ?>>Electives only</option></select></div>
  <div class="field" style="grid-column:span 3"><label>Find in plan</label><input type="search" name="q" value="<?= V::h($q) ?>" placeholder="Code or title"></div>
  <div><button class="btn btn-primary" type="submit">Show plan</button></div>
</form>
</div></section>
<?php if ($program): ?>
<section class="card"><div class="card-h"><h2><?= V::h($program['code'] . ' — ' . $program['name']) ?></h2><span class="muted small"><?= V::h($program['college']) ?> · <?= V::h($program['dept']) ?></span><a class="right small" href="<?= V::h($program['source_url']) ?>" target="_blank" rel="noopener noreferrer">Published PDF</a></div>
  <div class="card-b tight"><div class="table-wrap"><table><thead><tr><th>When</th><th>Course</th><th>Taught by</th><th class="num">Credit hours</th><th>Type</th><th>Group</th></tr></thead><tbody>
  <?php foreach ($rows as $e): ?><tr><td class="small nowrap"><?= $e['plan_year'] === null ? '<span class="muted">' . V::h('Any time') . '</span>' : ((int) $e['plan_year'] === 0 ? V::h('Foundation year') : V::h('Year ' . (int) $e['plan_year']) . ' · ' . V::h($e['plan_semester'] == 3 ? 'Summer' : 'Semester ' . (int) $e['plan_semester'])) ?></td>
    <td><?= $e['course_id'] ? '<strong>' . V::h($e['code']) . '</strong> ' . V::h($e['title']) . ((int) $e['is_graduate'] && $program['level'] === 'Undergraduate' ? ' ' . V::pill('graduate course', 'violet') : '') : '<span class="muted">' . V::h($e['slot_title']) . '</span> ' . V::pill('your choice', 'grey') ?></td>
    <td class="small muted"><?= V::h($e['owner'] ?? '') ?></td><td class="num"><?= V::h(rtrim(rtrim((string) $e['credits'], '0'), '.')) ?></td><td><?= V::pill(ucfirst((string) $e['course_type']), $e['course_type'] === 'required' ? 'blue' : 'grey') ?></td><td class="small"><?= V::h($e['requirement_group']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div></section>
<?php else: ?>
<div class="grid g3"><?php foreach ($colleges as $c): ?><section class="card"><div class="card-h"><h2><?= V::h($c['name']) ?></h2></div><div class="card-b small"><?php foreach ($programs as $p): if ((int) $p['college_id'] !== (int) $c['id']) { continue; } ?><div><a href="catalog.php?program=<?= (int) $p['id'] ?>&college=<?= (int) $c['id'] ?>"><?= V::h($p['code']) ?></a> <?= V::h($p['short_name']) ?> <span class="muted">· <?= V::h($p['level'] === 'Postgraduate' ? 'Master' : 'Bachelor') ?></span></div><?php endforeach; ?></div></section><?php endforeach; ?></div>
<?php endif;
V::footer();

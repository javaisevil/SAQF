<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Quality\Impact;
use Saqf\Quality\Reports;
use Saqf\Quality\Specs;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership']);
$p = Authz::program($user, (int) ($_GET['id'] ?? 0));
$canManage = Authz::canManageProgram($user, $p);
$r = Reports::program((int) $p['id']);
$target = Policy::get('plo.target_pct');
$issues = Db::all('SELECT * FROM findings WHERE program_id = ? AND status = "open" ORDER BY FIELD(severity,"blocker","warning","info")', [$p['id']]);
$terms = [];
foreach ($r['series'] as $ploId => $list) {
    foreach ($list as $row) {
        $terms[(int) $row['sequence']] = $row['name'];
    }
}
ksort($terms);
$coverageCount = [];
foreach ($r['matrix'] as $code => $byPlo) {
    foreach ($byPlo as $ploId => $n) {
        $coverageCount[$ploId] = ($coverageCount[$ploId] ?? 0) + 1;
    }
}
$narr = [];
foreach (Db::all('SELECT section_key, content FROM program_narratives WHERE program_id = ?', [$p['id']]) as $n) {
    $narr[$n['section_key']] = $n['content'];
}
$tab = in_array($_GET['tab'] ?? '', ['overview', 'plos', 'plan', 'matrix'], true) ? $_GET['tab'] : 'overview';
V::header($p['code'] . ' — ' . $p['name'], $user, ['subtitle' => V::h($p['department_name']) . ' · ' . V::h($p['college_name']) . ' · ' . V::h(ucfirst((string) $p['level'])) . ($p['total_credits'] ? ' · ' . V::h((int) $p['total_credits'] . ' credit hours') : '') . ' ' . V::source('institution')]);
echo V::tabs(['overview' => 'Overview', 'plos' => 'Program outcomes', 'plan' => 'Study plan', 'matrix' => 'Which course covers what'], $tab, 'program.php?id=' . (int) $p['id']);

if ($tab === 'overview'): ?>
<div class="grid g4" style="margin-bottom:16px"><?php foreach ($r['kpis'] as $k): ?><div class="card kpi"><div class="kpi-v"><?= V::h($k['value']) ?></div><div class="kpi-l"><?= V::h($k['label']) ?></div><div class="kpi-d"><?= V::h($k['detail']) ?></div></div><?php endforeach; ?></div>
<div class="split"><div class="stack">
  <section class="card"><div class="card-h"><h2>How students did on each program outcome</h2><span class="muted small right"><?= V::h('worked out from course results') ?> · <?= V::h('goal ' . V::pct($target)) ?></span></div>
    <div class="card-b tight"><div class="table-wrap"><table class="matrix"><thead><tr><th>Program outcome</th><?php foreach ($terms as $name): ?><th><?= V::h($name) ?></th><?php endforeach; ?><th title="Courses that teach this outcome">Courses</th><th>Trend</th></tr></thead><tbody>
    <?php foreach ($r['plos'] as $pl): $series = []; foreach ($r['series'][(int) $pl['id']] ?? [] as $s) { $series[(int) $s['sequence']] = $s; } ?>
      <tr><td><strong><?= V::h($pl['code']) ?></strong> <span class="tiny muted"><?= V::h(mb_strimwidth($pl['statement'], 0, 70, '…')) ?></span></td>
        <?php foreach ($terms as $seq => $name): $s = $series[$seq] ?? null; ?><td><?php if ($s): ?><span class="heat <?= (int) $s['prov'] ? 'heat-prov' : ((float) $s['v'] >= $target ? 'heat-ok' : 'heat-low') ?>" title="<?= V::h((int) $s['n'] === 1 ? 'From 1 course' : 'Average of ' . (int) $s['n'] . ' courses') ?>"><?= V::pct($s['v']) ?></span><?php else: ?><span class="heat heat-none">—</span><?php endif; ?></td><?php endforeach; ?>
        <td><?= (int) ($coverageCount[(int) $pl['id']] ?? 0) ?></td><td><?= V::spark(array_map(static fn($s) => (int) $s['prov'] ? null : (float) $s['v'], array_values($series)), $target) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$r['plos']): ?><tr><td colspan="9"><?= V::empty('No approved program outcomes', 'Results for the program appear once its outcomes are approved.') ?></td></tr><?php endif; ?>
    </tbody></table></div><div class="card-b"><div class="legend"><span class="lg-green"><?= V::h('met the ' . V::pct($target) . ' goal') ?></span><span class="lg-red">below the goal</span><span>this term, not final yet</span></div><div class="tiny muted" style="margin-top:6px">Each number is the average result of the courses that teach that outcome.</div></div></div></section>
  <section class="card"><div class="card-h"><h2>Problems to look at</h2><span class="muted small"><?= count($issues) ?></span></div>
    <?php foreach ($issues as $f): ?><div class="check sev-<?= V::h($f['severity']) ?>"><div class="check-ico">!</div><div style="flex:1"><div class="row"><a class="check-title" href="exceptions.php?finding=<?= (int) $f['id'] ?>"><?= V::h($f['title']) ?></a><?= V::category($f['category']) ?></div><div class="check-detail"><?= V::h($f['detail']) ?></div></div></div><?php endforeach; ?>
    <?php if (!$issues): ?><div class="allclear"><strong>✓</strong><div>No problems in this program.</div></div><?php endif; ?></section>
</div><aside class="stack">
  <section class="card"><div class="card-h"><h2>Where this comes from</h2></div><div class="card-b small">
    <p><?= V::h(stripos((string) $p['plan_version'], 'study plan') === 0 ? $p['plan_version'] : 'Study plan ' . $p['plan_version']) ?> · <?= V::h(V::date($p['plan_date'], 'M Y')) ?> <?= V::source('institution') ?><br><a href="<?= V::h($p['source_url']) ?>" target="_blank" rel="noopener noreferrer">Published plan (yu.edu.sa)</a></p>
    <p class="muted"><?= V::h($p['plo_source']) ?></p><?php if ($p['accreditation_note']): ?><p class="muted"><?= V::h($p['accreditation_note']) ?></p><?php endif; ?>
    <a class="btn btn-sm" href="report.php?type=program&id=<?= (int) $p['id'] ?>">Program report</a></div></section>
  <?php foreach (['mission' => 'Program mission', 'goals' => 'Program goals'] as $k => $label): ?>
  <section class="card"><div class="card-h"><h2><?= $label ?></h2></div><div class="card-b small">
    <?php if ($canManage): ?><form data-api="program_narrative"><input type="hidden" name="program" value="<?= (int) $p['id'] ?>"><input type="hidden" name="section" value="<?= $k ?>"><textarea name="content" aria-label="<?= V::h($label) ?>"><?= V::h($narr[$k] ?? '') ?></textarea><button class="btn btn-sm" type="submit" style="margin-top:6px">Save</button></form>
    <?php else: ?><p><?= isset($narr[$k]) ? nl2br(V::h($narr[$k])) : '<span class="muted">Not recorded.</span>' ?></p><?php endif; ?></div></section>
  <?php endforeach; ?>
</aside></div>

<?php elseif ($tab === 'plos'): ?>
<section class="card" id="plos"><div class="card-h"><h2>Program learning outcomes</h2><?= $canManage ? '<span class="muted small right">' . V::h('Before you change an outcome, SAQF shows what it affects; courses are re-checked automatically') . '</span>' : '' ?></div>
  <div class="card-b">
  <?php foreach ($r['plos'] as $pl): $imp = Impact::forPlo((int) $pl['id']); ?>
    <div class="clo"><div class="clo-head"><div class="clo-code"><?= V::h($pl['code']) ?></div><div class="clo-text"><?= V::h($pl['statement']) ?><div class="tiny muted"><?= V::h($pl['domain']) ?> · <?= $pl['source'] === 'institution' ? V::source('institution') : V::source('faculty', 'Kept in SAQF by the department') ?></div>
      <div class="small" style="margin-top:6px"><strong>If you change it:</strong> <?= V::h(count($imp['courses']) === 1 ? '1 course is affected' : count($imp['courses']) . ' courses are affected') ?><?= $imp['courses'] ? ' <span class="muted">(' . V::h(implode(', ', array_keys($imp['courses']))) . ')</span>' : '' ?><?php if ((int) $imp['active_offerings'] || (int) $imp['open_actions']): ?> · <?= V::h((int) $imp['active_offerings'] . ' taught this term') ?> · <?= V::h((int) $imp['open_actions'] . ' improvements under way') ?><?php endif; ?></div></div>
      <?php if ($canManage): ?><details><summary class="btn btn-sm">Change</summary><form data-api="plo_save" style="margin-top:8px;min-width:340px"><input type="hidden" name="program" value="<?= (int) $p['id'] ?>"><input type="hidden" name="code" value="<?= V::h($pl['code']) ?>">
        <div class="field"><textarea name="statement" required><?= V::h($pl['statement']) ?></textarea></div><div class="field"><select name="domain"><?php foreach (Specs::DOMAINS as $d): ?><option <?= $d === $pl['domain'] ? 'selected' : '' ?>><?= V::h($d) ?></option><?php endforeach; ?></select></div>
        <div class="field"><input type="text" name="reason" required placeholder="Why? (e.g. program council decision number)"></div><div class="row"><button class="btn btn-sm btn-primary" type="submit">Save change</button>
        <button class="btn btn-sm btn-red" type="button" data-act="plo_retire" data-program="<?= (int) $p['id'] ?>" data-plo="<?= (int) $pl['id'] ?>" data-prompt="Reason for retiring <?= V::h($pl['code']) ?>?">Retire</button></div></form></details><?php endif; ?>
    </div></div>
  <?php endforeach; ?>
  <?php if (!$r['plos']): ?><p class="muted">The university records have no approved outcomes for this program yet.</p><?php endif; ?>
  <?php if ($canManage): ?><details class="clo" <?= !$r['plos'] ? 'open' : '' ?>><summary class="strong" style="cursor:pointer">+ Add a program outcome</summary><form data-api="plo_save" class="grid g4" style="margin-top:10px;align-items:end"><input type="hidden" name="program" value="<?= (int) $p['id'] ?>">
    <div class="field"><label>Code</label><input type="text" name="code" required placeholder="e.g. K1"></div><div class="field" style="grid-column:span 2"><label>What graduates can do</label><input type="text" name="statement" required></div>
    <div class="field"><label>Type</label><select name="domain"><?php foreach (Specs::DOMAINS as $d): ?><option><?= V::h($d) ?></option><?php endforeach; ?></select></div><div><button class="btn btn-primary btn-sm" type="submit">Add</button></div></form></details><?php endif; ?>
  </div></section>

<?php elseif ($tab === 'plan'):
    $groups = [];
    foreach ($r['plan'] as $e) {
        $key = $e['plan_year'] ? ('Year ' . $e['plan_year'] . ($e['plan_semester'] == 3 ? ' · Summer' : ($e['plan_year'] == 0 ? '' : ' · Semester ' . $e['plan_semester']))) : ('Electives · ' . $e['requirement_group']);
        if ((int) $e['plan_year'] === 0 && $e['plan_year'] !== null) {
            $key = 'Foundation year';
        }
        $groups[$key][] = $e;
    }
    $rules = Db::all('SELECT * FROM elective_rules WHERE program_id = ?', [$p['id']]);
?>
<div class="split"><div class="stack">
<?php foreach ($groups as $label => $rows): ?>
  <section class="card"><div class="card-h"><h2><?= V::h($label) ?></h2><span class="muted small right"><?= V::h(array_sum(array_map(static fn($e) => (float) $e['credits'], array_filter($rows, static fn($e) => $e['course_type'] === 'required' || !$e['course_id']))) . ' credit hours') ?></span></div>
    <div class="card-b tight"><table><tbody><?php foreach ($rows as $e): ?>
      <tr><td style="width:110px"><strong><?= $e['course_id'] ? V::h($e['code']) : '<span class="muted">' . V::h('choice') . '</span>' ?></strong></td><td><?= V::h($e['course_id'] ? $e['title'] : $e['slot_title']) ?><div class="tiny muted"><?= V::h($e['requirement_group']) ?><?= $e['credit_threshold'] ? ' · ' . V::h('after ' . (int) $e['credit_threshold'] . ' credit hours') : '' ?></div></td>
        <td class="num nowrap"><?= V::h(rtrim(rtrim((string) $e['credits'], '0'), '.') . ' credit hours') ?></td><td><?= V::pill(ucfirst((string) $e['course_type']), $e['course_type'] === 'required' ? 'blue' : 'grey') ?></td><td><?= $e['course_id'] ? ($e['spec_version'] ? V::pill('Specification approved', 'green') : '<span class="tiny muted">' . V::h('No specification yet') . '</span>') : '' ?></td></tr>
    <?php endforeach; ?></tbody></table></div></section>
<?php endforeach; ?>
</div><aside class="stack"><section class="card"><div class="card-h"><h2>Choosing electives</h2></div><div class="card-b small"><?php foreach ($rules as $er): ?><p><strong><?= V::h($er['group_name']) ?></strong><br><?= V::h('Choose ' . ((int) $er['courses_required'] ? ((int) $er['courses_required'] === 1 ? '1 course' : (int) $er['courses_required'] . ' courses') . ', ' : '') . (int) $er['credits_required'] . ' credit hours') ?><?= $er['condition_text'] ? '<br><span class="muted">' . V::h($er['condition_text']) . '</span>' : '' ?></p><?php endforeach; ?><?= $rules ? '' : '<span class="muted">None recorded.</span>' ?></div></section></aside></div>

<?php else: ?>
<section class="card"><div class="card-h"><h2>Which course covers what</h2><span class="muted small right">From approved course specifications · each number is how many course outcomes support that program outcome</span></div>
  <div class="card-b tight"><div class="table-wrap"><table class="matrix"><thead><tr><th>Course</th><?php foreach ($r['plos'] as $pl): ?><th title="<?= V::h($pl['statement']) ?>"><?= V::h($pl['code']) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($r['matrix'] as $code => $byPlo): ?><tr><td><strong><?= V::h($code) ?></strong></td><?php foreach ($r['plos'] as $pl): $n = $byPlo[(int) $pl['id']] ?? 0; ?><td><?= $n ? '<span class="heat heat-ok">' . $n . '</span>' : '<span class="dotcell"></span>' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  <tr><td class="muted">Courses teaching it</td><?php foreach ($r['plos'] as $pl): $c = (int) ($coverageCount[(int) $pl['id']] ?? 0); ?><td><span class="heat <?= $c >= Policy::get('program.min_courses_per_plo') ? 'heat-ok' : ($c ? 'heat-prov' : 'heat-low') ?>"><?= $c ?></span></td><?php endforeach; ?></tr>
  </tbody></table></div></div></section>
<?php endif;
V::footer();

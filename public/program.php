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
V::header($p['code'] . ' — ' . $p['name'], $user, ['subtitle' => V::h($p['department_name']) . ' · ' . V::h($p['college_name']) . ' · ' . V::h($p['level']) . ($p['total_credits'] ? ' · ' . (int) $p['total_credits'] . ' credit hours' : '') . ' ' . V::source('institution')]);
echo V::tabs(['overview' => 'Program intelligence', 'plos' => 'PLOs', 'plan' => 'Study plan', 'matrix' => 'Coverage matrix'], $tab, 'program.php?id=' . (int) $p['id']);

if ($tab === 'overview'): ?>
<div class="grid g4" style="margin-bottom:16px"><?php foreach ($r['kpis'] as $k): ?><div class="card kpi"><div class="kpi-v"><?= V::h($k['value']) ?></div><div class="kpi-l"><?= V::h($k['label']) ?></div><div class="kpi-d"><?= V::h($k['detail']) ?></div></div><?php endforeach; ?></div>
<div class="split"><div class="stack">
  <section class="card"><div class="card-h"><h2>PLO achievement by term</h2><span class="muted small right">Emerges from course results automatically · target <?= V::pct($target) ?></span></div>
    <div class="card-b tight"><div class="table-wrap"><table class="matrix"><thead><tr><th>PLO</th><?php foreach ($terms as $name): ?><th><?= V::h($name) ?></th><?php endforeach; ?><th>Courses</th><th>Trend</th></tr></thead><tbody>
    <?php foreach ($r['plos'] as $pl): $series = []; foreach ($r['series'][(int) $pl['id']] ?? [] as $s) { $series[(int) $s['sequence']] = $s; } ?>
      <tr><td><strong><?= V::h($pl['code']) ?></strong> <span class="tiny muted"><?= V::h(mb_strimwidth($pl['statement'], 0, 70, '…')) ?></span></td>
        <?php foreach ($terms as $seq => $name): $s = $series[$seq] ?? null; ?><td><?php if ($s): ?><span class="heat <?= (int) $s['prov'] ? 'heat-prov' : ((float) $s['v'] >= $target ? 'heat-ok' : 'heat-low') ?>" title="<?= (int) $s['n'] ?> offering(s)"><?= V::pct($s['v']) ?></span><?php else: ?><span class="heat heat-none">—</span><?php endif; ?></td><?php endforeach; ?>
        <td><?= (int) ($coverageCount[(int) $pl['id']] ?? 0) ?></td><td><?= V::spark(array_map(static fn($s) => (int) $s['prov'] ? null : (float) $s['v'], array_values($series)), $target) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$r['plos']): ?><tr><td colspan="9"><?= V::empty('No approved PLOs', 'Program achievement cannot be calculated until PLOs are approved.') ?></td></tr><?php endif; ?>
    </tbody></table></div><div class="card-b tiny muted">Grey = provisional (in-term). Each value is the mean of course contributions measured that term.</div></div></section>
  <section class="card"><div class="card-h"><h2>Program issues</h2><span class="muted small"><?= count($issues) ?></span></div>
    <?php foreach ($issues as $f): ?><div class="check sev-<?= V::h($f['severity']) ?>"><div class="check-ico">!</div><div style="flex:1"><div class="row"><a class="check-title" href="exceptions.php?finding=<?= (int) $f['id'] ?>"><?= V::h($f['title']) ?></a><?= V::category($f['category']) ?></div><div class="check-detail"><?= V::h($f['detail']) ?></div></div></div><?php endforeach; ?>
    <?php if (!$issues): ?><div class="allclear"><strong>✓</strong><div>No program-level issues detected.</div></div><?php endif; ?></section>
</div><aside class="stack">
  <section class="card"><div class="card-h"><h2>Source</h2></div><div class="card-b small">
    <p>Study plan <?= V::h($p['plan_version']) ?> (<?= V::h(V::date($p['plan_date'], 'M Y')) ?>) <?= V::source('institution') ?><br><a href="<?= V::h($p['source_url']) ?>" target="_blank" rel="noopener noreferrer">Published plan (yu.edu.sa)</a></p>
    <p class="muted"><?= V::h($p['plo_source']) ?></p><?php if ($p['accreditation_note']): ?><p class="muted"><?= V::h($p['accreditation_note']) ?></p><?php endif; ?>
    <a class="btn btn-sm" href="report.php?type=program&id=<?= (int) $p['id'] ?>">Generated program report</a></div></section>
  <?php foreach (['mission' => 'Program mission', 'goals' => 'Program goals'] as $k => $label): ?>
  <section class="card"><div class="card-h"><h2><?= $label ?></h2></div><div class="card-b small">
    <?php if ($canManage): ?><form data-api="program_narrative"><input type="hidden" name="program" value="<?= (int) $p['id'] ?>"><input type="hidden" name="section" value="<?= $k ?>"><textarea name="content"><?= V::h($narr[$k] ?? '') ?></textarea><button class="btn btn-sm" type="submit" style="margin-top:6px">Save</button></form>
    <?php else: ?><p><?= isset($narr[$k]) ? nl2br(V::h($narr[$k])) : '<span class="muted">Not recorded.</span>' ?></p><?php endif; ?></div></section>
  <?php endforeach; ?>
</aside></div>

<?php elseif ($tab === 'plos'): ?>
<section class="card" id="plos"><div class="card-h"><h2>Program learning outcomes</h2><?= $canManage ? '<span class="muted small right">Changing a PLO shows its impact first; every change is audited and re-validates dependent courses</span>' : '' ?></div>
  <div class="card-b">
  <?php foreach ($r['plos'] as $pl): $imp = Impact::forPlo((int) $pl['id']); ?>
    <div class="clo"><div class="clo-head"><div class="clo-code"><?= V::h($pl['code']) ?></div><div class="clo-text"><?= V::h($pl['statement']) ?><div class="tiny muted"><?= V::h($pl['domain']) ?> · v<?= (int) $pl['version'] ?> · <?= $pl['source'] === 'institution' ? V::source('institution') : V::source('faculty', 'Maintained in SAQF by the department') ?></div>
      <div class="small" style="margin-top:6px"><strong>Impact if changed:</strong> <?= count($imp['courses']) ?> course(s)<?= $imp['courses'] ? ' (' . V::h(implode(', ', array_keys($imp['courses']))) . ')' : '' ?> · <?= (int) $imp['mappings'] ?> CLO mapping(s) · <?= (int) $imp['assessments'] ?> assessment(s) · <?= (int) $imp['active_offerings'] ?> active offering(s) · <?= (int) $imp['open_actions'] ?> open improvement action(s) · <?= (int) $imp['achievement_records'] ?> achievement record(s)</div></div>
      <?php if ($canManage): ?><details><summary class="btn btn-sm">Change</summary><form data-api="plo_save" style="margin-top:8px;min-width:340px"><input type="hidden" name="program" value="<?= (int) $p['id'] ?>"><input type="hidden" name="code" value="<?= V::h($pl['code']) ?>">
        <div class="field"><textarea name="statement" required><?= V::h($pl['statement']) ?></textarea></div><div class="field"><select name="domain"><?php foreach (Specs::DOMAINS as $d): ?><option <?= $d === $pl['domain'] ? 'selected' : '' ?>><?= V::h($d) ?></option><?php endforeach; ?></select></div>
        <div class="field"><input type="text" name="reason" required placeholder="Reason (e.g. program council decision ref.)"></div><div class="row"><button class="btn btn-sm btn-primary" type="submit">Save change</button>
        <button class="btn btn-sm btn-red" type="button" data-act="plo_retire" data-program="<?= (int) $p['id'] ?>" data-plo="<?= (int) $pl['id'] ?>" data-prompt="Reason for retiring <?= V::h($pl['code']) ?>?">Retire</button></div></form></details><?php endif; ?>
    </div></div>
  <?php endforeach; ?>
  <?php if (!$r['plos']): ?><p class="muted">No approved PLOs are recorded for this program in the institutional source.</p><?php endif; ?>
  <?php if ($canManage): ?><details class="clo" <?= !$r['plos'] ? 'open' : '' ?>><summary class="strong" style="cursor:pointer">+ Add a PLO</summary><form data-api="plo_save" class="grid g4" style="margin-top:10px;align-items:end"><input type="hidden" name="program" value="<?= (int) $p['id'] ?>">
    <div class="field"><label>Code</label><input type="text" name="code" required placeholder="e.g. K1"></div><div class="field" style="grid-column:span 2"><label>Statement</label><input type="text" name="statement" required></div>
    <div class="field"><label>Domain</label><select name="domain"><?php foreach (Specs::DOMAINS as $d): ?><option><?= V::h($d) ?></option><?php endforeach; ?></select></div><div><button class="btn btn-primary btn-sm" type="submit">Add PLO</button></div></form></details><?php endif; ?>
  </div></section>

<?php elseif ($tab === 'plan'):
    $groups = [];
    foreach ($r['plan'] as $e) {
        $key = $e['plan_year'] ? ('Year ' . $e['plan_year'] . ($e['plan_semester'] == 3 ? ' · Summer' : ($e['plan_year'] == 0 ? '' : ' · Semester ' . $e['plan_semester']))) : ('Elective pool · ' . $e['requirement_group']);
        if ((int) $e['plan_year'] === 0 && $e['plan_year'] !== null) {
            $key = 'Foundation / pre-program';
        }
        $groups[$key][] = $e;
    }
    $rules = Db::all('SELECT * FROM elective_rules WHERE program_id = ?', [$p['id']]);
?>
<div class="split"><div class="stack">
<?php foreach ($groups as $label => $rows): ?>
  <section class="card"><div class="card-h"><h2><?= V::h($label) ?></h2><span class="muted small right"><?= array_sum(array_map(static fn($e) => (float) $e['credits'], array_filter($rows, static fn($e) => $e['course_type'] === 'required' || !$e['course_id']))) ?> CR listed</span></div>
    <div class="card-b tight"><table><tbody><?php foreach ($rows as $e): ?>
      <tr><td style="width:110px"><strong><?= $e['course_id'] ? V::h($e['code']) : '<span class="muted">slot</span>' ?></strong></td><td><?= V::h($e['course_id'] ? $e['title'] : $e['slot_title']) ?><div class="tiny muted"><?= V::h($e['requirement_group']) ?><?= $e['credit_threshold'] ? ' · requires ' . (int) $e['credit_threshold'] . ' CH completed' : '' ?></div></td>
        <td class="num"><?= V::h(rtrim(rtrim((string) $e['credits'], '0'), '.')) ?> CR</td><td><?= V::pill($e['course_type'], $e['course_type'] === 'required' ? 'blue' : 'grey') ?></td><td><?= $e['course_id'] ? ($e['spec_version'] ? V::pill('spec v' . $e['spec_version'], 'green') : '<span class="tiny muted">no spec yet</span>') : '' ?></td></tr>
    <?php endforeach; ?></tbody></table></div></section>
<?php endforeach; ?>
</div><aside class="stack"><section class="card"><div class="card-h"><h2>Elective rules</h2></div><div class="card-b small"><?php foreach ($rules as $er): ?><p><strong><?= V::h($er['group_name']) ?></strong>: <?= (int) $er['courses_required'] ? (int) $er['courses_required'] . ' course(s), ' : '' ?><?= (int) $er['credits_required'] ?> CR<?= $er['condition_text'] ? '<br><span class="muted">' . V::h($er['condition_text']) . '</span>' : '' ?></p><?php endforeach; ?><?= $rules ? '' : '<span class="muted">None recorded.</span>' ?></div></section></aside></div>

<?php else: ?>
<section class="card"><div class="card-h"><h2>Curriculum coverage matrix</h2><span class="muted small right">From approved course specifications · numbers = CLOs mapped</span></div>
  <div class="card-b tight"><div class="table-wrap"><table class="matrix"><thead><tr><th>Course</th><?php foreach ($r['plos'] as $pl): ?><th title="<?= V::h($pl['statement']) ?>"><?= V::h($pl['code']) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($r['matrix'] as $code => $byPlo): ?><tr><td><strong><?= V::h($code) ?></strong></td><?php foreach ($r['plos'] as $pl): $n = $byPlo[(int) $pl['id']] ?? 0; ?><td><?= $n ? '<span class="heat heat-ok">' . $n . '</span>' : '<span class="dotcell"></span>' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  <tr><td class="muted">Courses contributing</td><?php foreach ($r['plos'] as $pl): $c = (int) ($coverageCount[(int) $pl['id']] ?? 0); ?><td><span class="heat <?= $c >= Policy::get('program.min_courses_per_plo') ? 'heat-ok' : ($c ? 'heat-prov' : 'heat-low') ?>"><?= $c ?></span></td><?php endforeach; ?></tr>
  </tbody></table></div></div></section>
<?php endif;
V::footer();

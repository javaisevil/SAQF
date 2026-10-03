<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Quality\Metrics;
use Saqf\Web\View as V;

$user = saqf_page(['leadership', 'qa']);
$target = Policy::get('plo.target_pct');
$wf = Metrics::workflow();
$auto = Metrics::automation();
$intervene = Db::all(
    'SELECT p.id, p.code, p.short_name, c.name AS college, COUNT(f.id) n, GROUP_CONCAT(DISTINCT f.rule_code) rules
     FROM findings f JOIN programs p ON p.id = f.program_id JOIN departments d ON d.id = p.department_id JOIN colleges c ON c.id = d.college_id
     WHERE f.status = "open" AND f.severity = "warning" AND f.category IN ("academic","quality_risk","data") GROUP BY p.id, p.code, p.short_name, c.name ORDER BY n DESC'
);
$recurring = Db::all('SELECT f.* FROM findings f WHERE f.status = "open" AND f.rule_code IN ("GAP_RECURRING","PLO_PERSISTENT_BELOW") ORDER BY f.rule_code DESC');
$overdue = Db::all('SELECT ia.*, c.code, u.full_name FROM improvement_actions ia JOIN courses c ON c.id = ia.course_id LEFT JOIN users u ON u.id = ia.owner_id WHERE ia.status IN ("open","in_progress") AND ia.due_on < ? ORDER BY ia.due_on', [Clock::today()]);
$cycles = Db::all(
    'SELECT t.name, t.status, COUNT(o.id) offerings, SUM(EXISTS(SELECT 1 FROM clo_achievement ca WHERE ca.offering_id = o.id AND ca.provisional = 0)) measured,
            SUM(EXISTS(SELECT 1 FROM snapshots s WHERE s.kind = "course_report" AND s.scope_id = o.id)) frozen
     FROM terms t LEFT JOIN course_offerings o ON o.term_id = t.id WHERE t.status <> "upcoming" GROUP BY t.id, t.name, t.status, t.sequence ORDER BY t.sequence DESC'
);
$concentration = Db::all(
    'SELECT col.name AS college, d.name AS dept, COUNT(*) gaps FROM clo_achievement ca JOIN course_offerings o ON o.id = ca.offering_id JOIN courses c ON c.id = o.course_id
     JOIN departments d ON d.id = c.owner_department_id JOIN colleges col ON col.id = d.college_id JOIN terms t ON t.id = o.term_id
     WHERE ca.met = 0 AND ca.provisional = 0 AND t.status = "closed" GROUP BY col.name, d.name ORDER BY gaps DESC'
);
$colleges = Db::all('SELECT c.*, (SELECT COUNT(*) FROM programs p JOIN departments d ON d.id = p.department_id WHERE d.college_id = c.id) programs FROM colleges c ORDER BY name');
V::header('University overview', $user, ['subtitle' => V::h('Al Yamamah University') . ' · ' . V::h('where help is needed, what keeps happening, and whether improvements are finished')]);
?>
<div class="grid g4" style="margin-bottom:16px">
  <a class="card kpi <?= $intervene ? 'tone-amber' : 'tone-green' ?>" href="#intervene"><div class="kpi-v"><?= count($intervene) ?></div><div class="kpi-l">programs with problems to look at</div></a>
  <a class="card kpi <?= $recurring ? 'tone-red' : 'tone-green' ?>" href="#risks"><div class="kpi-v"><?= count($recurring) ?></div><div class="kpi-l">problems that keep coming back</div></a>
  <a class="card kpi <?= $overdue ? 'tone-red' : '' ?>" href="#overdue"><div class="kpi-v"><?= count($overdue) ?></div><div class="kpi-l">improvements past their deadline</div></a>
  <div class="card kpi tone-blue"><div class="kpi-v"><?= $wf['no_qa_step_pct'] === null ? '—' : V::pct($wf['no_qa_step_pct']) ?></div><div class="kpi-l">of approvals needed nothing from Quality</div><div class="kpi-d"><?= V::h(number_format($auto['checks']) . ' checks run automatically') ?></div></div>
</div>
<div class="split"><div class="stack">
  <section class="card" id="intervene"><div class="card-h"><h2>Programs that need help</h2></div><div class="card-b tight"><table><tbody>
    <?php foreach ($intervene as $p): ?><tr><td><a class="strong" href="program.php?id=<?= (int) $p['id'] ?>"><?= V::h($p['code']) ?></a> <?= V::h($p['short_name']) ?><div class="tiny muted"><?= V::h($p['college']) ?></div></td><td class="small"><?php foreach (explode(',', (string) $p['rules']) as $r): ?><span class="tag"><?= V::h(\Saqf\Quality\Rules::label($r)) ?></span><?php endforeach; ?></td><td class="num"><?= V::pill((int) $p['n'] === 1 ? '1 problem' : $p['n'] . ' problems', 'amber') ?></td></tr><?php endforeach; ?>
    <?php if (!$intervene): ?><tr><td><?= V::empty('No program needs help') ?></td></tr><?php endif; ?></tbody></table></div></section>
  <section class="card" id="risks"><div class="card-h"><h2>What keeps coming back</h2></div>
    <?php foreach ($recurring as $f): ?><div class="check sev-warning"><div class="check-ico">↻</div><div style="flex:1"><a class="check-title" href="exceptions.php?finding=<?= (int) $f['id'] ?>"><?= V::h($f['title']) ?></a><div class="check-detail"><?= V::h($f['detail']) ?></div></div></div><?php endforeach; ?>
    <?php if (!$recurring): ?><div class="allclear">✓ Nothing keeps coming back.</div><?php endif; ?></section>
  <section class="card" id="overdue"><div class="card-h"><h2>Improvements past their deadline</h2></div><div class="card-b tight"><table><tbody>
    <?php foreach ($overdue as $a): ?><tr><td><strong><?= V::h($a['code']) ?></strong> <?= V::h($a['title']) ?></td><td class="small"><?= V::h($a['full_name']) ?></td><td class="small"><?= V::h('was due') ?> <?= V::h(V::date($a['due_on'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$overdue): ?><tr><td class="muted">Nothing is late.</td></tr><?php endif; ?></tbody></table></div></section>
</div><aside class="stack">
  <section class="card"><div class="card-h"><h2>Term by term</h2></div><div class="card-b tight"><table class="compact"><thead><tr><th>Term</th><th class="num">Courses</th><th class="num" title="Courses with final results">With results</th><th class="num" title="Course reports sealed when the term closed">Reports sealed</th></tr></thead><tbody>
    <?php foreach ($cycles as $c): ?><tr><td class="nowrap"><?= V::h($c['name']) ?><?= $c['status'] === 'active' ? '<div>' . V::pill('current', 'blue') . '</div>' : '' ?></td><td class="num"><?= (int) $c['offerings'] ?></td><td class="num"><?= (int) $c['measured'] ?></td><td class="num"><?= (int) $c['frozen'] ?></td></tr><?php endforeach; ?></tbody></table></div></section>
  <section class="card"><div class="card-h"><h2>Where outcomes fall short most</h2><span class="muted small">finished terms</span></div><div class="card-b tight"><table><tbody>
    <?php foreach ($concentration as $c): ?><tr><td class="small"><?= V::h($c['dept']) ?><div class="tiny muted"><?= V::h($c['college']) ?></div></td><td class="num nowrap" title="Course learning outcomes that missed their goal"><?= V::h((int) $c['gaps'] === 1 ? '1 outcome below goal' : $c['gaps'] . ' outcomes below goal') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
  <section class="card"><div class="card-h"><h2>Colleges</h2></div><div class="card-b small"><?php foreach ($colleges as $c): ?><div><a href="college.php?college=<?= (int) $c['id'] ?>"><?= V::h($c['name']) ?></a> <span class="muted">· <?= V::h((int) $c['programs'] === 1 ? '1 program' : $c['programs'] . ' programs') ?></span></div><?php endforeach; ?></div></section>
</aside></div>
<?php V::footer();

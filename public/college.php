<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Quality\ActionCenter;
use Saqf\Quality\Metrics;
use Saqf\Quality\Status;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['dean', 'leadership', 'qa']);
$collegeId = $user['role'] === 'dean' ? (int) $user['scope_college_id'] : (int) ($_GET['college'] ?? 0);
$college = Db::one('SELECT * FROM colleges WHERE id = ?', [$collegeId]);
if (!$college) {
    Authz::deny('college view', 404);
}
$deptFilter = (int) ($_GET['dept'] ?? 0);
$depts = Db::all('SELECT * FROM departments WHERE college_id = ? ORDER BY name', [$collegeId]);
$target = Policy::get('plo.target_pct');
$actions = $user['role'] === 'dean' ? ActionCenter::forUser($user) : [];
$wf = Metrics::workflow($collegeId);
$rows = [];
foreach ($depts as $d) {
    $offs = Db::all('SELECT o.id, o.course_id, c.code, c.title, u.full_name AS instructor FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id LEFT JOIN users u ON u.id = o.instructor_id WHERE t.status = "active" AND c.owner_department_id = ? ORDER BY c.code', [$d['id']]);
    $attention = 0;
    foreach ($offs as $i => $o) {
        $offs[$i]['st'] = Status::forOffering($o);
        $attention += $offs[$i]['st']['state'] !== 'ready' ? 1 : 0;
    }
    $rows[] = [
        'dept' => $d, 'offerings' => $offs, 'attention' => $attention,
        'exceptions' => (int) Db::val('SELECT COUNT(*) FROM findings WHERE status = "open" AND owner_role IN ("hod","dean") AND department_id = ? AND severity <> "info"', [$d['id']]),
        'overdue' => (int) Db::val('SELECT COUNT(*) FROM improvement_actions ia JOIN courses c ON c.id = ia.course_id WHERE c.owner_department_id = ? AND ia.status IN ("open","in_progress") AND ia.due_on < ?', [$d['id'], Clock::today()]),
        'approvals' => (int) Db::val('SELECT COUNT(*) FROM spec_versions sv JOIN courses c ON c.id = sv.course_id WHERE sv.status = "pending_hod" AND c.owner_department_id = ?', [$d['id']]),
    ];
}
$programs = Db::all('SELECT p.* FROM programs p JOIN departments d ON d.id = p.department_id WHERE d.college_id = ? ORDER BY p.level DESC, p.code', [$collegeId]);
$ia = Db::one('SELECT SUM(ia.status IN ("open","in_progress","completed")) committed, SUM(ia.status = "completed") completed, SUM(ia.effect = "improved") improved, SUM(ia.effect IN ("improved","similar","declined")) evaluated FROM improvement_actions ia JOIN courses c ON c.id = ia.course_id JOIN departments d ON d.id = c.owner_department_id WHERE d.college_id = ?', [$collegeId]);
$persistent = Db::all('SELECT f.*, p.code AS pcode FROM findings f JOIN programs p ON p.id = f.program_id WHERE f.status = "open" AND f.rule_code IN ("PLO_PERSISTENT_BELOW","GAP_RECURRING") AND f.college_id = ?', [$collegeId]);
V::header($college['name'], $user, ['subtitle' => 'College quality — start with decisions; drill down to department, program, course and outcome only when needed']);
?>
<div class="grid g4" style="margin-bottom:16px">
  <div class="card kpi <?= $persistent ? 'tone-red' : 'tone-green' ?>"><div class="kpi-v"><?= count($persistent) ?></div><div class="kpi-l">persistent or recurring issues</div><div class="kpi-d">patterns across terms, not one-offs</div></div>
  <div class="card kpi"><div class="kpi-v"><?= array_sum(array_column($rows, 'attention')) ?> / <?= array_sum(array_map(static fn($r) => count($r['offerings']), $rows)) ?></div><div class="kpi-l">courses needing attention this term</div></div>
  <div class="card kpi"><div class="kpi-v"><?= (int) $ia['committed'] ? round($ia['completed'] / $ia['committed'] * 100) . '%' : '—' ?></div><div class="kpi-l">improvement actions completed</div><div class="kpi-d"><?= (int) $ia['improved'] ?> of <?= (int) $ia['evaluated'] ?> evaluated actions were followed by improvement</div></div>
  <div class="card kpi"><div class="kpi-v"><?= $wf['no_qa_step_pct'] === null ? '—' : $wf['no_qa_step_pct'] . '%' ?></div><div class="kpi-l">approvals without a QA step</div><div class="kpi-d">median <?= $wf['median_hours'] === null ? '—' : V::h((string) $wf['median_hours']) . ' h' ?> to decision · <?= V::h((string) ($wf['avg_return_rounds'] ?? '—')) ?> avg return rounds</div></div>
</div>
<div class="split"><div class="stack">
  <?php if ($actions || $persistent): ?><section class="card"><div class="card-h"><h2>Needs a dean-level view</h2></div>
    <ul class="actions"><?php foreach ($actions as $a): ?><li class="action"><span class="prio prio-<?= (int) $a['priority'] ?>"></span><div class="action-main"><div class="action-title"><?= V::h($a['title']) ?></div><div class="action-reason"><?= V::h($a['reason']) ?></div></div><a class="btn btn-sm btn-primary" href="<?= V::h($a['link']) ?>"><?= V::h($a['cta']) ?></a></li><?php endforeach; ?>
    <?php if (!$actions): foreach ($persistent as $f): ?><li class="action"><span class="prio prio-2"></span><div class="action-main"><div class="action-title"><?= V::h($f['title']) ?></div><div class="action-reason"><?= V::h($f['detail']) ?></div></div><a class="btn btn-sm" href="exceptions.php?finding=<?= (int) $f['id'] ?>">Open</a></li><?php endforeach; endif; ?></ul></section><?php endif; ?>
  <section class="card"><div class="card-h"><h2>Departments</h2><span class="muted small">Active term</span></div><div class="card-b tight"><table>
    <thead><tr><th>Department</th><th class="num">Courses</th><th class="num">Need attention</th><th class="num">Exceptions</th><th class="num">Overdue actions</th><th class="num">Approvals waiting</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><strong><?= V::h($r['dept']['name']) ?></strong></td><td class="num"><?= count($r['offerings']) ?></td><td class="num"><?= $r['attention'] ? V::pill((string) $r['attention'], 'amber') : '0' ?></td><td class="num"><?= $r['exceptions'] ? V::pill((string) $r['exceptions'], 'red') : '0' ?></td><td class="num"><?= $r['overdue'] ?: '0' ?></td><td class="num"><?= $r['approvals'] ?: '0' ?></td><td class="num nowrap"><?php if ($r['offerings']): ?><a href="?college=<?= $collegeId ?>&dept=<?= (int) $r['dept']['id'] ?>#dept">Drill down</a><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
  <?php if ($deptFilter): foreach ($rows as $r): if ((int) $r['dept']['id'] !== $deptFilter) { continue; } ?>
  <section class="card" id="dept"><div class="card-h"><h2><?= V::h($r['dept']['name']) ?> — courses this term</h2></div><div class="card-b tight"><table><tbody>
    <?php foreach ($r['offerings'] as $o): ?><tr><td><strong><?= V::h($o['code']) ?></strong> <span class="muted"><?= V::h($o['title']) ?></span></td><td class="small"><?= V::h($o['instructor'] ?? '—') ?></td><td><?= V::pill($o['st']['label'], $o['st']['tone']) ?></td><td class="small"><?= V::h($o['st']['reasons'][0][1] ?? '') ?></td><td class="num"><a class="btn btn-sm" href="workspace.php?id=<?= (int) $o['id'] ?>">Open</a></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
  <?php endforeach; endif; ?>
</div><aside class="stack">
  <?php foreach ($programs as $p):
      $latest = Db::one('SELECT t.id, t.name FROM plo_achievement pa JOIN course_offerings o ON o.id = pa.offering_id JOIN terms t ON t.id = o.term_id WHERE pa.program_id = ? AND pa.provisional = 0 ORDER BY t.sequence DESC LIMIT 1', [$p['id']]);
      $vals = $latest ? Db::all('SELECT pl.code, AVG(pa.value_pct) v FROM plo_achievement pa JOIN course_offerings o ON o.id = pa.offering_id JOIN plos pl ON pl.id = pa.plo_id WHERE pa.program_id = ? AND o.term_id = ? AND pa.provisional = 0 GROUP BY pl.code ORDER BY pl.code', [$p['id'], $latest['id']]) : [];
      $plos = (int) Db::val('SELECT COUNT(*) FROM plos WHERE program_id = ? AND status = "approved"', [$p['id']]); ?>
    <a class="card ccard" href="program.php?id=<?= (int) $p['id'] ?>" style="display:block"><div class="row between"><span class="ccard-code"><?= V::h($p['code']) ?></span><span class="tiny muted"><?= V::h($p['level']) ?></span></div><div class="ccard-title"><?= V::h($p['short_name']) ?></div>
      <div class="row" style="gap:4px;margin-top:6px"><?php foreach ($vals as $v): ?><span class="heat <?= (float) $v['v'] >= $target ? 'heat-ok' : 'heat-low' ?>"><?= V::h($v['code']) ?> <?= round((float) $v['v']) ?></span><?php endforeach; ?></div>
      <div class="ccard-meta"><?= $latest ? V::h($latest['name']) : ($plos ? 'No measured results yet' : 'No approved PLOs') ?></div></a>
  <?php endforeach; ?>
</aside></div>
<?php V::footer();

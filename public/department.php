<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Quality\ActionCenter;
use Saqf\Quality\Status;
use Saqf\Web\View as V;

$user = saqf_page(['hod']);
$dept = (int) $user['department_id'];
$actions = ActionCenter::forUser($user);
$offerings = Db::all(
    'SELECT o.*, c.code, c.title, u.full_name AS instructor, t.name AS term_name FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id
     LEFT JOIN users u ON u.id = o.instructor_id WHERE t.status = "active" AND c.owner_department_id = ? ORDER BY c.code',
    [$dept]
);
$attention = [];
$ready = [];
foreach ($offerings as $o) {
    $st = Status::forOffering($o);
    $o['st'] = $st;
    if ($st['state'] === 'ready') {
        $ready[] = $o;
    } else {
        $attention[] = $o;
    }
}
$order = array_flip(array_keys(Status::STATES));
usort($attention, static fn($a, $b) => $order[$a['st']['state']] <=> $order[$b['st']['state']]);
$approvals = (int) Db::val('SELECT COUNT(*) FROM spec_versions sv JOIN courses c ON c.id = sv.course_id WHERE sv.status = "pending_hod" AND c.owner_department_id = ?', [$dept]);
$exceptions = (int) Db::val('SELECT COUNT(*) FROM findings WHERE status = "open" AND owner_role = "hod" AND department_id = ? AND severity <> "info"', [$dept]);
$actionsOpen = Db::one('SELECT SUM(ia.status IN ("open","in_progress")) open_n, SUM(ia.status IN ("open","in_progress") AND ia.due_on < ?) overdue, SUM(ia.status = "draft") drafts FROM improvement_actions ia JOIN courses c ON c.id = ia.course_id WHERE c.owner_department_id = ?', [Clock::today(), $dept]);
$programs = Db::all('SELECT * FROM programs WHERE department_id = ? ORDER BY level DESC, code', [$dept]);
$target = Policy::get('plo.target_pct');

V::header('My department', $user, ['subtitle' => V::h($user['department_name']) . ' · ' . V::h('only what needs you is shown; the rest runs by itself')]);
?>
<div class="grid g4" style="margin-bottom:16px">
  <a class="card kpi <?= $attention ? 'tone-amber' : 'tone-green' ?>" href="#attention"><div class="kpi-v"><?= count($attention) ?></div><div class="kpi-l"><?= V::h('of ' . count($offerings) . ' courses need attention') ?></div><div class="kpi-d"><?= V::h(count($ready) === 1 ? 'The other one is fine' : 'The other ' . count($ready) . ' are fine') ?></div></a>
  <a class="card kpi <?= $approvals ? 'tone-blue' : '' ?>" href="approvals.php"><div class="kpi-v"><?= $approvals ?></div><div class="kpi-l">changes waiting for your approval</div><div class="kpi-d">SAQF has already checked them</div></a>
  <a class="card kpi <?= $exceptions ? 'tone-red' : '' ?>" href="exceptions.php"><div class="kpi-v"><?= $exceptions ?></div><div class="kpi-l">department problems to sort out</div><div class="kpi-d">repeated gaps, program outcomes, late actions</div></a>
  <a class="card kpi" href="improvements.php"><div class="kpi-v"><?= (int) $actionsOpen['open_n'] ?></div><div class="kpi-l">improvements under way</div><div class="kpi-d"><?= V::h((int) $actionsOpen['overdue'] . ' late') ?> · <?= V::h((int) $actionsOpen['drafts'] . ' waiting for the instructor') ?></div></a>
</div>
<div class="split">
  <div class="stack">
    <section class="card"><div class="card-h"><h2>What needs you</h2><span class="muted small"><?= count($actions) === 1 ? 'One thing to do' : V::h(count($actions) . ' things to do') ?></span></div>
      <?php if (!$actions): ?><div class="allclear"><strong>✓</strong><div><strong>Nothing needs your decision.</strong><div class="muted small">SAQF keeps checking every course and will list anything new here.</div></div></div><?php endif; ?>
      <ul class="actions"><?php foreach ($actions as $a): ?>
        <li class="action"><span class="prio prio-<?= (int) $a['priority'] ?>"></span><div class="action-main"><div class="action-title"><?= V::h($a['title']) ?></div><div class="action-reason"><?= V::h($a['reason']) ?></div><div class="action-meta"><?= V::h($a['context']) ?></div></div><a class="btn btn-sm <?= $a['priority'] === 1 ? 'btn-primary' : '' ?>" href="<?= V::h($a['link']) ?>"><?= V::h($a['cta']) ?></a></li>
      <?php endforeach; ?></ul>
    </section>
    <section class="card" id="attention"><div class="card-h"><h2>Courses that need attention</h2><span class="muted small">This term · your department's courses</span></div>
      <div class="card-b tight"><table><thead><tr><th>Course</th><th>Instructor</th><th>Status</th><th>Why</th><th></th></tr></thead><tbody>
      <?php foreach ($attention as $o): ?>
        <tr><td><strong><?= V::h($o['code']) ?></strong><div class="tiny muted"><?= V::h($o['title']) ?></div></td><td class="small"><?= V::h($o['instructor'] ?? '—') ?></td><td><?= V::pill($o['st']['label'], $o['st']['tone']) ?></td>
          <td class="small"><?= V::h($o['st']['reasons'][0][1] ?? '') ?><?= count($o['st']['reasons']) > 1 ? ' <span class="muted">' . V::h('+' . (count($o['st']['reasons']) - 1) . ' more') . '</span>' : '' ?></td><td class="num"><a class="btn btn-sm" href="workspace.php?id=<?= (int) $o['id'] ?>">Open</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$attention): ?><tr><td colspan="5"><?= V::empty('Every course is fine', 'Nothing in your department needs you this term.') ?></td></tr><?php endif; ?>
      </tbody></table>
      <?php if ($ready): ?><details class="more"><summary>✓ <?= V::h(count($ready) === 1 ? '1 course is fine' : count($ready) . ' courses are fine') ?> · <?= V::h('nothing to do') ?></summary><div class="card-b small"><?php foreach ($ready as $o): ?><a class="tag" href="workspace.php?id=<?= (int) $o['id'] ?>"><?= V::h($o['code']) ?> · <?= V::h($o['instructor'] ?? '') ?></a><?php endforeach; ?></div></details><?php endif; ?>
      </div></section>
  </div>
  <aside class="stack">
    <?php if ($programs): ?><div class="small muted">Your programs: how students did on each program learning outcome in the latest finished term.
      <div class="legend"><span class="lg-green"><?= V::h('met the ' . V::pct($target) . ' goal') ?></span><span class="lg-red">below the goal</span><span>no results yet</span></div></div><?php endif; ?>
    <?php foreach ($programs as $p):
        $plos = Db::all('SELECT id, code, statement FROM plos WHERE program_id = ? AND status = "approved" ORDER BY code', [$p['id']]);
        $latest = Db::one('SELECT t.id, t.name FROM plo_achievement pa JOIN course_offerings o ON o.id = pa.offering_id JOIN terms t ON t.id = o.term_id WHERE pa.program_id = ? AND pa.provisional = 0 ORDER BY t.sequence DESC LIMIT 1', [$p['id']]);
        $vals = [];
        if ($latest) {
            foreach (Db::all('SELECT pa.plo_id, AVG(pa.value_pct) v FROM plo_achievement pa JOIN course_offerings o ON o.id = pa.offering_id WHERE pa.program_id = ? AND o.term_id = ? AND pa.provisional = 0 GROUP BY pa.plo_id', [$p['id'], $latest['id']]) as $r) {
                $vals[(int) $r['plo_id']] = (float) $r['v'];
            }
        }
        $open = (int) Db::val('SELECT COUNT(*) FROM findings WHERE program_id = ? AND status = "open" AND severity <> "info"', [$p['id']]);
    ?>
      <a class="card ccard" href="program.php?id=<?= (int) $p['id'] ?>" style="display:block">
        <div class="row between"><span class="ccard-code"><?= V::h($p['code']) ?></span><?= $open ? V::pill($open === 1 ? '1 problem' : $open . ' problems', 'amber') : V::pill('No problems', 'green') ?></div>
        <div class="ccard-title"><?= V::h($p['short_name']) ?> <span class="muted small">· <?= V::h(ucfirst((string) $p['level'])) ?></span></div>
        <div class="row" style="margin-top:8px;gap:4px"><?php foreach ($plos as $pl): $v = $vals[(int) $pl['id']] ?? null; ?><span class="heat <?= $v === null ? 'heat-none' : ($v >= $target ? 'heat-ok' : 'heat-low') ?>" title="<?= V::h($pl['code'] . ': ' . $pl['statement']) ?>"><?= V::h($pl['code']) ?><?= $v !== null ? ' ' . V::pct($v) : '' ?></span><?php endforeach; ?></div>
        <div class="ccard-meta"><?= $latest ? V::h('Results from') . ' ' . V::h($latest['name']) : V::h('No results yet') ?><?= !$plos ? ' · ' . V::h('no approved program outcomes') : '' ?></div>
      </a>
    <?php endforeach; ?>
  </aside>
</div>
<?php V::footer();

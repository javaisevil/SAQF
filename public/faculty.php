<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Quality\ActionCenter;
use Saqf\Quality\Sections;
use Saqf\Quality\Status;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['faculty']);
$actions = ActionCenter::forUser($user);
$current = Db::all(
    'SELECT o.*, c.code, c.title, c.credits, t.name AS term_name, t.status AS term_status, sv.version_no, cu.full_name AS coordinator_name
     FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id LEFT JOIN spec_versions sv ON sv.id = o.spec_version_id LEFT JOIN users cu ON cu.id = o.instructor_id
     WHERE ' . Authz::teachesSql('o') . ' AND t.status <> "closed" ORDER BY t.sequence DESC, c.code',
    [$user['id'], $user['id']]
);
$past = Db::all(
    'SELECT o.id, c.code, c.title, t.name AS term_name, (SELECT COUNT(*) FROM clo_achievement ca WHERE ca.offering_id = o.id AND ca.met = 0 AND ca.provisional = 0) AS gaps,
            (SELECT COUNT(*) FROM clo_achievement ca WHERE ca.offering_id = o.id) AS measured
     FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id
     WHERE ' . Authz::teachesSql('o') . ' AND t.status = "closed" ORDER BY t.sequence DESC, c.code',
    [$user['id'], $user['id']]
);
$ids = array_map(static fn($o) => (int) $o['id'], $current);
$ledger = [];
if ($ids) {
    foreach (Db::all('SELECT kind, SUM(quantity) q FROM automation_ledger WHERE offering_id IN (' . Db::in($ids) . ') GROUP BY kind', $ids) as $r) {
        $ledger[$r['kind']] = (int) $r['q'];
    }
}
$first = explode(' ', preg_replace('/^(Dr\.|Prof\.)\s*/', '', $user['full_name']))[0];
V::header('My courses', $user, ['subtitle' => V::h($user['title'] . ' · ' . ($user['department_name'] ?? ''))]);
?>
<div class="split">
  <div class="stack">
    <section class="card">
      <div class="card-h"><h2>What needs you</h2><span class="muted small"><?= count($actions) === 1 ? 'One thing to do' : V::h(count($actions) . ' things to do') ?> · SAQF handles the rest</span><a class="btn btn-sm right" href="calendar.php" title="<?= V::h('A calendar file with your deadlines, for Outlook, Google or Apple Calendar') ?>"><?= V::h('Add my deadlines to my calendar') ?></a></div>
      <?php if (!$actions): ?>
        <div class="allclear"><strong>✓</strong><div><strong>Nothing needs you right now, <?= V::h($first) ?>.</strong><div class="muted small">SAQF keeps checking your courses and will list anything new here.</div></div></div>
      <?php else: ?>
        <ul class="actions">
          <?php foreach ($actions as $a): ?>
          <li class="action"><span class="prio prio-<?= (int) $a['priority'] ?>" title="<?= V::h([1 => 'Do this first', 2 => 'Soon', 3 => 'When you can'][(int) $a['priority']] ?? 'When you can') ?>"></span>
            <div class="action-main"><div class="action-title"><?= V::h($a['title']) ?></div><div class="action-reason"><?= V::h($a['reason']) ?></div>
              <div class="action-meta"><?= V::h($a['context']) ?><?= $a['due'] ? ' · ' . V::h('by') . ' ' . V::h(V::date($a['due'])) : '' ?></div></div>
            <a class="btn btn-sm <?= $a['priority'] === 1 ? 'btn-primary' : '' ?>" href="<?= V::h($a['link']) ?>"><?= V::h($a['cta']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-h"><h2>My courses this term</h2><span class="muted small">Added for you from the university timetable</span></div>
      <div class="grid g2" style="padding:14px">
        <?php foreach ($current as $o): $st = Status::forOffering($o); $mySections = Sections::taughtBy((int) $o['id'], $user['id']); $sectionCount = count(Sections::forOffering((int) $o['id'])); $coordinator = (int) $o['instructor_id'] === $user['id']; ?>
          <a class="card ccard tone-<?= V::h($st['tone']) ?>" href="workspace.php?id=<?= (int) $o['id'] ?>">
            <div class="row between"><span class="ccard-code"><?= V::h($o['code']) ?></span><?= V::pill($st['label'], $st['tone']) ?></div>
            <div class="ccard-title"><?= V::h($o['title']) ?></div>
            <div class="ccard-meta"><?= V::h($o['term_name']) ?> · <?= V::h(V::count((int) $o['enrolled'], 'student', 'students')) ?> · <?= (int) $o['credits'] ?> <?= V::h((int) $o['credits'] === 1 ? 'credit hour' : 'credit hours') ?></div>
            <div class="ccard-meta"><?= $o['version_no'] ? '✓ ' . V::h('Course specification carried over') : V::h('Course specification needed') ?></div>
            <?php if ($sectionCount > 1): ?><div class="ccard-meta"><?= $coordinator ? V::pill('You coordinate', 'blue') . ' ' . V::h($sectionCount . ' sections') : V::pill('Section ' . implode(', ', $mySections), 'grey') . ' <span>' . V::h('Coordinator') . ': ' . V::h($o['coordinator_name'] ?? '—') . '</span>' ?></div><?php endif; ?>
            <?php if ($st['reasons']): ?><div class="ccard-meta"><?= V::h($st['reasons'][0][1]) ?><?= count($st['reasons']) > 1 ? ' <span class="muted">' . V::h('+' . (count($st['reasons']) - 1) . ' more') . '</span>' : '' ?></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if (!$current): ?><?= V::empty('No courses this term', 'When the university timetable gives you a course, it appears here by itself.') ?><?php endif; ?>
      </div>
    </section>

    <?php if ($past): ?>
    <section class="card">
      <div class="card-h"><h2>Previous terms</h2><span class="muted small">Kept for reference</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Course</th><th>Term</th><th>Learning outcomes</th><th></th></tr></thead><tbody>
        <?php foreach ($past as $p): ?>
          <tr><td><strong><?= V::h($p['code']) ?></strong> <span class="muted"><?= V::h($p['title']) ?></span></td><td><?= V::h($p['term_name']) ?></td>
            <td><?= $p['measured'] ? ((int) $p['gaps'] ? V::pill((int) $p['gaps'] === 1 ? '1 below its goal' : $p['gaps'] . ' below their goal', 'amber') : V::pill('All goals met', 'green')) : '<span class="muted">' . V::h('No grades') . '</span>' ?></td>
            <td class="num"><a href="workspace.php?id=<?= (int) $p['id'] ?>">Open</a> · <a href="report.php?type=course&id=<?= (int) $p['id'] ?>">Report</a></td></tr>
        <?php endforeach; ?></tbody></table></div>
    </section>
    <?php endif; ?>
  </div>

  <aside class="stack">
    <section class="card"><div class="card-h"><h2>Done for you this term</h2></div>
      <div class="card-b">
        <p class="small" style="margin-top:0">You only make the teaching decisions. SAQF did the rest:</p>
        <ul class="done-list">
          <?php foreach ([
              'field_populated' => ['detail filled in from university records', 'details filled in from university records'],
              'record_inherited' => ['item carried over from your approved specification', 'items carried over from your approved specifications'],
              'check_run' => ['check run on your courses', 'checks run on your courses'],
              'calculation' => ['result worked out from grades', 'results worked out from grades'],
              'evidence_linked' => ['grade linked to a learning outcome', 'grades linked to learning outcomes'],
              'auto_resolved' => ['problem fixed by itself', 'problems fixed by themselves'],
          ] as $kind => [$one, $many]): $n = (int) ($ledger[$kind] ?? 0); if (!$n) { continue; } ?>
            <li><b><?= number_format($n) ?></b> <span><?= V::h($n === 1 ? $one : $many) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!$ledger): ?><p class="small muted">Nothing yet this term.</p><?php endif; ?>
        <p class="tiny muted" style="margin-top:10px">Real counts, not estimates.</p>
      </div>
    </section>
    <section class="card"><div class="card-h"><h2>Where things come from</h2></div>
      <div class="card-b small">
        <p><?= V::source('institution') ?> Course name, credit hours, programs and prerequisites.</p>
        <p><?= V::source('inherited') ?> Your approved learning outcomes and assessments, every term.</p>
        <p><?= V::source('lms') ?> Grades, as they are entered. <?= V::source('calculated') ?> How students did.</p>
        <p style="margin:0"><?= V::source('faculty') ?> You: your outcomes, your comments and what to improve.</p>
      </div>
    </section>
  </aside>
</div>
<?php V::footer();

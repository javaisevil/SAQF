<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Core\Ledger;
use Saqf\Quality\ActionCenter;
use Saqf\Quality\Status;
use Saqf\Web\View as V;

$user = saqf_page(['faculty']);
$actions = ActionCenter::forUser($user);
$current = Db::all(
    'SELECT o.*, c.code, c.title, c.credits, t.name AS term_name, t.status AS term_status, sv.version_no
     FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id LEFT JOIN spec_versions sv ON sv.id = o.spec_version_id
     WHERE o.instructor_id = ? AND t.status <> "closed" ORDER BY t.sequence DESC, c.code',
    [$user['id']]
);
$past = Db::all(
    'SELECT o.id, c.code, c.title, t.name AS term_name, (SELECT COUNT(*) FROM clo_achievement ca WHERE ca.offering_id = o.id AND ca.met = 0 AND ca.provisional = 0) AS gaps,
            (SELECT COUNT(*) FROM clo_achievement ca WHERE ca.offering_id = o.id) AS measured
     FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id
     WHERE o.instructor_id = ? AND t.status = "closed" ORDER BY t.sequence DESC, c.code',
    [$user['id']]
);
$ids = array_map(static fn($o) => (int) $o['id'], $current);
$ledger = [];
if ($ids) {
    foreach (Db::all('SELECT kind, SUM(quantity) q FROM automation_ledger WHERE offering_id IN (' . Db::in($ids) . ') GROUP BY kind', $ids) as $r) {
        $ledger[$r['kind']] = (int) $r['q'];
    }
}
$first = explode(' ', preg_replace('/^(Dr\.|Prof\.)\s*/', '', $user['full_name']))[0];
V::header('My actions & courses', $user, ['subtitle' => V::h($user['title'] . ' · ' . ($user['department_name'] ?? ''))]);
?>
<div class="split">
  <div class="stack">
    <section class="card">
      <div class="card-h"><h2>What needs you</h2><span class="muted small"><?= count($actions) ?> item<?= count($actions) === 1 ? '' : 's' ?> · everything else is handled</span></div>
      <?php if (!$actions): ?>
        <div class="allclear"><strong>✓</strong><div><strong>Nothing needs you right now, <?= V::h($first) ?>.</strong><div class="muted small">SAQF keeps validating your courses in the background and will bring anything new to this list.</div></div></div>
      <?php else: ?>
        <ul class="actions">
          <?php foreach ($actions as $a): ?>
          <li class="action"><span class="prio prio-<?= (int) $a['priority'] ?>" title="Priority <?= (int) $a['priority'] ?>"></span>
            <div class="action-main"><div class="action-title"><?= V::h($a['title']) ?></div><div class="action-reason"><?= V::h($a['reason']) ?></div>
              <div class="action-meta"><?= V::h($a['context']) ?><?= $a['due'] ? ' · due ' . V::h(V::date($a['due'])) : '' ?></div></div>
            <a class="btn btn-sm <?= $a['priority'] === 1 ? 'btn-primary' : '' ?>" href="<?= V::h($a['link']) ?>"><?= V::h($a['cta']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-h"><h2>My courses this term</h2><span class="muted small">Assigned automatically from the SIS — you never create a course</span></div>
      <div class="grid g2" style="padding:14px">
        <?php foreach ($current as $o): $st = Status::forOffering($o); ?>
          <a class="card ccard tone-<?= V::h($st['tone']) ?>" href="workspace.php?id=<?= (int) $o['id'] ?>">
            <div class="row between"><span class="ccard-code"><?= V::h($o['code']) ?></span><?= V::pill($st['label'], $st['tone']) ?></div>
            <div class="ccard-title"><?= V::h($o['title']) ?></div>
            <div class="ccard-meta"><?= V::h($o['term_name']) ?> · <?= (int) $o['enrolled'] ?> students · <?= $o['version_no'] ? 'specification v' . (int) $o['version_no'] . ' inherited' : 'first specification needed' ?></div>
            <?php if ($st['reasons']): ?><div class="ccard-meta"><?= V::h($st['reasons'][0][1]) ?><?= count($st['reasons']) > 1 ? ' (+' . (count($st['reasons']) - 1) . ' more)' : '' ?></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if (!$current): ?><?= V::empty('No courses assigned this term', 'When the Registrar assigns you a course, its quality workspace appears here automatically.') ?><?php endif; ?>
      </div>
    </section>

    <?php if ($past): ?>
    <section class="card">
      <div class="card-h"><h2>Previous terms</h2><span class="muted small">Frozen records — institutional memory</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Course</th><th>Term</th><th>Outcomes</th><th></th></tr></thead><tbody>
        <?php foreach ($past as $p): ?>
          <tr><td><strong><?= V::h($p['code']) ?></strong> <span class="muted"><?= V::h($p['title']) ?></span></td><td><?= V::h($p['term_name']) ?></td>
            <td><?= $p['measured'] ? ((int) $p['gaps'] ? V::pill($p['gaps'] . ' below target', 'amber') : V::pill('All targets met', 'green')) : '<span class="muted">No results</span>' ?></td>
            <td class="num"><a href="workspace.php?id=<?= (int) $p['id'] ?>">Workspace</a> · <a href="report.php?type=course&id=<?= (int) $p['id'] ?>">Report</a></td></tr>
        <?php endforeach; ?></tbody></table></div>
    </section>
    <?php endif; ?>
  </div>

  <aside class="stack">
    <section class="card"><div class="card-h"><h2>Done for you this term</h2></div>
      <div class="card-b">
        <div class="ledger">
          <div><b><?= (int) ($ledger['field_populated'] ?? 0) ?></b><span>fields populated from university data</span></div>
          <div><b><?= (int) ($ledger['record_inherited'] ?? 0) ?></b><span>records inherited from approved specs</span></div>
          <div><b><?= (int) ($ledger['check_run'] ?? 0) ?></b><span>quality checks run automatically</span></div>
          <div><b><?= (int) ($ledger['calculation'] ?? 0) ?></b><span>achievement values calculated</span></div>
          <div><b><?= (int) ($ledger['evidence_linked'] ?? 0) ?></b><span>results linked to outcomes as evidence</span></div>
          <div><b><?= (int) ($ledger['auto_resolved'] ?? 0) ?></b><span>issues cleared automatically</span></div>
        </div>
        <p class="tiny muted" style="margin-top:10px">Factual counts from SAQF's automation ledger — not estimated time savings.</p>
      </div>
    </section>
    <section class="card"><div class="card-h"><h2>How SAQF works with you</h2></div>
      <div class="card-b small">
        <p><?= V::source('institution') ?> Course identity, credits, programs and prerequisites come from the Registrar.</p>
        <p><?= V::source('inherited') ?> Approved outcomes, mappings and assessments carry forward each term.</p>
        <p><?= V::source('lms') ?> Results arrive from the LMS; <?= V::source('calculated') ?> achievement is computed.</p>
        <p style="margin:0"><?= V::source('faculty') ?> You provide academic judgement: outcomes, interpretation and improvement.</p>
      </div>
    </section>
  </aside>
</div>
<?php V::footer();

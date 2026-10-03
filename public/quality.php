<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Quality\ActionCenter;
use Saqf\Quality\Metrics;
use Saqf\Web\View as V;

$user = saqf_page(['qa']);
$actions = ActionCenter::forUser($user);
$wf = Metrics::workflow();
$auto = Metrics::automation();
$byCat = [];
foreach (Db::all('SELECT category, owner_role, COUNT(*) n FROM findings WHERE status = "open" AND severity <> "info" GROUP BY category, owner_role') as $r) {
    $byCat[$r['category']][$r['owner_role']] = (int) $r['n'];
}
$labels = ['data' => 'Problems in university records', 'policy' => 'Exceptions to policy', 'academic' => 'Academic decisions', 'quality_risk' => 'Risks', 'evidence' => 'Missing evidence', 'workflow' => 'Next steps', 'validation' => 'Course fixes'];
$wait = $wf['median_hours'] === null ? null : ($wf['median_hours'] < 48 ? max(1, (int) ceil($wf['median_hours'])) . ' hours' : (int) ceil($wf['median_hours'] / 24) . ' days');
$lastSync = Db::one('SELECT * FROM sync_runs WHERE source = "institution" ORDER BY id DESC LIMIT 1');
V::header('Quality assurance', $user, ['subtitle' => 'SAQF runs the routine checks; you see only exceptions, policy questions and patterns']);
?>
<div class="grid g4" style="margin-bottom:16px">
  <div class="card kpi tone-blue"><div class="kpi-v"><?= number_format($auto['checks']) ?></div><div class="kpi-l">checks SAQF ran for you</div><div class="kpi-d"><?= V::h(number_format($auto['auto_resolved']) . ' problems cleared by themselves once fixed') ?></div></div>
  <a class="card kpi <?= $auto['escalated'] ? 'tone-amber' : '' ?>" href="exceptions.php?owner=exceptions"><div class="kpi-v"><?= (int) $auto['escalated'] ?></div><div class="kpi-l">problems that needed someone beyond the instructor</div><div class="kpi-d">Head of Department, Quality or Dean</div></a>
  <div class="card kpi tone-green"><div class="kpi-v"><?= $wf['no_qa_step_pct'] === null ? '—' : V::pct($wf['no_qa_step_pct']) ?></div><div class="kpi-l">of approvals needed nothing from Quality</div><div class="kpi-d" title="<?= V::h($wf['routes']['auto_minor'] . ' small changes approved automatically, ' . $wf['routes']['auto_green'] . ' approved by the Head of Department, ' . $wf['routes']['qa'] . ' needed Quality') ?>"><?= V::h($wf['routes']['qa'] === 1 ? 'Only 1 needed Quality' : 'Only ' . $wf['routes']['qa'] . ' needed Quality') ?></div></div>
  <div class="card kpi"><div class="kpi-v"><?= $wf['first_pass_pct'] === null ? '—' : V::pct($wf['first_pass_pct']) ?></div><div class="kpi-l">of changes approved the first time</div><div class="kpi-d"><?= $wait === null ? '' : V::h('Usually decided within ' . $wait) ?></div></div>
</div>
<div class="split"><div class="stack">
  <section class="card"><div class="card-h"><h2>What needs you</h2><span class="muted small"><?= count($actions) === 1 ? 'One thing to do' : V::h(count($actions) . ' things to do') ?></span></div>
    <?php if (!$actions): ?><div class="allclear"><strong>✓</strong><div>Nothing is waiting for Quality.</div></div><?php endif; ?>
    <ul class="actions"><?php foreach ($actions as $a): ?><li class="action"><span class="prio prio-<?= (int) $a['priority'] ?>"></span><div class="action-main"><div class="action-title"><?= V::h($a['title']) ?></div><div class="action-reason"><?= V::h($a['reason']) ?></div><div class="action-meta"><?= V::h($a['context']) ?></div></div><a class="btn btn-sm <?= $a['priority'] === 1 ? 'btn-primary' : '' ?>" href="<?= V::h($a['link']) ?>"><?= V::h($a['cta']) ?></a></li><?php endforeach; ?></ul></section>
  <section class="card"><div class="card-h"><h2>Open problems, by type and who handles them</h2><span class="muted small right">Course fixes stay with instructors and never reach Quality</span></div>
    <div class="card-b tight"><table><thead><tr><th>Type</th><th class="num">Instructor</th><th class="num">Head of Dept.</th><th class="num">Quality</th><th class="num">Dean</th><th></th></tr></thead><tbody>
    <?php foreach ($labels as $k => $l): $row = $byCat[$k] ?? []; ?><tr><td class="small"><?= V::h($l) ?></td><?php foreach (['faculty', 'hod', 'qa', 'dean'] as $o): ?><td class="num"><?= (int) ($row[$o] ?? 0) ?: '<span class="muted">0</span>' ?></td><?php endforeach; ?><td class="num"><a href="exceptions.php?category=<?= $k ?>&owner=">View</a></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
</div><aside class="stack">
  <section class="card"><div class="card-h"><h2>University records</h2><?= V::source('institution') ?></div><div class="card-b small">
    <?php if ($lastSync): $s = json_decode((string) $lastSync['stats'], true) ?: []; ?><p><?= V::h('Last updated') ?> <?= V::h(V::date($lastSync['finished_at'], 'j M Y H:i')) ?></p><p><?= V::h((int) ($s['programs'] ?? 0) . ' programs') ?> · <?= V::h((int) ($s['courses'] ?? 0) . ' courses') ?></p><p class="muted"><?= V::h($lastSync['message']) ?></p><?php endif; ?>
    <p><a href="exceptions.php?category=data&owner=">Problems found in the records →</a></p></div></section>
  <section class="card"><div class="card-h"><h2>Policy</h2></div><div class="card-b small"><p>The goals and limits SAQF checks against are set by the university: the pass mark for an outcome, goals, limits on assessment weights, when a gap counts as repeated, and how many automatic approvals Quality spot-checks.</p><a class="btn btn-sm" href="policies.php">Quality policies</a></div></section>
</aside></div>
<?php V::footer();

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
$labels = ['data' => 'Data exceptions', 'policy' => 'Policy exceptions', 'academic' => 'Academic', 'quality_risk' => 'Quality risks', 'evidence' => 'Evidence', 'workflow' => 'Workflow', 'validation' => 'Validation'];
$lastSync = Db::one('SELECT * FROM sync_runs WHERE source = "institution" ORDER BY id DESC LIMIT 1');
V::header('Quality assurance', $user, ['subtitle' => 'Supervisory, not clerical: deterministic checks run automatically; you see exceptions, policy and patterns']);
?>
<div class="grid g4" style="margin-bottom:16px">
  <div class="card kpi tone-blue"><div class="kpi-v"><?= number_format($auto['checks']) ?></div><div class="kpi-l">deterministic checks run by SAQF</div><div class="kpi-d"><?= number_format($auto['auto_resolved']) ?> issues cleared automatically once fixed</div></div>
  <a class="card kpi <?= $auto['escalated'] ? 'tone-amber' : '' ?>" href="exceptions.php?owner=exceptions"><div class="kpi-v"><?= (int) $auto['escalated'] ?></div><div class="kpi-l">issues that ever needed a person above faculty level</div><div class="kpi-d">QA, HoD or Dean owned</div></a>
  <div class="card kpi tone-green"><div class="kpi-v"><?= $wf['no_qa_step_pct'] === null ? '—' : $wf['no_qa_step_pct'] . '%' ?></div><div class="kpi-l">approvals that needed no QA step</div><div class="kpi-d"><?= $wf['routes']['auto_green'] ?> green after HoD · <?= $wf['routes']['auto_minor'] ?> minor · <?= $wf['routes']['qa'] ?> via QA</div></div>
  <div class="card kpi"><div class="kpi-v"><?= $wf['first_pass_pct'] === null ? '—' : $wf['first_pass_pct'] . '%' ?></div><div class="kpi-l">approved without being returned</div><div class="kpi-d">avg <?= V::h((string) ($wf['avg_return_rounds'] ?? '—')) ?> return rounds · median <?= $wf['median_hours'] === null ? '—' : V::h((string) $wf['median_hours']) . ' h' ?> submit→decision</div></div>
</div>
<div class="split"><div class="stack">
  <section class="card"><div class="card-h"><h2>Your queue</h2><span class="muted small"><?= count($actions) ?> item(s)</span></div>
    <?php if (!$actions): ?><div class="allclear"><strong>✓</strong><div>No exceptions or decisions are waiting for QA.</div></div><?php endif; ?>
    <ul class="actions"><?php foreach ($actions as $a): ?><li class="action"><span class="prio prio-<?= (int) $a['priority'] ?>"></span><div class="action-main"><div class="action-title"><?= V::h($a['title']) ?></div><div class="action-reason"><?= V::h($a['reason']) ?></div><div class="action-meta"><?= V::h($a['context']) ?></div></div><a class="btn btn-sm <?= $a['priority'] === 1 ? 'btn-primary' : '' ?>" href="<?= V::h($a['link']) ?>"><?= V::h($a['cta']) ?></a></li><?php endforeach; ?></ul></section>
  <section class="card"><div class="card-h"><h2>Open issues by category and owner</h2><span class="muted small right">Validation items stay with faculty and never reach QA</span></div>
    <div class="card-b tight"><table><thead><tr><th>Category</th><th class="num">Faculty</th><th class="num">HoD</th><th class="num">QA</th><th class="num">Dean</th><th></th></tr></thead><tbody>
    <?php foreach ($labels as $k => $l): $row = $byCat[$k] ?? []; ?><tr><td><?= V::category($k) ?> <span class="small"><?= V::h($l) ?></span></td><?php foreach (['faculty', 'hod', 'qa', 'dean'] as $o): ?><td class="num"><?= (int) ($row[$o] ?? 0) ?: '<span class="muted">0</span>' ?></td><?php endforeach; ?><td class="num"><a href="exceptions.php?category=<?= $k ?>&owner=">View</a></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
</div><aside class="stack">
  <section class="card"><div class="card-h"><h2>Institutional data</h2><?= V::source('institution') ?></div><div class="card-b small">
    <?php if ($lastSync): $s = json_decode((string) $lastSync['stats'], true) ?: []; ?><p>Last sync <?= V::h(V::date($lastSync['finished_at'], 'j M Y H:i')) ?> · <?= (int) ($s['programs'] ?? 0) ?> programs, <?= (int) ($s['courses'] ?? 0) ?> courses, <?= (int) ($s['plan_entries'] ?? 0) ?> plan entries</p><p class="muted"><?= V::h($lastSync['message']) ?></p><?php endif; ?>
    <p><a href="exceptions.php?category=data&owner=">Data exceptions from the sync →</a></p></div></section>
  <section class="card"><div class="card-h"><h2>Policy</h2></div><div class="card-b small"><p>All thresholds the rules engine uses are configurable institutional policy (achievement method, targets, weighting limits, recurrence, sampling rate).</p><a class="btn btn-sm" href="policies.php">Quality policies</a></div></section>
</aside></div>
<?php V::footer();

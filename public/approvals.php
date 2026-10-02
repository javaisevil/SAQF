<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Quality\Catalog;
use Saqf\Quality\Impact;
use Saqf\Quality\Specs;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['hod', 'qa', 'dean', 'leadership']);
$versionId = (int) ($_GET['version'] ?? 0);

if ($versionId) {
    $v = Specs::version($versionId);
    if (!$v || !Authz::canViewCourse($user, (int) $v['course_id'])) {
        Authz::deny('approval #' . $versionId);
    }
    $course = Catalog::course((int) $v['course_id']);
    $diff = $v['status'] === 'draft' ? Specs::diff($versionId) : (json_decode((string) $v['change_summary'], true) ?: Specs::diff($versionId));
    $impact = Impact::forRevision($versionId);
    $findings = Db::all('SELECT * FROM findings WHERE scope_type = "spec" AND scope_id = ? AND status IN ("open","overridden") ORDER BY FIELD(severity,"blocker","warning","info")', [$versionId]);
    $submitter = $v['submitted_by'] ? Db::val('SELECT full_name FROM users WHERE id = ?', [$v['submitted_by']]) : null;
    $history = Db::all('SELECT * FROM audit_log WHERE object_type = "spec_version" AND object_id = ? ORDER BY id', [(string) $versionId]);
    $canHod = $v['status'] === 'pending_hod' && Authz::canDecideCourse($user, (int) $v['course_id']);
    $canQa = $v['status'] === 'pending_qa' && $user['role'] === 'qa';
    $isSample = $v['qa_sampled'] && $v['status'] === 'approved' && $user['role'] === 'qa';
    V::header($course['code'] . ' — specification v' . $v['version_no'], $user, ['subtitle' => V::h($course['title']) . ' · ' . V::specStatus($v['status']) . ($submitter ? ' · submitted by ' . V::h($submitter) . ' ' . V::h(V::ago($v['submitted_at'])) : '')]);
    ?>
    <div class="split">
      <div class="stack">
        <section class="card"><div class="card-h"><h2>What changed</h2><span class="muted small"><?= count($diff) ?> change(s) from the approved baseline — everything else is unchanged and already validated</span></div>
          <div class="card-b"><?php if (!$diff): ?><p class="muted">No changes.</p><?php else: ?><div class="diff"><?php foreach ($diff as $d): ?><div class="diff-row"><div class="diff-label"><?= V::h($d['label']) ?><br><?= $d['academic'] ? V::pill('academic', 'blue') : V::pill('minor', 'grey') ?></div><div class="diff-before"><?= $d['before'] === null ? '<span class="muted">—</span>' : V::h($d['before']) ?></div><div class="diff-after"><?= $d['after'] === null ? '<span class="muted">removed</span>' : V::h($d['after']) ?></div></div><?php endforeach; ?></div><?php endif; ?></div></section>
        <section class="card"><div class="card-h"><h2>Automated checks on this revision</h2></div>
          <?php if (!$findings): ?><div class="allclear"><strong>✓</strong><div><strong>All deterministic checks pass (green).</strong><div class="muted small">Measurable outcomes, mappings, assessment coverage and weights verified by SAQF.</div></div></div><?php endif; ?>
          <?php foreach ($findings as $f): ?><div class="check sev-<?= V::h($f['severity']) ?>"><div class="check-ico"><?= $f['severity'] === 'blocker' ? '!' : '•' ?></div><div><div class="row"><span class="check-title"><?= V::h($f['title']) ?></span><?= V::category($f['category']) ?><?= $f['status'] === 'overridden' ? V::source('overridden') : '' ?></div><div class="check-detail"><?= V::h($f['detail']) ?></div><div class="check-why"><?= V::h($f['why']) ?></div></div></div><?php endforeach; ?>
        </section>
        <section class="card"><div class="card-h"><h2>Impact analysis</h2><span class="muted small">What this change touches</span></div>
          <div class="card-b small">
            <p><strong>Programs:</strong> <?= V::h(implode(' · ', $impact['programs'])) ?: '—' ?></p>
            <p><strong>Current offerings:</strong> <?php foreach ($impact['offerings'] as $io): ?><span class="tag"><?= V::h($io['term_name']) ?> · <?= V::h($io['full_name'] ?? '—') ?><?= (int) $io['results'] ? ' · keeps current version (results already recorded)' : ' · will switch on approval' ?></span><?php endforeach; ?><?= $impact['offerings'] ? '' : '—' ?></p>
            <?php if ($impact['coverage_risks']): ?><p><strong>PLO coverage:</strong></p><ul><?php foreach ($impact['coverage_risks'] as $r): ?><li><?= V::h($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($impact['history_breaks']): ?><p><strong>History:</strong></p><ul><?php foreach ($impact['history_breaks'] as $r): ?><li><?= V::h($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($impact['orphaned_actions']): ?><p class="alert alert-warning"><?= (int) $impact['orphaned_actions'] ?> open improvement action(s) refer to removed outcomes.</p><?php endif; ?>
            <p><strong>Dependent courses (prerequisite chain):</strong> <?= $impact['dependents'] ? V::h(implode(', ', array_column($impact['dependents'], 'code'))) : '—' ?></p>
          </div></section>
      </div>
      <aside class="stack">
        <?php if ($canHod || $canQa): ?>
        <section class="card"><div class="card-h"><h2><?= $canHod ? 'Head of Department decision' : 'Quality Assurance decision' ?></h2></div>
          <div class="card-b"><p class="small muted"><?= $canHod ? ($findings ? 'Warnings remain open, so approving routes this to QA (amber).' : 'All checks are green: approving activates it immediately without a QA step (QA keeps a random sample).') : 'Approved by the HoD and routed to you because warnings remain open.' ?></p>
            <form data-api="<?= $canHod ? 'hod_decide' : 'qa_decide' ?>"><input type="hidden" name="version" value="<?= $versionId ?>">
              <div class="field"><label>Comment (required when returning)</label><textarea name="note"></textarea></div>
              <div class="row"><button class="btn btn-green" type="submit" data-set="decision=approve">Approve</button>
                <button class="btn btn-red" type="submit" data-set="decision=return">Return with comment</button></div>
              <input type="hidden" name="decision" value="approve">
            </form></div></section>
        <?php elseif ($isSample): ?>
        <section class="card"><div class="card-h"><h2>QA sample spot-check</h2></div><div class="card-b small"><p>This revision was auto-cleared after HoD approval (all checks green) and randomly selected for governance sampling.</p>
          <form data-api="sample_reviewed"><input type="hidden" name="version" value="<?= $versionId ?>"><div class="field"><select name="result"><option value="ok">No concerns</option><option value="concern">Concern raised</option></select></div><div class="field"><textarea name="note" placeholder="Note (optional)"></textarea></div><button class="btn btn-sm" type="submit">Record spot-check</button></form></div></section>
        <?php endif; ?>
        <section class="card"><div class="card-h"><h2>Trail</h2></div><div class="card-b"><ul class="timeline"><?php foreach ($history as $e): ?><li class="<?= $e['actor_type'] === 'user' ? 'usr' : 'sys' ?>"><div class="when"><?= V::h(V::date($e['occurred_at'], 'j M Y H:i')) ?> · <?= V::h($e['actor_name']) ?></div><div class="small"><?= V::h($e['summary']) ?></div><?php if ($e['reason']): ?><div class="small muted">“<?= V::h($e['reason']) ?>”</div><?php endif; ?></li><?php endforeach; ?></ul></div></section>
      </aside>
    </div>
    <?php
    V::footer();
    exit;
}

// ------------------------------------------------------------------ list
$where = '1=1';
$params = [];
if ($user['role'] === 'hod') {
    $where = 'c.owner_department_id = ?';
    $params = [$user['department_id']];
} elseif ($user['role'] === 'dean') {
    $where = 'd.college_id = ?';
    $params = [$user['scope_college_id']];
}
$pending = Db::all("SELECT sv.*, c.code, c.title, u.full_name FROM spec_versions sv JOIN courses c ON c.id = sv.course_id JOIN departments d ON d.id = c.owner_department_id LEFT JOIN users u ON u.id = sv.submitted_by WHERE sv.status IN ('pending_hod','pending_qa') AND $where ORDER BY sv.submitted_at", $params);
$sample = $user['role'] === 'qa' ? Db::all('SELECT sv.*, c.code, c.title FROM spec_versions sv JOIN courses c ON c.id = sv.course_id WHERE sv.qa_sampled = 1 AND sv.status = "approved" ORDER BY sv.decided_at DESC') : [];
$recent = Db::all("SELECT sv.*, c.code, c.title, u.full_name AS decider FROM spec_versions sv JOIN courses c ON c.id = sv.course_id JOIN departments d ON d.id = c.owner_department_id LEFT JOIN users u ON u.id = sv.decided_by WHERE sv.status IN ('approved','superseded') AND sv.decided_at IS NOT NULL AND $where ORDER BY sv.decided_at DESC LIMIT 15", $params);
$routes = ['auto_minor' => ['Auto-approved (non-academic)', 'grey'], 'auto_green' => ['HoD-approved · auto-cleared (green)', 'green'], 'qa' => ['QA decision (amber)', 'amber']];
V::header('Approvals', $user, ['subtitle' => 'Only genuine academic changes need a decision — unchanged specifications are inherited without approval']);
?>
<section class="card"><div class="card-h"><h2>Waiting for a decision</h2></div><div class="card-b tight"><table><thead><tr><th>Course</th><th>Version</th><th>Changes</th><th>Submitted</th><th>With</th><th></th></tr></thead><tbody>
<?php foreach ($pending as $p): $n = count(json_decode((string) $p['change_summary'], true) ?: []); ?>
  <tr><td><strong><?= V::h($p['code']) ?></strong> <span class="muted"><?= V::h($p['title']) ?></span></td><td>v<?= (int) $p['version_no'] ?></td><td><?= $n ?></td><td class="small"><?= V::h($p['full_name']) ?> · <?= V::h(V::ago($p['submitted_at'])) ?></td><td><?= V::specStatus($p['status']) ?></td><td class="num"><a class="btn btn-sm btn-primary" href="approvals.php?version=<?= (int) $p['id'] ?>">Review</a></td></tr>
<?php endforeach; ?>
<?php if (!$pending): ?><tr><td colspan="6"><?= V::empty('No decisions waiting', 'Validated changes arrive here only when they genuinely need academic judgement.') ?></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php if ($sample): ?>
<section class="card"><div class="card-h"><h2>QA sample of auto-cleared revisions</h2><span class="muted small">Governance assurance without re-checking everything</span></div><div class="card-b tight"><table><tbody>
<?php foreach ($sample as $s): $done = Db::val('SELECT 1 FROM audit_log WHERE action = "qa.sample_reviewed" AND object_type = "spec_version" AND object_id = ?', [(string) $s['id']]); ?>
  <tr><td><strong><?= V::h($s['code']) ?></strong> v<?= (int) $s['version_no'] ?> <span class="muted"><?= V::h($s['title']) ?></span></td><td class="small"><?= V::h(V::date($s['decided_at'])) ?></td><td><?= $done ? V::pill('Spot-checked', 'green') : V::pill('To spot-check', 'blue') ?></td><td class="num"><a class="btn btn-sm" href="approvals.php?version=<?= (int) $s['id'] ?>">Open</a></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<section class="card"><div class="card-h"><h2>Recent decisions</h2></div><div class="card-b tight"><table><thead><tr><th>Course</th><th>Version</th><th>Route</th><th>Decided</th><th></th></tr></thead><tbody>
<?php foreach ($recent as $r): [$l, $t] = $routes[$r['decision_route']] ?? [str_replace('_', ' ', (string) $r['decision_route']), 'grey']; ?>
  <tr><td><strong><?= V::h($r['code']) ?></strong> <span class="muted"><?= V::h($r['title']) ?></span></td><td>v<?= (int) $r['version_no'] ?></td><td><?= V::pill($l, $t) ?><?= $r['qa_sampled'] ? ' ' . V::pill('QA sample', 'blue') : '' ?></td><td class="small"><?= V::h($r['decider'] ?? 'SAQF (policy)') ?> · <?= V::h(V::date($r['decided_at'])) ?></td><td class="num"><a href="approvals.php?version=<?= (int) $r['id'] ?>">Details</a></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<?php V::footer();

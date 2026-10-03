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
    V::header($course['code'] . ' — specification changes', $user, ['subtitle' => V::h($course['title']) . ' · ' . V::specStatus($v['status']) . ($submitter ? ' · ' . V::h('sent by') . ' ' . V::h($submitter) . ' · ' . V::h(V::ago($v['submitted_at'])) : '')]);
    ?>
    <div class="split">
      <div class="stack">
        <section class="card"><div class="card-h"><h2>What changed</h2><span class="muted small"><?= V::h(count($diff) === 1 ? '1 change' : count($diff) . ' changes') ?> · <?= V::h('everything else is the same as the approved version') ?></span></div>
          <div class="card-b"><?php if (!$diff): ?><p class="muted">No changes.</p><?php else: ?><div class="diff"><?php foreach ($diff as $d): ?><div class="diff-row"><div class="diff-label"><?= V::h($d['label']) ?><br><?= $d['academic'] ? V::pill('needs approval', 'blue') : V::pill('small change', 'grey') ?></div><div class="diff-before"><?= $d['before'] === null ? '<span class="muted">—</span>' : V::h($d['before']) ?></div><div class="diff-after"><?= $d['after'] === null ? '<span class="muted">Removed</span>' : V::h($d['after']) ?></div></div><?php endforeach; ?></div><?php endif; ?></div></section>
        <section class="card"><div class="card-h"><h2>Checks</h2></div>
          <?php if (!$findings): ?><div class="allclear"><strong>✓</strong><div><strong>Everything checks out.</strong><div class="muted small">SAQF checked that the outcomes can be measured, are linked to the program, are assessed, and that the weights add up.</div></div></div><?php endif; ?>
          <?php foreach ($findings as $f): ?><div class="check sev-<?= V::h($f['severity']) ?>"><div class="check-ico"><?= $f['severity'] === 'blocker' ? '!' : '•' ?></div><div><div class="row"><span class="check-title"><?= V::h($f['title']) ?></span><?= V::category($f['category']) ?><?= $f['status'] === 'overridden' ? V::source('overridden') : '' ?></div><div class="check-detail"><?= V::h($f['detail']) ?></div><?php if ($f['why']): ?><details class="why"><summary>Why does this matter?</summary><div class="check-why"><?= V::h($f['why']) ?></div></details><?php endif; ?></div></div><?php endforeach; ?>
        </section>
        <section class="card"><div class="card-h"><h2>What this affects</h2></div>
          <div class="card-b small">
            <p><strong>Programs:</strong> <?= V::h(implode(' · ', $impact['programs'])) ?: '—' ?></p>
            <p><strong>Classes this term:</strong> <?php foreach ($impact['offerings'] as $io): ?><span class="tag"><?= V::h($io['term_name']) ?> · <?= V::h($io['full_name'] ?? '—') ?> · <?= V::h((int) $io['results'] ? 'keeps the current version (grades already in)' : 'switches to this version once approved') ?></span><?php endforeach; ?><?= $impact['offerings'] ? '' : '—' ?></p>
            <?php if ($impact['coverage_risks']): ?><p><strong>Program outcomes:</strong></p><ul><?php foreach ($impact['coverage_risks'] as $r): ?><li><?= V::h($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($impact['history_breaks']): ?><p><strong>Results history:</strong></p><ul><?php foreach ($impact['history_breaks'] as $r): ?><li><?= V::h($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($impact['orphaned_actions']): ?><p class="alert alert-warning"><?= V::h((int) $impact['orphaned_actions'] === 1 ? '1 improvement under way is about an outcome this change removes.' : (int) $impact['orphaned_actions'] . ' improvements under way are about outcomes this change removes.') ?></p><?php endif; ?>
            <p><strong>Courses that need this one first:</strong> <?= $impact['dependents'] ? V::h(implode(', ', array_column($impact['dependents'], 'code'))) : '—' ?></p>
          </div></section>
      </div>
      <aside class="stack">
        <?php if ($canHod || $canQa): ?>
        <section class="card"><div class="card-h"><h2>Your decision</h2></div>
          <div class="card-b"><p class="small muted"><?= $canHod ? ($findings ? 'Some checks show warnings, so after you approve it also goes to Quality.' : 'Everything checks out: once you approve, it takes effect straight away. Quality spot-checks a few at random.') : 'The Head of Department approved it; it came to you because some checks show warnings.' ?></p>
            <form data-api="<?= $canHod ? 'hod_decide' : 'qa_decide' ?>"><input type="hidden" name="version" value="<?= $versionId ?>">
              <div class="field"><label>Comment (needed if you send it back)</label><textarea name="note"></textarea></div>
              <div class="row"><button class="btn btn-green" type="submit" data-set="decision=approve">Approve</button>
                <button class="btn btn-red" type="submit" data-set="decision=return">Send back with comment</button></div>
              <input type="hidden" name="decision" value="approve">
            </form></div></section>
        <?php elseif ($isSample): ?>
        <section class="card"><div class="card-h"><h2>Spot-check</h2></div><div class="card-b small"><p>The Head of Department approved this and every check passed, so it took effect without Quality. SAQF picked it at random for you to look over.</p>
          <form data-api="sample_reviewed"><input type="hidden" name="version" value="<?= $versionId ?>"><div class="field"><select name="result"><option value="ok">No concerns</option><option value="concern">I have a concern</option></select></div><div class="field"><textarea name="note" placeholder="Note (optional)"></textarea></div><button class="btn btn-sm" type="submit">Save</button></form></div></section>
        <?php endif; ?>
        <section class="card"><div class="card-h"><h2>What happened</h2></div><div class="card-b"><ul class="timeline"><?php foreach ($history as $e): ?><li class="<?= $e['actor_type'] === 'user' ? 'usr' : 'sys' ?>"><div class="when"><?= V::h(V::date($e['occurred_at'], 'j M Y H:i')) ?> · <?= V::h($e['actor_name']) ?></div><div class="small"><?= V::h($e['summary']) ?></div><?php if ($e['reason']): ?><div class="small muted">“<?= V::h($e['reason']) ?>”</div><?php endif; ?></li><?php endforeach; ?></ul></div></section>
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
$tones = ['auto_minor' => 'grey', 'auto_green' => 'green', 'hod' => 'green', 'qa' => 'amber'];
V::header('Approvals', $user, ['subtitle' => 'Only real academic changes come here; unchanged specifications carry over by themselves']);
?>
<section class="card"><div class="card-h"><h2>Waiting for a decision</h2></div><div class="card-b tight"><table><thead><tr><th>Course</th><th>Changes</th><th>Sent by</th><th>With</th><th></th></tr></thead><tbody>
<?php foreach ($pending as $p): $n = count(json_decode((string) $p['change_summary'], true) ?: []); ?>
  <tr><td><strong><?= V::h($p['code']) ?></strong> <span class="muted"><?= V::h($p['title']) ?></span></td><td><?= $n ?></td><td class="small"><?= V::h($p['full_name']) ?> · <?= V::h(V::ago($p['submitted_at'])) ?></td><td><?= V::specStatus($p['status']) ?></td><td class="num"><a class="btn btn-sm btn-primary" href="approvals.php?version=<?= (int) $p['id'] ?>">Review</a></td></tr>
<?php endforeach; ?>
<?php if (!$pending): ?><tr><td colspan="6"><?= V::empty('Nothing is waiting for you', 'Changes come here only when they need an academic decision.') ?></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php if ($sample): ?>
<section class="card"><div class="card-h"><h2>Spot-checks</h2><span class="muted small">A few automatic approvals picked at random, so nothing needs re-checking in full</span></div><div class="card-b tight"><table><tbody>
<?php foreach ($sample as $s): $done = Db::val('SELECT 1 FROM audit_log WHERE action = "qa.sample_reviewed" AND object_type = "spec_version" AND object_id = ?', [(string) $s['id']]); ?>
  <tr><td><strong><?= V::h($s['code']) ?></strong> <span class="muted"><?= V::h($s['title']) ?></span></td><td class="small"><?= V::h(V::date($s['decided_at'])) ?></td><td><?= $done ? V::pill('Spot-checked', 'green') : V::pill('To spot-check', 'blue') ?></td><td class="num"><a class="btn btn-sm" href="approvals.php?version=<?= (int) $s['id'] ?>">Open</a></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<section class="card"><div class="card-h"><h2>Recent decisions</h2></div><div class="card-b tight"><table><thead><tr><th>Course</th><th>How it was decided</th><th>When</th><th></th></tr></thead><tbody>
<?php foreach ($recent as $r): $l = V::route($r['decision_route']); $t = $tones[$r['decision_route']] ?? 'grey'; ?>
  <tr><td><strong><?= V::h($r['code']) ?></strong> <span class="muted"><?= V::h($r['title']) ?></span></td><td><?= V::pill($l, $t) ?><?= $r['qa_sampled'] ? ' ' . V::pill('Spot-check', 'blue') : '' ?></td><td class="small"><?= V::h($r['decider'] ?? 'SAQF (automatic)') ?> · <?= V::h(V::date($r['decided_at'])) ?></td><td class="num"><a href="approvals.php?version=<?= (int) $r['id'] ?>">Details</a></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<?php V::footer();

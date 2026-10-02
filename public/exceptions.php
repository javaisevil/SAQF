<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Quality\Rules;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership']);

/** Scope predicate for findings visible to this user. */
function finding_scope(array $user): array
{
    switch ($user['role']) {
        case 'qa':
        case 'leadership':
            return ['1=1', []];
        case 'hod':
            return ['(f.department_id = ? OR f.program_id IN (SELECT id FROM programs WHERE department_id = ?))', [$user['department_id'], $user['department_id']]];
        case 'dean':
            return ['f.college_id = ?', [$user['scope_college_id']]];
        default:
            return ['f.offering_id IN (SELECT id FROM course_offerings WHERE instructor_id = ?) OR (f.scope_type = "spec" AND f.course_id IN (SELECT course_id FROM course_offerings WHERE instructor_id = ?)) OR f.offering_id IN (SELECT offering_id FROM offering_sections WHERE instructor_id = ?)', [$user['id'], $user['id'], $user['id']]];
    }
}

$findingId = (int) ($_GET['finding'] ?? 0);
[$scopeSql, $scopeParams] = finding_scope($user);

if ($findingId) {
    $f = Db::one("SELECT f.* FROM findings f WHERE f.id = ? AND ($scopeSql)", array_merge([$findingId], $scopeParams));
    if (!$f) {
        Authz::deny('finding #' . $findingId);
    }
    $ctx = json_decode((string) $f['context'], true) ?: [];
    $course = $f['course_id'] ? Db::one('SELECT id, code, title FROM courses WHERE id = ?', [$f['course_id']]) : null;
    $program = $f['program_id'] ? Db::one('SELECT id, code, short_name FROM programs WHERE id = ?', [$f['program_id']]) : null;
    $offering = $f['offering_id'] ? Db::one('SELECT o.id, t.name FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.id = ?', [$f['offering_id']]) : null;
    if (!$offering && $f['course_id']) {
        $offering = Db::one('SELECT o.id, t.name FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.course_id = ? ORDER BY t.sequence DESC LIMIT 1', [$f['course_id']]);
    }
    $overrides = Db::all('SELECT o.*, u.full_name AS requester, d.full_name AS decider FROM overrides o JOIN users u ON u.id = o.requested_by LEFT JOIN users d ON d.id = o.decided_by WHERE o.finding_id = ? ORDER BY o.id DESC', [$findingId]);
    $trail = Db::all('SELECT * FROM audit_log WHERE object_type = "finding" AND object_id = ? ORDER BY id', [(string) $findingId]);
    $pending = current(array_filter($overrides, static fn($o) => $o['status'] === 'requested')) ?: null;
    $canResolve = $f['status'] === 'open' && $f['category'] !== 'validation' && ($user['role'] === 'qa'
        || ($user['role'] === 'hod' && $f['owner_role'] === 'hod') || ($user['role'] === 'dean' && $f['owner_role'] === 'dean'));
    $canOverride = $user['role'] === 'qa' && $f['status'] === 'open' && !($f['category'] === 'validation' && $f['severity'] === 'blocker');
    V::header($f['title'], $user, ['subtitle' => V::category($f['category']) . ' · ' . V::severity($f['severity']) . ' · owner: ' . V::h(strtoupper($f['owner_role'])) . ' · ' . V::h(str_replace('_', ' ', $f['status']))]);
    ?>
    <div class="split"><div class="stack">
      <section class="card"><div class="card-h"><h2>What SAQF found</h2><span class="right mono tiny"><?= V::h($f['rule_code']) ?></span></div><div class="card-b">
        <p><?= V::h($f['detail']) ?></p>
        <?php if ($f['why']): ?><p class="small"><strong>Why it matters:</strong> <?= V::h($f['why']) ?></p><?php endif; ?>
        <?php if ($f['remedy']): ?><p class="small" style="color:#0E5A2B"><strong>What resolves it:</strong> <?= V::h($f['remedy']) ?></p><?php endif; ?>
        <?php if (!empty($ctx['variants'])): ?><table class="small"><thead><tr><th>Source</th><th>Title</th><th class="num">Credits</th></tr></thead><tbody><?php foreach ($ctx['variants'] as $vr): ?><tr><td><?= V::h($vr['where']) ?></td><td><?= V::h($vr['title']) ?></td><td class="num"><?= V::h((string) $vr['credits']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        <p class="small muted" style="margin-top:10px">First detected <?= V::h(V::date($f['first_detected_at'], 'j M Y H:i')) ?> · last confirmed <?= V::h(V::ago($f['last_detected_at'])) ?><?= (int) $f['occurrences'] > 1 ? ' · reopened ' . ((int) $f['occurrences'] - 1) . ' time(s)' : '' ?><?= $f['resolution_note'] ? ' · ' . V::h($f['resolution_note']) : '' ?></p>
        <div class="row"><?php if ($course): ?><a class="btn btn-sm" href="<?= $offering ? 'workspace.php?id=' . (int) $offering['id'] : 'course.php?id=' . (int) $course['id'] ?>"><?= V::h($course['code']) ?> workspace</a><?php endif; ?><?php if ($program): ?><a class="btn btn-sm" href="program.php?id=<?= (int) $program['id'] ?>"><?= V::h($program['code']) ?> program</a><?php endif; ?></div>
      </div></section>
      <?php foreach ($overrides as $ov): ?>
      <section class="card"><div class="card-h"><h2>Exception request</h2><?= V::pill(ucfirst($ov['status']), ['requested' => 'blue', 'approved' => 'green', 'rejected' => 'red'][$ov['status']] ?? 'grey') ?></div><div class="card-b small">
        <p><strong><?= V::h($ov['requester']) ?></strong> · <?= V::h(V::date($ov['requested_at'], 'j M Y H:i')) ?></p><p>“<?= V::h($ov['justification']) ?>”</p>
        <?php if ($ov['decider']): ?><p class="muted">Decision by <?= V::h($ov['decider']) ?> on <?= V::h(V::date($ov['decided_at'], 'j M Y')) ?>: “<?= V::h($ov['decision_note']) ?>”</p><?php endif; ?>
        <?php if ($ov['status'] === 'requested' && $user['role'] === 'qa'): ?>
          <form data-api="override_decide"><input type="hidden" name="id" value="<?= (int) $ov['id'] ?>"><div class="field"><label>Decision note (recorded in the audit log)</label><textarea name="note" required></textarea></div>
            <div class="row"><button class="btn btn-green" type="submit" data-set="decision=approve">Approve exception</button><button class="btn btn-red" type="submit" data-set="decision=reject">Reject</button></div><input type="hidden" name="decision" value="approve"></form>
        <?php endif; ?></div></section>
      <?php endforeach; ?>
    </div><aside class="stack">
      <?php if ($canResolve): ?><section class="card"><div class="card-h"><h2>Resolve</h2></div><div class="card-b small"><p class="muted">Record how this was handled. If the rule still fails on the next evaluation, SAQF reopens it automatically.</p>
        <form data-api="finding_resolve"><input type="hidden" name="finding" value="<?= $findingId ?>"><textarea name="note" required placeholder="e.g. Registrar confirmed 3 CR; plan correction requested (ticket REG-1182)"></textarea><button class="btn btn-sm" type="submit" style="margin-top:8px">Mark resolved</button></form></div></section><?php endif; ?>
      <?php if ($canOverride && !$pending): ?><section class="card"><div class="card-h"><h2>Set the rule aside</h2></div><div class="card-b small"><p class="muted">An override never deletes the finding: it records who set the rule aside, why, and when.</p>
        <form data-api="qa_override"><input type="hidden" name="finding" value="<?= $findingId ?>"><textarea name="reason" required minlength="10" placeholder="Reason for the override"></textarea><button class="btn btn-sm btn-red" type="submit" style="margin-top:8px">Override with reason</button></form></div></section><?php endif; ?>
      <section class="card"><div class="card-h"><h2>Trail</h2></div><div class="card-b"><ul class="timeline"><?php foreach ($trail as $e): ?><li class="<?= $e['actor_type'] === 'user' ? 'usr' : 'sys' ?>"><div class="when"><?= V::h(V::date($e['occurred_at'], 'j M Y H:i')) ?> · <?= V::h($e['actor_name']) ?></div><div class="small"><?= V::h($e['summary']) ?></div><?php if ($e['reason']): ?><div class="small muted">“<?= V::h($e['reason']) ?>”</div><?php endif; ?></li><?php endforeach; ?></ul></div></section>
    </aside></div>
    <?php
    V::footer();
    exit;
}

$cat = (string) ($_GET['category'] ?? '');
$owner = (string) ($_GET['owner'] ?? ($user['role'] === 'qa' ? 'exceptions' : ''));
$status = (string) ($_GET['status'] ?? 'open');
$age = (int) ($_GET['age'] ?? 0);
$where = ["($scopeSql)"];
$params = $scopeParams;
if ($status !== 'all') {
    $where[] = 'f.status = ?';
    $params[] = in_array($status, ['open', 'overridden', 'resolved', 'auto_resolved'], true) ? $status : 'open';
}
if ($cat !== '' && isset(array_flip(['data', 'policy', 'academic', 'quality_risk', 'evidence', 'workflow', 'validation'])[$cat])) {
    $where[] = 'f.category = ?';
    $params[] = $cat;
}
if ($owner === 'exceptions') {
    $where[] = '(f.owner_role IN ("qa","hod","dean") OR f.id IN (SELECT finding_id FROM overrides WHERE status = "requested"))';
} elseif (in_array($owner, ['qa', 'hod', 'dean', 'faculty'], true)) {
    $where[] = 'f.owner_role = ?';
    $params[] = $owner;
}
if ($age > 0) {
    $where[] = 'f.first_detected_at < ?';
    $params[] = Clock::now()->modify('-' . $age . ' days')->format('Y-m-d H:i:s');
}
$rows = Db::all('SELECT f.*, c.code AS course_code, p.code AS program_code, (SELECT status FROM overrides WHERE finding_id = f.id ORDER BY id DESC LIMIT 1) AS override_status
    FROM findings f LEFT JOIN courses c ON c.id = f.course_id LEFT JOIN programs p ON p.id = f.program_id WHERE ' . implode(' AND ', $where) . '
    ORDER BY FIELD(f.severity,"blocker","warning","info"), f.last_detected_at DESC LIMIT 300', $params);
$counts = [];
foreach (Db::all("SELECT f.category, COUNT(*) n FROM findings f WHERE ($scopeSql) AND f.status = 'open' AND f.severity <> 'info' GROUP BY f.category", $scopeParams) as $r) {
    $counts[$r['category']] = (int) $r['n'];
}
$labels = ['data' => 'Data exceptions', 'policy' => 'Policy exceptions', 'academic' => 'Academic', 'quality_risk' => 'Quality risks', 'evidence' => 'Evidence', 'workflow' => 'Workflow', 'validation' => 'Validation (faculty-owned)'];
$qs = static fn(array $over) => 'exceptions.php?' . http_build_query(array_merge(['category' => $cat, 'owner' => $owner, 'status' => $status], $over));
V::header('Exception center', $user, ['subtitle' => 'Problems arrive here — nobody searches for them. Routine validation is handled automatically and never lands here.']);
?>
<div class="grid g4" style="margin-bottom:16px">
  <?php foreach (['data', 'policy', 'academic', 'quality_risk'] as $k): ?><a class="card kpi <?= ($counts[$k] ?? 0) ? 'tone-amber' : '' ?>" href="<?= V::h($qs(['category' => $k, 'owner' => ''])) ?>"><div class="kpi-v"><?= (int) ($counts[$k] ?? 0) ?></div><div class="kpi-l"><?= V::h($labels[$k]) ?></div></a><?php endforeach; ?>
</div>
<section class="card"><div class="card-h"><form class="row" method="get" style="width:100%">
  <select name="category" style="width:auto"><option value="">All categories</option><?php foreach ($labels as $k => $l): ?><option value="<?= $k ?>" <?= $cat === $k ? 'selected' : '' ?>><?= V::h($l) ?></option><?php endforeach; ?></select>
  <select name="owner" style="width:auto"><option value="">Any owner</option><option value="exceptions" <?= $owner === 'exceptions' ? 'selected' : '' ?>>Exceptions (QA / HoD / Dean)</option><?php foreach (['faculty', 'hod', 'qa', 'dean'] as $r): ?><option value="<?= $r ?>" <?= $owner === $r ? 'selected' : '' ?>>Owned by <?= strtoupper($r) ?></option><?php endforeach; ?></select>
  <select name="status" style="width:auto"><?php foreach (['open' => 'Open', 'overridden' => 'Overridden', 'resolved' => 'Resolved by a person', 'auto_resolved' => 'Cleared automatically', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= V::h($l) ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm" type="submit">Filter</button><span class="right muted small"><?= count($rows) ?> shown</span></form></div>
  <div class="card-b tight"><div class="table-wrap"><table><thead><tr><th>Issue</th><th>Category</th><th>Scope</th><th>Owner</th><th>Detected</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $f): ?>
    <tr><td><div class="strong"><?= V::h($f['title']) ?></div><div class="tiny muted"><?= V::h(mb_strimwidth($f['detail'], 0, 140, '…')) ?></div></td><td><?= V::category($f['category']) ?><br><?= V::severity($f['severity']) ?></td>
      <td class="small"><?= V::h($f['course_code'] ?? $f['program_code'] ?? 'Institution') ?></td><td class="small"><?= V::h(strtoupper($f['owner_role'])) ?><?= $f['override_status'] === 'requested' ? '<br>' . V::pill('exception requested', 'blue') : '' ?></td>
      <td class="small nowrap"><?= V::h(V::ago($f['first_detected_at'])) ?></td><td class="num"><a class="btn btn-sm" href="exceptions.php?finding=<?= (int) $f['id'] ?>">Open</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6"><?= V::empty('No exceptions match', 'Nothing in this view needs a human right now.') ?></td></tr><?php endif; ?>
  </tbody></table></div></div></section>
<?php V::footer();

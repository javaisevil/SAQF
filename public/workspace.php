<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Core\Session;
use Saqf\Integration\Gradebook;
use Saqf\Integration\Integrations;
use Saqf\Quality\Achievement;
use Saqf\Quality\Catalog;
use Saqf\Quality\Findings;
use Saqf\Quality\Improvements;
use Saqf\Quality\Rules;
use Saqf\Quality\Specs;
use Saqf\Quality\Status;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership']);
$o = Authz::offering($user, (int) ($_GET['id'] ?? 0));
$oid = (int) $o['id'];
$courseId = (int) $o['course_id'];
$canEdit = Authz::canEditOffering($user, $o);
$tab = in_array($_GET['tab'] ?? '', ['overview', 'structure', 'results', 'improve', 'report', 'history'], true) ? $_GET['tab'] : 'overview';

// CSV upload fallback (when results are not in the LMS). Validated strictly, never trusted.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_FILES['results'])) {
    saqf_require_post();
    if (!$canEdit) {
        Authz::deny('uploading results');
    }
    $f = $_FILES['results'];
    try {
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('Upload a CSV file under 2 MB.');
        }
        $results = Gradebook::parseCsv($f['tmp_name']);
        $r = Achievement::import($oid, $results, 'upload');
        Session::flash('success', "{$r['rows']} results imported; achievement recalculated automatically.");
    } catch (InvalidArgumentException $e) {
        Session::flash('error', $e->getMessage());
    }
    saqf_redirect('workspace.php?id=' . $oid . '&tab=results');
}

$status = Status::forOffering($o);
$programs = Catalog::programsFor($courseId);
$requisites = Catalog::requisitesFor($courseId);
$working = Specs::working($courseId);
$approved = Specs::approved($courseId);
$spec = $working ? Specs::load((int) $working['id']) : null;
$isDraft = $working && $working['status'] === 'draft';
$findings = Findings::forOffering($oid);
$openFindings = array_values(array_filter($findings, static fn($f) => $f['status'] === 'open'));
$overridden = array_values(array_filter($findings, static fn($f) => $f['status'] === 'overridden'));
$blocking = array_filter($openFindings, static fn($f) => $f['severity'] !== 'info');
$byClo = [];
foreach ($openFindings as $f) {
    $ctx = json_decode((string) $f['context'], true) ?: [];
    if (!empty($ctx['clo_id'])) {
        $byClo[(int) $ctx['clo_id']][] = $f;
    }
}
$ach = [];
foreach (Db::all('SELECT * FROM clo_achievement WHERE offering_id = ?', [$oid]) as $a) {
    $ach[$a['lineage_key']] = $a;
}
$evidenceSpec = $o['spec_version_id'] ? Specs::load((int) $o['spec_version_id']) : null;
$ledger = [];
foreach (Db::all('SELECT kind, SUM(quantity) q FROM automation_ledger WHERE offering_id = ? GROUP BY kind', [$oid]) as $r) {
    $ledger[$r['kind']] = (int) $r['q'];
}
$actionsCourse = Improvements::forCourse($courseId);
$draftActions = array_filter($actionsCourse, static fn($a) => $a['status'] === 'draft');
$defaultTarget = Policy::get('clo.default_target_pct');
$recs = $working ? Db::all('SELECT * FROM recommendations WHERE scope_type = "spec" AND scope_id = ? AND status = "open" ORDER BY kind, confidence DESC', [$working['id']]) : [];
$recByClo = [];
foreach ($recs as $r) {
    $p = json_decode((string) $r['payload'], true) ?: [];
    if ($r['kind'] === 'mapping') {
        $recByClo[(int) $p['clo_id']][(int) $p['plo_id']] = $r;
    }
}
$insights = Db::all('SELECT * FROM recommendations WHERE scope_type = "offering" AND scope_id = ? AND status = "open"', [$oid]);
$base = 'workspace.php?id=' . $oid;
$countBadge = static fn(int $n) => $n ? ' <span class="count">' . $n . '</span>' : '';
$improveCount = count($draftActions);

V::header($o['course_code'] . ' · ' . $o['course_title'], $user, ['subtitle' => V::h($o['term_name']) . ' · ' . V::h($o['instructor_name'] ?? 'No instructor') . ' · ' . (int) $o['enrolled'] . ' students' . ($o['term_status'] === 'closed' ? ' · <strong>closed (read-only record)</strong>' : '')]);
echo V::tabs([
    'overview' => 'Overview' . $countBadge(count($blocking)),
    'structure' => 'Outcomes & assessment',
    'results' => 'Results & achievement',
    'improve' => 'Improvement' . $countBadge($improveCount),
    'report' => 'Course report',
    'history' => 'History',
], $tab, $base);

if ($tab === 'overview'):
    $mark = ['green' => '✓', 'red' => '!', 'amber' => '⚑', 'blue' => '…', 'violet' => '↻'][$status['tone']] ?? '•';
?>
<div class="split">
  <div class="stack">
    <div class="status tone-<?= V::h($status['tone']) ?>">
      <div class="status-mark"><?= $mark ?></div>
      <div><h2><?= V::h($status['label']) ?></h2><div class="muted small"><?= V::h($status['help']) ?></div>
        <?php if ($status['reasons']): ?><ul class="reasons"><?php foreach ($status['reasons'] as [$state, $text, $link]): ?><li><?= $link ? '<a href="' . V::h($link) . '">' . V::h($text) . '</a>' : V::h($text) ?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
    </div>

    <section class="card">
      <div class="card-h"><h2>Quality checks</h2><span class="muted small right">Run continuously — <?= count($blocking) ?> need attention</span></div>
      <?php if (!$openFindings && !$overridden): ?>
        <div class="allclear"><strong>✓</strong><div><strong>All deterministic checks pass.</strong><div class="muted small">Outcomes, mappings, assessment coverage, weights and evidence were validated automatically.</div></div></div>
      <?php endif; ?>
      <?php foreach (array_merge($blocking, $overridden) as $f): $pending = Db::one('SELECT * FROM overrides WHERE finding_id = ? ORDER BY id DESC LIMIT 1', [$f['id']]); ?>
        <div class="check sev-<?= V::h($f['severity']) ?> st-<?= V::h($f['status']) ?>">
          <div class="check-ico"><?= $f['status'] === 'overridden' ? '—' : ($f['severity'] === 'blocker' ? '!' : '•') ?></div>
          <div style="flex:1">
            <div class="row"><span class="check-title"><?= V::h($f['title']) ?></span><?= V::category($f['category']) ?><?= $f['status'] === 'overridden' ? V::source('overridden', (string) $f['resolution_note']) : '' ?><?= $f['owner_role'] !== 'faculty' ? V::pill('with ' . strtoupper($f['owner_role']), 'grey') : '' ?></div>
            <div class="check-detail"><?= V::h($f['detail']) ?></div>
            <?php if ($f['why']): ?><div class="check-why"><strong>Why it matters:</strong> <?= V::h($f['why']) ?></div><?php endif; ?>
            <?php if ($f['remedy'] && $f['status'] === 'open'): ?><div class="check-fix"><strong>What to do:</strong> <?= V::h($f['remedy']) ?></div><?php endif; ?>
            <?php if ($pending && $pending['status'] === 'requested'): ?><div class="small muted" style="margin-top:4px">Exception requested on <?= V::h(V::date($pending['requested_at'])) ?> — waiting for Quality Assurance.</div>
            <?php elseif ($canEdit && $f['status'] === 'open' && in_array($f['category'], ['policy', 'quality_risk', 'evidence'], true)): ?>
              <details style="margin-top:6px"><summary class="small" style="cursor:pointer;color:var(--accent-ink)">Request a justified exception from QA</summary>
                <form data-api="override_request" style="margin-top:8px"><input type="hidden" name="finding" value="<?= (int) $f['id'] ?>"><input type="hidden" name="offering" value="<?= $oid ?>">
                  <textarea name="justification" required minlength="20" placeholder="Explain why this course should be treated as an exception (QA sees this, and it is kept in the audit log)."></textarea>
                  <button class="btn btn-sm" type="submit">Send to QA</button></form></details>
            <?php endif; ?>
          </div>
          <?php if ($f['status'] === 'open' && in_array($f['category'], ['validation'], true) && $canEdit): ?><a class="btn btn-sm" href="<?= $base ?>&tab=structure">Fix</a><?php endif; ?>
          <?php if (in_array($f['rule_code'], ['IMPROVEMENT_MISSING', 'CLO_TARGET_MISSED'], true) && $canEdit): ?><a class="btn btn-sm" href="<?= $base ?>&tab=improve">Respond</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php $info = array_filter($openFindings, static fn($f) => $f['severity'] === 'info'); if ($info): ?>
        <details class="more"><summary>+ <?= count($info) ?> advisory note(s)</summary>
          <?php foreach ($info as $f): ?><div class="check sev-info"><div class="check-ico">i</div><div><div class="check-title"><?= V::h($f['title']) ?></div><div class="check-detail"><?= V::h($f['detail']) ?></div></div></div><?php endforeach; ?>
        </details>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-h"><h2>Outcome achievement — <?= V::h($o['term_name']) ?></h2><a class="right small" href="<?= $base ?>&tab=results">Details</a></div>
      <div class="card-b tight"><table>
        <thead><tr><th style="width:70px">CLO</th><th>Outcome</th><th style="width:260px">Achievement vs target</th></tr></thead><tbody>
        <?php foreach ($evidenceSpec['clos'] ?? [] as $c): $a = $ach[$c['lineage_key']] ?? null; ?>
          <tr><td class="strong"><?= V::h($c['code']) ?></td><td><?= V::h($c['statement']) ?></td>
            <td><?= V::bar($a ? (float) $a['value_pct'] : null, $a ? (float) $a['target_pct'] : (float) ($c['target_pct'] ?? $defaultTarget), $a ? (bool) $a['provisional'] : false) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$evidenceSpec): ?><tr><td colspan="3"><?= V::empty('No specification yet', 'Define the outcomes and assessment plan once — SAQF will reuse them every term.') ?></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <?php if ($insights): ?>
    <section class="card"><div class="card-h"><?= V::icon('spark') ?><h2>Assisted insights</h2><span class="muted small right">Statistical heuristics · for your judgement, not facts</span></div>
      <?php foreach ($insights as $r): ?><div class="check sev-info"><div class="check-ico">?</div><div style="flex:1"><div class="check-title"><?= V::h($r['title']) ?></div><div class="check-detail"><?= V::h($r['rationale']) ?></div><div class="tiny muted">Method: <?= V::h($r['method']) ?></div></div>
        <?php if ($user['role'] === 'faculty'): ?><button class="btn btn-sm" data-act="insight" data-id="<?= (int) $r['id'] ?>" data-accept="0">Dismiss</button><?php endif; ?></div><?php endforeach; ?>
    </section>
    <?php endif; ?>
  </div>

  <aside class="stack">
    <section class="card"><div class="card-h"><?= V::icon('cpu') ?><h2>Prepared automatically</h2></div>
      <div class="card-b small">
        <p class="muted">SAQF initialised this workspace on <?= V::h(V::date($o['initialized_at'], 'j M Y, H:i')) ?> from the <?= $o['source'] === 'sis' ? 'SIS teaching assignment' : 'manual assignment' ?> — nobody created it by hand.</p>
        <div class="ledger">
          <div><b><?= (int) ($ledger['field_populated'] ?? 0) ?></b><span>fields populated</span></div>
          <div><b><?= (int) ($ledger['record_inherited'] ?? 0) ?></b><span>records inherited</span></div>
          <div><b><?= (int) ($ledger['check_run'] ?? 0) ?></b><span>checks run</span></div>
          <div><b><?= (int) ($ledger['calculation'] ?? 0) ?></b><span>values calculated</span></div>
          <div><b><?= (int) ($ledger['auto_resolved'] ?? 0) ?></b><span>issues auto-cleared</span></div>
          <div><b><?= (int) ($ledger['evidence_linked'] ?? 0) ?></b><span>evidence links</span></div>
        </div>
      </div>
    </section>
    <section class="card"><div class="card-h"><h2>Course identity</h2><span class="right"><?= V::source('institution') ?></span></div>
      <div class="card-b small">
        <table>
          <tr><td class="muted">Code / title</td><td><strong><?= V::h($o['course_code']) ?></strong> <?= V::h($o['course_title']) ?></td></tr>
          <tr><td class="muted">Credit hours</td><td><?= V::h(rtrim(rtrim((string) $o['credits'], '0'), '.')) ?> · <?= V::h(rtrim(rtrim(number_format(Catalog::contactHours((float) $o['credits']), 1), '0'), '.')) ?> contact h <?= V::source('derived', 'Credits × ' . Policy::get('contact.weeks_per_term') . ' teaching weeks (policy)') ?></td></tr>
          <tr><td class="muted">Owner</td><td><?= V::h($o['department_name']) ?><br><span class="muted"><?= V::h($o['college_name']) ?></span></td></tr>
          <tr><td class="muted">In programs</td><td><?php foreach ($programs as $p): ?><div><a href="program.php?id=<?= (int) $p['program_id'] ?>"><?= V::h($p['code']) ?></a> · <?= V::pill($p['course_type'], $p['course_type'] === 'required' ? 'blue' : 'grey') ?> <span class="muted"><?= $p['level_no'] ? 'level ' . (int) $p['level_no'] : V::h($p['requirement_group']) ?></span></div><?php endforeach; ?></td></tr>
          <tr><td class="muted">Prerequisites</td><td><?php if (!$requisites): ?><span class="muted">None (or preparatory only)</span><?php endif; ?><?php foreach ($requisites as $r): ?><div><?= V::h($r['code']) ?> <span class="muted"><?= V::h($r['program_code']) ?><?= $r['kind'] === 'corequisite' ? ' · co-req' : '' ?></span></div><?php endforeach; ?></td></tr>
          <tr><td class="muted">Term / instructor</td><td><?= V::h($o['term_name']) ?> · <?= V::h($o['instructor_name'] ?? '—') ?> <?= V::source('sis') ?></td></tr>
          <tr><td class="muted">Specification</td><td><?= $o['spec_version_id'] ? 'v' . (int) Db::val('SELECT version_no FROM spec_versions WHERE id = ?', [$o['spec_version_id']]) . ' ' . V::source('inherited') : '<span class="muted">not yet defined</span>' ?></td></tr>
        </table>
        <?php if ($o['description']): ?><p style="margin-top:10px"><?= V::h($o['description']) ?><br><span class="tiny muted">Source: <?= V::h($o['description_source']) ?></span></p><?php endif; ?>
      </div>
    </section>
  </aside>
</div>

<?php elseif ($tab === 'structure'):
    $diff = $isDraft ? Specs::diff((int) $working['id']) : [];
    $readOnly = !$canEdit || ($working && in_array($working['status'], ['pending_hod', 'pending_qa'], true));
?>
<?php if ($working && in_array($working['status'], ['pending_hod', 'pending_qa'], true)): ?>
  <div class="alert alert-info">Revision v<?= (int) $working['version_no'] ?> is <?= $working['status'] === 'pending_hod' ? 'with the Head of Department' : 'with Quality Assurance' ?>. It is read-only until a decision is made; <?= $approved ? 'v' . (int) $approved['version_no'] . ' stays in effect.' : '' ?></div>
<?php elseif ($isDraft): ?>
  <div class="card" style="margin-bottom:16px"><div class="card-h"><h2>Draft revision v<?= (int) $working['version_no'] ?></h2><?= V::specStatus('draft') ?>
      <?php if ($working['decision_note']): ?><span class="pill pill-red">Returned: <?= V::h(mb_strimwidth((string) $working['decision_note'], 0, 90, '…')) ?></span><?php endif; ?>
      <div class="right row"><?php if ($canEdit): ?><button class="btn btn-sm btn-ghost" data-act="discard_draft" data-offering="<?= $oid ?>" data-confirm="Discard this draft? The approved specification stays in effect.">Discard draft</button>
        <button class="btn btn-sm btn-primary" data-act="submit_spec" data-offering="<?= $oid ?>" <?= $blocking && array_filter($blocking, static fn($f) => $f['severity'] === 'blocker') ? 'disabled title="Fix the items marked Must fix first — red records are never sent"' : '' ?>>Submit changes</button><?php endif; ?></div></div>
    <div class="card-b">
      <?php if ($working['decision_note']): ?><div class="alert alert-warning"><strong>Reviewer comment:</strong> <?= V::h($working['decision_note']) ?></div><?php endif; ?>
      <?php if (!$diff): ?><p class="muted small" style="margin:0">No differences from the approved v<?= (int) ($approved['version_no'] ?? 0) ?> yet. Edit anything below — only what changes will be reviewed.</p>
      <?php else: ?>
        <p class="small muted">Reviewers will see only these <?= count($diff) ?> change(s) — not the whole specification.</p>
        <div class="diff"><?php foreach ($diff as $d): ?><div class="diff-row"><div class="diff-label"><?= V::h($d['label']) ?><br><?= $d['academic'] ? V::pill('academic — needs approval', 'blue') : V::pill('minor — auto-approved', 'grey') ?></div><div class="diff-before"><?= $d['before'] === null ? '<span class="muted">—</span>' : V::h($d['before']) ?></div><div class="diff-after"><?= $d['after'] === null ? '<span class="muted">removed</span>' : V::h($d['after']) ?></div></div><?php endforeach; ?></div>
      <?php endif; ?>
    </div></div>
<?php elseif ($canEdit && $approved): ?>
  <div class="alert alert-info">You are viewing approved specification v<?= (int) $approved['version_no'] ?>, inherited automatically this term. Any edit starts a draft revision; students continue under v<?= (int) $approved['version_no'] ?> until the change is approved.</div>
<?php endif; ?>

<div class="split">
  <div class="stack">
    <section class="card"><div class="card-h"><h2>Course learning outcomes</h2><span class="muted small">Mapped only to PLOs of programs whose study plan contains <?= V::h($o['course_code']) ?></span></div>
      <div class="card-b">
        <?php foreach ($spec['clos'] ?? [] as $c): $issues = $byClo[(int) $c['id']] ?? []; ?>
          <div class="clo <?= $issues ? 'has-issue' : '' ?>" id="clo-<?= (int) $c['id'] ?>">
            <div class="clo-head"><div class="clo-code"><?= V::h($c['code']) ?></div>
              <div class="clo-text"><div><?= V::h($c['statement']) ?></div>
                <div class="tiny muted"><?= V::h($c['domain']) ?> · target <?= V::pct($c['target_pct'] ?? $defaultTarget) ?> <?= $c['target_pct'] === null ? V::source('policy', 'Institutional default target') : V::source('faculty') ?><?= $c['skills_tags'] ? ' · Jahiziah skills: ' . V::h($c['skills_tags']) : '' ?></div></div>
              <?php if (!$readOnly): ?><details><summary class="btn btn-sm">Edit</summary>
                <form data-api="save_clo" style="margin-top:8px;min-width:320px"><input type="hidden" name="offering" value="<?= $oid ?>"><input type="hidden" name="clo" value="<?= (int) $c['id'] ?>">
                  <div class="field"><textarea name="statement" required><?= V::h($c['statement']) ?></textarea></div>
                  <div class="field"><select name="domain"><?php foreach (Specs::DOMAINS as $d): ?><option <?= $d === $c['domain'] ? 'selected' : '' ?>><?= V::h($d) ?></option><?php endforeach; ?></select></div>
                  <div class="field"><input type="number" name="target_pct" min="0" max="100" step="0.5" placeholder="Target % (blank = policy default <?= V::h((string) $defaultTarget) ?>%)" value="<?= V::h($c['target_pct']) ?>"></div>
                  <div class="field small"><span class="tiny muted" style="display:block;margin-bottom:4px">Jahiziah skill tags (classification only — no approval needed)</span><?php foreach (Specs::SKILL_TAGS as $t): ?><label class="inline" style="font-weight:400;margin-right:8px"><input type="checkbox" name="skills[]" value="<?= V::h($t) ?>" <?= in_array($t, explode(',', (string) $c['skills_tags']), true) ? 'checked' : '' ?>> <?= V::h($t) ?></label><?php endforeach; ?></div>
                  <div class="row"><button class="btn btn-sm btn-primary" type="submit">Save</button><button class="btn btn-sm btn-red" type="button" data-act="delete_clo" data-offering="<?= $oid ?>" data-clo="<?= (int) $c['id'] ?>" data-confirm="Remove <?= V::h($c['code']) ?> from the draft?">Remove</button></div>
                </form></details><?php endif; ?>
            </div>
            <?php foreach ($programs as $p): $pid = (int) $p['program_id']; $plos = $spec['plos'][$pid] ?? []; if (!$plos) { continue; } $mapped = array_map('intval', array_column($c['maps'][$pid] ?? [], 'id')); ?>
              <div class="maprow"><span class="prog"><?= V::h($p['code']) ?> <?= $p['course_type'] === 'required' ? '' : '(elective)' ?></span>
                <?php foreach ($plos as $pl): $on = in_array((int) $pl['id'], $mapped, true); $sug = $recByClo[(int) $c['id']][(int) $pl['id']] ?? null; ?>
                  <button class="chip <?= $on ? 'on' : '' ?> <?= $sug && !$on ? 'sugg' : '' ?>" title="<?= V::h($pl['code'] . ' · ' . $pl['domain'] . ' — ' . $pl['statement']) ?>" <?= $readOnly ? 'disabled' : 'data-act="map" data-offering="' . $oid . '" data-clo="' . (int) $c['id'] . '" data-plo="' . (int) $pl['id'] . '" data-on="' . ($on ? '0' : '1') . '"' ?>><?= V::h($pl['code']) ?></button>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
            <?php foreach ($recByClo[(int) $c['id']] ?? [] as $sug): ?>
              <div class="small" style="margin-top:8px;padding:8px 10px;border:1px dashed #E3A0C0;border-radius:8px;background:#FFF8FB">
                <?= V::source('suggested', 'Assisted suggestion — not applied until you confirm') ?> <strong><?= V::h($sug['title']) ?></strong> · <span class="muted"><?= V::h($sug['rationale']) ?></span>
                <div class="tiny muted">Method: <?= V::h($sug['method']) ?> · score <?= V::h($sug['confidence']) ?></div>
                <?php if (!$readOnly): ?><div class="row" style="margin-top:6px"><button class="btn btn-sm" data-act="suggestion" data-offering="<?= $oid ?>" data-id="<?= (int) $sug['id'] ?>" data-accept="1">Accept mapping</button><button class="btn btn-sm btn-ghost" data-act="suggestion" data-offering="<?= $oid ?>" data-id="<?= (int) $sug['id'] ?>" data-accept="0">Dismiss</button></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php foreach ($issues as $f): if ($f['severity'] === 'info') { continue; } ?><div class="small" style="color:var(--red);margin-top:6px">● <?= V::h($f['title']) ?> — <?= V::h($f['remedy']) ?></div><?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$readOnly): ?>
          <details class="clo" <?= empty($spec['clos']) ? 'open' : '' ?>><summary class="strong" style="cursor:pointer">+ Add a learning outcome</summary>
            <form data-api="save_clo" style="margin-top:10px"><input type="hidden" name="offering" value="<?= $oid ?>">
              <div class="field"><label>Outcome statement</label><textarea name="statement" required placeholder="Start with an observable verb, e.g. “Analyze…”, “Design…”, “Evaluate…”"></textarea></div>
              <div class="grid g2"><div class="field"><label>Learning domain</label><select name="domain"><?php foreach (Specs::DOMAINS as $d): ?><option><?= V::h($d) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label>Target % (optional)</label><input type="number" name="target_pct" min="0" max="100" step="0.5" placeholder="Policy default <?= V::h((string) $defaultTarget) ?>%"></div></div>
              <button class="btn btn-primary btn-sm" type="submit">Add outcome</button></form></details>
        <?php endif; ?>
      </div>
    </section>

    <section class="card"><div class="card-h"><h2>Assessment plan</h2><span class="right" data-weight-total="<?= V::h((string) Policy::get('assessment.weight_total_pct')) ?>"></span></div>
      <div class="card-b tight"><div class="table-wrap"><table>
        <thead><tr><th>Assessment</th><th>Type</th><th style="width:90px">Week</th><th style="width:110px">Weight %</th><th>Measures</th><?php if (!$readOnly): ?><th></th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($spec['assessments'] ?? [] as $a): ?>
          <tr><td class="strong"><?= V::h($a['name']) ?></td><td><?= V::h(Specs::ASSESSMENT_KINDS[$a['kind']] ?? $a['kind']) ?></td><td><?= V::h($a['week'] ?? '—') ?></td>
            <td><?php if ($readOnly): ?><?= V::pct($a['weight_pct']) ?><?php else: ?><input type="number" min="0" max="100" step="0.5" data-weight data-id="<?= (int) $a['id'] ?>" value="<?= V::h(rtrim(rtrim((string) $a['weight_pct'], '0'), '.')) ?>" style="width:80px"><?php endif; ?></td>
            <td><?php foreach ($spec['clos'] as $c): $on = in_array((int) $c['id'], $a['clos'], true); ?><button class="chip <?= $on ? 'on' : '' ?>" <?= $readOnly ? 'disabled' : 'data-act="link" data-offering="' . $oid . '" data-assessment="' . (int) $a['id'] . '" data-clo="' . (int) $c['id'] . '" data-on="' . ($on ? '0' : '1') . '"' ?>><?= V::h($c['code']) ?></button> <?php endforeach; ?></td>
            <?php if (!$readOnly): ?><td class="num"><button class="btn btn-sm btn-ghost" data-act="delete_assessment" data-offering="<?= $oid ?>" data-assessment="<?= (int) $a['id'] ?>" data-confirm="Remove “<?= V::h($a['name']) ?>” from the draft?">Remove</button></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php if (!$readOnly && !empty($spec['assessments'])): ?><div class="row" style="padding:10px 14px;border-top:1px solid var(--line-2)"><span class="small muted">Change weights above, then</span><button class="btn btn-sm" type="button" onclick="var w=[];document.querySelectorAll('input[data-weight]').forEach(function(i){w.push(i.dataset.id+':'+i.value)});saqf.post('save_weights',{offering:'<?= $oid ?>',w:w});">Save weights</button></div><?php endif; ?>
        <?php if (!$readOnly): ?>
        <details style="padding:12px 14px;border-top:1px solid var(--line-2)"><summary class="strong" style="cursor:pointer">+ Add an assessment</summary>
          <form data-api="save_assessment" class="grid g4" style="margin-top:10px;align-items:end"><input type="hidden" name="offering" value="<?= $oid ?>">
            <div class="field"><label>Name</label><input type="text" name="name" required></div>
            <div class="field"><label>Type</label><select name="kind"><?php foreach (Specs::ASSESSMENT_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= V::h($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Weight %</label><input type="number" name="weight_pct" min="0" max="100" step="0.5" required></div>
            <div class="field"><label>Week</label><input type="number" name="week" min="1" max="18"></div>
            <div><button class="btn btn-primary btn-sm" type="submit">Add assessment</button></div></form></details>
        <?php endif; ?>
      </div>
    </section>

    <section class="card"><div class="card-h"><h2>Specification text</h2><span class="muted small">Objectives, strategies, content and resources (non-academic edits are auto-approved)</span></div>
      <div class="card-b">
        <form data-api="save_spec_text"><input type="hidden" name="offering" value="<?= $oid ?>">
          <div class="field"><label>Main objective</label><textarea name="objectives" <?= $readOnly ? 'readonly' : '' ?>><?= V::h($spec['version']['objectives'] ?? '') ?></textarea></div>
          <div class="field"><label>Teaching strategies</label><textarea name="strategies" <?= $readOnly ? 'readonly' : '' ?>><?= V::h($spec['version']['teaching_strategies'] ?? '') ?></textarea></div>
          <?php if (!$readOnly): ?><button class="btn btn-sm" type="submit">Save text</button><?php endif; ?></form>
        <details style="margin-top:14px"><summary class="strong" style="cursor:pointer">Course content (<?= count($spec['topics'] ?? []) ?> topics) and learning resources (<?= count($spec['resources'] ?? []) ?>)</summary>
          <div class="grid g2" style="margin-top:10px">
            <form data-api="save_topics"><input type="hidden" name="offering" value="<?= $oid ?>"><label>Topics — one per line, optional “| hours”</label>
              <textarea name="topics" rows="8" <?= $readOnly ? 'readonly' : '' ?>><?= V::h(implode("\n", array_map(static fn($t) => $t['topic'] . ($t['contact_hours'] !== null ? ' | ' . rtrim(rtrim((string) $t['contact_hours'], '0'), '.') : ''), $spec['topics'] ?? []))) ?></textarea>
              <div class="field-help">Contact hours available: <?= V::h(rtrim(rtrim(number_format(Catalog::contactHours((float) $o['credits']), 1), '0'), '.')) ?> (derived from credits).</div>
              <?php if (!$readOnly): ?><button class="btn btn-sm" type="submit">Save content</button><?php endif; ?></form>
            <form data-api="save_resources"><input type="hidden" name="offering" value="<?= $oid ?>">
              <?php foreach (Specs::RESOURCE_CATEGORIES as $k => $label): ?><div class="field"><label><?= V::h($label) ?></label><textarea name="res_<?= $k ?>" rows="2" <?= $readOnly ? 'readonly' : '' ?>><?= V::h(implode("\n", array_map(static fn($r) => $r['reference_text'], array_filter($spec['resources'] ?? [], static fn($r) => $r['category'] === $k)))) ?></textarea></div><?php endforeach; ?>
              <?php if (!$readOnly): ?><button class="btn btn-sm" type="submit">Save resources</button><?php endif; ?></form>
          </div></details>
      </div>
    </section>
  </div>
  <aside class="stack">
    <section class="card"><div class="card-h"><h2>Live validation</h2></div>
      <?php $specChecks = array_filter($openFindings, static fn($f) => $f['scope_type'] === 'spec' && $f['severity'] !== 'info'); ?>
      <?php if (!$specChecks): ?><div class="allclear"><strong>✓</strong><div><strong>Structure is valid</strong><div class="muted small">Every CLO is measurable, mapped and assessed; weights total <?= V::h((string) Policy::get('assessment.weight_total_pct')) ?>%.</div></div></div><?php endif; ?>
      <?php foreach ($specChecks as $f): ?><div class="check sev-<?= V::h($f['severity']) ?>"><div class="check-ico">!</div><div><div class="check-title"><?= V::h($f['title']) ?></div><div class="check-fix"><?= V::h($f['remedy']) ?></div></div></div><?php endforeach; ?>
      <div class="card-b tiny muted">Checks re-run on every change. You cannot submit while any “Must fix” item remains — SAQF never sends a red record to reviewers.</div>
    </section>
    <section class="card"><div class="card-h"><h2>Versions</h2></div><div class="card-b small">
      <?php foreach (Db::all('SELECT sv.*, u.full_name FROM spec_versions sv LEFT JOIN users u ON u.id = sv.decided_by WHERE sv.course_id = ? ORDER BY version_no DESC', [$courseId]) as $v): ?>
        <div class="row" style="margin-bottom:6px"><strong>v<?= (int) $v['version_no'] ?></strong> <?= V::specStatus($v['status']) ?> <span class="muted"><?= V::h($v['decision_route'] ? str_replace('_', ' ', $v['decision_route']) : '') ?> <?= V::h(V::date($v['decided_at'] ?? $v['created_at'])) ?></span></div>
      <?php endforeach; ?>
      <a href="report.php?type=spec&id=<?= (int) ($approved['id'] ?? 0) ?>">Printable specification (NCAAA-oriented)</a>
    </div></section>
  </aside>
</div>

<?php elseif ($tab === 'results'):
    $batches = Db::all('SELECT rb.*, u.full_name FROM result_batches rb LEFT JOIN users u ON u.id = rb.imported_by WHERE rb.offering_id = ? ORDER BY rb.imported_at', [$oid]);
    $plo = Db::all('SELECT pa.*, p.code AS plo_code, p.statement, pr.code AS program_code FROM plo_achievement pa JOIN plos p ON p.id = pa.plo_id JOIN programs pr ON pr.id = pa.program_id WHERE pa.offering_id = ? ORDER BY pr.code, p.code', [$oid]);
    $pendingLms = array_filter(Integrations::lms()->pending(), static fn($b) => $b['course'] === $o['course_code'] && $b['term'] === $o['term_code']);
?>
<div class="split">
  <div class="stack">
    <section class="card"><div class="card-h"><h2>CLO achievement</h2><span class="muted small right"><?= V::source('calculated') ?> recalculated automatically whenever results arrive</span></div>
      <div class="card-b tight"><div class="table-wrap"><table>
        <thead><tr><th>CLO</th><th>Measured by</th><th style="width:280px">Achievement</th><th class="num">Students</th><th class="num">Evidence coverage</th></tr></thead><tbody>
        <?php foreach ($evidenceSpec['clos'] ?? [] as $c): $a = $ach[$c['lineage_key']] ?? null; $names = array_map(static fn($aid) => current(array_filter($evidenceSpec['assessments'], static fn($x) => (int) $x['id'] === $aid))['name'] ?? '', $c['assessments']); ?>
          <tr><td><strong><?= V::h($c['code']) ?></strong><div class="tiny muted"><?= V::h(mb_strimwidth($c['statement'], 0, 70, '…')) ?></div></td><td class="small"><?= V::h(implode(', ', $names)) ?></td>
            <td><?= V::bar($a ? (float) $a['value_pct'] : null, $a ? (float) $a['target_pct'] : (float) ($c['target_pct'] ?? $defaultTarget), $a ? (bool) $a['provisional'] : false) ?></td>
            <td class="num"><?= $a ? (int) $a['students_assessed'] : '—' ?></td><td class="num"><?= $a ? V::pct($a['coverage_pct'], 0) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="card-b tiny muted">Method (policy): <?= Policy::get('achievement.method') === 'average' ? 'mean of students\' CLO scores' : '% of students scoring ≥ ' . V::h((string) Policy::get('achievement.student_threshold_pct')) . '% on the CLO\'s assessments (weighted by assessment weight)' ?>. Target: course target or the <?= V::h((string) $defaultTarget) ?>% institutional default. “Provisional” means not all of the CLO's assessments have results yet.</div>
      </div>
    </section>
    <section class="card"><div class="card-h"><h2>Contribution to program outcomes</h2><span class="muted small right">Mean of mapped CLOs, per program</span></div>
      <div class="card-b tight"><table><thead><tr><th>Program</th><th>PLO</th><th style="width:280px">Contribution</th><th class="num">CLOs</th></tr></thead><tbody>
      <?php foreach ($plo as $p): ?><tr><td><?= V::h($p['program_code']) ?></td><td><strong><?= V::h($p['plo_code']) ?></strong> <span class="tiny muted"><?= V::h(mb_strimwidth($p['statement'], 0, 80, '…')) ?></span></td><td><?= V::bar((float) $p['value_pct'], Policy::get('plo.target_pct'), (bool) $p['provisional']) ?></td><td class="num"><?= (int) $p['contributing_clos'] ?></td></tr><?php endforeach; ?>
      <?php if (!$plo): ?><tr><td colspan="4"><?= V::empty('No results yet', 'PLO contributions appear automatically once results arrive.') ?></td></tr><?php endif; ?>
      </tbody></table></div></section>
  </div>
  <aside class="stack">
    <section class="card"><div class="card-h"><h2>Evidence sources</h2><span class="right"><?= V::source('lms') ?></span></div>
      <div class="card-b small">
        <?php foreach ($batches as $b): ?><div style="margin-bottom:10px"><strong><?= V::h(strtoupper($b['source'])) ?></strong> · <?= V::h(V::date($b['imported_at'], 'j M Y H:i')) ?><br><span class="muted"><?= V::h($b['assessments']) ?> · <?= (int) $b['rows_count'] ?> rows · <?= $b['full_name'] ? 'uploaded by ' . V::h($b['full_name']) : 'imported automatically' ?></span><br><span class="mono tiny">sha256 <?= V::h(substr($b['checksum'], 0, 16)) ?>…</span></div><?php endforeach; ?>
        <?php if (!$batches): ?><p class="muted">No results have been published for this offering yet.</p><?php endif; ?>
        <?php foreach ($pendingLms as $pb): ?><p class="muted">Expected from the LMS: <strong><?= V::h($pb['label']) ?></strong> (scheduled <?= V::h(V::date($pb['published_at'])) ?>). SAQF imports it automatically when it is published.</p><?php endforeach; ?>
        <?php if ($canEdit): ?>
          <button class="btn btn-sm" data-act="lms_sync" data-offering="<?= $oid ?>">Check the LMS now</button>
          <details style="margin-top:12px"><summary class="small" style="cursor:pointer">Upload results instead (CSV fallback)</summary>
            <form method="post" enctype="multipart/form-data" style="margin-top:8px"><?= Csrf::field() ?><input type="file" name="results" accept=".csv,text/csv" required>
              <div class="field-help">Columns: <span class="mono">student</span> (pseudonymous key) then one column per assessment name, values 0–100. Names must match: <?= V::h(implode(', ', array_column($evidenceSpec['assessments'] ?? [], 'name'))) ?>.</div>
              <button class="btn btn-sm" type="submit">Upload</button></form></details>
        <?php endif; ?>
      </div></section>
  </aside>
</div>

<?php elseif ($tab === 'improve'):
    $owners = Db::all('SELECT id, full_name FROM users WHERE role IN ("faculty","hod") AND status = "active" AND department_id = ? ORDER BY full_name', [$o['owner_department_id']]);
?>
<div class="stack">
  <?php if (!$actionsCourse): ?><div class="card"><?= V::empty('No improvement actions for this course', 'When an outcome misses its target, SAQF drafts the improvement record with the evidence and asks for your academic response.') ?></div><?php endif; ?>
  <?php foreach ($actionsCourse as $ia): $mine = $user['role'] === 'faculty' && ((int) $o['instructor_id'] === $user['id'] || (int) $ia['owner_id'] === $user['id']); ?>
  <section class="card" id="ia-<?= (int) $ia['id'] ?>">
    <div class="card-h"><h2><?= V::h($ia['title']) ?></h2><?= V::pill(Improvements::STATUS[$ia['status']] ?? $ia['status'], ['draft' => 'red', 'open' => 'blue', 'in_progress' => 'blue', 'completed' => 'green', 'cancelled' => 'grey'][$ia['status']] ?? 'grey') ?>
      <span class="right muted small">from <?= V::h($ia['origin_term']) ?> · <?= $ia['created_by'] ? 'created by a person' : 'drafted by SAQF' ?></span></div>
    <div class="card-b">
      <p class="small"><?= V::source('calculated', 'Evidence assembled automatically from results, history and earlier actions') ?> <?= V::h($ia['evidence_summary']) ?></p>
      <?php if ($ia['status'] === 'draft'): ?>
        <?php if ($mine || Authz::canDecideCourse($user, $courseId)): ?>
        <form data-api="improvement_commit" class="fieldset"><input type="hidden" name="id" value="<?= (int) $ia['id'] ?>">
          <div class="field"><label>Academic response — what will change, and where <?= V::source('faculty') ?></label><textarea name="action_text" required minlength="15" placeholder="e.g. Add a formative checkpoint on test planning in week 9 with rubric feedback…"></textarea>
            <div class="field-help">SAQF does not invent the academic solution. It will measure the next offering against the <?= V::pct($ia['baseline_pct']) ?> baseline.</div></div>
          <div class="grid g2"><div class="field"><label>Owner</label><select name="owner"><?php foreach ($owners as $ow): ?><option value="<?= (int) $ow['id'] ?>" <?= (int) $ow['id'] === (int) $ia['owner_id'] ? 'selected' : '' ?>><?= V::h($ow['full_name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Deadline</label><input type="date" name="due_on" required value="<?= V::h($ia['due_on']) ?>"></div></div>
          <button class="btn btn-primary btn-sm" type="submit">Commit improvement action</button></form>
        <?php else: ?><p class="muted small">Waiting for the instructor's academic response.</p><?php endif; ?>
      <?php else: ?>
        <div class="grid g3 small">
          <div><div class="muted">Academic response</div><div><?= V::h($ia['action_text']) ?></div></div>
          <div><div class="muted">Owner · deadline</div><div><?= V::h($ia['owner_name']) ?> · <?= V::h(V::date($ia['due_on'])) ?></div><?php if ($ia['completion_note']): ?><div class="muted" style="margin-top:6px">Completion: <?= V::h($ia['completion_note']) ?></div><?php endif; ?></div>
          <div><div class="muted">Measured effect</div>
            <div><?= V::pill(Improvements::EFFECT[$ia['effect']] ?? $ia['effect'], ['improved' => 'green', 'declined' => 'red', 'similar' => 'amber'][$ia['effect']] ?? 'grey') ?></div>
            <?php if ($ia['followup_pct'] !== null): ?><div class="small" style="margin-top:4px"><?= V::pct($ia['baseline_pct']) ?> (<?= V::h($ia['origin_term']) ?>) → <strong><?= V::pct($ia['followup_pct']) ?></strong> (<?= V::h($ia['followup_term']) ?>), target <?= V::pct($ia['target_pct']) ?></div><div class="tiny muted">Association over time, not proof of causation.</div><?php endif; ?></div>
        </div>
        <?php if (in_array($ia['status'], ['open', 'in_progress'], true) && ((int) $ia['owner_id'] === $user['id'] || Authz::canDecideCourse($user, $courseId))): ?>
          <form data-api="improvement_status" class="row" style="margin-top:12px"><input type="hidden" name="id" value="<?= (int) $ia['id'] ?>">
            <select name="status" style="width:auto"><?php if ($ia['status'] === 'open'): ?><option value="in_progress">Mark in progress</option><?php endif; ?><option value="completed">Mark completed</option><option value="cancelled">Cancel</option></select>
            <input type="text" name="note" placeholder="Evidence of completion / reason" style="flex:1;min-width:240px"><button class="btn btn-sm" type="submit">Update</button></form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>

<?php elseif ($tab === 'report'):
    $narr = [];
    foreach (Db::all('SELECT * FROM offering_narratives WHERE offering_id = ?', [$oid]) as $n) {
        $narr[$n['section_key']] = $n;
    }
    $snap = Db::one('SELECT * FROM snapshots WHERE kind = "course_report" AND scope_id = ?', [$oid]);
?>
<div class="split">
  <div class="stack">
    <section class="card"><div class="card-h"><h2>Course report</h2><a class="btn btn-sm right" href="report.php?type=course&id=<?= $oid ?>">Open generated report</a></div>
      <div class="card-b small">
        <p>The course report is <strong>generated</strong> from the structured record: course identity, outcomes, mappings, assessment results, CLO/PLO achievement, gaps, improvement actions and their effects, and the approval history. Only the interpretation below is written by a person.</p>
        <?php if ($snap): ?><div class="alert alert-info">Frozen on <?= V::h(V::date($snap['created_at'], 'j M Y H:i')) ?> when the term closed · sha256 <span class="mono"><?= V::h(substr($snap['sha256'], 0, 20)) ?>…</span></div><?php endif; ?>
      </div></section>
    <?php foreach (['interpretation' => 'Interpretation of results', 'difficulties' => 'Difficulties encountered (optional)', 'recommendations' => 'Recommendations for the next offering (optional)'] as $key => $label): ?>
      <section class="card"><div class="card-h"><h2><?= V::h($label) ?></h2><?= V::source('faculty') ?></div><div class="card-b">
        <?php if ($canEdit): ?><form data-api="narrative"><input type="hidden" name="offering" value="<?= $oid ?>"><input type="hidden" name="section" value="<?= $key ?>"><textarea name="content" rows="4"><?= V::h($narr[$key]['content'] ?? '') ?></textarea><button class="btn btn-sm" type="submit" style="margin-top:8px">Save</button></form>
        <?php else: ?><p><?= isset($narr[$key]) ? nl2br(V::h($narr[$key]['content'])) : '<span class="muted">Not provided.</span>' ?></p><?php endif; ?>
      </div></section>
    <?php endforeach; ?>
  </div>
</div>

<?php else: // history
    $lineages = $evidenceSpec ? $evidenceSpec['clos'] : ($spec['clos'] ?? []);
    $terms = Db::all('SELECT DISTINCT t.id, t.name, t.sequence FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.course_id = ? ORDER BY t.sequence', [$courseId]);
    $events = Db::all("SELECT * FROM audit_log WHERE (object_type = 'offering' AND object_id = ?) OR (object_type = 'spec_version' AND object_id IN (SELECT id FROM spec_versions WHERE course_id = ?)) OR (object_type = 'improvement' AND object_id IN (SELECT id FROM improvement_actions WHERE course_id = ?)) ORDER BY id DESC LIMIT 60", [(string) $oid, $courseId, $courseId]);
?>
<div class="stack">
  <section class="card"><div class="card-h"><h2>Achievement across terms</h2><span class="muted small right">Institutional memory — survives staff changes</span></div>
    <div class="card-b tight"><div class="table-wrap"><table class="matrix">
      <thead><tr><th>CLO</th><?php foreach ($terms as $t): ?><th><?= V::h($t['name']) ?></th><?php endforeach; ?><th>Trend</th></tr></thead><tbody>
      <?php foreach ($lineages as $c): $hist = []; foreach (Achievement::history($c['lineage_key']) as $h) { $hist[(int) $h['sequence']] = $h; } ?>
        <tr><td><strong><?= V::h($c['code']) ?></strong> <span class="tiny muted"><?= V::h(mb_strimwidth($c['statement'], 0, 60, '…')) ?></span></td>
          <?php foreach ($terms as $t): $h = $hist[(int) $t['sequence']] ?? null; ?><td><?php if ($h): ?><span class="heat <?= (int) $h['provisional'] ? 'heat-prov' : ((int) $h['met'] ? 'heat-ok' : 'heat-low') ?>"><?= V::pct($h['value_pct']) ?></span><?php else: ?><span class="heat heat-none">—</span><?php endif; ?></td><?php endforeach; ?>
          <td><?= V::spark(array_map(static fn($h) => (int) $h['provisional'] ? null : (float) $h['value_pct'], array_values($hist)), (float) ($c['target_pct'] ?? $defaultTarget)) ?></td></tr>
      <?php endforeach; ?></tbody></table></div></div></section>
  <section class="card"><div class="card-h"><h2>Audit trail for this course</h2><a class="right small" href="report.php?type=course&id=<?= $oid ?>">Report</a></div>
    <div class="card-b"><ul class="timeline">
      <?php foreach ($events as $e): ?><li class="<?= $e['actor_type'] === 'user' ? 'usr' : ($e['actor_type'] === 'integration' ? 'int' : 'sys') ?>"><div class="when"><?= V::h(V::date($e['occurred_at'], 'j M Y H:i')) ?> · <?= V::h($e['actor_name']) ?><?= $e['actor_type'] !== 'user' ? ' (' . V::h($e['actor_type']) . ')' : '' ?></div><div><?= V::h($e['summary']) ?></div><?php if ($e['reason']): ?><div class="small muted">Reason: <?= V::h($e['reason']) ?></div><?php endif; ?></li><?php endforeach; ?>
    </ul></div></section>
</div>
<?php endif;
V::footer();

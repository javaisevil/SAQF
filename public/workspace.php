<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Config;
use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Core\Session;
use Saqf\Integration\Gradebook;
use Saqf\Integration\Integrations;
use Saqf\Quality\Achievement;
use Saqf\Quality\Catalog;
use Saqf\Quality\Closeout;
use Saqf\Quality\Evidence;
use Saqf\Quality\Findings;
use Saqf\Quality\Improvements;
use Saqf\Quality\Rules;
use Saqf\Quality\Sections;
use Saqf\Quality\Specs;
use Saqf\Quality\Status;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership']);
$o = Authz::offering($user, (int) ($_GET['id'] ?? 0));
$oid = (int) $o['id'];
$courseId = (int) $o['course_id'];
$canEdit = Authz::canEditOffering($user, $o);
$canContribute = Authz::canContribute($user, $o);
$sections = Sections::forOffering($oid);
$mySections = $user['role'] === 'faculty' ? Sections::taughtBy($oid, $user['id']) : [];
/** A section named in a form, checked: it must be a section of this course, and a section instructor may name only their own. */
$checkedSection = static function (?string $raw) use ($sections, $mySections, $canEdit): ?string {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return count($mySections) === 1 && !$canEdit ? $mySections[0] : null;
    }
    $code = Sections::code($raw);
    if ($code === null || !in_array($code, array_column($sections, 'section_code'), true) || (!$canEdit && !in_array($code, $mySections, true))) {
        throw new InvalidArgumentException('Choose one of the sections you teach in this course.');
    }
    return $code;
};
$tab = in_array($_GET['tab'] ?? '', ['overview', 'structure', 'results', 'evidence', 'improve', 'report', 'closeout', 'history'], true) ? $_GET['tab'] : 'overview';

// Assessment evidence: upload (coordinator or section instructor) and removal (with a reason).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (isset($_FILES['evidence']) || ($_POST['op'] ?? '') === 'remove_evidence')) {
    saqf_require_post();
    if (!$canContribute) {
        Authz::deny('changing evidence');
    }
    try {
        if (isset($_FILES['evidence'])) {
            \Saqf\Core\Throttle::check('upload:' . $user['id'], 30, 3600, 'Too many uploads in the last hour. Please try again later.');
            $aid = (int) ($_POST['assessment'] ?? 0);
            $section = $checkedSection((string) ($_POST['section'] ?? ''));
            Evidence::store($o, $_FILES['evidence'], (string) ($_POST['kind'] ?? ''), (string) ($_POST['title'] ?? ''), $aid ?: null, $section, $user);
            Session::flash('success', 'Evidence added to the course file. Any matching evidence request cleared automatically.');
        } else {
            $ev = Db::one('SELECT * FROM evidence_files WHERE id = ? AND offering_id = ?', [(int) ($_POST['id'] ?? 0), $oid]);
            if (!$ev || !($canEdit || (int) $ev['uploaded_by'] === $user['id'])) {
                Authz::deny('removing evidence');
            }
            Evidence::remove((int) $ev['id'], $user, (string) ($_POST['reason'] ?? ''));
            Session::flash('success', 'Evidence removed from the course file (kept in the audit trail).');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        Session::flash('error', $e->getMessage());
    }
    saqf_redirect('workspace.php?id=' . $oid . '&tab=evidence');
}

// Several evidence files at once. The form pre-fills what each file is from its name (see evidence.js and
// Evidence::suggest); the person has corrected it by the time it arrives. Without JavaScript the same suggestion is
// made here. Each file is checked, scanned and audited on its own: one bad file never blocks the others.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_FILES['evidence_files'])) {
    saqf_require_post();
    if (!$canContribute) {
        Authz::deny('changing evidence');
    }
    $batch = $_FILES['evidence_files'];
    $added = 0;
    $used = [];
    $refused = [];
    try {
        $names = is_array($batch['name'] ?? null) ? $batch['name'] : [];
        $present = array_keys(array_filter($names, static fn($n, $i) => (int) ($batch['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE, ARRAY_FILTER_USE_BOTH));
        if (!$present) {
            throw new InvalidArgumentException('Choose at least one file.');
        }
        if (count($present) > 12) {
            throw new InvalidArgumentException('Add up to 12 files at a time.');
        }
        $specNow = $o['spec_version_id'] ? Specs::load((int) $o['spec_version_id']) : null;
        $asm = array_map(static fn($a) => ['id' => (int) $a['id'], 'name' => (string) $a['name']], $specNow['assessments'] ?? []);
        $post = static fn(string $k, int $i): ?string => is_array($_POST[$k] ?? null) && is_string($_POST[$k][$i] ?? null) ? trim($_POST[$k][$i]) : null;
        $section = $checkedSection((string) ($_POST['section'] ?? ''));
        foreach ($present as $i) {
            $label = mb_strimwidth(Evidence::maskIdentifiers((string) $names[$i]), 0, 60, '…');
            try {
                \Saqf\Core\Throttle::check('upload:' . $user['id'], 30, 3600, 'Too many uploads in the last hour. Please try again later.');
                $guess = Evidence::suggest((string) $names[$i], $asm);
                $kind = $post('item_kind', $i) ?: $guess['kind'];
                $aid = $post('item_assessment', $i);
                $aid = $aid === null ? $guess['assessment'] : ((int) $aid ?: null);
                $title = $post('item_title', $i);
                $title = $title === null || $title === '' ? $guess['title'] : $title;
                $used[$title] = ($used[$title] ?? 0) + 1;
                Evidence::store($o, ['name' => $names[$i], 'tmp_name' => $batch['tmp_name'][$i], 'size' => $batch['size'][$i], 'error' => $batch['error'][$i]], $kind, $used[$title] > 1 ? $title . ' (' . $used[$title] . ')' : $title, $aid, $section, $user);
                $added++;
            } catch (InvalidArgumentException | RuntimeException $e) {
                $refused[] = $label . ' (' . rtrim($e->getMessage(), '.') . ')';
                if (str_contains($e->getMessage(), 'Too many uploads')) {
                    break;
                }
            }
        }
        if ($added) {
            Session::flash('success', ($added === 1 ? '1 file added' : $added . ' files added') . ' to the course file. Any matching evidence request cleared automatically.');
        }
        if ($refused) {
            Session::flash('error', 'Not added: ' . implode('; ', $refused) . '.');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        Session::flash('error', $e->getMessage());
    }
    saqf_redirect('workspace.php?id=' . $oid . '&tab=evidence');
}

// Gradebook upload (when results are not in the LMS), in two steps: SAQF first shows what it understood from the
// file (nothing is written), and a person confirms. Student numbers become keyed pseudonyms while the file is read,
// so neither the preview nor the session ever holds one. Validated strictly, never trusted.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_FILES['results'])) {
    saqf_require_post();
    if (!$canContribute) {
        Authz::deny('uploading results');
    }
    $f = $_FILES['results'];
    try {
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('Upload a CSV file under 2 MB.');
        }
        $parsed = Gradebook::parse($f['tmp_name'], Gradebook::SYSTEM);
        $known = [];
        foreach (Db::all('SELECT id, name FROM assessments WHERE spec_version_id = ?', [$o['spec_version_id'] ?: 0]) as $a) {
            $known[mb_strtolower(trim((string) $a['name']))] = (int) $a['id'];
        }
        $matched = [];
        $ignored = [];
        foreach ($parsed['results'] as $name => $scores) {
            isset($known[mb_strtolower(trim((string) $name))]) ? $matched[$name] = $scores : $ignored[] = (string) $name;
        }
        // A section instructor contributes marks for their own section(s) only: the file may not name another section,
        // with one section everything is filed under it, and with several the file must say which student is where.
        $tag = $parsed['sections'] ?: null;
        if (!$canEdit) {
            if (!$mySections) {
                throw new InvalidArgumentException('You are not assigned to a section of this course.');
            }
            if (array_diff(array_map(static fn($c) => Sections::code($c), array_values(array_unique($parsed['sections']))), $mySections)) {
                throw new InvalidArgumentException('The file names a section you do not teach. Remove those rows, or ask the course coordinator to upload them.');
            }
            if (count($mySections) === 1) {
                $tag = $mySections[0];
            } elseif (!$parsed['sections']) {
                throw new InvalidArgumentException('You teach more than one section of this course: add a "section" column so each student is filed under the right one.');
            }
        }
        $stats = [];
        $students = [];
        foreach ($matched as $name => $scores) {
            $aid = $known[mb_strtolower(trim((string) $name))];
            $have = array_flip(Db::col('SELECT student_ref FROM assessment_results WHERE offering_id = ? AND assessment_id = ?', [$oid, $aid]));
            $vals = array_values($scores);
            $stats[$name] = ['n' => count($vals), 'mean' => $vals ? array_sum($vals) / count($vals) : 0, 'min' => $vals ? min($vals) : 0, 'max' => $vals ? max($vals) : 0,
                'zeros' => count(array_filter($vals, static fn($v) => $v == 0)), 'updates' => count(array_intersect_key($scores, $have)), 'new' => count(array_diff_key($scores, $have))];
            $students += $scores;
        }
        $_SESSION['gb_preview'] = [$oid => ['at' => time(), 'file' => mb_substr(preg_replace('/[^\p{L}\p{N} ._()-]+/u', '', basename((string) $f['name'])), 0, 80), 'results' => $matched, 'ignored' => $ignored,
            'tag' => $tag, 'stats' => $stats, 'students' => count($students), 'sections' => array_values(array_unique(array_values($parsed['sections'])))]];
    } catch (InvalidArgumentException $e) {
        Session::flash('error', $e->getMessage());
    }
    saqf_redirect('workspace.php?id=' . $oid . '&tab=results');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($_POST['op'] ?? '', ['gb_confirm', 'gb_cancel'], true)) {
    saqf_require_post();
    if (!$canContribute) {
        Authz::deny('uploading results');
    }
    $pv = $_SESSION['gb_preview'][$oid] ?? null;
    unset($_SESSION['gb_preview']);
    try {
        if ($_POST['op'] === 'gb_cancel') {
            Session::flash('info', 'Nothing was imported.');
        } elseif (!$pv || time() - (int) $pv['at'] > 900 || !$pv['results']) {
            throw new InvalidArgumentException('The preview expired or had nothing to import. Upload the file again.');
        } else {
            $r = Achievement::import($oid, $pv['results'], 'upload', null, $pv['tag'], $canEdit ? null : $mySections);
            if ($pv['ignored']) {
                \Saqf\Core\Audit::record('results.columns_ignored', 'offering', $oid, "{$o['course_code']}: columns in the uploaded file that are not in the course specification were not imported: " . mb_strimwidth(implode(', ', $pv['ignored']), 0, 250, '…'));
            }
            Session::flash('success', "{$r['rows']} results imported" . (is_string($pv['tag']) ? " for section {$pv['tag']}" : '') . '; achievement recalculated automatically.');
        }
    } catch (InvalidArgumentException $e) {
        Session::flash('error', $e->getMessage());
    }
    saqf_redirect('workspace.php?id=' . $oid . '&tab=results');
}

// Course file closeout: a Head of Department or Quality reviewer accepts the evidence set or returns it
// with a note. SAQF never accepts evidence by itself; the instructor cannot accept their own.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['op'] ?? '') === 'closeout_review') {
    saqf_require_post();
    if (!Closeout::canReview($user, $o)) {
        Authz::deny('reviewing the course file');
    }
    try {
        Closeout::reviewEvidence($user, $o, (string) ($_POST['decision'] ?? ''), (string) ($_POST['note'] ?? ''));
        Session::flash('success', ($_POST['decision'] ?? '') === 'accepted' ? 'Evidence accepted. If a file changes later, the review is due again.' : 'Evidence returned to the instructor with your note.');
    } catch (InvalidArgumentException $e) {
        Session::flash('error', $e->getMessage());
    }
    saqf_redirect('workspace.php?id=' . $oid . '&tab=closeout');
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
$evidenceFiles = Evidence::forOffering($oid);
$evidenceRequested = [];
foreach ($openFindings as $f) {
    if ($f['rule_code'] === 'EVIDENCE_REQUESTED') {
        $evidenceRequested = (json_decode((string) $f['context'], true) ?: [])['assessments'] ?? [];
    }
}

$closeout = Closeout::forOffering($o);
$teachingLine = count($sections) > 1 ? 'Coordinator ' . V::h($o['instructor_name'] ?? '—') . ' · ' . count($sections) . ' sections' : V::h($o['instructor_name'] ?? 'No instructor');
V::header($o['course_code'] . ' · ' . $o['course_title'], $user, ['subtitle' => V::h($o['term_name']) . ' · ' . $teachingLine . ' · ' . (int) $o['enrolled'] . ' students' . ($mySections && !$canEdit ? ' · you teach section ' . V::h(implode(', ', $mySections)) : '') . ($o['term_status'] === 'closed' ? ' · <strong>closed (read-only record)</strong>' : '')]);
echo V::tabs([
    'overview' => 'Overview' . $countBadge(count($blocking)),
    'structure' => 'Outcomes & assessment',
    'results' => 'Results & achievement',
    'evidence' => 'Evidence' . $countBadge(count($evidenceRequested)),
    'improve' => 'Improvement' . $countBadge($improveCount),
    'report' => 'Course report',
    'closeout' => 'Course file closeout' . $countBadge($closeout['counts']['missing'] + $closeout['counts']['review']),
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
      <div class="card-h"><h2>What needs attention</h2><span class="muted small right">SAQF re-checks this course after every change</span></div>
      <?php if (!$blocking && !$overridden): ?>
        <div class="allclear"><strong>✓</strong><div><strong>Everything checks out.</strong><div class="muted small">Outcomes, links to the program, assessments, weights and evidence were all checked automatically.</div></div></div>
      <?php endif; ?>
      <?php foreach (array_merge($blocking, $overridden) as $f): $pending = Db::one('SELECT * FROM overrides WHERE finding_id = ? ORDER BY id DESC LIMIT 1', [$f['id']]); ?>
        <div class="check sev-<?= V::h($f['severity']) ?> st-<?= V::h($f['status']) ?>">
          <div class="check-ico"><?= $f['status'] === 'overridden' ? '—' : ($f['severity'] === 'blocker' ? '!' : '•') ?></div>
          <div style="flex:1">
            <div class="row"><span class="check-title"><?= V::h($f['title']) ?></span><?= V::category($f['category']) ?><?= $f['status'] === 'overridden' ? V::source('overridden', (string) $f['resolution_note']) : '' ?><?= $f['owner_role'] !== 'faculty' ? V::pill('with ' . strtoupper($f['owner_role']), 'grey') : '' ?></div>
            <div class="check-detail"><?= V::h($f['detail']) ?></div>
            <?php if ($f['remedy'] && $f['status'] === 'open'): ?><div class="check-fix"><strong>What to do:</strong> <?= V::h($f['remedy']) ?></div><?php endif; ?>
            <?php if ($f['why']): ?><details class="why"><summary>Why does this matter?</summary><div class="check-why"><?= V::h($f['why']) ?></div></details><?php endif; ?>
            <?php if ($pending && $pending['status'] === 'requested'): ?><div class="small muted" style="margin-top:4px">Exception requested on <?= V::h(V::date($pending['requested_at'])) ?> — waiting for Quality.</div>
            <?php elseif ($canEdit && $f['status'] === 'open' && in_array($f['category'], ['policy', 'quality_risk', 'evidence'], true)): ?>
              <details style="margin-top:6px"><summary class="small" style="cursor:pointer;color:var(--accent-ink)">Ask Quality for an exception</summary>
                <form data-api="override_request" style="margin-top:8px"><input type="hidden" name="finding" value="<?= (int) $f['id'] ?>"><input type="hidden" name="offering" value="<?= $oid ?>">
                  <textarea name="justification" required minlength="20" placeholder="Explain why this course should be treated as an exception (Quality sees this, and it is kept in the audit log)."></textarea>
                  <button class="btn btn-sm" type="submit">Send to Quality</button></form></details>
            <?php endif; ?>
          </div>
          <?php if ($f['status'] === 'open' && in_array($f['category'], ['validation'], true) && $canEdit): ?><a class="btn btn-sm" href="<?= $base ?>&tab=structure">Fix</a><?php endif; ?>
          <?php if (in_array($f['rule_code'], ['IMPROVEMENT_MISSING', 'CLO_TARGET_MISSED'], true) && $canEdit): ?><a class="btn btn-sm" href="<?= $base ?>&tab=improve">Respond</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php $info = array_filter($openFindings, static fn($f) => $f['severity'] === 'info'); if ($info): ?>
        <details class="more"><summary>+ <?= V::h(count($info) === 1 ? '1 note for information' : count($info) . ' notes for information') ?></summary>
          <?php foreach ($info as $f): ?><div class="check sev-info"><div class="check-ico">i</div><div><div class="check-title"><?= V::h($f['title']) ?></div><div class="check-detail"><?= V::h($f['detail']) ?></div></div></div><?php endforeach; ?>
        </details>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-h"><h2>How students are doing — <?= V::h($o['term_name']) ?></h2><a class="right small" href="<?= $base ?>&tab=results">Details</a></div>
      <div class="card-b tight"><table>
        <thead><tr><th>Learning outcome</th><th style="width:270px">Students who met it</th></tr></thead><tbody>
        <?php foreach ($evidenceSpec['clos'] ?? [] as $c): $a = $ach[$c['lineage_key']] ?? null; ?>
          <tr><td><strong><?= V::h($c['code']) ?></strong> <?= V::h($c['statement']) ?></td>
            <td><?= V::bar($a ? (float) $a['value_pct'] : null, $a ? (float) $a['target_pct'] : (float) ($c['target_pct'] ?? $defaultTarget), $a ? (bool) $a['provisional'] : false) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$evidenceSpec): ?><tr><td colspan="2"><?= V::empty('No specification yet', 'Define the outcomes and assessment plan once — SAQF will reuse them every term.') ?></td></tr><?php endif; ?>
        </tbody></table>
        <?php if ($evidenceSpec): ?><div class="card-b tiny muted">Each bar is the share of students who met that outcome; the line marks the goal. “So far” means more grades are still to come.</div><?php endif; ?></div>
    </section>

    <?php if ($insights): ?>
    <section class="card"><div class="card-h"><?= V::icon('spark') ?><h2>Patterns SAQF noticed</h2><span class="muted small right">Simple statistics to support your judgement — not conclusions</span></div>
      <?php foreach ($insights as $r): ?><div class="check sev-info"><div class="check-ico">?</div><div style="flex:1"><div class="check-title"><?= V::h($r['title']) ?></div><div class="check-detail"><?= V::h($r['rationale']) ?></div><div class="tiny muted" title="<?= V::h($r['method']) ?>">How was this worked out? (hover)</div></div>
        <?php if ($user['role'] === 'faculty'): ?><button class="btn btn-sm" data-act="insight" data-id="<?= (int) $r['id'] ?>" data-accept="0">Dismiss</button><?php endif; ?></div><?php endforeach; ?>
    </section>
    <?php endif; ?>
  </div>

  <aside class="stack">
    <section class="card"><div class="card-h"><?= V::icon('cpu') ?><h2>Set up for you</h2></div>
      <div class="card-b small">
        <p><?= $o['source'] === 'sis' ? 'SAQF created this course record from the timetable on ' . V::h(V::date($o['initialized_at'])) . ' — nobody typed it.' : 'This course record was created on ' . V::h(V::date($o['initialized_at'])) . ' when the course was assigned.' ?></p>
        <p class="muted" style="margin:0"><?= V::h('So far SAQF has filled in ' . (int) ($ledger['field_populated'] ?? 0) . ' details, carried over ' . (int) ($ledger['record_inherited'] ?? 0) . ' items from the approved specification and run ' . (int) ($ledger['check_run'] ?? 0) . ' checks.') ?></p>
      </div>
    </section>
    <?php $specVersion = $o['spec_version_id'] ? Db::one('SELECT version_no, decided_at, status FROM spec_versions WHERE id = ?', [$o['spec_version_id']]) : null; ?>
    <section class="card"><div class="card-h"><h2>About this course</h2><span class="right"><?= V::source('institution') ?></span></div>
      <div class="card-b small">
        <table class="facts">
          <tr><td class="muted">Course</td><td><strong><?= V::h($o['course_code']) ?></strong> <?= V::h($o['course_title']) ?></td></tr>
          <tr><td class="muted">Credit hours</td><td><span title="<?= V::h('Worked out: credit hours × ' . Policy::get('contact.weeks_per_term') . ' teaching weeks') ?>"><?= V::h(rtrim(rtrim((string) $o['credits'], '0'), '.') . ' credit hours (about ' . rtrim(rtrim(number_format(Catalog::contactHours((float) $o['credits']), 1), '0'), '.') . ' hours of class time)') ?></span></td></tr>
          <tr><td class="muted">Department</td><td><?= V::h($o['department_name']) ?><br><span class="muted"><?= V::h($o['college_name']) ?></span></td></tr>
          <tr><td class="muted">Part of</td><td><?php foreach ($programs as $p): ?><div><a href="program.php?id=<?= (int) $p['program_id'] ?>"><?= V::h($p['short_name'] ?? $p['code']) ?></a> · <?= V::h($p['course_type'] === 'required' ? 'required course' : 'elective') ?><?= $p['level_no'] ? ' · ' . V::h('semester ' . (int) $p['level_no']) : '' ?></div><?php endforeach; ?></td></tr>
          <tr><td class="muted">Take first</td><td><?php if (!$requisites): ?><span class="muted">No prerequisite</span><?php endif; ?><?php foreach (array_values(array_column($requisites, null, 'code')) as $r): ?><div><strong><?= V::h($r['code']) ?></strong> <?= V::h($r['title'] ?? '') ?><?= $r['kind'] === 'corequisite' ? ' ' . V::pill('at the same time', 'grey') : '' ?></div><?php endforeach; ?></td></tr>
          <tr><td class="muted">This term</td><td><?= V::h($o['term_name']) ?><div><?= V::h($o['instructor_name'] ?? 'No instructor yet') ?><?= count($sections) > 1 ? ' ' . V::pill('Coordinator', 'blue') : '' ?></div><div class="muted"><?= V::h((int) $o['enrolled'] . ' students') ?></div></td></tr>
          <?php if (count($sections) > 1): ?><tr><td class="muted">Sections</td><td><?php foreach ($sections as $s): ?><div><strong><?= V::h($s['section_code']) ?></strong> · <?= V::h($s['instructor_name'] ?? 'no instructor') ?> · <?= V::h((int) $s['enrolled'] . ' students') ?></div><?php endforeach; ?></td></tr><?php endif; ?>
          <tr><td class="muted">Specification</td><td><?= $specVersion ? V::h('Version ' . (int) $specVersion['version_no']) . ' · ' . V::h('approved ' . V::date($specVersion['decided_at'])) . '<div class="muted">' . V::h('Carried over to this term automatically') . '</div>' : '<span class="muted">Not written yet</span>' ?></td></tr>
        </table>
        <?php if ($o['description']): ?><p style="margin-top:10px"><?= V::h($o['description']) ?></p><p class="tiny muted" style="margin:0">From the university's course descriptions</p><?php endif; ?>
      </div>
    </section>
  </aside>
</div>

<?php elseif ($tab === 'structure'):
    $diff = $isDraft ? Specs::diff((int) $working['id']) : [];
    $readOnly = !$canEdit || ($working && in_array($working['status'], ['pending_hod', 'pending_qa'], true));
?>
<?php if ($working && in_array($working['status'], ['pending_hod', 'pending_qa'], true)): ?>
  <div class="alert alert-info"><?= V::h('Version ' . (int) $working['version_no'] . ' is ' . ($working['status'] === 'pending_hod' ? 'with the Head of Department' : 'with Quality') . ' for a decision, so it cannot be edited right now.') ?><?= $approved ? ' ' . V::h('Version ' . (int) $approved['version_no'] . ' stays in use meanwhile.') : '' ?></div>
<?php elseif ($isDraft): ?>
  <div class="card" style="margin-bottom:16px"><div class="card-h"><h2><?= V::h('Your changes — version ' . (int) $working['version_no']) ?></h2><?= V::pill('Not submitted yet', 'grey') ?>
      <?php if ($working['decision_note']): ?><span class="pill pill-red">Returned: <?= V::h(mb_strimwidth((string) $working['decision_note'], 0, 90, '…')) ?></span><?php endif; ?>
      <div class="right row"><?php if ($canEdit): ?><button class="btn btn-sm btn-ghost" data-act="discard_draft" data-offering="<?= $oid ?>" data-confirm="Discard this draft? The approved specification stays in effect.">Discard draft</button>
        <button class="btn btn-sm btn-primary" data-act="submit_spec" data-offering="<?= $oid ?>" <?= $blocking && array_filter($blocking, static fn($f) => $f['severity'] === 'blocker') ? 'disabled title="Fix the items marked Must fix first — SAQF never sends an incomplete record"' : '' ?>>Submit changes</button><?php endif; ?></div></div>
    <div class="card-b">
      <?php if ($working['decision_note']): ?><div class="alert alert-warning"><strong>Reviewer comment:</strong> <?= V::h($working['decision_note']) ?></div><?php endif; ?>
      <?php if (!$diff): ?><p class="muted small" style="margin:0"><?= V::h('Nothing differs from the approved version ' . (int) ($approved['version_no'] ?? 0) . ' yet. Edit anything below — reviewers will see only what you change.') ?></p>
      <?php else: ?>
        <p class="small muted"><?= V::h(count($diff) === 1 ? 'Reviewers will see only this 1 change, not the whole specification.' : 'Reviewers will see only these ' . count($diff) . ' changes, not the whole specification.') ?></p>
        <div class="diff"><?php foreach ($diff as $d): ?><div class="diff-row"><div class="diff-label"><?= V::h($d['label']) ?><br><?= $d['academic'] ? V::pill('needs approval', 'blue') : V::pill('approved automatically', 'grey') ?></div><div class="diff-before"><?= $d['before'] === null ? '<span class="muted">—</span>' : V::h($d['before']) ?></div><div class="diff-after"><?= $d['after'] === null ? '<span class="muted">Removed</span>' : V::h($d['after']) ?></div></div><?php endforeach; ?></div>
      <?php endif; ?>
    </div></div>
<?php elseif ($canEdit && $approved): ?>
  <div class="alert alert-info"><?= V::h('This is the approved course specification (version ' . (int) $approved['version_no'] . '), carried over to this term automatically. Editing anything starts a new draft; the approved version stays in use until your changes are approved.') ?></div>
<?php endif; ?>

<div class="split">
  <div class="stack">
    <section class="card"><div class="card-h"><h2>Learning outcomes</h2><span class="muted small">What students should be able to do by the end of the course</span></div>
      <div class="card-b">
        <?php foreach ($spec['clos'] ?? [] as $c): $issues = $byClo[(int) $c['id']] ?? []; ?>
          <div class="clo <?= $issues ? 'has-issue' : '' ?>" id="clo-<?= (int) $c['id'] ?>">
            <div class="clo-head"><div class="clo-code"><?= V::h($c['code']) ?></div>
              <div class="clo-text"><div><?= V::h($c['statement']) ?></div>
                <div class="tiny muted"><?= V::h($c['domain']) ?> · <?= V::h('goal ' . V::pct($c['target_pct'] ?? $defaultTarget) . ' of students') ?> <?= $c['target_pct'] === null ? V::source('policy') : V::source('faculty') ?><?= $c['skills_tags'] ? ' · ' . V::h('Jahiziah skills: ' . $c['skills_tags']) : '' ?></div></div>
              <?php if (!$readOnly): ?><details><summary class="btn btn-sm">Edit</summary>
                <form data-api="save_clo" style="margin-top:8px;min-width:320px"><input type="hidden" name="offering" value="<?= $oid ?>"><input type="hidden" name="clo" value="<?= (int) $c['id'] ?>">
                  <div class="field"><textarea name="statement" required><?= V::h($c['statement']) ?></textarea></div>
                  <div class="field"><label class="tiny muted">Arabic wording (optional — shown in the Arabic interface and Word documents)</label><textarea name="statement_ar" dir="rtl" lang="ar" rows="2"><?= V::h(\Saqf\Core\Translations::get($c['statement']) ?? '') ?></textarea></div>
                  <div class="field"><select name="domain"><?php foreach (Specs::DOMAINS as $d): ?><option <?= $d === $c['domain'] ? 'selected' : '' ?>><?= V::h($d) ?></option><?php endforeach; ?></select></div>
                  <div class="field"><input type="number" name="target_pct" min="0" max="100" step="0.5" placeholder="Goal % (blank = university default <?= V::h((string) $defaultTarget) ?>%)" value="<?= V::h($c['target_pct']) ?>"></div>
                  <div class="field small"><span class="tiny muted" style="display:block;margin-bottom:4px">Jahiziah skill tags (classification only — no approval needed)</span><?php foreach (Specs::SKILL_TAGS as $t): ?><label class="inline" style="font-weight:400;margin-right:8px"><input type="checkbox" name="skills[]" value="<?= V::h($t) ?>" <?= in_array($t, explode(',', (string) $c['skills_tags']), true) ? 'checked' : '' ?>> <?= V::h($t) ?></label><?php endforeach; ?></div>
                  <div class="row"><button class="btn btn-sm btn-primary" type="submit">Save</button><button class="btn btn-sm btn-red" type="button" data-act="delete_clo" data-offering="<?= $oid ?>" data-clo="<?= (int) $c['id'] ?>" data-confirm="Remove <?= V::h($c['code']) ?> from the draft?">Remove</button></div>
                </form></details><?php endif; ?>
            </div>
            <?php foreach ($programs as $p): $pid = (int) $p['program_id']; $plos = $spec['plos'][$pid] ?? []; if (!$plos) { continue; } $mapped = array_map('intval', array_column($c['maps'][$pid] ?? [], 'id')); ?>
              <div class="maprow"><span class="prog" title="<?= V::h('Program outcomes of ' . ($p['short_name'] ?? $p['code']) . ' this outcome helps develop. Click to link or unlink; hover over a code to read it.') ?>"><?= V::h($p['short_name'] ?? $p['code']) ?></span>
                <?php foreach ($plos as $pl): $on = in_array((int) $pl['id'], $mapped, true); $sug = $recByClo[(int) $c['id']][(int) $pl['id']] ?? null; ?>
                  <button class="chip <?= $on ? 'on' : '' ?> <?= $sug && !$on ? 'sugg' : '' ?>" title="<?= V::h($pl['code'] . ' · ' . $pl['domain'] . ' — ' . $pl['statement']) ?>" <?= $readOnly ? 'disabled' : 'data-act="map" data-offering="' . $oid . '" data-clo="' . (int) $c['id'] . '" data-plo="' . (int) $pl['id'] . '" data-on="' . ($on ? '0' : '1') . '"' ?>><?= V::h($pl['code']) ?></button>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
            <?php foreach ($recByClo[(int) $c['id']] ?? [] as $sug): ?>
              <div class="small" style="margin-top:8px;padding:8px 10px;border:1px dashed #E3A0C0;border-radius:8px;background:#FFF8FB">
                <?= V::source('suggested') ?> <strong><?= V::h($sug['title']) ?></strong><div class="muted"><?= V::h($sug['rationale']) ?></div><?php $sp = json_decode((string) $sug['payload'], true) ?: []; $spText = !empty($sp['plo_id']) ? Db::val('SELECT statement FROM plos WHERE id = ?', [(int) $sp['plo_id']]) : null; ?><?php if ($spText): ?><div class="tiny">“<?= V::h($spText) ?>”</div><?php endif; ?>
                <div class="tiny muted" title="<?= V::h($sug['method'] . ' · similarity ' . $sug['confidence']) ?>">Suggested from the wording of the outcomes (hover for details)</div>
                <?php if (!$readOnly): ?><div class="row" style="margin-top:6px"><button class="btn btn-sm" data-act="suggestion" data-offering="<?= $oid ?>" data-id="<?= (int) $sug['id'] ?>" data-accept="1">Accept</button><button class="btn btn-sm btn-ghost" data-act="suggestion" data-offering="<?= $oid ?>" data-id="<?= (int) $sug['id'] ?>" data-accept="0">Dismiss</button></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php foreach ($issues as $f): if ($f['severity'] === 'info') { continue; } ?><div class="small" style="color:var(--red);margin-top:6px">● <?= V::h($f['title']) ?> — <?= V::h($f['remedy']) ?></div><?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$readOnly): ?>
          <details class="clo" <?= empty($spec['clos']) ? 'open' : '' ?>><summary class="strong" style="cursor:pointer">+ Add a learning outcome</summary>
            <form data-api="save_clo" style="margin-top:10px"><input type="hidden" name="offering" value="<?= $oid ?>">
              <div class="field"><label>Outcome statement</label><textarea name="statement" required placeholder="Start with an observable verb, e.g. “Analyze…”, “Design…”, “Evaluate…”"></textarea></div>
              <div class="field"><label>Arabic wording (optional)</label><textarea name="statement_ar" dir="rtl" lang="ar" rows="2"></textarea></div>
              <div class="grid g2"><div class="field"><label>Learning domain</label><select name="domain"><?php foreach (Specs::DOMAINS as $d): ?><option><?= V::h($d) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label>Goal % (optional)</label><input type="number" name="target_pct" min="0" max="100" step="0.5" placeholder="University default <?= V::h((string) $defaultTarget) ?>%"></div></div>
              <button class="btn btn-primary btn-sm" type="submit">Add outcome</button></form></details>
        <?php endif; ?>
      </div>
    </section>

    <section class="card"><div class="card-h"><h2>Assessment plan</h2><span class="right" data-weight-total="<?= V::h((string) Policy::get('assessment.weight_total_pct')) ?>"></span></div>
      <div class="card-b tight"><div class="table-wrap"><table>
        <thead><tr><th>Assessment</th><th>Type</th><th style="width:90px">Week</th><th style="width:110px">Weight %</th><th>Outcomes it measures</th><?php if (!$readOnly): ?><th></th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($spec['assessments'] ?? [] as $a): ?>
          <tr><td class="strong"><?= V::h($a['name']) ?></td><td><?= V::h(Specs::ASSESSMENT_KINDS[$a['kind']] ?? $a['kind']) ?></td><td><?= V::h($a['week'] ?? '—') ?></td>
            <td><?php if ($readOnly): ?><?= V::pct($a['weight_pct']) ?><?php else: ?><input type="number" min="0" max="100" step="0.5" data-weight aria-label="<?= V::h('Weight (%): ' . $a['name']) ?>" data-id="<?= (int) $a['id'] ?>" value="<?= V::h(rtrim(rtrim((string) $a['weight_pct'], '0'), '.')) ?>" style="width:80px"><?php endif; ?></td>
            <td><?php foreach ($spec['clos'] as $c): $on = in_array((int) $c['id'], $a['clos'], true); ?><button class="chip <?= $on ? 'on' : '' ?>" <?= $readOnly ? 'disabled' : 'data-act="link" data-offering="' . $oid . '" data-assessment="' . (int) $a['id'] . '" data-clo="' . (int) $c['id'] . '" data-on="' . ($on ? '0' : '1') . '"' ?>><?= V::h($c['code']) ?></button> <?php endforeach; ?></td>
            <?php if (!$readOnly): ?><td class="num"><button class="btn btn-sm btn-ghost" data-act="delete_assessment" data-offering="<?= $oid ?>" data-assessment="<?= (int) $a['id'] ?>" data-confirm="Remove “<?= V::h($a['name']) ?>” from the draft?">Remove</button></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php if (!$readOnly && !empty($spec['assessments'])): ?><div class="row" style="padding:10px 14px;border-top:1px solid var(--line-2)"><span class="small muted">Change weights above, then</span><button class="btn btn-sm" type="button" data-act="save_weights" data-offering="<?= $oid ?>" data-weights>Save weights</button></div><?php endif; ?>
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

    <section class="card"><div class="card-h"><h2>Course description and teaching</h2><span class="muted small">Objective, teaching approach, topics and resources — changes here are approved automatically</span></div>
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
    <section class="card"><div class="card-h"><h2>Checks</h2></div>
      <?php $specChecks = array_filter($openFindings, static fn($f) => $f['scope_type'] === 'spec' && $f['severity'] !== 'info'); ?>
      <?php if (!$specChecks): ?><div class="allclear"><strong>✓</strong><div><strong>Everything checks out</strong><div class="muted small"><?= V::h('Every outcome can be measured, is linked to the program and is assessed; the weights add up to ' . Policy::get('assessment.weight_total_pct') . '%.') ?></div></div></div><?php endif; ?>
      <?php foreach ($specChecks as $f): ?><div class="check sev-<?= V::h($f['severity']) ?>"><div class="check-ico">!</div><div><div class="check-title"><?= V::h($f['title']) ?></div><div class="check-fix"><?= V::h($f['remedy']) ?></div></div></div><?php endforeach; ?>
      <div class="card-b tiny muted">Checked again after every change. Changes can be submitted once nothing marked “Must fix” is left.</div>
    </section>
    <section class="card"><div class="card-h"><h2>Versions</h2></div><div class="card-b small">
      <?php foreach (Db::all('SELECT sv.*, u.full_name FROM spec_versions sv LEFT JOIN users u ON u.id = sv.decided_by WHERE sv.course_id = ? ORDER BY version_no DESC', [$courseId]) as $v): ?>
        <div style="margin-bottom:8px"><div class="row"><strong><?= V::h('Version ' . (int) $v['version_no']) ?></strong> <?= V::specStatus($v['status']) ?></div><div class="tiny muted"><?= $v['decision_route'] ? V::h(V::route($v['decision_route'])) . ' · ' : '' ?><?= V::h(V::date($v['decided_at'] ?? $v['created_at'])) ?></div></div>
      <?php endforeach; ?>
      <?php if ($approved): ?><div style="margin-top:10px"><div class="tiny muted">Download the approved specification</div><div class="row" style="margin-top:4px"><a class="btn btn-sm" href="export.php?doc=spec&id=<?= (int) $approved['id'] ?>">Word (English)</a><a class="btn btn-sm" href="export.php?doc=spec&id=<?= (int) $approved['id'] ?>&lang=ar">Word (Arabic)</a><a class="btn btn-sm btn-ghost" href="report.php?type=spec&id=<?= (int) $approved['id'] ?>">Print view</a></div></div><?php endif; ?>
    </div></section>
  </aside>
</div>

<?php elseif ($tab === 'results'):
    $batches = Db::all('SELECT rb.*, u.full_name FROM result_batches rb LEFT JOIN users u ON u.id = rb.imported_by WHERE rb.offering_id = ? ORDER BY rb.imported_at', [$oid]);
    $plo = Db::all('SELECT pa.*, p.code AS plo_code, p.statement, pr.code AS program_code, pr.short_name AS program_name FROM plo_achievement pa JOIN plos p ON p.id = pa.plo_id JOIN programs pr ON pr.id = pa.program_id WHERE pa.offering_id = ? ORDER BY pr.code, p.code', [$oid]);
    $pendingLms = array_filter(Integrations::lms()->pending(), static fn($b) => $b['course'] === $o['course_code'] && $b['term'] === $o['term_code']);
?>
<div class="split">
  <div class="stack">
    <?php $pv = $_SESSION['gb_preview'][$oid] ?? null; if ($pv && time() - (int) $pv['at'] > 900) { unset($_SESSION['gb_preview']); $pv = null; } ?>
    <?php if ($pv): $nMatched = count($pv['results']); ?>
    <section class="card" id="gb-preview"><div class="card-h"><h2>Check before importing</h2><?= V::pill('Nothing imported yet', 'amber') ?><span class="right muted small"><?= V::h($pv['file']) ?></span></div>
      <div class="card-b small">
        <p><strong><?= V::h(V::count((int) $pv['students'], 'student', 'students')) ?></strong> in the file<?= $o['enrolled'] ? ' (the course has ' . (int) $o['enrolled'] . ' enrolled)' : '' ?>. Student numbers were already replaced by codes: neither this page nor the database shows them.</p>
        <?php if ($nMatched): ?>
        <div class="table-wrap"><table><thead><tr><th>Assessment</th><th class="num">Students</th><th class="num">New</th><th class="num">Replaces</th><th class="num">Average</th><th class="num">Lowest–highest</th></tr></thead><tbody>
          <?php foreach ($pv['stats'] as $name => $st): ?><tr><td><strong><?= V::h($name) ?></strong></td><td class="num"><?= (int) $st['n'] ?></td><td class="num"><?= (int) $st['new'] ?></td><td class="num"><?= (int) $st['updates'] ?></td><td class="num"><?= V::h(rtrim(rtrim(number_format($st['mean'], 1), '0'), '.')) ?>%</td><td class="num"><?= V::h(rtrim(rtrim(number_format($st['min'], 1), '0'), '.') . '–' . rtrim(rtrim(number_format($st['max'], 1), '0'), '.')) ?>%</td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        <?php $warn = []; foreach ($pv['stats'] as $name => $st) { if ($st['max'] <= 1 && $st['n'] > 1) { $warn[] = '"' . $name . '": every mark is between 0 and 1. Marks must be percentages from 0 to 100; check the file is not in fractions.'; } if ($st['n'] >= 5 && $st['zeros'] / $st['n'] > 0.3) { $warn[] = '"' . $name . '": ' . $st['zeros'] . ' of ' . $st['n'] . ' marks are 0. Zeros count as real marks; leave a cell empty for a student who did not take it.'; } if ($st['updates']) { $warn[] = '"' . $name . '": ' . $st['updates'] . ' student(s) already have a mark here; the new mark replaces it.'; } }
          if ($o['enrolled'] && $pv['students'] > 1.3 * (int) $o['enrolled'] + 3) { $warn[] = 'The file has more students than are enrolled. Is it the right course?'; }
          if ($pv['ignored']) { $warn[] = 'Not in the course specification, so left out: ' . implode(', ', $pv['ignored']) . '.'; } ?>
        <?php foreach ($warn as $w): ?><div class="check sev-warning"><div class="check-ico">•</div><div class="check-detail"><?= V::h($w) ?></div></div><?php endforeach; ?>
        <?php if (!$nMatched): ?><div class="alert alert-error">No column in the file matches an assessment in the specification, so there is nothing to import. The specification's assessments are: <strong><?= V::h(implode(', ', array_column($evidenceSpec['assessments'] ?? [], 'name'))) ?></strong>. Rename the columns and upload again.</div><?php endif; ?>
        <?php if (is_string($pv['tag'])): ?><p class="muted">These marks will be filed under section <?= V::h($pv['tag']) ?>.</p><?php elseif ($pv['sections']): ?><p class="muted">Sections found in the file: <?= V::h(implode(', ', $pv['sections'])) ?>.</p><?php endif; ?>
        <div class="row" style="margin-top:10px">
          <?php if ($nMatched): ?><form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="gb_confirm"><button class="btn btn-primary btn-sm" type="submit">Import these marks</button></form><?php endif; ?>
          <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="gb_cancel"><button class="btn btn-sm btn-ghost" type="submit">Cancel</button></form></div>
      </div></section>
    <?php endif; ?>
    <section class="card"><div class="card-h"><h2>Learning outcomes — how students did</h2><span class="muted small right"><?= V::source('calculated') ?> updated automatically whenever grades arrive</span></div>
      <div class="card-b tight"><div class="table-wrap"><table>
        <thead><tr><th>Learning outcome</th><th>Assessed by</th><th style="width:280px">Students who met it</th><th class="num">Students</th><th class="num">Graded so far</th></tr></thead><tbody>
        <?php foreach ($evidenceSpec['clos'] ?? [] as $c): $a = $ach[$c['lineage_key']] ?? null; $names = array_map(static fn($aid) => current(array_filter($evidenceSpec['assessments'], static fn($x) => (int) $x['id'] === $aid))['name'] ?? '', $c['assessments']); ?>
          <tr><td><strong><?= V::h($c['code']) ?></strong><div class="tiny muted"><?= V::h(mb_strimwidth($c['statement'], 0, 70, '…')) ?></div></td><td class="small"><?= V::h(implode(', ', $names)) ?></td>
            <td><?= V::bar($a ? (float) $a['value_pct'] : null, $a ? (float) $a['target_pct'] : (float) ($c['target_pct'] ?? $defaultTarget), $a ? (bool) $a['provisional'] : false) ?></td>
            <td class="num"><?= $a ? (int) $a['students_assessed'] : '—' ?></td><td class="num" title="Share of this outcome's assessment weight that has grades"><?= $a ? ((float) $a['coverage_pct'] >= 99.95 ? 'All' : V::pct($a['coverage_pct'])) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="card-b tiny muted"><span><?= V::h(Policy::get('achievement.method') === 'average'
            ? 'How this is calculated: the average of students\' marks on each outcome\'s assessments.'
            : 'How this is calculated: a student meets an outcome when their marks on its assessments average ' . Policy::get('achievement.student_threshold_pct') . '% or more (each assessment counted by its weight).') ?></span> <span><?= V::h('The goal is ' . V::pct($defaultTarget) . ' of students unless the course sets its own.') ?></span> <span><?= V::h('“So far” means some of the outcome\'s assessments are not graded yet.') ?></span></div>
      </div>
    </section>
    <section class="card"><div class="card-h"><h2>What this course adds to program outcomes</h2><span class="muted small right">Average of this course's outcomes linked to each program outcome</span></div>
      <div class="card-b tight"><table><thead><tr><th>Program</th><th>Program outcome</th><th style="width:280px">Average</th><th class="num">Course outcomes</th></tr></thead><tbody>
      <?php foreach ($plo as $p): ?><tr><td><?= V::h($p['program_name'] ?? $p['program_code']) ?></td><td><strong><?= V::h($p['plo_code']) ?></strong> <span class="tiny muted"><?= V::h(mb_strimwidth($p['statement'], 0, 80, '…')) ?></span></td><td><?= V::bar((float) $p['value_pct'], Policy::get('plo.target_pct'), (bool) $p['provisional']) ?></td><td class="num"><?= (int) $p['contributing_clos'] ?></td></tr><?php endforeach; ?>
      <?php if (!$plo): ?><tr><td colspan="4"><?= V::empty('No grades yet', 'Program outcome figures appear automatically once grades arrive.') ?></td></tr><?php endif; ?>
      </tbody></table></div></section>
    <?php $bySection = Sections::achievement($oid); if ($bySection): $gapPts = Policy::get('section.gap_points'); ?>
    <section class="card"><div class="card-h"><h2>Achievement by section</h2><span class="muted small right"><?= V::h('Same calculation as above, per section · a difference of more than ' . Rules::fmt($gapPts) . ' points is flagged') ?></span></div>
      <div class="card-b tight"><div class="table-wrap"><table>
        <thead><tr><th>Outcome</th><?php foreach ($bySection as $code => $s): ?><th><?= V::h('Section ' . $code) ?><div class="tiny muted"><?= V::h($s['instructor'] ?? '—') ?> · <?= V::h((int) $s['students'] . ' students') ?></div></th><?php endforeach; ?><th class="num">Difference</th></tr></thead><tbody>
        <?php foreach ($evidenceSpec['clos'] ?? [] as $c): $vals = []; foreach ($bySection as $code => $s) { if (isset($s['clos'][(int) $c['id']])) { $vals[$code] = $s['clos'][(int) $c['id']]['value']; } } if (!$vals) { continue; } $gap = count($vals) > 1 ? max($vals) - min($vals) : 0; $target = (float) ($c['target_pct'] ?? $defaultTarget); ?>
          <tr><td><strong><?= V::h($c['code']) ?></strong></td>
            <?php foreach ($bySection as $code => $s): $v = $vals[$code] ?? null; ?><td><?= $v === null ? '<span class="muted">—</span>' : '<span class="heat ' . ($v >= $target - 1e-9 ? 'heat-ok' : 'heat-low') . '">' . V::pct($v) . '</span>' ?></td><?php endforeach; ?>
            <td class="num"><?= $gap > $gapPts + 1e-9 ? V::pill(Rules::whole($gap) . ' points', 'amber') : '<span class="muted">' . V::h(Rules::whole($gap) . ' points') . '</span>' ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <div class="card-b tiny muted">Sections share one specification and one set of outcomes; the course figure above combines every section. A large gap is a prompt to compare teaching and marking between sections, not a judgement of an instructor.</div></div></section>
    <?php endif; ?>
  </div>
  <aside class="stack">
    <section class="card"><div class="card-h"><h2>Grades received</h2><span class="right"><?= V::source('lms') ?></span></div>
      <div class="card-b small">
        <?php foreach ($batches as $b): ?><div style="margin-bottom:10px"><strong><?= V::h($b['assessments']) ?></strong><div class="muted"><?= V::h(V::date($b['imported_at'], 'j M Y, H:i')) ?> · <?= V::h((int) $b['rows_count'] . ' grades') ?></div><div class="muted"><?= $b['full_name'] ? V::h('Uploaded by ' . $b['full_name']) : V::h($b['source'] === 'upload' ? 'Uploaded from a file' : 'Received automatically from the gradebook') ?> <?= V::verified($b['checksum']) ?></div></div><?php endforeach; ?>
        <?php if (!$batches): ?><p class="muted">No grades have been published for this course yet.</p><?php endif; ?>
        <?php foreach ($pendingLms as $pb): ?><p class="muted"><?= V::h('Coming from the gradebook: ' . $pb['label'] . ' (expected ' . V::date($pb['published_at']) . '). SAQF imports it as soon as it is published.') ?></p><?php endforeach; ?>
        <?php if ($canContribute): ?>
          <button class="btn btn-sm" data-act="lms_sync" data-offering="<?= $oid ?>">Check for new grades now</button>
          <details style="margin-top:12px"><summary class="small" style="cursor:pointer">Upload grades from a file instead</summary>
            <form method="post" enctype="multipart/form-data" style="margin-top:8px"><?= Csrf::field() ?><input type="file" name="results" accept=".csv,text/csv" required>
              <div class="field-help"><?= V::h('A CSV file with one row per student: the student number (SAQF replaces it with a code before storing), optionally the section, then one column per assessment with marks from 0 to 100.') ?><?= !$canEdit && count($mySections) === 1 ? ' ' . V::h('Rows without a section count as section ' . $mySections[0] . '.') : '' ?>
                <div class="mono tiny" translate="no" style="margin-top:4px">student, section, <?= V::h(implode(', ', array_column($evidenceSpec['assessments'] ?? [], 'name'))) ?></div></div>
              <button class="btn btn-sm" type="submit">Upload</button></form></details>
        <?php endif; ?>
      </div></section>
  </aside>
</div>

<?php elseif ($tab === 'evidence'):
    $assessmentNames = array_column($evidenceSpec['assessments'] ?? [], 'name', 'id');
?>
<div class="split">
  <div class="stack">
    <?php if ($evidenceRequested): ?><div class="alert alert-info"><strong>Please add the papers for:</strong> <?= V::h(implode(', ', array_map(static fn($id) => $assessmentNames[$id] ?? '#' . $id, $evidenceRequested))) ?><div class="small">The grades for these are in. Upload the exam or assignment and a few marked samples; this reminder goes away by itself.</div></div><?php endif; ?>
    <section class="card"><div class="card-h"><h2>Course file</h2><span class="muted small right"><?= V::h(V::count(count($evidenceFiles), 'file', 'files')) ?> · kept securely, every download is recorded</span></div>
      <div class="card-b tight"><div class="table-wrap"><table>
        <thead><tr><th>Document</th><th>For</th><th>Section</th><th>Added</th><th class="num">File</th><?php if ($canContribute): ?><th></th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($evidenceFiles as $ev): ?>
          <tr><td><strong><?= V::h($ev['title']) ?></strong><div class="tiny muted"><?= V::h(Evidence::KINDS[$ev['kind']] ?? $ev['kind']) ?></div></td>
            <td class="small"><?= V::h($ev['assessment_name'] ?? '—') ?></td><td class="small"><?= V::h($ev['section_code'] ?? 'All sections') ?></td>
            <td class="small"><?= V::h(V::date($ev['uploaded_at'], 'j M Y')) ?><div class="tiny muted"><?= V::h($ev['uploader'] ?? 'SAQF') ?></div></td>
            <td class="num small"><a href="evidence.php?id=<?= (int) $ev['id'] ?>"><?= V::h(strtoupper(pathinfo($ev['original_name'], PATHINFO_EXTENSION))) ?> · <?= V::h(Evidence::size((int) $ev['size_bytes'])) ?></a><div class="tiny"><?= $ev['scan_status'] === 'clean' ? V::pill('Virus-checked', 'green') : '' ?> <?= V::verified($ev['sha256'], 'Unchanged') ?></div></td>
            <?php if ($canContribute): ?><td class="num"><?php if ($canEdit || (int) $ev['uploaded_by'] === $user['id']): ?><details><summary class="btn btn-sm btn-ghost">Remove</summary><form method="post" class="row" style="margin-top:6px"><?= Csrf::field() ?><input type="hidden" name="op" value="remove_evidence"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>"><input type="text" name="reason" placeholder="Reason (audited)" required minlength="5"><button class="btn btn-sm btn-red">Remove</button></form></details><?php endif; ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        <?php if (!$evidenceFiles): ?><tr><td colspan="6"><?= V::empty('No evidence in the course file yet', 'Exam papers, rubrics and samples of marked work go here; reviewers find everything in one place.') ?></td></tr><?php endif; ?>
        </tbody></table></div></div></section>
  </div>
  <aside class="stack">
    <?php if ($canContribute && $evidenceSpec): ?>
    <section class="card"><div class="card-h"><h2>Add evidence</h2></div><div class="card-b small">
      <form method="post" enctype="multipart/form-data"><?= Csrf::field() ?>
        <div class="field"><label for="ev-files">Files (choose several at once)</label><input type="file" id="ev-files" name="evidence_files[]" multiple required accept=".pdf,.docx,.xlsx,.pptx,.png,.jpg,.jpeg,.txt"></div>
        <div id="ev-rows" class="ev-rows" aria-live="polite" data-offering="<?= $oid ?>" data-max="12"></div>
        <?php /* Row template and wording for evidence.js: rendered here so the Arabic page filter translates them like any other text. */ ?>
        <template id="ev-tpl"><div class="ev-row"><div class="ev-name"></div>
          <select class="ev-kind" aria-label="What is it?"><?php foreach (Evidence::KINDS as $k => $l): ?><option value="<?= V::h($k) ?>"><?= V::h($l) ?></option><?php endforeach; ?></select>
          <select class="ev-asm" aria-label="Assessment"><option value="">General (not one assessment)</option><?php foreach ($evidenceSpec['assessments'] as $a): ?><option value="<?= (int) $a['id'] ?>"><?= V::h($a['name']) ?></option><?php endforeach; ?></select>
          <input type="text" class="ev-title" maxlength="200" aria-label="Title"><div class="tiny ev-note" hidden>Not sure about this one: please check.</div></div></template>
        <div id="ev-text" hidden><span data-k="hint">SAQF suggested these from the file names. Please check each one.</span><span data-k="toomany">Add up to 12 files at a time.</span></div>
        <?php if (count($sections) > 1): ?><div class="field"><label>Section (for all these files)</label><select name="section"><option value="">All sections</option><?php foreach ($sections as $s): ?><option <?= in_array($s['section_code'], $mySections, true) && !$canEdit ? 'selected' : '' ?>><?= V::h($s['section_code']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
        <p class="tiny muted" style="margin-top:8px">Up to 12 files at a time (together under <?= V::h((string) ini_get('post_max_size')) ?>): PDF, Word, Excel, PowerPoint, images or text, each up to <?= (int) Policy::get('evidence.max_mb') ?> MB. SAQF suggests what each file is from its name; you decide. Files are checked for safety before they are stored. Please remove student names from samples.</p>
      </form><script src="assets/evidence.js?v=<?= SAQF_VERSION ?>" defer></script></div></section>
    <?php endif; ?>
    <section class="card"><div class="card-h"><h2>Why this matters</h2></div><div class="card-b small muted">Accreditation reviewers ask to see the exams and assignments behind the results. SAQF reminds you when grades arrive, keeps the files with the course, and lists them in the course report for you.</div></section>
  </aside>
</div>

<?php elseif ($tab === 'improve'):
    $owners = Db::all('SELECT id, full_name FROM users WHERE role IN ("faculty","hod") AND status = "active" AND department_id = ? ORDER BY full_name', [$o['owner_department_id']]);
?>
<div class="stack">
  <?php if (!$actionsCourse): ?><div class="card"><?= V::empty('No improvement actions for this course', 'When an outcome misses its target, SAQF drafts the improvement record with the evidence and asks for your academic response.') ?></div><?php endif; ?>
  <?php foreach ($actionsCourse as $ia): $mine = $user['role'] === 'faculty' && (Authz::isCoordinator($user, $o) || (int) $ia['owner_id'] === $user['id']); ?>
  <section class="card" id="ia-<?= (int) $ia['id'] ?>">
    <div class="card-h"><h2><?= V::h($ia['title']) ?></h2><?= V::pill(Improvements::STATUS[$ia['status']] ?? $ia['status'], ['draft' => 'red', 'open' => 'blue', 'in_progress' => 'blue', 'completed' => 'green', 'cancelled' => 'grey'][$ia['status']] ?? 'grey') ?>
      <span class="right muted small"><?= V::h($ia['origin_term']) ?> · <?= $ia['created_by'] ? 'added by a person' : 'prepared by SAQF' ?></span></div>
    <div class="card-b">
      <?php $fx = Improvements::facts($ia); ?>
      <div class="small stack-tight">
        <div><strong><?= V::h($fx['code']) ?></strong> <?= V::h($fx['statement']) ?></div>
        <div><?= V::h('Students who met it') ?>: <strong><?= V::pct($fx['value']) ?></strong> · <?= V::h('goal ' . V::pct($fx['target'])) ?> · <?= V::h($ia['origin_term']) ?><?= $fx['students'] ? ' · ' . V::h(V::count($fx['students'], 'student', 'students')) : '' ?> <?= V::source('calculated', 'Put together automatically from this course\'s results, its history and earlier actions') ?></div>
        <?php if ($fx['assessments']): ?><div class="muted"><?= V::h('Measured by') ?>: <?php foreach ($fx['assessments'] as $fa): ?><span class="tag"><?= V::h($fa['name']) ?> · <?= V::pct($fa['weight_pct']) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if ($fx['history']): ?><div class="muted"><?= V::h('Earlier terms') ?>: <?php foreach ($fx['history'] as $fh): ?><span class="tag"><?= V::h($fh['name']) ?> · <?= V::pct($fh['value_pct']) ?> <?= (int) $fh['met'] ? '✓' : '✗' ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php foreach ($fx['prior'] as $fp): ?><div class="muted"><?= V::h('Tried before') ?> (<?= V::h($fp['name']) ?>): “<?= V::h(mb_strimwidth((string) ($fp['action_text'] ?: $fp['title']), 0, 120, '…')) ?>” · <?= V::h(Improvements::EFFECT[$fp['effect']] ?? $fp['effect']) ?></div><?php endforeach; ?>
      </div>
      <?php if ($ia['status'] === 'draft'): ?>
        <?php if ($mine || Authz::canDecideCourse($user, $courseId)): ?>
        <form data-api="improvement_commit" class="fieldset"><input type="hidden" name="id" value="<?= (int) $ia['id'] ?>">
          <div class="field"><label>What will you change, and when? <?= V::source('faculty') ?></label><textarea name="action_text" required minlength="15" placeholder="e.g. Add a formative checkpoint on test planning in week 9 with rubric feedback…"></textarea>
            <div class="field-help">Only you can decide the teaching change. Next term SAQF compares the result with today's <?= V::pct($ia['baseline_pct']) ?> and tells you whether it helped.</div></div>
          <div class="grid g2"><div class="field"><label>Owner</label><select name="owner"><?php foreach ($owners as $ow): ?><option value="<?= (int) $ow['id'] ?>" <?= (int) $ow['id'] === (int) $ia['owner_id'] ? 'selected' : '' ?>><?= V::h($ow['full_name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Deadline</label><input type="date" name="due_on" required value="<?= V::h($ia['due_on']) ?>"></div></div>
          <button class="btn btn-primary btn-sm" type="submit">Save the plan</button></form>
        <?php else: ?><p class="muted small">Waiting for the instructor to say what will change.</p><?php endif; ?>
      <?php else: ?>
        <div class="grid g3 small">
          <div><div class="muted">What will change</div><div><?= V::h($ia['action_text']) ?></div></div>
          <div><div class="muted">Who · by when</div><div><?= V::h($ia['owner_name']) ?> · <?= V::h(V::date($ia['due_on'])) ?></div><?php if ($ia['completion_note']): ?><div class="muted" style="margin-top:6px">Done: <?= V::h($ia['completion_note']) ?></div><?php endif; ?></div>
          <div><div class="muted">Did it help?</div>
            <div><?= V::pill(Improvements::EFFECT[$ia['effect']] ?? $ia['effect'], ['improved' => 'green', 'declined' => 'red', 'similar' => 'amber'][$ia['effect']] ?? 'grey') ?></div>
            <?php if ($ia['followup_pct'] !== null): ?><div class="small" style="margin-top:4px"><?= V::h($ia['origin_term']) ?>: <?= V::pct($ia['baseline_pct']) ?> → <?= V::h($ia['followup_term']) ?>: <strong><?= V::pct($ia['followup_pct']) ?></strong> · goal <?= V::pct($ia['target_pct']) ?></div><div class="tiny muted">Results changed after the action; other things may also have played a part.</div><?php endif; ?></div>
        </div>
        <?php if (in_array($ia['status'], ['open', 'in_progress'], true) && ((int) $ia['owner_id'] === $user['id'] || Authz::canDecideCourse($user, $courseId))): ?>
          <form data-api="improvement_status" class="row" style="margin-top:12px"><input type="hidden" name="id" value="<?= (int) $ia['id'] ?>">
            <select name="status" style="width:auto" aria-label="Change the status of this improvement action"><?php if ($ia['status'] === 'open'): ?><option value="in_progress">Mark in progress</option><?php endif; ?><option value="completed">Mark completed</option><option value="cancelled">Cancel</option></select>
            <input type="text" name="note" placeholder="What was done (or why it was cancelled)" style="flex:1;min-width:240px"><button class="btn btn-sm" type="submit">Update</button></form>
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
    <section class="card"><div class="card-h"><h2>Course report</h2><div class="right row"><a class="btn btn-sm" href="report.php?type=course&id=<?= $oid ?>">View report</a><a class="btn btn-sm btn-primary" href="export.php?doc=report&id=<?= $oid ?>">Word (English)</a><a class="btn btn-sm" href="export.php?doc=report&id=<?= $oid ?>&lang=ar">Word (Arabic)</a></div></div>
      <div class="card-b small">
        <p>SAQF writes the course report for you from what is already in the system: the course details, learning outcomes, grades, how students did, what fell short, what was done about it and who approved what. You only add your own comments below.</p>
        <?php if ($snap): ?><div class="alert alert-info">Sealed on <?= V::h(V::date($snap['created_at'])) ?> when the term closed, so it can no longer change. <?= V::verified($snap['sha256'], 'Unchanged since') ?></div><?php endif; ?>
      </div></section>
    <?php $facts = \Saqf\Quality\Facts::forOffering($o); ?>
    <section class="card"><div class="card-h"><h2>Facts for your reading</h2><span class="muted small right">Worked out by SAQF from your records</span></div>
      <div class="card-b small"><ul class="facts-list"><?php foreach ($facts as $f): ?><li class="fact fact-<?= V::h($f['tone']) ?>"><span class="fact-mark" aria-hidden="true"><?= ['ok' => '✓', 'warn' => '!', 'info' => 'i'][$f['tone']] ?? 'i' ?></span><span><?= V::h($f['text']) ?></span></li><?php endforeach; ?></ul>
        <p class="tiny muted" style="margin:10px 0 0">These are the numbers, not their meaning. Why the results look like this, and what to do about it, is for you to say below: SAQF never writes it for you.</p></div></section>
    <?php foreach (['interpretation' => 'What the results mean', 'difficulties' => 'Difficulties this term (optional)', 'recommendations' => 'Suggestions for next time (optional)'] as $key => $label): ?>
      <section class="card"><div class="card-h"><h2><?= V::h($label) ?></h2><?= V::source('faculty') ?></div><div class="card-b">
        <?php if ($canEdit): ?><form data-api="narrative"><input type="hidden" name="offering" value="<?= $oid ?>"><input type="hidden" name="section" value="<?= $key ?>"><textarea name="content" rows="4" aria-label="<?= V::h($label) ?>"><?= V::h($narr[$key]['content'] ?? '') ?></textarea><button class="btn btn-sm" type="submit" style="margin-top:8px">Save</button></form>
        <?php else: ?><p><?= isset($narr[$key]) ? nl2br(V::h($narr[$key]['content'])) : '<span class="muted">Not provided.</span>' ?></p><?php endif; ?>
      </div></section>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($tab === 'closeout'):
    $set = Closeout::checklistSetBy();
    $stateTone = ['complete' => 'green', 'missing' => 'red', 'review' => 'amber', 'scheduled' => 'grey'];
    $review = Closeout::evidenceReview($oid);
    $canReview = Closeout::canReview($user, $o);
?>
<div class="split">
  <div class="stack">
    <section class="card"><div class="card-h"><h2>Course file closeout</h2><?= Config::demoMode() ? V::pill('DEMO data', 'amber') : '' ?><span class="right muted small"><?= V::h($o['term_name']) ?></span></div>
      <div class="card-b small">
        <div class="row" style="flex-wrap:wrap;gap:8px"><?= V::pill($closeout['counts']['complete'] . ' complete', 'green') ?> <?= V::pill($closeout['counts']['missing'] . ' missing', $closeout['counts']['missing'] ? 'red' : 'grey') ?> <?= V::pill($closeout['counts']['review'] . ' need a person\'s review', $closeout['counts']['review'] ? 'amber' : 'grey') ?></div>
        <p style="margin:10px 0 0"><?= $closeout['ready'] ? V::h('Everything the checklist asks for is in place and reviewed by a person.') : V::h('Each line says who owns it and the next step. Everything here is read from SAQF\'s records; nothing is marked done until the records show it.') ?></p>
        <p class="tiny muted" style="margin:6px 0 0"><?= $set ? V::h('Checklist set by Quality (last change ' . V::date($set['at']) . ($set['by'] ? ' by ' . $set['by'] : '') . ').') : V::h('Checklist: SAQF\'s default settings, not yet confirmed by Quality. They are not a university requirement until Quality sets them.') ?><?= $user['role'] === 'qa' ? ' <a href="policies.php">' . V::h('Change the checklist') . '</a>' : '' ?></p>
      </div>
      <div class="card-b tight"><div class="table-wrap"><table class="closeout-list">
        <thead><tr><th>Item</th><th style="width:150px">Status</th><th>Owner</th><th>What the records show · next step</th></tr></thead><tbody>
        <?php foreach ($closeout['items'] as $it): ?>
          <tr><td><strong><?= V::h($it['label']) ?></strong></td>
            <td><?= V::pill(Closeout::STATES[$it['state']], $stateTone[$it['state']]) ?></td>
            <td class="small"><?= V::h($it['owner']) ?></td>
            <td class="small"><?= V::h($it['detail']) ?><?php if ($it['next'] !== '' && $it['state'] !== 'complete'): ?><div class="check-fix"><strong>Next:</strong> <?= $it['tab'] && $it['tab'] !== 'closeout' ? '<a href="' . $base . '&amp;tab=' . V::h($it['tab']) . '">' . V::h($it['next']) . '</a>' : V::h($it['next']) ?></div><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
    </section>
  </div>
  <aside class="stack">
    <?php if ($canReview && $evidenceFiles): ?>
    <section class="card"><div class="card-h"><h2>Review the evidence</h2></div><div class="card-b small">
      <p class="muted">Open the files, then accept the set or return it with a note. Your decision is recorded in the audit log; any later change to the files makes the review due again.</p>
      <ul style="padding-inline-start:18px;margin:0 0 10px"><?php foreach ($evidenceFiles as $ev): ?><li><a href="evidence.php?id=<?= (int) $ev['id'] ?>"><?= V::h($ev['title']) ?></a> <span class="tiny muted">· <?= V::h($ev['assessment_name'] ?? 'General') ?> · <?= V::h(Evidence::KINDS[$ev['kind']] ?? $ev['kind']) ?></span></li><?php endforeach; ?></ul>
      <?php if ($review): ?><p class="tiny muted"><?= V::h('Last review: ' . ($review['decision'] === 'accepted' ? 'accepted' : 'returned') . ' by ' . $review['reviewer'] . ' on ' . V::date($review['reviewed_at']) . ($review['current'] ? '' : ' (files changed since)')) ?></p><?php endif; ?>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="closeout_review">
        <div class="field"><label for="closeout-note">Note (required when returning)</label><textarea id="closeout-note" name="note" rows="2" maxlength="500" placeholder="e.g. Add marked samples for the final exam"></textarea></div>
        <div class="row"><button class="btn btn-sm btn-primary" name="decision" value="accepted" type="submit">Accept the evidence</button><button class="btn btn-sm" name="decision" value="returned" type="submit">Return with note</button></div></form>
    </div></section>
    <?php endif; ?>
    <section class="card"><div class="card-h"><h2>Course file package</h2></div><div class="card-b small">
      <p>One ZIP for reviewers: the course report and approved specification (Word), the evidence index, where the grades came from, this checklist, and SHA-256 checksums of every file.</p>
      <p class="tiny muted"><span>No student identities are included. Evidence files are listed, not included: each is downloaded on its own and recorded. The download itself is recorded too.</span><?= $o['term_status'] !== 'closed' ? ' <span>' . V::h('The term is still open, so the report in the package is not sealed yet.') . '</span>' : '' ?></p>
      <a class="btn btn-sm btn-primary" href="export.php?doc=package&amp;id=<?= $oid ?>">Download the course file package</a></div></section>
    <section class="card"><div class="card-h"><h2>What stays with people</h2></div><div class="card-b small muted">SAQF fills in the facts and checks. The instructor writes the reading of the results, the suggestions and each improvement plan; the Head of Department or Quality accepts the evidence and decides on approvals and exceptions. SAQF never does those for them.</div></section>
  </aside>
</div>

<?php else: // history
    $lineages = $evidenceSpec ? $evidenceSpec['clos'] : ($spec['clos'] ?? []);
    $terms = Db::all('SELECT DISTINCT t.id, t.name, t.sequence FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.course_id = ? ORDER BY t.sequence', [$courseId]);
    $events = Db::all("SELECT * FROM audit_log WHERE (object_type = 'offering' AND object_id = ?) OR (object_type = 'spec_version' AND object_id IN (SELECT id FROM spec_versions WHERE course_id = ?)) OR (object_type = 'improvement' AND object_id IN (SELECT id FROM improvement_actions WHERE course_id = ?)) ORDER BY id DESC LIMIT 60", [(string) $oid, $courseId, $courseId]);
?>
<div class="stack">
  <section class="card"><div class="card-h"><h2>How students did, term by term</h2><span class="muted small right">Kept even when the instructor changes</span></div>
    <div class="card-b tight"><div class="table-wrap"><table class="matrix">
      <thead><tr><th>Learning outcome</th><?php foreach ($terms as $t): ?><th><?= V::h($t['name']) ?></th><?php endforeach; ?><th>Trend</th></tr></thead><tbody>
      <?php foreach ($lineages as $c): $hist = []; foreach (Achievement::history($c['lineage_key']) as $h) { $hist[(int) $h['sequence']] = $h; } ?>
        <tr><td><strong><?= V::h($c['code']) ?></strong> <span class="tiny muted"><?= V::h(mb_strimwidth($c['statement'], 0, 60, '…')) ?></span></td>
          <?php foreach ($terms as $t): $h = $hist[(int) $t['sequence']] ?? null; ?><td><?php if ($h): ?><span class="heat <?= (int) $h['provisional'] ? 'heat-prov' : ((int) $h['met'] ? 'heat-ok' : 'heat-low') ?>"><?= V::pct($h['value_pct']) ?></span><?php else: ?><span class="heat heat-none">—</span><?php endif; ?></td><?php endforeach; ?>
          <td><?= V::spark(array_map(static fn($h) => (int) $h['provisional'] ? null : (float) $h['value_pct'], array_values($hist)), (float) ($c['target_pct'] ?? $defaultTarget)) ?></td></tr>
      <?php endforeach; ?></tbody></table></div></div></section>
  <section class="card"><div class="card-h"><h2>Everything that happened</h2><a class="right small" href="report.php?type=course&id=<?= $oid ?>">Course report</a></div>
    <div class="card-b"><ul class="timeline">
      <?php foreach ($events as $e): ?><li class="<?= $e['actor_type'] === 'user' ? 'usr' : ($e['actor_type'] === 'integration' ? 'int' : 'sys') ?>"><div class="when"><?= V::h(V::date($e['occurred_at'], 'j M Y H:i')) ?> · <span><?= V::h($e['actor_name']) ?></span><?= $e['actor_type'] === 'integration' ? ' <span class="pill pill-grey">' . V::h('University system') . '</span>' : ($e['actor_type'] === 'system' ? ' <span class="pill pill-grey">' . V::h('Automatic') . '</span>' : '') ?></div><div><?= V::h($e['summary']) ?></div><?php if ($e['reason']): ?><div class="small muted">Reason: <?= V::h($e['reason']) ?></div><?php endif; ?></li><?php endforeach; ?>
    </ul></div></section>
</div>
<?php endif;
V::footer();

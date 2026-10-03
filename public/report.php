<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Quality\Catalog;
use Saqf\Quality\Improvements;
use Saqf\Quality\Reports;
use Saqf\Quality\Specs;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership']);
$type = in_array($_GET['type'] ?? '', ['course', 'program', 'spec'], true) ? $_GET['type'] : 'course';
$id = (int) ($_GET['id'] ?? 0);
$snapshotView = isset($_GET['snapshot']);

// Authorise before any output, so a denial is a real HTTP 403.
if ($type === 'course') {
    $o = Authz::offering($user, $id);
} elseif ($type === 'program') {
    $p = Authz::program($user, $id);
} else {
    $v = Specs::version($id);
    if (!$v || !Authz::canViewCourse($user, (int) $v['course_id'])) {
        Authz::deny('specification report');
    }
}

V::header('Report', $user, ['subtitle' => 'Written by SAQF from the course record; nobody has to type it up']);
$wordLink = $type === 'course' ? 'export.php?doc=report&id=' . $id : ($type === 'spec' ? 'export.php?doc=spec&id=' . $id : null);
echo '<div class="row noprint" style="margin-bottom:12px"><button class="btn btn-sm" type="button" data-print>Print / save as PDF</button>' . ($wordLink ? '<a class="btn btn-sm btn-primary" href="' . V::h($wordLink) . '">Word (English)</a><a class="btn btn-sm" href="' . V::h($wordLink) . '&lang=ar">Word (Arabic)</a>' : '') . '<span class="muted small">Laid out like the NCAAA templates.</span></div>';

if ($type === 'course') {
    $snap = Db::one('SELECT * FROM snapshots WHERE kind = "course_report" AND scope_id = ?', [$id]);
    $r = ($snapshotView && $snap) ? json_decode($snap['payload'], true) : Reports::courseReport($id);
    $of = $r['offering'];
    ?>
    <article class="card"><div class="card-b">
      <div class="row between"><div><h2 style="font-size:20px">Course Report — <?= V::h($of['code'] . ' ' . $of['title']) ?></h2><div class="muted"><?= V::h($of['term_name']) ?> · Al Yamamah University · <?= V::h($of['college']) ?> · <?= V::h($of['department']) ?></div></div>
        <div class="small muted" style="text-align:right"><?= V::h('Written') ?> <?= V::h(V::date($r['generated_at'], 'j M Y H:i')) ?><?php if ($snap): ?><br><?= $snapshotView ? V::h('Sealed copy from when the term closed') : '<a href="?type=course&id=' . $id . '&snapshot=1">' . V::h('See the sealed copy') . '</a>' ?> <?= V::verified($snap['sha256'], 'Unchanged') ?><?php endif; ?></div></div>
      <h3 style="margin-top:18px">A. Course identification</h3>
      <table class="small"><tbody>
        <tr><td class="muted" style="width:220px">Credit hours</td><td><?= V::h(rtrim(rtrim((string) $of['credits'], '0'), '.')) ?></td></tr>
        <tr><td class="muted">Programs</td><td><?php foreach ($r['programs'] as $rp): ?><span class="tag"><?= V::h($rp['code']) ?> · <?= V::h($rp['course_type'] === 'required' ? 'required course' : 'elective') ?></span><?php endforeach; ?></td></tr>
        <tr><td class="muted">Instructor</td><td><?= V::h($of['instructor'] ?? '—') ?></td></tr>
        <tr><td class="muted">Students</td><td><?= V::h(V::count((int) $of['enrolled'], 'student', 'students')) ?> · <?= V::h(V::count((int) $of['sections'], 'section', 'sections')) ?></td></tr>
        <tr><td class="muted">Course specification</td><td><?= $of['version_no'] ? V::h('Approved') . ' ' . V::h(V::date($of['spec_approved_at'])) : '—' ?></td></tr>
      </tbody></table>
      <h3 style="margin-top:18px">B. Course learning outcomes and achievement</h3>
      <table class="small"><thead><tr><th>Code</th><th>Learning outcome</th><th>Program outcomes</th><th>Assessed by</th><th class="num">Goal</th><th class="num">Students who met it</th><th>Result</th></tr></thead><tbody>
      <?php foreach ($r['clos'] as $c): ?><tr><td><strong><?= V::h($c['code']) ?></strong></td><td><?= V::h($c['statement']) ?><div class="tiny muted"><?= V::h($c['domain']) ?></div></td><td><?= V::h(implode(', ', $c['plos'])) ?></td><td><?= V::h(implode(', ', $c['assessments'])) ?></td>
        <td class="num"><?= V::pct($c['target']) ?><div class="tiny muted"><?= V::h($c['target_source']) ?></div></td><td class="num"><?= V::pct($c['value']) ?><?= $c['students'] ? '<div class="tiny muted">' . V::h(V::count((int) $c['students'], 'student', 'students')) . '</div>' : '' ?></td>
        <td><?= $c['value'] === null ? '—' : ($c['provisional'] ? V::pill('so far', 'grey') : ($c['met'] ? V::pill('met', 'green') : V::pill('below goal', 'red'))) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <p class="tiny muted"><span><?= $r['method']['achievement'] === 'average' ? V::h('How it is worked out: the average mark on the assessments that measure each outcome.') : V::h('How it is worked out: the share of students who scored at least ' . V::pct($r['method']['student_threshold']) . ' on the assessments that measure each outcome.') ?></span> <span><?= V::h('The usual goal is ' . V::pct($r['method']['default_target']) . ', set by the university.') ?></span></p>
      <h3 style="margin-top:18px">C. Assessment results</h3>
      <table class="small"><thead><tr><th>Assessment</th><th class="num">Weight</th><th class="num">Week</th><th class="num">Grades</th><th class="num">Average</th></tr></thead><tbody>
      <?php foreach ($r['assessments'] as $a): ?><tr><td><?= V::h($a['name']) ?></td><td class="num"><?= V::pct($a['weight']) ?></td><td class="num"><?= V::h($a['week'] ?? '—') ?></td><td class="num"><?= (int) $a['n'] ?></td><td class="num"><?= V::pct($a['mean']) ?></td></tr><?php endforeach; ?></tbody></table>
      <h3 style="margin-top:18px">D. Contribution to program learning outcomes</h3>
      <table class="small"><tbody><?php foreach ($r['plo'] as $p): ?><tr><td><?= V::h($p['program_code'] . ' ' . $p['plo_code']) ?></td><td class="num"><?= V::pct($p['value_pct']) ?><?= (int) $p['provisional'] ? ' <span class="muted">' . V::h('so far') . '</span>' : '' ?></td><td class="muted"><?= V::h((int) $p['contributing_clos'] === 1 ? 'from 1 course outcome' : 'from ' . (int) $p['contributing_clos'] . ' course outcomes') ?></td></tr><?php endforeach; ?><?= $r['plo'] ? '' : '<tr><td class="muted">No results.</td></tr>' ?></tbody></table>
      <h3 style="margin-top:18px">E. Interpretation (instructor)</h3>
      <p class="small"><?= isset($r['narrative']['interpretation']) ? nl2br(V::h($r['narrative']['interpretation'])) : '<span class="muted">Not provided.</span>' ?></p>
      <?php if (!empty($r['narrative']['difficulties'])): ?><p class="small"><strong>Difficulties:</strong> <?= nl2br(V::h($r['narrative']['difficulties'])) ?></p><?php endif; ?>
      <?php if (!empty($r['narrative']['recommendations'])): ?><p class="small"><strong>Recommendations:</strong> <?= nl2br(V::h($r['narrative']['recommendations'])) ?></p><?php endif; ?>
      <h3 style="margin-top:18px">F. Improvement actions arising from this offering</h3>
      <table class="small"><tbody><?php foreach ($r['actions'] as $a): ?><tr><td><?= V::h($a['title']) ?><div class="muted"><?= V::h($a['action_text'] ?: 'Waiting for the instructor') ?></div></td><td><?= V::h($a['owner_name'] ?? '') ?></td><td><?= V::h(V::date($a['due_on'])) ?></td><td><?= V::h(Improvements::STATUS[$a['status']] ?? $a['status']) ?></td></tr><?php endforeach; ?><?= $r['actions'] ? '' : '<tr><td class="muted">' . V::h('None: every goal was met, or there are no results yet.') . '</td></tr>' ?></tbody></table>
      <h3 style="margin-top:18px">G. Follow-up of earlier actions</h3>
      <table class="small"><tbody><?php foreach ($r['followups'] as $a): ?><tr><td><?= V::h($a['action_text']) ?><div class="tiny muted"><?= V::h($a['origin_term']) ?></div></td><td class="num"><?= V::pct($a['baseline_pct']) ?> → <?= V::pct($a['followup_pct']) ?></td><td><?= V::h(Improvements::EFFECT[$a['effect']] ?? $a['effect']) ?></td></tr><?php endforeach; ?><?= $r['followups'] ? '' : '<tr><td class="muted">' . V::h('No earlier improvements to compare this term.') . '</td></tr>' ?></tbody></table>
      <h3 style="margin-top:18px">H. Evidence</h3>
      <table class="small"><tbody><?php foreach ($r['evidence'] as $e): ?><tr><td><?= V::h($e['source'] === 'lms' ? 'Gradebook' : ($e['source'] === 'csv' ? 'Uploaded file' : ucfirst((string) $e['source']))) ?></td><td><?= V::h($e['assessments']) ?></td><td class="num"><?= V::h((int) $e['rows_count'] . ' grades') ?></td><td><?= V::verified($e['checksum']) ?></td></tr><?php endforeach; ?><?= $r['evidence'] ? '' : '<tr><td class="muted">' . V::h('No grades received.') . '</td></tr>' ?></tbody></table>
    </div></article>
    <?php
} elseif ($type === 'program') {
    $r = Reports::program($id);
    $target = Policy::get('plo.target_pct');
    ?>
    <article class="card"><div class="card-b">
      <h2 style="font-size:20px">Program Report — <?= V::h($p['code'] . ' ' . $p['name']) ?></h2><div class="muted"><?= V::h($p['college_name']) ?> · <?= V::h($p['department_name']) ?> · <?= V::h('written') ?> <?= V::h(V::date($r['generated_at'], 'j M Y H:i')) ?></div>
      <h3 style="margin-top:18px">Key figures</h3><table class="small"><tbody><?php foreach ($r['kpis'] as $k): ?><tr><td><?= V::h($k['label']) ?></td><td class="num strong"><?= V::h($k['value']) ?></td><td class="muted"><?= V::h($k['detail']) ?></td></tr><?php endforeach; ?></tbody></table>
      <h3 style="margin-top:18px">Program learning outcomes and achievement</h3>
      <table class="small"><thead><tr><th>Code</th><th>Program outcome</th><th>Students who met it, by term</th></tr></thead><tbody>
      <?php foreach ($r['plos'] as $pl): ?><tr><td><strong><?= V::h($pl['code']) ?></strong></td><td><?= V::h($pl['statement']) ?></td><td><?php foreach ($r['series'][(int) $pl['id']] ?? [] as $s): ?><span class="heat <?= (int) $s['prov'] ? 'heat-prov' : ((float) $s['v'] >= $target ? 'heat-ok' : 'heat-low') ?>"><?= V::h($s['name']) ?> <?= V::pct($s['v']) ?></span> <?php endforeach; ?></td></tr><?php endforeach; ?></tbody></table>
      <h3 style="margin-top:18px">Which course covers what</h3>
      <table class="small matrix"><thead><tr><th>Course</th><?php foreach ($r['plos'] as $pl): ?><th><?= V::h($pl['code']) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($r['matrix'] as $code => $byPlo): ?><tr><td><?= V::h($code) ?></td><?php foreach ($r['plos'] as $pl): ?><td><?= isset($byPlo[(int) $pl['id']]) ? '●' : '' ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table>
      <h3 style="margin-top:18px">Open problems</h3>
      <ul class="small"><?php foreach (Db::all('SELECT title, detail FROM findings WHERE program_id = ? AND status = "open"', [$id]) as $f): ?><li><strong><?= V::h($f['title']) ?></strong> — <?= V::h($f['detail']) ?></li><?php endforeach; ?></ul>
    </div></article>
    <?php
} else {
    $s = Specs::load($id);
    $c = $s['course'];
    ?>
    <article class="card"><div class="card-b">
      <h2 style="font-size:20px">Course Specification — <?= V::h($c['code'] . ' ' . $c['title']) ?></h2><div class="muted"><?= V::h('Version') ?> <?= (int) $v['version_no'] ?> · <?= V::specStatus($v['status']) ?><?= $v['decided_at'] ? ' · ' . V::h(V::date($v['decided_at'])) : '' ?></div>
      <h3 style="margin-top:18px">A. General information</h3><table class="small"><tbody>
        <tr><td class="muted" style="width:220px">Institution · college · department</td><td>Al Yamamah University · <?= V::h($c['college_name']) ?> · <?= V::h($c['department_name']) ?></td></tr>
        <tr><td class="muted">Credit hours · contact hours</td><td><?= V::h(rtrim(rtrim((string) $c['credits'], '0'), '.')) ?> · <?= V::h((string) Catalog::contactHours((float) $c['credits'])) ?></td></tr>
        <tr><td class="muted">Programs · type · level</td><td><?php foreach ($s['programs'] as $sp): ?><span class="tag"><?= V::h($sp['code']) ?> · <?= V::h($sp['course_type'] === 'required' ? 'required course' : 'elective') ?><?= $sp['level_no'] ? ' · ' . V::h('level ' . $sp['level_no']) : '' ?></span><?php endforeach; ?></td></tr>
        <tr><td class="muted">Prerequisites</td><td><?= V::h(implode(', ', array_unique(array_column(Catalog::requisitesFor((int) $c['id']), 'code'))) ?: 'None') ?></td></tr>
        <tr><td class="muted">Description</td><td><?= V::h($c['description'] ?? '—') ?></td></tr>
        <tr><td class="muted">Main objective</td><td><?= V::h($v['objectives']) ?></td></tr></tbody></table>
      <h3 style="margin-top:18px">B. Learning outcomes, mapping and assessment</h3><table class="small"><thead><tr><th>Code</th><th>Outcome</th><th>Domain</th><th>PLOs</th><th>Assessment</th></tr></thead><tbody>
      <?php $pc = []; foreach ($s['programs'] as $p) { $pc[(int) $p['program_id']] = $p['code']; } foreach ($s['clos'] as $cl): $m = []; foreach ($cl['maps'] as $pid => $list) { foreach ($list as $x) { $m[] = ($pc[$pid] ?? '') . ' ' . $x['code']; } } ?>
        <tr><td><?= V::h($cl['code']) ?></td><td><?= V::h($cl['statement']) ?></td><td><?= V::h($cl['domain']) ?></td><td><?= V::h(implode(', ', $m)) ?></td><td><?= V::h(implode(', ', array_map(static fn($aid) => current(array_filter($s['assessments'], static fn($a) => (int) $a['id'] === $aid))['name'] ?? '', $cl['assessments']))) ?></td></tr>
      <?php endforeach; ?></tbody></table>
      <p class="small"><strong>Teaching strategies:</strong> <?= V::h($v['teaching_strategies']) ?></p>
      <h3 style="margin-top:18px">C. Course content</h3><table class="small"><tbody><?php foreach ($s['topics'] as $t): ?><tr><td><?= V::h($t['topic']) ?></td><td class="num"><?= V::h((string) $t['contact_hours']) ?></td></tr><?php endforeach; ?></tbody></table>
      <h3 style="margin-top:18px">D. Student assessment activities</h3><table class="small"><tbody><?php foreach ($s['assessments'] as $a): ?><tr><td><?= V::h($a['name']) ?></td><td><?= V::h(Specs::ASSESSMENT_KINDS[$a['kind']] ?? $a['kind']) ?></td><td>Week <?= V::h($a['week'] ?? '—') ?></td><td class="num"><?= V::pct($a['weight_pct']) ?></td></tr><?php endforeach; ?></tbody></table>
      <h3 style="margin-top:18px">E. Learning resources and facilities</h3><ul class="small"><?php foreach ($s['resources'] as $res): ?><li><?= V::h(Specs::RESOURCE_CATEGORIES[$res['category']] ?? $res['category']) ?>: <?= V::h($res['reference_text']) ?></li><?php endforeach; ?></ul>
      <h3 style="margin-top:18px">F. Specification approval</h3><p class="small"><?= $v['decided_at'] ? V::h(V::route($v['decision_route'])) . ' · ' . V::h(V::date($v['decided_at'])) . ($v['decision_note'] ? '<br>“' . V::h($v['decision_note']) . '”' : '') : V::h('Not approved yet.') ?></p>
    </div></article>
    <?php
}
V::footer();

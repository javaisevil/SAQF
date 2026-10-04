<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Session;
use Saqf\Core\Throttle;
use Saqf\Quality\Closeout;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

// Course file closeout across the courses a Head of Department, Quality, a dean or leadership may see (same scope as
// every other page): who is late, what is missing and who has to act next. Read-only; every figure comes from
// Saqf\Quality\Closeout, which reads the records.
$user = saqf_page(['hod', 'qa', 'dean', 'leadership']);
$terms = Db::all('SELECT id, code, name, status, grades_due_on FROM terms ORDER BY sequence DESC LIMIT 8');
$termId = (int) ($_GET['term'] ?? 0);
if (!in_array($termId, array_map('intval', array_column($terms, 'id')), true)) {
    $active = Db::one('SELECT id FROM terms WHERE status = "active" LIMIT 1');
    $termId = (int) ($active['id'] ?? ($terms[0]['id'] ?? 0));
}
$term = Db::one('SELECT * FROM terms WHERE id = ?', [$termId]);
$filter = in_array($_GET['show'] ?? '', ['all', 'missing', 'review', 'ready'], true) ? $_GET['show'] : 'all';
[$scope, $scopeParams] = Authz::offeringScope($user, 'o', 'c');
$offerings = Db::all(
    'SELECT o.*, c.code AS course_code, c.title AS course_title, c.owner_department_id, d.code AS department_code, t.name AS term_name, t.status AS term_status, t.grades_due_on,
            u.full_name AS instructor_name
     FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN departments d ON d.id = c.owner_department_id JOIN terms t ON t.id = o.term_id LEFT JOIN users u ON u.id = o.instructor_id
     WHERE o.term_id = ? AND ' . $scope . ' ORDER BY d.code, c.code',
    array_merge([$termId], $scopeParams)
);
$rows = [];
$totals = ['courses' => 0, 'ready' => 0, 'missing' => 0, 'review' => 0];
$today = Clock::now()->setTime(0, 0);
foreach ($offerings as $o) {
    $c = Closeout::forOffering($o);
    $p = Closeout::progress($c);
    $days = $term ? (int) $today->diff(new DateTimeImmutable((string) $term['grades_due_on']))->format('%r%a') : 0;
    $next = null;
    foreach ($c['items'] as $i) {
        if ($i['state'] === 'missing' || $i['state'] === 'review') {
            $next = $i;
            break;
        }
    }
    $state = $c['counts']['missing'] ? 'missing' : ($c['counts']['review'] ? 'review' : 'ready');
    $totals['courses']++;
    $totals[$state]++;
    if ($filter !== 'all' && $filter !== $state) {
        continue;
    }
    $rows[] = ['o' => $o, 'c' => $c, 'p' => $p, 'state' => $state, 'next' => $next, 'days' => $days];
}
if (($_GET['export'] ?? '') === 'csv') {
    try {
        Throttle::check('export:' . $user['id'], 60, 3600, 'Too many downloads in the last hour. Please try again later.');
    } catch (RuntimeException $e) {
        Session::flash('error', $e->getMessage());
        saqf_redirect('closeout_board.php');
    }
    Audit::record('export.downloaded', 'term', $termId, 'Course file closeout board exported to CSV for ' . ($term['name'] ?? '?') . ' (' . count($rows) . ' courses)');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="saqf-closeout-' . preg_replace('/[^A-Za-z0-9\-]+/', '-', (string) ($term['code'] ?? 'term')) . '.csv"');
    header('Cache-Control: private, no-store');
    echo Closeout::csv(['course', 'title', 'department', 'instructor', 'complete', 'missing', 'needs_review', 'state', 'next_item', 'owner', 'next_step', 'days_to_grades_due'], array_map(static fn($r) => [
        $r['o']['course_code'], $r['o']['course_title'], $r['o']['department_code'], $r['o']['instructor_name'] ?? '', $r['c']['counts']['complete'] + $r['c']['counts']['scheduled'], $r['c']['counts']['missing'], $r['c']['counts']['review'],
        ['ready' => 'ready', 'missing' => 'missing items', 'review' => 'waiting for a person'][$r['state']], $r['next']['label'] ?? '', $r['next']['owner'] ?? '', $r['next']['next'] ?? '', $r['days'],
    ], $rows));
    exit;
}
V::header('Course file closeout', $user, ['subtitle' => 'Who has what left to do, across the courses you can see']);
?>
<div class="alert alert-info">Every line is read from SAQF's records: nothing is marked done until the records show it. The checklist itself is Quality's to set (<a href="policies.php">Quality policies</a>); until Quality confirms it, it is SAQF's default.</div>
<section class="card"><div class="card-h"><h2><?= V::h($term['name'] ?? 'No term') ?></h2>
  <form method="get" class="row right" style="gap:8px"><label class="sr-only" for="cb-term">Term</label><select id="cb-term" name="term" style="width:auto"><?php foreach ($terms as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $termId ? 'selected' : '' ?>><?= V::h($t['name']) ?></option><?php endforeach; ?></select>
    <label class="sr-only" for="cb-show">Show</label><select id="cb-show" name="show" style="width:auto"><?php foreach (['all' => 'All courses', 'missing' => 'Items missing', 'review' => 'Waiting for a person', 'ready' => 'Ready'] as $k => $l): ?><option value="<?= $k ?>" <?= $filter === $k ? 'selected' : '' ?>><?= V::h($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm" type="submit">Show</button><a class="btn btn-sm" href="?<?= V::h(http_build_query(['term' => $termId, 'show' => $filter, 'export' => 'csv'])) ?>">Export CSV</a></form></div>
  <div class="card-b small"><div class="row" style="flex-wrap:wrap;gap:8px"><?= V::pill($totals['courses'] . ' courses', 'grey') ?> <?= V::pill($totals['ready'] . ' ready', 'green') ?> <?= V::pill($totals['review'] . ' waiting for a person', $totals['review'] ? 'amber' : 'grey') ?> <?= V::pill($totals['missing'] . ' with items missing', $totals['missing'] ? 'red' : 'grey') ?>
    <?php if ($term): ?><span class="muted"><?= V::h(($days = (int) $today->diff(new DateTimeImmutable((string) $term['grades_due_on']))->format('%r%a')) >= 0 ? 'Grades due ' . V::date($term['grades_due_on']) . ' (' . $days . ' day' . ($days === 1 ? '' : 's') . ')' : 'Grades were due ' . V::date($term['grades_due_on']) . ' (' . (-$days) . ' days ago)') ?></span><?php endif; ?></div></div>
  <div class="card-b tight"><div class="table-wrap"><table class="closeout-list">
    <thead><tr><th>Course</th><th>Instructor</th><th style="width:180px">Course file</th><th>Next step</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $p = $r['p']; $o = $r['o']; ?>
      <tr><td><a href="workspace.php?id=<?= (int) $o['id'] ?>&amp;tab=closeout"><strong><?= V::h($o['course_code']) ?></strong> <?= V::h($o['course_title']) ?></a><div class="tiny muted"><?= V::h($o['department_code']) ?></div></td>
        <td class="small"><?= V::h($o['instructor_name'] ?? '—') ?></td>
        <td><div class="cfile-bar" role="img" aria-label="<?= V::h($p['done'] . ' of ' . $p['total'] . ' done, ' . $p['missing'] . ' missing, ' . $p['review'] . ' waiting for a person') ?>"><span class="cfile-done" style="width:<?= $p['total'] ? round($p['done'] / $p['total'] * 100) : 0 ?>%"></span><span class="cfile-review" style="width:<?= $p['total'] ? round($p['review'] / $p['total'] * 100) : 0 ?>%"></span><span class="cfile-miss" style="width:<?= $p['total'] ? round($p['missing'] / $p['total'] * 100) : 0 ?>%"></span></div>
          <div class="cfile-label"><?= V::h($p['done'] . ' of ' . $p['total'] . ' done') ?><?= $p['missing'] ? ' · ' . V::h($p['missing'] . ' missing') : '' ?><?= $p['review'] ? ' · ' . V::h($p['review'] . ' to review') : '' ?></div></td>
        <td class="small"><?php if ($r['next']): ?><strong><?= V::h($r['next']['label']) ?></strong><div class="muted"><?= V::h($r['next']['owner']) ?></div><?= $r['days'] < 0 && $r['state'] === 'missing' ? V::pill('overdue', 'red') : '' ?><?php else: ?><?= V::pill('Ready', 'green') ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4"><?= V::empty('Nothing to show', 'No course matches this filter in this term.') ?></td></tr><?php endif; ?>
    </tbody></table></div></div></section>
<?php V::footer();

<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Config;
use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Web\View as V;

// The guided 5-minute tour of the demo: each step signs in as the right person and opens the right page.
if (!Config::demoMode()) {
    saqf_redirect('login.php');
}
$current = static fn(string $code) => (int) Db::val('SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = ? AND t.status = "active" LIMIT 1', [$code]);
$cis491 = (int) Db::val('SELECT f.id FROM findings f JOIN courses c ON c.id = f.course_id WHERE c.code = "CIS 491" AND f.rule_code = "ASSESSMENT_SINGLE_WEIGHT" AND f.status = "open" LIMIT 1');
$steps = [
    ['0:00', 'Production-grade sign-in, in Arabic or English', 'The real login page: university single sign-on in production, two-step verification for administrators, and a full Arabic interface (switch with العربية at the top).', [['Show the Arabic interface', null, 'login.php?lang=ar']]],
    ['0:30', 'Faculty: SAQF found four problems by itself', 'Dr. Omar is revising SWE 412. SAQF already flagged a vague verb, an unmapped and unassessed CLO and weights totalling 105%. Change “Lab assignments” from 25 to 20, press Save weights — the issues clear and the course turns Ready.', [['Open SWE 412 as Dr. Omar', 'f.omar', 'workspace.php?id=' . $current('SWE 412') . '&tab=structure']]],
    ['1:15', 'Faculty: results, sections and the improvement loop', 'SWE 401 meets its target overall, but section 02 trails section 01 by 15.7 points — flagged automatically. The Improvement tab shows last year’s action: 53.3% → 63.3%, “improved following the intervention”. The Evidence tab asks for the midterm paper; uploading it clears the request. Download the NCAAA-layout Word report.', [['SWE 401 results by section', 'f.omar', 'workspace.php?id=' . $current('SWE 401') . '&tab=results'], ['Evidence and Word report', 'f.omar', 'workspace.php?id=' . $current('SWE 401') . '&tab=evidence']]],
    ['2:15', 'Head of Department: only what needs a decision', 'Department view shows exceptions only — the recurring CLO3 gap and the SO2 program issue — not every course. Approvals show just the changes, never the whole form.', [['Open as the Head of Department', 'hod.ced', 'department.php']]],
    ['2:45', 'Quality: Exception Center and bulk import', 'Approve or reject the CIS 491 capstone exception with a reason. Import specifications brings a university’s existing approved specifications in one CSV.', [['CIS 491 exception (QA)', 'qa.director', $cis491 ? 'exceptions.php?finding=' . $cis491 : 'exceptions.php'], ['Import specifications', 'qa.director', 'spec_import.php']]],
    ['3:30', 'IT: the automation, live', 'Integrations → Publish now: the LMS releases SWE 302 midterm grades and SAQF raises an early warning on its own. Publish assignment: the SIS assigns a course to a brand-new instructor — SAQF creates the account and the workspace. Security center lists every control and its live status.', [['Integrations simulator', 'it.admin', 'admin.php?tab=integrations'], ['Security center', 'it.admin', 'admin.php?tab=center']]],
    ['4:30', 'Leadership: the whole institution', 'Programs needing intervention, recurring issues and quality cycles across colleges, with drill-down to any course.', [['Open as the Vice President', 'vp.academic', 'institution.php']]],
];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Guided tour · SAQF</title><link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css?v=<?= SAQF_VERSION ?>"></head>
<body class="tour"><main class="tour-main">
<header class="tour-head"><img src="assets/yu-logo.png" alt="Al Yamamah University"><div><h1>SAQF in five minutes</h1><p class="muted">Each button signs in as the right person and opens the right page. Fictional people and results; YU's real study plans.</p></div><div class="right row"><?= \Saqf\Web\I18n::switchLink('btn btn-sm') ?><a class="btn btn-sm" href="login.php">Back to sign-in</a></div></header>
<ol class="tour-steps">
<?php foreach ($steps as $i => [$time, $title, $text, $links]): ?>
  <li class="card"><div class="card-b"><div class="row"><span class="pill pill-blue"><?= V::h($time) ?></span><h2 style="margin:0"><?= ($i + 1) . '. ' . V::h($title) ?></h2></div>
    <p class="small"><?= V::h($text) ?></p>
    <div class="row"><?php foreach ($links as [$label, $as, $next]): ?>
      <?php if ($as === null): ?><a class="btn btn-sm" href="<?= V::h($next) ?>"><?= V::h($label) ?></a>
      <?php else: ?><form method="post" action="demo.php"><?= Csrf::field() ?><input type="hidden" name="as" value="<?= V::h($as) ?>"><input type="hidden" name="next" value="<?= V::h($next) ?>"><button class="btn btn-sm btn-primary"><?= V::h($label) ?></button></form><?php endif; ?>
    <?php endforeach; ?></div></div></li>
<?php endforeach; ?>
</ol>
<section class="card"><div class="card-b small"><strong>Ready for deployment, not a prototype:</strong> the same build runs in production mode against the university's SIS, Moodle or Blackboard, single sign-on and e-mail; the Docker stack adds HTTPS, encrypted backups, health checks and IT alerts. Every change runs the automated test suites (see the repository's CI). Demo shortcuts on this page do not exist in production mode.</div></section>
</main></body></html>

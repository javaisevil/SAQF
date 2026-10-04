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
    ['0:00', 'Sign in the real way', 'On the sign-in page press Use this account → Faculty Member: the robot check ticks itself, then the two-step code screen appears and the demo mailbox shows the e-mail Dr. Omar receives. Tick “Don’t ask again on this browser” and sign in: SAQF says when and where the account was last used. The whole interface is also in Arabic (العربية at the top).', [['Open the secure sign-in', null, 'login.php'], ['Show the Arabic interface', null, 'login.php?lang=ar']]],
    ['0:30', 'Instructor: SAQF found four problems by itself', 'Dr. Omar is revising SWE 412. SAQF already flagged an outcome that cannot be measured, an outcome that is neither linked to the program nor assessed, and weights that add up to 105%. Change “Lab assignments” from 25 to 20 and press Save weights: that problem clears itself at once.', [['Open SWE 412 as Dr. Omar', 'f.omar', 'workspace.php?id=' . $current('SWE 412') . '&tab=structure']]],
    ['1:15', 'Instructor: results, sections and improvements', 'SWE 401 meets its goal overall, but section 02 is 16 points behind section 01, flagged automatically. The Improvement tab shows last year’s plan: 53% → 63%, “Results went up afterwards”. The Evidence tab asks for the midterm paper; uploading it clears the request. Download the Word report in the NCAAA layout.', [['SWE 401 results by section', 'f.omar', 'workspace.php?id=' . $current('SWE 401') . '&tab=results'], ['Evidence and Word report', 'f.omar', 'workspace.php?id=' . $current('SWE 401') . '&tab=evidence'], ['Course file closeout', 'f.omar', 'workspace.php?id=' . $current('SWE 401') . '&tab=closeout']]],
    ['2:15', 'Head of Department: only what needs a decision', 'The department page shows only what needs attention, such as the repeated CLO3 gap and the SO2 program outcome, not every course. Approvals show just the changes, never the whole form.', [['Open as the Head of Department', 'hod.ced', 'department.php'], ['Review the SWE 401 course file', 'hod.ced', 'workspace.php?id=' . $current('SWE 401') . '&tab=closeout']]],
    ['2:45', 'Quality: exceptions, bulk import and Arabic wording', 'Allow or turn down the CIS 491 capstone exception, with a reason. Import specifications brings the university’s existing approved specifications in from one CSV file. Arabic wording shows what still needs Arabic and accepts a filled-in spreadsheet.', [['CIS 491 exception (Quality)', 'qa.director', $cis491 ? 'exceptions.php?finding=' . $cis491 : 'exceptions.php'], ['Import specifications', 'qa.director', 'spec_import.php'], ['Arabic wording', 'qa.director', 'translations.php']]],
    ['3:30', 'IT: the automation, live', 'University systems → Publish now: the LMS releases SWE 302 midterm grades and SAQF raises an early warning by itself. Publish assignment: the SIS gives a course to a brand-new instructor; SAQF creates the account and the course record. The Security center lists every control with its recorded status, including what is not configured in this demo; Run security self-test exercises some protections (a self-check, not a penetration test), and the printable security evidence report is a self-assessment. Access review shows who still needs which access. Go-live shows each connection as demo data, with the templates IT replaces with the university’s own data.', [['University systems simulator', 'it.admin', 'admin.php?tab=integrations'], ['Security center and self-test', 'it.admin', 'admin.php?tab=center'], ['Access review', 'it.admin', 'admin.php?tab=review'], ['Go-live: ready to connect', 'it.admin', 'admin.php?tab=golive']]],
    ['4:30', 'Leadership: the whole university', 'Programs that need help, problems that keep coming back and progress term by term across colleges, with a click through to any course.', [['Open as the Vice President', 'vp.academic', 'institution.php']]],
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

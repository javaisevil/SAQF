<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Policy;
use Saqf\Security\Mfa;
use Saqf\Web\View as V;

// Help: how to get around, the answers to the questions each role asks most, and how to keep the
// account safe. Written for the person's own role.
$user = saqf_page();
$role = $user['role'];
$faq = [
    'faculty' => [
        ['Where do I start?', 'My courses lists what needs you at the top, most urgent first. Each item has one button that opens the exact place to act. Everything else SAQF does by itself.', 'faculty.php'],
        ['How do I fix a problem SAQF found in my course?', 'Open the course, then the Structure tab. Each problem says what is wrong and how to fix it; it disappears by itself as soon as the fix is saved.', 'faculty.php'],
        ['Where do my students\' results come from?', 'From the LMS gradebook, as soon as grades are published there. You do not type or upload anything unless the LMS is not connected.', 'faculty.php'],
        ['How do I add an exam paper as evidence?', 'Open the course, then the Evidence tab, choose the assessment and upload the file. The request disappears once the paper is filed.', 'faculty.php'],
        ['How do I get my deadlines in my calendar?', 'On My courses, press "Add my deadlines to my calendar". The file opens in Outlook, Google Calendar or Apple Calendar.', 'calendar.php'],
        ['How do I get the course report in Word?', 'Open the course, then Course report, and choose Word (English) or Word (Arabic).', 'faculty.php'],
    ],
    'hod' => [
        ['What needs my decision?', 'My department shows only the courses and program outcomes that need you. Healthy courses are folded away.', 'department.php'],
        ['How do I approve a change to a specification?', 'Approvals lists the changes waiting for you; you see only what changed, never the whole form again.', 'approvals.php'],
        ['How do I give a course to an instructor?', 'Normally the timetable does it. When it does not, use Assign a course.', 'assign.php'],
    ],
    'qa' => [
        ['What needs Quality?', 'Problems to sort out lists only data conflicts, exceptions to policy, risks and the sample of automatic approvals.', 'exceptions.php'],
        ['How do I change a threshold, such as the 70% goal?', 'Quality policies. Every change is recorded with your reason.', 'policies.php'],
        ['How do I bring in existing specifications?', 'Import specifications accepts one CSV file and checks every course before anything is written.', 'spec_import.php'],
    ],
    'dean' => [['What does my college need?', 'My college shows the programs and problems that need attention, with a click through to any course.', 'college.php']],
    'leadership' => [['Where is the university picture?', 'University overview: programs that need help, problems that keep coming back, and progress term by term.', 'institution.php']],
    'admin' => [
        ['Is everything running?', 'System health shows the database, the scheduler, the connections and the audit log at a glance; IT alerts lists anything that needs you.', 'admin.php'],
        ['How do I prove the security controls work?', 'Security center → Run security self-test, then open the Security evidence report.', 'admin.php?tab=center'],
        ['Someone is locked out or lost their phone', 'Users & access → the person → Unlock, or Reset two-step verification (a reason is required).', 'admin.php?tab=users'],
        ['How do we connect the university systems?', 'Go-live shows each connection, what is missing and the templates for IT.', 'admin.php?tab=golive'],
    ],
][$role] ?? [];
$mfaOn = Mfa::enabled($user) ? 'an authenticator app' : (Mfa::emailAllowed($user) && Mfa::required($user) ? 'a code e-mailed to you' : null);
V::header('Help', $user, ['subtitle' => 'Getting around, common questions and staying secure']);
?>
<div class="split"><div class="stack">
  <section class="card"><div class="card-h"><h2>Common questions</h2></div><div class="card-b">
    <?php foreach ($faq as $i => [$q, $a, $link]): ?><details class="faq"<?= $i === 0 ? ' open' : '' ?>><summary><?= V::h($q) ?></summary><p class="small"><?= V::h($a) ?></p><a class="btn btn-sm" href="<?= V::h($link) ?>">Take me there</a></details><?php endforeach; ?>
    <?php if (!$faq): ?><p class="muted small">Your home page lists what needs you.</p><?php endif; ?>
  </div></section>
  <section class="card"><div class="card-h"><h2>Getting around</h2></div><div class="card-b small"><ul class="plain-list">
    <li><strong>The menu on the left</strong> holds every page you can use; the highlighted entry is where you are.</li>
    <li><strong>Search</strong> at the top finds courses, programs, outcomes, people and problems as you type. Press <kbd>/</kbd> to jump to it.</li>
    <li><strong>The bell</strong> shows only things that need you, never routine updates.</li>
    <li><strong>العربية / English</strong> at the top right switches the whole interface; SAQF remembers your choice on every device.</li>
    <li><strong>Forms warn you</strong> before you leave a page with unsaved changes, and buttons cannot be pressed twice by accident.</li>
  </ul></div></section>
</div><aside class="stack">
  <section class="card"><div class="card-h"><?= V::icon('shield') ?><h2>Keeping your account safe</h2></div><div class="card-b small"><ul class="plain-list">
    <li><?= $mfaOn ? 'Your sign-in is protected by two-step verification with ' . V::h($mfaOn) . '.' : 'Turn on two-step verification under Account & security.' ?></li>
    <li>SAQF staff will never ask for your password or a sign-in code.</li>
    <li>Check <strong>Recent sign-in activity</strong> under Account &amp; security; if something is not you, press "This wasn't me".</li>
    <li>You are signed out after <?= (int) Policy::get('session.idle_minutes') ?> minutes without activity; a warning appears two minutes before, with a button to stay signed in.</li>
    <li>On a shared computer, never tick "Don't ask again on this browser", and sign out when you finish.</li>
  </ul><a class="btn btn-sm" href="account.php">Account &amp; security</a></div></section>
  <section class="card"><div class="card-h"><h2>Keyboard shortcuts</h2></div><div class="card-b small"><table><tbody>
    <tr><td><kbd>/</kbd></td><td>Search</td></tr><tr><td><kbd>g</kbd> <kbd>h</kbd></td><td>Go to your home page</td></tr><tr><td><kbd>g</kbd> <kbd>n</kbd></td><td>Go to notifications</td></tr><tr><td><kbd>g</kbd> <kbd>a</kbd></td><td>Go to Account & security</td></tr><tr><td><kbd>?</kbd></td><td>Show the shortcuts</td></tr>
  </tbody></table></div></section>
  <section class="card"><div class="card-h"><h2>Need a person?</h2></div><div class="card-b small">For access problems, contact the university IT service desk. If something goes wrong, SAQF shows a short reference code: quote it, and IT can see exactly what happened.</div></section>
</aside></div>
<?php V::footer();

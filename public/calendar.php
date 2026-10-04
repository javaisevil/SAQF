<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Quality\ActionCenter;
use Saqf\Security\Authz;
use Saqf\Web\Ics;

// "Add my deadlines to my calendar": a downloadable .ics file with the dates that concern this
// person (their action-center items with a due date, and the key dates of the terms they teach in).
// It is generated for the signed-in person only; there is no shareable feed link to leak.
$user = saqf_page();
$events = [];
foreach (ActionCenter::forUser($user) as $a) {
    if (!empty($a['due'])) {
        $events[] = ['uid' => 'action:' . $user['id'] . ':' . $a['kind'] . ':' . $a['title'], 'date' => (string) $a['due'], 'title' => 'SAQF: ' . $a['title'], 'description' => trim($a['reason'] . ' ' . $a['context'])];
    }
}
if ($user['role'] === 'faculty') {
    foreach (Db::all(
        'SELECT DISTINCT t.id, t.name, t.ends_on, t.grades_due_on FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE ' . Authz::teachesSql('o') . ' AND t.status <> "closed"',
        [$user['id'], $user['id']]
    ) as $t) {
        $events[] = ['uid' => 'term-end:' . $t['id'], 'date' => $t['ends_on'], 'title' => $t['name'] . ' ends', 'description' => 'Last day of the term.'];
        $events[] = ['uid' => 'grades-due:' . $t['id'], 'date' => $t['grades_due_on'], 'title' => 'Final grades due (' . $t['name'] . ')', 'description' => 'SAQF imports grades from the LMS once they are published there.'];
    }
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="saqf-deadlines.ics"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo Ics::calendar('SAQF deadlines · ' . $user['full_name'], $events);

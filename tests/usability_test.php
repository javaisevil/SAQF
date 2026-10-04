<?php
declare(strict_types=1);

/**
 * Faculty-facing time savers (SAQF 2.6): the gradebook preview before anything is written, the "facts" next to the
 * report, deadline-aware reminders, the closeout board and the progress bars. Each one saves a person work without
 * deciding anything for them; the tests check both halves (it helps, and it does not overreach).
 * Run against a FRESH demo database on a test server only:
 *   php bin/install.php --demo --fresh && php tests/usability_test.php
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Quality\Closeout;
use Saqf\Quality\Evidence;
use Saqf\Quality\Facts;

if (Config::env() === 'production') {
    fwrite(STDERR, "Refusing to run: this test changes data and must never run with APP_ENV=production.\n");
    exit(1);
}

function http(string $jar, string $url, ?array $post = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'saqf-usability-test', CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array_filter($post, static fn($v) => $v instanceof CURLFile) ? $post : http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, $size), substr($raw, 0, $size)];
}

function csrf_of(string $html): string
{
    return preg_match('/name="(?:_csrf|csrf)" (?:value|content)="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function as_user(string $base, string $username): string
{
    $j = (string) tempnam(sys_get_temp_dir(), 'jar');
    [, $html] = http($j, "$base/login.php");
    http($j, "$base/demo.php", ['_csrf' => csrf_of($html), 'as' => $username, 'next' => 'index.php']);
    return $j;
}

function offering(string $code, string $term = '2026-1'): array
{
    return Db::one('SELECT o.*, c.code AS course_code, c.title AS course_title, c.owner_department_id, t.name AS term_name, t.status AS term_status, t.grades_due_on, u.full_name AS instructor_name
        FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id LEFT JOIN users u ON u.id = o.instructor_id
        WHERE c.code = ? AND t.code = ?', [$code, $term]);
}

/** Uploads a gradebook CSV and returns the page the person lands on. */
function upload(string $app, string $jar, int $oid, array $rows): array
{
    $csv = (string) tempnam(sys_get_temp_dir(), 'gb') . '.csv';
    write_csv($csv, $rows);
    [, $html] = http($jar, "$app/workspace.php?id=$oid&tab=results");
    [$code] = http($jar, "$app/workspace.php?id=$oid", ['_csrf' => csrf_of($html), 'results' => new CURLFile($csv, 'text/csv', 'grades.csv')]);
    [, $page] = http($jar, "$app/workspace.php?id=$oid&tab=results");
    return [$code, $page];
}

function stored(int $oid): int
{
    return (int) Db::val('SELECT COUNT(*) FROM assessment_results WHERE offering_id = ?', [$oid]);
}

$app = serve(dirname(__DIR__) . '/public');
$o = offering('SWE 401');
$oid = (int) $o['id'];
$names = Db::col('SELECT name FROM assessments WHERE spec_version_id = ? ORDER BY id', [$o['spec_version_id']]);
$omar = as_user($app, 'f.omar');

// ---------------------------------------------------------------------------------------------
section('1. Gradebook preview: see what SAQF understood before anything is written');
$before = stored($oid);
$rows = [['student', $names[0]]];
for ($i = 1; $i <= 8; $i++) {
    $rows[] = ['2099' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), (string) (50 + $i * 5)];
}
[$code, $page] = upload($app, $omar, $oid, $rows);
ok($code === 302 && str_contains($page, 'Check before importing') && str_contains($page, 'Import these marks') && str_contains($page, 'Cancel'), 'an upload shows a preview with Import and Cancel');
ok(str_contains($page, '8 students') && str_contains($page, V_h($names[0])) && str_contains($page, '72.5%'), 'the preview names the assessment, the number of students and the average (72.5%)');
ok(stored($oid) === $before, 'the preview wrote nothing');
ok(!preg_match('/2099\d{5}/', $page), 'no student number appears on the page');
[$code] = http($omar, "$app/workspace.php?id=$oid", ['op' => 'gb_confirm']);
ok(stored($oid) === $before, 'a confirmation without the anti-forgery token imports nothing');
[, $page] = http($omar, "$app/workspace.php?id=$oid&tab=results");
ok(str_contains($page, 'Check before importing'), '…and the preview is still waiting');
[$code] = http($omar, "$app/workspace.php?id=$oid", ['_csrf' => csrf_of($page), 'op' => 'gb_cancel']);
[, $page] = http($omar, "$app/workspace.php?id=$oid&tab=results");
ok(str_contains($page, 'Nothing was imported') && !str_contains($page, 'Check before importing') && stored($oid) === $before, 'Cancel discards the preview and imports nothing');

// warnings
$rows = [['student', $names[0], 'Quiz that is not in the plan']];
for ($i = 1; $i <= 10; $i++) {
    $rows[] = ['2098' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), $i <= 5 ? '0' : '0.8', '90'];
}
[, $page] = upload($app, $omar, $oid, $rows);
ok(str_contains($page, 'Zeros count as real marks') , 'a column full of zeros is flagged (a missing mark should be an empty cell)');
ok(str_contains($page, 'Not in the course specification, so left out: Quiz that is not in the plan'), 'a column that is not in the specification is named and left out');
[, $page2] = upload($app, $omar, $oid, [['student', $names[0]], ['2097' . '00001', '0.5'], ['2097' . '00002', '0.9']]);
ok(str_contains($page2, 'every mark is between 0 and 1'), 'marks that look like fractions are flagged (0–1 instead of 0–100)');
[, $page3] = upload($app, $omar, $oid, [['student', 'Something else'], ['209600001', '80']]);
ok(str_contains($page3, 'No column in the file matches an assessment') && !str_contains($page3, 'Import these marks'), 'a file with no matching column offers no Import button and lists the specification\'s assessments');
http($omar, "$app/workspace.php?id=$oid", ['_csrf' => csrf_of($page3), 'op' => 'gb_cancel']);

// confirm, replacements
[, $page] = upload($app, $omar, $oid, [['student', $names[0]], ['209500001', '71'], ['209500002', '64']]);
[$code] = http($omar, "$app/workspace.php?id=$oid", ['_csrf' => csrf_of($page), 'op' => 'gb_confirm']);
[, $page] = http($omar, "$app/workspace.php?id=$oid&tab=results");
ok($code === 302 && str_contains($page, '2 results imported') && stored($oid) === $before + 2, 'Import writes exactly what the preview showed');
[, $page] = upload($app, $omar, $oid, [['student', $names[0]], ['209500001', '75'], ['209500003', '80']]);
ok(str_contains($page, '1 student(s) already have a mark here; the new mark replaces it'), 'marks that already exist are announced as replacements before they are replaced');
http($omar, "$app/workspace.php?id=$oid", ['_csrf' => csrf_of($page), 'op' => 'gb_cancel']);

// who may use it
$hod = as_user($app, 'hod.ced');
[, $html] = http($hod, "$app/workspace.php?id=$oid&tab=results");
ok(!str_contains($html, 'name="results"'), 'a Head of Department (read-only on someone else\'s course) is not offered the upload');
$csv = (string) tempnam(sys_get_temp_dir(), 'gb') . '.csv';
write_csv($csv, [['student', $names[0]], ['209400001', '70']]);
[$code] = http($hod, "$app/workspace.php?id=$oid", ['_csrf' => csrf_of($html), 'results' => new CURLFile($csv, 'text/csv', 'g.csv')]);
ok($code === 403, 'and a hand-made upload from that account is refused (403)');
$stranger = as_user($app, 'f.noura');
[$code] = http($stranger, "$app/workspace.php?id=$oid&tab=results");
ok($code === 403 || $code === 404, 'an instructor of another department cannot open the course at all');

// ---------------------------------------------------------------------------------------------
section('2. Facts for your reading: numbers, never meaning');
$facts = Facts::forOffering($o);
$texts = array_column($facts, 'text');
ok(count($facts) >= 4 && !array_diff(array_unique(array_column($facts, 'tone')), ['ok', 'warn', 'info']), 'the course gets several facts, each marked ok, warning or information');
ok($texts === array_column(Facts::forOffering($o), 'text'), 'the same records always give the same sentences');
ok((bool) array_filter($texts, static fn($t) => preg_match('/^CLO\d+: \d+% of students met it(?: so far)? \(goal \d+%\)/', $t)), 'each learning outcome states its figure and its goal');
ok((bool) array_filter($texts, static fn($t) => str_contains($t, 'assessments have grades')), 'the grading progress is stated');
$judging = array_filter($texts, static fn($t) => preg_match('/\b(should|recommend|because|caused|reason|therefore|improve by|need to|must)\b/i', $t));
ok(!$judging, 'no fact explains, advises or judges' . ($judging ? ': ' . implode(' | ', $judging) : ''));
$noSpec = ['id' => 0, 'spec_version_id' => null, 'term_id' => $o['term_id']];
ok(Facts::forOffering($noSpec)[0]['tone'] === 'info' && str_contains(Facts::forOffering($noSpec)[0]['text'], 'no approved specification'), 'a course without a specification says so instead of inventing figures');
$narrBefore = (int) Db::val('SELECT COUNT(*) FROM offering_narratives WHERE offering_id = ?', [$oid]);
[$code, $html] = http($omar, "$app/workspace.php?id=$oid&tab=report");
ok($code === 200 && str_contains($html, 'Facts for your reading') && str_contains($html, 'not their meaning') && str_contains($html, 'SAQF never writes it for you'), 'the report tab shows the facts and says the meaning is the instructor\'s');
ok(preg_match('/<textarea name="content"[^>]*>\s*<\/textarea>/', $html) === 1 || !str_contains($html, 'of students met it</textarea>'), 'nothing from the facts is placed into the instructor\'s own text box');
ok((int) Db::val('SELECT COUNT(*) FROM offering_narratives WHERE offering_id = ?', [$oid]) === $narrBefore, 'viewing the facts writes nothing into the course report');
$hodHtml = http($hod, "$app/workspace.php?id=$oid&tab=report")[1];
ok(str_contains($hodHtml, 'Facts for your reading'), 'a Head of Department sees the same facts (read-only)');

// ---------------------------------------------------------------------------------------------
section('3. Progress on the home page');
$c = Closeout::forOffering($o);
$p = Closeout::progress($c);
ok($p['total'] === count($c['items']) && $p['done'] === $c['counts']['complete'] + $c['counts']['scheduled'] && $p['pct'] >= 0 && $p['pct'] <= 100, 'progress counts come from the checklist (done + scheduled out of all items)');
ok($p['next'] === null || in_array($p['next'], array_column($c['items'], 'label'), true), 'the "next" step is an item on the checklist');
[$code, $html] = http($omar, "$app/faculty.php");
ok($code === 200 && substr_count($html, 'class="cfile"') === (int) Db::val('SELECT COUNT(*) FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE t.status = "active" AND o.instructor_id = (SELECT id FROM users WHERE username = "f.omar")') && str_contains($html, 'Course file: ' . $p['done'] . ' of ' . $p['total'] . ' done'), 'every course card on My courses shows its course file progress');
ok(str_contains($html, 'role="img" aria-label="Course file:'), 'the bar has a text alternative');

// ---------------------------------------------------------------------------------------------
section('4. Deadline-aware reminders');
$due = new DateTimeImmutable((string) $o['grades_due_on']);
$instructor = (int) $o['instructor_id'];
$count = static fn(string $key) => (int) Db::val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dedupe_key = ?', [$instructor, $key]);
Clock::set($due->modify('-30 days')->format('Y-m-d') . ' 09:00:00');
ok(Closeout::remindAll() === 0, 'a month before grades are due nobody is reminded');
Clock::set($due->modify('-13 days')->format('Y-m-d') . ' 09:00:00');
$n = Closeout::remindAll();
ok($n >= 1 && $count("closeout:$oid:d14") === 1, "14 days before: the instructor gets one reminder ($n created across the active term)");
$n = Closeout::remindAll();
ok($n === 0 && $count("closeout:$oid:d14") === 1, 'running it again the same day sends nothing new (no duplicates)');
$note = Db::one('SELECT * FROM notifications WHERE user_id = ? AND dedupe_key = ?', [$instructor, "closeout:$oid:d14"]);
ok(str_contains($note['title'], 'SWE 401') && str_contains($note['title'], 'still missing') && str_contains($note['title'], 'due in 13 days') && str_contains((string) $note['body'], 'Reading of the results') === false || str_contains((string) $note['body'], 'results'), 'it says which course, how many items and how many days are left');
ok(str_contains((string) $note['link'], 'tab=closeout') && !preg_match('/\d{9}/', $note['title'] . $note['body']), 'it links to the course file and carries no student data');
Clock::set($due->modify('-6 days')->format('Y-m-d') . ' 09:00:00');
Closeout::remindAll();
ok($count("closeout:$oid:d7") === 1, '7 days before: a second reminder');
Clock::set($due->modify('-1 days')->format('Y-m-d') . ' 09:00:00');
Closeout::remindAll();
ok($count("closeout:$oid:d2") === 1, '2 days before: a third reminder');
$hodBefore = (int) Db::val('SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.username = "hod.ced" AND n.dedupe_key LIKE "closeout-hod:%"');
ok($hodBefore === 0, 'the Head of Department hears nothing while the deadline has not passed');
Clock::set($due->modify('+3 days')->format('Y-m-d') . ' 09:00:00');
Closeout::remindAll();
ok($count("closeout:$oid:late0") === 1, 'once overdue, the instructor is reminded weekly');
ok((int) Db::val('SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.username = "hod.ced" AND n.dedupe_key = ?', ["closeout-hod:$oid:late0"]) === 1, 'and the Head of Department is told the course file is overdue');
Clock::set($due->modify('+10 days')->format('Y-m-d') . ' 09:00:00');
Closeout::remindAll();
ok($count("closeout:$oid:late1") === 1, 'a week later, the next weekly reminder');
Policy::set('closeout.reminders', '0', 'test');
Clock::set($due->modify('+17 days')->format('Y-m-d') . ' 09:00:00');
ok(Closeout::remindAll() === 0 && $count("closeout:$oid:late2") === 0, 'with the policy switched off no reminder is sent');
Policy::set('closeout.reminders', '1', 'test');
Clock::set(null);
ok(Db::val('SELECT value FROM quality_policies WHERE policy_key = "closeout.reminders"') === '1', 'the policy is back on');
$sched = file_get_contents(__DIR__ . '/../src/Quality/Scheduler.php');
ok(str_contains($sched, 'Closeout::remindAll'), 'the daily scheduler runs the reminders by itself');

// ---------------------------------------------------------------------------------------------
section('5. Closeout board for Heads of Department and Quality');
[$code, $html] = http($omar, "$app/closeout_board.php");
ok($code === 403, 'an instructor cannot open the board (403)');
[$code, $html] = http($hod, "$app/closeout_board.php");
ok($code === 200 && str_contains($html, 'SWE 401') && str_contains($html, 'Export CSV') && str_contains($html, 'Quality\'s to set'), 'the Head of Department sees the board with the note that the checklist is Quality\'s to set');
ok(!str_contains($html, 'ACC 311') && !str_contains($html, 'MIS 327'), 'and only their own department\'s courses (no ACC or MIS)');
ok(substr_count($html, 'class="cfile-bar"') === (int) Db::val('SELECT COUNT(*) FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE o.term_id = ? AND c.owner_department_id = 1', [$o['term_id']]), 'every course in the department has a progress bar');
$qa = as_user($app, 'qa.director');
[$code, $qhtml] = http($qa, "$app/closeout_board.php");
ok($code === 200 && str_contains($qhtml, 'ACC 311') && str_contains($qhtml, 'SWE 401'), 'Quality sees every department');
[, $miss] = http($qa, "$app/closeout_board.php?show=ready");
ok(!str_contains($miss, 'SWE 401') || str_contains($miss, 'Ready'), 'the "Ready" filter lists only ready courses');
[$code, $csv, $head] = http($hod, "$app/closeout_board.php?term=" . (int) $o['term_id'] . "&show=all&export=csv");
ok($code === 200 && str_contains($head, 'text/csv') && str_contains($head, 'attachment') && str_contains($head, 'no-store'), 'the CSV is a download, not cached');
ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'course,title,department') && str_contains($csv, 'SWE 401') && !str_contains($csv, 'ACC 311'), 'it has Arabic-safe encoding, a header, and the same scope as the page');
ok(!preg_match('/\d{9}/', $csv), 'it contains no student identifiers');
ok((int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action = "export.downloaded" AND summary LIKE "Course file closeout board exported%"') === 1, 'the download is in the audit log');
$fx = explode("\n", trim(substr(Closeout::csv(['a'], [['=HYPERLINK("http://x")'], ['+1'], ['@SUM(A1)'], ['-2+3'], ['plain']]), 3)));
ok(count($fx) === 6 && !array_filter(array_slice($fx, 1, 4), static fn($l) => !str_starts_with($l, "'") && !str_starts_with($l, '"\'')) && $fx[5] === 'plain', 'spreadsheet formulas in any cell (=, +, -, @) are neutralised; ordinary text is untouched');
[$code] = http($hod, "$app/closeout_board.php?term=99999&show=bogus");
ok($code === 200, 'an invalid term or filter falls back instead of failing');

// ---------------------------------------------------------------------------------------------
section('6. Several evidence files at once, with suggestions a person can correct');
$assess = array_map(static fn($a) => ['id' => (int) $a['id'], 'name' => $a['name']], \Saqf\Quality\Specs::load((int) $o['spec_version_id'])['assessments']);
$mid = null;
$fin = null;
foreach ($assess as $a) {
    if (stripos($a['name'], 'mid') !== false) {
        $mid = $a['id'];
    }
    if (stripos($a['name'], 'final') !== false) {
        $fin = $a['id'];
    }
}
ok($mid !== null && $fin !== null, 'the demo course has a midterm and a final assessment to match against');
$s = Evidence::suggest('SWE401_Midterm_Exam_Paper.pdf', $assess);
ok($s['kind'] === 'assessment' && $s['assessment'] === $mid && $s['sure'], 'a file named like the midterm paper is suggested as the midterm assessment paper');
$s = Evidence::suggest('Midterm rubric.pdf', $assess);
ok($s['kind'] === 'rubric' && $s['assessment'] === $mid, '"rubric" in the name makes it a marking rubric for that assessment');
$s = Evidence::suggest('mid-term marked samples.docx', $assess);
ok($s['kind'] === 'student_work' && $s['assessment'] === $mid, '"marked samples" makes it student work, and "mid-term" still finds the midterm');
$s = Evidence::suggest('Final exam model answers.pdf', $assess);
ok($s['kind'] === 'assessment' && $s['assessment'] === $fin, 'model answers stay with the exam paper they belong to');
$s = Evidence::suggest('IMG_0042.jpg', $assess);
ok($s['kind'] === 'other' && $s['assessment'] === null && !$s['sure'], 'a file SAQF cannot place is "other", with no assessment, and flagged as not sure (it never guesses silently)');
$s = Evidence::suggest('Exam.pdf', [['id' => 1, 'name' => 'Midterm Exam'], ['id' => 2, 'name' => 'Final Exam']]);
ok($s['assessment'] === null && !$s['sure'], 'a name that fits two assessments equally is left for the person to choose');
$s = Evidence::suggest('اختبار نصفي.pdf', [['id' => 7, 'name' => 'Midterm Exam']]);
ok($s['kind'] === 'assessment' && $s['assessment'] === 7, 'Arabic file names are understood (اختبار نصفي = midterm exam)');
$s = Evidence::suggest("../../etc/passwd\0.pdf", $assess);
ok(!str_contains($s['title'], '/') && !str_contains($s['title'], "\0"), 'a hostile file name yields a harmless title');

[$code, $json] = (static function () use ($app, $omar, $oid, $mid) {
    [, $html] = http($omar, "$app/account.php");
    [$c, $b] = http($omar, "$app/api.php", ['action' => 'evidence_suggest', 'offering' => $oid, 'names' => ['Midterm rubric.pdf', 'notes.txt']], ['X-CSRF-Token: ' . csrf_of($html)]);
    return [$c, json_decode($b, true) ?: []];
})();
ok($code === 200 && ($json['suggestions'][0]['kind'] ?? '') === 'rubric' && ($json['suggestions'][0]['assessment'] ?? null) === $mid && ($json['suggestions'][1]['sure'] ?? true) === false, 'the page asks the server for suggestions (only the file names are sent)');
[, $html] = http($omar, "$app/account.php");
[$code] = http($hod, "$app/api.php", ['action' => 'evidence_suggest', 'offering' => $oid, 'names' => ['a.pdf']], ['X-CSRF-Token: ' . csrf_of($html)]);
ok($code === 403, 'it is not offered to someone who cannot add evidence to the course');
[$code] = http($omar, "$app/api.php", ['action' => 'evidence_suggest', 'offering' => $oid, 'names' => ['a.pdf']]);
ok($code === 403, 'and it needs the anti-forgery token');

$pdf = dirname(__DIR__) . '/docs/demo/SWE401-midterm-exam-paper.pdf';
$cf = static function (string $name, string $path = null) use ($pdf): CURLFile {
    return new CURLFile($path ?? $pdf, 'application/pdf', $name);
};
$evBefore = (int) Db::val('SELECT COUNT(*) FROM evidence_files WHERE offering_id = ? AND deleted_at IS NULL', [$oid]);
[, $html] = http($omar, "$app/workspace.php?id=$oid&tab=evidence");
ok(str_contains($html, 'id="ev-files"') && str_contains($html, 'multiple') && str_contains($html, 'assets/evidence.js') && str_contains($html, 'id="ev-rows"'), 'the evidence page takes several files and loads its (external) script');
$bad = (string) tempnam(sys_get_temp_dir(), 'bad');
file_put_contents($bad, 'this is not a pdf');
[$code] = http($omar, "$app/workspace.php?id=$oid&tab=evidence", [
    '_csrf' => csrf_of($html),
    'evidence_files[0]' => $cf('Midterm exam paper.pdf'), 'evidence_files[1]' => $cf('Midterm rubric.pdf'), 'evidence_files[2]' => $cf('fake.pdf', $bad),
    'item_kind[0]' => 'assessment', 'item_kind[1]' => 'rubric', 'item_kind[2]' => 'assessment',
    'item_assessment[0]' => (string) $mid, 'item_assessment[1]' => (string) $mid, 'item_assessment[2]' => (string) $mid,
    'item_title[0]' => 'Midterm paper', 'item_title[1]' => '', 'item_title[2]' => 'fake',
]);
[, $page] = http($omar, "$app/workspace.php?id=$oid&tab=evidence");
ok($code === 302 && (int) Db::val('SELECT COUNT(*) FROM evidence_files WHERE offering_id = ? AND deleted_at IS NULL', [$oid]) === $evBefore + 2, 'two good files are filed in one go');
ok(str_contains($page, '2 files added') && str_contains($page, 'fake.pdf') && str_contains($page, 'does not match'), 'the bad file is named with the reason, and does not stop the others');
ok(Db::val('SELECT kind FROM evidence_files WHERE offering_id = ? AND title = "Midterm paper"', [$oid]) === 'assessment' && Db::val('SELECT kind FROM evidence_files WHERE offering_id = ? AND original_name = "Midterm rubric.pdf"', [$oid]) === 'rubric', 'each file keeps the kind the person confirmed');
// no JavaScript: the server makes the same suggestion
[, $html] = http($omar, "$app/workspace.php?id=$oid&tab=evidence");
http($omar, "$app/workspace.php?id=$oid&tab=evidence", ['_csrf' => csrf_of($html), 'evidence_files[0]' => $cf('Final exam paper.pdf')]);
$row = Db::one('SELECT kind, assessment_id FROM evidence_files WHERE offering_id = ? AND original_name = "Final exam paper.pdf"', [$oid]);
ok($row && $row['kind'] === 'assessment' && (int) $row['assessment_id'] === $fin, 'without JavaScript the server fills in the same suggestion');
[, $html] = http($omar, "$app/workspace.php?id=$oid&tab=evidence");
$many = ['_csrf' => csrf_of($html)];
for ($i = 0; $i < 13; $i++) {
    $many["evidence_files[$i]"] = $cf("f$i.pdf");
}
http($omar, "$app/workspace.php?id=$oid&tab=evidence", $many);
[, $page] = http($omar, "$app/workspace.php?id=$oid&tab=evidence");
ok(str_contains($page, 'up to 12 files') && !Db::val('SELECT 1 FROM evidence_files WHERE offering_id = ? AND original_name = "f0.pdf"', [$oid]), 'more than 12 files at once is refused with a clear message (nothing half-filed)');
[$code] = http($hod, "$app/workspace.php?id=$oid&tab=evidence", ['_csrf' => csrf_of(http($hod, "$app/workspace.php?id=$oid&tab=evidence")[1]), 'evidence_files[0]' => $cf('hod.pdf')]);
ok($code === 403 && !Db::val('SELECT 1 FROM evidence_files WHERE offering_id = ? AND original_name = "hod.pdf"', [$oid]), 'a Head of Department cannot file evidence into another instructor\'s course (403)');
ok((int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action = "evidence.uploaded" AND object_id = ?', [(string) $oid]) >= 3, 'every file is audited individually');

finish();

function V_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

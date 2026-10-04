<?php
declare(strict_types=1);

/**
 * Course file closeout (SAQF 2.5): the checklist is derived from records and from the settings
 * Quality chose; human items (reading of results, improvement plans, evidence acceptance) are never
 * completed by SAQF; reviews and the course file package are authorised and audited.
 * Run against a FRESH demo database on a test server only:
 *   php bin/install.php --demo --fresh && php tests/closeout_test.php
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Quality\Closeout;
use Saqf\Quality\Evidence;

if (Config::env() === 'production') {
    fwrite(STDERR, "Refusing to run: this test changes data and must never run with APP_ENV=production.\n");
    exit(1);
}

function http(string $jar, string $url, ?array $post = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'saqf-closeout-test', CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
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

/** Calls api.php the way the page does (CSRF token in the header). */
function api(string $base, string $jar, string $action, array $data): array
{
    [, $html] = http($jar, "$base/account.php"); // index.php only redirects; any full page carries the token
    [$code, $body] = http($jar, "$base/api.php", ['action' => $action] + $data, ['X-CSRF-Token: ' . csrf_of($html)]);
    return [$code, json_decode($body, true) ?: []];
}

function offering(string $code, string $term = '2026-1'): array
{
    return Db::one('SELECT o.*, c.code AS course_code, c.title AS course_title, t.name AS term_name, t.status AS term_status, u.full_name AS instructor_name
        FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id LEFT JOIN users u ON u.id = o.instructor_id
        WHERE c.code = ? AND t.code = ?', [$code, $term]);
}

function item(array $o, string $key): ?array
{
    foreach (Closeout::forOffering($o)['items'] as $i) {
        if ($i['key'] === $key) {
            return $i;
        }
    }
    return null;
}

$app = serve(dirname(__DIR__) . '/public');
$o = offering('SWE 401');
$oid = (int) $o['id'];

section('1. The checklist comes from the records');
$c = Closeout::forOffering($o);
ok(array_column($c['items'], 'key') === ['spec', 'checks', 'results', 'evidence', 'improvement', 'reflection', 'report'], 'default checklist: specification, problems, grades, evidence, improvement plans, reading of results, sealed report');
ok(item($o, 'spec')['state'] === 'complete' && str_contains(item($o, 'spec')['detail'], 'approved'), 'the approved specification in use is complete');
$results = item($o, 'results');
ok($results['state'] === 'missing' && str_contains($results['detail'], 'still to come') && $results['tab'] === 'results', 'grades still to come are missing, named, with the next step and the tab to open');
ok(item($o, 'evidence')['state'] === 'missing' && str_contains(item($o, 'evidence')['detail'], 'Missing:'), 'missing evidence is listed per assessment and kind');
$refl = item($o, 'reflection');
ok($refl['state'] === 'missing' && str_contains($refl['detail'], 'SAQF does not write this') && str_contains($refl['owner'], 'Omar'), 'the instructor\'s reading of the results is missing, owned by the instructor, and never written by SAQF');
ok(item($o, 'report')['state'] === 'scheduled' && str_contains(item($o, 'report')['detail'], 'not an approval'), 'sealing the report is automatic at term end and is not called an approval');
ok(Closeout::checklistSetBy() === null, 'until Quality changes anything, the checklist is reported as SAQF\'s default');
ok(!$c['ready'], 'mid-term, the course file is not ready');

section('2. The instructor\'s own words, through the page');
$omar = as_user($app, 'f.omar');
[$code, $html] = http($omar, "$app/workspace.php?id=$oid&tab=closeout");
ok($code === 200 && str_contains($html, 'Course file closeout') && str_contains($html, 'DEMO data') && str_contains($html, 'not yet confirmed by Quality'), 'the Closeout tab shows the checklist, the DEMO label and that the checklist is not yet confirmed');
[$code, $r] = api($app, $omar, 'narrative', ['offering' => $oid, 'section' => 'interpretation', 'content' => 'Section 02 trails on CLO1 and CLO2; the midterm item analysis points to test-design topics.']);
ok($code === 200 && item($o, 'reflection')['state'] === 'complete' && str_contains(item($o, 'reflection')['detail'], 'Omar'), 'once the instructor writes it, the item is complete and names who wrote it');

section('3. Evidence: present is not accepted; a person reviews it');
$paper = dirname(__DIR__) . '/docs/demo/SWE401-midterm-exam-paper.pdf';
$spec = \Saqf\Quality\Specs::load((int) $o['spec_version_id']);
$user = Db::one('SELECT * FROM users WHERE username = "f.omar"');
foreach ($spec['assessments'] as $a) {
    foreach (['assessment', 'student_work'] as $kind) {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'ev');
        copy($paper, $tmp);
        Evidence::store($o, ['tmp_name' => $tmp, 'name' => "{$a['name']}-$kind.pdf", 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], $kind, $kind === 'assessment' && $a === $spec['assessments'][0] ? '=HYPERLINK("http://x")' : "{$a['name']} $kind", (int) $a['id'], null, $user, false);
    }
}
$ev = item($o, 'evidence');
ok($ev['state'] === 'review' && str_contains($ev['detail'], 'No person has reviewed') && str_contains($ev['owner'], 'Head of Department'), 'with every file present the item needs a person\'s review (Head of Department or Quality), not "complete"');
[, $html] = http($omar, "$app/workspace.php?id=$oid&tab=closeout");
ok(!str_contains($html, 'name="op" value="closeout_review"'), 'the instructor is not offered the review form');
[$code] = http($omar, "$app/workspace.php?id=$oid&tab=closeout", ['_csrf' => csrf_of($html), 'op' => 'closeout_review', 'decision' => 'accepted', 'note' => '']);
ok($code === 403 && Closeout::evidenceReview($oid) === null, 'the instructor cannot accept their own evidence (403, nothing recorded)');
$hod = as_user($app, 'hod.ced');
[$code, $html] = http($hod, "$app/workspace.php?id=$oid&tab=closeout");
ok($code === 200 && str_contains($html, 'Review the evidence') && str_contains($html, 'Accept the evidence'), 'the Head of Department sees the files and the review form');
http($hod, "$app/workspace.php?id=$oid&tab=closeout", ['_csrf' => csrf_of($html), 'op' => 'closeout_review', 'decision' => 'returned', 'note' => 'short']);
ok(Closeout::evidenceReview($oid) === null, 'returning without a useful note is refused');
[, $html] = http($hod, "$app/workspace.php?id=$oid&tab=closeout");
http($hod, "$app/workspace.php?id=$oid&tab=closeout", ['_csrf' => csrf_of($html), 'op' => 'closeout_review', 'decision' => 'returned', 'note' => 'Add marked samples with student names removed.']);
ok(item($o, 'evidence')['state'] === 'missing' && str_contains(item($o, 'evidence')['detail'], 'Returned by'), 'returned evidence goes back to the instructor with the reviewer\'s note');
[, $html] = http($hod, "$app/workspace.php?id=$oid&tab=closeout");
http($hod, "$app/workspace.php?id=$oid&tab=closeout", ['_csrf' => csrf_of($html), 'op' => 'closeout_review', 'decision' => 'accepted', 'note' => 'Checked against the specification.']);
ok(item($o, 'evidence')['state'] === 'complete' && str_contains(item($o, 'evidence')['detail'], 'Accepted by'), 'accepted evidence is complete and names the reviewer');
ok((int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action IN ("closeout.evidence_accepted","closeout.evidence_returned") AND object_id = ?', [(string) $oid]) === 2, 'both decisions are in the audit log');
$tmp = (string) tempnam(sys_get_temp_dir(), 'ev');
copy($paper, $tmp);
Evidence::store($o, ['tmp_name' => $tmp, 'name' => 'rubric.pdf', 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], 'rubric', 'Rubric', (int) $spec['assessments'][0]['id'], null, $user, false);
ok(item($o, 'evidence')['state'] === 'review' && str_contains(item($o, 'evidence')['detail'], 'changed after the last review'), 'a file added after acceptance makes the review due again');
$sara = as_user($app, 'f.sara');
[, $html] = http($sara, "$app/workspace.php?id=$oid&tab=closeout");
[$code] = http($sara, "$app/workspace.php?id=$oid&tab=closeout", ['_csrf' => csrf_of($html), 'op' => 'closeout_review', 'decision' => 'accepted', 'note' => '']);
ok($code === 403, 'a section instructor cannot review the course file either');

section('4. Quality configures the checklist (audited); nobody else can');
$qa = as_user($app, 'qa.director');
[$code] = api($app, $hod, 'policy_set', ['key' => 'closeout.evidence_kinds', 'v' => 'assessment', 'reason' => 'test']);
ok($code === 403 && Policy::get('closeout.evidence_kinds') === 'assessment+student_work', 'a Head of Department cannot change the checklist');
[$code] = api($app, $qa, 'policy_set', ['key' => 'closeout.require_reflection', 'v' => '0', 'reason' => 'Faculty reflection collected in the department meeting this term']);
Policy::flush();
ok($code === 200 && item($o, 'reflection') === null, 'Quality can switch an item off, and it leaves the checklist');
[$code] = api($app, $qa, 'policy_set', ['key' => 'closeout.evidence_kinds', 'v' => 'assessment+rubric+student_work', 'reason' => 'Rubrics required from this term']);
Policy::flush();
ok($code === 200 && item($o, 'evidence')['state'] === 'missing' && str_contains(item($o, 'evidence')['detail'], 'rubric'), 'requiring rubrics makes the missing rubrics show up');
ok(Closeout::checklistSetBy() !== null && (int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action = "policy.changed" AND object_id LIKE "closeout.%"') === 2, 'the page now says Quality set the checklist; both changes are audited');
[$code] = api($app, $qa, 'policy_set', ['key' => 'closeout.evidence_kinds', 'v' => 'everything', 'reason' => 'x']);
ok($code === 422, 'an unknown evidence choice is refused');

section('5. A plan for every missed goal stays the instructor\'s');
// The rest of the term's grades arrive (synthetic, pseudonymous keys already in SAQF), all low.
$keys = Db::col('SELECT DISTINCT student_ref FROM assessment_results WHERE offering_id = ?', [$oid]);
$graded = array_map('intval', Db::col('SELECT DISTINCT assessment_id FROM assessment_results WHERE offering_id = ?', [$oid]));
$batch = [];
foreach ($spec['assessments'] as $a) {
    if (!in_array((int) $a['id'], $graded, true)) {
        foreach ($keys as $k) {
            $batch[$a['name']][$k] = 40;
        }
    }
}
\Saqf\Core\Audit::asSystem(static fn() => \Saqf\Quality\Achievement::import($oid, $batch, 'lms', 'TEST-CLOSEOUT-FINAL'), 'integration', 'LMS integration');
ok(item($o, 'results')['state'] === 'complete', 'once every assessment has grades, the grades item is complete (' . count($keys) . ' pseudonymous students)');
$im = item($o, 'improvement');
$drafts = Db::col('SELECT id FROM improvement_actions WHERE origin_offering_id = ? AND status = "draft"', [$oid]);
ok($drafts && $im['state'] === 'review' && str_contains($im['detail'], 'prepared by SAQF') && str_contains($im['next'], 'your academic decision'), 'missed goals: SAQF prepares the improvement records with the facts, and the item waits for the instructor\'s own plan (' . count($drafts) . ')');
$omarId = (int) Db::val('SELECT id FROM users WHERE username = "f.omar"');
foreach ($drafts as $id) {
    api($app, $omar, 'improvement_commit', ['id' => $id, 'action_text' => 'Add a formative checkpoint on test planning in week 9 with rubric feedback.', 'owner' => $omarId, 'due_on' => '2027-02-15']);
}
ok(item($o, 'improvement')['state'] === 'complete' && str_contains(item($o, 'improvement')['detail'], 'written by the instructor'), 'when the instructor writes each plan, the item is complete');

section('6. Course file package');
[$code, $zip, $head] = http($omar, "$app/export.php?doc=package&id=$oid");
ok($code === 200 && stripos($head, 'application/zip') !== false && str_starts_with($zip, 'PK'), 'the instructor downloads the course file package (ZIP)');
$dir = tempdir('saqf-pkg');
file_put_contents("$dir/p.zip", $zip);
$names = [];
$z = new ZipArchive();
if ($z->open("$dir/p.zip") === true) {
    for ($i = 0; $i < $z->numFiles; $i++) {
        $names[] = $z->getNameIndex($i);
    }
    $z->extractTo($dir);
    $z->close();
}
ok($names === ['course-report.docx', 'specification-v1.docx', 'evidence-index.csv', 'grade-batches.csv', 'closeout-checklist.csv', 'README.txt', 'SHA256SUMS.txt'], 'it holds the report, the approved specification, the evidence index, grade provenance, the checklist, a README and checksums');
$sumsOk = true;
foreach (explode("\n", trim((string) @file_get_contents("$dir/SHA256SUMS.txt"))) as $line) {
    [$sum, $name] = array_pad(preg_split('/\s+/', $line, 2), 2, '');
    $sumsOk = $sumsOk && is_file("$dir/$name") && hash_file('sha256', "$dir/$name") === $sum;
}
ok($sumsOk && count($names) === 7, 'every checksum in SHA256SUMS.txt matches its file');
$readme = (string) @file_get_contents("$dir/README.txt");
ok(str_contains($readme, 'DEMO DATA') && str_contains($readme, 'NOT sealed') && str_contains($readme, 'not an approval of any NCAAA report') && str_contains($readme, 'set by Quality'), 'the README states demo data, that the report is not sealed, that nothing is an NCAAA approval, and who set the checklist');
$index = (string) @file_get_contents("$dir/evidence-index.csv");
ok(substr_count($index, "\n") >= 10 && str_contains($index, "'=HYPERLINK") && !str_contains($index, ',=HYPERLINK'), 'the evidence index lists every file with its checksum; a formula-like title is neutralised for spreadsheets');
ok(!array_filter($names, static fn($n) => str_ends_with($n, '.pdf')), 'the evidence files themselves are not in the package');
ok((bool) Db::val('SELECT 1 FROM audit_log WHERE action = "closeout.package_exported" AND object_id = ? AND new_value LIKE ?', [(string) $oid, '%' . hash('sha256', $zip) . '%']), 'the download is audited with the package checksum');
$noura = as_user($app, 'f.noura');
[$code] = http($noura, "$app/export.php?doc=package&id=$oid");
ok($code === 403, 'a faculty member of another department cannot download it');
[$code] = http((string) tempnam(sys_get_temp_dir(), 'jar'), "$app/export.php?doc=package&id=$oid");
ok($code === 302, 'nor can someone who is not signed in');

section('7. Arabic and narrow screens');
http($omar, "$app/workspace.php?id=$oid&tab=closeout&lang=ar");
[$code, $html] = http($omar, "$app/workspace.php?id=$oid&tab=closeout");
ok($code === 200 && str_contains($html, 'dir="rtl"') && str_contains($html, 'إغلاق ملف المقرر'), 'the Closeout tab works in Arabic, right to left');
http($omar, "$app/workspace.php?id=$oid&tab=closeout&lang=en");
ok(str_contains($html, 'class="table-wrap"'), 'the checklist table scrolls inside its card on a narrow screen');

finish();

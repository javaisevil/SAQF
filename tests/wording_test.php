<?php
declare(strict_types=1);

/**
 * Tests for plain wording and Arabic course content: results shown as whole numbers, course facts
 * in plain words, "what needs you" lists without jargon, the Arabic wording of university data and
 * course content (Registrar catalogue, faculty, the "Arabic wording" page and its spreadsheet),
 * Arabic search and the Arabic Word documents.
 *   php bin/install.php --demo --fresh && php tests/wording_test.php
 * Starts its own SAQF web server on a free port.
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Audit;
use Saqf\Core\Db;
use Saqf\Core\Translations;
use Saqf\Integration\Integrations;
use Saqf\Quality\ActionCenter;
use Saqf\Quality\NcaaaExport;
use Saqf\Quality\Rules;
use Saqf\Quality\Search;
use Saqf\Quality\Status;
use Saqf\Web\I18n;
use Saqf\Web\View;

$app = serve(dirname(__DIR__) . '/public');

function http(string $jar, string $url, ?array $post = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'saqf-wording-test', CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function as_user(string $base, string $username, string $lang = 'en'): string
{
    $j = (string) tempnam(sys_get_temp_dir(), 'jar');
    http($j, "$base/login.php?lang=$lang");
    [, $html] = http($j, "$base/login.php");
    preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
    http($j, "$base/demo.php", ['_csrf' => $m[1] ?? '', 'as' => $username, 'next' => 'index.php']);
    return $j;
}

function csrf_of(string $html): string
{
    return preg_match('/name="(?:_csrf|csrf)" (?:value|content)="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/** The words a person reads: no tags, scripts, attributes or hidden helper text. */
function visible(string $html): string
{
    $html = preg_replace('#<(script|style|textarea)\b.*?</\1>#is', ' ', $html) ?? $html;
    $html = preg_replace('#<div id="ui-text".*?</div>#s', ' ', $html) ?? $html;
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** Reads one file from a ZIP produced by Saqf\Web\Zip (no zip extension needed). */
function unzip_entry(string $zip, string $name): ?string
{
    $pos = 0;
    while (substr($zip, $pos, 4) === "PK\x03\x04") {
        $h = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen', substr($zip, $pos + 4, 26));
        $entry = substr($zip, $pos + 30, $h['nlen']);
        $data = substr($zip, $pos + 30 + $h['nlen'] + $h['xlen'], $h['csize']);
        if ($entry === $name) {
            return $h['method'] === 8 ? (string) gzinflate($data) : $data;
        }
        $pos += 30 + $h['nlen'] + $h['xlen'] + $h['csize'];
    }
    return null;
}

function person(string $username): array
{
    $u = Db::one('SELECT u.*, d.college_id AS dc FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE username = ?', [$username]);
    $u['id'] = (int) $u['id'];
    $u['department_id'] = $u['department_id'] === null ? null : (int) $u['department_id'];
    $u['scope_college_id'] = (int) ($u['college_id'] ?? $u['dc']);
    Audit::actAs('user', $u['id'], $u['full_name'], $u['role']);
    return $u;
}

$o401 = (int) Db::val('SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = "SWE 401" AND t.status = "active"');
$o412 = (int) Db::val('SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = "SWE 412" AND t.status = "active"');

section('1. Numbers people can read');
ok(Rules::whole(69.3) === '69' && Rules::whole(72.5) === '73' && View::pct(72.4) === '72%', 'results are whole numbers (69.3 → 69, 72.4% → 72%)');
$omar = as_user($app, 'f.omar');
[$code, $html] = http($omar, "$app/workspace.php?id=$o401&tab=results");
$text = visible($html);
ok($code === 200 && !preg_match('/\d+\.\d+ ?%/', $text) && !preg_match('/\d+\.\d+ points/', $text), 'the results tab shows no decimal percentages or points');
ok(str_contains($text, 'Students who met it') && str_contains($text, '✓ Verified') && !preg_match('/\b[0-9a-f]{16,}\b/', $text), 'grades show a "✓ Verified" mark instead of a long fingerprint');
[, $html] = http($omar, "$app/workspace.php?id=$o401&tab=improve");
$text = visible($html);
ok(str_contains($text, '53%') && str_contains($text, '63%') && str_contains($text, 'Did it help?') && !str_contains($text, 'causation'), 'the improvement record reads 53% → 63% and asks "Did it help?" in plain words');
[, $html] = http($omar, "$app/workspace.php?id=$o401&tab=report");
ok(!str_contains(visible($html), 'sha256'), 'the sealed course report shows "Sealed … ✓" rather than a SHA-256 hash');
$qa = as_user($app, 'qa.director');
[, $html] = http($qa, "$app/quality.php");
$text = visible($html);
ok(!str_contains($text, 'return rounds') && !str_contains($text, 'median') && str_contains($text, 'of approvals needed nothing from Quality'), 'the Quality overview explains each figure in a sentence (no "return rounds" or "median h")');
$hod = as_user($app, 'hod.ced');
[, $html] = http($hod, "$app/department.php");
$text = visible($html);
ok(preg_match('/SO\d \d+%/', $text) === 1 && str_contains($text, 'met the 70% goal'), 'program outcome chips show percentages with a colour legend ("SO2 69%")');

section('2. Course facts and next steps in plain words');
[, $html] = http($omar, "$app/workspace.php?id=$o401");
$text = visible($html);
ok(str_contains($text, 'About this course') && str_contains($text, '3 credit hours (about 45 hours of class time)') && str_contains($text, 'Take first') && str_contains($text, 'required course'), 'the course overview lists its facts in sentences (credit hours, what to take first, required or elective)');
ok(str_contains($text, 'What needs attention') && !str_contains($text, 'deterministic'), 'the overview leads with what needs attention, without jargon');
[, $html] = http($omar, "$app/faculty.php");
$text = visible($html);
ok(str_contains($text, 'Done for you this term') && str_contains($text, 'checks run on your courses') && !str_contains($text, 'automation ledger'), 'the instructor home says what SAQF did in plain sentences');
$items = ActionCenter::forUser(person('f.omar'));
$bad = array_filter($items, static fn($a) => str_contains($a['title'] . $a['reason'], '(s)') || preg_match('/\bv\d+\b/', $a['title']));
ok($items && !$bad, 'every "What needs you" item is a plain sentence (no "item(s)" or version codes): "' . ($items[0]['title'] ?? '') . '"');
$labels = array_column(Status::STATES, 0);
ok(in_array('Needs you', $labels, true) && in_array('All good', $labels, true), 'course states read "Needs you", "Waiting for approval", "All good"');
$noLabel = array_filter(array_keys(Rules::META), static fn($r) => !isset(Rules::LABELS[$r]));
ok(!$noLabel, 'every rule has a plain name for issue lists' . ($noLabel ? ' (missing: ' . implode(', ', $noLabel) . ')' : ''));

section('3. Arabic wording of university data');
$snap = Integrations::institution()->snapshot();
ok(!empty($snap['arabic']['courses']) && count($snap['arabic']['courses']) >= 300, 'the Registrar catalogue carries Arabic names for ' . count($snap['arabic']['courses'] ?? []) . ' courses');
ok(Translations::get('Software Quality Assurance') === 'ضمان جودة البرمجيات' && (int) Db::val('SELECT COUNT(*) FROM translations WHERE kind = "catalogue"') > 500, 'the catalogue sync stored the Arabic names (course titles, programs, departments, program outcomes, plan groups)');
$qaUser = person('qa.director');
Translations::set('Software Quality Assurance', 'ضمان جودة البرمجيات (مصحح)', 'catalogue', $qaUser['id']);
Translations::fromCatalogue($snap);
ok(Translations::get('Software Quality Assurance') === 'ضمان جودة البرمجيات (مصحح)', 'a correction made by a person survives the next Registrar sync');
Translations::set('Software Quality Assurance', 'ضمان جودة البرمجيات', 'catalogue', $qaUser['id']);
ok(Translations::set('Software Quality Assurance', '', 'catalogue') === false && Translations::get('Software Quality Assurance') !== null, 'an automatic source never deletes Arabic wording');
ok(I18n::phrase('SWE 401 Software Quality Assurance') === 'SWE 401 ضمان جودة البرمجيات' && I18n::phrase('Quiz, Midterm exam') === 'اختبار قصير، الاختبار النصفي', 'codes with titles and lists of names are translated ("SWE 401 …", "Quiz, Midterm exam")');
ok(str_contains((string) I18n::phrase('SWE 401 (Fall 2026): evidence requested for Quiz'), 'خريف 2026') && I18n::phrase('72% so far') === '72% حتى الآن', 'term names inside messages and results "so far" are translated');
$t = I18n::translate('<p><span translate="no">Saved</span> <b>Saved</b></p>');
ok(str_contains($t, '>Saved<') && str_contains($t, 'تم الحفظ'), 'text marked translate="no" stays as written (codes, English originals on the wording page)');

section('4. Arabic course content and the Arabic wording page');
$ar = as_user($app, 'f.omar', 'ar');
[$code, $html] = http($ar, "$app/workspace.php?id=$o401");
ok($code === 200 && str_contains($html, 'ضمان جودة البرمجيات') && str_contains($html, 'وصف نماذج جودة البرمجيات'), 'the Arabic workspace shows the course title and its learning outcomes in Arabic');
[, $html] = http($omar, "$app/workspace.php?id=$o412&tab=structure");
$clo = Db::one('SELECT c.id, c.statement FROM clos c JOIN spec_versions sv ON sv.id = c.spec_version_id WHERE sv.course_id = (SELECT course_id FROM course_offerings WHERE id = ?) AND sv.status = "draft" ORDER BY c.id LIMIT 1', [$o412]);
[$code, $body] = http($omar, "$app/api.php", ['action' => 'save_clo', 'offering' => $o412, 'clo' => $clo['id'], 'statement' => $clo['statement'], 'domain' => 'Knowledge and Understanding', 'statement_ar' => 'صياغة عربية تجريبية للمخرج'], ['X-CSRF-Token: ' . csrf_of($html)]);
Translations::flush();
ok($code === 200 && Translations::get($clo['statement']) === 'صياغة عربية تجريبية للمخرج' && Db::val('SELECT 1 FROM audit_log WHERE action = "translation.saved" AND object_type = "clo"'), 'an instructor can type the Arabic wording when writing an outcome; it is saved and recorded');
$results = Search::run(person('f.omar'), 'ضمان جودة');
ok((bool) array_filter($results, static fn($r) => str_contains($r['title'], 'SWE 401')), 'search finds a course by its Arabic name');
[$code, $html] = http($qa, "$app/translations.php?kind=content");
ok($code === 200 && str_contains($html, 'Still in English') && str_contains($html, 'Many at once'), 'Quality sees the Arabic wording page with what is still in English');
$missing = Translations::missing('content', 5);
if ($missing) {
    [$code] = http($qa, "$app/translations.php?kind=content", ['_csrf' => csrf_of($html), 'op' => 'save', 'en[0]' => $missing[0]['english'], 'ar[0]' => 'نص عربي من صفحة الصياغة']);
    Translations::flush();
    ok($code === 302 && Translations::get($missing[0]['english']) === 'نص عربي من صفحة الصياغة', 'Quality can type the Arabic for text still in English');
} else {
    ok(true, 'all course content already has Arabic wording');
}
$csv = Translations::csv('content', true);
ok(str_starts_with($csv, "\xEF\xBB\xBFenglish,arabic,kind"), 'the spreadsheet of missing wording opens in Excel (UTF-8 with BOM)');
$file = tempdir('saqf-ar') . '/ar.csv';
write_csv($file, [['english', 'arabic', 'kind'], ['Mobile app project', 'مشروع تطبيق جوال', 'content'], ['Final exam', 'no arabic here', 'content'], ['Software Engineering', 'هندسة البرمجيات', 'catalogue']]);
$r = Translations::importCsv($file, $qaUser['id']);
ok($r['saved'] >= 1 && count($r['errors']) === 1 && Translations::get('Mobile app project') === 'مشروع تطبيق جوال', 'a filled-in spreadsheet imports; a row without Arabic is reported, not saved');
$r = Translations::importCsv($file, $qaUser['id'], ['catalogue', 'people']);
ok(count(array_filter($r['errors'], static fn($e) => str_contains($e, 'cannot change'))) === 1, 'IT may word names, not course content');
[$code] = http($omar, "$app/translations.php");
ok($code === 403, 'instructors cannot open the Arabic wording page');
$it = as_user($app, 'it.admin');
[$code, $html] = http($it, "$app/translations.php?kind=content");
ok($code === 200 && !str_contains($html, 'Learning outcomes, assessments, topics'), 'IT sees catalogue and people wording only');
$doc = (string) NcaaaExport::courseReport($o401, 'ar')->bytes();
$xml = (string) unzip_entry($doc, 'word/document.xml');
ok(str_starts_with($doc, "PK\x03\x04") && str_contains($xml, 'ضمان جودة البرمجيات') && str_contains($xml, 'وصف نماذج جودة البرمجيات'), 'the Arabic Word report uses the Arabic course title and learning outcomes');

finish();

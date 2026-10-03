<?php
declare(strict_types=1);

/**
 * Automation scenario tests (service level). Run against a FRESH demo database:
 *   php bin/install.php --demo --fresh && php tests/automation_test.php
 * The tests mutate data (they replay the live demo and beyond) — reinstall afterwards.
 */

require __DIR__ . '/../src/bootstrap.php';

use Saqf\Core\Audit;
use Saqf\Core\Db;
use Saqf\Core\Ledger;
use Saqf\Core\Policy;
use Saqf\Integration\Integrations;
use Saqf\Integration\Sync;
use Saqf\Quality\Achievement;
use Saqf\Quality\Engine;
use Saqf\Quality\Findings;
use Saqf\Quality\Impact;
use Saqf\Quality\Improvements;
use Saqf\Quality\Intelligence;
use Saqf\Quality\Overrides;
use Saqf\Quality\Specs;
use Saqf\Quality\Workspaces;

$pass = 0;
$fail = 0;
function ok($cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ $label\n";
    } else {
        $fail++;
        echo "  ✗ $label\n";
    }
}
function throws(callable $fn, string $label): void
{
    try {
        $fn();
        ok(false, $label . ' (no exception)');
    } catch (InvalidArgumentException | DomainException $e) {
        ok(true, $label . ' — "' . mb_strimwidth($e->getMessage(), 0, 80, '…') . '"');
    }
}
function user(string $username): array
{
    $u = Db::one('SELECT u.*, d.college_id AS dc FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE username = ?', [$username]);
    $u['id'] = (int) $u['id'];
    $u['department_id'] = $u['department_id'] === null ? null : (int) $u['department_id'];
    $u['scope_college_id'] = (int) ($u['college_id'] ?? $u['dc']);
    Audit::actAs('user', $u['id'], $u['full_name'], $u['role']);
    $_SESSION['uid'] = $u['id'];
    return $u;
}
function openRules(int $offeringId): array
{
    return array_column(array_filter(Findings::forOffering($offeringId), static fn($f) => $f['status'] === 'open'), 'rule_code');
}
$offering = static fn(string $code, string $term) => (int) Db::val('SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = ? AND t.code = ?', [$code, $term]);
$course = static fn(string $code) => (int) Db::val('SELECT id FROM courses WHERE code = ?', [$code]);

echo "\n1. Study-plan intelligence (institutional data)\n";
$swe412 = $course('SWE 412');
$progs = array_column(\Saqf\Quality\Catalog::programsFor($swe412), 'code');
ok($progs === ['SWE'], 'SWE 412 belongs only to the SWE plan (elective) → only SWE PLOs are offered');
$mbaCourses = Db::col('SELECT c.code FROM study_plan_entries spe JOIN courses c ON c.id = spe.course_id JOIN programs p ON p.id = spe.program_id WHERE p.code = "MBA"');
ok(!array_filter($mbaCourses, static fn($c) => (int) preg_replace('/\D/', '', $c) < 495), 'MBA plan contains only graduate-level courses (no bachelor courses leak in)');
ok((int) Db::val('SELECT COUNT(*) FROM study_plan_entries spe JOIN programs p ON p.id = spe.program_id JOIN courses c ON c.id = spe.course_id WHERE p.code = "ACC" AND c.code = "MGT 502" AND spe.requirement_group LIKE "Graduate Option%"') === 1, 'MBA course MGT 502 appears in the Accounting plan only as the conditional Graduate Option');
ok((float) Db::val('SELECT credits FROM courses WHERE code = "MIS 316"') === 3.0 && Db::val('SELECT status FROM findings WHERE rule_code = "COURSE_CREDIT_CONFLICT"') === 'open', 'MIS 316 credit conflict in the published plan is detected and routed to QA');
$swe = (int) Db::val('SELECT id FROM programs WHERE code = "SWE"');
$cneSo1 = (int) Db::val('SELECT pl.id FROM plos pl JOIN programs p ON p.id = pl.program_id WHERE p.code = "CNE" AND pl.code = "SO1"');

echo "\n2. Change-based specification workflow + continuous validation (the live demo)\n";
$o412 = $offering('SWE 412', '2026-1');
$omar = user('f.omar');
ok(count(array_intersect(['CLO_VAGUE_VERB', 'CLO_UNMAPPED', 'CLO_NOT_ASSESSED', 'ASSESSMENT_WEIGHT_TOTAL'], openRules($o412))) === 4, 'four deterministic issues open on the SWE 412 draft');
$draft = (int) Specs::inFlight($swe412)['id'];
$r = Specs::submit($draft, $omar);
ok(!$r['ok'] && count($r['blockers']) === 4, 'submission with blockers is refused ("red is never sent")');
$clo4 = (int) Db::val('SELECT id FROM clos WHERE spec_version_id = ? AND code = "CLO4"', [$draft]);
Specs::saveClo($draft, $clo4, ['statement' => 'Analyze security risks in mobile applications and apply appropriate mitigations.', 'domain' => 'Skills', 'target_pct' => '']);
ok(!in_array('CLO_VAGUE_VERB', openRules($o412), true), 'rewording CLO4 with a measurable verb clears CLO_VAGUE_VERB automatically');
$sugg = Db::one('SELECT * FROM recommendations WHERE scope_type = "spec" AND scope_id = ? AND kind = "mapping" AND status = "open"', [$draft]);
ok($sugg !== null, 'an assisted mapping suggestion is offered for the unmapped CLO (' . ($sugg['title'] ?? 'none') . ')');
throws(static fn() => Specs::setMapping($clo4, $cneSo1, true), 'mapping to a PLO of a program that does not contain the course is prevented');
if ($sugg) {
    Intelligence::decide((int) $sugg['id'], $omar, true);
} else {
    Specs::setMapping($clo4, (int) Db::val('SELECT id FROM plos WHERE program_id = ? AND code = "SO1"', [$swe]), true);
}
ok(!in_array('CLO_UNMAPPED', openRules($o412), true), 'accepting the mapping clears CLO_UNMAPPED');
$final = (int) Db::val('SELECT id FROM assessments WHERE spec_version_id = ? AND name = "Final exam"', [$draft]);
Specs::setAssessmentClo($final, $clo4, true);
ok(!in_array('CLO_NOT_ASSESSED', openRules($o412), true), 'linking the final exam clears CLO_NOT_ASSESSED');
$lab = (int) Db::val('SELECT id FROM assessments WHERE spec_version_id = ? AND name = "Lab assignments"', [$draft]);
throws(static fn() => Specs::saveAssessment($draft, $lab, ['name' => 'Lab assignments', 'kind' => 'lab', 'weight_pct' => 140, 'week' => 10]), 'a weight above 100% is rejected at input');
throws(static fn() => Specs::saveAssessment($draft, null, ['name' => 'Final exam', 'kind' => 'final', 'weight_pct' => 5]), 'duplicate assessment names are rejected');
Specs::saveAssessment($draft, $lab, ['name' => 'Lab assignments', 'kind' => 'lab', 'weight_pct' => 20, 'week' => 10]);
ok(!array_intersect(['ASSESSMENT_WEIGHT_TOTAL', 'CLO_VAGUE_VERB', 'CLO_UNMAPPED', 'CLO_NOT_ASSESSED'], openRules($o412)), 'weights back to 100% — all four issues cleared without anyone "running a check"');
$diff = Specs::diff($draft);
ok(count($diff) >= 1 && $diff[0]['academic'], 'reviewers see only the difference from the approved baseline (' . count($diff) . ' change)');
$r = Specs::submit($draft, $omar);
ok($r['ok'] && $r['route'] === 'hod', 'valid revision is routed to the HoD');
$hod = user('hod.ced');
$msg = Specs::hodDecide($draft, $hod, 'approve', '');
ok(Specs::version($draft)['status'] === 'approved' && Specs::version($draft)['decision_route'] === 'auto_green', 'green revision auto-clears after HoD approval (no QA step)');
ok((int) Db::val('SELECT spec_version_id FROM course_offerings WHERE id = ?', [$o412]) === $draft, 'the current offering (no results yet) moved to the new version');
throws(static fn() => Specs::hodDecide($draft, $hod, 'approve', ''), 'a decided revision cannot be decided again');

echo "\n3. Course assignment → workspace initialisation (SIS event)\n";
Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("sis.assignment.0", ?, NOW())', [json_encode(Integrations::sis()->pendingAssignments()[0])]);
$before = Db::val('SELECT COUNT(*) FROM course_offerings');
Sync::assignments('2026-1');
$o413 = $offering('SWE 413', '2026-1');
ok($o413 > 0 && (int) Db::val('SELECT COUNT(*) FROM course_offerings') === (int) $before + 1, 'SIS assignment created the SWE 413 workspace automatically');
ok(in_array('OFFERING_NO_SPEC', openRules($o413), true), 'no approved specification → the one academic task is surfaced');
ok((Ledger::totals($o413)['field_populated'] ?? 0) > 0, 'automation ledger recorded ' . (Ledger::totals($o413)['field_populated'] ?? 0) . ' fields populated from institutional data');
$newInstructor = Db::one('SELECT u.* FROM users u JOIN course_offerings o ON o.instructor_id = u.id WHERE o.id = ?', [$o413]);
ok($newInstructor && $newInstructor['role'] === 'faculty' && $newInstructor['external_id'] === 'YU-F1047', 'a brand-new instructor in the SIS feed got a faculty account automatically (' . ($newInstructor['username'] ?? '?') . ')');
ok((bool) Db::val('SELECT 1 FROM notifications WHERE user_id = ? AND dedupe_key = ?', [(int) ($newInstructor['id'] ?? 0), 'first-spec:' . $o413]), 'instructor notified only because an academic input is needed');
Sync::assignments('2026-1');
ok((int) Db::val('SELECT COUNT(*) FROM course_offerings WHERE course_id = ?', [$course('SWE 413')]) === 1, 're-running the feed is idempotent (no duplicate workspace)');

echo "\n4. Results arrive → achievement recalculated → early warning\n";
$o302 = $offering('SWE 302', '2026-1');
$ref = Integrations::lms()->pending()[0]['ref'] ?? '';
ok(str_contains($ref, 'SWE302'), 'the SWE 302 midterm batch is pending in the LMS');
Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES (?, "1", NOW())', ['lms.released.' . $ref]);
$n = Audit::asSystem(static fn() => Achievement::syncFromLms($o302), 'integration', 'LMS');
ok($n === 1, 'LMS batch imported automatically');
$clo3 = Db::one('SELECT ca.* FROM clo_achievement ca JOIN clos c ON c.id = ca.clo_id WHERE ca.offering_id = ? AND c.code = "CLO3"', [$o302]);
ok($clo3 && abs((float) $clo3['value_pct'] - 60.0) < 0.01 && (int) $clo3['provisional'] === 1, 'SWE 302 CLO3 provisional achievement = 60% (computed, not typed)');
ok(in_array('CLO_EARLY_WARNING', openRules($o302), true), 'early warning raised while the term is still running');
ok(Achievement::syncFromLms($o302) === 0, 'importing the same LMS batch twice is prevented');
throws(static fn() => Achievement::import($o302, ['Pop quiz 9' => ['S1' => 80]], 'upload'), 'results for assessments not in the specification are rejected');

echo "\n5. Target missed → gap → improvement drafted → committed → tracked\n";
user('f.omar');
$scores = [];
foreach (Db::col('SELECT name FROM assessments WHERE spec_version_id = ?', [$draft]) as $name) {
    for ($i = 1; $i <= 12; $i++) {
        $scores[$name]['T' . $i] = $i <= 6 ? 55 : 82;
    }
}
Achievement::import($o412, $scores, 'upload');
ok(in_array('CLO_TARGET_MISSED', openRules($o412), true), 'missed targets detected immediately after results import');
$ia = Db::one('SELECT * FROM improvement_actions WHERE origin_offering_id = ? AND status = "draft" LIMIT 1', [$o412]);
ok($ia && $ia['created_by'] === null && str_contains($ia['evidence_summary'], 'the goal is'), 'improvement record drafted by SAQF with evidence: "' . mb_strimwidth((string) ($ia['evidence_summary'] ?? ''), 0, 70, '…') . '"');
throws(static fn() => Improvements::commit((int) $ia['id'], $omar, 'short', null, '2027-01-15'), 'an empty academic response is not accepted');
$drafts = Db::col('SELECT id FROM improvement_actions WHERE origin_offering_id = ? AND status = "draft"', [$o412]);
foreach ($drafts as $id) {
    Improvements::commit((int) $id, $omar, 'Add guided practice sessions and formative feedback before the summative assessment.', $omar['id'], '2027-01-15');
}
ok(!in_array('IMPROVEMENT_MISSING', openRules($o412), true) && !in_array('CLO_TARGET_MISSED', openRules($o412), true), 'committing actions resolves IMPROVEMENT_MISSING; the gap is now tracked by the actions');

echo "\n6. Improvement effectiveness and recurrence (history)\n";
$first = Db::one('SELECT * FROM improvement_actions WHERE course_id = ? ORDER BY id LIMIT 1', [$course('SWE 401')]);
ok($first['effect'] === 'improved' && (float) $first['followup_pct'] > (float) $first['baseline_pct'], sprintf('SWE 401 action: %.1f%% → %.1f%% evaluated as "%s" (association, not causation)', $first['baseline_pct'], $first['followup_pct'], $first['effect']));
ok((bool) Db::val('SELECT 1 FROM findings WHERE rule_code = "GAP_RECURRING" AND status = "open"'), 'recurring gap (2 consecutive misses) escalated to the HoD');
ok((bool) Db::val('SELECT 1 FROM findings WHERE rule_code = "PLO_PERSISTENT_BELOW" AND owner_role = "dean" AND status = "open"'), 'persistent program-level issue escalated to the Dean');

echo "\n7. PLO change → impact analysis → dependants re-validated\n";
$so2 = (int) Db::val('SELECT id FROM plos WHERE program_id = ? AND code = "SO2"', [$swe]);
$impact = Impact::forPlo($so2);
ok(count($impact['courses']) >= 4 && $impact['mappings'] > 0, 'SO2 impact: ' . count($impact['courses']) . ' courses (' . implode(', ', array_keys($impact['courses'])) . '), ' . $impact['mappings'] . ' mappings, ' . $impact['assessments'] . ' assessments, ' . $impact['open_actions'] . ' open actions');
$eventsBefore = (int) Db::val('SELECT COUNT(*) FROM events WHERE type = "plo.changed"');
\Saqf\Core\Events::emit('plo.changed', ['plo_id' => $so2, 'program_id' => $swe]);
ok(str_contains((string) Db::val('SELECT outcome FROM events WHERE type = "plo.changed" ORDER BY id DESC LIMIT 1'), 'mapping'), 'plo.changed event re-validated the affected specifications');

echo "\n8. Policy exception and safe override\n";
$qa = user('qa.director');
$ov = Db::one('SELECT * FROM overrides WHERE status = "requested" LIMIT 1');
ok($ov !== null, 'CIS 491 exception request is waiting for QA');
throws(static fn() => Overrides::decide((int) $ov['id'], $qa, true, ''), 'an override decision without a reason is refused');
Overrides::decide((int) $ov['id'], $qa, true, 'Capstone milestone structure accepted per graduation project handbook.');
ok(Db::val('SELECT status FROM findings WHERE id = ?', [$ov['finding_id']]) === 'overridden', 'finding marked overridden (not deleted), with who/why recorded');
$vague = Db::val('SELECT id FROM findings WHERE rule_code = "OFFERING_NO_SPEC" AND status = "open" LIMIT 1');
throws(static fn() => Overrides::request((int) $vague, user('f.sara'), 'Please waive this requirement for my course this term.'), 'workflow/validation blockers cannot be waived by request');

echo "\n9. Policy is configuration (audited), not code\n";
user('qa.director');
Policy::set('achievement.method', 'average', 'Test of configurable methodology');
Achievement::compute($o302);
$avg = (float) Db::val('SELECT ca.value_pct FROM clo_achievement ca JOIN clos c ON c.id = ca.clo_id WHERE ca.offering_id = ? AND c.code = "CLO3"', [$o302]);
Policy::set('achievement.method', 'threshold', 'Restore');
Achievement::compute($o302);
ok($avg > 0 && abs($avg - 60.0) > 0.01, sprintf('switching the method to "average" recalculates CLO3 to %.1f%% (mean score)', $avg));
ok((int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action = "policy.changed"') >= 2, 'both policy changes are in the audit log');
throws(static fn() => Policy::set('clo.default_target_pct', '150'), 'invalid policy values are rejected');

echo "\n10. Semester rollover\n";
$spring = (int) Db::val('SELECT id FROM terms WHERE code = "2026-2"');
$stats = Workspaces::activateTerm($spring);
ok(Db::val('SELECT status FROM terms WHERE code = "2026-1"') === 'closed', 'Fall 2026 closed when Spring 2027 was activated');
ok($stats['created'] >= 7 && $stats['inherited'] >= 7, "Spring 2027: {$stats['created']} workspaces created from the SIS, {$stats['inherited']} inherited an approved specification");
ok((int) Db::val('SELECT COUNT(*) FROM snapshots s JOIN course_offerings o ON o.id = s.scope_id JOIN terms t ON t.id = o.term_id WHERE t.code = "2026-1"') > 0, 'Fall 2026 course reports frozen as hashed snapshots');
$spring302 = $offering('SWE 302', '2026-2');
ok((Ledger::totals($spring302)['record_inherited'] ?? 0) > 0 && !Db::val('SELECT 1 FROM assessment_results WHERE offering_id = ?', [$spring302]), 'structure inherited; evidence starts empty for the new term');
$snap = Db::one('SELECT * FROM snapshots ORDER BY id DESC LIMIT 1');
ok(hash('sha256', $snap['payload']) === $snap['sha256'], 'snapshot content matches its stored SHA-256');

echo "\n11. Audit trail integrity\n";
$v = Audit::verify();
ok($v['ok'], $v['message']);
try {
    Db::exec('UPDATE audit_log SET summary = "tampered" WHERE id = 1');
    ok(false, 'UPDATE on audit_log must be blocked');
} catch (Throwable $e) {
    ok(true, 'database refuses UPDATE on the audit log (append-only trigger)');
}
try {
    Db::exec('DELETE FROM audit_log WHERE id = 1');
    ok(false, 'DELETE on audit_log must be blocked');
} catch (Throwable $e) {
    ok(true, 'database refuses DELETE on the audit log');
}
// Forge an entry inside a transaction that is rolled back, so the real chain stays intact.
Db::pdo()->beginTransaction();
$row = Db::one('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
Db::insert('audit_log', ['occurred_at' => date('Y-m-d H:i:s'), 'actor_type' => 'user', 'actor_name' => 'Forger', 'action' => 'x', 'object_type' => 'x', 'summary' => 'forged entry', 'prev_hash' => $row['hash'], 'hash' => str_repeat('a', 64)]);
$v = Audit::verify();
Db::pdo()->rollBack();
ok(!$v['ok'], 'a forged entry inserted behind SAQF\'s back is detected: ' . $v['message']);
ok(Audit::verify()['ok'], 'after rolling the forgery back the chain verifies again');
ok((int) Db::val('SELECT COUNT(*) FROM audit_log WHERE actor_type IN ("system","integration")') > 50, 'automated actions are recorded and attributed to SAQF/integrations');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

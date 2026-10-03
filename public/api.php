<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Quality\Achievement;
use Saqf\Quality\Engine;
use Saqf\Quality\Findings;
use Saqf\Quality\Improvements;
use Saqf\Quality\Intelligence;
use Saqf\Quality\Overrides;
use Saqf\Quality\Specs;
use Saqf\Security\Auth;
use Saqf\Security\Authz;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(\Saqf\Web\I18n::json($data), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out(['ok' => false, 'error' => 'POST required'], 405);
}
$user = Auth::user();
if (!$user) {
    out(['ok' => false, 'error' => 'Your session has expired. Sign in again.'], 401);
}
if (!Csrf::valid()) {
    out(['ok' => false, 'error' => 'Security token expired. Reload the page.'], 403);
}
if (saqf_maintenance() && $user['role'] !== 'admin') {
    out(['ok' => false, 'error' => 'SAQF is in maintenance mode.'], 503);
}

$action = (string) ($_POST['action'] ?? '');
$in = static fn(string $k, string $d = '') => is_string($_POST[$k] ?? null) ? trim((string) $_POST[$k]) : $d;
$int = static fn(string $k) => (int) ($_POST[$k] ?? 0);

/** Resolves the editable draft of an offering's course; translates ids from the approved version by lineage. */
$draftContext = static function () use ($user, $int): array {
    $o = Authz::offering($user, $int('offering'), 'edit');
    $draftId = Specs::ensureDraft((int) $o['course_id']);
    return [$o, $draftId];
};
$inDraft = static function (string $table, int $id, int $draftId): int {
    $row = Db::one("SELECT id, spec_version_id, lineage_key FROM `$table` WHERE id = ?", [$id]);
    if (!$row) {
        throw new InvalidArgumentException('Item not found.');
    }
    if ((int) $row['spec_version_id'] === $draftId) {
        return (int) $row['id'];
    }
    $mapped = Db::val("SELECT id FROM `$table` WHERE spec_version_id = ? AND lineage_key = ?", [$draftId, $row['lineage_key']]);
    if (!$mapped) {
        throw new InvalidArgumentException('That item no longer exists in the current draft.');
    }
    return (int) $mapped;
};
$openTitles = static function (?int $offeringId): array {
    if (!$offeringId) {
        return [];
    }
    $out = [];
    foreach (Findings::forOffering($offeringId) as $f) {
        if ($f['status'] === 'open' && $f['severity'] !== 'info') {
            $out[$f['fingerprint']] = $f['title'];
        }
    }
    return $out;
};

$offeringId = $int('offering') ?: null;
$before = $offeringId ? $openTitles($offeringId) : [];
$message = 'Saved.';
$redirect = null;

try {
    switch ($action) {
        // ------------------------------------------------ specification editing (faculty)
        case 'save_clo':
            [$o, $draft] = $draftContext();
            $cloId = $int('clo') ? $inDraft('clos', $int('clo'), $draft) : null;
            $savedId = Specs::saveClo($draft, $cloId, ['statement' => $in('statement'), 'domain' => $in('domain'), 'target_pct' => $in('target_pct'), 'skills' => $_POST['skills'] ?? []]);
            $message = $cloId ? 'Outcome updated.' : 'Outcome added.';
            if (isset($_POST['statement_ar'])) {
                // Optional Arabic wording of the outcome (Arabic interface and Arabic Word documents).
                $statement = (string) Db::val('SELECT statement FROM clos WHERE id = ?', [(int) ($savedId ?: $cloId)]);
                if ($statement !== '' && \Saqf\Core\Translations::set($statement, $in('statement_ar'), 'content', $user['id'])) {
                    Audit::record('translation.saved', 'clo', (int) ($savedId ?: $cloId), 'Arabic wording of an outcome saved', null, ['english' => $statement, 'arabic' => $in('statement_ar')]);
                }
            }
            break;
        case 'delete_clo':
            [$o, $draft] = $draftContext();
            Specs::deleteClo($draft, $inDraft('clos', $int('clo'), $draft));
            $message = 'Outcome removed from the draft.';
            break;
        case 'map':
            [$o, $draft] = $draftContext();
            Specs::setMapping($inDraft('clos', $int('clo'), $draft), $int('plo'), $in('on') === '1');
            $message = $in('on') === '1' ? 'Mapping added.' : 'Mapping removed.';
            break;
        case 'save_assessment':
            [$o, $draft] = $draftContext();
            $aid = $int('assessment') ? $inDraft('assessments', $int('assessment'), $draft) : null;
            Specs::saveAssessment($draft, $aid, ['name' => $in('name'), 'kind' => $in('kind'), 'weight_pct' => $in('weight_pct'), 'week' => $in('week')]);
            $message = $aid ? 'Assessment updated.' : 'Assessment added.';
            break;
        case 'save_weights':
            [$o, $draft] = $draftContext();
            foreach ((array) ($_POST['w'] ?? []) as $pair) {
                [$id, $w] = array_pad(explode(':', (string) $pair, 2), 2, '');
                $aid = $inDraft('assessments', (int) $id, $draft);
                $a = Db::one('SELECT * FROM assessments WHERE id = ?', [$aid]);
                if ((float) $a['weight_pct'] !== (float) $w) {
                    Specs::saveAssessment($draft, $aid, ['name' => $a['name'], 'kind' => $a['kind'], 'weight_pct' => $w, 'week' => (string) $a['week']]);
                }
            }
            $message = 'Weights saved.';
            break;
        case 'delete_assessment':
            [$o, $draft] = $draftContext();
            Specs::deleteAssessment($draft, $inDraft('assessments', $int('assessment'), $draft));
            $message = 'Assessment removed from the draft.';
            break;
        case 'link':
            [$o, $draft] = $draftContext();
            Specs::setAssessmentClo($inDraft('assessments', $int('assessment'), $draft), $inDraft('clos', $int('clo'), $draft), $in('on') === '1');
            $message = $in('on') === '1' ? 'Assessment now measures this outcome.' : 'Link removed.';
            break;
        case 'save_spec_text':
            [$o, $draft] = $draftContext();
            Specs::saveNarrative($draft, $in('objectives'), $in('strategies'));
            $message = 'Specification text saved.';
            break;
        case 'save_topics':
            [$o, $draft] = $draftContext();
            $topics = [];
            foreach (preg_split('/\r?\n/', $in('topics')) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $hours = null;
                if (preg_match('/\|\s*([\d.]+)\s*$/', $line, $m)) {
                    $hours = $m[1];
                    $line = preg_replace('/\|\s*[\d.]+\s*$/', '', $line);
                }
                $topics[] = ['topic' => trim($line), 'hours' => $hours];
            }
            Specs::saveTopics($draft, $topics);
            $message = 'Course content saved.';
            break;
        case 'save_resources':
            [$o, $draft] = $draftContext();
            $res = [];
            foreach (array_keys(Specs::RESOURCE_CATEGORIES) as $cat) {
                foreach (preg_split('/\r?\n/', $in('res_' . $cat)) as $line) {
                    if (trim($line) !== '') {
                        $res[] = ['category' => $cat, 'text' => trim($line)];
                    }
                }
            }
            Specs::saveResources($draft, $res);
            $message = 'Learning resources saved.';
            break;
        case 'submit_spec':
            $o = Authz::offering($user, $int('offering'), 'edit');
            $v = Specs::inFlight((int) $o['course_id']);
            if (!$v || $v['status'] !== 'draft') {
                throw new DomainException('There is no draft revision to submit.');
            }
            $r = Specs::submit((int) $v['id'], $user);
            if (!$r['ok']) {
                out(['ok' => false, 'error' => $r['message'] . ' ' . implode(' · ', array_column($r['blockers'], 'title'))]);
            }
            $message = $r['message'];
            break;
        case 'discard_draft':
            $o = Authz::offering($user, $int('offering'), 'edit');
            $v = Specs::inFlight((int) $o['course_id']);
            if (!$v || $v['status'] !== 'draft') {
                throw new DomainException('There is no draft to discard.');
            }
            Specs::discardDraft((int) $v['id']);
            $message = 'Draft discarded; the approved specification remains in effect.';
            break;
        case 'suggestion':
            $o = Authz::offering($user, $int('offering'), 'edit');
            $rec = Db::one('SELECT r.* FROM recommendations r JOIN spec_versions sv ON sv.id = r.scope_id WHERE r.id = ? AND r.scope_type = "spec" AND sv.course_id = ?', [$int('id'), $o['course_id']]);
            if (!$rec) {
                throw new InvalidArgumentException('Suggestion not found for this course.');
            }
            $message = Intelligence::decide((int) $rec['id'], $user, $in('accept') === '1');
            break;
        case 'insight':
            $rec = Db::one('SELECT * FROM recommendations WHERE id = ?', [$int('id')]);
            if (!$rec || $rec['scope_type'] !== 'offering') {
                throw new InvalidArgumentException('Insight not found.');
            }
            $off = Authz::offering($user, (int) $rec['scope_id']);
            if ($user['role'] === 'faculty' && !Authz::isCoordinator($user, $off)) {
                Authz::deny('insight');
            }
            $message = Intelligence::decide((int) $rec['id'], $user, $in('accept') === '1');
            break;

        // ------------------------------------------------ results & report (faculty)
        case 'lms_sync':
            $o = Authz::offering($user, $int('offering'));
            if (!Authz::canContribute($user, $o)) {
                Authz::deny('LMS check');
            }
            $n = Achievement::syncFromLms((int) $o['id']);
            $message = $n ? "$n new result batch(es) imported from the LMS; achievement recalculated." : 'The LMS has no new published results for this course yet.';
            break;
        case 'narrative':
            $o = Authz::offering($user, $int('offering'), 'edit');
            $text = mb_substr($in('content'), 0, 6000);
            $key = in_array($in('section'), ['interpretation', 'difficulties', 'recommendations'], true) ? $in('section') : 'interpretation';
            Db::exec('INSERT INTO offering_narratives (offering_id, section_key, content, updated_by, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)', [$o['id'], $key, $text, $user['id'], Clock::stamp()]);
            Audit::record('report.' . $key, 'offering', $o['id'], 'Course report ' . $key . ' updated');
            Engine::evaluateOffering((int) $o['id']);
            $message = 'Saved to the course report.';
            break;

        // ------------------------------------------------ improvement loop
        case 'improvement_commit':
            $ia = Improvements::find($int('id'));
            if (!$ia) {
                throw new InvalidArgumentException('Improvement action not found.');
            }
            $o = Authz::offering($user, (int) $ia['origin_offering_id']);
            if (!($user['role'] === 'faculty' && ((int) $o['instructor_id'] === $user['id'] || (int) $ia['owner_id'] === $user['id'])) && !Authz::canDecideCourse($user, (int) $ia['course_id'])) {
                Authz::deny('improvement action');
            }
            Improvements::commit((int) $ia['id'], $user, $in('action_text'), $int('owner') ?: null, $in('due_on'));
            $offeringId = (int) $ia['origin_offering_id'];
            $message = 'Plan saved. Next term SAQF compares the results with today\'s ' . \Saqf\Quality\Rules::whole((float) $ia['baseline_pct']) . '%.';
            break;
        case 'improvement_status':
            $ia = Improvements::find($int('id'));
            if (!$ia) {
                throw new InvalidArgumentException('Improvement action not found.');
            }
            if (!((int) $ia['owner_id'] === $user['id'] || Authz::canDecideCourse($user, (int) $ia['course_id']))) {
                Authz::deny('improvement status');
            }
            Improvements::setStatus((int) $ia['id'], $user, $in('status'), $in('note'));
            $message = 'Status updated.';
            break;

        // ------------------------------------------------ exceptions & overrides
        case 'override_request':
            $f = Db::one('SELECT * FROM findings WHERE id = ?', [$int('finding')]);
            if (!$f || !$f['course_id']) {
                throw new InvalidArgumentException('Finding not found.');
            }
            $teaches = Db::val('SELECT 1 FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.course_id = ? AND o.instructor_id = ? AND t.status <> "closed"', [$f['course_id'], $user['id']]);
            if ($user['role'] !== 'faculty' || !$teaches) {
                Authz::deny('exception request');
            }
            Overrides::request((int) $f['id'], $user, $in('justification'));
            $message = 'Exception request sent to Quality Assurance with your justification.';
            break;
        case 'override_decide':
            if ($user['role'] !== 'qa') {
                Authz::deny('override decision');
            }
            Overrides::decide($int('id'), $user, $in('decision') === 'approve', $in('note'));
            $message = 'Decision recorded and the requester notified.';
            break;
        case 'qa_override':
            if ($user['role'] !== 'qa') {
                Authz::deny('override');
            }
            Overrides::direct($int('finding'), $user, $in('reason'));
            $message = 'Rule set aside with your recorded reason.';
            break;
        case 'finding_resolve':
            $f = Db::one('SELECT * FROM findings WHERE id = ?', [$int('finding')]);
            if (!$f) {
                throw new InvalidArgumentException('Finding not found.');
            }
            $allowed = $user['role'] === 'qa'
                || ($user['role'] === 'hod' && $f['owner_role'] === 'hod' && (int) $f['department_id'] === $user['department_id'])
                || ($user['role'] === 'dean' && $f['owner_role'] === 'dean' && (int) $f['college_id'] === $user['scope_college_id']);
            if (!$allowed) {
                Authz::deny('resolving finding');
            }
            Overrides::resolve((int) $f['id'], $user, $in('note'));
            $message = 'Marked resolved. If the underlying data is still wrong, SAQF will reopen it automatically.';
            break;

        // ------------------------------------------------ approvals
        case 'hod_decide':
            $v = Specs::version($int('version'));
            if (!$v || !Authz::canDecideCourse($user, (int) $v['course_id'])) {
                Authz::deny('specification decision');
            }
            $message = Specs::hodDecide((int) $v['id'], $user, $in('decision') === 'return' ? 'return' : 'approve', $in('note'));
            $redirect = 'approvals.php';
            break;
        case 'qa_decide':
            if ($user['role'] !== 'qa') {
                Authz::deny('QA decision');
            }
            $message = Specs::qaDecide($int('version'), $user, $in('decision') === 'return' ? 'return' : 'approve', $in('note'));
            $redirect = 'approvals.php';
            break;
        case 'sample_reviewed':
            if ($user['role'] !== 'qa') {
                Authz::deny('QA sampling');
            }
            $v = Specs::version($int('version'));
            if (!$v || !$v['qa_sampled']) {
                throw new InvalidArgumentException('Not in the QA sample.');
            }
            Audit::record('qa.sample_reviewed', 'spec_version', $v['id'], 'QA spot-check of an auto-cleared specification: ' . ($in('result') === 'concern' ? 'concern raised' : 'no concerns'), null, null, $in('note') ?: null);
            $message = 'Spot-check recorded.';
            break;

        // ------------------------------------------------ program PLOs (HoD) with impact analysis
        case 'plo_save':
            $p = Authz::program($user, $int('program'));
            if (!Authz::canManageProgram($user, $p)) {
                Authz::deny('PLO management');
            }
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $in('code')));
            $statement = $in('statement');
            $domain = $in('domain');
            if ($code === '' || mb_strlen($statement) < 10 || !in_array($domain, Specs::DOMAINS, true)) {
                throw new InvalidArgumentException('Give the PLO a code, a statement and a learning domain.');
            }
            $old = Db::one('SELECT * FROM plos WHERE program_id = ? AND code = ?', [$p['id'], $code]);
            if ($old) {
                if (mb_strlen($in('reason')) < 5) {
                    throw new InvalidArgumentException('Changing an approved PLO needs a recorded reason (it affects every mapped course).');
                }
                Db::update('plos', ['statement' => $statement, 'domain' => $domain, 'version' => (int) $old['version'] + 1, 'status' => 'approved', 'source' => 'local', 'updated_at' => Clock::stamp()], 'id = ?', [$old['id']]);
                Audit::record('plo.changed', 'plo', $old['id'], "{$p['code']} $code updated", ['statement' => $old['statement'], 'domain' => $old['domain']], ['statement' => $statement, 'domain' => $domain], $in('reason'));
                \Saqf\Core\Events::emit('plo.changed', ['plo_id' => (int) $old['id'], 'program_id' => (int) $p['id']]);
                $message = "$code updated; dependent mappings re-validated.";
            } else {
                $id = Db::insert('plos', ['program_id' => $p['id'], 'code' => $code, 'domain' => $domain, 'statement' => $statement, 'status' => 'approved', 'source' => 'local', 'updated_at' => Clock::stamp()]);
                Audit::record('plo.created', 'plo', $id, "{$p['code']} $code added", null, ['statement' => $statement, 'domain' => $domain], $in('reason') ?: null);
                Engine::evaluateProgram((int) $p['id']);
                foreach (Db::col('SELECT DISTINCT sv.id FROM spec_versions sv WHERE sv.status IN ("approved","draft") AND sv.course_id IN (SELECT course_id FROM study_plan_entries WHERE program_id = ? AND course_id IS NOT NULL)', [$p['id']]) as $vid) {
                    Engine::evaluateSpec((int) $vid);
                }
                $message = "$code added; courses in the plan were re-validated against the new PLO.";
            }
            break;
        case 'plo_retire':
            $p = Authz::program($user, $int('program'));
            if (!Authz::canManageProgram($user, $p)) {
                Authz::deny('PLO management');
            }
            $plo = Db::one('SELECT * FROM plos WHERE id = ? AND program_id = ?', [$int('plo'), $p['id']]);
            if (!$plo || mb_strlen($in('reason')) < 5) {
                throw new InvalidArgumentException('Give a reason for retiring the PLO.');
            }
            Db::update('plos', ['status' => 'retired', 'updated_at' => Clock::stamp()], 'id = ?', [$plo['id']]);
            Audit::record('plo.retired', 'plo', $plo['id'], "{$p['code']} {$plo['code']} retired", null, null, $in('reason'));
            \Saqf\Core\Events::emit('plo.changed', ['plo_id' => (int) $plo['id'], 'program_id' => (int) $p['id']]);
            $message = 'PLO retired; affected specifications re-validated.';
            break;
        case 'program_narrative':
            $p = Authz::program($user, $int('program'));
            if (!Authz::canManageProgram($user, $p)) {
                Authz::deny('program narrative');
            }
            $key = in_array($in('section'), ['mission', 'goals'], true) ? $in('section') : 'mission';
            Db::exec('INSERT INTO program_narratives (program_id, section_key, content, updated_by, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)', [$p['id'], $key, mb_substr($in('content'), 0, 4000), $user['id'], Clock::stamp()]);
            Audit::record('program.' . $key, 'program', $p['id'], "{$p['code']} $key updated");
            break;

        // ------------------------------------------------ policies (QA only; audited)
        case 'policy_set':
            if ($user['role'] !== 'qa') {
                Authz::deny('quality policy');
            }
            $pairs = (array) ($_POST['p'] ?? []);
            if ($in('key') !== '') {
                $pairs[] = $in('key') . '=' . (string) ($_POST['v'] ?? '');
            }
            foreach ($pairs as $pair) {
                [$key, $val] = array_pad(explode('=', (string) $pair, 2), 2, '');
                Policy::set($key, $val, $in('reason') ?: null);
            }
            $message = 'Policy saved. Every change is in the audit log.';
            break;

        // ------------------------------------------------ notifications
        case 'notifications_read':
            Db::exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Clock::stamp(), $user['id']]);
            $message = 'All caught up.';
            break;

        default:
            out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (InvalidArgumentException | DomainException $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 422);
}

$after = $offeringId ? $openTitles($offeringId) : [];
out([
    'ok' => true,
    'message' => $message,
    'cleared' => array_values(array_diff_key($before, $after)),
    'opened' => array_values(array_diff_key($after, $before)),
    'redirect' => $redirect,
]);

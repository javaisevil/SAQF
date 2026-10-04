<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Mailer;
use Saqf\Core\Migrations;
use Saqf\Core\Policy;
use Saqf\Core\Request;
use Saqf\Core\Session;
use Saqf\Integration\DataPack;
use Saqf\Integration\FileSisSource;
use Saqf\Integration\GoLive;
use Saqf\Integration\Integrations;
use Saqf\Integration\SeededSisSource;
use Saqf\Integration\Sync;
use Saqf\Quality\Achievement;
use Saqf\Quality\Scheduler;
use Saqf\Quality\Workspaces;
use Saqf\Core\Alerts;
use Saqf\Quality\Evidence;
use Saqf\Security\AccessReview;
use Saqf\Security\Auth;
use Saqf\Security\Mfa;
use Saqf\Security\Oidc;
use Saqf\Security\SecurityCenter;
use Saqf\Security\SelfTest;
use Saqf\Security\Sessions;
use Saqf\Security\Users;
use Saqf\Security\Witness;
use Saqf\Web\View as V;

$user = saqf_page(['admin']);
$tab = in_array($_GET['tab'] ?? '', ['health', 'center', 'users', 'security', 'audit', 'errors', 'alerts', 'integrations', 'golive', 'review'], true) ? $_GET['tab'] : 'health';

// ---------------------------------------------------------------- CSV export of the audit log
if ($tab === 'audit' && ($_GET['export'] ?? '') === 'csv') {
    Audit::record('audit.exported', 'audit_log', null, 'Audit log exported to CSV by ' . $user['full_name']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="saqf-audit-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'occurred_at', 'actor_type', 'actor_name', 'actor_role', 'action', 'object_type', 'object_id', 'summary', 'reason', 'ip', 'request_id', 'hash']);
    foreach (Db::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 20000') as $r) {
        // Prefix formula-like values so spreadsheet tools never execute them.
        $row = array_map(static fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, [$r['id'], $r['occurred_at'], $r['actor_type'], $r['actor_name'], $r['actor_role'], $r['action'], $r['object_type'], $r['object_id'], $r['summary'], $r['reason'], $r['ip'], $r['request_id'], $r['hash']]);
        fputcsv($out, $row);
    }
    exit;
}

// ---------------------------------------------------------------- user import template
if ($tab === 'users' && ($_GET['template'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="saqf-users-template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, Users::CSV_COLUMNS);
    fputcsv($out, ['n.alsaud', 'Dr. Noura Al-Saud', 'n.alsaud@yu.edu.sa', 'faculty', 'CED', '', 'YU-F2001', 'Assistant Professor']);
    fputcsv($out, ['hod.cis', 'Dr. Khalid Al-Otaibi', 'k.alotaibi@yu.edu.sa', 'hod', 'CIS', '', 'YU-F2002', 'Associate Professor']);
    exit;
}

// ---------------------------------------------------------------- go-live templates (what IT replaces with the university's own files)
if ($tab === 'golive' && isset($_GET['download'])) {
    $name = (string) $_GET['download'];
    $sis = DataPack::sisTemplates();
    $files = [
        'catalogue-pack' => ['saqf-catalogue-template.zip', 'application/zip', static fn() => DataPack::zip(\Saqf\Integration\CatalogFileSource::defaultDir())],
        'sis-terms' => ['terms.csv', 'text/csv; charset=utf-8', static fn() => $sis['terms.csv']],
        'sis-assignments' => ['assignments.csv', 'text/csv; charset=utf-8', static fn() => $sis['assignments.csv']],
        'gradebook' => ['gradebook-template.csv', 'text/csv; charset=utf-8', static fn() => DataPack::gradebookTemplate()],
        'env' => ['saqf-go-live.env.txt', 'text/plain; charset=utf-8', static fn() => GoLive::envSnippet()],
    ];
    if (!isset($files[$name])) {
        saqf_redirect('admin.php?tab=golive');
    }
    [$file, $type, $make] = $files[$name];
    Audit::record('golive.template_downloaded', 'integration', $name, "Go-live template $file downloaded by {$user['full_name']}");
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('X-Content-Type-Options: nosniff');
    echo $make();
    exit;
}

// ---------------------------------------------------------------- actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    saqf_require_post();
    $op = (string) ($_POST['op'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $targetId = (int) ($_POST['user'] ?? 0);
    $target = $targetId ? Db::one('SELECT * FROM users WHERE id = ?', [$targetId]) : null;
    // Changes to people's access need a recent sign-in or a fresh identity confirmation.
    $sensitive = ['create_user', 'edit_user', 'import_users', 'disable', 'enable', 'reset', 'role', 'mfa_reset', 'end_sessions', 'maintenance', 'review_confirm', 'review_remove'];
    try {
        if (in_array($op, $sensitive, true) && !Auth::recentlyVerified()) {
            throw new DomainException('For security, confirm your identity (top of the page) before changing accounts or access.');
        }
        switch ($op) {
            case 'reauth':
                if ($err = Auth::reauthenticate($user, (string) ($_POST['password'] ?? ''), (string) ($_POST['code'] ?? ''))) {
                    throw new DomainException($err);
                }
                Session::flash('success', 'Identity confirmed for the next ' . (int) Policy::get('security.reauth_minutes') . ' minutes.');
                break;
            case 'mfa_reset':
                if (!$target || $targetId === $user['id']) {
                    throw new DomainException('Choose another account (manage your own under Account & security).');
                }
                if (mb_strlen($reason) < 5) {
                    throw new DomainException('Give a reason (e.g. the helpdesk ticket for a lost phone).');
                }
                Mfa::disable($targetId, 'admin', $reason);
                Sessions::endAll($targetId, 'two-step verification reset');
                \Saqf\Security\TrustedDevices::forgetAll($targetId);
                Session::flash('success', "Two-step verification reset for {$target['username']}; they set it up again at their next password sign-in.");
                break;
            case 'end_sessions':
                if (!$target) {
                    throw new DomainException('Unknown account.');
                }
                if (mb_strlen($reason) < 5) {
                    throw new DomainException('Give a reason (recorded in the audit log).');
                }
                $n = Sessions::endAll($targetId, 'signed out by an administrator');
                \Saqf\Security\TrustedDevices::forgetAll($targetId);
                Audit::record('admin.sessions_ended', 'user', $targetId, "All sessions of {$target['username']} ended by an administrator ($n)", null, null, $reason);
                Session::flash('success', "{$target['username']} was signed out everywhere ($n session(s)).");
                break;
            case 'create_user':
            case 'edit_user':
                $data = [];
                foreach (Users::CSV_COLUMNS as $k) {
                    $data[$k] = trim((string) ($_POST[$k] ?? ''));
                }
                if ($op === 'edit_user') {
                    if (!$target) {
                        throw new DomainException('Unknown account.');
                    }
                    $data['username'] = $target['username'];
                    if ($targetId === $user['id'] && $data['role'] !== $user['role']) {
                        throw new DomainException('You cannot change your own role.');
                    }
                } elseif (Db::val('SELECT 1 FROM users WHERE username = ?', [mb_strtolower($data['username'])])) {
                    throw new DomainException('That username already exists — use Manage → Edit details.');
                }
                $r = Users::save($data, 'admin', $reason ?: null);
                Mailer::flush(5);
                Session::flash('success', $op === 'edit_user' ? ($r['changed'] ? 'Account updated.' : 'No changes.')
                    : ('Account created.' . ($r['temp_password'] ? " Temporary password for {$data['username']}: {$r['temp_password']} — share it through a secure channel; it is not shown again." : ($r['invited'] ? ' An invitation to choose a password was e-mailed.' : (Oidc::enabled() ? ' The person signs in with their university account.' : '')))));
                break;
            case 'import_users':
                $f = $_FILES['users'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) {
                    throw new DomainException('Upload a CSV file under 2 MB.');
                }
                if (mb_strlen($reason) < 5) {
                    throw new DomainException('Give a reason for the import (e.g. HR reference).');
                }
                $r = Users::importCsv($f['tmp_name'], $reason);
                Mailer::flush(50);
                $_SESSION['import_result'] = $r;
                Session::flash($r['errors'] ? 'error' : 'success', "User import: {$r['created']} created, {$r['updated']} updated, {$r['unchanged']} unchanged" . ($r['invited'] ? ", {$r['invited']} invitation(s) e-mailed" : '') . ($r['errors'] ? ', ' . count($r['errors']) . ' row(s) rejected — see below.' : '.'));
                break;
            case 'unlock':
                Db::update('users', ['status' => 'active', 'locked_until' => null, 'failed_logins' => 0], 'id = ?', [$targetId]);
                Audit::record('admin.user_unlocked', 'user', $targetId, "Account {$target['username']} unlocked", null, null, $reason ?: null);
                Session::flash('success', 'Account unlocked.');
                break;
            case 'disable':
            case 'enable':
                if ($targetId === $user['id']) {
                    throw new DomainException('You cannot disable your own account.');
                }
                if (mb_strlen($reason) < 5) {
                    throw new DomainException('Give a reason (recorded in the audit log).');
                }
                Db::update('users', ['status' => $op === 'disable' ? 'disabled' : 'active'], 'id = ?', [$targetId]);
                if ($op === 'disable') {
                    Sessions::endAll($targetId, 'account disabled');
                    \Saqf\Security\TrustedDevices::forgetAll($targetId);
                }
                Audit::record('admin.user_' . $op . 'd', 'user', $targetId, "Account {$target['username']} {$op}d", ['status' => $target['status']], ['status' => $op === 'disable' ? 'disabled' : 'active'], $reason);
                Session::flash('success', 'Account ' . $op . 'd.');
                break;
            case 'reset':
                if ($targetId === $user['id']) {
                    throw new DomainException('Use Account & security to change your own password.');
                }
                $temp = Users::tempPassword();
                Db::update('users', ['password_hash' => Auth::hash($temp), 'must_change_password' => 1, 'status' => 'active', 'locked_until' => null, 'failed_logins' => 0], 'id = ?', [$targetId]);
                Sessions::endAll($targetId, 'password reset by an administrator');
                Audit::record('admin.password_reset', 'user', $targetId, "Temporary password issued for {$target['username']} (must change at next sign-in)", null, null, $reason ?: null);
                Session::flash('success', "Temporary password for {$target['username']}: $temp — share it through a secure channel; it is not stored or shown again.");
                break;
            case 'role':
                $role = (string) ($_POST['role'] ?? '');
                if (!isset(Auth::ROLES[$role]) || $targetId === $user['id']) {
                    throw new DomainException('Invalid role change (you cannot change your own role).');
                }
                if (mb_strlen($reason) < 5) {
                    throw new DomainException('Role changes need a reason (e.g. HR reference).');
                }
                Db::update('users', ['role' => $role], 'id = ?', [$targetId]);
                Audit::record('admin.role_changed', 'user', $targetId, "Role of {$target['username']} changed from {$target['role']} to $role", ['role' => $target['role']], ['role' => $role], $reason);
                Session::flash('success', 'Role updated.');
                break;
            case 'maintenance':
                $on = ($_POST['value'] ?? '') === '1' ? '1' : '0';
                if (mb_strlen($reason) < 5) {
                    throw new DomainException('Give a reason for the maintenance window.');
                }
                Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("maintenance_mode", ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$on, Clock::stamp()]);
                Audit::record('admin.maintenance', 'system', null, 'Maintenance mode ' . ($on === '1' ? 'ON' : 'OFF'), null, null, $reason);
                Session::flash('success', 'Maintenance mode ' . ($on === '1' ? 'enabled' : 'disabled') . '.');
                break;
            case 'verify':
                $v = Audit::verify();
                Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("audit.last_verify", ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [json_encode($v + ['at' => Clock::stamp()]), Clock::stamp()]);
                Audit::record('audit.verified', 'audit_log', null, 'Audit chain verification: ' . ($v['ok'] ? 'intact' : 'BROKEN') . " ({$v['checked']} entries)");
                Session::flash($v['ok'] ? 'success' : 'error', $v['message']);
                break;
            case 'sync':
                $s = Sync::institution();
                Session::flash('success', "Institutional data synchronised: {$s['programs']} programs, {$s['courses']} courses, {$s['conflicts']} conflict(s).");
                break;
            case 'tick':
                $s = Scheduler::tick(true);
                Session::flash('success', 'Scheduler ran: ' . (int) ($s['lms_batches'] ?? 0) . ' LMS batch(es) imported, ' . (int) ($s['offerings_checked'] ?? 0) . ' offerings re-checked.');
                break;
            case 'sis_assignments':
                $terms = Sync::terms();
                $term = Db::one('SELECT * FROM terms WHERE status = "active" LIMIT 1');
                $s = $term ? Sync::assignments($term['code']) : ['created' => 0, 'assignments' => 0];
                Session::flash('success', "SIS synchronised: $terms term(s); {$s['assignments']} assignment rows" . ($term ? " for {$term['name']}" : ' (no active term)') . ", {$s['created']} new workspace(s).");
                break;
            case 'check':
                $msgs = [];
                $allOk = true;
                foreach (['SIS' => Integrations::sis(), 'LMS' => Integrations::lms()] as $label => $source) {
                    $r = $source->check();
                    $allOk = $allOk && $r['ok'];
                    $msgs[] = "$label: " . ($r['ok'] ? 'OK — ' : 'PROBLEM — ') . $r['message'];
                }
                Audit::record('integration.checked', 'integration', null, 'Connection test: ' . implode(' | ', $msgs));
                Session::flash($allOk ? 'success' : 'error', implode(' · ', $msgs));
                break;
            case 'witness_take':
                $w = Witness::take($user['full_name']);
                Session::flash($w && $w['sent_to'] !== '' ? 'success' : 'info', $w ? 'Checkpoint recorded' . ($w['sent_to'] !== '' ? ' and sent by ' . $w['sent_to'] . '. Keep that message.' : '. Nothing was sent outside the server (no e-mail or webhook is configured): copy the line below and keep it somewhere IT controls.') : 'There is nothing to witness yet.');
                break;
            case 'witness_verify':
                $v = Witness::verifyLine((string) ($_POST['line'] ?? ''));
                Session::flash($v['ok'] ? 'success' : 'error', $v['message']);
                break;
            case 'selftest':
                $r = SelfTest::runAndStore();
                Audit::record('security.selftest', 'system', null, "Security self-test run by {$user['full_name']}: {$r['passed']} of {$r['total']} passed");
                Session::flash($r['passed'] === $r['total'] ? 'success' : 'error', "Security self-test: {$r['passed']} of {$r['total']} protections proved themselves.");
                break;
            case 'review_confirm':
                $ids = array_map('intval', (array) ($_POST['users'] ?? []));
                if (!$ids) {
                    throw new DomainException('Tick the people whose access you have checked.');
                }
                $n = AccessReview::confirm($ids, $user, $reason ?: null);
                Session::flash('success', $n . ($n === 1 ? ' person\'s' : ' people\'s') . ' access confirmed.');
                break;
            case 'review_remove':
                AccessReview::remove($targetId, $user, $reason);
                Session::flash('success', 'Access removed: the account is disabled and signed out everywhere.');
                break;
            case 'pack_check':
                $dir = ($_POST['which'] ?? '') === 'staging' ? DataPack::stagingDir() : \Saqf\Integration\CatalogFileSource::defaultDir();
                $_SESSION['pack_report'] = ['dir' => str_replace(SAQF_ROOT . '/', '', $dir)] + DataPack::inspect($dir);
                Audit::record('golive.catalogue_checked', 'integration', null, 'Catalogue checked (' . basename($dir) . '): ' . ($_SESSION['pack_report']['ok'] ? 'passed' : count($_SESSION['pack_report']['errors']) . ' problem(s)'));
                break;
            case 'sim_lms':
                if (!Config::demoMode()) {
                    throw new DomainException('The simulator is available in demo mode only.');
                }
                $ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($_POST['ref'] ?? ''));
                Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES (?, "1", ?) ON DUPLICATE KEY UPDATE value = "1"', ['lms.released.' . $ref, Clock::stamp()]);
                Audit::asSystem(static fn() => Audit::record('simulator.lms_published', 'integration', $ref, "Demo simulator: LMS published result batch $ref"), 'integration', 'LMS (simulated)');
                $n = 0;
                foreach (Db::col('SELECT o.id FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE t.status = "active"') as $oid) {
                    $n += Audit::asSystem(static fn() => Achievement::syncFromLms((int) $oid), 'integration', 'LMS integration');
                }
                Session::flash('success', "LMS published the batch; SAQF imported $n batch(es) and recalculated achievement automatically — no one clicked anything in the course.");
                break;
            case 'sim_sis':
                if (!Config::demoMode()) {
                    throw new DomainException('The simulator is available in demo mode only.');
                }
                $i = (int) ($_POST['index'] ?? -1);
                if (!Integrations::sis() instanceof SeededSisSource) {
                    throw new DomainException('The simulator needs the demo SIS feed (SAQF_SIS_SOURCE=demo).');
                }
                $pending = Integrations::sis()->pendingAssignments();
                if (!isset($pending[$i])) {
                    throw new DomainException('Unknown simulated assignment.');
                }
                $a = $pending[$i];
                Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['sis.assignment.' . $i, json_encode($a), Clock::stamp()]);
                $s = Sync::assignments($a['term']);
                Session::flash('success', "SIS published the assignment; {$s['created']} workspace(s) initialised automatically.");
                break;
            case 'term_add':
                $t = FileSisSource::term([
                    'code' => trim((string) ($_POST['code'] ?? '')), 'name' => trim((string) ($_POST['name'] ?? '')), 'academic_year' => trim((string) ($_POST['academic_year'] ?? '')),
                    'sequence' => (int) ($_POST['sequence'] ?? 0), 'starts_on' => (string) ($_POST['starts_on'] ?? ''), 'ends_on' => (string) ($_POST['ends_on'] ?? ''), 'grades_due_on' => (string) ($_POST['grades_due_on'] ?? ''),
                ]);
                if (!preg_match('/^[A-Za-z0-9\-]{2,12}$/', $t['code']) || $t['name'] === '' || $t['academic_year'] === '' || $t['sequence'] < 1) {
                    throw new DomainException('Give the term a code (e.g. 2026-1), a name, an academic year and a sequence number.');
                }
                if ($t['ends_on'] <= $t['starts_on']) {
                    throw new DomainException('The term must end after it starts.');
                }
                if (Db::val('SELECT 1 FROM terms WHERE code = ?', [$t['code']])) {
                    throw new DomainException('A term with that code exists already.');
                }
                $tid = Db::insert('terms', $t + ['status' => 'upcoming']);
                Audit::record('term.created', 'term', $tid, "{$t['name']} ({$t['code']}) added to the academic calendar", null, $t, $reason ?: null);
                Session::flash('success', "{$t['name']} added. It starts automatically on " . V::date($t['starts_on']) . ' (policy permitting), or start it now below.');
                break;
            case 'term_activate':
                $next = Db::one('SELECT * FROM terms WHERE id = ? AND status = "upcoming"', [(int) ($_POST['term'] ?? 0)]);
                if (!$next) {
                    throw new DomainException('Choose an upcoming term.');
                }
                if (mb_strlen($reason) < 5) {
                    throw new DomainException('Starting a term early needs a reason (audited).');
                }
                $s = Workspaces::activateTerm((int) $next['id']);
                Audit::record('term.started_manually', 'term', $next['id'], "{$next['name']} started by an administrator", null, null, $reason);
                Session::flash('success', "{$next['name']} activated: previous term closed and frozen; {$s['created']} workspaces created, {$s['inherited']} inherited an approved specification.");
                break;
            case 'sim_rollover':
                if (!Config::demoMode()) {
                    throw new DomainException('The simulator is available in demo mode only.');
                }
                $next = Db::one('SELECT * FROM terms WHERE status = "upcoming" ORDER BY sequence LIMIT 1');
                if (!$next) {
                    throw new DomainException('No upcoming term to activate.');
                }
                $s = Workspaces::activateTerm((int) $next['id']);
                Session::flash('success', "{$next['name']} activated: previous term closed and frozen; {$s['created']} workspaces created, {$s['inherited']} inherited an approved specification.");
                break;
            default:
                throw new DomainException('Unknown operation.');
        }
    } catch (DomainException | InvalidArgumentException | RuntimeException $e) {
        Session::flash('error', $e->getMessage());
    }
    saqf_redirect('admin.php?tab=' . $tab);
}

$openAlerts = Alerts::open();
$tabs = ['health' => 'System health', 'center' => 'Security center', 'users' => 'Users & access', 'security' => 'Security events', 'audit' => 'Activity log', 'errors' => 'Error log', 'alerts' => 'IT alerts' . ($openAlerts ? ' <span class="count">' . count($openAlerts) . '</span>' : ''), 'integrations' => 'University systems', 'golive' => 'Go-live', 'review' => 'Access review' . (($reviewDue = AccessReview::progress()['due']) ? ' <span class="count">' . $reviewDue . '</span>' : '')];
V::header('System administration', $user, ['subtitle' => 'Keep SAQF running and secure; IT cannot make academic decisions']);
echo V::tabs($tabs, $tab, 'admin.php');
if (!Auth::recentlyVerified() && in_array($tab, ['users', 'health', 'review'], true)): ?>
<section class="card" style="margin-bottom:14px;border-color:#F6DFC3"><div class="card-h"><?= V::icon('lock') ?><h2>Confirm your identity to change accounts or access</h2></div><div class="card-b small">
  <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="op" value="reauth"><input type="password" name="password" placeholder="Your password" required autocomplete="current-password" style="width:220px"><?php if (Mfa::enabled($user)): ?><input type="text" name="code" placeholder="Authenticator code" inputmode="numeric" autocomplete="one-time-code" required style="width:170px"><?php endif; ?><button class="btn btn-sm btn-primary">Confirm</button>
  <span class="muted">Required when your sign-in is older than <?= (int) Policy::get('security.reauth_minutes') ?> minutes.</span></form></div></section>
<?php endif;

if ($tab === 'health'):
    $dbVersion = Db::val('SELECT VERSION()');
    $maint = Db::val('SELECT value FROM system_settings WHERE setting_key = "maintenance_mode"') === '1';
    $lastTick = Db::val('SELECT value FROM system_settings WHERE setting_key = "scheduler.last_tick"');
    $lastSync = Db::one('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1');
    $verify = json_decode((string) Db::val('SELECT value FROM system_settings WHERE setting_key = "audit.last_verify"'), true);
    $errors24 = (int) Db::val('SELECT COUNT(*) FROM system_errors WHERE occurred_at >= ?', [Clock::now()->modify('-1 day')->format('Y-m-d H:i:s')]);
    $failed24 = (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= ?', [Clock::now()->modify('-1 day')->format('Y-m-d H:i:s')]);
    $locked = (int) Db::val('SELECT COUNT(*) FROM users WHERE status = "locked" AND locked_until > ?', [Clock::stamp()]);
    $mailFailing = (int) Db::val('SELECT COUNT(*) FROM mail_outbox WHERE sent_at IS NULL AND attempts > 0');
    try {
        $connectors = 'SIS: ' . Integrations::sisKind() . ' · LMS: ' . Integrations::lmsKind() . ' (Integrations → Test connections)';
        $connectorsOk = Config::env() === 'production' && (Integrations::sisKind() === 'demo' || Integrations::lmsKind() === 'demo') ? false : null;
    } catch (InvalidArgumentException $e) {
        $connectors = 'Configuration error: ' . $e->getMessage();
        $connectorsOk = false;
    }
    $pendingMigrations = Migrations::pending();
    $counts = Db::one('SELECT (SELECT COUNT(*) FROM users) users, (SELECT COUNT(*) FROM courses) courses, (SELECT COUNT(*) FROM course_offerings) offerings, (SELECT COUNT(*) FROM audit_log) audit, (SELECT COUNT(*) FROM events) events');
    $checks = [
        ['Database', $dbVersion ? 'Connected (' . $dbVersion . ')' : 'Unavailable', (bool) $dbVersion],
        ['Environment', Config::env() . (Config::debug() ? ' · debug ON' : ''), !(Config::env() === 'production' && Config::debug())],
        ['HTTPS', Request::isHttps() ? 'Enabled' : 'Not detected (enable TLS in production)', Request::isHttps() ? true : (Config::env() === 'production' ? false : null)],
        ['Demo mode', Config::demoMode() ? 'ON — fictional accounts visible (never in production)' : 'Off', Config::demoMode() ? (Config::env() === 'production' ? false : null) : true],
        ['Clock', Clock::offset() !== 0 ? 'Demo clock anchored to the scenario (' . Clock::now()->format('j M Y H:i') . '; real time ' . date('j M Y H:i') . ')' : 'Real time (' . date('j M Y H:i T') . ')', Clock::offset() !== 0 ? null : true],
        ['Scheduler heartbeat', $lastTick ? 'Last run ' . V::ago($lastTick) : 'Never run', (bool) $lastTick],
        ['Last integration run', $lastSync ? $lastSync['source'] . ' · ' . $lastSync['status'] . ' · ' . V::ago($lastSync['finished_at'] ?? $lastSync['started_at']) : 'None', $lastSync && $lastSync['status'] === 'ok'],
        ['Audit chain', $verify ? ($verify['ok'] ? 'Intact (' . $verify['checked'] . ' entries, verified ' . V::ago($verify['at']) . ')' : 'BROKEN at #' . $verify['broken_at']) : 'Not verified yet — use “Verify audit chain”', $verify ? (bool) $verify['ok'] : null],
        ['Errors (24 h)', (string) $errors24, $errors24 === 0],
        ['Failed sign-ins (24 h) · locked accounts', $failed24 . ' · ' . $locked, $locked === 0],
        ['PHP', PHP_VERSION . ' · SAQF ' . SAQF_VERSION, version_compare(PHP_VERSION, '8.1', '>=')],
        ['Connectors', $connectors, $connectorsOk],
        ['University sign-in (SSO)', Oidc::enabled() ? 'Configured (' . parse_url((string) Config::get('SAQF_OIDC_ISSUER'), PHP_URL_HOST) . ') · password sign-in: ' . Auth::passwordLoginMode() : 'Not configured — password sign-in only', Oidc::enabled() ? true : (Config::env() === 'production' ? false : null)],
        ['E-mail', Mailer::enabled() ? Mailer::transport() . ' · ' . $mailFailing . ' message(s) waiting to retry' . (Mailer::baseUrl() === '' ? ' · SAQF_BASE_URL not set (no links)' : '') : 'Not configured — notifications appear in SAQF only', Mailer::enabled() ? ($mailFailing === 0 && Mailer::baseUrl() !== '') : null],
        ['Database migrations', $pendingMigrations ? count($pendingMigrations) . ' pending — run php bin/migrate.php' : 'Up to date', !$pendingMigrations],
        ['Backups', SecurityCenter::backupLine(), SecurityCenter::backupOk()],
        ['Evidence store', is_dir(Evidence::dir()) && is_writable(Evidence::dir()) ? 'Writable (' . Evidence::dir() . ')' . (Config::get('SAQF_CLAMAV_HOST') ? ' · virus scanner configured' : ' · no virus scanner configured (files are not scanned for malware)') : 'Not writable: ' . Evidence::dir(), is_dir(Evidence::dir()) && is_writable(Evidence::dir())],
        ['IT alerts', $openAlerts ? count($openAlerts) . ' open — see IT alerts' : 'None open' . (Config::get('SAQF_ALERT_WEBHOOK') ? ' · webhook configured' : ''), !$openAlerts],
    ];
?>
<div class="split"><section class="card"><div class="card-h"><h2>Health checks</h2></div><div class="card-b tight"><table><tbody>
<?php foreach ($checks as [$label, $value, $ok]): ?><tr><td style="width:260px"><?= V::h($label) ?></td><td><?= $ok === null ? V::pill('Note', 'grey') : V::pill($ok ? 'OK' : 'Check', $ok ? 'green' : 'amber') ?> <?= V::h($value) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<aside class="stack">
  <section class="card"><div class="card-h"><h2>Maintenance mode</h2><?= $maint ? V::pill('On', 'red') : V::pill('Off', 'green') ?></div><div class="card-b small"><p class="muted">Shows everyone except IT a friendly "back soon" message; no data is touched. Use it for upgrades and restores.</p>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="maintenance"><input type="hidden" name="value" value="<?= $maint ? '0' : '1' ?>"><input type="text" name="reason" placeholder="Reason (audited)" required><button class="btn btn-sm <?= $maint ? '' : 'btn-red' ?>" type="submit" style="margin-top:8px"><?= $maint ? 'Turn off' : 'Turn on' ?></button></form></div></section>
  <section class="card"><div class="card-h"><h2>Activity log check</h2></div><div class="card-b small"><p class="muted">Confirms that no entry in the activity log has been changed or deleted. If one has, it shows exactly which.</p>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="verify"><button class="btn btn-sm" type="submit">Check the activity log</button></form></div></section>
  <section class="card"><div class="card-h"><h2>What SAQF holds</h2></div><div class="card-b small"><?php foreach (['users' => 'People with an account', 'courses' => 'Courses in the catalogue', 'offerings' => 'Course classes (all terms)', 'audit' => 'Entries in the activity log', 'events' => 'Automatic actions taken'] as $k => $label): ?><div class="row between"><span><?= V::h($label) ?></span><strong><?= number_format((int) $counts[$k]) ?></strong></div><?php endforeach; ?>
    <p class="tiny muted" style="margin-top:8px">After restoring a backup, run “Check the activity log”.</p></div></section>
</aside></div>

<?php elseif ($tab === 'users'):
    $q = trim((string) ($_GET['q'] ?? ''));
    $users = Db::all('SELECT u.*, COALESCE(d.name, c.name) AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN colleges c ON c.id = u.college_id' . ($q !== '' ? ' WHERE u.username LIKE ? OR u.full_name LIKE ?' : '') . ' ORDER BY FIELD(u.role,"admin","leadership","dean","qa","hod","faculty"), u.full_name', $q !== '' ? ["%$q%", "%$q%"] : []);
?>
<?php
    $import = $_SESSION['import_result'] ?? null;
    unset($_SESSION['import_result']);
    $depts = Db::all('SELECT code, name FROM departments ORDER BY name');
    $colleges = Db::all('SELECT code, name FROM colleges ORDER BY name');
    $fieldsFor = static function (?array $u) use ($depts, $colleges): string {
        $dept = $u && $u['department_id'] ? (string) Db::val('SELECT code FROM departments WHERE id = ?', [$u['department_id']]) : '';
        $col = $u && $u['college_id'] ? (string) Db::val('SELECT code FROM colleges WHERE id = ?', [$u['college_id']]) : '';
        $h = '<div class="grid g2" style="gap:8px">';
        if (!$u) {
            $h .= '<input type="text" name="username" placeholder="Username (e.g. n.alsaud)" required>';
        }
        $h .= '<input type="text" name="full_name" placeholder="Full name" required value="' . V::h($u['full_name'] ?? '') . '">';
        $h .= '<input type="email" name="email" placeholder="E-mail" value="' . V::h($u['email'] ?? '') . '">';
        $h .= '<input type="text" name="title" placeholder="Title (e.g. Assistant Professor)" value="' . V::h($u['title'] ?? '') . '">';
        $h .= '<input type="text" name="external_id" placeholder="SIS / HR identifier" value="' . V::h($u['external_id'] ?? '') . '">';
        $h .= '<select name="role" required>';
        foreach (Auth::ROLES as $k => $l) {
            $h .= '<option value="' . $k . '"' . (($u['role'] ?? 'faculty') === $k ? ' selected' : '') . '>' . V::h($l) . '</option>';
        }
        $h .= '</select><select name="department"><option value="">Department (faculty, HoD)</option>';
        foreach ($depts as $d) {
            $h .= '<option value="' . V::h($d['code']) . '"' . ($dept === $d['code'] ? ' selected' : '') . '>' . V::h($d['name']) . '</option>';
        }
        $h .= '</select><select name="college"><option value="">College (dean)</option>';
        foreach ($colleges as $c) {
            $h .= '<option value="' . V::h($c['code']) . '"' . ($col === $c['code'] ? ' selected' : '') . '>' . V::h($c['name']) . '</option>';
        }
        return $h . '</select><input type="text" name="reason" placeholder="Reason / ticket (audited)"></div>';
    };
?>
<?php if ($import && ($import['errors'] || $import['credentials'])): ?>
<section class="card" style="border-color:#F6DFC3"><div class="card-h"><h2>Import result</h2><span class="muted small right">Shown once</span></div><div class="card-b small">
  <?php if ($import['errors']): ?><p><strong>Rejected rows</strong></p><ul><?php foreach ($import['errors'] as $e): ?><li><?= V::h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($import['credentials']): ?><p><strong>Temporary passwords</strong> — share each through a secure channel; people must change them at first sign-in. They are not stored or shown again.</p>
  <table><tbody><?php foreach ($import['credentials'] as [$un, $pw]): ?><tr><td class="mono"><?= V::h($un) ?></td><td class="mono"><?= V::h($pw) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div></section>
<?php endif; ?>
<div class="grid g2">
<section class="card"><div class="card-h"><h2>Add a user</h2></div><div class="card-b small">
  <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="create_user"><?= $fieldsFor(null) ?><button class="btn btn-sm btn-primary" style="margin-top:8px">Create account</button></form>
  <p class="tiny muted" style="margin-top:8px"><?= Oidc::enabled() ? 'People sign in with their university account; no password is created.' : (Mailer::enabled() && Mailer::baseUrl() !== '' ? 'People with an e-mail address receive an invitation to choose their password.' : 'A temporary password is shown once after creation.') ?> New instructors in the SIS feed get accounts automatically.</p></div></section>
<section class="card"><div class="card-h"><h2>Import users (CSV)</h2><a class="btn btn-sm right" href="admin.php?tab=users&amp;template=csv">Template</a></div><div class="card-b small">
  <form method="post" enctype="multipart/form-data"><?= Csrf::field() ?><input type="hidden" name="op" value="import_users">
    <input type="file" name="users" accept=".csv,text/csv" required><input type="text" name="reason" placeholder="Reason / HR reference (audited)" required style="margin-top:8px">
    <button class="btn btn-sm" style="margin-top:8px">Import</button></form>
  <p class="tiny muted" style="margin-top:8px">Existing usernames are updated; invalid rows are reported and skipped. Department and college are codes.</p><p class="tiny muted" translate="no">Columns: <span class="mono"><?= V::h(implode(', ', Users::CSV_COLUMNS)) ?></span></p></div></section>
</div>
<section class="card"><div class="card-h"><form class="row" method="get"><input type="hidden" name="tab" value="users"><input type="search" name="q" value="<?= V::h($q) ?>" placeholder="Find user" style="width:260px"><button class="btn btn-sm">Search</button></form><span class="right muted small">Accounts come from SSO, the SIS feed, imports or this page; every change is audited.</span></div>
<div class="card-b tight"><div class="table-wrap"><table><thead><tr><th>User</th><th>Role & scope</th><th>Status</th><th>Last sign-in</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($users as $u): $isLocked = $u['status'] === 'locked' && $u['locked_until'] > Clock::stamp(); ?>
  <tr><td><strong><?= V::h($u['full_name']) ?></strong><div class="tiny muted" translate="no"><?= V::h($u['username']) ?> · <?= V::h($u['external_id'] ?? '') ?><?= $u['email'] ? ' · ' . V::h($u['email']) : '' ?></div><?= ($u['auth_source'] ?? 'local') === 'sso' ? V::pill('SSO', 'blue') : '' ?><?= ($u['provisioned_by'] ?? 'admin') === 'sis' ? ' ' . V::pill('from SIS', 'grey') : '' ?></td>
    <td class="small"><?= V::h(Auth::ROLES[$u['role']] ?? $u['role']) ?><div class="muted"><?= V::h($u['dept'] ?? '') ?></div></td>
    <td><?= $u['status'] === 'disabled' ? V::pill('disabled', 'grey') : ($isLocked ? V::pill('locked until ' . date('H:i', strtotime($u['locked_until'])), 'red') : V::pill('active', 'green')) ?><?= $u['must_change_password'] ? ' ' . V::pill('must change password', 'amber') : '' ?><?= Mfa::enabled($u) ? ' ' . V::pill('2-step', 'green') : (Mfa::required($u) && ($u['auth_source'] ?? 'local') !== 'sso' ? ' ' . V::pill('2-step not set up', 'amber') : '') ?></td>
    <td class="small"><?= V::h(V::ago($u['last_login_at'])) ?><div class="tiny muted mono"><?= V::h($u['last_login_ip'] ?? '') ?></div></td>
    <td><?php if ((int) $u['id'] !== $user['id']): ?><details><summary class="btn btn-sm">Manage</summary><div style="margin-top:8px;min-width:280px">
      <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="user" value="<?= (int) $u['id'] ?>"><input type="text" name="reason" placeholder="Reason / ticket (audited)" style="flex:1;min-width:180px">
        <?php if ($isLocked): ?><button class="btn btn-sm" name="op" value="unlock">Unlock</button><?php endif; ?>
        <button class="btn btn-sm" name="op" value="reset">Reset password</button>
        <button class="btn btn-sm" name="op" value="end_sessions">Sign out everywhere</button>
        <?php if (Mfa::enabled($u)): ?><button class="btn btn-sm" name="op" value="mfa_reset">Reset two-step</button><?php endif; ?>
        <button class="btn btn-sm <?= $u['status'] === 'disabled' ? '' : 'btn-red' ?>" name="op" value="<?= $u['status'] === 'disabled' ? 'enable' : 'disable' ?>"><?= $u['status'] === 'disabled' ? 'Enable' : 'Disable' ?></button>
        <select name="role" style="width:auto"><?php foreach (Auth::ROLES as $k => $l): ?><option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= V::h($l) ?></option><?php endforeach; ?></select><button class="btn btn-sm" name="op" value="role">Change role</button></form>
      <details style="margin-top:8px"><summary class="small">Edit details</summary><form method="post" style="margin-top:6px"><?= Csrf::field() ?><input type="hidden" name="op" value="edit_user"><input type="hidden" name="user" value="<?= (int) $u['id'] ?>"><?= $fieldsFor($u) ?><button class="btn btn-sm" style="margin-top:6px">Save details</button></form></details></div></details><?php else: ?><span class="tiny muted">your account</span><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></div></section>

<?php elseif ($tab === 'security'):
    $failed = Db::all('SELECT * FROM login_attempts WHERE success = 0 ORDER BY id DESC LIMIT 50');
    $ips = Db::all('SELECT ip, COUNT(*) n, MAX(created_at) last FROM login_attempts WHERE success = 0 AND created_at >= ? GROUP BY ip ORDER BY n DESC LIMIT 10', [Clock::now()->modify('-7 days')->format('Y-m-d H:i:s')]);
    $events = Db::all('SELECT * FROM audit_log WHERE action LIKE "security.%" OR action IN ("admin.role_changed","admin.user_disabled","admin.password_reset","auth.password_changed") ORDER BY id DESC LIMIT 60');
?>
<div class="grid g2">
<section class="card"><div class="card-h"><h2>Security events</h2><span class="muted small right">Access denials, lockouts, session anomalies, privileged changes</span></div><div class="card-b"><ul class="timeline"><?php foreach ($events as $e): ?><li class="<?= str_starts_with($e['action'], 'security.') ? 'usr' : 'sys' ?>"><div class="when"><?= V::h(V::date($e['occurred_at'], 'j M Y H:i')) ?> · <?= V::h($e['actor_name']) ?> · <span class="mono"><?= V::h($e['ip']) ?></span></div><div class="small"><strong translate="no"><?= V::h($e['action']) ?></strong> <?= V::h($e['summary']) ?></div><?php if ($e['reason']): ?><div class="tiny muted">“<?= V::h($e['reason']) ?>”</div><?php endif; ?></li><?php endforeach; ?><?= $events ? '' : '<li>No security events.</li>' ?></ul></div></section>
<div class="stack">
<section class="card"><div class="card-h"><h2>Failed sign-ins by IP (7 days)</h2></div><div class="card-b tight"><table><tbody><?php foreach ($ips as $i): ?><tr><td class="mono"><?= V::h($i['ip']) ?></td><td class="num"><?= (int) $i['n'] ?></td><td class="small"><?= V::h(V::ago($i['last'])) ?></td></tr><?php endforeach; ?><?= $ips ? '' : '<tr><td class="muted">None.</td></tr>' ?></tbody></table></div></section>
<section class="card"><div class="card-h"><h2>Recent failed sign-ins</h2></div><div class="card-b tight"><table><tbody><?php foreach ($failed as $f): ?><tr><td class="small"><?= V::h(V::date($f['created_at'], 'j M H:i')) ?></td><td><?= V::h($f['username']) ?></td><td class="mono tiny"><?= V::h($f['ip']) ?></td><td><?= V::pill(str_replace('_', ' ', (string) $f['reason']), 'red') ?></td></tr><?php endforeach; ?><?= $failed ? '' : '<tr><td class="muted">None.</td></tr>' ?></tbody></table></div></section>
<section class="card"><div class="card-h"><h2>Controls in place</h2></div><div class="card-b small"><ul style="margin:0;padding-left:18px">
  <li>Lockout after <?= (int) Policy::get('auth.max_failed_logins') ?> failures for <?= (int) Policy::get('auth.lockout_minutes') ?> min; IP throttle at <?= (int) Policy::get('auth.ip_max_attempts_15min') ?> failures / 15 min</li>
  <li>Sessions: <?= (int) Policy::get('session.idle_minutes') ?> min idle, <?= (int) Policy::get('session.absolute_hours') ?> h absolute, fixation-safe, browser-bound, SameSite=Strict, HttpOnly</li>
  <li>CSRF tokens on every state change; POST-only sign-out</li><li>Server-side, scope-based authorization on every object (attempts are logged)</li>
  <li>Prepared statements only; output escaping by default; strict CSP, no third-party scripts</li><li>Append-only, hash-chained audit log (database triggers + verification)</li></ul></div></section>
</div></div>

<?php elseif ($tab === 'audit'):
    $f = ['action' => trim((string) ($_GET['action'] ?? '')), 'actor' => trim((string) ($_GET['actor'] ?? '')), 'object' => trim((string) ($_GET['object'] ?? '')), 'from' => (string) ($_GET['from'] ?? ''), 'to' => (string) ($_GET['to'] ?? '')];
    $where = ['1=1'];
    $params = [];
    if ($f['action'] !== '') { $where[] = 'action LIKE ?'; $params[] = $f['action'] . '%'; }
    if ($f['actor'] !== '') { $where[] = 'actor_name LIKE ?'; $params[] = '%' . $f['actor'] . '%'; }
    if ($f['object'] !== '') { $where[] = 'object_type = ?'; $params[] = $f['object']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'occurred_at >= ?'; $params[] = $f['from']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 'occurred_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $f['to']; }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $rows = Db::all('SELECT * FROM audit_log WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 100 OFFSET ' . (($page - 1) * 100), $params);
    $objects = Db::col('SELECT DISTINCT object_type FROM audit_log ORDER BY object_type');
?>
<section class="card"><div class="card-h"><form class="row" method="get" style="width:100%"><input type="hidden" name="tab" value="audit">
  <input type="text" name="action" value="<?= V::h($f['action']) ?>" placeholder="Action prefix (e.g. spec.)" style="width:170px"><input type="text" name="actor" value="<?= V::h($f['actor']) ?>" placeholder="Actor" style="width:150px">
  <select name="object" style="width:auto"><option value="">Any object</option><?php foreach ($objects as $o): ?><option translate="no" <?= $f['object'] === $o ? 'selected' : '' ?>><?= V::h($o) ?></option><?php endforeach; ?></select>
  <input type="date" name="from" value="<?= V::h($f['from']) ?>" style="width:150px"><input type="date" name="to" value="<?= V::h($f['to']) ?>" style="width:150px"><button class="btn btn-sm">Filter</button>
  <a class="btn btn-sm right" href="admin.php?tab=audit&export=csv">Export CSV</a></form></div>
  <div class="card-b tight"><div class="table-wrap"><table><thead><tr><th>#</th><th>When</th><th>Actor</th><th>Action</th><th>Summary</th><th>Change</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="mono tiny"><?= (int) $r['id'] ?></td><td class="small nowrap"><?= V::h($r['occurred_at']) ?></td><td class="small"><?= V::h($r['actor_name']) ?><div class="tiny muted" translate="no"><?= V::h($r['actor_type']) ?><?= $r['actor_role'] ? ' · ' . V::h($r['actor_role']) : '' ?></div></td><td class="mono tiny" translate="no"><?= V::h($r['action']) ?><div class="muted"><?= V::h($r['object_type']) ?> <?= V::h($r['object_id']) ?></div></td>
    <td class="small"><?= V::h($r['summary']) ?><?php if ($r['reason']): ?><div class="tiny muted">Reason: <?= V::h($r['reason']) ?></div><?php endif; ?></td>
    <td class="tiny"><?php if ($r['old_value'] || $r['new_value']): ?><details><summary>view</summary><div class="mono" style="white-space:pre-wrap;max-width:360px" translate="no"><?= $r['old_value'] ? 'old: ' . V::h($r['old_value']) . "\n" : '' ?><?= $r['new_value'] ? 'new: ' . V::h($r['new_value']) : '' ?></div></details><?php endif; ?><span class="mono muted" translate="no" title="<?= V::h($r['hash']) ?>"><?= V::h(substr($r['hash'], 0, 8)) ?></span></td></tr><?php endforeach; ?>
  </tbody></table></div><div class="row" style="padding:10px 14px"><?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= V::h(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Newer</a><?php endif; ?><?php if (count($rows) === 100): ?><a class="btn btn-sm" href="?<?= V::h(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Older</a><?php endif; ?></div></div></section>

<section class="card"><div class="card-h"><h2>Witnessed checkpoints</h2><span class="right muted small">Proof that history up to a point is unchanged, kept outside this server</span></div>
  <div class="card-b small">
    <p class="muted">Every night SAQF records the last entry of the activity log (its number, the count and its hash) and sends that line to administrators by e-mail and to the alert webhook, when configured. If someone with database access ever rewrote the log, the copies IT already holds would no longer match. It does not stop tampering; it makes a quiet rewrite detectable, as long as the messages are kept.</p>
    <?php $wl = Witness::recent(5); ?>
    <?php if ($wl): ?><table><tbody><?php foreach ($wl as $w): ?><tr><td class="nowrap tiny"><?= V::h(V::date($w['taken_at'], 'j M H:i')) ?></td><td class="mono tiny" translate="no" style="word-break:break-all"><?= V::h($w['line']) ?></td><td class="tiny"><?= $w['sent_to'] !== '' ? V::pill('sent by ' . $w['sent_to'], 'green') : V::pill('not sent outside the server', 'amber') ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p class="muted">No checkpoint has been taken yet.</p><?php endif; ?>
    <div class="row" style="margin-top:10px;flex-wrap:wrap">
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="witness_take"><button class="btn btn-sm" type="submit">Take a checkpoint now</button></form></div>
    <form method="post" class="row" style="margin-top:10px"><?= Csrf::field() ?><input type="hidden" name="op" value="witness_verify"><label class="sr-only" for="wline">Witness line</label><input id="wline" type="text" name="line" class="mono" placeholder="Paste a witness line from an e-mail or message: SAQF-WITNESS/1 …" style="flex:1;min-width:260px" required><button class="btn btn-sm" type="submit">Verify it against the log</button></form>
  </div></section>

<?php elseif ($tab === 'errors'):
    $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['ref'] ?? '')));
    $rows = Db::all('SELECT e.*, u.username FROM system_errors e LEFT JOIN users u ON u.id = e.user_id' . ($ref ? ' WHERE e.ref = ?' : '') . ' ORDER BY e.id DESC LIMIT 100', $ref ? [$ref] : []);
?>
<section class="card"><div class="card-h"><form class="row" method="get"><input type="hidden" name="tab" value="errors"><input type="text" name="ref" value="<?= V::h($ref) ?>" placeholder="Reference quoted by the user" style="width:240px"><button class="btn btn-sm">Find</button></form><span class="right muted small">Users see only a reference code; details are kept here</span></div>
<div class="card-b tight"><table><tbody><?php foreach ($rows as $e): ?><tr><td class="mono"><?= V::h($e['ref']) ?></td><td class="small nowrap"><?= V::h($e['occurred_at']) ?></td><td class="small"><?= V::pill($e['level'], $e['level'] === 'error' ? 'red' : 'amber') ?> <?= V::h($e['message']) ?><div class="tiny muted mono"><?= V::h($e['location']) ?> · <?= V::h($e['url']) ?> · user <?= V::h($e['username'] ?? '—') ?> · req <?= V::h($e['request_id']) ?></div><details><summary class="tiny">trace</summary><pre class="mono tiny" style="white-space:pre-wrap"><?= V::h($e['trace']) ?></pre></details></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td><?= V::empty('No errors recorded', 'Unhandled errors appear here with a reference code users can quote.') ?></td></tr><?php endif; ?></tbody></table></div></section>

<?php elseif ($tab === 'center'):
    $checks = SecurityCenter::checks();
    $ok = count(array_filter($checks, static fn($c) => $c['ok'] === true));
    $attention = array_filter($checks, static fn($c) => $c['ok'] === false);
?>
<div class="split"><section class="card"><div class="card-h"><?= V::icon('shield') ?><h2>Security controls — live status</h2><span class="right muted small"><?= $ok ?> of <?= count($checks) ?> in place<?= $attention ? ' · ' . count($attention) . ' need attention' : '' ?></span></div><div class="card-b tight"><table><tbody>
<?php foreach ($checks as $c): ?><tr><td style="width:34px"><?= $c['ok'] === true ? V::pill('✓', 'green') : ($c['ok'] === false ? V::pill('!', 'amber') : V::pill('i', 'grey')) ?></td><td><strong><?= V::h($c['label']) ?></strong><div class="small"><?= V::h($c['status']) ?></div><?php if ($c['ok'] !== true && $c['fix']): ?><div class="tiny muted">How to fix: <?= V::h($c['fix']) ?></div><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<aside class="stack">
  <?php $st = SelfTest::last(); ?>
  <section class="card"><div class="card-h"><h2>Security self-test</h2><?= $st ? V::pill($st['passed'] . ' of ' . $st['total'] . ' passed', $st['passed'] === $st['total'] ? 'green' : 'red') : V::pill('not run', 'grey') ?></div><div class="card-b small">
    <p class="muted">An automated self-check: SAQF exercises some of its own protections on this system (weak passwords, forged requests, an edit to the audit log, encryption, pseudonymised student identities). Nothing is changed. Also runs every night.</p>
    <p class="tiny muted"><?= V::h(SelfTest::DISCLAIMER) ?></p>
    <?php if ($st): ?><table><tbody><?php foreach ($st['tests'] as $t): ?><tr><td style="width:30px"><?= $t['ok'] ? V::pill('✓', 'green') : V::pill('!', 'red') ?></td><td><strong class="small"><?= V::h($t['name']) ?></strong><div class="tiny muted"><?= V::h($t['detail']) ?></div></td></tr><?php endforeach; ?></tbody></table><p class="tiny muted">Last run <?= V::h(V::ago($st['at'])) ?>.</p><?php endif; ?>
    <div class="row"><form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="selftest"><button class="btn btn-sm btn-primary">Run security self-test</button></form>
      <a class="btn btn-sm" href="security_report.php" target="_blank" rel="noopener">Security evidence report</a></div></div></section>
  <section class="card"><div class="card-h"><h2>People</h2></div><div class="card-b small"><?php $p = SecurityCenter::people(); ?>
    <div class="row between"><span>Administrators</span><strong><?= (int) $p['admins'] ?></strong></div>
    <div class="row between"><span>… with two-step verification</span><strong><?= (int) $p['admins_mfa'] ?></strong></div>
    <div class="row between"><span>Active sessions now</span><strong><?= (int) $p['sessions'] ?></strong></div>
    <div class="row between"><span>Locked accounts</span><strong><?= (int) $p['locked'] ?></strong></div>
    <div class="row between"><span>Dormant accounts (&gt; <?= (int) Policy::get('auth.dormant_days') ?> days)</span><strong><?= count($p['dormant']) ?></strong></div>
    <?php if ($p['dormant']): ?><p class="tiny muted" style="margin-top:6px">Review: <?= V::h(implode(', ', array_slice($p['dormant'], 0, 8))) ?><?= count($p['dormant']) > 8 ? ' …' : '' ?></p><?php endif; ?></div></section>
  <section class="card"><div class="card-h"><h2>Last 7 days</h2></div><div class="card-b small"><?php foreach (SecurityCenter::week() as $label => $n): ?><div class="row between"><span><?= V::h($label) ?></span><strong><?= (int) $n ?></strong></div><?php endforeach; ?></div></section>
</aside></div>

<?php elseif ($tab === 'golive'):
    $systems = GoLive::systems();
    $prog = GoLive::progress($systems);
    $report = $_SESSION['pack_report'] ?? null;
    unset($_SESSION['pack_report']);
    $stagingPresent = is_file(DataPack::stagingDir() . '/institution.json');
?>
<div class="split"><div class="stack">
  <section class="card"><div class="card-h"><?= V::icon('bolt') ?><h2>Ready to connect to the university</h2><span class="right muted small"><?= (int) $prog['live'] ?> of <?= (int) $prog['total'] ?> configured for the university's own systems · a connection counts as working only after <em>Test connections</em> succeeds</span></div><div class="card-b tight"><table><tbody>
  <?php foreach ($systems as $sy): ?><tr><td style="width:96px"><?= V::pill(['live' => $sy['missing'] ? 'Incomplete' : 'Configured', 'demo' => 'Demo data', 'off' => 'Off'][$sy['mode']], ['live' => $sy['missing'] ? 'red' : 'blue', 'demo' => 'amber', 'off' => 'grey'][$sy['mode']]) ?></td>
    <td><strong><?= V::h($sy['label']) ?></strong> <span class="muted small">· <?= V::h($sy['headline']) ?></span><div class="small"><?= V::h($sy['detail']) ?></div>
      <?php if ($sy['mode'] !== 'live' || $sy['missing']): ?><div class="tiny muted">Next step: <?= V::h($sy['next']) ?></div><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
  <?php $pf = \Saqf\Security\Preflight::run(); $pfs = \Saqf\Security\Preflight::summary($pf); ?>
  <section class="card"><div class="card-h"><h2>Production preflight</h2><span class="right"><?= $pfs['ready'] ? V::pill('No blockers', 'green') : V::pill($pfs['blockers'] . ' blocker' . ($pfs['blockers'] === 1 ? '' : 's'), 'red') ?> <?= $pfs['warnings'] ? V::pill($pfs['warnings'] . ' to review', 'amber') : '' ?></span></div>
    <div class="card-b tight"><p class="small muted" style="padding:10px 14px 0;margin:0">Judged against the production rules, whatever mode this installation runs in. Also available as <span class="mono">php bin/preflight.php</span>. A list of known pre-conditions, not a security approval.</p>
      <table><tbody><?php foreach ($pf as $c): if ($c['pass'] && $c['severity'] === 'info') { continue; } ?><tr><td style="width:84px"><?= $c['pass'] ? V::pill('ok', 'green') : ($c['severity'] === 'blocker' ? V::pill('Blocker', 'red') : V::pill('Review', 'amber')) ?></td><td><strong><?= V::h($c['title']) ?></strong><div class="small"><?= V::h($c['detail']) ?></div><?php if (!$c['pass'] && $c['fix'] !== ''): ?><div class="tiny muted">How to fix: <?= V::h($c['fix']) ?></div><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
  <section class="card"><div class="card-h"><h2>Check the Registrar catalogue</h2><span class="right muted small">Nothing is changed by a check</span></div><div class="card-b small">
    <p>SAQF checks a catalogue completely before using it: structure, owning departments, credit hours, prerequisites and outcomes. A faulty export is refused as a whole, so it can never half-load over good data.</p>
    <p class="muted"><?= $stagingPresent ? 'A Registrar export is waiting in <span class="mono">storage/inbox/catalog</span> and is used automatically.' : 'To replace the bundled YU data, put the Registrar export in <span class="mono">storage/inbox/catalog</span> (same layout as the template below) or set <span class="mono">SAQF_INSTITUTION_DIR</span>.' ?></p>
    <div class="row"><form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="pack_check"><input type="hidden" name="which" value="active"><button class="btn btn-sm btn-primary">Check the catalogue in use</button></form>
      <?php if ($stagingPresent): ?><form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="pack_check"><input type="hidden" name="which" value="staging"><button class="btn btn-sm">Check the waiting export</button></form><?php endif; ?>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="check"><button class="btn btn-sm">Test SIS and LMS connections</button></form></div>
    <?php if ($report): ?><div class="fieldset" style="margin-top:10px"><div class="row"><?= V::pill($report['ok'] ? 'Passed' : 'Not usable yet', $report['ok'] ? 'green' : 'red') ?> <strong><?= V::h($report['dir']) ?></strong></div>
      <?php if ($report['summary']): ?><p class="small"><?= V::h(implode(' · ', array_map(static fn($k, $v) => $v . ' ' . $k, array_keys($report['summary']), $report['summary']))) ?></p><?php endif; ?>
      <?php foreach ($report['errors'] as $e): ?><div class="small"><?= V::pill('Problem', 'red') ?> <?= V::h($e) ?></div><?php endforeach; ?>
      <?php foreach ($report['warnings'] as $w): ?><div class="small"><?= V::pill('Note', 'amber') ?> <?= V::h($w) ?></div><?php endforeach; ?></div><?php endif; ?>
  </div></section>
</div><aside class="stack">
  <section class="card"><div class="card-h"><h2>Templates for IT</h2></div><div class="card-b small">
    <p class="muted">These are today's demo data in exactly the layout SAQF reads. Replace their content with the university's own, and nothing else in SAQF changes.</p>
    <div class="stack" style="gap:6px">
      <a class="btn btn-sm" href="admin.php?tab=golive&amp;download=catalogue-pack">Registrar catalogue (ZIP: programs, study plans, outcomes)</a>
      <a class="btn btn-sm" href="admin.php?tab=golive&amp;download=sis-terms">SIS: terms.csv</a>
      <a class="btn btn-sm" href="admin.php?tab=golive&amp;download=sis-assignments">SIS: assignments.csv</a>
      <a class="btn btn-sm" href="admin.php?tab=golive&amp;download=gradebook">LMS: gradebook export example</a>
      <a class="btn btn-sm" href="admin.php?tab=golive&amp;download=env">Settings file for go-live (.env)</a></div>
    <p class="tiny muted" style="margin-top:8px">Folders: <span class="mono">storage/inbox/catalog</span>, <span class="mono">storage/inbox/sis</span>, <span class="mono">storage/inbox/lms/&lt;term&gt;/&lt;course&gt;/*.csv</span>. Full guide: docs/INTEGRATIONS.md.</p></div></section>
  <section class="card"><div class="card-h"><h2>What changes at go-live</h2></div><div class="card-b small"><ol style="padding-left:18px;margin:0">
    <li>IT provides access: SIS export or API, an LMS read-only token, a sign-in registration, a mail account.</li>
    <li>IT fills in the settings file above; nothing is rebuilt.</li>
    <li>Press <em>Test connections</em>; SAQF reports each system in plain words.</li>
    <li>The scheduler takes over: calendar, assignments, grades and catalogue update themselves.</li>
    <li>Demo accounts, the simulator and the guided tour switch off in production mode.</li></ol></div></section>
</aside></div>

<?php elseif ($tab === 'review'):
    $rows = AccessReview::rows();
    $prog = AccessReview::progress();
    $recent = Db::all('SELECT r.*, u.username, rv.full_name AS reviewer FROM access_reviews r JOIN users u ON u.id = r.user_id LEFT JOIN users rv ON rv.id = r.reviewer_id ORDER BY r.id DESC LIMIT 8');
?>
<div class="split"><section class="card"><div class="card-h"><?= V::icon('shield') ?><h2>Access review</h2><span class="right muted small"><?= (int) $prog['current'] ?> of <?= (int) $prog['total'] ?> confirmed in the last <?= (int) $prog['days'] ?> days</span></div>
<form method="post" class="card-b tight"><?= Csrf::field() ?><input type="hidden" name="op" value="review_confirm">
<table><thead><tr><th style="width:30px"></th><th>Person</th><th>Access</th><th>Last sign-in</th><th>Last confirmed</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): $self = (int) $r['id'] === $user['id']; ?><tr>
  <td><?php if (!$self && $r['due']): ?><input type="checkbox" name="users[]" value="<?= (int) $r['id'] ?>" aria-label="Confirm <?= V::h($r['full_name']) ?>"><?php endif; ?></td>
  <td><strong><?= V::h($r['full_name']) ?></strong><div class="tiny muted mono"><?= V::h($r['username']) ?></div></td>
  <td class="small"><?= V::who($r['role']) ?><?= $r['department_code'] ? ' <span class="muted">' . V::h($r['department_code']) . '</span>' : '' ?><?= $r['role'] === 'admin' && $r['auth_source'] !== 'sso' ? ($r['mfa_enabled_at'] ? ' ' . V::pill('2-step', 'green') : ' ' . V::pill('no 2-step', 'amber')) : '' ?><?= $r['role_changed'] ? '<div class="tiny muted">Role changed since the last review (was ' . V::h($r['reviewed_role']) . ')</div>' : '' ?></td>
  <td class="small nowrap"><?= $r['last_login_at'] ? V::h(V::ago($r['last_login_at'])) : '<span class="muted">never</span>' ?></td>
  <td class="small nowrap"><?= $r['reviewed_at'] ? V::h(V::date($r['reviewed_at'])) . '<div class="tiny muted">by ' . V::h($r['reviewer'] ?? '—') . '</div>' : '<span class="muted">never</span>' ?></td>
  <td class="small"><?= $self ? '<span class="tiny muted">' . ($r['solo'] ? 'You are the only administrator: add a second so your access can be reviewed' : 'Another administrator reviews you') . '</span>' : ($r['due'] ? V::pill('Due', 'amber') : V::pill('Current', 'green')) ?></td></tr><?php endforeach; ?>
</tbody></table>
<div class="row" style="padding:12px 14px"><input type="text" name="reason" placeholder="Note (e.g. checked with HR list of <?= V::h(date('M Y')) ?>)" style="width:320px"><button class="btn btn-sm btn-primary">Confirm access for the ticked people</button></div></form></section>
<aside class="stack">
  <section class="card"><div class="card-h"><h2>Why this exists</h2></div><div class="card-b small">People change jobs; access should not outlive the job. Every <?= (int) $prog['days'] ?> days an administrator other than the person concerned confirms who still needs access and whether the role is right. A role change makes the next review due at once. Nobody can review their own access, and every decision is kept in the activity log.</div></section>
  <section class="card"><div class="card-h"><h2>Remove someone's access</h2></div><div class="card-b small"><form method="post" data-confirm="Disable this account and sign it out everywhere?"><?= Csrf::field() ?><input type="hidden" name="op" value="review_remove">
    <select name="user" required><option value="">Choose a person…</option><?php foreach ($rows as $r): if ((int) $r['id'] === $user['id']) { continue; } ?><option value="<?= (int) $r['id'] ?>"><?= V::h($r['full_name']) ?> (<?= V::h($r['username']) ?>)</option><?php endforeach; ?></select>
    <input type="text" name="reason" placeholder="Reason (audited)" required style="margin-top:6px"><button class="btn btn-sm" style="margin-top:6px">Remove access</button></form></div></section>
  <section class="card"><div class="card-h"><h2>Recent decisions</h2></div><div class="card-b tight"><table><tbody><?php foreach ($recent as $d): ?><tr><td class="small"><?= V::h($d['username']) ?><div class="tiny muted"><?= V::h(V::ago($d['reviewed_at'])) ?> · <?= V::h($d['reviewer'] ?? '—') ?></div></td><td><?= V::pill($d['decision'] === 'confirmed' ? 'Confirmed' : 'Removed', $d['decision'] === 'confirmed' ? 'green' : 'red') ?></td></tr><?php endforeach; ?><?= $recent ? '' : '<tr><td class="muted small">No decisions yet.</td></tr>' ?></tbody></table></div></section>
</aside></div>

<?php elseif ($tab === 'alerts'):
    $recent = Alerts::recent(30);
?>
<section class="card"><div class="card-h"><?= V::icon('bolt') ?><h2>IT alerts</h2><span class="right muted small">Raised automatically; administrators are notified (and e-mailed) when an alert opens and every <?= Alerts::REMIND_HOURS ?> hours while it stays open<?= Config::get('SAQF_ALERT_WEBHOOK') ? ' · also sent to the configured webhook' : '' ?></span></div>
<div class="card-b tight"><table><thead><tr><th>Alert</th><th>Since</th><th class="num">Occurrences</th><th>Status</th></tr></thead><tbody>
<?php foreach ($recent as $a): ?><tr><td><?= V::pill($a['severity'], ['critical' => 'red', 'warning' => 'amber'][$a['severity']] ?? 'grey') ?> <strong><?= V::h($a['title']) ?></strong><div class="tiny muted"><?= V::h($a['detail']) ?></div></td><td class="small nowrap"><?= V::h(V::date($a['first_at'], 'j M H:i')) ?><div class="tiny muted">last <?= V::h(V::ago($a['last_at'])) ?></div></td><td class="num"><?= (int) $a['occurrences'] ?></td><td><?= $a['resolved_at'] ? V::pill('resolved ' . V::ago($a['resolved_at']), 'green') : V::pill('open', 'red') ?></td></tr><?php endforeach; ?>
<?php if (!$recent): ?><tr><td colspan="4"><?= V::empty('No alerts', 'Connector failures, a stalled scheduler, missing backups, a broken audit chain, undeliverable mail and blocked malware raise alerts here automatically.') ?></td></tr><?php endif; ?>
</tbody></table></div></section>

<?php else:
    $runs = Db::all('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 15');
    $pendingLms = Integrations::lms()->pending();
    $pendingSis = Integrations::sis() instanceof SeededSisSource ? Integrations::sis()->pendingAssignments() : [];
    $released = Db::col('SELECT setting_key FROM system_settings WHERE setting_key LIKE "sis.assignment.%"');
    $next = Db::one('SELECT * FROM terms WHERE status = "upcoming" ORDER BY sequence LIMIT 1');
    $events = Db::all('SELECT * FROM events ORDER BY id DESC LIMIT 25');
?>
<div class="split"><div class="stack">
  <section class="card"><div class="card-h"><h2>Connected university systems</h2></div><div class="card-b small">
    <p><?= V::source('institution') ?> <strong>Registrar (programs and study plans):</strong> <?= V::h(Integrations::institution()->label()) ?></p>
    <p><?= V::source('sis') ?> <strong>Student information system (timetable):</strong> <?= V::h(Integrations::sis()->label()) ?></p>
    <p><?= V::source('lms') ?> <strong>Learning management system (grades):</strong> <?= V::h(Integrations::lms()->label()) ?></p>
    <p class="muted">SAQF updates from these systems by itself on a schedule; the buttons below do it now.</p><p class="tiny muted">Which system is connected is set in the server configuration (SAQF_SIS_SOURCE, SAQF_LMS_SOURCE; see docs/INTEGRATIONS.md).</p>
    <div class="row"><form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="check"><button class="btn btn-sm">Test connections</button></form>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="sync"><button class="btn btn-sm">Update study plans now</button></form>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="sis_assignments"><button class="btn btn-sm">Update timetable now</button></form>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="tick"><button class="btn btn-sm">Run automatic jobs now</button></form></div></div></section>
  <section class="card"><div class="card-h"><h2>Recent updates</h2></div><div class="card-b tight"><table><tbody><?php foreach ($runs as $r): ?><tr><td class="small"><?= V::h($r['source']) ?></td><td><?= V::pill($r['status'] === 'ok' ? 'Worked' : 'Failed', $r['status'] === 'ok' ? 'green' : 'red') ?></td><td class="small nowrap"><?= V::h(V::date($r['started_at'], 'j M Y H:i')) ?></td><td class="tiny muted" translate="no"><?php $st = json_decode((string) $r['stats'], true); echo is_array($st) ? V::h(implode(' · ', array_map(static fn($k, $v) => str_replace('_', ' ', (string) $k) . ' ' . (is_scalar($v) ? $v : json_encode($v)), array_keys($st), $st))) : V::h(mb_strimwidth((string) $r['stats'], 0, 140, '…')); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
  <section class="card"><div class="card-h"><h2>Automatic actions</h2><span class="muted small right">Everything SAQF did by itself, and why</span></div><div class="card-b tight"><table><tbody><?php foreach ($events as $e): ?><tr><td class="mono tiny nowrap"><?= V::h($e['type']) ?></td><td class="small nowrap"><?= V::h(V::date($e['created_at'], 'j M H:i')) ?></td><td class="small"><?= V::h($e['outcome']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div><aside class="stack">
  <?php $allTerms = Db::all('SELECT * FROM terms ORDER BY sequence DESC LIMIT 8'); $upcoming = array_reverse(array_filter($allTerms, static fn($t) => $t['status'] === 'upcoming')); ?>
  <section class="card"><div class="card-h"><h2>Academic calendar</h2></div><div class="card-b small">
    <table><tbody><?php foreach ($allTerms as $t): ?><tr><td><?= V::h($t['name']) ?> <span class="tiny muted mono"><?= V::h($t['code']) ?></span></td><td class="small nowrap"><?= V::h(V::date($t['starts_on'])) ?></td><td><?= V::pill(['active' => 'Current', 'upcoming' => 'Next', 'closed' => 'Finished'][$t['status']] ?? ucfirst((string) $t['status']), ['active' => 'green', 'upcoming' => 'blue'][$t['status']] ?? 'grey') ?></td></tr><?php endforeach; ?><?= $allTerms ? '' : '<tr><td class="muted">No terms yet.</td></tr>' ?></tbody></table>
    <p class="tiny muted" style="margin-top:8px"><?= Policy::get('term.auto_activate') ? 'Terms start automatically on their start date.' : 'Automatic term start is off (policy).' ?> Terms normally come from the SIS calendar.</p>
    <?php if ($upcoming): ?><form method="post" class="fieldset" data-confirm="Close the current term (freeze its reports) and start the selected term now?"><?= Csrf::field() ?><input type="hidden" name="op" value="term_activate">
      <strong>Start a term now</strong><select name="term" style="margin-top:6px"><?php foreach ($upcoming as $t): ?><option value="<?= (int) $t['id'] ?>"><?= V::h($t['name']) ?></option><?php endforeach; ?></select>
      <input type="text" name="reason" placeholder="Reason (audited)" required style="margin-top:6px"><button class="btn btn-sm" style="margin-top:6px">Start term</button></form><?php endif; ?>
    <details style="margin-top:8px"><summary class="small">Add a term (when the SIS does not provide it)</summary><form method="post" style="margin-top:6px"><?= Csrf::field() ?><input type="hidden" name="op" value="term_add">
      <div class="grid g2" style="gap:6px"><input type="text" name="code" placeholder="Code, e.g. 2027-1" required><input type="text" name="name" placeholder="Name, e.g. Fall 2027" required>
      <input type="text" name="academic_year" placeholder="Academic year, e.g. 2027-2028" required><input type="number" name="sequence" placeholder="Sequence" min="1" required value="<?= (int) Db::val('SELECT COALESCE(MAX(sequence), 0) + 1 FROM terms') ?>">
      <label class="tiny">Starts<input type="date" name="starts_on" required></label><label class="tiny">Ends<input type="date" name="ends_on" required></label><label class="tiny">Grades due<input type="date" name="grades_due_on" required></label></div>
      <input type="text" name="reason" placeholder="Reason (audited)" style="margin-top:6px"><button class="btn btn-sm" style="margin-top:6px">Add term</button></form></details>
  </div></section>
  <?php if (Config::demoMode()): ?>
  <section class="card" style="border-color:#F6DFC3"><div class="card-h"><h2>Integration simulator</h2><?= V::pill('demo only', 'amber') ?></div><div class="card-b small">
    <p class="muted">Plays the role of the university systems so the event-driven automation can be demonstrated. Disabled outside demo mode.</p>
    <?php foreach ($pendingLms as $b): ?><form method="post" class="fieldset"><?= Csrf::field() ?><input type="hidden" name="op" value="sim_lms"><input type="hidden" name="ref" value="<?= V::h($b['ref']) ?>"><strong>LMS publishes:</strong> <?= V::h($b['course']) ?> — <?= V::h($b['label']) ?> <span class="muted">(scheduled <?= V::h(V::date($b['published_at'])) ?>)</span><br><button class="btn btn-sm" style="margin-top:6px">Publish now</button></form><?php endforeach; ?>
    <?php foreach ($pendingSis as $i => $a): if (in_array('sis.assignment.' . $i, $released, true)) { continue; } ?><form method="post" class="fieldset"><?= Csrf::field() ?><input type="hidden" name="op" value="sim_sis"><input type="hidden" name="index" value="<?= (int) $i ?>"><strong>SIS publishes:</strong> <?= V::h($a['label']) ?><br><button class="btn btn-sm" style="margin-top:6px">Publish assignment</button></form><?php endforeach; ?>
    <?php if ($next): ?><form method="post" class="fieldset" data-confirm="Close the current term (freeze reports) and activate <?= V::h($next['name']) ?>?"><?= Csrf::field() ?><input type="hidden" name="op" value="sim_rollover"><strong>Registrar activates:</strong> <?= V::h($next['name']) ?> (semester rollover)<br><button class="btn btn-sm" style="margin-top:6px">Activate term</button></form><?php endif; ?>
  </div></section>
  <?php endif; ?>
</aside></div>
<?php endif;
V::footer();

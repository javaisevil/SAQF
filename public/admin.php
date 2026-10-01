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
use Saqf\Integration\FileSisSource;
use Saqf\Integration\Integrations;
use Saqf\Integration\SeededSisSource;
use Saqf\Integration\Sync;
use Saqf\Quality\Achievement;
use Saqf\Quality\Scheduler;
use Saqf\Quality\Workspaces;
use Saqf\Security\Auth;
use Saqf\Security\Oidc;
use Saqf\Security\Users;
use Saqf\Web\View as V;

$user = saqf_page(['admin']);
$tab = in_array($_GET['tab'] ?? '', ['health', 'users', 'security', 'audit', 'errors', 'integrations'], true) ? $_GET['tab'] : 'health';

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

// ---------------------------------------------------------------- actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    saqf_require_post();
    $op = (string) ($_POST['op'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $targetId = (int) ($_POST['user'] ?? 0);
    $target = $targetId ? Db::one('SELECT * FROM users WHERE id = ?', [$targetId]) : null;
    try {
        switch ($op) {
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
                Audit::record('admin.user_' . $op . 'd', 'user', $targetId, "Account {$target['username']} {$op}d", ['status' => $target['status']], ['status' => $op === 'disable' ? 'disabled' : 'active'], $reason);
                Session::flash('success', 'Account ' . $op . 'd.');
                break;
            case 'reset':
                if ($targetId === $user['id']) {
                    throw new DomainException('Use Account & security to change your own password.');
                }
                $temp = Users::tempPassword();
                Db::update('users', ['password_hash' => password_hash($temp, PASSWORD_DEFAULT), 'must_change_password' => 1, 'status' => 'active', 'locked_until' => null, 'failed_logins' => 0], 'id = ?', [$targetId]);
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

$tabs = ['health' => 'System health', 'users' => 'Users & access', 'security' => 'Security events', 'audit' => 'Audit log', 'errors' => 'Error log', 'integrations' => 'Integrations'];
V::header('System administration', $user, ['subtitle' => 'Operations and security — administrators manage the platform, not academic decisions (separation of duties)']);
echo V::tabs($tabs, $tab, 'admin.php');

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
    ];
?>
<div class="split"><section class="card"><div class="card-h"><h2>Health checks</h2></div><div class="card-b tight"><table><tbody>
<?php foreach ($checks as [$label, $value, $ok]): ?><tr><td style="width:260px"><?= V::h($label) ?></td><td><?= $ok === null ? V::pill('Note', 'grey') : V::pill($ok ? 'OK' : 'Check', $ok ? 'green' : 'amber') ?> <?= V::h($value) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<aside class="stack">
  <section class="card"><div class="card-h"><h2>Maintenance mode</h2><?= $maint ? V::pill('ON', 'red') : V::pill('off', 'green') ?></div><div class="card-b small"><p class="muted">Blocks all non-admin users with a friendly message (data is untouched). Use for upgrades and restores.</p>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="maintenance"><input type="hidden" name="value" value="<?= $maint ? '0' : '1' ?>"><input type="text" name="reason" placeholder="Reason (audited)" required><button class="btn btn-sm <?= $maint ? '' : 'btn-red' ?>" type="submit" style="margin-top:8px"><?= $maint ? 'Turn off' : 'Turn on' ?></button></form></div></section>
  <section class="card"><div class="card-h"><h2>Audit integrity</h2></div><div class="card-b small"><p class="muted">Re-computes the SHA-256 hash chain over every audit entry. Any edited or deleted row is reported with its position.</p>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="verify"><button class="btn btn-sm" type="submit">Verify audit chain</button></form></div></section>
  <section class="card"><div class="card-h"><h2>Volumes</h2></div><div class="card-b small"><?php foreach ($counts as $k => $v): ?><div class="row between"><span><?= V::h($k) ?></span><strong><?= number_format((int) $v) ?></strong></div><?php endforeach; ?>
    <p class="tiny muted" style="margin-top:8px">Backups: schedule <span class="mono">mysqldump --single-transaction</span> nightly (see README → Operations). Restore drills should verify the audit chain afterwards.</p></div></section>
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
  <p class="tiny muted" style="margin-top:8px">Columns: <span class="mono"><?= V::h(implode(', ', Users::CSV_COLUMNS)) ?></span>. Existing usernames are updated; invalid rows are reported and skipped. Department and college are codes.</p></div></section>
</div>
<section class="card"><div class="card-h"><form class="row" method="get"><input type="hidden" name="tab" value="users"><input type="search" name="q" value="<?= V::h($q) ?>" placeholder="Find user" style="width:260px"><button class="btn btn-sm">Search</button></form><span class="right muted small">Accounts come from SSO, the SIS feed, imports or this page; every change is audited.</span></div>
<div class="card-b tight"><div class="table-wrap"><table><thead><tr><th>User</th><th>Role & scope</th><th>Status</th><th>Last sign-in</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($users as $u): $isLocked = $u['status'] === 'locked' && $u['locked_until'] > Clock::stamp(); ?>
  <tr><td><strong><?= V::h($u['full_name']) ?></strong><div class="tiny muted"><?= V::h($u['username']) ?> · <?= V::h($u['external_id'] ?? '') ?><?= $u['email'] ? ' · ' . V::h($u['email']) : '' ?></div><?= ($u['auth_source'] ?? 'local') === 'sso' ? V::pill('SSO', 'blue') : '' ?><?= ($u['provisioned_by'] ?? 'admin') === 'sis' ? ' ' . V::pill('from SIS', 'grey') : '' ?></td>
    <td class="small"><?= V::h(Auth::ROLES[$u['role']] ?? $u['role']) ?><div class="muted"><?= V::h($u['dept'] ?? '') ?></div></td>
    <td><?= $u['status'] === 'disabled' ? V::pill('disabled', 'grey') : ($isLocked ? V::pill('locked until ' . date('H:i', strtotime($u['locked_until'])), 'red') : V::pill('active', 'green')) ?><?= $u['must_change_password'] ? ' ' . V::pill('must change password', 'amber') : '' ?></td>
    <td class="small"><?= V::h(V::ago($u['last_login_at'])) ?><div class="tiny muted mono"><?= V::h($u['last_login_ip'] ?? '') ?></div></td>
    <td><?php if ((int) $u['id'] !== $user['id']): ?><details><summary class="btn btn-sm">Manage</summary><div style="margin-top:8px;min-width:280px">
      <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="user" value="<?= (int) $u['id'] ?>"><input type="text" name="reason" placeholder="Reason / ticket (audited)" style="flex:1;min-width:180px">
        <?php if ($isLocked): ?><button class="btn btn-sm" name="op" value="unlock">Unlock</button><?php endif; ?>
        <button class="btn btn-sm" name="op" value="reset">Reset password</button>
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
<section class="card"><div class="card-h"><h2>Security events</h2><span class="muted small right">Access denials, lockouts, session anomalies, privileged changes</span></div><div class="card-b"><ul class="timeline"><?php foreach ($events as $e): ?><li class="<?= str_starts_with($e['action'], 'security.') ? 'usr' : 'sys' ?>"><div class="when"><?= V::h(V::date($e['occurred_at'], 'j M Y H:i')) ?> · <?= V::h($e['actor_name']) ?> · <span class="mono"><?= V::h($e['ip']) ?></span></div><div class="small"><strong><?= V::h($e['action']) ?></strong> — <?= V::h($e['summary']) ?></div><?php if ($e['reason']): ?><div class="tiny muted">“<?= V::h($e['reason']) ?>”</div><?php endif; ?></li><?php endforeach; ?><?= $events ? '' : '<li>No security events.</li>' ?></ul></div></section>
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
  <select name="object" style="width:auto"><option value="">Any object</option><?php foreach ($objects as $o): ?><option <?= $f['object'] === $o ? 'selected' : '' ?>><?= V::h($o) ?></option><?php endforeach; ?></select>
  <input type="date" name="from" value="<?= V::h($f['from']) ?>" style="width:150px"><input type="date" name="to" value="<?= V::h($f['to']) ?>" style="width:150px"><button class="btn btn-sm">Filter</button>
  <a class="btn btn-sm right" href="admin.php?tab=audit&export=csv">Export CSV</a></form></div>
  <div class="card-b tight"><div class="table-wrap"><table><thead><tr><th>#</th><th>When</th><th>Actor</th><th>Action</th><th>Summary</th><th>Change</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="mono tiny"><?= (int) $r['id'] ?></td><td class="small nowrap"><?= V::h($r['occurred_at']) ?></td><td class="small"><?= V::h($r['actor_name']) ?><div class="tiny muted"><?= V::h($r['actor_type']) ?><?= $r['actor_role'] ? ' · ' . V::h($r['actor_role']) : '' ?></div></td><td class="mono tiny"><?= V::h($r['action']) ?><div class="muted"><?= V::h($r['object_type']) ?> <?= V::h($r['object_id']) ?></div></td>
    <td class="small"><?= V::h($r['summary']) ?><?php if ($r['reason']): ?><div class="tiny muted">Reason: <?= V::h($r['reason']) ?></div><?php endif; ?></td>
    <td class="tiny"><?php if ($r['old_value'] || $r['new_value']): ?><details><summary>view</summary><div class="mono" style="white-space:pre-wrap;max-width:360px"><?= $r['old_value'] ? 'old: ' . V::h($r['old_value']) . "\n" : '' ?><?= $r['new_value'] ? 'new: ' . V::h($r['new_value']) : '' ?></div></details><?php endif; ?><span class="mono muted" title="<?= V::h($r['hash']) ?>"><?= V::h(substr($r['hash'], 0, 8)) ?></span></td></tr><?php endforeach; ?>
  </tbody></table></div><div class="row" style="padding:10px 14px"><?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= V::h(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Newer</a><?php endif; ?><?php if (count($rows) === 100): ?><a class="btn btn-sm" href="?<?= V::h(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Older</a><?php endif; ?></div></div></section>

<?php elseif ($tab === 'errors'):
    $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['ref'] ?? '')));
    $rows = Db::all('SELECT e.*, u.username FROM system_errors e LEFT JOIN users u ON u.id = e.user_id' . ($ref ? ' WHERE e.ref = ?' : '') . ' ORDER BY e.id DESC LIMIT 100', $ref ? [$ref] : []);
?>
<section class="card"><div class="card-h"><form class="row" method="get"><input type="hidden" name="tab" value="errors"><input type="text" name="ref" value="<?= V::h($ref) ?>" placeholder="Reference quoted by the user" style="width:240px"><button class="btn btn-sm">Find</button></form><span class="right muted small">Users see only a reference code; details are kept here</span></div>
<div class="card-b tight"><table><tbody><?php foreach ($rows as $e): ?><tr><td class="mono"><?= V::h($e['ref']) ?></td><td class="small nowrap"><?= V::h($e['occurred_at']) ?></td><td class="small"><?= V::pill($e['level'], $e['level'] === 'error' ? 'red' : 'amber') ?> <?= V::h($e['message']) ?><div class="tiny muted mono"><?= V::h($e['location']) ?> · <?= V::h($e['url']) ?> · user <?= V::h($e['username'] ?? '—') ?> · req <?= V::h($e['request_id']) ?></div><details><summary class="tiny">trace</summary><pre class="mono tiny" style="white-space:pre-wrap"><?= V::h($e['trace']) ?></pre></details></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td><?= V::empty('No errors recorded', 'Unhandled errors appear here with a reference code users can quote.') ?></td></tr><?php endif; ?></tbody></table></div></section>

<?php else:
    $runs = Db::all('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 15');
    $pendingLms = Integrations::lms()->pending();
    $pendingSis = Integrations::sis() instanceof SeededSisSource ? Integrations::sis()->pendingAssignments() : [];
    $released = Db::col('SELECT setting_key FROM system_settings WHERE setting_key LIKE "sis.assignment.%"');
    $next = Db::one('SELECT * FROM terms WHERE status = "upcoming" ORDER BY sequence LIMIT 1');
    $events = Db::all('SELECT * FROM events ORDER BY id DESC LIMIT 25');
?>
<div class="split"><div class="stack">
  <section class="card"><div class="card-h"><h2>Integration adapters</h2></div><div class="card-b small">
    <p><?= V::source('institution') ?> <strong>Registrar / study plans:</strong> <?= V::h(Integrations::institution()->label()) ?></p>
    <p><?= V::source('sis') ?> <strong>SIS:</strong> <?= V::h(Integrations::sis()->label()) ?></p>
    <p><?= V::source('lms') ?> <strong>LMS:</strong> <?= V::h(Integrations::lms()->label()) ?></p>
    <p class="muted">Connectors are chosen in the server configuration (<span class="mono">SAQF_SIS_SOURCE</span> = <?= V::h(implode(' | ', Integrations::SIS_KINDS)) ?>; <span class="mono">SAQF_LMS_SOURCE</span> = <?= V::h(implode(' | ', Integrations::LMS_KINDS)) ?>). Setup for each system: docs/INTEGRATIONS.md. The scheduler runs them automatically; these buttons run them now.</p>
    <div class="row"><form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="check"><button class="btn btn-sm">Test connections</button></form>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="sync"><button class="btn btn-sm">Sync institutional data now</button></form>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="sis_assignments"><button class="btn btn-sm">Sync SIS now</button></form>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="tick"><button class="btn btn-sm">Run scheduler now</button></form></div></div></section>
  <section class="card"><div class="card-h"><h2>Recent integration runs</h2></div><div class="card-b tight"><table><tbody><?php foreach ($runs as $r): ?><tr><td class="small"><?= V::h($r['source']) ?></td><td><?= V::pill($r['status'], $r['status'] === 'ok' ? 'green' : 'red') ?></td><td class="small nowrap"><?= V::h(V::date($r['started_at'], 'j M Y H:i')) ?></td><td class="tiny muted"><?php $st = json_decode((string) $r['stats'], true); echo is_array($st) ? V::h(implode(' · ', array_map(static fn($k, $v) => str_replace('_', ' ', (string) $k) . ' ' . (is_scalar($v) ? $v : json_encode($v)), array_keys($st), $st))) : V::h(mb_strimwidth((string) $r['stats'], 0, 140, '…')); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
  <section class="card"><div class="card-h"><h2>Event log</h2><span class="muted small right">Every automated reaction is traceable</span></div><div class="card-b tight"><table><tbody><?php foreach ($events as $e): ?><tr><td class="mono tiny nowrap"><?= V::h($e['type']) ?></td><td class="small nowrap"><?= V::h(V::date($e['created_at'], 'j M H:i')) ?></td><td class="small"><?= V::h($e['outcome']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div><aside class="stack">
  <?php $allTerms = Db::all('SELECT * FROM terms ORDER BY sequence DESC LIMIT 8'); $upcoming = array_reverse(array_filter($allTerms, static fn($t) => $t['status'] === 'upcoming')); ?>
  <section class="card"><div class="card-h"><h2>Academic calendar</h2></div><div class="card-b small">
    <table><tbody><?php foreach ($allTerms as $t): ?><tr><td><?= V::h($t['name']) ?> <span class="tiny muted mono"><?= V::h($t['code']) ?></span></td><td class="small nowrap"><?= V::h(V::date($t['starts_on'])) ?></td><td><?= V::pill($t['status'], ['active' => 'green', 'upcoming' => 'blue'][$t['status']] ?? 'grey') ?></td></tr><?php endforeach; ?><?= $allTerms ? '' : '<tr><td class="muted">No terms yet.</td></tr>' ?></tbody></table>
    <p class="tiny muted" style="margin-top:8px"><?= Policy::get('term.auto_activate') ? 'Terms start automatically on their start date.' : 'Automatic term start is off (policy).' ?> Terms normally come from the SIS calendar.</p>
    <?php if ($upcoming): ?><form method="post" class="fieldset" onsubmit="return confirm('Close the current term (freeze its reports) and start the selected term now?')"><?= Csrf::field() ?><input type="hidden" name="op" value="term_activate">
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
    <?php if ($next): ?><form method="post" class="fieldset" onsubmit="return confirm('Close the current term (freeze reports) and activate <?= V::h($next['name']) ?>?')"><?= Csrf::field() ?><input type="hidden" name="op" value="sim_rollover"><strong>Registrar activates:</strong> <?= V::h($next['name']) ?> (semester rollover)<br><button class="btn btn-sm" style="margin-top:6px">Activate term</button></form><?php endif; ?>
  </div></section>
  <?php endif; ?>
</aside></div>
<?php endif;
V::footer();

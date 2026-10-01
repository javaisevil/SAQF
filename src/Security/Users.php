<?php
declare(strict_types=1);

namespace Saqf\Security;

use InvalidArgumentException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Integration\Csv;

/**
 * Account provisioning: administrators (form or CSV import), the SIS feed (new instructors) and
 * university SSO all create accounts through here, so validation, scope rules and the audit
 * trail are identical whichever way a person arrives.
 */
final class Users
{
    public const CSV_COLUMNS = ['username', 'full_name', 'email', 'role', 'department', 'college', 'external_id', 'title'];

    /**
     * Creates or updates (by username) one account.
     * @param array{username:string,full_name:string,email?:?string,role:string,department?:?string,college?:?string,external_id?:?string,title?:?string} $data
     *        department / college are codes (e.g. CED, COE)
     * @return array{id:int,created:bool,changed:bool,temp_password:?string,invited:bool}
     */
    public static function save(array $data, string $source = 'admin', ?string $reason = null): array
    {
        $username = mb_strtolower(trim((string) ($data['username'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9._@\-]{1,79}$/', $username)) {
            throw new InvalidArgumentException("Username \"$username\" is not valid (2–80 characters: letters, digits, . _ - @).");
        }
        $name = trim((string) ($data['full_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException("$username: full name is required (up to 160 characters).");
        }
        $email = trim((string) ($data['email'] ?? '')) ?: null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("$username: \"$email\" is not a valid e-mail address.");
        }
        $role = trim((string) ($data['role'] ?? ''));
        if (!isset(Auth::ROLES[$role])) {
            throw new InvalidArgumentException("$username: role must be one of " . implode(', ', array_keys(Auth::ROLES)) . '.');
        }
        $deptId = null;
        if (($code = strtoupper(trim((string) ($data['department'] ?? '')))) !== '') {
            $deptId = Db::val('SELECT id FROM departments WHERE code = ?', [$code]);
            if (!$deptId) {
                throw new InvalidArgumentException("$username: unknown department code \"$code\".");
            }
        }
        $collegeId = null;
        if (($code = strtoupper(trim((string) ($data['college'] ?? '')))) !== '') {
            $collegeId = Db::val('SELECT id FROM colleges WHERE code = ?', [$code]);
            if (!$collegeId) {
                throw new InvalidArgumentException("$username: unknown college code \"$code\".");
            }
        }
        if ($role === 'hod' && !$deptId) {
            throw new InvalidArgumentException("$username: a Head of Department needs a department.");
        }
        if ($role === 'dean' && !$collegeId && !$deptId) {
            throw new InvalidArgumentException("$username: a College Dean needs a college.");
        }
        $external = trim((string) ($data['external_id'] ?? '')) ?: null;
        $existing = Db::one('SELECT * FROM users WHERE username = ?', [$username]);
        if ($external !== null && Db::val('SELECT 1 FROM users WHERE external_id = ? AND username <> ?', [$external, $username])) {
            throw new InvalidArgumentException("$username: SIS/HR identifier $external already belongs to another account.");
        }
        $fields = [
            'full_name' => $name, 'email' => $email, 'role' => $role, 'department_id' => $deptId ? (int) $deptId : null,
            'college_id' => $collegeId ? (int) $collegeId : null, 'external_id' => $external, 'title' => trim((string) ($data['title'] ?? '')) ?: null,
        ];

        if ($existing) {
            $changes = [];
            foreach ($fields as $k => $v) {
                if ((string) ($existing[$k] ?? '') !== (string) ($v ?? '')) {
                    $changes[$k] = $v;
                }
            }
            if ($changes) {
                Db::update('users', $changes, 'id = ?', [$existing['id']]);
                Audit::record('user.updated', 'user', $existing['id'], "Account $username updated (" . implode(', ', array_keys($changes)) . ')', array_intersect_key($existing, $changes), $changes, $reason);
            }
            return ['id' => (int) $existing['id'], 'created' => false, 'changed' => (bool) $changes, 'temp_password' => null, 'invited' => false];
        }

        // New account: university SSO accounts get no usable password; otherwise the person is
        // invited by e-mail to set one, or (no e-mail available) a one-time password is shown.
        $sso = Oidc::enabled();
        $passwordAllowed = Auth::passwordLoginAllowed($role);
        $invite = !$sso && $source !== 'sso' && $passwordAllowed && $email !== null && PasswordReset::available();
        // Accounts created by the SIS feed never get a password nobody has seen: an administrator
        // issues one on request (or the person signs in through SSO / the e-mail invitation).
        $temp = (!$sso && $passwordAllowed && !$invite && !in_array($source, ['sis', 'sso'], true)) ? self::tempPassword() : null;
        $id = Db::insert('users', $fields + [
            'username' => $username,
            'password_hash' => password_hash($temp ?? bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'auth_source' => $sso ? 'sso' : 'local',
            'provisioned_by' => $source,
            'status' => 'active',
            'must_change_password' => $temp !== null ? 1 : 0,
            'created_at' => Clock::stamp(),
        ]);
        Audit::record('user.created', 'user', $id, "Account $username created (" . (Auth::ROLES[$role]) . ", source: $source)", null, $fields, $reason);
        if ($invite) {
            PasswordReset::invite($id);
        }
        return ['id' => $id, 'created' => true, 'changed' => true, 'temp_password' => $temp, 'invited' => $invite];
    }

    /**
     * Bulk create/update from CSV (columns: username, full_name, email, role, department, college,
     * external_id, title). Invalid rows are reported and skipped; valid rows are applied.
     * @return array{created:int,updated:int,unchanged:int,errors:list<string>,credentials:list<array{0:string,1:string}>,invited:int}
     */
    public static function importCsv(string $path, string $reason): array
    {
        $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => [], 'credentials' => [], 'invited' => 0];
        $line = 1;
        foreach (Csv::rows($path, ['username', 'full_name', 'role']) as $row) {
            $line++;
            try {
                $r = Db::tx(static fn() => self::save($row, 'import', $reason));
                $out[$r['created'] ? 'created' : ($r['changed'] ? 'updated' : 'unchanged')]++;
                if ($r['temp_password']) {
                    $out['credentials'][] = [mb_strtolower(trim($row['username'])), $r['temp_password']];
                }
                $out['invited'] += (int) $r['invited'];
            } catch (InvalidArgumentException $e) {
                $out['errors'][] = "Line $line: " . $e->getMessage();
            }
            if ($line > 5000) {
                $out['errors'][] = 'Stopped after 5000 rows.';
                break;
            }
        }
        Audit::record('user.imported', 'user', null, "User import: {$out['created']} created, {$out['updated']} updated, " . count($out['errors']) . ' row(s) rejected', null, null, $reason);
        return $out;
    }

    /**
     * Account for an instructor the SIS assigned but SAQF does not know yet. An existing account
     * with the same e-mail is linked instead of duplicated. @return int|null user id
     */
    public static function provisionInstructor(string $externalId, ?string $name, ?string $email, ?int $departmentId): ?int
    {
        if ($email && ($id = Db::val('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND (external_id IS NULL OR external_id = "")', [$email]))) {
            Db::update('users', ['external_id' => $externalId], 'id = ?', [$id]);
            Audit::record('user.linked', 'user', $id, "Account linked to SIS instructor $externalId (matched by e-mail)");
            return (int) $id;
        }
        if (!$name || !Policy::get('integration.provision_instructors')) {
            return null;
        }
        $base = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? strstr($email, '@', true) : $externalId;
        $base = trim((string) preg_replace('/[^a-z0-9._\-]/', '', mb_strtolower((string) $base)), '.-_') ?: 'instructor';
        $username = $base;
        for ($i = 2; Db::val('SELECT 1 FROM users WHERE username = ?', [$username]); $i++) {
            $username = $base . $i;
        }
        $dept = $departmentId ? Db::val('SELECT code FROM departments WHERE id = ?', [$departmentId]) : null;
        $r = self::save(['username' => $username, 'full_name' => $name, 'email' => $email, 'role' => 'faculty', 'department' => $dept, 'external_id' => $externalId], 'sis', 'New instructor in the SIS teaching assignments');
        return $r['id'];
    }

    public static function tempPassword(): string
    {
        return 'Tmp' . substr(strtr(base64_encode(random_bytes(9)), '+/', 'xy'), 0, 10) . random_int(10, 99);
    }
}

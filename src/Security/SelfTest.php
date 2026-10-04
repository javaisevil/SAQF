<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Secrets;

/**
 * Live security self-test: tries each protection for real on this installation (against its own
 * database and keys) and reports what happened. Nothing is changed and nothing is left behind.
 * Shown in the Security center, in the security evidence report and runnable from the command line.
 */
final class SelfTest
{
    /** @return list<array{name:string,what:string,ok:bool,detail:string}> */
    public static function run(): array
    {
        $tests = [];
        $add = static function (string $name, string $what, callable $fn) use (&$tests) {
            try {
                [$ok, $detail] = $fn();
            } catch (\Throwable $e) {
                [$ok, $detail] = [false, 'The test itself failed: ' . $e->getMessage()];
            }
            $tests[] = ['name' => $name, 'what' => $what, 'ok' => (bool) $ok, 'detail' => (string) $detail];
        };

        $add('Weak passwords are refused', 'Tries a common password, a keyboard run and a strong passphrase.', static function () {
            $weak = PasswordPolicy::problem('Password123') !== null && PasswordPolicy::problem('qwertyuiop12') !== null;
            $strong = PasswordPolicy::problem('Tg7-violet-harbor-42') === null;
            return [$weak && $strong, $weak && $strong ? 'Common and guessable passwords rejected; a long passphrase accepted.' : 'The password policy accepted a weak password or refused a strong one.'];
        });
        $add('Audit log is tamper-evident', 'Re-computes the hash chain over every recorded action.', static function () {
            $v = Audit::verify();
            return [$v['ok'], $v['message']];
        });
        $add('Audit log cannot be edited or deleted', 'Tries to rewrite an audit entry directly in the database.', static function () {
            $id = (int) Db::val('SELECT MIN(id) FROM audit_log');
            if (!$id) {
                return [false, 'No audit entries to test against.'];
            }
            try {
                Db::exec('UPDATE audit_log SET summary = summary WHERE id = ?', [$id]);
            } catch (\Throwable $e) {
                return [true, 'The database refused the change (append-only).'];
            }
            return [false, 'The database allowed a change to the audit log. Apply database/audit_guard.sql (the hash chain would still reveal tampering).'];
        });
        $add('Forged requests are rejected', 'Submits a wrong anti-forgery token, then the right one.', static function () {
            $saved = $_SESSION['_csrf'] ?? null;
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
            $good = $_SESSION['_csrf'];
            $result = !Csrf::valid('forged-token') && !Csrf::valid('') && Csrf::valid($good);
            if ($saved === null) {
                unset($_SESSION['_csrf']);
            } else {
                $_SESSION['_csrf'] = $saved;
            }
            return [$result, $result ? 'A request without the session\'s token is refused.' : 'Anti-forgery check misbehaved.'];
        });
        $add('Authenticator codes are standard', 'Checks the two-step code generator against the RFC 6238 reference value.', static function () {
            $ok = Totp::code(Totp::base32('12345678901234567890'), 1) === '287082';
            return [$ok, $ok ? 'Codes match the published reference, so any authenticator app works.' : 'Code generator does not match RFC 6238.'];
        });
        $add('Stored secrets are encrypted and tamper-proof', 'Encrypts a value, reads it back, then flips one bit.', static function () {
            $enc = Secrets::encrypt('saqf-self-test');
            $raw = (string) base64_decode(substr($enc, 3), true);
            $flipped = 'v1:' . base64_encode(substr($raw, 0, -1) . chr(ord(substr($raw, -1)) ^ 1));
            $ok = Secrets::decrypt($enc) === 'saqf-self-test' && Secrets::decrypt($flipped) === null && !str_contains($enc, 'self-test');
            return [$ok, $ok ? 'AES-256-GCM: round-trips, hides the content, and a modified value is rejected.' : 'Encryption round-trip or tamper detection failed.'];
        });
        $add('Student identities are pseudonymised', 'Converts a student number and checks it cannot be read back.', static function () {
            $a = Secrets::pseudonym('lms', '202401234');
            $ok = $a === Secrets::pseudonym('lms', '202401234') && !str_contains($a, '202401234') && $a !== Secrets::pseudonym('sis', '202401234');
            return [$ok, $ok ? 'Keyed one-way keys: stable for matching results, impossible to reverse without the key.' : 'Pseudonymisation is not stable or leaks the identifier.'];
        });
        if (PHP_SAPI !== 'cli') { // session settings only exist in the web process
            $add('Sessions are protected', 'Checks the session cookie and session-ID settings.', static function () {
                $ok = ini_get('session.use_strict_mode') === '1' && ini_get('session.cookie_httponly') === '1' && ini_get('session.use_only_cookies') === '1';
                return [$ok, $ok ? 'HttpOnly cookies, strict session IDs, no session IDs in URLs.' : 'Session hardening settings are not active in this process.'];
            });
        }
        return $tests;
    }

    /** Runs the tests and remembers the outcome for the Security center. @return array{at:string,passed:int,total:int,tests:list<array>} */
    public static function runAndStore(): array
    {
        $tests = self::run();
        $r = ['at' => Clock::stamp(), 'passed' => count(array_filter($tests, static fn($t) => $t['ok'])), 'total' => count($tests), 'tests' => $tests];
        Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("security.last_selftest", ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [json_encode($r), Clock::stamp()]);
        return $r;
    }

    /** @return array{at:string,passed:int,total:int,tests:list<array>}|null */
    public static function last(): ?array
    {
        $r = json_decode((string) Db::val('SELECT value FROM system_settings WHERE setting_key = "security.last_selftest"'), true);
        return is_array($r) ? $r : null;
    }
}

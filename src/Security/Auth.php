<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Core\Request;
use Saqf\Core\Session;

/**
 * Authentication: university SSO (OpenID Connect, see Oidc) and password login with per-account
 * lockout, per-IP throttling, session fixation protection, idle/absolute timeouts and session binding.
 * SAQF_PASSWORD_LOGIN = all | admins | off decides who may still use a password once SSO is on
 * (recommended: admins, as a break-glass path when the identity provider is unavailable).
 */
final class Auth
{
    public const ROLES = [
        'faculty' => 'Faculty Member',
        'hod' => 'Head of Department',
        'qa' => 'Quality Assurance',
        'dean' => 'College Dean',
        'leadership' => 'University Leadership',
        'admin' => 'System Administrator',
    ];

    private static ?array $user = null;
    private const DUMMY_HASH = '$2y$10$AAfKQE8NqdIfMvVDSW4G4.gt.GclCdJUmLXFiV8wdGTpjS6oMtvhC';

    /** @return array{ok:bool,message:string} */
    public static function attempt(string $username, string $password): array
    {
        $username = mb_strtolower(trim($username));
        $ip = Request::ip();
        $now = Clock::now();
        $window = $now->modify('-15 minutes')->format('Y-m-d H:i:s');

        $ipAttempts = (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= ? AND success = 0', [$ip, $window]);
        if ($ipAttempts >= Policy::get('auth.ip_max_attempts_15min')) {
            self::logAttempt($username, false, 'ip_throttled');
            Audit::asSystem(fn() => Audit::record('security.ip_throttled', 'ip', $ip, "Login attempts from $ip throttled after $ipAttempts failures in 15 minutes"));
            return ['ok' => false, 'message' => 'Too many sign-in attempts from this network. Please wait 15 minutes and try again.'];
        }

        // With password sign-in restricted, every refusal reads the same, so the form reveals neither
        // which accounts exist nor whether a non-administrator's password was right.
        $incorrect = self::passwordLoginMode() === 'all' ? 'Incorrect username or password.'
            : 'Incorrect username or password. Unless you are a SAQF administrator, use “' . Oidc::buttonLabel() . '”.';
        $user = Db::one('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$user || !self::passwordLoginAllowed($user['role'])) {
            password_verify($password, self::DUMMY_HASH); // equalise timing
            self::logAttempt($username, false, $user ? 'password_login_disabled' : 'unknown_user');
            return ['ok' => false, 'message' => $incorrect];
        }
        if ($user['status'] === 'disabled') {
            self::logAttempt($username, false, 'disabled');
            return ['ok' => false, 'message' => 'This account is disabled. Contact the system administrator.'];
        }
        if ($user['locked_until'] !== null && $user['locked_until'] > $now->format('Y-m-d H:i:s')) {
            self::logAttempt($username, false, 'locked');
            return ['ok' => false, 'message' => 'This account is temporarily locked after repeated failed sign-ins. Try again later or contact IT support.'];
        }
        if (!password_verify($password, $user['password_hash'])) {
            $failed = (int) $user['failed_logins'] + 1;
            $max = Policy::get('auth.max_failed_logins');
            $lockedUntil = null;
            if ($failed >= $max) {
                $lockedUntil = $now->modify('+' . Policy::get('auth.lockout_minutes') . ' minutes')->format('Y-m-d H:i:s');
                $failed = 0;
            }
            Db::update('users', ['failed_logins' => $failed, 'locked_until' => $lockedUntil, 'status' => $lockedUntil ? 'locked' : $user['status']], 'id = ?', [$user['id']]);
            self::logAttempt($username, false, 'bad_password');
            if ($lockedUntil) {
                Audit::asSystem(fn() => Audit::record('security.account_locked', 'user', $user['id'], "Account {$user['username']} locked until $lockedUntil after $max failed sign-ins (IP $ip)"));
            }
            return ['ok' => false, 'message' => $incorrect];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        self::login($user);
        self::logAttempt($username, true, null);
        return ['ok' => true, 'message' => 'Signed in'];
    }

    public static function login(array $user, string $method = 'password'): void
    {
        Session::regenerate();
        $now = Clock::now();
        $_SESSION['auth'] = $method;
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['full_name'];
        // Session lifetimes are measured in real elapsed time (never the demo clock).
        $_SESSION['login_at'] = time();
        $_SESSION['seen_at'] = time();
        $_SESSION['ua'] = hash('sha256', Request::userAgent());
        Db::update('users', [
            'failed_logins' => 0, 'locked_until' => null, 'status' => 'active',
            'last_login_at' => $now->format('Y-m-d H:i:s'), 'last_login_ip' => Request::ip(),
        ], 'id = ?', [$user['id']]);
        self::$user = null;
        Audit::record('auth.login', 'user', $user['id'], "{$user['full_name']} signed in" . ($method === 'sso' ? ' (university SSO)' : ''));
    }

    public static function logout(string $reason = 'user'): void
    {
        if (!empty($_SESSION['uid'])) {
            Audit::record('auth.logout', 'user', $_SESSION['uid'], ($_SESSION['name'] ?? 'User') . ' signed out' . ($reason !== 'user' ? " ($reason)" : ''));
        }
        Session::destroy();
        self::$user = null;
    }

    /** Current user or null. Enforces idle/absolute timeouts and session binding. */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        if (empty($_SESSION['uid'])) {
            return null;
        }
        $now = time();
        $idle = Policy::get('session.idle_minutes') * 60;
        $absolute = Policy::get('session.absolute_hours') * 3600;
        if (($now - (int) ($_SESSION['seen_at'] ?? 0)) > $idle) {
            self::logout('idle timeout');
            Session::start();
            Session::flash('info', 'Your session expired after inactivity. Please sign in again.');
            return null;
        }
        if (($now - (int) ($_SESSION['login_at'] ?? 0)) > $absolute) {
            self::logout('session lifetime reached');
            Session::start();
            Session::flash('info', 'For security, sessions last at most ' . Policy::get('session.absolute_hours') . ' hours. Please sign in again.');
            return null;
        }
        if (!hash_equals((string) ($_SESSION['ua'] ?? ''), hash('sha256', Request::userAgent()))) {
            Audit::record('security.session_mismatch', 'user', $_SESSION['uid'], 'Session used from a different browser fingerprint; session terminated');
            self::logout('session binding mismatch');
            Session::start();
            return null;
        }
        $user = Db::one(
            'SELECT u.*, d.name AS department_name, d.code AS department_code, COALESCE(u.college_id, d.college_id) AS scope_college_id, c.name AS college_name
             FROM users u LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN colleges c ON c.id = COALESCE(u.college_id, d.college_id)
             WHERE u.id = ?',
            [$_SESSION['uid']]
        );
        if (!$user || $user['status'] === 'disabled') {
            self::logout('account disabled');
            Session::start();
            return null;
        }
        $_SESSION['seen_at'] = $now;
        $_SESSION['role'] = $user['role'];
        $user['id'] = (int) $user['id'];
        $user['department_id'] = $user['department_id'] === null ? null : (int) $user['department_id'];
        $user['scope_college_id'] = $user['scope_college_id'] === null ? null : (int) $user['scope_college_id'];
        self::$user = $user;
        return $user;
    }

    /** Page guard: requires sign-in and (optionally) one of the given roles. */
    public static function require(array $roles = []): array
    {
        $user = self::user();
        if ($user === null) {
            header('Location: ' . Request::url('login.php'));
            exit;
        }
        if ($roles && !in_array($user['role'], $roles, true)) {
            Authz::deny('page ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        }
        if ($user['must_change_password'] && ($_SESSION['auth'] ?? 'password') === 'password' && basename((string) $_SERVER['SCRIPT_NAME']) !== 'account.php') {
            header('Location: ' . Request::url('account.php?required=1'));
            exit;
        }
        return $user;
    }

    public static function passwordLoginMode(): string
    {
        $mode = strtolower((string) Config::get('SAQF_PASSWORD_LOGIN', 'all'));
        return in_array($mode, ['all', 'admins', 'off'], true) ? $mode : 'all';
    }

    public static function passwordLoginAllowed(string $role): bool
    {
        $mode = self::passwordLoginMode();
        return $mode === 'all' || ($mode === 'admins' && $role === 'admin');
    }

    /**
     * Maps verified SSO claims to a SAQF account: by linked identity, else by username/e-mail
     * (then linked), else created when SAQF_OIDC_AUTO_PROVISION is on and a role can be derived.
     * Roles follow the identity provider when SAQF_OIDC_ROLE_CLAIM / SAQF_OIDC_ROLE_MAP are set.
     */
    public static function ssoUser(array $claims): array
    {
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            throw new SsoException('The university sign-in did not identify you (no subject).');
        }
        $subject = hash('sha256', ($claims['iss'] ?? '') . '|' . $sub);
        $login = mb_strtolower(trim((string) ($claims[(string) (Config::get('SAQF_OIDC_USERNAME_CLAIM') ?: 'preferred_username')] ?? '')));
        $email = mb_strtolower(trim((string) ($claims['email'] ?? (str_contains($login, '@') ? $login : ''))));
        $role = Oidc::roleFromClaims($claims);
        $user = Db::one('SELECT * FROM users WHERE sso_subject = ?', [$subject]);
        if (!$user) {
            $candidates = array_values(array_unique(array_filter([$login, str_contains($login, '@') ? strstr($login, '@', true) : ''])));
            $matches = Db::all(
                'SELECT * FROM users WHERE (sso_subject IS NULL OR sso_subject = "") AND (' . ($candidates ? 'username IN (' . Db::in($candidates) . ')' : '0') . ($email !== '' ? ' OR LOWER(email) = ?' : '') . ')',
                array_merge($candidates, $email !== '' ? [$email] : [])
            );
            if (count($matches) > 1) {
                throw new SsoException('More than one SAQF account matches your university account. Ask the SAQF administrator to merge them.');
            }
            if ($matches) {
                $user = $matches[0];
                Db::update('users', ['sso_subject' => $subject, 'auth_source' => 'sso'], 'id = ?', [$user['id']]);
                Audit::asSystem(static fn() => Audit::record('user.sso_linked', 'user', $user['id'], "Account {$user['username']} linked to university sign-in"), 'integration', 'University SSO');
            } elseif (Config::bool('SAQF_OIDC_AUTO_PROVISION') && ($role ?? Config::get('SAQF_OIDC_DEFAULT_ROLE'))) {
                $username = $login !== '' ? (str_contains($login, '@') ? (string) strstr($login, '@', true) : $login) : 'user' . substr($subject, 0, 8);
                $id = Audit::asSystem(static fn() => Users::save([
                    'username' => $username, 'full_name' => (string) ($claims['name'] ?? $username), 'email' => $email ?: null,
                    'role' => $role ?? (string) Config::get('SAQF_OIDC_DEFAULT_ROLE'),
                ], 'sso', 'First sign-in through university SSO')['id'], 'integration', 'University SSO');
                Db::update('users', ['sso_subject' => $subject, 'auth_source' => 'sso'], 'id = ?', [$id]);
                $user = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
            } else {
                throw new SsoException('Your university account' . ($login !== '' ? " ($login)" : '') . ' is not set up in SAQF yet. Ask the SAQF administrator to add you.');
            }
        }
        if ($user['status'] === 'disabled') {
            throw new SsoException('This SAQF account is disabled. Contact the system administrator.');
        }
        if ($role !== null && $role !== $user['role']) {
            Db::update('users', ['role' => $role], 'id = ?', [$user['id']]);
            Audit::asSystem(static fn() => Audit::record('user.role_synced', 'user', $user['id'], "Role of {$user['username']} changed from {$user['role']} to $role by the university identity provider", ['role' => $user['role']], ['role' => $role]), 'integration', 'University SSO');
            $user['role'] = $role;
        }
        return $user;
    }

    public static function ssoSucceeded(string $username): void
    {
        self::logAttempt($username, true, 'sso');
    }

    /** Records an SSO sign-in failure (security log) without revealing details to the browser. */
    public static function ssoFailed(string $reason, string $who = ''): void
    {
        self::logAttempt($who !== '' ? $who : 'sso', false, 'sso: ' . mb_substr($reason, 0, 50));
    }

    /** @return string|null error message, or null when the password is acceptable */
    public static function passwordProblem(string $password, string $username): ?string
    {
        $min = Policy::get('auth.min_password_length');
        if (mb_strlen($password) < $min) {
            return "Use at least $min characters.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Use both letters and numbers.';
        }
        if (stripos($password, $username) !== false) {
            return 'The password must not contain your username.';
        }
        $common = ['password123', 'qwerty1234', '1234567890', 'yamamah123', 'saqf123456', 'welcome123'];
        if (in_array(mb_strtolower($password), $common, true)) {
            return 'That password is too common.';
        }
        return null;
    }

    public static function changePassword(array $user, string $current, string $new): ?string
    {
        $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            return 'Your current password is incorrect.';
        }
        if ($problem = self::passwordProblem($new, $user['username'])) {
            return $problem;
        }
        if (password_verify($new, $row['password_hash'])) {
            return 'Choose a password different from the current one.';
        }
        Db::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'password_changed_at' => Clock::stamp(), 'must_change_password' => 0], 'id = ?', [$user['id']]);
        Session::regenerate();
        Audit::record('auth.password_changed', 'user', $user['id'], "{$user['full_name']} changed their password");
        return null;
    }

    private static function logAttempt(string $username, bool $success, ?string $reason): void
    {
        Db::insert('login_attempts', [
            'username' => mb_substr($username, 0, 80),
            'ip' => Request::ip(),
            'success' => $success ? 1 : 0,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 60),
            'created_at' => Clock::stamp(),
        ]);
    }
}

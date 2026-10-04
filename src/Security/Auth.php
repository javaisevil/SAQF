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
 * lockout, per-IP throttling, two-step verification (Mfa), session fixation protection, a session
 * registry (Sessions), idle/absolute timeouts and session binding. Passwords are hashed with Argon2id
 * where PHP supports it (bcrypt otherwise) and upgraded on the next sign-in.
 * SAQF_PASSWORD_LOGIN = all | admins | off decides who may still use a password once SSO is on
 * (recommended: admins, as a break-glass path when the identity provider is unavailable).
 * SAQF_ADMIN_ALLOWED_IPS limits administrator access to the listed networks (e.g. the campus).
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
    private static ?string $dummy = null;

    /** Password hash with the strongest algorithm available (Argon2id, else bcrypt). */
    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    private static function algorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /** Administrators may be limited to trusted networks (SAQF_ADMIN_ALLOWED_IPS). */
    public static function adminNetworkAllowed(): bool
    {
        $allowed = trim((string) Config::get('SAQF_ADMIN_ALLOWED_IPS', ''));
        return $allowed === '' || Request::inRanges(Request::ip(), $allowed);
    }

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
            \Saqf\Core\Alerts::raise('security.bruteforce', 'warning', 'Password guessing blocked', "Sign-in attempts from $ip were throttled after $ipAttempts failures in 15 minutes. If this continues, block the address at the firewall (Security events lists the sources).");
            return ['ok' => false, 'message' => 'Too many sign-in attempts from this network. Please wait 15 minutes and try again.'];
        }

        // With password sign-in restricted, every refusal reads the same, so the form reveals neither
        // which accounts exist nor whether a non-administrator's password was right.
        $incorrect = self::passwordLoginMode() === 'all' ? 'Incorrect username or password.'
            : 'Incorrect username or password. Unless you are a SAQF administrator, use “' . Oidc::buttonLabel() . '”.';
        $user = Db::one('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$user || !self::passwordLoginAllowed($user['role'])) {
            password_verify($password, self::$dummy ??= self::hash(bin2hex(random_bytes(12)))); // equalise timing
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

        if (password_needs_rehash($user['password_hash'], self::algorithm())) {
            Db::update('users', ['password_hash' => self::hash($password)], 'id = ?', [$user['id']]);
        }
        if ($user['role'] === 'admin' && !self::adminNetworkAllowed()) {
            self::logAttempt($username, false, 'admin_network_refused');
            Audit::asSystem(fn() => Audit::record('security.admin_network_refused', 'user', $user['id'], "Administrator sign-in for {$user['username']} refused from $ip (outside SAQF_ADMIN_ALLOWED_IPS)"));
            return ['ok' => false, 'message' => 'Administrator access is only allowed from the university network.'];
        }
        // Second step: the authenticator app when the person set one up, otherwise an e-mailed code
        // whenever policy requires two-step verification for them.
        $method = Mfa::enabled($user) ? 'app' : (Mfa::required($user) && Mfa::emailAllowed($user) ? 'email' : null);
        if ($method !== null && TrustedDevices::recognises($user)) {
            self::login($user, 'password+trusted');
            self::logAttempt($username, true, 'trusted_browser');
            return ['ok' => true, 'message' => 'Signed in'];
        }
        if ($method !== null) {
            // Password is right; the session is created only after the second step.
            Session::regenerate();
            $_SESSION['mfa_pending'] = ['uid' => (int) $user['id'], 'at' => time(), 'tries' => 0, 'method' => $method];
            if ($method === 'email') {
                Mfa::sendEmailCode($user);
            }
            return ['ok' => true, 'mfa' => true, 'message' => $method === 'email' ? 'Enter the code we e-mailed you' : 'Enter the code from your authenticator app'];
        }
        self::login($user);
        self::logAttempt($username, true, null);
        return ['ok' => true, 'message' => 'Signed in'];
    }

    /** Records a sign-in refused before the password was even checked (robot check, expired form). */
    public static function refuse(string $username, string $reason): void
    {
        self::logAttempt(mb_strtolower(trim($username)) ?: '(none)', false, $reason);
    }

    /** How the waiting sign-in is being confirmed: app | email. */
    public static function mfaMethod(): string
    {
        return ($_SESSION['mfa_pending']['method'] ?? 'app') === 'email' ? 'email' : 'app';
    }

    /** Lets the person switch between the authenticator app and an e-mailed code. @return string|null error */
    public static function switchMfaMethod(string $to): ?string
    {
        $user = self::mfaPending();
        if (!$user) {
            return 'The sign-in expired. Enter your password again.';
        }
        if ($to === 'email' && Mfa::emailAllowed($user)) {
            $_SESSION['mfa_pending']['method'] = 'email';
            return Mfa::sendEmailCode($user);
        }
        if ($to === 'app' && Mfa::enabled($user)) {
            $_SESSION['mfa_pending']['method'] = 'app';
            return null;
        }
        return 'That way of confirming is not available for this account.';
    }

    /** User waiting for the second step, if the pending sign-in is still fresh (5 minutes). */
    public static function mfaPending(): ?array
    {
        $p = $_SESSION['mfa_pending'] ?? null;
        if (!$p || time() - (int) $p['at'] > 300) {
            unset($_SESSION['mfa_pending']);
            return null;
        }
        return Db::one('SELECT * FROM users WHERE id = ? AND status <> "disabled"', [$p['uid']]);
    }

    /** Second step of a password sign-in. @return array{ok:bool,message:string} */
    public static function completeMfa(string $code, bool $trustBrowser = false): array
    {
        $user = self::mfaPending();
        if (!$user) {
            return ['ok' => false, 'message' => 'The sign-in expired. Enter your password again.'];
        }
        $method = self::mfaMethod();
        $clean = (string) preg_replace('/[^A-Za-z0-9]/', '', $code);
        $right = $method === 'email' && strlen($clean) === 6
            ? Mfa::verifyEmailCode($user, $clean)
            : (Mfa::enabled($user) && Mfa::verify($user, $code) !== null);
        if (!$right) {
            $_SESSION['mfa_pending']['tries'] = (int) $_SESSION['mfa_pending']['tries'] + 1;
            self::logAttempt((string) $user['username'], false, 'bad_mfa_code');
            if ($_SESSION['mfa_pending']['tries'] >= 5) {
                unset($_SESSION['mfa_pending']);
                Audit::asSystem(fn() => Audit::record('security.mfa_failed', 'user', $user['id'], "Five wrong verification codes for {$user['username']}; sign-in abandoned"));
                return ['ok' => false, 'message' => 'Too many wrong codes. Sign in again.'];
            }
            return ['ok' => false, 'message' => $method === 'email' ? 'That code is not right or has expired. Enter the 6-digit code from the latest e-mail, or send a new one.' : 'That code is not right. Enter the current 6-digit code, or a recovery code.'];
        }
        unset($_SESSION['mfa_pending']);
        self::login($user, $method === 'email' ? 'password+email' : 'password+mfa');
        if ($trustBrowser) {
            TrustedDevices::trust($user);
        }
        self::logAttempt((string) $user['username'], true, $method === 'email' ? 'mfa_email' : 'mfa');
        return ['ok' => true, 'message' => 'Signed in'];
    }

    /** Sensitive changes need a sign-in or identity confirmation within security.reauth_minutes. */
    public static function recentlyVerified(): bool
    {
        $at = max((int) ($_SESSION['login_at'] ?? 0), (int) ($_SESSION['reauth_at'] ?? 0));
        return time() - $at <= Policy::get('security.reauth_minutes') * 60;
    }

    /** Confirms the signed-in person's identity again (password, plus a code when two-step is on). */
    public static function reauthenticate(array $user, string $password, string $code): ?string
    {
        $row = Db::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            self::logAttempt((string) $user['username'], false, 'reauth_failed');
            return 'That password is not right.';
        }
        if (Mfa::enabled($row) && Mfa::verify($row, $code) === null) {
            self::logAttempt((string) $user['username'], false, 'reauth_bad_code');
            return 'That verification code is not right.';
        }
        $_SESSION['reauth_at'] = time();
        Audit::record('auth.reauthenticated', 'user', $user['id'], "{$user['full_name']} confirmed their identity for sensitive changes");
        return null;
    }

    public static function login(array $user, string $method = 'password'): void
    {
        $previous = Db::one('SELECT created_at, user_agent, ip FROM user_sessions WHERE user_id = ? AND method <> "demo" ORDER BY created_at DESC LIMIT 1', [$user['id']]);
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
        Sessions::start($user, $method);
        \Saqf\Web\I18n::applyAccount($user);
        Db::update('users', [
            'failed_logins' => 0, 'locked_until' => null, 'status' => 'active',
            'last_login_at' => $now->format('Y-m-d H:i:s'), 'last_login_ip' => Request::ip(),
        ], 'id = ?', [$user['id']]);
        self::$user = null;
        $how = ['sso' => ' (university SSO)', 'password+mfa' => ' (password and authenticator code)', 'password+email' => ' (password and e-mailed code)', 'password+trusted' => ' (password on a trusted browser)', 'demo' => ' (demo one-click sign-in)'][$method] ?? '';
        Audit::record('auth.login', 'user', $user['id'], "{$user['full_name']} signed in$how");
        // Like a bank: say when and where the account was last used, so a stranger's sign-in stands out.
        if ($method !== 'demo' && $previous) {
            Session::flash('info', 'Welcome back. Your last sign-in was on ' . date('j M Y \a\t H:i', strtotime((string) $previous['created_at'])) . ' from ' . Sessions::describe((string) $previous['user_agent']) . ' (' . $previous['ip'] . '). Not you? Open Account & security.');
        }
    }

    public static function logout(string $reason = 'user'): void
    {
        if (!empty($_SESSION['uid'])) {
            Audit::record('auth.logout', 'user', $_SESSION['uid'], ($_SESSION['name'] ?? 'User') . ' signed out' . ($reason !== 'user' ? " ($reason)" : ''));
        }
        if ($hash = Sessions::currentHash()) {
            Sessions::end($hash, $reason === 'user' ? 'signed out' : $reason);
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
        if (!Sessions::valid((int) $_SESSION['uid'])) {
            // Ended elsewhere: password changed, "sign out other sessions", or an administrator.
            Session::destroy();
            Session::start();
            Session::flash('info', 'You were signed out of this session. Please sign in again.');
            self::$user = null;
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
        if ($user['role'] === 'admin' && !self::adminNetworkAllowed()) {
            self::logout('administrator network restriction');
            Authz::deny('administration from an untrusted network');
        }
        $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $passwordSession = in_array($_SESSION['auth'] ?? 'password', ['password', 'password+mfa', 'password+email', 'password+trusted'], true);
        if ($user['must_change_password'] && $passwordSession && $page !== 'account.php') {
            header('Location: ' . Request::url('account.php?required=1'));
            exit;
        }
        if ($passwordSession && Mfa::required($user) && !Mfa::hasFactor($user) && $page !== 'account.php') {
            header('Location: ' . Request::url('account.php?mfa=required'));
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

    /** @return string|null error message, or null when the password is acceptable (see PasswordPolicy) */
    public static function passwordProblem(string $password, string $username, array $person = []): ?string
    {
        return PasswordPolicy::problem($password, $person + ['username' => $username]);
    }

    public static function changePassword(array $user, string $current, string $new): ?string
    {
        $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            return 'Your current password is incorrect.';
        }
        if ($problem = self::passwordProblem($new, $user['username'], $user)) {
            return $problem;
        }
        if (password_verify($new, $row['password_hash'])) {
            return 'Choose a password different from the current one.';
        }
        Db::update('users', ['password_hash' => self::hash($new), 'password_changed_at' => Clock::stamp(), 'must_change_password' => 0], 'id = ?', [$user['id']]);
        Session::regenerate();
        $ended = Sessions::endAll((int) $user['id'], 'password changed', true);
        TrustedDevices::forgetAll((int) $user['id']);
        Audit::record('auth.password_changed', 'user', $user['id'], "{$user['full_name']} changed their password" . ($ended ? " ($ended other session(s) signed out)" : ''));
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

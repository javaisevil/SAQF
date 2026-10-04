<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Mailer;
use Saqf\Core\Policy;
use Saqf\Core\Request;
use Saqf\Core\Secrets;
use Saqf\Integration\Http;

/**
 * Live status of SAQF's security controls for the administrator's Security center: each check says
 * whether the control is in place on this installation and, if not, how to fix it. Nothing here is
 * a score: every line is a fact about the configuration or the data.
 */
final class SecurityCenter
{
    /** @return list<array{label:string,ok:?bool,status:string,fix:string}> ok: true in place, false needs attention, null advisory */
    public static function checks(): array
    {
        $prod = Config::env() === 'production';
        $https = Request::isHttps();
        $sso = Oidc::enabled();
        $mode = Auth::passwordLoginMode();
        $mfaPolicy = Policy::get('auth.mfa_required');
        $people = self::people();
        $verify = json_decode((string) Db::val('SELECT value FROM system_settings WHERE setting_key = "audit.last_verify"'), true);
        $verifiedRecently = $verify && strtotime((string) $verify['at']) >= Clock::now()->getTimestamp() - 2 * 86400;
        $c = [];
        $add = static function (string $label, ?bool $ok, string $status, string $fix = '') use (&$c) {
            $c[] = ['label' => $label, 'ok' => $ok, 'status' => $status, 'fix' => $fix];
        };
        $add('Encrypted connections (HTTPS + HSTS)', $https ? true : ($prod ? false : null), $https ? 'This page was served over HTTPS; browsers are told to use HTTPS only (HSTS).' : 'Not served over HTTPS on this connection.', 'Run the stack with the HTTPS proxy (docker compose --profile https up -d) or terminate TLS at the university load balancer, and set SAQF_BASE_URL to the https address.');
        $insecure = Http::readiness();
        $mailPlain = Mailer::enabled() && strtolower((string) (Config::get('SAQF_MAIL_ENCRYPTION') ?: 'tls')) === 'none';
        $configured = array_filter(array_keys(Http::URL_SETTINGS), static fn($k) => (string) Config::get($k, '') !== '');
        $add('Encrypted connections to university systems', ($insecure || $mailPlain) ? ($prod ? false : null) : ($configured || Mailer::enabled() ? true : null),
            $insecure || $mailPlain
                ? 'Not encrypted: ' . implode('; ', array_merge(array_map(static fn($i) => $i['label'] . ' (' . $i['setting'] . ': ' . $i['problem'] . ')', $insecure), $mailPlain ? ['e-mail (SAQF_MAIL_ENCRYPTION=none)'] : [])) . '.' . ($prod ? ' SAQF refuses plain http:// in production.' : ' Allowed only outside production (local stand-in servers).')
                : ($configured || Mailer::enabled() ? 'Every configured university-system address uses https:// (TLS certificates verified, redirects not followed); e-mail uses TLS. Production refuses plain http://.' : 'No university system is connected yet (demo data); production will refuse plain http:// addresses.'),
            'Use https:// addresses for the SIS, LMS, identity provider and webhook, and SAQF_MAIL_ENCRYPTION=tls or ssl.');
        $add('Production mode', $prod && !Config::debug() ? true : ($prod ? false : null), $prod ? (Config::debug() ? 'APP_DEBUG is on — error details could leak.' : 'Production mode, debug off, demo features disabled.') : 'Demo/local mode (fictional data, demo shortcuts on).', 'Set APP_ENV=production and APP_DEBUG=false.');
        $key = Secrets::keyStatus();
        $add('Application key', $key['ok'], $key['message'], 'Generate a key with php bin/app_key.php generate (new installation) or move the stored one (php bin/app_key.php export-stored), keep it in the password vault and set SAQF_APP_KEY. Back it up separately from the database: without it pseudonyms stop matching and two-step keys cannot be read.');
        $add('University single sign-on', $sso ? true : ($prod ? false : null), $sso ? 'OpenID Connect sign-in with ' . parse_url((string) Config::get('SAQF_OIDC_ISSUER'), PHP_URL_HOST) . ' (PKCE, signed ID tokens, nonce).' : 'Not configured: people sign in with SAQF passwords.', 'Register SAQF with the university identity provider and set SAQF_OIDC_* (docs/INTEGRATIONS.md).');
        $lastMfa = Oidc::lastMfaObservation();
        $observed = $lastMfa ? ' Last university sign-in (' . date('j M H:i', (int) $lastMfa['at']) . '): the identity provider ' . ['yes' => 'reported two-step verification', 'no' => 'reported NO two-step verification', 'not_reported' => 'did not report how the person signed in'][$lastMfa['reported']] . '.' : '';
        $add('Two-step verification for university sign-in', $sso ? (Oidc::requireMfa() ? true : null) : null,
            !$sso ? 'Not applicable: university sign-in is not configured.'
                : (Oidc::requireMfa()
                    ? 'SAQF refuses university sign-ins whose ID token does not report two-step verification (amr contains "mfa"). The second factor itself is checked by the identity provider, not by SAQF.' . $observed
                    : 'Not enforced by SAQF: for university sign-in, two-step verification is the identity provider\'s policy (e.g. Entra ID Conditional Access). University IT must confirm that policy covers SAQF.' . $observed),
            'Ask IT to confirm the identity provider requires MFA for SAQF; if its ID tokens carry the "amr" claim, set SAQF_OIDC_REQUIRE_MFA=true so SAQF also refuses sign-ins without it.');
        $add('Password sign-in restricted', $sso ? in_array($mode, ['admins', 'off'], true) : null, 'Password sign-in: ' . $mode . ($sso ? '' : ' (no SSO, so everyone uses passwords)'), 'With SSO on, set SAQF_PASSWORD_LOGIN=admins so only administrators keep a break-glass password.');
        $add('Two-step verification policy', $mfaPolicy === 'all' ? true : ($mfaPolicy === 'admins' ? null : false), 'Required for: ' . ['off' => 'nobody (optional)', 'admins' => 'administrators', 'all' => 'everyone signing in with a password (a code by e-mail or an authenticator app; administrators must use the app)'][$mfaPolicy] . '.', 'Set the policy "Two-step verification required for" to all.');
        $add('Robot check on public forms', BotGuard::enabled(), BotGuard::enabled() ? 'Sign-in and password-reset forms need a solved proof-of-work puzzle (single use, ' . BotGuard::BASE_BITS . ' bits, ' . BotGuard::HARD_BITS . ' after repeated failures) and carry a hidden bot trap. Self-hosted: nothing is sent to a third party.' : 'Switched off (SAQF_BOT_CHECK=off): scripts can try passwords as fast as the lockout allows.', 'Remove SAQF_BOT_CHECK=off.');
        $days = (int) Policy::get('auth.trusted_device_days');
        $add('Trusted browsers', $days <= 30 ? true : null, $days > 0 ? 'People may skip the code on a browser they trusted, for ' . $days . ' days (never administrators; a password change forgets every trusted browser). ' . (int) Db::val('SELECT COUNT(*) FROM trusted_devices WHERE revoked_at IS NULL AND expires_at > ?', [Clock::stamp()]) . ' trusted now.' : 'Off: every password sign-in asks for a code.', 'Keep "Trust a browser after two-step verification" at 30 days or less.');
        $add('Administrators use two-step verification', $people['admins_local'] === 0 || $people['admins_mfa'] >= $people['admins_local'], $people['admins_mfa'] . ' of ' . $people['admins_local'] . ' password-using administrator(s) have it on.', 'Each administrator sets it up under Account & security (they are prompted at their next sign-in).');
        $add('Administrator network restriction', Config::get('SAQF_ADMIN_ALLOWED_IPS') ? true : null, Config::get('SAQF_ADMIN_ALLOWED_IPS') ? 'Administration only from ' . Config::get('SAQF_ADMIN_ALLOWED_IPS') . '.' : 'Administrators can sign in from any network.', 'Set SAQF_ADMIN_ALLOWED_IPS to the campus/VPN ranges (e.g. 10.0.0.0/8).');
        $add('Strong passwords', Policy::get('auth.min_password_length') >= 10, 'At least ' . Policy::get('auth.min_password_length') . ' characters; common words, keyboard runs, names and the university name refused; Argon2id hashing' . (defined('PASSWORD_ARGON2ID') ? '' : ' (bcrypt: Argon2 not available in this PHP build)') . '.', 'Raise the minimum password length policy to 10 or more.');
        $add('Brute-force protection', Policy::get('auth.max_failed_logins') <= 10, 'Lockout after ' . Policy::get('auth.max_failed_logins') . ' failures for ' . Policy::get('auth.lockout_minutes') . ' min; network throttle at ' . Policy::get('auth.ip_max_attempts_15min') . ' failures per 15 min; rate limits on changes, uploads and exports.', 'Keep "Failed logins before lockout" at 10 or fewer.');
        $add('Session limits', Policy::get('session.idle_minutes') <= 60 && Policy::get('session.absolute_hours') <= 12, Policy::get('session.idle_minutes') . ' min idle, ' . Policy::get('session.absolute_hours') . ' h maximum; sessions listed per person and can be ended remotely; a password change signs out other sessions.', 'Keep the idle timeout at 60 minutes or less and the maximum at 12 hours or less.');
        $add('Re-confirmation for sensitive changes', Policy::get('security.reauth_minutes') <= 30, 'Access changes need a sign-in or identity confirmation within ' . Policy::get('security.reauth_minutes') . ' minutes.', 'Keep the re-confirmation window at 30 minutes or less.');
        $add('Browser protections', true, 'Strict Content-Security-Policy with no inline scripts, frame blocking, CSRF tokens on every change, SameSite=Strict cookies.');
        $guard = self::auditGuarded();
        $add('Tamper-evident audit log', $verifiedRecently && $verify['ok'] ? true : ($verify && !$verify['ok'] ? false : null), ($verify ? ($verify['ok'] ? 'Hash chain intact (' . $verify['checked'] . ' entries, verified ' . date('j M H:i', strtotime((string) $verify['at'])) . ').' : 'BROKEN at entry #' . $verify['broken_at'] . ' — investigate now.') : 'Not verified yet.')
            . ' Tamper-evident, not tamper-proof: the chain reveals an edited or deleted entry when it is verified, but someone with database administrator rights could still change rows.'
            . ($guard ? ' Append-only database triggers are installed.' : ' Append-only database triggers were NOT found (apply database/audit_guard.sql).'),
            'The scheduler verifies the chain nightly; use "Verify audit chain" on System health now. Keep exported copies of the log off this server if independent evidence is needed.');
        $wit = Witness::external();
        $witHead = Witness::recent(1);
        $add('Audit-chain witnesses', $wit === true ? true : null,
            $witHead ? 'Last checkpoint ' . date('j M H:i', strtotime((string) $witHead[0]['taken_at'])) . ($wit ? ' was sent by ' . $witHead[0]['sent_to'] . ' (' . 'SAQF cannot see whether the message is being kept).' : ' stayed on this server: no e-mail or webhook is configured, so it cannot prove anything against someone with database access.') : 'No checkpoint has been taken yet (the scheduler takes one every night).',
            'Configure SAQF_MAIL_* or SAQF_ALERT_WEBHOOK and keep the messages (a mailbox rule or channel with retention). Verify with: php bin/verify_audit.php --witness "<line>".');
        $drill = self::restoreDrill();
        $add('Restore drill', $drill !== null && !empty($drill['ok']) && time() - strtotime((string) $drill['finished_at']) < 100 * 86400 ? true : null,
            $drill === null ? 'No restore drill has been recorded: nobody has shown that these backups can actually be restored.' : 'Last restore drill ' . (!empty($drill['ok']) ? 'passed' : 'FAILED') . ' at ' . $drill['finished_at'] . ' (' . ($drill['message'] ?? '') . '). It restores the newest backup into a scratch database server and checks the data; it does not prove the hardware, the network path or the recovery time.',
            'Run docker/restore_drill.sh (docs/OPERATIONS.md) at least quarterly.');
        $open = (int) Db::val('SELECT COUNT(*) FROM security_incidents WHERE status <> "closed"');
        $add('Incident register', true, ($open ? $open . ' open incident(s). ' : 'No open incident. ') . 'Security incidents can be registered with a notification clock for personal-data breaches; the decisions stay with the data protection officer.', '');
        $add('Backups', self::backupOk(), self::backupLine(), 'Run the backup service (docker compose) with SAQF_BACKUP_PASSPHRASE set (encryption) and SAQF_BACKUP_OFFSITE_PATH pointing at a share on another server; then do a restore drill (docker/restore.sh) and record it.');
        $scans = self::scans();
        $scanLine = $scans['total'] ? ' Recorded so far: ' . $scans['clean'] . ' of ' . $scans['total'] . ' stored file(s) scanned clean, ' . $scans['unscanned'] . ' stored without a scan.' : ' No evidence files stored yet.';
        $add('Evidence virus scanning', Config::get('SAQF_CLAMAV_HOST') ? ($scans['unscanned'] ? null : true) : null, (Config::get('SAQF_CLAMAV_HOST')
                ? 'A ClamAV scanner is configured (' . Config::get('SAQF_CLAMAV_HOST') . '): new uploads are scanned and refused while it is unreachable. Detection depends on the scanner\'s signature updates, which SAQF does not check.'
                : 'Optional control NOT configured: uploaded files are checked by type and content only, not scanned for malware.') . $scanLine,
            'Run a clamd service (docker compose --profile antivirus) and set SAQF_CLAMAV_HOST=host:3310; the university\'s malware-scanning policy decides whether this is required.');
        $add('Proxy trust', !(Config::bool('SAQF_TRUST_PROXY') && !Config::get('SAQF_TRUSTED_PROXIES')), Config::get('SAQF_TRUSTED_PROXIES') ? 'Forwarded addresses trusted only from ' . Config::get('SAQF_TRUSTED_PROXIES') . '.' : (Config::bool('SAQF_TRUST_PROXY') ? 'Forwarded headers trusted from any address.' : 'No proxy trusted (direct connections).'), 'Set SAQF_TRUSTED_PROXIES to the proxy\'s address instead of SAQF_TRUST_PROXY=true.');
        $add('IT alerting', Mailer::enabled() || (bool) Config::get('SAQF_ALERT_WEBHOOK'), Mailer::enabled() ? 'Alerts reach administrators in SAQF and by e-mail' . (Config::get('SAQF_ALERT_WEBHOOK') ? ' and the webhook.' : '.') : (Config::get('SAQF_ALERT_WEBHOOK') ? 'Alerts reach administrators in SAQF and the webhook.' : 'Alerts are shown in SAQF only.'), 'Configure e-mail (SAQF_MAIL_*) or SAQF_ALERT_WEBHOOK (Teams/Slack).');
        $add('Student privacy', true, 'Student identifiers from every grade source (Moodle, Blackboard, the LMS export folder and the manual gradebook upload) are replaced by keyed pseudonyms before storage, and keys that look like raw student numbers are refused; no student names are kept. The pseudonyms are only as private as the application key (see "Application key").' . (Config::get('SAQF_LMS_PSEUDONYMIZE') !== null && !Config::bool('SAQF_LMS_PSEUDONYMIZE', true) ? ' SAQF_LMS_PSEUDONYMIZE=false is set but ignored: there is no opt-out.' : ''));
        $review = AccessReview::progress();
        $add('Access review', $review['due'] === 0, $review['due'] === 0 ? 'Everyone\'s access was confirmed by an administrator within the last ' . $review['days'] . ' days (' . $review['total'] . ' accounts).' : $review['due'] . ' of ' . $review['total'] . ' account(s) are due for an access review (at least every ' . $review['days'] . ' days, and after a role change).', 'Administration → Access review: confirm or remove each person\'s access (another administrator must review your own).');
        $add('Second administrator', $people['admins'] >= 2 ? true : null, $people['admins'] >= 2 ? $people['admins'] . ' administrators, so each can review the other\'s access.' : 'Only one administrator account: nobody else can review or recover it.', 'Create a second administrator (Users & access) so access can be reviewed independently and the system is never locked out.');
        $selfTest = SelfTest::last();
        $add('Security self-test (automated)', $selfTest ? $selfTest['passed'] === $selfTest['total'] : null, ($selfTest ? $selfTest['passed'] . ' of ' . $selfTest['total'] . ' self-checks passed when last run (' . date('j M H:i', strtotime($selfTest['at'])) . ').' : 'Not run yet.') . ' A self-test, not a penetration test or an independent review.', 'Press "Run security self-test" in the Security center.');
        $add('Dormant accounts', !$people['dormant'], $people['dormant'] ? count($people['dormant']) . ' active account(s) unused for more than ' . Policy::get('auth.dormant_days') . ' days.' : 'No active account unused for more than ' . Policy::get('auth.dormant_days') . ' days.', 'Disable accounts that are no longer needed (Users & access).');
        return $c;
    }

    /** @return array{total:int,clean:int,unscanned:int} scan results recorded for the stored evidence files */
    public static function scans(): array
    {
        $r = Db::one('SELECT COUNT(*) AS total, SUM(scan_status = "clean") AS clean FROM evidence_files WHERE deleted_at IS NULL') ?: [];
        $total = (int) ($r['total'] ?? 0);
        $clean = (int) ($r['clean'] ?? 0);
        return ['total' => $total, 'clean' => $clean, 'unscanned' => $total - $clean];
    }

    /** True when both append-only triggers exist on the audit log (read from the database, not assumed). */
    public static function auditGuarded(): bool
    {
        try {
            return (int) Db::val('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = "audit_log"') >= 2;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array{admins:int,admins_local:int,admins_mfa:int,sessions:int,locked:int,dormant:list<string>} */
    public static function people(): array
    {
        $idle = Clock::now()->modify('-' . Policy::get('session.idle_minutes') . ' minutes')->format('Y-m-d H:i:s');
        $days = Policy::get('auth.dormant_days');
        $cutoff = Clock::now()->modify("-$days days")->format('Y-m-d H:i:s');
        return [
            'admins' => (int) Db::val('SELECT COUNT(*) FROM users WHERE role = "admin" AND status <> "disabled"'),
            'admins_local' => (int) Db::val('SELECT COUNT(*) FROM users WHERE role = "admin" AND status <> "disabled" AND auth_source <> "sso"'),
            'admins_mfa' => (int) Db::val('SELECT COUNT(*) FROM users WHERE role = "admin" AND status <> "disabled" AND auth_source <> "sso" AND mfa_enabled_at IS NOT NULL'),
            'sessions' => (int) Db::val('SELECT COUNT(*) FROM user_sessions WHERE ended_at IS NULL AND last_seen_at >= ?', [$idle]),
            'locked' => (int) Db::val('SELECT COUNT(*) FROM users WHERE status = "locked" AND locked_until > ?', [Clock::stamp()]),
            'dormant' => $days > 0 ? array_map('strval', Db::col('SELECT username FROM users WHERE status = "active" AND COALESCE(last_login_at, created_at) < ? ORDER BY username', [$cutoff])) : [],
        ];
    }

    /** @return array<string,int> */
    public static function week(): array
    {
        $since = Clock::now()->modify('-7 days')->format('Y-m-d H:i:s');
        $audit = static fn(string $action) => (int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action = ? AND occurred_at >= ?', [$action, $since]);
        return [
            'Failed sign-ins' => (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= ?', [$since]),
            'Accounts locked' => $audit('security.account_locked'),
            'Access denied (blocked attempts)' => $audit('security.access_denied'),
            'Sign-ins from a new device' => $audit('security.new_device'),
            'Rate limits reached' => $audit('security.rate_limited'),
            'Wrong verification codes' => (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE reason = "bad_mfa_code" AND created_at >= ?', [$since]),
            'Robot checks failed' => (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE reason LIKE "bot\\_%" AND created_at >= ?', [$since]),
            'Suspicious sign-ins reported by people' => $audit('security.reported_suspicious'),
        ];
    }

    /** Backup status written by docker/backup.sh into the (read-only) backup folder. */
    public static function backupStatus(): ?array
    {
        $file = rtrim((string) (Config::get('SAQF_BACKUP_MONITOR_DIR') ?: SAQF_ROOT . '/storage/backups'), '/') . '/last-backup.json';
        if (!is_file($file)) {
            return null;
        }
        $s = json_decode((string) file_get_contents($file), true);
        return is_array($s) ? $s : null;
    }

    /**
     * Healthy only when the last recorded run is recent, succeeded, read its archives back, and covered
     * the evidence files whenever SAQF holds any. In production a backup that is not encrypted or has no
     * verified second copy is not healthy either; elsewhere that is reported as a note (null).
     * Everything is judged from what the backup run recorded, never from the configuration alone.
     */
    /** Last restore drill written by docker/restore_drill.sh into the backup status folder (null when none). */
    public static function restoreDrill(): ?array
    {
        $file = rtrim((string) (Config::get('SAQF_BACKUP_MONITOR_DIR') ?: SAQF_ROOT . '/storage/backups'), '/') . '/last-restore-drill.json';
        if (!is_file($file)) {
            return null;
        }
        $s = json_decode((string) file_get_contents($file), true);
        return is_array($s) ? $s : null;
    }

    public static function backupOk(): ?bool
    {
        $s = self::backupStatus();
        $prod = Config::env() === 'production';
        if ($s === null) {
            return $prod ? false : null;
        }
        if (($s['status'] ?? '') !== 'ok' || empty($s['verified']) || time() - strtotime((string) ($s['finished_at'] ?? '1970-01-01')) >= 26 * 3600) {
            return false;
        }
        if (empty($s['files']) && self::evidenceHeld()) {
            return false;
        }
        if (empty($s['encrypted']) || empty($s['offsite'])) {
            return $prod ? false : null;
        }
        return true;
    }

    public static function backupLine(): string
    {
        $s = self::backupStatus();
        if ($s === null) {
            return 'No backup has reported to SAQF (no status file in ' . (Config::get('SAQF_BACKUP_MONITOR_DIR') ?: 'storage/backups') . '). SAQF cannot confirm any backup exists: run the backup service or mount its status folder.';
        }
        if (($s['status'] ?? '') !== 'ok') {
            return 'LAST BACKUP FAILED at ' . ($s['finished_at'] ?? '?') . (!empty($s['message']) ? ' (' . $s['message'] . ')' : '') . ' — see the backup service log (docker compose logs backup).';
        }
        $age = time() - strtotime((string) $s['finished_at']);
        $files = $s['files_status'] ?? (!empty($s['files']) ? 'ok' : 'not_configured');
        return 'Last backup ' . ($age < 3600 ? floor($age / 60) . ' min' : floor($age / 3600) . ' h') . ' ago (' . ($s['file'] ?? '') . ', ' . round(((int) ($s['bytes'] ?? 0)) / 1048576, 1) . ' MB)'
            . (!empty($s['verified']) ? ' · database dump read back after writing' : ' · NOT verified')
            . (!empty($s['encrypted']) ? ' · encrypted (decrypted again to verify)' : ' · NOT encrypted')
            . (!empty($s['offsite']) ? ' · copied to a second location and its checksum re-checked there (SAQF cannot tell whether that location is on another machine)' : ' · no second copy')
            . ($files === 'ok' ? ' · evidence files included' : ($files === 'failed' ? ' · evidence files FAILED' : ' · evidence files NOT included' . (self::evidenceHeld() ? ' although SAQF holds evidence' : '')))
            . ' · a restore has not been tested from SAQF (run the restore drill in docs/OPERATIONS.md).';
    }

    /** True when the evidence store holds at least one current file (so a backup must include it). */
    private static function evidenceHeld(): bool
    {
        try {
            return (bool) Db::val('SELECT 1 FROM evidence_files WHERE deleted_at IS NULL LIMIT 1');
        } catch (\Throwable $e) {
            return false;
        }
    }
}

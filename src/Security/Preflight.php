<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Mailer;
use Saqf\Core\Migrations;
use Saqf\Core\Policy;
use Saqf\Core\Secrets;
use Saqf\Demo\Story;
use Saqf\Integration\Http;
use Saqf\Integration\Integrations;
use Saqf\Quality\Evidence;

/**
 * Production preflight: a go / no-go list for the moment before SAQF is exposed to real users.
 * It judges THIS installation against the production rules whatever mode it currently runs in, so IT
 * can run it on staging and see exactly what would block go-live. Every line is a fact read from the
 * configuration, the database or the file system; nothing is assumed, and secrets are never shown.
 *
 *   blocker  must be fixed before real users or real data
 *   warning  should be fixed or consciously accepted (and written down)
 *   info     good to know
 * A passing preflight does not mean the installation is secure or approved: it means none of these
 * known pre-conditions is missing. It is not a penetration test or an audit.
 */
final class Preflight
{
    /** @return list<array{id:string,severity:string,pass:bool,title:string,detail:string,fix:string}> */
    public static function run(): array
    {
        $out = [];
        $add = static function (string $id, string $severity, bool $pass, string $title, string $detail, string $fix = '') use (&$out) {
            $out[] = ['id' => $id, 'severity' => $severity, 'pass' => $pass, 'title' => $title, 'detail' => $detail, 'fix' => $fix];
        };
        $prod = Config::env() === 'production';

        $add('mode', 'blocker', $prod && !Config::debug(), 'Production mode, debug off',
            $prod ? (Config::debug() ? 'APP_DEBUG is on: error details could leak.' : 'APP_ENV=production, APP_DEBUG off.') : 'APP_ENV is "' . Config::env() . '".',
            'Set APP_ENV=production and APP_DEBUG=false.');

        // Demo accounts with the published password are the most embarrassing way to be breached.
        $demoUsers = [];
        foreach (Db::all('SELECT username, password_hash FROM users WHERE status <> "disabled"') as $u) {
            if ($u['password_hash'] && password_verify(Story::PASSWORD, (string) $u['password_hash'])) {
                $demoUsers[] = $u['username'];
            }
        }
        $add('demo_accounts', 'blocker', !$demoUsers && !Config::demoMode(), 'No demo accounts or demo shortcuts',
            $demoUsers ? count($demoUsers) . ' active account(s) still use the published demo password (' . implode(', ', array_slice($demoUsers, 0, 5)) . (count($demoUsers) > 5 ? ', …' : '') . ').' : (Config::demoMode() ? 'Demo mode is on.' : 'No account uses the published demo password and demo mode is off.'),
            'Install with SAQF_AUTO_INSTALL=production on an empty database (not --demo), or disable these accounts.');

        $base = (string) Config::get('SAQF_BASE_URL', '');
        $add('https_base', 'blocker', stripos($base, 'https://') === 0, 'Public address is https://',
            $base === '' ? 'SAQF_BASE_URL is not set.' : 'SAQF_BASE_URL is ' . (stripos($base, 'https://') === 0 ? 'https.' : 'not https.'),
            'Set SAQF_BASE_URL=https://your-domain and serve SAQF through the HTTPS proxy or the university load balancer.');

        $key = Secrets::keyStatus();
        $add('app_key', 'blocker', $key['ok'] === true, 'Application key from the environment', $key['message'], 'php bin/app_key.php generate; store it in the vault; set SAQF_APP_KEY (or SAQF_APP_KEY_FILE).');

        $bad = Http::readiness();
        $add('https_out', 'blocker', !$bad, 'Outbound connections use https://',
            $bad ? 'Plain http:// in ' . implode(', ', array_column($bad, 'setting')) . '.' : 'Every configured outbound address uses https://.', 'Use https:// addresses.');

        $add('audit_guard', 'blocker', SecurityCenter::auditGuarded(), 'Audit log protected by append-only triggers',
            SecurityCenter::auditGuarded() ? 'Both triggers exist on audit_log.' : 'The append-only triggers are missing.', 'Apply database/audit_guard.sql with a privileged account.');

        $verify = Audit::verify();
        $add('audit_chain', 'blocker', $verify['ok'], 'Audit chain verifies', $verify['message'], 'Investigate before go-live (docs/OPERATIONS.md, incident checklist).');

        $pending = Migrations::pending();
        $add('migrations', 'blocker', !$pending, 'Database migrations applied', $pending ? count($pending) . ' pending.' : 'Up to date.', 'php bin/migrate.php');

        $people = SecurityCenter::people();
        $add('admin_mfa', 'blocker', $people['admins_local'] === 0 || $people['admins_mfa'] >= $people['admins_local'], 'Password-using administrators have two-step verification',
            $people['admins_mfa'] . ' of ' . $people['admins_local'] . ' password-using administrator(s).', 'Each administrator sets it up under Account & security.');
        $add('second_admin', 'warning', $people['admins'] >= 2, 'A second administrator exists', $people['admins'] . ' administrator account(s).', 'Create a second administrator so access can be reviewed independently.');
        $add('mfa_policy', 'warning', Policy::get('auth.mfa_required') === 'all', 'Two-step verification required for everyone using a password', 'Policy auth.mfa_required = ' . Policy::get('auth.mfa_required') . '.', 'Set the policy to "all".');

        $sso = Oidc::enabled();
        $add('sso', 'warning', $sso, 'University single sign-on configured', $sso ? 'OpenID Connect issuer and client are set. MFA for SSO is the identity provider\'s policy; IT must confirm it.' : 'Not configured: everyone would use SAQF passwords.', 'Set SAQF_OIDC_* (docs/INTEGRATIONS.md).');
        if ($sso) {
            $add('password_login', 'warning', in_array(Auth::passwordLoginMode(), ['admins', 'off'], true), 'Password sign-in restricted when SSO is on', 'SAQF_PASSWORD_LOGIN=' . Auth::passwordLoginMode() . '.', 'Set SAQF_PASSWORD_LOGIN=admins.');
        }

        $backup = SecurityCenter::backupOk();
        $add('backup', 'blocker', $backup === true, 'Complete, verified, encrypted backup with a second copy', SecurityCenter::backupLine(), 'Run the backup service with SAQF_BACKUP_PASSPHRASE and SAQF_BACKUP_OFFSITE_PATH.');
        $drill = SecurityCenter::restoreDrill();
        $add('restore_drill', 'warning', $drill !== null && !empty($drill['ok']) && time() - strtotime((string) $drill['finished_at']) < 100 * 86400, 'A restore drill passed in the last 100 days',
            $drill === null ? 'No restore drill has been recorded.' : ('Last restore drill ' . (!empty($drill['ok']) ? 'passed' : 'FAILED') . ' at ' . $drill['finished_at'] . '.'), 'Run docker/restore_drill.sh (docs/OPERATIONS.md).');

        $tick = Db::val('SELECT value FROM system_settings WHERE setting_key = "scheduler.last_tick"');
        $add('scheduler', 'warning', $tick && Clock::now()->getTimestamp() - strtotime((string) $tick) <= 1200, 'Scheduler ran in the last 20 minutes', $tick ? 'Last run ' . $tick . '.' : 'The scheduler has never run.', 'Run the container loop or cron (php bin/tick.php every 5 minutes).');

        $alerts = Mailer::enabled() || (bool) Config::get('SAQF_ALERT_WEBHOOK');
        $add('alerting', 'warning', $alerts, 'IT alerts reach people outside SAQF', $alerts ? 'E-mail and/or webhook configured.' : 'Alerts are only shown inside SAQF.', 'Configure SAQF_MAIL_* or SAQF_ALERT_WEBHOOK.');
        $add('witness', 'warning', (bool) Config::get('SAQF_ALERT_WEBHOOK') || Mailer::enabled(), 'Audit-chain witnesses leave the server', $alerts ? 'The nightly audit-chain head is sent to IT, so a rewritten database cannot also rewrite history.' : 'No e-mail or webhook, so the audit-chain head is not witnessed outside this server.', 'Configure SAQF_MAIL_* or SAQF_ALERT_WEBHOOK.');

        $scan = (string) Config::get('SAQF_CLAMAV_HOST', '') !== '';
        $add('malware', 'warning', $scan, 'Evidence uploads are malware-scanned', $scan ? 'A ClamAV scanner is configured.' : 'No scanner: uploads are checked by type and content only.', 'Run --profile antivirus and set SAQF_CLAMAV_HOST, or document the university\'s own control.');

        $review = AccessReview::progress();
        $add('access_review', 'warning', $review['due'] === 0, 'Access review is current', $review['current'] . ' of ' . $review['total'] . ' accounts confirmed in ' . $review['days'] . ' days.', 'Administration → Access review.');
        $self = SelfTest::last();
        $add('self_test', 'warning', $self !== null && $self['passed'] === $self['total'], 'Security self-test passes', $self ? $self['passed'] . ' of ' . $self['total'] . ' (' . $self['at'] . '). A self-test, not a penetration test.' : 'Not run yet.', 'Security center → Run security self-test.');

        // File system -------------------------------------------------------------------------------
        $evid = Evidence::dir();
        $inWeb = str_starts_with(realpath($evid) ?: $evid, SAQF_ROOT . '/public');
        $add('evidence_dir', 'blocker', !$inWeb && is_dir($evid) && is_writable($evid), 'Evidence store outside the web root and writable', $inWeb ? 'The evidence folder is inside public/.' : (is_dir($evid) && is_writable($evid) ? $evid . ' is writable and not served.' : $evid . ' is missing or not writable.'), 'Mount a volume at SAQF_STORAGE_DIR.');
        $loose = [];
        foreach (['.env', 'config.local.php'] as $f) {
            $p = SAQF_ROOT . '/' . $f;
            if (is_file($p) && (fileperms($p) & 0004)) {
                $loose[] = $f;
            }
        }
        $add('secret_files', 'warning', !$loose, 'Secret files are not world-readable', $loose ? implode(', ', $loose) . ' readable by every user on this machine.' : 'No world-readable .env or config.local.php.', 'chmod 600 .env config.local.php (or use *_FILE secrets).');
        $fromFile = array_values(array_filter(Config::SECRET_KEYS, static fn($k) => Config::isFromFile($k)));
        $add('secrets_files', 'info', true, 'Secrets supplied from files', $fromFile ? count($fromFile) . ' secret(s) come from *_FILE (never shown here).' : 'No *_FILE secrets in use; secrets come from the environment.', 'Docker secrets / Kubernetes secrets: set SAQF_APP_KEY_FILE, SAQF_DB_PASS_FILE …');

        // Data sources -------------------------------------------------------------------------------
        $add('sis_real', 'info', Integrations::sisKind() !== 'demo', 'SIS is not the demo feed', 'SIS source: ' . Integrations::sisKind() . '.', 'docs/INTEGRATIONS.md');
        $add('lms_real', 'info', Integrations::lmsKind() !== 'demo', 'LMS is not the demo feed', 'LMS source: ' . Integrations::lmsKind() . '.', 'docs/INTEGRATIONS.md');
        $add('contact', 'warning', (string) Config::get('SAQF_SECURITY_CONTACT', '') !== '', 'A security contact is published', (string) Config::get('SAQF_SECURITY_CONTACT', '') !== '' ? 'Published at /.well-known/security.txt.' : 'SAQF_SECURITY_CONTACT is not set, so security.txt names no contact.', 'Set SAQF_SECURITY_CONTACT=mailto:security@your-university.edu');
        return $out;
    }

    /** @return array{blockers:int,warnings:int,total:int,ready:bool} */
    public static function summary(array $checks): array
    {
        $blockers = count(array_filter($checks, static fn($c) => !$c['pass'] && $c['severity'] === 'blocker'));
        $warnings = count(array_filter($checks, static fn($c) => !$c['pass'] && $c['severity'] === 'warning'));
        return ['blockers' => $blockers, 'warnings' => $warnings, 'total' => count($checks), 'ready' => $blockers === 0];
    }
}

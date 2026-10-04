<?php
declare(strict_types=1);

namespace Saqf\Integration;

use Saqf\Core\Config;
use Saqf\Core\Mailer;
use Saqf\Security\Oidc;

/**
 * "Ready to connect" status for the administrator: for each university system, whether SAQF is
 * running on demo data or on the live system, what is still missing, and the exact next step.
 * Values of secrets are never read out: only whether a setting is present.
 */
final class GoLive
{
    /**
     * @return list<array{key:string,label:string,mode:string,headline:string,detail:string,missing:list<string>,next:string,test:?array}>
     *   mode: live | demo | off
     */
    public static function systems(bool $test = false): array
    {
        $has = static fn(string $k) => (string) Config::get($k, '') !== '';
        $missing = static fn(array $keys) => array_values(array_filter($keys, static fn($k) => !$has($k)));
        $out = [];

        // Registrar catalogue --------------------------------------------------------------
        $cat = Integrations::institution();
        $dir = $cat instanceof CatalogFileSource ? $cat->dir() : '';
        $bundled = $dir === SAQF_ROOT . '/data/yu';
        $report = $dir !== '' ? DataPack::inspect($dir) : null;
        $s = $report['summary'] ?? [];
        $out[] = [
            'key' => 'catalogue', 'label' => 'Registrar: programs and study plans',
            'mode' => $bundled ? 'demo' : 'live',
            'headline' => $bundled ? 'YU\'s published study plans (bundled data)' : 'Registrar export in use',
            'detail' => $report ? (($report['ok'] ? 'Passes every check' : count($report['errors']) . ' problem(s) found') . ': ' . ($s['programs'] ?? 0) . ' programs, ' . ($s['courses'] ?? 0) . ' courses, ' . ($s['program outcomes'] ?? 0) . ' program outcomes.') : $cat->label(),
            'missing' => [],
            'next' => $bundled ? 'Registrar: send the catalogue in the same layout (download the template below), put it in storage/inbox/catalog and press "Check the catalogue". SAQF uses it from the next daily update.' : 'Nothing to do; it is refreshed daily.',
            'test' => $test && $report ? ['ok' => $report['ok'], 'message' => $report['ok'] ? 'The data pack is complete and consistent.' : $report['errors'][0]] : null,
        ];

        // SIS --------------------------------------------------------------------------------
        $kind = Integrations::sisKind();
        $miss = $kind === 'rest' ? $missing(['SAQF_SIS_URL', 'SAQF_SIS_TOKEN']) : [];
        $out[] = [
            'key' => 'sis', 'label' => 'Student information system (timetable)',
            'mode' => $kind === 'demo' ? 'demo' : ($kind === 'none' ? 'off' : 'live'),
            'headline' => ['demo' => 'Simulated university timetable', 'file' => 'Nightly export files', 'rest' => 'SIS integration API', 'none' => 'Switched off'][$kind],
            'detail' => $kind === 'rest' ? ($miss ? 'Missing: ' . implode(', ', $miss) : 'URL and token are set.') : ($kind === 'file' ? 'Reads terms.csv and assignments.csv from the SIS drop folder.' : ($kind === 'demo' ? 'Fictional teaching assignments drive the demonstration.' : 'Terms are added by hand and courses assigned by Heads of Department.')),
            'missing' => $miss,
            'next' => $kind === 'demo' ? 'IT: either place the SIS export (templates below) in storage/inbox/sis and set SAQF_SIS_SOURCE=file, or set SAQF_SIS_SOURCE=rest with SAQF_SIS_URL and SAQF_SIS_TOKEN.' : ($miss ? 'IT: set ' . implode(' and ', $miss) . '.' : 'Press "Test connections".'),
            'test' => $test ? self::safeCheck(static fn() => Integrations::sis()->check()) : null,
        ];

        // LMS --------------------------------------------------------------------------------
        $kind = Integrations::lmsKind();
        $miss = $kind === 'moodle' ? $missing(['SAQF_MOODLE_URL', 'SAQF_MOODLE_TOKEN']) : ($kind === 'blackboard' ? $missing(['SAQF_BLACKBOARD_URL', 'SAQF_BLACKBOARD_KEY', 'SAQF_BLACKBOARD_SECRET']) : []);
        $out[] = [
            'key' => 'lms', 'label' => 'Learning management system (grades)',
            'mode' => $kind === 'demo' ? 'demo' : ($kind === 'none' ? 'off' : 'live'),
            'headline' => ['demo' => 'Simulated gradebooks', 'moodle' => 'Moodle', 'blackboard' => 'Blackboard Learn', 'file' => 'Gradebook export files', 'none' => 'Switched off'][$kind],
            'detail' => $miss ? 'Missing: ' . implode(', ', $miss) : ($kind === 'demo' ? 'Fictional pseudonymous results for the demonstration courses.' : ($kind === 'none' ? 'Instructors upload gradebook CSV files in their course.' : 'Settings present; student identifiers are pseudonymised before storage.')),
            'missing' => $miss,
            'next' => $kind === 'demo' ? 'IT: create a read-only grade token in the LMS and set SAQF_LMS_SOURCE=moodle (or blackboard) with its URL and credentials; the course-ID pattern is SAQF_LMS_COURSE_KEY.' : ($miss ? 'IT: set ' . implode(' and ', $miss) . '.' : 'Press "Test connections".'),
            'test' => $test ? self::safeCheck(static fn() => Integrations::lms()->check()) : null,
        ];

        // Sign-in and mail -------------------------------------------------------------------
        $sso = Oidc::enabled();
        $miss = $sso ? $missing(['SAQF_OIDC_CLIENT_SECRET', 'SAQF_BASE_URL']) : [];
        $out[] = [
            'key' => 'sso', 'label' => 'University sign-in (single sign-on)',
            'mode' => $sso ? 'live' : 'demo',
            'headline' => $sso ? 'OpenID Connect with ' . parse_url((string) Config::get('SAQF_OIDC_ISSUER'), PHP_URL_HOST) : 'SAQF passwords (demo accounts)',
            'detail' => $sso ? ($miss ? 'Missing: ' . implode(', ', $miss) : 'Issuer and client are set; people sign in with their university account.') : 'People sign in with accounts created in SAQF.',
            'missing' => $miss,
            'next' => $sso ? ($miss ? 'IT: set ' . implode(' and ', $miss) . '.' : 'Nothing to do.') : 'IT: register SAQF in Microsoft Entra ID (or Google / Keycloak / ADFS), then set SAQF_OIDC_ISSUER, SAQF_OIDC_CLIENT_ID, SAQF_OIDC_CLIENT_SECRET and SAQF_BASE_URL.',
            'test' => null,
        ];
        $mail = Mailer::enabled();
        $out[] = [
            'key' => 'mail', 'label' => 'E-mail (digests, invitations, resets)',
            'mode' => $mail ? 'live' : 'demo',
            'headline' => $mail ? 'SMTP server connected' : 'Messages are shown in SAQF only',
            'detail' => $mail ? 'Notifications are delivered by e-mail with retries.' : 'Nothing is sent outside SAQF.',
            'missing' => [],
            'next' => $mail ? 'Nothing to do.' : 'IT: set SAQF_MAIL_HOST, SAQF_MAIL_USERNAME, SAQF_MAIL_PASSWORD and SAQF_MAIL_FROM.',
            'test' => null,
        ];

        // Mode ---------------------------------------------------------------------------------
        $prod = Config::env() === 'production';
        $out[] = [
            'key' => 'mode', 'label' => 'Running mode',
            'mode' => $prod ? 'live' : 'demo',
            'headline' => $prod ? 'Production mode' : 'Demonstration mode',
            'detail' => $prod ? 'Demo accounts, the simulator and the guided tour are switched off.' : 'Fictional people and the simulator are available.',
            'missing' => [],
            'next' => $prod ? 'Nothing to do.' : 'Last step of go-live: set APP_ENV=production and SAQF_AUTO_INSTALL=production, then run php bin/install.php to create the first administrator.',
            'test' => null,
        ];
        return $out;
    }

    /** @return array{live:int,total:int} */
    public static function progress(array $systems): array
    {
        return ['live' => count(array_filter($systems, static fn($s) => $s['mode'] === 'live')), 'total' => count($systems)];
    }

    /** A settings block to start from, pre-filled with the current non-secret choices. */
    public static function envSnippet(): string
    {
        return implode("\n", [
            '# SAQF go-live settings: copy to .env on the server, fill in, restart. Secrets stay in .env, never in the repository.',
            'APP_ENV=production',
            'SAQF_AUTO_INSTALL=production',
            'SAQF_BASE_URL=https://saqf.yu.edu.sa',
            '',
            '# Registrar catalogue: drop the export into storage/inbox/catalog (no setting needed) or point here',
            '# SAQF_INSTITUTION_DIR=/data/registrar-export',
            '',
            '# SIS: file (CSV in storage/inbox/sis) or rest',
            'SAQF_SIS_SOURCE=file',
            '# SAQF_SIS_URL=https://integration.yu.edu.sa/saqf',
            '# SAQF_SIS_TOKEN=',
            '',
            '# LMS: moodle or blackboard',
            'SAQF_LMS_SOURCE=moodle',
            'SAQF_MOODLE_URL=https://lms.yu.edu.sa',
            'SAQF_MOODLE_TOKEN=',
            'SAQF_LMS_COURSE_KEY={term}-{code_nospace}',
            '',
            '# University sign-in',
            'SAQF_OIDC_ISSUER=https://login.microsoftonline.com/<tenant-id>/v2.0',
            'SAQF_OIDC_CLIENT_ID=',
            'SAQF_OIDC_CLIENT_SECRET=',
            'SAQF_PASSWORD_LOGIN=admins',
            '',
            '# E-mail',
            'SAQF_MAIL_HOST=smtp.office365.com',
            'SAQF_MAIL_USERNAME=saqf@yu.edu.sa',
            'SAQF_MAIL_PASSWORD=',
            'SAQF_MAIL_FROM=saqf@yu.edu.sa',
            '',
        ]);
    }

    private static function safeCheck(callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

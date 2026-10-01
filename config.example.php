<?php
// Copy to config.local.php (git-ignored) when environment variables are not available,
// e.g. on shared hosting. Environment variables always take precedence over this file.
// Every setting is explained in .env.example and docs/INTEGRATIONS.md.
return [
    'APP_ENV' => 'production',      // production | demo | local
    'APP_DEBUG' => false,           // never true in production
    'APP_TIMEZONE' => 'Asia/Riyadh',
    'SAQF_DEMO' => false,           // demo accounts + integration simulator (ignored when APP_ENV=production)
    'SAQF_DB_HOST' => '127.0.0.1',
    'SAQF_DB_PORT' => '3306',
    'SAQF_DB_NAME' => 'saqf',
    'SAQF_DB_USER' => 'saqf',
    'SAQF_DB_PASS' => 'change-this-before-deploying',
    'SAQF_TRUST_PROXY' => false,
    'SAQF_BASE_URL' => '',          // e.g. https://saqf.yu.edu.sa (needed for SSO and e-mail links)

    // University systems
    'SAQF_SIS_SOURCE' => 'file',    // file | rest | none
    'SAQF_LMS_SOURCE' => 'file',    // moodle | blackboard | file | none
    // 'SAQF_MOODLE_URL' => '', 'SAQF_MOODLE_TOKEN' => '',
    // 'SAQF_BLACKBOARD_URL' => '', 'SAQF_BLACKBOARD_KEY' => '', 'SAQF_BLACKBOARD_SECRET' => '',
    // 'SAQF_SIS_URL' => '', 'SAQF_SIS_TOKEN' => '',

    // University sign-in (OpenID Connect)
    // 'SAQF_OIDC_ISSUER' => 'https://login.microsoftonline.com/<tenant-id>/v2.0',
    // 'SAQF_OIDC_CLIENT_ID' => '', 'SAQF_OIDC_CLIENT_SECRET' => '',
    // 'SAQF_PASSWORD_LOGIN' => 'admins',

    // E-mail
    // 'SAQF_MAIL_HOST' => 'smtp.office365.com', 'SAQF_MAIL_PORT' => '587', 'SAQF_MAIL_ENCRYPTION' => 'tls',
    // 'SAQF_MAIL_USERNAME' => '', 'SAQF_MAIL_PASSWORD' => '', 'SAQF_MAIL_FROM' => '',
];

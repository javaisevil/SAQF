<?php
// Copy to config.local.php (git-ignored) when environment variables are not available,
// e.g. on shared hosting. Environment variables always take precedence over this file.
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
];

<?php
declare(strict_types=1);

/**
 * SAQF bootstrap: configuration, autoloading, error handling, database and
 * (for web requests) hardened session + security headers.
 */

define('SAQF_ROOT', dirname(__DIR__));
define('SAQF_VERSION', '2.0.0');

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Saqf\\', 5) !== 0) {
        return;
    }
    // A few small related classes share a file; map them explicitly.
    static $map = [
        'Saqf\\Integration\\InstitutionSource' => 'Integration/Sources.php',
        'Saqf\\Integration\\SisSource' => 'Integration/Sources.php',
        'Saqf\\Integration\\LmsSource' => 'Integration/Sources.php',
        'Saqf\\Integration\\CatalogFileSource' => 'Integration/SeededSources.php',
        'Saqf\\Integration\\SeededSisSource' => 'Integration/SeededSources.php',
        'Saqf\\Integration\\SeededLmsSource' => 'Integration/SeededSources.php',
        'Saqf\\Integration\\FileSisSource' => 'Integration/FileSources.php',
        'Saqf\\Integration\\FileLmsSource' => 'Integration/FileSources.php',
        'Saqf\\Integration\\Csv' => 'Integration/FileSources.php',
        'Saqf\\Integration\\NullSisSource' => 'Integration/NullSources.php',
        'Saqf\\Integration\\NullLmsSource' => 'Integration/NullSources.php',
        'Saqf\\Security\\JwtKeyNotFound' => 'Security/Jwt.php',
    ];
    $path = SAQF_ROOT . '/src/' . ($map[$class] ?? str_replace('\\', '/', substr($class, 5)) . '.php');
    if (is_file($path)) {
        require_once $path;
    }
});

use Saqf\Core\Config;
use Saqf\Core\ErrorLog;
use Saqf\Core\Request;
use Saqf\Core\Session;

Config::load();
date_default_timezone_set(Config::get('APP_TIMEZONE', 'Asia/Riyadh'));
mb_internal_encoding('UTF-8');
ErrorLog::register();
\Saqf\Quality\Engine::boot();

if (PHP_SAPI !== 'cli') {
    Request::boot();
    if (!defined('SAQF_STATELESS')) {
        Session::start();
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
    if (Request::isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

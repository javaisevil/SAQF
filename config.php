<?php

$localConfig = [];
$localConfigFile = __DIR__ . '/config.local.php';
if (is_file($localConfigFile)) {
    $loadedConfig = require $localConfigFile;
    if (is_array($loadedConfig)) {
        $localConfig = $loadedConfig;
    }
}

$readConfig = static function (string $key, $default) use ($localConfig) {
    $environmentValue = getenv($key);
    if ($environmentValue !== false && $environmentValue !== '') {
        return $environmentValue;
    }

    return array_key_exists($key, $localConfig) ? $localConfig[$key] : $default;
};

$appEnvironment = (string)$readConfig('APP_ENV', 'local');

define('APP_ENV', $appEnvironment);
define('APP_DEBUG', filter_var($readConfig('APP_DEBUG', $appEnvironment === 'local'), FILTER_VALIDATE_BOOLEAN));
define('DB_HOST', (string)$readConfig('AQMS_DB_HOST', $appEnvironment === 'local' ? 'localhost' : '127.0.0.1'));
define('DB_PORT', (string)$readConfig('AQMS_DB_PORT', $appEnvironment === 'local' ? '8889' : '3306'));
define('DB_USER', (string)$readConfig('AQMS_DB_USER', 'root'));
define('DB_PASS', (string)$readConfig('AQMS_DB_PASS', $appEnvironment === 'local' ? 'root' : ''));
define('DB_NAME', (string)$readConfig('AQMS_DB_NAME', 'AQMS_db'));

$script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$project_dir = '/' . basename(__DIR__);
$project_pos = strpos($script_name, $project_dir . '/');
$base_url = $project_pos === false ? '' : substr($script_name, 0, $project_pos + strlen($project_dir));

define('BASE_URL', $base_url);
define('UNIVERSITY_NAME', (string)$readConfig('UNIVERSITY_NAME', 'Al Yamamah University'));

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

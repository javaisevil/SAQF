<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aqmsCsrfToken(): string
{
    if (empty($_SESSION['aqms_csrf_token'])) {
        $_SESSION['aqms_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['aqms_csrf_token'];
}

function aqmsVerifyCsrfToken($token): bool
{
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['aqms_csrf_token'])
        && hash_equals($_SESSION['aqms_csrf_token'], $token);
}

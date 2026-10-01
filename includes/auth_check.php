<?php

require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit();
}

if (!isset($_SESSION['department']) || $_SESSION['department'] === '') {
    require_once __DIR__ . '/../db.php';
    $profile = $pdo->prepare('SELECT full_name, role, department FROM user WHERE user_id = ?');
    $profile->execute([$_SESSION['user_id']]);
    $row = $profile->fetch();
    if ($row) {
        $_SESSION['name'] = $row['full_name'] ?? ($_SESSION['name'] ?? '');
        $_SESSION['role'] = $row['role'] ?? ($_SESSION['role'] ?? '');
        $_SESSION['department'] = $row['department'] ?? '';
    }
}

function requireRole($role) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
        header('Location: ' . BASE_URL . '/index.php');
        exit();
    }
}

function requireAnyRole(array $roles) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles, true)) {
        header('Location: ' . BASE_URL . '/index.php');
        exit();
    }
}

<?php

require_once __DIR__ . '/db.php';

function logAction($username, $action) {
    global $pdo;

    try {
        $stmt = $pdo->prepare('INSERT INTO logs (username, action) VALUES (?, ?)');
        $stmt->execute([$username, $action]);
    } catch (Exception $e) {
        // Logging should not stop the main system.
    }
}
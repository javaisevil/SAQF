<?php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/log.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'The login form expired. Please try again.';
    } elseif (!$username || !$password) {
        $error = 'Please enter your username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM user WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            $_SESSION['name']     = $user['full_name'];

            logAction($username, 'LOGIN_SUCCESS');
            header('Location: ' . BASE_URL . '/index.php');
            exit();
        } else {
            logAction($username, 'LOGIN_FAILED');
            $error = 'Incorrect username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Al Yamamah University — AQMS Login</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/style.css">
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/favicon.png">
</head>
<body class="login-page">

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-logo">
            <img src="<?php echo BASE_URL; ?>/assets/yu-logo.png" alt="Al Yamamah University">
            <h2>Academic Quality Management System</h2>
            <p>Sign in to continue</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(aqmsCsrfToken()); ?>">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-full btn-lg">Sign In</button>
        </form>

        <div class="login-footer">
            <p>Al Yamamah University</p>
        </div>
    </div>
</div>

</body>
</html>

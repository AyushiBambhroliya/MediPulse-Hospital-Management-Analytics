
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax'
    ]);

    session_start();
}

function requireRole($role) {
    if (
        !isset($_SESSION['user_id']) ||
        $_SESSION['role'] !== $role
    ) {
        header("Location: ../login/login.php");
        exit;
    }
}

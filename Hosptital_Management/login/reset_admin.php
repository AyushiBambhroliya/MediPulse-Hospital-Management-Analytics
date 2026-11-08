
<?php
require_once __DIR__ . '/../config.php';

$message = '';

if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    && ($_SERVER['REMOTE_ADDR'] ?? '') !== '::1') {
    exit('Local access only.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must contain at least 8 characters.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "UPDATE users
             SET password_hash = :hash, is_active = 1
             WHERE email = :email AND role = 'admin'"
        );

        $stmt->execute([
            'hash' => $hash,
            'email' => $email
        ]);

        $message = $stmt->rowCount() > 0
            ? 'Admin password updated successfully!'
            : 'Admin email not found. Check the email address.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>MediPulse Admin Password Reset</title>
</head>
<body style="background:#071321;color:white;font-family:Arial;padding:40px">
<h2>MediPulse Admin Password Reset</h2>
<p><?= htmlspecialchars($message) ?></p>
<form method="POST">
    <p>Admin Email</p>
    <input type="email" name="email" required>
    <p>New Password</p>
    <input type="password" name="password" minlength="8" required>
    <p><button type="submit">Reset Password</button></p>
</form>
</body>
</html>

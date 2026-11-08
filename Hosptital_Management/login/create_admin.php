
<?php
require_once __DIR__ . '/../config.php';

$message = '';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    exit('Local access only.');
}

$count = (int)$pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'admin'"
)->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $count === 0) {
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid name and email.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, role)
             VALUES (?, ?, ?, 'admin')"
        );

        try {
            $stmt->execute([
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT)
            ]);
            $message = 'Admin account created successfully!';
            $count = 1;
        } catch (PDOException $e) {
            $message = 'Account creation failed. Check the email or database settings.';
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Create MediPulse Admin</title>
</head>
<body style="background:#071321;color:white;font-family:Arial;padding:35px">
<h2 style="color:#23d5e6">Create Admin Account</h2>

<p><?= htmlspecialchars($message) ?></p>

<?php if ($count === 0): ?>
<form method="POST">
    <p>Full Name</p>
    <input name="full_name" required>

    <p>Email Address</p>
    <input type="email" name="email" required>

    <p>Password (minimum 8 characters)</p>
    <input type="password" name="password" minlength="8" required>

    <p><button type="submit">Create Admin Account</button></p>
</form>
<?php else: ?>
<p>Admin account already exists or has just been created.</p>
<a href="login.php" style="color:#23d5e6">Go to Login</a>
<?php endif; ?>
</body>
</html>

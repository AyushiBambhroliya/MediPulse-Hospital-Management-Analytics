
<?php
session_start();
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    header("Location: login.php?error=empty");
    exit;
}

$stmt = $pdo->prepare(
    "SELECT user_id, full_name, email, password_hash,
            role, doctor_id, is_active
     FROM users WHERE email = :email LIMIT 1"
);
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

$valid = $user
    && password_verify($password, $user['password_hash'])
    && (int)$user['is_active'] === 1;

$log = $pdo->prepare(
    "INSERT INTO login_logs
     (user_id, email_attempted, login_status, ip_address)
     VALUES (:user_id, :email, :status, :ip)"
);

$log->execute([
    'user_id' => $user ? $user['user_id'] : null,
    'email' => $email,
    'status' => $valid ? 'success' : 'failed',
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null
]);

if (!$valid) {
    header("Location: login.php?error=invalid");
    exit;
}

$pages = [
    'admin' => '../admin/dashboard.php',
    'doctor' =>'../doctor/dashboard.php',
    'nurse' => '../dashboards/nurse.php',
    'receptionist' => '../dashboards/receptionist.php',
    'billing_staff' => '../dashboards/billing_staff.php',
    'pharmacist' => '../dashboards/pharmacist.php',
    'lab_technician' => '../dashboards/lab_technician.php',
    'data_analyst' => '../dashboards/data_analyst.php'
];

if (!isset($pages[$user['role']])) {
    header("Location: login.php?error=invalid");
    exit;
}

session_regenerate_id(true);

$_SESSION['user_id'] = $user['user_id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email'] = $user['email'];
$_SESSION['role'] = $user['role'];
$_SESSION['doctor_id'] = $user['doctor_id'];

header("Location: " . $pages[$user['role']]);
exit;


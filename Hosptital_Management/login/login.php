
<?php
session_start();
require_once __DIR__ . '/../config.php';

// Redirect logged-in users to their dashboards
if (isset($_SESSION['user_id'])) {
    $pages = [
        'admin' => '../admin/dashboard.php',
        'doctor' => '../doctor/dashboard.php',
        'nurse' => '../nurse/dashboard.php',
        'receptionist' => '../receptionist/dashboard.php',
        'billing_staff' => '../billing_staff/dashboard.php',
        'pharmacist' => '../pharmacist/dashboard.php',
        'lab_technician' => '../lab_technician/dashboard.php',
        'data_analyst' => '../data_analyst/dashboard.php'
    ];

    $role = $_SESSION['role'] ?? '';

    if (isset($pages[$role])) {
        header("Location: " . $pages[$role]);
        exit;
    }
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mode = $_GET['mode'] ?? 'login';
if (!in_array($mode, ['login', 'register'], true)) {
    $mode = 'login';
}

$message = '';
$error = '';

// Registration processing
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'register') {

    $mode = 'register';

    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? '';

    $allowedRoles = [
        'doctor',
        'nurse',
        'receptionist',
        'billing_staff',
        'pharmacist',
        'lab_technician',
        'data_analyst'
    ];

    if (!hash_equals(
        $_SESSION['csrf_token'],
        $_POST['csrf_token'] ?? ''
    )) {
        $error = 'Invalid request. Please refresh the page.';
    } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid name and email.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = 'Please select a valid staff role.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must contain at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Check whether email already exists
            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM users WHERE email = ?"
            );
            $check->execute([$email]);

            if ((int)$check->fetchColumn() > 0) {
                $error = 'This email is already registered.';
            } else {
                // New staff accounts require Admin approval
                $stmt = $pdo->prepare(
                    "INSERT INTO users
                    (full_name, email, password_hash, role, is_active)
                    VALUES (?, ?, ?, ?, 0)"
                );

                $stmt->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role
                ]);

                $message = 'Registration submitted successfully! Please contact Admin for account approval.';
                $mode = 'login';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Registration failed. Please try again.';
            $mode = 'register';
        }
    }
}

// Login messages
if (isset($_GET['error'])) {
    $messages = [
        'invalid' => 'Invalid email or password.',
        'inactive' => 'Your account is inactive. Contact the administrator.',
        'empty' => 'Please enter your email and password.',
        'server' => 'Something went wrong. Please try again.'
    ];

    $error = $messages[$_GET['error']] ?? 'Please try again.';
}

if (isset($_GET['registered'])) {
    $message = 'Registration successful! You can now sign in.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In | MediPulse</title>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    min-height: 100vh;
    background:
        radial-gradient(circle at 15% 20%, rgba(35,213,230,.13), transparent 32%),
        radial-gradient(circle at 85% 80%, rgba(24,185,166,.12), transparent 30%),
        #071321;
    color: #f5fbff;
    font-family: 'DM Sans', sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 25px;
}

.login-wrapper {
    width: 100%;
    max-width: 1000px;
    min-height: 590px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 22px;
    background: #0c1d30;
    box-shadow: 0 25px 80px rgba(0,0,0,.4);
}

.left-panel {
    padding: 55px 45px;
    background: linear-gradient(145deg, #102b42, #0b1b2c);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}

.left-panel::before {
    content: "";
    position: absolute;
    width: 300px;
    height: 300px;
    background: rgba(35,213,230,.12);
    filter: blur(90px);
    border-radius: 50%;
    right: -100px;
    bottom: 20px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
    text-decoration: none;
    color: white;
}

.brand-icon {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, #23d5e6, #18b9a6);
    color: #071321;
    border-radius: 12px;
    display: grid;
    place-items: center;
    font-size: 25px;
    font-weight: 800;
}

.brand h2 {
    font-family: Outfit, sans-serif;
    font-size: 25px;
}

.brand h2 span,
.welcome h1 span {
    color: #23d5e6;
}

.welcome {
    position: relative;
    z-index: 1;
    margin-top: 55px;
}

.tag {
    color: #23d5e6;
    font-size: 11px;
    letter-spacing: 2px;
    margin-bottom: 22px;
}

.welcome h1 {
    font-family: Outfit, sans-serif;
    font-size: 42px;
    line-height: 1.2;
    margin-bottom: 20px;
}

.welcome p {
    color: #94a9bd;
    line-height: 1.9;
    font-size: 14px;
}

.panel-footer {
    color: #71869b;
    font-size: 12px;
    position: relative;
    z-index: 1;
    margin-top: 50px;
}

.right-panel {
    padding: 45px;
    display: flex;
    justify-content: center;
    flex-direction: column;
}

.form-heading {
    margin-bottom: 28px;
}

.form-heading h2 {
    font-family: Outfit, sans-serif;
    font-size: 30px;
    margin-bottom: 10px;
}

.form-heading p {
    color: #94a9bd;
    font-size: 13px;
}

label {
    display: block;
    color: #dce8f2;
    font-size: 13px;
    margin-bottom: 9px;
    font-weight: 600;
}

.input-group {
    margin-bottom: 18px;
}

input, select {
    width: 100%;
    padding: 14px 16px;
    background: #10243a;
    border: 1px solid rgba(255,255,255,.1);
    outline: none;
    border-radius: 9px;
    color: white;
    font-size: 14px;
}

select option {
    background: #10243a;
    color: white;
}

input:focus, select:focus {
    border-color: #23d5e6;
    box-shadow: 0 0 0 3px rgba(35,213,230,.08);
}

input::placeholder {
    color: #71869b;
}

.password-box {
    position: relative;
}

.password-box input {
    padding-right: 65px;
}

.show-password {
    position: absolute;
    right: 13px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #23d5e6;
    cursor: pointer;
    font-size: 12px;
}

.login-button {
    width: 100%;
    padding: 15px;
    margin-top: 7px;
    border: none;
    border-radius: 9px;
    background: linear-gradient(135deg, #23d5e6, #18b9a6);
    color: #071321;
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
}

.login-button:hover {
    transform: translateY(-2px);
}

.error {
    background: rgba(255,75,75,.1);
    border: 1px solid rgba(255,75,75,.3);
    color: #ff9999;
    padding: 12px;
    border-radius: 8px;
    font-size: 12px;
    margin-bottom: 18px;
}

.success {
    background: rgba(24,185,166,.1);
    border: 1px solid rgba(24,185,166,.3);
    color: #5ee5ce;
    padding: 12px;
    border-radius: 8px;
    font-size: 12px;
    margin-bottom: 18px;
}

.switch-link {
    display: block;
    text-align: center;
    color: #94a9bd;
    font-size: 13px;
    margin-top: 23px;
}

.switch-link a {
    color: #23d5e6;
    font-weight: 700;
    text-decoration: none;
}

.back-home {
    display: block;
    text-align: center;
    color: #94a9bd;
    font-size: 13px;
    margin-top: 22px;
    text-decoration: none;
}

.back-home:hover,
.switch-link a:hover {
    color: #23d5e6;
}

.security-note {
    text-align: center;
    color: #71869b;
    font-size: 11px;
    margin-top: 27px;
}

@media(max-width: 750px) {
    .login-wrapper {
        grid-template-columns: 1fr;
        max-width: 480px;
    }

    .left-panel {
        padding: 30px;
        min-height: 260px;
    }

    .welcome {
        margin-top: 35px;
    }

    .welcome h1 {
        font-size: 32px;
    }

    .right-panel {
        padding: 35px 30px;
    }
}
</style>
</head>

<body>

<div class="login-wrapper">

    <section class="left-panel">

        <a href="../index.php" class="brand">
            <div class="brand-icon">M</div>
            <h2>Medi<span>Pulse</span></h2>
        </a>

        <div class="welcome">
            <div class="tag">HOSPITAL ANALYTICS PLATFORM</div>

            <h1>
                <?php if ($mode === 'register'): ?>
                    Create Your<br><span>Account.</span>
                <?php else: ?>
                    Welcome<br>Back to <span>MediPulse.</span>
                <?php endif; ?>
            </h1>

            <p>
                Access hospital insights, explore analytics,
                monitor trends and understand performance
                through one unified platform.
            </p>
        </div>

        <div class="panel-footer">
            © 2026 MediPulse | Hospital Analytics Dashboard
        </div>

    </section>

    <section class="right-panel">

        <?php if ($mode === 'register'): ?>

            <div class="form-heading">
                <h2>Create Account</h2>
                <p>Register as a MediPulse staff member.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php?mode=register">

                <input type="hidden" name="action" value="register">

                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="input-group">
                    <label for="full_name">Full Name</label>
                    <input type="text"
                           id="full_name"
                           name="full_name"
                           placeholder="Enter your full name"
                           maxlength="100"
                           required>
                </div>

                <div class="input-group">
                    <label for="register_email">Email Address</label>
                    <input type="email"
                           id="register_email"
                           name="email"
                           placeholder="Enter your email"
                           maxlength="150"
                           required>
                </div>

                <div class="input-group">
                    <label for="role">Select Your Role</label>
                    <select id="role" name="role" required>
                        <option value="">Choose your role</option>
                        <option value="doctor">Doctor</option>
                        <option value="nurse">Nurse</option>
                        <option value="receptionist">Receptionist</option>
                        <option value="billing_staff">Billing Staff</option>
                        <option value="pharmacist">Pharmacist</option>
                        <option value="lab_technician">Lab Technician</option>
                        <option value="data_analyst">Data Analyst</option>
                    </select>
                </div>

                <div class="input-group">
                    <label for="register_password">Password</label>

                    <div class="password-box">
                        <input type="password"
                               id="register_password"
                               name="password"
                               minlength="8"
                               placeholder="Minimum 8 characters"
                               required>

                        <button type="button"
                                class="show-password"
                                onclick="toggleField('register_password', this)">
                            Show
                        </button>
                    </div>
                </div>

                <div class="input-group">
                    <label for="confirm_password">Confirm Password</label>

                    <input type="password"
                           id="confirm_password"
                           name="confirm_password"
                           minlength="8"
                           placeholder="Enter password again"
                           required>
                </div>

                <button type="submit" class="login-button">
                    Register Account →
                </button>

            </form>

            <div class="switch-link">
                Already have an account?
                <a href="login.php">Sign In</a>
            </div>

            <div class="security-note">
                New accounts require Admin approval before login.
            </div>

        <?php else: ?>

            <div class="form-heading">
                <h2>Sign In</h2>
                <p>Enter your account details to continue.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($message !== ''): ?>
                <div class="success">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form action="authenticate.php" method="POST">

                <div class="input-group">
                    <label for="email">Email Address</label>

                    <input type="email"
                           id="email"
                           name="email"
                           placeholder="Enter your email"
                           maxlength="150"
                           autocomplete="username"
                           required>
                </div>

                <div class="input-group">
                    <label for="password">Password</label>

                    <div class="password-box">
                        <input type="password"
                               id="password"
                               name="password"
                               placeholder="Enter your password"
                               autocomplete="current-password"
                               required>

                        <button type="button"
                                class="show-password"
                                onclick="toggleField('password', this)">
                            Show
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-button">
                    Login to Dashboard →
                </button>

            </form>

            <div class="switch-link">
                Don't have an account?
                <a href="login.php?mode=register">Register Now</a>
            </div>

        <?php endif; ?>

        <a href="../index.php" class="back-home">
            ← Back to Homepage
        </a>

        <div class="security-note">
            🔒 Secure access for authorized users only
        </div>

    </section>

</div>

<script>
function toggleField(id, button) {
    const field = document.getElementById(id);

    if (field.type === 'password') {
        field.type = 'text';
        button.textContent = 'Hide';
    } else {
        field.type = 'password';
        button.textContent = 'Show';
    }
}
</script>

</body>
</html>

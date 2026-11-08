<?php
session_start();
require_once "../config.php";

/* -------------------- LOGIN CHECK -------------------- */
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login.php");
    exit;
}

/* -------------------- SECURITY -------------------- */
if (empty($_SESSION['lab_csrf'])) {
    $_SESSION['lab_csrf'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage($message, $type = 'success') {
    $_SESSION['lab_message'] = $message;
    $_SESSION['lab_message_type'] = $type;
    header("Location: dashboard.php");
    exit;
}

/* -------------------- CREATE LAB TABLES -------------------- */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS lab_tests (
        test_id INT AUTO_INCREMENT PRIMARY KEY,
        test_name VARCHAR(150) NOT NULL,
        category VARCHAR(100) NOT NULL,
        description TEXT NULL,
        normal_range VARCHAR(150) NULL,
        unit VARCHAR(50) NULL,
        test_price DECIMAL(10,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS lab_orders (
        order_id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id VARCHAR(10) NOT NULL,
        appointment_id VARCHAR(10) NULL,
        test_id INT NOT NULL,
        sample_type VARCHAR(80) NOT NULL,
        priority VARCHAR(30) DEFAULT 'Normal',
        order_status VARCHAR(30) DEFAULT 'Requested',
        result_value TEXT NULL,
        result_unit VARCHAR(50) NULL,
        result_notes TEXT NULL,
        technician_user_id VARCHAR(100) NULL,
        ordered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id),
        FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id),
        FOREIGN KEY (test_id) REFERENCES lab_tests(test_id)
    ) ENGINE=InnoDB
");

/* -------------------- DEFAULT TESTS -------------------- */
$testCount = (int)$pdo->query("SELECT COUNT(*) FROM lab_tests")->fetchColumn();

if ($testCount === 0) {
    $defaultTests = [
        ['Complete Blood Count (CBC)', 'Hematology', 'Blood cell count test', 'As per laboratory reference', 'cells/µL', 350],
        ['Blood Glucose', 'Biochemistry', 'Blood sugar measurement', '70-99 fasting', 'mg/dL', 100],
        ['Lipid Profile', 'Biochemistry', 'Cholesterol and triglycerides', 'As per laboratory reference', 'mg/dL', 500],
        ['Liver Function Test (LFT)', 'Biochemistry', 'Liver health panel', 'As per laboratory reference', 'U/L', 450],
        ['Kidney Function Test (KFT)', 'Biochemistry', 'Kidney health panel', 'As per laboratory reference', 'mg/dL', 400],
        ['Urine Routine', 'Pathology', 'Routine urine examination', 'As per laboratory reference', 'N/A', 150],
        ['Thyroid Profile', 'Endocrinology', 'Thyroid hormone assessment', 'As per laboratory reference', 'µIU/mL', 400],
        ['Vitamin D', 'Biochemistry', 'Vitamin D level test', 'As per laboratory reference', 'ng/mL', 600]
    ];

    $insertTest = $pdo->prepare("
        INSERT INTO lab_tests
        (test_name, category, description, normal_range, unit, test_price)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($defaultTests as $test) {
        $insertTest->execute($test);
    }
}

/* -------------------- HANDLE FORMS -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['lab_csrf'], $_POST['csrf_token'])
    ) {
        redirectWithMessage("Invalid request. Please try again.", "error");
    }

    $action = $_POST['action'] ?? '';

    try {

        /* ADD NEW TEST */
        if ($action === 'add_test') {
            $name = trim($_POST['test_name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $normalRange = trim($_POST['normal_range'] ?? '');
            $unit = trim($_POST['unit'] ?? '');
            $price = (float)($_POST['test_price'] ?? 0);

            if ($name === '' || $category === '') {
                redirectWithMessage("Test name and category are required.", "error");
            }

            if ($price < 0) {
                redirectWithMessage("Test price cannot be negative.", "error");
            }

            $stmt = $pdo->prepare("
                INSERT INTO lab_tests
                (test_name, category, description, normal_range, unit, test_price)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $name, $category, $description, $normalRange, $unit, $price
            ]);

            redirectWithMessage("New laboratory test added successfully.");
        }

        /* CREATE LAB ORDER */
        if ($action === 'create_order') {
            $patientId = trim($_POST['patient_id'] ?? '');
            $appointmentId = trim($_POST['appointment_id'] ?? '');
            $testId = (int)($_POST['test_id'] ?? 0);
            $sampleType = trim($_POST['sample_type'] ?? '');
            $priority = $_POST['priority'] ?? 'Normal';

            if ($patientId === '' || $testId <= 0 || $sampleType === '') {
                redirectWithMessage("Please complete all required order fields.", "error");
            }

            if (!in_array($priority, ['Normal', 'Urgent'], true)) {
                redirectWithMessage("Invalid priority selected.", "error");
            }

            $patientCheck = $pdo->prepare(
                "SELECT patient_id FROM patients WHERE patient_id = ?"
            );
            $patientCheck->execute([$patientId]);

            if (!$patientCheck->fetch()) {
                redirectWithMessage("Selected patient was not found.", "error");
            }

            if ($appointmentId !== '') {
                $appointmentCheck = $pdo->prepare("
                    SELECT appointment_id
                    FROM appointments
                    WHERE appointment_id = ? AND patient_id = ?
                ");
                $appointmentCheck->execute([$appointmentId, $patientId]);

                if (!$appointmentCheck->fetch()) {
                    redirectWithMessage(
                        "The selected appointment does not belong to this patient.",
                        "error"
                    );
                }
            } else {
                $appointmentId = null;
            }

            $testCheck = $pdo->prepare(
                "SELECT test_id FROM lab_tests WHERE test_id = ?"
            );
            $testCheck->execute([$testId]);

            if (!$testCheck->fetch()) {
                redirectWithMessage("Selected laboratory test was not found.", "error");
            }

            $stmt = $pdo->prepare("
                INSERT INTO lab_orders
                (patient_id, appointment_id, test_id, sample_type, priority, order_status)
                VALUES (?, ?, ?, ?, ?, 'Requested')
            ");

            $stmt->execute([
                $patientId, $appointmentId, $testId, $sampleType, $priority
            ]);

            redirectWithMessage("Laboratory order created successfully.");
        }

        /* UPDATE ORDER STATUS AND RESULT */
        if ($action === 'update_order') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $status = $_POST['order_status'] ?? '';
            $resultValue = trim($_POST['result_value'] ?? '');
            $resultUnit = trim($_POST['result_unit'] ?? '');
            $resultNotes = trim($_POST['result_notes'] ?? '');

            $allowedStatuses = [
                'Requested',
                'Sample Collected',
                'Processing',
                'Completed',
                'Cancelled'
            ];

            if (!in_array($status, $allowedStatuses, true)) {
                redirectWithMessage("Invalid laboratory status.", "error");
            }

            if ($orderId <= 0) {
                redirectWithMessage("Invalid order selected.", "error");
            }

            if ($status === 'Completed' && $resultValue === '') {
                redirectWithMessage(
                    "Enter the test result before marking it completed.",
                    "error"
                );
            }

            $stmt = $pdo->prepare("
                UPDATE lab_orders
                SET order_status = ?,
                    result_value = ?,
                    result_unit = ?,
                    result_notes = ?,
                    technician_user_id = ?
                WHERE order_id = ?
            ");

            $stmt->execute([
                $status,
                $resultValue !== '' ? $resultValue : null,
                $resultUnit !== '' ? $resultUnit : null,
                $resultNotes !== '' ? $resultNotes : null,
                (string)$_SESSION['user_id'],
                $orderId
            ]);

            redirectWithMessage("Laboratory order updated successfully.");
        }

    } catch (PDOException $ex) {
        error_log("Lab dashboard error: " . $ex->getMessage());
        redirectWithMessage("Database error. Please check your entries and try again.", "error");
    }
}

/* -------------------- MESSAGE -------------------- */
$message = $_SESSION['lab_message'] ?? '';
$messageType = $_SESSION['lab_message_type'] ?? 'success';
unset($_SESSION['lab_message'], $_SESSION['lab_message_type']);

/* -------------------- DASHBOARD DATA -------------------- */
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total_orders,
        SUM(order_status = 'Requested') AS requested,
        SUM(order_status = 'Sample Collected') AS collected,
        SUM(order_status = 'Processing') AS processing,
        SUM(order_status = 'Completed') AS completed,
        SUM(order_status = 'Cancelled') AS cancelled,
        SUM(DATE(ordered_at) = CURDATE()) AS today_orders
    FROM lab_orders
")->fetch(PDO::FETCH_ASSOC);

$patients = $pdo->query("
    SELECT patient_id, patient_name
    FROM patients
    ORDER BY patient_name
")->fetchAll(PDO::FETCH_ASSOC);

$appointments = $pdo->query("
    SELECT a.appointment_id, a.patient_id, a.appointment_date, p.patient_name
    FROM appointments a
    INNER JOIN patients p ON p.patient_id = a.patient_id
    ORDER BY a.appointment_date DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$tests = $pdo->query("
    SELECT *
    FROM lab_tests
    ORDER BY category, test_name
")->fetchAll(PDO::FETCH_ASSOC);

/* RECENT ORDERS */
$orders = $pdo->query("
    SELECT
        o.*,
        p.patient_name,
        t.test_name,
        t.category,
        t.normal_range
    FROM lab_orders o
    INNER JOIN patients p ON p.patient_id = o.patient_id
    INNER JOIN lab_tests t ON t.test_id = o.test_id
    ORDER BY o.ordered_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

/* 7-DAY ORDER ACTIVITY */
$activityRows = $pdo->query("
    SELECT DATE(ordered_at) AS order_day, COUNT(*) AS total
    FROM lab_orders
    WHERE ordered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(ordered_at)
    ORDER BY order_day
")->fetchAll(PDO::FETCH_ASSOC);

$activity = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $activity[$day] = 0;
}

foreach ($activityRows as $row) {
    $activity[$row['order_day']] = (int)$row['total'];
}

$chartLabels = array_map(
    fn($date) => date('d M', strtotime($date)),
    array_keys($activity)
);
$chartValues = array_values($activity);

$technicianName = $_SESSION['full_name'] ?? 'Lab Technician';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lab Technician Dashboard | MediPulse</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#253047}
.layout{display:flex;min-height:100vh}
.sidebar{width:245px;background:#14243b;color:white;padding:25px 18px;flex-shrink:0}
.brand{font-size:23px;font-weight:bold;margin-bottom:35px}
.brand span{color:#35c6b0}
.profile{padding:15px;background:#203651;border-radius:12px;margin-bottom:28px}
.profile small{display:block;color:#b8c6d8;margin-top:6px}
.nav-title{font-size:11px;color:#91a4bb;margin:20px 10px 10px;text-transform:uppercase}
.nav a{display:block;text-decoration:none;color:#d9e3ef;padding:12px;border-radius:8px;margin:5px 0;font-size:14px}
.nav a.active,.nav a:hover{background:#294561;color:white}
.logout{margin-top:30px!important;background:#8f3443}
.main{flex:1;padding:28px;min-width:0}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:25px}
.topbar h1{font-size:25px}
.topbar p{color:#78869a;margin-top:7px;font-size:13px}
.date{background:white;padding:12px 16px;border-radius:9px;color:#536278;font-size:13px}
.alert{padding:13px 16px;border-radius:9px;margin-bottom:18px;font-size:14px}
.alert.success{background:#e2f7ed;color:#16744b}
.alert.error{background:#ffe9e9;color:#a83232}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}
.stat,.panel{background:white;border-radius:13px;padding:20px;box-shadow:0 3px 15px rgba(24,45,75,.05)}
.stat small{color:#7c899b;font-size:12px}
.stat h2{font-size:28px;margin-top:10px}
.stat .label{font-size:12px;color:#8290a1;margin-top:5px}
.stat-icon{font-size:22px;margin-bottom:12px}
.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px;margin-bottom:20px}
.panel{margin-bottom:20px}
.panel h3{font-size:17px;margin-bottom:17px}
.panel-sub{font-size:12px;color:#8390a2;margin-top:-10px;margin-bottom:15px}
form .field{margin-bottom:13px}
label{display:block;font-size:12px;color:#5e6c80;margin-bottom:6px;font-weight:bold}
input,select,textarea{width:100%;padding:11px;border:1px solid #dce3eb;border-radius:7px;background:#fff;font-size:13px;color:#27364b}
textarea{resize:vertical;min-height:65px}
input:focus,select:focus,textarea:focus{outline:1px solid #20a995;border-color:#20a995}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn{border:0;border-radius:7px;padding:11px 16px;background:#168f82;color:white;font-weight:bold;cursor:pointer;font-size:13px}
.btn:hover{background:#11796f}
.btn.secondary{background:#263f5c}
.btn.small{padding:8px 11px;font-size:12px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:700px}
th,td{text-align:left;padding:12px 10px;border-bottom:1px solid #edf0f4;font-size:12px;vertical-align:top}
th{color:#748196;font-size:11px;text-transform:uppercase;background:#fafbfd}
td{color:#3d4b60}
.status{display:inline-block;padding:5px 8px;border-radius:20px;font-size:10px;font-weight:bold;white-space:nowrap}
.Requested{background:#fff2d8;color:#9b6900}
.Sample-Collected{background:#e5f1ff;color:#2867a5}
.Processing{background:#eee8ff;color:#6844a4}
.Completed{background:#def6e9;color:#16744b}
.Cancelled{background:#ffe6e6;color:#ae3838}
.priority{font-weight:bold}
.priority.Urgent{color:#c33}
.priority.Normal{color:#65748a}
.inline-form{min-width:230px}
.inline-form input,.inline-form select,.inline-form textarea{margin-bottom:7px}
.test-list{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.test-item{border:1px solid #e8edf2;border-radius:8px;padding:12px}
.test-item strong{font-size:13px;display:block;margin-bottom:5px}
.test-item small{color:#8390a2;font-size:11px}
.chart-box{height:250px}
.empty{text-align:center;color:#8995a5;padding:25px}
@media(max-width:1050px){.stats{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}
@media(max-width:700px){.layout{display:block}.sidebar{width:100%}.main{padding:16px}.stats{grid-template-columns:1fr 1fr}.topbar{align-items:flex-start;flex-direction:column}.form-grid{grid-template-columns:1fr}.test-list{grid-template-columns:1fr}}
</style>
</head>

<body>
<div class="layout">

<aside class="sidebar">
    <div class="brand">Medi<span>Pulse</span></div>

    <div class="profile">
        <strong><?= e($technicianName) ?></strong>
        <small>Laboratory Technician</small>
    </div>

    <div class="nav-title">Workspace</div>
    <nav class="nav">
        <a href="#overview" class="active">▦ Dashboard</a>
        <a href="#new-order">＋ Create Lab Order</a>
        <a href="#orders">☷ Lab Orders</a>
        <a href="#tests">⚗ Test Catalogue</a>
        <a href="#activity">▥ Lab Activity</a>
        <a href="../login/logout.php" class="logout">↪ Logout</a>
    </nav>
</aside>

<main class="main">

<header class="topbar" id="overview">
    <div>
        <h1>Laboratory Dashboard</h1>
        <p>Manage laboratory orders, samples and test results.</p>
    </div>
    <div class="date"><?= date('l, d F Y') ?></div>
</header>

<?php if ($message): ?>
    <div class="alert <?= e($messageType) ?>"><?= e($message) ?></div>
<?php endif; ?>

<!-- STATISTICS -->
<section class="stats">
    <div class="stat">
        <div class="stat-icon">🧪</div>
        <small>Total Lab Orders</small>
        <h2><?= (int)($stats['total_orders'] ?? 0) ?></h2>
        <div class="label">All recorded orders</div>
    </div>

    <div class="stat">
        <div class="stat-icon">⏳</div>
        <small>Requested</small>
        <h2><?= (int)($stats['requested'] ?? 0) ?></h2>
        <div class="label">Awaiting sample collection</div>
    </div>

    <div class="stat">
        <div class="stat-icon">⚙️</div>
        <small>Processing</small>
        <h2><?= (int)($stats['processing'] ?? 0) ?></h2>
        <div class="label">Tests in progress</div>
    </div>

    <div class="stat">
        <div class="stat-icon">✅</div>
        <small>Completed</small>
        <h2><?= (int)($stats['completed'] ?? 0) ?></h2>
        <div class="label">Results entered</div>
    </div>

    <div class="stat">
        <div class="stat-icon">🩸</div>
        <small>Samples Collected</small>
        <h2><?= (int)($stats['collected'] ?? 0) ?></h2>
        <div class="label">Ready for processing</div>
    </div>

    <div class="stat">
        <div class="stat-icon">📅</div>
        <small>Today's Orders</small>
        <h2><?= (int)($stats['today_orders'] ?? 0) ?></h2>
        <div class="label">Orders received today</div>
    </div>

    <div class="stat">
        <div class="stat-icon">📋</div>
        <small>Available Tests</small>
        <h2><?= count($tests) ?></h2>
        <div class="label">Test catalogue</div>
    </div>

    <div class="stat">
        <div class="stat-icon">❌</div>
        <small>Cancelled</small>
        <h2><?= (int)($stats['cancelled'] ?? 0) ?></h2>
        <div class="label">Cancelled orders</div>
    </div>
</section>

<!-- CREATE ORDER AND ADD TEST -->
<section class="grid">

<div class="panel" id="new-order">
    <h3>＋ Create New Lab Order</h3>
    <p class="panel-sub">Register a patient laboratory test.</p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['lab_csrf']) ?>">
        <input type="hidden" name="action" value="create_order">

        <div class="field">
            <label>Patient *</label>
            <select name="patient_id" required>
                <option value="">Select patient</option>
                <?php foreach ($patients as $patient): ?>
                    <option value="<?= e($patient['patient_id']) ?>">
                        <?= e($patient['patient_name']) ?> (<?= e($patient['patient_id']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Appointment (Optional)</label>
            <select name="appointment_id">
                <option value="">No appointment linked</option>
                <?php foreach ($appointments as $appointment): ?>
                    <option value="<?= e($appointment['appointment_id']) ?>">
                        <?= e($appointment['appointment_id']) ?> -
                        <?= e($appointment['patient_name']) ?> -
                        <?= e($appointment['appointment_date']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Laboratory Test *</label>
                <select name="test_id" required>
                    <option value="">Select test</option>
                    <?php foreach ($tests as $test): ?>
                        <option value="<?= (int)$test['test_id'] ?>">
                            <?= e($test['test_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Sample Type *</label>
                <select name="sample_type" required>
                    <option value="">Select sample</option>
                    <option value="Blood">Blood</option>
                    <option value="Urine">Urine</option>
                    <option value="Serum">Serum</option>
                    <option value="Plasma">Plasma</option>
                    <option value="Stool">Stool</option>
                    <option value="Other">Other</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label>Priority</label>
            <select name="priority">
                <option value="Normal">Normal</option>
                <option value="Urgent">Urgent</option>
            </select>
        </div>

        <button class="btn" type="submit">Create Lab Order</button>
    </form>
</div>

<div class="panel">
    <h3>⚗ Add New Laboratory Test</h3>
    <p class="panel-sub">Add a test to the hospital test catalogue.</p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['lab_csrf']) ?>">
        <input type="hidden" name="action" value="add_test">

        <div class="field">
            <label>Test Name *</label>
            <input type="text" name="test_name" placeholder="e.g. Hemoglobin Test" required>
        </div>

        <div class="field">
            <label>Category *</label>
            <input type="text" name="category" placeholder="e.g. Hematology" required>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Normal Range</label>
                <input type="text" name="normal_range" placeholder="Reference range">
            </div>
            <div class="field">
                <label>Unit</label>
                <input type="text" name="unit" placeholder="e.g. mg/dL">
            </div>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Test Price (₹)</label>
                <input type="number" name="test_price" min="0" step="0.01" value="0">
            </div>
            <div class="field">
                <label>Description</label>
                <input type="text" name="description" placeholder="Short description">
            </div>
        </div>

        <button class="btn secondary" type="submit">Add Test</button>
    </form>
</div>

</section>

<!-- RECENT ORDERS -->
<section class="panel" id="orders">
    <h3>☷ Laboratory Orders & Result Management</h3>
    <p class="panel-sub">Update sample status and enter results for each order.</p>

    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Order</th>
                <th>Patient</th>
                <th>Test</th>
                <th>Sample</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Result / Update</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$orders): ?>
            <tr><td colspan="7" class="empty">No laboratory orders found.</td></tr>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td>
                        <strong>#LAB<?= (int)$order['order_id'] ?></strong><br>
                        <small><?= e(date('d M Y', strtotime($order['ordered_at']))) ?></small>
                    </td>
                    <td>
                        <?= e($order['patient_name']) ?><br>
                        <small><?= e($order['patient_id']) ?></small>
                    </td>
                    <td>
                        <strong><?= e($order['test_name']) ?></strong><br>
                        <small><?= e($order['category']) ?></small>
                    </td>
                    <td><?= e($order['sample_type']) ?></td>
                    <td>
                        <span class="priority <?= e($order['priority']) ?>">
                            <?= e($order['priority']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="status <?= e(str_replace(' ', '-', $order['order_status'])) ?>">
                            <?= e($order['order_status']) ?>
                        </span>
                        <?php if ($order['result_value'] !== null && $order['result_value'] !== ''): ?>
                            <br><small>Result: <?= e($order['result_value']) ?> <?= e($order['result_unit']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['lab_csrf']) ?>">
                            <input type="hidden" name="action" value="update_order">
                            <input type="hidden" name="order_id" value="<?= (int)$order['order_id'] ?>">

                            <select name="order_status" required>
                                <?php
                                $statuses = [
                                    'Requested',
                                    'Sample Collected',
                                    'Processing',
                                    'Completed',
                                    'Cancelled'
                                ];
                                foreach ($statuses as $status):
                                ?>
                                    <option value="<?= e($status) ?>"
                                        <?= $order['order_status'] === $status ? 'selected' : '' ?>>
                                        <?= e($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <input type="text" name="result_value"
                                placeholder="Result value"
                                value="<?= e($order['result_value']) ?>">

                            <input type="text" name="result_unit"
                                placeholder="Result unit"
                                value="<?= e($order['result_unit']) ?>">

                            <textarea name="result_notes" placeholder="Result notes"><?= e($order['result_notes']) ?></textarea>

                            <button type="submit" class="btn small">Save Update</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<!-- TEST CATALOGUE -->
<section class="panel" id="tests">
    <h3>⚗ Laboratory Test Catalogue</h3>
    <p class="panel-sub">Available tests and their reference information.</p>

    <div class="test-list">
        <?php foreach ($tests as $test): ?>
            <div class="test-item">
                <strong><?= e($test['test_name']) ?></strong>
                <small>
                    <?= e($test['category']) ?>
                    <?php if ($test['normal_range']): ?>
                        · Reference: <?= e($test['normal_range']) ?>
                    <?php endif; ?>
                    <br>
                    Price: ₹<?= number_format((float)$test['test_price'], 2) ?>
                </small>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ACTIVITY CHART -->
<section class="panel" id="activity">
    <h3>▥ Laboratory Order Activity</h3>
    <p class="panel-sub">Number of laboratory orders received over the last seven days.</p>
    <div class="chart-box">
        <canvas id="labChart"></canvas>
    </div>
</section>

<footer style="text-align:center;color:#8a96a6;font-size:12px;padding:15px">
    MediPulse Hospital Management System · Laboratory Module
</footer>

</main>
</div>

<script>
const labels = <?= json_encode($chartLabels) ?>;
const values = <?= json_encode($chartValues) ?>;

new Chart(document.getElementById('labChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [{
            label: 'Lab Orders',
            data: values,
            backgroundColor: '#20a995',
            borderRadius: 6,
            maxBarThickness: 45
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0 }
            }
        }
    }
});
</script>
</body>
</html>
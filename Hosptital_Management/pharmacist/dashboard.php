
<?php
session_start();
require_once "../config.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "success";

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Create pharmacy tables automatically
$pdo->exec("
    CREATE TABLE IF NOT EXISTS pharmacy_medicines (
        medicine_id INT AUTO_INCREMENT PRIMARY KEY,
        medicine_name VARCHAR(150) NOT NULL,
        category VARCHAR(100),
        manufacturer VARCHAR(150),
        batch_number VARCHAR(80),
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        stock_quantity INT NOT NULL DEFAULT 0,
        minimum_stock INT NOT NULL DEFAULT 10,
        expiry_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS pharmacy_transactions (
        transaction_id INT AUTO_INCREMENT PRIMARY KEY,
        medicine_id INT NOT NULL,
        transaction_type ENUM('IN','OUT') NOT NULL,
        quantity INT NOT NULL,
        patient_id VARCHAR(10) NULL,
        appointment_id VARCHAR(10) NULL,
        notes VARCHAR(255),
        transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (medicine_id)
            REFERENCES pharmacy_medicines(medicine_id),
        FOREIGN KEY (patient_id)
            REFERENCES patients(patient_id),
        FOREIGN KEY (appointment_id)
            REFERENCES appointments(appointment_id)
    ) ENGINE=InnoDB
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS pharmacy_dispensing (
        dispensing_id INT AUTO_INCREMENT PRIMARY KEY,
        medicine_id INT NOT NULL,
        patient_id VARCHAR(10) NOT NULL,
        appointment_id VARCHAR(10) NULL,
        quantity INT NOT NULL,
        notes VARCHAR(255),
        dispensing_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (medicine_id)
            REFERENCES pharmacy_medicines(medicine_id),
        FOREIGN KEY (patient_id)
            REFERENCES patients(patient_id),
        FOREIGN KEY (appointment_id)
            REFERENCES appointments(appointment_id)
    ) ENGINE=InnoDB
");

// Generate CSRF-protected form handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception("Invalid request. Refresh the page.");
        }

        $action = $_POST['action'] ?? '';

        // ADD MEDICINE
        if ($action === 'add_medicine') {
            $name = trim($_POST['medicine_name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $manufacturer = trim($_POST['manufacturer'] ?? '');
            $batch = trim($_POST['batch_number'] ?? '');
            $price = $_POST['unit_price'] ?? '';
            $stock = $_POST['stock_quantity'] ?? '';
            $minimum = $_POST['minimum_stock'] ?? '';
            $expiry = $_POST['expiry_date'] ?? '';

            if ($name === '') {
                throw new Exception("Medicine name is required.");
            }

            if (!is_numeric($price) || !is_finite((float)$price) ||
                (float)$price < 0 || (float)$price > 99999999.99) {
                throw new Exception("Enter a valid medicine price.");
            }

            if (!ctype_digit((string)$stock) || (int)$stock < 0) {
                throw new Exception("Enter a valid stock quantity.");
            }

            if (!ctype_digit((string)$minimum) || (int)$minimum < 0) {
                throw new Exception("Enter a valid minimum stock.");
            }

            if ($expiry !== '') {
                $date = DateTime::createFromFormat('Y-m-d', $expiry);
                if (!$date || $date->format('Y-m-d') !== $expiry) {
                    throw new Exception("Invalid expiry date.");
                }
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO pharmacy_medicines
                (medicine_name, category, manufacturer, batch_number,
                 unit_price, stock_quantity, minimum_stock, expiry_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $name,
                $category ?: null,
                $manufacturer ?: null,
                $batch ?: null,
                $price,
                (int)$stock,
                (int)$minimum,
                $expiry ?: null
            ]);

            $medicineId = (int)$pdo->lastInsertId();

            if ((int)$stock > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO pharmacy_transactions
                    (medicine_id, transaction_type, quantity, notes)
                    VALUES (?, 'IN', ?, 'Initial stock')
                ");
                $stmt->execute([$medicineId, (int)$stock]);
            }

            $pdo->commit();
            $message = "Medicine added successfully.";
        }

        // UPDATE STOCK
        elseif ($action === 'update_stock') {
            $medicineId = $_POST['medicine_id'] ?? '';
            $type = $_POST['transaction_type'] ?? '';
            $quantity = $_POST['quantity'] ?? '';
            $notes = trim($_POST['notes'] ?? '');

            if (!ctype_digit((string)$medicineId) ||
                (int)$medicineId <= 0) {
                throw new Exception("Select a valid medicine.");
            }

            if (!in_array($type, ['IN', 'OUT'], true)) {
                throw new Exception("Invalid stock transaction.");
            }

            if (!ctype_digit((string)$quantity) ||
                (int)$quantity <= 0 || (int)$quantity > 1000000) {
                throw new Exception("Enter a valid stock quantity.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT medicine_id, stock_quantity
                FROM pharmacy_medicines
                WHERE medicine_id = ?
                FOR UPDATE
            ");
            $stmt->execute([(int)$medicineId]);
            $medicine = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$medicine) {
                throw new Exception("Medicine not found.");
            }

            $currentStock = (int)$medicine['stock_quantity'];
            $quantity = (int)$quantity;

            if ($type === 'OUT' && $quantity > $currentStock) {
                throw new Exception("Not enough stock available.");
            }

            $newStock = $type === 'IN'
                ? $currentStock + $quantity
                : $currentStock - $quantity;

            $stmt = $pdo->prepare("
                UPDATE pharmacy_medicines
                SET stock_quantity = ?
                WHERE medicine_id = ?
            ");
            $stmt->execute([$newStock, (int)$medicineId]);

            $stmt = $pdo->prepare("
                INSERT INTO pharmacy_transactions
                (medicine_id, transaction_type, quantity, notes)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                (int)$medicineId,
                $type,
                $quantity,
                $notes ?: null
            ]);

            $pdo->commit();

            $message = "Medicine stock updated successfully.";
        }

        // ISSUE MEDICINE TO PATIENT
        elseif ($action === 'dispense') {
            $medicineId = $_POST['medicine_id'] ?? '';
            $patientId = trim($_POST['patient_id'] ?? '');
            $appointmentId = trim($_POST['appointment_id'] ?? '');
            $quantity = $_POST['quantity'] ?? '';
            $notes = trim($_POST['notes'] ?? '');

            if (!ctype_digit((string)$medicineId) ||
                (int)$medicineId <= 0 ||
                $patientId === '') {
                throw new Exception("Select a medicine and patient.");
            }

            if (!ctype_digit((string)$quantity) ||
                (int)$quantity <= 0 || (int)$quantity > 1000000) {
                throw new Exception("Enter a valid quantity.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT medicine_id, stock_quantity
                FROM pharmacy_medicines
                WHERE medicine_id = ?
                FOR UPDATE
            ");
            $stmt->execute([(int)$medicineId]);
            $medicine = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$medicine) {
                throw new Exception("Medicine not found.");
            }

            $stmt = $pdo->prepare("
                SELECT patient_id
                FROM patients
                WHERE patient_id = ?
            ");
            $stmt->execute([$patientId]);

            if (!$stmt->fetch()) {
                throw new Exception("Patient not found.");
            }

            if ($appointmentId !== '') {
                $stmt = $pdo->prepare("
                    SELECT appointment_id, patient_id
                    FROM appointments
                    WHERE appointment_id = ?
                ");
                $stmt->execute([$appointmentId]);
                $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$appointment ||
                    $appointment['patient_id'] !== $patientId) {
                    throw new Exception(
                        "Appointment does not belong to the selected patient."
                    );
                }
            }

            $quantity = (int)$quantity;

            if ($quantity > (int)$medicine['stock_quantity']) {
                throw new Exception("Insufficient medicine stock.");
            }

            $stmt = $pdo->prepare("
                UPDATE pharmacy_medicines
                SET stock_quantity = stock_quantity - ?
                WHERE medicine_id = ?
            ");
            $stmt->execute([$quantity, (int)$medicineId]);

            $stmt = $pdo->prepare("
                INSERT INTO pharmacy_dispensing
                (medicine_id, patient_id, appointment_id, quantity, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                (int)$medicineId,
                $patientId,
                $appointmentId ?: null,
                $quantity,
                $notes ?: null
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO pharmacy_transactions
                (medicine_id, transaction_type, quantity,
                 patient_id, appointment_id, notes)
                VALUES (?, 'OUT', ?, ?, ?, ?)
            ");
            $stmt->execute([
                (int)$medicineId,
                $quantity,
                $patientId,
                $appointmentId ?: null,
                $notes ?: 'Issued to patient'
            ]);

            $pdo->commit();
            $message = "Medicine issue recorded successfully.";
        }

        else {
            throw new Exception("Invalid action.");
        }

    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Database error: " . $ex->getMessage();
        $messageType = "error";

    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $ex->getMessage();
        $messageType = "error";
    }
}

// DASHBOARD DATA

$totalMedicines = (int)$pdo->query("
    SELECT COUNT(*) FROM pharmacy_medicines
")->fetchColumn();

$totalStock = (int)$pdo->query("
    SELECT COALESCE(SUM(stock_quantity), 0)
    FROM pharmacy_medicines
")->fetchColumn();

$lowStock = (int)$pdo->query("
    SELECT COUNT(*)
    FROM pharmacy_medicines
    WHERE stock_quantity <= minimum_stock
")->fetchColumn();

$expiredMedicines = (int)$pdo->query("
    SELECT COUNT(*)
    FROM pharmacy_medicines
    WHERE expiry_date IS NOT NULL
      AND expiry_date < CURDATE()
")->fetchColumn();

$expiringSoon = (int)$pdo->query("
    SELECT COUNT(*)
    FROM pharmacy_medicines
    WHERE expiry_date BETWEEN CURDATE()
      AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetchColumn();

$todayDispensing = (int)$pdo->query("
    SELECT COUNT(*)
    FROM pharmacy_dispensing
    WHERE DATE(dispensing_date) = CURDATE()
")->fetchColumn();

// MEDICINE LIST
$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT *
        FROM pharmacy_medicines
        WHERE medicine_name LIKE ?
           OR category LIKE ?
           OR manufacturer LIKE ?
           OR batch_number LIKE ?
        ORDER BY medicine_name
    ");
    $term = "%$search%";
    $stmt->execute([$term, $term, $term, $term]);
    $medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $medicines = $pdo->query("
        SELECT *
        FROM pharmacy_medicines
        ORDER BY medicine_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// PATIENTS
$patients = $pdo->query("
    SELECT patient_id, patient_name
    FROM patients
    ORDER BY patient_name
")->fetchAll(PDO::FETCH_ASSOC);

// APPOINTMENTS
$appointments = $pdo->query("
    SELECT a.appointment_id, a.patient_id,
           a.appointment_date, p.patient_name
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.patient_id
    ORDER BY a.appointment_date DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// RECENT TRANSACTIONS
$transactions = $pdo->query("
    SELECT t.transaction_id, t.transaction_type,
           t.quantity, t.notes, t.transaction_date,
           m.medicine_name, p.patient_name
    FROM pharmacy_transactions t
    INNER JOIN pharmacy_medicines m
        ON t.medicine_id = m.medicine_id
    LEFT JOIN patients p ON t.patient_id = p.patient_id
    ORDER BY t.transaction_date DESC, t.transaction_id DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// LOW STOCK MEDICINES
$lowStockList = $pdo->query("
    SELECT medicine_name, stock_quantity, minimum_stock
    FROM pharmacy_medicines
    WHERE stock_quantity <= minimum_stock
    ORDER BY stock_quantity ASC
")->fetchAll(PDO::FETCH_ASSOC);

// EXPIRY ALERTS
$expiryList = $pdo->query("
    SELECT medicine_name, batch_number, expiry_date, stock_quantity
    FROM pharmacy_medicines
    WHERE expiry_date IS NOT NULL
      AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY expiry_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// MONTHLY STOCK-OUT ANALYTICS
$monthly = $pdo->query("
    SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month_name,
           SUM(quantity) AS total
    FROM pharmacy_transactions
    WHERE transaction_type = 'OUT'
      AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
    ORDER BY month_name
")->fetchAll(PDO::FETCH_ASSOC);

$chartLabels = [];
$chartValues = [];

foreach ($monthly as $row) {
    $chartLabels[] = date('M Y', strtotime($row['month_name'] . '-01'));
    $chartValues[] = (int)$row['total'];
}

$userName = $_SESSION['full_name'] ?? 'Pharmacist';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediPulse | Pharmacist Dashboard</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: Arial, sans-serif;
}

body {
    background: #f4f7fb;
    color: #263247;
}

.sidebar {
    width: 245px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: #14213d;
    color: white;
    padding: 25px 17px;
    overflow-y: auto;
}

.logo {
    font-size: 25px;
    font-weight: bold;
    margin-bottom: 35px;
    color: #58d6c8;
}

.sidebar h4 {
    color: #aab8cf;
    font-size: 12px;
    margin: 20px 10px 12px;
    text-transform: uppercase;
}

.sidebar a {
    display: block;
    color: #dce5f4;
    text-decoration: none;
    padding: 13px 12px;
    border-radius: 8px;
    margin-bottom: 7px;
    font-size: 14px;
}

.sidebar a:hover,
.sidebar a.active {
    background: #263b61;
}

.main {
    margin-left: 245px;
    padding: 28px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 25px;
}

.topbar h1 {
    font-size: 25px;
}

.welcome {
    color: #69758a;
    margin-top: 6px;
    font-size: 14px;
}

.user {
    background: white;
    padding: 12px 18px;
    border-radius: 10px;
    box-shadow: 0 2px 10px #dfe5ee;
    font-size: 14px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 17px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 2px 10px #e4e9f1;
    border-left: 4px solid #168f91;
}

.stat-card p {
    color: #7c8798;
    font-size: 13px;
}

.stat-card h2 {
    font-size: 27px;
    margin: 12px 0 5px;
}

.stat-card small {
    color: #8b96a7;
}

.section-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 22px;
}

.panel {
    background: white;
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 2px 10px #e4e9f1;
    margin-bottom: 22px;
    min-width: 0;
}

.panel h3 {
    margin-bottom: 18px;
    font-size: 18px;
}

label {
    display: block;
    font-size: 13px;
    font-weight: bold;
    margin: 12px 0 7px;
    color: #45536a;
}

input, select, textarea {
    width: 100%;
    padding: 11px;
    border: 1px solid #d8e0eb;
    border-radius: 7px;
    background: white;
    font-size: 14px;
}

textarea {
    resize: vertical;
}

button {
    border: none;
    background: #168f91;
    color: white;
    padding: 11px 17px;
    border-radius: 7px;
    cursor: pointer;
    margin-top: 15px;
    font-weight: bold;
}

button:hover {
    background: #107477;
}

.alert {
    padding: 13px 17px;
    margin-bottom: 20px;
    border-radius: 8px;
    font-size: 14px;
}

.success {
    background: #dff7e8;
    color: #176c3a;
}

.error {
    background: #ffe5e5;
    color: #a32929;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 650px;
}

th {
    background: #f1f5fa;
    color: #526178;
    text-align: left;
    padding: 13px;
}

td {
    padding: 13px;
    border-bottom: 1px solid #edf0f5;
}

.badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
}

.low {
    background: #fff1cf;
    color: #956000;
}

.expired {
    background: #ffe0e0;
    color: #a52b2b;
}

.good {
    background: #d8f5e3;
    color: #16733d;
}

.search-form {
    display: flex;
    gap: 10px;
    margin-bottom: 17px;
}

.search-form button {
    margin: 0;
}

.chart-container {
    height: 270px;
}

.note {
    font-size: 12px;
    color: #788598;
    margin-top: 12px;
    line-height: 1.5;
}

.footer {
    text-align: center;
    padding: 20px;
    color: #8994a4;
    font-size: 12px;
}

@media(max-width: 1100px) {
    .stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 750px) {
    .sidebar {
        width: 100%;
        height: auto;
        position: relative;
    }

    .main {
        margin-left: 0;
        padding: 16px;
    }

    .section-grid {
        grid-template-columns: 1fr;
    }

    .stats {
        grid-template-columns: 1fr 1fr;
    }

    .topbar {
        align-items: flex-start;
        flex-direction: column;
    }
}

@media(max-width: 430px) {
    .stats {
        grid-template-columns: 1fr;
    }

    .search-form {
        flex-direction: column;
    }
}
</style>
</head>

<body>

<aside class="sidebar">
    <div class="logo">✚ MediPulse</div>

    <h4>Pharmacy</h4>
    <a href="dashboard.php" class="active">▣ Dashboard</a>
    <a href="#add-medicine">＋ Add Medicine</a>
    <a href="#inventory">▤ Medicine Inventory</a>
    <a href="#stock">↕ Update Stock</a>
    <a href="#dispense">＋ Issue Medicine</a>
    <a href="#alerts">⚠ Stock Alerts</a>
    <a href="#transactions">◷ Transactions</a>
    <a href="#analytics">▥ Analytics</a>

    <h4>Account</h4>
    <a href="../login/logout.php">↪ Logout</a>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h1>Pharmacist Dashboard</h1>
            <p class="welcome">
                Manage medicine inventory, stock and patient medicine issues.
            </p>
        </div>

        <div class="user">
            Welcome, <strong><?php echo e($userName); ?></strong>
            <br><?php echo date('d M Y'); ?>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert <?php echo e($messageType); ?>">
            <?php echo e($message); ?>
        </div>
    <?php endif; ?>

    <!-- STATISTICS -->

    <div class="stats">

        <div class="stat-card">
            <p>Total Medicines</p>
            <h2><?php echo $totalMedicines; ?></h2>
            <small>Medicine records</small>
        </div>

        <div class="stat-card">
            <p>Total Stock Units</p>
            <h2><?php echo $totalStock; ?></h2>
            <small>Available units</small>
        </div>

        <div class="stat-card">
            <p>Low Stock Medicines</p>
            <h2><?php echo $lowStock; ?></h2>
            <small>At or below minimum stock</small>
        </div>

        <div class="stat-card">
            <p>Expired Medicines</p>
            <h2><?php echo $expiredMedicines; ?></h2>
            <small>Past expiry date</small>
        </div>

        <div class="stat-card">
            <p>Expiring Within 30 Days</p>
            <h2><?php echo $expiringSoon; ?></h2>
            <small>Expiry monitoring</small>
        </div>

        <div class="stat-card">
            <p>Today's Medicine Issues</p>
            <h2><?php echo $todayDispensing; ?></h2>
            <small>Recorded dispensing entries</small>
        </div>

    </div>

    <!-- ADD MEDICINE -->

    <div class="section-grid">

        <section class="panel" id="add-medicine">
            <h3>＋ Add New Medicine</h3>

            <form method="POST">

                <input type="hidden" name="csrf_token"
                    value="<?php echo e($_SESSION['csrf_token']); ?>">

                <input type="hidden" name="action" value="add_medicine">

                <label>Medicine Name *</label>
                <input type="text" name="medicine_name"
                    maxlength="150" required placeholder="Enter medicine name">

                <label>Category</label>
                <input type="text" name="category"
                    maxlength="100" placeholder="Tablet / Syrup / Injection">

                <label>Manufacturer</label>
                <input type="text" name="manufacturer"
                    maxlength="150" placeholder="Manufacturer name">

                <label>Batch Number</label>
                <input type="text" name="batch_number"
                    maxlength="80" placeholder="Enter batch number">

                <label>Unit Price (₹)</label>
                <input type="number" name="unit_price"
                    min="0" max="99999999.99" step="0.01" value="0">

                <label>Opening Stock *</label>
                <input type="number" name="stock_quantity"
                    min="0" max="1000000" value="0" required>

                <label>Minimum Stock Alert *</label>
                <input type="number" name="minimum_stock"
                    min="0" max="1000000" value="10" required>

                <label>Expiry Date</label>
                <input type="date" name="expiry_date">

                <button type="submit">Add Medicine</button>
            </form>
        </section>

        <!-- STOCK UPDATE -->

        <section class="panel" id="stock">
            <h3>↕ Update Medicine Stock</h3>

            <form method="POST">

                <input type="hidden" name="csrf_token"
                    value="<?php echo e($_SESSION['csrf_token']); ?>">

                <input type="hidden" name="action" value="update_stock">

                <label>Select Medicine *</label>

                <select name="medicine_id" required>
                    <option value="">Select Medicine</option>

                    <?php foreach ($medicines as $m): ?>
                        <option value="<?php echo e($m['medicine_id']); ?>">
                            <?php
                            echo e($m['medicine_name']);
                            echo " (Stock: " . e($m['stock_quantity']) . ")";
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Transaction Type *</label>
                <select name="transaction_type" required>
                    <option value="IN">Stock In (Purchase / Restock)</option>
                    <option value="OUT">Stock Out (Adjustment / Damaged)</option>
                </select>

                <label>Quantity *</label>
                <input type="number" name="quantity"
                    min="1" max="1000000" required>

                <label>Notes</label>
                <textarea name="notes" rows="3"
                    maxlength="255" placeholder="Enter reason or stock details"></textarea>

                <button type="submit">Update Stock</button>
            </form>
        </section>

    </div>

    <!-- ISSUE MEDICINE -->

    <section class="panel" id="dispense">
        <h3>＋ Record Medicine Issued to Patient</h3>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                value="<?php echo e($_SESSION['csrf_token']); ?>">

            <input type="hidden" name="action" value="dispense">

            <div class="section-grid">

                <div>
                    <label>Select Medicine *</label>
                    <select name="medicine_id" required>
                        <option value="">Select Medicine</option>

                        <?php foreach ($medicines as $m): ?>
                            <?php if ((int)$m['stock_quantity'] > 0): ?>
                            <option value="<?php echo e($m['medicine_id']); ?>">
                                <?php
                                echo e($m['medicine_name']);
                                echo " - Stock: " . e($m['stock_quantity']);
                                ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>

                    <label>Select Patient *</label>
                    <select name="patient_id" required>
                        <option value="">Select Patient</option>

                        <?php foreach ($patients as $p): ?>
                            <option value="<?php echo e($p['patient_id']); ?>">
                                <?php
                                echo e($p['patient_name']);
                                echo " (" . e($p['patient_id']) . ")";
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Related Appointment (Optional)</label>
                    <select name="appointment_id">
                        <option value="">No Appointment Selected</option>

                        <?php foreach ($appointments as $a): ?>
                            <option value="<?php echo e($a['appointment_id']); ?>">
                                <?php
                                echo e($a['appointment_id']);
                                echo " - " . e($a['patient_name']);
                                echo " - " . e($a['appointment_date']);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label>Quantity *</label>
                    <input type="number" name="quantity"
                        min="1" max="1000000" required>

                    <label>Notes</label>
                    <input type="text" name="notes"
                        maxlength="255" placeholder="Enter dispensing notes">
                </div>

            </div>

            <button type="submit">Record Medicine Issue</button>

        </form>

        <p class="note">
            Record dispensing only according to a valid prescription and
            hospital policy. This form records inventory movement; it does
            not create or verify a doctor's prescription.
        </p>
    </section>

    <!-- INVENTORY -->

    <section class="panel" id="inventory">
        <h3>▤ Medicine Inventory</h3>

        <form method="GET" class="search-form">
            <input type="text" name="search"
                placeholder="Search medicine, category, manufacturer or batch..."
                value="<?php echo e($search); ?>">
            <button type="submit">Search</button>
        </form>

        <div class="table-wrap">
        <table>

            <thead>
                <tr>
                    <th>ID</th>
                    <th>Medicine</th>
                    <th>Category</th>
                    <th>Manufacturer</th>
                    <th>Batch</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Expiry Date</th>
                    <th>Stock Status</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($medicines) > 0): ?>

                <?php foreach ($medicines as $m): ?>

                <?php
                    $isExpired = !empty($m['expiry_date']) &&
                        $m['expiry_date'] < date('Y-m-d');

                    $isLow = (int)$m['stock_quantity'] <=
                        (int)$m['minimum_stock'];
                ?>

                <tr>
                    <td><?php echo e($m['medicine_id']); ?></td>
                    <td><?php echo e($m['medicine_name']); ?></td>
                    <td><?php echo e($m['category']); ?></td>
                    <td><?php echo e($m['manufacturer']); ?></td>
                    <td><?php echo e($m['batch_number']); ?></td>
                    <td>₹<?php echo number_format((float)$m['unit_price'], 2); ?></td>
                    <td><?php echo e($m['stock_quantity']); ?></td>
                    <td><?php echo e($m['expiry_date'] ?: 'Not entered'); ?></td>
                    <td>
                        <?php if ($isExpired): ?>
                            <span class="badge expired">Expired</span>
                        <?php elseif ($isLow): ?>
                            <span class="badge low">Low Stock</span>
                        <?php else: ?>
                            <span class="badge good">Available</span>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="9">No medicine records found.</td>
                </tr>

            <?php endif; ?>

            </tbody>
        </table>
        </div>
    </section>

    <!-- ALERTS -->

    <div class="section-grid" id="alerts">

        <section class="panel">
            <h3>⚠ Low Stock Alerts</h3>

            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Current Stock</th>
                        <th>Minimum Stock</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($lowStockList) > 0): ?>

                    <?php foreach ($lowStockList as $m): ?>
                    <tr>
                        <td><?php echo e($m['medicine_name']); ?></td>
                        <td><?php echo e($m['stock_quantity']); ?></td>
                        <td><?php echo e($m['minimum_stock']); ?></td>
                    </tr>
                    <?php endforeach; ?>

                <?php else: ?>
                    <tr><td colspan="3">No low-stock medicines.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </section>

        <section class="panel">
            <h3>⚠ Expiry Alerts</h3>

            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Batch</th>
                        <th>Expiry Date</th>
                        <th>Stock</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($expiryList) > 0): ?>

                    <?php foreach ($expiryList as $m): ?>
                    <tr>
                        <td><?php echo e($m['medicine_name']); ?></td>
                        <td><?php echo e($m['batch_number']); ?></td>
                        <td>
                            <?php echo e($m['expiry_date']); ?>
                            <?php if ($m['expiry_date'] < date('Y-m-d')): ?>
                                <span class="badge expired">Expired</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($m['stock_quantity']); ?></td>
                    </tr>
                    <?php endforeach; ?>

                <?php else: ?>
                    <tr><td colspan="4">No expiry alerts.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </section>

    </div>

    <!-- ANALYTICS -->

    <section class="panel" id="analytics">
        <h3>Monthly Medicine Stock-Out Analytics</h3>

        <div class="chart-container">
            <canvas id="stockChart"></canvas>
        </div>
    </section>

    <!-- TRANSACTIONS -->

    <section class="panel" id="transactions">
        <h3>Recent Stock Transactions</h3>

        <div class="table-wrap">
        <table>

            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Medicine</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Patient</th>
                    <th>Notes</th>
                    <th>Date</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($transactions) > 0): ?>

                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td><?php echo e($t['transaction_id']); ?></td>
                    <td><?php echo e($t['medicine_name']); ?></td>
                    <td>
                        <?php if ($t['transaction_type'] === 'IN'): ?>
                            <span class="badge good">Stock In</span>
                        <?php else: ?>
                            <span class="badge low">Stock Out</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($t['quantity']); ?></td>
                    <td><?php echo e($t['patient_name'] ?: '-'); ?></td>
                    <td><?php echo e($t['notes']); ?></td>
                    <td><?php echo e($t['transaction_date']); ?></td>
                </tr>
                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="7">No transactions recorded yet.</td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>
        </div>
    </section>

    <div class="footer">
        MediPulse Hospital Management System | Pharmacist Dashboard
    </div>

</main>

<script>
const chartLabels = <?php echo json_encode($chartLabels); ?>;
const chartValues = <?php echo json_encode($chartValues); ?>;

new Chart(document.getElementById('stockChart'), {
    type: 'bar',

    data: {
        labels: chartLabels,
        datasets: [{
            label: 'Medicine Units Issued',
            data: chartValues,
            backgroundColor: '#168f91',
            borderRadius: 6
        }]
    },

    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        }
    }
});
</script>

</body>
</html>

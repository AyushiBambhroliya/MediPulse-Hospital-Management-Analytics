
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

function generateUniqueId($pdo, $table, $column, $prefix) {
    $allowed = [
        'billing' => ['bill_id'],
        'payments' => ['payment_id']
    ];

    if (!isset($allowed[$table]) ||
        !in_array($column, $allowed[$table], true)) {
        throw new Exception("Invalid ID configuration.");
    }

    do {
        $id = $prefix . strtoupper(bin2hex(random_bytes(4)));

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE `$column` = ?"
        );
        $stmt->execute([$id]);
        $exists = $stmt->fetchColumn();

    } while ($exists > 0);

    return $id;
}

// Process forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception("Invalid request. Refresh the page and try again.");
        }

        $action = $_POST['action'] ?? '';

        // CREATE BILL
        if ($action === 'create_bill') {
            $appointmentId = trim($_POST['appointment_id'] ?? '');
            $amount = $_POST['amount'] ?? '';

            if ($appointmentId === '' || $amount === '') {
                throw new Exception("Please select an appointment and enter an amount.");
            }

            if (!is_numeric($amount) || (float)$amount <= 0 ||
                !is_finite((float)$amount) || (float)$amount > 9999999999.99) {
                throw new Exception("Enter a valid bill amount.");
            }

            $pdo->beginTransaction();

            // Get patient from selected appointment
            $stmt = $pdo->prepare("
                SELECT appointment_id, patient_id
                FROM appointments
                WHERE appointment_id = ?
            ");
            $stmt->execute([$appointmentId]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appointment) {
                throw new Exception("Appointment not found.");
            }

            // Avoid creating duplicate bills for the same appointment
            $stmt = $pdo->prepare("
                SELECT bill_id
                FROM billing
                WHERE appointment_id = ?
            ");
            $stmt->execute([$appointmentId]);

            if ($stmt->fetch()) {
                throw new Exception("A bill already exists for this appointment.");
            }

            $billId = generateUniqueId(
                $pdo, 'billing', 'bill_id', 'B'
            );

            $stmt = $pdo->prepare("
                INSERT INTO billing
                (bill_id, patient_id, appointment_id, bill_date, amount)
                VALUES (?, ?, ?, CURDATE(), ?)
            ");

            $stmt->execute([
                $billId,
                $appointment['patient_id'],
                $appointmentId,
                number_format((float)$amount, 2, '.', '')
            ]);

            $pdo->commit();

            $message = "Bill created successfully. Bill ID: " . $billId;
        }

        // RECORD PAYMENT
        elseif ($action === 'record_payment') {
            $billId = trim($_POST['bill_id'] ?? '');
            $paymentAmount = $_POST['amount_paid'] ?? '';
            $method = $_POST['payment_method'] ?? '';

            $allowedMethods = [
                'Cash',
                'UPI',
                'Card',
                'Bank Transfer'
            ];

            if ($billId === '' || $paymentAmount === '') {
                throw new Exception("Select a bill and enter the payment amount.");
            }

            if (!in_array($method, $allowedMethods, true)) {
                throw new Exception("Select a valid payment method.");
            }

            if (!is_numeric($paymentAmount) ||
                (float)$paymentAmount <= 0 ||
                !is_finite((float)$paymentAmount) ||
                (float)$paymentAmount > 9999999999.99) {
                throw new Exception("Enter a valid payment amount.");
            }

            $pdo->beginTransaction();

            // Lock bill while checking remaining amount
            $stmt = $pdo->prepare("
                SELECT bill_id, amount
                FROM billing
                WHERE bill_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$billId]);
            $bill = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bill) {
                throw new Exception("Bill not found.");
            }

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount_paid), 0)
                FROM payments
                WHERE bill_id = ?
            ");
            $stmt->execute([$billId]);

            $alreadyPaid = (float)$stmt->fetchColumn();
            $billAmount = (float)$bill['amount'];
            $remaining = round($billAmount - $alreadyPaid, 2);
            $newPayment = round((float)$paymentAmount, 2);

            if ($remaining <= 0) {
                throw new Exception("This bill is already fully paid.");
            }

            if ($newPayment > $remaining) {
                throw new Exception(
                    "Payment exceeds the remaining balance of ₹" .
                    number_format($remaining, 2)
                );
            }

            $paymentId = generateUniqueId(
                $pdo, 'payments', 'payment_id', 'PAY'
            );

            $newTotalPaid = round($alreadyPaid + $newPayment, 2);

            if ($newTotalPaid >= $billAmount) {
                $paymentStatus = 'Paid';
            } elseif ($newTotalPaid > 0) {
                $paymentStatus = 'Partial';
            } else {
                $paymentStatus = 'Pending';
            }

            $stmt = $pdo->prepare("
                INSERT INTO payments
                (payment_id, bill_id, payment_date,
                 payment_method, amount_paid, payment_status)
                VALUES (?, ?, CURDATE(), ?, ?, ?)
            ");

            $stmt->execute([
                $paymentId,
                $billId,
                $method,
                number_format($newPayment, 2, '.', ''),
                $paymentStatus
            ]);

            $pdo->commit();

            $message = "Payment recorded successfully. Payment ID: " . $paymentId;
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

// BILLING STATISTICS
$totalBills = (int)$pdo->query("
    SELECT COUNT(*) FROM billing
")->fetchColumn();

$totalBilled = (float)$pdo->query("
    SELECT COALESCE(SUM(amount), 0) FROM billing
")->fetchColumn();

$totalCollected = (float)$pdo->query("
    SELECT COALESCE(SUM(amount_paid), 0) FROM payments
")->fetchColumn();

$totalOutstanding = max(0, round($totalBilled - $totalCollected, 2));

$todayCollection = (float)$pdo->query("
    SELECT COALESCE(SUM(amount_paid), 0)
    FROM payments
    WHERE payment_date = CURDATE()
")->fetchColumn();

$todayBills = (int)$pdo->query("
    SELECT COUNT(*)
    FROM billing
    WHERE bill_date = CURDATE()
")->fetchColumn();

// APPOINTMENTS WITHOUT EXISTING BILLS
$availableAppointments = $pdo->query("
    SELECT a.appointment_id,
           a.appointment_date,
           p.patient_id,
           p.patient_name,
           d.doctor_name
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.patient_id
    INNER JOIN doctors d ON a.doctor_id = d.doctor_id
    LEFT JOIN billing b ON a.appointment_id = b.appointment_id
    WHERE b.bill_id IS NULL
    ORDER BY a.appointment_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// BILLS WITH OUTSTANDING BALANCE
$availableBills = $pdo->query("
    SELECT b.bill_id,
           b.amount,
           p.patient_name,
           COALESCE(SUM(pay.amount_paid), 0) AS paid_amount
    FROM billing b
    INNER JOIN patients p ON b.patient_id = p.patient_id
    LEFT JOIN payments pay ON b.bill_id = pay.bill_id
    GROUP BY b.bill_id, b.amount, p.patient_name
    HAVING b.amount > paid_amount
    ORDER BY b.bill_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ALL BILL RECORDS
$bills = $pdo->query("
    SELECT b.bill_id,
           b.bill_date,
           b.amount,
           b.appointment_id,
           p.patient_name,
           COALESCE(SUM(pay.amount_paid), 0) AS paid_amount
    FROM billing b
    INNER JOIN patients p ON b.patient_id = p.patient_id
    LEFT JOIN payments pay ON b.bill_id = pay.bill_id
    GROUP BY b.bill_id, b.bill_date, b.amount,
             b.appointment_id, p.patient_name
    ORDER BY b.bill_date DESC, b.bill_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// PAYMENT HISTORY
$payments = $pdo->query("
    SELECT pay.payment_id,
           pay.payment_date,
           pay.payment_method,
           pay.amount_paid,
           pay.payment_status,
           b.bill_id,
           p.patient_name
    FROM payments pay
    INNER JOIN billing b ON pay.bill_id = b.bill_id
    INNER JOIN patients p ON b.patient_id = p.patient_id
    ORDER BY pay.payment_date DESC, pay.payment_id DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// BILL SEARCH
$search = trim($_GET['search'] ?? '');
$searchResults = [];

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT b.bill_id,
               b.bill_date,
               b.amount,
               b.appointment_id,
               p.patient_name,
               COALESCE(SUM(pay.amount_paid), 0) AS paid_amount
        FROM billing b
        INNER JOIN patients p ON b.patient_id = p.patient_id
        LEFT JOIN payments pay ON b.bill_id = pay.bill_id
        WHERE b.bill_id LIKE ?
           OR p.patient_name LIKE ?
           OR p.patient_id LIKE ?
        GROUP BY b.bill_id, b.bill_date, b.amount,
                 b.appointment_id, p.patient_name
        ORDER BY b.bill_date DESC
    ");

    $term = "%$search%";
    $stmt->execute([$term, $term, $term]);
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// MONTHLY COLLECTION ANALYTICS
$monthlyData = $pdo->query("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month_name,
           SUM(amount_paid) AS collection
    FROM payments
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
    ORDER BY month_name
")->fetchAll(PDO::FETCH_ASSOC);

$monthlyLabels = [];
$monthlyValues = [];

foreach ($monthlyData as $row) {
    $monthlyLabels[] = date('M Y', strtotime($row['month_name'] . '-01'));
    $monthlyValues[] = (float)$row['collection'];
}

$userName = $_SESSION['full_name'] ?? 'Billing Staff';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>MediPulse | Billing Staff Dashboard</title>

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
    background: #14213d;
    color: white;
    padding: 25px 17px;
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
    color: white;
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
    font-size: 25px;
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

input, select {
    width: 100%;
    padding: 11px;
    border: 1px solid #d8e0eb;
    border-radius: 7px;
    background: white;
    font-size: 14px;
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

.Paid {
    background: #d8f5e3;
    color: #16733d;
}

.Partial {
    background: #fff1cf;
    color: #956000;
}

.Pending {
    background: #ffe0e0;
    color: #a52b2b;
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

    <h4>Billing Staff</h4>

    <a href="dashboard.php" class="active">▣ Dashboard</a>
    <a href="#create-bill">＋ Create Bill</a>
    <a href="#record-payment">₹ Record Payment</a>
    <a href="#bill-records">▤ Bill Records</a>
    <a href="#payment-history">◷ Payment History</a>
    <a href="#analytics">▥ Collection Analytics</a>

    <h4>Account</h4>
    <a href="../login/logout.php">↪ Logout</a>

</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h1>Billing Staff Dashboard</h1>
            <p class="welcome">
                Manage hospital bills, patient payments and outstanding balances.
            </p>
        </div>

        <div class="user">
            Welcome, <strong><?php echo e($userName); ?></strong>
            <br>
            <?php echo date('d M Y'); ?>
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
            <p>Total Bills</p>
            <h2><?php echo $totalBills; ?></h2>
            <small>All generated bills</small>
        </div>

        <div class="stat-card">
            <p>Total Billed Amount</p>
            <h2>₹<?php echo number_format($totalBilled, 2); ?></h2>
            <small>Total billing value</small>
        </div>

        <div class="stat-card">
            <p>Total Collection</p>
            <h2>₹<?php echo number_format($totalCollected, 2); ?></h2>
            <small>Payments received</small>
        </div>

        <div class="stat-card">
            <p>Outstanding Balance</p>
            <h2>₹<?php echo number_format($totalOutstanding, 2); ?></h2>
            <small>Amount remaining to collect</small>
        </div>

        <div class="stat-card">
            <p>Today's Collection</p>
            <h2>₹<?php echo number_format($todayCollection, 2); ?></h2>
            <small>Payments received today</small>
        </div>

        <div class="stat-card">
            <p>Today's Bills</p>
            <h2><?php echo $todayBills; ?></h2>
            <small>Bills generated today</small>
        </div>

    </div>

    <!-- CREATE BILL AND RECORD PAYMENT -->

    <div class="section-grid">

        <section class="panel" id="create-bill">

            <h3>＋ Create New Bill</h3>

            <?php if (count($availableAppointments) > 0): ?>

            <form method="POST">

                <input type="hidden" name="csrf_token"
                    value="<?php echo e($_SESSION['csrf_token']); ?>">

                <input type="hidden" name="action" value="create_bill">

                <label>Select Appointment *</label>

                <select name="appointment_id" required>
                    <option value="">Select Appointment</option>

                    <?php foreach ($availableAppointments as $a): ?>

                    <option value="<?php echo e($a['appointment_id']); ?>">
                        <?php
                        echo e($a['appointment_id']);
                        echo " - " . e($a['patient_name']);
                        echo " - " . e($a['doctor_name']);
                        echo " - " . e($a['appointment_date']);
                        ?>
                    </option>

                    <?php endforeach; ?>

                </select>

                <label>Bill Amount (₹) *</label>

                <input type="number" name="amount"
                    min="0.01" max="9999999999.99"
                    step="0.01" placeholder="Enter bill amount" required>

                <button type="submit">Generate Bill</button>

            </form>

            <?php else: ?>

                <p>All existing appointments already have bills, or no appointments are available.</p>

            <?php endif; ?>

        </section>

        <section class="panel" id="record-payment">

            <h3>₹ Record Patient Payment</h3>

            <?php if (count($availableBills) > 0): ?>

            <form method="POST">

                <input type="hidden" name="csrf_token"
                    value="<?php echo e($_SESSION['csrf_token']); ?>">

                <input type="hidden" name="action" value="record_payment">

                <label>Select Bill *</label>

                <select name="bill_id" required>
                    <option value="">Select Outstanding Bill</option>

                    <?php foreach ($availableBills as $b): ?>

                    <option value="<?php echo e($b['bill_id']); ?>">
                        <?php
                        $balance = max(
                            0,
                            round((float)$b['amount'] - (float)$b['paid_amount'], 2)
                        );

                        echo e($b['bill_id']);
                        echo " - " . e($b['patient_name']);
                        echo " - Balance ₹" . number_format($balance, 2);
                        ?>
                    </option>

                    <?php endforeach; ?>

                </select>

                <label>Payment Amount (₹) *</label>

                <input type="number" name="amount_paid"
                    min="0.01" max="9999999999.99"
                    step="0.01" placeholder="Enter received amount" required>

                <label>Payment Method *</label>

                <select name="payment_method" required>
                    <option value="">Select Method</option>
                    <option value="Cash">Cash</option>
                    <option value="UPI">UPI</option>
                    <option value="Card">Card</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                </select>

                <button type="submit">Save Payment</button>

            </form>

            <?php else: ?>

                <p>No outstanding bills available for payment.</p>

            <?php endif; ?>

        </section>

    </div>

    <!-- SEARCH BILL -->

    <section class="panel" id="bill-records">

        <h3>⌕ Search Bill Records</h3>

        <form method="GET" class="search-form">

            <input type="text" name="search"
                placeholder="Search by bill ID, patient name or patient ID..."
                value="<?php echo e($search); ?>">

            <button type="submit">Search</button>

        </form>

        <?php if ($search !== ''): ?>

        <h4 style="margin-bottom:12px;">
            Search Results: <?php echo count($searchResults); ?>
        </h4>

        <div class="table-wrap">
        <table>

            <thead>
                <tr>
                    <th>Bill ID</th>
                    <th>Patient</th>
                    <th>Appointment ID</th>
                    <th>Bill Date</th>
                    <th>Total Amount</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($searchResults) > 0): ?>

                <?php foreach ($searchResults as $b): ?>

                <?php
                    $balance = max(
                        0,
                        round((float)$b['amount'] - (float)$b['paid_amount'], 2)
                    );

                    $status = $balance <= 0 ? 'Paid' :
                        ((float)$b['paid_amount'] > 0 ? 'Partial' : 'Pending');
                ?>

                <tr>
                    <td><?php echo e($b['bill_id']); ?></td>
                    <td><?php echo e($b['patient_name']); ?></td>
                    <td><?php echo e($b['appointment_id']); ?></td>
                    <td><?php echo e($b['bill_date']); ?></td>
                    <td>₹<?php echo number_format((float)$b['amount'], 2); ?></td>
                    <td>₹<?php echo number_format((float)$b['paid_amount'], 2); ?></td>
                    <td>₹<?php echo number_format($balance, 2); ?></td>
                    <td>
                        <span class="badge <?php echo $status; ?>">
                            <?php echo $status; ?>
                        </span>
                    </td>
                </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="8">No matching bills found.</td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>
        </div>

        <?php endif; ?>

        <h3 style="margin-top:25px;">All Bill Records</h3>

        <div class="table-wrap">
        <table>

            <thead>
                <tr>
                    <th>Bill ID</th>
                    <th>Patient</th>
                    <th>Appointment ID</th>
                    <th>Bill Date</th>
                    <th>Total Amount</th>
                    <th>Paid Amount</th>
                    <th>Outstanding</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($bills) > 0): ?>

                <?php foreach ($bills as $b): ?>

                <?php
                    $balance = max(
                        0,
                        round((float)$b['amount'] - (float)$b['paid_amount'], 2)
                    );

                    $status = $balance <= 0 ? 'Paid' :
                        ((float)$b['paid_amount'] > 0 ? 'Partial' : 'Pending');
                ?>

                <tr>
                    <td><?php echo e($b['bill_id']); ?></td>
                    <td><?php echo e($b['patient_name']); ?></td>
                    <td><?php echo e($b['appointment_id']); ?></td>
                    <td><?php echo e($b['bill_date']); ?></td>
                    <td>₹<?php echo number_format((float)$b['amount'], 2); ?></td>
                    <td>₹<?php echo number_format((float)$b['paid_amount'], 2); ?></td>
                    <td>₹<?php echo number_format($balance, 2); ?></td>
                    <td>
                        <span class="badge <?php echo $status; ?>">
                            <?php echo $status; ?>
                        </span>
                    </td>
                </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="8">No bills have been generated yet.</td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>
        </div>

    </section>

    <!-- ANALYTICS -->

    <section class="panel" id="analytics">

        <h3>Monthly Payment Collection Analytics</h3>

        <div class="chart-container">
            <canvas id="collectionChart"></canvas>
        </div>

    </section>

    <!-- PAYMENT HISTORY -->

    <section class="panel" id="payment-history">

        <h3>Recent Payment History</h3>

        <div class="table-wrap">
        <table>

            <thead>
                <tr>
                    <th>Payment ID</th>
                    <th>Bill ID</th>
                    <th>Patient</th>
                    <th>Payment Date</th>
                    <th>Method</th>
                    <th>Amount Paid</th>
                    <th>Payment Status</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($payments) > 0): ?>

                <?php foreach ($payments as $p): ?>

                <tr>
                    <td><?php echo e($p['payment_id']); ?></td>
                    <td><?php echo e($p['bill_id']); ?></td>
                    <td><?php echo e($p['patient_name']); ?></td>
                    <td><?php echo e($p['payment_date']); ?></td>
                    <td><?php echo e($p['payment_method']); ?></td>
                    <td>₹<?php echo number_format((float)$p['amount_paid'], 2); ?></td>
                    <td>
                        <span class="badge <?php echo e($p['payment_status']); ?>">
                            <?php echo e($p['payment_status']); ?>
                        </span>
                    </td>
                </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="7">No payment records found.</td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>
        </div>

    </section>

    <div class="footer">
        MediPulse Hospital Management System | Billing Staff Dashboard
    </div>

</main>

<script>
const monthlyLabels = <?php echo json_encode($monthlyLabels); ?>;
const monthlyValues = <?php echo json_encode($monthlyValues); ?>;

new Chart(document.getElementById('collectionChart'), {
    type: 'bar',

    data: {
        labels: monthlyLabels,

        datasets: [{
            label: 'Payment Collection (₹)',
            data: monthlyValues,
            backgroundColor: '#168f91',
            borderRadius: 6
        }]
    },

    options: {
        responsive: true,
        maintainAspectRatio: false,

        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>

</body>
</html>

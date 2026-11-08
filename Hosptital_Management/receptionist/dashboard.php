
<?php
session_start();
require_once "../config.php";

// Login protection
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login.php");
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "success";

// Generate unique IDs for existing VARCHAR primary keys
function generateUniqueId($pdo, $table, $column, $prefix) {
    $allowed = [
        'patients' => ['patient_id'],
        'appointments' => ['appointment_id']
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception("Invalid request. Please refresh the page.");
        }

        $action = $_POST['action'] ?? '';

        // 1. Register a new patient
        if ($action === 'register_patient') {
            $name = trim($_POST['patient_name'] ?? '');
            $age = $_POST['age'] ?? '';
            $gender = $_POST['gender'] ?? '';
            $city = trim($_POST['city'] ?? '');

            if ($name === '') {
                throw new Exception("Patient name is required.");
            }

            if ($age !== '' &&
                (!ctype_digit((string)$age) || (int)$age < 0 || (int)$age > 120)) {
                throw new Exception("Please enter a valid age.");
            }

            if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
                throw new Exception("Please select a valid gender.");
            }

            $patientId = generateUniqueId(
                $pdo, 'patients', 'patient_id', 'P'
            );

            $stmt = $pdo->prepare("
                INSERT INTO patients
                (patient_id, patient_name, age, gender, city, registration_date)
                VALUES (?, ?, ?, ?, ?, CURDATE())
            ");

            $stmt->execute([
                $patientId,
                $name,
                $age === '' ? null : (int)$age,
                $gender,
                $city === '' ? null : $city
            ]);

            $message = "Patient registered successfully. Patient ID: " . $patientId;
        }

        // 2. Book a new appointment
        elseif ($action === 'book_appointment') {
            $patientId = trim($_POST['patient_id'] ?? '');
            $doctorId = trim($_POST['doctor_id'] ?? '');
            $appointmentDate = $_POST['appointment_date'] ?? '';

            if ($patientId === '' || $doctorId === '' || $appointmentDate === '') {
                throw new Exception("Please complete all appointment fields.");
            }

            $date = DateTime::createFromFormat('Y-m-d', $appointmentDate);

            if (!$date || $date->format('Y-m-d') !== $appointmentDate) {
                throw new Exception("Please enter a valid appointment date.");
            }

            // Verify patient exists
            $stmt = $pdo->prepare(
                "SELECT patient_id FROM patients WHERE patient_id = ?"
            );
            $stmt->execute([$patientId]);

            if (!$stmt->fetch()) {
                throw new Exception("Selected patient does not exist.");
            }

            // Verify doctor exists
            $stmt = $pdo->prepare(
                "SELECT doctor_id FROM doctors WHERE doctor_id = ?"
            );
            $stmt->execute([$doctorId]);

            if (!$stmt->fetch()) {
                throw new Exception("Selected doctor does not exist.");
            }

            $appointmentId = generateUniqueId(
                $pdo, 'appointments', 'appointment_id', 'A'
            );

            $stmt = $pdo->prepare("
                INSERT INTO appointments
                (appointment_id, patient_id, doctor_id,
                 appointment_date, status, waiting_time_minutes)
                VALUES (?, ?, ?, ?, 'Pending', 0)
            ");

            $stmt->execute([
                $appointmentId,
                $patientId,
                $doctorId,
                $appointmentDate
            ]);

            $message = "Appointment booked successfully. Appointment ID: " . $appointmentId;
        }

        // 3. Update appointment status
        elseif ($action === 'update_status') {
            $appointmentId = trim($_POST['appointment_id'] ?? '');
            $status = $_POST['status'] ?? '';

            $allowedStatuses = [
                'Pending',
                'Confirmed',
                'Completed',
                'Cancelled'
            ];

            if (!in_array($status, $allowedStatuses, true)) {
                throw new Exception("Invalid appointment status.");
            }

            $stmt = $pdo->prepare("
                UPDATE appointments
                SET status = ?
                WHERE appointment_id = ?
            ");

            $stmt->execute([$status, $appointmentId]);

            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare(
                    "SELECT appointment_id FROM appointments WHERE appointment_id = ?"
                );
                $check->execute([$appointmentId]);

                if (!$check->fetch()) {
                    throw new Exception("Appointment not found.");
                }
            }

            $message = "Appointment status updated successfully.";
        }

        else {
            throw new Exception("Invalid action.");
        }

    } catch (PDOException $e) {
        $message = "Database error: " . $e->getMessage();
        $messageType = "error";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = "error";
    }
}

// Escape output
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Dashboard statistics
$totalPatients = (int)$pdo->query(
    "SELECT COUNT(*) FROM patients"
)->fetchColumn();

$totalDoctors = (int)$pdo->query(
    "SELECT COUNT(*) FROM doctors"
)->fetchColumn();

$totalAppointments = (int)$pdo->query(
    "SELECT COUNT(*) FROM appointments"
)->fetchColumn();

$todayPatients = (int)$pdo->query(
    "SELECT COUNT(*) FROM patients WHERE registration_date = CURDATE()"
)->fetchColumn();

$todayAppointments = (int)$pdo->query(
    "SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()"
)->fetchColumn();

$pendingAppointments = (int)$pdo->query(
    "SELECT COUNT(*) FROM appointments WHERE status = 'Pending'"
)->fetchColumn();

$completedAppointments = (int)$pdo->query(
    "SELECT COUNT(*) FROM appointments WHERE status = 'Completed'"
)->fetchColumn();

$cancelledAppointments = (int)$pdo->query(
    "SELECT COUNT(*) FROM appointments WHERE status = 'Cancelled'"
)->fetchColumn();

// Doctor dropdown
$doctors = $pdo->query("
    SELECT d.doctor_id, d.doctor_name,
           d.specialization, dep.department_name
    FROM doctors d
    LEFT JOIN departments dep
        ON d.department_id = dep.department_id
    ORDER BY d.doctor_name
")->fetchAll(PDO::FETCH_ASSOC);

// Patient dropdown
$patients = $pdo->query("
    SELECT patient_id, patient_name, age
    FROM patients
    ORDER BY patient_name
")->fetchAll(PDO::FETCH_ASSOC);

// Today's appointment schedule
$todaySchedule = $pdo->query("
    SELECT a.appointment_id,
           a.appointment_date,
           a.status,
           a.waiting_time_minutes,
           p.patient_id,
           p.patient_name,
           d.doctor_name,
           d.specialization
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.patient_id
    INNER JOIN doctors d ON a.doctor_id = d.doctor_id
    WHERE a.appointment_date = CURDATE()
    ORDER BY a.appointment_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Recent appointments
$recentAppointments = $pdo->query("
    SELECT a.appointment_id,
           a.appointment_date,
           a.status,
           p.patient_name,
           d.doctor_name
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.patient_id
    INNER JOIN doctors d ON a.doctor_id = d.doctor_id
    ORDER BY a.appointment_date DESC, a.appointment_id DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Patient search
$search = trim($_GET['search'] ?? '');
$searchResults = [];

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT patient_id, patient_name, age, gender, city, registration_date
        FROM patients
        WHERE patient_name LIKE ?
           OR patient_id LIKE ?
           OR city LIKE ?
        ORDER BY patient_name
        LIMIT 30
    ");

    $term = "%$search%";
    $stmt->execute([$term, $term, $term]);
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Appointment status summary
$statusSummary = $pdo->query("
    SELECT COALESCE(status, 'Not Set') AS status_name,
           COUNT(*) AS total
    FROM appointments
    GROUP BY status
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Daily registration data for the last 7 days
$dailyRegistrations = $pdo->query("
    SELECT registration_date, COUNT(*) AS total
    FROM patients
    WHERE registration_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
      AND registration_date <= CURDATE()
    GROUP BY registration_date
    ORDER BY registration_date
")->fetchAll(PDO::FETCH_ASSOC);

$registrationLabels = [];
$registrationValues = [];

foreach ($dailyRegistrations as $item) {
    $registrationLabels[] = date(
        'd M', strtotime($item['registration_date'])
    );
    $registrationValues[] = (int)$item['total'];
}

$userName = $_SESSION['full_name'] ?? 'Receptionist';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediPulse | Receptionist Dashboard</title>

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
    margin-bottom: 25px;
    gap: 15px;
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
    grid-template-columns: repeat(4, 1fr);
    gap: 17px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 2px 10px #e4e9f1;
    border-left: 4px solid #16a6a1;
}

.stat-card p {
    color: #7c8798;
    font-size: 13px;
}

.stat-card h2 {
    font-size: 28px;
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

.Pending { background: #fff1cf; color: #956000; }
.Confirmed { background: #dcecff; color: #175b9c; }
.Completed { background: #d8f5e3; color: #16733d; }
.Cancelled { background: #ffe0e0; color: #a52b2b; }

.status-form {
    display: flex;
    gap: 6px;
    align-items: center;
    min-width: 190px;
}

.status-form select {
    min-width: 110px;
    padding: 7px;
}

.status-form button {
    margin: 0;
    padding: 8px;
}

.search-form {
    display: flex;
    gap: 10px;
    margin-bottom: 17px;
}

.search-form button {
    margin: 0;
    white-space: nowrap;
}

.chart-container {
    height: 260px;
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

    <h4>Receptionist</h4>
    <a href="dashboard.php" class="active">▣ Dashboard</a>
    <a href="#register">＋ Register Patient</a>
    <a href="#booking">▦ Book Appointment</a>
    <a href="#patients">⌕ Search Patients</a>
    <a href="#schedule">◷ Today's Schedule</a>
    <a href="#recent">▤ Recent Appointments</a>

    <h4>Account</h4>
    <a href="../login/logout.php">↪ Logout</a>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h1>Receptionist Dashboard</h1>
            <p class="welcome">Manage patient registration and appointments.</p>
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

    <!-- Dashboard Statistics -->
    <div class="stats">

        <div class="stat-card">
            <p>Total Patients</p>
            <h2><?php echo $totalPatients; ?></h2>
            <small>Registered patients</small>
        </div>

        <div class="stat-card">
            <p>Today's Registrations</p>
            <h2><?php echo $todayPatients; ?></h2>
            <small>New patients today</small>
        </div>

        <div class="stat-card">
            <p>Total Doctors</p>
            <h2><?php echo $totalDoctors; ?></h2>
            <small>Available doctor records</small>
        </div>

        <div class="stat-card">
            <p>Total Appointments</p>
            <h2><?php echo $totalAppointments; ?></h2>
            <small>All appointment records</small>
        </div>

        <div class="stat-card">
            <p>Today's Appointments</p>
            <h2><?php echo $todayAppointments; ?></h2>
            <small>Scheduled today</small>
        </div>

        <div class="stat-card">
            <p>Pending Appointments</p>
            <h2><?php echo $pendingAppointments; ?></h2>
            <small>Awaiting action</small>
        </div>

        <div class="stat-card">
            <p>Completed Appointments</p>
            <h2><?php echo $completedAppointments; ?></h2>
            <small>Completed visits</small>
        </div>

        <div class="stat-card">
            <p>Cancelled Appointments</p>
            <h2><?php echo $cancelledAppointments; ?></h2>
            <small>Cancelled records</small>
        </div>

    </div>

    <!-- Patient Registration and Appointment Booking -->
    <div class="section-grid">

        <section class="panel" id="register">
            <h3>＋ Register New Patient</h3>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                    value="<?php echo e($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="register_patient">

                <label>Patient Name *</label>
                <input type="text" name="patient_name"
                    placeholder="Enter patient name" maxlength="150" required>

                <label>Age</label>
                <input type="number" name="age" min="0" max="120"
                    placeholder="Enter age">

                <label>Gender *</label>
                <select name="gender" required>
                    <option value="">Select Gender</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                    <option value="Other">Other</option>
                </select>

                <label>City</label>
                <input type="text" name="city"
                    placeholder="Enter city" maxlength="100">

                <button type="submit">Register Patient</button>
            </form>
        </section>

        <section class="panel" id="booking">
            <h3>＋ Book New Appointment</h3>

            <?php if (count($patients) > 0 && count($doctors) > 0): ?>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                    value="<?php echo e($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="book_appointment">

                <label>Select Patient *</label>
                <select name="patient_id" required>
                    <option value="">Select Patient</option>
                    <?php foreach ($patients as $p): ?>
                        <option value="<?php echo e($p['patient_id']); ?>">
                            <?php echo e($p['patient_name']); ?>
                            (<?php echo e($p['patient_id']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Select Doctor *</label>
                <select name="doctor_id" required>
                    <option value="">Select Doctor</option>
                    <?php foreach ($doctors as $d): ?>
                        <option value="<?php echo e($d['doctor_id']); ?>">
                            <?php
                            echo e($d['doctor_name']);
                            if (!empty($d['specialization'])) {
                                echo " - " . e($d['specialization']);
                            }
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Appointment Date *</label>
                <input type="date" name="appointment_date"
                    min="<?php echo date('Y-m-d'); ?>" required>

                <button type="submit">Book Appointment</button>
            </form>

            <?php else: ?>
                <p>Please add at least one patient and one doctor before booking.</p>
            <?php endif; ?>
        </section>

    </div>

    <!-- Patient Search -->
    <section class="panel" id="patients">
        <h3>⌕ Search Patient Records</h3>

        <form method="GET" class="search-form">
            <input type="text" name="search"
                placeholder="Search by patient name, ID or city..."
                value="<?php echo e($search); ?>">
            <button type="submit">Search</button>
        </form>

        <?php if ($search !== ''): ?>
            <p style="margin-bottom:12px;">
                Search results: <?php echo count($searchResults); ?>
            </p>

            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Patient ID</th>
                        <th>Name</th>
                        <th>Age</th>
                        <th>Gender</th>
                        <th>City</th>
                        <th>Registration Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($searchResults) > 0): ?>
                    <?php foreach ($searchResults as $p): ?>
                    <tr>
                        <td><?php echo e($p['patient_id']); ?></td>
                        <td><?php echo e($p['patient_name']); ?></td>
                        <td><?php echo e($p['age']); ?></td>
                        <td><?php echo e($p['gender']); ?></td>
                        <td><?php echo e($p['city']); ?></td>
                        <td><?php echo e($p['registration_date']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">No patients found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Today's Schedule -->
    <section class="panel" id="schedule">
        <h3>◷ Today's Appointment Schedule</h3>

        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Appointment ID</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Specialization</th>
                    <th>Date</th>
                    <th>Waiting (min)</th>
                    <th>Status</th>
                    <th>Update Status</th>
                </tr>
            </thead>

            <tbody>
            <?php if (count($todaySchedule) > 0): ?>
                <?php foreach ($todaySchedule as $a): ?>
                <tr>
                    <td><?php echo e($a['appointment_id']); ?></td>
                    <td>
                        <?php echo e($a['patient_name']); ?>
                        <br><small><?php echo e($a['patient_id']); ?></small>
                    </td>
                    <td><?php echo e($a['doctor_name']); ?></td>
                    <td><?php echo e($a['specialization']); ?></td>
                    <td><?php echo e($a['appointment_date']); ?></td>
                    <td><?php echo e($a['waiting_time_minutes'] ?? 0); ?></td>
                    <td>
                        <span class="badge <?php echo e($a['status']); ?>">
                            <?php echo e($a['status'] ?? 'Not Set'); ?>
                        </span>
                    </td>
                    <td>
                        <form method="POST" class="status-form">
                            <input type="hidden" name="csrf_token"
                                value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="appointment_id"
                                value="<?php echo e($a['appointment_id']); ?>">

                            <select name="status">
                                <option value="Pending">Pending</option>
                                <option value="Confirmed">Confirmed</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>

                            <button type="submit">Save</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">No appointments scheduled for today.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>

    <!-- Analytics -->
    <div class="section-grid">

        <section class="panel">
            <h3>Patient Registration Trend (Last 7 Days)</h3>
            <div class="chart-container">
                <canvas id="registrationChart"></canvas>
            </div>
        </section>

        <section class="panel">
            <h3>Appointment Status Distribution</h3>
            <div class="chart-container">
                <canvas id="statusChart"></canvas>
            </div>
        </section>

    </div>

    <!-- Recent Appointments -->
    <section class="panel" id="recent">
        <h3>Recent Appointments</h3>

        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Appointment ID</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Appointment Date</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
            <?php if (count($recentAppointments) > 0): ?>
                <?php foreach ($recentAppointments as $a): ?>
                <tr>
                    <td><?php echo e($a['appointment_id']); ?></td>
                    <td><?php echo e($a['patient_name']); ?></td>
                    <td><?php echo e($a['doctor_name']); ?></td>
                    <td><?php echo e($a['appointment_date']); ?></td>
                    <td>
                        <span class="badge <?php echo e($a['status']); ?>">
                            <?php echo e($a['status'] ?? 'Not Set'); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5">No appointment records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>

    <div class="footer">
        MediPulse Hospital Management System | Receptionist Dashboard
    </div>

</main>

<script>
const registrationLabels = <?php echo json_encode($registrationLabels); ?>;
const registrationValues = <?php echo json_encode($registrationValues); ?>;

new Chart(document.getElementById('registrationChart'), {
    type: 'line',
    data: {
        labels: registrationLabels,
        datasets: [{
            label: 'New Patients',
            data: registrationValues,
            borderColor: '#168f91',
            backgroundColor: 'rgba(22,143,145,0.12)',
            fill: true,
            tension: 0.35
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});

const statusLabels = <?php echo json_encode(
    array_column($statusSummary, 'status_name')
); ?>;

const statusValues = <?php echo json_encode(
    array_map('intval', array_column($statusSummary, 'total'))
); ?>;

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusValues,
            backgroundColor: [
                '#f4bd50',
                '#4597d6',
                '#36b879',
                '#e46b6b',
                '#9b8bd5'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false
    }
});
</script>

</body>
</html>

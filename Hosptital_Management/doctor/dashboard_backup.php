
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require_once "../config.php";

// =====================================
// 1. LOGIN CHECK
// =====================================

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login.php");
    exit;
}

// =====================================
// 2. ROLE CHECK
// =====================================

$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($role, ['admin', 'doctor'], true)) {
    http_response_code(403);
    exit("Access Denied.");
}

$isAdmin = ($role === 'admin');
$userId = $_SESSION['user_id'];

try {

    // =====================================
    // 3. GET USER DETAILS
    // =====================================

    $stmt = $pdo->prepare("
        SELECT user_id, full_name, email, doctor_id
        FROM users
        WHERE user_id = ?
    ");

    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        exit("User account not found.");
    }

    // =====================================
    // 4. DOCTOR PROFILE
    // =====================================

    if ($isAdmin) {

        $doctor = [
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'specialization' => 'All Specializations',
            'department_name' => 'All Departments',
            'experience_years' => 'N/A'
        ];

        $doctorId = null;

    } else {

        $stmt = $pdo->prepare("
            SELECT
                u.full_name,
                u.email,
                u.doctor_id,
                d.specialization,
                d.experience_years,
                dep.department_name
            FROM users u
            LEFT JOIN doctors d
                ON u.doctor_id = d.doctor_id
            LEFT JOIN departments dep
                ON d.department_id = dep.department_id
            WHERE u.user_id = ?
        ");

        $stmt->execute([$userId]);
        $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doctor || empty($doctor['doctor_id'])) {
            exit("Doctor profile is not linked. Please contact Admin.");
        }

        $doctorId = $doctor['doctor_id'];
    }

    // =====================================
    // 5. CONDITIONS
    // =====================================

    $filter = $isAdmin ? "" : " WHERE doctor_id = ?";
    $params = $isAdmin ? [] : [$doctorId];

    // =====================================
    // 6. TOTAL PATIENTS
    // =====================================

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT patient_id)
        FROM appointments
        $filter
    ");

    $stmt->execute($params);
    $totalPatients = $stmt->fetchColumn();

    // =====================================
    // 7. TODAY'S APPOINTMENTS
    // =====================================

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointments
        $filter
        " . ($isAdmin ? "WHERE" : "AND") . "
        DATE(appointment_date) = CURDATE()
    ");

    $stmt->execute($params);
    $todayAppointments = $stmt->fetchColumn();

    // =====================================
    // 8. COMPLETED APPOINTMENTS
    // =====================================

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointments
        $filter
        " . ($isAdmin ? "WHERE" : "AND") . "
        LOWER(status) = 'completed'
    ");

    $stmt->execute($params);
    $completedAppointments = $stmt->fetchColumn();

    // =====================================
    // 9. PENDING APPOINTMENTS
    // =====================================

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointments
        $filter
        " . ($isAdmin ? "WHERE" : "AND") . "
        LOWER(status) = 'pending'
    ");

    $stmt->execute($params);
    $pendingAppointments = $stmt->fetchColumn();

    // =====================================
    // 10. RECENT APPOINTMENTS
    // =====================================

    $stmt = $pdo->prepare("
        SELECT
            a.appointment_id,
            a.appointment_date,
            a.status,
            a.waiting_time_minutes,
            p.patient_name,
            p.age,
            p.gender
        FROM appointments a
        INNER JOIN patients p
            ON a.patient_id = p.patient_id
        " . ($isAdmin ? "" : "WHERE a.doctor_id = ?") . "
        ORDER BY a.appointment_date DESC
        LIMIT 10
    ");

    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =====================================
    // 11. APPOINTMENT STATUS CHART
    // =====================================

    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) AS total
        FROM appointments
        $filter
        GROUP BY status
    ");

    $stmt->execute($params);
    $statusRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $chartLabels = [];
    $chartValues = [];

    foreach ($statusRows as $row) {
        $chartLabels[] = $row['status'];
        $chartValues[] = (int)$row['total'];
    }

} catch (PDOException $e) {

    error_log($e->getMessage());

    exit(
        "Dashboard database error: " .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
    );
}

// =====================================
// 12. SAFE OUTPUT FUNCTION
// =====================================

function e($value) {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Doctor Dashboard | MediPulse</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f3f7fb;
    color: #243447;
}

.sidebar {
    width: 240px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: #10283e;
    padding: 25px 15px;
    color: white;
}

.brand {
    font-size: 26px;
    font-weight: bold;
    color: #23d5e6;
    margin: 0 12px 35px;
}

.sidebar a {
    display: block;
    padding: 13px;
    margin-bottom: 8px;
    color: #dce8f2;
    text-decoration: none;
    border-radius: 8px;
}

.sidebar a:hover,
.sidebar a.active {
    background: #20445d;
    color: #23d5e6;
}

.main {
    margin-left: 240px;
    padding: 30px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    gap: 15px;
}

.header h1 {
    font-size: 28px;
}

.subtitle {
    color: #718096;
    margin-top: 7px;
}

.logout {
    background: #e74c3c;
    color: white;
    text-decoration: none;
    padding: 11px 18px;
    border-radius: 7px;
}

.profile {
    background: white;
    padding: 22px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 3px 12px #0000000a;
}

.profile h2 {
    margin-bottom: 12px;
    color: #173b54;
}

.profile p {
    color: #64748b;
    line-height: 1.8;
}

.cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 25px;
}

.card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    border-left: 4px solid #23b9d0;
    box-shadow: 0 3px 12px #0000000a;
}

.card p {
    color: #718096;
    margin-bottom: 12px;
}

.card h2 {
    font-size: 30px;
    color: #173b54;
}

.section {
    background: white;
    padding: 22px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 3px 12px #0000000a;
}

.section h2 {
    margin-bottom: 18px;
    color: #173b54;
}

.chart-container {
    max-width: 500px;
    height: 300px;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th, td {
    padding: 13px;
    text-align: left;
    border-bottom: 1px solid #edf1f5;
}

th {
    background: #f3f7fb;
    color: #40566c;
}

.status {
    display: inline-block;
    padding: 6px 11px;
    background: #e8f4f8;
    border-radius: 15px;
    font-size: 12px;
}

@media(max-width: 1000px) {
    .cards {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 650px) {
    .sidebar {
        width: 100%;
        height: auto;
        position: relative;
    }

    .main {
        margin-left: 0;
        padding: 15px;
    }

    .cards {
        grid-template-columns: 1fr;
    }
}

</style>

</head>

<body>

<!-- SIDEBAR -->

<aside class="sidebar">

    <div class="brand">MediPulse</div>

    <a class="active" href="dashboard.php">
        🏠 Dashboard
    </a>

    <a href="#appointments">
        📅 My Appointments
    </a>

    <a href="#patients">
        👥 My Patients
    </a>

    <a href="#analytics">
        📊 Analytics
    </a>

    <?php if ($isAdmin): ?>
        <a href="../admin/dashboard.php">
            ← Admin Dashboard
        </a>
    <?php endif; ?>

    <a href="../login/logout.php">
        🚪 Logout
    </a>

</aside>

<!-- MAIN CONTENT -->

<main class="main">

    <div class="header">

        <div>

            <h1>
                Doctor Dashboard
            </h1>

            <p class="subtitle">
                Hospital Analytics Dashboard
            </p>

        </div>

        <a class="logout" href="../login/logout.php">
            Logout
        </a>

    </div>

    <!-- PROFILE -->

    <section class="profile">

        <h2>
            Welcome,
            <?= $isAdmin ? 'Admin' : 'Dr.' ?>
            <?= e($doctor['full_name']) ?> 👋
        </h2>

        <p>

            <strong>Email:</strong>
            <?= e($doctor['email']) ?><br>

            <strong>Specialization:</strong>
            <?= e($doctor['specialization'] ?? 'Not specified') ?><br>

            <strong>Department:</strong>
            <?= e($doctor['department_name'] ?? 'Not assigned') ?><br>

            <strong>Experience:</strong>
            <?= e($doctor['experience_years'] ?? 'Not specified') ?>
            years

        </p>

        <?php if ($isAdmin): ?>

            <p>
                <strong>Access:</strong>
                All Doctors and Hospital Appointments
            </p>

        <?php endif; ?>

    </section>

    <!-- STATISTICS -->

    <section class="cards">

        <div class="card">

            <p>Total Patients</p>

            <h2>
                <?= number_format((int)$totalPatients) ?>
            </h2>

        </div>

        <div class="card">

            <p>Today's Appointments</p>

            <h2>
                <?= number_format((int)$todayAppointments) ?>
            </h2>

        </div>

        <div class="card">

            <p>Completed Appointments</p>

            <h2>
                <?= number_format((int)$completedAppointments) ?>
            </h2>

        </div>

        <div class="card">

            <p>Pending Appointments</p>

            <h2>
                <?= number_format((int)$pendingAppointments) ?>
            </h2>

        </div>

    </section>

    <!-- ANALYTICS -->

    <section class="section" id="analytics">

        <h2>Appointment Analytics</h2>

        <div class="chart-container">

            <canvas id="appointmentChart"></canvas>

        </div>

    </section>

    <!-- APPOINTMENTS -->

    <section class="section" id="appointments">

        <h2>
            <?= $isAdmin ? 'Recent Hospital Appointments' : 'Recent Appointments' ?>
        </h2>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>ID</th>
                        <th>Patient</th>
                        <th>Age</th>
                        <th>Gender</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Waiting Time</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (count($appointments) > 0): ?>

                    <?php foreach ($appointments as $appointment): ?>

                        <tr>

                            <td>
                                <?= e($appointment['appointment_id']) ?>
                            </td>

                            <td>
                                <?= e($appointment['patient_name']) ?>
                            </td>

                            <td>
                                <?= e($appointment['age']) ?>
                            </td>

                            <td>
                                <?= e($appointment['gender']) ?>
                            </td>

                            <td>
                                <?= e($appointment['appointment_date']) ?>
                            </td>

                            <td>

                                <span class="status">
                                    <?= e($appointment['status']) ?>
                                </span>

                            </td>

                            <td>
                                <?= e($appointment['waiting_time_minutes']) ?>
                                min
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="7">
                            No appointments found.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- PATIENT SUMMARY -->

    <section class="section" id="patients">

        <h2>My Patients</h2>

        <p>

            Total unique patients:

            <strong>
                <?= number_format((int)$totalPatients) ?>
            </strong>

        </p>

    </section>

</main>

<!-- CHART -->

<script>

const labels = <?= json_encode($chartLabels) ?>;
const values = <?= json_encode($chartValues) ?>;

new Chart(
    document.getElementById('appointmentChart'),
    {
        type: 'doughnut',

        data: {

            labels: labels,

            datasets: [{

                label: 'Appointments',

                data: values,

                backgroundColor: [
                    '#23b9d0',
                    '#5479d1',
                    '#36b37e',
                    '#f5a623',
                    '#e76f51',
                    '#9b72cf'
                ],

                borderWidth: 1

            }]

        },

        options: {

            responsive: true,
            maintainAspectRatio: false,

            plugins: {

                legend: {
                    position: 'bottom'
                }

            }

        }

    }
);

</script>

</body>
</html>

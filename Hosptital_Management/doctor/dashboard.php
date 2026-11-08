
<?php
session_start();
require_once "../config.php";

// Login protection only. No doctor/admin role restriction.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login.php");
    exit;
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function countData($pdo, $sql) {
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (PDOException $ex) {
        return 0;
    }
}

function getRows($pdo, $sql) {
    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        return [];
    }
}

// HOSPITAL OVERVIEW
$totalPatients = countData($pdo, "SELECT COUNT(*) FROM patients");
$totalDoctors = countData($pdo, "SELECT COUNT(*) FROM doctors");
$totalDepartments = countData($pdo, "SELECT COUNT(*) FROM departments");
$totalAppointments = countData($pdo, "SELECT COUNT(*) FROM appointments");

// APPOINTMENT KPIs
$todayAppointments = countData($pdo,
    "SELECT COUNT(*) FROM appointments
     WHERE DATE(appointment_date) = CURDATE()"
);

$pendingAppointments = countData($pdo,
    "SELECT COUNT(*) FROM appointments
     WHERE LOWER(status) = 'pending'"
);

$completedAppointments = countData($pdo,
    "SELECT COUNT(*) FROM appointments
     WHERE LOWER(status) = 'completed'"
);

$cancelledAppointments = countData($pdo,
    "SELECT COUNT(*) FROM appointments
     WHERE LOWER(status) = 'cancelled'"
);

// MONTHLY APPOINTMENT TREND
$monthlyData = getRows($pdo, "
    SELECT DATE_FORMAT(appointment_date, '%b %Y') AS month_name,
           COUNT(*) AS total
    FROM appointments
    WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY YEAR(appointment_date), MONTH(appointment_date)
    ORDER BY YEAR(appointment_date), MONTH(appointment_date)
");

$monthlyLabels = [];
$monthlyValues = [];

foreach ($monthlyData as $row) {
    $monthlyLabels[] = $row['month_name'];
    $monthlyValues[] = (int)$row['total'];
}

// APPOINTMENT STATUS
$statusData = getRows($pdo, "
    SELECT status, COUNT(*) AS total
    FROM appointments
    GROUP BY status
");

$statusLabels = [];
$statusValues = [];

foreach ($statusData as $row) {
    $statusLabels[] = ucfirst($row['status']);
    $statusValues[] = (int)$row['total'];
}

// TOP VISITING PATIENTS
$topPatients = getRows($pdo, "
    SELECT p.patient_id,
           p.patient_name,
           COUNT(a.appointment_id) AS total_visits
    FROM patients p
    INNER JOIN appointments a
        ON p.patient_id = a.patient_id
    GROUP BY p.patient_id, p.patient_name
    ORDER BY total_visits DESC
    LIMIT 10
");

// DEPARTMENT ANALYTICS
$departmentData = getRows($pdo, "
    SELECT d.department_name,
           COUNT(doc.doctor_id) AS total_doctors
    FROM departments d
    LEFT JOIN doctors doc
        ON d.department_id = doc.department_id
    GROUP BY d.department_id, d.department_name
    ORDER BY total_doctors DESC
");

$departmentLabels = [];
$departmentValues = [];

foreach ($departmentData as $row) {
    $departmentLabels[] = $row['department_name'];
    $departmentValues[] = (int)$row['total_doctors'];
}

// DOCTOR WORKLOAD
$doctorData = getRows($pdo, "
    SELECT u.full_name,
           COUNT(a.appointment_id) AS total_appointments
    FROM doctors d
    INNER JOIN users u
        ON d.doctor_id = u.doctor_id
    LEFT JOIN appointments a
        ON d.doctor_id = a.doctor_id
    GROUP BY d.doctor_id, u.full_name
    ORDER BY total_appointments DESC
    LIMIT 10
");

$doctorNames = [];
$doctorVisits = [];

foreach ($doctorData as $row) {
    $doctorNames[] = $row['full_name'];
    $doctorVisits[] = (int)$row['total_appointments'];
}

// COMMON DISEASES
$diseaseLabels = [];
$diseaseValues = [];

try {
    $patientColumns = $pdo->query("DESCRIBE patients")
                          ->fetchAll(PDO::FETCH_COLUMN);

    $possibleDiseaseColumns = [
        'disease',
        'diagnosis',
        'medical_condition',
        'condition_name'
    ];

    $diseaseColumn = null;

    foreach ($possibleDiseaseColumns as $column) {
        if (in_array($column, $patientColumns, true)) {
            $diseaseColumn = $column;
            break;
        }
    }

    if ($diseaseColumn !== null) {
        $diseaseData = getRows($pdo, "
            SELECT `$diseaseColumn` AS disease_name,
                   COUNT(*) AS total
            FROM patients
            WHERE `$diseaseColumn` IS NOT NULL
              AND `$diseaseColumn` != ''
            GROUP BY `$diseaseColumn`
            ORDER BY total DESC
            LIMIT 8
        ");

        foreach ($diseaseData as $row) {
            $diseaseLabels[] = $row['disease_name'];
            $diseaseValues[] = (int)$row['total'];
        }
    }
} catch (PDOException $ex) {
    // Disease analytics unavailable if no matching column exists.
}

// RECENT APPOINTMENTS
$recentAppointments = getRows($pdo, "
    SELECT a.appointment_id,
           p.patient_name,
           u.full_name AS doctor_name,
           a.appointment_date,
           a.status
    FROM appointments a
    LEFT JOIN patients p
        ON a.patient_id = p.patient_id
    LEFT JOIN doctors d
        ON a.doctor_id = d.doctor_id
    LEFT JOIN users u
        ON d.doctor_id = u.doctor_id
    ORDER BY a.appointment_date DESC
    LIMIT 10
");

$displayName = $_SESSION['full_name'] ?? 'Doctor';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>MediPulse | Doctor Analytics Dashboard</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: Arial, sans-serif;
}

body {
    background: #f3f6fb;
    color: #263445;
}

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 235px;
    height: 100vh;
    background: #142d4e;
    padding: 25px 15px;
    color: white;
}

.logo {
    font-size: 25px;
    font-weight: bold;
    margin-bottom: 35px;
}

.sidebar a {
    display: block;
    padding: 14px;
    color: #dce7f5;
    text-decoration: none;
    border-radius: 8px;
    margin-bottom: 9px;
}

.sidebar a:hover,
.sidebar a.active {
    background: #28558b;
    color: white;
}

.main {
    margin-left: 235px;
    padding: 28px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 25px;
}

.header h1 {
    font-size: 26px;
}

.subtitle {
    color: #718096;
    margin-top: 8px;
}

.section-title {
    font-size: 19px;
    margin: 26px 0 15px;
}

.cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 24px;
}

.card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.card p {
    color: #718096;
    font-size: 14px;
}

.card h2 {
    color: #183b63;
    font-size: 30px;
    margin-top: 12px;
}

.chart-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.chart-card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    min-width: 0;
}

.chart-card h3 {
    font-size: 16px;
    margin-bottom: 18px;
}

.chart-box {
    height: 280px;
    position: relative;
}

.table-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    overflow-x: auto;
    margin-bottom: 20px;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 600px;
}

th, td {
    padding: 14px;
    text-align: left;
    border-bottom: 1px solid #edf0f4;
    font-size: 14px;
}

th {
    background: #f5f8fc;
    color: #43556b;
}

.status {
    padding: 5px 10px;
    border-radius: 20px;
    background: #e8f1ff;
    color: #245a9b;
    font-size: 12px;
}

.empty {
    color: #8491a1;
    margin-top: 12px;
    font-size: 13px;
}

@media(max-width: 1000px) {
    .cards {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 700px) {
    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
    }

    .main {
        margin-left: 0;
        padding: 16px;
    }

    .chart-grid {
        grid-template-columns: 1fr;
    }

    .header {
        align-items: flex-start;
        flex-direction: column;
    }
}

@media(max-width: 450px) {
    .cards {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>



<aside class="sidebar">

    <div class="logo">MediPulse</div>

    <a href="/medipulse/doctor/dashboard.php" class="active">
        Dashboard
    </a>

    <a href="/medipulse/dataset/appointments.csv" target="_blank">
         Appointments
    </a>

    <a href="/medipulse/dataset/patients.csv" target="_blank">
         Patients
    </a>

    <a href="/medipulse/login/logout.php">
         Logout
    </a>

</aside>





<main class="main">

    <div class="header">
        <div>
            <h1>Doctor Analytics Dashboard</h1>
            <p class="subtitle">
                Welcome, <?php echo e($displayName); ?> |
                Hospital Operations & Patient Analytics
            </p>
        </div>

        <p><?php echo date("d M Y"); ?></p>
    </div>

    <h2 class="section-title">Hospital Overview</h2>

    <div class="cards">

        <div class="card">
            <p>Total Patients</p>
            <h2><?php echo $totalPatients; ?></h2>
        </div>

        <div class="card">
            <p>Total Doctors</p>
            <h2><?php echo $totalDoctors; ?></h2>
        </div>

        <div class="card">
            <p>Total Departments</p>
            <h2><?php echo $totalDepartments; ?></h2>
        </div>

        <div class="card">
            <p>Total Appointments</p>
            <h2><?php echo $totalAppointments; ?></h2>
        </div>

    </div>

    <h2 class="section-title">Appointment Overview</h2>

    <div class="cards">

        <div class="card">
            <p>Today's Appointments</p>
            <h2><?php echo $todayAppointments; ?></h2>
        </div>

        <div class="card">
            <p>Pending Appointments</p>
            <h2><?php echo $pendingAppointments; ?></h2>
        </div>

        <div class="card">
            <p>Completed Appointments</p>
            <h2><?php echo $completedAppointments; ?></h2>
        </div>

        <div class="card">
            <p>Cancelled Appointments</p>
            <h2><?php echo $cancelledAppointments; ?></h2>
        </div>

    </div>

    <h2 class="section-title">Hospital Data Analytics</h2>

    <div class="chart-grid">

        <div class="chart-card">
            <h3>Monthly Appointment Trends</h3>
            <div class="chart-box">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Appointment Status Distribution</h3>
            <div class="chart-box">
                <canvas id="statusChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Doctors by Department</h3>
            <div class="chart-box">
                <canvas id="departmentChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Doctor Workload - Top 10</h3>
            <div class="chart-box">
                <canvas id="workloadChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Most Common Diseases</h3>
            <div class="chart-box">
                <canvas id="diseaseChart"></canvas>
            </div>

            <?php if (empty($diseaseLabels)): ?>
                <p class="empty">
                    No disease data available. This chart requires a
                    disease or diagnosis column in the patients table.
                </p>
            <?php endif; ?>
        </div>

    </div>

    <h2 class="section-title">Most Frequently Visiting Patients</h2>

    <div class="table-card">

        <table>
            <thead>
                <tr>
                    <th>Patient ID</th>
                    <th>Patient Name</th>
                    <th>Total Visits</th>
                </tr>
            </thead>

            <tbody>

            <?php if (empty($topPatients)): ?>

                <tr>
                    <td colspan="3">No patient visit records available.</td>
                </tr>

            <?php else: ?>

                <?php foreach ($topPatients as $patient): ?>
                    <tr>
                        <td><?php echo e($patient['patient_id']); ?></td>
                        <td><?php echo e($patient['patient_name']); ?></td>
                        <td><?php echo e($patient['total_visits']); ?></td>
                    </tr>
                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>
        </table>

    </div>

    <h2 class="section-title">Recent Hospital Appointments</h2>

    <div class="table-card">

        <table>
            <thead>
                <tr>
                    <th>Appointment ID</th>
                    <th>Patient Name</th>
                    <th>Doctor Name</th>
                    <th>Appointment Date</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

            <?php if (empty($recentAppointments)): ?>

                <tr>
                    <td colspan="5">No appointments available.</td>
                </tr>

            <?php else: ?>

                <?php foreach ($recentAppointments as $appointment): ?>

                    <tr>
                        <td><?php echo e($appointment['appointment_id']); ?></td>
                        <td><?php echo e($appointment['patient_name']); ?></td>
                        <td><?php echo e($appointment['doctor_name']); ?></td>
                        <td><?php echo e($appointment['appointment_date']); ?></td>
                        <td>
                            <span class="status">
                                <?php echo e($appointment['status']); ?>
                            </span>
                        </td>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>
        </table>

    </div>

</main>

<script>
const monthlyLabels = <?php echo json_encode($monthlyLabels); ?>;
const monthlyValues = <?php echo json_encode($monthlyValues); ?>;

const statusLabels = <?php echo json_encode($statusLabels); ?>;
const statusValues = <?php echo json_encode($statusValues); ?>;

const departmentLabels = <?php echo json_encode($departmentLabels); ?>;
const departmentValues = <?php echo json_encode($departmentValues); ?>;

const doctorNames = <?php echo json_encode($doctorNames); ?>;
const doctorVisits = <?php echo json_encode($doctorVisits); ?>;

const diseaseLabels = <?php echo json_encode($diseaseLabels); ?>;
const diseaseValues = <?php echo json_encode($diseaseValues); ?>;

new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
        labels: monthlyLabels,
        datasets: [{
            label: 'Appointments',
            data: monthlyValues,
            borderColor: '#2878c7',
            backgroundColor: 'rgba(40,120,199,.12)',
            fill: true,
            tension: 0.35
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false
    }
});

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusValues
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false
    }
});

new Chart(document.getElementById('departmentChart'), {
    type: 'bar',
    data: {
        labels: departmentLabels,
        datasets: [{
            label: 'Number of Doctors',
            data: departmentValues,
            backgroundColor: '#4c9b87'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false
    }
});

new Chart(document.getElementById('workloadChart'), {
    type: 'bar',
    data: {
        labels: doctorNames,
        datasets: [{
            label: 'Appointments',
            data: doctorVisits,
            backgroundColor: '#7995c7'
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false
    }
});

new Chart(document.getElementById('diseaseChart'), {
    type: 'bar',
    data: {
        labels: diseaseLabels,
        datasets: [{
            label: 'Patient Records',
            data: diseaseValues,
            backgroundColor: '#d59a63'
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

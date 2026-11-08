
<?php
session_start();
require_once __DIR__ . '/../config.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header("Location: ../login/login.php");
    exit;
}

function getCount($pdo, $sql) {
    return (int)$pdo->query($sql)->fetchColumn();
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// MAIN STATISTICS
$patients = getCount($pdo, "SELECT COUNT(*) FROM patients");
$doctors = getCount($pdo, "SELECT COUNT(*) FROM doctors");
$departments = getCount($pdo, "SELECT COUNT(*) FROM departments");
$appointments = getCount($pdo, "SELECT COUNT(*) FROM appointments");
$bills = getCount($pdo, "SELECT COUNT(*) FROM billing");
$staff = getCount($pdo, "SELECT COUNT(*) FROM users WHERE role != 'admin'");

$revenue = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount_paid), 0)
     FROM payments
     WHERE payment_status = 'Paid'"
)->fetchColumn();

// RECENT APPOINTMENTS
$recent = $pdo->query(
    "SELECT a.appointment_id, a.appointment_date, a.status,
            p.patient_name, d.doctor_name
     FROM appointments a
     JOIN patients p ON a.patient_id = p.patient_id
     JOIN doctors d ON a.doctor_id = d.doctor_id
     ORDER BY a.appointment_date DESC
     LIMIT 8"
)->fetchAll();

// LOGIN HISTORY
$logs = $pdo->query(
    "SELECT email_attempted, login_status, login_time
     FROM login_logs
     ORDER BY login_time DESC
     LIMIT 5"
)->fetchAll();

// STAFF ACCOUNTS
$staffList = $pdo->query(
    "SELECT full_name, email, role, is_active
     FROM users
     WHERE role != 'admin'
     ORDER BY created_at DESC
     LIMIT 10"
)->fetchAll();

// MONTHLY APPOINTMENTS
$monthlyRows = $pdo->query(
    "SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS month_name,
            COUNT(*) AS total
     FROM appointments
     WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY DATE_FORMAT(appointment_date, '%Y-%m')
     ORDER BY month_name"
)->fetchAll();

// APPOINTMENT STATUS
$statusRows = $pdo->query(
    "SELECT status, COUNT(*) AS total
     FROM appointments
     GROUP BY status"
)->fetchAll();

// DEPARTMENT-WISE DOCTORS
$departmentRows = $pdo->query(
    "SELECT dep.department_name, COUNT(d.doctor_id) AS total
     FROM departments dep
     LEFT JOIN doctors d ON dep.department_id = d.department_id
     GROUP BY dep.department_id, dep.department_name
     ORDER BY total DESC"
)->fetchAll();

// MONTHLY REVENUE
$revenueRows = $pdo->query(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month_name,
            COALESCE(SUM(amount_paid), 0) AS total
     FROM payments
     WHERE payment_status = 'Paid'
       AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
     ORDER BY month_name"
)->fetchAll();

// PATIENT AGE GROUPS
$ageRows = $pdo->query(
    "SELECT
        CASE
            WHEN age < 18 THEN 'Under 18'
            WHEN age BETWEEN 18 AND 30 THEN '18-30'
            WHEN age BETWEEN 31 AND 45 THEN '31-45'
            WHEN age BETWEEN 46 AND 60 THEN '46-60'
            ELSE 'Above 60'
        END AS age_group,
        COUNT(*) AS total
     FROM patients
     GROUP BY age_group
     ORDER BY MIN(age)"
)->fetchAll();

// CHART DATA
$monthlyLabels = array_column($monthlyRows, 'month_name');
$monthlyValues = array_map('intval', array_column($monthlyRows, 'total'));

$statusLabels = array_column($statusRows, 'status');
$statusValues = array_map('intval', array_column($statusRows, 'total'));

$departmentLabels = array_column($departmentRows, 'department_name');
$departmentValues = array_map('intval', array_column($departmentRows, 'total'));

$revenueLabels = array_column($revenueRows, 'month_name');
$revenueValues = array_map('floatval', array_column($revenueRows, 'total'));

$ageLabels = array_column($ageRows, 'age_group');
$ageValues = array_map('intval', array_column($ageRows, 'total'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>MediPulse | Admin Dashboard</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #071321;
    color: #f4f8fc;
}

.sidebar {
    position: fixed;
    width: 230px;
    height: 100vh;
    overflow-y: auto;
    background: #10243a;
    padding: 25px 18px;
}

.brand {
    color: #23d5e6;
    font-size: 25px;
    font-weight: bold;
    margin-bottom: 35px;
}

.sidebar a {
    display: block;
    padding: 13px;
    margin: 7px 0;
    border-radius: 8px;
    color: #c7d6e5;
    text-decoration: none;
    font-size: 14px;
}

.sidebar a.active,
.sidebar a:hover {
    background: #173b54;
    color: #23d5e6;
}

.sidebar-heading {
    color: #23d5e6;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 1px;
    margin: 25px 12px 10px;
    padding-top: 10px;
    border-top: 1px solid #294057;
}

.main {
    margin-left: 230px;
    padding: 30px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.muted {
    color: #9bb0c4;
}

.section-title {
    margin-top: 35px;
    margin-bottom: 16px;
}

.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
    gap: 18px;
    margin: 28px 0;
}

.card,
.panel {
    background: #10243a;
    border: 1px solid #203b53;
    border-radius: 13px;
    padding: 22px;
}

.card p {
    color: #9bb0c4;
    margin: 0 0 12px;
}

.card h2 {
    color: #23d5e6;
    margin: 0;
    font-size: 28px;
}

.panel {
    margin-top: 22px;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 500px;
}

th,
td {
    text-align: left;
    padding: 13px;
    border-bottom: 1px solid #294057;
}

th {
    color: #23d5e6;
}

.staff-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 16px;
}

.staff-card {
    display: block;
    background: #10243a;
    border: 1px solid #203b53;
    border-radius: 12px;
    padding: 20px;
    color: white;
    text-decoration: none;
    transition: 0.2s;
}

.staff-card:hover {
    border-color: #23d5e6;
    transform: translateY(-3px);
}

.staff-card h3 {
    color: #23d5e6;
    margin: 0 0 10px;
}

.staff-card p {
    color: #9bb0c4;
    font-size: 13px;
    line-height: 1.5;
}

.chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.chart-box {
    background: #10243a;
    border: 1px solid #203b53;
    border-radius: 13px;
    padding: 20px;
    min-width: 0;
    height: 330px;
}

.chart-box h3 {
    margin-top: 0;
    color: #23d5e6;
}

.chart-box canvas {
    max-height: 255px;
}

.logout {
    color: #23d5e6;
    text-decoration: none;
}

/* CHATBOT BUTTON */

.chat-toggle {
    position: fixed;
    bottom: 25px;
    right: 25px;
    width: 62px;
    height: 62px;
    border: none;
    border-radius: 50%;
    background: #23d5e6;
    color: #071321;
    font-size: 27px;
    cursor: pointer;
    z-index: 2000;
    box-shadow: 0 5px 20px #0008;
}

.chat-toggle:hover {
    transform: scale(1.06);
}

/* CHAT WINDOW */

.chat-window {
    display: none;
    position: fixed;
    bottom: 100px;
    right: 25px;
    width: 370px;
    height: 510px;
    max-height: 75vh;
    background: #10243a;
    border: 1px solid #23d5e6;
    border-radius: 15px;
    z-index: 2000;
    overflow: hidden;
    box-shadow: 0 5px 25px #0009;
    flex-direction: column;
}

.chat-window.open {
    display: flex;
}

.chat-header {
    background: #173b54;
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-title {
    color: #23d5e6;
    font-weight: bold;
    font-size: 16px;
}

.chat-subtitle {
    color: #b8cbdc;
    font-size: 11px;
    margin-top: 5px;
}

.chat-close {
    border: none;
    background: transparent;
    color: white;
    font-size: 24px;
    cursor: pointer;
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 13px;
}

.chat-message {
    padding: 11px 13px;
    margin: 9px 0;
    border-radius: 11px;
    max-width: 90%;
    font-size: 13px;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.bot-message {
    background: #203b53;
    color: white;
    margin-right: auto;
}

.user-message {
    background: #176071;
    color: white;
    margin-left: auto;
}

.chat-quick {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    padding: 10px;
    border-top: 1px solid #294057;
}

.chat-quick button {
    background: #173b54;
    color: #23d5e6;
    border: 1px solid #294057;
    padding: 7px 10px;
    border-radius: 15px;
    cursor: pointer;
    font-size: 11px;
}

.chat-quick button:hover {
    background: #20506a;
}

.chat-input-area {
    display: flex;
    padding: 11px;
    gap: 8px;
    border-top: 1px solid #294057;
}

.chat-input-area input {
    flex: 1;
    min-width: 0;
    padding: 11px;
    border-radius: 8px;
    border: 1px solid #294057;
    background: #071321;
    color: white;
    outline: none;
}

.chat-input-area input:focus {
    border-color: #23d5e6;
}

.chat-input-area button {
    border: none;
    border-radius: 8px;
    padding: 10px 14px;
    background: #23d5e6;
    color: #071321;
    font-weight: bold;
    cursor: pointer;
}

.chat-input-area button:hover {
    background: #6ce8f2;
}

.chat-note {
    padding: 5px 12px 9px;
    font-size: 10px;
    color: #9bb0c4;
    text-align: center;
}

@media(max-width:700px) {
    .sidebar {
        position: static;
        width: 100%;
        height: auto;
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }
}

@media(max-width:450px) {
    .chat-window {
        right: 10px;
        width: calc(100% - 20px);
        bottom: 90px;
    }

    .chat-toggle {
        right: 15px;
        bottom: 15px;
    }
}
</style>
</head>

<body>

<!-- SIDEBAR -->

<aside class="sidebar">

    <div class="brand">MediPulse</div>

    <a class="active" href="dashboard.php"> Dashboard</a>

    <p class="sidebar-heading">STAFF DASHBOARDS</p>
    <a href="../doctor/dashboard.php"> Doctor</a>
    <a href="../nurse/dashboard.php"> Nurse</a>
    <a href="../receptionist/dashboard.php"> Receptionist</a>
    <a href="../billing_staff/dashboard.php">Billing Staff</a>
    <a href="../pharmacist/dashboard.php"> Pharmacist</a>
    <a href="../lab_technician/dashboard.php"> Lab Technician</a>
    <a href="../data_analyst/dashboard.php"> Data Analyst</a>

    <p class="sidebar-heading">ADMIN MENU</p>
    <a href="#analytics"> Analytics Charts</a>
    <a href="#appointments">Appointments</a>
    <a href="#staff-accounts">Staff Accounts</a>
    <a href="#login-history"> Login History</a>
    <a href="../index.php"> Home Page</a>
    <a href="../login/logout.php"> Logout</a>

</aside>

<main class="main">

    <!-- HEADER -->

    <div class="topbar">
        <div>
            <h1>Admin Dashboard</h1>

            <p class="muted">
                Welcome, <?= h($_SESSION['full_name']) ?>!
                Here is your hospital analytics overview.
            </p>
        </div>

        <a class="logout" href="../login/logout.php">
            Logout ↗
        </a>
    </div>

    <!-- STATISTICS -->

    <section class="cards">

        <div class="card">
            <p>Total Patients</p>
            <h2><?= number_format($patients) ?></h2>
        </div>

        <div class="card">
            <p>Total Doctors</p>
            <h2><?= number_format($doctors) ?></h2>
        </div>

        <div class="card">
            <p>Departments</p>
            <h2><?= number_format($departments) ?></h2>
        </div>

        <div class="card">
            <p>Appointments</p>
            <h2><?= number_format($appointments) ?></h2>
        </div>

        <div class="card">
            <p>Total Bills</p>
            <h2><?= number_format($bills) ?></h2>
        </div>

        <div class="card">
            <p>Staff Accounts</p>
            <h2><?= number_format($staff) ?></h2>
        </div>

        <div class="card">
            <p>Paid Revenue</p>
            <h2>₹<?= number_format($revenue, 2) ?></h2>
        </div>

    </section>

    <!-- STAFF DASHBOARDS -->

    <h2 class="section-title" id="staff">Staff Dashboards</h2>

    <p class="muted">
        Access the different staff workspaces.
    </p>

    <section class="staff-grid">

        <a class="staff-card" href="../doctor/dashboard.php">
            <h3>Doctor</h3>
            <p>Patient records, appointments, diagnoses and prescriptions.</p>
        </a>

        <a class="staff-card" href="../nurse/dashboard.php">
            <h3>Nurse</h3>
            <p>Assigned patients and nursing updates.</p>
        </a>

        <a class="staff-card" href="../receptionist/dashboard.php">
            <h3> Receptionist</h3>
            <p>Patient registration and appointment management.</p>
        </a>

        <a class="staff-card" href="../billing_staff/dashboard.php">
            <h3>Billing Staff</h3>
            <p>Billing, payment records and revenue information.</p>
        </a>

        <a class="staff-card" href="../pharmacist/dashboard.php">
            <h3> Pharmacist</h3>
            <p>Prescriptions and medicine inventory.</p>
        </a>

        <a class="staff-card" href="../lab_technician/dashboard.php">
            <h3>Lab Technician</h3>
            <p>Lab requests and test results.</p>
        </a>

        <a class="staff-card" href="../data_analyst/dashboard.php">
            <h3> Data Analyst</h3>
            <p>Analytics, charts and hospital reports.</p>
        </a>

    </section>

    <!-- ANALYTICS -->

    <h2 class="section-title" id="analytics">
        Hospital Analytics
    </h2>

    <p class="muted">
        Charts generated from your existing MySQL records.
    </p>

    <section class="chart-grid">

        <div class="chart-box">
            <h3>Monthly Appointments</h3>
            <canvas id="appointmentsChart"></canvas>
        </div>

        <div class="chart-box">
            <h3>Appointment Status</h3>
            <canvas id="statusChart"></canvas>
        </div>

        <div class="chart-box">
            <h3>Doctors by Department</h3>
            <canvas id="departmentChart"></canvas>
        </div>

        <div class="chart-box">
            <h3>Monthly Paid Revenue</h3>
            <canvas id="revenueChart"></canvas>
        </div>

        <div class="chart-box">
            <h3>Patients by Age Group</h3>
            <canvas id="ageChart"></canvas>
        </div>

    </section>

    <!-- RECENT APPOINTMENTS -->

    <section class="panel" id="appointments">

        <h2>Recent Appointments</h2>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($recent as $row): ?>

                <tr>
                    <td><?= h($row['appointment_id']) ?></td>
                    <td><?= h($row['patient_name']) ?></td>
                    <td><?= h($row['doctor_name']) ?></td>
                    <td><?= h($row['appointment_date']) ?></td>
                    <td><?= h($row['status']) ?></td>
                </tr>

            <?php endforeach; ?>

            </tbody>
        </table>

    </section>

    <!-- STAFF ACCOUNTS -->

    <section class="panel" id="staff-accounts">

        <h2>Staff Accounts</h2>

        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Account Status</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($staffList as $person): ?>

                <tr>
                    <td><?= h($person['full_name']) ?></td>
                    <td><?= h($person['email']) ?></td>

                    <td>
                        <?= h(ucwords(str_replace('_', ' ', $person['role']))) ?>
                    </td>

                    <td>
                        <?= $person['is_active'] ? 'Active' : 'Inactive' ?>
                    </td>
                </tr>

            <?php endforeach; ?>

            <?php if (empty($staffList)): ?>
                <tr>
                    <td colspan="4">No staff accounts created yet.</td>
                </tr>
            <?php endif; ?>

            </tbody>
        </table>

    </section>

    <!-- LOGIN HISTORY -->

    <section class="panel" id="login-history">

        <h2>Recent Login Activity</h2>

        <table>
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Login Status</th>
                    <th>Login Time</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($logs as $row): ?>

                <tr>
                    <td><?= h($row['email_attempted']) ?></td>
                    <td><?= h($row['login_status']) ?></td>
                    <td><?= h($row['login_time']) ?></td>
                </tr>

            <?php endforeach; ?>

            </tbody>
        </table>

    </section>

</main>

<!-- CHATBOT WINDOW -->

<div class="chat-window" id="chatWindow">

    <div class="chat-header">

        <div>
            <div class="chat-title">
                🤖 MediPulse Assistant
            </div>

            <div class="chat-subtitle">
                Dashboard Help Assistant
            </div>
        </div>

        <button
            class="chat-close"
            onclick="toggleChat()"
            aria-label="Close chatbot"
        >
            ×
        </button>

    </div>

    <div class="chat-messages" id="chatMessages">

        <div class="chat-message bot-message">
            Hello <?= h($_SESSION['full_name']) ?>! 👋<br><br>

            Welcome to MediPulse. I'm your Dashboard Help Assistant.

            I can explain how to use the Admin Dashboard, view reports,
            understand charts and manage staff.

            What would you like to know?
        </div>

    </div>

    <div class="chat-quick">

        <button onclick="quickQuestion('How do I use the dashboard?')">
            Dashboard Help
        </button>

        <button onclick="quickQuestion('How do I manage staff?')">
            Staff Help
        </button>

        <button onclick="quickQuestion('How do I understand charts?')">
            Chart Help
        </button>

        <button onclick="quickQuestion('How do I view appointments?')">
            Appointments
        </button>

    </div>

    <form class="chat-input-area" id="chatForm">

        <input
            type="text"
            id="chatInput"
            placeholder="Ask me anything about MediPulse..."
            autocomplete="off"
            required
        >

        <button type="submit">Send</button>

    </form>

    <div class="chat-note">
        MediPulse help assistant • Do not enter private patient information.
    </div>

</div>

<!-- FLOATING CHAT BUTTON -->

<button
    class="chat-toggle"
    id="chatToggle"
    onclick="toggleChat()"
    title="Chat with MediPulse Assistant"
    aria-label="Open MediPulse Assistant"
>
    🤖
</button>

<script>

// CHART DATA FROM PHP

const chartTextColor = '#c7d6e5';
const chartGridColor = '#294057';

Chart.defaults.color = chartTextColor;

const monthlyLabels = <?= json_encode($monthlyLabels) ?>;
const monthlyValues = <?= json_encode($monthlyValues) ?>;

const statusLabels = <?= json_encode($statusLabels) ?>;
const statusValues = <?= json_encode($statusValues) ?>;

const departmentLabels = <?= json_encode($departmentLabels) ?>;
const departmentValues = <?= json_encode($departmentValues) ?>;

const revenueLabels = <?= json_encode($revenueLabels) ?>;
const revenueValues = <?= json_encode($revenueValues) ?>;

const ageLabels = <?= json_encode($ageLabels) ?>;
const ageValues = <?= json_encode($ageValues) ?>;

// MONTHLY APPOINTMENTS CHART

new Chart(document.getElementById('appointmentsChart'), {
    type: 'line',

    data: {
        labels: monthlyLabels,

        datasets: [{
            label: 'Appointments',
            data: monthlyValues,
            borderColor: '#23d5e6',
            backgroundColor: 'rgba(35,213,230,0.15)',
            fill: true,
            tension: 0.35
        }]
    },

    options: {
        responsive: true,
        maintainAspectRatio: false,

        scales: {
            y: {
                beginAtZero: true,
                grid: { color: chartGridColor }
            },

            x: {
                grid: { color: chartGridColor }
            }
        }
    }
});

// APPOINTMENT STATUS CHART

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',

    data: {
        labels: statusLabels,

        datasets: [{
            data: statusValues,

            backgroundColor: [
                '#23d5e6',
                '#3987e8',
                '#e6a23c',
                '#e05252',
                '#8e74d8',
                '#40bd89'
            ]
        }]
    },

    options: {
        responsive: true,
        maintainAspectRatio: false
    }
});

// DEPARTMENT CHART

new Chart(document.getElementById('departmentChart'), {
    type: 'bar',

    data: {
        labels: departmentLabels,

        datasets: [{
            label: 'Doctors',
            data: departmentValues,
            backgroundColor: '#23d5e6'
        }]
    },

    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,

        scales: {
            x: {
                beginAtZero: true,
                grid: { color: chartGridColor }
            },

            y: {
                grid: { color: chartGridColor }
            }
        }
    }
});

// REVENUE CHART

new Chart(document.getElementById('revenueChart'), {
    type: 'bar',

    data: {
        labels: revenueLabels,

        datasets: [{
            label: 'Paid Revenue (₹)',
            data: revenueValues,
            backgroundColor: '#40bd89'
        }]
    },

    options: {
        responsive: true,
        maintainAspectRatio: false,

        scales: {
            y: {
                beginAtZero: true,
                grid: { color: chartGridColor }
            },

            x: {
                grid: { color: chartGridColor }
            }
        }
    }
});

// PATIENT AGE CHART

new Chart(document.getElementById('ageChart'), {
    type: 'pie',

    data: {
        labels: ageLabels,

        datasets: [{
            data: ageValues,

            backgroundColor: [
                '#23d5e6',
                '#3987e8',
                '#e6a23c',
                '#e05252',
                '#8e74d8'
            ]
        }]
    },

    options: {
        responsive: true,
        maintainAspectRatio: false
    }
});


// MEDIPULSE CHATBOT

const chatWindow = document.getElementById('chatWindow');
const chatMessages = document.getElementById('chatMessages');
const chatInput = document.getElementById('chatInput');
const chatForm = document.getElementById('chatForm');

function toggleChat() {

    chatWindow.classList.toggle('open');

    if (chatWindow.classList.contains('open')) {
        chatInput.focus();
    }
}

function addMessage(message, type) {

    const messageDiv = document.createElement('div');

    messageDiv.className = 'chat-message ' + type;

    // Text is inserted safely as text, not executable HTML.
    messageDiv.textContent = message;

    chatMessages.appendChild(messageDiv);

    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function getBotAnswer(question) {

    const q = question.toLowerCase();

    // DASHBOARD HELP
    if (
        q.includes('dashboard') ||
        q.includes('start') ||
        q.includes('overview')
    ) {
        return "The Admin Dashboard is your main overview. The summary cards show total patients, doctors, departments, appointments, bills, staff accounts and paid revenue. Use the left sidebar to move between sections.";
    }

    // PATIENTS
    if (
        q.includes('patient') ||
        q.includes('people')
    ) {
        return "The Total Patients card shows how many patient records are stored in MediPulse. Your current patient information comes from the patients table. Patient record management pages can be added to the Receptionist and Admin workspaces.";
    }

    // DOCTORS
    if (q.includes('doctor')) {
        return "The Doctor section is intended for doctor-specific work, including assigned patients, appointments, diagnoses and prescriptions. Select Doctor under Staff Dashboards when its page is available.";
    }

    // NURSES
    if (
        q.includes('nurse') ||
        q.includes('nursing')
    ) {
        return "The Nurse Dashboard is intended for assigned patient information and nursing updates. Nurses should only access records permitted by their role.";
    }

    // STAFF
    if (
        q.includes('staff') ||
        q.includes('employee') ||
        q.includes('account') ||
        q.includes('receptionist') ||
        q.includes('pharmacist') ||
        q.includes('technician') ||
        q.includes('analyst') ||
        q.includes('billing')
    ) {
        return "The Staff Dashboards section contains links for Doctor, Nurse, Receptionist, Billing Staff, Pharmacist, Lab Technician and Data Analyst. The individual staff pages and account-management tools are being built step by step.";
    }

    // APPOINTMENTS
    if (
        q.includes('appointment') ||
        q.includes('booking') ||
        q.includes('schedule')
    ) {
        return "Use the Recent Appointments section to see appointment ID, patient, doctor, date and status. The Analytics Charts section also displays monthly appointment trends and appointment status.";
    }

    // CHARTS
    if (
        q.includes('chart') ||
        q.includes('graph') ||
        q.includes('analytics') ||
        q.includes('report')
    ) {
        return "MediPulse has five charts: Monthly Appointments, Appointment Status, Doctors by Department, Monthly Paid Revenue and Patients by Age Group. These charts are based on your MySQL database records.";
    }

    // REVENUE AND BILLING
    if (
        q.includes('revenue') ||
        q.includes('payment') ||
        q.includes('bill') ||
        q.includes('money')
    ) {
        return "The Paid Revenue card totals payments whose payment_status is 'Paid'. The Billing Staff Dashboard will provide billing and payment workflows when we build it.";
    }

    // LOGIN HISTORY
    if (
        q.includes('login') ||
        q.includes('history') ||
        q.includes('failed')
    ) {
        return "Go to Login History in the sidebar or scroll to Recent Login Activity. It shows the attempted email, login status and login time.";
    }

    // DEPARTMENT
    if (q.includes('department')) {
        return "The Doctors by Department chart displays how many doctors belong to each department. The department information comes from the departments and doctors tables.";
    }

    // AI
    if (
        q.includes('ai') ||
        q.includes('artificial intelligence') ||
        q.includes('chatbot')
    ) {
        return "I am the MediPulse Help Assistant. I explain how to use the dashboard and its features. This version uses predefined answers; it is not connected to a live AI model.";
    }

    // MEDICINE
    if (
        q.includes('medicine') ||
        q.includes('medication') ||
        q.includes('drug') ||
        q.includes('prescription')
    ) {
        return "The current database does not yet contain prescription or medicine inventory tables. We can add these features for authorised doctors and pharmacists. I cannot recommend medicines or make prescribing decisions.";
    }

    // HEALTH PROBLEMS
    if (
        q.includes('disease') ||
        q.includes('problem') ||
        q.includes('diagnosis') ||
        q.includes('health')
    ) {
        return "The current database does not contain diagnosis records. We need a clinician-entered diagnosis table before MediPulse can report common health problems. This chatbot does not diagnose patients.";
    }

    // SECURITY
    if (
        q.includes('permission') ||
        q.includes('access') ||
        q.includes('security')
    ) {
        return "Each staff role will have separate permissions. Admin has overall access; other staff should only view or update information needed for their work. These restrictions must be enforced on the PHP server as well as in the interface.";
    }

    // HELP
    if (
        q.includes('help') ||
        q.includes('what can you do') ||
        q.includes('features')
    ) {
        return "I can explain the dashboard, patients, doctors, staff, appointments, charts, billing, revenue, login history and planned medicine and diagnosis features. Try asking: How do I understand charts?";
    }

    return "I am not sure about that question yet. Please ask me about the Admin Dashboard, patients, staff, appointments, charts, billing, revenue or login history.";
}

function sendChatMessage(question) {

    question = question.trim();

    if (question === '') {
        return;
    }

    addMessage(question, 'user-message');

    chatInput.value = '';

    const answer = getBotAnswer(question);

    // Short delay makes the conversation easier to follow.
    setTimeout(function() {
        addMessage(answer, 'bot-message');
    }, 250);
}

chatForm.addEventListener('submit', function(event) {

    event.preventDefault();

    sendChatMessage(chatInput.value);
});

function quickQuestion(question) {

    sendChatMessage(question);
}

</script>

</body>
</html>

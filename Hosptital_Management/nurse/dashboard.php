
<?php
session_start();
require_once "../config.php";

// Login protection
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login.php");
    exit;
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$userId = (int)$_SESSION['user_id'];
$nurseName = $_SESSION['full_name'] ?? 'Nurse';

if (empty($_SESSION['nurse_csrf'])) {
    $_SESSION['nurse_csrf'] = bin2hex(random_bytes(32));
}

// Create nurse daily task table automatically
$pdo->exec("
    CREATE TABLE IF NOT EXISTS nurse_tasks (
        task_id INT AUTO_INCREMENT PRIMARY KEY,
        nurse_user_id INT NOT NULL,
        patient_id INT NULL,
        task_title VARCHAR(200) NOT NULL,
        task_type VARCHAR(50) NOT NULL DEFAULT 'Other',
        due_date DATE NOT NULL,
        task_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Handle task forms
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['nurse_csrf'], $token)) {
        $message = "Invalid request. Please refresh the page.";
    } else {

        // ADD TASK
        if (isset($_POST['add_task'])) {

            $title = trim($_POST['task_title'] ?? '');
            $type = trim($_POST['task_type'] ?? 'Other');
            $dueDate = $_POST['due_date'] ?? date('Y-m-d');
            $patientId = !empty($_POST['patient_id'])
                ? (int)$_POST['patient_id']
                : null;
            $notes = trim($_POST['notes'] ?? '');

            $allowedTypes = [
                'Vitals',
                'Patient Follow-up',
                'Room Preparation',
                'Documentation',
                'Other'
            ];

            $dateValid = DateTime::createFromFormat('Y-m-d', $dueDate);

            if ($title === '' || strlen($title) > 200) {
                $message = "Enter a task title (maximum 200 characters).";
            } elseif (!in_array($type, $allowedTypes, true)) {
                $message = "Select a valid task type.";
            } elseif (!$dateValid || $dateValid->format('Y-m-d') !== $dueDate) {
                $message = "Select a valid due date.";
            } else {

                // Verify selected patient exists
                if ($patientId !== null) {
                    $check = $pdo->prepare(
                        "SELECT COUNT(*) FROM patients WHERE patient_id = ?"
                    );
                    $check->execute([$patientId]);

                    if ((int)$check->fetchColumn() === 0) {
                        $message = "Selected patient was not found.";
                    }
                }

                if ($message === '') {
                    $stmt = $pdo->prepare("
                        INSERT INTO nurse_tasks
                        (nurse_user_id, patient_id, task_title,
                         task_type, due_date, task_status, notes)
                        VALUES (?, ?, ?, ?, ?, 'Pending', ?)
                    ");

                    $stmt->execute([
                        $userId,
                        $patientId,
                        $title,
                        $type,
                        $dueDate,
                        $notes
                    ]);

                    header("Location: dashboard.php?success=added");
                    exit;
                }
            }
        }

        // UPDATE TASK STATUS
        if (isset($_POST['update_task'])) {

            $taskId = (int)($_POST['task_id'] ?? 0);
            $newStatus = $_POST['task_status'] ?? '';

            if (!in_array($newStatus, ['Pending', 'In Progress', 'Completed'], true)) {
                $message = "Invalid task status.";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE nurse_tasks
                    SET task_status = ?
                    WHERE task_id = ? AND nurse_user_id = ?
                ");

                $stmt->execute([$newStatus, $taskId, $userId]);

                header("Location: dashboard.php?success=updated");
                exit;
            }
        }

        // DELETE TASK
        if (isset($_POST['delete_task'])) {

            $taskId = (int)($_POST['task_id'] ?? 0);

            $stmt = $pdo->prepare("
                DELETE FROM nurse_tasks
                WHERE task_id = ? AND nurse_user_id = ?
            ");

            $stmt->execute([$taskId, $userId]);

            header("Location: dashboard.php?success=deleted");
            exit;
        }
    }
}

if (isset($_GET['success'])) {
    $messages = [
        'added' => 'Task added successfully.',
        'updated' => 'Task status updated successfully.',
        'deleted' => 'Task deleted successfully.'
    ];

    $message = $messages[$_GET['success']] ?? '';
}

// PATIENTS
$patients = $pdo->query("
    SELECT patient_id, patient_name
    FROM patients
    ORDER BY patient_name
")->fetchAll(PDO::FETCH_ASSOC);

// HOSPITAL COUNTS
function getCount($pdo, $sql) {
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (PDOException $ex) {
        return 0;
    }
}

$totalPatients = getCount($pdo, "SELECT COUNT(*) FROM patients");

$totalAppointments = getCount($pdo, "
    SELECT COUNT(*) FROM appointments
");

$todayAppointments = getCount($pdo, "
    SELECT COUNT(*) FROM appointments
    WHERE DATE(appointment_date) = CURDATE()
");

$pendingAppointments = getCount($pdo, "
    SELECT COUNT(*) FROM appointments
    WHERE LOWER(status) = 'pending'
");

// NURSE'S TASK COUNTS
$taskCount = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(task_status = 'Pending') AS pending,
        SUM(task_status = 'In Progress') AS in_progress,
        SUM(task_status = 'Completed') AS completed
    FROM nurse_tasks
    WHERE nurse_user_id = ?
");

$taskCount->execute([$userId]);
$taskStats = $taskCount->fetch(PDO::FETCH_ASSOC);

$totalTasks = (int)($taskStats['total'] ?? 0);
$pendingTasks = (int)($taskStats['pending'] ?? 0);
$progressTasks = (int)($taskStats['in_progress'] ?? 0);
$completedTasks = (int)($taskStats['completed'] ?? 0);

// TODAY'S TASKS
$todayStmt = $pdo->prepare("
    SELECT t.*, p.patient_name
    FROM nurse_tasks t
    LEFT JOIN patients p ON t.patient_id = p.patient_id
    WHERE t.nurse_user_id = ?
      AND t.due_date = CURDATE()
    ORDER BY
        FIELD(t.task_status, 'Pending', 'In Progress', 'Completed'),
        t.created_at DESC
");

$todayStmt->execute([$userId]);
$todayTasks = $todayStmt->fetchAll(PDO::FETCH_ASSOC);

// ALL NURSE TASKS
$allStmt = $pdo->prepare("
    SELECT t.*, p.patient_name
    FROM nurse_tasks t
    LEFT JOIN patients p ON t.patient_id = p.patient_id
    WHERE t.nurse_user_id = ?
    ORDER BY t.due_date DESC, t.created_at DESC
    LIMIT 30
");

$allStmt->execute([$userId]);
$allTasks = $allStmt->fetchAll(PDO::FETCH_ASSOC);

// TODAY'S APPOINTMENTS
$appointmentStmt = $pdo->query("
    SELECT a.appointment_id,
           p.patient_name,
           a.appointment_date,
           a.status
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.patient_id
    WHERE DATE(a.appointment_date) = CURDATE()
    ORDER BY a.appointment_date
");

$appointments = $appointmentStmt->fetchAll(PDO::FETCH_ASSOC);

// TASK CHART - LAST 7 DAYS
$chartStmt = $pdo->prepare("
    SELECT due_date,
           COUNT(*) AS total,
           SUM(task_status = 'Completed') AS completed
    FROM nurse_tasks
    WHERE nurse_user_id = ?
      AND due_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY due_date
    ORDER BY due_date
");

$chartStmt->execute([$userId]);
$chartRows = $chartStmt->fetchAll(PDO::FETCH_ASSOC);

$chartLabels = [];
$chartTotal = [];
$chartCompleted = [];

$chartMap = [];

foreach ($chartRows as $row) {
    $chartMap[$row['due_date']] = $row;
}

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));

    $chartLabels[] = date('D', strtotime($date));
    $chartTotal[] = (int)($chartMap[$date]['total'] ?? 0);
    $chartCompleted[] = (int)($chartMap[$date]['completed'] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>MediPulse | Nurse Dashboard</title>

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

.panel {
    background: white;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    margin-bottom: 20px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.field.full {
    grid-column: 1 / -1;
}

label {
    font-size: 14px;
    font-weight: bold;
}

input, select, textarea {
    padding: 11px;
    border: 1px solid #d8e0eb;
    border-radius: 7px;
    width: 100%;
    background: white;
}

textarea {
    min-height: 75px;
    resize: vertical;
}

.btn {
    background: #245b91;
    color: white;
    padding: 11px 18px;
    border: none;
    border-radius: 7px;
    cursor: pointer;
    text-decoration: none;
}

.btn:hover {
    background: #173e68;
}

.btn-small {
    padding: 7px 10px;
    font-size: 12px;
}

.delete-btn {
    background: #bd4545;
}

.notice {
    background: #e6f5ed;
    color: #21643e;
    padding: 12px;
    border-radius: 8px;
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
    min-width: 650px;
}

th, td {
    padding: 13px;
    text-align: left;
    border-bottom: 1px solid #edf0f4;
    font-size: 14px;
}

th {
    background: #f5f8fc;
}

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #e8f1ff;
    color: #245a9b;
    font-size: 12px;
}

.completed {
    background: #e4f5e9;
    color: #217346;
}

.progress {
    background: #fff2d9;
    color: #91631b;
}

.actions {
    display: flex;
    gap: 6px;
    align-items: center;
}

.actions form {
    display: inline;
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

    .header {
        align-items: flex-start;
        flex-direction: column;
    }

    .form-grid {
        grid-template-columns: 1fr;
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

    <a href="/medipulse/nurse/dashboard.php" class="active">
        📊 Dashboard
    </a>

    <a href="/medipulse/dataset/appointments.csv" target="_blank">
        📅 Appointments
    </a>

    <a href="/medipulse/dataset/patients.csv" target="_blank">
        👥 Patients
    </a>

    <a href="/medipulse/login/logout.php">
        🚪 Logout
    </a>

</aside>

<main class="main">

    <div class="header">
        <div>
            <h1>Nurse Dashboard</h1>
            <p class="subtitle">
                Welcome, <?php echo e($nurseName); ?> |
                Daily Nursing Operations
            </p>
        </div>

        <p><?php echo date('d M Y'); ?></p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice"><?php echo e($message); ?></div>
    <?php endif; ?>

    <h2 class="section-title">Hospital Overview</h2>

    <div class="cards">

        <div class="card">
            <p>Total Patients</p>
            <h2><?php echo $totalPatients; ?></h2>
        </div>

        <div class="card">
            <p>Total Appointments</p>
            <h2><?php echo $totalAppointments; ?></h2>
        </div>

        <div class="card">
            <p>Today's Appointments</p>
            <h2><?php echo $todayAppointments; ?></h2>
        </div>

        <div class="card">
            <p>Pending Appointments</p>
            <h2><?php echo $pendingAppointments; ?></h2>
        </div>

    </div>

    <h2 class="section-title">My Daily Work</h2>

    <div class="cards">

        <div class="card">
            <p>Total My Tasks</p>
            <h2><?php echo $totalTasks; ?></h2>
        </div>

        <div class="card">
            <p>Pending Tasks</p>
            <h2><?php echo $pendingTasks; ?></h2>
        </div>

        <div class="card">
            <p>In Progress</p>
            <h2><?php echo $progressTasks; ?></h2>
        </div>

        <div class="card">
            <p>Completed Tasks</p>
            <h2><?php echo $completedTasks; ?></h2>
        </div>

    </div>

    <h2 class="section-title">Add Daily Nursing Task</h2>

    <div class="panel">

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?php echo e($_SESSION['nurse_csrf']); ?>">

            <div class="form-grid">

                <div class="field">
                    <label>Task Title</label>
                    <input type="text" name="task_title"
                           maxlength="200"
                           placeholder="Example: Check patient vital signs"
                           required>
                </div>

                <div class="field">
                    <label>Task Type</label>
                    <select name="task_type" required>
                        <option value="Vitals">Vitals</option>
                        <option value="Patient Follow-up">Patient Follow-up</option>
                        <option value="Room Preparation">Room Preparation</option>
                        <option value="Documentation">Documentation</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="field">
                    <label>Patient (Optional)</label>
                    <select name="patient_id">
                        <option value="">Select Patient</option>

                        <?php foreach ($patients as $patient): ?>
                            <option value="<?php echo e($patient['patient_id']); ?>">
                                <?php echo e($patient['patient_name']); ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="field">
                    <label>Due Date</label>
                    <input type="date" name="due_date"
                           value="<?php echo date('Y-m-d'); ?>"
                           required>
                </div>

                <div class="field full">
                    <label>Notes</label>
                    <textarea name="notes"
                              placeholder="Enter task details"></textarea>
                </div>

                <div class="field full">
                    <button class="btn" type="submit" name="add_task">
                        + Add Task
                    </button>
                </div>

            </div>

        </form>

    </div>

    <h2 class="section-title">Today's Nursing Tasks</h2>

    <div class="table-card">

        <table>
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Patient</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Update</th>
                </tr>
            </thead>

            <tbody>

            <?php if (empty($todayTasks)): ?>

                <tr>
                    <td colspan="5">No tasks scheduled for today.</td>
                </tr>

            <?php else: ?>

                <?php foreach ($todayTasks as $task): ?>

                    <tr>
                        <td>
                            <strong><?php echo e($task['task_title']); ?></strong>
                            <?php if (!empty($task['notes'])): ?>
                                <br><small><?php echo e($task['notes']); ?></small>
                            <?php endif; ?>
                        </td>

                        <td><?php echo e($task['patient_name'] ?? 'Not assigned'); ?></td>

                        <td><?php echo e($task['task_type']); ?></td>

                        <td>
                            <span class="status <?php
                                echo $task['task_status'] === 'Completed'
                                    ? 'completed'
                                    : ($task['task_status'] === 'In Progress' ? 'progress' : '');
                            ?>">
                                <?php echo e($task['task_status']); ?>
                            </span>
                        </td>

                        <td>
                            <form method="POST" class="actions">
                                <input type="hidden" name="csrf_token"
                                       value="<?php echo e($_SESSION['nurse_csrf']); ?>">

                                <input type="hidden" name="task_id"
                                       value="<?php echo e($task['task_id']); ?>">

                                <input type="hidden" name="update_task" value="1">

                                <select name="task_status">
                                    <option value="Pending"
                                        <?php echo $task['task_status'] === 'Pending' ? 'selected' : ''; ?>>
                                        Pending
                                    </option>

                                    <option value="In Progress"
                                        <?php echo $task['task_status'] === 'In Progress' ? 'selected' : ''; ?>>
                                        In Progress
                                    </option>

                                    <option value="Completed"
                                        <?php echo $task['task_status'] === 'Completed' ? 'selected' : ''; ?>>
                                        Completed
                                    </option>
                                </select>

                                <button class="btn btn-small" type="submit">
                                    Save
                                </button>
                            </form>
                        </td>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>
        </table>

    </div>

    <h2 class="section-title">Today's Appointments</h2>

    <div class="table-card">

        <table>
            <thead>
                <tr>
                    <th>Appointment ID</th>
                    <th>Patient</th>
                    <th>Date & Time</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

            <?php if (empty($appointments)): ?>

                <tr>
                    <td colspan="4">No appointments scheduled for today.</td>
                </tr>

            <?php else: ?>

                <?php foreach ($appointments as $appointment): ?>
                    <tr>
                        <td><?php echo e($appointment['appointment_id']); ?></td>
                        <td><?php echo e($appointment['patient_name'] ?? 'N/A'); ?></td>
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

    <h2 class="section-title">My Task Performance - Last 7 Days</h2>

    <div class="panel">
        <div class="chart-box">
            <canvas id="taskChart"></canvas>
        </div>
    </div>

    <h2 class="section-title">All My Tasks</h2>

    <div class="table-card">

        <table>
            <thead>
                <tr>
                    <th>Task ID</th>
                    <th>Task</th>
                    <th>Patient</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

            <?php if (empty($allTasks)): ?>

                <tr>
                    <td colspan="6">No tasks have been added yet.</td>
                </tr>

            <?php else: ?>

                <?php foreach ($allTasks as $task): ?>

                    <tr>
                        <td><?php echo e($task['task_id']); ?></td>
                        <td><?php echo e($task['task_title']); ?></td>
                        <td><?php echo e($task['patient_name'] ?? 'Not assigned'); ?></td>
                        <td><?php echo e($task['due_date']); ?></td>
                        <td><?php echo e($task['task_status']); ?></td>

                        <td>
                            <form method="POST"
                                  onsubmit="return confirm('Delete this task?');">

                                <input type="hidden" name="csrf_token"
                                       value="<?php echo e($_SESSION['nurse_csrf']); ?>">

                                <input type="hidden" name="task_id"
                                       value="<?php echo e($task['task_id']); ?>">

                                <button class="btn btn-small delete-btn"
                                        type="submit" name="delete_task">
                                    Delete
                                </button>

                            </form>
                        </td>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>
        </table>

    </div>

</main>

<script>
const chartLabels = <?php echo json_encode($chartLabels); ?>;
const chartTotal = <?php echo json_encode($chartTotal); ?>;
const chartCompleted = <?php echo json_encode($chartCompleted); ?>;

new Chart(document.getElementById('taskChart'), {
    type: 'bar',
    data: {
        labels: chartLabels,
        datasets: [
            {
                label: 'Total Tasks',
                data: chartTotal,
                backgroundColor: '#7995c7'
            },
            {
                label: 'Completed Tasks',
                data: chartCompleted,
                backgroundColor: '#4c9b87'
            }
        ]
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

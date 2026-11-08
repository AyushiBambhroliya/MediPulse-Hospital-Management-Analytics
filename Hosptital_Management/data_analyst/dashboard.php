
<?php
session_start();

/* ---------------- LOGIN CHECK ---------------- */
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login.php");
    exit;
}

/* ---------------- CONFIGURATION ---------------- */
$datasetDir = realpath(__DIR__ . '/../dataset');

$allowedFiles = [
    'patients'     => 'patients.csv',
    'doctors'      => 'doctors.csv',
    'departments'  => 'departments.csv',
    'appointments' => 'appointments.csv',
    'billing'      => 'billing.csv',
    'payments'     => 'payments.csv'
];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function readCsvFile($path) {
    if (!$path || !is_file($path) || !is_readable($path)) {
        return [[], []];
    }

    $handle = fopen($path, 'r');

    if (!$handle) {
        return [[], []];
    }

    $headers = fgetcsv($handle);

    if (!$headers) {
        fclose($handle);
        return [[], []];
    }

    $headers = array_map(function ($header) {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header);
        return trim($header);
    }, $headers);

    $rows = [];

    while (($values = fgetcsv($handle)) !== false) {
        if ($values === [null]) {
            continue;
        }

        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $row[$header] = trim((string)($values[$index] ?? ''));
        }

        if (count(array_filter($row, fn($v) => $v !== '')) > 0) {
            $rows[] = $row;
        }
    }

    fclose($handle);

    return [$headers, $rows];
}

function findColumn($headers, $keywords) {
    foreach ($headers as $header) {
        $normalized = strtolower(str_replace([' ', '-', '_'], '', $header));

        foreach ($keywords as $keyword) {
            $keyword = strtolower(str_replace([' ', '-', '_'], '', $keyword));

            if ($normalized === $keyword) {
                return $header;
            }
        }
    }

    return null;
}

function isNumericColumn($rows, $column) {
    if (!$column || !$rows) {
        return false;
    }

    $valid = 0;
    $checked = 0;

    foreach (array_slice($rows, 0, 100) as $row) {
        $value = $row[$column] ?? '';

        if ($value === '') {
            continue;
        }

        $checked++;

        $clean = str_replace([',', '₹', '$', ' '], '', $value);

        if (is_numeric($clean)) {
            $valid++;
        }
    }

    return $checked > 0 && ($valid / $checked) >= 0.7;
}

function numericValue($value) {
    $clean = str_replace([',', '₹', '$', ' '], '', (string)$value);
    return is_numeric($clean) ? (float)$clean : 0;
}

function makeDistribution($rows, $column, $limit = 8) {
    $result = [];

    if (!$column) {
        return $result;
    }

    foreach ($rows as $row) {
        $value = trim($row[$column] ?? '');

        if ($value === '') {
            $value = 'Not specified';
        }

        $result[$value] = ($result[$value] ?? 0) + 1;
    }

    arsort($result);

    return array_slice($result, 0, $limit, true);
}

function makeDateDistribution($rows, $column) {
    $result = [];

    if (!$column) {
        return $result;
    }

    foreach ($rows as $row) {
        $value = trim($row[$column] ?? '');

        if ($value === '') {
            continue;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            continue;
        }

        $month = date('M Y', $timestamp);
        $result[$month] = ($result[$month] ?? 0) + 1;
    }

    return $result;
}

/* ---------------- SELECT DATASET ---------------- */
$selected = $_GET['dataset'] ?? 'patients';

if (!array_key_exists($selected, $allowedFiles)) {
    $selected = 'patients';
}

$filename = $allowedFiles[$selected];
$csvPath = $datasetDir ? $datasetDir . DIRECTORY_SEPARATOR . $filename : null;

/* Fallback to CSV files in project root */
if (!$csvPath || !is_file($csvPath)) {
    $rootPath = realpath(__DIR__ . '/../' . $filename);

    if ($rootPath && is_file($rootPath)) {
        $csvPath = $rootPath;
    }
}

[$headers, $allRows] = readCsvFile($csvPath);

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

/* ---------------- DETECT COLUMNS ---------------- */
$dateColumn = findColumn($headers, [
    'date', 'appointment_date', 'registration_date',
    'bill_date', 'payment_date', 'created_at'
]);

$statusColumn = findColumn($headers, [
    'status', 'appointment_status', 'payment_status',
    'order_status'
]);

$amountColumn = findColumn($headers, [
    'amount', 'amount_paid', 'total_amount',
    'bill_amount', 'price', 'revenue'
]);

$categoryColumn = findColumn($headers, [
    'category', 'department_name', 'specialization',
    'payment_method', 'gender', 'city'
]);

$ageColumn = findColumn($headers, ['age']);

$numericColumns = [];

foreach ($headers as $header) {
    if (isNumericColumn($allRows, $header)) {
        $numericColumns[] = $header;
    }
}

/* ---------------- FILTER DATA ---------------- */
$filteredRows = [];

foreach ($allRows as $row) {
    $matchesSearch = true;
    $matchesStatus = true;

    if ($search !== '') {
        $matchesSearch = false;

        foreach ($row as $value) {
            if (stripos($value, $search) !== false) {
                $matchesSearch = true;
                break;
            }
        }
    }

    if ($statusFilter !== '' && $statusColumn) {
        $matchesStatus = (
            strcasecmp(trim($row[$statusColumn] ?? ''), $statusFilter) === 0
        );
    }

    if ($matchesSearch && $matchesStatus) {
        $filteredRows[] = $row;
    }
}

/* ---------------- ANALYTICS ---------------- */
$totalRecords = count($allRows);
$filteredCount = count($filteredRows);
$columnCount = count($headers);

$uniquePatients = null;
$patientColumn = findColumn($headers, [
    'patient_id', 'patientid'
]);

if ($patientColumn) {
    $uniquePatients = count(array_unique(array_filter(
        array_column($filteredRows, $patientColumn)
    )));
}

$totalAmount = 0;
$amountCount = 0;

if ($amountColumn) {
    foreach ($filteredRows as $row) {
        $value = $row[$amountColumn] ?? '';

        if ($value !== '' && is_numeric(str_replace(
            [',', '₹', '$', ' '], '', $value
        ))) {
            $totalAmount += numericValue($value);
            $amountCount++;
        }
    }
}

$averageAmount = $amountCount > 0 ? $totalAmount / $amountCount : 0;

$statusDistribution = makeDistribution($filteredRows, $statusColumn);
$categoryDistribution = makeDistribution($filteredRows, $categoryColumn);
$dateDistribution = makeDateDistribution($filteredRows, $dateColumn);

/* Available status filters */
$availableStatuses = [];

if ($statusColumn) {
    foreach ($allRows as $row) {
        $value = trim($row[$statusColumn] ?? '');

        if ($value !== '') {
            $availableStatuses[$value] = true;
        }
    }
}

$availableStatuses = array_keys($availableStatuses);
sort($availableStatuses);

/* Numeric column summary */
$numericSummary = [];

foreach ($numericColumns as $column) {
    $sum = 0;
    $count = 0;

    foreach ($filteredRows as $row) {
        $value = $row[$column] ?? '';

        if ($value !== '' && is_numeric(str_replace(
            [',', '₹', '$', ' '], '', $value
        ))) {
            $sum += numericValue($value);
            $count++;
        }
    }

    $numericSummary[] = [
        'column' => $column,
        'sum' => $sum,
        'average' => $count ? $sum / $count : 0,
        'count' => $count
    ];
}

/* ---------------- EXPORT FILTERED CSV ---------------- */
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $selected . '_filtered.csv"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, $headers);

    foreach ($filteredRows as $row) {
        $line = [];

        foreach ($headers as $header) {
            $line[] = $row[$header] ?? '';
        }

        fputcsv($output, $line);
    }

    fclose($output);
    exit;
}

/* ---------------- DASHBOARD DATA ---------------- */
$datasetLabels = [
    'patients' => 'Patients',
    'doctors' => 'Doctors',
    'departments' => 'Departments',
    'appointments' => 'Appointments',
    'billing' => 'Billing',
    'payments' => 'Payments'
];

$analystName = $_SESSION['full_name'] ?? 'Data Analyst';

$chartStatusLabels = array_keys($statusDistribution);
$chartStatusValues = array_values($statusDistribution);

$chartCategoryLabels = array_keys($categoryDistribution);
$chartCategoryValues = array_values($categoryDistribution);

$chartDateLabels = array_keys($dateDistribution);
$chartDateValues = array_values($dateDistribution);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Analyst Dashboard | MediPulse</title>

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
.nav a:hover,.nav a.active{background:#294561;color:white}
.logout{margin-top:30px!important;background:#8f3443}
.main{flex:1;padding:28px;min-width:0}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:25px}
.topbar h1{font-size:25px}
.topbar p{color:#78869a;margin-top:7px;font-size:13px}
.date{background:white;padding:12px 16px;border-radius:9px;color:#536278;font-size:13px}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}
.stat,.panel{background:white;border-radius:13px;padding:20px;box-shadow:0 3px 15px rgba(24,45,75,.05)}
.stat small{color:#7c899b;font-size:12px}
.stat h2{font-size:27px;margin-top:10px;overflow-wrap:anywhere}
.stat .label{font-size:12px;color:#8290a1;margin-top:5px}
.stat-icon{font-size:22px;margin-bottom:12px}
.panel{margin-bottom:20px}
.panel h3{font-size:17px;margin-bottom:16px}
.panel-sub{font-size:12px;color:#8390a2;margin-top:-9px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.chart-box{height:270px;position:relative}
.toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:18px}
.toolbar select,.toolbar input{padding:11px;border:1px solid #dce3eb;border-radius:7px;background:white;font-size:13px}
.toolbar input{flex:1;min-width:170px}
.btn{display:inline-block;text-decoration:none;border:0;border-radius:7px;padding:11px 15px;background:#168f82;color:white;font-weight:bold;cursor:pointer;font-size:13px}
.btn:hover{background:#11796f}
.btn.secondary{background:#263f5c}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:650px}
th,td{text-align:left;padding:12px 10px;border-bottom:1px solid #edf0f4;font-size:12px;vertical-align:top}
th{color:#748196;font-size:11px;text-transform:uppercase;background:#fafbfd}
td{color:#3d4b60;max-width:260px;overflow-wrap:anywhere}
.pill{display:inline-block;background:#e6f5f2;color:#168274;border-radius:20px;padding:5px 9px;font-size:11px}
.muted{color:#8793a3;font-size:12px}
.summary-table{width:100%;min-width:0}
.empty{text-align:center;color:#8995a5;padding:25px}
@media(max-width:1050px){.stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:750px){.layout{display:block}.sidebar{width:100%}.main{padding:16px}.grid{grid-template-columns:1fr}.topbar{align-items:flex-start;flex-direction:column}}
@media(max-width:450px){.stats{grid-template-columns:1fr 1fr}.stat h2{font-size:22px}}
</style>
</head>

<body>
<div class="layout">

<aside class="sidebar">
    <div class="brand">Medi<span>Pulse</span></div>

    <div class="profile">
        <strong><?= e($analystName) ?></strong>
        <small>Data Analyst</small>
    </div>

    <div class="nav-title">Analytics Workspace</div>
    <nav class="nav">
        <a href="#overview" class="active">▦ Dashboard</a>
        <a href="#datasets">▤ Datasets</a>
        <a href="#charts">▥ Visual Analytics</a>
        <a href="#summary">∑ Data Summary</a>
        <a href="#preview">☷ Data Preview</a>
        <a href="../login/logout.php" class="logout">↪ Logout</a>
    </nav>
</aside>

<main class="main">

<header class="topbar" id="overview">
    <div>
        <h1>Data Analytics Dashboard</h1>
        <p>Explore hospital datasets, monitor key metrics and analyse trends.</p>
    </div>
    <div class="date"><?= date('l, d F Y') ?></div>
</header>

<!-- OVERVIEW CARDS -->
<section class="stats">
    <div class="stat">
        <div class="stat-icon">📊</div>
        <small>Total Records</small>
        <h2><?= number_format($totalRecords) ?></h2>
        <div class="label">Selected dataset</div>
    </div>

    <div class="stat">
        <div class="stat-icon">🔎</div>
        <small>Filtered Records</small>
        <h2><?= number_format($filteredCount) ?></h2>
        <div class="label">Matching your filters</div>
    </div>

    <div class="stat">
        <div class="stat-icon">📋</div>
        <small>Total Columns</small>
        <h2><?= number_format($columnCount) ?></h2>
        <div class="label">Available fields</div>
    </div>

    <div class="stat">
        <div class="stat-icon">🧮</div>
        <small>Numeric Columns</small>
        <h2><?= count($numericColumns) ?></h2>
        <div class="label">Detected numeric fields</div>
    </div>

    <div class="stat">
        <div class="stat-icon">💰</div>
        <small><?= $amountColumn ? 'Total ' . e($amountColumn) : 'Amount Column' ?></small>
        <h2><?= $amountColumn ? '₹' . number_format($totalAmount, 2) : 'N/A' ?></h2>
        <div class="label"><?= $amountColumn ? 'Sum of filtered records' : 'Not detected in this dataset' ?></div>
    </div>

    <div class="stat">
        <div class="stat-icon">📈</div>
        <small>Average Amount</small>
        <h2><?= $amountColumn ? '₹' . number_format($averageAmount, 2) : 'N/A' ?></h2>
        <div class="label">Average of numeric values</div>
    </div>

    <div class="stat">
        <div class="stat-icon">👥</div>
        <small>Unique Patient IDs</small>
        <h2><?= $uniquePatients !== null ? number_format($uniquePatients) : 'N/A' ?></h2>
        <div class="label">In selected dataset</div>
    </div>

    <div class="stat">
        <div class="stat-icon">🗂️</div>
        <small>Selected Dataset</small>
        <h2 style="font-size:21px"><?= e($datasetLabels[$selected]) ?></h2>
        <div class="label">Current data source</div>
    </div>
</section>

<!-- DATASET SELECTOR -->
<section class="panel" id="datasets">
    <h3>🗂️ Hospital Datasets</h3>
    <p class="panel-sub">Choose a dataset to explore its records and analytics.</p>

    <form method="GET" class="toolbar">
        <select name="dataset" onchange="this.form.submit()">
            <?php foreach ($datasetLabels as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $selected === $key ? 'selected' : '' ?>>
                    <?= e($label) ?> Dataset
                </option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="q" placeholder="Search records..."
               value="<?= e($search) ?>">

        <?php if ($statusColumn): ?>
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach ($availableStatuses as $status): ?>
                    <option value="<?= e($status) ?>"
                        <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= e($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <button type="submit" class="btn">Apply Filters</button>
        <a class="btn secondary"
           href="?dataset=<?= e($selected) ?>">Reset</a>

        <a class="btn"
           href="?dataset=<?= e($selected) ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&export=1">
            Export CSV
        </a>
    </form>

    <div class="muted">
        Current file: <?= e($filename) ?> ·
        <?= number_format($filteredCount) ?> matching records
    </div>
</section>

<?php if (!$csvPath || !is_file($csvPath)): ?>
    <section class="panel">
        <h3>Dataset not found</h3>
        <p class="muted">
            Please check that <?= e($filename) ?> exists in the dataset folder
            or in the project root.
        </p>
    </section>
<?php elseif (!$headers): ?>
    <section class="panel">
        <h3>Empty or invalid CSV file</h3>
        <p class="muted">The file could not be read or does not contain a header row.</p>
    </section>
<?php else: ?>

<!-- CHARTS -->
<section class="grid" id="charts">

    <div class="panel">
        <h3>📊 Status Distribution</h3>
        <p class="panel-sub">
            <?php if ($statusColumn): ?>
                Records grouped by <?= e($statusColumn) ?>.
            <?php else: ?>
                No status column detected in this dataset.
            <?php endif; ?>
        </p>
        <div class="chart-box">
            <canvas id="statusChart"></canvas>
        </div>
    </div>

    <div class="panel">
        <h3>📌 Category Distribution</h3>
        <p class="panel-sub">
            <?php if ($categoryColumn): ?>
                Records grouped by <?= e($categoryColumn) ?>.
            <?php else: ?>
                No suitable category column detected.
            <?php endif; ?>
        </p>
        <div class="chart-box">
            <canvas id="categoryChart"></canvas>
        </div>
    </div>

    <div class="panel" style="grid-column:1/-1">
        <h3>📈 Date Trend</h3>
        <p class="panel-sub">
            <?php if ($dateColumn): ?>
                Monthly record count using <?= e($dateColumn) ?>.
            <?php else: ?>
                No suitable date column detected.
            <?php endif; ?>
        </p>
        <div class="chart-box">
            <canvas id="dateChart"></canvas>
        </div>
    </div>

</section>

<!-- NUMERIC SUMMARY -->
<section class="panel" id="summary">
    <h3>∑ Numeric Data Summary</h3>
    <p class="panel-sub">Sum and average for detected numeric columns in filtered records.</p>

    <div class="table-wrap">
    <table class="summary-table">
        <thead>
            <tr>
                <th>Column</th>
                <th>Valid Values</th>
                <th>Total / Sum</th>
                <th>Average</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$numericSummary): ?>
            <tr><td colspan="4" class="empty">No numeric columns detected.</td></tr>
        <?php else: ?>
            <?php foreach ($numericSummary as $item): ?>
                <tr>
                    <td><strong><?= e($item['column']) ?></strong></td>
                    <td><?= number_format($item['count']) ?></td>
                    <td><?= number_format($item['sum'], 2) ?></td>
                    <td><?= number_format($item['average'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<!-- DATA PREVIEW -->
<section class="panel" id="preview">
    <h3>☷ Dataset Preview</h3>
    <p class="panel-sub">Showing the first 50 filtered records.</p>

    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <?php foreach ($headers as $header): ?>
                    <th><?= e($header) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php if (!$filteredRows): ?>
            <tr>
                <td colspan="<?= max(1, count($headers)) ?>" class="empty">
                    No matching records found.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach (array_slice($filteredRows, 0, 50) as $row): ?>
                <tr>
                    <?php foreach ($headers as $header): ?>
                        <td><?= e($row[$header] ?? '') ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($filteredCount > 50): ?>
        <p class="muted" style="margin-top:12px">
            Showing 50 of <?= number_format($filteredCount) ?> records.
            Use Export CSV to download all filtered records.
        </p>
    <?php endif; ?>
</section>

<?php endif; ?>

<footer style="text-align:center;color:#8a96a6;font-size:12px;padding:15px">
    MediPulse Hospital Management System · Data Analytics Module
</footer>

</main>
</div>

<script>
function createChart(id, type, labels, values, title) {
    const canvas = document.getElementById(id);

    if (!canvas || !labels.length) {
        if (canvas) {
            canvas.parentElement.innerHTML =
                '<p class="empty">No data available for this chart.</p>';
        }
        return;
    }

    new Chart(canvas, {
        type: type,
        data: {
            labels: labels,
            datasets: [{
                label: title,
                data: values,
                backgroundColor: [
                    '#168f82', '#4c83c3', '#e4a64b',
                    '#8a6bc1', '#df7777', '#54a6a0',
                    '#8294aa', '#bd83a9'
                ],
                borderColor: '#168f82',
                borderWidth: 1,
                borderRadius: 5,
                tension: 0.3,
                fill: type === 'line'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: type === 'doughnut' || type === 'pie'
                }
            },
            scales: (type === 'bar' || type === 'line') ? {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            } : {}
        }
    });
}

createChart(
    'statusChart',
    'doughnut',
    <?= json_encode($chartStatusLabels) ?>,
    <?= json_encode($chartStatusValues) ?>,
    'Records'
);

createChart(
    'categoryChart',
    'bar',
    <?= json_encode($chartCategoryLabels) ?>,
    <?= json_encode($chartCategoryValues) ?>,
    'Records'
);

createChart(
    'dateChart',
    'line',
    <?= json_encode($chartDateLabels) ?>,
    <?= json_encode($chartDateValues) ?>,
    'Records'
);
</script>

</body>
</html>

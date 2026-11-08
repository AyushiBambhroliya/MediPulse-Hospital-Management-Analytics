
<?php
// MediPulse - Central Role-Based Permissions

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rolePermissions = [

    'admin' => ['*'],

    'doctor' => [
        'patients.view',
        'appointments.view',
        'appointments.update',
        'diagnoses.view',
        'diagnoses.create',
        'diagnoses.update',
        'prescriptions.view',
        'prescriptions.create',
        'prescriptions.update',
        'lab_results.view',
        'reports.view',
        'ai.view'
    ],

    'nurse' => [
        'patients.view',
        'appointments.view',
        'nursing.view',
        'nursing.update',
        'lab_results.view'
    ],

    'receptionist' => [
        'patients.view',
        'patients.create',
        'patients.update',
        'appointments.view',
        'appointments.create',
        'appointments.update'
    ],

    'billing_staff' => [
        'billing.view',
        'billing.create',
        'billing.update',
        'payments.view',
        'payments.create',
        'reports.billing'
    ],

    'pharmacist' => [
        'prescriptions.view',
        'inventory.view',
        'inventory.update'
    ],

    'lab_technician' => [
        'lab_requests.view',
        'lab_results.view',
        'lab_results.create',
        'lab_results.update'
    ],

    'data_analyst' => [
        'analytics.view',
        'reports.view',
        'ai.view'
    ]
];

// Check whether the logged-in user has permission.
function hasPermission($permission)
{
    global $rolePermissions;

    $role = $_SESSION['role'] ?? '';

    if (!isset($rolePermissions[$role])) {
        return false;
    }

    if ($role === 'admin') {
        return true;
    }

    return in_array(
        $permission,
        $rolePermissions[$role],
        true
    );
}

// Protect a page or action from unauthorized access.
function requirePermission($permission)
{
    if (!hasPermission($permission)) {
        http_response_code(403);
        exit(
            '<h2>Access Denied</h2>
             <p>You do not have permission to access this page.</p>
             <a href="../login/login.php">Return to Login</a>'
        );
    }
}

// Check whether the user has logged in.
function requireLogin()
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ../login/login.php');
        exit;
    }
}

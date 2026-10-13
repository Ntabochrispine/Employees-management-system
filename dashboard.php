
<?php
require_once __DIR__ . "/includes/auth.php";

requireLogin();

$role = $_SESSION["role"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <h1>Employee Management Dashboard</h1>

    <p>
        Welcome,
        <?= htmlspecialchars($_SESSION["user_name"]) ?>!
    </p>

    <p>
        Your role:
        <strong><?= htmlspecialchars(ucfirst($role)) ?></strong>
    </p>

    <?php if (in_array($role, ["employee"], true)): ?>
        <p><a href="request_leave.php">Request Leave</a></p>
        <p><a href="attendance_kiosk.php">Attendance Terminal</a></p>
        <p><a href="leave_history.php">My Leave History</a></p>
        <p><a href="attendance.php">My Attendance</a></p>

    <?php endif; ?>


    <?php if (in_array($role, ["admin", "hr"], true)): ?>
        <p><a href="employees.php">View employee records</a></p>
        <p><a href="add_employee.php">Register an employee</a></p>
        <p><a href="attendance_kiosk.php">Attendance Terminal</a></p>
        <p><a href="request_leave.php">Request Leave</a></p>
        <p><a href="manage_leave.php">Manage Leave Requests</a></p>
        <p><a href="leave_history.php">My Leave History</a></p>


    <?php endif; ?>

    <?php if (in_array($role, ["admin", "hr", "supervisor"], true)): ?>
        <p><a href="attendance_reports.php">Attendance reports (next module)</a></p>
        <p><a href="timesheets.php">Working Hours &amp; Timesheets</a></p>
        <p><a href="leave_history.php">My Leave History</a></p>

    <?php endif; ?>

    <form method="POST" action="logout.php">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(
                $_SESSION["logout_csrf"] ??= bin2hex(random_bytes(32))
            ) ?>"
        >
        <button type="submit">Log out</button>
    </form>
</div>
</body>
</html>

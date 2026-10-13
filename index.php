
<?php
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";
requireLogin();
require_once __DIR__ . "/config/database.php";

$totalEmployees = (int) $pdo->query(
    "SELECT COUNT(*) FROM employees"
)->fetchColumn();

$activeEmployees = (int) $pdo->query(
    "SELECT COUNT(*) FROM employees
     WHERE employment_status = 'active'"
)->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee Management Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="container">
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="employees.php">Employees</a>
        <a href="add_employee.php">Register Employee</a>
    </nav>

    <h1>Employee Management System</h1>
    <p>Manage your workforce from one place.</p>

    <div class="table-wrapper">
        <table>
            <tr>
                <th>Total Employees</th>
                <th>Active Employees</th>
            </tr>
            <tr>
                <td><?= $totalEmployees ?></td>
                <td><?= $activeEmployees ?></td>
            </tr>
        </table>
    </div>

    <p>
        <a href="add_employee.php">Register an employee</a>
    </p>

    <p>
        <a href="employees.php">View all employees</a>
    </p>
</div>

</body>
</html>

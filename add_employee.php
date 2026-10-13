
<?php
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";
requireRole(["admin", "hr"]);

$errors = [];
$success = "";

$employee_number = "";
$first_name = "";
$last_name = "";
$worker_type = "";
$start_date = "";
$end_date = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $employee_number = trim($_POST["employee_number"] ?? "");
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $worker_type = $_POST["worker_type"] ?? "";
    $start_date = $_POST["start_date"] ?? "";
    $end_date = $_POST["end_date"] ?? "";

    $allowed_types = [
        "permanent",
        "contract",
        "attachment",
        "casual"
    ];

    if ($employee_number === "") {
        $errors[] = "Employee number is required.";
    }

    if ($first_name === "") {
        $errors[] = "First name is required.";
    }

    if ($last_name === "") {
        $errors[] = "Last name is required.";
    }

    if (!in_array($worker_type, $allowed_types, true)) {
        $errors[] = "Please select a valid worker type.";
    }

    if ($start_date !== "" &&
        !DateTime::createFromFormat("Y-m-d", $start_date)) {
        $errors[] = "Invalid start date.";
    }

    if ($end_date !== "" &&
        !DateTime::createFromFormat("Y-m-d", $end_date)) {
        $errors[] = "Invalid end date.";
    }

    if ($start_date !== "" &&
        $end_date !== "" &&
        $end_date < $start_date) {
        $errors[] = "End date cannot be before start date.";
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO employees
                (employee_number, first_name, last_name,
                 worker_type, start_date, end_date)
                VALUES (?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $employee_number,
                $first_name,
                $last_name,
                $worker_type,
                $start_date !== "" ? $start_date : null,
                $end_date !== "" ? $end_date : null
            ]);

            header("Location: employees.php?registered=1");
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() === "23000") {
                $errors[] =
                    "That employee number already exists.";
            } else {
                error_log($e->getMessage());
                $errors[] =
                    "Unable to save employee. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register Employee</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="container">
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="employees.php">Employees</a>
        <a href="add_employee.php">Register Employee</a>
    </nav>

    <h1>Register New Employee</h1>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="add_employee.php">

        <label for="employee_number">Employee Number</label>
        <input
            type="text"
            id="employee_number"
            name="employee_number"
            maxlength="30"
            value="<?= htmlspecialchars($employee_number) ?>"
            required
        >

        <label for="first_name">First Name</label>
        <input
            type="text"
            id="first_name"
            name="first_name"
            maxlength="100"
            value="<?= htmlspecialchars($first_name) ?>"
            required
        >

        <label for="last_name">Last Name</label>
        <input
            type="text"
            id="last_name"
            name="last_name"
            maxlength="100"
            value="<?= htmlspecialchars($last_name) ?>"
            required
        >

        <label for="worker_type">Worker Category</label>
        <select id="worker_type" name="worker_type" required>
            <option value="">Select category</option>
            <option value="permanent"
                <?= $worker_type === "permanent" ? "selected" : "" ?>>
                Permanent
            </option>
            <option value="contract"
                <?= $worker_type === "contract" ? "selected" : "" ?>>
                Contract
            </option>
            <option value="attachment"
                <?= $worker_type === "attachment" ? "selected" : "" ?>>
                Attachment / Intern
            </option>
            <option value="casual"
                <?= $worker_type === "casual" ? "selected" : "" ?>>
                Casual / Support Worker
            </option>
        </select>

        <label for="start_date">Start Date</label>
        <input
            type="date"
            id="start_date"
            name="start_date"
            value="<?= htmlspecialchars($start_date) ?>"
        >

        <label for="end_date">End Date (if applicable)</label>
        <input
            type="date"
            id="end_date"
            name="end_date"
            value="<?= htmlspecialchars($end_date) ?>"
        >

        <button type="submit">Register Employee</button>
    </form>
</div>

</body>
</html>

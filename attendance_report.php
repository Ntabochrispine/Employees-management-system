
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/attendance_report_helpers.php";

requireRole(["admin", "hr"]);

$today = date("Y-m-d");
$startDate = $_GET["start_date"] ?? date("Y-m-01");
$endDate = $_GET["end_date"] ?? $today;
$employeeFilter = filter_var(
    $_GET["employee_id"] ?? "",
    FILTER_VALIDATE_INT
);

if ($employeeFilter === false || $employeeFilter < 1) {
    $employeeFilter = "";
}

$error = "";
$rows = [];

$employees = $pdo->query("
    SELECT id, employee_number, first_name, last_name
    FROM employees
    ORDER BY first_name, last_name
")->fetchAll(PDO::FETCH_ASSOC);

if (
    !reportValidDate($startDate)
    || !reportValidDate($endDate)
    || $startDate > $endDate
    || $endDate > $today
) {
    $error = "Please select a valid date range.";
} else {
    $rows = reportLoadSessions(
        $pdo,
        $startDate,
        $endDate,
        (string) $employeeFilter
    );
}

$query = http_build_query([
    "start_date" => $startDate,
    "end_date" => $endDate,
    "employee_id" => $employeeFilter
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .filters {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 12px;
            margin: 20px 0;
        }

        .filters label {
            display: block;
            margin-bottom: 5px;
        }

        .filters input,
        .filters select,
        .filters button {
            padding: 9px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 15px;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 9px;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #f4f4f4;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Attendance Report</h1>

    <p>
        <a href="dashboard.php">Dashboard</a> |
        <a href="attendance_summary.php">Attendance Summary</a>
    </p>

    <form method="GET" class="filters">
        <div>
            <label for="start_date">Shift start date from</label>
            <input
                type="date"
                id="start_date"
                name="start_date"
                value="<?= reportH($startDate) ?>"
                required
            >
        </div>

        <div>
            <label for="end_date">Shift start date to</label>
            <input
                type="date"
                id="end_date"
                name="end_date"
                value="<?= reportH($endDate) ?>"
                required
            >
        </div>

        <div>
            <label for="employee_id">Employee</label>
            <select name="employee_id" id="employee_id">
                <option value="">All employees</option>

                <?php foreach ($employees as $employee): ?>
                    <option
                        value="<?= (int) $employee["id"] ?>"
                        <?= (string) $employeeFilter === (string) $employee["id"]
                            ? "selected"
                            : "" ?>
                    >
                        <?= reportH(
                            $employee["employee_number"] . " - "
                            . $employee["first_name"] . " "
                            . $employee["last_name"]
                        ) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit">Filter Report</button>
    </form>

    <?php if ($error !== ""): ?>
        <p><?= reportH($error) ?></p>
    <?php else: ?>
        <p>
            <a href="export_attendance.php?<?= reportH($query) ?>">
                Download filtered CSV
            </a>
        </p>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Shift Date</th>
                    <th>Employee Number</th>
                    <th>Employee Name</th>
                    <th>Shift</th>
                    <th>Expected Start</th>
                    <th>Grace Minutes</th>
                    <th>Clock In</th>
                    <th>Clock Out</th>
                    <th>Recorded Duration</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="10">No attendance sessions found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= reportH($row["date"]) ?></td>
                            <td><?= reportH($row["employee_number"]) ?></td>
                            <td><?= reportH($row["employee_name"]) ?></td>
                            <td><?= reportH($row["shift_name"]) ?></td>
                            <td><?= reportH($row["expected_start"] ?: "—") ?></td>
                            <td><?= $row["grace_minutes"] === null
                                ? "—"
                                : (int) $row["grace_minutes"] ?></td>
                            <td><?= reportH($row["clock_in"] ?: "—") ?></td>
                            <td><?= reportH($row["clock_out"] ?: "—") ?></td>
                            <td><?= reportH($row["duration"] ?: "—") ?></td>
                            <td><?= reportH($row["status"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>


<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireRole(["hr", "admin"]);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$search = trim($_GET["search"] ?? "");
$startDate = trim($_GET["start_date"] ?? "");
$endDate = trim($_GET["end_date"] ?? "");

function validDate(string $value): bool
{
    $date = DateTime::createFromFormat("!Y-m-d", $value);
    $errors = DateTime::getLastErrors();

    return $date !== false
        && (
            $errors === false ||
            (
                $errors["warning_count"] === 0 &&
                $errors["error_count"] === 0
            )
        )
        && $date->format("Y-m-d") === $value;
}

$error = "";
$logs = [];

if ($startDate !== "" && !validDate($startDate)) {
    $error = "The start date is invalid.";
}

if ($endDate !== "" && !validDate($endDate)) {
    $error = "The end date is invalid.";
}

if (
    $error === "" &&
    $startDate !== "" &&
    $endDate !== "" &&
    $endDate < $startDate
) {
    $error = "The end date cannot be earlier than the start date.";
}

if ($error === "") {
    $sql = "
        SELECT
            al.id AS audit_id,
            al.attendance_event_id,
            al.old_event_type,
            al.new_event_type,
            al.old_recorded_at,
            al.new_recorded_at,
            al.old_event_status,
            al.new_event_status,
            al.reason,
            al.changed_at,

            e.employee_number,
            e.first_name,
            e.last_name,

            u.name AS changed_by_name,
            u.email AS changed_by_email

        FROM attendance_audit_logs al

        INNER JOIN employees e
            ON e.id = al.employee_id

        INNER JOIN users u
            ON u.id = al.changed_by

        WHERE 1 = 1
    ";

    $params = [];

    if ($search !== "") {
        $sql .= "
            AND (
                e.employee_number LIKE :employee_number
                OR e.first_name LIKE :first_name
                OR e.last_name LIKE :last_name
                OR u.name LIKE :changed_by_name
                OR u.email LIKE :changed_by_email
            )
        ";

        $term = "%" . $search . "%";

        $params["employee_number"] = $term;
        $params["first_name"] = $term;
        $params["last_name"] = $term;
        $params["changed_by_name"] = $term;
        $params["changed_by_email"] = $term;
    }

    if ($startDate !== "") {
        $sql .= " AND al.changed_at >= :start_date";
        $params["start_date"] = $startDate . " 00:00:00";
    }

    if ($endDate !== "") {
        $sql .= " AND al.changed_at < :end_date";
        $params["end_date"] = date(
            "Y-m-d",
            strtotime($endDate . " +1 day")
        ) . " 00:00:00";
    }

    $sql .= " ORDER BY al.changed_at DESC, al.id DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Attendance Audit History</title>

    <link rel="stylesheet" href="assets/style.css">

    <style>
        .page-container {
            max-width: 1400px;
            margin: 25px auto;
            padding: 20px;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 12px;
            margin: 20px 0;
            padding: 16px;
            background: #f5f7fa;
            border-radius: 6px;
        }

        .filters label {
            display: block;
            margin-bottom: 5px;
        }

        .filters input {
            padding: 9px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f0f2f5;
        }

        .reason {
            min-width: 200px;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .notice {
            padding: 12px;
            background: #f5f7fa;
            border-radius: 5px;
            margin: 15px 0;
        }

        .error {
            padding: 12px;
            background: #ffe7e7;
            color: #8a1c1c;
            margin: 15px 0;
        }
    </style>
</head>

<body>
<div class="page-container">

    <h1>Attendance Audit History</h1>

    <p>
        Review the history of attendance corrections made by HR
        and administrators.
    </p>

    <p>
        <a href="attendance_corrections.php">Attendance Corrections</a>
        |
        <a href="attendance_report.php">Attendance Report</a>
        |
        <a href="dashboard.php">Dashboard</a>
    </p>

    <div class="notice">
        This page displays up to 200 correction records, newest first.
        The date filters refer to when a correction was made, not the
        original attendance date.
    </div>

    <?php if ($error !== ""): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="GET" class="filters">
        <div>
            <label for="search">Search employee or reviewer</label>
            <input
                type="text"
                name="search"
                id="search"
                value="<?= h($search) ?>"
                placeholder="Name, employee number or email"
            >
        </div>

        <div>
            <label for="start_date">Correction From</label>
            <input
                type="date"
                name="start_date"
                id="start_date"
                value="<?= h($startDate) ?>"
            >
        </div>

        <div>
            <label for="end_date">Correction To</label>
            <input
                type="date"
                name="end_date"
                id="end_date"
                value="<?= h($endDate) ?>"
            >
        </div>

        <button type="submit">Filter</button>

        <a href="attendance_audit.php">Clear Filters</a>
    </form>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Audit ID</th>
                    <th>Event ID</th>
                    <th>Employee</th>
                    <th>Changed By</th>
                    <th>Original Event</th>
                    <th>Corrected Event</th>
                    <th>Original Status</th>
                    <th>Corrected Status</th>
                    <th>Reason</th>
                    <th>Correction Date</th>
                </tr>
            </thead>

            <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="10">No audit records found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= (int) $log["audit_id"] ?></td>

                        <td><?= (int) $log["attendance_event_id"] ?></td>

                        <td>
                            <?= h(
                                $log["employee_number"] . " - " .
                                $log["first_name"] . " " .
                                $log["last_name"]
                            ) ?>
                        </td>

                        <td>
                            <?= h($log["changed_by_name"]) ?>
                            <br>
                            <small><?= h($log["changed_by_email"]) ?></small>
                        </td>

                        <td>
                            <strong>
                                <?= h(ucwords(str_replace(
                                    "_",
                                    " ",
                                    $log["old_event_type"]
                                ))) ?>
                            </strong>
                            <br>
                            <?= h($log["old_recorded_at"]) ?>
                        </td>

                        <td>
                            <strong>
                                <?= h(ucwords(str_replace(
                                    "_",
                                    " ",
                                    $log["new_event_type"]
                                ))) ?>
                            </strong>
                            <br>
                            <?= h($log["new_recorded_at"]) ?>
                        </td>

                        <td><?= h(ucfirst($log["old_event_status"])) ?></td>

                        <td><?= h(ucfirst($log["new_event_status"])) ?></td>

                        <td class="reason"><?= nl2br(h($log["reason"])) ?></td>

                        <td><?= h($log["changed_at"]) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>

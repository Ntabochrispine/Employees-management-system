
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireRole(["hr", "admin"]);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function validDate(string $value): bool
{
    $date = DateTime::createFromFormat("!Y-m-d", $value);
    $errors = DateTime::getLastErrors();

    return $date !== false
        && ($errors === false ||
            ($errors["warning_count"] === 0 && $errors["error_count"] === 0))
        && $date->format("Y-m-d") === $value;
}

$search = trim($_GET["search"] ?? "");
$startDate = trim($_GET["start_date"] ?? "");
$endDate = trim($_GET["end_date"] ?? "");
$status = $_GET["status"] ?? "";

$error = "";

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

$allowedStatuses = ["accepted", "rejected", "corrected"];

if ($status !== "" && !in_array($status, $allowedStatuses, true)) {
    $status = "";
}

$events = [];

if ($error === "") {
    $sql = "
        SELECT
            a.id,
            a.employee_id,
            a.event_type,
            a.recorded_at,
            a.verification_method,
            a.event_status,
            e.employee_number,
            e.first_name,
            e.last_name
        FROM attendance_events a
        INNER JOIN employees e ON e.id = a.employee_id
        WHERE 1 = 1
    ";

    $params = [];

    if ($search !== "") {
        $sql .= "
            AND (
                e.employee_number LIKE :employee_number
                OR e.first_name LIKE :first_name
                OR e.last_name LIKE :last_name
            )
        ";

        $searchTerm = "%" . $search . "%";

        $params["employee_number"] = $searchTerm;
        $params["first_name"] = $searchTerm;
        $params["last_name"] = $searchTerm;
    }

    if ($startDate !== "") {
        $sql .= " AND a.recorded_at >= :start_date";
        $params["start_date"] = $startDate . " 00:00:00";
    }

    if ($endDate !== "") {
        $sql .= " AND a.recorded_at <= :end_date";
        $params["end_date"] = $endDate . " 23:59:59";
    }

    if ($status !== "") {
        $sql .= " AND a.event_status = :status";
        $params["status"] = $status;
    }

    $sql .= " ORDER BY a.recorded_at DESC, a.id DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Corrections</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .page-container {
            max-width: 1250px;
            margin: 25px auto;
            padding: 20px;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 12px;
            margin-bottom: 20px;
            padding: 16px;
            background: #f5f7fa;
            border-radius: 6px;
        }

        .filter-form label {
            display: block;
            margin-bottom: 5px;
        }

        .filter-form input,
        .filter-form select {
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
            padding: 11px;
            border: 1px solid #ddd;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #f0f2f5;
        }

        .edit-link {
            display: inline-block;
            padding: 7px 11px;
            background: #155eef;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }

        .error-message {
            padding: 12px;
            background: #ffe7e7;
            color: #8a1c1c;
            margin-bottom: 15px;
        }

        .note {
            color: #555;
            margin: 12px 0;
        }
    </style>
</head>

<body>
<div class="page-container">

    <h1>Attendance Corrections</h1>

    <p>
        Review attendance events and select a record to correct.
        Every saved correction is recorded in the audit log.
    </p>

    <p>
        <a href="attendance_report.php">Back to Attendance Report</a>
    </p>

    <?php if ($error !== ""): ?>
        <div class="error-message"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="GET" class="filter-form">
        <div>
            <label for="search">Employee Search</label>
            <input
                type="text"
                id="search"
                name="search"
                value="<?= h($search) ?>"
                placeholder="Number or name"
            >
        </div>

        <div>
            <label for="start_date">From Date</label>
            <input
                type="date"
                id="start_date"
                name="start_date"
                value="<?= h($startDate) ?>"
            >
        </div>

        <div>
            <label for="end_date">To Date</label>
            <input
                type="date"
                id="end_date"
                name="end_date"
                value="<?= h($endDate) ?>"
            >
        </div>

        <div>
            <label for="status">Event Status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                <option value="accepted"
                    <?= $status === "accepted" ? "selected" : "" ?>>
                    Accepted
                </option>
                <option value="rejected"
                    <?= $status === "rejected" ? "selected" : "" ?>>
                    Rejected
                </option>
                <option value="corrected"
                    <?= $status === "corrected" ? "selected" : "" ?>>
                    Corrected
                </option>
            </select>
        </div>

        <button type="submit">Filter</button>
        <a href="attendance_corrections.php">Clear Filters</a>
    </form>

    <p class="note">
        Showing up to 100 matching events, newest first.
    </p>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Event ID</th>
                    <th>Employee Number</th>
                    <th>Employee Name</th>
                    <th>Event Type</th>
                    <th>Recorded Date and Time</th>
                    <th>Verification Method</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php if (empty($events)): ?>
                <tr>
                    <td colspan="8">No attendance events found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?= (int) $event["id"] ?></td>

                        <td><?= h($event["employee_number"]) ?></td>

                        <td>
                            <?= h(
                                $event["first_name"] . " " .
                                $event["last_name"]
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                ucwords(
                                    str_replace("_", " ", $event["event_type"])
                                )
                            ) ?>
                        </td>

                        <td><?= h($event["recorded_at"]) ?></td>

                        <td>
                            <?= h(
                                ucwords(
                                    str_replace(
                                        "_",
                                        " ",
                                        $event["verification_method"]
                                    )
                                )
                            ) ?>
                        </td>

                        <td><?= h(ucfirst($event["event_status"])) ?></td>

                        <td>
                            <a
                                class="edit-link"
                                href="correct_attendance.php?id=<?= (int) $event["id"] ?>"
                            >
                                Correct
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>

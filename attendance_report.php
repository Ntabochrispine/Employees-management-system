
<?php
require_once __DIR__ . "/includes/auth.php";
requireRole(["admin", "hr", "supervisor"]);

require_once __DIR__ . "/config/database.php";

$stmt = $pdo->query(
    "SELECT
        a.id,
        a.event_type,
        a.recorded_at,
        a.verification_method,
        a.event_status,
        e.employee_number,
        e.first_name,
        e.last_name
     FROM attendance_events a
     INNER JOIN employees e ON e.id = a.employee_id
     ORDER BY a.recorded_at DESC, a.id DESC
     LIMIT 100"
);

$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="container">
    <h1>Attendance Report</h1>

    <p>
        <a href="dashboard.php">Dashboard</a> |
        <a href="attendance_kiosk.php">Attendance Terminal</a>
    </p>

    <p>Showing the latest 100 attendance events.</p>

    <?php if (count($records) === 0): ?>
        <p>No attendance records have been recorded yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Employee No.</th>
                    <th>Employee Name</th>
                    <th>Event</th>
                    <th>Date and Time</th>
                    <th>Verification</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($record["employee_number"]) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars(
                                $record["first_name"] . " " .
                                $record["last_name"]
                            ) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars(
                                ucwords(str_replace(
                                    "_",
                                    " ",
                                    $record["event_type"]
                                ))
                            ) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($record["recorded_at"]) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($record["verification_method"]) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($record["event_status"]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>

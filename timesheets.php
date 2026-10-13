
<?php
require_once __DIR__ . "/includes/auth.php";
requireRole(["admin", "hr", "supervisor"]);

require_once __DIR__ . "/config/database.php";

date_default_timezone_set("Africa/Nairobi");

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);

    return $date !== false && $date->format("Y-m-d") === $value;
}

$today = date("Y-m-d");

$from = $_GET["from"] ?? $today;
$to = $_GET["to"] ?? $today;

if (
    !is_string($from) ||
    !is_string($to) ||
    !validDate($from) ||
    !validDate($to) ||
    $from > $to
) {
    http_response_code(400);
    exit("Invalid date range. Please select valid dates.");
}

$start = new DateTimeImmutable($from . " 00:00:00");
$endExclusive = (new DateTimeImmutable($to))
    ->modify("+1 day")
    ->format("Y-m-d 00:00:00");

$stmt = $pdo->prepare(
    "SELECT
        e.id AS employee_id,
        e.employee_number,
        e.first_name,
        e.last_name,
        a.event_type,
        a.recorded_at
     FROM attendance_events a
     INNER JOIN employees e ON e.id = a.employee_id
     WHERE a.event_status = 'accepted'
       AND a.recorded_at >= :start_date
       AND a.recorded_at < :end_date
     ORDER BY e.employee_number, a.recorded_at, a.id"
);

$stmt->execute([
    "start_date" => $start->format("Y-m-d H:i:s"),
    "end_date" => $endExclusive
]);

$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$days = [];

foreach ($events as $event) {
    $employeeId = $event["employee_id"];
    $date = substr($event["recorded_at"], 0, 10);
    $key = $employeeId . "|" . $date;

    if (!isset($days[$key])) {
        $days[$key] = [
            "employee_number" => $event["employee_number"],
            "name" => $event["first_name"] . " " .
                      $event["last_name"],
            "date" => $date,
            "first_in" => null,
            "last_out" => null,
            "seconds_worked" => 0,
            "open_in" => null,
            "incomplete" => false
        ];
    }

    $timestamp = strtotime($event["recorded_at"]);
    $day = &$days[$key];

    if ($event["event_type"] === "clock_in") {
        if ($day["first_in"] === null) {
            $day["first_in"] = $timestamp;
        }

        // A second clock-in without a clock-out is an anomaly.
        if ($day["open_in"] !== null) {
            $day["incomplete"] = true;
        }

        $day["open_in"] = $timestamp;
    } elseif ($event["event_type"] === "clock_out") {
        $day["last_out"] = $timestamp;

        if (
            $day["open_in"] !== null &&
            $timestamp >= $day["open_in"]
        ) {
            $day["seconds_worked"] +=
                $timestamp - $day["open_in"];

            $day["open_in"] = null;
        } else {
            // Clock-out without a matching clock-in.
            $day["incomplete"] = true;
        }
    }

    unset($day);
}

$rows = array_values($days);

usort($rows, function ($a, $b) {
    return [$b["date"], $a["employee_number"]]
        <=> [$a["date"], $b["employee_number"]];
});

$totalSeconds = 0;
$lateCount = 0;
$incompleteCount = 0;

foreach ($rows as &$row) {
    $row["late"] = false;

    if ($row["first_in"] !== null) {
        $scheduledStart = strtotime(
            $row["date"] . " 08:00:00"
        );

        $row["late"] = $row["first_in"] > $scheduledStart;
    }

    if ($row["open_in"] !== null) {
        $row["incomplete"] = true;
    }

    $totalSeconds += $row["seconds_worked"];

    if ($row["late"]) {
        $lateCount++;
    }

    if ($row["incomplete"]) {
        $incompleteCount++;
    }
}
unset($row);

function formatDuration(int $seconds): string
{
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return sprintf("%02d:%02d", $hours, $minutes);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Timesheets</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="container">
    <h1>Employee Timesheets</h1>

    <p>
        <a href="dashboard.php">Dashboard</a> |
        <a href="attendance_report.php">Attendance Report</a> |
        <a href="attendance_kiosk.php">Attendance Terminal</a>
    </p>

    <form method="GET" action="timesheets.php">
        <label for="from">From date</label>
        <input
            type="date"
            id="from"
            name="from"
            value="<?= htmlspecialchars($from) ?>"
            required
        >

        <label for="to">To date</label>
        <input
            type="date"
            id="to"
            name="to"
            value="<?= htmlspecialchars($to) ?>"
            required
        >

        <button type="submit">Generate Timesheet</button>
    </form>

    <h2>Summary</h2>

    <p>Attendance records: <?= count($rows) ?></p>
    <p>Late arrivals: <?= $lateCount ?></p>
    <p>Incomplete records: <?= $incompleteCount ?></p>
    <p>
        Total recorded working time across all employees:
        <?= htmlspecialchars(formatDuration($totalSeconds)) ?>
    </p>

    <h2>Daily Attendance Details</h2>

    <?php if (count($rows) === 0): ?>
        <p>No attendance events were found for this date range.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Employee No.</th>
                    <th>Name</th>
                    <th>First Clock-in</th>
                    <th>Last Clock-out</th>
                    <th>Hours Worked</th>
                    <th>Arrival</th>
                    <th>Record Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($row["date"]) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($row["employee_number"]) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($row["name"]) ?>
                        </td>
                        <td>
                            <?= $row["first_in"] !== null
                                ? htmlspecialchars(date("H:i:s", $row["first_in"]))
                                : "—" ?>
                        </td>
                        <td>
                            <?= $row["last_out"] !== null
                                ? htmlspecialchars(date("H:i:s", $row["last_out"]))
                                : "—" ?>
                        </td>
                        <td>
                            <?= htmlspecialchars(
                                formatDuration($row["seconds_worked"])
                            ) ?>
                        </td>
                        <td>
                            <?php if ($row["first_in"] === null): ?>
                                No clock-in
                            <?php elseif ($row["late"]): ?>
                                Late
                            <?php else: ?>
                                On time
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $row["incomplete"]
                                ? "Needs review"
                                : "Complete" ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p>
        Hours are displayed as HH:MM. Only matched clock-in and
        clock-out intervals contribute to working time.
    </p>
</div>

</body>
</html>


<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireRole(["admin", "hr"]);

function summaryH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function summaryValidDate(string $date): bool
{
    $parsed = DateTime::createFromFormat("!Y-m-d", $date);
    return $parsed !== false && $parsed->format("Y-m-d") === $date;
}

function summaryDuration(int $seconds): string
{
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return $hours . "h " . $minutes . "m";
}

function summaryClock(?string $datetime): string
{
    return $datetime ? date("H:i", strtotime($datetime)) : "—";
}

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

$totals = [
    "sessions" => 0,
    "complete" => 0,
    "incomplete" => 0,
    "late" => 0,
    "no_shift" => 0,
    "unmatched_out" => 0,
    "seconds" => 0
];

$employees = $pdo->query("
    SELECT id, employee_number, first_name, last_name
    FROM employees
    ORDER BY first_name, last_name
")->fetchAll(PDO::FETCH_ASSOC);

if (
    !summaryValidDate($startDate)
    || !summaryValidDate($endDate)
    || $startDate > $endDate
    || $endDate > $today
) {
    $error = "Select a valid date range. The end date cannot be in the future.";
} else {
    /*
     * Fetch one extra day before and two days beyond the selected
     * end date's midnight boundary. This gives the pairing algorithm
     * enough context for ordinary overnight shifts.
     */
    $fetchStart = (new DateTime($startDate))
        ->modify("-1 day")
        ->format("Y-m-d");

    $fetchEndExclusive = (new DateTime($endDate))
        ->modify("+2 days")
        ->format("Y-m-d");

    $sql = "
        SELECT
            ae.id,
            ae.employee_id,
            ae.event_type,
            ae.recorded_at,
            e.employee_number,
            e.first_name,
            e.last_name
        FROM attendance_events ae
        INNER JOIN employees e ON e.id = ae.employee_id
        WHERE ae.event_status = 'accepted'
          AND ae.recorded_at >= :fetch_start
          AND ae.recorded_at < :fetch_end
    ";

    $params = [
        ":fetch_start" => $fetchStart . " 00:00:00",
        ":fetch_end" => $fetchEndExclusive . " 00:00:00"
    ];

    if ($employeeFilter !== "") {
        $sql .= " AND ae.employee_id = :employee_id";
        $params[":employee_id"] = $employeeFilter;
    }

    $sql .= "
        ORDER BY ae.employee_id ASC, ae.recorded_at ASC, ae.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Pair events in chronological order.
     *
     * A clock-in on October 14 at 22:00 can pair with a clock-out
     * on October 15 at 06:00.
     */
    $eventsByEmployee = [];

    foreach ($events as $event) {
        $eventsByEmployee[(int) $event["employee_id"]][] = $event;
    }

    $sessions = [];

    foreach ($eventsByEmployee as $employeeId => $employeeEvents) {
        $openClockIn = null;

        foreach ($employeeEvents as $event) {
            if ($event["event_type"] === "clock_in") {
                /*
                 * A second clock-in without a clock-out means the
                 * previous session was left open. Keep it visible.
                 */
                if ($openClockIn !== null) {
                    $sessions[] = [
                        "employee_id" => $employeeId,
                        "employee_number" => $openClockIn["employee_number"],
                        "employee_name" => $openClockIn["first_name"] . " "
                            . $openClockIn["last_name"],
                        "date" => substr($openClockIn["recorded_at"], 0, 10),
                        "clock_in" => $openClockIn["recorded_at"],
                        "clock_out" => null,
                        "anomaly" => ""
                    ];
                }

                $openClockIn = $event;
                continue;
            }

            // This event is a clock-out.
            if ($openClockIn === null) {
                $sessions[] = [
                    "employee_id" => $employeeId,
                    "employee_number" => $event["employee_number"],
                    "employee_name" => $event["first_name"] . " "
                        . $event["last_name"],
                    "date" => substr($event["recorded_at"], 0, 10),
                    "clock_in" => null,
                    "clock_out" => $event["recorded_at"],
                    "anomaly" => "unmatched_out"
                ];

                continue;
            }

            $sessions[] = [
                "employee_id" => $employeeId,
                "employee_number" => $openClockIn["employee_number"],
                "employee_name" => $openClockIn["first_name"] . " "
                    . $openClockIn["last_name"],
                "date" => substr($openClockIn["recorded_at"], 0, 10),
                "clock_in" => $openClockIn["recorded_at"],
                "clock_out" => $event["recorded_at"],
                "anomaly" => ""
            ];

            $openClockIn = null;
        }

        // Preserve a clock-in that has not yet been closed.
        if ($openClockIn !== null) {
            $sessions[] = [
                "employee_id" => $employeeId,
                "employee_number" => $openClockIn["employee_number"],
                "employee_name" => $openClockIn["first_name"] . " "
                    . $openClockIn["last_name"],
                "date" => substr($openClockIn["recorded_at"], 0, 10),
                "clock_in" => $openClockIn["recorded_at"],
                "clock_out" => null,
                "anomaly" => ""
            ];
        }
    }

    /*
     * Fetch assignments effective during the selected shift-start dates.
     */
    $assignmentSql = "
        SELECT
            a.employee_id,
            a.effective_from,
            a.effective_to,
            s.shift_name,
            s.start_time,
            s.end_time,
            s.grace_period_minutes
        FROM employee_shift_assignments a
        INNER JOIN work_shifts s ON s.id = a.shift_id
        WHERE a.effective_from <= :end_date
          AND (
              a.effective_to IS NULL
              OR a.effective_to >= :start_date
          )
    ";

    $assignmentParams = [
        ":start_date" => $startDate,
        ":end_date" => $endDate
    ];

    if ($employeeFilter !== "") {
        $assignmentSql .= " AND a.employee_id = :employee_id";
        $assignmentParams[":employee_id"] = $employeeFilter;
    }

    $assignmentSql .= "
        ORDER BY a.employee_id, a.effective_from DESC
    ";

    $assignmentStmt = $pdo->prepare($assignmentSql);
    $assignmentStmt->execute($assignmentParams);

    $assignmentsByEmployee = [];

    foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
        $assignmentsByEmployee[(int) $assignment["employee_id"]][] = $assignment;
    }

    /*
     * Calculate each session using the shift assigned on its start date.
     */
    foreach ($sessions as $session) {
        $day = $session["date"];

        // Only report sessions whose shift-start date is in the chosen range.
        if ($day < $startDate || $day > $endDate) {
            continue;
        }

        $employeeId = (int) $session["employee_id"];
        $clockIn = $session["clock_in"];
        $clockOut = $session["clock_out"];

        $shift = null;

        foreach ($assignmentsByEmployee[$employeeId] ?? [] as $assignment) {
            if (
                $assignment["effective_from"] <= $day
                && (
                    $assignment["effective_to"] === null
                    || $assignment["effective_to"] >= $day
                )
            ) {
                $shift = $assignment;
                break;
            }
        }

        $shiftName = $shift["shift_name"] ?? "No shift assigned";
        $expectedStart = $shift
            ? substr($shift["start_time"], 0, 5)
            : "—";
        $graceMinutes = $shift
            ? (int) $shift["grace_period_minutes"]
            : null;

        $durationSeconds = 0;
        $isComplete = false;
        $isLate = false;

        if ($clockIn !== null && $clockOut !== null) {
            $inTimestamp = strtotime($clockIn);
            $outTimestamp = strtotime($clockOut);

            if ($outTimestamp > $inTimestamp) {
                $durationSeconds = $outTimestamp - $inTimestamp;
                $isComplete = true;
            }
        }

        if ($shift !== null && $clockIn !== null) {
            $threshold = new DateTime(
                $day . " " . $shift["start_time"]
            );

            $threshold->modify("+" . $graceMinutes . " minutes");

            $isLate = new DateTime($clockIn) > $threshold;
        }

        if ($session["anomaly"] === "unmatched_out") {
            $status = "Unmatched Clock-out";
        } elseif (!$isComplete) {
            $status = $shift === null
                ? "Incomplete — No Shift"
                : "Incomplete";
        } elseif ($shift === null) {
            $status = "No Shift Assigned";
        } elseif ($isLate) {
            $status = "Late";
        } else {
            $status = "Complete";
        }

        $totals["sessions"]++;

        if ($isComplete) {
            $totals["complete"]++;
            $totals["seconds"] += $durationSeconds;
        } else {
            $totals["incomplete"]++;
        }

        if ($isLate) {
            $totals["late"]++;
        }

        if ($shift === null) {
            $totals["no_shift"]++;
        }

        if ($session["anomaly"] === "unmatched_out") {
            $totals["unmatched_out"]++;
        }

        $rows[] = [
            "date" => $day,
            "employee_number" => $session["employee_number"],
            "employee_name" => $session["employee_name"],
            "shift_name" => $shiftName,
            "expected_start" => $expectedStart,
            "grace_minutes" => $graceMinutes,
            "clock_in" => $clockIn,
            "clock_out" => $clockOut,
            "duration" => $durationSeconds,
            "complete" => $isComplete,
            "status" => $status
        ];
    }

    usort($rows, function ($a, $b) {
        $dateComparison = strcmp($b["date"], $a["date"]);

        if ($dateComparison !== 0) {
            return $dateComparison;
        }

        return strcmp($a["employee_name"], $b["employee_name"]);
    });
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Summary</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .summary-card {
            padding: 18px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fff;
        }

        .summary-card h3 {
            margin: 0 0 10px;
            font-size: 14px;
            color: #555;
        }

        .summary-card p {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 12px;
            margin: 20px 0;
        }

        .filter-form label {
            display: block;
            margin-bottom: 5px;
        }

        .filter-form input,
        .filter-form select,
        .filter-form button {
            padding: 9px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .table-wrapper table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table-wrapper th,
        .table-wrapper td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
            white-space: nowrap;
        }

        .table-wrapper th {
            background: #f4f4f4;
        }

        .report-note {
            margin: 15px 0;
            padding: 12px;
            background: #f5f5f5;
            border-radius: 5px;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>Attendance Summary</h1>

    <p>
        <a href="dashboard.php">Dashboard</a> |
        <a href="attendance_report.php">Attendance Report</a> |
        <a href="manage_shifts.php">Manage Shifts</a> |
        <a href="assign_shifts.php">Assign Shifts</a>
    </p>

    <form method="GET" class="filter-form">
        <div>
            <label for="start_date">Start date</label>
            <input
                type="date"
                id="start_date"
                name="start_date"
                value="<?= summaryH($startDate) ?>"
                required
            >
        </div>

        <div>
            <label for="end_date">End date</label>
            <input
                type="date"
                id="end_date"
                name="end_date"
                value="<?= summaryH($endDate) ?>"
                required
            >
        </div>

        <div>
            <label for="employee_id">Employee</label>
            <select id="employee_id" name="employee_id">
                <option value="">All employees</option>

                <?php foreach ($employees as $employee): ?>
                    <option
                        value="<?= (int) $employee["id"] ?>"
                        <?= (string) $employeeFilter === (string) $employee["id"]
                            ? "selected"
                            : "" ?>
                    >
                        <?= summaryH(
                            $employee["employee_number"] . " - "
                            . $employee["first_name"] . " "
                            . $employee["last_name"]
                        ) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit">Generate Summary</button>
    </form>

    <?php if ($error !== ""): ?>
        <p class="error"><?= summaryH($error) ?></p>
    <?php else: ?>

        <div class="summary-grid">
            <div class="summary-card">
                <h3>Sessions</h3>
                <p><?= $totals["sessions"] ?></p>
            </div>

            <div class="summary-card">
                <h3>Complete Sessions</h3>
                <p><?= $totals["complete"] ?></p>
            </div>

            <div class="summary-card">
                <h3>Incomplete Sessions</h3>
                <p><?= $totals["incomplete"] ?></p>
            </div>

            <div class="summary-card">
                <h3>Late Sessions</h3>
                <p><?= $totals["late"] ?></p>
            </div>

            <div class="summary-card">
                <h3>No Shift Assigned</h3>
                <p><?= $totals["no_shift"] ?></p>
            </div>

            <div class="summary-card">
                <h3>Unmatched Clock-outs</h3>
                <p><?= $totals["unmatched_out"] ?></p>
            </div>

            <div class="summary-card">
                <h3>Recorded Work Duration</h3>
                <p><?= summaryH(summaryDuration($totals["seconds"])) ?></p>
            </div>
        </div>

        <div class="report-note">
            <strong>Overnight shifts:</strong>
            Clock-in and clock-out events are paired chronologically.
            A shift that starts before midnight and ends the following
            morning is attributed to the date of its clock-in.

            <br><br>

            <strong>Important:</strong>
            Recorded duration is the time between clock-in and clock-out;
            it does not automatically subtract breaks. Sessions without
            a valid pair require review.
        </div>

        <h2>Shift Attendance Details</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Shift Date</th>
                        <th>Employee Number</th>
                        <th>Employee Name</th>
                        <th>Assigned Shift</th>
                        <th>Expected Start</th>
                        <th>Grace (min)</th>
                        <th>Clock In</th>
                        <th>Clock Out</th>
                        <th>Duration</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="10">
                                No attendance sessions found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= summaryH($row["date"]) ?></td>
                                <td><?= summaryH($row["employee_number"]) ?></td>
                                <td><?= summaryH($row["employee_name"]) ?></td>
                                <td><?= summaryH($row["shift_name"]) ?></td>
                                <td><?= summaryH($row["expected_start"]) ?></td>
                                <td>
                                    <?= $row["grace_minutes"] === null
                                        ? "—"
                                        : (int) $row["grace_minutes"] ?>
                                </td>
                                <td><?= summaryH(summaryClock($row["clock_in"])) ?></td>
                                <td><?= summaryH(summaryClock($row["clock_out"])) ?></td>
                                <td>
                                    <?= $row["complete"]
                                        ? summaryH(summaryDuration($row["duration"]))
                                        : "—" ?>
                                </td>
                                <td><?= summaryH($row["status"]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p>
            Attendance dates with no events are not automatically treated
            as absences. Absence reporting should be based on assigned
            shifts, workdays and approved leave.
        </p>

    <?php endif; ?>
</div>

</body>
</html>
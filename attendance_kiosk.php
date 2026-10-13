
<?php
require_once __DIR__ . "/includes/auth.php";
requireRole(["admin", "hr", "supervisor"]);

require_once __DIR__ . "/config/database.php";

date_default_timezone_set("Africa/Nairobi");

$message = "";
$messageType = "";
$employeeNumber = "";

$_SESSION["attendance_csrf"] ??= bin2hex(random_bytes(32));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $employeeNumber = trim($_POST["employee_number"] ?? "");
    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["attendance_csrf"], $token)
    ) {
        $message = "Invalid form token. Refresh the page and try again.";
        $messageType = "error";
    } elseif ($employeeNumber === "") {
        $message = "Enter the employee number.";
        $messageType = "error";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT id, employee_number, first_name, last_name
                 FROM employees
                 WHERE employee_number = :employee_number
                   AND employment_status = 'active'
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->execute([
                "employee_number" => $employeeNumber
            ]);

            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$employee) {
                $pdo->rollBack();
                $message = "Employee not found or not active.";
                $messageType = "error";
            } else {
                $today = date("Y-m-d");

                $stmt = $pdo->prepare(
                    "SELECT event_type
                     FROM attendance_events
                     WHERE employee_id = :employee_id
                       AND recorded_at >= :day_start
                       AND recorded_at < :day_end
                     ORDER BY recorded_at DESC, id DESC
                     LIMIT 1"
                );

                $stmt->execute([
                    "employee_id" => $employee["id"],
                    "day_start" => $today . " 00:00:00",
                    "day_end" => date(
                        "Y-m-d 00:00:00",
                        strtotime($today . " +1 day")
                    )
                ]);

                $lastEvent = $stmt->fetch(PDO::FETCH_ASSOC);

                // Alternate between clock-in and clock-out.
                $eventType = (
                    $lastEvent &&
                    $lastEvent["event_type"] === "clock_in"
                ) ? "clock_out" : "clock_in";

                $recordedAt = date("Y-m-d H:i:s");

                $stmt = $pdo->prepare(
                    "INSERT INTO attendance_events
                        (employee_id, event_type, recorded_at,
                         verification_method, event_status)
                     VALUES
                        (:employee_id, :event_type, :recorded_at,
                         'simulated', 'accepted')"
                );

                $stmt->execute([
                    "employee_id" => $employee["id"],
                    "event_type" => $eventType,
                    "recorded_at" => $recordedAt
                ]);

                $pdo->commit();

                $fullName = $employee["first_name"] . " " .
                            $employee["last_name"];

                $action = $eventType === "clock_in"
                    ? "Clock-in"
                    : "Clock-out";

                $message = $action . " recorded for " .
                    $fullName . " at " .
                    date("H:i:s", strtotime($recordedAt)) .
                    ". Verification: SIMULATED.";

                $messageType = "success";
                $employeeNumber = "";
                $_SESSION["attendance_csrf"] =
                    bin2hex(random_bytes(32));
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Attendance error: " . $e->getMessage());

            $message = "Unable to record attendance. Please try again.";
            $messageType = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Terminal</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="container">
    <h1>Attendance Terminal</h1>

    <p>
        Signed in as
        <strong><?= htmlspecialchars($_SESSION["user_name"] ?? "User") ?></strong>
    </p>

    <p>
        <a href="dashboard.php">Dashboard</a> |
        <a href="attendance_report.php">Attendance Report</a>
    </p>

    <p>
        <strong>Test mode:</strong>
        This terminal records simulated attendance. It does not verify
        fingerprints or prove an employee's identity.
    </p>

    <?php if ($message !== ""): ?>
        <p role="status">
            <?= htmlspecialchars($message) ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="attendance_kiosk.php">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION["attendance_csrf"]) ?>"
        >

        <label for="employee_number">Employee Number</label>

        <input
            type="text"
            id="employee_number"
            name="employee_number"
            value="<?= htmlspecialchars($employeeNumber) ?>"
            maxlength="30"
            required
            autocomplete="off"
            placeholder="e.g. EMP001"
        >

        <button type="submit">Record Attendance</button>
    </form>

    <p>
        The system automatically selects clock-in or clock-out based on
        the employee's latest event for today.
    </p>
</div>

</body>
</html>

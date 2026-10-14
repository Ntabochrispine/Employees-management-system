
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireLogin();

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function attendancePageH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$message = "";
$messageType = "";

$userId = (int) $_SESSION["user_id"];

/*
 * Find the active employee linked to the logged-in user.
 */
$stmt = $pdo->prepare("
    SELECT
        e.id,
        e.employee_number,
        e.first_name,
        e.last_name
    FROM users u
    INNER JOIN employees e ON e.id = u.employee_id
    WHERE u.id = :user_id
      AND u.status = 'active'
      AND e.employment_status = 'active'
    LIMIT 1
");

$stmt->execute([":user_id" => $userId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    http_response_code(403);
    exit(
        "No active employee profile is linked to your account. "
        . "Please contact the administrator."
    );
}

$employeeId = (int) $employee["id"];

/*
 * Process clock-in or clock-out.
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedToken = $_POST["csrf_token"] ?? "";
    $action = $_POST["action"] ?? "";

    if (
        !is_string($submittedToken)
        || !hash_equals($_SESSION["csrf_token"], $submittedToken)
    ) {
        $message = "Your session token is invalid. Refresh the page and try again.";
        $messageType = "error";
    } elseif (!in_array($action, ["clock_in", "clock_out"], true)) {
        $message = "Invalid attendance action.";
        $messageType = "error";
    } else {
        try {
            $pdo->beginTransaction();

            /*
             * Lock the employee row to help prevent two simultaneous
             * attendance requests for the same employee.
             */
            $lockStmt = $pdo->prepare("
                SELECT id
                FROM employees
                WHERE id = :employee_id
                  AND employment_status = 'active'
                FOR UPDATE
            ");

            $lockStmt->execute([":employee_id" => $employeeId]);

            if (!$lockStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException(
                    "Your employee profile is no longer active."
                );
            }

            /*
             * IMPORTANT:
             * Check the latest accepted event across all dates,
             * not just today's events.
             *
             * This allows a clock-in on one date to be closed by
             * a clock-out on the following date.
             */
            $lastStmt = $pdo->prepare("
                SELECT id, event_type, recorded_at
                FROM attendance_events
                WHERE employee_id = :employee_id
                  AND event_status = 'accepted'
                ORDER BY recorded_at DESC, id DESC
                LIMIT 1
                FOR UPDATE
            ");

            $lastStmt->execute([":employee_id" => $employeeId]);
            $lastEvent = $lastStmt->fetch(PDO::FETCH_ASSOC);

            if (
                $action === "clock_in"
                && $lastEvent
                && $lastEvent["event_type"] === "clock_in"
            ) {
                throw new RuntimeException(
                    "You are already clocked in. Please clock out before starting another attendance session."
                );
            }

            if (
                $action === "clock_out"
                && (
                    !$lastEvent
                    || $lastEvent["event_type"] !== "clock_in"
                )
            ) {
                throw new RuntimeException(
                    "You do not have an open clock-in. Please clock in first."
                );
            }

            /*
             * Record the current date and time using the server's
             * configured timezone.
             */
            $recordedAt = date("Y-m-d H:i:s");

            $insertStmt = $pdo->prepare("
                INSERT INTO attendance_events (
                    employee_id,
                    event_type,
                    recorded_at,
                    verification_method,
                    event_status
                ) VALUES (
                    :employee_id,
                    :event_type,
                    :recorded_at,
                    'simulated',
                    'accepted'
                )
            ");

            $insertStmt->execute([
                ":employee_id" => $employeeId,
                ":event_type" => $action,
                ":recorded_at" => $recordedAt
            ]);

            $pdo->commit();

            $message = $action === "clock_in"
                ? "Clock-in recorded successfully at " . $recordedAt . "."
                : "Clock-out recorded successfully at " . $recordedAt . ".";

            $messageType = "success";

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $message = $e->getMessage();
            } else {
                error_log("Attendance error: " . $e->getMessage());
                $message = "Unable to record attendance. Please try again or contact the administrator.";
            }

            $messageType = "error";
        }
    }
}

/*
 * Get the latest accepted event again to determine the current state.
 */
$stateStmt = $pdo->prepare("
    SELECT event_type, recorded_at
    FROM attendance_events
    WHERE employee_id = :employee_id
      AND event_status = 'accepted'
    ORDER BY recorded_at DESC, id DESC
    LIMIT 1
");

$stateStmt->execute([":employee_id" => $employeeId]);
$lastEvent = $stateStmt->fetch(PDO::FETCH_ASSOC);

$isClockedIn = (
    $lastEvent
    && $lastEvent["event_type"] === "clock_in"
);

/*
 * Show the latest attendance records.
 */
$historyStmt = $pdo->prepare("
    SELECT event_type, recorded_at
    FROM attendance_events
    WHERE employee_id = :employee_id
    ORDER BY recorded_at DESC, id DESC
    LIMIT 10
");

$historyStmt->execute([":employee_id" => $employeeId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .attendance-panel {
            max-width: 800px;
            margin: 25px auto;
            padding: 24px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
        }

        .attendance-message {
            padding: 12px;
            margin: 15px 0;
            border-radius: 5px;
        }

        .attendance-message.success {
            background: #e8f7e9;
            color: #185c25;
        }

        .attendance-message.error {
            background: #fdeaea;
            color: #8a1f1f;
        }

        .attendance-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 20px 0;
        }

        .attendance-actions button {
            padding: 12px 20px;
            cursor: pointer;
        }

        .attendance-table-wrapper {
            overflow-x: auto;
        }

        .attendance-table-wrapper table {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table-wrapper th,
        .attendance-table-wrapper td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
    </style>
</head>

<body>
<div class="container">
    <div class="attendance-panel">
        <h1>My Attendance</h1>

        <p>
            Welcome,
            <strong>
                <?= attendancePageH(
                    $employee["first_name"] . " " . $employee["last_name"]
                ) ?>
            </strong>
        </p>

        <p>
            Employee number:
            <strong><?= attendancePageH($employee["employee_number"]) ?></strong>
        </p>

        <?php if ($message !== ""): ?>
            <div class="attendance-message <?= attendancePageH($messageType) ?>">
                <?= attendancePageH($message) ?>
            </div>
        <?php endif; ?>

        <h2>Current Status</h2>

        <?php if ($isClockedIn): ?>
            <p>
                Status: <strong>Clocked in</strong>
            </p>
            <p>
                Clock-in time:
                <?= attendancePageH($lastEvent["recorded_at"]) ?>
            </p>
        <?php else: ?>
            <p>Status: <strong>Not clocked in</strong></p>
        <?php endif; ?>

        <div class="attendance-actions">
            <form method="POST">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= attendancePageH($_SESSION["csrf_token"]) ?>"
                >

                <input type="hidden" name="action" value="clock_in">

                <button type="submit" <?= $isClockedIn ? "disabled" : "" ?>>
                    Clock In
                </button>
            </form>

            <form method="POST">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= attendancePageH($_SESSION["csrf_token"]) ?>"
                >

                <input type="hidden" name="action" value="clock_out">

                <button type="submit" <?= !$isClockedIn ? "disabled" : "" ?>>
                    Clock Out
                </button>
            </form>
        </div>

        <p>
            You can clock out the following day if your shift continues
            past midnight.
        </p>

        <h2>Recent Attendance Events</h2>

        <div class="attendance-table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Date and Time</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="2">No attendance records yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $event): ?>
                            <tr>
                                <td>
                                    <?= attendancePageH(
                                        $event["event_type"] === "clock_in"
                                            ? "Clock In"
                                            : "Clock Out"
                                    ) ?>
                                </td>
                                <td>
                                    <?= attendancePageH($event["recorded_at"]) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p><a href="dashboard.php">Back to Dashboard</a></p>
    </div>
</div>
</body>
</html>

<?php

require_once __DIR__ . "/includes/auth.php";
requireRole(["employee"]);

require_once __DIR__ . "/config/database.php";

// Generate CSRF token.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$userId = (int) $_SESSION["user_id"];
$error = "";

// Get the active employee linked to the logged-in account.
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

$stmt->execute(["user_id" => $userId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    http_response_code(403);
    exit("Your account is not linked to an active employee. Contact HR.");
}

$employeeId = (int) $employee["id"];

// Process clock-in or clock-out submissions.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";
    $action = $_POST["action"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {
        $error = "Invalid form token. Refresh the page and try again.";

    } elseif (!in_array($action, ["clock_in", "clock_out"], true)) {
        $error = "Invalid attendance action.";

    } else {

        try {
            $pdo->beginTransaction();

            // Lock the employee row to reduce the risk of
            // simultaneous duplicate attendance submissions.
            $lockEmployee = $pdo->prepare("
                SELECT id
                FROM employees
                WHERE id = :employee_id
                  AND employment_status = 'active'
                FOR UPDATE
            ");

            $lockEmployee->execute([
                "employee_id" => $employeeId
            ]);

            if (!$lockEmployee->fetch()) {
                throw new RuntimeException(
                    "Your employee record is no longer active."
                );
            }

            // Find the most recent accepted attendance event.
            $latest = $pdo->prepare("
                SELECT id, event_type, recorded_at
                FROM attendance_events
                WHERE employee_id = :employee_id
                  AND event_status = 'accepted'
                ORDER BY recorded_at DESC, id DESC
                LIMIT 1
            ");

            $latest->execute([
                "employee_id" => $employeeId
            ]);

            $lastEvent = $latest->fetch(PDO::FETCH_ASSOC);

            // Determine whether the requested action is valid.
            if (
                $action === "clock_in" &&
                $lastEvent &&
                $lastEvent["event_type"] === "clock_in"
            ) {
                throw new RuntimeException(
                    "You are already clocked in. Please clock out first."
                );
            }

            if (
                $action === "clock_out" &&
                (
                    !$lastEvent ||
                    $lastEvent["event_type"] !== "clock_in"
                )
            ) {
                throw new RuntimeException(
                    "You must clock in before you can clock out."
                );
            }

            // Record the attendance event using the database server time.
            $insert = $pdo->prepare("
                INSERT INTO attendance_events (
                    employee_id,
                    event_type,
                    recorded_at,
                    verification_method,
                    event_status
                ) VALUES (
                    :employee_id,
                    :event_type,
                    NOW(),
                    'simulated',
                    'accepted'
                )
            ");

            $insert->execute([
                "employee_id" => $employeeId,
                "event_type" => $action
            ]);

            $pdo->commit();

            // Redirect to prevent accidental resubmission.
            header(
                "Location: attendance.php?message=" .
                urlencode($action)
            );
            exit;

        } catch (RuntimeException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());

            $error = "Attendance could not be recorded. Please try again.";
        }
    }
}

// Read the latest accepted event to determine the current state.
$latest = $pdo->prepare("
    SELECT event_type, recorded_at
    FROM attendance_events
    WHERE employee_id = :employee_id
      AND event_status = 'accepted'
    ORDER BY recorded_at DESC, id DESC
    LIMIT 1
");

$latest->execute([
    "employee_id" => $employeeId
]);

$lastEvent = $latest->fetch(PDO::FETCH_ASSOC);

$isClockedIn = (
    $lastEvent &&
    $lastEvent["event_type"] === "clock_in"
);

// Get the employee's recent attendance events.
$history = $pdo->prepare("
    SELECT event_type, recorded_at, event_status
    FROM attendance_events
    WHERE employee_id = :employee_id
    ORDER BY recorded_at DESC, id DESC
    LIMIT 20
");

$history->execute([
    "employee_id" => $employeeId
]);

$attendanceRecords = $history->fetchAll(PDO::FETCH_ASSOC);

// Display a success message after redirect.
$message = $_GET["message"] ?? "";
$success = "";

if ($message === "clock_in") {
    $success = "You have successfully clocked in.";
} elseif ($message === "clock_out") {
    $success = "You have successfully clocked out.";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Attendance</title>

    <link rel="stylesheet" href="assets/style.css">
</head>

<body>

<main class="container">

    <h1>My Attendance</h1>

    <p>
        Welcome,
        <strong>
            <?= h($employee["first_name"] . " " . $employee["last_name"]) ?>
        </strong>
    </p>

    <p>
        Employee Number:
        <strong><?= h($employee["employee_number"]) ?></strong>
    </p>

    <?php if ($error !== ""): ?>
        <p class="error">
            <?= h($error) ?>
        </p>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <p class="success">
            <?= h($success) ?>
        </p>
    <?php endif; ?>

    <section class="card">

        <h2>Attendance Status</h2>

        <?php if ($isClockedIn): ?>

            <p>
                Current status:
                <strong>Clocked In</strong>
            </p>

            <p>
                Clock-in time:
                <?= h($lastEvent["recorded_at"]) ?>
            </p>

            <form method="POST" action="attendance.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION["csrf_token"]) ?>"
                >

                <button
                    type="submit"
                    name="action"
                    value="clock_out"
                >
                    Clock Out
                </button>

            </form>

        <?php else: ?>

            <p>
                Current status:
                <strong>Clocked Out</strong>
            </p>

            <form method="POST" action="attendance.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION["csrf_token"]) ?>"
                >

                <button
                    type="submit"
                    name="action"
                    value="clock_in"
                >
                    Clock In
                </button>

            </form>

        <?php endif; ?>

    </section>

    <section>

        <h2>Recent Attendance History</h2>

        <?php if (empty($attendanceRecords)): ?>

            <p>You have no attendance records yet.</p>

        <?php else: ?>

            <table>

                <thead>
                    <tr>
                        <th>Date and Time</th>
                        <th>Event</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($attendanceRecords as $record): ?>

                        <tr>
                            <td>
                                <?= h($record["recorded_at"]) ?>
                            </td>

                            <td>
                                <?= h(
                                    $record["event_type"] === "clock_in"
                                        ? "Clock In"
                                        : "Clock Out"
                                ) ?>
                            </td>

                            <td>
                                <?= h(ucfirst($record["event_status"])) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </section>

    <p>
        <a href="dashboard.php">Back to Dashboard</a>
    </p>

</main>

</body>
</html>
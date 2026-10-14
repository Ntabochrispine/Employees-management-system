
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireRole(["hr", "admin"]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$eventId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$eventId || $eventId < 1) {
    http_response_code(400);
    exit("Invalid attendance event ID.");
}

$error = "";
$success = "";

// Process a correction.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedToken = $_POST["csrf_token"] ?? "";

    if (
        !is_string($postedToken) ||
        !hash_equals($_SESSION["csrf_token"], $postedToken)
    ) {
        $error = "Invalid security token. Refresh the page and try again.";
    } else {
        $newEventType = $_POST["event_type"] ?? "";
        $newRecordedAt = trim($_POST["recorded_at"] ?? "");
        $reason = trim($_POST["reason"] ?? "");

        if (!in_array($newEventType, ["clock_in", "clock_out"], true)) {
            $error = "Select a valid attendance event type.";
        } elseif ($newRecordedAt === "") {
            $error = "Enter the corrected date and time.";
        } elseif (strlen($reason) < 5 || strlen($reason) > 1000) {
            $error = "The correction reason must contain 5 to 1000 characters.";
        } else {
            // Validate the datetime-local value strictly.
            $dateTime = DateTime::createFromFormat(
                "!Y-m-d\TH:i",
                $newRecordedAt
            );

            $dateErrors = DateTime::getLastErrors();

            $validDateTime = $dateTime !== false
                && (
                    $dateErrors === false ||
                    (
                        $dateErrors["warning_count"] === 0 &&
                        $dateErrors["error_count"] === 0
                    )
                )
                && $dateTime->format("Y-m-d\TH:i") === $newRecordedAt;

            if (!$validDateTime) {
                $error = "Enter a valid date and time.";
            } else {
                $newRecordedAtSql = $dateTime->format("Y-m-d H:i:s");

                try {
                    $pdo->beginTransaction();

                    // Lock the event to prevent simultaneous corrections.
                    $stmt = $pdo->prepare("
                        SELECT
                            id,
                            employee_id,
                            event_type,
                            recorded_at,
                            event_status
                        FROM attendance_events
                        WHERE id = :id
                        FOR UPDATE
                    ");

                    $stmt->execute(["id" => $eventId]);
                    $oldEvent = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$oldEvent) {
                        throw new RuntimeException(
                            "The attendance event could not be found."
                        );
                    }

                    // Do not create an unnecessary audit entry.
                    if (
                        $oldEvent["event_type"] === $newEventType &&
                        $oldEvent["recorded_at"] === $newRecordedAtSql
                    ) {
                        throw new RuntimeException(
                            "No changes detected. Change the event type or time."
                        );
                    }

                    // Save the original and corrected values in the audit log.
                    $audit = $pdo->prepare("
                        INSERT INTO attendance_audit_logs (
                            attendance_event_id,
                            employee_id,
                            changed_by,
                            old_event_type,
                            new_event_type,
                            old_recorded_at,
                            new_recorded_at,
                            old_event_status,
                            new_event_status,
                            reason
                        ) VALUES (
                            :event_id,
                            :employee_id,
                            :changed_by,
                            :old_type,
                            :new_type,
                            :old_time,
                            :new_time,
                            :old_status,
                            :new_status,
                            :reason
                        )
                    ");

                    $audit->execute([
                        "event_id"    => $oldEvent["id"],
                        "employee_id" => $oldEvent["employee_id"],
                        "changed_by"  => $_SESSION["user_id"],
                        "old_type"    => $oldEvent["event_type"],
                        "new_type"    => $newEventType,
                        "old_time"    => $oldEvent["recorded_at"],
                        "new_time"    => $newRecordedAtSql,
                        "old_status"  => $oldEvent["event_status"],
                        "new_status"  => $oldEvent["event_status"],
                        "reason"      => $reason
                    ]);

                    // Preserve the event status so accepted events remain
                    // included in the existing attendance reports.
                    $update = $pdo->prepare("
                        UPDATE attendance_events
                        SET event_type = :event_type,
                            recorded_at = :recorded_at
                        WHERE id = :id
                    ");

                    $update->execute([
                        "event_type"  => $newEventType,
                        "recorded_at" => $newRecordedAtSql,
                        "id"          => $eventId
                    ]);

                    $pdo->commit();

                    $success = "Attendance event corrected successfully. "
                        . "The original values and reason have been recorded.";

                    // Refresh the security token after a successful change.
                    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if ($e instanceof RuntimeException) {
                        $error = $e->getMessage();
                    } else {
                        error_log("Attendance correction error: " . $e->getMessage());
                        $error = "The correction could not be completed. "
                            . "Check the server error log if the problem continues.";
                    }
                }
            }
        }
    }
}

// Load the event and employee details for display.
$stmt = $pdo->prepare("
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
    WHERE a.id = :id
    LIMIT 1
");

$stmt->execute(["id" => $eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    exit("Attendance event not found.");
}

$currentDateTime = date(
    "Y-m-d\TH:i",
    strtotime($event["recorded_at"])
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Correct Attendance</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .correction-container {
            max-width: 760px;
            margin: 30px auto;
            padding: 24px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .correction-container label {
            display: block;
            margin: 15px 0 6px;
            font-weight: 600;
        }

        .correction-container input,
        .correction-container select,
        .correction-container textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        .correction-container textarea {
            min-height: 110px;
            resize: vertical;
        }

        .message {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 5px;
        }

        .error {
            background: #ffe7e7;
            color: #8a1c1c;
        }

        .success {
            background: #e5f7e9;
            color: #17652a;
        }

        .event-details {
            padding: 15px;
            background: #f5f7fa;
            border-radius: 5px;
        }

        .event-details p {
            margin: 8px 0;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>

<div class="correction-container">
    <h1>Correct Attendance Event</h1>

    <p>
        Employee:
        <strong>
            <?= h($event["first_name"] . " " . $event["last_name"]) ?>
        </strong>
    </p>

    <div class="event-details">
        <p>
            <strong>Employee Number:</strong>
            <?= h($event["employee_number"]) ?>
        </p>

        <p>
            <strong>Event ID:</strong>
            <?= h($event["id"]) ?>
        </p>

        <p>
            <strong>Current Event:</strong>
            <?= h(ucwords(str_replace("_", " ", $event["event_type"]))) ?>
        </p>

        <p>
            <strong>Current Recorded Time:</strong>
            <?= h($event["recorded_at"]) ?>
        </p>

        <p>
            <strong>Event Status:</strong>
            <?= h(ucfirst($event["event_status"])) ?>
        </p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="message error">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?= h($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST"
          action="correct_attendance.php?id=<?= (int) $event["id"] ?>">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($_SESSION["csrf_token"]) ?>"
        >

        <label for="event_type">Correct Event Type</label>
        <select name="event_type" id="event_type" required>
            <option value="clock_in"
                <?= $event["event_type"] === "clock_in" ? "selected" : "" ?>>
                Clock In
            </option>

            <option value="clock_out"
                <?= $event["event_type"] === "clock_out" ? "selected" : "" ?>>
                Clock Out
            </option>
        </select>

        <label for="recorded_at">Correct Date and Time</label>
        <input
            type="datetime-local"
            name="recorded_at"
            id="recorded_at"
            value="<?= h($currentDateTime) ?>"
            required
        >

        <label for="reason">Reason for Correction</label>
        <textarea
            name="reason"
            id="reason"
            minlength="5"
            maxlength="1000"
            required
            placeholder="Explain why this attendance record needs correction."
        ><?= h($_POST["reason"] ?? "") ?></textarea>

        <div class="form-actions">
            <button type="submit">Save Correction</button>

            <a href="attendance_report.php">
                <button type="button">Back to Attendance Report</button>
            </a>
        </div>
    </form>
</div>

</body>
</html>

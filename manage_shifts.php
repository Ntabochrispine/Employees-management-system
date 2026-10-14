
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireRole(["hr", "admin"]);

function shiftH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";
$success = "";

$editShift = null;
$editId = filter_input(INPUT_GET, "edit", FILTER_VALIDATE_INT);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid security token. Refresh the page and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            if ($action === "save") {
                $id = filter_var(
                    $_POST["id"] ?? "",
                    FILTER_VALIDATE_INT
                );

                $name = trim($_POST["shift_name"] ?? "");
                $startTime = trim($_POST["start_time"] ?? "");
                $endTime = trim($_POST["end_time"] ?? "");
                $grace = filter_var(
                    $_POST["grace_period_minutes"] ?? "",
                    FILTER_VALIDATE_INT
                );

                if ($name === "" || strlen($name) > 100) {
                    throw new RuntimeException(
                        "Enter a shift name of up to 100 characters."
                    );
                }

                $validTime = function ($time): bool {
                    return is_string($time) &&
                        preg_match(
                            '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
                            $time
                        ) === 1;
                };

                if (!$validTime($startTime) || !$validTime($endTime)) {
                    throw new RuntimeException(
                        "Enter valid start and end times."
                    );
                }

                if ($startTime === $endTime) {
                    throw new RuntimeException(
                        "Start and end times cannot be identical."
                    );
                }

                if ($grace === false || $grace < 0 || $grace > 240) {
                    throw new RuntimeException(
                        "The grace period must be between 0 and 240 minutes."
                    );
                }

                $startTime = strlen($startTime) === 5
                    ? $startTime . ":00"
                    : $startTime;

                $endTime = strlen($endTime) === 5
                    ? $endTime . ":00"
                    : $endTime;

                if ($id !== false && $id !== null && $id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE work_shifts
                        SET shift_name = :name,
                            start_time = :start_time,
                            end_time = :end_time,
                            grace_period_minutes = :grace
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        "name" => $name,
                        "start_time" => $startTime,
                        "end_time" => $endTime,
                        "grace" => $grace,
                        "id" => $id
                    ]);

                    $success = "Shift updated successfully.";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO work_shifts (
                            shift_name,
                            start_time,
                            end_time,
                            grace_period_minutes
                        ) VALUES (
                            :name,
                            :start_time,
                            :end_time,
                            :grace
                        )
                    ");

                    $stmt->execute([
                        "name" => $name,
                        "start_time" => $startTime,
                        "end_time" => $endTime,
                        "grace" => $grace
                    ]);

                    $success = "Shift created successfully.";
                }

                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
            } elseif ($action === "toggle") {
                $id = filter_var(
                    $_POST["id"] ?? "",
                    FILTER_VALIDATE_INT
                );

                if ($id === false || $id < 1) {
                    throw new RuntimeException("Invalid shift ID.");
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT id, is_active
                    FROM work_shifts
                    WHERE id = :id
                    FOR UPDATE
                ");
                $stmt->execute(["id" => $id]);
                $shift = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$shift) {
                    throw new RuntimeException("Shift not found.");
                }

                $newStatus = (int) $shift["is_active"] === 1 ? 0 : 1;

                // Prevent deactivation if an employee currently has
                // an open-ended assignment to this shift.
                if ($newStatus === 0) {
                    $check = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM employee_shift_assignments
                        WHERE shift_id = :id
                          AND effective_to IS NULL
                    ");
                    $check->execute(["id" => $id]);

                    if ((int) $check->fetchColumn() > 0) {
                        throw new RuntimeException(
                            "This shift has current employee assignments. "
                            . "Reassign those employees before deactivating it."
                        );
                    }
                }

                $update = $pdo->prepare("
                    UPDATE work_shifts
                    SET is_active = :status
                    WHERE id = :id
                ");
                $update->execute([
                    "status" => $newStatus,
                    "id" => $id
                ]);

                $pdo->commit();

                $success = $newStatus === 1
                    ? "Shift activated successfully."
                    : "Shift deactivated successfully.";

                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
            } else {
                throw new RuntimeException("Invalid action.");
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } elseif ($e instanceof PDOException &&
                $e->getCode() === "23000") {
                $error = "A shift with this name already exists, "
                    . "or the change conflicts with related records.";
            } else {
                error_log("Shift management error: " . $e->getMessage());
                $error = "The operation failed. Check the server error log.";
            }
        }
    }
}

// Load a shift for editing.
if ($editId && $editId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM work_shifts
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(["id" => $editId]);
    $editShift = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editShift) {
        $error = "The selected shift could not be found.";
    }
}

$stmt = $pdo->query("
    SELECT
        s.*,
        (
            SELECT COUNT(*)
            FROM employee_shift_assignments a
            WHERE a.shift_id = s.id
              AND a.effective_to IS NULL
        ) AS current_assignments
    FROM work_shifts s
    ORDER BY s.is_active DESC, s.shift_name
");
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formName = $_POST["shift_name"] ?? ($editShift["shift_name"] ?? "");
$formStart = $_POST["start_time"] ?? (
    isset($editShift["start_time"])
        ? substr($editShift["start_time"], 0, 5)
        : "08:00"
);
$formEnd = $_POST["end_time"] ?? (
    isset($editShift["end_time"])
        ? substr($editShift["end_time"], 0, 5)
        : "17:00"
);
$formGrace = $_POST["grace_period_minutes"] ??
    ($editShift["grace_period_minutes"] ?? 15);
$formId = $_POST["id"] ?? ($editShift["id"] ?? "");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Work Shifts</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .page-container {
            max-width: 1100px;
            margin: 25px auto;
            padding: 20px;
        }

        .form-panel {
            max-width: 600px;
            margin: 20px 0;
            padding: 20px;
            background: #f5f7fa;
            border-radius: 8px;
        }

        .form-panel label {
            display: block;
            margin: 14px 0 6px;
            font-weight: bold;
        }

        .form-panel input {
            box-sizing: border-box;
            width: 100%;
            padding: 10px;
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
        }

        th {
            background: #f0f2f5;
        }

        .message {
            padding: 12px;
            margin: 15px 0;
            border-radius: 5px;
        }

        .error {
            color: #8a1c1c;
            background: #ffe7e7;
        }

        .success {
            color: #17652a;
            background: #e5f7e9;
        }

        .inline-form {
            display: inline;
        }
    </style>
</head>

<body>
<div class="page-container">

    <h1>Manage Work Shifts</h1>

    <p>
        <a href="dashboard.php">Dashboard</a> |
        <a href="attendance_summary.php">Attendance Summary</a> |
        <a href="attendance_corrections.php">Attendance Corrections</a>
    </p>

    <?php if ($error !== ""): ?>
        <div class="message error"><?= shiftH($error) ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="message success"><?= shiftH($success) ?></div>
    <?php endif; ?>

    <div class="form-panel">
        <h2><?= $editShift ? "Edit Shift" : "Add New Shift" ?></h2>

        <form method="POST" action="manage_shifts.php">
            <input type="hidden" name="csrf_token"
                   value="<?= shiftH($_SESSION["csrf_token"]) ?>">

            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= shiftH($formId) ?>">

            <label for="shift_name">Shift Name</label>
            <input
                type="text"
                id="shift_name"
                name="shift_name"
                maxlength="100"
                required
                value="<?= shiftH($formName) ?>"
                placeholder="e.g. Morning Shift"
            >

            <label for="start_time">Start Time</label>
            <input
                type="time"
                id="start_time"
                name="start_time"
                required
                value="<?= shiftH($formStart) ?>"
            >

            <label for="end_time">End Time</label>
            <input
                type="time"
                id="end_time"
                name="end_time"
                required
                value="<?= shiftH($formEnd) ?>"
            >

            <label for="grace_period_minutes">Grace Period (minutes)</label>
            <input
                type="number"
                id="grace_period_minutes"
                name="grace_period_minutes"
                min="0"
                max="240"
                required
                value="<?= shiftH($formGrace) ?>"
            >

            <p>
                For an overnight shift, such as 10:00 PM to 6:00 AM,
                enter 22:00 as the start and 06:00 as the end.
            </p>

            <button type="submit">
                <?= $editShift ? "Save Changes" : "Create Shift" ?>
            </button>

            <?php if ($editShift): ?>
                <a href="manage_shifts.php">Cancel Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <h2>Existing Shifts</h2>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Shift Name</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Grace Period</th>
                    <th>Current Assignments</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php if (empty($shifts)): ?>
                <tr>
                    <td colspan="8">No shifts have been created.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($shifts as $shift): ?>
                    <tr>
                        <td><?= (int) $shift["id"] ?></td>
                        <td><?= shiftH($shift["shift_name"]) ?></td>
                        <td><?= shiftH(substr($shift["start_time"], 0, 5)) ?></td>
                        <td><?= shiftH(substr($shift["end_time"], 0, 5)) ?></td>
                        <td>
                            <?= (int) $shift["grace_period_minutes"] ?> minutes
                        </td>
                        <td><?= (int) $shift["current_assignments"] ?></td>
                        <td>
                            <?= (int) $shift["is_active"] === 1
                                ? "Active"
                                : "Inactive" ?>
                        </td>
                        <td>
                            <a href="manage_shifts.php?edit=<?= (int) $shift["id"] ?>">
                                Edit
                            </a>

                            <form
                                method="POST"
                                action="manage_shifts.php"
                                class="inline-form"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= shiftH($_SESSION["csrf_token"]) ?>"
                                >

                                <input type="hidden" name="action" value="toggle">
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int) $shift["id"] ?>"
                                >

                                <button type="submit">
                                    <?= (int) $shift["is_active"] === 1
                                        ? "Deactivate"
                                        : "Activate" ?>
                                </button>
                            </form>
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

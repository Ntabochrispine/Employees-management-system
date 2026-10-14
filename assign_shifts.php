
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";

requireRole(["hr", "admin"]);

function assignH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function assignValidDate(string $value): bool
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

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {
        $error = "Invalid security token. Refresh the page and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            if ($action === "assign") {
                $employeeId = filter_var(
                    $_POST["employee_id"] ?? "",
                    FILTER_VALIDATE_INT
                );

                $shiftId = filter_var(
                    $_POST["shift_id"] ?? "",
                    FILTER_VALIDATE_INT
                );

                $effectiveFrom = trim($_POST["effective_from"] ?? "");

                if (
                    $employeeId === false || $employeeId < 1 ||
                    $shiftId === false || $shiftId < 1
                ) {
                    throw new RuntimeException(
                        "Select a valid employee and shift."
                    );
                }

                if (!assignValidDate($effectiveFrom)) {
                    throw new RuntimeException(
                        "Enter a valid assignment start date."
                    );
                }

                $pdo->beginTransaction();

                // Lock the employee record while checking assignments.
                $stmt = $pdo->prepare("
                    SELECT id, employment_status
                    FROM employees
                    WHERE id = :id
                    FOR UPDATE
                ");
                $stmt->execute(["id" => $employeeId]);
                $employee = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$employee || $employee["employment_status"] !== "active") {
                    throw new RuntimeException(
                        "Only active employees can be assigned to a shift."
                    );
                }

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM work_shifts
                    WHERE id = :id AND is_active = 1
                ");
                $stmt->execute(["id" => $shiftId]);

                if (!$stmt->fetch()) {
                    throw new RuntimeException(
                        "Select an active work shift."
                    );
                }

                // An assignment from its start date onward must not overlap
                // any existing assignment for that employee.
                $stmt = $pdo->prepare("
                    SELECT id, effective_from, effective_to
                    FROM employee_shift_assignments
                    WHERE employee_id = :employee_id
                      AND (
                          effective_to IS NULL
                          OR effective_to >= :effective_from
                      )
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    "employee_id" => $employeeId,
                    "effective_from" => $effectiveFrom
                ]);

                $overlap = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($overlap) {
                    throw new RuntimeException(
                        "This employee already has a shift assignment "
                        . "that overlaps the selected start date. End the "
                        . "existing assignment first, or choose a later date."
                    );
                }

                $stmt = $pdo->prepare("
                    INSERT INTO employee_shift_assignments (
                        employee_id,
                        shift_id,
                        effective_from,
                        effective_to,
                        assigned_by
                    ) VALUES (
                        :employee_id,
                        :shift_id,
                        :effective_from,
                        NULL,
                        :assigned_by
                    )
                ");

                $stmt->execute([
                    "employee_id" => $employeeId,
                    "shift_id" => $shiftId,
                    "effective_from" => $effectiveFrom,
                    "assigned_by" => $_SESSION["user_id"]
                ]);

                $pdo->commit();

                $success = "Employee shift assignment created successfully.";
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

            } elseif ($action === "end") {
                $assignmentId = filter_var(
                    $_POST["assignment_id"] ?? "",
                    FILTER_VALIDATE_INT
                );

                $effectiveTo = trim($_POST["effective_to"] ?? "");

                if ($assignmentId === false || $assignmentId < 1) {
                    throw new RuntimeException(
                        "Invalid assignment ID."
                    );
                }

                if (!assignValidDate($effectiveTo)) {
                    throw new RuntimeException(
                        "Enter a valid assignment end date."
                    );
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT id, employee_id, effective_from, effective_to
                    FROM employee_shift_assignments
                    WHERE id = :id
                    FOR UPDATE
                ");
                $stmt->execute(["id" => $assignmentId]);
                $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$assignment) {
                    throw new RuntimeException(
                        "The shift assignment was not found."
                    );
                }

                if ($assignment["effective_to"] !== null) {
                    throw new RuntimeException(
                        "This assignment has already ended."
                    );
                }

                if ($effectiveTo < $assignment["effective_from"]) {
                    throw new RuntimeException(
                        "The end date cannot be earlier than the start date."
                    );
                }

                // Prevent ending an assignment in a way that overlaps
                // another assignment for the same employee.
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM employee_shift_assignments
                    WHERE employee_id = :employee_id
                      AND id <> :assignment_id
                      AND effective_from <= :effective_to
                      AND (
                          effective_to IS NULL
                          OR effective_to >= :effective_from
                      )
                    LIMIT 1
                ");

                $stmt->execute([
                    "employee_id" => $assignment["employee_id"],
                    "assignment_id" => $assignmentId,
                    "effective_to" => $effectiveTo,
                    "effective_from" => $assignment["effective_from"]
                ]);

                if ($stmt->fetch()) {
                    throw new RuntimeException(
                        "Ending the assignment on this date would overlap "
                        . "another assignment. Choose a valid end date."
                    );
                }

                $stmt = $pdo->prepare("
                    UPDATE employee_shift_assignments
                    SET effective_to = :effective_to
                    WHERE id = :id AND effective_to IS NULL
                ");

                $stmt->execute([
                    "effective_to" => $effectiveTo,
                    "id" => $assignmentId
                ]);

                $pdo->commit();

                $success = "Shift assignment ended successfully.";
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

            } else {
                throw new RuntimeException("Invalid form action.");
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } elseif (
                $e instanceof PDOException &&
                $e->getCode() === "23000"
            ) {
                $error = "The assignment could not be saved because "
                    . "of a database constraint. Check the employee, "
                    . "shift, and existing assignments.";
            } else {
                error_log("Shift assignment error: " . $e->getMessage());
                $error = "The operation failed. Check the server error log.";
            }
        }
    }
}

// Load active employees.
$stmt = $pdo->query("
    SELECT id, employee_number, first_name, last_name
    FROM employees
    WHERE employment_status = 'active'
    ORDER BY first_name, last_name
");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load active shifts.
$stmt = $pdo->query("
    SELECT id, shift_name, start_time, end_time, grace_period_minutes
    FROM work_shifts
    WHERE is_active = 1
    ORDER BY shift_name
");
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load assignment history.
$stmt = $pdo->query("
    SELECT
        a.id AS assignment_id,
        a.employee_id,
        a.shift_id,
        a.effective_from,
        a.effective_to,
        e.employee_number,
        e.first_name,
        e.last_name,
        s.shift_name,
        s.start_time,
        s.end_time,
        u.name AS assigned_by_name
    FROM employee_shift_assignments a
    INNER JOIN employees e ON e.id = a.employee_id
    INNER JOIN work_shifts s ON s.id = a.shift_id
    INNER JOIN users u ON u.id = a.assigned_by
    ORDER BY
        (a.effective_to IS NULL) DESC,
        a.effective_from DESC,
        e.first_name,
        e.last_name
");
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Employee Shifts</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .page-container {
            max-width: 1250px;
            margin: 25px auto;
            padding: 20px;
        }

        .panel {
            max-width: 650px;
            padding: 20px;
            margin: 20px 0;
            background: #f5f7fa;
            border-radius: 8px;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: 600;
        }

        input, select {
            box-sizing: border-box;
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
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

        .table-wrapper {
            overflow-x: auto;
            margin: 20px 0;
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

        .end-form {
            min-width: 180px;
        }

        .end-form input {
            margin-bottom: 6px;
        }
    </style>
</head>

<body>
<div class="page-container">

    <h1>Employee Shift Assignments</h1>

    <p>
        <a href="dashboard.php">Dashboard</a> |
        <a href="manage_shifts.php">Manage Work Shifts</a> |
        <a href="attendance_summary.php">Attendance Summary</a>
    </p>

    <?php if ($error !== ""): ?>
        <div class="message error"><?= assignH($error) ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="message success"><?= assignH($success) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Assign a Shift</h2>

        <?php if (empty($employees)): ?>
            <p>There are no active employees available for assignment.</p>
        <?php elseif (empty($shifts)): ?>
            <p>There are no active shifts. Create or activate a shift first.</p>
        <?php else: ?>
            <form method="POST" action="assign_shifts.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= assignH($_SESSION["csrf_token"]) ?>"
                >

                <input type="hidden" name="action" value="assign">

                <label for="employee_id">Employee</label>
                <select name="employee_id" id="employee_id" required>
                    <option value="">Select an employee</option>

                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee["id"] ?>">
                            <?= assignH(
                                $employee["employee_number"] . " - " .
                                $employee["first_name"] . " " .
                                $employee["last_name"]
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="shift_id">Work Shift</label>
                <select name="shift_id" id="shift_id" required>
                    <option value="">Select a shift</option>

                    <?php foreach ($shifts as $shift): ?>
                        <option value="<?= (int) $shift["id"] ?>">
                            <?= assignH(
                                $shift["shift_name"] . " (" .
                                substr($shift["start_time"], 0, 5) . " - " .
                                substr($shift["end_time"], 0, 5) . ")"
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="effective_from">Assignment Start Date</label>
                <input
                    type="date"
                    name="effective_from"
                    id="effective_from"
                    value="<?= date("Y-m-d") ?>"
                    required
                >

                <p>
                    This assignment will remain active until an end date
                    is recorded.
                </p>

                <button type="submit">Assign Shift</button>
            </form>
        <?php endif; ?>
    </div>

    <h2>Assignment History</h2>

    <p>
        Assignments with no end date are open-ended. To change an employee's
        shift, end the existing assignment and create the new one starting
        the following day.
    </p>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Employee Number</th>
                    <th>Employee Name</th>
                    <th>Shift</th>
                    <th>Shift Hours</th>
                    <th>Effective From</th>
                    <th>Effective To</th>
                    <th>Assigned By</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php if (empty($assignments)): ?>
                <tr>
                    <td colspan="8">No shift assignments have been created.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($assignments as $assignment): ?>
                    <tr>
                        <td><?= assignH($assignment["employee_number"]) ?></td>

                        <td>
                            <?= assignH(
                                $assignment["first_name"] . " " .
                                $assignment["last_name"]
                            ) ?>
                        </td>

                        <td><?= assignH($assignment["shift_name"]) ?></td>

                        <td>
                            <?= assignH(substr($assignment["start_time"], 0, 5)) ?>
                            -
                            <?= assignH(substr($assignment["end_time"], 0, 5)) ?>
                        </td>

                        <td><?= assignH($assignment["effective_from"]) ?></td>

                        <td>
                            <?= $assignment["effective_to"] === null
                                ? "Open-ended"
                                : assignH($assignment["effective_to"]) ?>
                        </td>

                        <td><?= assignH($assignment["assigned_by_name"]) ?></td>

                        <td>
                            <?php if ($assignment["effective_to"] === null): ?>
                                <form
                                    method="POST"
                                    action="assign_shifts.php"
                                    class="end-form"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= assignH($_SESSION["csrf_token"]) ?>"
                                    >

                                    <input type="hidden" name="action" value="end">

                                    <input
                                        type="hidden"
                                        name="assignment_id"
                                        value="<?= (int) $assignment["assignment_id"] ?>"
                                    >

                                    <label for="end_<?= (int) $assignment["assignment_id"] ?>">
                                        End Date
                                    </label>

                                    <input
                                        type="date"
                                        id="end_<?= (int) $assignment["assignment_id"] ?>"
                                        name="effective_to"
                                        min="<?= assignH($assignment["effective_from"]) ?>"
                                        value="<?= date("Y-m-d") ?>"
                                        required
                                    >

                                    <button type="submit">End Assignment</button>
                                </form>
                            <?php else: ?>
                                Ended
                            <?php endif; ?>
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

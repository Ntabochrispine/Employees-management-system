<?php

require_once __DIR__ . "/includes/auth.php";
requireRole(["admin", "hr"]);

require_once __DIR__ . "/config/database.php";

// Create a CSRF token if one does not already exist.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Safely escape output before displaying it in HTML.
function h($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

// Validate a date in YYYY-MM-DD format.
// An empty date is allowed.
function validDateOrEmpty(string $date): bool
{
    if ($date === "") {
        return true;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    [$year, $month, $day] = array_map(
        "intval",
        explode("-", $date)
    );

    return checkdate($month, $day, $year);
}

// Get the employee ID from the URL.
$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit("Invalid employee ID.");
}

// Load employee details and any linked login account.
$stmt = $pdo->prepare("
    SELECT
        e.*,
        u.id AS linked_user_id,
        u.role AS linked_user_role,
        u.email AS linked_user_email
    FROM employees e
    LEFT JOIN users u ON u.employee_id = e.id
    WHERE e.id = :id
    LIMIT 1
");

$stmt->execute(["id" => $id]);

$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    http_response_code(404);
    exit("Employee not found.");
}

$error = "";
$success = "";

// Process the submitted form.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {
        $error = "Invalid form token. Refresh the page and try again.";

    } else {

        // Collect and sanitize form values.
        $employeeNumber = trim($_POST["employee_number"] ?? "");
        $firstName = trim($_POST["first_name"] ?? "");
        $lastName = trim($_POST["last_name"] ?? "");
        $workerType = $_POST["worker_type"] ?? "";
        $startDate = trim($_POST["start_date"] ?? "");
        $endDate = trim($_POST["end_date"] ?? "");
        $accountRole = $_POST["account_role"] ?? "";

        $allowedTypes = [
            "permanent",
            "contract",
            "attachment",
            "casual"
        ];

        // Only these roles can be assigned through this form.
        // Admin accounts cannot be assigned through this dropdown.
        $allowedRoles = [
            "employee",
            "hr",
            "supervisor"
        ];

        // Validate employee information.
        if (
            $employeeNumber === "" ||
            strlen($employeeNumber) > 30
        ) {
            $error = "Employee number is required and must not exceed 30 characters.";

        } elseif (
            $firstName === "" ||
            strlen($firstName) > 100
        ) {
            $error = "First name is required and must not exceed 100 characters.";

        } elseif (
            $lastName === "" ||
            strlen($lastName) > 100
        ) {
            $error = "Last name is required and must not exceed 100 characters.";

        } elseif (
            !in_array($workerType, $allowedTypes, true)
        ) {
            $error = "Select a valid worker type.";

        } elseif (
            !validDateOrEmpty($startDate) ||
            !validDateOrEmpty($endDate)
        ) {
            $error = "Enter valid dates.";

        } elseif (
            $startDate !== "" &&
            $endDate !== "" &&
            $endDate < $startDate
        ) {
            $error = "The end date cannot be before the start date.";

        } elseif (
            ($_SESSION["role"] ?? "") === "admin" &&
            !empty($employee["linked_user_id"]) &&
            (
                !is_string($accountRole) ||
                !in_array($accountRole, $allowedRoles, true)
            )
        ) {
            $error = "Please select a valid account role.";
        }

        // Save only when validation succeeds.
        if ($error === "") {

            try {
                $pdo->beginTransaction();

                // Check whether another employee already uses this number.
                $duplicate = $pdo->prepare("
                    SELECT id
                    FROM employees
                    WHERE employee_number = :number
                      AND id <> :id
                    LIMIT 1
                ");

                $duplicate->execute([
                    "number" => $employeeNumber,
                    "id" => $id
                ]);

                if ($duplicate->fetch()) {
                    throw new RuntimeException(
                        "That employee number is already in use."
                    );
                }

                // Update the employee's details.
                $update = $pdo->prepare("
                    UPDATE employees
                    SET employee_number = :number,
                        first_name = :first_name,
                        last_name = :last_name,
                        worker_type = :worker_type,
                        start_date = :start_date,
                        end_date = :end_date
                    WHERE id = :id
                ");

                $update->execute([
                    "number" => $employeeNumber,
                    "first_name" => $firstName,
                    "last_name" => $lastName,
                    "worker_type" => $workerType,
                    "start_date" => $startDate !== ""
                        ? $startDate
                        : null,
                    "end_date" => $endDate !== ""
                        ? $endDate
                        : null,
                    "id" => $id
                ]);

                // Keep the linked login account's display name synchronized.
                $syncUser = $pdo->prepare("
                    UPDATE users
                    SET name = :name
                    WHERE employee_id = :employee_id
                ");

                $syncUser->execute([
                    "name" => $firstName . " " . $lastName,
                    "employee_id" => $id
                ]);

                // Only an admin can change the linked account's role.
                // HR users cannot change roles, even if they manually
                // submit an account_role field.
                if (
                    ($_SESSION["role"] ?? "") === "admin" &&
                    !empty($employee["linked_user_id"])
                ) {
                    $updateRole = $pdo->prepare("
                        UPDATE users
                        SET role = :role
                        WHERE id = :user_id
                          AND employee_id = :employee_id
                    ");

                    $updateRole->execute([
                        "role" => $accountRole,
                        "user_id" => $employee["linked_user_id"],
                        "employee_id" => $id
                    ]);
                }

                $pdo->commit();

                // Redirect after a successful update.
                header("Location: employees.php?updated=1");
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

                $error = "Could not update the employee. Check the employee number and database.";
            }
        }
    }
}

// Preserve submitted values if validation fails.
$selectedType = $_POST["worker_type"] ?? $employee["worker_type"];

$selectedRole = $_POST["account_role"]
    ?? $employee["linked_user_role"]
    ?? "";

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Employee</title>

    <link rel="stylesheet" href="assets/style.css">
</head>

<body>

<main class="container">

    <h1>Edit Employee</h1>

    <?php if ($error !== ""): ?>
        <p class="error">
            <?= h($error) ?>
        </p>
    <?php endif; ?>

    <form method="POST"
          action="edit_employee.php?id=<?= (int) $id ?>">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($_SESSION["csrf_token"]) ?>"
        >

        <label for="employee_number">Employee Number</label>

        <input
            type="text"
            id="employee_number"
            name="employee_number"
            maxlength="30"
            required
            value="<?= h(
                $_POST["employee_number"]
                ?? $employee["employee_number"]
            ) ?>"
        >

        <label for="first_name">First Name</label>

        <input
            type="text"
            id="first_name"
            name="first_name"
            maxlength="100"
            required
            value="<?= h(
                $_POST["first_name"]
                ?? $employee["first_name"]
            ) ?>"
        >

        <label for="last_name">Last Name</label>

        <input
            type="text"
            id="last_name"
            name="last_name"
            maxlength="100"
            required
            value="<?= h(
                $_POST["last_name"]
                ?? $employee["last_name"]
            ) ?>"
        >

        <?php
        // Display the role selector only to admins
        // when the employee has a linked login account.
        if (
            ($_SESSION["role"] ?? "") === "admin" &&
            !empty($employee["linked_user_id"])
        ):
        ?>

            <label for="account_role">Login Account Role</label>

            <select
                id="account_role"
                name="account_role"
                required
            >

                <option
                    value="employee"
                    <?= $selectedRole === "employee" ? "selected" : "" ?>
                >
                    Employee
                </option>

                <option
                    value="hr"
                    <?= $selectedRole === "hr" ? "selected" : "" ?>
                >
                    HR
                </option>

                <option
                    value="supervisor"
                    <?= $selectedRole === "supervisor" ? "selected" : "" ?>
                >
                    Supervisor
                </option>

            </select>

            <p>
                <strong>Login Email:</strong>
                <?= h($employee["linked_user_email"]) ?>
            </p>

        <?php elseif (empty($employee["linked_user_id"])): ?>

            <p>
                <strong>Login Account:</strong>
                No linked account. Use Manage User Accounts
                to create an account before assigning a login role.
            </p>

        <?php else: ?>

            <p>
                <strong>Login Account Role:</strong>
                <?= h(ucfirst($employee["linked_user_role"])) ?>
            </p>

            <p>
                <strong>Login Email:</strong>
                <?= h($employee["linked_user_email"]) ?>
            </p>

        <?php endif; ?>

        <label for="worker_type">Worker Type</label>

        <select
            id="worker_type"
            name="worker_type"
            required
        >

            <?php foreach (
                ["permanent", "contract", "attachment", "casual"]
                as $type
            ): ?>

                <option
                    value="<?= h($type) ?>"
                    <?= $selectedType === $type ? "selected" : "" ?>
                >
                    <?= h(ucfirst($type)) ?>
                </option>

            <?php endforeach; ?>

        </select>

        <label for="start_date">Start Date</label>

        <input
            type="date"
            id="start_date"
            name="start_date"
            value="<?= h(
                $_POST["start_date"]
                ?? $employee["start_date"]
            ) ?>"
        >

        <label for="end_date">End Date</label>

        <input
            type="date"
            id="end_date"
            name="end_date"
            value="<?= h(
                $_POST["end_date"]
                ?? $employee["end_date"]
            ) ?>"
        >

        <p>
            Employment Status:
            <strong>
                <?= h($employee["employment_status"]) ?>
            </strong>
        </p>

        <button type="submit">Save Changes</button>

        <a href="employees.php">Cancel</a>

    </form>

</main>

</body>
</html>

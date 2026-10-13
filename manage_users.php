
<?php
require_once __DIR__ . "/includes/auth.php";
requireRole(["admin"]);

require_once __DIR__ . "/config/database.php";

// Create a CSRF token for form protection.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "error";

// Process account creation.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)) {
        $message = "Invalid form token. Refresh the page and try again.";
    } else {
        $employeeId = filter_input(
            INPUT_POST,
            "employee_id",
            FILTER_VALIDATE_INT
        );

        $role = $_POST["role"] ?? "";
        $email = strtolower(trim($_POST["email"] ?? ""));
        $password = $_POST["password"] ?? "";

        $allowedRoles = ["employee", "hr", "supervisor"];

        if (!$employeeId || !in_array($role, $allowedRoles, true)) {
            $message = "Select a valid employee and role.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Enter a valid email address.";
        } elseif (strlen($password) < 12) {
            $message = "The password must contain at least 12 characters.";
        } else {
            try {
                $pdo->beginTransaction();

                // Only allow active employees without an existing account.
                $check = $pdo->prepare("
                    SELECT e.id, e.first_name, e.last_name
                    FROM employees e
                    WHERE e.id = :employee_id
                      AND e.employment_status = 'active'
                      AND NOT EXISTS (
                          SELECT 1
                          FROM users u
                          WHERE u.employee_id = e.id
                      )
                    FOR UPDATE
                ");

                $check->execute(["employee_id" => $employeeId]);
                $employee = $check->fetch(PDO::FETCH_ASSOC);

                if (!$employee) {
                    throw new RuntimeException(
                        "This employee is inactive, does not exist, or already has a login account."
                    );
                }

                // Hash the password before storing it.
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $name = trim(
                    $employee["first_name"] . " " .
                    $employee["last_name"]
                );

                $insert = $pdo->prepare("
                    INSERT INTO users
                        (employee_id, name, email, password, role, status)
                    VALUES
                        (:employee_id, :name, :email, :password, :role, 'active')
                ");

                $insert->execute([
                    "employee_id" => $employeeId,
                    "name" => $name,
                    "email" => $email,
                    "password" => $passwordHash,
                    "role" => $role
                ]);

                $pdo->commit();

                $message = "Account created successfully for " .
                    $name . ". The employee can now log in.";
                $messageType = "success";

                // Rotate the token after a successful operation.
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = $e->getMessage();

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e->getCode() === "23000") {
                    $message = "That email address is already registered, or the employee already has an account.";
                } else {
                    error_log($e->getMessage());
                    $message = "The account could not be created. Check your database configuration and try again.";
                }
            }
        }
    }
}

// Employees who do not yet have a login account.
$stmt = $pdo->query("
    SELECT e.id, e.employee_number, e.first_name, e.last_name
    FROM employees e
    WHERE e.employment_status = 'active'
      AND NOT EXISTS (
          SELECT 1 FROM users u
          WHERE u.employee_id = e.id
      )
    ORDER BY e.first_name, e.last_name
");
$availableEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Display existing accounts and their linked employee records.
$stmt = $pdo->query("
    SELECT
        u.id,
        u.name,
        u.email,
        u.role,
        u.status,
        e.employee_number
    FROM users u
    LEFT JOIN employees e ON e.id = u.employee_id
    ORDER BY u.role, u.name
");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage User Accounts</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<main class="container">
    <h1>Manage User Accounts</h1>

    <p>
        Create login accounts for existing active employees.
        Each employee can have only one linked account.
    </p>

    <?php if ($message !== ""): ?>
        <p class="<?= $messageType === 'success' ? 'success' : 'error' ?>">
            <?= h($message) ?>
        </p>
    <?php endif; ?>

    <section>
        <h2>Create an Account</h2>

        <?php if (count($availableEmployees) > 0): ?>
            <form method="POST" action="manage_users.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['csrf_token']) ?>"
                >

                <label for="employee_id">Employee</label>
                <select id="employee_id" name="employee_id" required>
                    <option value="">Select an employee</option>
                    <?php foreach ($availableEmployees as $employee): ?>
                        <option value="<?= (int)$employee['id'] ?>">
                            <?= h($employee['employee_number']) ?> -
                            <?= h($employee['first_name'] . " " . $employee['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="employee">Employee — request leave</option>
                    <option value="hr">HR — review leave requests</option>
                    <option value="supervisor">Supervisor</option>
                </select>

                <label for="email">Login Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="190"
                    required
                >

                <label for="password">Temporary Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="12"
                    autocomplete="new-password"
                    required
                >

                <button type="submit">Create Account</button>
            </form>
        <?php else: ?>
            <p>
                No unassigned active employees were found.
                Check the employee records and existing account links.
            </p>
        <?php endif; ?>
    </section>

    <section>
        <h2>Existing User Accounts</h2>

        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Employee Number</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td><?= h($account['name']) ?></td>
                            <td><?= h($account['email']) ?></td>
                            <td><?= h($account['role']) ?></td>
                            <td>
                                <?= h($account['employee_number'] ?? 'Not linked') ?>
                            </td>
                            <td><?= h($account['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (count($accounts) === 0): ?>
                        <tr>
                            <td colspan="5">No user accounts found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p><a href="dashboard.php">Back to Dashboard</a></p>
</main>

</body>
</html>

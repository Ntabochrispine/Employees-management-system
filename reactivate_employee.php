
<?php
require_once __DIR__ . "/includes/auth.php";
requireRole(["admin"]);
require_once __DIR__ . "/config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Method not allowed.");
}

$token = $_POST["csrf_token"] ?? "";
$id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);

if (
    !is_string($token) ||
    !hash_equals($_SESSION["csrf_token"] ?? "", $token)
) {
    http_response_code(403);
    exit("Invalid form token. Return to the employee directory and try again.");
}

if (!$id || $id < 1) {
    http_response_code(400);
    exit("Invalid employee ID.");
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, employment_status, end_date
        FROM employees
        WHERE id = :id
        FOR UPDATE
    ");
    $stmt->execute(["id" => $id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        throw new RuntimeException("Employee not found.");
    }

    if ($employee["employment_status"] === "active") {
        throw new RuntimeException("This employee is already active.");
    }

    $stmt = $pdo->prepare("
        UPDATE employees
        SET employment_status = 'active',
            end_date = NULL
        WHERE id = :id
    ");
    $stmt->execute(["id" => $id]);

    // Reactivate any login account linked to this employee.
    $stmt = $pdo->prepare("
        UPDATE users
        SET status = 'active'
        WHERE employee_id = :id
          AND status = 'inactive'
    ");
    $stmt->execute(["id" => $id]);

    $pdo->commit();

    header("Location: employees.php?reactivated=1");
    exit;

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8"));

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());
    http_response_code(500);
    exit("Could not reactivate the employee. Please try again.");
}

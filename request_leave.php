
<?php
require_once __DIR__ . "/includes/auth.php";
requireLogin();
require_once __DIR__ . "/config/database.php";

date_default_timezone_set("Africa/Nairobi");

$userId = (int) $_SESSION["user_id"];
$message = "";
$error = "";

$leaveTypes = [
    "annual" => "Annual Leave",
    "sick" => "Sick Leave",
    "maternity_paternity" => "Maternity / Paternity Leave",
    "compassionate" => "Compassionate Leave",
    "other" => "Other Leave"
];

$_SESSION["leave_csrf"] ??= bin2hex(random_bytes(32));

$stmt = $pdo->prepare(
    "SELECT e.id, e.employee_number, e.first_name, e.last_name
     FROM users u
     INNER JOIN employees e ON e.id = u.employee_id
     WHERE u.id = :user_id
       AND u.status = 'active'
       AND e.employment_status = 'active'
     LIMIT 1"
);
$stmt->execute(["user_id" => $userId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    http_response_code(403);
    exit(
        "Your account is not linked to an active employee record. " .
        "Contact HR to link your account."
    );
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $type = $_POST["leave_type"] ?? "";
    $startDate = $_POST["start_date"] ?? "";
    $endDate = $_POST["end_date"] ?? "";
    $reason = trim($_POST["reason"] ?? "");

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["leave_csrf"], $token)
    ) {
        $error = "Invalid form token. Refresh the page and try again.";
    } elseif (!isset($leaveTypes[$type])) {
        $error = "Select a valid leave type.";
    } elseif (
        !is_string($startDate) ||
        !is_string($endDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ||
        !checkdate(
            (int) substr($startDate, 5, 2),
            (int) substr($startDate, 8, 2),
            (int) substr($startDate, 0, 4)
        ) ||
        !checkdate(
            (int) substr($endDate, 5, 2),
            (int) substr($endDate, 8, 2),
            (int) substr($endDate, 0, 4)
        )
    ) {
        $error = "Enter valid leave dates.";
    } elseif ($endDate < $startDate) {
        $error = "The end date cannot be before the start date.";
    } elseif (strlen($reason) > 5000) {
        $error = "The reason is too long.";
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM leave_requests
                 WHERE employee_id = :employee_id
                   AND status IN ('pending', 'approved')
                   AND start_date <= :end_date
                   AND end_date >= :start_date
                 LIMIT 1"
            );

            $stmt->execute([
                "employee_id" => $employee["id"],
                "start_date" => $startDate,
                "end_date" => $endDate
            ]);

            if ($stmt->fetch()) {
                $error =
                    "You already have a pending or approved leave " .
                    "request overlapping those dates.";
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO leave_requests
                        (employee_id, requested_by, leave_type,
                         start_date, end_date, reason)
                     VALUES
                        (:employee_id, :requested_by, :leave_type,
                         :start_date, :end_date, :reason)"
                );

                $stmt->execute([
                    "employee_id" => $employee["id"],
                    "requested_by" => $userId,
                    "leave_type" => $type,
                    "start_date" => $startDate,
                    "end_date" => $endDate,
                    "reason" => $reason !== "" ? $reason : null
                ]);

                $message = "Your leave request has been submitted.";
                $_SESSION["leave_csrf"] =
                    bin2hex(random_bytes(32));
            }
        } catch (PDOException $e) {
            error_log("Leave request error: " . $e->getMessage());
            $error = "Unable to submit your request. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Leave</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <h1>Request Leave</h1>

    <p>
        Employee:
        <strong><?= htmlspecialchars(
            $employee["first_name"] . " " . $employee["last_name"]
        ) ?></strong>
        (<?= htmlspecialchars($employee["employee_number"]) ?>)
    </p>

    <p><a href="dashboard.php">Back to Dashboard</a></p>

    <?php if ($message !== ""): ?>
        <p role="status"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="request_leave.php">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION["leave_csrf"]) ?>"
        >

        <label for="leave_type">Leave Type</label>
        <select id="leave_type" name="leave_type" required>
            <option value="">Select leave type</option>
            <?php foreach ($leaveTypes as $value => $label): ?>
                <option
                    value="<?= htmlspecialchars($value) ?>"
                    <?= (($_POST["leave_type"] ?? "") === $value)
                        ? "selected" : "" ?>
                >
                    <?= htmlspecialchars($label) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="start_date">Start Date</label>
        <input
            type="date"
            id="start_date"
            name="start_date"
            value="<?= htmlspecialchars($_POST["start_date"] ?? "") ?>"
            required
        >

        <label for="end_date">End Date</label>
        <input
            type="date"
            id="end_date"
            name="end_date"
            value="<?= htmlspecialchars($_POST["end_date"] ?? "") ?>"
            required
        >

        <label for="reason">Reason (optional)</label>
        <textarea
            id="reason"
            name="reason"
            rows="4"
            maxlength="5000"
        ><?= htmlspecialchars($_POST["reason"] ?? "") ?></textarea>

        <button type="submit">Submit Leave Request</button>
    </form>
</div>
</body>
</html>

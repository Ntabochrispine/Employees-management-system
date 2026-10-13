
<?php
require_once __DIR__ . "/includes/auth.php";
requireRole(["admin", "hr"]);
require_once __DIR__ . "/config/database.php";

$leaveTypes = [
    "annual" => "Annual Leave",
    "sick" => "Sick Leave",
    "maternity_paternity" => "Maternity / Paternity Leave",
    "compassionate" => "Compassionate Leave",
    "other" => "Other Leave"
];

$message = "";
$error = "";

$_SESSION["manage_leave_csrf"] ??= bin2hex(random_bytes(32));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $requestId = filter_var(
        $_POST["request_id"] ?? null,
        FILTER_VALIDATE_INT
    );
    $decision = $_POST["decision"] ?? "";
    $comment = trim($_POST["reviewer_comment"] ?? "");

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["manage_leave_csrf"], $token)
    ) {
        $error = "Invalid form token. Refresh the page and try again.";
    } elseif (
        !$requestId ||
        !in_array($decision, ["approved", "rejected"], true)
    ) {
        $error = "Invalid leave request or decision.";
    } elseif (strlen($comment) > 1000) {
        $error = "The reviewer comment is too long.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT id, employee_id, start_date, end_date
                 FROM leave_requests
                 WHERE id = :id
                   AND status = 'pending'
                 FOR UPDATE"
            );
            $stmt->execute(["id" => $requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                $pdo->rollBack();
                $error =
                    "This request is no longer pending. Refresh the page.";
            } else {
                if ($decision === "approved") {
                    $stmt = $pdo->prepare(
                        "SELECT id
                         FROM leave_requests
                         WHERE employee_id = :employee_id
                           AND id <> :request_id
                           AND status = 'approved'
                           AND start_date <= :end_date
                           AND end_date >= :start_date
                         LIMIT 1"
                    );

                    $stmt->execute([
                        "employee_id" => $request["employee_id"],
                        "request_id" => $requestId,
                        "start_date" => $request["start_date"],
                        "end_date" => $request["end_date"]
                    ]);

                    if ($stmt->fetch()) {
                        $pdo->rollBack();
                        $error =
                            "This employee already has approved leave " .
                            "overlapping these dates.";
                    }
                }

                if ($pdo->inTransaction()) {
                    $stmt = $pdo->prepare(
                        "UPDATE leave_requests
                         SET status = :status,
                             reviewer_id = :reviewer_id,
                             reviewer_comment = :comment,
                             reviewed_at = NOW()
                         WHERE id = :id
                           AND status = 'pending'"
                    );

                    $stmt->execute([
                        "status" => $decision,
                        "reviewer_id" => (int) $_SESSION["user_id"],
                        "comment" => $comment !== "" ? $comment : null,
                        "id" => $requestId
                    ]);

                    $pdo->commit();

                    $message = "Leave request " . $decision . ".";
                    $_SESSION["manage_leave_csrf"] =
                        bin2hex(random_bytes(32));
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Leave review error: " . $e->getMessage());
            $error = "Unable to review this request. Please try again.";
        }
    }
}

$stmt = $pdo->query(
    "SELECT
        l.id,
        l.leave_type,
        l.start_date,
        l.end_date,
        l.reason,
        l.status,
        l.created_at,
        e.employee_number,
        e.first_name,
        e.last_name
     FROM leave_requests l
     INNER JOIN employees e ON e.id = l.employee_id
     ORDER BY
        CASE WHEN l.status = 'pending' THEN 0 ELSE 1 END,
        l.created_at DESC
     LIMIT 200"
);

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Leave Requests</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <h1>Manage Leave Requests</h1>

    <p><a href="dashboard.php">Back to Dashboard</a></p>

    <?php if ($message !== ""): ?>
        <p role="status"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if (!$requests): ?>
        <p>No leave requests found.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Leave Type</th>
                    <th>Dates</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Decision</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars(
                                $request["employee_number"] . " - " .
                                $request["first_name"] . " " .
                                $request["last_name"]
                            ) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars(
                                $leaveTypes[$request["leave_type"]]
                                    ?? $request["leave_type"]
                            ) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($request["start_date"]) ?>
                            to
                            <?= htmlspecialchars($request["end_date"]) ?>
                        </td>
                        <td>
                            <?= nl2br(htmlspecialchars(
                                $request["reason"] ?? ""
                            )) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars(ucfirst($request["status"])) ?>
                        </td>
                        <td>
                            <?php if ($request["status"] === "pending"): ?>
                                <form method="POST" action="manage_leave.php">
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars(
                                            $_SESSION["manage_leave_csrf"]
                                        ) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="request_id"
                                        value="<?= (int) $request["id"] ?>"
                                    >

                                    <label>Reviewer comment</label>
                                    <textarea
                                        name="reviewer_comment"
                                        maxlength="1000"
                                        rows="2"
                                    ></textarea>

                                    <button
                                        type="submit"
                                        name="decision"
                                        value="approved"
                                    >Approve</button>

                                    <button
                                        type="submit"
                                        name="decision"
                                        value="rejected"
                                    >Reject</button>
                                </form>
                            <?php else: ?>
                                <?= htmlspecialchars(
                                    $request["reviewer_comment"] ?? "—"
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>

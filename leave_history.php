
<?php
require_once __DIR__ . "/includes/auth.php";
requireRole(["employee"]);

require_once __DIR__ . "/config/database.php";

$userId = (int) $_SESSION["user_id"];

// Find the employee record linked to the logged-in worker.
$stmt = $pdo->prepare("
    SELECT e.id, e.employee_number, e.first_name, e.last_name
    FROM users u
    INNER JOIN employees e ON e.id = u.employee_id
    WHERE u.id = :user_id
      AND u.status = 'active'
      AND e.employment_status = 'active'
    LIMIT 1
");

$stmt->execute(["user_id" => $userId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

$requests = [];
$error = "";

if (!$employee) {
    $error = "Your account is not linked to an active employee record. Please contact the administrator.";
} else {
    // Show only requests belonging to this worker.
    $stmt = $pdo->prepare("
        SELECT
            lr.id,
            lr.leave_type,
            lr.start_date,
            lr.end_date,
            lr.reason,
            lr.status,
            lr.reviewer_comment,
            lr.reviewed_at,
            lr.created_at,
            reviewer.name AS reviewer_name
        FROM leave_requests lr
        LEFT JOIN users reviewer ON reviewer.id = lr.reviewer_id
        WHERE lr.employee_id = :employee_id
          AND lr.requested_by = :user_id
        ORDER BY lr.created_at DESC
    ");

    $stmt->execute([
        "employee_id" => $employee["id"],
        "user_id" => $userId
    ]);

    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function h($value): string
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function statusClass(string $status): string
{
    switch ($status) {
        case "approved":
            return "approved";
        case "rejected":
            return "rejected";
        case "cancelled":
            return "cancelled";
        default:
            return "pending";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Leave History</title>

    <link rel="stylesheet" href="assets/style.css">

    <style>
        .history-container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 20px;
        }

        .history-table-wrapper {
            overflow-x: auto;
            margin-top: 20px;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .history-table th,
        .history-table td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
            vertical-align: top;
        }

        .history-table th {
            background: #f2f4f7;
        }

        .leave-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 13px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .leave-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .leave-status.approved {
            background: #d4edda;
            color: #155724;
        }

        .leave-status.rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .leave-status.cancelled {
            background: #e2e3e5;
            color: #383d41;
        }

        .history-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin: 20px 0;
        }

        .history-message {
            padding: 12px;
            background: #f8d7da;
            color: #721c24;
            border-radius: 5px;
        }

        .empty-history {
            padding: 20px;
            background: #f2f4f7;
            border-radius: 5px;
        }
    </style>
</head>

<body>
<main class="history-container">

    <h1>My Leave History</h1>

    <?php if ($employee): ?>
        <p>
            <strong>Employee:</strong>
            <?= h($employee["first_name"] . " " . $employee["last_name"]) ?>
        </p>

        <p>
            <strong>Employee Number:</strong>
            <?= h($employee["employee_number"]) ?>
        </p>
    <?php endif; ?>

    <div class="history-actions">
        <a href="request_leave.php">Submit Leave Request</a>
        <a href="dashboard.php">Back to Dashboard</a>
    </div>

    <?php if ($error !== ""): ?>

        <p class="history-message"><?= h($error) ?></p>

    <?php elseif (count($requests) === 0): ?>

        <div class="empty-history">
            <h3>No leave requests yet</h3>
            <p>Your leave requests will appear here after you submit one.</p>
        </div>

    <?php else: ?>

        <div class="history-table-wrapper">
            <table class="history-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Leave Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Reviewed By</th>
                        <th>HR Comment</th>
                        <th>Submitted</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($requests as $index => $request): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>

                        <td>
                            <?= h(ucwords(str_replace(
                                "_",
                                " ",
                                $request["leave_type"]
                            ))) ?>
                        </td>

                        <td><?= h($request["start_date"]) ?></td>
                        <td><?= h($request["end_date"]) ?></td>

                        <td>
                            <?= $request["reason"] !== null &&
                                trim($request["reason"]) !== ""
                                ? nl2br(h($request["reason"]))
                                : "—" ?>
                        </td>

                        <td>
                            <span class="leave-status <?= h(statusClass($request["status"])) ?>">
                                <?= h($request["status"]) ?>
                            </span>
                        </td>

                        <td>
                            <?= h($request["reviewer_name"] ?? "—") ?>
                        </td>

                        <td>
                            <?= $request["reviewer_comment"] !== null &&
                                trim($request["reviewer_comment"]) !== ""
                                ? nl2br(h($request["reviewer_comment"]))
                                : "—" ?>
                        </td>

                        <td><?= h($request["created_at"]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

</main>
</body>
</html>

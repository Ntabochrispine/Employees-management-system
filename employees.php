
<?php
require_once __DIR__ . "/includes/auth.php";
requireRole(["admin", "hr"]);

require_once __DIR__ . "/config/database.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$search = trim($_GET["search"] ?? "");
$status = $_GET["status"] ?? "";
$type = $_GET["type"] ?? "";

$allowedStatuses = ["active", "inactive"];
$allowedTypes = ["permanent", "contract", "attachment", "casual"];

if (!in_array($status, $allowedStatuses, true)) {
    $status = "";
}

if (!in_array($type, $allowedTypes, true)) {
    $type = "";
}

$sql = "
    SELECT
        e.id,
        e.employee_number,
        e.first_name,
        e.last_name,
        e.worker_type,
        e.employment_status,
        e.start_date,
        e.end_date,
        u.email AS login_email,
        u.role AS login_role
    FROM employees e
    LEFT JOIN users u ON u.employee_id = e.id
    WHERE 1 = 1
";

$params = [];

if ($search !== "") {
    $sql .= "
        AND (
            e.employee_number LIKE :search_number
            OR e.first_name LIKE :search_first
            OR e.last_name LIKE :search_last
        )
    ";

    $term = "%" . $search . "%";
    $params["search_number"] = $term;
    $params["search_first"] = $term;
    $params["search_last"] = $term;
}

if ($status !== "") {
    $sql .= " AND e.employment_status = :status";
    $params["status"] = $status;
}

if ($type !== "") {
    $sql .= " AND e.worker_type = :worker_type";
    $params["worker_type"] = $type;
}

$sql .= " ORDER BY e.created_at DESC, e.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Directory</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .employee-container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 20px;
        }

        .employee-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: end;
            margin: 20px 0;
        }

        .employee-filters label {
            display: block;
            margin-bottom: 5px;
        }

        .employee-filters input,
        .employee-filters select {
            padding: 9px;
        }

        .employee-table-wrapper {
            overflow-x: auto;
        }

        .employee-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        .employee-table th,
        .employee-table td {
            border: 1px solid #ddd;
            padding: 11px;
            text-align: left;
            white-space: nowrap;
        }

        .employee-table th {
            background: #f2f4f7;
        }

        .employee-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .employee-count {
            margin: 15px 0;
        }
    </style>
</head>
<body>

<main class="employee-container">
    <h1>Employee Directory</h1>
    <p>Search and view employee records and their linked login accounts.</p>

    <div class="employee-actions">
        <?php if (($_SESSION["role"] ?? "") === "admin"): ?>
            <a href="add_employee.php">Add Employee</a>
            <a href="manage_users.php">Manage User Accounts</a>
        <?php endif; ?>

        <a href="dashboard.php">Back to Dashboard</a>
    </div>

    <form method="GET" action="employees.php" class="employee-filters">
        <div>
            <label for="search">Search employees</label>
            <input
                type="search"
                id="search"
                name="search"
                value="<?= h($search) ?>"
                placeholder="Name or employee number"
            >
        </div>

        <div>
            <label for="status">Employment status</label>
            <select name="status" id="status">
                <option value="">All statuses</option>
                <option value="active" <?= $status === "active" ? "selected" : "" ?>>
                    Active
                </option>
                <option value="inactive" <?= $status === "inactive" ? "selected" : "" ?>>
                    Inactive
                </option>
            </select>
        </div>

        <div>
            <label for="type">Worker type</label>
            <select name="type" id="type">
                <option value="">All worker types</option>
                <?php foreach ($allowedTypes as $workerType): ?>
                    <option
                        value="<?= h($workerType) ?>"
                        <?= $type === $workerType ? "selected" : "" ?>
                    >
                        <?= h(ucfirst($workerType)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit">Search</button>
        <a href="employees.php">Clear filters</a>
    </form>

    <p class="employee-count">
        <strong><?= count($employees) ?></strong>
        employee record(s) found.
    </p>

    <div class="employee-table-wrapper">
        <table class="employee-table">
            
            <thead>
                <tr>
                    <th rowspan="2">Employee Number</th>
                    <th rowspan="2">Full Name</th>
                    <th rowspan="2">Worker Type</th>
                    <th rowspan="2">Status</th>
                    <th rowspan="2">Start Date</th>
                    <th rowspan="2">End Date</th>
                    <th rowspan="2">Login Email</th>
                    <th rowspan="2">Login Role</th>
                    <th colspan="2" style="text-align: center;">Actions</th>
                </tr>
                <tr>
                    <th>Edit</th>
                    <th>Activate / Deactivate</th>
                </tr>
            </thead>


            <tbody>
            <?php if (!$employees): ?>
                <tr>
                    <td colspan="9">No matching employee records found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td><?= h($employee["employee_number"]) ?></td>
                        <td>
                            <?= h(
                                $employee["first_name"] . " " .
                                $employee["last_name"]
                            ) ?>
                        </td>
                        <td><?= h(ucfirst($employee["worker_type"])) ?></td>
                        <td><?= h(ucfirst($employee["employment_status"])) ?></td>
                        <td><?= h($employee["start_date"] ?: "—") ?></td>
                        <td><?= h($employee["end_date"] ?: "—") ?></td>
                        <td><?= h($employee["login_email"] ?: "No account linked") ?></td>
                        <td><?= h($employee["login_role"] ?: "—") ?></td>
           
                        
                    <!-- Edit column -->
                    <td>
                        <a href="edit_employee.php?id=<?= (int)$employee['id'] ?>"><button type="button">Edit</button></a>
                        </a>
                    </td>

                    <!-- Activate / Deactivate column -->
                    <td>
                        <?php if (($_SESSION["role"] ?? "") === "admin"): ?>

                            <?php if ($employee["employment_status"] === "active"): ?>

                                <form method="POST"
                                    action="deactivate_employee.php"
                                    onsubmit="return confirm('Deactivate this employee and disable their linked login account?');">

                                    <input type="hidden" name="csrf_token"
                                        value="<?= h($_SESSION['csrf_token']) ?>">

                                    <input type="hidden" name="id"
                                        value="<?= (int)$employee['id'] ?>">

                                    <button type="submit">Deactivate</button>
                                </form>

                            <?php else: ?>

                                <form method="POST"
                                    action="reactivate_employee.php"
                                    onsubmit="return confirm('Reactivate this employee and their linked login account?');">

                                    <input type="hidden" name="csrf_token"
                                        value="<?= h($_SESSION['csrf_token']) ?>">

                                    <input type="hidden" name="id"
                                        value="<?= (int)$employee['id'] ?>">

                                    <button type="submit">Activate</button>
                                </form>

                            <?php endif; ?>

                        <?php else: ?>
                            <span>Admin only</span>
                        <?php endif; ?>
                    </td>

                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

</body>
</html>

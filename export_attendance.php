
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/attendance_report_helpers.php";

requireRole(["admin", "hr"]);

$today = date("Y-m-d");
$startDate = $_GET["start_date"] ?? date("Y-m-01");
$endDate = $_GET["end_date"] ?? $today;

$employeeFilter = filter_var(
    $_GET["employee_id"] ?? "",
    FILTER_VALIDATE_INT
);

if ($employeeFilter === false || $employeeFilter < 1) {
    $employeeFilter = "";
}

if (
    !reportValidDate($startDate)
    || !reportValidDate($endDate)
    || $startDate > $endDate
    || $endDate > $today
) {
    http_response_code(400);
    exit("Invalid attendance report date range.");
}

$rows = reportLoadSessions(
    $pdo,
    $startDate,
    $endDate,
    (string) $employeeFilter
);

header("Content-Type: text/csv; charset=UTF-8");
header(
    'Content-Disposition: attachment; filename="attendance_report_'
    . $startDate . '_to_' . $endDate . '.csv"'
);

$output = fopen("php://output", "w");

/* UTF-8 BOM helps Excel recognize the CSV encoding. */
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    "Shift Date",
    "Employee Number",
    "Employee Name",
    "Assigned Shift",
    "Expected Start",
    "Grace Minutes",
    "Clock In",
    "Clock Out",
    "Recorded Duration",
    "Status"
]);

foreach ($rows as $row) {
    fputcsv($output, [
        $row["date"],
        $row["employee_number"],
        $row["employee_name"],
        $row["shift_name"],
        $row["expected_start"],
        $row["grace_minutes"] ?? "",
        $row["clock_in"] ?? "",
        $row["clock_out"] ?? "",
        $row["duration"],
        $row["status"]
    ]);
}

fclose($output);
exit;

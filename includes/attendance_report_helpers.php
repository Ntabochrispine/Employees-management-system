
<?php

function reportH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function reportValidDate(string $date): bool
{
    $parsed = DateTime::createFromFormat("!Y-m-d", $date);

    return $parsed !== false && $parsed->format("Y-m-d") === $date;
}

function reportDuration(int $seconds): string
{
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return $hours . "h " . $minutes . "m";
}

function reportLoadSessions(
    PDO $pdo,
    string $startDate,
    string $endDate,
    string $employeeFilter = ""
): array {
    /*
     * Load one day before the requested period so a session
     * that began before midnight can be paired correctly.
     */
    $fetchStart = (new DateTime($startDate))
        ->modify("-1 day")->format("Y-m-d");

    $fetchEnd = (new DateTime($endDate))
        ->modify("+2 days")->format("Y-m-d");

    $sql = "
        SELECT
            ae.id,
            ae.employee_id,
            ae.event_type,
            ae.recorded_at,
            e.employee_number,
            e.first_name,
            e.last_name
        FROM attendance_events ae
        INNER JOIN employees e ON e.id = ae.employee_id
        WHERE ae.event_status = 'accepted'
          AND ae.recorded_at >= :fetch_start
          AND ae.recorded_at < :fetch_end
    ";

    $params = [
        ":fetch_start" => $fetchStart . " 00:00:00",
        ":fetch_end" => $fetchEnd . " 00:00:00"
    ];

    if ($employeeFilter !== "") {
        $sql .= " AND ae.employee_id = :employee_id";
        $params[":employee_id"] = (int) $employeeFilter;
    }

    $sql .= "
        ORDER BY ae.employee_id, ae.recorded_at, ae.id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Pair each clock-out with the preceding open clock-in.
     */
    $openByEmployee = [];
    $sessions = [];

    foreach ($events as $event) {
        $employeeId = (int) $event["employee_id"];

        if ($event["event_type"] === "clock_in") {
            if (isset($openByEmployee[$employeeId])) {
                $open = $openByEmployee[$employeeId];

                $sessions[] = [
                    "employee_id" => $employeeId,
                    "employee_number" => $open["employee_number"],
                    "employee_name" => $open["first_name"] . " " . $open["last_name"],
                    "date" => substr($open["recorded_at"], 0, 10),
                    "clock_in" => $open["recorded_at"],
                    "clock_out" => null,
                    "anomaly" => "Unclosed clock-in"
                ];
            }

            $openByEmployee[$employeeId] = $event;
            continue;
        }

        if (!isset($openByEmployee[$employeeId])) {
            $sessions[] = [
                "employee_id" => $employeeId,
                "employee_number" => $event["employee_number"],
                "employee_name" => $event["first_name"] . " " . $event["last_name"],
                "date" => substr($event["recorded_at"], 0, 10),
                "clock_in" => null,
                "clock_out" => $event["recorded_at"],
                "anomaly" => "Unmatched clock-out"
            ];

            continue;
        }

        $open = $openByEmployee[$employeeId];

        $sessions[] = [
            "employee_id" => $employeeId,
            "employee_number" => $open["employee_number"],
            "employee_name" => $open["first_name"] . " " . $open["last_name"],
            "date" => substr($open["recorded_at"], 0, 10),
            "clock_in" => $open["recorded_at"],
            "clock_out" => $event["recorded_at"],
            "anomaly" => ""
        ];

        unset($openByEmployee[$employeeId]);
    }

    foreach ($openByEmployee as $employeeId => $open) {
        $sessions[] = [
            "employee_id" => (int) $employeeId,
            "employee_number" => $open["employee_number"],
            "employee_name" => $open["first_name"] . " " . $open["last_name"],
            "date" => substr($open["recorded_at"], 0, 10),
            "clock_in" => $open["recorded_at"],
            "clock_out" => null,
            "anomaly" => "Open session"
        ];
    }

    /*
     * Load shift assignments that overlap the requested period.
     */
    $assignmentSql = "
        SELECT
            a.employee_id,
            a.effective_from,
            a.effective_to,
            s.shift_name,
            s.start_time,
            s.end_time,
            s.grace_period_minutes
        FROM employee_shift_assignments a
        INNER JOIN work_shifts s ON s.id = a.shift_id
        WHERE a.effective_from <= :end_date
          AND (a.effective_to IS NULL OR a.effective_to >= :start_date)
    ";

    $assignmentParams = [
        ":start_date" => $startDate,
        ":end_date" => $endDate
    ];

    if ($employeeFilter !== "") {
        $assignmentSql .= " AND a.employee_id = :employee_id";
        $assignmentParams[":employee_id"] = (int) $employeeFilter;
    }

    $assignmentSql .= "
        ORDER BY a.employee_id, a.effective_from DESC
    ";

    $assignmentStmt = $pdo->prepare($assignmentSql);
    $assignmentStmt->execute($assignmentParams);

    $assignments = [];

    foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
        $assignments[(int) $assignment["employee_id"]][] = $assignment;
    }

    /*
     * Calculate duration and lateness for sessions that started
     * within the selected date range.
     */
    $rows = [];

    foreach ($sessions as $session) {
        $day = $session["date"];

        if ($day < $startDate || $day > $endDate) {
            continue;
        }

        $employeeId = (int) $session["employee_id"];
        $shift = null;

        foreach ($assignments[$employeeId] ?? [] as $assignment) {
            if (
                $assignment["effective_from"] <= $day
                && (
                    $assignment["effective_to"] === null
                    || $assignment["effective_to"] >= $day
                )
            ) {
                $shift = $assignment;
                break;
            }
        }

        $durationSeconds = 0;
        $complete = false;

        if ($session["clock_in"] && $session["clock_out"]) {
            $startTimestamp = strtotime($session["clock_in"]);
            $endTimestamp = strtotime($session["clock_out"]);

            if ($endTimestamp > $startTimestamp) {
                $durationSeconds = $endTimestamp - $startTimestamp;
                $complete = true;
            }
        }

        $late = false;

        if ($shift && $session["clock_in"]) {
            $threshold = new DateTime(
                $day . " " . $shift["start_time"]
            );

            $threshold->modify(
                "+" . (int) $shift["grace_period_minutes"] . " minutes"
            );

            $late = new DateTime($session["clock_in"]) > $threshold;
        }

        if ($session["anomaly"] !== "") {
            $status = $session["anomaly"];
        } elseif (!$complete) {
            $status = "Incomplete";
        } elseif (!$shift) {
            $status = "No shift assigned";
        } elseif ($late) {
            $status = "Late";
        } else {
            $status = "Complete";
        }

        $rows[] = [
            "date" => $day,
            "employee_id" => $employeeId,
            "employee_number" => $session["employee_number"],
            "employee_name" => $session["employee_name"],
            "shift_name" => $shift["shift_name"] ?? "No shift assigned",
            "expected_start" => $shift
                ? substr($shift["start_time"], 0, 5)
                : "",
            "grace_minutes" => $shift
                ? (int) $shift["grace_period_minutes"]
                : null,
            "clock_in" => $session["clock_in"],
            "clock_out" => $session["clock_out"],
            "duration_seconds" => $durationSeconds,
            "duration" => $complete
                ? reportDuration($durationSeconds)
                : "",
            "complete" => $complete,
            "late" => $late,
            "status" => $status
        ];
    }

    usort($rows, function ($a, $b) {
        $dateCompare = strcmp($b["date"], $a["date"]);

        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return strcmp($a["employee_name"], $b["employee_name"]);
    });

    return $rows;
}

<?php
declare(strict_types=1);

// TIMEKEEPING: monthly hours, approvals of leave and overtime, attendance verification,
// and each employee's own month for the payslip.
//
//  GET  ?resource=timekeeping&view=summary&month=YYYY-MM   HR roles: hours per employee for a month
//  GET  ?resource=timekeeping&view=pending                 HR roles: leave, overtime and attendance waiting for a decision
//  GET  ?resource=timekeeping&view=my-month&month=YYYY-MM  Employee: own month (used by the payslip)
//  POST ?resource=timekeeping  {action: leave-decide | overtime-decide | attendance-verify | ess-overtime}
//       HR Officer and Admin only. HR Staff can view but not decide.

const TK_DAY_HOURS = 8;                                   // regular hours per work day
const TK_DECIDERS = ['HR1', 'HR3', 'ADMIN', 'HR_OFFICER']; // who may approve / verify
// Philippine regular holidays (same list as the attendance calendar). Not counted as work days.
const TK_HOLIDAYS = [
    '2026-01-01', '2026-02-25', '2026-04-02', '2026-04-03', '2026-04-09', '2026-05-01',
    '2026-06-12', '2026-08-31', '2026-11-01', '2026-11-30', '2026-12-25', '2026-12-30',
];

function tkMonth(): array
{
    $month = (string)($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        jsonResponse(['error' => 'Choose a valid month.'], 422);
    }
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    return [$month, $start, $end];
}

// Monday to Friday, minus holidays, from $from to $to (inclusive).
function tkWorkDays(string $from, string $to): array
{
    $days = [];
    for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
        $date = date('Y-m-d', $d);
        if ((int)date('N', $d) <= 5 && !in_array($date, TK_HOLIDAYS, true)) {
            $days[] = $date;
        }
    }
    return $days;
}

function tkDaysBetween(string $from, string $to, string $clipFrom, string $clipTo): array
{
    $a = max($from, $clipFrom);
    $b = min($to, $clipTo);
    $days = [];
    for ($d = strtotime($a); $d <= strtotime($b); $d += 86400) {
        $days[] = date('Y-m-d', $d);
    }
    return $days;
}

// Builds the month for the given employees (rows from the employees table).
function tkBuildMonth(PDO $pdo, array $employees, string $start, string $end): array
{
    if (!$employees) {
        return [];
    }
    $ids = array_map(static fn($e) => (int)$e['id'], $employees);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $today = date('Y-m-d');
    $countUntil = min($end, $today); // absences only count for days that have passed

    $verified = true;
    try {
        $att = $pdo->prepare("SELECT id, employee_id, DATE_FORMAT(work_date, '%Y-%m-%d') AS d,
                TIMESTAMPDIFF(MINUTE, time_in, time_out) AS minutes, time_out IS NULL AS open, verified_at IS NOT NULL AS verified
            FROM attendance_records WHERE employee_id IN ($marks) AND work_date BETWEEN ? AND ?");
        $att->execute([...$ids, $start, $end]);
    } catch (PDOException $error) { // verification columns missing until 020 is run
        $verified = false;
        $att = $pdo->prepare("SELECT id, employee_id, DATE_FORMAT(work_date, '%Y-%m-%d') AS d,
                TIMESTAMPDIFF(MINUTE, time_in, time_out) AS minutes, time_out IS NULL AS open, 0 AS verified
            FROM attendance_records WHERE employee_id IN ($marks) AND work_date BETWEEN ? AND ?");
        $att->execute([...$ids, $start, $end]);
    }
    $attendance = $att->fetchAll();

    $ot = $pdo->prepare("SELECT COALESCE(o.employee_id, e.id) AS employee_id, o.hours, o.status
        FROM overtime_requests o LEFT JOIN employees e ON e.user_id = o.user_id
        WHERE COALESCE(o.employee_id, e.id) IN ($marks) AND COALESCE(o.work_date, o.date, DATE(o.created_at)) BETWEEN ? AND ?");
    $ot->execute([...$ids, $start, $end]);
    $overtime = $ot->fetchAll();

    // Leave: filed by the employee (leaves, by user) and encoded by HR (leave_requests, by employee).
    $lv = $pdo->prepare("SELECT e.id AS employee_id, DATE_FORMAT(l.start_date, '%Y-%m-%d') AS s, DATE_FORMAT(l.end_date, '%Y-%m-%d') AS f, l.status
        FROM leaves l JOIN employees e ON e.user_id = l.user_id
        WHERE e.id IN ($marks) AND l.start_date <= ? AND l.end_date >= ?
        UNION ALL
        SELECT r.employee_id, DATE_FORMAT(r.start_date, '%Y-%m-%d'), DATE_FORMAT(r.end_date, '%Y-%m-%d'), r.status
        FROM leave_requests r WHERE r.employee_id IN ($marks) AND r.start_date <= ? AND r.end_date >= ?");
    $lv->execute([...$ids, $end, $start, ...$ids, $end, $start]);
    $leaves = $lv->fetchAll();

    $rd = $pdo->prepare("SELECT employee_id, DATE_FORMAT(rest_date, '%Y-%m-%d') AS d FROM rest_days WHERE employee_id IN ($marks) AND rest_date BETWEEN ? AND ?");
    $rd->execute([...$ids, $start, $end]);
    $restDays = $rd->fetchAll();

    // First attendance ever per employee: absences are not counted before someone starts.
    $first = $pdo->prepare("SELECT employee_id, DATE_FORMAT(MIN(work_date), '%Y-%m-%d') AS d FROM attendance_records WHERE employee_id IN ($marks) GROUP BY employee_id");
    $first->execute($ids);
    $firstDay = array_column($first->fetchAll(), 'd', 'employee_id');

    $out = [];
    foreach ($employees as $e) {
        $id = (int)$e['id'];
        $perDay = [];
        $openRecords = 0;
        $openDays = [];
        $unverified = 0;
        foreach ($attendance as $a) {
            if ((int)$a['employee_id'] !== $id) continue;
            if ((int)$a['open'] === 1) { $openRecords++; $openDays[$a['d']] = true; continue; } // came in, no time-out yet
            $perDay[$a['d']] = ($perDay[$a['d']] ?? 0) + max(0, (int)$a['minutes']) / 60;
            if (!(int)$a['verified']) $unverified++;
        }
        $hours = array_sum($perDay);
        $regular = 0.0;
        $undertime = 0.0;
        foreach ($perDay as $h) {
            $regular += min($h, TK_DAY_HOURS);
            $undertime += max(0, TK_DAY_HOURS - $h);
        }
        $otApproved = 0.0;
        $otPending = 0.0;
        foreach ($overtime as $o) {
            if ((int)$o['employee_id'] !== $id) continue;
            if ($o['status'] === 'approved') $otApproved += (float)$o['hours'];
            if ($o['status'] === 'pending') $otPending += (float)$o['hours'];
        }
        $leaveDays = [];
        $pendingLeave = 0;
        foreach ($leaves as $l) {
            if ((int)$l['employee_id'] !== $id) continue;
            if ($l['status'] === 'approved') {
                foreach (tkDaysBetween($l['s'], $l['f'], $start, $end) as $d) $leaveDays[$d] = true;
            } elseif ($l['status'] === 'pending') {
                $pendingLeave++;
            }
        }
        $rest = [];
        foreach ($restDays as $r) {
            if ((int)$r['employee_id'] === $id) $rest[$r['d']] = true;
        }

        // Work days that have passed, from the employee's start (hire date or first attendance).
        $startedOn = $e['hire_date'] ?: ($firstDay[$id] ?? null);
        $absentDays = [];
        if ($startedOn !== null) {
            foreach (tkWorkDays(max($start, $startedOn), $countUntil) as $d) {
                if (!isset($perDay[$d]) && !isset($openDays[$d]) && !isset($leaveDays[$d]) && !isset($rest[$d])) $absentDays[] = $d;
            }
        }
        $workDays = tkWorkDays($start, $end);
        $paidLeaveDays = count(array_filter(array_keys($leaveDays), static fn($d) => in_array($d, $workDays, true)));

        $out[] = [
            'employeeId' => $id,
            'code' => $e['employee_code'],
            'name' => trim($e['first_name'] . ' ' . $e['last_name']),
            'department' => $e['department'],
            'workDays' => count($workDays),
            'daysPresent' => count($perDay),
            'hours' => round($hours, 2),
            'regularHours' => round($regular, 2),
            'undertimeHours' => round($undertime, 2),
            'overtimeApproved' => round($otApproved, 2),
            'overtimePending' => round($otPending, 2),
            'leaveDays' => $paidLeaveDays,
            'pendingLeave' => $pendingLeave,
            'restDays' => count($rest),
            'absences' => count($absentDays),
            'absentDates' => $absentDays,
            'openRecords' => $openRecords,
            'unverified' => $verified ? $unverified : null,
            'countedFrom' => $startedOn,
        ];
    }
    return $out;
}

// Employee portal: the signed-in employee's own month.
function handleTimekeepingEmployee(PDO $pdo, array $user): never
{
    [$month, $start, $end] = tkMonth();
    $q = $pdo->prepare("SELECT id, employee_code, first_name, last_name, department, DATE_FORMAT(hire_date, '%Y-%m-%d') AS hire_date FROM employees WHERE user_id = ? LIMIT 1");
    $q->execute([(int)$user['id']]);
    $employee = $q->fetch();
    if (!$employee) {
        jsonResponse(['error' => 'Your employee profile has not been provisioned. Contact HR.'], 404);
    }
    $rows = tkBuildMonth($pdo, [$employee], $start, $end);
    jsonResponse(['month' => $month, 'dayHours' => TK_DAY_HOURS, 'record' => $rows[0] ?? null]);
}

function handleTimekeeping(string $resource, string $method, PDO $pdo, array $user): void
{
    if ($resource !== 'timekeeping') {
        return;
    }

    if ($method === 'GET') {
        $view = (string)($_GET['view'] ?? 'summary');
        if ($view === 'summary') {
            [$month, $start, $end] = tkMonth();
            $employees = $pdo->query("SELECT id, employee_code, first_name, last_name, department, DATE_FORMAT(hire_date, '%Y-%m-%d') AS hire_date
                FROM employees ORDER BY last_name, first_name LIMIT 500")->fetchAll();
            jsonResponse(['month' => $month, 'dayHours' => TK_DAY_HOURS, 'canDecide' => in_array($user['role'], TK_DECIDERS, true),
                'records' => tkBuildMonth($pdo, $employees, $start, $end)]);
        }
        if ($view === 'pending') {
            $leave = $pdo->query("SELECT 'employee' AS source, l.id, CONCAT(e.first_name, ' ', e.last_name) AS name, e.employee_code AS code, lt.name AS type,
                    DATE_FORMAT(l.start_date, '%Y-%m-%d') AS start, DATE_FORMAT(l.end_date, '%Y-%m-%d') AS end, DATEDIFF(l.end_date, l.start_date) + 1 AS days,
                    l.notes AS reason, DATE_FORMAT(l.created_at, '%Y-%m-%d %h:%i %p') AS filed
                FROM leaves l JOIN employees e ON e.user_id = l.user_id JOIN leave_types lt ON lt.id = l.leave_type_id WHERE l.status = 'pending'
                UNION ALL
                SELECT 'hr', r.id, CONCAT(e.first_name, ' ', e.last_name), e.employee_code, lt.name,
                    DATE_FORMAT(r.start_date, '%Y-%m-%d'), DATE_FORMAT(r.end_date, '%Y-%m-%d'), DATEDIFF(r.end_date, r.start_date) + 1,
                    r.notes, DATE_FORMAT(r.created_at, '%Y-%m-%d %h:%i %p')
                FROM leave_requests r JOIN employees e ON e.id = r.employee_id JOIN leave_types lt ON lt.id = r.leave_type_id WHERE r.status = 'pending'
                ORDER BY start LIMIT 200")->fetchAll();
            $overtime = $pdo->query("SELECT o.id, CONCAT(e.first_name, ' ', e.last_name) AS name, e.employee_code AS code, o.hours, o.notes,
                    DATE_FORMAT(COALESCE(o.work_date, o.date, DATE(o.created_at)), '%Y-%m-%d') AS date, DATE_FORMAT(o.created_at, '%Y-%m-%d %h:%i %p') AS filed
                FROM overtime_requests o LEFT JOIN employees e ON e.id = o.employee_id OR (o.employee_id IS NULL AND e.user_id = o.user_id)
                WHERE o.status = 'pending' ORDER BY o.created_at LIMIT 200")->fetchAll();
            $attendance = [];
            $verifyReady = true;
            try {
                $attendance = $pdo->query("SELECT a.id, CONCAT(e.first_name, ' ', e.last_name) AS name, e.employee_code AS code,
                        DATE_FORMAT(a.work_date, '%Y-%m-%d') AS date, DATE_FORMAT(a.time_in, '%h:%i %p') AS timeIn, DATE_FORMAT(a.time_out, '%h:%i %p') AS timeOut,
                        ROUND(TIMESTAMPDIFF(MINUTE, a.time_in, a.time_out) / 60, 2) AS hours
                    FROM attendance_records a JOIN employees e ON e.id = a.employee_id
                    WHERE a.time_out IS NOT NULL AND a.verified_at IS NULL AND a.work_date >= (CURDATE() - INTERVAL 60 DAY)
                    ORDER BY a.work_date DESC, e.last_name LIMIT 300")->fetchAll();
            } catch (PDOException $error) {
                $verifyReady = false;
            }
            jsonResponse(['leave' => $leave, 'overtime' => $overtime, 'attendance' => $attendance, 'verifyReady' => $verifyReady,
                'canDecide' => in_array($user['role'], TK_DECIDERS, true)]);
        }
        jsonResponse(['error' => 'Unknown timekeeping view.'], 422);
    }

    if ($method !== 'POST') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }
    if (!in_array($user['role'], TK_DECIDERS, true)) {
        jsonResponse(['error' => 'Leave, overtime and attendance are approved by an HR Officer or Admin.'], 403);
    }
    $body = requestBody();
    $action = (string)($body['action'] ?? '');
    $status = (string)($body['status'] ?? '');

    if ($action === 'leave-decide' || $action === 'overtime-decide') {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            jsonResponse(['error' => 'Choose approve or reject.'], 422);
        }
        $table = $action === 'overtime-decide' ? 'overtime_requests'
            : (($body['source'] ?? '') === 'hr' ? 'leave_requests' : 'leaves');
        $update = $pdo->prepare("UPDATE {$table} SET status = ? WHERE id = ? AND status = 'pending'");
        $update->execute([$status, (int)($body['id'] ?? 0)]);
        if ($update->rowCount() === 0) {
            jsonResponse(['error' => 'This request was already decided or no longer exists.'], 409);
        }
        jsonResponse(['message' => ($action === 'overtime-decide' ? 'Overtime ' : 'Leave ') . $status . '.']);
    }

    if ($action === 'attendance-verify') {
        $ids = array_values(array_filter(array_map('intval', (array)($body['ids'] ?? [])), static fn($id) => $id > 0));
        if (!$ids) {
            jsonResponse(['error' => 'Choose at least one attendance record.'], 422);
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        try {
            $verify = $pdo->prepare("UPDATE attendance_records SET verified_by = ?, verified_at = NOW() WHERE id IN ($marks) AND verified_at IS NULL AND time_out IS NOT NULL");
            $verify->execute([(int)$user['id'], ...$ids]);
        } catch (PDOException $error) {
            jsonResponse(['error' => 'Run database/020_timekeeping_verification.sql in MySQL Workbench first.'], 500);
        }
        jsonResponse(['message' => $verify->rowCount() . ' attendance record(s) verified.', 'verified' => $verify->rowCount()]);
    }

    // Overtime requested by an employee in My Requests and approved in ESS Requests goes to the payroll too.
    if ($action === 'ess-overtime') {
        $code = trim((string)($body['employeeCode'] ?? ''));
        $hours = (float)($body['hours'] ?? 0);
        $date = (string)($body['date'] ?? '');
        if ($code === '' || $hours <= 0 || $hours > 24) {
            jsonResponse(['error' => 'Employee and overtime hours are required.'], 422);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $employee = $pdo->prepare('SELECT id FROM employees WHERE employee_code = ? LIMIT 1');
        $employee->execute([$code]);
        $employeeId = $employee->fetchColumn();
        if (!$employeeId) {
            jsonResponse(['error' => 'Employee was not found.'], 404);
        }
        $pdo->prepare("INSERT INTO overtime_requests (employee_id, work_date, hours, notes, status, submitted_by) VALUES (?, ?, ?, ?, 'approved', ?)")
            ->execute([(int)$employeeId, $date, $hours, 'Approved from an ESS request', (int)$user['id']]);
        jsonResponse(['message' => 'Overtime added to payroll.'], 201);
    }

    jsonResponse(['error' => 'Unknown timekeeping action.'], 422);
}

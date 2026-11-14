<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// HIMS SENDS DATA TO THE OTHER SYSTEMS (push).
//
//   HR2             employee information after onboarding     employee.onboarded, employee.activated
//   HR3             trainee accounts + applicant information  trainee.account_created, applicant.onboarded
//   HR4             claims, reimbursements, payroll inputs    claim.decided, reimbursement.decided, payroll.inputs
//   Administrators  hired applicants                          applicant.hired
//
// The functions below build the data and hand it to App\Services\PartnerPush (Laravel), which stores it
// in integration_outbox and POSTs it to the address the Super Admin saved in File Exchange > Connections.
// A push never stops the HIMS action that caused it: if a partner is offline it is retried later.
// ---------------------------------------------------------------------

const PUSH_MANAGERS = ['SUPER_ADMIN', 'HR1', 'HR3', 'ADMIN', 'HR_OFFICER'];

function himsPush(string $partner, string $event, ?string $ref, array $data, bool $sendNow = true): void
{
    if (!class_exists(\App\Services\PartnerPush::class)) {
        return;
    }
    try {
        \App\Services\PartnerPush::queue($partner, $event, $ref, $data, $sendNow);
    } catch (Throwable $error) {
        error_log('HIMS push failed: ' . $error->getMessage());
    }
}

function pushTablesReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $pdo->query('SELECT 1 FROM integration_outbox LIMIT 1');
            $ready = true;
        } catch (PDOException $error) {
            $ready = false;
        }
    }
    return $ready;
}

// ---------------------------------------------------------------- data builders
function pushApplicant(PDO $pdo, int $applicationId): ?array
{
    $stmt = $pdo->prepare('SELECT a.*, v.department AS vacancy_department, v.employment_type AS vacancy_type, v.title AS vacancy_title
                           FROM applications a LEFT JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ? LIMIT 1');
    $stmt->execute([$applicationId]);
    $a = $stmt->fetch();
    if (!$a) {
        return null;
    }
    return [
        'applicationId' => (int)$a['id'],
        'fullName' => $a['full_name'],
        'email' => $a['email'],
        'phone' => $a['phone'] ?? null,
        'position' => $a['position_title'] ?? ($a['vacancy_title'] ?? null),
        'department' => $a['vacancy_department'] ?? null,
        'employmentType' => $a['vacancy_type'] ?? null,
        'status' => $a['status'],
        'source' => $a['channel'] ?? null,
        'hasResume' => !empty($a['resume_stored_name']),
        'appliedAt' => $a['created_at'] ?? null,
        'updatedAt' => $a['updated_at'] ?? null,
    ];
}

function pushApplicantByEmail(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare("SELECT id FROM applications WHERE email = ? ORDER BY (status = 'Hired') DESC, id DESC LIMIT 1");
    $stmt->execute([$email]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    return $id ? pushApplicant($pdo, $id) : null;
}

function pushOnboarding(array $r): array
{
    return [
        'onboardingId' => (int)$r['id'],
        'applicationId' => isset($r['application_id']) ? (int)$r['application_id'] : null,
        'name' => $r['name'],
        'email' => $r['email'],
        'position' => $r['position'] ?? null,
        'department' => $r['department'] ?? null,
        'startDate' => $r['start_date'] ?? null,
        'status' => $r['status'] ?? null,
        'completedAt' => $r['completed_at'] ?? null,
    ];
}

function pushEmployee(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT e.*, u.id AS account_id, u.role AS account_role, u.name AS account_name
                           FROM employees e LEFT JOIN users u ON u.id = e.user_id
                           WHERE e.email = ? OR u.email = ? ORDER BY e.id DESC LIMIT 1');
    $stmt->execute([$email, $email]);
    $e = $stmt->fetch();
    if (!$e) {
        return null;
    }
    return [
        'employeeCode' => $e['employee_code'],
        'firstName' => $e['first_name'],
        'lastName' => $e['last_name'],
        'fullName' => trim($e['first_name'] . ' ' . $e['last_name']),
        'email' => $e['email'] ?: $email,
        'contact' => $e['contact'] ?? null,
        'department' => $e['department'] ?? null,
        'position' => $e['position'] ?? null,
        'hireDate' => $e['hire_date'] ?? null,
        'status' => $e['status'] ?? null,
        'accountRole' => $e['account_role'] ?? null,
    ];
}

function pushTrainee(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT id, name, email, department, position, role, is_active, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) {
        return null;
    }
    $training = null;
    try {
        $t = $pdo->prepare('SELECT program, start_date, expected_end_date, status, notes FROM trainee_training WHERE user_id = ? LIMIT 1');
        $t->execute([$userId]);
        $row = $t->fetch();
        if ($row) {
            $training = ['program' => $row['program'], 'startDate' => $row['start_date'], 'expectedEndDate' => $row['expected_end_date'], 'status' => $row['status'], 'notes' => $row['notes']];
        }
    } catch (PDOException $error) {
        $training = null;
    }
    return [
        'accountId' => (int)$u['id'],
        'name' => $u['name'],
        'email' => $u['email'],
        'department' => $u['department'],
        'position' => $u['position'],
        'role' => $u['role'],
        'active' => (bool)$u['is_active'],
        'createdAt' => $u['created_at'],
        'training' => $training,
    ];
}

function pushClaim(array $c): array
{
    return [
        'claimId' => $c['_uid'] ?? null,
        'employeeId' => $c['id'] ?? null,
        'employeeName' => $c['name'] ?? null,
        'amount' => is_numeric($c['amount'] ?? null) ? round((float)$c['amount'], 2) : null,
        'status' => $c['status'] ?? null,
        'filedAt' => $c['date'] ?? null,
        'encodedBy' => $c['_createdBy'] ?? null,
        'decidedBy' => $c['_updatedBy'] ?? null,
        'hasReceipt' => !empty($c['receipt']),
    ];
}

function pushReimbursement(array $r): array
{
    return [
        'requestId' => $r['id'] ?? ($r['_uid'] ?? null),
        'employeeId' => $r['empId'] ?? null,
        'employeeName' => $r['name'] ?? null,
        'department' => $r['department'] ?? null,
        'details' => $r['details'] ?? null,
        'amount' => is_numeric($r['amount'] ?? null) ? round((float)$r['amount'], 2) : null,
        'neededBy' => $r['neededBy'] ?? null,
        'status' => $r['status'] ?? null,
        'hrNote' => $r['hrNote'] ?? null,
        'decidedBy' => $r['handledBy'] ?? ($r['_updatedBy'] ?? null),
        'filedAt' => $r['created'] ?? null,
    ];
}

/** Payroll inputs for one month: salary + attendance + approved overtime + leave + absences per employee. */
function pushPayrollInputs(PDO $pdo, string $month): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || !function_exists('tkBuildMonth')) {
        return [];
    }
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    $employees = $pdo->query("SELECT id, employee_code, first_name, last_name, department, position, salary, DATE_FORMAT(hire_date, '%Y-%m-%d') AS hire_date
        FROM employees WHERE status <> 'inactive' ORDER BY last_name, first_name LIMIT 1000")->fetchAll();
    $byId = [];
    foreach ($employees as $e) {
        $byId[(int)$e['id']] = $e;
    }
    $rows = [];
    foreach (tkBuildMonth($pdo, $employees, $start, $end) as $r) {
        $e = $byId[(int)$r['employeeId']] ?? [];
        $rows[] = [
            'employeeCode' => $r['code'],
            'name' => $r['name'],
            'department' => $r['department'],
            'position' => $e['position'] ?? null,
            'basicMonthlySalary' => isset($e['salary']) ? round((float)$e['salary'], 2) : null,
            'workDays' => $r['workDays'],
            'daysPresent' => $r['daysPresent'],
            'hoursWorked' => $r['hours'],
            'regularHours' => $r['regularHours'],
            'undertimeHours' => $r['undertimeHours'],
            'overtimeApprovedHours' => $r['overtimeApproved'],
            'leaveDays' => $r['leaveDays'],
            'absences' => $r['absences'],
            'restDays' => $r['restDays'],
            'unverifiedAttendance' => $r['unverified'],
        ];
    }
    return ['month' => $month, 'periodStart' => $start, 'periodEnd' => $end, 'dayHours' => defined('TK_DAY_HOURS') ? TK_DAY_HOURS : 8, 'employees' => $rows];
}

// ---------------------------------------------------------------- events (called from the HIMS actions)
function pushOnHired(PDO $pdo, int $applicationId): void
{
    if (!pushTablesReady($pdo) || !($applicant = pushApplicant($pdo, $applicationId))) return;
    himsPush('ADMIN', 'applicant.hired', 'application-' . $applicationId, ['applicant' => $applicant]);
}

function pushOnOnboardingCompleted(PDO $pdo, int $recordId): void
{
    if (!pushTablesReady($pdo)) return;
    $stmt = $pdo->prepare("SELECT id, application_id, email, name, position, department, DATE_FORMAT(start_date, '%Y-%m-%d') AS start_date, status, completed_at FROM onboarding_records WHERE id = ?");
    $stmt->execute([$recordId]);
    $r = $stmt->fetch();
    if (!$r) return;
    $onboarding = pushOnboarding($r);
    $applicant = $r['application_id'] ? pushApplicant($pdo, (int)$r['application_id']) : pushApplicantByEmail($pdo, (string)$r['email']);
    $employee = pushEmployee($pdo, (string)$r['email']);
    himsPush('HR2', 'employee.onboarded', 'onboarding-' . $recordId, [
        'employee' => $employee ?? ['fullName' => $r['name'], 'email' => $r['email'], 'position' => $r['position'], 'department' => $r['department'], 'hireDate' => $r['start_date']],
        'onboarding' => $onboarding,
    ]);
    himsPush('HR3', 'applicant.onboarded', 'onboarding-' . $recordId, ['applicant' => $applicant, 'onboarding' => $onboarding]);
}

function pushOnTraineeCreated(PDO $pdo, int $userId): void
{
    if (!pushTablesReady($pdo) || !($trainee = pushTrainee($pdo, $userId))) return;
    himsPush('HR3', 'trainee.account_created', 'trainee-' . $userId, [
        'trainee' => $trainee,
        'applicant' => pushApplicantByEmail($pdo, (string)$trainee['email']),
    ]);
}

function pushOnEmployeeActivated(PDO $pdo, int $userId): void
{
    if (!pushTablesReady($pdo)) return;
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $email = (string)($stmt->fetchColumn() ?: '');
    if ($email === '' || !($employee = pushEmployee($pdo, $email))) return;
    himsPush('HR2', 'employee.activated', 'employee-' . $userId, ['employee' => $employee]);
}

function pushOnClaimDecided(PDO $pdo, array $claim): void
{
    if (!pushTablesReady($pdo)) return;
    himsPush('HR4', 'claim.decided', (string)($claim['_uid'] ?? ''), ['claim' => pushClaim($claim)]);
}

function pushOnReimbursementDecided(PDO $pdo, array $request): void
{
    if (!pushTablesReady($pdo)) return;
    himsPush('HR4', 'reimbursement.decided', (string)($request['_uid'] ?? ($request['id'] ?? '')), ['reimbursement' => pushReimbursement($request)]);
}

// ---------------------------------------------------------------- "send everything" (existing records)
function pushBackfill(PDO $pdo, string $partner, string $month): int
{
    $n = 0;
    $queue = static function (string $event, ?string $ref, array $data) use ($partner, &$n): void {
        himsPush($partner, $event, $ref, $data, false);
        $n++;
    };
    if ($partner === 'ADMIN') {
        foreach ($pdo->query("SELECT id FROM applications WHERE status = 'Hired' ORDER BY id LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($a = pushApplicant($pdo, (int)$id)) $queue('applicant.hired', 'application-' . $id, ['applicant' => $a]);
        }
    }
    if ($partner === 'HR2' || $partner === 'HR3') {
        try {
            $records = $pdo->query("SELECT id, application_id, email, name, position, department, DATE_FORMAT(start_date, '%Y-%m-%d') AS start_date, status, completed_at
                FROM onboarding_records WHERE status = 'Completed' AND archived = 0 ORDER BY id LIMIT 1000")->fetchAll();
        } catch (PDOException $error) {
            $records = [];
        }
        foreach ($records as $r) {
            $onboarding = pushOnboarding($r);
            if ($partner === 'HR2') {
                $employee = pushEmployee($pdo, (string)$r['email']) ?? ['fullName' => $r['name'], 'email' => $r['email'], 'position' => $r['position'], 'department' => $r['department'], 'hireDate' => $r['start_date']];
                $queue('employee.onboarded', 'onboarding-' . $r['id'], ['employee' => $employee, 'onboarding' => $onboarding]);
            } else {
                $applicant = $r['application_id'] ? pushApplicant($pdo, (int)$r['application_id']) : pushApplicantByEmail($pdo, (string)$r['email']);
                $queue('applicant.onboarded', 'onboarding-' . $r['id'], ['applicant' => $applicant, 'onboarding' => $onboarding]);
            }
        }
    }
    if ($partner === 'HR2') {
        foreach ($pdo->query("SELECT id, email FROM users WHERE role = 'EMPLOYEE' AND is_active = 1 ORDER BY id LIMIT 1000")->fetchAll() as $u) {
            if ($e = pushEmployee($pdo, (string)$u['email'])) $queue('employee.activated', 'employee-' . $u['id'], ['employee' => $e]);
        }
    }
    if ($partner === 'HR3') {
        foreach ($pdo->query("SELECT id FROM users WHERE role = 'TRAINEE' ORDER BY id LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($t = pushTrainee($pdo, (int)$id)) $queue('trainee.account_created', 'trainee-' . $id, ['trainee' => $t, 'applicant' => pushApplicantByEmail($pdo, (string)$t['email'])]);
        }
    }
    if ($partner === 'HR4') {
        try {
            $rows = $pdo->query("SELECT kind, data FROM shared_records WHERE kind IN ('claims', 'essRequests') AND status IN ('Approved', 'Rejected', 'Completed') ORDER BY seq LIMIT 2000")->fetchAll();
        } catch (PDOException $error) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $rec = json_decode((string)$row['data'], true) ?: [];
            if ($row['kind'] === 'claims') {
                $queue('claim.decided', (string)($rec['_uid'] ?? ''), ['claim' => pushClaim($rec)]);
            } elseif (($rec['type'] ?? '') === 'Reimbursement / claim') {
                $queue('reimbursement.decided', (string)($rec['_uid'] ?? ''), ['reimbursement' => pushReimbursement($rec)]);
            }
        }
        $payroll = pushPayrollInputs($pdo, $month);
        if ($payroll) $queue('payroll.inputs', 'payroll-' . $month, $payroll);
    }
    return $n;
}

// ---------------------------------------------------------------- Connections page (File Exchange)
function handlePushSettings(string $method, PDO $pdo, array $user, array $body = []): never
{
    if (!pushTablesReady($pdo)) {
        jsonResponse(['error' => 'Run database/sql/026_partner_push.sql in phpMyAdmin to turn on sending data to the other systems.', 'setup' => true], 500);
    }
    if ($method === 'GET') {
        jsonResponse(\App\Services\PartnerPush::overview() + [
            'canManage' => $user['role'] === 'SUPER_ADMIN',
            'canSend' => in_array($user['role'], PUSH_MANAGERS, true),
        ]);
    }
    $action = (string)($body['action'] ?? '');
    $partner = (string)($body['partner'] ?? '');
    $partners = \App\Services\PartnerPush::PARTNERS;
    if ($partner !== '' && !in_array($partner, $partners, true)) {
        jsonResponse(['error' => 'Unknown system.'], 422);
    }
    if ($action === 'push-save') {
        if ($user['role'] !== 'SUPER_ADMIN') jsonResponse(['error' => 'Only the Super Admin can change where data is sent.'], 403);
        $url = trim((string)($body['url'] ?? ''));
        if ($partner === '' || ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url) || strlen($url) > 500))) {
            jsonResponse(['error' => 'Enter the full address of their API, starting with https:// (or http:// for testing).'], 422);
        }
        $token = array_key_exists('token', $body) ? (string)$body['token'] : null; // null = keep the saved key
        if ($token !== null && strlen($token) > 1000) jsonResponse(['error' => 'The key is too long.'], 422);
        \App\Services\PartnerPush::saveEndpoint($partner, $url, $token, (bool)($body['enabled'] ?? true), (int)$user['id']);
        if (function_exists('writeSuperAdminAudit')) {
            writeSuperAdminAudit($pdo, $user, 'integration_push_saved', null, "Saved the push address for {$partner}");
        }
        $result = $url !== '' ? \App\Services\PartnerPush::retry($partner) : ['sent' => 0, 'failed' => 0];
        jsonResponse(['message' => "Saved. {$result['sent']} waiting item(s) sent" . ($result['failed'] ? ", {$result['failed']} still failing." : '.')]);
    }
    if (!in_array($user['role'], PUSH_MANAGERS, true)) {
        jsonResponse(['error' => 'Only an HR Officer, Admin or Super Admin can send data to the other systems.'], 403);
    }
    if ($action === 'push-test') {
        if ($partner === '') jsonResponse(['error' => 'Choose a system.'], 422);
        $id = \App\Services\PartnerPush::queue($partner, 'hims.test', 'test', ['message' => 'Test from HIMS (HR1). If you can read this, the connection works.']);
        $row = $id ? \App\Models\IntegrationOutbox::query()->find($id) : null;
        if ($row && $row->status === 'sent') jsonResponse(['message' => "{$partner} received the test (HTTP {$row->response_code})."]);
        jsonResponse(['error' => 'Test not delivered: ' . ($row->last_error ?? 'unknown error')], 502);
    }
    if ($action === 'push-retry') {
        $result = \App\Services\PartnerPush::retry($partner !== '' ? $partner : null);
        jsonResponse(['message' => "Sent {$result['sent']}" . ($result['failed'] ? ", {$result['failed']} still failing." : '.')]);
    }
    if ($action === 'push-backfill') {
        if ($partner === '') jsonResponse(['error' => 'Choose a system.'], 422);
        $month = (string)($body['month'] ?? date('Y-m'));
        $count = pushBackfill($pdo, $partner, $month);
        $result = \App\Services\PartnerPush::retry($partner, 2000);
        jsonResponse(['message' => "{$count} record(s) prepared for {$partner}: {$result['sent']} sent" . ($result['failed'] ? ", {$result['failed']} waiting (see the log)." : '.')]);
    }
    if ($action === 'push-payroll') {
        $month = (string)($body['month'] ?? '');
        $payroll = pushPayrollInputs($pdo, $month);
        if (!$payroll) jsonResponse(['error' => 'Choose a valid month.'], 422);
        $id = \App\Services\PartnerPush::queue('HR4', 'payroll.inputs', 'payroll-' . $month, $payroll);
        $row = $id ? \App\Models\IntegrationOutbox::query()->find($id) : null;
        $n = count($payroll['employees']);
        jsonResponse(['message' => $row && $row->status === 'sent'
            ? "Payroll inputs for {$month} ({$n} employees) sent to HR4."
            : "Payroll inputs for {$month} ({$n} employees) saved; not delivered yet: " . ($row->last_error ?? 'unknown error')]);
    }
    jsonResponse(['error' => 'Unknown action.'], 422);
}

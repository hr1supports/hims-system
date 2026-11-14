<?php
declare(strict_types=1);

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    error_log('HIMS API fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['error' => 'The PHP API encountered a server error. Check the application runtime logs.']);
    }
});

$allowedOrigins = ['http://127.0.0.1:5500', 'http://localhost:5500'];
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-HR3-Request');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/config.php';
require __DIR__ . '/hiring.php';
require __DIR__ . '/accounts.php';
require __DIR__ . '/timekeeping.php';
require __DIR__ . '/file-exchange.php';
require __DIR__ . '/shared-records.php';
require __DIR__ . '/integration-push.php';

function writeSuperAdminAudit(PDO $pdo, array $actor, string $action, ?int $targetId, string $details): void
{
    $statement = $pdo->prepare('INSERT INTO super_admin_audit (actor_id, action, target_user_id, details, ip_address) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$actor['id'], $action, $targetId, $details, clientIp()]);
}

function traineeTrainingValues(array $body): array
{
    $program = trim((string)($body['training_program'] ?? 'General onboarding'));
    $startDate = trim((string)($body['training_start_date'] ?? ''));
    $endDate = trim((string)($body['training_end_date'] ?? ''));
    $status = (string)($body['training_status'] ?? 'Not started');
    $notes = trim((string)($body['training_notes'] ?? ''));

    if ($program === '' || mb_strlen($program) > 150
        || ($startDate !== '' && !validDate($startDate))
        || ($endDate !== '' && !validDate($endDate))
        || ($startDate !== '' && $endDate !== '' && $endDate < $startDate)
        || !in_array($status, ['Not started', 'In progress', 'Completed'], true)
        || mb_strlen($notes) > 1000) {
        jsonResponse(['error' => 'Enter a training program, valid date range, status, and notes under 1000 characters.'], 422);
    }
    return [$program, $startDate ?: null, $endDate ?: null, $status, $notes ?: null];
}

function ensureTraineeTraining(PDO $pdo, int $userId, array $body = []): void
{
    $check = $pdo->prepare('SELECT id FROM trainee_training WHERE user_id = ? LIMIT 1');
    $check->execute([$userId]);
    if ($check->fetchColumn()) {
        return;
    }
    [$program, $startDate, $endDate, $status, $notes] = traineeTrainingValues($body);
    $insert = $pdo->prepare('INSERT INTO trainee_training (user_id, program, start_date, expected_end_date, status, notes) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([$userId, $program, $startDate, $endDate, $status, $notes]);
}

// ---------------------------------------------------------------------
// SYSTEM SETTINGS: values the Super Admin can change on the Settings page.
// Defaults are used until a value is saved (or if migration 017 is not run yet).
// ---------------------------------------------------------------------
const SETTING_DEFAULTS = [
    'hospital_name' => 'Hospital System',
    'hospital_logo' => '',
    'leave_vacation' => '15',
    'leave_sick' => '10',
    'leave_emergency' => '5',
    'lock_max_failures' => '5',
    'lock_minutes' => '5',
    'onboarding_overdue_days' => '30',
    'target_hire_rate' => '10',
    'target_reached_interview' => '30',
    'target_interviews_completed' => '80',
    'target_pending_claims' => '5',
    'target_pending_ess' => '5',
    'target_active_employees' => '90',
];
// [min, max] for the number settings
const SETTING_LIMITS = [
    'leave_vacation' => [0, 60], 'leave_sick' => [0, 60], 'leave_emergency' => [0, 30],
    'lock_max_failures' => [3, 20], 'lock_minutes' => [1, 1440], 'onboarding_overdue_days' => [1, 365],
    'target_hire_rate' => [0, 100], 'target_reached_interview' => [0, 100], 'target_interviews_completed' => [0, 100],
    'target_pending_claims' => [0, 1000], 'target_pending_ess' => [0, 1000], 'target_active_employees' => [0, 100],
];

function hrSettings(?PDO $pdo = null): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = SETTING_DEFAULTS;
    try {
        foreach (($pdo ?? db())->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll() as $row) {
            if (array_key_exists($row['setting_key'], SETTING_DEFAULTS) && $row['setting_value'] !== null) {
                $cache[$row['setting_key']] = (string)$row['setting_value'];
            }
        }
    } catch (Throwable $error) { /* table missing: defaults */ }
    return $cache;
}
function hrSetting(string $key): string { return hrSettings()[$key] ?? (SETTING_DEFAULTS[$key] ?? ''); }
function leaveQuotas(): array
{
    return ['Vacation Leave' => (int)hrSetting('leave_vacation'), 'Sick Leave' => (int)hrSetting('leave_sick'), 'Emergency Leave' => (int)hrSetting('leave_emergency')];
}

// Public (no sign-in): only the hospital name and logo, for the login and careers pages.
function handlePublicSettings(string $resource, string $method): void
{
    if ($resource !== 'public-settings' || $method !== 'GET') {
        return;
    }
    jsonResponse(['hospitalName' => hrSetting('hospital_name'), 'hospitalLogo' => hrSetting('hospital_logo')]);
}

// Signed-in users read settings; only the Super Admin changes them.
function handleSettings(string $resource, string $method, PDO $pdo, array $user): void
{
    if ($resource !== 'settings') {
        return;
    }
    if ($method === 'GET') {
        jsonResponse(['settings' => hrSettings($pdo), 'defaults' => SETTING_DEFAULTS]);
    }
    if ($user['role'] !== 'SUPER_ADMIN') {
        jsonResponse(['error' => 'Only the Super Admin can change system settings.'], 403);
    }
    $values = requestBody()['settings'] ?? null;
    if (!is_array($values)) {
        jsonResponse(['error' => 'No settings were sent.'], 422);
    }
    $clean = [];
    foreach ($values as $key => $value) {
        if (!array_key_exists($key, SETTING_DEFAULTS)) continue;
        $value = trim((string)$value);
        if ($key === 'hospital_name') {
            if ($value === '' || mb_strlen($value) > 120) jsonResponse(['error' => 'Hospital name must be 1 to 120 characters.'], 422);
        } elseif ($key === 'hospital_logo') {
            if ($value !== '' && (!preg_match('#^data:image/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$#', $value) || strlen($value) > 250000)) {
                jsonResponse(['error' => 'The logo must be a PNG, JPG, WEBP or GIF image under about 180 KB.'], 422);
            }
        } else {
            [$min, $max] = SETTING_LIMITS[$key];
            if (!preg_match('/^\d+$/', $value) || (int)$value < $min || (int)$value > $max) {
                jsonResponse(['error' => "Enter a whole number from {$min} to {$max} for " . str_replace('_', ' ', $key) . '.'], 422);
            }
        }
        $clean[$key] = $value;
    }
    $save = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)');
    foreach ($clean as $key => $value) {
        $save->execute([$key, $value, $user['id']]);
    }
    writeSuperAdminAudit($pdo, $user, 'settings-updated', null, 'Updated system settings: ' . implode(', ', array_keys($clean)) . '.');
    jsonResponse(['message' => 'Settings saved.']);
}

// ---------------------------------------------------------------------
// DEPARTMENTS & POSITIONS: one official list, used by the forms' dropdowns.
// ---------------------------------------------------------------------
function handleDepartments(string $resource, string $method, PDO $pdo, array $user): void
{
    if ($resource !== 'departments') {
        return;
    }
    if ($method === 'GET') {
        $departments = $pdo->query('SELECT id, name, archived FROM departments ORDER BY archived, name')->fetchAll();
        $positions = $pdo->query('SELECT id, department_id AS departmentId, title, archived FROM positions ORDER BY archived, title')->fetchAll();
        $byDepartment = [];
        foreach ($positions as $position) {
            $byDepartment[(int)$position['departmentId']][] = $position;
        }
        // How many people use each department (all spellings), and spellings that are not in the list.
        $usage = $pdo->query("SELECT name, SUM(total) AS total FROM (
                SELECT TRIM(CONVERT(department USING utf8mb4)) COLLATE utf8mb4_general_ci AS name, COUNT(*) AS total FROM employees WHERE department IS NOT NULL AND TRIM(department) <> '' GROUP BY 1
                UNION ALL SELECT TRIM(CONVERT(department USING utf8mb4)) COLLATE utf8mb4_general_ci, COUNT(*) FROM users WHERE department IS NOT NULL AND TRIM(department) <> '' GROUP BY 1
            ) AS used GROUP BY name")->fetchAll();
        $variants = $pdo->query("SELECT DISTINCT BINARY TRIM(CONVERT(department USING utf8mb4)) AS spelling FROM (
                SELECT department FROM employees UNION ALL SELECT department FROM users UNION ALL SELECT department FROM vacancies
            ) AS d WHERE department IS NOT NULL AND TRIM(department) <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $official = [];
        foreach ($departments as $department) $official[mb_strtolower($department['name'])] = $department['name'];
        $counts = [];
        foreach ($usage as $row) $counts[mb_strtolower((string)$row['name'])] = (int)$row['total'];
        $needsCleanup = [];
        $unlisted = [];
        foreach ($variants as $spelling) {
            $spelling = (string)$spelling;
            $key = mb_strtolower($spelling);
            if (!isset($official[$key])) $unlisted[$key] = $spelling;
            elseif ($official[$key] !== $spelling) $needsCleanup[] = ['spelling' => $spelling, 'official' => $official[$key]];
        }
        foreach ($departments as &$department) {
            $department['archived'] = (int)$department['archived'];
            $department['people'] = $counts[mb_strtolower($department['name'])] ?? 0;
            $department['positions'] = $byDepartment[(int)$department['id']] ?? [];
        }
        unset($department);
        jsonResponse(['records' => $departments, 'needsCleanup' => $needsCleanup, 'unlisted' => array_values($unlisted)]);
    }
    if ($user['role'] !== 'SUPER_ADMIN') {
        jsonResponse(['error' => 'Only the Super Admin can change departments and positions.'], 403);
    }
    $body = requestBody();
    $action = (string)($body['action'] ?? '');
    $name = trim(preg_replace('/\s+/', ' ', (string)($body['name'] ?? '')));
    $id = (int)($body['id'] ?? 0);
    $valid = static function (string $text, string $what): void {
        if ($text === '' || mb_strlen($text) > 100) jsonResponse(['error' => "Enter a {$what} name (100 characters max)."], 422);
    };
    try {
        if ($action === 'department-add') {
            $valid($name, 'department');
            $pdo->prepare('INSERT INTO departments (name) VALUES (?)')->execute([$name]);
            writeSuperAdminAudit($pdo, $user, 'department-added', null, "Added department \"{$name}\".");
            jsonResponse(['message' => 'Department added.'], 201);
        }
        if ($action === 'department-rename') {
            $valid($name, 'department');
            $old = $pdo->prepare('SELECT name FROM departments WHERE id = ?'); $old->execute([$id]);
            $oldName = $old->fetchColumn();
            if ($oldName === false) jsonResponse(['error' => 'Department not found.'], 404);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE departments SET name = ? WHERE id = ?')->execute([$name, $id]);
            foreach (['employees', 'users', 'vacancies'] as $table) {
                $pdo->prepare("UPDATE {$table} SET department = ? WHERE LOWER(TRIM(CONVERT(department USING utf8mb4))) COLLATE utf8mb4_general_ci = LOWER(?)")->execute([$name, (string)$oldName]);
            }
            $pdo->commit();
            writeSuperAdminAudit($pdo, $user, 'department-renamed', null, "Renamed department \"{$oldName}\" to \"{$name}\" (records updated).");
            jsonResponse(['message' => 'Department renamed everywhere.']);
        }
        if ($action === 'department-archive' || $action === 'department-restore') {
            $pdo->prepare('UPDATE departments SET archived = ? WHERE id = ?')->execute([$action === 'department-archive' ? 1 : 0, $id]);
            writeSuperAdminAudit($pdo, $user, $action === 'department-archive' ? 'department-archived' : 'department-restored', null, ucfirst(substr($action, 11)) . "d department #{$id}.");
            jsonResponse(['message' => $action === 'department-archive' ? 'Department archived.' : 'Department restored.']);
        }
        if ($action === 'position-add') {
            $valid($name, 'position');
            $pdo->prepare('INSERT INTO positions (department_id, title) VALUES (?, ?)')->execute([(int)($body['departmentId'] ?? 0), $name]);
            jsonResponse(['message' => 'Position added.'], 201);
        }
        if ($action === 'position-archive' || $action === 'position-restore') {
            $pdo->prepare('UPDATE positions SET archived = ? WHERE id = ?')->execute([$action === 'position-archive' ? 1 : 0, $id]);
            jsonResponse(['message' => $action === 'position-archive' ? 'Position archived.' : 'Position restored.']);
        }
        if ($action === 'cleanup') {
            // Make every record use the official spelling, for example "Hr department" becomes "HR department".
            $changed = 0;
            foreach ($pdo->query('SELECT name FROM departments')->fetchAll(PDO::FETCH_COLUMN) as $official) {
                foreach (['employees', 'users', 'vacancies'] as $table) {
                    $update = $pdo->prepare("UPDATE {$table} SET department = ? WHERE LOWER(TRIM(CONVERT(department USING utf8mb4))) COLLATE utf8mb4_general_ci = LOWER(?) AND BINARY department <> BINARY ?");
                    $update->execute([$official, $official, $official]);
                    $changed += $update->rowCount();
                }
            }
            writeSuperAdminAudit($pdo, $user, 'departments-cleaned', null, "Fixed department spelling on {$changed} record(s).");
            jsonResponse(['message' => "Fixed the department name on {$changed} record(s).", 'changed' => $changed]);
        }
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int)($error->errorInfo[1] ?? 0) === 1062) jsonResponse(['error' => 'That name is already in the list.'], 409);
        throw $error;
    }
    jsonResponse(['error' => 'Unknown department action.'], 422);
}

// ---------------------------------------------------------------------
// ACCOUNT LOCKOUT: 5 failed sign-ins within 5 minutes (changeable in Settings) locks an account,
// or until a Super Admin clicks Unlock. Uses the login_attempts table the login already writes.
// ---------------------------------------------------------------------
function lockMaxFailures(): int { return max(3, (int)hrSetting('lock_max_failures')); }
function lockMinutes(): int { return max(1, (int)hrSetting('lock_minutes')); }

// Returns ['email' => ['failures' => n, 'lockedUntil' => 'Y-m-d H:i:s']] for every locked account.
function lockedAccounts(PDO $pdo, ?string $onlyEmail = null): array
{
    // Times are worked out by MySQL so PHP and MySQL time zones can't disagree about how long is left.
    $sql = "SELECT LOWER(l.email) AS email, COUNT(*) AS failures, MAX(l.created_at) AS lastFailure,
            DATE_FORMAT(MAX(l.created_at) + INTERVAL " . lockMinutes() . " MINUTE, '%Y-%m-%d %h:%i %p') AS lockedUntil,
            TIMESTAMPDIFF(SECOND, NOW(), MAX(l.created_at) + INTERVAL " . lockMinutes() . " MINUTE) AS secondsLeft
        FROM login_attempts l
        LEFT JOIN users u ON LOWER(u.email) = LOWER(l.email)
        WHERE l.kind = 'login' AND l.success = 0
          AND l.created_at > (NOW() - INTERVAL " . lockMinutes() . " MINUTE)
          AND (u.unlocked_at IS NULL OR l.created_at > u.unlocked_at)
          AND l.created_at > COALESCE((SELECT MAX(s.created_at) FROM login_attempts s
                WHERE s.kind = 'login' AND s.success = 1 AND LOWER(s.email) = LOWER(l.email)), '1970-01-01')"
        . ($onlyEmail !== null ? ' AND LOWER(l.email) = ?' : '') . '
        GROUP BY LOWER(l.email)
        HAVING COUNT(*) >= ' . lockMaxFailures();
    $statement = $pdo->prepare($sql);
    $statement->execute($onlyEmail !== null ? [strtolower($onlyEmail)] : []);
    $locked = [];
    foreach ($statement->fetchAll() as $row) {
        $locked[$row['email']] = [
            'failures' => (int)$row['failures'],
            'lockedUntil' => (string)$row['lockedUntil'],
            'secondsLeft' => max(0, (int)$row['secondsLeft']),
        ];
    }
    return $locked;
}

// Called before the normal login code: refuses sign-in to a locked account.
function enforceAccountLock(string $resource, string $method): void
{
    if ($resource !== 'login' || $method !== 'POST') {
        return;
    }
    $email = strtolower(trim((string)(requestBody()['email'] ?? '')));
    if ($email === '') {
        return;
    }
    try {
        $lock = lockedAccounts(db(), $email)[$email] ?? null;
    } catch (Throwable $error) {
        return; // never block sign-in because of a lockout check problem (for example, migration 016 not run yet)
    }
    if ($lock) {
        $minutes = max(1, (int)ceil($lock['secondsLeft'] / 60));
        jsonResponse(['error' => "This account is locked after {$lock['failures']} failed sign-ins. Try again in about {$minutes} minute(s), or ask the Super Admin to unlock it.", 'locked' => true], 423);
    }
}

// Full SQL backup of the connected database (structure and data), built with PDO.
function buildDatabaseBackup(PDO $pdo): array
{
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    $info = dbInfo();
    $out = "-- HIMS database backup\n-- Database: {$info['name']} ({$info['mode']}) on {$info['host']}\n-- Created: " . date('Y-m-d H:i:s') . "\n\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    $rowTotal = 0;
    foreach ($tables as [$table]) {
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
        $out .= "-- Table `{$table}`\nDROP TABLE IF EXISTS `{$table}`;\n{$create[1]};\n";
        $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
        $batch = [];
        $columns = null;
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns ??= '(`' . implode('`, `', array_keys($row)) . '`)';
            $batch[] = '(' . implode(', ', array_map(static fn($value) => $value === null ? 'NULL' : $pdo->quote((string)$value), array_values($row))) . ')';
            $rowTotal++;
            if (count($batch) === 200) {
                $out .= "INSERT INTO `{$table}` {$columns} VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }
        if ($batch) {
            $out .= "INSERT INTO `{$table}` {$columns} VALUES\n" . implode(",\n", $batch) . ";\n";
        }
        $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return ['sql' => $out, 'tables' => count($tables), 'rows' => $rowTotal];
}

function handleSuperAdmin(string $resource, string $method, PDO $pdo, array $user): void
{
    if ($resource !== 'super-admin') {
        return;
    }
    if ($user['role'] !== 'SUPER_ADMIN') {
        jsonResponse(['error' => 'Super Admin access required.'], 403);
    }

    if ($method === 'GET') {
        $view = (string)($_GET['view'] ?? 'overview');
        if ($view === 'overview') {
            $stats = [
                'activeAccounts' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn(),
                'disabledAccounts' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn(),
                'pendingApprovals' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE approval = 'pending'")->fetchColumn(),
                'failedLogins' => (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE kind = 'login' AND success = 0 AND created_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn(),
            ];
            $recent = $pdo->query('SELECT a.id, a.action, a.details, DATE_FORMAT(a.created_at, \'%Y-%m-%d %H:%i\') AS created, u.name AS actor FROM super_admin_audit a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 8')->fetchAll();
            $database = dbInfo() + ['version' => (string)$pdo->query('SELECT VERSION()')->fetchColumn()];
            jsonResponse(['stats' => $stats, 'recent' => $recent, 'database' => $database]);
        }
        if ($view === 'signins') {
            $days = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m-%d') AS day, SUM(success = 1) AS ok, SUM(success = 0) AS failed
                FROM login_attempts WHERE kind = 'login' AND created_at >= (CURDATE() - INTERVAL 13 DAY)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d') ORDER BY day")->fetchAll();
            $topFailed = $pdo->query("SELECT LOWER(email) AS email, COUNT(*) AS failed, DATE_FORMAT(MAX(created_at), '%Y-%m-%d %H:%i') AS last
                FROM login_attempts WHERE kind = 'login' AND success = 0 AND created_at > (NOW() - INTERVAL 24 HOUR)
                GROUP BY LOWER(email) ORDER BY failed DESC LIMIT 5")->fetchAll();
            jsonResponse(['days' => $days, 'topFailed' => $topFailed]);
        }
        if ($view === 'health') {
            $health = dbInfo() + [
                'version' => (string)$pdo->query('SELECT VERSION()')->fetchColumn(),
                'php' => PHP_VERSION,
                'serverTime' => date('Y-m-d h:i:s A'), // 12-hour clock, e.g. 2026-10-14 08:05:46 PM
                'serverEpoch' => time(), // lets the page keep the clock ticking
                'timezone' => date_default_timezone_get(),
            ];
            $size = $pdo->query('SELECT COUNT(*) AS tables, COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.TABLES WHERE table_schema = DATABASE()')->fetch();
            $health['tables'] = (int)$size['tables'];
            $health['sizeBytes'] = (int)$size['bytes'];
            try {
                $health['uptimeSeconds'] = (int)($pdo->query("SHOW GLOBAL STATUS LIKE 'Uptime'")->fetch(PDO::FETCH_NUM)[1] ?? 0);
            } catch (Throwable $error) {
                $health['uptimeSeconds'] = null;
            }
            try {
                $health['lastBackup'] = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') FROM backup_history ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
                $health['backupsReady'] = true;
            } catch (Throwable $error) {
                $health['lastBackup'] = null;
                $health['backupsReady'] = false;
            }
            jsonResponse(['health' => $health]);
        }
        if ($view === 'backups') {
            try {
                $rows = $pdo->query("SELECT b.id, b.file_name AS fileName, b.size_bytes AS sizeBytes, b.table_count AS tables, b.row_count AS rowCount, b.database_mode AS mode,
                    DATE_FORMAT(b.created_at, '%Y-%m-%d %H:%i') AS created, u.name AS createdBy FROM backup_history b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 20")->fetchAll();
                jsonResponse(['records' => $rows, 'ready' => true]);
            } catch (PDOException $error) {
                jsonResponse(['records' => [], 'ready' => false]);
            }
        }
        if ($view === 'pending') {
            $list = static fn(string $sql) => $pdo->query($sql)->fetchAll();
            $hired = $list("SELECT a.id, a.full_name AS name, a.position_title AS detail FROM applications a WHERE a.status = 'Hired' AND a.archived = 0
                AND NOT EXISTS (SELECT 1 FROM users u WHERE LOWER(u.email) = LOWER(a.email)) ORDER BY a.created_at LIMIT 50");
            $promote = $list("SELECT u.id, u.name, COALESCE(t.program, 'Training') AS detail FROM users u JOIN trainee_training t ON t.user_id = u.id
                WHERE u.role = 'TRAINEE' AND u.is_active = 1 AND t.status = 'Completed' ORDER BY u.name LIMIT 50");
            try {
                $onboarded = $list("SELECT u.id, u.name, 'Onboarding approved' AS detail FROM users u JOIN onboarding_records r
                    ON r.email COLLATE utf8mb4_general_ci = LOWER(CONVERT(u.email USING utf8mb4)) COLLATE utf8mb4_general_ci
                    WHERE u.role = 'TRAINEE' AND u.is_active = 1 AND r.status = 'Completed' AND r.archived = 0 LIMIT 50");
                $seen = array_column($promote, 'id');
                foreach ($onboarded as $row) {
                    if (!in_array($row['id'], $seen)) $promote[] = $row;
                }
            } catch (Throwable $error) { /* onboarding tables not installed */ }
            $lockedRows = [];
            try {
                $locks = lockedAccounts($pdo);
                if ($locks) {
                    $marks = implode(',', array_fill(0, count($locks), '?'));
                    $names = $pdo->prepare("SELECT id, name, LOWER(email) AS email FROM users WHERE LOWER(email) IN ($marks)");
                    $names->execute(array_keys($locks));
                    foreach ($names->fetchAll() as $row) {
                        $lockedRows[] = ['id' => $row['id'], 'name' => $row['name'], 'detail' => $locks[$row['email']]['failures'] . ' failed sign-ins, locked until ' . $locks[$row['email']]['lockedUntil']];
                    }
                }
            } catch (Throwable $error) { /* lockout check unavailable */ }
            $never = $list("SELECT id, name, CONCAT(role, ' account created ', DATE_FORMAT(created_at, '%Y-%m-%d')) AS detail FROM users
                WHERE is_active = 1 AND last_login_at IS NULL AND role <> 'SUPER_ADMIN' AND created_at < (NOW() - INTERVAL 7 DAY) ORDER BY created_at LIMIT 50");
            $approvals = $list("SELECT id, name, CONCAT(role, ' sign-up waiting for approval') AS detail FROM users WHERE approval = 'pending' ORDER BY id LIMIT 50");
            jsonResponse(['groups' => [
                ['key' => 'hired', 'title' => 'Hired applicants without a trainee account', 'action' => 'Create trainee account', 'tone' => 'watch', 'items' => $hired],
                ['key' => 'promote', 'title' => 'Trainees ready to become Employees', 'action' => 'Change role to Employee', 'tone' => 'good', 'items' => $promote],
                ['key' => 'locked', 'title' => 'Locked accounts', 'action' => 'Unlock', 'tone' => 'bad', 'items' => $lockedRows],
                ['key' => 'approval', 'title' => 'Accounts waiting for approval', 'action' => 'Review', 'tone' => 'watch', 'items' => $approvals],
                ['key' => 'never', 'title' => 'Accounts never used (7+ days old)', 'action' => 'Review', 'tone' => 'muted', 'items' => $never],
            ]]);
        }
        if ($view === 'accounts') {
            // Online now / signed in today (needs database/018_online_status.sql; falls back without it).
            $activity = ", DATE(u.last_login_at) = CURDATE() AS loginToday, DATE_FORMAT(u.last_login_at, '%h:%i %p') AS lastLoginTime,
                (SELECT COUNT(*) FROM login_attempts s WHERE s.kind = 'login' AND s.success = 1 AND LOWER(s.email) = LOWER(u.email) AND s.created_at >= CURDATE()) AS loginsToday";
            try {
                $pdo->query('SELECT last_seen_at FROM users LIMIT 1');
                $activity .= ", TIMESTAMPDIFF(SECOND, u.last_seen_at, NOW()) AS seenAgo, DATE_FORMAT(u.last_seen_at, '%h:%i %p') AS lastSeenTime";
            } catch (Throwable $error) {
                $activity .= ', NULL AS seenAgo, NULL AS lastSeenTime, 1 AS onlineUnavailable';
            }
            $rows = $pdo->query("SELECT u.id, u.name, u.email, u.role, u.department, u.position, u.is_active AS active, u.approval, DATE_FORMAT(u.last_login_at, '%Y-%m-%d %h:%i %p') AS lastLogin{$activity}, t.program AS trainingProgram, DATE_FORMAT(t.start_date, '%Y-%m-%d') AS trainingStart, DATE_FORMAT(t.expected_end_date, '%Y-%m-%d') AS trainingEnd, t.status AS trainingStatus, t.notes AS trainingNotes FROM users u LEFT JOIN trainee_training t ON t.user_id = u.id ORDER BY CASE u.role WHEN 'SUPER_ADMIN' THEN 0 WHEN 'ADMIN' THEN 1 ELSE 2 END, u.role, u.name LIMIT 1000")->fetchAll();
            try {
                $locks = lockedAccounts($pdo);
                foreach ($rows as &$row) {
                    $lock = $locks[strtolower((string)$row['email'])] ?? null;
                    $row['locked'] = (bool)$lock;
                    $row['lockedUntil'] = $lock['lockedUntil'] ?? null;
                    $row['lockFailures'] = $lock['failures'] ?? 0;
                    $row['online'] = !$lock && (int)$row['active'] === 1 && $row['seenAgo'] !== null && (int)$row['seenAgo'] <= SESSION_IDLE_SECONDS;
                }
                unset($row);
            } catch (Throwable $error) { /* lockout check unavailable */ }
            // Latest device and number of active sessions per account (needs database/019_login_sessions.sql).
            try {
                $latest = $pdo->query('SELECT s.user_id, s.device, s.ip FROM login_sessions s
                    JOIN (SELECT user_id, MAX(id) AS last_id FROM login_sessions GROUP BY user_id) m ON m.last_id = s.id')->fetchAll();
                $active = $pdo->query('SELECT user_id, COUNT(*) AS n FROM login_sessions
                    WHERE ended_at IS NULL AND last_seen_at > (NOW() - INTERVAL ' . SESSION_IDLE_SECONDS . ' SECOND) GROUP BY user_id')->fetchAll();
                $byUser = array_column($latest, null, 'user_id');
                $activeBy = array_column($active, 'n', 'user_id');
                foreach ($rows as &$row) {
                    $row['lastDevice'] = $byUser[$row['id']]['device'] ?? null;
                    $row['lastIp'] = $byUser[$row['id']]['ip'] ?? null;
                    $row['activeSessions'] = (int)($activeBy[$row['id']] ?? 0);
                }
                unset($row);
            } catch (Throwable $error) {
                foreach ($rows as &$row) { $row['sessionsUnavailable'] = 1; }
                unset($row);
            }
            // Which accounts use the authenticator app (needs database/021_authenticator.sql).
            try {
                $withApp = array_flip(array_map('intval', $pdo->query('SELECT id FROM users WHERE totp_secret IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)));
                foreach ($rows as &$row) { $row['totpEnabled'] = isset($withApp[(int)$row['id']]); }
                unset($row);
            } catch (Throwable $error) { /* 021 not run yet */ }
            jsonResponse(['records' => $rows]);
        }
        if ($view === 'sessions') {
            $userId = (int)($_GET['id'] ?? 0);
            try {
                $list = $pdo->prepare("SELECT id, device, ip, user_agent AS userAgent,
                        DATE_FORMAT(created_at, '%Y-%m-%d %h:%i %p') AS started, DATE_FORMAT(last_seen_at, '%Y-%m-%d %h:%i %p') AS lastActive,
                        DATE_FORMAT(ended_at, '%Y-%m-%d %h:%i %p') AS ended, end_reason AS endReason,
                        (ended_at IS NULL AND last_seen_at > (NOW() - INTERVAL " . SESSION_IDLE_SECONDS . " SECOND)) AS active
                    FROM login_sessions WHERE user_id = ? ORDER BY id DESC LIMIT 30");
                $list->execute([$userId]);
                $records = $list->fetchAll();
            } catch (Throwable $error) {
                jsonResponse(['records' => [], 'ready' => false]);
            }
            $current = (int)($_SESSION['login_session_id'] ?? 0);
            foreach ($records as &$record) {
                $record['active'] = (int)$record['active'] === 1;
                $record['current'] = (int)$record['id'] === $current;
                $record['status'] = $record['active'] ? 'Active'
                    : ($record['endReason'] ? ucfirst((string)$record['endReason']) : 'Timed out');
            }
            unset($record);
            jsonResponse(['records' => $records, 'ready' => true]);
        }
        if ($view === 'accepted-applications') {
            $rows = $pdo->query("SELECT a.id, a.full_name AS name, a.email, a.position_title AS program, v.department FROM applications a LEFT JOIN vacancies v ON v.id = a.vacancy_id WHERE a.status = 'Hired' AND a.archived = 0 AND NOT EXISTS (SELECT 1 FROM users u WHERE LOWER(u.email) = LOWER(a.email)) ORDER BY a.created_at DESC, a.id DESC LIMIT 500")->fetchAll();
            jsonResponse(['records' => $rows]);
        }
        if ($view === 'audit') {
            $query = '%' . trim((string)($_GET['q'] ?? '')) . '%';
            $statement = $pdo->prepare("SELECT * FROM (
                SELECT a.id, a.action, a.details, a.ip_address AS ip, DATE_FORMAT(a.created_at, '%Y-%m-%d %H:%i:%s') AS created, u.name AS actor, u.email AS actorEmail
                FROM super_admin_audit a LEFT JOIN users u ON u.id = a.actor_id
                UNION ALL
                SELECT l.id, IF(l.success = 1, 'login-success', 'login-failed'), CONCAT(IF(l.success = 1, 'Successful login for ', 'Failed login for '), l.email), l.ip, DATE_FORMAT(l.created_at, '%Y-%m-%d %H:%i:%s'), COALESCE(u.name, l.email), l.email
                FROM login_attempts l LEFT JOIN users u ON u.email = l.email
            ) AS activity
            WHERE action LIKE ? OR details LIKE ? OR actor LIKE ? OR actorEmail LIKE ? OR ip LIKE ?
            ORDER BY created DESC, id DESC LIMIT 500");
            $statement->execute([$query, $query, $query, $query, $query]);
            jsonResponse(['records' => $statement->fetchAll()]);
        }
        jsonResponse(['error' => 'Unknown Super Admin view.'], 422);
    }

    if ($method !== 'POST') {
        return;
    }
    $body = requestBody();
    $action = (string)($body['action'] ?? '');
    if ($action === 'announcement-create') {
        $title = trim((string)($body['title'] ?? ''));
        $text = trim((string)($body['body'] ?? ''));
        $audience = (string)($body['audience'] ?? 'all');
        if ($title === '' || mb_strlen($title) > 150 || mb_strlen($text) > 1000 || !in_array($audience, ['all', 'admin', 'employee', 'trainee'], true)) {
            jsonResponse(['error' => 'Enter a title (150 characters max), a message (1000 max), and choose who sees it.'], 422);
        }
        $pdo->prepare('INSERT INTO announcements (title, body, audience, created_by) VALUES (?, ?, ?, ?)')->execute([$title, $text ?: null, $audience, $user['id']]);
        writeSuperAdminAudit($pdo, $user, 'announcement-posted', null, "Posted announcement \"{$title}\" for {$audience}.");
        jsonResponse(['id' => (int)$pdo->lastInsertId(), 'message' => 'Announcement posted.'], 201);
    }
    if ($action === 'announcement-archive' || $action === 'announcement-restore') {
        $announcementId = (int)($body['id'] ?? 0);
        $archive = $action === 'announcement-archive';
        $pdo->prepare('UPDATE announcements SET archived = ? WHERE id = ?')->execute([$archive ? 1 : 0, $announcementId]);
        writeSuperAdminAudit($pdo, $user, $archive ? 'announcement-archived' : 'announcement-restored', null, ($archive ? 'Archived' : 'Restored') . " announcement #{$announcementId}.");
        jsonResponse(['message' => $archive ? 'Announcement archived.' : 'Announcement restored.']);
    }

    $allowedRoles = ['HR1', 'HR3', 'ADMIN', 'HR_OFFICER', 'HR_STAFF', 'EMPLOYEE', 'TRAINEE'];

    if ($action === 'create-account') {
        $password = (string)($body['password'] ?? '');
        $role = (string)($body['role'] ?? '');
        $department = trim((string)($body['department'] ?? ''));
        $position = trim((string)($body['position'] ?? ''));
        $applicationId = (int)($body['application_id'] ?? 0);
        if ($role === 'TRAINEE') {
            $accepted = $pdo->prepare("SELECT a.full_name AS name, a.email, a.position_title, v.department FROM applications a LEFT JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ? AND a.status = 'Hired' AND a.archived = 0 LIMIT 1");
            $accepted->execute([$applicationId]);
            $applicant = $accepted->fetch();
            if (!$applicant) {
                jsonResponse(['error' => 'Select an active accepted application before creating a trainee account.'], 422);
            }
            $name = (string)$applicant['name'];
            $email = strtolower((string)$applicant['email']);
            $department = $department ?: trim((string)($applicant['department'] ?? ''));
            $position = $position ?: trim((string)($applicant['position_title'] ?? ''));
        } else {
            $name = trim((string)($body['name'] ?? ''));
            $email = strtolower(trim((string)($body['email'] ?? '')));
        }
        if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !in_array($role, $allowedRoles, true) || mb_strlen($department) > 100 || mb_strlen($position) > 100) {
            jsonResponse(['error' => 'Enter a name, valid email, and supported role.'], 422);
        }
        if ($problem = passwordProblem($password, $email)) {
            jsonResponse(['error' => $problem], 422);
        }
        $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ((int)$exists->fetchColumn() > 0) {
            jsonResponse(['error' => 'An account with this email already exists.'], 409);
        }
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, department, position, is_active, approval) VALUES (?, ?, ?, ?, ?, ?, 1, 'approved')");
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $department ?: null, $position ?: null]);
            $id = (int)$pdo->lastInsertId();
            $addedToDirectory = $role === 'EMPLOYEE';
            if ($role === 'EMPLOYEE') ensureEmployeeProfile($pdo, $id, $body);
            if ($role === 'TRAINEE') ensureTraineeTraining($pdo, $id, $body);
            // HR Officer / HR Staff / Admin are hospital employees too: add them to the Employee Directory when asked.
            if (in_array($role, ['HR_OFFICER', 'HR_STAFF', 'ADMIN', 'HR1', 'HR3'], true) && !empty($body['add_employee'])) {
                ensureEmployeeProfile($pdo, $id, $body, true);
                $addedToDirectory = true;
            }
            writeSuperAdminAudit($pdo, $user, 'account-created', $id, "Created {$role} account for {$email}.");
            $pdo->commit();
            if ($role === 'TRAINEE') pushOnTraineeCreated($pdo, $id);     // HR3 gets the trainee account
            if ($role === 'EMPLOYEE') pushOnEmployeeActivated($pdo, $id); // HR2 gets the employee information
            $roleLabel = ['HR_OFFICER' => 'HR Officer', 'HR_STAFF' => 'HR Staff', 'EMPLOYEE' => 'Employee', 'TRAINEE' => 'Trainee', 'ADMIN' => 'Admin'][$role] ?? $role;
            jsonResponse(['id' => $id, 'role' => $role, 'message' => "{$roleLabel} account created for {$name}" . ($addedToDirectory ? ' and added to the Employee Directory.' : '.')], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($action === 'backup') {
        // Ask for the Super Admin's own password again before handing out a full copy of the database.
        $hash = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $hash->execute([$user['id']]);
        if (!password_verify((string)($body['password'] ?? ''), (string)$hash->fetchColumn())) {
            writeSuperAdminAudit($pdo, $user, 'backup-denied', null, 'Database backup refused: wrong password.');
            jsonResponse(['error' => 'Incorrect password. The backup was not created.'], 403);
        }
        @set_time_limit(120);
        $backup = buildDatabaseBackup($pdo);
        $fileName = 'hims-backup-' . date('Ymd-His') . '.sql';
        try {
            $pdo->prepare('INSERT INTO backup_history (created_by, file_name, size_bytes, table_count, row_count, database_mode) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$user['id'], $fileName, strlen($backup['sql']), $backup['tables'], $backup['rows'], dbInfo()['mode']]);
        } catch (PDOException $error) { /* history table missing: still give the backup */ }
        writeSuperAdminAudit($pdo, $user, 'backup-created', null, "Downloaded database backup {$fileName} ({$backup['tables']} tables, {$backup['rows']} rows).");
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $backup['sql'];
        exit;
    }

    $targetId = (int)($body['id'] ?? 0);
    $targetQuery = $pdo->prepare('SELECT id, email, role, is_active FROM users WHERE id = ? LIMIT 1');
    $targetQuery->execute([$targetId]);
    $target = $targetQuery->fetch();
    if (!$target) {
        jsonResponse(['error' => 'Account not found.'], 404);
    }

    if ($action === 'unlock') {
        $pdo->prepare('UPDATE users SET unlocked_at = NOW() WHERE id = ?')->execute([$targetId]);
        writeSuperAdminAudit($pdo, $user, 'account-unlocked', $targetId, "Unlocked {$target['email']} after failed sign-ins.");
        jsonResponse(['message' => 'Account unlocked.']);
    }

    if ($action === 'reset-2fa') { // lost phone: remove the authenticator app, the person uses email codes again
        try {
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?')->execute([$targetId]);
        } catch (Throwable $error) {
            jsonResponse(['error' => 'Run database/021_authenticator.sql in MySQL Workbench first.'], 500);
        }
        writeSuperAdminAudit($pdo, $user, 'authenticator-removed', $targetId, "Removed the authenticator app of {$target['email']}.");
        jsonResponse(['message' => 'Authenticator app removed. They will get sign-in codes by email.']);
    }

    if ($action === 'end-session') {
        $sessionId = (int)($body['session_id'] ?? 0);
        if ($sessionId === (int)($_SESSION['login_session_id'] ?? 0)) {
            jsonResponse(['error' => 'This is the device you are using now. Use Log out instead.'], 422);
        }
        try {
            $end = $pdo->prepare("UPDATE login_sessions SET ended_at = NOW(), end_reason = 'signed out by Super Admin' WHERE id = ? AND user_id = ? AND ended_at IS NULL");
            $end->execute([$sessionId, $targetId]);
        } catch (Throwable $error) {
            jsonResponse(['error' => 'Run database/019_login_sessions.sql in MySQL Workbench first.'], 500);
        }
        if ($end->rowCount() === 0) {
            jsonResponse(['error' => 'That session has already ended.'], 422);
        }
        writeSuperAdminAudit($pdo, $user, 'session-ended', $targetId, "Signed out a device of {$target['email']}.");
        jsonResponse(['message' => 'Device signed out.']);
    }

    if ($action === 'update-trainee-training') {
        if ($target['role'] !== 'TRAINEE') {
            jsonResponse(['error' => 'Training details can only be updated for trainee accounts.'], 422);
        }
        [$program, $startDate, $endDate, $status, $notes] = traineeTrainingValues($body);
        $pdo->beginTransaction();
        try {
            ensureTraineeTraining($pdo, $targetId);
            $pdo->prepare('UPDATE trainee_training SET program = ?, start_date = ?, expected_end_date = ?, status = ?, notes = ? WHERE user_id = ?')
                ->execute([$program, $startDate, $endDate, $status, $notes, $targetId]);
            writeSuperAdminAudit($pdo, $user, 'trainee-training-updated', $targetId, "Updated training details for {$target['email']}.");
            $pdo->commit();
            jsonResponse(['message' => 'Trainee training details updated.']);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($action === 'set-approval') {
        $approval = (string)($body['approval'] ?? '');
        if ($target['role'] !== 'EMPLOYEE' || !in_array($approval, ['approved', 'rejected'], true)) {
            jsonResponse(['error' => 'Only employee approvals can be changed to approved or rejected.'], 422);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET approval = ? WHERE id = ?')->execute([$approval, $targetId]);
            if ($approval === 'approved') ensureEmployeeProfile($pdo, $targetId);
            writeSuperAdminAudit($pdo, $user, 'employee-' . $approval, $targetId, ucfirst($approval) . " employee account {$target['email']}.");
            $pdo->commit();
            jsonResponse(['message' => 'Employee approval updated.']);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($action === 'set-active') {
        $active = !empty($body['active']);
        if (!$active && $targetId === (int)$user['id']) {
            jsonResponse(['error' => 'You cannot disable your own active session account.'], 422);
        }
        if (!$active && $target['role'] === 'SUPER_ADMIN') {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'SUPER_ADMIN' AND is_active = 1")->fetchColumn();
            if ($count <= 1) {
                jsonResponse(['error' => 'The last active Super Admin cannot be disabled.'], 422);
            }
        }
        $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $targetId]);
        if (!$active) { // a disabled account is signed out everywhere
            try { $pdo->prepare("UPDATE login_sessions SET ended_at = NOW(), end_reason = 'account disabled' WHERE user_id = ? AND ended_at IS NULL")->execute([$targetId]); }
            catch (Throwable $error) { /* table missing until 019 is run */ }
        }
        if ($target['role'] === 'EMPLOYEE') {
            $pdo->prepare('UPDATE employees SET status = ? WHERE user_id = ?')->execute([$active ? 'active' : 'inactive', $targetId]);
        }
        writeSuperAdminAudit($pdo, $user, $active ? 'account-enabled' : 'account-disabled', $targetId, ($active ? 'Enabled ' : 'Disabled ') . $target['email'] . '.');
        jsonResponse(['message' => 'Account status updated.']);
    }

    if ($action === 'set-role') {
        $role = (string)($body['role'] ?? '');
        if (!in_array($role, $allowedRoles, true)) {
            jsonResponse(['error' => 'Choose a supported role.'], 422);
        }
        if ($role === 'TRAINEE' && $target['role'] !== 'TRAINEE') {
            jsonResponse(['error' => 'Create a trainee account from a Hired application in the accepted-applicant workflow.'], 422);
        }
        if ($target['role'] === 'SUPER_ADMIN') {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'SUPER_ADMIN' AND is_active = 1")->fetchColumn();
            if ($count <= 1) {
                jsonResponse(['error' => 'The last active Super Admin cannot be demoted.'], 422);
            }
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
            if ($role === 'EMPLOYEE') ensureEmployeeProfile($pdo, $targetId);
            if ($role === 'TRAINEE') {
                ensureTraineeTraining($pdo, $targetId);
                $pdo->prepare("UPDATE employees SET status = 'inactive' WHERE user_id = ?")->execute([$targetId]);
            }
            writeSuperAdminAudit($pdo, $user, 'account-role-changed', $targetId, "Changed {$target['email']} role to {$role}.");
            $pdo->commit();
            if ($role === 'EMPLOYEE' && $target['role'] !== 'EMPLOYEE') pushOnEmployeeActivated($pdo, $targetId); // HR2
            jsonResponse(['message' => 'Account role updated.']);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($action === 'reset-password') {
        $password = (string)($body['password'] ?? '');
        if ($problem = passwordProblem($password, (string)$target['email'])) {
            jsonResponse(['error' => $problem], 422);
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
        writeSuperAdminAudit($pdo, $user, 'password-reset', $targetId, "Reset password for {$target['email']}.");
        jsonResponse(['message' => 'Password reset.']);
    }

    jsonResponse(['error' => 'Unknown account action.'], 422);
}

// HR Staff encode and process; HR Officers review and approve. The server enforces this too,
// so a blocked action fails even if someone bypasses the screens.
function enforceHrRoleRules(string $resource, string $method, array $user): void
{
    if ($method !== 'POST' || !in_array($user['role'], ['HR_STAFF', 'HR_OFFICER'], true)) {
        return;
    }
    $body = requestBody();
    $action = (string)($body['action'] ?? '');
    $deny = static fn(string $message) => jsonResponse(['error' => $message], 403);

    if ($user['role'] === 'HR_STAFF') {
        if ($resource === 'vacancies') {
            $deny('Hire orders are published, declined or archived by an HR Officer.');
        }
        if ($resource === 'applications') {
            $status = (string)($body['status'] ?? '');
            if ($action === 'status' && !in_array($status, ['Applied', 'Screening', 'Interview'], true)) {
                $deny('HR Staff can move applicants up to Interview. The final decision is made by an HR Officer.');
            }
            if ($action === 'archive' || $action === 'restore') {
                $deny('Only an HR Officer or Admin can archive or restore applicants.');
            }
        }
    }

    if ($user['role'] === 'HR_OFFICER') {
        // Officers review records; HR Staff encode them.
        if ($resource === 'sync' || in_array($resource, ['attendance', 'overtime', 'leaves', 'claims', 'rest-days'], true)) {
            $deny('HR Officers review records; HR Staff encode them.');
        }
        if ($resource === 'applications' && $action === 'create') {
            $deny('Applicants are added by HR Staff or Admin.');
        }
    }
}

// ---------------------------------------------------------------------
// ONBOARDING: checklist for every applicant HR marks Hired, until they become an Employee.
// HR Staff (and Admin) tick items; HR Officer (and Admin) approve completion.
// "account" and "training" are ticked automatically from the trainee account.
// ---------------------------------------------------------------------
const ONBOARDING_ITEMS = [
    'documents' => 'Pre-employment documents (NBI clearance, birth certificate, diploma, TOR)',
    'medical' => 'Medical exam and drug test',
    'government_ids' => 'SSS, PhilHealth, Pag-IBIG and TIN numbers on file',
    'contract' => 'Employment contract signed',
    'orientation' => 'Orientation attended',
    'id_uniform' => 'Hospital ID, uniform and locker issued',
    'account' => 'HIMS trainee account created',
    'training' => 'Training completed',
];
const ONBOARDING_AUTO_ITEMS = ['account', 'training'];

function onboardingSyncHired(PDO $pdo): void
{
    // Every active Hired application gets an onboarding record (only once).
    // CONVERT ... USING utf8mb4: older tables may use another character set (avoids "Illegal mix of collations").
    $pdo->exec("INSERT IGNORE INTO onboarding_records (application_id, email, name, position, department)
        SELECT a.id, LOWER(CONVERT(a.email USING utf8mb4)), CONVERT(a.full_name USING utf8mb4),
               CONVERT(a.position_title USING utf8mb4), CONVERT(v.department USING utf8mb4)
        FROM applications a LEFT JOIN vacancies v ON v.id = a.vacancy_id
        WHERE a.status = 'Hired' AND a.archived = 0 AND a.email IS NOT NULL AND a.email <> ''");
}

function onboardingList(PDO $pdo, ?string $email = null): array
{
    $sql = "SELECT r.id, r.application_id AS applicationId, r.email, r.name, r.position, r.department,
            DATE_FORMAT(r.start_date, '%Y-%m-%d') AS startDate, r.status, r.archived,
            DATE_FORMAT(r.created_at, '%Y-%m-%d') AS created, DATE_FORMAT(r.completed_at, '%Y-%m-%d %H:%i') AS completedAt,
            cu.name AS completedBy, u.id AS userId, u.role AS accountRole, t.status AS trainingStatus
        FROM onboarding_records r
        LEFT JOIN users u ON LOWER(CONVERT(u.email USING utf8mb4)) COLLATE utf8mb4_general_ci = r.email COLLATE utf8mb4_general_ci
        LEFT JOIN trainee_training t ON t.user_id = u.id
        LEFT JOIN users cu ON cu.id = r.completed_by"
        . ($email !== null ? ' WHERE r.email COLLATE utf8mb4_general_ci = ?' : '') . ' ORDER BY r.archived, r.status = \'Completed\', r.created_at DESC, r.id DESC LIMIT 500';
    $statement = $pdo->prepare($sql);
    $statement->execute($email !== null ? [strtolower($email)] : []);
    $records = $statement->fetchAll();
    if (!$records) {
        return [];
    }
    $ids = array_map(static fn($r) => (int)$r['id'], $records);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $items = $pdo->prepare("SELECT c.record_id, c.item_key, c.done, c.note, DATE_FORMAT(c.done_at, '%Y-%m-%d %H:%i') AS doneAt, u.name AS doneBy
        FROM onboarding_checklist c LEFT JOIN users u ON u.id = c.done_by WHERE c.record_id IN ($marks)");
    $items->execute($ids);
    $byRecord = [];
    foreach ($items->fetchAll() as $item) {
        $byRecord[(int)$item['record_id']][$item['item_key']] = $item;
    }
    foreach ($records as &$record) {
        $saved = $byRecord[(int)$record['id']] ?? [];
        $hasAccount = !empty($record['userId']);
        $trained = ($record['trainingStatus'] ?? '') === 'Completed' || in_array($record['accountRole'] ?? '', ['EMPLOYEE', 'HR_STAFF', 'HR_OFFICER', 'ADMIN', 'HR1', 'HR3'], true);
        $list = [];
        foreach (ONBOARDING_ITEMS as $key => $label) {
            $row = $saved[$key] ?? null;
            $auto = $key === 'account' ? $hasAccount : ($key === 'training' ? $trained : null);
            $list[] = [
                'key' => $key,
                'label' => $label,
                'auto' => $auto !== null,
                'done' => $auto !== null ? $auto : (bool)($row['done'] ?? false),
                'doneBy' => $auto !== null ? ($auto ? 'Automatic' : null) : ($row['doneBy'] ?? null),
                'doneAt' => $row['doneAt'] ?? null,
                'note' => $row['note'] ?? null,
            ];
        }
        $record['items'] = $list;
        $record['done'] = count(array_filter($list, static fn($i) => $i['done']));
        $record['total'] = count($list);
        $record['archived'] = (int)$record['archived'];
        unset($record['userId']);
    }
    unset($record);
    return $records;
}

function handleOnboarding(string $resource, string $method, PDO $pdo, array $user): void
{
    if ($resource !== 'onboarding') {
        return;
    }
    if ($method === 'GET') {
        try {
            onboardingSyncHired($pdo);
            jsonResponse(['records' => onboardingList($pdo)]);
        } catch (PDOException $error) {
            // Show the real MySQL message on the Onboarding page (HR-only), so problems are easy to fix.
            error_log('HIMS onboarding: ' . $error->getMessage());
            $code = (int)($error->errorInfo[1] ?? 0);
            jsonResponse(['error' => $code === 1146
                ? 'The onboarding tables are not in the database yet. Run database/015_onboarding.sql in phpMyAdmin.'
                : 'Onboarding query failed: ' . ($error->errorInfo[2] ?? $error->getMessage())], 500);
        }
    }
    if ($method !== 'POST') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }
    $body = requestBody();
    $action = (string)($body['action'] ?? '');
    $id = (int)($body['id'] ?? 0);
    $find = $pdo->prepare('SELECT id, email, name, status, archived FROM onboarding_records WHERE id = ? LIMIT 1');
    $find->execute([$id]);
    $record = $find->fetch();
    if (!$record) {
        jsonResponse(['error' => 'Onboarding record not found.'], 404);
    }
    $role = $user['role'];
    $canTick = in_array($role, ['HR1', 'HR3', 'ADMIN', 'HR_STAFF'], true);
    $canApprove = in_array($role, ['HR1', 'HR3', 'ADMIN', 'HR_OFFICER'], true);

    if ($action === 'tick') {
        if (!$canTick) jsonResponse(['error' => 'Checklist items are updated by HR Staff.'], 403);
        $key = (string)($body['key'] ?? '');
        $note = trim((string)($body['note'] ?? ''));
        if (!isset(ONBOARDING_ITEMS[$key]) || in_array($key, ONBOARDING_AUTO_ITEMS, true) || mb_strlen($note) > 255) {
            jsonResponse(['error' => 'Choose a valid checklist item.'], 422);
        }
        if ($record['status'] === 'Completed') jsonResponse(['error' => 'This onboarding is already completed. An HR Officer can reopen it.'], 422);
        $done = !empty($body['done']);
        $pdo->prepare('INSERT INTO onboarding_checklist (record_id, item_key, done, done_by, done_at, note) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE done = VALUES(done), done_by = VALUES(done_by), done_at = VALUES(done_at), note = COALESCE(VALUES(note), note)')
            ->execute([$id, $key, $done ? 1 : 0, $done ? $user['id'] : null, $done ? date('Y-m-d H:i:s') : null, $note !== '' ? $note : null]);
        jsonResponse(['message' => 'Checklist updated.']);
    }
    if ($action === 'start-date') {
        if (!$canTick && !$canApprove) jsonResponse(['error' => 'Your role cannot change the start date.'], 403);
        $date = trim((string)($body['date'] ?? ''));
        if ($date !== '' && !validDate($date)) jsonResponse(['error' => 'Enter a valid start date.'], 422);
        $pdo->prepare('UPDATE onboarding_records SET start_date = ? WHERE id = ?')->execute([$date ?: null, $id]);
        jsonResponse(['message' => 'Start date saved.']);
    }
    if ($action === 'complete') {
        if (!$canApprove) jsonResponse(['error' => 'Onboarding is approved as complete by an HR Officer.'], 403);
        $current = onboardingList($pdo, (string)$record['email']);
        $row = $current[0] ?? null;
        if (!$row || $row['done'] < $row['total']) {
            jsonResponse(['error' => 'Finish every checklist item before approving onboarding.'], 422);
        }
        $pdo->prepare("UPDATE onboarding_records SET status = 'Completed', completed_by = ?, completed_at = NOW() WHERE id = ?")->execute([$user['id'], $id]);
        pushOnOnboardingCompleted($pdo, (int)$id); // HR2 (employee) and HR3 (applicant)
        jsonResponse(['message' => 'Onboarding approved as complete.']);
    }
    if ($action === 'reopen') {
        if (!$canApprove) jsonResponse(['error' => 'Only an HR Officer or Admin can reopen onboarding.'], 403);
        $pdo->prepare("UPDATE onboarding_records SET status = 'In progress', completed_by = NULL, completed_at = NULL WHERE id = ?")->execute([$id]);
        jsonResponse(['message' => 'Onboarding reopened.']);
    }
    if ($action === 'archive' || $action === 'restore') {
        if (!$canApprove) jsonResponse(['error' => 'Only an HR Officer or Admin can archive onboarding records.'], 403);
        $pdo->prepare('UPDATE onboarding_records SET archived = ? WHERE id = ?')->execute([$action === 'archive' ? 1 : 0, $id]);
        jsonResponse(['message' => $action === 'archive' ? 'Onboarding archived.' : 'Onboarding restored.']);
    }
    jsonResponse(['error' => 'Unknown onboarding action.'], 422);
}

function handleEmployeePortal(string $method, PDO $pdo, array $user): void
{
    $profileQuery = $pdo->prepare("SELECT e.id, e.employee_code AS employeeCode, u.name, u.email, COALESCE(e.department, u.department) AS department, COALESCE(e.position, u.position) AS position, e.contact, e.hire_date AS hireDate, e.emergency_contact AS emergency, e.salary, e.status FROM employees e JOIN users u ON u.id = e.user_id WHERE u.id = ? AND u.role = 'EMPLOYEE' AND u.is_active = 1 AND u.approval = 'approved' LIMIT 1");
    $profileQuery->execute([$user['id']]);
    $profile = $profileQuery->fetch();
    if (!$profile) {
        jsonResponse(['error' => 'Your employee profile has not been provisioned. Contact HR.'], 404);
    }
    $employeeId = (int)$profile['id'];
    $userId = (int)$user['id'];

    if ($method === 'GET') {
        $leaves = $pdo->prepare("SELECT l.id, lt.name AS type, DATE_FORMAT(l.start_date, '%Y-%m-%d') AS start, DATE_FORMAT(l.end_date, '%Y-%m-%d') AS end, l.notes AS reason, CONCAT(UPPER(LEFT(l.status, 1)), SUBSTRING(l.status, 2)) AS status FROM leaves l JOIN leave_types lt ON lt.id = l.leave_type_id WHERE l.user_id = ? ORDER BY l.created_at DESC");
        $leaves->execute([$userId]);
        $attendance = $pdo->prepare("SELECT e.employee_code AS id, DATE_FORMAT(a.work_date, '%Y-%m-%d') AS date, TIME_FORMAT(a.time_in, '%H:%i:%s') AS timeIn, TIME_FORMAT(a.time_out, '%H:%i:%s') AS timeOut FROM attendance_records a JOIN employees e ON e.id = a.employee_id WHERE e.id = ? ORDER BY a.work_date DESC LIMIT 500");
        $attendance->execute([$employeeId]);
        $overtime = $pdo->prepare("SELECT e.employee_code AS id, DATE_FORMAT(COALESCE(o.work_date, o.date), '%Y-%m-%d') AS date, o.hours, o.status FROM overtime_requests o LEFT JOIN employees e ON e.id = o.employee_id WHERE o.user_id = ? OR o.employee_id = ? ORDER BY COALESCE(o.work_date, o.date) DESC LIMIT 500");
        $overtime->execute([$userId, $employeeId]);
        $reviews = $pdo->prepare("SELECT id, period, ratings, average AS avg, comments AS comment, DATE_FORMAT(created_at, '%Y-%m-%d') AS date FROM employee_performance_reviews WHERE user_id = ? ORDER BY created_at, id");
        $reviews->execute([$userId]);
        $reviewRows = $reviews->fetchAll();
        foreach ($reviewRows as &$review) {
            $review['ratings'] = json_decode((string)$review['ratings'], true) ?: [];
            $review['avg'] = (float)$review['avg'];
        }
        unset($review);
        $goals = $pdo->prepare('SELECT id AS gid, goal AS text, progress FROM employee_goals WHERE user_id = ? AND archived = 0 ORDER BY id');
        $goals->execute([$userId]);
        $departments = $pdo->query("SELECT COALESCE(NULLIF(department, ''), 'Unassigned') AS department, COUNT(*) AS total, SUM(status = 'active') AS active FROM employees GROUP BY department ORDER BY department")->fetchAll();
        $profile['id'] = $profile['employeeCode'];
        $profile['status'] = ucfirst(str_replace('_', ' ', (string)$profile['status']));
        $profile['salary'] = (float)$profile['salary'];
        jsonResponse([
            'profile' => $profile,
            'leaves' => $leaves->fetchAll(),
            'attendance' => $attendance->fetchAll(),
            'requests' => $overtime->fetchAll(),
            'reviews' => $reviewRows,
            'goals' => $goals->fetchAll(),
            'employeeCount' => (int)$pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn(),
            'leaveQuotas' => leaveQuotas(),
            'departmentCounts' => $departments,
        ]);
    }

    if ($method !== 'POST') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }
    $body = requestBody();
    $action = (string)($body['action'] ?? '');

    if ($action === 'leave-create') {
        $type = trim((string)($body['type'] ?? ''));
        $start = (string)($body['start'] ?? '');
        $end = (string)($body['end'] ?? '');
        $reason = trim((string)($body['reason'] ?? ''));
        if (!validDate($start) || !validDate($end) || $end < $start || mb_strlen($reason) > 1000) {
            jsonResponse(['error' => 'Enter valid leave dates and a reason under 1000 characters.'], 422);
        }
        $quotas = leaveQuotas(); // set on the Super Admin Settings page
        if (!isset($quotas[$type])) {
            jsonResponse(['error' => 'Choose a valid leave type.'], 422);
        }
        $typeQuery = $pdo->prepare('SELECT id FROM leave_types WHERE name = ? LIMIT 1');
        $typeQuery->execute([$type]);
        $typeId = $typeQuery->fetchColumn();
        if (!$typeId) {
            jsonResponse(['error' => 'Leave type is not configured.'], 422);
        }
        $used = $pdo->prepare("SELECT COALESCE(SUM(DATEDIFF(end_date, start_date) + 1), 0) FROM leaves WHERE user_id = ? AND leave_type_id = ? AND status <> 'rejected'");
        $used->execute([$userId, $typeId]);
        $requestedDays = (int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
        if ((int)$used->fetchColumn() + $requestedDays > $quotas[$type]) {
            jsonResponse(['error' => 'This request exceeds your remaining leave balance.'], 422);
        }
        $pdo->prepare("INSERT INTO leaves (user_id, leave_type_id, start_date, end_date, notes, status) VALUES (?, ?, ?, ?, ?, 'pending')")
            ->execute([$userId, $typeId, $start, $end, $reason ?: null]);
        jsonResponse(['message' => 'Leave request submitted.'], 201);
    }

    if ($action === 'review-create') {
        $period = trim((string)($body['period'] ?? ''));
        $ratings = $body['ratings'] ?? null;
        $comment = trim((string)($body['comment'] ?? ''));
        if (!preg_match('/^\d{4} H[12]$/', $period) || !is_array($ratings) || count($ratings) !== 6 || mb_strlen($comment) > 1000) {
            jsonResponse(['error' => 'Provide a valid review period, six ratings, and comments under 1000 characters.'], 422);
        }
        foreach ($ratings as $rating) {
            if (!is_numeric($rating) || (int)$rating < 1 || (int)$rating > 5) {
                jsonResponse(['error' => 'Each competency rating must be from 1 to 5.'], 422);
            }
        }
        $ratings = array_map('intval', array_values($ratings));
        $average = array_sum($ratings) / count($ratings);
        try {
            $pdo->prepare('INSERT INTO employee_performance_reviews (user_id, period, ratings, average, comments) VALUES (?, ?, ?, ?, ?)')
                ->execute([$userId, $period, json_encode($ratings), $average, $comment ?: null]);
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1062) {
                jsonResponse(['error' => 'You already submitted an assessment for this period.'], 409);
            }
            throw $error;
        }
        jsonResponse(['message' => 'Assessment submitted.'], 201);
    }

    if ($action === 'goal-create') {
        $goal = trim((string)($body['text'] ?? ''));
        if ($goal === '' || mb_strlen($goal) > 300) {
            jsonResponse(['error' => 'Goal must be between 1 and 300 characters.'], 422);
        }
        $pdo->prepare('INSERT INTO employee_goals (user_id, goal, progress) VALUES (?, ?, 0)')->execute([$userId, $goal]);
        jsonResponse(['id' => (int)$pdo->lastInsertId(), 'message' => 'Goal added.'], 201);
    }

    if ($action === 'goal-update') {
        $goalId = (int)($body['id'] ?? 0);
        $progress = filter_var($body['progress'] ?? null, FILTER_VALIDATE_INT);
        if (!$goalId || $progress === false || $progress < 0 || $progress > 100) {
            jsonResponse(['error' => 'Goal progress must be between 0 and 100.'], 422);
        }
        $exists = $pdo->prepare('SELECT 1 FROM employee_goals WHERE id = ? AND user_id = ?');
        $exists->execute([$goalId, $userId]);
        if (!$exists->fetchColumn()) jsonResponse(['error' => 'Goal not found.'], 404);
        $statement = $pdo->prepare('UPDATE employee_goals SET progress = ? WHERE id = ? AND user_id = ?');
        $statement->execute([$progress, $goalId, $userId]);
        jsonResponse(['message' => 'Goal updated.']);
    }

    if ($action === 'goal-archive') {
        $statement = $pdo->prepare('UPDATE employee_goals SET archived = 1 WHERE id = ? AND user_id = ? AND archived = 0');
        $statement->execute([(int)($body['id'] ?? 0), $userId]);
        if (!$statement->rowCount()) jsonResponse(['error' => 'Goal not found.'], 404);
        jsonResponse(['message' => 'Goal archived.']);
    }

    jsonResponse(['error' => 'Unknown employee action.'], 422);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $resource = $_GET['resource'] ?? '';

    enforceAccountLock($resource, $method); // locked accounts cannot sign in
    handleAuth($resource, $method);

    handlePublicSettings($resource, $method); // hospital name and logo for the login page
    handlePublicHiring($resource, $method); // job list + apply: no login needed
    handleHireOrder($resource, $method);    // hire orders pushed by the HR3 system (needs the integration key)
    handleIntegrationFiles($resource, $method); // files sent to / picked up by HR2, HR3, HR4 (needs their integration key)
    handleIntegrationRequests($resource, $method); // requests sent by HR2, HR3, HR4 and their decisions (needs their integration key)

    if ($resource === 'change-password') {
        $passwordUser = requireLogin();
        handleChangePassword($resource, $method, db(), $passwordUser);
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }

    if ($resource === 'totp') { // any signed-in account can turn the authenticator app on or off for itself
        $totpUser = requireLogin();
        handleTotp($method, db(), $totpUser);
    }

    if ($resource === 'trainee-portal') {
        $trainee = requireLogin();
        if ($trainee['role'] !== 'TRAINEE') {
            jsonResponse(['error' => 'Trainee access required.'], 403);
        }
        if ($method !== 'GET') {
            jsonResponse(['error' => 'Method not allowed.'], 405);
        }
        $pdo = db();
        $trainingQuery = $pdo->prepare("SELECT program, DATE_FORMAT(start_date, '%Y-%m-%d') AS startDate, DATE_FORMAT(expected_end_date, '%Y-%m-%d') AS expectedEndDate, status, notes FROM trainee_training WHERE user_id = ? LIMIT 1");
        $trainingQuery->execute([$trainee['id']]);
        $training = $trainingQuery->fetch();
        if (!$training) {
            jsonResponse(['error' => 'Training details have not been set up. Contact your Super Admin.'], 404);
        }
        $announcements = $pdo->prepare("SELECT title, body, DATE_FORMAT(a.created_at, '%Y-%m-%d %H:%i') AS created, u.name AS author FROM announcements a LEFT JOIN users u ON u.id = a.created_by WHERE a.audience IN ('all', 'trainee') AND a.archived = 0 ORDER BY a.id DESC LIMIT 50");
        $announcements->execute();
        // Onboarding checklist for this trainee (empty until migration 010 is applied).
        try {
            $onboarding = onboardingList($pdo, (string)$trainee['email'])[0] ?? null;
        } catch (PDOException $error) {
            $onboarding = null;
        }
        jsonResponse(['user' => $trainee, 'training' => $training, 'announcements' => $announcements->fetchAll(), 'onboarding' => $onboarding]);
    }

    if ($resource === 'timekeeping' && (string)($_SESSION['user']['role'] ?? '') === 'EMPLOYEE') { // employee's own month for the payslip
        $timekeepingUser = requireLogin();
        if ($method !== 'GET' || ($_GET['view'] ?? '') !== 'my-month') {
            jsonResponse(['error' => 'HR access required.'], 403);
        }
        handleTimekeepingEmployee(db(), $timekeepingUser);
    }

    if ($resource === 'employee-portal') {
        $employeeUser = requireLogin();
        if ($employeeUser['role'] !== 'EMPLOYEE') {
            jsonResponse(['error' => 'Employee access required.'], 403);
        }
        handleEmployeePortal($method, db(), $employeeUser);
    }

    if ($resource === 'settings' || ($resource === 'departments' && $method === 'GET')) { // any signed-in role
        $settingsUser = requireLogin();
        try {
            handleSettings($resource, $method, db(), $settingsUser);
            handleDepartments($resource, $method, db(), $settingsUser);
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1146) jsonResponse(['error' => 'Run database/017_settings_departments.sql in phpMyAdmin to use this page.'], 500);
            throw $error;
        }
    }

    if ($resource === 'announcements' && $method === 'GET') { // any signed-in role sees the announcements meant for it
        $viewer = requireLogin();
        $audiences = match ($viewer['role']) {
            'EMPLOYEE' => ['all', 'employee'],
            'TRAINEE' => ['all', 'trainee'],
            'SUPER_ADMIN' => ['all', 'admin', 'employee', 'trainee'],
            default => ['all', 'admin'],
        };
        $marks = implode(',', array_fill(0, count($audiences), '?'));
        $list = db()->prepare("SELECT a.id, a.title, a.body, a.audience, a.archived, DATE_FORMAT(a.created_at, '%Y-%m-%d %H:%i') AS created, u.name AS author FROM announcements a LEFT JOIN users u ON u.id = a.created_by WHERE a.audience IN ($marks)" . ($viewer['role'] === 'SUPER_ADMIN' ? '' : ' AND a.archived = 0') . " ORDER BY a.id DESC LIMIT 50");
        $list->execute($audiences);
        jsonResponse(['records' => $list->fetchAll()]);
    }

    if ($resource === 'file-exchange') { // File Exchange page: HR Staff, HR Officer, Admin and Super Admin
        handleFileExchange($method, requireLogin());
    }
    if ($resource === 'shared-records') { // claims and ESS requests (HR roles; employees for their own ESS requests)
        handleSharedRecords($method, requireLogin());
    }
    if ($resource === 'hr-requests') { // HR Requests page: requests from HR2 / HR3 / HR4, decided by HR Officer or Admin
        handleHrRequests($method, requireLogin());
    }

    // HR Staff and HR Officer use the HR API like Admin (limited by enforceHrRoleRules below).
    // Every other role still goes through the original requireHr3() check in config.php.
    $signedInRole = (string)($_SESSION['user']['role'] ?? '');
    $user = in_array($signedInRole, ['HR_STAFF', 'HR_OFFICER'], true) ? requireLogin() : requireHr3();
    $pdo = db();
    enforceHrRoleRules($resource, $method, $user);
    handleSuperAdmin($resource, $method, $pdo, $user);
    handleOnboarding($resource, $method, $pdo, $user);
    handleTimekeeping($resource, $method, $pdo, $user);
    if ($resource === 'departments') {
        try { handleDepartments($resource, $method, $pdo, $user); }
        catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1146) jsonResponse(['error' => 'Run database/017_settings_departments.sql in phpMyAdmin to use this page.'], 500);
            throw $error;
        }
    }
    handleAdminAccounts($resource, $method, $pdo, $user);
    handleAdminHiring($resource, $method, $pdo, $user);

    if ($resource === 'sync' && $method === 'POST') {
        $body = requestBody();
        $kind = (string)($body['kind'] ?? '');
        $name = trim((string)($body['name'] ?? ''));
        $employeeCode = trim((string)($body['id'] ?? ''));
        if (!in_array($kind, ['attendance', 'overtime', 'leave', 'claim', 'restday'], true)) {
            jsonResponse(['error' => 'Unsupported record type.'], 422);
        }
        if (!$kind || !$employeeCode || !$name) {
            jsonResponse(['error' => 'Employee ID, name, and record type are required.'], 422);
        }

        $nameParts = preg_split('/\s+/', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '-';
        $employee = $pdo->prepare('SELECT id FROM employees WHERE employee_code = ? LIMIT 1');
        $employee->execute([$employeeCode]);
        $employeeId = $employee->fetchColumn();
        if (!$employeeId) {
            $insertEmployee = $pdo->prepare('INSERT INTO employees (employee_code, first_name, last_name) VALUES (?, ?, ?)');
            $insertEmployee->execute([$employeeCode, $firstName, $lastName]);
            $employeeId = $pdo->lastInsertId();
        }

        if ($kind === 'attendance') {
            if (!empty($body['timeOut'])) {
                $statement = $pdo->prepare('UPDATE attendance_records SET time_out = NOW() WHERE employee_id = ? AND time_out IS NULL ORDER BY id DESC LIMIT 1');
                $statement->execute([$employeeId]);
            } else {
                $statement = $pdo->prepare('INSERT INTO attendance_records (employee_id, work_date, time_in, created_by) VALUES (?, CURDATE(), NOW(), ?)');
                $statement->execute([$employeeId, $user['id']]);
            }
        } elseif ($kind === 'overtime') {
            $statement = $pdo->prepare('INSERT INTO overtime_requests (employee_id, work_date, hours, submitted_by) VALUES (?, CURDATE(), ?, ?)');
            $statement->execute([$employeeId, $body['hours'], $user['id']]);
        } elseif ($kind === 'leave') {
            $type = $pdo->prepare('SELECT id FROM leave_types WHERE name = ? LIMIT 1');
            $type->execute([(string)($body['type'] ?? 'Vacation Leave')]);
            $typeId = $type->fetchColumn() ?: 1;
            $statement = $pdo->prepare('INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, submitted_by) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$employeeId, $typeId, $body['start'], $body['end'], $user['id']]);
        } elseif ($kind === 'claim') {
            $statement = $pdo->prepare('INSERT INTO claims (employee_id, amount, submitted_by) VALUES (?, ?, ?)');
            $statement->execute([$employeeId, $body['amount'], $user['id']]);
        } elseif ($kind === 'restday') {
            $statement = $pdo->prepare('INSERT IGNORE INTO rest_days (employee_id, rest_date, created_by) VALUES (?, ?, ?)');
            $statement->execute([$employeeId, $body['date'], $user['id']]);
        } else {
            jsonResponse(['error' => 'Unsupported record type.'], 422);
        }
        jsonResponse(['message' => 'Record synchronized successfully.'], 201);
    }

    if ($resource === 'sync' && $method === 'GET') {
        $kind = (string)($_GET['kind'] ?? '');
        $queries = [
            'attendance' => "SELECT e.employee_code AS id, CONCAT(e.first_name, ' ', e.last_name) AS name, DATE_FORMAT(a.work_date, '%Y-%m-%d') AS date, DATE_FORMAT(a.time_in, '%H:%i:%s') AS timeIn, DATE_FORMAT(a.time_out, '%H:%i:%s') AS timeOut FROM attendance_records a JOIN employees e ON e.id = a.employee_id ORDER BY a.work_date DESC, a.id DESC LIMIT 500",
            'overtime' => "SELECT o.id AS rowId, e.employee_code AS id, CONCAT(e.first_name, ' ', e.last_name) AS name, o.hours, o.status FROM overtime_requests o JOIN employees e ON e.id = o.employee_id ORDER BY o.work_date DESC, o.id DESC LIMIT 500",
            'leave' => "SELECT e.employee_code AS id, CONCAT(e.first_name, ' ', e.last_name) AS name, lt.name AS type, DATE_FORMAT(l.start_date, '%Y-%m-%d') AS start, DATE_FORMAT(l.end_date, '%Y-%m-%d') AS end FROM leave_requests l JOIN employees e ON e.id = l.employee_id JOIN leave_types lt ON lt.id = l.leave_type_id ORDER BY l.start_date DESC, l.id DESC LIMIT 500",
            'claim' => "SELECT e.employee_code AS id, CONCAT(e.first_name, ' ', e.last_name) AS name, c.amount, c.status, DATE_FORMAT(c.created_at, '%Y-%m-%d %H:%i:%s') AS date FROM claims c JOIN employees e ON e.id = c.employee_id ORDER BY c.created_at DESC LIMIT 500",
            'restday' => "SELECT e.employee_code AS id, CONCAT(e.first_name, ' ', e.last_name) AS name, DATE_FORMAT(r.rest_date, '%Y-%m-%d') AS date FROM rest_days r JOIN employees e ON e.id = r.employee_id ORDER BY r.rest_date DESC LIMIT 500",
        ];
        if (!isset($queries[$kind])) {
            jsonResponse(['error' => 'Unsupported record type.'], 422);
        }
        jsonResponse(['records' => $pdo->query($queries[$kind])->fetchAll()]);
    }

    if ($resource === 'employees' && $method === 'GET') {
        $search = trim((string)($_GET['search'] ?? ''));
        $statement = $pdo->prepare(
            'SELECT * FROM employees WHERE employee_code LIKE ? OR first_name LIKE ? OR last_name LIKE ? ORDER BY last_name, first_name LIMIT 100'
        );
        $term = "%{$search}%";
        $statement->execute([$term, $term, $term]);
        jsonResponse(['records' => $statement->fetchAll()]);
    }

    $tables = [
        'attendance' => ['table' => 'attendance_records', 'columns' => 'employee_id, work_date, time_in, time_out, notes'],
        'overtime' => ['table' => 'overtime_requests', 'columns' => 'employee_id, work_date, hours, notes'],
        'leaves' => ['table' => 'leave_requests', 'columns' => 'employee_id, leave_type_id, start_date, end_date, notes'],
        'claims' => ['table' => 'claims', 'columns' => 'employee_id, amount, description'],
        'rest-days' => ['table' => 'rest_days', 'columns' => 'employee_id, rest_date, notes'],
    ];

    if (isset($tables[$resource])) {
        $definition = $tables[$resource];
        if ($method === 'GET') {
            $statement = $pdo->query("SELECT * FROM {$definition['table']} ORDER BY created_at DESC LIMIT 500");
            jsonResponse(['records' => $statement->fetchAll()]);
        }
        if ($method === 'POST') {
            $body = requestBody();
            $fields = array_map('trim', explode(',', $definition['columns']));
            foreach ($fields as $field) {
                if (!array_key_exists($field, $body) || $body[$field] === '') {
                    jsonResponse(['error' => "Missing field: {$field}."], 422);
                }
            }
            $fields[] = in_array($resource, ['leaves', 'overtime', 'claims'], true) ? 'submitted_by' : 'created_by';
            $values = array_map(static fn(string $field) => $body[$field] ?? $user['id'], $fields);
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $statement = $pdo->prepare("INSERT INTO {$definition['table']} (" . implode(', ', $fields) . ") VALUES ({$placeholders})");
            $statement->execute($values);
            jsonResponse(['id' => (int)$pdo->lastInsertId(), 'message' => 'Record created successfully.'], 201);
        }
    }

    if ($resource === 'notifications' && $method === 'GET') {
        $statement = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
        $statement->execute([$user['id']]);
        jsonResponse(['records' => $statement->fetchAll()]);
    }

    jsonResponse(['error' => 'Unknown API resource or method.'], 404);
} catch (Throwable $error) {
    error_log($error->getMessage());
    if ($error instanceof PDOException) {
        $driverCode = (int)($error->errorInfo[1] ?? $error->getCode());
        if (in_array($driverCode, [1044, 1045, 1049, 2002, 2005, 2006], true)) {
            if (dbInfo()['mode'] === 'cloud') {
                jsonResponse(['error' => 'Cloud database connection failed. Check backend/cloud-db.ini, the cloud password, and that Remote MySQL allows your IP address in hostforge.'], 500);
            }
            jsonResponse(['error' => 'MySQL connection failed. Check the hr3_app password, permissions, and PDO MySQL extension.'], 500);
        }
        if ($driverCode === 1054) {
            if (stripos($error->getMessage(), "unknown column 'name'") !== false || stripos($error->getMessage(), 'unknown column `name`') !== false) {
                jsonResponse(['error' => "The connected hr3_system.users table is missing the required 'name' column. database/schema.sql does not update existing tables; inspect or repair this table with a database administrator before running migration 005."], 500);
            }
            jsonResponse(['error' => 'Database columns are missing (MySQL 1054). Apply database/002_security.sql if it has not already run, then database/005_super_admin.sql.'], 500);
        }
        if ($driverCode === 1146) {
            jsonResponse(['error' => 'A database table is missing (MySQL 1146). Apply database/schema.sql and the required migrations that have not already run.'], 500);
        }
        jsonResponse(['error' => 'Database query failed. Check the PHP server terminal for the exact SQL error. Verify database/schema.sql and required migrations 001_hiring.sql through 005_super_admin.sql are applied. Do not rerun one-time migrations that already succeeded.'], 500);
    }
    jsonResponse(['error' => 'PHP backend error. Check the PHP server terminal.'], 500);
}

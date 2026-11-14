<?php
declare(strict_types=1);

// Philippine time for PHP. Without this, PHP uses whatever the computer or host is set to (it was Europe/Berlin),
// which made times disagree with MySQL. Change it with the HR3_TIMEZONE environment variable if needed.
date_default_timezone_set(getenv('HR3_TIMEZONE') ?: 'Asia/Manila');

// Database: LOCAL (XAMPP MySQL on this computer) or CLOUD (hostforge MySQL).
// The mode is chosen when the server starts (HR3_DB_MODE). Cloud host, port, database and user are read
// from backend/cloud-db.ini; the cloud password is never stored in a file, it is typed when HIMS starts.
function dbSettings(): array
{
    $mode = strtolower((string)(getenv('HR3_DB_MODE') ?: 'local')) === 'cloud' ? 'cloud' : 'local';
    if ($mode === 'cloud') {
        $ini = is_file(__DIR__ . '/cloud-db.ini') ? (parse_ini_file(__DIR__ . '/cloud-db.ini') ?: []) : [];
        return [
            'mode' => 'cloud',
            'host' => getenv('HR3_CLOUD_DB_HOST') ?: (string)($ini['host'] ?? ''),
            'port' => getenv('HR3_CLOUD_DB_PORT') ?: (string)($ini['port'] ?? '3306'),
            'name' => getenv('HR3_CLOUD_DB_NAME') ?: (string)($ini['database'] ?? ''),
            'user' => getenv('HR3_CLOUD_DB_USER') ?: (string)($ini['user'] ?? ''),
            'password' => (string)(getenv('HR3_CLOUD_DB_PASSWORD') ?: ''),
        ];
    }
    return [
        'mode' => 'local',
        'host' => getenv('HR3_DB_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1'),
        'port' => getenv('HR3_DB_PORT') ?: (getenv('DB_PORT') ?: '3306'),
        'name' => getenv('HR3_DB_NAME') ?: (getenv('DB_DATABASE') ?: 'hr3_system'),
        'user' => getenv('HR3_DB_USER') ?: (getenv('DB_USERNAME') ?: 'hr3_app'),
        'password' => (string)(getenv('HR3_DB_PASSWORD') ?: (getenv('DB_PASSWORD') ?: '')),
    ];
}

// Which database is actually in use (set by db()). Cloud mode falls back to local automatically.
$GLOBALS['HIMS_DB_ACTIVE'] = null;

// Safe to show on the Super Admin page: no password.
function dbInfo(): array
{
    $active = $GLOBALS['HIMS_DB_ACTIVE'] ?? null;
    if ($active) {
        return $active;
    }
    $s = dbSettings();
    return ['mode' => $s['mode'], 'host' => $s['host'], 'name' => $s['name'], 'fallback' => false];
}

function dbConnect(array $settings): PDO
{
    ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'password' => $password] = $settings;
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5, // an unreachable cloud server fails fast instead of hanging
            // MySQL uses the same clock as PHP, even on a cloud host set to another time zone.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . date('P') . "'",
        ]
    );
}

// Local settings, used directly in local mode and as the backup in cloud mode.
function localDbSettings(): array
{
    $saved = getenv('HR3_DB_MODE');
    putenv('HR3_DB_MODE=local');
    $settings = dbSettings();
    putenv($saved === false ? 'HR3_DB_MODE' : 'HR3_DB_MODE=' . $saved);
    return $settings;
}

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $settings = dbSettings();
    if ($settings['mode'] !== 'cloud') {
        $connection = dbConnect($settings);
        $GLOBALS['HIMS_DB_ACTIVE'] = ['mode' => 'local', 'host' => $settings['host'], 'name' => $settings['name'], 'fallback' => false];
        return $connection;
    }

    // Cloud first. If the cloud can't be reached, use the local database instead.
    // After a failure, skip the cloud for 60 seconds so every page doesn't wait for it.
    $downFlag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hims-cloud-db-down';
    $cloudRecentlyDown = is_file($downFlag) && time() - (int)@filemtime($downFlag) < 60;
    $cloudReady = $settings['host'] !== '' && $settings['name'] !== '' && $settings['user'] !== '';
    $reason = $cloudReady ? 'unreachable' : 'not set up';
    if ($cloudReady && !$cloudRecentlyDown) {
        try {
            $connection = dbConnect($settings);
            @unlink($downFlag);
            $GLOBALS['HIMS_DB_ACTIVE'] = ['mode' => 'cloud', 'host' => $settings['host'], 'name' => $settings['name'], 'fallback' => false];
            return $connection;
        } catch (PDOException $error) {
            error_log('HIMS cloud database unavailable, switching to local: ' . $error->getMessage());
            @touch($downFlag);
            $reason = 'unreachable';
        }
    }
    $local = localDbSettings();
    $connection = dbConnect($local);
    $GLOBALS['HIMS_DB_ACTIVE'] = ['mode' => 'local', 'host' => $local['host'], 'name' => $local['name'], 'fallback' => true, 'reason' => 'Cloud database ' . $reason];
    return $connection;
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function requestBody(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : $_POST;
}

function requireLogin(): array
{
    if (empty($_SESSION['user'])) {
        jsonResponse($GLOBALS['HIMS_SESSION_EXPIRED'] ?? false
            ? ['error' => 'You were signed out after 5 minutes of inactivity. Please log in again.', 'expired' => true]
            : ['error' => 'Authentication required.'], 401);
    }
    // The Super Admin can sign a device out from Accounts & Roles > Sessions.
    if (loginSessionEnded()) {
        $_SESSION = [];
        jsonResponse(['error' => 'This device was signed out by the Super Admin. Please log in again.', 'expired' => true], 401);
    }
    markUserSeen((int)$_SESSION['user']['id']);
    return $_SESSION['user'];
}

// ---------------------------------------------------------------- login sessions (devices)
// Each sign-in is saved in login_sessions (database/019_login_sessions.sql) with the device, IP and times.
// Every function here does nothing if that table has not been created yet.
function loginSessionEnded(): bool
{
    $id = (int)($_SESSION['login_session_id'] ?? 0);
    if ($id <= 0) {
        return false;
    }
    try {
        $query = db()->prepare('SELECT ended_at FROM login_sessions WHERE id = ? LIMIT 1');
        $query->execute([$id]);
        $ended = $query->fetchColumn();
        return $ended !== false && $ended !== null;
    } catch (Throwable $error) {
        return false;
    }
}

function endLoginSession(string $reason): void
{
    $id = (int)($_SESSION['login_session_id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    try {
        db()->prepare('UPDATE login_sessions SET ended_at = NOW(), end_reason = ? WHERE id = ? AND ended_at IS NULL')->execute([$reason, $id]);
    } catch (Throwable $error) { /* table missing until 019 is run */ }
}

// Saves when an account last used HIMS (at most once a minute), for "Online now" on the Super Admin page.
function markUserSeen(int $userId, bool $force = false): void
{
    if (!$force && time() - (int)($_SESSION['seen_saved'] ?? 0) < 60) {
        return;
    }
    $_SESSION['seen_saved'] = time();
    try {
        db()->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?')->execute([$userId]);
    } catch (Throwable $error) {
        // Column missing until database/018_online_status.sql is run: everything else keeps working.
    }
    if (!empty($_SESSION['login_session_id'])) {
        try { db()->prepare('UPDATE login_sessions SET last_seen_at = NOW() WHERE id = ?')->execute([(int)$_SESSION['login_session_id']]); }
        catch (Throwable $error) { /* table missing until 019 is run */ }
    }
}

// Roles that may use the HR API. SUPER_ADMIN is needed for the Super Admin pages (api.php checks
// this first, then requires SUPER_ADMIN for them). HR Staff and HR Officer are limited further in api.php.
const HR_ROLES = ['HR1', 'HR3', 'ADMIN', 'SUPER_ADMIN', 'HR_OFFICER', 'HR_STAFF'];

function requireHr3(): array
{
    $user = requireLogin();
    if (!in_array($user['role'], HR_ROLES, true)) {
        jsonResponse(['error' => 'HR3 access required.'], 403);
    }
    return $user;
}

// Use on actions only some HR roles may do, for example:
//   requireHrRole(['HR1', 'HR3', 'ADMIN', 'HR_OFFICER']);  // approvals and hiring decisions
function requireHrRole(array $roles): array
{
    $user = requireHr3();
    if (!in_array($user['role'], $roles, true)) {
        jsonResponse(['error' => 'Your role cannot do this. Ask an HR Officer or Admin.'], 403);
    }
    return $user;
}

// Block forged cross-site requests: browsers only send this custom header from our own pages.
function requireSameSiteHeader(): void
{
    if (($_SERVER['HTTP_X_HR3_REQUEST'] ?? '') !== '1') {
        jsonResponse(['error' => 'Invalid request.'], 403);
    }
}

// Cookie is marked Secure when the visitor is on HTTPS (for example through the Cloudflare tunnel).
function isHttps(): bool
{
    if (($_SERVER['HTTPS'] ?? '') === 'on') {
        return true;
    }
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    return $local && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

session_name('HR3SESSID');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => isHttps(),
    'use_strict_mode' => true,
    'use_only_cookies' => true,
]);

// Sign out after 5 minutes without activity (the pages also sign out on their own after 5 idle minutes).
const SESSION_IDLE_SECONDS = 300;
$GLOBALS['HIMS_SESSION_EXPIRED'] = false;
if (!empty($_SESSION['user']) && isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > SESSION_IDLE_SECONDS) {
    endLoginSession('timed out');
    $_SESSION = [];
    $GLOBALS['HIMS_SESSION_EXPIRED'] = true;
}
$_SESSION['last_seen'] = time();

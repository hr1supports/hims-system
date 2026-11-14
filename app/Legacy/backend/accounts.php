<?php
declare(strict_types=1);

// Accounts: login protection, employee sign-up, admin account management, password change.

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/totp.php';

const LOGIN_MAX_FAILS = 5;        // per email + connection, within the lock time (5 minutes by default)
const LOGIN_MAX_FAILS_IP = 30;    // per connection, within the lock time
const SIGNUP_MAX_PER_HOUR = 5;
const COMMON_PASSWORDS = ['password', 'password1', '12345678', 'qwerty123', 'hr3@admin12345', 'hr1@admin12345', 'superadmin@1234', 'employeeadmin@1234', 'mynewpassword123', 'hr3admin', 'admin12345'];

function passwordProblem(string $password, string $email = ''): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (strlen($password) > 72) {
        return 'Password must be 72 characters or fewer.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    if (in_array(strtolower($password), COMMON_PASSWORDS, true) || ($email !== '' && strtolower($password) === strtolower($email))) {
        return 'That password is too common. Choose a different one.';
    }
    return null;
}

function recordAttempt(PDO $pdo, string $kind, string $email, bool $success): void
{
    $pdo->prepare('INSERT INTO login_attempts (kind, email, ip, success) VALUES (?, ?, ?, ?)')
        ->execute([$kind, substr($email, 0, 190), clientIp(), $success ? 1 : 0]);
}

function destroySession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function sessionUser(array $row): array
{
    return [
        'id' => (int)$row['id'], 'name' => $row['name'], 'email' => $row['email'], 'role' => $row['role'],
        'department' => $row['department'] ?? null, 'position' => $row['position'] ?? null,
    ];
}

// $anyRole: also for HR Officer / HR Staff / Admin accounts that the Super Admin adds to the Employee Directory.
function ensureEmployeeProfile(PDO $pdo, int $userId, array $profile = [], bool $anyRole = false): int
{
    $userQuery = $pdo->prepare("SELECT id, name, email, department, position FROM users WHERE id = ?" . ($anyRole ? '' : " AND role = 'EMPLOYEE'") . ' LIMIT 1');
    $userQuery->execute([$userId]);
    $user = $userQuery->fetch();
    if (!$user) {
        throw new RuntimeException('Employee account was not found.');
    }

    $parts = preg_split('/\s+/', trim((string)$user['name']), 2);
    $firstName = $parts[0] ?? 'Employee';
    $lastName = $parts[1] ?? '-';
    $status = strtolower((string)($profile['status'] ?? 'active'));
    if ($status === 'on leave') $status = 'on_leave';
    if (!in_array($status, ['active', 'inactive', 'on_leave'], true)) $status = 'active';
    $hireDate = trim((string)($profile['hire_date'] ?? ''));
    if ($hireDate !== '' && !validDate($hireDate)) {
        jsonResponse(['error' => 'Hire date is not valid.'], 422);
    }
    $salary = $profile['salary'] ?? null;
    if ($salary !== null && (!is_numeric($salary) || (float)$salary < 0 || (float)$salary > 9999999999.99)) {
        jsonResponse(['error' => 'Salary must be a non-negative amount.'], 422);
    }

    $find = $pdo->prepare('SELECT id FROM employees WHERE user_id = ? OR email = ? ORDER BY (user_id = ?) DESC LIMIT 1');
    $find->execute([$userId, $user['email'], $userId]);
    $employeeId = $find->fetchColumn();
    $department = trim((string)($profile['department'] ?? $user['department'] ?? '')) ?: null;
    $position = trim((string)($profile['position'] ?? $user['position'] ?? '')) ?: null;
    $contact = trim((string)($profile['contact'] ?? '')) ?: null;
    $emergency = trim((string)($profile['emergency_contact'] ?? '')) ?: null;
    if (($contact !== null && mb_strlen($contact) > 50) || ($emergency !== null && mb_strlen($emergency) > 255)) {
        jsonResponse(['error' => 'Contact details are too long.'], 422);
    }

    if ($employeeId) {
        $update = $pdo->prepare('UPDATE employees SET user_id = ?, first_name = ?, last_name = ?, department = ?, position = ?, contact = COALESCE(?, contact), hire_date = COALESCE(?, hire_date), emergency_contact = COALESCE(?, emergency_contact), salary = COALESCE(?, salary), status = ? WHERE id = ?');
        $update->execute([$userId, $firstName, $lastName, $department, $position, $contact, $hireDate ?: null, $emergency, $salary === null ? null : (float)$salary, $status, (int)$employeeId]);
        return (int)$employeeId;
    }

    $insert = $pdo->prepare('INSERT INTO employees (employee_code, first_name, last_name, email, department, position, status, user_id, contact, hire_date, emergency_contact, salary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute(['USR-' . $userId, $firstName, $lastName, $user['email'], $department, $position, $status, $userId, $contact, $hireDate ?: null, $emergency, $salary === null ? 0 : (float)$salary]);
    return (int)$pdo->lastInsertId();
}

// "Chrome on Windows · Computer", "Safari on iPhone · Phone" ... from the browser's user-agent text.
function deviceLabel(string $ua): string
{
    $browser = match (true) {
        (bool)preg_match('/Edg\//i', $ua) => 'Edge',
        (bool)preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
        (bool)preg_match('/SamsungBrowser/i', $ua) => 'Samsung Internet',
        (bool)preg_match('/Firefox\//i', $ua) => 'Firefox',
        (bool)preg_match('/Chrome\/|CriOS/i', $ua) => 'Chrome',
        (bool)preg_match('/Safari\//i', $ua) => 'Safari',
        default => 'Unknown browser',
    };
    $os = match (true) {
        (bool)preg_match('/iPhone/i', $ua) => 'iPhone',
        (bool)preg_match('/iPad/i', $ua) => 'iPad',
        (bool)preg_match('/Android/i', $ua) => 'Android',
        (bool)preg_match('/Windows/i', $ua) => 'Windows',
        (bool)preg_match('/Mac OS X|Macintosh/i', $ua) => 'Mac',
        (bool)preg_match('/CrOS/i', $ua) => 'Chromebook',
        (bool)preg_match('/Linux/i', $ua) => 'Linux',
        default => 'unknown system',
    };
    $type = preg_match('/iPad|Tablet/i', $ua) || (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua)) ? 'Tablet'
        : (preg_match('/Mobile|iPhone|Android/i', $ua) ? 'Phone' : 'Computer');
    return "{$browser} on {$os} · {$type}";
}

function startLoginSession(PDO $pdo, int $userId): void
{
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    try {
        $pdo->prepare('INSERT INTO login_sessions (user_id, ip, user_agent, device) VALUES (?, ?, ?, ?)')
            ->execute([$userId, clientIp(), $ua, deviceLabel($ua)]);
        $_SESSION['login_session_id'] = (int)$pdo->lastInsertId();
    } catch (Throwable $error) {
        unset($_SESSION['login_session_id']); // table missing until 019 is run
    }
}

function finishLogin(PDO $pdo, array $user): never
{
    session_regenerate_id(true);
    $_SESSION['user'] = sessionUser($user) + ['is_active' => 1];
    startLoginSession($pdo, (int)$user['id']);
    $pdo->prepare("DELETE FROM login_attempts WHERE kind = 'login' AND success = 0 AND email = ? AND ip = ?")
        ->execute([$user['email'], clientIp()]);
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    $_SESSION['seen_saved'] = 0;
    markUserSeen((int)$user['id'], true);
    recordAttempt($pdo, 'login', $user['email'], true);
    jsonResponse(['user' => $_SESSION['user']]);
}


// ---------------------------------------------------------------- sign-in code (OTP)
// After the password is correct, a 6-digit code is sent to the account's email (or printed in the
// server window while no email account is set up). The person must enter it to finish signing in.
const OTP_CODE_SECONDS = 120;    // each code works for 2 minutes
const OTP_WINDOW_SECONDS = 300;  // 5 minutes in total to finish signing in, then start over
const OTP_MAX_TRIES = 5;         // wrong codes before starting over (each one also counts toward the account lock)
const OTP_RESEND_WAIT = 30;      // seconds between "send a new code" requests
const OTP_MAX_SENDS = 4;         // codes per sign-in

function maskEmail(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $shown = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));
    return $shown . str_repeat('*', max(3, mb_strlen($local) - mb_strlen($shown))) . ($domain !== '' ? '@' . $domain : '');
}

function otpHash(string $code): string
{
    return hash_hmac('sha256', $code, (string)($_SESSION['otp']['key'] ?? ''));
}

// Makes a new code for the sign-in in progress and sends it. Returns "email" or "terminal".
function issueLoginCode(): string
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['otp']['hash'] = otpHash($code);
    $_SESSION['otp']['sentAt'] = time();
    $_SESSION['otp']['expires'] = min(time() + OTP_CODE_SECONDS, (int)$_SESSION['otp']['windowEnds']);
    $_SESSION['otp']['sends'] = (int)($_SESSION['otp']['sends'] ?? 0) + 1;
    try {
        return sendLoginCodeMessage($_SESSION['otp']['email'], $_SESSION['otp']['name'], $code, intdiv(OTP_CODE_SECONDS, 60));
    } catch (Throwable $error) {
        error_log('[HIMS] Could not email the sign-in code: ' . $error->getMessage());
        unset($_SESSION['otp']);
        jsonResponse(['error' => 'We could not send your sign-in code. Try again in a moment or contact the Super Admin.', 'restart' => true], 503);
    }
}

function otpStatus(string $delivery): array
{
    $otp = $_SESSION['otp'];
    return [
        'otp_required' => true,
        'email' => maskEmail((string)$otp['email']),
        'delivery' => $delivery,
        'methods' => $otp['methods'] ?? ['email'],       // "email", and "app" when the authenticator is on
        'emailSent' => isset($otp['hash']),
        'codeExpiresIn' => max(0, (int)($otp['expires'] ?? 0) - time()),
        'windowEndsIn' => max(0, (int)$otp['windowEnds'] - time()),
        'resendIn' => isset($otp['sentAt']) ? max(0, (int)$otp['sentAt'] + OTP_RESEND_WAIT - time()) : 0,
    ];
}

function startLoginCode(array $user): never
{
    session_regenerate_id(true);
    unset($_SESSION['user']); // nobody is signed in until the code is entered
    $_SESSION['otp'] = [
        'uid' => (int)$user['id'], 'email' => (string)$user['email'], 'name' => (string)$user['name'],
        'key' => bin2hex(random_bytes(16)), 'windowEnds' => time() + OTP_WINDOW_SECONDS, 'tries' => 0, 'sends' => 0,
        'methods' => ['email'],
    ];
    // The person always chooses: email code, or the authenticator app on their phone (when they have turned it on).
    // The email code is only sent after they pick email (otp-resend).
    if (totpForUser(db(), (int)$user['id'])) {
        $_SESSION['otp']['methods'] = ['email', 'app'];
    }
    jsonResponse(otpStatus('choose'));
}

function restartLogin(string $message, int $status = 401): never
{
    unset($_SESSION['otp']);
    jsonResponse(['error' => $message, 'restart' => true], $status);
}

function handleLoginCode(string $resource, string $method): void
{
    if (!in_array($resource, ['otp-verify', 'otp-resend'], true)) {
        return;
    }
    if ($method !== 'POST') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }
    $otp = $_SESSION['otp'] ?? null;
    if (!$otp) {
        restartLogin('Your sign-in has expired. Enter your email and password again.');
    }
    if (time() > (int)$otp['windowEnds']) {
        restartLogin('Time is up. You have 5 minutes to enter the code. Enter your email and password again.');
    }

    if ($resource === 'otp-resend') { // also sends the first email code when the person chose email
        $wait = isset($otp['sentAt']) ? (int)$otp['sentAt'] + OTP_RESEND_WAIT - time() : 0;
        if ($wait > 0) {
            jsonResponse(['error' => "Please wait {$wait} seconds before asking for a new code."] + otpStatus('wait'), 429);
        }
        if ((int)$otp['sends'] >= OTP_MAX_SENDS) {
            restartLogin('Too many codes were requested. Enter your email and password again.', 429);
        }
        $delivery = issueLoginCode();
        jsonResponse(['message' => 'A new code was sent.'] + otpStatus($delivery));
    }

    // otp-verify
    $pdo = db();
    $body = requestBody();
    $code = preg_replace('/\D/', '', (string)($body['code'] ?? ''));
    $useApp = ($body['method'] ?? '') === 'app';
    $valid = false;
    if ($useApp) {
        $totp = in_array('app', $otp['methods'] ?? [], true) ? totpForUser($pdo, (int)$otp['uid']) : null;
        if (!$totp) {
            jsonResponse(['error' => 'The authenticator app is not set up for this account. Use the email code.'], 400);
        }
        $step = totpVerify((string)$totp['totp_secret'], $code, $totp['totp_last_step'] !== null ? (int)$totp['totp_last_step'] : null);
        if ($step !== null) {
            $pdo->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, (int)$otp['uid']]); // a code works only once
            $valid = true;
        }
    } else {
        if (!isset($otp['hash'])) {
            jsonResponse(['error' => 'Choose how to get your code first.'], 400);
        }
        if (time() > (int)$otp['expires']) {
            jsonResponse(['error' => 'This code has expired. Click "Send a new code".', 'expired' => true] + otpStatus('wait'), 400);
        }
        $valid = strlen($code) === 6 && hash_equals((string)$otp['hash'], otpHash($code));
    }
    if (!$valid) {
        $_SESSION['otp']['tries'] = (int)$otp['tries'] + 1;
        recordAttempt($pdo, 'login', (string)$otp['email'], false); // counts toward the account lock
        $left = OTP_MAX_TRIES - $_SESSION['otp']['tries'];
        if ($left <= 0) {
            restartLogin('Too many wrong codes. Enter your email and password again.');
        }
        jsonResponse(['error' => "Wrong code. {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.'], 400);
    }
    $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role, department, position, is_active, approval FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$otp['uid']]);
    $user = $stmt->fetch();
    unset($_SESSION['otp']);
    if (!$user || !$user['is_active'] || $user['approval'] !== 'approved') {
        jsonResponse(['error' => 'This account is not active. Contact HR.', 'restart' => true], 403);
    }
    finishLogin($pdo, $user);
}

// ---------------------------------------------------------------- forgot password (6-digit code)
// 1) POST ?resource=forgot-start  {email}            a 6-digit code is emailed (or printed in the server window)
// 2) POST ?resource=forgot-reset  {code, password}   the right code sets the new password
// The answer to step 1 is the same whether or not the email has an account, so nobody can use it to
// find out who has an account. A code works for 10 minutes; 5 wrong codes and the reset starts over.
const RESET_CODE_SECONDS = 600;
const RESET_MAX_TRIES = 5;
const RESET_RESEND_WAIT = 60;
const RESET_MAX_SENDS = 3;

function handleForgotPassword(string $resource, string $method): void
{
    if (!in_array($resource, ['forgot-start', 'forgot-reset'], true)) {
        return;
    }
    if ($method !== 'POST') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }
    $pdo = db();
    $body = requestBody();
    $generic = 'If that email has a HIMS account, a 6-digit code was sent to it. It works for 10 minutes.';

    if ($resource === 'forgot-start') {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            jsonResponse(['error' => 'Enter the email address of your HIMS account.'], 422);
        }
        $prev = $_SESSION['reset'] ?? null;
        if ($prev && ($prev['email'] ?? '') === $email) {
            $wait = (int)$prev['sentAt'] + RESET_RESEND_WAIT - time();
            if ($wait > 0) {
                jsonResponse(['error' => "Please wait {$wait} seconds before asking for a new code.", 'resendIn' => $wait], 429);
            }
            if ((int)$prev['sends'] >= RESET_MAX_SENDS) {
                jsonResponse(['error' => 'Too many codes were requested. Try again later or ask the Super Admin to reset your password.'], 429);
            }
        }
        // Same limit as sign-in: too many failures from this connection stop new requests for a while.
        $lockMinutes = function_exists('lockMinutes') ? lockMinutes() : 5;
        $any = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE kind = 'login' AND success = 0 AND ip = ? AND created_at > (NOW() - INTERVAL {$lockMinutes} MINUTE)");
        $any->execute([clientIp()]);
        if ((int)$any->fetchColumn() >= LOGIN_MAX_FAILS_IP) {
            jsonResponse(['error' => "Too many failed attempts. Try again in {$lockMinutes} minutes."], 429);
        }

        $stmt = $pdo->prepare('SELECT id, name, email, is_active, approval FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $sends = ($prev && ($prev['email'] ?? '') === $email) ? (int)$prev['sends'] + 1 : 1;
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $key = bin2hex(random_bytes(16));
        $_SESSION['reset'] = [
            'email' => $email, 'uid' => $user ? (int)$user['id'] : 0, 'key' => $key,
            'hash' => hash_hmac('sha256', $code, $key), 'expires' => time() + RESET_CODE_SECONDS,
            'sentAt' => time(), 'sends' => $sends, 'tries' => 0,
        ];
        $delivery = 'email';
        if ($user && $user['is_active'] && $user['approval'] === 'approved') {
            try {
                $delivery = sendLoginCodeMessage((string)$user['email'], (string)$user['name'], $code, intdiv(RESET_CODE_SECONDS, 60), 'reset');
            } catch (Throwable $error) {
                error_log('[HIMS] Could not email the password reset code: ' . $error->getMessage());
                unset($_SESSION['reset']);
                jsonResponse(['error' => 'We could not send the code right now. Try again in a moment or ask the Super Admin.'], 503);
            }
        } elseif (mailSettings() === null && !getenv('BREVO_API_KEY')) {
            $delivery = 'terminal'; // keep the same answer as for a real account
        }
        jsonResponse(['message' => $delivery === 'terminal'
            ? 'If that email has a HIMS account, its 6-digit code is in the server window (local mode). It works for 10 minutes.'
            : $generic, 'delivery' => $delivery, 'expiresIn' => RESET_CODE_SECONDS, 'resendIn' => RESET_RESEND_WAIT]);
    }

    // forgot-reset
    $reset = $_SESSION['reset'] ?? null;
    if (!$reset) {
        jsonResponse(['error' => 'Ask for a code first.', 'restart' => true], 400);
    }
    if (time() > (int)$reset['expires']) {
        unset($_SESSION['reset']);
        jsonResponse(['error' => 'The code has expired. Ask for a new one.', 'restart' => true], 400);
    }
    $code = preg_replace('/\D/', '', (string)($body['code'] ?? ''));
    $valid = strlen($code) === 6 && (int)$reset['uid'] > 0 && hash_equals((string)$reset['hash'], hash_hmac('sha256', $code, (string)$reset['key']));
    if (!$valid) {
        $_SESSION['reset']['tries'] = (int)$reset['tries'] + 1;
        recordAttempt($pdo, 'login', (string)$reset['email'], false); // counts toward the account lock
        $left = RESET_MAX_TRIES - $_SESSION['reset']['tries'];
        if ($left <= 0) {
            unset($_SESSION['reset']);
            jsonResponse(['error' => 'Too many wrong codes. Ask for a new code.', 'restart' => true], 400);
        }
        jsonResponse(['error' => "Wrong code. {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.'], 400);
    }
    $password = (string)($body['password'] ?? '');
    if ($problem = passwordProblem($password, (string)$reset['email'])) {
        jsonResponse(['error' => $problem], 422); // code stays valid: fix the password and try again
    }
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['uid']]);
    // Sign the account out everywhere: old sessions end, failed tries are cleared.
    try {
        $pdo->prepare("UPDATE login_sessions SET ended_at = NOW(), end_reason = 'password reset' WHERE user_id = ? AND ended_at IS NULL")->execute([(int)$reset['uid']]);
    } catch (Throwable $error) { /* table missing until 019 is run */ }
    $pdo->prepare("DELETE FROM login_attempts WHERE kind = 'login' AND success = 0 AND email = ?")->execute([(string)$reset['email']]);
    if (function_exists('writeSuperAdminAudit')) {
        try { writeSuperAdminAudit($pdo, ['id' => (int)$reset['uid'], 'name' => '', 'email' => $reset['email']], 'password-reset-code', (int)$reset['uid'], "Password reset with an email code for {$reset['email']}."); }
        catch (Throwable $error) { /* audit is optional */ }
    }
    unset($_SESSION['reset']);
    session_regenerate_id(true);
    jsonResponse(['message' => 'Password changed. You can now log in with your new password.']);
}

// ---------------------------------------------------------------- public: login, logout, sign-up
function handleAuth(string $resource, string $method): void
{
    handleForgotPassword($resource, $method);

    if ($resource === 'login' && $method === 'POST') {
        $pdo = db();
        $body = requestBody();

        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $ip = clientIp();

        $lockMinutes = function_exists('lockMinutes') ? lockMinutes() : 5; // set in Super Admin > Settings
        // Failures before a Super Admin clicked Unlock no longer count.
        $mine = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE kind = 'login' AND success = 0 AND email = ? AND ip = ? AND created_at > (NOW() - INTERVAL {$lockMinutes} MINUTE)
            AND created_at > COALESCE((SELECT u.unlocked_at FROM users u WHERE LOWER(u.email) = ? LIMIT 1), '1970-01-01')");
        $mine->execute([$email, $ip, $email]);
        $any = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE kind = 'login' AND success = 0 AND ip = ? AND created_at > (NOW() - INTERVAL {$lockMinutes} MINUTE)");
        $any->execute([$ip]);
        if ((int)$mine->fetchColumn() >= LOGIN_MAX_FAILS || (int)$any->fetchColumn() >= LOGIN_MAX_FAILS_IP) {
            jsonResponse(['error' => "Too many failed attempts. Try again in {$lockMinutes} minutes."], 429);
        }

        $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role, department, position, is_active, approval FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        static $dummy = null;
        $dummy ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT); // same work whether or not the email exists
        $ok = password_verify($password, $user ? $user['password_hash'] : $dummy);
        if (!$user || !$ok) {
            recordAttempt($pdo, 'login', $email, false);
            jsonResponse(['error' => 'Wrong email or password.'], 401);
        }
        if ($user['approval'] === 'pending') {
            jsonResponse(['error' => 'Your account is waiting for HR approval.'], 403);
        }
        if ($user['approval'] === 'rejected' || !$user['is_active']) {
            jsonResponse(['error' => 'This account is not active. Contact HR.'], 403);
        }
        $pdo->prepare("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 90 DAY)")->execute();
        // Someone already signed in re-entering their own password (for example before a download): no new code needed.
        if (!empty($_SESSION['user']) && (int)$_SESSION['user']['id'] === (int)$user['id']) {
            jsonResponse(['user' => $_SESSION['user']]);
        }
        startLoginCode($user); // the code step calls finishLogin() when the right code is entered
    }

    handleLoginCode($resource, $method);

    if ($resource === 'logout' && $method === 'POST') {
        endLoginSession('signed out');
        if (!empty($_SESSION['user']['id'])) {
            try { db()->prepare('UPDATE users SET last_seen_at = NULL WHERE id = ?')->execute([(int)$_SESSION['user']['id']]); }
            catch (Throwable $error) { /* column missing until 018 is run */ }
        }
        destroySession();
        jsonResponse(['message' => 'Logged out successfully.']);
    }

    if ($resource === 'me' && $method === 'GET') {
        jsonResponse(['user' => requireLogin()]);
    }

    if ($resource === 'signup' && $method === 'POST') {
        jsonResponse(['error' => 'Sign-up is disabled. Ask a Super Admin to create your account.'], 403);
        $pdo = db();
        $b = requestBody();
        $name = trim((string)($b['name'] ?? ''));
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $password = (string)($b['password'] ?? '');
        $dept = trim((string)($b['department'] ?? ''));
        $pos = trim((string)($b['position'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150 || $dept === '' || $pos === '' || mb_strlen($dept) > 100 || mb_strlen($pos) > 100) {
            jsonResponse(['error' => 'Name, department and position are required.'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            jsonResponse(['error' => 'Enter a valid email address.'], 422);
        }
        if ($problem = passwordProblem($password, $email)) {
            jsonResponse(['error' => $problem], 422);
        }
        $recent = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE kind = 'signup' AND ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
        $recent->execute([clientIp()]);
        if ((int)$recent->fetchColumn() >= SIGNUP_MAX_PER_HOUR) {
            jsonResponse(['error' => 'Too many sign-ups from this connection. Try again later.'], 429);
        }
        $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ((int)$exists->fetchColumn() > 0) {
            jsonResponse(['error' => 'An account with this email already exists.'], 409);
        }
        $pdo->prepare("INSERT INTO users (name, email, password_hash, role, department, position, is_active, approval) VALUES (?, ?, ?, 'EMPLOYEE', ?, ?, 1, 'pending')")
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $dept, $pos]);
        recordAttempt($pdo, 'signup', $email, true);
        jsonResponse(['message' => 'Account created. HR must approve it before you can log in.'], 201);
    }
}

// ---------------------------------------------------------------- logged in (any role): change own password
function handleChangePassword(string $resource, string $method, PDO $pdo, array $user): void
{
    if ($resource !== 'change-password' || $method !== 'POST') {
        return;
    }
    $b = requestBody();
    $current = (string)($b['current'] ?? '');
    $new = (string)($b['new'] ?? '');
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = (string)$stmt->fetchColumn();
    if (!password_verify($current, $hash)) {
        jsonResponse(['error' => 'Your current password is not correct.'], 422);
    }
    if ($problem = passwordProblem($new, (string)$user['email'])) {
        jsonResponse(['error' => $problem], 422);
    }
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    session_regenerate_id(true);
    jsonResponse(['message' => 'Password changed.']);
}

// ---------------------------------------------------------------- admin: employee logins
function handleAdminAccounts(string $resource, string $method, PDO $pdo, array $user): void
{
    if ($resource !== 'accounts') {
        return;
    }
    if ($method === 'GET') {
        $rows = $pdo->query("SELECT id, name, email, department, position, approval, is_active AS active, DATE_FORMAT(created_at, '%Y-%m-%d') AS created FROM users WHERE role = 'EMPLOYEE' ORDER BY (approval = 'pending') DESC, id DESC LIMIT 500")->fetchAll();
        jsonResponse(['records' => $rows]);
    }
    if ($method !== 'POST') {
        return;
    }
    $b = requestBody();
    $action = (string)($b['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)($b['name'] ?? ''));
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $password = (string)($b['password'] ?? '');
        $dept = trim((string)($b['department'] ?? ''));
        $pos = trim((string)($b['position'] ?? ''));
        if ($name === '' || $dept === '' || $pos === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['error' => 'Name, a valid email, department and position are required.'], 422);
        }
        if ($problem = passwordProblem($password, $email)) {
            jsonResponse(['error' => $problem], 422);
        }
        $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ((int)$exists->fetchColumn() > 0) {
            jsonResponse(['error' => 'An account with this email already exists.'], 409);
        }
        if (mb_strlen(trim((string)($b['contact'] ?? ''))) > 50 || mb_strlen(trim((string)($b['emergency_contact'] ?? ''))) > 255) {
            jsonResponse(['error' => 'Contact details are too long.'], 422);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO users (name, email, password_hash, role, department, position, is_active, approval) VALUES (?, ?, ?, 'EMPLOYEE', ?, ?, 1, 'approved')")
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $dept, $pos]);
            $id = (int)$pdo->lastInsertId();
            ensureEmployeeProfile($pdo, $id, $b);
            $pdo->commit();
            jsonResponse(['id' => $id, 'message' => 'Employee login and profile created.'], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    $id = (int)($b['id'] ?? 0);
    $target = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'EMPLOYEE'"); // admins can never be changed here
    $target->execute([$id]);
    if (!$target->fetchColumn()) {
        jsonResponse(['error' => 'Employee account not found.'], 404);
    }

    if ($action === 'approve' || $action === 'reject') {
        $pdo->prepare('UPDATE users SET approval = ? WHERE id = ?')->execute([$action === 'approve' ? 'approved' : 'rejected', $id]);
        if ($action === 'approve') ensureEmployeeProfile($pdo, $id);
        jsonResponse(['message' => 'Account updated.']);
    }
    if ($action === 'reset-password') {
        $password = (string)($b['password'] ?? '');
        if ($problem = passwordProblem($password)) {
            jsonResponse(['error' => $problem], 422);
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        jsonResponse(['message' => 'Password reset.']);
    }
    if ($action === 'remove') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
            $pdo->prepare("UPDATE employees SET status = 'inactive' WHERE user_id = ?")->execute([$id]);
            $pdo->commit();
            jsonResponse(['message' => 'Employee account disabled; historical records were retained.']);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    jsonResponse(['error' => 'Unknown account action.'], 422);
}

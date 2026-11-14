<?php
declare(strict_types=1);

// AUTHENTICATOR APP (phone OTP) - works with Google Authenticator, Microsoft Authenticator and similar apps.
// Standard time-based codes (RFC 6238): 6 digits, a new code every 30 seconds. Free, and the phone needs no internet.
// Needs database/021_authenticator.sql. Without it, everything here reports "not available" and email codes keep working.
//
//  GET  ?resource=totp                      signed-in user: is the authenticator on?
//  POST ?resource=totp {action: start}      make a new secret and return the QR details (not saved until confirmed)
//  POST ?resource=totp {action: confirm, code}   check the first code from the app, then turn it on
//  POST ?resource=totp {action: disable, password}  turn it off (needs the account password)

const TOTP_PERIOD = 30;
const TOTP_ISSUER = 'HIMS Hospital System';

function base32Encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32Decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text));
    $bits = '';
    foreach (str_split($text) as $char) {
        $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function totpCodeAt(string $secretBytes, int $step, int $digits = 6): string
{
    $hash = hash_hmac('sha1', pack('J', $step), $secretBytes, true);
    $offset = ord($hash[19]) & 0x0f;
    $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string)($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

// Returns the time step the code belongs to (allowing 30 seconds of clock difference), or null.
// $lastStep stops the same code from being used twice.
function totpVerify(string $secretBase32, string $code, ?int $lastStep): ?int
{
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $secret = base32Decode($secretBase32);
    $now = intdiv(time(), TOTP_PERIOD);
    foreach ([0, -1, 1] as $drift) {
        $step = $now + $drift;
        if ($lastStep !== null && $step <= $lastStep) continue;
        if (hash_equals(totpCodeAt($secret, $step), $code)) return $step;
    }
    return null;
}

function totpUri(string $secret, string $email): string
{
    return 'otpauth://totp/' . rawurlencode(TOTP_ISSUER . ':' . $email)
        . '?secret=' . $secret . '&issuer=' . rawurlencode(TOTP_ISSUER) . '&algorithm=SHA1&digits=6&period=' . TOTP_PERIOD;
}

// The authenticator details of an account, or null when it is off or 021 has not been run.
function totpForUser(PDO $pdo, int $userId): ?array
{
    try {
        $q = $pdo->prepare('SELECT totp_secret, totp_last_step, DATE_FORMAT(totp_enabled_at, \'%Y-%m-%d %h:%i %p\') AS enabledAt FROM users WHERE id = ? LIMIT 1');
        $q->execute([$userId]);
        $row = $q->fetch();
    } catch (Throwable $error) {
        return null;
    }
    return $row && $row['totp_secret'] ? $row : null;
}

function totpReady(PDO $pdo): bool
{
    try { $pdo->query('SELECT totp_secret FROM users LIMIT 1'); return true; }
    catch (Throwable $error) { return false; }
}

function handleTotp(string $method, PDO $pdo, array $user): never
{
    $userId = (int)$user['id'];
    if ($method === 'GET') {
        $totp = totpForUser($pdo, $userId);
        jsonResponse(['ready' => totpReady($pdo), 'enabled' => (bool)$totp, 'enabledAt' => $totp['enabledAt'] ?? null]);
    }
    if ($method !== 'POST') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }
    if (!totpReady($pdo)) {
        jsonResponse(['error' => 'Run database/021_authenticator.sql in MySQL Workbench first.'], 500);
    }
    $body = requestBody();
    $action = (string)($body['action'] ?? '');

    if ($action === 'start') {
        $secret = base32Encode(random_bytes(20)); // 32 characters
        $_SESSION['totp_pending'] = ['secret' => $secret, 'created' => time()];
        jsonResponse(['secret' => trim(chunk_split($secret, 4, ' ')), 'uri' => totpUri($secret, (string)$user['email'])]);
    }

    if ($action === 'confirm') {
        $pending = $_SESSION['totp_pending'] ?? null;
        if (!$pending || time() - (int)$pending['created'] > 900) {
            jsonResponse(['error' => 'The setup timed out. Click "Set up" again to get a new QR code.'], 409);
        }
        $step = totpVerify($pending['secret'], preg_replace('/\D/', '', (string)($body['code'] ?? '')), null);
        if ($step === null) {
            jsonResponse(['error' => 'That code is not right. Make sure the phone shows "HIMS Hospital System" and try the newest code.'], 400);
        }
        $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled_at = NOW(), totp_last_step = ? WHERE id = ?')
            ->execute([$pending['secret'], $step, $userId]);
        unset($_SESSION['totp_pending']);
        jsonResponse(['message' => 'Authenticator app turned on. Next time you log in, you can use it instead of an email code.']);
    }

    if ($action === 'disable') {
        $q = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $q->execute([$userId]);
        if (!password_verify((string)($body['password'] ?? ''), (string)$q->fetchColumn())) {
            jsonResponse(['error' => 'Incorrect password. The authenticator app is still on.'], 403);
        }
        $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?')->execute([$userId]);
        jsonResponse(['message' => 'Authenticator app turned off. You will get sign-in codes by email.']);
    }

    jsonResponse(['error' => 'Unknown action.'], 422);
}

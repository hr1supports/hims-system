<?php
declare(strict_types=1);

// Sends the 6-digit sign-in code.
//
// TERMINAL MODE (now): when backend/mail.ini does not exist, or no email password was given at start-up,
//   the code is printed in the PHP server window instead of being emailed. Use this while developing.
// EMAIL MODE (when deployed), either:
//   - BREVO (recommended): set BREVO_API_KEY and HIMS_MAIL_FROM in .env / HostForge environment variables, or
//   - SMTP (e.g. Gmail): copy mail.example.ini to mail.ini, fill in the sender account,
//     and give the password in the HR3_SMTP_PASSWORD environment variable. The password is never stored in a file.

function mailSettings(): ?array
{
    $file = __DIR__ . '/mail.ini';
    if (!is_file($file)) {
        return null;
    }
    $ini = parse_ini_file($file) ?: [];
    $settings = [
        'host' => trim((string)($ini['host'] ?? '')),
        'port' => (int)($ini['port'] ?? 587),
        'encryption' => strtolower(trim((string)($ini['encryption'] ?? 'tls'))), // tls (port 587) or ssl (port 465)
        'user' => trim((string)($ini['user'] ?? '')),
        'from' => trim((string)($ini['from'] ?? '')),
        'from_name' => trim((string)($ini['from_name'] ?? 'HIMS Hospital System')),
        'password' => (string)(getenv('HR3_SMTP_PASSWORD') ?: ''),
    ];
    if ($settings['host'] === '' || $settings['user'] === '' || $settings['password'] === '') {
        return null;
    }
    $settings['from'] = $settings['from'] ?: $settings['user'];
    return $settings;
}

// Returns "email" when the code was emailed, or "terminal" when it was printed in the server window.
// $purpose: "sign-in" (login code) or "reset" (forgot password code).
function sendLoginCodeMessage(string $to, string $name, string $code, int $minutes, string $purpose = 'sign-in'): string
{
    $reset = $purpose === 'reset';
    $settings = mailSettings();
    $brevoKey = (string)(getenv('BREVO_API_KEY') ?: '');
    if ($settings === null && $brevoKey === '') {
        error_log(sprintf("\n==============================\n  HIMS %s for %s:  %s\n  (works for %d minutes)\n==============================",
            $reset ? 'PASSWORD RESET CODE' : 'SIGN-IN CODE', $to, $code, $minutes));
        return 'terminal';
    }
    $first = trim(explode(' ', trim($name))[0] ?? '');
    $subject = $reset ? 'Your HIMS password reset code' : 'Your HIMS sign-in code';
    $body = "Hi" . ($first !== '' ? " {$first}" : '') . ",\n\n"
        . ($reset ? "Your HIMS password reset code is:" : "Your HIMS sign-in code is:") . "\n\n    {$code}\n\n"
        . "It works for {$minutes} minutes. "
        . ($reset ? "If you did not ask to reset your password, ignore this email; your password stays the same." : "If you did not try to sign in, ignore this email and tell your Super Admin.")
        . "\n\nHIMS Hospital System";
    if ($brevoKey !== '') {
        brevoSend($brevoKey, $to, $name, $subject, $body); // throws when sending fails
    } else {
        smtpSend($settings, $to, $subject, $body); // throws when sending fails
    }
    return 'email';
}

// Sends through Brevo's email API over HTTPS (port 443), which hosting platforms do not block,
// unlike Gmail SMTP ports 587/465. Settings (environment variables / .env):
//   BREVO_API_KEY     the API key from Brevo > SMTP & API > API Keys
//   HIMS_MAIL_FROM    a sender address verified in Brevo > Senders
//   HIMS_MAIL_NAME    optional sender name (default "HIMS Hospital System")
function brevoSend(string $apiKey, string $to, string $toName, string $subject, string $text): void
{
    $from = trim((string)(getenv('HIMS_MAIL_FROM') ?: ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid recipient address.');
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Set HIMS_MAIL_FROM to the sender address you verified in Brevo.');
    }
    $payload = json_encode([
        'sender' => ['email' => $from, 'name' => (string)(getenv('HIMS_MAIL_NAME') ?: 'HIMS Hospital System')],
        'to' => [['email' => $to, 'name' => mb_substr(trim($toName) ?: $to, 0, 100)]],
        'subject' => $subject,
        'textContent' => $text,
    ], JSON_UNESCAPED_UNICODE);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "api-key: {$apiKey}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $payload,
        'timeout' => 15,
        'ignore_errors' => true, // read Brevo's error message instead of a bare warning
    ]]);
    $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
        }
    }
    if ($response === false || $status < 200 || $status >= 300) {
        $detail = is_string($response) ? (json_decode($response, true)['message'] ?? trim($response)) : 'no response';
        throw new RuntimeException("Brevo could not send the email (HTTP {$status}): " . mb_substr((string)$detail, 0, 200));
    }
}

function smtpSend(array $s, string $to, string $subject, string $text): void
{
    $clean = static fn(string $v): string => str_replace(["\r", "\n"], '', $v);
    $to = $clean($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid recipient address.');
    }
    $prefix = $s['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = @stream_socket_client($prefix . $s['host'] . ':' . $s['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new RuntimeException("Cannot reach the mail server {$s['host']}:{$s['port']} ({$errstr}).");
    }
    stream_set_timeout($socket, 15);

    $read = static function () use ($socket): string {
        $reply = '';
        while (($line = fgets($socket, 515)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $reply;
    };
    $send = static function (?string $command, array $expect) use ($socket, $read): string {
        if ($command !== null) {
            fwrite($socket, $command . "\r\n");
        }
        $reply = $read();
        if (!in_array((int)substr($reply, 0, 3), $expect, true)) {
            throw new RuntimeException('Mail server said: ' . trim($reply));
        }
        return $reply;
    };

    try {
        $send(null, [220]);
        $send('EHLO hims.local', [250]);
        if ($s['encryption'] === 'tls') {
            $send('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not start a secure connection with the mail server.');
            }
            $send('EHLO hims.local', [250]);
        }
        $send('AUTH LOGIN', [334]);
        $send(base64_encode($s['user']), [334]);
        $send(base64_encode($s['password']), [235]);
        $from = $clean($s['from']);
        $send("MAIL FROM:<{$from}>", [250]);
        $send("RCPT TO:<{$to}>", [250, 251]);
        $send('DATA', [354]);

        $headers = [
            'From: ' . mb_encode_mimeheader($clean($s['from_name']), 'UTF-8') . " <{$from}>",
            "To: <{$to}>",
            'Subject: ' . mb_encode_mimeheader($clean($subject), 'UTF-8'),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@hims.local>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $text));
        $send(implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.", [250]);
        $send('QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

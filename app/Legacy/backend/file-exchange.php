<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// FILE EXCHANGE with the HR2, HR3 and HR4 systems (integration).
//
// Inside HIMS (signed in as HR Staff, HR Officer, Admin or Super Admin):
//   ?resource=file-exchange                          see handleFileExchange()
// From the partner systems (no login, they send their integration key):
//   ?resource=integration-files                      see handleIntegrationFiles()
//
// Tables: database/024_file_exchange.sql. Files are stored OUTSIDE the project folder
// (next to the resumes), so the web server can never serve them directly.
// ---------------------------------------------------------------------

const EXCHANGE_PARTNERS = ['HR2', 'HR3', 'HR4', 'ADMIN']; // ADMIN = the Administrators group's system
const EXCHANGE_MAX_BYTES = 10 * 1024 * 1024;
const EXCHANGE_TYPES = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'csv' => 'text/csv',
    'txt' => 'text/plain',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'zip' => 'application/zip',
];

function exchangeDir(): string
{
    $dir = getenv('HIMS_EXCHANGE_DIR') ?: '';
    if ($dir === '') {
        $resumes = getenv('HR3_UPLOAD_DIR') ?: '';
        $dir = $resumes !== ''
            ? dirname($resumes) . DIRECTORY_SEPARATOR . 'exchange'
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'hr3_uploads' . DIRECTORY_SEPARATOR . 'exchange';
    }
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the file exchange folder.');
    }
    return $dir;
}

// Check the real file header, not just the extension.
function exchangeContentOk(string $ext, string $path): bool
{
    $head = (string)file_get_contents($path, false, null, 0, 8192);
    return match ($ext) {
        'pdf' => str_starts_with($head, '%PDF'),
        'png' => str_starts_with($head, "\x89PNG"),
        'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
        'doc', 'xls', 'ppt' => str_starts_with($head, "\xD0\xCF\x11\xE0"),
        'docx', 'xlsx', 'pptx', 'zip' => str_starts_with($head, "PK\x03\x04"),
        'csv', 'txt' => !str_contains($head, "\x00"), // plain text never contains NUL bytes
        default => false,
    };
}

// When a post is bigger than PHP's post_max_size, PHP silently drops the whole form. Say so clearly.
function exchangeCheckPostSize(): void
{
    if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        jsonResponse(['error' => 'The file is larger than this server accepts. The limit is 10 MB.'], 413);
    }
}

// Validates $_FILES[$field] and moves it into the exchange folder. Returns the file details.
function exchangeStoreUpload(string $field = 'file'): array
{
    exchangeCheckPostSize();
    $file = $_FILES[$field] ?? null;
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        jsonResponse(['error' => 'Attach a file.'], 422);
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int)$file['size'] > EXCHANGE_MAX_BYTES) {
        jsonResponse(['error' => 'The file is larger than 10 MB. Send a smaller file.'], 413);
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        jsonResponse(['error' => 'The upload failed. Please try again.'], 422);
    }
    $original = basename(str_replace('\\', '/', (string)$file['name']));
    $original = mb_substr((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $original), 0, 200);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($original === '' || !isset(EXCHANGE_TYPES[$ext]) || !exchangeContentOk($ext, $file['tmp_name'])) {
        jsonResponse(['error' => 'Only real PDF, Word, Excel, PowerPoint, CSV, TXT, JPG, PNG or ZIP files can be sent.'], 422);
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = exchangeDir() . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }
    return [
        'original' => $original,
        'stored' => $stored,
        'mime' => EXCHANGE_TYPES[$ext],
        'size' => (int)filesize($dest),
        'sha256' => hash_file('sha256', $dest),
    ];
}

function exchangeSendFile(array $row): never
{
    $stored = (string)$row['stored_name'];
    $path = exchangeDir() . DIRECTORY_SEPARATOR . $stored;
    if (!preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $stored) || !is_file($path)) {
        jsonResponse(['error' => 'File not found on the server.'], 404);
    }
    header('Content-Type: ' . (string)$row['mime_type']);
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string)$row['original_name']));
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function exchangeText(mixed $value, int $max): string
{
    $text = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$value));
    return mb_substr($text, 0, $max);
}

function exchangeTablesMissing(PDOException $error): bool
{
    return (int)($error->errorInfo[1] ?? 0) === 1146;
}

// Which partner owns this key? Keys made in HIMS (Super Admin > File Exchange > Connections) are checked first,
// then the HR2_/HR3_/HR4_INTEGRATION_KEY environment variables (HR3's is the same key used for hire orders).
function exchangePartnerForKey(PDO $pdo, string $key): ?string
{
    if ($key === '' || strlen($key) > 200) {
        return null;
    }
    $hash = hash('sha256', $key);
    $rows = $pdo->query('SELECT partner, key_hash FROM integration_keys')->fetchAll();
    foreach ($rows as $row) {
        if (hash_equals((string)$row['key_hash'], $hash)) {
            $pdo->prepare('UPDATE integration_keys SET last_used_at = NOW() WHERE partner = ?')->execute([$row['partner']]);
            return (string)$row['partner'];
        }
    }
    foreach (EXCHANGE_PARTNERS as $partner) {
        $env = (string)(getenv($partner . '_INTEGRATION_KEY') ?: '');
        if ($env !== '' && hash_equals($env, $key)) {
            return $partner;
        }
    }
    return null;
}

function exchangeConnections(PDO $pdo): array
{
    $keys = [];
    foreach ($pdo->query("SELECT partner, key_hint AS hint, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS createdAt, DATE_FORMAT(last_used_at, '%Y-%m-%d %H:%i') AS lastUsedAt FROM integration_keys")->fetchAll() as $row) {
        $keys[$row['partner']] = $row;
    }
    $out = [];
    foreach (EXCHANGE_PARTNERS as $partner) {
        $row = $keys[$partner] ?? null;
        $env = (string)(getenv($partner . '_INTEGRATION_KEY') ?: '') !== '';
        $out[] = [
            'partner' => $partner,
            'connected' => $row !== null || $env,
            'source' => $row !== null ? 'HIMS key' : ($env ? 'Server setting' : ''),
            'hint' => $row['hint'] ?? '',
            'createdAt' => $row['createdAt'] ?? '',
            'lastUsedAt' => $row['lastUsedAt'] ?? '',
        ];
    }
    return $out;
}

// ---------------------------------------------------------------- inside HIMS
function handleFileExchange(string $method, array $user): never
{
    if (!in_array($user['role'], ['HR1', 'HR3', 'ADMIN', 'SUPER_ADMIN', 'HR_OFFICER', 'HR_STAFF'], true)) {
        jsonResponse(['error' => 'Your role cannot use File Exchange.'], 403);
    }
    $pdo = db();
    try {
        if ($method === 'GET') {
            exchangeGet($pdo, $user);
        }
        if ($method === 'POST') {
            requireSameSiteHeader(); // blocks forged cross-site form posts
            exchangePost($pdo, $user);
        }
    } catch (PDOException $error) {
        if (exchangeTablesMissing($error)) {
            jsonResponse(['error' => 'Run database/024_file_exchange.sql in phpMyAdmin to use File Exchange.'], 500);
        }
        throw $error;
    }
    jsonResponse(['error' => 'Method not allowed.'], 405);
}

function exchangeGet(PDO $pdo, array $user): never
{
    if (isset($_GET['download'])) {
        $stmt = $pdo->prepare('SELECT * FROM file_exchange WHERE id = ?');
        $stmt->execute([(int)$_GET['download']]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(['error' => 'File not found.'], 404);
        }
        if ($row['direction'] === 'incoming' && $row['status'] === 'New') {
            $pdo->prepare("UPDATE file_exchange SET status = 'Opened', opened_by = ?, opened_at = NOW() WHERE id = ? AND status = 'New'")
                ->execute([$user['id'], $row['id']]);
        }
        exchangeSendFile($row);
    }

    $view = (string)($_GET['view'] ?? 'list');
    if ($view === 'push') { // what HIMS sends to each system, and the delivery log
        handlePushSettings('GET', $pdo, $user);
    }
    if ($view === 'connections') {
        jsonResponse(['records' => exchangeConnections($pdo), 'canManage' => $user['role'] === 'SUPER_ADMIN']);
    }
    if ($view === 'counts') {
        $rows = $pdo->query("SELECT partner, COUNT(*) AS n FROM file_exchange WHERE direction = 'incoming' AND status = 'New' AND archived = 0 GROUP BY partner")->fetchAll();
        $counts = array_fill_keys(EXCHANGE_PARTNERS, 0);
        foreach ($rows as $row) {
            $counts[$row['partner']] = (int)$row['n'];
        }
        jsonResponse(['unread' => $counts, 'total' => array_sum($counts)]);
    }

    $box = ($_GET['box'] ?? 'incoming') === 'outgoing' ? 'outgoing' : 'incoming';
    $where = ['f.direction = ?', 'f.archived = ?'];
    $args = [$box, ($_GET['archived'] ?? '0') === '1' ? 1 : 0];
    $partner = (string)($_GET['partner'] ?? '');
    if (in_array($partner, EXCHANGE_PARTNERS, true)) {
        $where[] = 'f.partner = ?';
        $args[] = $partner;
    }
    $q = exchangeText($_GET['q'] ?? '', 100);
    if ($q !== '') {
        $where[] = '(f.subject LIKE ? OR f.original_name LIKE ? OR f.note LIKE ? OR f.partner_sender LIKE ?)';
        array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
    }
    $stmt = $pdo->prepare(
        "SELECT f.id, f.direction, f.partner, f.subject, f.note, f.original_name AS fileName, f.file_size AS size, f.sha256,
                f.partner_sender AS partnerSender, f.partner_ref AS partnerRef, f.status, f.archived,
                DATE_FORMAT(f.created_at, '%Y-%m-%d %H:%i') AS created,
                DATE_FORMAT(f.opened_at, '%Y-%m-%d %H:%i') AS openedAt,
                DATE_FORMAT(f.picked_up_at, '%Y-%m-%d %H:%i') AS pickedUpAt,
                s.name AS sentBy, o.name AS openedBy
         FROM file_exchange f
         LEFT JOIN users s ON s.id = f.sent_by
         LEFT JOIN users o ON o.id = f.opened_by
         WHERE " . implode(' AND ', $where) . ' ORDER BY f.id DESC LIMIT 300'
    );
    $stmt->execute($args);
    jsonResponse(['records' => $stmt->fetchAll()]);
}

function exchangePost(PDO $pdo, array $user): never
{
    // Sending a file is a multipart form post; every other action is JSON.
    if (!empty($_FILES) || str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
        exchangeCheckPostSize();
        $partners = array_values(array_unique(array_filter((array)($_POST['partners'] ?? []), static fn($p) => in_array($p, EXCHANGE_PARTNERS, true))));
        $subject = exchangeText($_POST['subject'] ?? '', 150);
        $note = exchangeText($_POST['note'] ?? '', 2000);
        if (!$partners) {
            jsonResponse(['error' => 'Choose at least one system to send to (HR2, HR3, HR4 or Administrators).'], 422);
        }
        if ($subject === '') {
            jsonResponse(['error' => 'Enter a subject.'], 422);
        }
        $file = exchangeStoreUpload('file');
        $insert = $pdo->prepare(
            "INSERT INTO file_exchange (direction, partner, subject, note, original_name, stored_name, mime_type, file_size, sha256, sent_by, status)
             VALUES ('outgoing', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Waiting')"
        );
        foreach ($partners as $partner) {
            $insert->execute([$partner, $subject, $note ?: null, $file['original'], $file['stored'], $file['mime'], $file['size'], $file['sha256'], $user['id']]);
        }
        jsonResponse(['message' => 'File sent to ' . implode(', ', $partners) . '. It waits there until their system picks it up.'], 201);
    }

    $body = requestBody();
    $action = (string)($body['action'] ?? '');
    $id = (int)($body['id'] ?? 0);
    if (str_starts_with($action, 'push-')) { // sending data to HR2 / HR3 / HR4 / Administrators
        handlePushSettings('POST', $pdo, $user, $body);
    }

    if ($action === 'archive' || $action === 'restore') {
        $stmt = $pdo->prepare('UPDATE file_exchange SET archived = ? WHERE id = ?');
        $stmt->execute([$action === 'archive' ? 1 : 0, $id]);
        if (!$stmt->rowCount()) {
            jsonResponse(['error' => 'File not found.'], 404);
        }
        jsonResponse(['message' => $action === 'archive' ? 'File archived.' : 'File restored.']);
    }
    if ($action === 'mark-unread') {
        $stmt = $pdo->prepare("UPDATE file_exchange SET status = 'New', opened_by = NULL, opened_at = NULL WHERE id = ? AND direction = 'incoming'");
        $stmt->execute([$id]);
        jsonResponse(['message' => 'Marked as new.']);
    }

    // Connection keys: Super Admin only.
    if ($action === 'generate-key' || $action === 'revoke-key') {
        if ($user['role'] !== 'SUPER_ADMIN') {
            jsonResponse(['error' => 'Only the Super Admin can manage connection keys.'], 403);
        }
        $partner = (string)($body['partner'] ?? '');
        if (!in_array($partner, EXCHANGE_PARTNERS, true)) {
            jsonResponse(['error' => 'Unknown system.'], 422);
        }
        if ($action === 'revoke-key') {
            $pdo->prepare('DELETE FROM integration_keys WHERE partner = ?')->execute([$partner]);
            if (function_exists('writeSuperAdminAudit')) {
                writeSuperAdminAudit($pdo, $user, 'integration_key_revoked', null, "Revoked the File Exchange key for $partner");
            }
            jsonResponse(['message' => "$partner key removed. $partner can no longer send or pick up files with it."]);
        }
        $key = 'hims_' . strtolower($partner) . '_' . bin2hex(random_bytes(24));
        $pdo->prepare(
            'INSERT INTO integration_keys (partner, key_hash, key_hint, created_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE key_hash = VALUES(key_hash), key_hint = VALUES(key_hint), created_by = VALUES(created_by), created_at = NOW(), last_used_at = NULL'
        )->execute([$partner, hash('sha256', $key), '…' . substr($key, -6), $user['id']]);
        if (function_exists('writeSuperAdminAudit')) {
            writeSuperAdminAudit($pdo, $user, 'integration_key_created', null, "Created a new File Exchange key for $partner");
        }
        jsonResponse(['key' => $key, 'message' => "New key for $partner. Copy it now: it will not be shown again."], 201);
    }

    jsonResponse(['error' => 'Unknown action.'], 422);
}

// ---------------------------------------------------------------- partner systems (HR2 / HR3 / HR4)
// Header:  X-Integration-Key: <key>   (HR3 may also use its existing X-HR3-Key)
//   POST  ?resource=integration-files            multipart: file, subject, note, sender, reference  -> sends a file to HIMS
//   GET   ?resource=integration-files            list of files HIMS sent to you (add &status=all to include picked-up ones)
//   GET   ?resource=integration-files&id=12      download one of them (marks it Picked up)
function handleIntegrationFiles(string $resource, string $method): void
{
    if ($resource !== 'integration-files') {
        return;
    }
    $pdo = db();
    try {
        $key = (string)($_SERVER['HTTP_X_INTEGRATION_KEY'] ?? ($_SERVER['HTTP_X_HR3_KEY'] ?? ''));
        $partner = exchangePartnerForKey($pdo, $key);
        if ($partner === null) {
            jsonResponse(['error' => 'Invalid or missing integration key (send it in the X-Integration-Key header).'], 401);
        }

        if ($method === 'POST') {
            $recent = $pdo->prepare("SELECT COUNT(*) FROM file_exchange WHERE direction = 'incoming' AND partner = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
            $recent->execute([$partner]);
            if ((int)$recent->fetchColumn() >= 100) {
                jsonResponse(['error' => 'Too many files sent in the last hour. Try again later.'], 429);
            }
            exchangeCheckPostSize();
            $subject = exchangeText($_POST['subject'] ?? '', 150);
            if ($subject === '') {
                jsonResponse(['error' => 'subject is required.'], 422);
            }
            $file = exchangeStoreUpload('file');
            $pdo->prepare(
                "INSERT INTO file_exchange (direction, partner, subject, note, original_name, stored_name, mime_type, file_size, sha256, partner_sender, partner_ref, status)
                 VALUES ('incoming', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'New')"
            )->execute([
                $partner, $subject, exchangeText($_POST['note'] ?? '', 2000) ?: null,
                $file['original'], $file['stored'], $file['mime'], $file['size'], $file['sha256'],
                exchangeText($_POST['sender'] ?? '', 150) ?: null, exchangeText($_POST['reference'] ?? '', 60) ?: null,
            ]);
            jsonResponse(['id' => (int)$pdo->lastInsertId(), 'sha256' => $file['sha256'], 'message' => "File received from $partner."], 201);
        }

        if ($method === 'GET' && isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM file_exchange WHERE id = ? AND direction = 'outgoing' AND partner = ? AND archived = 0");
            $stmt->execute([(int)$_GET['id'], $partner]);
            $row = $stmt->fetch();
            if (!$row) {
                jsonResponse(['error' => 'File not found.'], 404);
            }
            $pdo->prepare("UPDATE file_exchange SET status = 'Picked up', picked_up_at = COALESCE(picked_up_at, NOW()) WHERE id = ?")->execute([$row['id']]);
            exchangeSendFile($row);
        }

        if ($method === 'GET') {
            $all = ($_GET['status'] ?? '') === 'all';
            $stmt = $pdo->prepare(
                "SELECT f.id, f.subject, f.note, f.original_name AS fileName, f.mime_type AS mimeType, f.file_size AS size, f.sha256, f.status,
                        u.name AS sentBy, DATE_FORMAT(f.created_at, '%Y-%m-%d %H:%i:%s') AS sentAt
                 FROM file_exchange f LEFT JOIN users u ON u.id = f.sent_by
                 WHERE f.direction = 'outgoing' AND f.partner = ? AND f.archived = 0" . ($all ? '' : " AND f.status = 'Waiting'") . '
                 ORDER BY f.id DESC LIMIT 200'
            );
            $stmt->execute([$partner]);
            jsonResponse(['partner' => $partner, 'records' => $stmt->fetchAll()]);
        }
    } catch (PDOException $error) {
        if (exchangeTablesMissing($error)) {
            jsonResponse(['error' => 'File exchange is not set up on HIMS yet.'], 503);
        }
        throw $error;
    }
    jsonResponse(['error' => 'Method not allowed.'], 405);
}

// =====================================================================
// HR REQUESTS from the HR2, HR3 and HR4 systems (integration).
// Inside HIMS:      ?resource=hr-requests            see handleHrRequests()
// Partner systems:  ?resource=integration-requests   see handleIntegrationRequests()
// Table: integration_requests (database/024_file_exchange.sql).
// =====================================================================
const REQUEST_STATUSES = ['Pending', 'Approved', 'Rejected'];

function handleHrRequests(string $method, array $user): never
{
    if (!in_array($user['role'], ['HR1', 'HR3', 'ADMIN', 'HR_OFFICER'], true)) {
        jsonResponse(['error' => 'HR Requests are reviewed by an HR Officer or Admin.'], 403);
    }
    $pdo = db();
    try {
        if ($method === 'GET') {
            $where = [];
            $args = [];
            $status = (string)($_GET['status'] ?? 'Pending');
            if (in_array($status, REQUEST_STATUSES, true)) {
                $where[] = 'r.status = ?';
                $args[] = $status;
            }
            $partner = (string)($_GET['partner'] ?? '');
            if (in_array($partner, EXCHANGE_PARTNERS, true)) {
                $where[] = 'r.partner = ?';
                $args[] = $partner;
            }
            $stmt = $pdo->prepare(
                "SELECT r.id, r.partner, r.reference, r.request_type AS type, r.title, r.employee_code AS employeeCode, r.employee_name AS employeeName,
                        r.details, r.hours, DATE_FORMAT(r.request_date, '%Y-%m-%d') AS requestDate, r.requested_by AS requestedBy,
                        r.status, r.remark, u.name AS decidedBy,
                        DATE_FORMAT(r.decided_at, '%Y-%m-%d %H:%i') AS decidedAt, DATE_FORMAT(r.created_at, '%Y-%m-%d %H:%i') AS received
                 FROM integration_requests r LEFT JOIN users u ON u.id = r.decided_by"
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.id DESC LIMIT 300'
            );
            $stmt->execute($args);
            $counts = array_fill_keys(EXCHANGE_PARTNERS, 0);
            foreach ($pdo->query("SELECT partner, COUNT(*) AS n FROM integration_requests WHERE status = 'Pending' GROUP BY partner")->fetchAll() as $row) {
                $counts[$row['partner']] = (int)$row['n'];
            }
            jsonResponse(['records' => $stmt->fetchAll(), 'pending' => $counts, 'pendingTotal' => array_sum($counts)]);
        }
        if ($method === 'POST') {
            requireSameSiteHeader();
            $body = requestBody();
            if ((string)($body['action'] ?? '') !== 'decide') {
                jsonResponse(['error' => 'Unknown action.'], 422);
            }
            $status = (string)($body['status'] ?? '');
            if (!in_array($status, ['Approved', 'Rejected'], true)) {
                jsonResponse(['error' => 'Status must be Approved or Rejected.'], 422);
            }
            $remark = exchangeText($body['remark'] ?? '', 500);
            $stmt = $pdo->prepare("UPDATE integration_requests SET status = ?, remark = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$status, $remark ?: null, $user['id'], (int)($body['id'] ?? 0)]);
            if (!$stmt->rowCount()) {
                jsonResponse(['error' => 'This request was already decided or no longer exists.'], 409);
            }
            jsonResponse(['message' => "Request $status. The sending system sees the decision the next time it checks."]);
        }
    } catch (PDOException $error) {
        if (exchangeTablesMissing($error)) {
            jsonResponse(['error' => 'Run database/024_file_exchange.sql in phpMyAdmin to use HR Requests.'], 500);
        }
        throw $error;
    }
    jsonResponse(['error' => 'Method not allowed.'], 405);
}

// Header:  X-Integration-Key: <key>
//   POST  ?resource=integration-requests            JSON: reference, type, title, employee_code, employee_name, details, hours, date, requested_by
//   GET   ?resource=integration-requests            your requests and their status (add &status=Pending|Approved|Rejected)
//   GET   ?resource=integration-requests&reference=OT-0012   one request
function handleIntegrationRequests(string $resource, string $method): void
{
    if ($resource !== 'integration-requests') {
        return;
    }
    $pdo = db();
    try {
        $key = (string)($_SERVER['HTTP_X_INTEGRATION_KEY'] ?? ($_SERVER['HTTP_X_HR3_KEY'] ?? ''));
        $partner = exchangePartnerForKey($pdo, $key);
        if ($partner === null) {
            jsonResponse(['error' => 'Invalid or missing integration key (send it in the X-Integration-Key header).'], 401);
        }
        $select = "SELECT id, reference, request_type AS type, title, employee_code AS employeeCode, employee_name AS employeeName, details, hours,
                          DATE_FORMAT(request_date, '%Y-%m-%d') AS date, requested_by AS requestedBy, status, remark,
                          DATE_FORMAT(decided_at, '%Y-%m-%d %H:%i:%s') AS decidedAt, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS receivedAt
                   FROM integration_requests WHERE partner = ?";

        if ($method === 'POST') {
            $b = requestBody();
            $ref = exchangeText($b['reference'] ?? '', 60);
            $type = exchangeText($b['type'] ?? '', 60);
            $title = exchangeText($b['title'] ?? '', 150);
            $hours = $b['hours'] ?? null;
            $date = trim((string)($b['date'] ?? ''));
            if ($ref === '' || $type === '' || $title === '') {
                jsonResponse(['error' => 'reference, type and title are required.'], 422);
            }
            if ($hours !== null && $hours !== '' && (!is_numeric($hours) || (float)$hours < 0 || (float)$hours > 999)) {
                jsonResponse(['error' => 'hours must be a number from 0 to 999.'], 422);
            }
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                jsonResponse(['error' => 'date must look like 2026-12-31.'], 422);
            }
            $recent = $pdo->prepare('SELECT COUNT(*) FROM integration_requests WHERE partner = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
            $recent->execute([$partner]);
            if ((int)$recent->fetchColumn() >= 300) {
                jsonResponse(['error' => 'Too many requests sent in the last hour. Try again later.'], 429);
            }
            $dupe = $pdo->prepare('SELECT id FROM integration_requests WHERE partner = ? AND reference = ?');
            $dupe->execute([$partner, $ref]);
            if ($dupe->fetchColumn()) {
                jsonResponse(['error' => 'A request with this reference was already received.'], 409);
            }
            $pdo->prepare(
                "INSERT INTO integration_requests (partner, reference, request_type, title, employee_code, employee_name, details, hours, request_date, requested_by, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')"
            )->execute([
                $partner, $ref, $type, $title,
                exchangeText($b['employee_code'] ?? '', 40) ?: null, exchangeText($b['employee_name'] ?? '', 150) ?: null,
                exchangeText($b['details'] ?? '', 2000) ?: null,
                ($hours === null || $hours === '') ? null : round((float)$hours, 2),
                $date ?: null, exchangeText($b['requested_by'] ?? '', 150) ?: null,
            ]);
            jsonResponse(['id' => (int)$pdo->lastInsertId(), 'status' => 'Pending', 'message' => "Request received from $partner. An HR Officer will review it."], 201);
        }

        if ($method === 'GET' && isset($_GET['reference'])) {
            $stmt = $pdo->prepare($select . ' AND reference = ?');
            $stmt->execute([$partner, exchangeText($_GET['reference'], 60)]);
            $row = $stmt->fetch();
            if (!$row) {
                jsonResponse(['error' => 'Request not found.'], 404);
            }
            jsonResponse(['partner' => $partner, 'record' => $row]);
        }

        if ($method === 'GET') {
            $status = (string)($_GET['status'] ?? '');
            $args = [$partner];
            $sql = $select;
            if (in_array($status, REQUEST_STATUSES, true)) {
                $sql .= ' AND status = ?';
                $args[] = $status;
            }
            $stmt = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 300');
            $stmt->execute($args);
            jsonResponse(['partner' => $partner, 'records' => $stmt->fetchAll()]);
        }
    } catch (PDOException $error) {
        if (exchangeTablesMissing($error)) {
            jsonResponse(['error' => 'HR Requests are not set up on HIMS yet.'], 503);
        }
        throw $error;
    }
    jsonResponse(['error' => 'Method not allowed.'], 405);
}

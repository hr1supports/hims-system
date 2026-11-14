<?php
declare(strict_types=1);

// ---------------------------------------------------------------------
// SHARED RECORDS: claims and ESS requests saved in the database, so every computer sees the same list.
//
// The pages still work with the same lists as before ("claims" and "essRequests"); hims-shared-sync.js
// sends every change here and loads the latest list back. ?resource=shared-records
//   GET  &kind=claims|essRequests          the list (an employee only gets their own ESS requests)
//   POST {kind, upserts:[...], deletes:[...]}   save changes
//   GET  &receipt=<uid>                    view a claim receipt
//
// The server checks who may change what (the same rules as the screens):
//   - HR Staff encode claims and prepare ESS requests; only an HR Officer or Admin approves or rejects.
//   - Employees can only send new ESS requests for themselves.
// Table: shared_records (database/025_shared_records.sql). Receipts are stored outside the project folder.
// ---------------------------------------------------------------------

const SHARED_KINDS = ['claims', 'essRequests'];
const SHARED_HR_ROLES = ['HR1', 'HR3', 'ADMIN', 'HR_OFFICER', 'HR_STAFF'];
const SHARED_DECIDE_ROLES = ['HR1', 'HR3', 'ADMIN', 'HR_OFFICER'];
const RECEIPT_MAX_BYTES = 5 * 1024 * 1024;
const RECEIPT_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

function receiptDir(): string
{
    $dir = getenv('HIMS_RECEIPT_DIR') ?: '';
    if ($dir === '') {
        $resumes = getenv('HR3_UPLOAD_DIR') ?: '';
        $dir = $resumes !== ''
            ? dirname($resumes) . DIRECTORY_SEPARATOR . 'receipts'
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'hr3_uploads' . DIRECTORY_SEPARATOR . 'receipts';
    }
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the receipt folder.');
    }
    return $dir;
}

function sharedEmployeeCode(PDO $pdo, array $user): string
{
    $stmt = $pdo->prepare('SELECT employee_code FROM employees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    return (string)($stmt->fetchColumn() ?: '');
}

function sharedReceiptUrl(string $uid): string
{
    return 'backend/api.php?resource=shared-records&receipt=' . rawurlencode($uid);
}

// A receipt arrives as a data: URL inside the claim. Save it as a file and keep only a link in the record.
function sharedSaveReceipt(PDO $pdo, string $uid, string $dataUrl): array
{
    if (!preg_match('#^data:([a-z0-9.+/-]+);base64,(.+)$#is', $dataUrl, $m)) {
        throw new InvalidArgumentException('The receipt could not be read.');
    }
    $mime = strtolower($m[1]);
    $bytes = base64_decode($m[2], true);
    if (!isset(RECEIPT_TYPES[$mime]) || $bytes === false) {
        throw new InvalidArgumentException('Receipts must be a JPG, PNG, WEBP image or a PDF.');
    }
    if (strlen($bytes) > RECEIPT_MAX_BYTES) {
        throw new InvalidArgumentException('The receipt is larger than 5 MB.');
    }
    $head = substr($bytes, 0, 12);
    $ok = match ($mime) {
        'application/pdf' => str_starts_with($head, '%PDF'),
        'image/png' => str_starts_with($head, "\x89PNG"),
        'image/jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
        'image/webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
        default => false,
    };
    if (!$ok) {
        throw new InvalidArgumentException('The receipt file is not a real image or PDF.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . RECEIPT_TYPES[$mime];
    if (file_put_contents(receiptDir() . DIRECTORY_SEPARATOR . $stored, $bytes) === false) {
        throw new RuntimeException('Could not save the receipt.');
    }
    return [$stored, $mime];
}

function handleSharedRecords(string $method, array $user): never
{
    $role = (string)$user['role'];
    $isHr = in_array($role, SHARED_HR_ROLES, true);
    $isEmployee = $role === 'EMPLOYEE';
    if (!$isHr && !$isEmployee) {
        jsonResponse(['error' => 'Your role cannot use these records.'], 403);
    }
    $pdo = db();
    try {
        if ($method === 'GET' && isset($_GET['receipt'])) {
            if (!$isHr) {
                jsonResponse(['error' => 'HR access required.'], 403);
            }
            $stmt = $pdo->prepare("SELECT receipt_stored, receipt_mime FROM shared_records WHERE kind = 'claims' AND uid = ?");
            $stmt->execute([(string)$_GET['receipt']]);
            $row = $stmt->fetch();
            $stored = (string)($row['receipt_stored'] ?? '');
            $path = receiptDir() . DIRECTORY_SEPARATOR . $stored;
            if (!$row || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $stored) || !is_file($path)) {
                jsonResponse(['error' => 'Receipt not found.'], 404);
            }
            header('Content-Type: ' . $row['receipt_mime']);
            header('Content-Disposition: inline; filename="receipt.' . pathinfo($stored, PATHINFO_EXTENSION) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Content-Security-Policy: sandbox');
            header('Cache-Control: private, no-store');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }

        $kind = (string)($method === 'GET' ? ($_GET['kind'] ?? '') : (requestBody()['kind'] ?? ''));
        if (!in_array($kind, SHARED_KINDS, true)) {
            jsonResponse(['error' => 'Unknown record type.'], 422);
        }
        if ($isEmployee && $kind !== 'essRequests') {
            jsonResponse(['error' => 'Employees can only see their own self-service requests.'], 403);
        }
        $myCode = $isEmployee ? sharedEmployeeCode($pdo, $user) : '';

        if ($method === 'GET') {
            if ($isEmployee) {
                $stmt = $pdo->prepare('SELECT data FROM shared_records WHERE kind = ? AND owner_code = ? AND owner_code <> \'\' ORDER BY seq');
                $stmt->execute([$kind, $myCode]);
            } else {
                $stmt = $pdo->prepare('SELECT data FROM shared_records WHERE kind = ? ORDER BY seq');
                $stmt->execute([$kind]);
            }
            $records = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
                $row = json_decode((string)$json, true);
                if (is_array($row)) {
                    $records[] = $row;
                }
            }
            jsonResponse(['kind' => $kind, 'records' => $records]);
        }

        if ($method !== 'POST') {
            jsonResponse(['error' => 'Method not allowed.'], 405);
        }
        requireSameSiteHeader();
        $body = requestBody();
        $upserts = is_array($body['upserts'] ?? null) ? array_slice($body['upserts'], 0, 200) : [];
        $deletes = is_array($body['deletes'] ?? null) ? array_slice($body['deletes'], 0, 200) : [];
        $saved = [];
        $refused = [];

        $find = $pdo->prepare('SELECT seq, data, status, owner_code FROM shared_records WHERE kind = ? AND uid = ?');
        $insert = $pdo->prepare('INSERT INTO shared_records (kind, uid, owner_code, status, data, receipt_stored, receipt_mime, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $update = $pdo->prepare('UPDATE shared_records SET owner_code = ?, status = ?, data = ?, receipt_stored = COALESCE(?, receipt_stored), receipt_mime = COALESCE(?, receipt_mime), updated_by = ? WHERE seq = ?');

        foreach ($upserts as $record) {
            if (!is_array($record)) {
                continue;
            }
            $uid = (string)($record['_uid'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $uid)) {
                $refused[] = ['uid' => $uid, 'error' => 'Missing record id.'];
                continue;
            }
            $find->execute([$kind, $uid]);
            $existing = $find->fetch() ?: null;
            $old = $existing ? (json_decode((string)$existing['data'], true) ?: []) : [];
            $oldStatus = (string)($existing['status'] ?? '');
            $newStatus = mb_substr((string)($record['status'] ?? 'Pending'), 0, 40);
            $owner = mb_substr(trim((string)($kind === 'essRequests' ? ($record['empId'] ?? '') : ($record['id'] ?? ''))), 0, 60);

            // ---- who may do what
            $why = null;
            if ($isEmployee) {
                if ($existing) {
                    $why = 'Employees cannot change a request after sending it.';
                } elseif ($myCode === '' || $owner !== $myCode) {
                    $why = 'You can only send requests for yourself.';
                } elseif ($newStatus !== 'Pending') {
                    $why = 'New requests start as Pending.';
                }
            } elseif (!in_array($role, SHARED_DECIDE_ROLES, true)) { // HR Staff
                $statusChanged = $existing ? $newStatus !== $oldStatus : $newStatus !== 'Pending';
                if ($statusChanged) {
                    $allowed = $kind === 'essRequests' && $oldStatus === 'Approved' && $newStatus === 'Completed';
                    if (!$allowed) {
                        $why = $kind === 'claims' ? 'Claims are approved by an HR Officer.' : 'ESS requests are approved by an HR Officer. HR Staff mark approved ones Completed.';
                    }
                }
            }
            if ($why !== null) {
                $refused[] = ['uid' => $uid, 'error' => $why];
                continue;
            }

            // ---- receipt: keep files out of the record
            $receiptStored = null;
            $receiptMime = null;
            if ($kind === 'claims') {
                $receipt = $record['receipt'] ?? null;
                if (is_string($receipt) && str_starts_with($receipt, 'data:')) {
                    try {
                        [$receiptStored, $receiptMime] = sharedSaveReceipt($pdo, $uid, $receipt);
                    } catch (InvalidArgumentException $error) {
                        $refused[] = ['uid' => $uid, 'error' => $error->getMessage()];
                        continue;
                    }
                    $record['receipt'] = sharedReceiptUrl($uid);
                } elseif ($existing && !empty($old['receipt'])) {
                    $record['receipt'] = $old['receipt']; // a receipt cannot be swapped for an outside link
                } else {
                    $record['receipt'] = null;
                }
            }
            if ($existing && $isHr) {
                $record['_updatedBy'] = (string)$user['name'];
            }
            if (!$existing) {
                $record['_createdBy'] = (string)$user['name'];
            }
            $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > 60000) {
                $refused[] = ['uid' => $uid, 'error' => 'The record is too large.'];
                continue;
            }
            if ($existing) {
                $update->execute([$owner, $newStatus, $json, $receiptStored, $receiptMime, $user['id'], $existing['seq']]);
            } else {
                $insert->execute([$kind, $uid, $owner, $newStatus, $json, $receiptStored, $receiptMime, $user['id'], $user['id']]);
            }
            $saved[] = $record;
            // HR4 gets claims and reimbursements once an HR Officer decides them.
            $decided = in_array($newStatus, ['Approved', 'Rejected'], true) && $newStatus !== $oldStatus;
            if ($decided && $kind === 'claims' && function_exists('pushOnClaimDecided')) {
                pushOnClaimDecided($pdo, $record);
            } elseif ($decided && $kind === 'essRequests' && ($record['type'] ?? '') === 'Reimbursement / claim' && function_exists('pushOnReimbursementDecided')) {
                pushOnReimbursementDecided($pdo, $record);
            }
        }

        // Only HR Officers / Admin delete; everyone else archives.
        $deleted = [];
        if ($deletes && in_array($role, SHARED_DECIDE_ROLES, true)) {
            $del = $pdo->prepare('DELETE FROM shared_records WHERE kind = ? AND uid = ?');
            foreach ($deletes as $uid) {
                $del->execute([$kind, (string)$uid]);
                if ($del->rowCount()) {
                    $deleted[] = (string)$uid;
                }
            }
        } elseif ($deletes) {
            foreach ($deletes as $uid) {
                $refused[] = ['uid' => (string)$uid, 'error' => 'Only an HR Officer or Admin can delete records.'];
            }
        }
        jsonResponse(['saved' => $saved, 'deleted' => $deleted, 'refused' => $refused]);
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) === 1146) {
            jsonResponse(['error' => 'Run database/025_shared_records.sql in phpMyAdmin so claims and ESS requests are saved to the database.', 'setup' => true], 500);
        }
        throw $error;
    }
}

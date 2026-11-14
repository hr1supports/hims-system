<?php
declare(strict_types=1);

// Hiring API: public job list + application upload, and admin vacancy/application/resume endpoints.

const RESUME_MAX_BYTES = 5 * 1024 * 1024;
const RESUME_TYPES = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
];
const APPLICATION_STATUSES = ['Applied', 'Screening', 'Interview', 'Hired', 'Rejected'];
const VACANCY_TYPES = ['Full-time', 'Part-time', 'Contract', 'Trainee'];

// Resumes are stored OUTSIDE the project folder so the PHP server can never serve them directly.
function resumeDir(): string
{
    $dir = getenv('HR3_UPLOAD_DIR') ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'hr3_uploads' . DIRECTORY_SEPARATOR . 'resumes';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the resume folder.');
    }
    return $dir;
}

// Check the real file header, not just the extension.
function resumeContentOk(string $ext, string $path): bool
{
    $head = (string)file_get_contents($path, false, null, 0, 8);
    return match ($ext) {
        'pdf' => str_starts_with($head, '%PDF'),
        'png' => str_starts_with($head, "\x89PNG"),
        'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
        'doc' => str_starts_with($head, "\xD0\xCF\x11\xE0"),
        'docx' => str_starts_with($head, "PK\x03\x04"),
        default => false,
    };
}

function clientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // Believe forwarded headers only when the connection comes from this machine or a private-network proxy
    // (Cloudflare tunnel, Docker network, hosting ingress). Otherwise every visitor would share one address.
    $fromProxy = in_array($ip, ['127.0.0.1', '::1'], true)
        || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($fromProxy) {
        $forwarded = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
            return $forwarded;
        }
        $chain = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        $last = end($chain); // the entry added by our own proxy; earlier entries can be forged by the visitor
        if ($last !== false && filter_var($last, FILTER_VALIDATE_IP)) {
            return $last;
        }
    }
    return $ip;
}

// Qualifications column (database/022_vacancy_qualifications.sql). Until it is run, pages keep working without it.
function vacancyQualificationsReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT qualifications FROM vacancies LIMIT 1'); $ready = true; }
        catch (Throwable $error) { $ready = false; }
    }
    return $ready;
}

// Job boards + applicant channel (database/023_job_boards.sql). Until it is run, pages keep working without it.
function hiringColumnReady(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = "$table.$column";
    if (!isset($cache[$key])) {
        try { $pdo->query("SELECT `$column` FROM `$table` LIMIT 1"); $cache[$key] = true; }
        catch (Throwable $error) { $cache[$key] = false; }
    }
    return $cache[$key];
}
const APPLICANT_CHANNELS = ['linkedin' => 'LinkedIn', 'jobstreet' => 'JobStreet', 'facebook' => 'Facebook'];

// The job's link on LinkedIn or JobStreet. Empty is allowed; anything else must be https on that site.
function jobBoardUrl(string $url, array $hosts, string $label): string
{
    $url = trim($url);
    if ($url === '') return '';
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $ok = strlen($url) <= 500 && str_starts_with(strtolower($url), 'https://');
    if ($ok) {
        $ok = false;
        foreach ($hosts as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) { $ok = true; break; }
        }
    }
    if (!$ok) {
        jsonResponse(['error' => "The $label link must start with https:// and be on " . implode(' or ', $hosts) . '.'], 422);
    }
    return $url;
}

// One qualification per line; blank lines and bullet marks removed. Max 20 lines, 200 characters each.
function cleanQualifications(string $text): string
{
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim((string)preg_replace('/^\s*(?:[\-\*\x{2022}]+|\d+[\.\)])?\s*/u', '', $line));
        if ($line !== '') $lines[] = mb_substr($line, 0, 200);
    }
    return implode("\n", array_slice($lines, 0, 20));
}

function validDate(string $value): bool
{
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

// ---------------------------------------------------------------- public (no login)
function handlePublicHiring(string $resource, string $method): void
{
    if ($resource === 'public-vacancies' && $method === 'GET') {
        $qual = vacancyQualificationsReady(db()) ? 'qualifications' : "'' AS qualifications";
        $boards = hiringColumnReady(db(), 'vacancies', 'linkedin_url')
            ? 'linkedin_url AS linkedinUrl, jobstreet_url AS jobstreetUrl' : "'' AS linkedinUrl, '' AS jobstreetUrl";
        $rows = db()->query(
            "SELECT id, title, department, employment_type AS type, slots, description, $qual, $boards, DATE_FORMAT(deadline, '%Y-%m-%d') AS deadline
             FROM vacancies WHERE status = 'Open' AND archived = 0 AND (deadline IS NULL OR deadline >= CURDATE()) ORDER BY id"
        )->fetchAll();
        jsonResponse(['records' => $rows]);
    }
    if ($resource !== 'apply' || $method !== 'POST') {
        return;
    }

    $pdo = db();
    $vacancyId = (int)($_POST['vacancy_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($name === '' || mb_strlen($name) > 150) {
        jsonResponse(['error' => 'Enter your full name.'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        jsonResponse(['error' => 'Enter a valid email address.'], 422);
    }
    if (mb_strlen($phone) > 50 || mb_strlen($note) > 1000) {
        jsonResponse(['error' => 'Phone or message is too long.'], 422);
    }

    $vacancy = $pdo->prepare("SELECT id, title FROM vacancies WHERE id = ? AND status = 'Open' AND archived = 0 AND (deadline IS NULL OR deadline >= CURDATE())");
    $vacancy->execute([$vacancyId]);
    $vacancy = $vacancy->fetch();
    if (!$vacancy) {
        jsonResponse(['error' => 'This position is no longer open.'], 422);
    }

    $file = $_FILES['resume'] ?? null;
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        jsonResponse(['error' => 'Attach your resume.'], 422);
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || $file['size'] > RESUME_MAX_BYTES) {
        jsonResponse(['error' => 'Resume is larger than 5 MB. Attach a smaller file.'], 422);
    }
    if ($err !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'The upload failed. Please try again.'], 422);
    }
    $original = basename(str_replace('\\', '/', (string)$file['name']));
    $original = mb_substr((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $original), 0, 200);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!isset(RESUME_TYPES[$ext]) || !is_uploaded_file($file['tmp_name']) || !resumeContentOk($ext, $file['tmp_name'])) {
        jsonResponse(['error' => 'Resume must be a real PDF, DOC, DOCX, JPG or PNG file.'], 422);
    }

    $ip = clientIp();
    $recent = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
    $recent->execute([$ip]);
    if ((int)$recent->fetchColumn() >= 10) {
        jsonResponse(['error' => 'Too many applications from this connection. Please try again later.'], 429);
    }
    $dupe = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE email = ? AND vacancy_id = ? AND status <> 'Rejected'");
    $dupe->execute([$email, $vacancy['id']]);
    if ((int)$dupe->fetchColumn() > 0) {
        jsonResponse(['error' => 'You already applied for this position with that email.'], 409);
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = resumeDir() . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save the uploaded resume.');
    }
    try {
        $pdo->prepare(
            'INSERT INTO applications (vacancy_id, position_title, full_name, email, phone, note, resume_original_name, resume_stored_name, resume_size, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$vacancy['id'], $vacancy['title'], $name, $email, $phone ?: null, $note ?: null, $original, $stored, (int)$file['size'], $ip]);
        $newApplicationId = (int)$pdo->lastInsertId(); // read first: another query resets it
        $channel = APPLICANT_CHANNELS[strtolower(trim((string)($_POST['source'] ?? '')))] ?? null;
        if ($channel !== null && hiringColumnReady($pdo, 'applications', 'channel')) {
            $pdo->prepare('UPDATE applications SET channel = ? WHERE id = ?')->execute([$channel, $newApplicationId]);
        }
    } catch (Throwable $e) {
        @unlink($dest);
        throw $e;
    }
    jsonResponse(['message' => 'Application received.'], 201);
}

// ---------------------------------------------------------------- HR3 system pushes a hire order (server to server, no browser login)
function handleHireOrder(string $resource, string $method): void
{
    if ($resource !== 'hire-order' || $method !== 'POST') {
        return;
    }
    // HR3 can use the key the Super Admin created on File Exchange > Connections, or the older
    // HR3_INTEGRATION_KEY server setting. Either header works: X-HR3-Key or X-Integration-Key.
    $given = (string)($_SERVER['HTTP_X_HR3_KEY'] ?? ($_SERVER['HTTP_X_INTEGRATION_KEY'] ?? ''));
    $ok = false;
    if (function_exists('exchangePartnerForKey')) {
        try {
            $ok = exchangePartnerForKey(db(), $given) === 'HR3';
        } catch (PDOException $error) {
            $ok = false; // integration tables not created yet: fall back to the server setting below
        }
    }
    if (!$ok) {
        $expected = (string)(getenv('HR3_INTEGRATION_KEY') ?: '');
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            jsonResponse(['error' => 'Invalid integration key. Ask the HIMS Super Admin for the HR3 key.'], 401);
        }
    }
    $b = requestBody();
    $ref = trim((string)($b['reference'] ?? ''));
    $title = trim((string)($b['title'] ?? ''));
    $dept = trim((string)($b['department'] ?? ''));
    $type = (string)($b['type'] ?? 'Full-time');
    $slots = (int)($b['slots'] ?? 1);
    $deadline = trim((string)($b['deadline'] ?? ''));
    $desc = trim((string)($b['description'] ?? ''));
    $by = trim((string)($b['requested_by'] ?? ''));
    if ($ref === '' || mb_strlen($ref) > 60 || $title === '' || mb_strlen($title) > 150 || $dept === '' || mb_strlen($dept) > 100 || mb_strlen($by) > 150) {
        jsonResponse(['error' => 'reference, title and department are required (within the length limits).'], 422);
    }
    if (!in_array($type, VACANCY_TYPES, true) || $slots < 1 || $slots > 1000) {
        jsonResponse(['error' => 'type must be one of ' . implode(', ', VACANCY_TYPES) . ' and slots between 1 and 1000.'], 422);
    }
    if ($deadline !== '' && !validDate($deadline)) {
        jsonResponse(['error' => 'deadline must be a date like 2026-12-31.'], 422);
    }
    $pdo = db();
    $dupe = $pdo->prepare("SELECT id FROM vacancies WHERE source = 'HR3' AND source_ref = ?");
    $dupe->execute([$ref]);
    if ($dupe->fetchColumn()) {
        jsonResponse(['error' => 'This hire order was already received.'], 409);
    }
    $pdo->prepare("INSERT INTO vacancies (title, department, employment_type, slots, deadline, description, status, source, source_ref, requested_by) VALUES (?, ?, ?, ?, ?, ?, 'Pending', 'HR3', ?, ?)")
        ->execute([$title, $dept, $type, $slots, $deadline ?: null, $desc ?: null, $ref, $by ?: null]);
    $newId = (int)$pdo->lastInsertId();
    $qual = cleanQualifications((string)($b['qualifications'] ?? ''));
    if ($qual !== '' && vacancyQualificationsReady($pdo)) {
        $pdo->prepare('UPDATE vacancies SET qualifications = ? WHERE id = ?')->execute([$qual, $newId]);
    }
    jsonResponse(['id' => $newId, 'message' => 'Hire order received. HR1 will review it.'], 201);
}

// ---------------------------------------------------------------- admin (HR3 / ADMIN login already checked)
function handleAdminHiring(string $resource, string $method, PDO $pdo, array $user): void
{
    if ($resource === 'vacancies') {
        if ($method === 'GET') {
            $qual = vacancyQualificationsReady($pdo) ? 'v.qualifications' : "'' AS qualifications";
            $boards = hiringColumnReady($pdo, 'vacancies', 'linkedin_url')
                ? 'v.linkedin_url AS linkedinUrl, v.jobstreet_url AS jobstreetUrl' : "'' AS linkedinUrl, '' AS jobstreetUrl";
            $rows = $pdo->query(
                "SELECT v.id, v.title, v.department AS dept, v.employment_type AS type, v.slots, v.status, v.archived, v.description, $qual, $boards,
                        v.source, v.source_ref AS sourceRef, v.requested_by AS requestedBy, DATE_FORMAT(v.created_at, '%Y-%m-%d') AS received,
                        DATE_FORMAT(v.deadline, '%Y-%m-%d') AS deadline,
                        (SELECT COUNT(*) FROM applications a WHERE a.vacancy_id = v.id) AS applications
                 FROM vacancies v ORDER BY v.id DESC"
            )->fetchAll();
            jsonResponse(['records' => $rows]);
        }
        if ($method === 'POST') {
            $b = requestBody();
            $action = (string)($b['action'] ?? '');
            if ($action === 'create') {
                $title = trim((string)($b['title'] ?? ''));
                $dept = trim((string)($b['dept'] ?? ''));
                $type = (string)($b['type'] ?? 'Full-time');
                $slots = (int)($b['slots'] ?? 0);
                $deadline = trim((string)($b['deadline'] ?? ''));
                $desc = trim((string)($b['description'] ?? ''));
                if ($title === '' || $dept === '' || mb_strlen($title) > 150 || mb_strlen($dept) > 100) {
                    jsonResponse(['error' => 'Job title and department are required.'], 422);
                }
                if (!in_array($type, VACANCY_TYPES, true) || $slots < 1 || $slots > 1000) {
                    jsonResponse(['error' => 'Choose a valid job type and number of slots.'], 422);
                }
                if ($deadline !== '' && !validDate($deadline)) {
                    jsonResponse(['error' => 'Deadline is not a valid date.'], 422);
                }
                $pdo->prepare('INSERT INTO vacancies (title, department, employment_type, slots, deadline, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$title, $dept, $type, $slots, $deadline ?: null, $desc ?: null, $user['id']]);
                $newId = (int)$pdo->lastInsertId();
                $qual = cleanQualifications((string)($b['qualifications'] ?? ''));
                if ($qual !== '' && vacancyQualificationsReady($pdo)) {
                    $pdo->prepare('UPDATE vacancies SET qualifications = ? WHERE id = ?')->execute([$qual, $newId]);
                }
                jsonResponse(['id' => $newId, 'message' => 'Vacancy posted.'], 201);
            }
            $id = (int)($b['id'] ?? 0);
            if ($action === 'status') {
                $status = (string)($b['status'] ?? '');
                if (!in_array($status, ['Pending', 'Open', 'Closed'], true)) {
                    jsonResponse(['error' => 'Invalid status.'], 422);
                }
                $pdo->prepare('UPDATE vacancies SET status = ? WHERE id = ?')->execute([$status, $id]);
                jsonResponse(['message' => 'Vacancy updated.']);
            }
            if ($action === 'boards') {
                if (!hiringColumnReady($pdo, 'vacancies', 'linkedin_url')) {
                    jsonResponse(['error' => 'Run database/023_job_boards.sql in MySQL Workbench first.'], 500);
                }
                $linkedin = jobBoardUrl((string)($b['linkedinUrl'] ?? ''), ['linkedin.com'], 'LinkedIn');
                $jobstreet = jobBoardUrl((string)($b['jobstreetUrl'] ?? ''), ['jobstreet.com', 'jobstreet.com.ph'], 'JobStreet');
                $pdo->prepare('UPDATE vacancies SET linkedin_url = ?, jobstreet_url = ? WHERE id = ?')
                    ->execute([$linkedin ?: null, $jobstreet ?: null, $id]);
                jsonResponse(['message' => 'Job board links saved.']);
            }
            if ($action === 'qualifications') {
                if (!vacancyQualificationsReady($pdo)) {
                    jsonResponse(['error' => 'Run database/022_vacancy_qualifications.sql in MySQL Workbench first.'], 500);
                }
                $qual = cleanQualifications((string)($b['qualifications'] ?? ''));
                $pdo->prepare('UPDATE vacancies SET qualifications = ? WHERE id = ?')->execute([$qual !== '' ? $qual : null, $id]);
                jsonResponse(['message' => 'Qualifications saved.', 'qualifications' => $qual]);
            }
            if ($action === 'archive' || $action === 'restore') {
                $pdo->prepare('UPDATE vacancies SET archived = ? WHERE id = ?')->execute([$action === 'archive' ? 1 : 0, $id]);
                jsonResponse(['message' => $action === 'archive' ? 'Vacancy archived.' : 'Vacancy restored.']);
            }
            jsonResponse(['error' => 'Unknown vacancy action.'], 422);
        }
    }

    if ($resource === 'applications') {
        if ($method === 'GET') {
            $rows = $pdo->query(
                "SELECT id, vacancy_id AS vacancyId, position_title AS vacancy, full_name AS name, email, phone, note, status, archived, "
                    . (hiringColumnReady($pdo, 'applications', 'channel') ? 'channel' : "IF(resume_stored_name IS NULL, 'Walk-in', 'Careers page') AS channel") . ",
                        resume_original_name AS resumeName, DATE_FORMAT(created_at, '%Y-%m-%d') AS date
                 FROM applications ORDER BY created_at DESC, id DESC LIMIT 500"
            )->fetchAll();
            jsonResponse(['records' => $rows]);
        }
        if ($method === 'POST') {
            $b = requestBody();
            $action = (string)($b['action'] ?? '');
            if ($action === 'create') { // walk-in applicant added by HR, no resume
                $name = trim((string)($b['name'] ?? ''));
                $email = strtolower(trim((string)($b['email'] ?? '')));
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    jsonResponse(['error' => 'Applicant name and a valid email are required.'], 422);
                }
                $vac = $pdo->prepare('SELECT id, title FROM vacancies WHERE id = ?');
                $vac->execute([(int)($b['vacancy_id'] ?? 0)]);
                $vac = $vac->fetch();
                if (!$vac) {
                    jsonResponse(['error' => 'Choose a vacancy.'], 422);
                }
                $phone = trim((string)($b['phone'] ?? ''));
                if (hiringColumnReady($pdo, 'applications', 'channel')) {
                    $pdo->prepare("INSERT INTO applications (vacancy_id, position_title, full_name, email, phone, channel) VALUES (?, ?, ?, ?, ?, 'Walk-in')")
                        ->execute([$vac['id'], $vac['title'], $name, $email, $phone ?: null]);
                } else {
                    $pdo->prepare('INSERT INTO applications (vacancy_id, position_title, full_name, email, phone) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$vac['id'], $vac['title'], $name, $email, $phone ?: null]);
                }
                jsonResponse(['id' => (int)$pdo->lastInsertId(), 'message' => 'Applicant added.'], 201);
            }
            $id = (int)($b['id'] ?? 0);
            if ($action === 'status') {
                $status = (string)($b['status'] ?? '');
                if (!in_array($status, APPLICATION_STATUSES, true)) {
                    jsonResponse(['error' => 'Invalid status.'], 422);
                }
                $before = $pdo->prepare('SELECT status FROM applications WHERE id = ?');
                $before->execute([$id]);
                $wasStatus = (string)($before->fetchColumn() ?: '');
                $pdo->prepare('UPDATE applications SET status = ? WHERE id = ?')->execute([$status, $id]);
                if ($status === 'Hired' && $wasStatus !== 'Hired' && function_exists('pushOnHired')) {
                    pushOnHired($pdo, $id); // the Administrators' system gets the hired applicant
                }
                jsonResponse(['message' => 'Status updated.']);
            }
            if ($action === 'archive' || $action === 'restore') {
                $pdo->prepare('UPDATE applications SET archived = ? WHERE id = ?')->execute([$action === 'archive' ? 1 : 0, $id]);
                jsonResponse(['message' => $action === 'archive' ? 'Application archived.' : 'Application restored.']);
            }
            jsonResponse(['error' => 'Unknown application action.'], 422);
        }
    }

    if ($resource === 'resume' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT resume_original_name, resume_stored_name FROM applications WHERE id = ?');
        $stmt->execute([(int)($_GET['id'] ?? 0)]);
        $row = $stmt->fetch();
        $stored = (string)($row['resume_stored_name'] ?? '');
        $path = resumeDir() . DIRECTORY_SEPARATOR . $stored;
        if (!$row || !preg_match('/^[a-f0-9]{32}\.(pdf|docx?|jpe?g|png)$/', $stored) || !is_file($path)) {
            jsonResponse(['error' => 'Resume file not found.'], 404);
        }
        $ext = substr($stored, strrpos($stored, '.') + 1);
        $filename = rawurlencode((string)$row['resume_original_name']);
        header('Content-Type: ' . RESUME_TYPES[$ext]);
        header("Content-Disposition: attachment; filename*=UTF-8''" . $filename);
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}

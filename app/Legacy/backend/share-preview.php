<?php
declare(strict_types=1);

// Link preview for LinkedIn / Facebook / Messenger.
// Their servers read <meta property="og:..."> tags from the page to draw the preview card.
// careers.html is the same for every job, so the router sends it through here to add tags for
// the job in ?vacancy= (title, department, qualifications) and the share image og-image.png.
// The page itself is not changed in any other way.

function himsCareersPage(): void
{
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'careers.html';
    $html = (string)file_get_contents($file);

    // Full address of this site (the tunnel or the real domain), needed because previews require absolute links.
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    // Behind a hosting proxy (HostForge, Docker) the visitor's https is reported in X-Forwarded-Proto.
    $proxy = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    $scheme = (isHttps() || ($proxy && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')) ? 'https' : 'http';
    $base = $scheme . '://' . $host;

    $title = 'Careers at HIMS Hospital';
    $description = "We're hiring nurses, doctors, security and support staff. Check the qualifications and apply online.";
    $url = $base . '/careers.html';

    $vacancyId = (int)($_GET['vacancy'] ?? 0);
    if ($vacancyId > 0) {
        try {
            $qual = vacancyQualificationsReady(db()) ? 'qualifications' : "'' AS qualifications";
            $q = db()->prepare("SELECT id, title, department, employment_type AS type, slots, $qual FROM vacancies
                WHERE id = ? AND status = 'Open' AND archived = 0 AND (deadline IS NULL OR deadline >= CURDATE())");
            $q->execute([$vacancyId]);
            $job = $q->fetch();
            if ($job) {
                $title = "We're hiring: {$job['title']} ({$job['department']}) | HIMS Hospital";
                $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$job['qualifications']))));
                $description = $job['type'] . ' - ' . (int)$job['slots'] . ((int)$job['slots'] === 1 ? ' slot' : ' slots') . '. '
                    . ($lines ? 'Qualifications Required: ' . implode('; ', array_slice($lines, 0, 4)) . (count($lines) > 4 ? '; ...' : '') . '. ' : '')
                    . 'Apply online with your resume.';
                $url = $base . '/careers.html?vacancy=' . (int)$job['id'];
            }
        } catch (Throwable $error) {
            // Database not reachable: keep the general preview.
        }
    }

    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $tags = "\n" . implode("\n", [
        '<meta name="description" content="' . $e($description) . '">',
        '<meta property="og:type" content="website">',
        '<meta property="og:site_name" content="HIMS Hospital System">',
        '<meta property="og:title" content="' . $e($title) . '">',
        '<meta property="og:description" content="' . $e($description) . '">',
        '<meta property="og:url" content="' . $e($url) . '">',
        '<meta property="og:image" content="' . $e($base . '/og-image.png') . '">',
        '<meta property="og:image:width" content="1200">',
        '<meta property="og:image:height" content="627">',
        '<meta property="og:image:alt" content="HIMS Hospital System - We\'re hiring">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<link rel="canonical" href="' . $e($url) . '">',
    ]);
    $html = preg_replace('#</title>#i', '</title>' . $tags, $html, 1) ?? $html;

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    echo $html;
}

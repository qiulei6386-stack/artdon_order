<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(200);

function clip_text(string $value, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    if (function_exists('iconv_substr')) {
        $clipped = iconv_substr($value, 0, $max, 'UTF-8');
        if ($clipped !== false) return $clipped;
    }
    $clipped = substr($value, 0, $max);
    while ($clipped !== '' && preg_match('//u', $clipped) !== 1) {
        $clipped = substr($clipped, 0, -1);
    }
    return $clipped;
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'POST requests only.']);
}

if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
    respond(419, ['success' => false, 'message' => 'The form session expired. Refresh the page and try again.']);
}

$submissionToken = preg_replace('/[^a-f0-9]/i', '', (string) ($_POST['submission_token'] ?? ''));
if (strlen($submissionToken) !== 40) {
    respond(422, ['success' => false, 'message' => 'The submission token is invalid. Refresh the page and try again.']);
}
$usedTokens = (array) ($_SESSION['used_submission_tokens'] ?? []);
if (isset($usedTokens[$submissionToken])) {
    respond(200, [
        'success' => true,
        'duplicate' => true,
        'message' => 'This request was already recorded.',
        'reference' => (string) $usedTokens[$submissionToken],
        'next_submission_token' => bin2hex(random_bytes(20)),
    ]);
}

if (trim((string) ($_POST['website'] ?? '')) !== '') {
    respond(200, ['success' => true, 'message' => 'Request received.', 'reference' => 'OK']);
}

$now = time();
$recent = array_values(array_filter((array) ($_SESSION['submission_times'] ?? []), static fn ($timestamp): bool => $now - (int) $timestamp < 60));
if (count($recent) >= 5) {
    respond(429, ['success' => false, 'message' => 'Too many requests. Please retry shortly.']);
}
$recent[] = $now;
$_SESSION['submission_times'] = $recent;

$clean = static function (string $key, int $max = 5000): string {
    $value = trim((string) ($_POST[$key] ?? ''));
    $value = preg_replace('/\R/u', "\n", $value) ?? $value;
    return clip_text($value, $max);
};

$formType = preg_replace('/[^a-z0-9_-]/i', '', $clean('form_type', 60)) ?: 'request';
$company = $clean('company', 180);
$name = $clean('name', 120);
$email = $clean('email', 180);
$country = $clean('country', 120);

$errors = [];
if ($company === '') $errors[] = 'Company is required.';
if ($name === '') $errors[] = 'Contact name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
if ($country === '') $errors[] = 'Country or region is required.';
if ($errors) respond(422, ['success' => false, 'message' => implode(' ', $errors)]);

$prefixMap = [
    'order_request' => 'WO', 'quick_rfq' => 'RFQ', 'quick-rfq' => 'RFQ',
    'sample-order' => 'SMP', 'project-package' => 'PRJ', 'bulk-order' => 'BLK',
    'ready-stock' => 'RST', 'procurement-service' => 'SRV',
    'oem' => 'OEM', 'odm' => 'ODM', 'contact' => 'MSG',
];
$prefix = $prefixMap[$formType] ?? 'REQ';
$reference = sprintf('%s-%s-%s', $prefix, date('Ymd-His'), strtoupper(bin2hex(random_bytes(2))));

$allowedExtensions = ['pdf','xls','xlsx','csv','doc','docx','dwg','dxf','zip','jpg','jpeg','png','webp','ies','ldt'];
$uploadRecords = [];
$files = $_FILES['attachments'] ?? null;
if ($files && is_array($files['name'] ?? null)) {
    $uploadDir = __DIR__ . '/../storage/uploads/' . date('Y/m');
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        respond(500, ['success' => false, 'message' => 'Upload storage is not available.']);
    }
    foreach ($files['name'] as $index => $originalName) {
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) respond(422, ['success' => false, 'message' => 'One of the uploaded files could not be received.']);
        $size = (int) ($files['size'][$index] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) respond(422, ['success' => false, 'message' => 'Each file must be between 1 byte and 10 MB.']);
        $extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) respond(422, ['success' => false, 'message' => 'Unsupported attachment type: ' . $extension]);
        $tmpName = (string) ($files['tmp_name'][$index] ?? '');
        $head = is_file($tmpName) ? (string) file_get_contents($tmpName, false, null, 0, 4096) : '';
        if ($head !== '' && preg_match('/<\?(?:php|=)/i', $head)) {
            respond(422, ['success' => false, 'message' => 'Executable content is not permitted in attachments.']);
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
            if ($finfo) finfo_close($finfo);
            $blockedMimes = ['text/x-php','application/x-httpd-php','application/x-executable','application/x-dosexec','application/x-sharedlib'];
            if (in_array(strtolower($mime), $blockedMimes, true)) {
                respond(422, ['success' => false, 'message' => 'Executable files are not permitted.']);
            }
        }
        $storedName = $reference . '-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $target = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file((string) $files['tmp_name'][$index], $target)) respond(500, ['success' => false, 'message' => 'An attachment could not be stored.']);
        chmod($target, 0640);
        $uploadRecords[] = ['original_name' => clip_text(basename((string) $originalName), 220), 'stored_name' => $storedName, 'size' => $size, 'extension' => $extension];
    }
}

$fields = [];
foreach ($_POST as $key => $value) {
    if (in_array($key, ['csrf_token','submission_token','website'], true) || is_array($value)) continue;
    $safeKey = preg_replace('/[^a-z0-9_-]/i', '', (string) $key);
    if ($safeKey === '') continue;
    $fields[$safeKey] = clip_text(trim((string) $value), $safeKey === 'cart_json' ? 50000 : 5000);
}

$record = [
    'reference' => $reference,
    'created_at' => date(DATE_ATOM),
    'form_type' => $formType,
    'company' => $company,
    'name' => $name,
    'email' => strtolower($email),
    'country' => $country,
    'fields' => $fields,
    'attachments' => $uploadRecords,
    'request' => [
        'ip_hash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')),
        'user_agent' => clip_text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 500),
        'referer' => clip_text((string) ($_SERVER['HTTP_REFERER'] ?? ''), 500),
    ],
];

$logFile = __DIR__ . '/../storage/submissions.jsonl';
$line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
if (file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) === false) {
    respond(500, ['success' => false, 'message' => 'The request could not be recorded.']);
}
@chmod($logFile, 0640);

$usedTokens[$submissionToken] = $reference;
if (count($usedTokens) > 50) {
    $usedTokens = array_slice($usedTokens, -50, null, true);
}
$_SESSION['used_submission_tokens'] = $usedTokens;

if (($site['enable_mail'] ?? false) === true) {
    $safeCompany = str_replace(["\r", "\n"], ' ', $company);
    $subject = sprintf('[%s] %s from %s', $reference, strtoupper(str_replace(['_','-'], ' ', $formType)), $safeCompany);
    $body = "Reference: {$reference}\nType: {$formType}\nCompany: {$company}\nName: {$name}\nEmail: {$email}\nCountry: {$country}\n\n" . print_r($fields, true);
    $headers = [
        'From: ' . $site['order_email'],
        'Reply-To: ' . str_replace(["\r", "\n"], '', $email),
        'Content-Type: text/plain; charset=UTF-8',
    ];
    @mail((string) $site['order_email'], $subject, $body, implode("\r\n", $headers));
}

respond(200, [
    'success' => true,
    'message' => 'Your request has been recorded for review.',
    'reference' => $reference,
    'next_submission_token' => bin2hex(random_bytes(20)),
]);

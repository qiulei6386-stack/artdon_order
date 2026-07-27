<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/procurement.php';

$requestId = api_request_id();

/**
 * Keep the original form response contract while adding a stable error code.
 *
 * @param array<string,mixed> $payload
 */
function procurement_api_respond(int $status, array $payload): never
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    if (!headers_sent()) {
        header('Allow: POST');
    }
    procurement_api_respond(405, [
        'success' => false,
        'message' => 'POST requests only.',
        'error' => ['code' => 'method_not_allowed'],
        'request_id' => $requestId,
    ]);
}

api_rate_limit('procurement-submit', 20, 3600);

try {
    artdon_procurement_verify_csrf((string) ($_POST['csrf_token'] ?? ''));

    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        procurement_api_respond(200, [
            'success' => true,
            'message' => 'Request received.',
            'reference' => 'OK',
            'next_submission_token' => bin2hex(random_bytes(20)),
            'request_id' => $requestId,
        ]);
    }

    $now = time();
    $recent = array_values(array_filter(
        (array) ($_SESSION['submission_times'] ?? []),
        static fn(mixed $timestamp): bool => $now - (int) $timestamp < 60
    ));
    if (count($recent) >= 5) {
        procurement_api_respond(429, [
            'success' => false,
            'message' => 'Too many requests. Please retry shortly.',
            'error' => ['code' => 'rate_limited'],
            'request_id' => $requestId,
        ]);
    }
    $recent[] = $now;
    $_SESSION['submission_times'] = $recent;

    $pdo = artdon_db_open_ready();
    $result = artdon_procurement_submit(
        $_POST,
        is_array($_FILES['attachments'] ?? null) ? $_FILES['attachments'] : [],
        api_session_hash(),
        $pdo,
        [
            'request_id' => $requestId,
            'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'referer' => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
        ]
    );

    $_SESSION['used_submission_tokens'][(string) ($_POST['submission_token'] ?? '')] = $result['reference'];
    if (count((array) $_SESSION['used_submission_tokens']) > 50) {
        $_SESSION['used_submission_tokens'] = array_slice(
            (array) $_SESSION['used_submission_tokens'],
            -50,
            null,
            true
        );
    }

    if (($site['enable_mail'] ?? false) === true && empty($result['duplicate'])) {
        $safeCompany = str_replace(["\r", "\n"], ' ', (string) ($_POST['company'] ?? ''));
        $safeEmail = str_replace(["\r", "\n"], '', (string) ($_POST['email'] ?? ''));
        $subject = sprintf('[%s] Procurement request from %s', $result['reference'], $safeCompany);
        $body = sprintf(
            "Reference: %s\nType: %s\nCompany: %s\nName: %s\nEmail: %s\nCountry: %s\n",
            $result['reference'],
            $result['request_type'],
            $safeCompany,
            (string) ($_POST['name'] ?? ''),
            $safeEmail,
            (string) ($_POST['country'] ?? '')
        );
        $headers = [
            'From: ' . $site['order_email'],
            'Reply-To: ' . $safeEmail,
            'Content-Type: text/plain; charset=UTF-8',
        ];
        @mail((string) $site['order_email'], $subject, $body, implode("\r\n", $headers));
    }

    procurement_api_respond(200, array_merge($result, [
        'next_submission_token' => bin2hex(random_bytes(20)),
        'request_id' => $requestId,
    ]));
} catch (ArtdonProcurementException $error) {
    procurement_api_respond($error->httpStatus, [
        'success' => false,
        'message' => $error->getMessage(),
        'error' => [
            'code' => $error->errorCode,
            'details' => $error->details,
        ],
        'request_id' => $requestId,
    ]);
} catch (ArtdonDatabaseUnavailable $error) {
    error_log(sprintf('[procurement:%s] Database is not ready: %s', $requestId, $error->getMessage()));
    if (!headers_sent()) {
        header('Retry-After: 5');
    }
    procurement_api_respond(503, [
        'success' => false,
        'message' => 'Request storage is temporarily unavailable. Please try again.',
        'error' => ['code' => 'request_storage_unavailable'],
        'request_id' => $requestId,
    ]);
} catch (PDOException $error) {
    error_log(sprintf('[procurement:%s] Database error: %s', $requestId, $error->getMessage()));
    if (!headers_sent()) {
        header('Retry-After: 2');
    }
    procurement_api_respond(503, [
        'success' => false,
        'message' => 'Request storage is temporarily unavailable. Please try again.',
        'error' => ['code' => 'request_storage_unavailable'],
        'request_id' => $requestId,
    ]);
} catch (Throwable $error) {
    error_log(sprintf('[procurement:%s] Unexpected error: %s', $requestId, $error->getMessage()));
    procurement_api_respond(500, [
        'success' => false,
        'message' => 'The request could not be recorded.',
        'error' => ['code' => 'request_error'],
        'request_id' => $requestId,
    ]);
}

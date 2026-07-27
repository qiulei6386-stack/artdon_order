<?php

declare(strict_types=1);

function api_json_body(int $maxBytes = 1_048_576): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'application/json')) {
        api_respond(415, ['success' => false, 'message' => 'Send the request as application/json.']);
    }

    $maxBytes = max(1, min(5_242_880, $maxBytes));
    $declaredLength = filter_var(
        $_SERVER['CONTENT_LENGTH'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    if ($declaredLength !== false && $declaredLength !== null && $declaredLength > $maxBytes) {
        api_respond(413, ['success' => false, 'message' => 'The request body is too large.']);
    }

    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false) {
        api_respond(400, ['success' => false, 'message' => 'The request body could not be read.']);
    }
    if (strlen($raw) > $maxBytes) {
        api_respond(413, ['success' => false, 'message' => 'The request body is too large.']);
    }
    if (trim($raw) === '') {
        api_respond(400, ['success' => false, 'message' => 'A JSON request body is required.']);
    }

    try {
        $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        api_respond(400, ['success' => false, 'message' => 'Invalid JSON request body.']);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        api_respond(400, ['success' => false, 'message' => 'The JSON request body must be an object.']);
    }

    return $decoded;
}

function api_respond(int $status, array $payload): never
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function api_require_method(string ...$allowed): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowed = array_map('strtoupper', $allowed);
    if (!in_array($method, $allowed, true)) {
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', $allowed));
        }
        api_respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }
}

function api_verify_csrf(array $input = []): void
{
    $provided = (string) (
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $input['csrf_token']
        ?? ''
    );
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        api_respond(419, [
            'success' => false,
            'message' => 'The form session expired. Refresh the page and try again.',
        ]);
    }
}

function api_request_id(): string
{
    $incoming = preg_replace(
        '/[^A-Za-z0-9._-]/',
        '',
        (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '')
    );
    if ($incoming !== '') {
        return substr($incoming, 0, 80);
    }

    return bin2hex(random_bytes(12));
}

function api_session_hash(): string
{
    $sessionId = session_id();
    if ($sessionId === '') {
        throw new RuntimeException('An active session is required.');
    }

    return hash('sha256', 'artdon-session-v1|' . $sessionId);
}

function api_public_id(string $prefix): string
{
    $safePrefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix) ?: 'ID');
    return $safePrefix . '-' . strtoupper(bin2hex(random_bytes(8)));
}

function api_client_ip_hash(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

/**
 * Fixed-window, per-origin limiter for expensive or state-changing endpoints.
 * It deliberately trusts only REMOTE_ADDR, not spoofable forwarding headers.
 */
function api_cleanup_rate_limit_files(
    string $directory,
    int $olderThanSeconds = 86_400,
    int $maximumScanned = 256
): int {
    if ($olderThanSeconds < 3_600 || $maximumScanned < 1 || $maximumScanned > 2_048) {
        throw new InvalidArgumentException('Invalid API rate-limit cleanup policy.');
    }
    if (!is_dir($directory)) {
        return 0;
    }

    $cutoff = time() - $olderThanSeconds;
    $scanned = 0;
    $removed = 0;
    foreach (new DirectoryIterator($directory) as $file) {
        if ($file->isDot()) {
            continue;
        }
        $scanned++;
        if ($scanned > $maximumScanned) {
            break;
        }
        if (
            $file->isLink()
            || !$file->isFile()
            || preg_match('/^[a-z0-9_-]+-[a-f0-9]{64}\.json$/', $file->getFilename()) !== 1
            || $file->getMTime() > $cutoff
        ) {
            continue;
        }
        if (@unlink($file->getPathname())) {
            $removed++;
        }
    }

    return $removed;
}

function api_rate_limit(string $scope, int $maximum, int $windowSeconds): void
{
    $scope = strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $scope));
    if ($scope === '' || $maximum < 1 || $windowSeconds < 1) {
        throw new InvalidArgumentException('Invalid API rate-limit configuration.');
    }

    $directory = sys_get_temp_dir() . '/artdon-rate-limits';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        api_respond(503, ['success' => false, 'message' => 'The service is temporarily unavailable.']);
    }
    @chmod($directory, 0700);

    $now = time();
    $bucket = intdiv($now, $windowSeconds);
    $key = hash('sha256', $scope . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $path = $directory . '/' . $scope . '-' . $key . '.json';
    $handle = fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        api_respond(503, ['success' => false, 'message' => 'The service is temporarily unavailable.']);
    }

    $retryAfter = null;
    try {
        $contents = stream_get_contents($handle);
        $state = is_string($contents) && $contents !== ''
            ? json_decode($contents, true)
            : null;
        $count = is_array($state) && (int) ($state['bucket'] ?? -1) === $bucket
            ? max(0, (int) ($state['count'] ?? 0))
            : 0;
        if ($count >= $maximum) {
            $retryAfter = max(1, (($bucket + 1) * $windowSeconds) - $now);
        } else {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode([
                'bucket' => $bucket,
                'count' => $count + 1,
                'updated_at' => $now,
            ], JSON_THROW_ON_ERROR));
            fflush($handle);
            @chmod($path, 0600);
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    if ($retryAfter !== null) {
        if (!headers_sent()) {
            header('Retry-After: ' . $retryAfter);
        }
        api_respond(429, [
            'success' => false,
            'message' => 'Too many requests. Please retry shortly.',
        ]);
    }

    // Bound stale limiter files without ever scanning an unbounded directory.
    try {
        if (random_int(1, 64) === 1) {
            api_cleanup_rate_limit_files($directory);
        }
    } catch (Throwable) {
        // Housekeeping must not disable an otherwise successful rate limit.
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

final class ArtdonIntegrationConfigurationException extends RuntimeException
{
}

/**
 * Read and validate the outbound ERP / CRM integration configuration.
 *
 * Supplying an environment array is useful for tests and intentionally does
 * not fall back to the process environment for missing keys.
 *
 * @param array<string,scalar|null>|null $environment
 * @return array{
 *   endpoint:string,
 *   token:string,
 *   app_env:string,
 *   connect_timeout:int,
 *   request_timeout:int,
 *   lock_timeout:int,
 *   retry_base:int,
 *   retry_cap:int,
 *   max_payload_bytes:int
 * }
 */
function artdon_integration_config(?array $environment = null): array
{
    $read = static function (string $key, string $default = '') use ($environment): string {
        if ($environment !== null) {
            if (!array_key_exists($key, $environment) || $environment[$key] === null) {
                return $default;
            }
            return trim((string) $environment[$key]);
        }

        $value = getenv($key);
        return $value === false ? $default : trim((string) $value);
    };
    $boundedInteger = static function (
        string $value,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }
        return max($minimum, min($maximum, (int) $value));
    };

    $endpoint = $read('ERP_API_URL');
    $token = $read('ERP_API_TOKEN');
    if ($endpoint === '' || $token === '') {
        throw new ArtdonIntegrationConfigurationException(
            'ERP_API_URL and ERP_API_TOKEN must both be configured before jobs can be claimed.'
        );
    }

    $parts = parse_url($endpoint);
    if (
        $parts === false
        || !isset($parts['scheme'], $parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
    ) {
        throw new ArtdonIntegrationConfigurationException(
            'ERP_API_URL must be an absolute URL without embedded credentials or a fragment.'
        );
    }

    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower(trim((string) $parts['host'], '[]'));
    if (!in_array($scheme, ['https', 'http'], true)) {
        throw new ArtdonIntegrationConfigurationException('ERP_API_URL must use HTTPS.');
    }

    $appEnvironment = strtolower($read('APP_ENV', 'production'));
    $testEnvironment = in_array(
        $appEnvironment,
        ['test', 'testing', 'development', 'local'],
        true
    );
    $loopbackHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if ($scheme !== 'https' && !($testEnvironment && $loopbackHost)) {
        throw new ArtdonIntegrationConfigurationException(
            'Plain HTTP is allowed only for an explicit test/development environment on localhost.'
        );
    }

    return [
        'endpoint' => $endpoint,
        'token' => $token,
        'app_env' => $appEnvironment,
        'connect_timeout' => $boundedInteger(
            $read('ERP_CONNECT_TIMEOUT_SECONDS'),
            5,
            1,
            15
        ),
        'request_timeout' => $boundedInteger(
            $read('ERP_REQUEST_TIMEOUT_SECONDS'),
            20,
            2,
            60
        ),
        'lock_timeout' => $boundedInteger(
            $read('ERP_JOB_LOCK_TIMEOUT_SECONDS'),
            300,
            30,
            3600
        ),
        'retry_base' => $boundedInteger(
            $read('ERP_RETRY_BASE_SECONDS'),
            60,
            5,
            3600
        ),
        'retry_cap' => $boundedInteger(
            $read('ERP_RETRY_CAP_SECONDS'),
            21600,
            60,
            86400
        ),
        'max_payload_bytes' => $boundedInteger(
            $read('ERP_MAX_PAYLOAD_BYTES'),
            1048576,
            1024,
            5242880
        ),
    ];
}

function artdon_integration_time(?DateTimeImmutable $time = null): string
{
    $time ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function artdon_integration_worker_id(): string
{
    $host = preg_replace('/[^A-Za-z0-9_.-]/', '-', (string) (gethostname() ?: 'worker'));
    return substr((string) $host, 0, 48)
        . '-'
        . (string) getmypid()
        . '-'
        . bin2hex(random_bytes(4));
}

/**
 * Claim one due job. BEGIN IMMEDIATE serializes SQLite queue writers, while the
 * conditional update and worker lock protect against stale workers.
 *
 * Attempts are incremented when a job is claimed. A crashed worker therefore
 * consumes one attempt when its stale lock is later reclaimed.
 *
 * @return array<string,mixed>|null
 */
function artdon_integration_claim_job(
    PDO $pdo,
    string $workerId,
    int $lockTimeout,
    ?DateTimeImmutable $now = null
): ?array {
    if ($pdo->inTransaction()) {
        throw new RuntimeException('A sync job cannot be claimed inside another transaction.');
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $nowSql = artdon_integration_time($now);
    $staleSql = artdon_integration_time($now->modify('-' . max(30, $lockTimeout) . ' seconds'));

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $expire = $pdo->prepare(
            "UPDATE sync_jobs
             SET status = 'dead',
                 locked_at = NULL,
                 locked_by = NULL,
                 next_attempt_at = NULL,
                 last_error = CASE
                     WHEN last_error IS NULL OR last_error = ''
                         THEN 'Retry budget exhausted before claim.'
                     ELSE last_error
                 END,
                 updated_at = :updated_at
             WHERE attempts >= max_attempts
               AND (
                   status IN ('pending', 'failed')
                   OR (status = 'running' AND locked_at IS NOT NULL AND locked_at <= :stale_at)
               )"
        );
        $expire->execute([
            ':updated_at' => $nowSql,
            ':stale_at' => $staleSql,
        ]);

        $find = $pdo->prepare(
            "SELECT *
             FROM sync_jobs
             WHERE attempts < max_attempts
               AND (
                   (
                       status IN ('pending', 'failed')
                       AND (next_attempt_at IS NULL OR next_attempt_at <= :now_at)
                   )
                   OR (
                       status = 'running'
                       AND locked_at IS NOT NULL
                       AND locked_at <= :stale_at
                   )
               )
             ORDER BY
                 CASE WHEN status = 'running' THEN 0 ELSE 1 END,
                 COALESCE(next_attempt_at, created_at),
                 id
             LIMIT 1"
        );
        $find->execute([
            ':now_at' => $nowSql,
            ':stale_at' => $staleSql,
        ]);
        $job = $find->fetch();
        if (!is_array($job)) {
            $pdo->exec('COMMIT');
            return null;
        }

        $claim = $pdo->prepare(
            "UPDATE sync_jobs
             SET status = 'running',
                 attempts = attempts + 1,
                 next_attempt_at = NULL,
                 locked_at = :locked_at,
                 locked_by = :locked_by,
                 updated_at = :updated_at
             WHERE id = :id
               AND status = :previous_status
               AND attempts = :previous_attempts"
        );
        $claim->execute([
            ':locked_at' => $nowSql,
            ':locked_by' => $workerId,
            ':updated_at' => $nowSql,
            ':id' => (int) $job['id'],
            ':previous_status' => (string) $job['status'],
            ':previous_attempts' => (int) $job['attempts'],
        ]);
        if ($claim->rowCount() !== 1) {
            $pdo->exec('ROLLBACK');
            return null;
        }

        $reload = $pdo->prepare('SELECT * FROM sync_jobs WHERE id = :id');
        $reload->execute([':id' => (int) $job['id']]);
        $claimed = $reload->fetch();
        $pdo->exec('COMMIT');

        return is_array($claimed) ? $claimed : null;
    } catch (Throwable $error) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // The transaction may already have been rolled back.
        }
        throw $error;
    }
}

function artdon_integration_retry_delay(int $attempt, int $baseSeconds, int $capSeconds): int
{
    $attempt = max(1, $attempt);
    $exponent = min(20, $attempt - 1);
    $delay = max(1, $baseSeconds) * (2 ** $exponent);
    return (int) min(max(1, $capSeconds), $delay);
}

/**
 * Remove secrets and control characters before a diagnostic is stored.
 *
 * @param array<string,mixed> $config
 */
function artdon_integration_safe_error(string $message, array $config): string
{
    $token = (string) ($config['token'] ?? '');
    if ($token !== '') {
        $message = str_replace($token, '[redacted]', $message);
    }
    $message = preg_replace(
        '/\b(Authorization\s*:\s*Bearer|Bearer)\s+[^\s,;]+/i',
        '$1 [redacted]',
        $message
    ) ?? $message;
    $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? $message;
    $message = preg_replace('/\s+/', ' ', $message) ?? $message;

    return substr(trim($message), 0, 500);
}

/**
 * @param array<string,mixed> $job
 * @param array<string,mixed> $config
 * @return array{body:string,headers:list<string>}
 */
function artdon_integration_request(array $job, array $config): array
{
    $idempotencyKey = (string) ($job['idempotency_key'] ?? '');
    if (
        $idempotencyKey === ''
        || strlen($idempotencyKey) > 200
        || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1
    ) {
        throw new UnexpectedValueException('The job has an invalid idempotency key.');
    }

    try {
        $payload = json_decode(
            (string) ($job['payload_json'] ?? ''),
            true,
            64,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $error) {
        throw new UnexpectedValueException('The job payload is not valid JSON.', 0, $error);
    }
    if (!is_array($payload)) {
        throw new UnexpectedValueException('The job payload must be a JSON object or array.');
    }

    $body = artdon_json_encode([
        'event' => (string) ($job['job_type'] ?? ''),
        'idempotency_key' => $idempotencyKey,
        'occurred_at' => (string) ($job['created_at'] ?? ''),
        'data' => $payload,
    ]);
    if (strlen($body) > (int) $config['max_payload_bytes']) {
        throw new LengthException('The integration payload exceeds the configured size limit.');
    }

    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $signature = hash_hmac(
        'sha256',
        $timestamp . "\n" . $idempotencyKey . "\n" . $body,
        (string) $config['token']
    );

    return [
        'body' => $body,
        'headers' => [
            'Accept: application/json',
            'Authorization: Bearer ' . (string) $config['token'],
            'Content-Type: application/json',
            'Idempotency-Key: ' . $idempotencyKey,
            'X-Artdon-Timestamp: ' . $timestamp,
            'X-Artdon-Signature: sha256=' . $signature,
            'User-Agent: Artdon-Procurement-Sync/1.0',
        ],
    ];
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $job
 * @param list<string> $headers
 * @return array{status_code:int,transport_error:?string,duration_ms:int,response_body:string}
 */
function artdon_integration_http_post(
    array $config,
    array $job,
    string $body,
    array $headers
): array {
    unset($job);
    if (!extension_loaded('curl')) {
        return [
            'status_code' => 0,
            'transport_error' => 'The PHP cURL extension is unavailable.',
            'duration_ms' => 0,
            'response_body' => '',
        ];
    }

    $handle = curl_init((string) $config['endpoint']);
    if ($handle === false) {
        return [
            'status_code' => 0,
            'transport_error' => 'The HTTP client could not be initialized.',
            'duration_ms' => 0,
            'response_body' => '',
        ];
    }

    $responseBytes = 0;
    $responseBody = '';
    $responseTooLarge = false;
    $maximumResponseBytes = 65536;
    $write = static function ($curlHandle, string $chunk) use (
        &$responseBytes,
        &$responseBody,
        &$responseTooLarge,
        $maximumResponseBytes
    ): int {
        unset($curlHandle);
        $length = strlen($chunk);
        $responseBytes += $length;
        if ($responseBytes > $maximumResponseBytes) {
            $responseTooLarge = true;
            return 0;
        }
        $responseBody .= $chunk;
        return $length;
    };

    $allowedProtocols = CURLPROTO_HTTPS;
    if ((string) $config['app_env'] !== 'production') {
        $allowedProtocols |= CURLPROTO_HTTP;
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => (int) $config['connect_timeout'],
        CURLOPT_TIMEOUT => (int) $config['request_timeout'],
        CURLOPT_NOSIGNAL => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => $allowedProtocols,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER => false,
        CURLOPT_WRITEFUNCTION => $write,
    ]);

    $started = microtime(true);
    $result = curl_exec($handle);
    $duration = (int) round((microtime(true) - $started) * 1000);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = $result === false ? curl_error($handle) : '';
    curl_close($handle);

    if ($responseTooLarge) {
        $curlError = 'The integration response exceeded 65536 bytes.';
    }

    return [
        'status_code' => $statusCode,
        'transport_error' => $curlError === '' ? null : $curlError,
        'duration_ms' => $duration,
        'response_body' => $responseBody,
    ];
}

/**
 * A successful HTTP status is not enough: the remote side must explicitly
 * acknowledge the same idempotency key so that a proxy-generated 2xx page or
 * an application error cannot silently discard an order.
 *
 * @param array<string,mixed> $response
 */
function artdon_integration_validate_ack(array $job, array $response): ?string
{
    $body = trim((string) ($response['response_body'] ?? ''));
    if ($body === '') {
        return 'Integration endpoint returned 2xx without an acknowledgement body.';
    }

    try {
        $ack = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return 'Integration endpoint returned an invalid JSON acknowledgement.';
    }
    if (!is_array($ack) || ($ack['success'] ?? null) !== true) {
        return 'Integration endpoint did not confirm success.';
    }

    $expectedKey = (string) ($job['idempotency_key'] ?? '');
    $acknowledgedKey = (string) ($ack['idempotency_key'] ?? '');
    if ($acknowledgedKey === '' || !hash_equals($expectedKey, $acknowledgedKey)) {
        return 'Integration acknowledgement idempotency key did not match.';
    }
    $remoteId = trim((string) ($ack['remote_id'] ?? ''));
    if ($remoteId === '' || strlen($remoteId) > 200) {
        return 'Integration acknowledgement did not include a valid remote_id.';
    }

    return null;
}

/**
 * @param array<string,mixed> $job
 * @param array<string,mixed> $config
 */
function artdon_integration_finish_job(
    PDO $pdo,
    array $job,
    array $config,
    string $outcome,
    ?string $error,
    ?DateTimeImmutable $now = null
): bool {
    if (!in_array($outcome, ['success', 'retry', 'dead'], true)) {
        throw new InvalidArgumentException('Unknown integration job outcome.');
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $nowSql = artdon_integration_time($now);
    $status = $outcome === 'retry' ? 'failed' : $outcome;
    $nextAttemptAt = null;
    if ($outcome === 'retry') {
        $delay = artdon_integration_retry_delay(
            (int) $job['attempts'],
            (int) $config['retry_base'],
            (int) $config['retry_cap']
        );
        $nextAttemptAt = artdon_integration_time($now->modify('+' . $delay . ' seconds'));
    }

    $statement = $pdo->prepare(
        'UPDATE sync_jobs
         SET status = :status,
             next_attempt_at = :next_attempt_at,
             locked_at = NULL,
             locked_by = NULL,
             last_error = :last_error,
             completed_at = :completed_at,
             updated_at = :updated_at
         WHERE id = :id
           AND status = :running_status
           AND locked_by = :locked_by'
    );
    $statement->execute([
        ':status' => $status,
        ':next_attempt_at' => $nextAttemptAt,
        ':last_error' => $error === null ? null : artdon_integration_safe_error($error, $config),
        ':completed_at' => $outcome === 'success' ? $nowSql : null,
        ':updated_at' => $nowSql,
        ':id' => (int) $job['id'],
        ':running_status' => 'running',
        ':locked_by' => (string) $job['locked_by'],
    ]);

    return $statement->rowCount() === 1;
}

/**
 * Process one already-claimed job.
 *
 * The transport callback is injectable for tests. It receives configuration,
 * the job, encoded request body, and request headers.
 *
 * @param array<string,mixed> $job
 * @param array<string,mixed> $config
 * @param callable(array<string,mixed>,array<string,mixed>,string,list<string>):array<string,mixed>|null $transport
 * @return array{job_id:int,status:string,http_status:int,duration_ms:int}
 */
function artdon_integration_process_job(
    PDO $pdo,
    array $job,
    array $config,
    callable $transport,
    ?DateTimeImmutable $now = null
): array {
    try {
        $request = artdon_integration_request($job, $config);
    } catch (UnexpectedValueException | LengthException $error) {
        artdon_integration_finish_job(
            $pdo,
            $job,
            $config,
            'dead',
            $error->getMessage(),
            $now
        );
        return [
            'job_id' => (int) $job['id'],
            'status' => 'dead',
            'http_status' => 0,
            'duration_ms' => 0,
        ];
    }

    try {
        $response = $transport(
            $config,
            $job,
            $request['body'],
            $request['headers']
        );
        if (!is_array($response)) {
            throw new UnexpectedValueException('The integration transport returned an invalid result.');
        }
    } catch (Throwable $error) {
        $response = [
            'status_code' => 0,
            'transport_error' => 'Transport exception: ' . $error->getMessage(),
            'duration_ms' => 0,
            'response_body' => '',
        ];
    }

    $statusCode = max(0, (int) ($response['status_code'] ?? 0));
    $duration = max(0, (int) ($response['duration_ms'] ?? 0));
    $transportError = trim((string) ($response['transport_error'] ?? ''));
    $attemptsExhausted = (int) $job['attempts'] >= (int) $job['max_attempts'];

    $ackError = null;
    if ($transportError === '' && $statusCode >= 200 && $statusCode < 300) {
        $ackError = artdon_integration_validate_ack($job, $response);
    }
    if ($transportError === '' && $statusCode >= 200 && $statusCode < 300 && $ackError === null) {
        artdon_integration_finish_job($pdo, $job, $config, 'success', null, $now);
        return [
            'job_id' => (int) $job['id'],
            'status' => 'success',
            'http_status' => $statusCode,
            'duration_ms' => $duration,
        ];
    }

    $retryableStatus = $ackError !== null
        || in_array($statusCode, [0, 408, 425, 429], true)
        || $statusCode >= 500;
    $outcome = $retryableStatus && !$attemptsExhausted ? 'retry' : 'dead';
    $error = $transportError !== ''
        ? 'Integration transport failed: ' . $transportError
        : ($ackError ?? ('Integration endpoint returned HTTP ' . $statusCode . '.'));

    artdon_integration_finish_job($pdo, $job, $config, $outcome, $error, $now);
    return [
        'job_id' => (int) $job['id'],
        'status' => $outcome === 'retry' ? 'failed' : 'dead',
        'http_status' => $statusCode,
        'duration_ms' => $duration,
    ];
}

/**
 * Run a bounded batch and exit; this function never loops or sleeps.
 *
 * @param array<string,mixed> $config
 * @param callable(array<string,mixed>,array<string,mixed>,string,list<string>):array<string,mixed>|null $transport
 * @return array{
 *   claimed:int,
 *   success:int,
 *   failed:int,
 *   dead:int,
 *   idle:bool,
 *   jobs:list<array{job_id:int,status:string,http_status:int,duration_ms:int}>
 * }
 */
function artdon_integration_run(
    PDO $pdo,
    array $config,
    int $limit = 1,
    ?callable $transport = null,
    ?string $workerId = null
): array {
    $limit = max(1, min(100, $limit));
    $workerId = $workerId === null || trim($workerId) === ''
        ? artdon_integration_worker_id()
        : substr(trim($workerId), 0, 100);
    $transport ??= 'artdon_integration_http_post';

    $summary = [
        'claimed' => 0,
        'success' => 0,
        'failed' => 0,
        'dead' => 0,
        'idle' => false,
        'jobs' => [],
    ];
    for ($index = 0; $index < $limit; $index++) {
        $job = artdon_integration_claim_job(
            $pdo,
            $workerId,
            (int) $config['lock_timeout']
        );
        if ($job === null) {
            $summary['idle'] = true;
            break;
        }

        $summary['claimed']++;
        $result = artdon_integration_process_job($pdo, $job, $config, $transport);
        $summary[$result['status']]++;
        $summary['jobs'][] = $result;
    }

    return $summary;
}

/**
 * Configuration is resolved before the first queue claim. Missing or invalid
 * settings therefore leave every job untouched.
 *
 * @param array<string,scalar|null>|null $environment
 * @param callable(array<string,mixed>,array<string,mixed>,string,list<string>):array<string,mixed>|null $transport
 * @return array<string,mixed>
 */
function artdon_integration_run_from_environment(
    PDO $pdo,
    ?array $environment = null,
    int $limit = 1,
    ?callable $transport = null,
    ?string $workerId = null
): array {
    $config = artdon_integration_config($environment);
    return artdon_integration_run($pdo, $config, $limit, $transport, $workerId);
}

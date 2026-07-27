<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__, 2) . '/includes/integration.php';

$assertions = 0;

function integration_test_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function integration_test_same(mixed $expected, mixed $actual, string $message): void
{
    integration_test_assert(
        $expected === $actual,
        sprintf('%s; expected %s, got %s', $message, var_export($expected, true), var_export($actual, true))
    );
}

function integration_test_database(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $migration = require dirname(__DIR__, 2) . '/database/migrations/001_platform.php';
    $migration['up']($pdo);
    return $pdo;
}

function integration_test_insert_job(
    PDO $pdo,
    string $key,
    string $status = 'pending',
    int $attempts = 0,
    int $maxAttempts = 8,
    ?string $lockedAt = null
): int {
    $now = '2026-01-01 00:00:00';
    $statement = $pdo->prepare(
        'INSERT INTO sync_jobs (
            job_type, idempotency_key, payload_json, status, attempts,
            max_attempts, next_attempt_at, locked_at, locked_by,
            created_at, updated_at
         ) VALUES (
            :job_type, :idempotency_key, :payload_json, :status, :attempts,
            :max_attempts, :next_attempt_at, :locked_at, :locked_by,
            :created_at, :updated_at
         )'
    );
    $statement->execute([
        ':job_type' => 'request_push',
        ':idempotency_key' => $key,
        ':payload_json' => '{"public_id":"REQ-TEST"}',
        ':status' => $status,
        ':attempts' => $attempts,
        ':max_attempts' => $maxAttempts,
        ':next_attempt_at' => $now,
        ':locked_at' => $lockedAt,
        ':locked_by' => $status === 'running' ? 'crashed-worker' : null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    return (int) $pdo->lastInsertId();
}

function integration_test_row(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare('SELECT * FROM sync_jobs WHERE id = :id');
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Test job was not found.');
    }
    return $row;
}

function integration_test_config(): array
{
    return artdon_integration_config([
        'ERP_API_URL' => 'https://erp.example.test/integration/v1/procurement-requests',
        'ERP_API_TOKEN' => 'integration-secret-token',
        'APP_ENV' => 'testing',
        'ERP_RETRY_BASE_SECONDS' => '60',
        'ERP_RETRY_CAP_SECONDS' => '3600',
    ]);
}

try {
    $missingDatabase = integration_test_database();
    $missingJobId = integration_test_insert_job($missingDatabase, 'request_push:missing-config');
    try {
        artdon_integration_run_from_environment($missingDatabase, [], 1);
        throw new RuntimeException('Missing configuration should have failed.');
    } catch (ArtdonIntegrationConfigurationException) {
        integration_test_same(
            'pending',
            integration_test_row($missingDatabase, $missingJobId)['status'],
            'missing configuration leaves the job untouched'
        );
        integration_test_same(
            0,
            (int) integration_test_row($missingDatabase, $missingJobId)['attempts'],
            'missing configuration does not consume an attempt'
        );
    }

    try {
        artdon_integration_config([
            'ERP_API_URL' => 'http://erp.example.test/push',
            'ERP_API_TOKEN' => 'secret',
            'APP_ENV' => 'production',
        ]);
        throw new RuntimeException('Production HTTP should have failed.');
    } catch (ArtdonIntegrationConfigurationException) {
        integration_test_assert(true, 'production requires HTTPS');
    }
    $localConfig = artdon_integration_config([
        'ERP_API_URL' => 'http://127.0.0.1:9911/push',
        'ERP_API_TOKEN' => 'local-secret',
        'APP_ENV' => 'testing',
        'ERP_CONNECT_TIMEOUT_SECONDS' => '999',
        'ERP_REQUEST_TIMEOUT_SECONDS' => '0',
    ]);
    integration_test_same(15, $localConfig['connect_timeout'], 'connect timeout is bounded');
    integration_test_same(2, $localConfig['request_timeout'], 'request timeout is bounded');

    $successDatabase = integration_test_database();
    $successJobId = integration_test_insert_job($successDatabase, 'request_push:success');
    $captured = [];
    $successTransport = static function (
        array $config,
        array $job,
        string $body,
        array $headers
    ) use (&$captured): array {
        $captured = compact('config', 'job', 'body', 'headers');
        return [
            'status_code' => 201,
            'transport_error' => null,
            'duration_ms' => 23,
            'response_body' => json_encode([
                'success' => true,
                'idempotency_key' => (string) $job['idempotency_key'],
                'remote_id' => 'ERP-10001',
            ], JSON_THROW_ON_ERROR),
        ];
    };
    $success = artdon_integration_run(
        $successDatabase,
        integration_test_config(),
        1,
        $successTransport,
        'test-worker'
    );
    integration_test_same(1, $success['success'], '2xx response completes the job');
    $successRow = integration_test_row($successDatabase, $successJobId);
    integration_test_same('success', $successRow['status'], 'successful job is terminal');
    integration_test_same(1, (int) $successRow['attempts'], 'claim increments attempts once');
    integration_test_assert($successRow['completed_at'] !== null, 'success records completion time');
    integration_test_assert(
        in_array('Idempotency-Key: request_push:success', $captured['headers'], true),
        'idempotency key is sent'
    );
    integration_test_assert(
        in_array('Authorization: Bearer integration-secret-token', $captured['headers'], true),
        'configured bearer token is sent'
    );
    integration_test_assert(
        count(array_filter(
            $captured['headers'],
            static fn(string $header): bool => str_starts_with($header, 'X-Artdon-Signature: sha256=')
        )) === 1,
        'request is HMAC signed'
    );
    $decodedBody = json_decode($captured['body'], true, 32, JSON_THROW_ON_ERROR);
    integration_test_same(
        'REQ-TEST',
        $decodedBody['data']['public_id'],
        'queued payload is wrapped without alteration'
    );

    $badAckDatabase = integration_test_database();
    $badAckJobId = integration_test_insert_job($badAckDatabase, 'request_push:bad-ack');
    $badAck = artdon_integration_run(
        $badAckDatabase,
        integration_test_config(),
        1,
        static fn(): array => [
            'status_code' => 202,
            'transport_error' => null,
            'duration_ms' => 5,
            'response_body' => '{"success":true,"idempotency_key":"wrong","remote_id":"ERP-2"}',
        ],
        'bad-ack-worker'
    );
    integration_test_same(1, $badAck['failed'], 'mismatched 2xx acknowledgement is retried');
    integration_test_same(
        'failed',
        integration_test_row($badAckDatabase, $badAckJobId)['status'],
        'mismatched acknowledgement never completes the job'
    );

    $retryDatabase = integration_test_database();
    $retryJobId = integration_test_insert_job($retryDatabase, 'request_push:retry');
    $retry = artdon_integration_run(
        $retryDatabase,
        integration_test_config(),
        1,
        static fn(): array => [
            'status_code' => 0,
            'transport_error' => 'timeout integration-secret-token Authorization: Bearer exposed',
            'duration_ms' => 20000,
        ],
        'retry-worker'
    );
    integration_test_same(1, $retry['failed'], 'transport failure is scheduled for retry');
    $retryRow = integration_test_row($retryDatabase, $retryJobId);
    integration_test_same('failed', $retryRow['status'], 'retryable job returns to failed state');
    integration_test_assert(
        (string) $retryRow['next_attempt_at'] > (string) $retryRow['updated_at'],
        'retry has a future next-attempt timestamp'
    );
    integration_test_assert(
        !str_contains((string) $retryRow['last_error'], 'integration-secret-token')
        && !str_contains((string) $retryRow['last_error'], 'Bearer exposed'),
        'stored diagnostics redact credentials'
    );

    $permanentDatabase = integration_test_database();
    $permanentJobId = integration_test_insert_job($permanentDatabase, 'request_push:invalid');
    $permanent = artdon_integration_run(
        $permanentDatabase,
        integration_test_config(),
        1,
        static fn(): array => [
            'status_code' => 422,
            'transport_error' => null,
            'duration_ms' => 12,
        ],
        'permanent-worker'
    );
    integration_test_same(1, $permanent['dead'], 'non-retryable 4xx response is terminal');
    integration_test_same(
        'dead',
        integration_test_row($permanentDatabase, $permanentJobId)['status'],
        'permanent failure is marked dead'
    );

    $exhaustedDatabase = integration_test_database();
    $exhaustedJobId = integration_test_insert_job(
        $exhaustedDatabase,
        'request_push:exhausted',
        'pending',
        7,
        8
    );
    artdon_integration_run(
        $exhaustedDatabase,
        integration_test_config(),
        1,
        static fn(): array => [
            'status_code' => 503,
            'transport_error' => null,
            'duration_ms' => 50,
        ],
        'exhausted-worker'
    );
    $exhaustedRow = integration_test_row($exhaustedDatabase, $exhaustedJobId);
    integration_test_same(8, (int) $exhaustedRow['attempts'], 'final attempt is counted');
    integration_test_same('dead', $exhaustedRow['status'], 'retry budget exhaustion is terminal');

    $staleDatabase = integration_test_database();
    $staleJobId = integration_test_insert_job(
        $staleDatabase,
        'request_push:stale',
        'running',
        1,
        8,
        '2026-07-27 08:00:00'
    );
    $fixedNow = new DateTimeImmutable('2026-07-27 10:00:00', new DateTimeZone('UTC'));
    $staleJob = artdon_integration_claim_job($staleDatabase, 'recovery-worker', 300, $fixedNow);
    integration_test_same($staleJobId, (int) $staleJob['id'], 'stale running job is reclaimed');
    integration_test_same(2, (int) $staleJob['attempts'], 'stale reclaim consumes a new attempt');
    integration_test_same('recovery-worker', $staleJob['locked_by'], 'new worker owns reclaimed job');

    integration_test_same(60, artdon_integration_retry_delay(1, 60, 3600), 'first retry delay');
    integration_test_same(120, artdon_integration_retry_delay(2, 60, 3600), 'retry is exponential');
    integration_test_same(3600, artdon_integration_retry_delay(20, 60, 3600), 'retry delay is capped');

    $limitDatabase = integration_test_database();
    integration_test_insert_job($limitDatabase, 'request_push:limit-1');
    integration_test_insert_job($limitDatabase, 'request_push:limit-2');
    integration_test_insert_job($limitDatabase, 'request_push:limit-3');
    $limited = artdon_integration_run(
        $limitDatabase,
        integration_test_config(),
        2,
        static fn(array $config, array $job): array => [
            'status_code' => 200,
            'transport_error' => null,
            'duration_ms' => 1,
            'response_body' => json_encode([
                'success' => true,
                'idempotency_key' => (string) $job['idempotency_key'],
                'remote_id' => 'ERP-' . (string) $job['id'],
            ], JSON_THROW_ON_ERROR),
        ],
        'limit-worker'
    );
    integration_test_same(2, $limited['claimed'], 'run respects the requested limit');
    integration_test_same(
        1,
        (int) $limitDatabase->query(
            "SELECT COUNT(*) FROM sync_jobs WHERE status = 'pending'"
        )->fetchColumn(),
        'unclaimed job remains pending'
    );

    fwrite(STDOUT, 'Integration worker tests passed: ' . $assertions . " assertions\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

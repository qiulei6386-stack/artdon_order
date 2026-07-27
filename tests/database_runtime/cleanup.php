<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
$token = strtoupper(bin2hex(random_bytes(8)));
$temporaryRoot = sys_get_temp_dir() . '/artdon-cleanup-test-' . strtolower($token);
$databasePath = $temporaryRoot . '/cleanup.sqlite';
if (!mkdir($temporaryRoot, 0750, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('Unable to create the cleanup test directory.');
}
putenv('APP_DATABASE_PATH=' . $databasePath);

require_once $projectRoot . '/includes/database.php';
require_once $projectRoot . '/includes/lighting_repository.php';

/**
 * @return array<string,mixed>
 */
function cleanup_run_tool(string $projectRoot, string $databasePath, bool $apply): array
{
    $command = [
        PHP_BINARY,
        $projectRoot . '/tools/cleanup.php',
        '--database=' . $databasePath,
        '--cart-days=30',
        '--simulation-days=30',
        '--guest-completed-days=30',
        '--orphan-report-days=30',
        '--orphan-upload-days=30',
        '--json',
    ];
    if ($apply) {
        $command[] = '--apply';
    }
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $pipes = [];
    $process = proc_open(
        $escaped,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectRoot
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch the cleanup command.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('Cleanup command failed: ' . trim((string) $stderr));
    }
    $decoded = json_decode((string) $stdout, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Cleanup output was not a JSON object.');
    }
    return $decoded;
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function cleanup_assert_same(string $label, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

$fixtureFiles = [];
try {
    $bootstrap = artdon_db_bootstrap(true);
    $pdo = $bootstrap['pdo'];
    artdon_lighting_seed_demo_profiles($pdo);
    $old = '2000-01-01 00:00:00';
    $future = '2999-01-01 00:00:00';

    $cartInsert = $pdo->prepare(
        'INSERT INTO project_carts (
            public_id, session_key_hash, status, version, expires_at, created_at, updated_at
         ) VALUES (:public_id, :session_key_hash, :status, 1, :expires_at, :created_at, :updated_at)'
    );
    $cartIds = [];
    foreach ([
        'expired_delete' => ['expired', $old, $old],
        'expired_linked' => ['expired', $old, $old],
        'submitted' => ['submitted', null, $old],
        'active_expired' => ['active', $old, $old],
        'active_current' => ['active', $future, $old],
    ] as $name => [$status, $expiresAt, $updatedAt]) {
        $cartInsert->execute([
            ':public_id' => 'CART-' . $token . '-' . strtoupper($name),
            ':session_key_hash' => hash('sha256', $token . $name),
            ':status' => $status,
            ':expires_at' => $expiresAt,
            ':created_at' => $old,
            ':updated_at' => $updatedAt,
        ]);
        $cartIds[$name] = (int) $pdo->lastInsertId();
    }

    $requestInsert = $pdo->prepare(
        'INSERT INTO procurement_requests (
            public_id, request_no, idempotency_key, request_type, cart_id,
            company_name, contact_name, contact_email, country,
            company_snapshot_json, status, submitted_at, updated_at
         ) VALUES (
            :public_id, :request_no, :idempotency_key, :request_type, :cart_id,
            :company_name, :contact_name, :contact_email, :country,
            :company_snapshot_json, :status, :submitted_at, :updated_at
         )'
    );
    $requestInsert->execute([
        ':public_id' => 'REQ-' . $token,
        ':request_no' => 'RFQ-' . $token,
        ':idempotency_key' => hash('sha256', 'request-' . $token),
        ':request_type' => 'quick_rfq',
        ':cart_id' => $cartIds['expired_linked'],
        ':company_name' => 'Retention Test',
        ':contact_name' => 'Retention Test',
        ':contact_email' => 'retention@example.invalid',
        ':country' => 'SG',
        ':company_snapshot_json' => '{}',
        ':status' => 'submitted',
        ':submitted_at' => $old,
        ':updated_at' => $old,
    ]);
    $requestId = (int) $pdo->lastInsertId();

    $productId = (int) $pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    $iesId = (int) $pdo->query('SELECT id FROM ies_library ORDER BY id LIMIT 1')->fetchColumn();
    $simulationInsert = $pdo->prepare(
        'INSERT INTO simulation_projects (
            public_id, customer_id, session_key_hash, room_type, room_length_m, room_width_m,
            room_height_m, installation_height_m, work_plane_height_m,
            mounting_type, target_lux, maintenance_factor, product_id,
            ies_library_id, configured_model, input_snapshot_json, status,
            created_at, updated_at, report_path
         ) VALUES (
            :public_id, :customer_id, :session_key_hash, :room_type, 10, 8, 4, 3, 0,
            :mounting_type, 500, 0.8, :product_id,
            :ies_library_id, :configured_model, :input_snapshot_json, :status,
            :created_at, :updated_at, :report_path
         )'
    );
    $staleReport = 'storage/reports/2000/01/SIM-' . $token . '.pdf';
    $completedReport = 'storage/reports/2000/01/SIM-' . strrev($token) . '.pdf';
    $guestCompletedId = 'SIM-' . strtoupper(substr(hash('sha256', 'guest-' . $token), 0, 16));
    $guestCompletedReport = 'storage/reports/2000/01/' . $guestCompletedId . '.pdf';
    foreach ([
        'stale' => ['SIM-' . $token, 'draft', $staleReport, null],
        'completed' => ['SIM-' . strrev($token), 'completed', $completedReport, 42],
        'guest_completed' => [$guestCompletedId, 'completed', $guestCompletedReport, null],
    ] as $name => [$publicId, $status, $reportPath, $customerId]) {
        $simulationInsert->execute([
            ':public_id' => $publicId,
            ':customer_id' => $customerId,
            ':session_key_hash' => hash('sha256', $token . $name),
            ':room_type' => 'office',
            ':mounting_type' => 'recessed',
            ':product_id' => $productId,
            ':ies_library_id' => $iesId,
            ':configured_model' => 'TEST',
            ':input_snapshot_json' => '{}',
            ':status' => $status,
            ':created_at' => $old,
            ':updated_at' => $old,
            ':report_path' => $reportPath,
        ]);
    }

    $reportDirectory = $projectRoot . '/storage/reports/2000/01';
    $uploadDirectory = $projectRoot . '/storage/uploads/2000/01';
    @mkdir($reportDirectory, 0750, true);
    @mkdir($uploadDirectory, 0750, true);
    $orphanReport = 'storage/reports/2000/01/orphan-' . strtolower($token) . '.pdf';
    $activeUpload = 'storage/uploads/2000/01/active-' . strtolower($token) . '.pdf';
    $deletedUpload = 'storage/uploads/2000/01/deleted-' . strtolower($token) . '.pdf';
    $orphanUpload = 'storage/uploads/2000/01/orphan-' . strtolower($token) . '.pdf';
    foreach ([
        $staleReport,
        $completedReport,
        $guestCompletedReport,
        $orphanReport,
        $activeUpload,
        $deletedUpload,
        $orphanUpload,
    ] as $relative) {
        $absolute = $projectRoot . '/' . $relative;
        file_put_contents($absolute, 'retention fixture');
        touch($absolute, strtotime($old));
        $fixtureFiles[] = $absolute;
    }

    $attachmentInsert = $pdo->prepare(
        'INSERT INTO procurement_attachments (
            request_id, original_name, stored_path, mime_type, extension,
            file_size, status, created_at
         ) VALUES (
            :request_id, :original_name, :stored_path, :mime_type, :extension,
            :file_size, :status, :created_at
         )'
    );
    foreach ([
        [$activeUpload, 'active'],
        [$deletedUpload, 'deleted'],
    ] as [$storedPath, $status]) {
        $attachmentInsert->execute([
            ':request_id' => $requestId,
            ':original_name' => basename($storedPath),
            ':stored_path' => $storedPath,
            ':mime_type' => 'application/pdf',
            ':extension' => 'pdf',
            ':file_size' => 17,
            ':status' => $status,
            ':created_at' => $old,
        ]);
    }
    $pdo = null;

    $dryRun = cleanup_run_tool($projectRoot, $databasePath, false);
    cleanup_assert_same('dry-run mode', $dryRun['mode'] ?? null, 'dry-run');
    cleanup_assert_same('dry-run expired cart deletion candidates', $dryRun['database']['expired_or_abandoned_carts_to_delete'] ?? null, 1);
    cleanup_assert_same('dry-run cart expiration candidates', $dryRun['database']['active_carts_to_expire'] ?? null, 1);
    cleanup_assert_same('dry-run simulation deletion candidates', $dryRun['database']['stale_noncompleted_simulations_to_delete'] ?? null, 1);
    cleanup_assert_same(
        'dry-run stale guest completed simulation candidates',
        $dryRun['database']['stale_unowned_completed_simulations_to_delete'] ?? null,
        1
    );
    cleanup_assert_same('dry-run does not remove files', $dryRun['files']['removed'] ?? null, 0);

    $verify = new PDO('sqlite:' . $databasePath);
    cleanup_assert_same(
        'dry-run leaves candidate cart',
        (int) $verify->query("SELECT COUNT(*) FROM project_carts WHERE id = {$cartIds['expired_delete']}")->fetchColumn(),
        1
    );
    $verify = null;
    if (!is_file($projectRoot . '/' . $staleReport) || !is_file($projectRoot . '/' . $orphanUpload)) {
        throw new RuntimeException('Dry-run removed a fixture file.');
    }

    $applied = cleanup_run_tool($projectRoot, $databasePath, true);
    cleanup_assert_same('apply mode', $applied['mode'] ?? null, 'apply');
    cleanup_assert_same('one expired cart deleted', $applied['database']['expired_or_abandoned_carts_to_delete'] ?? null, 1);
    cleanup_assert_same('one active cart expired', $applied['database']['active_carts_to_expire'] ?? null, 1);
    cleanup_assert_same('one stale simulation deleted', $applied['database']['stale_noncompleted_simulations_to_delete'] ?? null, 1);
    cleanup_assert_same(
        'one stale unowned completed simulation deleted',
        $applied['database']['stale_unowned_completed_simulations_to_delete'] ?? null,
        1
    );

    $verify = new PDO('sqlite:' . $databasePath);
    cleanup_assert_same(
        'unlinked expired cart deleted',
        (int) $verify->query("SELECT COUNT(*) FROM project_carts WHERE id = {$cartIds['expired_delete']}")->fetchColumn(),
        0
    );
    foreach (['expired_linked', 'submitted', 'active_expired', 'active_current'] as $protectedCart) {
        cleanup_assert_same(
            $protectedCart . ' cart retained',
            (int) $verify->query("SELECT COUNT(*) FROM project_carts WHERE id = {$cartIds[$protectedCart]}")->fetchColumn(),
            1
        );
    }
    cleanup_assert_same(
        'completed simulation retained',
        (int) $verify->query("SELECT COUNT(*) FROM simulation_projects WHERE status = 'completed'")->fetchColumn(),
        1
    );
    cleanup_assert_same(
        'submitted procurement request retained',
        (int) $verify->query('SELECT COUNT(*) FROM procurement_requests')->fetchColumn(),
        1
    );
    $verify = null;

    foreach ([$staleReport, $guestCompletedReport, $orphanReport, $deletedUpload, $orphanUpload] as $removed) {
        $absoluteRemoved = $projectRoot . '/' . $removed;
        clearstatcache(true, $absoluteRemoved);
        if (is_file($absoluteRemoved)) {
            throw new RuntimeException(
                'Cleanup did not remove expected disposable file: '
                . $removed
                . ' (result: '
                . json_encode($applied, JSON_UNESCAPED_SLASHES)
                . ')'
            );
        }
    }
    foreach ([$completedReport, $activeUpload] as $retained) {
        $absoluteRetained = $projectRoot . '/' . $retained;
        clearstatcache(true, $absoluteRetained);
        if (!is_file($absoluteRetained)) {
            throw new RuntimeException('Cleanup removed protected file: ' . $retained);
        }
    }

    fwrite(STDOUT, "cleanup retention tests passed\n");
} finally {
    foreach ($fixtureFiles as $file) {
        @unlink($file);
    }
    @unlink($databasePath);
    @unlink($databasePath . '-wal');
    @unlink($databasePath . '-shm');
    @rmdir($temporaryRoot);
}

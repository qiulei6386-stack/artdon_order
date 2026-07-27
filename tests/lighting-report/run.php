<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__, 2);
$temporaryRoot = sys_get_temp_dir() . '/artdon-lighting-report-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryRoot, 0750, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('Unable to create the lighting report test directory.');
}

putenv('APP_DATABASE_PATH=' . $temporaryRoot . '/report.sqlite');
putenv('ARTDON_REPORT_STORAGE_PATH=' . $temporaryRoot . '/reports');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_id('artdon-lighting-report-test');
    session_start();
}
$_SESSION = [];

require_once $root . '/includes/lighting_report.php';

$tests = 0;

function report_test(bool $condition, string $message): void
{
    global $tests;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $tests++;
    fwrite(STDOUT, sprintf("PASS %d: %s\n", $tests, $message));
}

function report_test_rejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException|RuntimeException) {
        report_test(true, $message);
        return;
    }
    throw new RuntimeException('FAIL: ' . $message);
}

try {
    $pdo = artdon_db(true);
    $pdo->exec(
        'CREATE TABLE simulation_projects (
            public_id TEXT PRIMARY KEY,
            session_key_hash TEXT NOT NULL,
            report_path TEXT,
            report_checksum_sha256 TEXT,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $projectId = 'SIM-0123456789ABCDEF';
    $createdAt = '2026-07-27 12:34:56';
    $insert = $pdo->prepare(
        'INSERT INTO simulation_projects (
            public_id, session_key_hash, report_path, report_checksum_sha256,
            status, created_at, updated_at
        ) VALUES (
            :public_id, :session_key_hash, NULL, NULL,
            :status, :created_at, :updated_at
        )'
    );
    $insert->execute([
        ':public_id' => $projectId,
        ':session_key_hash' => artdon_lighting_session_key_hash(),
        ':status' => 'completed',
        ':created_at' => $createdAt,
        ':updated_at' => $createdAt,
    ]);

    $heatmapValues = [];
    for ($index = 0; $index < 100; $index++) {
        $heatmapValues[] = 120.0 + ($index % 10) * 38.0;
    }
    $project = [
        'public_id' => $projectId,
        'created_at' => $createdAt,
        'project_name' => 'Atomic report service test',
        'sku' => 'AT2020',
        'product_name' => 'Adjustable Track Light',
        'configured_model' => 'AT2020-20W-3000K-24D',
        'ies_original_name' => 'AT2020-demo-24deg.ies',
        'ies_public_id' => 'IES-DEMO-AT2020-24D',
        'ies_validation_status' => 'warning',
        'result' => [
            'room' => [
                'type' => 'retail',
                'length_m' => 10.0,
                'width_m' => 8.0,
                'height_m' => 4.0,
                'installation_height_m' => 3.2,
                'target_lux' => 400.0,
            ],
            'layout' => [
                'quantity' => 20,
                'columns' => 5,
                'rows' => 4,
                'spacing_x_m' => 2.0,
                'spacing_y_m' => 2.0,
            ],
            'metrics' => [
                'average_lux' => 438.0,
                'maximum_lux' => 792.0,
                'minimum_lux' => 176.0,
                'uniformity_u0' => 0.40,
                'target_lux' => 400.0,
                'target_met' => true,
            ],
            'heatmap' => [
                'nx' => 10,
                'ny' => 10,
                'values_lux' => $heatmapValues,
            ],
            'maintenance_factor' => 0.8,
        ],
    ];

    $report = artdon_lighting_ensure_report($project, $pdo);
    report_test(
        array_keys($report) === ['absolute', 'relative', 'size', 'checksum'],
        'the service returns the documented report contract'
    );
    report_test(
        $report['relative'] === 'storage/reports/2026/07/' . $projectId . '.pdf',
        'the database-facing path is derived from the project date and identifier'
    );
    report_test(
        str_starts_with($report['absolute'], $temporaryRoot . '/reports/2026/07/'),
        'the isolated report storage root is honored'
    );
    report_test(
        is_file($report['absolute'])
            && $report['size'] === filesize($report['absolute'])
            && $report['size'] > 2_000,
        'a complete non-empty PDF is published'
    );
    report_test(
        hash_file('sha256', $report['absolute']) === $report['checksum'],
        'the returned SHA-256 matches the published file'
    );
    report_test(
        artdon_lighting_inspect_report_file($report['absolute']) !== null,
        'the published PDF passes structural and checksum inspection'
    );

    $stored = $pdo->query(
        "SELECT report_path, report_checksum_sha256
         FROM simulation_projects
         WHERE public_id = '{$projectId}'"
    )->fetch();
    report_test(
        is_array($stored)
            && $stored['report_path'] === $report['relative']
            && $stored['report_checksum_sha256'] === $report['checksum'],
        'verified report metadata is recorded on the simulation project'
    );

    touch($report['absolute'], 946684800);
    clearstatcache(true, $report['absolute']);
    $stableModifiedTime = filemtime($report['absolute']);
    $reused = artdon_lighting_ensure_report($project, $pdo);
    clearstatcache(true, $report['absolute']);
    report_test(
        $reused === $report && filemtime($report['absolute']) === $stableModifiedTime,
        'a structurally valid report with matching metadata is reused without rewriting'
    );

    file_put_contents($report['absolute'], "%PDF-1.4\ncorrupt\n%%EOF\n", LOCK_EX);
    $repaired = artdon_lighting_ensure_report($project, $pdo);
    report_test(
        $repaired['size'] > 2_000
            && $repaired['checksum'] === hash_file('sha256', $repaired['absolute']),
        'a truncated report is regenerated and verified'
    );

    $pdo->prepare(
        'UPDATE simulation_projects
         SET report_checksum_sha256 = :checksum
         WHERE public_id = :public_id'
    )->execute([
        ':checksum' => str_repeat('0', 64),
        ':public_id' => $projectId,
    ]);
    $metadataRepaired = artdon_lighting_ensure_report($project, $pdo);
    $storedChecksum = $pdo->query(
        "SELECT report_checksum_sha256
         FROM simulation_projects
         WHERE public_id = '{$projectId}'"
    )->fetchColumn();
    report_test(
        $metadataRepaired['checksum'] === $storedChecksum
            && $storedChecksum === hash_file('sha256', $metadataRepaired['absolute']),
        'a checksum mismatch causes regeneration and repairs stored metadata'
    );

    report_test(
        (glob(dirname($report['absolute']) . '/.lighting-report-*') ?: []) === [],
        'atomic publication leaves no temporary report files behind'
    );
    report_test_rejects(
        static fn (): array => artdon_lighting_ensure_report(
            array_replace($project, ['public_id' => '../escape']),
            $pdo
        ),
        'an invalid report identity is rejected before filesystem access'
    );

    $otherSession = hash('sha256', 'another-session');
    $pdo->prepare(
        'UPDATE simulation_projects
         SET session_key_hash = :session_key_hash
         WHERE public_id = :public_id'
    )->execute([
        ':session_key_hash' => $otherSession,
        ':public_id' => $projectId,
    ]);
    report_test_rejects(
        static fn (): array => artdon_lighting_ensure_report($project, $pdo),
        'a project outside the active session cannot generate or reuse a report'
    );

    fwrite(STDOUT, sprintf("\nAll %d lighting-report tests passed.\n", $tests));
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "\n" . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    putenv('ARTDON_REPORT_STORAGE_PATH');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $path) {
        $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
    }
    @rmdir($temporaryRoot);
}

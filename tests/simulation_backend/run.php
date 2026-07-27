<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__, 2);
$temporaryRoot = sys_get_temp_dir() . '/artdon-simulation-backend-' . bin2hex(random_bytes(5));
if (!mkdir($temporaryRoot, 0750, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('Unable to create the test directory.');
}
putenv('APP_DATABASE_PATH=' . $temporaryRoot . '/artdon.sqlite');
putenv('ARTDON_RATE_LIMIT_PATH=' . $temporaryRoot . '/rate-limits');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_id('artdon-simulation-backend-test');
    session_start();
}
$_SESSION = [];

require_once $root . '/includes/api.php';
require_once $root . '/includes/lighting_repository.php';
require_once $root . '/includes/simple_pdf.php';

$tests = 0;

function simulationTest(bool $condition, string $message): void
{
    global $tests;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $tests++;
    echo "PASS {$tests}: {$message}\n";
}

function simulationRejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        simulationTest(true, $message);
        return;
    }
    throw new RuntimeException('FAIL: ' . $message);
}

try {
    $databaseBootstrap = artdon_db_bootstrap(true);
    artdon_lighting_seed_demo_profiles($databaseBootstrap['pdo']);
    $pdo = artdon_lighting_bootstrap();
    $products = artdon_lighting_products($pdo);
    simulationTest(count($products) === 3, 'three explicitly labelled demo products are exposed');

    $profileCount = array_sum(array_map(
        static fn (array $product): int => count((array) ($product['profiles'] ?? [])),
        $products
    ));
    simulationTest($profileCount === 3, 'three demo photometric profiles are bound to catalog products');
    foreach ($products as $product) {
        foreach ((array) $product['profiles'] as $publicProfile) {
            simulationTest(
                ($publicProfile['data_status'] ?? '') === 'synthetic_preliminary_demo',
                'profile is visibly marked as synthetic preliminary data'
            );
            simulationTest(
                ($publicProfile['manufacturer_validated'] ?? true) === false,
                'profile never claims manufacturer validation'
            );
            simulationTest(!isset($publicProfile['file_path']), 'profile API shape does not expose a server path');
        }
    }

    $profile = artdon_lighting_find_profile('IES-DEMO-AT2020-24D', $pdo);
    simulationTest(is_array($profile), 'a profile can be resolved by its public identifier');
    simulationTest(
        ($profile['configuration_match']['beam'] ?? '') === '24',
        'the profile carries its optical configuration binding'
    );
    simulationRejects(
        fn (): array => artdon_lighting_bound_configuration($profile, ['beam' => '60']),
        'a mismatched optical configuration is rejected'
    );
    $configuration = artdon_lighting_bound_configuration($profile, ['cct' => '3000K']);
    simulationTest(
        ($configuration['beam'] ?? '') === '24' && ($configuration['power'] ?? '') === '20W',
        'the profile optical binding is included in the saved configuration'
    );

    $request = [
        'mode' => 'single',
        'room' => [
            'type' => 'retail',
            'length_m' => 8,
            'width_m' => 6,
            'height_m' => 4,
            'installation_height_m' => 3,
            'calculation_plane_m' => 0.8,
            'mounting_type' => 'track',
            'target_lux' => 300,
        ],
        'options' => ['grid_nx' => 10, 'grid_ny' => 10],
        'maintenance_factor' => 0.8,
    ];
    $result = artdon_lighting_simulate_profile($profile, $request);
    simulationTest(($result['success'] ?? false) === true, 'the bound IES profile produces a simulation');
    simulationTest(count((array) $result['heatmap']['values_lux']) === 100, 'the simulation returns a 10 by 10 heatmap');
    simulationTest(abs((float) $result['metrics']['target_lux'] - 300.0) < 0.001, 'the requested target is restored after maintenance-factor calculation');
    simulationTest(abs((float) $result['maintenance_factor'] - 0.8) < 0.001, 'the maintenance factor is recorded');
    simulationTest(strlen((string) $result['calculation_hash']) === 64, 'the final adjusted result has a calculation hash');
    simulationRejects(
        fn (): array => artdon_lighting_simulate_profile(
            $profile,
            array_replace_recursive($request, ['options' => ['grid_nx' => 37, 'grid_ny' => 10]])
        ),
        'a heatmap wider than the public 36-cell axis cap is rejected'
    );
    simulationRejects(
        fn (): array => artdon_lighting_simulate_profile(
            $profile,
            array_replace_recursive($request, ['options' => ['max_fixtures' => 121]])
        ),
        'a fixture search above the public 120-luminaire cap is rejected'
    );
    simulationRejects(
        fn (): array => artdon_lighting_simulate_profile($profile, array_replace_recursive($request, [
            'mode' => 'layout',
            'layout' => ['columns' => 11, 'rows' => 11, 'rotation_deg' => 0],
        ])),
        'a manual public layout above 120 luminaires is rejected'
    );

    $defaultProfileId = (string) ($products[0]['profiles'][0]['id'] ?? '');
    $defaultProfile = artdon_lighting_find_profile($defaultProfileId, $pdo);
    $defaultStarted = microtime(true);
    $defaultResult = artdon_lighting_simulate_profile((array) $defaultProfile, [
        'mode' => 'auto_layout',
        'room' => [
            'type' => 'retail',
            'length_m' => 10,
            'width_m' => 8,
            'height_m' => 4,
            'installation_height_m' => 3.2,
            'calculation_plane_m' => 0,
            'mounting_type' => 'recessed',
            'target_lux' => 400,
        ],
        'options' => ['grid_nx' => 29, 'grid_ny' => 23, 'max_fixtures' => 96],
        'maintenance_factor' => 0.8,
    ]);
    $defaultElapsed = microtime(true) - $defaultStarted;
    simulationTest(
        ($defaultResult['success'] ?? false) === true
            && (int) ($defaultResult['layout']['quantity'] ?? 0) <= 96
            && count((array) ($defaultResult['heatmap']['values_lux'] ?? [])) === 29 * 23,
        'the page default 10 by 8 metre auto-layout succeeds within public caps'
    );
    simulationTest(
        $defaultElapsed < 4.0,
        sprintf('the page default simulation completes promptly (%.3f seconds)', $defaultElapsed)
    );

    $firstRateCheck = artdon_lighting_rate_limit('simulation-test', '203.0.113.15', 2, 60);
    $secondRateCheck = artdon_lighting_rate_limit('simulation-test', '203.0.113.15', 2, 60);
    $thirdRateCheck = artdon_lighting_rate_limit('simulation-test', '203.0.113.15', 2, 60);
    simulationTest(
        $firstRateCheck['allowed'] && $secondRateCheck['allowed'] && !$thirdRateCheck['allowed'],
        'the atomic IP-aware rate limiter rejects requests above its window'
    );
    $rateFiles = glob($temporaryRoot . '/rate-limits/*.json') ?: [];
    simulationTest(count($rateFiles) === 1, 'rate-limit state is stored under the protected server directory');
    $rateFileContents = file_get_contents($rateFiles[0]);
    simulationTest(
        is_string($rateFileContents) && !str_contains($rateFileContents, '203.0.113.15'),
        'rate-limit state never stores the client identity'
    );
    $staleRateFile = $temporaryRoot . '/rate-limits/stale-' . str_repeat('a', 64) . '.json';
    $freshRateFile = $temporaryRoot . '/rate-limits/fresh-' . str_repeat('b', 64) . '.json';
    file_put_contents($staleRateFile, '[]');
    file_put_contents($freshRateFile, '[]');
    touch($staleRateFile, time() - 90_000);
    $removedRateFiles = artdon_lighting_cleanup_rate_limit_files(
        $temporaryRoot . '/rate-limits',
        86_400,
        256
    );
    simulationTest(
        $removedRateFiles === 1 && !is_file($staleRateFile) && is_file($freshRateFile),
        'stale rate-limit state is removed without deleting current windows'
    );
    $apiRateDirectory = $temporaryRoot . '/api-rate-limits';
    mkdir($apiRateDirectory, 0750, true);
    $staleApiRateFile = $apiRateDirectory . '/cart-' . str_repeat('c', 64) . '.json';
    $freshApiRateFile = $apiRateDirectory . '/cart-' . str_repeat('d', 64) . '.json';
    file_put_contents($staleApiRateFile, '{}');
    file_put_contents($freshApiRateFile, '{}');
    touch($staleApiRateFile, time() - 90_000);
    $removedApiRateFiles = api_cleanup_rate_limit_files($apiRateDirectory, 86_400, 256);
    simulationTest(
        $removedApiRateFiles === 1
            && !is_file($staleApiRateFile)
            && is_file($freshApiRateFile),
        'the shared API limiter uses bounded stale-state cleanup'
    );

    $maximumGridRequest = array_replace_recursive($request, [
        'options' => ['grid_nx' => 36, 'grid_ny' => 36],
    ]);
    $maximumGridResult = artdon_lighting_simulate_profile($profile, $maximumGridRequest);
    $pendingResult = artdon_lighting_store_pending([
        'profile' => $profile,
        'configuration' => $configuration,
        'input' => [
            'project_name' => 'Test retail project',
            'profile_id' => $profile['public_id'],
            'configuration' => $configuration,
            'mode' => 'single',
            'room' => $request['room'],
            'layout' => null,
            'options' => $maximumGridRequest['options'],
            'maintenance_factor' => 0.8,
        ],
        'result' => $maximumGridResult,
    ]);
    simulationTest(
        preg_match('/^LST-[A-F0-9]{16}$/', $pendingResult['token']) === 1,
        'a session-bound pending token is issued'
    );
    $storedPending = (array) ($_SESSION['lighting_pending_simulations'] ?? []);
    $serializedPending = serialize($storedPending);
    simulationTest(
        !str_contains($serializedPending, 'parsed_data')
            && !str_contains($serializedPending, 'candela_cd')
            && !str_contains($serializedPending, 'distribution_json'),
        'pending session data excludes parsed IES and candela distributions'
    );
    simulationTest(
        strlen($serializedPending) < 70_000,
        sprintf(
            'pending simulation session data remains below the bounded size ceiling (%d bytes)',
            strlen($serializedPending)
        )
    );
    $pending = artdon_lighting_pending($pendingResult['token']);
    simulationTest(is_array($pending), 'the current session can recover its pending simulation');
    simulationTest(
        is_array($pending['profile']['parsed_data'] ?? null),
        'the pending profile is reloaded from the database only when needed'
    );
    simulationTest(
        !isset($pending['result']['layout']['fixtures']),
        'pending results omit derivable fixture coordinates'
    );

    $project = artdon_lighting_create_project($pending, "Test\x01 retail project", $pdo);
    simulationTest(
        preg_match('/^SIM-[A-F0-9]{16}$/', (string) $project['public_id']) === 1,
        'the simulation project is saved with an opaque public identifier'
    );
    simulationTest(
        artdon_lighting_find_project((string) $project['public_id'], str_repeat('0', 64), $pdo) === null,
        'a different session hash cannot retrieve the project'
    );
    $publicProject = artdon_lighting_public_project($project);
    simulationTest(
        ($publicProject['ies']['manufacturer_validated'] ?? true) === false,
        'the saved project retains the unvalidated data warning'
    );
    simulationTest(
        !isset($publicProject['report']['path']),
        'the saved project exposes a report URL but no filesystem path'
    );

    $safePath = artdon_lighting_report_path($project);
    simulationTest(
        preg_match('#^storage/reports/\d{4}/\d{2}/SIM-[A-F0-9]{16}\.pdf$#', $safePath['relative']) === 1,
        'the report path is derived inside the protected report directory'
    );
    simulationRejects(
        fn (): array => artdon_lighting_report_path(['public_id' => '../escape', 'created_at' => 'now']),
        'path traversal cannot be used as a report identity'
    );

    $pdfBytes = artdon_simple_pdf_report($project);
    $reportFile = $temporaryRoot . '/simulation-report.pdf';
    file_put_contents($reportFile, $pdfBytes);
    simulationTest(str_starts_with($pdfBytes, '%PDF-1.4'), 'the report has a real PDF header');
    simulationTest(str_ends_with($pdfBytes, "%%EOF\n"), 'the report has a complete PDF trailer');
    simulationTest(str_contains($pdfBytes, 'Lighting Simulation Report'), 'the report includes its title');
    simulationTest(str_contains($pdfBytes, 'SYNTHETIC PRELIMINARY DEMO'), 'the report includes the demo-data warning');
    simulationTest(str_contains($pdfBytes, 'FALSE-COLOR ILLUMINANCE MAP'), 'the report includes a heatmap section');
    simulationTest(strlen($pdfBytes) > 5_000, 'the report contains rendered report and heatmap content');

    echo "\nAll {$tests} simulation-backend tests passed.\n";
    echo "PDF_FIXTURE={$reportFile}\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "\n{$error->getMessage()}\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
}

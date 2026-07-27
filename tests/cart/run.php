<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$temporaryDatabase = sys_get_temp_dir() . '/artdon_cart_test_' . bin2hex(random_bytes(6)) . '.sqlite';
putenv('APP_DATABASE_PATH=' . $temporaryDatabase);
$reportRelative = 'storage/reports/tests/cart-' . bin2hex(random_bytes(8)) . '.pdf';
$reportAbsolute = dirname(__DIR__, 2) . '/' . $reportRelative;

require dirname(__DIR__, 2) . '/includes/database.php';
require dirname(__DIR__, 2) . '/includes/cart.php';

$assertions = 0;

function cart_test_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function cart_test_same(mixed $expected, mixed $actual, string $message): void
{
    cart_test_assert(
        $expected === $actual,
        $message . sprintf(
            ' (expected %s, got %s)',
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

/**
 * @param callable():mixed $callback
 */
function cart_test_error(string $expectedCode, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (ArtdonCartException $error) {
        cart_test_same($expectedCode, $error->errorCode, $message);
        return;
    }

    throw new RuntimeException('Expected cart error was not thrown: ' . $message);
}

try {
    $database = artdon_db_bootstrap(true);
    $pdo = $database['pdo'];
    $sessionA = hash('sha256', 'artdon-session-v1|cart-test-session-a');
    $sessionB = hash('sha256', 'artdon-session-v1|cart-test-session-b');

    $cart = artdon_cart_get($sessionA, $pdo);
    cart_test_same(0, $cart['summary']['line_count'], 'new cart is empty');
    cart_test_same(1, $cart['version'], 'new cart starts at version 1');
    cart_test_same(
        0,
        (int) $pdo->query('SELECT COUNT(*) FROM project_carts')->fetchColumn(),
        'read-only cart access does not create a database row'
    );

    cart_test_error(
        'below_moq',
        static fn() => artdon_cart_mutate('add', [
            'item' => ['sku' => 'AT2020', 'configuration' => [], 'quantity' => 19],
        ], $sessionA, $pdo),
        'MOQ is enforced by the server'
    );
    cart_test_error(
        'unknown_configuration_option',
        static fn() => artdon_cart_mutate('add', [
            'item' => [
                'sku' => 'AT2020',
                'configuration' => ['made_up_option' => 'yes'],
                'quantity' => 20,
            ],
        ], $sessionA, $pdo),
        'unknown option is rejected'
    );
    cart_test_error(
        'configuration_not_allowed',
        static fn() => artdon_cart_mutate('add', [
            'item' => [
                'sku' => 'AT2020',
                'configuration' => ['power' => '20W', 'beam' => '15'],
                'quantity' => 20,
            ],
        ], $sessionA, $pdo),
        'deny rule is enforced'
    );

    $cart = artdon_cart_mutate('add', [
        'expected_version' => 1,
        'item' => [
            'sku' => 'AT2020',
            'configuration' => [],
            'quantity' => 20,
            'unit_price' => 0.01,
            'moq' => 1,
            'configured_model' => 'CLIENT-FORGED-MODEL',
        ],
    ], $sessionA, $pdo, 'test-add-1');
    cart_test_same(1, $cart['summary']['line_count'], 'valid item is added');
    cart_test_same(20, $cart['summary']['total_quantity'], 'quantity is persisted');
    cart_test_same(80.0, $cart['items'][0]['unit_price'], 'price is rebuilt on the server');
    cart_test_same(
        'AT2020-20W-BK-3000K-24D-LIF-ON',
        $cart['items'][0]['configured_model'],
        'configured model is rebuilt on the server'
    );
    $firstItemId = $cart['items'][0]['item_id'];

    $cart = artdon_cart_mutate('add', [
        'item' => ['sku' => 'AT2020', 'configuration' => [], 'quantity' => 20],
    ], $sessionA, $pdo);
    cart_test_same(1, $cart['summary']['line_count'], 'identical add merges into one line');
    cart_test_same(40, $cart['summary']['total_quantity'], 'merged quantity is correct');

    cart_test_error(
        'cart_version_conflict',
        static fn() => artdon_cart_mutate('update', [
            'expected_version' => 1,
            'item_id' => $GLOBALS['firstItemId'],
            'item' => ['quantity' => 30],
        ], $sessionA, $pdo),
        'stale expected_version is rejected'
    );

    $cart = artdon_cart_mutate('update', [
        'expected_version' => $cart['version'],
        'item_id' => $firstItemId,
        'item' => ['quantity' => 30, 'customer_note' => 'Aisle display area'],
    ], $sessionA, $pdo);
    cart_test_same(30, $cart['items'][0]['quantity'], 'update changes the quantity');
    cart_test_same('Aisle display area', $cart['items'][0]['customer_note'], 'update saves note');

    $cart = artdon_cart_mutate('duplicate', [
        'item_id' => $firstItemId,
        'quantity' => 25,
    ], $sessionA, $pdo);
    cart_test_same(2, $cart['summary']['line_count'], 'duplicate creates a separate line');
    cart_test_same(55, $cart['summary']['total_quantity'], 'duplicate quantity is counted');
    $duplicatedItemId = $cart['items'][1]['item_id'];

    $cart = artdon_cart_mutate('remove', [
        'item_id' => $duplicatedItemId,
    ], $sessionA, $pdo);
    cart_test_same(1, $cart['summary']['line_count'], 'remove deletes only the selected line');

    $beforeFailedReplace = $cart;
    cart_test_error(
        'configuration_not_allowed',
        static fn() => artdon_cart_mutate('replace', [
            'items' => [
                ['sku' => 'AL1010', 'configuration' => [], 'quantity' => 20],
                [
                    'sku' => 'AT2020',
                    'configuration' => ['power' => '20W', 'beam' => '15'],
                    'quantity' => 20,
                ],
            ],
        ], $sessionA, $pdo),
        'replace validates every line'
    );
    $afterFailedReplace = artdon_cart_get($sessionA, $pdo);
    cart_test_same(
        $beforeFailedReplace['version'],
        $afterFailedReplace['version'],
        'failed replace rolls back the version'
    );
    cart_test_same(
        $beforeFailedReplace['items'][0]['configured_model'],
        $afterFailedReplace['items'][0]['configured_model'],
        'failed replace preserves existing lines'
    );

    $cart = artdon_cart_mutate('replace', [
        'project_name' => 'Singapore Flagship Store',
        'items' => [
            ['sku' => 'AL1010', 'configuration' => [], 'quantity' => 20],
            ['sku' => 'DR7010', 'configuration' => [], 'quantity' => 50],
        ],
    ], $sessionA, $pdo);
    cart_test_same('Singapore Flagship Store', $cart['project_name'], 'replace updates project name');
    cart_test_same(2, $cart['summary']['line_count'], 'replace installs all valid items');
    cart_test_same(70, $cart['summary']['total_quantity'], 'replace total quantity is correct');

    $product = artdon_catalog_find_by_sku('AT2020', $pdo);
    cart_test_assert(is_array($product), 'test product exists');
    $now = artdon_db_now();
    $ies = $pdo->prepare(
        'INSERT INTO ies_library (
            public_id, product_id, option_signature, configured_model, version,
            original_name, file_path, checksum_sha256, validation_status,
            status, created_at, updated_at
         ) VALUES (
            :public_id, :product_id, :option_signature, :configured_model, 1,
            :original_name, :file_path, :checksum_sha256, :validation_status,
            :status, :created_at, :updated_at
         )'
    );
    $ies->execute([
        ':public_id' => 'IES-CART-TEST-01',
        ':product_id' => (int) $product['id'],
        ':option_signature' => '{"beam":"24"}',
        ':configured_model' => 'AT20-20W-BK-3000K-24D-LIF-ON',
        ':original_name' => 'cart-test.ies',
        ':file_path' => 'tests/lighting/fixtures/synthetic-constant-1000cd.ies',
        ':checksum_sha256' => hash('sha256', 'cart-test-ies'),
        ':validation_status' => 'valid',
        ':status' => 'active',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $iesId = (int) $pdo->lastInsertId();
    $simulationConfigurationResult = artdon_configurator_configure('AT2020', [], 20, $pdo);
    if (empty($simulationConfigurationResult['valid'])) {
        throw new RuntimeException('Unable to prepare the canonical simulation configuration fixture.');
    }
    $simulationConfigurationJson = artdon_json_encode(
        (array) $simulationConfigurationResult['configuration']
    );
    if (!is_dir(dirname($reportAbsolute))
        && !mkdir(dirname($reportAbsolute), 0750, true)
        && !is_dir(dirname($reportAbsolute))
    ) {
        throw new RuntimeException('Unable to create the cart report fixture directory.');
    }
    file_put_contents($reportAbsolute, "%PDF-1.4\ncart report fixture\n%%EOF\n", LOCK_EX);
    $reportChecksum = hash_file('sha256', $reportAbsolute);
    if (!is_string($reportChecksum)) {
        throw new RuntimeException('Unable to checksum the cart report fixture.');
    }

    $simulation = $pdo->prepare(
        'INSERT INTO simulation_projects (
            public_id, session_key_hash, project_name, room_type,
            room_length_m, room_width_m, room_height_m, installation_height_m,
            work_plane_height_m, mounting_type, target_lux, maintenance_factor,
            product_id, ies_library_id, configured_model,
            configuration_snapshot_json, simulation_mode, fixture_quantity,
            average_lux, maximum_lux, minimum_lux, uniformity,
            input_snapshot_json, result_json, heatmap_json, algorithm_version,
            status, report_path, report_checksum_sha256, created_at, updated_at
         ) VALUES (
            :public_id, :session_key_hash, :project_name, :room_type,
            10, 8, 4, 4, 0, :mounting_type, 500, 0.8,
            :product_id, :ies_library_id, :configured_model,
            :configuration_snapshot_json, :simulation_mode, 20,
            510, 680, 220, 0.43,
            :input_snapshot_json, :result_json, :heatmap_json, :algorithm_version,
            :status, :report_path, :report_checksum_sha256, :created_at, :updated_at
         )'
    );
    $simulation->execute([
        ':public_id' => 'SIM-CART-OWNED-01',
        ':session_key_hash' => $sessionA,
        ':project_name' => 'Owned simulation',
        ':room_type' => 'retail',
        ':mounting_type' => 'track',
        ':product_id' => (int) $product['id'],
        ':ies_library_id' => $iesId,
        ':configured_model' => 'AT20-20W-BK-3000K-24D-LIF-ON',
        ':configuration_snapshot_json' => $simulationConfigurationJson,
        ':simulation_mode' => 'auto_layout',
        ':input_snapshot_json' => '{}',
        ':result_json' => '{}',
        ':heatmap_json' => '[]',
        ':algorithm_version' => 'cart-test',
        ':status' => 'completed',
        ':report_path' => $reportRelative,
        ':report_checksum_sha256' => $reportChecksum,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    cart_test_error(
        'simulation_not_found',
        static fn() => artdon_cart_mutate('add', [
            'item' => [
                'sku' => 'AT2020',
                'configuration' => [],
                'quantity' => 20,
                'simulation_project_id' => 'SIM-CART-OWNED-01',
            ],
        ], $sessionB, $pdo),
        'another session cannot attach a simulation'
    );
    cart_test_error(
        'simulation_configuration_mismatch',
        static fn() => artdon_cart_mutate('add', [
            'item' => [
                'sku' => 'AT2020',
                'configuration' => ['beam' => '36'],
                'quantity' => 20,
                'simulation_project_id' => 'SIM-CART-OWNED-01',
            ],
        ], $sessionA, $pdo),
        'simulation optical binding must match the cart configuration'
    );
    $forgedSimulationConfiguration = (array) $simulationConfigurationResult['configuration'];
    $forgedSimulationConfiguration['not_a_product_option'] = 'forged';
    $updateSimulationConfiguration = $pdo->prepare(
        'UPDATE simulation_projects
         SET configuration_snapshot_json = :configuration
         WHERE public_id = :public_id'
    );
    $updateSimulationConfiguration->execute([
        ':configuration' => artdon_json_encode($forgedSimulationConfiguration),
        ':public_id' => 'SIM-CART-OWNED-01',
    ]);
    cart_test_error(
        'simulation_configuration_mismatch',
        static fn() => artdon_cart_mutate('add', [
            'item' => [
                'sku' => 'AT2020',
                'configuration' => [],
                'quantity' => 20,
                'simulation_project_id' => 'SIM-CART-OWNED-01',
            ],
        ], $sessionA, $pdo),
        'unknown stored simulation options cannot enter the Project Cart'
    );
    $updateSimulationConfiguration->execute([
        ':configuration' => $simulationConfigurationJson,
        ':public_id' => 'SIM-CART-OWNED-01',
    ]);
    $cart = artdon_cart_mutate('add', [
        'item' => [
            'sku' => 'AT2020',
            'configuration' => [],
            'quantity' => 20,
            'simulation_project_id' => 'SIM-CART-OWNED-01',
        ],
    ], $sessionA, $pdo);
    $simulationLines = array_values(array_filter(
        $cart['items'],
        static fn(array $item): bool => $item['simulation'] !== null
    ));
    cart_test_same(1, count($simulationLines), 'own completed simulation can be attached');
    cart_test_same(
        'SIM-CART-OWNED-01',
        $simulationLines[0]['simulation']['public_id'],
        'simulation public ID is returned'
    );
    cart_test_same(
        '/api/lighting-report.php?id=SIM-CART-OWNED-01',
        $simulationLines[0]['simulation']['report_url'],
        'report endpoint is derived from the owned simulation ID'
    );

    $_SESSION = [];
    $payload = ['action' => 'clear', 'idempotency_key' => 'cart-test-key-0001'];
    cart_test_same(
        null,
        artdon_cart_idempotency_replay('cart-test-key-0001', $payload),
        'new idempotency key is not a replay'
    );
    artdon_cart_idempotency_remember('cart-test-key-0001', $payload, $cart);
    $replay = artdon_cart_idempotency_replay('cart-test-key-0001', $payload);
    cart_test_assert(is_array($replay), 'same idempotency request is recognized');
    cart_test_error(
        'idempotency_conflict',
        static fn() => artdon_cart_idempotency_replay(
            'cart-test-key-0001',
            ['action' => 'remove', 'item_id' => 999]
        ),
        'idempotency key cannot be reused with another payload'
    );

    $cart = artdon_cart_mutate('clear', [], $sessionA, $pdo);
    cart_test_same(0, $cart['summary']['line_count'], 'clear removes all lines');

    echo sprintf("Project Cart tests passed: %d assertions\n", $assertions);
} finally {
    if (is_file($reportAbsolute)) {
        @unlink($reportAbsolute);
    }
    foreach ([$temporaryDatabase, $temporaryDatabase . '-wal', $temporaryDatabase . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

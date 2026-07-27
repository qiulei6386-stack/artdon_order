<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$databasePath = sys_get_temp_dir() . '/artdon-procurement-' . bin2hex(random_bytes(6)) . '.sqlite';
putenv('APP_DATABASE_PATH=' . $databasePath);

require dirname(__DIR__, 2) . '/includes/database.php';
require dirname(__DIR__, 2) . '/includes/api.php';
require dirname(__DIR__, 2) . '/includes/cart.php';
require dirname(__DIR__, 2) . '/includes/procurement.php';

$assertions = 0;

function procurement_test_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function procurement_test_same(mixed $expected, mixed $actual, string $message): void
{
    procurement_test_assert(
        $expected === $actual,
        sprintf('%s; expected %s, got %s', $message, var_export($expected, true), var_export($actual, true))
    );
}

function procurement_test_error(string $code, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (ArtdonProcurementException $error) {
        procurement_test_same($code, $error->errorCode, $message);
        return;
    }

    throw new RuntimeException('Expected procurement error: ' . $message);
}

try {
    $database = artdon_db_bootstrap(true);
    $pdo = $database['pdo'];
    $sessionA = hash('sha256', 'procurement-session-a');

    artdon_procurement_verify_csrf('known-token', 'known-token');
    procurement_test_error(
        'csrf_failed',
        static fn() => artdon_procurement_verify_csrf('wrong-token', 'known-token'),
        'invalid CSRF is rejected'
    );

    $cart = artdon_cart_mutate('add', [
        'item' => [
            'sku' => 'AT2020',
            'configuration' => [],
            'quantity' => 20,
        ],
    ], $sessionA, $pdo);
    procurement_test_same(1, $cart['summary']['line_count'], 'server cart has one line');
    $cartItem = $cart['items'][0];

    $orderInput = [
        'form_type' => 'order_request',
        'submission_token' => str_repeat('a', 40),
        'company' => 'Procurement Test Co',
        'name' => 'Test Buyer',
        'email' => 'buyer@example.test',
        'country' => 'Singapore',
        'project' => 'Authority Test',
        'target_date' => '2026-12-01',
        'trade_term' => 'FOB',
        'message' => 'Use only the server-side cart.',
        'cart_json' => json_encode([
            [
                'sku' => 'FAKE-SKU',
                'configured_model' => 'FAKE-MODEL',
                'quantity' => 999999,
                'unit_price' => 0.01,
            ],
        ], JSON_THROW_ON_ERROR),
    ];
    $order = artdon_procurement_submit($orderInput, [], $sessionA, $pdo, [
        'request_id' => 'procurement-test-order',
        'remote_addr' => '127.0.0.1',
    ]);
    procurement_test_same(false, $order['duplicate'], 'first order is not a replay');
    procurement_test_same('order_request', $order['request_type'], 'order type is normalized');
    procurement_test_same(1, $order['item_count'], 'order copies one authoritative cart line');

    $storedItem = $pdo->query(
        'SELECT pri.*, pr.request_no
         FROM procurement_request_items pri
         JOIN procurement_requests pr ON pr.id = pri.request_id
         WHERE pr.request_type = "order_request"'
    )->fetch();
    procurement_test_assert(is_array($storedItem), 'request item was stored');
    $snapshot = json_decode((string) $storedItem['product_snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
    procurement_test_same('AT2020', $snapshot['sku'], 'client fake SKU is ignored');
    procurement_test_same(
        $cartItem['configured_model'],
        $snapshot['configured_model'],
        'request snapshot keeps the authoritative configured model'
    );
    procurement_test_same(
        (float) $cartItem['unit_price'],
        (float) $storedItem['estimated_unit_price'],
        'client fake price is ignored'
    );
    procurement_test_same(20.0, (float) $storedItem['quantity'], 'client fake quantity is ignored');

    $cartStatus = $pdo->query(
        "SELECT status FROM project_carts WHERE session_key_hash = '" . $sessionA . "' ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    procurement_test_same('submitted', $cartStatus, 'successful order marks cart submitted');
    procurement_test_same(
        'pending',
        $pdo->query('SELECT status FROM sync_jobs ORDER BY id DESC LIMIT 1')->fetchColumn(),
        'ERP job remains pending'
    );
    $queuedPayload = json_decode(
        (string) $pdo->query('SELECT payload_json FROM sync_jobs ORDER BY id DESC LIMIT 1')->fetchColumn(),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    procurement_test_same(1, $queuedPayload['schema_version'], 'ERP payload has a versioned contract');
    procurement_test_same(
        $order['reference'],
        $queuedPayload['request']['request_no'],
        'ERP payload contains the immutable request snapshot'
    );
    procurement_test_same('AT2020', $queuedPayload['items'][0]['product']['sku'], 'ERP payload contains product data');
    procurement_test_same(20.0, (float) $queuedPayload['items'][0]['quantity'], 'ERP payload contains quantity');
    procurement_test_same('buyer@example.test', $queuedPayload['contact']['email'], 'ERP payload contains contact data');
    procurement_test_same(
        1,
        (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'procurement_request.submitted'"
        )->fetchColumn(),
        'submission writes an audit record'
    );

    $replay = artdon_procurement_submit($orderInput, [], $sessionA, $pdo);
    procurement_test_same(true, $replay['duplicate'], 'same persistent token is replayed');
    procurement_test_same($order['reference'], $replay['reference'], 'replay returns original reference');
    procurement_test_same(
        1,
        (int) $pdo->query("SELECT COUNT(*) FROM procurement_requests WHERE request_type = 'order_request'")->fetchColumn(),
        'replay does not create another request'
    );
    $conflictingReplay = $orderInput;
    $conflictingReplay['email'] = 'different@example.test';
    procurement_test_error(
        'idempotency_conflict',
        static fn() => artdon_procurement_submit($conflictingReplay, [], $sessionA, $pdo),
        'same token with different content is rejected'
    );

    $sessionB = hash('sha256', 'procurement-session-b');
    $quick = artdon_procurement_submit([
        'form_type' => 'quick-rfq',
        'submission_token' => str_repeat('b', 40),
        'company' => 'Quick RFQ Co',
        'name' => 'Quick Buyer',
        'email' => 'quick@example.test',
        'country' => 'Australia',
        'models' => 'AL1010',
        'quantity' => '200 pcs',
        'message' => 'Please quote.',
    ], [], $sessionB, $pdo);
    procurement_test_same('quick_rfq', $quick['request_type'], 'Quick RFQ normalizes without a cart');
    procurement_test_same(0, $quick['item_count'], 'Quick RFQ can submit without cart items');

    $sessionC = hash('sha256', 'procurement-session-c');
    $sample = artdon_procurement_submit([
        'form_type' => 'sample-order',
        'submission_token' => str_repeat('c', 40),
        'company' => 'Sample Co',
        'name' => 'Sample Buyer',
        'email' => 'sample@example.test',
        'country' => 'Malaysia',
        'models' => 'AT2020',
        'quantity' => '2 pcs',
    ], [], $sessionC, $pdo);
    procurement_test_same('sample', $sample['request_type'], 'Sample request normalizes without a cart');
    procurement_test_same(0, $sample['item_count'], 'Sample request can submit without cart items');

    procurement_test_error(
        'cart_required',
        static fn() => artdon_procurement_submit([
            'form_type' => 'order_request',
            'submission_token' => str_repeat('d', 40),
            'company' => 'No Cart Co',
            'name' => 'No Cart Buyer',
            'email' => 'nocart@example.test',
            'country' => 'Singapore',
        ], [], hash('sha256', 'no-cart-session'), $pdo),
        'order request cannot submit without a server cart'
    );

    $unsafeFile = tempnam(sys_get_temp_dir(), 'artdon-upload-');
    if ($unsafeFile === false) {
        throw new RuntimeException('Unable to create upload fixture.');
    }
    file_put_contents($unsafeFile, "<?php echo 'unsafe';");
    try {
        procurement_test_error(
            'unsafe_attachment',
            static fn() => artdon_procurement_prepare_uploads([
                'name' => ['fake.pdf'],
                'tmp_name' => [$unsafeFile],
                'error' => [UPLOAD_ERR_OK],
                'size' => [filesize($unsafeFile)],
            ]),
            'executable attachment content is rejected'
        );
    } finally {
        @unlink($unsafeFile);
    }

    $safePdf = tempnam(sys_get_temp_dir(), 'artdon-safe-pdf-');
    if ($safePdf === false) {
        throw new RuntimeException('Unable to create the safe upload fixture.');
    }
    file_put_contents($safePdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
    try {
        $preparedPdf = artdon_procurement_prepare_uploads([
            'name' => ['lighting-brief.pdf'],
            'tmp_name' => [$safePdf],
            'error' => [UPLOAD_ERR_OK],
            'size' => [filesize($safePdf)],
        ]);
        procurement_test_same(
            'application/pdf',
            (string) ($preparedPdf[0]['mime_type'] ?? ''),
            'signature-verified PDF uploads work with or without Fileinfo'
        );
    } finally {
        @unlink($safePdf);
    }

    procurement_test_error(
        'upload_capacity_reached',
        static fn() => artdon_procurement_assert_upload_capacity(
            [['size' => 128]],
            $pdo,
            127,
            0,
            sys_get_temp_dir()
        ),
        'the durable upload quota rejects storage exhaustion before moving files'
    );

    procurement_test_same(
        3,
        (int) $pdo->query('SELECT COUNT(*) FROM procurement_requests')->fetchColumn(),
        'only one order, one Quick RFQ, and one sample request exist'
    );

    echo sprintf("Procurement tests passed: %d assertions\n", $assertions);
} finally {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

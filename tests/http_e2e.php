#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "The HTTP end-to-end test requires PHP cURL.\n");
    exit(2);
}

$baseUrl = rtrim((string) (getenv('ARTDON_TEST_BASE_URL') ?: ''), '/');
if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "Set ARTDON_TEST_BASE_URL to an isolated local or staging instance.\n");
    exit(2);
}

$temporaryDirectory = sys_get_temp_dir() . '/artdon-http-e2e-' . bin2hex(random_bytes(5));
if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    throw new RuntimeException('The HTTP test directory could not be created.');
}
$cookieJar = $temporaryDirectory . '/cookies.txt';
$secondCookieJar = $temporaryDirectory . '/other-cookies.txt';
$assertions = 0;

/**
 * @param array<string,string> $headers
 * @param array<string,mixed>|string|null $body
 * @return array{status:int,body:string,content_type:string}
 */
function http_e2e_request(
    string $baseUrl,
    string $cookieJar,
    string $method,
    string $path,
    array|string|null $body = null,
    array $headers = []
): array {
    $handle = curl_init($baseUrl . '/' . ltrim($path, '/'));
    if ($handle === false) {
        throw new RuntimeException('The HTTP test client could not start.');
    }
    $requestHeaders = ['Accept: application/json, text/html;q=0.9, */*;q=0.8'];
    foreach ($headers as $name => $value) {
        $requestHeaders[] = $name . ': ' . $value;
    }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HTTPHEADER => $requestHeaders,
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($handle);
    if (!is_string($responseBody)) {
        $message = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('HTTP request failed: ' . $message);
    }
    $result = [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'body' => $responseBody,
        'content_type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
    ];
    curl_close($handle);

    return $result;
}

function http_e2e_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('HTTP assertion failed: ' . $message);
    }
}

/**
 * @return array<string,mixed>
 */
function http_e2e_json(array $response, string $context): array
{
    try {
        $payload = json_decode($response['body'], true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException($context . ' did not return JSON.', 0, $error);
    }
    if (!is_array($payload)) {
        throw new RuntimeException($context . ' returned an invalid payload.');
    }

    return $payload;
}

try {
    $page = http_e2e_request($baseUrl, $cookieJar, 'GET', '/lighting-simulation');
    http_e2e_assert($page['status'] === 200, 'simulation page returns 200');
    http_e2e_assert(
        str_contains($page['body'], 'data-lighting-simulation'),
        'simulation page contains its application root'
    );
    if (preg_match('/csrf:(\"[a-f0-9]+\")/', $page['body'], $csrfMatch) !== 1) {
        throw new RuntimeException('The simulation page did not expose a test CSRF token.');
    }
    $csrf = json_decode($csrfMatch[1], true, 4, JSON_THROW_ON_ERROR);
    http_e2e_assert(is_string($csrf) && strlen($csrf) === 48, 'CSRF token is well formed');

    $productsResponse = http_e2e_request(
        $baseUrl,
        $cookieJar,
        'GET',
        '/api/lighting-products.php'
    );
    $productsPayload = http_e2e_json($productsResponse, 'Lighting products');
    http_e2e_assert($productsResponse['status'] === 200, 'lighting products return 200');
    http_e2e_assert(!empty($productsPayload['products'][0]['profiles'][0]), 'an IES profile is available');
    $product = $productsPayload['products'][0];
    $profile = $product['profiles'][0];

    $simulationBody = json_encode([
        'profile_id' => $profile['id'],
        'product_sku' => $product['sku'],
        'configuration' => $profile['configuration_match'] ?? [],
        'mode' => 'auto_layout',
        'room' => [
            'type' => 'retail',
            'length_m' => 8,
            'width_m' => 6,
            'height_m' => 4,
            'installation_height_m' => 3,
            'calculation_plane_m' => 0,
            'mounting_type' => 'track',
            'target_lux' => 300,
        ],
        'maintenance_factor' => 0.8,
        'options' => ['grid_nx' => 20, 'grid_ny' => 20, 'max_fixtures' => 96],
    ], JSON_THROW_ON_ERROR);
    $wrongProductResponse = http_e2e_request(
        $baseUrl,
        $cookieJar,
        'POST',
        '/api/lighting-simulate.php',
        json_encode([
            'profile_id' => $profile['id'],
            'product_sku' => 'NONMATCHING-SKU',
            'configuration' => $profile['configuration_match'] ?? [],
            'mode' => 'single',
            'room' => [
                'type' => 'retail',
                'length_m' => 8,
                'width_m' => 6,
                'height_m' => 4,
                'installation_height_m' => 3,
                'calculation_plane_m' => 0,
                'mounting_type' => 'track',
                'target_lux' => 300,
            ],
            'maintenance_factor' => 0.8,
            'options' => ['grid_nx' => 20, 'grid_ny' => 20, 'max_fixtures' => 96],
        ], JSON_THROW_ON_ERROR),
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf]
    );
    http_e2e_assert(
        $wrongProductResponse['status'] === 422,
        'a profile cannot silently substitute a different product'
    );
    $unknownOptionResponse = http_e2e_request(
        $baseUrl,
        $cookieJar,
        'POST',
        '/api/lighting-simulate.php',
        json_encode([
            'profile_id' => $profile['id'],
            'product_sku' => $product['sku'],
            'configuration' => array_merge(
                (array) ($profile['configuration_match'] ?? []),
                ['not_a_product_option' => 'forged']
            ),
            'mode' => 'single',
            'room' => [
                'type' => 'retail',
                'length_m' => 8,
                'width_m' => 6,
                'height_m' => 4,
                'installation_height_m' => 3,
                'calculation_plane_m' => 0,
                'mounting_type' => 'track',
                'target_lux' => 300,
            ],
            'maintenance_factor' => 0.8,
            'options' => ['grid_nx' => 20, 'grid_ny' => 20, 'max_fixtures' => 96],
        ], JSON_THROW_ON_ERROR),
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf]
    );
    http_e2e_assert(
        $unknownOptionResponse['status'] === 422,
        'simulation rejects options outside the server product schema'
    );
    $simulationResponse = http_e2e_request(
        $baseUrl,
        $cookieJar,
        'POST',
        '/api/lighting-simulate.php',
        $simulationBody,
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf]
    );
    $simulation = http_e2e_json($simulationResponse, 'Lighting simulation');
    http_e2e_assert($simulationResponse['status'] === 200, 'simulation returns 200');
    http_e2e_assert(!empty($simulation['success']), 'simulation succeeds');
    http_e2e_assert(
        count((array) ($simulation['result']['heatmap']['values_lux'] ?? [])) === 400,
        'simulation returns a 20 by 20 heatmap'
    );

    $projectResponse = http_e2e_request(
        $baseUrl,
        $cookieJar,
        'POST',
        '/api/lighting-project.php',
        json_encode([
            'simulation_token' => $simulation['simulation_token'],
            'project_name' => 'HTTP release verification',
        ], JSON_THROW_ON_ERROR),
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf]
    );
    $saved = http_e2e_json($projectResponse, 'Simulation project');
    http_e2e_assert($projectResponse['status'] === 201, 'simulation project is created');
    $projectId = (string) ($saved['project']['id'] ?? '');
    http_e2e_assert(
        preg_match('/^SIM-[A-F0-9]{16}$/', $projectId) === 1,
        'saved simulation has an opaque identifier'
    );

    $reportResponse = http_e2e_request(
        $baseUrl,
        $cookieJar,
        'GET',
        '/api/lighting-report.php?id=' . rawurlencode($projectId)
    );
    http_e2e_assert($reportResponse['status'] === 200, 'report download returns 200');
    http_e2e_assert(
        str_starts_with($reportResponse['content_type'], 'application/pdf'),
        'report has the PDF content type'
    );
    http_e2e_assert(str_starts_with($reportResponse['body'], '%PDF-1.4'), 'report is a PDF');
    $reportPath = $temporaryDirectory . '/simulation-report.pdf';
    file_put_contents($reportPath, $reportResponse['body'], LOCK_EX);

    $otherSessionReport = http_e2e_request(
        $baseUrl,
        $secondCookieJar,
        'GET',
        '/api/lighting-report.php?id=' . rawurlencode($projectId)
    );
    http_e2e_assert($otherSessionReport['status'] === 404, 'another session cannot read the report');

    $configurationResponse = http_e2e_request(
        $baseUrl,
        $cookieJar,
        'GET',
        '/api/configure.php?sku=' . rawurlencode((string) $product['sku'])
    );
    $configuration = http_e2e_json($configurationResponse, 'Product configuration');
    http_e2e_assert($configurationResponse['status'] === 200, 'configurator returns 200');
    $minimum = max(1, (int) ceil((float) ($configuration['data']['product']['moq'] ?? 1)));
    $recommended = max(1, (int) ($simulation['result']['layout']['quantity'] ?? 1));

    $cartResponse = http_e2e_request(
        $baseUrl,
        $cookieJar,
        'POST',
        '/api/cart.php',
        json_encode([
            'action' => 'add',
            'item' => [
                'sku' => $product['sku'],
                'configuration' => $simulation['product']['configuration'],
                'quantity' => max($minimum, $recommended),
                'customer_note' => 'HTTP end-to-end verification.',
                'simulation_project_id' => $projectId,
            ],
        ], JSON_THROW_ON_ERROR),
        [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $csrf,
            'Idempotency-Key' => 'http-e2e-' . bin2hex(random_bytes(8)),
        ]
    );
    $cart = http_e2e_json($cartResponse, 'Project Cart');
    http_e2e_assert(
        $cartResponse['status'] === 200,
        sprintf(
            'simulation adds to Project Cart (status %d, response %s)',
            $cartResponse['status'],
            substr($cartResponse['body'], 0, 500)
        )
    );
    http_e2e_assert(
        count((array) ($cart['data']['cart']['items'] ?? [])) === 1,
        'Project Cart contains one validated line'
    );
    http_e2e_assert(
        ($cart['data']['cart']['items'][0]['simulation']['public_id'] ?? '') === $projectId,
        'cart line retains the simulation project'
    );

    $cartPage = http_e2e_request($baseUrl, $cookieJar, 'GET', '/cart');
    if (preg_match(
        '/name=\"submission_token\"\\s+value=\"([a-f0-9]{40})\"/',
        $cartPage['body'],
        $tokenMatch
    ) !== 1) {
        throw new RuntimeException('The cart page did not expose a submission token.');
    }
    $submissionToken = $tokenMatch[1];
    $submissionFields = [
        'csrf_token' => $csrf,
        'submission_token' => $submissionToken,
        'form_type' => 'order_request',
        'company' => 'Artdon HTTP Verification',
        'name' => 'Release Test',
        'email' => 'release-test@example.invalid',
        'country' => 'Singapore',
        'project' => 'Automated release verification',
        'message' => 'Isolated test record.',
        'attachments' => new CURLFile(
            $reportPath,
            'application/pdf',
            'lighting-simulation-report.pdf'
        ),
    ];
    $submissionResponse = http_e2e_request(
        $baseUrl,
        $cookieJar,
        'POST',
        '/api/submit.php',
        $submissionFields
    );
    $submission = http_e2e_json($submissionResponse, 'Procurement submission');
    http_e2e_assert($submissionResponse['status'] === 200, 'order request returns 200');
    http_e2e_assert(!empty($submission['success']), 'order request succeeds');
    http_e2e_assert(($submission['item_count'] ?? 0) === 1, 'order request uses the server cart');
    http_e2e_assert(
        ($submission['attachment_count'] ?? 0) === 1,
        'a signature-verified PDF is stored in quarantine'
    );

    $replayResponse = http_e2e_request(
        $baseUrl,
        $cookieJar,
        'POST',
        '/api/submit.php',
        $submissionFields
    );
    $replay = http_e2e_json($replayResponse, 'Procurement replay');
    http_e2e_assert($replayResponse['status'] === 200, 'submission replay returns 200');
    http_e2e_assert(!empty($replay['duplicate']), 'submission replay is idempotent');
    http_e2e_assert(
        ($replay['reference'] ?? '') === ($submission['reference'] ?? ''),
        'submission replay returns the original reference'
    );

    $protected = http_e2e_request($baseUrl, $cookieJar, 'GET', '/storage/artdon.sqlite');
    http_e2e_assert($protected['status'] === 404, 'protected storage returns 404');
    $protectedTests = http_e2e_request($baseUrl, $cookieJar, 'GET', '/tests/cart/run.php');
    http_e2e_assert($protectedTests['status'] === 404, 'test entry points return 404');

    fwrite(STDOUT, 'HTTP end-to-end tests passed: ' . $assertions . " assertions\n");
    fwrite(STDOUT, 'REPORT_FILE=' . $reportPath . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

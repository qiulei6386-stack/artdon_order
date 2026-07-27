<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/configurator.php';

$requestId = api_request_id();

try {
    $pdo = artdon_db_open_ready();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $sku = trim((string) ($_GET['sku'] ?? ''));
        if ($sku === '') {
            api_respond(422, ['success' => false, 'message' => 'A product SKU is required.', 'request_id' => $requestId]);
        }
        $product = artdon_configurator_product($sku, $pdo);
        if ($product === null) {
            api_respond(404, ['success' => false, 'message' => 'The selected product was not found.', 'request_id' => $requestId]);
        }
        $default = artdon_configurator_configure(
            $sku,
            [],
            max(1, (int) ceil((float) ($product['moq'] ?? 1))),
            $pdo
        );
        api_respond(200, [
            'success' => true,
            'data' => ['product' => $product, 'configuration' => $default],
            'request_id' => $requestId,
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        api_respond(405, ['success' => false, 'message' => 'Method not allowed.', 'request_id' => $requestId]);
    }
    api_rate_limit('product-configure', 120, 60);
    api_verify_csrf();
    $input = api_json_body(65_536);
    $sku = trim((string) ($input['sku'] ?? $input['product_id'] ?? ''));
    $selection = $input['configuration'] ?? $input['selection'] ?? [];
    $quantity = filter_var($input['quantity'] ?? 1, FILTER_VALIDATE_INT);
    if (!is_array($selection) || array_is_list($selection) || $quantity === false) {
        api_respond(422, ['success' => false, 'message' => 'Configuration or quantity is invalid.', 'request_id' => $requestId]);
    }

    $result = artdon_configurator_configure($sku, $selection, (int) $quantity, $pdo);
    if (!$result['valid']) {
        api_respond(($result['code'] ?? '') === 'product_not_found' ? 404 : 422, [
            'success' => false,
            'message' => (string) ($result['message'] ?? 'The selected configuration is not allowed.'),
            'errors' => $result['error_details'] ?? [],
            'data' => ['configuration' => $result],
            'request_id' => $requestId,
        ]);
    }
    api_respond(200, [
        'success' => true,
        'data' => ['configuration' => $result],
        'request_id' => $requestId,
    ]);
} catch (ArtdonDatabaseUnavailable $error) {
    error_log('Configurator database is not ready [' . $requestId . ']: ' . $error->getMessage());
    if (!headers_sent()) {
        header('Retry-After: 5');
    }
    api_respond(503, ['success' => false, 'message' => 'The product configuration service is temporarily unavailable.', 'request_id' => $requestId]);
} catch (PDOException $error) {
    error_log('Configurator database error [' . $requestId . ']: ' . $error->getMessage());
    api_respond(503, ['success' => false, 'message' => 'The product configuration service is temporarily unavailable.', 'request_id' => $requestId]);
} catch (Throwable $error) {
    error_log('Configurator error [' . $requestId . ']: ' . $error->getMessage());
    api_respond(500, ['success' => false, 'message' => 'The product could not be configured.', 'request_id' => $requestId]);
}

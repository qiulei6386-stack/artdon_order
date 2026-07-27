<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/lighting_repository.php';

api_require_method('GET');
$requestId = api_request_id();

try {
    $pdo = artdon_db_open_ready();
    $products = artdon_lighting_products($pdo);
    $profileCount = 0;
    $validatedCount = 0;
    foreach ($products as $product) {
        foreach ((array) ($product['profiles'] ?? []) as $profile) {
            $profileCount++;
            if (!empty($profile['manufacturer_validated'])) {
                $validatedCount++;
            }
        }
    }
    api_respond(200, [
        'success' => true,
        'request_id' => $requestId,
        'count' => count($products),
        'products' => $products,
        'data_status' => $profileCount === 0
            ? 'library_empty'
            : (
                $validatedCount === $profileCount
                    ? 'manufacturer_validated'
                    : ($validatedCount > 0 ? 'mixed_profile_library' : 'synthetic_preliminary_demo')
            ),
        'manufacturer_validated_count' => $validatedCount,
        'disclaimer' => artdon_lighting_disclaimer(),
    ]);
} catch (ArtdonDatabaseUnavailable $error) {
    error_log(sprintf('[lighting-products:%s] Database is not ready: %s', $requestId, $error->getMessage()));
    if (!headers_sent()) {
        header('Retry-After: 5');
    }
    api_respond(503, [
        'success' => false,
        'request_id' => $requestId,
        'message' => 'Lighting simulation products are temporarily unavailable.',
    ]);
} catch (Throwable $error) {
    error_log(sprintf('[lighting-products:%s] %s', $requestId, $error->getMessage()));
    api_respond(500, [
        'success' => false,
        'request_id' => $requestId,
        'message' => 'Lighting simulation products are temporarily unavailable.',
    ]);
}

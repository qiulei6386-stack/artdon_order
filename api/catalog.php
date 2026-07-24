<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

$q = strtolower(trim((string) ($_GET['q'] ?? '')));
$category = trim((string) ($_GET['category'] ?? ''));
$result = array_values(array_filter($products, static function (array $product) use ($q, $category): bool {
    if ($category !== '' && $product['category'] !== $category && $product['stock_group'] !== $category) return false;
    if ($q === '') return true;
    $haystack = strtolower($product['sku'] . ' ' . $product['name'] . ' ' . $product['series'] . ' ' . implode(' ', $product['features']));
    return str_contains($haystack, $q);
}));
echo json_encode(['success' => true, 'count' => count($result), 'products' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

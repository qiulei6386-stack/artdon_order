<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = static function (array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $reply(['ok' => false, 'message' => 'Method not allowed.'], 405);
    $body = (string)file_get_contents('php://input');
    if ($body === '' || strlen($body) > 1048576) $reply(['ok' => false, 'message' => 'Invalid request body.'], 400);
    $timestamp = (string)($_SERVER['HTTP_X_ARTDON_TIMESTAMP'] ?? '');
    $signature = strtolower((string)($_SERVER['HTTP_X_ARTDON_SIGNATURE'] ?? ''));
    $idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    $secret = trim((string)@file_get_contents('/www/secure/artdon_singapore_channel.key'));
    if ($secret === '' || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300
        || !preg_match('/^[a-f0-9]{64}$/', $signature)
        || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) {
        $reply(['ok' => false, 'message' => 'Signature verification failed.'], 401);
    }
    if ($idempotencyKey === '' || strlen($idempotencyKey) > 190) $reply(['ok' => false, 'message' => 'Invalid idempotency key.'], 400);
    $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    if (($payload['event_type'] ?? '') !== 'product.upsert' || !is_array($payload['product'] ?? null)) {
        $reply(['ok' => false, 'message' => 'Unsupported payload.'], 422);
    }
    $product = $payload['product'];
    $sku = trim((string)($product['sku'] ?? ''));
    $name = trim((string)($product['name'] ?? ''));
    if ($sku === '' || $name === '' || strlen($sku) > 120 || strlen($name) > 190) {
        $reply(['ok' => false, 'message' => 'Product SKU or name is invalid.'], 422);
    }

    $pdo = artdon_db_open_ready();
    $duplicate = $pdo->prepare("SELECT entity_id,after_json FROM audit_logs WHERE action='channel.product.upsert' AND request_id=:request_id ORDER BY id DESC LIMIT 1");
    $duplicate->execute([':request_id' => $idempotencyKey]);
    if ($previous = $duplicate->fetch(PDO::FETCH_ASSOC)) {
        $reply(['ok' => true, 'idempotent' => true, 'external_reference' => 'SG-PRODUCT-' . $previous['entity_id'],
            'product' => json_decode((string)$previous['after_json'], true)]);
    }

    $existing = $pdo->prepare('SELECT * FROM products WHERE sku=:sku LIMIT 1');
    $existing->execute([':sku' => $sku]);
    $before = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
    $dimensions = is_array($product['dimensions'] ?? null) ? $product['dimensions'] : [];
    $specs = array_filter([
        'Mounting' => trim((string)($product['lamp_type'] ?? '')),
        'Cut-out' => ($dimensions['opening'] ?? '') !== '' ? (string)$dimensions['opening'] . ' mm' : '',
        'Outer diameter' => ($dimensions['outer_diameter'] ?? '') !== '' ? (string)$dimensions['outer_diameter'] . ' mm' : '',
        'Length' => ($dimensions['length'] ?? '') !== '' ? (string)$dimensions['length'] . ' mm' : '',
        'Width' => ($dimensions['width'] ?? '') !== '' ? (string)$dimensions['width'] . ' mm' : '',
        'Height' => ($dimensions['height'] ?? '') !== '' ? (string)$dimensions['height'] . ' mm' : '',
    ], static fn(string $value): bool => $value !== '');
    $configuration = is_array($product['configuration'] ?? null) ? $product['configuration'] : [];
    $schemes = is_array($configuration['schemes'] ?? null) ? array_values($configuration['schemes']) : [];
    $values = [];
    foreach ($schemes as $index => $scheme) {
        if (!is_array($scheme)) continue;
        $code = trim((string)($scheme['code'] ?? chr(65 + $index)));
        $parts = [];
        foreach ((array)($scheme['selections'] ?? []) as $selection) {
            if (is_array($selection) && trim((string)($selection['value'] ?? '')) !== '') $parts[] = trim((string)$selection['value']);
        }
        $values[] = ['code' => $code, 'label' => trim((string)($scheme['name'] ?? ('Configuration ' . $code))) . ($parts ? ' · ' . implode(' + ', $parts) : ''),
            'sku' => $code, 'default' => !empty($scheme['is_default']), 'components' => $scheme['selections'] ?? []];
    }
    if ($values === []) $values[] = ['code' => 'STD', 'label' => 'Standard configuration', 'sku' => 'STD', 'default' => true];
    $schema = ['price_mode' => 'review', 'sku_order' => ['series', 'configuration'],
        'options' => [['code' => 'configuration', 'label' => 'Material Configuration', 'values' => $values]], 'rules' => []];
    $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $now = gmdate('Y-m-d H:i:s');
    $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $sku) ?: 'product');
    $categoryText = strtolower((string)($product['category'] ?? ''));
    $category = str_contains($categoryText, '嵌入') || str_contains($categoryText, 'recess') ? 'recessed-downlights' : 'products';
    $imagePath = (string)($before['image_path'] ?? '');

    $pdo->beginTransaction();
    $statement = $pdo->prepare('INSERT INTO products (source_system,source_id,source_version,sku,slug,name,series_code,category_slug,subcategory_slug,stock_group,summary,description,specs_json,features_json,image_path,badge,status,order_enabled,sample_enabled,price_mode,base_currency,base_price,default_moq,lead_time_text,stock_quantity,is_new,is_clearance,synced_at,created_at,updated_at)
        VALUES (:source_system,:source_id,:source_version,:sku,:slug,:name,:series,:category,:subcategory,:stock_group,:summary,:description,:specs,:features,:image,:badge,:status,:order_enabled,0,:price_mode,:currency,NULL,:moq,:lead_time,:stock,1,0,:synced_at,:created_at,:updated_at)
        ON CONFLICT(sku) DO UPDATE SET source_system=excluded.source_system,source_id=excluded.source_id,source_version=excluded.source_version,name=excluded.name,series_code=excluded.series_code,category_slug=excluded.category_slug,subcategory_slug=excluded.subcategory_slug,stock_group=excluded.stock_group,summary=excluded.summary,description=excluded.description,specs_json=excluded.specs_json,features_json=excluded.features_json,image_path=CASE WHEN excluded.image_path<>\'\' THEN excluded.image_path ELSE products.image_path END,status=excluded.status,order_enabled=excluded.order_enabled,price_mode=excluded.price_mode,base_currency=excluded.base_currency,base_price=NULL,default_moq=excluded.default_moq,lead_time_text=excluded.lead_time_text,stock_quantity=excluded.stock_quantity,synced_at=excluded.synced_at,updated_at=excluded.updated_at');
    $statement->execute([':source_system' => 'artdon_erp_material_center_v2', ':source_id' => (string)($product['source_id'] ?? ''),
        ':source_version' => (string)($product['source_version'] ?? ''), ':sku' => $sku, ':slug' => $before['slug'] ?? $slug,
        ':name' => $name, ':series' => (string)($product['series'] ?? ''), ':category' => $category, ':subcategory' => 'fixed',
        ':stock_group' => 'made-to-order', ':summary' => $name . ' published from Artdon ERP.',
        ':description' => 'Commercial terms are confirmed by quotation.', ':specs' => json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ':features' => json_encode(['ERP published configuration', 'Commercial review pricing'], JSON_THROW_ON_ERROR),
        ':image' => $imagePath, ':badge' => 'NEW', ':status' => 'active', ':order_enabled' => !empty($product['allow_order']) ? 1 : 0,
        ':price_mode' => 'review', ':currency' => (string)($product['currency'] ?? 'USD'), ':moq' => max(1, (float)($product['moq'] ?? 1)),
        ':lead_time' => empty($product['lead_time_days']) ? 'To be confirmed' : ((int)$product['lead_time_days'] . ' days'),
        ':stock' => max(0, (float)($product['stock'] ?? 0)), ':synced_at' => $now, ':created_at' => $before['created_at'] ?? $now, ':updated_at' => $now]);
    $find = $pdo->prepare('SELECT id,sku,name,source_version,status FROM products WHERE sku=:sku');
    $find->execute([':sku' => $sku]);
    $stored = $find->fetch(PDO::FETCH_ASSOC);
    $productId = (int)$stored['id'];
    $pdo->prepare("UPDATE product_configuration_schemas SET status='archived',updated_at=:now WHERE product_id=:product_id AND status='active'")
        ->execute([':now' => $now, ':product_id' => $productId]);
    $version = (int)$pdo->query('SELECT COALESCE(MAX(version),0)+1 FROM product_configuration_schemas WHERE product_id=' . $productId)->fetchColumn();
    $pdo->prepare('INSERT INTO product_configuration_schemas(product_id,version,source_system,schema_json,checksum,status,published_at,created_at,updated_at) VALUES(:product_id,:version,:source_system,:schema,:checksum,\'active\',:published_at,:created_at,:updated_at)')
        ->execute([':product_id' => $productId, ':version' => $version, ':source_system' => 'artdon_erp_material_center_v2', ':schema' => $schemaJson,
            ':checksum' => hash('sha256', $schemaJson), ':published_at' => $now, ':created_at' => $now, ':updated_at' => $now]);
    $afterJson = json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO audit_logs(actor_type,actor_id,action,entity_type,entity_id,request_id,before_json,after_json,metadata_json,created_at) VALUES(\'channel\',\'guangzhou-commercial-center\',\'channel.product.upsert\',\'product\',:entity_id,:request_id,:before_json,:after_json,:metadata_json,:created_at)')
        ->execute([':entity_id' => (string)$productId, ':request_id' => $idempotencyKey,
            ':before_json' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null, ':after_json' => $afterJson,
            ':metadata_json' => json_encode(['payload_hash' => hash('sha256', $body)], JSON_THROW_ON_ERROR), ':created_at' => $now]);
    $pdo->commit();
    $reply(['ok' => true, 'idempotent' => false, 'external_reference' => 'SG-PRODUCT-' . $productId, 'product' => $stored]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Channel product sync failed: ' . $error->getMessage());
    $reply(['ok' => false, 'message' => 'Product sync failed.'], 500);
}

<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/api/channel_product.php');
$checks = [
    'HMAC verification' => str_contains($source, 'hash_hmac') && str_contains($source, 'hash_equals'),
    'replay window' => str_contains($source, '> 300'),
    'idempotency' => str_contains($source, 'HTTP_IDEMPOTENCY_KEY') && str_contains($source, 'request_id'),
    'product upsert' => str_contains($source, 'ON CONFLICT(sku) DO UPDATE'),
    'configuration versions' => str_contains($source, 'product_configuration_schemas'),
    'audit trail' => str_contains($source, 'channel.product.upsert'),
    'secret outside repository' => str_contains($source, '/www/secure/artdon_singapore_channel.key'),
];
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo 'Channel product contract passed (' . count($checks) . " checks).\n";

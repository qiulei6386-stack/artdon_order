<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/api/channel_product.php');
$checks = [
    'HMAC verification' => str_contains($source, 'hash_hmac') && str_contains($source, 'hash_equals'),
    'replay window' => str_contains($source, '> 300'),
    'idempotency' => str_contains($source, "header('Idempotency-Key')") && str_contains($source, 'request_id'),
    'product upsert' => str_contains($source, 'ON CONFLICT(sku) DO UPDATE'),
    'configuration versions' => str_contains($source, 'product_configuration_schemas'),
    'image allowlist' => str_contains($source, "'artdonlighting.com'") && str_contains($source, 'CURLPROTO_HTTPS'),
    'media synchronization' => str_contains($source, 'cms_media') && str_contains($source, "'media:' . \$publicId"),
    'audit trail' => str_contains($source, 'channel.product.upsert'),
    'private storage secret' => str_contains($source, "/storage/channel_sync_secret"),
];
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo 'Channel product contract passed (' . count($checks) . " checks).\n";

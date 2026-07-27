<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/configurator.php';

$path = sys_get_temp_dir() . '/artdon-configurator-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('APP_DATABASE_PATH=' . $path);

try {
    $bootstrap = artdon_db_bootstrap(true);
    $pdo = $bootstrap['pdo'];

    $default = artdon_configurator_configure('AL1010', [], 20, $pdo);
    if (!$default['valid'] || !str_starts_with($default['configured_model'], 'AL1010-')) {
        throw new RuntimeException('Default product configuration failed.');
    }
    if ($default['calculation']['server_validated'] !== true) {
        throw new RuntimeException('Server validation marker is missing.');
    }

    $invalidBeam = artdon_configurator_configure('AL1010', ['power' => '20W', 'beam' => '15'], 20, $pdo);
    if ($invalidBeam['valid'] || ($invalidBeam['code'] ?? '') !== 'combination_not_allowed') {
        throw new RuntimeException('20W + 15° deny rule was not enforced.');
    }

    $invalidDali = artdon_configurator_configure('AT2020', ['control' => 'DALI-2', 'driver' => 'Lifud'], 20, $pdo);
    if ($invalidDali['valid']) {
        throw new RuntimeException('DALI-2 + Lifud deny rule was not enforced.');
    }

    $belowMoq = artdon_configurator_configure('DR7010', [], 1, $pdo);
    if ($belowMoq['valid'] || !str_contains($belowMoq['message'], 'minimum order quantity')) {
        throw new RuntimeException('MOQ validation was not enforced.');
    }

    $review = artdon_configurator_configure('AC9010', [], 100, $pdo);
    if (!$review['valid'] || $review['price_mode'] !== 'review' || $review['unit_price'] !== null) {
        throw new RuntimeException('Review-price behavior failed.');
    }

    echo "Configurator tests passed.\n";
} finally {
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}

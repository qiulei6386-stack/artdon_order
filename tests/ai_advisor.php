<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/includes/ai_advisor.php';

$catalog = require dirname(__DIR__) . '/config/products.php';
$hotel = artdon_ai_lighting_advice('I need lighting for a hotel lobby, ceiling 5 meters high.', $catalog);
if ($hotel['room_type'] !== 'hotel' || abs($hotel['installation_height_m'] - 5.0) > 0.001) {
    throw new RuntimeException('Hotel lobby extraction failed.');
}
if ($hotel['target_lux']['recommended'] !== 300 || $hotel['shortlist'] === []) {
    throw new RuntimeException('Hotel recommendation failed.');
}

$office = artdon_ai_lighting_advice('办公室照明，需要均匀的工作面照度', $catalog);
if ($office['room_type'] !== 'office' || $office['target_lux']['recommended'] !== 500) {
    throw new RuntimeException('Chinese office recommendation failed.');
}

echo "AI advisor tests passed.\n";

<?php

declare(strict_types=1);

/**
 * Deterministic project-intake assistant.
 *
 * This is intentionally transparent: it turns a short brief into safe starting
 * parameters and a catalog shortlist without claiming to be a final design.
 * A hosted language model can later call this same function as a grounded tool.
 *
 * @param list<array<string,mixed>> $catalog
 * @return array<string,mixed>
 */
function artdon_ai_lighting_advice(string $brief, array $catalog): array
{
    $brief = trim($brief);
    if ($brief === '') {
        throw new InvalidArgumentException('Describe the space or lighting requirement first.');
    }
    if (function_exists('mb_substr')) {
        $brief = mb_substr($brief, 0, 1200, 'UTF-8');
    } else {
        $brief = substr($brief, 0, 1200);
    }
    $needle = function_exists('mb_strtolower')
        ? mb_strtolower($brief, 'UTF-8')
        : strtolower($brief);

    $profiles = [
        'museum' => [
            'terms' => ['museum', '博物馆', 'exhibition', '展览'],
            'target' => [200, 300, 300],
            'mounting' => 'track',
            'category' => 'track-lighting',
            'beam' => '24°–36°',
            'height' => 4.5,
            'notes' => ['Prioritise CRI 90+ and verify conservation limits for sensitive exhibits.'],
        ],
        'gallery' => [
            'terms' => ['gallery', '画廊', 'art space', '艺术空间'],
            'target' => [200, 400, 300],
            'mounting' => 'track',
            'category' => 'track-lighting',
            'beam' => '15°–36°',
            'height' => 3.5,
            'notes' => ['Use adjustable optics and review vertical illuminance on artwork.'],
        ],
        'hotel' => [
            'terms' => ['hotel', 'lobby', '酒店', '大堂', 'hospitality'],
            'target' => [200, 400, 300],
            'mounting' => 'recessed',
            'category' => 'recessed-downlights',
            'beam' => '24°–36°',
            'height' => 4.0,
            'notes' => ['Layer ambient and accent light; this estimate covers direct horizontal illuminance only.'],
        ],
        'restaurant' => [
            'terms' => ['restaurant', 'dining', 'cafe', '餐厅', '咖啡'],
            'target' => [150, 300, 200],
            'mounting' => 'track',
            'category' => 'track-lighting',
            'beam' => '24°–36°',
            'height' => 3.0,
            'notes' => ['Warm CCT and high colour rendering are common starting points.'],
        ],
        'office' => [
            'terms' => ['office', 'workplace', '办公室', '办公'],
            'target' => [300, 500, 500],
            'mounting' => 'linear',
            'category' => 'linear',
            'beam' => 'Wide / low-glare',
            'height' => 3.0,
            'notes' => ['Check task-plane illuminance, glare and screen reflections in the final design.'],
        ],
        'warehouse' => [
            'terms' => ['warehouse', 'logistics', '仓库', '物流'],
            'target' => [200, 300, 250],
            'mounting' => 'surface',
            'category' => 'linear',
            'beam' => '60°+',
            'height' => 6.0,
            'notes' => ['Confirm aisle orientation and vertical illuminance on racking.'],
        ],
        'residential' => [
            'terms' => ['residential', 'home', 'villa', '住宅', '家居', '别墅'],
            'target' => [100, 300, 200],
            'mounting' => 'recessed',
            'category' => 'recessed-downlights',
            'beam' => '36°–60°',
            'height' => 2.8,
            'notes' => ['Use dimming and layered lighting for adaptable scenes.'],
        ],
        'retail' => [
            'terms' => ['retail', 'shop', 'store', 'boutique', '零售', '商店', '门店'],
            'target' => [300, 500, 400],
            'mounting' => 'track',
            'category' => 'track-lighting',
            'beam' => '24°–36°',
            'height' => 3.5,
            'notes' => ['Combine general light with higher-contrast merchandise accents.'],
        ],
    ];

    $roomType = 'retail';
    $matchedTerms = [];
    foreach ($profiles as $candidate => $profile) {
        foreach ($profile['terms'] as $term) {
            if (str_contains($needle, strtolower($term))) {
                $roomType = $candidate;
                $matchedTerms[] = $term;
                break 2;
            }
        }
    }
    $profile = $profiles[$roomType];

    $height = (float) $profile['height'];
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:m(?:eters?)?|metres?|米)(?:\s|$|[,.，。])/ui', $brief, $matches) === 1) {
        $height = min(30.0, max(1.8, (float) $matches[1]));
    }

    $shortlist = [];
    foreach ($catalog as $product) {
        if (($product['status'] ?? 'active') !== 'active') {
            continue;
        }
        $category = (string) ($product['category'] ?? $product['category_slug'] ?? '');
        if ($category !== $profile['category']) {
            continue;
        }
        $shortlist[] = [
            'sku' => (string) ($product['sku'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'series' => (string) ($product['series'] ?? ''),
            'category' => $category,
            'reason' => 'Catalog category matches the recommended mounting and application starting point.',
            'configure_url' => '/configure/' . rawurlencode((string) ($product['sku'] ?? '')),
            'simulation_url' => '/lighting-simulation?product=' . rawurlencode((string) ($product['sku'] ?? '')),
        ];
        if (count($shortlist) >= 3) {
            break;
        }
    }
    if ($shortlist === []) {
        foreach (array_slice($catalog, 0, 3) as $product) {
            $shortlist[] = [
                'sku' => (string) ($product['sku'] ?? ''),
                'name' => (string) ($product['name'] ?? ''),
                'series' => (string) ($product['series'] ?? ''),
                'category' => (string) ($product['category'] ?? ''),
                'reason' => 'Catalog fallback; technical compatibility requires review.',
                'configure_url' => '/configure/' . rawurlencode((string) ($product['sku'] ?? '')),
                'simulation_url' => '/lighting-simulation?product=' . rawurlencode((string) ($product['sku'] ?? '')),
            ];
        }
    }

    return [
        'engine' => 'grounded-rules-v1',
        'brief' => $brief,
        'room_type' => $roomType,
        'target_lux' => [
            'minimum' => (int) $profile['target'][0],
            'maximum' => (int) $profile['target'][1],
            'recommended' => (int) $profile['target'][2],
        ],
        'mounting_type' => $profile['mounting'],
        'installation_height_m' => $height,
        'product_category' => $profile['category'],
        'beam_angle' => $profile['beam'],
        'quantity_guidance' => 'Run the selected product IES profile with actual room dimensions to calculate a preliminary quantity.',
        'shortlist' => $shortlist,
        'notes' => $profile['notes'],
        'confidence' => $matchedTerms === [] ? 'low' : 'medium',
        'matched_terms' => $matchedTerms,
        'disclaimer' => 'Starting recommendation only. Verify the final design using validated product photometry and a qualified lighting professional.',
    ];
}

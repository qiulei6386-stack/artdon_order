<?php

declare(strict_types=1);

if (!$product) {
    return;
}

require_once __DIR__ . '/../includes/configurator.php';

$schema = product_configuration($product);
$minimumQuantity = max(1, (int) ceil((float) ($product['moq'] ?? 1)));
$initialConfiguration = [];
$initialModel = (string) $product['sku'];
$initialPrice = is_numeric($product['price'] ?? null) ? (float) $product['price'] : null;
$initialPriceMode = (string) ($schema['price_mode'] ?? 'review');
$initialAvailability = [];
try {
    if ($databaseAvailable) {
        $serverConfiguration = artdon_configurator_configure(
            (string) $product['sku'],
            [],
            $minimumQuantity
        );
        if (!empty($serverConfiguration['valid'])) {
            $schema = (array) $serverConfiguration['schema'];
            $initialConfiguration = (array) $serverConfiguration['configuration'];
            $initialModel = (string) $serverConfiguration['configured_model'];
            $initialPrice = $serverConfiguration['unit_price'] === null
                ? null
                : (float) $serverConfiguration['unit_price'];
            $initialPriceMode = (string) $serverConfiguration['price_mode'];
            $initialAvailability = (array) $serverConfiguration['availability'];
        }
    }
} catch (Throwable $configurationError) {
    error_log('Configurator template fallback: ' . $configurationError->getMessage());
}

foreach ((array) ($schema['options'] ?? []) as $option) {
    if (!is_array($option)) {
        continue;
    }
    $code = (string) ($option['code'] ?? '');
    if ($code === '' || isset($initialConfiguration[$code])) {
        continue;
    }
    $values = (array) ($option['values'] ?? []);
    $selected = null;
    foreach ($values as $value) {
        if (is_array($value) && !empty($value['default'])) {
            $selected = (string) ($value['code'] ?? '');
            break;
        }
    }
    if ($selected === null && isset($values[0]) && is_array($values[0])) {
        $selected = (string) ($values[0]['code'] ?? '');
    }
    $initialConfiguration[$code] = $selected ?? '';
}

$cartPayload = product_cart_payload($product);
$cartPayload['configuration_schema'] = $schema;
$cartPayloadJson = json_encode(
    $cartPayload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
$related = array_values(array_filter(
    $products,
    fn (array $candidate): bool =>
        $candidate['sku'] !== $product['sku']
        && ($candidate['category'] === $product['category'] || $candidate['subcategory'] === $product['subcategory'])
));
if (count($related) < 4) {
    $related = array_values(array_filter($products, fn (array $candidate): bool => $candidate['sku'] !== $product['sku']));
}
?>
<section class="product-page">
<div class="container">
    <?= breadcrumb(['title' => $product['sku'] . ' ' . $product['name'], 'section' => 'products', 'path' => 'product/' . $product['sku']]) ?>
    <div
        class="product-detail-grid"
        data-product-configurator
        data-base-price="<?= e((string) ($product['price'] ?? '')) ?>"
        data-base-sku="<?= e($product['sku']) ?>"
        data-product="<?= e((string) $cartPayloadJson) ?>"
    >
        <div class="product-gallery">
            <div class="main-product-image"><span class="product-badge"><?= e($product['badge']) ?></span><img data-main-product-image src="<?= asset('img/' . $product['image']) ?>" alt="<?= e($product['name']) ?>"></div>
            <div class="thumbnail-row" data-product-thumbnails><button class="is-active"><img src="<?= asset('img/' . $product['image']) ?>" alt=""></button><button><img src="<?= asset('img/product-detail-side.svg') ?>" alt=""></button><button><img src="<?= asset('img/product-detail-back.svg') ?>" alt=""></button><button><img src="<?= asset('img/dimension.svg') ?>" alt=""></button></div>
            <div class="gallery-document-links"><a href="<?= url('resources/ies') ?>"><?= icon('download') ?> IES file</a><a href="<?= url('resources/datasheet') ?>"><?= icon('file') ?> Datasheet</a><a href="<?= url('resources/installation') ?>"><?= icon('file') ?> Installation</a><a href="<?= url('resources/bim') ?>"><?= icon('download') ?> BIM / CAD</a></div>
        </div>
        <div class="product-summary">
            <span class="product-series"><?= e($product['series']) ?> SERIES</span>
            <h1><?= e($product['sku']) ?> <?= e($product['name']) ?></h1>
            <p class="product-lead"><?= e($product['summary']) ?></p>
            <div class="feature-chips feature-chips-large"><?php foreach ($product['features'] as $feature): ?><span><?= e($feature) ?></span><?php endforeach; ?></div>
            <div class="availability-card"><div><span class="stock-dot high"></span><strong><?= number_format((float) $product['stock']) ?> pcs <?= catalog_is_demo() ? 'in demo data' : 'available' ?></strong><small><?= catalog_is_demo() ? 'Not live inventory' : 'Current catalog inventory' ?></small></div><div><?= icon('clock') ?><span><strong><?= e($product['lead_time']) ?></strong><small><?= catalog_is_demo() ? 'Indicative only' : 'Typical dispatch' ?></small></span></div></div>

            <div class="configuration-panel">
                <div class="configuration-title"><div><span class="eyebrow">Server-validated configurator</span><h2>Select a compatible combination</h2></div><button class="text-button" type="button" data-reset-config>Reset</button></div>
                <?php foreach ((array) ($schema['options'] ?? []) as $option):
                    if (!is_array($option)) continue;
                    $code = (string) ($option['code'] ?? '');
                    if ($code === '') continue;
                ?>
                    <label class="config-field">
                        <span><?= e((string) ($option['label'] ?? $code)) ?></span>
                        <select name="<?= e($code) ?>" data-config-option>
                            <?php foreach ((array) ($option['values'] ?? []) as $value):
                                if (!is_array($value)) continue;
                                $valueCode = (string) ($value['code'] ?? '');
                                $selected = ($initialConfiguration[$code] ?? '') === $valueCode;
                                $disabled = isset($initialAvailability[$code][$valueCode]) && !$initialAvailability[$code][$valueCode];
                            ?>
                                <option value="<?= e($valueCode) ?>" <?= $selected ? 'selected' : '' ?> <?= $disabled ? 'disabled' : '' ?>><?= e((string) ($value['label'] ?? $valueCode)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
                <p class="configuration-rule-note" data-config-rule-note aria-live="polite" hidden></p>
            </div>

            <div class="selection-summary">
                <div><span>Configured model</span><strong data-config-sku><?= e($initialModel) ?></strong><small data-config-validation>Validated against product rules</small></div>
                <div class="selection-price">
                    <span>Estimated unit price</span>
                    <strong data-config-price-wrap>
                        <?php if ($initialPrice === null || $initialPriceMode === 'review'): ?>
                            Commercial review
                        <?php else: ?>
                            <?= e((string) ($site['currency'] ?? 'USD')) ?> <b data-config-price><?= number_format($initialPrice, 2) ?></b>
                        <?php endif; ?>
                    </strong>
                    <small>Price, stock and lead time are recalculated on the server before submission.</small>
                </div>
                <label class="quantity-field"><span>Quantity</span><div><button type="button" data-qty-minus><?= icon('minus') ?></button><input type="number" min="<?= $minimumQuantity ?>" max="100000" value="<?= $minimumQuantity ?>" data-config-qty><button type="button" data-qty-plus><?= icon('plus') ?></button></div><small>MOQ: <?= $minimumQuantity ?> pcs</small></label>
                <div class="selection-actions selection-actions-three">
                    <button type="button" class="button button-dark button-large" data-add-configured-cart>Add to Project Cart <?= icon('cart') ?></button>
                    <a class="button button-blue button-large" href="<?= url('lighting-simulation?product=' . rawurlencode($product['sku'])) ?>" data-simulate-configured>Simulate This Product <?= icon('ai') ?></a>
                    <button type="button" class="button button-outline button-large" data-rfq-open data-configured-rfq>Request quote <?= icon('quote') ?></button>
                </div>
                <a class="sample-link" href="<?= url('procurement/sample-order') ?>"><?= icon('sample') ?> Need only a sample? Start a sample order</a>
            </div>
        </div>
    </div>
</div>
</section>

<section class="product-information section">
<div class="container">
    <div class="tabs" data-tabs><button class="is-active" data-tab="overview">Overview</button><button data-tab="specifications">Specifications</button><button data-tab="photometrics">Photometrics</button><button data-tab="downloads">Downloads</button><button data-tab="accessories">Accessories</button><button data-tab="applications">Applications</button></div>
    <div class="tab-panel is-active" data-tab-panel="overview"><div class="overview-grid"><div><span class="eyebrow">Product overview</span><h2>Designed for professional commercial projects</h2><p><?= e($product['summary']) ?> Product options, compatibility, model generation, MOQ and pricing are validated by one server-side rule engine.</p><ul class="check-list"><?php foreach ($product['features'] as $feature): ?><li><?= icon('check') ?><?= e($feature) ?></li><?php endforeach; ?></ul></div><div class="overview-scene"><img src="<?= asset('img/scene-retail.svg') ?>" alt="Retail lighting application"></div></div></div>
    <div class="tab-panel" data-tab-panel="specifications"><div class="spec-table"><?php foreach ($product['specs'] as $key => $value): ?><div><span><?= e($key) ?></span><strong><?= e($value) ?></strong></div><?php endforeach; ?></div></div>
    <div class="tab-panel" data-tab-panel="photometrics"><div class="resource-empty"><img src="<?= asset('img/photometric.svg') ?>" alt="Photometric curve"><div><h3>Photometric simulation by optical configuration</h3><p>Use the matching IES profile to estimate direct illuminance, quantity and spacing.</p><a class="button button-dark" href="<?= url('lighting-simulation?product=' . rawurlencode($product['sku'])) ?>">Run Lighting Simulation</a></div></div></div>
    <div class="tab-panel" data-tab-panel="downloads"><div class="download-list"><?php foreach (['Datasheet PDF','IES photometric file','Installation guide','BIM model','CAD drawing','Certificate pack'] as $file): ?><a href="#" data-demo-download><?= icon('file') ?><span><strong><?= e($file) ?></strong><small><?= e($product['sku']) ?> · Demo placeholder, file not connected</small></span><?= icon('download') ?></a><?php endforeach; ?></div></div>
    <div class="tab-panel" data-tab-panel="accessories"><div class="product-grid product-grid-three"><?php foreach (array_slice(array_filter($products, fn ($candidate) => in_array($candidate['category'], ['accessories','driver','track-rail'], true)), 0, 3) as $candidate): ?><?= product_card($candidate, true) ?><?php endforeach; ?></div></div>
    <div class="tab-panel" data-tab-panel="applications"><div class="scene-grid scene-grid-three"><?php foreach ([['retail','Retail'],['hospitality','Hospitality'],['office','Office']] as [$slug,$title]): ?><a class="scene-card" href="<?= url('solutions/' . $slug) ?>"><img src="<?= asset('img/' . scene_image_for($slug)) ?>" alt=""><div class="scene-overlay"><span>Application</span><h3><?= e($title) ?></h3><strong>View solution <?= icon('arrow') ?></strong></div></a><?php endforeach; ?></div></div>
</div>
</section>
<section class="section related-products"><div class="container"><?= section_heading('Continue sourcing', 'Related products and components', 'Compare compatible alternatives, drivers and accessories.') ?><div class="product-grid product-grid-four"><?php foreach (array_slice($related, 0, 4) as $candidate): ?><?= product_card($candidate, true) ?><?php endforeach; ?></div></div></section>

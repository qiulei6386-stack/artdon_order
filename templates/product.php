<?php declare(strict_types=1);
if(!$product){return;}
$related=array_values(array_filter($products,fn($p)=>$p['sku']!==$product['sku'] && ($p['category']===$product['category'] || $p['subcategory']===$product['subcategory'])));
if(count($related)<4){$related=array_values(array_filter($products,fn($p)=>$p['sku']!==$product['sku']));}
$productPayload=json_encode(['sku'=>$product['sku'],'name'=>$product['name'],'price'=>$product['price'],'image'=>$product['image']], JSON_UNESCAPED_SLASHES);
$requiresReview=($product['price_mode'] ?? 'review') === 'review' || !is_numeric($product['price'] ?? null);
$configurationOptions=is_array($product['configuration_schema']['options'] ?? null) ? $product['configuration_schema']['options'] : [];
$configurationValues=[];
foreach($configurationOptions as $option){
    if(is_array($option) && ($option['code'] ?? '')==='configuration' && is_array($option['values'] ?? null)){
        $configurationValues=array_values($option['values']);
        break;
    }
}
?>
<section class="product-page product-overview-page">
<div class="container">
    <?= breadcrumb(['title'=>$product['sku'].' '.$product['name'],'section'=>'products','path'=>'product/'.$product['sku']]) ?>
    <div class="product-detail-grid product-overview-grid">
        <div class="product-gallery">
            <div class="main-product-image"><span class="product-badge"><?= e($product['badge']) ?></span><img data-main-product-image src="<?= product_image_url($product) ?>" alt="<?= e($product['name']) ?>"></div>
            <div class="thumbnail-row" data-product-thumbnails><button class="is-active"><img src="<?= product_image_url($product) ?>" alt=""></button><button><img src="<?= asset('img/product-detail-side.svg') ?>" alt=""></button><button><img src="<?= asset('img/product-detail-back.svg') ?>" alt=""></button><button><img src="<?= asset('img/dimension.svg') ?>" alt=""></button></div>
            <div class="gallery-document-links"><a href="<?= url('resources/ies') ?>"><?= icon('download') ?> IES file</a><a href="<?= url('resources/datasheet') ?>"><?= icon('file') ?> Datasheet</a><a href="<?= url('resources/installation') ?>"><?= icon('file') ?> Installation</a><a href="<?= url('resources/bim') ?>"><?= icon('download') ?> BIM / CAD</a></div>
        </div>
        <div class="product-summary product-overview-summary">
            <span class="product-series"><?= e($product['series']) ?> SERIES</span>
            <h1><?= e($product['sku']) ?> <?= e($product['name']) ?></h1>
            <p class="product-lead"><?= e($product['summary']) ?></p>
            <div class="feature-chips feature-chips-large"><?php foreach($product['features'] as $feature): ?><span><?= e($feature) ?></span><?php endforeach; ?></div>
            <?php if($configurationValues): ?>
                <div class="product-key-specs"><div class="configuration-title"><div><span class="eyebrow">Published configurations</span><h2>Available material combinations</h2></div><a class="text-link" href="<?= url('configure/'.$product['sku']) ?>">Select configuration <?= icon('arrow') ?></a></div>
                    <div class="mini-spec-grid"><?php foreach($configurationValues as $value): ?><div><span>Configuration <?= e((string)($value['code'] ?? '')) ?></span><strong><?= e((string)($value['label'] ?? $value['code'] ?? '')) ?></strong></div><?php endforeach; ?></div>
                </div>
            <?php endif; ?>
            <div class="availability-card"><div><span class="stock-dot high"></span><strong><?= number_format($product['stock']) ?> pcs <?= catalog_is_demo() ? 'in demo data' : 'available' ?></strong><small><?= catalog_is_demo() ? 'Not live inventory' : 'Current inventory' ?></small></div><div><?= icon('clock') ?><span><strong><?= e($product['lead_time']) ?></strong><small><?= catalog_is_demo() ? 'Indicative only' : 'Typical dispatch' ?></small></span></div></div>

            <div class="product-action-card">
                <div class="product-price-block"><span><?= $requiresReview ? 'Commercial pricing' : (catalog_is_demo() ? 'Demo price estimate' : 'Starting price') ?></span><strong><?= $requiresReview ? 'Request quote' : 'From USD ' . number_format((float)$product['price'],2) ?></strong><small><?= catalog_is_demo() ? 'Demonstration data only. ' : '' ?>Final price is confirmed after configuration, quantity and commercial review.</small></div>
                <div class="product-procurement-facts"><div><span>MOQ</span><strong><?= e((string)$product['moq']) ?> pcs</strong></div><div><span>Sample</span><strong>Available</strong></div><div><span>Configuration</span><strong>Required</strong></div></div>
                <div class="product-primary-actions"><a class="button button-blue button-large" href="<?= url('configure/'.$product['sku']) ?>">Configure &amp; order <?= icon('arrow') ?></a><a class="button button-dark button-large" href="<?= url('lighting-simulation?product='.rawurlencode($product['sku'])) ?>">Lighting Simulation <?= icon('ai') ?></a><button type="button" class="button button-outline button-large" data-rfq-open data-product='<?= e((string)$productPayload) ?>'>Request quote <?= icon('quote') ?></button></div>
                <a class="sample-link" href="<?= url('procurement/sample-order') ?>"><?= icon('sample') ?> Order a sample before the project quantity</a>
            </div>

            <div class="product-key-specs"><div class="configuration-title"><div><span class="eyebrow">Key specifications</span><h2>Selection starting point</h2></div><a class="text-link" href="#specifications">All specifications <?= icon('arrow') ?></a></div><div class="mini-spec-grid"><?php foreach(array_slice($product['specs'],0,6,true) as $key=>$value): ?><div><span><?= e($key) ?></span><strong><?= e($value) ?></strong></div><?php endforeach; ?></div></div>
        </div>
    </div>
</div>
</section>
<section class="product-information section" id="specifications">
<div class="container">
    <div class="tabs" data-tabs><button class="is-active" data-tab="overview">Overview</button><button data-tab="specifications">Specifications</button><button data-tab="photometrics">Photometrics</button><button data-tab="downloads">Downloads</button><button data-tab="accessories">Accessories</button><button data-tab="applications">Applications</button></div>
    <div class="tab-panel is-active" data-tab-panel="overview"><div class="overview-grid"><div><span class="eyebrow">Product overview</span><h2>Designed for professional commercial projects</h2><p><?= e($product['summary']) ?> Product pages focus on selection evidence, while the dedicated configurator controls compatible options and procurement actions.</p><ul class="check-list"><?php foreach($product['features'] as $feature): ?><li><?= icon('check') ?><?= e($feature) ?></li><?php endforeach; ?></ul><a class="button button-dark" href="<?= url('configure/'.$product['sku']) ?>">Configure this product</a></div><div class="overview-scene"><img src="<?= asset('img/scene-retail.svg') ?>" alt="Retail lighting application"></div></div></div>
    <div class="tab-panel" data-tab-panel="specifications"><div class="spec-table"><?php foreach($product['specs'] as $key=>$value): ?><div><span><?= e($key) ?></span><strong><?= e($value) ?></strong></div><?php endforeach; ?></div></div>
    <div class="tab-panel" data-tab-panel="photometrics"><div class="resource-empty"><img src="<?= asset('img/photometric.svg') ?>" alt="Photometric curve"><div><h3>Photometric files by configuration</h3><p>Select power and beam angle in the configurator to retrieve the matching IES/LDT file.</p><a class="button button-dark" href="<?= url('resources/ies') ?>">Open IES library</a></div></div></div>
    <div class="tab-panel" data-tab-panel="downloads"><div class="download-list"><?php foreach(['Datasheet PDF','IES photometric file','Installation guide','BIM model','CAD drawing','Certificate pack'] as $file): ?><a href="#" data-demo-download><?= icon('file') ?><span><strong><?= e($file) ?></strong><small><?= e($product['sku']) ?> · Demo placeholder, file not connected</small></span><?= icon('download') ?></a><?php endforeach; ?></div></div>
    <div class="tab-panel" data-tab-panel="accessories"><div class="product-grid product-grid-three"><?php foreach(array_slice(array_filter($products,fn($p)=>in_array($p['category'],['accessories','driver','track-rail'],true)),0,3) as $p): ?><?= product_card($p,true) ?><?php endforeach; ?></div></div>
    <div class="tab-panel" data-tab-panel="applications"><div class="scene-grid scene-grid-three"><?php foreach([['retail','Retail'],['hospitality','Hospitality'],['office','Office']] as [$slug,$title]): ?><a class="scene-card" href="<?= url('solutions/'.$slug) ?>"><img src="<?= asset('img/'.scene_image_for($slug)) ?>" alt=""><div class="scene-overlay"><span>Application</span><h3><?= e($title) ?></h3><strong>View solution <?= icon('arrow') ?></strong></div></a><?php endforeach; ?></div></div>
</div>
</section>
<section class="section related-products"><div class="container"><?= section_heading('Continue sourcing','Related products and components','Compare compatible alternatives, drivers and accessories.') ?><div class="product-grid product-grid-four"><?php foreach(array_slice($related,0,4) as $p): ?><?= product_card($p,true) ?><?php endforeach; ?></div></div></section>

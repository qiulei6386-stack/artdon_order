<?php declare(strict_types=1);
if(!$product){return;}
$related=array_values(array_filter($products,fn($p)=>$p['sku']!==$product['sku'] && ($p['category']===$product['category'] || $p['subcategory']===$product['subcategory'])));
if(count($related)<4){$related=array_values(array_filter($products,fn($p)=>$p['sku']!==$product['sku']));}
?>
<section class="product-page">
<div class="container">
    <?= breadcrumb(['title'=>$product['sku'].' '.$product['name'],'section'=>'products','path'=>'product/'.$product['sku']]) ?>
    <div class="product-detail-grid" data-product-configurator data-base-price="<?= e((string)$product['price']) ?>" data-base-sku="<?= e($product['sku']) ?>">
        <div class="product-gallery">
            <div class="main-product-image"><span class="product-badge"><?= e($product['badge']) ?></span><img data-main-product-image src="<?= asset('img/'.$product['image']) ?>" alt="<?= e($product['name']) ?>"></div>
            <div class="thumbnail-row" data-product-thumbnails><button class="is-active"><img src="<?= asset('img/'.$product['image']) ?>" alt=""></button><button><img src="<?= asset('img/product-detail-side.svg') ?>" alt=""></button><button><img src="<?= asset('img/product-detail-back.svg') ?>" alt=""></button><button><img src="<?= asset('img/dimension.svg') ?>" alt=""></button></div>
            <div class="gallery-document-links"><a href="<?= url('resources/ies') ?>"><?= icon('download') ?> IES file</a><a href="<?= url('resources/datasheet') ?>"><?= icon('file') ?> Datasheet</a><a href="<?= url('resources/installation') ?>"><?= icon('file') ?> Installation</a><a href="<?= url('resources/bim') ?>"><?= icon('download') ?> BIM / CAD</a></div>
        </div>
        <div class="product-summary">
            <span class="product-series"><?= e($product['series']) ?> SERIES</span>
            <h1><?= e($product['sku']) ?> <?= e($product['name']) ?></h1>
            <p class="product-lead"><?= e($product['summary']) ?></p>
            <div class="feature-chips feature-chips-large"><?php foreach($product['features'] as $feature): ?><span><?= e($feature) ?></span><?php endforeach; ?></div>
            <div class="availability-card"><div><span class="stock-dot high"></span><strong><?= number_format($product['stock']) ?> pcs available</strong><small>Demo inventory</small></div><div><?= icon('clock') ?><span><strong><?= e($product['lead_time']) ?></strong><small>Typical dispatch</small></span></div></div>
            <div class="configuration-panel">
                <div class="configuration-title"><div><span class="eyebrow">Configure product</span><h2>Select a valid combination</h2></div><button class="text-button" type="button" data-reset-config>Reset</button></div>
                <?php
                $options=[
                    'Installation'=>['Recessed','Surface'],
                    'Size / cut-out'=>['Ø75mm','Ø85mm','Ø95mm'],
                    'Power'=>['10W','15W','20W'],
                    'CCT'=>['2700K','3000K','4000K','5000K'],
                    'CRI'=>['Ra90','Ra95'],
                    'Beam angle'=>['15°','24°','36°','60°'],
                    'Finish'=>['White','Black'],
                    'Driver'=>['Tridonic','Philips','Lifud'],
                    'Dimming'=>['On / Off','0–10V','DALI-2'],
                    'Accessory'=>['None','Honeycomb','Anti-glare ring'],
                ]; foreach($options as $label=>$values): $name=strtolower(preg_replace('/[^a-zA-Z0-9]+/','_',$label)); ?>
                    <label class="config-field"><span><?= e($label) ?></span><select name="<?= e($name) ?>" data-config-option><?php foreach($values as $idx=>$value): ?><option value="<?= e($value) ?>" <?= $idx===0?'selected':'' ?>><?= e($value) ?></option><?php endforeach; ?></select></label>
                <?php endforeach; ?>
                <p class="configuration-rule-note" data-config-rule-note aria-live="polite" hidden></p>
            </div>
            <div class="selection-summary">
                <div><span>Configured model</span><strong data-config-sku><?= e($product['sku']) ?>-10W-3000K-24-BK-ON</strong></div>
                <div class="selection-price"><span>Estimated unit price</span><strong>USD <b data-config-price><?= number_format($product['price'],2) ?></b></strong><small>Final price depends on quantity and review.</small></div>
                <label class="quantity-field"><span>Quantity</span><div><button type="button" data-qty-minus><?= icon('minus') ?></button><input type="number" min="1" value="<?= e((string)$product['moq']) ?>" data-config-qty><button type="button" data-qty-plus><?= icon('plus') ?></button></div><small>MOQ: <?= e((string)$product['moq']) ?> pcs</small></label>
                <div class="selection-actions"><button type="button" class="button button-dark button-large" data-add-configured-cart data-product='<?= e(json_encode(['sku'=>$product['sku'],'name'=>$product['name'],'price'=>$product['price'],'image'=>$product['image']], JSON_UNESCAPED_SLASHES)) ?>'>Add to order <?= icon('cart') ?></button><button type="button" class="button button-outline button-large" data-rfq-open data-configured-rfq>Request quote <?= icon('quote') ?></button></div>
                <a class="sample-link" href="<?= url('procurement/sample-order') ?>"><?= icon('sample') ?> Need only a sample? Start a sample order</a>
            </div>
        </div>
    </div>
</div>
</section>
<section class="product-information section">
<div class="container">
    <div class="tabs" data-tabs><button class="is-active" data-tab="overview">Overview</button><button data-tab="specifications">Specifications</button><button data-tab="photometrics">Photometrics</button><button data-tab="downloads">Downloads</button><button data-tab="accessories">Accessories</button><button data-tab="applications">Applications</button></div>
    <div class="tab-panel is-active" data-tab-panel="overview"><div class="overview-grid"><div><span class="eyebrow">Product overview</span><h2>Designed for professional commercial projects</h2><p><?= e($product['summary']) ?> The final platform should load product data, valid combinations, price rules and stock from one central product source.</p><ul class="check-list"><?php foreach($product['features'] as $feature): ?><li><?= icon('check') ?><?= e($feature) ?></li><?php endforeach; ?></ul></div><div class="overview-scene"><img src="<?= asset('img/scene-retail.svg') ?>" alt="Retail lighting application"></div></div></div>
    <div class="tab-panel" data-tab-panel="specifications"><div class="spec-table"><?php foreach($product['specs'] as $key=>$value): ?><div><span><?= e($key) ?></span><strong><?= e($value) ?></strong></div><?php endforeach; ?></div></div>
    <div class="tab-panel" data-tab-panel="photometrics"><div class="resource-empty"><img src="<?= asset('img/photometric.svg') ?>" alt="Photometric curve"><div><h3>Photometric files by configuration</h3><p>Select power and beam angle to download the matching IES/LDT file.</p><a class="button button-dark" href="<?= url('resources/ies') ?>">Open IES library</a></div></div></div>
    <div class="tab-panel" data-tab-panel="downloads"><div class="download-list"><?php foreach(['Datasheet PDF','IES photometric file','Installation guide','BIM model','CAD drawing','Certificate pack'] as $file): ?><a href="#" data-demo-download><?= icon('file') ?><span><strong><?= e($file) ?></strong><small><?= e($product['sku']) ?> · Latest revision</small></span><?= icon('download') ?></a><?php endforeach; ?></div></div>
    <div class="tab-panel" data-tab-panel="accessories"><div class="product-grid product-grid-three"><?php foreach(array_slice(array_filter($products,fn($p)=>in_array($p['category'],['accessories','driver','track-rail'],true)),0,3) as $p): ?><?= product_card($p,true) ?><?php endforeach; ?></div></div>
    <div class="tab-panel" data-tab-panel="applications"><div class="scene-grid scene-grid-three"><?php foreach([['retail','Retail'],['hospitality','Hospitality'],['office','Office']] as [$slug,$title]): ?><a class="scene-card" href="<?= url('solutions/'.$slug) ?>"><img src="<?= asset('img/'.scene_image_for($slug)) ?>" alt=""><div class="scene-overlay"><span>Application</span><h3><?= e($title) ?></h3><strong>View solution <?= icon('arrow') ?></strong></div></a><?php endforeach; ?></div></div>
</div>
</section>
<section class="section related-products"><div class="container"><?= section_heading('Continue sourcing','Related products and components','Compare compatible alternatives, drivers and accessories.') ?><div class="product-grid product-grid-four"><?php foreach(array_slice($related,0,4) as $p): ?><?= product_card($p,true) ?><?php endforeach; ?></div></div></section>

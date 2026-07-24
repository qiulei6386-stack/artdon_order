<?php declare(strict_types=1);
$listProducts=products_for_page($page);
$q=trim((string)($_GET['q'] ?? ''));
if($q!==''){
    $needle=strtolower($q);
    $listProducts=array_values(array_filter($listProducts,fn($p)=>str_contains(strtolower($p['sku'].' '.$p['name'].' '.$p['series'].' '.implode(' ',$p['features']).' '.implode(' ',$p['specs'])),$needle)));
}
$sectionData=$sections[$page['section']] ?? [];
?>
<?= page_intro($page, '<div class="page-stat"><strong>'.count($listProducts).'</strong><span>matching products</span></div><a class="button button-dark" href="'.url('procurement/quick-rfq').'">Request project pricing</a>') ?>
<?php if (($page['is_root'] ?? false) && !empty($sectionData['items'])): ?>
<section class="section section-tight"><div class="container"><?= child_cards($page['section'],$sectionData) ?></div></section>
<?php endif; ?>
<section class="section listing-section">
    <div class="container listing-layout">
        <aside class="filter-panel" data-filter-panel>
            <div class="filter-panel-head"><h2>Filter products</h2><div class="filter-head-actions"><button type="button" class="text-button" data-filter-reset>Reset</button><button type="button" class="icon-button filter-close" data-filter-close><?= icon('close') ?></button></div></div>
            <label class="filter-search"><?= icon('search') ?><input type="search" placeholder="Model or keyword" value="<?= e($q) ?>" data-card-search></label>
            <div class="filter-group"><h3>Availability</h3><label><input type="checkbox" data-list-filter="availability" value="ready"> Ready stock</label><label><input type="checkbox" data-list-filter="availability" value="new"> New arrival</label><label><input type="checkbox" data-list-filter="availability" value="clearance"> Clearance</label></div>
            <div class="filter-group"><h3>Power</h3><label><input type="checkbox" data-list-filter="power" value="under-10"> Under 10W</label><label><input type="checkbox" data-list-filter="power" value="10-20"> 10–20W</label><label><input type="checkbox" data-list-filter="power" value="20-40"> 20–40W</label><label><input type="checkbox" data-list-filter="power" value="above-40"> Above 40W</label></div>
            <div class="filter-group"><h3>Dimming</h3><label><input type="checkbox" data-list-filter="dimming" value="on-off"> On / Off</label><label><input type="checkbox" data-list-filter="dimming" value="0-10v"> 0–10V</label><label><input type="checkbox" data-list-filter="dimming" value="phase-cut"> Phase-cut</label><label><input type="checkbox" data-list-filter="dimming" value="dali-2"> DALI-2</label></div>
            <div class="filter-group"><h3>Optical</h3><label><input type="checkbox" data-list-filter="optical" value="15"> 15°</label><label><input type="checkbox" data-list-filter="optical" value="24"> 24°</label><label><input type="checkbox" data-list-filter="optical" value="36"> 36°</label><label><input type="checkbox" data-list-filter="optical" value="60"> 60°</label></div>
            <a class="filter-rfq" href="<?= url('procurement/project-package') ?>"><span class="eyebrow eyebrow-light">Have a schedule?</span><strong>Upload BOQ instead</strong><small>We will match products and accessories.</small><?= icon('arrow') ?></a>
        </aside>
        <div class="listing-main">
            <div class="listing-toolbar"><button class="button button-outline mobile-filter-button" type="button" data-filter-open><?= icon('filter') ?> Filters</button><div><strong><span data-visible-count><?= count($listProducts) ?></span> products</strong><?php if($q!==''): ?><span>for “<?= e($q) ?>”</span><?php endif; ?></div><label>Sort<select data-product-sort><option value="recommended">Recommended</option><option value="stock-desc">Stock: high to low</option><option value="price-asc">Price: low to high</option><option value="newest">Newest</option></select></label></div>
            <?php if ($listProducts): ?>
                <div class="product-grid product-grid-three" data-filter-grid><?php foreach($listProducts as $p): ?><?= product_card($p,true) ?><?php endforeach; ?></div>
            <?php else: ?>
                <div class="empty-state"><?= icon('search') ?><h2>No exact product found</h2><p>Submit the requirement and we will check compatible, custom or upcoming products.</p><a class="button button-dark" href="<?= url('procurement/quick-rfq') ?>">Send requirement</a></div>
            <?php endif; ?>
        </div>
    </div>
</section>
<section class="section buying-help-section"><div class="container buying-help-grid"><div><span class="eyebrow">Buying support</span><h2>Not sure which configuration is valid?</h2><p>Use Product Finder or send the project requirement. Invalid combinations should be disabled by the final product-rule engine.</p></div><div class="button-row"><a class="button button-dark" href="<?= url('ai/product-finder') ?>">Use Product Finder</a><a class="button button-outline" href="<?= url('contact') ?>">Ask technical support</a></div></div></section>

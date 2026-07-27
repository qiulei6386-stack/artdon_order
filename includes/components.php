<?php

declare(strict_types=1);

function breadcrumb(array $page): string
{
    $items = [['title' => 'Home', 'path' => '']];
    $section = $page['section'] ?? '';
    if ($section && $section !== 'home' && $section !== 'product') {
        global $sections;
        if (isset($sections[$section])) {
            $items[] = ['title' => $sections[$section]['title'], 'path' => $section];
        }
    }
    foreach (($page['parents'] ?? []) as $parent) {
        $items[] = $parent;
    }
    if (($page['is_root'] ?? false) !== true && !in_array($section, ['home'], true)) {
        $items[] = ['title' => $page['title'] ?? '', 'path' => $page['path'] ?? ''];
    }
    $html = '<nav class="breadcrumb" aria-label="Breadcrumb">';
    foreach ($items as $index => $item) {
        if ($index > 0) $html .= icon('chevron');
        if ($index === count($items)-1) {
            $html .= '<span aria-current="page">'.e($item['title']).'</span>';
        } else {
            $html .= '<a href="'.url($item['path']).'">'.e($item['title']).'</a>';
        }
    }
    return $html.'</nav>';
}

function page_intro(array $page, string $extra = ''): string
{
    global $sections;
    $eyebrow = $page['eyebrow'] ?? ($sections[$page['section']]['eyebrow'] ?? 'Artdon Lighting');
    $html = '<section class="page-intro"><div class="container">'.breadcrumb($page).'<div class="page-intro-grid"><div><span class="eyebrow">'.e($eyebrow).'</span><h1>'.e($page['title']).'</h1><p>'.e($page['description'] ?? '').'</p></div>';
    $html .= $extra !== '' ? '<div class="page-intro-extra">'.$extra.'</div>' : '<div class="page-intro-extra"><a class="button button-dark" href="'.url('procurement/quick-rfq').'">Start an RFQ</a><a class="button button-outline" href="'.url('contact').'">Contact sales</a></div>';
    return $html.'</div></div></section>';
}

function product_card(array $p, bool $showStock = true, string $class = ''): string
{
    $stockClass = $p['stock'] > 500 ? 'high' : ($p['stock'] > 100 ? 'medium' : 'low');
    $cartPayload = product_cart_payload($p);
    ob_start(); ?>
    <article class="product-card <?= e($class) ?>" data-product-card data-category="<?= e($p['category']) ?>" data-subcategory="<?= e($p['subcategory']) ?>" data-stock-group="<?= e($p['stock_group']) ?>" data-stock="<?= e((string)$p['stock']) ?>" data-price="<?= e((string)$p['price']) ?>" data-new="<?= !empty($p['new']) ? '1' : '0' ?>" data-clearance="<?= !empty($p['clearance']) ? '1' : '0' ?>" data-power="<?= e(strtolower((string)($p['specs']['Power'] ?? ''))) ?>" data-dimming="<?= e(strtolower(implode(' ', $p['features']).' '.(string)($p['specs']['Dimming'] ?? ''))) ?>" data-optical="<?= e(strtolower((string)($p['specs']['Beam'] ?? '').' '.implode(' ', $p['features']))) ?>" data-search="<?= e(strtolower($p['sku'].' '.$p['name'].' '.$p['series'].' '.implode(' ', $p['features']))) ?>">
        <div class="product-media">
            <?php if (!empty($p['badge'])): ?><span class="product-badge"><?= e($p['badge']) ?></span><?php endif; ?>
            <div class="product-card-actions">
                <button type="button" class="round-action" data-wishlist="<?= e($p['sku']) ?>" aria-label="Add to wishlist"><?= icon('heart') ?></button>
                <button type="button" class="round-action" data-compare="<?= e($p['sku']) ?>" aria-label="Compare product"><?= icon('compare') ?></button>
            </div>
            <a href="<?= url('product/'.$p['sku']) ?>"><img src="<?= asset('img/'.$p['image']) ?>" alt="<?= e($p['name']) ?>" loading="lazy"></a>
        </div>
        <div class="product-card-body">
            <div class="product-kicker"><?= e($p['series']) ?> SERIES</div>
            <h3><a href="<?= url('product/'.$p['sku']) ?>"><?= e($p['sku']) ?> <?= e($p['name']) ?></a></h3>
            <p><?= e($p['summary']) ?></p>
            <div class="feature-chips">
                <?php foreach (array_slice($p['features'], 0, 3) as $feature): ?><span><?= e($feature) ?></span><?php endforeach; ?>
            </div>
            <?php if ($showStock): ?>
                <div class="stock-line"><span class="stock-dot <?= $stockClass ?>"></span><strong><?= number_format((int)$p['stock']) ?> pcs</strong><span>· <?= catalog_is_demo() ? 'Demo inventory' : e($p['lead_time']) ?></span></div>
            <?php endif; ?>
            <div class="product-card-footer">
                <div><span class="price-label"><?= catalog_is_demo() ? 'Demo estimate' : 'From' ?></span><strong class="price">USD <?= number_format((float)$p['price'], 2) ?></strong></div>
                <div class="product-card-buttons">
                    <button type="button" class="button button-outline button-icon" data-rfq-open data-product='<?= e(json_encode(['sku'=>$p['sku'],'name'=>$p['name'],'price'=>$p['price']], JSON_UNESCAPED_SLASHES)) ?>' aria-label="Add to RFQ"><?= icon('quote') ?></button>
                    <button type="button" class="button button-dark button-icon" data-quick-config-open data-product='<?= e(json_encode($cartPayload, JSON_UNESCAPED_SLASHES)) ?>' aria-label="Quick configure for Project Cart"><?= icon('cart') ?></button>
                </div>
            </div>
        </div>
    </article>
    <?php return (string)ob_get_clean();
}

function child_cards(string $sectionKey, array $section, int $limit = 99): string
{
    $html = '<div class="link-card-grid">';
    $i=0;
    foreach (($section['items'] ?? []) as $slug=>$item) {
        if ($i++ >= $limit) break;
        $html .= '<a class="link-card" href="'.url($sectionKey.'/'.$slug).'"><div class="link-card-icon">'.icon(match($sectionKey){'resources'=>'file','ai'=>'ai','procurement'=>'quote','support'=>'shield','about'=>'project',default=>'arrow'}).'</div><h3>'.e($item['title']).'</h3><p>'.e($item['description'] ?? $section['description'] ?? '').'</p><span class="text-link">Explore '.icon('arrow').'</span></a>';
    }
    return $html.'</div>';
}

function section_heading(string $eyebrow, string $title, string $description = '', string $link = '', string $linkText = 'View all'): string
{
    $html='<div class="section-heading"><div><span class="eyebrow">'.e($eyebrow).'</span><h2>'.e($title).'</h2>';
    if ($description!=='') $html.='<p>'.e($description).'</p>';
    $html.='</div>';
    if($link!=='') $html.='<a class="text-link" href="'.url($link).'">'.e($linkText).' '.icon('arrow').'</a>';
    return $html.'</div>';
}

function scene_image_for(string $slug): string
{
    $map=[
        'retail'=>'scene-retail.svg','shopping-mall'=>'scene-retail.svg','supermarket'=>'scene-retail.svg',
        'hospitality'=>'scene-hospitality.svg','restaurant'=>'scene-hospitality.svg',
        'office'=>'scene-office.svg','education'=>'scene-office.svg','healthcare'=>'scene-office.svg',
        'outdoor'=>'scene-outdoor.svg','airport'=>'scene-airport.svg','commercial'=>'scene-office.svg',
        'museum'=>'scene-gallery.svg','gallery'=>'scene-gallery.svg','residential'=>'scene-residential.svg',
    ];
    return $map[$slug] ?? 'scene-retail.svg';
}

<?php

declare(strict_types=1);

$pageTitle = ($page['title'] ?? $site['name']) . ' | ' . $site['brand'];
$currentSection = $page['section'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e(meta_description($page)) ?>">
    <?php if (in_array($currentSection, ['account','cart','configure'], true)): ?><meta name="robots" content="noindex,follow"><?php endif; ?>
    <meta name="theme-color" content="#0a0a0a">
    <link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>?v=<?= e((string) filemtime(__DIR__ . '/../assets/css/app.css')) ?>">
    <script>window.ARTDON={basePath:<?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>,csrf:<?= json_encode(csrf_token()) ?>};</script>
</head>
<body data-page="<?= e($currentSection) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<div class="utility-bar">
    <div class="container utility-inner">
        <div class="utility-message"><span class="live-dot"></span><?= catalog_is_demo()
            ? 'Catalog preview · Demo price and inventory · Confirm by RFQ'
            : 'Live inventory · Configurable products · Global delivery' ?></div>
        <div class="utility-links">
            <a href="<?= url('procurement/quick-rfq') ?>">Quick RFQ</a>
            <a href="<?= url('procurement/sample-order') ?>">Sample Order</a>
            <a href="<?= url('procurement/procurement-service') ?>">Procurement Service</a>
            <span class="lang-button" aria-label="Current language: English">EN</span>
        </div>
    </div>
</div>
<header class="site-header" id="siteHeader">
    <div class="container header-main">
        <a class="brand" href="<?= url() ?>" aria-label="Artdon Lighting home">
            <span class="brand-word">ARTDON</span>
            <span class="brand-sub">LIGHTING</span>
        </a>
        <button class="mobile-menu-button" type="button" data-mobile-menu aria-expanded="false" aria-label="Open menu"><?= icon('menu') ?></button>
        <nav class="primary-nav" aria-label="Primary navigation" data-nav>
            <?php foreach ($site['primary_nav'] as $navItem):
                $navPath = $navItem['path'];
                $active = ($navPath === '' && $path === '') || ($navPath !== '' && ($path === $navPath || str_starts_with($path, $navPath . '/')));
                $hasMega = isset($navItem['mega']);
            ?>
                <div class="nav-item <?= $hasMega ? 'has-mega' : '' ?> <?= $active ? 'is-active' : '' ?>">
                    <a href="<?= url($navPath) ?>" <?= $hasMega ? 'data-mega-trigger' : '' ?>>
                        <?php if (($navItem['icon'] ?? '') === 'user'): ?><?= icon('user') ?><?php endif; ?>
                        <?php if (($navItem['icon'] ?? '') === 'cart'): ?><?= icon('cart') ?><span class="cart-count" data-cart-count>0</span><?php endif; ?>
                        <span><?= e($navItem['label']) ?><?php if (($navItem['icon'] ?? '') === 'cart'): ?><small class="cart-nav-summary" data-cart-summary>0 Products · 0 pcs</small><?php endif; ?></span>
                    </a>
                    <?php if ($hasMega):
                        $megaKey = $navItem['mega'];
                        $megaSection = $sections[$megaKey] ?? null;
                    ?>
                        <div class="mega-menu" data-mega-menu>
                            <div class="container mega-grid">
                                <div class="mega-intro">
                                    <span class="eyebrow"><?= e($megaSection['eyebrow'] ?? $megaSection['title']) ?></span>
                                    <h3><?= e($megaSection['title']) ?></h3>
                                    <p><?= e($megaSection['description']) ?></p>
                                    <a class="text-link" href="<?= url($megaKey) ?>">Explore all <?= icon('arrow') ?></a>
                                </div>
                                <div class="mega-links <?= $megaKey === 'products' ? 'mega-links-wide' : '' ?>">
                                    <?php foreach (($megaSection['items'] ?? []) as $itemSlug => $item): ?>
                                        <div class="mega-column">
                                            <a class="mega-heading" href="<?= url($megaKey . '/' . $itemSlug) ?>"><?= e($item['title']) ?></a>
                                            <?php if (!empty($item['items'])): ?>
                                                <?php foreach ($item['items'] as $childSlug => $child): ?>
                                                    <a href="<?= url($megaKey . '/' . $itemSlug . '/' . $childSlug) ?>"><?= e($child['title']) ?></a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if ($megaKey === 'resources'): ?>
                                        <div class="mega-column mega-learn">
                                            <a class="mega-heading" href="<?= url('learn') ?>">Learn / Blog</a>
                                            <?php foreach ($sections['learn']['items'] as $learnSlug => $learnItem): ?>
                                                <a href="<?= url('learn/' . $learnSlug) ?>"><?= e($learnItem['title']) ?></a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mega-feature">
                                    <span class="mega-feature-label">Procurement shortcut</span>
                                    <h4><?= $megaKey === 'ai' ? 'Get a guided product recommendation' : 'Upload your BOQ for a project package' ?></h4>
                                    <p><?= $megaKey === 'ai' ? 'Answer a few questions and shortlist compatible products.' : 'Send drawings, schedules or spreadsheets. We will structure the selection.' ?></p>
                                    <a class="button button-dark button-small" href="<?= url($megaKey === 'ai' ? 'ai/product-finder' : 'procurement/project-package') ?>"><?= $megaKey === 'ai' ? 'Start finder' : 'Upload BOQ' ?></a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="header-actions">
            <button class="icon-button" type="button" data-search-open aria-label="Search"><?= icon('search') ?></button>
            <a class="button button-dark button-small header-rfq" href="<?= url('procurement/quick-rfq') ?>">Get a quote</a>
        </div>
    </div>
</header>
<div class="search-panel" data-search-panel aria-hidden="true">
    <div class="container search-panel-inner">
        <div>
            <span class="eyebrow">Search the platform</span>
            <h2>Find a product, model or resource</h2>
        </div>
        <button class="icon-button" type="button" data-search-close aria-label="Close search"><?= icon('close') ?></button>
        <form class="global-search" action="<?= url('products/all-products') ?>" method="get">
            <?= icon('search') ?>
            <input type="search" name="q" placeholder="Try AL1010, DALI downlight, IES…" autocomplete="off" data-global-search>
            <button class="button button-blue" type="submit">Search</button>
        </form>
        <div class="search-suggestions" data-search-suggestions></div>
    </div>
</div>
<main id="main">

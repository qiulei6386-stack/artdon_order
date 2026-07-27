<?php declare(strict_types=1); ?>
<section class="home-hero">
    <div class="home-hero-media"><img src="<?= asset('img/hero-procurement.svg') ?>" alt="Architectural commercial lighting interior"></div>
    <div class="container home-hero-inner">
        <div class="home-hero-copy">
            <span class="hero-label">ARTDON PROCUREMENT PLATFORM · <?= e($site['version']) ?></span>
            <h1>Source commercial lighting faster.</h1>
            <p>Find products, test configurations, request samples or upload a complete project schedule—all from one procurement platform.</p>
            <div class="hero-actions">
                <a class="button button-blue button-large" href="<?= url('ready-stock') ?>">Browse product catalogue <?= icon('arrow') ?></a>
                <a class="button button-outline-light button-large" href="<?= url('procurement/project-package') ?>">Upload BOQ <?= icon('upload') ?></a>
            </div>
            <div class="hero-proof"><span><?= icon('check') ?> Configurable products</span><span><?= icon('check') ?> Commercial review before confirmation</span><span><?= icon('check') ?> Global delivery support</span></div>
        </div>
        <div class="procurement-search-card">
            <div class="search-card-tabs" role="tablist">
                <button class="is-active" type="button" data-home-search-tab="product">Find product</button>
                <button type="button" data-home-search-tab="project">Project RFQ</button>
                <button type="button" data-home-search-tab="sample">Sample</button>
            </div>
            <div class="search-card-panel is-active" data-home-search-panel="product">
                <h2>What are you sourcing?</h2>
                <p>Search by model, category, application or specification.</p>
                <form class="hero-search" action="<?= url('products/all-products') ?>" method="get">
                    <?= icon('search') ?><input name="q" placeholder="e.g. 15W DALI track spotlight"><button type="submit">Search</button>
                </form>
                <div class="quick-tags"><a href="<?= url('products/recessed-downlights') ?>">Downlights</a><a href="<?= url('products/track-lighting') ?>">Track</a><a href="<?= url('products/driver') ?>">Drivers</a><a href="<?= url('products/accessories') ?>">Accessories</a></div>
            </div>
            <div class="search-card-panel" data-home-search-panel="project">
                <h2>Upload your lighting schedule</h2><p>Send BOQ, drawings or an Excel list. We will return a structured product package.</p>
                <a class="upload-drop" href="<?= url('procurement/project-package') ?>"><?= icon('upload') ?><span><strong>Upload BOQ / drawing</strong><small>PDF, XLSX, DWG, ZIP</small></span></a>
            </div>
            <div class="search-card-panel" data-home-search-panel="sample">
                <h2>Order samples for approval</h2><p>Select products, quantities, finish and the target project date.</p>
                <a class="button button-dark button-block" href="<?= url('procurement/sample-order') ?>">Start sample order</a>
            </div>
        </div>
    </div>
</section>

<section class="trust-metrics">
    <div class="container trust-metrics-grid">
        <div><?= icon('stock') ?><span><strong>Demo</strong> inventory and pricing dataset</span></div>
        <div><?= icon('clock') ?><span><strong>Review</strong> stock and lead time before order</span></div>
        <div><?= icon('shield') ?><span><strong>100%</strong> order review before confirmation</span></div>
        <div><?= icon('truck') ?><span><strong>Global</strong> project shipping support</span></div>
    </div>
</section>

<section class="section section-tight procurement-entry-section">
    <div class="container">
        <?= section_heading('Start here', 'Choose the fastest procurement route', 'The homepage is designed around purchasing tasks—not a company introduction.') ?>
        <div class="procurement-entry-grid">
            <a class="procurement-entry-card card-blue" href="<?= url('ready-stock') ?>"><div class="entry-icon"><?= icon('stock') ?></div><span>01</span><h3>Ready Stock</h3><p>Review indicative quantity, price and dispatch fields, then request confirmation.</p><strong>Browse catalogue <?= icon('arrow') ?></strong></a>
            <a class="procurement-entry-card" href="<?= url('procurement/sample-order') ?>"><div class="entry-icon"><?= icon('sample') ?></div><span>02</span><h3>Sample Order</h3><p>Request samples for evaluation, mock-up or client approval.</p><strong>Order samples <?= icon('arrow') ?></strong></a>
            <a class="procurement-entry-card" href="<?= url('procurement/quick-rfq') ?>"><div class="entry-icon"><?= icon('quote') ?></div><span>03</span><h3>Quick RFQ</h3><p>Send models, quantities and target delivery date for review.</p><strong>Request pricing <?= icon('arrow') ?></strong></a>
            <a class="procurement-entry-card card-dark" href="<?= url('procurement/project-package') ?>"><div class="entry-icon"><?= icon('project') ?></div><span>04</span><h3>Project Package</h3><p>Upload a BOQ and receive a coordinated lighting proposal.</p><strong>Upload project <?= icon('arrow') ?></strong></a>
        </div>
    </div>
</section>

<section class="section inventory-section">
    <div class="container">
        <?= section_heading('Procurement preview', 'Sample ready-stock catalogue', 'All price, inventory and lead-time values below are demonstration data until the ERP connection is approved.', 'ready-stock', 'View sample catalogue') ?>
        <div class="inventory-toolbar">
            <div class="inventory-tabs"><button class="is-active" data-product-filter="all">All</button><button data-product-filter="track-lighting">Track</button><button data-product-filter="downlights">Downlights</button><button data-product-filter="magnetic-system">Magnetic</button><button data-product-filter="accessories">Accessories</button></div>
            <div class="inventory-updated"><span class="live-dot"></span> Demo data · not live inventory</div>
        </div>
        <div class="product-grid product-grid-four" data-filter-grid>
            <?php foreach (array_slice($products,0,8) as $p): ?><?= product_card($p,true) ?><?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section category-section">
    <div class="container">
        <?= section_heading('Product system', 'Source more than luminaires', 'Drivers, tracks, optics, accessories and spare parts are first-class product categories.', 'products', 'View all products') ?>
        <div class="category-showcase">
            <?php
            $cats=[
                ['slug'=>'recessed-downlights','title'=>'Recessed Downlights','desc'=>'Fixed, adjustable, trimless, IP65 and mini.','img'=>'downlight.svg','count'=>'6 families'],
                ['slug'=>'track-lighting','title'=>'Track Lighting','desc'=>'Spot, linear, wall washer, zoom and pendant.','img'=>'track.svg','count'=>'6 families'],
                ['slug'=>'magnetic','title'=>'Magnetic System','desc'=>'48V tracks, luminaires and connectors.','img'=>'magnetic.svg','count'=>'Complete system'],
                ['slug'=>'linear','title'=>'Linear Lighting','desc'=>'Architectural continuous linear solutions.','img'=>'linear.svg','count'=>'Custom length'],
                ['slug'=>'driver','title'=>'LED Drivers','desc'=>'On/off, 0–10V, phase-cut and DALI-2.','img'=>'driver.svg','count'=>'Demo category'],
                ['slug'=>'accessories','title'=>'Accessories','desc'=>'Optics, reflectors, connectors and mounting kits.','img'=>'accessory.svg','count'=>'Demo category'],
            ]; foreach($cats as $cat): ?>
                <a class="category-tile" href="<?= url('products/'.$cat['slug']) ?>"><div class="category-tile-media"><img src="<?= asset('img/'.$cat['img']) ?>" alt=""></div><div><span><?= e($cat['count']) ?></span><h3><?= e($cat['title']) ?></h3><p><?= e($cat['desc']) ?></p><strong>Explore <?= icon('arrow') ?></strong></div></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section solutions-home-section">
    <div class="container">
        <?= section_heading('Application-led SEO', 'Solutions for every project type', 'Each solution page connects design guidance, recommended products, project evidence and downloadable files.', 'solutions', 'Explore all solutions') ?>
        <div class="scene-grid">
            <?php $scenes=[['retail','Retail','Highlight products and guide attention.'],['hospitality','Hospitality','Create comfort, atmosphere and hierarchy.'],['office','Office','Balance visual comfort and performance.'],['museum','Museum & Gallery','Protect artworks with precise optical control.'],['residential','Residential','Layered lighting for premium interiors.'],['airport','Airport','Durable systems for large public spaces.']]; foreach($scenes as [$slug,$title,$desc]): ?>
                <a class="scene-card" href="<?= url('solutions/'.$slug) ?>"><img src="<?= asset('img/'.scene_image_for($slug)) ?>" alt="<?= e($title) ?> lighting"><div class="scene-overlay"><span>Solution</span><h3><?= e($title) ?></h3><p><?= e($desc) ?></p><strong>View solution <?= icon('arrow') ?></strong></div></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section ai-home-section">
    <div class="container ai-home-grid">
        <div class="ai-home-intro"><span class="eyebrow eyebrow-light">AI procurement assistant</span><h2>Turn a lighting requirement into a product shortlist.</h2><p>Use guided tools before contacting sales. Each result can be added directly to a comparison or RFQ.</p><a class="button button-light" href="<?= url('ai/product-finder') ?>">Start Product Finder <?= icon('arrow') ?></a></div>
        <div class="ai-tool-grid">
            <a href="<?= url('ai/product-finder') ?>"><?= icon('ai') ?><span><strong>Product Finder</strong><small>Application → product shortlist</small></span><?= icon('arrow') ?></a>
            <a href="<?= url('ai/lighting-calculator') ?>"><?= icon('project') ?><span><strong>Lighting Calculator</strong><small>Estimate fixture quantities</small></span><?= icon('arrow') ?></a>
            <a href="<?= url('ai/beam-angle-selector') ?>"><?= icon('compare') ?><span><strong>Beam Angle Selector</strong><small>Match height and beam diameter</small></span><?= icon('arrow') ?></a>
            <a href="<?= url('ai/driver-selector') ?>"><?= icon('stock') ?><span><strong>Driver Selector</strong><small>Check power and dimming</small></span><?= icon('arrow') ?></a>
            <a href="<?= url('ai/compare-products') ?>"><?= icon('compare') ?><span><strong>Compare Products</strong><small>Compare technical differences</small></span><?= icon('arrow') ?></a>
            <a href="<?= url('ai/ai-consultant') ?>"><?= icon('ai') ?><span><strong>AI Consultant</strong><small>Ask a lighting question</small></span><?= icon('arrow') ?></a>
        </div>
    </div>
</section>

<section class="section project-package-section">
    <div class="container project-package-grid">
        <div class="project-package-media"><img src="<?= asset('img/project-package.svg') ?>" alt="Project procurement package"></div>
        <div class="project-package-copy"><span class="eyebrow">Project procurement</span><h2>One BOQ. One coordinated lighting package.</h2><p>Upload schedules, drawings or a competitor specification. Artdon can structure the product match, alternates, accessories, drivers, IES and commercial quotation.</p><ol><li><span>1</span>Upload project files</li><li><span>2</span>Receive product matching and questions</li><li><span>3</span>Review quotation, samples and lead time</li><li><span>4</span>Confirm the project order</li></ol><div class="button-row"><a class="button button-dark" href="<?= url('procurement/project-package') ?>">Upload BOQ</a><a class="button button-outline" href="<?= url('projects') ?>">View projects</a></div></div>
    </div>
</section>

<section class="section resources-home-section">
    <div class="container">
        <?= section_heading('Technical resources', 'Everything procurement and design teams need', 'Search by model and download the latest technical file.', 'resources', 'Open resource centre') ?>
        <div class="resource-shortcut-grid">
            <?php $res=[['catalogue','Catalogue','Browse complete product ranges.'],['datasheet','Datasheets','Product-specific technical data.'],['ies','IES Files','Photometric files for calculations.'],['bim','BIM / CAD','Design and coordination assets.'],['installation','Installation','Mounting and wiring guidance.'],['certificates','Certificates','Quality and compliance documents.']]; foreach($res as [$slug,$title,$desc]): ?>
                <a href="<?= url('resources/'.$slug) ?>"><?= icon('file') ?><span><strong><?= e($title) ?></strong><small><?= e($desc) ?></small></span><?= icon('download') ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

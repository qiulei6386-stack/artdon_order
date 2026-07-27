<?php declare(strict_types=1); $section=$sections['resources']; ?>
<?= page_intro($page) ?>
<section class="section"><div class="container">
<?php if($page['is_root'] ?? false): ?>
<?= section_heading('Technical library','Find the latest file by type or model','The production version should read files from the same media centre used by product pages.') ?>
<?= child_cards('resources',$section) ?>
<?php else: ?>
<div class="resource-toolbar"><label><?= icon('search') ?><input type="search" placeholder="Search model, series or file name" data-resource-search></label><select><option>All series</option><option>AL10</option><option>AT20</option><option>MW30</option></select><select><option>Demo placeholders</option></select></div>
<div class="resource-table" data-resource-table>
<div class="resource-table-head"><span>File</span><span>Series / model</span><span>Revision</span><span>Size</span><span></span></div>
<?php for($i=1;$i<=8;$i++): $p=$products[($i-1)%count($products)]; ?>
<a class="resource-row" href="#" data-demo-download data-search="<?= e(strtolower($p['sku'].' '.$p['name'].' '.$page['title'])) ?>"><span><?= icon('file') ?><b><?= e($p['sku'].' '.$page['title']) ?></b><small><?= e($p['name']) ?> · Demo file placeholder</small></span><span><?= e($p['series'].' / '.$p['sku']) ?></span><span>Demo</span><span>—</span><span><?= icon('download') ?></span></a>
<?php endfor; ?>
</div>
<div class="resource-note"><div><?= icon('shield') ?></div><div><h3>Revision-controlled files</h3><p>In the live platform, each download should retain model, revision, upload time, uploader and usage log.</p></div></div>
<?php endif; ?>
</div></section>

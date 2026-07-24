<?php declare(strict_types=1); $section=$sections['about']; ?>
<?= page_intro($page) ?>
<section class="section"><div class="container">
<?php if($page['is_root'] ?? false): ?><?= child_cards('about',$section) ?><?php else: ?>
<div class="about-feature"><div><span class="eyebrow">Manufacturing partner</span><h2><?= e($page['title']) ?> at Artdon Lighting</h2><p>Use this template to show verifiable manufacturing capability, people, processes, quality controls and project support—not generic company slogans.</p><div class="about-metrics"><div><strong>OEM / ODM</strong><span>Configurable product support</span></div><div><strong>IES · EMC · Thermal</strong><span>Product validation workflow</span></div><div><strong>Global projects</strong><span>Commercial procurement support</span></div></div><a class="button button-dark" href="<?= url('contact') ?>">Contact the team</a></div><div class="about-feature-media"><img src="<?= asset('img/factory.svg') ?>" alt="Artdon manufacturing"></div></div>
<div class="process-grid"><?php foreach(['Requirement review','Engineering and BOM','Prototype and validation','Surface and assembly','Inspection and packing','Shipping and after-sales'] as $i=>$step): ?><article><span><?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span><h3><?= e($step) ?></h3><p>Show evidence, records and responsible functions for this stage.</p></article><?php endforeach; ?></div>
<?php endif; ?>
</div></section>

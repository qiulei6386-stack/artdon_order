<?php declare(strict_types=1); $section=$sections['support']; ?>
<?= page_intro($page) ?>
<section class="section"><div class="container">
<?php if($page['is_root'] ?? false): ?><?= child_cards('support',$section) ?>
<?php else: ?>
<div class="content-layout"><aside class="content-nav"><strong>Support topics</strong><?php foreach($section['items'] as $slug=>$item): ?><a class="<?= $slug===$page['slug']?'is-active':'' ?>" href="<?= url('support/'.$slug) ?>"><?= e($item['title']) ?><?= icon('arrow') ?></a><?php endforeach; ?></aside><article class="content-article"><span class="eyebrow">Support policy</span><h2><?= e($page['title']) ?> information</h2><p class="lead">This is the reusable support-page template. Replace the sample copy with approved commercial, logistics and quality policies.</p><?php for($i=1;$i<=4;$i++): ?><section><h3><?= e($page['title']) ?> topic <?= $i ?></h3><p>Provide clear terms, scope, responsibilities, exclusions, required documents and the exact process customers should follow.</p></section><?php endfor; ?><div class="faq-list" data-accordion><?php for($i=1;$i<=4;$i++): ?><details><summary>Common <?= e(strtolower($page['title'])) ?> question <?= $i ?><?= icon('plus') ?></summary><p>Answer with precise conditions and the next action for the customer.</p></details><?php endfor; ?></div></article></div>
<?php endif; ?>
</div></section>

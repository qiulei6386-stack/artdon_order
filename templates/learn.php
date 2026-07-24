<?php declare(strict_types=1); $section=$sections['learn']; ?>
<?= page_intro($page) ?>
<section class="section"><div class="container">
<?php if($page['is_root'] ?? false): ?>
<?= child_cards('learn',$section) ?>
<?php endif; ?>
<?= section_heading('Knowledge centre',$page['is_root']?'Latest lighting articles':$page['title'].' articles','Structured content designed for search, AI discovery and practical buying decisions.') ?>
<div class="article-grid"><?php for($i=1;$i<=9;$i++): ?><article class="article-card"><a class="article-media" href="#"><img src="<?= asset('img/article-'.(($i%3)+1).'.svg') ?>" alt=""></a><div><span><?= e($page['is_root']?'Product Guide':$page['title']) ?> · <?= 4+$i ?> min read</span><h3><a href="#">How to choose the right commercial lighting configuration <?= $i ?></a></h3><p>Practical guidance connecting application, optics, power, control and procurement considerations.</p><a class="text-link" href="#">Read article <?= icon('arrow') ?></a></div></article><?php endfor; ?></div>
</div></section>

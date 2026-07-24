<?php declare(strict_types=1); $section=$sections['procurement']; ?>
<?= page_intro($page) ?>
<section class="section"><div class="container">
<?php if($page['is_root'] ?? false): ?>
<?= child_cards('procurement',$section) ?>
<?php else: ?>
<div class="procurement-service-layout">
<div class="procurement-service-copy"><span class="eyebrow"><?= e($page['title']) ?></span><h2>Submit the requirement for review</h2><p>The first submission creates an order or quotation request—not an automatically confirmed sales order. Price, compatibility, shipping and lead time are reviewed before confirmation.</p><div class="service-steps"><div><span>1</span><strong>Submit</strong><p>Models, quantity, project and files.</p></div><div><span>2</span><strong>Review</strong><p>Compatibility, price, lead time and logistics.</p></div><div><span>3</span><strong>Confirm</strong><p>Receive quotation / PI and approve online.</p></div></div><div class="service-assurance"><h3>What is captured</h3><ul class="check-list"><li><?= icon('check') ?> Product and configuration snapshot</li><li><?= icon('check') ?> Company and project information</li><li><?= icon('check') ?> Files, notes and requested date</li><li><?= icon('check') ?> Submission and revision history</li></ul></div></div>
<form class="api-form procurement-form" action="<?= url('api/submit.php') ?>" method="post" enctype="multipart/form-data" data-api-form>
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="submission_token" value="<?= e(submission_token()) ?>"><input type="hidden" name="form_type" value="<?= e($page['slug']) ?>">
<div class="form-section"><span>01</span><div><h3>Company</h3><div class="form-grid two-columns"><label>Company name *<input required name="company"></label><label>Country / region *<input required name="country"></label><label>Contact name *<input required name="name"></label><label>Email *<input required type="email" name="email"></label><label>Phone / WhatsApp<input name="phone"></label><label>Website<input name="company_website"></label></div></div></div>
<div class="form-section"><span>02</span><div><h3><?= e($page['title']) ?> requirement</h3><div class="form-grid two-columns"><label>Models / product type<input name="models" placeholder="AL1010 or recessed downlight"></label><label>Estimated quantity<input name="quantity" placeholder="e.g. 500 pcs"></label><label>Project name<input name="project"></label><label>Target delivery date<input type="date" name="target_date"></label><label>Budget / target price<input name="budget"></label><label>Trade term<select name="trade_term"><option>Not decided</option><option>EXW</option><option>FOB</option><option>CIF</option><option>DDP</option></select></label><label class="full">Requirement *<textarea required name="message" rows="6" placeholder="Power, CCT, beam angle, finish, driver, dimming, certification and any custom requirement…"></textarea></label></div></div></div>
<div class="form-section"><span>03</span><div><h3>Project files</h3><label class="file-drop"><?= icon('upload') ?><strong>Choose or drop files</strong><small>BOQ, Excel, PDF, drawing, image or ZIP · Maximum 10 MB per file</small><input type="file" name="attachments[]" multiple></label></div></div>
<label class="honeypot" aria-hidden="true">Website<input name="website" tabindex="-1"></label>
<div class="form-actions"><label class="consent"><input type="checkbox" required> I confirm that the information may be used to prepare this request.</label><button class="button button-dark button-large" type="submit">Submit <?= e($page['title']) ?></button></div><div class="form-status" data-form-status></div>
</form>
</div>
<?php endif; ?>
</div></section>

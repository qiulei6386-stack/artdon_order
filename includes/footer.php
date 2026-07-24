<?php declare(strict_types=1); ?>
</main>
<section class="procurement-strip">
    <div class="container procurement-strip-grid">
        <div>
            <span class="eyebrow eyebrow-light">Need help with a project?</span>
            <h2>Send one requirement. Receive a structured lighting package.</h2>
        </div>
        <div class="procurement-strip-actions">
            <a class="button button-light" href="<?= url('procurement/project-package') ?>">Upload BOQ <?= icon('upload') ?></a>
            <a class="button button-outline-light" href="<?= url('contact') ?>">Talk to sales</a>
        </div>
    </div>
</section>
<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-brand">
            <a class="brand brand-light" href="<?= url() ?>"><span class="brand-word">ARTDON</span><span class="brand-sub">LIGHTING</span></a>
            <p><?= e($site['tagline']) ?></p>
            <div class="footer-contact-lines">
                <a href="mailto:<?= e($site['contact_email']) ?>"><?= icon('mail') ?> <?= e($site['contact_email']) ?></a>
                <?php if ($site['phone'] !== ''): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $site['phone'])) ?>"><?= icon('phone') ?> <?= e($site['phone']) ?></a><?php endif; ?>
            </div>
        </div>
        <div>
            <h3>Procurement</h3>
            <a href="<?= url('ready-stock') ?>">Ready Stock</a>
            <a href="<?= url('procurement/quick-rfq') ?>">Quick RFQ</a>
            <a href="<?= url('procurement/sample-order') ?>">Sample Order</a>
            <a href="<?= url('procurement/project-package') ?>">Project Package</a>
            <a href="<?= url('procurement/oem') ?>">OEM / ODM</a>
        </div>
        <div>
            <h3>Products</h3>
            <a href="<?= url('products/track-lighting') ?>">Track Lighting</a>
            <a href="<?= url('products/recessed-downlights') ?>">Downlights</a>
            <a href="<?= url('products/magnetic') ?>">Magnetic</a>
            <a href="<?= url('products/linear') ?>">Linear</a>
            <a href="<?= url('products/driver') ?>">Drivers</a>
        </div>
        <div>
            <h3>Resources</h3>
            <a href="<?= url('resources/catalogue') ?>">Catalogue</a>
            <a href="<?= url('resources/ies') ?>">IES</a>
            <a href="<?= url('resources/bim') ?>">BIM / CAD</a>
            <a href="<?= url('resources/installation') ?>">Installation</a>
            <a href="<?= url('learn') ?>">Learn</a>
        </div>
        <div>
            <h3>Company</h3>
            <a href="<?= url('about/company') ?>">Company</a>
            <a href="<?= url('about/factory') ?>">Factory</a>
            <a href="<?= url('about/quality') ?>">Quality</a>
            <a href="<?= url('support') ?>">Support</a>
            <a href="<?= url('contact') ?>">Contact</a>
        </div>
    </div>
    <div class="container footer-bottom">
        <span>© <?= date('Y') ?> Artdon Lighting Limited. All rights reserved.</span>
        <div><a href="<?= url('support/payment') ?>">Payment</a><a href="<?= url('support/returns') ?>">Returns</a><span><?= e($site['version']) ?></span></div>
    </div>
</footer>
<div class="toast-region" aria-live="polite" aria-atomic="true" data-toast-region></div>
<div class="modal-backdrop" data-rfq-modal aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="rfqModalTitle">
        <div class="modal-header">
            <div><span class="eyebrow">Quick procurement request</span><h2 id="rfqModalTitle">Add this selection to an RFQ</h2></div>
            <button type="button" class="icon-button" data-modal-close aria-label="Close"><?= icon('close') ?></button>
        </div>
        <form class="api-form" action="<?= url('api/submit.php') ?>" method="post" data-api-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="submission_token" value="<?= e(submission_token()) ?>">
            <input type="hidden" name="form_type" value="quick_rfq">
            <input type="hidden" name="selection" value="" data-rfq-selection>
            <div class="form-grid two-columns">
                <label>Company<input required name="company" autocomplete="organization"></label>
                <label>Contact name<input required name="name" autocomplete="name"></label>
                <label>Email<input required type="email" name="email" autocomplete="email"></label>
                <label>Country / region<input required name="country" autocomplete="country-name"></label>
                <label class="full">Requirement<textarea name="message" rows="4" placeholder="Quantity, target delivery date, project details…"></textarea></label>
                <label class="honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
            </div>
            <div class="form-actions"><span class="form-note">A salesperson will review price, lead time and shipping before confirmation.</span><button class="button button-dark" type="submit">Submit RFQ</button></div>
            <div class="form-status" data-form-status></div>
        </form>
    </div>
</div>
<script src="<?= asset('js/app.js') ?>?v=<?= e((string) filemtime(__DIR__ . '/../assets/js/app.js')) ?>" defer></script>
</body>
</html>

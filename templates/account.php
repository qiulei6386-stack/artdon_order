<?php

declare(strict_types=1);

$section = $sections['account'];
$view = $page['slug'] ?? 'dashboard';
?>
<section class="account-page">
    <div class="container account-layout">
        <aside class="account-sidebar">
            <div class="account-profile">
                <div class="avatar">AP</div>
                <div>
                    <strong>Procurement Workspace</strong>
                    <span>Secure account access pending</span>
                </div>
            </div>
            <nav>
                <?php foreach ($section['items'] as $slug => $item): ?>
                    <a class="<?= $slug === $view ? 'is-active' : '' ?>" href="<?= url('account/' . $slug) ?>">
                        <?= icon(match ($slug) {
                            'orders' => 'cart',
                            'quotes' => 'quote',
                            'wishlist' => 'heart',
                            'compare' => 'compare',
                            'downloads' => 'download',
                            'address' => 'location',
                            'settings' => 'user',
                            default => 'project',
                        }) ?>
                        <?= e($item['title']) ?>
                        <span><?= icon('chevron') ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <a class="account-help" href="<?= url('support/contact') ?>">
                <strong>Need account access?</strong>
                <span>Contact the Artdon procurement team.</span>
                <?= icon('arrow') ?>
            </a>
        </aside>

        <div class="account-main">
            <?= breadcrumb($page) ?>
            <div class="account-header">
                <div>
                    <span class="eyebrow">My Account</span>
                    <h1><?= e($page['title']) ?></h1>
                    <p>Authenticated customer records are not exposed until account verification is connected.</p>
                </div>
                <a class="button button-dark" href="<?= url('procurement/quick-rfq') ?>">Start an RFQ</a>
            </div>

            <section class="account-panel">
                <div class="panel-head">
                    <h2>Customer portal activation</h2>
                </div>
                <div class="compare-storage">
                    <h2>Your commercial records stay private</h2>
                    <p>
                        Quotes, orders, company details and project downloads require verified sign-in.
                        The authentication and ERP identity connection are being prepared, so this page
                        intentionally shows no sample customer data.
                    </p>
                    <div class="hero-actions">
                        <a class="button button-dark" href="<?= url('cart') ?>">Open Project Cart</a>
                        <a class="button button-outline" href="<?= url('support/contact') ?>">Contact support</a>
                    </div>
                </div>
            </section>
        </div>
    </div>
</section>

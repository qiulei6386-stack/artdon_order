<?php

declare(strict_types=1);

return [
    'name' => 'Artdon Procurement Platform',
    'brand' => 'ARTDON LIGHTING',
    'version' => 'V1.0',
    'tagline' => 'Commercial lighting sourcing, simplified.',
    'base_path' => rtrim((string) (getenv('APP_BASE_PATH') ?: ''), '/'),
    'currency' => 'USD',
    'contact_email' => (string) (getenv('CONTACT_EMAIL') ?: 'sales@artdonlighting.com'),
    'order_email' => (string) (getenv('ORDER_EMAIL') ?: 'orders@artdonlighting.com'),
    'whatsapp' => (string) (getenv('WHATSAPP_NUMBER') ?: ''),
    'phone' => (string) (getenv('CONTACT_PHONE') ?: ''),
    'address' => (string) (getenv('CONTACT_ADDRESS') ?: 'Hong Kong sales · Zhongshan manufacturing'),
    'enable_mail' => filter_var(getenv('ENABLE_PHP_MAIL') ?: 'false', FILTER_VALIDATE_BOOL),
    'primary_nav' => [
        ['label' => 'Home', 'path' => ''],
        ['label' => 'Ready Stock', 'path' => 'ready-stock', 'mega' => 'ready-stock'],
        ['label' => 'Products', 'path' => 'products', 'mega' => 'products'],
        ['label' => 'Solutions', 'path' => 'solutions', 'mega' => 'solutions'],
        ['label' => 'Projects', 'path' => 'projects', 'mega' => 'projects'],
        ['label' => 'Resources', 'path' => 'resources', 'mega' => 'resources'],
        ['label' => 'AI Assistant', 'path' => 'ai', 'mega' => 'ai'],
        ['label' => 'About', 'path' => 'about', 'mega' => 'about'],
        ['label' => 'Support', 'path' => 'support', 'mega' => 'support'],
        ['label' => 'Contact', 'path' => 'contact'],
        ['label' => 'Account', 'path' => 'account/dashboard', 'icon' => 'user'],
        ['label' => 'Cart', 'path' => 'cart', 'icon' => 'cart'],
    ],
];

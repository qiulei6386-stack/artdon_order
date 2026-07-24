<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Singapore');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

$site = require __DIR__ . '/../config/site.php';
$sections = require __DIR__ . '/../config/content.php';
$products = require __DIR__ . '/../config/products.php';
$productConfigurations = require __DIR__ . '/../config/product_configuration.php';
require_once __DIR__ . '/components.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    global $site;
    return (string) ($site['base_path'] ?? '');
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = base_path();
    if ($path === '') {
        return $base !== '' ? $base . '/' : '/';
    }
    return ($base !== '' ? $base : '') . '/' . $path;
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function icon(string $name, string $class = ''): string
{
    $icons = [
        'search' => '<path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/>',
        'user' => '<path d="M20 21a8 8 0 0 0-16 0m8-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>',
        'cart' => '<path d="M3 4h2l2.2 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L21 7H6m4 12.5h.01m7 0h.01"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'chevron' => '<path d="m9 18 6-6-6-6"/>',
        'download' => '<path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/>',
        'upload' => '<path d="M12 21V9m0 0 4 4m-4-4-4 4M5 3h14"/>',
        'stock' => '<path d="M3 7.5 12 3l9 4.5-9 4.5-9-4.5Zm0 5L12 17l9-4.5M3 17l9 4 9-4"/>',
        'truck' => '<path d="M3 6h11v10H3zM14 10h4l3 3v3h-7zM7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Zm-3-10 2 2 4-5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'quote' => '<path d="M4 5h16v14H4zM8 9h8m-8 4h5"/>',
        'sample' => '<path d="M8 3h8l2 5-6 13L6 8l2-5Zm-2 5h12"/>',
        'project' => '<path d="M4 20V7l8-4 8 4v13M8 20v-5h8v5M8 10h.01M12 10h.01M16 10h.01"/>',
        'ai' => '<rect x="4" y="5" width="16" height="14" rx="3"/><path d="M9 10h.01M15 10h.01M9 15c2 1 4 1 6 0M12 5V2"/>',
        'compare' => '<path d="M8 3v18M16 3v18M4 7h4m8 0h4M4 17h4m8 0h4"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'mail' => '<path d="M3 5h18v14H3zM3 7l9 6 9-6"/>',
        'phone' => '<path d="M6.6 3h3l1.5 4-2 1.4a16 16 0 0 0 6.5 6.5l1.4-2 4 1.5v3c0 2-1.6 3.6-3.6 3.6A15.4 15.4 0 0 1 3 6.6C3 4.6 4.6 3 6.6 3Z"/>',
        'location' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        'filter' => '<path d="M4 6h16M7 12h10M10 18h4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'file' => '<path d="M6 2h8l4 4v16H6zM14 2v5h5M9 13h6m-6 4h6"/>',
        'external' => '<path d="M14 4h6v6m0-6-9 9M18 13v7H4V6h7"/>',
    ];
    $body = $icons[$name] ?? $icons['arrow'];
    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

function flatten_routes(array $sections): array
{
    $routes = [];
    foreach ($sections as $sectionSlug => $section) {
        $routes[$sectionSlug] = array_merge($section, [
            'slug' => $sectionSlug,
            'path' => $sectionSlug,
            'section' => $sectionSlug,
            'is_root' => true,
        ]);
        $walker = function (array $items, string $prefix, string $sectionKey, array $parents = []) use (&$walker, &$routes, $section): void {
            foreach ($items as $slug => $item) {
                $path = $prefix . '/' . $slug;
                $record = array_merge([
                    'description' => $section['description'] ?? '',
                    'template' => $section['template'] ?? 'page',
                ], $item, [
                    'slug' => $slug,
                    'path' => $path,
                    'section' => $sectionKey,
                    'parents' => $parents,
                    'is_root' => false,
                ]);
                $routes[$path] = $record;
                if (!empty($item['items']) && is_array($item['items'])) {
                    $nextParents = array_merge($parents, [['title' => $item['title'], 'path' => $path]]);
                    $walker($item['items'], $path, $sectionKey, $nextParents);
                }
            }
        };
        if (!empty($section['items'])) {
            $walker($section['items'], $sectionSlug, $sectionSlug);
        }
    }
    return $routes;
}

function current_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_path();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    $uri = trim($uri, '/');
    if ($uri === 'index.php') {
        return '';
    }
    return $uri;
}

function find_product(string $sku): ?array
{
    global $products;
    foreach ($products as $product) {
        if (strcasecmp($product['sku'], $sku) === 0) {
            return $product;
        }
    }
    return null;
}

function product_configuration(array $product): array
{
    global $productConfigurations;
    $category = (string) ($product['category'] ?? '');
    $config = $productConfigurations[$product['sku']] ?? $productConfigurations[$category] ?? $productConfigurations['default'];
    return array_merge([
        'price_mode' => 'fixed',
        'sku_order' => ['series'],
        'options' => [],
        'rules' => [],
    ], $config);
}

function product_cart_payload(array $product): array
{
    $price = $product['price'] ?? null;
    return [
        'product_id' => $product['sku'],
        'sku' => $product['sku'],
        'product_name' => $product['name'],
        'name' => $product['name'],
        'model' => $product['sku'],
        'series' => $product['series'],
        'image' => $product['image'],
        'unit_price' => is_numeric($price) ? (float) $price : null,
        'base_unit_price' => is_numeric($price) ? (float) $price : null,
        'price' => is_numeric($price) ? (float) $price : null,
        'price_mode' => product_configuration($product)['price_mode'] ?? 'fixed',
        'lead_time' => $product['lead_time'],
        'moq' => (int) ($product['moq'] ?? 1),
        'configuration_schema' => product_configuration($product),
    ];
}

function products_for_page(array $page): array
{
    global $products;
    $section = $page['section'] ?? '';
    $slug = $page['slug'] ?? '';
    $path = $page['path'] ?? '';

    if ($section === 'ready-stock') {
        if ($slug === 'new-arrival') return array_values(array_filter($products, fn($p) => $p['new']));
        if ($slug === 'clearance') return array_values(array_filter($products, fn($p) => $p['clearance']));
        if (in_array($slug, ['all-ready-stock', 'ready-stock'], true) || ($page['is_root'] ?? false)) return $products;
        return array_values(array_filter($products, fn($p) => $p['stock_group'] === $slug));
    }

    if ($section === 'products') {
        if (($page['is_root'] ?? false) || $slug === 'all-products') return $products;
        $parts = explode('/', $path);
        $category = $parts[1] ?? $slug;
        $subcategory = $parts[2] ?? null;
        return array_values(array_filter($products, function ($p) use ($category, $subcategory, $slug) {
            if ($subcategory !== null) return $p['category'] === $category && $p['subcategory'] === $subcategory;
            return $p['category'] === $slug || $p['category'] === $category || $p['subcategory'] === $slug;
        }));
    }

    return array_slice($products, 0, 6);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function submission_token(): string
{
    return bin2hex(random_bytes(20));
}

function meta_description(array $page): string
{
    return (string) ($page['description'] ?? 'Commercial lighting products, ready stock, project solutions and procurement services from Artdon Lighting.');
}

$routes = flatten_routes($sections);
$path = current_path();
$page = null;
$template = 'home';
$product = null;

if ($path === '') {
    $page = [
        'title' => 'Commercial Lighting Procurement Platform',
        'description' => 'Source ready-stock commercial lighting, configure products, request samples and submit project RFQs.',
        'path' => '',
        'section' => 'home',
    ];
    $template = 'home';
} elseif ($path === 'contact') {
    $page = ['title' => 'Contact', 'description' => 'Contact Artdon Lighting sales and technical teams.', 'path' => 'contact', 'section' => 'contact'];
    $template = 'contact';
} elseif ($path === 'cart') {
    $page = ['title' => 'Project Cart', 'description' => 'Review configured products and submit a commercial lighting project request.', 'path' => 'cart', 'section' => 'cart'];
    $template = 'cart';
} elseif (preg_match('#^(product|configure)/([A-Za-z0-9-]+)$#', $path, $matches)) {
    $product = find_product($matches[2]);
    if ($product) {
        $isConfigurator = $matches[1] === 'configure';
        $page = [
            'title' => ($isConfigurator ? 'Configure ' : '') . $product['sku'] . ' ' . $product['name'],
            'description' => $product['summary'],
            'path' => $path,
            'section' => $isConfigurator ? 'configure' : 'product',
        ];
        $template = $isConfigurator ? 'configure' : 'product';
    }
} elseif (isset($routes[$path])) {
    $page = $routes[$path];
    $template = (string) ($page['template'] ?? 'page');
}

if ($page === null) {
    http_response_code(404);
    $page = ['title' => 'Page not found', 'description' => 'The requested page could not be found.', 'path' => $path, 'section' => '404'];
    $template = '404';
}

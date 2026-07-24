<?php

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
http_response_code(200);
header('Content-Type: application/xml; charset=utf-8');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = preg_replace('/[^a-z0-9.:-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'shop.artdonlighting.com'));
$base = $scheme . '://' . $host . base_path();
$paths = array_merge(['', 'contact'], array_keys($routes), array_map(static fn(array $p): string => 'product/' . $p['sku'], $products));
$paths = array_values(array_unique($paths));
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($paths as $item) {
    if (str_starts_with($item, 'account')) continue;
    $loc = htmlspecialchars(rtrim($base, '/') . '/' . ltrim($item, '/'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    echo "  <url><loc>{$loc}</loc><changefreq>weekly</changefreq><priority>" . ($item === '' ? '1.0' : '0.7') . "</priority></url>\n";
}
echo "</urlset>\n";

<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Keep development-server behavior aligned with the production deny rules.
if (preg_match('#^/(?:config|includes|templates|storage|database|docs|preview|tools)(?:/|$)#i', $uri)
    || preg_match('#(?:^|/)\.(?!well-known(?:/|$))#i', $uri)
    || preg_match('#^/(?:README\.md|LICENSE\.txt|nginx\.conf\.example|router\.php)$#i', $uri)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return true;
}

$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';

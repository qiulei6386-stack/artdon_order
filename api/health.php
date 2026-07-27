<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Singapore');

require_once __DIR__ . '/../includes/database.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

try {
    $pdo = artdon_db_open_ready();
    if ((int) $pdo->query('SELECT 1')->fetchColumn() !== 1) {
        throw new ArtdonDatabaseUnavailable('The application data store is unavailable.');
    }

    $storageDirectory = dirname(__DIR__) . '/storage';
    if (!is_dir($storageDirectory) || !is_writable($storageDirectory)) {
        throw new RuntimeException('Application storage is unavailable.');
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'service' => 'Artdon Procurement Platform',
        'version' => 'V1.0',
        'time' => date(DATE_ATOM),
        'checks' => [
            'database' => 'ready',
            'storage' => 'writable',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('[health] ' . $error->getMessage());
    if (!headers_sent()) {
        header('Retry-After: 5');
    }
    http_response_code(503);
    echo json_encode([
        'status' => 'unavailable',
        'service' => 'Artdon Procurement Platform',
        'time' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/lighting_report.php';

api_require_method('GET');
$requestId = api_request_id();

try {
    $pdo = artdon_db_open_ready();
    $publicId = strtoupper(trim((string) ($_GET['id'] ?? '')));
    $project = artdon_lighting_find_project($publicId, artdon_lighting_session_key_hash(), $pdo);
    if ($project === null) {
        api_respond(404, [
            'success' => false,
            'request_id' => $requestId,
            'message' => 'The simulation report was not found in this session.',
        ]);
    }

    $report = artdon_lighting_ensure_report($project, $pdo);

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . $report['size']);
        header('Content-Disposition: attachment; filename="Artdon-Lighting-Simulation-' . $publicId . '.pdf"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
    }
    readfile($report['absolute']);
    exit;
} catch (ArtdonDatabaseUnavailable $error) {
    error_log(sprintf('[lighting-report:%s] Database is not ready: %s', $requestId, $error->getMessage()));
    if (!headers_sent()) {
        header('Retry-After: 5');
    }
    api_respond(503, [
        'success' => false,
        'request_id' => $requestId,
        'message' => 'The lighting simulation report service is temporarily unavailable.',
    ]);
} catch (InvalidArgumentException $error) {
    api_respond(422, [
        'success' => false,
        'request_id' => $requestId,
        'message' => $error->getMessage(),
    ]);
} catch (Throwable $error) {
    error_log(sprintf('[lighting-report:%s] %s', $requestId, $error->getMessage()));
    api_respond(500, [
        'success' => false,
        'request_id' => $requestId,
        'message' => 'The lighting simulation report could not be generated.',
    ]);
}

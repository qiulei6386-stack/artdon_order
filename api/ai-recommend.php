<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/ai_advisor.php';

api_require_method('POST');

try {
    api_rate_limit('ai-lighting-advice', 30, 60);
    api_verify_csrf();
    $payload = api_json_body();
    $brief = trim((string) ($payload['brief'] ?? ''));
    $advice = artdon_ai_lighting_advice($brief, $products);
    api_respond(200, ['success' => true, 'advice' => $advice]);
} catch (InvalidArgumentException $error) {
    api_respond(422, ['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('AI recommendation error [' . api_request_id() . ']: ' . $error->getMessage());
    api_respond(500, ['success' => false, 'message' => 'The recommendation could not be generated.']);
}

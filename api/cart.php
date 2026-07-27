<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/cart.php';

$requestId = api_request_id();

/**
 * @param array<string,mixed> $extra
 */
function cart_api_meta(string $requestId, array $extra = []): array
{
    return array_merge([
        'request_id' => $requestId,
        'schema_version' => 1,
    ], $extra);
}

/**
 * @param array<string,mixed> $details
 */
function cart_api_error(
    int $status,
    string $code,
    string $message,
    string $requestId,
    array $details = []
): never {
    $error = ['code' => $code, 'message' => $message];
    if ($details !== []) {
        $error['details'] = $details;
    }
    api_respond($status, [
        'success' => false,
        'error' => $error,
        'meta' => cart_api_meta($requestId),
    ]);
}

/**
 * Read a bounded JSON object while keeping every error in the cart API
 * response contract.
 *
 * @return array<string,mixed>
 */
function cart_api_json_body(string $requestId): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'application/json')) {
        cart_api_error(
            415,
            'json_required',
            'Send the request as application/json.',
            $requestId
        );
    }

    $declaredLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declaredLength > ARTDON_CART_MAX_PAYLOAD_BYTES) {
        cart_api_error(
            413,
            'payload_too_large',
            sprintf('The request body cannot exceed %d bytes.', ARTDON_CART_MAX_PAYLOAD_BYTES),
            $requestId,
            ['maximum_bytes' => ARTDON_CART_MAX_PAYLOAD_BYTES]
        );
    }

    $raw = file_get_contents('php://input', false, null, 0, ARTDON_CART_MAX_PAYLOAD_BYTES + 1);
    if ($raw === false) {
        cart_api_error(400, 'invalid_body', 'The request body could not be read.', $requestId);
    }
    if (strlen($raw) > ARTDON_CART_MAX_PAYLOAD_BYTES) {
        cart_api_error(
            413,
            'payload_too_large',
            sprintf('The request body cannot exceed %d bytes.', ARTDON_CART_MAX_PAYLOAD_BYTES),
            $requestId,
            ['maximum_bytes' => ARTDON_CART_MAX_PAYLOAD_BYTES]
        );
    }
    if (trim($raw) === '') {
        cart_api_error(400, 'body_required', 'A JSON request body is required.', $requestId);
    }

    try {
        $payload = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        cart_api_error(400, 'invalid_json', 'The JSON request body is invalid.', $requestId);
    }
    if (!is_array($payload) || array_is_list($payload)) {
        cart_api_error(400, 'invalid_json', 'The JSON request body must be an object.', $requestId);
    }

    return $payload;
}

/**
 * @param array<string,mixed> $payload
 */
function cart_api_verify_csrf(array $payload, string $requestId): void
{
    $provided = (string) (
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $payload['csrf_token']
        ?? ''
    );
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        cart_api_error(
            419,
            'csrf_failed',
            'The form session expired. Refresh the page and try again.',
            $requestId
        );
    }
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        if (!headers_sent()) {
            header('Allow: GET, POST');
        }
        cart_api_error(405, 'method_not_allowed', 'Use GET or POST for this endpoint.', $requestId);
    }
    if ($method === 'POST') {
        api_rate_limit('cart-write', 120, 60);
    }

    $pdo = artdon_db_open_ready();
    $sessionHash = api_session_hash();

    if ($method === 'GET') {
        $cart = artdon_cart_get($sessionHash, $pdo);
        api_respond(200, [
            'success' => true,
            'data' => ['cart' => $cart],
            'meta' => cart_api_meta($requestId),
        ]);
    }

    $payload = cart_api_json_body($requestId);
    cart_api_verify_csrf($payload, $requestId);
    $action = strtolower(trim((string) ($payload['action'] ?? '')));
    if ($action === '') {
        throw new ArtdonCartException(
            'action_required',
            'A cart action is required.',
            400,
            ['field' => 'action']
        );
    }

    $idempotencyKey = artdon_cart_idempotency_key($payload);
    $replay = artdon_cart_idempotency_replay($idempotencyKey, $payload);
    if ($replay !== null) {
        $cart = artdon_cart_get($sessionHash, $pdo);
        api_respond(200, [
            'success' => true,
            'data' => ['cart' => $cart],
            'meta' => cart_api_meta($requestId, [
                'idempotency_key' => $idempotencyKey,
                'idempotency_replayed' => true,
                'original_cart_version' => (int) ($replay['cart_version'] ?? 0),
            ]),
        ]);
    }

    $cart = artdon_cart_mutate(
        $action,
        $payload,
        $sessionHash,
        $pdo,
        $requestId
    );
    artdon_cart_idempotency_remember($idempotencyKey, $payload, $cart);
    api_respond(200, [
        'success' => true,
        'data' => ['cart' => $cart],
        'meta' => cart_api_meta($requestId, [
            'idempotency_key' => $idempotencyKey,
            'idempotency_replayed' => false,
        ]),
    ]);
} catch (ArtdonCartException $error) {
    cart_api_error(
        $error->httpStatus,
        $error->errorCode,
        $error->getMessage(),
        $requestId,
        $error->details
    );
} catch (ArtdonDatabaseUnavailable $error) {
    error_log(sprintf('[cart:%s] Database is not ready: %s', $requestId, $error->getMessage()));
    if (!headers_sent()) {
        header('Retry-After: 5');
    }
    cart_api_error(
        503,
        'cart_storage_unavailable',
        'The Project Cart is temporarily unavailable. Please try again.',
        $requestId
    );
} catch (PDOException $error) {
    error_log(sprintf('[cart:%s] Database error: %s', $requestId, $error->getMessage()));
    if (!headers_sent()) {
        header('Retry-After: 2');
    }
    cart_api_error(
        503,
        'cart_storage_unavailable',
        'The Project Cart is temporarily unavailable. Please try again.',
        $requestId
    );
} catch (Throwable $error) {
    error_log(sprintf('[cart:%s] Unexpected error: %s', $requestId, $error->getMessage()));
    cart_api_error(
        500,
        'cart_error',
        'The Project Cart could not be processed.',
        $requestId
    );
}

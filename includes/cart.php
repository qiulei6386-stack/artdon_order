<?php

declare(strict_types=1);

/**
 * Server-side Project Cart domain service.
 *
 * The browser may cache a cart for responsiveness, but every persisted line is
 * rebuilt from the authoritative product/configuration records before it is
 * written. Client-supplied model numbers, prices, MOQ values, lead times,
 * product snapshots and report paths are never trusted.
 */

final class ArtdonCartException extends RuntimeException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

const ARTDON_CART_MAX_PAYLOAD_BYTES = 65_536;
const ARTDON_CART_MAX_LINES = 100;
const ARTDON_CART_MAX_LINE_QUANTITY = 100_000;
const ARTDON_CART_MAX_TOTAL_QUANTITY = 500_000;
const ARTDON_CART_EXPIRY_DAYS = 30;

function artdon_cart_session_key_hash(?string $sessionId = null): string
{
    if ($sessionId === null && function_exists('api_session_hash')) {
        return api_session_hash();
    }

    $sessionId ??= session_id();
    if ($sessionId === '') {
        throw new ArtdonCartException(
            'session_required',
            'An active session is required.',
            401
        );
    }

    return hash('sha256', 'artdon-session-v1|' . $sessionId);
}

function artdon_cart_public_id(): string
{
    if (function_exists('api_public_id')) {
        return api_public_id('CART');
    }

    return 'CART-' . strtoupper(bin2hex(random_bytes(8)));
}

function artdon_cart_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/**
 * Recursively sort associative keys so hashes are independent of JSON key order.
 */
function artdon_cart_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('artdon_cart_canonicalize', $value);
    }

    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = artdon_cart_canonicalize($item);
    }

    return $value;
}

function artdon_cart_json(mixed $value): string
{
    if (function_exists('artdon_json_encode')) {
        return artdon_json_encode($value);
    }

    return json_encode(
        $value,
        JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

/**
 * @return array<string,mixed>
 */
function artdon_cart_json_decode(?string $value, array $fallback = []): array
{
    if ($value === null || trim($value) === '') {
        return $fallback;
    }

    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Stored cart data is not valid JSON.', 0, $error);
    }

    return is_array($decoded) ? $decoded : $fallback;
}

function artdon_cart_quantity(mixed $value): int
{
    if (is_int($value)) {
        $quantity = $value;
    } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
        $quantity = (int) $value;
    } elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
        $quantity = (int) $value;
    } else {
        throw new ArtdonCartException(
            'invalid_quantity',
            'Quantity must be a whole number.',
            422,
            ['field' => 'quantity']
        );
    }

    if ($quantity < 1 || $quantity > ARTDON_CART_MAX_LINE_QUANTITY) {
        throw new ArtdonCartException(
            'invalid_quantity',
            sprintf('Quantity must be between 1 and %d.', ARTDON_CART_MAX_LINE_QUANTITY),
            422,
            ['field' => 'quantity', 'maximum' => ARTDON_CART_MAX_LINE_QUANTITY]
        );
    }

    return $quantity;
}

function artdon_cart_item_id(mixed $value): int
{
    if (is_int($value)) {
        $itemId = $value;
    } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
        $itemId = (int) $value;
    } else {
        $itemId = 0;
    }

    if ($itemId < 1) {
        throw new ArtdonCartException(
            'invalid_item_id',
            'A valid cart item ID is required.',
            422,
            ['field' => 'item_id']
        );
    }

    return $itemId;
}

function artdon_cart_expected_version(array $payload): ?int
{
    if (!array_key_exists('expected_version', $payload) || $payload['expected_version'] === null) {
        return null;
    }

    $value = $payload['expected_version'];
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
        return (int) $value;
    }

    throw new ArtdonCartException(
        'invalid_cart_version',
        'expected_version must be a positive integer.',
        422,
        ['field' => 'expected_version']
    );
}

/**
 * Load or create the active cart for one anonymous browser session.
 *
 * @return array<string,mixed>
 */
function artdon_cart_find_or_create(PDO $pdo, string $sessionHash): array
{
    if (preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1) {
        throw new InvalidArgumentException('The session hash is invalid.');
    }

    $now = artdon_db_now();
    $expireOld = $pdo->prepare(
        "UPDATE project_carts
         SET status = 'expired', updated_at = :now
         WHERE session_key_hash = :session_key_hash
           AND status = 'active'
           AND expires_at IS NOT NULL
           AND expires_at <= :now"
    );
    $expireOld->execute([
        ':session_key_hash' => $sessionHash,
        ':now' => $now,
    ]);

    $find = $pdo->prepare(
        "SELECT *
         FROM project_carts
         WHERE session_key_hash = :session_key_hash
           AND status = 'active'
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $find->execute([':session_key_hash' => $sessionHash]);
    $cart = $find->fetch();
    if (is_array($cart)) {
        return $cart;
    }

    $publicId = artdon_cart_public_id();
    $insert = $pdo->prepare(
        'INSERT INTO project_carts (
            public_id, session_key_hash, project_name, currency, status,
            version, expires_at, created_at, updated_at
         ) VALUES (
            :public_id, :session_key_hash, :project_name, :currency, :status,
            1, :expires_at, :created_at, :updated_at
         )'
    );
    $insert->execute([
        ':public_id' => $publicId,
        ':session_key_hash' => $sessionHash,
        ':project_name' => '',
        ':currency' => 'USD',
        ':status' => 'active',
        ':expires_at' => gmdate('Y-m-d H:i:s', time() + (ARTDON_CART_EXPIRY_DAYS * 86400)),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $findById = $pdo->prepare('SELECT * FROM project_carts WHERE id = :id');
    $findById->execute([':id' => (int) $pdo->lastInsertId()]);
    $cart = $findById->fetch();
    if (!is_array($cart)) {
        throw new RuntimeException('Unable to create the Project Cart.');
    }

    return $cart;
}

/**
 * Return a cart without mutating its version.
 *
 * @return array<string,mixed>
 */
function artdon_cart_get(string $sessionHash, ?PDO $pdo = null): array
{
    $pdo ??= artdon_db();
    if (preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1) {
        throw new InvalidArgumentException('The session hash is invalid.');
    }

    /*
     * A read-only visitor must not create a durable database row. The first
     * cart mutation creates the real cart; until then this virtual cart keeps
     * the existing browser/API contract and version handshake.
     */
    $statement = $pdo->prepare(
        "SELECT *
         FROM project_carts
         WHERE session_key_hash = :session_key_hash
           AND status = 'active'
           AND (expires_at IS NULL OR expires_at > :now)
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $statement->execute([
        ':session_key_hash' => $sessionHash,
        ':now' => artdon_db_now(),
    ]);
    $row = $statement->fetch();
    if (is_array($row)) {
        return artdon_cart_hydrate($pdo, $row);
    }

    return [
        'public_id' => '',
        'project_name' => '',
        'currency' => 'USD',
        'status' => 'active',
        'version' => 1,
        'items' => [],
        'summary' => [
            'line_count' => 0,
            'total_quantity' => 0,
            'priced_subtotal' => 0.0,
            'has_review_items' => false,
        ],
        'expires_at' => null,
    ];
}

/**
 * Build one authoritative configured-product snapshot.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function artdon_cart_resolve_item(
    PDO $pdo,
    array $input,
    string $sessionHash,
    ?int $quantityOverride = null
): array {
    $sku = trim((string) (
        $input['sku']
        ?? $input['product_id']
        ?? $input['base_sku']
        ?? ''
    ));
    if ($sku === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $sku) !== 1) {
        throw new ArtdonCartException(
            'invalid_product',
            'A valid base product SKU is required.',
            422,
            ['field' => 'sku']
        );
    }

    $quantity = $quantityOverride ?? artdon_cart_quantity($input['quantity'] ?? $input['qty'] ?? 1);
    $configuration = $input['configuration'] ?? $input['selections'] ?? [];
    if (!is_array($configuration) || ($configuration !== [] && array_is_list($configuration))) {
        throw new ArtdonCartException(
            'invalid_configuration',
            'configuration must be an object of option codes and values.',
            422,
            ['field' => 'configuration']
        );
    }

    $note = trim((string) ($input['customer_note'] ?? $input['note'] ?? ''));
    if (artdon_cart_text_length($note) > 1000) {
        throw new ArtdonCartException(
            'note_too_long',
            'The item note cannot exceed 1,000 characters.',
            422,
            ['field' => 'customer_note', 'maximum' => 1000]
        );
    }

    if (strlen(artdon_cart_json($configuration)) > 20_000) {
        throw new ArtdonCartException(
            'configuration_too_large',
            'The product configuration is too large.',
            422,
            ['field' => 'configuration', 'maximum_bytes' => 20_000]
        );
    }

    $configured = artdon_cart_configure_product($pdo, $sku, $configuration, $quantity);
    $product = $configured['product'];
    $moq = $configured['moq'] ?? $product['moq'] ?? 1;
    if (!is_numeric($moq) || !is_finite((float) $moq) || (float) $moq <= 0) {
        throw new RuntimeException('The product MOQ is invalid.');
    }
    $minimum = max(1, (int) ceil((float) $moq));
    if ($quantity < $minimum) {
        throw new ArtdonCartException(
            'below_moq',
            sprintf('The minimum order quantity for %s is %d.', (string) $product['sku'], $minimum),
            422,
            ['field' => 'quantity', 'sku' => (string) $product['sku'], 'minimum' => $minimum]
        );
    }

    $canonicalConfiguration = artdon_cart_canonicalize((array) $configured['configuration']);
    $configuredModel = trim((string) $configured['configured_model']);
    if (
        $configuredModel === ''
        || artdon_cart_text_length($configuredModel) > 180
        || preg_match('/[\x00-\x1F\x7F]/', $configuredModel) === 1
    ) {
        throw new RuntimeException('The configured model is invalid.');
    }
    $currency = strtoupper(trim((string) ($configured['currency'] ?? 'USD')));
    if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
        throw new RuntimeException('The configured currency is invalid.');
    }
    $unitPrice = $configured['unit_price'];
    if (
        $unitPrice !== null
        && (!is_numeric($unitPrice) || !is_finite((float) $unitPrice) || (float) $unitPrice < 0)
    ) {
        throw new RuntimeException('The configured unit price is invalid.');
    }
    $simulation = artdon_cart_resolve_simulation(
        $pdo,
        $input['simulation_project_id'] ?? null,
        $input['simulation_report_path'] ?? $input['report'] ?? null,
        $sessionHash,
        (int) $product['id'],
        $canonicalConfiguration
    );

    $productSnapshot = [
        'id' => (int) $product['id'],
        'sku' => (string) $product['sku'],
        'name' => (string) $product['name'],
        'series' => (string) ($product['series'] ?? $product['series_code'] ?? ''),
        'category' => (string) ($product['category'] ?? $product['category_slug'] ?? ''),
        'image' => (string) ($product['image'] ?? $product['image_path'] ?? ''),
        'source_system' => (string) ($product['source_system'] ?? ''),
        'source_id' => (string) ($product['source_id'] ?? ''),
        'moq' => $minimum,
    ];
    $hashPayload = [
        'product_id' => (int) $product['id'],
        'configured_model' => $configuredModel,
        'configuration' => $canonicalConfiguration,
        'customer_note' => $note,
        'simulation_public_id' => $simulation['snapshot']['public_id'] ?? null,
    ];

    return [
        'product_id' => (int) $product['id'],
        'product_snapshot' => $productSnapshot,
        'configuration' => $canonicalConfiguration,
        'configuration_hash' => hash('sha256', artdon_cart_json(artdon_cart_canonicalize($hashPayload))),
        'configured_model' => $configuredModel,
        'quantity' => $quantity,
        'unit_price' => $unitPrice === null ? null : round((float) $unitPrice, 2),
        'price_mode' => (string) $configured['price_mode'],
        'currency' => $currency,
        'lead_time_text' => (string) ($configured['lead_time_text'] ?? $product['lead_time'] ?? ''),
        'customer_note' => $note,
        'simulation_project_id' => $simulation['id'] ?? null,
        'simulation_snapshot' => $simulation['snapshot'] ?? null,
        'simulation_report_path' => $simulation['report_path'] ?? null,
    ];
}

/**
 * Delegate to the shared configurator when available, otherwise apply the same
 * schema format locally. The fallback keeps this module independently testable
 * while the configurator module is rolled out.
 *
 * @param array<string,mixed> $configuration
 * @return array<string,mixed>
 */
function artdon_cart_configure_product(
    PDO $pdo,
    string $sku,
    array $configuration,
    int $quantity
): array {
    $configuratorFile = __DIR__ . '/configurator.php';
    if (is_file($configuratorFile)) {
        require_once $configuratorFile;
    }

    if (function_exists('artdon_configurator_configure')) {
        /** @var mixed $result */
        $result = artdon_configurator_configure($sku, $configuration, $quantity, $pdo);
        if (!is_array($result)) {
            throw new RuntimeException('The configurator returned an invalid result.');
        }

        return artdon_cart_normalize_configurator_result($pdo, $sku, $configuration, $quantity, $result);
    }

    return artdon_cart_configure_fallback($pdo, $sku, $configuration, $quantity);
}

/**
 * @param array<string,mixed> $requestedConfiguration
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function artdon_cart_normalize_configurator_result(
    PDO $pdo,
    string $sku,
    array $requestedConfiguration,
    int $quantity,
    array $result
): array {
    if (($result['valid'] ?? true) === false || ($result['success'] ?? true) === false) {
        $messages = $result['errors'] ?? $result['messages'] ?? [];
        $sourceCode = (string) (
            $result['code']
            ?? $result['error_details'][0]['code']
            ?? 'configuration_not_allowed'
        );
        $mappedCode = match ($sourceCode) {
            'product_not_found' => 'product_not_found',
            'product_not_orderable' => 'product_not_orderable',
            'invalid_quantity' => 'invalid_quantity',
            'below_moq' => 'below_moq',
            'unknown_option' => 'unknown_configuration_option',
            'invalid_option_value' => 'invalid_configuration_value',
            default => 'configuration_not_allowed',
        };
        $status = $mappedCode === 'product_not_found' ? 404 : 422;
        throw new ArtdonCartException(
            $mappedCode,
            is_array($messages) && $messages !== []
                ? implode(' ', array_map('strval', $messages))
                : 'The selected configuration is not allowed.',
            $status,
            [
                'errors' => is_array($messages) ? array_values($messages) : [],
                'configurator_code' => $sourceCode,
            ]
        );
    }

    $product = $result['product'] ?? artdon_catalog_find_by_sku($sku, $pdo);
    if (!is_array($product)) {
        throw new ArtdonCartException('product_not_found', 'The selected product was not found.', 404);
    }
    if (
        !isset($product['id'], $product['sku'], $product['name'])
        || (string) ($product['status'] ?? '') !== 'active'
    ) {
        throw new ArtdonCartException(
            'product_not_found',
            'The selected product is not available.',
            404,
            ['sku' => $sku]
        );
    }
    if (empty($product['order_enabled'])) {
        throw new ArtdonCartException(
            'product_not_orderable',
            'The selected product cannot currently be ordered.',
            422,
            ['sku' => (string) $product['sku']]
        );
    }

    $resolvedConfiguration = $result['configuration']
        ?? $result['selections']
        ?? $result['selection']
        ?? $requestedConfiguration;
    if (!is_array($resolvedConfiguration)) {
        throw new RuntimeException('The configurator did not return a valid configuration.');
    }

    $configuredModel = trim((string) (
        $result['configured_model']
        ?? $result['model']
        ?? $result['configured_sku']
        ?? ''
    ));
    if ($configuredModel === '') {
        throw new RuntimeException('The configurator did not return a configured model.');
    }

    $priceMode = (string) (
        $result['price_mode']
        ?? $product['price_mode']
        ?? 'review'
    );
    if (!in_array($priceMode, ['fixed', 'from', 'review'], true)) {
        throw new RuntimeException('The configurator returned an invalid price mode.');
    }
    $unitPrice = $result['unit_price'] ?? $result['price'] ?? null;
    if ($priceMode === 'review') {
        $unitPrice = null;
    } elseif (!is_numeric($unitPrice) || (float) $unitPrice < 0) {
        throw new RuntimeException('The configurator returned an invalid unit price.');
    }

    return [
        'product' => $product,
        'configuration' => $resolvedConfiguration,
        'configured_model' => $configuredModel,
        'quantity' => $quantity,
        'unit_price' => $unitPrice === null ? null : (float) $unitPrice,
        'price_mode' => $priceMode,
        'currency' => (string) ($result['currency'] ?? $product['base_currency'] ?? 'USD'),
        'moq' => (float) ($result['moq'] ?? $product['moq'] ?? $product['default_moq'] ?? 1),
        'lead_time_text' => (string) (
            $result['lead_time_text']
            ?? $result['lead_time']
            ?? $product['lead_time']
            ?? ''
        ),
    ];
}

/**
 * @param array<string,mixed> $configuration
 * @return array<string,mixed>
 */
function artdon_cart_configure_fallback(
    PDO $pdo,
    string $sku,
    array $configuration,
    int $quantity
): array {
    $product = artdon_catalog_find_by_sku($sku, $pdo);
    if ($product === null || (string) ($product['status'] ?? '') !== 'active') {
        throw new ArtdonCartException(
            'product_not_found',
            'The selected product is not available.',
            404,
            ['sku' => $sku]
        );
    }
    if (empty($product['order_enabled'])) {
        throw new ArtdonCartException(
            'product_not_orderable',
            'The selected product cannot currently be ordered.',
            422,
            ['sku' => (string) $product['sku']]
        );
    }

    $schema = artdon_catalog_configuration_schema((int) $product['id'], $pdo) ?? [];
    $options = is_array($schema['options'] ?? null) ? $schema['options'] : [];
    $knownCodes = [];
    $selected = [];
    $selectedValues = [];
    foreach ($options as $option) {
        if (!is_array($option) || trim((string) ($option['code'] ?? '')) === '') {
            continue;
        }
        $code = (string) $option['code'];
        $knownCodes[$code] = true;
        $values = is_array($option['values'] ?? null) ? $option['values'] : [];
        if (
            array_key_exists($code, $configuration)
            && !is_scalar($configuration[$code])
        ) {
            throw new ArtdonCartException(
                'invalid_configuration_value',
                sprintf('The value selected for %s is invalid.', $code),
                422,
                ['field' => 'configuration.' . $code]
            );
        }
        $requested = array_key_exists($code, $configuration)
            ? (string) $configuration[$code]
            : null;
        $chosen = null;
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($requested !== null && (string) ($value['code'] ?? '') === $requested) {
                $chosen = $value;
                break;
            }
            if ($requested === null && !empty($value['default']) && $chosen === null) {
                $chosen = $value;
            }
        }
        if ($requested === null && $chosen === null && isset($values[0]) && is_array($values[0])) {
            $chosen = $values[0];
        }
        if ($chosen === null) {
            throw new ArtdonCartException(
                'invalid_configuration_value',
                sprintf('The value selected for %s is not available.', $code),
                422,
                ['field' => 'configuration.' . $code, 'option' => $code, 'value' => $requested]
            );
        }
        $selected[$code] = (string) ($chosen['code'] ?? '');
        $selectedValues[$code] = $chosen;
    }

    foreach (array_keys($configuration) as $code) {
        if (!is_string($code) || !isset($knownCodes[$code])) {
            throw new ArtdonCartException(
                'unknown_configuration_option',
                sprintf('The configuration option "%s" is not available for this product.', (string) $code),
                422,
                ['field' => 'configuration.' . (string) $code, 'option' => (string) $code]
            );
        }
        if (!is_scalar($configuration[$code]) && $configuration[$code] !== null) {
            throw new ArtdonCartException(
                'invalid_configuration_value',
                sprintf('The value selected for %s is invalid.', (string) $code),
                422,
                ['field' => 'configuration.' . (string) $code]
            );
        }
    }

    $ruleMessages = [];
    foreach ((array) ($schema['rules'] ?? []) as $rule) {
        if (!is_array($rule) || (string) ($rule['type'] ?? '') !== 'deny') {
            continue;
        }
        $matches = true;
        foreach ((array) ($rule['when'] ?? []) as $code => $value) {
            if (($selected[(string) $code] ?? null) !== (string) $value) {
                $matches = false;
                break;
            }
        }
        $optionCode = (string) ($rule['option'] ?? '');
        if (
            $matches
            && $optionCode !== ''
            && ($selected[$optionCode] ?? null) === (string) ($rule['value'] ?? '')
        ) {
            $ruleMessages[] = (string) ($rule['message'] ?? 'The selected option combination is not available.');
        }
    }
    if ($ruleMessages !== []) {
        throw new ArtdonCartException(
            'configuration_not_allowed',
            implode(' ', array_values(array_unique($ruleMessages))),
            422,
            ['errors' => array_values(array_unique($ruleMessages))]
        );
    }

    $parts = [];
    foreach ((array) ($schema['sku_order'] ?? ['series']) as $part) {
        $part = (string) $part;
        if ($part === 'series') {
            $value = (string) ($product['series'] ?? $product['sku']);
        } else {
            $value = (string) ($selectedValues[$part]['sku'] ?? $selected[$part] ?? '');
        }
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    $configuredModel = implode('-', $parts);
    if ($configuredModel === '') {
        $configuredModel = (string) $product['sku'];
    }

    $priceMode = (string) ($schema['price_mode'] ?? $product['price_mode'] ?? 'review');
    if (!in_array($priceMode, ['fixed', 'from', 'review'], true)) {
        $priceMode = 'review';
    }
    $unitPrice = null;
    if ($priceMode !== 'review') {
        $basePrice = $product['price'] ?? $product['base_price'] ?? null;
        if (!is_numeric($basePrice) || (float) $basePrice < 0) {
            throw new ArtdonCartException(
                'price_unavailable',
                'This product requires a price review before it can be ordered.',
                422,
                ['sku' => (string) $product['sku']]
            );
        }
        $unitPrice = (float) $basePrice;
        foreach ($selectedValues as $value) {
            if (isset($value['price_delta']) && is_numeric($value['price_delta'])) {
                $unitPrice += (float) $value['price_delta'];
            }
        }
        if (!is_finite($unitPrice) || $unitPrice < 0) {
            throw new RuntimeException('The configured unit price is invalid.');
        }
        $unitPrice = round($unitPrice, 2);
    }

    return [
        'product' => $product,
        'configuration' => $selected,
        'configured_model' => $configuredModel,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'price_mode' => $priceMode,
        'currency' => (string) ($product['base_currency'] ?? 'USD'),
        'moq' => (float) ($product['moq'] ?? $product['default_moq'] ?? 1),
        'lead_time_text' => (string) ($product['lead_time'] ?? $product['lead_time_text'] ?? ''),
    ];
}

/**
 * Validate that a completed simulation belongs to the current browser session.
 *
 * The API accepts the simulation public ID, never a database primary key.
 * Report paths are always read from the database and confined to
 * storage/reports.
 *
 * @return array{id:int,snapshot:array<string,mixed>,report_path:?string}|array{}
 */
function artdon_cart_resolve_simulation(
    PDO $pdo,
    mixed $publicId,
    mixed $requestedReport,
    string $sessionHash,
    int $productId,
    array $cartConfiguration
): array {
    if ($publicId === null || trim((string) $publicId) === '') {
        if ($requestedReport !== null && trim((string) $requestedReport) !== '') {
            throw new ArtdonCartException(
                'simulation_required',
                'A simulation project ID is required when attaching a report.',
                422,
                ['field' => 'simulation_project_id']
            );
        }
        return [];
    }

    $publicId = trim((string) $publicId);
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{5,79}$/', $publicId) !== 1) {
        throw new ArtdonCartException(
            'invalid_simulation',
            'The simulation project ID is invalid.',
            422,
            ['field' => 'simulation_project_id']
        );
    }

    $statement = $pdo->prepare(
        "SELECT sp.*, il.option_signature AS ies_option_signature
         FROM simulation_projects sp
         INNER JOIN ies_library il ON il.id = sp.ies_library_id
         WHERE sp.public_id = :public_id
           AND sp.session_key_hash = :session_key_hash
           AND sp.status = 'completed'
         LIMIT 1"
    );
    $statement->execute([
        ':public_id' => $publicId,
        ':session_key_hash' => $sessionHash,
    ]);
    $simulation = $statement->fetch();
    if (!is_array($simulation)) {
        throw new ArtdonCartException(
            'simulation_not_found',
            'The completed simulation was not found for this session.',
            404,
            ['simulation_project_id' => $publicId]
        );
    }
    if ((int) $simulation['product_id'] !== $productId) {
        throw new ArtdonCartException(
            'simulation_product_mismatch',
            'The simulation belongs to a different product.',
            422,
            ['simulation_project_id' => $publicId]
        );
    }
    $simulationConfiguration = artdon_cart_json_decode(
        (string) ($simulation['configuration_snapshot_json'] ?? '{}')
    );
    $profileBinding = artdon_cart_json_decode(
        (string) ($simulation['ies_option_signature'] ?? '{}')
    );
    foreach ($profileBinding as $option => $expected) {
        if (
            !array_key_exists((string) $option, $simulationConfiguration)
            || !is_scalar($simulationConfiguration[(string) $option])
            || (string) $simulationConfiguration[(string) $option] !== (string) $expected
        ) {
            throw new ArtdonCartException(
                'simulation_configuration_mismatch',
                'The simulation optical binding is inconsistent with its saved product configuration.',
                422,
                ['simulation_project_id' => $publicId, 'option' => (string) $option]
            );
        }
    }
    if (
        count($simulationConfiguration) !== count($cartConfiguration)
        || array_diff_key($simulationConfiguration, $cartConfiguration) !== []
        || array_diff_key($cartConfiguration, $simulationConfiguration) !== []
    ) {
        throw new ArtdonCartException(
            'simulation_configuration_mismatch',
            'The simulation and Project Cart must use the same complete product configuration.',
            422,
            ['simulation_project_id' => $publicId]
        );
    }
    foreach ($simulationConfiguration as $option => $value) {
        $option = (string) $option;
        if (
            !is_scalar($value)
            || !is_scalar($cartConfiguration[$option])
            || (string) $cartConfiguration[$option] !== (string) $value
        ) {
            throw new ArtdonCartException(
                'simulation_configuration_mismatch',
                sprintf(
                    'The simulation uses a different %s configuration.',
                    $option
                ),
                422,
                ['simulation_project_id' => $publicId, 'option' => $option]
            );
        }
    }

    $reportPath = artdon_cart_report_path((string) ($simulation['report_path'] ?? ''));
    $storedChecksum = strtolower(trim((string) ($simulation['report_checksum_sha256'] ?? '')));
    if ($reportPath === null
        || preg_match('/^[a-f0-9]{64}$/', $storedChecksum) !== 1
        || !hash_equals(
            $storedChecksum,
            (string) hash_file('sha256', dirname(__DIR__) . '/' . $reportPath)
        )
    ) {
        throw new ArtdonCartException(
            'simulation_report_unavailable',
            'Generate the verified simulation report before adding this result to the Project Cart.',
            422
        );
    }
    if ($requestedReport !== null && trim((string) $requestedReport) !== '') {
        $requested = trim((string) $requestedReport);
        $allowedReferences = array_filter([
            $reportPath,
            $reportPath !== null ? basename($reportPath) : null,
            $publicId,
            '/api/lighting-report.php?id=' . rawurlencode($publicId),
        ]);
        if (!in_array($requested, $allowedReferences, true)) {
            throw new ArtdonCartException(
                'invalid_simulation_report',
                'The report reference does not belong to the selected simulation.',
                422,
                ['field' => 'simulation_report_path']
            );
        }
    }

    $snapshot = [
        'public_id' => (string) $simulation['public_id'],
        'project_name' => (string) ($simulation['project_name'] ?? ''),
        'room_type' => (string) $simulation['room_type'],
        'room' => [
            'length_m' => (float) $simulation['room_length_m'],
            'width_m' => (float) $simulation['room_width_m'],
            'height_m' => (float) $simulation['room_height_m'],
            'installation_height_m' => (float) $simulation['installation_height_m'],
        ],
        'target_lux' => (float) $simulation['target_lux'],
        'configuration' => $simulationConfiguration,
        'quantity' => (int) $simulation['fixture_quantity'],
        'average_lux' => $simulation['average_lux'] === null ? null : (float) $simulation['average_lux'],
        'maximum_lux' => $simulation['maximum_lux'] === null ? null : (float) $simulation['maximum_lux'],
        'minimum_lux' => $simulation['minimum_lux'] === null ? null : (float) $simulation['minimum_lux'],
        'uniformity' => $simulation['uniformity'] === null ? null : (float) $simulation['uniformity'],
        'report_checksum_sha256' => (string) ($simulation['report_checksum_sha256'] ?? ''),
    ];

    return [
        'id' => (int) $simulation['id'],
        'snapshot' => $snapshot,
        'report_path' => $reportPath,
    ];
}

function artdon_cart_report_path(string $storedPath): ?string
{
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '') {
        return null;
    }

    $root = dirname(__DIR__);
    $reportsRoot = $root . '/storage/reports';
    $candidate = str_starts_with($storedPath, '/')
        ? $storedPath
        : $root . '/' . ltrim($storedPath, '/');
    $realReportsRoot = realpath($reportsRoot);
    $realCandidate = realpath($candidate);
    if (
        $realReportsRoot === false
        || $realCandidate === false
        || !is_file($realCandidate)
        || !str_starts_with($realCandidate, $realReportsRoot . DIRECTORY_SEPARATOR)
    ) {
        throw new ArtdonCartException(
            'simulation_report_unavailable',
            'The simulation report is not available.',
            422
        );
    }

    return 'storage/reports/' . ltrim(
        str_replace('\\', '/', substr($realCandidate, strlen($realReportsRoot))),
        '/'
    );
}

/**
 * @param array<string,mixed> $resolved
 */
function artdon_cart_insert_item(PDO $pdo, int $cartId, array $resolved, int $sortOrder): int
{
    $now = artdon_db_now();
    $statement = $pdo->prepare(
        'INSERT INTO project_cart_items (
            cart_id, product_id, configured_model, product_snapshot_json,
            configuration_json, configuration_hash, quantity, unit_price,
            price_mode, currency, lead_time_text, customer_note,
            simulation_project_id, simulation_snapshot_json,
            simulation_report_path, sort_order, created_at, updated_at
         ) VALUES (
            :cart_id, :product_id, :configured_model, :product_snapshot_json,
            :configuration_json, :configuration_hash, :quantity, :unit_price,
            :price_mode, :currency, :lead_time_text, :customer_note,
            :simulation_project_id, :simulation_snapshot_json,
            :simulation_report_path, :sort_order, :created_at, :updated_at
         )'
    );
    $statement->execute([
        ':cart_id' => $cartId,
        ':product_id' => $resolved['product_id'],
        ':configured_model' => $resolved['configured_model'],
        ':product_snapshot_json' => artdon_cart_json($resolved['product_snapshot']),
        ':configuration_json' => artdon_cart_json($resolved['configuration']),
        ':configuration_hash' => $resolved['configuration_hash'],
        ':quantity' => $resolved['quantity'],
        ':unit_price' => $resolved['unit_price'],
        ':price_mode' => $resolved['price_mode'],
        ':currency' => $resolved['currency'],
        ':lead_time_text' => $resolved['lead_time_text'],
        ':customer_note' => $resolved['customer_note'],
        ':simulation_project_id' => $resolved['simulation_project_id'],
        ':simulation_snapshot_json' => $resolved['simulation_snapshot'] === null
            ? null
            : artdon_cart_json($resolved['simulation_snapshot']),
        ':simulation_report_path' => $resolved['simulation_report_path'],
        ':sort_order' => $sortOrder,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @param array<string,mixed> $resolved
 */
function artdon_cart_update_item(PDO $pdo, int $cartId, int $itemId, array $resolved): void
{
    $statement = $pdo->prepare(
        'UPDATE project_cart_items
         SET product_id = :product_id,
             configured_model = :configured_model,
             product_snapshot_json = :product_snapshot_json,
             configuration_json = :configuration_json,
             configuration_hash = :configuration_hash,
             quantity = :quantity,
             unit_price = :unit_price,
             price_mode = :price_mode,
             currency = :currency,
             lead_time_text = :lead_time_text,
             customer_note = :customer_note,
             simulation_project_id = :simulation_project_id,
             simulation_snapshot_json = :simulation_snapshot_json,
             simulation_report_path = :simulation_report_path,
             updated_at = :updated_at
         WHERE id = :id AND cart_id = :cart_id'
    );
    $statement->execute([
        ':product_id' => $resolved['product_id'],
        ':configured_model' => $resolved['configured_model'],
        ':product_snapshot_json' => artdon_cart_json($resolved['product_snapshot']),
        ':configuration_json' => artdon_cart_json($resolved['configuration']),
        ':configuration_hash' => $resolved['configuration_hash'],
        ':quantity' => $resolved['quantity'],
        ':unit_price' => $resolved['unit_price'],
        ':price_mode' => $resolved['price_mode'],
        ':currency' => $resolved['currency'],
        ':lead_time_text' => $resolved['lead_time_text'],
        ':customer_note' => $resolved['customer_note'],
        ':simulation_project_id' => $resolved['simulation_project_id'],
        ':simulation_snapshot_json' => $resolved['simulation_snapshot'] === null
            ? null
            : artdon_cart_json($resolved['simulation_snapshot']),
        ':simulation_report_path' => $resolved['simulation_report_path'],
        ':updated_at' => artdon_db_now(),
        ':id' => $itemId,
        ':cart_id' => $cartId,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new ArtdonCartException('item_not_found', 'The cart item was not found.', 404);
    }
}

/**
 * @return array<string,mixed>
 */
function artdon_cart_raw_item(PDO $pdo, int $cartId, int $itemId): array
{
    $statement = $pdo->prepare(
        'SELECT * FROM project_cart_items WHERE id = :id AND cart_id = :cart_id LIMIT 1'
    );
    $statement->execute([':id' => $itemId, ':cart_id' => $cartId]);
    $item = $statement->fetch();
    if (!is_array($item)) {
        throw new ArtdonCartException('item_not_found', 'The cart item was not found.', 404);
    }

    return $item;
}

/**
 * Reconstruct accepted input from a stored line, then run it through the
 * current catalog/configuration rules again.
 *
 * @return array<string,mixed>
 */
function artdon_cart_input_from_row(array $row): array
{
    $product = artdon_cart_json_decode((string) $row['product_snapshot_json']);
    $simulation = artdon_cart_json_decode($row['simulation_snapshot_json'] ?? null);

    return [
        'sku' => (string) ($product['sku'] ?? ''),
        'configuration' => artdon_cart_json_decode((string) $row['configuration_json']),
        'quantity' => (int) $row['quantity'],
        'customer_note' => (string) ($row['customer_note'] ?? ''),
        'simulation_project_id' => $simulation['public_id'] ?? null,
    ];
}

function artdon_cart_next_sort_order(PDO $pdo, int $cartId): int
{
    $statement = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM project_cart_items WHERE cart_id = :cart_id'
    );
    $statement->execute([':cart_id' => $cartId]);
    return (int) $statement->fetchColumn();
}

function artdon_cart_assert_capacity(PDO $pdo, int $cartId): void
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS lines, COALESCE(SUM(quantity), 0) AS quantity
         FROM project_cart_items
         WHERE cart_id = :cart_id'
    );
    $statement->execute([':cart_id' => $cartId]);
    $totals = $statement->fetch();
    $lines = (int) ($totals['lines'] ?? 0);
    $quantity = (int) ($totals['quantity'] ?? 0);
    if ($lines > ARTDON_CART_MAX_LINES) {
        throw new ArtdonCartException(
            'cart_line_limit',
            sprintf('A Project Cart can contain at most %d product lines.', ARTDON_CART_MAX_LINES),
            422,
            ['maximum' => ARTDON_CART_MAX_LINES]
        );
    }
    if ($quantity > ARTDON_CART_MAX_TOTAL_QUANTITY) {
        throw new ArtdonCartException(
            'cart_quantity_limit',
            sprintf(
                'A Project Cart can contain at most %d total units.',
                ARTDON_CART_MAX_TOTAL_QUANTITY
            ),
            422,
            ['maximum' => ARTDON_CART_MAX_TOTAL_QUANTITY]
        );
    }
}

/**
 * Increment the cart version using optimistic concurrency control.
 *
 * @return array<string,mixed>
 */
function artdon_cart_touch(
    PDO $pdo,
    array $cart,
    ?string $projectName = null
): array {
    $now = artdon_db_now();
    $nextVersion = (int) $cart['version'] + 1;
    $statement = $pdo->prepare(
        'UPDATE project_carts
         SET project_name = :project_name,
             version = :next_version,
             expires_at = :expires_at,
             updated_at = :updated_at
         WHERE id = :id
           AND status = :status
           AND version = :current_version'
    );
    $statement->execute([
        ':project_name' => $projectName ?? (string) $cart['project_name'],
        ':next_version' => $nextVersion,
        ':expires_at' => gmdate('Y-m-d H:i:s', time() + (ARTDON_CART_EXPIRY_DAYS * 86400)),
        ':updated_at' => $now,
        ':id' => (int) $cart['id'],
        ':status' => 'active',
        ':current_version' => (int) $cart['version'],
    ]);
    if ($statement->rowCount() !== 1) {
        throw new ArtdonCartException(
            'cart_version_conflict',
            'The Project Cart changed in another request. Reload it and try again.',
            409
        );
    }

    $cart['version'] = $nextVersion;
    $cart['project_name'] = $projectName ?? (string) $cart['project_name'];
    $cart['updated_at'] = $now;
    return $cart;
}

/**
 * Apply one cart action atomically.
 *
 * Supported actions: add, replace, update, remove, duplicate, clear.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function artdon_cart_mutate(
    string $action,
    array $payload,
    string $sessionHash,
    ?PDO $pdo = null,
    ?string $requestId = null
): array {
    $pdo ??= artdon_db();
    $action = strtolower(trim($action));
    if (!in_array($action, ['add', 'replace', 'update', 'remove', 'duplicate', 'clear'], true)) {
        throw new ArtdonCartException(
            'unsupported_action',
            'The requested cart action is not supported.',
            400,
            ['action' => $action]
        );
    }

    return artdon_db_transaction(
        static function (PDO $pdo) use ($action, $payload, $sessionHash, $requestId): array {
            $cart = artdon_cart_find_or_create($pdo, $sessionHash);
            $expectedVersion = artdon_cart_expected_version($payload);
            if ($expectedVersion !== null && $expectedVersion !== (int) $cart['version']) {
                throw new ArtdonCartException(
                    'cart_version_conflict',
                    'The Project Cart changed in another request. Reload it and try again.',
                    409,
                    [
                        'expected_version' => $expectedVersion,
                        'current_version' => (int) $cart['version'],
                    ]
                );
            }

            $cartId = (int) $cart['id'];
            if ($action === 'add') {
                $itemInput = $payload['item'] ?? null;
                if (!is_array($itemInput)) {
                    throw new ArtdonCartException(
                        'item_required',
                        'An item object is required.',
                        422,
                        ['field' => 'item']
                    );
                }
                $resolved = artdon_cart_resolve_item($pdo, $itemInput, $sessionHash);
                $same = $pdo->prepare(
                    'SELECT id, quantity
                     FROM project_cart_items
                     WHERE cart_id = :cart_id AND configuration_hash = :configuration_hash
                     ORDER BY id
                     LIMIT 1'
                );
                $same->execute([
                    ':cart_id' => $cartId,
                    ':configuration_hash' => $resolved['configuration_hash'],
                ]);
                $existing = $same->fetch();
                if (is_array($existing)) {
                    $combined = artdon_cart_quantity(
                        (int) $existing['quantity'] + (int) $resolved['quantity']
                    );
                    $resolved['quantity'] = $combined;
                    artdon_cart_update_item($pdo, $cartId, (int) $existing['id'], $resolved);
                } else {
                    artdon_cart_insert_item(
                        $pdo,
                        $cartId,
                        $resolved,
                        artdon_cart_next_sort_order($pdo, $cartId)
                    );
                }
            } elseif ($action === 'replace') {
                $items = $payload['items'] ?? null;
                if (!is_array($items) || !array_is_list($items)) {
                    throw new ArtdonCartException(
                        'items_required',
                        'items must be an array.',
                        422,
                        ['field' => 'items']
                    );
                }
                if (count($items) > ARTDON_CART_MAX_LINES) {
                    throw new ArtdonCartException(
                        'cart_line_limit',
                        sprintf('A Project Cart can contain at most %d product lines.', ARTDON_CART_MAX_LINES),
                        422,
                        ['maximum' => ARTDON_CART_MAX_LINES]
                    );
                }

                $resolvedItems = [];
                foreach ($items as $index => $itemInput) {
                    if (!is_array($itemInput)) {
                        throw new ArtdonCartException(
                            'invalid_item',
                            sprintf('Cart item %d must be an object.', $index + 1),
                            422,
                            ['field' => 'items.' . $index]
                        );
                    }
                    try {
                        $resolvedItems[] = artdon_cart_resolve_item(
                            $pdo,
                            $itemInput,
                            $sessionHash
                        );
                    } catch (ArtdonCartException $error) {
                        throw new ArtdonCartException(
                            $error->errorCode,
                            sprintf('Cart item %d: %s', $index + 1, $error->getMessage()),
                            $error->httpStatus,
                            array_merge($error->details, ['item_index' => $index]),
                            $error
                        );
                    }
                }

                $delete = $pdo->prepare('DELETE FROM project_cart_items WHERE cart_id = :cart_id');
                $delete->execute([':cart_id' => $cartId]);
                foreach ($resolvedItems as $sortOrder => $resolved) {
                    artdon_cart_insert_item($pdo, $cartId, $resolved, $sortOrder);
                }
            } elseif ($action === 'update') {
                $itemId = artdon_cart_item_id($payload['item_id'] ?? null);
                $row = artdon_cart_raw_item($pdo, $cartId, $itemId);
                $input = artdon_cart_input_from_row($row);
                $changes = $payload['item'] ?? [];
                if (!is_array($changes)) {
                    throw new ArtdonCartException(
                        'invalid_item',
                        'item must be an object.',
                        422,
                        ['field' => 'item']
                    );
                }
                foreach (['configuration', 'selections', 'quantity', 'qty', 'customer_note', 'note', 'simulation_project_id', 'simulation_report_path', 'report'] as $field) {
                    if (array_key_exists($field, $changes)) {
                        $input[$field] = $changes[$field];
                    } elseif (array_key_exists($field, $payload)) {
                        $input[$field] = $payload[$field];
                    }
                }
                if (array_key_exists('simulation_project_id', $input) && trim((string) $input['simulation_project_id']) === '') {
                    $input['simulation_project_id'] = null;
                    $input['simulation_report_path'] = null;
                }
                $resolved = artdon_cart_resolve_item($pdo, $input, $sessionHash);
                artdon_cart_update_item($pdo, $cartId, $itemId, $resolved);
            } elseif ($action === 'remove') {
                $itemId = artdon_cart_item_id($payload['item_id'] ?? null);
                $delete = $pdo->prepare(
                    'DELETE FROM project_cart_items WHERE id = :id AND cart_id = :cart_id'
                );
                $delete->execute([':id' => $itemId, ':cart_id' => $cartId]);
                if ($delete->rowCount() !== 1) {
                    throw new ArtdonCartException('item_not_found', 'The cart item was not found.', 404);
                }
            } elseif ($action === 'duplicate') {
                $itemId = artdon_cart_item_id($payload['item_id'] ?? null);
                $row = artdon_cart_raw_item($pdo, $cartId, $itemId);
                $input = artdon_cart_input_from_row($row);
                if (array_key_exists('quantity', $payload)) {
                    $input['quantity'] = $payload['quantity'];
                }
                $resolved = artdon_cart_resolve_item($pdo, $input, $sessionHash);
                artdon_cart_insert_item(
                    $pdo,
                    $cartId,
                    $resolved,
                    artdon_cart_next_sort_order($pdo, $cartId)
                );
            } else {
                $clear = $pdo->prepare('DELETE FROM project_cart_items WHERE cart_id = :cart_id');
                $clear->execute([':cart_id' => $cartId]);
            }

            artdon_cart_assert_capacity($pdo, $cartId);
            $projectName = null;
            if ($action === 'replace' && array_key_exists('project_name', $payload)) {
                $projectName = trim((string) $payload['project_name']);
                if (artdon_cart_text_length($projectName) > 120) {
                    throw new ArtdonCartException(
                        'project_name_too_long',
                        'The project name cannot exceed 120 characters.',
                        422,
                        ['field' => 'project_name', 'maximum' => 120]
                    );
                }
            }
            $cart = artdon_cart_touch($pdo, $cart, $projectName);

            $audit = $pdo->prepare(
                'INSERT INTO audit_logs (
                    actor_type, actor_id, action, entity_type, entity_id,
                    request_id, metadata_json, created_at
                 ) VALUES (
                    :actor_type, :actor_id, :action, :entity_type, :entity_id,
                    :request_id, :metadata_json, :created_at
                 )'
            );
            $audit->execute([
                ':actor_type' => 'session',
                ':actor_id' => substr($sessionHash, 0, 16),
                ':action' => 'cart.' . $action,
                ':entity_type' => 'project_cart',
                ':entity_id' => (string) $cart['public_id'],
                ':request_id' => $requestId,
                ':metadata_json' => artdon_cart_json(['version' => (int) $cart['version']]),
                ':created_at' => artdon_db_now(),
            ]);

            return artdon_cart_hydrate($pdo, $cart);
        },
        $pdo
    );
}

/**
 * @param array<string,mixed> $cart
 * @return array<string,mixed>
 */
function artdon_cart_hydrate(PDO $pdo, array $cart): array
{
    $statement = $pdo->prepare(
        'SELECT *
         FROM project_cart_items
         WHERE cart_id = :cart_id
         ORDER BY sort_order, id'
    );
    $statement->execute([':cart_id' => (int) $cart['id']]);

    $items = [];
    $totalQuantity = 0;
    $subtotal = 0.0;
    $hasReviewItems = false;
    foreach ($statement->fetchAll() as $row) {
        $product = artdon_cart_json_decode((string) $row['product_snapshot_json']);
        $configuration = artdon_cart_json_decode((string) $row['configuration_json']);
        $simulation = artdon_cart_json_decode($row['simulation_snapshot_json'] ?? null);
        $quantity = (int) $row['quantity'];
        $unitPrice = $row['unit_price'] === null ? null : (float) $row['unit_price'];
        $lineTotal = $unitPrice === null ? null : round($unitPrice * $quantity, 2);
        if ($lineTotal !== null) {
            $subtotal += $lineTotal;
        } else {
            $hasReviewItems = true;
        }
        $totalQuantity += $quantity;

        $reportUrl = null;
        if ($simulation !== [] && !empty($simulation['public_id'])) {
            $reportUrl = '/api/lighting-report.php?id=' . rawurlencode((string) $simulation['public_id']);
        }
        $items[] = [
            'item_id' => (int) $row['id'],
            'product' => $product,
            'configured_model' => (string) $row['configured_model'],
            'configuration' => $configuration,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'price_mode' => (string) $row['price_mode'],
            'currency' => (string) $row['currency'],
            'lead_time' => (string) $row['lead_time_text'],
            'customer_note' => (string) $row['customer_note'],
            'simulation' => $simulation === [] ? null : array_merge($simulation, [
                'report_url' => $reportUrl,
            ]),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    return [
        'public_id' => (string) $cart['public_id'],
        'project_name' => (string) $cart['project_name'],
        'currency' => (string) $cart['currency'],
        'status' => (string) $cart['status'],
        'version' => (int) $cart['version'],
        'items' => $items,
        'summary' => [
            'line_count' => count($items),
            'total_quantity' => $totalQuantity,
            'priced_subtotal' => round($subtotal, 2),
            'has_review_items' => $hasReviewItems,
        ],
        'expires_at' => $cart['expires_at'] === null ? null : (string) $cart['expires_at'],
        'created_at' => (string) $cart['created_at'],
        'updated_at' => (string) $cart['updated_at'],
    ];
}

function artdon_cart_idempotency_key(array $payload): string
{
    $key = trim((string) (
        $_SERVER['HTTP_IDEMPOTENCY_KEY']
        ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY']
        ?? $payload['idempotency_key']
        ?? ''
    ));
    if ($key === '') {
        return 'auto-' . bin2hex(random_bytes(12));
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $key) !== 1) {
        throw new ArtdonCartException(
            'invalid_idempotency_key',
            'The idempotency key must be 8–128 safe characters.',
            400,
            ['field' => 'idempotency_key']
        );
    }

    return $key;
}

/**
 * Return replay metadata, or null for a first request. A reused key with a
 * different payload is rejected.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>|null
 */
function artdon_cart_idempotency_replay(string $key, array $payload): ?array
{
    $now = time();
    $cache = is_array($_SESSION['artdon_cart_idempotency'] ?? null)
        ? $_SESSION['artdon_cart_idempotency']
        : [];
    foreach ($cache as $cachedKey => $entry) {
        if (!is_array($entry) || (int) ($entry['created_at'] ?? 0) < $now - 7200) {
            unset($cache[$cachedKey]);
        }
    }
    $_SESSION['artdon_cart_idempotency'] = $cache;

    if (!isset($cache[$key]) || !is_array($cache[$key])) {
        return null;
    }
    $requestHash = hash(
        'sha256',
        artdon_cart_json(artdon_cart_canonicalize(
            array_diff_key($payload, ['csrf_token' => true, 'idempotency_key' => true])
        ))
    );
    if (!hash_equals((string) ($cache[$key]['request_hash'] ?? ''), $requestHash)) {
        throw new ArtdonCartException(
            'idempotency_conflict',
            'This idempotency key was already used for a different cart request.',
            409
        );
    }

    return $cache[$key];
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $cart
 */
function artdon_cart_idempotency_remember(string $key, array $payload, array $cart): void
{
    $cache = is_array($_SESSION['artdon_cart_idempotency'] ?? null)
        ? $_SESSION['artdon_cart_idempotency']
        : [];
    $cache[$key] = [
        'request_hash' => hash(
            'sha256',
            artdon_cart_json(artdon_cart_canonicalize(
                array_diff_key($payload, ['csrf_token' => true, 'idempotency_key' => true])
            ))
        ),
        'cart_public_id' => (string) $cart['public_id'],
        'cart_version' => (int) $cart['version'],
        'created_at' => time(),
    ];
    if (count($cache) > 25) {
        uasort(
            $cache,
            static fn(array $left, array $right): int =>
                (int) ($left['created_at'] ?? 0) <=> (int) ($right['created_at'] ?? 0)
        );
        $cache = array_slice($cache, -25, null, true);
    }
    $_SESSION['artdon_cart_idempotency'] = $cache;
}

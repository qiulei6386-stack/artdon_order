<?php

declare(strict_types=1);

/**
 * Server-side product configuration and commercial calculation.
 *
 * Browser code may preview options, but this service is the only authority for
 * allowed combinations, configured model, MOQ, unit price and lead time.
 */

function artdon_configurator_product(string $sku, ?PDO $pdo = null): ?array
{
    $pdo ??= artdon_db();
    $product = artdon_catalog_find_by_sku(trim($sku), $pdo);
    if ($product === null || ($product['status'] ?? '') !== 'active') {
        return null;
    }
    $product['configuration_schema'] = artdon_catalog_configuration_schema((int) $product['id'], $pdo) ?? [];

    return $product;
}

/**
 * @param array<string,mixed> $selection
 * @return array<string,mixed>
 */
function artdon_configurator_configure(
    string $sku,
    array $selection = [],
    int $quantity = 1,
    ?PDO $pdo = null
): array {
    $pdo ??= artdon_db();
    $product = artdon_configurator_product($sku, $pdo);
    if ($product === null) {
        return artdon_configurator_invalid('product_not_found', 'The selected product is not available.');
    }
    if (empty($product['order_enabled'])) {
        return artdon_configurator_invalid('product_not_orderable', 'This product is not currently available for online configuration.');
    }
    if ($quantity < 1 || $quantity > 100_000) {
        return artdon_configurator_invalid('invalid_quantity', 'Quantity must be between 1 and 100,000.');
    }

    $schema = is_array($product['configuration_schema'] ?? null)
        ? $product['configuration_schema']
        : [];
    $options = is_array($schema['options'] ?? null) ? array_values($schema['options']) : [];
    $known = [];
    $resolved = [];
    $selectedDefinitions = [];
    $errors = [];

    foreach ($options as $option) {
        if (!is_array($option)) {
            continue;
        }
        $code = trim((string) ($option['code'] ?? ''));
        if ($code === '' || isset($known[$code])) {
            continue;
        }
        $known[$code] = true;
        $values = is_array($option['values'] ?? null) ? array_values($option['values']) : [];
        $requested = array_key_exists($code, $selection) && is_scalar($selection[$code])
            ? (string) $selection[$code]
            : null;
        $selectedValue = null;
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($requested !== null && (string) ($value['code'] ?? '') === $requested) {
                $selectedValue = $value;
                break;
            }
            if ($requested === null && !empty($value['default']) && $selectedValue === null) {
                $selectedValue = $value;
            }
        }
        if ($requested === null && $selectedValue === null) {
            $selectedValue = isset($values[0]) && is_array($values[0]) ? $values[0] : null;
        }
        if ($selectedValue === null) {
            $errors[] = [
                'code' => 'invalid_option_value',
                'field' => 'configuration.' . $code,
                'message' => sprintf('Select a valid value for %s.', (string) ($option['label'] ?? $code)),
            ];
            continue;
        }
        $resolved[$code] = (string) ($selectedValue['code'] ?? '');
        $selectedDefinitions[$code] = $selectedValue;
    }

    foreach ($selection as $code => $value) {
        if (!is_string($code) || !isset($known[$code])) {
            $errors[] = [
                'code' => 'unknown_option',
                'field' => 'configuration.' . (string) $code,
                'message' => sprintf('The option "%s" is not available for this product.', (string) $code),
            ];
        } elseif (!is_scalar($value) && $value !== null) {
            $errors[] = [
                'code' => 'invalid_option_value',
                'field' => 'configuration.' . $code,
                'message' => sprintf('The value for "%s" is invalid.', $code),
            ];
        }
    }

    $ruleMessages = [];
    $manualReview = false;
    foreach ((array) ($schema['rules'] ?? []) as $rule) {
        if (!is_array($rule) || !artdon_configurator_rule_matches($rule, $resolved)) {
            continue;
        }
        $type = strtolower((string) ($rule['type'] ?? ''));
        if (
            $type === 'deny'
            && isset($rule['option'], $rule['value'])
            && ($resolved[(string) $rule['option']] ?? null) === (string) $rule['value']
        ) {
            $ruleMessages[] = (string) ($rule['message'] ?? 'The selected combination is not available.');
        }
        if (in_array($type, ['review', 'manual_review', 'requires_review'], true)) {
            $manualReview = true;
        }
    }
    foreach (array_values(array_unique($ruleMessages)) as $message) {
        $errors[] = [
            'code' => 'combination_not_allowed',
            'field' => 'configuration',
            'message' => $message,
        ];
    }

    $minimum = max(1, (int) ceil((float) ($product['moq'] ?? $product['default_moq'] ?? 1)));
    if ($quantity < $minimum) {
        $errors[] = [
            'code' => 'below_moq',
            'field' => 'quantity',
            'message' => sprintf('The minimum order quantity is %d pcs.', $minimum),
        ];
    }

    $availability = artdon_configurator_availability($schema, $resolved);
    if ($errors !== []) {
        return [
            'success' => false,
            'valid' => false,
            'code' => (string) $errors[0]['code'],
            'message' => implode(' ', array_column($errors, 'message')),
            'errors' => array_column($errors, 'message'),
            'error_details' => $errors,
            'product' => $product,
            'schema' => $schema,
            'configuration' => $resolved,
            'availability' => $availability,
            'moq' => $minimum,
        ];
    }

    $modelParts = [];
    foreach ((array) ($schema['sku_order'] ?? ['product']) as $part) {
        $part = (string) $part;
        if (in_array($part, ['series', 'product', 'model'], true)) {
            $modelParts[] = (string) $product['sku'];
            continue;
        }
        $definition = $selectedDefinitions[$part] ?? null;
        $value = is_array($definition)
            ? (string) ($definition['sku'] ?? $definition['code'] ?? '')
            : (string) ($resolved[$part] ?? '');
        if ($value !== '') {
            $modelParts[] = $value;
        }
    }
    if ($modelParts === [] || $modelParts[0] !== (string) $product['sku']) {
        array_unshift($modelParts, (string) $product['sku']);
    }
    $configuredModel = implode('-', array_values(array_unique(array_filter($modelParts, 'strlen'))));

    $priceMode = strtolower((string) ($schema['price_mode'] ?? $product['price_mode'] ?? 'review'));
    if (!in_array($priceMode, ['fixed', 'from', 'review'], true) || $manualReview) {
        $priceMode = 'review';
    }
    $unitPrice = null;
    if ($priceMode !== 'review' && is_numeric($product['price'] ?? null)) {
        $unitPrice = (float) $product['price'];
        foreach ($selectedDefinitions as $definition) {
            if (is_numeric($definition['price_delta'] ?? null)) {
                $unitPrice += (float) $definition['price_delta'];
            }
        }
        $unitPrice = round(max(0, $unitPrice), 2);
    }

    $labels = [];
    foreach ($options as $option) {
        if (!is_array($option)) {
            continue;
        }
        $code = (string) ($option['code'] ?? '');
        foreach ((array) ($option['values'] ?? []) as $value) {
            if (is_array($value) && (string) ($value['code'] ?? '') === ($resolved[$code] ?? null)) {
                $labels[] = (string) ($option['label'] ?? $code) . ': ' . (string) ($value['label'] ?? $value['code'] ?? '');
                break;
            }
        }
    }

    return [
        'success' => true,
        'valid' => true,
        'product' => $product,
        'schema' => $schema,
        'configuration' => $resolved,
        'configuration_text' => implode(' · ', $labels),
        'configured_model' => $configuredModel,
        'quantity' => $quantity,
        'moq' => $minimum,
        'price_mode' => $priceMode,
        'unit_price' => $unitPrice,
        'currency' => (string) ($product['base_currency'] ?? 'USD'),
        'lead_time_text' => (string) ($product['lead_time'] ?? $product['lead_time_text'] ?? 'To be confirmed'),
        'stock_quantity' => max(0, (float) ($product['stock'] ?? $product['stock_quantity'] ?? 0)),
        'availability' => $availability,
        'review_required' => $priceMode === 'review',
        'calculation' => [
            'line_total' => $unitPrice === null ? null : round($unitPrice * $quantity, 2),
            'server_validated' => true,
        ],
    ];
}

/**
 * @param array<string,mixed> $rule
 * @param array<string,string> $selection
 */
function artdon_configurator_rule_matches(array $rule, array $selection): bool
{
    foreach ((array) ($rule['when'] ?? []) as $code => $expected) {
        if (($selection[(string) $code] ?? null) !== (string) $expected) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string,mixed> $schema
 * @param array<string,string> $selection
 * @return array<string,array<string,bool>>
 */
function artdon_configurator_availability(array $schema, array $selection): array
{
    $availability = [];
    foreach ((array) ($schema['options'] ?? []) as $option) {
        if (!is_array($option)) {
            continue;
        }
        $code = (string) ($option['code'] ?? '');
        foreach ((array) ($option['values'] ?? []) as $value) {
            if (!is_array($value)) {
                continue;
            }
            $valueCode = (string) ($value['code'] ?? '');
            $candidate = array_merge($selection, [$code => $valueCode]);
            $allowed = true;
            foreach ((array) ($schema['rules'] ?? []) as $rule) {
                if (
                    is_array($rule)
                    && (string) ($rule['type'] ?? '') === 'deny'
                    && (string) ($rule['option'] ?? '') === $code
                    && (string) ($rule['value'] ?? '') === $valueCode
                    && artdon_configurator_rule_matches($rule, $candidate)
                ) {
                    $allowed = false;
                    break;
                }
            }
            $availability[$code][$valueCode] = $allowed;
        }
    }

    return $availability;
}

/**
 * @return array<string,mixed>
 */
function artdon_configurator_invalid(string $code, string $message): array
{
    return [
        'success' => false,
        'valid' => false,
        'code' => $code,
        'message' => $message,
        'errors' => [$message],
        'error_details' => [['code' => $code, 'field' => 'sku', 'message' => $message]],
    ];
}

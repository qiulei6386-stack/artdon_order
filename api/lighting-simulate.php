<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/configurator.php';
require_once __DIR__ . '/../includes/lighting_repository.php';

api_require_method('POST');
$requestId = api_request_id();
$input = api_json_body(32_768);
api_verify_csrf($input);

try {
    $now = time();
    $recent = array_values(array_filter(
        is_array($_SESSION['lighting_simulation_times'] ?? null)
            ? $_SESSION['lighting_simulation_times']
            : [],
        static fn (mixed $timestamp): bool => $now - (int) $timestamp < 60
    ));
    if (count($recent) >= 6) {
        api_respond(429, [
            'success' => false,
            'request_id' => $requestId,
            'message' => 'Too many simulations were requested. Please wait one minute and try again.',
        ]);
    }
    $recent[] = $now;
    $_SESSION['lighting_simulation_times'] = $recent;

    $ipLimit = artdon_lighting_rate_limit(
        'simulate-ip',
        function_exists('api_client_ip_hash')
            ? api_client_ip_hash()
            : hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')),
        24,
        300
    );
    if (!$ipLimit['allowed']) {
        if (!headers_sent()) {
            header('Retry-After: ' . $ipLimit['retry_after']);
        }
        api_respond(429, [
            'success' => false,
            'request_id' => $requestId,
            'message' => 'This network has submitted too many simulations. Please wait and try again.',
            'retry_after' => $ipLimit['retry_after'],
        ]);
    }

    $pdo = artdon_lighting_bootstrap();
    $profileId = strtoupper(trim((string) ($input['profile_id'] ?? '')));
    $profile = artdon_lighting_find_profile($profileId, $pdo);
    if ($profile === null) {
        api_respond(404, [
            'success' => false,
            'request_id' => $requestId,
            'message' => 'The selected photometric profile is unavailable.',
        ]);
    }

    $requestedProductSku = strtoupper(trim((string) ($input['product_sku'] ?? '')));
    if (
        preg_match('/^[A-Z0-9][A-Z0-9-]{1,63}$/', $requestedProductSku) !== 1
        || !hash_equals(strtoupper((string) $profile['sku']), $requestedProductSku)
    ) {
        throw new InvalidArgumentException(
            'The selected product does not match this photometric profile. No substitute product was used.'
        );
    }

    $configurationInput = $input['configuration'] ?? [];
    if (!is_array($configurationInput)) {
        throw new InvalidArgumentException('The product configuration must be an object.');
    }
    $configuration = artdon_lighting_bound_configuration($profile, $configurationInput);
    $catalogProduct = artdon_configurator_product((string) $profile['sku'], $pdo);
    if ($catalogProduct === null) {
        throw new InvalidArgumentException('The selected product is no longer configurable.');
    }
    $configured = artdon_configurator_configure(
        (string) $profile['sku'],
        $configuration,
        max(1, (int) ceil((float) ($catalogProduct['moq'] ?? 1))),
        $pdo
    );
    if (empty($configured['valid']) || !is_array($configured['configuration'] ?? null)) {
        throw new InvalidArgumentException(
            (string) ($configured['message'] ?? 'The selected product configuration is not allowed.')
        );
    }
    $configuration = $configured['configuration'];

    $simulationRequest = [
        'mode' => (string) ($input['mode'] ?? 'auto_layout'),
        'room' => $input['room'] ?? null,
        'layout' => $input['layout'] ?? null,
        'options' => $input['options'] ?? [],
        'maintenance_factor' => $input['maintenance_factor'] ?? 0.8,
    ];
    $result = artdon_lighting_simulate_profile($profile, $simulationRequest);
    $manufacturerValidated = artdon_lighting_manufacturer_validated($profile);
    if (!$manufacturerValidated) {
        $result['warnings'][] = str_starts_with((string) $profile['public_id'], 'IES-DEMO-')
            ? 'This simulation uses synthetic preliminary demo photometry, not manufacturer-validated IES data.'
            : 'This library profile has not been marked as manufacturer validated.';
    }
    $result['warnings'] = array_values(array_unique(array_map('strval', $result['warnings'])));

    $pending = artdon_lighting_store_pending([
        'profile' => $profile,
        'configuration' => $configuration,
        'input' => [
            'project_name' => artdon_lighting_clip_text(trim((string) ($input['project_name'] ?? '')), 160),
            'profile_id' => $profileId,
            'configured_model' => (string) $configured['configured_model'],
            'configuration' => $configuration,
            'mode' => $simulationRequest['mode'],
            'room' => $simulationRequest['room'],
            'layout' => $simulationRequest['layout'],
            'options' => $simulationRequest['options'],
            'maintenance_factor' => (float) ($result['maintenance_factor'] ?? 0.8),
        ],
        'result' => $result,
    ]);

    api_respond(200, [
        'success' => true,
        'request_id' => $requestId,
        'simulation_token' => $pending['token'],
        'expires_at' => $pending['expires_at'],
        'product' => [
            'sku' => (string) $profile['sku'],
            'name' => (string) $profile['product_name'],
            'series' => (string) $profile['series_code'],
            'configured_model' => (string) $configured['configured_model'],
            'configuration' => $configuration,
        ],
        'profile' => artdon_lighting_public_profile($profile),
        'result' => $result,
        'data_status' => (string) $profile['data_status'],
        'manufacturer_validated' => $manufacturerValidated,
        'disclaimer' => artdon_lighting_disclaimer(),
    ]);
} catch (ArtdonDatabaseUnavailable $error) {
    error_log(sprintf('[lighting-simulate:%s] Database is not ready: %s', $requestId, $error->getMessage()));
    if (!headers_sent()) {
        header('Retry-After: 5');
    }
    api_respond(503, [
        'success' => false,
        'request_id' => $requestId,
        'message' => 'The lighting simulation service is temporarily unavailable.',
    ]);
} catch (InvalidArgumentException $error) {
    api_respond(422, [
        'success' => false,
        'request_id' => $requestId,
        'message' => $error->getMessage(),
    ]);
} catch (Throwable $error) {
    error_log(sprintf('[lighting-simulate:%s] %s', $requestId, $error->getMessage()));
    api_respond(500, [
        'success' => false,
        'request_id' => $requestId,
        'message' => 'The lighting simulation could not be completed.',
    ]);
}

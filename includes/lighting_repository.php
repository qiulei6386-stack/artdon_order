<?php

declare(strict_types=1);

use Artdon\Lighting\IesParser;
use Artdon\Lighting\PhotometricDistribution;
use Artdon\Lighting\SimulationService;

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/lighting/IesParser.php';
require_once __DIR__ . '/lighting/PhotometricDistribution.php';
require_once __DIR__ . '/lighting/IlluminanceCalculator.php';
require_once __DIR__ . '/lighting/LayoutOptimizer.php';
require_once __DIR__ . '/lighting/SimulationValidator.php';
require_once __DIR__ . '/lighting/SimulationService.php';

function artdon_lighting_disclaimer(): string
{
    return 'Preliminary direct-illuminance estimate. Final construction design must be verified by a qualified lighting designer or professional lighting software.';
}

/**
 * Manufacturer/laboratory validation is a stronger claim than operator
 * approval. It is exposed only when a non-demo record carries the explicit
 * provenance marker reserved for a future controlled approval workflow.
 *
 * @param array<string,mixed> $profile
 */
function artdon_lighting_manufacturer_validated(array $profile): bool
{
    $publicId = (string) ($profile['public_id'] ?? $profile['ies_public_id'] ?? '');
    $validationStatus = (string) (
        $profile['validation_status']
        ?? $profile['ies_validation_status']
        ?? ''
    );
    $messages = $profile['validation_messages']
        ?? $profile['ies_validation_messages']
        ?? [];
    if (!is_array($messages)) {
        try {
            $decoded = json_decode((string) $messages, true, 32, JSON_THROW_ON_ERROR);
            $messages = is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            $messages = [];
        }
    }
    $provenanceMarker = 'Manufacturer or laboratory provenance was independently verified.';

    return $validationStatus === 'valid'
        && !str_starts_with($publicId, 'IES-DEMO-')
        && in_array($provenanceMarker, array_map('strval', $messages), true);
}

function artdon_lighting_session_key_hash(): string
{
    if (function_exists('api_session_hash')) {
        return api_session_hash();
    }

    $sessionId = session_id();
    if ($sessionId === '') {
        throw new RuntimeException('An active session is required.');
    }

    return hash('sha256', 'artdon-session-v1|' . $sessionId);
}

function artdon_lighting_public_id(string $prefix): string
{
    if (function_exists('api_public_id')) {
        return api_public_id($prefix);
    }

    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix) ?: 'ID');
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(8)));
}

/**
 * Open the already-provisioned lighting data store. Runtime requests never
 * migrate or seed; deployment maintenance must do that explicitly.
 */
function artdon_lighting_bootstrap(): PDO
{
    return artdon_db_open_ready();
}

/**
 * @return int Number of demo profiles ensured.
 */
function artdon_lighting_seed_demo_profiles(?PDO $pdo = null): int
{
    $pdo ??= artdon_db();
    $seedDirectory = dirname(__DIR__) . '/database/seeds/ies';
    $manifestFile = $seedDirectory . '/manifest.php';
    if (!is_file($manifestFile)) {
        throw new RuntimeException('The lighting demo profile manifest is missing.');
    }

    $manifest = require $manifestFile;
    if (!is_array($manifest)) {
        throw new RuntimeException('The lighting demo profile manifest is invalid.');
    }

    $parser = new IesParser();
    $findProduct = $pdo->prepare(
        "SELECT p.id,
                (
                    SELECT pcs.id
                    FROM product_configuration_schemas pcs
                    WHERE pcs.product_id = p.id AND pcs.status = 'active'
                    ORDER BY pcs.version DESC
                    LIMIT 1
                ) AS configuration_schema_id
         FROM products p
         WHERE p.sku = :sku AND p.status = 'active'
         LIMIT 1"
    );
    $upsert = $pdo->prepare(
        'INSERT INTO ies_library (
            public_id, product_id, configuration_schema_id, option_signature,
            configured_model, version, original_name, file_path, checksum_sha256,
            ies_standard, photometric_type, tilt_mode, lumens, power_w,
            beam_angle_deg, candela_multiplier, vertical_angles_json,
            horizontal_angles_json, distribution_json, parsed_data_json,
            parser_version, validation_status, validation_messages_json, status,
            created_at, updated_at
        ) VALUES (
            :public_id, :product_id, :configuration_schema_id, :option_signature,
            :configured_model, :version, :original_name, :file_path, :checksum_sha256,
            :ies_standard, :photometric_type, :tilt_mode, :lumens, :power_w,
            :beam_angle_deg, :candela_multiplier, :vertical_angles_json,
            :horizontal_angles_json, :distribution_json, :parsed_data_json,
            :parser_version, :validation_status, :validation_messages_json, :status,
            :created_at, :updated_at
        )
        ON CONFLICT(public_id) DO UPDATE SET
            product_id = excluded.product_id,
            configuration_schema_id = excluded.configuration_schema_id,
            option_signature = excluded.option_signature,
            configured_model = excluded.configured_model,
            version = excluded.version,
            original_name = excluded.original_name,
            file_path = excluded.file_path,
            checksum_sha256 = excluded.checksum_sha256,
            ies_standard = excluded.ies_standard,
            photometric_type = excluded.photometric_type,
            tilt_mode = excluded.tilt_mode,
            lumens = excluded.lumens,
            power_w = excluded.power_w,
            beam_angle_deg = excluded.beam_angle_deg,
            candela_multiplier = excluded.candela_multiplier,
            vertical_angles_json = excluded.vertical_angles_json,
            horizontal_angles_json = excluded.horizontal_angles_json,
            distribution_json = excluded.distribution_json,
            parsed_data_json = excluded.parsed_data_json,
            parser_version = excluded.parser_version,
            validation_status = excluded.validation_status,
            validation_messages_json = excluded.validation_messages_json,
            status = excluded.status,
            updated_at = excluded.updated_at'
    );

    return artdon_db_transaction(static function (PDO $pdo) use (
        $manifest,
        $seedDirectory,
        $parser,
        $findProduct,
        $upsert
    ): int {
        $count = 0;
        $now = artdon_db_now();
        foreach ($manifest as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('A lighting demo profile manifest entry is invalid.');
            }

            $productSku = trim((string) ($entry['product_sku'] ?? ''));
            $fileName = basename((string) ($entry['filename'] ?? ''));
            $publicId = trim((string) ($entry['public_id'] ?? ''));
            if ($productSku === '' || $fileName === '' || !preg_match('/^IES-DEMO-[A-Z0-9-]+$/', $publicId)) {
                throw new RuntimeException('A lighting demo profile identity is invalid.');
            }

            $findProduct->execute([':sku' => $productSku]);
            $product = $findProduct->fetch();
            if (!$product) {
                throw new RuntimeException('The demo lighting product does not exist: ' . $productSku);
            }

            $absolutePath = $seedDirectory . '/' . $fileName;
            $expectedPrefix = rtrim(realpath($seedDirectory) ?: $seedDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $resolved = realpath($absolutePath);
            if ($resolved === false || !str_starts_with($resolved, $expectedPrefix)) {
                throw new RuntimeException('The demo IES seed path is invalid.');
            }

            $parsed = $parser->parseFile($resolved);
            $distribution = new PhotometricDistribution($parsed);
            $photometry = (array) ($parsed['photometry'] ?? []);
            $source = (array) ($parsed['source'] ?? []);
            $matchOptions = artdon_lighting_canonical_array((array) ($entry['match_options'] ?? []));
            $optionSignature = artdon_json_encode($matchOptions);
            $lumensPerLamp = (float) ($photometry['lumens_per_lamp'] ?? -1);
            $lampCount = (int) ($photometry['lamp_count'] ?? 1);
            $lumens = $lumensPerLamp >= 0
                ? $lumensPerLamp * max(1, $lampCount)
                : (float) ($entry['lumens'] ?? 0);
            $beamAngle = $distribution->beamAngle(0.0);
            $relativePath = 'database/seeds/ies/' . $fileName;
            $messages = [
                'Synthetic preliminary demo photometry for workflow evaluation only.',
                'Not supplied, measured, or validated by the product manufacturer.',
                artdon_lighting_disclaimer(),
            ];

            $upsert->execute([
                ':public_id' => $publicId,
                ':product_id' => (int) $product['id'],
                ':configuration_schema_id' => $product['configuration_schema_id'] === null
                    ? null
                    : (int) $product['configuration_schema_id'],
                ':option_signature' => $optionSignature,
                ':configured_model' => trim((string) ($entry['configured_model'] ?? $productSku)),
                ':version' => max(1, (int) ($entry['version'] ?? 1)),
                ':original_name' => $fileName,
                ':file_path' => $relativePath,
                ':checksum_sha256' => (string) ($source['sha256'] ?? hash_file('sha256', $resolved)),
                ':ies_standard' => (string) ($source['lm63_version_tag'] ?? ''),
                ':photometric_type' => (string) ($photometry['type'] ?? ''),
                ':tilt_mode' => (string) ($photometry['tilt']['mode'] ?? ''),
                ':lumens' => $lumens > 0 ? $lumens : null,
                ':power_w' => (float) ($photometry['input_watts'] ?? $entry['power_w'] ?? 0),
                ':beam_angle_deg' => $beamAngle,
                ':candela_multiplier' => (float) ($photometry['candela_multiplier'] ?? 1),
                ':vertical_angles_json' => artdon_json_encode((array) ($photometry['vertical_angles_deg'] ?? [])),
                ':horizontal_angles_json' => artdon_json_encode((array) ($photometry['horizontal_angles_deg'] ?? [])),
                ':distribution_json' => artdon_json_encode((array) ($photometry['candela_cd'] ?? [])),
                ':parsed_data_json' => artdon_json_encode($parsed),
                ':parser_version' => (string) ($parsed['parser_version'] ?? 'ies-parser-1.0.0'),
                ':validation_status' => 'warning',
                ':validation_messages_json' => artdon_json_encode($messages),
                ':status' => 'active',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $count++;
        }

        return $count;
    }, $pdo);
}

/**
 * @return list<array<string,mixed>>
 */
function artdon_lighting_products(?PDO $pdo = null): array
{
    $pdo ??= artdon_db();
    $rows = $pdo->query(
        "SELECT i.*, p.sku, p.name AS product_name, p.series_code,
                p.category_slug, p.image_path
         FROM ies_library i
         INNER JOIN products p ON p.id = i.product_id
         WHERE i.status = 'active'
           AND i.validation_status IN ('valid', 'warning')
           AND p.status = 'active'
         ORDER BY p.category_slug, p.series_code, p.sku, i.version DESC, i.id"
    )->fetchAll();

    $products = [];
    foreach ($rows as $row) {
        $profile = artdon_lighting_hydrate_profile($row);
        $sku = (string) $row['sku'];
        if (!isset($products[$sku])) {
            $products[$sku] = [
                'id' => (int) $row['product_id'],
                'sku' => $sku,
                'name' => (string) $row['product_name'],
                'series' => (string) $row['series_code'],
                'category' => (string) $row['category_slug'],
                'image' => (string) $row['image_path'],
                'profiles' => [],
            ];
        }
        $products[$sku]['profiles'][] = artdon_lighting_public_profile($profile);
    }

    return array_values($products);
}

function artdon_lighting_find_profile(string $publicId, ?PDO $pdo = null): ?array
{
    $pdo ??= artdon_db();
    $publicId = strtoupper(trim($publicId));
    if (!preg_match('/^IES-[A-Z0-9-]{4,80}$/', $publicId)) {
        return null;
    }

    $statement = $pdo->prepare(
        "SELECT i.*, p.sku, p.name AS product_name, p.series_code,
                p.category_slug, p.image_path
         FROM ies_library i
         INNER JOIN products p ON p.id = i.product_id
         WHERE i.public_id = :public_id
           AND i.status = 'active'
           AND i.validation_status IN ('valid', 'warning')
           AND p.status = 'active'
         LIMIT 1"
    );
    $statement->execute([':public_id' => $publicId]);
    $row = $statement->fetch();

    return $row ? artdon_lighting_hydrate_profile($row) : null;
}

/**
 * @param array<string,mixed> $profile
 * @return array<string,mixed>
 */
function artdon_lighting_public_profile(array $profile): array
{
    $manufacturerValidated = artdon_lighting_manufacturer_validated($profile);

    return [
        'id' => (string) $profile['public_id'],
        'configured_model' => (string) $profile['configured_model'],
        'version' => (int) $profile['version'],
        'configuration_match' => (array) $profile['configuration_match'],
        'lumens' => $profile['lumens'],
        'power_w' => $profile['power_w'],
        'beam_angle_deg' => $profile['beam_angle_deg'],
        'ies' => [
            'original_name' => (string) $profile['original_name'],
            'standard' => (string) $profile['ies_standard'],
            'photometric_type' => (string) $profile['photometric_type'],
            'tilt_mode' => (string) $profile['tilt_mode'],
            'validation_status' => (string) $profile['validation_status'],
            'validation_messages' => (array) $profile['validation_messages'],
        ],
        'data_status' => (string) $profile['data_status'],
        'manufacturer_validated' => $manufacturerValidated,
        'disclaimer' => artdon_lighting_disclaimer(),
    ];
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function artdon_lighting_hydrate_profile(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['product_id'] = (int) $row['product_id'];
    $row['version'] = (int) $row['version'];
    $row['lumens'] = $row['lumens'] === null ? null : (float) $row['lumens'];
    $row['power_w'] = $row['power_w'] === null ? null : (float) $row['power_w'];
    $row['beam_angle_deg'] = $row['beam_angle_deg'] === null ? null : (float) $row['beam_angle_deg'];
    $row['configuration_match'] = artdon_lighting_json_array((string) ($row['option_signature'] ?? '{}'));
    $row['validation_messages'] = artdon_lighting_json_array((string) ($row['validation_messages_json'] ?? '[]'));
    $row['parsed_data'] = artdon_lighting_json_array((string) ($row['parsed_data_json'] ?? '{}'));
    $row['data_status'] = str_starts_with((string) $row['public_id'], 'IES-DEMO-')
        ? 'synthetic_preliminary_demo'
        : (artdon_lighting_manufacturer_validated($row)
            ? 'manufacturer_validated'
            : 'unverified_library_data');

    return $row;
}

/**
 * Validate and combine the optical variant binding carried by an IES profile
 * with the user's current configured selections.
 *
 * @param array<string,mixed> $profile
 * @param array<string,mixed> $configuration
 * @return array<string,mixed>
 */
function artdon_lighting_bound_configuration(array $profile, array $configuration): array
{
    $clean = artdon_lighting_safe_json_value($configuration, 0);
    if (!is_array($clean)) {
        throw new InvalidArgumentException('The product configuration must be an object.');
    }
    $encoded = artdon_json_encode($clean);
    if (strlen($encoded) > 20_000) {
        throw new InvalidArgumentException('The product configuration is too large.');
    }

    $match = (array) ($profile['configuration_match'] ?? []);
    foreach ($match as $key => $expected) {
        if (array_key_exists((string) $key, $clean) && (string) $clean[$key] !== (string) $expected) {
            throw new InvalidArgumentException(
                sprintf('The selected %s value does not match this photometric profile.', (string) $key)
            );
        }
        $clean[(string) $key] = $expected;
    }
    ksort($clean, SORT_STRING);

    return $clean;
}

/**
 * Run the engine with the maintenance factor applied consistently to both
 * layout selection and reported illuminance.
 *
 * @param array<string,mixed> $profile
 * @param array<string,mixed> $request
 * @return array<string,mixed>
 */
function artdon_lighting_simulate_profile(array $profile, array $request): array
{
    $maintenanceFactor = $request['maintenance_factor'] ?? 0.8;
    if (!is_numeric($maintenanceFactor)) {
        throw new InvalidArgumentException('The maintenance factor must be numeric.');
    }
    $maintenanceFactor = (float) $maintenanceFactor;
    if (!is_finite($maintenanceFactor) || $maintenanceFactor < 0.5 || $maintenanceFactor > 1.0) {
        throw new InvalidArgumentException('The maintenance factor must be between 0.5 and 1.0.');
    }

    $engineRequest = $request;
    $room = $engineRequest['room'] ?? null;
    if (!is_array($room) || !isset($room['target_lux']) || !is_numeric($room['target_lux'])) {
        throw new InvalidArgumentException('The room target_lux value is required and must be numeric.');
    }
    $targetLux = (float) $room['target_lux'];
    $engineRequest['room']['target_lux'] = $targetLux / $maintenanceFactor;

    $options = is_array($engineRequest['options'] ?? null) ? $engineRequest['options'] : [];
    $length = is_numeric($room['length_m'] ?? null) ? (float) $room['length_m'] : 10.0;
    $width = is_numeric($room['width_m'] ?? null) ? (float) $room['width_m'] : 8.0;
    $gridNx = isset($options['grid_nx']) ? (int) $options['grid_nx'] : max(20, min(36, (int) ceil($length / 0.35)));
    $gridNy = isset($options['grid_ny']) ? (int) $options['grid_ny'] : max(20, min(36, (int) ceil($width / 0.35)));
    if ($gridNx < 10 || $gridNx > 36 || $gridNy < 10 || $gridNy > 36 || $gridNx * $gridNy > 1_296) {
        throw new InvalidArgumentException('The heatmap grid must be between 10 and 36 cells per axis and no more than 1,296 cells.');
    }
    $maxFixtures = isset($options['max_fixtures']) ? (int) $options['max_fixtures'] : 96;
    if ($maxFixtures < 1 || $maxFixtures > 120) {
        throw new InvalidArgumentException('The fixture limit must be between 1 and 120.');
    }
    $mode = strtolower(trim((string) ($engineRequest['mode'] ?? 'auto_layout')));
    if (in_array($mode, ['layout', 'manual_layout'], true)) {
        $manualLayout = $engineRequest['layout'] ?? null;
        $columns = is_array($manualLayout) ? filter_var($manualLayout['columns'] ?? null, FILTER_VALIDATE_INT) : false;
        $rows = is_array($manualLayout) ? filter_var($manualLayout['rows'] ?? null, FILTER_VALIDATE_INT) : false;
        if ($columns !== false && $rows !== false && $columns > 0 && $rows > 0 && $columns * $rows > 120) {
            throw new InvalidArgumentException('A public simulation layout cannot contain more than 120 luminaires.');
        }
    }
    $engineRequest['options'] = [
        'grid_nx' => $gridNx,
        'grid_ny' => $gridNy,
        'max_fixtures' => $maxFixtures,
    ];

    $parsed = $profile['parsed_data'] ?? null;
    if (!is_array($parsed) || $parsed === []) {
        throw new RuntimeException('The selected photometric profile has no parsed IES data.');
    }
    $result = (new SimulationService())->simulateParsed($parsed, $engineRequest);

    foreach ((array) ($result['heatmap']['values_lux'] ?? []) as $index => $value) {
        $result['heatmap']['values_lux'][$index] = (float) $value * $maintenanceFactor;
    }
    foreach (['average_lux', 'maximum_lux', 'minimum_lux'] as $metric) {
        if (isset($result['heatmap']['metrics'][$metric])) {
            $result['heatmap']['metrics'][$metric] = (float) $result['heatmap']['metrics'][$metric] * $maintenanceFactor;
        }
        if (isset($result['metrics'][$metric])) {
            $result['metrics'][$metric] = (float) $result['metrics'][$metric] * $maintenanceFactor;
        }
    }
    $result['metrics']['target_lux'] = $targetLux;
    $result['metrics']['target_met'] = (float) ($result['metrics']['average_lux'] ?? 0) + 1.0E-9 >= $targetLux;
    $result['room']['target_lux'] = $targetLux;
    if (is_array($result['single'] ?? null)) {
        foreach (['center_lux', 'average_lux', 'maximum_lux', 'minimum_lux', 'edge_lux_c0', 'edge_lux_c90'] as $key) {
            if ($result['single'][$key] !== null && isset($result['single'][$key])) {
                $result['single'][$key] = (float) $result['single'][$key] * $maintenanceFactor;
            }
        }
    }
    $result['maintenance_factor'] = $maintenanceFactor;
    $result['assumptions'][] = sprintf('A maintenance factor of %.2f is applied to reported illuminance.', $maintenanceFactor);
    $result['disclaimer'] = artdon_lighting_disclaimer();

    $hashPayload = $result;
    unset($hashPayload['calculation_hash']);
    $result['calculation_hash'] = hash('sha256', artdon_json_encode($hashPayload));

    return $result;
}

/**
 * Apply an atomic, storage-backed sliding-window limit. The identity is
 * irreversibly hashed into the filename and is never written to disk.
 *
 * @return array{allowed:bool,retry_after:int,remaining:int}
 */
function artdon_lighting_cleanup_rate_limit_files(
    string $directory,
    int $olderThanSeconds = 86_400,
    int $maximumScanned = 256
): int {
    if ($olderThanSeconds < 3_600 || $maximumScanned < 1 || $maximumScanned > 2_048) {
        throw new InvalidArgumentException('The rate-limit cleanup policy is invalid.');
    }
    if (!is_dir($directory)) {
        return 0;
    }

    $cutoff = time() - $olderThanSeconds;
    $scanned = 0;
    $removed = 0;
    $iterator = new DirectoryIterator($directory);
    foreach ($iterator as $file) {
        if ($file->isDot()) {
            continue;
        }
        $scanned++;
        if ($scanned > $maximumScanned) {
            break;
        }
        if (
            $file->isLink()
            || !$file->isFile()
            || preg_match('/^[a-z0-9_-]+-[a-f0-9]{64}\.json$/', $file->getFilename()) !== 1
            || $file->getMTime() > $cutoff
        ) {
            continue;
        }
        if (@unlink($file->getPathname())) {
            $removed++;
        }
    }

    return $removed;
}

function artdon_lighting_rate_limit(
    string $scope,
    string $identity,
    int $limit,
    int $windowSeconds
): array {
    if ($limit < 1 || $limit > 100 || $windowSeconds < 1 || $windowSeconds > 3_600) {
        throw new InvalidArgumentException('The lighting rate-limit policy is invalid.');
    }

    $scope = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $scope) ?: 'lighting');
    $configuredDirectory = trim((string) (getenv('ARTDON_RATE_LIMIT_PATH') ?: ''));
    $directory = $configuredDirectory !== ''
        ? $configuredDirectory
        : dirname(__DIR__) . '/storage/rate-limits';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The lighting rate-limit directory could not be created.');
    }
    @chmod($directory, 0750);
    try {
        if (random_int(1, 64) === 1) {
            artdon_lighting_cleanup_rate_limit_files($directory);
        }
    } catch (Throwable) {
        // A bounded housekeeping failure must not disable request limiting.
    }

    $identityHash = hash('sha256', 'artdon-lighting-rate-v1|' . $identity);
    $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . $scope . '-' . $identityHash . '.json';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('The lighting rate-limit state could not be opened.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('The lighting rate-limit state could not be locked.');
        }
        $raw = stream_get_contents($handle);
        $timestamps = [];
        if (is_string($raw) && $raw !== '' && strlen($raw) <= 16_384) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $timestamps = $decoded;
            }
        }

        $now = microtime(true);
        $cutoff = $now - $windowSeconds;
        $timestamps = array_values(array_filter(
            $timestamps,
            static fn (mixed $timestamp): bool => is_numeric($timestamp)
                && is_finite((float) $timestamp)
                && (float) $timestamp > $cutoff
                && (float) $timestamp <= $now + 1.0
        ));
        sort($timestamps, SORT_NUMERIC);

        $allowed = count($timestamps) < $limit;
        if ($allowed) {
            $timestamps[] = $now;
        }
        $retryAfter = $allowed || $timestamps === []
            ? 0
            : max(1, (int) ceil(((float) $timestamps[0] + $windowSeconds) - $now));

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException('The lighting rate-limit state could not be reset.');
        }
        $encoded = json_encode($timestamps, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (fwrite($handle, $encoded) === false || !fflush($handle)) {
            throw new RuntimeException('The lighting rate-limit state could not be saved.');
        }
        @chmod($path, 0640);
        flock($handle, LOCK_UN);

        return [
            'allowed' => $allowed,
            'retry_after' => $retryAfter,
            'remaining' => max(0, $limit - count($timestamps)),
        ];
    } finally {
        fclose($handle);
    }
}

/**
 * @param array<string,mixed> $payload
 * @return array{token:string,expires_at:string}
 */
function artdon_lighting_store_pending(array $payload): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('An active session is required.');
    }

    $profile = $payload['profile'] ?? null;
    $configuration = $payload['configuration'] ?? null;
    $input = $payload['input'] ?? null;
    $result = $payload['result'] ?? null;
    if (!is_array($profile) || !is_array($configuration) || !is_array($input) || !is_array($result)) {
        throw new InvalidArgumentException('The pending simulation payload is incomplete.');
    }
    $profileId = strtoupper(trim((string) ($profile['public_id'] ?? $input['profile_id'] ?? '')));
    if (!preg_match('/^IES-[A-Z0-9-]{4,80}$/', $profileId)) {
        throw new InvalidArgumentException('The pending photometric profile identity is invalid.');
    }

    $configuration = artdon_lighting_safe_json_value($configuration, 0);
    if (!is_array($configuration) || strlen(artdon_json_encode($configuration)) > 4_096) {
        throw new InvalidArgumentException('The pending product configuration is too large.');
    }
    $compactResult = artdon_lighting_compact_pending_result($result);
    $compactInput = [
        'project_name' => artdon_lighting_clip_text(trim((string) ($input['project_name'] ?? '')), 160),
        'profile_id' => $profileId,
        'configured_model' => artdon_lighting_clip_text(
            trim((string) ($input['configured_model'] ?? $profile['configured_model'] ?? '')),
            255
        ),
        'configuration' => $configuration,
        'mode' => (string) ($compactResult['mode'] ?? ''),
        'room' => (array) ($compactResult['room'] ?? []),
        'layout' => artdon_lighting_layout_without_fixtures((array) ($compactResult['layout'] ?? [])),
        'options' => [
            'grid_nx' => (int) ($compactResult['heatmap']['nx'] ?? 0),
            'grid_ny' => (int) ($compactResult['heatmap']['ny'] ?? 0),
            'max_fixtures' => max(1, (int) ($compactResult['layout']['quantity'] ?? 1)),
        ],
        'maintenance_factor' => (float) ($compactResult['maintenance_factor'] ?? 0.8),
    ];
    if (strlen(artdon_json_encode($compactInput)) > 12_288) {
        throw new InvalidArgumentException('The pending simulation input is too large.');
    }
    $minimalPayload = [
        'profile_id' => $profileId,
        'configured_model' => $compactInput['configured_model'],
        'configuration' => $configuration,
        'input' => $compactInput,
        'result' => $compactResult,
    ];
    if (strlen(artdon_json_encode($minimalPayload)) > 65_536) {
        throw new InvalidArgumentException('The pending simulation result is too large.');
    }

    $now = time();
    $pending = is_array($_SESSION['lighting_pending_simulations'] ?? null)
        ? $_SESSION['lighting_pending_simulations']
        : [];
    foreach ($pending as $token => $record) {
        if (!is_array($record) || (int) ($record['expires_at_unix'] ?? 0) <= $now) {
            unset($pending[$token]);
        }
    }
    uasort($pending, static fn (array $a, array $b): int => (int) ($a['created_at_unix'] ?? 0) <=> (int) ($b['created_at_unix'] ?? 0));
    while (count($pending) >= 2) {
        array_shift($pending);
    }

    $token = artdon_lighting_public_id('LST');
    $expires = $now + 30 * 60;
    $pending[$token] = [
        'created_at_unix' => $now,
        'expires_at_unix' => $expires,
        'payload' => $minimalPayload,
    ];
    $_SESSION['lighting_pending_simulations'] = $pending;

    return [
        'token' => $token,
        'expires_at' => gmdate(DATE_ATOM, $expires),
    ];
}

function artdon_lighting_pending(string $token, bool $consume = false): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || !preg_match('/^LST-[A-F0-9]{16}$/', strtoupper($token))) {
        return null;
    }

    $token = strtoupper($token);
    $pending = is_array($_SESSION['lighting_pending_simulations'] ?? null)
        ? $_SESSION['lighting_pending_simulations']
        : [];
    $record = $pending[$token] ?? null;
    if (!is_array($record) || (int) ($record['expires_at_unix'] ?? 0) <= time()) {
        unset($pending[$token]);
        $_SESSION['lighting_pending_simulations'] = $pending;
        return null;
    }
    if ($consume) {
        unset($pending[$token]);
        $_SESSION['lighting_pending_simulations'] = $pending;
    }

    $payload = $record['payload'] ?? null;
    if (!is_array($payload)) {
        return null;
    }
    $profileId = strtoupper(trim((string) ($payload['profile_id'] ?? '')));
    $profile = artdon_lighting_find_profile($profileId, artdon_db_open_ready());
    if ($profile === null) {
        return null;
    }
    $payload['profile'] = $profile;

    return $payload;
}

/**
 * Keep only report/project fields and round dense heatmap data before storing
 * it in the PHP session. The API response itself retains the full result.
 *
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function artdon_lighting_compact_pending_result(array $result): array
{
    $heatmap = is_array($result['heatmap'] ?? null) ? $result['heatmap'] : [];
    $values = is_array($heatmap['values_lux'] ?? null)
        ? array_values($heatmap['values_lux'])
        : [];
    if (count($values) > 1_296) {
        throw new InvalidArgumentException('The pending heatmap contains too many points.');
    }
    $heatmap['values_lux'] = array_map(static function (mixed $value): float {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new InvalidArgumentException('The pending heatmap contains an invalid value.');
        }
        return round(max(0.0, (float) $value), 3);
    }, $values);

    $compact = [];
    foreach ([
        'success',
        'calculation_hash',
        'engine_version',
        'mode',
        'room',
        'photometry',
        'metrics',
        'single',
        'maintenance_factor',
        'disclaimer',
    ] as $key) {
        if (array_key_exists($key, $result)) {
            $compact[$key] = $result[$key];
        }
    }
    $compact['layout'] = artdon_lighting_layout_without_fixtures(
        is_array($result['layout'] ?? null) ? $result['layout'] : []
    );
    $compact['heatmap'] = $heatmap;
    foreach (['assumptions', 'warnings'] as $key) {
        $messages = is_array($result[$key] ?? null) ? array_values($result[$key]) : [];
        $compact[$key] = array_map(
            static fn (mixed $message): string => artdon_lighting_clip_text((string) $message, 300),
            array_slice($messages, 0, 20)
        );
    }

    $safe = artdon_lighting_safe_json_value($compact, 0);
    if (!is_array($safe)) {
        throw new InvalidArgumentException('The pending simulation result is invalid.');
    }

    return $safe;
}

/**
 * @param array<string,mixed> $layout
 * @return array<string,mixed>
 */
function artdon_lighting_layout_without_fixtures(array $layout): array
{
    unset($layout['fixtures']);
    return $layout;
}

/**
 * @param array<string,mixed> $pending
 * @return array<string,mixed>
 */
function artdon_lighting_create_project(
    array $pending,
    string $projectName,
    ?PDO $pdo = null
): array {
    $pdo ??= artdon_db();
    $profile = $pending['profile'] ?? null;
    $result = $pending['result'] ?? null;
    $input = $pending['input'] ?? null;
    $configuration = $pending['configuration'] ?? [];
    $configuredModel = trim((string) (
        $pending['configured_model']
        ?? (is_array($input) ? ($input['configured_model'] ?? '') : '')
        ?? ''
    ));
    if (!is_array($profile) || !is_array($result) || !is_array($input) || !is_array($configuration)) {
        throw new InvalidArgumentException('The pending simulation is incomplete.');
    }
    if ($configuredModel === '') {
        $configuredModel = (string) $profile['configured_model'];
    }

    $projectName = artdon_lighting_clip_text(trim($projectName), 160);
    $room = (array) ($result['room'] ?? []);
    $layout = (array) ($result['layout'] ?? []);
    $metrics = (array) ($result['metrics'] ?? []);
    $mode = match ((string) ($result['mode'] ?? '')) {
        'single' => 'one_light',
        'layout' => 'manual_layout',
        default => 'auto_layout',
    };
    $publicId = artdon_lighting_public_id('SIM');
    $now = artdon_db_now();

    $resultWithoutHeatmap = $result;
    $heatmap = is_array($resultWithoutHeatmap['heatmap'] ?? null)
        ? $resultWithoutHeatmap['heatmap']
        : [];
    unset($resultWithoutHeatmap['heatmap']);

    $statement = $pdo->prepare(
        'INSERT INTO simulation_projects (
            public_id, session_key_hash, project_name, room_type, room_length_m,
            room_width_m, room_height_m, installation_height_m, work_plane_height_m,
            mounting_type, target_lux, maintenance_factor, product_id,
            ies_library_id, configured_model, configuration_snapshot_json,
            simulation_mode, fixture_quantity, layout_rows, layout_columns,
            spacing_x_m, spacing_y_m, average_lux, maximum_lux, minimum_lux,
            uniformity, input_snapshot_json, result_json, heatmap_json,
            algorithm_version, status, created_at, updated_at
        ) VALUES (
            :public_id, :session_key_hash, :project_name, :room_type, :room_length_m,
            :room_width_m, :room_height_m, :installation_height_m, :work_plane_height_m,
            :mounting_type, :target_lux, :maintenance_factor, :product_id,
            :ies_library_id, :configured_model, :configuration_snapshot_json,
            :simulation_mode, :fixture_quantity, :layout_rows, :layout_columns,
            :spacing_x_m, :spacing_y_m, :average_lux, :maximum_lux, :minimum_lux,
            :uniformity, :input_snapshot_json, :result_json, :heatmap_json,
            :algorithm_version, :status, :created_at, :updated_at
        )'
    );

    artdon_db_transaction(static function (PDO $pdo) use (
        $statement,
        $publicId,
        $projectName,
        $room,
        $layout,
        $metrics,
        $profile,
        $configuredModel,
        $configuration,
        $mode,
        $input,
        $resultWithoutHeatmap,
        $heatmap,
        $result,
        $now
    ): void {
        $statement->execute([
            ':public_id' => $publicId,
            ':session_key_hash' => artdon_lighting_session_key_hash(),
            ':project_name' => $projectName,
            ':room_type' => (string) ($room['type'] ?? ''),
            ':room_length_m' => (float) ($room['length_m'] ?? 0),
            ':room_width_m' => (float) ($room['width_m'] ?? 0),
            ':room_height_m' => (float) ($room['height_m'] ?? 0),
            ':installation_height_m' => (float) ($room['installation_height_m'] ?? 0),
            ':work_plane_height_m' => (float) ($room['calculation_plane_m'] ?? 0),
            ':mounting_type' => (string) ($room['mounting_type'] ?? ''),
            ':target_lux' => (float) ($room['target_lux'] ?? 0),
            ':maintenance_factor' => (float) ($result['maintenance_factor'] ?? 0.8),
            ':product_id' => (int) $profile['product_id'],
            ':ies_library_id' => (int) $profile['id'],
            ':configured_model' => $configuredModel,
            ':configuration_snapshot_json' => artdon_json_encode($configuration),
            ':simulation_mode' => $mode,
            ':fixture_quantity' => max(1, (int) ($layout['quantity'] ?? 1)),
            ':layout_rows' => isset($layout['rows']) ? (int) $layout['rows'] : null,
            ':layout_columns' => isset($layout['columns']) ? (int) $layout['columns'] : null,
            ':spacing_x_m' => isset($layout['spacing_x_m']) ? (float) $layout['spacing_x_m'] : null,
            ':spacing_y_m' => isset($layout['spacing_y_m']) ? (float) $layout['spacing_y_m'] : null,
            ':average_lux' => isset($metrics['average_lux']) ? (float) $metrics['average_lux'] : null,
            ':maximum_lux' => isset($metrics['maximum_lux']) ? (float) $metrics['maximum_lux'] : null,
            ':minimum_lux' => isset($metrics['minimum_lux']) ? (float) $metrics['minimum_lux'] : null,
            ':uniformity' => isset($metrics['uniformity_u0']) ? (float) $metrics['uniformity_u0'] : null,
            ':input_snapshot_json' => artdon_json_encode($input),
            ':result_json' => artdon_json_encode($resultWithoutHeatmap),
            ':heatmap_json' => artdon_json_encode($heatmap),
            ':algorithm_version' => (string) ($result['engine_version'] ?? SimulationService::ENGINE_VERSION),
            ':status' => 'completed',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }, $pdo);

    $project = artdon_lighting_find_project($publicId, artdon_lighting_session_key_hash(), $pdo);
    if ($project === null) {
        throw new RuntimeException('The simulation project could not be reloaded.');
    }

    return $project;
}

function artdon_lighting_find_project(
    string $publicId,
    ?string $sessionKeyHash = null,
    ?PDO $pdo = null
): ?array {
    $pdo ??= artdon_db();
    $sessionKeyHash ??= artdon_lighting_session_key_hash();
    $publicId = strtoupper(trim($publicId));
    if (!preg_match('/^SIM-[A-F0-9]{16}$/', $publicId)) {
        return null;
    }

    $statement = $pdo->prepare(
        "SELECT s.*, p.sku, p.name AS product_name, p.series_code,
                i.public_id AS ies_public_id, i.original_name AS ies_original_name,
                i.ies_standard, i.validation_status AS ies_validation_status,
                i.validation_messages_json
         FROM simulation_projects s
         INNER JOIN products p ON p.id = s.product_id
         INNER JOIN ies_library i ON i.id = s.ies_library_id
         WHERE s.public_id = :public_id
           AND s.session_key_hash = :session_key_hash
           AND s.status IN ('completed', 'draft')
         LIMIT 1"
    );
    $statement->execute([
        ':public_id' => $publicId,
        ':session_key_hash' => $sessionKeyHash,
    ]);
    $row = $statement->fetch();
    if (!$row) {
        return null;
    }

    $row['configuration'] = artdon_lighting_json_array((string) $row['configuration_snapshot_json']);
    $row['input'] = artdon_lighting_json_array((string) $row['input_snapshot_json']);
    $row['result'] = artdon_lighting_json_array((string) ($row['result_json'] ?? '{}'));
    $heatmap = artdon_lighting_json_array((string) ($row['heatmap_json'] ?? '{}'));
    $row['result']['heatmap'] = $heatmap;
    $row['ies_validation_messages'] = artdon_lighting_json_array((string) $row['validation_messages_json']);

    return $row;
}

/**
 * @param array<string,mixed> $project
 * @return array<string,mixed>
 */
function artdon_lighting_public_project(array $project): array
{
    $result = (array) ($project['result'] ?? []);
    $manufacturerValidated = artdon_lighting_manufacturer_validated($project);

    return [
        'id' => (string) $project['public_id'],
        'project_name' => (string) $project['project_name'],
        'status' => (string) $project['status'],
        'created_at' => (string) $project['created_at'],
        'product' => [
            'sku' => (string) $project['sku'],
            'name' => (string) $project['product_name'],
            'series' => (string) $project['series_code'],
            'configured_model' => (string) $project['configured_model'],
            'configuration' => (array) $project['configuration'],
        ],
        'ies' => [
            'profile_id' => (string) $project['ies_public_id'],
            'original_name' => (string) $project['ies_original_name'],
            'standard' => (string) $project['ies_standard'],
            'validation_status' => (string) $project['ies_validation_status'],
            'validation_messages' => (array) $project['ies_validation_messages'],
            'data_status' => str_starts_with((string) $project['ies_public_id'], 'IES-DEMO-')
                ? 'synthetic_preliminary_demo'
                : ($manufacturerValidated ? 'manufacturer_validated' : 'unverified_library_data'),
            'manufacturer_validated' => $manufacturerValidated,
        ],
        'result' => $result,
        'report' => [
            'available' => true,
            'url' => function_exists('url')
                ? url('api/lighting-report.php?id=' . rawurlencode((string) $project['public_id']))
                : '/api/lighting-report.php?id=' . rawurlencode((string) $project['public_id']),
        ],
        'disclaimer' => artdon_lighting_disclaimer(),
    ];
}

/**
 * Return a safe, derived report path. Client-provided paths and stored paths
 * are deliberately ignored.
 *
 * @param array<string,mixed> $project
 * @return array{relative:string,absolute:string,directory:string}
 */
function artdon_lighting_report_path(array $project): array
{
    $publicId = strtoupper((string) ($project['public_id'] ?? ''));
    if (!preg_match('/^SIM-[A-F0-9]{16}$/', $publicId)) {
        throw new InvalidArgumentException('The simulation report identity is invalid.');
    }
    $created = strtotime((string) ($project['created_at'] ?? '')) ?: time();
    $year = gmdate('Y', $created);
    $month = gmdate('m', $created);
    $relative = 'storage/reports/' . $year . '/' . $month . '/' . $publicId . '.pdf';
    $root = dirname(__DIR__);
    $directory = $root . '/storage/reports/' . $year . '/' . $month;
    $absolute = $directory . '/' . $publicId . '.pdf';

    $normalizedRoot = rtrim(str_replace('\\', '/', $root . '/storage/reports'), '/');
    $normalizedAbsolute = str_replace('\\', '/', $absolute);
    if (!str_starts_with($normalizedAbsolute, $normalizedRoot . '/') || str_contains($relative, '..')) {
        throw new RuntimeException('The simulation report path is outside the report directory.');
    }

    return ['relative' => $relative, 'absolute' => $absolute, 'directory' => $directory];
}

function artdon_lighting_record_report(
    string $publicId,
    string $relativePath,
    string $checksum,
    ?PDO $pdo = null
): void {
    $pdo ??= artdon_db();
    if (!preg_match('#^storage/reports/\d{4}/\d{2}/SIM-[A-F0-9]{16}\.pdf$#', $relativePath)) {
        throw new InvalidArgumentException('The report path is invalid.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
        throw new InvalidArgumentException('The report checksum is invalid.');
    }

    $statement = $pdo->prepare(
        'UPDATE simulation_projects
         SET report_path = :report_path,
             report_checksum_sha256 = :checksum,
             updated_at = :updated_at
         WHERE public_id = :public_id
           AND session_key_hash = :session_key_hash
           AND status = :status'
    );
    $statement->execute([
        ':report_path' => $relativePath,
        ':checksum' => $checksum,
        ':updated_at' => artdon_db_now(),
        ':public_id' => strtoupper($publicId),
        ':session_key_hash' => artdon_lighting_session_key_hash(),
        ':status' => 'completed',
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('The report metadata could not be recorded.');
    }
}

/**
 * @param array<string,mixed> $value
 * @return array<string,mixed>
 */
function artdon_lighting_canonical_array(array $value): array
{
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = artdon_lighting_canonical_array($item);
        }
    }

    return $value;
}

/**
 * @return array<mixed>
 */
function artdon_lighting_json_array(string $json): array
{
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Stored lighting JSON is invalid.', 0, $error);
    }

    return is_array($decoded) ? $decoded : [];
}

function artdon_lighting_safe_json_value(mixed $value, int $depth): mixed
{
    if ($depth > 8) {
        throw new InvalidArgumentException('The product configuration is too deeply nested.');
    }
    if (is_null($value) || is_bool($value) || is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('The product configuration contains an invalid number.');
        }
        return $value;
    }
    if (is_string($value)) {
        return artdon_lighting_clip_text(trim($value), 500);
    }
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $safeKey = is_int($key)
                ? $key
                : preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $key);
            if ($safeKey === '') {
                throw new InvalidArgumentException('The product configuration contains an invalid key.');
            }
            $clean[$safeKey] = artdon_lighting_safe_json_value($item, $depth + 1);
        }
        return $clean;
    }

    throw new InvalidArgumentException('The product configuration contains an unsupported value.');
}

function artdon_lighting_clip_text(string $value, int $max): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    return substr($value, 0, $max);
}

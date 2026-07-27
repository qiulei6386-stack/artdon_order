#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

function artdon_import_ies_usage(): string
{
    return <<<'TEXT'
Artdon IES Library import tool

Usage:
  php tools/import_ies.php --file=/path/profile.ies --product=SKU [options]

Required:
  --file=PATH          Candidate IES file (LM-63 Type C, TILT=NONE)
  --product=SKU        Existing active product SKU

Pending import:
  --model=MODEL        Provisional configured model represented by the file
  --options=JSON       Provisional configuration binding

Activation (all are required):
  --options=JSON       Complete product configuration; no defaults may be omitted
  --model=MODEL        Must exactly equal the server-generated configured model
  --validated          Record authorised operator approval of file provenance
  --activate           Activate this profile after every server check passes

Other:
  --version=N          Positive version; defaults to the next compatible version
  --database=PATH      Override APP_DATABASE_PATH for this command
  --help, -h           Show this help

An import without --activate is stored as PENDING ONLY and is never offered to
customers. --validated records an operator action; it is not a laboratory or
manufacturer performance certification.
TEXT;
}

/**
 * @return array<string,string>
 */
function artdon_import_parse_configuration(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }

    $decodedObject = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
    if (!is_object($decodedObject)) {
        throw new InvalidArgumentException('--options must be a JSON object.');
    }
    $decoded = (array) $decodedObject;
    $configuration = [];
    foreach ($decoded as $key => $value) {
        if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/i', (string) $key) !== 1) {
            throw new InvalidArgumentException('An option key is invalid.');
        }
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || strlen(trim((string) $value)) > 100
        ) {
            throw new InvalidArgumentException('An option value is invalid.');
        }
        $configuration[(string) $key] = trim((string) $value);
    }
    ksort($configuration, SORT_STRING);

    return $configuration;
}

/**
 * @param array<string,string> $configuration
 * @return array{
 *   configuration:array<string,string>,
 *   configured_model:string,
 *   configuration_schema_id:int,
 *   product:array<string,mixed>
 * }
 */
function artdon_import_validate_activation_configuration(
    string $productSku,
    array $configuration,
    PDO $pdo
): array {
    $product = artdon_configurator_product($productSku, $pdo);
    if ($product === null) {
        throw new InvalidArgumentException('No active product was found for SKU ' . $productSku . '.');
    }

    $schema = is_array($product['configuration_schema'] ?? null)
        ? $product['configuration_schema']
        : [];
    $expectedCodes = [];
    foreach ((array) ($schema['options'] ?? []) as $option) {
        if (!is_array($option)) {
            continue;
        }
        $code = trim((string) ($option['code'] ?? ''));
        if ($code !== '' && !in_array($code, $expectedCodes, true)) {
            $expectedCodes[] = $code;
        }
    }
    $providedCodes = array_keys($configuration);
    $missing = array_values(array_diff($expectedCodes, $providedCodes));
    $unknown = array_values(array_diff($providedCodes, $expectedCodes));
    if ($missing !== []) {
        throw new InvalidArgumentException(
            'Activation requires every product option. Missing: ' . implode(', ', $missing) . '.'
        );
    }
    if ($unknown !== []) {
        throw new InvalidArgumentException(
            'Activation contains unknown product options: ' . implode(', ', $unknown) . '.'
        );
    }

    $quantity = max(1, (int) ceil((float) ($product['moq'] ?? $product['default_moq'] ?? 1)));
    $configured = artdon_configurator_configure($productSku, $configuration, $quantity, $pdo);
    if (empty($configured['valid'])) {
        throw new InvalidArgumentException(
            'The product configuration was rejected by the server configurator: '
            . (string) ($configured['message'] ?? 'invalid configuration')
        );
    }

    $accepted = is_array($configured['configuration'] ?? null)
        ? array_map('strval', $configured['configuration'])
        : [];
    ksort($accepted, SORT_STRING);
    if (array_keys($accepted) !== array_keys($configuration)) {
        throw new InvalidArgumentException(
            'The server configurator did not accept the complete option set exactly.'
        );
    }
    foreach ($configuration as $key => $value) {
        if (!array_key_exists($key, $accepted) || $accepted[$key] !== $value) {
            throw new InvalidArgumentException(
                'The server configurator resolved a different value for option "' . $key . '".'
            );
        }
    }

    $schemaStatement = $pdo->prepare(
        "SELECT pcs.id
         FROM product_configuration_schemas pcs
         WHERE pcs.product_id = :product_id AND pcs.status = 'active'
         ORDER BY pcs.version DESC
         LIMIT 1"
    );
    $schemaStatement->execute([':product_id' => (int) $product['id']]);
    $schemaId = $schemaStatement->fetchColumn();
    if ($schemaId === false) {
        throw new InvalidArgumentException('The product has no active configuration schema.');
    }

    return [
        'configuration' => $accepted,
        'configured_model' => (string) ($configured['configured_model'] ?? ''),
        'configuration_schema_id' => (int) $schemaId,
        'product' => $product,
    ];
}

function artdon_import_next_version(PDO $pdo, int $productId, string $optionSignature): int
{
    $statement = $pdo->prepare(
        'SELECT COALESCE(MAX(version), 0) + 1
         FROM ies_library
         WHERE product_id = :product_id AND option_signature = :option_signature'
    );
    $statement->execute([
        ':product_id' => $productId,
        ':option_signature' => $optionSignature,
    ]);

    return max(1, (int) $statement->fetchColumn());
}

function artdon_import_assert_version_available(
    PDO $pdo,
    int $productId,
    string $optionSignature,
    int $version,
    ?int $excludeId = null
): void {
    $sql = 'SELECT public_id
            FROM ies_library
            WHERE product_id = :product_id
              AND option_signature = :option_signature
              AND version = :version';
    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
    }
    $sql .= ' LIMIT 1';
    $statement = $pdo->prepare($sql);
    $statement->bindValue(':product_id', $productId, PDO::PARAM_INT);
    $statement->bindValue(':option_signature', $optionSignature, PDO::PARAM_STR);
    $statement->bindValue(':version', $version, PDO::PARAM_INT);
    if ($excludeId !== null) {
        $statement->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $statement->execute();
    $conflict = $statement->fetchColumn();
    if (is_string($conflict) && $conflict !== '') {
        throw new InvalidArgumentException(
            sprintf(
                'Version %d already exists for this complete product configuration (%s).',
                $version,
                $conflict
            )
        );
    }
}

function artdon_import_storage_root(string $projectRoot): string
{
    $configured = trim((string) (getenv('APP_IES_STORAGE_PATH') ?: ''));
    if ($configured === '') {
        return $projectRoot . '/storage/ies';
    }
    $isAbsolute = str_starts_with($configured, '/')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured) === 1;

    return $isAbsolute ? $configured : $projectRoot . '/' . ltrim($configured, '/\\');
}

$arguments = array_slice($argv, 1);
if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    fwrite(STDOUT, artdon_import_ies_usage() . PHP_EOL);
    exit(0);
}

$options = getopt('', [
    'file:',
    'product:',
    'model:',
    'options:',
    'version:',
    'validated',
    'activate',
    'database:',
]);

if (isset($options['database'])) {
    $databasePath = trim((string) $options['database']);
    if ($databasePath === '') {
        fwrite(STDERR, "The --database path cannot be empty.\n");
        exit(2);
    }
    putenv('APP_DATABASE_PATH=' . $databasePath);
    $_ENV['APP_DATABASE_PATH'] = $databasePath;
}

$sourceInput = trim((string) ($options['file'] ?? ''));
$productSku = strtoupper(trim((string) ($options['product'] ?? '')));
$modelWasProvided = array_key_exists('model', $options);
$configuredModel = trim((string) ($options['model'] ?? $productSku));
$validated = array_key_exists('validated', $options);
$activate = array_key_exists('activate', $options);

if ($sourceInput === '' || $productSku === '') {
    fwrite(STDERR, "Both --file and --product are required.\n\n");
    fwrite(STDERR, artdon_import_ies_usage() . PHP_EOL);
    exit(2);
}
if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,79}$/', $productSku) !== 1) {
    fwrite(STDERR, "The product SKU format is invalid.\n");
    exit(2);
}
if ($configuredModel === '' || strlen($configuredModel) > 160) {
    fwrite(STDERR, "The configured model must contain 1-160 characters.\n");
    exit(2);
}
if ($activate && !$validated) {
    fwrite(STDERR, "--activate requires --validated.\n");
    exit(2);
}
if ($activate && !$modelWasProvided) {
    fwrite(STDERR, "--activate requires an explicit --model generated by the server configurator.\n");
    exit(2);
}
if ($activate && !array_key_exists('options', $options)) {
    fwrite(STDERR, "--activate requires a complete --options JSON object.\n");
    exit(2);
}

$sourcePath = realpath($sourceInput);
if ($sourcePath === false || !is_file($sourcePath) || !is_readable($sourcePath)) {
    fwrite(STDERR, "The IES source file is not readable.\n");
    exit(2);
}
if (strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) !== 'ies') {
    fwrite(STDERR, "The source filename must use the .ies extension.\n");
    exit(2);
}

try {
    $projectRoot = dirname(__DIR__);
    require_once $projectRoot . '/includes/database.php';
    require_once $projectRoot . '/includes/configurator.php';
    require_once $projectRoot . '/includes/lighting/IesParser.php';
    require_once $projectRoot . '/includes/lighting/PhotometricDistribution.php';

    $configuration = artdon_import_parse_configuration(
        array_key_exists('options', $options) ? (string) $options['options'] : null
    );
    $pdo = artdon_db_open_ready();
    $product = artdon_configurator_product($productSku, $pdo);
    if ($product === null) {
        throw new InvalidArgumentException('No active product was found for SKU ' . $productSku . '.');
    }

    $configurationSchemaId = null;
    if ($activate) {
        $activation = artdon_import_validate_activation_configuration($productSku, $configuration, $pdo);
        $configuration = $activation['configuration'];
        $configurationSchemaId = $activation['configuration_schema_id'];
        $generatedModel = $activation['configured_model'];
        if (!hash_equals($generatedModel, $configuredModel)) {
            throw new InvalidArgumentException(
                'The supplied --model does not exactly match the server-generated model. Expected: '
                . $generatedModel
            );
        }
    } else {
        $schemaStatement = $pdo->prepare(
            "SELECT pcs.id
             FROM product_configuration_schemas pcs
             WHERE pcs.product_id = :product_id AND pcs.status = 'active'
             ORDER BY pcs.version DESC
             LIMIT 1"
        );
        $schemaStatement->execute([':product_id' => (int) $product['id']]);
        $schemaId = $schemaStatement->fetchColumn();
        $configurationSchemaId = $schemaId === false ? null : (int) $schemaId;
    }
    ksort($configuration, SORT_STRING);
    $optionSignature = artdon_json_encode($configuration);

    $parser = new Artdon\Lighting\IesParser();
    $parsed = $parser->parseFile($sourcePath);
    $distribution = new Artdon\Lighting\PhotometricDistribution($parsed);
    $photometry = (array) ($parsed['photometry'] ?? []);
    $source = (array) ($parsed['source'] ?? []);
    $checksum = (string) ($source['sha256'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
        throw new RuntimeException('The parsed IES checksum is invalid.');
    }

    $explicitVersion = null;
    if (array_key_exists('version', $options)) {
        $versionText = trim((string) $options['version']);
        if (preg_match('/^[1-9][0-9]{0,5}$/', $versionText) !== 1) {
            throw new InvalidArgumentException('--version must be a positive integer.');
        }
        $explicitVersion = (int) $versionText;
    }

    $duplicate = $pdo->prepare(
        'SELECT *
         FROM ies_library
         WHERE checksum_sha256 = :checksum
         LIMIT 1'
    );
    $duplicate->execute([':checksum' => $checksum]);
    $existing = $duplicate->fetch();
    if ($existing && (int) $existing['product_id'] !== (int) $product['id']) {
        throw new InvalidArgumentException(
            'This checksum is already assigned to a different product (' . (string) $existing['public_id'] . ').'
        );
    }

    if ($existing && !$activate) {
        $requestedVersion = $explicitVersion ?? (int) $existing['version'];
        $sameMapping = hash_equals((string) $existing['option_signature'], $optionSignature)
            && hash_equals((string) $existing['configured_model'], $configuredModel)
            && (int) $existing['version'] === $requestedVersion;
        if (!$sameMapping) {
            throw new InvalidArgumentException(
                'This checksum is already pending or stored with a different model, configuration, or version ('
                . (string) $existing['public_id'] . ').'
            );
        }
        fwrite(
            STDOUT,
            sprintf(
                "IES already imported idempotently: %s (version %d, %s)\n",
                (string) $existing['public_id'],
                (int) $existing['version'],
                (string) $existing['status']
            )
        );
        exit(0);
    }

    if ($existing && $activate && (string) $existing['status'] === 'active') {
        $requestedVersion = $explicitVersion ?? (int) $existing['version'];
        $sameMapping = hash_equals((string) $existing['option_signature'], $optionSignature)
            && hash_equals((string) $existing['configured_model'], $configuredModel)
            && (int) $existing['version'] === $requestedVersion;
        if (!$sameMapping) {
            throw new InvalidArgumentException(
                'This checksum is already active with a different complete configuration, model, or version ('
                . (string) $existing['public_id'] . ').'
            );
        }
        fwrite(
            STDOUT,
            sprintf(
                "IES already active idempotently: %s (version %d)\n",
                (string) $existing['public_id'],
                (int) $existing['version']
            )
        );
        exit(0);
    }
    if ($existing && $activate && (string) $existing['status'] !== 'pending') {
        throw new InvalidArgumentException(
            'This checksum belongs to a non-pending library record and requires manual review ('
            . (string) $existing['public_id'] . ').'
        );
    }

    if ($existing && $activate && $explicitVersion === null
        && hash_equals((string) $existing['option_signature'], $optionSignature)
    ) {
        $version = (int) $existing['version'];
    } else {
        $version = $explicitVersion
            ?? artdon_import_next_version($pdo, (int) $product['id'], $optionSignature);
    }
    artdon_import_assert_version_available(
        $pdo,
        (int) $product['id'],
        $optionSignature,
        $version,
        $existing ? (int) $existing['id'] : null
    );

    $storageRoot = artdon_import_storage_root($projectRoot);
    $productDirectory = rtrim($storageRoot, '/\\') . '/' . strtolower($productSku);
    if (!is_dir($productDirectory)
        && !mkdir($productDirectory, 0750, true)
        && !is_dir($productDirectory)
    ) {
        throw new RuntimeException('The protected IES storage directory could not be created.');
    }
    $destination = $productDirectory . '/' . $checksum . '.ies';
    $newFile = !is_file($destination);
    if ($newFile) {
        if (!copy($sourcePath, $destination)) {
            throw new RuntimeException('The IES file could not be copied to protected storage.');
        }
        @chmod($destination, 0640);
    }
    if (!is_file($destination) || !hash_equals($checksum, (string) hash_file('sha256', $destination))) {
        if ($newFile && is_file($destination)) {
            @unlink($destination);
        }
        throw new RuntimeException('The stored IES checksum does not match the source.');
    }

    $defaultStorageRoot = $projectRoot . '/storage/ies';
    if (str_starts_with($destination, rtrim($defaultStorageRoot, '/\\') . '/')) {
        $relativePath = substr($destination, strlen($projectRoot) + 1);
    } else {
        $relativePath = $destination;
    }
    $publicId = $existing
        ? (string) $existing['public_id']
        : 'IES-LIB-' . strtoupper(bin2hex(random_bytes(8)));
    $lumensPerLamp = (float) ($photometry['lumens_per_lamp'] ?? -1);
    $lampCount = max(1, (int) ($photometry['lamp_count'] ?? 1));
    $lumens = $lumensPerLamp >= 0 ? $lumensPerLamp * $lampCount : null;
    $parserWarnings = array_values(array_map(
        'strval',
        (array) (($parsed['validation'] ?? [])['warnings'] ?? [])
    ));
    if ($activate) {
        $messages = array_merge($parserWarnings, [
            'File provenance approval was recorded by an authorised server operator.',
            'The complete option set was accepted by the server configurator.',
            'The supplied configured model exactly matched the server-generated model.',
            'Operator approval does not replace manufacturer or laboratory performance certification.',
        ]);
        $validationStatus = $parserWarnings === [] ? 'valid' : 'warning';
    } else {
        $messages = array_merge($parserWarnings, [
            'PENDING ONLY: configuration and configured-model activation checks have not been completed.',
            $validated
                ? 'File provenance approval was recorded, but this pending record is not customer-selectable.'
                : 'File provenance approval is pending; this record is not customer-selectable.',
        ]);
        $validationStatus = 'pending';
    }
    $now = artdon_db_now();

    try {
        artdon_db_transaction(
            static function (PDO $pdo) use (
                $activate,
                $existing,
                $product,
                $configurationSchemaId,
                $optionSignature,
                $publicId,
                $configuredModel,
                $version,
                $sourcePath,
                $relativePath,
                $checksum,
                $source,
                $photometry,
                $lumens,
                $distribution,
                $parsed,
                $messages,
                $validationStatus,
                $now
            ): void {
                if ($activate) {
                    $archive = $pdo->prepare(
                        "UPDATE ies_library
                         SET status = 'archived', updated_at = :updated_at
                         WHERE product_id = :product_id
                           AND option_signature = :option_signature
                           AND status = 'active'
                           AND id <> :current_id"
                    );
                    $archive->execute([
                        ':updated_at' => $now,
                        ':product_id' => (int) $product['id'],
                        ':option_signature' => $optionSignature,
                        ':current_id' => $existing ? (int) $existing['id'] : 0,
                    ]);
                }

                $values = [
                    ':product_id' => (int) $product['id'],
                    ':configuration_schema_id' => $configurationSchemaId,
                    ':option_signature' => $optionSignature,
                    ':configured_model' => $configuredModel,
                    ':version' => $version,
                    ':original_name' => basename($sourcePath),
                    ':file_path' => $relativePath,
                    ':checksum_sha256' => $checksum,
                    ':ies_standard' => (string) ($source['lm63_version_tag'] ?? ''),
                    ':photometric_type' => (string) ($photometry['type'] ?? ''),
                    ':tilt_mode' => (string) (($photometry['tilt'] ?? [])['mode'] ?? ''),
                    ':lumens' => $lumens,
                    ':power_w' => (float) ($photometry['input_watts'] ?? 0),
                    ':beam_angle_deg' => $distribution->beamAngle(0.0),
                    ':candela_multiplier' => (float) ($photometry['candela_multiplier'] ?? 1),
                    ':vertical_angles_json' => artdon_json_encode(
                        (array) ($photometry['vertical_angles_deg'] ?? [])
                    ),
                    ':horizontal_angles_json' => artdon_json_encode(
                        (array) ($photometry['horizontal_angles_deg'] ?? [])
                    ),
                    ':distribution_json' => artdon_json_encode(
                        (array) ($photometry['candela_cd'] ?? [])
                    ),
                    ':parsed_data_json' => artdon_json_encode($parsed),
                    ':parser_version' => (string) ($parsed['parser_version'] ?? ''),
                    ':validation_status' => $validationStatus,
                    ':validation_messages_json' => artdon_json_encode($messages),
                    ':status' => $activate ? 'active' : 'pending',
                    ':updated_at' => $now,
                ];

                if ($existing) {
                    $update = $pdo->prepare(
                        'UPDATE ies_library SET
                            product_id = :product_id,
                            configuration_schema_id = :configuration_schema_id,
                            option_signature = :option_signature,
                            configured_model = :configured_model,
                            version = :version,
                            original_name = :original_name,
                            file_path = :file_path,
                            checksum_sha256 = :checksum_sha256,
                            ies_standard = :ies_standard,
                            photometric_type = :photometric_type,
                            tilt_mode = :tilt_mode,
                            lumens = :lumens,
                            power_w = :power_w,
                            beam_angle_deg = :beam_angle_deg,
                            candela_multiplier = :candela_multiplier,
                            vertical_angles_json = :vertical_angles_json,
                            horizontal_angles_json = :horizontal_angles_json,
                            distribution_json = :distribution_json,
                            parsed_data_json = :parsed_data_json,
                            parser_version = :parser_version,
                            validation_status = :validation_status,
                            validation_messages_json = :validation_messages_json,
                            status = :status,
                            updated_at = :updated_at
                         WHERE id = :id AND status = :expected_status'
                    );
                    $values[':id'] = (int) $existing['id'];
                    $values[':expected_status'] = 'pending';
                    $update->execute($values);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('The pending IES record changed during activation.');
                    }
                    return;
                }

                $insert = $pdo->prepare(
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
                     )'
                );
                $insert->execute(array_merge($values, [
                    ':public_id' => $publicId,
                    ':created_at' => $now,
                ]));
            },
            $pdo
        );
    } catch (Throwable $error) {
        if ($newFile && is_file($destination)) {
            @unlink($destination);
        }
        throw $error;
    }

    fwrite(STDOUT, ($existing ? 'Activated existing pending IES profile: ' : 'Imported IES profile: ') . $publicId . PHP_EOL);
    fwrite(STDOUT, 'Product / model: ' . $productSku . ' / ' . $configuredModel . PHP_EOL);
    fwrite(STDOUT, 'Version / status: ' . $version . ' / ' . ($activate ? 'active' : 'PENDING ONLY') . PHP_EOL);
    fwrite(STDOUT, 'Configuration signature: ' . $optionSignature . PHP_EOL);
    fwrite(STDOUT, 'Checksum: ' . $checksum . PHP_EOL);
} catch (JsonException | InvalidArgumentException $error) {
    fwrite(STDERR, 'Import rejected: ' . $error->getMessage() . PHP_EOL);
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Import failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

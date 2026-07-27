<?php

declare(strict_types=1);

/**
 * SQLite foundation for the procurement platform.
 *
 * Runtime requests only open a database that has already been migrated.
 * Schema creation and demo-data seeding are CLI maintenance operations.
 */

final class ArtdonDatabaseUnavailable extends RuntimeException
{
}

function artdon_database_path(): string
{
    $configured = trim((string) (getenv('APP_DATABASE_PATH') ?: ''));
    if ($configured === '') {
        return dirname(__DIR__) . '/storage/artdon.sqlite';
    }

    $isAbsolute = str_starts_with($configured, '/')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured) === 1;

    return $isAbsolute ? $configured : dirname(__DIR__) . '/' . ltrim($configured, '/\\');
}

function artdon_db(bool $allowCreate = false): PDO
{
    /** @var array<string,PDO> $connections */
    static $connections = [];

    $path = artdon_database_path();
    if (isset($connections[$path])) {
        return $connections[$path];
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new ArtdonDatabaseUnavailable('The application data store is unavailable.');
    }

    $directory = dirname($path);
    if ($allowCreate && !is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the SQLite database directory.');
    }
    if (!$allowCreate && (!is_file($path) || !is_readable($path) || !is_writable($path))) {
        throw new ArtdonDatabaseUnavailable('The application data store is not initialized.');
    }
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new ArtdonDatabaseUnavailable('The application data store is unavailable.');
    }

    try {
        $connection = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $connection->exec('PRAGMA foreign_keys = ON');
        if ($allowCreate) {
            $connection->exec('PRAGMA journal_mode = WAL');
        }
        $connection->exec('PRAGMA synchronous = NORMAL');
        $connection->exec('PRAGMA busy_timeout = 5000');
        $connection->exec('PRAGMA temp_store = MEMORY');
    } catch (PDOException $error) {
        throw new ArtdonDatabaseUnavailable(
            'The application data store is unavailable.',
            0,
            $error
        );
    }

    if ($allowCreate && is_file($path)) {
        @chmod($path, 0640);
    }

    $connections[$path] = $connection;

    return $connections[$path];
}

function artdon_db_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/**
 * Execute a callback atomically. Nested calls use SQLite savepoints.
 *
 * @template T
 * @param callable(PDO):T $callback
 * @return T
 */
function artdon_db_transaction(callable $callback, ?PDO $pdo = null): mixed
{
    $pdo ??= artdon_db();
    $nested = $pdo->inTransaction();
    $savepoint = 'artdon_' . bin2hex(random_bytes(6));

    if ($nested) {
        $pdo->exec('SAVEPOINT ' . $savepoint);
    } else {
        $pdo->beginTransaction();
    }

    try {
        $result = $callback($pdo);
        if ($nested) {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } else {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($nested) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function artdon_db_require_cli_maintenance(): void
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Database maintenance is available from the command line only.');
    }
}

/**
 * Tables required by the currently deployed application.
 *
 * @return list<string>
 */
function artdon_db_required_tables(): array
{
    return [
        'schema_migrations',
        'products',
        'product_configuration_schemas',
        'project_carts',
        'project_cart_items',
        'procurement_requests',
        'procurement_request_items',
        'procurement_attachments',
        'sync_jobs',
        'audit_logs',
        'ies_library',
        'simulation_projects',
    ];
}

/**
 * Inspect schema state using SELECT/PRAGMA reads only.
 *
 * @return array{ready:bool,issues:list<string>,applied_migrations:list<string>}
 */
function artdon_db_readiness(?PDO $pdo = null): array
{
    $pdo ??= artdon_db(false);
    $issues = [];

    try {
        $rows = $pdo->query(
            "SELECT name
             FROM sqlite_master
             WHERE type = 'table'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_fill_keys(array_map('strval', $rows), true);
    } catch (PDOException $error) {
        throw new ArtdonDatabaseUnavailable(
            'The application data store schema cannot be inspected.',
            0,
            $error
        );
    }

    foreach (artdon_db_required_tables() as $table) {
        if (!isset($tables[$table])) {
            $issues[] = 'missing_table:' . $table;
        }
    }

    $applied = [];
    if (isset($tables['schema_migrations'])) {
        try {
            $migrationRows = $pdo->query(
                'SELECT migration, checksum
                 FROM schema_migrations
                 ORDER BY migration'
            )->fetchAll();
            foreach ($migrationRows as $row) {
                $applied[(string) $row['migration']] = (string) $row['checksum'];
            }
        } catch (PDOException $error) {
            throw new ArtdonDatabaseUnavailable(
                'The application data store schema cannot be inspected.',
                0,
                $error
            );
        }
    }

    $files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
    sort($files, SORT_STRING);
    if ($files === []) {
        $issues[] = 'migration_files_missing';
    }
    foreach ($files as $file) {
        $migrationId = pathinfo($file, PATHINFO_FILENAME);
        $checksum = hash_file('sha256', $file);
        if (!isset($applied[$migrationId])) {
            $issues[] = 'migration_not_applied:' . $migrationId;
        } elseif (!is_string($checksum) || !hash_equals($applied[$migrationId], $checksum)) {
            $issues[] = 'migration_checksum_mismatch:' . $migrationId;
        }
    }

    return [
        'ready' => $issues === [],
        'issues' => array_values(array_unique($issues)),
        'applied_migrations' => array_keys($applied),
    ];
}

/**
 * Open an already-provisioned runtime database without migrating or seeding.
 */
function artdon_db_open_ready(): PDO
{
    $pdo = artdon_db(false);
    $readiness = artdon_db_readiness($pdo);
    if (!$readiness['ready']) {
        throw new ArtdonDatabaseUnavailable('The application data store schema is not ready.');
    }

    return $pdo;
}

/**
 * Apply all database/migrations/*.php files in filename order.
 *
 * @return array{applied:list<string>,skipped:list<string>}
 */
function artdon_db_migrate(?PDO $pdo = null): array
{
    artdon_db_require_cli_maintenance();
    $pdo ??= artdon_db(true);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration TEXT PRIMARY KEY,
            checksum TEXT NOT NULL,
            applied_at TEXT NOT NULL
        )'
    );

    $migrationDirectory = dirname(__DIR__) . '/database/migrations';
    $files = glob($migrationDirectory . '/*.php') ?: [];
    sort($files, SORT_STRING);

    $appliedRows = $pdo->query('SELECT migration, checksum FROM schema_migrations')->fetchAll();
    $applied = [];
    foreach ($appliedRows as $row) {
        $applied[(string) $row['migration']] = (string) $row['checksum'];
    }

    $result = ['applied' => [], 'skipped' => []];
    foreach ($files as $file) {
        $migration = require $file;
        if (!is_array($migration) || !isset($migration['id'], $migration['up']) || !is_callable($migration['up'])) {
            throw new RuntimeException('Invalid migration definition: ' . $file);
        }

        $id = (string) $migration['id'];
        $expectedId = pathinfo($file, PATHINFO_FILENAME);
        if ($id !== $expectedId) {
            throw new RuntimeException(sprintf('Migration id "%s" must match filename "%s".', $id, $expectedId));
        }

        $checksum = hash_file('sha256', $file);
        if ($checksum === false) {
            throw new RuntimeException('Unable to checksum migration: ' . $file);
        }

        if (isset($applied[$id])) {
            if (!hash_equals($applied[$id], $checksum)) {
                throw new RuntimeException('Applied migration was modified: ' . $id);
            }
            $result['skipped'][] = $id;
            continue;
        }

        artdon_db_transaction(static function (PDO $pdo) use ($migration, $id, $checksum): void {
            ($migration['up'])($pdo);
            $statement = $pdo->prepare(
                'INSERT INTO schema_migrations (migration, checksum, applied_at)
                 VALUES (:migration, :checksum, :applied_at)'
            );
            $statement->execute([
                ':migration' => $id,
                ':checksum' => $checksum,
                ':applied_at' => artdon_db_now(),
            ]);
        }, $pdo);

        $result['applied'][] = $id;
    }

    return $result;
}

function artdon_json_encode(mixed $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

/**
 * Seed the existing demo catalog without overwriting future master-data rows.
 *
 * @return int Number of demo products ensured.
 */
function artdon_db_seed_demo_catalog(?PDO $pdo = null): int
{
    artdon_db_require_cli_maintenance();
    $pdo ??= artdon_db();
    $productsFile = dirname(__DIR__) . '/config/products.php';
    $configurationFile = dirname(__DIR__) . '/config/product_configuration.php';
    $products = require $productsFile;
    $configurations = require $configurationFile;

    if (!is_array($products) || !is_array($configurations)) {
        throw new RuntimeException('Demo catalog configuration is invalid.');
    }

    return artdon_db_transaction(static function (PDO $pdo) use ($products, $configurations): int {
        $now = artdon_db_now();
        $productStatement = $pdo->prepare(
            'INSERT INTO products (
                source_system, source_id, sku, slug, name, series_code,
                category_slug, subcategory_slug, stock_group, summary,
                specs_json, features_json, image_path, badge, status,
                order_enabled, sample_enabled, price_mode, base_currency,
                base_price, default_moq, lead_time_text, stock_quantity,
                is_new, is_clearance, created_at, updated_at
            ) VALUES (
                :source_system, :source_id, :sku, :slug, :name, :series_code,
                :category_slug, :subcategory_slug, :stock_group, :summary,
                :specs_json, :features_json, :image_path, :badge, :status,
                1, 1, :price_mode, :base_currency, :base_price, :default_moq,
                :lead_time_text, :stock_quantity, :is_new, :is_clearance,
                :created_at, :updated_at
            )
            ON CONFLICT(sku) DO UPDATE SET
                source_id = excluded.source_id,
                slug = excluded.slug,
                name = excluded.name,
                series_code = excluded.series_code,
                category_slug = excluded.category_slug,
                subcategory_slug = excluded.subcategory_slug,
                stock_group = excluded.stock_group,
                summary = excluded.summary,
                specs_json = excluded.specs_json,
                features_json = excluded.features_json,
                image_path = excluded.image_path,
                badge = excluded.badge,
                status = excluded.status,
                order_enabled = excluded.order_enabled,
                sample_enabled = excluded.sample_enabled,
                price_mode = excluded.price_mode,
                base_currency = excluded.base_currency,
                base_price = excluded.base_price,
                default_moq = excluded.default_moq,
                lead_time_text = excluded.lead_time_text,
                stock_quantity = excluded.stock_quantity,
                is_new = excluded.is_new,
                is_clearance = excluded.is_clearance,
                updated_at = excluded.updated_at
            WHERE products.source_system = :updatable_source'
        );
        $findProduct = $pdo->prepare('SELECT id, source_system FROM products WHERE sku = :sku');
        $schemaStatement = $pdo->prepare(
            'INSERT INTO product_configuration_schemas (
                product_id, version, source_system, schema_json, checksum,
                status, published_at, created_at, updated_at
            ) VALUES (
                :product_id, 1, :source_system, :schema_json, :checksum,
                :status, :published_at, :created_at, :updated_at
            )
            ON CONFLICT(product_id, version) DO UPDATE SET
                schema_json = excluded.schema_json,
                checksum = excluded.checksum,
                status = excluded.status,
                published_at = excluded.published_at,
                updated_at = excluded.updated_at
            WHERE product_configuration_schemas.source_system = :updatable_source'
        );

        $count = 0;
        foreach ($products as $product) {
            if (!is_array($product) || empty($product['sku']) || empty($product['name'])) {
                continue;
            }

            $sku = (string) $product['sku'];
            $category = (string) ($product['category'] ?? '');
            $schema = $configurations[$sku]
                ?? $configurations[$category]
                ?? $configurations['default']
                ?? ['price_mode' => 'review', 'sku_order' => ['series'], 'options' => [], 'rules' => []];
            $priceMode = (string) ($schema['price_mode'] ?? 'review');
            if (!in_array($priceMode, ['fixed', 'from', 'review'], true)) {
                $priceMode = 'review';
            }

            $productStatement->execute([
                ':source_system' => 'demo_config',
                ':source_id' => $sku,
                ':sku' => $sku,
                ':slug' => strtolower($sku),
                ':name' => (string) $product['name'],
                ':series_code' => (string) ($product['series'] ?? ''),
                ':category_slug' => $category,
                ':subcategory_slug' => (string) ($product['subcategory'] ?? ''),
                ':stock_group' => (string) ($product['stock_group'] ?? ''),
                ':summary' => (string) ($product['summary'] ?? ''),
                ':specs_json' => artdon_json_encode((array) ($product['specs'] ?? [])),
                ':features_json' => artdon_json_encode(array_values((array) ($product['features'] ?? []))),
                ':image_path' => (string) ($product['image'] ?? ''),
                ':badge' => (string) ($product['badge'] ?? ''),
                ':status' => 'active',
                ':price_mode' => $priceMode,
                ':base_currency' => 'USD',
                ':base_price' => is_numeric($product['price'] ?? null) ? (float) $product['price'] : null,
                ':default_moq' => max(0.001, (float) ($product['moq'] ?? 1)),
                ':lead_time_text' => (string) ($product['lead_time'] ?? ''),
                ':stock_quantity' => max(0, (float) ($product['stock'] ?? 0)),
                ':is_new' => !empty($product['new']) ? 1 : 0,
                ':is_clearance' => !empty($product['clearance']) ? 1 : 0,
                ':created_at' => $now,
                ':updated_at' => $now,
                ':updatable_source' => 'demo_config',
            ]);

            $findProduct->execute([':sku' => $sku]);
            $storedProduct = $findProduct->fetch();
            if (!$storedProduct) {
                throw new RuntimeException('Unable to locate seeded product: ' . $sku);
            }

            if ((string) $storedProduct['source_system'] === 'demo_config') {
                $schemaJson = artdon_json_encode($schema);
                $schemaStatement->execute([
                    ':product_id' => (int) $storedProduct['id'],
                    ':source_system' => 'demo_config',
                    ':schema_json' => $schemaJson,
                    ':checksum' => hash('sha256', $schemaJson),
                    ':status' => 'active',
                    ':published_at' => $now,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                    ':updatable_source' => 'demo_config',
                ]);
            }
            $count++;
        }

        return $count;
    }, $pdo);
}

/**
 * Connect, migrate, and optionally seed the current demo catalog.
 *
 * @return array{pdo:PDO,migrations:array{applied:list<string>,skipped:list<string>},seeded:int}
 */
function artdon_db_bootstrap(bool $seedDemoCatalog = true): array
{
    artdon_db_require_cli_maintenance();
    $pdo = artdon_db(true);
    $migrations = artdon_db_migrate($pdo);
    $seeded = $seedDemoCatalog ? artdon_db_seed_demo_catalog($pdo) : 0;

    return ['pdo' => $pdo, 'migrations' => $migrations, 'seeded' => $seeded];
}

/**
 * Return active catalog products in the same shape as config/products.php.
 *
 * Supported filters: q, category, subcategory, stock_group, status,
 * ready_only, new_only, clearance_only, limit, offset.
 *
 * @return list<array<string,mixed>>
 */
function artdon_catalog_all(array $filters = [], ?PDO $pdo = null): array
{
    $pdo ??= artdon_db();
    $conditions = [];
    $parameters = [];

    $status = array_key_exists('status', $filters) ? $filters['status'] : 'active';
    if ($status !== null && $status !== '') {
        $conditions[] = 'p.status = :status';
        $parameters[':status'] = (string) $status;
    }
    foreach (['category' => 'category_slug', 'subcategory' => 'subcategory_slug', 'stock_group' => 'stock_group'] as $filter => $column) {
        if (isset($filters[$filter]) && trim((string) $filters[$filter]) !== '') {
            $conditions[] = 'p.' . $column . ' = :' . $filter;
            $parameters[':' . $filter] = trim((string) $filters[$filter]);
        }
    }
    if (!empty($filters['ready_only'])) {
        $conditions[] = 'p.stock_quantity > 0';
    }
    if (!empty($filters['new_only'])) {
        $conditions[] = 'p.is_new = 1';
    }
    if (!empty($filters['clearance_only'])) {
        $conditions[] = 'p.is_clearance = 1';
    }
    if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
        $conditions[] = "LOWER(p.sku || ' ' || p.name || ' ' || p.series_code || ' ' || p.summary || ' ' || p.features_json || ' ' || p.specs_json) LIKE :query";
        $parameters[':query'] = '%' . strtolower(trim((string) $filters['q'])) . '%';
    }

    $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $sql = "
        SELECT p.*,
               (
                   SELECT pcs.schema_json
                   FROM product_configuration_schemas pcs
                   WHERE pcs.product_id = p.id AND pcs.status = 'active'
                   ORDER BY pcs.version DESC
                   LIMIT 1
               ) AS configuration_schema_json
        FROM products p
        {$where}
        ORDER BY p.category_slug, p.series_code, p.sku
        LIMIT :limit OFFSET :offset
    ";
    $statement = $pdo->prepare($sql);
    foreach ($parameters as $name => $value) {
        $statement->bindValue($name, $value, PDO::PARAM_STR);
    }
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    return array_map('artdon_catalog_hydrate', $statement->fetchAll());
}

function artdon_catalog_find_by_sku(string $sku, ?PDO $pdo = null): ?array
{
    $pdo ??= artdon_db();
    $statement = $pdo->prepare(
        "SELECT p.*,
                (
                    SELECT pcs.schema_json
                    FROM product_configuration_schemas pcs
                    WHERE pcs.product_id = p.id AND pcs.status = 'active'
                    ORDER BY pcs.version DESC
                    LIMIT 1
                ) AS configuration_schema_json
         FROM products p
         WHERE p.sku = :sku
         LIMIT 1"
    );
    $statement->execute([':sku' => trim($sku)]);
    $row = $statement->fetch();

    return $row ? artdon_catalog_hydrate($row) : null;
}

function artdon_catalog_configuration_schema(int|string $product, ?PDO $pdo = null): ?array
{
    $pdo ??= artdon_db();
    $byId = is_int($product);
    $statement = $pdo->prepare(
        'SELECT pcs.schema_json
         FROM product_configuration_schemas pcs
         INNER JOIN products p ON p.id = pcs.product_id
         WHERE ' . ($byId ? 'p.id = :product' : 'p.sku = :product') . "
           AND pcs.status = 'active'
         ORDER BY pcs.version DESC
         LIMIT 1"
    );
    $statement->bindValue(':product', $product, $byId ? PDO::PARAM_INT : PDO::PARAM_STR);
    $statement->execute();
    $json = $statement->fetchColumn();

    if (!is_string($json) || $json === '') {
        return null;
    }

    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function artdon_catalog_hydrate(array $row): array
{
    $features = json_decode((string) ($row['features_json'] ?? '[]'), true);
    $specs = json_decode((string) ($row['specs_json'] ?? '{}'), true);
    $schema = json_decode((string) ($row['configuration_schema_json'] ?? '{}'), true);

    $row['series'] = (string) ($row['series_code'] ?? '');
    $row['category'] = (string) ($row['category_slug'] ?? '');
    $row['subcategory'] = (string) ($row['subcategory_slug'] ?? '');
    $row['price'] = $row['base_price'] === null ? null : (float) $row['base_price'];
    $row['stock'] = (float) ($row['stock_quantity'] ?? 0);
    $row['lead_time'] = (string) ($row['lead_time_text'] ?? '');
    $row['moq'] = (float) ($row['default_moq'] ?? 1);
    $row['image'] = (string) ($row['image_path'] ?? '');
    $row['new'] = (bool) ($row['is_new'] ?? false);
    $row['clearance'] = (bool) ($row['is_clearance'] ?? false);
    $row['features'] = is_array($features) ? $features : [];
    $row['specs'] = is_array($specs) ? $specs : [];
    $row['configuration_schema'] = is_array($schema) ? $schema : [];
    unset($row['features_json'], $row['specs_json'], $row['configuration_schema_json']);

    return $row;
}

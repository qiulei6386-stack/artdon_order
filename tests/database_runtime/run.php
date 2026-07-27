<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$temporaryRoot = sys_get_temp_dir() . '/artdon-database-runtime-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryRoot, 0750, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('Unable to create the database runtime test directory.');
}

/**
 * @param callable():void $test
 */
function runtime_test(string $name, callable $test): void
{
    static $passed = 0;
    $test();
    $passed++;
    fwrite(STDOUT, sprintf("ok %d - %s\n", $passed, $name));
}

/**
 * @param callable():mixed $callback
 */
function runtime_expect_database_unavailable(callable $callback): void
{
    try {
        $callback();
    } catch (ArtdonDatabaseUnavailable) {
        return;
    }
    throw new RuntimeException('Expected ArtdonDatabaseUnavailable.');
}

require_once dirname(__DIR__, 2) . '/includes/database.php';

try {
    $missingPath = $temporaryRoot . '/missing.sqlite';
    putenv('APP_DATABASE_PATH=' . $missingPath);
    runtime_test('runtime open does not create a missing database', static function () use ($missingPath): void {
        runtime_expect_database_unavailable(static fn (): PDO => artdon_db_open_ready());
        if (file_exists($missingPath)) {
            throw new RuntimeException('Runtime readiness created the missing database.');
        }
    });

    $readyPath = $temporaryRoot . '/ready.sqlite';
    putenv('APP_DATABASE_PATH=' . $readyPath);
    $bootstrap = artdon_db_bootstrap(false);
    runtime_test('CLI migration prepares a ready schema', static function () use ($bootstrap): void {
        $readiness = artdon_db_readiness($bootstrap['pdo']);
        if (!$readiness['ready'] || $readiness['issues'] !== []) {
            throw new RuntimeException('Migrated schema was not ready.');
        }
    });
    runtime_test('runtime open accepts the migrated database without schema writes', static function () use ($readyPath): void {
        $before = filemtime($readyPath);
        $pdo = artdon_db_open_ready();
        $after = filemtime($readyPath);
        if (!$pdo instanceof PDO || $before !== $after) {
            throw new RuntimeException('Runtime readiness unexpectedly modified the database file.');
        }
    });

    $incompletePath = $temporaryRoot . '/incomplete.sqlite';
    $incomplete = new PDO('sqlite:' . $incompletePath);
    $incomplete->exec(
        'CREATE TABLE schema_migrations (
            migration TEXT PRIMARY KEY,
            checksum TEXT NOT NULL,
            applied_at TEXT NOT NULL
        )'
    );
    $incomplete = null;
    putenv('APP_DATABASE_PATH=' . $incompletePath);
    runtime_test('runtime open rejects an incomplete schema', static function (): void {
        runtime_expect_database_unavailable(static fn (): PDO => artdon_db_open_ready());
    });

    fwrite(STDOUT, "database runtime tests passed\n");
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $path) {
        $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
    }
    @rmdir($temporaryRoot);
}

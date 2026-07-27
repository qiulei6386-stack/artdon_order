#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$arguments = array_slice($argv, 1);
if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    fwrite(STDOUT, <<<TEXT
Artdon SQLite migration tool

Usage:
  php tools/migrate.php [--database=/absolute/or/project-relative/path] [--no-seed]

Options:
  --database=PATH  Override APP_DATABASE_PATH for this command.
  --no-seed        Apply migrations without seeding the demo catalog or demo IES profiles.
  --help, -h       Show this help.

TEXT);
    exit(0);
}

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--database=')) {
        $databasePath = trim(substr($argument, strlen('--database=')));
        if ($databasePath === '') {
            fwrite(STDERR, "The --database path cannot be empty.\n");
            exit(2);
        }
        putenv('APP_DATABASE_PATH=' . $databasePath);
        $_ENV['APP_DATABASE_PATH'] = $databasePath;
    }
}

$seedDemo = !in_array('--no-seed', $arguments, true);

try {
    require_once dirname(__DIR__) . '/includes/database.php';
    require_once dirname(__DIR__) . '/includes/lighting_repository.php';
    $result = artdon_db_bootstrap($seedDemo);
    $seededProfiles = $seedDemo
        ? artdon_lighting_seed_demo_profiles($result['pdo'])
        : 0;
    $readiness = artdon_db_readiness($result['pdo']);
    if (!$readiness['ready']) {
        throw new RuntimeException(
            'Database readiness check failed: ' . implode(', ', $readiness['issues'])
        );
    }
    $applied = $result['migrations']['applied'];
    $skipped = $result['migrations']['skipped'];

    fwrite(STDOUT, 'Database: ' . artdon_database_path() . PHP_EOL);
    fwrite(STDOUT, 'Applied migrations: ' . ($applied ? implode(', ', $applied) : 'none') . PHP_EOL);
    fwrite(STDOUT, 'Already applied: ' . ($skipped ? implode(', ', $skipped) : 'none') . PHP_EOL);
    fwrite(STDOUT, 'Demo products ensured: ' . $result['seeded'] . PHP_EOL);
    fwrite(STDOUT, 'Demo IES profiles ensured: ' . $seededProfiles . PHP_EOL);
    fwrite(STDOUT, "Readiness: ready\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

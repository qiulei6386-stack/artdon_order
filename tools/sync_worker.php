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
Artdon ERP / CRM outbound sync worker

Usage:
  php tools/sync_worker.php [--once] [--limit=N] [--database=PATH]

Options:
  --once           Explicitly run one bounded batch and exit (the default).
  --single-run     Alias for --once.
  --limit=N        Process at most N due jobs (1-100; default: 1).
  --database=PATH  Override APP_DATABASE_PATH for this command.
  --help, -h       Show this help.

Required environment:
  ERP_API_URL      Full HTTPS destination URL.
  ERP_API_TOKEN    Bearer token and HMAC signing secret.

TEXT);
    exit(0);
}

$limit = 1;
for ($index = 0, $count = count($arguments); $index < $count; $index++) {
    $argument = $arguments[$index];
    if (in_array($argument, ['--once', '--single-run'], true)) {
        continue;
    }
    if (str_starts_with($argument, '--limit=')) {
        $value = substr($argument, strlen('--limit='));
    } elseif ($argument === '--limit' && isset($arguments[$index + 1])) {
        $value = $arguments[++$index];
    } elseif (str_starts_with($argument, '--database=')) {
        $databasePath = trim(substr($argument, strlen('--database=')));
        if ($databasePath === '') {
            fwrite(STDERR, "The --database path cannot be empty.\n");
            exit(2);
        }
        putenv('APP_DATABASE_PATH=' . $databasePath);
        $_ENV['APP_DATABASE_PATH'] = $databasePath;
        continue;
    } elseif ($argument !== '') {
        fwrite(STDERR, 'Unknown option: ' . $argument . PHP_EOL);
        exit(2);
    } else {
        continue;
    }

    if (
        filter_var($value, FILTER_VALIDATE_INT) === false
        || (int) $value < 1
        || (int) $value > 100
    ) {
        fwrite(STDERR, "The --limit value must be an integer from 1 to 100.\n");
        exit(2);
    }
    $limit = (int) $value;
}

require_once dirname(__DIR__) . '/includes/integration.php';

try {
    // Validate the destination before opening the already-provisioned queue.
    // This guarantees a missing credential cannot consume a pending job.
    $config = artdon_integration_config();
    $pdo = artdon_db_open_ready();
    $result = artdon_integration_run($pdo, $config, $limit);

    fwrite(
        STDOUT,
        sprintf(
            "Sync batch complete: claimed=%d success=%d failed=%d dead=%d\n",
            $result['claimed'],
            $result['success'],
            $result['failed'],
            $result['dead']
        )
    );
    foreach ($result['jobs'] as $job) {
        fwrite(
            STDOUT,
            sprintf(
                "Job %d: %s%s\n",
                $job['job_id'],
                $job['status'],
                $job['http_status'] > 0 ? ' (HTTP ' . $job['http_status'] . ')' : ''
            )
        );
    }

    exit(($result['failed'] + $result['dead']) > 0 ? 1 : 0);
} catch (ArtdonIntegrationConfigurationException $error) {
    fwrite(STDERR, 'Sync worker is not configured: ' . $error->getMessage() . PHP_EOL);
    exit(78);
} catch (Throwable $error) {
    fwrite(STDERR, 'Sync worker failed safely: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

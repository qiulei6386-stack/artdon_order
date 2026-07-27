#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/**
 * Conservative retention cleanup for disposable runtime records/files.
 *
 * Submitted procurement records, submitted carts, owned/referenced completed
 * simulations and active attachment files are intentionally outside this
 * tool's deletion set. Expired guest-only simulations are disposable.
 */

$arguments = array_slice($argv, 1);
if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    fwrite(STDOUT, <<<'TEXT'
Artdon retention cleanup

Usage:
  php tools/cleanup.php [options]

The default mode is a read-only dry run. Nothing is changed unless --apply is
provided explicitly.

Options:
  --apply                    Apply the displayed cleanup plan.
  --database=PATH            Override APP_DATABASE_PATH.
  --cart-days=N              Retain expired/abandoned carts for N days (default 30).
  --simulation-days=N        Retain draft/failed/archived simulations for N days (default 90).
  --guest-completed-days=N   Retain unowned, unreferenced completed simulations for N days (default 30).
  --orphan-report-days=N     Retain orphan report files for N days (default 30).
  --orphan-upload-days=N     Retain orphan/deleted upload files for N days (default 30).
  --json                     Emit machine-readable JSON.
  --help, -h                 Show this help.

TEXT);
    exit(0);
}

$apply = in_array('--apply', $arguments, true);
$jsonOutput = in_array('--json', $arguments, true);
$retention = [
    'cart_days' => 30,
    'simulation_days' => 90,
    'guest_completed_days' => 30,
    'orphan_report_days' => 30,
    'orphan_upload_days' => 30,
];
$optionMap = [
    '--cart-days=' => 'cart_days',
    '--simulation-days=' => 'simulation_days',
    '--guest-completed-days=' => 'guest_completed_days',
    '--orphan-report-days=' => 'orphan_report_days',
    '--orphan-upload-days=' => 'orphan_upload_days',
];

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--database=')) {
        $databasePath = trim(substr($argument, strlen('--database=')));
        if ($databasePath === '') {
            fwrite(STDERR, "The --database path cannot be empty.\n");
            exit(2);
        }
        putenv('APP_DATABASE_PATH=' . $databasePath);
        $_ENV['APP_DATABASE_PATH'] = $databasePath;
        continue;
    }

    $matched = false;
    foreach ($optionMap as $prefix => $key) {
        if (!str_starts_with($argument, $prefix)) {
            continue;
        }
        $matched = true;
        $value = substr($argument, strlen($prefix));
        if (preg_match('/^[1-9][0-9]{0,3}$/', $value) !== 1) {
            fwrite(STDERR, sprintf("%s must be an integer from 1 to 9999.\n", rtrim($prefix, '=')));
            exit(2);
        }
        $retention[$key] = (int) $value;
        break;
    }
    if ($matched || in_array($argument, ['--apply', '--json'], true)) {
        continue;
    }
    if (str_starts_with($argument, '-')) {
        fwrite(STDERR, 'Unknown option: ' . $argument . PHP_EOL);
        exit(2);
    }
}

require_once dirname(__DIR__) . '/includes/database.php';

/**
 * @return list<array<string,mixed>>
 */
function artdon_cleanup_rows(PDO $pdo, string $sql, array $parameters = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return array_values($statement->fetchAll());
}

/**
 * @return array<string,true>
 */
function artdon_cleanup_path_set(array $rows, string $column): array
{
    $paths = [];
    foreach ($rows as $row) {
        $path = str_replace('\\', '/', trim((string) ($row[$column] ?? '')));
        if ($path !== '' && !str_contains($path, "\0")) {
            $paths[ltrim($path, '/')] = true;
        }
    }
    return $paths;
}

/**
 * @return array<string,array{absolute:string,mtime:int}>
 */
function artdon_cleanup_files(string $projectRoot, string $relativeRoot): array
{
    $absoluteRoot = $projectRoot . '/' . trim($relativeRoot, '/');
    if (!is_dir($absoluteRoot)) {
        return [];
    }

    $realRoot = realpath($absoluteRoot);
    if ($realRoot === false) {
        return [];
    }
    $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $realRoot,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->isLink() || !$file->isFile()) {
            continue;
        }
        $absolute = str_replace('\\', '/', $file->getPathname());
        if (!str_starts_with($absolute, $realRoot . '/')) {
            continue;
        }
        $relative = trim($relativeRoot, '/') . '/' . ltrim(substr($absolute, strlen($realRoot)), '/');
        $files[$relative] = [
            'absolute' => $absolute,
            'mtime' => max(0, (int) $file->getMTime()),
        ];
    }

    return $files;
}

try {
    $pdo = artdon_db_open_ready();
    $now = time();
    $nowSql = gmdate('Y-m-d H:i:s', $now);
    $cartCutoff = gmdate('Y-m-d H:i:s', $now - ($retention['cart_days'] * 86400));
    $simulationCutoff = gmdate('Y-m-d H:i:s', $now - ($retention['simulation_days'] * 86400));
    $guestCompletedCutoff = gmdate('Y-m-d H:i:s', $now - ($retention['guest_completed_days'] * 86400));
    $reportCutoffUnix = $now - ($retention['orphan_report_days'] * 86400);
    $uploadCutoffSql = gmdate('Y-m-d H:i:s', $now - ($retention['orphan_upload_days'] * 86400));
    $uploadCutoffUnix = $now - ($retention['orphan_upload_days'] * 86400);

    $expirableCarts = artdon_cleanup_rows(
        $pdo,
        "SELECT id
         FROM project_carts
         WHERE status = 'active'
           AND expires_at IS NOT NULL
           AND expires_at <= :now",
        [':now' => $nowSql]
    );
    $deletableCarts = artdon_cleanup_rows(
        $pdo,
        "SELECT c.id
         FROM project_carts c
         WHERE c.status IN ('expired', 'abandoned')
           AND c.updated_at <= :cutoff
           AND NOT EXISTS (
               SELECT 1 FROM procurement_requests r WHERE r.cart_id = c.id
           )",
        [':cutoff' => $cartCutoff]
    );
    $deletableSimulations = artdon_cleanup_rows(
        $pdo,
        "SELECT s.id, s.public_id, s.report_path
         FROM simulation_projects s
         WHERE s.status IN ('draft', 'failed', 'archived')
           AND s.updated_at <= :cutoff
           AND NOT EXISTS (
               SELECT 1
               FROM project_cart_items ci
               WHERE ci.simulation_project_id = s.id
                  OR (
                      s.report_path IS NOT NULL
                      AND s.report_path <> ''
                      AND ci.simulation_report_path = s.report_path
                  )
           )
           AND NOT EXISTS (
               SELECT 1
               FROM procurement_request_items ri
               WHERE (
                       s.report_path IS NOT NULL
                       AND s.report_path <> ''
                       AND ri.simulation_report_path = s.report_path
                     )
                  OR ri.simulation_snapshot_json LIKE
                     '%' || s.public_id || '%'
           )",
        [':cutoff' => $simulationCutoff]
    );
    $deletableGuestCompletedSimulations = artdon_cleanup_rows(
        $pdo,
        "SELECT s.id, s.public_id, s.report_path
         FROM simulation_projects s
         WHERE s.status = 'completed'
           AND s.customer_id IS NULL
           AND s.owner_user_id IS NULL
           AND s.updated_at <= :cutoff
           AND NOT EXISTS (
               SELECT 1
               FROM project_cart_items ci
               WHERE ci.simulation_project_id = s.id
                  OR (
                      s.report_path IS NOT NULL
                      AND s.report_path <> ''
                      AND ci.simulation_report_path = s.report_path
                  )
           )
           AND NOT EXISTS (
               SELECT 1
               FROM procurement_request_items ri
               WHERE (
                       s.report_path IS NOT NULL
                       AND s.report_path <> ''
                       AND ri.simulation_report_path = s.report_path
                     )
                  OR ri.simulation_snapshot_json LIKE
                     '%' || s.public_id || '%'
           )",
        [':cutoff' => $guestCompletedCutoff]
    );
    $allDeletableSimulations = array_merge(
        $deletableSimulations,
        $deletableGuestCompletedSimulations
    );

    $simulationReportReferences = artdon_cleanup_path_set(
        artdon_cleanup_rows($pdo, "SELECT report_path FROM simulation_projects WHERE report_path IS NOT NULL AND report_path <> ''"),
        'report_path'
    );
    $cartReportReferences = artdon_cleanup_path_set(
        artdon_cleanup_rows($pdo, "SELECT simulation_report_path FROM project_cart_items WHERE simulation_report_path IS NOT NULL AND simulation_report_path <> ''"),
        'simulation_report_path'
    );
    $requestReportReferences = artdon_cleanup_path_set(
        artdon_cleanup_rows($pdo, "SELECT simulation_report_path FROM procurement_request_items WHERE simulation_report_path IS NOT NULL AND simulation_report_path <> ''"),
        'simulation_report_path'
    );
    $protectedReportReferences = $cartReportReferences + $requestReportReferences;
    $allReportReferences = $simulationReportReferences + $protectedReportReferences;
    $staleSimulationReports = artdon_cleanup_path_set($allDeletableSimulations, 'report_path');

    $attachmentRows = artdon_cleanup_rows(
        $pdo,
        'SELECT stored_path, status, created_at FROM procurement_attachments'
    );
    $allUploadReferences = [];
    $protectedUploadReferences = [];
    $deletedUploadReferences = [];
    foreach ($attachmentRows as $attachment) {
        $path = ltrim(str_replace('\\', '/', trim((string) ($attachment['stored_path'] ?? ''))), '/');
        if ($path === '' || str_contains($path, "\0")) {
            continue;
        }
        $allUploadReferences[$path] = true;
        if ((string) $attachment['status'] !== 'deleted') {
            $protectedUploadReferences[$path] = true;
        } elseif ((string) $attachment['created_at'] <= $uploadCutoffSql) {
            $deletedUploadReferences[$path] = true;
        }
    }

    $projectRoot = dirname(__DIR__);
    $reportFiles = artdon_cleanup_files($projectRoot, 'storage/reports');
    $uploadFiles = artdon_cleanup_files($projectRoot, 'storage/uploads');
    $fileCandidates = [
        'stale_simulation_reports' => [],
        'orphan_reports' => [],
        'deleted_attachment_files' => [],
        'orphan_uploads' => [],
    ];

    foreach ($reportFiles as $relative => $file) {
        if (isset($staleSimulationReports[$relative]) && !isset($protectedReportReferences[$relative])) {
            $fileCandidates['stale_simulation_reports'][$relative] = $file;
            continue;
        }
        if (!isset($allReportReferences[$relative]) && $file['mtime'] <= $reportCutoffUnix) {
            $fileCandidates['orphan_reports'][$relative] = $file;
        }
    }
    foreach ($uploadFiles as $relative => $file) {
        if (isset($deletedUploadReferences[$relative]) && !isset($protectedUploadReferences[$relative])) {
            $fileCandidates['deleted_attachment_files'][$relative] = $file;
            continue;
        }
        if (!isset($allUploadReferences[$relative]) && $file['mtime'] <= $uploadCutoffUnix) {
            $fileCandidates['orphan_uploads'][$relative] = $file;
        }
    }

    $databaseCounts = [
        'active_carts_to_expire' => count($expirableCarts),
        'expired_or_abandoned_carts_to_delete' => count($deletableCarts),
        'stale_noncompleted_simulations_to_delete' => count($deletableSimulations),
        'stale_unowned_completed_simulations_to_delete' => count($deletableGuestCompletedSimulations),
    ];
    if ($apply) {
        $databaseCounts = artdon_db_transaction(
            static function (PDO $pdo) use (
                $nowSql,
                $cartCutoff,
                $simulationCutoff,
                $guestCompletedCutoff
            ): array {
                $expire = $pdo->prepare(
                    "UPDATE project_carts
                     SET status = 'expired', updated_at = :now
                     WHERE status = 'active'
                       AND expires_at IS NOT NULL
                       AND expires_at <= :now"
                );
                $expire->execute([':now' => $nowSql]);

                $deleteCarts = $pdo->prepare(
                    "DELETE FROM project_carts
                     WHERE status IN ('expired', 'abandoned')
                       AND updated_at <= :cutoff
                       AND NOT EXISTS (
                           SELECT 1
                           FROM procurement_requests r
                           WHERE r.cart_id = project_carts.id
                       )"
                );
                $deleteCarts->execute([':cutoff' => $cartCutoff]);

                $deleteSimulations = $pdo->prepare(
                    "DELETE FROM simulation_projects
                     WHERE status IN ('draft', 'failed', 'archived')
                       AND updated_at <= :cutoff
                       AND NOT EXISTS (
                           SELECT 1
                           FROM project_cart_items ci
                           WHERE ci.simulation_project_id = simulation_projects.id
                              OR (
                                  simulation_projects.report_path IS NOT NULL
                                  AND simulation_projects.report_path <> ''
                                  AND ci.simulation_report_path = simulation_projects.report_path
                              )
                       )
                       AND NOT EXISTS (
                           SELECT 1
                           FROM procurement_request_items ri
                           WHERE (
                                   simulation_projects.report_path IS NOT NULL
                                   AND simulation_projects.report_path <> ''
                                   AND ri.simulation_report_path = simulation_projects.report_path
                                 )
                              OR ri.simulation_snapshot_json LIKE
                                 '%' || simulation_projects.public_id || '%'
                       )"
                );
                $deleteSimulations->execute([':cutoff' => $simulationCutoff]);

                $deleteGuestCompleted = $pdo->prepare(
                    "DELETE FROM simulation_projects
                     WHERE status = 'completed'
                       AND customer_id IS NULL
                       AND owner_user_id IS NULL
                       AND updated_at <= :cutoff
                       AND NOT EXISTS (
                           SELECT 1
                           FROM project_cart_items ci
                           WHERE ci.simulation_project_id = simulation_projects.id
                              OR (
                                  simulation_projects.report_path IS NOT NULL
                                  AND simulation_projects.report_path <> ''
                                  AND ci.simulation_report_path = simulation_projects.report_path
                              )
                       )
                       AND NOT EXISTS (
                           SELECT 1
                           FROM procurement_request_items ri
                           WHERE (
                                   simulation_projects.report_path IS NOT NULL
                                   AND simulation_projects.report_path <> ''
                                   AND ri.simulation_report_path = simulation_projects.report_path
                                 )
                              OR ri.simulation_snapshot_json LIKE
                                 '%' || simulation_projects.public_id || '%'
                       )"
                );
                $deleteGuestCompleted->execute([':cutoff' => $guestCompletedCutoff]);

                return [
                    'active_carts_to_expire' => $expire->rowCount(),
                    'expired_or_abandoned_carts_to_delete' => $deleteCarts->rowCount(),
                    'stale_noncompleted_simulations_to_delete' => $deleteSimulations->rowCount(),
                    'stale_unowned_completed_simulations_to_delete' => $deleteGuestCompleted->rowCount(),
                ];
            },
            $pdo
        );
    }

    $filesRemoved = 0;
    $fileErrors = 0;
    if ($apply) {
        $uniqueFiles = [];
        foreach ($fileCandidates as $files) {
            foreach ($files as $relative => $file) {
                $uniqueFiles[$relative] = $file['absolute'];
            }
        }
        foreach ($uniqueFiles as $absolute) {
            if (!is_file($absolute)) {
                continue;
            }
            if (@unlink($absolute)) {
                $filesRemoved++;
            } else {
                $fileErrors++;
            }
        }
    }

    $result = [
        'mode' => $apply ? 'apply' : 'dry-run',
        'generated_at' => gmdate(DATE_ATOM, $now),
        'retention_days' => $retention,
        'database' => $databaseCounts,
        'files' => [
            'stale_simulation_reports' => count($fileCandidates['stale_simulation_reports']),
            'orphan_reports' => count($fileCandidates['orphan_reports']),
            'deleted_attachment_files' => count($fileCandidates['deleted_attachment_files']),
            'orphan_uploads' => count($fileCandidates['orphan_uploads']),
            'removed' => $filesRemoved,
            'errors' => $fileErrors,
        ],
        'safety' => [
            'submitted_carts_deleted' => 0,
            'procurement_requests_deleted' => 0,
            'owned_completed_simulations_deleted' => 0,
            'referenced_completed_simulations_deleted' => 0,
            'active_attachment_files_deleted' => 0,
        ],
    ];

    if ($jsonOutput) {
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDOUT, 'Mode: ' . $result['mode'] . PHP_EOL);
        fwrite(STDOUT, 'Active carts to expire: ' . $databaseCounts['active_carts_to_expire'] . PHP_EOL);
        fwrite(STDOUT, 'Expired/abandoned carts to delete: ' . $databaseCounts['expired_or_abandoned_carts_to_delete'] . PHP_EOL);
        fwrite(STDOUT, 'Stale non-completed simulations to delete: ' . $databaseCounts['stale_noncompleted_simulations_to_delete'] . PHP_EOL);
        fwrite(STDOUT, 'Stale unowned completed simulations to delete: ' . $databaseCounts['stale_unowned_completed_simulations_to_delete'] . PHP_EOL);
        fwrite(STDOUT, 'Stale simulation reports: ' . count($fileCandidates['stale_simulation_reports']) . PHP_EOL);
        fwrite(STDOUT, 'Orphan reports: ' . count($fileCandidates['orphan_reports']) . PHP_EOL);
        fwrite(STDOUT, 'Deleted attachment files: ' . count($fileCandidates['deleted_attachment_files']) . PHP_EOL);
        fwrite(STDOUT, 'Orphan uploads: ' . count($fileCandidates['orphan_uploads']) . PHP_EOL);
        if ($apply) {
            fwrite(STDOUT, 'Files removed: ' . $filesRemoved . PHP_EOL);
            fwrite(STDOUT, 'File removal errors: ' . $fileErrors . PHP_EOL);
        } else {
            fwrite(STDOUT, "Dry run only. Re-run with --apply to execute this plan.\n");
        }
    }

    exit($fileErrors === 0 ? 0 : 1);
} catch (ArtdonDatabaseUnavailable $error) {
    fwrite(STDERR, "Cleanup unavailable: the database is missing or not ready. Run tools/migrate.php first.\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Cleanup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

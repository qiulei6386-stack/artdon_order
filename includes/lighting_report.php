<?php

declare(strict_types=1);

require_once __DIR__ . '/lighting_repository.php';
require_once __DIR__ . '/simple_pdf.php';

/**
 * Ensure that a completed, session-owned simulation has a verified PDF report.
 *
 * The file is rendered into the destination directory and then atomically
 * renamed into place. Existing files are reused only when their PDF structure,
 * derived path, stored checksum, and calculated checksum all agree.
 *
 * @param array<string,mixed> $project Hydrated lighting project.
 * @return array{absolute:string,relative:string,size:int,checksum:string}
 */
function artdon_lighting_ensure_report(array $project, ?PDO $pdo = null): array
{
    $pdo ??= artdon_db();
    $publicId = strtoupper(trim((string) ($project['public_id'] ?? '')));
    if (!preg_match('/^SIM-[A-F0-9]{16}$/', $publicId)) {
        throw new InvalidArgumentException('The simulation report identity is invalid.');
    }

    $metadata = artdon_lighting_report_metadata($publicId, $pdo);
    $pathProject = $project;
    $pathProject['public_id'] = $publicId;
    $pathProject['created_at'] = $metadata['created_at'];
    $path = artdon_lighting_report_service_path($pathProject);
    $inspection = artdon_lighting_inspect_report_file($path['absolute']);
    $storedChecksum = $metadata['checksum'];
    $canReuse = $inspection !== null
        && $metadata['relative'] === $path['relative']
        && preg_match('/^[a-f0-9]{64}$/', $storedChecksum) === 1
        && hash_equals($storedChecksum, $inspection['checksum']);

    if (!$canReuse) {
        artdon_lighting_prepare_report_directory($path['directory']);
        $bytes = artdon_simple_pdf_report($project);
        if (!artdon_lighting_valid_report_bytes($bytes)) {
            throw new RuntimeException('The generated report is invalid.');
        }

        $expectedChecksum = hash('sha256', $bytes);
        artdon_lighting_publish_report_atomically($path['absolute'], $path['directory'], $bytes);
        $inspection = artdon_lighting_inspect_report_file($path['absolute']);
        if ($inspection === null || !hash_equals($expectedChecksum, $inspection['checksum'])) {
            @unlink($path['absolute']);
            throw new RuntimeException('The published report could not be verified.');
        }

        artdon_lighting_record_report(
            $publicId,
            $path['relative'],
            $inspection['checksum'],
            $pdo
        );
    }

    if ($inspection === null) {
        throw new RuntimeException('The simulation report is unavailable.');
    }

    return [
        'absolute' => $path['absolute'],
        'relative' => $path['relative'],
        'size' => $inspection['size'],
        'checksum' => $inspection['checksum'],
    ];
}

/**
 * Read authoritative report metadata while confirming that the project still
 * belongs to the active session and remains completed.
 *
 * @return array{relative:string,checksum:string,created_at:string}
 */
function artdon_lighting_report_metadata(string $publicId, PDO $pdo): array
{
    $statement = $pdo->prepare(
        "SELECT report_path, report_checksum_sha256, created_at
         FROM simulation_projects
         WHERE public_id = :public_id
           AND session_key_hash = :session_key_hash
           AND status = 'completed'
         LIMIT 1"
    );
    $statement->execute([
        ':public_id' => $publicId,
        ':session_key_hash' => artdon_lighting_session_key_hash(),
    ]);
    $row = $statement->fetch();
    if (!$row) {
        throw new RuntimeException('The simulation report project is unavailable.');
    }

    return [
        'relative' => (string) ($row['report_path'] ?? ''),
        'checksum' => strtolower((string) ($row['report_checksum_sha256'] ?? '')),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * Resolve the derived report path. ARTDON_REPORT_STORAGE_PATH is an
 * operator-only override used for isolated storage mounts and tests; the
 * database-facing relative path remains stable.
 *
 * @param array<string,mixed> $project
 * @return array{relative:string,absolute:string,directory:string}
 */
function artdon_lighting_report_service_path(array $project): array
{
    $path = artdon_lighting_report_path($project);
    $configuredRoot = trim((string) (getenv('ARTDON_REPORT_STORAGE_PATH') ?: ''));
    if ($configuredRoot === '') {
        return $path;
    }

    $isAbsolute = str_starts_with($configuredRoot, '/')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $configuredRoot) === 1;
    if (!$isAbsolute || str_contains($configuredRoot, "\0")) {
        throw new RuntimeException('The configured report storage path is invalid.');
    }

    $prefix = 'storage/reports/';
    if (!str_starts_with($path['relative'], $prefix)) {
        throw new RuntimeException('The simulation report relative path is invalid.');
    }
    $suffix = substr($path['relative'], strlen($prefix));
    if ($suffix === '' || str_contains($suffix, '..')) {
        throw new RuntimeException('The simulation report storage suffix is invalid.');
    }

    $root = rtrim($configuredRoot, DIRECTORY_SEPARATOR);
    $absolute = $root . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $suffix);
    $directory = dirname($absolute);
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    $normalizedAbsolute = str_replace('\\', '/', $absolute);
    if ($normalizedRoot === '' || !str_starts_with($normalizedAbsolute, $normalizedRoot . '/')) {
        throw new RuntimeException('The simulation report path is outside the configured storage directory.');
    }

    return [
        'relative' => $path['relative'],
        'absolute' => $absolute,
        'directory' => $directory,
    ];
}

function artdon_lighting_prepare_report_directory(string $directory): void
{
    if (!is_dir($directory)
        && !mkdir($directory, 0750, true)
        && !is_dir($directory)
    ) {
        throw new RuntimeException('The report directory could not be created.');
    }
    @chmod($directory, 0750);
    if (!is_writable($directory)) {
        throw new RuntimeException('The report directory is not writable.');
    }
}

function artdon_lighting_valid_report_bytes(string $bytes): bool
{
    $length = strlen($bytes);

    return $length >= 2_000
        && $length <= 8 * 1024 * 1024
        && str_starts_with($bytes, '%PDF-1.4')
        && str_ends_with(rtrim($bytes), '%%EOF');
}

/**
 * @return array{size:int,checksum:string}|null
 */
function artdon_lighting_inspect_report_file(string $absolutePath): ?array
{
    clearstatcache(true, $absolutePath);
    if (!is_file($absolutePath) || is_link($absolutePath)) {
        return null;
    }

    $size = filesize($absolutePath);
    if (!is_int($size) || $size < 2_000 || $size > 8 * 1024 * 1024) {
        return null;
    }

    $handle = @fopen($absolutePath, 'rb');
    if ($handle === false) {
        return null;
    }
    try {
        $header = fread($handle, 8);
        if (!is_string($header) || !str_starts_with($header, '%PDF-1.4')) {
            return null;
        }
        if (fseek($handle, max(0, $size - 64), SEEK_SET) !== 0) {
            return null;
        }
        $trailer = stream_get_contents($handle);
        if (!is_string($trailer) || !str_ends_with(rtrim($trailer), '%%EOF')) {
            return null;
        }
    } finally {
        fclose($handle);
    }

    $checksum = hash_file('sha256', $absolutePath);
    if (!is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
        return null;
    }

    return ['size' => $size, 'checksum' => $checksum];
}

function artdon_lighting_publish_report_atomically(
    string $absolutePath,
    string $directory,
    string $bytes
): void {
    $temporary = tempnam($directory, '.lighting-report-');
    if ($temporary === false) {
        throw new RuntimeException('A temporary report file could not be created.');
    }

    $handle = null;
    try {
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The temporary report file could not be opened.');
        }

        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('The complete report could not be written.');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('The report file could not be flushed.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('The report file could not be synchronized.');
        }
        fclose($handle);
        $handle = null;

        @chmod($temporary, 0640);
        if (!rename($temporary, $absolutePath)) {
            throw new RuntimeException('The report could not be published.');
        }
        clearstatcache(true, $absolutePath);
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

<?php

declare(strict_types=1);

/**
 * Transactional procurement-request service.
 *
 * Order requests are always built from the current server-side Project Cart.
 * cart_json, client prices, client models, and client product snapshots are
 * intentionally ignored.
 */

final class ArtdonProcurementException extends RuntimeException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

const ARTDON_PROCUREMENT_MAX_ATTACHMENTS = 10;
const ARTDON_PROCUREMENT_MAX_FILE_BYTES = 10_485_760;
// Keep the PHP limit below the 20 MB reverse-proxy limit, including multipart
// framing overhead.
const ARTDON_PROCUREMENT_MAX_TOTAL_UPLOAD_BYTES = 18_874_368;
const ARTDON_PROCUREMENT_MAX_ARCHIVE_ENTRIES = 1_000;
const ARTDON_PROCUREMENT_MAX_ARCHIVE_UNCOMPRESSED_BYTES = 104_857_600;
const ARTDON_PROCUREMENT_DEFAULT_UPLOAD_QUOTA_BYTES = 2_147_483_648;
const ARTDON_PROCUREMENT_DEFAULT_FREE_SPACE_RESERVE_BYTES = 1_073_741_824;

/**
 * @return never
 */
function artdon_procurement_fail(
    string $code,
    string $message,
    int $status = 422,
    array $details = []
): never {
    throw new ArtdonProcurementException($code, $message, $status, $details);
}

function artdon_procurement_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/**
 * @param array<string,mixed> $input
 */
function artdon_procurement_text(
    array $input,
    string $key,
    int $maximum,
    bool $required = false
): string {
    $raw = $input[$key] ?? '';
    if (!is_scalar($raw) && $raw !== null) {
        artdon_procurement_fail(
            'invalid_field',
            sprintf('%s must be text.', $key),
            422,
            ['field' => $key]
        );
    }

    $value = trim((string) $raw);
    $value = preg_replace('/\R/u', "\n", $value) ?? $value;
    if ($required && $value === '') {
        artdon_procurement_fail(
            'required_field',
            sprintf('%s is required.', $key),
            422,
            ['field' => $key]
        );
    }
    if (artdon_procurement_text_length($value) > $maximum) {
        artdon_procurement_fail(
            'field_too_long',
            sprintf('%s cannot exceed %d characters.', $key, $maximum),
            422,
            ['field' => $key, 'maximum' => $maximum]
        );
    }

    return $value;
}

function artdon_procurement_normalize_type(string $value): string
{
    $key = strtolower(trim($value));
    $types = [
        'order_request' => 'order_request',
        'quick_rfq' => 'quick_rfq',
        'quick-rfq' => 'quick_rfq',
        'sample' => 'sample',
        'sample-order' => 'sample',
        'ready_stock' => 'ready_stock',
        'ready-stock' => 'ready_stock',
        'oem' => 'oem',
        'odm' => 'odm',
        'bulk' => 'bulk',
        'bulk-order' => 'bulk',
        'project_package' => 'project_package',
        'project-package' => 'project_package',
        'service' => 'service',
        'procurement-service' => 'service',
        'contact' => 'contact',
    ];

    if (!isset($types[$key])) {
        artdon_procurement_fail(
            'invalid_request_type',
            'The request type is not supported.',
            422,
            ['field' => 'form_type']
        );
    }

    return $types[$key];
}

function artdon_procurement_verify_csrf(string $provided, ?string $expected = null): void
{
    $expected ??= (string) ($_SESSION['csrf_token'] ?? '');
    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        artdon_procurement_fail(
            'csrf_failed',
            'The form session expired. Refresh the page and try again.',
            419
        );
    }
}

function artdon_procurement_submission_token(mixed $value): string
{
    if (!is_string($value) || preg_match('/^[a-f0-9]{40}$/i', $value) !== 1) {
        artdon_procurement_fail(
            'invalid_submission_token',
            'The submission token is invalid. Refresh the page and try again.',
            422,
            ['field' => 'submission_token']
        );
    }

    return strtolower($value);
}

function artdon_procurement_idempotency_key(string $sessionHash, string $submissionToken): string
{
    if (preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1) {
        throw new InvalidArgumentException('The session hash is invalid.');
    }

    return hash('sha256', 'artdon-procurement-v1|' . $sessionHash . '|' . $submissionToken);
}

/**
 * @return array<string,mixed>|null
 */
function artdon_procurement_find_idempotent(PDO $pdo, string $idempotencyKey): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, public_id, request_no, request_type, status, company_snapshot_json
         FROM procurement_requests
         WHERE idempotency_key = :idempotency_key
         LIMIT 1'
    );
    $statement->execute([':idempotency_key' => $idempotencyKey]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @param array<string,mixed> $normalized
 * @param list<array{name:string,tmp_name:string,size:int,extension:string,mime_type:string,checksum_sha256:string}> $uploads
 */
function artdon_procurement_submission_fingerprint(array $normalized, array $uploads): string
{
    ksort($normalized);
    $uploadFingerprint = array_map(
        static fn(array $upload): array => [
            'name' => $upload['name'],
            'size' => $upload['size'],
            'extension' => $upload['extension'],
            'mime_type' => $upload['mime_type'],
            'checksum_sha256' => $upload['checksum_sha256'],
        ],
        $uploads
    );

    return hash('sha256', artdon_json_encode([
        'schema_version' => 1,
        'fields' => $normalized,
        'attachments' => $uploadFingerprint,
    ]));
}

/**
 * @return array{success:bool,duplicate:bool,message:string,reference:string,request_id:int,request_type:string,item_count:int,attachment_count:int}
 */
function artdon_procurement_duplicate_response(array $existing, string $fingerprint): array
{
    $snapshot = [];
    try {
        $decoded = json_decode(
            (string) ($existing['company_snapshot_json'] ?? ''),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $snapshot = is_array($decoded) ? $decoded : [];
    } catch (JsonException) {
        $snapshot = [];
    }
    $storedFingerprint = (string) ($snapshot['submission_fingerprint'] ?? '');
    if ($storedFingerprint !== '' && !hash_equals($storedFingerprint, $fingerprint)) {
        artdon_procurement_fail(
            'idempotency_conflict',
            'This submission token was already used for different request content. Refresh the page and submit again.',
            409
        );
    }

    return [
        'success' => true,
        'duplicate' => true,
        'message' => 'This request was already recorded.',
        'reference' => (string) $existing['request_no'],
        'request_id' => (int) $existing['id'],
        'request_type' => (string) $existing['request_type'],
        'item_count' => 0,
        'attachment_count' => 0,
    ];
}

/**
 * @return array<string,mixed>|null
 */
function artdon_procurement_active_cart(PDO $pdo, string $sessionHash): ?array
{
    $statement = $pdo->prepare(
        "SELECT *
         FROM project_carts
         WHERE session_key_hash = :session_key_hash
           AND status = 'active'
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $statement->execute([':session_key_hash' => $sessionHash]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @return list<array<string,mixed>>
 */
function artdon_procurement_cart_items(PDO $pdo, int $cartId): array
{
    $statement = $pdo->prepare(
        'SELECT *
         FROM project_cart_items
         WHERE cart_id = :cart_id
         ORDER BY sort_order ASC, id ASC'
    );
    $statement->execute([':cart_id' => $cartId]);

    return array_values($statement->fetchAll());
}

/**
 * Normalize PHP's single/multiple $_FILES shape.
 *
 * @param array<string,mixed> $files
 * @return list<array{name:string,tmp_name:string,error:int,size:int}>
 */
function artdon_procurement_normalize_uploads(array $files): array
{
    if ($files === [] || !array_key_exists('name', $files)) {
        return [];
    }

    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $temporaryNames = is_array($files['tmp_name'] ?? null)
        ? $files['tmp_name']
        : [$files['tmp_name'] ?? ''];
    $errors = is_array($files['error'] ?? null)
        ? $files['error']
        : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
    $sizes = is_array($files['size'] ?? null)
        ? $files['size']
        : [$files['size'] ?? 0];

    if (count($names) > ARTDON_PROCUREMENT_MAX_ATTACHMENTS) {
        artdon_procurement_fail(
            'too_many_attachments',
            sprintf('A maximum of %d attachments is allowed.', ARTDON_PROCUREMENT_MAX_ATTACHMENTS),
            422
        );
    }

    $normalized = [];
    foreach ($names as $index => $name) {
        $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            artdon_procurement_fail(
                'upload_failed',
                'One of the uploaded files could not be received.',
                422,
                ['upload_error' => $error]
            );
        }

        $normalized[] = [
            'name' => (string) $name,
            'tmp_name' => (string) ($temporaryNames[$index] ?? ''),
            'error' => $error,
            'size' => (int) ($sizes[$index] ?? 0),
        ];
    }

    return $normalized;
}

/**
 * @return array<string,list<string>>
 */
function artdon_procurement_upload_mime_map(): array
{
    return [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'ies' => ['text/plain', 'application/octet-stream'],
        'ldt' => ['text/plain', 'application/octet-stream'],
        'dxf' => ['text/plain', 'image/vnd.dxf', 'application/dxf', 'application/x-dxf', 'application/octet-stream'],
        'dwg' => ['image/vnd.dwg', 'application/acad', 'application/x-acad', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];
}

function artdon_procurement_validate_archive(string $path, string $extension): void
{
    if (!class_exists(ZipArchive::class)) {
        artdon_procurement_fail(
            'archive_inspection_unavailable',
            'Archive attachments cannot be inspected on this server.',
            503
        );
    }

    $archive = new ZipArchive();
    if ($archive->open($path, ZipArchive::RDONLY) !== true) {
        artdon_procurement_fail('invalid_archive', 'The archive attachment is invalid.');
    }

    try {
        if ($archive->numFiles < 1 || $archive->numFiles > ARTDON_PROCUREMENT_MAX_ARCHIVE_ENTRIES) {
            artdon_procurement_fail('invalid_archive', 'The archive contains an unsafe number of files.');
        }

        $uncompressedTotal = 0;
        $compressedTotal = 0;
        $entryNames = [];
        $blockedExtensions = [
            'php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash',
            'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'jar',
        ];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            if (!is_array($stat)) {
                artdon_procurement_fail('invalid_archive', 'The archive directory is invalid.');
            }

            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if (
                $name === ''
                || str_starts_with($name, '/')
                || preg_match('#(?:^|/)\.\.(?:/|$)#', $name) === 1
                || str_contains($name, "\0")
            ) {
                artdon_procurement_fail('unsafe_archive_path', 'The archive contains an unsafe path.');
            }

            $entryExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($entryExtension, $blockedExtensions, true)) {
                artdon_procurement_fail('unsafe_archive_content', 'Executable archive content is not permitted.');
            }

            $entryNames[$name] = true;
            $uncompressedTotal += max(0, (int) ($stat['size'] ?? 0));
            $compressedTotal += max(0, (int) ($stat['comp_size'] ?? 0));
            if ($uncompressedTotal > ARTDON_PROCUREMENT_MAX_ARCHIVE_UNCOMPRESSED_BYTES) {
                artdon_procurement_fail('archive_too_large', 'The expanded archive is too large.');
            }
        }

        if ($compressedTotal > 0 && $uncompressedTotal / $compressedTotal > 200) {
            artdon_procurement_fail('unsafe_archive_ratio', 'The archive compression ratio is unsafe.');
        }
        if ($extension === 'docx' && !isset($entryNames['[Content_Types].xml'], $entryNames['word/document.xml'])) {
            artdon_procurement_fail('invalid_office_document', 'The DOCX attachment is invalid.');
        }
        if ($extension === 'xlsx' && !isset($entryNames['[Content_Types].xml'], $entryNames['xl/workbook.xml'])) {
            artdon_procurement_fail('invalid_office_document', 'The XLSX attachment is invalid.');
        }
    } finally {
        $archive->close();
    }
}

function artdon_procurement_validate_signature(string $path, string $extension): void
{
    $head = file_get_contents($path, false, null, 0, 16_384);
    if ($head === false || $head === '') {
        artdon_procurement_fail('empty_attachment', 'Empty attachments are not permitted.');
    }

    if (
        str_starts_with($head, "MZ")
        || str_starts_with($head, "\x7FELF")
        || preg_match('/<\?(?:php|=)|<script\b|<html\b|#!\/(?:usr\/)?bin\//i', $head) === 1
    ) {
        artdon_procurement_fail('unsafe_attachment', 'Executable content is not permitted in attachments.');
    }

    $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    $valid = match ($extension) {
        'pdf' => str_starts_with($head, '%PDF-'),
        'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
        'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
        'webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
        'zip', 'docx', 'xlsx' => str_starts_with($head, "PK\x03\x04")
            || str_starts_with($head, "PK\x05\x06")
            || str_starts_with($head, "PK\x07\x08"),
        'doc', 'xls' => str_starts_with($head, $ole),
        'dwg' => str_starts_with($head, 'AC10'),
        'dxf' => !str_contains($head, "\0") && preg_match('/\bSECTION\b/i', $head) === 1,
        'ies' => !str_contains($head, "\0") && preg_match('/(?:IESNA|LM-63|TILT=)/i', $head) === 1,
        'csv', 'ldt' => !str_contains($head, "\0"),
        default => false,
    };

    if (!$valid) {
        artdon_procurement_fail(
            'attachment_signature_mismatch',
            sprintf('The .%s attachment content does not match its extension.', $extension)
        );
    }

    if (in_array($extension, ['zip', 'docx', 'xlsx'], true)) {
        artdon_procurement_validate_archive($path, $extension);
    }
}

/**
 * Use Fileinfo when available. The signature validator remains authoritative,
 * so a minimal PHP runtime can safely fall back to an explicit canonical MIME
 * for the already-verified file type.
 *
 * @param list<string> $allowedMimes
 */
function artdon_procurement_detect_mime(
    string $path,
    string $extension,
    array $allowedMimes
): string {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime === '' || !in_array($mime, $allowedMimes, true)) {
                artdon_procurement_fail(
                    'attachment_mime_mismatch',
                    sprintf('The .%s attachment MIME type is not permitted.', $extension),
                    422,
                    ['detected_mime' => $mime]
                );
            }

            return $mime;
        }
    }

    $canonical = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'csv' => 'text/csv',
        'ies' => 'text/plain',
        'ldt' => 'text/plain',
        'dxf' => 'text/plain',
        'dwg' => 'image/vnd.dwg',
        'doc' => 'application/msword',
        'xls' => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
    ][$extension] ?? '';
    if ($canonical === '' || !in_array($canonical, $allowedMimes, true)) {
        artdon_procurement_fail(
            'mime_inspection_unavailable',
            'Attachment inspection is temporarily unavailable.',
            503
        );
    }

    return $canonical;
}

/**
 * Validate all upload temporary files without moving them.
 *
 * @param array<string,mixed> $files
 * @return list<array{name:string,tmp_name:string,size:int,extension:string,mime_type:string,checksum_sha256:string}>
 */
function artdon_procurement_prepare_uploads(array $files): array
{
    $mimeMap = artdon_procurement_upload_mime_map();
    $uploads = artdon_procurement_normalize_uploads($files);
    $prepared = [];
    $total = 0;

    foreach ($uploads as $upload) {
        $temporaryPath = $upload['tmp_name'];
        if ($temporaryPath === '' || !is_file($temporaryPath) || !is_readable($temporaryPath)) {
            artdon_procurement_fail('upload_missing', 'An attachment temporary file is unavailable.');
        }

        $actualSize = filesize($temporaryPath);
        if ($actualSize === false || $actualSize < 1 || $actualSize > ARTDON_PROCUREMENT_MAX_FILE_BYTES) {
            artdon_procurement_fail(
                'attachment_size',
                sprintf('Each attachment must be between 1 byte and %d MB.', (int) (ARTDON_PROCUREMENT_MAX_FILE_BYTES / 1_048_576))
            );
        }
        $total += $actualSize;
        if ($total > ARTDON_PROCUREMENT_MAX_TOTAL_UPLOAD_BYTES) {
            artdon_procurement_fail(
                'attachments_too_large',
                sprintf('Attachments cannot exceed %d MB in total.', (int) (ARTDON_PROCUREMENT_MAX_TOTAL_UPLOAD_BYTES / 1_048_576))
            );
        }

        $originalName = basename(str_replace("\0", '', $upload['name']));
        $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?? $originalName;
        if ($originalName === '' || artdon_procurement_text_length($originalName) > 220) {
            artdon_procurement_fail('invalid_attachment_name', 'An attachment filename is invalid.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset($mimeMap[$extension])) {
            artdon_procurement_fail(
                'unsupported_attachment',
                sprintf('Unsupported attachment type: %s', $extension === '' ? '(none)' : $extension)
            );
        }

        artdon_procurement_validate_signature($temporaryPath, $extension);
        $mime = artdon_procurement_detect_mime(
            $temporaryPath,
            $extension,
            $mimeMap[$extension]
        );

        $checksum = hash_file('sha256', $temporaryPath);
        if ($checksum === false) {
            throw new RuntimeException('Unable to checksum an attachment.');
        }
        $prepared[] = [
            'name' => $originalName,
            'tmp_name' => $temporaryPath,
            'size' => (int) $actualSize,
            'extension' => $extension,
            'mime_type' => $mime,
            'checksum_sha256' => $checksum,
        ];
    }

    return $prepared;
}

function artdon_procurement_capacity_setting(
    string $environmentName,
    int $default,
    int $minimum,
    int $maximum
): int {
    $raw = trim((string) (getenv($environmentName) ?: ''));
    if ($raw === '') {
        return $default;
    }
    if (preg_match('/^[1-9][0-9]{0,15}$/', $raw) !== 1) {
        throw new RuntimeException($environmentName . ' must contain a positive byte count.');
    }
    $value = (int) $raw;
    if ($value < $minimum || $value > $maximum) {
        throw new RuntimeException($environmentName . ' is outside the permitted range.');
    }

    return $value;
}

/**
 * Preserve both an application quota and a filesystem reserve before moving
 * untrusted uploads into durable storage.
 *
 * @param list<array<string,mixed>> $prepared
 */
function artdon_procurement_assert_upload_capacity(
    array $prepared,
    PDO $pdo,
    ?int $quotaBytes = null,
    ?int $reserveBytes = null,
    ?string $storageRoot = null
): void {
    if ($prepared === []) {
        return;
    }

    $incomingBytes = array_sum(array_map(
        static fn (array $upload): int => max(0, (int) ($upload['size'] ?? 0)),
        $prepared
    ));
    $quotaBytes ??= artdon_procurement_capacity_setting(
        'ARTDON_UPLOAD_QUOTA_BYTES',
        ARTDON_PROCUREMENT_DEFAULT_UPLOAD_QUOTA_BYTES,
        67_108_864,
        1_099_511_627_776
    );
    $reserveBytes ??= artdon_procurement_capacity_setting(
        'ARTDON_UPLOAD_FREE_RESERVE_BYTES',
        ARTDON_PROCUREMENT_DEFAULT_FREE_SPACE_RESERVE_BYTES,
        67_108_864,
        1_099_511_627_776
    );
    if ($quotaBytes < 1 || $reserveBytes < 0) {
        throw new InvalidArgumentException('The upload storage policy is invalid.');
    }

    $storedBytes = (int) $pdo->query(
        "SELECT COALESCE(SUM(file_size), 0)
         FROM procurement_attachments
         WHERE status <> 'deleted'"
    )->fetchColumn();
    if ($incomingBytes > $quotaBytes || $storedBytes > $quotaBytes - $incomingBytes) {
        artdon_procurement_fail(
            'upload_capacity_reached',
            'Attachment storage is temporarily at capacity. Submit without files or contact Artdon.',
            507
        );
    }

    $storageRoot ??= dirname(__DIR__) . '/storage';
    $freeBytes = @disk_free_space($storageRoot);
    if (
        $freeBytes === false
        || $incomingBytes > $freeBytes
        || $freeBytes - $incomingBytes < $reserveBytes
    ) {
        artdon_procurement_fail(
            'upload_capacity_reached',
            'Attachment storage is temporarily at capacity. Submit without files or contact Artdon.',
            507
        );
    }
}

/**
 * @param list<array{name:string,tmp_name:string,size:int,extension:string,mime_type:string,checksum_sha256:string}> $prepared
 * @param list<string> $movedPaths
 * @return list<array<string,mixed>>
 */
function artdon_procurement_store_uploads(
    array $prepared,
    string $reference,
    array &$movedPaths,
    PDO $pdo
): array {
    if ($prepared === []) {
        return [];
    }

    artdon_procurement_assert_upload_capacity($prepared, $pdo);
    $relativeDirectory = 'storage/uploads/' . gmdate('Y/m');
    $absoluteDirectory = dirname(__DIR__) . '/' . $relativeDirectory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('Upload storage is unavailable.');
    }
    @chmod($absoluteDirectory, 0750);

    $stored = [];
    foreach ($prepared as $index => $upload) {
        $storedName = sprintf(
            '%s-%02d-%s.%s',
            preg_replace('/[^A-Z0-9-]/', '', strtoupper($reference)),
            $index + 1,
            bin2hex(random_bytes(8)),
            $upload['extension']
        );
        $absolutePath = $absoluteDirectory . '/' . $storedName;
        if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
            throw new RuntimeException('An attachment could not be stored.');
        }
        @chmod($absolutePath, 0640);
        $movedPaths[] = $absolutePath;
        $stored[] = [
            'original_name' => $upload['name'],
            'stored_path' => $relativeDirectory . '/' . $storedName,
            'mime_type' => $upload['mime_type'],
            'extension' => $upload['extension'],
            'file_size' => $upload['size'],
            'checksum_sha256' => $upload['checksum_sha256'],
        ];
    }

    return $stored;
}

/**
 * @param list<string> $paths
 */
function artdon_procurement_cleanup_files(array $paths): void
{
    foreach ($paths as $path) {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}

function artdon_procurement_verified_report(string $storedPath, string $storedChecksum): ?string
{
    $storedPath = ltrim(trim(str_replace('\\', '/', $storedPath)), '/');
    if ($storedPath === '') {
        return null;
    }
    if (!preg_match('#^storage/reports/\d{4}/\d{2}/SIM-[A-F0-9]{16}\.pdf$#', $storedPath)
        || preg_match('/^[a-f0-9]{64}$/', $storedChecksum) !== 1
    ) {
        artdon_procurement_fail(
            'simulation_report_unavailable',
            'A verified lighting simulation report is required before submission.',
            422
        );
    }

    $root = dirname(__DIR__);
    $reportsRoot = realpath($root . '/storage/reports');
    $candidate = realpath($root . '/' . $storedPath);
    if ($reportsRoot === false
        || $candidate === false
        || !is_file($candidate)
        || is_link($candidate)
        || !str_starts_with($candidate, rtrim($reportsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    ) {
        artdon_procurement_fail(
            'simulation_report_unavailable',
            'A verified lighting simulation report is required before submission.',
            422
        );
    }
    $actualChecksum = hash_file('sha256', $candidate);
    if (!is_string($actualChecksum) || !hash_equals($storedChecksum, $actualChecksum)) {
        artdon_procurement_fail(
            'simulation_report_unavailable',
            'The lighting simulation report could not be verified.',
            422
        );
    }

    return $storedPath;
}

function artdon_procurement_reference(string $type): string
{
    $prefix = [
        'order_request' => 'WO',
        'quick_rfq' => 'RFQ',
        'sample' => 'SMP',
        'ready_stock' => 'RST',
        'oem' => 'OEM',
        'odm' => 'ODM',
        'bulk' => 'BLK',
        'project_package' => 'PRJ',
        'service' => 'SRV',
        'contact' => 'MSG',
    ][$type] ?? 'REQ';

    return sprintf('%s-%s-%s', $prefix, gmdate('Ymd-His'), strtoupper(bin2hex(random_bytes(4))));
}

/**
 * Submit one request atomically.
 *
 * Public contract:
 * artdon_procurement_submit($input, $files, $sessionHash, $pdo, $context)
 *
 * @param array<string,mixed> $input Normalized or raw form fields.
 * @param array<string,mixed> $files The attachments member from $_FILES.
 * @param array<string,mixed> $context request_id, remote_addr, user_agent, referer.
 * @return array{success:bool,duplicate:bool,message:string,reference:string,request_id:int,request_type:string,item_count:int,attachment_count:int}
 */
function artdon_procurement_submit(
    array $input,
    array $files,
    string $sessionHash,
    ?PDO $pdo = null,
    array $context = []
): array {
    $pdo ??= artdon_db();
    $type = artdon_procurement_normalize_type(
        artdon_procurement_text($input, 'form_type', 60, true)
    );
    $submissionToken = artdon_procurement_submission_token($input['submission_token'] ?? null);
    $idempotencyKey = artdon_procurement_idempotency_key($sessionHash, $submissionToken);

    $company = artdon_procurement_text($input, 'company', 180, true);
    $contactName = artdon_procurement_text($input, 'name', 120, true);
    $email = strtolower(artdon_procurement_text($input, 'email', 190, true));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        artdon_procurement_fail(
            'invalid_email',
            'A valid email is required.',
            422,
            ['field' => 'email']
        );
    }
    $country = artdon_procurement_text($input, 'country', 120, true);
    $phone = artdon_procurement_text($input, 'phone', 80);
    $projectName = artdon_procurement_text($input, 'project', 255);
    $projectType = artdon_procurement_text($input, 'project_type', 120);
    $targetDate = artdon_procurement_text($input, 'target_date', 10);
    if ($targetDate !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $targetDate);
        if ($date === false || $date->format('Y-m-d') !== $targetDate) {
            artdon_procurement_fail(
                'invalid_delivery_date',
                'The requested delivery date is invalid.',
                422,
                ['field' => 'target_date']
            );
        }
    }
    $tradeTerm = strtoupper(artdon_procurement_text($input, 'trade_term', 20));
    if ($tradeTerm === 'NOT DECIDED') {
        $tradeTerm = '';
    }
    if ($tradeTerm !== '' && !in_array($tradeTerm, ['EXW', 'FOB', 'CIF', 'DDP'], true)) {
        artdon_procurement_fail(
            'invalid_trade_term',
            'The trade term is invalid.',
            422,
            ['field' => 'trade_term']
        );
    }
    $notes = artdon_procurement_text($input, 'message', 5_000);

    $requestFields = [
        'models' => artdon_procurement_text($input, 'models', 500),
        'quantity' => artdon_procurement_text($input, 'quantity', 120),
        'budget' => artdon_procurement_text($input, 'budget', 180),
        'company_website' => artdon_procurement_text($input, 'company_website', 255),
        'shipping_destination' => artdon_procurement_text($input, 'shipping_destination', 500),
        'po_number' => artdon_procurement_text($input, 'po_number', 120),
        'selection' => artdon_procurement_text($input, 'selection', 20_000),
    ];
    $preparedUploads = artdon_procurement_prepare_uploads($files);
    $submissionFingerprint = artdon_procurement_submission_fingerprint([
        'request_type' => $type,
        'company' => $company,
        'contact_name' => $contactName,
        'email' => $email,
        'phone' => $phone,
        'country' => $country,
        'project_name' => $projectName,
        'project_type' => $projectType,
        'target_date' => $targetDate,
        'trade_term' => $tradeTerm,
        'notes' => $notes,
        'request_fields' => $requestFields,
    ], $preparedUploads);

    $existing = artdon_procurement_find_idempotent($pdo, $idempotencyKey);
    if ($existing !== null) {
        return artdon_procurement_duplicate_response($existing, $submissionFingerprint);
    }

    $reference = artdon_procurement_reference($type);
    $publicId = 'PR-' . strtoupper(bin2hex(random_bytes(10)));
    $movedPaths = [];

    try {
        return artdon_db_transaction(
            static function (PDO $pdo) use (
                $type,
                $idempotencyKey,
                $company,
                $contactName,
                $email,
                $phone,
                $country,
                $projectName,
                $projectType,
                $targetDate,
                $tradeTerm,
                $notes,
                $requestFields,
                $preparedUploads,
                $submissionFingerprint,
                $reference,
                $publicId,
                $sessionHash,
                $context,
                &$movedPaths
            ): array {
                $existing = artdon_procurement_find_idempotent($pdo, $idempotencyKey);
                if ($existing !== null) {
                    return artdon_procurement_duplicate_response($existing, $submissionFingerprint);
                }

                $cart = null;
                $cartItems = [];
                if ($type === 'order_request') {
                    $cart = artdon_procurement_active_cart($pdo, $sessionHash);
                    if ($cart === null) {
                        artdon_procurement_fail(
                            'cart_required',
                            'An active Project Cart is required for an order request.'
                        );
                    }
                    $cartItems = artdon_procurement_cart_items($pdo, (int) $cart['id']);
                    if ($cartItems === []) {
                        artdon_procurement_fail(
                            'cart_empty',
                            'Add at least one product to the Project Cart before submitting.'
                        );
                    }
                }

                $effectiveProjectName = $projectName !== ''
                    ? $projectName
                    : (string) ($cart['project_name'] ?? '');
                $currency = (string) ($cart['currency'] ?? 'USD');
                $now = artdon_db_now();
                $companySnapshot = [
                    'company' => $company,
                    'contact_name' => $contactName,
                    'email' => $email,
                    'phone' => $phone,
                    'country' => $country,
                    'request_fields' => $requestFields,
                    'submission_fingerprint' => $submissionFingerprint,
                ];

                $insertRequest = $pdo->prepare(
                    'INSERT INTO procurement_requests (
                        public_id, request_no, idempotency_key, request_type, cart_id,
                        company_name, contact_name, contact_email, contact_phone, country,
                        company_snapshot_json, project_name, project_type, project_country,
                        requested_delivery_date, currency, trade_term, notes, status,
                        source, submitted_at, updated_at
                     ) VALUES (
                        :public_id, :request_no, :idempotency_key, :request_type, :cart_id,
                        :company_name, :contact_name, :contact_email, :contact_phone, :country,
                        :company_snapshot_json, :project_name, :project_type, :project_country,
                        :requested_delivery_date, :currency, :trade_term, :notes, :status,
                        :source, :submitted_at, :updated_at
                     )'
                );
                $insertRequest->execute([
                    ':public_id' => $publicId,
                    ':request_no' => $reference,
                    ':idempotency_key' => $idempotencyKey,
                    ':request_type' => $type,
                    ':cart_id' => $cart === null ? null : (int) $cart['id'],
                    ':company_name' => $company,
                    ':contact_name' => $contactName,
                    ':contact_email' => $email,
                    ':contact_phone' => $phone,
                    ':country' => $country,
                    ':company_snapshot_json' => artdon_json_encode($companySnapshot),
                    ':project_name' => $effectiveProjectName,
                    ':project_type' => $projectType,
                    ':project_country' => $country,
                    ':requested_delivery_date' => $targetDate === '' ? null : $targetDate,
                    ':currency' => $currency,
                    ':trade_term' => $tradeTerm,
                    ':notes' => $notes,
                    ':status' => 'submitted',
                    ':source' => 'shop.artdonlighting.com',
                    ':submitted_at' => $now,
                    ':updated_at' => $now,
                ]);
                $requestId = (int) $pdo->lastInsertId();

                $syncItems = [];
                if ($cartItems !== []) {
                    $insertItem = $pdo->prepare(
                        'INSERT INTO procurement_request_items (
                            request_id, product_id, line_no, product_snapshot_json,
                            configuration_snapshot_json, quantity, estimated_unit_price,
                            currency, customer_note, simulation_snapshot_json,
                            simulation_report_path, review_status, created_at, updated_at
                         ) VALUES (
                            :request_id, :product_id, :line_no, :product_snapshot_json,
                            :configuration_snapshot_json, :quantity, :estimated_unit_price,
                            :currency, :customer_note, :simulation_snapshot_json,
                            :simulation_report_path, :review_status, :created_at, :updated_at
                         )'
                    );
                    foreach ($cartItems as $offset => $item) {
                        $quantity = (float) $item['quantity'];
                        if (!is_finite($quantity) || $quantity <= 0) {
                            throw new RuntimeException('The stored cart contains an invalid quantity.');
                        }
                        $productSnapshot = json_decode(
                            (string) $item['product_snapshot_json'],
                            true,
                            64,
                            JSON_THROW_ON_ERROR
                        );
                        $configurationSnapshot = json_decode(
                            (string) $item['configuration_json'],
                            true,
                            64,
                            JSON_THROW_ON_ERROR
                        );
                        if (!is_array($productSnapshot) || !is_array($configurationSnapshot)) {
                            throw new RuntimeException('The stored cart contains an invalid snapshot.');
                        }
                        $priceMode = (string) ($item['price_mode'] ?? 'review');
                        $estimatedPrice = $priceMode === 'review' ? null : $item['unit_price'];
                        $productSnapshot['configured_model'] = (string) $item['configured_model'];
                        $productSnapshot['configuration_hash'] = (string) $item['configuration_hash'];
                        $productSnapshot['price_mode'] = $priceMode;
                        $productSnapshot['currency'] = (string) ($item['currency'] ?? $currency);
                        $productSnapshot['lead_time_text'] = (string) ($item['lead_time_text'] ?? '');
                        $simulationSnapshot = null;
                        if (is_string($item['simulation_snapshot_json']) && $item['simulation_snapshot_json'] !== '') {
                            $decodedSimulation = json_decode(
                                $item['simulation_snapshot_json'],
                                true,
                                64,
                                JSON_THROW_ON_ERROR
                            );
                            if (!is_array($decodedSimulation)) {
                                throw new RuntimeException('The stored cart contains an invalid simulation snapshot.');
                            }
                            $simulationSnapshot = $decodedSimulation;
                        }
                        $safeReportPath = null;
                        if ($simulationSnapshot !== null) {
                            $safeReportPath = artdon_procurement_verified_report(
                                (string) ($item['simulation_report_path'] ?? ''),
                                strtolower((string) ($simulationSnapshot['report_checksum_sha256'] ?? ''))
                            );
                            if ($safeReportPath === null) {
                                artdon_procurement_fail(
                                    'simulation_report_unavailable',
                                    'A verified lighting simulation report is required before submission.',
                                    422
                                );
                            }
                        } elseif (trim((string) ($item['simulation_report_path'] ?? '')) !== '') {
                            artdon_procurement_fail(
                                'simulation_report_unavailable',
                                'A simulation report cannot be submitted without its simulation snapshot.',
                                422
                            );
                        }
                        $insertItem->execute([
                            ':request_id' => $requestId,
                            ':product_id' => $item['product_id'] === null ? null : (int) $item['product_id'],
                            ':line_no' => $offset + 1,
                            ':product_snapshot_json' => artdon_json_encode($productSnapshot),
                            ':configuration_snapshot_json' => artdon_json_encode($configurationSnapshot),
                            ':quantity' => $quantity,
                            ':estimated_unit_price' => $estimatedPrice,
                            ':currency' => (string) ($item['currency'] ?? $currency),
                            ':customer_note' => (string) ($item['customer_note'] ?? ''),
                            ':simulation_snapshot_json' => $item['simulation_snapshot_json'],
                            ':simulation_report_path' => $safeReportPath,
                            ':review_status' => $priceMode === 'review' ? 'review' : 'pending',
                            ':created_at' => $now,
                            ':updated_at' => $now,
                        ]);
                        $reportPath = (string) ($safeReportPath ?? '');
                        $syncItems[] = [
                            'line_no' => $offset + 1,
                            'product_id' => $item['product_id'] === null ? null : (int) $item['product_id'],
                            'product' => $productSnapshot,
                            'configuration' => $configurationSnapshot,
                            'quantity' => $quantity,
                            'estimated_unit_price' => $estimatedPrice === null ? null : (float) $estimatedPrice,
                            'currency' => (string) ($item['currency'] ?? $currency),
                            'customer_note' => (string) ($item['customer_note'] ?? ''),
                            'simulation' => $simulationSnapshot,
                            'simulation_report' => $reportPath === '' ? null : [
                                'status' => 'available',
                                'file_name' => basename($reportPath),
                                'transfer_status' => 'requires_secure_transfer',
                            ],
                            'review_status' => $priceMode === 'review' ? 'review' : 'pending',
                        ];
                    }
                }

                $storedUploads = artdon_procurement_store_uploads(
                    $preparedUploads,
                    $reference,
                    $movedPaths,
                    $pdo
                );
                $syncAttachments = [];
                if ($storedUploads !== []) {
                    $insertAttachment = $pdo->prepare(
                        'INSERT INTO procurement_attachments (
                            request_id, original_name, stored_path, mime_type, extension,
                            file_size, checksum_sha256, status, created_at
                         ) VALUES (
                            :request_id, :original_name, :stored_path, :mime_type, :extension,
                            :file_size, :checksum_sha256, :status, :created_at
                         )'
                    );
                    foreach ($storedUploads as $upload) {
                        $insertAttachment->execute([
                            ':request_id' => $requestId,
                            ':original_name' => $upload['original_name'],
                            ':stored_path' => $upload['stored_path'],
                            ':mime_type' => $upload['mime_type'],
                            ':extension' => $upload['extension'],
                            ':file_size' => $upload['file_size'],
                            ':checksum_sha256' => $upload['checksum_sha256'],
                            // Signature/MIME/archive validation is complete, but the file
                            // remains quarantined until an external malware scanner or a
                            // staff review explicitly releases it.
                            ':status' => 'quarantined',
                            ':created_at' => $now,
                        ]);
                        $syncAttachments[] = [
                            'attachment_id' => (int) $pdo->lastInsertId(),
                            'original_name' => $upload['original_name'],
                            'mime_type' => $upload['mime_type'],
                            'extension' => $upload['extension'],
                            'file_size' => (int) $upload['file_size'],
                            'checksum_sha256' => $upload['checksum_sha256'],
                            'status' => 'quarantined',
                            'transfer_status' => 'requires_security_release',
                        ];
                    }
                }

                $syncPayload = [
                    'schema_version' => 1,
                    'source' => 'shop.artdonlighting.com',
                    'submitted_at' => $now,
                    'request' => [
                        'request_id' => $requestId,
                        'public_id' => $publicId,
                        'request_no' => $reference,
                        'request_type' => $type,
                        'status' => 'submitted',
                        'project_name' => $effectiveProjectName,
                        'project_type' => $projectType,
                        'project_country' => $country,
                        'requested_delivery_date' => $targetDate === '' ? null : $targetDate,
                        'currency' => $currency,
                        'trade_term' => $tradeTerm,
                        'notes' => $notes,
                        'request_fields' => $requestFields,
                    ],
                    'company' => [
                        'name' => $company,
                        'country' => $country,
                        'website' => $requestFields['company_website'],
                    ],
                    'contact' => [
                        'name' => $contactName,
                        'email' => $email,
                        'phone' => $phone,
                    ],
                    'items' => $syncItems,
                    'attachments' => $syncAttachments,
                ];
                $insertSyncJob = $pdo->prepare(
                    'INSERT INTO sync_jobs (
                        job_type, idempotency_key, payload_json, status, attempts,
                        max_attempts, next_attempt_at, created_at, updated_at
                     ) VALUES (
                        :job_type, :idempotency_key, :payload_json, :status, 0,
                        8, :next_attempt_at, :created_at, :updated_at
                     )'
                );
                $insertSyncJob->execute([
                    ':job_type' => 'request_push',
                    ':idempotency_key' => 'request_push:' . $publicId,
                    ':payload_json' => artdon_json_encode($syncPayload),
                    ':status' => 'pending',
                    ':next_attempt_at' => $now,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);

                $remoteAddress = (string) ($context['remote_addr'] ?? '');
                $ipHash = $remoteAddress === '' ? null : hash('sha256', 'artdon-ip-v1|' . $remoteAddress);
                $auditMetadata = [
                    'request_type' => $type,
                    'cart_id' => $cart === null ? null : (int) $cart['id'],
                    'item_count' => count($cartItems),
                    'attachment_count' => count($storedUploads),
                    'user_agent' => substr((string) ($context['user_agent'] ?? ''), 0, 500),
                    'referer' => substr((string) ($context['referer'] ?? ''), 0, 500),
                ];
                $insertAudit = $pdo->prepare(
                    'INSERT INTO audit_logs (
                        actor_type, actor_id, action, entity_type, entity_id,
                        request_id, after_json, metadata_json, ip_hash, created_at
                     ) VALUES (
                        :actor_type, :actor_id, :action, :entity_type, :entity_id,
                        :request_id, :after_json, :metadata_json, :ip_hash, :created_at
                     )'
                );
                $insertAudit->execute([
                    ':actor_type' => 'guest',
                    ':actor_id' => $sessionHash,
                    ':action' => 'procurement_request.submitted',
                    ':entity_type' => 'procurement_request',
                    ':entity_id' => $publicId,
                    ':request_id' => (string) ($context['request_id'] ?? ''),
                    ':after_json' => artdon_json_encode([
                        'request_no' => $reference,
                        'status' => 'submitted',
                    ]),
                    ':metadata_json' => artdon_json_encode($auditMetadata),
                    ':ip_hash' => $ipHash,
                    ':created_at' => $now,
                ]);

                if ($cart !== null) {
                    $markCart = $pdo->prepare(
                        "UPDATE project_carts
                         SET status = 'submitted', version = version + 1, updated_at = :updated_at
                         WHERE id = :id AND status = 'active'"
                    );
                    $markCart->execute([
                        ':updated_at' => $now,
                        ':id' => (int) $cart['id'],
                    ]);
                    if ($markCart->rowCount() !== 1) {
                        throw new RuntimeException('The Project Cart changed during submission.');
                    }
                }

                return [
                    'success' => true,
                    'duplicate' => false,
                    'message' => 'Your request has been recorded for review.',
                    'reference' => $reference,
                    'request_id' => $requestId,
                    'request_type' => $type,
                    'item_count' => count($cartItems),
                    'attachment_count' => count($storedUploads),
                ];
            },
            $pdo
        );
    } catch (Throwable $error) {
        artdon_procurement_cleanup_files($movedPaths);

        if ($error instanceof PDOException && str_contains(strtolower($error->getMessage()), 'unique')) {
            $existing = artdon_procurement_find_idempotent($pdo, $idempotencyKey);
            if ($existing !== null) {
                return artdon_procurement_duplicate_response($existing, $submissionFingerprint);
            }
        }

        throw $error;
    }
}

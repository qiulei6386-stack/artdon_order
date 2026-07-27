<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__, 2);
$tool = $root . '/tools/import_ies.php';
$fixtureOne = $root . '/tests/lighting/fixtures/synthetic-quadrant.ies';
$fixtureTwo = $root . '/tests/lighting/fixtures/synthetic-constant-1000cd.ies';
$temporary = sys_get_temp_dir() . '/artdon-ies-import-' . bin2hex(random_bytes(6));
$database = $temporary . '/artdon.sqlite';
$storage = $temporary . '/ies-storage';
if (!mkdir($temporary, 0750, true) && !is_dir($temporary)) {
    throw new RuntimeException('Unable to create the IES importer test directory.');
}
putenv('APP_DATABASE_PATH=' . $database);
require_once $root . '/includes/database.php';
require_once $root . '/includes/lighting_repository.php';
$databaseBootstrap = artdon_db_bootstrap(true);
artdon_lighting_seed_demo_profiles($databaseBootstrap['pdo']);
$databaseBootstrap = null;

$tests = 0;

function iesImportAssert(bool $condition, string $message): void
{
    global $tests;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $tests++;
    echo "PASS {$tests}: {$message}\n";
}

/**
 * @param list<string> $arguments
 * @return array{code:int,stdout:string,stderr:string}
 */
function iesImportRun(string $tool, string $database, string $storage, array $arguments): array
{
    $command = array_merge(
        [PHP_BINARY, $tool, '--database=' . $database],
        $arguments
    );
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        dirname($tool),
        ['APP_IES_STORAGE_PATH' => $storage]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the IES import command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [
        'code' => $code,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function iesImportRemoveTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($directory);
}

$completeConfiguration = [
    'beam' => '24',
    'cct' => '3000K',
    'color' => 'Black',
    'control' => 'On/Off',
    'cri' => 'CRI90',
    'driver' => 'Lifud',
    'fixture' => 'Complete',
    'light_source' => 'Bridgelux',
    'power' => '20W',
];
$correctModel = 'AT2020-20W-BK-3000K-24D-LIF-ON';
$base = [
    '--product=AT2020',
    '--model=' . $correctModel,
];

try {
    $pendingConfiguration = ['beam' => '24', 'power' => '20W'];
    $pending = iesImportRun($tool, $database, $storage, array_merge($base, [
        '--file=' . $fixtureOne,
        '--options=' . json_encode($pendingConfiguration, JSON_UNESCAPED_SLASHES),
    ]));
    iesImportAssert($pending['code'] === 0, 'a partial configuration can be stored as pending');
    iesImportAssert(str_contains($pending['stdout'], 'PENDING ONLY'), 'pending output is explicitly labelled');

    $pdo = new PDO('sqlite:' . $database, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $checksumOne = hash_file('sha256', $fixtureOne);
    $statement = $pdo->prepare('SELECT * FROM ies_library WHERE checksum_sha256 = :checksum');
    $statement->execute([':checksum' => $checksumOne]);
    $pendingRow = $statement->fetch();
    iesImportAssert(is_array($pendingRow), 'pending parse is stored in the IES library');
    iesImportAssert(
        $pendingRow['status'] === 'pending' && $pendingRow['validation_status'] === 'pending',
        'pending parse is not customer-selectable'
    );
    iesImportAssert(
        str_contains((string) $pendingRow['validation_messages_json'], 'PENDING ONLY'),
        'pending database messages state that activation checks are incomplete'
    );
    $pendingPublicId = (string) $pendingRow['public_id'];

    $pendingAgain = iesImportRun($tool, $database, $storage, array_merge($base, [
        '--file=' . $fixtureOne,
        '--options=' . json_encode($pendingConfiguration, JSON_UNESCAPED_SLASHES),
    ]));
    iesImportAssert(
        $pendingAgain['code'] === 0 && str_contains($pendingAgain['stdout'], 'idempotently'),
        'repeating the same pending checksum and mapping is idempotent'
    );

    $missing = $completeConfiguration;
    unset($missing['cri']);
    $missingResult = iesImportRun($tool, $database, $storage, array_merge($base, [
        '--file=' . $fixtureOne,
        '--options=' . json_encode($missing, JSON_UNESCAPED_SLASHES),
        '--validated',
        '--activate',
    ]));
    iesImportAssert(
        $missingResult['code'] === 2 && str_contains($missingResult['stderr'], 'Missing: cri'),
        'activation rejects a missing product option'
    );

    $unknown = array_merge($completeConfiguration, ['unknown_optic' => 'x']);
    $unknownResult = iesImportRun($tool, $database, $storage, array_merge($base, [
        '--file=' . $fixtureOne,
        '--options=' . json_encode($unknown, JSON_UNESCAPED_SLASHES),
        '--validated',
        '--activate',
    ]));
    iesImportAssert(
        $unknownResult['code'] === 2 && str_contains($unknownResult['stderr'], 'unknown product options'),
        'activation rejects an unknown product option'
    );

    $wrongModel = iesImportRun($tool, $database, $storage, [
        '--file=' . $fixtureOne,
        '--product=AT2020',
        '--model=AT2020-WRONG',
        '--options=' . json_encode($completeConfiguration, JSON_UNESCAPED_SLASHES),
        '--validated',
        '--activate',
    ]);
    iesImportAssert(
        $wrongModel['code'] === 2 && str_contains($wrongModel['stderr'], 'Expected: ' . $correctModel),
        'activation requires an exact server-generated configured model'
    );

    $activate = iesImportRun($tool, $database, $storage, array_merge($base, [
        '--file=' . $fixtureOne,
        '--options=' . json_encode($completeConfiguration, JSON_UNESCAPED_SLASHES),
        '--validated',
        '--activate',
    ]));
    iesImportAssert($activate['code'] === 0, 'a complete server-valid configuration can be activated');
    iesImportAssert(
        str_contains($activate['stdout'], 'Activated existing pending IES profile: ' . $pendingPublicId),
        'activation promotes the checksum-identical pending record instead of duplicating it'
    );

    $statement->execute([':checksum' => $checksumOne]);
    $activeOne = $statement->fetch();
    $expectedSignature = json_encode(
        $completeConfiguration,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    iesImportAssert($activeOne['status'] === 'active', 'promoted record is active');
    iesImportAssert(
        (string) $activeOne['option_signature'] === $expectedSignature,
        'active option signature is canonical JSON for the complete accepted configuration'
    );
    iesImportAssert(
        (string) $activeOne['configured_model'] === $correctModel,
        'active record stores the exact server-generated configured model'
    );
    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM ies_library WHERE checksum_sha256 = " . $pdo->quote((string) $checksumOne)
    )->fetchColumn();
    iesImportAssert($count === 1, 'pending promotion preserves checksum uniqueness');

    $activateAgain = iesImportRun($tool, $database, $storage, array_merge($base, [
        '--file=' . $fixtureOne,
        '--options=' . json_encode($completeConfiguration, JSON_UNESCAPED_SLASHES),
        '--validated',
        '--activate',
    ]));
    iesImportAssert(
        $activateAgain['code'] === 0 && str_contains($activateAgain['stdout'], 'already active idempotently'),
        'repeating an identical activation is idempotent'
    );

    $checksumTwo = hash_file('sha256', $fixtureTwo);
    $versionConflict = iesImportRun($tool, $database, $storage, array_merge($base, [
        '--file=' . $fixtureTwo,
        '--options=' . json_encode($completeConfiguration, JSON_UNESCAPED_SLASHES),
        '--version=1',
        '--validated',
        '--activate',
    ]));
    iesImportAssert(
        $versionConflict['code'] === 2 && str_contains($versionConflict['stderr'], 'Version 1 already exists'),
        'an explicit occupied version is rejected before activation'
    );
    iesImportAssert(
        !is_file($storage . '/at2020/' . $checksumTwo . '.ies'),
        'a version conflict does not leave a copied IES file'
    );

    $activateVersionTwo = iesImportRun($tool, $database, $storage, array_merge($base, [
        '--file=' . $fixtureTwo,
        '--options=' . json_encode($completeConfiguration, JSON_UNESCAPED_SLASHES),
        '--validated',
        '--activate',
    ]));
    iesImportAssert(
        $activateVersionTwo['code'] === 0 && str_contains($activateVersionTwo['stdout'], 'Version / status: 2 / active'),
        'a new checksum receives the next version automatically'
    );

    // Reopen after the child import processes so the assertion cannot retain
    // an older SQLite read snapshot through a still-cached statement cursor.
    $statement->closeCursor();
    $pdo = null;
    $pdo = new PDO('sqlite:' . $database, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $rows = $pdo->query(
        "SELECT checksum_sha256, version, status
         FROM ies_library
         WHERE product_id = (SELECT id FROM products WHERE sku = 'AT2020')
           AND option_signature = " . $pdo->quote($expectedSignature) . '
         ORDER BY version'
    )->fetchAll();
    iesImportAssert(
        count($rows) === 2
        && (int) $rows[0]['version'] === 1
        && $rows[0]['status'] === 'archived'
        && (int) $rows[1]['version'] === 2
        && $rows[1]['status'] === 'active',
        'activating a new version archives the previous active version atomically'
    );

    echo "\nAll {$tests} IES importer hardening tests passed.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "\n" . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    $pdo = null;
    iesImportRemoveTree($temporary);
}

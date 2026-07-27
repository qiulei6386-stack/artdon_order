<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

use Artdon\Lighting\IesParser;
use Artdon\Lighting\IlluminanceCalculator;
use Artdon\Lighting\LayoutOptimizer;
use Artdon\Lighting\PhotometricDistribution;
use Artdon\Lighting\SimulationService;

$root = dirname(__DIR__, 2);
require_once $root . '/includes/lighting/IesParser.php';
require_once $root . '/includes/lighting/PhotometricDistribution.php';
require_once $root . '/includes/lighting/IlluminanceCalculator.php';
require_once $root . '/includes/lighting/LayoutOptimizer.php';
require_once $root . '/includes/lighting/SimulationValidator.php';
require_once $root . '/includes/lighting/SimulationService.php';

$tests = 0;

function pass(string $message): void
{
    global $tests;
    $tests++;
    echo "PASS {$tests}: {$message}\n";
}

function sameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
        );
    }
    pass($message);
}

function near(float $expected, float $actual, float $tolerance, string $message): void
{
    if (!is_finite($actual) || abs($expected - $actual) > $tolerance) {
        throw new RuntimeException(
            sprintf('%s Expected %.12f ± %.12f, got %.12f.', $message, $expected, $tolerance, $actual)
        );
    }
    pass($message);
}

function rejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        pass($message);
        return;
    }
    throw new RuntimeException($message . ' Expected InvalidArgumentException.');
}

try {
    $parser = new IesParser();
    $constantPath = __DIR__ . '/fixtures/synthetic-constant-1000cd.ies';
    $quadrantPath = __DIR__ . '/fixtures/synthetic-quadrant.ies';

    $constantParsed = $parser->parseFile($constantPath);
    sameValue(2002, $constantParsed['source']['lm63_version'], 'LM-63-2002 version is parsed');
    sameValue('C', $constantParsed['photometry']['type'], 'Type C photometry is accepted');
    sameValue('NONE', $constantParsed['photometry']['tilt']['mode'], 'TILT=NONE is accepted');
    sameValue('axial', $constantParsed['photometry']['horizontal_symmetry'], 'Single C-plane expands axially');
    sameValue(3, count($constantParsed['photometry']['candela_cd'][0]), 'Candela count matches vertical angles');

    $constant = new PhotometricDistribution($constantParsed);
    near(1000.0, $constant->intensity(0.0, 0.0), 1.0E-9, 'Nadir intensity is exact');
    near(1000.0, $constant->intensity(45.0, 237.0), 1.0E-9, 'Axial symmetry ignores azimuth');

    $calculator = new IlluminanceCalculator();
    $fixture = [
        'x_m' => 0.0,
        'y_m' => 0.0,
        'z_m' => 4.0,
        'rotation_deg' => 0.0,
        'intensity_scale' => 1.0,
        'maintenance_factor' => 1.0,
    ];
    near(62.5, $calculator->illuminanceAtPoint($constant, 0.0, 0.0, $fixture), 0.001, 'Centre illuminance follows inverse-square law');
    near(22.097086912079607, $calculator->illuminanceAtPoint($constant, 4.0, 0.0, $fixture), 0.001, 'Off-axis horizontal illuminance includes cosine projection');
    near(15.236464574498154, $calculator->illuminanceAtPoint($constant, 3.0, 4.0, $fixture), 0.001, 'Arbitrary point illuminance matches analytic result');

    $twoFixtures = [
        array_merge($fixture, ['x_m' => -2.0]),
        array_merge($fixture, ['x_m' => 2.0]),
    ];
    $twoLightValue = 0.0;
    foreach ($twoFixtures as $twoFixture) {
        $twoLightValue += $calculator->illuminanceAtPoint($constant, 0.0, 0.0, $twoFixture);
    }
    near(89.44271909999158, $twoLightValue, 0.001, 'Multiple luminaires add linearly');

    $room = [
        'type' => 'office',
        'length_m' => 4.0,
        'width_m' => 4.0,
        'height_m' => 4.0,
        'installation_height_m' => 4.0,
        'calculation_plane_m' => 0.0,
        'mounting_type' => 'recessed',
        'target_lux' => 50.0,
    ];
    $centreFixture = array_merge($fixture, ['x_m' => 2.0, 'y_m' => 2.0]);
    $heatmap = $calculator->heatmap($constant, $room, [$centreFixture], 4, 4);
    near(50.828612739962075, $heatmap['metrics']['average_lux'], 0.001, '4×4 heatmap average is deterministic');
    near(59.68072289907042, $heatmap['metrics']['maximum_lux'], 0.001, '4×4 heatmap maximum is deterministic');
    near(43.09522968774499, $heatmap['metrics']['minimum_lux'], 0.001, '4×4 heatmap minimum is deterministic');
    near(0.8478537454527653, $heatmap['metrics']['uniformity_u0'], 0.00001, 'Uniformity is Emin divided by Eavg');

    $quadrantParsed = $parser->parseFile($quadrantPath);
    sameValue('quadrant', $quadrantParsed['photometry']['horizontal_symmetry'], '0–90 degree C-planes expand by quadrant');
    $quadrant = new PhotometricDistribution($quadrantParsed);
    near(750.0, $quadrant->intensity(45.0, 45.0), 1.0E-9, 'Horizontal C-plane interpolation is linear');
    near(750.0, $quadrant->intensity(45.0, 135.0), 1.0E-9, 'Second quadrant mirrors correctly');
    near(750.0, $quadrant->intensity(45.0, 225.0), 1.0E-9, 'Third quadrant mirrors correctly');
    near(750.0, $quadrant->intensity(45.0, 315.0), 1.0E-9, 'Fourth quadrant mirrors correctly');

    $diagonal = 4.0 / sqrt(2.0);
    near(
        16.572815184059706,
        $calculator->illuminanceAtPoint($quadrant, $diagonal, $diagonal, $fixture),
        0.001,
        'Interpolated 750cd value produces the expected 45-degree illuminance'
    );

    $constantText = file_get_contents($constantPath);
    if ($constantText === false) {
        throw new RuntimeException('The constant test fixture could not be read.');
    }
    rejects(
        fn () => $parser->parseString(str_replace('TILT=NONE', 'TILT=INCLUDE', $constantText)),
        'TILT=INCLUDE is rejected explicitly'
    );
    rejects(
        fn () => $parser->parseString(str_replace('1 1000 1 3 1 1 2', '1 1000 1 3 1 2 2', $constantText)),
        'Non-Type-C photometry is rejected explicitly'
    );
    rejects(
        fn () => $parser->parseString(str_replace('1000 1000 1000', '1000 1000', $constantText)),
        'Incomplete candela matrices are rejected'
    );

    $mockEvaluationCount = 0;
    $mockFullEvaluationCount = 0;
    $mockEvaluator = static function (
        PhotometricDistribution $distribution,
        array $mockRoom,
        array $fixtures,
        ?int $gridNx,
        ?int $gridNy
    ) use (&$mockEvaluationCount, &$mockFullEvaluationCount): array {
        $mockEvaluationCount++;
        if ($gridNx !== 8 || $gridNy !== 8) {
            $mockFullEvaluationCount++;
        }
        $value = count($fixtures) * 25.0;
        return [
            'nx' => 1,
            'ny' => 1,
            'dx_m' => (float) $mockRoom['length_m'],
            'dy_m' => (float) $mockRoom['width_m'],
            'values_lux' => [$value],
            'metrics' => [
                'average_lux' => $value,
                'maximum_lux' => $value,
                'minimum_lux' => $value,
                'uniformity_u0' => 1.0,
            ],
        ];
    };
    $optimizer = new LayoutOptimizer($calculator, $mockEvaluator);
    $autoRoom = array_merge($room, [
        'length_m' => 10.0,
        'width_m' => 8.0,
        'height_m' => 6.0,
        'installation_height_m' => 4.0,
        'target_lux' => 500.0,
    ]);
    $optimized = $optimizer->optimize($constant, $autoRoom, ['max_fixtures' => 20]);
    sameValue(20, $optimized['layout']['quantity'], 'Auto-layout finds the minimum mock quantity');
    sameValue(5, $optimized['layout']['columns'], 'Auto-layout selects five columns for room aspect');
    sameValue(4, $optimized['layout']['rows'], 'Auto-layout selects four rows for room aspect');
    near(2.0, $optimized['layout']['spacing_x_m'], 1.0E-9, 'Auto-layout X spacing is exact');
    near(2.0, $optimized['layout']['spacing_y_m'], 1.0E-9, 'Auto-layout Y spacing is exact');
    near(500.0, $optimized['metrics']['average_lux'], 1.0E-9, 'Auto-layout reaches target average');
    near(1.0, $optimized['metrics']['uniformity_u0'], 1.0E-9, 'Auto-layout preserves mock uniformity');
    sameValue(1, $mockFullEvaluationCount, 'Auto-layout renders a full heatmap only for the selected candidate');
    if ($mockEvaluationCount > 513) {
        throw new RuntimeException('Auto-layout exceeded its hard candidate evaluation budget.');
    }
    pass('Auto-layout candidate evaluations stay within the hard budget');

    $worstCaseRoom = array_merge($autoRoom, [
        'length_m' => 100.0,
        'width_m' => 100.0,
        'height_m' => 30.0,
        'installation_height_m' => 29.0,
        'target_lux' => 5000.0,
    ]);
    $worstCaseStarted = microtime(true);
    $worstCase = (new LayoutOptimizer($calculator))->optimize($quadrant, $worstCaseRoom, [
        'grid_nx' => 36,
        'grid_ny' => 36,
        'max_fixtures' => 120,
    ]);
    $worstCaseElapsed = microtime(true) - $worstCaseStarted;
    if ($worstCaseElapsed > 6.0) {
        throw new RuntimeException(sprintf(
            'Worst-case bounded layout took %.3f seconds, exceeding the 6 second test ceiling.',
            $worstCaseElapsed
        ));
    }
    pass(sprintf('Worst-case public layout is bounded (%.3f seconds)', $worstCaseElapsed));
    if ((int) $worstCase['calculation_budget']['candidate_evaluations'] > 512) {
        throw new RuntimeException('Worst-case layout exceeded 512 coarse candidate evaluations.');
    }
    sameValue(
        1,
        (int) $worstCase['calculation_budget']['full_heatmap_evaluations'],
        'Worst-case layout performs one full-resolution heatmap evaluation'
    );

    $service = new SimulationService($parser, $calculator);
    $serviceResult = $service->simulateFile($constantPath, [
        'mode' => 'single',
        'room' => $room,
        'options' => ['grid_nx' => 4, 'grid_ny' => 4],
    ]);
    sameValue(true, $serviceResult['success'], 'Simulation service returns a successful result');
    sameValue('direct-v1.0.0', $serviceResult['engine_version'], 'Simulation service records engine version');
    sameValue(64, strlen($serviceResult['calculation_hash']), 'Simulation result has a reproducibility hash');
    near(62.5, $serviceResult['single']['center_lux'], 0.001, 'Simulation service exposes single-light centre lux');
    near(50.828612739962075, $serviceResult['metrics']['average_lux'], 0.001, 'Simulation service exposes heatmap metrics');

    echo "\nAll {$tests} lighting-engine tests passed.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "\nFAIL after {$tests} passing tests: {$error->getMessage()}\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
}

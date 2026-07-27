<?php

declare(strict_types=1);

namespace Artdon\Lighting;

use InvalidArgumentException;

final class SimulationService
{
    public const ENGINE_VERSION = 'direct-v1.0.0';

    private IesParser $parser;
    private IlluminanceCalculator $calculator;
    private LayoutOptimizer $optimizer;
    private SimulationValidator $validator;

    public function __construct(
        ?IesParser $parser = null,
        ?IlluminanceCalculator $calculator = null,
        ?LayoutOptimizer $optimizer = null,
        ?SimulationValidator $validator = null
    ) {
        $this->parser = $parser ?? new IesParser();
        $this->calculator = $calculator ?? new IlluminanceCalculator();
        $this->optimizer = $optimizer ?? new LayoutOptimizer($this->calculator);
        $this->validator = $validator ?? new SimulationValidator();
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function simulateFile(string $iesPath, array $request): array
    {
        return $this->simulateParsed($this->parser->parseFile($iesPath), $request);
    }

    /**
     * @param array<string, mixed> $parsedIes
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function simulateParsed(array $parsedIes, array $request): array
    {
        $roomInput = $request['room'] ?? null;
        if (!is_array($roomInput)) {
            throw new InvalidArgumentException('Simulation room input is required.');
        }
        $room = $this->validator->room($roomInput);
        $distribution = new PhotometricDistribution($parsedIes);
        $mode = strtolower(trim((string) ($request['mode'] ?? 'single')));
        $gridNx = isset($request['options']['grid_nx']) ? (int) $request['options']['grid_nx'] : null;
        $gridNy = isset($request['options']['grid_ny']) ? (int) $request['options']['grid_ny'] : null;

        $single = null;
        $warnings = array_values(array_map(
            'strval',
            is_array($parsedIes['validation']['warnings'] ?? null) ? $parsedIes['validation']['warnings'] : []
        ));

        if (in_array($mode, ['single', 'one_light'], true)) {
            $mode = 'single';
            $fixture = [
                'x_m' => $room['length_m'] / 2.0,
                'y_m' => $room['width_m'] / 2.0,
                'z_m' => $room['calculation_plane_m'] + $room['installation_height_m'],
                'rotation_deg' => 0.0,
                'intensity_scale' => 1.0,
                'maintenance_factor' => 1.0,
            ];
            $fixtures = [$fixture];
            $heatmap = $this->calculator->heatmap($distribution, $room, $fixtures, $gridNx, $gridNy);
            $metrics = $this->withTarget($heatmap['metrics'], (float) $room['target_lux']);
            $layout = $this->layoutSummary($room, 1, 1, 0.0, $fixtures);
            $single = $this->singleResult($distribution, $room, $fixture, $metrics);
        } elseif (in_array($mode, ['layout', 'manual_layout'], true)) {
            $mode = 'layout';
            $layoutInput = $request['layout'] ?? null;
            if (!is_array($layoutInput)) {
                throw new InvalidArgumentException('Manual layout input is required.');
            }
            $validatedLayout = $this->validator->layout($layoutInput);
            $fixtures = $this->calculator->regularLayout(
                $room,
                $validatedLayout['columns'],
                $validatedLayout['rows'],
                $validatedLayout['rotation_deg']
            );
            $heatmap = $this->calculator->heatmap($distribution, $room, $fixtures, $gridNx, $gridNy);
            $metrics = $this->withTarget($heatmap['metrics'], (float) $room['target_lux']);
            $layout = $this->layoutSummary(
                $room,
                $validatedLayout['columns'],
                $validatedLayout['rows'],
                $validatedLayout['rotation_deg'],
                $fixtures
            );
        } elseif ($mode === 'auto_layout') {
            $options = is_array($request['options'] ?? null) ? $request['options'] : [];
            $optimized = $this->optimizer->optimize($distribution, $room, $options);
            $layout = $optimized['layout'];
            $metrics = $optimized['metrics'];
            $heatmap = $optimized['heatmap'];
            $warnings = array_merge($warnings, $optimized['warnings'] ?? []);
        } else {
            throw new InvalidArgumentException('The simulation mode must be single, layout, or auto_layout.');
        }

        $dimensions = $parsedIes['photometry']['dimensions_m'] ?? [];
        if (is_array($dimensions)) {
            $largestDimension = max(
                (float) ($dimensions['width'] ?? 0.0),
                (float) ($dimensions['length'] ?? 0.0),
                (float) ($dimensions['height'] ?? 0.0)
            );
            if ($largestDimension > 0 && (float) $room['installation_height_m'] < 5.0 * $largestDimension) {
                $warnings[] = 'The calculation distance is less than five times the largest luminous-opening dimension; near-field error may be significant.';
            }
        }

        $resultCore = [
            'engine_version' => self::ENGINE_VERSION,
            'mode' => $mode,
            'room' => $room,
            'photometry' => [
                'source_sha256' => (string) ($parsedIes['source']['sha256'] ?? ''),
                'lm63_version' => (int) ($parsedIes['source']['lm63_version'] ?? 0),
                'type' => 'C',
                'horizontal_symmetry' => $distribution->symmetry(),
                'input_watts' => (float) ($parsedIes['photometry']['input_watts'] ?? 0.0),
                'peak_candela' => $distribution->peakCandela(),
                'beam_angle_c0_deg' => $distribution->beamAngle(0.0),
                'beam_angle_c90_deg' => $distribution->beamAngle(90.0),
            ],
            'layout' => $layout,
            'metrics' => $metrics,
            'heatmap' => $heatmap,
            'single' => $single,
            'assumptions' => [
                'Initial direct horizontal illuminance only.',
                'No reflected light, daylight, obstructions, glare or vertical illuminance.',
                'Luminaires are modelled as far-field point sources aimed vertically downward.',
                'This preliminary estimate requires professional verification before construction.',
            ],
            'warnings' => array_values(array_unique(array_map('strval', $warnings))),
        ];
        $encoded = json_encode($resultCore, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($encoded === false) {
            throw new InvalidArgumentException('The simulation result could not be serialized.');
        }

        return array_merge([
            'success' => true,
            'calculation_hash' => hash('sha256', $encoded),
        ], $resultCore);
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function withTarget(array $metrics, float $targetLux): array
    {
        $average = (float) ($metrics['average_lux'] ?? 0.0);
        return array_merge($metrics, [
            'target_lux' => $targetLux,
            'target_met' => $average + 1.0E-9 >= $targetLux,
        ]);
    }

    /**
     * @param array<string, mixed> $room
     * @param list<array<string, int|float>> $fixtures
     * @return array<string, mixed>
     */
    private function layoutSummary(
        array $room,
        int $columns,
        int $rows,
        float $rotationDeg,
        array $fixtures
    ): array {
        $spacingX = (float) $room['length_m'] / $columns;
        $spacingY = (float) $room['width_m'] / $rows;

        return [
            'quantity' => $columns * $rows,
            'columns' => $columns,
            'rows' => $rows,
            'spacing_x_m' => $spacingX,
            'spacing_y_m' => $spacingY,
            'edge_offset_x_m' => $spacingX / 2.0,
            'edge_offset_y_m' => $spacingY / 2.0,
            'rotation_deg' => $rotationDeg,
            'fixtures' => $fixtures,
        ];
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, int|float> $fixture
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function singleResult(
        PhotometricDistribution $distribution,
        array $room,
        array $fixture,
        array $metrics
    ): array {
        $height = (float) $room['installation_height_m'];
        $centreLux = $this->calculator->illuminanceAtPoint(
            $distribution,
            (float) $fixture['x_m'],
            (float) $fixture['y_m'],
            $fixture,
            (float) $room['calculation_plane_m']
        );

        $beamC0 = $distribution->beamAngle(0.0);
        $beamC90 = $distribution->beamAngle(90.0);
        $diameterC0 = $beamC0 !== null ? 2.0 * $height * tan(deg2rad($beamC0 / 2.0)) : null;
        $diameterC90 = $beamC90 !== null ? 2.0 * $height * tan(deg2rad($beamC90 / 2.0)) : null;
        $edgeC0 = $diameterC0 !== null
            ? $this->calculator->illuminanceAtPoint(
                $distribution,
                (float) $fixture['x_m'] + $diameterC0 / 2.0,
                (float) $fixture['y_m'],
                $fixture,
                (float) $room['calculation_plane_m']
            )
            : null;
        $edgeC90 = $diameterC90 !== null
            ? $this->calculator->illuminanceAtPoint(
                $distribution,
                (float) $fixture['x_m'],
                (float) $fixture['y_m'] + $diameterC90 / 2.0,
                $fixture,
                (float) $room['calculation_plane_m']
            )
            : null;

        return [
            'center_lux' => $centreLux,
            'average_lux' => (float) $metrics['average_lux'],
            'maximum_lux' => (float) $metrics['maximum_lux'],
            'minimum_lux' => (float) $metrics['minimum_lux'],
            'spot_diameter_c0_m' => $diameterC0,
            'spot_diameter_c90_m' => $diameterC90,
            'edge_lux_c0' => $edgeC0,
            'edge_lux_c90' => $edgeC90,
        ];
    }
}

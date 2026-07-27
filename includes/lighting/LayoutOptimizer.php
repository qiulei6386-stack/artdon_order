<?php

declare(strict_types=1);

namespace Artdon\Lighting;

use Closure;
use InvalidArgumentException;

final class LayoutOptimizer
{
    private const DEFAULT_MAX_FIXTURES = 96;
    private const HARD_MAX_FIXTURES = 120;
    private const MAX_CANDIDATE_EVALUATIONS = 512;
    private const MAX_LAYOUT_SHAPES_PER_QUANTITY = 2;
    private const COARSE_GRID_AXIS = 8;
    private const TIME_BUDGET_SECONDS = 2.5;

    private IlluminanceCalculator $calculator;
    private ?Closure $candidateEvaluator;

    public function __construct(?IlluminanceCalculator $calculator = null, ?callable $candidateEvaluator = null)
    {
        $this->calculator = $calculator ?? new IlluminanceCalculator();
        $this->candidateEvaluator = $candidateEvaluator !== null
            ? Closure::fromCallable($candidateEvaluator)
            : null;
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function optimize(
        PhotometricDistribution $distribution,
        array $room,
        array $options = []
    ): array {
        $targetLux = $this->positiveNumber($room, 'target_lux');
        $length = $this->positiveNumber($room, 'length_m');
        $width = $this->positiveNumber($room, 'width_m');
        $maxFixtures = isset($options['max_fixtures'])
            ? (int) $options['max_fixtures']
            : self::DEFAULT_MAX_FIXTURES;
        if ($maxFixtures < 1 || $maxFixtures > self::HARD_MAX_FIXTURES) {
            throw new InvalidArgumentException(
                sprintf('The auto-layout fixture limit must be between 1 and %d.', self::HARD_MAX_FIXTURES)
            );
        }

        $gridNx = isset($options['grid_nx']) ? (int) $options['grid_nx'] : null;
        $gridNy = isset($options['grid_ny']) ? (int) $options['grid_ny'] : null;
        $rotations = $distribution->isAxiallySymmetric() ? [0.0] : [0.0, 90.0];
        $bestCoarseFailure = null;
        $selected = null;
        $candidateEvaluations = 0;
        $budgetExhausted = false;
        $startedAt = hrtime(true);

        for ($quantity = 1; $quantity <= $maxFixtures; $quantity++) {
            $passing = [];
            foreach ($this->candidateShapes($quantity, $length, $width) as [$columns, $rows]) {
                foreach ($rotations as $rotation) {
                    if (
                        $candidateEvaluations >= self::MAX_CANDIDATE_EVALUATIONS
                        || $this->elapsedSeconds($startedAt) >= self::TIME_BUDGET_SECONDS
                    ) {
                        $budgetExhausted = true;
                        break 3;
                    }

                    $fixtures = $this->calculator->regularLayout($room, $columns, $rows, $rotation);
                    $coarseHeatmap = $this->evaluate(
                        $distribution,
                        $room,
                        $fixtures,
                        self::COARSE_GRID_AXIS,
                        self::COARSE_GRID_AXIS
                    );
                    $candidateEvaluations++;
                    $metrics = $coarseHeatmap['metrics'] ?? null;
                    if (!is_array($metrics) || !isset($metrics['average_lux'], $metrics['minimum_lux'], $metrics['maximum_lux'], $metrics['uniformity_u0'])) {
                        throw new InvalidArgumentException('The layout evaluator returned incomplete metrics.');
                    }

                    $spacingX = $length / $columns;
                    $spacingY = $width / $rows;
                    $averageLux = (float) $metrics['average_lux'];
                    $candidate = [
                        'layout' => [
                            'quantity' => $quantity,
                            'columns' => $columns,
                            'rows' => $rows,
                            'spacing_x_m' => $spacingX,
                            'spacing_y_m' => $spacingY,
                            'edge_offset_x_m' => $spacingX / 2.0,
                            'edge_offset_y_m' => $spacingY / 2.0,
                            'rotation_deg' => $rotation,
                            'fixtures' => $fixtures,
                        ],
                        'metrics' => $metrics,
                        '_aspect_penalty' => abs(log($spacingX / $spacingY)),
                        '_overlight' => abs($averageLux - $targetLux),
                    ];

                    if ($averageLux + 1.0E-9 >= $targetLux) {
                        $passing[] = $candidate;
                    }
                    if (
                        $bestCoarseFailure === null
                        || $averageLux > (float) $bestCoarseFailure['metrics']['average_lux']
                    ) {
                        $bestCoarseFailure = $candidate;
                    }
                }
            }

            if ($passing !== []) {
                usort($passing, [$this, 'compareCandidates']);
                $selected = $passing[0];
                break;
            }
        }

        $selected ??= $bestCoarseFailure;
        if ($selected === null) {
            throw new InvalidArgumentException('No auto-layout candidate could be evaluated.');
        }

        // Candidate ranking uses an intentionally small grid. Only the chosen
        // layout receives the requested full heatmap, keeping worst-case work
        // bounded by one full-resolution evaluation.
        $finalHeatmap = $this->evaluate(
            $distribution,
            $room,
            (array) $selected['layout']['fixtures'],
            $gridNx,
            $gridNy
        );
        $finalMetrics = $finalHeatmap['metrics'] ?? null;
        if (!is_array($finalMetrics) || !isset($finalMetrics['average_lux'], $finalMetrics['minimum_lux'], $finalMetrics['maximum_lux'], $finalMetrics['uniformity_u0'])) {
            throw new InvalidArgumentException('The layout evaluator returned incomplete final metrics.');
        }
        $selected['metrics'] = array_merge($finalMetrics, [
            'target_lux' => $targetLux,
            'target_met' => (float) $finalMetrics['average_lux'] + 1.0E-9 >= $targetLux,
        ]);
        $selected['heatmap'] = $finalHeatmap;

        $result = $this->finalize($selected);
        if (!$result['metrics']['target_met']) {
            $result['warnings'][] = $budgetExhausted
                ? 'The target illuminance was not reached within the bounded calculation budget.'
                : 'The target illuminance was not reached within the fixture limit.';
        }
        $result['calculation_budget'] = [
            'candidate_evaluations' => $candidateEvaluations,
            'candidate_limit' => self::MAX_CANDIDATE_EVALUATIONS,
            'coarse_grid_points' => self::COARSE_GRID_AXIS * self::COARSE_GRID_AXIS,
            'full_heatmap_evaluations' => 1,
            'time_budget_seconds' => self::TIME_BUDGET_SECONDS,
            'budget_exhausted' => $budgetExhausted,
        ];

        return $result;
    }

    /**
     * Return only the two factorisations whose fixture spacing most closely
     * follows the room aspect ratio. This removes redundant, extremely
     * elongated candidates while retaining both useful orientations.
     *
     * @return list<array{0:int,1:int}>
     */
    private function candidateShapes(int $quantity, float $length, float $width): array
    {
        $shapes = [];
        for ($columns = 1; $columns * $columns <= $quantity; $columns++) {
            if ($quantity % $columns !== 0) {
                continue;
            }
            $rows = intdiv($quantity, $columns);
            foreach ([[$columns, $rows], [$rows, $columns]] as [$candidateColumns, $candidateRows]) {
                $key = $candidateColumns . 'x' . $candidateRows;
                $spacingX = $length / $candidateColumns;
                $spacingY = $width / $candidateRows;
                $shapes[$key] = [
                    'columns' => $candidateColumns,
                    'rows' => $candidateRows,
                    'penalty' => abs(log($spacingX / $spacingY)),
                ];
            }
        }

        uasort($shapes, static function (array $left, array $right): int {
            $penalty = (float) $left['penalty'] <=> (float) $right['penalty'];
            if ($penalty !== 0) {
                return $penalty;
            }
            $columns = (int) $right['columns'] <=> (int) $left['columns'];
            return $columns !== 0 ? $columns : (int) $left['rows'] <=> (int) $right['rows'];
        });

        $selected = array_slice(array_values($shapes), 0, self::MAX_LAYOUT_SHAPES_PER_QUANTITY);
        return array_map(
            static fn (array $shape): array => [(int) $shape['columns'], (int) $shape['rows']],
            $selected
        );
    }

    private function elapsedSeconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000_000;
    }

    /**
     * @param array<string, mixed> $room
     * @param list<array<string, int|float>> $fixtures
     * @return array<string, mixed>
     */
    private function evaluate(
        PhotometricDistribution $distribution,
        array $room,
        array $fixtures,
        ?int $gridNx,
        ?int $gridNy
    ): array {
        if ($this->candidateEvaluator !== null) {
            $result = ($this->candidateEvaluator)($distribution, $room, $fixtures, $gridNx, $gridNy);
            if (!is_array($result)) {
                throw new InvalidArgumentException('The layout evaluator must return an array.');
            }
            return $result;
        }

        return $this->calculator->heatmap($distribution, $room, $fixtures, $gridNx, $gridNy);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareCandidates(array $left, array $right): int
    {
        $uniformityComparison = (float) $right['metrics']['uniformity_u0'] <=> (float) $left['metrics']['uniformity_u0'];
        if ($uniformityComparison !== 0) {
            return $uniformityComparison;
        }

        $aspectComparison = (float) $left['_aspect_penalty'] <=> (float) $right['_aspect_penalty'];
        if ($aspectComparison !== 0) {
            return $aspectComparison;
        }

        $overlightComparison = (float) $left['_overlight'] <=> (float) $right['_overlight'];
        if ($overlightComparison !== 0) {
            return $overlightComparison;
        }

        return (float) $left['layout']['rotation_deg'] <=> (float) $right['layout']['rotation_deg'];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function finalize(array $candidate): array
    {
        unset($candidate['_aspect_penalty'], $candidate['_overlight']);
        $candidate['warnings'] = [];
        if ((float) $candidate['metrics']['uniformity_u0'] < 0.40) {
            $candidate['warnings'][] = 'The estimated uniformity is below the configurable 0.40 advisory level.';
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function positiveNumber(array $values, string $key): float
    {
        if (!isset($values[$key]) || !is_numeric($values[$key])) {
            throw new InvalidArgumentException("The {$key} value is required and must be numeric.");
        }
        $value = (float) $values[$key];
        if (!is_finite($value) || $value <= 0) {
            throw new InvalidArgumentException("The {$key} value must be a positive finite number.");
        }

        return $value;
    }
}

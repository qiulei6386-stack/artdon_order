<?php

declare(strict_types=1);

namespace Artdon\Lighting;

use InvalidArgumentException;

final class IlluminanceCalculator
{
    private const MAX_GRID_POINTS = 14_400;

    /**
     * @param array<string, int|float> $fixture
     */
    public function illuminanceAtPoint(
        PhotometricDistribution $distribution,
        float $pointX,
        float $pointY,
        array $fixture,
        float $planeZ = 0.0
    ): float {
        foreach ([$pointX, $pointY, $planeZ] as $coordinate) {
            if (!is_finite($coordinate)) {
                throw new InvalidArgumentException('Calculation coordinates must be finite.');
            }
        }

        $fixtureX = $this->number($fixture, 'x_m', 0.0);
        $fixtureY = $this->number($fixture, 'y_m', 0.0);
        $fixtureZ = $this->number($fixture, 'z_m');
        $verticalDistance = $fixtureZ - $planeZ;
        if ($verticalDistance <= 0) {
            throw new InvalidArgumentException('A luminaire must be above the calculation plane.');
        }

        $dx = $pointX - $fixtureX;
        $dy = $pointY - $fixtureY;
        $horizontalDistance = hypot($dx, $dy);
        $distanceSquared = $horizontalDistance * $horizontalDistance + $verticalDistance * $verticalDistance;
        $verticalAngle = rad2deg(atan2($horizontalDistance, $verticalDistance));
        $horizontalAngle = rad2deg(atan2($dy, $dx));
        if ($horizontalAngle < 0) {
            $horizontalAngle += 360.0;
        }

        $rotation = $this->number($fixture, 'rotation_deg', 0.0);
        $scale = $this->number($fixture, 'intensity_scale', 1.0);
        $maintenanceFactor = $this->number($fixture, 'maintenance_factor', 1.0);
        if ($scale < 0 || $maintenanceFactor < 0) {
            throw new InvalidArgumentException('Photometric scale factors cannot be negative.');
        }

        $intensity = $distribution->intensity($verticalAngle, $horizontalAngle, $rotation);
        $illuminance = $intensity * $verticalDistance / pow($distanceSquared, 1.5);

        return max(0.0, $illuminance * $scale * $maintenanceFactor);
    }

    /**
     * @param array<string, mixed> $room
     * @return list<array{x_m:float,y_m:float,z_m:float,rotation_deg:float,intensity_scale:float,maintenance_factor:float}>
     */
    public function regularLayout(array $room, int $columns, int $rows, float $rotationDeg = 0.0): array
    {
        if ($columns < 1 || $rows < 1) {
            throw new InvalidArgumentException('Layout rows and columns must be positive.');
        }

        $length = $this->roomNumber($room, 'length_m');
        $width = $this->roomNumber($room, 'width_m');
        $installationHeight = $this->roomNumber($room, 'installation_height_m');
        $planeZ = $this->roomNumber($room, 'calculation_plane_m', 0.0);
        if ($length <= 0 || $width <= 0 || $installationHeight <= 0) {
            throw new InvalidArgumentException('Room dimensions and installation height must be positive.');
        }

        $spacingX = $length / $columns;
        $spacingY = $width / $rows;
        $fixtures = [];
        for ($row = 0; $row < $rows; $row++) {
            for ($column = 0; $column < $columns; $column++) {
                $fixtures[] = [
                    'x_m' => ($column + 0.5) * $spacingX,
                    'y_m' => ($row + 0.5) * $spacingY,
                    'z_m' => $planeZ + $installationHeight,
                    'rotation_deg' => $rotationDeg,
                    'intensity_scale' => 1.0,
                    'maintenance_factor' => 1.0,
                ];
            }
        }

        return $fixtures;
    }

    /**
     * @param array<string, mixed> $room
     * @param list<array<string, int|float>> $fixtures
     * @return array<string, mixed>
     */
    public function heatmap(
        PhotometricDistribution $distribution,
        array $room,
        array $fixtures,
        ?int $gridNx = null,
        ?int $gridNy = null
    ): array {
        if ($fixtures === []) {
            throw new InvalidArgumentException('At least one luminaire is required.');
        }

        $length = $this->roomNumber($room, 'length_m');
        $width = $this->roomNumber($room, 'width_m');
        $planeZ = $this->roomNumber($room, 'calculation_plane_m', 0.0);
        if ($length <= 0 || $width <= 0) {
            throw new InvalidArgumentException('Room length and width must be positive.');
        }

        $gridNx ??= max(20, min(120, (int) ceil($length / 0.25)));
        $gridNy ??= max(20, min(120, (int) ceil($width / 0.25)));
        if ($gridNx < 1 || $gridNy < 1) {
            throw new InvalidArgumentException('Heatmap grid dimensions must be positive.');
        }

        if ($gridNx * $gridNy > self::MAX_GRID_POINTS) {
            $scale = sqrt(self::MAX_GRID_POINTS / ($gridNx * $gridNy));
            $gridNx = max(1, (int) floor($gridNx * $scale));
            $gridNy = max(1, (int) floor($gridNy * $scale));
        }

        $dx = $length / $gridNx;
        $dy = $width / $gridNy;
        $values = [];
        $sum = 0.0;
        $minimum = INF;
        $maximum = 0.0;

        for ($yIndex = 0; $yIndex < $gridNy; $yIndex++) {
            $y = ($yIndex + 0.5) * $dy;
            for ($xIndex = 0; $xIndex < $gridNx; $xIndex++) {
                $x = ($xIndex + 0.5) * $dx;
                $value = 0.0;
                foreach ($fixtures as $fixture) {
                    $value += $this->illuminanceAtPoint($distribution, $x, $y, $fixture, $planeZ);
                }
                $values[] = $value;
                $sum += $value;
                $minimum = min($minimum, $value);
                $maximum = max($maximum, $value);
            }
        }

        $pointCount = count($values);
        $average = $pointCount > 0 ? $sum / $pointCount : 0.0;
        if (!is_finite($minimum)) {
            $minimum = 0.0;
        }

        return [
            'nx' => $gridNx,
            'ny' => $gridNy,
            'dx_m' => $dx,
            'dy_m' => $dy,
            'sample_origin_m' => ['x' => $dx / 2.0, 'y' => $dy / 2.0],
            'values_lux' => $values,
            'metrics' => [
                'average_lux' => $average,
                'maximum_lux' => $maximum,
                'minimum_lux' => $minimum,
                'uniformity_u0' => $average > 0 ? $minimum / $average : 0.0,
            ],
        ];
    }

    /**
     * @param array<string, int|float> $values
     */
    private function number(array $values, string $key, ?float $default = null): float
    {
        if (!array_key_exists($key, $values)) {
            if ($default !== null) {
                return $default;
            }
            throw new InvalidArgumentException("The luminaire {$key} value is required.");
        }
        if (!is_int($values[$key]) && !is_float($values[$key])) {
            throw new InvalidArgumentException("The luminaire {$key} value must be numeric.");
        }
        $value = (float) $values[$key];
        if (!is_finite($value)) {
            throw new InvalidArgumentException("The luminaire {$key} value must be finite.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $room
     */
    private function roomNumber(array $room, string $key, ?float $default = null): float
    {
        if (!array_key_exists($key, $room)) {
            if ($default !== null) {
                return $default;
            }
            throw new InvalidArgumentException("The room {$key} value is required.");
        }
        if (!is_numeric($room[$key])) {
            throw new InvalidArgumentException("The room {$key} value must be numeric.");
        }
        $value = (float) $room[$key];
        if (!is_finite($value)) {
            throw new InvalidArgumentException("The room {$key} value must be finite.");
        }

        return $value;
    }
}

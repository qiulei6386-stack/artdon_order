<?php

declare(strict_types=1);

namespace Artdon\Lighting;

use InvalidArgumentException;

final class SimulationValidator
{
    private const ROOM_TYPES = [
        'retail',
        'office',
        'hotel',
        'restaurant',
        'gallery',
        'museum',
        'residential',
        'warehouse',
    ];

    private const MOUNTING_TYPES = [
        'recessed',
        'track',
        'surface',
        'pendant',
        'linear',
    ];

    /**
     * @param array<string, mixed> $room
     * @return array<string, mixed>
     */
    public function room(array $room): array
    {
        $roomType = strtolower(trim((string) ($room['type'] ?? $room['room_type'] ?? '')));
        if (!in_array($roomType, self::ROOM_TYPES, true)) {
            throw new InvalidArgumentException('The room type is not supported.');
        }

        $mountingType = strtolower(trim((string) ($room['mounting_type'] ?? '')));
        if (!in_array($mountingType, self::MOUNTING_TYPES, true)) {
            throw new InvalidArgumentException('The mounting type is not supported.');
        }

        $length = $this->numberInRange($room, 'length_m', 0.5, 100.0);
        $width = $this->numberInRange($room, 'width_m', 0.5, 100.0);
        $height = $this->numberInRange($room, 'height_m', 1.0, 30.0);
        $installationHeight = $this->numberInRange($room, 'installation_height_m', 0.2, 30.0);
        $targetLux = $this->numberInRange($room, 'target_lux', 1.0, 5000.0);
        $calculationPlane = array_key_exists('calculation_plane_m', $room)
            ? $this->numberInRange($room, 'calculation_plane_m', 0.0, 5.0)
            : 0.0;

        if ($installationHeight + $calculationPlane > $height + 1.0E-9) {
            throw new InvalidArgumentException('Installation height plus calculation-plane height cannot exceed room height.');
        }

        return [
            'type' => $roomType,
            'length_m' => $length,
            'width_m' => $width,
            'height_m' => $height,
            'installation_height_m' => $installationHeight,
            'calculation_plane_m' => $calculationPlane,
            'mounting_type' => $mountingType,
            'target_lux' => $targetLux,
        ];
    }

    /**
     * @param array<string, mixed> $layout
     * @return array{columns:int,rows:int,rotation_deg:float}
     */
    public function layout(array $layout): array
    {
        $columns = filter_var($layout['columns'] ?? null, FILTER_VALIDATE_INT);
        $rows = filter_var($layout['rows'] ?? null, FILTER_VALIDATE_INT);
        if ($columns === false || $rows === false || $columns < 1 || $rows < 1) {
            throw new InvalidArgumentException('Layout rows and columns must be positive integers.');
        }
        if ($columns * $rows > 400) {
            throw new InvalidArgumentException('A V1 layout cannot contain more than 400 luminaires.');
        }

        $rotation = $layout['rotation_deg'] ?? 0.0;
        if (!is_numeric($rotation) || !is_finite((float) $rotation)) {
            throw new InvalidArgumentException('Layout rotation must be a finite number.');
        }

        return [
            'columns' => (int) $columns,
            'rows' => (int) $rows,
            'rotation_deg' => (float) $rotation,
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function numberInRange(array $values, string $key, float $minimum, float $maximum): float
    {
        if (!array_key_exists($key, $values) || !is_numeric($values[$key])) {
            throw new InvalidArgumentException("The room {$key} value is required and must be numeric.");
        }
        $value = (float) $values[$key];
        if (!is_finite($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("The room {$key} value must be between {$minimum} and {$maximum}.");
        }

        return $value;
    }
}

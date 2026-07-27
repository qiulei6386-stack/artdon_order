<?php

declare(strict_types=1);

namespace Artdon\Lighting;

use InvalidArgumentException;

final class PhotometricDistribution
{
    private const EPSILON = 1.0E-9;

    /** @var list<float> */
    private array $verticalAngles;

    /** @var list<float> */
    private array $horizontalAngles;

    /** @var list<list<float>> */
    private array $candela;

    private string $symmetry;
    private float $peakCandela;

    /**
     * @param array<string, mixed> $parsed
     */
    public function __construct(private readonly array $parsed)
    {
        $photometry = $parsed['photometry'] ?? null;
        if (!is_array($photometry) || ($photometry['type'] ?? null) !== 'C') {
            throw new InvalidArgumentException('A parsed Type C photometric distribution is required.');
        }

        $verticalAngles = $photometry['vertical_angles_deg'] ?? null;
        $horizontalAngles = $photometry['horizontal_angles_deg'] ?? null;
        $candela = $photometry['candela_cd'] ?? null;
        if (!is_array($verticalAngles) || !is_array($horizontalAngles) || !is_array($candela)) {
            throw new InvalidArgumentException('The parsed photometric distribution is incomplete.');
        }

        $this->verticalAngles = array_map('floatval', array_values($verticalAngles));
        $this->horizontalAngles = array_map('floatval', array_values($horizontalAngles));
        $this->candela = [];
        foreach ($candela as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('The parsed candela matrix is invalid.');
            }
            $this->candela[] = array_map('floatval', array_values($row));
        }
        if (count($this->candela) !== count($this->horizontalAngles)) {
            throw new InvalidArgumentException('The candela matrix does not match the horizontal angles.');
        }
        foreach ($this->candela as $row) {
            if (count($row) !== count($this->verticalAngles)) {
                throw new InvalidArgumentException('The candela matrix does not match the vertical angles.');
            }
        }

        $this->symmetry = (string) ($photometry['horizontal_symmetry'] ?? '');
        if (!in_array($this->symmetry, ['axial', 'quadrant', 'bilateral_0_180', 'bilateral_90_270', 'full'], true)) {
            throw new InvalidArgumentException('The Type C horizontal symmetry is invalid.');
        }
        $this->peakCandela = (float) ($parsed['derived']['peak_candela'] ?? 0.0);
        if ($this->peakCandela <= 0) {
            foreach ($this->candela as $row) {
                $this->peakCandela = max($this->peakCandela, max($row));
            }
        }
    }

    public function intensity(float $verticalDeg, float $horizontalDeg = 0.0, float $rotationDeg = 0.0): float
    {
        if (!is_finite($verticalDeg) || !is_finite($horizontalDeg) || !is_finite($rotationDeg)) {
            throw new InvalidArgumentException('Photometric angles must be finite.');
        }

        $verticalMinimum = $this->verticalAngles[0];
        $verticalMaximum = $this->verticalAngles[count($this->verticalAngles) - 1];
        if ($verticalDeg < $verticalMinimum - self::EPSILON || $verticalDeg > $verticalMaximum + self::EPSILON) {
            return 0.0;
        }
        $verticalDeg = min($verticalMaximum, max($verticalMinimum, $verticalDeg));

        $mappedHorizontal = $this->mapHorizontalAngle($horizontalDeg - $rotationDeg);
        [$verticalLow, $verticalHigh, $verticalT] = $this->bounds($this->verticalAngles, $verticalDeg);
        [$horizontalLow, $horizontalHigh, $horizontalT] = $this->bounds($this->horizontalAngles, $mappedHorizontal);

        $lowPlane = $this->lerp(
            $this->candela[$horizontalLow][$verticalLow],
            $this->candela[$horizontalLow][$verticalHigh],
            $verticalT
        );
        if ($horizontalLow === $horizontalHigh) {
            return max(0.0, $lowPlane);
        }

        $highPlane = $this->lerp(
            $this->candela[$horizontalHigh][$verticalLow],
            $this->candela[$horizontalHigh][$verticalHigh],
            $verticalT
        );

        return max(0.0, $this->lerp($lowPlane, $highPlane, $horizontalT));
    }

    public function peakCandela(): float
    {
        return $this->peakCandela;
    }

    public function symmetry(): string
    {
        return $this->symmetry;
    }

    public function isAxiallySymmetric(): bool
    {
        return $this->symmetry === 'axial';
    }

    /**
     * Returns a full-width-at-half-maximum beam angle for a centred beam.
     * Batwing and off-axis distributions intentionally return null.
     */
    public function beamAngle(float $horizontalDeg = 0.0): ?float
    {
        if (abs($this->verticalAngles[0]) > self::EPSILON) {
            return null;
        }

        $centre = $this->intensity(0.0, $horizontalDeg);
        if ($centre <= 0) {
            return null;
        }

        $curvePeak = 0.0;
        foreach ($this->verticalAngles as $verticalAngle) {
            if ($verticalAngle > 90.0 + self::EPSILON) {
                break;
            }
            $curvePeak = max($curvePeak, $this->intensity($verticalAngle, $horizontalDeg));
        }
        if ($curvePeak > $centre * 1.05) {
            return null;
        }

        $half = $centre / 2.0;
        $previousAngle = 0.0;
        $previousIntensity = $centre;
        foreach ($this->verticalAngles as $verticalAngle) {
            if ($verticalAngle <= self::EPSILON) {
                continue;
            }
            $currentIntensity = $this->intensity($verticalAngle, $horizontalDeg);
            if ($currentIntensity <= $half) {
                if (abs($currentIntensity - $previousIntensity) <= self::EPSILON) {
                    return 2.0 * $verticalAngle;
                }
                $fraction = ($half - $previousIntensity) / ($currentIntensity - $previousIntensity);
                $halfAngle = $previousAngle + ($verticalAngle - $previousAngle) * $fraction;
                return 2.0 * $halfAngle;
            }
            $previousAngle = $verticalAngle;
            $previousIntensity = $currentIntensity;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function parsed(): array
    {
        return $this->parsed;
    }

    private function mapHorizontalAngle(float $angle): float
    {
        $angle = fmod($angle, 360.0);
        if ($angle < 0) {
            $angle += 360.0;
        }
        if (abs($angle - 360.0) <= self::EPSILON) {
            $angle = 0.0;
        }

        return match ($this->symmetry) {
            'axial' => 0.0,
            'quadrant' => $this->mapQuadrant($angle),
            'bilateral_0_180' => $angle > 180.0 ? 360.0 - $angle : $angle,
            'bilateral_90_270' => $this->mapBilateralNinety($angle),
            'full' => $angle,
            default => throw new InvalidArgumentException('The Type C horizontal symmetry is invalid.'),
        };
    }

    private function mapQuadrant(float $angle): float
    {
        $angle = fmod($angle, 180.0);
        return $angle > 90.0 ? 180.0 - $angle : $angle;
    }

    private function mapBilateralNinety(float $angle): float
    {
        if ($angle < 90.0) {
            return 180.0 - $angle;
        }
        if ($angle > 270.0) {
            return 540.0 - $angle;
        }
        return $angle;
    }

    /**
     * @param list<float> $axis
     * @return array{0:int,1:int,2:float}
     */
    private function bounds(array $axis, float $value): array
    {
        $lastIndex = count($axis) - 1;
        if ($value <= $axis[0] + self::EPSILON) {
            return [0, 0, 0.0];
        }
        if ($value >= $axis[$lastIndex] - self::EPSILON) {
            return [$lastIndex, $lastIndex, 0.0];
        }

        $low = 0;
        $high = $lastIndex;
        while ($high - $low > 1) {
            $middle = intdiv($low + $high, 2);
            if ($axis[$middle] <= $value) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }
        $span = $axis[$high] - $axis[$low];
        $fraction = $span > 0 ? ($value - $axis[$low]) / $span : 0.0;

        return [$low, $high, min(1.0, max(0.0, $fraction))];
    }

    private function lerp(float $start, float $end, float $fraction): float
    {
        return $start + ($end - $start) * $fraction;
    }
}

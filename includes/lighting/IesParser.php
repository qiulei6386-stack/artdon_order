<?php

declare(strict_types=1);

namespace Artdon\Lighting;

use InvalidArgumentException;

final class IesParser
{
    private const MAX_FILE_BYTES = 5_242_880;
    private const MAX_CANDELA_VALUES = 500_000;
    private const EPSILON = 1.0E-6;

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The IES file is not readable.');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new InvalidArgumentException('The IES file is empty.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('The IES file exceeds the 5 MB limit.');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new InvalidArgumentException('The IES file could not be read.');
        }

        return $this->parseString($content, basename($path));
    }

    /**
     * @return array<string, mixed>
     */
    public function parseString(string $content, string $sourceName = 'inline.ies'): array
    {
        if ($content === '') {
            throw new InvalidArgumentException('The IES content is empty.');
        }
        if (strlen($content) > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('The IES content exceeds the 5 MB limit.');
        }
        if (str_contains($content, "\0")) {
            throw new InvalidArgumentException('The IES content contains null bytes.');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\n|\r/', $content);
        if ($lines === false) {
            throw new InvalidArgumentException('The IES content could not be split into lines.');
        }

        $tiltLineIndex = null;
        $tiltMode = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*TILT\s*=\s*(.*?)\s*$/i', $line, $matches) === 1) {
                $tiltLineIndex = $index;
                $tiltMode = strtoupper(trim($matches[1]));
                break;
            }
        }

        if ($tiltLineIndex === null || $tiltMode === null) {
            throw new InvalidArgumentException('The IES file has no TILT record.');
        }
        if ($tiltMode !== 'NONE') {
            throw new InvalidArgumentException('V1 simulation supports TILT=NONE only.');
        }

        [$version, $versionTag] = $this->detectVersion($lines);
        [$keywords, $labels] = $this->parseLabels(array_slice($lines, 0, $tiltLineIndex));

        $numericText = implode("\n", array_slice($lines, $tiltLineIndex + 1));
        $tokens = preg_split('/[\s,]+/', trim($numericText), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || count($tokens) < 13) {
            throw new InvalidArgumentException('The IES numeric section is incomplete.');
        }
        foreach ($tokens as $token) {
            if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[Ee][+-]?\d+)?$/', $token) !== 1) {
                throw new InvalidArgumentException('The IES numeric section contains an invalid number.');
            }
        }

        $cursor = 0;
        $lampCount = $this->nextInteger($tokens, $cursor, 'lamp count');
        $lumensPerLamp = $this->nextFloat($tokens, $cursor, 'lumens per lamp');
        $candelaMultiplier = $this->nextFloat($tokens, $cursor, 'candela multiplier');
        $verticalCount = $this->nextInteger($tokens, $cursor, 'vertical angle count');
        $horizontalCount = $this->nextInteger($tokens, $cursor, 'horizontal angle count');
        $photometricTypeCode = $this->nextInteger($tokens, $cursor, 'photometric type');
        $unitsType = $this->nextInteger($tokens, $cursor, 'units type');
        $width = $this->nextFloat($tokens, $cursor, 'luminous width');
        $length = $this->nextFloat($tokens, $cursor, 'luminous length');
        $height = $this->nextFloat($tokens, $cursor, 'luminous height');
        $ballastFactor = $this->nextFloat($tokens, $cursor, 'ballast factor');
        $versionSpecificFactor = $this->nextFloat($tokens, $cursor, 'version-specific factor');
        $inputWatts = $this->nextFloat($tokens, $cursor, 'input watts');

        if ($lampCount < 1) {
            throw new InvalidArgumentException('The IES lamp count must be positive.');
        }
        if ($lumensPerLamp < 0 && !$this->nearlyEqual($lumensPerLamp, -1.0)) {
            throw new InvalidArgumentException('Lumens per lamp must be non-negative or -1 for absolute photometry.');
        }
        if ($candelaMultiplier <= 0 || $ballastFactor <= 0) {
            throw new InvalidArgumentException('IES photometric multipliers must be positive.');
        }
        if ($verticalCount < 2 || $horizontalCount < 1) {
            throw new InvalidArgumentException('The IES angle counts are invalid.');
        }
        if ($verticalCount * $horizontalCount > self::MAX_CANDELA_VALUES) {
            throw new InvalidArgumentException('The IES candela matrix exceeds the supported size.');
        }
        if ($photometricTypeCode !== 1) {
            throw new InvalidArgumentException('V1 simulation supports LM-63 Type C photometry only.');
        }
        if (!in_array($unitsType, [1, 2], true)) {
            throw new InvalidArgumentException('The IES units type must be 1 (feet) or 2 (metres).');
        }
        if ($inputWatts < 0) {
            throw new InvalidArgumentException('The IES input wattage cannot be negative.');
        }

        $verticalAngles = [];
        for ($i = 0; $i < $verticalCount; $i++) {
            $verticalAngles[] = $this->nextFloat($tokens, $cursor, 'vertical angle');
        }
        $horizontalAngles = [];
        for ($i = 0; $i < $horizontalCount; $i++) {
            $horizontalAngles[] = $this->nextFloat($tokens, $cursor, 'horizontal angle');
        }

        $this->validateAngles($verticalAngles, 0.0, 180.0, 'vertical');
        $this->validateAngles($horizontalAngles, 0.0, 360.0, 'horizontal');
        $symmetry = $this->horizontalSymmetry($horizontalAngles);

        $warnings = [];
        $legacyPhotometricFactor = 1.0;
        if ($version <= 1991) {
            if ($versionSpecificFactor <= 0) {
                throw new InvalidArgumentException('The legacy ballast-lamp photometric factor must be positive.');
            }
            $legacyPhotometricFactor = $versionSpecificFactor;
        } elseif ($version <= 2002 && !$this->nearlyEqual($versionSpecificFactor, 1.0)) {
            $warnings[] = 'The LM-63 reserved photometric field is not 1.0 and was ignored.';
        }

        $effectiveMultiplier = $candelaMultiplier * $ballastFactor * $legacyPhotometricFactor;
        $candela = [];
        $peakCandela = 0.0;
        for ($horizontalIndex = 0; $horizontalIndex < $horizontalCount; $horizontalIndex++) {
            $row = [];
            for ($verticalIndex = 0; $verticalIndex < $verticalCount; $verticalIndex++) {
                $rawValue = $this->nextFloat($tokens, $cursor, 'candela value');
                if ($rawValue < 0) {
                    throw new InvalidArgumentException('IES candela values cannot be negative.');
                }
                $value = $rawValue * $effectiveMultiplier;
                if (!is_finite($value)) {
                    throw new InvalidArgumentException('An IES candela value is outside the supported numeric range.');
                }
                $row[] = $value;
                $peakCandela = max($peakCandela, $value);
            }
            $candela[] = $row;
        }

        if ($cursor !== count($tokens)) {
            throw new InvalidArgumentException('The IES numeric section has unexpected trailing values.');
        }
        if ($peakCandela <= 0) {
            throw new InvalidArgumentException('The IES file contains no positive candela values.');
        }

        $unitScale = $unitsType === 1 ? 0.3048 : 1.0;

        return [
            'schema_version' => 1,
            'parser_version' => 'ies-parser-1.0.0',
            'source' => [
                'filename' => $sourceName,
                'sha256' => hash('sha256', $content),
                'lm63_version' => $version,
                'lm63_version_tag' => $versionTag,
                'keywords' => $keywords,
                'labels' => $labels,
            ],
            'photometry' => [
                'type' => 'C',
                'type_code' => $photometricTypeCode,
                'units' => 'm',
                'source_units' => $unitsType === 1 ? 'ft' : 'm',
                'lamp_count' => $lampCount,
                'lumens_per_lamp' => $lumensPerLamp,
                'absolute_photometry' => $this->nearlyEqual($lumensPerLamp, -1.0),
                'candela_multiplier' => $candelaMultiplier,
                'ballast_factor' => $ballastFactor,
                'photometric_factor' => $legacyPhotometricFactor,
                'file_generation_type' => $version >= 2019 ? $versionSpecificFactor : null,
                'input_watts' => $inputWatts,
                'dimensions_m' => [
                    'width' => abs($width) * $unitScale,
                    'length' => abs($length) * $unitScale,
                    'height' => abs($height) * $unitScale,
                ],
                'tilt' => ['mode' => 'NONE'],
                'vertical_angles_deg' => $verticalAngles,
                'horizontal_angles_deg' => $horizontalAngles,
                'candela_cd' => $candela,
                'horizontal_symmetry' => $symmetry,
            ],
            'derived' => [
                'peak_candela' => $peakCandela,
            ],
            'validation' => [
                'simulation_ready' => true,
                'warnings' => $warnings,
                'errors' => [],
            ],
        ];
    }

    /**
     * @param list<string> $lines
     * @return array{0:int,1:string}
     */
    private function detectVersion(array $lines): array
    {
        $first = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $first = strtoupper(trim($line));
                break;
            }
        }

        $versions = [
            'IESNA91' => 1991,
            'IESNA:LM-63-1991' => 1991,
            'IESNA:LM-63-1995' => 1995,
            'IESNA:LM-63-2002' => 2002,
            'IES:LM-63-2019' => 2019,
        ];
        if (isset($versions[$first])) {
            return [$versions[$first], $first];
        }
        if (str_starts_with($first, 'IESNA') || str_starts_with($first, 'IES:')) {
            throw new InvalidArgumentException('The LM-63 version identifier is not supported.');
        }

        return [1986, 'LM-63-1986-LEGACY'];
    }

    /**
     * @param list<string> $lines
     * @return array{0:array<string, string|list<string>>,1:list<string>}
     */
    private function parseLabels(array $lines): array
    {
        $keywords = [];
        $labels = [];
        foreach ($lines as $line) {
            $label = trim($line);
            if ($label === '' || preg_match('/^(?:IESNA91|IESNA:LM-63-\d{4}|IES:LM-63-\d{4})$/i', $label) === 1) {
                continue;
            }
            $labels[] = $label;
            if (preg_match('/^\[([A-Z0-9_-]+)]\s*(.*)$/i', $label, $matches) !== 1) {
                continue;
            }
            $key = strtoupper($matches[1]);
            $value = trim($matches[2]);
            if (!isset($keywords[$key])) {
                $keywords[$key] = $value;
            } elseif (is_array($keywords[$key])) {
                $keywords[$key][] = $value;
            } else {
                $keywords[$key] = [$keywords[$key], $value];
            }
        }

        return [$keywords, $labels];
    }

    /**
     * @param list<string> $tokens
     */
    private function nextFloat(array $tokens, int &$cursor, string $field): float
    {
        if (!array_key_exists($cursor, $tokens)) {
            throw new InvalidArgumentException("The IES {$field} field is missing.");
        }
        $value = (float) $tokens[$cursor++];
        if (!is_finite($value)) {
            throw new InvalidArgumentException("The IES {$field} field is outside the supported numeric range.");
        }

        return $value;
    }

    /**
     * @param list<string> $tokens
     */
    private function nextInteger(array $tokens, int &$cursor, string $field): int
    {
        $value = $this->nextFloat($tokens, $cursor, $field);
        if (abs($value - round($value)) > self::EPSILON) {
            throw new InvalidArgumentException("The IES {$field} field must be an integer.");
        }

        return (int) round($value);
    }

    /**
     * @param list<float> $angles
     */
    private function validateAngles(array $angles, float $minimum, float $maximum, string $label): void
    {
        $previous = null;
        foreach ($angles as $angle) {
            if ($angle < $minimum - self::EPSILON || $angle > $maximum + self::EPSILON) {
                throw new InvalidArgumentException("An IES {$label} angle is outside the supported range.");
            }
            if ($previous !== null && $angle <= $previous + self::EPSILON) {
                throw new InvalidArgumentException("IES {$label} angles must be strictly increasing.");
            }
            $previous = $angle;
        }
    }

    /**
     * @param list<float> $angles
     */
    private function horizontalSymmetry(array $angles): string
    {
        if (count($angles) === 1) {
            if (!$this->nearlyEqual($angles[0], 0.0)) {
                throw new InvalidArgumentException('A rotationally symmetric Type C file must use the 0-degree C-plane.');
            }
            return 'axial';
        }

        $first = $angles[0];
        $last = $angles[count($angles) - 1];
        if ($this->nearlyEqual($first, 0.0) && $this->nearlyEqual($last, 90.0)) {
            return 'quadrant';
        }
        if ($this->nearlyEqual($first, 0.0) && $this->nearlyEqual($last, 180.0)) {
            return 'bilateral_0_180';
        }
        if ($this->nearlyEqual($first, 90.0) && $this->nearlyEqual($last, 270.0)) {
            return 'bilateral_90_270';
        }
        if ($this->nearlyEqual($first, 0.0) && $this->nearlyEqual($last, 360.0)) {
            return 'full';
        }

        throw new InvalidArgumentException('The Type C horizontal angle coverage is not supported.');
    }

    private function nearlyEqual(float $left, float $right): bool
    {
        return abs($left - $right) <= self::EPSILON;
    }
}

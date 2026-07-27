<?php

declare(strict_types=1);

/**
 * Minimal, dependency-free PDF renderer used by the live PHP endpoint.
 *
 * It intentionally supports a narrow report layout rather than arbitrary HTML.
 * All dynamic values are escaped before being written to a WinAnsi text stream.
 */
final class ArtdonSimplePdf
{
    /** @var list<string> */
    private array $commands = [];

    public function fillRect(float $x, float $y, float $width, float $height, array $rgb): void
    {
        $this->commands[] = sprintf(
            '%s %s %s rg %s %s %s %s re f',
            $this->number((float) ($rgb[0] ?? 0)),
            $this->number((float) ($rgb[1] ?? 0)),
            $this->number((float) ($rgb[2] ?? 0)),
            $this->number($x),
            $this->number($y),
            $this->number($width),
            $this->number($height)
        );
    }

    public function strokeRect(
        float $x,
        float $y,
        float $width,
        float $height,
        array $rgb = [0.82, 0.85, 0.89],
        float $lineWidth = 0.6
    ): void {
        $this->commands[] = sprintf(
            '%s w %s %s %s RG %s %s %s %s re S',
            $this->number($lineWidth),
            $this->number((float) ($rgb[0] ?? 0)),
            $this->number((float) ($rgb[1] ?? 0)),
            $this->number((float) ($rgb[2] ?? 0)),
            $this->number($x),
            $this->number($y),
            $this->number($width),
            $this->number($height)
        );
    }

    public function line(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        array $rgb = [0.82, 0.85, 0.89],
        float $lineWidth = 0.6
    ): void {
        $this->commands[] = sprintf(
            '%s w %s %s %s RG %s %s m %s %s l S',
            $this->number($lineWidth),
            $this->number((float) ($rgb[0] ?? 0)),
            $this->number((float) ($rgb[1] ?? 0)),
            $this->number((float) ($rgb[2] ?? 0)),
            $this->number($x1),
            $this->number($y1),
            $this->number($x2),
            $this->number($y2)
        );
    }

    public function text(
        float $x,
        float $y,
        string $text,
        float $size = 10,
        bool $bold = false,
        array $rgb = [0.12, 0.16, 0.22]
    ): void {
        $font = $bold ? 'F2' : 'F1';
        $this->commands[] = sprintf(
            'BT /%s %s Tf %s %s %s rg 1 0 0 1 %s %s Tm (%s) Tj ET',
            $font,
            $this->number($size),
            $this->number((float) ($rgb[0] ?? 0)),
            $this->number((float) ($rgb[1] ?? 0)),
            $this->number((float) ($rgb[2] ?? 0)),
            $this->number($x),
            $this->number($y),
            $this->escapeText($text)
        );
    }

    /**
     * @return float Y coordinate after the final line.
     */
    public function wrappedText(
        float $x,
        float $y,
        string $text,
        float $maxWidth,
        float $size = 9,
        float $leading = 12,
        bool $bold = false,
        array $rgb = [0.24, 0.28, 0.34],
        int $maxLines = 6
    ): float {
        $lines = $this->wrap($text, $maxWidth, $size);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = rtrim(substr($lines[$maxLines - 1], 0, -3)) . '...';
        }
        foreach ($lines as $line) {
            $this->text($x, $y, $line, $size, $bold, $rgb);
            $y -= $leading;
        }

        return $y;
    }

    public function render(): string
    {
        $stream = implode("\n", $this->commands) . "\n";
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] '
                . '/Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
            4 => "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach (array_keys($objects) as $number) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, float $maxWidth, float $fontSize): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->winAnsi($text)) ?? '');
        if ($text === '') {
            return [''];
        }
        $maxCharacters = max(8, (int) floor($maxWidth / max(1.0, $fontSize * 0.52)));
        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            while (strlen($word) > $maxCharacters) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = substr($word, 0, $maxCharacters);
                $word = substr($word, $maxCharacters);
            }
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (strlen($candidate) > $maxCharacters && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines ?: [''];
    }

    private function escapeText(string $text): string
    {
        $text = $this->winAnsi($text);
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';
    }

    private function winAnsi(string $text): string
    {
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}

/**
 * Build a polished one-page Lighting Simulation report.
 *
 * @param array<string,mixed> $project Hydrated repository project.
 */
function artdon_simple_pdf_report(array $project): string
{
    $pdf = new ArtdonSimplePdf();
    $navy = [0.035, 0.10, 0.19];
    $blue = [0.055, 0.38, 0.72];
    $muted = [0.36, 0.41, 0.48];
    $light = [0.965, 0.972, 0.982];
    $border = [0.82, 0.85, 0.89];
    $orange = [0.98, 0.71, 0.17];
    $result = (array) ($project['result'] ?? []);
    $room = (array) ($result['room'] ?? []);
    $layout = (array) ($result['layout'] ?? []);
    $metrics = (array) ($result['metrics'] ?? []);
    $heatmap = (array) ($result['heatmap'] ?? []);
    $manufacturerValidated = function_exists('artdon_lighting_manufacturer_validated')
        && artdon_lighting_manufacturer_validated($project);
    $syntheticDemo = str_starts_with(
        (string) ($project['ies_public_id'] ?? ''),
        'IES-DEMO-'
    );

    $pdf->fillRect(0, 0, 595.28, 841.89, [1, 1, 1]);
    $pdf->fillRect(0, 748, 595.28, 93.89, $navy);
    $pdf->text(40, 805, 'ARTDON', 10, true, [0.45, 0.72, 1]);
    $pdf->text(40, 777, 'Lighting Simulation Report', 25, true, [1, 1, 1]);
    $pdf->text(438, 808, 'PRELIMINARY', 9, true, $orange);
    $pdf->text(438, 790, (string) ($project['public_id'] ?? ''), 8, false, [0.84, 0.88, 0.94]);
    $pdf->text(438, 775, gmdate('Y-m-d') . ' UTC', 8, false, [0.84, 0.88, 0.94]);

    $pdf->fillRect(40, 690, 515, 42, [1.0, 0.965, 0.87]);
    $pdf->strokeRect(40, 690, 515, 42, [0.93, 0.68, 0.13]);
    $pdf->text(
        52,
        716,
        $manufacturerValidated
            ? 'DATA STATUS: VERIFIED MANUFACTURER / LAB PROFILE'
            : ($syntheticDemo
                ? 'DATA STATUS: SYNTHETIC PRELIMINARY DEMO'
                : 'DATA STATUS: UNVERIFIED LIBRARY PROFILE'),
        9,
        true,
        [0.56, 0.32, 0.02]
    );
    $pdf->text(
        52,
        700,
        $manufacturerValidated
            ? 'Independent provenance is recorded. The preliminary calculation scope and disclaimer still apply.'
            : ($syntheticDemo
                ? 'Not manufacturer-supplied or laboratory-validated photometry. Do not use for product claims.'
                : 'Manufacturer/laboratory provenance is not verified. Do not use for final construction design.'),
        8,
        false,
        [0.48, 0.32, 0.08]
    );

    artdon_pdf_section_box($pdf, 40, 601, 250, 72, 'PROJECT', [
        ['Project name', (string) (($project['project_name'] ?? '') ?: 'Untitled simulation')],
        ['Project ID', (string) ($project['public_id'] ?? '')],
        ['Room type', ucfirst((string) ($room['type'] ?? ''))],
    ], $light, $border, $blue, $muted);
    artdon_pdf_section_box($pdf, 305, 601, 250, 72, 'PRODUCT & PHOTOMETRY', [
        ['Product', trim((string) ($project['sku'] ?? '') . ' - ' . (string) ($project['product_name'] ?? ''))],
        ['Configured model', (string) ($project['configured_model'] ?? '')],
        ['IES profile', (string) ($project['ies_original_name'] ?? '')],
    ], $light, $border, $blue, $muted);

    artdon_pdf_section_box($pdf, 40, 510, 250, 75, 'ROOM & TARGET', [
        [
            'Dimensions',
            sprintf(
                '%.2f x %.2f x %.2f m',
                (float) ($room['length_m'] ?? 0),
                (float) ($room['width_m'] ?? 0),
                (float) ($room['height_m'] ?? 0)
            ),
        ],
        ['Installation height', sprintf('%.2f m', (float) ($room['installation_height_m'] ?? 0))],
        ['Target / MF', sprintf('%.0f lux / %.2f', (float) ($room['target_lux'] ?? 0), (float) ($result['maintenance_factor'] ?? 0.8))],
    ], $light, $border, $blue, $muted);
    artdon_pdf_section_box($pdf, 305, 510, 250, 75, 'LAYOUT', [
        ['Quantity', (string) ((int) ($layout['quantity'] ?? 0)) . ' pcs'],
        ['Grid', (string) ((int) ($layout['columns'] ?? 0)) . ' x ' . (string) ((int) ($layout['rows'] ?? 0))],
        [
            'Spacing',
            sprintf(
                '%.2f m x %.2f m',
                (float) ($layout['spacing_x_m'] ?? 0),
                (float) ($layout['spacing_y_m'] ?? 0)
            ),
        ],
    ], $light, $border, $blue, $muted);

    $cardWidth = 120.25;
    $cardX = [40.0, 171.75, 303.5, 435.25];
    $metricCards = [
        ['AVERAGE', sprintf('%.0f lux', (float) ($metrics['average_lux'] ?? 0))],
        ['MAXIMUM', sprintf('%.0f lux', (float) ($metrics['maximum_lux'] ?? 0))],
        ['MINIMUM', sprintf('%.0f lux', (float) ($metrics['minimum_lux'] ?? 0))],
        ['UNIFORMITY U0', sprintf('%.2f', (float) ($metrics['uniformity_u0'] ?? 0))],
    ];
    foreach ($metricCards as $index => $metricCard) {
        $pdf->fillRect($cardX[$index], 443, $cardWidth, 52, $index === 0 ? [0.92, 0.96, 1] : $light);
        $pdf->strokeRect($cardX[$index], 443, $cardWidth, 52, $border);
        $pdf->text($cardX[$index] + 10, 478, $metricCard[0], 7.5, true, $muted);
        $pdf->text($cardX[$index] + 10, 456, $metricCard[1], 15, true, $index === 0 ? $blue : $navy);
    }

    $pdf->text(40, 415, 'FALSE-COLOR ILLUMINANCE MAP', 8, true, $blue);
    $mapX = 40.0;
    $mapY = 150.0;
    $mapWidth = 315.0;
    $mapHeight = 245.0;
    $pdf->fillRect($mapX, $mapY, $mapWidth, $mapHeight, [0.96, 0.97, 0.98]);
    artdon_pdf_draw_heatmap(
        $pdf,
        $heatmap,
        $mapX + 8,
        $mapY + 8,
        $mapWidth - 16,
        $mapHeight - 16,
        max(1.0, (float) ($metrics['target_lux'] ?? 1))
    );
    $pdf->strokeRect($mapX, $mapY, $mapWidth, $mapHeight, $border);

    $rightX = 376.0;
    $pdf->text($rightX, 415, 'RESULT', 8, true, $blue);
    $targetMet = !empty($metrics['target_met']);
    $pdf->fillRect($rightX, 357, 179, 38, $targetMet ? [0.91, 0.97, 0.93] : [1.0, 0.94, 0.91]);
    $pdf->strokeRect($rightX, 357, 179, 38, $targetMet ? [0.34, 0.66, 0.43] : [0.84, 0.40, 0.28]);
    $pdf->text(
        $rightX + 12,
        379,
        $targetMet ? 'Target average reached' : 'Target average not reached',
        10,
        true,
        $targetMet ? [0.10, 0.42, 0.20] : [0.62, 0.18, 0.10]
    );
    $pdf->text(
        $rightX + 12,
        365,
        sprintf('Estimated average: %.0f lux', (float) ($metrics['average_lux'] ?? 0)),
        8,
        false,
        $muted
    );

    $pdf->text($rightX, 330, 'METHOD & LIMITATIONS', 8, true, $blue);
    $method = 'Direct horizontal illuminance from LM-63 Type C intensity data. '
        . 'The V1 model excludes reflected light, daylight, obstructions, glare, vertical illuminance, and near-field effects.';
    $nextY = $pdf->wrappedText($rightX, 312, $method, 179, 8, 11, false, $muted, 8);
    $pdf->text($rightX, $nextY - 7, 'RECOMMENDATION', 8, true, $blue);
    $recommendation = $targetMet
        ? 'Use this layout as an early quantity and spacing study, then verify the selected optical variant in professional lighting software.'
        : 'Review the fixture quantity, mounting height, beam, or target. Re-run the study and verify in professional lighting software.';
    $pdf->wrappedText($rightX, $nextY - 25, $recommendation, 179, 8, 11, false, $muted, 8);

    $pdf->fillRect(40, 75, 515, 55, [0.955, 0.96, 0.97]);
    $pdf->strokeRect(40, 75, 515, 55, $border);
    $pdf->text(52, 112, 'IMPORTANT DISCLAIMER', 8, true, [0.36, 0.40, 0.46]);
    $pdf->wrappedText(
        52,
        97,
        function_exists('artdon_lighting_disclaimer')
            ? artdon_lighting_disclaimer()
            : 'Preliminary direct-illuminance estimate. Final construction design must be verified by a qualified lighting designer or professional lighting software.',
        490,
        8,
        10,
        false,
        [0.38, 0.42, 0.48],
        3
    );
    $pdf->line(40, 54, 555, 54, $border);
    $pdf->text(40, 38, 'Artdon Procurement Platform', 8, true, $navy);
    $pdf->text(440, 38, 'Page 1 of 1', 8, false, $muted);

    return $pdf->render();
}

/**
 * @param list<array{0:string,1:string}> $rows
 */
function artdon_pdf_section_box(
    ArtdonSimplePdf $pdf,
    float $x,
    float $y,
    float $width,
    float $height,
    string $title,
    array $rows,
    array $background,
    array $border,
    array $accent,
    array $muted
): void {
    $pdf->fillRect($x, $y, $width, $height, $background);
    $pdf->strokeRect($x, $y, $width, $height, $border);
    $pdf->text($x + 11, $y + $height - 15, $title, 7.5, true, $accent);
    $rowY = $y + $height - 31;
    foreach ($rows as $row) {
        $pdf->text($x + 11, $rowY, $row[0] . ':', 7.5, true, $muted);
        $value = strlen($row[1]) > 34 ? substr($row[1], 0, 31) . '...' : $row[1];
        $pdf->text($x + 87, $rowY, $value, 8, false, [0.13, 0.17, 0.22]);
        $rowY -= 15;
    }
}

/**
 * @param array<string,mixed> $heatmap
 */
function artdon_pdf_draw_heatmap(
    ArtdonSimplePdf $pdf,
    array $heatmap,
    float $x,
    float $y,
    float $width,
    float $height,
    float $targetLux
): void {
    $sourceNx = max(1, (int) ($heatmap['nx'] ?? 1));
    $sourceNy = max(1, (int) ($heatmap['ny'] ?? 1));
    $values = is_array($heatmap['values_lux'] ?? null) ? array_values($heatmap['values_lux']) : [0];
    $outputNx = min(44, $sourceNx);
    $outputNy = min(34, $sourceNy);
    $cellWidth = $width / $outputNx;
    $cellHeight = $height / $outputNy;

    for ($outputY = 0; $outputY < $outputNy; $outputY++) {
        $sourceYStart = (int) floor($outputY * $sourceNy / $outputNy);
        $sourceYEnd = max($sourceYStart + 1, (int) ceil(($outputY + 1) * $sourceNy / $outputNy));
        for ($outputX = 0; $outputX < $outputNx; $outputX++) {
            $sourceXStart = (int) floor($outputX * $sourceNx / $outputNx);
            $sourceXEnd = max($sourceXStart + 1, (int) ceil(($outputX + 1) * $sourceNx / $outputNx));
            $sum = 0.0;
            $count = 0;
            for ($sourceY = $sourceYStart; $sourceY < min($sourceNy, $sourceYEnd); $sourceY++) {
                for ($sourceX = $sourceXStart; $sourceX < min($sourceNx, $sourceXEnd); $sourceX++) {
                    $index = $sourceY * $sourceNx + $sourceX;
                    if (isset($values[$index]) && is_numeric($values[$index])) {
                        $sum += (float) $values[$index];
                        $count++;
                    }
                }
            }
            $lux = $count > 0 ? $sum / $count : 0.0;
            $pdf->fillRect(
                $x + $outputX * $cellWidth,
                $y + $outputY * $cellHeight,
                $cellWidth + 0.15,
                $cellHeight + 0.15,
                artdon_pdf_heat_color($lux / $targetLux)
            );
        }
    }
}

/**
 * @return array{0:float,1:float,2:float}
 */
function artdon_pdf_heat_color(float $ratio): array
{
    $stops = [
        [0.0, [0.04, 0.10, 0.32]],
        [0.35, [0.00, 0.50, 0.85]],
        [0.70, [0.10, 0.76, 0.48]],
        [1.00, [1.00, 0.84, 0.10]],
        [1.50, [0.86, 0.12, 0.09]],
    ];
    $ratio = max(0.0, min(1.5, $ratio));
    for ($index = 1; $index < count($stops); $index++) {
        if ($ratio <= $stops[$index][0]) {
            $left = $stops[$index - 1];
            $right = $stops[$index];
            $span = $right[0] - $left[0];
            $fraction = $span > 0 ? ($ratio - $left[0]) / $span : 0.0;
            return [
                $left[1][0] + ($right[1][0] - $left[1][0]) * $fraction,
                $left[1][1] + ($right[1][1] - $left[1][1]) * $fraction,
                $left[1][2] + ($right[1][2] - $left[1][2]) * $fraction,
            ];
        }
    }

    return $stops[count($stops) - 1][1];
}

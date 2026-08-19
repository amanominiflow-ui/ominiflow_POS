<?php
/**
 * Pure PHP Code128 Barcode Vector SVG Generator (Zero External Dependencies)
 */

declare(strict_types=1);

function generate_code128_svg(string $text, int $barHeight = 45, float $barWidth = 1.6): string {
    $text = trim($text);
    if ($text === '') {
        $text = '100001';
    }

    // Code128 Pattern Table (Patterns 0 to 106)
    $patterns = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '112322', '122231', '113222', '123122', '123221', '223211',
        '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112',
        '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311',
        '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121',
        '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311',
        '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221',
        '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112',
        '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211',
        '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311',
        '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112'
    ];

    $startB = 104;
    $stop = 106;

    $values = [$startB];
    $checksum = $startB;

    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ascii = ord($text[$i]);
        $val = ($ascii >= 32 && $ascii <= 126) ? ($ascii - 32) : 0;
        $values[] = $val;
        $checksum += ($val * ($i + 1));
    }

    $checkVal = $checksum % 103;
    $values[] = $checkVal;
    $values[] = $stop;

    // Convert values to widths string
    $patternStr = '';
    foreach ($values as $v) {
        $patternStr .= $patterns[$v] ?? '211214';
    }
    // Termination bar
    $patternStr .= '2';

    // Calculate total width
    $totalModules = 0;
    for ($k = 0; $k < strlen($patternStr); $k++) {
        $totalModules += (int)$patternStr[$k];
    }

    $quietZone = 10;
    $svgWidth = ($totalModules + ($quietZone * 2)) * $barWidth;
    $svgHeight = $barHeight + 16; // extra space for text

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $svgWidth . ' ' . $svgHeight . '" width="' . $svgWidth . '" height="' . $svgHeight . '" style="max-width: 100%; height: auto; display: block; margin: 0 auto;">';
    $svg .= '<rect width="100%" height="100%" fill="transparent"/>';

    $currentX = $quietZone * $barWidth;
    $isBar = true;

    for ($m = 0; $m < strlen($patternStr); $m++) {
        $w = (int)$patternStr[$m] * $barWidth;
        if ($isBar) {
            $svg .= '<rect x="' . number_format($currentX, 2, '.', '') . '" y="0" width="' . number_format($w, 2, '.', '') . '" height="' . $barHeight . '" fill="#0f172a"/>';
        }
        $currentX += $w;
        $isBar = !$isBar;
    }

    // Human readable text
    $svg .= '<text x="' . number_format($svgWidth / 2, 2, '.', '') . '" y="' . ($barHeight + 12) . '" font-family="monospace" font-size="11" font-weight="bold" fill="#0f172a" text-anchor="middle">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</text>';
    $svg .= '</svg>';

    return $svg;
}

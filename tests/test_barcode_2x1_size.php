<?php
declare(strict_types=1);

/**
 * Verification test for 2x1 inch barcode label printing preset.
 * Run: php tests/test_barcode_2x1_size.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/barcode_helper.php';

$failures = 0;

function assert_true(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo " [PASS] {$message}\n";
    } else {
        echo " [FAIL] {$message}\n";
        $failures++;
    }
}

echo "========================================================\n";
echo "   TESTING 2x1 INCH BARCODE LABEL OPTION & LAYOUT      \n";
echo "========================================================\n\n";

// 1. Verify Code128 generation with 2x1 parameters (24 height, 1.15 width)
$svg2x1 = generate_code128_svg('TESTSKU123', 24, 1.15);
assert_true(str_contains($svg2x1, '<svg'), "generate_code128_svg produces valid SVG for 2x1 parameters");
assert_true(str_contains($svg2x1, 'height="40"'), "SVG total height is 40px (24 bar height + 16 text padding) for compact 1-inch fit");
assert_true(str_contains($svg2x1, 'TESTSKU123'), "SVG embeds barcode text");

// 2. Check file content assertions in barcode-print.php
$content = file_get_contents(__DIR__ . '/../barcode-print.php');

assert_true(str_contains($content, '<option value="2x1"'), "Option value '2x1' exists in template size dropdown");
assert_true(str_contains($content, '<option value="4x1"'), "Option value '4x1' exists in template size dropdown");
assert_true(str_contains($content, 'size: 4in 1in;'), "Print CSS defines exact thermal page size 4in 1in");
assert_true(str_contains($content, 'margin: 0;'), "Print CSS removes page margins for thermal roll edge-to-edge");
assert_true(str_contains($content, '.barcode-pair-row'), "CSS contains .barcode-pair-row rule");
assert_true(str_contains($content, 'width: 4in !important;'), "Pair row is sized to 4in width");
assert_true(str_contains($content, 'height: 1in !important;'), "Pair row is sized to 1in height");
assert_true(str_contains($content, 'page-break-after: always !important;'), "Pair row enforces page-break-after for 1-inch feed rows");

// 3. Test rendering simulated GET request for 2x1 preset
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Admin';
$_SESSION['user_role'] = 'admin';

$_GET['label_size'] = '2x1';
$_GET['copies'] = '6';

ob_start();
include __DIR__ . '/../barcode-print.php';
$html = ob_get_clean();

assert_true(str_contains($html, '<div class="size-2x1">'), "Output HTML contains wrapper class 'size-2x1'");
assert_true(str_contains($html, 'value="2x1" selected'), "Dropdown option 2x1 is selected");
assert_true(str_contains($html, 'class="pair-roll-container"'), "Renders pair-roll-container for 2x1");
assert_true(str_contains($html, 'class="barcode-pair-row"'), "Renders barcode-pair-row elements for 2-per-row layout");
assert_true(substr_count($html, 'class="barcode-pair-row"') === 3, "6 copies render exactly 3 pair rows (2 labels per 4x1 feed)");

// 4. Test rendering simulated GET request for 4x1 preset
$_GET['label_size'] = '4x1';
$_GET['copies'] = '4';

ob_start();
include __DIR__ . '/../barcode-print.php';
$html4x1 = ob_get_clean();

assert_true(str_contains($html4x1, '<div class="size-4x1">'), "Output HTML contains wrapper class 'size-4x1'");
assert_true(str_contains($html4x1, 'value="4x1" selected'), "Dropdown option 4x1 is selected");
assert_true(str_contains($html4x1, 'class="pair-roll-container"'), "Renders pair-roll-container for 4x1");
assert_true(substr_count($html4x1, 'class="barcode-pair-row"') === 2, "4 copies render exactly 2 pair rows");

// 5. Test backward compatibility for standard preset
$_GET['label_size'] = 'standard';
$_GET['copies'] = '6';

ob_start();
include __DIR__ . '/../barcode-print.php';
$htmlStd = ob_get_clean();

assert_true(str_contains($htmlStd, '<div class="size-standard">'), "Standard preset still outputs class 'size-standard'");
assert_true(str_contains($htmlStd, 'value="standard" selected'), "Standard preset option remains selected");
assert_true(str_contains($htmlStd, 'repeat(auto-fill, minmax(210px, 1fr))'), "Standard preset retains its default auto-fill responsive grid");

echo "\n========================================================\n";
if ($failures === 0) {
    echo "   ALL BARCODE 2x1 TESTS PASSED SUCCESSFULLY!          \n";
} else {
    echo "   {$failures} TESTS FAILED!                           \n";
}
echo "========================================================\n";

exit($failures === 0 ? 0 : 1);

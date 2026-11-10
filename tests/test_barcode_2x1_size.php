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
assert_true(str_contains($content, '2 x 1 inch (2 in 1 Row)'), "Dropdown displays label '2 x 1 inch (2 in 1 Row)'");
assert_true(str_contains($content, '.size-2x1 .print-grid'), "CSS contains .size-2x1 .print-grid rule");
assert_true(str_contains($content, 'grid-template-columns: repeat(2, minmax(0, 1fr)) !important;'), "Screen CSS sets 2 columns for 2x1");
assert_true(str_contains($content, 'grid-template-columns: repeat(2, 1fr) !important;'), "Print media CSS sets 2 columns for 2x1");
assert_true(str_contains($content, "max-width: 620px"), "Screen grid is max-width constrained for 2:1 ratio");

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
assert_true(str_contains($html, 'grid-template-columns: repeat(2, minmax(0, 1fr)); max-width: 620px;'), "Grid style renders 2 columns inline on screen preview");

// 4. Test backward compatibility for standard preset
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

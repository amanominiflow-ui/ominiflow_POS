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
assert_true(str_contains($content, '.size-2x1 .print-grid'), "CSS defines grid layout for .size-2x1");
assert_true(str_contains($content, '.size-4x1 .print-grid'), "CSS defines grid layout for .size-4x1");
assert_true(str_contains($content, 'grid-template-columns: repeat(2, minmax(0, 1fr)) !important;'), "Screen CSS configures 2 columns per row");
assert_true(str_contains($content, 'grid-template-columns: repeat(2, 1fr) !important;'), "Print CSS configures 2 columns per row");

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
assert_true(str_contains($html, 'class="print-grid"'), "Renders continuous print-grid for 2x1");
assert_true(str_contains($html, 'grid-template-columns: repeat(2, minmax(0, 1fr));'), "Applies 2-in-one-row grid inline style");
assert_true(substr_count($html, 'class="barcode-card-item"') === 6, "6 copies render all 6 label cards in continuous grid");

// 4. Test rendering simulated GET request for 4x1 preset
$_GET['label_size'] = '4x1';
$_GET['copies'] = '4';

ob_start();
include __DIR__ . '/../barcode-print.php';
$html4x1 = ob_get_clean();

assert_true(str_contains($html4x1, '<div class="size-4x1">'), "Output HTML contains wrapper class 'size-4x1'");
assert_true(str_contains($html4x1, 'value="4x1" selected'), "Dropdown option 4x1 is selected");
assert_true(str_contains($html4x1, 'class="print-grid"'), "Renders continuous print-grid for 4x1");
assert_true(substr_count($html4x1, 'class="barcode-card-item"') === 4, "4 copies render all 4 label cards in continuous grid");

// 5. Test backward compatibility for standard preset
$_GET['label_size'] = 'standard';
$_GET['copies'] = '6';

ob_start();
include __DIR__ . '/../barcode-print.php';
$htmlStd = ob_get_clean();

assert_true(str_contains($htmlStd, '<div class="size-standard">'), "Standard preset still outputs class 'size-standard'");
assert_true(str_contains($htmlStd, 'value="standard" selected'), "Standard preset option remains selected");
assert_true(str_contains($htmlStd, 'repeat(auto-fill, minmax(210px, 1fr))'), "Standard preset retains its default auto-fill responsive grid");
assert_true(str_contains($htmlStd, 'size: auto;'), "Standard preset preserves standard sheet '@page { size: auto; margin: 5mm; }'");
assert_true(!str_contains($htmlStd, 'size: 4in 1in;'), "Standard preset does not set 4-inch roll size");

// 6. Test 4-inch page size for 2x1 preset
assert_true(str_contains($html, 'size: 4in 1in;'), "2x1 preset generates '@page { size: 4in 1in; margin: 0; }'");
assert_true(str_contains($html, '#omniflow-impersonation-bar'), "Impersonation bar is hidden in print mode");

// 7. Test Ash Collective auto-defaults to 2x1 template
unset($_GET['label_size']);
$_SESSION['user_email'] = 'ashcollective1@gmail.com';

ob_start();
include __DIR__ . '/../barcode-print.php';
$htmlAsh = ob_get_clean();

assert_true(str_contains($htmlAsh, 'value="2x1" selected'), "Ash Collective defaults to 2x1 template");
assert_true(str_contains($htmlAsh, 'size: 4in 1in;'), "Ash Collective default sets 4in 1in page size");

echo "\n========================================================\n";
if ($failures === 0) {
    echo "   ALL BARCODE 2x1 TESTS PASSED SUCCESSFULLY!          \n";
} else {
    echo "   {$failures} TESTS FAILED!                           \n";
}
echo "========================================================\n";

exit($failures === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/**
 * CLI smoke checks for purchase receive + barcode helpers (no web session).
 * Run: php tests/smoke_purchase_barcode.php
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/purchases_db.php';
require_once __DIR__ . '/../includes/barcode_helper.php';

$failures = 0;

function ok(string $msg): void {
    echo "[OK] {$msg}\n";
}

function fail(string $msg): void {
    global $failures;
    $failures++;
    echo "[FAIL] {$msg}\n";
}

try {
    ensure_purchases_full_schema();
    ok('ensure_purchases_full_schema()');
} catch (Throwable $e) {
    fail('ensure_purchases_full_schema: ' . $e->getMessage());
}

$db = get_db();
$tables = ['purchase_receives', 'purchase_receive_items', 'purchase_orders', 'purchase_order_items'];
foreach ($tables as $t) {
    try {
        $db->query("SELECT 1 FROM `{$t}` LIMIT 1");
        ok("table {$t} readable");
    } catch (Throwable $e) {
        fail("table {$t}: " . $e->getMessage());
    }
}

$svg = generate_code128_svg('8901234567890', 36, 1.4);
if (str_contains($svg, '<svg') && str_contains($svg, '8901234567890')) {
    ok('generate_code128_svg()');
} else {
    fail('generate_code128_svg output unexpected');
}

$emptySvg = generate_code128_svg('', 36, 1.4);
if (str_contains($emptySvg, '<svg')) {
    ok('generate_code128_svg empty fallback');
} else {
    fail('generate_code128_svg empty');
}

// receive_purchase_order_items should delegate without throwing on missing PO
$res = receive_purchase_order_items(0, [], null, 1);
if (!$res['success'] && !empty($res['error'])) {
    ok('receive_purchase_order_items invalid PO returns error');
} else {
    fail('receive_purchase_order_items invalid PO should fail');
}

echo $failures === 0 ? "\nAll smoke checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);

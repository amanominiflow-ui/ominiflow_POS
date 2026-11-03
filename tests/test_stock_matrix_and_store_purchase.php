<?php
declare(strict_types=1);

/**
 * Test: Stock Matrix Feature & Store Size-Specific Stock Decrement
 * Run: php tests/test_stock_matrix_and_store_purchase.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/inward_db.php';
require_once __DIR__ . '/../includes/orders_db.php';
require_once __DIR__ . '/../includes/storefront_db.php';

$failures = 0;
function assert_test(string $name, bool $cond, string $msg = ''): void {
    global $failures;
    if (!$cond) {
        $failures++;
        echo "[FAIL] {$name}: {$msg}\n";
    } else {
        echo "[PASS] {$name}\n";
    }
}

echo "=== 1. TEST PRODUCT CREATION & MATRIX SETUP ===\n";
$db = get_db();
$admin = $db->query("SELECT id, business_id FROM users WHERE role IN ('owner', 'admin') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$bid = (int)($admin['business_id'] ?? 1);
$uid = (int)($admin['id'] ?? 1);

// Create product
$sku = 'MTX-' . strtoupper(substr(uniqid(), -5));
$prodRes = save_product([
    'name' => 'Matrix Test Polo ' . time(),
    'sku' => $sku,
    'product_type' => 'simple',
    'selling_price' => 799.00,
    'cost_price' => 350.00,
    'status' => 'active',
], null, null, $uid, $bid);

assert_test('Product created', !empty($prodRes['success']), json_encode($prodRes));
$pid = (int)$prodRes['product_id'];

echo "=== 2. TEST SAVE STOCK MATRIX (CONVERT SIMPLE TO SIZE MATRIX) ===\n";
$matrixPayload = [
    'sizes' => ['S', 'M', 'L', 'XL', 'XXL'],
    'rows' => [
        [
            'colour' => 'Navy',
            'cost_price' => 350.00,
            'selling_price' => 799.00,
            'quantities' => [
                'S' => 10,
                'M' => 25,
                'L' => 30,
                'XL' => 15,
                'XXL' => 5,
            ]
        ]
    ]
];

$saveRes = save_product_stock_matrix($pid, $matrixPayload, $bid, $uid);
assert_test('Save stock matrix success', !empty($saveRes['success']), $saveRes['error'] ?? '');
assert_test('Expected total stock is 85', (int)($saveRes['total_stock'] ?? 0) === 85, 'Got ' . ($saveRes['total_stock'] ?? 0));

$parentProd = $db->query("SELECT stock_quantity, product_type FROM products WHERE id = $pid")->fetch(PDO::FETCH_ASSOC);
assert_test('Parent stock is 85', (int)$parentProd['stock_quantity'] === 85);
assert_test('Product type converted to variable', $parentProd['product_type'] === 'variable');

echo "=== 3. TEST GET STOCK MATRIX ===\n";
$matrixData = get_product_stock_matrix($pid, $bid);
assert_test('Get stock matrix success', !empty($matrixData['success']));
assert_test('Sizes count is 5', count($matrixData['sizes']) === 5);
assert_test('Totals match 85', (int)$matrixData['totals']['total_qty'] === 85);
assert_test('Size L stock is 30', (int)($matrixData['rows'][0]['quantities']['L'] ?? 0) === 30);
assert_test('Size S stock is 10', (int)($matrixData['rows'][0]['quantities']['S'] ?? 0) === 10);
assert_test('Total valuation is 67915', (float)$matrixData['totals']['total_value'] === 67915.00);

echo "=== 4. TEST STORE PURCHASE OF SIZE L ONLY ===\n";
// Find variant ID for size L
$varL = $db->query("
    SELECT id, variant_name, stock_quantity 
    FROM product_variants 
    WHERE product_id = $pid AND variant_name LIKE '%L%' AND variant_name NOT LIKE '%XL%' AND variant_name NOT LIKE '%XXL%'
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
assert_test('Found L variant', !empty($varL['id']), 'Variant not found');
$varLId = (int)$varL['id'];
$initialLStock = (int)$varL['stock_quantity'];
echo "Initial Size L stock: {$initialLStock}\n";

// Place order for 2 units of Size L
$orderRes = process_pos_order(
    [
        [
            'product_id' => $pid,
            'variant_id' => $varLId,
            'size' => 'L',
            'colour' => 'Navy',
            'quantity' => 2,
            'price' => 799.00
        ]
    ],
    1, $uid, 0.0, 'fixed', 'cash', 'Customer store purchase Size L', 1598.00, 1, null, null, null, 0, 0.0, null, $bid, 'online_store', 'delivered'
);
assert_test('Store purchase placed successfully', !empty($orderRes['success']), json_encode($orderRes));

// Check variant stocks after purchase
$varLAfter = $db->query("SELECT stock_quantity FROM product_variants WHERE id = $varLId")->fetchColumn();
assert_test('Size L variant stock decreased by 2 (30 -> 28)', (int)$varLAfter === 28, "Got {$varLAfter}");

// Verify other sizes were NOT touched
$otherSizes = $db->query("SELECT variant_name, stock_quantity FROM product_variants WHERE product_id = $pid AND id != $varLId ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($otherSizes as $os) {
    if (strpos($os['variant_name'], '/ S') !== false) {
        assert_test('Size S stock remains 10', (int)$os['stock_quantity'] === 10, "Got {$os['stock_quantity']}");
    } elseif (strpos($os['variant_name'], '/ M') !== false) {
        assert_test('Size M stock remains 25', (int)$os['stock_quantity'] === 25, "Got {$os['stock_quantity']}");
    } elseif (strpos($os['variant_name'], '/ XL') !== false) {
        assert_test('Size XL stock remains 15', (int)$os['stock_quantity'] === 15, "Got {$os['stock_quantity']}");
    } elseif (strpos($os['variant_name'], '/ XXL') !== false) {
        assert_test('Size XXL stock remains 5', (int)$os['stock_quantity'] === 5, "Got {$os['stock_quantity']}");
    }
}

// Verify parent product total stock reflects new grand total (85 - 2 = 83)
$parentAfter = $db->query("SELECT stock_quantity FROM products WHERE id = $pid")->fetchColumn();
assert_test('Parent product total stock is 83 (85 - 2)', (int)$parentAfter === 83, "Got {$parentAfter}");

echo "=== 5. TEST GET MATRIX AFTER PURCHASE ===\n";
$matrixAfter = get_product_stock_matrix($pid, $bid);
assert_test('Matrix grand total reflects 83', (int)$matrixAfter['totals']['total_qty'] === 83);
assert_test('Matrix Size L reflects 28', (int)$matrixAfter['rows'][0]['quantities']['L'] === 28);
assert_test('Column L total reflects 28', (int)$matrixAfter['totals']['by_size']['L'] === 28);

echo "=== 6. TEST MULTI-COLOUR MATRIX WITH ZERO STOCK ===\n";
// Update matrix with 2 colours (Navy and White) and set some to 0
$matrixPayload2 = [
    'sizes' => ['S', 'M', 'L'],
    'rows' => [
        [
            'colour' => 'Navy',
            'cost_price' => 350.00,
            'selling_price' => 799.00,
            'quantities' => ['S' => 5, 'M' => 0, 'L' => 10]
        ],
        [
            'colour' => 'White',
            'cost_price' => 360.00,
            'selling_price' => 849.00,
            'quantities' => ['S' => 2, 'M' => 4, 'L' => 0]
        ]
    ]
];
$saveRes2 = save_product_stock_matrix($pid, $matrixPayload2, $bid, $uid);
assert_test('Multi-colour matrix save success', !empty($saveRes2['success']));
assert_test('Multi-colour total stock is 21', (int)$saveRes2['total_stock'] === 21, "Got " . $saveRes2['total_stock']);

$parentAfter2 = $db->query("SELECT stock_quantity FROM products WHERE id = $pid")->fetchColumn();
assert_test('Parent product stock matches 21', (int)$parentAfter2 === 21);

echo "\n=========================================\n";
if ($failures === 0) {
    echo "ALL TESTS PASSED SUCCESSFULLY! (0 failures)\n";
    exit(0);
} else {
    echo "TESTS COMPLETED WITH {$failures} FAILURE(S).\n";
    exit(1);
}

<?php
/**
 * End-to-End Test for:
 * 1. "Show on Store" toggle (default on for new products, editable on existing products, storefront filtering)
 * 2. Interactive Stock Matrix & size L purchase deduction
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/storefront_db.php';
require_once __DIR__ . '/../includes/orders_db.php';

echo "=== Running Show on Store & Matrix Integration Tests ===\n";

$db = get_db();
$bizId = 1;
$userId = 1;

// 1. Test "Show on Store" default on product creation
$testSku1 = 'TEST-STORE-DEF-' . time();
$prodData1 = [
    'name' => 'Store Test Default Prod ' . time(),
    'sku' => $testSku1,
    'barcode' => 'BRC' . time(),
    'category_id' => null,
    'cost_price' => 200.0,
    'selling_price' => 500.0,
    'stock_quantity' => 10,
    'low_stock_threshold' => 2,
    'status' => 'active',
    'product_type' => 'simple',
    'show_on_store' => 1
];

$res1 = save_product($prodData1, null, null, $userId, $bizId);
assert($res1['success'] === true, "save_product failed: " . json_encode($res1['errors'] ?? []));
$pId1 = (int)$res1['product_id'];

$saved1 = get_product_by_id($pId1, $bizId);
assert((int)$saved1['show_on_store'] === 1, "Default show_on_store must be 1, got: " . ($saved1['show_on_store'] ?? 'null'));
echo "✔ Product created with show_on_store = 1 (default active)\n";

// 2. Test updating existing product to show_on_store = 0
$prodData1['show_on_store'] = 0;
$resUpdate = save_product($prodData1, null, $pId1, $userId, $bizId);
assert($resUpdate['success'] === true, "save_product update failed: " . json_encode($resUpdate['errors'] ?? []));

$savedUpdated = get_product_by_id($pId1, $bizId);
assert((int)$savedUpdated['show_on_store'] === 0, "Updated show_on_store must be 0, got: " . ($savedUpdated['show_on_store'] ?? 'null'));
echo "✔ Existing product updated to show_on_store = 0\n";

// 3. Test Storefront queries with showOnStore filter
// Product 1 has show_on_store = 0, should NOT be in showOnStore=true list
$storeProds = get_products('', null, 'active', '', $bizId, '', true);
$foundStoreProd1 = false;
foreach ($storeProds as $sp) {
    if ((int)$sp['id'] === $pId1) {
        $foundStoreProd1 = true;
        break;
    }
}
assert(!$foundStoreProd1, "Product with show_on_store = 0 must NOT appear in storefront products list");
echo "✔ Storefront get_products(..., showOnStore: true) correctly excludes show_on_store = 0\n";

// Product 1 SHOULD appear in showOnStore = false list
$hiddenProds = get_products('', null, 'active', '', $bizId, '', false);
$foundHiddenProd1 = false;
foreach ($hiddenProds as $hp) {
    if ((int)$hp['id'] === $pId1) {
        $foundHiddenProd1 = true;
        break;
    }
}
assert($foundHiddenProd1, "Product with show_on_store = 0 must appear in showOnStore: false list");
echo "✔ get_products(..., showOnStore: false) correctly finds the hidden product\n";

// Now toggle back to show_on_store = 1
$prodData1['show_on_store'] = 1;
$prodData1['is_trending'] = 1;
save_product($prodData1, null, $pId1, $userId, $bizId);

$trendingProds = get_storefront_trending_products($bizId, 50);
$foundTrending = false;
foreach ($trendingProds as $tp) {
    if ((int)$tp['id'] === $pId1) {
        $foundTrending = true;
        break;
    }
}
assert($foundTrending, "Product with show_on_store = 1 and is_trending = 1 must appear in trending storefront products");
echo "✔ get_storefront_trending_products includes show_on_store = 1 products\n";

// 4. Test Stock Matrix creation & size L purchase deduction
$matrixSku = 'TEST-MX-' . time();
$prodMatrix = [
    'name' => 'Matrix Garment ' . time(),
    'sku' => $matrixSku,
    'barcode' => 'MXB' . time(),
    'category_id' => null,
    'cost_price' => 300.0,
    'selling_price' => 799.0,
    'stock_quantity' => 0,
    'low_stock_threshold' => 2,
    'status' => 'active',
    'product_type' => 'simple',
    'show_on_store' => 1
];
$resM = save_product($prodMatrix, null, null, $userId, $bizId);
$pIdMatrix = (int)$resM['product_id'];

// Save matrix with sizes S, M, L, XL and colour "Navy"
$matrixPayload = [
    'sizes' => ['S', 'M', 'L', 'XL'],
    'rows' => [
        [
            'colour' => 'Navy',
            'quantities' => [
                'S' => 5,
                'M' => 10,
                'L' => 15,
                'XL' => 8
            ]
        ]
    ]
];

$saveMxRes = save_product_stock_matrix($pIdMatrix, $matrixPayload, $bizId, $userId);
assert($saveMxRes['success'] === true, "save_product_stock_matrix failed: " . ($saveMxRes['error'] ?? ''));
assert((int)$saveMxRes['total_stock'] === 38, "Expected total stock 38 (5+10+15+8), got: " . $saveMxRes['total_stock']);

// Fetch matrix data
$matrixData = get_product_stock_matrix($pIdMatrix, $bizId);
assert($matrixData['success'] === true, "get_product_stock_matrix failed");
assert($matrixData['sizes'] === ['S', 'M', 'L', 'XL'], "Garment sizes must be standard sorted S, M, L, XL");
assert($matrixData['totals']['total_qty'] === 38, "Grand total pcs must be 38");
echo "✔ Stock matrix saved and loaded successfully with 38 pcs across S, M, L, XL\n";

// Check parent product stock
$parentProd = get_product_by_id($pIdMatrix, $bizId);
assert((int)$parentProd['stock_quantity'] === 38, "Parent product stock must be 38, got: " . $parentProd['stock_quantity']);
assert($parentProd['product_type'] === 'variable', "Parent product type must now be variable");

// 5. Simulate purchasing size L in POS / Store
$vars = get_product_variants($pIdMatrix, $bizId);
$variantL = null;
$variantS = null;
foreach ($vars as $v) {
    $attrs = parse_variant_size_colour($v);
    if ($attrs['size'] === 'L') {
        $variantL = $v;
    } elseif ($attrs['size'] === 'S') {
        $variantS = $v;
    }
}
assert($variantL !== null, "Variant L must exist");
assert((int)$variantL['stock_quantity'] === 15, "Variant L stock must start at 15");

// Process an order buying 2 pcs of size L
$orderRes = process_pos_order(
    [
        [
            'product_id' => $pIdMatrix,
            'variant_id' => (int)$variantL['id'],
            'size' => 'L',
            'colour' => 'Navy',
            'quantity' => 2,
            'price' => 799.00
        ]
    ],
    1, $userId, 0.0, 'fixed', 'cash', 'Customer store purchase Size L', 1598.00, 1, null, null, null, 0, 0.0, null, $bizId, 'online_store', 'delivered'
);
assert($orderRes['success'] === true, "process_pos_order failed: " . ($orderRes['error'] ?? ''));

// Verify: Variant L stock decreased by 2 (15 -> 13)
$updatedVars = get_product_variants($pIdMatrix, $bizId);
$updatedL = null;
$updatedS = null;
foreach ($updatedVars as $uv) {
    $attrs = parse_variant_size_colour($uv);
    if ($attrs['size'] === 'L') {
        $updatedL = $uv;
    } elseif ($attrs['size'] === 'S') {
        $updatedS = $uv;
    }
}

assert((int)$updatedL['stock_quantity'] === 13, "Variant L stock must be 13 after buying 2, got: " . $updatedL['stock_quantity']);
assert((int)$updatedS['stock_quantity'] === 5, "Variant S stock must remain untouched at 5, got: " . $updatedS['stock_quantity']);
echo "✔ Size L stock successfully decremented by 2, size S untouched\n";

// Verify: Parent product total stock automatically recalculated to 36 (38 - 2)
$updatedParent = get_product_by_id($pIdMatrix, $bizId);
assert((int)$updatedParent['stock_quantity'] === 36, "Parent product stock must be 36 (38 - 2), got: " . $updatedParent['stock_quantity']);
echo "✔ Parent product total stock automatically synced to 36\n";

echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";

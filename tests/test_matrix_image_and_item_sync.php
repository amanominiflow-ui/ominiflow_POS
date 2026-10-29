<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/inward_db.php';

echo "=== TEST: MATRIX INWARD IMAGE & ITEM SYNC ===\n";

$db = get_db();
ensure_product_item_schema();
ensure_inward_schema();

$userStmt = $db->query("SELECT * FROM users WHERE role IN ('owner', 'admin') LIMIT 1");
$adminUser = $userStmt->fetch(PDO::FETCH_ASSOC);
$bid = (int) ($adminUser['business_id'] ?? 1);
$userId = (int) $adminUser['id'];
$_SESSION['user_id'] = $userId;
$_SESSION['business_id'] = $bid;

// 1. Create a test product
$prodRes = save_product([
    'name' => 'Sync Test Kurti ' . time(),
    'sku' => 'STK-' . substr(uniqid(), -4),
    'product_type' => 'simple',
    'track_inventory' => 1,
    'status' => 'active',
], null, null, $userId, $bid);

if (empty($prodRes['success'])) {
    die("FAILED to create test product: " . json_encode($prodRes) . "\n");
}
$productId = (int)$prodRes['product_id'];
$parentSku = $prodRes['sku'] ?? 'STK';
echo "[OK] Created test product ID: $productId ($parentSku)\n";

// 2. Prepare mock matrix inward payload with images, costs, selling prices
$testImg1 = 'assets/uploads/products/variants/test_maroon_' . time() . '.png';
$testImg2 = 'assets/uploads/products/variants/test_black_' . time() . '.png';

$payload = [
    'products' => [
        [
            'product_id' => $productId,
            'sizes' => ['S', 'M', 'L'],
            'rows' => [
                [
                    'colour' => 'Maroon',
                    'colour_code' => 'MRN',
                    'video_sku' => $parentSku . '-MRN',
                    'image_path' => $testImg1,
                    'cost_price' => 240.00,
                    'selling_price' => 599.00,
                    'quantities' => ['S' => 5, 'M' => 10, 'L' => 7]
                ],
                [
                    'colour' => 'Black',
                    'colour_code' => 'BLK',
                    'video_sku' => $parentSku . '-BLK',
                    'image_path' => $testImg2,
                    'cost_price' => 260.00,
                    'selling_price' => 649.00,
                    'quantities' => ['S' => 3, 'M' => 6, 'L' => 4]
                ]
            ]
        ]
    ]
];

// 3. Get warehouse and execute matrix inward
$whId = (int)$db->query("SELECT id FROM warehouses WHERE business_id = $bid AND status = 'active' LIMIT 1")->fetchColumn();
if (!$whId) {
    die("No active warehouse found\n");
}

$res = create_inward_matrix_entry(
    $whId,
    $payload,
    date('Y-m-d'),
    'DOC-IMG-SYNC-' . time(),
    'Testing image and item sync',
    null,
    'Sync Test Vendor',
    $userId,
    $bid
);

if (empty($res['success'])) {
    die("FAILED to create matrix inward: " . ($res['error'] ?? 'Unknown error') . "\n");
}
echo "[OK] Matrix Inward created: " . $res['entry_number'] . " (Total Qty: " . $res['total_qty'] . ")\n";

// 4. Verify parent product synchronization
$stmtP = $db->prepare("SELECT * FROM products WHERE id = :id AND business_id = :bid");
$stmtP->execute(['id' => $productId, 'bid' => $bid]);
$parent = $stmtP->fetch();

assert($parent['product_type'] === 'variable', "Parent product_type should be variable, got: " . $parent['product_type']);
assert((float)$parent['cost_price'] === 240.00, "Parent cost_price should be 240.00, got: " . $parent['cost_price']);
assert((float)$parent['selling_price'] === 599.00, "Parent selling_price should be 599.00, got: " . $parent['selling_price']);
assert($parent['image_path'] === $testImg1, "Parent image_path should be $testImg1, got: " . $parent['image_path']);
echo "[OK] Parent product synchronized (type=variable, cost=240, price=599, img=$testImg1)\n";

// 5. Verify product_variants synchronization
$stmtV = $db->prepare("SELECT * FROM product_variants WHERE product_id = :pid AND business_id = :bid ORDER BY id ASC");
$stmtV->execute(['pid' => $productId, 'bid' => $bid]);
$variants = $stmtV->fetchAll();

echo "[INFO] Created " . count($variants) . " variants in product_variants\n";
assert(count($variants) === 6, "Expected 6 variants (2 colours x 3 sizes), got: " . count($variants));

foreach ($variants as $v) {
    $vName = $v['variant_name'];
    $isMaroon = strpos($vName, 'Maroon') !== false;
    $isBlack = strpos($vName, 'Black') !== false;
    
    if ($isMaroon) {
        assert($v['image_path'] === $testImg1, "Maroon variant image_path mismatch: " . $v['image_path']);
        assert($v['video_sku'] === $parentSku . '-MRN', "Maroon video_sku mismatch: " . $v['video_sku']);
        assert((float)$v['cost_price'] === 240.00, "Maroon cost_price mismatch: " . $v['cost_price']);
        assert((float)$v['selling_price'] === 599.00, "Maroon selling_price mismatch: " . $v['selling_price']);
    } elseif ($isBlack) {
        assert($v['image_path'] === $testImg2, "Black variant image_path mismatch: " . $v['image_path']);
        assert($v['video_sku'] === $parentSku . '-BLK', "Black video_sku mismatch: " . $v['video_sku']);
        assert((float)$v['cost_price'] === 260.00, "Black cost_price mismatch: " . $v['cost_price']);
        assert((float)$v['selling_price'] === 649.00, "Black selling_price mismatch: " . $v['selling_price']);
    }
}
echo "[OK] All variants have correct image_path, video_sku, cost_price, and selling_price!\n";

// 6. Verify product_attributes synchronization
$attrs = get_product_attributes($productId, $bid);
$attrNames = array_column($attrs, 'attribute_name');
assert(in_array('Color', $attrNames), "Missing 'Color' attribute");
assert(in_array('Size', $attrNames), "Missing 'Size' attribute");

foreach ($attrs as $a) {
    $optVals = array_column($a['options'], 'value');
    if ($a['attribute_name'] === 'Color') {
        assert(in_array('Maroon', $optVals), "Missing Maroon in Color options");
        assert(in_array('Black', $optVals), "Missing Black in Color options");
    } elseif ($a['attribute_name'] === 'Size') {
        assert(in_array('S', $optVals), "Missing S in Size options");
        assert(in_array('M', $optVals), "Missing M in Size options");
        assert(in_array('L', $optVals), "Missing L in Size options");
    }
}
echo "[OK] Product attributes (Color and Size) synchronized for Item Edit form!\n";

// 7. Verify stock quantities posted
$totalVarStock = (int)$db->query("SELECT SUM(stock_quantity) FROM product_variants WHERE product_id = $productId AND business_id = $bid")->fetchColumn();
echo "[INFO] Total variant stock: $totalVarStock (Expected: " . $res['total_qty'] . ")\n";
assert($totalVarStock === (int)$res['total_qty'], "Variant stock mismatch");

echo "\nSUCCESS: ALL MATRIX IMAGE & ITEM SYNC TESTS PASSED PERFECTLY!\n";

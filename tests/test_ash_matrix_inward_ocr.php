<?php
declare(strict_types=1);

/**
 * Ash Collective Developer Brief - Comprehensive Test Suite
 * Validates 3-Tier Product Data Model, Matrix Inward Entry, Bulk CSV Import, and WhatsApp OCR API
 * Covering all 13 Acceptance Tests from Section 11 of the brief.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/inward_db.php';

echo "=================================================================\n";
echo "   ASH COLLECTIVE: PRODUCT MATRIX & WHATSAPP OCR TEST SUITE     \n";
echo "=================================================================\n\n";

$db = get_db();
ensure_product_item_schema();
ensure_inward_schema();

// Authenticate or find admin user
$userStmt = $db->query("SELECT * FROM users WHERE role IN ('owner', 'admin') LIMIT 1");
$adminUser = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$adminUser) {
    echo "FAIL: No admin user found.\n";
    exit(1);
}

$businessId = (int) ($adminUser['business_id'] ?? 1);
$userId = (int) $adminUser['id'];
$_SESSION['user_id'] = $userId;
$_SESSION['business_id'] = $businessId;

// Find or create active warehouse
$whStmt = $db->prepare("SELECT id, name FROM warehouses WHERE business_id = :bid AND status = 'active' ORDER BY id ASC LIMIT 1");
$whStmt->execute(['bid' => $businessId]);
$whRow = $whStmt->fetch(PDO::FETCH_ASSOC);
if (!$whRow) {
    $db->prepare("INSERT INTO warehouses (business_id, name, code, is_default, status) VALUES (:bid, 'Central Warehouse', 'WH-CENTRAL', 1, 'active')")
        ->execute(['bid' => $businessId]);
    $warehouseId = (int)$db->lastInsertId();
    $warehouseName = 'Central Warehouse';
} else {
    $warehouseId = (int)$whRow['id'];
    $warehouseName = (string)$whRow['name'];
}

// Find or create test vendor
$vStmt = $db->prepare("SELECT id, name FROM vendors WHERE business_id = :bid AND status = 'active' LIMIT 1");
$vStmt->execute(['bid' => $businessId]);
$vendor = $vStmt->fetch(PDO::FETCH_ASSOC);
if (!$vendor) {
    $db->prepare("INSERT INTO vendors (business_id, name, status) VALUES (:bid, 'Ash Wholesale Suppliers', 'active')")
        ->execute(['bid' => $businessId]);
    $vendorId = (int)$db->lastInsertId();
    $vendorName = 'Ash Wholesale Suppliers';
} else {
    $vendorId = (int)$vendor['id'];
    $vendorName = (string)$vendor['name'];
}

$testPassed = 0;
$testFailed = 0;

function run_test(string $name, callable $fn): void {
    global $testPassed, $testFailed;
    echo "[TEST] {$name}... ";
    try {
        $result = $fn();
        if ($result === true || $result === null) {
            echo "PASSED\n";
            $testPassed++;
        } else {
            echo "FAILED: {$result}\n";
            $testFailed++;
        }
    } catch (Throwable $e) {
        echo "FAILED WITH EXCEPTION: " . $e->getMessage() . "\n";
        echo "       At " . $e->getFile() . ":" . $e->getLine() . "\n";
        $testFailed++;
    }
}

/**
 * Isolated call to api/whatsapp_ocr.php via PHP subprocess
 */
function call_ocr_api(array $get = [], array $post = []): array {
    global $businessId;
    if (!isset($get['business_id']) && !isset($post['business_id'])) {
        $get['business_id'] = $businessId;
    }

    $phpExe = 'C:\\xampp\\php\\php.exe';
    $script = str_replace('\\', '/', realpath(__DIR__ . '/../api/whatsapp_ocr.php'));
    $method = !empty($post) ? 'POST' : 'GET';
    $getStr = addslashes(http_build_query($get));
    $postStr = addslashes(http_build_query($post));

    $code = "parse_str('{$getStr}', \$_GET); parse_str('{$postStr}', \$_POST); \$_SERVER['REQUEST_METHOD'] = '{$method}'; include '{$script}';";
    $cmd = "\"{$phpExe}\" -r \"{$code}\"";

    $output = shell_exec($cmd);
    $data = json_decode((string)$output, true);
    if (!is_array($data)) {
        return ['success' => false, 'raw_output' => $output, 'error' => 'Malformed API response: ' . $output];
    }
    return $data;
}

$uniqueSuffix = strtoupper(substr(uniqid(), -5));
$designCode = 'AC' . rand(100, 999) . '-' . $uniqueSuffix; // e.g. AC125-XYZ
$testBillRef = 'BILL-' . $uniqueSuffix . '-001';
$parentProdId = null;

// Clean up any old test items with this design code
$db->prepare("DELETE FROM products WHERE sku = :sku AND business_id = :bid")->execute(['sku' => $designCode, 'bid' => $businessId]);

// -------------------------------------------------------------------------
// TEST 1: 3-Tier Data Model - Parent with 3 colours and 5 sizes -> 15 unique inventory SKUs
// -------------------------------------------------------------------------
run_test("1. Create parent design with 3 colours and 5 sizes (15 unique inventory SKUs)", function() use ($db, $businessId, $designCode, &$parentProdId) {
    // Insert parent design
    $db->prepare("
        INSERT INTO products (
            business_id, name, sku, product_type, cost_price, selling_price,
            track_inventory, stock_quantity, status, created_at, updated_at
        ) VALUES (
            :bid, :name, :sku, 'variable', 250.00, 599.00,
            1, 0, 'active', NOW(), NOW()
        )
    ")->execute([
        'bid' => $businessId,
        'name' => 'Designer Anarkali Kurti ' . $designCode,
        'sku' => $designCode,
    ]);
    $parentProdId = (int)$db->lastInsertId();

    $colourDefs = [
        ['colour' => 'Maroon', 'colour_code' => 'MRN', 'cost_price' => 250.00, 'selling_price' => 599.00],
        ['colour' => 'Black', 'colour_code' => 'BLK', 'cost_price' => 250.00, 'selling_price' => 599.00],
        ['colour' => 'Bottle Green', 'colour_code' => 'BGR', 'cost_price' => 250.00, 'selling_price' => 599.00],
    ];
    $sizes = ['S', 'M', 'L', 'XL', 'XXL'];

    $variantMatrix = ensure_matrix_variants($parentProdId, $colourDefs, $sizes, $businessId);

    // Verify 15 variants created in product_variants
    $stmt = $db->prepare("SELECT id, sku, video_sku, color_code, variant_name, barcode FROM product_variants WHERE product_id = :pid AND business_id = :bid");
    $stmt->execute(['pid' => $parentProdId, 'bid' => $businessId]);
    $vars = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($vars) !== 15) {
        return "Expected 15 variants, found " . count($vars);
    }

    // Verify Video SKU and Inventory SKU format
    $sampleMaroonL = null;
    foreach ($vars as $v) {
        if ($v['sku'] === "{$designCode}-MRN-L") {
            $sampleMaroonL = $v;
            break;
        }
    }
    if (!$sampleMaroonL) {
        return "Variant with SKU '{$designCode}-MRN-L' not found.";
    }
    if ($sampleMaroonL['video_sku'] !== "{$designCode}-MRN") {
        return "Expected video_sku '{$designCode}-MRN', got '{$sampleMaroonL['video_sku']}'";
    }
    if (empty($sampleMaroonL['barcode'])) {
        return "Variant barcode is empty.";
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 2: Video SKU Resolves to 5 Maroon Size Variants Without Duplicate Stock
// -------------------------------------------------------------------------
run_test("2. Video SKU lookup resolves Maroon variants without conflation", function() use ($businessId, $designCode) {
    $videoSku = "{$designCode}-MRN";
    $result = find_variants_by_video_sku($videoSku, $businessId);

    if (!$result) {
        return "Video SKU lookup returned null.";
    }
    if ($result['parent']['sku'] !== $designCode) {
        return "Parent SKU mismatch: expected {$designCode}, got " . ($result['parent']['sku'] ?? '');
    }
    if (count($result['variants']) !== 5) {
        return "Expected 5 Maroon variants, got " . count($result['variants']);
    }

    foreach ($result['variants'] as $v) {
        if (strcasecmp((string)$v['colour'], 'Maroon') !== 0) {
            return "Unexpected colour in Maroon lookup: " . $v['colour'];
        }
    }
    return true;
});

// -------------------------------------------------------------------------
// TEST 3: Post 100-piece Matrix Inward Receipt
// -------------------------------------------------------------------------
run_test("3. Post 100-piece matrix inward receipt and verify variant stock matches matrix exactly", function() use ($businessId, $warehouseId, $vendorId, $vendorName, $userId, $parentProdId, $designCode, $testBillRef, $db) {
    // 100 pieces total:
    // Maroon: S=4, M=8, L=10, XL=6, XXL=2 = 30 pcs
    // Black: S=5, M=10, L=12, XL=8, XXL=5 = 40 pcs
    // Bottle Green: S=5, M=8, L=10, XL=5, XXL=2 = 30 pcs
    // Grand Total: 30 + 40 + 30 = 100 pcs
    $matrixPayload = [
        'product_id' => $parentProdId,
        'sizes' => ['S', 'M', 'L', 'XL', 'XXL'],
        'rows' => [
            [
                'colour' => 'Maroon',
                'colour_code' => 'MRN',
                'unit_cost' => 250.00,
                'selling_price' => 599.00,
                'quantities' => ['S' => 4, 'M' => 8, 'L' => 10, 'XL' => 6, 'XXL' => 2]
            ],
            [
                'colour' => 'Black',
                'colour_code' => 'BLK',
                'unit_cost' => 250.00,
                'selling_price' => 599.00,
                'quantities' => ['S' => 5, 'M' => 10, 'L' => 12, 'XL' => 8, 'XXL' => 5]
            ],
            [
                'colour' => 'Bottle Green',
                'colour_code' => 'BGR',
                'unit_cost' => 250.00,
                'selling_price' => 599.00,
                'quantities' => ['S' => 5, 'M' => 8, 'L' => 10, 'XL' => 5, 'XXL' => 2]
            ],
        ]
    ];

    $res = create_inward_matrix_entry(
        $warehouseId,
        $matrixPayload,
        date('Y-m-d'),
        $testBillRef,
        'Test Matrix 100 pcs',
        $vendorId,
        $vendorName,
        $userId,
        $businessId
    );

    if (empty($res['success'])) {
        return "create_inward_matrix_entry failed: " . ($res['error'] ?? 'unknown');
    }
    if ((int)$res['total_qty'] !== 100) {
        return "Expected total_qty 100, got " . (int)$res['total_qty'];
    }

    // Verify variant stock for Maroon L is exactly 10
    $vStmt = $db->prepare("SELECT stock_quantity FROM product_variants WHERE sku = :sku AND business_id = :bid");
    $vStmt->execute(['sku' => "{$designCode}-MRN-L", 'bid' => $businessId]);
    $mStock = (int)$vStmt->fetchColumn();
    if ($mStock !== 10) {
        return "Expected Maroon L stock 10, got {$mStock}";
    }

    // Verify total product stock is 100
    $pStock = (int)$db->query("SELECT stock_quantity FROM products WHERE id = {$parentProdId}")->fetchColumn();
    if ($pStock !== 100) {
        return "Expected parent product stock 100, got {$pStock}";
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 4: Duplicate Supplier Document Reference Check
// -------------------------------------------------------------------------
run_test("4. Duplicate supplier reference is blocked to prevent double-stock", function() use ($businessId, $warehouseId, $vendorId, $vendorName, $userId, $parentProdId, $testBillRef) {
    // Attempting same supplier reference with same vendor
    $matrixPayload = [
        'product_id' => $parentProdId,
        'sizes' => ['S'],
        'rows' => [
            ['colour' => 'Maroon', 'colour_code' => 'MRN', 'unit_cost' => 250, 'selling_price' => 599, 'quantities' => ['S' => 5]]
        ]
    ];

    $res = create_inward_matrix_entry(
        $warehouseId,
        $matrixPayload,
        date('Y-m-d'),
        $testBillRef,
        'Duplicate attempt',
        $vendorId,
        $vendorName,
        $userId,
        $businessId
    );

    if (!empty($res['success'])) {
        return "Expected duplicate reference to be blocked, but it succeeded.";
    }
    if (stripos($res['error'] ?? '', 'already been') === false && stripos($res['error'] ?? '', 'duplicate') === false && stripos($res['error'] ?? '', 'prevented') === false) {
        return "Unexpected error message for duplicate: " . ($res['error'] ?? '');
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 5: WhatsApp OCR Query API
// -------------------------------------------------------------------------
run_test("5. WhatsApp OCR lookup for AC125-MRN returns parent details and available sizes with live stock", function() use ($designCode) {
    $data = call_ocr_api([
        'action' => 'query',
        'video_sku' => "{$designCode}-MRN",
    ]);

    if (empty($data['success'])) {
        return "API returned error: " . ($data['error'] ?? json_encode($data));
    }
    if ($data['video_sku'] !== "{$designCode}-MRN") {
        return "Expected video_sku {$designCode}-MRN, got " . ($data['video_sku'] ?? '');
    }
    if (count($data['available_sizes']) !== 5) {
        return "Expected 5 sizes, got " . count($data['available_sizes'] ?? []);
    }
    if (empty($data['whatsapp_bot_reply'])) {
        return "WhatsApp bot formatted reply missing.";
    }
    // Check Maroon L stock in sizes list
    $foundL = false;
    foreach ($data['available_sizes'] as $sz) {
        if ($sz['size'] === 'L') {
            $foundL = true;
            if ($sz['stock'] !== 10) {
                return "Expected size L stock 10, got {$sz['stock']}";
            }
        }
    }
    if (!$foundL) {
        return "Size L not found in API response.";
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 6: Size Selection Resolves to Exact Inventory SKU
// -------------------------------------------------------------------------
run_test("6. Size selection 'L' resolves to exact inventory SKU AC125-MRN-L", function() use ($designCode) {
    $data = call_ocr_api([
        'action' => 'resolve_sku',
        'video_sku' => "{$designCode}-MRN",
        'size' => 'L',
    ]);

    if (empty($data['success'])) {
        return "API resolve_sku failed: " . ($data['error'] ?? json_encode($data));
    }
    if ($data['sku'] !== "{$designCode}-MRN-L") {
        return "Expected resolved SKU '{$designCode}-MRN-L', got '{$data['sku']}'";
    }
    if ((int)$data['available_stock'] !== 10) {
        return "Expected available stock 10, got {$data['available_stock']}";
    }
    return true;
});

// -------------------------------------------------------------------------
// TEST 7: Prevent Order Exceeding Available Stock
// -------------------------------------------------------------------------
run_test("7. Attempting to reserve quantity > available stock returns error and prevents overselling", function() use ($designCode) {
    $data = call_ocr_api([
        'action' => 'order',
    ], [
        'action' => 'order',
        'sku' => "{$designCode}-MRN-L",
        'quantity' => 20, // Available is only 10
        'order_reference' => 'WA-TEST-OVERSELL',
    ]);

    if (!empty($data['success'])) {
        return "Order of 20 pieces succeeded when only 10 were available!";
    }
    if (empty($data['insufficient_stock'])) {
        return "Expected 'insufficient_stock' flag in error response. Got: " . json_encode($data);
    }
    return true;
});

// -------------------------------------------------------------------------
// TEST 8: Atomic Stock Reservation
// -------------------------------------------------------------------------
run_test("8. Atomic reservation of 3 pieces locks and decrements live available stock", function() use ($designCode, $db, $businessId) {
    $data = call_ocr_api([
        'action' => 'order',
    ], [
        'action' => 'order',
        'sku' => "{$designCode}-MRN-L",
        'quantity' => 3,
        'order_reference' => 'WA-ORDER-101',
    ]);

    if (empty($data['success'])) {
        return "Order reservation failed: " . ($data['error'] ?? json_encode($data));
    }
    if ((int)$data['stock_after'] !== 7) {
        return "Expected stock_after 7, got {$data['stock_after']}";
    }

    // Verify directly in DB
    $stmt = $db->prepare("SELECT stock_quantity FROM product_variants WHERE sku = :sku AND business_id = :bid");
    $stmt->execute(['sku' => "{$designCode}-MRN-L", 'bid' => $businessId]);
    $newStock = (int)$stmt->fetchColumn();
    if ($newStock !== 7) {
        return "DB stock_quantity expected 7, found {$newStock}";
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 9: Multi-Location Stock Breakdown
// -------------------------------------------------------------------------
run_test("9. Multi-location stock breakdown for AC125-MRN-L", function() use ($designCode, $warehouseName) {
    $data = call_ocr_api([
        'action' => 'location_stock',
        'sku' => "{$designCode}-MRN-L",
    ]);

    if (empty($data['success'])) {
        return "location_stock failed: " . ($data['error'] ?? json_encode($data));
    }
    if (!isset($data['locations']) || !is_array($data['locations'])) {
        return "Locations array missing in response.";
    }
    return true;
});

// -------------------------------------------------------------------------
// TEST 10: Order Cancellation & Release of Reserved Stock
// -------------------------------------------------------------------------
run_test("10. Order cancellation releases 3 pieces back to variant inventory", function() use ($designCode, $db, $businessId) {
    $data = call_ocr_api([
        'action' => 'cancel',
    ], [
        'action' => 'cancel',
        'sku' => "{$designCode}-MRN-L",
        'quantity' => 3,
        'order_reference' => 'WA-ORDER-101',
        'reason' => 'Customer changed mind',
    ]);

    if (empty($data['success'])) {
        return "Order cancellation failed: " . ($data['error'] ?? json_encode($data));
    }
    if ((int)$data['stock_after'] !== 10) {
        return "Expected stock_after 10, got {$data['stock_after']}";
    }

    // Verify in DB
    $stmt = $db->prepare("SELECT stock_quantity FROM product_variants WHERE sku = :sku AND business_id = :bid");
    $stmt->execute(['sku' => "{$designCode}-MRN-L", 'bid' => $businessId]);
    $restoredStock = (int)$stmt->fetchColumn();
    if ($restoredStock !== 10) {
        return "DB stock_quantity expected 10, found {$restoredStock}";
    }
    return true;
});

// -------------------------------------------------------------------------
// TEST 11: POS Stock Reduction Immediately Reflected in WhatsApp Query
// -------------------------------------------------------------------------
run_test("11. POS stock reduction immediately reflected in WhatsApp query", function() use ($designCode, $db, $businessId) {
    // Simulate POS sale of 4 pieces of Maroon L
    $db->prepare("UPDATE product_variants SET stock_quantity = stock_quantity - 4 WHERE sku = :sku AND business_id = :bid")
        ->execute(['sku' => "{$designCode}-MRN-L", 'bid' => $businessId]);

    // Query via OCR
    $data = call_ocr_api([
        'action' => 'query',
        'video_sku' => "{$designCode}-MRN",
    ]);

    if (empty($data['success'])) {
        return "OCR query failed: " . ($data['error'] ?? json_encode($data));
    }

    $lStock = null;
    foreach ($data['available_sizes'] as $sz) {
        if ($sz['size'] === 'L') {
            $lStock = $sz['stock'];
            break;
        }
    }
    if ($lStock !== 6) {
        return "Expected Maroon L stock to reflect 6, got " . var_export($lStock, true);
    }

    // Restore stock back to 10
    $db->prepare("UPDATE product_variants SET stock_quantity = stock_quantity + 4 WHERE sku = :sku AND business_id = :bid")
        ->execute(['sku' => "{$designCode}-MRN-L", 'bid' => $businessId]);

    return true;
});

// -------------------------------------------------------------------------
// TEST 12: Barcode Scan / Print Resolves Variant Correctly
// -------------------------------------------------------------------------
run_test("12. Barcode scan resolves to variant and parent product", function() use ($designCode, $db, $businessId) {
    $stmt = $db->prepare("SELECT barcode, sku FROM product_variants WHERE sku = :sku AND business_id = :bid");
    $stmt->execute(['sku' => "{$designCode}-MRN-L", 'bid' => $businessId]);
    $var = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$var || empty($var['barcode'])) {
        return "Variant barcode missing.";
    }

    $barcode = $var['barcode'];

    // In POS: scanning barcode searches for variant where barcode = code or sku = code
    $lookup = $db->prepare("
        SELECT pv.id, pv.sku, pv.variant_name, p.name AS product_name
        FROM product_variants pv
        JOIN products p ON p.id = pv.product_id
        WHERE (pv.barcode = :b1 OR pv.sku = :b2) AND pv.business_id = :bid
    ");
    $lookup->execute(['b1' => $barcode, 'b2' => $barcode, 'bid' => $businessId]);
    $scanned = $lookup->fetch(PDO::FETCH_ASSOC);

    if (!$scanned) {
        return "Failed to resolve variant by barcode '{$barcode}'";
    }
    if ($scanned['sku'] !== "{$designCode}-MRN-L") {
        return "Expected scanned SKU {$designCode}-MRN-L, got {$scanned['sku']}";
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 13: Bulk CSV Import with Duplicate Reference Check & Audit History
// -------------------------------------------------------------------------
run_test("13. Bulk CSV Import verifies controlled template & audit history", function() use ($businessId, $warehouseId, $vendorId, $vendorName, $designCode, $userId, $db) {
    $newDesignCode = 'CSV' . rand(100, 999) . '-' . strtoupper(substr(uniqid(), -4));
    $csvRef = 'CSV-INV-' . rand(1000, 9999);

    $csvContent = "Supplier,Receipt Date,Warehouse,Supplier Reference,Design Code,Colour,Unit Cost,S,M,L,XL,XXL\n" .
        "{$vendorName}," . date('Y-m-d') . ",Central Warehouse,{$csvRef},{$newDesignCode},Navy Blue,320,5,10,15,10,5\n" .
        "{$vendorName}," . date('Y-m-d') . ",Central Warehouse,{$csvRef},{$newDesignCode},Rust,320,4,8,12,8,4\n";

    $importRes = import_inward_matrix_csv(
        $csvContent,
        $warehouseId,
        $vendorId,
        date('Y-m-d'),
        '',
        $userId,
        $businessId
    );

    if (empty($importRes['success'])) {
        return "CSV Import failed: " . ($importRes['error'] ?? 'unknown');
    }

    // Total imported should be: (5+10+15+10+5) + (4+8+12+8+4) = 45 + 36 = 81
    if ((int)$importRes['total_qty'] !== 81) {
        return "Expected total_qty 81, got " . (int)$importRes['total_qty'];
    }

    // Verify duplicate ref blocking on CSV
    $dupRes = import_inward_matrix_csv(
        $csvContent,
        $warehouseId,
        $vendorId,
        date('Y-m-d'),
        '',
        $userId,
        $businessId
    );
    if (!empty($dupRes['success'])) {
        return "Expected duplicate CSV import with ref '{$csvRef}' to be blocked, but it succeeded.";
    }

    // Verify audit history in inventory_movements
    $movStmt = $db->prepare("
        SELECT COUNT(*) FROM inventory_movements im
        JOIN products p ON p.id = im.product_id
        WHERE p.sku = :sku AND im.business_id = :bid
    ");
    $movStmt->execute(['sku' => $newDesignCode, 'bid' => $businessId]);
    $movCount = (int)$movStmt->fetchColumn();
    if ($movCount <= 0) {
        return "No audit movements logged for imported design.";
    }

    // Clean up CSV test product
    $db->prepare("DELETE FROM products WHERE sku = :sku AND business_id = :bid")->execute(['sku' => $newDesignCode, 'bid' => $businessId]);

    return true;
});

// Clean up main test product
$db->prepare("DELETE FROM products WHERE sku = :sku AND business_id = :bid")->execute(['sku' => $designCode, 'bid' => $businessId]);

echo "\n=================================================================\n";
echo "   TEST SUMMARY: {$testPassed} PASSED, {$testFailed} FAILED\n";
echo "=================================================================\n";

if ($testFailed > 0) {
    exit(1);
}
echo "ALL ASH COLLECTIVE BRIEF ACCEPTANCE CRITERIA SATISFIED!\n";
exit(0);

<?php
/**
 * Comprehensive Suite Verification:
 * 1. "Show on Store" Feature (Creation default, Edit toggling, Storefront exclusion/inclusion, POS visibility)
 * 2. Stock Matrix Modal AJAX API & Variant Operations
 * 3. Exact Size L Stock Deduction (Store order + POS counter order)
 * 4. Parent Product Grand Total Stock Live Auto-Sync
 * 5. Quick Stock Adjustment Non-regression
 * 6. Barcode & SKU Lookup Non-regression
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/storefront_db.php';
require_once __DIR__ . '/../includes/orders_db.php';

echo "========================================================\n";
echo "   COMPREHENSIVE VERIFICATION & REGRESSION TEST SUITE   \n";
echo "========================================================\n\n";

$db = get_db();
$bizId = 1;
$userId = 1;

function run_check(string $title, callable $test) {
    try {
        $test();
        echo " [PASS] " . $title . "\n";
    } catch (Throwable $e) {
        echo " [FAIL] " . $title . "\n";
        echo "        Error: " . $e->getMessage() . "\n";
        echo "        Line: " . $e->getFile() . ":" . $e->getLine() . "\n";
        exit(1);
    }
}

// -------------------------------------------------------------
// PART 1: "SHOW ON STORE" TOGGLE TESTS
// -------------------------------------------------------------
echo "--- 1. Testing 'Show on Store' End-to-End ---\n";

$testProdId = null;

run_check("Creating a new product without show_on_store defaults to 1 (Show on Store)", function() use ($bizId, $userId, &$testProdId) {
    $sku = 'TEST-STORE-VIS-' . time();
    $data = [
        'name' => 'Visibility Test Product ' . time(),
        'sku' => $sku,
        'barcode' => 'VIS' . time(),
        'category_id' => null,
        'cost_price' => 250.0,
        'selling_price' => 599.0,
        'stock_quantity' => 20,
        'low_stock_threshold' => 3,
        'status' => 'active',
        'product_type' => 'simple'
        // 'show_on_store' omitted intentionally to test default behavior
    ];
    $res = save_product($data, null, null, $userId, $bizId);
    if (empty($res['success'])) {
        throw new Exception("Product creation failed: " . json_encode($res['errors'] ?? []));
    }
    $testProdId = (int)$res['product_id'];
    $saved = get_product_by_id($testProdId, $bizId);
    if ((int)($saved['show_on_store'] ?? 0) !== 1) {
        throw new Exception("Expected show_on_store = 1 by default, got: " . var_export($saved['show_on_store'], true));
    }
});

run_check("Product appears in Storefront queries (get_products with showOnStore=true)", function() use ($bizId, &$testProdId) {
    $storeProds = get_products('', null, 'active', '', $bizId, '', true);
    $found = false;
    foreach ($storeProds as $sp) {
        if ((int)$sp['id'] === $testProdId) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        throw new Exception("Product #{$testProdId} must appear in storefront catalog");
    }
});

run_check("Editing product to uncheck 'Show on Store' updates show_on_store to 0", function() use ($bizId, $userId, &$testProdId) {
    $saved = get_product_by_id($testProdId, $bizId);
    $saved['show_on_store'] = 0;
    $res = save_product($saved, null, $testProdId, $userId, $bizId);
    if (empty($res['success'])) {
        throw new Exception("Failed to update product: " . json_encode($res['errors'] ?? []));
    }
    $updated = get_product_by_id($testProdId, $bizId);
    if ((int)$updated['show_on_store'] !== 0) {
        throw new Exception("Expected show_on_store = 0, got: " . $updated['show_on_store']);
    }
});

run_check("Product is now hidden from Storefront catalog queries", function() use ($bizId, &$testProdId) {
    $storeProds = get_products('', null, 'active', '', $bizId, '', true);
    foreach ($storeProds as $sp) {
        if ((int)$sp['id'] === $testProdId) {
            throw new Exception("Product #{$testProdId} was found in storefront when show_on_store is 0!");
        }
    }
});

run_check("Product is still visible to POS Counter and In-Store Cashier", function() use ($bizId, &$testProdId) {
    // POS queries active products without filtering by showOnStore
    $posProds = get_products('', null, 'active', '', $bizId);
    $found = false;
    foreach ($posProds as $pp) {
        if ((int)$pp['id'] === $testProdId) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        throw new Exception("Product #{$testProdId} must still be accessible in POS counter");
    }
});

run_check("Re-enabling 'Show on Store' restores it on storefront", function() use ($bizId, $userId, &$testProdId) {
    $saved = get_product_by_id($testProdId, $bizId);
    $saved['show_on_store'] = 1;
    save_product($saved, null, $testProdId, $userId, $bizId);
    $updated = get_product_by_id($testProdId, $bizId);
    if ((int)$updated['show_on_store'] !== 1) {
        throw new Exception("Failed to re-enable show_on_store");
    }
    $storeProds = get_products('', null, 'active', '', $bizId, '', true);
    $found = false;
    foreach ($storeProds as $sp) {
        if ((int)$sp['id'] === $testProdId) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        throw new Exception("Product did not reappear in storefront");
    }
});

// -------------------------------------------------------------
// PART 2: STOCK MATRIX & ISOLATED SIZE DEDUCTION
// -------------------------------------------------------------
echo "\n--- 2. Testing Stock Matrix Creation & Size L Purchases ---\n";

$matrixProdId = null;
$varLId = null;

run_check("Creating garment product and setting up Size Matrix (S, M, L, XL, XXL)", function() use ($bizId, $userId, &$matrixProdId) {
    $sku = 'TEST-MAT-' . time();
    $data = [
        'name' => 'Premium Polo Tee ' . time(),
        'sku' => $sku,
        'barcode' => 'POLO' . time(),
        'category_id' => null,
        'cost_price' => 350.0,
        'selling_price' => 899.0,
        'stock_quantity' => 0,
        'low_stock_threshold' => 5,
        'status' => 'active',
        'product_type' => 'simple',
        'show_on_store' => 1
    ];
    $res = save_product($data, null, null, $userId, $bizId);
    $matrixProdId = (int)$res['product_id'];

    $payload = [
        'sizes' => ['S', 'M', 'L', 'XL', 'XXL'],
        'rows' => [
            [
                'colour' => 'Royal Blue',
                'colour_code' => '#1e40af',
                'quantities' => [
                    'S' => 10,
                    'M' => 20,
                    'L' => 30,
                    'XL' => 15,
                    'XXL' => 5
                ]
            ]
        ]
    ];
    $saveRes = save_product_stock_matrix($matrixProdId, $payload, $bizId, $userId);
    if (empty($saveRes['success'])) {
        throw new Exception("save_product_stock_matrix failed: " . ($saveRes['error'] ?? ''));
    }
    if ((int)$saveRes['total_stock'] !== 80) {
        throw new Exception("Expected 80 total stock (10+20+30+15+5), got: " . $saveRes['total_stock']);
    }
});

run_check("Matrix API returns sorted garment sizes & matching row/column totals", function() use ($bizId, &$matrixProdId, &$varLId) {
    $matrix = get_product_stock_matrix($matrixProdId, $bizId);
    if (empty($matrix['success'])) {
        throw new Exception("get_product_stock_matrix failed");
    }
    if ($matrix['sizes'] !== ['S', 'M', 'L', 'XL', 'XXL']) {
        throw new Exception("Sizes not sorted properly: " . json_encode($matrix['sizes']));
    }
    if ((int)$matrix['totals']['total_qty'] !== 80) {
        throw new Exception("Expected total_qty 80, got: " . $matrix['totals']['total_qty']);
    }
    if ((int)$matrix['totals']['by_size']['L'] !== 30) {
        throw new Exception("Expected Size L column total 30, got: " . $matrix['totals']['by_size']['L']);
    }

    // Find Variant L ID
    $vars = get_product_variants($matrixProdId, $bizId);
    foreach ($vars as $v) {
        $attrs = parse_variant_size_colour($v);
        if ($attrs['size'] === 'L') {
            $varLId = (int)$v['id'];
            break;
        }
    }
    if (!$varLId) {
        throw new Exception("Variant L not found in product variants");
    }
});

run_check("Parent product total stock is automatically 80 and type is variable", function() use ($bizId, &$matrixProdId) {
    $parent = get_product_by_id($matrixProdId, $bizId);
    if ((int)$parent['stock_quantity'] !== 80) {
        throw new Exception("Parent product stock is {$parent['stock_quantity']}, expected 80");
    }
    if ($parent['product_type'] !== 'variable') {
        throw new Exception("Parent product type must be variable, got: " . $parent['product_type']);
    }
});

run_check("Store order for 4 units of Size L: only Size L decreases (30 -> 26), parent stock syncs to 76", function() use ($bizId, $userId, &$matrixProdId, &$varLId, $db) {
    $orderRes = process_pos_order(
        [
            [
                'product_id' => $matrixProdId,
                'variant_id' => $varLId,
                'size' => 'L',
                'colour' => 'Royal Blue',
                'quantity' => 4,
                'price' => 899.00
            ]
        ],
        1, $userId, 0.0, 'fixed', 'cash', 'Online Store checkout for Size L', 3596.00, 1, null, null, null, 0, 0.0, null, $bizId, 'online_store', 'delivered'
    );
    if (empty($orderRes['success'])) {
        throw new Exception("Order failed: " . ($orderRes['error'] ?? ''));
    }

    // Check variant stocks
    $varLStock = (int)$db->query("SELECT stock_quantity FROM product_variants WHERE id = $varLId")->fetchColumn();
    if ($varLStock !== 26) {
        throw new Exception("Expected Size L stock to be 26 (30 - 4), got: " . $varLStock);
    }

    // Verify other variants are untouched
    $allVars = get_product_variants($matrixProdId, $bizId);
    foreach ($allVars as $v) {
        $attrs = parse_variant_size_colour($v);
        $sz = $attrs['size'];
        $qty = (int)$v['stock_quantity'];
        if ($sz === 'S' && $qty !== 10) throw new Exception("Size S changed to $qty, should be 10");
        if ($sz === 'M' && $qty !== 20) throw new Exception("Size M changed to $qty, should be 20");
        if ($sz === 'XL' && $qty !== 15) throw new Exception("Size XL changed to $qty, should be 15");
        if ($sz === 'XXL' && $qty !== 5) throw new Exception("Size XXL changed to $qty, should be 5");
    }

    // Verify parent product total stock
    $parent = get_product_by_id($matrixProdId, $bizId);
    if ((int)$parent['stock_quantity'] !== 76) {
        throw new Exception("Expected parent stock 76 (80 - 4), got: " . $parent['stock_quantity']);
    }
});

run_check("POS Counter checkout for 2 units of Size L: only Size L decreases (26 -> 24), parent stock syncs to 74", function() use ($bizId, $userId, &$matrixProdId, &$varLId, $db) {
    $orderRes = process_pos_order(
        [
            [
                'product_id' => $matrixProdId,
                'variant_id' => $varLId,
                'size' => 'L',
                'colour' => 'Royal Blue',
                'quantity' => 2,
                'price' => 899.00
            ]
        ],
        1, $userId, 0.0, 'fixed', 'cash', 'POS counter sale Size L', 1798.00, 1, null, null, null, 0, 0.0, null, $bizId, 'pos', 'delivered'
    );
    if (empty($orderRes['success'])) {
        throw new Exception("Order failed: " . ($orderRes['error'] ?? ''));
    }

    $varLStock = (int)$db->query("SELECT stock_quantity FROM product_variants WHERE id = $varLId")->fetchColumn();
    if ($varLStock !== 24) {
        throw new Exception("Expected Size L stock to be 24 (26 - 2), got: " . $varLStock);
    }

    $parent = get_product_by_id($matrixProdId, $bizId);
    if ((int)$parent['stock_quantity'] !== 74) {
        throw new Exception("Expected parent stock 74 (76 - 2), got: " . $parent['stock_quantity']);
    }
});

run_check("Stock Matrix Modal data reflects updated 24 pcs for L and 74 grand total", function() use ($bizId, &$matrixProdId) {
    $matrix = get_product_stock_matrix($matrixProdId, $bizId);
    if ((int)$matrix['totals']['total_qty'] !== 74) {
        throw new Exception("Expected matrix total 74, got: " . $matrix['totals']['total_qty']);
    }
    if ((int)$matrix['totals']['by_size']['L'] !== 24) {
        throw new Exception("Expected Size L column total 24, got: " . $matrix['totals']['by_size']['L']);
    }
    if ((int)$matrix['rows'][0]['quantities']['L'] !== 24) {
        throw new Exception("Expected Size L cell 24, got: " . $matrix['rows'][0]['quantities']['L']);
    }
});

// -------------------------------------------------------------
// PART 3: NON-REGRESSION OF EXISTING WORKING FUNCTIONALITY
// -------------------------------------------------------------
echo "\n--- 3. Testing Non-Regression of Existing Features ---\n";

run_check("Quick Stock Adjustment modal ('in', 'out', 'adjustment') works without disturbance", function() use ($bizId, $userId) {
    $sku = 'TEST-ADJ-' . time();
    $data = [
        'name' => 'Adjustment Test Product ' . time(),
        'sku' => $sku,
        'barcode' => 'ADJ' . time(),
        'category_id' => null,
        'cost_price' => 100.0,
        'selling_price' => 200.0,
        'initial_stock' => 15,
        'low_stock_threshold' => 2,
        'status' => 'active',
        'product_type' => 'simple',
        'show_on_store' => 1
    ];
    $res = save_product($data, null, null, $userId, $bizId);
    $adjPid = (int)$res['product_id'];

    // Adjust 'in' +10
    $adj1 = adjust_stock($adjPid, $userId, 'in', 10, 'Received extra stock');
    if (empty($adj1['success'])) {
        throw new Exception("Stock adjustment 'in' failed: " . json_encode($adj1['errors'] ?? []));
    }
    $p1 = get_product_by_id($adjPid, $bizId);
    if ((int)$p1['stock_quantity'] !== 25) {
        throw new Exception("Expected stock 25 (15 + 10), got: " . $p1['stock_quantity']);
    }

    // Adjust 'out' -5
    $adj2 = adjust_stock($adjPid, $userId, 'out', 5, 'Damaged during transit');
    if (empty($adj2['success'])) {
        throw new Exception("Stock adjustment 'out' failed: " . json_encode($adj2['errors'] ?? []));
    }
    $p2 = get_product_by_id($adjPid, $bizId);
    if ((int)$p2['stock_quantity'] !== 20) {
        throw new Exception("Expected stock 20 (25 - 5), got: " . $p2['stock_quantity']);
    }

    // Adjust 'adjustment' to physical count of 30
    $adj3 = adjust_stock($adjPid, $userId, 'adjustment', 30, 'Physical inventory recount');
    if (empty($adj3['success'])) {
        throw new Exception("Stock adjustment 'adjustment' failed: " . json_encode($adj3['errors'] ?? []));
    }
    $p3 = get_product_by_id($adjPid, $bizId);
    if ((int)$p3['stock_quantity'] !== 30) {
        throw new Exception("Expected stock 30 from recount, got: " . $p3['stock_quantity']);
    }
});

run_check("Product search by barcode, SKU, and text query remains functional", function() use ($bizId, &$testProdId) {
    $p = get_product_by_id($testProdId, $bizId);
    
    // Search by SKU
    $bySku = get_product_by_sku($p['sku'], $bizId);
    if (!$bySku || (int)$bySku['id'] !== $testProdId) {
        throw new Exception("Search by SKU failed");
    }

    // Search by Barcode
    $byBarcode = get_product_by_barcode($p['barcode'], $bizId);
    if (!$byBarcode || (int)$byBarcode['id'] !== $testProdId) {
        throw new Exception("Search by Barcode failed");
    }

    // Search via get_products text filter
    $byText = get_products($p['sku'], null, 'active', '', $bizId);
    if (empty($byText) || (int)$byText[0]['id'] !== $testProdId) {
        throw new Exception("Search via get_products text filter failed");
    }
});

run_check("Inward Batch Matrix & Barcode Release flow remains functional", function() {
    // Run inward entry smoke test script directly to ensure inward batch processing is intact
    $output = [];
    $returnCode = 0;
    exec("php " . escapeshellarg(__DIR__ . '/smoke_inward_entry.php'), $output, $returnCode);
    if ($returnCode !== 0) {
        throw new Exception("Inward smoke test failed: " . implode("\n", $output));
    }
});

echo "\n========================================================\n";
echo "   ALL TESTS PASSED! ZERO REGRESSIONS DETECTED.        \n";
echo "========================================================\n";

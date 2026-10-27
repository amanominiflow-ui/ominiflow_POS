<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/inward_db.php';
require_once dirname(__DIR__) . '/includes/products_db.php';

echo "=== TESTING MULTIPLE SIZES AND COLOURS IN INWARD ENTRY ===\n";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = get_db();
$userStmt = $db->query("SELECT * FROM users WHERE role IN ('owner', 'admin') LIMIT 1");
$adminUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$adminUser) {
    echo "FAILED: No admin user found.\n";
    exit(1);
}

$_SESSION['user_id'] = (int) $adminUser['id'];
$_SESSION['business_id'] = (int) ($adminUser['business_id'] ?? 1);
$currentBid = (int) $_SESSION['business_id'];

echo "[OK] Authenticated as user {$adminUser['id']} (Business: {$currentBid})\n";

// 1. Simulate quick_create_product with multiple sizes and colors
$uniqueCode = strtoupper(substr(uniqid(), -6));
$pName = "Polo T-Shirt " . $uniqueCode;
$pSku = "POLO-" . $uniqueCode;
$pCost = 250.00;
$pSelling = 599.00;
$testSizes = ['S', 'M', 'L', 'XL'];
$testColours = ['Black', 'Navy Blue'];

$variantAttrs = [
    ['name' => 'Size', 'options' => $testSizes],
    ['name' => 'Color', 'options' => $testColours],
];

$productData = [
    'name' => $pName,
    'sku' => $pSku,
    'barcode' => $pSku,
    'cost_price' => $pCost,
    'selling_price' => $pSelling,
    'product_type' => 'variable',
    'track_inventory' => 1,
    'initial_stock' => 0,
    'status' => 'active',
    'variant_attributes' => $variantAttrs,
];

$saveRes = save_product($productData, null, null, (int)$adminUser['id'], $currentBid);
if (empty($saveRes['success']) || empty($saveRes['product_id'])) {
    echo "FAILED: save_product failed: " . json_encode($saveRes) . "\n";
    exit(1);
}

$productId = (int) $saveRes['product_id'];
echo "[OK] Created variable product ID {$productId} with SKU {$pSku}\n";

// 2. Verify variants created in product_variants
$vStmt = $db->prepare("SELECT * FROM product_variants WHERE product_id = :pid AND business_id = :bid ORDER BY id ASC");
$vStmt->execute([':pid' => $productId, ':bid' => $currentBid]);
$variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

$expectedComboCount = count($testSizes) * count($testColours); // 4 x 2 = 8
if (count($variants) !== $expectedComboCount) {
    echo "FAILED: Expected {$expectedComboCount} variants, found " . count($variants) . "\n";
    exit(1);
}
echo "[OK] Exactly {$expectedComboCount} variants created in product_variants\n";

// Verify variant attributes and pricing inheritance
foreach ($variants as $v) {
    $attrs = json_decode((string)$v['attribute_values'], true);
    if (empty($attrs['Size']) || empty($attrs['Color'])) {
        echo "FAILED: Variant missing Size or Color in attribute_values: " . json_encode($v) . "\n";
        exit(1);
    }
    if ((float)$v['selling_price'] !== $pSelling || (float)$v['cost_price'] !== $pCost) {
        echo "FAILED: Variant did not inherit parent price/cost: " . json_encode($v) . "\n";
        exit(1);
    }
}
echo "[OK] All variants correctly populated with Size, Color, and inherited cost & selling price\n";

// 3. Verify inward_count_catalog returns this product with all 8 variants
$catalog = inward_count_catalog($currentBid);
$catalogProd = null;
foreach ($catalog as $cp) {
    if ((int)$cp['id'] === $productId) {
        $catalogProd = $cp;
        break;
    }
}

if (!$catalogProd) {
    echo "FAILED: Product {$productId} not found in inward_count_catalog.\n";
    exit(1);
}

if (count($catalogProd['variants']) !== $expectedComboCount) {
    echo "FAILED: Catalog product expected {$expectedComboCount} variants, got " . count($catalogProd['variants']) . "\n";
    exit(1);
}
echo "[OK] inward_count_catalog correctly returned all {$expectedComboCount} variants with sizes & colours\n";

// 4. Test inward count entry creation with all variants
$whStmt = $db->prepare("SELECT id FROM warehouses WHERE business_id = :bid AND status = 'active' LIMIT 1");
$whStmt->execute([':bid' => $currentBid]);
$whId = (int) $whStmt->fetchColumn();

if ($whId <= 0) {
    $db->prepare("INSERT INTO warehouses (business_id, name, code, is_default, status) VALUES (:bid, 'Main Test WH', 'MTW', 1, 'active')")
       ->execute([':bid' => $currentBid]);
    $whId = (int) $db->lastInsertId();
}

$inwardLines = [];
foreach ($catalogProd['variants'] as $v) {
    $inwardLines[] = [
        'product_id' => $productId,
        'size' => $v['size'],
        'colour' => $v['colour'],
        'quantity' => 5,
    ];
}

$createRes = create_inward_entry($whId, $inwardLines, date('Y-m-d'), 'Test multi-variant count', null, (int)$adminUser['id'], $currentBid);
if (empty($createRes['success']) || empty($createRes['entry_id'])) {
    echo "FAILED: create_inward_entry failed: " . json_encode($createRes) . "\n";
    exit(1);
}

$entryId = (int) $createRes['entry_id'];
echo "[OK] Created inward entry #{$createRes['entry_number']} with {$expectedComboCount} lines\n";

// Verify lines in inward_lines
$linesStmt = $db->prepare("SELECT * FROM inward_lines WHERE inward_entry_id = :eid");
$linesStmt->execute([':eid' => $entryId]);
$savedLines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($savedLines) !== $expectedComboCount) {
    echo "FAILED: Expected {$expectedComboCount} lines in inward_lines, got " . count($savedLines) . "\n";
    exit(1);
}
echo "[OK] All {$expectedComboCount} variant lines successfully saved with size, colour, and quantity\n";

// 5. Test HTML rendering in inward-entry.php for UI components
ob_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/inward-entry.php';
$html = ob_get_clean();

$checks = [
    'qp_quick_sizes' => 'Quick size presets container',
    'qp_quick_colours' => 'Quick colour presets container',
    'qp_selected_sizes' => 'Selected sizes container',
    'qp_selected_colours' => 'Selected colours container',
    'qp_auto_inward_rows' => 'Auto inward rows checkbox',
    'inw-fill-variants-btn' => 'Add all sizes & colours button logic',
    'splitSizes' => 'Comma-separated size expansion logic',
];

foreach ($checks as $needle => $label) {
    if (strpos($html, $needle) === false) {
        echo "FAILED: Markup or JS logic for '{$needle}' ({$label}) missing from inward-entry.php\n";
        exit(1);
    }
    echo "[OK] Found {$label} in inward-entry.php\n";
}

// Clean up test inward entry and product
$db->prepare("DELETE FROM inward_lines WHERE inward_entry_id = :eid")->execute([':eid' => $entryId]);
$db->prepare("DELETE FROM inward_entries WHERE id = :eid")->execute([':eid' => $entryId]);
$db->prepare("DELETE FROM product_variants WHERE product_id = :pid")->execute([':pid' => $productId]);
$db->prepare("DELETE FROM product_attributes WHERE product_id = :pid")->execute([':pid' => $productId]);
$db->prepare("DELETE FROM products WHERE id = :pid")->execute([':pid' => $productId]);

echo "[OK] Cleaned up test data.\n";
echo "\n>>> ALL MULTIPLE SIZES AND COLOURS TESTS PASSED PERFECTLY! <<<\n";

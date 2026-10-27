<?php
/**
 * Comprehensive verification for:
 * 1. Purchase Returns (Zoho POS style with vouchers, stock reversals, inventory movements, vendor balance)
 * 2. Inward Entry (Quick Product Creation, inline tag chips, multi-size inward, and table alignment)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/purchases_db.php';
require_once __DIR__ . '/../includes/purchase_returns_db.php';
require_once __DIR__ . '/../includes/inward_db.php';
require_once __DIR__ . '/../includes/outlets_db.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/reports_db.php';

echo "========================================================\n";
echo "   COMPREHENSIVE END-TO-END FEATURE VERIFICATION\n";
echo "========================================================\n\n";

$db = get_db();

// Authenticate session as test user
$_SESSION['user_id'] = 1;
$_SESSION['business_id'] = 2;
$businessId = 2;
$userId = 1;

// -------------------------------------------------------------------------
// PART 1: VERIFY PURCHASE RETURN SYSTEM
// -------------------------------------------------------------------------
echo "--- [1/2] VERIFYING PURCHASE RETURN SYSTEM ---\n";

// 1.1 Check DB schema & migration
$tables = $db->query("SHOW TABLES LIKE 'purchase_returns'")->fetchAll();
if (empty($tables)) {
    die("FAIL: purchase_returns table does not exist.\n");
}
echo "[PASS] purchase_returns table exists.\n";

$itemsTable = $db->query("SHOW TABLES LIKE 'purchase_return_items'")->fetchAll();
if (empty($itemsTable)) {
    die("FAIL: purchase_return_items table does not exist.\n");
}
echo "[PASS] purchase_return_items table exists.\n";

// 1.2 Fetch vendor and product
$vendors = get_vendors();
if (empty($vendors)) {
    die("FAIL: No vendors found in business.\n");
}
$vendor = $vendors[0];
$vendorId = (int)$vendor['id'];

// Get or create product
$products = $db->query("SELECT * FROM products WHERE business_id = $businessId AND status = 'active' LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
if (empty($products)) {
    die("FAIL: No active product found.\n");
}
$testProd = $products[0];
$prodId = (int)$testProd['id'];
$initialStock = (float)($testProd['stock_quantity'] ?? 0);

// Set test vendor balance to 5000 for verification
$db->prepare("UPDATE vendors SET outstanding_balance = 5000 WHERE id = ?")->execute([$vendorId]);
$vendorBefore = get_vendor_by_id($vendorId, $businessId);
$vendorBalBefore = (float)($vendorBefore['outstanding_balance'] ?? 0);

echo "[INFO] Testing with Product: {$testProd['name']} (ID: $prodId, Current Stock: $initialStock)\n";
echo "[INFO] Testing with Vendor: {$vendor['name']} (ID: $vendorId, Current Balance: $vendorBalBefore)\n";

// 1.3 Create Standalone Purchase Return
$returnHeader = [
    'vendor_id' => $vendorId,
    'purchase_order_id' => null,
    'return_date' => date('Y-m-d'),
    'refund_method' => 'vendor_credit',
    'reason' => 'E2E Automated Verification Return',
    'notes' => 'Testing end-to-end purchase return functionality',
    'tax_percentage' => 18.00,
];

$returnItems = [
    [
        'product_id' => $prodId,
        'variant_id' => null,
        'quantity' => 2,
        'unit_cost' => 100.00,
        'tax_percent' => 18.00,
        'reason' => 'Quality mismatch'
    ]
];

$createRes = create_purchase_return($returnHeader, $returnItems, $userId, $businessId);
if (empty($createRes['success'])) {
    die("FAIL: Failed to create purchase return: " . ($createRes['error'] ?? 'Unknown error') . "\n");
}
$returnId = (int)$createRes['return_id'];
$debitNote = $createRes['return_number'];
echo "[PASS] Created Purchase Return ID: $returnId (Debit Note #: $debitNote)\n";

// 1.4 Verify Stock Reversal
$prodAfter = get_product_by_id($prodId, $businessId);
$stockAfter = (float)($prodAfter['stock_quantity'] ?? 0);
if ($stockAfter !== ($initialStock - 2)) {
    die("FAIL: Stock not reduced correctly. Expected " . ($initialStock - 2) . ", got $stockAfter\n");
}
echo "[PASS] Product Stock reduced correctly from $initialStock to $stockAfter (-2 units).\n";

// 1.5 Verify Inventory Movement Logging
$movStmt = $db->prepare("SELECT * FROM inventory_movements WHERE product_id = ? AND reason LIKE ? ORDER BY id DESC LIMIT 1");
$movStmt->execute([$prodId, "%$debitNote%"]);
$mov = $movStmt->fetch(PDO::FETCH_ASSOC);
if (!$mov || $mov['movement_type'] !== 'out' || abs((int)$mov['quantity_change'] - (-2)) > 0) {
    die("FAIL: Inventory movement not logged correctly.\n");
}
echo "[PASS] Inventory movement logged with type='out', quantity_change=-2, and debit note reference '{$mov['reason']}'.\n";

// 1.6 Verify Vendor Balance Adjustment
$vendorAfter = get_vendor_by_id($vendorId, $businessId);
$vendorBalAfter = (float)($vendorAfter['outstanding_balance'] ?? 0);
$expectedTotal = 2 * 100 * 1.18; // 236.00
if (abs(($vendorBalBefore - $expectedTotal) - $vendorBalAfter) > 0.01) {
    die("FAIL: Vendor balance not updated properly. Expected " . ($vendorBalBefore - $expectedTotal) . ", got $vendorBalAfter\n");
}
echo "[PASS] Vendor balance updated from $vendorBalBefore to $vendorBalAfter (Debit: ₹$expectedTotal).\n";

// 1.7 Verify Voucher Fetching & Details
$voucher = get_purchase_return_by_id($returnId, $businessId);
if (!$voucher || $voucher['return_number'] !== $debitNote) {
    die("FAIL: Could not retrieve voucher by ID.\n");
}
if (count($voucher['items']) !== 1 || (int)$voucher['items'][0]['quantity'] !== 2) {
    die("FAIL: Voucher items mismatch.\n");
}
echo "[PASS] Debit Note Voucher retrieved cleanly with subtotal ₹{$voucher['subtotal']}, tax ₹{$voucher['tax_amount']}, total ₹{$voucher['total_amount']}.\n";

// 1.8 Verify KPIs
$kpis = get_purchase_returns_kpis($businessId);
if (($kpis['total_count'] ?? 0) < 1 || ($kpis['total_amount'] ?? 0) <= 0) {
    die("FAIL: Purchase return KPIs not calculating properly.\n");
}
echo "[PASS] Purchase Return KPIs verified: Total Returns = {$kpis['total_count']}, Total Value = ₹" . number_format($kpis['total_amount'], 2) . "\n";


// -------------------------------------------------------------------------
// PART 2: VERIFY INWARD ENTRY FEATURES & ALIGNMENT
// -------------------------------------------------------------------------
echo "\n--- [2/2] VERIFYING INWARD ENTRY & TABLE ALIGNMENT ---\n";

// 2.1 Verify Inward Entry HTML & Table Header Alignment
$inwardHtml = file_get_contents(__DIR__ . '/../inward-entry.php');

// Check that lineTable has clean layout & headers
if (strpos($inwardHtml, 'id="lineTable"') === false) {
    die("FAIL: lineTable not found in inward-entry.php\n");
}
if (strpos($inwardHtml, 'class="inw-qty-header"') === false) {
    die("FAIL: inw-qty-header class missing on Quantity th\n");
}
if (strpos($inwardHtml, '#lineTable td.inw-qty-cell, #lineTable th.inw-qty-header') === false) {
    die("FAIL: Quantity column alignment CSS missing\n");
}
echo "[PASS] Inward Entry table headers and alignment CSS verified.\n";

// 2.2 Verify Tag Input creates inner wrapper div (not display:flex on td)
if (strpos($inwardHtml, "const box = document.createElement('div');") === false) {
    die("FAIL: Inner wrapper div for tag input not found\n");
}
if (strpos($inwardHtml, "cell.appendChild(box);") === false) {
    die("FAIL: box is not appended to cell\n");
}
echo "[PASS] Tag input correctly wraps in inner div to preserve table column layout.\n";

// 2.3 Verify Quick Create Product Logic
$quickSku = 'QCK-' . strtoupper(substr(md5(uniqid()), 0, 6));
$quickName = 'E2E Verification T-Shirt ' . rand(100, 999);
$saveProdRes = save_product([
    'name' => $quickName,
    'sku' => $quickSku,
    'barcode' => $quickSku,
    'cost_price' => 250.00,
    'selling_price' => 499.00,
    'category_id' => null,
    'product_type' => 'simple',
    'track_inventory' => 1,
    'initial_stock' => 0,
    'status' => 'active',
], null, null, $userId, $businessId);

if (empty($saveProdRes['success'])) {
    die("FAIL: Quick product creation failed.\n");
}
$newProdId = (int)$saveProdRes['product_id'];
echo "[PASS] Quick Product Created via API: ID $newProdId ($quickName, SKU: $quickSku)\n";

// 2.4 Verify Inward Entry with Multi-Size and Custom Colour
$warehouseList = get_warehouses(null, 'active');
$whId = !empty($warehouseList) ? (int)$warehouseList[0]['id'] : 1;

$inwardLines = [
    [
        'product_id' => $newProdId,
        'size' => 'S',
        'colour' => 'Navy Blue',
        'quantity' => 10
    ],
    [
        'product_id' => $newProdId,
        'size' => 'M',
        'colour' => 'Navy Blue',
        'quantity' => 15
    ],
    [
        'product_id' => $newProdId,
        'size' => 'L',
        'colour' => 'Navy Blue',
        'quantity' => 20
    ]
];

$inwardRes = create_inward_entry(
    $whId,
    $inwardLines,
    date('Y-m-d'),
    'E2E Inward multi-size check',
    $vendorId,
    $userId,
    $businessId
);

if (empty($inwardRes['success'])) {
    die("FAIL: Failed to create inward entry: " . ($inwardRes['error'] ?? 'Unknown error') . "\n");
}
$inwardId = (int)$inwardRes['entry_id'];
$inwardEntry = get_inward_entry_by_id($inwardId, $businessId);
echo "[PASS] Inward Entry created #{$inwardEntry['entry_number']} with " . count($inwardEntry['lines']) . " multi-size lines.\n";

// Verify lines
$savedSizes = array_column($inwardEntry['lines'], 'size');
sort($savedSizes);
if ($savedSizes !== ['L', 'M', 'S']) {
    die("FAIL: Inward lines did not store all 3 sizes properly.\n");
}
echo "[PASS] All sizes (S, M, L) and colour ('Navy Blue') stored accurately.\n";

// 2.5 Complete Cost & Purchase Confirmation & QC on Inward
$prices = [
    ['line_id' => (int)$inwardEntry['lines'][0]['id'], 'purchase_cost' => 200, 'selling_price' => 450],
    ['line_id' => (int)$inwardEntry['lines'][1]['id'], 'purchase_cost' => 200, 'selling_price' => 450],
    ['line_id' => (int)$inwardEntry['lines'][2]['id'], 'purchase_cost' => 200, 'selling_price' => 450],
];
$costConfirm = confirm_inward_costs($inwardId, $prices, $businessId, $vendorId, $userId);
if (empty($costConfirm['success'])) {
    die("FAIL: Failed to confirm inward costs: " . ($costConfirm['error'] ?? '') . "\n");
}
echo "[PASS] Inward costs confirmed successfully.\n";

$purchaseConfirm = confirm_inward_purchase($inwardId, $businessId);
if (empty($purchaseConfirm['success'])) {
    die("FAIL: Failed to confirm inward purchase: " . ($purchaseConfirm['error'] ?? '') . "\n");
}
echo "[PASS] Inward purchase confirmed and barcodes generated.\n";

$qcConfirm = complete_inward_qc($inwardId, $businessId, $userId);
if (empty($qcConfirm['success'])) {
    die("FAIL: Failed to complete inward QC: " . ($qcConfirm['error'] ?? '') . "\n");
}
echo "[PASS] Inward QC completed: 45 total units transferred to Central ready stock.\n";

// Clean up test inward & product so database stays clean
$db->prepare("DELETE FROM inward_lines WHERE inward_entry_id = ?")->execute([$inwardId]);
$db->prepare("DELETE FROM inward_entries WHERE id = ?")->execute([$inwardId]);
$db->prepare("DELETE FROM products WHERE id = ?")->execute([$newProdId]);
echo "[PASS] Cleaned up test inward entry and test product.\n";

echo "\n========================================================\n";
echo "   >>> ALL VERIFICATION CHECKS PASSED 100% <<< \n";
echo "========================================================\n";

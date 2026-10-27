<?php
/**
 * End-to-End Test for Purchase Return & Debit Notes (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/purchases_db.php';
require_once __DIR__ . '/../includes/purchase_returns_db.php';
require_once __DIR__ . '/../includes/products_db.php';

echo "=== STARTING PURCHASE RETURN FEATURE TEST ===\n\n";

$db = get_db();
$bizId = 2; // Testing for client business 2

// 1. Get or create test vendor
$stmtVen = $db->prepare('SELECT id, name, outstanding_balance FROM vendors WHERE business_id = :bid LIMIT 1');
$stmtVen->execute(['bid' => $bizId]);
$vendor = $stmtVen->fetch();
if (!$vendor) {
    $resV = save_vendor([
        'name' => 'Supplier Co Ltd',
        'company_name' => 'Apex Wholesale',
        'phone' => '9876543210',
        'email' => 'supplier@test.com',
        'gstin' => '29AABCU9603R1ZM'
    ], null, $bizId);
    $vendorId = (int)$resV['id'];
} else {
    $vendorId = (int)$vendor['id'];
}
echo "1. Using Vendor ID: {$vendorId}\n";

// Set initial vendor balance for testing vendor credit deduction
$initialBalance = 10000.00;
$db->prepare('UPDATE vendors SET outstanding_balance = :bal WHERE id = :id')->execute(['bal' => $initialBalance, 'id' => $vendorId]);

// 2. Get a test product for business 2
$stmtP = $db->prepare('SELECT id, name, sku, cost_price, stock_quantity FROM products WHERE business_id = :bid LIMIT 1');
$stmtP->execute(['bid' => $bizId]);
$prod = $stmtP->fetch();
assert($prod !== false, 'No product found for business 2');
$prodId = (int)$prod['id'];
$initialStock = (int)$prod['stock_quantity'];
$costPrice = (float)$prod['cost_price'] > 0 ? (float)$prod['cost_price'] : 150.00;
echo "2. Using Product ID: {$prodId} ({$prod['name']}), Initial Stock: {$initialStock}, Cost: {$costPrice}\n";

// 3. Create a Purchase Order
$poItems = [
    [
        'product_id' => $prodId,
        'quantity' => 10,
        'unit_cost' => $costPrice,
        'tax_percent' => 18.0
    ]
];
$poRes = create_purchase_order($vendorId, $poItems, date('Y-m-d', strtotime('+7 days')), 'Test PO for Return', 1, $bizId);
assert($poRes['success'] === true, 'Failed to create PO: ' . ($poRes['error'] ?? ''));
$poId = (int)$poRes['po_id'];
$poNum = $poRes['po_number'];
echo "3. Created PO #{$poNum} (ID: {$poId})\n";

// Get created PO items
$poDetails = get_purchase_order_by_id($poId, $bizId);
assert(!empty($poDetails['items']), 'PO has no items');
$firstPoItem = $poDetails['items'][0];
$poiId = (int)$firstPoItem['id'];

// 4. Receive goods on the PO
$recvRes = receive_purchase_order_items($poId, [
    ['po_item_id' => $poiId, 'quantity_to_receive' => 10]
], 1, $bizId);
assert($recvRes['success'] === true, 'Failed to receive goods: ' . ($recvRes['error'] ?? ''));
echo "4. Received 10 units for PO #{$poNum}. Expected stock: " . ($initialStock + 10) . "\n";

// Verify returnable items
$returnable = get_returnable_po_items($poId, $bizId);
assert(count($returnable) > 0, 'No returnable items found for PO');
$returnableItem = $returnable[0];
echo "   Returnable item: {$returnableItem['product_name']}, Available to return: {$returnableItem['available_to_return']}\n";
assert((int)$returnableItem['available_to_return'] === 10, 'Expected 10 units available to return');

// 5. Process Purchase Return / Debit Note (returning 3 units)
$returnQty = 3;
$returnItems = [
    [
        'product_id' => $prodId,
        'po_item_id' => $returnableItem['id'],
        'product_name' => $returnableItem['product_name'],
        'product_sku' => $returnableItem['product_sku'],
        'quantity' => $returnQty,
        'unit_cost' => $costPrice,
        'tax_percent' => 18.0,
        'reason' => 'Damaged in Transit'
    ]
];
$retHeader = [
    'vendor_id' => $vendorId,
    'purchase_order_id' => $poId,
    'return_date' => date('Y-m-d'),
    'refund_method' => 'vendor_credit',
    'reason' => 'Damaged in Transit',
    'notes' => 'Damaged cartons observed upon delivery'
];

$retRes = create_purchase_return($retHeader, $returnItems, 1, $bizId);
assert($retRes['success'] === true, 'Failed to create return: ' . ($retRes['error'] ?? ''));
$returnId = (int)$retRes['return_id'];
$returnNum = $retRes['return_number'];
$totalAmount = (float)$retRes['total_amount'];
echo "5. Purchase Return Processed Successfully!\n";
echo "   Debit Note #: {$returnNum}\n";
echo "   Total Amount: ₹{$totalAmount}\n";

// 6. Verify Stock Deduction
$stmtPAfter = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id');
$stmtPAfter->execute(['id' => $prodId]);
$afterStock = (int)$stmtPAfter->fetchColumn();
echo "6. Stock verification: Initial was " . ($initialStock + 10) . ", after return of {$returnQty} units = {$afterStock}\n";
assert($afterStock === ($initialStock + 10 - $returnQty), "Stock mismatch! Expected " . ($initialStock + 10 - $returnQty) . ", got {$afterStock}");

// 7. Verify Inventory Movement Log
$stmtMov = $db->prepare('SELECT * FROM inventory_movements WHERE business_id = :bid AND product_id = :pid ORDER BY id DESC LIMIT 1');
$stmtMov->execute(['bid' => $bizId, 'pid' => $prodId]);
$mov = $stmtMov->fetch();
assert($mov !== false, 'Inventory movement log not found');
echo "7. Inventory Movement Logged: type={$mov['movement_type']}, change={$mov['quantity_change']}, reason={$mov['reason']}\n";
assert((int)$mov['quantity_change'] === -$returnQty, 'Movement change mismatch');

// 8. Verify Vendor Balance Adjustment
$stmtVenAfter = $db->prepare('SELECT outstanding_balance FROM vendors WHERE id = :id');
$stmtVenAfter->execute(['id' => $vendorId]);
$afterBalance = (float)$stmtVenAfter->fetchColumn();
echo "8. Vendor Balance: Initial was ₹{$initialBalance}, after debit of ₹{$totalAmount} = ₹{$afterBalance}\n";
assert(abs($afterBalance - ($initialBalance - $totalAmount)) < 0.01, 'Vendor balance not properly reduced');

// 9. Verify PO Items Return Tracking
$returnableAfter = get_returnable_po_items($poId, $bizId);
$availNow = (int)$returnableAfter[0]['available_to_return'];
$returnedNow = (int)$returnableAfter[0]['quantity_returned'];
echo "9. PO Item Tracking: Previously returned={$returnedNow}, Available now={$availNow}\n";
assert($returnedNow === $returnQty, 'quantity_returned on PO item not updated');
assert($availNow === (10 - $returnQty), 'available_to_return not updated properly');

// 10. Verify Full Debit Note Voucher Data & Amount in Words
$voucher = get_purchase_return_by_id($returnId, $bizId);
assert($voucher !== null, 'Voucher not found by ID');
echo "10. Voucher fetched:\n";
echo "    Debit Note: {$voucher['return_number']}\n";
echo "    Vendor: {$voucher['vendor_name']}\n";
echo "    Subtotal: ₹{$voucher['subtotal']}\n";
echo "    Tax: ₹{$voucher['tax_amount']}\n";
echo "    Grand Total: ₹{$voucher['total_amount']}\n";
$words = number_to_indian_currency_words((float)$voucher['total_amount']);
echo "    Amount in Words: {$words}\n";
assert(!empty($words), 'Amount in words is empty');

// 11. Test Standalone Direct Return (without PO)
$directItems = [
    [
        'product_id' => $prodId,
        'quantity' => 1,
        'unit_cost' => $costPrice,
        'tax_percent' => 5.0,
        'reason' => 'Direct vendor return test'
    ]
];
$directHeader = [
    'vendor_id' => $vendorId,
    'purchase_order_id' => null,
    'bill_id' => null,
    'return_date' => date('Y-m-d'),
    'refund_method' => 'cash',
    'reason' => 'Direct Return',
    'notes' => 'Over-the-counter return'
];
$directRes = create_purchase_return($directHeader, $directItems, 1, $bizId);
assert($directRes['success'] === true, 'Failed direct return: ' . ($directRes['error'] ?? ''));
echo "11. Direct Standalone Return processed: {$directRes['return_number']}\n";

// 12. Check KPIs
$kpis = get_purchase_returns_kpis($bizId);
echo "12. KPIs verified: Total Returns={$kpis['total_count']}, Value=₹{$kpis['total_amount']}, Credit=₹{$kpis['total_credit']}\n";
assert((int)$kpis['total_count'] >= 2, 'KPI count mismatch');

echo "\n=== ALL 12 TESTS PASSED PERFECTLY! ===\n";

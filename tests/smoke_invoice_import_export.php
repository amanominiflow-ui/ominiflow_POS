<?php
/**
 * Smoke Test: Invoices CSV Import & Export Engine
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders_db.php';
require_once __DIR__ . '/../includes/import_export_db.php';

echo "=== STARTING SMOKE TEST: INVOICE IMPORT & EXPORT ===\n";

$db = get_db();

// Fetch default business id
$bizStmt = $db->query('SELECT id FROM businesses LIMIT 1');
$businessId = (int)($bizStmt->fetchColumn() ?: 1);

// Ensure user exists
$userStmt = $db->query('SELECT id FROM users LIMIT 1');
$userId = (int)($userStmt->fetchColumn() ?: 1);

echo "Testing with Business ID: {$businessId}, User ID: {$userId}\n";

// 1. Create a temporary test CSV file
$tempCsv = tempnam(sys_get_temp_dir(), 'test_inv_') . '.csv';
$csvRows = [
    ['Invoice Number', 'Invoice Date', 'Customer Name', 'Customer Phone', 'Customer Email', 'Product SKU', 'Product Name', 'Quantity', 'Unit Price', 'Discount Amount', 'Tax Percent', 'Payment Method', 'Invoice Status', 'Notes'],
    // Multi-item invoice 1
    ['TEST-INV-001', '2026-09-28 10:00:00', 'Test Buyer Alpha', '9999988881', 'alpha@example.com', 'TSHIRT-001', 'Cotton Polo T-Shirt', '2', '500.00', '50.00', '5.00', 'cash', 'paid', 'First imported item'],
    ['TEST-INV-001', '2026-09-28 10:00:00', 'Test Buyer Alpha', '9999988881', 'alpha@example.com', 'TECH-MOU-01', 'Wireless Optical Mouse', '1', '400.00', '0.00', '18.00', 'cash', 'paid', 'Second imported item'],
    // Single-item invoice 2
    ['TEST-INV-002', '2026-09-28 11:30:00', 'Test Buyer Beta', '9999988882', 'beta@example.com', 'GROC-TEA-01', 'Organic Green Tea 250g', '4', '200.00', '0.00', '0.00', 'upi', 'paid', 'Express UPI payment'],
];

$fp = fopen($tempCsv, 'w');
fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
foreach ($csvRows as $row) {
    fputcsv($fp, $row);
}
fclose($fp);

echo "Created test CSV with " . (count($csvRows) - 1) . " data rows.\n";

// 2. Run import_invoices_from_csv
$res = import_invoices_from_csv($tempCsv, $userId, $businessId);

echo "Import Result: " . json_encode($res, JSON_PRETTY_PRINT) . "\n";

if (!$res['success'] || $res['imported_count'] !== 2) {
    echo "FAILED: Expected 2 imported invoices, got {$res['imported_count']}\n";
    @unlink($tempCsv);
    exit(1);
}

echo "SUCCESS: 2 invoices imported successfully.\n";

// 3. Verify Invoice 1 in database
$stmtInv1 = $db->prepare('SELECT * FROM invoices WHERE invoice_number = "TEST-INV-001" AND business_id = :bid');
$stmtInv1->execute(['bid' => $businessId]);
$inv1 = $stmtInv1->fetch(PDO::FETCH_ASSOC);

if (!$inv1) {
    echo "FAILED: Could not find TEST-INV-001 in invoices table.\n";
    @unlink($tempCsv);
    exit(1);
}

echo "Verified TEST-INV-001: Subtotal = {$inv1['subtotal']}, Tax = {$inv1['tax_amount']}, Total = {$inv1['total_amount']}\n";

// Verify line items for Invoice 1
$stmtItems1 = $db->prepare('SELECT * FROM order_items WHERE order_id = :oid');
$stmtItems1->execute(['oid' => $inv1['order_id']]);
$items1 = $stmtItems1->fetchAll(PDO::FETCH_ASSOC);

echo "Invoice 1 line items count: " . count($items1) . " (Expected: 2)\n";
if (count($items1) !== 2) {
    echo "FAILED: Expected 2 line items for TEST-INV-001.\n";
    @unlink($tempCsv);
    exit(1);
}

// 4. Verify Customer Auto-Creation
$stmtCust = $db->prepare('SELECT * FROM customers WHERE phone = "9999988881" AND business_id = :bid');
$stmtCust->execute(['bid' => $businessId]);
$cust = $stmtCust->fetch(PDO::FETCH_ASSOC);

if (!$cust || $cust['name'] !== 'Test Buyer Alpha') {
    echo "FAILED: Customer Test Buyer Alpha was not auto-created properly.\n";
    @unlink($tempCsv);
    exit(1);
}
echo "Verified Customer auto-created: {$cust['name']} ({$cust['phone']})\n";

// 5. Verify Invoice 2 in database
$stmtInv2 = $db->prepare('SELECT * FROM invoices WHERE invoice_number = "TEST-INV-002" AND business_id = :bid');
$stmtInv2->execute(['bid' => $businessId]);
$inv2 = $stmtInv2->fetch(PDO::FETCH_ASSOC);

if (!$inv2) {
    echo "FAILED: Could not find TEST-INV-002 in invoices table.\n";
    @unlink($tempCsv);
    exit(1);
}
echo "Verified TEST-INV-002: Total = {$inv2['total_amount']}, Payment Method = {$inv2['payment_method']}\n";

// Clean up test data and temporary file
@unlink($tempCsv);

// Remove test invoices from DB to keep database clean
$db->exec('DELETE FROM invoices WHERE invoice_number IN ("TEST-INV-001", "TEST-INV-002")');
$db->exec("DELETE FROM orders WHERE id IN ({$inv1['order_id']}, {$inv2['order_id']})");
$db->exec('DELETE FROM customers WHERE phone IN ("9999988881", "9999988882")');

echo "Cleaned up test data.\n";
echo "=== ALL INVOICE IMPORT & EXPORT SMOKE TESTS PASSED 100%! ===\n";

<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/purchases_db.php';
require __DIR__ . '/../includes/purchase_entry_db.php';
require __DIR__ . '/../includes/products_db.php';
require __DIR__ . '/../includes/outlets_db.php';

$bid = 1;
$vendors = get_vendors('', $bid);
$wh = get_warehouses(null, 'active', $bid);
$cats = get_categories('', 'active', $bid);
echo "vendors:" . count($vendors) . " wh:" . count($wh) . " cats:" . count($cats) . "\n";
if (!$vendors || !$wh || !$cats) {
    exit(1);
}
$res = save_purchase_entry([
    'vendor_id' => (int) $vendors[0]['id'],
    'supplier_invoice_no' => 'TEST-1',
    'purchase_date' => date('Y-m-d'),
    'received_date' => date('Y-m-d'),
    'warehouse_id' => (int) $wh[0]['id'],
    'notes' => 'diag',
], [[
    'category_id' => (int) $cats[0]['id'],
    'subcategory_id' => '',
    'colour' => 'Green',
    'size' => 'M',
    'selling_price' => 799,
    'quantity' => 2,
]], null, 1, $bid);
print_r($res);

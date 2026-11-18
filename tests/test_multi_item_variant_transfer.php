<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/outlets_db.php';
require_once __DIR__ . '/../includes/products_db.php';

echo "Testing multi-item & variant stock transfer...\n";

$db = get_db();
$bidRow = $db->query('SELECT business_id FROM products WHERE status = "active" ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$bid = (int) ($bidRow['business_id'] ?? 1);
$_SESSION['business_id'] = $bid;

$warehouses = get_warehouses(null, 'active', $bid);
if (count($warehouses) < 2) {
    echo "Skipping: Need at least 2 active warehouses\n";
    exit(0);
}

$srcId = (int) $warehouses[0]['id'];
$dstId = (int) $warehouses[1]['id'];

// Find or pick 2 products
$prods = $db->query("SELECT id, name, sku FROM products WHERE business_id = {$bid} AND status = 'active' LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (count($prods) < 1) {
    echo "Skipping: Need at least 1 active product\n";
    exit(0);
}

$p1 = $prods[0];
$p2 = $prods[1] ?? $prods[0];

// Check if any variants exist
$varRow = $db->query("SELECT id, product_id, variant_name FROM product_variants WHERE business_id = {$bid} AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$vid = $varRow ? (int)$varRow['id'] : null;
$vidPid = $varRow ? (int)$varRow['product_id'] : (int)$p1['id'];

$items = [
    [
        'product_id' => $vidPid,
        'variant_id' => $vid,
        'quantity' => 3,
    ],
    [
        'product_id' => (int)$p2['id'],
        'variant_id' => null,
        'quantity' => 5,
    ],
];

// Ensure source warehouse has stock
set_product_warehouse_stock($vidPid, $srcId, 50);
set_product_warehouse_stock((int)$p2['id'], $srcId, 50);

// 1. Create transfer
$res = create_stock_transfer($srcId, $dstId, $items, 'Test multi-item transfer', 1, $bid);
if (empty($res['success'])) {
    echo "[FAIL] Failed to create stock transfer: " . ($res['error'] ?? 'Unknown error') . "\n";
    exit(1);
}
$tid = (int)$res['transfer_id'];
echo "[PASS] Created transfer #{$res['transfer_number']} (ID: {$tid})\n";

// 2. Fetch transfer and verify variant_id stored
$trf = get_stock_transfer_by_id($tid, $bid);
if (!$trf || count($trf['items']) !== 2) {
    echo "[FAIL] Transfer does not have 2 items: " . var_export($trf, true) . "\n";
    exit(1);
}

echo "[PASS] Fetched transfer with " . count($trf['items']) . " items successfully!\n";
if ($vid) {
    $foundVar = false;
    foreach ($trf['items'] as $item) {
        if ((int)$item['variant_id'] === $vid) {
            $foundVar = true;
            echo "[PASS] Verified variant_id {$vid} stored on transfer item (variant_name: " . ($item['variant_name'] ?? 'N/A') . ")!\n";
            break;
        }
    }
    if (!$foundVar) {
        echo "[FAIL] Did not find variant_id {$vid} in transfer items!\n";
        exit(1);
    }
}

// 3. Workflow transitions: approve -> pick -> dispatch -> ship -> receive
$resApprove = approve_stock_transfer($tid, 1);
assert(!empty($resApprove['success']), "Approve failed");
$resPick = pick_stock_transfer($tid, 1);
assert(!empty($resPick['success']), "Pick failed");
$resDispatch = dispatch_stock_transfer($tid, 1);
assert(!empty($resDispatch['success']), "Dispatch failed");
$resShip = ship_stock_transfer_in_transit($tid, 1);
assert(!empty($resShip['success']), "Ship failed: " . ($resShip['error'] ?? ''));

// Verify source warehouse reduced
assert(get_product_warehouse_stock((int)$p2['id'], $srcId) === 45, "Source warehouse not reduced properly");

$resReceive = receive_stock_transfer($tid, 1);
assert(!empty($resReceive['success']), "Receive failed: " . ($resReceive['error'] ?? ''));

// Verify items received in db
$trfDone = get_stock_transfer_by_id($tid, $bid);
assert($trfDone['status'] === 'received', "Status should be received");
foreach ($trfDone['items'] as $item) {
    assert((int)$item['quantity_received'] === (int)$item['quantity_requested'], "Received qty must match requested qty");
}
echo "[PASS] Successfully received all items, verified status and quantities!\n";

// Clean up test transfer to avoid cluttering history
$db->prepare("DELETE FROM stock_transfers WHERE id = :id")->execute(['id' => $tid]);
echo "[PASS] Cleaned up test transfer.\n";
echo "ALL MULTI-ITEM VARIANT TRANSFER TESTS PASSED!\n";

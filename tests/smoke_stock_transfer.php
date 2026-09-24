<?php
declare(strict_types=1);

/**
 * Transfer in transit + receive: source warehouse -N, destination +N, company total unchanged.
 * Run: php tests/smoke_stock_transfer.php
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/outlets_db.php';
require_once __DIR__ . '/../includes/auth.php';

$failures = 0;

function ok(string $msg): void {
    echo "[OK] {$msg}\n";
}

function fail(string $msg): void {
    global $failures;
    $failures++;
    echo "[FAIL] {$msg}\n";
}

$db = get_db();
$bidRow = $db->query('SELECT business_id FROM products WHERE status = "active" ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$bid = (int) ($bidRow['business_id'] ?? 1);
$_SESSION['business_id'] = $bid;

$warehouses = get_warehouses(null, 'active', $bid);
if (count($warehouses) < 2) {
    fail('need at least two active warehouses');
    exit($failures > 0 ? 1 : 0);
}

$srcId = (int) $warehouses[0]['id'];
$dstId = (int) $warehouses[1]['id'];
if ($srcId === $dstId) {
    $dstId = (int) $warehouses[count($warehouses) - 1]['id'];
}

$prod = $db->query("SELECT id, stock_quantity FROM products WHERE business_id = {$bid} AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$prod) {
    fail('no active product');
    exit(1);
}
$pid = (int) $prod['id'];

$companyQty = static function (PDO $db, int $productId): int {
    return (int) $db->query('SELECT stock_quantity FROM products WHERE id = ' . $productId)->fetchColumn();
};

$whQty = static function (PDO $db, int $productId, int $warehouseId): int {
    return get_product_warehouse_stock($productId, $warehouseId);
};

$srcOrig = $whQty($db, $pid, $srcId);
$dstOrig = $whQty($db, $pid, $dstId);
$companyOrig = $companyQty($db, $pid);

try {
    set_product_warehouse_stock($pid, $srcId, 100);
    set_product_warehouse_stock($pid, $dstId, 50);
    $companyBefore = $companyQty($db, $pid);
    $srcBefore = $whQty($db, $pid, $srcId);
    $dstBefore = $whQty($db, $pid, $dstId);

    $qty = 20;
    $created = create_stock_transfer($srcId, $dstId, [['product_id' => $pid, 'quantity' => $qty]], 'smoke', null, $bid);
    if (empty($created['success'])) {
        throw new RuntimeException('create failed: ' . ($created['error'] ?? ''));
    }
    $tid = (int) $created['transfer_id'];

    foreach (['approve_stock_transfer', 'pick_stock_transfer', 'dispatch_stock_transfer', 'ship_stock_transfer_in_transit'] as $step) {
        $res = $step($tid, null);
        if (empty($res['success'])) {
            throw new RuntimeException($step . ' failed: ' . ($res['error'] ?? ''));
        }
    }

    if ($companyQty($db, $pid) !== $companyBefore) {
        fail('company total changed during in transit');
    } elseif ($whQty($db, $pid, $srcId) !== $srcBefore - $qty) {
        fail('source warehouse not reduced in transit');
    } elseif ($whQty($db, $pid, $dstId) !== $dstBefore) {
        fail('destination changed before receive');
    } else {
        ok('in transit: source -' . $qty . ', company total unchanged');
    }

    $received = receive_stock_transfer($tid, null);
    if (empty($received['success'])) {
        throw new RuntimeException('receive failed: ' . ($received['error'] ?? ''));
    }

    if ($companyQty($db, $pid) !== $companyBefore) {
        fail('company total changed on receive');
    } elseif ($whQty($db, $pid, $srcId) !== $srcBefore - $qty) {
        fail('source warehouse wrong after receive');
    } elseif ($whQty($db, $pid, $dstId) !== $dstBefore + $qty) {
        fail('destination warehouse not increased on receive');
    } else {
        ok('receive: destination +' . $qty . ', company total unchanged');
    }

    $summary = get_company_and_store_stock_summary($bid);
    if (!isset($summary['company_pieces'], $summary['locations']) || !is_array($summary['locations'])) {
        fail('store stock summary shape invalid');
    } else {
        ok('company and store stock summary available');
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}

set_product_warehouse_stock($pid, $srcId, $srcOrig);
set_product_warehouse_stock($pid, $dstId, $dstOrig);
$db->prepare('UPDATE products SET stock_quantity = :q WHERE id = :id')->execute(['q' => $companyOrig, 'id' => $pid]);
ok('restored warehouse and company quantities');

exit($failures > 0 ? 1 : 0);

<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/outlets_db.php';
require_once __DIR__ . '/../includes/registers_db.php';

$failures = 0;
function ok(string $msg): void { echo "[OK] {$msg}\n"; }
function fail(string $msg): void { global $failures; $failures++; echo "[FAIL] {$msg}\n"; }

ensure_counter_store_columns();
$db = get_db();
foreach (['users', 'registers'] as $table) {
    $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = "outlet_id"');
    $st->execute(['db' => DB_NAME, 'tbl' => $table]);
    if ((int) $st->fetchColumn() > 0) {
        ok("{$table}.outlet_id exists");
    } else {
        fail("{$table}.outlet_id missing");
    }
}

$bidRow = $db->query('SELECT business_id FROM products WHERE status = "active" ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$bid = (int) ($bidRow['business_id'] ?? 1);
$db->beginTransaction();
$outlets = get_outlets('active', $bid);
if (count($outlets) < 2) {
    $db->prepare('
        INSERT INTO outlets (business_id, name, code, address, phone, email, status, created_at, updated_at)
        VALUES (:bid, :name, :code, "", "", "", "active", NOW(), NOW())
    ')->execute([
        'bid' => $bid,
        'name' => 'Smoke Counter Store',
        'code' => 'SMK-' . substr(uniqid(), -6),
    ]);
    $newOutlet = (int) $db->lastInsertId();
    $db->prepare('
        INSERT INTO warehouses (business_id, outlet_id, name, code, location, status, created_at, updated_at)
        VALUES (:bid, :oid, :name, :code, "", "active", NOW(), NOW())
    ')->execute([
        'bid' => $bid,
        'oid' => $newOutlet,
        'name' => 'Smoke Counter Warehouse',
        'code' => 'WH-SMK-' . substr(uniqid(), -6),
    ]);
    $outlets = get_outlets('active', $bid);
}

$product = $db->prepare('SELECT id FROM products WHERE business_id = :bid AND status = "active" ORDER BY id ASC LIMIT 1');
$product->execute(['bid' => $bid]);
$pid = (int) $product->fetchColumn();
if ($pid <= 0) {
    fail('no product');
    $db->rollBack();
    exit(1);
}

$whA = get_warehouse_id_for_outlet((int) $outlets[0]['id'], $bid);
$whB = get_warehouse_id_for_outlet((int) $outlets[1]['id'], $bid);
if (!$whA || !$whB) {
    fail('outlet warehouses missing');
    $db->rollBack();
    exit(1);
}

try {
    set_product_warehouse_stock($pid, $whA, 7);
    $db->prepare('DELETE FROM warehouse_stock WHERE product_id = :pid AND warehouse_id = :wid')->execute(['pid' => $pid, 'wid' => $whB]);
    $atA = get_pos_counter_stock($pid, $whA, $bid);
    $atB = get_pos_counter_stock($pid, $whB, $bid);
    if ($atA === 7) {
        ok('counter A sells its own 7');
    } else {
        fail("counter A expected 7, got {$atA}");
    }
    if ($atB === 0) {
        ok('counter B does not sell the other store quantity');
    } else {
        fail("counter B expected 0, got {$atB}");
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
$db->rollBack();
ok('rolled back');

echo $failures === 0 ? "\nPassed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);

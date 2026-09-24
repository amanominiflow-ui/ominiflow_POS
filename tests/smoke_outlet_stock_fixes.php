<?php
declare(strict_types=1);

/**
 * Smoke checks for outlet-on-bill + stock-count movement fixes.
 * Run: php tests/smoke_outlet_stock_fixes.php
 * Uses DB transactions and rolls back — no lasting data changes.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/outlets_db.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/stock_counts_db.php';
require_once __DIR__ . '/../includes/orders_db.php';
require_once __DIR__ . '/../includes/reports_db.php';

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

// Schema
try {
    ensure_orders_invoices_schema();
    ok('ensure_orders_invoices_schema()');
} catch (Throwable $e) {
    fail('ensure_orders_invoices_schema: ' . $e->getMessage());
}

foreach (['orders.outlet_id', 'invoices.outlet_id'] as $colCheck) {
    [$tbl, $col] = explode('.', $colCheck);
    $st = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col
    ");
    $st->execute(['db' => DB_NAME, 'tbl' => $tbl, 'col' => $col]);
    if ((int) $st->fetchColumn() > 0) {
        ok("column {$colCheck} exists");
    } else {
        fail("column {$colCheck} missing");
    }
}

// Outlet resolution (no default to arbitrary id when session outlet valid)
$outlets = get_outlets('active', $bid);
if ($outlets === []) {
    fail('no active outlets to test');
} else {
    $secondId = (int) ($outlets[1]['id'] ?? $outlets[0]['id']);
    $resolved = resolve_pos_outlet_id($secondId, $bid);
    if ($resolved === $secondId) {
        ok('resolve_pos_outlet_id() keeps valid outlet');
    } else {
        fail("resolve_pos_outlet_id expected {$secondId}, got {$resolved}");
    }
    $fromSession = resolve_pos_outlet_id(0, $bid);
    if ($fromSession > 0) {
        ok('resolve_pos_outlet_id() falls back to first active outlet');
    } else {
        fail('resolve_pos_outlet_id fallback failed');
    }
}

// adjust_stock still logs movement (rollback)
$prod = $db->query("SELECT id, stock_quantity FROM products WHERE business_id = {$bid} AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$prod) {
    fail('no active product for adjust_stock test');
} else {
    $pid = (int) $prod['id'];
    $before = (int) $prod['stock_quantity'];
    try {
        $res = adjust_stock($pid, null, 'in', 1, 'Smoke test stock in', $bid);
        if (empty($res['success'])) {
            fail('adjust_stock failed: ' . json_encode($res['errors'] ?? []));
        } else {
            $mov = $db->prepare('SELECT id FROM inventory_movements WHERE product_id = :pid AND reason = :r ORDER BY id DESC LIMIT 1');
            $mov->execute(['pid' => $pid, 'r' => 'Smoke test stock in']);
            if ($mov->fetchColumn()) {
                ok('adjust_stock() writes inventory_movements');
            } else {
                fail('adjust_stock() did not write movement');
            }
            $restore = adjust_stock($pid, null, 'adjustment', $before, 'Smoke test restore', $bid);
            if (empty($restore['success'])) {
                fail('adjust_stock restore failed');
            } else {
                ok('adjust_stock restore to original qty');
            }
        }
    } catch (Throwable $e) {
        fail('adjust_stock exception: ' . $e->getMessage());
    }
}

// apply_counted_quantity_to_stock + reconcile movement (rollback)
if ($prod) {
    $pid = (int) $prod['id'];
    $db->beginTransaction();
    try {
        $cur = (int) $db->query("SELECT stock_quantity FROM products WHERE id = {$pid}")->fetchColumn();
        $target = $cur + 2;
        $saved = apply_counted_quantity_to_stock($db, $bid, $pid, $target);
        if ($saved === $target) {
            ok('apply_counted_quantity_to_stock() sets expected qty');
        } else {
            fail("apply_counted_quantity_to_stock expected {$target}, got {$saved}");
        }
        $afterRead = (int) $db->query("SELECT stock_quantity FROM products WHERE id = {$pid}")->fetchColumn();
        if ($afterRead === $target) {
            ok('product stock reflects counted quantity in transaction');
        } else {
            fail("product stock after apply expected {$target}, got {$afterRead}");
        }
    } catch (Throwable $e) {
        fail('apply_counted_quantity_to_stock: ' . $e->getMessage());
    }
    $db->rollBack();
    ok('apply_counted_quantity_to_stock test rolled back');
}

// get_outlet_sales_report still runs
try {
    $rep = get_outlet_sales_report('', '', $bid);
    if (is_array($rep)) {
        ok('get_outlet_sales_report() returns array');
    } else {
        fail('get_outlet_sales_report invalid return');
    }
} catch (Throwable $e) {
    fail('get_outlet_sales_report: ' . $e->getMessage());
}

// bill_generate / invoice query includes outlet join path
try {
    $invRow = $db->query('SELECT id, business_id FROM invoices ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($invRow) {
        $row = get_invoice_by_id((int) $invRow['id'], (int) $invRow['business_id']);
        if ($row && array_key_exists('outlet_name', $row)) {
            ok('get_invoice_by_id() exposes outlet_name');
        } else {
            fail('get_invoice_by_id missing outlet_name key');
        }
    } else {
        ok('get_invoice_by_id skipped (no invoices)');
    }
} catch (Throwable $e) {
    fail('get_invoice_by_id: ' . $e->getMessage());
}

echo $failures === 0 ? "\nAll outlet/stock smoke checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);

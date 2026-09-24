<?php
declare(strict_types=1);

/**
 * Inward entry stores a warehouse count and does not change sellable stock.
 * Run: php tests/smoke_inward_entry.php
 * Uses a transaction and rolls back.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inward_db.php';
require_once __DIR__ . '/../includes/orders_db.php';

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
ensure_inward_schema();
require_once __DIR__ . '/../includes/purchases_db.php';
ensure_purchases_full_schema();
ok('ensure_inward_schema()');

$product = $db->query('SELECT id, business_id, stock_quantity FROM products WHERE status = "active" ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    fail('no active product');
    exit(1);
}
$bid = (int) $product['business_id'];
$productId = (int) $product['id'];

$wh = $db->prepare('SELECT id FROM warehouses WHERE business_id = :bid AND status = "active" ORDER BY id ASC LIMIT 1');
$wh->execute(['bid' => $bid]);
$warehouseId = (int) $wh->fetchColumn();
if ($warehouseId <= 0) {
    fail('no active warehouse');
    exit(1);
}

$variant = $db->prepare('SELECT id, variant_name, attribute_values, stock_quantity FROM product_variants WHERE product_id = :pid AND business_id = :bid AND status = "active" LIMIT 1');
$variant->execute(['pid' => $productId, 'bid' => $bid]);
$variantRow = $variant->fetch(PDO::FETCH_ASSOC) ?: null;
$attrs = $variantRow ? parse_variant_size_colour($variantRow) : ['size' => '', 'colour' => ''];
$size = $attrs['size'] !== '' ? $attrs['size'] : 'M';
$colour = $attrs['colour'] !== '' ? $attrs['colour'] : 'Blue';

function snapshot(PDO $db, int $productId, int $warehouseId): array {
    $stock = (int) $db->query('SELECT stock_quantity FROM products WHERE id = ' . $productId)->fetchColumn();
    $wh = $db->prepare('SELECT COALESCE(SUM(stock_quantity), 0) FROM warehouse_stock WHERE product_id = :pid AND warehouse_id = :wid');
    $wh->execute(['pid' => $productId, 'wid' => $warehouseId]);
    $var = $db->prepare('SELECT COALESCE(SUM(stock_quantity), 0) FROM product_variants WHERE product_id = :pid');
    $var->execute(['pid' => $productId]);
    $moves = (int) $db->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn();
    return [
        'product' => $stock,
        'warehouse' => (int) $wh->fetchColumn(),
        'variants' => (int) $var->fetchColumn(),
        'movements' => $moves,
    ];
}

$db->beginTransaction();
try {
    $before = snapshot($db, $productId, $warehouseId);
    $res = create_inward_entry($warehouseId, [[
        'product_id' => $productId,
        'size' => $size,
        'colour' => $colour,
        'quantity' => 7,
    ]], date('Y-m-d'), 'smoke count', null, null, $bid);

    if (empty($res['success'])) {
        fail('create_inward_entry: ' . ($res['error'] ?? 'unknown'));
    } else {
        ok('create_inward_entry saved ' . $res['entry_number']);
        $entry = get_inward_entry_by_id((int) $res['entry_id'], $bid);
        if (!$entry || (int) $entry['is_sellable'] !== 0) {
            fail('entry is not marked not-sellable');
        } else {
            ok('entry stays not sellable');
        }
        $line = $entry['lines'][0] ?? null;
        if (!$line || (int) $line['quantity'] !== 7 || strcasecmp((string) $line['size'], $size) !== 0 || strcasecmp((string) $line['colour'], $colour) !== 0) {
            fail('line did not store product size colour quantity');
        } else {
            ok('line stores size, colour, and quantity');
        }
        if ((string) ($entry['status'] ?? '') !== 'cost_pending' || $line['purchase_cost'] !== null || $line['selling_price'] !== null) {
            fail('costs were not left blank while pending');
        } else {
            ok('purchase cost and selling price stay blank while cost is pending');
        }

        $priceBefore = $db->prepare('SELECT cost_price, selling_price, barcode, stock_quantity FROM products WHERE id = :id');
        $priceBefore->execute(['id' => $productId]);
        $productBefore = $priceBefore->fetch(PDO::FETCH_ASSOC);
        $variantBefore = null;
        if ($variantRow) {
            $vb = $db->prepare('SELECT cost_price, selling_price, barcode, stock_quantity FROM product_variants WHERE id = :id');
            $vb->execute(['id' => (int) $variantRow['id']]);
            $variantBefore = $vb->fetch(PDO::FETCH_ASSOC);
        }

        $blankCost = confirm_inward_costs((int) $res['entry_id'], [[
            'line_id' => (int) $line['id'],
            'purchase_cost' => '',
            'selling_price' => '100',
        ]], $bid);
        if (!empty($blankCost['success'])) {
            fail('blank purchase cost was accepted');
        } else {
            ok('blank cost rejected');
        }

        $confirmed = confirm_inward_costs((int) $res['entry_id'], [[
            'line_id' => (int) $line['id'],
            'purchase_cost' => '40.50',
            'selling_price' => '99',
        ]], $bid, null, null, 'Smoke Supplier');
        if (empty($confirmed['success'])) {
            fail('confirm_inward_costs: ' . ($confirmed['error'] ?? 'unknown'));
        } else {
            $saved = get_inward_entry_by_id((int) $res['entry_id'], $bid);
            $savedLine = $saved['lines'][0] ?? null;
            if ((string) ($saved['status'] ?? '') !== 'confirmed' || (int) ($saved['is_sellable'] ?? 1) !== 0) {
                fail('confirmed entry changed sellable state');
            } elseif (!$savedLine || (string) $savedLine['purchase_cost'] !== '40.50' || (string) $savedLine['selling_price'] !== '99.00') {
                fail('confirmed prices were not stored on the inward line');
            } elseif ((int) ($saved['bill_id'] ?? 0) <= 0 || ($saved['bill_number'] ?? '') === '') {
                fail('purchase bill was not created');
            } elseif ((string) ($saved['vendor_name'] ?? '') !== 'Smoke Supplier') {
                fail('typed supplier name was not saved');
            } else {
                ok('confirm stores prices and creates bill ' . $saved['bill_number']);
            }
        }

        $priceAfter = $db->prepare('SELECT cost_price, selling_price, barcode, stock_quantity FROM products WHERE id = :id');
        $priceAfter->execute(['id' => $productId]);
        $productAfter = $priceAfter->fetch(PDO::FETCH_ASSOC);
        if ($productBefore == $productAfter) {
            ok('product price, barcode, and stock unchanged');
        } else {
            fail('product master changed when costs were confirmed');
        }
        if ($variantRow && $variantBefore) {
            $va = $db->prepare('SELECT cost_price, selling_price, barcode, stock_quantity FROM product_variants WHERE id = :id');
            $va->execute(['id' => (int) $variantRow['id']]);
            if ($variantBefore == $va->fetch(PDO::FETCH_ASSOC)) {
                ok('variant price, barcode, and stock unchanged');
            } else {
                fail('variant master changed when costs were confirmed');
            }
        }
        if ($variantRow && ($attrs['size'] === '' || $attrs['colour'] === '')) {
            ok('product variant has no size/colour pair; line stored as a count only');
        } elseif ($variantRow && (int) ($line['variant_id'] ?? 0) !== (int) $variantRow['id']) {
            fail('matching variant was not linked');
        } elseif ($variantRow) {
            ok('matching variant linked without a stock change');
        }
    }

    $after = snapshot($db, $productId, $warehouseId);
    foreach (['product', 'warehouse', 'variants', 'movements'] as $key) {
        if ($before[$key] === $after[$key]) {
            ok("{$key} stock unchanged ({$before[$key]})");
        } else {
            fail("{$key} changed from {$before[$key]} to {$after[$key]}");
        }
    }

    $named = create_inward_entry($warehouseId, [[
        'product_id' => $productId,
        'size' => $size,
        'colour' => $colour,
        'quantity' => 1,
    ]], date('Y-m-d'), 'smoke named bill', null, null, $bid);
    if (empty($named['success'])) {
        fail('second inward entry: ' . ($named['error'] ?? 'unknown'));
    } else {
        $namedEntry = get_inward_entry_by_id((int) $named['entry_id'], $bid);
        $namedLine = $namedEntry['lines'][0] ?? null;
        $db->prepare('UPDATE inward_lines SET purchase_cost = 12.00, selling_price = 20.00, cost_status = "confirmed" WHERE id = :id')
            ->execute(['id' => (int) ($namedLine['id'] ?? 0)]);
        $db->prepare('UPDATE inward_entries SET status = "confirmed" WHERE id = :id AND business_id = :bid')
            ->execute(['id' => (int) $named['entry_id'], 'bid' => $bid]);
        $namedBill = create_inward_purchase_bill((int) $named['entry_id'], null, null, $bid, 'Smoke Bill Supplier');
        $namedSaved = get_inward_entry_by_id((int) $named['entry_id'], $bid);
        if (empty($namedBill['success']) || ($namedSaved['bill_number'] ?? '') === '' || (string) ($namedSaved['vendor_name'] ?? '') !== 'Smoke Bill Supplier') {
            fail('create bill from typed supplier: ' . ($namedBill['error'] ?? 'bill missing'));
        } else {
            ok('create bill from a typed supplier name ' . $namedSaved['bill_number']);
        }
    }

    $bad = create_inward_entry($warehouseId, [[
        'product_id' => $productId,
        'size' => '',
        'colour' => 'Blue',
        'quantity' => 1,
    ]], date('Y-m-d'), '', null, null, $bid);
    if (!empty($bad['success'])) {
        fail('blank size was accepted');
    } else {
        ok('blank size rejected');
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures > 0) {
    echo "{$failures} failure(s)\n";
    exit(1);
}
echo "inward entry smoke passed\n";
exit(0);

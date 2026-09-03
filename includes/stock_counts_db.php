<?php
/**
 * Physical Inventory Stock Count & Reconciliation Service for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function start_stock_count(int $userId, string $notes = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    // Check if there is already an in-progress count for this business
    $stmtActive = $db->prepare('SELECT id, count_number FROM stock_counts WHERE business_id = :bid AND status = "in_progress" LIMIT 1');
    $stmtActive->execute(['bid' => $bid]);
    $active = $stmtActive->fetch();
    if ($active) {
        $countId = (int)$active['id'];
        $stmtMissing = $db->prepare("
            SELECT p.id, p.stock_quantity 
            FROM products p 
            WHERE p.business_id = :bid AND p.status = 'active' AND p.id NOT IN (SELECT product_id FROM stock_count_items WHERE stock_count_id = {$countId})
        ");
        $stmtMissing->execute(['bid' => $bid]);
        $missingProds = $stmtMissing->fetchAll();
        $stmtItem = $db->prepare('
            INSERT INTO stock_count_items (stock_count_id, product_id, expected_qty, counted_qty, difference_qty, created_at)
            VALUES (:sc_id, :prod_id, :exp_qty, :counted_qty, 0, NOW())
        ');
        foreach ($missingProds as $mp) {
            $stmtItem->execute([
                'sc_id' => $countId,
                'prod_id' => $mp['id'],
                'exp_qty' => (int)$mp['stock_quantity'],
                'counted_qty' => (int)$mp['stock_quantity'],
            ]);
        }
        return ['success' => true, 'count_id' => $countId, 'count_number' => $active['count_number'], 'is_active' => true];
    }

    try {
        $db->beginTransaction();

        require_once __DIR__ . '/orders_db.php';
        $countNumber = generate_unique_reference('stock_counts', 'count_number', 'STK-', $db);

        $stmtSC = $db->prepare('
            INSERT INTO stock_counts (business_id, count_number, user_id, status, notes, created_at)
            VALUES (:biz_id, :num, :uid, "in_progress", :notes, NOW())
        ');
        $stmtSC->execute([
            'biz_id' => $bid,
            'num' => $countNumber,
            'uid' => $userId,
            'notes' => trim($notes) ?: null,
        ]);
        $countId = (int) $db->lastInsertId();

        // Snapshot all active products into stock_count_items
        $stmtProds = $db->prepare('SELECT id, stock_quantity FROM products WHERE business_id = :bid AND status = "active"');
        $stmtProds->execute(['bid' => $bid]);
        $products = $stmtProds->fetchAll();
        $stmtItem = $db->prepare('
            INSERT INTO stock_count_items (stock_count_id, product_id, expected_qty, counted_qty, difference_qty, created_at)
            VALUES (:sc_id, :prod_id, :exp_qty, :counted_qty, 0, NOW())
        ');

        foreach ($products as $p) {
            $stmtItem->execute([
                'sc_id' => $countId,
                'prod_id' => $p['id'],
                'exp_qty' => (int) $p['stock_quantity'],
                'counted_qty' => (int) $p['stock_quantity'],
            ]);
        }

        $db->commit();
        return ['success' => true, 'count_id' => $countId, 'count_number' => $countNumber];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function update_stock_count_item(int $stockCountItemId, int $countedQty): array {
    $db = get_db();
    $stmt = $db->prepare('SELECT expected_qty FROM stock_count_items WHERE id = :id');
    $stmt->execute(['id' => $stockCountItemId]);
    $exp = $stmt->fetchColumn();
    if ($exp === false) return ['success' => false, 'error' => 'Item not found'];

    $diff = $countedQty - (int)$exp;
    $stmtUp = $db->prepare('
        UPDATE stock_count_items
        SET counted_qty = :counted, difference_qty = :diff
        WHERE id = :id
    ');
    $stmtUp->execute([
        'counted' => $countedQty,
        'diff' => $diff,
        'id' => $stockCountItemId,
    ]);

    return ['success' => true, 'difference' => $diff];
}

function reconcile_and_complete_stock_count(int $countId, int $userId, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    try {
        $db->beginTransaction();

        $count = get_stock_count_by_id($countId, $bid);
        if (!$count || $count['status'] !== 'in_progress') {
            throw new Exception('Active stock count session not found.');
        }

        $stmtStockAdj = $db->prepare('UPDATE products SET stock_quantity = :qty, updated_at = NOW() WHERE id = :id AND business_id = :bid');
        $stmtMoveLog = $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :biz_id, :product_id, :user_id, "adjustment", :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
            )
        ');

        $adjustmentsMade = 0;
        foreach ($count['items'] as $item) {
            $diff = (int) $item['difference_qty'];
            if ($diff !== 0) {
                $prodId = (int) $item['product_id'];

                $stmtCur = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :bid FOR UPDATE');
                $stmtCur->execute(['id' => $prodId, 'bid' => $bid]);
                $currStock = (int) $stmtCur->fetchColumn();

                $newStock = (int) $item['counted_qty'];
                $stmtStockAdj->execute(['qty' => $newStock, 'id' => $prodId, 'bid' => $bid]);

                $stmtMoveLog->execute([
                    'biz_id' => $bid,
                    'product_id' => $prodId,
                    'user_id' => $userId,
                    'quantity_change' => $diff,
                    'quantity_before' => $currStock,
                    'quantity_after' => $newStock,
                    'reason' => "Physical Stock Audit #{$count['count_number']} Reconciliation",
                ]);
                $adjustmentsMade++;
            }
        }

        // Mark count completed
        $stmtDone = $db->prepare('
            UPDATE stock_counts
            SET status = "completed", completed_at = NOW(), total_items_counted = :total
            WHERE id = :id AND business_id = :bid
        ');
        $stmtDone->execute(['total' => count($count['items']), 'id' => $countId, 'bid' => $bid]);

        $db->commit();
        return ['success' => true, 'adjustments_made' => $adjustmentsMade];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function get_stock_counts(int $limit = 50, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT sc.*, COALESCE(u.name, "Staff Auditor") AS auditor_name,
               (SELECT COUNT(*) FROM stock_count_items sci WHERE sci.stock_count_id = sc.id) AS total_items,
               (SELECT COUNT(*) FROM stock_count_items sci WHERE sci.stock_count_id = sc.id AND sci.difference_qty != 0) AS total_discrepancies
        FROM stock_counts sc
        LEFT JOIN users u ON u.id = sc.user_id
        WHERE sc.business_id = :bid
        ORDER BY sc.id DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':bid', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_stock_count_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT sc.*, COALESCE(u.name, "Staff Auditor") AS auditor_name
        FROM stock_counts sc
        LEFT JOIN users u ON u.id = sc.user_id
        WHERE sc.id = :id AND sc.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid]);
    $sc = $stmt->fetch();
    if (!$sc) return null;

    $stmtItems = $db->prepare('
        SELECT sci.*, p.name AS product_name, p.sku AS product_sku, p.selling_price
        FROM stock_count_items sci
        JOIN products p ON p.id = sci.product_id AND p.business_id = :bid_p
        WHERE sci.stock_count_id = :id
        ORDER BY p.name ASC
    ');
    $stmtItems->execute(['id' => $id, 'bid_p' => $bid]);
    $sc['items'] = $stmtItems->fetchAll();

    return $sc;
}

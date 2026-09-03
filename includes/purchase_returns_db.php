<?php
/**
 * Purchase Returns & Vendor Payables Service for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function create_purchase_return(int $poId, array $items, string $refundMethod = 'vendor_credit', string $notes = '', ?int $userId = null, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    try {
        $db->beginTransaction();

        $stmtPO = $db->prepare('SELECT * FROM purchase_orders WHERE id = :id AND business_id = :bid LIMIT 1');
        $stmtPO->execute(['id' => $poId, 'bid' => $bid]);
        $po = $stmtPO->fetch();
        if (!$po) throw new Exception('Purchase Order not found.');

        $vendorId = (int)$po['vendor_id'];

        require_once __DIR__ . '/orders_db.php';
        $returnNumber = generate_unique_reference('purchase_returns', 'return_number', 'PRET-', $db);

        $totalReturnAmount = 0.00;
        foreach ($items as $it) {
            $cost = (float)($it['unit_cost'] ?? 0);
            $qty = (int)($it['quantity'] ?? 1);
            $totalReturnAmount += ($cost * $qty);
        }

        $stmtPR = $db->prepare('
            INSERT INTO purchase_returns (business_id, return_number, purchase_order_id, vendor_id, user_id, total_amount, refund_method, status, notes, created_at, updated_at)
            VALUES (:biz_id, :rnum, :poid, :vid, :uid, :total, :method, "completed", :notes, NOW(), NOW())
        ');
        $stmtPR->execute([
            'biz_id' => $bid,
            'rnum' => $returnNumber,
            'poid' => $poId,
            'vid' => $vendorId,
            'uid' => $userId ?: 1,
            'total' => $totalReturnAmount,
            'method' => $refundMethod,
            'notes' => trim($notes) ?: null,
        ]);
        $returnId = (int)$db->lastInsertId();

        $stmtItem = $db->prepare('
            INSERT INTO purchase_return_items (purchase_return_id, product_id, unit_cost, quantity, line_total, created_at)
            VALUES (:pr_id, :pid, :cost, :qty, :lt, NOW())
        ');

        $stmtStockDec = $db->prepare('UPDATE products SET stock_quantity = stock_quantity - :qty, updated_at = NOW() WHERE id = :id AND business_id = :bid');
        $stmtMoveLog = $db->prepare('
            INSERT INTO inventory_movements (business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at)
            VALUES (:biz_id, :pid, :uid, "out", :change, :before, :after, :reason, NOW())
        ');

        foreach ($items as $it) {
            $pid = (int)$it['product_id'];
            $qty = (int)$it['quantity'];
            $cost = (float)$it['unit_cost'];
            $lineTot = round($cost * $qty, 2);

            $stmtItem->execute([
                'pr_id' => $returnId,
                'pid' => $pid,
                'cost' => $cost,
                'qty' => $qty,
                'lt' => $lineTot,
            ]);

            // Deduct stock from products & log movement
            $stmtCur = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :bid FOR UPDATE');
            $stmtCur->execute(['id' => $pid, 'bid' => $bid]);
            $beforeStock = (int)$stmtCur->fetchColumn();
            $afterStock = $beforeStock - $qty;

            $stmtStockDec->execute(['qty' => $qty, 'id' => $pid, 'bid' => $bid]);
            $stmtMoveLog->execute([
                'biz_id' => $bid,
                'pid' => $pid,
                'uid' => $userId ?: 1,
                'change' => -$qty,
                'before' => $beforeStock,
                'after' => $afterStock,
                'reason' => "Purchase Return #{$returnNumber} to Vendor",
            ]);
        }

        // Adjust vendor outstanding balance if vendor credit
        $stmtVenAdj = $db->prepare('UPDATE vendors SET outstanding_balance = GREATEST(0, outstanding_balance - :amt) WHERE id = :id AND business_id = :bid');
        $stmtVenAdj->execute(['amt' => $totalReturnAmount, 'id' => $vendorId, 'bid' => $bid]);

        $db->commit();
        return ['success' => true, 'return_id' => $returnId, 'return_number' => $returnNumber, 'total_amount' => $totalReturnAmount];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function record_vendor_payment(int $vendorId, float $amount, ?int $poId = null, string $method = 'bank_transfer', string $ref = '', string $notes = '', ?int $userId = null, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    try {
        $db->beginTransaction();

        require_once __DIR__ . '/orders_db.php';
        $paymentNumber = generate_unique_reference('vendor_payments', 'payment_number', 'VPAY-', $db);

        $stmtVP = $db->prepare('
            INSERT INTO vendor_payments (business_id, payment_number, vendor_id, purchase_order_id, user_id, amount, payment_method, transaction_ref, notes, status, created_at)
            VALUES (:biz_id, :pnum, :vid, :poid, :uid, :amt, :method, :ref, :notes, "paid", NOW())
        ');
        $stmtVP->execute([
            'biz_id' => $bid,
            'pnum' => $paymentNumber,
            'vid' => $vendorId,
            'poid' => $poId ?: null,
            'uid' => $userId ?: 1,
            'amt' => $amount,
            'method' => $method,
            'ref' => trim($ref) ?: null,
            'notes' => trim($notes) ?: null,
        ]);
        $payId = (int)$db->lastInsertId();

        // Update vendor balance
        $stmtVen = $db->prepare('UPDATE vendors SET outstanding_balance = GREATEST(0, outstanding_balance - :amt) WHERE id = :id AND business_id = :bid');
        $stmtVen->execute(['amt' => $amount, 'id' => $vendorId, 'bid' => $bid]);

        $db->commit();
        return ['success' => true, 'payment_id' => $payId, 'payment_number' => $paymentNumber];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function get_purchase_returns(int $limit = 50, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT pr.*, v.name AS vendor_name, v.company_name AS vendor_company, po.po_number, COALESCE(u.name, "Staff") AS creator_name
        FROM purchase_returns pr
        LEFT JOIN vendors v ON v.id = pr.vendor_id AND v.business_id = :bid_v
        LEFT JOIN purchase_orders po ON po.id = pr.purchase_order_id AND po.business_id = :bid_po
        LEFT JOIN users u ON u.id = pr.user_id
        WHERE pr.business_id = :bid
        ORDER BY pr.id DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':bid', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':bid_v', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':bid_po', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

<?php
/**
 * Vendor Management & Purchase Orders (Procurement) Service for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/* =========================================================================
   1. VENDOR MANAGEMENT
   ========================================================================= */

function get_vendors(string $search = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT v.*,
               (SELECT COUNT(*) FROM purchase_orders po WHERE po.vendor_id = v.id AND po.business_id = :bid_po) AS total_orders,
               (SELECT COALESCE(SUM(po.total_amount), 0) FROM purchase_orders po WHERE po.vendor_id = v.id AND po.business_id = :bid_po2) AS total_purchased
        FROM vendors v
        WHERE v.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_po' => $bid,
        'bid_po2' => $bid,
    ];
    if ($search !== '') {
        $sql .= ' AND (v.name LIKE :s1 OR v.company_name LIKE :s2 OR v.phone LIKE :s3 OR v.email LIKE :s4)';
        $params['s1'] = "%{$search}%";
        $params['s2'] = "%{$search}%";
        $params['s3'] = "%{$search}%";
        $params['s4'] = "%{$search}%";
    }
    $sql .= ' ORDER BY v.name ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_vendor_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM vendors WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $id, 'bid' => $bid]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function save_vendor(array $data, ?int $id = null, ?int $businessId = null): array {
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        return ['success' => false, 'error' => 'Vendor name is required.'];
    }

    $db = get_db();
    $bid = $businessId ?: current_business_id();
    if ($id) {
        $stmt = $db->prepare('
            UPDATE vendors
            SET name = :name, company_name = :company, phone = :phone, email = :email,
                address = :address, gstin = :gstin, payment_terms = :terms, status = :status, updated_at = NOW()
            WHERE id = :id AND business_id = :bid
        ');
        $stmt->execute([
            'name' => $name,
            'company' => trim($data['company_name'] ?? '') ?: null,
            'phone' => trim($data['phone'] ?? '') ?: null,
            'email' => trim($data['email'] ?? '') ?: null,
            'address' => trim($data['address'] ?? '') ?: null,
            'gstin' => trim($data['gstin'] ?? '') ?: null,
            'terms' => trim($data['payment_terms'] ?? 'Net 30'),
            'status' => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? $data['status'] : 'active',
            'id' => $id,
            'bid' => $bid,
        ]);
        return ['success' => true, 'id' => $id];
    } else {
        $stmt = $db->prepare('
            INSERT INTO vendors (
                business_id, name, company_name, phone, email, address, gstin, payment_terms, status, created_at, updated_at
            ) VALUES (
                :biz_id, :name, :company, :phone, :email, :address, :gstin, :terms, :status, NOW(), NOW()
            )
        ');
        $stmt->execute([
            'biz_id' => $bid,
            'name' => $name,
            'company' => trim($data['company_name'] ?? '') ?: null,
            'phone' => trim($data['phone'] ?? '') ?: null,
            'email' => trim($data['email'] ?? '') ?: null,
            'address' => trim($data['address'] ?? '') ?: null,
            'gstin' => trim($data['gstin'] ?? '') ?: null,
            'terms' => trim($data['payment_terms'] ?? 'Net 30'),
            'status' => 'active',
        ]);
        return ['success' => true, 'id' => (int)$db->lastInsertId()];
    }
}

/* =========================================================================
   2. PURCHASE ORDERS & STOCK RECEIVING
   ========================================================================= */

function create_purchase_order(
    int $vendorId,
    array $items, // Array of ['product_id' => int, 'quantity' => int, 'unit_cost' => float, 'tax_percent' => float]
    string $expectedDate = '',
    string $notes = '',
    ?int $userId = null,
    ?int $businessId = null
): array {
    if (empty($items)) {
        return ['success' => false, 'error' => 'Purchase order must have at least one product.'];
    }

    $db = get_db();
    $bid = $businessId ?: current_business_id();

    try {
        $db->beginTransaction();

        $vendor = get_vendor_by_id($vendorId, $bid);
        if (!$vendor) {
            throw new Exception('Vendor not found.');
        }

        // Generate PO Number (PO-YYYYMMDD-XXXX)
        require_once __DIR__ . '/orders_db.php';
        $poNumber = generate_unique_reference('purchase_orders', 'po_number', 'PO-', $db);

        $subtotal = 0.00;
        $totalTax = 0.00;
        $processedItems = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            $unitCost = (float) ($item['unit_cost'] ?? 0.00);
            $taxPercent = (float) ($item['tax_percent'] ?? 0.00);

            if ($qty <= 0) continue;

            $stmtP = $db->prepare('SELECT name, sku FROM products WHERE id = :id AND business_id = :bid');
            $stmtP->execute(['id' => $productId, 'bid' => $bid]);
            $p = $stmtP->fetch();
            if (!$p) throw new Exception("Product ID {$productId} not found.");

            $lineSubtotal = round($unitCost * $qty, 2);
            $lineTax = round(($lineSubtotal * $taxPercent) / 100, 2);
            $lineTotal = $lineSubtotal + $lineTax;

            $subtotal += $lineSubtotal;
            $totalTax += $lineTax;

            $processedItems[] = [
                'product_id' => $productId,
                'product_name' => $p['name'],
                'product_sku' => $p['sku'],
                'unit_cost' => $unitCost,
                'quantity_ordered' => $qty,
                'tax_percent' => $taxPercent,
                'line_total' => $lineTotal,
            ];
        }

        $grandTotal = $subtotal + $totalTax;

        $stmtPO = $db->prepare('
            INSERT INTO purchase_orders (
                business_id, po_number, vendor_id, user_id, subtotal, tax_amount, total_amount,
                expected_delivery_date, status, notes, created_at, updated_at
            ) VALUES (
                :biz_id, :po_number, :vendor_id, :user_id, :subtotal, :tax_amount, :total_amount,
                :exp_date, "ordered", :notes, NOW(), NOW()
            )
        ');
        $stmtPO->execute([
            'biz_id' => $bid,
            'po_number' => $poNumber,
            'vendor_id' => $vendorId,
            'user_id' => $userId,
            'subtotal' => $subtotal,
            'tax_amount' => $totalTax,
            'total_amount' => $grandTotal,
            'exp_date' => !empty($expectedDate) ? $expectedDate : null,
            'notes' => trim($notes) ?: null,
        ]);
        $poId = (int) $db->lastInsertId();

        $stmtPOI = $db->prepare('
            INSERT INTO purchase_order_items (
                purchase_order_id, product_id, product_name, product_sku, unit_cost,
                quantity_ordered, quantity_received, tax_percent, line_total, created_at
            ) VALUES (
                :po_id, :product_id, :product_name, :product_sku, :unit_cost,
                :quantity_ordered, 0, :tax_percent, :line_total, NOW()
            )
        ');

        foreach ($processedItems as $pItem) {
            $stmtPOI->execute([
                'po_id' => $poId,
                'product_id' => $pItem['product_id'],
                'product_name' => $pItem['product_name'],
                'product_sku' => $pItem['product_sku'],
                'unit_cost' => $pItem['unit_cost'],
                'quantity_ordered' => $pItem['quantity_ordered'],
                'tax_percent' => $pItem['tax_percent'],
                'line_total' => $pItem['line_total'],
            ]);
        }

        $db->commit();
        return ['success' => true, 'po_id' => $poId, 'po_number' => $poNumber, 'total_amount' => $grandTotal];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Goods Receiving: Increments inventory stock in products table,
 * logs inventory movement audit ('movement_type' = 'in'), and updates PO status.
 */
function receive_purchase_order_items(int $poId, array $receivingList, ?int $userId = null, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    try {
        $db->beginTransaction();

        $po = get_purchase_order_by_id($poId, $bid);
        if (!$po) throw new Exception("Purchase Order #{$poId} not found.");

        if ($po['status'] === 'received') {
            throw new Exception("This purchase order has already been fully received.");
        }

        $stmtUpdatePOI = $db->prepare('
            UPDATE purchase_order_items
            SET quantity_received = quantity_received + :qty
            WHERE id = :id
        ');

        $stmtStockInc = $db->prepare('
            UPDATE products
            SET stock_quantity = stock_quantity + :qty, updated_at = NOW()
            WHERE id = :id AND business_id = :bid
        ');

        $stmtMoveLog = $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :biz_id, :product_id, :user_id, "in", :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
            )
        ');

        $totalReceivedInBatch = 0;

        foreach ($receivingList as $rec) {
            $poiId = (int) ($rec['po_item_id'] ?? 0);
            $qtyToReceive = (int) ($rec['quantity_to_receive'] ?? 0);

            if ($qtyToReceive <= 0) continue;

            // Find matching item
            $foundItem = null;
            foreach ($po['items'] as $it) {
                if ((int)$it['id'] === $poiId) {
                    $foundItem = $it;
                    break;
                }
            }

            if (!$foundItem) throw new Exception("PO Item ID {$poiId} not in this order.");

            $remainingNeeded = (int)$foundItem['quantity_ordered'] - (int)$foundItem['quantity_received'];
            if ($qtyToReceive > $remainingNeeded) {
                throw new Exception("Cannot receive {$qtyToReceive} units for {$foundItem['product_name']}. Only {$remainingNeeded} remaining.");
            }

            // Update PO Item quantity received
            $stmtUpdatePOI->execute(['qty' => $qtyToReceive, 'id' => $poiId]);

            // Get product current stock & increment
            $prodId = (int) $foundItem['product_id'];
            $stmtCur = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :bid FOR UPDATE');
            $stmtCur->execute(['id' => $prodId, 'bid' => $bid]);
            $currStock = (int) $stmtCur->fetchColumn();

            $stmtStockInc->execute(['qty' => $qtyToReceive, 'id' => $prodId, 'bid' => $bid]);

            // Log movement
            $stmtMoveLog->execute([
                'biz_id' => $bid,
                'product_id' => $prodId,
                'user_id' => $userId,
                'quantity_change' => $qtyToReceive,
                'quantity_before' => $currStock,
                'quantity_after' => $currStock + $qtyToReceive,
                'reason' => "PO Goods Receiving #{$po['po_number']} (Vendor: {$po['vendor_name']})",
            ]);

            $totalReceivedInBatch += $qtyToReceive;
        }

        if ($totalReceivedInBatch === 0) {
            throw new Exception("Please specify at least 1 unit to receive.");
        }

        // Re-evaluate overall PO status
        $updatedPO = get_purchase_order_by_id($poId, $bid);
        $allComplete = true;
        foreach ($updatedPO['items'] as $uit) {
            if ((int)$uit['quantity_received'] < (int)$uit['quantity_ordered']) {
                $allComplete = false;
                break;
            }
        }

        $newStatus = $allComplete ? 'received' : 'partially_received';
        $stmtStatus = $db->prepare('UPDATE purchase_orders SET status = :status, updated_at = NOW() WHERE id = :id AND business_id = :bid');
        $stmtStatus->execute(['status' => $newStatus, 'id' => $poId, 'bid' => $bid]);

        $db->commit();
        return ['success' => true, 'new_status' => $newStatus, 'received_units' => $totalReceivedInBatch];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function get_purchase_orders(string $search = '', string $status = '', int $limit = 50, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT po.*, v.name AS vendor_name, v.phone AS vendor_phone, u.name AS creator_name,
               (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS items_count,
               (SELECT SUM(poi.quantity_ordered) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS total_ordered_qty,
               (SELECT SUM(poi.quantity_received) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS total_received_qty
        FROM purchase_orders po
        JOIN vendors v ON v.id = po.vendor_id AND v.business_id = :bid_v
        LEFT JOIN users u ON u.id = po.user_id
        WHERE po.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_v' => $bid,
    ];
    if ($search !== '') {
        $sql .= ' AND (po.po_number LIKE :s1 OR v.name LIKE :s2)';
        $params['s1'] = "%{$search}%";
        $params['s2'] = "%{$search}%";
    }
    if ($status !== '') {
        $sql .= ' AND po.status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY po.id DESC LIMIT ' . max(1, $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_purchase_order_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT po.*, v.name AS vendor_name, v.company_name AS vendor_company, v.phone AS vendor_phone,
               v.email AS vendor_email, v.address AS vendor_address, v.gstin AS vendor_gstin, u.name AS creator_name
        FROM purchase_orders po
        JOIN vendors v ON v.id = po.vendor_id AND v.business_id = :bid_v
        LEFT JOIN users u ON u.id = po.user_id
        WHERE po.id = :id AND po.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid, 'bid_v' => $bid]);
    $po = $stmt->fetch();
    if (!$po) return null;

    $stmtItems = $db->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = :po_id ORDER BY id ASC');
    $stmtItems->execute(['po_id' => $id]);
    $po['items'] = $stmtItems->fetchAll();

    return $po;
}

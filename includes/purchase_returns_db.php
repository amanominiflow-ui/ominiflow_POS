<?php
/**
 * Purchase Returns & Vendor Debit Notes Service for OminiFlow POS (Zoho POS Parity)
 * Multi-tenant isolation, inventory movement logging, and vendor credit ledger.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/orders_db.php';

function ensure_purchase_returns_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = get_db();

    // 1. purchase_returns table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_returns` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `return_number` VARCHAR(50) NOT NULL,
            `purchase_order_id` INT UNSIGNED NULL,
            `bill_id` INT UNSIGNED NULL,
            `vendor_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NULL,
            `return_date` DATE NOT NULL DEFAULT (CURRENT_DATE),
            `subtotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `refund_method` VARCHAR(50) NOT NULL DEFAULT 'vendor_credit',
            `status` ENUM('completed', 'pending', 'cancelled') NOT NULL DEFAULT 'completed',
            `reason` VARCHAR(255) NULL,
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_pr_biz` (`business_id`),
            INDEX `idx_pr_po` (`purchase_order_id`),
            INDEX `idx_pr_bill` (`bill_id`),
            INDEX `idx_pr_vendor` (`vendor_id`),
            INDEX `idx_pr_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    try {
        $cols = $db->query("SHOW COLUMNS FROM purchase_returns")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('business_id', $cols, true)) {
            $db->exec("ALTER TABLE purchase_returns ADD COLUMN `business_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`");
            $db->exec("ALTER TABLE purchase_returns ADD INDEX `idx_pr_biz` (`business_id`)");
        }
        if (!in_array('bill_id', $cols, true)) {
            $db->exec("ALTER TABLE purchase_returns ADD COLUMN `bill_id` INT UNSIGNED NULL AFTER `purchase_order_id`");
        }
        if (!in_array('return_date', $cols, true)) {
            $db->exec("ALTER TABLE purchase_returns ADD COLUMN `return_date` DATE NOT NULL DEFAULT (CURRENT_DATE) AFTER `user_id`");
        }
        if (!in_array('subtotal', $cols, true)) {
            $db->exec("ALTER TABLE purchase_returns ADD COLUMN `subtotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER `return_date`");
        }
        if (!in_array('tax_amount', $cols, true)) {
            $db->exec("ALTER TABLE purchase_returns ADD COLUMN `tax_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER `subtotal`");
        }
        if (!in_array('reason', $cols, true)) {
            $db->exec("ALTER TABLE purchase_returns ADD COLUMN `reason` VARCHAR(255) NULL AFTER `status`");
        }
        $db->exec("ALTER TABLE purchase_returns MODIFY COLUMN `purchase_order_id` INT UNSIGNED NULL");
        $db->exec("ALTER TABLE purchase_returns MODIFY COLUMN `user_id` INT UNSIGNED NULL");
    } catch (Exception $ign) {}

    // 2. purchase_return_items table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_return_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `purchase_return_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `product_name` VARCHAR(191) NOT NULL DEFAULT '',
            `product_sku` VARCHAR(100) NOT NULL DEFAULT '',
            `unit_cost` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `quantity` INT NOT NULL DEFAULT 1,
            `tax_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `reason` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_pri_return` (`purchase_return_id`),
            INDEX `idx_pri_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    try {
        $colsItems = $db->query("SHOW COLUMNS FROM purchase_return_items")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('product_name', $colsItems, true)) {
            $db->exec("ALTER TABLE purchase_return_items ADD COLUMN `product_name` VARCHAR(191) NOT NULL DEFAULT '' AFTER `product_id`");
        }
        if (!in_array('product_sku', $colsItems, true)) {
            $db->exec("ALTER TABLE purchase_return_items ADD COLUMN `product_sku` VARCHAR(100) NOT NULL DEFAULT '' AFTER `product_name`");
        }
        if (!in_array('tax_percent', $colsItems, true)) {
            $db->exec("ALTER TABLE purchase_return_items ADD COLUMN `tax_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00 AFTER `quantity`");
        }
        if (!in_array('tax_amount', $colsItems, true)) {
            $db->exec("ALTER TABLE purchase_return_items ADD COLUMN `tax_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER `tax_percent`");
        }
        if (!in_array('reason', $colsItems, true)) {
            $db->exec("ALTER TABLE purchase_return_items ADD COLUMN `reason` VARCHAR(255) NULL AFTER `line_total`");
        }
    } catch (Exception $ign) {}

    // 3. purchase_order_items.quantity_returned
    try {
        $colsPoi = $db->query("SHOW COLUMNS FROM purchase_order_items")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('quantity_returned', $colsPoi, true)) {
            $db->exec("ALTER TABLE purchase_order_items ADD COLUMN `quantity_returned` INT NOT NULL DEFAULT 0 AFTER `quantity_received`");
        }
    } catch (Exception $ign) {}
}

/**
 * Fetch received / ordered purchase orders for a vendor or all vendors
 */
function get_purchase_orders_for_return(?int $vendorId = null, ?int $businessId = null): array {
    ensure_purchase_returns_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $sql = '
        SELECT po.id, po.po_number, po.vendor_id, po.total_amount, po.status, po.created_at,
               v.name AS vendor_name, v.company_name AS vendor_company,
               COUNT(poi.id) AS items_count,
               COALESCE(SUM(poi.quantity_received), 0) AS total_received,
               COALESCE(SUM(poi.quantity_returned), 0) AS total_returned
        FROM purchase_orders po
        JOIN vendors v ON v.id = po.vendor_id AND v.business_id = :bid_v
        LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
        WHERE po.business_id = :bid AND po.status IN ("received", "partially_received", "ordered")
    ';
    $params = [
        'bid' => $bid,
        'bid_v' => $bid,
    ];
    if ($vendorId) {
        $sql .= ' AND po.vendor_id = :vid';
        $params['vid'] = $vendorId;
    }
    $sql .= ' GROUP BY po.id ORDER BY po.id DESC LIMIT 100';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Fetch returnable line items for a specific purchase order
 */
function get_returnable_po_items(int $poId, ?int $businessId = null): array {
    ensure_purchase_returns_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmtPO = $db->prepare('SELECT * FROM purchase_orders WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmtPO->execute(['id' => $poId, 'bid' => $bid]);
    $po = $stmtPO->fetch();
    if (!$po) {
        return [];
    }

    $stmt = $db->prepare('
        SELECT poi.*,
               p.stock_quantity AS current_stock,
               p.name AS catalog_product_name,
               p.sku AS catalog_sku,
               p.cost_price AS catalog_cost
        FROM purchase_order_items poi
        LEFT JOIN products p ON p.id = poi.product_id AND p.business_id = :bid_prod
        WHERE poi.purchase_order_id = :poid
        ORDER BY poi.id ASC
    ');
    $stmt->execute(['bid_prod' => $bid, 'poid' => $poId]);
    $items = $stmt->fetchAll();

    foreach ($items as &$it) {
        $recv = (int)($it['quantity_received'] ?? 0);
        $ord = (int)($it['quantity_ordered'] ?? 0);
        $ret = (int)($it['quantity_returned'] ?? 0);
        // Base returnable qty on received if goods received; fallback to ordered
        $base = $recv > 0 ? $recv : $ord;
        $it['available_to_return'] = max(0, $base - $ret);
    }
    unset($it);

    return $items;
}

/**
 * Fetch returnable bills for a vendor or all vendors
 */
function get_purchase_bills_for_return(?int $vendorId = null, ?int $businessId = null): array {
    ensure_purchase_returns_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $sql = '
        SELECT pb.id, pb.bill_number, pb.vendor_id, pb.bill_date, pb.total_amount, pb.balance_due, pb.status,
               v.name AS vendor_name, v.company_name AS vendor_company
        FROM purchase_bills pb
        JOIN vendors v ON v.id = pb.vendor_id AND v.business_id = :bid_v
        WHERE pb.business_id = :bid AND pb.status != "cancelled"
    ';
    $params = [
        'bid' => $bid,
        'bid_v' => $bid,
    ];
    if ($vendorId) {
        $sql .= ' AND pb.vendor_id = :vid';
        $params['vid'] = $vendorId;
    }
    $sql .= ' ORDER BY pb.id DESC LIMIT 100';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Fetch returnable items for a specific bill
 */
function get_returnable_bill_items(int $billId, ?int $businessId = null): array {
    ensure_purchase_returns_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmtBill = $db->prepare('SELECT * FROM purchase_bills WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmtBill->execute(['id' => $billId, 'bid' => $bid]);
    $bill = $stmtBill->fetch();
    if (!$bill) {
        return [];
    }

    $stmt = $db->prepare('
        SELECT pbi.*, p.stock_quantity AS current_stock
        FROM purchase_bill_items pbi
        LEFT JOIN products p ON p.id = pbi.product_id AND p.business_id = :bid_prod
        WHERE pbi.bill_id = :bid_bill
        ORDER BY pbi.id ASC
    ');
    $stmt->execute(['bid_prod' => $bid, 'bid_bill' => $billId]);
    $items = $stmt->fetchAll();

    foreach ($items as &$it) {
        $it['available_to_return'] = max(1, (int)($it['quantity'] ?? 1));
    }
    unset($it);

    return $items;
}

/**
 * Create a new Purchase Return / Debit Note (Zoho POS Parity)
 * Supports both modern array signature and legacy positional signature.
 */
function create_purchase_return(
    $headerDataOrPoId,
    array $items,
    $refundMethodOrUserId = 'vendor_credit',
    $notesOrBusinessId = '',
    ?int $userId = null,
    ?int $businessId = null
): array {
    ensure_purchase_returns_schema();
    $db = get_db();

    // Support legacy signature: create_purchase_return(int $poId, array $items, string $method, string $notes, ?int $userId, ?int $bid)
    if (!is_array($headerDataOrPoId)) {
        $poId = (int)$headerDataOrPoId;
        $method = is_string($refundMethodOrUserId) ? $refundMethodOrUserId : 'vendor_credit';
        $reason = is_string($notesOrBusinessId) ? $notesOrBusinessId : '';
        $headerData = [
            'purchase_order_id' => $poId > 0 ? $poId : null,
            'refund_method' => $method,
            'reason' => $reason,
            'notes' => $reason,
        ];
        $actualUserId = $userId;
        $actualBid = $businessId ?: current_business_id();
    } else {
        $headerData = $headerDataOrPoId;
        $actualUserId = is_int($refundMethodOrUserId) ? $refundMethodOrUserId : $userId;
        $actualBid = is_int($notesOrBusinessId) ? $notesOrBusinessId : ($businessId ?: current_business_id());
    }

    if (empty($items)) {
        return ['success' => false, 'error' => 'At least one item must be selected for return.'];
    }

    $poId = !empty($headerData['purchase_order_id']) ? (int)$headerData['purchase_order_id'] : null;
    $billId = !empty($headerData['bill_id']) ? (int)$headerData['bill_id'] : null;
    $vendorId = !empty($headerData['vendor_id']) ? (int)$headerData['vendor_id'] : 0;
    $returnDate = !empty($headerData['return_date']) ? (string)$headerData['return_date'] : date('Y-m-d');
    $refundMethod = (string)($headerData['refund_method'] ?? 'vendor_credit');
    $reason = trim((string)($headerData['reason'] ?? 'Defective / Damaged items'));
    $notes = trim((string)($headerData['notes'] ?? ''));

    try {
        $db->beginTransaction();

        // 1. Resolve & validate vendor
        if ($poId) {
            $stmtPO = $db->prepare('SELECT * FROM purchase_orders WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmtPO->execute(['id' => $poId, 'bid' => $actualBid]);
            $po = $stmtPO->fetch();
            if (!$po) {
                throw new Exception('Purchase Order not found for this business.');
            }
            if ($vendorId <= 0) {
                $vendorId = (int)$po['vendor_id'];
            }
        } elseif ($billId) {
            $stmtBill = $db->prepare('SELECT * FROM purchase_bills WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmtBill->execute(['id' => $billId, 'bid' => $actualBid]);
            $bill = $stmtBill->fetch();
            if (!$bill) {
                throw new Exception('Purchase Bill not found for this business.');
            }
            if ($vendorId <= 0) {
                $vendorId = (int)$bill['vendor_id'];
            }
        }

        if ($vendorId <= 0) {
            throw new Exception('Supplier / Vendor selection is required.');
        }

        $stmtVen = $db->prepare('SELECT * FROM vendors WHERE id = :id AND business_id = :bid LIMIT 1');
        $stmtVen->execute(['id' => $vendorId, 'bid' => $actualBid]);
        $vendor = $stmtVen->fetch();
        if (!$vendor) {
            throw new Exception('Vendor not found.');
        }

        // 2. Generate Unique Return / Debit Note Reference (e.g. DN-20261027-0001)
        $returnNumber = generate_unique_reference('purchase_returns', 'return_number', 'DN-', $db);

        // 3. Process Items & Totals
        $subtotal = 0.00;
        $totalTax = 0.00;
        $grandTotal = 0.00;
        $validItems = [];

        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            $cost = (float)($it['unit_cost'] ?? 0.00);
            $taxPct = (float)($it['tax_percent'] ?? 0.00);
            $itemReason = trim((string)($it['reason'] ?? $reason));
            $poItemId = !empty($it['po_item_id']) ? (int)$it['po_item_id'] : null;

            if ($pid <= 0 || $qty <= 0) {
                continue; // Skip invalid or zero qty lines
            }

            // Fetch product snapshot & verify tenant ownership
            $stmtP = $db->prepare('SELECT * FROM products WHERE id = :id AND business_id = :bid LIMIT 1 FOR UPDATE');
            $stmtP->execute(['id' => $pid, 'bid' => $actualBid]);
            $prod = $stmtP->fetch();
            if (!$prod) {
                throw new Exception("Product ID #{$pid} not found in this business.");
            }

            $prodName = !empty($it['product_name']) ? (string)$it['product_name'] : (string)$prod['name'];
            $prodSku = !empty($it['product_sku']) ? (string)$it['product_sku'] : (string)$prod['sku'];
            if ($cost <= 0) {
                $cost = (float)$prod['cost_price'];
            }

            $lineSub = round($cost * $qty, 2);
            $lineTax = round($lineSub * ($taxPct / 100), 2);
            $lineTot = round($lineSub + $lineTax, 2);

            $subtotal += $lineSub;
            $totalTax += $lineTax;
            $grandTotal += $lineTot;

            $validItems[] = [
                'product_id' => $pid,
                'po_item_id' => $poItemId,
                'product_name' => $prodName,
                'product_sku' => $prodSku,
                'unit_cost' => $cost,
                'quantity' => $qty,
                'tax_percent' => $taxPct,
                'tax_amount' => $lineTax,
                'line_total' => $lineTot,
                'reason' => $itemReason,
                'before_stock' => (int)$prod['stock_quantity'],
            ];
        }

        if (empty($validItems)) {
            throw new Exception('Please specify at least one product with quantity greater than zero.');
        }

        // 4. Insert purchase_returns record
        $stmtPR = $db->prepare('
            INSERT INTO purchase_returns (
                business_id, return_number, purchase_order_id, bill_id, vendor_id, user_id,
                return_date, subtotal, tax_amount, total_amount, refund_method, status, reason, notes,
                created_at, updated_at
            ) VALUES (
                :biz_id, :rnum, :poid, :bill_id, :vid, :uid,
                :rdate, :subtotal, :tax_amount, :total, :method, "completed", :reason, :notes,
                NOW(), NOW()
            )
        ');
        $stmtPR->execute([
            'biz_id' => $actualBid,
            'rnum' => $returnNumber,
            'poid' => $poId ?: null,
            'bill_id' => $billId ?: null,
            'vid' => $vendorId,
            'uid' => $actualUserId ?: null,
            'rdate' => $returnDate,
            'subtotal' => $subtotal,
            'tax_amount' => $totalTax,
            'total' => $grandTotal,
            'method' => $refundMethod,
            'reason' => $reason ?: null,
            'notes' => $notes ?: null,
        ]);
        $returnId = (int)$db->lastInsertId();

        // 5. Insert return items, deduct product stock, log inventory movements, and update PO item returned qty
        $stmtItem = $db->prepare('
            INSERT INTO purchase_return_items (
                purchase_return_id, product_id, product_name, product_sku, unit_cost, quantity,
                tax_percent, tax_amount, line_total, reason, created_at
            ) VALUES (
                :pr_id, :pid, :pname, :psku, :cost, :qty,
                :tax_pct, :tax_amt, :lt, :reason, NOW()
            )
        ');

        $stmtStockDec = $db->prepare('
            UPDATE products
            SET stock_quantity = stock_quantity - :qty, updated_at = NOW()
            WHERE id = :id AND business_id = :bid
        ');

        $stmtMoveLog = $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :biz_id, :pid, :uid, "out", :change, :before, :after, :reason, NOW()
            )
        ');

        $stmtUpdatePoi = $db->prepare('
            UPDATE purchase_order_items
            SET quantity_returned = quantity_returned + :qty
            WHERE id = :poi_id
        ');

        $vendorDisplayName = (string)($vendor['company_name'] ?: $vendor['name']);

        foreach ($validItems as $vi) {
            $stmtItem->execute([
                'pr_id' => $returnId,
                'pid' => $vi['product_id'],
                'pname' => $vi['product_name'],
                'psku' => $vi['product_sku'],
                'cost' => $vi['unit_cost'],
                'qty' => $vi['quantity'],
                'tax_pct' => $vi['tax_percent'],
                'tax_amt' => $vi['tax_amount'],
                'lt' => $vi['line_total'],
                'reason' => $vi['reason'],
            ]);

            // Deduct inventory stock
            $stmtStockDec->execute([
                'qty' => $vi['quantity'],
                'id' => $vi['product_id'],
                'bid' => $actualBid,
            ]);

            $afterStock = $vi['before_stock'] - $vi['quantity'];

            // Log movement
            $movementReason = "Debit Note #{$returnNumber} return to {$vendorDisplayName} - " . ($vi['reason'] ?: 'Purchase Return');
            $stmtMoveLog->execute([
                'biz_id' => $actualBid,
                'pid' => $vi['product_id'],
                'uid' => $actualUserId ?: null,
                'change' => -$vi['quantity'],
                'before' => $vi['before_stock'],
                'after' => $afterStock,
                'reason' => $movementReason,
            ]);

            // Update PO item return count if linked
            if (!empty($vi['po_item_id'])) {
                $stmtUpdatePoi->execute([
                    'qty' => $vi['quantity'],
                    'poi_id' => $vi['po_item_id'],
                ]);
            }
        }

        // 6. Adjust vendor balance (Vendor Credit Note / Debit Note reduces outstanding payable)
        if ($refundMethod === 'vendor_credit') {
            $stmtVenAdj = $db->prepare('
                UPDATE vendors
                SET outstanding_balance = GREATEST(0, outstanding_balance - :amt), updated_at = NOW()
                WHERE id = :id AND business_id = :bid
            ');
            $stmtVenAdj->execute(['amt' => $grandTotal, 'id' => $vendorId, 'bid' => $actualBid]);
        }

        $db->commit();
        return [
            'success' => true,
            'return_id' => $returnId,
            'return_number' => $returnNumber,
            'total_amount' => $grandTotal,
            'subtotal' => $subtotal,
            'tax_amount' => $totalTax,
            'refund_method' => $refundMethod,
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get list of purchase returns with filters
 */
function get_purchase_returns(array $filters = [], int $limit = 50, int $offset = 0, ?int $businessId = null): array {
    ensure_purchase_returns_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $sql = '
        SELECT pr.*,
               v.name AS vendor_name, v.company_name AS vendor_company, v.phone AS vendor_phone, v.email AS vendor_email,
               po.po_number,
               pb.bill_number,
               COALESCE(u.name, "Staff") AS creator_name,
               (SELECT COUNT(*) FROM purchase_return_items pri WHERE pri.purchase_return_id = pr.id) AS items_count,
               (SELECT COALESCE(SUM(pri.quantity), 0) FROM purchase_return_items pri WHERE pri.purchase_return_id = pr.id) AS total_units_returned
        FROM purchase_returns pr
        LEFT JOIN vendors v ON v.id = pr.vendor_id AND v.business_id = :bid_v
        LEFT JOIN purchase_orders po ON po.id = pr.purchase_order_id AND po.business_id = :bid_po
        LEFT JOIN purchase_bills pb ON pb.id = pr.bill_id AND pb.business_id = :bid_pb
        LEFT JOIN users u ON u.id = pr.user_id
        WHERE pr.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_v' => $bid,
        'bid_po' => $bid,
        'bid_pb' => $bid,
    ];

    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (pr.return_number LIKE :s1 OR v.name LIKE :s2 OR v.company_name LIKE :s3 OR po.po_number LIKE :s4 OR pb.bill_number LIKE :s5)';
        $params['s1'] = "%{$search}%";
        $params['s2'] = "%{$search}%";
        $params['s3'] = "%{$search}%";
        $params['s4'] = "%{$search}%";
        $params['s5'] = "%{$search}%";
    }

    $vendorId = !empty($filters['vendor_id']) ? (int)$filters['vendor_id'] : 0;
    if ($vendorId > 0) {
        $sql .= ' AND pr.vendor_id = :vendor_id';
        $params['vendor_id'] = $vendorId;
    }

    $refundMethod = trim((string)($filters['refund_method'] ?? ''));
    if ($refundMethod !== '') {
        $sql .= ' AND pr.refund_method = :rmethod';
        $params['rmethod'] = $refundMethod;
    }

    $dateFrom = trim((string)($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $sql .= ' AND pr.return_date >= :dfrom';
        $params['dfrom'] = $dateFrom;
    }

    $dateTo = trim((string)($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $sql .= ' AND pr.return_date <= :dto';
        $params['dto'] = $dateTo;
    }

    $sql .= ' ORDER BY pr.id DESC LIMIT :limit OFFSET :offset';

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get total purchase returns count matching filters
 */
function get_purchase_returns_count(array $filters = [], ?int $businessId = null): int {
    ensure_purchase_returns_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $sql = '
        SELECT COUNT(*)
        FROM purchase_returns pr
        LEFT JOIN vendors v ON v.id = pr.vendor_id AND v.business_id = :bid_v
        LEFT JOIN purchase_orders po ON po.id = pr.purchase_order_id AND po.business_id = :bid_po
        WHERE pr.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_v' => $bid,
        'bid_po' => $bid,
    ];

    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (pr.return_number LIKE :s1 OR v.name LIKE :s2 OR v.company_name LIKE :s3 OR po.po_number LIKE :s4)';
        $params['s1'] = "%{$search}%";
        $params['s2'] = "%{$search}%";
        $params['s3'] = "%{$search}%";
        $params['s4'] = "%{$search}%";
    }

    $vendorId = !empty($filters['vendor_id']) ? (int)$filters['vendor_id'] : 0;
    if ($vendorId > 0) {
        $sql .= ' AND pr.vendor_id = :vendor_id';
        $params['vendor_id'] = $vendorId;
    }

    $refundMethod = trim((string)($filters['refund_method'] ?? ''));
    if ($refundMethod !== '') {
        $sql .= ' AND pr.refund_method = :rmethod';
        $params['rmethod'] = $refundMethod;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Aggregate KPI statistics for Purchase Returns
 */
function get_purchase_returns_kpis(?int $businessId = null): array {
    ensure_purchase_returns_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmt = $db->prepare('
        SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(total_amount), 0) AS total_amount,
            COALESCE(SUM(CASE WHEN refund_method = "vendor_credit" THEN total_amount ELSE 0 END), 0) AS total_credit,
            COALESCE(SUM(CASE WHEN refund_method IN ("cash", "bank") THEN total_amount ELSE 0 END), 0) AS total_refunded,
            COALESCE(SUM(CASE WHEN refund_method = "replacement" THEN total_amount ELSE 0 END), 0) AS total_replacement
        FROM purchase_returns
        WHERE business_id = :bid
    ');
    $stmt->execute(['bid' => $bid]);
    $kpi = $stmt->fetch();
    return $kpi ?: [
        'total_count' => 0,
        'total_amount' => 0.00,
        'total_credit' => 0.00,
        'total_refunded' => 0.00,
        'total_replacement' => 0.00,
    ];
}

/**
 * Fetch a single Purchase Return by ID with vendor, business & line item details
 */
function get_purchase_return_by_id(int $id, ?int $businessId = null): ?array {
    ensure_purchase_returns_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmt = $db->prepare('
        SELECT pr.*,
               v.name AS vendor_name, v.company_name AS vendor_company, v.phone AS vendor_phone,
               v.email AS vendor_email, v.address AS vendor_address, v.gstin AS vendor_gstin,
               po.po_number, po.created_at AS po_date,
               pb.bill_number, pb.bill_date,
               COALESCE(u.name, "Staff") AS creator_name,
               b.name AS business_name, b.legal_name AS business_legal_name,
               b.email AS business_email, b.phone AS business_phone, b.address AS business_address,
               b.city AS business_city, b.state AS business_state, b.pincode AS business_pincode,
               b.tax_id AS business_tax_id, b.currency, b.currency_symbol
        FROM purchase_returns pr
        LEFT JOIN vendors v ON v.id = pr.vendor_id AND v.business_id = :bid_v
        LEFT JOIN purchase_orders po ON po.id = pr.purchase_order_id AND po.business_id = :bid_po
        LEFT JOIN purchase_bills pb ON pb.id = pr.bill_id AND pb.business_id = :bid_pb
        LEFT JOIN users u ON u.id = pr.user_id
        LEFT JOIN businesses b ON b.id = pr.business_id
        WHERE pr.id = :id AND pr.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute([
        'id' => $id,
        'bid' => $bid,
        'bid_v' => $bid,
        'bid_po' => $bid,
        'bid_pb' => $bid,
    ]);
    $ret = $stmt->fetch();
    if (!$ret) {
        return null;
    }

    // Try fetching GSTIN from gst_settings if not on businesses table
    if (empty($ret['business_tax_id'])) {
        try {
            $stmtGst = $db->prepare('SELECT gstin FROM gst_settings WHERE business_id = :bid LIMIT 1');
            $stmtGst->execute(['bid' => $bid]);
            $gstVal = $stmtGst->fetchColumn();
            if ($gstVal) {
                $ret['business_tax_id'] = $gstVal;
            }
        } catch (Exception $ign) {}
    }

    $ret['items'] = get_purchase_return_items((int)$ret['id']);
    return $ret;
}

/**
 * Fetch line items for a given Purchase Return ID
 */
function get_purchase_return_items(int $returnId): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT pri.*, p.name AS current_prod_name, p.sku AS current_prod_sku, p.barcode
        FROM purchase_return_items pri
        LEFT JOIN products p ON p.id = pri.product_id
        WHERE pri.purchase_return_id = :ret_id
        ORDER BY pri.id ASC
    ');
    $stmt->execute(['ret_id' => $returnId]);
    return $stmt->fetchAll();
}

if (!function_exists('record_vendor_payment')) {
    /**
     * Record vendor payment / settlement ledger entry
     */
    function record_vendor_payment(
        int $vendorId,
        float $amount,
        ?int $poId = null,
        string $method = 'bank_transfer',
        string $ref = '',
        string $notes = '',
        ?int $userId = null,
        ?int $businessId = null
    ): array {
        $db = get_db();
        $bid = $businessId ?: current_business_id();

        try {
            $db->beginTransaction();

            $paymentNumber = generate_unique_reference('vendor_payments', 'payment_number', 'VPAY-', $db);

            $stmtVP = $db->prepare('
                INSERT INTO vendor_payments (
                    business_id, payment_number, vendor_id, purchase_order_id, user_id, amount,
                    payment_mode, reference_number, notes, status, created_at
                ) VALUES (
                    :biz_id, :pnum, :vid, :poid, :uid, :amt,
                    :method, :ref, :notes, "paid", NOW()
                )
            ');
            $stmtVP->execute([
                'biz_id' => $bid,
                'pnum' => $paymentNumber,
                'vid' => $vendorId,
                'poid' => $poId ?: null,
                'uid' => $userId ?: null,
                'amt' => $amount,
                'method' => $method,
                'ref' => trim($ref) ?: null,
                'notes' => trim($notes) ?: null,
            ]);
            $payId = (int)$db->lastInsertId();

            $stmtVen = $db->prepare('
                UPDATE vendors
                SET outstanding_balance = GREATEST(0, outstanding_balance - :amt), updated_at = NOW()
                WHERE id = :id AND business_id = :bid
            ');
            $stmtVen->execute(['amt' => $amount, 'id' => $vendorId, 'bid' => $bid]);

            $db->commit();
            return ['success' => true, 'payment_id' => $payId, 'payment_number' => $paymentNumber];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * Convert numeric amount into formal Indian Currency Words
 * e.g. 1540.50 => "One Thousand Five Hundred Forty Rupees and Fifty Paise Only"
 */
function number_to_indian_currency_words(float $number): string {
    $number = round($number, 2);
    $integerPart = (int)floor($number);
    $decimalPart = (int)round(($number - $integerPart) * 100);

    if ($integerPart === 0 && $decimalPart === 0) {
        return 'Zero Rupees Only';
    }

    $ones = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
    ];
    $tens = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
    ];

    $convertTwoDigits = function (int $n) use ($ones, $tens): string {
        if ($n < 20) return $ones[$n];
        $t = (int)floor($n / 10);
        $r = $n % 10;
        return trim($tens[$t] . ' ' . $ones[$r]);
    };

    $convertThreeDigits = function (int $n) use ($ones, $convertTwoDigits): string {
        $h = (int)floor($n / 100);
        $rem = $n % 100;
        $res = '';
        if ($h > 0) {
            $res .= $ones[$h] . ' Hundred ';
        }
        if ($rem > 0) {
            $res .= $convertTwoDigits($rem);
        }
        return trim($res);
    };

    $parts = [];
    if ($integerPart >= 10000000) {
        $crore = (int)floor($integerPart / 10000000);
        $integerPart %= 10000000;
        $parts[] = $convertTwoDigits($crore) . ' Crore';
    }
    if ($integerPart >= 100000) {
        $lakh = (int)floor($integerPart / 100000);
        $integerPart %= 100000;
        $parts[] = $convertTwoDigits($lakh) . ' Lakh';
    }
    if ($integerPart >= 1000) {
        $thousand = (int)floor($integerPart / 1000);
        $integerPart %= 1000;
        $parts[] = $convertTwoDigits($thousand) . ' Thousand';
    }
    if ($integerPart > 0) {
        $parts[] = $convertThreeDigits($integerPart);
    }

    $words = implode(' ', $parts);
    $words = $words ? $words . ' Rupees' : '';

    if ($decimalPart > 0) {
        $paiseWords = $convertTwoDigits($decimalPart) . ' Paise';
        $words = $words ? $words . ' and ' . $paiseWords : $paiseWords;
    }

    return trim($words) . ' Only';
}

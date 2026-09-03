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

    if ($userId) {
        $stmtU = $db->prepare('SELECT id FROM users WHERE id = :id');
        $stmtU->execute(['id' => $userId]);
        if (!$stmtU->fetch()) $userId = null;
    }

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
            $pName = trim((string)($item['product_name'] ?? ''));
            $pSku = trim((string)($item['product_sku'] ?? ''));

            if ($qty <= 0) continue;

            if ($productId > 0) {
                $stmtP = $db->prepare('SELECT name, sku FROM products WHERE id = :id AND business_id = :bid');
                $stmtP->execute(['id' => $productId, 'bid' => $bid]);
                $p = $stmtP->fetch();
                if ($p) {
                    $pName = $p['name'];
                    $pSku = $p['sku'];
                }
            }

            if ($pName === '') {
                $pName = 'Ordered Item #' . (count($processedItems) + 1);
            }
            if ($pSku === '') {
                $pSku = 'SKU-' . strtoupper(substr(uniqid(), -6));
            }

            if ($productId <= 0) {
                $stmtCatId = $db->prepare('SELECT id FROM categories WHERE business_id = :bid LIMIT 1');
                $stmtCatId->execute(['bid' => $bid]);
                $catId = (int)$stmtCatId->fetchColumn() ?: 1;

                $stmtInsProd = $db->prepare('
                    INSERT INTO products (
                        business_id, category_id, name, sku, barcode, cost_price, selling_price,
                        stock_quantity, status, created_at, updated_at
                    ) VALUES (
                        :biz_id, :cat_id, :name, :sku, :barcode, :cost, :price, 0, "active", NOW(), NOW()
                    )
                ');
                $stmtInsProd->execute([
                    'biz_id' => $bid,
                    'cat_id' => $catId,
                    'name' => $pName,
                    'sku' => $pSku,
                    'barcode' => $pSku,
                    'cost' => $unitCost,
                    'price' => round($unitCost * 1.3, 2),
                ]);
                $productId = (int)$db->lastInsertId();
            }

            $lineSubtotal = round($unitCost * $qty, 2);
            $lineTax = round(($lineSubtotal * $taxPercent) / 100, 2);
            $lineTotal = $lineSubtotal + $lineTax;

            $subtotal += $lineSubtotal;
            $totalTax += $lineTax;

            $processedItems[] = [
                'product_id' => $productId,
                'product_name' => $pName,
                'product_sku' => $pSku,
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

function receive_purchase_order_items(int $poId, array $receivingList, ?int $userId = null, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    if ($userId) {
        $stmtU = $db->prepare('SELECT id FROM users WHERE id = :id');
        $stmtU->execute(['id' => $userId]);
        if (!$stmtU->fetch()) $userId = null;
    }

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

/* =========================================================================
   3. FULL PURCHASES SCHEMA & LOCATIONS
   ========================================================================= */

function ensure_purchases_full_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = get_db();

    // 1. purchase_receives table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_receives` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `receive_number` VARCHAR(50) NOT NULL,
            `purchase_order_id` INT UNSIGNED NOT NULL,
            `vendor_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NULL,
            `location_name` VARCHAR(191) NOT NULL DEFAULT 'Head Office',
            `receive_date` DATE NOT NULL,
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_pr_biz` (`business_id`),
            INDEX `idx_pr_po` (`purchase_order_id`),
            INDEX `idx_pr_vendor` (`vendor_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. purchase_receive_items table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_receive_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `receive_id` INT UNSIGNED NOT NULL,
            `po_item_id` INT UNSIGNED NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `product_name` VARCHAR(191) NOT NULL,
            `product_sku` VARCHAR(100) NOT NULL,
            `quantity_received` INT NOT NULL DEFAULT 1,
            `unit_cost` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_pri_rec` (`receive_id`),
            INDEX `idx_pri_prod` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. purchase_bills table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_bills` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `bill_number` VARCHAR(50) NOT NULL,
            `purchase_order_id` INT UNSIGNED NULL,
            `vendor_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NULL,
            `bill_date` DATE NOT NULL,
            `due_date` DATE NULL,
            `reference_number` VARCHAR(100) NULL,
            `location_name` VARCHAR(191) NOT NULL DEFAULT 'Head Office',
            `subtotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `amount_paid` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `balance_due` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `status` ENUM('unpaid', 'partially_paid', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'unpaid',
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_pb_biz` (`business_id`),
            INDEX `idx_pb_vendor` (`vendor_id`),
            INDEX `idx_pb_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. purchase_bill_items table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_bill_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `bill_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NULL,
            `product_name` VARCHAR(191) NOT NULL,
            `product_sku` VARCHAR(100) NOT NULL,
            `quantity` INT NOT NULL DEFAULT 1,
            `unit_cost` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `tax_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_pbi_bill` (`bill_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 5. vendor_payments table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `vendor_payments` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `payment_number` VARCHAR(50) NOT NULL,
            `vendor_id` INT UNSIGNED NOT NULL,
            `bill_id` INT UNSIGNED NULL,
            `purchase_order_id` INT UNSIGNED NULL,
            `user_id` INT UNSIGNED NULL,
            `payment_date` DATE NOT NULL,
            `location_name` VARCHAR(191) NOT NULL DEFAULT 'Head Office',
            `payment_mode` VARCHAR(50) NOT NULL DEFAULT 'Cash',
            `reference_number` VARCHAR(100) NULL,
            `amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `unused_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `status` ENUM('paid', 'draft', 'cancelled') NOT NULL DEFAULT 'paid',
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_vp_biz` (`business_id`),
            INDEX `idx_vp_vendor` (`vendor_id`),
            INDEX `idx_vp_bill` (`bill_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Ensure missing columns in existing vendor_payments if table existed
    try {
        $cols = $db->query("SHOW COLUMNS FROM vendor_payments")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('bill_id', $cols, true)) {
            $db->exec("ALTER TABLE vendor_payments ADD COLUMN `bill_id` INT UNSIGNED NULL AFTER `vendor_id`");
        }
        if (!in_array('payment_date', $cols, true)) {
            $db->exec("ALTER TABLE vendor_payments ADD COLUMN `payment_date` DATE NOT NULL DEFAULT (CURRENT_DATE) AFTER `user_id`");
        }
        if (!in_array('location_name', $cols, true)) {
            $db->exec("ALTER TABLE vendor_payments ADD COLUMN `location_name` VARCHAR(191) NOT NULL DEFAULT 'Head Office' AFTER `payment_date`");
        }
        if (!in_array('payment_mode', $cols, true)) {
            $db->exec("ALTER TABLE vendor_payments ADD COLUMN `payment_mode` VARCHAR(50) NOT NULL DEFAULT 'Cash' AFTER `location_name`");
        }
        if (!in_array('reference_number', $cols, true)) {
            $db->exec("ALTER TABLE vendor_payments ADD COLUMN `reference_number` VARCHAR(100) NULL AFTER `payment_mode`");
        }
        if (!in_array('unused_amount', $cols, true)) {
            $db->exec("ALTER TABLE vendor_payments ADD COLUMN `unused_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER `amount`");
        }
        if (!in_array('updated_at', $cols, true)) {
            $db->exec("ALTER TABLE vendor_payments ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        $db->exec("ALTER TABLE vendor_payments MODIFY COLUMN `user_id` INT UNSIGNED NULL");
    } catch (Exception $ign) {}
}

function get_purchase_locations(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $locations = ['Head Office'];
    try {
        $stmt = $db->prepare('SELECT name FROM outlets WHERE business_id = :bid AND status = "active" ORDER BY id ASC');
        $stmt->execute(['bid' => $bid]);
        $outlets = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($outlets)) {
            $locations = array_unique(array_merge($locations, $outlets));
        }
    } catch (Exception $e) {}
    return array_values($locations);
}

/* =========================================================================
   4. PURCHASE RECEIVES (GOODS RECEIVING LOGS)
   ========================================================================= */

function get_purchase_receives(string $search = '', int $limit = 50, ?int $businessId = null): array {
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $sql = '
        SELECT pr.*, po.po_number, v.name AS vendor_name, u.name AS receiver_name,
               (SELECT COUNT(*) FROM purchase_receive_items pri WHERE pri.receive_id = pr.id) AS items_count,
               (SELECT SUM(pri.quantity_received) FROM purchase_receive_items pri WHERE pri.receive_id = pr.id) AS total_received_qty
        FROM purchase_receives pr
        JOIN purchase_orders po ON po.id = pr.purchase_order_id AND po.business_id = :bid_po
        JOIN vendors v ON v.id = pr.vendor_id AND v.business_id = :bid_v
        LEFT JOIN users u ON u.id = pr.user_id
        WHERE pr.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_po' => $bid,
        'bid_v' => $bid,
    ];
    if ($search !== '') {
        $sql .= ' AND (pr.receive_number LIKE :s1 OR po.po_number LIKE :s2 OR v.name LIKE :s3)';
        $params['s1'] = "%{$search}%";
        $params['s2'] = "%{$search}%";
        $params['s3'] = "%{$search}%";
    }
    $sql .= ' ORDER BY pr.id DESC LIMIT ' . max(1, $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_purchase_receive_by_id(int $id, ?int $businessId = null): ?array {
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmt = $db->prepare('
        SELECT pr.*, po.po_number, v.name AS vendor_name, v.company_name AS vendor_company,
               v.phone AS vendor_phone, u.name AS receiver_name
        FROM purchase_receives pr
        JOIN purchase_orders po ON po.id = pr.purchase_order_id AND po.business_id = :bid_po
        JOIN vendors v ON v.id = pr.vendor_id AND v.business_id = :bid_v
        LEFT JOIN users u ON u.id = pr.user_id
        WHERE pr.id = :id AND pr.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid, 'bid_po' => $bid, 'bid_v' => $bid]);
    $rec = $stmt->fetch();
    if (!$rec) return null;

    $stmtItems = $db->prepare('SELECT * FROM purchase_receive_items WHERE receive_id = :rid ORDER BY id ASC');
    $stmtItems->execute(['rid' => $id]);
    $rec['items'] = $stmtItems->fetchAll();
    return $rec;
}

function create_purchase_receive_log(
    int $poId,
    array $receivingList,
    string $receiveDate = '',
    string $locationName = 'Head Office',
    string $notes = '',
    ?int $userId = null,
    ?int $businessId = null
): array {
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    if ($userId) {
        $stmtU = $db->prepare('SELECT id FROM users WHERE id = :id');
        $stmtU->execute(['id' => $userId]);
        if (!$stmtU->fetch()) $userId = null;
    }

    try {
        $db->beginTransaction();

        $po = get_purchase_order_by_id($poId, $bid);
        if (!$po) throw new Exception("Purchase Order #{$poId} not found.");

        require_once __DIR__ . '/orders_db.php';
        $receiveNumber = generate_unique_reference('purchase_receives', 'receive_number', 'PRCV-', $db);
        $cleanDate = !empty($receiveDate) ? $receiveDate : date('Y-m-d');

        // Insert Receive record
        $stmtRec = $db->prepare('
            INSERT INTO purchase_receives (
                business_id, receive_number, purchase_order_id, vendor_id, user_id,
                location_name, receive_date, notes, created_at, updated_at
            ) VALUES (
                :biz_id, :rec_num, :po_id, :vendor_id, :user_id,
                :location, :rec_date, :notes, NOW(), NOW()
            )
        ');
        $stmtRec->execute([
            'biz_id' => $bid,
            'rec_num' => $receiveNumber,
            'po_id' => $poId,
            'vendor_id' => (int)$po['vendor_id'],
            'user_id' => $userId,
            'location' => $locationName ?: 'Head Office',
            'rec_date' => $cleanDate,
            'notes' => trim($notes) ?: null,
        ]);
        $receiveId = (int) $db->lastInsertId();

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

        $stmtRecItem = $db->prepare('
            INSERT INTO purchase_receive_items (
                receive_id, po_item_id, product_id, product_name, product_sku, quantity_received, unit_cost, created_at
            ) VALUES (
                :rec_id, :po_item_id, :product_id, :product_name, :product_sku, :qty, :cost, NOW()
            )
        ');

        $totalUnitsReceived = 0;
        foreach ($receivingList as $rec) {
            $poiId = (int) ($rec['po_item_id'] ?? 0);
            $qtyToReceive = (int) ($rec['quantity_to_receive'] ?? 0);
            if ($qtyToReceive <= 0) continue;

            $foundItem = null;
            foreach ($po['items'] as $it) {
                if ((int)$it['id'] === $poiId) {
                    $foundItem = $it;
                    break;
                }
            }
            if (!$foundItem) throw new Exception("PO Item ID {$poiId} not in this order.");

            $rem = (int)$foundItem['quantity_ordered'] - (int)$foundItem['quantity_received'];
            if ($qtyToReceive > $rem) {
                throw new Exception("Cannot receive {$qtyToReceive} units for {$foundItem['product_name']}. Only {$rem} remaining.");
            }

            // 1. Update PO Item
            $stmtUpdatePOI->execute(['qty' => $qtyToReceive, 'id' => $poiId]);

            // 2. Insert Receive Item
            $stmtRecItem->execute([
                'rec_id' => $receiveId,
                'po_item_id' => $poiId,
                'product_id' => (int)$foundItem['product_id'],
                'product_name' => $foundItem['product_name'],
                'product_sku' => $foundItem['product_sku'],
                'qty' => $qtyToReceive,
                'cost' => (float)$foundItem['unit_cost'],
            ]);

            // 3. Increment Product Stock
            $prodId = (int) $foundItem['product_id'];
            $stmtCur = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :bid FOR UPDATE');
            $stmtCur->execute(['id' => $prodId, 'bid' => $bid]);
            $currStock = (int) $stmtCur->fetchColumn();

            $stmtStockInc->execute(['qty' => $qtyToReceive, 'id' => $prodId, 'bid' => $bid]);

            // 4. Movement Log
            $stmtMoveLog->execute([
                'biz_id' => $bid,
                'product_id' => $prodId,
                'user_id' => $userId,
                'quantity_change' => $qtyToReceive,
                'quantity_before' => $currStock,
                'quantity_after' => $currStock + $qtyToReceive,
                'reason' => "Purchase Receive #{$receiveNumber} (PO #{$po['po_number']})",
            ]);

            $totalUnitsReceived += $qtyToReceive;
        }

        if ($totalUnitsReceived === 0) {
            throw new Exception("Please specify at least 1 unit to receive.");
        }

        // Check PO status
        $updatedPO = get_purchase_order_by_id($poId, $bid);
        $allDone = true;
        foreach ($updatedPO['items'] as $uit) {
            if ((int)$uit['quantity_received'] < (int)$uit['quantity_ordered']) {
                $allDone = false;
                break;
            }
        }
        $newPoStatus = $allDone ? 'received' : 'partially_received';
        $db->prepare('UPDATE purchase_orders SET status = :st, updated_at = NOW() WHERE id = :id AND business_id = :bid')
            ->execute(['st' => $newPoStatus, 'id' => $poId, 'bid' => $bid]);

        $db->commit();
        return [
            'success' => true,
            'receive_id' => $receiveId,
            'receive_number' => $receiveNumber,
            'total_received' => $totalUnitsReceived,
            'po_status' => $newPoStatus,
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/* =========================================================================
   5. BILLS (PURCHASE INVOICES)
   ========================================================================= */

function get_purchase_bills(string $search = '', string $status = '', int $limit = 50, ?int $businessId = null): array {
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $sql = '
        SELECT pb.*, v.name AS vendor_name, v.phone AS vendor_phone, po.po_number,
               (SELECT COUNT(*) FROM purchase_bill_items pbi WHERE pbi.bill_id = pb.id) AS items_count
        FROM purchase_bills pb
        JOIN vendors v ON v.id = pb.vendor_id AND v.business_id = :bid_v
        LEFT JOIN purchase_orders po ON po.id = pb.purchase_order_id AND po.business_id = :bid_po
        WHERE pb.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_v' => $bid,
        'bid_po' => $bid,
    ];
    if ($search !== '') {
        $sql .= ' AND (pb.bill_number LIKE :s1 OR pb.reference_number LIKE :s2 OR v.name LIKE :s3 OR po.po_number LIKE :s4)';
        $params['s1'] = "%{$search}%";
        $params['s2'] = "%{$search}%";
        $params['s3'] = "%{$search}%";
        $params['s4'] = "%{$search}%";
    }
    if ($status !== '') {
        $sql .= ' AND pb.status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY pb.id DESC LIMIT ' . max(1, $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_purchase_bill_by_id(int $id, ?int $businessId = null): ?array {
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmt = $db->prepare('
        SELECT pb.*, v.name AS vendor_name, v.company_name AS vendor_company, v.phone AS vendor_phone,
               v.email AS vendor_email, v.address AS vendor_address, v.gstin AS vendor_gstin,
               po.po_number, u.name AS creator_name
        FROM purchase_bills pb
        JOIN vendors v ON v.id = pb.vendor_id AND v.business_id = :bid_v
        LEFT JOIN purchase_orders po ON po.id = pb.purchase_order_id AND po.business_id = :bid_po
        LEFT JOIN users u ON u.id = pb.user_id
        WHERE pb.id = :id AND pb.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid, 'bid_v' => $bid, 'bid_po' => $bid]);
    $bill = $stmt->fetch();
    if (!$bill) return null;

    $stmtItems = $db->prepare('SELECT * FROM purchase_bill_items WHERE bill_id = :bid ORDER BY id ASC');
    $stmtItems->execute(['bid' => $id]);
    $bill['items'] = $stmtItems->fetchAll();

    $stmtPayments = $db->prepare('SELECT * FROM vendor_payments WHERE bill_id = :bid ORDER BY id DESC');
    $stmtPayments->execute(['bid' => $id]);
    $bill['payments'] = $stmtPayments->fetchAll();

    return $bill;
}

function create_purchase_bill(array $data, ?int $userId = null, ?int $businessId = null): array {
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    if ($userId) {
        $stmtU = $db->prepare('SELECT id FROM users WHERE id = :id');
        $stmtU->execute(['id' => $userId]);
        if (!$stmtU->fetch()) $userId = null;
    }

    $vendorId = (int) ($data['vendor_id'] ?? 0);
    $items = (array) ($data['items'] ?? []);
    if ($vendorId <= 0) return ['success' => false, 'error' => 'Vendor is required.'];
    if (empty($items)) return ['success' => false, 'error' => 'At least one item is required in the bill.'];

    try {
        $db->beginTransaction();

        $vendor = get_vendor_by_id($vendorId, $bid);
        if (!$vendor) throw new Exception("Vendor not found.");

        require_once __DIR__ . '/orders_db.php';
        $billNumber = trim((string)($data['bill_number'] ?? ''));
        if ($billNumber === '') {
            $billNumber = generate_unique_reference('purchase_bills', 'bill_number', 'BILL-', $db);
        }

        $subtotal = 0.00;
        $taxAmount = 0.00;
        $processedItems = [];

        foreach ($items as $item) {
            $prodId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 1);
            $cost = (float) ($item['unit_cost'] ?? 0.00);
            $taxPercent = (float) ($item['tax_percent'] ?? 0.00);
            $pName = trim((string)($item['product_name'] ?? 'Product Item'));
            $pSku = trim((string)($item['product_sku'] ?? 'SKU-ITEM'));

            if ($prodId > 0) {
                $stmtP = $db->prepare('SELECT name, sku FROM products WHERE id = :id AND business_id = :bid');
                $stmtP->execute(['id' => $prodId, 'bid' => $bid]);
                $p = $stmtP->fetch();
                if ($p) {
                    $pName = $p['name'];
                    $pSku = $p['sku'];
                }
            }

            $lineSub = round($cost * $qty, 2);
            $lineTax = round(($lineSub * $taxPercent) / 100, 2);
            $lineTot = $lineSub + $lineTax;

            $subtotal += $lineSub;
            $taxAmount += $lineTax;

            $processedItems[] = [
                'product_id' => $prodId ?: null,
                'product_name' => $pName,
                'product_sku' => $pSku,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'tax_percent' => $taxPercent,
                'line_total' => $lineTot,
            ];
        }

        $grandTotal = $subtotal + $taxAmount;
        $billDate = !empty($data['bill_date']) ? $data['bill_date'] : date('Y-m-d');
        $dueDate = !empty($data['due_date']) ? $data['due_date'] : date('Y-m-d', strtotime('+30 days'));
        $refNo = trim((string)($data['reference_number'] ?? ''));
        $loc = trim((string)($data['location_name'] ?? 'Head Office')) ?: 'Head Office';
        $poId = !empty($data['purchase_order_id']) ? (int)$data['purchase_order_id'] : null;

        $stmtBill = $db->prepare('
            INSERT INTO purchase_bills (
                business_id, bill_number, purchase_order_id, vendor_id, user_id,
                bill_date, due_date, reference_number, location_name, subtotal,
                tax_amount, total_amount, amount_paid, balance_due, status, notes, created_at, updated_at
            ) VALUES (
                :biz_id, :bill_num, :po_id, :vendor_id, :user_id,
                :bill_date, :due_date, :ref_no, :location, :subtotal,
                :tax_amt, :total_amt, 0.00, :balance_due, "unpaid", :notes, NOW(), NOW()
            )
        ');
        $stmtBill->execute([
            'biz_id' => $bid,
            'bill_num' => $billNumber,
            'po_id' => $poId,
            'vendor_id' => $vendorId,
            'user_id' => $userId,
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'ref_no' => $refNo ?: null,
            'location' => $loc,
            'subtotal' => $subtotal,
            'tax_amt' => $taxAmount,
            'total_amt' => $grandTotal,
            'balance_due' => $grandTotal,
            'notes' => trim((string)($data['notes'] ?? '')) ?: null,
        ]);
        $billId = (int) $db->lastInsertId();

        $stmtBI = $db->prepare('
            INSERT INTO purchase_bill_items (
                bill_id, product_id, product_name, product_sku, quantity, unit_cost, tax_percent, line_total, created_at
            ) VALUES (
                :bill_id, :product_id, :product_name, :product_sku, :qty, :cost, :tax, :line_tot, NOW()
            )
        ');
        foreach ($processedItems as $bi) {
            $stmtBI->execute([
                'bill_id' => $billId,
                'product_id' => $bi['product_id'],
                'product_name' => $bi['product_name'],
                'product_sku' => $bi['product_sku'],
                'qty' => $bi['quantity'],
                'cost' => $bi['unit_cost'],
                'tax' => $bi['tax_percent'],
                'line_tot' => $bi['line_total'],
            ]);
        }

        $db->commit();
        return ['success' => true, 'bill_id' => $billId, 'bill_number' => $billNumber, 'total_amount' => $grandTotal];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function convert_po_to_bill(
    int $poId,
    string $billDate = '',
    string $dueDate = '',
    string $refNo = '',
    ?int $userId = null,
    ?int $businessId = null
): array {
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $po = get_purchase_order_by_id($poId, $bid);
    if (!$po) return ['success' => false, 'error' => 'Purchase Order not found.'];

    $items = [];
    foreach ($po['items'] as $poi) {
        $items[] = [
            'product_id' => (int)$poi['product_id'],
            'product_name' => $poi['product_name'],
            'product_sku' => $poi['product_sku'],
            'quantity' => (int)$poi['quantity_ordered'],
            'unit_cost' => (float)$poi['unit_cost'],
            'tax_percent' => (float)$poi['tax_percent'],
        ];
    }

    $billData = [
        'purchase_order_id' => $poId,
        'vendor_id' => (int)$po['vendor_id'],
        'bill_date' => $billDate ?: date('Y-m-d'),
        'due_date' => $dueDate ?: date('Y-m-d', strtotime('+30 days')),
        'reference_number' => $refNo ?: $po['po_number'],
        'location_name' => 'Head Office',
        'items' => $items,
        'notes' => 'Converted from Purchase Order #' . $po['po_number'],
    ];

    return create_purchase_bill($billData, $userId, $bid);
}

/* =========================================================================
   6. PAYMENTS MADE (VENDOR PAYMENTS)
   ========================================================================= */

function get_vendor_payments(string $search = '', int $limit = 50, ?int $businessId = null): array {
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $sql = '
        SELECT vp.*, v.name AS vendor_name, v.phone AS vendor_phone, pb.bill_number
        FROM vendor_payments vp
        JOIN vendors v ON v.id = vp.vendor_id AND v.business_id = :bid_v
        LEFT JOIN purchase_bills pb ON pb.id = vp.bill_id AND pb.business_id = :bid_pb
        WHERE vp.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_v' => $bid,
        'bid_pb' => $bid,
    ];
    if ($search !== '') {
        $sql .= ' AND (vp.payment_number LIKE :s1 OR vp.reference_number LIKE :s2 OR v.name LIKE :s3 OR pb.bill_number LIKE :s4)';
        $params['s1'] = "%{$search}%";
        $params['s2'] = "%{$search}%";
        $params['s3'] = "%{$search}%";
        $params['s4'] = "%{$search}%";
    }
    $sql .= ' ORDER BY vp.id DESC LIMIT ' . max(1, $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_vendor_payment_by_id(int $id, ?int $businessId = null): ?array {
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmt = $db->prepare('
        SELECT vp.*, v.name AS vendor_name, v.company_name AS vendor_company, v.phone AS vendor_phone,
               pb.bill_number, pb.total_amount AS bill_total, pb.balance_due AS bill_balance
        FROM vendor_payments vp
        JOIN vendors v ON v.id = vp.vendor_id AND v.business_id = :bid_v
        LEFT JOIN purchase_bills pb ON pb.id = vp.bill_id AND pb.business_id = :bid_pb
        WHERE vp.id = :id AND vp.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid, 'bid_v' => $bid, 'bid_pb' => $bid]);
    $pay = $stmt->fetch();
    return $pay ?: null;
}

function record_vendor_payment(array $data, ?int $userId = null, ?int $businessId = null): array {
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    if ($userId) {
        $stmtU = $db->prepare('SELECT id FROM users WHERE id = :id');
        $stmtU->execute(['id' => $userId]);
        if (!$stmtU->fetch()) $userId = null;
    }

    $vendorId = (int) ($data['vendor_id'] ?? 0);
    $billId = !empty($data['bill_id']) ? (int)$data['bill_id'] : null;
    $amount = (float) ($data['amount'] ?? 0.00);

    if ($vendorId <= 0) return ['success' => false, 'error' => 'Vendor selection is required.'];
    if ($amount <= 0) return ['success' => false, 'error' => 'Payment amount must be greater than zero.'];

    try {
        $db->beginTransaction();

        $vendor = get_vendor_by_id($vendorId, $bid);
        if (!$vendor) throw new Exception("Vendor not found.");

        require_once __DIR__ . '/orders_db.php';
        $payNum = generate_unique_reference('vendor_payments', 'payment_number', 'VPAY-', $db);
        $payDate = !empty($data['payment_date']) ? $data['payment_date'] : date('Y-m-d');
        $payMode = trim((string)($data['payment_mode'] ?? 'Cash')) ?: 'Cash';
        $refNo = trim((string)($data['reference_number'] ?? ''));
        $loc = trim((string)($data['location_name'] ?? 'Head Office')) ?: 'Head Office';
        $notes = trim((string)($data['notes'] ?? ''));

        $unusedAmount = 0.00;

        // If linked to a Bill, update the bill's amount_paid and balance_due
        if ($billId) {
            $bill = get_purchase_bill_by_id($billId, $bid);
            if ($bill) {
                $curPaid = (float) $bill['amount_paid'];
                $curBal = (float) $bill['balance_due'];
                $newPaid = round($curPaid + $amount, 2);
                $newBal = max(0.00, round($curBal - $amount, 2));

                if ($amount > $curBal) {
                    $unusedAmount = round($amount - $curBal, 2);
                }

                $newStatus = ($newBal <= 0.00) ? 'paid' : 'partially_paid';

                $stmtUpdBill = $db->prepare('
                    UPDATE purchase_bills
                    SET amount_paid = :paid, balance_due = :bal, status = :st, updated_at = NOW()
                    WHERE id = :id AND business_id = :bid
                ');
                $stmtUpdBill->execute([
                    'paid' => $newPaid,
                    'bal' => $newBal,
                    'st' => $newStatus,
                    'id' => $billId,
                    'bid' => $bid,
                ]);
            }
        }

        $stmtVP = $db->prepare('
            INSERT INTO vendor_payments (
                business_id, payment_number, vendor_id, bill_id, purchase_order_id, user_id,
                payment_date, location_name, payment_mode, reference_number, amount, unused_amount, status, notes, created_at, updated_at
            ) VALUES (
                :biz_id, :pnum, :vid, :bill_id, :po_id, :user_id,
                :pdate, :loc, :pmode, :ref_no, :amt, :unused, "paid", :notes, NOW(), NOW()
            )
        ');
        $stmtVP->execute([
            'biz_id' => $bid,
            'pnum' => $payNum,
            'vid' => $vendorId,
            'bill_id' => $billId,
            'po_id' => !empty($data['purchase_order_id']) ? (int)$data['purchase_order_id'] : null,
            'user_id' => $userId,
            'pdate' => $payDate,
            'loc' => $loc,
            'pmode' => $payMode,
            'ref_no' => $refNo ?: null,
            'amt' => $amount,
            'unused' => $unusedAmount,
            'notes' => $notes ?: null,
        ]);
        $payId = (int) $db->lastInsertId();

        $db->commit();
        return [
            'success' => true,
            'payment_id' => $payId,
            'payment_number' => $payNum,
            'amount' => $amount,
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

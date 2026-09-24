<?php
/**
 * Multi-Outlet, Multi-Warehouse & Stock Transfers Service for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/products_db.php';

/* =========================================================================
   1. OUTLET OPERATIONS
   ========================================================================= */

function ensure_business_warehouse_baseline(?int $businessId = null): void {
    static $done = [];
    $bid = $businessId ?: current_business_id();
    if (isset($done[$bid])) {
        return;
    }
    $done[$bid] = true;
    $db = get_db();

    $stmt = $db->prepare('
        SELECT id FROM warehouses
        WHERE business_id = :bid AND code = :code
        LIMIT 1
    ');
    $stmt->execute(['bid' => $bid, 'code' => 'WH-CENTRAL']);
    if (!$stmt->fetch()) {
        try {
            $db->prepare('
                INSERT INTO warehouses (business_id, outlet_id, name, code, location, status, created_at, updated_at)
                VALUES (:bid, NULL, :name, :code, :loc, "active", NOW(), NOW())
            ')->execute([
                'bid' => $bid,
                'name' => 'Central Warehouse',
                'code' => 'WH-CENTRAL',
                'loc' => 'Main distribution hub',
            ]);
        } catch (PDOException $e) {
            // Ignore duplicate or schema mismatch on older DBs.
        }
    }
}

function resolve_pos_outlet_id(?int $outletId, ?int $businessId = null): int {
    $bid = $businessId ?: current_business_id();
    if ($outletId !== null && $outletId > 0) {
        $outlet = get_outlet_by_id($outletId, $bid);
        if ($outlet && ($outlet['status'] ?? '') === 'active') {
            return $outletId;
        }
    }
    $active = get_outlets('active', $bid);
    return !empty($active[0]['id']) ? (int) $active[0]['id'] : 1;
}

function get_warehouse_id_for_outlet(int $outletId, ?int $businessId = null): ?int {
    ensure_business_warehouse_baseline($businessId);
    if ($outletId <= 0) {
        return null;
    }
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT id FROM warehouses
        WHERE business_id = :bid AND outlet_id = :oid AND status = "active"
        ORDER BY id ASC
        LIMIT 1
    ');
    $stmt->execute(['bid' => $bid, 'oid' => $outletId]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function warehouse_has_product_stock_row(int $productId, int $warehouseId): bool {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT 1 FROM warehouse_stock
        WHERE product_id = :pid AND warehouse_id = :wid
        LIMIT 1
    ');
    $stmt->execute(['pid' => $productId, 'wid' => $warehouseId]);
    return (bool) $stmt->fetchColumn();
}

function pos_isolates_outlet_stock(?int $businessId = null): bool {
    return count(get_outlets('active', $businessId)) > 1;
}

function product_has_location_stock(int $productId, ?int $businessId = null): bool {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT 1
        FROM warehouse_stock ws
        INNER JOIN warehouses w ON w.id = ws.warehouse_id AND w.business_id = :bid
        WHERE ws.product_id = :pid
        LIMIT 1
    ');
    $stmt->execute(['bid' => $bid, 'pid' => $productId]);
    return (bool) $stmt->fetchColumn();
}

function get_outlet_product_stock(int $productId, int $warehouseId, ?int $businessId = null): int {
    if ($warehouseId > 0 && warehouse_has_product_stock_row($productId, $warehouseId)) {
        return get_product_warehouse_stock($productId, $warehouseId);
    }
    $product = get_product_by_id($productId, $businessId);
    return $product ? (int) $product['stock_quantity'] : 0;
}

/**
 * Quantity this counter may sell. With more than one store, a product that
 * already sits in a warehouse is sold only from this store's warehouse.
 */
function get_pos_counter_stock(int $productId, int $warehouseId, ?int $businessId = null): int {
    $bid = $businessId ?: current_business_id();
    if (pos_isolates_outlet_stock($bid) && product_has_location_stock($productId, $bid)) {
        if ($warehouseId > 0 && warehouse_has_product_stock_row($productId, $warehouseId)) {
            return get_product_warehouse_stock($productId, $warehouseId);
        }
        return 0;
    }
    return get_outlet_product_stock($productId, $warehouseId, $bid);
}

/**
 * Deduct sellable stock for POS from the outlet warehouse when allocated; otherwise product stock.
 *
 * @return array{quantity_before:int, quantity_after:int, used_warehouse:bool}
 */
function pos_deduct_inventory_for_sale(
    PDO $db,
    int $businessId,
    int $productId,
    int $quantity,
    int $warehouseId,
    ?int $userId,
    string $reason,
    bool $strictOutlet = false
): array {
    if ($quantity <= 0) {
        throw new Exception('Invalid sale quantity.');
    }

    $hasWarehouseRow = $warehouseId > 0 && warehouse_has_product_stock_row($productId, $warehouseId);
    if ($hasWarehouseRow || ($strictOutlet && $warehouseId > 0)) {
        $before = $hasWarehouseRow ? get_product_warehouse_stock($productId, $warehouseId) : 0;
        if ($before < $quantity) {
            throw new Exception('Insufficient stock at this store warehouse.');
        }
        $after = $before - $quantity;
        set_product_warehouse_stock($productId, $warehouseId, $after);
        sync_product_stock_from_warehouses($productId, $businessId);

        $stmtMov = $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :biz_id, :product_id, :user_id, "out", :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
            )
        ');
        $stmtMov->execute([
            'biz_id' => $businessId,
            'product_id' => $productId,
            'user_id' => $userId,
            'quantity_change' => -$quantity,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reason' => $reason,
        ]);

        return ['quantity_before' => $before, 'quantity_after' => $after, 'used_warehouse' => true];
    }

    $stmtCur = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :bid FOR UPDATE');
    $stmtCur->execute(['id' => $productId, 'bid' => $businessId]);
    $before = (int) $stmtCur->fetchColumn();
    if ($before < $quantity) {
        throw new Exception('Insufficient stock for this product.');
    }
    $after = max(0, $before - $quantity);
    $db->prepare('UPDATE products SET stock_quantity = :qty, updated_at = NOW() WHERE id = :id AND business_id = :bid')
        ->execute(['qty' => $after, 'id' => $productId, 'bid' => $businessId]);

    $stmtMov = $db->prepare('
        INSERT INTO inventory_movements (
            business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
        ) VALUES (
            :biz_id, :product_id, :user_id, "out", :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
        )
    ');
    $stmtMov->execute([
        'biz_id' => $businessId,
        'product_id' => $productId,
        'user_id' => $userId,
        'quantity_change' => -$quantity,
        'quantity_before' => $before,
        'quantity_after' => $after,
        'reason' => $reason,
    ]);

    return ['quantity_before' => $before, 'quantity_after' => $after, 'used_warehouse' => false];
}

function get_outlets(string $status = '', ?int $businessId = null): array {
    ensure_business_warehouse_baseline($businessId);
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = 'SELECT * FROM outlets WHERE business_id = :biz_id';
    $params = ['biz_id' => $bid];
    if ($status !== '') {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_outlet_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM outlets WHERE id = :id AND business_id = :biz_id LIMIT 1');
    $stmt->execute(['id' => $id, 'biz_id' => $bid]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function save_outlet(array $data, ?int $id = null, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $name = trim((string)($data['name'] ?? ''));
    $code = strtoupper(trim((string)($data['code'] ?? '')));
    $address = trim((string)($data['address'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $gstin = trim((string)($data['gstin'] ?? ''));
    $status = (!empty($data['status']) && in_array($data['status'], ['active', 'inactive'], true)) ? $data['status'] : 'active';

    if ($name === '') return ['success' => false, 'error' => 'Outlet name is required.'];
    if ($code === '') $code = 'OUT-' . strtoupper(substr(uniqid(), -4));

    try {
        if ($id !== null && $id > 0) {
            $stmt = $db->prepare('
                UPDATE outlets 
                SET name = :name, code = :code, address = :address, phone = :phone, email = :email, gstin = :gstin, status = :status, updated_at = NOW()
                WHERE id = :id AND business_id = :biz_id
            ');
            $stmt->execute([
                'name' => $name, 'code' => $code, 'address' => $address ?: null,
                'phone' => $phone ?: null, 'email' => $email ?: null, 'gstin' => $gstin ?: null,
                'status' => $status, 'id' => $id, 'biz_id' => $bid,
            ]);
            return ['success' => true, 'outlet_id' => $id];
        } else {
            $stmt = $db->prepare('
                INSERT INTO outlets (business_id, name, code, address, phone, email, gstin, status, created_at, updated_at)
                VALUES (:biz_id, :name, :code, :address, :phone, :email, :gstin, :status, NOW(), NOW())
            ');
            $stmt->execute([
                'biz_id' => $bid,
                'name' => $name, 'code' => $code, 'address' => $address ?: null,
                'phone' => $phone ?: null, 'email' => $email ?: null, 'gstin' => $gstin ?: null,
                'status' => $status,
            ]);
            $outletId = (int)$db->lastInsertId();

            // Auto-create an associated default warehouse for this new outlet
            $whCode = 'WH-' . $code;
            $stmtWH = $db->prepare('
                INSERT INTO warehouses (business_id, outlet_id, name, code, location, status, created_at, updated_at)
                VALUES (:biz_id, :oid, :name, :code, :loc, "active", NOW(), NOW())
            ');
            $stmtWH->execute([
                'biz_id' => $bid,
                'oid' => $outletId,
                'name' => $name . ' Warehouse',
                'code' => $whCode,
                'loc' => $address ?: 'Main Store Floor',
            ]);

            return ['success' => true, 'outlet_id' => $outletId, 'warehouse_created' => true];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/* =========================================================================
   2. WAREHOUSE OPERATIONS & STOCK
   ========================================================================= */

function get_warehouses(?int $outletId = null, string $status = '', ?int $businessId = null): array {
    ensure_business_warehouse_baseline($businessId);
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT w.*, o.name AS outlet_name, o.code AS outlet_code
        FROM warehouses w
        LEFT JOIN outlets o ON o.id = w.outlet_id AND o.business_id = :biz_id_o
        WHERE w.business_id = :biz_id
    ';
    $params = [
        'biz_id' => $bid,
        'biz_id_o' => $bid
    ];
    if ($outletId !== null && $outletId > 0) {
        $sql .= ' AND w.outlet_id = :oid';
        $params['oid'] = $outletId;
    }
    if ($status !== '') {
        $sql .= ' AND w.status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY w.id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_warehouse_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT w.*, o.name AS outlet_name 
        FROM warehouses w 
        LEFT JOIN outlets o ON o.id = w.outlet_id AND o.business_id = :biz_id_o
        WHERE w.id = :id AND w.business_id = :biz_id LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'biz_id' => $bid, 'biz_id_o' => $bid]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function get_product_warehouse_stock(int $productId, int $warehouseId): int {
    $db = get_db();
    $stmt = $db->prepare('SELECT stock_quantity FROM warehouse_stock WHERE product_id = :pid AND warehouse_id = :wid LIMIT 1');
    $stmt->execute(['pid' => $productId, 'wid' => $warehouseId]);
    $qty = $stmt->fetchColumn();
    return $qty !== false ? (int)$qty : 0;
}

function set_product_warehouse_stock(int $productId, int $warehouseId, int $newStock): void {
    $db = get_db();
    $stmt = $db->prepare('
        INSERT INTO warehouse_stock (warehouse_id, product_id, stock_quantity, created_at, updated_at)
        VALUES (:wid, :pid, :qty, NOW(), NOW())
        ON DUPLICATE KEY UPDATE stock_quantity = :qty_up, updated_at = NOW()
    ');
    $stmt->execute(['wid' => $warehouseId, 'pid' => $productId, 'qty' => $newStock, 'qty_up' => $newStock]);
}

/* =========================================================================
   3. STOCK TRANSFERS WORKFLOW
   ========================================================================= */

function ensure_stock_transfer_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = get_db();
    try {
        $db->exec("
            ALTER TABLE stock_transfers
            MODIFY COLUMN `status` ENUM(
                'draft', 'requested', 'approved', 'picked', 'dispatched',
                'in_transit', 'received', 'cancelled'
            ) NOT NULL DEFAULT 'draft'
        ");
    } catch (Exception $e) {
        // Table or column may differ on older installs; ignore.
    }
}

/**
 * Company-wide piece total (products.stock_quantity). Includes stock not yet placed in a store warehouse.
 */
function get_company_stock_piece_total(?int $businessId = null): int {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT COALESCE(SUM(stock_quantity), 0) FROM products WHERE business_id = :bid');
    $stmt->execute(['bid' => $bid]);
    return (int) $stmt->fetchColumn();
}

function stock_location_display_label(array $warehouseRow): string {
    $outlet = trim((string) ($warehouseRow['outlet_name'] ?? ''));
    if ($outlet !== '') {
        return $outlet;
    }
    $name = trim((string) ($warehouseRow['name'] ?? 'Warehouse'));
    if (stripos($name, 'central') !== false) {
        return 'Central';
    }
    if (stripos($name, 'online') !== false) {
        return 'Online';
    }
    return $name;
}

function stock_location_display_sort_key(array $warehouseRow): int {
    $haystack = strtolower(stock_location_display_label($warehouseRow) . ' ' . (string) ($warehouseRow['name'] ?? ''));
    $order = [
        'garden reach' => 10,
        'behala' => 20,
        'store 3' => 30,
        'central' => 40,
        'online' => 50,
    ];
    foreach ($order as $needle => $priority) {
        if (str_contains($haystack, $needle)) {
            return $priority;
        }
    }
    return 100 + (int) ($warehouseRow['id'] ?? 0);
}

/**
 * @return array{company_pieces:int, locations:array<int, array{label:string, pieces:int, warehouse_id:int}>}
 */
function get_company_and_store_stock_summary(?int $businessId = null): array {
    ensure_business_warehouse_baseline($businessId);
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $companyPieces = get_company_stock_piece_total($bid);

    $stmt = $db->prepare('
        SELECT w.id, w.name, w.code, o.name AS outlet_name,
               COALESCE(SUM(ws.stock_quantity), 0) AS pieces
        FROM warehouses w
        LEFT JOIN outlets o ON o.id = w.outlet_id AND o.business_id = :bid_o
        LEFT JOIN warehouse_stock ws ON ws.warehouse_id = w.id
        LEFT JOIN products p ON p.id = ws.product_id AND p.business_id = :bid_p
        WHERE w.business_id = :bid_w AND w.status = "active"
        GROUP BY w.id, w.name, w.code, o.name
    ');
    $stmt->execute(['bid_o' => $bid, 'bid_p' => $bid, 'bid_w' => $bid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    usort($rows, static function (array $a, array $b): int {
        $cmp = stock_location_display_sort_key($a) <=> stock_location_display_sort_key($b);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp(stock_location_display_label($a), stock_location_display_label($b));
    });

    $locations = [];
    foreach ($rows as $row) {
        $locations[] = [
            'warehouse_id' => (int) $row['id'],
            'label' => stock_location_display_label($row),
            'pieces' => (int) $row['pieces'],
        ];
    }

    return [
        'company_pieces' => $companyPieces,
        'locations' => $locations,
    ];
}

function sync_product_stock_from_warehouses(int $productId, int $businessId): void {
    $db = get_db();
    $stmtCount = $db->prepare('
        SELECT COUNT(*) FROM warehouse_stock ws
        INNER JOIN warehouses w ON w.id = ws.warehouse_id AND w.business_id = :bid
        WHERE ws.product_id = :pid
    ');
    $stmtCount->execute(['pid' => $productId, 'bid' => $businessId]);
    if ((int) $stmtCount->fetchColumn() === 0) {
        return;
    }

    $stmtSum = $db->prepare('
        SELECT COALESCE(SUM(ws.stock_quantity), 0) FROM warehouse_stock ws
        INNER JOIN warehouses w ON w.id = ws.warehouse_id AND w.business_id = :bid
        WHERE ws.product_id = :pid
    ');
    $stmtSum->execute(['pid' => $productId, 'bid' => $businessId]);
    $total = (int) $stmtSum->fetchColumn();

    $db->prepare('
        UPDATE products SET stock_quantity = :qty, updated_at = NOW()
        WHERE id = :pid AND business_id = :bid
    ')->execute(['qty' => $total, 'pid' => $productId, 'bid' => $businessId]);
}

function log_stock_transfer_movement(
    PDO $db,
    int $businessId,
    int $productId,
    ?int $userId,
    string $reason,
    string $movementType = 'adjustment',
    int $quantityChange = 0
): void {
    $stmtStock = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :bid');
    $stmtStock->execute(['id' => $productId, 'bid' => $businessId]);
    $stock = (int) $stmtStock->fetchColumn();
    $after = $stock + $quantityChange;

    $stmtMov = $db->prepare('
        INSERT INTO inventory_movements (
            business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
        ) VALUES (
            :biz_id, :pid, :uid, :mtype, :change, :before, :after, :reason, NOW()
        )
    ');
    $stmtMov->execute([
        'biz_id' => $businessId,
        'pid' => $productId,
        'uid' => $userId ?: null,
        'mtype' => $movementType,
        'change' => $quantityChange,
        'before' => $stock,
        'after' => $after,
        'reason' => $reason,
    ]);
}

function stock_transfer_assert_status(array $trf, array $allowed): void {
    if (!in_array($trf['status'], $allowed, true)) {
        throw new Exception('Stock transfer is not in the correct state for this action.');
    }
}

function create_stock_transfer(int $sourceWarehouseId, int $destWarehouseId, array $items, string $notes = '', ?int $userId = null, ?int $businessId = null): array {
    ensure_stock_transfer_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    if ($sourceWarehouseId === $destWarehouseId) {
        return ['success' => false, 'error' => 'Source and destination warehouse cannot be identical.'];
    }
    if (empty($items)) {
        return ['success' => false, 'error' => 'At least one product item is required for transfer.'];
    }

    try {
        $db->beginTransaction();

        require_once __DIR__ . '/orders_db.php';
        $transferNumber = generate_unique_reference('stock_transfers', 'transfer_number', 'TRF-', $db);

        $stmtTrf = $db->prepare('
            INSERT INTO stock_transfers (business_id, transfer_number, source_warehouse_id, dest_warehouse_id, user_id, status, notes, created_at, updated_at)
            VALUES (:biz_id, :num, :swid, :dwid, :uid, "requested", :notes, NOW(), NOW())
        ');
        $stmtTrf->execute([
            'biz_id' => $bid,
            'num' => $transferNumber,
            'swid' => $sourceWarehouseId,
            'dwid' => $destWarehouseId,
            'uid' => $userId ?: 1,
            'notes' => trim($notes) ?: null,
        ]);
        $transferId = (int)$db->lastInsertId();

        $stmtItem = $db->prepare('
            INSERT INTO stock_transfer_items (stock_transfer_id, product_id, quantity_requested, quantity_transferred, quantity_received, created_at)
            VALUES (:tid, :pid, :qty_req, :qty_trf, 0, NOW())
        ');

        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = max(1, (int)($item['quantity'] ?? 1));
            if ($pid <= 0) continue;

            $stmtItem->execute([
                'tid' => $transferId,
                'pid' => $pid,
                'qty_req' => $qty,
                'qty_trf' => $qty,
            ]);
        }

        $db->commit();
        return ['success' => true, 'transfer_id' => $transferId, 'transfer_number' => $transferNumber];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function approve_stock_transfer(int $transferId, ?int $userId = null): array {
    ensure_stock_transfer_schema();
    $db = get_db();
    try {
        $db->beginTransaction();
        $trf = get_stock_transfer_by_id($transferId);
        if (!$trf) {
            throw new Exception('Stock transfer not found.');
        }
        stock_transfer_assert_status($trf, ['draft', 'requested']);
        $bid = (int) ($trf['business_id'] ?? current_business_id());

        foreach ($trf['items'] as $item) {
            log_stock_transfer_movement(
                $db,
                $bid,
                (int) $item['product_id'],
                $userId,
                "Stock Transfer #{$trf['transfer_number']} approved",
                'adjustment',
                0
            );
        }

        $db->prepare('UPDATE stock_transfers SET status = "approved", updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $transferId]);
        $db->commit();
        return ['success' => true, 'status' => 'approved'];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function pick_stock_transfer(int $transferId, ?int $userId = null): array {
    ensure_stock_transfer_schema();
    $db = get_db();
    try {
        $db->beginTransaction();
        $trf = get_stock_transfer_by_id($transferId);
        if (!$trf) {
            throw new Exception('Stock transfer not found.');
        }
        stock_transfer_assert_status($trf, ['approved']);
        $bid = (int) ($trf['business_id'] ?? current_business_id());

        foreach ($trf['items'] as $item) {
            log_stock_transfer_movement(
                $db,
                $bid,
                (int) $item['product_id'],
                $userId,
                "Stock Transfer #{$trf['transfer_number']} picked at {$trf['source_warehouse_name']}",
                'adjustment',
                0
            );
        }

        $db->prepare('UPDATE stock_transfers SET status = "picked", updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $transferId]);
        $db->commit();
        return ['success' => true, 'status' => 'picked'];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function dispatch_stock_transfer(int $transferId, ?int $userId = null): array {
    ensure_stock_transfer_schema();
    $db = get_db();
    try {
        $db->beginTransaction();
        $trf = get_stock_transfer_by_id($transferId);
        if (!$trf) {
            throw new Exception('Stock transfer not found.');
        }
        stock_transfer_assert_status($trf, ['picked']);
        $bid = (int) ($trf['business_id'] ?? current_business_id());

        foreach ($trf['items'] as $item) {
            $qty = (int) $item['quantity_requested'];
            log_stock_transfer_movement(
                $db,
                $bid,
                (int) $item['product_id'],
                $userId,
                "Stock Transfer #{$trf['transfer_number']} dispatched to {$trf['dest_warehouse_name']} ({$qty} units)",
                'adjustment',
                0
            );
        }

        $db->prepare('UPDATE stock_transfers SET status = "dispatched", updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $transferId]);
        $db->commit();
        return ['success' => true, 'status' => 'dispatched'];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function ship_stock_transfer_in_transit(int $transferId, ?int $userId = null): array {
    ensure_stock_transfer_schema();
    $db = get_db();
    try {
        $db->beginTransaction();
        $trf = get_stock_transfer_by_id($transferId);
        if (!$trf) {
            throw new Exception('Stock transfer not found.');
        }
        stock_transfer_assert_status($trf, ['dispatched']);
        $bid = (int) ($trf['business_id'] ?? current_business_id());
        $sourceWhId = (int) $trf['source_warehouse_id'];

        foreach ($trf['items'] as $item) {
            $pid = (int) $item['product_id'];
            $qty = (int) $item['quantity_requested'];

            $currStock = get_product_warehouse_stock($pid, $sourceWhId);
            if ($currStock < $qty) {
                throw new Exception("Insufficient stock in source warehouse for {$item['product_name']} (Available: {$currStock}, Required: {$qty})");
            }

            $newSourceStock = $currStock - $qty;
            set_product_warehouse_stock($pid, $sourceWhId, $newSourceStock);

            // Company-wide product stock is unchanged while goods are in transit (warehouse-level move only).
            log_stock_transfer_movement(
                $db,
                $bid,
                $pid,
                $userId,
                "Stock Transfer #{$trf['transfer_number']} in transit: {$qty} unit(s) left {$trf['source_warehouse_name']} (WH {$currStock} → {$newSourceStock})",
                'adjustment',
                0
            );
        }

        $db->prepare('UPDATE stock_transfers SET status = "in_transit", updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $transferId]);
        $db->commit();
        return ['success' => true, 'status' => 'in_transit'];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function receive_stock_transfer(int $transferId, ?int $userId = null): array {
    ensure_stock_transfer_schema();
    $db = get_db();

    try {
        $db->beginTransaction();
        $trf = get_stock_transfer_by_id($transferId);
        if (!$trf || $trf['status'] !== 'in_transit') {
            throw new Exception('Only in-transit transfers can be received.');
        }

        $bid = (int)($trf['business_id'] ?? current_business_id());
        $destWhId = (int)$trf['dest_warehouse_id'];

        foreach ($trf['items'] as $item) {
            $pid = (int)$item['product_id'];
            $qty = (int)$item['quantity_requested'];

            $currDestStock = get_product_warehouse_stock($pid, $destWhId);
            $newDestStock = $currDestStock + $qty;
            set_product_warehouse_stock($pid, $destWhId, $newDestStock);

            $stmtItemUp = $db->prepare('UPDATE stock_transfer_items SET quantity_received = :qty WHERE stock_transfer_id = :tid AND product_id = :pid');
            $stmtItemUp->execute(['qty' => $qty, 'tid' => $transferId, 'pid' => $pid]);

            // Destination warehouse +qty only; products.stock_quantity (company total) stays unchanged.
            log_stock_transfer_movement(
                $db,
                $bid,
                $pid,
                $userId,
                "Stock Transfer #{$trf['transfer_number']} received at {$trf['dest_warehouse_name']} ({$qty} units, WH {$currDestStock} → {$newDestStock})",
                'adjustment',
                0
            );
        }

        $stmtDone = $db->prepare('UPDATE stock_transfers SET status = "received", completed_at = NOW(), updated_at = NOW() WHERE id = :id');
        $stmtDone->execute(['id' => $transferId]);

        $db->commit();
        return ['success' => true, 'status' => 'received'];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function get_stock_transfers(int $limit = 50, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT st.*, 
               sw.name AS source_warehouse_name, sw.code AS source_warehouse_code,
               dw.name AS dest_warehouse_name, dw.code AS dest_warehouse_code,
               COALESCE(u.name, "Staff") AS creator_name,
               (SELECT COUNT(*) FROM stock_transfer_items sti WHERE sti.stock_transfer_id = st.id) AS total_items,
               (SELECT COALESCE(SUM(quantity_requested), 0) FROM stock_transfer_items sti WHERE sti.stock_transfer_id = st.id) AS total_units
        FROM stock_transfers st
        LEFT JOIN warehouses sw ON sw.id = st.source_warehouse_id AND sw.business_id = :bid1
        LEFT JOIN warehouses dw ON dw.id = st.dest_warehouse_id AND dw.business_id = :bid2
        LEFT JOIN users u ON u.id = st.user_id
        WHERE st.business_id = :bid3
        ORDER BY st.id DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':bid1', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':bid2', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':bid3', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_stock_transfer_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT st.*, 
               sw.name AS source_warehouse_name, sw.code AS source_warehouse_code,
               dw.name AS dest_warehouse_name, dw.code AS dest_warehouse_code,
               COALESCE(u.name, "Staff") AS creator_name
        FROM stock_transfers st
        LEFT JOIN warehouses sw ON sw.id = st.source_warehouse_id AND sw.business_id = :bid1
        LEFT JOIN warehouses dw ON dw.id = st.dest_warehouse_id AND dw.business_id = :bid2
        LEFT JOIN users u ON u.id = st.user_id
        WHERE st.id = :id AND st.business_id = :bid3
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid1' => $bid, 'bid2' => $bid, 'bid3' => $bid]);
    $trf = $stmt->fetch();
    if (!$trf) return null;

    $stmtItems = $db->prepare('
        SELECT sti.*, p.name AS product_name, p.sku AS product_sku, p.selling_price
        FROM stock_transfer_items sti
        JOIN products p ON p.id = sti.product_id AND p.business_id = :bid
        WHERE sti.stock_transfer_id = :id
    ');
    $stmtItems->execute(['id' => $id, 'bid' => $bid]);
    $trf['items'] = $stmtItems->fetchAll();

    return $trf;
}

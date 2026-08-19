<?php
/**
 * Multi-Outlet, Multi-Warehouse & Stock Transfers Service for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* =========================================================================
   1. OUTLET OPERATIONS
   ========================================================================= */

function get_outlets(string $status = ''): array {
    $db = get_db();
    $sql = 'SELECT * FROM outlets WHERE 1=1';
    $params = [];
    if ($status !== '') {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_outlet_by_id(int $id): ?array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM outlets WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function save_outlet(array $data, ?int $id = null): array {
    $db = get_db();
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
                WHERE id = :id
            ');
            $stmt->execute([
                'name' => $name, 'code' => $code, 'address' => $address ?: null,
                'phone' => $phone ?: null, 'email' => $email ?: null, 'gstin' => $gstin ?: null,
                'status' => $status, 'id' => $id,
            ]);
            return ['success' => true, 'outlet_id' => $id];
        } else {
            $stmt = $db->prepare('
                INSERT INTO outlets (name, code, address, phone, email, gstin, status, created_at, updated_at)
                VALUES (:name, :code, :address, :phone, :email, :gstin, :status, NOW(), NOW())
            ');
            $stmt->execute([
                'name' => $name, 'code' => $code, 'address' => $address ?: null,
                'phone' => $phone ?: null, 'email' => $email ?: null, 'gstin' => $gstin ?: null,
                'status' => $status,
            ]);
            $outletId = (int)$db->lastInsertId();

            // Auto-create an associated default warehouse for this new outlet
            $whCode = 'WH-' . $code;
            $stmtWH = $db->prepare('
                INSERT INTO warehouses (outlet_id, name, code, location, status, created_at, updated_at)
                VALUES (:oid, :name, :code, :loc, "active", NOW(), NOW())
            ');
            $stmtWH->execute([
                'oid' => $outletId,
                'name' => $name . ' Warehouse',
                'code' => $whCode,
                'loc' => $address ?: 'Main Store Floor',
            ]);

            return ['success' => true, 'outlet_id' => $outletId];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/* =========================================================================
   2. WAREHOUSE OPERATIONS & STOCK
   ========================================================================= */

function get_warehouses(?int $outletId = null, string $status = ''): array {
    $db = get_db();
    $sql = '
        SELECT w.*, o.name AS outlet_name, o.code AS outlet_code
        FROM warehouses w
        LEFT JOIN outlets o ON o.id = w.outlet_id
        WHERE 1=1
    ';
    $params = [];
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

function get_warehouse_by_id(int $id): ?array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT w.*, o.name AS outlet_name 
        FROM warehouses w 
        LEFT JOIN outlets o ON o.id = w.outlet_id 
        WHERE w.id = :id LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
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

function create_stock_transfer(int $sourceWarehouseId, int $destWarehouseId, array $items, string $notes = '', ?int $userId = null): array {
    $db = get_db();
    if ($sourceWarehouseId === $destWarehouseId) {
        return ['success' => false, 'error' => 'Source and destination warehouse cannot be identical.'];
    }
    if (empty($items)) {
        return ['success' => false, 'error' => 'At least one product item is required for transfer.'];
    }

    try {
        $db->beginTransaction();

        $stmtCount = $db->query("SELECT COUNT(*) FROM stock_transfers WHERE DATE(created_at) = CURDATE()");
        $seq = (int)$stmtCount->fetchColumn() + 1;
        $transferNumber = 'TRF-' . date('Ymd') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        $stmtTrf = $db->prepare('
            INSERT INTO stock_transfers (transfer_number, source_warehouse_id, dest_warehouse_id, user_id, status, notes, created_at, updated_at)
            VALUES (:num, :swid, :dwid, :uid, "requested", :notes, NOW(), NOW())
        ');
        $stmtTrf->execute([
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

function dispatch_stock_transfer(int $transferId, ?int $userId = null): array {
    $db = get_db();

    try {
        $db->beginTransaction();
        $trf = get_stock_transfer_by_id($transferId);
        if (!$trf || !in_array($trf['status'], ['draft', 'requested'], true)) {
            throw new Exception('Stock transfer is not in dispatchable state.');
        }

        $sourceWhId = (int)$trf['source_warehouse_id'];

        // Deduct from source warehouse stock & log audit
        foreach ($trf['items'] as $item) {
            $pid = (int)$item['product_id'];
            $qty = (int)$item['quantity_requested'];

            $currStock = get_product_warehouse_stock($pid, $sourceWhId);
            if ($currStock < $qty) {
                throw new Exception("Insufficient stock in source warehouse for product: {$item['product_name']} (Available: {$currStock}, Required: {$qty})");
            }

            $newSourceStock = $currStock - $qty;
            set_product_warehouse_stock($pid, $sourceWhId, $newSourceStock);

            // Also decrement general product table stock while in transit
            $stmtProd = $db->prepare('UPDATE products SET stock_quantity = stock_quantity - :qty WHERE id = :id');
            $stmtProd->execute(['qty' => $qty, 'id' => $pid]);

            // Audit movement
            $stmtMov = $db->prepare('
                INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at)
                VALUES (:pid, :uid, "out", :change, :before, :after, :reason, NOW())
            ');
            $stmtMov->execute([
                'pid' => $pid,
                'uid' => $userId ?: 1,
                'change' => -$qty,
                'before' => $currStock,
                'after' => $newSourceStock,
                'reason' => "Dispatched Stock Transfer #{$trf['transfer_number']} to {$trf['dest_warehouse_name']}",
            ]);
        }

        $stmtUp = $db->prepare('UPDATE stock_transfers SET status = "in_transit", updated_at = NOW() WHERE id = :id');
        $stmtUp->execute(['id' => $transferId]);

        $db->commit();
        return ['success' => true, 'status' => 'in_transit'];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function receive_stock_transfer(int $transferId, ?int $userId = null): array {
    $db = get_db();

    try {
        $db->beginTransaction();
        $trf = get_stock_transfer_by_id($transferId);
        if (!$trf || $trf['status'] !== 'in_transit') {
            throw new Exception('Only in-transit transfers can be received.');
        }

        $destWhId = (int)$trf['dest_warehouse_id'];

        foreach ($trf['items'] as $item) {
            $pid = (int)$item['product_id'];
            $qty = (int)$item['quantity_requested'];

            $currDestStock = get_product_warehouse_stock($pid, $destWhId);
            $newDestStock = $currDestStock + $qty;
            set_product_warehouse_stock($pid, $destWhId, $newDestStock);

            // Increment general product stock table
            $stmtProd = $db->prepare('UPDATE products SET stock_quantity = stock_quantity + :qty WHERE id = :id');
            $stmtProd->execute(['qty' => $qty, 'id' => $pid]);

            // Update item received qty
            $stmtItemUp = $db->prepare('UPDATE stock_transfer_items SET quantity_received = :qty WHERE stock_transfer_id = :tid AND product_id = :pid');
            $stmtItemUp->execute(['qty' => $qty, 'tid' => $transferId, 'pid' => $pid]);

            // Audit movement
            $stmtMov = $db->prepare('
                INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at)
                VALUES (:pid, :uid, "in", :change, :before, :after, :reason, NOW())
            ');
            $stmtMov->execute([
                'pid' => $pid,
                'uid' => $userId ?: 1,
                'change' => $qty,
                'before' => $currDestStock,
                'after' => $newDestStock,
                'reason' => "Received Stock Transfer #{$trf['transfer_number']} from {$trf['source_warehouse_name']}",
            ]);
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

function get_stock_transfers(int $limit = 50): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT st.*, 
               sw.name AS source_warehouse_name, sw.code AS source_warehouse_code,
               dw.name AS dest_warehouse_name, dw.code AS dest_warehouse_code,
               COALESCE(u.name, "Staff") AS creator_name,
               (SELECT COUNT(*) FROM stock_transfer_items sti WHERE sti.stock_transfer_id = st.id) AS total_items,
               (SELECT COALESCE(SUM(quantity_requested), 0) FROM stock_transfer_items sti WHERE sti.stock_transfer_id = st.id) AS total_units
        FROM stock_transfers st
        LEFT JOIN warehouses sw ON sw.id = st.source_warehouse_id
        LEFT JOIN warehouses dw ON dw.id = st.dest_warehouse_id
        LEFT JOIN users u ON u.id = st.user_id
        ORDER BY st.id DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_stock_transfer_by_id(int $id): ?array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT st.*, 
               sw.name AS source_warehouse_name, sw.code AS source_warehouse_code,
               dw.name AS dest_warehouse_name, dw.code AS dest_warehouse_code,
               COALESCE(u.name, "Staff") AS creator_name
        FROM stock_transfers st
        LEFT JOIN warehouses sw ON sw.id = st.source_warehouse_id
        LEFT JOIN warehouses dw ON dw.id = st.dest_warehouse_id
        LEFT JOIN users u ON u.id = st.user_id
        WHERE st.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $trf = $stmt->fetch();
    if (!$trf) return null;

    $stmtItems = $db->prepare('
        SELECT sti.*, p.name AS product_name, p.sku AS product_sku, p.selling_price
        FROM stock_transfer_items sti
        JOIN products p ON p.id = sti.product_id
        WHERE sti.stock_transfer_id = :id
    ');
    $stmtItems->execute(['id' => $id]);
    $trf['items'] = $stmtItems->fetchAll();

    return $trf;
}

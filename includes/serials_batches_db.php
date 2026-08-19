<?php
/**
 * Serial Numbers & Batch / Expiry Tracking Service for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* =========================================================================
   1. SERIAL NUMBERS
   ========================================================================= */

function get_product_serials(int $productId, string $status = 'available'): array {
    $db = get_db();
    $sql = 'SELECT * FROM product_serials WHERE product_id = :pid';
    $params = ['pid' => $productId];
    if ($status !== '') {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function add_product_serials(int $productId, array $serialNumbers): array {
    $db = get_db();
    $inserted = 0;
    $errors = [];

    $stmt = $db->prepare('
        INSERT INTO product_serials (product_id, serial_number, status, created_at, updated_at)
        VALUES (:pid, :sn, "available", NOW(), NOW())
    ');

    foreach ($serialNumbers as $sn) {
        $sn = trim((string)$sn);
        if ($sn === '') continue;
        try {
            $stmt->execute(['pid' => $productId, 'sn' => $sn]);
            $inserted++;
        } catch (PDOException $e) {
            $errors[] = "Duplicate or invalid serial: {$sn}";
        }
    }

    if ($inserted > 0) {
        $db->exec("UPDATE products SET has_serials = 1 WHERE id = {$productId}");
    }

    return ['success' => true, 'inserted_count' => $inserted, 'errors' => $errors];
}

function allocate_serial_for_sale(string $serialNumber, int $orderId, ?int $invoiceId = null): bool {
    $db = get_db();
    $stmt = $db->prepare('
        UPDATE product_serials 
        SET status = "sold", order_id = :oid, invoice_id = :invid, updated_at = NOW() 
        WHERE serial_number = :sn AND status = "available"
    ');
    $stmt->execute(['oid' => $orderId, 'invid' => $invoiceId, 'sn' => $serialNumber]);
    return $stmt->rowCount() > 0;
}

/* =========================================================================
   2. PRODUCT BATCHES & EXPIRY TRACKING
   ========================================================================= */

function get_product_batches(int $productId): array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM product_batches WHERE product_id = :pid ORDER BY expiry_date ASC');
    $stmt->execute(['pid' => $productId]);
    return $stmt->fetchAll();
}

function save_product_batch(int $productId, string $batchNumber, ?string $mfgDate, ?string $expiryDate, int $quantity): array {
    $db = get_db();
    $batchNumber = trim($batchNumber);
    if ($batchNumber === '') return ['success' => false, 'error' => 'Batch number is required.'];

    try {
        $stmt = $db->prepare('
            INSERT INTO product_batches (product_id, batch_number, mfg_date, expiry_date, quantity, created_at, updated_at)
            VALUES (:pid, :bnum, :mfg, :exp, :qty, NOW(), NOW())
        ');
        $stmt->execute([
            'pid' => $productId,
            'bnum' => $batchNumber,
            'mfg' => $mfgDate ?: null,
            'exp' => $expiryDate ?: null,
            'qty' => max(0, $quantity),
        ]);

        $db->exec("UPDATE products SET has_batches = 1 WHERE id = {$productId}");
        return ['success' => true, 'batch_id' => (int)$db->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function get_expiring_batches_report(int $daysThreshold = 60): array {
    $db = get_db();
    $targetDate = date('Y-m-d', strtotime("+{$daysThreshold} days"));
    $stmt = $db->prepare('
        SELECT pb.*, p.name AS product_name, p.sku AS product_sku, p.selling_price
        FROM product_batches pb
        JOIN products p ON p.id = pb.product_id
        WHERE pb.expiry_date IS NOT NULL AND pb.expiry_date <= :tdate AND pb.quantity > 0
        ORDER BY pb.expiry_date ASC
    ');
    $stmt->execute(['tdate' => $targetDate]);
    return $stmt->fetchAll();
}

<?php
/**
 * Payment Options & Tender Types Service for OminiFlow POS (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function get_payment_options(string $status = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    // Auto-seed if empty for this tenant
    seed_default_payment_options_if_needed($bid);

    $sql = 'SELECT * FROM payment_options WHERE business_id = :bid';
    $params = ['bid' => $bid];

    if ($status !== '' && in_array($status, ['active', 'inactive'], true)) {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }

    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_payment_option_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM payment_options WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $id, 'bid' => $bid]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function save_payment_option(array $data, ?int $id = null, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $displayName = trim((string)($data['display_name'] ?? ''));
    $processingType = trim((string)($data['processing_type'] ?? 'Manual Entry'));
    $paymentMode = trim((string)($data['payment_mode'] ?? 'Cash'));
    $depositTo = trim((string)($data['deposit_to'] ?? 'Petty Cash'));
    $isCustRequired = !empty($data['is_customer_required']) ? 1 : 0;
    $isExpress = !empty($data['is_express_checkout']) ? 1 : 0;
    $status = (!empty($data['status']) && in_array($data['status'], ['active', 'inactive'], true)) ? $data['status'] : 'active';
    $sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;

    if ($displayName === '') {
        return ['success' => false, 'error' => 'Display Name is required.'];
    }

    try {
        if ($id !== null && $id > 0) {
            $stmt = $db->prepare('
                UPDATE payment_options
                SET display_name = :dname,
                    processing_type = :ptype,
                    payment_mode = :pmode,
                    deposit_to = :dto,
                    is_customer_required = :cust_req,
                    is_express_checkout = :express,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND business_id = :bid
            ');
            $stmt->execute([
                'dname' => $displayName,
                'ptype' => $processingType,
                'pmode' => $paymentMode,
                'dto' => $depositTo,
                'cust_req' => $isCustRequired,
                'express' => $isExpress,
                'status' => $status,
                'id' => $id,
                'bid' => $bid,
            ]);
            return ['success' => true, 'id' => $id];
        } else {
            $stmtMax = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM payment_options WHERE business_id = :bid');
            $stmtMax->execute(['bid' => $bid]);
            $nextOrder = (int)$stmtMax->fetchColumn();

            $stmt = $db->prepare('
                INSERT INTO payment_options (
                    business_id, display_name, processing_type, payment_mode, deposit_to,
                    is_customer_required, is_express_checkout, sort_order, status, created_at, updated_at
                ) VALUES (
                    :bid, :dname, :ptype, :pmode, :dto, :cust_req, :express, :sorder, :status, NOW(), NOW()
                )
            ');
            $stmt->execute([
                'bid' => $bid,
                'dname' => $displayName,
                'ptype' => $processingType,
                'pmode' => $paymentMode,
                'dto' => $depositTo,
                'cust_req' => $isCustRequired,
                'express' => $isExpress,
                'sorder' => $sortOrder ?: $nextOrder,
                'status' => $status,
            ]);
            return ['success' => true, 'id' => (int)$db->lastInsertId()];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function update_payment_options_order(array $orderedIds, ?int $businessId = null): bool {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    try {
        $db->beginTransaction();
        $stmt = $db->prepare('UPDATE payment_options SET sort_order = :order WHERE id = :id AND business_id = :bid');
        foreach ($orderedIds as $index => $id) {
            $stmt->execute([
                'order' => $index + 1,
                'id' => (int)$id,
                'bid' => $bid,
            ]);
        }
        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return false;
    }
}

function delete_payment_option(int $id, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $opt = get_payment_option_by_id($id, $bid);
    if (!$opt) {
        return ['success' => false, 'error' => 'Payment option not found.'];
    }

    try {
        $stmt = $db->prepare('DELETE FROM payment_options WHERE id = :id AND business_id = :bid');
        $stmt->execute(['id' => $id, 'bid' => $bid]);
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function seed_default_payment_options_if_needed(int $businessId): void {
    $db = get_db();
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM payment_options WHERE business_id = :bid');
        $stmt->execute(['bid' => $businessId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $stmtIns = $db->prepare('
                INSERT INTO payment_options (business_id, display_name, processing_type, payment_mode, deposit_to, is_customer_required, is_express_checkout, sort_order, status, created_at, updated_at)
                VALUES (:bid, :dname, :ptype, :pmode, :dto, :cust_req, :express, :sorder, :status, NOW(), NOW())
            ');

            $defaults = [
                ['Cash', 'Manual Entry', 'Cash', 'Petty Cash', 0, 1, 1, 'active'],
                ['Card', 'Manual Entry', 'Card', 'Main Bank Account', 0, 0, 2, 'active'],
                ['UPI', 'Manual Entry', 'UPI', 'Main Bank Account', 0, 1, 3, 'active'],
                ['Credit Sale', 'Credit Sale', '-', 'Petty Cash', 1, 0, 4, 'active'],
                ['Loyalty', 'Loyalty Redemption', 'Loyalty Points', 'Petty Cash', 1, 0, 5, 'inactive'],
                ['Credit Note', 'Credit Note', 'Credit Note', 'Petty Cash', 1, 0, 6, 'active'],
            ];

            foreach ($defaults as $d) {
                $stmtIns->execute([
                    'bid' => $businessId,
                    'dname' => $d[0],
                    'ptype' => $d[1],
                    'pmode' => $d[2],
                    'dto' => $d[3],
                    'cust_req' => $d[4],
                    'express' => $d[5],
                    'sorder' => $d[6],
                    'status' => $d[7],
                ]);
            }
        }
    } catch (Exception $e) {}
}

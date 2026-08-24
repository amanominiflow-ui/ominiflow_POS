<?php
/**
 * Register & Cash Drawer Shift Management Service for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function get_registers(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM registers WHERE business_id = :bid ORDER BY id ASC');
    $stmt->execute(['bid' => $bid]);
    return $stmt->fetchAll();
}

function get_open_register_session(?int $userId = null, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT s.*, r.name AS register_name, r.code AS register_code, COALESCE(u.name, "Cashier") AS cashier_name
        FROM register_sessions s
        JOIN registers r ON r.id = s.register_id AND r.business_id = :bid_r
        LEFT JOIN users u ON u.id = s.user_id
        WHERE s.status = "open" AND s.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_r' => $bid,
    ];
    if ($userId !== null) {
        $sql .= ' AND s.user_id = :user_id';
        $params['user_id'] = $userId;
    }
    $sql .= ' ORDER BY s.id DESC LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $session = $stmt->fetch();
    return $session ?: null;
}

function open_register_session(int $registerId, int $userId, float $openingCash, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    // Check if user already has an open session
    $existing = get_open_register_session($userId, $bid);
    if ($existing) {
        return ['success' => false, 'error' => 'You already have an active open register session (#'.$existing['id'].'). Please close it before opening a new one.'];
    }

    $stmt = $db->prepare('
        INSERT INTO register_sessions (
            business_id, register_id, user_id, opened_at, opening_cash, status, created_at
        ) VALUES (
            :biz_id, :register_id, :user_id, NOW(), :opening_cash, "open", NOW()
        )
    ');
    $stmt->execute([
        'biz_id' => $bid,
        'register_id' => $registerId,
        'user_id' => $userId,
        'opening_cash' => max(0, $openingCash),
    ]);

    $sessionId = (int) $db->lastInsertId();
    return ['success' => true, 'session_id' => $sessionId];
}

function record_cash_movement(int $sessionId, float $amount, string $type, string $reason, int $userId, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $session = get_register_session_by_id($sessionId, $bid);
    if (!$session || $session['status'] !== 'open') {
        return ['success' => false, 'error' => 'Active open session not found.'];
    }

    if ($amount <= 0) {
        return ['success' => false, 'error' => 'Amount must be greater than zero.'];
    }

    if ($type === 'cash_in') {
        $stmt = $db->prepare('UPDATE register_sessions SET cash_in = cash_in + :amt WHERE id = :id AND business_id = :bid');
        $stmt->execute(['amt' => $amount, 'id' => $sessionId, 'bid' => $bid]);
    } elseif ($type === 'cash_out') {
        $stmt = $db->prepare('UPDATE register_sessions SET cash_out = cash_out + :amt WHERE id = :id AND business_id = :bid');
        $stmt->execute(['amt' => $amount, 'id' => $sessionId, 'bid' => $bid]);
    } else {
        return ['success' => false, 'error' => 'Invalid movement type.'];
    }

    return ['success' => true];
}

function update_session_sales(int $sessionId, float $amount, string $paymentMethod): void {
    $db = get_db();
    if ($sessionId <= 0) return;

    $col = 'total_cash_sales';
    if ($paymentMethod === 'card') $col = 'total_card_sales';
    elseif ($paymentMethod === 'upi') $col = 'total_upi_sales';

    $stmt = $db->prepare("UPDATE register_sessions SET {$col} = {$col} + :amt WHERE id = :id");
    $stmt->execute(['amt' => $amount, 'id' => $sessionId]);
}

function close_register_session(int $sessionId, float $closingCashActual, string $notes, int $userId, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $session = get_register_session_by_id($sessionId, $bid);
    if (!$session || $session['status'] !== 'open') {
        return ['success' => false, 'error' => 'Open session not found.'];
    }

    // Calculate expected cash: Opening Cash + Total Cash Sales + Cash In - Cash Out - Cash Refunds
    $openingCash = (float) $session['opening_cash'];
    $cashSales = (float) $session['total_cash_sales'];
    $cashIn = (float) $session['cash_in'];
    $cashOut = (float) $session['cash_out'];
    $refunds = (float) $session['total_refunds'];

    $expectedCash = $openingCash + $cashSales + $cashIn - $cashOut - $refunds;
    $difference = $closingCashActual - $expectedCash;

    $stmt = $db->prepare('
        UPDATE register_sessions
        SET closed_at = NOW(),
            closing_cash_actual = :actual,
            closing_cash_expected = :expected,
            cash_difference = :diff,
            status = "closed",
            closing_notes = :notes
        WHERE id = :id AND business_id = :bid
    ');
    $stmt->execute([
        'actual' => $closingCashActual,
        'expected' => $expectedCash,
        'diff' => $difference,
        'notes' => trim($notes) ?: null,
        'id' => $sessionId,
        'bid' => $bid,
    ]);

    return [
        'success' => true,
        'session_id' => $sessionId,
        'expected_cash' => $expectedCash,
        'actual_cash' => $closingCashActual,
        'difference' => $difference,
    ];
}

function get_register_sessions(int $limit = 50, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT s.*, r.name AS register_name, r.code AS register_code, COALESCE(u.name, "Cashier") AS cashier_name
        FROM register_sessions s
        JOIN registers r ON r.id = s.register_id AND r.business_id = :bid_r
        LEFT JOIN users u ON u.id = s.user_id
        WHERE s.business_id = :bid
        ORDER BY s.id DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':bid', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':bid_r', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_register_session_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT s.*, r.name AS register_name, r.code AS register_code, COALESCE(u.name, "Cashier") AS cashier_name
        FROM register_sessions s
        JOIN registers r ON r.id = s.register_id AND r.business_id = :bid_r
        LEFT JOIN users u ON u.id = s.user_id
        WHERE s.id = :id AND s.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid, 'bid_r' => $bid]);
    $res = $stmt->fetch();
    return $res ?: null;
}

<?php
/**
 * Register & Cash Drawer Shift Management Service for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Ensure database tables and columns for denomination-wise counting exist
 */
function ensure_denomination_schema(?PDO $db = null): void {
    static $schemaChecked = false;
    if ($schemaChecked) {
        return;
    }
    $db = $db ?: get_db();

    try {
        // 1. Table cash_denominations
        $db->exec("
            CREATE TABLE IF NOT EXISTS `cash_denominations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `business_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `label` VARCHAR(50) NOT NULL,
                `value` DECIMAL(10, 2) NOT NULL,
                `type` ENUM('note', 'coin') NOT NULL DEFAULT 'note',
                `sort_order` INT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_cd_biz` (`business_id`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Seed default Indian currency denominations if none exist
        $cnt = (int) $db->query("SELECT COUNT(*) FROM `cash_denominations` WHERE `business_id` = 0")->fetchColumn();
        if ($cnt === 0) {
            $defaultDenominations = [
                ['label' => '₹500', 'value' => 500.00, 'type' => 'note', 'sort_order' => 1],
                ['label' => '₹200', 'value' => 200.00, 'type' => 'note', 'sort_order' => 2],
                ['label' => '₹100', 'value' => 100.00, 'type' => 'note', 'sort_order' => 3],
                ['label' => '₹50',  'value' => 50.00,  'type' => 'note', 'sort_order' => 4],
                ['label' => '₹20',  'value' => 20.00,  'type' => 'note', 'sort_order' => 5],
                ['label' => '₹10',  'value' => 10.00,  'type' => 'note', 'sort_order' => 6],
                ['label' => '₹5',   'value' => 5.00,   'type' => 'coin', 'sort_order' => 7],
                ['label' => '₹2',   'value' => 2.00,   'type' => 'coin', 'sort_order' => 8],
                ['label' => '₹1',   'value' => 1.00,   'type' => 'coin', 'sort_order' => 9],
            ];
            $stmt = $db->prepare("INSERT INTO `cash_denominations` (`business_id`, `label`, `value`, `type`, `sort_order`, `is_active`) VALUES (0, :lbl, :val, :type, :sort, 1)");
            foreach ($defaultDenominations as $d) {
                $stmt->execute(['lbl' => $d['label'], 'val' => $d['value'], 'type' => $d['type'], 'sort' => $d['sort_order']]);
            }
        }

        // 2. Table session_cash_denominations
        $db->exec("
            CREATE TABLE IF NOT EXISTS `session_cash_denominations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `session_id` INT UNSIGNED NOT NULL,
                `business_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entry_type` ENUM('opening', 'closing') NOT NULL,
                `denomination_id` INT UNSIGNED NULL,
                `denomination_value` DECIMAL(10, 2) NOT NULL,
                `quantity` INT NOT NULL DEFAULT 0,
                `total_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_scd_sess` (`session_id`, `entry_type`),
                INDEX `idx_scd_biz` (`business_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 3. Table session_denomination_audit_logs
        $db->exec("
            CREATE TABLE IF NOT EXISTS `session_denomination_audit_logs` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `session_id` INT UNSIGNED NOT NULL,
                `business_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entry_type` ENUM('opening', 'closing') NOT NULL,
                `admin_user_id` INT UNSIGNED NOT NULL,
                `previous_data` LONGTEXT NOT NULL,
                `new_data` LONGTEXT NOT NULL,
                `reason` TEXT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_sdal_sess` (`session_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 4. Columns in register_sessions
        $cols = $db->query("SHOW COLUMNS FROM `register_sessions` LIKE 'opening_count_confirmed'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE `register_sessions` ADD COLUMN `opening_count_confirmed` TINYINT(1) NOT NULL DEFAULT 0");
        }
        $cols = $db->query("SHOW COLUMNS FROM `register_sessions` LIKE 'closing_count_confirmed'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE `register_sessions` ADD COLUMN `closing_count_confirmed` TINYINT(1) NOT NULL DEFAULT 0");
        }
        $cols = $db->query("SHOW COLUMNS FROM `register_sessions` LIKE 'discrepancy_reason'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE `register_sessions` ADD COLUMN `discrepancy_reason` TEXT NULL");
        }
        $cols = $db->query("SHOW COLUMNS FROM `register_sessions` LIKE 'opening_notes'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE `register_sessions` ADD COLUMN `opening_notes` TEXT NULL");
        }

        $schemaChecked = true;
    } catch (Throwable $e) {
        // Schema check failed or table locked
    }
}

function get_registers(?int $businessId = null): array {
    ensure_counter_store_columns();
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT r.*, o.name AS outlet_name
        FROM registers r
        LEFT JOIN outlets o ON o.id = r.outlet_id AND o.business_id = :bid_o
        WHERE r.business_id = :bid
        ORDER BY r.id ASC
    ');
    $stmt->execute(['bid' => $bid, 'bid_o' => $bid]);
    return $stmt->fetchAll();
}

function get_open_register_session(?int $userId = null, ?int $businessId = null): ?array {
    ensure_counter_store_columns();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT s.*, r.name AS register_name, r.code AS register_code, r.outlet_id,
               o.name AS outlet_name, COALESCE(u.name, "Cashier") AS cashier_name
        FROM register_sessions s
        JOIN registers r ON r.id = s.register_id AND r.business_id = :bid_r
        LEFT JOIN outlets o ON o.id = r.outlet_id AND o.business_id = :bid_o
        LEFT JOIN users u ON u.id = s.user_id
        WHERE s.status = "open" AND s.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_r' => $bid,
        'bid_o' => $bid,
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

function open_register_session(
    int $registerId,
    int $userId,
    float $openingCash = 0.00,
    ?int $businessId = null,
    ?int $outletId = null,
    array $denominations = [],
    bool $countConfirmed = false,
    string $notes = ''
): array {
    ensure_counter_store_columns();
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $regStmt = $db->prepare('SELECT id, outlet_id FROM registers WHERE id = :id AND business_id = :bid LIMIT 1');
    $regStmt->execute(['id' => $registerId, 'bid' => $bid]);
    $register = $regStmt->fetch();
    if (!$register) {
        return ['success' => false, 'error' => 'That register counter was not found.'];
    }

    $userStmt = $db->prepare('SELECT id, role, outlet_id FROM users WHERE id = :id AND business_id = :bid LIMIT 1');
    $userStmt->execute(['id' => $userId, 'bid' => $bid]);
    $cashier = $userStmt->fetch() ?: [];
    $locked = user_pos_is_outlet_locked($cashier);
    $assignedOutlet = (int) ($cashier['outlet_id'] ?? 0);
    $registerOutlet = (int) ($register['outlet_id'] ?? 0);

    if ($locked) {
        if ($assignedOutlet <= 0) {
            return ['success' => false, 'error' => 'This staff account is not assigned to a store.'];
        }
        if ($registerOutlet > 0 && $registerOutlet !== $assignedOutlet) {
            return ['success' => false, 'error' => 'This counter belongs to another store.'];
        }
        $outletId = $assignedOutlet;
    } else {
        if ($outletId === null || $outletId <= 0) {
            $outletId = $registerOutlet;
        }
        if ($outletId <= 0) {
            return ['success' => false, 'error' => 'Choose the store this counter sells from.'];
        }
    }

    require_once __DIR__ . '/outlets_db.php';
    $outlet = get_outlet_by_id((int) $outletId, $bid);
    if (!$outlet || ($outlet['status'] ?? '') !== 'active') {
        return ['success' => false, 'error' => 'Choose an active store for this counter.'];
    }

    if ($registerOutlet !== (int) $outletId) {
        $db->prepare('UPDATE registers SET outlet_id = :oid, updated_at = NOW() WHERE id = :id AND business_id = :bid')
            ->execute(['oid' => (int) $outletId, 'id' => $registerId, 'bid' => $bid]);
    }

    // Check if user already has an open session
    $existing = get_open_register_session($userId, $bid);
    if ($existing) {
        return ['success' => false, 'error' => 'You already have an active open register session (#'.$existing['id'].'). Please close it before opening a new one.'];
    }

    $hasDenomInput = !empty($denominations);
    $denomData = null;

    if ($hasDenomInput) {
        if (!$countConfirmed) {
            return ['success' => false, 'error' => 'Cashier must confirm the physical cash count before starting the session.'];
        }
        $denomData = parse_denominations_input($denominations, $bid);
        $openingCash = $denomData['total_cash'];
    } elseif ($openingCash <= 0 && empty($denominations)) {
        return ['success' => false, 'error' => 'Denomination-wise cash count is required to open the register session.'];
    }

    $stmt = $db->prepare('
        INSERT INTO register_sessions (
            business_id, register_id, user_id, opened_at, opening_cash, status, opening_count_confirmed, opening_notes, created_at
        ) VALUES (
            :biz_id, :register_id, :user_id, NOW(), :opening_cash, "open", :confirmed, :notes, NOW()
        )
    ');
    $stmt->execute([
        'biz_id' => $bid,
        'register_id' => $registerId,
        'user_id' => $userId,
        'opening_cash' => max(0, $openingCash),
        'confirmed' => $countConfirmed ? 1 : 0,
        'notes' => trim($notes) ?: null,
    ]);

    $sessionId = (int) $db->lastInsertId();

    if ($denomData && !empty($denomData['items'])) {
        $insStmt = $db->prepare('
            INSERT INTO session_cash_denominations (
                session_id, business_id, entry_type, denomination_id, denomination_value, quantity, total_amount, created_at
            ) VALUES (
                :sess_id, :bid, "opening", :denom_id, :val, :qty, :amt, NOW()
            )
        ');
        foreach ($denomData['items'] as $item) {
            $insStmt->execute([
                'sess_id' => $sessionId,
                'bid' => $bid,
                'denom_id' => $item['denomination_id'],
                'val' => $item['value'],
                'qty' => $item['quantity'],
                'amt' => $item['total_amount'],
            ]);
        }
    }

    return ['success' => true, 'session_id' => $sessionId, 'opening_cash' => $openingCash];
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

function close_register_session(
    int $sessionId,
    float $closingCashActual = 0.00,
    string $notes = '',
    int $userId = 0,
    ?int $businessId = null,
    array $denominations = [],
    bool $countConfirmed = false,
    string $discrepancyReason = ''
): array {
    ensure_counter_store_columns();
    ensure_denomination_schema();
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

    $hasDenomInput = !empty($denominations);
    $denomData = null;

    if ($hasDenomInput) {
        if (!$countConfirmed) {
            return ['success' => false, 'error' => 'Cashier must confirm the physical cash count before closing the session.'];
        }
        $denomData = parse_denominations_input($denominations, $bid);
        $closingCashActual = $denomData['total_cash'];
    } elseif ($closingCashActual <= 0 && empty($denominations)) {
        return ['success' => false, 'error' => 'Denomination-wise cash count is required to close the register session.'];
    }

    $difference = $closingCashActual - $expectedCash;

    // Requirement 8: If there is any shortage or excess, the cashier must enter a reason.
    if (abs($difference) > 0.001) {
        $cleanReason = trim($discrepancyReason);
        if ($cleanReason === '') {
            $diffType = $difference < 0 ? 'shortage of ₹' . number_format(abs($difference), 2) : 'excess of ₹' . number_format($difference, 2);
            return [
                'success' => false,
                'error' => "A cash {$diffType} was detected. You must provide a reason explaining this discrepancy.",
            ];
        }
    }

    $stmt = $db->prepare('
        UPDATE register_sessions
        SET closed_at = NOW(),
            closing_cash_actual = :actual,
            closing_cash_expected = :expected,
            cash_difference = :diff,
            status = "closed",
            closing_notes = :notes,
            discrepancy_reason = :discrepancy_reason,
            closing_count_confirmed = :confirmed
        WHERE id = :id AND business_id = :bid
    ');
    $stmt->execute([
        'actual' => $closingCashActual,
        'expected' => $expectedCash,
        'diff' => $difference,
        'notes' => trim($notes) ?: null,
        'discrepancy_reason' => trim($discrepancyReason) ?: null,
        'confirmed' => $countConfirmed ? 1 : 0,
        'id' => $sessionId,
        'bid' => $bid,
    ]);

    if ($denomData && !empty($denomData['items'])) {
        $insStmt = $db->prepare('
            INSERT INTO session_cash_denominations (
                session_id, business_id, entry_type, denomination_id, denomination_value, quantity, total_amount, created_at
            ) VALUES (
                :sess_id, :bid, "closing", :denom_id, :val, :qty, :amt, NOW()
            )
        ');
        foreach ($denomData['items'] as $item) {
            $insStmt->execute([
                'sess_id' => $sessionId,
                'bid' => $bid,
                'denom_id' => $item['denomination_id'],
                'val' => $item['value'],
                'qty' => $item['quantity'],
                'amt' => $item['total_amount'],
            ]);
        }
    }

    return [
        'success' => true,
        'session_id' => $sessionId,
        'expected_cash' => $expectedCash,
        'actual_cash' => $closingCashActual,
        'difference' => $difference,
    ];
}

function get_register_sessions(int $limit = 50, ?int $businessId = null): array {
    ensure_counter_store_columns();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT s.*, r.name AS register_name, r.code AS register_code, r.outlet_id,
               o.name AS outlet_name, COALESCE(u.name, "Cashier") AS cashier_name
        FROM register_sessions s
        JOIN registers r ON r.id = s.register_id AND r.business_id = :bid_r
        LEFT JOIN outlets o ON o.id = r.outlet_id AND o.business_id = :bid_o
        LEFT JOIN users u ON u.id = s.user_id
        WHERE s.business_id = :bid
        ORDER BY s.id DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':bid', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':bid_r', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':bid_o', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_register_session_by_id(int $id, ?int $businessId = null): ?array {
    ensure_counter_store_columns();
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT s.*, r.name AS register_name, r.code AS register_code, r.outlet_id,
               o.name AS outlet_name, COALESCE(u.name, "Cashier") AS cashier_name
        FROM register_sessions s
        JOIN registers r ON r.id = s.register_id AND r.business_id = :bid_r
        LEFT JOIN outlets o ON o.id = r.outlet_id AND o.business_id = :bid_o
        LEFT JOIN users u ON u.id = s.user_id
        WHERE s.id = :id AND s.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid, 'bid_r' => $bid, 'bid_o' => $bid]);
    $res = $stmt->fetch();
    return $res ?: null;
}

/**
 * Fetch active denominations configured for this business (or global defaults)
 */
function get_active_denominations(?int $businessId = null): array {
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmt = $db->prepare('SELECT * FROM cash_denominations WHERE business_id = :bid AND is_active = 1 ORDER BY sort_order ASC, value DESC');
    $stmt->execute(['bid' => $bid]);
    $items = $stmt->fetchAll();

    if (empty($items)) {
        $stmtDefault = $db->query('SELECT * FROM cash_denominations WHERE business_id = 0 AND is_active = 1 ORDER BY sort_order ASC, value DESC');
        $items = $stmtDefault->fetchAll();
    }

    return $items;
}

/**
 * Fetch all denominations (active and inactive) for administration
 */
function get_all_denominations(?int $businessId = null): array {
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmt = $db->prepare('SELECT * FROM cash_denominations WHERE business_id = :bid ORDER BY sort_order ASC, value DESC');
    $stmt->execute(['bid' => $bid]);
    $items = $stmt->fetchAll();

    if (empty($items)) {
        // Clone default denominations for this business so they can be tailored
        $stmtDefault = $db->query('SELECT * FROM cash_denominations WHERE business_id = 0 ORDER BY sort_order ASC, value DESC');
        $defaults = $stmtDefault->fetchAll();
        if (!empty($defaults) && $bid > 0) {
            $insertStmt = $db->prepare('INSERT INTO cash_denominations (business_id, label, value, type, sort_order, is_active) VALUES (:bid, :label, :value, :type, :sort, :active)');
            foreach ($defaults as $d) {
                $insertStmt->execute([
                    'bid' => $bid,
                    'label' => $d['label'],
                    'value' => $d['value'],
                    'type' => $d['type'],
                    'sort' => $d['sort_order'],
                    'active' => $d['is_active'],
                ]);
            }
            $stmt = $db->prepare('SELECT * FROM cash_denominations WHERE business_id = :bid ORDER BY sort_order ASC, value DESC');
            $stmt->execute(['bid' => $bid]);
            $items = $stmt->fetchAll();
        } else {
            $items = $defaults;
        }
    }

    return $items;
}

/**
 * Parse and normalize denomination inputs from opening or closing form submissions
 */
function parse_denominations_input(array $rawInput, ?int $businessId = null): array {
    $denominations = get_active_denominations($businessId);
    $items = [];
    $totalCash = 0.00;
    $totalQuantity = 0;

    foreach ($denominations as $d) {
        $id = (int) $d['id'];
        $val = (float) $d['value'];
        $valKey = (string) (int) $val;
        $valKeyFloat = (string) $val;

        $qty = 0;
        if (isset($rawInput[$id])) {
            $qty = is_array($rawInput[$id]) ? (int) ($rawInput[$id]['quantity'] ?? 0) : (int) $rawInput[$id];
        } elseif (isset($rawInput[$valKey])) {
            $qty = is_array($rawInput[$valKey]) ? (int) ($rawInput[$valKey]['quantity'] ?? 0) : (int) $rawInput[$valKey];
        } elseif (isset($rawInput[$valKeyFloat])) {
            $qty = is_array($rawInput[$valKeyFloat]) ? (int) ($rawInput[$valKeyFloat]['quantity'] ?? 0) : (int) $rawInput[$valKeyFloat];
        }

        $qty = max(0, $qty);
        $lineTotal = round($val * $qty, 2);
        $totalCash += $lineTotal;
        $totalQuantity += $qty;

        $items[] = [
            'denomination_id' => $id,
            'label' => $d['label'],
            'value' => $val,
            'type' => $d['type'] ?? 'note',
            'quantity' => $qty,
            'total_amount' => $lineTotal,
        ];
    }

    return [
        'items' => $items,
        'total_cash' => round($totalCash, 2),
        'total_quantity' => $totalQuantity,
    ];
}

/**
 * Save / Update a currency denomination (Admin)
 */
function save_denomination(array $data, ?int $businessId = null): array {
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $id = (int) ($data['id'] ?? 0);
    $label = trim((string) ($data['label'] ?? ''));
    $value = (float) ($data['value'] ?? 0);
    $type = in_array($data['type'] ?? '', ['note', 'coin'], true) ? $data['type'] : 'note';
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $isActive = isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1;

    if ($value <= 0) {
        return ['success' => false, 'error' => 'Denomination value must be greater than zero.'];
    }
    if ($label === '') {
        $label = '₹' . (int)$value;
    }

    if ($id > 0) {
        $stmt = $db->prepare('
            UPDATE cash_denominations
            SET label = :label, value = :value, type = :type, sort_order = :sort, is_active = :active
            WHERE id = :id AND (business_id = :bid OR business_id = 0)
        ');
        $stmt->execute([
            'label' => $label,
            'value' => $value,
            'type' => $type,
            'sort' => $sortOrder,
            'active' => $isActive,
            'id' => $id,
            'bid' => $bid,
        ]);
        return ['success' => true, 'id' => $id];
    }

    $stmt = $db->prepare('
        INSERT INTO cash_denominations (business_id, label, value, type, sort_order, is_active)
        VALUES (:bid, :label, :value, :type, :sort, :active)
    ');
    $stmt->execute([
        'bid' => $bid,
        'label' => $label,
        'value' => $value,
        'type' => $type,
        'sort' => $sortOrder,
        'active' => $isActive,
    ]);

    return ['success' => true, 'id' => (int) $db->lastInsertId()];
}

/**
 * Delete a denomination (Admin)
 */
function delete_denomination(int $id, ?int $businessId = null): array {
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmt = $db->prepare('DELETE FROM cash_denominations WHERE id = :id AND (business_id = :bid OR business_id = 0)');
    $stmt->execute(['id' => $id, 'bid' => $bid]);
    return ['success' => true];
}

/**
 * Toggle active status of a denomination
 */
function toggle_denomination_status(int $id, bool $isActive, ?int $businessId = null): array {
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmt = $db->prepare('UPDATE cash_denominations SET is_active = :act WHERE id = :id AND (business_id = :bid OR business_id = 0)');
    $stmt->execute(['act' => $isActive ? 1 : 0, 'id' => $id, 'bid' => $bid]);
    return ['success' => true];
}

/**
 * Get recorded denomination breakdown for a register session
 */
function get_session_denominations(int $sessionId, ?string $entryType = null, ?int $businessId = null): array {
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $sql = '
        SELECT scd.*, COALESCE(cd.label, CONCAT("₹", FORMAT(scd.denomination_value, 0))) AS label,
               COALESCE(cd.type, "note") AS type
        FROM session_cash_denominations scd
        LEFT JOIN cash_denominations cd ON cd.id = scd.denomination_id
        WHERE scd.session_id = :sess_id
    ';
    $params = ['sess_id' => $sessionId];
    if ($bid > 0) {
        $sql .= ' AND (scd.business_id = :bid OR scd.business_id = 0)';
        $params['bid'] = $bid;
    }
    if ($entryType !== null && in_array($entryType, ['opening', 'closing'], true)) {
        $sql .= ' AND scd.entry_type = :etype';
        $params['etype'] = $entryType;
    }
    $sql .= ' ORDER BY scd.entry_type ASC, scd.denomination_value DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = [
        'opening' => [],
        'closing' => [],
        'opening_total' => 0.00,
        'closing_total' => 0.00,
        'opening_count' => 0,
        'closing_count' => 0,
    ];

    foreach ($rows as $r) {
        $etype = $r['entry_type'];
        $amt = (float) $r['total_amount'];
        $qty = (int) $r['quantity'];
        if ($etype === 'opening') {
            $result['opening'][] = $r;
            $result['opening_total'] += $amt;
            $result['opening_count'] += $qty;
        } else {
            $result['closing'][] = $r;
            $result['closing_total'] += $amt;
            $result['closing_count'] += $qty;
        }
    }

    $result['opening_total'] = round($result['opening_total'], 2);
    $result['closing_total'] = round($result['closing_total'], 2);

    return $result;
}

/**
 * Admin revision of recorded session cash denominations with mandatory audit trail
 */
function admin_update_session_denominations(
    int $sessionId,
    string $entryType,
    array $denominations,
    string $reason,
    int $adminUserId,
    ?int $businessId = null
): array {
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    // Verify admin privileges
    $adminStmt = $db->prepare('SELECT id, role, is_super_admin FROM users WHERE id = :id AND (business_id = :bid OR is_super_admin = 1) LIMIT 1');
    $adminStmt->execute(['id' => $adminUserId, 'bid' => $bid]);
    $adminUser = $adminStmt->fetch();
    if (!$adminUser || (!user_has_full_access($adminUser) && empty($adminUser['is_super_admin']))) {
        return ['success' => false, 'error' => 'Unauthorized: Only an Administrator or Store Owner can modify recorded denominations.'];
    }

    $reason = trim($reason);
    if ($reason === '') {
        return ['success' => false, 'error' => 'An audit explanation/reason is mandatory when modifying denomination records.'];
    }

    $session = get_register_session_by_id($sessionId, $bid);
    if (!$session) {
        return ['success' => false, 'error' => 'Session not found.'];
    }

    if (!in_array($entryType, ['opening', 'closing'], true)) {
        return ['success' => false, 'error' => 'Invalid entry type.'];
    }

    $prevDenoms = get_session_denominations($sessionId, $entryType, $bid)[$entryType] ?? [];
    $prevTotal = $entryType === 'opening' ? (float)$session['opening_cash'] : (float)$session['closing_cash_actual'];

    $parsed = parse_denominations_input($denominations, $bid);
    $newTotal = $parsed['total_cash'];

    $prevSnapshot = [
        'total' => $prevTotal,
        'items' => $prevDenoms,
    ];
    $newSnapshot = [
        'total' => $newTotal,
        'items' => $parsed['items'],
    ];

    $db->beginTransaction();
    try {
        // Record audit log
        $auditStmt = $db->prepare('
            INSERT INTO session_denomination_audit_logs (
                session_id, business_id, entry_type, admin_user_id, previous_data, new_data, reason, created_at
            ) VALUES (
                :sess_id, :bid, :etype, :admin_id, :prev, :new, :reason, NOW()
            )
        ');
        $auditStmt->execute([
            'sess_id' => $sessionId,
            'bid' => $bid,
            'etype' => $entryType,
            'admin_id' => $adminUserId,
            'prev' => json_encode($prevSnapshot, JSON_UNESCAPED_UNICODE),
            'new' => json_encode($newSnapshot, JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
        ]);

        // Remove old rows for this entry type
        $delStmt = $db->prepare('DELETE FROM session_cash_denominations WHERE session_id = :sess_id AND entry_type = :etype AND (business_id = :bid OR business_id = 0)');
        $delStmt->execute(['sess_id' => $sessionId, 'etype' => $entryType, 'bid' => $bid]);

        // Insert new rows
        $insStmt = $db->prepare('
            INSERT INTO session_cash_denominations (
                session_id, business_id, entry_type, denomination_id, denomination_value, quantity, total_amount, created_at
            ) VALUES (
                :sess_id, :bid, :etype, :denom_id, :val, :qty, :amt, NOW()
            )
        ');
        foreach ($parsed['items'] as $item) {
            $insStmt->execute([
                'sess_id' => $sessionId,
                'bid' => $bid,
                'etype' => $entryType,
                'denom_id' => $item['denomination_id'],
                'val' => $item['value'],
                'qty' => $item['quantity'],
                'amt' => $item['total_amount'],
            ]);
        }

        // Update register_sessions table
        if ($entryType === 'opening') {
            $updateSql = 'UPDATE register_sessions SET opening_cash = :new_open';
            $params = ['new_open' => $newTotal, 'id' => $sessionId, 'bid' => $bid];
            if ($session['status'] === 'closed') {
                $newExpected = $newTotal + (float)$session['total_cash_sales'] + (float)$session['cash_in'] - (float)$session['cash_out'] - (float)$session['total_refunds'];
                $newDiff = (float)$session['closing_cash_actual'] - $newExpected;
                $updateSql .= ', closing_cash_expected = :new_exp, cash_difference = :new_diff';
                $params['new_exp'] = $newExpected;
                $params['new_diff'] = $newDiff;
            }
            $updateSql .= ' WHERE id = :id AND business_id = :bid';
            $db->prepare($updateSql)->execute($params);
        } else {
            $newDiff = $newTotal - (float)$session['closing_cash_expected'];
            $db->prepare('UPDATE register_sessions SET closing_cash_actual = :new_actual, cash_difference = :new_diff WHERE id = :id AND business_id = :bid')
                ->execute(['new_actual' => $newTotal, 'new_diff' => $newDiff, 'id' => $sessionId, 'bid' => $bid]);
        }

        $db->commit();
        return ['success' => true, 'new_total' => $newTotal];
    } catch (Throwable $e) {
        $db->rollBack();
        return ['success' => false, 'error' => 'Database error updating denominations: ' . $e->getMessage()];
    }
}

/**
 * Fetch audit logs for a session
 */
function get_session_audit_logs(int $sessionId, ?int $businessId = null): array {
    ensure_denomination_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmt = $db->prepare('
        SELECT l.*, u.name AS admin_name, u.email AS admin_email
        FROM session_denomination_audit_logs l
        LEFT JOIN users u ON u.id = l.admin_user_id
        WHERE l.session_id = :sess_id AND (l.business_id = :bid OR :bid2 = 0)
        ORDER BY l.id DESC
    ');
    $stmt->execute(['sess_id' => $sessionId, 'bid' => $bid, 'bid2' => $bid]);
    return $stmt->fetchAll();
}

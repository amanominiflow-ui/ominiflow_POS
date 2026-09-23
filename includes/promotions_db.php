<?php
/**
 * Promotions, Coupons, Customer Groups & Loyalty Program Engine for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/* =========================================================================
   0. SCHEMA & TENANT SCOPE
   ========================================================================= */

function promotions_business_id(?int $businessId = null): int {
    $bid = $businessId ?: current_business_id();
    return max(1, (int) $bid);
}

function ensure_promotions_coupons_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }

    $db = get_db();
    if ($db->inTransaction()) {
        return;
    }
    $addColumn = static function (string $table, string $column, string $definition) use ($db): void {
        try {
            $stmt = $db->prepare('
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col
            ');
            $stmt->execute(['db' => DB_NAME, 'tbl' => $table, 'col' => $column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $db->exec("ALTER TABLE `{$table}` ADD `{$column}` {$definition}");
            }
        } catch (Throwable $e) {
            // Table may not exist yet.
        }
    };

    $addColumn('promotions', 'business_id', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`');
    $addColumn('coupons', 'business_id', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`');

    try {
        $indexes = $db->query('SHOW INDEX FROM `coupons`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $idx) {
            if ($idx['Key_name'] !== 'PRIMARY' && $idx['Column_name'] === 'code' && (int) $idx['Non_unique'] === 0) {
                if ($idx['Key_name'] !== 'uk_business_coupon_code') {
                    $kName = $idx['Key_name'];
                    try {
                        $db->exec("ALTER TABLE `coupons` DROP INDEX `{$kName}`");
                    } catch (Throwable $e) {
                    }
                }
            }
        }
        $db->exec('ALTER TABLE `coupons` ADD UNIQUE KEY `uk_business_coupon_code` (`business_id`, `code`)');
    } catch (Throwable $e) {
        // Index may already exist.
    }

    try {
        $db->exec('ALTER TABLE `promotions` ADD INDEX `idx_promotions_business` (`business_id`)');
    } catch (Throwable $e) {
    }
    try {
        $db->exec('ALTER TABLE `coupons` ADD INDEX `idx_coupons_business` (`business_id`)');
    } catch (Throwable $e) {
    }

    $done = true;
}

/**
 * Reassign promotion/coupon rows that were saved without an explicit tenant
 * when this install only has one business (no hardcoded business id).
 */
function realign_promotions_coupons_for_single_business(?int $businessId = null): void {
    ensure_promotions_coupons_schema();
    $bid = promotions_business_id($businessId);
    $db = get_db();

    try {
        $bizCount = (int) $db->query('SELECT COUNT(*) FROM businesses')->fetchColumn();
        if ($bizCount !== 1) {
            return;
        }
        $onlyBid = (int) $db->query('SELECT id FROM businesses ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($onlyBid <= 0 || $onlyBid !== $bid) {
            return;
        }
        $db->prepare('UPDATE promotions SET business_id = :bid WHERE business_id != :bid')->execute(['bid' => $onlyBid]);
        $db->prepare('UPDATE coupons SET business_id = :bid WHERE business_id != :bid')->execute(['bid' => $onlyBid]);
    } catch (Throwable $e) {
        // businesses table may not exist on very old installs.
    }
}

/* =========================================================================
   1. PROMOTIONS & DISCOUNTS
   ========================================================================= */

function create_promotion(array $data, ?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    $bid = promotions_business_id($businessId);
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        return ['success' => false, 'errors' => ['Promotion name is required.']];
    }

    $type = (string) ($data['promo_type'] ?? 'percentage');
    $allowedTypes = ['percentage', 'fixed_amount', 'buy_x_get_y'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'percentage';
    }

    $db = get_db();
    try {
        $stmt = $db->prepare('
            INSERT INTO promotions (
                business_id, name, promo_type, discount_value, buy_qty, get_qty, min_order_amount, status, created_at, updated_at
            ) VALUES (
                :bid, :name, :type, :val, :buy, :get, :min, "active", NOW(), NOW()
            )
        ');
        $stmt->execute([
            'bid' => $bid,
            'name' => $name,
            'type' => $type,
            'val' => (float) ($data['discount_value'] ?? 0),
            'buy' => (int) ($data['buy_qty'] ?? 0),
            'get' => (int) ($data['get_qty'] ?? 0),
            'min' => (float) ($data['min_order_amount'] ?? 0),
        ]);

        return ['success' => true, 'id' => (int) $db->lastInsertId(), 'name' => $name];
    } catch (PDOException $e) {
        return ['success' => false, 'errors' => ['Could not save promotion. Please try again.']];
    }
}

function create_coupon(array $data, ?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    $bid = promotions_business_id($businessId);
    $code = strtoupper(trim((string) ($data['code'] ?? '')));
    if ($code === '') {
        return ['success' => false, 'errors' => ['Coupon code is required.']];
    }

    $type = (string) ($data['discount_type'] ?? 'fixed');
    if (!in_array($type, ['fixed', 'percent'], true)) {
        $type = 'fixed';
    }

    $db = get_db();
    try {
        $stmt = $db->prepare('
            INSERT INTO coupons (
                business_id, code, discount_type, discount_value, min_order_amount, usage_limit, status, created_at, updated_at
            ) VALUES (
                :bid, :code, :type, :val, :min, :limit, "active", NOW(), NOW()
            )
        ');
        $stmt->execute([
            'bid' => $bid,
            'code' => $code,
            'type' => $type,
            'val' => (float) ($data['discount_value'] ?? 0),
            'min' => (float) ($data['min_order_amount'] ?? 0),
            'limit' => (int) ($data['usage_limit'] ?? 100),
        ]);

        return ['success' => true, 'id' => (int) $db->lastInsertId(), 'code' => $code];
    } catch (PDOException $e) {
        return ['success' => false, 'errors' => ["Coupon code '{$code}' already exists for this business."]];
    }
}

function delete_promotion(int $promotionId, ?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    $bid = promotions_business_id($businessId);
    if ($promotionId <= 0) {
        return ['success' => false, 'errors' => ['Invalid promotion.']];
    }
    $db = get_db();
    $stmt = $db->prepare('SELECT id, name FROM promotions WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $promotionId, 'bid' => $bid]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'errors' => ['Promotion not found.']];
    }
    $del = $db->prepare('DELETE FROM promotions WHERE id = :id AND business_id = :bid');
    $del->execute(['id' => $promotionId, 'bid' => $bid]);
    return ['success' => true, 'name' => (string) $row['name']];
}

function delete_coupon(int $couponId, ?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    $bid = promotions_business_id($businessId);
    if ($couponId <= 0) {
        return ['success' => false, 'errors' => ['Invalid coupon.']];
    }
    $db = get_db();
    $stmt = $db->prepare('SELECT id, code FROM coupons WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $couponId, 'bid' => $bid]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'errors' => ['Coupon not found.']];
    }
    $del = $db->prepare('DELETE FROM coupons WHERE id = :id AND business_id = :bid');
    $del->execute(['id' => $couponId, 'bid' => $bid]);
    return ['success' => true, 'code' => (string) $row['code']];
}

function get_promotions(string $status = '', ?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    realign_promotions_coupons_for_single_business($businessId);
    $db = get_db();
    $bid = promotions_business_id($businessId);
    $sql = 'SELECT * FROM promotions WHERE business_id = :bid';
    $params = ['bid' => $bid];
    if ($status !== '') {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY id DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function calculate_promotions_for_cart(array $cartItems, float $subtotal, ?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    $db = get_db();
    $bid = promotions_business_id($businessId);
    $today = date('Y-m-d');
    $stmt = $db->prepare('
        SELECT * FROM promotions 
        WHERE business_id = :bid
          AND status = "active" 
          AND (start_date IS NULL OR start_date <= :d1)
          AND (end_date IS NULL OR end_date >= :d2)
        ORDER BY id DESC
    ');
    $stmt->execute(['bid' => $bid, 'd1' => $today, 'd2' => $today]);
    $activePromos = $stmt->fetchAll();

    $totalPromoDiscount = 0.00;
    $appliedPromos = [];

    foreach ($activePromos as $promo) {
        $minOrder = (float)$promo['min_order_amount'];
        if ($minOrder > 0 && $subtotal < $minOrder) {
            continue;
        }

        if ($promo['promo_type'] === 'percentage') {
            $disc = round($subtotal * ((float)$promo['discount_value'] / 100), 2);
            $totalPromoDiscount += $disc;
            $appliedPromos[] = [
                'id' => (int)$promo['id'],
                'name' => $promo['name'],
                'discount' => $disc,
                'type' => 'percentage',
            ];
        } elseif ($promo['promo_type'] === 'fixed_amount') {
            $disc = min($subtotal, (float)$promo['discount_value']);
            $totalPromoDiscount += $disc;
            $appliedPromos[] = [
                'id' => (int)$promo['id'],
                'name' => $promo['name'],
                'discount' => $disc,
                'type' => 'fixed',
            ];
        } elseif ($promo['promo_type'] === 'buy_x_get_y') {
            $buyQty = (int)$promo['buy_qty'];
            $getQty = (int)$promo['get_qty'];
            if ($buyQty > 0 && $getQty > 0) {
                $totalUnitsInCart = array_sum(array_column($cartItems, 'quantity'));
                if ($totalUnitsInCart >= ($buyQty + $getQty)) {
                    // Apply discount equal to cheapest item in bundle
                    $freeItemsCount = (int)floor($totalUnitsInCart / ($buyQty + $getQty)) * $getQty;
                    $minPrice = !empty($cartItems) ? min(array_column($cartItems, 'price')) : 0.00;
                    $disc = round($minPrice * $freeItemsCount, 2);
                    $totalPromoDiscount += $disc;
                    $appliedPromos[] = [
                        'id' => (int)$promo['id'],
                        'name' => "{$promo['name']} (Buy {$buyQty} Get {$getQty} Free)",
                        'discount' => $disc,
                        'type' => 'buy_x_get_y',
                    ];
                }
            }
        }
    }

    return [
        'total_discount' => round($totalPromoDiscount, 2),
        'applied_promotions' => $appliedPromos,
    ];
}

/* =========================================================================
   2. COUPONS
   ========================================================================= */

function get_coupons(?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    realign_promotions_coupons_for_single_business($businessId);
    $db = get_db();
    $bid = promotions_business_id($businessId);
    $stmt = $db->prepare('SELECT * FROM coupons WHERE business_id = :bid ORDER BY id DESC');
    $stmt->execute(['bid' => $bid]);
    return $stmt->fetchAll();
}

function validate_and_apply_coupon(string $couponCode, float $subtotal, ?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    $db = get_db();
    $bid = promotions_business_id($businessId);
    $code = strtoupper(trim($couponCode));
    if ($code === '') {
        return ['valid' => false, 'error' => 'Coupon code is required.'];
    }

    $today = date('Y-m-d');
    $stmt = $db->prepare('
        SELECT * FROM coupons 
        WHERE code = :code AND business_id = :bid AND status = "active" 
          AND (start_date IS NULL OR start_date <= :d1)
          AND (end_date IS NULL OR end_date >= :d2)
        LIMIT 1
    ');
    $stmt->execute(['code' => $code, 'bid' => $bid, 'd1' => $today, 'd2' => $today]);
    $coupon = $stmt->fetch();

    if (!$coupon) {
        return ['valid' => false, 'error' => "Coupon '{$code}' is invalid or expired."];
    }

    if ((int)$coupon['usage_limit'] > 0 && (int)$coupon['usage_count'] >= (int)$coupon['usage_limit']) {
        return ['valid' => false, 'error' => "Coupon '{$code}' has reached its maximum usage limit."];
    }

    $minOrder = (float)$coupon['min_order_amount'];
    if ($minOrder > 0 && $subtotal < $minOrder) {
        return ['valid' => false, 'error' => "Minimum order of ₹" . number_format($minOrder, 2) . " required to use coupon '{$code}'."];
    }

    $discountAmount = 0.00;
    if ($coupon['discount_type'] === 'percent') {
        $discountAmount = round($subtotal * ((float)$coupon['discount_value'] / 100), 2);
        $maxDisc = (float)$coupon['max_discount_amount'];
        if ($maxDisc > 0 && $discountAmount > $maxDisc) {
            $discountAmount = $maxDisc;
        }
    } else {
        $discountAmount = min($subtotal, (float)$coupon['discount_value']);
    }

    return [
        'valid' => true,
        'coupon_id' => (int)$coupon['id'],
        'code' => $coupon['code'],
        'discount_amount' => round($discountAmount, 2),
        'discount_type' => $coupon['discount_type'],
    ];
}

function increment_coupon_usage(int $couponId, ?int $businessId = null): void {
    ensure_promotions_coupons_schema();
    $db = get_db();
    $bid = promotions_business_id($businessId);
    $stmt = $db->prepare('UPDATE coupons SET usage_count = usage_count + 1 WHERE id = :id AND business_id = :bid');
    $stmt->execute(['id' => $couponId, 'bid' => $bid]);
}

/* =========================================================================
   3. CUSTOMER GROUPS & LOYALTY PROGRAM
   ========================================================================= */

function get_customer_groups(?int $businessId = null): array {
    $db = get_db();
    $bid = promotions_business_id($businessId);
    $stmt = $db->prepare('SELECT * FROM customer_groups WHERE business_id = :bid ORDER BY id ASC');
    $stmt->execute(['bid' => $bid]);
    return $stmt->fetchAll();
}

function get_customer_loyalty_balance(int $customerId, ?int $businessId = null): int {
    $db = get_db();
    $bid = promotions_business_id($businessId);
    $stmt = $db->prepare('SELECT loyalty_points_balance FROM customers WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $customerId, 'bid' => $bid]);
    $pts = $stmt->fetchColumn();
    return $pts !== false ? (int)$pts : 0;
}

function record_loyalty_transaction(int $customerId, ?int $orderId, string $type, int $points, string $notes = '', ?int $businessId = null): int {
    $db = get_db();
    $bid = promotions_business_id($businessId);
    $currBalance = get_customer_loyalty_balance($customerId, $bid);

    $change = ($type === 'redeemed') ? -$points : $points;
    $newBalance = max(0, $currBalance + $change);

    $stmt = $db->prepare('
        INSERT INTO loyalty_transactions (business_id, customer_id, order_id, transaction_type, points, balance_after, notes, created_at)
        VALUES (:biz_id, :cid, :oid, :type, :pts, :after, :notes, NOW())
    ');
    $stmt->execute([
        'biz_id' => $bid,
        'cid' => $customerId,
        'oid' => $orderId,
        'type' => $type,
        'pts' => $points,
        'after' => $newBalance,
        'notes' => trim($notes) ?: null,
    ]);

    $stmtCust = $db->prepare('UPDATE customers SET loyalty_points_balance = :bal WHERE id = :id AND business_id = :bid');
    $stmtCust->execute(['bal' => $newBalance, 'id' => $customerId, 'bid' => $bid]);

    return $newBalance;
}

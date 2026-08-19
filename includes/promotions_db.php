<?php
/**
 * Promotions, Coupons, Customer Groups & Loyalty Program Engine for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* =========================================================================
   1. PROMOTIONS & DISCOUNTS
   ========================================================================= */

function get_promotions(string $status = ''): array {
    $db = get_db();
    $sql = 'SELECT * FROM promotions WHERE 1=1';
    $params = [];
    if ($status !== '') {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY id DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function calculate_promotions_for_cart(array $cartItems, float $subtotal): array {
    $db = get_db();
    $today = date('Y-m-d');
    $stmt = $db->prepare('
        SELECT * FROM promotions 
        WHERE status = "active" 
          AND (start_date IS NULL OR start_date <= :d1)
          AND (end_date IS NULL OR end_date >= :d2)
        ORDER BY id DESC
    ');
    $stmt->execute(['d1' => $today, 'd2' => $today]);
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

function get_coupons(): array {
    $db = get_db();
    return $db->query('SELECT * FROM coupons ORDER BY id DESC')->fetchAll();
}

function validate_and_apply_coupon(string $couponCode, float $subtotal): array {
    $db = get_db();
    $code = strtoupper(trim($couponCode));
    if ($code === '') {
        return ['valid' => false, 'error' => 'Coupon code is required.'];
    }

    $today = date('Y-m-d');
    $stmt = $db->prepare('
        SELECT * FROM coupons 
        WHERE code = :code AND status = "active" 
          AND (start_date IS NULL OR start_date <= :d1)
          AND (end_date IS NULL OR end_date >= :d2)
        LIMIT 1
    ');
    $stmt->execute(['code' => $code, 'd1' => $today, 'd2' => $today]);
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

function increment_coupon_usage(int $couponId): void {
    $db = get_db();
    $stmt = $db->prepare('UPDATE coupons SET usage_count = usage_count + 1 WHERE id = :id');
    $stmt->execute(['id' => $couponId]);
}

/* =========================================================================
   3. CUSTOMER GROUPS & LOYALTY PROGRAM
   ========================================================================= */

function get_customer_groups(): array {
    $db = get_db();
    return $db->query('SELECT * FROM customer_groups ORDER BY id ASC')->fetchAll();
}

function get_customer_loyalty_balance(int $customerId): int {
    $db = get_db();
    $stmt = $db->prepare('SELECT loyalty_points_balance FROM customers WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $customerId]);
    $pts = $stmt->fetchColumn();
    return $pts !== false ? (int)$pts : 0;
}

function record_loyalty_transaction(int $customerId, ?int $orderId, string $type, int $points, string $notes = ''): int {
    $db = get_db();
    $currBalance = get_customer_loyalty_balance($customerId);

    $change = ($type === 'redeemed') ? -$points : $points;
    $newBalance = max(0, $currBalance + $change);

    $stmt = $db->prepare('
        INSERT INTO loyalty_transactions (customer_id, order_id, transaction_type, points, balance_after, notes, created_at)
        VALUES (:cid, :oid, :type, :pts, :after, :notes, NOW())
    ');
    $stmt->execute([
        'cid' => $customerId,
        'oid' => $orderId,
        'type' => $type,
        'pts' => $points,
        'after' => $newBalance,
        'notes' => trim($notes) ?: null,
    ]);

    $stmtCust = $db->prepare('UPDATE customers SET loyalty_points_balance = :bal WHERE id = :id');
    $stmtCust->execute(['bal' => $newBalance, 'id' => $customerId]);

    return $newBalance;
}

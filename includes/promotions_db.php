<?php
/**
 * Promotions, Coupons, Customer Groups & Loyalty Program Engine for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/* =========================================================================
   1. PROMOTIONS & DISCOUNTS
   ========================================================================= */

function get_promotions(string $status = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
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
    $db = get_db();
    $bid = $businessId ?: current_business_id();
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
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM coupons WHERE business_id = :bid ORDER BY id DESC');
    $stmt->execute(['bid' => $bid]);
    return $stmt->fetchAll();
}

function validate_and_apply_coupon(string $couponCode, float $subtotal, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
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
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('UPDATE coupons SET usage_count = usage_count + 1 WHERE id = :id AND business_id = :bid');
    $stmt->execute(['id' => $couponId, 'bid' => $bid]);
}

/* =========================================================================
   3. CUSTOMER GROUPS & LOYALTY PROGRAM
   ========================================================================= */

function get_customer_groups(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM customer_groups WHERE business_id = :bid ORDER BY id ASC');
    $stmt->execute(['bid' => $bid]);
    return $stmt->fetchAll();
}

function get_customer_loyalty_balance(int $customerId, ?int $businessId = null): int {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT loyalty_points_balance FROM customers WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $customerId, 'bid' => $bid]);
    $pts = $stmt->fetchColumn();
    return $pts !== false ? (int)$pts : 0;
}

function record_loyalty_transaction(int $customerId, ?int $orderId, string $type, int $points, string $notes = '', ?int $businessId = null): int {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
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

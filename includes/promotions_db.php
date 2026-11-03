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
    $addColumn('promotions', 'starts_at', 'DATETIME NULL AFTER `end_date`');
    $addColumn('promotions', 'ends_at', 'DATETIME NULL AFTER `starts_at`');
    $addColumn('promotions', 'target_type', "VARCHAR(30) NOT NULL DEFAULT 'all' AFTER `ends_at`");
    $addColumn('promotions', 'priority', 'INT NOT NULL DEFAULT 1 AFTER `target_type`');

    try {
        $db->exec('CREATE TABLE IF NOT EXISTS `promotion_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `promotion_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `variant_id` INT UNSIGNED NULL DEFAULT NULL,
            `sku` VARCHAR(100) NULL,
            `original_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `discounted_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_promo_items_bid_pid` (`business_id`, `product_id`),
            INDEX `idx_promo_items_promo` (`promotion_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    } catch (Throwable $e) {
    }

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

function save_item_offer(array $data, ?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    $bid = promotions_business_id($businessId);
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        return ['success' => false, 'errors' => ['Offer name is required.']];
    }

    $discountPercent = (float) ($data['discount_percent'] ?? 0);
    if ($discountPercent <= 0 || $discountPercent > 100) {
        return ['success' => false, 'errors' => ['Offer percentage must be between 1% and 100%.']];
    }

    $productIds = [];
    if (!empty($data['product_ids']) && is_array($data['product_ids'])) {
        foreach ($data['product_ids'] as $pid) {
            $pidInt = (int) $pid;
            if ($pidInt > 0) {
                $productIds[] = $pidInt;
            }
        }
    }
    $productIds = array_values(array_unique($productIds));
    if (empty($productIds)) {
        return ['success' => false, 'errors' => ['Please select at least one item to apply this offer.']];
    }

    $rawStart = trim((string) ($data['start_date'] ?? ''));
    $rawEnd = trim((string) ($data['end_date'] ?? ''));
    $startDate = $rawStart !== '' ? date('Y-m-d', strtotime($rawStart)) : date('Y-m-d');
    $endDate = $rawEnd !== '' ? date('Y-m-d', strtotime($rawEnd)) : date('Y-m-d', strtotime('+30 days'));
    $startsAt = $startDate . ' 00:00:00';
    $endsAt = $endDate . ' 23:59:59';

    $db = get_db();
    try {
        $db->beginTransaction();

        $stmt = $db->prepare('
            INSERT INTO promotions (
                business_id, name, promo_type, discount_value, min_order_amount,
                start_date, end_date, starts_at, ends_at, target_type, status, created_at, updated_at
            ) VALUES (
                :bid, :name, "percentage", :val, 0.00,
                :sdate, :edate, :sat, :eat, "products", "active", NOW(), NOW()
            )
        ');
        $stmt->execute([
            'bid' => $bid,
            'name' => $name,
            'val' => $discountPercent,
            'sdate' => $startDate,
            'edate' => $endDate,
            'sat' => $startsAt,
            'eat' => $endsAt,
        ]);
        $promoId = (int) $db->lastInsertId();

        // Enforce: One item, one active offer. An item can have only one active offer at a time.
        // New offers automatically replace older ones.
        $inClause = implode(',', array_fill(0, count($productIds), '?'));
        $delStmt = $db->prepare("DELETE FROM promotion_items WHERE business_id = ? AND product_id IN ($inClause)");
        $delParams = array_merge([$bid], $productIds);
        $delStmt->execute($delParams);

        // Fetch products details
        $prodStmt = $db->prepare("SELECT id, sku, barcode, selling_price FROM products WHERE business_id = ? AND id IN ($inClause)");
        $prodStmt->execute($delParams);
        $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

        $insItemStmt = $db->prepare('
            INSERT INTO promotion_items (
                business_id, promotion_id, product_id, sku, original_price, discount_percent, discounted_price, created_at
            ) VALUES (
                :bid, :promo_id, :pid, :sku, :orig_price, :disc_pct, :disc_price, NOW()
            )
        ');

        foreach ($products as $prod) {
            $origPrice = (float) ($prod['selling_price'] ?? 0);
            $sku = (string) ($prod['sku'] ?? $prod['barcode'] ?? '');
            $discPrice = max(0.00, round($origPrice * (1.0 - ($discountPercent / 100.0)), 2));

            $insItemStmt->execute([
                'bid' => $bid,
                'promo_id' => $promoId,
                'pid' => (int) $prod['id'],
                'sku' => $sku,
                'orig_price' => $origPrice,
                'disc_pct' => $discountPercent,
                'disc_price' => $discPrice,
            ]);
        }

        // Clean up older product promotions that now have 0 items attached
        $db->exec("
            UPDATE promotions p
            LEFT JOIN promotion_items pi ON p.id = pi.promotion_id
            SET p.status = 'inactive'
            WHERE p.business_id = {$bid} AND p.target_type = 'products' AND p.id != {$promoId} AND pi.id IS NULL
        ");

        $db->commit();

        return [
            'success' => true,
            'promotion_id' => $promoId,
            'name' => $name,
            'items_count' => count($products),
            'discount_percent' => $discountPercent,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'errors' => ['Could not save offer: ' . $e->getMessage()]];
    }
}

function get_offer_eligible_products(?int $businessId = null, string $search = '', string $category = ''): array {
    ensure_promotions_coupons_schema();
    $bid = promotions_business_id($businessId);
    $db = get_db();

    $sql = "
        SELECT 
            p.id,
            p.name,
            p.sku,
            p.barcode,
            p.stock_quantity,
            COALESCE(NULLIF(p.mrp, 0), p.selling_price) AS mrp,
            p.selling_price,
            p.tax_percent,
            COALESCE(c.name, 'Uncategorized') AS category_name,
            p.category_id,
            act.promo_name AS current_offer_name,
            act.discount_percent AS current_offer_percent
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN (
            SELECT pi.product_id, pr.name AS promo_name, pi.discount_percent
            FROM promotion_items pi
            JOIN promotions pr ON pi.promotion_id = pr.id
            WHERE pi.business_id = :bid1
              AND pr.status = 'active'
              AND (
                  (pr.starts_at IS NOT NULL AND pr.ends_at IS NOT NULL AND NOW() BETWEEN pr.starts_at AND pr.ends_at)
                  OR
                  (pr.starts_at IS NULL AND (pr.start_date IS NULL OR pr.start_date <= CURDATE()) AND (pr.end_date IS NULL OR pr.end_date >= CURDATE()))
              )
        ) act ON p.id = act.product_id
        WHERE p.business_id = :bid2 AND p.status = 'active'
    ";
    $params = ['bid1' => $bid, 'bid2' => $bid];

    if ($category !== '' && $category !== 'all') {
        $sql .= " AND (c.name = :cat OR c.id = :cat_id)";
        $params['cat'] = $category;
        $params['cat_id'] = is_numeric($category) ? (int)$category : 0;
    }
    if ($search !== '') {
        $sql .= " AND (p.name LIKE :q OR p.sku LIKE :q OR p.barcode LIKE :q OR c.name LIKE :q)";
        $params['q'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY p.name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        $itemCode = !empty($r['sku']) ? $r['sku'] : (!empty($r['barcode']) ? $r['barcode'] : '-');
        $items[] = [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'category_name' => (string) $r['category_name'],
            'category_id' => (int) ($r['category_id'] ?? 0),
            'item_code' => $itemCode,
            'stock_quantity' => (int) $r['stock_quantity'],
            'stock_display' => (int) $r['stock_quantity'] . ' PCS',
            'mrp' => (float) $r['mrp'],
            'mrp_formatted' => '₹ ' . number_format((float) $r['mrp'], 2),
            'selling_price' => (float) $r['selling_price'],
            'selling_price_formatted' => '₹ ' . number_format((float) $r['selling_price'], 2),
            'tax_percent' => (float) $r['tax_percent'],
            'current_offer' => $r['current_offer_name'] ? ($r['current_offer_name'] . ' (' . (float)$r['current_offer_percent'] . '%)') : null,
        ];
    }
    return $items;
}

function get_active_product_offers(?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    $bid = promotions_business_id($businessId);
    $db = get_db();
    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.name,
            p.promo_type,
            p.discount_value,
            p.start_date,
            p.end_date,
            p.starts_at,
            p.ends_at,
            p.status,
            p.created_at,
            COUNT(pi.id) AS items_count
        FROM promotions p
        LEFT JOIN promotion_items pi ON p.id = pi.promotion_id
        WHERE p.business_id = :bid AND p.target_type = 'products'
        GROUP BY p.id
        ORDER BY p.id DESC
    ");
    $stmt->execute(['bid' => $bid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_all_active_product_offers_map(?int $businessId = null): array {
    ensure_promotions_coupons_schema();
    $bid = promotions_business_id($businessId);
    $db = get_db();
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    $stmt = $db->prepare("
        SELECT 
            pi.product_id,
            pi.sku,
            pi.discount_percent,
            pi.discounted_price,
            p.id AS promotion_id,
            p.name AS promo_name
        FROM promotion_items pi
        JOIN promotions p ON pi.promotion_id = p.id
        WHERE pi.business_id = :bid
          AND p.status = 'active'
          AND (
              (p.starts_at IS NOT NULL AND p.ends_at IS NOT NULL AND :now1 BETWEEN p.starts_at AND p.ends_at)
              OR
              (p.starts_at IS NULL AND (p.start_date IS NULL OR p.start_date <= :d1) AND (p.end_date IS NULL OR p.end_date >= :d2))
          )
        ORDER BY p.id DESC
    ");
    $stmt->execute(['bid' => $bid, 'now1' => $now, 'd1' => $today, 'd2' => $today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        $pid = (int) $r['product_id'];
        if ($pid > 0 && !isset($map[$pid])) {
            $map[$pid] = $r;
        }
    }
    return $map;
}

function get_product_active_offer(int $productId, ?int $businessId = null): ?array {
    $map = get_all_active_product_offers_map($businessId);
    return $map[$productId] ?? null;
}

function calculate_promotions_for_cart(array $cartItems, float $subtotal, ?int $businessId = null, ?int $promotionId = null): array {
    ensure_promotions_coupons_schema();
    $db = get_db();
    $bid = promotions_business_id($businessId);

    $totalPromoDiscount = 0.00;
    $appliedPromos = [];
    $itemDiscounts = [];
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    // 1. Automatic Item-level Product Offers (Eligibility checked against scanned items/SKU)
    $itemOfferStmt = $db->prepare("
        SELECT 
            pi.product_id,
            pi.sku,
            pi.discount_percent,
            pi.discounted_price,
            p.id AS promotion_id,
            p.name AS promo_name
        FROM promotion_items pi
        JOIN promotions p ON pi.promotion_id = p.id
        WHERE pi.business_id = :bid
          AND p.status = 'active'
          AND (
              (p.starts_at IS NOT NULL AND p.ends_at IS NOT NULL AND :now1 BETWEEN p.starts_at AND p.ends_at)
              OR
              (p.starts_at IS NULL AND (p.start_date IS NULL OR p.start_date <= :d1) AND (p.end_date IS NULL OR p.end_date >= :d2))
          )
    ");
    $itemOfferStmt->execute(['bid' => $bid, 'now1' => $now, 'd1' => $today, 'd2' => $today]);
    $itemOffers = $itemOfferStmt->fetchAll(PDO::FETCH_ASSOC);

    $itemOfferByPid = [];
    $itemOfferBySku = [];
    foreach ($itemOffers as $io) {
        $pid = (int) $io['product_id'];
        if ($pid > 0) {
            $itemOfferByPid[$pid] = $io;
        }
        $sku = trim((string)($io['sku'] ?? ''));
        if ($sku !== '') {
            $itemOfferBySku[strtoupper($sku)] = $io;
        }
    }

    if (!empty($cartItems) && (!empty($itemOfferByPid) || !empty($itemOfferBySku))) {
        foreach ($cartItems as $cItem) {
            $pid = (int) ($cItem['product_id'] ?? 0);
            $sku = strtoupper(trim((string) ($cItem['sku'] ?? '')));
            $matchedOffer = null;
            if ($pid > 0 && isset($itemOfferByPid[$pid])) {
                $matchedOffer = $itemOfferByPid[$pid];
            } elseif ($sku !== '' && isset($itemOfferBySku[$sku])) {
                $matchedOffer = $itemOfferBySku[$sku];
            }

            if ($matchedOffer !== null) {
                $itemPrice = (float) ($cItem['price'] ?? 0);
                $qty = max(1, (int) ($cItem['quantity'] ?? 1));
                $pct = (float) ($matchedOffer['discount_percent'] ?? 0);
                if ($pct > 0 && $itemPrice > 0) {
                    $discPerUnit = round($itemPrice * ($pct / 100.0), 2);
                    $discTotal = round($discPerUnit * $qty, 2);
                    $totalPromoDiscount += $discTotal;
                    $appliedPromos[] = [
                        'id' => (int) $matchedOffer['promotion_id'],
                        'product_id' => $pid,
                        'name' => "{$matchedOffer['promo_name']} ({$pct}% off)",
                        'discount' => $discTotal,
                        'type' => 'item_offer',
                    ];
                    $itemDiscounts[$pid] = [
                        'discount' => $discTotal,
                        'discount_percent' => $pct,
                        'promo_name' => $matchedOffer['promo_name'],
                    ];
                }
            }
        }
    }

    // 2. Order-level Promotions (applied if selected or general active)
    if ($promotionId !== null && $promotionId <= 0) {
        return [
            'total_discount' => round($totalPromoDiscount, 2),
            'applied_promotions' => $appliedPromos,
            'item_discounts' => $itemDiscounts,
        ];
    }

    $stmt = $db->prepare('
        SELECT * FROM promotions 
        WHERE business_id = :bid
          AND status = "active" 
          AND target_type != "products"
          AND (
              (starts_at IS NOT NULL AND ends_at IS NOT NULL AND :now1 BETWEEN starts_at AND ends_at)
              OR
              (starts_at IS NULL AND (start_date IS NULL OR start_date <= :d1) AND (end_date IS NULL OR end_date >= :d2))
          )
        ORDER BY id DESC
    ');
    $stmt->execute(['bid' => $bid, 'now1' => $now, 'd1' => $today, 'd2' => $today]);
    $activePromos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($promotionId !== null && $promotionId > 0) {
        $activePromos = array_values(array_filter($activePromos, static function ($promo) use ($promotionId) {
            return (int) ($promo['id'] ?? 0) === $promotionId;
        }));
    }

    $effectiveSubtotal = max(0.00, $subtotal - $totalPromoDiscount);

    foreach ($activePromos as $promo) {
        $minOrder = (float)$promo['min_order_amount'];
        if ($minOrder > 0 && $subtotal < $minOrder) {
            continue;
        }

        if ($promo['promo_type'] === 'percentage') {
            $disc = round($effectiveSubtotal * ((float)$promo['discount_value'] / 100), 2);
            $totalPromoDiscount += $disc;
            $appliedPromos[] = [
                'id' => (int)$promo['id'],
                'name' => $promo['name'],
                'discount' => $disc,
                'type' => 'percentage',
            ];
        } elseif ($promo['promo_type'] === 'fixed_amount') {
            $disc = min($effectiveSubtotal, (float)$promo['discount_value']);
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
        'item_discounts' => $itemDiscounts,
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

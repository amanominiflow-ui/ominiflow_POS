<?php
/**
 * Product Variants, Composite Products & Price Lists Service for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/* =========================================================================
   1. PRODUCT VARIANTS
   ========================================================================= */

function get_product_variants(int $productId, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM product_variants WHERE product_id = :pid AND business_id = :bid ORDER BY id ASC');
    $stmt->execute(['pid' => $productId, 'bid' => $bid]);
    return $stmt->fetchAll();
}

function save_product_variant(int $productId, array $data, ?int $variantId = null, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $name = trim((string)($data['variant_name'] ?? ''));
    $sku = strtoupper(trim((string)($data['sku'] ?? '')));
    $barcode = trim((string)($data['barcode'] ?? ''));
    $cost = (float)($data['cost_price'] ?? 0.00);
    $price = (float)($data['selling_price'] ?? 0.00);
    $stock = max(0, (int)($data['stock_quantity'] ?? 0));
    $status = (!empty($data['status']) && in_array($data['status'], ['active', 'inactive'], true)) ? $data['status'] : 'active';

    if ($name === '') return ['success' => false, 'error' => 'Variant name is required.'];
    if ($sku === '') $sku = 'VAR-' . strtoupper(substr(uniqid(), -6));

    try {
        if ($variantId !== null && $variantId > 0) {
            $stmt = $db->prepare('
                UPDATE product_variants
                SET variant_name = :name, sku = :sku, barcode = :barcode, cost_price = :cost, selling_price = :price, stock_quantity = :stock, status = :status, updated_at = NOW()
                WHERE id = :id AND business_id = :bid
            ');
            $stmt->execute([
                'name' => $name, 'sku' => $sku, 'barcode' => $barcode ?: null,
                'cost' => $cost, 'price' => $price, 'stock' => $stock, 'status' => $status, 'id' => $variantId,
                'bid' => $bid,
            ]);
            return ['success' => true, 'variant_id' => $variantId];
        } else {
            $stmt = $db->prepare('
                INSERT INTO product_variants (business_id, product_id, variant_name, sku, barcode, cost_price, selling_price, stock_quantity, status, created_at, updated_at)
                VALUES (:biz_id, :pid, :name, :sku, :barcode, :cost, :price, :stock, :status, NOW(), NOW())
            ');
            $stmt->execute([
                'biz_id' => $bid,
                'pid' => $productId, 'name' => $name, 'sku' => $sku, 'barcode' => $barcode ?: null,
                'cost' => $cost, 'price' => $price, 'stock' => $stock, 'status' => $status,
            ]);
            $newId = (int)$db->lastInsertId();

            // Mark parent product as variable
            $stmtProd = $db->prepare("UPDATE products SET product_type = 'variable' WHERE id = :pid AND business_id = :bid");
            $stmtProd->execute(['pid' => $productId, 'bid' => $bid]);

            return ['success' => true, 'variant_id' => $newId];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/* =========================================================================
   2. COMPOSITE / BUNDLE PRODUCTS
   ========================================================================= */

function get_composite_items(int $parentProductId, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT cpi.*, p.name AS component_name, p.sku AS component_sku, p.selling_price, p.stock_quantity AS component_stock
        FROM composite_product_items cpi
        JOIN products p ON p.id = cpi.component_product_id AND p.business_id = :bid_p
        WHERE cpi.parent_product_id = :pid AND cpi.business_id = :bid
    ');
    $stmt->execute(['pid' => $parentProductId, 'bid' => $bid, 'bid_p' => $bid]);
    return $stmt->fetchAll();
}

function save_composite_bundle(int $parentProductId, array $componentItems, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    try {
        $db->beginTransaction();

        $stmtDel = $db->prepare('DELETE FROM composite_product_items WHERE parent_product_id = :pid AND business_id = :bid');
        $stmtDel->execute(['pid' => $parentProductId, 'bid' => $bid]);

        $stmtIns = $db->prepare('
            INSERT INTO composite_product_items (business_id, parent_product_id, component_product_id, quantity, created_at)
            VALUES (:biz_id, :parent_id, :comp_id, :qty, NOW())
        ');

        foreach ($componentItems as $item) {
            $compId = (int)($item['component_product_id'] ?? 0);
            $qty = max(1, (int)($item['quantity'] ?? 1));
            if ($compId <= 0 || $compId === $parentProductId) continue;

            $stmtIns->execute([
                'biz_id' => $bid,
                'parent_id' => $parentProductId,
                'comp_id' => $compId,
                'qty' => $qty,
            ]);
        }

        $stmtProd = $db->prepare("UPDATE products SET product_type = 'composite' WHERE id = :pid AND business_id = :bid");
        $stmtProd->execute(['pid' => $parentProductId, 'bid' => $bid]);

        $db->commit();
        return ['success' => true];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/* =========================================================================
   3. PRICE LISTS
   ========================================================================= */

function get_price_lists(string $status = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = 'SELECT * FROM price_lists WHERE business_id = :bid';
    $params = ['bid' => $bid];
    if ($status !== '') {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_price_list_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM price_lists WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $id, 'bid' => $bid]);
    $pl = $stmt->fetch();
    if (!$pl) return null;

    $stmtItems = $db->prepare('
        SELECT pli.*, p.name AS product_name, p.sku AS product_sku, p.selling_price AS standard_price
        FROM price_list_items pli
        JOIN products p ON p.id = pli.product_id AND p.business_id = :bid_p
        WHERE pli.price_list_id = :id AND pli.business_id = :bid
    ');
    $stmtItems->execute(['id' => $id, 'bid' => $bid, 'bid_p' => $bid]);
    $pl['items'] = $stmtItems->fetchAll();

    return $pl;
}

function get_effective_product_price(int $productId, ?int $priceListId = null, ?int $businessId = null): float {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmtP = $db->prepare('SELECT selling_price FROM products WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmtP->execute(['id' => $productId, 'bid' => $bid]);
    $stdPrice = (float)$stmtP->fetchColumn();

    if ($priceListId === null || $priceListId <= 1) {
        return $stdPrice;
    }

    $stmtPL = $db->prepare('SELECT * FROM price_lists WHERE id = :id AND business_id = :bid AND status = "active" LIMIT 1');
    $stmtPL->execute(['id' => $priceListId, 'bid' => $bid]);
    $pl = $stmtPL->fetch();
    if (!$pl) return $stdPrice;

    // Check custom item price
    $stmtItem = $db->prepare('SELECT custom_price FROM price_list_items WHERE price_list_id = :plid AND product_id = :pid AND business_id = :bid LIMIT 1');
    $stmtItem->execute(['plid' => $priceListId, 'pid' => $productId, 'bid' => $bid]);
    $custom = $stmtItem->fetchColumn();
    if ($custom !== false && (float)$custom > 0) {
        return (float)$custom;
    }

    // Percentage calculation
    if ($pl['type'] === 'percentage' && (float)$pl['percentage_value'] > 0) {
        $discount = $stdPrice * ((float)$pl['percentage_value'] / 100);
        return max(0.00, round($stdPrice - $discount, 2));
    }

    return $stdPrice;
}

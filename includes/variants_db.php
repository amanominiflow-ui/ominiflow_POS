<?php
/**
 * Product Variants, Composite Products & Price Lists Service for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* =========================================================================
   1. PRODUCT VARIANTS
   ========================================================================= */

function get_product_variants(int $productId): array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM product_variants WHERE product_id = :pid ORDER BY id ASC');
    $stmt->execute(['pid' => $productId]);
    return $stmt->fetchAll();
}

function save_product_variant(int $productId, array $data, ?int $variantId = null): array {
    $db = get_db();
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
                WHERE id = :id
            ');
            $stmt->execute([
                'name' => $name, 'sku' => $sku, 'barcode' => $barcode ?: null,
                'cost' => $cost, 'price' => $price, 'stock' => $stock, 'status' => $status, 'id' => $variantId,
            ]);
            return ['success' => true, 'variant_id' => $variantId];
        } else {
            $stmt = $db->prepare('
                INSERT INTO product_variants (product_id, variant_name, sku, barcode, cost_price, selling_price, stock_quantity, status, created_at, updated_at)
                VALUES (:pid, :name, :sku, :barcode, :cost, :price, :stock, :status, NOW(), NOW())
            ');
            $stmt->execute([
                'pid' => $productId, 'name' => $name, 'sku' => $sku, 'barcode' => $barcode ?: null,
                'cost' => $cost, 'price' => $price, 'stock' => $stock, 'status' => $status,
            ]);
            $newId = (int)$db->lastInsertId();

            // Mark parent product as variable
            $db->exec("UPDATE products SET product_type = 'variable' WHERE id = {$productId}");

            return ['success' => true, 'variant_id' => $newId];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/* =========================================================================
   2. COMPOSITE / BUNDLE PRODUCTS
   ========================================================================= */

function get_composite_items(int $parentProductId): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT cpi.*, p.name AS component_name, p.sku AS component_sku, p.selling_price, p.stock_quantity AS component_stock
        FROM composite_product_items cpi
        JOIN products p ON p.id = cpi.component_product_id
        WHERE cpi.parent_product_id = :pid
    ');
    $stmt->execute(['pid' => $parentProductId]);
    return $stmt->fetchAll();
}

function save_composite_bundle(int $parentProductId, array $componentItems): array {
    $db = get_db();

    try {
        $db->beginTransaction();

        $stmtDel = $db->prepare('DELETE FROM composite_product_items WHERE parent_product_id = :pid');
        $stmtDel->execute(['pid' => $parentProductId]);

        $stmtIns = $db->prepare('
            INSERT INTO composite_product_items (parent_product_id, component_product_id, quantity, created_at)
            VALUES (:parent_id, :comp_id, :qty, NOW())
        ');

        foreach ($componentItems as $item) {
            $compId = (int)($item['component_product_id'] ?? 0);
            $qty = max(1, (int)($item['quantity'] ?? 1));
            if ($compId <= 0 || $compId === $parentProductId) continue;

            $stmtIns->execute([
                'parent_id' => $parentProductId,
                'comp_id' => $compId,
                'qty' => $qty,
            ]);
        }

        $db->exec("UPDATE products SET product_type = 'composite' WHERE id = {$parentProductId}");

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

function get_price_lists(string $status = ''): array {
    $db = get_db();
    $sql = 'SELECT * FROM price_lists WHERE 1=1';
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

function get_price_list_by_id(int $id): ?array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM price_lists WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $pl = $stmt->fetch();
    if (!$pl) return null;

    $stmtItems = $db->prepare('
        SELECT pli.*, p.name AS product_name, p.sku AS product_sku, p.selling_price AS standard_price
        FROM price_list_items pli
        JOIN products p ON p.id = pli.product_id
        WHERE pli.price_list_id = :id
    ');
    $stmtItems->execute(['id' => $id]);
    $pl['items'] = $stmtItems->fetchAll();

    return $pl;
}

function get_effective_product_price(int $productId, ?int $priceListId = null): float {
    $db = get_db();
    $stmtP = $db->prepare('SELECT selling_price FROM products WHERE id = :id LIMIT 1');
    $stmtP->execute(['id' => $productId]);
    $stdPrice = (float)$stmtP->fetchColumn();

    if ($priceListId === null || $priceListId <= 1) {
        return $stdPrice;
    }

    $stmtPL = $db->prepare('SELECT * FROM price_lists WHERE id = :id AND status = "active" LIMIT 1');
    $stmtPL->execute(['id' => $priceListId]);
    $pl = $stmtPL->fetch();
    if (!$pl) return $stdPrice;

    // Check custom item price
    $stmtItem = $db->prepare('SELECT custom_price FROM price_list_items WHERE price_list_id = :plid AND product_id = :pid LIMIT 1');
    $stmtItem->execute(['plid' => $priceListId, 'pid' => $productId]);
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

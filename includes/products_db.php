<?php
/**
 * Product, Category, and Inventory Database Services for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/* =========================================================================
   1. CATEGORY OPERATIONS
   ========================================================================= */

function get_categories(string $search = '', string $status = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT c.*, COUNT(p.id) AS product_count
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.id AND p.business_id = :biz_id_prod
        WHERE c.business_id = :biz_id
    ';
    $params = [
        'biz_id' => $bid,
        'biz_id_prod' => $bid
    ];

    if ($search !== '') {
        $sql .= ' AND (c.name LIKE :search1 OR c.code LIKE :search2 OR c.description LIKE :search3)';
        $params['search1'] = '%' . $search . '%';
        $params['search2'] = '%' . $search . '%';
        $params['search3'] = '%' . $search . '%';
    }

    if ($status !== '' && in_array($status, ['active', 'inactive'], true)) {
        $sql .= ' AND c.status = :status';
        $params['status'] = $status;
    }

    $sql .= ' GROUP BY c.id ORDER BY c.name ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_category_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = :id AND business_id = :biz_id LIMIT 1');
    $stmt->execute(['id' => $id, 'biz_id' => $bid]);
    $cat = $stmt->fetch();
    return $cat ?: null;
}

function get_category_by_code(string $code, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM categories WHERE code = :code AND business_id = :biz_id LIMIT 1');
    $stmt->execute(['code' => strtoupper(trim($code)), 'biz_id' => $bid]);
    $cat = $stmt->fetch();
    return $cat ?: null;
}

function save_category(array $data, ?int $id = null, ?int $businessId = null): array {
    $errors = [];
    $bid = $businessId ?: current_business_id();
    $name = trim((string) ($data['name'] ?? ''));
    $code = strtoupper(trim((string) ($data['code'] ?? '')));
    $description = trim((string) ($data['description'] ?? ''));
    $status = in_array(($data['status'] ?? 'active'), ['active', 'inactive'], true) ? $data['status'] : 'active';

    if ($name === '') {
        $errors['name'] = 'Category name is required.';
    }

    if ($code === '') {
        // Auto-generate code from name
        $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 10));
        if ($code === '') {
            $code = 'CAT' . time();
        }
    }

    // Check unique code within the same business
    $existing = get_category_by_code($code, $bid);
    if ($existing && ($id === null || (int) $existing['id'] !== $id)) {
        $errors['code'] = 'A category with code "' . $code . '" already exists.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    try {
        $db = get_db();
        if ($id !== null) {
            $stmt = $db->prepare('
                UPDATE categories
                SET name = :name, code = :code, description = :description, status = :status, updated_at = NOW()
                WHERE id = :id AND business_id = :biz_id
            ');
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'status' => $status,
                'id' => $id,
                'biz_id' => $bid,
            ]);
            $categoryId = $id;
        } else {
            $stmt = $db->prepare('
                INSERT INTO categories (business_id, name, code, description, status, created_at, updated_at)
                VALUES (:biz_id, :name, :code, :description, :status, NOW(), NOW())
            ');
            $stmt->execute([
                'biz_id' => $bid,
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'status' => $status,
            ]);
            $categoryId = (int) $db->lastInsertId();
        }

        return ['success' => true, 'errors' => [], 'category_id' => $categoryId];
    } catch (PDOException $e) {
        return ['success' => false, 'errors' => ['general' => 'Database error: ' . $e->getMessage()]];
    }
}

function delete_category(int $id, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    
    // Check if category exists
    $cat = get_category_by_id($id, $bid);
    if (!$cat) {
        return ['success' => false, 'error' => 'Category not found.'];
    }

    // Check if products are assigned to this category
    $stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id AND business_id = :biz_id');
    $stmt->execute(['id' => $id, 'biz_id' => $bid]);
    $count = (int) $stmt->fetchColumn();

    if ($count > 0) {
        return [
            'success' => false,
            'error' => 'Cannot delete category "' . $cat['name'] . '" because ' . $count . ' product(s) are assigned to it. Please reassign or delete the products first.',
        ];
    }

    $stmt = $db->prepare('DELETE FROM categories WHERE id = :id AND business_id = :biz_id');
    $stmt->execute(['id' => $id, 'biz_id' => $bid]);

    return ['success' => true];
}

/* =========================================================================
   2. PRODUCT OPERATIONS
   ========================================================================= */

function get_products(string $search = '', ?int $categoryId = null, string $status = '', string $stockFilter = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT p.*, c.name AS category_name, c.code AS category_code
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id AND c.business_id = :biz_id_cat
        WHERE p.business_id = :biz_id
    ';
    $params = [
        'biz_id' => $bid,
        'biz_id_cat' => $bid
    ];

    if ($search !== '') {
        $sql .= ' AND (p.name LIKE :search1 OR p.sku LIKE :search2 OR p.barcode LIKE :search3)';
        $params['search1'] = '%' . $search . '%';
        $params['search2'] = '%' . $search . '%';
        $params['search3'] = '%' . $search . '%';
    }

    if ($categoryId !== null && $categoryId > 0) {
        $sql .= ' AND p.category_id = :cat_id';
        $params['cat_id'] = $categoryId;
    }

    if ($status !== '' && in_array($status, ['active', 'inactive'], true)) {
        $sql .= ' AND p.status = :status';
        $params['status'] = $status;
    }

    if ($stockFilter === 'low_stock') {
        $sql .= ' AND p.stock_quantity > 0 AND p.stock_quantity <= p.low_stock_threshold';
    } elseif ($stockFilter === 'out_of_stock') {
        $sql .= ' AND p.stock_quantity <= 0';
    } elseif ($stockFilter === 'in_stock') {
        $sql .= ' AND p.stock_quantity > p.low_stock_threshold';
    }

    $sql .= ' ORDER BY p.id DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_product_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT p.*, c.name AS category_name, c.code AS category_code
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id AND c.business_id = :biz_id_cat
        WHERE p.id = :id AND p.business_id = :biz_id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'biz_id' => $bid, 'biz_id_cat' => $bid]);
    $prod = $stmt->fetch();
    return $prod ?: null;
}

function get_product_by_sku(string $sku, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM products WHERE sku = :sku AND business_id = :biz_id LIMIT 1');
    $stmt->execute(['sku' => trim($sku), 'biz_id' => $bid]);
    $prod = $stmt->fetch();
    return $prod ?: null;
}

function get_product_by_barcode(string $barcode, ?int $businessId = null): ?array {
    if (trim($barcode) === '') return null;
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM products WHERE barcode = :barcode AND business_id = :biz_id LIMIT 1');
    $stmt->execute(['barcode' => trim($barcode), 'biz_id' => $bid]);
    $prod = $stmt->fetch();
    return $prod ?: null;
}

function handle_product_image_upload(?array $file, ?string $oldPath = null): ?string {
    if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return $oldPath;
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

    $fileInfo = pathinfo($file['name']);
    $ext = strtolower($fileInfo['extension'] ?? '');

    if (!in_array($ext, $allowedExts, true)) {
        return $oldPath;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMimes, true)) {
        return $oldPath;
    }

    // Max 5MB
    if ($file['size'] > 5 * 1024 * 1024) {
        return $oldPath;
    }

    $uploadDir = __DIR__ . '/../assets/uploads/products/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newFileName = 'prod_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $targetPath = $uploadDir . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Delete old image if exists
        if ($oldPath && file_exists(__DIR__ . '/../' . ltrim($oldPath, '/'))) {
            @unlink(__DIR__ . '/../' . ltrim($oldPath, '/'));
        }
        return 'assets/uploads/products/' . $newFileName;
    }

    return $oldPath;
}

function save_product(array $data, ?array $file = null, ?int $id = null, ?int $userId = null, ?int $businessId = null): array {
    $errors = [];
    $bid = $businessId ?: current_business_id();
    $name = trim((string) ($data['name'] ?? ''));
    $sku = strtoupper(trim((string) ($data['sku'] ?? '')));
    $barcode = trim((string) ($data['barcode'] ?? ''));
    $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;
    $costPrice = (float) ($data['cost_price'] ?? 0.00);
    $sellingPrice = (float) ($data['selling_price'] ?? 0.00);
    $taxPercent = (float) ($data['tax_percent'] ?? 0.00);
    $lowStockThreshold = max(0, (int) ($data['low_stock_threshold'] ?? 5));
    $status = (!empty($data['status']) && in_array($data['status'], ['active', 'inactive'], true)) ? $data['status'] : 'active';
    $initialStock = max(0, (int) ($data['initial_stock'] ?? 0));

    if ($name === '') {
        $errors['name'] = 'Product name is required.';
    }

    if ($sku === '') {
        $sku = 'SKU-' . strtoupper(substr(uniqid(), -6));
    }

    // Check unique SKU within the same business
    $existingSku = get_product_by_sku($sku, $bid);
    if ($existingSku && ($id === null || (int) $existingSku['id'] !== $id)) {
        $errors['sku'] = 'A product with SKU "' . $sku . '" already exists.';
    }

    // Check unique Barcode within the same business
    if ($barcode !== '') {
        $existingBarcode = get_product_by_barcode($barcode, $bid);
        if ($existingBarcode && ($id === null || (int) $existingBarcode['id'] !== $id)) {
            $errors['barcode'] = 'A product with Barcode "' . $barcode . '" already exists.';
        }
    } else {
        $barcode = null;
    }

    if ($sellingPrice < 0) {
        $errors['selling_price'] = 'Selling price cannot be negative.';
    }

    if ($costPrice < 0) {
        $errors['cost_price'] = 'Cost price cannot be negative.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    $db = get_db();
    $oldProduct = $id !== null ? get_product_by_id($id, $bid) : null;
    $imagePath = handle_product_image_upload($file, $oldProduct['image_path'] ?? null);

    try {
        if ($id !== null) {
            $stmt = $db->prepare('
                UPDATE products
                SET category_id = :category_id,
                    name = :name,
                    sku = :sku,
                    barcode = :barcode,
                    cost_price = :cost_price,
                    selling_price = :selling_price,
                    tax_percent = :tax_percent,
                    low_stock_threshold = :low_stock_threshold,
                    image_path = :image_path,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND business_id = :biz_id
            ');
            $stmt->execute([
                'category_id' => $categoryId,
                'name' => $name,
                'sku' => $sku,
                'barcode' => $barcode,
                'cost_price' => $costPrice,
                'selling_price' => $sellingPrice,
                'tax_percent' => $taxPercent,
                'low_stock_threshold' => $lowStockThreshold,
                'image_path' => $imagePath,
                'status' => $status,
                'id' => $id,
                'biz_id' => $bid,
            ]);
            $productId = $id;
        } else {
            $stmt = $db->prepare('
                INSERT INTO products (
                    business_id, category_id, name, sku, barcode, cost_price, selling_price,
                    tax_percent, stock_quantity, low_stock_threshold, image_path, status, created_at, updated_at
                ) VALUES (
                    :biz_id, :category_id, :name, :sku, :barcode, :cost_price, :selling_price,
                    :tax_percent, :stock_quantity, :low_stock_threshold, :image_path, :status, NOW(), NOW()
                )
            ');
            $stmt->execute([
                'biz_id' => $bid,
                'category_id' => $categoryId,
                'name' => $name,
                'sku' => $sku,
                'barcode' => $barcode,
                'cost_price' => $costPrice,
                'selling_price' => $sellingPrice,
                'tax_percent' => $taxPercent,
                'stock_quantity' => $initialStock,
                'low_stock_threshold' => $lowStockThreshold,
                'image_path' => $imagePath,
                'status' => $status,
            ]);
            $productId = (int) $db->lastInsertId();

            // Record initial stock movement if quantity > 0
            if ($initialStock > 0) {
                $stmtMove = $db->prepare('
                    INSERT INTO inventory_movements (
                        business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
                    ) VALUES (
                        :biz_id, :product_id, :user_id, :movement_type, :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
                    )
                ');
                $stmtMove->execute([
                    'biz_id' => $bid,
                    'product_id' => $productId,
                    'user_id' => $userId,
                    'movement_type' => 'in',
                    'quantity_change' => $initialStock,
                    'quantity_before' => 0,
                    'quantity_after' => $initialStock,
                    'reason' => 'Initial opening stock upon product creation',
                ]);
            }
        }

        return ['success' => true, 'errors' => [], 'product_id' => $productId];
    } catch (PDOException $e) {
        return ['success' => false, 'errors' => ['general' => 'Database error: ' . $e->getMessage()]];
    }
}

function delete_product(int $id, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $prod = get_product_by_id($id, $bid);
    if (!$prod) {
        return ['success' => false, 'error' => 'Product not found.'];
    }

    if ($prod['image_path'] && file_exists(__DIR__ . '/../' . ltrim($prod['image_path'], '/'))) {
        @unlink(__DIR__ . '/../' . ltrim($prod['image_path'], '/'));
    }

    $stmt = $db->prepare('DELETE FROM products WHERE id = :id AND business_id = :biz_id');
    $stmt->execute(['id' => $id, 'biz_id' => $bid]);

    return ['success' => true];
}

/* =========================================================================
   3. INVENTORY & STOCK ADJUSTMENT OPERATIONS
   ========================================================================= */

function adjust_stock(int $productId, ?int $userId, string $movementType, int $quantity, string $reason, ?int $businessId = null): array {
    $errors = [];
    $bid = $businessId ?: current_business_id();
    $movementType = strtolower(trim($movementType));
    $reason = trim($reason);

    if (!in_array($movementType, ['in', 'out', 'adjustment'], true)) {
        $errors['movement_type'] = 'Invalid stock movement type.';
    }

    if ($quantity <= 0 && $movementType !== 'adjustment') {
        $errors['quantity'] = 'Quantity must be greater than zero.';
    }

    if ($reason === '') {
        $errors['reason'] = 'Reason for stock adjustment is required.';
    }

    $product = get_product_by_id($productId, $bid);
    if (!$product) {
        $errors['product'] = 'Product not found.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    $currentStock = (int) $product['stock_quantity'];
    $newStock = $currentStock;
    $change = 0;

    if ($movementType === 'in') {
        $change = $quantity;
        $newStock = $currentStock + $quantity;
    } elseif ($movementType === 'out') {
        if ($quantity > $currentStock) {
            return [
                'success' => false,
                'errors' => ['quantity' => 'Cannot decrease stock by ' . $quantity . ' units. Current stock is only ' . $currentStock . ' units.'],
            ];
        }
        $change = -$quantity;
        $newStock = $currentStock - $quantity;
    } elseif ($movementType === 'adjustment') {
        // Here $quantity represents the exact new physical counted stock
        $change = $quantity - $currentStock;
        $newStock = $quantity;
    }

    $db = get_db();
    try {
        $db->beginTransaction();

        // 1. Update product stock
        $stmtUpdate = $db->prepare('UPDATE products SET stock_quantity = :stock, updated_at = NOW() WHERE id = :id AND business_id = :biz_id');
        $stmtUpdate->execute(['stock' => $newStock, 'id' => $productId, 'biz_id' => $bid]);

        // 2. Insert inventory movement log
        $stmtMove = $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :biz_id, :product_id, :user_id, :movement_type, :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
            )
        ');
        $stmtMove->execute([
            'biz_id' => $bid,
            'product_id' => $productId,
            'user_id' => $userId,
            'movement_type' => $movementType,
            'quantity_change' => $change,
            'quantity_before' => $currentStock,
            'quantity_after' => $newStock,
            'reason' => $reason,
        ]);

        $db->commit();

        return [
            'success' => true,
            'errors' => [],
            'stock_before' => $currentStock,
            'stock_after' => $newStock,
            'quantity_change' => $change,
        ];
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'errors' => ['general' => 'Database error: ' . $e->getMessage()]];
    }
}

function get_inventory_movements(?int $productId = null, int $limit = 50, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT m.*, p.name AS product_name, p.sku AS product_sku, u.name AS user_name
        FROM inventory_movements m
        JOIN products p ON p.id = m.product_id AND p.business_id = :biz_id_p
        LEFT JOIN users u ON u.id = m.user_id
        WHERE m.business_id = :biz_id
    ';
    $params = [
        'biz_id' => $bid,
        'biz_id_p' => $bid
    ];

    if ($productId !== null && $productId > 0) {
        $sql .= ' AND m.product_id = :product_id';
        $params['product_id'] = $productId;
    }

    $sql .= ' ORDER BY m.id DESC LIMIT ' . max(1, (int) $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_inventory_stats(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    // Total products & stock units scoped to current business
    $stmt = $db->prepare('
        SELECT 
            COUNT(*) AS total_products,
            COALESCE(SUM(stock_quantity), 0) AS total_stock_units,
            COALESCE(SUM(stock_quantity * cost_price), 0) AS total_cost_value,
            COALESCE(SUM(stock_quantity * selling_price), 0) AS total_retail_value,
            SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= low_stock_threshold THEN 1 ELSE 0 END) AS low_stock_count,
            SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock_count
        FROM products
        WHERE business_id = :biz_id
    ');
    $stmt->execute(['biz_id' => $bid]);
    $stats = $stmt->fetch();

    return [
        'total_products' => (int) ($stats['total_products'] ?? 0),
        'total_stock_units' => (int) ($stats['total_stock_units'] ?? 0),
        'total_cost_value' => (float) ($stats['total_cost_value'] ?? 0.00),
        'total_retail_value' => (float) ($stats['total_retail_value'] ?? 0.00),
        'low_stock_count' => (int) ($stats['low_stock_count'] ?? 0),
        'out_of_stock_count' => (int) ($stats['out_of_stock_count'] ?? 0),
    ];
}

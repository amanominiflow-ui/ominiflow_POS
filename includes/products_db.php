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

function ensure_category_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = get_db();
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'image_path'
        ");
        $stmt->execute(['db' => DB_NAME]);
        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE `categories` ADD `image_path` VARCHAR(255) NULL AFTER `description`");
        }
    } catch (PDOException $e) {
        // Table may not exist yet or column exists
    }
}

function handle_category_image_upload(?array $file, ?string $oldPath = null): ?string {
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

    $uploadDir = __DIR__ . '/../assets/uploads/categories/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newFileName = 'cat_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $targetPath = $uploadDir . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Delete old category image if it exists
        if ($oldPath && file_exists(__DIR__ . '/../' . ltrim($oldPath, '/'))) {
            @unlink(__DIR__ . '/../' . ltrim($oldPath, '/'));
        }
        return 'assets/uploads/categories/' . $newFileName;
    }

    return $oldPath;
}

function get_categories(string $search = '', string $status = '', ?int $businessId = null): array {
    ensure_category_schema();
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
    ensure_category_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = :id AND business_id = :biz_id LIMIT 1');
    $stmt->execute(['id' => $id, 'biz_id' => $bid]);
    $cat = $stmt->fetch();
    return $cat ?: null;
}

function get_category_by_code(string $code, ?int $businessId = null): ?array {
    ensure_category_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM categories WHERE code = :code AND business_id = :biz_id LIMIT 1');
    $stmt->execute(['code' => strtoupper(trim($code)), 'biz_id' => $bid]);
    $cat = $stmt->fetch();
    return $cat ?: null;
}

function save_category(array $data, ?int $id = null, ?int $businessId = null, ?array $file = null): array {
    ensure_category_schema();
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

    $oldCategory = $id !== null ? get_category_by_id($id, $bid) : null;
    $imagePath = $oldCategory['image_path'] ?? null;

    if (!empty($data['remove_image'])) {
        if ($imagePath && file_exists(__DIR__ . '/../' . ltrim($imagePath, '/'))) {
            @unlink(__DIR__ . '/../' . ltrim($imagePath, '/'));
        }
        $imagePath = null;
    }

    if ($file && isset($file['error']) && $file['error'] === UPLOAD_ERR_OK) {
        $imagePath = handle_category_image_upload($file, $imagePath);
    }

    try {
        $db = get_db();
        if ($id !== null) {
            $stmt = $db->prepare('
                UPDATE categories
                SET name = :name, code = :code, description = :description, image_path = :image_path, status = :status, updated_at = NOW()
                WHERE id = :id AND business_id = :biz_id
            ');
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'image_path' => $imagePath,
                'status' => $status,
                'id' => $id,
                'biz_id' => $bid,
            ]);
            $categoryId = $id;
        } else {
            $stmt = $db->prepare('
                INSERT INTO categories (business_id, name, code, description, image_path, status, created_at, updated_at)
                VALUES (:biz_id, :name, :code, :description, :image_path, :status, NOW(), NOW())
            ');
            $stmt->execute([
                'biz_id' => $bid,
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'image_path' => $imagePath,
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
    ensure_category_schema();
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

    if (!empty($cat['image_path']) && file_exists(__DIR__ . '/../' . ltrim($cat['image_path'], '/'))) {
        @unlink(__DIR__ . '/../' . ltrim($cat['image_path'], '/'));
    }

    $stmt = $db->prepare('DELETE FROM categories WHERE id = :id AND business_id = :biz_id');
    $stmt->execute(['id' => $id, 'biz_id' => $bid]);

    return ['success' => true];
}

/* =========================================================================
   2. PRODUCT OPERATIONS
   ========================================================================= */

function get_products(string $search = '', ?int $categoryId = null, string $status = '', string $stockFilter = '', ?int $businessId = null, string $sort = ''): array {
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

    if ($sort === 'name_asc') {
        $sql .= ' ORDER BY p.name ASC';
    } elseif ($sort === 'name_desc') {
        $sql .= ' ORDER BY p.name DESC';
    } elseif ($sort === 'price_asc') {
        $sql .= ' ORDER BY p.selling_price ASC';
    } elseif ($sort === 'price_desc') {
        $sql .= ' ORDER BY p.selling_price DESC';
    } elseif ($sort === 'stock_desc') {
        $sql .= ' ORDER BY p.stock_quantity DESC';
    } elseif ($sort === 'stock_asc') {
        $sql .= ' ORDER BY p.stock_quantity ASC';
    } elseif ($sort === 'created_asc') {
        $sql .= ' ORDER BY p.id ASC';
    } else {
        $sql .= ' ORDER BY p.id DESC';
    }

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

function handle_product_video_upload(?array $file, ?string $oldPath = null): ?string {
    if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return $oldPath;
    }

    $allowedExts = ['mp4', 'webm', 'mov', 'ogg', 'ogv', 'm4v', 'mkv'];
    $fileInfo = pathinfo($file['name']);
    $ext = strtolower($fileInfo['extension'] ?? '');

    if (!in_array($ext, $allowedExts, true)) {
        return $oldPath;
    }

    // Max 100MB
    if ($file['size'] > 100 * 1024 * 1024) {
        return $oldPath;
    }

    $uploadDir = __DIR__ . '/../assets/uploads/products/videos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newFileName = 'vid_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $targetPath = $uploadDir . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        if ($oldPath && file_exists(__DIR__ . '/../' . ltrim($oldPath, '/'))) {
            @unlink(__DIR__ . '/../' . ltrim($oldPath, '/'));
        }
        return 'assets/uploads/products/videos/' . $newFileName;
    }

    return $oldPath;
}

function ensure_product_item_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = get_db();

    $columns = [
        'item_kind' => "ENUM('goods','service') NOT NULL DEFAULT 'goods'",
        'brand' => "VARCHAR(120) NULL",
        'manufacturer' => "VARCHAR(120) NULL",
        'tax_preference' => "ENUM('taxable','non_taxable') NOT NULL DEFAULT 'taxable'",
        'unit' => "VARCHAR(30) NOT NULL DEFAULT 'pcs'",
        'description' => "TEXT NULL",
        'mrp' => "DECIMAL(10,2) NULL",
        'sales_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
        'purchase_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
        'sales_account' => "VARCHAR(100) NULL DEFAULT 'Sales'",
        'purchase_account' => "VARCHAR(100) NULL DEFAULT 'Cost of Goods Sold'",
        'sales_description' => "TEXT NULL",
        'purchase_description' => "TEXT NULL",
        'preferred_vendor_id' => "INT UNSIGNED NULL",
        'intra_tax_rate_id' => "INT UNSIGNED NULL",
        'inter_tax_rate_id' => "INT UNSIGNED NULL",
        'track_inventory' => "TINYINT(1) NOT NULL DEFAULT 1",
        'inventory_account' => "VARCHAR(100) NULL",
        'inventory_valuation' => "VARCHAR(20) NOT NULL DEFAULT 'fifo'",
        'returnable' => "TINYINT(1) NOT NULL DEFAULT 1",
        'dim_length' => "DECIMAL(10,2) NULL",
        'dim_width' => "DECIMAL(10,2) NULL",
        'dim_height' => "DECIMAL(10,2) NULL",
        'dim_unit' => "VARCHAR(10) NOT NULL DEFAULT 'cm'",
        'weight' => "DECIMAL(10,3) NULL",
        'weight_unit' => "VARCHAR(10) NOT NULL DEFAULT 'kg'",
        'extra_identifiers' => "TEXT NULL",
        'rear_image_path' => "VARCHAR(255) NULL",
        'video_path' => "VARCHAR(255) NULL",
        'video_url' => "VARCHAR(500) NULL",
    ];
    foreach ($columns as $col => $def) {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'products' AND COLUMN_NAME = :col
            ");
            $stmt->execute(['db' => DB_NAME, 'col' => $col]);
            if ((int) $stmt->fetchColumn() === 0) {
                $db->exec("ALTER TABLE `products` ADD `{$col}` {$def}");
            }
        } catch (PDOException $e) {
            // ignore
        }
    }

    try {
        $indexes = $db->query("SHOW INDEX FROM products WHERE Key_name = 'sku' AND Non_unique = 0")->fetchAll();
        if (!empty($indexes)) {
            $db->exec("ALTER TABLE products DROP INDEX `sku`");
        }
        $indexesBc = $db->query("SHOW INDEX FROM products WHERE Key_name = 'barcode' AND Non_unique = 0")->fetchAll();
        if (!empty($indexesBc)) {
            $db->exec("ALTER TABLE products DROP INDEX `barcode`");
        }
        $chkSku = $db->query("SHOW INDEX FROM products WHERE Key_name = 'uk_business_sku'")->fetchAll();
        if (empty($chkSku)) {
            $db->exec("ALTER TABLE products ADD UNIQUE KEY `uk_business_sku` (`business_id`, `sku`)");
        }
        $chkBc = $db->query("SHOW INDEX FROM products WHERE Key_name = 'uk_business_barcode'")->fetchAll();
        if (empty($chkBc)) {
            $db->exec("ALTER TABLE products ADD UNIQUE KEY `uk_business_barcode` (`business_id`, `barcode`)");
        }
    } catch (PDOException $e) {
        // ignore
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS `product_images` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `product_id` INT UNSIGNED NOT NULL,
            `kind` ENUM('front','rear','other') NOT NULL DEFAULT 'other',
            `path` VARCHAR(255) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_product_images_product` (`product_id`),
            INDEX `idx_product_images_biz` (`business_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `product_brands` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `kind` ENUM('brand','manufacturer') NOT NULL DEFAULT 'brand',
            `name` VARCHAR(120) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_product_brands` (`business_id`, `kind`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Product Attributes table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `product_attributes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `product_id` INT UNSIGNED NOT NULL,
            `attribute_name` VARCHAR(100) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_pa_product` (`product_id`),
            INDEX `idx_pa_biz` (`business_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Product Attribute Options table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `product_attribute_options` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `attribute_id` INT UNSIGNED NOT NULL,
            `option_value` VARCHAR(100) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_pao_attr` (`attribute_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Ensure product_variants has needed columns
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `product_variants` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `product_id` INT UNSIGNED NOT NULL,
            `variant_name` VARCHAR(191) NOT NULL,
            `attribute_values` JSON NULL,
            `sku` VARCHAR(100) NOT NULL,
            `barcode` VARCHAR(100) NULL,
            `cost_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `selling_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `stock_quantity` INT NOT NULL DEFAULT 0,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_pv_product` (`product_id`),
            INDEX `idx_pv_sku` (`sku`),
            INDEX `idx_pv_biz` (`business_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) { /* table may exist */ }
    // Add columns to product_variants if missing
    foreach (['business_id' => "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`", 'attribute_values' => "JSON NULL AFTER `variant_name`"] as $vc => $vd) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'product_variants' AND COLUMN_NAME = :col");
            $stmt->execute(['db' => DB_NAME, 'col' => $vc]);
            if ((int) $stmt->fetchColumn() === 0) {
                $db->exec("ALTER TABLE `product_variants` ADD `{$vc}` {$vd}");
            }
        } catch (PDOException $e) { /* ignore */ }
    }
}

function get_tax_rates_list(?int $businessId = null): array {
    $db = get_db();
    try {
        $stmt = $db->query("SELECT id, name, rate, type, is_default, status FROM tax_rates WHERE status = 'active' ORDER BY rate ASC, name ASC");
        return $stmt ? $stmt->fetchAll() : [];
    } catch (PDOException $e) {
        return [];
    }
}

function get_product_brand_names(string $kind = 'brand', ?int $businessId = null): array {
    ensure_product_item_schema();
    $bid = $businessId ?: current_business_id();
    $stmt = get_db()->prepare('SELECT name FROM product_brands WHERE business_id = :bid AND kind = :k ORDER BY name ASC');
    $stmt->execute(['bid' => $bid, 'k' => $kind]);
    return array_values(array_filter(array_map(static fn($r) => (string) $r['name'], $stmt->fetchAll())));
}

function remember_product_brand(string $kind, string $name, ?int $businessId = null): void {
    $name = trim($name);
    if ($name === '') {
        return;
    }
    $bid = $businessId ?: current_business_id();
    $kind = $kind === 'manufacturer' ? 'manufacturer' : 'brand';
    try {
        get_db()->prepare('INSERT IGNORE INTO product_brands (business_id, kind, name) VALUES (:bid, :k, :n)')
            ->execute(['bid' => $bid, 'k' => $kind, 'n' => $name]);
    } catch (PDOException $e) {
        // ignore
    }
}

function get_product_images(int $productId, ?int $businessId = null): array {
    ensure_product_item_schema();
    $bid = $businessId ?: current_business_id();
    $stmt = get_db()->prepare('SELECT * FROM product_images WHERE product_id = :pid AND business_id = :bid ORDER BY sort_order ASC, id ASC');
    $stmt->execute(['pid' => $productId, 'bid' => $bid]);
    return $stmt->fetchAll();
}

function collect_product_form_data(): array {
    $kind = (($_POST['item_kind'] ?? 'goods') === 'service') ? 'service' : 'goods';
    $taxPref = (($_POST['tax_preference'] ?? 'taxable') === 'non_taxable') ? 'non_taxable' : 'taxable';
    $hasVariants = (($_POST['item_type'] ?? 'single') === 'variants');
    $salesOn = !empty($_POST['sales_enabled']);
    $purchaseOn = !empty($_POST['purchase_enabled']);
    $track = $kind === 'service' ? false : !empty($_POST['track_inventory']);

    $identifiers = [];
    foreach ((array) ($_POST['identifiers'] ?? []) as $ident) {
        $ident = trim((string) $ident);
        if ($ident !== '') {
            $identifiers[] = $ident;
        }
    }
    $barcode = trim((string) ($_POST['barcode'] ?? ''));
    if ($barcode === '' && !empty($identifiers)) {
        $barcode = $identifiers[0];
    }

    $intraId = (int) ($_POST['intra_tax_rate_id'] ?? 0);
    $interId = (int) ($_POST['inter_tax_rate_id'] ?? 0);
    $taxPercent = 0.0;
    if ($taxPref === 'taxable' && $intraId > 0) {
        foreach (get_tax_rates_list() as $tr) {
            if ((int) $tr['id'] === $intraId) {
                $taxPercent = (float) $tr['rate'];
                break;
            }
        }
    }

    $reorder = max(0, (int) ($_POST['reorder_point'] ?? $_POST['low_stock_threshold'] ?? 5));

    // Collect variant attributes
    $variantAttributes = [];
    $attrNames = (array) ($_POST['attr_name'] ?? []);
    $attrOptions = (array) ($_POST['attr_options'] ?? []);
    foreach ($attrNames as $i => $aName) {
        $aName = trim((string) $aName);
        if ($aName === '') continue;
        // Options come as comma-separated or as array
        $opts = $attrOptions[$i] ?? '';
        if (is_string($opts)) {
            $opts = array_values(array_filter(array_map('trim', explode(',', $opts))));
        } else {
            $opts = array_values(array_filter(array_map('trim', (array) $opts)));
        }
        if (!empty($opts)) {
            $variantAttributes[] = ['name' => $aName, 'options' => $opts];
        }
    }

    return [
        'name' => $_POST['name'] ?? '',
        'sku' => $_POST['sku'] ?? '',
        'barcode' => $barcode,
        'category_id' => $_POST['category_id'] ?? null,
        'cost_price' => $_POST['cost_price'] ?? 0.00,
        'selling_price' => $_POST['selling_price'] ?? 0.00,
        'tax_percent' => $taxPercent,
        'initial_stock' => $_POST['initial_stock'] ?? 0,
        'low_stock_threshold' => $reorder,
        'status' => $_POST['status'] ?? 'active',
        'item_kind' => $kind,
        'brand' => trim((string) ($_POST['brand'] ?? '')),
        'manufacturer' => trim((string) ($_POST['manufacturer'] ?? '')),
        'hsn_code' => strtoupper(trim((string) ($_POST['hsn_code'] ?? ''))),
        'tax_preference' => $taxPref,
        'unit' => trim((string) ($_POST['unit'] ?? 'pcs')) ?: 'pcs',
        'description' => trim((string) ($_POST['description'] ?? '')),
        'mrp' => $_POST['mrp'] ?? null,
        'sales_enabled' => $salesOn ? 1 : 0,
        'purchase_enabled' => $purchaseOn ? 1 : 0,
        'sales_account' => trim((string) ($_POST['sales_account'] ?? 'Sales')) ?: 'Sales',
        'purchase_account' => trim((string) ($_POST['purchase_account'] ?? 'Cost of Goods Sold')) ?: 'Cost of Goods Sold',
        'sales_description' => trim((string) ($_POST['sales_description'] ?? '')),
        'purchase_description' => trim((string) ($_POST['purchase_description'] ?? '')),
        'preferred_vendor_id' => !empty($_POST['preferred_vendor_id']) ? (int) $_POST['preferred_vendor_id'] : null,
        'intra_tax_rate_id' => $intraId ?: null,
        'inter_tax_rate_id' => $interId ?: null,
        'track_inventory' => $track ? 1 : 0,
        'inventory_account' => trim((string) ($_POST['inventory_account'] ?? '')),
        'inventory_valuation' => in_array(($_POST['inventory_valuation'] ?? 'fifo'), ['fifo', 'wac', 'lifo'], true) ? ($_POST['inventory_valuation'] ?? 'fifo') : 'fifo',
        'returnable' => !empty($_POST['returnable']) ? 1 : 0,
        'dim_length' => $_POST['dim_length'] ?? null,
        'dim_width' => $_POST['dim_width'] ?? null,
        'dim_height' => $_POST['dim_height'] ?? null,
        'dim_unit' => trim((string) ($_POST['dim_unit'] ?? 'cm')) ?: 'cm',
        'weight' => $_POST['weight'] ?? null,
        'weight_unit' => trim((string) ($_POST['weight_unit'] ?? 'kg')) ?: 'kg',
        'extra_identifiers' => json_encode($identifiers, JSON_UNESCAPED_UNICODE),
        'product_type' => $hasVariants ? 'variable' : 'simple',
        'is_trending' => !empty($_POST['is_trending']) ? 1 : 0,
        'remove_image_ids' => array_map('intval', (array) ($_POST['remove_image_ids'] ?? [])),
        'remove_video' => !empty($_POST['remove_video']),
        'video_url' => trim((string) ($_POST['video_url'] ?? '')),
        'variant_attributes' => $variantAttributes,
    ];
}

function save_product_gallery(int $productId, int $businessId, array $files, array $removeIds = []): ?string {
    ensure_product_item_schema();
    $db = get_db();
    $primary = null;

    if ($removeIds) {
        $placeholders = implode(',', array_fill(0, count($removeIds), '?'));
        $params = $removeIds;
        $params[] = $productId;
        $params[] = $businessId;
        $sel = $db->prepare("SELECT id, path FROM product_images WHERE id IN ({$placeholders}) AND product_id = ? AND business_id = ?");
        $sel->execute($params);
        foreach ($sel->fetchAll() as $row) {
            $full = __DIR__ . '/../' . ltrim((string) $row['path'], '/');
            if (is_file($full)) {
                @unlink($full);
            }
        }
        $del = $db->prepare("DELETE FROM product_images WHERE id IN ({$placeholders}) AND product_id = ? AND business_id = ?");
        $del->execute($params);
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = :pid AND business_id = :bid');
    $countStmt->execute(['pid' => $productId, 'bid' => $businessId]);
    $existing = (int) $countStmt->fetchColumn();

    $uploads = [];
    foreach (['front' => 'image_front', 'rear' => 'image_rear'] as $kind => $field) {
        if (!empty($files[$field]) && ($files[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploads[] = ['kind' => $kind, 'file' => $files[$field]];
        }
    }
    if (!empty($files['images']['name']) && is_array($files['images']['name'])) {
        $n = count($files['images']['name']);
        for ($i = 0; $i < $n; $i++) {
            $one = [
                'name' => $files['images']['name'][$i],
                'type' => $files['images']['type'][$i] ?? '',
                'tmp_name' => $files['images']['tmp_name'][$i] ?? '',
                'error' => $files['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['images']['size'][$i] ?? 0,
            ];
            if ($one['error'] === UPLOAD_ERR_OK) {
                $uploads[] = ['kind' => 'other', 'file' => $one];
            }
        }
    }

    $sort = $existing;
    foreach ($uploads as $up) {
        if ($existing >= 15) {
            break;
        }
        $path = handle_product_image_upload($up['file'], null);
        if (!$path) {
            continue;
        }
        $kind = $up['kind'];
        if ($kind === 'front' || $kind === 'rear') {
            $old = $db->prepare('SELECT id, path FROM product_images WHERE product_id = :pid AND business_id = :bid AND kind = :k LIMIT 1');
            $old->execute(['pid' => $productId, 'bid' => $businessId, 'k' => $kind]);
            $prev = $old->fetch();
            if ($prev) {
                $full = __DIR__ . '/../' . ltrim((string) $prev['path'], '/');
                if (is_file($full)) {
                    @unlink($full);
                }
                $db->prepare('UPDATE product_images SET path = :p WHERE id = :id')->execute(['p' => $path, 'id' => (int) $prev['id']]);
                $existing++;
                if ($kind === 'front') {
                    $primary = $path;
                }
                continue;
            }
        }
        $db->prepare('INSERT INTO product_images (business_id, product_id, kind, path, sort_order) VALUES (:bid, :pid, :k, :p, :s)')
            ->execute(['bid' => $businessId, 'pid' => $productId, 'k' => $kind, 'p' => $path, 's' => $sort]);
        $sort++;
        $existing++;
        if ($kind === 'front' && $primary === null) {
            $primary = $path;
        }
    }

    if ($primary === null) {
        $front = $db->prepare("SELECT path FROM product_images WHERE product_id = :pid AND business_id = :bid AND kind = 'front' LIMIT 1");
        $front->execute(['pid' => $productId, 'bid' => $businessId]);
        $primary = $front->fetchColumn() ?: null;
        if (!$primary) {
            $any = $db->prepare('SELECT path FROM product_images WHERE product_id = :pid AND business_id = :bid ORDER BY sort_order ASC, id ASC LIMIT 1');
            $any->execute(['pid' => $productId, 'bid' => $businessId]);
            $primary = $any->fetchColumn() ?: null;
        }
    }

    return $primary ? (string) $primary : null;
}

function save_product(array $data, ?array $file = null, ?int $id = null, ?int $userId = null, ?int $businessId = null): array {
    ensure_product_item_schema();
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
    $itemKind = (($data['item_kind'] ?? 'goods') === 'service') ? 'service' : 'goods';
    $productType = in_array(($data['product_type'] ?? 'simple'), ['simple', 'variable', 'composite'], true) ? $data['product_type'] : 'simple';
    $mrp = isset($data['mrp']) && $data['mrp'] !== '' && $data['mrp'] !== null ? (float) $data['mrp'] : null;
    $nullableDec = static function ($v): ?float {
        if ($v === '' || $v === null) {
            return null;
        }
        return (float) $v;
    };
    $hsn = strtoupper(trim((string) ($data['hsn_code'] ?? ''))) ?: null;
    $track = $itemKind === 'service' ? 0 : ((int) ($data['track_inventory'] ?? 1) === 1 ? 1 : 0);

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
    $legacyFile = $file;
    if ($legacyFile && isset($legacyFile['name']) && is_array($legacyFile['name'])) {
        $legacyFile = null;
    }
    $imagePath = handle_product_image_upload($legacyFile, $oldProduct['image_path'] ?? null);

    $videoPath = $oldProduct['video_path'] ?? null;
    if (!empty($data['remove_video'])) {
        if ($videoPath && file_exists(__DIR__ . '/../' . ltrim($videoPath, '/'))) {
            @unlink(__DIR__ . '/../' . ltrim($videoPath, '/'));
        }
        $videoPath = null;
    }
    if (!empty($_FILES['product_video']) && ($_FILES['product_video']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $videoPath = handle_product_video_upload($_FILES['product_video'], $videoPath);
    }

    $fields = [
        'category_id' => $categoryId,
        'product_type' => $productType,
        'item_kind' => $itemKind,
        'name' => $name,
        'sku' => $sku,
        'barcode' => $barcode,
        'hsn_code' => $hsn,
        'brand' => trim((string) ($data['brand'] ?? '')) ?: null,
        'manufacturer' => trim((string) ($data['manufacturer'] ?? '')) ?: null,
        'tax_preference' => (($data['tax_preference'] ?? 'taxable') === 'non_taxable') ? 'non_taxable' : 'taxable',
        'unit' => trim((string) ($data['unit'] ?? 'pcs')) ?: 'pcs',
        'description' => trim((string) ($data['description'] ?? '')) ?: null,
        'cost_price' => $costPrice,
        'selling_price' => $sellingPrice,
        'mrp' => $mrp,
        'tax_percent' => $taxPercent,
        'low_stock_threshold' => $lowStockThreshold,
        'sales_enabled' => array_key_exists('sales_enabled', $data) ? (!empty($data['sales_enabled']) ? 1 : 0) : 1,
        'purchase_enabled' => array_key_exists('purchase_enabled', $data) ? (!empty($data['purchase_enabled']) ? 1 : 0) : 1,
        'sales_account' => trim((string) ($data['sales_account'] ?? 'Sales')) ?: 'Sales',
        'purchase_account' => trim((string) ($data['purchase_account'] ?? 'Cost of Goods Sold')) ?: 'Cost of Goods Sold',
        'sales_description' => trim((string) ($data['sales_description'] ?? '')) ?: null,
        'purchase_description' => trim((string) ($data['purchase_description'] ?? '')) ?: null,
        'preferred_vendor_id' => !empty($data['preferred_vendor_id']) ? (int) $data['preferred_vendor_id'] : null,
        'intra_tax_rate_id' => !empty($data['intra_tax_rate_id']) ? (int) $data['intra_tax_rate_id'] : null,
        'inter_tax_rate_id' => !empty($data['inter_tax_rate_id']) ? (int) $data['inter_tax_rate_id'] : null,
        'track_inventory' => $track,
        'inventory_account' => trim((string) ($data['inventory_account'] ?? '')) ?: null,
        'inventory_valuation' => in_array(($data['inventory_valuation'] ?? 'fifo'), ['fifo', 'wac', 'lifo'], true) ? ($data['inventory_valuation'] ?? 'fifo') : 'fifo',
        'returnable' => array_key_exists('returnable', $data) ? (!empty($data['returnable']) ? 1 : 0) : 1,
        'dim_length' => $nullableDec($data['dim_length'] ?? null),
        'dim_width' => $nullableDec($data['dim_width'] ?? null),
        'dim_height' => $nullableDec($data['dim_height'] ?? null),
        'dim_unit' => trim((string) ($data['dim_unit'] ?? 'cm')) ?: 'cm',
        'weight' => $nullableDec($data['weight'] ?? null),
        'weight_unit' => trim((string) ($data['weight_unit'] ?? 'kg')) ?: 'kg',
        'extra_identifiers' => (string) ($data['extra_identifiers'] ?? '[]'),
        'image_path' => $imagePath,
        'video_path' => $videoPath,
        'video_url' => trim((string) ($data['video_url'] ?? '')) ?: null,
        'status' => $status,
        'is_trending' => array_key_exists('is_trending', $data) ? (!empty($data['is_trending']) ? 1 : 0) : (!empty($oldProduct['is_trending']) ? 1 : 0),
    ];

    try {
        if ($id !== null) {
            $sets = [];
            $params = ['id' => $id, 'biz_id' => $bid];
            foreach ($fields as $col => $val) {
                $sets[] = "`{$col}` = :{$col}";
                $params[$col] = $val;
            }
            $db->prepare('UPDATE products SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id AND business_id = :biz_id')
                ->execute($params);
            $productId = $id;
        } else {
            $cols = array_merge(['business_id', 'stock_quantity'], array_keys($fields));
            $place = array_map(static fn($c) => ':' . $c, $cols);
            $params = $fields;
            $params['business_id'] = $bid;
            $params['stock_quantity'] = $track ? $initialStock : 0;
            $db->prepare('INSERT INTO products (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES (' . implode(', ', $place) . ', NOW(), NOW())')
                ->execute($params);
            $productId = (int) $db->lastInsertId();

            if ($track && $initialStock > 0) {
                $validUserId = null;
                if ($userId !== null && (int)$userId > 0) {
                    try {
                        $stmtChkUser = $db->prepare('SELECT id FROM users WHERE id = :uid LIMIT 1');
                        $stmtChkUser->execute(['uid' => (int)$userId]);
                        if ($stmtChkUser->fetchColumn()) {
                            $validUserId = (int)$userId;
                        }
                    } catch (Exception $e) {}
                }

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
                    'user_id' => $validUserId,
                    'movement_type' => 'in',
                    'quantity_change' => $initialStock,
                    'quantity_before' => 0,
                    'quantity_after' => $initialStock,
                    'reason' => 'Initial opening stock upon product creation',
                ]);
            }
        }

        $galleryPrimary = save_product_gallery($productId, $bid, $_FILES, (array) ($data['remove_image_ids'] ?? []));
        if ($galleryPrimary) {
            $db->prepare('UPDATE products SET image_path = :p WHERE id = :id AND business_id = :bid')
                ->execute(['p' => $galleryPrimary, 'id' => $productId, 'bid' => $bid]);
            $rear = $db->prepare("SELECT path FROM product_images WHERE product_id = :pid AND business_id = :bid AND kind = 'rear' LIMIT 1");
            $rear->execute(['pid' => $productId, 'bid' => $bid]);
            $rearPath = $rear->fetchColumn();
            if ($rearPath) {
                $db->prepare('UPDATE products SET rear_image_path = :p WHERE id = :id AND business_id = :bid')
                    ->execute(['p' => $rearPath, 'id' => $productId, 'bid' => $bid]);
            }
        }

        remember_product_brand('brand', (string) ($fields['brand'] ?? ''));
        remember_product_brand('manufacturer', (string) ($fields['manufacturer'] ?? ''));

        // Save variant attributes and generate variant combos
        if ($productType === 'variable' && !empty($data['variant_attributes'])) {
            save_product_attributes($productId, $data['variant_attributes'], $bid);
            save_product_variants_from_attributes($productId, $data['variant_attributes'], $bid);
        } elseif ($productType === 'simple') {
            // Clean up any leftover variant data
            try {
                $db->prepare('DELETE FROM product_attributes WHERE product_id = :pid AND business_id = :bid')
                    ->execute(['pid' => $productId, 'bid' => $bid]);
                $db->prepare('DELETE FROM product_variants WHERE product_id = :pid AND business_id = :bid')
                    ->execute(['pid' => $productId, 'bid' => $bid]);
            } catch (PDOException $e) { /* ignore */ }
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
   2b. PRODUCT VARIANT & ATTRIBUTE OPERATIONS
   ========================================================================= */

function get_product_attributes(int $productId, ?int $businessId = null): array {
    ensure_product_item_schema();
    $bid = $businessId ?: current_business_id();
    $db = get_db();
    $stmt = $db->prepare('
        SELECT pa.*, GROUP_CONCAT(pao.id, ":::", pao.option_value ORDER BY pao.sort_order ASC, pao.id ASC SEPARATOR "|||")
            AS options_raw
        FROM product_attributes pa
        LEFT JOIN product_attribute_options pao ON pao.attribute_id = pa.id
        WHERE pa.product_id = :pid AND pa.business_id = :bid
        GROUP BY pa.id
        ORDER BY pa.sort_order ASC, pa.id ASC
    ');
    $stmt->execute(['pid' => $productId, 'bid' => $bid]);
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $options = [];
        if (!empty($row['options_raw'])) {
            foreach (explode('|||', $row['options_raw']) as $part) {
                $pieces = explode(':::', $part, 2);
                if (count($pieces) === 2) {
                    $options[] = ['id' => (int) $pieces[0], 'value' => $pieces[1]];
                }
            }
        }
        $result[] = [
            'id' => (int) $row['id'],
            'attribute_name' => $row['attribute_name'],
            'sort_order' => (int) $row['sort_order'],
            'options' => $options,
        ];
    }
    return $result;
}

function get_product_variants(int $productId, ?int $businessId = null): array {
    ensure_product_item_schema();
    $bid = $businessId ?: current_business_id();
    $db = get_db();
    $stmt = $db->prepare('
        SELECT * FROM product_variants
        WHERE product_id = :pid AND business_id = :bid
        ORDER BY id ASC
    ');
    $stmt->execute(['pid' => $productId, 'bid' => $bid]);
    return $stmt->fetchAll();
}

function save_product_attributes(int $productId, array $attributes, ?int $businessId = null): void {
    ensure_product_item_schema();
    $bid = $businessId ?: current_business_id();
    $db = get_db();

    // Delete old attributes (cascade deletes options)
    $db->prepare('DELETE FROM product_attributes WHERE product_id = :pid AND business_id = :bid')
        ->execute(['pid' => $productId, 'bid' => $bid]);

    foreach ($attributes as $idx => $attr) {
        $attrName = trim((string) ($attr['name'] ?? ''));
        if ($attrName === '') continue;
        $options = array_values(array_filter(array_map('trim', (array) ($attr['options'] ?? []))));
        if (empty($options)) continue;

        $stmtAttr = $db->prepare('INSERT INTO product_attributes (business_id, product_id, attribute_name, sort_order) VALUES (:bid, :pid, :name, :sort)');
        $stmtAttr->execute(['bid' => $bid, 'pid' => $productId, 'name' => $attrName, 'sort' => $idx]);
        $attrId = (int) $db->lastInsertId();

        foreach ($options as $oi => $optVal) {
            $db->prepare('INSERT INTO product_attribute_options (attribute_id, option_value, sort_order) VALUES (:aid, :val, :sort)')
                ->execute(['aid' => $attrId, 'val' => $optVal, 'sort' => $oi]);
        }
    }
}

function generate_variant_combinations(array $attributes): array {
    $attrOptions = [];
    foreach ($attributes as $attr) {
        $name = trim((string) ($attr['name'] ?? ''));
        $opts = array_values(array_filter(array_map('trim', (array) ($attr['options'] ?? []))));
        if ($name !== '' && !empty($opts)) {
            $attrOptions[] = ['name' => $name, 'options' => $opts];
        }
    }
    if (empty($attrOptions)) return [];

    $combos = [[]];
    foreach ($attrOptions as $ao) {
        $newCombos = [];
        foreach ($combos as $combo) {
            foreach ($ao['options'] as $opt) {
                $newCombos[] = array_merge($combo, [$ao['name'] => $opt]);
            }
        }
        $combos = $newCombos;
    }
    return $combos;
}

function save_product_variants_from_attributes(int $productId, array $attributes, ?int $businessId = null): void {
    ensure_product_item_schema();
    $bid = $businessId ?: current_business_id();
    $db = get_db();

    // Get existing variants keyed by attribute_values JSON
    $existingStmt = $db->prepare('SELECT * FROM product_variants WHERE product_id = :pid AND business_id = :bid');
    $existingStmt->execute(['pid' => $productId, 'bid' => $bid]);
    $existingVariants = [];
    foreach ($existingStmt->fetchAll() as $ev) {
        $key = $ev['attribute_values'] ?? '';
        $existingVariants[$key] = $ev;
    }

    $combos = generate_variant_combinations($attributes);
    $usedKeys = [];

    // Check if POST has variant-specific data
    $postedSkus = $_POST['variant_sku'] ?? [];
    $postedSelling = $_POST['variant_selling_price'] ?? [];
    $postedCost = $_POST['variant_cost_price'] ?? [];
    $postedStock = $_POST['variant_stock'] ?? [];

    $totalVariantStock = 0;
    $firstVariantPrice = null;
    $firstVariantCost = null;

    foreach ($combos as $ci => $combo) {
        $variantName = implode(' / ', array_values($combo));
        $attrJson = json_encode($combo, JSON_UNESCAPED_UNICODE);
        $usedKeys[] = $attrJson;

        // Check if variant already exists
        if (isset($existingVariants[$attrJson])) {
            // Update existing
            $ev = $existingVariants[$attrJson];
            $sku = !empty($postedSkus[$ci]) ? strtoupper(trim($postedSkus[$ci])) : $ev['sku'];
            $sp = isset($postedSelling[$ci]) && $postedSelling[$ci] !== '' ? (float) $postedSelling[$ci] : (float) $ev['selling_price'];
            $cp = isset($postedCost[$ci]) && $postedCost[$ci] !== '' ? (float) $postedCost[$ci] : (float) $ev['cost_price'];
            $stk = isset($postedStock[$ci]) && $postedStock[$ci] !== '' ? max(0, (int) $postedStock[$ci]) : (int) $ev['stock_quantity'];
            $db->prepare('UPDATE product_variants SET variant_name = :vn, sku = :sku, selling_price = :sp, cost_price = :cp, stock_quantity = :stk, updated_at = NOW() WHERE id = :id')
                ->execute(['vn' => $variantName, 'sku' => $sku, 'sp' => $sp, 'cp' => $cp, 'stk' => $stk, 'id' => (int) $ev['id']]);
            $totalVariantStock += $stk;
            if ($firstVariantPrice === null && $sp > 0) $firstVariantPrice = $sp;
            if ($firstVariantCost === null && $cp > 0) $firstVariantCost = $cp;
        } else {
            // Generate SKU for new variant
            $baseSku = strtoupper(trim($postedSkus[$ci] ?? ''));
            if ($baseSku === '') {
                $baseSku = 'SKU-' . strtoupper(substr(uniqid(), -6)) . '-V' . ($ci + 1);
            }
            $sp = isset($postedSelling[$ci]) && $postedSelling[$ci] !== '' ? (float) $postedSelling[$ci] : 0.00;
            $cp = isset($postedCost[$ci]) && $postedCost[$ci] !== '' ? (float) $postedCost[$ci] : 0.00;
            $stk = isset($postedStock[$ci]) && $postedStock[$ci] !== '' ? max(0, (int) $postedStock[$ci]) : 0;
            $db->prepare('INSERT INTO product_variants (business_id, product_id, variant_name, attribute_values, sku, selling_price, cost_price, stock_quantity) VALUES (:bid, :pid, :vn, :av, :sku, :sp, :cp, :stk)')
                ->execute(['bid' => $bid, 'pid' => $productId, 'vn' => $variantName, 'av' => $attrJson, 'sku' => $baseSku, 'sp' => $sp, 'cp' => $cp, 'stk' => $stk]);
            $totalVariantStock += $stk;
            if ($firstVariantPrice === null && $sp > 0) $firstVariantPrice = $sp;
            if ($firstVariantCost === null && $cp > 0) $firstVariantCost = $cp;
        }
    }

    // Delete variants that no longer match any combo
    if (!empty($usedKeys)) {
        $allVariants = $db->prepare('SELECT id, attribute_values FROM product_variants WHERE product_id = :pid AND business_id = :bid');
        $allVariants->execute(['pid' => $productId, 'bid' => $bid]);
        foreach ($allVariants->fetchAll() as $v) {
            if (!in_array($v['attribute_values'], $usedKeys, true)) {
                $db->prepare('DELETE FROM product_variants WHERE id = :id')->execute(['id' => (int) $v['id']]);
            }
        }
    } else {
        // No combos, delete all variants
        $db->prepare('DELETE FROM product_variants WHERE product_id = :pid AND business_id = :bid')
            ->execute(['pid' => $productId, 'bid' => $bid]);
    }

    // Sync parent product price and stock from variants
    try {
        $pStmt = $db->prepare('SELECT selling_price, cost_price, stock_quantity FROM products WHERE id = :pid AND business_id = :bid LIMIT 1');
        $pStmt->execute(['pid' => $productId, 'bid' => $bid]);
        $parentProd = $pStmt->fetch();
        if ($parentProd) {
            $updates = [];
            $params = ['id' => $productId, 'bid' => $bid];
            if ($firstVariantPrice !== null && ((float)$parentProd['selling_price'] <= 0)) {
                $updates[] = 'selling_price = :sp';
                $params['sp'] = $firstVariantPrice;
            }
            if ($firstVariantCost !== null && ((float)$parentProd['cost_price'] <= 0)) {
                $updates[] = 'cost_price = :cp';
                $params['cp'] = $firstVariantCost;
            }
            if ($totalVariantStock > 0 || (int)$parentProd['stock_quantity'] === 0) {
                $updates[] = 'stock_quantity = :stk';
                $params['stk'] = $totalVariantStock;
            }
            if (!empty($updates)) {
                $db->prepare('UPDATE products SET ' . implode(', ', $updates) . ' WHERE id = :id AND business_id = :bid')->execute($params);
            }
        }
    } catch (PDOException $e) { /* ignore */ }
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

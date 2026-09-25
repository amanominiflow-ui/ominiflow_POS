<?php
/**
 * Purchase Entry (draft → owner cost → finalize → barcode).
 * Does not change purchase orders or inward counts.
 * Stock is posted once, only when an owner finalizes.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function ensure_purchase_entry_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = get_db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_entries` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL,
            `entry_number` VARCHAR(40) NOT NULL,
            `vendor_id` INT UNSIGNED NOT NULL,
            `supplier_invoice_no` VARCHAR(80) NULL,
            `purchase_date` DATE NOT NULL,
            `received_date` DATE NOT NULL,
            `warehouse_id` INT UNSIGNED NOT NULL,
            `notes` TEXT NULL,
            `status` ENUM('draft','pending_cost','finalized','barcode_generated','completed') NOT NULL DEFAULT 'pending_cost',
            `stock_posted` TINYINT(1) NOT NULL DEFAULT 0,
            `user_id` INT UNSIGNED NULL,
            `finalized_by` INT UNSIGNED NULL,
            `finalized_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_pe_number` (`business_id`, `entry_number`),
            INDEX `idx_pe_status` (`business_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_entry_lines` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `purchase_entry_id` INT UNSIGNED NOT NULL,
            `line_no` INT UNSIGNED NOT NULL,
            `category_id` INT UNSIGNED NOT NULL,
            `subcategory_id` INT UNSIGNED NULL,
            `sku` VARCHAR(100) NOT NULL,
            `colour` VARCHAR(80) NOT NULL,
            `size_label` VARCHAR(80) NOT NULL,
            `selling_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `cost_price` DECIMAL(12,2) NULL,
            `quantity` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NULL,
            `variant_id` INT UNSIGNED NULL,
            `barcode` VARCHAR(100) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_pel_entry` (`purchase_entry_id`),
            INDEX `idx_pel_sku` (`sku`),
            CONSTRAINT `fk_pel_entry` FOREIGN KEY (`purchase_entry_id`) REFERENCES `purchase_entries` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_subcategories` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL,
            `category_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_pe_sub` (`business_id`, `category_id`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_attr_options` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL,
            `attr_type` ENUM('colour','size') NOT NULL,
            `category_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `name` VARCHAR(80) NOT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_pe_attr` (`business_id`, `attr_type`, `category_id`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `purchase_sku_sequences` (
            `business_id` INT UNSIGNED NOT NULL,
            `prefix` VARCHAR(12) NOT NULL,
            `next_number` INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (`business_id`, `prefix`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    purchase_entry_seed_attrs($db);
}

function purchase_entry_can_view_cost(?array $user = null): bool {
    if ($user === null) {
        $user = current_user();
    }
    if (!$user) {
        return false;
    }
    require_once __DIR__ . '/roles_db.php';
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if (in_array($role, ['owner', 'admin', 'administrator'], true)) {
        return true;
    }
    return has_permission('purchases.purchase_entry_cost.view', $user)
        || has_permission('purchases.purchase_entry_cost.edit', $user);
}

function purchase_entry_permission_for_action(string $action, int $entryId = 0): string {
    return match ($action) {
        'save_draft' => $entryId > 0 ? 'purchases.purchase_entry.edit' : 'purchases.purchase_entry.create',
        'finalize' => 'purchases.purchase_entry_cost.edit',
        'add_option' => 'purchases.purchase_entry_cost.edit',
        'labels', 'mark_printed' => 'purchases.purchase_entry.view',
        default => 'purchases.purchase_entry.view',
    };
}

function purchase_entry_assert_action(string $action, int $entryId = 0, bool $ajax = false): void {
    require_once __DIR__ . '/roles_db.php';
    $perm = purchase_entry_permission_for_action($action, $entryId);
    if (has_permission($perm)) {
        return;
    }
    if ($ajax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have permission for this action.']);
        exit;
    }
    set_flash('error', 'Access denied for Purchase Entry.');
    redirect(APP_URL . '/purchase-entry.php');
}

function purchase_entry_seed_attrs(PDO $db): void {
    $bid = function_exists('current_business_id') ? current_business_id() : 1;
    $count = $db->prepare('SELECT COUNT(*) FROM purchase_attr_options WHERE business_id = :bid');
    $count->execute(['bid' => $bid]);
    if ((int) $count->fetchColumn() > 0) {
        return;
    }
    $colours = ['Black', 'White', 'Red', 'Green', 'Blue', 'Pink', 'Maroon', 'Beige', 'Multicolour'];
    $sizes = ['S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', 'Free Size', '28', '30', '32', '34', '36'];
    $stmt = $db->prepare('
        INSERT IGNORE INTO purchase_attr_options (business_id, attr_type, category_id, name, status, created_at)
        VALUES (:bid, :type, 0, :name, "active", NOW())
    ');
    foreach ($colours as $name) {
        $stmt->execute(['bid' => $bid, 'type' => 'colour', 'name' => $name]);
    }
    foreach ($sizes as $name) {
        $stmt->execute(['bid' => $bid, 'type' => 'size', 'name' => $name]);
    }
}

function purchase_entry_catalog(?int $businessId = null): array {
    ensure_purchase_entry_schema();
    require_once __DIR__ . '/products_db.php';
    require_once __DIR__ . '/purchases_db.php';
    require_once __DIR__ . '/outlets_db.php';
    $bid = $businessId ?: current_business_id();
    $db = get_db();

    $categories = get_categories('', 'active', $bid);
    $subs = $db->prepare('
        SELECT id, category_id, name FROM purchase_subcategories
        WHERE business_id = :bid AND status = "active" ORDER BY name ASC
    ');
    $subs->execute(['bid' => $bid]);
    $byCat = [];
    foreach ($subs->fetchAll() as $row) {
        $byCat[(int) $row['category_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }
    $catOut = [];
    foreach ($categories as $cat) {
        $id = (int) $cat['id'];
        $catOut[] = [
            'id' => $id,
            'name' => (string) $cat['name'],
            'code' => (string) ($cat['code'] ?? ''),
            'subcategories' => $byCat[$id] ?? [],
        ];
    }

    $colours = $db->prepare('
        SELECT name FROM purchase_attr_options
        WHERE business_id = :bid AND attr_type = "colour" AND status = "active" ORDER BY name ASC
    ');
    $colours->execute(['bid' => $bid]);
    $sizes = $db->prepare('
        SELECT name, category_id FROM purchase_attr_options
        WHERE business_id = :bid AND attr_type = "size" AND status = "active" ORDER BY name ASC
    ');
    $sizes->execute(['bid' => $bid]);

    $vendors = [];
    foreach (get_vendors('', $bid) as $v) {
        $vendors[] = [
            'id' => (int) $v['id'],
            'name' => (string) $v['name'],
            'phone' => (string) ($v['phone'] ?? ''),
        ];
    }
    $warehouses = [];
    foreach (get_warehouses(null, 'active', $bid) as $w) {
        $warehouses[] = [
            'id' => (int) $w['id'],
            'name' => (string) $w['name'],
            'code' => (string) ($w['code'] ?? ''),
        ];
    }

    return [
        'categories' => $catOut,
        'colours' => array_column($colours->fetchAll(), 'name'),
        'sizes' => $sizes->fetchAll(),
        'vendors' => $vendors,
        'warehouses' => $warehouses,
        'can_view_cost' => purchase_entry_can_view_cost(),
    ];
}

function purchase_entry_prefix(array $category): string {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($category['code'] ?? '')) ?? '');
    if ($code === '') {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($category['name'] ?? '')) ?? '');
    }
    $code = substr($code, 0, 6);
    return $code !== '' ? $code : 'SKU';
}

function purchase_entry_peek_sku(int $categoryId, ?int $businessId = null): array {
    ensure_purchase_entry_schema();
    require_once __DIR__ . '/products_db.php';
    $bid = $businessId ?: current_business_id();
    $cat = get_category_by_id($categoryId, $bid);
    if (!$cat) {
        return ['success' => false, 'error' => 'Please select a category.'];
    }
    $db = get_db();
    $prefix = purchase_entry_prefix($cat);
    $stmt = $db->prepare('SELECT next_number FROM purchase_sku_sequences WHERE business_id = :bid AND prefix = :prefix LIMIT 1');
    $stmt->execute(['bid' => $bid, 'prefix' => $prefix]);
    $next = $stmt->fetchColumn();
    $n = $next === false ? 1 : (int) $next;
    return ['success' => true, 'sku' => $prefix . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT)];
}

function purchase_entry_alloc_sku(PDO $db, int $businessId, int $categoryId): string {
    require_once __DIR__ . '/products_db.php';
    $cat = get_category_by_id($categoryId, $businessId);
    if (!$cat) {
        throw new RuntimeException('Please select a category.');
    }
    $prefix = purchase_entry_prefix($cat);
    $db->prepare('
        INSERT INTO purchase_sku_sequences (business_id, prefix, next_number)
        VALUES (:bid, :prefix, 1)
        ON DUPLICATE KEY UPDATE next_number = next_number
    ')->execute(['bid' => $businessId, 'prefix' => $prefix]);
    $stmt = $db->prepare('
        SELECT next_number FROM purchase_sku_sequences
        WHERE business_id = :bid AND prefix = :prefix LIMIT 1 FOR UPDATE
    ');
    $stmt->execute(['bid' => $businessId, 'prefix' => $prefix]);
    $n = (int) $stmt->fetchColumn();
    if ($n < 1) {
        $n = 1;
    }
    for ($try = 0; $try < 50; $try++) {
        $sku = $prefix . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $takenProd = $db->prepare('SELECT id FROM products WHERE business_id = :bid AND sku = :sku LIMIT 1');
        $takenProd->execute(['bid' => $businessId, 'sku' => $sku]);
        $takenLine = $db->prepare('
            SELECT pel.id FROM purchase_entry_lines pel
            INNER JOIN purchase_entries pe ON pe.id = pel.purchase_entry_id
            WHERE pe.business_id = :bid AND pel.sku = :sku AND pe.status IN ("draft","pending_cost","finalized","barcode_generated","completed")
            LIMIT 1
        ');
        $takenLine->execute(['bid' => $businessId, 'sku' => $sku]);
        if (!$takenProd->fetchColumn() && !$takenLine->fetchColumn()) {
            $db->prepare('UPDATE purchase_sku_sequences SET next_number = :n WHERE business_id = :bid AND prefix = :prefix')
                ->execute(['n' => $n + 1, 'bid' => $businessId, 'prefix' => $prefix]);
            return $sku;
        }
        $n++;
    }
    throw new RuntimeException('This SKU already exists.');
}

/**
 * @param array<int, array<string, mixed>> $lines
 * @return array{success:bool, error?:string, lines?:array<int, array<string, mixed>>}
 */
function purchase_entry_validate_lines(array $lines, int $businessId, bool $requireCost): array {
    require_once __DIR__ . '/products_db.php';
    $db = get_db();
    if ($lines === []) {
        return ['success' => false, 'error' => 'Add at least one product.'];
    }
    $clean = [];
    $seen = [];
    foreach ($lines as $i => $line) {
        $rowNo = $i + 1;
        $categoryId = (int) ($line['category_id'] ?? 0);
        if ($categoryId <= 0 || !get_category_by_id($categoryId, $businessId)) {
            return ['success' => false, 'error' => 'Please select a category on row ' . $rowNo . '.'];
        }
        $qty = $line['quantity'] ?? null;
        if (!is_numeric($qty) || (int) $qty != (float) $qty || (int) $qty <= 0) {
            return ['success' => false, 'error' => 'Quantity must be greater than 0.'];
        }
        $subId = (int) ($line['subcategory_id'] ?? 0);
        $subCount = $db->prepare('SELECT COUNT(*) FROM purchase_subcategories WHERE business_id = :bid AND category_id = :cid AND status = "active"');
        $subCount->execute(['bid' => $businessId, 'cid' => $categoryId]);
        $hasSubs = (int) $subCount->fetchColumn() > 0;
        if ($hasSubs && $subId <= 0) {
            return ['success' => false, 'error' => 'Please select a sub-category on row ' . $rowNo . '.'];
        }
        if ($subId > 0) {
            $sub = $db->prepare('SELECT id FROM purchase_subcategories WHERE id = :id AND business_id = :bid AND category_id = :cid AND status = "active" LIMIT 1');
            $sub->execute(['id' => $subId, 'bid' => $businessId, 'cid' => $categoryId]);
            if (!$sub->fetchColumn()) {
                return ['success' => false, 'error' => 'That sub-category does not belong to the selected category.'];
            }
        } else {
            $subId = 0;
        }
        $colour = trim((string) ($line['colour'] ?? ''));
        $size = trim((string) ($line['size'] ?? ''));
        if ($colour === '') {
            return ['success' => false, 'error' => 'Please select a colour on row ' . $rowNo . '.'];
        }
        if ($size === '') {
            return ['success' => false, 'error' => 'Please select a size on row ' . $rowNo . '.'];
        }
        if (!is_numeric($line['selling_price'] ?? null) || (float) $line['selling_price'] < 0) {
            return ['success' => false, 'error' => 'Selling price must be a number that is 0 or more.'];
        }
        $cost = null;
        if ($requireCost || (purchase_entry_can_view_cost() && array_key_exists('cost_price', $line) && $line['cost_price'] !== '' && $line['cost_price'] !== null)) {
            if (!is_numeric($line['cost_price'] ?? null) || (float) $line['cost_price'] < 0) {
                return ['success' => false, 'error' => 'Please enter cost price before finalizing.'];
            }
            $cost = number_format((float) $line['cost_price'], 2, '.', '');
        }
        $sku = strtoupper(trim((string) ($line['sku'] ?? '')));
        if ($sku === '') {
            return ['success' => false, 'error' => 'Please enter SKU on row ' . $rowNo . '.'];
        }
        if (isset($seen[$sku])) {
            return ['success' => false, 'error' => 'Duplicate SKU on row ' . $rowNo . ' — each line needs a unique SKU.'];
        }
        $seen[$sku] = true;
        $clean[] = [
            'category_id' => $categoryId,
            'subcategory_id' => $subId > 0 ? $subId : null,
            'sku' => $sku,
            'colour' => $colour,
            'size' => $size,
            'selling_price' => number_format((float) $line['selling_price'], 2, '.', ''),
            'cost_price' => $cost,
            'quantity' => (int) $qty,
        ];
    }
    return ['success' => true, 'lines' => $clean];
}

function purchase_entry_next_number(PDO $db, int $businessId): string {
    $year = date('Y');
    $prefix = 'PUR-' . $year . '-';
    $stmt = $db->prepare('
        SELECT entry_number FROM purchase_entries
        WHERE business_id = :bid AND entry_number LIKE :pfx
        ORDER BY id DESC LIMIT 1 FOR UPDATE
    ');
    $stmt->execute(['bid' => $businessId, 'pfx' => $prefix . '%']);
    $last = (string) $stmt->fetchColumn();
    $n = 1;
    if ($last !== '' && preg_match('/(\d+)$/', $last, $m)) {
        $n = (int) $m[1] + 1;
    }
    return $prefix . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

/**
 * Valid vendor id, or create/find vendor by typed name when dropdown was not used.
 *
 * @return array{success: bool, vendor_id?: int, error?: string}
 */
function purchase_entry_resolve_vendor_id(PDO $db, int $businessId, int $vendorId, string $vendorName): array
{
    if ($vendorId > 0) {
        $vendor = $db->prepare('SELECT id FROM vendors WHERE id = :id AND business_id = :bid LIMIT 1');
        $vendor->execute(['id' => $vendorId, 'bid' => $businessId]);
        if ($vendor->fetch()) {
            return ['success' => true, 'vendor_id' => $vendorId];
        }
    }

    $name = trim($vendorName);
    if ($name === '') {
        return ['success' => false, 'error' => 'Please select or enter a supplier name.'];
    }

    $find = $db->prepare('
        SELECT id FROM vendors
        WHERE business_id = :bid AND LOWER(TRIM(name)) = LOWER(:name)
        LIMIT 1
    ');
    $find->execute(['bid' => $businessId, 'name' => $name]);
    $row = $find->fetch();
    if ($row) {
        return ['success' => true, 'vendor_id' => (int) $row['id']];
    }

    $db->prepare('
        INSERT INTO vendors (business_id, name, payment_terms, status, created_at, updated_at)
        VALUES (:bid, :name, "Net 30", "active", NOW(), NOW())
    ')->execute(['bid' => $businessId, 'name' => $name]);

    return ['success' => true, 'vendor_id' => (int) $db->lastInsertId()];
}

function purchase_entry_audit(PDO $db, ?int $userId, string $action, int $entryId, string $details): void {
    try {
        $db->prepare('
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (:uid, :action, "purchase_entry", :eid, :details, :ip, NOW())
        ')->execute([
            'uid' => ($userId !== null && $userId > 0) ? $userId : null,
            'action' => $action,
            'eid' => $entryId,
            'details' => $details,
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 50) ?: null,
        ]);
    } catch (Throwable $e) {
    }
}

/**
 * @param array<string, mixed> $header
 * @param array<int, array<string, mixed>> $lines
 */
function save_purchase_entry(array $header, array $lines, ?int $entryId, ?int $userId, ?int $businessId = null): array {
    ensure_purchase_entry_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $canCost = purchase_entry_can_view_cost();

    $vendorResolved = purchase_entry_resolve_vendor_id(
        $db,
        $bid,
        (int) ($header['vendor_id'] ?? 0),
        (string) ($header['vendor_name'] ?? '')
    );
    if (!$vendorResolved['success']) {
        return $vendorResolved;
    }
    $vendorId = (int) $vendorResolved['vendor_id'];
    $warehouseId = (int) ($header['warehouse_id'] ?? 0);
    $wh = $db->prepare('SELECT id FROM warehouses WHERE id = :id AND business_id = :bid AND status = "active" LIMIT 1');
    $wh->execute(['id' => $warehouseId, 'bid' => $bid]);
    if (!$wh->fetch()) {
        return ['success' => false, 'error' => 'Choose a warehouse.'];
    }
    $purchaseDate = trim((string) ($header['purchase_date'] ?? ''));
    $receivedDate = trim((string) ($header['received_date'] ?? ''));
    foreach (['Purchase date' => $purchaseDate, 'Received date' => $receivedDate] as $label => $date) {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return ['success' => false, 'error' => $label . ' is required.'];
        }
    }
    $invoice = trim((string) ($header['supplier_invoice_no'] ?? ''));
    $notes = trim((string) ($header['notes'] ?? ''));

    $validated = purchase_entry_validate_lines($lines, $bid, false);
    if (!$validated['success']) {
        return $validated;
    }

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }
    try {
        if ($entryId !== null && $entryId > 0) {
            $lock = $db->prepare('SELECT id, status, stock_posted, user_id FROM purchase_entries WHERE id = :id AND business_id = :bid FOR UPDATE');
            $lock->execute(['id' => $entryId, 'bid' => $bid]);
            $existing = $lock->fetch();
            if (!$existing) {
                throw new RuntimeException('That purchase was not found.');
            }
            if (!in_array((string) $existing['status'], ['draft', 'pending_cost'], true) || (int) $existing['stock_posted'] === 1) {
                throw new RuntimeException('This purchase can no longer be edited.');
            }
            if (!$canCost && (int) ($existing['user_id'] ?? 0) !== (int) $userId) {
                throw new RuntimeException('You can edit only your own pending purchases.');
            }
            $db->prepare('
                UPDATE purchase_entries
                SET vendor_id = :vid, supplier_invoice_no = :inv, purchase_date = :pd, received_date = :rd,
                    warehouse_id = :wid, notes = :notes, status = "pending_cost", updated_at = NOW()
                WHERE id = :id AND business_id = :bid
            ')->execute([
                'vid' => $vendorId,
                'inv' => $invoice !== '' ? $invoice : null,
                'pd' => $purchaseDate,
                'rd' => $receivedDate,
                'wid' => $warehouseId,
                'notes' => $notes !== '' ? $notes : null,
                'id' => $entryId,
                'bid' => $bid,
            ]);
            $oldCosts = [];
            if (!$canCost) {
                $prev = $db->prepare('SELECT line_no, cost_price FROM purchase_entry_lines WHERE purchase_entry_id = :id');
                $prev->execute(['id' => $entryId]);
                foreach ($prev->fetchAll() as $prevLine) {
                    $oldCosts[(int) $prevLine['line_no']] = $prevLine['cost_price'];
                }
            }
            $db->prepare('DELETE FROM purchase_entry_lines WHERE purchase_entry_id = :id')->execute(['id' => $entryId]);
            $savedId = $entryId;
            $number = null;
        } else {
            $number = purchase_entry_next_number($db, $bid);
            $db->prepare('
                INSERT INTO purchase_entries (
                    business_id, entry_number, vendor_id, supplier_invoice_no, purchase_date, received_date,
                    warehouse_id, notes, status, stock_posted, user_id, created_at, updated_at
                ) VALUES (
                    :bid, :num, :vid, :inv, :pd, :rd, :wid, :notes, "pending_cost", 0, :uid, NOW(), NOW()
                )
            ')->execute([
                'bid' => $bid,
                'num' => $number,
                'vid' => $vendorId,
                'inv' => $invoice !== '' ? $invoice : null,
                'pd' => $purchaseDate,
                'rd' => $receivedDate,
                'wid' => $warehouseId,
                'notes' => $notes !== '' ? $notes : null,
                'uid' => ($userId !== null && $userId > 0) ? $userId : null,
            ]);
            $savedId = (int) $db->lastInsertId();
            $oldCosts = [];
        }

        $ins = $db->prepare('
            INSERT INTO purchase_entry_lines (
                purchase_entry_id, line_no, category_id, subcategory_id, sku, colour, size_label,
                selling_price, cost_price, quantity, created_at
            ) VALUES (
                :eid, :no, :cid, :sid, :sku, :colour, :size, :sp, :cp, :qty, NOW()
            )
        ');
        $assigned = [];
        $lineNo = 1;
        foreach ($validated['lines'] as $line) {
            $sku = $line['sku'];
            if ($sku !== '') {
                $clashLine = $db->prepare('
                    SELECT pel.id FROM purchase_entry_lines pel
                    INNER JOIN purchase_entries pe ON pe.id = pel.purchase_entry_id
                    WHERE pe.business_id = :bid AND pel.sku = :sku AND pe.id <> :eid
                    LIMIT 1
                ');
                $clashLine->execute(['bid' => $bid, 'sku' => $sku, 'eid' => $savedId]);
                if ($clashLine->fetchColumn()) {
                    throw new RuntimeException('This SKU already exists.');
                }
            }
            $cost = $canCost ? $line['cost_price'] : ($oldCosts[$lineNo] ?? null);
            $ins->execute([
                'eid' => $savedId,
                'no' => $lineNo,
                'cid' => $line['category_id'],
                'sid' => $line['subcategory_id'],
                'sku' => $sku,
                'colour' => $line['colour'],
                'size' => $line['size'],
                'sp' => $line['selling_price'],
                'cp' => $cost,
                'qty' => $line['quantity'],
            ]);
            $assigned[] = $sku;
            $lineNo++;
        }

        if ($number === null) {
            $numStmt = $db->prepare('SELECT entry_number FROM purchase_entries WHERE id = :id');
            $numStmt->execute(['id' => $savedId]);
            $number = (string) $numStmt->fetchColumn();
        }
        purchase_entry_audit($db, $userId, $entryId ? 'purchase_edited' : 'purchase_created', $savedId, $number);
        if ($ownTx) {
            $db->commit();
        }
        return ['success' => true, 'entry_id' => $savedId, 'entry_number' => $number, 'skus' => $assigned];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        $message = $e->getMessage();
        if ($message === '' || str_starts_with($message, 'SQLSTATE')) {
            $message = 'Could not save this purchase.';
        }
        return ['success' => false, 'error' => $message];
    }
}

function purchase_entry_strip_cost(array $entry, bool $canCost): array {
    if ($canCost) {
        return $entry;
    }
    unset($entry['cost_total']);
    if (!empty($entry['lines']) && is_array($entry['lines'])) {
        foreach ($entry['lines'] as $i => $line) {
            $entry['lines'][$i]['cost_price'] = null;
            unset($entry['lines'][$i]['margin']);
        }
    }
    return $entry;
}

function get_purchase_entries(string $status = '', ?int $businessId = null): array {
    ensure_purchase_entry_schema();
    $bid = $businessId ?: current_business_id();
    $db = get_db();
    $sql = '
        SELECT pe.id, pe.entry_number, pe.status, pe.purchase_date, pe.received_date, pe.supplier_invoice_no, pe.stock_posted,
               v.name AS vendor_name,
               (SELECT COUNT(*) FROM purchase_entry_lines pel WHERE pel.purchase_entry_id = pe.id) AS item_count,
               (SELECT COALESCE(SUM(pel.quantity), 0) FROM purchase_entry_lines pel WHERE pel.purchase_entry_id = pe.id) AS total_qty
        FROM purchase_entries pe
        INNER JOIN vendors v ON v.id = pe.vendor_id AND v.business_id = :bid_v
        WHERE pe.business_id = :bid
    ';
    $params = ['bid' => $bid, 'bid_v' => $bid];
    if ($status === 'pending_cost') {
        $sql .= ' AND pe.status IN ("draft","pending_cost")';
    } elseif ($status !== '') {
        $sql .= ' AND pe.status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY pe.id DESC LIMIT 100';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!purchase_entry_can_view_cost()) {
        foreach ($rows as $i => $row) {
            unset($rows[$i]['cost_total']);
        }
    }
    return $rows;
}

function get_purchase_entry_by_id(int $id, ?int $businessId = null, bool $includeCost = true): ?array {
    ensure_purchase_entry_schema();
    $bid = $businessId ?: current_business_id();
    $db = get_db();
    $stmt = $db->prepare('
        SELECT pe.*, v.name AS vendor_name, v.phone AS vendor_phone, w.name AS warehouse_name
        FROM purchase_entries pe
        INNER JOIN vendors v ON v.id = pe.vendor_id AND v.business_id = :bid_v
        INNER JOIN warehouses w ON w.id = pe.warehouse_id AND w.business_id = :bid_w
        WHERE pe.id = :id AND pe.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid, 'bid_v' => $bid, 'bid_w' => $bid]);
    $entry = $stmt->fetch();
    if (!$entry) {
        return null;
    }
    $lines = $db->prepare('
        SELECT pel.*, c.name AS category_name, s.name AS subcategory_name
        FROM purchase_entry_lines pel
        INNER JOIN categories c ON c.id = pel.category_id
        LEFT JOIN purchase_subcategories s ON s.id = pel.subcategory_id
        WHERE pel.purchase_entry_id = :id
        ORDER BY pel.line_no ASC
    ');
    $lines->execute(['id' => $id]);
    $entry['lines'] = $lines->fetchAll();
    $can = $includeCost && purchase_entry_can_view_cost();
    return purchase_entry_strip_cost($entry, $can);
}

function finalize_purchase_entry(int $entryId, array $costs, ?int $userId, ?int $businessId = null): array {
    ensure_purchase_entry_schema();
    if (!purchase_entry_can_view_cost()) {
        return ['success' => false, 'error' => 'Only the owner can enter cost price and finalize a purchase.'];
    }
    require_once __DIR__ . '/products_db.php';
    ensure_product_item_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $entry = get_purchase_entry_by_id($entryId, $bid, true);
    if (!$entry) {
        return ['success' => false, 'error' => 'That purchase was not found.'];
    }
    if (!in_array((string) $entry['status'], ['draft', 'pending_cost'], true)) {
        return ['success' => false, 'error' => 'This purchase is already finalized.'];
    }
    if (trim((string) ($entry['supplier_invoice_no'] ?? '')) === '') {
        return ['success' => false, 'error' => 'Please enter the supplier invoice number.'];
    }
    if (empty($entry['lines'])) {
        return ['success' => false, 'error' => 'Add at least one product.'];
    }

    $costByLine = [];
    foreach ($costs as $row) {
        $costByLine[(int) ($row['line_no'] ?? 0)] = $row['cost_price'] ?? null;
    }

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }
    try {
        $lock = $db->prepare('SELECT status, stock_posted, entry_number, warehouse_id FROM purchase_entries WHERE id = :id AND business_id = :bid FOR UPDATE');
        $lock->execute(['id' => $entryId, 'bid' => $bid]);
        $locked = $lock->fetch();
        if (!$locked || (int) $locked['stock_posted'] === 1 || !in_array((string) $locked['status'], ['draft', 'pending_cost'], true)) {
            throw new RuntimeException('This purchase is already finalized.');
        }
        $warehouseId = (int) $locked['warehouse_id'];
        $entryNumber = (string) $locked['entry_number'];

        foreach ($entry['lines'] as $line) {
            $lineNo = (int) $line['line_no'];
            $rawCost = $costByLine[$lineNo] ?? $line['cost_price'];
            if (!is_numeric($rawCost) || (float) $rawCost < 0) {
                throw new RuntimeException('Please enter cost price before finalizing.');
            }
            $cost = number_format((float) $rawCost, 2, '.', '');
            $db->prepare('UPDATE purchase_entry_lines SET cost_price = :cp WHERE id = :id AND purchase_entry_id = :eid')
                ->execute(['cp' => $cost, 'id' => (int) $line['id'], 'eid' => $entryId]);
            $line['cost_price'] = $cost;
            purchase_entry_post_line($db, $bid, $warehouseId, $entryNumber, $line, $userId);
        }

        $updated = $db->prepare('
            UPDATE purchase_entries
            SET status = "finalized", stock_posted = 1, finalized_by = :uid, finalized_at = NOW(), updated_at = NOW()
            WHERE id = :id AND business_id = :bid AND stock_posted = 0
        ');
        $updated->execute([
            'uid' => ($userId !== null && $userId > 0) ? $userId : null,
            'id' => $entryId,
            'bid' => $bid,
        ]);
        if ($updated->rowCount() !== 1) {
            throw new RuntimeException('This purchase is already finalized.');
        }
        purchase_entry_audit($db, $userId, 'purchase_finalized', $entryId, $entryNumber . ' stock posted');
        if ($ownTx) {
            $db->commit();
        }
        return ['success' => true, 'entry_number' => $entryNumber];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        $message = $e->getMessage();
        if ($message === '' || str_starts_with($message, 'SQLSTATE')) {
            $message = 'Could not finalize this purchase.';
        }
        return ['success' => false, 'error' => $message];
    }
}

function purchase_entry_product_name_from_line(array $line): string
{
    $sub = trim((string) ($line['subcategory_name'] ?? ''));
    if ($sub !== '' && in_array(strtolower($sub), ['none', 'n/a', '-'], true)) {
        $sub = '';
    }
    $parts = array_filter([
        $sub,
        trim((string) ($line['colour'] ?? '')),
        trim((string) ($line['size_label'] ?? '')),
    ], static fn(string $p): bool => $p !== '');
    $name = trim(implode(' ', $parts));
    if ($name === '') {
        $name = strtoupper(trim((string) ($line['sku'] ?? '')));
    }
    if ($name === '') {
        $name = 'Item';
    }
    return preg_replace('/\s+/', ' ', $name) ?? $name;
}

/**
 * Remove category name wrongly prefixed on products created from purchase entry (one-time / safe re-run).
 *
 * @return array{success: bool, fixed: int, skipped: int}
 */
function purchase_entry_repair_prefixed_product_names(?int $businessId = null): array
{
    $db = get_db();
    $fixed = 0;
    $skipped = 0;

    if ($businessId !== null && $businessId > 0) {
        $businessIds = [$businessId];
    } else {
        $ids = $db->query('SELECT DISTINCT business_id FROM products ORDER BY business_id')->fetchAll(PDO::FETCH_COLUMN);
        $businessIds = array_values(array_filter(array_map('intval', $ids ?: []), static fn(int $id): bool => $id > 0));
        if ($businessIds === []) {
            $businessIds = [current_business_id()];
        }
    }

    $stmt = $db->prepare('
        SELECT p.id, p.name, p.sku, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id AND c.business_id = p.business_id
        WHERE p.business_id = :bid
    ');

    $upd = $db->prepare('
        UPDATE products SET name = :name, updated_at = NOW()
        WHERE id = :id AND business_id = :bid AND name = :old
    ');

    foreach ($businessIds as $bid) {
        $stmt->execute(['bid' => $bid]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $oldName = trim((string) ($row['name'] ?? ''));
            $cat = trim((string) ($row['category_name'] ?? ''));
            if ($oldName === '') {
                $skipped++;
                continue;
            }

            $newName = $oldName;
            if ($cat !== '') {
                $pattern = '/^' . preg_quote($cat, '/') . '\s+/iu';
                if (preg_match($pattern, $oldName)) {
                    $newName = trim((string) preg_replace($pattern, '', $oldName, 1));
                }
            }

            if ($newName === '' || $newName === $oldName) {
                $skipped++;
                continue;
            }

            $upd->execute([
                'name' => $newName,
                'id' => (int) $row['id'],
                'bid' => $bid,
                'old' => $oldName,
            ]);
            if ($upd->rowCount() === 1) {
                $fixed++;
            } else {
                $skipped++;
            }
        }
    }

    return ['success' => true, 'fixed' => $fixed, 'skipped' => $skipped];
}

function purchase_entry_post_line(PDO $db, int $businessId, int $warehouseId, string $entryNumber, array $line, ?int $userId): void {
    $sku = strtoupper(trim((string) $line['sku']));
    $qty = (int) $line['quantity'];
    $selling = (float) $line['selling_price'];
    $cost = (float) $line['cost_price'];
    $name = purchase_entry_product_name_from_line($line);

    $find = $db->prepare('SELECT id, stock_quantity, barcode FROM products WHERE business_id = :bid AND sku = :sku LIMIT 1 FOR UPDATE');
    $find->execute(['bid' => $businessId, 'sku' => $sku]);
    $product = $find->fetch();
    $barcode = purchase_entry_alloc_barcode($db, $businessId);

    if ($product) {
        $productId = (int) $product['id'];
        $before = (int) $product['stock_quantity'];
        $keepBarcode = trim((string) ($product['barcode'] ?? ''));
        if ($keepBarcode !== '') {
            $barcode = $keepBarcode;
        }
        $db->prepare('
            UPDATE products
            SET stock_quantity = stock_quantity + :qty, selling_price = :sp, cost_price = :cp,
                barcode = COALESCE(NULLIF(barcode, ""), :bc), updated_at = NOW()
            WHERE id = :id AND business_id = :bid
        ')->execute([
            'qty' => $qty, 'sp' => $selling, 'cp' => $cost, 'bc' => $barcode, 'id' => $productId, 'bid' => $businessId,
        ]);
    } else {
        $db->prepare('
            INSERT INTO products (
                business_id, category_id, name, sku, barcode, cost_price, selling_price, tax_percent,
                stock_quantity, low_stock_threshold, status, created_at, updated_at
            ) VALUES (
                :bid, :cid, :name, :sku, :bc, :cp, :sp, 0, :qty, 5, "active", NOW(), NOW()
            )
        ')->execute([
            'bid' => $businessId,
            'cid' => (int) $line['category_id'],
            'name' => $name,
            'sku' => $sku,
            'bc' => $barcode,
            'cp' => $cost,
            'sp' => $selling,
            'qty' => $qty,
        ]);
        $productId = (int) $db->lastInsertId();
        $before = 0;
    }

    $db->prepare('
        INSERT INTO inventory_movements (
            business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
        ) VALUES (
            :bid, :pid, :uid, "in", :change, :before, :after, :reason, NOW()
        )
    ')->execute([
        'bid' => $businessId,
        'pid' => $productId,
        'uid' => ($userId !== null && $userId > 0) ? $userId : null,
        'change' => $qty,
        'before' => $before,
        'after' => $before + $qty,
        'reason' => 'Purchase ' . $entryNumber . ' finalized',
    ]);

    $have = $db->prepare('SELECT stock_quantity FROM warehouse_stock WHERE product_id = :pid AND warehouse_id = :wid LIMIT 1 FOR UPDATE');
    $have->execute(['pid' => $productId, 'wid' => $warehouseId]);
    $current = $have->fetchColumn();
    $next = ($current === false ? 0 : (int) $current) + $qty;
    $db->prepare('
        INSERT INTO warehouse_stock (warehouse_id, product_id, stock_quantity, created_at, updated_at)
        VALUES (:wid, :pid, :qty, NOW(), NOW())
        ON DUPLICATE KEY UPDATE stock_quantity = :qty_up, updated_at = NOW()
    ')->execute(['wid' => $warehouseId, 'pid' => $productId, 'qty' => $next, 'qty_up' => $next]);

    $variantId = purchase_entry_upsert_variant($db, $businessId, $productId, $line, $sku, $barcode, $selling, $cost, $qty);
    $db->prepare('
        UPDATE purchase_entry_lines
        SET product_id = :pid, variant_id = :vid, barcode = :bc
        WHERE id = :id
    ')->execute([
        'pid' => $productId,
        'vid' => $variantId,
        'bc' => $barcode,
        'id' => (int) $line['id'],
    ]);
}

function purchase_entry_upsert_variant(PDO $db, int $businessId, int $productId, array $line, string $sku, string $barcode, float $selling, float $cost, int $qty): int {
    $attrs = json_encode(['Colour' => $line['colour'], 'Size' => $line['size_label']], JSON_UNESCAPED_UNICODE);
    $variantName = $line['colour'] . ' / ' . $line['size_label'];
    $find = $db->prepare('SELECT id, stock_quantity FROM product_variants WHERE business_id = :bid AND sku = :sku LIMIT 1 FOR UPDATE');
    $find->execute(['bid' => $businessId, 'sku' => $sku]);
    $existing = $find->fetch();
    if ($existing) {
        $db->prepare('
            UPDATE product_variants
            SET stock_quantity = stock_quantity + :qty, selling_price = :sp, cost_price = :cp,
                barcode = COALESCE(NULLIF(barcode, ""), :bc), attribute_values = :av, updated_at = NOW()
            WHERE id = :id
        ')->execute([
            'qty' => $qty, 'sp' => $selling, 'cp' => $cost, 'bc' => $barcode, 'av' => $attrs, 'id' => (int) $existing['id'],
        ]);
        return (int) $existing['id'];
    }
    $db->prepare('
        INSERT INTO product_variants (
            business_id, product_id, variant_name, attribute_values, sku, barcode, cost_price, selling_price, stock_quantity, status, created_at, updated_at
        ) VALUES (
            :bid, :pid, :vn, :av, :sku, :bc, :cp, :sp, :qty, "active", NOW(), NOW()
        )
    ')->execute([
        'bid' => $businessId,
        'pid' => $productId,
        'vn' => $variantName,
        'av' => $attrs,
        'sku' => $sku,
        'bc' => $barcode,
        'cp' => $cost,
        'sp' => $selling,
        'qty' => $qty,
    ]);
    return (int) $db->lastInsertId();
}

function purchase_entry_alloc_barcode(PDO $db, int $businessId): string {
    for ($try = 0; $try < 12; $try++) {
        $code = '2'
            . str_pad((string) ($businessId % 10000), 4, '0', STR_PAD_LEFT)
            . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $checks = [
            'SELECT id FROM products WHERE business_id = :bid AND barcode = :bc LIMIT 1',
            'SELECT id FROM product_variants WHERE barcode = :bc LIMIT 1',
            'SELECT id FROM purchase_entry_lines WHERE barcode = :bc LIMIT 1',
        ];
        $taken = false;
        foreach ($checks as $sql) {
            $stmt = $db->prepare($sql);
            $params = ['bc' => $code];
            if (str_contains($sql, ':bid')) {
                $params['bid'] = $businessId;
            }
            $stmt->execute($params);
            if ($stmt->fetchColumn()) {
                $taken = true;
                break;
            }
        }
        if (!$taken) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a barcode.');
}

function purchase_entry_labels(int $entryId, ?int $businessId = null): array {
    $entry = get_purchase_entry_by_id($entryId, $businessId, false);
    if (!$entry) {
        return ['success' => false, 'error' => 'That purchase was not found.'];
    }
    if (!in_array((string) $entry['status'], ['finalized', 'barcode_generated', 'completed'], true)) {
        return ['success' => false, 'error' => 'Finalize the purchase before printing barcodes.'];
    }
    $business = current_business();
    $store = (string) ($business['legal_name'] ?? $business['name'] ?? 'Store');
    $labels = [];
    foreach ($entry['lines'] as $line) {
        $code = trim((string) ($line['barcode'] ?? ''));
        if ($code === '') {
            continue;
        }
        $labels[] = [
            'store' => $store,
            'sku' => (string) $line['sku'],
            'colour' => (string) $line['colour'],
            'size' => (string) $line['size_label'],
            'price' => (string) $line['selling_price'],
            'barcode' => $code,
            'copies' => (int) $line['quantity'],
            'line_no' => (int) $line['line_no'],
        ];
    }
    if ($labels === []) {
        return ['success' => false, 'error' => 'This purchase has no barcode ready to print.'];
    }
    return ['success' => true, 'entry_number' => $entry['entry_number'], 'labels' => $labels];
}

function purchase_entry_mark_status(int $entryId, string $status, ?int $userId, ?int $businessId = null): array {
    ensure_purchase_entry_schema();
    $allowed = [
        'finalized' => 'barcode_generated',
        'barcode_generated' => 'completed',
    ];
    $bid = $businessId ?: current_business_id();
    $db = get_db();
    $stmt = $db->prepare('SELECT status, entry_number FROM purchase_entries WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $entryId, 'bid' => $bid]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'error' => 'That purchase was not found.'];
    }
    $current = (string) $row['status'];
    if ($current === $status || ($status === 'completed' && $current === 'completed')) {
        return ['success' => true];
    }
    if (($allowed[$current] ?? '') !== $status && !($status === 'completed' && $current === 'finalized')) {
        return ['success' => false, 'error' => 'This purchase is not ready for that step.'];
    }
    $db->prepare('UPDATE purchase_entries SET status = :status, updated_at = NOW() WHERE id = :id AND business_id = :bid')
        ->execute(['status' => $status, 'id' => $entryId, 'bid' => $bid]);
    $action = $status === 'completed' ? 'barcode_reprinted' : 'barcode_generated';
    purchase_entry_audit($db, $userId, $action, $entryId, (string) $row['entry_number']);
    return ['success' => true];
}

function purchase_entry_add_option(string $kind, string $name, int $categoryId, ?int $businessId = null): array {
    ensure_purchase_entry_schema();
    if (!purchase_entry_can_view_cost()) {
        return ['success' => false, 'error' => 'Only the owner can add colours, sizes, or sub-categories.'];
    }
    $bid = $businessId ?: current_business_id();
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80) {
        return ['success' => false, 'error' => 'Enter a name.'];
    }
    $db = get_db();
    if ($kind === 'subcategory') {
        require_once __DIR__ . '/products_db.php';
        if (!get_category_by_id($categoryId, $bid)) {
            return ['success' => false, 'error' => 'Please select a category.'];
        }
        $db->prepare('
            INSERT IGNORE INTO purchase_subcategories (business_id, category_id, name, status, created_at)
            VALUES (:bid, :cid, :name, "active", NOW())
        ')->execute(['bid' => $bid, 'cid' => $categoryId, 'name' => $name]);
        $id = $db->prepare('SELECT id FROM purchase_subcategories WHERE business_id = :bid AND category_id = :cid AND name = :name LIMIT 1');
        $id->execute(['bid' => $bid, 'cid' => $categoryId, 'name' => $name]);
        return ['success' => true, 'id' => (int) $id->fetchColumn(), 'name' => $name];
    }
    if (!in_array($kind, ['colour', 'size'], true)) {
        return ['success' => false, 'error' => 'Unknown option.'];
    }
    $cat = $kind === 'size' && $categoryId > 0 ? $categoryId : 0;
    $db->prepare('
        INSERT IGNORE INTO purchase_attr_options (business_id, attr_type, category_id, name, status, created_at)
        VALUES (:bid, :type, :cid, :name, "active", NOW())
    ')->execute(['bid' => $bid, 'type' => $kind, 'cid' => $cat, 'name' => $name]);
    return ['success' => true, 'name' => $name];
}

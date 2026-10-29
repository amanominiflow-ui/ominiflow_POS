<?php
/**
 * Warehouse inward count.
 * Saving a count adds company stock at once. It is not ready stock in Central.
 * QC, checking, and tagging has no pass or fail. Completing it places ready stock in Central only.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function inward_entry_can_manage_cost(?array $user = null): bool {
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
    return has_permission('purchases.inward_entry_cost.view', $user)
        || has_permission('purchases.inward_entry_cost.edit', $user);
}

function inward_entry_permission_for_action(string $action): string {
    return match ($action) {
        'save_inward', 'save_matrix_inward', 'import_matrix_csv', 'quick_create_product' => 'purchases.inward_entry.create',
        'confirm_costs' => 'purchases.inward_entry_cost.edit',
        'confirm_purchase', 'complete_qc', 'create_bill' => 'purchases.inward_entry.edit',
        default => 'purchases.inward_entry.view',
    };
}

function inward_entry_assert_action(string $action, bool $ajax = false): void {
    require_once __DIR__ . '/roles_db.php';
    $perm = inward_entry_permission_for_action($action);
    if (has_permission($perm)) {
        return;
    }
    if ($ajax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have permission for this action.']);
        exit;
    }
    set_flash('error', 'Access denied for Inward Entry.');
    redirect(APP_URL . '/inward-entry.php');
}

function ensure_inward_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = get_db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS `inward_entries` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `entry_number` VARCHAR(50) NOT NULL,
            `warehouse_id` INT UNSIGNED NOT NULL,
            `vendor_id` INT UNSIGNED NULL,
            `user_id` INT UNSIGNED NULL,
            `entry_date` DATE NOT NULL,
            `status` ENUM('cost_pending','confirmed') NOT NULL DEFAULT 'cost_pending',
            `purchase_status` ENUM('pending','confirmed') NOT NULL DEFAULT 'pending',
            `is_sellable` TINYINT(1) NOT NULL DEFAULT 0,
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_inward_entry_number` (`business_id`, `entry_number`),
            INDEX `idx_inward_business` (`business_id`),
            INDEX `idx_inward_warehouse` (`warehouse_id`),
            INDEX `idx_inward_date` (`entry_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `inward_lines` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `inward_entry_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `variant_id` INT UNSIGNED NULL,
            `size` VARCHAR(80) NOT NULL,
            `colour` VARCHAR(80) NOT NULL,
            `quantity` INT UNSIGNED NOT NULL,
            `purchase_cost` DECIMAL(12,2) NULL,
            `selling_price` DECIMAL(12,2) NULL,
            `cost_status` ENUM('pending','confirmed') NOT NULL DEFAULT 'pending',
            `barcode` VARCHAR(100) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_inward_line_entry` (`inward_entry_id`),
            INDEX `idx_inward_line_product` (`product_id`),
            CONSTRAINT `fk_inward_line_entry` FOREIGN KEY (`inward_entry_id`) REFERENCES `inward_entries` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    inward_migrate_cost_columns($db);
}

function inward_migrate_cost_columns(PDO $db): void {
    try {
        $db->exec("ALTER TABLE `inward_entries` MODIFY `status` ENUM('counted','cost_pending','confirmed') NOT NULL DEFAULT 'cost_pending'");
        $db->exec("UPDATE `inward_entries` SET `status` = 'cost_pending' WHERE `status` = 'counted'");
    } catch (Throwable $e) {
    }

    try {
        $existing = $db->query('SHOW COLUMNS FROM `inward_lines`')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }
    $add = [
        'sku' => 'VARCHAR(100) NULL AFTER `variant_id`',
        'purchase_cost' => 'DECIMAL(12,2) NULL AFTER `quantity`',
        'selling_price' => 'DECIMAL(12,2) NULL AFTER `purchase_cost`',
        'cost_status' => "ENUM('pending','confirmed') NOT NULL DEFAULT 'pending' AFTER `selling_price`",
        'barcode' => 'VARCHAR(100) NULL AFTER `cost_status`',
    ];
    foreach ($add as $col => $definition) {
        if (!in_array($col, $existing, true)) {
            try {
                $db->exec("ALTER TABLE `inward_lines` ADD COLUMN `{$col}` {$definition}");
            } catch (Throwable $e) {
            }
        }
    }

    try {
        $entryCols = $db->query('SHOW COLUMNS FROM `inward_entries`')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('supplier_reference', $entryCols, true)) {
            $db->exec('ALTER TABLE `inward_entries` ADD COLUMN `supplier_reference` VARCHAR(100) NULL AFTER `vendor_id`');
        }
        if (!in_array('bill_id', $entryCols, true)) {
            $db->exec('ALTER TABLE `inward_entries` ADD COLUMN `bill_id` INT UNSIGNED NULL AFTER `vendor_id`');
        }
        if (!in_array('purchase_status', $entryCols, true)) {
            $db->exec("ALTER TABLE `inward_entries` ADD COLUMN `purchase_status` ENUM('pending','confirmed') NOT NULL DEFAULT 'pending' AFTER `status`");
        }
        if (!in_array('qc_status', $entryCols, true)) {
            $db->exec("ALTER TABLE `inward_entries` ADD COLUMN `qc_status` ENUM('pending','done') NOT NULL DEFAULT 'pending' AFTER `purchase_status`");
        }
        if (!in_array('company_stock_posted', $entryCols, true)) {
            $db->exec('ALTER TABLE `inward_entries` ADD COLUMN `company_stock_posted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_sellable`');
        }
        if (!in_array('inward_mode', $entryCols, true)) {
            $db->exec("ALTER TABLE `inward_entries` ADD COLUMN `inward_mode` ENUM('standard','matrix','import') NOT NULL DEFAULT 'standard' AFTER `notes`");
        }
    } catch (Throwable $e) {
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `warehouse_variant_stock` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `warehouse_id` INT UNSIGNED NOT NULL,
                `product_id` INT UNSIGNED NOT NULL,
                `variant_id` INT UNSIGNED NOT NULL,
                `stock_quantity` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_wh_variant` (`warehouse_id`, `variant_id`),
                INDEX `idx_wvs_wh` (`warehouse_id`),
                INDEX `idx_wvs_prod` (`product_id`),
                INDEX `idx_wvs_var` (`variant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }
}

function inward_count_catalog(?int $businessId = null): array {
    ensure_inward_schema();
    require_once __DIR__ . '/orders_db.php';
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmtP = $db->prepare('
        SELECT id, name, sku
        FROM products
        WHERE business_id = :bid AND status = "active"
        ORDER BY name ASC
    ');
    $stmtP->execute(['bid' => $bid]);
    $products = $stmtP->fetchAll();

    $stmtV = $db->prepare('
        SELECT id, product_id, variant_name, attribute_values
        FROM product_variants
        WHERE business_id = :bid AND status = "active"
        ORDER BY id ASC
    ');
    $stmtV->execute(['bid' => $bid]);

    $byProduct = [];
    foreach ($stmtV->fetchAll() as $variant) {
        $attrs = parse_variant_size_colour($variant);
        if ($attrs['size'] === '' && $attrs['colour'] === '') {
            continue;
        }
        $pid = (int) $variant['product_id'];
        $byProduct[$pid][] = [
            'id' => (int) $variant['id'],
            'size' => $attrs['size'] !== '' ? $attrs['size'] : 'Standard',
            'colour' => $attrs['colour'] !== '' ? $attrs['colour'] : 'Standard',
        ];
    }

    $catalog = [];
    foreach ($products as $product) {
        $pid = (int) $product['id'];
        $catalog[] = [
            'id' => $pid,
            'name' => (string) $product['name'],
            'sku' => (string) ($product['sku'] ?? ''),
            'variants' => $byProduct[$pid] ?? [],
        ];
    }
    return $catalog;
}

/**
 * @param array<int, array<string, mixed>> $lines
 * @return array{success:bool, error?:string, entry_id?:int, entry_number?:string}
 */
function create_inward_entry(
    int $warehouseId,
    array $lines,
    string $entryDate = '',
    string $notes = '',
    ?int $vendorId = null,
    ?int $userId = null,
    ?int $businessId = null
): array {
    ensure_inward_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    if ($warehouseId <= 0) {
        return ['success' => false, 'error' => 'Choose the warehouse that was counted.'];
    }
    $stmtWh = $db->prepare('SELECT id FROM warehouses WHERE id = :id AND business_id = :bid AND status = "active" LIMIT 1');
    $stmtWh->execute(['id' => $warehouseId, 'bid' => $bid]);
    if (!$stmtWh->fetch()) {
        return ['success' => false, 'error' => 'That warehouse is not available for this business.'];
    }

    if ($vendorId !== null && $vendorId > 0) {
        $stmtV = $db->prepare('SELECT id FROM vendors WHERE id = :id AND business_id = :bid LIMIT 1');
        $stmtV->execute(['id' => $vendorId, 'bid' => $bid]);
        if (!$stmtV->fetch()) {
            return ['success' => false, 'error' => 'That supplier was not found.'];
        }
    } else {
        $vendorId = null;
    }

    $entryDate = trim($entryDate);
    if ($entryDate === '') {
        $entryDate = date('Y-m-d');
    }
    $parsedDate = DateTime::createFromFormat('Y-m-d', $entryDate);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $entryDate) {
        return ['success' => false, 'error' => 'Enter a valid count date.'];
    }
    if ($entryDate > date('Y-m-d')) {
        return ['success' => false, 'error' => 'The count date cannot be in the future.'];
    }

    $resolved = inward_resolve_lines($lines, $bid);
    if (!$resolved['success']) {
        return ['success' => false, 'error' => $resolved['error']];
    }

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    } else {
        $db->exec('SAVEPOINT inward_entry');
    }

    try {
        $entryNumber = inward_next_number($db, $bid);
        $stmt = $db->prepare('
            INSERT INTO inward_entries (
                business_id, entry_number, warehouse_id, vendor_id, user_id,
                entry_date, status, is_sellable, notes, created_at, updated_at
            ) VALUES (
                :bid, :num, :wid, :vid, :uid,
                :entry_date, "cost_pending", 0, :notes, NOW(), NOW()
            )
        ');
        $stmt->execute([
            'bid' => $bid,
            'num' => $entryNumber,
            'wid' => $warehouseId,
            'vid' => $vendorId,
            'uid' => ($userId !== null && $userId > 0) ? $userId : null,
            'entry_date' => $entryDate,
            'notes' => trim($notes) !== '' ? trim($notes) : null,
        ]);
        $entryId = (int) $db->lastInsertId();

        $stmtLine = $db->prepare('
            INSERT INTO inward_lines (
                inward_entry_id, product_id, variant_id, sku, size, colour, quantity,
                purchase_cost, selling_price, cost_status, created_at
            ) VALUES (
                :eid, :pid, :varid, :sku, :size, :colour, :qty,
                NULL, NULL, "pending", NOW()
            )
        ');
        foreach ($resolved['lines'] as $line) {
            $stmtLine->execute([
                'eid' => $entryId,
                'pid' => $line['product_id'],
                'varid' => $line['variant_id'],
                'sku' => $line['sku'] ?? null,
                'size' => $line['size'],
                'colour' => $line['colour'],
                'qty' => $line['quantity'],
            ]);
        }

        inward_post_company_stock($db, $bid, $entryId, $entryNumber, $resolved['lines'], $userId, $warehouseId);
        $db->prepare('UPDATE inward_entries SET company_stock_posted = 1 WHERE id = :id AND business_id = :bid')
            ->execute(['id' => $entryId, 'bid' => $bid]);

        if ($ownTx) {
            $db->commit();
        } else {
            $db->exec('RELEASE SAVEPOINT inward_entry');
        }

        return ['success' => true, 'entry_id' => $entryId, 'entry_number' => $entryNumber];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        } elseif ($db->inTransaction()) {
            $db->exec('ROLLBACK TO SAVEPOINT inward_entry');
        }
        return ['success' => false, 'error' => 'Could not save the inward count.'];
    }
}

/**
 * Use an existing supplier for this business, or save a typed name.
 *
 * @return array{success:bool, error?:string, vendor_id:?int}
 */
function inward_resolve_vendor(?int $vendorId, ?string $vendorName, int $businessId, bool $required): array {
    require_once __DIR__ . '/purchases_db.php';
    $db = get_db();
    if ($vendorId !== null && $vendorId > 0) {
        $stmt = $db->prepare('SELECT id FROM vendors WHERE id = :id AND business_id = :bid LIMIT 1');
        $stmt->execute(['id' => $vendorId, 'bid' => $businessId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'That supplier was not found for this business.', 'vendor_id' => null];
        }
        return ['success' => true, 'vendor_id' => $vendorId];
    }

    $name = trim((string) $vendorName);
    if ($name === '') {
        if ($required) {
            return ['success' => false, 'error' => 'Type the supplier name. This business has no suppliers saved yet, so the bill cannot be created until you enter one.', 'vendor_id' => null];
        }
        return ['success' => true, 'vendor_id' => null];
    }
    if (mb_strlen($name) > 191) {
        return ['success' => false, 'error' => 'Supplier name is too long.', 'vendor_id' => null];
    }

    $stmt = $db->prepare('SELECT id FROM vendors WHERE business_id = :bid AND name = :name LIMIT 1');
    $stmt->execute(['bid' => $businessId, 'name' => $name]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return ['success' => true, 'vendor_id' => (int) $existing];
    }

    $saved = save_vendor(['name' => $name], null, $businessId);
    if (empty($saved['success'])) {
        return ['success' => false, 'error' => $saved['error'] ?? 'Could not save the supplier.', 'vendor_id' => null];
    }
    return ['success' => true, 'vendor_id' => (int) $saved['id']];
}

/**
 * Confirm purchase cost and selling price on an inward count.
 * Prices stay null until this runs. Product, variant, stock, and barcode rows are not changed.
 * Confirming cost does not release a barcode for the warehouse to print.
 *
 * @param array<int, array<string, mixed>> $prices
 * @return array{success:bool, error?:string}
 */
function confirm_inward_costs(int $entryId, array $prices, ?int $businessId = null, ?int $vendorId = null, ?int $userId = null, ?string $vendorName = null): array {
    ensure_inward_schema();
    require_once __DIR__ . '/purchases_db.php';
    ensure_purchases_full_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $entry = get_inward_entry_by_id($entryId, $bid);
    if (!$entry) {
        return ['success' => false, 'error' => 'That inward count was not found.'];
    }
    if ((string) $entry['status'] === 'confirmed') {
        return ['success' => false, 'error' => 'Costs for this count are already confirmed.'];
    }
    if ((string) $entry['status'] !== 'cost_pending') {
        return ['success' => false, 'error' => 'This count is not waiting for costs.'];
    }

    $byId = [];
    foreach ($prices as $raw) {
        $lineId = (int) ($raw['line_id'] ?? 0);
        if ($lineId > 0) {
            $byId[$lineId] = $raw;
        }
    }

    $resolved = [];
    foreach ($entry['lines'] as $line) {
        $lineId = (int) $line['id'];
        $raw = $byId[$lineId] ?? null;
        if ($raw === null) {
            return ['success' => false, 'error' => 'Enter purchase cost and selling price for every line.'];
        }
        $cost = inward_parse_money($raw['purchase_cost'] ?? null);
        $price = inward_parse_money($raw['selling_price'] ?? null);
        if ($cost === null || $price === null) {
            return ['success' => false, 'error' => 'Purchase cost and selling price stay blank until you enter both for every line.'];
        }
        $resolved[] = [
            'id' => $lineId,
            'purchase_cost' => $cost,
            'selling_price' => $price,
        ];
    }

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    } else {
        $db->exec('SAVEPOINT inward_confirm_costs');
    }

    try {
        $stmt = $db->prepare('
            UPDATE inward_lines
            SET purchase_cost = :cost, selling_price = :price, cost_status = "confirmed"
            WHERE id = :id AND inward_entry_id = :eid
        ');
        foreach ($resolved as $line) {
            $stmt->execute([
                'cost' => $line['purchase_cost'],
                'price' => $line['selling_price'],
                'id' => $line['id'],
                'eid' => $entryId,
            ]);
        }
        $db->prepare('
            UPDATE inward_entries
            SET status = "confirmed", is_sellable = 0, updated_at = NOW()
            WHERE id = :id AND business_id = :bid AND status = "cost_pending"
        ')->execute(['id' => $entryId, 'bid' => $bid]);

        $postedVendorId = ($vendorId !== null && $vendorId > 0) ? $vendorId : null;
        if ($postedVendorId === null && trim((string) $vendorName) === '') {
            $postedVendorId = (int) ($entry['vendor_id'] ?? 0) ?: null;
        }
        $resolvedVendor = inward_resolve_vendor($postedVendorId, $vendorName, $bid, true);
        if (empty($resolvedVendor['success']) || (int) ($resolvedVendor['vendor_id'] ?? 0) <= 0) {
            throw new RuntimeException((string) ($resolvedVendor['error'] ?? 'Type the supplier name so the purchase bill can be created.'));
        }
        $billVendorId = (int) $resolvedVendor['vendor_id'];
        if ((int) ($entry['vendor_id'] ?? 0) !== $billVendorId) {
            $db->prepare('UPDATE inward_entries SET vendor_id = :vid WHERE id = :id AND business_id = :bid')
                ->execute(['vid' => $billVendorId, 'id' => $entryId, 'bid' => $bid]);
            $entry['vendor_id'] = $billVendorId;
        }

        $billLines = [];
        foreach ($entry['lines'] as $line) {
            $match = null;
            foreach ($resolved as $priced) {
                if ((int) $priced['id'] === (int) $line['id']) {
                    $match = $priced;
                    break;
                }
            }
            if ($match === null) {
                throw new RuntimeException('Enter purchase cost and selling price for every line.');
            }
            $billLines[] = [
                'product_id' => (int) $line['product_id'],
                'product_name' => (string) ($line['product_name'] ?? ''),
                'product_sku' => (string) ($line['product_sku'] ?? ''),
                'quantity' => (int) $line['quantity'],
                'size' => (string) $line['size'],
                'colour' => (string) $line['colour'],
                'purchase_cost' => $match['purchase_cost'],
            ];
        }
        $bill = inward_insert_purchase_bill($entry, $billLines, $bid, $userId);
        if (empty($bill['success'])) {
            throw new RuntimeException((string) ($bill['error'] ?? 'Could not create the purchase bill.'));
        }
        $db->prepare('UPDATE inward_entries SET bill_id = :bill WHERE id = :id AND business_id = :bid')
            ->execute(['bill' => (int) $bill['bill_id'], 'id' => $entryId, 'bid' => $bid]);

        if ($ownTx) {
            $db->commit();
        } else {
            $db->exec('RELEASE SAVEPOINT inward_confirm_costs');
        }
        return [
            'success' => true,
            'bill_id' => (int) $bill['bill_id'],
            'bill_number' => (string) $bill['bill_number'],
        ];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        } elseif ($db->inTransaction()) {
            $db->exec('ROLLBACK TO SAVEPOINT inward_confirm_costs');
        }
        $message = $e->getMessage();
        if ($message === '' || str_starts_with($message, 'SQLSTATE')) {
            $message = 'Could not confirm these costs.';
        }
        return ['success' => false, 'error' => $message];
    }
}

/**
 * Confirm the purchase and release one barcode per line for warehouse printing.
 * Cost confirmation does not call this. Stock and prices on the product stay unchanged.
 *
 * @return array{success:bool, error?:string}
 */
function confirm_inward_purchase(int $entryId, ?int $businessId = null): array {
    ensure_inward_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $entry = get_inward_entry_by_id($entryId, $bid);
    if (!$entry) {
        return ['success' => false, 'error' => 'That inward count was not found.'];
    }
    if ((string) ($entry['status'] ?? '') !== 'confirmed') {
        return ['success' => false, 'error' => 'Confirm the costs first. Confirming cost does not release a barcode.'];
    }
    if ((string) ($entry['purchase_status'] ?? 'pending') === 'confirmed') {
        return ['success' => false, 'error' => 'This purchase is already confirmed. Barcodes are ready to print.'];
    }
    if (empty($entry['lines'])) {
        return ['success' => false, 'error' => 'This count has no lines to barcode.'];
    }

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    } else {
        $db->exec('SAVEPOINT inward_confirm_purchase');
    }

    try {
        $locked = $db->prepare('
            SELECT status, purchase_status
            FROM inward_entries
            WHERE id = :id AND business_id = :bid
            FOR UPDATE
        ');
        $locked->execute(['id' => $entryId, 'bid' => $bid]);
        $row = $locked->fetch();
        if (!$row || (string) $row['status'] !== 'confirmed' || (string) $row['purchase_status'] !== 'pending') {
            throw new RuntimeException('This purchase is not waiting to be confirmed.');
        }

        $stmtLine = $db->prepare('
            UPDATE inward_lines SET barcode = :barcode
            WHERE id = :id AND inward_entry_id = :eid
        ');
        foreach ($entry['lines'] as $line) {
            $code = inward_release_line_barcode($db, $bid, $line);
            $stmtLine->execute([
                'barcode' => $code,
                'id' => (int) $line['id'],
                'eid' => $entryId,
            ]);
        }

        $updated = $db->prepare('
            UPDATE inward_entries
            SET purchase_status = "confirmed", is_sellable = 0, updated_at = NOW()
            WHERE id = :id AND business_id = :bid AND status = "confirmed" AND purchase_status = "pending"
        ');
        $updated->execute(['id' => $entryId, 'bid' => $bid]);
        if ($updated->rowCount() !== 1) {
            throw new RuntimeException('This purchase is not waiting to be confirmed.');
        }

        if ($ownTx) {
            $db->commit();
        } else {
            $db->exec('RELEASE SAVEPOINT inward_confirm_purchase');
        }
        return ['success' => true];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        } elseif ($db->inTransaction()) {
            $db->exec('ROLLBACK TO SAVEPOINT inward_confirm_purchase');
        }
        $message = $e->getMessage();
        if ($message === '' || str_starts_with($message, 'SQLSTATE')) {
            $message = 'Could not confirm this purchase.';
        }
        return ['success' => false, 'error' => $message];
    }
}

/**
 * Received quantity becomes company stock. Warehouse rows, including Central, stay unchanged.
 *
 * @param array<int, array<string, mixed>> $lines
 */
function inward_post_company_stock(PDO $db, int $businessId, int $entryId, string $entryNumber, array $lines, ?int $userId, ?int $warehouseId = null): void {
    $byProduct = [];
    foreach ($lines as $line) {
        $productId = (int) ($line['product_id'] ?? 0);
        $qty = (int) ($line['quantity'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            continue;
        }
        $byProduct[$productId] = ($byProduct[$productId] ?? 0) + $qty;
    }

    $stmtCur = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :bid FOR UPDATE');
    $stmtInc = $db->prepare('
        UPDATE products SET stock_quantity = stock_quantity + :qty, updated_at = NOW()
        WHERE id = :id AND business_id = :bid
    ');
    $stmtMov = $db->prepare('
        INSERT INTO inventory_movements (
            business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
        ) VALUES (
            :bid, :pid, :uid, "in", :change, :before, :after, :reason, NOW()
        )
    ');
    foreach ($byProduct as $productId => $qty) {
        $stmtCur->execute(['id' => $productId, 'bid' => $businessId]);
        $before = $stmtCur->fetchColumn();
        if ($before === false) {
            throw new RuntimeException('One of the products is not available for company stock.');
        }
        $beforeQty = (int) $before;
        $stmtInc->execute(['qty' => $qty, 'id' => $productId, 'bid' => $businessId]);
        $stmtMov->execute([
            'bid' => $businessId,
            'pid' => $productId,
            'uid' => ($userId !== null && $userId > 0) ? $userId : null,
            'change' => $qty,
            'before' => $beforeQty,
            'after' => $beforeQty + $qty,
            'reason' => 'Inward ' . $entryNumber . ' received as company stock',
        ]);
    }
    unset($entryId);
}

function inward_post_variant_stock(PDO $db, int $businessId, ?int $warehouseId, array $lines): void {
    $stmtVarCur = $db->prepare('SELECT stock_quantity FROM product_variants WHERE id = :id AND business_id = :bid FOR UPDATE');
    $stmtVarInc = $db->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + :qty, updated_at = NOW() WHERE id = :id AND business_id = :bid');

    $stmtWvs = null;
    if ($warehouseId !== null && $warehouseId > 0) {
        $stmtWvs = $db->prepare('
            INSERT INTO warehouse_variant_stock (warehouse_id, product_id, variant_id, stock_quantity, created_at, updated_at)
            VALUES (:wid, :pid, :varid, :qty, NOW(), NOW())
            ON DUPLICATE KEY UPDATE stock_quantity = stock_quantity + :qty_up, updated_at = NOW()
        ');
    }

    foreach ($lines as $line) {
        $variantId = (int) ($line['variant_id'] ?? 0);
        $productId = (int) ($line['product_id'] ?? 0);
        $qty = (int) ($line['quantity'] ?? 0);
        if ($variantId > 0 && $qty > 0) {
            $stmtVarCur->execute(['id' => $variantId, 'bid' => $businessId]);
            if ($stmtVarCur->fetchColumn() !== false) {
                $stmtVarInc->execute(['qty' => $qty, 'id' => $variantId, 'bid' => $businessId]);
            }
            if ($stmtWvs && $productId > 0) {
                $stmtWvs->execute([
                    'wid' => $warehouseId,
                    'pid' => $productId,
                    'varid' => $variantId,
                    'qty' => $qty,
                    'qty_up' => $qty,
                ]);
            }
        }
    }
}

function inward_central_warehouse_id(PDO $db, int $businessId): int {
    require_once __DIR__ . '/outlets_db.php';
    ensure_business_warehouse_baseline($businessId);
    $stmt = $db->prepare('
        SELECT id FROM warehouses
        WHERE business_id = :bid AND status = "active"
          AND (code = "WH-CENTRAL" OR name LIKE "%Central%")
        ORDER BY (code = "WH-CENTRAL") DESC, id ASC
        LIMIT 1
    ');
    $stmt->execute(['bid' => $businessId]);
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $code = 'WH-CENTRAL';
    $taken = $db->prepare('SELECT id FROM warehouses WHERE code = :code LIMIT 1');
    $taken->execute(['code' => $code]);
    if ($taken->fetchColumn()) {
        $code = 'WH-CENTRAL-' . $businessId;
    }
    $db->prepare('
        INSERT INTO warehouses (business_id, outlet_id, name, code, location, status, created_at, updated_at)
        VALUES (:bid, NULL, :name, :code, :loc, "active", NOW(), NOW())
    ')->execute([
        'bid' => $businessId,
        'name' => 'Central Warehouse',
        'code' => $code,
        'loc' => 'Main distribution hub',
    ]);
    return (int) $db->lastInsertId();
}

/**
 * Finish QC, checking, and tagging. There is no pass or fail.
 * Ready quantity is added to Central Warehouse only. Company stock is not increased again.
 *
 * @return array{success:bool, error?:string, warehouse_name?:string}
 */
function complete_inward_qc(int $entryId, ?int $businessId = null, ?int $userId = null): array {
    ensure_inward_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $entry = get_inward_entry_by_id($entryId, $bid);
    if (!$entry) {
        return ['success' => false, 'error' => 'That inward count was not found.'];
    }
    if ((string) ($entry['purchase_status'] ?? 'pending') !== 'confirmed') {
        return ['success' => false, 'error' => 'Confirm the purchase so barcodes can be printed before QC, checking, and tagging.'];
    }
    if ((string) ($entry['qc_status'] ?? 'pending') === 'done') {
        return ['success' => false, 'error' => 'QC, checking, and tagging is already complete. This stock is already ready in Central.'];
    }
    if (empty($entry['lines'])) {
        return ['success' => false, 'error' => 'This count has no lines to make ready.'];
    }

    $centralId = inward_central_warehouse_id($db, $bid);
    if ($centralId <= 0) {
        return ['success' => false, 'error' => 'Central Warehouse was not found. Ready stock can go there only.'];
    }

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    } else {
        $db->exec('SAVEPOINT inward_complete_qc');
    }

    try {
        $locked = $db->prepare('
            SELECT purchase_status, qc_status, company_stock_posted, entry_number
            FROM inward_entries
            WHERE id = :id AND business_id = :bid
            FOR UPDATE
        ');
        $locked->execute(['id' => $entryId, 'bid' => $bid]);
        $row = $locked->fetch();
        if (!$row || (string) $row['purchase_status'] !== 'confirmed' || (string) $row['qc_status'] !== 'pending') {
            throw new RuntimeException('This count is not waiting for QC, checking, and tagging.');
        }

        if ((int) ($row['company_stock_posted'] ?? 0) !== 1) {
            inward_post_company_stock($db, $bid, $entryId, (string) $row['entry_number'], $entry['lines'], $userId);
            $db->prepare('UPDATE inward_entries SET company_stock_posted = 1 WHERE id = :id AND business_id = :bid')
                ->execute(['id' => $entryId, 'bid' => $bid]);
        }

        $byProduct = [];
        foreach ($entry['lines'] as $line) {
            $productId = (int) $line['product_id'];
            $byProduct[$productId] = ($byProduct[$productId] ?? 0) + (int) $line['quantity'];
        }

        $stmtHave = $db->prepare('SELECT stock_quantity FROM warehouse_stock WHERE product_id = :pid AND warehouse_id = :wid LIMIT 1 FOR UPDATE');
        foreach ($byProduct as $productId => $qty) {
            $stmtHave->execute(['pid' => $productId, 'wid' => $centralId]);
            $have = $stmtHave->fetchColumn();
            $next = ($have === false ? 0 : (int) $have) + $qty;
            $db->prepare('
                INSERT INTO warehouse_stock (warehouse_id, product_id, stock_quantity, created_at, updated_at)
                VALUES (:wid, :pid, :qty, NOW(), NOW())
                ON DUPLICATE KEY UPDATE stock_quantity = :qty_up, updated_at = NOW()
            ')->execute([
                'wid' => $centralId,
                'pid' => $productId,
                'qty' => $next,
                'qty_up' => $next,
            ]);
        }

        $stmtWvsCentral = $db->prepare('
            INSERT INTO warehouse_variant_stock (warehouse_id, product_id, variant_id, stock_quantity, created_at, updated_at)
            VALUES (:wid, :pid, :varid, :qty, NOW(), NOW())
            ON DUPLICATE KEY UPDATE stock_quantity = stock_quantity + :qty_up, updated_at = NOW()
        ');
        foreach ($entry['lines'] as $line) {
            $pid = (int) $line['product_id'];
            $vid = (int) ($line['variant_id'] ?? 0);
            $qty = (int) $line['quantity'];
            if ($pid > 0 && $vid > 0 && $qty > 0) {
                $stmtWvsCentral->execute([
                    'wid' => $centralId,
                    'pid' => $pid,
                    'varid' => $vid,
                    'qty' => $qty,
                    'qty_up' => $qty,
                ]);
            }
        }

        $updated = $db->prepare('
            UPDATE inward_entries
            SET qc_status = "done", is_sellable = 1, updated_at = NOW()
            WHERE id = :id AND business_id = :bid AND qc_status = "pending" AND purchase_status = "confirmed"
        ');
        $updated->execute(['id' => $entryId, 'bid' => $bid]);
        if ($updated->rowCount() !== 1) {
            throw new RuntimeException('This count is not waiting for QC, checking, and tagging.');
        }

        if ($ownTx) {
            $db->commit();
        } else {
            $db->exec('RELEASE SAVEPOINT inward_complete_qc');
        }
        return ['success' => true, 'warehouse_name' => 'Central Warehouse'];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        } elseif ($db->inTransaction()) {
            $db->exec('ROLLBACK TO SAVEPOINT inward_complete_qc');
        }
        $message = $e->getMessage();
        if ($message === '' || str_starts_with($message, 'SQLSTATE')) {
            $message = 'Could not finish QC, checking, and tagging.';
        }
        return ['success' => false, 'error' => $message];
    }
}

function inward_barcode_taken(PDO $db, int $businessId, string $barcode): bool {
    $product = $db->prepare('SELECT id FROM products WHERE business_id = :bid AND barcode = :bc LIMIT 1');
    $product->execute(['bid' => $businessId, 'bc' => $barcode]);
    if ($product->fetchColumn()) {
        return true;
    }
    $variant = $db->prepare('SELECT id FROM product_variants WHERE barcode = :bc LIMIT 1');
    $variant->execute(['bc' => $barcode]);
    if ($variant->fetchColumn()) {
        return true;
    }
    $line = $db->prepare('
        SELECT il.id
        FROM inward_lines il
        INNER JOIN inward_entries ie ON ie.id = il.inward_entry_id
        WHERE ie.business_id = :bid AND il.barcode = :bc
        LIMIT 1
    ');
    $line->execute(['bid' => $businessId, 'bc' => $barcode]);
    return (bool) $line->fetchColumn();
}

function inward_allocate_barcode(PDO $db, int $businessId): string {
    for ($try = 0; $try < 12; $try++) {
        $code = '2'
            . str_pad((string) ($businessId % 10000), 4, '0', STR_PAD_LEFT)
            . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        if (!inward_barcode_taken($db, $businessId, $code)) {
            return $code;
        }
    }
    throw new RuntimeException('Could not release a barcode.');
}

/**
 * @param array<string, mixed> $line
 */
function inward_release_line_barcode(PDO $db, int $businessId, array $line): string {
    $variantId = (int) ($line['variant_id'] ?? 0);
    $productId = (int) ($line['product_id'] ?? 0);

    if ($variantId > 0) {
        $stmt = $db->prepare('
            SELECT barcode FROM product_variants
            WHERE id = :id AND product_id = :pid AND business_id = :bid
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute(['id' => $variantId, 'pid' => $productId, 'bid' => $businessId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false && trim((string) $existing) !== '') {
            return trim((string) $existing);
        }
        if ($existing !== false) {
            $code = inward_allocate_barcode($db, $businessId);
            $db->prepare('
                UPDATE product_variants
                SET barcode = :bc, updated_at = NOW()
                WHERE id = :id AND business_id = :bid AND (barcode IS NULL OR barcode = "")
            ')->execute(['bc' => $code, 'id' => $variantId, 'bid' => $businessId]);
            return $code;
        }
    }

    $stmt = $db->prepare('
        SELECT barcode FROM products
        WHERE id = :id AND business_id = :bid
        LIMIT 1
        FOR UPDATE
    ');
    $stmt->execute(['id' => $productId, 'bid' => $businessId]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false && trim((string) $existing) !== '') {
        return trim((string) $existing);
    }
    $code = inward_allocate_barcode($db, $businessId);
    if ($existing !== false) {
        $db->prepare('
            UPDATE products
            SET barcode = :bc, updated_at = NOW()
            WHERE id = :id AND business_id = :bid AND (barcode IS NULL OR barcode = "")
        ')->execute(['bc' => $code, 'id' => $productId, 'bid' => $businessId]);
    }
    return $code;
}

/**
 * @return array{success:bool, error?:string, entry?:array<string, mixed>, labels?:array<int, array<string, mixed>>}
 */
function get_inward_print_labels(int $entryId, ?int $businessId = null): array {
    $entry = get_inward_entry_by_id($entryId, $businessId);
    if (!$entry) {
        return ['success' => false, 'error' => 'That inward count was not found.'];
    }
    if ((string) ($entry['purchase_status'] ?? 'pending') !== 'confirmed') {
        return ['success' => false, 'error' => 'Confirming cost does not release a barcode. Confirm the purchase before the warehouse prints.'];
    }

    $labels = [];
    foreach ($entry['lines'] as $line) {
        $code = trim((string) ($line['barcode'] ?? ''));
        if ($code === '') {
            continue;
        }
        $copies = max(1, min(100, (int) $line['quantity']));
        $labels[] = [
            'name' => (string) ($line['product_name'] ?? 'Product'),
            'sku' => (string) ($line['product_sku'] ?? ''),
            'size' => (string) ($line['size'] ?? ''),
            'colour' => (string) ($line['colour'] ?? ''),
            'price' => $line['selling_price'],
            'barcode' => $code,
            'copies' => $copies,
        ];
    }
    if ($labels === []) {
        return ['success' => false, 'error' => 'This purchase has no barcode ready to print.'];
    }
    return ['success' => true, 'entry' => $entry, 'labels' => $labels];
}

function inward_insert_purchase_bill(array $entry, array $lines, int $businessId, ?int $userId): array {
    require_once __DIR__ . '/purchases_db.php';
    $db = get_db();
    $items = [];
    foreach ($lines as $line) {
        $tax = 0.0;
        $productId = (int) ($line['product_id'] ?? 0);
        if ($productId > 0) {
            $stmt = $db->prepare('SELECT tax_percent FROM products WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmt->execute(['id' => $productId, 'bid' => $businessId]);
            $tax = (float) $stmt->fetchColumn();
        }
        $name = trim((string) ($line['product_name'] ?? 'Product'));
        $size = trim((string) ($line['size'] ?? ''));
        $colour = trim((string) ($line['colour'] ?? ''));
        if ($size !== '' || $colour !== '') {
            $name .= ' (' . trim($size . ' / ' . $colour, ' /') . ')';
        }
        $items[] = [
            'product_id' => $productId,
            'product_name' => $name,
            'product_sku' => (string) ($line['product_sku'] ?? ''),
            'quantity' => (int) ($line['quantity'] ?? 0),
            'unit_cost' => (float) ($line['purchase_cost'] ?? 0),
            'tax_percent' => $tax,
        ];
    }
    return create_purchase_bill([
        'vendor_id' => (int) ($entry['vendor_id'] ?? 0),
        'bill_date' => (string) ($entry['entry_date'] ?? date('Y-m-d')),
        'reference_number' => (string) ($entry['entry_number'] ?? ''),
        'location_name' => (string) ($entry['warehouse_name'] ?? 'Warehouse'),
        'notes' => 'Purchase bill for inward ' . (string) ($entry['entry_number'] ?? ''),
        'items' => $items,
    ], $userId, $businessId);
}

/**
 * Create the purchase bill for a count whose costs are already confirmed.
 *
 * @return array{success:bool, error?:string, bill_id?:int, bill_number?:string}
 */
function create_inward_purchase_bill(int $entryId, ?int $vendorId = null, ?int $userId = null, ?int $businessId = null, ?string $vendorName = null): array {
    ensure_inward_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $entry = get_inward_entry_by_id($entryId, $bid);
    if (!$entry) {
        return ['success' => false, 'error' => 'That inward count was not found.'];
    }
    if ((string) $entry['status'] !== 'confirmed') {
        return ['success' => false, 'error' => 'Confirm purchase cost and selling price before creating the bill.'];
    }
    if ((int) ($entry['bill_id'] ?? 0) > 0) {
        return ['success' => false, 'error' => 'A bill is already linked to this count.'];
    }
    $postedVendorId = ($vendorId !== null && $vendorId > 0) ? $vendorId : null;
    if ($postedVendorId === null && trim((string) $vendorName) === '') {
        $postedVendorId = (int) ($entry['vendor_id'] ?? 0) ?: null;
    }
    $resolvedVendor = inward_resolve_vendor($postedVendorId, $vendorName, $bid, true);
    if (empty($resolvedVendor['success']) || (int) ($resolvedVendor['vendor_id'] ?? 0) <= 0) {
        return ['success' => false, 'error' => (string) ($resolvedVendor['error'] ?? 'Type the supplier name so the purchase bill can be created.')];
    }
    $entry['vendor_id'] = (int) $resolvedVendor['vendor_id'];
    $lines = [];
    foreach ($entry['lines'] as $line) {
        if ($line['purchase_cost'] === null) {
            return ['success' => false, 'error' => 'Purchase cost is still blank on a line.'];
        }
        $lines[] = $line;
    }

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }
    try {
        $db->prepare('UPDATE inward_entries SET vendor_id = :vid WHERE id = :id AND business_id = :bid')
            ->execute(['vid' => (int) $entry['vendor_id'], 'id' => $entryId, 'bid' => $bid]);
        $bill = inward_insert_purchase_bill($entry, $lines, $bid, $userId);
        if (empty($bill['success'])) {
            throw new RuntimeException((string) ($bill['error'] ?? 'Could not create the purchase bill.'));
        }
        $db->prepare('UPDATE inward_entries SET bill_id = :bill WHERE id = :id AND business_id = :bid')
            ->execute(['bill' => (int) $bill['bill_id'], 'id' => $entryId, 'bid' => $bid]);
        if ($ownTx) {
            $db->commit();
        }
        return ['success' => true, 'bill_id' => (int) $bill['bill_id'], 'bill_number' => (string) $bill['bill_number']];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Could not create the purchase bill.'];
    }
}

function inward_parse_money(mixed $value): ?string {
    if ($value === null) {
        return null;
    }
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
        return null;
    }
    $amount = (float) $text;
    if ($amount < 0 || $amount > 99999999.99) {
        return null;
    }
    return number_format($amount, 2, '.', '');
}

/**
 * @param array<int, array<string, mixed>> $lines
 * @return array{success:bool, error?:string, lines?:array<int, array{product_id:int, variant_id:?int, size:string, colour:string, quantity:int}>}
 */
function inward_resolve_lines(array $lines, int $businessId): array {
    require_once __DIR__ . '/orders_db.php';
    if ($lines === []) {
        return ['success' => false, 'error' => 'Add at least one product, size, colour, and quantity.'];
    }

    $db = get_db();
    $merged = [];

    foreach ($lines as $raw) {
        $productId = (int) ($raw['product_id'] ?? 0);
        $size = trim((string) ($raw['size'] ?? ''));
        $colour = trim((string) ($raw['colour'] ?? $raw['color'] ?? ''));
        if ($colour === '') {
            $colour = 'Standard';
        }
        $qty = (int) ($raw['quantity'] ?? 0);

        if ($productId <= 0) {
            return ['success' => false, 'error' => 'Each line needs a product.'];
        }
        if ($size === '') {
            return ['success' => false, 'error' => 'Each line needs a size.'];
        }

        if (strlen($size) > 80 || strlen($colour) > 80) {
            return ['success' => false, 'error' => 'Size and colour must be 80 characters or less.'];
        }
        if ($qty < 1) {
            return ['success' => false, 'error' => 'Quantity must be at least 1.'];
        }
        if ($qty > 1000000) {
            return ['success' => false, 'error' => 'Quantity is too large.'];
        }

        $stmtP = $db->prepare('SELECT id, name FROM products WHERE id = :id AND business_id = :bid AND status = "active" LIMIT 1');
        $stmtP->execute(['id' => $productId, 'bid' => $businessId]);
        $product = $stmtP->fetch();
        if (!$product) {
            return ['success' => false, 'error' => 'One of the products is not active.'];
        }

        $stmtVars = $db->prepare('
            SELECT id, variant_name, attribute_values, sku
            FROM product_variants
            WHERE product_id = :pid AND business_id = :bid AND status = "active"
        ');
        $stmtVars->execute(['pid' => $productId, 'bid' => $businessId]);
        $variantId = null;
        $matched = false;
        $hasSizeColour = false;
        $lineSku = (string)($product['sku'] ?? '');
        foreach ($stmtVars->fetchAll() as $variant) {
            $attrs = parse_variant_size_colour($variant);
            if ($attrs['size'] === '' && $attrs['colour'] === '') {
                continue;
            }
            $hasSizeColour = true;
            $vSize = $attrs['size'] !== '' ? $attrs['size'] : 'Standard';
            $vColour = $attrs['colour'] !== '' ? $attrs['colour'] : 'Standard';
            if (strcasecmp($vSize, $size) === 0 && strcasecmp($vColour, $colour) === 0) {
                $variantId = (int) $variant['id'];
                $size = $vSize;
                $colour = $vColour;
                $lineSku = (string)($variant['sku'] ?? $lineSku);
                $matched = true;
                break;
            }
        }

        // If not matched to existing variant, auto-create variant with 3-tier SKU & barcode
        if (!$matched && $size !== '') {
            $parentSku = trim((string)($product['sku'] ?? ''));
            if ($parentSku === '') {
                $parentSku = 'P' . $productId;
            }
            $cCode = function_exists('get_standard_color_code') ? get_standard_color_code($colour) : 'STD';
            $videoSku = function_exists('build_video_sku') ? build_video_sku($parentSku, $colour, $cCode) : ($parentSku . '-' . $cCode);
            $invSku = function_exists('build_variant_inventory_sku') ? build_variant_inventory_sku($parentSku, $colour, $size, $cCode) : ($videoSku . '-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $size)));
            $barcode = inward_allocate_barcode($db, $businessId);
            $varName = $colour . ' / ' . $size;
            $attrValues = json_encode(['Colour' => $colour, 'Size' => $size], JSON_UNESCAPED_UNICODE);

            $stmtInsVar = $db->prepare('
                INSERT INTO product_variants (
                    business_id, product_id, variant_name, attribute_values,
                    sku, video_sku, color_code, barcode,
                    cost_price, selling_price, stock_quantity, status, created_at, updated_at
                ) VALUES (
                    :bid, :pid, :vname, :av,
                    :sku, :vsku, :ccode, :bc,
                    :cp, :sp, 0, "active", NOW(), NOW()
                )
            ');
            $stmtInsVar->execute([
                'bid' => $businessId,
                'pid' => $productId,
                'vname' => $varName,
                'av' => $attrValues,
                'sku' => $invSku,
                'vsku' => $videoSku,
                'ccode' => $cCode,
                'bc' => $barcode,
                'cp' => (float)($product['cost_price'] ?? 0),
                'sp' => (float)($product['selling_price'] ?? 0),
            ]);
            $variantId = (int)$db->lastInsertId();
            $lineSku = $invSku;
        }

        $key = $productId . '|' . strtolower($size) . '|' . strtolower($colour);
        if (!isset($merged[$key])) {
            $merged[$key] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'sku' => $lineSku,
                'size' => $size,
                'colour' => $colour,
                'quantity' => 0,
            ];
        }
        $merged[$key]['quantity'] += $qty;
        if ($merged[$key]['quantity'] > 1000000) {
            return ['success' => false, 'error' => 'Quantity is too large.'];
        }
    }

    return ['success' => true, 'lines' => array_values($merged)];
}

function inward_next_number(PDO $db, int $businessId): string {
    $prefix = 'INW-' . date('Ymd') . '-';
    $stmt = $db->prepare('
        SELECT entry_number FROM inward_entries
        WHERE business_id = :bid AND entry_number LIKE :pfx
        ORDER BY id DESC
        LIMIT 30
    ');
    $stmt->execute(['bid' => $businessId, 'pfx' => $prefix . '%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $num) {
        $parts = explode('-', (string) $num);
        $max = max($max, (int) end($parts));
    }
    $seq = $max + 1;
    $chk = $db->prepare('SELECT id FROM inward_entries WHERE business_id = :bid AND entry_number = :num LIMIT 1');
    do {
        $candidate = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        $chk->execute(['bid' => $businessId, 'num' => $candidate]);
        $exists = (int) $chk->fetchColumn() > 0;
        if ($exists) {
            $seq++;
        }
    } while ($exists);
    return $candidate;
}

function get_inward_entries(string $search = '', int $limit = 50, ?int $businessId = null): array {
    ensure_inward_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $limit = max(1, min(200, $limit));
    $sql = '
        SELECT ie.*,
               w.name AS warehouse_name,
               v.name AS vendor_name,
               u.name AS counted_by_name,
               pb.bill_number,
               (SELECT COUNT(*) FROM inward_lines il WHERE il.inward_entry_id = ie.id) AS line_count,
               (SELECT COALESCE(SUM(il.quantity), 0) FROM inward_lines il WHERE il.inward_entry_id = ie.id) AS total_qty
        FROM inward_entries ie
        LEFT JOIN warehouses w ON w.id = ie.warehouse_id AND w.business_id = ie.business_id
        LEFT JOIN vendors v ON v.id = ie.vendor_id AND v.business_id = ie.business_id
        LEFT JOIN users u ON u.id = ie.user_id
        LEFT JOIN purchase_bills pb ON pb.id = ie.bill_id AND pb.business_id = ie.business_id
        WHERE ie.business_id = :bid
    ';
    $params = ['bid' => $bid];
    if ($search !== '') {
        $sql .= ' AND (ie.entry_number LIKE :s1 OR w.name LIKE :s2 OR v.name LIKE :s3 OR ie.notes LIKE :s4 OR ie.supplier_reference LIKE :s5)';
        $params['s1'] = '%' . $search . '%';
        $params['s2'] = '%' . $search . '%';
        $params['s3'] = '%' . $search . '%';
        $params['s4'] = '%' . $search . '%';
        $params['s5'] = '%' . $search . '%';
    }
    $sql .= ' ORDER BY ie.id DESC LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_inward_entry_by_id(int $id, ?int $businessId = null): ?array {
    ensure_inward_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT ie.*,
               w.name AS warehouse_name,
               v.name AS vendor_name,
               u.name AS counted_by_name,
               pb.bill_number
        FROM inward_entries ie
        LEFT JOIN warehouses w ON w.id = ie.warehouse_id AND w.business_id = ie.business_id
        LEFT JOIN vendors v ON v.id = ie.vendor_id AND v.business_id = ie.business_id
        LEFT JOIN users u ON u.id = ie.user_id
        LEFT JOIN purchase_bills pb ON pb.id = ie.bill_id AND pb.business_id = ie.business_id
        WHERE ie.id = :id AND ie.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid]);
    $entry = $stmt->fetch();
    if (!$entry) {
        return null;
    }

    $stmtLines = $db->prepare('
        SELECT il.*, p.name AS product_name, p.sku AS product_sku
        FROM inward_lines il
        INNER JOIN products p ON p.id = il.product_id AND p.business_id = :bid
        WHERE il.inward_entry_id = :id
        ORDER BY il.id ASC
    ');
    $stmtLines->execute(['id' => $id, 'bid' => $bid]);
    $entry['lines'] = $stmtLines->fetchAll();
    return $entry;
}

/**
 * Ensure variants exist for a parent design product following the 3-tier SKU convention:
 * - Parent SKU: AC125
 * - Video SKU: AC125-MRN (lookup alias only)
 * - Inventory SKU: AC125-MRN-L (stock-bearing variant)
 *
 * @param int $productId
 * @param array<int, array{colour:string, colour_code?:string, video_sku?:string, cost_price?:float, selling_price?:float}> $colourDefs
 * @param array<int, string> $sizes
 * @param int $businessId
 * @return array<string, array<string, array<string, mixed>>> [colour_norm][size_norm] => variant
 */
function ensure_matrix_variants(
    int $productId,
    array $colourDefs,
    array $sizes,
    int $businessId,
    ?float $defaultCost = null,
    ?float $defaultSelling = null
): array {
    ensure_inward_schema();
    $db = get_db();

    $stmtP = $db->prepare('SELECT id, name, sku, cost_price, selling_price FROM products WHERE id = :pid AND business_id = :bid LIMIT 1');
    $stmtP->execute(['pid' => $productId, 'bid' => $businessId]);
    $product = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new RuntimeException("Product ID {$productId} not found for business {$businessId}.");
    }
    $parentSku = trim((string)($product['sku'] ?? ''));
    if ($parentSku === '') {
        $parentSku = 'P' . $productId;
        $db->prepare('UPDATE products SET sku = :sku WHERE id = :id')->execute(['sku' => $parentSku, 'id' => $productId]);
    }
    $parentCost = (float)($product['cost_price'] ?? 0);
    $parentSelling = (float)($product['selling_price'] ?? 0);

    // Get existing variants
    $stmtV = $db->prepare('SELECT * FROM product_variants WHERE product_id = :pid AND business_id = :bid');
    $stmtV->execute(['pid' => $productId, 'bid' => $businessId]);
    $existing = $stmtV->fetchAll(PDO::FETCH_ASSOC);

    $matrix = [];
    foreach ($existing as $ev) {
        $attrs = parse_variant_size_colour($ev);
        $cNorm = strtolower(trim($attrs['colour']));
        $sNorm = strtolower(trim($attrs['size']));
        if ($cNorm !== '' && $sNorm !== '') {
            $matrix[$cNorm][$sNorm] = $ev;
        }
    }

    foreach ($colourDefs as $cDef) {
        $colourName = trim((string)($cDef['colour'] ?? ''));
        if ($colourName === '') continue;
        $cCode = !empty($cDef['colour_code']) ? trim((string)$cDef['colour_code']) : get_standard_color_code($colourName);
        $vSku = !empty($cDef['video_sku']) ? trim((string)$cDef['video_sku']) : build_video_sku($parentSku, $colourName, $cCode);
        $rowCost = isset($cDef['cost_price']) && $cDef['cost_price'] !== '' ? (float)$cDef['cost_price'] : ($defaultCost ?? $parentCost);
        $rowSelling = isset($cDef['selling_price']) && $cDef['selling_price'] !== '' ? (float)$cDef['selling_price'] : ($defaultSelling ?? $parentSelling);

        $cNorm = strtolower($colourName);
        if (!isset($matrix[$cNorm])) {
            $matrix[$cNorm] = [];
        }

        foreach ($sizes as $sizeName) {
            $sizeName = trim((string)$sizeName);
            if ($sizeName === '') continue;
            $sNorm = strtolower($sizeName);

            if (isset($matrix[$cNorm][$sNorm])) {
                $ev = $matrix[$cNorm][$sNorm];
                if (empty($ev['video_sku'])) {
                    $db->prepare('UPDATE product_variants SET video_sku = :vsku, color_code = :cc WHERE id = :id')
                        ->execute(['vsku' => $vSku, 'cc' => $cCode, 'id' => (int)$ev['id']]);
                    $matrix[$cNorm][$sNorm]['video_sku'] = $vSku;
                    $matrix[$cNorm][$sNorm]['color_code'] = $cCode;
                }
                continue;
            }

            $invSku = build_variant_inventory_sku($parentSku, $colourName, $sizeName, $cCode);
            $chkSku = $db->prepare('SELECT COUNT(*) FROM product_variants WHERE sku = :sku');
            $chkSku->execute(['sku' => $invSku]);
            if ((int)$chkSku->fetchColumn() > 0) {
                $invSku .= '-' . substr(uniqid(), -4);
            }

            $barcode = inward_allocate_barcode($db, $businessId);
            $varName = $colourName . ' / ' . $sizeName;
            $attrValues = json_encode(['Colour' => $colourName, 'Size' => $sizeName], JSON_UNESCAPED_UNICODE);

            $stmtIns = $db->prepare('
                INSERT INTO product_variants (
                    business_id, product_id, variant_name, attribute_values,
                    sku, video_sku, color_code, barcode,
                    cost_price, selling_price, stock_quantity, status, created_at, updated_at
                ) VALUES (
                    :bid, :pid, :vname, :av,
                    :sku, :vsku, :ccode, :bc,
                    :cp, :sp, 0, "active", NOW(), NOW()
                )
            ');
            $stmtIns->execute([
                'bid' => $businessId,
                'pid' => $productId,
                'vname' => $varName,
                'av' => $attrValues,
                'sku' => $invSku,
                'vsku' => $vSku,
                'ccode' => $cCode,
                'bc' => $barcode,
                'cp' => $rowCost,
                'sp' => $rowSelling,
            ]);
            $newId = (int)$db->lastInsertId();

            $newRow = [
                'id' => $newId,
                'business_id' => $businessId,
                'product_id' => $productId,
                'variant_name' => $varName,
                'attribute_values' => $attrValues,
                'sku' => $invSku,
                'video_sku' => $vSku,
                'color_code' => $cCode,
                'barcode' => $barcode,
                'cost_price' => $rowCost,
                'selling_price' => $rowSelling,
                'stock_quantity' => 0,
                'status' => 'active',
            ];
            $matrix[$cNorm][$sNorm] = $newRow;
        }
    }

    if (!empty($matrix)) {
        $db->prepare('UPDATE products SET product_type = "variable", updated_at = NOW() WHERE id = :pid AND business_id = :bid')
            ->execute(['pid' => $productId, 'bid' => $businessId]);
    }

    return $matrix;
}

/**
 * Save an inward count entered via the Size x Colour Matrix screen.
 *
 * @param int $warehouseId
 * @param array<string, mixed> $matrixPayload
 * @param string $entryDate
 * @param string $supplierReference
 * @param string $notes
 * @param int|null $vendorId
 * @param string|null $vendorName
 * @param int|null $userId
 * @param int|null $businessId
 * @return array{success:bool, error?:string, entry_id?:int, entry_number?:string, total_qty?:int, lines_count?:int, duplicate_entry_id?:int, duplicate_entry_number?:string}
 */
function create_inward_matrix_entry(
    int $warehouseId,
    array $matrixPayload,
    string $entryDate = '',
    string $supplierReference = '',
    string $notes = '',
    ?int $vendorId = null,
    ?string $vendorName = null,
    ?int $userId = null,
    ?int $businessId = null
): array {
    ensure_inward_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    if ($warehouseId <= 0) {
        return ['success' => false, 'error' => 'Choose the warehouse that was counted.'];
    }

    $supplierReference = trim($supplierReference);
    if ($supplierReference !== '') {
        $chkRef = $db->prepare('
            SELECT id, entry_number, entry_date
            FROM inward_entries
            WHERE business_id = :bid AND supplier_reference = :ref
            LIMIT 1
        ');
        $chkRef->execute(['bid' => $bid, 'ref' => $supplierReference]);
        $existingDoc = $chkRef->fetch(PDO::FETCH_ASSOC);
        if ($existingDoc) {
            return [
                'success' => false,
                'error' => "Supplier document reference '{$supplierReference}' has already been inwarded under entry #{$existingDoc['entry_number']} on " . date('d M Y', strtotime($existingDoc['entry_date'])) . ". Double posting prevented.",
                'duplicate_entry_id' => (int)$existingDoc['id'],
                'duplicate_entry_number' => $existingDoc['entry_number'],
            ];
        }
    }

    $resolvedVendor = inward_resolve_vendor($vendorId, $vendorName, $bid, false);
    if (!$resolvedVendor['success']) {
        return ['success' => false, 'error' => $resolvedVendor['error'] ?? 'Could not resolve supplier.'];
    }
    $vendorId = $resolvedVendor['vendor_id'];

    $productId = (int)($matrixPayload['product_id'] ?? 0);
    if ($productId <= 0) {
        return ['success' => false, 'error' => 'Please select or create a design product for the matrix.'];
    }

    $sizes = (array)($matrixPayload['sizes'] ?? []);
    $rows = (array)($matrixPayload['rows'] ?? []);
    if (empty($sizes) || empty($rows)) {
        return ['success' => false, 'error' => 'Matrix must have at least one size column and one colour row.'];
    }

    $colourDefs = [];
    foreach ($rows as $r) {
        $colourDefs[] = [
            'colour' => $r['colour'] ?? '',
            'colour_code' => $r['colour_code'] ?? null,
            'video_sku' => $r['video_sku'] ?? null,
            'cost_price' => isset($r['cost_price']) && $r['cost_price'] !== '' ? (float)$r['cost_price'] : null,
            'selling_price' => isset($r['selling_price']) && $r['selling_price'] !== '' ? (float)$r['selling_price'] : null,
        ];
    }
    $variantMatrix = ensure_matrix_variants($productId, $colourDefs, $sizes, $bid);

    $lines = [];
    $totalQty = 0;
    foreach ($rows as $r) {
        $cName = trim((string)($r['colour'] ?? ''));
        $cNorm = strtolower($cName);
        $cost = isset($r['cost_price']) && $r['cost_price'] !== '' ? (float)$r['cost_price'] : null;
        $selling = isset($r['selling_price']) && $r['selling_price'] !== '' ? (float)$r['selling_price'] : null;
        $cellQtys = (array)($r['quantities'] ?? []);

        foreach ($sizes as $sName) {
            $sNorm = strtolower(trim((string)$sName));
            $qty = isset($cellQtys[$sName]) ? (int)$cellQtys[$sName] : (isset($cellQtys[$sNorm]) ? (int)$cellQtys[$sNorm] : 0);
            if ($qty > 0) {
                $varRow = $variantMatrix[$cNorm][$sNorm] ?? null;
                $lines[] = [
                    'product_id' => $productId,
                    'variant_id' => $varRow ? (int)$varRow['id'] : null,
                    'sku' => $varRow ? (string)$varRow['sku'] : null,
                    'size' => $sName,
                    'colour' => $cName,
                    'quantity' => $qty,
                    'purchase_cost' => $cost,
                    'selling_price' => $selling,
                ];
                $totalQty += $qty;
            }
        }
    }

    if (empty($lines)) {
        return ['success' => false, 'error' => 'Matrix has no quantities entered. Enter quantities for at least one size/colour cell.'];
    }

    $entryDate = trim($entryDate) ?: date('Y-m-d');

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }

    try {
        $entryNumber = inward_next_number($db, $bid);
        $stmtEntry = $db->prepare('
            INSERT INTO inward_entries (
                business_id, entry_number, warehouse_id, vendor_id, supplier_reference, user_id,
                entry_date, status, purchase_status, is_sellable, notes, inward_mode, created_at, updated_at
            ) VALUES (
                :bid, :num, :wid, :vid, :ref, :uid,
                :entry_date, "cost_pending", "pending", 0, :notes, "matrix", NOW(), NOW()
            )
        ');
        $stmtEntry->execute([
            'bid' => $bid,
            'num' => $entryNumber,
            'wid' => $warehouseId,
            'vid' => $vendorId,
            'ref' => $supplierReference !== '' ? $supplierReference : null,
            'uid' => $userId,
            'entry_date' => $entryDate,
            'notes' => $notes !== '' ? $notes : null,
        ]);
        $entryId = (int)$db->lastInsertId();

        $stmtLine = $db->prepare('
            INSERT INTO inward_lines (
                inward_entry_id, product_id, variant_id, sku, size, colour, quantity,
                purchase_cost, selling_price, cost_status, barcode, created_at
            ) VALUES (
                :eid, :pid, :varid, :sku, :size, :colour, :qty,
                :cost, :price, :cost_status, :barcode, NOW()
            )
        ');

        foreach ($lines as $line) {
            $costStatus = ($line['purchase_cost'] !== null && $line['selling_price'] !== null) ? 'confirmed' : 'pending';
            $stmtLine->execute([
                'eid' => $entryId,
                'pid' => $line['product_id'],
                'varid' => $line['variant_id'],
                'sku' => $line['sku'],
                'size' => $line['size'],
                'colour' => $line['colour'],
                'qty' => $line['quantity'],
                'cost' => $line['purchase_cost'],
                'price' => $line['selling_price'],
                'cost_status' => $costStatus,
                'barcode' => null,
            ]);
        }

        inward_post_company_stock($db, $bid, $entryId, $entryNumber, $lines, $userId, $warehouseId);
        inward_post_variant_stock($db, $bid, $warehouseId, $lines);
        $db->prepare('UPDATE inward_entries SET company_stock_posted = 1 WHERE id = :id AND business_id = :bid')
            ->execute(['id' => $entryId, 'bid' => $bid]);

        if ($ownTx) {
            $db->commit();
        }

        return [
            'success' => true,
            'entry_id' => $entryId,
            'entry_number' => $entryNumber,
            'total_qty' => $totalQty,
            'lines_count' => count($lines),
        ];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => 'Could not save the matrix inward entry: ' . $e->getMessage()];
    }
}

/**
 * Import bulk matrix inward entries from CSV.
 * Supports format:
 * Supplier, Receipt Date, Warehouse, Design Code, Colour, Unit Cost, S, M, L, XL, XXL...
 *
 * @return array{success:bool, error?:string, entry_id?:int, entry_number?:string, total_qty?:int, rows_imported?:int}
 */
function import_inward_matrix_csv(
    string $csvContent,
    int $defaultWarehouseId,
    ?int $defaultVendorId = null,
    string $defaultDate = '',
    string $supplierReference = '',
    ?int $userId = null,
    ?int $businessId = null
): array {
    ensure_inward_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $supplierReference = trim($supplierReference);
    if ($supplierReference !== '') {
        $chkRef = $db->prepare('SELECT id, entry_number, entry_date FROM inward_entries WHERE business_id = :bid AND supplier_reference = :ref LIMIT 1');
        $chkRef->execute(['bid' => $bid, 'ref' => $supplierReference]);
        $existingDoc = $chkRef->fetch(PDO::FETCH_ASSOC);
        if ($existingDoc) {
            return [
                'success' => false,
                'error' => "Supplier document reference '{$supplierReference}' has already been imported under inward entry #{$existingDoc['entry_number']} on " . date('d M Y', strtotime($existingDoc['entry_date'])) . ". Duplicate import blocked to prevent double stock.",
                'duplicate_entry_id' => (int)$existingDoc['id'],
                'duplicate_entry_number' => $existingDoc['entry_number'],
            ];
        }
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
    if (empty($lines) || count($lines) < 2) {
        return ['success' => false, 'error' => 'The CSV file is empty or missing data rows.'];
    }

    $rawHeader = str_getcsv(array_shift($lines));
    $headers = array_map(function ($h) {
        return strtolower(trim(preg_replace('/[\x{FEFF}]/u', '', (string)$h)));
    }, $rawHeader);

    // Map column indexes
    $colMap = [
        'supplier' => -1,
        'date' => -1,
        'warehouse' => -1,
        'design_code' => -1,
        'colour' => -1,
        'cost' => -1,
        'reference' => -1,
    ];

    $sizeCols = []; // colIndex => sizeLabel
    foreach ($headers as $idx => $h) {
        if (in_array($h, ['supplier', 'vendor', 'vendor_name', 'supplier_name', 'supplier name'], true)) {
            $colMap['supplier'] = $idx;
        } elseif (in_array($h, ['receipt date', 'receipt_date', 'date', 'entry_date', 'entry date'], true)) {
            $colMap['date'] = $idx;
        } elseif (in_array($h, ['warehouse', 'warehouse_name', 'warehouse name', 'location'], true)) {
            $colMap['warehouse'] = $idx;
        } elseif (in_array($h, ['design code', 'design_code', 'design', 'sku', 'product_sku', 'product sku', 'product_code', 'product code', 'parent_sku', 'parent sku'], true)) {
            $colMap['design_code'] = $idx;
        } elseif (in_array($h, ['colour', 'color', 'shade'], true)) {
            $colMap['colour'] = $idx;
        } elseif (in_array($h, ['unit cost', 'unit_cost', 'cost', 'cost_price', 'cost price', 'purchase_cost', 'purchase cost'], true)) {
            $colMap['cost'] = $idx;
        } elseif (in_array($h, ['reference', 'supplier reference', 'supplier_reference', 'supplier ref', 'supplier_ref', 'invoice_no', 'invoice no', 'bill_no', 'bill no', 'doc_ref', 'doc ref', 'document reference', 'invoice ref'], true)) {
            $colMap['reference'] = $idx;
        } else {
            // Treat as size column
            $sizeLabel = trim($rawHeader[$idx]);
            if ($sizeLabel !== '') {
                $sizeCols[$idx] = $sizeLabel;
            }
        }
    }

    if ($colMap['design_code'] === -1) {
        return ['success' => false, 'error' => 'Missing "Design Code" column in CSV header.'];
    }
    if ($colMap['colour'] === -1) {
        return ['success' => false, 'error' => 'Missing "Colour" column in CSV header.'];
    }
    if (empty($sizeCols)) {
        return ['success' => false, 'error' => 'No size columns found in CSV (e.g. S, M, L, XL, XXL).'];
    }

    $sizes = array_values($sizeCols);

    // Group rows by design code
    $grouped = [];
    $detectedSupplier = null;
    $detectedWarehouse = null;
    $detectedDate = null;
    $detectedRef = null;

    foreach ($lines as $lineNum => $lineStr) {
        if (trim($lineStr) === '') continue;
        $row = str_getcsv($lineStr);
        $dCode = strtoupper(trim((string)($row[$colMap['design_code']] ?? '')));
        $colour = trim((string)($row[$colMap['colour']] ?? ''));
        if ($dCode === '' || $colour === '') continue;

        $cost = $colMap['cost'] !== -1 ? (float)($row[$colMap['cost']] ?? 0) : null;
        if ($colMap['supplier'] !== -1 && trim((string)($row[$colMap['supplier']] ?? '')) !== '') {
            $detectedSupplier = trim((string)$row[$colMap['supplier']]);
        }
        if ($colMap['warehouse'] !== -1 && trim((string)($row[$colMap['warehouse']] ?? '')) !== '') {
            $detectedWarehouse = trim((string)$row[$colMap['warehouse']]);
        }
        if ($colMap['date'] !== -1 && trim((string)($row[$colMap['date']] ?? '')) !== '') {
            $detectedDate = trim((string)$row[$colMap['date']]);
        }
        if ($colMap['reference'] !== -1 && trim((string)($row[$colMap['reference']] ?? '')) !== '') {
            $detectedRef = trim((string)$row[$colMap['reference']]);
        }

        $qtys = [];
        foreach ($sizeCols as $cIdx => $sLabel) {
            $qtys[$sLabel] = max(0, (int)($row[$cIdx] ?? 0));
        }

        if (!isset($grouped[$dCode])) {
            $grouped[$dCode] = [];
        }
        $grouped[$dCode][] = [
            'colour' => $colour,
            'cost_price' => $cost,
            'quantities' => $qtys,
        ];
    }

    if (empty($grouped)) {
        return ['success' => false, 'error' => 'No valid data rows found in CSV.'];
    }

    // Resolve warehouse
    $whId = $defaultWarehouseId;
    if ($detectedWarehouse) {
        $stmtWh = $db->prepare('SELECT id FROM warehouses WHERE business_id = :bid AND (name = :name OR code = :code) AND status = "active" LIMIT 1');
        $stmtWh->execute(['bid' => $bid, 'name' => $detectedWarehouse, 'code' => $detectedWarehouse]);
        $whRow = $stmtWh->fetchColumn();
        if ($whRow) {
            $whId = (int)$whRow;
        }
    }
    if ($whId <= 0) {
        return ['success' => false, 'error' => 'Please choose a warehouse for the imported inward entry.'];
    }

    // Resolve supplier
    $vId = $defaultVendorId;
    $vName = $detectedSupplier;
    if ($vName) {
        $vRes = inward_resolve_vendor(null, $vName, $bid, false);
        if (!empty($vRes['vendor_id'])) {
            $vId = (int)$vRes['vendor_id'];
        }
    }

    $ref = $detectedRef ?: $supplierReference;
    if ($ref !== '') {
        $chkRef = $db->prepare('SELECT id, entry_number FROM inward_entries WHERE business_id = :bid AND supplier_reference = :ref LIMIT 1');
        $chkRef->execute(['bid' => $bid, 'ref' => $ref]);
        $dup = $chkRef->fetch();
        if ($dup) {
            return [
                'success' => false,
                'error' => "Supplier document reference '{$ref}' has already been imported under entry #{$dup['entry_number']}. Duplicate import blocked to prevent double stock.",
                'duplicate_entry_id' => (int)$dup['id'],
            ];
        }
    }

    $eDate = $detectedDate ?: ($defaultDate ?: date('Y-m-d'));

    // Process all designs and post single/grouped inward entry
    $allLines = [];
    $totalQty = 0;

    foreach ($grouped as $dCode => $rows) {
        // Find or create parent product
        $stmtP = $db->prepare('SELECT id, name, sku, cost_price, selling_price FROM products WHERE sku = :sku AND business_id = :bid LIMIT 1');
        $stmtP->execute(['sku' => $dCode, 'bid' => $bid]);
        $prod = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            $db->prepare('
                INSERT INTO products (
                    business_id, name, sku, product_type, cost_price, selling_price,
                    track_inventory, stock_quantity, status, created_at, updated_at
                ) VALUES (
                    :bid, :name, :sku, "variable", :cp, 0,
                    1, 0, "active", NOW(), NOW()
                )
            ')->execute([
                'bid' => $bid,
                'name' => 'Design ' . $dCode,
                'sku' => $dCode,
                'cp' => (float)($rows[0]['cost_price'] ?? 0),
            ]);
            $productId = (int)$db->lastInsertId();
        } else {
            $productId = (int)$prod['id'];
        }

        $cDefs = [];
        foreach ($rows as $r) {
            $cDefs[] = [
                'colour' => $r['colour'],
                'cost_price' => $r['cost_price'],
            ];
        }
        $vMatrix = ensure_matrix_variants($productId, $cDefs, $sizes, $bid);

        foreach ($rows as $r) {
            $cName = $r['colour'];
            $cNorm = strtolower($cName);
            $cost = $r['cost_price'];
            foreach ($sizes as $sName) {
                $sNorm = strtolower($sName);
                $qty = (int)($r['quantities'][$sName] ?? 0);
                if ($qty > 0) {
                    $vRow = $vMatrix[$cNorm][$sNorm] ?? null;
                    $allLines[] = [
                        'product_id' => $productId,
                        'variant_id' => $vRow ? (int)$vRow['id'] : null,
                        'sku' => $vRow ? (string)$vRow['sku'] : null,
                        'size' => $sName,
                        'colour' => $cName,
                        'quantity' => $qty,
                        'purchase_cost' => $cost,
                        'selling_price' => null,
                    ];
                    $totalQty += $qty;
                }
            }
        }
    }

    if (empty($allLines)) {
        return ['success' => false, 'error' => 'No inward quantities found in the CSV rows.'];
    }

    // Save inward entry
    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }

    try {
        $entryNumber = inward_next_number($db, $bid);
        $stmtEntry = $db->prepare('
            INSERT INTO inward_entries (
                business_id, entry_number, warehouse_id, vendor_id, supplier_reference, user_id,
                entry_date, status, purchase_status, is_sellable, notes, inward_mode, created_at, updated_at
            ) VALUES (
                :bid, :num, :wid, :vid, :ref, :uid,
                :entry_date, "cost_pending", "pending", 0, "Bulk CSV Import", "import", NOW(), NOW()
            )
        ');
        $stmtEntry->execute([
            'bid' => $bid,
            'num' => $entryNumber,
            'wid' => $whId,
            'vid' => $vId,
            'ref' => $ref !== '' ? $ref : null,
            'uid' => $userId,
            'entry_date' => $eDate,
        ]);
        $entryId = (int)$db->lastInsertId();

        $stmtLine = $db->prepare('
            INSERT INTO inward_lines (
                inward_entry_id, product_id, variant_id, sku, size, colour, quantity,
                purchase_cost, selling_price, cost_status, created_at
            ) VALUES (
                :eid, :pid, :varid, :sku, :size, :colour, :qty,
                :cost, NULL, :cost_status, NOW()
            )
        ');

        foreach ($allLines as $line) {
            $stmtLine->execute([
                'eid' => $entryId,
                'pid' => $line['product_id'],
                'varid' => $line['variant_id'],
                'sku' => $line['sku'],
                'size' => $line['size'],
                'colour' => $line['colour'],
                'qty' => $line['quantity'],
                'cost' => $line['purchase_cost'],
                'cost_status' => $line['purchase_cost'] !== null ? 'confirmed' : 'pending',
            ]);
        }

        inward_post_company_stock($db, $bid, $entryId, $entryNumber, $allLines, $userId, $whId);
        inward_post_variant_stock($db, $bid, $whId, $allLines);
        $db->prepare('UPDATE inward_entries SET company_stock_posted = 1 WHERE id = :id AND business_id = :bid')
            ->execute(['id' => $entryId, 'bid' => $bid]);

        if ($ownTx) {
            $db->commit();
        }

        return [
            'success' => true,
            'entry_id' => $entryId,
            'entry_number' => $entryNumber,
            'total_qty' => $totalQty,
            'rows_imported' => count($allLines),
        ];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => 'CSV Inward Import failed: ' . $e->getMessage()];
    }
}

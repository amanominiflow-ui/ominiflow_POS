<?php
/**
 * Warehouse inward count.
 * Stores product + size + colour + quantity only.
 * Does not change sellable stock, variants, purchase receives, or POS.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

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
        'purchase_cost' => 'DECIMAL(12,2) NULL AFTER `quantity`',
        'selling_price' => 'DECIMAL(12,2) NULL AFTER `purchase_cost`',
        'cost_status' => "ENUM('pending','confirmed') NOT NULL DEFAULT 'pending' AFTER `selling_price`",
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
        if (!in_array('bill_id', $entryCols, true)) {
            $db->exec('ALTER TABLE `inward_entries` ADD COLUMN `bill_id` INT UNSIGNED NULL AFTER `vendor_id`');
        }
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
        if ($attrs['size'] === '' || $attrs['colour'] === '') {
            continue;
        }
        $pid = (int) $variant['product_id'];
        $byProduct[$pid][] = [
            'id' => (int) $variant['id'],
            'size' => $attrs['size'],
            'colour' => $attrs['colour'],
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
                inward_entry_id, product_id, variant_id, size, colour, quantity,
                purchase_cost, selling_price, cost_status, created_at
            ) VALUES (
                :eid, :pid, :varid, :size, :colour, :qty,
                NULL, NULL, "pending", NOW()
            )
        ');
        foreach ($resolved['lines'] as $line) {
            $stmtLine->execute([
                'eid' => $entryId,
                'pid' => $line['product_id'],
                'varid' => $line['variant_id'],
                'size' => $line['size'],
                'colour' => $line['colour'],
                'qty' => $line['quantity'],
            ]);
        }

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
        $qty = (int) ($raw['quantity'] ?? 0);

        if ($productId <= 0) {
            return ['success' => false, 'error' => 'Each line needs a product.'];
        }
        if ($size === '' || $colour === '') {
            return ['success' => false, 'error' => 'Each line needs a size and a colour.'];
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
            SELECT id, variant_name, attribute_values
            FROM product_variants
            WHERE product_id = :pid AND business_id = :bid AND status = "active"
        ');
        $stmtVars->execute(['pid' => $productId, 'bid' => $businessId]);
        $variantId = null;
        $matched = false;
        $hasSizeColour = false;
        foreach ($stmtVars->fetchAll() as $variant) {
            $attrs = parse_variant_size_colour($variant);
            if ($attrs['size'] === '' || $attrs['colour'] === '') {
                continue;
            }
            $hasSizeColour = true;
            if (strcasecmp($attrs['size'], $size) === 0 && strcasecmp($attrs['colour'], $colour) === 0) {
                $variantId = (int) $variant['id'];
                $size = $attrs['size'];
                $colour = $attrs['colour'];
                $matched = true;
                break;
            }
        }
        if ($hasSizeColour && !$matched) {
            return ['success' => false, 'error' => 'Choose a size and colour that already exist on "' . $product['name'] . '". This count does not create variants or sellable stock.'];
        }

        $key = $productId . '|' . strtolower($size) . '|' . strtolower($colour);
        if (!isset($merged[$key])) {
            $merged[$key] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
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
        $sql .= ' AND (ie.entry_number LIKE :s1 OR w.name LIKE :s2 OR v.name LIKE :s3 OR ie.notes LIKE :s4)';
        $params['s1'] = '%' . $search . '%';
        $params['s2'] = '%' . $search . '%';
        $params['s3'] = '%' . $search . '%';
        $params['s4'] = '%' . $search . '%';
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

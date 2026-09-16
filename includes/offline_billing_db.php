<?php
/**
 * Isolated Offline Billing storage for COD Manifest.
 * Does not touch orders, invoices, stock, or inventory movements.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function ensure_offline_billing_schema(): void {
    $db = get_db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS `offline_bills` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `invoice_number` VARCHAR(50) NOT NULL,
            `order_number` VARCHAR(80) NOT NULL DEFAULT '',
            `invoice_date` DATE NOT NULL,
            `payment_mode` VARCHAR(80) NOT NULL DEFAULT 'Cash on Delivery (COD)',
            `customer_name` VARCHAR(255) NOT NULL DEFAULT '',
            `customer_phone` VARCHAR(50) NOT NULL DEFAULT '',
            `customer_address` VARCHAR(500) NOT NULL DEFAULT '',
            `customer_pincode` VARCHAR(20) NOT NULL DEFAULT '',
            `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `shipping_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `grand_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `print_size` VARCHAR(20) NOT NULL DEFAULT 'default',
            `display_snapshot` JSON NULL,
            `created_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_ofb_invoice` (`business_id`, `invoice_number`),
            INDEX `idx_ofb_biz_date` (`business_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `offline_bill_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `bill_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NULL,
            `product_name` VARCHAR(255) NOT NULL DEFAULT '',
            `size` VARCHAR(80) NOT NULL DEFAULT '-',
            `colour` VARCHAR(80) NOT NULL DEFAULT '-',
            `quantity` INT NOT NULL DEFAULT 1,
            `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            INDEX `idx_ofb_items_bill` (`bill_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function generate_next_offline_invoice_number(?int $businessId = null): string {
    ensure_offline_billing_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $prefix = 'OFB-' . date('Ymd') . '-';
    $stmt = $db->prepare("
        SELECT invoice_number FROM offline_bills
        WHERE business_id = :bid AND invoice_number LIKE :pfx
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(['bid' => $bid, 'pfx' => $prefix . '%']);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $seq = 1;
    if ($last !== '' && preg_match('/(\d+)$/', $last, $m)) {
        $seq = ((int) $m[1]) + 1;
    }
    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

function generate_next_offline_order_number(?int $businessId = null): string {
    ensure_offline_billing_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    do {
        $num = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $stmt = $db->prepare('SELECT id FROM offline_bills WHERE business_id = :bid AND order_number IN (:a, :b) LIMIT 1');
        $stmt->execute(['bid' => $bid, 'a' => $num, 'b' => '#' . $num]);
        $exists = (int) $stmt->fetchColumn();
    } while ($exists > 0);
    return '#' . $num;
}

function get_offline_bill_by_id(int $id, ?int $businessId = null): ?array {
    ensure_offline_billing_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM offline_bills WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $id, 'bid' => $bid]);
    $bill = $stmt->fetch();
    if (!$bill) {
        return null;
    }
    $itemStmt = $db->prepare('SELECT * FROM offline_bill_items WHERE bill_id = :bid ORDER BY sort_order ASC, id ASC');
    $itemStmt->execute(['bid' => $id]);
    $bill['items'] = $itemStmt->fetchAll() ?: [];
    return $bill;
}

function list_recent_offline_bills(int $limit = 25, ?int $businessId = null): array {
    ensure_offline_billing_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $limit = max(1, min(100, $limit));
    $stmt = $db->prepare("
        SELECT id, invoice_number, order_number, customer_name, grand_total, invoice_date, print_size, created_at
        FROM offline_bills
        WHERE business_id = :bid
        ORDER BY id DESC
        LIMIT {$limit}
    ");
    $stmt->execute(['bid' => $bid]);
    return $stmt->fetchAll() ?: [];
}

function save_offline_bill(array $data, ?int $userId = null, ?int $businessId = null): array {
    ensure_offline_billing_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $billId = max(0, (int) ($data['id'] ?? 0));
    $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
    $orderNumber = trim((string) ($data['order_number'] ?? ''));
    $invoiceDate = trim((string) ($data['invoice_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
        $ts = strtotime($invoiceDate);
        $invoiceDate = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }
    $paymentMode = trim((string) ($data['payment_mode'] ?? 'Cash on Delivery (COD)'));
    if ($paymentMode === '') {
        $paymentMode = 'Cash on Delivery (COD)';
    }
    $customerName = trim((string) ($data['customer_name'] ?? ''));
    $customerPhone = trim((string) ($data['customer_phone'] ?? ''));
    $customerAddress = trim((string) ($data['customer_address'] ?? ''));
    $customerPincode = trim((string) ($data['customer_pincode'] ?? ''));
    $discount = max(0.0, (float) ($data['discount_amount'] ?? 0));
    $shipping = max(0.0, (float) ($data['shipping_fee'] ?? 0));
    $printSize = trim((string) ($data['print_size'] ?? 'default'));
    if (!in_array($printSize, ['default', '4x3'], true)) {
        $printSize = 'default';
    }

    $rawItems = $data['items'] ?? [];
    if (!is_array($rawItems)) {
        $rawItems = [];
    }

    $items = [];
    $subtotal = 0.0;
    foreach ($rawItems as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string) ($row['product_name'] ?? $row['name'] ?? ''));
        $qty = max(1, (int) ($row['quantity'] ?? $row['qty'] ?? 1));
        $price = max(0.0, (float) ($row['unit_price'] ?? $row['price'] ?? 0));
        if ($name === '' && $price <= 0) {
            continue;
        }
        if ($name === '') {
            $name = 'Item';
        }
        $lineTotal = round($qty * $price, 2);
        $subtotal += $lineTotal;
        $size = trim((string) ($row['size'] ?? '-'));
        $colour = trim((string) ($row['colour'] ?? $row['color'] ?? '-'));
        $items[] = [
            'product_id' => !empty($row['product_id']) ? (int) $row['product_id'] : null,
            'product_name' => $name,
            'size' => $size !== '' ? $size : '-',
            'colour' => $colour !== '' ? $colour : '-',
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => $lineTotal,
        ];
    }

    if (empty($items)) {
        return ['success' => false, 'error' => 'Add at least one product before saving.'];
    }

    $grandTotal = max(0.0, round($subtotal - $discount + $shipping, 2));

    if ($invoiceNumber === '') {
        $invoiceNumber = generate_next_offline_invoice_number($bid);
    }
    if ($orderNumber === '') {
        $orderNumber = generate_next_offline_order_number($bid);
    }

    $snapshot = json_encode([
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'customer_address' => $customerAddress,
        'customer_pincode' => $customerPincode,
        'payment_mode' => $paymentMode,
        'print_size' => $printSize,
    ], JSON_UNESCAPED_UNICODE);

    try {
        $db->beginTransaction();

        if ($billId > 0) {
            $own = $db->prepare('SELECT id FROM offline_bills WHERE id = :id AND business_id = :bid LIMIT 1');
            $own->execute(['id' => $billId, 'bid' => $bid]);
            if (!$own->fetchColumn()) {
                throw new Exception('Offline bill not found.');
            }

            $dup = $db->prepare('SELECT id FROM offline_bills WHERE business_id = :bid AND invoice_number = :num AND id <> :id LIMIT 1');
            $dup->execute(['bid' => $bid, 'num' => $invoiceNumber, 'id' => $billId]);
            if ($dup->fetchColumn()) {
                throw new Exception('Invoice number already exists.');
            }

            $upd = $db->prepare('
                UPDATE offline_bills SET
                    invoice_number = :invoice_number,
                    order_number = :order_number,
                    invoice_date = :invoice_date,
                    payment_mode = :payment_mode,
                    customer_name = :customer_name,
                    customer_phone = :customer_phone,
                    customer_address = :customer_address,
                    customer_pincode = :customer_pincode,
                    subtotal = :subtotal,
                    discount_amount = :discount_amount,
                    shipping_fee = :shipping_fee,
                    grand_total = :grand_total,
                    print_size = :print_size,
                    display_snapshot = :display_snapshot,
                    updated_at = NOW()
                WHERE id = :id AND business_id = :bid
            ');
            $upd->execute([
                'invoice_number' => $invoiceNumber,
                'order_number' => $orderNumber,
                'invoice_date' => $invoiceDate,
                'payment_mode' => $paymentMode,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_address' => $customerAddress,
                'customer_pincode' => $customerPincode,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'shipping_fee' => $shipping,
                'grand_total' => $grandTotal,
                'print_size' => $printSize,
                'display_snapshot' => $snapshot,
                'id' => $billId,
                'bid' => $bid,
            ]);
            $db->prepare('DELETE FROM offline_bill_items WHERE bill_id = :id')->execute(['id' => $billId]);
        } else {
            $dup = $db->prepare('SELECT id FROM offline_bills WHERE business_id = :bid AND invoice_number = :num LIMIT 1');
            $dup->execute(['bid' => $bid, 'num' => $invoiceNumber]);
            if ($dup->fetchColumn()) {
                $invoiceNumber = generate_next_offline_invoice_number($bid);
            }

            $ins = $db->prepare('
                INSERT INTO offline_bills (
                    business_id, invoice_number, order_number, invoice_date, payment_mode,
                    customer_name, customer_phone, customer_address, customer_pincode,
                    subtotal, discount_amount, shipping_fee, grand_total, print_size,
                    display_snapshot, created_by, created_at, updated_at
                ) VALUES (
                    :bid, :invoice_number, :order_number, :invoice_date, :payment_mode,
                    :customer_name, :customer_phone, :customer_address, :customer_pincode,
                    :subtotal, :discount_amount, :shipping_fee, :grand_total, :print_size,
                    :display_snapshot, :created_by, NOW(), NOW()
                )
            ');
            $ins->execute([
                'bid' => $bid,
                'invoice_number' => $invoiceNumber,
                'order_number' => $orderNumber,
                'invoice_date' => $invoiceDate,
                'payment_mode' => $paymentMode,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_address' => $customerAddress,
                'customer_pincode' => $customerPincode,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'shipping_fee' => $shipping,
                'grand_total' => $grandTotal,
                'print_size' => $printSize,
                'display_snapshot' => $snapshot,
                'created_by' => $userId,
            ]);
            $billId = (int) $db->lastInsertId();
        }

        $itemIns = $db->prepare('
            INSERT INTO offline_bill_items (
                bill_id, product_id, product_name, size, colour, quantity, unit_price, line_total, sort_order
            ) VALUES (
                :bill_id, :product_id, :product_name, :size, :colour, :quantity, :unit_price, :line_total, :sort_order
            )
        ');
        foreach ($items as $idx => $item) {
            $itemIns->execute([
                'bill_id' => $billId,
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'size' => $item['size'],
                'colour' => $item['colour'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
                'sort_order' => $idx,
            ]);
        }

        $db->commit();
        return [
            'success' => true,
            'id' => $billId,
            'invoice_number' => $invoiceNumber,
            'order_number' => $orderNumber,
            'grand_total' => $grandTotal,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function ofb_parse_variant_attrs(array $variant): array {
    $size = '';
    $colour = '';
    $av = json_decode((string) ($variant['attribute_values'] ?? ''), true);
    if (is_array($av)) {
        foreach ($av as $k => $v) {
            $kLow = strtolower((string) $k);
            $val = trim((string) $v);
            if ($val === '') {
                continue;
            }
            if (in_array($kLow, ['size', 'sizes', 'size / fits'], true)) {
                $size = $val;
            }
            if (in_array($kLow, ['color', 'colour', 'shade'], true)) {
                $colour = $val;
            }
        }
    }
    $vn = trim((string) ($variant['variant_name'] ?? ''));
    if (($size === '' || $colour === '') && $vn !== '' && str_contains($vn, '/')) {
        $parts = array_map('trim', explode('/', $vn));
        if ($size === '' && isset($parts[0])) {
            $size = $parts[0];
        }
        if ($colour === '' && isset($parts[1])) {
            $colour = $parts[1];
        }
    }
    return ['size' => $size, 'colour' => $colour];
}

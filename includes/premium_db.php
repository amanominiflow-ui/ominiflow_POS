<?php
/**
 * Premium plan — ₹35,000 with 18% GST (GST amount is not shown or calculated).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function premium_base_amount(): float {
    return 35000.00;
}

function premium_gst_rate(): float {
    return 18.00;
}

function premium_total_amount(): float {
    return 35000.00;
}

function premium_price_breakdown(): array {
    $amount = premium_total_amount();
    return [
        'base' => $amount,
        'gst_rate' => premium_gst_rate(),
        'gst_amount' => 0.00,
        'total' => $amount,
    ];
}

function premium_features(): array {
    return [
        [
            'title' => 'POS Billing',
            'items' => [
                'Fast POS register with barcode billing',
                'Invoices, payments, and sales returns',
                'Multiple payment options at checkout',
            ],
        ],
        [
            'title' => 'Inventory & Catalog',
            'items' => [
                'Unlimited products and categories',
                'Live stock, stock count, and transfers',
                'Barcode labels and print templates',
            ],
        ],
        [
            'title' => 'Online / Mobile Store',
            'items' => [
                'Customer-facing mobile store website',
                'Home Layout editor (banners, categories, items)',
                'Branding: logo, favicon, colors, and fonts',
                'Custom domain for your store URL',
            ],
        ],
        [
            'title' => 'Multi-store & Team',
            'items' => [
                'Multi-outlet / warehouse operations',
                'Users, roles, and access control',
                'Register shifts and cash movements',
            ],
        ],
        [
            'title' => 'Reports & GST',
            'items' => [
                'Sales, inventory, and GST reports',
                'GSTR-1 ready tax summaries',
                'Customer and order history',
            ],
        ],
        [
            'title' => 'Integrations',
            'items' => [
                'WhatsApp, shipping, and cart channels',
                'Import / export of catalog data',
                'Promotions and fulfillment tools',
            ],
        ],
    ];
}

function premium_add_column_if_missing(PDO $db, string $table, string $column, string $definition): void {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :col
        ");
        $stmt->execute(['db' => DB_NAME, 'table' => $table, 'col' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE `{$table}` ADD `{$column}` {$definition}");
        }
    } catch (PDOException $e) {
        // ignore
    }
}

function ensure_premium_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = get_db();

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `premium_orders` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `business_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `amount` DECIMAL(12,2) NOT NULL,
                `gst_rate` DECIMAL(5,2) NOT NULL DEFAULT 18.00,
                `gst_amount` DECIMAL(12,2) NOT NULL,
                `total_amount` DECIMAL(12,2) NOT NULL,
                `payment_method` VARCHAR(50) NULL,
                `payment_ref` VARCHAR(120) NULL,
                `payer_name` VARCHAR(150) NULL,
                `payer_phone` VARCHAR(30) NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_premium_orders_biz` (`business_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        // ignore
    }

    premium_add_column_if_missing($db, 'businesses', 'is_premium', 'TINYINT(1) NOT NULL DEFAULT 0');
    premium_add_column_if_missing($db, 'businesses', 'premium_purchased_at', 'TIMESTAMP NULL');
    premium_add_column_if_missing($db, 'premium_orders', 'payment_method', 'VARCHAR(50) NULL');
    premium_add_column_if_missing($db, 'premium_orders', 'payment_ref', 'VARCHAR(120) NULL');
    premium_add_column_if_missing($db, 'premium_orders', 'payer_name', 'VARCHAR(150) NULL');
    premium_add_column_if_missing($db, 'premium_orders', 'payer_phone', 'VARCHAR(30) NULL');
}

function is_premium_active(?int $businessId = null): bool {
    ensure_premium_schema();
    $bid = $businessId ?: (function_exists('current_business_id') ? current_business_id() : 0);
    if ($bid < 1) {
        return false;
    }
    try {
        $stmt = get_db()->prepare('SELECT is_premium FROM businesses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $bid]);
        return (int) $stmt->fetchColumn() === 1;
    } catch (PDOException $e) {
        return false;
    }
}

function get_pending_premium_order(int $businessId): ?array {
    ensure_premium_schema();
    if ($businessId < 1) {
        return null;
    }
    try {
        $stmt = get_db()->prepare('
            SELECT * FROM premium_orders
            WHERE business_id = :bid AND status = "pending"
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute(['bid' => $businessId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function submit_premium_payment(int $businessId, int $userId, array $payload): array {
    ensure_premium_schema();
    if ($businessId < 1 || $userId < 1) {
        return ['success' => false, 'error' => 'Invalid account.'];
    }
    if (is_premium_active($businessId)) {
        return ['success' => false, 'error' => 'Premium is already active on this account.'];
    }

    $method = strtolower(trim((string) ($payload['payment_method'] ?? '')));
    $allowed = ['upi', 'bank', 'card'];
    if (!in_array($method, $allowed, true)) {
        return ['success' => false, 'error' => 'Select a payment method.'];
    }

    $name = trim((string) ($payload['payer_name'] ?? ''));
    $phone = trim((string) ($payload['payer_phone'] ?? ''));
    $ref = trim((string) ($payload['payment_ref'] ?? ''));
    if ($name === '') {
        return ['success' => false, 'error' => 'Enter the payer name.'];
    }
    if ($phone === '') {
        return ['success' => false, 'error' => 'Enter a phone number.'];
    }
    if ($ref === '') {
        return ['success' => false, 'error' => 'Enter the UTR / transaction ID after you pay.'];
    }

    $price = premium_price_breakdown();
    $db = get_db();
    try {
        $pending = get_pending_premium_order($businessId);
        if ($pending) {
            $db->prepare('
                UPDATE premium_orders
                SET user_id = :uid, payment_method = :method, payment_ref = :ref,
                    payer_name = :name, payer_phone = :phone, status = "pending"
                WHERE id = :id AND business_id = :bid
            ')->execute([
                'uid' => $userId,
                'method' => $method,
                'ref' => $ref,
                'name' => $name,
                'phone' => $phone,
                'id' => (int) $pending['id'],
                'bid' => $businessId,
            ]);
        } else {
            $db->prepare('
                INSERT INTO premium_orders (
                    business_id, user_id, amount, gst_rate, gst_amount, total_amount,
                    payment_method, payment_ref, payer_name, payer_phone, status, created_at
                ) VALUES (
                    :bid, :uid, :amt, :rate, :gst, :total,
                    :method, :ref, :name, :phone, "pending", NOW()
                )
            ')->execute([
                'bid' => $businessId,
                'uid' => $userId,
                'amt' => $price['base'],
                'rate' => $price['gst_rate'],
                'gst' => $price['gst_amount'],
                'total' => $price['total'],
                'method' => $method,
                'ref' => $ref,
                'name' => $name,
                'phone' => $phone,
            ]);
        }
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => 'Could not submit payment details. Try again.'];
    }
}

function premium_gate_enabled(): bool {
    return false;
}

function premium_free_pages(): array {
    return ['dashboard.php', 'pricing.php', 'premium-checkout.php', 'logout.php', 'login.php', 'signup.php'];
}

function enforce_premium_gate(): void {
    if (!premium_gate_enabled()) {
        return;
    }
    ensure_premium_schema();
    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    if (in_array($script, premium_free_pages(), true)) {
        return;
    }
    if (is_premium_active()) {
        return;
    }
    set_flash('error', 'Premium plan required. Buy Premium to unlock POS, inventory, online store, and all other modules.');
    redirect(APP_URL . '/pricing.php');
}

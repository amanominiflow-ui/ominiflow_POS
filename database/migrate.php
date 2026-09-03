<?php
/**
 * Master Migration runner for OminiFlow POS (Zoho POS Feature Parity)
 * Initializes all core, sales, purchases, inventory, registers, and payments tables.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function add_column_if_not_exists(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col
        ");
        $stmt->execute([
            'db' => DB_NAME,
            'tbl' => $table,
            'col' => $column,
        ]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD `{$column}` {$definition}");
        }
    } catch (Exception $e) {
        // Table might not exist or already has column
    }
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Create database if not exists
    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        DB_NAME
    ));

    // Select database
    $pdo->exec(sprintf('USE `%s`', DB_NAME));

    // 0. Businesses / Organizations Table (Multi-Tenant SaaS Support)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `businesses` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(191) NOT NULL,
            `legal_name` VARCHAR(191) NULL,
            `email` VARCHAR(191) NULL,
            `phone` VARCHAR(50) NULL,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
            `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '₹',
            `tax_id` VARCHAR(50) NULL,
            `address` TEXT NULL,
            `city` VARCHAR(100) NULL,
            `state` VARCHAR(100) NULL,
            `pincode` VARCHAR(20) NULL,
            `country` VARCHAR(100) NOT NULL DEFAULT 'India',
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_businesses_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed default Business ID 1 if empty
    $stmtB = $pdo->query("SELECT id FROM businesses WHERE id = 1 LIMIT 1");
    if (!$stmtB->fetch()) {
        $pdo->exec("
            INSERT INTO `businesses` (`id`, `name`, `legal_name`, `email`, `currency`, `currency_symbol`, `country`, `status`, `created_at`, `updated_at`)
            VALUES (1, 'OminiFlow Retail', 'OminiFlow POS Inc.', 'admin@ominiflow.com', 'INR', '₹', 'India', 'active', NOW(), NOW())
        ");
    }

    // 1. Users Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `name` VARCHAR(191) NOT NULL,
            `email` VARCHAR(191) NOT NULL UNIQUE,
            `phone` VARCHAR(50) NULL,
            `password` VARCHAR(255) NOT NULL,
            `role` ENUM('admin', 'manager', 'cashier') NOT NULL DEFAULT 'admin',
            `remember_token` VARCHAR(100) NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_users_business` (`business_id`),
            INDEX `idx_users_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Categories Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `categories` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `description` TEXT NULL,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_categories_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. Products Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `products` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `category_id` INT UNSIGNED NULL,
            `name` VARCHAR(191) NOT NULL,
            `sku` VARCHAR(100) NOT NULL UNIQUE,
            `barcode` VARCHAR(100) NULL UNIQUE,
            `cost_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `selling_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `tax_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
            `stock_quantity` INT NOT NULL DEFAULT 0,
            `low_stock_threshold` INT NOT NULL DEFAULT 5,
            `image_path` VARCHAR(255) NULL,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
            INDEX `idx_products_category` (`category_id`),
            INDEX `idx_products_sku` (`sku`),
            INDEX `idx_products_barcode` (`barcode`),
            INDEX `idx_products_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Inventory Movements Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `inventory_movements` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NULL,
            `movement_type` ENUM('in', 'out', 'adjustment') NOT NULL,
            `quantity_change` INT NOT NULL,
            `quantity_before` INT NOT NULL,
            `quantity_after` INT NOT NULL,
            `reason` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_movements_product` (`product_id`),
            INDEX `idx_movements_type` (`movement_type`),
            INDEX `idx_movements_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 5. Customers Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `customers` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(191) NOT NULL,
            `phone` VARCHAR(50) NULL,
            `email` VARCHAR(191) NULL,
            `address` TEXT NULL,
            `credit_limit` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `outstanding_balance` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `loyalty_points` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_customers_phone` (`phone`),
            INDEX `idx_customers_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Ensure default Walk-in customer exists
    $stmtWalkin = $pdo->query("SELECT id FROM customers WHERE id = 1 LIMIT 1");
    if (!$stmtWalkin->fetch()) {
        $pdo->exec("
            INSERT INTO `customers` (`id`, `name`, `phone`, `email`, `address`, `created_at`, `updated_at`)
            VALUES (1, 'Walk-in Customer', 'N/A', NULL, 'In-Store Counter', NOW(), NOW())
        ");
    }

    // 6. Registers Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `registers` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `order_prefix` VARCHAR(20) NOT NULL DEFAULT 'ORD',
            `invoice_prefix` VARCHAR(20) NOT NULL DEFAULT 'INV',
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Ensure default Main Register exists
    $stmtReg = $pdo->query("SELECT id FROM registers WHERE id = 1 LIMIT 1");
    if (!$stmtReg->fetch()) {
        $pdo->exec("
            INSERT INTO `registers` (`id`, `name`, `code`, `order_prefix`, `invoice_prefix`, `status`, `created_at`, `updated_at`)
            VALUES (1, 'Main Checkout Register #1', 'REG-01', 'ORD', 'INV', 'active', NOW(), NOW())
        ");
    }

    // 7. Register Sessions Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `register_sessions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `register_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `opened_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `closed_at` TIMESTAMP NULL,
            `opening_cash` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `closing_cash_actual` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `closing_cash_expected` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `cash_difference` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `total_cash_sales` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `total_card_sales` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `total_upi_sales` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `total_refunds` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `cash_in` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `cash_out` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `status` ENUM('open', 'closed') NOT NULL DEFAULT 'open',
            `closing_notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`register_id`) REFERENCES `registers` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            INDEX `idx_reg_sessions_user` (`user_id`),
            INDEX `idx_reg_sessions_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 8. Orders Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `orders` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `order_number` VARCHAR(50) NOT NULL UNIQUE,
            `register_id` INT UNSIGNED NULL,
            `session_id` INT UNSIGNED NULL,
            `customer_id` INT UNSIGNED NULL,
            `user_id` INT UNSIGNED NULL,
            `subtotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `discount_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `discount_type` ENUM('fixed', 'percent') NOT NULL DEFAULT 'fixed',
            `tax_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash',
            `payment_status` ENUM('paid', 'partially_paid', 'pending', 'cancelled') NOT NULL DEFAULT 'paid',
            `order_status` ENUM('completed', 'hold', 'cancelled') NOT NULL DEFAULT 'completed',
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_orders_number` (`order_number`),
            INDEX `idx_orders_created` (`created_at`),
            INDEX `idx_orders_status` (`order_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 9. Order Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `order_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NULL,
            `product_name` VARCHAR(191) NOT NULL,
            `product_sku` VARCHAR(100) NOT NULL,
            `unit_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `quantity` INT NOT NULL DEFAULT 1,
            `tax_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `discount_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
            INDEX `idx_order_items_order` (`order_id`),
            INDEX `idx_order_items_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 10. Invoices Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `invoices` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
            `order_id` INT UNSIGNED NOT NULL,
            `customer_id` INT UNSIGNED NULL,
            `user_id` INT UNSIGNED NULL,
            `invoice_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `subtotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `discount_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `discount_type` VARCHAR(20) NOT NULL DEFAULT 'fixed',
            `taxable_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `cgst_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `sgst_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `igst_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `amount_paid` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `change_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash',
            `payment_status` ENUM('paid', 'partially_paid', 'unpaid', 'refunded', 'cancelled') NOT NULL DEFAULT 'paid',
            `invoice_status` ENUM('paid', 'draft', 'cancelled', 'refunded') NOT NULL DEFAULT 'paid',
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_invoices_number` (`invoice_number`),
            INDEX `idx_invoices_order` (`order_id`),
            INDEX `idx_invoices_status` (`invoice_status`),
            INDEX `idx_invoices_date` (`invoice_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 11. Payments Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payments` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `payment_number` VARCHAR(50) NOT NULL UNIQUE,
            `order_id` INT UNSIGNED NULL,
            `invoice_id` INT UNSIGNED NULL,
            `customer_id` INT UNSIGNED NULL,
            `user_id` INT UNSIGNED NULL,
            `session_id` INT UNSIGNED NULL,
            `payment_type` ENUM('sale', 'refund', 'receivable_payment') NOT NULL DEFAULT 'sale',
            `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash',
            `amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `transaction_reference` VARCHAR(100) NULL,
            `status` ENUM('paid', 'refunded', 'failed') NOT NULL DEFAULT 'paid',
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_payments_number` (`payment_number`),
            INDEX `idx_payments_order` (`order_id`),
            INDEX `idx_payments_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 12. Held Sales Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `held_sales` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `reference_note` VARCHAR(191) NOT NULL,
            `customer_id` INT UNSIGNED NULL,
            `user_id` INT UNSIGNED NULL,
            `cart_json` LONGTEXT NOT NULL,
            `subtotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_held_sales_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 13. Returns Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `returns` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `return_number` VARCHAR(50) NOT NULL UNIQUE,
            `order_id` INT UNSIGNED NOT NULL,
            `invoice_id` INT UNSIGNED NULL,
            `customer_id` INT UNSIGNED NULL,
            `user_id` INT UNSIGNED NULL,
            `refund_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `refund_method` VARCHAR(50) NOT NULL DEFAULT 'cash',
            `reason` VARCHAR(255) NOT NULL,
            `notes` TEXT NULL,
            `status` ENUM('completed', 'pending', 'rejected') NOT NULL DEFAULT 'completed',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_returns_number` (`return_number`),
            INDEX `idx_returns_order` (`order_id`),
            INDEX `idx_returns_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 14. Return Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `return_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `return_id` INT UNSIGNED NOT NULL,
            `order_item_id` INT UNSIGNED NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `product_name` VARCHAR(191) NOT NULL,
            `product_sku` VARCHAR(100) NOT NULL,
            `unit_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `quantity` INT NOT NULL DEFAULT 1,
            `refund_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            INDEX `idx_return_items_return` (`return_id`),
            INDEX `idx_return_items_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 15. Credit Notes Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `credit_notes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `credit_note_number` VARCHAR(50) NOT NULL UNIQUE,
            `return_id` INT UNSIGNED NULL,
            `invoice_id` INT UNSIGNED NULL,
            `customer_id` INT UNSIGNED NULL,
            `user_id` INT UNSIGNED NULL,
            `amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `status` ENUM('active', 'redeemed', 'refunded', 'cancelled') NOT NULL DEFAULT 'active',
            `reason` VARCHAR(255) NOT NULL,
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
            INDEX `idx_cn_number` (`credit_note_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 16. Vendors Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `vendors` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(191) NOT NULL,
            `company_name` VARCHAR(191) NULL,
            `phone` VARCHAR(50) NULL,
            `email` VARCHAR(191) NULL,
            `address` TEXT NULL,
            `gstin` VARCHAR(50) NULL,
            `payment_terms` VARCHAR(100) NULL DEFAULT 'Net 30',
            `outstanding_balance` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_vendors_name` (`name`),
            INDEX `idx_vendors_phone` (`phone`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed default vendor if none exists
    $stmtVen = $pdo->query("SELECT id FROM vendors WHERE id = 1 LIMIT 1");
    if (!$stmtVen->fetch()) {
        $pdo->exec("
            INSERT INTO `vendors` (`id`, `name`, `company_name`, `phone`, `email`, `address`, `gstin`, `payment_terms`, `created_at`, `updated_at`)
            VALUES (1, 'Apex Wholesale Distributors', 'Apex Logistics Ltd', '+91 99000 11223', 'supplies@apexwholesale.com', 'Warehouse Complex 7, Bangalore', '29AAACA1234B1Z5', 'Net 30', NOW(), NOW())
        ");
    }

    // 17. Purchase Orders Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `purchase_orders` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `po_number` VARCHAR(50) NOT NULL UNIQUE,
            `vendor_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NULL,
            `subtotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `expected_delivery_date` DATE NULL,
            `status` ENUM('draft', 'ordered', 'partially_received', 'received', 'cancelled') NOT NULL DEFAULT 'ordered',
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_po_number` (`po_number`),
            INDEX `idx_po_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 18. Purchase Order Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `purchase_order_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `purchase_order_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `product_name` VARCHAR(191) NOT NULL,
            `product_sku` VARCHAR(100) NOT NULL,
            `unit_cost` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `quantity_ordered` INT NOT NULL DEFAULT 1,
            `quantity_received` INT NOT NULL DEFAULT 0,
            `tax_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            INDEX `idx_poi_po` (`purchase_order_id`),
            INDEX `idx_poi_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 19. Stock Counts Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `stock_counts` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `count_number` VARCHAR(50) NOT NULL UNIQUE,
            `user_id` INT UNSIGNED NOT NULL,
            `status` ENUM('in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'in_progress',
            `total_items_counted` INT NOT NULL DEFAULT 0,
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `completed_at` TIMESTAMP NULL,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            INDEX `idx_sc_number` (`count_number`),
            INDEX `idx_sc_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 20. Stock Count Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `stock_count_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `stock_count_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `expected_qty` INT NOT NULL DEFAULT 0,
            `counted_qty` INT NOT NULL DEFAULT 0,
            `difference_qty` INT NOT NULL DEFAULT 0,
            `is_reconciled` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`stock_count_id`) REFERENCES `stock_counts` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            INDEX `idx_sci_count` (`stock_count_id`),
            INDEX `idx_sci_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 21. Store Settings Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `store_settings` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `store_name` VARCHAR(191) NOT NULL DEFAULT 'OminiFlow Retail POS',
            `legal_name` VARCHAR(191) NULL,
            `tagline` VARCHAR(255) NULL DEFAULT 'Official Retail Store & POS Terminal',
            `logo_path` VARCHAR(255) NULL DEFAULT 'assets/images/logo.jpg',
            `address` TEXT NULL,
            `city` VARCHAR(100) NULL,
            `state` VARCHAR(100) NULL,
            `pincode` VARCHAR(20) NULL,
            `phone` VARCHAR(50) NULL DEFAULT '+91 98765 43210',
            `email` VARCHAR(191) NULL DEFAULT 'pos@ominiflow.com',
            `gstin` VARCHAR(50) NULL DEFAULT '29ABCDE1234F1Z5',
            `pan_number` VARCHAR(50) NULL,
            `bank_name` VARCHAR(100) NULL DEFAULT 'HDFC Bank',
            `account_holder` VARCHAR(191) NULL DEFAULT 'Ominiflow Enterprises',
            `account_number` VARCHAR(50) NULL DEFAULT '50200111653091',
            `bank_ifsc` VARCHAR(30) NULL DEFAULT 'HDFC0000887',
            `bank_branch` VARCHAR(100) NULL DEFAULT 'DEWAS',
            `account_type` VARCHAR(50) NULL DEFAULT 'Current Account',
            `upi_id` VARCHAR(100) NULL,
            `terms_conditions` TEXT NULL,
            `privacy_policy` MEDIUMTEXT NULL,
            `package_name` VARCHAR(100) NULL DEFAULT 'Monthly',
            `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '₹',
            `tax_type` VARCHAR(20) NOT NULL DEFAULT 'GST',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Ensure default Store Settings row exists
    $stmtSettings = $pdo->query("SELECT id FROM store_settings WHERE id = 1 LIMIT 1");
    if (!$stmtSettings->fetch()) {
        $pdo->exec("
            INSERT INTO `store_settings` (
                `id`, `store_name`, `tagline`, `logo_path`, `address`, `phone`, `email`, `gstin`, `currency_symbol`, `tax_type`, `created_at`, `updated_at`
            ) VALUES (
                1, 'OminiFlow Retail POS', 'Official Retail Store & POS Terminal', 'assets/images/logo.jpg',
                'Plot No. 42, Tech Park, Sector 5, Bangalore, Karnataka - 560100', '+91 98765 43210', 'pos@ominiflow.com',
                '29ABCDE1234F1Z5', '₹', 'GST', NOW(), NOW()
            )
        ");
    }

    /* =========================================================================
       PHASE 2 ADVANCED ZOHO POS FEATURE PARITY TABLES
       ========================================================================= */

    // 22. Outlets Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `outlets` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(191) NOT NULL,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `address` TEXT NULL,
            `phone` VARCHAR(50) NULL,
            `email` VARCHAR(191) NULL,
            `gstin` VARCHAR(50) NULL,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_outlets_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 23. Warehouses Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `warehouses` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `outlet_id` INT UNSIGNED NULL,
            `name` VARCHAR(191) NOT NULL,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `location` TEXT NULL,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE SET NULL,
            INDEX `idx_warehouses_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 24. Warehouse Stock Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `warehouse_stock` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `warehouse_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `stock_quantity` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_wh_product` (`warehouse_id`, `product_id`),
            FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            INDEX `idx_whs_wh` (`warehouse_id`),
            INDEX `idx_whs_prod` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 25. Stock Transfers Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `stock_transfers` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `transfer_number` VARCHAR(50) NOT NULL UNIQUE,
            `source_warehouse_id` INT UNSIGNED NOT NULL,
            `dest_warehouse_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `status` ENUM('draft', 'requested', 'in_transit', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `completed_at` TIMESTAMP NULL,
            FOREIGN KEY (`source_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`dest_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            INDEX `idx_transfers_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 26. Stock Transfer Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `stock_transfer_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `stock_transfer_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `quantity_requested` INT NOT NULL DEFAULT 1,
            `quantity_transferred` INT NOT NULL DEFAULT 0,
            `quantity_received` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`stock_transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 27. Product Variants Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_variants` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT UNSIGNED NOT NULL,
            `variant_name` VARCHAR(191) NOT NULL,
            `sku` VARCHAR(100) NOT NULL UNIQUE,
            `barcode` VARCHAR(100) NULL UNIQUE,
            `cost_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `selling_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `stock_quantity` INT NOT NULL DEFAULT 0,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            INDEX `idx_pv_product` (`product_id`),
            INDEX `idx_pv_sku` (`sku`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 27b. Product Attributes Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_attributes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `product_id` INT UNSIGNED NOT NULL,
            `attribute_name` VARCHAR(100) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_pa_product` (`product_id`),
            INDEX `idx_pa_biz` (`business_id`),
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 27c. Product Attribute Options Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_attribute_options` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `attribute_id` INT UNSIGNED NOT NULL,
            `option_value` VARCHAR(100) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_pao_attr` (`attribute_id`),
            FOREIGN KEY (`attribute_id`) REFERENCES `product_attributes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 28. Composite Product Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `composite_product_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `parent_product_id` INT UNSIGNED NOT NULL,
            `component_product_id` INT UNSIGNED NOT NULL,
            `quantity` INT NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`parent_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`component_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 29. Price Lists Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `price_lists` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(191) NOT NULL,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `type` ENUM('fixed', 'percentage') NOT NULL DEFAULT 'fixed',
            `percentage_value` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 30. Price List Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `price_list_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `price_list_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `custom_price` DECIMAL(10, 2) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_pli_prod` (`price_list_id`, `product_id`),
            FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 31. Customer Groups Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `customer_groups` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(191) NOT NULL,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `discount_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
            `credit_limit` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 32. Promotions Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `promotions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(191) NOT NULL,
            `promo_type` ENUM('percentage', 'fixed_amount', 'buy_x_get_y') NOT NULL DEFAULT 'percentage',
            `discount_value` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `buy_qty` INT NOT NULL DEFAULT 0,
            `get_qty` INT NOT NULL DEFAULT 0,
            `min_order_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `start_date` DATE NULL,
            `end_date` DATE NULL,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 33. Coupons Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `coupons` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `discount_type` ENUM('percent', 'fixed') NOT NULL DEFAULT 'fixed',
            `discount_value` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `min_order_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `max_discount_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `usage_limit` INT NOT NULL DEFAULT 100,
            `usage_count` INT NOT NULL DEFAULT 0,
            `start_date` DATE NULL,
            `end_date` DATE NULL,
            `status` ENUM('active', 'inactive', 'expired') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 34. Loyalty Transactions Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `loyalty_transactions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT UNSIGNED NOT NULL,
            `order_id` INT UNSIGNED NULL,
            `transaction_type` ENUM('earned', 'redeemed', 'adjusted') NOT NULL,
            `points` INT NOT NULL,
            `balance_after` INT NOT NULL DEFAULT 0,
            `notes` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 35. Role Permissions Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `role_permissions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `role` ENUM('owner', 'admin', 'manager', 'cashier', 'inventory_manager', 'purchase_manager', 'accountant') NOT NULL,
            `permission` VARCHAR(100) NOT NULL,
            `is_allowed` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_role_perm` (`role`, `permission`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 36. Purchase Returns Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `purchase_returns` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `return_number` VARCHAR(50) NOT NULL UNIQUE,
            `purchase_order_id` INT UNSIGNED NOT NULL,
            `vendor_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `total_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `refund_method` VARCHAR(50) NOT NULL DEFAULT 'vendor_credit',
            `status` ENUM('completed', 'pending') NOT NULL DEFAULT 'completed',
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 37. Purchase Return Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `purchase_return_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `purchase_return_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `unit_cost` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `quantity` INT NOT NULL DEFAULT 1,
            `line_total` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 38. Vendor Payments Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `vendor_payments` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `payment_number` VARCHAR(50) NOT NULL UNIQUE,
            `vendor_id` INT UNSIGNED NOT NULL,
            `purchase_order_id` INT UNSIGNED NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `amount` DECIMAL(10, 2) NOT NULL,
            `payment_method` VARCHAR(50) NOT NULL DEFAULT 'bank_transfer',
            `transaction_ref` VARCHAR(100) NULL,
            `notes` TEXT NULL,
            `status` ENUM('paid', 'pending') NOT NULL DEFAULT 'paid',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 39. Product Serials Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_serials` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT UNSIGNED NOT NULL,
            `serial_number` VARCHAR(191) NOT NULL UNIQUE,
            `status` ENUM('available', 'sold', 'returned', 'defective') NOT NULL DEFAULT 'available',
            `order_id` INT UNSIGNED NULL,
            `invoice_id` INT UNSIGNED NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 40. Product Batches Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_batches` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT UNSIGNED NOT NULL,
            `batch_number` VARCHAR(100) NOT NULL,
            `mfg_date` DATE NULL,
            `expiry_date` DATE NULL,
            `quantity` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            INDEX `idx_batch_expiry` (`expiry_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 41. Channel Sync Logs Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `channel_sync_logs` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `channel` VARCHAR(50) NOT NULL,
            `event_type` VARCHAR(100) NOT NULL,
            `payload` LONGTEXT NULL,
            `status` ENUM('synced', 'pending', 'failed') NOT NULL DEFAULT 'synced',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 42. Audit Logs Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NULL,
            `action` VARCHAR(100) NOT NULL,
            `entity_type` VARCHAR(100) NOT NULL,
            `entity_id` INT UNSIGNED NULL,
            `old_value` LONGTEXT NULL,
            `new_value` LONGTEXT NULL,
            `details` TEXT NULL,
            `ip_address` VARCHAR(50) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
            INDEX `idx_audit_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 43. GST Settings Table (Zoho POS Parity)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gst_settings` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `is_gst_registered` TINYINT(1) NOT NULL DEFAULT 1,
            `gstin` VARCHAR(50) NULL,
            `registration_type` VARCHAR(50) NOT NULL DEFAULT 'Regular',
            `business_legal_name` VARCHAR(191) NULL,
            `business_trade_name` VARCHAR(191) NULL,
            `gst_registered_on` VARCHAR(50) NULL,
            `enable_reverse_charge` TINYINT(1) NOT NULL DEFAULT 0,
            `is_sez_overseas` TINYINT(1) NOT NULL DEFAULT 0,
            `track_digital_services` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed default GST Settings
    $stmtGST = $pdo->query("SELECT id FROM gst_settings WHERE id = 1 LIMIT 1");
    if (!$stmtGST->fetch()) {
        $pdo->exec("
            INSERT INTO `gst_settings` (`id`, `is_gst_registered`, `gstin`, `registration_type`, `business_legal_name`, `business_trade_name`, `gst_registered_on`, `enable_reverse_charge`, `is_sez_overseas`, `track_digital_services`, `created_at`, `updated_at`)
            VALUES (1, 1, '29ABCDE1234F1Z5', 'Regular', 'OminiFlow Retail Private Limited', 'OminiFlow POS & Billing', '01 Apr 2023', 0, 0, 0, NOW(), NOW())
        ");
    }

    // 44. Tax Rates Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tax_rates` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `rate` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
            `type` ENUM('gst', 'igst', 'cgst', 'sgst', 'exempt') NOT NULL DEFAULT 'gst',
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed GST Tax Slabs
    $stmtTR = $pdo->query("SELECT id FROM tax_rates LIMIT 1");
    if (!$stmtTR->fetch()) {
        $pdo->exec("
            INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `is_default`, `status`, `created_at`, `updated_at`) VALUES
            (1, 'GST 0% (Nil Rated / Exempt)', 0.00, 'exempt', 0, 'active', NOW(), NOW()),
            (2, 'GST 5% (Essential Goods)', 5.00, 'gst', 0, 'active', NOW(), NOW()),
            (3, 'GST 12% (Processed Goods)', 12.00, 'gst', 0, 'active', NOW(), NOW()),
            (4, 'GST 18% (Standard Rate)', 18.00, 'gst', 1, 'active', NOW(), NOW()),
            (5, 'GST 28% (Luxury & Sin Goods)', 28.00, 'gst', 0, 'active', NOW(), NOW()),
            (6, 'IGST 18% (Inter-State Supply)', 18.00, 'igst', 0, 'active', NOW(), NOW())
        ");
    }

    // 45. Business Profile Table (Zoho POS Exact Parity)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `business_profile` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `organization_id` VARCHAR(50) NOT NULL DEFAULT '60082591427',
            `business_name` VARCHAR(191) NOT NULL DEFAULT 'Ominiflow',
            `business_type` VARCHAR(100) NOT NULL DEFAULT 'Services',
            `business_location` VARCHAR(100) NOT NULL DEFAULT 'India',
            `phone_code` VARCHAR(10) NOT NULL DEFAULT '+91',
            `phone` VARCHAR(50) NOT NULL DEFAULT '9755332357',
            `email` VARCHAR(191) NOT NULL DEFAULT 'info@ominiflow.com',
            `website` VARCHAR(191) NULL DEFAULT 'https://ominiflow.com',
            `logo_path` VARCHAR(255) NULL,
            `address_line1` VARCHAR(255) NULL,
            `address_line2` VARCHAR(255) NULL,
            `city` VARCHAR(100) NULL,
            `state` VARCHAR(100) NOT NULL DEFAULT 'Madhya Pradesh',
            `zip_code` VARCHAR(20) NULL,
            `fiscal_year` VARCHAR(50) NOT NULL DEFAULT 'April - March',
            `base_currency` VARCHAR(20) NOT NULL DEFAULT 'INR',
            `time_zone` VARCHAR(100) NOT NULL DEFAULT '(GMT 05:30) India Standard Time (Asia/Calcutta)',
            `date_format` VARCHAR(50) NOT NULL DEFAULT 'dd MMM yyyy',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed default Business Profile
    $stmtBP = $pdo->query("SELECT id FROM business_profile WHERE id = 1 LIMIT 1");
    if (!$stmtBP->fetch()) {
        $pdo->exec("
            INSERT INTO `business_profile` (`id`, `organization_id`, `business_name`, `business_type`, `business_location`, `phone_code`, `phone`, `email`, `website`, `state`, `fiscal_year`, `base_currency`, `time_zone`, `date_format`, `created_at`, `updated_at`)
            VALUES (1, '60082591427', 'Ominiflow', 'Services', 'India', '+91', '9755332357', 'info@ominiflow.com', 'https://ominiflow.com', 'Madhya Pradesh', 'April - March', 'INR', '(GMT 05:30) India Standard Time (Asia/Calcutta)', 'dd MMM yyyy', NOW(), NOW())
        ");
    }

    // 46. Shipping Integrations Table (Zoho POS Exact Parity)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `shipping_integrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `provider_code` VARCHAR(50) NOT NULL UNIQUE,
            `provider_name` VARCHAR(100) NOT NULL,
            `api_key` VARCHAR(255) NULL,
            `api_secret` VARCHAR(255) NULL,
            `account_id` VARCHAR(100) NULL,
            `status` ENUM('connected', 'disconnected') NOT NULL DEFAULT 'disconnected',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 47. Ecommerce & Shopping Cart Integrations Table (Zoho POS Parity)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ecommerce_integrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `platform_code` VARCHAR(50) NOT NULL UNIQUE,
            `store_name` VARCHAR(191) NOT NULL,
            `store_url` VARCHAR(255) NULL,
            `client_id` VARCHAR(255) NULL,
            `client_secret` VARCHAR(255) NULL,
            `access_token` TEXT NULL,
            `status` ENUM('connected', 'disconnected') NOT NULL DEFAULT 'disconnected',
            `last_synced_at` TIMESTAMP NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 48. Consignment & COD Label Manifest Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `consignment_manifests` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `tracking_number` VARCHAR(100) NOT NULL,
            `product_name` VARCHAR(255) NULL,
            `service_label` VARCHAR(100) NOT NULL DEFAULT 'SPEED POST',
            `order_type` ENUM('Cash on Delivery', 'Prepaid') NOT NULL DEFAULT 'Cash on Delivery',
            `cod_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `sender_name` VARCHAR(255) NULL,
            `sender_owner` VARCHAR(255) NULL,
            `sender_address1` VARCHAR(255) NULL,
            `sender_address2` VARCHAR(255) NULL,
            `sender_state` VARCHAR(100) NULL,
            `sender_pincode` VARCHAR(20) NULL,
            `sender_mobile` VARCHAR(50) NULL,
            `receiver_name` VARCHAR(255) NOT NULL,
            `receiver_company` VARCHAR(255) NULL,
            `receiver_address1` VARCHAR(255) NULL,
            `receiver_address2` VARCHAR(255) NULL,
            `receiver_city` VARCHAR(100) NULL,
            `receiver_pincode` VARCHAR(20) NULL,
            `receiver_state` VARCHAR(100) NULL,
            `receiver_mobile` VARCHAR(50) NULL,
            `thank_you_message` VARCHAR(255) NULL,
            `footer_line` VARCHAR(255) NULL,
            `print_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Manifested',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_consignment_tracking` (`tracking_number`),
            INDEX `idx_consignment_date` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 48. Payment Options / Tender Types Table (Zoho POS Parity)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payment_options` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `display_name` VARCHAR(100) NOT NULL,
            `processing_type` VARCHAR(50) NOT NULL DEFAULT 'Manual Entry',
            `payment_mode` VARCHAR(100) NOT NULL DEFAULT 'Cash',
            `deposit_to` VARCHAR(100) NOT NULL DEFAULT 'Petty Cash',
            `is_customer_required` TINYINT(1) NOT NULL DEFAULT 0,
            `is_express_checkout` TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order` INT NOT NULL DEFAULT 0,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_payment_options_business` (`business_id`),
            INDEX `idx_payment_options_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed default Payment Tender Types for Business 1 if empty
    $stmtPO = $pdo->query("SELECT id FROM payment_options WHERE business_id = 1 LIMIT 1");
    if (!$stmtPO->fetch()) {
        $pdo->exec("
            INSERT INTO `payment_options` (`business_id`, `display_name`, `processing_type`, `payment_mode`, `deposit_to`, `is_customer_required`, `is_express_checkout`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
            (1, 'Cash', 'Manual Entry', 'Cash', 'Petty Cash', 0, 1, 1, 'active', NOW(), NOW()),
            (1, 'Card', 'Manual Entry', 'Card', 'Main Bank Account', 0, 0, 2, 'active', NOW(), NOW()),
            (1, 'UPI', 'Manual Entry', 'UPI', 'Main Bank Account', 0, 1, 3, 'active', NOW(), NOW()),
            (1, 'Credit Sale', 'Credit Sale', '-', 'Petty Cash', 1, 0, 4, 'active', NOW(), NOW()),
            (1, 'Loyalty', 'Loyalty Redemption', 'Loyalty Points', 'Petty Cash', 1, 0, 5, 'inactive', NOW(), NOW()),
            (1, 'Credit Note', 'Credit Note', 'Credit Note', 'Petty Cash', 1, 0, 6, 'active', NOW(), NOW());
        ");
    }

    // 49. Custom Roles & Permissions Table (Zoho POS Parity)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `roles` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT NULL,
            `web_access` TINYINT(1) NOT NULL DEFAULT 1,
            `billing_access` TINYINT(1) NOT NULL DEFAULT 1,
            `permissions_json` LONGTEXT NULL,
            `is_system_default` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_roles_business` (`business_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed default Roles for Business 1 if empty
    $stmtRole = $pdo->query("SELECT id FROM roles WHERE business_id = 1 LIMIT 1");
    if (!$stmtRole->fetch()) {
        $pdo->exec("
            INSERT INTO `roles` (`business_id`, `name`, `description`, `web_access`, `billing_access`, `permissions_json`, `is_system_default`, `created_at`, `updated_at`) VALUES
            (1, 'Admin', 'The administrators are the business owners. They\'ll have access to the entire application', 1, 1, '{\"all\":true}', 1, NOW(), NOW()),
            (1, 'Store Manager', 'The store manager manages the business. They\'ll have access to most features except for certain administrative privileges', 1, 1, '{\"inventory\":{\"items\":[\"view\",\"create\",\"edit\",\"delete\"]},\"sales\":{\"invoices\":[\"view\",\"create\",\"edit\"]},\"pos\":{\"allow_price_edit\":true,\"allow_discount\":true}}', 1, NOW(), NOW()),
            (1, 'Cashier', 'The staff executes day-to-day operations such as sales, receiving purchases, processing returns, etc.', 0, 1, '{\"sales\":{\"invoices\":[\"view\",\"create\"]},\"pos\":{\"allow_discount\":true,\"allow_cash_in\":true,\"allow_cash_out\":true}}', 1, NOW(), NOW()),
            (1, 'Staff', 'General store staff with basic sales checkout and item lookup permissions.', 0, 1, '{\"sales\":{\"invoices\":[\"view\",\"create\"]}}', 1, NOW(), NOW());
        ");
    }

    // 50. Customer Payment Gateway Integrations Table (Razorpay, Paytm, Stripe, Pine Labs, PhonePe, Worldline, 2Checkout)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payment_integrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `gateway_code` VARCHAR(50) NOT NULL,
            `gateway_name` VARCHAR(100) NOT NULL,
            `api_key` VARCHAR(255) NULL,
            `api_secret` VARCHAR(255) NULL,
            `merchant_id` VARCHAR(100) NULL,
            `webhook_secret` VARCHAR(255) NULL,
            `terminal_id` VARCHAR(100) NULL,
            `environment` ENUM('test', 'live') NOT NULL DEFAULT 'test',
            `enable_in_pos` TINYINT(1) NOT NULL DEFAULT 1,
            `enable_in_store` TINYINT(1) NOT NULL DEFAULT 1,
            `status` ENUM('active', 'inactive', 'connected', 'disconnected') NOT NULL DEFAULT 'disconnected',
            `extra_config` JSON NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_business_gateway` (`business_id`, `gateway_code`),
            INDEX `idx_payment_integ_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    /* =========================================================================
       ADDITIVE MULTI-TENANT & BUSINESS_ID COLUMNS (SAFE & NON-BREAKING)
       ========================================================================= */

    $tenantTables = [
        'users', 'categories', 'products', 'inventory_movements', 'customers',
        'registers', 'register_sessions', 'orders', 'invoices', 'held_sales',
        'returns', 'credit_notes', 'vendors', 'purchase_orders', 'stock_counts',
        'store_settings', 'outlets', 'warehouses', 'stock_transfers', 'product_variants',
        'price_lists', 'customer_groups', 'promotions', 'coupons', 'loyalty_transactions',
        'role_permissions', 'purchase_returns', 'vendor_payments', 'product_serials',
        'product_batches', 'channel_sync_logs', 'audit_logs', 'gst_settings',
        'tax_rates', 'business_profile', 'shipping_integrations', 'ecommerce_integrations',
        'payment_options', 'roles', 'payments', 'payment_integrations',
        'purchase_receives', 'purchase_bills'
    ];

    foreach ($tenantTables as $tTable) {
        add_column_if_not_exists($pdo, $tTable, 'business_id', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`");
        // Ensure index on business_id
        try {
            $pdo->exec("ALTER TABLE `{$tTable}` ADD INDEX `idx_{$tTable}_business` (`business_id`)");
        } catch (Exception $ign) {}
    }

    add_column_if_not_exists($pdo, 'users', 'role', "VARCHAR(50) NOT NULL DEFAULT 'Admin' AFTER `password`");
    add_column_if_not_exists($pdo, 'products', 'product_type', "ENUM('simple', 'variable', 'composite') NOT NULL DEFAULT 'simple' AFTER `category_id`");
    add_column_if_not_exists($pdo, 'products', 'hsn_code', "VARCHAR(50) NULL AFTER `barcode`");
    add_column_if_not_exists($pdo, 'products', 'has_serials', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `low_stock_threshold`");
    add_column_if_not_exists($pdo, 'products', 'has_batches', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `has_serials`");

    add_column_if_not_exists($pdo, 'customers', 'customer_group_id', "INT UNSIGNED NULL AFTER `name`");
    add_column_if_not_exists($pdo, 'customers', 'loyalty_points_balance', "INT NOT NULL DEFAULT 0 AFTER `address`");
    add_column_if_not_exists($pdo, 'customers', 'credit_limit', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `loyalty_points_balance`");
    add_column_if_not_exists($pdo, 'customers', 'outstanding_receivable', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `credit_limit`");
    add_column_if_not_exists($pdo, 'customers', 'password', "VARCHAR(255) NULL");

    add_column_if_not_exists($pdo, 'orders', 'outlet_id', "INT UNSIGNED NULL AFTER `id`");
    add_column_if_not_exists($pdo, 'orders', 'fulfillment_status', "ENUM('pending', 'confirmed', 'packed', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled', 'returned') NOT NULL DEFAULT 'delivered' AFTER `order_status`");
    add_column_if_not_exists($pdo, 'orders', 'price_list_id', "INT UNSIGNED NULL AFTER `discount_type`");
    add_column_if_not_exists($pdo, 'orders', 'coupon_id', "INT UNSIGNED NULL AFTER `price_list_id`");
    add_column_if_not_exists($pdo, 'orders', 'coupon_code', "VARCHAR(50) NULL AFTER `coupon_id`");
    add_column_if_not_exists($pdo, 'orders', 'loyalty_points_used', "INT NOT NULL DEFAULT 0 AFTER `coupon_code`");
    add_column_if_not_exists($pdo, 'orders', 'loyalty_discount_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `loyalty_points_used`");
    add_column_if_not_exists($pdo, 'orders', 'client_order_uuid', "VARCHAR(100) NULL UNIQUE AFTER `notes`");

    add_column_if_not_exists($pdo, 'order_items', 'variant_id', "INT UNSIGNED NULL AFTER `product_id`");
    add_column_if_not_exists($pdo, 'order_items', 'hsn_code', "VARCHAR(50) NULL AFTER `product_sku`");

    add_column_if_not_exists($pdo, 'product_variants', 'business_id', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`");
    add_column_if_not_exists($pdo, 'product_variants', 'attribute_values', "JSON NULL AFTER `variant_name`");

    add_column_if_not_exists($pdo, 'invoices', 'outlet_id', "INT UNSIGNED NULL AFTER `id`");
    add_column_if_not_exists($pdo, 'invoices', 'vehicle_number', "VARCHAR(50) NULL AFTER `notes`");

    // Multi-tenant Unique Indexes for invoices, orders, credit_notes, purchase_orders
    try {
        $idxs = $pdo->query("SHOW INDEX FROM invoices WHERE Key_name = 'invoice_number' AND Non_unique = 0")->fetchAll();
        if (!empty($idxs)) {
            $pdo->exec("ALTER TABLE invoices DROP INDEX `invoice_number`");
        }
        $chk = $pdo->query("SHOW INDEX FROM invoices WHERE Key_name = 'uk_business_invoice_number'")->fetchAll();
        if (empty($chk)) {
            $pdo->exec("ALTER TABLE invoices ADD UNIQUE KEY `uk_business_invoice_number` (`business_id`, `invoice_number`)");
        }
    } catch (Exception $ign) {}

    try {
        $idxs = $pdo->query("SHOW INDEX FROM orders WHERE Key_name = 'order_number' AND Non_unique = 0")->fetchAll();
        if (!empty($idxs)) {
            $pdo->exec("ALTER TABLE orders DROP INDEX `order_number`");
        }
        $chk = $pdo->query("SHOW INDEX FROM orders WHERE Key_name = 'uk_business_order_number'")->fetchAll();
        if (empty($chk)) {
            $pdo->exec("ALTER TABLE orders ADD UNIQUE KEY `uk_business_order_number` (`business_id`, `order_number`)");
        }
    } catch (Exception $ign) {}

    try {
        $idxs = $pdo->query("SHOW INDEX FROM credit_notes WHERE Key_name = 'credit_note_number' AND Non_unique = 0")->fetchAll();
        if (!empty($idxs)) {
            $pdo->exec("ALTER TABLE credit_notes DROP INDEX `credit_note_number`");
        }
        $chk = $pdo->query("SHOW INDEX FROM credit_notes WHERE Key_name = 'uk_business_credit_note_number'")->fetchAll();
        if (empty($chk)) {
            $pdo->exec("ALTER TABLE credit_notes ADD UNIQUE KEY `uk_business_credit_note_number` (`business_id`, `credit_note_number`)");
        }
    } catch (Exception $ign) {}

    try {
        $idxs = $pdo->query("SHOW INDEX FROM purchase_orders WHERE Key_name = 'po_number' AND Non_unique = 0")->fetchAll();
        if (!empty($idxs)) {
            $pdo->exec("ALTER TABLE purchase_orders DROP INDEX `po_number`");
        }
        $chk = $pdo->query("SHOW INDEX FROM purchase_orders WHERE Key_name = 'uk_business_po_number'")->fetchAll();
        if (empty($chk)) {
            $pdo->exec("ALTER TABLE purchase_orders ADD UNIQUE KEY `uk_business_po_number` (`business_id`, `po_number`)");
        }
    } catch (Exception $ign) {}

    /* =========================================================================
       ONLINE STORE + CUSTOM DOMAIN (ZOHO POS PARITY) — ADDITIVE
       ========================================================================= */
    add_column_if_not_exists($pdo, 'businesses', 'store_slug', "VARCHAR(80) NULL");
    add_column_if_not_exists($pdo, 'businesses', 'store_published', "TINYINT(1) NOT NULL DEFAULT 1");
    add_column_if_not_exists($pdo, 'orders', 'sales_channel', "VARCHAR(30) NOT NULL DEFAULT 'pos'");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mobile_store_settings` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL,
            `display_name` VARCHAR(191) NULL,
            `logo_path` VARCHAR(255) NULL,
            `header_color` VARCHAR(20) NOT NULL DEFAULT '#0f4c3a',
            `accent_color` VARCHAR(20) NOT NULL DEFAULT '#2563eb',
            `banner_title` VARCHAR(191) NULL,
            `banner_subtitle` VARCHAR(255) NULL,
            `banner_image` VARCHAR(255) NULL,
            `search_placeholder` VARCHAR(191) NULL,
            `show_location` TINYINT(1) NOT NULL DEFAULT 1,
            `show_banner` TINYINT(1) NOT NULL DEFAULT 1,
            `show_categories` TINYINT(1) NOT NULL DEFAULT 1,
            `show_items` TINYINT(1) NOT NULL DEFAULT 1,
            `published_at` TIMESTAMP NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_mobile_store_business` (`business_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    try {
        $pdo->exec("ALTER TABLE `businesses` ADD UNIQUE INDEX `uq_businesses_store_slug` (`store_slug`)");
    } catch (Exception $ign) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `custom_domains` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL,
            `domain` VARCHAR(191) NOT NULL,
            `status` ENUM('pending', 'verified', 'disabled') NOT NULL DEFAULT 'pending',
            `cname_token` VARCHAR(64) NOT NULL,
            `ssl_status` ENUM('none', 'pending', 'active') NOT NULL DEFAULT 'none',
            `verified_at` TIMESTAMP NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_custom_domains_domain` (`domain`),
            INDEX `idx_custom_domains_business` (`business_id`),
            INDEX `idx_custom_domains_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $bizRows = $pdo->query("SELECT id, name, store_slug FROM businesses")->fetchAll();
        foreach ($bizRows as $bizRow) {
            if (!empty($bizRow['store_slug'])) {
                continue;
            }
            $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', (string) ($bizRow['name'] ?? 'store')), '-'));
            if ($base === '') {
                $base = 'store';
            }
            $base = substr($base, 0, 48);
            $slug = $base;
            $n = 2;
            while (true) {
                $chk = $pdo->prepare('SELECT id FROM businesses WHERE store_slug = :s AND id <> :id LIMIT 1');
                $chk->execute(['s' => $slug, 'id' => (int) $bizRow['id']]);
                if (!$chk->fetch()) {
                    break;
                }
                $slug = $base . '-' . $n;
                $n++;
            }
            $pdo->prepare('UPDATE businesses SET store_slug = :s WHERE id = :id')
                ->execute(['s' => $slug, 'id' => (int) $bizRow['id']]);
        }
    } catch (Exception $ign) {}

    /* Zoho-style item fields (additive) */
    add_column_if_not_exists($pdo, 'products', 'item_kind', "ENUM('goods','service') NOT NULL DEFAULT 'goods'");
    add_column_if_not_exists($pdo, 'products', 'brand', "VARCHAR(120) NULL");
    add_column_if_not_exists($pdo, 'products', 'manufacturer', "VARCHAR(120) NULL");
    add_column_if_not_exists($pdo, 'products', 'tax_preference', "ENUM('taxable','non_taxable') NOT NULL DEFAULT 'taxable'");
    add_column_if_not_exists($pdo, 'products', 'unit', "VARCHAR(30) NOT NULL DEFAULT 'pcs'");
    add_column_if_not_exists($pdo, 'products', 'description', "TEXT NULL");
    add_column_if_not_exists($pdo, 'products', 'mrp', "DECIMAL(10,2) NULL");
    add_column_if_not_exists($pdo, 'products', 'sales_enabled', "TINYINT(1) NOT NULL DEFAULT 1");
    add_column_if_not_exists($pdo, 'products', 'purchase_enabled', "TINYINT(1) NOT NULL DEFAULT 1");
    add_column_if_not_exists($pdo, 'products', 'track_inventory', "TINYINT(1) NOT NULL DEFAULT 1");
    add_column_if_not_exists($pdo, 'products', 'returnable', "TINYINT(1) NOT NULL DEFAULT 1");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_images` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `product_id` INT UNSIGNED NOT NULL,
            `kind` ENUM('front','rear','other') NOT NULL DEFAULT 'other',
            `path` VARCHAR(255) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_product_images_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_brands` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `kind` ENUM('brand','manufacturer') NOT NULL DEFAULT 'brand',
            `name` VARCHAR(120) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_product_brands` (`business_id`, `kind`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /* =========================================================================
       DEFAULT SEEDING FOR MULTI-OUTLET, WAREHOUSES & CUSTOMER GROUPS
       ========================================================================= */

    // 1. Seed Main Outlet if empty
    $stmtOutlet = $pdo->query("SELECT id FROM outlets WHERE id = 1 LIMIT 1");
    if (!$stmtOutlet->fetch()) {
        $pdo->exec("
            INSERT INTO `outlets` (`id`, `name`, `code`, `address`, `phone`, `email`, `gstin`, `status`, `created_at`, `updated_at`)
            VALUES (1, 'Main Store Outlet', 'OUT-MAIN', 'Plot No. 42, Tech Park, Sector 5, Bangalore', '+91 98765 43210', 'outlet1@ominiflow.com', '29ABCDE1234F1Z5', 'active', NOW(), NOW())
        ");
    }

    // 2. Seed Central Warehouse if empty
    $stmtWH = $pdo->query("SELECT id FROM warehouses WHERE id = 1 LIMIT 1");
    if (!$stmtWH->fetch()) {
        $pdo->exec("
            INSERT INTO `warehouses` (`id`, `outlet_id`, `name`, `code`, `location`, `status`, `created_at`, `updated_at`)
            VALUES (1, 1, 'Central Warehouse', 'WH-CENTRAL', 'Bangalore Hub Floor 1', 'active', NOW(), NOW())
        ");
    }

    // 3. Seed Default Customer Groups
    $stmtCG = $pdo->query("SELECT id FROM customer_groups LIMIT 1");
    if (!$stmtCG->fetch()) {
        $pdo->exec("
            INSERT INTO `customer_groups` (`id`, `name`, `code`, `discount_percent`, `credit_limit`, `created_at`, `updated_at`) VALUES
            (1, 'Retail Customer', 'RETAIL', 0.00, 5000.00, NOW(), NOW()),
            (2, 'Wholesale Customer', 'WHOLESALE', 10.00, 50000.00, NOW(), NOW()),
            (3, 'VIP Gold Club', 'VIP_GOLD', 15.00, 25000.00, NOW(), NOW())
        ");
    }

    // 4. Seed Default Price Lists
    $stmtPL = $pdo->query("SELECT id FROM price_lists LIMIT 1");
    if (!$stmtPL->fetch()) {
        $pdo->exec("
            INSERT INTO `price_lists` (`id`, `name`, `code`, `type`, `percentage_value`, `status`, `created_at`, `updated_at`) VALUES
            (1, 'Standard Retail Price', 'RETAIL_STD', 'fixed', 0.00, 'active', NOW(), NOW()),
            (2, 'Wholesale 10% Off', 'WHOLESALE_10', 'percentage', 10.00, 'active', NOW(), NOW()),
            (3, 'VIP Club 15% Off', 'VIP_15', 'percentage', 15.00, 'active', NOW(), NOW())
        ");
    }

    // 5. Seed default warehouse stock for existing active products
    $pdo->exec("
        INSERT IGNORE INTO `warehouse_stock` (`warehouse_id`, `product_id`, `stock_quantity`, `created_at`, `updated_at`)
        SELECT 1, p.id, p.stock_quantity, NOW(), NOW() FROM `products` p
    ");

    // 6. Dynamic Invoice Branding & Bank Details Migration (Zoho POS / Multi-Vendor Parity)
    $helperAddCol = function(PDO $p, string $tbl, string $col, string $def) {
        try {
            $st = $p->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col");
            $st->execute(['db' => DB_NAME, 'tbl' => $tbl, 'col' => $col]);
            if ((int)$st->fetchColumn() === 0) {
                $p->exec("ALTER TABLE `{$tbl}` ADD `{$col}` {$def}");
            }
        } catch (Exception $e) {}
    };

    $helperAddCol($pdo, 'store_settings', 'legal_name', "VARCHAR(191) NULL");
    $helperAddCol($pdo, 'store_settings', 'city', "VARCHAR(100) NULL");
    $helperAddCol($pdo, 'store_settings', 'state', "VARCHAR(100) NULL");
    $helperAddCol($pdo, 'store_settings', 'pincode', "VARCHAR(20) NULL");
    $helperAddCol($pdo, 'store_settings', 'pan_number', "VARCHAR(50) NULL");
    $helperAddCol($pdo, 'store_settings', 'bank_name', "VARCHAR(100) NULL DEFAULT 'HDFC Bank'");
    $helperAddCol($pdo, 'store_settings', 'account_holder', "VARCHAR(191) NULL DEFAULT 'Ominiflow Enterprises'");
    $helperAddCol($pdo, 'store_settings', 'account_number', "VARCHAR(50) NULL DEFAULT '50200111653091'");
    $helperAddCol($pdo, 'store_settings', 'bank_ifsc', "VARCHAR(30) NULL DEFAULT 'HDFC0000887'");
    $helperAddCol($pdo, 'store_settings', 'bank_branch', "VARCHAR(100) NULL DEFAULT 'DEWAS'");
    $helperAddCol($pdo, 'store_settings', 'account_type', "VARCHAR(50) NULL DEFAULT 'Current Account'");
    $helperAddCol($pdo, 'store_settings', 'upi_id', "VARCHAR(100) NULL");
    $helperAddCol($pdo, 'store_settings', 'terms_conditions', "TEXT NULL");
    $helperAddCol($pdo, 'store_settings', 'privacy_policy', "MEDIUMTEXT NULL");
    $helperAddCol($pdo, 'store_settings', 'package_name', "VARCHAR(100) NULL DEFAULT 'Monthly'");

    $helperAddCol($pdo, 'business_profile', 'business_id', 'INT UNSIGNED NULL');
    $helperAddCol($pdo, 'businesses', 'organization_id', 'VARCHAR(50) NULL');
    $helperAddCol($pdo, 'business_profile', 'bank_name', "VARCHAR(100) NULL DEFAULT 'HDFC Bank'");
    $helperAddCol($pdo, 'business_profile', 'account_holder', "VARCHAR(191) NULL DEFAULT 'Ominiflow Enterprises'");
    $helperAddCol($pdo, 'business_profile', 'account_number', "VARCHAR(50) NULL DEFAULT '50200111653091'");
    $helperAddCol($pdo, 'business_profile', 'bank_ifsc', "VARCHAR(30) NULL DEFAULT 'HDFC0000887'");
    $helperAddCol($pdo, 'business_profile', 'bank_branch', "VARCHAR(100) NULL DEFAULT 'DEWAS'");
    $helperAddCol($pdo, 'business_profile', 'account_type', "VARCHAR(50) NULL DEFAULT 'Current Account'");
    $helperAddCol($pdo, 'business_profile', 'upi_id', "VARCHAR(100) NULL");
    $helperAddCol($pdo, 'business_profile', 'terms_conditions', "TEXT NULL");
    $helperAddCol($pdo, 'business_profile', 'privacy_policy', "MEDIUMTEXT NULL");
    $helperAddCol($pdo, 'business_profile', 'package_name', "VARCHAR(100) NULL DEFAULT 'Monthly'");

    require_once __DIR__ . '/../includes/organization_ids.php';
    ensure_pos_organization_ids($pdo);

    // Mobile Store Payment Preferences Columns
    $helperAddCol($pdo, 'mobile_store_settings', 'enable_cod', "TINYINT(1) NOT NULL DEFAULT 1");
    $helperAddCol($pdo, 'mobile_store_settings', 'enable_upi', "TINYINT(1) NOT NULL DEFAULT 1");
    $helperAddCol($pdo, 'mobile_store_settings', 'enable_card', "TINYINT(1) NOT NULL DEFAULT 1");
    $helperAddCol($pdo, 'mobile_store_settings', 'enable_netbanking', "TINYINT(1) NOT NULL DEFAULT 1");
    $helperAddCol($pdo, 'mobile_store_settings', 'enable_store_pickup_payment', "TINYINT(1) NOT NULL DEFAULT 1");
    $helperAddCol($pdo, 'mobile_store_settings', 'upi_id', "VARCHAR(100) NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'payment_instructions', "TEXT NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'show_home_hero_banner', "TINYINT(1) NOT NULL DEFAULT 1");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_banner', "VARCHAR(255) NULL DEFAULT NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_banner_link', "VARCHAR(255) NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_banner_2', "VARCHAR(255) NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_banner_link_2', "VARCHAR(255) NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_banner_3', "VARCHAR(255) NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_banner_link_3', "VARCHAR(255) NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_banner_4', "VARCHAR(255) NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_banner_link_4', "VARCHAR(255) NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_banner_5', "VARCHAR(255) NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_banner_link_5', "VARCHAR(255) NULL");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_autoplay', "TINYINT(1) NOT NULL DEFAULT 1");
    $helperAddCol($pdo, 'mobile_store_settings', 'home_hero_autoplay_speed', "INT NOT NULL DEFAULT 4000");


    if (php_sapi_name() === 'cli') {
        echo "SUCCESS: Database `ominiflow_pos` Multi-Tenant businesses and tables migrated successfully.\n";
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>Migration Successful — OminiFlow POS</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
                .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 40px; max-width: 540px; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
                .icon { width: 64px; height: 64px; background: #059669; color: #fff; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px; font-size: 32px; }
                h1 { margin: 0 0 10px; font-size: 24px; color: #fff; }
                p { color: #94a3b8; font-size: 15px; line-height: 1.5; margin: 0 0 28px; }
                .btn { display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 12px 28px; border-radius: 10px; font-weight: 600; font-size: 15px; transition: background 0.2s; }
                .btn:hover { background: #1d4ed8; }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="icon">✓</div>
                <h1>Database Migration Completed!</h1>
                <p>All 37 multi-tenant tables, <code>businesses</code> organization schema, and <code>business_id</code> columns have been configured successfully.</p>
                <a href="../login.php" class="btn">Go to Login / Dashboard →</a>
            </div>
        </body>
        </html>';
    }
} catch (PDOException $e) {
    if (php_sapi_name() === 'cli') {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
    echo '<div style="font-family:sans-serif;background:#fee2e2;color:#991b1b;padding:24px;border-radius:12px;max-width:600px;margin:50px auto;border:1px solid #f87171;">
        <h2 style="margin-top:0;">Migration Error</h2>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
    </div>';
    exit(1);
}

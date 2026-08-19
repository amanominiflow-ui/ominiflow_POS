<?php
/**
 * Master Migration runner for OminiFlow POS (Zoho POS Feature Parity)
 * Initializes all core, sales, purchases, inventory, registers, and payments tables.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

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

    // 1. Users Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(191) NOT NULL,
            `email` VARCHAR(191) NOT NULL UNIQUE,
            `phone` VARCHAR(50) NULL,
            `password` VARCHAR(255) NOT NULL,
            `role` ENUM('admin', 'manager', 'cashier') NOT NULL DEFAULT 'admin',
            `remember_token` VARCHAR(100) NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
            `store_name` VARCHAR(191) NOT NULL DEFAULT 'OminiFlow Retail POS',
            `tagline` VARCHAR(255) NULL DEFAULT 'Official Retail Store & POS Terminal',
            `logo_path` VARCHAR(255) NULL DEFAULT 'assets/images/logo.jpg',
            `address` TEXT NULL,
            `phone` VARCHAR(50) NULL DEFAULT '+91 98765 43210',
            `email` VARCHAR(191) NULL DEFAULT 'pos@ominiflow.com',
            `gstin` VARCHAR(50) NULL DEFAULT '29ABCDE1234F1Z5',
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

    /* =========================================================================
       ADDITIVE COLUMN EXTENSIONS FOR EXISTING TABLES (SAFE & NON-BREAKING)
       ========================================================================= */

    function add_column_if_not_exists(PDO $pdo, string $table, string $column, string $typeSql): void {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $stmt->execute(['col' => $column]);
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD `{$column}` {$typeSql}");
        }
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

    add_column_if_not_exists($pdo, 'invoices', 'outlet_id', "INT UNSIGNED NULL AFTER `id`");
    add_column_if_not_exists($pdo, 'invoices', 'vehicle_number', "VARCHAR(50) NULL AFTER `notes`");

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

    if (php_sapi_name() === 'cli') {
        echo "SUCCESS: Database `ominiflow_pos` Phase 2 Advanced Zoho POS tables and columns migrated successfully.\n";
    }
} catch (PDOException $e) {
    if (php_sapi_name() === 'cli') {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
    throw $e;
}

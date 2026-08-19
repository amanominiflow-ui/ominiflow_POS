-- Database Schema for OminiFlow POS (Independent Application)
-- Database Name: ominiflow_pos

CREATE DATABASE IF NOT EXISTS `ominiflow_pos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `ominiflow_pos`;

-- 1. Users Table
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

-- 2. Categories Table
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

-- 3. Products Table
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

-- 4. Inventory Movements Table
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

-- 5. Customers Table
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

-- 6. Registers Table
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

-- 7. Register Sessions Table (Shift / Cash Drawer Management)
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

-- 8. Orders Table
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

-- 9. Order Items Table
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

-- 10. Invoices Table
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

-- 11. Payments Table (Centralized Multi-Tender / Split Payment Ledger)
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

-- 12. Held Sales Table
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

-- 13. Returns Table
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

-- 14. Return Items Table
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

-- 15. Credit Notes Table
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

-- 16. Vendors Table
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

-- 17. Purchase Orders Table
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

-- 18. Purchase Order Items Table
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

-- 19. Stock Counts Table (Physical Inventory Audits)
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

-- 20. Stock Count Items Table
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

-- 21. Store Settings Table
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

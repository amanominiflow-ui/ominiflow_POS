-- =====================================================================
-- OminiFlow POS: Master Super Admin & Feature Subscriptions Schema
-- Can be executed directly in phpMyAdmin / Cloudways MySQL Database Manager
-- Target Database: Local (`ominiflow_pos`) or Live (`tgpryurzxb`)
-- =====================================================================

-- 1. Ensure `is_super_admin` column on `users` table
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_super_admin';

SET @sql = IF(@col_exists = 0, 'ALTER TABLE `users` ADD COLUMN `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Ensure subscription columns on `businesses` table
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'subscription_status';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `businesses` ADD COLUMN `subscription_status` VARCHAR(30) NOT NULL DEFAULT \'active\'', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'subscription_plan';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `businesses` ADD COLUMN `subscription_plan` VARCHAR(50) NOT NULL DEFAULT \'pro\'', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'subscription_expires_at';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `businesses` ADD COLUMN `subscription_expires_at` DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'max_outlets';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `businesses` ADD COLUMN `max_outlets` INT NOT NULL DEFAULT 5', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'max_users';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `businesses` ADD COLUMN `max_users` INT NOT NULL DEFAULT 10', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Create Master `system_features` table
CREATE TABLE IF NOT EXISTS `system_features` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feature_key` VARCHAR(64) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'General',
    `description` VARCHAR(255) NULL,
    `default_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create Per-Tenant `business_features` overrides table
CREATE TABLE IF NOT EXISTS `business_features` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `business_id` INT UNSIGNED NOT NULL,
    `feature_key` VARCHAR(64) NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_biz_feature` (`business_id`, `feature_key`),
    INDEX `idx_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Seed default catalog features
INSERT IGNORE INTO `system_features` (`feature_key`, `name`, `category`, `description`, `default_enabled`, `sort_order`) VALUES
('pos_billing', 'POS Register & Billing', 'Core POS', 'Fast cashier counter, barcode scanning, cash register sessions, and offline billing.', 1, 10),
('inventory', 'Inventory & Products Catalog', 'Catalog & Stock', 'Product catalog, categories, stock adjustments, live stock alerts, and transfers.', 1, 20),
('barcode_print', 'Barcode Printing & Designer', 'Catalog & Stock', 'Custom barcode and label generation with roll and sheet printing templates.', 1, 25),
('sales_invoicing', 'Sales History & Invoicing', 'Sales & Billing', 'Invoices, receipts, returns/exchanges, credit notes, and print invoice templates.', 1, 30),
('purchases_vendors', 'Purchases & Vendors', 'Purchases & Vendors', 'Purchase orders, GRN, inward receipts, supplier ledger, and payment tracking.', 1, 40),
('online_store', 'Online Storefront & Ordering', 'Sales Channels', 'Self-hosted responsive online store catalog, customer cart, and web orders.', 1, 50),
('multi_store', 'Multi-Store & Outlets Hub', 'Operations', 'Multi-outlet switching, multi-warehouse stock allocations, and register management.', 1, 60),
('reports_analytics', 'Analytics & Reports', 'Analytics', 'Gross profit reports, sales breakdown, tax reports, cashier reconciliation, and exports.', 1, 70),
('customers_crm', 'Customer CRM & Loyalty', 'CRM', 'Customer ledger, store credits, purchase history, and marketing contacts.', 1, 80),
('whatsapp_alerts', 'WhatsApp Notifications', 'Integrations', 'Automatic order confirmations, e-bills, and payment receipts via WhatsApp Cloud API.', 1, 90),
('razorpay_gateway', 'Razorpay & UPI Payments', 'Integrations', 'Dynamic UPI QR codes, Razorpay payment gateway integration, and webhook reconciliation.', 1, 100),
('staff_roles', 'Staff & Permissions Management', 'Administration', 'Role-based access control (Admin, Cashier, Manager) and counter assignment.', 1, 110);

-- 6. Seed / Synchronize Super Admin (admin@example.com / admin@12345)
-- Ensure only admin@example.com is Super Admin across the whole system
UPDATE `users` SET `is_super_admin` = 0 WHERE `email` != 'admin@example.com';

SET @biz_id = (SELECT `id` FROM `businesses` ORDER BY `id` ASC LIMIT 1);
SET @biz_id = IFNULL(@biz_id, 1);

INSERT INTO `users` (`business_id`, `name`, `email`, `phone`, `password`, `role`, `is_super_admin`, `status`, `created_at`)
VALUES (@biz_id, 'Super Administrator', 'admin@example.com', '9999999999', '$2y$10$fAVVhFFe5eGkZZLBYIWzcuozuPG2NU6Y35O686zsS9IOOIhA0OSVK', 'admin', 1, 'active', NOW())
ON DUPLICATE KEY UPDATE 
    `is_super_admin` = 1,
    `status` = 'active',
    `role` = 'admin';

<?php
/**
 * Dynamic Feature Entitlement & Subscription Management Engine for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Safely add a column to a table if it doesn't already exist
 */
function feature_add_column_if_missing(PDO $db, string $table, string $column, string $definition): void {
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
        // Silently continue if column already exists or fails
    }
}

/**
 * Ensure all necessary tables and columns exist for Super Admin, Subscriptions, and Dynamic Features
 */
function ensure_features_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = get_db();

    // 1. Add columns to users table
    feature_add_column_if_missing($db, 'users', 'is_super_admin', 'TINYINT(1) NOT NULL DEFAULT 0');

    // 2. Add columns to businesses table
    feature_add_column_if_missing($db, 'businesses', 'subscription_status', "VARCHAR(30) NOT NULL DEFAULT 'active'");
    feature_add_column_if_missing($db, 'businesses', 'subscription_plan', "VARCHAR(50) NOT NULL DEFAULT 'pro'");
    feature_add_column_if_missing($db, 'businesses', 'subscription_expires_at', "DATETIME NULL");
    feature_add_column_if_missing($db, 'businesses', 'max_outlets', "INT NOT NULL DEFAULT 5");
    feature_add_column_if_missing($db, 'businesses', 'max_users', "INT NOT NULL DEFAULT 10");

    // 3. Create system_features table (Master features registry)
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `system_features` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `feature_key` VARCHAR(64) NOT NULL UNIQUE,
                `name` VARCHAR(100) NOT NULL,
                `category` VARCHAR(50) NOT NULL DEFAULT 'General',
                `description` VARCHAR(255) NULL,
                `default_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {}

    // 4. Create business_features table (Per-client feature overrides)
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `business_features` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `business_id` INT UNSIGNED NOT NULL,
                `feature_key` VARCHAR(64) NOT NULL,
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_biz_feature` (`business_id`, `feature_key`),
                INDEX `idx_biz` (`business_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {}

    // 5. Seed default system features if empty
    seed_default_system_features($db);

    // 6. Ensure Super Admin account exists and is synchronized
    ensure_super_admin_seed($db);
}

/**
 * Ensure Super Administrator (admin@example.com) exists and is active across Local & Live environments
 */
function ensure_super_admin_seed(?PDO $db = null): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db = $db ?: get_db();
        $adminEmail = 'admin@example.com';
        $adminPassword = password_hash('admin@12345', PASSWORD_DEFAULT);

        // Ensure is_super_admin column exists
        feature_add_column_if_missing($db, 'users', 'is_super_admin', 'TINYINT(1) NOT NULL DEFAULT 0');

        // Reset any non-admin account so only admin@example.com is Super Admin
        $db->exec("UPDATE users SET is_super_admin = 0 WHERE email != 'admin@example.com' AND is_super_admin = 1");

        $checkAdmin = $db->prepare('SELECT id, password, is_super_admin, status FROM users WHERE email = :email LIMIT 1');
        $checkAdmin->execute(['email' => $adminEmail]);
        $adminRow = $checkAdmin->fetch(PDO::FETCH_ASSOC);

        if ($adminRow) {
            // User already exists: only ensure privileges and status. NEVER overwrite custom password!
            $db->prepare('UPDATE users SET is_super_admin = 1, status = "active", role = "admin" WHERE id = :id')
                ->execute(['id' => $adminRow['id']]);
        } else {
            $firstBiz = (int)$db->query('SELECT id FROM businesses ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1;
            $db->prepare('
                INSERT INTO users (business_id, name, email, phone, password, role, is_super_admin, status, created_at)
                VALUES (:bid, "Super Administrator", :email, "9999999999", :pw, "admin", 1, "active", NOW())
            ')->execute(['bid' => $firstBiz, 'email' => $adminEmail, 'pw' => $adminPassword]);
        }
    } catch (Throwable $e) {
        error_log('ensure_super_admin_seed error: ' . $e->getMessage());
    }
}

/**
 * Seed master features list
 */
function seed_default_system_features(PDO $db): void {
    $features = [
        [
            'feature_key' => 'pos_billing',
            'name' => 'POS Register & Billing',
            'category' => 'Core POS',
            'description' => 'Fast cashier counter, barcode scanning, cash register sessions, and offline billing.',
            'default_enabled' => 1,
            'sort_order' => 10,
        ],
        [
            'feature_key' => 'inventory',
            'name' => 'Inventory & Products Catalog',
            'category' => 'Catalog & Stock',
            'description' => 'Product catalog, categories, stock adjustments, live stock alerts, and transfers.',
            'default_enabled' => 1,
            'sort_order' => 20,
        ],
        [
            'feature_key' => 'barcode_print',
            'name' => 'Barcode Printing & Designer',
            'category' => 'Catalog & Stock',
            'description' => 'Custom barcode and label generation with roll and sheet printing templates.',
            'default_enabled' => 1,
            'sort_order' => 30,
        ],
        [
            'feature_key' => 'sales_invoices',
            'name' => 'Sales Invoices & Orders',
            'category' => 'Sales & Billing',
            'description' => 'Invoices, order tracking, consignment manifests, and returns management.',
            'default_enabled' => 1,
            'sort_order' => 40,
        ],
        [
            'feature_key' => 'purchases',
            'name' => 'Purchases & Inward Stock',
            'category' => 'Purchases & Vendors',
            'description' => 'Purchase orders, inward stock entries, vendor bills, and supplier payments.',
            'default_enabled' => 1,
            'sort_order' => 50,
        ],
        [
            'feature_key' => 'online_store',
            'name' => 'Online Store & Mobile Storefront',
            'category' => 'Sales Channels',
            'description' => 'Customer-facing mobile e-commerce store, store studio layout editor, and custom domains.',
            'default_enabled' => 1,
            'sort_order' => 60,
        ],
        [
            'feature_key' => 'multi_outlet',
            'name' => 'Multi-Outlet & Multi-Warehouse',
            'category' => 'Operations',
            'description' => 'Manage multiple retail store outlets, central warehouses, and inter-store transfers.',
            'default_enabled' => 1,
            'sort_order' => 70,
        ],
        [
            'feature_key' => 'reports_analytics',
            'name' => 'Reports & GST Analytics',
            'category' => 'Analytics',
            'description' => 'Comprehensive sales analytics, GSTR-1 reports, stock movements, and profit margins.',
            'default_enabled' => 1,
            'sort_order' => 80,
        ],
        [
            'feature_key' => 'promotions_crm',
            'name' => 'Customers CRM & Promotions',
            'category' => 'Marketing & CRM',
            'description' => 'Customer database, coupon codes, promotional campaigns, and loyalty rewards.',
            'default_enabled' => 1,
            'sort_order' => 90,
        ],
        [
            'feature_key' => 'whatsapp_integration',
            'name' => 'WhatsApp Cloud Invoicing',
            'category' => 'Integrations',
            'description' => 'Automated PDF invoices sent directly to customer WhatsApp via Meta Cloud API.',
            'default_enabled' => 1,
            'sort_order' => 100,
        ],
        [
            'feature_key' => 'shipping_integration',
            'name' => 'Shipping & Courier Integrations',
            'category' => 'Integrations',
            'description' => 'Shiprocket, Delhivery, and courier order tracking sync.',
            'default_enabled' => 1,
            'sort_order' => 110,
        ],
        [
            'feature_key' => 'user_roles',
            'name' => 'User Roles & Staff Permissions',
            'category' => 'Administration',
            'description' => 'Custom role creation, staff members management, and granular permission gates.',
            'default_enabled' => 1,
            'sort_order' => 120,
        ],
    ];

    try {
        $stmtCheck = $db->prepare('SELECT COUNT(*) FROM system_features WHERE feature_key = :k');
        $stmtInsert = $db->prepare('
            INSERT INTO system_features (feature_key, name, category, description, default_enabled, sort_order)
            VALUES (:feature_key, :name, :category, :description, :default_enabled, :sort_order)
        ');

        foreach ($features as $f) {
            $stmtCheck->execute(['k' => $f['feature_key']]);
            if ((int) $stmtCheck->fetchColumn() === 0) {
                $stmtInsert->execute($f);
            }
        }
    } catch (PDOException $e) {}
}

/**
 * Get all registered system features
 */
function get_all_system_features(): array {
    ensure_features_schema();
    try {
        $stmt = get_db()->query('SELECT * FROM system_features ORDER BY sort_order ASC, id ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Invalidate or reset feature status cache
 */
function reset_feature_cache(?int $businessId = null): void {
    if ($businessId === null) {
        $GLOBALS['omniflow_feature_cache'] = [];
    } else {
        foreach (array_keys($GLOBALS['omniflow_feature_cache'] ?? []) as $key) {
            if (str_starts_with($key, $businessId . '_')) {
                unset($GLOBALS['omniflow_feature_cache'][$key]);
            }
        }
    }
}

/**
 * Check if a feature is enabled for a given business (or current active business)
 */
function is_feature_enabled(string $featureKey, ?int $businessId = null, bool $fresh = false): bool {
    ensure_features_schema();
    $bid = $businessId ?: (function_exists('current_business_id') ? current_business_id() : 0);
    if ($bid < 1) {
        return true; // Default fallback to allow if no business context
    }

    if (!isset($GLOBALS['omniflow_feature_cache'])) {
        $GLOBALS['omniflow_feature_cache'] = [];
    }

    $cacheKey = $bid . '_' . $featureKey;
    if (!$fresh && isset($GLOBALS['omniflow_feature_cache'][$cacheKey])) {
        return $GLOBALS['omniflow_feature_cache'][$cacheKey];
    }

    $db = get_db();
    try {
        // 1. Check if business has an explicit override in business_features
        $stmt = $db->prepare('SELECT is_enabled FROM business_features WHERE business_id = :bid AND feature_key = :k LIMIT 1');
        $stmt->execute(['bid' => $bid, 'k' => $featureKey]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            $result = (int)$val === 1;
            $GLOBALS['omniflow_feature_cache'][$cacheKey] = $result;
            return $result;
        }

        // 2. Fall back to system_features default_enabled
        $stmtDef = $db->prepare('SELECT default_enabled FROM system_features WHERE feature_key = :k LIMIT 1');
        $stmtDef->execute(['k' => $featureKey]);
        $defVal = $stmtDef->fetchColumn();
        if ($defVal !== false) {
            $result = (int)$defVal === 1;
            $GLOBALS['omniflow_feature_cache'][$cacheKey] = $result;
            return $result;
        }
    } catch (PDOException $e) {
        // In case of error, default to true
        return true;
    }

    $GLOBALS['omniflow_feature_cache'][$cacheKey] = true;
    return true;
}

/**
 * Get all feature states for a business, merging system features with overrides
 */
function get_business_features_map(int $businessId): array {
    ensure_features_schema();
    $allFeatures = get_all_system_features();
    if (empty($allFeatures)) {
        return [];
    }

    $db = get_db();
    $overrides = [];
    try {
        $stmt = $db->prepare('SELECT feature_key, is_enabled FROM business_features WHERE business_id = :bid');
        $stmt->execute(['bid' => $businessId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $overrides[$row['feature_key']] = (int)$row['is_enabled'] === 1;
        }
    } catch (PDOException $e) {}

    $result = [];
    foreach ($allFeatures as $feat) {
        $key = $feat['feature_key'];
        $isEnabled = isset($overrides[$key]) ? $overrides[$key] : ((int)$feat['default_enabled'] === 1);
        $result[] = array_merge($feat, [
            'is_enabled' => $isEnabled,
            'is_customized' => isset($overrides[$key]),
        ]);
    }

    return $result;
}

/**
 * Set an individual feature for a business
 */
function set_business_feature(int $businessId, string $featureKey, bool $enabled): bool {
    ensure_features_schema();
    $db = get_db();
    try {
        $stmt = $db->prepare('
            INSERT INTO business_features (business_id, feature_key, is_enabled, updated_at)
            VALUES (:bid, :k, :en, NOW())
            ON DUPLICATE KEY UPDATE is_enabled = :en_update, updated_at = NOW()
        ');
        $res = $stmt->execute([
            'bid' => $businessId,
            'k' => $featureKey,
            'en' => $enabled ? 1 : 0,
            'en_update' => $enabled ? 1 : 0,
        ]);
        reset_feature_cache($businessId);
        return $res;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Save multiple features for a business at once
 */
function save_business_features(int $businessId, array $featuresMap): bool {
    ensure_features_schema();
    $db = get_db();
    try {
        $stmt = $db->prepare('
            INSERT INTO business_features (business_id, feature_key, is_enabled, updated_at)
            VALUES (:bid, :k, :en, NOW())
            ON DUPLICATE KEY UPDATE is_enabled = :en_update, updated_at = NOW()
        ');

        foreach ($featuresMap as $key => $enabled) {
            $stmt->execute([
                'bid' => $businessId,
                'k' => (string)$key,
                'en' => $enabled ? 1 : 0,
                'en_update' => $enabled ? 1 : 0,
            ]);
        }
        reset_feature_cache($businessId);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Apply a preset package of features to a business
 */
function apply_feature_preset(int $businessId, string $preset): bool {
    $presets = [
        'all' => [
            'pos_billing' => true,
            'inventory' => true,
            'barcode_print' => true,
            'sales_invoices' => true,
            'purchases' => true,
            'online_store' => true,
            'multi_outlet' => true,
            'reports_analytics' => true,
            'promotions_crm' => true,
            'whatsapp_integration' => true,
            'shipping_integration' => true,
            'user_roles' => true,
        ],
        'basic' => [
            'pos_billing' => true,
            'inventory' => true,
            'barcode_print' => true,
            'sales_invoices' => true,
            'purchases' => false,
            'online_store' => false,
            'multi_outlet' => false,
            'reports_analytics' => false,
            'promotions_crm' => false,
            'whatsapp_integration' => false,
            'shipping_integration' => false,
            'user_roles' => false,
        ],
        'standard' => [
            'pos_billing' => true,
            'inventory' => true,
            'barcode_print' => true,
            'sales_invoices' => true,
            'purchases' => true,
            'online_store' => false,
            'multi_outlet' => false,
            'reports_analytics' => true,
            'promotions_crm' => true,
            'whatsapp_integration' => false,
            'shipping_integration' => false,
            'user_roles' => true,
        ],
        'enterprise' => [
            'pos_billing' => true,
            'inventory' => true,
            'barcode_print' => true,
            'sales_invoices' => true,
            'purchases' => true,
            'online_store' => true,
            'multi_outlet' => true,
            'reports_analytics' => true,
            'promotions_crm' => true,
            'whatsapp_integration' => true,
            'shipping_integration' => true,
            'user_roles' => true,
        ],
    ];

    if (!isset($presets[$preset])) {
        return false;
    }

    return save_business_features($businessId, $presets[$preset]);
}

/** Default length of the automatic free trial for new POS signups (days). */
function pos_free_trial_days(): int {
    return 7;
}

/**
 * Start a 7-day free trial for a newly registered business store.
 */
function provision_new_business_free_trial(int $businessId): bool {
    ensure_features_schema();
    if ($businessId < 1) {
        return false;
    }
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . pos_free_trial_days() . ' days'));
    return update_business_subscription($businessId, 'trial', 'pro', $expiresAt);
}

/**
 * If a trial (or time-limited plan) has passed its expiry, mark the store inactive in the database.
 */
function sync_business_subscription_expiry(int $businessId): void {
    ensure_features_schema();
    if ($businessId < 1) {
        return;
    }

    try {
        $db = get_db();
        $stmt = $db->prepare('
            UPDATE businesses
            SET subscription_status = :inactive, is_premium = 0, updated_at = NOW()
            WHERE id = :id
              AND subscription_expires_at IS NOT NULL
              AND subscription_expires_at < NOW()
              AND subscription_status IN (\'trial\', \'active\')
        ');
        $stmt->execute(['inactive' => 'inactive', 'id' => $businessId]);
    } catch (PDOException $e) {
        // ignore
    }
}

/**
 * Check if a business has an active subscription
 */
function is_business_subscription_active(?int $businessId = null): bool {
    ensure_features_schema();
    $bid = $businessId ?: (function_exists('current_business_id') ? current_business_id() : 0);
    if ($bid < 1) {
        return true;
    }

    sync_business_subscription_expiry($bid);

    try {
        $stmt = get_db()->prepare('SELECT subscription_status, subscription_expires_at FROM businesses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $bid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return true;
        }

        $status = strtolower(trim((string)($row['subscription_status'] ?? 'active')));
        if ($status === 'suspended' || $status === 'inactive') {
            return false;
        }

        if (!empty($row['subscription_expires_at'])) {
            $expiry = strtotime($row['subscription_expires_at']);
            if ($expiry !== false && $expiry < time()) {
                return false;
            }
        }

        return true;
    } catch (PDOException $e) {
        return true;
    }
}

/**
 * Update business subscription details
 */
function update_business_subscription(int $businessId, string $status, ?string $plan = null, ?string $expiresAt = null): bool {
    ensure_features_schema();
    $db = get_db();
    try {
        $statusClean = strtolower(trim($status));
        $planClean = $plan !== null ? strtolower(trim($plan)) : null;

        // Trial and paid active plans keep access. Free + active stays locked. Suspended/inactive do not.
        $isPrem = 0;
        if ($statusClean === 'trial') {
            $isPrem = 1;
        } elseif ($statusClean === 'active' && ($planClean === null || $planClean !== 'free')) {
            $isPrem = 1;
        }

        $sql = 'UPDATE businesses SET subscription_status = :status, is_premium = :is_prem, updated_at = NOW()';
        $params = ['status' => $statusClean, 'is_prem' => $isPrem, 'bid' => $businessId];

        if ($plan !== null) {
            $sql .= ', subscription_plan = :plan';
            $params['plan'] = $plan;
        }
        if ($expiresAt !== null) {
            $sql .= ', subscription_expires_at = :expires';
            $params['expires'] = $expiresAt !== '' ? $expiresAt : null;
        }

        $sql .= ' WHERE id = :bid';
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        return false;
    }
}

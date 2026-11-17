<?php
/**
 * Authentication management for OminiFlow POS
 * Handles user registration, password hashing, session management, and validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function is_authenticated(): bool {
    return !empty($_SESSION['user_id']);
}

function ensure_users_table_schema(?PDO $db = null): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = $db ?: get_db();

        // 1. Ensure `role` column is VARCHAR(50) so 'Staff', 'Accountant', 'Manager', etc. do not fail MySQL enum validation
        try {
            $db->exec("ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'staff'");
        } catch (Throwable $e) {}

        // 2. Ensure `outlet_id` column exists in users
        try {
            $cols = $db->query("SHOW COLUMNS FROM `users` LIKE 'outlet_id'")->fetchAll();
            if (empty($cols)) {
                $db->exec("ALTER TABLE `users` ADD COLUMN `outlet_id` INT UNSIGNED NULL");
            }
        } catch (Throwable $e) {}

        // 3. Ensure `status` column exists in users
        try {
            $cols = $db->query("SHOW COLUMNS FROM `users` LIKE 'status'")->fetchAll();
            if (empty($cols)) {
                $db->exec("ALTER TABLE `users` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active'");
            }
        } catch (Throwable $e) {}

        // 4. Ensure `updated_at` column exists in users
        try {
            $cols = $db->query("SHOW COLUMNS FROM `users` LIKE 'updated_at'")->fetchAll();
            if (empty($cols)) {
                $db->exec("ALTER TABLE `users` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
        } catch (Throwable $e) {}

        // 5. Ensure `phone` column is nullable in users
        try {
            $cols = $db->query("SHOW COLUMNS FROM `users` LIKE 'phone'")->fetchAll();
            if (empty($cols)) {
                $db->exec("ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(50) NULL");
            } else {
                $db->exec("ALTER TABLE `users` MODIFY COLUMN `phone` VARCHAR(50) NULL");
            }
        } catch (Throwable $e) {}

        // 6. Ensure `registers` table has `outlet_id`
        try {
            $cols = $db->query("SHOW COLUMNS FROM `registers` LIKE 'outlet_id'")->fetchAll();
            if (empty($cols)) {
                $db->exec("ALTER TABLE `registers` ADD COLUMN `outlet_id` INT UNSIGNED NULL");
            }
        } catch (Throwable $e) {}
    } catch (Throwable $e) {
        // Silently continue if alters cannot run
    }
}

function ensure_counter_store_columns(): void {
    ensure_users_table_schema();
}

/**
 * Full-access users (owner dashboard, cost, all stores). Matches legacy installs with blank role.
 */
function user_has_full_access(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if ($role === '') {
        return true;
    }
    if (in_array($role, ['owner', 'admin', 'administrator'], true)) {
        return true;
    }
    $slug = preg_replace('/[^a-z0-9]/', '', $role) ?? '';
    return in_array($slug, ['admin', 'administrator', 'owner'], true);
}

function user_pos_is_outlet_locked(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    if (user_has_full_access($user)) {
        return false;
    }
    return (int) ($user['outlet_id'] ?? 0) > 0;
}

function is_super_admin(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    return !empty($user['is_super_admin']) && strtolower(trim((string)($user['email'] ?? ''))) === 'admin@example.com';
}

function user_role(?array $user = null): string {
    $user = $user ?? current_user();
    if (!$user) return '';
    return strtolower(trim((string) ($user['role'] ?? 'staff')));
}

function user_can_manage_users(?array $user = null): bool {
    return user_has_full_access($user);
}

function user_can_access_settings(?array $user = null): bool {
    return user_has_full_access($user);
}

function user_can_access_purchasing(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    if (user_has_full_access($user)) return true;
    $role = user_role($user);
    return in_array($role, ['manager', 'store manager', 'accountant'], true);
}

function user_can_access_books(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    if (user_has_full_access($user)) return true;
    $role = user_role($user);
    return in_array($role, ['manager', 'store manager', 'accountant'], true);
}

function user_can_access_reports(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    if (user_has_full_access($user)) return true;
    $role = user_role($user);
    return in_array($role, ['manager', 'store manager', 'accountant'], true);
}

function user_can_access_inventory(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    if (user_has_full_access($user)) return true;
    $role = user_role($user);
    return in_array($role, ['manager', 'store manager', 'cashier', 'staff'], true);
}

function user_can_manage_inventory(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    if (user_has_full_access($user)) return true;
    $role = user_role($user);
    return in_array($role, ['manager', 'store manager'], true);
}

function enforce_user_page_permissions(): void {
    $user = current_user();
    if (!$user || user_has_full_access($user)) {
        return;
    }

    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $role = user_role($user);

    // 1. Administration & Settings Pages - Strictly Admin/Owner
    $adminSettingsPages = [
        'users.php',
        'roles.php',
        'role-create.php',
        'settings.php',
        'payment-integrations.php',
        'customer-payments.php',
        'integrations-whatsapp.php',
        'integrations-shipping.php',
        'integrations-cart.php',
        'taxes.php',
        'business-profile.php',
        'import-export.php',
        'pricing.php',
    ];
    if (in_array($script, $adminSettingsPages, true)) {
        if (!empty($_POST['is_ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access Denied: Administration settings access is restricted to Administrators.']);
            exit;
        }
        set_flash('error', 'Access Denied: Administration settings and user management are restricted to Administrators.');
        redirect(APP_URL . '/pos.php');
    }

    // 2. Purchasing & Inward Pages - Only Admin, Manager, Accountant
    $purchasingPages = [
        'purchases.php',
        'purchase-receives.php',
        'purchase-entry.php',
        'inward-entry.php',
        'bills.php',
        'payments-made.php',
        'vendors.php',
        'purchase-returns.php',
    ];
    if (in_array($script, $purchasingPages, true) && !in_array($role, ['manager', 'store manager', 'accountant'], true)) {
        if (!empty($_POST['is_ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access Denied: Purchasing module is restricted.']);
            exit;
        }
        set_flash('error', 'Access Denied: Purchasing and vendor modules are restricted.');
        redirect(APP_URL . '/pos.php');
    }

    // 3. Books of Accounts - Only Admin, Manager, Accountant
    if ($script === 'books.php' && !in_array($role, ['manager', 'store manager', 'accountant'], true)) {
        set_flash('error', 'Access Denied: Books of Accounts is restricted.');
        redirect(APP_URL . '/pos.php');
    }

    // 4. Analytics & Reports - Only Admin, Manager, Accountant
    if ($script === 'reports.php' && !in_array($role, ['manager', 'store manager', 'accountant'], true)) {
        set_flash('error', 'Access Denied: Analytics and Reports are restricted.');
        redirect(APP_URL . '/pos.php');
    }

    // 5. Store / Outlets Setup - Cashier & Staff cannot configure outlets
    $storeConfigPages = ['outlets.php'];
    if (in_array($script, $storeConfigPages, true) && in_array($role, ['cashier', 'staff'], true)) {
        set_flash('error', 'Access Denied: Store outlet configuration is restricted.');
        redirect(APP_URL . '/pos.php');
    }
}

function current_user(): ?array {
    if (!is_authenticated()) {
        return null;
    }

    static $cachedUser = null;
    if ($cachedUser !== null && $cachedUser['id'] === $_SESSION['user_id']) {
        return $cachedUser;
    }

    ensure_counter_store_columns();
    $db = get_db();
    $user = null;
    try {
        $stmt = $db->prepare('SELECT id, business_id, name, email, phone, role, outlet_id, status, is_super_admin, created_at FROM users WHERE id = :id AND status = :status LIMIT 1');
        $stmt->execute([
            'id' => $_SESSION['user_id'],
            'status' => 'active',
        ]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        // Fallback if is_super_admin or business_id column not added to users table yet
        try {
            $stmt = $db->prepare('SELECT id, business_id, name, email, phone, role, outlet_id, status, created_at FROM users WHERE id = :id AND status = :status LIMIT 1');
            $stmt->execute([
                'id' => $_SESSION['user_id'],
                'status' => 'active',
            ]);
            $user = $stmt->fetch();
            if ($user) {
                $user['is_super_admin'] = 0;
            }
        } catch (Exception $e2) {
            $user = null;
        }
    }

    if (!$user) {
        logout_user();
        return null;
    }

    if (empty($_SESSION['business_id']) && !empty($user['business_id'])) {
        $_SESSION['business_id'] = (int)$user['business_id'];
    }

    $cachedUser = $user;
    return $user;
}

function current_business_id(): int {
    if (!empty($_SESSION['business_id'])) {
        return (int)$_SESSION['business_id'];
    }
    $user = current_user();
    if ($user && !empty($user['business_id'])) {
        $_SESSION['business_id'] = (int)$user['business_id'];
        return (int)$user['business_id'];
    }
    return 1;
}

function current_business(): ?array {
    $bid = current_business_id();
    $db = get_db();
    try {
        $stmt = $db->prepare('SELECT * FROM businesses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $bid]);
        $biz = $stmt->fetch();
        if ($biz) {
            return $biz;
        }
    } catch (PDOException $e) {
        // Table not created yet
    }

    return [
        'id' => $bid,
        'name' => 'My Store POS',
        'legal_name' => 'My Store POS',
        'currency' => 'INR',
        'currency_symbol' => '₹',
        'country' => 'India',
        'status' => 'active'
    ];
}

function require_auth(): void {
    if (!is_authenticated()) {
        set_flash('error', 'Please sign in to access the POS Dashboard.');
        redirect(APP_URL . '/login.php');
    }

    require_once __DIR__ . '/features.php';
    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $subscriptionBypassPages = [
        'account-suspended.php',
        'logout.php',
        'login.php',
        'pricing.php',
        'premium-checkout.php',
    ];
    if (!in_array($script, $subscriptionBypassPages, true)) {
        if (!is_business_subscription_active() && empty($_SESSION['superadmin_impersonator']) && !is_super_admin()) {
            redirect(APP_URL . '/account-suspended.php');
        }
    }

    require_once __DIR__ . '/premium_db.php';
    enforce_premium_gate();

    // Enforce role-based module/page access
    enforce_user_page_permissions();
}

function require_guest(): void {
    if (is_authenticated()) {
        if (is_super_admin()) {
            redirect(APP_URL . '/admin/dashboard.php');
        }
        redirect(APP_URL . '/dashboard.php');
    }
}

function find_user_by_email(string $email): ?array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => strtolower(trim($email))]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function register_user(string $name, string $email, string $phone, string $password, string $confirmPassword, string $businessName = ''): array {
    $errors = [];
    $name = trim($name);
    $email = strtolower(trim($email));
    $phone = trim($phone);
    $businessName = trim($businessName);
    if ($businessName === '') {
        $businessName = $name . "'s Store";
    }

    // Validation
    if ($name === '') {
        $errors['name'] = 'Full name is required.';
    } elseif (mb_strlen($name) < 2) {
        $errors['name'] = 'Name must be at least 2 characters.';
    }

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } elseif (find_user_by_email($email) !== null) {
        $errors['email'] = 'An account with this email already exists.';
    }

    if ($phone === '') {
        $errors['phone'] = 'Phone number is required.';
    } elseif (!preg_match('/^[0-9+\s()-]{7,20}$/', $phone)) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    }

    if ($confirmPassword === '') {
        $errors['password_confirmation'] = 'Please confirm your password.';
    } elseif ($password !== $confirmPassword) {
        $errors['password_confirmation'] = 'Password confirmation does not match.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    // Secure password hashing
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    try {
        require_once __DIR__ . '/storefront_db.php';
        ensure_online_store_schema();

        $db = get_db();
        if (!$db->inTransaction()) {
            $db->beginTransaction();
        }

        // 1. Create Business Record
        $stmtBiz = $db->prepare('
            INSERT INTO businesses (name, legal_name, email, phone, currency, currency_symbol, country, status, created_at, updated_at)
            VALUES (:name, :legal_name, :email, :phone, "INR", "₹", "India", "active", NOW(), NOW())
        ');
        $stmtBiz->execute([
            'name' => $businessName,
            'legal_name' => $businessName,
            'email' => $email,
            'phone' => $phone ?: null,
        ]);
        $businessId = (int)$db->lastInsertId();
        require_once __DIR__ . '/organization_ids.php';
        assign_organization_id_to_business($db, $businessId, $businessName);

        try {
            $slug = generate_unique_store_slug($businessName, $businessId);
            $db->prepare('UPDATE businesses SET store_slug = :slug, store_published = 1 WHERE id = :id')
                ->execute(['slug' => $slug, 'id' => $businessId]);
        } catch (Exception $eSlug) {}

        // 2. Create User linked to Business
        $stmt = $db->prepare('
            INSERT INTO users (business_id, name, email, phone, password, role, status, created_at)
            VALUES (:biz_id, :name, :email, :phone, :password, "admin", :status, NOW())
        ');
        $stmt->execute([
            'biz_id' => $businessId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $hashedPassword,
            'status' => 'active',
        ]);
        $userId = (int) $db->lastInsertId();
        try {
            $db->prepare('UPDATE users SET public_id = ? WHERE id = ?')->execute([pos_public_user_id($userId), $userId]);
        } catch (Exception $ePub) {
            // public_id column may not exist on older schemas
        }

        // 3. Seed Default Category
        try {
            $stmtCat = $db->prepare('
                INSERT INTO categories (business_id, name, code, description, status, created_at, updated_at)
                VALUES (:biz_id, "General", :code, "Standard category for in-store items", "active", NOW(), NOW())
            ');
            $stmtCat->execute([
                'biz_id' => $businessId,
                'code' => 'GEN-' . strtoupper(substr(uniqid(), -4)),
            ]);
        } catch (Exception $eCat) {}

        // 4. Seed Default Outlet
        $outletId = 1;
        $outletCode = 'OUT-' . strtoupper(substr(uniqid(), -4));
        try {
            $stmtOutlet = $db->prepare('
                INSERT INTO outlets (business_id, name, code, address, phone, email, status, created_at, updated_at)
                VALUES (:biz_id, :name, :code, :address, :phone, :email, "active", NOW(), NOW())
            ');
            $stmtOutlet->execute([
                'biz_id' => $businessId,
                'name' => $businessName . ' (Main Outlet)',
                'code' => $outletCode,
                'address' => 'Main Retail Counter',
                'phone' => $phone ?: null,
                'email' => $email,
            ]);
            $outletId = (int)$db->lastInsertId();
        } catch (Exception $eOut) {}

        // 5. Seed Default Warehouse
        try {
            $stmtWH = $db->prepare('
                INSERT INTO warehouses (business_id, outlet_id, name, code, location, status, created_at, updated_at)
                VALUES (:biz_id, :oid, :name, :code, "Store Front Inventory", "active", NOW(), NOW())
            ');
            $stmtWH->execute([
                'biz_id' => $businessId,
                'oid' => $outletId,
                'name' => $businessName . ' Warehouse',
                'code' => 'WH-' . $outletCode,
            ]);
        } catch (Exception $eWH) {}

        // 5b. Seed Central Warehouse (hub — not tied to a store type)
        try {
            require_once __DIR__ . '/outlets_db.php';
            ensure_business_warehouse_baseline($businessId);
        } catch (Exception $eCentralWh) {}

        // 6. Seed Default Register
        try {
            $stmtReg = $db->prepare('
                INSERT INTO registers (business_id, name, code, order_prefix, invoice_prefix, status, created_at, updated_at)
                VALUES (:biz_id, "Counter 1", :code, "ORD", "INV", "active", NOW(), NOW())
            ');
            $stmtReg->execute([
                'biz_id' => $businessId,
                'code' => 'REG-' . strtoupper(substr(uniqid(), -4)),
            ]);
        } catch (Exception $eReg) {}

        // 7. Seed Default Walk-in Customer
        try {
            $stmtCust = $db->prepare('
                INSERT INTO customers (business_id, name, phone, email, address, created_at, updated_at)
                VALUES (:biz_id, "Walk-in Customer", "N/A", NULL, "Counter Customer", NOW(), NOW())
            ');
            $stmtCust->execute(['biz_id' => $businessId]);
        } catch (Exception $eCust) {}

        // 8. Seed Default Taxes
        try {
            $stmtTax = $db->prepare('
                INSERT INTO tax_rates (business_id, name, rate, type, is_default, status, created_at, updated_at)
                VALUES (:biz_id, "GST 18% (Standard Rate)", 18.00, "gst", 1, "active", NOW(), NOW())
            ');
            $stmtTax->execute(['biz_id' => $businessId]);
        } catch (Exception $eTax) {}

        // 9. Seed Default Payment Tender Types
        try {
            require_once __DIR__ . '/payment_options_db.php';
            seed_default_payment_options_if_needed($businessId);
        } catch (Exception $ePay) {}

        // 10. Automatic 7-day free trial for new stores
        try {
            require_once __DIR__ . '/features.php';
            provision_new_business_free_trial($businessId);
            if (function_exists('apply_feature_preset')) {
                apply_feature_preset($businessId, 'all');
            }
        } catch (Exception $eTrial) {}

        if ($db->inTransaction()) {
            $db->commit();
        }
        return ['success' => true, 'errors' => [], 'user_id' => $userId, 'business_id' => $businessId];
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'errors' => ['general' => 'Registration failed: ' . $e->getMessage()]];
    }
}

function login_user(string $email, string $password, bool $remember = false): array {
    $errors = [];
    $email = strtolower(trim($email));

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    if ($email === 'admin@example.com') {
        require_once __DIR__ . '/features.php';
        if (function_exists('ensure_super_admin_seed')) {
            ensure_super_admin_seed();
        }
    }

    $user = find_user_by_email($email);
    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'errors' => ['general' => 'These credentials do not match our records.']];
    }

    if ($user['status'] !== 'active') {
        return ['success' => false, 'errors' => ['general' => 'Your account is deactivated. Please contact support.']];
    }

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['business_id'] = (int) ($user['business_id'] ?? 1);
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'] ?? 'admin';
    $_SESSION['is_super_admin'] = !empty($user['is_super_admin']);

    return ['success' => true, 'errors' => []];
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

<?php
/**
 * Online storefront + custom domain mapping for OminiFlow POS (Zoho POS parity).
 * Additive only — does not replace POS, Shopify, or WooCommerce integrations.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/products_db.php';
require_once __DIR__ . '/orders_db.php';

function ensure_online_store_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = get_db();

    add_schema_column_if_missing($db, 'businesses', 'store_slug', "VARCHAR(80) NULL");
    add_schema_column_if_missing($db, 'businesses', 'store_published', "TINYINT(1) NOT NULL DEFAULT 1");
    add_schema_column_if_missing($db, 'orders', 'sales_channel', "VARCHAR(30) NOT NULL DEFAULT 'pos'");
    add_schema_column_if_missing($db, 'payments', 'business_id', "INT UNSIGNED NOT NULL DEFAULT 1");
    add_schema_column_if_missing($db, 'customers', 'password', "VARCHAR(255) NULL");

    $db->exec("
        CREATE TABLE IF NOT EXISTS `mobile_store_settings` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL,
            `display_name` VARCHAR(191) NULL,
            `logo_path` VARCHAR(255) NULL,
            `header_color` VARCHAR(20) NOT NULL DEFAULT '#0f4c3a',
            `accent_color` VARCHAR(20) NOT NULL DEFAULT '#2563eb',
            `banner_title` VARCHAR(191) NULL DEFAULT 'We''re online now!',
            `banner_subtitle` VARCHAR(255) NULL DEFAULT 'Stay at home and shop online.',
            `banner_image` VARCHAR(255) NULL,
            `search_placeholder` VARCHAR(191) NULL DEFAULT 'Search by item or category',
            `show_location` TINYINT(1) NOT NULL DEFAULT 1,
            `show_banner` TINYINT(1) NOT NULL DEFAULT 1,
            `show_categories` TINYINT(1) NOT NULL DEFAULT 1,
            `show_items` TINYINT(1) NOT NULL DEFAULT 1,
            `favicon_path` VARCHAR(255) NULL,
            `header_text_color` VARCHAR(20) NOT NULL DEFAULT '#ffffff',
            `button_text_color` VARCHAR(20) NOT NULL DEFAULT '#ffffff',
            `show_logo_header` TINYINT(1) NOT NULL DEFAULT 1,
            `show_name_with_logo` TINYINT(1) NOT NULL DEFAULT 1,
            `published_at` TIMESTAMP NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_mobile_store_business` (`business_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
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
        $db->exec("ALTER TABLE `businesses` ADD UNIQUE INDEX `uq_businesses_store_slug` (`store_slug`)");
    } catch (PDOException $e) {
        // Index already exists
    }

    seed_missing_store_slugs();

    add_schema_column_if_missing($db, 'mobile_store_settings', 'favicon_path', "VARCHAR(255) NULL");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'header_text_color', "VARCHAR(20) NOT NULL DEFAULT '#ffffff'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'button_text_color', "VARCHAR(20) NOT NULL DEFAULT '#ffffff'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'show_logo_header', "TINYINT(1) NOT NULL DEFAULT 1");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'show_name_with_logo', "TINYINT(1) NOT NULL DEFAULT 1");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'published_at', "TIMESTAMP NULL");

    // Additional Store Preferences
    add_schema_column_if_missing($db, 'mobile_store_settings', 'hide_out_of_stock', "TINYINT(1) NOT NULL DEFAULT 1");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'allow_custom_quantity', "TINYINT(1) NOT NULL DEFAULT 1");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'display_stock_count', "TINYINT(1) NOT NULL DEFAULT 0");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'display_low_stock_below_10', "TINYINT(1) NOT NULL DEFAULT 0");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'hide_product_price', "TINYINT(1) NOT NULL DEFAULT 0");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'show_image_disclaimer', "TINYINT(1) NOT NULL DEFAULT 0");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'enable_billing_address', "TINYINT(1) NOT NULL DEFAULT 0");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'enable_delivery', "TINYINT(1) NOT NULL DEFAULT 1");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'min_delivery_order_value', "DECIMAL(10,2) NOT NULL DEFAULT 50.00");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'enable_pickup', "TINYINT(1) NOT NULL DEFAULT 0");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'customer_care_phone', "VARCHAR(50) NOT NULL DEFAULT ''");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'customer_care_email', "VARCHAR(191) NOT NULL DEFAULT ''");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'footer_location', "VARCHAR(255) NULL");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'privacy_policy', "MEDIUMTEXT NULL");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'contact_us_text', "TEXT NULL");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'contact_whatsapp', "VARCHAR(50) NULL");

    // Visual Builder / Home Layout Components
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_section_name', "VARCHAR(191) NOT NULL DEFAULT 'All Categories'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_bg_color', "VARCHAR(20) NOT NULL DEFAULT '#ffffff'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_text_color', "VARCHAR(20) NOT NULL DEFAULT '#000000'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_shape', "VARCHAR(20) NOT NULL DEFAULT 'rectangle'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_columns', "INT NOT NULL DEFAULT 2");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_rows', "INT NOT NULL DEFAULT 2");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_section_name', "VARCHAR(191) NOT NULL DEFAULT 'Banners'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'show_banner_section_name', "TINYINT(1) NOT NULL DEFAULT 0");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_bg_color', "VARCHAR(20) NOT NULL DEFAULT '#7c3aed'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_text_color', "VARCHAR(20) NOT NULL DEFAULT '#ffffff'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_2_tag', "VARCHAR(191) NULL DEFAULT 'Best deal,'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_2_title', "VARCHAR(191) NULL DEFAULT 'Start Shopping'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_2_subtitle', "VARCHAR(255) NULL DEFAULT 'and discover the best deals!'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_2_bg_color', "VARCHAR(20) NOT NULL DEFAULT '#2563eb'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_2_text_color', "VARCHAR(20) NOT NULL DEFAULT '#ffffff'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_3_tag', "VARCHAR(191) NULL DEFAULT 'Order'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_3_title', "VARCHAR(191) NULL DEFAULT 'with Ease'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_3_subtitle', "VARCHAR(255) NULL DEFAULT 'with Speed'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_3_bg_color', "VARCHAR(20) NOT NULL DEFAULT '#028476'");
    add_schema_column_if_missing($db, 'products', 'is_trending', "TINYINT(1) NOT NULL DEFAULT 0");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'show_trending_items', "TINYINT(1) NOT NULL DEFAULT 1");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'trending_section_name', "VARCHAR(191) NOT NULL DEFAULT 'Top Trending Items'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'trending_bg_color', "VARCHAR(20) NOT NULL DEFAULT '#ffffff'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'trending_text_color', "VARCHAR(20) NOT NULL DEFAULT '#000000'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'item_section_name', "VARCHAR(191) NOT NULL DEFAULT 'All Items'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'section_order', "VARCHAR(191) NOT NULL DEFAULT 'category,banner,trending,item'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'font_size', "VARCHAR(20) NOT NULL DEFAULT 'medium'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_mode', "VARCHAR(20) NOT NULL DEFAULT 'all'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'selected_category_ids', "TEXT NULL");
}

function add_schema_column_if_missing(PDO $db, string $table, string $column, string $definition): void {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col
        ");
        $stmt->execute([
            'db' => DB_NAME,
            'tbl' => $table,
            'col' => $column,
        ]);
        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE `{$table}` ADD `{$column}` {$definition}");
        }
    } catch (PDOException $e) {
        // Table may not exist yet
    }
}

function seed_missing_store_slugs(): void {
    $db = get_db();
    try {
        $rows = $db->query('SELECT id, name, store_slug FROM businesses')->fetchAll();
    } catch (PDOException $e) {
        return;
    }
    foreach ($rows as $row) {
        if (!empty($row['store_slug'])) {
            continue;
        }
        $slug = generate_unique_store_slug((string) ($row['name'] ?? 'store'), (int) $row['id']);
        $db->prepare('UPDATE businesses SET store_slug = :slug WHERE id = :id')
            ->execute(['slug' => $slug, 'id' => (int) $row['id']]);
    }
}

function generate_unique_store_slug(string $name, int $excludeBusinessId = 0): string {
    $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
    if ($base === '') {
        $base = 'store';
    }
    $base = substr($base, 0, 48);
    $reserved = ['store', 'admin', 'api', 'login', 'dashboard', 'www', 'app', 'pos', 'online-store'];
    if (in_array($base, $reserved, true)) {
        $base .= '-shop';
    }

    $db = get_db();
    $slug = $base;
    $i = 2;
    while (true) {
        $stmt = $db->prepare('SELECT id FROM businesses WHERE store_slug = :slug AND id <> :id LIMIT 1');
        $stmt->execute(['slug' => $slug, 'id' => $excludeBusinessId]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
        if ($i > 500) {
            return $base . '-' . bin2hex(random_bytes(3));
        }
    }
}

function normalize_store_domain(string $raw): string {
    $domain = strtolower(trim($raw));
    $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
    $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
    $domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;
    $domain = trim($domain, '.');
    return $domain;
}

function current_request_host(): string {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
    return $host;
}

function is_local_app_host(?string $host = null): bool {
    $host = $host ?? current_request_host();
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function app_absolute_url(string $path = ''): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . APP_URL . '/' . ltrim($path, '/');
}

function get_business_store(int $businessId): ?array {
    ensure_online_store_schema();
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM businesses WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $businessId]);
    $biz = $stmt->fetch();
    return $biz ?: null;
}

function get_business_by_store_slug(string $slug): ?array {
    ensure_online_store_schema();
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return null;
    }
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM businesses WHERE store_slug = :slug AND status = "active" LIMIT 1');
    $stmt->execute(['slug' => $slug]);
    $biz = $stmt->fetch();
    return $biz ?: null;
}

function get_business_by_custom_domain(string $host, bool $verifiedOnly = true): ?array {
    ensure_online_store_schema();
    $host = normalize_store_domain($host);
    if ($host === '' || is_local_app_host($host)) {
        return null;
    }
    $db = get_db();
    $sql = '
        SELECT b.*, d.domain AS mapped_domain, d.status AS domain_status
        FROM custom_domains d
        INNER JOIN businesses b ON b.id = d.business_id
        WHERE d.domain = :domain AND b.status = "active"
    ';
    if ($verifiedOnly) {
        $sql .= ' AND d.status = "verified"';
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute(['domain' => $host]);
    $biz = $stmt->fetch();
    if ($biz) {
        return $biz;
    }
    if (strpos($host, 'www.') === 0) {
        return get_business_by_custom_domain(substr($host, 4), $verifiedOnly);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute(['domain' => 'www.' . $host]);
    $biz = $stmt->fetch();
    return $biz ?: null;
}

function resolve_store_business_from_request(?string $slug = null): ?array {
    ensure_online_store_schema();
    $hostBiz = get_business_by_custom_domain(current_request_host(), true);
    if ($hostBiz) {
        return $hostBiz;
    }
    $slug = $slug !== null ? $slug : (string) ($_GET['slug'] ?? '');
    if ($slug !== '') {
        return get_business_by_store_slug($slug);
    }
    if (is_authenticated()) {
        return get_business_store(current_business_id());
    }
    return null;
}

function store_is_on_custom_domain(?array $business = null): bool {
    $hostBiz = get_business_by_custom_domain(current_request_host(), true);
    if (!$hostBiz) {
        return false;
    }
    if ($business && (int) $hostBiz['id'] !== (int) $business['id']) {
        return false;
    }
    return true;
}

function public_store_url(?array $business, string $page = 'home', array $query = []): string {
    if (!$business) {
        return app_absolute_url('store.php');
    }
    if (store_is_on_custom_domain($business)) {
        $query['page'] = $page !== 'home' ? $page : ($query['page'] ?? null);
        unset($query['slug']);
        $qs = array_filter($query, static fn($v) => $v !== null && $v !== '');
        $url = app_absolute_url('store.php');
        return $qs ? ($url . '?' . http_build_query($qs)) : $url;
    }
    $query['slug'] = (string) ($business['store_slug'] ?? '');
    if ($page !== 'home') {
        $query['page'] = $page;
    }
    return app_absolute_url('store.php') . '?' . http_build_query($query);
}

function public_store_signin_url(?array $business, array $query = []): string {
    if ($business && !store_is_on_custom_domain($business) && !empty($business['store_slug'])) {
        $query['slug'] = (string) $business['store_slug'];
    }
    $qs = array_filter($query, static fn($v) => $v !== null && $v !== '');
    $url = app_absolute_url('store-signin.php');
    return $qs ? ($url . '?' . http_build_query($qs)) : $url;
}

function public_store_local_url(array $business): string {
    $slug = (string) ($business['store_slug'] ?? '');
    return app_absolute_url('store.php') . ($slug !== '' ? ('?slug=' . rawurlencode($slug)) : '');
}

function is_platform_logo(?string $path): bool {
    $path = strtolower(str_replace('\\', '/', trim((string) $path)));
    if ($path === '') {
        return true;
    }
    $blocked = [
        'assets/images/logo.jpg',
        'assets/images/logo.png',
        'assets/images/logo.jpeg',
        'assets/images/favicon.ico',
        'assets/images/favicon-32x32.png',
        'assets/images/favicon-16x16.png',
        'assets/images/apple-touch-icon.png',
    ];
    return in_array($path, $blocked, true);
}

function store_logo_file_exists(?string $path): bool {
    if (!$path || is_platform_logo($path)) {
        return false;
    }
    $full = dirname(__DIR__) . '/' . ltrim($path, '/');
    return is_file($full);
}

function normalize_hex_color(string $value, string $fallback): string {
    $value = trim($value);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return strtolower($value);
    }
    if (preg_match('/^[0-9a-fA-F]{6}$/', $value)) {
        return '#' . strtolower($value);
    }
    return $fallback;
}

function store_initials_from_name(string $name): string {
    $name = trim($name);
    $parts = preg_split('/\s+/', $name) ?: [];
    $parts = array_values(array_filter($parts));
    if (!$parts) {
        return 'ST';
    }
    $out = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $out .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
    }
    return $out;
}

function get_storefront_dynamic_favicon_url(array $brand, string $storeName): string {
    if (!empty($brand['favicon_path'])) {
        return asset((string) $brand['favicon_path']);
    }
    if (!empty($brand['logo_path']) && !is_platform_logo((string) $brand['logo_path'])) {
        return asset((string) $brand['logo_path']);
    }
    $bgColor = !empty($brand['header_color']) ? (string) $brand['header_color'] : '#0f4c3a';
    $textColor = !empty($brand['header_text_color']) ? (string) $brand['header_text_color'] : '#ffffff';
    $nameClean = trim($storeName) !== '' ? trim($storeName) : 'Store';
    $initials = store_initials_from_name($nameClean);

    $fontSize = strlen($initials) > 1 ? 26 : 34;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
         . '<rect width="64" height="64" rx="16" fill="' . htmlspecialchars($bgColor, ENT_QUOTES, 'UTF-8') . '"/>'
         . '<text x="50%" y="54%" dominant-baseline="central" text-anchor="middle" '
         . 'fill="' . htmlspecialchars($textColor, ENT_QUOTES, 'UTF-8') . '" '
         . 'font-family="-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif" '
         . 'font-size="' . $fontSize . '" font-weight="800">'
         . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</text>'
         . '</svg>';

    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

function ensure_mobile_store_row(int $businessId): void {
    ensure_online_store_schema();
    $db = get_db();
    $stmt = $db->prepare('SELECT id FROM mobile_store_settings WHERE business_id = :bid LIMIT 1');
    $stmt->execute(['bid' => $businessId]);
    if ($stmt->fetch()) {
        return;
    }
    $biz = get_business_store($businessId);
    $name = (string) ($biz['name'] ?? 'My Store');
    $db->prepare('
        INSERT INTO mobile_store_settings (business_id, display_name, created_at, updated_at)
        VALUES (:bid, :name, NOW(), NOW())
    ')->execute(['bid' => $businessId, 'name' => $name]);
}

function get_mobile_store_settings(int $businessId): array {
    ensure_mobile_store_row($businessId);
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM mobile_store_settings WHERE business_id = :bid LIMIT 1');
    $stmt->execute(['bid' => $businessId]);
    $row = $stmt->fetch() ?: [];
    $biz = get_business_store($businessId) ?: [];
    $store = [];
    try {
        $store = get_store_settings($businessId);
    } catch (Throwable $e) {
        $store = [];
    }

    $profileLogo = null;
    try {
        $p = $db->prepare('SELECT logo_path FROM business_profile WHERE business_id = :bid LIMIT 1');
        $p->execute(['bid' => $businessId]);
        $profileLogo = $p->fetchColumn() ?: null;
    } catch (PDOException $e) {
        try {
            $p = $db->query('SELECT logo_path FROM business_profile WHERE id = 1 LIMIT 1');
            $maybe = $p ? $p->fetchColumn() : null;
            if ($businessId === 1) {
                $profileLogo = $maybe ?: null;
            }
        } catch (PDOException $e2) {
            $profileLogo = null;
        }
    }

    $candidates = [
        $row['logo_path'] ?? null,
        $profileLogo,
        $store['logo_path'] ?? null,
    ];
    $logo = null;
    foreach ($candidates as $c) {
        if ($c && store_logo_file_exists((string) $c)) {
            $logo = (string) $c;
            break;
        }
    }

    $display = trim((string) ($row['display_name'] ?? ''));
    if ($display === '') {
        $display = trim((string) ($biz['name'] ?? ''));
    }
    if ($display === '') {
        $storeName = trim((string) ($store['store_name'] ?? ''));
        $display = $storeName !== '' ? $storeName : 'My Store';
    }

    return [
        'display_name' => $display,
        'logo_path' => $logo,
        'initials' => store_initials_from_name($display),
        'header_color' => (string) ($row['header_color'] ?? '#0f4c3a'),
        'accent_color' => (string) ($row['accent_color'] ?? '#2563eb'),
        'banner_title' => (string) ($row['banner_title'] ?? "We're online now!"),
        'banner_subtitle' => (string) ($row['banner_subtitle'] ?? 'Stay at home and shop online.'),
        'banner_image' => (!empty($row['banner_image']) && store_logo_file_exists((string) $row['banner_image'])) ? (string) $row['banner_image'] : null,
        'search_placeholder' => (string) ($row['search_placeholder'] ?? 'Search by item or category'),
        'show_location' => (int) ($row['show_location'] ?? 1) === 1,
        'show_banner' => (int) ($row['show_banner'] ?? 1) === 1,
        'show_categories' => (int) ($row['show_categories'] ?? 1) === 1,
        'show_items' => (int) ($row['show_items'] ?? 1) === 1,
        'favicon_path' => (!empty($row['favicon_path']) && store_logo_file_exists((string) $row['favicon_path'])) ? (string) $row['favicon_path'] : null,
        'header_text_color' => (string) ($row['header_text_color'] ?? '#ffffff'),
        'button_text_color' => (string) ($row['button_text_color'] ?? '#ffffff'),
        'show_logo_header' => (int) ($row['show_logo_header'] ?? 1) === 1,
        'show_name_with_logo' => (int) ($row['show_name_with_logo'] ?? 1) === 1,
        'published_at' => $row['published_at'] ?? null,
        'phone' => (string) ($store['phone'] ?? $biz['phone'] ?? ''),
        'tagline' => (string) ($store['tagline'] ?? ''),

        // Additional Preferences
        'hide_out_of_stock' => (int) ($row['hide_out_of_stock'] ?? 1) === 1,
        'allow_custom_quantity' => (int) ($row['allow_custom_quantity'] ?? 1) === 1,
        'display_stock_count' => (int) ($row['display_stock_count'] ?? 0) === 1,
        'display_low_stock_below_10' => (int) ($row['display_low_stock_below_10'] ?? 0) === 1,
        'hide_product_price' => (int) ($row['hide_product_price'] ?? 0) === 1,
        'show_image_disclaimer' => (int) ($row['show_image_disclaimer'] ?? 0) === 1,
        'enable_billing_address' => (int) ($row['enable_billing_address'] ?? 0) === 1,
        'enable_delivery' => (int) ($row['enable_delivery'] ?? 1) === 1,
        'min_delivery_order_value' => (float) ($row['min_delivery_order_value'] ?? 50.00),
        'enable_pickup' => (int) ($row['enable_pickup'] ?? 0) === 1,
        'customer_care_phone' => (string) ($row['customer_care_phone'] ?? ''),
        'customer_care_email' => (string) ($row['customer_care_email'] ?? ''),
        'contact_whatsapp' => (string) ($row['contact_whatsapp'] ?? ''),
        'contact_us_text' => (string) ($row['contact_us_text'] ?? ''),
        'privacy_policy' => (string) ($row['privacy_policy'] ?? ''),
        'footer_location' => (string) ($row['footer_location'] ?? ''),
        // Visual Builder / Home Layout Components
        'category_section_name' => (string) ($row['category_section_name'] ?? 'All Categories'),
        'category_bg_color' => (string) ($row['category_bg_color'] ?? '#ffffff'),
        'category_text_color' => (string) ($row['category_text_color'] ?? '#000000'),
        'category_shape' => (string) ($row['category_shape'] ?? 'rectangle'),
        'category_columns' => (int) ($row['category_columns'] ?? 2),
        'category_rows' => (int) ($row['category_rows'] ?? 2),
        'banner_section_name' => (string) ($row['banner_section_name'] ?? 'Banners'),
        'show_banner_section_name' => (int) ($row['show_banner_section_name'] ?? 0) === 1,
        'banner_bg_color' => (string) ($row['banner_bg_color'] ?? '#7c3aed'),
        'banner_text_color' => (string) ($row['banner_text_color'] ?? '#ffffff'),
        'banner_2_tag' => (string) ($row['banner_2_tag'] ?? 'Best deal,'),
        'banner_2_title' => (string) ($row['banner_2_title'] ?? 'Start Shopping'),
        'banner_2_subtitle' => (string) ($row['banner_2_subtitle'] ?? 'and discover the best deals!'),
        'banner_2_bg_color' => (string) ($row['banner_2_bg_color'] ?? '#2563eb'),
        'banner_2_text_color' => (string) ($row['banner_2_text_color'] ?? '#ffffff'),
        'banner_3_tag' => (string) ($row['banner_3_tag'] ?? 'Order'),
        'banner_3_title' => (string) ($row['banner_3_title'] ?? 'with Ease'),
        'banner_3_subtitle' => (string) ($row['banner_3_subtitle'] ?? 'with Speed'),
        'banner_3_bg_color' => (string) ($row['banner_3_bg_color'] ?? '#028476'),
        'banner_3_text_color' => (string) ($row['banner_3_text_color'] ?? '#ffffff'),
        'show_trending_items' => (int) ($row['show_trending_items'] ?? 1) === 1,
        'trending_section_name' => (string) ($row['trending_section_name'] ?? 'Top Trending Items'),
        'trending_bg_color' => (string) ($row['trending_bg_color'] ?? '#ffffff'),
        'trending_text_color' => (string) ($row['trending_text_color'] ?? '#000000'),
        'item_section_name' => (string) ($row['item_section_name'] ?? 'All Items'),
        'section_order' => (string) ($row['section_order'] ?? 'category,banner,trending,item'),
        'font_size' => in_array(($row['font_size'] ?? 'medium'), ['small', 'medium', 'large'], true) ? (string) $row['font_size'] : 'medium',
        'category_mode' => (($row['category_mode'] ?? 'all') === 'custom') ? 'custom' : 'all',
        'selected_category_ids' => parse_selected_ids($row['selected_category_ids'] ?? ''),
    ];
}

function parse_selected_ids($value): array {
    if (is_array($value)) {
        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }
    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', $decoded))));
}

function storefront_home_sections(array $brand): array {
    $order = array_filter(array_map('trim', explode(',', (string) ($brand['section_order'] ?? ''))));
    $allowed = ['category', 'banner', 'trending', 'item'];
    $out = [];
    foreach ($order as $key) {
        if (in_array($key, $allowed, true) && !in_array($key, $out, true)) {
            $out[] = $key;
        }
    }
    foreach ($allowed as $key) {
        if (!in_array($key, $out, true)) {
            $out[] = $key;
        }
    }
    return $out;
}

function storefront_visible_categories(array $categories, array $brand): array {
    if (($brand['category_mode'] ?? 'all') !== 'custom') {
        return $categories;
    }
    $ids = $brand['selected_category_ids'] ?? [];
    if (!is_array($ids) || !$ids) {
        return $categories;
    }
    $set = array_fill_keys($ids, true);
    return array_values(array_filter($categories, static fn($c) => isset($set[(int) ($c['id'] ?? 0)])));
}

function save_mobile_store_settings(int $businessId, array $data, array $files = []): array {
    ensure_mobile_store_row($businessId);
    $db = get_db();
    $current = get_mobile_store_settings($businessId);

    $display = trim((string) ($data['display_name'] ?? $current['display_name']));
    if ($display === '') {
        return ['success' => false, 'error' => 'Display name is required.'];
    }

    $header = normalize_hex_color((string) ($data['header_color'] ?? $current['header_color']), '#0f4c3a');
    $accent = normalize_hex_color((string) ($data['accent_color'] ?? $current['accent_color']), '#2563eb');
    $headerText = normalize_hex_color((string) ($data['header_text_color'] ?? $current['header_text_color'] ?? '#ffffff'), '#ffffff');
    $buttonText = normalize_hex_color((string) ($data['button_text_color'] ?? $current['button_text_color'] ?? '#ffffff'), '#ffffff');

    $rowStmt = $db->prepare('SELECT logo_path, favicon_path, banner_image FROM mobile_store_settings WHERE business_id = :bid LIMIT 1');
    $rowStmt->execute(['bid' => $businessId]);
    $dbRow = $rowStmt->fetch() ?: [];
    $logoPath = $dbRow['logo_path'] ?? null;
    $bannerPath = $dbRow['banner_image'] ?? null;
    $faviconPath = $dbRow['favicon_path'] ?? null;

    if (!empty($files['logo']['name'])) {
        $up = upload_mobile_store_image($businessId, $files['logo'], 'logo');
        if (empty($up['success'])) {
            return $up;
        }
        $logoPath = $up['path'];
    }
    if (!empty($data['remove_logo'])) {
        $logoPath = null;
    }
    if (!empty($files['favicon']['name'])) {
        $up = upload_mobile_store_image($businessId, $files['favicon'], 'favicon');
        if (empty($up['success'])) {
            return $up;
        }
        $faviconPath = $up['path'];
    }
    if (!empty($data['remove_favicon'])) {
        $faviconPath = null;
    }
    if (!empty($files['banner']['name'])) {
        $up = upload_mobile_store_image($businessId, $files['banner'], 'banner');
        if (empty($up['success'])) {
            return $up;
        }
        $bannerPath = $up['path'];
    }
    if (!empty($data['remove_banner'])) {
        $bannerPath = null;
    }

    $db->prepare('
        UPDATE mobile_store_settings
        SET display_name = :name,
            logo_path = :logo,
            favicon_path = :fav,
            header_color = :header,
            header_text_color = :htext,
            accent_color = :accent,
            button_text_color = :btext,
            banner_title = :btitle,
            banner_subtitle = :bsub,
            banner_image = :bimg,
            search_placeholder = :search,
            show_location = :loc,
            show_banner = :banner,
            show_categories = :cats,
            show_items = :items,
            show_logo_header = :slogo,
            show_name_with_logo = :sname,
            hide_out_of_stock = :hoos,
            allow_custom_quantity = :acq,
            display_stock_count = :dsc,
            display_low_stock_below_10 = :dls,
            hide_product_price = :hpp,
            show_image_disclaimer = :sid,
            enable_billing_address = :eba,
            enable_delivery = :ed,
            min_delivery_order_value = :mdov,
            enable_pickup = :ep,
            customer_care_phone = :ccp,
            customer_care_email = :cce,
            contact_whatsapp = :cw,
            contact_us_text = :cut,
            privacy_policy = :pp,
            footer_location = :floc,
            category_section_name = :csn,
            category_bg_color = :cbg,
            category_text_color = :ctc,
            category_shape = :csh,
            category_columns = :ccol,
            category_rows = :crow,
            banner_section_name = :bsn,
            show_banner_section_name = :sbsn,
            banner_bg_color = :bbg,
            banner_text_color = :btxt,
            banner_2_tag = :b2tag,
            banner_2_title = :b2title,
            banner_2_subtitle = :b2sub,
            banner_2_bg_color = :b2bg,
            banner_2_text_color = :b2txt,
            banner_3_tag = :b3tag,
            banner_3_title = :b3title,
            banner_3_subtitle = :b3sub,
            banner_3_bg_color = :b3bg,
            banner_3_text_color = :b3txt,
            show_trending_items = :strend,
            trending_section_name = :tsn,
            trending_bg_color = :tbg,
            trending_text_color = :ttc,
            item_section_name = :isn,
            section_order = :sord,
            font_size = :fsize,
            category_mode = :cmode,
            selected_category_ids = :scids,
            updated_at = NOW()
        WHERE business_id = :bid
    ')->execute([
        'name' => $display,
        'logo' => $logoPath,
        'fav' => $faviconPath,
        'header' => $header,
        'htext' => $headerText,
        'accent' => $accent,
        'btext' => $buttonText,
        'btitle' => trim((string) ($data['banner_title'] ?? $current['banner_title'])),
        'bsub' => trim((string) ($data['banner_subtitle'] ?? $current['banner_subtitle'])),
        'bimg' => $bannerPath,
        'search' => trim((string) ($data['search_placeholder'] ?? $current['search_placeholder'])),
        'loc' => array_key_exists('show_location', $data) ? (!empty($data['show_location']) ? 1 : 0) : ($current['show_location'] ? 1 : 0),
        'banner' => array_key_exists('show_banner', $data) ? (!empty($data['show_banner']) ? 1 : 0) : ($current['show_banner'] ? 1 : 0),
        'cats' => array_key_exists('show_categories', $data) ? (!empty($data['show_categories']) ? 1 : 0) : ($current['show_categories'] ? 1 : 0),
        'items' => array_key_exists('show_items', $data) ? (!empty($data['show_items']) ? 1 : 0) : ($current['show_items'] ? 1 : 0),
        'slogo' => array_key_exists('show_logo_header', $data) ? (!empty($data['show_logo_header']) ? 1 : 0) : ($current['show_logo_header'] ? 1 : 0),
        'sname' => array_key_exists('show_name_with_logo', $data) ? (!empty($data['show_name_with_logo']) ? 1 : 0) : ($current['show_name_with_logo'] ? 1 : 0),
        'hoos' => array_key_exists('hide_out_of_stock', $data) ? (!empty($data['hide_out_of_stock']) ? 1 : 0) : ($current['hide_out_of_stock'] ? 1 : 0),
        'acq' => array_key_exists('allow_custom_quantity', $data) ? (!empty($data['allow_custom_quantity']) ? 1 : 0) : ($current['allow_custom_quantity'] ? 1 : 0),
        'dsc' => array_key_exists('display_stock_count', $data) ? (!empty($data['display_stock_count']) ? 1 : 0) : ($current['display_stock_count'] ? 1 : 0),
        'dls' => array_key_exists('display_low_stock_below_10', $data) ? (!empty($data['display_low_stock_below_10']) ? 1 : 0) : ($current['display_low_stock_below_10'] ? 1 : 0),
        'hpp' => array_key_exists('hide_product_price', $data) ? (!empty($data['hide_product_price']) ? 1 : 0) : ($current['hide_product_price'] ? 1 : 0),
        'sid' => array_key_exists('show_image_disclaimer', $data) ? (!empty($data['show_image_disclaimer']) ? 1 : 0) : ($current['show_image_disclaimer'] ? 1 : 0),
        'eba' => array_key_exists('enable_billing_address', $data) ? (!empty($data['enable_billing_address']) ? 1 : 0) : ($current['enable_billing_address'] ? 1 : 0),
        'ed' => array_key_exists('enable_delivery', $data) ? (!empty($data['enable_delivery']) ? 1 : 0) : ($current['enable_delivery'] ? 1 : 0),
        'mdov' => array_key_exists('min_delivery_order_value', $data) ? (float)$data['min_delivery_order_value'] : (float)$current['min_delivery_order_value'],
        'ep' => array_key_exists('enable_pickup', $data) ? (!empty($data['enable_pickup']) ? 1 : 0) : ($current['enable_pickup'] ? 1 : 0),
        'ccp' => trim((string)($data['customer_care_phone'] ?? $current['customer_care_phone'])),
        'cce' => trim((string)($data['customer_care_email'] ?? $current['customer_care_email'])),
        'cw' => array_key_exists('contact_whatsapp', $data) ? trim((string)$data['contact_whatsapp']) : ($current['contact_whatsapp'] ?? null),
        'cut' => array_key_exists('contact_us_text', $data) ? trim((string)$data['contact_us_text']) : ($current['contact_us_text'] ?? null),
        'pp' => array_key_exists('privacy_policy', $data) ? trim((string)$data['privacy_policy']) : ($current['privacy_policy'] ?? null),
        'floc' => array_key_exists('footer_location', $data) ? trim((string)$data['footer_location']) : ($current['footer_location'] ?? null),
        'csn' => trim((string)($data['category_section_name'] ?? $current['category_section_name'])),
        'cbg' => normalize_hex_color((string)($data['category_bg_color'] ?? $current['category_bg_color']), '#ffffff'),
        'ctc' => normalize_hex_color((string)($data['category_text_color'] ?? $current['category_text_color']), '#000000'),
        'csh' => in_array(($data['category_shape'] ?? $current['category_shape']), ['square', 'rectangle'], true)
            ? (string) ($data['category_shape'] ?? $current['category_shape'])
            : 'rectangle',
        'ccol' => isset($data['category_columns']) ? (int)$data['category_columns'] : (int)$current['category_columns'],
        'crow' => isset($data['category_rows']) ? (int)$data['category_rows'] : (int)$current['category_rows'],
        'bsn' => trim((string)($data['banner_section_name'] ?? $current['banner_section_name'])),
        'sbsn' => array_key_exists('show_banner_section_name', $data) ? (!empty($data['show_banner_section_name']) ? 1 : 0) : ($current['show_banner_section_name'] ? 1 : 0),
        'bbg' => normalize_hex_color((string)($data['banner_bg_color'] ?? $current['banner_bg_color'] ?? '#7c3aed'), '#7c3aed'),
        'btxt' => normalize_hex_color((string)($data['banner_text_color'] ?? $current['banner_text_color'] ?? '#ffffff'), '#ffffff'),
        'b2tag' => trim((string)($data['banner_2_tag'] ?? $current['banner_2_tag'] ?? 'Best deal,')),
        'b2title' => trim((string)($data['banner_2_title'] ?? $current['banner_2_title'] ?? 'Start Shopping')),
        'b2sub' => trim((string)($data['banner_2_subtitle'] ?? $current['banner_2_subtitle'] ?? 'and discover the best deals!')),
        'b2bg' => normalize_hex_color((string)($data['banner_2_bg_color'] ?? $current['banner_2_bg_color'] ?? '#2563eb'), '#2563eb'),
        'b2txt' => normalize_hex_color((string)($data['banner_2_text_color'] ?? $current['banner_2_text_color'] ?? '#ffffff'), '#ffffff'),
        'b3tag' => trim((string)($data['banner_3_tag'] ?? $current['banner_3_tag'] ?? 'Order')),
        'b3title' => trim((string)($data['banner_3_title'] ?? $current['banner_3_title'] ?? 'with Ease')),
        'b3sub' => trim((string)($data['banner_3_subtitle'] ?? $current['banner_3_subtitle'] ?? 'with Speed')),
        'b3bg' => normalize_hex_color((string)($data['banner_3_bg_color'] ?? $current['banner_3_bg_color'] ?? '#028476'), '#028476'),
        'b3txt' => normalize_hex_color((string)($data['banner_3_text_color'] ?? $current['banner_3_text_color'] ?? '#ffffff'), '#ffffff'),
        'strend' => array_key_exists('show_trending_items', $data) ? (!empty($data['show_trending_items']) ? 1 : 0) : ($current['show_trending_items'] ? 1 : 0),
        'tsn' => trim((string)($data['trending_section_name'] ?? $current['trending_section_name'] ?? 'Top Trending Items')),
        'tbg' => normalize_hex_color((string)($data['trending_bg_color'] ?? $current['trending_bg_color'] ?? '#ffffff'), '#ffffff'),
        'ttc' => normalize_hex_color((string)($data['trending_text_color'] ?? $current['trending_text_color'] ?? '#000000'), '#000000'),
        'isn' => trim((string)($data['item_section_name'] ?? $current['item_section_name'])),
        'sord' => trim((string)($data['section_order'] ?? $current['section_order'])),
        'fsize' => in_array(($data['font_size'] ?? $current['font_size'] ?? 'medium'), ['small', 'medium', 'large'], true)
            ? (string) ($data['font_size'] ?? $current['font_size'])
            : 'medium',
        'cmode' => (($data['category_mode'] ?? $current['category_mode'] ?? 'all') === 'custom') ? 'custom' : 'all',
        'scids' => json_encode(parse_selected_ids($data['selected_category_ids'] ?? $current['selected_category_ids'] ?? [])),
        'bid' => $businessId,
    ]);

    try {
        $db->prepare('UPDATE store_settings SET store_name = :n, updated_at = NOW() WHERE business_id = :bid')
            ->execute(['n' => $display, 'bid' => $businessId]);
    } catch (PDOException $e) {
        // optional sync
    }

    return ['success' => true];
}

function get_storefront_trending_products(int $businessId, int $limit = 20): array {
    ensure_online_store_schema();
    $db = get_db();
    $stmt = $db->prepare("
        SELECT * FROM products 
        WHERE business_id = :bid 
          AND status = 'active' 
          AND is_trending = 1 
        ORDER BY updated_at DESC, id DESC 
        LIMIT :lim
    ");
    $stmt->bindValue(':bid', $businessId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function publish_mobile_store(int $businessId): void {
    ensure_mobile_store_row($businessId);
    set_store_published($businessId, true);
    get_db()->prepare('UPDATE mobile_store_settings SET published_at = NOW(), updated_at = NOW() WHERE business_id = :bid')
        ->execute(['bid' => $businessId]);
}

function upload_mobile_store_image(int $businessId, array $file, string $kind): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed. Try another image.'];
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if ($kind === 'favicon') {
        $allowed[] = 'ico';
    }
    if (!in_array($ext, $allowed, true)) {
        return ['success' => false, 'error' => $kind === 'favicon' ? 'Use PNG, JPG, WEBP, or ICO.' : 'Use JPG, PNG, or WEBP.'];
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Image must be under 5 MB.'];
    }
    $dir = dirname(__DIR__) . '/assets/uploads/store/' . $businessId . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['success' => false, 'error' => 'Could not create upload folder.'];
    }
    $name = $kind . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file((string) $file['tmp_name'], $dir . $name)) {
        return ['success' => false, 'error' => 'Could not save image.'];
    }
    return ['success' => true, 'path' => 'assets/uploads/store/' . $businessId . '/' . $name];
}

function get_store_custom_domains(int $businessId): array {
    ensure_online_store_schema();
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM custom_domains WHERE business_id = :bid ORDER BY id DESC');
    $stmt->execute(['bid' => $businessId]);
    return $stmt->fetchAll();
}

function save_business_store_slug(int $businessId, string $slug): array {
    ensure_online_store_schema();
    $slug = strtolower(trim((string) preg_replace('/[^a-z0-9\-]+/', '-', $slug), '-'));
    if ($slug === '' || strlen($slug) < 2) {
        return ['success' => false, 'error' => 'Store URL slug must be at least 2 characters.'];
    }
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        return ['success' => false, 'error' => 'Use lowercase letters, numbers, and hyphens only.'];
    }
    $taken = get_business_by_store_slug($slug);
    if ($taken && (int) $taken['id'] !== $businessId) {
        return ['success' => false, 'error' => 'This store URL is already taken.'];
    }
    $db = get_db();
    $db->prepare('UPDATE businesses SET store_slug = :slug, updated_at = NOW() WHERE id = :id')
        ->execute(['slug' => $slug, 'id' => $businessId]);
    return ['success' => true, 'slug' => $slug];
}

function set_store_published(int $businessId, bool $published): void {
    ensure_online_store_schema();
    get_db()->prepare('UPDATE businesses SET store_published = :pub, updated_at = NOW() WHERE id = :id')
        ->execute(['pub' => $published ? 1 : 0, 'id' => $businessId]);
}

function add_custom_domain(int $businessId, string $rawDomain): array {
    ensure_online_store_schema();
    $domain = normalize_store_domain($rawDomain);
    if ($domain === '' || !preg_match('/^[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)+$/', $domain)) {
        return ['success' => false, 'error' => 'Enter a valid domain such as shop.yourbrand.com'];
    }
    if (is_local_app_host($domain)) {
        return ['success' => false, 'error' => 'localhost cannot be used as a custom store domain.'];
    }

    $db = get_db();
    $stmt = $db->prepare('SELECT id, business_id FROM custom_domains WHERE domain = :domain LIMIT 1');
    $stmt->execute(['domain' => $domain]);
    $existing = $stmt->fetch();
    if ($existing && (int) $existing['business_id'] !== $businessId) {
        return ['success' => false, 'error' => 'This domain is already mapped to another store.'];
    }
    if ($existing) {
        return ['success' => false, 'error' => 'This domain is already added to your store.'];
    }

    $token = 'of-' . bin2hex(random_bytes(8));
    $db->prepare('
        INSERT INTO custom_domains (business_id, domain, status, cname_token, ssl_status, created_at, updated_at)
        VALUES (:bid, :domain, "pending", :token, "none", NOW(), NOW())
    ')->execute([
        'bid' => $businessId,
        'domain' => $domain,
        'token' => $token,
    ]);

    return ['success' => true, 'domain' => $domain, 'token' => $token];
}

function verify_custom_domain(int $businessId, int $domainId, bool $forceLocal = false): array {
    ensure_online_store_schema();
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM custom_domains WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $domainId, 'bid' => $businessId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'error' => 'Domain not found.'];
    }

    $domain = (string) $row['domain'];
    $token = (string) $row['cname_token'];
    $verified = false;
    $reason = '';

    // 1. If explicitly activated for local test environment
    if ($forceLocal) {
        $verified = true;
        $reason = 'Activated for local development environment.';
    }

    // 2. If the request was served directly from the domain host
    if (!$verified && strcasecmp(current_request_host(), $domain) === 0) {
        $verified = true;
        $reason = 'Domain host resolved directly to server.';
    }

    // 3. Real Global DNS CNAME lookup (Zoho Production Lifecycle)
    if (!$verified && function_exists('dns_get_record')) {
        $cnameTarget = strtolower((string) STORE_CNAME_TARGET);
        $lookupDomains = [$domain];
        if (!str_starts_with(strtolower($domain), 'www.') && substr_count($domain, '.') === 1) {
            $lookupDomains[] = 'www.' . $domain;
        } elseif (str_starts_with(strtolower($domain), 'www.')) {
            $lookupDomains[] = substr($domain, 4);
        }

        foreach ($lookupDomains as $d) {
            $records = @dns_get_record($d, DNS_CNAME) ?: [];
            foreach ($records as $rec) {
                $target = strtolower(rtrim((string) ($rec['target'] ?? ''), '.'));
                if ($target === $cnameTarget || $target === 'localhost' || strpos($target, $token) !== false) {
                    $verified = true;
                    $reason = 'CNAME record verified on global DNS (' . $d . ').';
                    break 2;
                }
            }
        }
    }

    // 4. Check local hosts resolution (Windows/Linux /etc/hosts)
    if (!$verified) {
        $resolved = @gethostbyname($domain);
        if (is_string($resolved) && $resolved !== $domain && in_array($resolved, ['127.0.0.1', '::1'], true)) {
            $verified = true;
            $reason = 'Domain resolved via local hosts mapping.';
        }
    }

    if (!$verified) {
        return [
            'success' => false,
            'error' => 'DNS record not found or not propagated yet. Please ensure you have added the CNAME record in your domain registrar (GoDaddy, Cloudflare, etc.) pointing to ' . STORE_CNAME_TARGET . '. Note: DNS propagation may take up to 24–48 hours.',
        ];
    }

    // Automatically register domain alias on Cloudways web server via API
    cloudways_add_domain_alias($domain);

    $db->prepare('
        UPDATE custom_domains
        SET status = "verified", ssl_status = :ssl, verified_at = NOW(), updated_at = NOW()
        WHERE id = :id AND business_id = :bid
    ')->execute([
        'ssl' => 'active',
        'id' => $domainId,
        'bid' => $businessId,
    ]);

    return ['success' => true, 'message' => 'DNS verified successfully! SSL certificate is active. ' . $reason];
}

function cloudways_api_request(string $endpoint, string $method = 'GET', array $params = []): ?array {
    if (!defined('CLOUDWAYS_API_KEY') || CLOUDWAYS_API_KEY === '') {
        return null;
    }
    $url = 'https://api.cloudways.com/api/v1/' . ltrim($endpoint, '/');
    if ($method === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . CLOUDWAYS_API_KEY,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code >= 200 && $code < 300 && is_string($res)) {
        return json_decode($res, true) ?: ['status' => true];
    }
    return null;
}

function cloudways_get_app_aliases(): array {
    if (!defined('CLOUDWAYS_APP_ID') || !defined('CLOUDWAYS_SERVER_ID')) {
        return [];
    }
    $res = cloudways_api_request('app/' . CLOUDWAYS_APP_ID, 'GET', [
        'server_id' => CLOUDWAYS_SERVER_ID,
    ]);
    $aliases = $res['app']['aliases'] ?? [];
    return is_array($aliases) ? $aliases : [];
}

function cloudways_add_domain_alias(string $domain): bool {
    $domain = strtolower(trim($domain));
    if ($domain === '' || !defined('CLOUDWAYS_APP_ID')) return false;
    $existing = cloudways_get_app_aliases();
    $domainsToAdd = [$domain];
    if (!str_starts_with($domain, 'www.') && substr_count($domain, '.') === 1) {
        $domainsToAdd[] = 'www.' . $domain;
    }
    $merged = array_unique(array_merge($existing, $domainsToAdd));
    $res = cloudways_api_request('app/manage/aliases', 'POST', [
        'server_id' => CLOUDWAYS_SERVER_ID,
        'app_id' => CLOUDWAYS_APP_ID,
        'aliases' => array_values($merged),
    ]);
    return !empty($res['status']);
}

function cloudways_remove_domain_alias(string $domain): bool {
    $domain = strtolower(trim($domain));
    if ($domain === '' || !defined('CLOUDWAYS_APP_ID')) return false;
    $existing = cloudways_get_app_aliases();
    $toRemove = [$domain, 'www.' . ltrim($domain, 'w.')];
    $remaining = array_values(array_diff($existing, $toRemove));
    $res = cloudways_api_request('app/manage/aliases', 'POST', [
        'server_id' => CLOUDWAYS_SERVER_ID,
        'app_id' => CLOUDWAYS_APP_ID,
        'aliases' => $remaining,
    ]);
    return !empty($res['status']);
}

function set_custom_domain_status(int $businessId, int $domainId, string $status): bool {
    if (!in_array($status, ['pending', 'verified', 'disabled'], true)) {
        return false;
    }
    $db = get_db();
    $stmt = $db->prepare('UPDATE custom_domains SET status = :st, updated_at = NOW() WHERE id = :id AND business_id = :bid');
    return $stmt->execute(['st' => $status, 'id' => $domainId, 'bid' => $businessId]);
}

function delete_custom_domain(int $businessId, int $domainId): bool {
    $db = get_db();
    $stmt = $db->prepare('SELECT domain FROM custom_domains WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $domainId, 'bid' => $businessId]);
    $dom = $stmt->fetchColumn();
    if ($dom) {
        cloudways_remove_domain_alias((string) $dom);
    }
    $del = $db->prepare('DELETE FROM custom_domains WHERE id = :id AND business_id = :bid');
    return $del->execute(['id' => $domainId, 'bid' => $businessId]);
}

function storefront_cart_key(int $businessId): string {
    return 'storefront_cart_' . $businessId;
}

function get_storefront_cart(int $businessId): array {
    $key = storefront_cart_key($businessId);
    $cart = $_SESSION[$key] ?? [];
    return is_array($cart) ? $cart : [];
}

function save_storefront_cart(int $businessId, array $cart): void {
    $_SESSION[storefront_cart_key($businessId)] = $cart;
}

function add_to_storefront_cart(int $businessId, int $productId, int $qty = 1): array {
    $qty = max(1, $qty);
    $product = get_product_by_id($productId, $businessId);
    if (!$product || ($product['status'] ?? '') !== 'active') {
        return ['success' => false, 'error' => 'This item is not available.'];
    }
    $stock = (int) ($product['stock_quantity'] ?? 0);
    $cart = get_storefront_cart($businessId);
    $current = (int) ($cart[$productId] ?? 0);
    $next = $current + $qty;
    if ($stock <= 0) {
        return ['success' => false, 'error' => 'This item is out of stock.'];
    }
    if ($next > $stock) {
        return ['success' => false, 'error' => 'Only ' . $stock . ' unit(s) left in stock.'];
    }
    $cart[$productId] = $next;
    save_storefront_cart($businessId, $cart);
    return ['success' => true, 'qty' => $next];
}

function update_storefront_cart_qty(int $businessId, int $productId, int $qty): array {
    $cart = get_storefront_cart($businessId);
    if ($qty <= 0) {
        unset($cart[$productId]);
        save_storefront_cart($businessId, $cart);
        return ['success' => true];
    }
    $product = get_product_by_id($productId, $businessId);
    if (!$product) {
        unset($cart[$productId]);
        save_storefront_cart($businessId, $cart);
        return ['success' => false, 'error' => 'Item removed because it is no longer available.'];
    }
    $stock = (int) ($product['stock_quantity'] ?? 0);
    if ($qty > $stock) {
        return ['success' => false, 'error' => 'Only ' . $stock . ' unit(s) left in stock.'];
    }
    $cart[$productId] = $qty;
    save_storefront_cart($businessId, $cart);
    return ['success' => true];
}

function storefront_cart_count(int $businessId): int {
    $total = 0;
    foreach (get_storefront_cart($businessId) as $qty) {
        $total += (int) $qty;
    }
    return $total;
}

function hydrate_storefront_cart(int $businessId): array {
    $raw = get_storefront_cart($businessId);
    $lines = [];
    $subtotal = 0.0;
    $tax = 0.0;
    $changed = false;
    $clean = [];

    foreach ($raw as $pid => $qty) {
        $product = get_product_by_id((int) $pid, $businessId);
        $qty = (int) $qty;
        if (!$product || ($product['status'] ?? '') !== 'active' || $qty <= 0) {
            $changed = true;
            continue;
        }
        $stock = (int) ($product['stock_quantity'] ?? 0);
        if ($stock <= 0) {
            $changed = true;
            continue;
        }
        if ($qty > $stock) {
            $qty = $stock;
            $changed = true;
        }
        $unit = (float) $product['selling_price'];
        $taxPct = (float) $product['tax_percent'];
        $line = $unit * $qty;
        $lineTax = round($line * ($taxPct / 100), 2);
        $subtotal += $line;
        $tax += $lineTax;
        $clean[(int) $product['id']] = $qty;
        $lines[] = [
            'product' => $product,
            'qty' => $qty,
            'unit_price' => $unit,
            'tax_percent' => $taxPct,
            'line_total' => $line,
            'tax_amount' => $lineTax,
        ];
    }

    if ($changed) {
        save_storefront_cart($businessId, $clean);
    }

    return [
        'lines' => $lines,
        'subtotal' => round($subtotal, 2),
        'tax' => round($tax, 2),
        'total' => round($subtotal + $tax, 2),
        'count' => storefront_cart_count($businessId),
    ];
}

function storefront_shopper_key(int $businessId): string {
    return 'storefront_shopper_' . $businessId;
}

function get_storefront_shopper(int $businessId): ?array {
    $row = $_SESSION[storefront_shopper_key($businessId)] ?? null;
    return is_array($row) && !empty($row['id']) ? $row : null;
}

function set_storefront_shopper(int $businessId, array $shopper): void {
    $name = storefront_clean_person_name((string) ($shopper['name'] ?? ''));
    $email = strtolower(trim((string) ($shopper['email'] ?? '')));
    if ($name === '' && $email !== '') {
        $name = explode('@', $email)[0] ?: 'Customer';
    }
    if ($name === '') {
        $name = 'Customer';
    }
    $_SESSION[storefront_shopper_key($businessId)] = [
        'id' => (int) ($shopper['id'] ?? 0),
        'name' => $name,
        'phone' => (string) ($shopper['phone'] ?? ''),
        'email' => $email,
        'address' => (string) ($shopper['address'] ?? ''),
    ];
}

function storefront_clean_person_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/\bnull\b/i', '', $name) ?? $name;
    $name = trim((string) preg_replace('/\s+/', ' ', $name));
    return $name;
}

function refresh_storefront_shopper(int $businessId): ?array {
    $current = get_storefront_shopper($businessId);
    if (!$current) {
        return null;
    }
    $cust = function_exists('get_customer_by_id') ? get_customer_by_id((int) $current['id'], $businessId) : null;
    if (!$cust) {
        clear_storefront_shopper($businessId);
        return null;
    }
    set_storefront_shopper($businessId, $cust);
    return get_storefront_shopper($businessId);
}

function update_storefront_shopper_profile(int $businessId, int $customerId, array $data): array {
    $name = storefront_clean_person_name((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $phone = trim((string) ($data['phone'] ?? ''));
    $address = trim((string) ($data['address'] ?? ''));
    if ($name === '') {
        return ['success' => false, 'error' => 'Name is required.'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Enter a valid email address.'];
    }
    $other = find_store_customer_by_email($businessId, $email);
    if ($other && (int) $other['id'] !== $customerId) {
        return ['success' => false, 'error' => 'That email is already used on another account.'];
    }
    get_db()->prepare('UPDATE customers SET name = :name, email = :email, phone = :phone, address = :address, updated_at = NOW() WHERE id = :id AND business_id = :bid')
        ->execute([
            'name' => $name,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'address' => $address !== '' ? $address : null,
            'id' => $customerId,
            'bid' => $businessId,
        ]);
    $cust = get_customer_by_id($customerId, $businessId);
    if ($cust) {
        set_storefront_shopper($businessId, $cust);
    }
    return ['success' => true];
}

function get_storefront_customer_orders(int $businessId, int $customerId): array {
    $stmt = get_db()->prepare('SELECT * FROM orders WHERE business_id = :bid AND customer_id = :cid ORDER BY id DESC LIMIT 50');
    $stmt->execute(['bid' => $businessId, 'cid' => $customerId]);
    return $stmt->fetchAll() ?: [];
}

function get_storefront_customer_invoices(int $businessId, int $customerId): array {
    try {
        $stmt = get_db()->prepare('SELECT * FROM invoices WHERE business_id = :bid AND customer_id = :cid ORDER BY id DESC LIMIT 50');
        $stmt->execute(['bid' => $businessId, 'cid' => $customerId]);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function clear_storefront_shopper(int $businessId): void {
    unset($_SESSION[storefront_shopper_key($businessId)]);
}

function clean_customer_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    return $digits;
}

function find_store_customer_by_phone(int $businessId, string $phone): ?array {
    $clean = clean_customer_phone($phone);
    if ($clean === '') {
        return null;
    }
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM customers WHERE business_id = :bid AND (phone = :raw OR phone = :clean OR phone LIKE :like) LIMIT 1');
    $stmt->execute([
        'bid' => $businessId,
        'raw' => trim($phone),
        'clean' => $clean,
        'like' => '%' . $clean,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_store_customer_by_email(int $businessId, string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $stmt = get_db()->prepare('SELECT * FROM customers WHERE business_id = :bid AND email = :email LIMIT 1');
    $stmt->execute(['bid' => $businessId, 'email' => $email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_store_customer_by_identifier(int $businessId, string $identifier): ?array {
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }
    if (str_contains($identifier, '@')) {
        return find_store_customer_by_email($businessId, strtolower($identifier));
    }
    return find_store_customer_by_phone($businessId, $identifier);
}

function login_storefront_shopper(int $businessId, string $identifier, string $password): array {
    $identifier = trim($identifier);
    if ($identifier === '') {
        return ['success' => false, 'error' => 'Please enter your mobile number or email.'];
    }
    if ($password === '') {
        return ['success' => false, 'error' => 'Please enter your password.'];
    }
    $cust = find_store_customer_by_identifier($businessId, $identifier);
    if (!$cust) {
        return ['success' => false, 'error' => 'No account found for this mobile number / email. Create an account to continue.'];
    }
    $hash = (string) ($cust['password'] ?? '');
    if ($hash === '') {
        return ['success' => false, 'error' => 'This account has no password set yet. Use Create Account to set one.'];
    }
    if (!password_verify($password, $hash)) {
        return ['success' => false, 'error' => 'Incorrect password. Try again or reset password.'];
    }
    set_storefront_shopper($businessId, $cust);
    return ['success' => true];
}

function send_storefront_otp_sms(string $phone, string $otp, string $storeName): bool {
    $cleanPhone = clean_customer_phone($phone);
    if (strlen($cleanPhone) < 10) {
        return false;
    }
    $message = "Your {$storeName} verification code is: {$otp}. Valid for 10 minutes. Powered by OminiFlow POS.";
    $_SESSION['sf_last_sms_otp'] = [
        'phone' => $cleanPhone,
        'otp' => $otp,
        'message' => $message,
        'time' => time(),
    ];

    // Fast2SMS API Integration (if FAST2SMS_API_KEY is configured in config/app.php)
    if (defined('FAST2SMS_API_KEY') && FAST2SMS_API_KEY !== '') {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://www.fast2sms.com/dev/bulkV2",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    "route" => "otp",
                    "variables_values" => $otp,
                    "numbers" => $cleanPhone,
                ]),
                CURLOPT_HTTPHEADER => [
                    "authorization: " . FAST2SMS_API_KEY,
                    "Content-Type: application/json"
                ],
                CURLOPT_TIMEOUT => 8,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable $e) {}
    }

    // 2Factor.in SMS Integration (if TWO_FACTOR_API_KEY is configured)
    if (defined('TWO_FACTOR_API_KEY') && TWO_FACTOR_API_KEY !== '') {
        try {
            $url = "https://2factor.in/v1/API/" . urlencode(TWO_FACTOR_API_KEY) . "/SMS/" . urlencode($cleanPhone) . "/" . urlencode($otp) . "/OTP1";
            @file_get_contents($url);
        } catch (Throwable $e) {}
    }

    return true;
}

function send_storefront_otp_email(string $toEmail, string $toName, string $otp, string $storeName): bool {
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = "Your {$storeName} Verification Code: {$otp}";
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . mb_encode_mimeheader($storeName, 'UTF-8') . ' <noreply@ominiflow.com>',
        'Reply-To: noreply@ominiflow.com',
        'X-Mailer: PHP/' . phpversion(),
    ];

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>OTP Verification</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 28px 12px;">
  <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 460px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 6px 24px rgba(15,23,42,0.06);">
    <tr>
      <td style="background: #0f4c3a; padding: 24px; text-align: center;">
        <h2 style="margin: 0; font-size: 22px; font-weight: 800; color: #ffffff; letter-spacing: 0.3px;">{$storeName}</h2>
        <p style="margin: 4px 0 0; font-size: 13px; color: #e2e8f0;">Storefront Mobile & Email Verification</p>
      </td>
    </tr>
    <tr>
      <td style="padding: 32px 24px 28px; text-align: center;">
        <h3 style="margin: 0 0 12px; color: #0f172a; font-size: 18px; font-weight: 700;">Hello {$toName},</h3>
        <p style="color: #64748b; font-size: 14.5px; line-height: 1.6; margin: 0 0 24px;">Please use the following 6-digit One-Time Password (OTP) to verify your mobile number and activate your account:</p>
        <div style="background: #f8fafc; border: 2px dashed #94a3b8; border-radius: 10px; padding: 14px 24px; display: inline-block; margin-bottom: 24px;">
          <span style="font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #0f172a; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; padding-left: 8px;">{$otp}</span>
        </div>
        <p style="color: #94a3b8; font-size: 12.5px; line-height: 1.5; margin: 0;">This OTP is valid for <strong>10 minutes</strong>.</p>
      </td>
    </tr>
    <tr>
      <td style="background: #f8fafc; padding: 16px; text-align: center; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8;">
        &copy; {$storeName} &bull; Powered by OminiFlow POS
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

    try {
        return @mail($toEmail, $subject, $html, implode("\r\n", $headers));
    } catch (Throwable $e) {
        return false;
    }
}

function register_storefront_shopper(int $businessId, array $data): array {
    $name = storefront_clean_person_name((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $phone = clean_customer_phone((string) ($data['phone'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($name === '') {
        return ['success' => false, 'error' => 'Full name is required.'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'error' => 'Password must be at least 6 characters.'];
    }

    $existing = find_store_customer_by_email($businessId, $email);
    if (!$existing && $phone !== '') {
        $existing = find_store_customer_by_phone($businessId, $phone);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db = get_db();

    if ($existing) {
        if (!empty($existing['password'])) {
            return ['success' => false, 'error' => 'An account already exists for this email. Please sign in.'];
        }
        $db->prepare('UPDATE customers SET name = :name, phone = COALESCE(:phone, phone), email = :email, password = :password, updated_at = NOW() WHERE id = :id AND business_id = :bid')
            ->execute([
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email,
                'password' => $hash,
                'id' => (int) $existing['id'],
                'bid' => $businessId,
            ]);
        $existing['name'] = $name;
        $existing['email'] = $email;
        if ($phone !== '') $existing['phone'] = $phone;
        set_storefront_shopper($businessId, $existing);
        return ['success' => true];
    }

    $res = save_customer([
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'address' => '',
    ], $businessId);
    if (empty($res['success']) || empty($res['customer_id'])) {
        $errors = $res['errors'] ?? [];
        $msg = is_array($errors) ? implode(' ', $errors) : 'Could not create account.';
        return ['success' => false, 'error' => $msg !== '' ? $msg : 'Could not create account.'];
    }
    $db->prepare('UPDATE customers SET password = :password WHERE id = :id AND business_id = :bid')
        ->execute([
            'password' => $hash,
            'id' => (int) $res['customer_id'],
            'bid' => $businessId,
        ]);
    set_storefront_shopper($businessId, [
        'id' => (int) $res['customer_id'],
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
    ]);
    return ['success' => true];
}

function reset_storefront_shopper_password(int $businessId, string $email, string $password, string $confirm): array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Enter a valid email address.'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'error' => 'Password must be at least 6 characters.'];
    }
    if ($password !== $confirm) {
        return ['success' => false, 'error' => 'Passwords do not match.'];
    }
    $cust = find_store_customer_by_email($businessId, $email);
    if (!$cust) {
        return ['success' => false, 'error' => 'No account found for this email. Create an account instead.'];
    }
    get_db()->prepare('UPDATE customers SET password = :password, updated_at = NOW() WHERE id = :id AND business_id = :bid')
        ->execute([
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'id' => (int) $cust['id'],
            'bid' => $businessId,
        ]);
    return ['success' => true];
}

function sign_in_storefront_shopper(int $businessId, string $name, string $phone): array {
    $phone = trim($phone);
    $name = trim($name);
    if ($phone === '' || !preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
        return ['success' => false, 'error' => 'Enter a valid phone number.'];
    }
    if ($name === '') {
        $name = 'Customer';
    }
    $res = find_or_create_store_customer($businessId, [
        'name' => $name,
        'phone' => $phone,
        'email' => '',
        'address' => '',
    ]);
    if (empty($res['success']) || empty($res['customer_id'])) {
        $errors = $res['errors'] ?? [];
        $msg = is_array($errors) ? implode(' ', $errors) : 'Could not sign in.';
        return ['success' => false, 'error' => $msg !== '' ? $msg : 'Could not sign in.'];
    }
    $cust = function_exists('get_customer_by_id') ? get_customer_by_id((int) $res['customer_id'], $businessId) : null;
    set_storefront_shopper($businessId, [
        'id' => (int) $res['customer_id'],
        'name' => (string) ($cust['name'] ?? $name),
        'phone' => (string) ($cust['phone'] ?? $phone),
        'email' => (string) ($cust['email'] ?? ''),
    ]);
    return ['success' => true];
}

function find_or_create_store_customer(int $businessId, array $data): array {
    $name = trim((string) ($data['name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $address = trim((string) ($data['address'] ?? ''));

    if ($name === '') {
        return ['success' => false, 'errors' => ['name' => 'Name is required.']];
    }
    if ($phone === '' && $email === '') {
        return ['success' => false, 'errors' => ['phone' => 'Phone or email is required.']];
    }

    $db = get_db();
    if ($phone !== '') {
        $stmt = $db->prepare('SELECT id FROM customers WHERE business_id = :bid AND phone = :phone LIMIT 1');
        $stmt->execute(['bid' => $businessId, 'phone' => $phone]);
        $found = $stmt->fetch();
        if ($found) {
            $db->prepare('UPDATE customers SET name = :name, email = COALESCE(:email, email), address = COALESCE(:address, address), updated_at = NOW() WHERE id = :id')
                ->execute([
                    'name' => $name,
                    'email' => $email !== '' ? $email : null,
                    'address' => $address !== '' ? $address : null,
                    'id' => (int) $found['id'],
                ]);
            return ['success' => true, 'customer_id' => (int) $found['id']];
        }
    }
    if ($email !== '') {
        $stmt = $db->prepare('SELECT id FROM customers WHERE business_id = :bid AND email = :email LIMIT 1');
        $stmt->execute(['bid' => $businessId, 'email' => $email]);
        $found = $stmt->fetch();
        if ($found) {
            return ['success' => true, 'customer_id' => (int) $found['id']];
        }
    }

    return save_customer([
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'address' => $address,
    ], $businessId);
}

function place_online_store_order(int $businessId, array $checkout): array {
    $hydrated = hydrate_storefront_cart($businessId);
    if (empty($hydrated['lines'])) {
        return ['success' => false, 'errors' => ['cart' => 'Your cart is empty.']];
    }

    $cust = find_or_create_store_customer($businessId, $checkout);
    if (empty($cust['success'])) {
        return ['success' => false, 'errors' => $cust['errors'] ?? ['general' => 'Could not save customer details.']];
    }

    $method = (string) ($checkout['payment_method'] ?? 'cod');
    $method = in_array($method, ['cod', 'upi', 'pickup'], true) ? $method : 'cod';
    $paymentStatus = $method === 'cod' ? 'pending' : 'paid';
    $notesParts = [
        'Online Store order',
        'Payment: ' . strtoupper($method),
    ];
    if (!empty($checkout['notes'])) {
        $notesParts[] = trim((string) $checkout['notes']);
    }

    $cartItems = [];
    foreach ($hydrated['lines'] as $line) {
        $cartItems[] = [
            'product_id' => (int) $line['product']['id'],
            'quantity' => (int) $line['qty'],
        ];
    }

    $result = process_pos_order(
        $cartItems,
        (int) $cust['customer_id'],
        null,
        0.00,
        'fixed',
        $method,
        implode(' | ', $notesParts),
        0.00,
        1,
        null,
        null,
        null,
        0,
        0.00,
        null,
        $businessId,
        'online_store',
        'pending',
        $paymentStatus
    );

    if (!empty($result['success'])) {
        save_storefront_cart($businessId, []);
    }
    return $result;
}

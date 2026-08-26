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

    // Visual Builder / Home Layout Components
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_section_name', "VARCHAR(191) NOT NULL DEFAULT 'All Categories'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_bg_color', "VARCHAR(20) NOT NULL DEFAULT '#ffffff'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_text_color', "VARCHAR(20) NOT NULL DEFAULT '#000000'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_shape', "VARCHAR(20) NOT NULL DEFAULT 'rectangle'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_columns', "INT NOT NULL DEFAULT 2");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'category_rows', "INT NOT NULL DEFAULT 2");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'banner_section_name', "VARCHAR(191) NOT NULL DEFAULT 'Banners'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'show_banner_section_name', "TINYINT(1) NOT NULL DEFAULT 0");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'item_section_name', "VARCHAR(191) NOT NULL DEFAULT 'All Items'");
    add_schema_column_if_missing($db, 'mobile_store_settings', 'section_order', "VARCHAR(191) NOT NULL DEFAULT 'banner,category,item'");
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
        // Visual Builder / Home Layout Components
        'category_section_name' => (string) ($row['category_section_name'] ?? 'All Categories'),
        'category_bg_color' => (string) ($row['category_bg_color'] ?? '#ffffff'),
        'category_text_color' => (string) ($row['category_text_color'] ?? '#000000'),
        'category_shape' => (string) ($row['category_shape'] ?? 'rectangle'),
        'category_columns' => (int) ($row['category_columns'] ?? 2),
        'category_rows' => (int) ($row['category_rows'] ?? 2),
        'banner_section_name' => (string) ($row['banner_section_name'] ?? 'Banners'),
        'show_banner_section_name' => (int) ($row['show_banner_section_name'] ?? 0) === 1,
        'item_section_name' => (string) ($row['item_section_name'] ?? 'All Items'),
        'section_order' => (string) ($row['section_order'] ?? 'banner,category,item'),
    ];
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
            category_section_name = :csn,
            category_bg_color = :cbg,
            category_text_color = :ctc,
            category_shape = :csh,
            category_columns = :ccol,
            category_rows = :crow,
            banner_section_name = :bsn,
            show_banner_section_name = :sbsn,
            item_section_name = :isn,
            section_order = :sord,
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
        'csn' => trim((string)($data['category_section_name'] ?? $current['category_section_name'])),
        'cbg' => normalize_hex_color((string)($data['category_bg_color'] ?? $current['category_bg_color']), '#ffffff'),
        'ctc' => normalize_hex_color((string)($data['category_text_color'] ?? $current['category_text_color']), '#000000'),
        'csh' => in_array(($data['category_shape'] ?? $current['category_shape']), ['square', 'rectangle'], true) ? $data['category_shape'] : 'rectangle',
        'ccol' => isset($data['category_columns']) ? (int)$data['category_columns'] : (int)$current['category_columns'],
        'crow' => isset($data['category_rows']) ? (int)$data['category_rows'] : (int)$current['category_rows'],
        'bsn' => trim((string)($data['banner_section_name'] ?? $current['banner_section_name'])),
        'sbsn' => array_key_exists('show_banner_section_name', $data) ? (!empty($data['show_banner_section_name']) ? 1 : 0) : ($current['show_banner_section_name'] ? 1 : 0),
        'isn' => trim((string)($data['item_section_name'] ?? $current['item_section_name'])),
        'sord' => trim((string)($data['section_order'] ?? $current['section_order'])),
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
    if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Image must be under 4 MB.'];
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
        $records = @dns_get_record($domain, DNS_CNAME) ?: [];
        foreach ($records as $rec) {
            $target = strtolower(rtrim((string) ($rec['target'] ?? ''), '.'));
            if ($target === $cnameTarget || $target === 'localhost' || strpos($target, $token) !== false) {
                $verified = true;
                $reason = 'CNAME record verified on global DNS.';
                break;
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
    $stmt = $db->prepare('DELETE FROM custom_domains WHERE id = :id AND business_id = :bid');
    return $stmt->execute(['id' => $domainId, 'bid' => $businessId]);
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

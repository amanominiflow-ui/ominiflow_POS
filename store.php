<?php
/**
 * Public mobile storefront — client branding only (never platform logo).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/storefront_db.php';
require_once __DIR__ . '/includes/variants_db.php';

ensure_online_store_schema();

$slugParam = trim((string) ($_GET['slug'] ?? ''));
$storeBiz = resolve_store_business_from_request($slugParam !== '' ? $slugParam : null);

$brand = [
    'display_name' => 'Store',
    'logo_path' => null,
    'initials' => 'ST',
    'header_color' => '#0f4c3a',
    'accent_color' => '#2563eb',
    'banner_title' => "We're online now!",
    'banner_subtitle' => 'Stay at home and shop online.',
    'banner_image' => null,
    'search_placeholder' => 'Search by item or category',
    'show_location' => true,
    'show_banner' => true,
    'show_categories' => true,
    'show_items' => true,
    'phone' => '',
];

if (!$storeBiz) {
    http_response_code(404);
    $pageTitle = 'Store not found';
    $storeNotFound = true;
    $published = false;
    $cartCount = 0;
    $page = 'missing';
    $currency = '₹';
    $homeUrl = $cartUrl = $checkoutUrl = '#';
    $bid = 0;
    $drawerCart = ['lines' => [], 'subtotal' => 0.0, 'tax' => 0.0, 'total' => 0.0, 'count' => 0];
    $openCartDrawer = false;
    $openAccountDrawer = false;
    $storeShopper = null;
} else {
    $storeNotFound = false;
    $bid = (int) $storeBiz['id'];
    $brand = get_mobile_store_settings($bid);
    $storeSettings = get_store_settings($bid);
    $pageTitle = (string) $brand['display_name'];
    $published = (int) ($storeBiz['store_published'] ?? 1) === 1;
    $page = trim((string) ($_GET['page'] ?? 'home'));
    if (!in_array($page, ['home', 'product', 'cart', 'checkout', 'thanks', 'orders', 'invoices', 'addresses', 'profile', 'privacy', 'contact'], true)) {
        $page = 'home';
    }

    $flashSuccess = get_flash('success');
    $flashError = get_flash('error');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash('error', 'Your session expired. Please try again.');
            redirect(public_store_url($storeBiz, $page, $_GET));
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add_to_cart') {
            $pid = (int) ($_POST['product_id'] ?? 0);
            $qty = max(1, (int) ($_POST['qty'] ?? 1));
            $back = (string) ($_POST['redirect_page'] ?? 'home');

            $res = add_to_storefront_cart($bid, $pid, $qty);
            set_flash(!empty($res['success']) ? 'success' : 'error', !empty($res['success']) ? 'Added to cart.' : ($res['error'] ?? 'Could not add item.'));
            $params = $back === 'product' ? ['id' => $pid] : [];
            if (!empty($_GET['category_id'])) {
                $params['category_id'] = (int) $_GET['category_id'];
            }
            if (!empty($_GET['q'])) {
                $params['q'] = (string) $_GET['q'];
            }
            $params['cart'] = '1';
            redirect(public_store_url($storeBiz, $back === 'product' ? 'product' : 'home', $params));
        }

        if ($action === 'update_cart') {
            $res = update_storefront_cart_qty($bid, (int) ($_POST['product_id'] ?? 0), (int) ($_POST['qty'] ?? 0));
            if (empty($res['success']) && !empty($res['error'])) {
                set_flash('error', $res['error']);
            }
            $allowedReturn = ['home', 'product', 'cart', 'checkout', 'thanks', 'orders', 'invoices', 'addresses', 'profile'];
            $returnPage = (string) ($_POST['return_page'] ?? $page);
            if (!in_array($returnPage, $allowedReturn, true)) {
                $returnPage = 'home';
            }
            $params = ['cart' => '1'];
            if ($returnPage === 'product') {
                $params['id'] = (int) ($_POST['return_id'] ?? $_GET['id'] ?? 0);
            }
            if (!empty($_GET['category_id'])) {
                $params['category_id'] = (int) $_GET['category_id'];
            }
            if (!empty($_GET['q'])) {
                $params['q'] = (string) $_GET['q'];
            }
            $target = $returnPage === 'cart' ? 'home' : $returnPage;
            redirect(public_store_url($storeBiz, $target, $params));
        }

        if ($action === 'storefront_signout') {
            clear_storefront_shopper($bid);
            set_flash('success', 'Signed out.');
            redirect(public_store_url($storeBiz, 'home'));
        }

        if ($action === 'save_delivery_location') {
            $delName = storefront_clean_person_name((string) ($_POST['name'] ?? ''));
            $doorNo = trim((string) ($_POST['door_no'] ?? ''));
            $street = trim((string) ($_POST['street_area'] ?? ''));
            $city = trim((string) ($_POST['city'] ?? ''));
            $state = trim((string) ($_POST['state'] ?? ''));
            $pincode = trim((string) ($_POST['pincode'] ?? ''));
            $country = trim((string) ($_POST['country'] ?? 'India'));
            $phone = trim((string) ($_POST['phone'] ?? ''));

            $addrParts = array_filter([$doorNo, $street, $city, $state ? ($state . ($pincode ? ' - ' . $pincode : '')) : $pincode, $country]);
            $fullAddress = implode(', ', $addrParts);

            $_SESSION['sf_delivery_location_' . $bid] = [
                'name' => $delName,
                'door_no' => $doorNo,
                'street_area' => $street,
                'city' => $city,
                'state' => $state,
                'pincode' => $pincode,
                'country' => $country,
                'phone' => $phone,
                'formatted' => $fullAddress,
                'display' => $city !== '' ? ($street !== '' ? ($street . ', ' . $city) : $city) : ($state !== '' ? $state : ($doorNo !== '' ? $doorNo : $fullAddress)),
            ];

            $shopper = get_storefront_shopper($bid);
            if ($shopper) {
                update_storefront_shopper_profile($bid, (int)$shopper['id'], [
                    'name' => $delName !== '' ? $delName : ($shopper['name'] ?? ''),
                    'phone' => $phone !== '' ? $phone : ($shopper['phone'] ?? ''),
                    'address' => $fullAddress,
                ]);
            }

            set_flash('success', 'Delivery address saved.');
            $retPage = (string)($_POST['return_page'] ?? $page);
            redirect(public_store_url($storeBiz, $retPage));
        }

        if ($action === 'update_profile' || $action === 'update_address') {
            $shopper = get_storefront_shopper($bid);
            if (!$shopper) {
                redirect(public_store_signin_url($storeBiz));
            }
            $res = update_storefront_shopper_profile($bid, (int) $shopper['id'], [
                'name' => (string) ($_POST['name'] ?? $shopper['name']),
                'email' => (string) ($_POST['email'] ?? $shopper['email']),
                'phone' => (string) ($_POST['phone'] ?? $shopper['phone']),
                'address' => (string) ($_POST['address'] ?? $shopper['address'] ?? ''),
            ]);
            set_flash(!empty($res['success']) ? 'success' : 'error', !empty($res['success']) ? 'Saved.' : ($res['error'] ?? 'Could not save.'));
            redirect(public_store_url($storeBiz, $action === 'update_address' ? 'addresses' : 'profile'));
        }

        if ($action === 'place_order') {
            $shopper = get_storefront_shopper($bid);
            if (!$shopper) {
                set_flash('error', 'Please sign in or create an account with your mobile number to complete your order.');
                redirect(public_store_signin_url($storeBiz, ['return' => 'checkout']));
            }
            $result = place_online_store_order($bid, [
                'name' => (string) ($_POST['name'] ?? $shopper['name'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? $shopper['phone'] ?? ''),
                'email' => (string) ($_POST['email'] ?? $shopper['email'] ?? ''),
                'address' => (string) ($_POST['address'] ?? $shopper['address'] ?? ''),
                'notes' => (string) ($_POST['notes'] ?? ''),
                'payment_method' => (string) ($_POST['payment_method'] ?? 'cod'),
            ]);
            if (!empty($result['success'])) {
                redirect(public_store_url($storeBiz, 'thanks', [
                    'order' => (string) ($result['order_number'] ?? ''),
                    'total' => (string) ($result['total_amount'] ?? ''),
                ]));
            }
            $msg = is_array($result['errors'] ?? null) ? implode(' ', $result['errors']) : 'Could not place order.';
            set_flash('error', $msg);
            redirect(public_store_url($storeBiz, 'checkout'));
        }
    }

    $cartCount = storefront_cart_count($bid);
    $currency = (string) ($storeSettings['currency_symbol'] ?? $storeBiz['currency_symbol'] ?? '₹');
    $homeUrl = public_store_url($storeBiz, 'home');
    $cartUrl = public_store_url($storeBiz, 'cart');
    $checkoutUrl = public_store_url($storeBiz, 'checkout');
    $drawerCart = hydrate_storefront_cart($bid);
    $cartCount = (int) ($drawerCart['count'] ?? $cartCount);
    $storeShopper = refresh_storefront_shopper($bid);
    $openAccountDrawer = !empty($_GET['account']);
    $openCartDrawer = (!$openAccountDrawer) && (!empty($_GET['cart']) || $page === 'cart');
    if (in_array($page, ['orders', 'invoices', 'addresses', 'profile', 'checkout'], true) && !$storeShopper) {
        $ret = $page === 'checkout' ? 'checkout' : 'account';
        redirect(public_store_signin_url($storeBiz, ['return' => $ret]));
    }
}

if (!function_exists('sf_money')) {
    function sf_money(string $symbol, float $amount): string {
        return e($symbol) . number_format($amount, 2);
    }
}

if (!function_exists('sf_product_image')) {
    function sf_product_image(?string $path): string {
        return $path ? asset($path) : '';
    }
}

if (!function_exists('storefront_parse_product_display_info')) {
    function storefront_parse_product_display_info(array $p, int $businessId): array {
        $variants = function_exists('get_product_variants') ? get_product_variants((int) $p['id'], $businessId) : [];
        $varCount = count($variants);
        
        $attrText = '';
        $mrp = (float) ($p['mrp'] ?? 0);
        $sellingPrice = (float) ($p['selling_price'] ?? 0);
        
        if ($varCount > 0) {
            $firstVar = $variants[0];
            $vName = trim((string) ($firstVar['variant_name'] ?? ''));
            
            if (!empty($firstVar['selling_price']) && (float) $firstVar['selling_price'] > 0) {
                $sellingPrice = (float) $firstVar['selling_price'];
            }
            
            if ($vName !== '') {
                if (stripos($vName, 'COLOUR:') !== false || stripos($vName, 'SIZE:') !== false) {
                    $attrText = strtoupper($vName);
                } elseif (strpos($vName, '/') !== false) {
                    $parts = array_map('trim', explode('/', $vName));
                    if (count($parts) >= 2) {
                        $attrText = 'COLOUR: ' . strtoupper($parts[0]) . ', SIZES: ' . strtoupper($parts[1]);
                    } else {
                        $attrText = 'COLOUR: ' . strtoupper($vName);
                    }
                } elseif (strpos($vName, '-') !== false && !is_numeric($vName)) {
                    $parts = array_map('trim', explode('-', $vName));
                    if (count($parts) >= 2 && !is_numeric($parts[0])) {
                        $attrText = 'COLOUR: ' . strtoupper($parts[0]) . ', SIZES: ' . strtoupper($parts[1]);
                    } else {
                        $attrText = 'COLOUR: ' . strtoupper($vName);
                    }
                } else {
                    $attrText = 'COLOUR: ' . strtoupper($vName);
                }
            }
        } else {
            $pName = (string) ($p['name'] ?? '');
            if (preg_match('/-([a-zA-Z\s]+)-([a-zA-Z0-9]+)$/i', $pName, $m)) {
                $attrText = 'COLOUR: ' . strtoupper(trim($m[1])) . ', SIZES: ' . strtoupper(trim($m[2]));
            } elseif (preg_match('/-([a-zA-Z\s]+)$/i', $pName, $m)) {
                $attrText = 'COLOUR: ' . strtoupper(trim($m[1]));
            }
        }

        $discountPercent = 0;
        if ($mrp > $sellingPrice && $mrp > 0) {
            $discountPercent = (int) round((($mrp - $sellingPrice) / $mrp) * 100);
        }

        return [
            'variants' => $variants,
            'variant_count' => $varCount,
            'attr_text' => $attrText,
            'selling_price' => $sellingPrice,
            'mrp' => $mrp,
            'discount_percent' => $discountPercent,
        ];
    }
}

$favicon = get_storefront_dynamic_favicon_url($brand, $pageTitle);
$fontSize = in_array(($brand['font_size'] ?? 'medium'), ['small', 'medium', 'large'], true) ? $brand['font_size'] : 'medium';
$headerText = (string) ($brand['header_text_color'] ?? '#ffffff');
$buttonText = (string) ($brand['button_text_color'] ?? '#ffffff');

$searchCategories = [];
if (!empty($bid)) {
    try {
        $rawCats = get_categories('', 'active', $bid);
        foreach ($rawCats as $rc) {
            $cName = trim((string) ($rc['name'] ?? ''));
            if ($cName !== '' && !in_array($cName, $searchCategories, true)) {
                $searchCategories[] = $cName;
            }
        }
    } catch (Throwable $e) {}

    if (count($searchCategories) < 4) {
        try {
            $rawProds = get_products('', null, 'active', '', $bid);
            foreach ($rawProds as $rp) {
                $pName = trim((string) ($rp['name'] ?? ''));
                if ($pName !== '' && !in_array($pName, $searchCategories, true)) {
                    $searchCategories[] = $pName;
                }
                if (count($searchCategories) >= 6) {
                    break;
                }
            }
        } catch (Throwable $e) {}
    }
}
if (empty($searchCategories)) {
    $searchCategories = ['All Products', 'Fresh Items', 'Best Sellers'];
} elseif (count($searchCategories) === 1) {
    $searchCategories[] = 'All Products';
    $searchCategories[] = 'Best Sellers';
}
$cssVersion = (@filemtime(__DIR__ . '/assets/css/storefront.css') ?: 20) . '.' . 102;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e($favicon) ?>">
    <link rel="alternate icon" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="apple-touch-icon" href="<?= e($favicon) ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/storefront.css') ?>?v=<?= $cssVersion ?>">
    <style>
        :root {
            --ms-header: <?= e($brand['header_color']) ?>;
            --ms-header-text: <?= e($headerText) ?>;
            --ms-accent: <?= e($brand['accent_color']) ?>;
            --ms-btn-text: <?= e($buttonText) ?>;
            --ms-font: <?= $fontSize === 'small' ? '13px' : ($fontSize === 'large' ? '16px' : '14px') ?>;
        }
        body.ms-body { font-size: var(--ms-font); }
        .ms-top-nav, .ms-title, .ms-nav-item, .ms-location-widget { color: var(--ms-header-text, #ffffff); }
        .ms-add-btn, .ms-btn { color: var(--ms-btn-text, #ffffff); }

        /* Critical Search Overlay Styles to eliminate caching/rendering glitches */
        .ms-search-wrap {
            position: relative;
            flex: 1 1 auto;
            max-width: 560px;
            min-width: 0;
            margin: 0;
        }
        .ms-search-box {
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
        }
        .ms-search {
            width: 100%;
            border: 0;
            border-radius: 999px;
            padding: 10px 16px 10px 40px;
            font: inherit;
            font-size: 14px;
            background: #ffffff;
            color: #0f172a;
            outline: none;
            position: relative;
            z-index: 1;
        }
        .ms-search::placeholder {
            color: transparent !important;
        }
        .ms-search-placeholder {
            position: absolute !important;
            left: 40px !important;
            right: 16px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            display: flex !important;
            align-items: center !important;
            pointer-events: none !important;
            z-index: 2 !important;
            font-size: 13.5px !important;
            color: #94a3b8 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            height: 22px !important;
        }
        .ms-search-wrap.is-active .ms-search-placeholder,
        .ms-search-wrap.has-val .ms-search-placeholder {
            display: none !important;
        }
        .ms-sp-prefix {
            font-weight: 500;
            color: #94a3b8;
            flex-shrink: 0;
        }
        .ms-sp-track {
            position: relative;
            display: inline-block;
            height: 22px;
            overflow: hidden;
            vertical-align: middle;
            margin-left: 2px;
            flex: 1;
            min-width: 0;
        }
        .ms-sp-word {
            display: block;
            line-height: 22px;
            font-weight: 600;
            color: #475569;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transform: translate3d(0, 0, 0);
            will-change: transform, opacity;
        }
        .ms-sp-word.ms-slide-in {
            animation: msSearchSlideIn 0.3s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }
        .ms-sp-word.ms-slide-out {
            animation: msSearchSlideOut 0.26s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }
        @keyframes msSearchSlideIn {
            0% { transform: translate3d(0, 80%, 0); opacity: 0; }
            100% { transform: translate3d(0, 0, 0); opacity: 1; }
        }
        @keyframes msSearchSlideOut {
            0% { transform: translate3d(0, 0, 0); opacity: 1; }
            100% { transform: translate3d(0, -80%, 0); opacity: 0; }
        }
        @media (max-width: 768px) {
            .ms-search-wrap {
                order: 3;
                width: 100%;
                max-width: 100%;
                flex: 1 1 100%;
            }
            .ms-search {
                padding: 9px 14px 9px 38px;
                font-size: 13.5px;
            }
            .ms-search-placeholder {
                left: 38px !important;
                right: 14px !important;
                font-size: 13px !important;
            }
        }

        /* Storefront Footer */
        .ms-footer {
            width: 100%;
            background-color: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 36px 24px 44px;
            margin-top: 48px;
        }
        .ms-footer-wrap {
            max-width: 1200px;
            margin: 0 auto;
        }
        .ms-footer-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }
        .ms-footer-brand-box {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .ms-footer-name {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin: 0;
        }
        .ms-footer-location {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
            line-height: 1.4;
        }
        .ms-footer-links-box {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .ms-footer-nav-link {
            font-size: 13.5px;
            font-weight: 600;
            color: #475569;
            text-decoration: none;
            transition: color 0.15s;
        }
        .ms-footer-nav-link:hover {
            color: #0f172a;
            text-decoration: underline;
        }
        .ms-footer-nav-dot {
            color: #94a3b8;
            font-weight: bold;
        }

        /* Legal & Contact Us Pages */
        .ms-legal-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 36px 32px;
            max-width: 860px;
            margin: 20px auto 40px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .ms-legal-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13.5px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            margin-bottom: 20px;
            transition: color 0.15s;
        }
        .ms-legal-back:hover {
            color: #0f172a;
        }
        .ms-legal-title {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 6px;
            line-height: 1.25;
        }
        .ms-legal-subtitle {
            font-size: 14.5px;
            color: #64748b;
            margin: 0 0 24px;
        }
        .ms-legal-meta {
            font-size: 12.5px;
            color: #94a3b8;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        .ms-legal-content {
            font-size: 14.5px;
            color: #334155;
            line-height: 1.7;
        }
        .ms-legal-content h3 {
            font-size: 16.5px;
            font-weight: 700;
            color: #0f172a;
            margin: 24px 0 8px;
        }
        .ms-legal-content p {
            margin: 0 0 14px;
        }
        .ms-legal-content ul {
            margin: 0 0 16px 20px;
            padding: 0;
        }
        .ms-legal-content li {
            margin-bottom: 6px;
        }

        /* Contact Us Grid */
        .ms-contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-top: 24px;
        }
        .ms-contact-card {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 18px 20px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            transition: border-color 0.15s, transform 0.15s;
        }
        .ms-contact-card:hover {
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }
        .ms-contact-icon {
            width: 42px;
            height: 42px;
            border-radius: 8px;
            background: #e2e8f0;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .ms-contact-label {
            font-size: 12.5px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 4px;
        }
        .ms-contact-val {
            font-size: 14.5px;
            font-weight: 600;
            color: #0f172a;
            text-decoration: none;
            line-height: 1.4;
            display: block;
        }
        a.ms-contact-val:hover {
            color: var(--ms-accent, #2563eb);
            text-decoration: underline;
        }
        .ms-contact-card-wa {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }
        .ms-contact-icon-wa {
            background: #22c55e;
            color: #ffffff;
        }
        .ms-contact-val-wa {
            color: #166534 !important;
            font-weight: 700;
        }
    </style>
</head>
<body class="ms-body ms-font-<?= e($fontSize) ?><?= (!empty($openCartDrawer) || !empty($openAccountDrawer)) ? ' ms-cart-lock' : '' ?>">
<div class="ms-app">
    <header class="ms-top-nav">
        <div class="ms-top-container">
            <?php if ($storeNotFound): ?>
                <div class="ms-brand-left">
                    <span class="ms-initials">?</span>
                    <div class="ms-title">Store</div>
                </div>
            <?php else: ?>
                <a class="ms-brand-left" href="<?= e($homeUrl) ?>">
                    <?php if (!empty($brand['show_logo_header'])): ?>
                    <span class="<?= $brand['logo_path'] ? 'ms-logo' : 'ms-initials' ?>">
                        <?php if ($brand['logo_path']): ?>
                            <img src="<?= asset($brand['logo_path']) ?>" alt="<?= e($pageTitle) ?>">
                        <?php else: ?>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($brand['show_name_with_logo'])): ?>
                    <span class="ms-title"><?= e($pageTitle) ?></span>
                    <?php endif; ?>
                </a>

                <form class="ms-search-wrap" method="get" action="<?= e($homeUrl) ?>" style="position:relative;flex:1 1 auto;max-width:560px;min-width:0;margin:0;">
                    <?php if (!store_is_on_custom_domain($storeBiz) && !empty($storeBiz['store_slug'])): ?>
                        <input type="hidden" name="slug" value="<?= e((string) $storeBiz['store_slug']) ?>">
                    <?php endif; ?>
                    <div class="ms-search-box" style="position:relative;width:100%;display:flex;align-items:center;">
                        <svg class="ms-search-icon" style="position:absolute;left:16px;color:#94a3b8;pointer-events:none;z-index:3;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7.2"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input class="ms-search" id="msSearchInput" type="search" name="q" value="<?= e(trim((string) ($_GET['q'] ?? ''))) ?>" style="width:100%;border:0;border-radius:999px;padding:10px 18px 10px 42px;font:inherit;font-size:14px;background:#ffffff;color:#0f172a;outline:none;position:relative;z-index:1;" autocomplete="off">
                        <div class="ms-search-placeholder" id="msSearchPlaceholder" aria-hidden="true" style="position:absolute;left:42px;right:18px;top:50%;transform:translateY(-50%);display:flex;align-items:center;pointer-events:none;z-index:2;font-size:14px;color:#94a3b8;white-space:nowrap;overflow:hidden;height:22px;">
                            <span class="ms-sp-prefix" style="font-weight:500;color:#94a3b8;flex-shrink:0;">Search by </span>
                            <span class="ms-sp-track" style="position:relative;display:inline-block;height:22px;overflow:hidden;vertical-align:middle;margin-left:2px;flex:1;min-width:0;">
                                <span class="ms-sp-word" id="msSearchWord" style="display:block;line-height:22px;font-weight:600;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">"<?= e($searchCategories[0] ?? 'items') ?>"</span>
                            </span>
                        </div>
                    </div>
                </form>

                <div class="ms-top-actions">
                    <?php if (!empty($brand['show_location'])):
                        $savedLoc = $_SESSION['sf_delivery_location_' . $bid] ?? [];
                        $locDisplay = !empty($savedLoc['display']) ? $savedLoc['display'] : (!empty($storeShopper['address']) ? $storeShopper['address'] : 'Set delivery location');
                        if (mb_strlen($locDisplay) > 28) {
                            $locDisplay = mb_substr($locDisplay, 0, 26) . '...';
                        }
                        ?>
                        <div class="ms-location-widget" id="msLocationToggle" style="cursor:pointer;" title="Set delivery location">
                            <span class="ms-location-sub">Delivery to <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg></span>
                            <span class="ms-location-main" id="msLocationMainText"><?= e($locDisplay) ?></span>
                        </div>
                        <span class="ms-nav-divider" aria-hidden="true"></span>
                    <?php endif; ?>

                    <a class="ms-nav-item" href="<?= e($cartUrl) ?>" title="Cart" id="msCartToggle">
                        <span class="ms-nav-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l-1 12H7L6 7z"/><path d="M9 7V6a3 3 0 0 1 6 0v1"/></svg>
                            <?php if ($cartCount > 0): ?>
                                <span class="ms-cart-badge"><?= (int) $cartCount ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="ms-nav-label">Cart</span>
                    </a>

                    <a class="ms-nav-item" href="#account" title="Account" id="msAccountToggle">
                        <span class="ms-nav-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.2"/><circle cx="12" cy="10" r="3"/><path d="M6.8 18.2c1.4-2.2 3.2-3.2 5.2-3.2s3.8 1 5.2 3.2"/></svg>
                        </span>
                        <span class="ms-nav-label"><?= !empty($storeShopper) ? e(storefront_clean_person_name((string)($storeShopper['name'] ?? 'Account')) ?: 'Account') : 'Account' ?></span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="ms-body-pad">
        <?php if ($storeNotFound): ?>
            <div class="ms-empty">This store URL was not found.</div>
        <?php elseif (!$published): ?>
            <div class="ms-empty"><?= e($pageTitle) ?> is closed right now.</div>
        <?php else: ?>
            <?php if (!empty($flashSuccess)): ?><div class="ms-alert ms-ok"><?= e($flashSuccess) ?></div><?php endif; ?>
            <?php if (!empty($flashError)): ?><div class="ms-alert ms-err"><?= e($flashError) ?></div><?php endif; ?>

            <?php if ($page === 'home'):
                $q = trim((string) ($_GET['q'] ?? ''));
                $catId = !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null;
                $categories = get_categories('', 'active', $bid);
                $products = get_products($q, $catId, 'active', '', $bid);

                if (!empty($brand['hide_out_of_stock'])) {
                    $products = array_values(array_filter($products, static fn($p) => (int) ($p['stock_quantity'] ?? 0) > 0));
                }
                $categories = storefront_visible_categories($categories, $brand);
                $homeSections = storefront_home_sections($brand);
                $catCols = max(2, min(4, (int) ($brand['category_columns'] ?? 2)));
                ?>

                <?php foreach ($homeSections as $homeSec): ?>
                <?php if ($homeSec === 'banner' && $brand['show_banner'] && $q === '' && !$catId): ?>
                    <div class="ms-banners-grid" id="msBanners">
                        <!-- Banner 1: We're online now! -->
                        <div class="ms-banner-card ms-banner-1">
                            <div class="ms-banner-info">
                                <div class="ms-banner-tag">We're</div>
                                <div class="ms-banner-title">online now!</div>
                                <div class="ms-banner-sub">Stay at home and<br>shop online.</div>
                            </div>
                            <div class="ms-banner-art">
                                <svg viewBox="0 0 160 135" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="20" cy="30" r="1.5" fill="#ffffff" opacity="0.8"/>
                                    <circle cx="140" cy="20" r="1.5" fill="#ffffff" opacity="0.7"/>
                                    <circle cx="35" cy="110" r="1.5" fill="#ffffff" opacity="0.7"/>
                                    <circle cx="150" cy="100" r="1.5" fill="#ffffff" opacity="0.6"/>

                                    <ellipse cx="80" cy="120" rx="60" ry="12" fill="#00695c" opacity="0.6"/>

                                    <g transform="translate(48, 12)">
                                        <rect x="0" y="0" width="56" height="105" rx="8" fill="#1e293b"/>
                                        <rect x="2.5" y="2.5" width="51" height="100" rx="6" fill="#ffffff"/>
                                        
                                        <g transform="translate(0, 8)">
                                            <path d="M-2 0 L58 0 L54 18 L2 18 Z" fill="#fb7185"/>
                                            <path d="M7 0 L19 0 L17 18 L5 18 Z" fill="#ffffff"/>
                                            <path d="M29 0 L41 0 L39 18 L27 18 Z" fill="#ffffff"/>
                                            <path d="M51 0 L58 0 L54 18 L47 18 Z" fill="#ffffff"/>
                                            <circle cx="6" cy="18" r="5" fill="#fb7185"/>
                                            <circle cx="17" cy="18" r="5" fill="#ffffff"/>
                                            <circle cx="28" cy="18" r="5" fill="#fb7185"/>
                                            <circle cx="39" cy="18" r="5" fill="#ffffff"/>
                                            <circle cx="50" cy="18" r="5" fill="#fb7185"/>
                                        </g>
                                        
                                        <rect x="6" y="38" width="18" height="18" rx="3" fill="#f1f5f9"/>
                                        <rect x="28" y="38" width="18" height="18" rx="3" fill="#f1f5f9"/>
                                        <rect x="6" y="60" width="18" height="18" rx="3" fill="#f1f5f9"/>
                                        <rect x="28" y="60" width="18" height="18" rx="3" fill="#f1f5f9"/>

                                        <g transform="translate(11, 84)">
                                            <rect x="0" y="0" width="34" height="13" rx="6.5" fill="#e11d48"/>
                                            <text x="17" y="9.5" fill="#ffffff" font-size="6.5" font-weight="900" text-anchor="middle" font-family="sans-serif">ORDER!</text>
                                        </g>
                                    </g>

                                    <g transform="translate(26, 26)">
                                        <path d="M10 0 C4.5 0 0 4.5 0 10 C0 17 10 24 10 24 C10 24 20 17 20 10 C20 4.5 15.5 0 10 0 Z" fill="#e11d48"/>
                                        <circle cx="10" cy="9" r="4" fill="#ffffff"/>
                                    </g>

                                    <g transform="translate(100, 10) rotate(8)">
                                        <path d="M12 0 L32 6 L20 15 L0 9 Z" fill="#f59e0b"/>
                                        <path d="M0 9 L20 15 L20 32 L0 26 Z" fill="#d97706"/>
                                        <path d="M20 15 L32 6 L32 23 L20 32 Z" fill="#b45309"/>
                                        <path d="M6 11 L14 13 L14 29 L6 27 Z" fill="#fef3c7" opacity="0.6"/>
                                    </g>

                                    <g transform="translate(24, 60)">
                                        <circle cx="9" cy="9" r="9" fill="#fbbf24"/>
                                        <circle cx="6" cy="7" r="1.2" fill="#1e293b"/>
                                        <circle cx="12" cy="7" r="1.2" fill="#1e293b"/>
                                        <path d="M6 11 Q9 15 12 11" stroke="#1e293b" stroke-width="1.2" stroke-linecap="round" fill="none"/>
                                    </g>

                                    <g transform="translate(112, 54)">
                                        <circle cx="8" cy="8" r="8" fill="#fb7185"/>
                                        <path d="M8 12.5 L3.5 8 C2.2 6.7 2.2 4.5 3.5 3.2 C4.8 1.9 7 1.9 8.3 3.2 L8 3.5 L8.7 3.2 C10 1.9 12.2 1.9 13.5 3.2 C14.8 4.5 14.8 6.7 13.5 8 Z" fill="#ffffff" transform="scale(0.8) translate(1, 1)"/>
                                    </g>

                                    <g transform="translate(94, 48)">
                                        <circle cx="18" cy="10" r="8" fill="#fed7aa"/>
                                        <path d="M12 8 C12 2, 24 2, 26 8 C26 5, 23 3, 19 3 C15 3, 12 5, 12 8 Z" fill="#0f172a"/>
                                        <circle cx="14" cy="9" r="1" fill="#0f172a"/>
                                        <path d="M8 20 C8 16, 26 16, 26 20 L30 38 L4 38 Z" fill="#fda4af"/>
                                        <path d="M4 38 L30 38 L34 68 L18 68 L14 50 L-2 50 L-2 42 Z" fill="#312e81"/>
                                        <path d="M8 24 L-4 32 L-2 36 L10 28 Z" fill="#fda4af"/>
                                        <rect x="-8" y="28" width="6" height="10" rx="1.5" fill="#0f172a" transform="rotate(-15)"/>
                                    </g>
                                </svg>
                            </div>
                        </div>

                        <!-- Banner 2: Start Shopping -->
                        <div class="ms-banner-card ms-banner-2">
                            <div class="ms-banner-info">
                                <div class="ms-banner-tag">Best deal,</div>
                                <div class="ms-banner-title">Start<br>Shopping</div>
                                <div class="ms-banner-sub">and discover the<br>best deals!</div>
                            </div>
                            <div class="ms-banner-art">
                                <svg viewBox="0 0 160 135" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="18" cy="24" r="1.5" fill="#ffffff" opacity="0.8"/>
                                    <circle cx="145" cy="22" r="1.5" fill="#ffffff" opacity="0.7"/>
                                    <circle cx="28" cy="115" r="1.5" fill="#ffffff" opacity="0.7"/>
                                    <circle cx="152" cy="98" r="1.5" fill="#ffffff" opacity="0.6"/>

                                    <ellipse cx="95" cy="118" rx="55" ry="12" fill="#1d4ed8" opacity="0.5"/>

                                    <g transform="translate(98, 12)">
                                        <circle cx="14" cy="14" r="14" fill="#ffffff" opacity="0.95"/>
                                        <path d="M10 13 L10 20 L7 20 C6.4 20 6 19.6 6 19 L6 14 C6 13.4 6.4 13 7 13 Z" fill="#3b82f6"/>
                                        <path d="M11 13 L15 6 C15.5 5 17 5.5 17 7 L17 11 L21 11 C22.1 11 23 11.9 23 13 L21 19 C20.7 19.6 20.1 20 19.5 20 L11 20 Z" fill="#3b82f6"/>
                                    </g>

                                    <g transform="translate(68, 22)">
                                        <rect x="0" y="0" width="22" height="18" rx="6" fill="#f43f5e"/>
                                        <path d="M11 15 L7 19 L9 15 Z" fill="#f43f5e"/>
                                        <path d="M11 12.5 L7.5 9 C6.5 8 6.5 6.4 7.5 5.4 C8.5 4.4 10.1 4.4 11.1 5.4 L11 5.6 L11.5 5.4 C12.5 4.4 14.1 4.4 15.1 5.4 C16.1 6.4 16.1 8 15.1 9 Z" fill="#ffffff" transform="scale(0.85) translate(2, 1)"/>
                                    </g>

                                    <g transform="translate(48, 48) rotate(-12)">
                                        <rect x="0" y="0" width="16" height="24" rx="3" fill="#a855f7"/>
                                        <circle cx="8" cy="5" r="2" fill="#1e60d5"/>
                                        <text x="8" y="18" fill="#ffffff" font-size="9" font-weight="900" text-anchor="middle" font-family="sans-serif">%</text>
                                    </g>

                                    <g transform="translate(130, 48)">
                                        <circle cx="10" cy="10" r="10" fill="#22c55e"/>
                                        <polyline points="6 10 9 13 14 7" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                    </g>

                                    <g transform="translate(74, 46)">
                                        <path d="M14 18 C14 2, 28 2, 28 18" fill="none" stroke="#fef3c7" stroke-width="2.5" stroke-linecap="round"/>
                                        <path d="M4 18 L38 18 L34 68 L6 68 Z" fill="#e09f67"/>
                                        <path d="M4 18 L38 18 L36 24 L5 24 Z" fill="#c8834c"/>
                                        <path d="M38 18 L50 24 L46 74 L34 68 Z" fill="#b45309"/>
                                        <line x1="20" y1="24" x2="20" y2="68" stroke="#c8834c" stroke-width="1.2" opacity="0.6"/>
                                    </g>

                                    <g transform="translate(52, 60)">
                                        <path d="M10 14 C10 2, 20 2, 20 14" fill="none" stroke="#fef3c7" stroke-width="2" stroke-linecap="round"/>
                                        <path d="M2 14 L28 14 L25 54 L4 54 Z" fill="#f2b279"/>
                                        <path d="M2 14 L28 14 L26 19 L3 19 Z" fill="#d97706"/>
                                        <path d="M28 14 L36 18 L33 58 L25 54 Z" fill="#b45309"/>
                                    </g>

                                    <g transform="translate(108, 66)">
                                        <path d="M8 10 C8 0, 16 0, 16 10" fill="none" stroke="#fef3c7" stroke-width="1.8" stroke-linecap="round"/>
                                        <path d="M2 10 L22 10 L20 44 L3 44 Z" fill="#e09f67"/>
                                        <path d="M2 10 L22 10 L21 14 L3 14 Z" fill="#c8834c"/>
                                        <path d="M22 10 L28 14 L26 48 L20 44 Z" fill="#a16207"/>
                                    </g>
                                </svg>
                            </div>
                        </div>

                        <!-- Banner 3: Order with Ease, Receive with Speed -->
                        <div class="ms-banner-card ms-banner-3">
                            <div class="ms-banner-info">
                                <div class="ms-banner-tag" style="font-size:16px;">Order</div>
                                <div class="ms-banner-title" style="font-style:italic;">with Ease</div>
                                <div class="ms-banner-tag" style="font-size:16px;margin-top:6px;">Receive</div>
                                <div class="ms-banner-title" style="font-style:italic;">with Speed</div>
                            </div>
                            <div class="ms-banner-art">
                                <svg viewBox="0 0 160 135" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="22" cy="25" r="1.5" fill="#ffffff" opacity="0.8"/>
                                    <circle cx="145" cy="18" r="1.5" fill="#ffffff" opacity="0.7"/>
                                    <circle cx="30" cy="115" r="1.5" fill="#ffffff" opacity="0.7"/>
                                    <circle cx="150" cy="105" r="1.5" fill="#ffffff" opacity="0.6"/>

                                    <path d="M40 35 C40 25, 60 25, 65 35 C70 28, 85 28, 90 35 C95 30, 110 30, 115 35 L120 45 L35 45 Z" fill="#ffffff" opacity="0.12"/>
                                    <path d="M80 85 C80 75, 100 75, 105 85 C110 78, 125 78, 130 85 L135 95 L75 95 Z" fill="#ffffff" opacity="0.12"/>

                                    <g transform="translate(118, 48)">
                                        <path d="M7 0 C3.1 0 0 3.1 0 7 C0 12 7 17 7 17 C7 17 14 12 14 7 C14 3.1 10.9 0 7 0 Z" fill="#ef4444"/>
                                        <circle cx="7" cy="6" r="2.8" fill="#ffffff"/>
                                    </g>
                                    <g transform="translate(142, 78) scale(0.85)">
                                        <path d="M7 0 C3.1 0 0 3.1 0 7 C0 12 7 17 7 17 C7 17 14 12 14 7 C14 3.1 10.9 0 7 0 Z" fill="#ef4444"/>
                                        <circle cx="7" cy="6" r="2.8" fill="#ffffff"/>
                                    </g>

                                    <g transform="translate(25, 15) rotate(-10)">
                                        <path d="M0 12 C-8 4, -18 8, -20 18 C-16 18, -12 22, -6 18 Z" fill="#ffffff" opacity="0.95"/>
                                        <path d="M22 6 C28 0, 38 2, 42 12 C38 13, 34 18, 28 14 Z" fill="#ffffff" opacity="0.95"/>
                                        <path d="M10 0 L24 6 L14 14 L0 8 Z" fill="#fbbf24"/>
                                        <path d="M0 8 L14 14 L14 26 L0 20 Z" fill="#d97706"/>
                                        <path d="M14 14 L24 6 L24 18 L14 26 Z" fill="#b45309"/>
                                        <line x1="-8" y1="24" x2="-2" y2="22" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" opacity="0.7"/>
                                    </g>

                                    <g transform="translate(85, 26)">
                                        <g transform="translate(-18, 12)">
                                            <path d="M18 10 C8 -2, -6 0, -12 14 C-4 15, 2 22, 10 18 C14 22, 18 18, 18 10 Z" fill="#ffffff"/>
                                            <path d="M6 10 C0 4, -8 6, -10 14" stroke="#e2e8f0" stroke-width="1.2" fill="none"/>
                                        </g>
                                        <g transform="translate(36, 4)">
                                            <path d="M0 14 C10 0, 26 2, 32 16 C24 17, 18 24, 10 20 C6 24, 2 20, 0 14 Z" fill="#ffffff"/>
                                            <path d="M14 12 C20 6, 28 8, 30 16" stroke="#e2e8f0" stroke-width="1.2" fill="none"/>
                                        </g>
                                        <path d="M18 0 L42 8 L24 20 L0 12 Z" fill="#fbbf24"/>
                                        <path d="M0 12 L24 20 L24 44 L0 36 Z" fill="#d97706"/>
                                        <path d="M24 20 L42 8 L42 32 L24 44 Z" fill="#b45309"/>
                                        <path d="M9 6 L21 10 L21 34 L9 30 Z" fill="#ffffff" opacity="0.5"/>
                                    </g>

                                    <g transform="translate(68, 76) rotate(6)">
                                        <path d="M0 10 C-8 2, -18 4, -20 14 C-14 15, -10 19, -4 15 Z" fill="#ffffff" opacity="0.95"/>
                                        <path d="M22 6 C30 0, 38 2, 42 12 C36 13, 32 18, 26 14 Z" fill="#ffffff" opacity="0.95"/>
                                        <path d="M10 0 L24 6 L14 14 L0 8 Z" fill="#fbbf24"/>
                                        <path d="M0 8 L14 14 L14 26 L0 20 Z" fill="#d97706"/>
                                        <path d="M14 14 L24 6 L24 18 L14 26 Z" fill="#b45309"/>
                                        <line x1="-10" y1="22" x2="-4" y2="20" stroke="#ffffff" stroke-width="1.6" stroke-linecap="round" opacity="0.7"/>
                                    </g>
                                </svg>
                            </div>
                        </div>
                    </div>

                <?php elseif ($homeSec === 'category' && $brand['show_categories'] && $categories && $q === ''): ?>
                    <div id="msCategories" class="ms-sec-title"><?= e($brand['category_section_name'] ?: 'All Categories') ?></div>
                    <div class="ms-cat-grid">
                        <?php foreach ($categories as $cat):
                            $catUrl = public_store_url($storeBiz, 'home', ['category_id' => (int) $cat['id']]);
                            $thumb = !empty($cat['image_path']) ? (string) $cat['image_path'] : '';
                            if (!$thumb) {
                                $catProducts = get_products('', (int) $cat['id'], 'active', '', $bid);
                                foreach ($catProducts as $cp) {
                                    if (!empty($cp['image_path'])) { $thumb = (string) $cp['image_path']; break; }
                                }
                            }
                            $isCatActive = ((int)$catId === (int)$cat['id']);
                            ?>
                            <a class="ms-cat-card<?= $isCatActive ? ' ms-cat-active' : '' ?>" href="<?= e($catUrl) ?>">
                                <div class="ms-cat-img-box">
                                    <?php if ($thumb): ?>
                                        <img src="<?= asset($thumb) ?>" alt="<?= e((string) $cat['name']) ?>">
                                    <?php else: ?>
                                        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="3" width="18" height="18" rx="3" ry="3"/>
                                            <circle cx="8.5" cy="8.5" r="1.5" fill="#cbd5e1"/>
                                            <polyline points="21 15 16 10 5 21" fill="none" stroke="#cbd5e1"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="ms-cat-title"><?= e((string) $cat['name']) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($homeSec === 'item' && $brand['show_items']): ?>
                    <div class="ms-sec-title"><?= $catId ? 'Category items' : e($brand['item_section_name'] ?: 'All Items') ?></div>
                    <?php if (!$products): ?>
                        <div class="ms-empty">No items to show.</div>
                    <?php else: ?>
                        <div class="ms-item-grid">
                            <?php foreach ($products as $p):
                                $img = sf_product_image($p['image_path'] ?? null);
                                $pUrl = public_store_url($storeBiz, 'product', ['id' => (int) $p['id']]);
                                $inStock = (int) $p['stock_quantity'] > 0;
                                $pInfo = storefront_parse_product_display_info($p, $bid);
                                $attrText = $pInfo['attr_text'];
                                $varCount = $pInfo['variant_count'];
                                $sellingPrice = $pInfo['selling_price'];
                                $mrp = $pInfo['mrp'];
                                $discountPct = $pInfo['discount_percent'];
                                ?>
                                <div class="ms-product-card">
                                    <a class="ms-product-img-wrap" href="<?= e($pUrl) ?>">
                                        <?php if ($discountPct > 0): ?>
                                            <span class="ms-card-discount-badge"><?= $discountPct ?>%<br>Off</span>
                                        <?php endif; ?>

                                        <?php if ($img): ?>
                                            <img src="<?= e($img) ?>" alt="<?= e((string) $p['name']) ?>">
                                        <?php else: ?>
                                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="3" width="18" height="18" rx="3" ry="3"/>
                                                <circle cx="8.5" cy="8.5" r="1.5" fill="#cbd5e1"/>
                                                <polyline points="21 15 16 10 5 21" fill="none" stroke="#cbd5e1"/>
                                            </svg>
                                        <?php endif; ?>
                                    </a>
                                    <div class="ms-product-body">
                                        <div>
                                            <a class="ms-product-name" href="<?= e($pUrl) ?>"><?= e((string) $p['name']) ?></a>
                                            <?php if ($attrText !== ''): ?>
                                                <div class="ms-product-attr"><?= e($attrText) ?></div>
                                            <?php endif; ?>
                                            <?php if ($varCount > 0): ?>
                                                <a href="<?= e($pUrl) ?>" class="ms-product-variants-link">+<?= max(1, $varCount - 1) ?> variants</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ms-product-price-row">
                                            <?php if (empty($brand['hide_product_price'])): ?>
                                                <span class="ms-product-price"><?= sf_money($currency, (float) $sellingPrice) ?></span>
                                                <?php if ($mrp > $sellingPrice && $mrp > 0): ?>
                                                    <span class="ms-product-mrp"><?= sf_money($currency, (float) $mrp) ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($brand['show_image_disclaimer'])): ?>
                            <div style="font-size:11.5px;color:#94a3b8;margin-top:14px;text-align:center;">* Product images are for representation purposes only.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
                <?php endforeach; ?>

            <?php elseif ($page === 'product'):
                $product = get_product_by_id((int) ($_GET['id'] ?? 0), $bid);
                if (!$product || ($product['status'] ?? '') !== 'active'): ?>
                    <div class="ms-empty">This product is not available.</div>
                <?php else:
                    $variants = function_exists('get_product_variants') ? get_product_variants((int) $product['id'], $bid) : [];
                    $selectedVariantId = !empty($_GET['variant_id']) ? (int) $_GET['variant_id'] : (!empty($variants[0]['id']) ? (int) $variants[0]['id'] : null);
                    
                    $activeVariant = null;
                    if ($variants) {
                        foreach ($variants as $v) {
                            if ((int)$v['id'] === $selectedVariantId) {
                                $activeVariant = $v;
                                break;
                            }
                        }
                        if (!$activeVariant) {
                            $activeVariant = $variants[0];
                            $selectedVariantId = (int)$variants[0]['id'];
                        }
                    }

                    $currentPrice = $activeVariant ? (float)$activeVariant['selling_price'] : (float)$product['selling_price'];
                    $inStock = $activeVariant ? ((int)$activeVariant['stock_quantity'] > 0) : ((int)$product['stock_quantity'] > 0);
                    $stockQty = $activeVariant ? (int)$activeVariant['stock_quantity'] : (int)$product['stock_quantity'];
                    $img = sf_product_image($product['image_path'] ?? null);
                    ?>
                    <div class="ms-pdp-wrap">
                        <a href="<?= e($homeUrl) ?>" class="ms-legal-back" style="display:inline-flex;margin-bottom:16px;">← Back to Store</a>

                        <!-- Top 2-Column Product Detail Card -->
                        <div class="ms-pdp-top-card">
                            <!-- Left: High-Res Image Gallery -->
                            <div class="ms-pdp-gallery">
                                <?php if ($img): ?>
                                    <img src="<?= e($img) ?>" alt="<?= e((string) $product['name']) ?>" id="pdpMainImg">
                                <?php else: ?>
                                    <svg width="84" height="84" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="3" width="18" height="18" rx="3" ry="3"/>
                                        <circle cx="8.5" cy="8.5" r="1.5" fill="#cbd5e1"/>
                                        <polyline points="21 15 16 10 5 21" fill="none" stroke="#cbd5e1"/>
                                    </svg>
                                <?php endif; ?>
                            </div>

                            <!-- Right: Product Information & Actions -->
                            <div class="ms-pdp-info">
                                <h1 class="ms-pdp-title"><?= e((string) $product['name']) ?></h1>

                                <?php if (empty($brand['hide_product_price'])): ?>
                                    <div class="ms-pdp-price"><?= sf_money($currency, $currentPrice) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($variants)): ?>
                                    <div class="ms-pdp-opt-title">Options / Variants</div>
                                    <div class="ms-pdp-variants-wrap">
                                        <?php foreach ($variants as $v):
                                            $isActiveVar = ((int)$v['id'] === $selectedVariantId);
                                            $varUrl = public_store_url($storeBiz, 'product', ['id' => (int)$product['id'], 'variant_id' => (int)$v['id']]);
                                            ?>
                                            <a href="<?= e($varUrl) ?>" class="ms-pdp-var-btn<?= $isActiveVar ? ' is-active' : '' ?>">
                                                <span class="ms-pdp-var-name"><?= e((string)$v['variant_name']) ?></span>
                                                <span class="ms-pdp-var-price"><?= sf_money($currency, (float)$v['selling_price']) ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <form method="post" style="margin-top:auto;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add_to_cart">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <?php if ($selectedVariantId): ?>
                                        <input type="hidden" name="variant_id" value="<?= (int) $selectedVariantId ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="redirect_page" value="product">
                                    <input type="hidden" name="return_id" value="<?= (int) $product['id'] ?>">

                                    <div class="ms-pdp-action-row">
                                        <div class="ms-pdp-stepper">
                                            <button type="button" class="ms-pdp-step-btn" id="pdpMinus" aria-label="Decrease quantity">−</button>
                                            <input type="number" name="qty" id="pdpQty" class="ms-pdp-qty-val" value="1" min="1" max="<?= $inStock ? max(1, $stockQty) : 1 ?>" <?= $inStock ? '' : 'disabled' ?>>
                                            <button type="button" class="ms-pdp-step-btn" id="pdpPlus" aria-label="Increase quantity">+</button>
                                        </div>

                                        <button type="submit" class="ms-pdp-add-btn" <?= $inStock ? '' : 'disabled' ?>>
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                                            <span><?= $inStock ? 'Go To Cart' : 'Out of Stock' ?></span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Specifications Section -->
                        <div class="ms-pdp-sec-title">Specifications</div>
                        <div class="ms-pdp-specs-card">
                            <div class="ms-pdp-spec-row">
                                <div class="ms-pdp-spec-key">Unit</div>
                                <div class="ms-pdp-spec-val"><?= e((string)($product['unit'] ?: 'pcs')) ?></div>
                            </div>
                            <?php if (!empty($product['manufacturer']) || !empty($product['brand'])): ?>
                                <div class="ms-pdp-spec-row">
                                    <div class="ms-pdp-spec-key">Manufacturer</div>
                                    <div class="ms-pdp-spec-val"><?= e((string)($product['manufacturer'] ?: $product['brand'])) ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($product['category_name'])): ?>
                                <div class="ms-pdp-spec-row">
                                    <div class="ms-pdp-spec-key">Category</div>
                                    <div class="ms-pdp-spec-val"><?= e((string)$product['category_name']) ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($product['sku'])): ?>
                                <div class="ms-pdp-spec-row">
                                    <div class="ms-pdp-spec-key">SKU / Item Code</div>
                                    <div class="ms-pdp-spec-val"><?= e((string)$product['sku']) ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($product['description'])): ?>
                                <div class="ms-pdp-spec-row">
                                    <div class="ms-pdp-spec-key">Description</div>
                                    <div class="ms-pdp-spec-val" style="font-weight:400;line-height:1.6;"><?= nl2br(e((string)$product['description'])) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Highlights Section -->
                        <div class="ms-pdp-sec-title">Highlights</div>
                        <div class="ms-pdp-highlights-grid">
                            <div class="ms-pdp-hl-item">
                                <div class="ms-pdp-hl-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>
                                </div>
                                <div class="ms-pdp-hl-label">Pay on Delivery</div>
                            </div>
                            <div class="ms-pdp-hl-item">
                                <div class="ms-pdp-hl-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                </div>
                                <div class="ms-pdp-hl-label">Fast Delivery</div>
                            </div>
                            <div class="ms-pdp-hl-item">
                                <div class="ms-pdp-hl-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                </div>
                                <div class="ms-pdp-hl-label">100% Genuine</div>
                            </div>
                            <div class="ms-pdp-hl-item">
                                <div class="ms-pdp-hl-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                </div>
                                <div class="ms-pdp-hl-label">Easy Returns</div>
                            </div>
                        </div>
                    </div>

                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        var minusBtn = document.getElementById('pdpMinus');
                        var plusBtn = document.getElementById('pdpPlus');
                        var qtyInput = document.getElementById('pdpQty');
                        if (minusBtn && plusBtn && qtyInput) {
                            minusBtn.addEventListener('click', function() {
                                var val = parseInt(qtyInput.value, 10) || 1;
                                if (val > 1) {
                                    qtyInput.value = val - 1;
                                }
                            });
                            plusBtn.addEventListener('click', function() {
                                var val = parseInt(qtyInput.value, 10) || 1;
                                var max = parseInt(qtyInput.getAttribute('max'), 10) || 999;
                                if (val < max) {
                                    qtyInput.value = val + 1;
                                }
                            });
                        }
                    });
                    </script>
                <?php endif; ?>

            <?php elseif ($page === 'cart'):
                $hydrated = $drawerCart;
                ?>
                <div class="ms-form-card">
                    <h1 style="font-size:20px;font-weight:800;margin-bottom:16px">Shopping Cart</h1>
                    <?php if (empty($hydrated['lines'])): ?>
                        <div class="ms-empty">Your cart is empty.</div>
                        <a class="ms-btn" href="<?= e($homeUrl) ?>" style="margin-top:12px">Start Shopping</a>
                    <?php else: ?>
                        <?php foreach ($hydrated['lines'] as $line): $p = $line['product']; ?>
                            <div class="ms-cart-line">
                                <div>
                                    <strong style="text-transform:uppercase"><?= e((string) $p['name']) ?></strong>
                                    <form method="post" style="margin-top:6px;display:flex;gap:6px">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_cart">
                                        <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                        <input class="ms-input" style="width:70px;margin:0" type="number" name="qty" min="0" value="<?= (int) $line['qty'] ?>">
                                        <button class="ms-btn-ghost" type="submit" style="padding:6px 10px;font-size:12px">Update</button>
                                    </form>
                                </div>
                                <div style="font-weight:700"><?= sf_money($currency, (float) $line['line_total']) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <div class="ms-total"><span>Total</span><span><?= sf_money($currency, $hydrated['total']) ?></span></div>
                        <a class="ms-btn" href="<?= e($checkoutUrl) ?>">Proceed to Checkout</a>
                    <?php endif; ?>
                </div>

            <?php elseif ($page === 'checkout'):
                $hydrated = $drawerCart;
                if (empty($hydrated['lines'])): ?>
                    <div class="ms-form-card">
                        <div class="ms-empty">Your cart is empty.</div>
                        <a class="ms-btn" href="<?= e($homeUrl) ?>" style="margin-top:12px">Start Shopping</a>
                    </div>
                <?php else: ?>
                    <div class="ms-form-card">
                        <h1 style="font-size:20px;font-weight:800;margin-bottom:16px">Checkout</h1>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="place_order">
                            <label class="ms-label">Full name</label>
                            <input class="ms-input" type="text" name="name" required value="<?= e(storefront_clean_person_name((string)($_SESSION['sf_delivery_location_' . $bid]['name'] ?? $storeShopper['name'] ?? ''))) ?>">
                            <label class="ms-label">Phone</label>
                            <input class="ms-input" type="tel" name="phone" required value="<?= e((string)($_SESSION['sf_delivery_location_' . $bid]['phone'] ?? $storeShopper['phone'] ?? '')) ?>">
                            <label class="ms-label">Email</label>
                            <input class="ms-input" type="email" name="email" value="<?= e((string) ($storeShopper['email'] ?? '')) ?>">
                            <label class="ms-label">Delivery address</label>
                            <textarea class="ms-textarea" name="address" rows="3" required><?= e((string)($_SESSION['sf_delivery_location_' . $bid]['formatted'] ?? $storeShopper['address'] ?? '')) ?></textarea>
                            <label class="ms-label">Payment Method</label>
                            <select class="ms-select" name="payment_method">
                                <option value="cod">Cash on Delivery</option>
                                <option value="upi">UPI</option>
                                <option value="pickup">Pay at store / Pickup</option>
                            </select>
                            <div class="ms-total"><span>Total</span><span><?= sf_money($currency, $hydrated['total']) ?></span></div>
                            <button class="ms-btn" type="submit">Place Order</button>
                        </form>
                    </div>
                <?php endif; ?>

            <?php elseif ($page === 'profile' && $storeShopper): ?>
                <div class="ms-form-card">
                    <h1 style="font-size:20px;font-weight:800;margin-bottom:16px">Edit profile</h1>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <label class="ms-label">Full name</label>
                        <input class="ms-input" type="text" name="name" required value="<?= e(storefront_clean_person_name((string) ($storeShopper['name'] ?? ''))) ?>">
                        <label class="ms-label">Email</label>
                        <input class="ms-input" type="email" name="email" required value="<?= e((string) ($storeShopper['email'] ?? '')) ?>">
                        <label class="ms-label">Phone</label>
                        <input class="ms-input" type="tel" name="phone" value="<?= e((string) ($storeShopper['phone'] ?? '')) ?>">
                        <input type="hidden" name="address" value="<?= e((string) ($storeShopper['address'] ?? '')) ?>">
                        <button class="ms-btn" type="submit">Save</button>
                    </form>
                </div>

            <?php elseif ($page === 'orders' && $storeShopper):
                $myOrders = get_storefront_customer_orders($bid, (int) $storeShopper['id']);
                ?>
                <div class="ms-form-card">
                    <h1 style="font-size:20px;font-weight:800;margin-bottom:16px">Orders</h1>
                    <?php if (!$myOrders): ?>
                        <div class="ms-empty" style="padding:24px 0">You have no orders yet.</div>
                    <?php else: ?>
                        <?php foreach ($myOrders as $ord): ?>
                            <div class="ms-cart-line">
                                <div>
                                    <strong><?= e((string) ($ord['order_number'] ?? ('#' . $ord['id']))) ?></strong>
                                    <div class="ms-item-meta"><?= e((string) ($ord['created_at'] ?? '')) ?> · <?= e((string) ($ord['order_status'] ?? '')) ?></div>
                                </div>
                                <div style="font-weight:700"><?= sf_money($currency, (float) ($ord['total_amount'] ?? 0)) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($page === 'invoices' && $storeShopper):
                $myInvoices = get_storefront_customer_invoices($bid, (int) $storeShopper['id']);
                ?>
                <div class="ms-form-card">
                    <h1 style="font-size:20px;font-weight:800;margin-bottom:16px">Invoices</h1>
                    <?php if (!$myInvoices): ?>
                        <div class="ms-empty" style="padding:24px 0">No invoices yet.</div>
                    <?php else: ?>
                        <?php foreach ($myInvoices as $inv): ?>
                            <div class="ms-cart-line">
                                <div>
                                    <strong><?= e((string) ($inv['invoice_number'] ?? ('#' . $inv['id']))) ?></strong>
                                    <div class="ms-item-meta"><?= e((string) ($inv['invoice_date'] ?? $inv['created_at'] ?? '')) ?></div>
                                </div>
                                <div style="font-weight:700"><?= sf_money($currency, (float) ($inv['total_amount'] ?? 0)) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($page === 'addresses' && $storeShopper): ?>
                <div class="ms-form-card">
                    <h1 style="font-size:20px;font-weight:800;margin-bottom:16px">Addresses</h1>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_address">
                        <input type="hidden" name="name" value="<?= e((string) ($storeShopper['name'] ?? '')) ?>">
                        <input type="hidden" name="email" value="<?= e((string) ($storeShopper['email'] ?? '')) ?>">
                        <input type="hidden" name="phone" value="<?= e((string) ($storeShopper['phone'] ?? '')) ?>">
                        <label class="ms-label">Delivery address</label>
                        <textarea class="ms-textarea" name="address" rows="4" required><?= e((string) ($storeShopper['address'] ?? '')) ?></textarea>
                        <button class="ms-btn" type="submit">Save address</button>
                    </form>
                </div>

            <?php elseif ($page === 'thanks'): ?>
                <div class="ms-form-card" style="text-align:center">
                    <div class="ms-empty" style="padding:20px 0">
                        <h1 style="color:#0f172a;font-size:24px;font-weight:800;margin-bottom:8px">Order Placed Successfully! 🎉</h1>
                        <?php if (!empty($_GET['order'])): ?><p style="font-size:15px;color:#334155">Order ID: <strong><?= e((string) $_GET['order']) ?></strong></p><?php endif; ?>
                        <a class="ms-btn" href="<?= e($homeUrl) ?>" style="margin-top:20px;display:inline-flex;width:auto">Continue Shopping</a>
                    </div>
                </div>

            <?php elseif ($page === 'privacy'):
                $customPolicy = trim((string)($brand['privacy_policy'] ?? ''));
                ?>
                <div class="ms-legal-card">
                    <a href="<?= e($homeUrl) ?>" class="ms-legal-back">← Back to Store</a>
                    <h1 class="ms-legal-title">Privacy Policy</h1>
                    <div class="ms-legal-meta">Last updated for <?= e($pageTitle) ?></div>

                    <div class="ms-legal-content">
                        <?php if ($customPolicy !== ''): ?>
                            <?= nl2br(e($customPolicy)) ?>
                        <?php else: ?>
                            <p>Welcome to <strong><?= e($pageTitle) ?></strong>. We value your privacy and are committed to protecting your personal information. This Privacy Policy explains how we collect, use, and safeguard your details when you visit our store or make a purchase.</p>
                            
                            <h3>1. Information We Collect</h3>
                            <p>When you create an account, place an order, or browse our store, we may collect the following information:</p>
                            <ul>
                                <li>Contact details such as your name, email address, and phone number.</li>
                                <li>Delivery and shipping address to fulfill your orders.</li>
                                <li>Order history, transaction details, and cart preferences.</li>
                            </ul>

                            <h3>2. How We Use Your Information</h3>
                            <p>We use the information we collect for the following purposes:</p>
                            <ul>
                                <li>To process, confirm, and deliver your orders accurately.</li>
                                <li>To provide order status updates and customer support.</li>
                                <li>To improve our products, services, and online shopping experience.</li>
                            </ul>

                            <h3>3. Data Security</h3>
                            <p>We implement strict technical and security measures to protect your personal information against unauthorized access, loss, or misuse.</p>

                            <h3>4. Contact Us</h3>
                            <p>If you have any questions or concerns regarding our Privacy Policy or your personal information, please reach out through our <a href="<?= e(public_store_url($storeBiz, 'contact')) ?>" style="color:var(--ms-accent,#2563eb);text-decoration:underline;">Contact Us</a> page.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($page === 'contact'):
                $carePhone = trim((string)($brand['customer_care_phone'] ?? $storeBiz['phone'] ?? ''));
                $careEmail = trim((string)($brand['customer_care_email'] ?? $storeBiz['email'] ?? ''));
                $careWhatsapp = preg_replace('/[^0-9]/', '', (string)($brand['contact_whatsapp'] ?? ''));
                $careNote = trim((string)($brand['contact_us_text'] ?? ''));
                $storeAddress = trim((string)($storeBiz['address'] ?? ''));
                if (!empty($storeBiz['city'])) $storeAddress .= ($storeAddress ? ', ' : '') . $storeBiz['city'];
                if (!empty($storeBiz['state'])) $storeAddress .= ($storeAddress ? ', ' : '') . $storeBiz['state'];
                if (!empty($storeBiz['pincode'])) $storeAddress .= ' - ' . $storeBiz['pincode'];
                if (!empty($storeBiz['country'])) $storeAddress .= ($storeAddress ? ', ' : '') . $storeBiz['country'];
                ?>
                <div class="ms-legal-card">
                    <a href="<?= e($homeUrl) ?>" class="ms-legal-back">← Back to Store</a>
                    <h1 class="ms-legal-title">Contact Us</h1>
                    <p class="ms-legal-subtitle">Have questions or need assistance with your order? We're here to help!</p>

                    <div class="ms-contact-grid">
                        <?php if ($carePhone !== ''): ?>
                            <div class="ms-contact-card">
                                <div class="ms-contact-icon">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                </div>
                                <div>
                                    <div class="ms-contact-label">Phone Support</div>
                                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $carePhone)) ?>" class="ms-contact-val"><?= e($carePhone) ?></a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($careEmail !== ''): ?>
                            <div class="ms-contact-card">
                                <div class="ms-contact-icon">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                </div>
                                <div>
                                    <div class="ms-contact-label">Email Support</div>
                                    <a href="mailto:<?= e($careEmail) ?>" class="ms-contact-val"><?= e($careEmail) ?></a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($careWhatsapp !== ''): ?>
                            <div class="ms-contact-card ms-contact-card-wa">
                                <div class="ms-contact-icon ms-contact-icon-wa">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.816 9.816 0 0 0 12.04 2z"/></svg>
                                </div>
                                <div>
                                    <div class="ms-contact-label">WhatsApp Support</div>
                                    <a href="https://wa.me/<?= e($careWhatsapp) ?>" target="_blank" rel="noopener" class="ms-contact-val ms-contact-val-wa">Chat on WhatsApp →</a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($storeAddress !== ''): ?>
                            <div class="ms-contact-card">
                                <div class="ms-contact-icon">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                </div>
                                <div>
                                    <div class="ms-contact-label">Store Location</div>
                                    <div class="ms-contact-val" style="color:#334155;cursor:default;"><?= e($storeAddress) ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($careNote !== ''): ?>
                            <div class="ms-contact-card">
                                <div class="ms-contact-icon">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </div>
                                <div>
                                    <div class="ms-contact-label">Working Hours / Note</div>
                                    <div class="ms-contact-val" style="color:#334155;cursor:default;"><?= e($careNote) ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php if (empty($storeNotFound)):
        $footerLocStr = trim((string) ($brand['footer_location'] ?? ''));
        if ($footerLocStr === '') {
            $footerLocs = [];
            if (!empty($storeBiz['city'])) $footerLocs[] = $storeBiz['city'];
            if (!empty($storeBiz['state'])) $footerLocs[] = $storeBiz['state'];
            $footerLocStr = implode(', ', $footerLocs);
            if ($footerLocStr === '' && !empty($storeBiz['country'])) {
                $footerLocStr = $storeBiz['country'];
            }
            if ($footerLocStr === '' && !empty($storeBiz['address'])) {
                $footerLocStr = $storeBiz['address'];
            }
        }
        ?>
        <footer class="ms-footer">
            <div class="ms-footer-wrap">
                <div class="ms-footer-content">
                    <div class="ms-footer-brand-box">
                        <div class="ms-footer-name"><?= e($pageTitle) ?></div>
                        <?php if ($footerLocStr !== ''): ?>
                            <div class="ms-footer-location"><?= e($footerLocStr) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="ms-footer-links-box">
                        <a href="<?= e(public_store_url($storeBiz, 'privacy')) ?>" class="ms-footer-nav-link">Privacy Policy</a>
                        <span class="ms-footer-nav-dot">·</span>
                        <a href="<?= e(public_store_url($storeBiz, 'contact')) ?>" class="ms-footer-nav-link">Contact Us</a>
                    </div>
                </div>
            </div>
        </footer>
    <?php endif; ?>
</div>
<?php if (empty($storeNotFound)):
    $cdLines = $drawerCart['lines'] ?? [];
    $cdLineCount = count($cdLines);
    $cdQty = (int) ($drawerCart['count'] ?? 0);
    $cdSub = (float) ($drawerCart['subtotal'] ?? 0);
    $cdTax = (float) ($drawerCart['tax'] ?? 0);
    $cdTotal = (float) ($drawerCart['total'] ?? 0);
    $cdReturnPage = in_array($page, ['home', 'product', 'cart', 'checkout', 'thanks', 'orders', 'invoices', 'addresses', 'profile', 'privacy', 'contact'], true) ? $page : 'home';
    $cdReturnId = (int) ($_GET['id'] ?? 0);
    ?>
<div class="ms-cart-overlay<?= !empty($openCartDrawer) ? ' is-open' : '' ?>" id="msCartOverlay"<?= empty($openCartDrawer) ? ' hidden' : '' ?> aria-hidden="<?= !empty($openCartDrawer) ? 'false' : 'true' ?>">
    <button type="button" class="ms-cd-close" id="msCartClose" aria-label="Close cart">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
    <aside class="ms-cart-drawer" id="msCartDrawer" role="dialog" aria-labelledby="msCartTitle">
        <div class="ms-cd-head">
            <div class="ms-cd-title" id="msCartTitle">My cart <span>( <?= $cdLineCount ?> <?= $cdLineCount === 1 ? 'item' : 'items' ?>, <?= $cdQty ?> Qty)</span></div>
        </div>
        <?php if (!empty($brand['show_location'])): ?>
            <div class="ms-cd-delivery">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-5.4 7-11a7 7 0 10-14 0c0 5.6 7 11 7 11z"/><circle cx="12" cy="10" r="2.4"/></svg>
                <span>Set delivery location</span>
            </div>
        <?php endif; ?>

        <div class="ms-cd-body">
            <?php if (!$cdLines): ?>
                <div class="ms-cd-empty">Your cart is empty.</div>
            <?php else: ?>
                <?php foreach ($cdLines as $line):
                    $p = $line['product'];
                    $pid = (int) $p['id'];
                    $qty = (int) $line['qty'];
                    $stock = (int) ($p['stock_quantity'] ?? 0);
                    $img = sf_product_image($p['image_path'] ?? null);
                    $sku = trim((string) ($p['sku'] ?? ''));
                    ?>
                    <div class="ms-cd-item">
                        <div class="ms-cd-thumb">
                            <?php if ($img): ?>
                                <img src="<?= e($img) ?>" alt="">
                            <?php else: ?>
                                <span></span>
                            <?php endif; ?>
                        </div>
                        <div class="ms-cd-info">
                            <div class="ms-cd-name"><?= e((string) $p['name']) ?></div>
                            <?php if ($sku !== ''): ?>
                                <div class="ms-cd-meta"><?= e($sku) ?></div>
                            <?php endif; ?>
                            <?php if (empty($brand['hide_product_price'])): ?>
                                <div class="ms-cd-price"><?= sf_money($currency, (float) $line['unit_price']) ?></div>
                            <?php endif; ?>
                            <form method="post" class="ms-cd-remove">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_cart">
                                <input type="hidden" name="product_id" value="<?= $pid ?>">
                                <input type="hidden" name="qty" value="0">
                                <input type="hidden" name="return_page" value="<?= e($cdReturnPage) ?>">
                                <?php if ($cdReturnPage === 'product'): ?>
                                    <input type="hidden" name="return_id" value="<?= $cdReturnId ?>">
                                <?php endif; ?>
                                <button type="submit">Remove</button>
                            </form>
                        </div>
                        <div class="ms-cd-stepper">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_cart">
                                <input type="hidden" name="product_id" value="<?= $pid ?>">
                                <input type="hidden" name="qty" value="<?= max(0, $qty - 1) ?>">
                                <input type="hidden" name="return_page" value="<?= e($cdReturnPage) ?>">
                                <?php if ($cdReturnPage === 'product'): ?>
                                    <input type="hidden" name="return_id" value="<?= $cdReturnId ?>">
                                <?php endif; ?>
                                <button type="submit" aria-label="Decrease quantity">−</button>
                            </form>
                            <span><?= $qty ?></span>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_cart">
                                <input type="hidden" name="product_id" value="<?= $pid ?>">
                                <input type="hidden" name="qty" value="<?= min($stock, $qty + 1) ?>">
                                <input type="hidden" name="return_page" value="<?= e($cdReturnPage) ?>">
                                <?php if ($cdReturnPage === 'product'): ?>
                                    <input type="hidden" name="return_id" value="<?= $cdReturnId ?>">
                                <?php endif; ?>
                                <button type="submit" aria-label="Increase quantity" <?= $qty >= $stock ? 'disabled' : '' ?>>+</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="ms-cd-summary" id="msCartSummary">
                    <div class="ms-cd-summary-title">Summary</div>
                    <div class="ms-cd-row"><span>Sub Total (Tax Excluded)</span><span><?= sf_money($currency, $cdSub) ?></span></div>
                    <div class="ms-cd-row"><span>Delivery Charge</span><span class="ms-cd-free">Free</span></div>
                    <div class="ms-cd-row"><span>Tax</span><span><?= sf_money($currency, $cdTax) ?></span></div>
                    <div class="ms-cd-row ms-cd-pay"><span>To be Paid</span><span><?= sf_money($currency, $cdTotal) ?></span></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="ms-cd-foot">
            <div class="ms-cd-foot-left">
                <div class="ms-cd-foot-total"><?= sf_money($currency, $cdTotal) ?></div>
                <?php if ($cdLines): ?>
                    <button type="button" class="ms-cd-summary-link" id="msCartSummaryLink">View summary details</button>
                <?php endif; ?>
            </div>
            <?php if ($cdLines): ?>
                <a class="ms-cd-checkout" href="<?= e($checkoutUrl) ?>">Proceed to checkout</a>
            <?php else: ?>
                <a class="ms-cd-checkout" href="<?= e($homeUrl) ?>">Start shopping</a>
            <?php endif; ?>
        </div>
    </aside>
</div>

<div class="ms-cart-overlay<?= !empty($openAccountDrawer) ? ' is-open' : '' ?>" id="msAccountOverlay"<?= empty($openAccountDrawer) ? ' hidden' : '' ?> aria-hidden="<?= !empty($openAccountDrawer) ? 'false' : 'true' ?>">
    <button type="button" class="ms-cd-close" id="msAccountClose" aria-label="Close account">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
    <aside class="ms-cart-drawer" id="msAccountDrawer" role="dialog" aria-labelledby="msAccountTitle">
        <div class="ms-acc-head">
            <div class="ms-acc-title" id="msAccountTitle">Account</div>
        </div>
        <div class="ms-acc-body">
            <div class="ms-acc-block">
                <?php if (!empty($storeShopper)):
                    $accName = storefront_clean_person_name((string) ($storeShopper['name'] ?? ''));
                    if ($accName === '') {
                        $accName = explode('@', (string) ($storeShopper['email'] ?? 'customer'))[0];
                    }
                    ?>
                    <div class="ms-acc-user">
                        <div>
                            <div class="ms-acc-user-name"><?= e($accName) ?></div>
                            <?php if (!empty($storeShopper['email'])): ?>
                                <div class="ms-acc-user-email"><?= e((string) $storeShopper['email']) ?></div>
                            <?php endif; ?>
                        </div>
                        <a class="ms-acc-edit" href="<?= e(public_store_url($storeBiz, 'profile')) ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                            Edit
                        </a>
                    </div>
                <?php else: ?>
                    <div class="ms-acc-h">Welcome</div>
                    <p class="ms-acc-sub">Sign in to <?= e($pageTitle) ?></p>
                    <a class="ms-acc-signin" href="<?= e(public_store_signin_url($storeBiz)) ?>">Sign In</a>
                <?php endif; ?>
            </div>

            <?php if (!empty($storeShopper)): ?>
            <div class="ms-acc-block">
                <div class="ms-acc-sec">General</div>
                <a class="ms-acc-link" href="<?= e(public_store_url($storeBiz, 'orders')) ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    <span>Orders</span>
                </a>
                <a class="ms-acc-link" href="<?= e(public_store_url($storeBiz, 'invoices')) ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/></svg>
                    <span>Invoices</span>
                </a>
                <a class="ms-acc-link" href="<?= e(public_store_url($storeBiz, 'addresses')) ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-5.4 7-11a7 7 0 10-14 0c0 5.6 7 11 7 11z"/><circle cx="12" cy="10" r="2.4"/></svg>
                    <span>Addresses</span>
                </a>
            </div>
            <?php endif; ?>

            <div class="ms-acc-block">
                <div class="ms-acc-sec">Explore</div>
                <a class="ms-acc-link" href="<?= e($homeUrl) ?>" data-acc-close="1">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11.5L12 4l8 7.5"/><path d="M6 10.5V20h12v-9.5"/></svg>
                    <span>Home</span>
                </a>
                <a class="ms-acc-link" href="<?= e($homeUrl) ?>#msCategories" data-acc-close="1" id="msAccountCatsLink">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7.5" height="7.5" rx="1.2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.2"/></svg>
                    <span>All Categories</span>
                </a>
            </div>
        </div>
        <?php if (!empty($storeShopper)): ?>
        <div class="ms-acc-foot">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="storefront_signout">
                <button type="submit" class="ms-acc-logout">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 11-12.73 0M12 2v10"/></svg>
                    <span>Log out</span>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </aside>
</div>

<div class="ms-cart-overlay" id="msLocationOverlay" hidden aria-hidden="true">
    <aside class="ms-cart-drawer" id="msLocationDrawer" role="dialog" aria-labelledby="msLocTitle" style="max-width:460px;">
        <!-- Header (Dynamic based on View 1 or View 2) -->
        <div class="ms-loc-head" id="msLocHead">
            <button type="button" class="ms-loc-head-btn" id="msLocTopActionBtn" aria-label="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
            <div class="ms-loc-head-title" id="msLocTitle">Set Delivery Location</div>
        </div>

        <div class="ms-loc-body">
            <!-- View 1: Graphic Illustration & "Add Address" Button (Screenshot 2) -->
            <div class="ms-loc-prompt" id="msLocPromptView">
                <div class="ms-loc-art-wrap">
                    <svg viewBox="0 0 200 200" width="160" height="160" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Soft background clouds & dots -->
                        <circle cx="100" cy="100" r="75" fill="#f8fafc"/>
                        <circle cx="55" cy="65" r="2.5" fill="#cbd5e1"/>
                        <circle cx="145" cy="55" r="2" fill="#cbd5e1"/>
                        <circle cx="150" cy="140" r="3" fill="#cbd5e1"/>
                        <path d="M40 145 C60 140, 75 148, 95 145 C115 142, 135 148, 160 145" stroke="#e2e8f0" stroke-width="2" stroke-linecap="round"/>
                        
                        <!-- Secondary Pins in background -->
                        <g transform="translate(56, 62) scale(0.65)" opacity="0.6">
                            <path d="M40 0 C17.9 0 0 17.9 0 40 C0 70 40 100 40 100 C40 100 80 70 80 40 C80 17.9 62.1 0 40 0 Z" fill="#94a3b8"/>
                            <circle cx="40" cy="38" r="18" fill="#ffffff"/>
                        </g>
                        <g transform="translate(108, 72) scale(0.6)" opacity="0.6">
                            <path d="M40 0 C17.9 0 0 17.9 0 40 C0 70 40 100 40 100 C40 100 80 70 80 40 C80 17.9 62.1 0 40 0 Z" fill="#94a3b8"/>
                            <circle cx="40" cy="38" r="18" fill="#ffffff"/>
                        </g>

                        <!-- Main Central Map Pin with Plus icon -->
                        <g transform="translate(70, 36)">
                            <path d="M30 0 C13.4 0 0 13.4 0 30 C0 55 30 84 30 84 C30 84 60 55 60 30 C60 13.4 46.6 0 30 0 Z" fill="#818cf8" opacity="0.15"/>
                            <path d="M30 4 C15.6 4 4 15.6 4 30 C4 52 30 78 30 78 C30 78 56 52 56 30 C56 15.6 44.4 4 30 4 Z" fill="#64748b"/>
                            <circle cx="30" cy="28" r="14" fill="#ffffff"/>
                            <!-- Add/Plus circle badge -->
                            <circle cx="48" cy="12" r="10" fill="#94a3b8"/>
                            <path d="M48 7v10M43 12h10" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round"/>
                        </g>
                        <!-- Base shadow -->
                        <ellipse cx="100" cy="155" rx="30" ry="6" fill="#cbd5e1" opacity="0.6"/>
                    </svg>
                </div>
                <div class="ms-loc-prompt-title">Add Delivery Location</div>
                <div class="ms-loc-prompt-sub">Let us know where we should send your orders</div>
                <button type="button" class="ms-loc-btn" id="msBtnOpenAddressForm">Add Address</button>
            </div>

            <!-- View 2: Address Details Form (Screenshot 1) -->
            <?php
            $savedLoc = $_SESSION['sf_delivery_location_' . $bid] ?? [];
            $prefillName = $savedLoc['name'] ?? storefront_clean_person_name((string)($storeShopper['name'] ?? ''));
            $prefillPhone = $savedLoc['phone'] ?? (string)($storeShopper['phone'] ?? '');
            $prefillDoor = $savedLoc['door_no'] ?? '';
            $prefillStreet = $savedLoc['street_area'] ?? '';
            $prefillCity = $savedLoc['city'] ?? '';
            $prefillState = $savedLoc['state'] ?? 'West Bengal';
            $prefillPincode = $savedLoc['pincode'] ?? '';
            $indianStates = [
                'Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh',
                'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana',
                'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep',
                'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry',
                'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'
            ];
            ?>
            <div class="ms-loc-form-view" id="msLocFormView" style="display:none;">
                <form method="post" id="msDeliveryAddressForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_delivery_location">
                    <input type="hidden" name="return_page" value="<?= e($page) ?>">

                    <div class="ms-loc-form-group">
                        <label class="ms-loc-label">Name<span class="ms-req">*</span></label>
                        <input type="text" name="name" class="ms-loc-input" required value="<?= e($prefillName) ?>">
                    </div>

                    <div class="ms-loc-form-group">
                        <label class="ms-loc-label">Door No/Floor/Apartments<span class="ms-req">*</span></label>
                        <input type="text" name="door_no" class="ms-loc-input" required value="<?= e($prefillDoor) ?>">
                    </div>

                    <div class="ms-loc-form-group">
                        <label class="ms-loc-label">Street/Area/Landmark<span class="ms-req">*</span></label>
                        <input type="text" name="street_area" class="ms-loc-input" required value="<?= e($prefillStreet) ?>">
                    </div>

                    <div class="ms-loc-form-group">
                        <label class="ms-loc-label">City<span class="ms-req">*</span></label>
                        <input type="text" name="city" class="ms-loc-input" required value="<?= e($prefillCity) ?>">
                    </div>

                    <div class="ms-loc-form-group">
                        <label class="ms-loc-label">Country<span class="ms-req">*</span></label>
                        <input type="text" name="country" class="ms-loc-input" readonly value="India">
                    </div>

                    <div class="ms-loc-form-group">
                        <label class="ms-loc-label">State/Union Territory<span class="ms-req">*</span></label>
                        <select name="state" class="ms-loc-select" required>
                            <?php foreach ($indianStates as $st): ?>
                                <option value="<?= e($st) ?>" <?= ($st === $prefillState) ? 'selected' : '' ?>><?= e($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ms-loc-form-group">
                        <label class="ms-loc-label">ZIP/Postal Code<span class="ms-req">*</span></label>
                        <input type="text" name="pincode" class="ms-loc-input" required value="<?= e($prefillPincode) ?>">
                    </div>

                    <div class="ms-loc-form-group">
                        <label class="ms-loc-label">Mobile Number<span class="ms-req">*</span></label>
                        <input type="tel" name="phone" class="ms-loc-input" required value="<?= e($prefillPhone) ?>">
                    </div>

                    <button type="submit" class="ms-loc-btn ms-loc-save-btn">Save Address</button>
                </form>
            </div>
        </div>
    </aside>
</div>
<?php endif; ?>
<script>
(function () {
    function lockBody() {
        document.body.classList.add('ms-cart-lock');
    }
    function unlockIfNoneOpen() {
        var cart = document.getElementById('msCartOverlay');
        var acc = document.getElementById('msAccountOverlay');
        var loc = document.getElementById('msLocationOverlay');
        var cartOpen = cart && cart.classList.contains('is-open');
        var accOpen = acc && acc.classList.contains('is-open');
        var locOpen = loc && loc.classList.contains('is-open');
        if (!cartOpen && !accOpen && !locOpen) {
            document.body.classList.remove('ms-cart-lock');
        }
    }
    function closeDrawer(overlay) {
        if (!overlay) return;
        overlay.classList.remove('is-open');
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
        unlockIfNoneOpen();
    }
    function openDrawer(overlay) {
        if (!overlay) return;
        var overlays = [document.getElementById('msCartOverlay'), document.getElementById('msAccountOverlay'), document.getElementById('msLocationOverlay')];
        overlays.forEach(function (o) {
            if (o && o !== overlay) closeDrawer(o);
        });
        overlay.hidden = false;
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        lockBody();
    }
    function bindDrawer(overlayId, toggleId, closeId) {
        var overlay = document.getElementById(overlayId);
        var toggle = document.getElementById(toggleId);
        var closeBtn = document.getElementById(closeId);
        if (!overlay) return overlay;
        if (toggle) {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                if (overlay.classList.contains('is-open')) closeDrawer(overlay);
                else openDrawer(overlay);
            });
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', function () { closeDrawer(overlay); });
        }
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeDrawer(overlay);
        });
        if (overlay.classList.contains('is-open')) {
            overlay.hidden = false;
            lockBody();
        }
        return overlay;
    }

    var cartOverlay = bindDrawer('msCartOverlay', 'msCartToggle', 'msCartClose');
    var accOverlay = bindDrawer('msAccountOverlay', 'msAccountToggle', 'msAccountClose');
    var locOverlay = bindDrawer('msLocationOverlay', 'msLocationToggle', null);

    // Location Drawer View Switching (Prompt vs Form)
    var promptView = document.getElementById('msLocPromptView');
    var formView = document.getElementById('msLocFormView');
    var locHeadBtn = document.getElementById('msLocTopActionBtn');
    var locTitle = document.getElementById('msLocTitle');
    var btnOpenForm = document.getElementById('msBtnOpenAddressForm');

    function showLocPrompt() {
        if (promptView && formView) {
            promptView.style.display = 'flex';
            formView.style.display = 'none';
            if (locTitle) locTitle.textContent = 'Set Delivery Location';
            if (locHeadBtn) {
                locHeadBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>';
                locHeadBtn.setAttribute('aria-label', 'Close');
            }
        }
    }

    function showLocForm() {
        if (promptView && formView) {
            promptView.style.display = 'none';
            formView.style.display = 'block';
            if (locTitle) locTitle.textContent = 'Address Details';
            if (locHeadBtn) {
                locHeadBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>';
                locHeadBtn.setAttribute('aria-label', 'Back');
            }
        }
    }

    if (btnOpenForm) {
        btnOpenForm.addEventListener('click', showLocForm);
    }
    if (locHeadBtn) {
        locHeadBtn.addEventListener('click', function () {
            if (formView && formView.style.display !== 'none') {
                showLocPrompt();
            } else {
                closeDrawer(locOverlay);
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (locOverlay && locOverlay.classList.contains('is-open')) closeDrawer(locOverlay);
        else if (accOverlay && accOverlay.classList.contains('is-open')) closeDrawer(accOverlay);
        else if (cartOverlay && cartOverlay.classList.contains('is-open')) closeDrawer(cartOverlay);
    });

    var summaryLink = document.getElementById('msCartSummaryLink');
    var summaryBox = document.getElementById('msCartSummary');
    if (summaryLink && summaryBox) {
        summaryLink.addEventListener('click', function () {
            summaryBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    }

    document.querySelectorAll('[data-acc-close]').forEach(function (link) {
        link.addEventListener('click', function () {
            closeDrawer(accOverlay);
        });
    });
})();
</script>
<script>
(function () {
    var track = document.getElementById('msBanners');
    if (!track) return;

    var cards = track.querySelectorAll('.ms-banner-card');
    if (cards.length < 2) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var index = 0;
    var timer = null;
    var resumeTimer = null;

    function isCarousel() {
        var style = window.getComputedStyle(track);
        return style.display === 'flex' && track.scrollWidth > track.clientWidth + 12;
    }

    function slideTo(next) {
        if (!isCarousel()) return;
        var wrap = next >= cards.length;
        index = wrap ? 0 : next;
        var left = cards[index].offsetLeft - cards[0].offsetLeft;
        track.scrollTo({ left: left, behavior: wrap ? 'auto' : 'smooth' });
    }

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        if (resumeTimer) {
            clearTimeout(resumeTimer);
            resumeTimer = null;
        }
    }

    function start() {
        stop();
        if (!isCarousel()) return;
        timer = setInterval(function () {
            slideTo(index + 1);
        }, 2000);
    }

    function pauseThenResume() {
        stop();
        resumeTimer = setTimeout(start, 5000);
    }

    track.addEventListener('pointerdown', pauseThenResume);
    track.addEventListener('touchstart', pauseThenResume, { passive: true });
    track.addEventListener('wheel', pauseThenResume, { passive: true });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) stop();
        else start();
    });
    window.addEventListener('resize', function () {
        stop();
        start();
    });

    start();
})();

// Animated search placeholder slot-machine / slide effect
(function () {
    var searchWrap = document.querySelector('.ms-search-wrap');
    var searchInput = document.getElementById('msSearchInput');
    var wordEl = document.getElementById('msSearchWord');
    if (!searchInput || !wordEl || !searchWrap) return;

    var categories = <?= json_encode(array_values($searchCategories), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    if (!Array.isArray(categories) || categories.length === 0) return;

    var catIndex = 0;
    var timer = null;
    var isFocused = false;

    function checkValue() {
        if (searchInput.value && searchInput.value.trim() !== '') {
            searchWrap.classList.add('has-val');
        } else {
            searchWrap.classList.remove('has-val');
        }
    }

    function switchWord() {
        if (isFocused || (searchInput.value && searchInput.value.trim() !== '')) {
            return;
        }

        // 1. Slide OUT current word
        wordEl.classList.remove('ms-slide-in');
        wordEl.classList.add('ms-slide-out');

        setTimeout(function () {
            // 2. Change text to next category
            catIndex = (catIndex + 1) % categories.length;
            wordEl.textContent = '"' + categories[catIndex] + '"';

            // 3. Slide IN next word
            wordEl.classList.remove('ms-slide-out');
            wordEl.classList.add('ms-slide-in');

            // Schedule next switch in 1.7 seconds
            timer = setTimeout(switchWord, 1700);
        }, 260);
    }

    searchInput.addEventListener('focus', function () {
        isFocused = true;
        searchWrap.classList.add('is-active');
        if (timer) clearTimeout(timer);
    });

    searchInput.addEventListener('blur', function () {
        isFocused = false;
        searchWrap.classList.remove('is-active');
        checkValue();
        if (!searchInput.value || searchInput.value.trim() === '') {
            timer = setTimeout(switchWord, 1400);
        }
    });

    searchInput.addEventListener('input', checkValue);

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (timer) clearTimeout(timer);
        } else if (!isFocused && (!searchInput.value || searchInput.value.trim() === '')) {
            switchWord();
        }
    });

    checkValue();
    timer = setTimeout(switchWord, 1700);
})();
</script>
</body>
</html>

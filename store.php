<?php
/**
 * Public mobile storefront — client branding only (never platform logo).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/storefront_db.php';

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
                    <?php if (!empty($brand['show_location'])): ?>
                        <div class="ms-location-widget">
                            <span class="ms-location-sub">Delivery to <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg></span>
                            <span class="ms-location-main">Set delivery location</span>
                        </div>
                        <span class="ms-nav-divider" aria-hidden="true"></span>
                    <?php endif; ?>

                    <a class="ms-nav-item" href="<?= e($cartUrl) ?>" title="Cart" id="msCartToggle">
                        <span class="ms-nav-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l-1 12H7L6 7z"/><path d="M9 7V6a3 3 0 0 1 6 0v1"/></svg>
                            <?php if ($cartCount > 0): ?>
                                <span class="ms-cart-badge"><?= (int) $cartCount ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="ms-nav-label">Cart</span>
                    </a>

                    <a class="ms-nav-item" href="#account" title="Account" id="msAccountToggle">
                        <span class="ms-nav-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.2"/><circle cx="12" cy="10" r="3"/><path d="M6.8 18.2c1.4-2.2 3.2-3.2 5.2-3.2s3.8 1 5.2 3.2"/></svg>
                        </span>
                        <span class="ms-nav-label">Account</span>
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
                        <!-- Banner 1: Online Now -->
                        <div class="ms-banner-card ms-banner-1">
                            <div class="ms-banner-info">
                                <div class="ms-banner-title"><?= e($brand['banner_title'] ?: "We're online now!") ?></div>
                                <div class="ms-banner-sub"><?= e($brand['banner_subtitle'] ?: 'Stay at home and shop online.') ?></div>
                            </div>
                            <div class="ms-banner-art">
                                <svg viewBox="0 0 140 120" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <rect x="35" y="15" width="60" height="95" rx="8" fill="#ffffff"/>
                                    <path d="M30 30 L100 30 L95 48 L35 48 Z" fill="#ef4444"/>
                                    <path d="M35 30 L48 30 L45 48 L35 48 Z" fill="#ffffff" opacity="0.9"/>
                                    <path d="M60 30 L73 30 L70 48 L60 48 Z" fill="#ffffff" opacity="0.9"/>
                                    <path d="M85 30 L98 30 L95 48 L85 48 Z" fill="#ffffff" opacity="0.9"/>
                                    <circle cx="42" cy="22" r="6" fill="#dc2626"/>
                                    <circle cx="42" cy="22" r="2.5" fill="#ffffff"/>
                                    <rect x="75" y="12" width="22" height="18" rx="2" fill="#d97706"/>
                                    <line x1="86" y1="12" x2="86" y2="30" stroke="#b45309" stroke-width="2"/>
                                    <circle cx="108" cy="72" r="10" fill="#fed7aa"/>
                                    <path d="M102 68 Q108 60 114 68 Z" fill="#1e293b"/>
                                    <path d="M96 84 C96 78, 120 78, 120 84 L118 105 L98 105 Z" fill="#f43f5e"/>
                                    <path d="M98 90 L85 86" stroke="#fed7aa" stroke-width="4" stroke-linecap="round"/>
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
                                <svg viewBox="0 0 140 120" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="105" cy="18" r="2.5" fill="#ffffff" opacity="0.8"/>
                                    <circle cx="35" cy="24" r="2" fill="#ffffff" opacity="0.6"/>
                                    <g transform="translate(80, 12)">
                                        <circle cx="10" cy="10" r="10" fill="#ffffff" opacity="0.95"/>
                                        <path d="M8 7v4l2 3 3-1v-4h-2V6c0-.8-.4-1.5-1.2-1.5S8 5.8 8 7z" fill="#3b82f6"/>
                                    </g>
                                    <g transform="translate(48, 22)">
                                        <rect x="0" y="0" width="18" height="18" rx="5" fill="#f43f5e"/>
                                        <path d="M9 13l-1-1C5 9 3 7 3 5.5a2.5 2.5 0 0 1 4-1.5 2.5 2.5 0 0 1 4 1.5 2.5 2.5 0 0 1 4-1.5 2.5 2.5 0 0 1 4 1.5c0 1.5-2 3.5-5 6.5l-1 1z" fill="#ffffff"/>
                                    </g>
                                    <g transform="translate(110, 46)">
                                        <circle cx="8" cy="8" r="8" fill="#22c55e"/>
                                        <polyline points="4 8 7 11 12 5" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </g>
                                    <rect x="42" y="44" width="24" height="30" rx="3" fill="#a855f7" transform="rotate(-10 42 44)"/>
                                    <path d="M58 40 L98 40 L95 86 L61 86 Z" fill="#e09f67"/>
                                    <path d="M58 40 L98 40 L96 45 L60 45 Z" fill="#c8834c"/>
                                    <path d="M70 40 C70 28, 86 28, 86 40" fill="none" stroke="#f8fafc" stroke-width="2.2" stroke-linecap="round"/>
                                    <path d="M46 54 L80 54 L78 90 L48 90 Z" fill="#f2b279"/>
                                    <path d="M85 58 L110 58 L108 90 L87 90 Z" fill="#e09f67"/>
                                </svg>
                            </div>
                        </div>

                        <!-- Banner 3: Order with Ease -->
                        <div class="ms-banner-card ms-banner-3">
                            <div class="ms-banner-info">
                                <div class="ms-banner-title">Order<br>with Ease</div>
                                <div class="ms-banner-sub">Receive<br>with Speed</div>
                            </div>
                            <div class="ms-banner-art">
                                <svg viewBox="0 0 140 120" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <g transform="translate(30, 20)">
                                        <rect x="25" y="15" width="28" height="24" rx="3" fill="#f59e0b" transform="rotate(-12 25 15)"/>
                                        <path d="M22 26 L12 18 M20 34 L8 28" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity="0.8"/>
                                        <g transform="translate(45, 10)">
                                            <path d="M10 20 L40 10 L55 26 L25 36 Z" fill="#fbbf24"/>
                                            <path d="M10 20 L25 36 L25 56 L10 40 Z" fill="#d97706"/>
                                            <path d="M25 36 L55 26 L55 46 L25 56 Z" fill="#b45309"/>
                                            <path d="M10 20 C0 10, -5 20, 5 28 Z" fill="#ffffff" opacity="0.9"/>
                                            <path d="M40 10 C50 0, 55 10, 45 18 Z" fill="#ffffff" opacity="0.9"/>
                                        </g>
                                        <rect x="35" y="55" width="24" height="20" rx="3" fill="#fbbf24" transform="rotate(8 35 55)"/>
                                        <path d="M30 65 L18 62 M32 72 L20 74" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity="0.8"/>
                                    </g>
                                </svg>
                            </div>
                        </div>
                    </div>

                <?php elseif ($homeSec === 'category' && $brand['show_categories'] && $categories && $q === ''): ?>
                    <div id="msCategories" class="ms-sec-title"><?= e($brand['category_section_name'] ?: 'All Categories') ?></div>
                    <div class="ms-cat-grid" style="--ms-cat-cols: <?= $catCols ?>;">
                        <?php foreach ($categories as $cat):
                            $catUrl = public_store_url($storeBiz, 'home', ['category_id' => (int) $cat['id']]);
                            $thumb = !empty($cat['image_path']) ? (string) $cat['image_path'] : '';
                            if (!$thumb) {
                                $catProducts = get_products('', (int) $cat['id'], 'active', '', $bid);
                                foreach ($catProducts as $cp) {
                                    if (!empty($cp['image_path'])) { $thumb = (string) $cp['image_path']; break; }
                                }
                            }
                            ?>
                            <a class="ms-cat-card" href="<?= e($catUrl) ?>">
                                <div class="ms-cat-img-box">
                                    <?php if ($thumb): ?>
                                        <img src="<?= asset($thumb) ?>" alt="<?= e((string) $cat['name']) ?>">
                                    <?php else: ?>
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="3" width="18" height="18" rx="3" ry="3"/>
                                            <circle cx="8.5" cy="8.5" r="1.5" fill="#cbd5e1"/>
                                            <polyline points="21 15 16 10 5 21" fill="#f1f5f9" stroke="#cbd5e1"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="ms-cat-title"><?= e(strtoupper((string) $cat['name'])) ?></div>
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

                                $stockText = 'In stock';
                                if (!empty($brand['display_stock_count'])) {
                                    $stockText = $inStock ? ((int)$p['stock_quantity'] . ' units available') : 'Out of stock';
                                } elseif (!empty($brand['display_low_stock_below_10'])) {
                                    if ($inStock && (int)$p['stock_quantity'] < 10) {
                                        $stockText = 'Only ' . (int)$p['stock_quantity'] . ' left in stock';
                                    } else {
                                        $stockText = $inStock ? 'In stock' : 'Out of stock';
                                    }
                                } else {
                                    $stockText = $inStock ? 'In stock' : 'Out of stock';
                                }
                                ?>
                                <div class="ms-product-card">
                                    <a class="ms-product-img-wrap" href="<?= e($pUrl) ?>">
                                        <?php if ($inStock): ?>
                                            <span class="ms-product-badge">In Stock</span>
                                        <?php else: ?>
                                            <span class="ms-product-badge" style="background:#dc2626">Out of Stock</span>
                                        <?php endif; ?>

                                        <?php if ($img): ?>
                                            <img src="<?= e($img) ?>" alt="<?= e((string) $p['name']) ?>">
                                        <?php else: ?>
                                            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="3" width="18" height="18" rx="3" ry="3"/>
                                                <circle cx="8.5" cy="8.5" r="1.5" fill="#cbd5e1"/>
                                                <polyline points="21 15 16 10 5 21" fill="#f8fafc" stroke="#cbd5e1"/>
                                            </svg>
                                        <?php endif; ?>
                                    </a>
                                    <div class="ms-product-body">
                                        <div>
                                            <a class="ms-product-name" href="<?= e($pUrl) ?>" style="text-decoration:none;color:inherit"><?= e((string) $p['name']) ?></a>
                                            <div class="ms-product-variant"><?= e($stockText) ?></div>
                                        </div>
                                        <div class="ms-product-foot">
                                            <?php if (empty($brand['hide_product_price'])): ?>
                                                <div class="ms-product-price"><?= sf_money($currency, (float) $p['selling_price']) ?></div>
                                            <?php else: ?>
                                                <div></div>
                                            <?php endif; ?>
                                            <form method="post" style="margin:0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="add_to_cart">
                                                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                                <input type="hidden" name="qty" value="1">
                                                <input type="hidden" name="redirect_page" value="home">
                                                <button class="ms-add-btn" type="submit" <?= $inStock ? '' : 'disabled' ?>>Add</button>
                                            </form>
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
                    $inStock = (int) $product['stock_quantity'] > 0;
                    $img = sf_product_image($product['image_path'] ?? null);
                    ?>
                    <div class="ms-form-card">
                        <a href="<?= e($homeUrl) ?>" class="ms-btn-ghost" style="margin-bottom:14px;width:auto;display:inline-flex">← Back to store</a>
                        <div class="ms-product-hero">
                            <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e((string) $product['name']) ?>"><?php else: ?>No image<?php endif; ?>
                        </div>
                        <h1 style="font-size:20px;font-weight:800;margin-bottom:6px;text-transform:uppercase"><?= e((string) $product['name']) ?></h1>
                        <?php if (empty($brand['hide_product_price'])): ?>
                            <div class="ms-product-price" style="font-size:20px;margin-bottom:8px"><?= sf_money($currency, (float) $product['selling_price']) ?></div>
                        <?php endif; ?>
                        <p class="ms-item-meta" style="margin-bottom:16px;color:#64748b"><?= $inStock ? ((int) $product['stock_quantity'] . ' in stock') : 'Out of stock' ?></p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_to_cart">
                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                            <input type="hidden" name="redirect_page" value="product">
                            <?php if (!empty($brand['allow_custom_quantity'])): ?>
                                <label class="ms-label">Quantity</label>
                                <input class="ms-input" type="number" name="qty" min="1" value="1" <?= $inStock ? '' : 'disabled' ?>>
                            <?php else: ?>
                                <input type="hidden" name="qty" value="1">
                            <?php endif; ?>
                            <button class="ms-btn" type="submit" <?= $inStock ? '' : 'disabled' ?>>Add to cart</button>
                        </form>
                    </div>
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
                            <input class="ms-input" type="text" name="name" required value="<?= e((string) ($storeShopper['name'] ?? '')) ?>">
                            <label class="ms-label">Phone</label>
                            <input class="ms-input" type="tel" name="phone" required value="<?= e((string) ($storeShopper['phone'] ?? '')) ?>">
                            <label class="ms-label">Email</label>
                            <input class="ms-input" type="email" name="email" value="<?= e((string) ($storeShopper['email'] ?? '')) ?>">
                            <label class="ms-label">Delivery address</label>
                            <textarea class="ms-textarea" name="address" rows="3" required></textarea>
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
<?php endif; ?>
<script>
(function () {
    function lockBody() {
        document.body.classList.add('ms-cart-lock');
    }
    function unlockIfNoneOpen() {
        var cart = document.getElementById('msCartOverlay');
        var acc = document.getElementById('msAccountOverlay');
        var cartOpen = cart && cart.classList.contains('is-open');
        var accOpen = acc && acc.classList.contains('is-open');
        if (!cartOpen && !accOpen) {
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
        closeDrawer(document.getElementById('msCartOverlay') === overlay
            ? document.getElementById('msAccountOverlay')
            : document.getElementById('msCartOverlay'));
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

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (accOverlay && accOverlay.classList.contains('is-open')) closeDrawer(accOverlay);
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

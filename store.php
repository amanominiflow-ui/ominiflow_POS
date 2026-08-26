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
    if (!in_array($page, ['home', 'product', 'cart', 'checkout', 'thanks', 'orders', 'invoices', 'addresses', 'profile'], true)) {
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
            $res = add_to_storefront_cart($bid, $pid, $qty);
            set_flash(!empty($res['success']) ? 'success' : 'error', !empty($res['success']) ? 'Added to cart.' : ($res['error'] ?? 'Could not add item.'));
            $back = (string) ($_POST['redirect_page'] ?? 'home');
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
            $result = place_online_store_order($bid, [
                'name' => (string) ($_POST['name'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'address' => (string) ($_POST['address'] ?? ''),
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
    if (in_array($page, ['orders', 'invoices', 'addresses', 'profile'], true) && !$storeShopper) {
        redirect(public_store_signin_url($storeBiz));
    }
}

function sf_money(string $symbol, float $amount): string {
    return e($symbol) . number_format($amount, 2);
}

function sf_product_image(?string $path): string {
    return $path ? asset($path) : '';
}

$favicon = '';
if (!empty($brand['favicon_path'])) {
    $favicon = asset((string) $brand['favicon_path']);
} elseif (!empty($brand['logo_path'])) {
    $favicon = asset((string) $brand['logo_path']);
}
$fontSize = in_array(($brand['font_size'] ?? 'medium'), ['small', 'medium', 'large'], true) ? $brand['font_size'] : 'medium';
$headerText = (string) ($brand['header_text_color'] ?? '#ffffff');
$buttonText = (string) ($brand['button_text_color'] ?? '#ffffff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <?php if ($favicon): ?>
        <link rel="icon" href="<?= e($favicon) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= asset('assets/css/storefront.css') ?>?v=9">
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

                <form class="ms-search-wrap" method="get" action="<?= e($homeUrl) ?>">
                    <?php if (!store_is_on_custom_domain($storeBiz) && !empty($storeBiz['store_slug'])): ?>
                        <input type="hidden" name="slug" value="<?= e((string) $storeBiz['store_slug']) ?>">
                    <?php endif; ?>
                    <div class="ms-search-box">
                        <svg class="ms-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7.2"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input class="ms-search" type="search" name="q" value="<?= e(trim((string) ($_GET['q'] ?? ''))) ?>" placeholder="<?= e($brand['search_placeholder'] ?: 'Search by') ?>">
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
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<?php if (empty($storeNotFound)):
    $cdLines = $drawerCart['lines'] ?? [];
    $cdLineCount = count($cdLines);
    $cdQty = (int) ($drawerCart['count'] ?? 0);
    $cdSub = (float) ($drawerCart['subtotal'] ?? 0);
    $cdTax = (float) ($drawerCart['tax'] ?? 0);
    $cdTotal = (float) ($drawerCart['total'] ?? 0);
    $cdReturnPage = in_array($page, ['home', 'product', 'cart', 'checkout', 'thanks', 'orders', 'invoices', 'addresses', 'profile'], true) ? $page : 'home';
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
</script>
</body>
</html>

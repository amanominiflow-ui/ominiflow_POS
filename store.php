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
} else {
    $storeNotFound = false;
    $bid = (int) $storeBiz['id'];
    $brand = get_mobile_store_settings($bid);
    $storeSettings = get_store_settings($bid);
    $pageTitle = (string) $brand['display_name'];
    $published = (int) ($storeBiz['store_published'] ?? 1) === 1;
    $page = trim((string) ($_GET['page'] ?? 'home'));
    if (!in_array($page, ['home', 'product', 'cart', 'checkout', 'thanks'], true)) {
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
            redirect(public_store_url($storeBiz, $back === 'product' ? 'product' : 'home', $params));
        }

        if ($action === 'update_cart') {
            $res = update_storefront_cart_qty($bid, (int) ($_POST['product_id'] ?? 0), (int) ($_POST['qty'] ?? 0));
            if (empty($res['success']) && !empty($res['error'])) {
                set_flash('error', $res['error']);
            }
            redirect(public_store_url($storeBiz, 'cart'));
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
}

function sf_money(string $symbol, float $amount): string {
    return e($symbol) . number_format($amount, 2);
}

function sf_product_image(?string $path): string {
    return $path ? asset($path) : '';
}

$favicon = !empty($brand['logo_path']) ? asset($brand['logo_path']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <?php if ($favicon): ?>
        <link rel="icon" href="<?= e($favicon) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= asset('assets/css/storefront.css') ?>">
    <style>
        :root {
            --ms-header: <?= e($brand['header_color']) ?>;
            --ms-accent: <?= e($brand['accent_color']) ?>;
        }
    </style>
</head>
<body class="ms-body">
<div class="ms-app">
    <header class="ms-top-nav">
        <div class="ms-top-container">
            <?php if ($storeNotFound): ?>
                <div class="ms-brand-left">
                    <span class="ms-initials">?</span>
                    <div class="ms-title">Store</div>
                </div>
            <?php else: ?>
                <div class="ms-top-row">
                    <a class="ms-brand-left" href="<?= e($homeUrl) ?>">
                        <span class="<?= $brand['logo_path'] ? 'ms-logo' : 'ms-initials' ?>">
                            <?php if ($brand['logo_path']): ?>
                                <img src="<?= asset($brand['logo_path']) ?>" alt="<?= e($pageTitle) ?>">
                            <?php else: ?>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                            <?php endif; ?>
                        </span>
                        <span class="ms-title"><?= e($pageTitle) ?></span>
                    </a>

                    <div class="ms-top-actions">
                        <?php if (!empty($brand['show_location'])): ?>
                            <div class="ms-location-widget">
                                <span class="ms-location-sub">Delivery to ▾</span>
                                <span class="ms-location-main">Set delivery location</span>
                            </div>
                        <?php endif; ?>

                        <a class="ms-nav-item" href="<?= e($cartUrl) ?>" title="Cart">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                            <span>Cart</span>
                            <?php if ($cartCount > 0): ?>
                                <span class="ms-cart-badge"><?= (int) $cartCount ?></span>
                            <?php endif; ?>
                        </a>

                        <a class="ms-nav-item" href="<?= e($cartUrl) ?>" title="Account">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
                            <span>Account</span>
                        </a>
                    </div>
                </div>

                <form class="ms-search-wrap" method="get" action="<?= e($homeUrl) ?>">
                    <?php if (!store_is_on_custom_domain($storeBiz) && !empty($storeBiz['store_slug'])): ?>
                        <input type="hidden" name="slug" value="<?= e((string) $storeBiz['store_slug']) ?>">
                    <?php endif; ?>
                    <div class="ms-search-box">
                        <svg class="ms-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input class="ms-search" type="search" name="q" value="<?= e(trim((string) ($_GET['q'] ?? ''))) ?>" placeholder="<?= e($brand['search_placeholder'] ?: 'Search by category or item') ?>">
                    </div>
                </form>
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
                ?>

                <?php if ($brand['show_banner'] && $q === '' && !$catId): ?>
                    <div class="ms-banners-grid">
                        <!-- Banner 1: Online Now -->
                        <div class="ms-banner-card ms-banner-1">
                            <div class="ms-banner-info">
                                <div class="ms-banner-title">We're<br>online now!</div>
                                <div class="ms-banner-sub">Stay at home and<br>shop online.</div>
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
                <?php endif; ?>

                <?php if ($brand['show_categories'] && $categories && $q === ''): ?>
                    <div class="ms-sec-title">All Categories</div>
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
                <?php endif; ?>

                <?php if ($brand['show_items']): ?>
                    <div class="ms-sec-title"><?= $catId ? 'Category items' : 'All Items' ?></div>
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
                $hydrated = hydrate_storefront_cart($bid);
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
                $hydrated = hydrate_storefront_cart($bid);
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
                            <input class="ms-input" type="text" name="name" required>
                            <label class="ms-label">Phone</label>
                            <input class="ms-input" type="tel" name="phone" required>
                            <label class="ms-label">Email</label>
                            <input class="ms-input" type="email" name="email">
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
</body>
</html>

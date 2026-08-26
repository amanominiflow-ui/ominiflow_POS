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
    <header class="ms-top">
        <div class="ms-top-row">
            <?php if ($storeNotFound): ?>
                <span class="ms-initials">?</span>
                <div class="ms-title">Store</div>
                <span></span>
            <?php else: ?>
                <a class="<?= $brand['logo_path'] ? 'ms-logo' : 'ms-initials' ?>" href="<?= e($homeUrl) ?>">
                    <?php if ($brand['logo_path']): ?>
                        <img src="<?= asset($brand['logo_path']) ?>" alt="<?= e($pageTitle) ?>">
                    <?php else: ?>
                        <?= e($brand['initials']) ?>
                    <?php endif; ?>
                </a>
                <div class="ms-title"><?= e($pageTitle) ?></div>
                <a class="ms-icon-btn" href="<?= e($cartUrl) ?>" title="Cart"><?= (int) $cartCount ?></a>
            <?php endif; ?>
        </div>
        <?php if (!$storeNotFound && !empty($brand['show_location'])): ?>
            <div class="ms-location">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11a3 3 0 100-6 3 3 0 000 6z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 22s8-7.5 8-13a8 8 0 10-16 0c0 5.5 8 13 8 13z"/></svg>
                Set delivery location
            </div>
        <?php endif; ?>
    </header>

    <?php if (!$storeNotFound && $published && $page === 'home'): ?>
        <form class="ms-search-wrap" method="get" action="<?= e($homeUrl) ?>">
            <?php if (!store_is_on_custom_domain($storeBiz) && !empty($storeBiz['store_slug'])): ?>
                <input type="hidden" name="slug" value="<?= e((string) $storeBiz['store_slug']) ?>">
            <?php endif; ?>
            <input class="ms-search" type="search" name="q" value="<?= e(trim((string) ($_GET['q'] ?? ''))) ?>" placeholder="<?= e($brand['search_placeholder']) ?>">
        </form>
    <?php endif; ?>

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
                ?>

                <?php if ($brand['show_banner'] && $q === '' && !$catId): ?>
                    <div class="ms-banner" <?php if ($brand['banner_image']): ?>style="background-image:linear-gradient(90deg,rgba(15,76,58,.55),rgba(20,184,166,.35)),url('<?= asset($brand['banner_image']) ?>')"<?php endif; ?>>
                        <div>
                            <h2><?= e($brand['banner_title']) ?></h2>
                            <p><?= e($brand['banner_subtitle']) ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($brand['show_categories'] && $categories && $q === ''): ?>
                    <div class="ms-sec-title">All Categories</div>
                    <div class="ms-cat-grid">
                        <?php foreach ($categories as $cat):
                            $catUrl = public_store_url($storeBiz, 'home', ['category_id' => (int) $cat['id']]);
                            $catProducts = get_products('', (int) $cat['id'], 'active', '', $bid);
                            $thumb = '';
                            foreach ($catProducts as $cp) {
                                if (!empty($cp['image_path'])) { $thumb = (string) $cp['image_path']; break; }
                            }
                            ?>
                            <a class="ms-cat" href="<?= e($catUrl) ?>">
                                <div class="ms-cat-img">
                                    <?php if ($thumb): ?><img src="<?= asset($thumb) ?>" alt=""><?php else: ?><?= e(strtoupper(substr((string) $cat['name'], 0, 1))) ?><?php endif; ?>
                                </div>
                                <div class="ms-cat-name"><?= e((string) $cat['name']) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($brand['show_items']): ?>
                    <div class="ms-sec-title"><?= $catId ? 'Category items' : 'All Items' ?></div>
                    <?php if (!$products): ?>
                        <div class="ms-empty">No items to show.</div>
                    <?php else: ?>
                        <div class="ms-item-list">
                            <?php foreach ($products as $p):
                                $img = sf_product_image($p['image_path'] ?? null);
                                $pUrl = public_store_url($storeBiz, 'product', ['id' => (int) $p['id']]);
                                $inStock = (int) $p['stock_quantity'] > 0;
                                ?>
                                <div class="ms-item">
                                    <a class="ms-item-img" href="<?= e($pUrl) ?>">
                                        <?php if ($img): ?><img src="<?= e($img) ?>" alt=""><?php else: ?>Item<?php endif; ?>
                                    </a>
                                    <div>
                                        <a class="ms-item-name" href="<?= e($pUrl) ?>" style="color:inherit;text-decoration:none"><?= e((string) $p['name']) ?></a>
                                        <div class="ms-item-meta"><?= $inStock ? 'In stock' : 'Out of stock' ?></div>
                                        <div class="ms-price"><?= sf_money($currency, (float) $p['selling_price']) ?></div>
                                    </div>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="add_to_cart">
                                        <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                        <input type="hidden" name="qty" value="1">
                                        <input type="hidden" name="redirect_page" value="home">
                                        <button class="ms-btn" type="submit" <?= $inStock ? '' : 'disabled' ?> style="width:auto;padding:8px 10px;font-size:12px">Add</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
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
                    <a href="<?= e($homeUrl) ?>" class="ms-btn-ghost" style="margin-bottom:12px;width:auto">Back</a>
                    <div class="ms-product-hero">
                        <?php if ($img): ?><img src="<?= e($img) ?>" alt=""><?php else: ?>No image<?php endif; ?>
                    </div>
                    <h1 style="font-size:20px;margin-bottom:6px"><?= e((string) $product['name']) ?></h1>
                    <div class="ms-price" style="font-size:18px;margin-bottom:8px"><?= sf_money($currency, (float) $product['selling_price']) ?></div>
                    <p class="ms-item-meta" style="margin-bottom:14px"><?= $inStock ? ((int) $product['stock_quantity'] . ' in stock') : 'Out of stock' ?></p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_to_cart">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                        <input type="hidden" name="redirect_page" value="product">
                        <label class="ms-label">Qty</label>
                        <input class="ms-input" type="number" name="qty" min="1" value="1" <?= $inStock ? '' : 'disabled' ?>>
                        <button class="ms-btn" type="submit" <?= $inStock ? '' : 'disabled' ?>>Add to cart</button>
                    </form>
                <?php endif; ?>

            <?php elseif ($page === 'cart'):
                $hydrated = hydrate_storefront_cart($bid);
                ?>
                <h1 style="font-size:20px;margin-bottom:12px">Cart</h1>
                <?php if (empty($hydrated['lines'])): ?>
                    <div class="ms-empty">Cart is empty.</div>
                <?php else: ?>
                    <?php foreach ($hydrated['lines'] as $line): $p = $line['product']; ?>
                        <div class="ms-cart-line">
                            <div>
                                <strong><?= e((string) $p['name']) ?></strong>
                                <form method="post" style="margin-top:6px;display:flex;gap:6px">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_cart">
                                    <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                    <input class="ms-input" style="width:70px;margin:0" type="number" name="qty" min="0" value="<?= (int) $line['qty'] ?>">
                                    <button class="ms-btn-ghost" type="submit">Update</button>
                                </form>
                            </div>
                            <div><?= sf_money($currency, (float) $line['line_total']) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="ms-total"><span>Total</span><span><?= sf_money($currency, $hydrated['total']) ?></span></div>
                    <a class="ms-btn" href="<?= e($checkoutUrl) ?>">Checkout</a>
                <?php endif; ?>

            <?php elseif ($page === 'checkout'):
                $hydrated = hydrate_storefront_cart($bid);
                if (empty($hydrated['lines'])): ?>
                    <div class="ms-empty">Cart is empty.</div>
                <?php else: ?>
                    <h1 style="font-size:20px;margin-bottom:12px">Checkout</h1>
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
                        <label class="ms-label">Payment</label>
                        <select class="ms-select" name="payment_method">
                            <option value="cod">Cash on Delivery</option>
                            <option value="upi">UPI</option>
                            <option value="pickup">Pay at store / Pickup</option>
                        </select>
                        <div class="ms-total"><span>Total</span><span><?= sf_money($currency, $hydrated['total']) ?></span></div>
                        <button class="ms-btn" type="submit">Place order</button>
                    </form>
                <?php endif; ?>

            <?php elseif ($page === 'thanks'): ?>
                <div class="ms-empty">
                    <h1 style="color:#0f172a;margin-bottom:8px">Order placed</h1>
                    <?php if (!empty($_GET['order'])): ?><p><strong><?= e((string) $_GET['order']) ?></strong></p><?php endif; ?>
                    <a class="ms-btn" href="<?= e($homeUrl) ?>" style="margin-top:16px">Continue shopping</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
</body>
</html>

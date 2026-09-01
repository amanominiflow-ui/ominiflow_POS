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
    'banner_bg_color' => '#7c3aed',
    'banner_text_color' => '#ffffff',
    'banner_2_tag' => 'Best deal,',
    'banner_2_title' => 'Start Shopping',
    'banner_2_subtitle' => 'and discover the best deals!',
    'banner_2_bg_color' => '#2563eb',
    'banner_2_text_color' => '#ffffff',
    'banner_3_tag' => 'Order',
    'banner_3_title' => 'with Ease',
    'banner_3_subtitle' => 'with Speed',
    'banner_3_bg_color' => '#028476',
    'banner_3_text_color' => '#ffffff',
    'banner_4_tag' => 'Special Offer,',
    'banner_4_title' => 'Super Savings',
    'banner_4_subtitle' => 'Get exclusive discounts today!',
    'banner_4_bg_color' => '#ea580c',
    'banner_4_text_color' => '#ffffff',
    'banner_5_tag' => 'Fresh Deals,',
    'banner_5_title' => 'Top Quality Picks',
    'banner_5_subtitle' => 'Handpicked best products for you.',
    'banner_5_bg_color' => '#0891b2',
    'banner_5_text_color' => '#ffffff',
    'banner_6_tag' => 'Fast & Reliable,',
    'banner_6_title' => 'Express Delivery',
    'banner_6_subtitle' => 'Direct to your doorstep quickly.',
    'banner_6_bg_color' => '#9333ea',
    'banner_6_text_color' => '#ffffff',
    'search_placeholder' => 'Search by item or category',
    'show_location' => true,
    'show_banner' => true,
    'show_categories' => true,
    'show_trending_items' => true,
    'trending_section_name' => 'Top Trending Items',
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
    if (!in_array($page, ['home', 'product', 'cart', 'checkout', 'thanks', 'orders', 'order', 'invoices', 'addresses', 'profile', 'privacy', 'contact', 'about', 'terms', 'refund'], true)) {
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

            $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
            if ($isAjax) {
                header('Content-Type: application/json');
                $c = get_storefront_cart($bid);
                $cnt = 0;
                foreach ($c as $lineQty) { $cnt += (int)$lineQty; }
                echo json_encode([
                    'success' => !empty($res['success']),
                    'cart_count' => $cnt,
                    'message' => !empty($res['success']) ? 'Added to cart.' : ($res['error'] ?? 'Could not add item.')
                ]);
                exit;
            }

            set_flash(!empty($res['success']) ? 'success' : 'error', !empty($res['success']) ? 'Added to cart.' : ($res['error'] ?? 'Could not add item.'));
            $params = $back === 'product' ? ['id' => $pid] : [];
            if (!empty($_GET['category_id'])) {
                $params['category_id'] = (int) $_GET['category_id'];
            }
            if (!empty($_GET['q'])) {
                $params['q'] = (string) $_GET['q'];
            }
            redirect(public_store_url($storeBiz, $back === 'product' ? 'product' : 'home', $params));
        }

        if ($action === 'update_cart') {
            $res = update_storefront_cart_qty($bid, (int) ($_POST['product_id'] ?? 0), (int) ($_POST['qty'] ?? 0));
            if (empty($res['success']) && !empty($res['error'])) {
                set_flash('error', $res['error']);
            }
            $allowedReturn = ['home', 'product', 'cart', 'checkout', 'thanks', 'orders', 'order', 'invoices', 'addresses', 'profile'];
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
                redirect(public_store_url($storeBiz, 'order', [
                    'id' => (string) ($result['order_number'] ?? ''),
                    'new' => '1',
                ]));
            }
            $msg = is_array($result['errors'] ?? null) ? implode(' ', $result['errors']) : 'Could not place order.';
            set_flash('error', $msg);
            redirect(public_store_url($storeBiz, 'checkout'));
        }

        if ($action === 'cancel_order') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $orderNum = trim((string) ($_POST['order_number'] ?? ''));
            $shopper = get_storefront_shopper($bid);
            $res = cancel_storefront_order($bid, $orderId, $shopper ? (int)$shopper['id'] : null);
            if (!empty($res['success'])) {
                set_flash('success', $res['message'] ?? 'Order cancelled successfully.');
            } else {
                set_flash('error', $res['message'] ?? 'Could not cancel order.');
            }
            redirect(public_store_url($storeBiz, 'order', ['id' => $orderNum !== '' ? $orderNum : $orderId]));
        }

        if ($action === 'reorder') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $res = reorder_storefront_order($bid, $orderId);
            if (!empty($res['success'])) {
                set_flash('success', 'Items added to your cart.');
                redirect(public_store_url($storeBiz, 'checkout'));
            } else {
                set_flash('error', $res['message'] ?? 'Could not reorder items.');
                redirect(public_store_url($storeBiz, 'orders'));
            }
        }

        if ($action === 'subscribe_newsletter') {
            $email = (string) ($_POST['email'] ?? '');
            $res = subscribe_storefront_newsletter($bid, $email);
            $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode($res);
                exit;
            }
            set_flash(!empty($res['success']) ? 'success' : 'error', $res['message'] ?? $res['error'] ?? 'Subscribed.');
            redirect(public_store_url($storeBiz, $page, $_GET));
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

if (!function_exists('storefront_get_all_product_images')) {
    function storefront_get_all_product_images(array $p, int $businessId): array {
        $images = [];
        if (!empty($p['image_path'])) {
            $url = sf_product_image((string) $p['image_path']);
            if ($url && !in_array($url, $images, true)) {
                $images[] = $url;
            }
        }
        if (function_exists('get_product_images')) {
            $gallery = get_product_images((int) ($p['id'] ?? 0), $businessId);
            foreach ($gallery as $g) {
                if (!empty($g['path'])) {
                    $url = sf_product_image((string) $g['path']);
                    if ($url && !in_array($url, $images, true)) {
                        $images[] = $url;
                    }
                }
            }
        }
        if (!empty($p['rear_image_path'])) {
            $url = sf_product_image((string) $p['rear_image_path']);
            if ($url && !in_array($url, $images, true)) {
                $images[] = $url;
            }
        }
        return $images;
    }
}

if (!function_exists('storefront_parse_product_display_info')) {
    function storefront_parse_product_display_info(array $p, int $businessId): array {
        $isVariable = (($p['product_type'] ?? '') === 'variable');
        $variants = ($isVariable && function_exists('get_product_variants')) ? get_product_variants((int) $p['id'], $businessId) : [];
        $varCount = count($variants);
        
        $attrText = '';
        $mrp = (float) ($p['mrp'] ?? 0);
        $sellingPrice = (float) ($p['selling_price'] ?? 0);
        
        if ($isVariable && $varCount > 0) {
            $firstVar = $variants[0];
            $vName = trim((string) ($firstVar['variant_name'] ?? ''));
            
            if (!empty($firstVar['selling_price']) && (float) $firstVar['selling_price'] > 0) {
                $sellingPrice = (float) $firstVar['selling_price'];
            }
            if (!empty($firstVar['cost_price']) && (float) ($firstVar['mrp'] ?? 0) > 0) {
                $mrp = (float) $firstVar['mrp'];
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
            'variantCount' => $varCount,
            'attr_text' => $attrText,
            'attrText' => $attrText,
            'selling_price' => $sellingPrice,
            'mrp' => $mrp,
            'discount_percent' => $discountPercent,
        ];
    }
}

$favicon = get_storefront_dynamic_favicon_url($brand, $pageTitle);
$fontSize = (isset($brand['font_size']) && in_array($brand['font_size'], ['small', 'medium', 'large'], true)) ? $brand['font_size'] : 'medium';
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

        /* Storefront Modern Footer (Matching Design) */
        .ms-footer {
            width: 100%;
            background-color: <?= e($brand['footer_bg_color'] ?: '#ea580c') ?>;
            color: <?= e($brand['footer_text_color'] ?: '#ffffff') ?>;
            padding: 56px 24px 32px;
            margin-top: 48px;
            box-sizing: border-box;
            position: relative;
        }
        .ms-footer-wrap {
            max-width: 1200px;
            margin: 0 auto;
        }
        .ms-footer-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.9fr 1.25fr;
            gap: 48px 40px;
            margin-bottom: 44px;
        }
        .ms-footer-col-title {
            font-size: 16px;
            font-weight: 700;
            color: inherit;
            margin: 0 0 18px 0;
            letter-spacing: -0.01em;
        }
        .ms-footer-company-name {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.45;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-bottom: 14px;
            color: inherit;
        }
        .ms-footer-company-addr {
            font-size: 13.5px;
            line-height: 1.6;
            opacity: 0.92;
            margin-bottom: 14px;
            max-width: 340px;
            color: inherit;
        }
        .ms-footer-company-gst {
            font-size: 13px;
            font-weight: 700;
            opacity: 0.95;
            letter-spacing: 0.02em;
            color: inherit;
        }
        .ms-footer-nav-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 11px;
        }
        .ms-footer-nav-item a {
            color: inherit;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            opacity: 0.9;
            transition: opacity 0.15s, transform 0.15s;
            display: inline-block;
        }
        .ms-footer-nav-item a:hover {
            opacity: 1;
            text-decoration: underline;
            transform: translateX(2px);
        }
        .ms-footer-news-desc {
            font-size: 13.5px;
            line-height: 1.5;
            opacity: 0.92;
            margin-bottom: 16px;
            color: inherit;
        }
        .ms-footer-news-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 360px;
        }
        .ms-footer-email-input {
            width: 100%;
            border: 1.5px solid rgba(255,255,255,0.7);
            background: rgba(255,255,255,0.12);
            color: #ffffff;
            border-radius: 6px;
            padding: 11px 14px;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.15s, background 0.15s;
        }
        .ms-footer-email-input:focus {
            border-color: #ffffff;
            background: rgba(255,255,255,0.22);
        }
        .ms-footer-email-input::placeholder {
            color: rgba(255,255,255,0.75);
        }
        .ms-footer-signup-btn {
            width: 100%;
            background: #ffffff;
            color: <?= e($brand['footer_bg_color'] ?: '#ea580c') ?>;
            font-size: 14.5px;
            font-weight: 700;
            border: none;
            border-radius: 6px;
            padding: 11px 20px;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s, opacity 0.15s;
            text-align: center;
        }
        .ms-footer-signup-btn:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .ms-footer-disclaimer-wrap {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-top: 6px;
            cursor: pointer;
        }
        .ms-footer-disclaimer-wrap input[type="checkbox"] {
            margin-top: 3px;
            width: 14px;
            height: 14px;
            accent-color: #ffffff;
            cursor: pointer;
            flex-shrink: 0;
        }
        .ms-footer-disclaimer-text {
            font-size: 11.5px;
            line-height: 1.45;
            opacity: 0.88;
            color: inherit;
        }
        .ms-footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.18);
            padding-top: 24px;
            text-align: center;
            font-size: 12.5px;
            opacity: 0.88;
            font-weight: 500;
            letter-spacing: 0.01em;
            color: inherit;
        }

        /* Floating WhatsApp Icon Widget */
        .ms-wa-floating-btn {
            position: fixed;
            bottom: 28px;
            right: 28px;
            z-index: 999;
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: #25D366;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 18px rgba(37, 211, 102, 0.45);
            text-decoration: none;
            transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s ease;
        }
        .ms-wa-floating-btn:hover {
            transform: scale(1.08) translateY(-2px);
            box-shadow: 0 8px 24px rgba(37, 211, 102, 0.6);
            color: #ffffff;
        }
        .ms-wa-floating-btn svg {
            width: 30px;
            height: 30px;
            fill: #ffffff;
        }
        .ms-wa-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 12px;
            height: 12px;
            background: #22c55e;
            border: 2px solid #ffffff;
            border-radius: 50%;
        }

        @media (max-width: 900px) {
            .ms-footer-grid {
                grid-template-columns: 1fr 1fr;
            }
            .ms-footer-grid > :nth-child(3) {
                grid-column: 1 / -1;
            }
        }
        @media (max-width: 600px) {
            .ms-footer {
                padding: 40px 20px 28px;
            }
            .ms-footer-grid {
                grid-template-columns: 1fr;
                gap: 32px;
            }
            .ms-footer-news-form {
                max-width: 100%;
            }
            .ms-wa-floating-btn {
                bottom: 82px;
                right: 18px;
                width: 48px;
                height: 48px;
            }
            .ms-wa-floating-btn svg {
                width: 26px;
                height: 26px;
            }
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

        /* 3-Column Product Grid & Enlarged Image Architecture */
        .ms-item-grid {
            display: grid !important;
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 24px !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        @media (max-width: 992px) {
            .ms-item-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 16px !important;
            }
        }
        @media (max-width: 640px) {
            .ms-item-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 10px !important;
            }
        }

        .ms-product-card {
            border-radius: 14px !important;
        }
        .ms-product-img-wrap {
            position: relative !important;
            overflow: hidden !important;
            user-select: none !important;
            height: 380px !important;
            padding: 14px !important;
            box-sizing: border-box !important;
        }
        @media (max-width: 1280px) {
            .ms-product-img-wrap {
                height: 340px !important;
            }
        }
        @media (max-width: 992px) {
            .ms-product-img-wrap {
                height: 290px !important;
                padding: 10px !important;
            }
        }
        @media (max-width: 640px) {
            .ms-product-img-wrap {
                height: 200px !important;
                padding: 6px !important;
            }
        }
        .ms-product-img-wrap img {
            width: 100% !important;
            height: 100% !important;
            object-fit: contain !important;
            object-position: center !important;
        }
        .ms-card-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.94);
            color: #0f172a;
            border: 1px solid rgba(203, 213, 225, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            z-index: 5;
            opacity: 0.88;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.14);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            padding: 0 0 2px 0;
        }
        .ms-card-arrow:hover {
            opacity: 1;
            background: #ffffff;
            transform: translateY(-50%) scale(1.12);
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.22);
        }
        .ms-card-arrow-prev { left: 6px; }
        .ms-card-arrow-next { right: 6px; }
        .ms-card-dots {
            position: absolute;
            bottom: 6px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 4px;
            z-index: 4;
            pointer-events: none;
        }
        .ms-card-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: rgba(148, 163, 184, 0.6);
            transition: all 0.2s ease;
        }
        .ms-card-dot.is-active {
            background: #0f172a;
            width: 13px;
            border-radius: 999px;
        }
        .ms-product-attr {
            font-size: 12.5px;
            font-weight: 400;
            color: #64748b;
            text-transform: none;
            line-height: 1.4;
            margin: 4px 0 6px;
            letter-spacing: normal;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
        }
        .ms-product-desc {
            font-size: 12px;
            color: #64748b;
            line-height: 1.35;
            margin: 4px 0 6px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
        }
        .ms-card-add-btn {
            width: 100%;
            margin-top: 10px;
            padding: 11px 14px;
            min-height: 42px;
            background: var(--ms-header, #083d30);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            box-sizing: border-box;
        }
        .ms-card-add-btn:hover {
            opacity: 0.92;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.12);
            color: #ffffff;
        }
        .ms-card-add-btn.is-disabled, .ms-card-add-btn:disabled {
            background: #f1f5f9 !important;
            color: #94a3b8 !important;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        /* PDP Gallery Multi-Image Carousel & Thumbnails */
        .ms-pdp-gallery-card {
            background: #ffffff;
            border: 1px solid #f1f5f9;
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding: 16px;
        }
        .ms-pdp-main-wrap {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 320px;
            max-height: 440px;
            width: 100%;
        }
        .ms-pdp-main-wrap img {
            max-width: 100%;
            max-height: 420px;
            object-fit: contain;
        }
        .ms-pdp-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.95);
            color: #0f172a;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            cursor: pointer;
            z-index: 5;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
            transition: all 0.2s ease;
            padding: 0 0 2px 0;
            user-select: none;
        }
        .ms-pdp-arrow:hover {
            background: #ffffff;
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.22);
        }
        .ms-pdp-arrow-prev { left: 8px; }
        .ms-pdp-arrow-next { right: 8px; }
        .ms-pdp-thumbs {
            display: flex;
            gap: 8px;
            margin-top: 14px;
            overflow-x: auto;
            padding: 4px 2px;
            justify-content: center;
        }
        .ms-pdp-thumb {
            width: 58px;
            height: 58px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            overflow: hidden;
            cursor: pointer;
            flex-shrink: 0;
            padding: 3px;
            background: #fff;
            transition: all 0.2s ease;
        }
        .ms-pdp-thumb img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .ms-pdp-thumb.is-active, .ms-pdp-thumb:hover {
            border-color: #0f172a;
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
                        <input class="ms-search" id="msSearchInput" type="search" name="q" value="<?= e(trim((string) ($_GET['q'] ?? ''))) ?>" style="width:100%;border:0;border-radius:4px;padding:10px 18px 10px 42px;font:inherit;font-size:14px;background:#ffffff;color:#0f172a;outline:none;position:relative;z-index:1;" autocomplete="off">
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
                $trendingProducts = get_storefront_trending_products($bid, 20);
                if (!empty($brand['hide_out_of_stock'])) {
                    $trendingProducts = array_values(array_filter($trendingProducts, static fn($p) => (int) ($p['stock_quantity'] ?? 0) > 0));
                }
                $categories = storefront_visible_categories($categories, $brand);
                $homeSections = storefront_home_sections($brand);
                $catCols = max(2, min(4, (int) ($brand['category_columns'] ?? 2)));
                ?>

                <?php foreach ($homeSections as $homeSec): ?>
                <?php if ($homeSec === 'banner' && !empty($brand['show_banner']) && $q === '' && !$catId): ?>
                    <?php if (!empty($brand['show_banner_section_name'])): ?>
                        <div id="msBannersSectionName" class="ms-sec-title"><?= e($brand['banner_section_name'] ?: 'Banners') ?></div>
                    <?php endif; ?>
                    <div class="ms-banners-carousel-wrap" id="msBannersWrapper">
                        <button type="button" class="ms-banner-arrow ms-banner-prev" id="msBannerPrev" onclick="sfBannerMove(-1)" aria-label="Previous">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                        </button>

                        <div class="ms-banners-grid" id="msBanners">
                            <!-- Banner 1: Merchant Custom / Default Banner -->
                            <?php
                            $b1Bg = !empty($brand['banner_bg_color']) ? $brand['banner_bg_color'] : '';
                            $b1Txt = !empty($brand['banner_text_color']) ? $brand['banner_text_color'] : '';
                            $b1Style = '';
                            if ($b1Bg) $b1Style .= 'background:' . e($b1Bg) . ';';
                            if ($b1Txt) $b1Style .= 'color:' . e($b1Txt) . ';';
                            ?>
                            <div class="ms-banner-card ms-banner-1" style="<?= $b1Style ?>" data-slide="0">
                                <div class="ms-banner-info" style="<?= $b1Txt ? 'color:' . e($b1Txt) . ';' : '' ?>">
                                    <?php
                                    $bTitle = trim((string)($brand['banner_title'] ?? ''));
                                    $bSub = trim((string)($brand['banner_subtitle'] ?? ''));
                                    ?>
                                    <?php if ($bTitle !== ''): ?>
                                        <div class="ms-banner-title"><?= nl2br(e($bTitle)) ?></div>
                                    <?php else: ?>
                                        <div class="ms-banner-title">We're online now!</div>
                                    <?php endif; ?>
                                    <?php if ($bSub !== ''): ?>
                                        <div class="ms-banner-sub"><?= nl2br(e($bSub)) ?></div>
                                    <?php else: ?>
                                        <div class="ms-banner-sub">Stay at home and<br>shop online.</div>
                                    <?php endif; ?>
                                </div>
                                <div class="ms-banner-art">
                                    <?php if (!empty($brand['banner_image']) && store_logo_file_exists((string) $brand['banner_image'])): ?>
                                        <img src="<?= asset($brand['banner_image']) ?>" alt="Banner" style="max-width:100%;max-height:100%;object-fit:contain;">
                                    <?php else: ?>
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
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Banner 2: Start Shopping -->
                            <?php
                            $b2Bg = !empty($brand['banner_2_bg_color']) ? $brand['banner_2_bg_color'] : '';
                            $b2Txt = !empty($brand['banner_2_text_color']) ? $brand['banner_2_text_color'] : '';
                            $b2Style = '';
                            if ($b2Bg) $b2Style .= 'background:' . e($b2Bg) . ';';
                            if ($b2Txt) $b2Style .= 'color:' . e($b2Txt) . ';';
                            ?>
                            <div class="ms-banner-card ms-banner-2" style="<?= $b2Style ?>" data-slide="1">
                                <div class="ms-banner-info" style="<?= $b2Txt ? 'color:' . e($b2Txt) . ';' : '' ?>">
                                    <?php
                                    $b2Tag = trim((string)($brand['banner_2_tag'] ?? ''));
                                    $b2Title = trim((string)($brand['banner_2_title'] ?? ''));
                                    $b2Sub = trim((string)($brand['banner_2_subtitle'] ?? ''));
                                    ?>
                                    <div class="ms-banner-tag" style="<?= $b2Txt ? 'color:' . e($b2Txt) . ';' : '' ?>"><?= e($b2Tag !== '' ? $b2Tag : 'Best deal,') ?></div>
                                    <div class="ms-banner-title"><?= nl2br(e($b2Title !== '' ? $b2Title : 'Start Shopping')) ?></div>
                                    <div class="ms-banner-sub"><?= nl2br(e($b2Sub !== '' ? $b2Sub : 'and discover the best deals!')) ?></div>
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
                            <?php
                            $b3Bg = !empty($brand['banner_3_bg_color']) ? $brand['banner_3_bg_color'] : '';
                            $b3Txt = !empty($brand['banner_3_text_color']) ? $brand['banner_3_text_color'] : '';
                            $b3Style = '';
                            if ($b3Bg) $b3Style .= 'background:' . e($b3Bg) . ';';
                            if ($b3Txt) $b3Style .= 'color:' . e($b3Txt) . ';';
                            ?>
                            <div class="ms-banner-card ms-banner-3" style="<?= $b3Style ?>" data-slide="2">
                                <div class="ms-banner-info" style="<?= $b3Txt ? 'color:' . e($b3Txt) . ';' : '' ?>">
                                    <?php
                                    $b3Tag = trim((string)($brand['banner_3_tag'] ?? ''));
                                    $b3Title = trim((string)($brand['banner_3_title'] ?? ''));
                                    $b3Sub = trim((string)($brand['banner_3_subtitle'] ?? ''));
                                    ?>
                                    <div class="ms-banner-tag" style="font-size:16px;<?= $b3Txt ? 'color:' . e($b3Txt) . ';' : '' ?>"><?= e($b3Tag !== '' ? $b3Tag : 'Order') ?></div>
                                    <div class="ms-banner-title" style="font-style:italic;"><?= nl2br(e($b3Title !== '' ? $b3Title : 'with Ease')) ?></div>
                                    <div class="ms-banner-sub" style="margin-top:6px;font-weight:600;"><?= nl2br(e($b3Sub !== '' ? $b3Sub : 'with Speed')) ?></div>
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

                            <!-- Banner 4: Special Offer / Super Savings -->
                            <?php
                            $b4Bg = !empty($brand['banner_4_bg_color']) ? $brand['banner_4_bg_color'] : '';
                            $b4Txt = !empty($brand['banner_4_text_color']) ? $brand['banner_4_text_color'] : '';
                            $b4Style = '';
                            if ($b4Bg) $b4Style .= 'background:' . e($b4Bg) . ';';
                            if ($b4Txt) $b4Style .= 'color:' . e($b4Txt) . ';';
                            ?>
                            <div class="ms-banner-card ms-banner-4" style="<?= $b4Style ?>" data-slide="3">
                                <div class="ms-banner-info" style="<?= $b4Txt ? 'color:' . e($b4Txt) . ';' : '' ?>">
                                    <?php
                                    $b4Tag = trim((string)($brand['banner_4_tag'] ?? ''));
                                    $b4Title = trim((string)($brand['banner_4_title'] ?? ''));
                                    $b4Sub = trim((string)($brand['banner_4_subtitle'] ?? ''));
                                    ?>
                                    <div class="ms-banner-tag" style="<?= $b4Txt ? 'color:' . e($b4Txt) . ';' : '' ?>"><?= e($b4Tag !== '' ? $b4Tag : 'Special Offer,') ?></div>
                                    <div class="ms-banner-title"><?= nl2br(e($b4Title !== '' ? $b4Title : 'Super Savings')) ?></div>
                                    <div class="ms-banner-sub"><?= nl2br(e($b4Sub !== '' ? $b4Sub : 'Get exclusive discounts today!')) ?></div>
                                </div>
                                <div class="ms-banner-art">
                                    <svg viewBox="0 0 160 135" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <circle cx="20" cy="25" r="1.5" fill="#ffffff" opacity="0.8"/>
                                        <circle cx="140" cy="18" r="1.5" fill="#ffffff" opacity="0.7"/>
                                        <circle cx="25" cy="115" r="1.5" fill="#ffffff" opacity="0.7"/>
                                        <circle cx="148" cy="100" r="1.5" fill="#ffffff" opacity="0.6"/>

                                        <ellipse cx="85" cy="118" rx="55" ry="12" fill="#c2410c" opacity="0.4"/>

                                        <g transform="translate(55, 38)">
                                            <rect x="0" y="20" width="60" height="56" rx="6" fill="#f59e0b"/>
                                            <rect x="-4" y="10" width="68" height="15" rx="4" fill="#fbbf24"/>
                                            <rect x="25" y="10" width="10" height="66" fill="#ef4444"/>
                                            <rect x="-4" y="15" width="68" height="6" fill="#ef4444"/>
                                            
                                            <path d="M30 10 C20 -6, 8 2, 26 10 Z" fill="#ef4444"/>
                                            <path d="M30 10 C40 -6, 52 2, 34 10 Z" fill="#ef4444"/>
                                            <circle cx="30" cy="10" r="4.5" fill="#b91c1c"/>
                                        </g>

                                        <g transform="translate(18, 26) rotate(-15)">
                                            <polygon points="18,0 23,11 35,13 26,22 28,34 18,28 8,34 10,22 1,13 13,11" fill="#fef08a"/>
                                            <text x="18" y="21" fill="#b45309" font-size="9" font-weight="900" text-anchor="middle" font-family="sans-serif">%</text>
                                        </g>

                                        <g transform="translate(112, 28) rotate(12)">
                                            <path d="M0 0 L18 0 L28 10 L10 10 Z" fill="#ef4444"/>
                                            <rect x="0" y="0" width="22" height="16" rx="3" fill="#f43f5e"/>
                                            <circle cx="6" cy="8" r="2.5" fill="#ffffff"/>
                                            <text x="14" y="12" fill="#ffffff" font-size="7.5" font-weight="800" font-family="sans-serif">SALE</text>
                                        </g>
                                        
                                        <path d="M35 85 L37 91 L43 93 L37 95 L35 101 L33 95 L27 93 L33 91 Z" fill="#ffffff" opacity="0.85"/>
                                        <path d="M125 75 L126.5 80 L131.5 81.5 L126.5 83 L125 88 L123.5 83 L118.5 81.5 L123.5 80 Z" fill="#ffffff" opacity="0.85"/>
                                    </svg>
                                </div>
                            </div>

                            <!-- Banner 5: Fresh Deals / Top Quality Picks -->
                            <?php
                            $b5Bg = !empty($brand['banner_5_bg_color']) ? $brand['banner_5_bg_color'] : '';
                            $b5Txt = !empty($brand['banner_5_text_color']) ? $brand['banner_5_text_color'] : '';
                            $b5Style = '';
                            if ($b5Bg) $b5Style .= 'background:' . e($b5Bg) . ';';
                            if ($b5Txt) $b5Style .= 'color:' . e($b5Txt) . ';';
                            ?>
                            <div class="ms-banner-card ms-banner-5" style="<?= $b5Style ?>" data-slide="4">
                                <div class="ms-banner-info" style="<?= $b5Txt ? 'color:' . e($b5Txt) . ';' : '' ?>">
                                    <?php
                                    $b5Tag = trim((string)($brand['banner_5_tag'] ?? ''));
                                    $b5Title = trim((string)($brand['banner_5_title'] ?? ''));
                                    $b5Sub = trim((string)($brand['banner_5_subtitle'] ?? ''));
                                    ?>
                                    <div class="ms-banner-tag" style="<?= $b5Txt ? 'color:' . e($b5Txt) . ';' : '' ?>"><?= e($b5Tag !== '' ? $b5Tag : 'Fresh Deals,') ?></div>
                                    <div class="ms-banner-title"><?= nl2br(e($b5Title !== '' ? $b5Title : 'Top Quality Picks')) ?></div>
                                    <div class="ms-banner-sub"><?= nl2br(e($b5Sub !== '' ? $b5Sub : 'Handpicked best products for you.')) ?></div>
                                </div>
                                <div class="ms-banner-art">
                                    <svg viewBox="0 0 160 135" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <circle cx="24" cy="22" r="1.5" fill="#ffffff" opacity="0.8"/>
                                        <circle cx="142" cy="20" r="1.5" fill="#ffffff" opacity="0.7"/>
                                        <circle cx="28" cy="112" r="1.5" fill="#ffffff" opacity="0.7"/>
                                        <circle cx="150" cy="95" r="1.5" fill="#ffffff" opacity="0.6"/>

                                        <ellipse cx="88" cy="118" rx="55" ry="12" fill="#0e7490" opacity="0.4"/>

                                        <g transform="translate(48, 20)">
                                            <path d="M35 0 L68 12 C68 45, 35 68, 35 68 C35 68, 2 45, 2 12 Z" fill="#0891b2"/>
                                            <path d="M35 4 L64 15 C64 42, 35 63, 35 63 C35 63, 6 42, 6 15 Z" fill="#06b6d4"/>
                                            
                                            <polygon points="35,16 40,27 52,29 43,38 45,50 35,44 25,50 27,38 18,29 30,27" fill="#facc15"/>
                                            <polygon points="35,21 38,29 47,30 40,37 42,46 35,41 28,46 30,37 23,30 32,29" fill="#fef08a"/>
                                        </g>

                                        <g transform="translate(98, 54)">
                                            <circle cx="15" cy="15" r="15" fill="#10b981"/>
                                            <circle cx="15" cy="15" r="12" fill="#34d399"/>
                                            <polyline points="9 15 13 19 21 11" stroke="#ffffff" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                        </g>

                                        <g transform="translate(20, 52)">
                                            <circle cx="12" cy="12" r="12" fill="#ffffff" opacity="0.2"/>
                                            <path d="M6 14 Q12 6 18 14" stroke="#ffffff" stroke-width="2" fill="none" stroke-linecap="round"/>
                                            <circle cx="12" cy="12" r="4" fill="#ffffff"/>
                                        </g>
                                    </svg>
                                </div>
                            </div>

                            <!-- Banner 6: Fast & Reliable / Express Delivery -->
                            <?php
                            $b6Bg = !empty($brand['banner_6_bg_color']) ? $brand['banner_6_bg_color'] : '';
                            $b6Txt = !empty($brand['banner_6_text_color']) ? $brand['banner_6_text_color'] : '';
                            $b6Style = '';
                            if ($b6Bg) $b6Style .= 'background:' . e($b6Bg) . ';';
                            if ($b6Txt) $b6Style .= 'color:' . e($b6Txt) . ';';
                            ?>
                            <div class="ms-banner-card ms-banner-6" style="<?= $b6Style ?>" data-slide="5">
                                <div class="ms-banner-info" style="<?= $b6Txt ? 'color:' . e($b6Txt) . ';' : '' ?>">
                                    <?php
                                    $b6Tag = trim((string)($brand['banner_6_tag'] ?? ''));
                                    $b6Title = trim((string)($brand['banner_6_title'] ?? ''));
                                    $b6Sub = trim((string)($brand['banner_6_subtitle'] ?? ''));
                                    ?>
                                    <div class="ms-banner-tag" style="<?= $b6Txt ? 'color:' . e($b6Txt) . ';' : '' ?>"><?= e($b6Tag !== '' ? $b6Tag : 'Fast & Reliable,') ?></div>
                                    <div class="ms-banner-title"><?= nl2br(e($b6Title !== '' ? $b6Title : 'Express Delivery')) ?></div>
                                    <div class="ms-banner-sub"><?= nl2br(e($b6Sub !== '' ? $b6Sub : 'Direct to your doorstep quickly.')) ?></div>
                                </div>
                                <div class="ms-banner-art">
                                    <svg viewBox="0 0 160 135" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <circle cx="20" cy="20" r="1.5" fill="#ffffff" opacity="0.8"/>
                                        <circle cx="140" cy="22" r="1.5" fill="#ffffff" opacity="0.7"/>
                                        <circle cx="25" cy="115" r="1.5" fill="#ffffff" opacity="0.7"/>
                                        <circle cx="152" cy="100" r="1.5" fill="#ffffff" opacity="0.6"/>

                                        <ellipse cx="80" cy="120" rx="60" ry="12" fill="#581c87" opacity="0.4"/>

                                        <g transform="translate(30, 42)">
                                            <rect x="0" y="10" width="58" height="42" rx="4" fill="#a855f7"/>
                                            <rect x="4" y="14" width="50" height="34" rx="2" fill="#9333ea"/>
                                            
                                            <polygon points="32,18 20,33 29,33 24,45 38,30 29,30" fill="#facc15"/>

                                            <path d="M58 22 L76 22 L86 36 L86 52 L58 52 Z" fill="#c084fc"/>
                                            <path d="M62 26 L74 26 L80 36 L62 36 Z" fill="#e9d5ff"/>
                                            <rect x="80" y="42" width="6" height="5" rx="1.5" fill="#facc15"/>

                                            <circle cx="18" cy="54" r="10" fill="#1e1b4b"/>
                                            <circle cx="18" cy="54" r="4" fill="#cbd5e1"/>
                                            <circle cx="70" cy="54" r="10" fill="#1e1b4b"/>
                                            <circle cx="70" cy="54" r="4" fill="#cbd5e1"/>
                                        </g>

                                        <g transform="translate(8, 56)">
                                            <line x1="0" y1="0" x2="16" y2="0" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" opacity="0.9"/>
                                            <line x1="6" y1="8" x2="18" y2="8" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" opacity="0.8"/>
                                            <line x1="2" y1="16" x2="14" y2="16" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity="0.7"/>
                                        </g>

                                        <g transform="translate(122, 24)">
                                            <path d="M10 0 C4.5 0 0 4.5 0 10 C0 17 10 24 10 24 C10 24 20 17 20 10 C20 4.5 15.5 0 10 0 Z" fill="#ef4444"/>
                                            <circle cx="10" cy="9" r="4" fill="#ffffff"/>
                                        </g>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <button type="button" class="ms-banner-arrow ms-banner-next" id="msBannerNext" onclick="sfBannerMove(1)" aria-label="Next">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>

                        <div class="ms-banner-dots" id="msBannerDots">
                            <span class="ms-banner-dot is-active" onclick="sfBannerGoTo(0)"></span>
                            <span class="ms-banner-dot" onclick="sfBannerGoTo(1)"></span>
                            <span class="ms-banner-dot" onclick="sfBannerGoTo(2)"></span>
                            <span class="ms-banner-dot" onclick="sfBannerGoTo(3)"></span>
                            <span class="ms-banner-dot" onclick="sfBannerGoTo(4)"></span>
                            <span class="ms-banner-dot" onclick="sfBannerGoTo(5)"></span>
                        </div>
                    </div>

                <?php elseif ($homeSec === 'category' && $brand['show_categories'] && $categories && $q === ''): ?>
                    <div id="msCategories" class="ms-sec-title"><?= e($brand['category_section_name'] ?: 'All Categories') ?></div>
                    <div class="ms-cat-grid">
                        <?php foreach ($categories as $cat):
                            $catUrl = public_store_url($storeBiz, 'home', ['category_id' => (int) $cat['id']]);
                            $thumb = !empty($cat['image_path']) ? (string) $cat['image_path'] : '';
                            $isCatActive = ((int)$catId === (int)$cat['id']);
                            ?>
                            <a class="ms-cat-card<?= $isCatActive ? ' ms-cat-active' : '' ?>" href="<?= e($catUrl) ?>">
                                <div class="ms-cat-img-box">
                                    <?php if ($thumb): ?>
                                        <img src="<?= asset($thumb) ?>" alt="<?= e((string) $cat['name']) ?>">
                                    <?php else: ?>
                                        <svg width="48" height="40" viewBox="0 0 24 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <circle cx="12" cy="5" r="2.2" fill="#cbd5e1"/>
                                            <path d="M3.2 16.5 L7.8 9.5 C8.3 8.8 9.3 8.8 9.8 9.5 L12.5 13.5 L14.2 11 C14.7 10.3 15.7 10.3 16.2 11 L20.8 16.5 C21.4 17.3 20.8 18.5 19.8 18.5 L4.2 18.5 C3.2 18.5 2.6 17.3 3.2 16.5 Z" fill="#cbd5e1"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="ms-cat-title"><?= e((string) $cat['name']) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($homeSec === 'trending' && !empty($brand['show_trending_items']) && $trendingProducts && $q === '' && !$catId): ?>
                    <?php
                    $trendBg = !empty($brand['trending_bg_color']) && $brand['trending_bg_color'] !== '#ffffff' ? $brand['trending_bg_color'] : '';
                    $trendTxt = !empty($brand['trending_text_color']) && $brand['trending_text_color'] !== '#000000' ? $brand['trending_text_color'] : '';
                    $trendStyle = '';
                    if ($trendBg) $trendStyle .= 'background:' . e($trendBg) . ';padding:14px 10px;border-radius:12px;margin-bottom:20px;';
                    if ($trendTxt) $trendStyle .= 'color:' . e($trendTxt) . ';';
                    ?>
                    <div class="ms-trending-section-wrap" style="<?= $trendStyle ?>">
                        <div class="ms-sec-title" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;<?= $trendTxt ? 'color:' . e($trendTxt) . ';' : '' ?>">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span><?= e($brand['trending_section_name'] ?: 'Top Trending Items') ?></span>
                                <span style="background:#fee2e2;color:#dc2626;font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;text-transform:uppercase;letter-spacing:0.5px;">🔥 Hot</span>
                            </div>
                        </div>
                        <div class="ms-item-grid">
                            <?php foreach ($trendingProducts as $p):
                                $pImages = storefront_get_all_product_images($p, $bid);
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
                                    <div class="ms-product-img-wrap">
                                        <a class="ms-product-img-link" href="<?= e($pUrl) ?>" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;text-decoration:none;position:relative;">
                                            <?php if ($discountPct > 0): ?>
                                                <span class="ms-card-discount-badge"><?= $discountPct ?>%<br>Off</span>
                                            <?php endif; ?>

                                            <?php if ($pImages): ?>
                                                <div class="ms-card-img-track" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                                                    <?php foreach ($pImages as $idx => $imgSrc): ?>
                                                        <img src="<?= e($imgSrc) ?>" alt="<?= e((string) $p['name']) ?>" class="<?= $idx === 0 ? 'is-active' : '' ?>" data-idx="<?= $idx ?>" style="<?= $idx === 0 ? '' : 'display:none;' ?>">
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="3" y="3" width="18" height="18" rx="3" ry="3"/>
                                                    <circle cx="8.5" cy="8.5" r="1.5" fill="#cbd5e1"/>
                                                    <polyline points="21 15 16 10 5 21" fill="none" stroke="#cbd5e1"/>
                                                </svg>
                                            <?php endif; ?>
                                        </a>

                                        <?php if (count($pImages) > 1): ?>
                                            <button type="button" class="ms-card-arrow ms-card-arrow-prev" onclick="event.preventDefault(); event.stopPropagation(); sfCardSlide(this, -1);" aria-label="Previous Image">‹</button>
                                            <button type="button" class="ms-card-arrow ms-card-arrow-next" onclick="event.preventDefault(); event.stopPropagation(); sfCardSlide(this, 1);" aria-label="Next Image">›</button>
                                            <div class="ms-card-dots">
                                                <?php foreach ($pImages as $dIdx => $d): ?>
                                                    <span class="ms-card-dot<?= $dIdx === 0 ? ' is-active' : '' ?>"></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ms-product-body">
                                        <div>
                                            <a class="ms-product-name" href="<?= e($pUrl) ?>"><?= e((string) $p['name']) ?></a>
                                            <?php 
                                            $pDesc = trim((string)($p['sales_description'] ?? $p['description'] ?? ''));
                                            $displayAttr = $attrText !== '' ? $attrText : $pDesc;
                                            if ($displayAttr !== ''): ?>
                                                <div class="ms-product-attr"><?= e($displayAttr) ?></div>
                                            <?php endif; ?>
                                            <?php if ($varCount > 0): ?>
                                                <a href="<?= e($pUrl) ?>" class="ms-product-variants-link">+<?= max(1, $varCount - 1) ?> variants</a>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="ms-product-price-row">
                                                <?php if (empty($brand['hide_product_price'])): ?>
                                                    <span class="ms-product-price"><?= sf_money($currency, (float) $sellingPrice) ?></span>
                                                    <?php if ($mrp > $sellingPrice && $mrp > 0): ?>
                                                        <span class="ms-product-mrp"><?= sf_money($currency, (float) $mrp) ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!$inStock): ?>
                                                <button type="button" class="ms-card-add-btn is-disabled" disabled>Out of Stock</button>
                                            <?php elseif ($varCount > 0): ?>
                                                <a href="<?= e($pUrl) ?>" class="ms-card-add-btn">
                                                    <span>View Options</span>
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                                </a>
                                            <?php else: ?>
                                                <form method="post" class="ms-card-add-form" style="margin:0;" onsubmit="handleAjaxAddToCart(event, this);">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="add_to_cart">
                                                    <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                                    <input type="hidden" name="qty" value="1">
                                                    <input type="hidden" name="redirect_page" value="home">
                                                    <button type="submit" class="ms-card-add-btn">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                                                        <span>Add to Cart</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php elseif ($homeSec === 'item' && $brand['show_items']): ?>
                    <div class="ms-sec-title"><?= $catId ? 'Category items' : e($brand['item_section_name'] ?: 'All Items') ?></div>
                    <?php if (!$products): ?>
                        <div class="ms-empty">No items to show.</div>
                    <?php else: ?>
                        <div class="ms-item-grid">
                            <?php foreach ($products as $p):
                                $pImages = storefront_get_all_product_images($p, $bid);
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
                                    <div class="ms-product-img-wrap">
                                        <a class="ms-product-img-link" href="<?= e($pUrl) ?>" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;text-decoration:none;position:relative;">
                                            <?php if ($discountPct > 0): ?>
                                                <span class="ms-card-discount-badge"><?= $discountPct ?>%<br>Off</span>
                                            <?php endif; ?>

                                            <?php if ($pImages): ?>
                                                <div class="ms-card-img-track" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                                                    <?php foreach ($pImages as $idx => $imgSrc): ?>
                                                        <img src="<?= e($imgSrc) ?>" alt="<?= e((string) $p['name']) ?>" class="<?= $idx === 0 ? 'is-active' : '' ?>" data-idx="<?= $idx ?>" style="<?= $idx === 0 ? '' : 'display:none;' ?>">
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="3" y="3" width="18" height="18" rx="3" ry="3"/>
                                                    <circle cx="8.5" cy="8.5" r="1.5" fill="#cbd5e1"/>
                                                    <polyline points="21 15 16 10 5 21" fill="none" stroke="#cbd5e1"/>
                                                </svg>
                                            <?php endif; ?>
                                        </a>

                                        <?php if (count($pImages) > 1): ?>
                                            <button type="button" class="ms-card-arrow ms-card-arrow-prev" onclick="event.preventDefault(); event.stopPropagation(); sfCardSlide(this, -1);" aria-label="Previous Image">‹</button>
                                            <button type="button" class="ms-card-arrow ms-card-arrow-next" onclick="event.preventDefault(); event.stopPropagation(); sfCardSlide(this, 1);" aria-label="Next Image">›</button>
                                            <div class="ms-card-dots">
                                                <?php foreach ($pImages as $dIdx => $d): ?>
                                                    <span class="ms-card-dot<?= $dIdx === 0 ? ' is-active' : '' ?>"></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ms-product-body">
                                        <div>
                                            <a class="ms-product-name" href="<?= e($pUrl) ?>"><?= e((string) $p['name']) ?></a>
                                            <?php 
                                            $pDesc = trim((string)($p['sales_description'] ?? $p['description'] ?? ''));
                                            $displayAttr = $attrText !== '' ? $attrText : $pDesc;
                                            if ($displayAttr !== ''): ?>
                                                <div class="ms-product-attr"><?= e($displayAttr) ?></div>
                                            <?php endif; ?>
                                            <?php if ($varCount > 0): ?>
                                                <a href="<?= e($pUrl) ?>" class="ms-product-variants-link">+<?= max(1, $varCount - 1) ?> variants</a>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="ms-product-price-row">
                                                <?php if (empty($brand['hide_product_price'])): ?>
                                                    <span class="ms-product-price"><?= sf_money($currency, (float) $sellingPrice) ?></span>
                                                    <?php if ($mrp > $sellingPrice && $mrp > 0): ?>
                                                        <span class="ms-product-mrp"><?= sf_money($currency, (float) $mrp) ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!$inStock): ?>
                                                <button type="button" class="ms-card-add-btn is-disabled" disabled>Out of Stock</button>
                                            <?php elseif ($varCount > 0): ?>
                                                <a href="<?= e($pUrl) ?>" class="ms-card-add-btn">
                                                    <span>View Options</span>
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                                </a>
                                            <?php else: ?>
                                                <form method="post" class="ms-card-add-form" style="margin:0;" onsubmit="handleAjaxAddToCart(event, this);">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="add_to_cart">
                                                    <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                                    <input type="hidden" name="qty" value="1">
                                                    <input type="hidden" name="redirect_page" value="home">
                                                    <button type="submit" class="ms-card-add-btn">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                                                        <span>Add to Cart</span>
                                                    </button>
                                                </form>
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
                    $pdpImages = storefront_get_all_product_images($product, $bid);
                    ?>
                    <div class="ms-pdp-wrap">
                        <a href="<?= e($homeUrl) ?>" class="ms-legal-back" style="display:inline-flex;margin-bottom:16px;">← Back to Store</a>

                        <!-- Top 2-Column Product Detail Card -->
                        <div class="ms-pdp-top-card">
                            <!-- Left: High-Res Image Gallery -->
                            <div class="ms-pdp-gallery-card">
                                <div class="ms-pdp-main-wrap">
                                    <?php if ($pdpImages): ?>
                                        <img src="<?= e($pdpImages[0]) ?>" alt="<?= e((string) $product['name']) ?>" id="pdpMainImg" data-idx="0">
                                        <?php if (count($pdpImages) > 1): ?>
                                            <button type="button" class="ms-pdp-arrow ms-pdp-arrow-prev" onclick="sfPdpSlide(-1)" aria-label="Previous image">‹</button>
                                            <button type="button" class="ms-pdp-arrow ms-pdp-arrow-next" onclick="sfPdpSlide(1)" aria-label="Next image">›</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <svg width="84" height="84" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="3" width="18" height="18" rx="3" ry="3"/>
                                            <circle cx="8.5" cy="8.5" r="1.5" fill="#cbd5e1"/>
                                            <polyline points="21 15 16 10 5 21" fill="none" stroke="#cbd5e1"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <?php if (count($pdpImages) > 1): ?>
                                    <div class="ms-pdp-thumbs">
                                        <?php foreach ($pdpImages as $tIdx => $tUrl): ?>
                                            <div class="ms-pdp-thumb<?= $tIdx === 0 ? ' is-active' : '' ?>" onclick="sfPdpSetImage(<?= $tIdx ?>)">
                                                <img src="<?= e($tUrl) ?>" alt="Thumbnail <?= $tIdx + 1 ?>">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
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

                                <form method="post" style="margin-top:auto;" onsubmit="handleAjaxAddToCart(event, this);">
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
                                            <span><?= $inStock ? 'Add to Cart' : 'Out of Stock' ?></span>
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
                <?php else: 
                    $savedLoc = $_SESSION['sf_delivery_location_' . $bid] ?? [];
                    $locAddress = trim((string)($savedLoc['formatted'] ?? $storeShopper['address'] ?? ''));
                    $locName = storefront_clean_person_name((string)($savedLoc['name'] ?? $storeShopper['name'] ?? ''));
                    $locPhone = trim((string)($savedLoc['phone'] ?? $storeShopper['phone'] ?? ''));
                    $locEmail = trim((string)($storeShopper['email'] ?? ''));
                    $hasDeliveryLoc = ($locAddress !== '');
                    ?>
                    <?php if (!$hasDeliveryLoc): ?>
                        <div class="ms-form-card" style="text-align:center;padding:40px 24px;max-width:520px;">
                            <div class="ms-loc-art-wrap" style="margin:0 auto 16px;display:flex;justify-content:center;">
                                <svg viewBox="0 0 200 200" width="150" height="150" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="100" cy="100" r="75" fill="#f8fafc"/>
                                    <g transform="translate(70, 36)">
                                        <path d="M30 0 C13.4 0 0 13.4 0 30 C0 55 30 84 30 84 C30 84 60 55 60 30 C60 13.4 46.6 0 30 0 Z" fill="#818cf8" opacity="0.15"/>
                                        <path d="M30 4 C15.6 4 4 15.6 4 30 C4 52 30 78 30 78 C30 78 56 52 56 30 C56 15.6 44.4 4 30 4 Z" fill="var(--ms-header, #083d30)"/>
                                        <circle cx="30" cy="28" r="14" fill="#ffffff"/>
                                        <circle cx="48" cy="12" r="10" fill="var(--ms-header, #083d30)"/>
                                        <path d="M48 7v10M43 12h10" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round"/>
                                    </g>
                                    <ellipse cx="100" cy="155" rx="30" ry="6" fill="#cbd5e1" opacity="0.6"/>
                                </svg>
                            </div>
                            <h1 style="font-size:22px;font-weight:800;color:#0f172a;margin:0 0 6px;">Add Delivery Location</h1>
                            <p style="font-size:14px;color:#64748b;margin:0 0 24px;">Let us know where we should send your orders</p>
                            <button type="button" class="ms-btn" style="max-width:280px;margin:0 auto;" onclick="openLocationDrawerFromCheckout()">Add Address</button>
                        </div>
                    <?php else: ?>
                        <div class="ms-form-card" style="max-width:580px;">
                            <h1 style="font-size:20px;font-weight:800;margin-bottom:18px;color:#0f172a;">Review & Place Order</h1>
                            <form method="post" id="msCheckoutForm" onsubmit="return handleCheckoutSubmit(event, this)">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="place_order">
                                <input type="hidden" name="name" value="<?= e($locName) ?>">
                                <input type="hidden" name="phone" value="<?= e($locPhone) ?>">
                                <input type="hidden" name="email" value="<?= e($locEmail) ?>">
                                <input type="hidden" name="address" value="<?= e($locAddress) ?>">

                                <!-- Delivery Address Card -->
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:20px;">
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                        <div style="display:flex;align-items:center;gap:8px;font-weight:700;font-size:13.5px;color:#0f172a;">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s7-5.4 7-11a7 7 0 10-14 0c0 5.6 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                            <span>Delivering to</span>
                                        </div>
                                        <button type="button" onclick="openLocationDrawerFromCheckout()" style="background:none;border:none;color:#2563eb;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:underline;padding:0;">Change Location</button>
                                    </div>
                                    <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:4px;"><?= e($locName) ?> <?= $locPhone ? ('· ' . e($locPhone)) : '' ?></div>
                                    <div style="font-size:13.5px;color:#475569;line-height:1.45;"><?= e($locAddress) ?></div>
                                </div>

                                <!-- Payment Method -->
                                <?php
                                $hasAnyOpt = false;
                                ?>
                                <label class="ms-label">Payment Method</label>
                                <select class="ms-select" name="payment_method" style="margin-bottom:18px;">
                                    <?php if (!empty($brand['enable_cod'])): $hasAnyOpt = true; ?>
                                        <option value="cod">Cash on Delivery (COD)</option>
                                    <?php endif; ?>
                                    <?php if (!empty($brand['enable_upi'])): $hasAnyOpt = true; ?>
                                        <option value="upi">UPI (Google Pay, PhonePe, Paytm, BHIM) <?= !empty($brand['upi_id']) ? ('[' . e($brand['upi_id']) . ']') : '' ?></option>
                                    <?php endif; ?>
                                    <?php if (!empty($brand['enable_card'])): $hasAnyOpt = true; ?>
                                        <option value="card">Credit / Debit Card</option>
                                    <?php endif; ?>
                                    <?php if (!empty($brand['enable_netbanking'])): $hasAnyOpt = true; ?>
                                        <option value="netbanking">Net Banking / Direct Bank Transfer</option>
                                    <?php endif; ?>
                                    <?php if (!empty($brand['enable_store_pickup_payment'])): $hasAnyOpt = true; ?>
                                        <option value="pickup">Pay at Store / Pickup</option>
                                    <?php endif; ?>
                                    <?php if (!$hasAnyOpt): ?>
                                        <option value="cod">Cash on Delivery (COD)</option>
                                    <?php endif; ?>
                                </select>
                                <?php if (!empty($brand['payment_instructions'])): ?>
                                    <div style="font-size:12.5px;color:#64748b;margin-top:-10px;margin-bottom:16px;background:#f8fafc;padding:8px 12px;border-radius:6px;border:1px solid #e2e8f0;">
                                        ℹ️ <?= e($brand['payment_instructions']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="ms-total"><span>Total</span><span><?= sf_money($currency, $hydrated['total']) ?></span></div>
                                <button class="ms-btn" type="submit" style="margin-top:14px;">Place Order</button>
                            </form>
                        </div>
                    <?php endif; ?>
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
                <div class="ms-form-card" style="max-width:600px;">
                    <h1 style="font-size:20px;font-weight:800;margin-bottom:16px">My Orders</h1>
                    <?php if (!$myOrders): ?>
                        <div class="ms-empty" style="padding:24px 0">You have no orders yet.</div>
                    <?php else: ?>
                        <?php foreach ($myOrders as $ord): 
                            $ordNum = (string) ($ord['order_number'] ?? ('#' . $ord['id']));
                            $ordDate = !empty($ord['created_at']) ? date('M j, Y · g:i A', strtotime($ord['created_at'])) : '';
                            $ordStatus = ucfirst((string) ($ord['order_status'] ?? 'pending'));
                            $ordUrl = public_store_url($storeBiz, 'order', ['id' => $ord['order_number'] ?: $ord['id']]);
                        ?>
                            <a href="<?= e($ordUrl) ?>" class="ms-cart-line" style="text-decoration:none;color:inherit;cursor:pointer;transition:background 0.15s;padding:12px 10px;border-radius:10px;">
                                <div>
                                    <strong style="color:#0f172a;font-size:14.5px;"><?= e($ordNum) ?></strong>
                                    <div class="ms-item-meta" style="margin-top:2px;"><?= e($ordDate) ?> · <span style="font-weight:600;color:var(--ms-header,#083d30);"><?= e($ordStatus) ?></span></div>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span style="font-weight:800;color:#0f172a;font-size:15px;"><?= sf_money($currency, (float) ($ord['total_amount'] ?? 0)) ?></span>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </div>
                            </a>
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
                            <div class="ms-cart-line" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 0;border-bottom:1px solid #f1f5f9;">
                                <div>
                                    <strong style="color:#0f172a;font-size:14px;"><?= e((string) ($inv['invoice_number'] ?? ('#' . $inv['id']))) ?></strong>
                                    <div class="ms-item-meta"><?= e((string) ($inv['invoice_date'] ?? $inv['created_at'] ?? '')) ?></div>
                                </div>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div style="font-weight:700"><?= sf_money($currency, (float) ($inv['total_amount'] ?? 0)) ?></div>
                                    <a href="<?= APP_URL . '/invoice-view.php?id=' . (int)$inv['id'] . '&standalone=1' ?>" target="_blank" style="padding:6px 12px;border-radius:6px;background:#0f172a;color:#ffffff;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                                        <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <span>Invoice</span>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($page === 'addresses' && $storeShopper):
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
                    'Himachal Pradesh', 'Jammon and Kashmir', 'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep',
                    'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry',
                    'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'
                ];
                ?>
                <div class="ms-form-card" style="max-width:560px;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;">
                        <div style="width:42px;height:42px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-5.4 7-11a7 7 0 10-14 0c0 5.6 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        </div>
                        <div>
                            <h1 style="font-size:20px;font-weight:800;color:#0f172a;margin:0 0 2px;">Set Delivery Location</h1>
                            <p style="font-size:13px;color:#64748b;margin:0;">Manage your primary delivery address for orders</p>
                        </div>
                    </div>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_delivery_location">
                        <input type="hidden" name="return_page" value="addresses">

                        <div class="ms-loc-form-group">
                            <label class="ms-loc-label">Full Name<span class="ms-req">*</span></label>
                            <input type="text" name="name" class="ms-loc-input" required value="<?= e($prefillName) ?>" placeholder="e.g. John Doe">
                        </div>

                        <div class="ms-loc-form-group">
                            <label class="ms-loc-label">Phone Number<span class="ms-req">*</span></label>
                            <input type="tel" name="phone" class="ms-loc-input" required value="<?= e($prefillPhone) ?>" placeholder="e.g. 9876543210">
                        </div>

                        <div class="ms-loc-form-group">
                            <label class="ms-loc-label">Door No / Floor / Apartment<span class="ms-req">*</span></label>
                            <input type="text" name="door_no" class="ms-loc-input" required value="<?= e($prefillDoor) ?>" placeholder="e.g. Flat 4B, Sunshine Towers">
                        </div>

                        <div class="ms-loc-form-group">
                            <label class="ms-loc-label">Street / Area / Landmark<span class="ms-req">*</span></label>
                            <input type="text" name="street_area" class="ms-loc-input" required value="<?= e($prefillStreet) ?>" placeholder="e.g. Near City Center Mall">
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="ms-loc-form-group">
                                <label class="ms-loc-label">City<span class="ms-req">*</span></label>
                                <input type="text" name="city" class="ms-loc-input" required value="<?= e($prefillCity) ?>" placeholder="e.g. Kolkata">
                            </div>
                            <div class="ms-loc-form-group">
                                <label class="ms-loc-label">Pincode<span class="ms-req">*</span></label>
                                <input type="text" name="pincode" class="ms-loc-input" required value="<?= e($prefillPincode) ?>" placeholder="e.g. 700001">
                            </div>
                        </div>

                        <div class="ms-loc-form-group">
                            <label class="ms-loc-label">State<span class="ms-req">*</span></label>
                            <select name="state" class="ms-loc-select" required>
                                <?php foreach ($indianStates as $st): ?>
                                    <option value="<?= e($st) ?>" <?= ($prefillState === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button class="ms-btn" type="submit" style="margin-top:16px;">Save Delivery Location</button>
                    </form>
                </div>

            <?php elseif ($page === 'order' || $page === 'thanks'):
                $ordParam = trim((string)($_GET['id'] ?? $_GET['order'] ?? ''));
                $order = $ordParam !== '' ? get_storefront_order_details($bid, $ordParam, $storeShopper ? (int)$storeShopper['id'] : null) : null;
                $isNewOrder = !empty($_GET['new']);
                ?>
                <?php if (!$order): ?>
                    <div class="ms-order-view-wrap">
                        <div class="ms-order-card" style="text-align:center;padding:40px 20px;">
                            <div style="font-size:38px;margin-bottom:12px;">📦</div>
                            <h1 style="font-size:20px;font-weight:800;color:#0f172a;margin:0 0 6px;">Order Details</h1>
                            <p style="font-size:14px;color:#64748b;margin:0 0 20px;">We could not find the requested order details.</p>
                            <a class="ms-btn" href="<?= e(public_store_url($storeBiz, $storeShopper ? 'orders' : 'home')) ?>" style="display:inline-flex;width:auto;">
                                <?= $storeShopper ? 'View My Orders' : 'Continue Shopping' ?>
                            </a>
                        </div>
                    </div>
                <?php else:
                    $orderId = (int)$order['id'];
                    $orderNum = (string)($order['order_number'] ?? ('#' . $orderId));
                    $ordTotal = (float)($order['total_amount'] ?? 0);
                    $ordStatus = strtolower((string)($order['order_status'] ?? 'pending'));
                    $canCancel = in_array($ordStatus, ['pending', 'new', 'placed', 'processing'], true);
                    $ordType = (($order['payment_method'] ?? '') === 'pickup') ? 'Store Pickup' : 'Home Delivery';
                    $custName = trim((string)($order['customer_name'] ?? $storeShopper['name'] ?? 'Guest Customer'));
                    $custPhone = trim((string)($order['customer_phone'] ?? $storeShopper['phone'] ?? ''));
                    $custAddress = trim((string)($order['customer_address'] ?? $storeShopper['address'] ?? ''));
                    ?>
                    <div class="ms-order-view-wrap">
                        <?php if ($isNewOrder): ?>
                            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
                                <div style="width:32px;height:32px;border-radius:50%;background:#16a34a;color:#ffffff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                </div>
                                <div>
                                    <div style="font-weight:800;color:#166534;font-size:15px;">Order Placed Successfully! 🎉</div>
                                    <div style="font-size:13px;color:#15803d;">Thank you for shopping with us.</div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="ms-order-view-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                            <a href="<?= e(public_store_url($storeBiz, $storeShopper ? 'orders' : 'home')) ?>" class="ms-order-view-back">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                <span>Order Details</span>
                            </a>
                            <a href="<?= APP_URL . '/invoice-view.php?order_id=' . $orderId . '&standalone=1' ?>" target="_blank" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#0f172a;color:#ffffff;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <span>View / Print Invoice</span>
                            </a>
                        </div>

                        <!-- Card 1: Order Meta & Status -->
                        <div class="ms-order-card">
                            <div class="ms-order-top-row">
                                <div class="ms-order-id">Order ID <?= e($orderNum) ?></div>
                                <div class="ms-order-total-price"><?= sf_money($currency, $ordTotal) ?></div>
                            </div>
                            <div class="ms-order-type">Order Type: <?= e($ordType) ?></div>
                            <div class="ms-order-status-pill status-<?= e($ordStatus) ?>">
                                <span>📦</span>
                                <span>Order Status: <?= e($order['order_status_label']) ?></span>
                            </div>
                            <div class="ms-order-date">Ordered on <?= e($order['formatted_date']) ?></div>
                        </div>

                        <!-- Card 2: Payment Status -->
                        <div class="ms-order-card">
                            <div class="ms-order-sec-title">Payment Status</div>
                            <div class="ms-order-payment-row">
                                <div class="ms-order-payment-method">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                                    <span><?= e($order['payment_method_label']) ?></span>
                                </div>
                                <span class="ms-order-payment-badge <?= e($order['payment_status_badge']) ?>"><?= e($order['payment_status_label']) ?></span>
                            </div>
                        </div>

                        <!-- Card 3: Shipping Address -->
                        <div class="ms-order-card">
                            <div class="ms-order-sec-title">Shipping Address</div>
                            <div class="ms-order-addr-name"><?= e($custName) ?></div>
                            <?php if ($custAddress !== ''): ?>
                                <div class="ms-order-addr-text"><?= nl2br(e($custAddress)) ?></div>
                            <?php endif; ?>
                            <?php if ($custPhone !== ''): ?>
                                <div class="ms-order-addr-phone">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                    <span><?= e($custPhone) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card 4: Items & Cost Summary -->
                        <div class="ms-order-card">
                            <div class="ms-order-sec-title">Items</div>
                            <table class="ms-order-items-table">
                                <thead>
                                    <tr>
                                        <th>Particulars</th>
                                        <th style="text-align:right;">Rate</th>
                                        <th style="text-align:center;">Qty</th>
                                        <th style="text-align:right;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $calcSubtotal = 0;
                                    $calcTax = (float)($order['tax_amount'] ?? 0);
                                    foreach ($order['items'] as $item): 
                                        $iPrice = (float)($item['unit_price'] ?? 0);
                                        $iQty = (int)($item['quantity'] ?? 1);
                                        $iTotal = (float)($item['line_total'] ?? ($iPrice * $iQty));
                                        $calcSubtotal += $iTotal;
                                        $iImg = sf_product_image($item['image_path'] ?? null);
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="ms-order-item-prod">
                                                    <?php if ($iImg): ?>
                                                        <img src="<?= e($iImg) ?>" alt="" class="ms-order-item-img">
                                                    <?php else: ?>
                                                        <div class="ms-order-item-img" style="display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:16px;">📦</div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="ms-order-item-name"><?= e((string)$item['product_name']) ?></div>
                                                        <?php if (!empty($item['product_sku'])): ?>
                                                            <div class="ms-order-item-sku"><?= e((string)$item['product_sku']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="text-align:right;white-space:nowrap;font-weight:600;"><?= sf_money($currency, $iPrice) ?></td>
                                            <td style="text-align:center;font-weight:600;"><?= $iQty ?></td>
                                            <td style="text-align:right;white-space:nowrap;font-weight:700;"><?= sf_money($currency, $iTotal) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="ms-order-summary-box">
                                <div class="ms-order-summary-row">
                                    <span>Sub Total (Tax Excluded)</span>
                                    <span><?= sf_money($currency, max(0, $calcSubtotal - $calcTax)) ?></span>
                                </div>
                                <div class="ms-order-summary-row">
                                    <span>Delivery Charge</span>
                                    <span style="color:#16a34a;font-weight:600;">Free</span>
                                </div>
                                <?php if ($calcTax > 0): ?>
                                    <div class="ms-order-summary-row">
                                        <span>Tax</span>
                                        <span><?= sf_money($currency, $calcTax) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="ms-order-summary-row is-total">
                                    <span>To be Paid</span>
                                    <span><?= sf_money($currency, $ordTotal) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Bottom Actions: Cancel Order & Reorder -->
                        <div class="ms-order-actions-bar">
                            <?php if ($canCancel): ?>
                                <button type="button" class="ms-order-btn-cancel" onclick="openCancelOrderModal(<?= $orderId ?>, '<?= e(addslashes($orderNum)) ?>')">
                                    Cancel Order
                                </button>
                            <?php endif; ?>

                            <form method="post" style="flex:1;display:flex;margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reorder">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <button type="submit" class="ms-order-btn-reorder">
                                    Reorder
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

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

            <?php elseif ($page === 'about'):
                $aboutContent = trim((string)($brand['about_us_content'] ?? ''));
                ?>
                <div class="ms-legal-card">
                    <a href="<?= e($homeUrl) ?>" class="ms-legal-back">← Back to Store</a>
                    <h1 class="ms-legal-title">About Us</h1>
                    <div class="ms-legal-meta">Welcome to <?= e($pageTitle) ?></div>

                    <div class="ms-legal-content">
                        <?php if ($aboutContent !== ''): ?>
                            <?= nl2br(e($aboutContent)) ?>
                        <?php else: ?>
                            <p>Welcome to <strong><?= e($pageTitle) ?></strong>, your destination for premium quality products delivered right to your doorstep.</p>
                            
                            <h3>Our Mission</h3>
                            <p>We are dedicated to providing you with the best shopping experience, focusing on product authenticity, rapid fulfillment, and world-class customer service.</p>

                            <h3>Why Shop With Us?</h3>
                            <ul>
                                <li><strong>Handpicked Quality:</strong> We source and curate only the finest items for our customers.</li>
                                <li><strong>Express Delivery:</strong> Quick turnaround times and dependable delivery across all supported locations.</li>
                                <li><strong>Customer First:</strong> Dedicated assistance whenever you have questions or require support.</li>
                            </ul>

                            <p>Have a question or looking for assistance? Feel free to reach out anytime via our <a href="<?= e(public_store_url($storeBiz, 'contact')) ?>" style="color:var(--ms-accent,#2563eb);text-decoration:underline;">Contact Us</a> page.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($page === 'terms'):
                $termsContent = trim((string)($brand['terms_content'] ?? ''));
                ?>
                <div class="ms-legal-card">
                    <a href="<?= e($homeUrl) ?>" class="ms-legal-back">← Back to Store</a>
                    <h1 class="ms-legal-title">Terms of Service</h1>
                    <div class="ms-legal-meta">Last updated for <?= e($pageTitle) ?></div>

                    <div class="ms-legal-content">
                        <?php if ($termsContent !== ''): ?>
                            <?= nl2br(e($termsContent)) ?>
                        <?php else: ?>
                            <p>By accessing and placing orders on <strong><?= e($pageTitle) ?></strong>, you agree to be bound by these Terms of Service.</p>
                            
                            <h3>1. Orders & Pricing</h3>
                            <p>All orders are subject to acceptance and product availability. Prices for items listed on our website are inclusive of applicable taxes unless explicitly stated otherwise.</p>

                            <h3>2. Delivery & Fulfillment</h3>
                            <p>We strive to dispatch all orders promptly. Delivery times may vary depending on destination location and logistics factors.</p>

                            <h3>3. Account Responsibility</h3>
                            <p>Customers are responsible for maintaining the confidentiality of their account details and phone credentials when accessing the store.</p>

                            <p>For questions or inquiries regarding these terms, please contact our support team on the <a href="<?= e(public_store_url($storeBiz, 'contact')) ?>" style="color:var(--ms-accent,#2563eb);text-decoration:underline;">Contact Us</a> page.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($page === 'refund'):
                $refundContent = trim((string)($brand['refund_policy_content'] ?? ''));
                ?>
                <div class="ms-legal-card">
                    <a href="<?= e($homeUrl) ?>" class="ms-legal-back">← Back to Store</a>
                    <h1 class="ms-legal-title">Refund & Return Policy</h1>
                    <div class="ms-legal-meta">Customer Protection Policy for <?= e($pageTitle) ?></div>

                    <div class="ms-legal-content">
                        <?php if ($refundContent !== ''): ?>
                            <?= nl2br(e($refundContent)) ?>
                        <?php else: ?>
                            <p>At <strong><?= e($pageTitle) ?></strong>, customer satisfaction is our top priority. If you receive an item that is damaged, defective, or incorrect, we are here to assist you.</p>
                            
                            <h3>1. Eligibility for Returns & Replacements</h3>
                            <p>Items may be eligible for return or replacement if reported within the applicable return window from the date of delivery, provided they are in their original condition and packaging.</p>

                            <h3>2. Refund Processing</h3>
                            <p>Once your return is received and inspected, approved refunds will be credited back to your original method of payment or processed via UPI/direct bank transfer within 5–7 business days.</p>

                            <h3>3. How to Request a Return or Refund</h3>
                            <p>Please reach out directly to our support team through our <a href="<?= e(public_store_url($storeBiz, 'contact')) ?>" style="color:var(--ms-accent,#2563eb);text-decoration:underline;">Contact Us</a> page or message us on WhatsApp with your Order ID and photos of the item.</p>
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
        $companyName = trim((string)($brand['footer_company_name'] ?? ''));
        if ($companyName === '') {
            $companyName = (string)$pageTitle;
        }
        $companyAddr = trim((string)($brand['footer_company_address'] ?? ''));
        if ($companyAddr === '') {
            $footerLocs = [];
            if (!empty($storeBiz['address'])) $footerLocs[] = $storeBiz['address'];
            if (!empty($storeBiz['city'])) $footerLocs[] = $storeBiz['city'];
            if (!empty($storeBiz['state'])) $footerLocs[] = $storeBiz['state'];
            if (!empty($storeBiz['pincode'])) $footerLocs[] = 'Pin Code: ' . $storeBiz['pincode'];
            $companyAddr = implode(",\n", $footerLocs);
        }
        $gstNo = trim((string)($brand['footer_gst_no'] ?? ''));
        $showNewsletter = !empty($brand['show_footer_newsletter']);
        $newsTitle = trim((string)($brand['footer_newsletter_title'] ?? 'Subscribe to our emails'));
        $newsSubtitle = trim((string)($brand['footer_newsletter_subtitle'] ?? 'Join our email list for exclusive offers and the latest news.'));
        $newsDisclaimer = trim((string)($brand['footer_newsletter_disclaimer'] ?? 'I hereby authorize you to send me SMS, messages, and promotional or informational communications.'));
        $poweredBy = trim((string)($brand['footer_powered_by'] ?? 'Shrine'));
        if ($poweredBy === '') {
            $poweredBy = 'Shrine';
        }
        $careWhatsapp = preg_replace('/[^0-9]/', '', (string)($brand['contact_whatsapp'] ?? $storeBiz['phone'] ?? ''));
        $showWhatsapp = !empty($brand['show_whatsapp_floating']) && ($careWhatsapp !== '');
        $waMsg = trim((string)($brand['whatsapp_floating_msg'] ?? 'Hi! I am browsing your online store and have a question.'));
        ?>
        <footer class="ms-footer">
            <div class="ms-footer-wrap">
                <div class="ms-footer-grid">
                    <!-- Column 1: Company -->
                    <div class="ms-footer-col">
                        <h3 class="ms-footer-col-title">Company</h3>
                        <div class="ms-footer-company-name"><?= e($companyName) ?></div>
                        <?php if ($companyAddr !== ''): ?>
                            <div class="ms-footer-company-addr"><?= nl2br(e($companyAddr)) ?></div>
                        <?php endif; ?>
                        <?php if ($gstNo !== ''): ?>
                            <div class="ms-footer-company-gst">GST No: <?= e($gstNo) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Column 2: Quick links -->
                    <div class="ms-footer-col">
                        <h3 class="ms-footer-col-title">Quick links</h3>
                        <ul class="ms-footer-nav-list">
                            <li class="ms-footer-nav-item"><a href="<?= e(public_store_url($storeBiz, 'about')) ?>">About Us</a></li>
                            <li class="ms-footer-nav-item"><a href="<?= e(public_store_url($storeBiz, 'contact')) ?>">Contact Us</a></li>
                            <li class="ms-footer-nav-item"><a href="<?= e(public_store_url($storeBiz, 'terms')) ?>">Terms of Service</a></li>
                            <li class="ms-footer-nav-item"><a href="<?= e(public_store_url($storeBiz, 'refund')) ?>">Refund policy</a></li>
                            <li class="ms-footer-nav-item"><a href="<?= e(public_store_url($storeBiz, 'privacy')) ?>">Privacy Policy</a></li>
                        </ul>
                    </div>

                    <!-- Column 3: Subscribe to our emails -->
                    <?php if ($showNewsletter): ?>
                    <div class="ms-footer-col">
                        <h3 class="ms-footer-col-title"><?= e($newsTitle) ?></h3>
                        <p class="ms-footer-news-desc"><?= e($newsSubtitle) ?></p>
                        <form id="msFooterNewsletterForm" class="ms-footer-news-form" method="post" action="<?= e(public_store_url($storeBiz, $page, $_GET)) ?>" onsubmit="return submitFooterNewsletter(event);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="subscribe_newsletter">
                            <input type="email" name="email" class="ms-footer-email-input" placeholder="Email" required autocomplete="email">
                            <button type="submit" class="ms-footer-signup-btn">Sign up</button>
                            <?php if ($newsDisclaimer !== ''): ?>
                                <label class="ms-footer-disclaimer-wrap">
                                    <input type="checkbox" required checked>
                                    <span class="ms-footer-disclaimer-text"><?= e($newsDisclaimer) ?></span>
                                </label>
                            <?php endif; ?>
                            <div id="msFooterNewsletterMsg" style="display:none;font-size:13px;font-weight:600;margin-top:6px;"></div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Bottom Centered Copyright Bar -->
                <div class="ms-footer-bottom">
                    © <?= date('Y') ?>, <?= e($pageTitle) ?> Powered by <?= e($poweredBy) ?>
                </div>
            </div>
        </footer>

        <!-- Floating WhatsApp Widget Button -->
        <?php if ($showWhatsapp): ?>
            <a href="https://wa.me/<?= e($careWhatsapp) ?>?text=<?= rawurlencode($waMsg) ?>" target="_blank" rel="noopener noreferrer" class="ms-wa-floating-btn" title="Chat with us on WhatsApp" aria-label="Chat on WhatsApp">
                <span class="ms-wa-badge"></span>
                <svg viewBox="0 0 24 24"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.816 9.816 0 0 0 12.04 2zm.04 16.48c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31c-.82-1.3-1.26-2.82-1.26-4.38 0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.83c.02 4.54-3.68 8.23-8.23 8.23zm4.52-6.17c-.25-.12-1.47-.72-1.7-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.79.97-.14.17-.29.19-.53.07-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.5.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.22.25-.86.84-.86 2.05 0 1.21.88 2.38 1 2.54.12.17 1.73 2.65 4.2 3.71.59.25 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.12-.22-.19-.47-.31z"/></svg>
            </a>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php if (empty($storeNotFound)):
    $cdLines = $drawerCart['lines'] ?? [];
    $cdLineCount = count($cdLines);
    $cdQty = (int) ($drawerCart['count'] ?? 0);
    $cdSub = (float) ($drawerCart['subtotal'] ?? 0);
    $cdTax = (float) ($drawerCart['tax'] ?? 0);
    $cdTotal = (float) ($drawerCart['total'] ?? 0);
    $cdSavings = (float) ($drawerCart['total_savings'] ?? 0);
    $cdReturnPage = in_array($page, ['home', 'product', 'cart', 'checkout', 'thanks', 'orders', 'order', 'invoices', 'addresses', 'profile', 'privacy', 'contact', 'about', 'terms', 'refund'], true) ? $page : 'home';
    $cdReturnId = (int) ($_GET['id'] ?? 0);

    $savedLoc = $_SESSION['sf_delivery_location_' . $bid] ?? [];
    $locAddress = trim((string)($savedLoc['formatted'] ?? $storeShopper['address'] ?? ''));
    $locName = storefront_clean_person_name((string)($savedLoc['name'] ?? $storeShopper['name'] ?? ''));
    $locPhone = trim((string)($savedLoc['phone'] ?? $storeShopper['phone'] ?? ''));
    $locDisplay = !empty($savedLoc['display']) ? $savedLoc['display'] : (!empty($storeShopper['address']) ? $storeShopper['address'] : 'Set delivery location');
    $hasDeliveryLoc = ($locAddress !== '');
    ?>
<div class="ms-cart-overlay<?= !empty($openCartDrawer) ? ' is-open' : '' ?>" id="msCartOverlay"<?= empty($openCartDrawer) ? ' hidden' : '' ?> aria-hidden="<?= !empty($openCartDrawer) ? 'false' : 'true' ?>">
    <aside class="ms-cart-drawer" id="msCartDrawer" role="dialog" aria-labelledby="msCartTitle">
        
        <!-- STEP 1: MY CART (Screenshot 1) -->
        <div class="ms-cd-view-panel" id="msCartViewMain" style="display:flex;flex-direction:column;height:100%;">
            <div class="ms-cd-head">
                <button type="button" class="ms-cd-btn-circle" id="msCartClose" aria-label="Close cart">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
                <div class="ms-cd-title" id="msCartTitle">My cart <span>( <?= $cdLineCount ?> items, <?= $cdQty ?> Qty)</span></div>
            </div>

            <?php if (!empty($brand['show_location'])): ?>
                <div class="ms-cd-delivery-bar" onclick="openLocationDrawerFromCart()">
                    <div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s7-5.4 7-11a7 7 0 10-14 0c0 5.6 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        <span class="ms-cd-delivery-text">Delivery to <?= e($locDisplay) ?></span>
                    </div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
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
                        $dispInfo = storefront_parse_product_display_info($p, $bid);
                        $pAttr = $dispInfo['attrText'] ?: trim((string)($p['sales_description'] ?? $p['description'] ?? ''));
                        $unitPrice = (float) $line['unit_price'];
                        $lineMrp = (float) ($line['mrp'] ?? $p['mrp'] ?? 0);
                        $lineSaving = ($lineMrp > $unitPrice) ? ($lineMrp - $unitPrice) : 0;
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
                                <?php if ($pAttr !== ''): ?>
                                    <div class="ms-cd-meta"><?= e($pAttr) ?></div>
                                <?php endif; ?>
                                <div class="ms-cd-price-row">
                                    <span class="ms-cd-price"><?= sf_money($currency, $unitPrice) ?></span>
                                    <?php if ($lineMrp > $unitPrice): ?>
                                        <span class="ms-cd-mrp"><?= sf_money($currency, $lineMrp) ?></span>
                                        <span class="ms-cd-item-save">Save <?= sf_money($currency, $lineSaving) ?></span>
                                    <?php endif; ?>
                                </div>
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

                    <?php if ($cdSavings > 0): ?>
                        <div class="ms-cd-savings-strip">You have saved <?= sf_money($currency, $cdSavings) ?></div>
                    <?php endif; ?>
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
                    <button type="button" class="ms-cd-checkout-btn" onclick="goToCartOrderSummary()">Proceed to Buy</button>
                <?php else: ?>
                    <a class="ms-cd-checkout-btn" href="<?= e($homeUrl) ?>">Start shopping</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- STEP 2: ORDER SUMMARY (Screenshot 2) -->
        <div class="ms-cd-view-panel" id="msCartViewOrderSummary" style="display:none;flex-direction:column;height:100%;">
            <div class="ms-cd-head">
                <button type="button" class="ms-cd-btn-circle" onclick="goToCartMainView()" aria-label="Back to cart">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                </button>
                <div class="ms-cd-title">Order Summary</div>
            </div>

            <div class="ms-cd-body">
                <!-- Deliver to Section -->
                <div class="ms-cd-deliver-section" onclick="openLocationDrawerFromCheckout('cart')">
                    <div class="ms-cd-deliver-head">
                        <div style="display:flex;align-items:center;gap:8px;font-weight:700;font-size:14px;color:#0f172a;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s7-5.4 7-11a7 7 0 10-14 0c0 5.6 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                            <span>Deliver to</span>
                        </div>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                    </div>
                    <div class="ms-cd-deliver-name"><?= e($locName ?: 'Add Delivery Address') ?></div>
                    <div class="ms-cd-deliver-addr"><?= e($locAddress ?: 'Tap here to add your delivery location') ?></div>
                    <?php if ($locPhone !== ''): ?>
                        <div class="ms-cd-deliver-phone">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <span><?= e($locPhone) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Summary -->
                <div class="ms-cd-summary" style="padding-top:14px;">
                    <div class="ms-cd-summary-title">Summary</div>
                    <div class="ms-cd-row"><span>Sub Total (Tax Excluded)</span><span><?= sf_money($currency, $cdSub) ?></span></div>
                    <div class="ms-cd-row"><span>Delivery Charge</span><span class="ms-cd-free">Free</span></div>
                    <div class="ms-cd-row"><span>Tax</span><span><?= sf_money($currency, $cdTax) ?></span></div>
                    <div class="ms-cd-row ms-cd-pay"><span>To be Paid</span><span><?= sf_money($currency, $cdTotal) ?></span></div>
                </div>

                <?php if ($cdSavings > 0): ?>
                    <div class="ms-cd-savings-strip">You have saved <?= sf_money($currency, $cdSavings) ?></div>
                <?php endif; ?>

                <!-- Payment Method Selector Section -->
                <?php
                $activeDrawerMethods = [];
                if (!empty($brand['enable_cod'])) {
                    $activeDrawerMethods['cod'] = [
                        'name' => 'Pay on Delivery (COD)',
                        'label' => 'Pay on Delivery',
                        'desc' => 'Pay with cash or UPI upon delivery',
                        'icon' => '💵'
                    ];
                }
                if (!empty($brand['enable_upi'])) {
                    $upiSub = 'Google Pay, PhonePe, Paytm, BHIM';
                    if (!empty($brand['upi_id'])) {
                        $upiSub .= ' (' . e($brand['upi_id']) . ')';
                    }
                    $activeDrawerMethods['upi'] = [
                        'name' => 'Pay with UPI',
                        'label' => 'Pay with UPI',
                        'desc' => $upiSub,
                        'icon' => '📱'
                    ];
                }
                if (!empty($brand['enable_card'])) {
                    $activeDrawerMethods['card'] = [
                        'name' => 'Credit / Debit Card',
                        'label' => 'Card Payment',
                        'desc' => 'Visa, MasterCard, RuPay',
                        'icon' => '💳'
                    ];
                }
                if (!empty($brand['enable_netbanking'])) {
                    $activeDrawerMethods['netbanking'] = [
                        'name' => 'Net Banking / Direct Bank Transfer',
                        'label' => 'Bank Transfer',
                        'desc' => 'Direct bank transfer / NEFT / IMPS',
                        'icon' => '🏦'
                    ];
                }
                if (!empty($brand['enable_store_pickup_payment'])) {
                    $activeDrawerMethods['pickup'] = [
                        'name' => 'Pay at Store / Pickup',
                        'label' => 'Pay at Store',
                        'desc' => 'Collect & pay directly at counter',
                        'icon' => '🏪'
                    ];
                }

                if (empty($activeDrawerMethods)) {
                    $activeDrawerMethods['cod'] = [
                        'name' => 'Pay on Delivery (COD)',
                        'label' => 'Pay on Delivery',
                        'desc' => 'Pay with cash or UPI upon delivery',
                        'icon' => '💵'
                    ];
                }

                $firstKey = array_key_first($activeDrawerMethods);
                $firstOpt = $activeDrawerMethods[$firstKey];
                ?>
                <div class="ms-cd-pm-section" id="msDrawerPmSection">
                    <div class="ms-cd-pm-head">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        <span>Payment Method</span>
                    </div>
                    <div class="ms-cd-pm-options">
                        <?php foreach ($activeDrawerMethods as $mKey => $mInfo): 
                            $isSel = ($mKey === $firstKey);
                        ?>
                            <label class="ms-cd-pm-option <?= $isSel ? 'is-selected' : '' ?>" onclick="selectDrawerPaymentMethod('<?= e($mKey) ?>', '<?= e(addslashes($mInfo['label'])) ?>', this)">
                                <input type="radio" name="drawer_pm_choice" value="<?= e($mKey) ?>" <?= $isSel ? 'checked' : '' ?>>
                                <div class="ms-cd-pm-icon"><?= $mInfo['icon'] ?></div>
                                <div class="ms-cd-pm-text">
                                    <div class="ms-cd-pm-name"><?= e($mInfo['name']) ?></div>
                                    <div class="ms-cd-pm-desc"><?= e($mInfo['desc']) ?></div>
                                </div>
                                <div class="ms-cd-pm-radio"></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($brand['payment_instructions'])): ?>
                        <div style="font-size:12px;color:#64748b;margin-top:10px;padding:8px 12px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0;line-height:1.4;">
                            ℹ️ <?= e($brand['payment_instructions']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" id="msDrawerCheckoutForm" onsubmit="return handleCheckoutSubmit(event, this)">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="place_order">
                <input type="hidden" name="name" value="<?= e($locName) ?>">
                <input type="hidden" name="phone" value="<?= e($locPhone) ?>">
                <input type="hidden" name="email" value="<?= e($storeShopper['email'] ?? '') ?>">
                <input type="hidden" name="address" value="<?= e($locAddress) ?>">
                <input type="hidden" name="payment_method" id="msDrawerSelectedPaymentMethod" value="<?= e($firstKey) ?>">

                <div class="ms-cd-foot">
                    <div class="ms-cd-foot-left" onclick="scrollToPaymentSection()" style="cursor:pointer;" title="Tap to change payment method">
                        <div class="ms-cd-pay-title" id="msDrawerPayTitle"><?= e($firstOpt['label']) ?></div>
                        <div class="ms-cd-pay-sub" style="color:#2563eb;font-weight:600;">Change Method ⌵</div>
                    </div>
                    <button type="submit" class="ms-cd-checkout-btn">Place Order</button>
                </div>
            </form>
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

<!-- Place Order Confirmation Modal (Reference Confirm Dialog) -->
<div class="ms-confirm-modal-overlay" id="msConfirmOrderModal" hidden aria-hidden="true">
    <div class="ms-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="msConfirmOrderTitle">
        <h3 class="ms-confirm-title" id="msConfirmOrderTitle">Confirm</h3>
        <p class="ms-confirm-msg">Do you wish to proceed ?</p>
        <div class="ms-confirm-actions">
            <button type="button" class="ms-confirm-btn-cancel" onclick="closeConfirmOrderModal()">Cancel</button>
            <button type="button" class="ms-confirm-btn-proceed" id="msConfirmProceedBtn" onclick="proceedConfirmOrder()">Proceed</button>
        </div>
    </div>
</div>

<!-- Cancel Order Confirmation Modal -->
<div class="ms-confirm-modal-overlay" id="msCancelOrderModal" hidden aria-hidden="true">
    <div class="ms-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="msCancelOrderTitle">
        <h3 class="ms-confirm-title" id="msCancelOrderTitle" style="color:#e11d48;">Cancel Order</h3>
        <p class="ms-confirm-msg">Are you sure you want to cancel this order?</p>
        <div class="ms-confirm-actions">
            <button type="button" class="ms-confirm-btn-cancel" onclick="closeCancelOrderModal()">Keep Order</button>
            <button type="button" class="ms-confirm-btn-proceed" id="msCancelProceedBtn" style="background:#e11d48;" onclick="proceedCancelOrder()">Yes, Cancel</button>
        </div>
    </div>
</div>

<form method="post" id="msCancelOrderForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel_order">
    <input type="hidden" name="order_id" value="">
    <input type="hidden" name="order_number" value="">
</form>

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

    window.openLocationDrawerFromCheckout = function(returnPage) {
        var rPage = returnPage || 'checkout';
        if (locOverlay) {
            var retInput = locOverlay.querySelector('input[name="return_page"]');
            if (retInput) retInput.value = rPage;
            showLocPrompt();
            openDrawer(locOverlay);
        }
    };

    window.openLocationDrawerFromCart = function() {
        window.openLocationDrawerFromCheckout('checkout');
    };
})();
</script>
<script>
/* ==========================================================================
   Storefront Banner Smooth Carousel & Auto-Play Engine
   ========================================================================== */
(function () {
    var sfBannerCurrentIdx = 0;
    var sfBannerAutoplayTimer = null;
    var sfBannerIsPaused = false;

    function sfBannerGetCards() {
        var grid = document.getElementById('msBanners');
        return grid ? grid.querySelectorAll('.ms-banner-card') : [];
    }

    function sfBannerUpdateDots(idx) {
        var dots = document.querySelectorAll('.ms-banner-dot');
        for (var i = 0; i < dots.length; i++) {
            dots[i].classList.toggle('is-active', i === idx);
        }
    }

    window.sfBannerGoTo = function(idx) {
        var grid = document.getElementById('msBanners');
        var cards = sfBannerGetCards();
        if (!grid || !cards.length) return;
        idx = Math.max(0, Math.min(idx, cards.length - 1));
        sfBannerCurrentIdx = idx;
        var targetCard = cards[idx];
        if (targetCard) {
            var scrollLeft = targetCard.offsetLeft - grid.offsetLeft;
            grid.scrollTo({
                left: scrollLeft,
                behavior: 'smooth'
            });
        }
        sfBannerUpdateDots(idx);
    };

    window.sfBannerMove = function(dir) {
        var cards = sfBannerGetCards();
        if (!cards.length) return;
        var nextIdx = (sfBannerCurrentIdx + dir + cards.length) % cards.length;
        window.sfBannerGoTo(nextIdx);
    };

    function sfInitBannerCarousel() {
        var wrap = document.getElementById('msBannersWrapper');
        var grid = document.getElementById('msBanners');
        if (!wrap || !grid) return;

        // Pause on hover
        wrap.addEventListener('mouseenter', function() { sfBannerIsPaused = true; });
        wrap.addEventListener('mouseleave', function() { sfBannerIsPaused = false; });
        wrap.addEventListener('touchstart', function() { sfBannerIsPaused = true; }, { passive: true });
        wrap.addEventListener('touchend', function() {
            setTimeout(function() { sfBannerIsPaused = false; }, 5000);
        }, { passive: true });

        // Sync dots on scroll
        var scrollTimeout;
        grid.addEventListener('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(function() {
                var cards = sfBannerGetCards();
                if (!cards.length) return;
                var scrollLeft = grid.scrollLeft;
                var closestIdx = 0;
                var minDiff = Infinity;
                for (var i = 0; i < cards.length; i++) {
                    var diff = Math.abs(cards[i].offsetLeft - grid.offsetLeft - scrollLeft);
                    if (diff < minDiff) {
                        minDiff = diff;
                        closestIdx = i;
                    }
                }
                sfBannerCurrentIdx = closestIdx;
                sfBannerUpdateDots(closestIdx);
            }, 60);
        }, { passive: true });

        // Auto-play interval
        clearInterval(sfBannerAutoplayTimer);
        sfBannerAutoplayTimer = setInterval(function() {
            if (sfBannerIsPaused) return;
            var cards = sfBannerGetCards();
            if (!cards || cards.length <= 1) return;
            var nextIdx = (sfBannerCurrentIdx + 1) % cards.length;
            window.sfBannerGoTo(nextIdx);
        }, 3500);
    }

    document.addEventListener('visibilitychange', function () {
        sfBannerIsPaused = document.hidden;
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sfInitBannerCarousel);
    } else {
        sfInitBannerCarousel();
    }
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

function sfCardSlide(btn, dir) {
    var wrap = btn.closest('.ms-product-img-wrap');
    if (!wrap) return;
    var imgs = wrap.querySelectorAll('.ms-card-img-track img');
    var dots = wrap.querySelectorAll('.ms-card-dots .ms-card-dot');
    if (imgs.length <= 1) return;
    
    var currentIdx = 0;
    for (var i = 0; i < imgs.length; i++) {
        if (imgs[i].style.display !== 'none' && !imgs[i].hidden) {
            currentIdx = i;
            break;
        }
    }
    
    var nextIdx = (currentIdx + dir + imgs.length) % imgs.length;
    
    for (var j = 0; j < imgs.length; j++) {
        imgs[j].style.display = (j === nextIdx) ? 'block' : 'none';
        imgs[j].classList.toggle('is-active', j === nextIdx);
    }
    for (var k = 0; k < dots.length; k++) {
        dots[k].classList.toggle('is-active', k === nextIdx);
    }
}

var pdpImages = <?= !empty($pdpImages) ? json_encode($pdpImages) : '[]' ?>;
var pdpCurrentIdx = 0;

function sfPdpSlide(dir) {
    if (!pdpImages || pdpImages.length <= 1) return;
    pdpCurrentIdx = (pdpCurrentIdx + dir + pdpImages.length) % pdpImages.length;
    sfPdpSetImage(pdpCurrentIdx);
}

function sfPdpSetImage(idx) {
    if (!pdpImages || !pdpImages[idx]) return;
    pdpCurrentIdx = idx;
    var mainImg = document.getElementById('pdpMainImg');
    if (mainImg) {
        mainImg.src = pdpImages[idx];
    }
    var thumbs = document.querySelectorAll('.ms-pdp-thumb');
    for (var i = 0; i < thumbs.length; i++) {
        thumbs[i].classList.toggle('is-active', i === idx);
    }
}

function handleAjaxAddToCart(ev, form) {
    if (ev) ev.preventDefault();
    var btn = form.querySelector('button[type="submit"]');
    var origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 0.8s linear infinite"><circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="12"/></svg> <span>Adding...</span>';
    }

    var formData = new FormData(form);
    formData.append('ajax', '1');

    fetch(form.action || window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data && data.success) {
            if (btn) {
                var prevBg = btn.style.background;
                btn.style.background = '#16a34a';
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> <span>Added!</span>';
                setTimeout(function() {
                    btn.disabled = false;
                    btn.style.background = prevBg;
                    btn.innerHTML = origHtml;
                }, 1400);
            }
            var badges = document.querySelectorAll('#msCartBadge, .ms-cart-badge, .ms-bottom-cart-badge');
            badges.forEach(function(b) {
                b.textContent = data.cart_count;
                b.style.display = data.cart_count > 0 ? '' : 'none';
            });
        } else {
            alert((data && data.message) ? data.message : 'Could not add item to cart.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }
    })
    .catch(function(err) {
        form.submit();
    });
}

function selectDrawerPaymentMethod(methodKey, methodTitle, labelEl) {
    var hiddenInput = document.getElementById('msDrawerSelectedPaymentMethod');
    if (hiddenInput) hiddenInput.value = methodKey;

    var titleEl = document.getElementById('msDrawerPayTitle');
    if (titleEl) titleEl.textContent = methodTitle;

    var options = document.querySelectorAll('.ms-cd-pm-option');
    options.forEach(function(opt) { opt.classList.remove('is-selected'); });

    if (labelEl) {
        labelEl.classList.add('is-selected');
        var r = labelEl.querySelector('input[type="radio"]');
        if (r) r.checked = true;
    }
}

function scrollToPaymentSection() {
    var pmSec = document.getElementById('msDrawerPmSection');
    if (pmSec) {
        pmSec.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function goToCartOrderSummary() {
    var hasLocation = <?= (!empty($hasDeliveryLoc)) ? 'true' : 'false' ?>;
    if (!hasLocation) {
        window.openLocationDrawerFromCheckout('cart');
        return;
    }
    var vMain = document.getElementById('msCartViewMain');
    var vSummary = document.getElementById('msCartViewOrderSummary');
    if (vMain && vSummary) {
        vMain.style.display = 'none';
        vSummary.style.display = 'flex';
    }
}

function goToCartMainView() {
    var vMain = document.getElementById('msCartViewMain');
    var vSummary = document.getElementById('msCartViewOrderSummary');
    if (vMain && vSummary) {
        vSummary.style.display = 'none';
        vMain.style.display = 'flex';
    }
}

var msCheckoutFormPending = null;
function handleCheckoutSubmit(e, form) {
    if (form.dataset.confirmed === '1') {
        return true;
    }
    if (e) e.preventDefault();
    msCheckoutFormPending = form;
    openConfirmOrderModal();
    return false;
}

function openConfirmOrderModal() {
    var modal = document.getElementById('msConfirmOrderModal');
    if (modal) {
        modal.classList.add('is-open');
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
    }
}

function closeConfirmOrderModal() {
    var modal = document.getElementById('msConfirmOrderModal');
    if (modal) {
        modal.classList.remove('is-open');
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
    }
    msCheckoutFormPending = null;
}

function proceedConfirmOrder() {
    if (msCheckoutFormPending) {
        msCheckoutFormPending.dataset.confirmed = '1';
        var btn = document.getElementById('msConfirmProceedBtn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Placing order...';
        }
        msCheckoutFormPending.submit();
    }
}

var msCancelOrderIdPending = null;
var msCancelOrderNumPending = '';

function openCancelOrderModal(orderId, orderNum) {
    msCancelOrderIdPending = orderId;
    msCancelOrderNumPending = orderNum || '';
    var modal = document.getElementById('msCancelOrderModal');
    if (modal) {
        modal.classList.add('is-open');
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
    }
}

function closeCancelOrderModal() {
    var modal = document.getElementById('msCancelOrderModal');
    if (modal) {
        modal.classList.remove('is-open');
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
    }
    msCancelOrderIdPending = null;
}

function proceedCancelOrder() {
    if (msCancelOrderIdPending) {
        var form = document.getElementById('msCancelOrderForm');
        if (form) {
            form.querySelector('input[name="order_id"]').value = msCancelOrderIdPending;
            form.querySelector('input[name="order_number"]').value = msCancelOrderNumPending;
            var btn = document.getElementById('msCancelProceedBtn');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Cancelling...';
            }
            form.submit();
        }
    }
}

function submitFooterNewsletter(e) {
    if (e && e.preventDefault) e.preventDefault();
    var form = document.getElementById('msFooterNewsletterForm');
    if (!form) return false;
    var emailInput = form.querySelector('input[name="email"]');
    var btn = form.querySelector('.ms-footer-signup-btn');
    var msgBox = document.getElementById('msFooterNewsletterMsg');
    var email = emailInput ? emailInput.value.trim() : '';
    if (!email) return false;

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Subscribing...';
    }

    var formData = new FormData(form);
    formData.append('ajax', '1');

    fetch(form.action || window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Sign up';
        }
        if (msgBox) {
            msgBox.style.display = 'block';
            msgBox.style.color = data.success ? '#ffffff' : '#ffcdd2';
            msgBox.textContent = data.message || (data.success ? 'Thank you for subscribing!' : 'Could not subscribe.');
        }
        if (data.success && emailInput) {
            emailInput.value = '';
        }
    })
    .catch(function(err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Sign up';
        }
        if (msgBox) {
            msgBox.style.display = 'block';
            msgBox.style.color = '#ffffff';
            msgBox.textContent = 'Thank you for subscribing!';
        }
        if (emailInput) {
            emailInput.value = '';
        }
    });
    return false;
}
</script>
</body>
</html>

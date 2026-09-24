<?php
/**
 * OminiFlow POS - Point of Sale / Register Screen
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/products_db.php';
require_once __DIR__ . '/includes/orders_db.php';
require_once __DIR__ . '/includes/payment_options_db.php';
require_once __DIR__ . '/includes/payment_integrations_db.php';
require_once __DIR__ . '/includes/razorpay_oauth.php';
require_once __DIR__ . '/includes/invoice_whatsapp.php';
require_once __DIR__ . '/includes/outlets_db.php';
require_once __DIR__ . '/includes/registers_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_pos_outlet') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '') && !user_pos_is_outlet_locked($user)) {
        $openShift = get_open_register_session($userId);
        if ((int) ($openShift['outlet_id'] ?? 0) <= 0) {
            $_SESSION['pos_outlet_id'] = (int) ($_POST['outlet_id'] ?? 0);
        }
    }
    redirect(APP_URL . '/pos.php');
}

// Handle AJAX Actions (Checkout, Hold, Resume, Add Customer)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Verify CSRF
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid session token. Please reload the page.']);
            exit;
        }
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/pos.php');
    }

    if ($action === 'send_order_invoice_whatsapp') {
        header('Content-Type: application/json; charset=utf-8');
        ignore_user_abort(true);
        @set_time_limit(60);
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            exit;
        }
        $phone = trim((string) ($_POST['customer_phone'] ?? ''));
        $payStatus = strtolower(trim((string) ($_POST['payment_status'] ?? 'paid')));
        if ($payStatus === '') {
            $payStatus = 'paid';
        }
        try {
            $res = send_order_invoice_whatsapp(current_business_id(), $orderId, $phone, [
                'invoice_id' => (int) ($_POST['invoice_id'] ?? 0),
                'order_number' => (string) ($_POST['order_number'] ?? ''),
                'payment_status' => $payStatus,
                'customer_phone' => $phone,
            ]);
        } catch (Throwable $e) {
            error_log('POS async invoice WhatsApp: ' . $e->getMessage());
            $res = ['success' => false, 'error' => 'Could not send invoice on WhatsApp.'];
        }
        echo json_encode($res);
        exit;
    }

    if ($action === 'checkout') {
        $cartJson = $_POST['cart_json'] ?? '[]';
        $cartItems = json_decode($cartJson, true) ?: [];
        $customerId = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : 1;
        $discountVal = (float) ($_POST['discount_value'] ?? 0.00);
        $discountType = (string) ($_POST['discount_type'] ?? 'fixed');
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
        $notes = (string) ($_POST['notes'] ?? '');
        $amountTendered = (float) ($_POST['amount_tendered'] ?? 0.00);
        $outletId = (int) ($_POST['outlet_id'] ?? 0);
        if ($outletId <= 0) {
            $outletId = (int) ($_SESSION['pos_outlet_id'] ?? 0);
        }
        if (user_pos_is_outlet_locked($user)) {
            $outletId = (int) ($user['outlet_id'] ?? 0);
        } else {
            $openShift = get_open_register_session($userId);
            if ((int) ($openShift['outlet_id'] ?? 0) > 0) {
                $outletId = (int) $openShift['outlet_id'];
            }
        }
        $clientOrderUuid = !empty($_POST['client_order_uuid']) ? (string)$_POST['client_order_uuid'] : null;
        $couponId = !empty($_POST['coupon_id']) ? (int)$_POST['coupon_id'] : null;
        $couponCode = !empty($_POST['coupon_code']) ? (string)$_POST['coupon_code'] : null;
        $loyaltyPoints = !empty($_POST['loyalty_points']) ? (int)$_POST['loyalty_points'] : 0;
        $loyaltyDiscount = !empty($_POST['loyalty_discount']) ? (float)$_POST['loyalty_discount'] : 0.00;
        $priceListId = !empty($_POST['price_list_id']) ? (int)$_POST['price_list_id'] : null;

        if ($paymentMethod === 'razorpay') {
            $rzpOrderId = trim((string) ($_POST['razorpay_order_id'] ?? ''));
            $rzpPaymentId = trim((string) ($_POST['razorpay_payment_id'] ?? ''));
            $rzpSignature = trim((string) ($_POST['razorpay_signature'] ?? ''));
            $verified = $rzpOrderId !== '' && $rzpPaymentId !== '' && razorpay_verify_checkout($rzpOrderId, $rzpPaymentId, $rzpSignature);
            if (!$verified) {
                $fail = ['success' => false, 'errors' => ['payment' => 'Razorpay payment could not be verified. Please try again.']];
                if (!empty($_POST['is_ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode($fail);
                    exit;
                }
                set_flash('error', $fail['errors']['payment']);
                redirect(APP_URL . '/pos.php');
            }
            $notes = trim($notes . "\nRazorpay order: {$rzpOrderId}; payment: {$rzpPaymentId}");
        }

        $paymentSplitsJson = trim((string) ($_POST['payment_splits_json'] ?? ''));
        if ($paymentSplitsJson !== '') {
            $paymentMethod = 'split';
        }

        $result = process_pos_order(
            $cartItems, $customerId, $userId, $discountVal, $discountType, $paymentMethod,
            $notes, $amountTendered, $outletId, $clientOrderUuid, $couponId, $couponCode,
            $loyaltyPoints, $loyaltyDiscount, $priceListId, null, 'pos', 'delivered', null,
            $paymentSplitsJson !== '' ? $paymentSplitsJson : null
        );

        if (!empty($result['success'])) {
            if (!empty($_POST['is_ajax'])) {
                $result['whatsapp_invoice'] = ['pending' => true];
            } else {
                $result = attach_pos_invoice_whatsapp($result, current_business_id());
            }
        }

        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }

        if ($result['success']) {
            $wa = $result['whatsapp_invoice'] ?? [];
            $flash = 'Order #' . $result['order_number'] . ' completed successfully! Total: ₹' . number_format($result['total_amount'], 2);
            if (!empty($wa['success'])) {
                $flash .= ' Invoice PDF sent to WhatsApp.';
            }
            set_flash('success', $flash);
            redirect(APP_URL . '/orders.php?highlight=' . $result['order_id']);
        } else {
            $msg = implode(' ', $result['errors']);
            set_flash('error', $msg);
            redirect(APP_URL . '/pos.php');
        }
    } elseif ($action === 'create_razorpay_order') {
        $amount = (float) ($_POST['amount'] ?? 0);
        $amountPaise = (int) round($amount * 100);
        if ($amountPaise < 100) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Amount must be at least ₹1.00']);
            exit;
        }
        $receipt = 'POS-' . current_business_id() . '-' . time();
        $created = razorpay_create_order($amountPaise, $receipt, [
            'source' => 'pos',
            'business_id' => (string) current_business_id(),
        ]);
        header('Content-Type: application/json');
        if (empty($created['success'])) {
            echo json_encode(['success' => false, 'error' => $created['error'] ?? 'Could not start Razorpay']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'key' => razorpay_checkout_key(),
            'order_id' => $created['order']['id'],
            'amount' => $created['order']['amount'] ?? $amountPaise,
            'currency' => $created['order']['currency'] ?? 'INR',
            'name' => APP_NAME,
        ]);
        exit;
    } elseif ($action === 'validate_coupon') {
        require_once __DIR__ . '/includes/promotions_db.php';
        $couponCode = trim($_POST['coupon_code'] ?? '');
        $subtotal = (float)($_POST['subtotal'] ?? 0.00);
        $res = validate_and_apply_coupon($couponCode, $subtotal);
        header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    } elseif ($action === 'calc_cart_promotions') {
        require_once __DIR__ . '/includes/promotions_db.php';
        $cartJson = $_POST['cart_json'] ?? '[]';
        $cartItems = json_decode($cartJson, true) ?: [];
        $subtotal = (float) ($_POST['subtotal'] ?? 0.00);
        if ($subtotal <= 0 && is_array($cartItems)) {
            foreach ($cartItems as $item) {
                $subtotal += (float) ($item['price'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1));
            }
        }
        $promoLines = [];
        foreach ($cartItems as $item) {
            $promoLines[] = [
                'price' => (float) ($item['price'] ?? 0),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            ];
        }
        $promoResult = calculate_promotions_for_cart($promoLines, $subtotal);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'total_discount' => (float) ($promoResult['total_discount'] ?? 0),
            'applied_promotions' => $promoResult['applied_promotions'] ?? [],
        ]);
        exit;
    } elseif ($action === 'hold_sale') {
        $cartJson = $_POST['cart_json'] ?? '[]';
        $cartItems = json_decode($cartJson, true) ?: [];
        $customerId = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : 1;
        $subtotal = (float) ($_POST['subtotal'] ?? 0.00);
        $totalAmount = (float) ($_POST['total_amount'] ?? 0.00);
        $referenceNote = (string) ($_POST['reference_note'] ?? 'Hold Sale');

        $result = save_held_sale($referenceNote, $customerId, $userId, $cartItems, $subtotal, $totalAmount);

        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }
        redirect(APP_URL . '/pos.php');
    } elseif ($action === 'delete_held') {
        $heldId = (int) ($_POST['held_id'] ?? 0);
        $deleted = delete_held_sale($heldId);

        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $deleted]);
            exit;
        }
        redirect(APP_URL . '/pos.php');
    } elseif ($action === 'add_customer') {
        $custData = [
            'name' => $_POST['name'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'address' => $_POST['address'] ?? '',
        ];

        $result = save_customer($custData);

        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }

        if ($result['success']) {
            set_flash('success', 'Customer added successfully!');
        } else {
            set_flash('error', implode(' ', $result['errors']));
        }
        redirect(APP_URL . '/pos.php');
    } elseif ($action === 'add_product') {
        $respondAddProduct = static function (array $payload): void {
            if (!empty($_POST['is_ajax'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($payload, JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!empty($payload['success'])) {
                set_flash('success', 'Product added successfully!');
            } else {
                $errs = $payload['errors'] ?? ['Could not add product.'];
                set_flash('error', is_array($errs) ? implode(' ', array_values($errs)) : (string) $errs);
            }
            redirect(APP_URL . '/pos.php');
        };

        $file = null;
        $wantedImage = false;
        if (!empty($_FILES['product_image']) && ($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $wantedImage = true;
            $imgErr = (int) ($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($imgErr === UPLOAD_ERR_OK) {
                if ((int) ($_FILES['product_image']['size'] ?? 0) > 5 * 1024 * 1024) {
                    $respondAddProduct(['success' => false, 'errors' => ['image' => 'Image must be 5MB or smaller.']]);
                }
                $file = $_FILES['product_image'];
            } elseif (in_array($imgErr, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $respondAddProduct(['success' => false, 'errors' => ['image' => 'Image is too large. Use a file under 5MB.']]);
            } else {
                $respondAddProduct(['success' => false, 'errors' => ['image' => 'Could not read the image. Try another JPG or PNG.']]);
            }
        }

        $prodData = [
            'name' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 191),
            'sku' => mb_substr(trim((string) ($_POST['sku'] ?? '')), 0, 100),
            'selling_price' => max(0, (float) ($_POST['selling_price'] ?? 0)),
            'tax_percent' => 0,
            'status' => 'active',
            'initial_stock' => max(0, (int) ($_POST['initial_stock'] ?? 1)),
            'item_kind' => 'goods',
            'product_type' => 'simple',
            'track_inventory' => 1,
        ];

        if ($prodData['name'] === '') {
            $respondAddProduct(['success' => false, 'errors' => ['name' => 'Product name is required.']]);
        }
        if ($prodData['sku'] === '') {
            $respondAddProduct(['success' => false, 'errors' => ['sku' => 'SKU is required.']]);
        }

        $result = ['success' => false, 'errors' => ['general' => 'Could not add product.']];
        try {
            $result = save_product($prodData, $file, null, $userId);
        } catch (Throwable $e) {
            error_log('POS add_product: ' . $e->getMessage());
            if ($file) {
                try {
                    $result = save_product($prodData, null, null, $userId);
                    if (!empty($result['success'])) {
                        $result['image_warning'] = 'Product saved without image.';
                    }
                } catch (Throwable $e2) {
                    error_log('POS add_product retry: ' . $e2->getMessage());
                    $respondAddProduct(['success' => false, 'errors' => ['general' => 'Could not save product. Please try again.']]);
                }
            } else {
                $respondAddProduct(['success' => false, 'errors' => ['general' => 'Could not save product. Please try again.']]);
            }
        }

        if (!empty($result['success']) && !empty($result['product_id'])) {
            $saved = get_product_by_id((int) $result['product_id']);
            if ($saved) {
                $result['product'] = [
                    'id' => (int) $saved['id'],
                    'name' => (string) $saved['name'],
                    'sku' => (string) $saved['sku'],
                    'barcode' => (string) ($saved['barcode'] ?? ''),
                    'selling_price' => (float) $saved['selling_price'],
                    'tax_percent' => (float) $saved['tax_percent'],
                    'stock_quantity' => (int) $saved['stock_quantity'],
                    'low_stock_threshold' => (int) $saved['low_stock_threshold'],
                    'category_id' => $saved['category_id'] ?: 'none',
                    'image_url' => !empty($saved['image_path']) ? asset((string) $saved['image_path']) : '',
                ];
                if ($wantedImage && empty($saved['image_path']) && empty($result['image_warning'])) {
                    $result['image_warning'] = 'Product saved, but the image could not be uploaded. Check file type (JPG/PNG/WEBP) and folder permissions.';
                }
            }
        }

        $respondAddProduct($result ?? ['success' => false, 'errors' => ['general' => 'Could not add product.']]);
    }
}

// Load Catalog Data for Terminal
$categories = get_categories('', 'active');
$products = get_products('', null, 'active');
$posVariantsByProduct = [];
try {
    $variantStmt = get_db()->prepare('
        SELECT id, product_id, variant_name, attribute_values, sku, barcode, selling_price, stock_quantity
        FROM product_variants
        WHERE business_id = :bid AND status = "active"
        ORDER BY id ASC
    ');
    $variantStmt->execute(['bid' => current_business_id()]);
    foreach ($variantStmt->fetchAll() as $variantRow) {
        $attrs = parse_variant_size_colour($variantRow);
        $variantProductId = (int) $variantRow['product_id'];
        $posVariantsByProduct[$variantProductId][] = [
            'id' => (int) $variantRow['id'],
            'name' => (string) $variantRow['variant_name'],
            'sku' => (string) $variantRow['sku'],
            'barcode' => (string) ($variantRow['barcode'] ?? ''),
            'price' => (float) $variantRow['selling_price'],
            'stock' => (int) $variantRow['stock_quantity'],
            'size' => $attrs['size'],
            'colour' => $attrs['colour'],
        ];
    }
} catch (Throwable $e) {
    $posVariantsByProduct = [];
}
$customers = get_customers();
$heldSales = get_held_sales();
$paymentOptions = get_payment_options('active');
$activeGateways = get_active_pos_payment_gateways();
$posSplitPayMethods = [];
foreach ($paymentOptions as $pOpt) {
    $code = strtolower(str_replace(' ', '_', (string) ($pOpt['payment_mode'] ?? '')));
    if ($code === '') {
        continue;
    }
    $posSplitPayMethods[] = [
        'code' => $code,
        'label' => (string) ($pOpt['display_name'] ?? ucfirst($code)),
    ];
}
if ($posSplitPayMethods === []) {
    $posSplitPayMethods = [
        ['code' => 'cash', 'label' => 'Cash'],
        ['code' => 'upi', 'label' => 'UPI'],
        ['code' => 'card', 'label' => 'Card / POS'],
        ['code' => 'credit', 'label' => 'Credit'],
    ];
}

// Daily Alerts: Low Stock & Unsold for a Week (7+ Days)
$lowStockAlerts = get_pos_low_stock_alerts();
$unsoldAlerts = get_pos_unsold_products_alerts(7);
$totalPosAlerts = count($lowStockAlerts) + count($unsoldAlerts);

$posOutlets = get_outlets('active');
$posOpenShift = get_open_register_session($userId);
$posLockedToStore = false;
$posLockLabel = '';
if (user_pos_is_outlet_locked($user)) {
    $posOutletId = resolve_pos_outlet_id((int) ($user['outlet_id'] ?? 0));
    $posLockedToStore = true;
    $posLockLabel = 'Your store';
} elseif ((int) ($posOpenShift['outlet_id'] ?? 0) > 0) {
    $posOutletId = resolve_pos_outlet_id((int) $posOpenShift['outlet_id']);
    $posLockedToStore = true;
    $posLockLabel = trim((string) ($posOpenShift['register_name'] ?? 'This counter'));
} else {
    $posOutletId = resolve_pos_outlet_id((int) ($_SESSION['pos_outlet_id'] ?? 0));
}
$_SESSION['pos_outlet_id'] = $posOutletId;
$posOutletName = '';
foreach ($posOutlets as $posOutletRow) {
    if ((int) $posOutletRow['id'] === $posOutletId) {
        $posOutletName = (string) $posOutletRow['name'];
        break;
    }
}
$posWarehouseId = get_warehouse_id_for_outlet($posOutletId) ?? 0;
$posIsolateOutletStock = pos_isolates_outlet_stock();
$posWarehouseStockByProduct = [];
$posLocationTracked = [];
if ($posWarehouseId > 0) {
    $wsStmt = get_db()->prepare('SELECT product_id, stock_quantity FROM warehouse_stock WHERE warehouse_id = :wid');
    $wsStmt->execute(['wid' => $posWarehouseId]);
    foreach ($wsStmt->fetchAll() as $wsRow) {
        $posWarehouseStockByProduct[(int) $wsRow['product_id']] = (int) $wsRow['stock_quantity'];
    }
}
if ($posIsolateOutletStock) {
    $locStmt = get_db()->prepare('
        SELECT DISTINCT ws.product_id
        FROM warehouse_stock ws
        INNER JOIN warehouses w ON w.id = ws.warehouse_id AND w.business_id = :bid
    ');
    $locStmt->execute(['bid' => current_business_id()]);
    foreach ($locStmt->fetchAll() as $locRow) {
        $posLocationTracked[(int) $locRow['product_id']] = true;
    }
    foreach ($posVariantsByProduct as $variantProductId => $variantRows) {
        if (empty($posLocationTracked[(int) $variantProductId])) {
            continue;
        }
        $storeCap = (int) ($posWarehouseStockByProduct[(int) $variantProductId] ?? 0);
        foreach ($variantRows as $variantIndex => $variantRow) {
            $posVariantsByProduct[$variantProductId][$variantIndex]['stock'] = min((int) $variantRow['stock'], $storeCap);
        }
    }
}

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale Register - OminiFlow POS</title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>?v=<?= (int) @filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body class="pos-page">
    <div class="app-layout">
        <!-- Sidebar Component -->
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="app-main">
            <!-- Header Component -->
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <!-- POS Split Container -->
                <div class="pos-container">
                    <!-- LEFT / MAIN AREA: Product Search, Barcode Scanner & Catalog Grid -->
                    <div class="pos-catalog-panel">
                        <!-- Barcode & Search Controls -->
                        <div class="pos-search-barcode-row">
                            <?php if ($posLockedToStore): ?>
                            <div class="pos-outlet-switch" style="min-width: 200px;">
                                <label class="form-label" style="font-size: 11px; font-weight: 700; color: var(--saas-slate-600); margin-bottom: 4px; display: block;">Billing store</label>
                                <div class="form-control" style="background: #f8fafc; font-weight: 700;" title="This counter sells only this store's quantity">
                                    <?= e($posOutletName !== '' ? $posOutletName : 'Store') ?>
                                    <span style="font-weight: 500; color: #64748b;"> · <?= e($posLockLabel) ?></span>
                                </div>
                            </div>
                            <?php elseif (count($posOutlets) > 0): ?>
                            <form method="POST" action="<?= asset('pos.php') ?>" class="pos-outlet-switch" style="min-width: 200px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_pos_outlet">
                                <label class="form-label" style="font-size: 11px; font-weight: 700; color: var(--saas-slate-600); margin-bottom: 4px; display: block;">Billing store</label>
                                <select name="outlet_id" class="form-control" onchange="this.form.submit()" title="Stock is deducted from this store's warehouse">
                                    <?php foreach ($posOutlets as $posOutlet): ?>
                                        <option value="<?= (int) $posOutlet['id'] ?>" <?= (int) $posOutlet['id'] === $posOutletId ? 'selected' : '' ?>>
                                            <?= e($posOutlet['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <?php endif; ?>
                            <div class="search-input-wrap">
                                <span class="search-icon">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                    </svg>
                                </span>
                                <input
                                    type="text"
                                    id="productSearchInput"
                                    placeholder="Search products by name or SKU..."
                                    class="form-control with-icon"
                                    autocomplete="off"
                                >
                            </div>

                            <div class="search-input-wrap">
                                <span class="search-icon">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/>
                                    </svg>
                                </span>
                                <input
                                    type="text"
                                    id="barcodeInput"
                                    placeholder="Scan Barcode (Enter)..."
                                    class="form-control with-icon"
                                    autocomplete="off"
                                    autofocus
                                >
                            </div>

                            <button
                                type="button"
                                class="pos-alerts-trigger-btn <?= $totalPosAlerts > 0 ? 'has-alerts' : '' ?>"
                                id="openPosAlertsModalBtn"
                                title="Daily Stock & Unsold Product Notices"
                            >
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                                </svg>
                                <span>Alerts</span>
                                <?php if ($totalPosAlerts > 0): ?>
                                    <span class="pos-alert-count-badge"><?= $totalPosAlerts ?></span>
                                <?php endif; ?>
                            </button>
                        </div>

                        <!-- Category Filter Pills -->
                        <div class="pos-category-pills" id="categoryPillRow">
                            <button type="button" class="pos-cat-pill active" data-category="all" id="allProductsPill">All Products (<?= count($products) ?>)</button>
                            <?php foreach ($categories as $cat): ?>
                                <button type="button" class="pos-cat-pill" data-category="<?= $cat['id'] ?>">
                                    <?= e($cat['name']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- Product Cards Grid -->
                        <div class="pos-product-grid" id="posProductGrid">
                            <?php foreach ($products as $prod): ?>
                                <?php
                                    $prodVariants = $posVariantsByProduct[(int) $prod['id']] ?? [];
                                    $pid = (int) $prod['id'];
                                    $sellsStoreQty = $posIsolateOutletStock && !empty($posLocationTracked[$pid]);
                                    if ($sellsStoreQty) {
                                        $stock = (int) ($posWarehouseStockByProduct[$pid] ?? 0);
                                    } elseif (array_key_exists($pid, $posWarehouseStockByProduct)) {
                                        $stock = (int) $posWarehouseStockByProduct[$pid];
                                    } else {
                                        $stock = (int) $prod['stock_quantity'];
                                    }
                                    if ($prodVariants && !$sellsStoreQty) {
                                        $stock = 0;
                                        foreach ($prodVariants as $prodVariant) {
                                            $stock += (int) $prodVariant['stock'];
                                        }
                                    }
                                    $isOutOfStock = ($stock <= 0);
                                    $threshold = (int) $prod['low_stock_threshold'];
                                    $stockClass = 'badge-in-stock';
                                    $stockText = $stock . ' in stock';

                                    if ($isOutOfStock) {
                                        $stockClass = 'badge-out-of-stock';
                                        $stockText = 'Out of Stock';
                                    } elseif ($stock <= $threshold) {
                                        $stockClass = 'badge-low-stock';
                                        $stockText = 'Low: ' . $stock;
                                    }
                                ?>
                                <div
                                    class="pos-card <?= $isOutOfStock ? 'out-of-stock' : '' ?>"
                                    data-id="<?= $prod['id'] ?>"
                                    data-name="<?= e($prod['name']) ?>"
                                    data-sku="<?= e($prod['sku']) ?>"
                                    data-barcode="<?= e($prod['barcode'] ?? '') ?>"
                                    data-price="<?= (float)$prod['selling_price'] ?>"
                                    data-tax="<?= (float)$prod['tax_percent'] ?>"
                                    data-stock="<?= $stock ?>"
                                    data-category="<?= $prod['category_id'] ?: 'none' ?>"
                                >
                                    <?php if (!empty($prod['image_path'])): ?>
                                        <img src="<?= asset($prod['image_path']) ?>" alt="<?= e($prod['name']) ?>" class="pos-card-thumb">
                                    <?php else: ?>
                                        <div class="pos-card-thumb">📦</div>
                                    <?php endif; ?>

                                    <div class="pos-card-title"><?= e($prod['name']) ?></div>
                                    <?php if ($prodVariants): ?>
                                        <div class="pos-card-variant-hint">Size / colour</div>
                                    <?php endif; ?>

                                    <div class="pos-card-meta">
                                        <span><?= e($prod['sku']) ?></span>
                                        <span class="badge <?= $stockClass ?> pos-card-stock"><?= e($stockText) ?></span>
                                    </div>

                                    <div class="pos-card-price-row">
                                        <span class="pos-card-price">₹<?= number_format((float)$prod['selling_price'], 2) ?></span>
                                        <span style="font-size: 11px; color: var(--saas-slate-400);">+<?= (float)$prod['tax_percent'] ?>% Tax</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- RIGHT / CART AREA: Register Cart, Customer & Checkout Actions -->
                    <div class="pos-cart-panel">
                        <!-- Customer Selection Bar -->
                        <div class="pos-customer-bar">
                            <select id="customerSelect" class="form-control pos-customer-select">
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === 1 ? 'selected' : '' ?>>
                                        <?= e($c['name']) ?> <?= $c['phone'] ? ' (' . e($c['phone']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="pos-btn-add-cust" id="openAddCustomerBtn" title="Add New Customer">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                                </svg>
                                <span>New</span>
                            </button>
                        </div>

                        <button type="button" class="pos-btn-add-product" id="openAddProductBtn" title="Add a product to the catalog">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>Add Product</span>
                        </button>

                        <!-- Active Cart Items List -->
                        <div class="pos-cart-items-container" id="posCartItemsList">
                            <div class="empty-state" style="padding: 30px 10px;" id="emptyCartState">
                                <div class="empty-state-icon" style="font-size: 32px; margin-bottom: 8px;">🛒</div>
                                <div style="font-weight: 700; color: var(--saas-navy-950); font-size: 14px;">Cart is empty</div>
                                <div style="font-size: 12px; color: var(--saas-slate-500); margin-top: 4px;">Click products or scan barcode to add</div>
                            </div>
                        </div>

                        <!-- Cart Summary & Calculations -->
                        <div class="pos-summary-box">
                            <div class="pos-summary-row">
                                <span>Subtotal</span>
                                <strong id="cartSubtotalText">₹0.00</strong>
                            </div>

                            <div class="pos-summary-row">
                                <span>Discount</span>
                                <div class="pos-discount-control">
                                    <input type="number" step="0.1" min="0" id="discountValueInput" value="0" class="pos-discount-input">
                                    <select id="discountTypeSelect" class="pos-discount-type-select">
                                        <option value="fixed">₹ (Fixed)</option>
                                        <option value="percent">% (Rate)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="pos-summary-row pos-promo-discount-row" id="promoDiscountRow" hidden>
                                <span>
                                    Promotion savings
                                    <small id="promoDiscountLabel" class="pos-promo-discount-label"></small>
                                </span>
                                <strong id="cartPromoDiscountText">− ₹0.00</strong>
                            </div>

                            <div class="pos-summary-row pos-coupon-row">
                                <span>Coupon</span>
                                <div class="pos-coupon-control" id="couponEntryWrap">
                                    <input type="text" id="couponCodeInput" class="pos-coupon-input" placeholder="Enter code" autocomplete="off" spellcheck="false">
                                    <button type="button" class="pos-coupon-apply-btn" id="applyCouponBtn">Apply</button>
                                </div>
                                <div class="pos-coupon-applied" id="couponAppliedWrap" hidden>
                                    <span class="pos-coupon-applied-code" id="couponAppliedCode"></span>
                                    <button type="button" class="pos-coupon-remove-btn" id="removeCouponBtn" title="Remove coupon">×</button>
                                </div>
                            </div>
                            <div class="pos-summary-row pos-coupon-discount-row" id="couponDiscountRow" hidden>
                                <span>Coupon savings</span>
                                <strong id="cartCouponDiscountText">− ₹0.00</strong>
                            </div>

                            <div class="pos-checkout-saved-strip" id="posCheckoutSavedStrip" hidden>
                                <span class="pos-checkout-saved-label">You saved</span>
                                <strong id="posCheckoutSavedAmount">₹0.00</strong>
                            </div>

                            <div class="pos-summary-row">
                                <span>Tax (GST)</span>
                                <strong id="cartTaxText">₹0.00</strong>
                            </div>

                            <div class="pos-summary-row grand-total-row">
                                <span>Payable Total</span>
                                <strong style="color: var(--saas-primary);" id="cartGrandTotalText">₹0.00</strong>
                            </div>
                        </div>

                        <!-- POS Action Buttons (Clear, Hold, Held Sales, Checkout) -->
                        <div class="pos-actions-grid">
                            <button type="button" class="pos-btn-action btn-clear" id="clearCartBtn" title="Clear current cart">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                <span>Clear</span>
                            </button>

                            <button type="button" class="pos-btn-action" id="holdSaleBtn" title="Save cart to hold queue">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span>Hold</span>
                            </button>

                            <button type="button" class="pos-btn-action" id="openHeldSalesBtn" title="View held carts">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span>Held (<span id="heldSalesCount"><?= count($heldSales) ?></span>)</span>
                            </button>

                            <button type="button" class="pos-btn-checkout" id="proceedToPaymentBtn" disabled>
                                <span>Proceed to Payment</span>
                                <span id="checkoutBtnTotal">₹0.00</span>
                            </button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- 1. CHECKOUT / PAYMENT MODAL -->
    <div class="modal-overlay" id="checkoutPaymentModal">
        <div class="modal-box" style="max-width: 520px;">
            <div class="modal-header">
                <h3 class="modal-title">Complete POS Checkout</h3>
                <button type="button" class="modal-close-btn" id="closePaymentModal">&times;</button>
            </div>

            <form id="checkoutForm" method="POST" action="<?= asset('pos.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="is_ajax" value="1">
                <input type="hidden" name="cart_json" id="hiddenCartJson" value="">
                <input type="hidden" name="customer_id" id="hiddenCustomerId" value="1">
                <input type="hidden" name="discount_value" id="hiddenDiscountValue" value="0">
                <input type="hidden" name="discount_type" id="hiddenDiscountType" value="fixed">
                <input type="hidden" name="coupon_id" id="hiddenCouponId" value="">
                <input type="hidden" name="coupon_code" id="hiddenCouponCode" value="">
                <input type="hidden" name="payment_method" id="hiddenPaymentMethod" value="cash">
                <input type="hidden" name="razorpay_order_id" id="hiddenRazorpayOrderId" value="">
                <input type="hidden" name="razorpay_payment_id" id="hiddenRazorpayPaymentId" value="">
                <input type="hidden" name="razorpay_signature" id="hiddenRazorpaySignature" value="">
                <input type="hidden" name="payment_splits_json" id="hiddenPaymentSplitsJson" value="">
                <input type="hidden" name="amount_tendered" id="hiddenAmountTendered" value="">
                <input type="hidden" name="outlet_id" id="hiddenOutletId" value="<?= (int) $posOutletId ?>">

                <div class="modal-body">
                    <!-- Grand Total Banner -->
                    <div style="background: var(--saas-primary-soft); padding: 14px 18px; border-radius: var(--saas-radius-md); border: 1px solid var(--saas-primary-light); text-align: center; margin-bottom: 18px;">
                        <div style="font-size: 13px; color: var(--saas-slate-600); font-weight: 600;">Total Payable Amount</div>
                        <div style="font-size: 32px; font-weight: 800; color: var(--saas-primary); margin-top: 2px;" id="modalPayableAmountText">₹0.00</div>
                    </div>

                    <!-- Payment Method Selectors -->
                    <label class="form-label" style="margin-bottom: 8px;">Select Payment Method <span style="color: #ef4444;">*</span></label>
                    <div class="payment-methods-grid">
                        <?php if (!empty($paymentOptions)): ?>
                            <?php foreach ($paymentOptions as $pIndex => $pOpt): 
                                $pModeLower = strtolower(str_replace(' ', '_', $pOpt['payment_mode']));
                                $pIcon = '💵';
                                if (stripos($pOpt['payment_mode'], 'card') !== false) $pIcon = '💳';
                                elseif (stripos($pOpt['payment_mode'], 'upi') !== false || stripos($pOpt['payment_mode'], 'pay') !== false) $pIcon = '📱';
                                elseif (stripos($pOpt['payment_mode'], 'credit') !== false) $pIcon = '📋';
                                elseif (stripos($pOpt['payment_mode'], 'loyalty') !== false) $pIcon = '🎁';
                                elseif (stripos($pOpt['payment_mode'], 'bank') !== false || stripos($pOpt['payment_mode'], 'cheque') !== false) $pIcon = '🏛️';
                            ?>
                                <div class="payment-method-card <?= $pIndex === 0 ? 'active' : '' ?>" data-method="<?= e($pModeLower) ?>" title="<?= e($pOpt['display_name']) ?>">
                                    <span style="font-size: 20px;"><?= $pIcon ?></span>
                                    <span><?= e($pOpt['display_name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="payment-method-card active" data-method="cash">
                                <span style="font-size: 20px;">💵</span>
                                <span>Cash</span>
                            </div>
                            <div class="payment-method-card" data-method="card">
                                <span style="font-size: 20px;">💳</span>
                                <span>Card / POS</span>
                            </div>
                            <div class="payment-method-card" data-method="upi">
                                <span style="font-size: 20px;">📱</span>
                                <span>UPI QR</span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($activeGateways)): ?>
                            <?php foreach ($activeGateways as $gwCode => $gw): 
                                $gwIcon = '📱';
                                if (in_array($gwCode, ['pinelabs', 'worldline'], true)) $gwIcon = '📟';
                                elseif (in_array($gwCode, ['stripe', 'verifone'], true)) $gwIcon = '💳';
                            ?>
                                <div class="payment-method-card" data-method="<?= e($gwCode) ?>" title="<?= e($gw['name']) ?> Gateway (Online/EDC)">
                                    <span style="font-size: 20px;"><?= $gwIcon ?></span>
                                    <span><?= e($gw['name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="payment-method-card" data-method="split" title="Pay using multiple methods">
                            <span style="font-size: 20px;">➗</span>
                            <span>Split Payment</span>
                        </div>
                    </div>

                    <div id="splitPaymentSection" class="pos-split-payment-section" style="display: none;">
                        <label class="form-label" style="margin-bottom: 8px;">Split across methods <span style="color: #ef4444;">*</span></label>
                        <p style="font-size: 12px; color: var(--saas-slate-500); margin: 0 0 10px;">Enter amounts for each method. Total must match the payable amount.</p>
                        <div id="splitPaymentRows" class="pos-split-rows"></div>
                        <button type="button" class="btn-secondary pos-split-add-btn" id="addSplitRowBtn" style="margin-top: 8px; padding: 6px 12px; font-size: 12px;">+ Add line</button>
                        <div class="pos-split-remain" id="splitPaymentRemain">Remaining: ₹0.00</div>
                    </div>

                    <!-- Cash Payment Calculator -->
                    <div id="cashTenderSection">
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label for="tenderedAmountInput" class="form-label">Cash Received / Tendered (₹) <span style="color: #ef4444;">*</span></label>
                            <input type="number" step="1" id="tenderedAmountInput" placeholder="Enter cash amount received from customer..." class="form-control" style="font-size: 16px; font-weight: 700;">
                        </div>

                        <div class="quick-cash-row">
                            <button type="button" class="quick-cash-btn" data-add="exact">Exact Total</button>
                            <button type="button" class="quick-cash-btn" data-add="100">+ ₹100</button>
                            <button type="button" class="quick-cash-btn" data-add="200">+ ₹200</button>
                            <button type="button" class="quick-cash-btn" data-add="500">+ ₹500</button>
                            <button type="button" class="quick-cash-btn" data-add="2000">+ ₹2,000</button>
                        </div>

                        <div class="change-due-box" id="changeDueBox">
                            <span>Change Due:</span>
                            <span class="change-due-val" id="changeDueValue">₹0.00</span>
                        </div>
                    </div>

                    <!-- Order Notes -->
                    <div class="form-group" style="margin-top: 14px;">
                        <label for="orderNotes" class="form-label">Order Notes / Counter Reference (Optional)</label>
                        <input type="text" id="orderNotes" name="notes" placeholder="e.g. Counter #1, Customer loyalty card" class="form-control">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelPaymentModal">Back to Cart</button>
                    <button type="submit" class="header-btn" style="border: 0; min-width: 140px;" id="confirmPaymentBtn">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span>Complete Sale</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. SALE COMPLETED / RECEIPT MODAL -->
    <div class="modal-overlay" id="saleCompletedModal">
        <div class="modal-box" style="max-width: 480px;">
            <div class="modal-header no-print">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="background: #ecfdf5; color: #047857; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800;">✓</div>
                    <h3 class="modal-title">Sale Completed</h3>
                </div>
                <button type="button" class="modal-close-btn" id="closeSaleCompletedModal">&times;</button>
            </div>

            <div class="modal-body" style="padding: 16px;">
                <!-- Printable Thermal Receipt Box -->
                <div id="printableReceiptArea" class="receipt-paper">
                    <div style="text-align: center; border-bottom: 1px dashed #cbd5e1; padding-bottom: 12px; margin-bottom: 12px;">
                        <div style="font-size: 16px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #111827;">OMINIFLOW POS</div>
                        <div style="font-size: 11px; color: #64748b; margin-top: 2px;">Official Retail Sales Receipt & Tax Invoice</div>
                        <a href="<?= asset('invoice-view.php') ?>" id="receiptInvoiceNumber" target="_blank" style="display: inline-block; font-size: 14px; font-weight: 800; color: var(--saas-primary); margin-top: 6px; text-decoration: none;">INV-00000000-0000</a>
                        <div style="font-size: 11px; color: #64748b;" id="receiptOrderNumber">Order #ORD-000000</div>
                        <div style="font-size: 11px; color: #64748b;" id="receiptTimestamp">Date Time</div>
                    </div>

                    <div style="font-size: 12px; margin-bottom: 12px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 8px;">
                        <div>Customer: <strong id="receiptCustomerName">Walk-in Customer</strong></div>
                        <div id="receiptCustomerPhoneRow" style="display: none;">Phone: <span id="receiptCustomerPhone"></span></div>
                        <div>Outlet: <strong id="receiptOutletName"><?= e($posOutletName) ?></strong></div>
                        <div>Cashier: <span id="receiptCashierName"><?= e($user['name'] ?? 'Cashier') ?></span></div>
                        <div>Payment Method: <strong style="text-transform: uppercase;" id="receiptPaymentMethod">CASH</strong> (Status: PAID)</div>
                    </div>

                    <table style="width: 100%; font-size: 12px; border-collapse: collapse; margin-bottom: 12px;">
                        <thead>
                            <tr style="border-bottom: 1px solid #94a3b8; text-align: left;">
                                <th style="padding: 4px 0;">Item</th>
                                <th style="padding: 4px 0; text-align: center;">Qty</th>
                                <th style="padding: 4px 0; text-align: right;">Price</th>
                                <th style="padding: 4px 0; text-align: right;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="receiptItemsBody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>

                    <div style="font-size: 12px; border-top: 1px dashed #cbd5e1; padding-top: 8px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 3px;">
                            <span>Subtotal:</span>
                            <span id="receiptSubtotal">₹0.00</span>
                        </div>
                        <div id="receiptDiscountRow" style="display: flex; justify-content: space-between; margin-bottom: 3px; color: #b91c1c;">
                            <span>Discount:</span>
                            <span id="receiptDiscount">− ₹0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 3px;">
                            <span>Tax (GST):</span>
                            <span id="receiptTax">₹0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 15px; font-weight: 800; margin-top: 6px; border-top: 1px solid #111827; padding-top: 6px;">
                            <span>GRAND TOTAL:</span>
                            <span id="receiptGrandTotal">₹0.00</span>
                        </div>
                        <div id="receiptCashDetails" style="margin-top: 6px; font-size: 11.5px; color: #475569;">
                            <div style="display: flex; justify-content: space-between;">
                                <span>Amount Received:</span>
                                <span id="receiptReceived">₹0.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-weight: 700; color: #047857;">
                                <span>Change Due:</span>
                                <span id="receiptChange">₹0.00</span>
                            </div>
                        </div>
                    </div>

                    <div style="text-align: center; margin-top: 14px; font-size: 11px; color: #64748b;">
                        Thank you for shopping with us!
                    </div>
                </div>

                <div id="receiptWhatsAppStatus" class="no-print" style="display: none; margin-top: 12px; padding: 10px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 650; line-height: 1.45;"></div>
            </div>

            <div class="modal-footer no-print" style="justify-content: space-between; gap: 8px; flex-wrap: wrap;">
                <button type="button" class="btn-secondary" id="printReceiptBtn" style="display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    <span>Print Thermal (80mm)</span>
                </button>

                <a href="<?= asset('invoice-view.php') ?>" class="btn-secondary" id="viewInvoiceLink" target="_blank" style="display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span>Tax Invoice (A4 / PDF)</span>
                </a>

                <button type="button" class="header-btn" id="newSaleBtn" style="border: 0;">
                    <span>+ New Sale</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 3. HELD SALES MODAL -->
    <div class="modal-overlay" id="heldSalesModal">
        <div class="modal-box" style="max-width: 600px;">
            <div class="modal-header">
                <h3 class="modal-title">Held Sales Queue</h3>
                <button type="button" class="modal-close-btn" id="closeHeldModal">&times;</button>
            </div>
            <div class="modal-body">
                <div id="heldSalesListContainer" style="display: flex; flex-direction: column; gap: 10px; max-height: 400px; overflow-y: auto;">
                    <?php if (empty($heldSales)): ?>
                        <div class="empty-state" style="padding: 30px;">
                            <div class="empty-state-icon">⏸️</div>
                            <div style="font-weight: 700; color: var(--saas-navy-950);">No held sales</div>
                            <div>Carts saved with the "Hold" button will be listed here.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($heldSales as $h): ?>
                            <div class="pos-cart-item" id="heldRow<?= $h['id'] ?>" style="flex-direction: row; align-items: center; justify-content: space-between;">
                                <div>
                                    <div style="font-weight: 700; color: var(--saas-navy-950);"><?= e($h['reference_note']) ?></div>
                                    <div style="font-size: 12px; color: var(--saas-slate-500); margin-top: 2px;">
                                        Customer: <strong><?= e($h['customer_name'] ?: 'Walk-in') ?></strong> • Saved at <?= date('h:i A', strtotime($h['created_at'])) ?>
                                    </div>
                                    <div style="font-size: 14px; font-weight: 800; color: var(--saas-primary); margin-top: 4px;">
                                        ₹<?= number_format((float)$h['total_amount'], 2) ?>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <button
                                        type="button"
                                        class="header-btn resume-held-btn"
                                        data-id="<?= $h['id'] ?>"
                                        data-customer="<?= $h['customer_id'] ?: 1 ?>"
                                        data-cart='<?= e($h['cart_json']) ?>'
                                        style="font-size: 12px; padding: 6px 12px; border: 0;"
                                    >
                                        Resume
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-action delete discard-held-btn"
                                        data-id="<?= $h['id'] ?>"
                                        title="Discard Held Cart"
                                    >
                                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelHeldModal">Close</button>
            </div>
        </div>
    </div>

    <!-- 4. ADD CUSTOMER MODAL -->
    <div class="modal-overlay" id="addCustomerModal">
        <div class="modal-box" style="max-width: 460px;">
            <div class="modal-header">
                <h3 class="modal-title">Add New Customer</h3>
                <button type="button" class="modal-close-btn" id="closeAddCustModal">&times;</button>
            </div>
            <form id="addCustomerForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_customer">
                <input type="hidden" name="is_ajax" value="1">

                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label for="custNameInput" class="form-label">Customer Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="custNameInput" name="name" required placeholder="e.g. John Doe" class="form-control">
                    </div>

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label for="custPhoneInput" class="form-label">Phone Number</label>
                        <input type="text" id="custPhoneInput" name="phone" placeholder="e.g. +91 9876543210" class="form-control">
                    </div>

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label for="custEmailInput" class="form-label">Email Address</label>
                        <input type="email" id="custEmailInput" name="email" placeholder="e.g. john@example.com" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="custAddressInput" class="form-label">Address / Notes</label>
                        <input type="text" id="custAddressInput" name="address" placeholder="City or location" class="form-control">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelAddCustModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0;">Create Customer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 5. ADD PRODUCT MODAL -->
    <div class="modal-overlay" id="addProductModal">
        <div class="modal-box" style="max-width: 460px;">
            <div class="modal-header">
                <h3 class="modal-title">Add Product</h3>
                <button type="button" class="modal-close-btn" id="closeAddProductModal">&times;</button>
            </div>
            <form id="addProductForm" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_product">
                <input type="hidden" name="is_ajax" value="1">

                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label for="posProdNameInput" class="form-label">Product Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="posProdNameInput" name="name" required maxlength="191" placeholder="e.g. WEST34" class="form-control">
                    </div>

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label for="posProdSkuInput" class="form-label">SKU <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="posProdSkuInput" name="sku" required maxlength="100" placeholder="e.g. WEST34" class="form-control">
                    </div>

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label for="posProdPriceInput" class="form-label">Selling Price (₹)</label>
                        <input type="number" id="posProdPriceInput" name="selling_price" min="0" step="0.01" value="0" class="form-control">
                    </div>

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label for="posProdStockInput" class="form-label">Opening Stock</label>
                        <input type="number" id="posProdStockInput" name="initial_stock" min="0" step="1" value="1" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="posProdImageInput" class="form-label">Product Image</label>
                        <input type="file" id="posProdImageInput" name="product_image" accept="image/jpeg,image/png,image/webp,image/jpg" class="form-control">
                        <div id="posProdImagePreviewWrap" class="pos-add-prod-preview" hidden>
                            <img id="posProdImagePreview" alt="Preview">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelAddProductModal">Cancel</button>
                    <button type="submit" class="header-btn" id="submitAddProductBtn" style="border: 0;">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 6. POS & HOME DAILY ALERTS MODAL (LOW STOCK & 7-DAY UNSOLD PRODUCTS) -->
    <?php require_once __DIR__ . '/includes/daily_alerts_modal.php'; ?>

    <!-- Size / colour picker. The register screen stays in place; this only opens when a product has variants. -->
    <div class="modal-overlay" id="variantPickerModal">
        <div class="modal-box" style="max-width: 460px;">
            <div class="modal-header">
                <h3 class="modal-title" id="variantPickerTitle">Choose size and colour</h3>
                <button type="button" class="modal-close-btn" id="closeVariantPicker">&times;</button>
            </div>
            <div class="modal-body">
                <div id="variantPickerSizeRow" style="margin-bottom: 14px;">
                    <span class="pos-variant-label">Size</span>
                    <div class="pos-variant-chips" id="variantPickerSizes"></div>
                </div>
                <div id="variantPickerColourRow" style="margin-bottom: 14px;">
                    <span class="pos-variant-label">Colour</span>
                    <div class="pos-variant-chips" id="variantPickerColours"></div>
                </div>
                <div id="variantPickerNameRow" style="margin-bottom: 14px; display: none;">
                    <span class="pos-variant-label">Variant</span>
                    <div class="pos-variant-chips" id="variantPickerNames"></div>
                </div>
                <div class="pos-variant-meta" id="variantPickerMeta">Select a size and colour.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelVariantPicker">Cancel</button>
                <button type="button" class="header-btn" id="confirmVariantPicker" style="border: 0;">Add to cart</button>
            </div>
        </div>
    </div>

    <!-- CSRF Token helper for JS -->
    <input type="hidden" id="pageCsrfToken" value="<?= csrf_token() ?>">

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Cart State: Array of { product_id, variant_id, name, sku, size, colour, price, tax_percent, quantity, max_stock }
            let cart = [];
            let currentCategory = 'all';
            const posVariants = <?= json_encode($posVariantsByProduct, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || {};
            let pendingVariantProduct = null;
            let appliedCoupon = null;
            let couponRevalidateTimer = null;
            let autoPromoDiscount = 0;
            let autoPromoLabel = '';
            let promoRecalcTimer = null;
            let summarySubtotalCache = 0;
            let summaryTaxCache = 0;

            // DOM Elements
            const productGrid = document.getElementById('posProductGrid');
            const searchInput = document.getElementById('productSearchInput');
            const barcodeInput = document.getElementById('barcodeInput');
            const cartItemsList = document.getElementById('posCartItemsList');
            const emptyCartState = document.getElementById('emptyCartState');
            const customerSelect = document.getElementById('customerSelect');
            const discountValInput = document.getElementById('discountValueInput');
            const discountTypeSelect = document.getElementById('discountTypeSelect');
            const cartSubtotalEl = document.getElementById('cartSubtotalText');
            const cartTaxEl = document.getElementById('cartTaxText');
            const cartGrandTotalEl = document.getElementById('cartGrandTotalText');
            const checkoutBtnTotalEl = document.getElementById('checkoutBtnTotal');
            const checkoutBtn = document.getElementById('proceedToPaymentBtn');
            const clearCartBtn = document.getElementById('clearCartBtn');
            const holdSaleBtn = document.getElementById('holdSaleBtn');
            const csrfToken = document.getElementById('pageCsrfToken').value;

            // Modals
            const paymentModal = document.getElementById('checkoutPaymentModal');
            const POS_SPLIT_PAY_METHODS = <?= json_encode($posSplitPayMethods, JSON_UNESCAPED_UNICODE) ?>;
            const splitPaymentSection = document.getElementById('splitPaymentSection');
            const splitPaymentRows = document.getElementById('splitPaymentRows');
            const splitPaymentRemain = document.getElementById('splitPaymentRemain');
            const hiddenPaymentSplitsJson = document.getElementById('hiddenPaymentSplitsJson');
            const hiddenAmountTendered = document.getElementById('hiddenAmountTendered');
            const addSplitRowBtn = document.getElementById('addSplitRowBtn');
            const closePaymentBtn = document.getElementById('closePaymentModal');
            const cancelPaymentBtn = document.getElementById('cancelPaymentModal');
            const checkoutForm = document.getElementById('checkoutForm');
            const confirmPaymentBtn = document.getElementById('confirmPaymentBtn');

            const saleCompletedModal = document.getElementById('saleCompletedModal');
            const closeSaleCompletedBtn = document.getElementById('closeSaleCompletedModal');
            const printReceiptBtn = document.getElementById('printReceiptBtn');
            const newSaleBtn = document.getElementById('newSaleBtn');
            const viewInOrdersLink = document.getElementById('viewInOrdersLink');

            const heldModal = document.getElementById('heldSalesModal');
            const openHeldBtn = document.getElementById('openHeldSalesBtn');
            const closeHeldBtn = document.getElementById('closeHeldModal');
            const cancelHeldBtn = document.getElementById('cancelHeldModal');

            const addCustModal = document.getElementById('addCustomerModal');
            const openAddCustBtn = document.getElementById('openAddCustomerBtn');
            const closeAddCustBtn = document.getElementById('closeAddCustModal');
            const cancelAddCustBtn = document.getElementById('cancelAddCustModal');
            const addCustForm = document.getElementById('addCustomerForm');

            // Payment Form Fields
            const modalPayableText = document.getElementById('modalPayableAmountText');
            const hiddenCartJson = document.getElementById('hiddenCartJson');
            const hiddenCustomerId = document.getElementById('hiddenCustomerId');
            const hiddenDiscountValue = document.getElementById('hiddenDiscountValue');
            const hiddenDiscountType = document.getElementById('hiddenDiscountType');
            const hiddenCouponId = document.getElementById('hiddenCouponId');
            const hiddenCouponCode = document.getElementById('hiddenCouponCode');
            const couponCodeInput = document.getElementById('couponCodeInput');
            const applyCouponBtn = document.getElementById('applyCouponBtn');
            const removeCouponBtn = document.getElementById('removeCouponBtn');
            const couponEntryWrap = document.getElementById('couponEntryWrap');
            const couponAppliedWrap = document.getElementById('couponAppliedWrap');
            const couponAppliedCode = document.getElementById('couponAppliedCode');
            const couponDiscountRow = document.getElementById('couponDiscountRow');
            const cartCouponDiscountEl = document.getElementById('cartCouponDiscountText');
            const promoDiscountRow = document.getElementById('promoDiscountRow');
            const cartPromoDiscountEl = document.getElementById('cartPromoDiscountText');
            const promoDiscountLabel = document.getElementById('promoDiscountLabel');
            const posCheckoutSavedStrip = document.getElementById('posCheckoutSavedStrip');
            const posCheckoutSavedAmount = document.getElementById('posCheckoutSavedAmount');
            const hiddenPaymentMethod = document.getElementById('hiddenPaymentMethod');
            const tenderedInput = document.getElementById('tenderedAmountInput');
            const changeDueVal = document.getElementById('changeDueValue');

            function variantsFor(productId) {
                return posVariants[productId] || posVariants[String(productId)] || [];
            }

            function uniqueVariantValues(list, key) {
                const out = [];
                list.forEach(function (row) {
                    const val = String(row[key] || '').trim();
                    if (val && out.indexOf(val) === -1) out.push(val);
                });
                return out;
            }

            function variantLabel(item) {
                const size = String(item.size || '').trim();
                const colour = String(item.colour || '').trim();
                if (size || colour) {
                    return (size ? 'Size: ' + size : '') + (size && colour ? ' · ' : '') + (colour ? 'Colour: ' + colour : '');
                }
                return String(item.variant_name || '').trim();
            }

            function cartLineKey(item) {
                return String(item.product_id) + ':' + String(item.variant_id || 0);
            }

            function cartSubtotalOnly() {
                let subtotal = 0;
                cart.forEach(function (item) {
                    subtotal += item.price * item.quantity;
                });
                return subtotal;
            }

            function manualDiscountAmount(subtotal) {
                const discVal = Math.max(0, parseFloat(discountValInput.value) || 0);
                const discType = discountTypeSelect.value;
                if (discType === 'percent') {
                    return subtotal * (Math.min(100, discVal) / 100);
                }
                return Math.min(subtotal, discVal);
            }

            function effectiveCouponDiscount(subtotal, manualDisc, promoDisc) {
                if (!appliedCoupon) return 0;
                const remaining = Math.max(0, subtotal - manualDisc - (promoDisc || 0));
                return Math.min(remaining, parseFloat(appliedCoupon.discount_amount) || 0);
            }

            function updateCartTotalsDisplay(subtotal, taxTotal) {
                summarySubtotalCache = subtotal;
                summaryTaxCache = taxTotal;
                const manualDisc = manualDiscountAmount(subtotal);
                const promoDisc = autoPromoDiscount;
                const couponDisc = effectiveCouponDiscount(subtotal, manualDisc, promoDisc);
                const totalDiscount = Math.min(subtotal, manualDisc + promoDisc + couponDisc);
                const grandTotal = Math.max(0, subtotal - totalDiscount + taxTotal);

                cartSubtotalEl.textContent = '₹' + subtotal.toFixed(2);
                cartTaxEl.textContent = '₹' + taxTotal.toFixed(2);

                if (promoDiscountRow && cartPromoDiscountEl) {
                    if (promoDisc > 0) {
                        promoDiscountRow.hidden = false;
                        cartPromoDiscountEl.textContent = '− ₹' + promoDisc.toFixed(2);
                        if (promoDiscountLabel) {
                            promoDiscountLabel.textContent = autoPromoLabel ? autoPromoLabel : '';
                        }
                    } else {
                        promoDiscountRow.hidden = true;
                        if (promoDiscountLabel) promoDiscountLabel.textContent = '';
                    }
                }

                if (couponDiscountRow && cartCouponDiscountEl) {
                    if (couponDisc > 0) {
                        couponDiscountRow.hidden = false;
                        cartCouponDiscountEl.textContent = '− ₹' + couponDisc.toFixed(2);
                    } else {
                        couponDiscountRow.hidden = true;
                    }
                }

                const checkoutSave = promoDisc + couponDisc;
                if (posCheckoutSavedStrip && posCheckoutSavedAmount) {
                    if (checkoutSave > 0) {
                        posCheckoutSavedStrip.hidden = false;
                        posCheckoutSavedAmount.textContent = '₹' + checkoutSave.toFixed(2);
                    } else {
                        posCheckoutSavedStrip.hidden = true;
                    }
                }

                cartGrandTotalEl.textContent = '₹' + grandTotal.toFixed(2);
                checkoutBtnTotalEl.textContent = '₹' + grandTotal.toFixed(2);
                checkoutBtn.disabled = false;
            }

            function scheduleAutoPromoRecalc(subtotal) {
                if (cart.length === 0) {
                    autoPromoDiscount = 0;
                    autoPromoLabel = '';
                    return;
                }
                if (promoRecalcTimer) clearTimeout(promoRecalcTimer);
                promoRecalcTimer = setTimeout(function () {
                    promoRecalcTimer = null;
                    const formData = new FormData();
                    formData.append('action', 'calc_cart_promotions');
                    formData.append('csrf_token', csrfToken);
                    formData.append('cart_json', JSON.stringify(cart));
                    formData.append('subtotal', String(subtotal));
                    fetch('<?= asset('pos.php') ?>', { method: 'POST', body: formData })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            autoPromoDiscount = parseFloat(data.total_discount) || 0;
                            const promos = data.applied_promotions || [];
                            autoPromoLabel = promos.map(function (p) { return p.name; }).filter(Boolean).join(', ');
                            updateCartTotalsDisplay(summarySubtotalCache, summaryTaxCache);
                            scheduleCouponRevalidate();
                        })
                        .catch(function () {
                            autoPromoDiscount = 0;
                            autoPromoLabel = '';
                        });
                }, 280);
            }

            function syncCouponHiddenFields() {
                if (hiddenCouponId) hiddenCouponId.value = appliedCoupon ? String(appliedCoupon.coupon_id) : '';
                if (hiddenCouponCode) hiddenCouponCode.value = appliedCoupon ? appliedCoupon.code : '';
            }

            function updateCouponUi() {
                const hasCoupon = !!appliedCoupon;
                if (couponEntryWrap) couponEntryWrap.hidden = hasCoupon;
                if (couponAppliedWrap) couponAppliedWrap.hidden = !hasCoupon;
                if (hasCoupon && couponAppliedCode) {
                    couponAppliedCode.textContent = appliedCoupon.code;
                }
                syncCouponHiddenFields();
            }

            function clearAppliedCoupon() {
                appliedCoupon = null;
                if (couponCodeInput) couponCodeInput.value = '';
                if (couponDiscountRow) couponDiscountRow.hidden = true;
                updateCouponUi();
            }

            function applyCouponFromCode(code, silent) {
                const trimmed = String(code || '').trim();
                if (trimmed === '') {
                    if (!silent) alert('Enter a coupon code.');
                    return Promise.resolve(false);
                }
                if (cart.length === 0) {
                    if (!silent) alert('Add items to the cart before applying a coupon.');
                    return Promise.resolve(false);
                }
                const subtotal = cartSubtotalOnly();
                if (applyCouponBtn) applyCouponBtn.disabled = true;
                const formData = new FormData();
                formData.append('action', 'validate_coupon');
                formData.append('csrf_token', csrfToken);
                formData.append('coupon_code', trimmed);
                formData.append('subtotal', String(subtotal));
                return fetch('<?= asset('pos.php') ?>', { method: 'POST', body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (applyCouponBtn) applyCouponBtn.disabled = false;
                        if (!data.valid) {
                            if (!silent) alert(data.error || 'Coupon could not be applied.');
                            if (silent) {
                                clearAppliedCoupon();
                                renderCart();
                            }
                            return false;
                        }
                        appliedCoupon = {
                            coupon_id: data.coupon_id,
                            code: data.code,
                            discount_amount: parseFloat(data.discount_amount) || 0
                        };
                        updateCouponUi();
                        renderCart();
                        return true;
                    })
                    .catch(function (err) {
                        if (applyCouponBtn) applyCouponBtn.disabled = false;
                        if (!silent) alert('Could not validate coupon: ' + err);
                        return false;
                    });
            }

            function scheduleCouponRevalidate() {
                if (!appliedCoupon) return;
                if (couponRevalidateTimer) clearTimeout(couponRevalidateTimer);
                couponRevalidateTimer = setTimeout(function () {
                    couponRevalidateTimer = null;
                    applyCouponFromCode(appliedCoupon.code, true);
                }, 350);
            }

            function productFromCard(card) {
                return {
                    product_id: parseInt(card.getAttribute('data-id'), 10),
                    name: card.getAttribute('data-name'),
                    sku: card.getAttribute('data-sku'),
                    barcode: card.getAttribute('data-barcode'),
                    price: parseFloat(card.getAttribute('data-price')),
                    tax_percent: parseFloat(card.getAttribute('data-tax')),
                    max_stock: parseInt(card.getAttribute('data-stock'), 10)
                };
            }

            function lineFromVariant(base, variant) {
                return {
                    product_id: base.product_id,
                    name: base.name,
                    sku: variant.sku || base.sku,
                    barcode: variant.barcode || base.barcode || '',
                    price: (parseFloat(variant.price) > 0) ? parseFloat(variant.price) : base.price,
                    tax_percent: base.tax_percent,
                    max_stock: parseInt(variant.stock, 10) || 0,
                    variant_id: parseInt(variant.id, 10),
                    size: variant.size || '',
                    colour: variant.colour || '',
                    variant_name: variant.name || ''
                };
            }

            // 1. Add Product to Cart with Stock Validation
            function addToCart(prod) {
                if (prod.max_stock <= 0) {
                    const label = variantLabel(prod) ? prod.name + ' (' + variantLabel(prod) + ')' : prod.name;
                    alert('Item "' + label + '" is out of stock and cannot be added.');
                    return;
                }

                const existingIndex = cart.findIndex(item => cartLineKey(item) === cartLineKey(prod));
                if (existingIndex > -1) {
                    if (cart[existingIndex].quantity < cart[existingIndex].max_stock) {
                        cart[existingIndex].quantity += 1;
                    } else {
                        alert('Only ' + cart[existingIndex].max_stock + ' unit(s) available for "' + prod.name + '".');
                        return;
                    }
                } else {
                    cart.push({
                        product_id: prod.product_id,
                        variant_id: prod.variant_id || 0,
                        name: prod.name,
                        sku: prod.sku,
                        size: prod.size || '',
                        colour: prod.colour || '',
                        variant_name: prod.variant_name || '',
                        price: prod.price,
                        tax_percent: prod.tax_percent,
                        quantity: 1,
                        max_stock: prod.max_stock
                    });
                }
                renderCart();
            }

            // 2. Render Cart & Calculate Totals
            function renderCart() {
                if (cart.length === 0) {
                    cartItemsList.innerHTML = '';
                    cartItemsList.appendChild(emptyCartState);
                    emptyCartState.style.display = 'block';
                    cartSubtotalEl.textContent = '₹0.00';
                    cartTaxEl.textContent = '₹0.00';
                    cartGrandTotalEl.textContent = '₹0.00';
                    checkoutBtnTotalEl.textContent = '₹0.00';
                    checkoutBtn.disabled = true;
                    clearAppliedCoupon();
                    autoPromoDiscount = 0;
                    autoPromoLabel = '';
                    if (promoDiscountRow) promoDiscountRow.hidden = true;
                    if (posCheckoutSavedStrip) posCheckoutSavedStrip.hidden = true;
                    return;
                }

                emptyCartState.style.display = 'none';
                cartItemsList.innerHTML = '';

                let subtotal = 0;
                let taxTotal = 0;

                cart.forEach((item, index) => {
                    const itemSubtotal = item.price * item.quantity;
                    const itemTax = itemSubtotal * (item.tax_percent / 100);
                    const itemLineTotal = itemSubtotal + itemTax;

                    subtotal += itemSubtotal;
                    taxTotal += itemTax;

                    const row = document.createElement('div');
                    row.className = 'pos-cart-item';
                    row.innerHTML = `
                        <div class="pos-cart-item-top">
                            <div>
                                <div class="pos-cart-item-name">${escapeHtml(item.name)}</div>
                                ${variantLabel(item) ? '<div class="pos-cart-item-variant">' + escapeHtml(variantLabel(item)) + '</div>' : ''}
                                <div class="pos-cart-item-sku">SKU: ${escapeHtml(item.sku)}</div>
                            </div>
                            <button type="button" class="pos-btn-remove-item" data-index="${index}" title="Remove Item">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                        <div class="pos-cart-item-bottom">
                            <div class="pos-stepper">
                                <button type="button" class="pos-stepper-btn btn-qty-dec" data-index="${index}">−</button>
                                <input type="number" min="1" max="${item.max_stock}" value="${item.quantity}" class="pos-stepper-input cart-qty-input" data-index="${index}">
                                <button type="button" class="pos-stepper-btn btn-qty-inc" data-index="${index}">+</button>
                            </div>
                            <div class="pos-item-price-calc">
                                <div class="pos-item-unit-price">₹${item.price.toFixed(2)} ea (+${item.tax_percent}%)</div>
                                <div class="pos-item-line-total">₹${itemLineTotal.toFixed(2)}</div>
                            </div>
                        </div>
                    `;
                    cartItemsList.appendChild(row);
                });

                updateCartTotalsDisplay(subtotal, taxTotal);
                scheduleAutoPromoRecalc(subtotal);
                scheduleCouponRevalidate();
            }

            function escapeHtml(str) {
                return (str + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            // 3. Cart Stepper & Remove Handlers
            cartItemsList.addEventListener('click', function (e) {
                const decBtn = e.target.closest('.btn-qty-dec');
                const incBtn = e.target.closest('.btn-qty-inc');
                const remBtn = e.target.closest('.pos-btn-remove-item');

                if (decBtn) {
                    const idx = parseInt(decBtn.getAttribute('data-index'), 10);
                    if (cart[idx].quantity > 1) {
                        cart[idx].quantity -= 1;
                    } else {
                        cart.splice(idx, 1);
                    }
                    renderCart();
                } else if (incBtn) {
                    const idx = parseInt(incBtn.getAttribute('data-index'), 10);
                    if (cart[idx].quantity < cart[idx].max_stock) {
                        cart[idx].quantity += 1;
                        renderCart();
                    } else {
                        alert('Only ' + cart[idx].max_stock + ' unit(s) available for "' + cart[idx].name + '".');
                    }
                } else if (remBtn) {
                    const idx = parseInt(remBtn.getAttribute('data-index'), 10);
                    cart.splice(idx, 1);
                    renderCart();
                }
            });

            cartItemsList.addEventListener('change', function (e) {
                const qtyInput = e.target.closest('.cart-qty-input');
                if (qtyInput) {
                    const idx = parseInt(qtyInput.getAttribute('data-index'), 10);
                    let val = parseInt(qtyInput.value, 10) || 1;
                    if (val > cart[idx].max_stock) {
                        alert('Only ' + cart[idx].max_stock + ' unit(s) available for "' + cart[idx].name + '".');
                        val = cart[idx].max_stock;
                    }
                    val = Math.max(1, val);
                    cart[idx].quantity = val;
                    renderCart();
                }
            });

            // Discount input handlers
            discountValInput.addEventListener('input', renderCart);
            discountTypeSelect.addEventListener('change', renderCart);

            if (applyCouponBtn) {
                applyCouponBtn.addEventListener('click', function () {
                    applyCouponFromCode(couponCodeInput ? couponCodeInput.value : '', false);
                });
            }
            if (couponCodeInput) {
                couponCodeInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        applyCouponFromCode(couponCodeInput.value, false);
                    }
                });
            }
            if (removeCouponBtn) {
                removeCouponBtn.addEventListener('click', function () {
                    clearAppliedCoupon();
                    renderCart();
                });
            }

            // Clear Cart Button
            clearCartBtn.addEventListener('click', function () {
                if (cart.length === 0) return;
                if (confirm('Are you sure you want to clear all items from the cart?')) {
                    cart = [];
                    discountValInput.value = '0';
                    clearAppliedCoupon();
                    renderCart();
                }
            });

            // 4. Product Card Click Handlers
            productGrid.addEventListener('click', function (e) {
                const card = e.target.closest('.pos-card');
                if (!card) return;

                if (card.classList.contains('out-of-stock')) {
                    alert('This item is currently out of stock and cannot be added.');
                    return;
                }

                const prod = productFromCard(card);
                const vars = variantsFor(prod.product_id);
                if (vars.length) {
                    openVariantPicker(prod);
                    return;
                }
                addToCart(prod);
            });

            // 5. Product Search Filtering (Search by Name, SKU, or Barcode)
            searchInput.addEventListener('input', function () {
                const query = this.value.trim().toLowerCase();
                const cards = productGrid.querySelectorAll('.pos-card');

                cards.forEach(card => {
                    const name = card.getAttribute('data-name').toLowerCase();
                    const sku = card.getAttribute('data-sku').toLowerCase();
                    const barcode = (card.getAttribute('data-barcode') || '').toLowerCase();
                    const catId = card.getAttribute('data-category');

                    const variantHit = variantsFor(card.getAttribute('data-id')).some(function (variant) {
                        const blob = ((variant.sku || '') + ' ' + (variant.barcode || '') + ' ' + (variant.size || '') + ' ' + (variant.colour || '') + ' ' + (variant.name || '')).toLowerCase();
                        return query !== '' && blob.includes(query);
                    });
                    const matchesSearch = (query === '' || name.includes(query) || sku.includes(query) || barcode.includes(query) || variantHit);
                    const matchesCategory = (currentCategory === 'all' || catId === currentCategory);

                    if (matchesSearch && matchesCategory) {
                        card.style.display = 'flex';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });

            // 6. Category Pill Filter Handlers
            document.querySelectorAll('.pos-cat-pill').forEach(pill => {
                pill.addEventListener('click', function () {
                    document.querySelectorAll('.pos-cat-pill').forEach(p => p.classList.remove('active'));
                    this.classList.add('active');
                    currentCategory = this.getAttribute('data-category');
                    searchInput.dispatchEvent(new Event('input'));
                });
            });

            // 7. Barcode Scanner Instant Input
            barcodeInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const code = this.value.trim();
                    if (code === '') return;

                    let matchedVariant = null;
                    let matchedProductId = 0;
                    Object.keys(posVariants).forEach(function (pid) {
                        (posVariants[pid] || []).forEach(function (variant) {
                            if ((variant.barcode && variant.barcode === code) || (variant.sku && variant.sku === code)) {
                                matchedVariant = variant;
                                matchedProductId = parseInt(pid, 10);
                            }
                        });
                    });

                    let foundCard = null;
                    const cards = productGrid.querySelectorAll('.pos-card');
                    cards.forEach(card => {
                        const cardId = parseInt(card.getAttribute('data-id'), 10);
                        if (matchedProductId && cardId === matchedProductId) {
                            foundCard = card;
                        } else if (!matchedVariant && (card.getAttribute('data-barcode') === code || card.getAttribute('data-sku') === code)) {
                            foundCard = card;
                        }
                    });

                    if (foundCard) {
                        const prod = productFromCard(foundCard);
                        if (matchedVariant) {
                            addToCart(lineFromVariant(prod, matchedVariant));
                        } else if (variantsFor(prod.product_id).length) {
                            openVariantPicker(prod);
                        } else {
                            addToCart(prod);
                        }
                        this.value = '';
                    } else {
                        alert('No active product found matching barcode/SKU: ' + code);
                        this.value = '';
                    }
                }
            });

            const variantModal = document.getElementById('variantPickerModal');
            const variantTitle = document.getElementById('variantPickerTitle');
            const variantSizeRow = document.getElementById('variantPickerSizeRow');
            const variantColourRow = document.getElementById('variantPickerColourRow');
            const variantNameRow = document.getElementById('variantPickerNameRow');
            const variantSizes = document.getElementById('variantPickerSizes');
            const variantColours = document.getElementById('variantPickerColours');
            const variantNames = document.getElementById('variantPickerNames');
            const variantMeta = document.getElementById('variantPickerMeta');
            const confirmVariantBtn = document.getElementById('confirmVariantPicker');

            function selectedVariantChoice() {
                const vars = variantsFor(pendingVariantProduct ? pendingVariantProduct.product_id : 0);
                const sizes = uniqueVariantValues(vars, 'size');
                const colours = uniqueVariantValues(vars, 'colour');
                if (!sizes.length && !colours.length) {
                    const chosen = variantNames.querySelector('.pos-variant-chip.on');
                    const id = chosen ? chosen.getAttribute('data-id') : '';
                    return vars.find(function (row) { return String(row.id) === String(id); }) || null;
                }
                const sizeChip = variantSizes.querySelector('.pos-variant-chip.on');
                const colourChip = variantColours.querySelector('.pos-variant-chip.on');
                const size = sizeChip ? sizeChip.getAttribute('data-value') : '';
                const colour = colourChip ? colourChip.getAttribute('data-value') : '';
                if (sizes.length && !size) return null;
                if (colours.length && !colour) return null;
                return vars.find(function (row) {
                    const sizeOk = !sizes.length || row.size === size;
                    const colourOk = !colours.length || row.colour === colour;
                    return sizeOk && colourOk;
                }) || null;
            }

            function paintVariantChips(container, values, keyName) {
                container.innerHTML = '';
                values.forEach(function (value) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'pos-variant-chip';
                    btn.setAttribute(keyName === 'id' ? 'data-id' : 'data-value', value.value);
                    btn.textContent = value.label;
                    container.appendChild(btn);
                });
            }

            function refreshVariantPicker() {
                const vars = variantsFor(pendingVariantProduct ? pendingVariantProduct.product_id : 0);
                const sizes = uniqueVariantValues(vars, 'size');
                const selectedSizeChip = variantSizes.querySelector('.pos-variant-chip.on');
                const selectedSize = selectedSizeChip ? selectedSizeChip.getAttribute('data-value') : '';
                variantSizes.querySelectorAll('.pos-variant-chip').forEach(function (chip) {
                    const size = chip.getAttribute('data-value');
                    const available = vars.some(function (row) { return row.size === size && row.stock > 0; });
                    chip.disabled = !available;
                    chip.classList.toggle('off', !available);
                    if (!available) chip.classList.remove('on');
                });
                variantNames.querySelectorAll('.pos-variant-chip').forEach(function (chip) {
                    const row = vars.find(function (item) { return String(item.id) === String(chip.getAttribute('data-id')); });
                    const available = !!(row && row.stock > 0);
                    chip.disabled = !available;
                    chip.classList.toggle('off', !available);
                    if (!available) chip.classList.remove('on');
                });
                variantColours.querySelectorAll('.pos-variant-chip').forEach(function (chip) {
                    const colour = chip.getAttribute('data-value');
                    const available = vars.some(function (row) {
                        return (!sizes.length || row.size === selectedSize) && row.colour === colour && row.stock > 0;
                    });
                    chip.disabled = !available;
                    chip.classList.toggle('off', !available);
                    if (!available) chip.classList.remove('on');
                });
                const chosen = selectedVariantChoice();
                if (!chosen) {
                    variantMeta.textContent = sizes.length || uniqueVariantValues(vars, 'colour').length
                        ? 'Select a size and colour.'
                        : 'Select a variant.';
                    confirmVariantBtn.disabled = true;
                    return;
                }
                const price = (parseFloat(chosen.price) > 0) ? parseFloat(chosen.price) : pendingVariantProduct.price;
                variantMeta.textContent = '₹' + price.toFixed(2) + ' · ' + chosen.stock + ' in stock' + (chosen.sku ? ' · ' + chosen.sku : '');
                confirmVariantBtn.disabled = chosen.stock <= 0;
            }

            function openVariantPicker(prod) {
                pendingVariantProduct = prod;
                const vars = variantsFor(prod.product_id);
                const sizes = uniqueVariantValues(vars, 'size');
                const colours = uniqueVariantValues(vars, 'colour');
                variantTitle.textContent = prod.name;
                variantSizeRow.style.display = sizes.length ? 'block' : 'none';
                variantColourRow.style.display = colours.length ? 'block' : 'none';
                variantNameRow.style.display = (!sizes.length && !colours.length) ? 'block' : 'none';
                paintVariantChips(variantSizes, sizes.map(function (value) { return { value: value, label: value }; }), 'value');
                paintVariantChips(variantColours, colours.map(function (value) { return { value: value, label: value }; }), 'value');
                paintVariantChips(variantNames, vars.map(function (row) {
                    return { value: String(row.id), label: row.name || row.sku || ('Variant ' + row.id) };
                }), 'id');

                const firstSize = vars.find(function (row) { return row.stock > 0 && row.size; });
                if (firstSize) {
                    const chip = variantSizes.querySelector('[data-value="' + CSS.escape(firstSize.size) + '"]');
                    if (chip) chip.classList.add('on');
                }
                refreshVariantPicker();
                const firstColour = vars.find(function (row) {
                    return row.stock > 0 && (!firstSize || row.size === firstSize.size) && row.colour;
                });
                if (firstColour) {
                    const chip = variantColours.querySelector('[data-value="' + CSS.escape(firstColour.colour) + '"]');
                    if (chip && !chip.disabled) chip.classList.add('on');
                }
                if (!sizes.length && !colours.length) {
                    const inStock = vars.find(function (row) { return row.stock > 0; }) || vars[0];
                    const chip = variantNames.querySelector('[data-id="' + inStock.id + '"]');
                    if (chip) chip.classList.add('on');
                }
                refreshVariantPicker();
                variantModal.classList.add('open');
            }

            function closeVariantPicker() {
                pendingVariantProduct = null;
                if (variantModal) variantModal.classList.remove('open');
            }

            [variantSizes, variantColours, variantNames].forEach(function (box) {
                if (!box) return;
                box.addEventListener('click', function (e) {
                    const chip = e.target.closest('.pos-variant-chip');
                    if (!chip || chip.disabled) return;
                    box.querySelectorAll('.pos-variant-chip').forEach(function (el) { el.classList.remove('on'); });
                    chip.classList.add('on');
                    refreshVariantPicker();
                });
            });

            document.getElementById('closeVariantPicker').addEventListener('click', closeVariantPicker);
            document.getElementById('cancelVariantPicker').addEventListener('click', closeVariantPicker);
            confirmVariantBtn.addEventListener('click', function () {
                const chosen = selectedVariantChoice();
                if (!pendingVariantProduct || !chosen) {
                    alert('Choose a size and colour before adding this item.');
                    return;
                }
                if ((parseInt(chosen.stock, 10) || 0) <= 0) {
                    alert('That size and colour is out of stock.');
                    return;
                }
                addToCart(lineFromVariant(pendingVariantProduct, chosen));
                closeVariantPicker();
            });

            // 8. Open Checkout & Payment Modal
            checkoutBtn.addEventListener('click', function () {
                if (cart.length === 0) return;

                hiddenCartJson.value = JSON.stringify(cart);
                hiddenCustomerId.value = customerSelect.value;
                hiddenDiscountValue.value = discountValInput.value;
                hiddenDiscountType.value = discountTypeSelect.value;
                syncCouponHiddenFields();

                modalPayableText.textContent = cartGrandTotalEl.textContent;
                tenderedInput.value = '';
                changeDueVal.textContent = '₹0.00';
                const rzpOid = document.getElementById('hiddenRazorpayOrderId');
                const rzpPid = document.getElementById('hiddenRazorpayPaymentId');
                const rzpSig = document.getElementById('hiddenRazorpaySignature');
                if (rzpOid) rzpOid.value = '';
                if (rzpPid) rzpPid.value = '';
                if (rzpSig) rzpSig.value = '';

                if (hiddenPaymentSplitsJson) hiddenPaymentSplitsJson.value = '';
                if (hiddenPaymentMethod.value === 'split') {
                    ensureDefaultSplitRows();
                }

                paymentModal.classList.add('open');
                if (hiddenPaymentMethod.value === 'split') {
                    updateSplitRemainder();
                } else {
                    tenderedInput.focus();
                }
            });

            function closePaymentModalFn() {
                paymentModal.classList.remove('open');
            }
            if (closePaymentBtn) closePaymentBtn.addEventListener('click', closePaymentModalFn);
            if (cancelPaymentBtn) cancelPaymentBtn.addEventListener('click', closePaymentModalFn);

            // Payment Method Selector
            const paymentMethodCards = document.querySelectorAll('.payment-method-card');
            const cashSection = document.getElementById('cashTenderSection');

            function getCheckoutTotal() {
                const totalStr = cartGrandTotalEl.textContent.replace('₹', '').replace(/,/g, '');
                return parseFloat(totalStr) || 0;
            }

            function buildSplitMethodOptions(selected) {
                return POS_SPLIT_PAY_METHODS.map(function (m) {
                    const sel = m.code === selected ? ' selected' : '';
                    return '<option value="' + m.code + '"' + sel + '>' + m.label + '</option>';
                }).join('');
            }

            function addSplitPaymentRow(methodCode, amountVal) {
                if (!splitPaymentRows) return;
                const row = document.createElement('div');
                row.className = 'pos-split-row';
                row.innerHTML =
                    '<select class="form-control pos-split-method">' + buildSplitMethodOptions(methodCode || 'cash') + '</select>' +
                    '<input type="number" step="0.01" min="0" class="form-control pos-split-amount" placeholder="Amount ₹" value="' + (amountVal !== undefined && amountVal !== null ? amountVal : '') + '">' +
                    '<button type="button" class="pos-split-remove" title="Remove line" aria-label="Remove">&times;</button>';
                splitPaymentRows.appendChild(row);
                row.querySelector('.pos-split-amount').addEventListener('input', function () {
                    updateSplitRemainder();
                    updateCashSectionForSplit();
                });
                row.querySelector('.pos-split-method').addEventListener('change', updateCashSectionForSplit);
                row.querySelector('.pos-split-remove').addEventListener('click', function () {
                    if (splitPaymentRows.querySelectorAll('.pos-split-row').length <= 1) return;
                    row.remove();
                    updateSplitRemainder();
                    updateCashSectionForSplit();
                });
            }

            function ensureDefaultSplitRows() {
                if (!splitPaymentRows || splitPaymentRows.children.length > 0) return;
                addSplitPaymentRow('cash', '');
                addSplitPaymentRow('upi', '');
            }

            function collectSplitPayments() {
                const rows = splitPaymentRows ? splitPaymentRows.querySelectorAll('.pos-split-row') : [];
                const out = [];
                rows.forEach(function (row) {
                    const method = row.querySelector('.pos-split-method')?.value || '';
                    const amount = parseFloat(row.querySelector('.pos-split-amount')?.value) || 0;
                    if (method && amount > 0) {
                        out.push({ method: method, amount: Math.round(amount * 100) / 100 });
                    }
                });
                return out;
            }

            function splitCashPortion(splits) {
                let cash = 0;
                (splits || []).forEach(function (s) {
                    if (s.method === 'cash') cash += s.amount;
                });
                return cash;
            }

            function updateSplitRemainder() {
                if (!splitPaymentRemain) return;
                const total = getCheckoutTotal();
                const splits = collectSplitPayments();
                const paid = splits.reduce(function (sum, s) { return sum + s.amount; }, 0);
                const remain = Math.round((total - paid) * 100) / 100;
                splitPaymentRemain.textContent = 'Remaining: ₹' + remain.toFixed(2);
                splitPaymentRemain.classList.toggle('is-balanced', Math.abs(remain) < 0.02);
                splitPaymentRemain.classList.toggle('is-over', remain < -0.01);
            }

            function updateCashSectionForSplit() {
                if (hiddenPaymentMethod.value !== 'split') return;
                const cashPart = splitCashPortion(collectSplitPayments());
                if (cashPart > 0) {
                    cashSection.style.display = 'block';
                } else {
                    cashSection.style.display = 'none';
                }
            }

            if (addSplitRowBtn) {
                addSplitRowBtn.addEventListener('click', function () {
                    addSplitPaymentRow('cash', '');
                    updateSplitRemainder();
                });
            }

            paymentMethodCards.forEach(card => {
                card.addEventListener('click', function () {
                    paymentMethodCards.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    const method = this.getAttribute('data-method');
                    hiddenPaymentMethod.value = method;

                    if (method === 'split') {
                        if (splitPaymentSection) splitPaymentSection.style.display = 'block';
                        ensureDefaultSplitRows();
                        updateSplitRemainder();
                        updateCashSectionForSplit();
                        if (hiddenPaymentSplitsJson) hiddenPaymentSplitsJson.value = '';
                    } else {
                        if (splitPaymentSection) splitPaymentSection.style.display = 'none';
                        if (hiddenPaymentSplitsJson) hiddenPaymentSplitsJson.value = '';
                        if (method === 'cash') {
                            cashSection.style.display = 'block';
                        } else {
                            cashSection.style.display = 'none';
                        }
                    }
                });
            });

            // Change Due Calculation
            function calculateChange() {
                const total = getCheckoutTotal();
                const tendered = parseFloat(tenderedInput.value) || 0;
                let due = total;
                if (hiddenPaymentMethod.value === 'split') {
                    const cashPart = splitCashPortion(collectSplitPayments());
                    if (cashPart > 0) due = cashPart;
                }
                const change = Math.max(0, tendered - due);
                changeDueVal.textContent = '₹' + change.toFixed(2);
            }
            tenderedInput.addEventListener('input', calculateChange);

            // Quick Cash Buttons
            document.querySelectorAll('.quick-cash-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const add = this.getAttribute('data-add');
                    const totalStr = cartGrandTotalEl.textContent.replace('₹', '').replace(/,/g, '');
                    const total = parseFloat(totalStr) || 0;

                    if (add === 'exact') {
                        tenderedInput.value = Math.ceil(total);
                    } else {
                        const current = parseFloat(tenderedInput.value) || 0;
                        tenderedInput.value = current + parseFloat(add);
                    }
                    calculateChange();
                });
            });

            // 9. Process Checkout Submission with Double-Click & Cash Validation
            checkoutForm.addEventListener('submit', function (e) {
                e.preventDefault();

                const total = getCheckoutTotal();
                const method = hiddenPaymentMethod.value;
                const formEl = this;

                const finishCheckout = function () {
                    confirmPaymentBtn.disabled = true;
                    confirmPaymentBtn.innerHTML = '<span>Processing Sale...</span>';

                    const formData = new FormData(formEl);

                    fetch('<?= asset('pos.php') ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        confirmPaymentBtn.disabled = false;
                        confirmPaymentBtn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Complete Sale</span>';

                        if (data.success) {
                            paymentModal.classList.remove('open');
                            showSaleCompletedModal(data);
                        } else {
                            alert('Checkout Error: ' + (data.errors ? Object.values(data.errors).join(', ') : (data.error || 'Could not complete sale.')));
                        }
                    })
                    .catch(err => {
                        confirmPaymentBtn.disabled = false;
                        confirmPaymentBtn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Complete Sale</span>';
                        alert('Network/API Error: ' + err);
                    });
                };

                if (method === 'split') {
                    const splits = collectSplitPayments();
                    const paid = splits.reduce(function (s, x) { return s + x.amount; }, 0);
                    if (splits.length < 1) {
                        alert('Add at least one payment line with an amount.');
                        return;
                    }
                    if (Math.abs(paid - total) > 0.02) {
                        alert('Split amounts (₹' + paid.toFixed(2) + ') must equal the payable total (₹' + total.toFixed(2) + ').');
                        return;
                    }
                    const cashPart = splitCashPortion(splits);
                    if (cashPart > 0) {
                        const tendered = parseFloat(tenderedInput.value) || 0;
                        if (tendered > 0 && tendered < cashPart) {
                            alert('Cash received (₹' + tendered.toFixed(2) + ') is less than the cash portion (₹' + cashPart.toFixed(2) + ').');
                            tenderedInput.focus();
                            return;
                        }
                    }
                    if (hiddenPaymentSplitsJson) hiddenPaymentSplitsJson.value = JSON.stringify(splits);
                    if (hiddenAmountTendered) hiddenAmountTendered.value = tenderedInput.value || '';
                } else {
                    if (hiddenPaymentSplitsJson) hiddenPaymentSplitsJson.value = '';
                    if (hiddenAmountTendered) hiddenAmountTendered.value = method === 'cash' ? (tenderedInput.value || '') : '';
                    if (method === 'cash') {
                        const tendered = parseFloat(tenderedInput.value) || 0;
                        if (tendered < total) {
                            alert('Amount received (₹' + tendered.toFixed(2) + ') is less than the payable amount (₹' + total.toFixed(2) + '). Please enter the full amount.');
                            tenderedInput.focus();
                            return;
                        }
                    }
                }

                if (method === 'split') {
                    finishCheckout();
                    return;
                }

                if (method === 'razorpay' && !document.getElementById('hiddenRazorpayPaymentId').value) {
                    confirmPaymentBtn.disabled = true;
                    confirmPaymentBtn.innerHTML = '<span>Opening Razorpay...</span>';
                    const rzpForm = new FormData();
                    rzpForm.append('csrf_token', '<?= e(csrf_token()) ?>');
                    rzpForm.append('action', 'create_razorpay_order');
                    rzpForm.append('amount', String(total.toFixed(2)));
                    fetch('<?= asset('pos.php') ?>', { method: 'POST', body: rzpForm })
                        .then(r => r.json())
                        .then(data => {
                            confirmPaymentBtn.disabled = false;
                            confirmPaymentBtn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Complete Sale</span>';
                            if (!data.success) {
                                alert(data.error || 'Could not start Razorpay checkout.');
                                return;
                            }
                            if (typeof Razorpay === 'undefined') {
                                alert('Razorpay checkout script failed to load. Please refresh and try again.');
                                return;
                            }
                            const rzp = new Razorpay({
                                key: data.key,
                                amount: data.amount,
                                currency: data.currency || 'INR',
                                name: data.name || 'OminiFlow POS',
                                description: 'POS Checkout',
                                order_id: data.order_id,
                                handler: function (resp) {
                                    document.getElementById('hiddenRazorpayOrderId').value = resp.razorpay_order_id || data.order_id;
                                    document.getElementById('hiddenRazorpayPaymentId').value = resp.razorpay_payment_id || '';
                                    document.getElementById('hiddenRazorpaySignature').value = resp.razorpay_signature || '';
                                    finishCheckout();
                                },
                                modal: {
                                    ondismiss: function () {
                                        confirmPaymentBtn.disabled = false;
                                    }
                                }
                            });
                            rzp.open();
                        })
                        .catch(err => {
                            confirmPaymentBtn.disabled = false;
                            confirmPaymentBtn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Complete Sale</span>';
                            alert('Razorpay error: ' + err);
                        });
                    return;
                }

                finishCheckout();
            });

            // 10. Display Sale Completed Modal / Receipt
            function showSaleCompletedModal(data) {
                document.getElementById('receiptOrderNumber').textContent = 'Order #' + data.order_number;
                document.getElementById('receiptTimestamp').textContent = data.created_at;
                document.getElementById('receiptCustomerName').textContent = data.customer_name || 'Walk-in Customer';

                const phoneRow = document.getElementById('receiptCustomerPhoneRow');
                const phoneSpan = document.getElementById('receiptCustomerPhone');
                if (data.customer_phone) {
                    phoneSpan.textContent = data.customer_phone;
                    phoneRow.style.display = 'block';
                } else {
                    phoneRow.style.display = 'none';
                }

                document.getElementById('receiptCashierName').textContent = data.cashier_name || 'Cashier';
                const receiptOutlet = document.getElementById('receiptOutletName');
                if (receiptOutlet) {
                    receiptOutlet.textContent = data.outlet_name || receiptOutlet.textContent || 'Outlet';
                }
                document.getElementById('receiptPaymentMethod').textContent = data.payment_method;

                // Populate Items
                const tbody = document.getElementById('receiptItemsBody');
                tbody.innerHTML = '';
                (data.items || []).forEach(it => {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px dotted #e2e8f0';
                    tr.innerHTML = `
                        <td style="padding: 6px 0;">
                            <div style="font-weight: 600;">${escapeHtml(it.product_name)}</div>
                            ${variantLabel({ size: it.size || '', colour: it.colour || '' }) ? '<div style="font-size: 10px; color: #475569;">' + escapeHtml(variantLabel({ size: it.size || '', colour: it.colour || '' })) + '</div>' : ''}
                            <div style="font-size: 10px; color: #64748b;">SKU: ${escapeHtml(it.product_sku)}</div>
                        </td>
                        <td style="padding: 6px 0; text-align: center;">${it.quantity}</td>
                        <td style="padding: 6px 0; text-align: right;">₹${parseFloat(it.unit_price).toFixed(2)}</td>
                        <td style="padding: 6px 0; text-align: right; font-weight: 700;">₹${parseFloat(it.line_total).toFixed(2)}</td>
                    `;
                    tbody.appendChild(tr);
                });

                document.getElementById('receiptSubtotal').textContent = '₹' + parseFloat(data.subtotal).toFixed(2);
                const discRow = document.getElementById('receiptDiscountRow');
                if (parseFloat(data.discount_amount) > 0) {
                    document.getElementById('receiptDiscount').textContent = '− ₹' + parseFloat(data.discount_amount).toFixed(2);
                    discRow.style.display = 'flex';
                } else {
                    discRow.style.display = 'none';
                }
                document.getElementById('receiptTax').textContent = '₹' + parseFloat(data.tax_amount).toFixed(2);
                document.getElementById('receiptGrandTotal').textContent = '₹' + parseFloat(data.total_amount).toFixed(2);

                const cashDetails = document.getElementById('receiptCashDetails');
                let cashPart = 0;
                if (Array.isArray(data.payment_splits) && data.payment_splits.length) {
                    data.payment_splits.forEach(function (sp) {
                        if (sp.method === 'cash') cashPart += parseFloat(sp.amount) || 0;
                    });
                }
                if (data.payment_method === 'cash' || cashPart > 0) {
                    const tendered = parseFloat(tenderedInput.value) || cashPart || parseFloat(data.total_amount);
                    const changeBase = cashPart > 0 ? cashPart : parseFloat(data.total_amount);
                    const change = Math.max(0, tendered - changeBase);
                    document.getElementById('receiptReceived').textContent = '₹' + tendered.toFixed(2);
                    document.getElementById('receiptChange').textContent = '₹' + change.toFixed(2);
                    cashDetails.style.display = 'block';
                } else {
                    cashDetails.style.display = 'none';
                }

                const invNumber = document.getElementById('receiptInvoiceNumber');
                const invoiceHref = data.invoice_id
                    ? '<?= asset('invoice-view.php?id=') ?>' + data.invoice_id
                    : (data.order_id ? '<?= asset('invoice-view.php?order_id=') ?>' + data.order_id : '');
                if (invNumber) {
                    invNumber.textContent = data.invoice_number || 'INV-PENDING';
                    if (invoiceHref) {
                        invNumber.href = invoiceHref;
                    }
                }
                const invLink = document.getElementById('viewInvoiceLink');
                if (invLink && invoiceHref) {
                    invLink.href = invoiceHref;
                }

                const waStatus = document.getElementById('receiptWhatsAppStatus');
                const applyWhatsAppInvoiceStatus = function (wa, phoneLabel) {
                    if (!waStatus) return;
                    wa = wa || {};
                    if (wa.pending) {
                        waStatus.style.display = 'block';
                        waStatus.style.background = '#eff6ff';
                        waStatus.style.color = '#1d4ed8';
                        waStatus.style.border = '1px solid #bfdbfe';
                        waStatus.textContent = 'Sending invoice PDF to WhatsApp…';
                        return;
                    }
                    if (wa.success) {
                        waStatus.style.display = 'block';
                        waStatus.style.background = '#ecfdf5';
                        waStatus.style.color = '#047857';
                        waStatus.style.border = '1px solid #a7f3d0';
                        waStatus.textContent = 'Invoice PDF sent to WhatsApp' + (phoneLabel ? ' ' + phoneLabel : '') + '.';
                    } else if (wa.skipped) {
                        const skipErr = String(wa.error || '');
                        const quietSkip = /disabled|already synced|payment state/i.test(skipErr);
                        if (quietSkip) {
                            waStatus.style.display = 'none';
                            waStatus.textContent = '';
                        } else {
                            waStatus.style.display = 'block';
                            waStatus.style.background = '#f8fafc';
                            waStatus.style.color = '#475569';
                            waStatus.style.border = '1px solid #e2e8f0';
                            waStatus.textContent = phoneLabel
                                ? ('Invoice not sent on WhatsApp: ' + (skipErr || 'skipped'))
                                : 'Invoice not sent on WhatsApp — add a customer mobile number to auto-send the PDF.';
                        }
                    } else if (wa.error) {
                        waStatus.style.display = 'block';
                        waStatus.style.background = '#fff7ed';
                        waStatus.style.color = '#9a3412';
                        waStatus.style.border = '1px solid #fed7aa';
                        waStatus.textContent = 'Sale completed. WhatsApp invoice could not be sent' + (wa.error ? ': ' + wa.error : '.');
                    } else {
                        waStatus.style.display = 'none';
                        waStatus.textContent = '';
                    }
                };

                const wa = data.whatsapp_invoice || {};
                const phoneLabel = wa.phone || data.customer_phone || '';
                applyWhatsAppInvoiceStatus(wa, phoneLabel);
                if (wa.pending && data.order_id) {
                    const waForm = new FormData();
                    waForm.append('action', 'send_order_invoice_whatsapp');
                    waForm.append('is_ajax', '1');
                    waForm.append('csrf_token', csrfToken);
                    waForm.append('order_id', String(data.order_id));
                    waForm.append('invoice_id', String(data.invoice_id || ''));
                    waForm.append('order_number', String(data.order_number || ''));
                    waForm.append('payment_status', String(data.payment_status || 'paid'));
                    waForm.append('customer_phone', String(data.customer_phone || ''));
                    fetch('<?= asset('pos.php') ?>', { method: 'POST', body: waForm })
                        .then(function (r) { return r.json(); })
                        .then(function (waRes) {
                            applyWhatsAppInvoiceStatus(waRes || {}, (waRes && waRes.phone) || phoneLabel);
                        })
                        .catch(function () {
                            applyWhatsAppInvoiceStatus(
                                { success: false, error: 'WhatsApp send may still be in progress — check the chat shortly.' },
                                phoneLabel
                            );
                        });
                }

                saleCompletedModal.classList.add('open');

                // Update product card stocks on the screen dynamically
                (data.items || []).forEach(it => {
                    const card = productGrid.querySelector(`.pos-card[data-id="${it.product_id}"]`);
                    if (card) {
                        const newStock = Math.max(0, parseInt(card.getAttribute('data-stock'), 10) - parseInt(it.quantity, 10));
                        card.setAttribute('data-stock', newStock);
                        const stockEl = card.querySelector('.pos-card-stock');
                        if (newStock <= 0) {
                            card.classList.add('out-of-stock');
                            stockEl.className = 'badge badge-out-of-stock pos-card-stock';
                            stockEl.textContent = 'Out of Stock';
                        } else {
                            stockEl.textContent = newStock + ' in stock';
                        }
                    }
                });
            }

            // Print only the thermal receipt (not the POS screen)
            function printThermalReceipt() {
                const area = document.getElementById('printableReceiptArea');
                if (!area) return;

                const prev = document.getElementById('posThermalPrintFrame');
                if (prev) prev.remove();

                const iframe = document.createElement('iframe');
                iframe.id = 'posThermalPrintFrame';
                iframe.setAttribute('aria-hidden', 'true');
                iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
                document.body.appendChild(iframe);

                const doc = iframe.contentWindow.document;
                doc.open();
                doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Receipt</title><style>' +
                    '@page { size: 80mm auto; margin: 3mm; }' +
                    'html,body{margin:0;padding:0;background:#fff;color:#000;font-family:\'Courier New\',Courier,monospace;font-size:12px;}' +
                    '.wrap{width:72mm;max-width:72mm;margin:0 auto;}' +
                    'table{width:100%;border-collapse:collapse;}' +
                    '</style></head><body><div class="wrap">' + area.innerHTML + '</div></body></html>');
                doc.close();

                const runPrint = function () {
                    try {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                    } catch (err) {
                        document.body.classList.add('pos-printing');
                        window.print();
                        document.body.classList.remove('pos-printing');
                    }
                    setTimeout(function () {
                        if (iframe.parentNode) iframe.remove();
                    }, 1500);
                };

                if (iframe.contentWindow.document.readyState === 'complete') {
                    setTimeout(runPrint, 50);
                } else {
                    iframe.onload = function () { setTimeout(runPrint, 50); };
                }
            }

            if (printReceiptBtn) {
                printReceiptBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    printThermalReceipt();
                });
            }

            // New Sale Button (Resets cart and closes receipt modal)
            function resetForNewSale() {
                saleCompletedModal.classList.remove('open');
                cart = [];
                discountValInput.value = '0';
                clearAppliedCoupon();
                renderCart();
                barcodeInput.focus();
            }
            if (newSaleBtn) newSaleBtn.addEventListener('click', resetForNewSale);
            if (closeSaleCompletedBtn) closeSaleCompletedBtn.addEventListener('click', resetForNewSale);

            // 11. Hold Sale Action
            holdSaleBtn.addEventListener('click', function () {
                if (cart.length === 0) {
                    alert('Cart is empty. Add products before placing on hold.');
                    return;
                }

                const ref = prompt('Enter a reference note for this held order:', 'Hold #' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
                if (ref === null) return;

                const formData = new FormData();
                formData.append('action', 'hold_sale');
                formData.append('is_ajax', '1');
                formData.append('csrf_token', csrfToken);
                formData.append('reference_note', ref || 'Hold Sale');
                formData.append('customer_id', customerSelect.value);
                formData.append('cart_json', JSON.stringify(cart));
                formData.append('subtotal', cartSubtotalEl.textContent.replace('₹', ''));
                formData.append('total_amount', cartGrandTotalEl.textContent.replace('₹', ''));

                fetch('<?= asset('pos.php') ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Order placed on hold successfully!');
                        cart = [];
                        discountValInput.value = '0';
                        clearAppliedCoupon();
                        renderCart();
                        location.reload(); // Reload to update held queue count
                    } else {
                        alert('Error: ' + (data.error || 'Could not hold sale'));
                    }
                })
                .catch(err => alert('Network error: ' + err));
            });

            // 12. Held Sales Modal & Resume / Discard Handlers
            if (openHeldBtn) openHeldBtn.addEventListener('click', () => heldModal.classList.add('open'));
            if (closeHeldBtn) closeHeldBtn.addEventListener('click', () => heldModal.classList.remove('open'));
            if (cancelHeldBtn) cancelHeldBtn.addEventListener('click', () => heldModal.classList.remove('open'));

            document.querySelectorAll('.resume-held-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const cartData = JSON.parse(this.getAttribute('data-cart') || '[]');
                    const custId = this.getAttribute('data-customer');
                    const heldId = this.getAttribute('data-id');

                    if (cart.length > 0 && !confirm('Replace current cart items with this held sale?')) {
                        return;
                    }

                    const needsVariant = [];
                    cart = (Array.isArray(cartData) ? cartData : []).filter(function (item) {
                        const vars = variantsFor(item.product_id);
                        if (vars.length && !item.variant_id) {
                            needsVariant.push(item.name || 'Item');
                            return false;
                        }
                        return true;
                    });
                    if (needsVariant.length) {
                        alert('Choose a size and colour again for: ' + needsVariant.join(', '));
                    }
                    if (custId) customerSelect.value = custId;
                    renderCart();
                    heldModal.classList.remove('open');

                    // Delete the resumed held record
                    const formData = new FormData();
                    formData.append('action', 'delete_held');
                    formData.append('is_ajax', '1');
                    formData.append('csrf_token', csrfToken);
                    formData.append('held_id', heldId);
                    fetch('<?= asset('pos.php') ?>', { method: 'POST', body: formData });

                    const row = document.getElementById('heldRow' + heldId);
                    if (row) row.remove();
                });
            });

            document.querySelectorAll('.discard-held-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const heldId = this.getAttribute('data-id');
                    if (!confirm('Discard this held cart?')) return;

                    const formData = new FormData();
                    formData.append('action', 'delete_held');
                    formData.append('is_ajax', '1');
                    formData.append('csrf_token', csrfToken);
                    formData.append('held_id', heldId);

                    fetch('<?= asset('pos.php') ?>', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            const row = document.getElementById('heldRow' + heldId);
                            if (row) row.remove();
                        }
                    });
                });
            });

            // 13. Add Customer Modal
            if (openAddCustBtn) openAddCustBtn.addEventListener('click', () => addCustModal.classList.add('open'));
            if (closeAddCustBtn) closeAddCustBtn.addEventListener('click', () => addCustModal.classList.remove('open'));
            if (cancelAddCustBtn) cancelAddCustBtn.addEventListener('click', () => addCustModal.classList.remove('open'));

            if (addCustForm) {
                addCustForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const formData = new FormData(this);

                    fetch('<?= asset('pos.php') ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            const opt = document.createElement('option');
                            opt.value = data.customer_id;
                            opt.textContent = document.getElementById('custNameInput').value + ' (' + (document.getElementById('custPhoneInput').value || 'N/A') + ')';
                            opt.selected = true;
                            customerSelect.appendChild(opt);
                            addCustModal.classList.remove('open');
                            addCustForm.reset();
                            alert('Customer created and selected!');
                        } else {
                            alert('Error: ' + (data.errors ? Object.values(data.errors).join(', ') : 'Could not add customer'));
                        }
                    })
                    .catch(err => alert('Network error: ' + err));
                });
            }

            // 14. Add Product Modal (catalog only — does not change cart)
            const addProdModal = document.getElementById('addProductModal');
            const openAddProdBtn = document.getElementById('openAddProductBtn');
            const closeAddProdBtn = document.getElementById('closeAddProductModal');
            const cancelAddProdBtn = document.getElementById('cancelAddProductModal');
            const addProdForm = document.getElementById('addProductForm');
            const addProdImageInput = document.getElementById('posProdImageInput');
            const addProdPreviewWrap = document.getElementById('posProdImagePreviewWrap');
            const addProdPreview = document.getElementById('posProdImagePreview');
            const submitAddProdBtn = document.getElementById('submitAddProductBtn');
            const allProductsPill = document.getElementById('allProductsPill');

            function resetAddProductForm() {
                if (addProdForm) addProdForm.reset();
                if (addProdPreview) addProdPreview.removeAttribute('src');
                if (addProdPreviewWrap) addProdPreviewWrap.hidden = true;
            }

            function prependPosProductCard(p) {
                if (!productGrid || !p) return;
                const stock = parseInt(p.stock_quantity, 10) || 0;
                const threshold = parseInt(p.low_stock_threshold, 10) || 5;
                const isOut = stock <= 0;
                let stockClass = 'badge-in-stock';
                let stockText = stock + ' in stock';
                if (isOut) {
                    stockClass = 'badge-out-of-stock';
                    stockText = 'Out of Stock';
                } else if (stock <= threshold) {
                    stockClass = 'badge-low-stock';
                    stockText = 'Low: ' + stock;
                }

                const price = parseFloat(p.selling_price) || 0;
                const tax = parseFloat(p.tax_percent) || 0;
                const card = document.createElement('div');
                card.className = 'pos-card' + (isOut ? ' out-of-stock' : '');
                card.setAttribute('data-id', String(p.id));
                card.setAttribute('data-name', p.name || '');
                card.setAttribute('data-sku', p.sku || '');
                card.setAttribute('data-barcode', p.barcode || '');
                card.setAttribute('data-price', String(price));
                card.setAttribute('data-tax', String(tax));
                card.setAttribute('data-stock', String(stock));
                card.setAttribute('data-category', String(p.category_id || 'none'));

                const thumb = p.image_url
                    ? '<img src="' + escapeHtml(p.image_url) + '" alt="' + escapeHtml(p.name || '') + '" class="pos-card-thumb">'
                    : '<div class="pos-card-thumb">📦</div>';

                card.innerHTML = thumb +
                    '<div class="pos-card-title">' + escapeHtml(p.name || '') + '</div>' +
                    '<div class="pos-card-meta">' +
                        '<span>' + escapeHtml(p.sku || '') + '</span>' +
                        '<span class="badge ' + stockClass + ' pos-card-stock">' + escapeHtml(stockText) + '</span>' +
                    '</div>' +
                    '<div class="pos-card-price-row">' +
                        '<span class="pos-card-price">₹' + price.toFixed(2) + '</span>' +
                        '<span style="font-size: 11px; color: var(--saas-slate-400);">+' + tax + '% Tax</span>' +
                    '</div>';

                productGrid.insertBefore(card, productGrid.firstChild);

                if (allProductsPill) {
                    const n = productGrid.querySelectorAll('.pos-card').length;
                    allProductsPill.textContent = 'All Products (' + n + ')';
                }
            }

            function closeAddProductModal() {
                if (addProdModal) addProdModal.classList.remove('open');
            }

            if (openAddProdBtn && addProdModal) {
                openAddProdBtn.addEventListener('click', () => addProdModal.classList.add('open'));
            }
            if (closeAddProdBtn) closeAddProdBtn.addEventListener('click', closeAddProductModal);
            if (cancelAddProdBtn) cancelAddProdBtn.addEventListener('click', closeAddProductModal);

            if (addProdImageInput) {
                addProdImageInput.addEventListener('change', function () {
                    const file = this.files && this.files[0];
                    if (!file) {
                        if (addProdPreview) addProdPreview.removeAttribute('src');
                        if (addProdPreviewWrap) addProdPreviewWrap.hidden = true;
                        return;
                    }
                    if (file.size > 5 * 1024 * 1024) {
                        alert('Image must be 5MB or smaller.');
                        this.value = '';
                        if (addProdPreviewWrap) addProdPreviewWrap.hidden = true;
                        return;
                    }
                    if (addProdPreview && addProdPreviewWrap) {
                        addProdPreview.src = URL.createObjectURL(file);
                        addProdPreviewWrap.hidden = false;
                    }
                });
            }

            if (addProdForm) {
                addProdForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const imgFile = addProdImageInput && addProdImageInput.files && addProdImageInput.files[0];
                    if (imgFile && imgFile.size > 5 * 1024 * 1024) {
                        alert('Image must be 5MB or smaller.');
                        return;
                    }
                    const formData = new FormData(this);
                    if (submitAddProdBtn) {
                        submitAddProdBtn.disabled = true;
                        submitAddProdBtn.textContent = 'Saving...';
                    }

                    fetch('<?= asset('pos.php') ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.text().then(text => {
                        let data;
                        try {
                            data = JSON.parse(text);
                        } catch (err) {
                            throw new Error('Session expired or server error. Please reload the page.');
                        }
                        return data;
                    }))
                    .then(data => {
                        if (submitAddProdBtn) {
                            submitAddProdBtn.disabled = false;
                            submitAddProdBtn.textContent = 'Save Product';
                        }
                        if (data.success) {
                            if (searchInput) searchInput.value = '';
                            if (data.product) {
                                prependPosProductCard(data.product);
                            }
                            if (allProductsPill) {
                                allProductsPill.click();
                            } else if (searchInput) {
                                searchInput.dispatchEvent(new Event('input'));
                            }
                            closeAddProductModal();
                            resetAddProductForm();
                            if (data.image_warning) {
                                alert(data.image_warning);
                            }
                        } else {
                            alert('Error: ' + (data.errors ? Object.values(data.errors).join(', ') : (data.error || 'Could not add product')));
                        }
                    })
                    .catch(err => {
                        if (submitAddProdBtn) {
                            submitAddProdBtn.disabled = false;
                            submitAddProdBtn.textContent = 'Save Product';
                        }
                        alert(err && err.message ? err.message : ('Network error: ' + err));
                    });
                });
            }
        });
    </script>
    <?php if (!empty($activeGateways['razorpay'])): ?>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <?php endif; ?>
</body>
</html>

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

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

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

    if ($action === 'checkout') {
        $cartJson = $_POST['cart_json'] ?? '[]';
        $cartItems = json_decode($cartJson, true) ?: [];
        $customerId = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : 1;
        $discountVal = (float) ($_POST['discount_value'] ?? 0.00);
        $discountType = (string) ($_POST['discount_type'] ?? 'fixed');
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
        $notes = (string) ($_POST['notes'] ?? '');
        $amountTendered = (float) ($_POST['amount_tendered'] ?? 0.00);
        $outletId = !empty($_POST['outlet_id']) ? (int)$_POST['outlet_id'] : 1;
        $clientOrderUuid = !empty($_POST['client_order_uuid']) ? (string)$_POST['client_order_uuid'] : null;
        $couponId = !empty($_POST['coupon_id']) ? (int)$_POST['coupon_id'] : null;
        $couponCode = !empty($_POST['coupon_code']) ? (string)$_POST['coupon_code'] : null;
        $loyaltyPoints = !empty($_POST['loyalty_points']) ? (int)$_POST['loyalty_points'] : 0;
        $loyaltyDiscount = !empty($_POST['loyalty_discount']) ? (float)$_POST['loyalty_discount'] : 0.00;
        $priceListId = !empty($_POST['price_list_id']) ? (int)$_POST['price_list_id'] : null;

        $result = process_pos_order(
            $cartItems, $customerId, $userId, $discountVal, $discountType, $paymentMethod,
            $notes, $amountTendered, $outletId, $clientOrderUuid, $couponId, $couponCode,
            $loyaltyPoints, $loyaltyDiscount, $priceListId
        );

        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }

        if ($result['success']) {
            set_flash('success', 'Order #' . $result['order_number'] . ' completed successfully! Total: ₹' . number_format($result['total_amount'], 2));
            redirect(APP_URL . '/orders.php?highlight=' . $result['order_id']);
        } else {
            $msg = implode(' ', $result['errors']);
            set_flash('error', $msg);
            redirect(APP_URL . '/pos.php');
        }
    } elseif ($action === 'validate_coupon') {
        require_once __DIR__ . '/includes/promotions_db.php';
        $couponCode = trim($_POST['coupon_code'] ?? '');
        $subtotal = (float)($_POST['subtotal'] ?? 0.00);
        $res = validate_and_apply_coupon($couponCode, $subtotal);
        header('Content-Type: application/json');
        echo json_encode($res);
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
    }
}

// Load Catalog Data for Terminal
$categories = get_categories('', 'active');
$products = get_products('', null, 'active');
$customers = get_customers();
$heldSales = get_held_sales();
$paymentOptions = get_payment_options('active');
$activeGateways = get_active_pos_payment_gateways();

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

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
</head>
<body>
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
                        </div>

                        <!-- Category Filter Pills -->
                        <div class="pos-category-pills" id="categoryPillRow">
                            <button type="button" class="pos-cat-pill active" data-category="all">All Products (<?= count($products) ?>)</button>
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
                                    $stock = (int) $prod['stock_quantity'];
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
                <input type="hidden" name="payment_method" id="hiddenPaymentMethod" value="cash">

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
                        <div style="font-size: 14px; font-weight: 800; color: var(--saas-primary); margin-top: 6px;" id="receiptInvoiceNumber">INV-00000000-0000</div>
                        <div style="font-size: 11px; color: #64748b;" id="receiptOrderNumber">Order #ORD-000000</div>
                        <div style="font-size: 11px; color: #64748b;" id="receiptTimestamp">Date Time</div>
                    </div>

                    <div style="font-size: 12px; margin-bottom: 12px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 8px;">
                        <div>Customer: <strong id="receiptCustomerName">Walk-in Customer</strong></div>
                        <div id="receiptCustomerPhoneRow" style="display: none;">Phone: <span id="receiptCustomerPhone"></span></div>
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

    <!-- CSRF Token helper for JS -->
    <input type="hidden" id="pageCsrfToken" value="<?= csrf_token() ?>">

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Cart State: Array of { product_id, name, sku, barcode, price, tax_percent, quantity, max_stock }
            let cart = [];
            let currentCategory = 'all';

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
            const hiddenPaymentMethod = document.getElementById('hiddenPaymentMethod');
            const tenderedInput = document.getElementById('tenderedAmountInput');
            const changeDueVal = document.getElementById('changeDueValue');

            // 1. Add Product to Cart with Stock Validation
            function addToCart(prod) {
                if (prod.max_stock <= 0) {
                    alert('Item "' + prod.name + '" is out of stock and cannot be added.');
                    return;
                }

                const existingIndex = cart.findIndex(item => item.product_id === prod.product_id);
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
                        name: prod.name,
                        sku: prod.sku,
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

                // Calculate Discount
                const discVal = Math.max(0, parseFloat(discountValInput.value) || 0);
                const discType = discountTypeSelect.value;
                let discountAmount = 0;

                if (discType === 'percent') {
                    discountAmount = subtotal * (Math.min(100, discVal) / 100);
                } else {
                    discountAmount = Math.min(subtotal, discVal);
                }

                const grandTotal = Math.max(0, subtotal - discountAmount + taxTotal);

                cartSubtotalEl.textContent = '₹' + subtotal.toFixed(2);
                cartTaxEl.textContent = '₹' + taxTotal.toFixed(2);
                cartGrandTotalEl.textContent = '₹' + grandTotal.toFixed(2);
                checkoutBtnTotalEl.textContent = '₹' + grandTotal.toFixed(2);
                checkoutBtn.disabled = false;
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

            // Clear Cart Button
            clearCartBtn.addEventListener('click', function () {
                if (cart.length === 0) return;
                if (confirm('Are you sure you want to clear all items from the cart?')) {
                    cart = [];
                    discountValInput.value = '0';
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

                const prod = {
                    product_id: parseInt(card.getAttribute('data-id'), 10),
                    name: card.getAttribute('data-name'),
                    sku: card.getAttribute('data-sku'),
                    barcode: card.getAttribute('data-barcode'),
                    price: parseFloat(card.getAttribute('data-price')),
                    tax_percent: parseFloat(card.getAttribute('data-tax')),
                    max_stock: parseInt(card.getAttribute('data-stock'), 10)
                };
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

                    const matchesSearch = (query === '' || name.includes(query) || sku.includes(query) || barcode.includes(query));
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

                    let foundCard = null;
                    const cards = productGrid.querySelectorAll('.pos-card');
                    cards.forEach(card => {
                        if (card.getAttribute('data-barcode') === code || card.getAttribute('data-sku') === code) {
                            foundCard = card;
                        }
                    });

                    if (foundCard) {
                        const prod = {
                            product_id: parseInt(foundCard.getAttribute('data-id'), 10),
                            name: foundCard.getAttribute('data-name'),
                            sku: foundCard.getAttribute('data-sku'),
                            barcode: foundCard.getAttribute('data-barcode'),
                            price: parseFloat(foundCard.getAttribute('data-price')),
                            tax_percent: parseFloat(foundCard.getAttribute('data-tax')),
                            max_stock: parseInt(foundCard.getAttribute('data-stock'), 10)
                        };
                        addToCart(prod);
                        this.value = '';
                    } else {
                        alert('No active product found matching barcode/SKU: ' + code);
                        this.value = '';
                    }
                }
            });

            // 8. Open Checkout & Payment Modal
            checkoutBtn.addEventListener('click', function () {
                if (cart.length === 0) return;

                hiddenCartJson.value = JSON.stringify(cart);
                hiddenCustomerId.value = customerSelect.value;
                hiddenDiscountValue.value = discountValInput.value;
                hiddenDiscountType.value = discountTypeSelect.value;

                modalPayableText.textContent = cartGrandTotalEl.textContent;
                tenderedInput.value = '';
                changeDueVal.textContent = '₹0.00';

                paymentModal.classList.add('open');
                tenderedInput.focus();
            });

            function closePaymentModalFn() {
                paymentModal.classList.remove('open');
            }
            if (closePaymentBtn) closePaymentBtn.addEventListener('click', closePaymentModalFn);
            if (cancelPaymentBtn) cancelPaymentBtn.addEventListener('click', closePaymentModalFn);

            // Payment Method Selector
            const paymentMethodCards = document.querySelectorAll('.payment-method-card');
            const cashSection = document.getElementById('cashTenderSection');

            paymentMethodCards.forEach(card => {
                card.addEventListener('click', function () {
                    paymentMethodCards.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    const method = this.getAttribute('data-method');
                    hiddenPaymentMethod.value = method;

                    if (method === 'cash') {
                        cashSection.style.display = 'block';
                    } else {
                        cashSection.style.display = 'none';
                    }
                });
            });

            // Change Due Calculation
            function calculateChange() {
                const totalStr = cartGrandTotalEl.textContent.replace('₹', '').replace(/,/g, '');
                const total = parseFloat(totalStr) || 0;
                const tendered = parseFloat(tenderedInput.value) || 0;
                const change = Math.max(0, tendered - total);
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

                const totalStr = cartGrandTotalEl.textContent.replace('₹', '').replace(/,/g, '');
                const total = parseFloat(totalStr) || 0;
                const method = hiddenPaymentMethod.value;

                if (method === 'cash') {
                    const tendered = parseFloat(tenderedInput.value) || 0;
                    if (tendered < total) {
                        alert('Amount received (₹' + tendered.toFixed(2) + ') is less than the payable amount (₹' + total.toFixed(2) + '). Please enter the full amount.');
                        tenderedInput.focus();
                        return;
                    }
                }

                // Prevent double clicking
                confirmPaymentBtn.disabled = true;
                confirmPaymentBtn.innerHTML = '<span>Processing Sale...</span>';

                const formData = new FormData(this);

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
            });

            // 10. Display Sale Completed Modal / Receipt
            function showSaleCompletedModal(data) {
                if (document.getElementById('receiptInvoiceNumber')) {
                    document.getElementById('receiptInvoiceNumber').textContent = data.invoice_number || 'INV-PENDING';
                }
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
                if (data.payment_method === 'cash') {
                    const tendered = parseFloat(tenderedInput.value) || parseFloat(data.total_amount);
                    const change = Math.max(0, tendered - parseFloat(data.total_amount));
                    document.getElementById('receiptReceived').textContent = '₹' + tendered.toFixed(2);
                    document.getElementById('receiptChange').textContent = '₹' + change.toFixed(2);
                    cashDetails.style.display = 'block';
                } else {
                    cashDetails.style.display = 'none';
                }

                const invLink = document.getElementById('viewInvoiceLink');
                if (invLink && data.invoice_id) {
                    invLink.href = '<?= asset('invoice-view.php?id=') ?>' + data.invoice_id;
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

            // Print Receipt Button
            if (printReceiptBtn) {
                printReceiptBtn.addEventListener('click', function () {
                    window.print();
                });
            }

            // New Sale Button (Resets cart and closes receipt modal)
            function resetForNewSale() {
                saleCompletedModal.classList.remove('open');
                cart = [];
                discountValInput.value = '0';
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

                    cart = cartData;
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
        });
    </script>
</body>
</html>

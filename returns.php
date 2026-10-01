<?php
/**
 * OminiFlow POS - Returns & Refunds Management Screen
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/orders_db.php';
require_once __DIR__ . '/includes/products_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

// Handle AJAX actions (Fetch Returnable Items, Process Return)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid session token.']);
            exit;
        }
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/returns.php');
    }

    if ($action === 'get_order_items') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $order = get_order_by_id($orderId);
        if (!$order) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            exit;
        }

        $items = get_returnable_order_items($orderId);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items,
        ]);
        exit;
    } elseif ($action === 'process_return') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $refundMethod = (string) ($_POST['refund_method'] ?? 'cash');
        $reason = (string) ($_POST['reason'] ?? 'Customer Return');
        $notes = (string) ($_POST['notes'] ?? '');
        $returnItemsJson = (string) ($_POST['return_items_json'] ?? '[]');
        $returnItems = json_decode($returnItemsJson, true) ?: [];

        $res = process_pos_return($orderId, $returnItems, $refundMethod, $reason, $notes, $userId);

        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }

        if ($res['success']) {
            set_flash('success', 'Return #' . $res['return_number'] . ' processed successfully! Refund Amount: ₹' . number_format((float)$res['refund_amount'], 2));
        } else {
            set_flash('error', $res['error'] ?? 'Could not process return.');
        }
        redirect(APP_URL . '/returns.php');
    } elseif ($action === 'process_exchange') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $settlementMethod = (string) ($_POST['refund_method'] ?? 'cash');
        $reason = (string) ($_POST['reason'] ?? 'Size / Variant Exchange');
        $notes = (string) ($_POST['notes'] ?? '');
        $returnItemsJson = (string) ($_POST['return_items_json'] ?? '[]');
        $exchangeItemsJson = (string) ($_POST['exchange_items_json'] ?? '[]');
        $returnItems = json_decode($returnItemsJson, true) ?: [];
        $exchangeItems = json_decode($exchangeItemsJson, true) ?: [];

        $res = process_pos_exchange($orderId, $returnItems, $exchangeItems, $settlementMethod, $reason, $notes, $userId);

        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }

        if ($res['success']) {
            set_flash('success', 'Exchange #' . $res['return_number'] . ' completed on bill ' . $res['order_number'] . '.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not process exchange.');
        }
        redirect(APP_URL . '/returns.php');
    }
}

// Search & Filter Parameters
$search = trim($_GET['search'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$returns = get_returns($search, $dateFrom, $dateTo, 100);
$salesStats = get_sales_stats();
$recentOrders = get_orders('', 'completed', '', '', 50);
$exchangeCatalog = [];
foreach (get_products('', null, 'active') as $catProd) {
    $exchangeCatalog[] = [
        'id' => (int) $catProd['id'],
        'name' => (string) $catProd['name'],
        'sku' => (string) $catProd['sku'],
        'barcode' => (string) ($catProd['barcode'] ?? ''),
        'price' => (float) $catProd['selling_price'],
        'tax' => (float) $catProd['tax_percent'],
        'stock' => (int) $catProd['stock_quantity'],
    ];
}

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returns & Refunds - OminiFlow POS</title>

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

        <!-- Main Content Area -->
        <div class="app-main">
            <!-- Header Component -->
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <!-- Page Top Header -->
                <div class="page-top-header">
                    <div>
                        <h1 class="page-title">Returns & Refunds</h1>
                        <p class="page-subtitle">Process refunds or exchange items on the same bill, and restore or deduct stock safely.</p>
                    </div>
                    <div class="page-top-actions">
                        <button type="button" class="header-btn" id="openNewReturnBtn">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H4m0 0l3-3m-3 3l3 3m5 4v1a3 3 0 003 3h6a3 3 0 003-3V7a3 3 0 00-3-3h-6a3 3 0 00-3 3v1"/>
                            </svg>
                            <span>Process New Return</span>
                        </button>
                    </div>
                </div>

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

                <!-- Metric KPI Cards -->
                <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 24px;">
                    <div class="kpi-card">
                        <div class="kpi-header">
                            <div class="kpi-title">Total Return Transactions</div>
                            <div class="kpi-icon-badge" style="background: var(--saas-primary-soft); color: var(--saas-primary);">↩️</div>
                        </div>
                        <div class="kpi-value"><?= $salesStats['total_returns'] ?></div>
                        <div class="kpi-trend" style="color: var(--saas-slate-500);">
                            <span>Completed customer returns</span>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <div class="kpi-title">Total Refunded Amount</div>
                            <div class="kpi-icon-badge" style="background: #fef2f2; color: #b91c1c;">💸</div>
                        </div>
                        <div class="kpi-value" style="color: #b91c1c;">₹<?= number_format($salesStats['total_refunded'], 2) ?></div>
                        <div class="kpi-trend" style="color: var(--saas-slate-500);">
                            <span>Cash, UPI & Card settlements</span>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <div class="kpi-title">Net Sales Revenue</div>
                            <div class="kpi-icon-badge" style="background: #ecfdf5; color: #047857;">💰</div>
                        </div>
                        <div class="kpi-value">₹<?= number_format(max(0, $salesStats['total_revenue'] - $salesStats['total_refunded']), 2) ?></div>
                        <div class="kpi-trend" style="color: #047857;">
                            <span>After refund adjustments</span>
                        </div>
                    </div>
                </div>

                <!-- Search & Filters Toolbar -->
                <div class="filter-toolbar">
                    <form method="GET" action="<?= asset('returns.php') ?>" class="filter-form" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; width: 100%;">
                        <div class="search-input-wrap" style="flex: 1; min-width: 240px;">
                            <span class="search-icon">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </span>
                            <input
                                type="text"
                                name="search"
                                value="<?= e($search) ?>"
                                placeholder="Search by Return #, Order #, Customer, Phone..."
                                class="form-control with-icon"
                            >
                        </div>

                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="form-control" title="Date From" style="width: 140px;">
                            <span style="color: var(--saas-slate-400); font-size: 12px;">to</span>
                            <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="form-control" title="Date To" style="width: 140px;">
                        </div>

                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="header-btn" style="padding: 8px 16px;">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                                </svg>
                                <span>Filter</span>
                            </button>

                            <?php if ($search !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                                <a href="<?= asset('returns.php') ?>" class="btn-secondary" style="padding: 8px 14px;">Reset</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Returns History Table Card -->
                <div class="section-card" style="margin-top: 18px;">
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Return #</th>
                                    <th>Original Order #</th>
                                    <th>Invoice #</th>
                                    <th>Customer</th>
                                    <th>Date & Time</th>
                                    <th>Items</th>
                                    <th>Refund Amount</th>
                                    <th>Method</th>
                                    <th>Reason</th>
                                    <th style="text-align: right;">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($returns)): ?>
                                    <tr>
                                        <td colspan="10">
                                            <div class="empty-state">
                                                <div class="empty-state-icon">↩️</div>
                                                <div style="font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">No Returns Recorded</div>
                                                <div><?= ($search !== '' || $dateFrom !== '') ? 'No return records matched your filters.' : 'Click "+ Process New Return" to handle customer returns and refunds.' ?></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($returns as $ret): ?>
                                        <tr>
                                            <td>
                                                <strong style="font-family: monospace; color: #b91c1c; font-size: 13.5px;">
                                                    <?= e($ret['return_number']) ?>
                                                </strong>
                                                <?php if (($ret['return_type'] ?? 'refund') === 'exchange'): ?>
                                                    <span class="badge badge-info" style="margin-left: 6px; font-size: 10px;">Exchange</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="<?= asset('orders.php?search=' . urlencode($ret['order_number'])) ?>" style="font-weight: 600; color: var(--saas-primary); text-decoration: none;">
                                                    <?= e($ret['order_number']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <?php if (!empty($ret['invoice_id'])): ?>
                                                    <a href="<?= asset('invoice-view.php?id=' . $ret['invoice_id']) ?>" style="font-weight: 600; color: #1e3a8a; text-decoration: none;">
                                                        <?= e($ret['invoice_number']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8; font-size: 12px;">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 600; color: var(--saas-navy-950);"><?= e($ret['customer_name'] ?: 'Walk-in Customer') ?></div>
                                                <?php if (!empty($ret['customer_phone'])): ?>
                                                    <div style="font-size: 11px; color: var(--saas-slate-400);"><?= e($ret['customer_phone']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 600; color: var(--saas-navy-900);"><?= date('M d, Y', strtotime($ret['created_at'])) ?></div>
                                                <div style="font-size: 11px; color: var(--saas-slate-400);"><?= date('h:i A', strtotime($ret['created_at'])) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge badge-info"><?= (int)$ret['items_count'] ?> item(s)</span>
                                            </td>
                                            <td>
                                                <strong style="color: #b91c1c; font-size: 14px;">
                                                    ₹<?= number_format((float)$ret['refund_amount'], 2) ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary" style="text-transform: uppercase; font-size: 11px;">
                                                    <?= e($ret['refund_method']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="font-size: 12.5px; color: var(--saas-slate-600);"><?= e($ret['reason']) ?></span>
                                            </td>
                                            <td style="text-align: right;">
                                                <button
                                                    type="button"
                                                    class="btn-action view view-return-trigger"
                                                    data-id="<?= $ret['id'] ?>"
                                                    title="View Return Details"
                                                >
                                                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- 1. PROCESS NEW RETURN MODAL -->
    <div class="modal-overlay" id="newReturnModal">
        <div class="modal-box" style="max-width: 680px;">
            <div class="modal-header">
                <h3 class="modal-title" id="returnModalTitle">Process Customer Return & Refund</h3>
                <button type="button" class="modal-close-btn" id="closeNewReturnModal">&times;</button>
            </div>
            <form id="processReturnForm" method="POST" action="<?= asset('returns.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="returnFormAction" value="process_return">
                <input type="hidden" name="is_ajax" value="1">
                <input type="hidden" name="return_items_json" id="returnItemsJson" value="[]">
                <input type="hidden" name="exchange_items_json" id="exchangeItemsJson" value="[]">

                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label">Transaction type</label>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600;">
                                <input type="radio" name="return_mode" value="refund" id="returnModeRefund" checked> Return & refund
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600;">
                                <input type="radio" name="return_mode" value="exchange" id="returnModeExchange"> Exchange on same bill
                            </label>
                        </div>
                    </div>

                    <!-- Step 1: Select Order -->
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="returnOrderSelect" class="form-label">Select Original Sale Order <span style="color: #ef4444;">*</span></label>
                        <select id="returnOrderSelect" name="order_id" required class="form-control">
                            <option value="">-- Choose Completed Sale Order --</option>
                            <?php foreach ($recentOrders as $ro): ?>
                                <option value="<?= $ro['id'] ?>">
                                    <?= e($ro['order_number']) ?> — <?= e($ro['customer_name'] ?: 'Walk-in') ?> (₹<?= number_format((float)$ro['total_amount'], 2) ?> • <?= date('M d', strtotime($ro['created_at'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Step 2: Order Items Selection Box -->
                    <div id="returnItemsSection" style="display: none;">
                        <label class="form-label">Select Items & Quantities to Return <span style="color: #ef4444;">*</span></label>
                        <div style="border: 1px solid var(--saas-border); border-radius: var(--saas-radius-md); overflow: hidden; margin-bottom: 16px;">
                            <table style="width: 100%; font-size: 12.5px; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: var(--saas-slate-50); border-bottom: 1px solid var(--saas-border);">
                                        <th style="padding: 8px 10px; text-align: left;">Product</th>
                                        <th style="padding: 8px 10px; text-align: right;">Unit Price</th>
                                        <th style="padding: 8px 10px; text-align: center;">Returnable</th>
                                        <th style="padding: 8px 10px; text-align: center; width: 110px;">Return Qty</th>
                                        <th style="padding: 8px 10px; text-align: right;">Refund Total</th>
                                    </tr>
                                </thead>
                                <tbody id="returnItemsTableBody">
                                    <!-- Populated dynamically via JS -->
                                </tbody>
                            </table>
                        </div>

                        <div id="exchangeReplacementSection" style="display: none; margin-bottom: 16px;">
                            <label class="form-label">Replacement items (added to same bill)</label>
                            <div style="display: flex; gap: 8px; margin-bottom: 8px;">
                                <input type="text" id="exchangeProductSearch" class="form-control" placeholder="Search by name, SKU, or barcode..." autocomplete="off">
                                <button type="button" class="btn-secondary" id="exchangeAddFirstMatchBtn" style="white-space: nowrap;">Add match</button>
                            </div>
                            <div id="exchangeSearchResults" style="max-height: 120px; overflow: auto; border: 1px solid var(--saas-border); border-radius: 8px; margin-bottom: 8px; display: none;"></div>
                            <table style="width: 100%; font-size: 12.5px; border-collapse: collapse; border: 1px solid var(--saas-border); border-radius: 8px; overflow: hidden;">
                                <thead>
                                    <tr style="background: var(--saas-slate-50);">
                                        <th style="padding: 8px 10px; text-align: left;">Replacement</th>
                                        <th style="padding: 8px 10px; text-align: center; width: 90px;">Qty</th>
                                        <th style="padding: 8px 10px; text-align: right;">Line total</th>
                                        <th style="padding: 8px 10px; width: 40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="exchangeCartBody">
                                    <tr id="exchangeCartEmptyRow"><td colspan="4" style="padding: 12px; text-align: center; color: #64748b;">No replacement items yet.</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Refund / settlement banner -->
                        <div style="display: flex; justify-content: space-between; align-items: center; background: #fef2f2; border: 1px solid #fecaca; padding: 12px 16px; border-radius: var(--saas-radius-md); margin-bottom: 16px;">
                            <span style="font-weight: 700; color: #991b1b;" id="settlementLabel">Total Refund to Customer:</span>
                            <span style="font-size: 20px; font-weight: 800; color: #b91c1c;" id="totalRefundText">₹0.00</span>
                        </div>
                        <div id="exchangeSettlementHint" style="display: none; font-size: 12px; color: #64748b; margin: -8px 0 16px;"></div>

                        <!-- Step 3: Refund Payment Method & Reason -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                            <div class="form-group">
                                <label for="refundMethodSelect" class="form-label">Refund Payment Method <span style="color: #ef4444;">*</span></label>
                                <select id="refundMethodSelect" name="refund_method" class="form-control">
                                    <option value="cash">Cash Refund</option>
                                    <option value="upi">UPI / Online Transfer</option>
                                    <option value="card">Original Card / POS</option>
                                    <option value="store_credit">Store Credit Voucher</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="returnReasonSelect" class="form-label">Return Reason <span style="color: #ef4444;">*</span></label>
                                <select id="returnReasonSelect" name="reason" class="form-control">
                                    <option value="Customer Dissatisfied">Customer Dissatisfied</option>
                                    <option value="Defective / Damaged Item">Defective / Damaged Item</option>
                                    <option value="Wrong Item Purchased">Wrong Item Purchased</option>
                                    <option value="Size / Variant Mismatch">Size / Variant Mismatch</option>
                                    <option value="Other / Counter Return">Other / Counter Return</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="returnNotesInput" class="form-label">Additional Notes / Inspection Remarks (Optional)</label>
                            <input type="text" id="returnNotesInput" name="notes" placeholder="e.g. Unopened box, verified receipt" class="form-control">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelNewReturnBtn">Cancel</button>
                    <button type="submit" class="header-btn" id="confirmReturnSubmitBtn" style="border: 0; background: #b91c1c;" disabled>
                        <span id="confirmReturnSubmitLabel">Confirm Return & Restore Stock</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- CSRF Token helper for JS -->
    <input type="hidden" id="pageCsrfToken" value="<?= csrf_token() ?>">

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const newReturnModal = document.getElementById('newReturnModal');
            const openNewReturnBtn = document.getElementById('openNewReturnBtn');
            const closeNewReturnBtn = document.getElementById('closeNewReturnModal');
            const cancelNewReturnBtn = document.getElementById('cancelNewReturnBtn');
            const orderSelect = document.getElementById('returnOrderSelect');
            const returnItemsSection = document.getElementById('returnItemsSection');
            const returnTableBody = document.getElementById('returnItemsTableBody');
            const totalRefundText = document.getElementById('totalRefundText');
            const returnItemsJson = document.getElementById('returnItemsJson');
            const exchangeItemsJson = document.getElementById('exchangeItemsJson');
            const confirmReturnBtn = document.getElementById('confirmReturnSubmitBtn');
            const confirmReturnSubmitLabel = document.getElementById('confirmReturnSubmitLabel');
            const processReturnForm = document.getElementById('processReturnForm');
            const returnFormAction = document.getElementById('returnFormAction');
            const returnModeRefund = document.getElementById('returnModeRefund');
            const returnModeExchange = document.getElementById('returnModeExchange');
            const exchangeReplacementSection = document.getElementById('exchangeReplacementSection');
            const exchangeProductSearch = document.getElementById('exchangeProductSearch');
            const exchangeSearchResults = document.getElementById('exchangeSearchResults');
            const exchangeAddFirstMatchBtn = document.getElementById('exchangeAddFirstMatchBtn');
            const exchangeCartBody = document.getElementById('exchangeCartBody');
            const settlementLabel = document.getElementById('settlementLabel');
            const exchangeSettlementHint = document.getElementById('exchangeSettlementHint');
            const csrfToken = document.getElementById('pageCsrfToken').value;
            const exchangeCatalog = <?= json_encode($exchangeCatalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            let currentOrderItems = [];
            let exchangeCart = [];

            if (openNewReturnBtn) openNewReturnBtn.addEventListener('click', () => newReturnModal.classList.add('open'));
            function closeModal() {
                newReturnModal.classList.remove('open');
                processReturnForm.reset();
                returnItemsSection.style.display = 'none';
                confirmReturnBtn.disabled = true;
                exchangeCart = [];
                renderExchangeCart();
                setReturnMode('refund');
            }

            function isExchangeMode() {
                return returnModeExchange && returnModeExchange.checked;
            }

            function setReturnMode(mode) {
                const exchange = mode === 'exchange';
                if (returnModeRefund) returnModeRefund.checked = !exchange;
                if (returnModeExchange) returnModeExchange.checked = exchange;
                if (exchangeReplacementSection) exchangeReplacementSection.style.display = exchange ? 'block' : 'none';
                if (returnFormAction) returnFormAction.value = exchange ? 'process_exchange' : 'process_return';
                if (confirmReturnSubmitLabel) {
                    confirmReturnSubmitLabel.textContent = exchange
                        ? 'Confirm Exchange on Same Bill'
                        : 'Confirm Return & Restore Stock';
                }
                document.getElementById('returnModalTitle').textContent = exchange
                    ? 'Exchange Items on Same Bill'
                    : 'Process Customer Return & Refund';
                recalculateRefund();
            }

            if (returnModeRefund) returnModeRefund.addEventListener('change', () => setReturnMode('refund'));
            if (returnModeExchange) returnModeExchange.addEventListener('change', () => setReturnMode('exchange'));
            if (closeNewReturnBtn) closeNewReturnBtn.addEventListener('click', closeModal);
            if (cancelNewReturnBtn) cancelNewReturnBtn.addEventListener('click', closeModal);

            // Fetch Order Items on Selection
            orderSelect.addEventListener('change', function () {
                const orderId = this.value;
                if (!orderId) {
                    returnItemsSection.style.display = 'none';
                    confirmReturnBtn.disabled = true;
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'get_order_items');
                formData.append('is_ajax', '1');
                formData.append('csrf_token', csrfToken);
                formData.append('order_id', orderId);

                fetch('<?= asset('returns.php') ?>', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        currentOrderItems = data.items;
                        renderReturnItemsTable(data.items);
                        returnItemsSection.style.display = 'block';
                    } else {
                        alert(data.error || 'Could not load order items.');
                    }
                })
                .catch(err => alert('Network error: ' + err));
            });

            function renderReturnItemsTable(items) {
                returnTableBody.innerHTML = '';
                if (items.length === 0) {
                    returnTableBody.innerHTML = '<tr><td colspan="5" style="padding: 16px; text-align: center; color: #64748b;">No items available to return in this order.</td></tr>';
                    return;
                }

                items.forEach(it => {
                    const maxQty = it.returnable_quantity;
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid var(--saas-border)';
                    tr.innerHTML = `
                        <td style="padding: 8px 10px;">
                            <div style="font-weight: 700; color: var(--saas-navy-950);">${escapeHtml(it.product_name)}</div>
                            <div style="font-size: 11px; color: var(--saas-slate-400);">SKU: ${escapeHtml(it.product_sku)}</div>
                        </td>
                        <td style="padding: 8px 10px; text-align: right;">₹${parseFloat(it.effective_unit_price).toFixed(2)}</td>
                        <td style="padding: 8px 10px; text-align: center;"><strong>${maxQty}</strong></td>
                        <td style="padding: 8px 10px; text-align: center;">
                            <input
                                type="number"
                                min="0"
                                max="${maxQty}"
                                value="0"
                                class="form-control return-qty-input"
                                data-id="${it.id}"
                                data-price="${it.effective_unit_price}"
                                style="width: 70px; margin: 0 auto; text-align: center; padding: 4px;"
                                ${maxQty <= 0 ? 'disabled' : ''}
                            >
                        </td>
                        <td style="padding: 8px 10px; text-align: right; font-weight: 700;" class="line-refund-text">₹0.00</td>
                    `;
                    returnTableBody.appendChild(tr);
                });
                recalculateRefund();
            }

            function escapeHtml(str) {
                return (str + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function lineTotalWithTax(prod, qty) {
                const sub = (parseFloat(prod.price) || 0) * qty;
                const tax = sub * ((parseFloat(prod.tax) || 0) / 100);
                return sub + tax;
            }

            function findExchangeProducts(query) {
                const q = (query || '').trim().toLowerCase();
                if (!q) return [];
                return exchangeCatalog.filter(p => {
                    return (p.name || '').toLowerCase().includes(q)
                        || (p.sku || '').toLowerCase() === q
                        || (p.barcode || '').toLowerCase() === q
                        || (p.sku || '').toLowerCase().includes(q);
                }).slice(0, 8);
            }

            function addExchangeProduct(prod) {
                if (!prod) return;
                const key = String(prod.id);
                const existing = exchangeCart.find(r => String(r.product_id) === key);
                if (existing) {
                    existing.quantity += 1;
                } else {
                    exchangeCart.push({ product_id: prod.id, quantity: 1, price: prod.price, tax: prod.tax, name: prod.name, sku: prod.sku });
                }
                renderExchangeCart();
                recalculateRefund();
            }

            function renderExchangeCart() {
                if (!exchangeCartBody) return;
                exchangeCartBody.innerHTML = '';
                if (exchangeCart.length === 0) {
                    exchangeCartBody.innerHTML = '<tr id="exchangeCartEmptyRow"><td colspan="4" style="padding: 12px; text-align: center; color: #64748b;">No replacement items yet.</td></tr>';
                    if (exchangeItemsJson) exchangeItemsJson.value = '[]';
                    return;
                }
                exchangeCart.forEach((row, idx) => {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid var(--saas-border)';
                    const lt = lineTotalWithTax(row, row.quantity);
                    tr.innerHTML = `
                        <td style="padding: 8px 10px;">
                            <div style="font-weight:700;">${escapeHtml(row.name)}</div>
                            <div style="font-size:11px;color:#64748b;">${escapeHtml(row.sku)}</div>
                        </td>
                        <td style="padding: 8px 10px; text-align:center;">
                            <input type="number" min="1" value="${row.quantity}" data-idx="${idx}" class="form-control exchange-qty-input" style="width:64px;margin:0 auto;text-align:center;padding:4px;">
                        </td>
                        <td style="padding: 8px 10px; text-align:right; font-weight:700;">₹${lt.toFixed(2)}</td>
                        <td style="padding: 8px 10px; text-align:center;">
                            <button type="button" class="btn-secondary exchange-remove-btn" data-idx="${idx}" style="padding:2px 8px;">×</button>
                        </td>`;
                    exchangeCartBody.appendChild(tr);
                });
                if (exchangeItemsJson) {
                    exchangeItemsJson.value = JSON.stringify(exchangeCart.map(r => ({
                        product_id: r.product_id,
                        quantity: r.quantity,
                        price: r.price
                    })));
                }
            }

            if (exchangeProductSearch) {
                exchangeProductSearch.addEventListener('input', function () {
                    const matches = findExchangeProducts(this.value);
                    if (!matches.length) {
                        exchangeSearchResults.style.display = 'none';
                        return;
                    }
                    exchangeSearchResults.style.display = 'block';
                    exchangeSearchResults.innerHTML = matches.map(p => `
                        <button type="button" class="exchange-pick-btn" data-id="${p.id}" style="display:block;width:100%;text-align:left;padding:8px 10px;border:0;border-bottom:1px solid #e2e8f0;background:#fff;cursor:pointer;">
                            <strong>${escapeHtml(p.name)}</strong> · ${escapeHtml(p.sku)} · ₹${parseFloat(p.price).toFixed(2)}
                        </button>
                    `).join('');
                });
            }
            if (exchangeSearchResults) {
                exchangeSearchResults.addEventListener('click', function (e) {
                    const btn = e.target.closest('.exchange-pick-btn');
                    if (!btn) return;
                    const id = parseInt(btn.getAttribute('data-id'), 10);
                    const prod = exchangeCatalog.find(p => p.id === id);
                    addExchangeProduct(prod);
                    exchangeProductSearch.value = '';
                    exchangeSearchResults.style.display = 'none';
                });
            }
            if (exchangeAddFirstMatchBtn && exchangeProductSearch) {
                exchangeAddFirstMatchBtn.addEventListener('click', function () {
                    const matches = findExchangeProducts(exchangeProductSearch.value);
                    if (matches[0]) addExchangeProduct(matches[0]);
                });
            }
            if (exchangeCartBody) {
                exchangeCartBody.addEventListener('input', function (e) {
                    if (e.target.classList.contains('exchange-qty-input')) {
                        const idx = parseInt(e.target.getAttribute('data-idx'), 10);
                        exchangeCart[idx].quantity = Math.max(1, parseInt(e.target.value, 10) || 1);
                        renderExchangeCart();
                        recalculateRefund();
                    }
                });
                exchangeCartBody.addEventListener('click', function (e) {
                    const btn = e.target.closest('.exchange-remove-btn');
                    if (!btn) return;
                    exchangeCart.splice(parseInt(btn.getAttribute('data-idx'), 10), 1);
                    renderExchangeCart();
                    recalculateRefund();
                });
            }

            function recalculateRefund() {
                let grandRefund = 0;
                const returnPayload = [];

                document.querySelectorAll('.return-qty-input').forEach(input => {
                    const orderItemId = parseInt(input.getAttribute('data-id'), 10);
                    const price = parseFloat(input.getAttribute('data-price')) || 0;
                    const max = parseInt(input.getAttribute('max'), 10) || 0;
                    let qty = parseInt(input.value, 10) || 0;

                    if (qty > max) {
                        qty = max;
                        input.value = max;
                    }
                    if (qty < 0) {
                        qty = 0;
                        input.value = 0;
                    }

                    const lineRefund = qty * price;
                    grandRefund += lineRefund;

                    const row = input.closest('tr');
                    if (row) {
                        row.querySelector('.line-refund-text').textContent = '₹' + lineRefund.toFixed(2);
                    }

                    if (qty > 0) {
                        returnPayload.push({
                            order_item_id: orderItemId,
                            quantity: qty
                        });
                    }
                });

                let exchangeTotal = 0;
                exchangeCart.forEach(row => { exchangeTotal += lineTotalWithTax(row, row.quantity); });

                if (isExchangeMode()) {
                    const net = exchangeTotal - grandRefund;
                    if (net > 0.009) {
                        settlementLabel.textContent = 'Customer pays (difference):';
                        totalRefundText.textContent = '₹' + net.toFixed(2);
                        totalRefundText.style.color = '#1e3a8a';
                        exchangeSettlementHint.style.display = 'block';
                        exchangeSettlementHint.textContent = 'Return credit ₹' + grandRefund.toFixed(2) + ' applied toward replacement total ₹' + exchangeTotal.toFixed(2) + '.';
                    } else if (net < -0.009) {
                        settlementLabel.textContent = 'Refund to customer (difference):';
                        totalRefundText.textContent = '₹' + Math.abs(net).toFixed(2);
                        totalRefundText.style.color = '#b91c1c';
                        exchangeSettlementHint.style.display = 'block';
                        exchangeSettlementHint.textContent = 'Replacement is lower than returned value. Balance refunded on same bill.';
                    } else {
                        settlementLabel.textContent = 'Even exchange:';
                        totalRefundText.textContent = '₹0.00';
                        totalRefundText.style.color = '#047857';
                        exchangeSettlementHint.style.display = 'block';
                        exchangeSettlementHint.textContent = 'Returned value matches replacement total.';
                    }
                    confirmReturnBtn.disabled = (returnPayload.length === 0 || exchangeCart.length === 0);
                } else {
                    settlementLabel.textContent = 'Total Refund to Customer:';
                    totalRefundText.textContent = '₹' + grandRefund.toFixed(2);
                    totalRefundText.style.color = '#b91c1c';
                    exchangeSettlementHint.style.display = 'none';
                    confirmReturnBtn.disabled = (returnPayload.length === 0 || grandRefund <= 0);
                }
                returnItemsJson.value = JSON.stringify(returnPayload);
            }

            returnTableBody.addEventListener('input', function (e) {
                if (e.target.classList.contains('return-qty-input')) {
                    recalculateRefund();
                }
            });

            // Process Return Form Submission
            processReturnForm.addEventListener('submit', function (e) {
                e.preventDefault();

                confirmReturnBtn.disabled = true;
                confirmReturnBtn.innerHTML = '<span>' + (isExchangeMode() ? 'Processing Exchange...' : 'Processing Return...') + '</span>';

                const formData = new FormData(this);

                fetch('<?= asset('returns.php') ?>', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        if (data.return_type === 'exchange') {
                            let msg = 'Exchange #' + data.return_number + ' completed on bill ' + data.order_number + '.';
                            if (parseFloat(data.amount_collected) > 0) {
                                msg += ' Collected: ₹' + parseFloat(data.amount_collected).toFixed(2) + '.';
                            } else if (parseFloat(data.refund_amount) > 0) {
                                msg += ' Refunded: ₹' + parseFloat(data.refund_amount).toFixed(2) + '.';
                            }
                            alert(msg);
                        } else {
                            alert('Return #' + data.return_number + ' processed successfully! Total Refund: ₹' + parseFloat(data.refund_amount).toFixed(2) + '. Inventory has been restored.');
                        }
                        location.reload();
                    } else {
                        alert((isExchangeMode() ? 'Exchange' : 'Return') + ' error: ' + (data.error || 'Could not complete.'));
                        confirmReturnBtn.disabled = false;
                        confirmReturnSubmitLabel.textContent = isExchangeMode()
                            ? 'Confirm Exchange on Same Bill'
                            : 'Confirm Return & Restore Stock';
                    }
                })
                .catch(err => {
                    alert('Network error: ' + err);
                    confirmReturnBtn.disabled = false;
                    confirmReturnSubmitLabel.textContent = isExchangeMode()
                        ? 'Confirm Exchange on Same Bill'
                        : 'Confirm Return & Restore Stock';
                });
            });
        });
    </script>
</body>
</html>

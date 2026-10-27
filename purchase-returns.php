<?php
/**
 * OminiFlow POS - Purchase Returns & Vendor Debit Notes Screen (Zoho POS Feature Parity)
 * Full multi-tenant isolation, inventory stock replenishment, and vendor credit ledger.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/purchases_db.php';
require_once __DIR__ . '/includes/purchase_returns_db.php';
require_once __DIR__ . '/includes/products_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int)$user['id'] : null;
$bizId = current_business_id();
$business = current_business() ?: ['name' => 'OminiFlow POS'];

// Handle AJAX GET requests (PO items, Bill items, Product search, Voucher details)
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $ajaxAction = $_GET['action'] ?? '';

    if ($ajaxAction === 'get_po_items') {
        $poId = (int)($_GET['po_id'] ?? 0);
        $items = get_returnable_po_items($poId, $bizId);
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    if ($ajaxAction === 'get_bill_items') {
        $billId = (int)($_GET['bill_id'] ?? 0);
        $items = get_returnable_bill_items($billId, $bizId);
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    if ($ajaxAction === 'search_products') {
        $q = trim((string)($_GET['q'] ?? ''));
        $db = get_db();
        $stmt = $db->prepare('
            SELECT id, name, sku, barcode, cost_price, selling_price, stock_quantity, tax_rate
            FROM products
            WHERE business_id = :bid AND (name LIKE :q1 OR sku LIKE :q2 OR barcode LIKE :q3)
            ORDER BY name ASC LIMIT 20
        ');
        $searchParam = "%{$q}%";
        $stmt->execute([
            'bid' => $bizId,
            'q1' => $searchParam,
            'q2' => $searchParam,
            'q3' => $searchParam,
        ]);
        $prods = $stmt->fetchAll();
        echo json_encode(['success' => true, 'products' => $prods]);
        exit;
    }

    if ($ajaxAction === 'get_voucher') {
        $retId = (int)($_GET['id'] ?? 0);
        $ret = get_purchase_return_by_id($retId, $bizId);
        if (!$ret) {
            echo json_encode(['success' => false, 'error' => 'Purchase Return not found.']);
            exit;
        }
        $ret['amount_in_words'] = number_to_indian_currency_words((float)$ret['total_amount']);
        echo json_encode(['success' => true, 'return' => $ret]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}

// Handle POST submissions (Create Purchase Return)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_POST['is_ajax']);

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid session token. Please refresh.']);
            exit;
        }
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/purchase-returns.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_return') {
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $poId = !empty($_POST['purchase_order_id']) ? (int)$_POST['purchase_order_id'] : null;
        $billId = !empty($_POST['bill_id']) ? (int)$_POST['bill_id'] : null;
        $returnDate = trim((string)($_POST['return_date'] ?? date('Y-m-d')));
        $refundMethod = trim((string)($_POST['refund_method'] ?? 'vendor_credit'));
        $reason = trim((string)($_POST['reason'] ?? 'Damaged in Transit'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $itemsJson = (string)($_POST['items_json'] ?? '[]');
        $items = json_decode($itemsJson, true);
        if (!is_array($items) || empty($items)) {
            // Fallback for simple single-item submission if submitted via legacy form
            $singlePid = (int)($_POST['product_id'] ?? 0);
            if ($singlePid > 0) {
                $items = [[
                    'product_id' => $singlePid,
                    'quantity' => max(1, (int)($_POST['quantity'] ?? 1)),
                    'unit_cost' => (float)($_POST['unit_cost'] ?? 0),
                    'tax_percent' => (float)($_POST['tax_percent'] ?? 0),
                    'reason' => $reason,
                ]];
            }
        }

        $headerData = [
            'vendor_id' => $vendorId,
            'purchase_order_id' => $poId,
            'bill_id' => $billId,
            'return_date' => $returnDate ?: date('Y-m-d'),
            'refund_method' => $refundMethod,
            'reason' => $reason,
            'notes' => $notes,
        ];

        $res = create_purchase_return($headerData, $items ?: [], $userId, $bizId);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }

        if ($res['success']) {
            set_flash('success', "Debit Note #{$res['return_number']} created successfully! Total Return: ₹" . number_format((float)$res['total_amount'], 2));
            redirect(APP_URL . '/purchase-returns.php?view_id=' . $res['return_id']);
        } else {
            set_flash('error', $res['error'] ?? 'Failed to process purchase return.');
            redirect(APP_URL . '/purchase-returns.php');
        }
    }
}

// Filters & Data
$search = trim((string)($_GET['search'] ?? ''));
$filterVendorId = !empty($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
$filterRefundMethod = trim((string)($_GET['refund_method'] ?? ''));
$filterDateFrom = trim((string)($_GET['date_from'] ?? ''));
$filterDateTo = trim((string)($_GET['date_to'] ?? ''));

$filters = [
    'search' => $search,
    'vendor_id' => $filterVendorId,
    'refund_method' => $filterRefundMethod,
    'date_from' => $filterDateFrom,
    'date_to' => $filterDateTo,
];

$returns = get_purchase_returns($filters, 100, 0, $bizId);
$kpis = get_purchase_returns_kpis($bizId);
$vendors = get_vendors('', $bizId);
$purchaseOrders = get_purchase_orders_for_return(null, $bizId);
$purchaseBills = get_purchase_bills_for_return(null, $bizId);

// View specific return voucher if requested in URL
$viewReturnId = !empty($_GET['view_id']) ? (int)$_GET['view_id'] : null;
$viewReturn = $viewReturnId ? get_purchase_return_by_id($viewReturnId, $bizId) : null;
$autoPrint = !empty($_GET['print']);

// Pre-fill parameters if arriving from PO or Bill
$prefillPoId = !empty($_GET['po_id']) ? (int)$_GET['po_id'] : null;
$prefillBillId = !empty($_GET['bill_id']) ? (int)$_GET['bill_id'] : null;
$openNewModal = !empty($_GET['new']) || $prefillPoId || $prefillBillId;

$pageTitle = 'Purchase Returns & Debit Notes';
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        /* Modern Purchase Section Tabs */
        .purchase-nav-tabs {
            display: flex;
            align-items: center;
            gap: 6px;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 24px;
            padding-bottom: 2px;
            overflow-x: auto;
        }
        .purchase-nav-tab {
            padding: 9px 16px;
            font-size: 13.5px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            border-radius: 8px 8px 0 0;
            border-bottom: 2px solid transparent;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .purchase-nav-tab:hover {
            color: #1e293b;
            background: #f8fafc;
        }
        .purchase-nav-tab.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            background: #eff6ff;
            font-weight: 700;
        }

        /* KPI cards styling */
        .kpi-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .kpi-stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }
        .kpi-stat-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #cbd5e1;
        }
        .kpi-stat-card.card-primary::before { background: #3b82f6; }
        .kpi-stat-card.card-danger::before { background: #ef4444; }
        .kpi-stat-card.card-success::before { background: #10b981; }
        .kpi-stat-card.card-purple::before { background: #8b5cf6; }
        .kpi-stat-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
        }
        .kpi-stat-value {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
        }
        .kpi-stat-hint {
            font-size: 11.5px;
            color: #94a3b8;
        }

        /* Modal Backdrop & Container */
        .return-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(2px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .return-modal-backdrop.open {
            display: flex !important;
        }
        .return-modal-box {
            background: #ffffff;
            border-radius: 12px;
            width: 100%;
            max-width: 880px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
        }
        .return-modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border-radius: 12px 12px 0 0;
        }
        .return-modal-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .return-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            line-height: 1;
            color: #64748b;
            cursor: pointer;
            padding: 4px;
        }
        .return-modal-close:hover { color: #0f172a; }
        .return-modal-body {
            padding: 24px;
            flex: 1;
            overflow-y: auto;
        }
        .return-modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            border-radius: 0 0 12px 12px;
        }

        /* Mode Switcher Tabs inside Modal */
        .return-mode-tabs {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 8px;
            margin-bottom: 20px;
            gap: 4px;
        }
        .return-mode-tab {
            flex: 1;
            padding: 8px 12px;
            border-radius: 6px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            border: none;
            background: transparent;
            transition: all 0.15s ease;
        }
        .return-mode-tab.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            font-weight: 700;
        }

        /* Items selection table in modal */
        .return-items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 12px;
        }
        .return-items-table th {
            background: #f8fafc;
            padding: 10px 12px;
            font-weight: 700;
            color: #475569;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .return-items-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .return-items-table tr:hover td {
            background: #f8fafc;
        }

        /* Printable Debit Note Voucher Styles */
        .voucher-container {
            background: #ffffff;
            padding: 32px;
            border-radius: 10px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #1e293b;
        }
        .voucher-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 18px;
            margin-bottom: 20px;
        }
        .voucher-title {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #0f172a;
            text-transform: uppercase;
        }
        .voucher-box-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .voucher-info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 12.5px;
            line-height: 1.6;
        }
        .voucher-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .voucher-table th {
            background: #0f172a;
            color: #ffffff;
            padding: 10px 12px;
            text-align: left;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
        }
        .voucher-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .voucher-totals-box {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 24px;
        }
        .voucher-totals-table {
            width: 320px;
            font-size: 13px;
        }
        .voucher-totals-table td {
            padding: 6px 10px;
        }
        .voucher-sign-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 48px;
            padding-top: 24px;
            border-top: 1px dashed #cbd5e1;
        }

        @media print {
            body { background: #ffffff !important; }
            .app-sidebar, .app-header, .page-header-row, .purchase-nav-tabs,
            .kpi-stat-grid, .filter-card, .section-card, .return-modal-header,
            .return-modal-footer, .header-btn, .btn-secondary {
                display: none !important;
            }
            .return-modal-backdrop {
                position: static !important;
                background: none !important;
                padding: 0 !important;
                display: block !important;
            }
            .return-modal-box {
                max-width: 100% !important;
                box-shadow: none !important;
                border: none !important;
            }
            .voucher-container {
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <!-- Purchases Module Subnavigation Tabs -->
                <div class="purchase-nav-tabs">
                    <a href="<?= asset('vendors.php') ?>" class="purchase-nav-tab">Vendors</a>
                    <a href="<?= asset('purchases.php') ?>" class="purchase-nav-tab">Purchase Orders</a>
                    <a href="<?= asset('purchase-entry.php') ?>" class="purchase-nav-tab">Purchase Entry</a>
                    <a href="<?= asset('purchase-receives.php') ?>" class="purchase-nav-tab">Purchase Receives</a>
                    <a href="<?= asset('inward-entry.php') ?>" class="purchase-nav-tab">Inward Entry</a>
                    <a href="<?= asset('bills.php') ?>" class="purchase-nav-tab">Bills</a>
                    <a href="<?= asset('payments-made.php') ?>" class="purchase-nav-tab">Payments Made</a>
                    <a href="<?= asset('purchase-returns.php') ?>" class="purchase-nav-tab active">↩ Purchase Returns (Debit Notes)</a>
                </div>

                <div class="page-header-row">
                    <div>
                        <h1 class="page-title">Purchase Returns & Vendor Debit Notes</h1>
                        <p class="page-subtitle">Return defective, damaged, or excess inventory back to suppliers with automated stock deduction and credit ledger.</p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" onclick="openNewReturnModal()" class="header-btn" style="padding: 10px 20px; display: inline-flex; align-items: center; gap: 8px; background: #dc2626; border-color: #dc2626;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                            <span>+ New Purchase Return</span>
                        </button>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success" style="margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger" style="margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <!-- KPI Statistics Cards -->
                <div class="kpi-stat-grid">
                    <div class="kpi-stat-card card-primary">
                        <div class="kpi-stat-title">Total Returns</div>
                        <div class="kpi-stat-value"><?= number_format((int)$kpis['total_count']) ?></div>
                        <div class="kpi-stat-hint">Processed Debit Notes</div>
                    </div>
                    <div class="kpi-stat-card card-danger">
                        <div class="kpi-stat-title">Total Returned Value</div>
                        <div class="kpi-stat-value" style="color: #dc2626;">₹<?= number_format((float)$kpis['total_amount'], 2) ?></div>
                        <div class="kpi-stat-hint">Gross stock value reversed</div>
                    </div>
                    <div class="kpi-stat-card card-success">
                        <div class="kpi-stat-title">Vendor Credit Notes</div>
                        <div class="kpi-stat-value" style="color: #059669;">₹<?= number_format((float)$kpis['total_credit'], 2) ?></div>
                        <div class="kpi-stat-hint">Adjusted in supplier ledger</div>
                    </div>
                    <div class="kpi-stat-card card-purple">
                        <div class="kpi-stat-title">Cash / Bank Settled</div>
                        <div class="kpi-stat-value" style="color: #7c3aed;">₹<?= number_format((float)$kpis['total_refunded'], 2) ?></div>
                        <div class="kpi-stat-hint">Refunded to accounts</div>
                    </div>
                </div>

                <!-- Filter & Search Toolbar -->
                <div class="filter-card" style="padding: 16px 20px; margin-bottom: 24px;">
                    <form method="GET" action="<?= asset('purchase-returns.php') ?>" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 220px;">
                            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by Debit Note #, PO #, Vendor..." class="form-control">
                        </div>
                        <div style="width: 200px;">
                            <select name="vendor_id" class="form-control" onchange="this.form.submit()">
                                <option value="">All Suppliers / Vendors</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= $v['id'] ?>" <?= $filterVendorId === (int)$v['id'] ? 'selected' : '' ?>>
                                        <?= e($v['name']) ?><?= $v['company_name'] ? ' (' . e($v['company_name']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="width: 180px;">
                            <select name="refund_method" class="form-control" onchange="this.form.submit()">
                                <option value="">All Settlement Modes</option>
                                <option value="vendor_credit" <?= $filterRefundMethod === 'vendor_credit' ? 'selected' : '' ?>>Vendor Credit</option>
                                <option value="cash" <?= $filterRefundMethod === 'cash' ? 'selected' : '' ?>>Cash Refund</option>
                                <option value="bank" <?= $filterRefundMethod === 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                                <option value="replacement" <?= $filterRefundMethod === 'replacement' ? 'selected' : '' ?>>Replacement</option>
                            </select>
                        </div>
                        <div style="width: 140px;">
                            <input type="date" name="date_from" value="<?= e($filterDateFrom) ?>" class="form-control" placeholder="From Date" title="From Date">
                        </div>
                        <div style="width: 140px;">
                            <input type="date" name="date_to" value="<?= e($filterDateTo) ?>" class="form-control" placeholder="To Date" title="To Date">
                        </div>
                        <button type="submit" class="header-btn" style="padding: 8px 18px;">Filter</button>
                        <?php if ($search !== '' || $filterVendorId > 0 || $filterRefundMethod !== '' || $filterDateFrom !== '' || $filterDateTo !== ''): ?>
                            <a href="<?= asset('purchase-returns.php') ?>" class="btn-secondary" style="padding: 8px 14px;">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Purchase Returns History Table -->
                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h2 class="section-title">Purchase Returns & Debit Notes Ledger</h2>
                            <p class="section-subheading">History of supplier stock returns, debit notes, and inventory write-backs</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Debit Note #</th>
                                    <th>Return Date</th>
                                    <th>Supplier / Vendor</th>
                                    <th>Reference</th>
                                    <th>Returned Items</th>
                                    <th style="text-align: right;">Total Amount</th>
                                    <th>Settlement Mode</th>
                                    <th>Reason</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($returns)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; padding: 40px; color: #64748b;">
                                            <div style="font-size: 32px; margin-bottom: 8px;">↩️</div>
                                            <div style="font-weight: 700; color: #1e293b; margin-bottom: 4px;">No Purchase Returns Recorded</div>
                                            <p style="font-size: 13px; max-width: 440px; margin: 0 auto 16px;">
                                                Create a purchase return to return damaged, excess, or defective items back to your supplier with automated stock reversal.
                                            </p>
                                            <button type="button" onclick="openNewReturnModal()" class="header-btn" style="padding: 8px 18px; background: #dc2626; border-color: #dc2626;">
                                                + Create First Purchase Return
                                            </button>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($returns as $ret): ?>
                                        <tr>
                                            <td>
                                                <a href="javascript:void(0)" onclick="viewReturnVoucher(<?= (int)$ret['id'] ?>)" style="font-family: monospace; font-weight: 700; color: #dc2626; text-decoration: none;">
                                                    <?= e($ret['return_number']) ?>
                                                </a>
                                            </td>
                                            <td style="font-size: 12.5px; color: #64748b;">
                                                <?= date('d M Y', strtotime($ret['return_date'] ?? $ret['created_at'])) ?>
                                            </td>
                                            <td>
                                                <strong><?= e($ret['vendor_name'] ?: 'Unknown Vendor') ?></strong>
                                                <?php if (!empty($ret['vendor_company'])): ?>
                                                    <div style="font-size: 11.5px; color: #94a3b8;"><?= e($ret['vendor_company']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($ret['po_number'])): ?>
                                                    <span class="badge badge-info" style="font-family: monospace;">PO: <?= e($ret['po_number']) ?></span>
                                                <?php elseif (!empty($ret['bill_number'])): ?>
                                                    <span class="badge badge-info" style="font-family: monospace; background: #f5f3ff; color: #6d28d9;">Bill: <?= e($ret['bill_number']) ?></span>
                                                <?php else: ?>
                                                    <span style="font-size: 12px; color: #94a3b8;">Direct Return</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span style="font-weight: 600; color: #1e293b; font-size: 12.5px;">
                                                    <?= (int)$ret['items_count'] ?> Products (<?= (int)$ret['total_units_returned'] ?> Units)
                                                </span>
                                            </td>
                                            <td style="text-align: right; font-weight: 800; font-size: 13.5px; color: #dc2626;">
                                                ₹<?= number_format((float)$ret['total_amount'], 2) ?>
                                            </td>
                                            <td>
                                                <?php
                                                    $method = $ret['refund_method'] ?? 'vendor_credit';
                                                    if ($method === 'vendor_credit') {
                                                        echo '<span class="badge badge-success" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;">Vendor Credit</span>';
                                                    } elseif ($method === 'cash') {
                                                        echo '<span class="badge badge-success" style="background:#ecfdf5; color:#047857; border:1px solid #a7f3d0;">Cash Refund</span>';
                                                    } elseif ($method === 'bank') {
                                                        echo '<span class="badge badge-info" style="background:#f5f3ff; color:#6d28d9; border:1px solid #ddd6fe;">Bank Transfer</span>';
                                                    } else {
                                                        echo '<span class="badge badge-warning" style="background:#fffbeb; color:#b45309; border:1px solid #fde68a;">Replacement</span>';
                                                    }
                                                ?>
                                            </td>
                                            <td style="font-size: 12px; color: #475569; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?= e($ret['reason'] ?: 'Damaged / Defective') ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <div style="display: inline-flex; gap: 6px;">
                                                    <button type="button" onclick="viewReturnVoucher(<?= (int)$ret['id'] ?>)" class="btn-secondary" style="padding: 4px 10px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;" title="View Debit Note Voucher">
                                                        <span>📄 Voucher</span>
                                                    </button>
                                                    <button type="button" onclick="printReturnVoucher(<?= (int)$ret['id'] ?>)" class="btn-secondary" style="padding: 4px 8px; font-size: 12px;" title="Print Debit Note">
                                                        <span>🖨️</span>
                                                    </button>
                                                </div>
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

    <!-- NEW PURCHASE RETURN MODAL (ZOHO POS PARITY) -->
    <div class="return-modal-backdrop <?= $openNewModal ? 'open' : '' ?>" id="newReturnModal">
        <div class="return-modal-box">
            <div class="return-modal-header">
                <h3 class="return-modal-title">
                    <span style="color: #dc2626;">↩</span>
                    <span>New Purchase Return & Debit Note</span>
                </h3>
                <button type="button" class="return-modal-close" onclick="closeNewReturnModal()">&times;</button>
            </div>

            <form id="purchaseReturnForm" method="POST" action="<?= asset('purchase-returns.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_return">
                <input type="hidden" name="items_json" id="returnItemsJson" value="[]">

                <div class="return-modal-body">
                    <!-- Source Mode Selector Tabs -->
                    <div class="return-mode-tabs">
                        <button type="button" class="return-mode-tab active" id="tabModePO" onclick="switchReturnMode('po')">
                            📦 From Purchase Order
                        </button>
                        <button type="button" class="return-mode-tab" id="tabModeBill" onclick="switchReturnMode('bill')">
                            🧾 From Vendor Bill
                        </button>
                        <button type="button" class="return-mode-tab" id="tabModeDirect" onclick="switchReturnMode('direct')">
                            🏷️ Direct Return to Vendor
                        </button>
                    </div>

                    <!-- Source Selection Row -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <!-- Purchase Order Selector (Mode: PO) -->
                        <div id="groupSourcePO" class="form-group">
                            <label class="form-label" style="font-weight: 700;">Select Purchase Order <span style="color:#ef4444;">*</span></label>
                            <select name="purchase_order_id" id="poSelect" class="form-control" onchange="onPOSelected(this.value)">
                                <option value="">-- Choose Received Purchase Order --</option>
                                <?php foreach ($purchaseOrders as $po): ?>
                                    <option value="<?= $po['id'] ?>" data-vendor="<?= $po['vendor_id'] ?>" <?= ($prefillPoId === (int)$po['id']) ? 'selected' : '' ?>>
                                        <?= e($po['po_number']) ?> — <?= e($po['vendor_name']) ?> (₹<?= number_format((float)$po['total_amount'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Bill Selector (Mode: Bill) -->
                        <div id="groupSourceBill" class="form-group" style="display: none;">
                            <label class="form-label" style="font-weight: 700;">Select Vendor Bill <span style="color:#ef4444;">*</span></label>
                            <select name="bill_id" id="billSelect" class="form-control" onchange="onBillSelected(this.value)">
                                <option value="">-- Choose Vendor Bill --</option>
                                <?php foreach ($purchaseBills as $b): ?>
                                    <option value="<?= $b['id'] ?>" data-vendor="<?= $b['vendor_id'] ?>" <?= ($prefillBillId === (int)$b['id']) ? 'selected' : '' ?>>
                                        <?= e($b['bill_number']) ?> — <?= e($b['vendor_name']) ?> (₹<?= number_format((float)$b['total_amount'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Vendor Selector (Mode: Direct, or auto-locked for PO/Bill) -->
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700;">Supplier / Vendor <span style="color:#ef4444;">*</span></label>
                            <select name="vendor_id" id="vendorSelect" required class="form-control">
                                <option value="">-- Select Vendor --</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= $v['id'] ?>" data-balance="<?= (float)$v['outstanding_balance'] ?>">
                                        <?= e($v['name']) ?><?= $v['company_name'] ? ' (' . e($v['company_name']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Details Row: Return Date, Reason, Settlement Method -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700;">Return Date <span style="color:#ef4444;">*</span></label>
                            <input type="date" name="return_date" id="returnDate" value="<?= date('Y-m-d') ?>" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700;">Reason for Return <span style="color:#ef4444;">*</span></label>
                            <select name="reason" id="returnReasonSelect" class="form-control">
                                <option value="Damaged in Transit">Damaged in Transit / Shipping</option>
                                <option value="Defective / Poor Quality">Defective / Poor Quality</option>
                                <option value="Incorrect Item Shipped">Incorrect Item / Wrong Specification</option>
                                <option value="Excess Inventory / Overstock">Excess Inventory / Overstock</option>
                                <option value="Expired / Near Expiry">Expired / Near Expiry</option>
                                <option value="Price Discrepancy">Price Discrepancy / Billing Error</option>
                                <option value="Other">Other / Supplier Recall</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700;">Refund Settlement <span style="color:#ef4444;">*</span></label>
                            <select name="refund_method" id="refundMethodSelect" class="form-control">
                                <option value="vendor_credit">Vendor Credit Note (Adjust Payable)</option>
                                <option value="cash">Cash Refund</option>
                                <option value="bank">Bank Transfer / UPI</option>
                                <option value="replacement">Replacement Pending</option>
                            </select>
                        </div>
                    </div>

                    <!-- Direct Product Autocomplete Search Bar (Mode: Direct) -->
                    <div id="directProductSearchRow" style="display: none; margin-bottom: 16px; background: #f8fafc; padding: 12px 16px; border-radius: 8px; border: 1px dashed #cbd5e1;">
                        <label class="form-label" style="font-weight: 700; margin-bottom: 4px;">Search & Add Product to Return</label>
                        <div style="position: relative;">
                            <input type="text" id="directProductInput" placeholder="Scan barcode or type product name/SKU..." class="form-control" oninput="onDirectProductSearch(this.value)">
                            <div id="productSearchResults" style="display: none; position: absolute; left: 0; right: 0; top: 100%; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);"></div>
                        </div>
                    </div>

                    <!-- Return Items Selection Table -->
                    <div style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 16px;">
                        <div style="padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 700; font-size: 13px; color: #1e293b;">Items to Return</span>
                            <span style="font-size: 12px; color: #64748b;" id="itemsSummaryHint">Select products and specify return quantities</span>
                        </div>
                        <div style="max-height: 280px; overflow-y: auto;">
                            <table class="return-items-table">
                                <thead>
                                    <tr>
                                        <th style="width: 32px;"><input type="checkbox" id="selectAllItems" onchange="toggleSelectAllItems(this.checked)"></th>
                                        <th>Product / Description</th>
                                        <th style="text-align: center; width: 110px;">Max Returnable</th>
                                        <th style="text-align: center; width: 110px;">Return Qty</th>
                                        <th style="text-align: right; width: 110px;">Unit Cost (₹)</th>
                                        <th style="text-align: right; width: 90px;">Tax (%)</th>
                                        <th style="text-align: right; width: 120px;">Line Total (₹)</th>
                                        <th style="width: 40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="returnItemsTbody">
                                    <tr id="emptyItemsRow">
                                        <td colspan="8" style="text-align: center; padding: 24px; color: #94a3b8;">
                                            Select a Purchase Order or Bill above, or switch to "Direct Return" to add products.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Summary & Remarks Box -->
                    <div style="display: grid; grid-template-columns: 1fr 300px; gap: 20px; align-items: flex-start;">
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700;">Internal Notes / Return Remarks</label>
                            <textarea name="notes" rows="3" class="form-control" placeholder="Add specific notes, debit voucher terms, or vendor agreement reference..."></textarea>
                        </div>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px;">
                            <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px; color: #475569;">
                                <span>Subtotal:</span>
                                <span style="font-weight: 600;" id="summarySubtotal">₹0.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px; color: #475569;">
                                <span>GST / Taxes:</span>
                                <span style="font-weight: 600;" id="summaryTax">₹0.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 16px; font-weight: 800; color: #dc2626; border-top: 2px solid #e2e8f0; padding-top: 8px;">
                                <span>Total Debit:</span>
                                <span id="summaryGrandTotal">₹0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="return-modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeNewReturnModal()" style="padding: 9px 18px;">Cancel</button>
                    <button type="button" class="header-btn" id="submitReturnBtn" onclick="submitPurchaseReturnForm()" style="padding: 9px 24px; background: #dc2626; border-color: #dc2626;">
                        <span>Confirm & Process Return</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- PRINTABLE DEBIT NOTE VOUCHER MODAL (ZOHO POS PARITY) -->
    <div class="return-modal-backdrop <?= ($viewReturn && !$openNewModal) ? 'open' : '' ?>" id="voucherModal">
        <div class="return-modal-box" style="max-width: 820px;">
            <div class="return-modal-header" style="background: #0f172a; color: #ffffff;">
                <h3 class="return-modal-title" style="color: #ffffff;">
                    <span>Debit Note Voucher</span>
                    <span id="voucherRefTitle" style="font-family: monospace; color: #f87171;"><?= e($viewReturn['return_number'] ?? '') ?></span>
                </h3>
                <button type="button" class="return-modal-close" style="color: #ffffff;" onclick="closeVoucherModal()">&times;</button>
            </div>

            <div class="return-modal-body" style="padding: 24px;" id="voucherPrintArea">
                <?php if ($viewReturn): ?>
                    <div class="voucher-container">
                        <div class="voucher-header">
                            <div>
                                <h2 style="margin: 0 0 4px; font-size: 22px; font-weight: 800; color: #0f172a;">
                                    <?= e($viewReturn['business_legal_name'] ?: $viewReturn['business_name']) ?>
                                </h2>
                                <div style="font-size: 12.5px; color: #64748b; line-height: 1.5;">
                                    <?= e($viewReturn['business_address'] ?: 'Commercial Hub') ?><br>
                                    <?= e($viewReturn['business_city'] ?: 'City') ?>, <?= e($viewReturn['business_state'] ?: 'State') ?> - <?= e($viewReturn['business_pincode'] ?: '000000') ?><br>
                                    <?php if (!empty($viewReturn['business_tax_id'])): ?>
                                        <strong>GSTIN:</strong> <?= e($viewReturn['business_tax_id']) ?> |
                                    <?php endif; ?>
                                    <strong>Email:</strong> <?= e($viewReturn['business_email'] ?: 'contact@store.com') ?>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div class="voucher-title" style="color: #dc2626;">DEBIT NOTE</div>
                                <div style="font-size: 13px; font-weight: 700; color: #0f172a; margin-top: 4px;">
                                    <?= e($viewReturn['return_number']) ?>
                                </div>
                                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                    Date: <?= date('d M Y', strtotime($viewReturn['return_date'])) ?>
                                </div>
                            </div>
                        </div>

                        <div class="voucher-box-grid">
                            <div class="voucher-info-box">
                                <div style="font-weight: 700; color: #0f172a; text-transform: uppercase; font-size: 11px; margin-bottom: 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px;">
                                    Debited To (Supplier / Vendor)
                                </div>
                                <strong style="font-size: 14px; color: #0f172a;"><?= e($viewReturn['vendor_name']) ?></strong>
                                <?php if (!empty($viewReturn['vendor_company'])): ?>
                                    <div><?= e($viewReturn['vendor_company']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($viewReturn['vendor_address'])): ?>
                                    <div><?= e($viewReturn['vendor_address']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($viewReturn['vendor_gstin'])): ?>
                                    <div><strong>GSTIN:</strong> <?= e($viewReturn['vendor_gstin']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($viewReturn['vendor_phone'])): ?>
                                    <div><strong>Phone:</strong> <?= e($viewReturn['vendor_phone']) ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="voucher-info-box">
                                <div style="font-weight: 700; color: #0f172a; text-transform: uppercase; font-size: 11px; margin-bottom: 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px;">
                                    Reference & Settlement Details
                                </div>
                                <div><strong>Original Ref:</strong> <?= e($viewReturn['po_number'] ? 'PO #' . $viewReturn['po_number'] : ($viewReturn['bill_number'] ? 'Bill #' . $viewReturn['bill_number'] : 'Direct Purchase Return')) ?></div>
                                <div><strong>Return Reason:</strong> <?= e($viewReturn['reason'] ?: 'Damaged Goods') ?></div>
                                <div><strong>Settlement Mode:</strong> <?= strtoupper(str_replace('_', ' ', $viewReturn['refund_method'] ?? 'vendor_credit')) ?></div>
                                <div><strong>Prepared By:</strong> <?= e($viewReturn['creator_name'] ?? 'Staff') ?></div>
                            </div>
                        </div>

                        <table class="voucher-table">
                            <thead>
                                <tr>
                                    <th style="width: 36px;">#</th>
                                    <th>Item Description</th>
                                    <th>SKU / Barcode</th>
                                    <th style="text-align: center; width: 80px;">Qty</th>
                                    <th style="text-align: right; width: 100px;">Rate (₹)</th>
                                    <th style="text-align: right; width: 80px;">Tax (%)</th>
                                    <th style="text-align: right; width: 110px;">Amount (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $idx = 1; foreach ($viewReturn['items'] as $item): ?>
                                    <tr>
                                        <td><?= $idx++ ?></td>
                                        <td>
                                            <strong><?= e($item['product_name'] ?: $item['current_prod_name'] ?: 'Product #' . $item['product_id']) ?></strong>
                                            <?php if (!empty($item['reason'])): ?>
                                                <div style="font-size: 11px; color: #64748b;"><?= e($item['reason']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-family: monospace; font-size: 12px;"><?= e($item['product_sku'] ?: $item['current_prod_sku'] ?: '—') ?></td>
                                        <td style="text-align: center; font-weight: 700;"><?= (int)$item['quantity'] ?></td>
                                        <td style="text-align: right;">₹<?= number_format((float)$item['unit_cost'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format((float)$item['tax_percent'], 1) ?>%</td>
                                        <td style="text-align: right; font-weight: 700;">₹<?= number_format((float)$item['line_total'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="voucher-totals-box">
                            <table class="voucher-totals-table">
                                <tr>
                                    <td style="color: #64748b;">Subtotal:</td>
                                    <td style="text-align: right; font-weight: 600;">₹<?= number_format((float)$viewReturn['subtotal'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td style="color: #64748b;">Tax Amount (GST):</td>
                                    <td style="text-align: right; font-weight: 600;">₹<?= number_format((float)$viewReturn['tax_amount'], 2) ?></td>
                                </tr>
                                <tr style="border-top: 2px solid #0f172a; font-size: 16px;">
                                    <td style="font-weight: 800; color: #0f172a;">Grand Total:</td>
                                    <td style="text-align: right; font-weight: 800; color: #dc2626;">₹<?= number_format((float)$viewReturn['total_amount'], 2) ?></td>
                                </tr>
                            </table>
                        </div>

                        <div style="background: #f8fafc; border-left: 3px solid #0f172a; padding: 10px 14px; font-size: 12.5px; margin-bottom: 24px;">
                            <strong>Amount in Words:</strong> <?= e(number_to_indian_currency_words((float)$viewReturn['total_amount'])) ?>
                        </div>

                        <?php if (!empty($viewReturn['notes'])): ?>
                            <div style="font-size: 12px; color: #475569; margin-bottom: 24px;">
                                <strong>Remarks:</strong> <?= e($viewReturn['notes']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="voucher-sign-row">
                            <div style="font-size: 11.5px; color: #64748b; line-height: 1.5;">
                                This is a computer generated Debit Note.<br>
                                Inventory reversed and adjusted against vendor ledger.
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 12px; font-weight: 700; color: #0f172a; margin-bottom: 40px;">For <?= e($viewReturn['business_name']) ?></div>
                                <div style="border-top: 1px solid #94a3b8; width: 180px; padding-top: 4px; font-size: 11px; color: #64748b;">Authorized Signatory</div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div id="voucherDynamicArea">Loading voucher...</div>
                <?php endif; ?>
            </div>

            <div class="return-modal-footer">
                <button type="button" class="btn-secondary" onclick="closeVoucherModal()">Close</button>
                <button type="button" class="header-btn" onclick="window.print()" style="background: #0f172a; border-color: #0f172a; display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    <span>Print Debit Note</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentReturnMode = 'po'; // 'po', 'bill', or 'direct'
        let returnItems = []; // Array of { product_id, po_item_id, product_name, product_sku, available_to_return, quantity, unit_cost, tax_percent, line_total, reason, selected }

        function openNewReturnModal() {
            document.getElementById('newReturnModal').classList.add('open');
            // If prefilled, trigger loading
            const poSelect = document.getElementById('poSelect');
            if (poSelect && poSelect.value) {
                onPOSelected(poSelect.value);
            }
        }

        function closeNewReturnModal() {
            document.getElementById('newReturnModal').classList.remove('open');
        }

        function switchReturnMode(mode) {
            currentReturnMode = mode;
            document.getElementById('tabModePO').classList.toggle('active', mode === 'po');
            document.getElementById('tabModeBill').classList.toggle('active', mode === 'bill');
            document.getElementById('tabModeDirect').classList.toggle('active', mode === 'direct');

            document.getElementById('groupSourcePO').style.display = (mode === 'po') ? 'block' : 'none';
            document.getElementById('groupSourceBill').style.display = (mode === 'bill') ? 'block' : 'none';
            document.getElementById('directProductSearchRow').style.display = (mode === 'direct') ? 'block' : 'none';

            // Reset items
            returnItems = [];
            renderReturnItemsTable();

            if (mode === 'po') {
                const poVal = document.getElementById('poSelect').value;
                if (poVal) onPOSelected(poVal);
            } else if (mode === 'bill') {
                const billVal = document.getElementById('billSelect').value;
                if (billVal) onBillSelected(billVal);
            }
        }

        function onPOSelected(poId) {
            if (!poId) {
                returnItems = [];
                renderReturnItemsTable();
                return;
            }

            // Sync vendor select
            const opt = document.querySelector('#poSelect option[value="' + poId + '"]');
            if (opt && opt.dataset.vendor) {
                document.getElementById('vendorSelect').value = opt.dataset.vendor;
            }

            fetch('<?= asset('purchase-returns.php') ?>?ajax=1&action=get_po_items&po_id=' + encodeURIComponent(poId))
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.items) {
                        returnItems = data.items.map(it => {
                            const avail = parseInt(it.available_to_return) || 0;
                            return {
                                product_id: parseInt(it.product_id),
                                po_item_id: parseInt(it.id),
                                product_name: it.product_name || it.catalog_product_name || ('Product #' + it.product_id),
                                product_sku: it.product_sku || it.catalog_sku || '',
                                available_to_return: avail,
                                quantity: avail > 0 ? 1 : 0,
                                unit_cost: parseFloat(it.unit_cost) || 0,
                                tax_percent: parseFloat(it.tax_percent) || 0,
                                reason: '',
                                selected: avail > 0
                            };
                        });
                        renderReturnItemsTable();
                    }
                })
                .catch(err => console.error('Failed to load PO items', err));
        }

        function onBillSelected(billId) {
            if (!billId) {
                returnItems = [];
                renderReturnItemsTable();
                return;
            }

            const opt = document.querySelector('#billSelect option[value="' + billId + '"]');
            if (opt && opt.dataset.vendor) {
                document.getElementById('vendorSelect').value = opt.dataset.vendor;
            }

            fetch('<?= asset('purchase-returns.php') ?>?ajax=1&action=get_bill_items&bill_id=' + encodeURIComponent(billId))
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.items) {
                        returnItems = data.items.map(it => {
                            const qty = parseInt(it.quantity) || 1;
                            return {
                                product_id: parseInt(it.product_id),
                                po_item_id: null,
                                product_name: it.product_name || ('Product #' + it.product_id),
                                product_sku: it.product_sku || '',
                                available_to_return: qty,
                                quantity: 1,
                                unit_cost: parseFloat(it.unit_cost) || 0,
                                tax_percent: parseFloat(it.tax_percent) || 0,
                                reason: '',
                                selected: true
                            };
                        });
                        renderReturnItemsTable();
                    }
                })
                .catch(err => console.error('Failed to load Bill items', err));
        }

        // Direct product search autocomplete
        let directSearchTimeout = null;
        function onDirectProductSearch(query) {
            clearTimeout(directSearchTimeout);
            const resultsBox = document.getElementById('productSearchResults');
            if (!query || query.trim().length < 1) {
                resultsBox.style.display = 'none';
                return;
            }

            directSearchTimeout = setTimeout(() => {
                fetch('<?= asset('purchase-returns.php') ?>?ajax=1&action=search_products&q=' + encodeURIComponent(query.trim()))
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.products && data.products.length > 0) {
                            resultsBox.innerHTML = data.products.map(p => `
                                <div onclick="addDirectProduct(${p.id}, '${escapeHtml(p.name)}', '${escapeHtml(p.sku || '')}', ${parseFloat(p.cost_price || 0)}, ${parseFloat(p.tax_rate || 0)}, ${parseInt(p.stock_quantity || 0)})"
                                     style="padding: 10px 14px; border-bottom: 1px solid #f1f5f9; cursor: pointer; display: flex; justify-content: space-between; align-items: center;"
                                     onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#ffffff'">
                                    <div>
                                        <strong style="color: #0f172a;">${escapeHtml(p.name)}</strong>
                                        <div style="font-size: 11.5px; color: #64748b;">SKU: ${escapeHtml(p.sku || 'N/A')} | In Stock: ${p.stock_quantity}</div>
                                    </div>
                                    <div style="text-align: right; font-weight: 700; color: #0f172a;">₹${parseFloat(p.cost_price).toFixed(2)}</div>
                                </div>
                            `).join('');
                            resultsBox.style.display = 'block';
                        } else {
                            resultsBox.innerHTML = '<div style="padding: 12px; color: #94a3b8; text-align: center;">No matching products found</div>';
                            resultsBox.style.display = 'block';
                        }
                    });
            }, 250);
        }

        function addDirectProduct(id, name, sku, cost, taxRate, stock) {
            document.getElementById('productSearchResults').style.display = 'none';
            document.getElementById('directProductInput').value = '';

            const existing = returnItems.find(it => it.product_id === id);
            if (existing) {
                existing.quantity += 1;
                existing.selected = true;
            } else {
                returnItems.push({
                    product_id: id,
                    po_item_id: null,
                    product_name: name,
                    product_sku: sku,
                    available_to_return: Math.max(1, stock),
                    quantity: 1,
                    unit_cost: cost > 0 ? cost : 0,
                    tax_percent: taxRate > 0 ? taxRate : 0,
                    reason: '',
                    selected: true
                });
            }
            renderReturnItemsTable();
        }

        function renderReturnItemsTable() {
            const tbody = document.getElementById('returnItemsTbody');
            if (returnItems.length === 0) {
                tbody.innerHTML = `
                    <tr id="emptyItemsRow">
                        <td colspan="8" style="text-align: center; padding: 24px; color: #94a3b8;">
                            ${currentReturnMode === 'direct' ? 'Type or scan a product above to add items.' : 'Select a Purchase Order or Bill above to load returnable items.'}
                        </td>
                    </tr>
                `;
                calculateTotals();
                return;
            }

            let html = '';
            returnItems.forEach((it, idx) => {
                const sub = (it.quantity * it.unit_cost);
                const tax = sub * (it.tax_percent / 100);
                const tot = sub + tax;

                html += `
                    <tr style="${!it.selected ? 'opacity: 0.6;' : ''}">
                        <td>
                            <input type="checkbox" ${it.selected ? 'checked' : ''} onchange="toggleItemSelect(${idx}, this.checked)">
                        </td>
                        <td>
                            <strong>${escapeHtml(it.product_name)}</strong>
                            <div style="font-size: 11px; color: #64748b; font-family: monospace;">SKU: ${escapeHtml(it.product_sku || '—')}</div>
                        </td>
                        <td style="text-align: center; font-weight: 600; color: #475569;">
                            ${it.available_to_return}
                        </td>
                        <td style="text-align: center;">
                            <input type="number" min="1" max="${it.available_to_return > 0 ? it.available_to_return : 9999}" value="${it.quantity}"
                                   class="form-control" style="width: 75px; text-align: center; padding: 4px;"
                                   onchange="updateItemQty(${idx}, this.value)" ${!it.selected ? 'disabled' : ''}>
                        </td>
                        <td style="text-align: right;">
                            <input type="number" step="0.01" min="0" value="${it.unit_cost.toFixed(2)}"
                                   class="form-control" style="width: 90px; text-align: right; padding: 4px;"
                                   onchange="updateItemCost(${idx}, this.value)" ${!it.selected ? 'disabled' : ''}>
                        </td>
                        <td style="text-align: right;">
                            <input type="number" step="0.1" min="0" max="100" value="${it.tax_percent}"
                                   class="form-control" style="width: 65px; text-align: right; padding: 4px;"
                                   onchange="updateItemTax(${idx}, this.value)" ${!it.selected ? 'disabled' : ''}>
                        </td>
                        <td style="text-align: right; font-weight: 700; color: #dc2626;">
                            ₹${tot.toFixed(2)}
                        </td>
                        <td style="text-align: center;">
                            ${currentReturnMode === 'direct' ? `<button type="button" onclick="removeItem(${idx})" style="background:none; border:none; color:#ef4444; font-size:16px; cursor:pointer;" title="Remove">&times;</button>` : ''}
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
            calculateTotals();
        }

        function toggleItemSelect(idx, checked) {
            if (returnItems[idx]) {
                returnItems[idx].selected = checked;
                renderReturnItemsTable();
            }
        }

        function toggleSelectAllItems(checked) {
            returnItems.forEach(it => it.selected = checked);
            renderReturnItemsTable();
        }

        function updateItemQty(idx, val) {
            let q = parseInt(val) || 1;
            if (q < 1) q = 1;
            if (returnItems[idx].available_to_return > 0 && q > returnItems[idx].available_to_return) {
                alert('Return quantity cannot exceed available quantity (' + returnItems[idx].available_to_return + ')');
                q = returnItems[idx].available_to_return;
            }
            returnItems[idx].quantity = q;
            renderReturnItemsTable();
        }

        function updateItemCost(idx, val) {
            let c = parseFloat(val) || 0;
            if (c < 0) c = 0;
            returnItems[idx].unit_cost = c;
            renderReturnItemsTable();
        }

        function updateItemTax(idx, val) {
            let t = parseFloat(val) || 0;
            if (t < 0) t = 0;
            returnItems[idx].tax_percent = t;
            renderReturnItemsTable();
        }

        function removeItem(idx) {
            returnItems.splice(idx, 1);
            renderReturnItemsTable();
        }

        function calculateTotals() {
            let subtotal = 0;
            let totalTax = 0;

            returnItems.filter(it => it.selected && it.quantity > 0).forEach(it => {
                const lineSub = it.quantity * it.unit_cost;
                const lineTax = lineSub * (it.tax_percent / 100);
                subtotal += lineSub;
                totalTax += lineTax;
            });

            const grandTotal = subtotal + totalTax;
            document.getElementById('summarySubtotal').innerText = '₹' + subtotal.toFixed(2);
            document.getElementById('summaryTax').innerText = '₹' + totalTax.toFixed(2);
            document.getElementById('summaryGrandTotal').innerText = '₹' + grandTotal.toFixed(2);
        }

        function submitPurchaseReturnForm() {
            const vendorId = parseInt(document.getElementById('vendorSelect').value) || 0;
            if (vendorId <= 0) {
                alert('Please select a Supplier / Vendor.');
                return;
            }

            const activeItems = returnItems.filter(it => it.selected && it.quantity > 0);
            if (activeItems.length === 0) {
                alert('Please select at least one item to return with quantity greater than zero.');
                return;
            }

            document.getElementById('returnItemsJson').value = JSON.stringify(activeItems);

            const submitBtn = document.getElementById('submitReturnBtn');
            submitBtn.disabled = true;
            submitBtn.innerText = 'Processing Return...';

            document.getElementById('purchaseReturnForm').submit();
        }

        // Voucher Preview & Print Handlers
        function viewReturnVoucher(id) {
            const modal = document.getElementById('voucherModal');
            modal.classList.add('open');

            fetch('<?= asset('purchase-returns.php') ?>?ajax=1&action=get_voucher&id=' + encodeURIComponent(id))
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.return) {
                        renderVoucherModalContent(data.return);
                    } else {
                        alert(data.error || 'Failed to load voucher.');
                    }
                })
                .catch(err => {
                    console.error('Voucher load error', err);
                });
        }

        function printReturnVoucher(id) {
            viewReturnVoucher(id);
            setTimeout(() => {
                window.print();
            }, 400);
        }

        function renderVoucherModalContent(ret) {
            document.getElementById('voucherRefTitle').innerText = ret.return_number;

            const area = document.getElementById('voucherPrintArea');
            let itemsRows = '';
            (ret.items || []).forEach((it, idx) => {
                itemsRows += `
                    <tr>
                        <td>${idx + 1}</td>
                        <td>
                            <strong>${escapeHtml(it.product_name || it.current_prod_name || ('Product #' + it.product_id))}</strong>
                            ${it.reason ? `<div style="font-size:11px; color:#64748b;">${escapeHtml(it.reason)}</div>` : ''}
                        </td>
                        <td style="font-family: monospace; font-size: 12px;">${escapeHtml(it.product_sku || it.current_prod_sku || '—')}</td>
                        <td style="text-align: center; font-weight: 700;">${parseInt(it.quantity)}</td>
                        <td style="text-align: right;">₹${parseFloat(it.unit_cost).toFixed(2)}</td>
                        <td style="text-align: right;">${parseFloat(it.tax_percent).toFixed(1)}%</td>
                        <td style="text-align: right; font-weight: 700;">₹${parseFloat(it.line_total).toFixed(2)}</td>
                    </tr>
                `;
            });

            area.innerHTML = `
                <div class="voucher-container">
                    <div class="voucher-header">
                        <div>
                            <h2 style="margin: 0 0 4px; font-size: 22px; font-weight: 800; color: #0f172a;">
                                ${escapeHtml(ret.business_legal_name || ret.business_name)}
                            </h2>
                            <div style="font-size: 12.5px; color: #64748b; line-height: 1.5;">
                                ${escapeHtml(ret.business_address || 'Commercial Hub')}<br>
                                ${escapeHtml(ret.business_city || 'City')}, ${escapeHtml(ret.business_state || 'State')} - ${escapeHtml(ret.business_pincode || '000000')}<br>
                                ${ret.business_tax_id ? `<strong>GSTIN:</strong> ${escapeHtml(ret.business_tax_id)} | ` : ''}
                                <strong>Email:</strong> ${escapeHtml(ret.business_email || 'contact@store.com')}
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div class="voucher-title" style="color: #dc2626;">DEBIT NOTE</div>
                            <div style="font-size: 13px; font-weight: 700; color: #0f172a; margin-top: 4px;">
                                ${escapeHtml(ret.return_number)}
                            </div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                Date: ${escapeHtml(ret.return_date)}
                            </div>
                        </div>
                    </div>

                    <div class="voucher-box-grid">
                        <div class="voucher-info-box">
                            <div style="font-weight: 700; color: #0f172a; text-transform: uppercase; font-size: 11px; margin-bottom: 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px;">
                                Debited To (Supplier / Vendor)
                            </div>
                            <strong style="font-size: 14px; color: #0f172a;">${escapeHtml(ret.vendor_name)}</strong>
                            ${ret.vendor_company ? `<div>${escapeHtml(ret.vendor_company)}</div>` : ''}
                            ${ret.vendor_address ? `<div>${escapeHtml(ret.vendor_address)}</div>` : ''}
                            ${ret.vendor_gstin ? `<div><strong>GSTIN:</strong> ${escapeHtml(ret.vendor_gstin)}</div>` : ''}
                            ${ret.vendor_phone ? `<div><strong>Phone:</strong> ${escapeHtml(ret.vendor_phone)}</div>` : ''}
                        </div>

                        <div class="voucher-info-box">
                            <div style="font-weight: 700; color: #0f172a; text-transform: uppercase; font-size: 11px; margin-bottom: 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px;">
                                Reference & Settlement Details
                            </div>
                            <div><strong>Original Ref:</strong> ${escapeHtml(ret.po_number ? ('PO #' + ret.po_number) : (ret.bill_number ? ('Bill #' + ret.bill_number) : 'Direct Purchase Return'))}</div>
                            <div><strong>Return Reason:</strong> ${escapeHtml(ret.reason || 'Damaged Goods')}</div>
                            <div><strong>Settlement Mode:</strong> ${escapeHtml(ret.refund_method.replace('_', ' ').toUpperCase())}</div>
                            <div><strong>Prepared By:</strong> ${escapeHtml(ret.creator_name || 'Staff')}</div>
                        </div>
                    </div>

                    <table class="voucher-table">
                        <thead>
                            <tr>
                                <th style="width: 36px;">#</th>
                                <th>Item Description</th>
                                <th>SKU / Barcode</th>
                                <th style="text-align: center; width: 80px;">Qty</th>
                                <th style="text-align: right; width: 100px;">Rate (₹)</th>
                                <th style="text-align: right; width: 80px;">Tax (%)</th>
                                <th style="text-align: right; width: 110px;">Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsRows}
                        </tbody>
                    </table>

                    <div class="voucher-totals-box">
                        <table class="voucher-totals-table">
                            <tr>
                                <td style="color: #64748b;">Subtotal:</td>
                                <td style="text-align: right; font-weight: 600;">₹${parseFloat(ret.subtotal).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td style="color: #64748b;">Tax Amount (GST):</td>
                                <td style="text-align: right; font-weight: 600;">₹${parseFloat(ret.tax_amount).toFixed(2)}</td>
                            </tr>
                            <tr style="border-top: 2px solid #0f172a; font-size: 16px;">
                                <td style="font-weight: 800; color: #0f172a;">Grand Total:</td>
                                <td style="text-align: right; font-weight: 800; color: #dc2626;">₹${parseFloat(ret.total_amount).toFixed(2)}</td>
                            </tr>
                        </table>
                    </div>

                    <div style="background: #f8fafc; border-left: 3px solid #0f172a; padding: 10px 14px; font-size: 12.5px; margin-bottom: 24px;">
                        <strong>Amount in Words:</strong> ${escapeHtml(ret.amount_in_words)}
                    </div>

                    ${ret.notes ? `
                        <div style="font-size: 12px; color: #475569; margin-bottom: 24px;">
                            <strong>Remarks:</strong> ${escapeHtml(ret.notes)}
                        </div>
                    ` : ''}

                    <div class="voucher-sign-row">
                        <div style="font-size: 11.5px; color: #64748b; line-height: 1.5;">
                            This is a computer generated Debit Note.<br>
                            Inventory reversed and adjusted against vendor ledger.
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 12px; font-weight: 700; color: #0f172a; margin-bottom: 40px;">For ${escapeHtml(ret.business_name)}</div>
                            <div style="border-top: 1px solid #94a3b8; width: 180px; padding-top: 4px; font-size: 11px; color: #64748b;">Authorized Signatory</div>
                        </div>
                    </div>
                </div>
            `;
        }

        function closeVoucherModal() {
            document.getElementById('voucherModal').classList.remove('open');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }

        <?php if ($autoPrint && $viewReturn): ?>
            window.addEventListener('load', () => {
                setTimeout(() => window.print(), 350);
            });
        <?php endif; ?>
    </script>
</body>
</html>

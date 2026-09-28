<?php
/**
 * OminiFlow POS - Billing & Invoices History Screen
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/orders_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

// Handle Invoice Actions (Cancellation)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid session token.']);
            exit;
        }
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/invoices.php');
    }

    if ($action === 'cancel_invoice') {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));

        $res = cancel_invoice($invoiceId, $userId, $reason);

        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }

        if ($res['success']) {
            set_flash('success', 'Invoice #' . $res['invoice_number'] . ' was cancelled successfully and inventory stock has been restored.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not cancel invoice.');
        }
        redirect(APP_URL . '/invoices.php');
    }
}

// Search & Filter Parameters
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$invoices = get_invoices($search, $status, $dateFrom, $dateTo, 100);
$salesStats = get_sales_stats();

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing & Invoices - OminiFlow POS</title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .invoice-row {
            transition: background-color 0.15s ease;
        }
        .invoice-row.row-selected td {
            background-color: #eff6ff !important;
        }
        .inv-toast-container {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 999999;
            pointer-events: none;
        }
        .inv-toast {
            pointer-events: auto;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            animation: invToastIn 0.25s ease-out;
            margin-bottom: 10px;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        .inv-toast-warning {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .inv-toast-info {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        .inv-toast-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        @keyframes invToastIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
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
                        <h1 class="page-title">Billing & Invoices</h1>
                        <p class="page-subtitle">Track, print, and manage official retail tax invoices and customer receipts.</p>
                    </div>
                    <div class="page-top-actions">
                        <button type="button" id="topPrintInvoiceBtn" class="header-btn" style="background: #2563eb; color: #ffffff; border: 1px solid #2563eb; cursor: pointer; transition: all 0.2s ease;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            <span id="topPrintBtnText">Print Invoice</span>
                        </button>
                        <button type="button" id="topPdfInvoiceBtn" class="header-btn" style="background: #0f172a; color: #ffffff; border: 1px solid #0f172a; cursor: pointer; transition: all 0.2s ease;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            <span id="topPdfBtnText">Save PDF</span>
                        </button>
                        <a href="<?= asset('invoice-create.php') ?>" class="header-btn" style="background: #059669; color: #ffffff;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>New Invoice</span>
                        </a>
                        <a href="<?= asset('pos.php') ?>" class="btn-secondary">
                            <span>Open POS Register</span>
                        </a>
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

                <!-- KPI Metric Cards -->
                <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 24px;">
                    <div class="kpi-card">
                        <div class="kpi-header">
                            <div class="kpi-title">Total Active Invoices</div>
                            <div class="kpi-icon-badge" style="background: var(--saas-primary-soft); color: var(--saas-primary);">🧾</div>
                        </div>
                        <div class="kpi-value"><?= $salesStats['total_invoices'] ?></div>
                        <div class="kpi-trend" style="color: #059669;">
                            <span><?= $salesStats['paid_invoices'] ?> paid invoices</span>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <div class="kpi-title">Total Sales Revenue</div>
                            <div class="kpi-icon-badge" style="background: #ecfdf5; color: #047857;">💰</div>
                        </div>
                        <div class="kpi-value">₹<?= number_format($salesStats['total_revenue'], 2) ?></div>
                        <div class="kpi-trend" style="color: var(--saas-slate-500);">
                            <span>Across active POS orders</span>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <div class="kpi-title">Today's Invoiced Amount</div>
                            <div class="kpi-icon-badge" style="background: #eff6ff; color: #1d4ed8;">⚡</div>
                        </div>
                        <div class="kpi-value">₹<?= number_format($salesStats['today_revenue'], 2) ?></div>
                        <div class="kpi-trend" style="color: var(--saas-slate-500);">
                            <span><?= $salesStats['today_orders'] ?> orders processed today</span>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <div class="kpi-title">Cancelled Invoices</div>
                            <div class="kpi-icon-badge" style="background: #fef2f2; color: #b91c1c;">⚠️</div>
                        </div>
                        <div class="kpi-value" style="color: #b91c1c;"><?= $salesStats['cancelled_invoices'] ?></div>
                        <div class="kpi-trend" style="color: var(--saas-slate-500);">
                            <span>Inventory auto-restored</span>
                        </div>
                    </div>
                </div>

                <!-- Search & Filters Toolbar -->
                <div class="filter-toolbar">
                    <form method="GET" action="<?= asset('invoices.php') ?>" class="filter-form" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; width: 100%;">
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
                                placeholder="Search by Invoice #, Order #, Customer, Phone..."
                                class="form-control with-icon"
                            >
                        </div>

                        <div style="min-width: 140px;">
                            <select name="status" class="form-control">
                                <option value="">All Statuses</option>
                                <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="unpaid" <?= $status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
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

                            <?php if ($search !== '' || $status !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                                <a href="<?= asset('invoices.php') ?>" class="btn-secondary" style="padding: 8px 14px;">Reset</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Invoices Table Card -->
                <div class="section-card" style="margin-top: 18px;">
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th style="width: 44px; text-align: center;">
                                        <input type="checkbox" id="selectAllInvoices" title="Select All Invoices" style="cursor: pointer; width: 16px; height: 16px; accent-color: #2563eb;">
                                    </th>
                                    <th>Invoice #</th>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Date & Time</th>
                                    <th>Grand Total</th>
                                    <th>Payment</th>
                                    <th>Invoice Status</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($invoices)): ?>
                                    <tr>
                                        <td colspan="9">
                                            <div class="empty-state">
                                                <div class="empty-state-icon">🧾</div>
                                                <div style="font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">No Invoices Found</div>
                                                <div><?= ($search !== '' || $status !== '' || $dateFrom !== '') ? 'Try adjusting your search criteria or date filters.' : 'Completed POS checkouts will automatically generate tax invoices here.' ?></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($invoices as $inv): ?>
                                        <?php
                                            $invStatusUi = invoice_status_display($inv);
                                            $isCancelled = ($inv['invoice_status'] === 'cancelled');
                                            $statusBadge = $invStatusUi['badge'];
                                            $statusLabel = $invStatusUi['label'];
                                        ?>
                                        <tr class="invoice-row" data-invoice-id="<?= $inv['id'] ?>" style="cursor: pointer; <?= $isCancelled ? 'opacity: 0.75; background: #fffafb;' : '' ?>">
                                            <td style="text-align: center; width: 44px;">
                                                <input type="checkbox" class="invoice-select-cb" value="<?= $inv['id'] ?>" data-invoice-num="<?= e($inv['invoice_number']) ?>" style="cursor: pointer; width: 16px; height: 16px; accent-color: #2563eb;">
                                            </td>
                                            <td>
                                                <a href="<?= asset('invoice-view.php?id=' . $inv['id']) ?>" style="font-weight: 700; color: var(--saas-primary); text-decoration: none;">
                                                    <?= e($inv['invoice_number']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <a href="<?= asset('orders.php?search=' . urlencode($inv['order_number'])) ?>" style="color: var(--saas-navy-900); font-weight: 600; text-decoration: none;">
                                                    <?= e($inv['order_number']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div style="font-weight: 600; color: var(--saas-navy-950);"><?= e($inv['customer_name'] ?: 'Walk-in Customer') ?></div>
                                                <?php if (!empty($inv['customer_phone'])): ?>
                                                    <div style="font-size: 11px; color: var(--saas-slate-400);"><?= e($inv['customer_phone']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 600; color: var(--saas-navy-900);"><?= date('M d, Y', strtotime($inv['invoice_date'])) ?></div>
                                                <div style="font-size: 11px; color: var(--saas-slate-400);"><?= date('h:i A', strtotime($inv['invoice_date'])) ?></div>
                                            </td>
                                            <td>
                                                <strong style="font-size: 14px; color: <?= $isCancelled ? '#94a3b8; text-decoration: line-through;' : 'var(--saas-navy-950);' ?>">
                                                    ₹<?= number_format((float)$inv['total_amount'], 2) ?>
                                                </strong>
                                                <div style="font-size: 11px; color: var(--saas-slate-400);">Tax: ₹<?= number_format((float)$inv['tax_amount'], 2) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge badge-in-stock" style="text-transform: uppercase; font-size: 11px;">
                                                    <?= e($inv['payment_method']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= $statusBadge ?>">
                                                    <?= $statusLabel ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right;">
                                                <div style="display: inline-flex; align-items: center; gap: 6px;">
                                                    <!-- View Details Button -->
                                                    <a href="<?= asset('invoice-view.php?id=' . $inv['id']) ?>" class="btn-action view" title="View Tax Invoice Details">
                                                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                        </svg>
                                                    </a>

                                                    <!-- Print Tax Invoice Button -->
                                                    <a href="<?= asset('invoice-view.php?id=' . $inv['id'] . '&print=1') ?>" class="btn-action edit" title="Print Tax Invoice (A4 / Thermal)" target="_blank">
                                                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                        </svg>
                                                    </a>

                                                    <!-- Cancel Invoice Button (if not already cancelled) -->
                                                    <?php if (!$isCancelled): ?>
                                                        <button
                                                            type="button"
                                                            class="btn-action delete cancel-inv-trigger"
                                                            data-id="<?= $inv['id'] ?>"
                                                            data-number="<?= e($inv['invoice_number']) ?>"
                                                            title="Cancel Invoice & Restore Stock"
                                                        >
                                                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                            </svg>
                                                        </button>
                                                    <?php endif; ?>
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

    <!-- CANCEL INVOICE MODAL -->
    <div class="modal-overlay" id="cancelInvoiceModal">
        <div class="modal-box" style="max-width: 460px;">
            <div class="modal-header">
                <h3 class="modal-title" style="color: #b91c1c;">Cancel Invoice & Restore Inventory</h3>
                <button type="button" class="modal-close-btn" id="closeCancelModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('invoices.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_invoice">
                <input type="hidden" name="invoice_id" id="cancelInvoiceId" value="">

                <div class="modal-body">
                    <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 12px; border-radius: var(--saas-radius-md); color: #991b1b; font-size: 13px; margin-bottom: 14px;">
                        <strong>Warning:</strong> Cancelling invoice <span id="cancelInvoiceNumberText" style="font-weight: 800;"></span> will mark it as cancelled, exclude its revenue from sales totals, and <strong>automatically restore all sold product quantities back into inventory</strong>.
                    </div>

                    <div class="form-group">
                        <label for="cancelReasonInput" class="form-label">Cancellation Reason <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="cancelReasonInput" name="reason" required placeholder="e.g. Customer returned goods, Billing error" class="form-control">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="dismissCancelModal">Keep Invoice</button>
                    <button type="submit" class="btn-danger" style="padding: 9px 18px; border-radius: var(--saas-radius-md); font-weight: 700;">Confirm Cancellation</button>
                </div>
            </form>
        </div>
    </div>

    <!-- PRINT / DOWNLOAD CHOICE MODAL -->
    <div class="modal-overlay" id="printChoiceModal">
        <div class="modal-box" style="max-width: 480px; text-align: center;">
            <div class="modal-header" style="justify-content: space-between; border-bottom: 1px solid var(--saas-border-light);">
                <h3 class="modal-title" style="font-size: 17px; color: var(--saas-navy-950); margin: 0;">Print or Save Invoices</h3>
                <button type="button" class="modal-close-btn" id="closePrintChoiceModal">&times;</button>
            </div>
            <div class="modal-body" style="padding: 24px 20px;">
                <p style="font-size: 14px; color: var(--saas-slate-500); margin-bottom: 20px;">
                    You have selected <strong id="printChoiceCountText" style="color: var(--saas-navy-950);">0 invoices</strong>. Choose your 1-click action:
                </p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <!-- Option 1: Direct Print -->
                    <button type="button" id="choiceDirectPrintBtn" style="background: #2563eb; color: #ffffff; border: none; padding: 20px 14px; border-radius: 12px; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 10px; box-shadow: 0 4px 12px rgba(37,99,235,0.25); transition: all 0.2s ease;">
                        <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        <span style="font-size: 14px; font-weight: 800;">Direct Print</span>
                        <span style="font-size: 11px; opacity: 0.9;">Send to Printer in 1 Click</span>
                    </button>

                    <!-- Option 2: Save as PDF -->
                    <button type="button" id="choiceSavePdfBtn" style="background: #0f172a; color: #ffffff; border: none; padding: 20px 14px; border-radius: 12px; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 10px; box-shadow: 0 4px 12px rgba(15,23,42,0.25); transition: all 0.2s ease;">
                        <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <span style="font-size: 14px; font-weight: 800;">Save as PDF</span>
                        <span style="font-size: 11px; opacity: 0.9;">Export all pages to PDF</span>
                    </button>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: center; background: #f8fafc; border-top: 1px solid var(--saas-border-light);">
                <button type="button" class="btn-secondary" id="dismissPrintChoiceModal" style="padding: 8px 18px;">Cancel</button>
            </div>
        </div>
    </div>

    <div class="inv-toast-container" id="invToastContainer"></div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Cancel invoice modal handlers
            const cancelModal = document.getElementById('cancelInvoiceModal');
            const cancelIdInput = document.getElementById('cancelInvoiceId');
            const cancelNumSpan = document.getElementById('cancelInvoiceNumberText');
            const closeCancelBtn = document.getElementById('closeCancelModal');
            const dismissCancelBtn = document.getElementById('dismissCancelModal');

            document.querySelectorAll('.cancel-inv-trigger').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const id = this.getAttribute('data-id');
                    const num = this.getAttribute('data-number');
                    cancelIdInput.value = id;
                    cancelNumSpan.textContent = num;
                    cancelModal.classList.add('open');
                });
            });

            function closeModal() {
                if (cancelModal) cancelModal.classList.remove('open');
            }
            if (closeCancelBtn) closeCancelBtn.addEventListener('click', closeModal);
            if (dismissCancelBtn) dismissCancelBtn.addEventListener('click', closeModal);

            // Choice Modal handlers
            const choiceModal = document.getElementById('printChoiceModal');
            const choiceCountText = document.getElementById('printChoiceCountText');
            const closeChoiceBtn = document.getElementById('closePrintChoiceModal');
            const dismissChoiceBtn = document.getElementById('dismissPrintChoiceModal');
            const choiceDirectPrintBtn = document.getElementById('choiceDirectPrintBtn');
            const choiceSavePdfBtn = document.getElementById('choiceSavePdfBtn');

            function closeChoiceModal() {
                if (choiceModal) choiceModal.classList.remove('open');
            }
            if (closeChoiceBtn) closeChoiceBtn.addEventListener('click', closeChoiceModal);
            if (dismissChoiceBtn) dismissChoiceBtn.addEventListener('click', closeChoiceModal);

            // Toast Notification Helper
            function showInvoiceToast(message, type = 'info') {
                const container = document.getElementById('invToastContainer');
                if (!container) return;

                const toast = document.createElement('div');
                toast.className = `inv-toast inv-toast-${type}`;

                let iconSvg = '';
                if (type === 'warning') {
                    iconSvg = `<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>`;
                } else if (type === 'success') {
                    iconSvg = `<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`;
                } else {
                    iconSvg = `<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;
                }

                toast.innerHTML = `${iconSvg}<span>${message}</span>`;
                container.appendChild(toast);

                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-10px)';
                    setTimeout(() => toast.remove(), 300);
                }, 3500);
            }

            // Selection & Top Action Buttons Handlers
            const selectAllCb = document.getElementById('selectAllInvoices');
            const rowCheckboxes = document.querySelectorAll('.invoice-select-cb');
            const topPrintBtn = document.getElementById('topPrintInvoiceBtn');
            const topPrintText = document.getElementById('topPrintBtnText');
            const topPdfBtn = document.getElementById('topPdfInvoiceBtn');
            const topPdfText = document.getElementById('topPdfBtnText');

            function getSelectedInvoices() {
                const selected = [];
                document.querySelectorAll('.invoice-select-cb:checked').forEach(cb => {
                    selected.push({
                        id: cb.value,
                        number: cb.getAttribute('data-invoice-num') || ('#' + cb.value)
                    });
                });
                return selected;
            }

            function updateSelectionState() {
                const selected = getSelectedInvoices();
                const count = selected.length;

                // Update Print & PDF button text
                if (topPrintText && topPrintBtn) {
                    if (count === 0) {
                        topPrintText.textContent = 'Print Invoice';
                        topPrintBtn.style.boxShadow = 'none';
                    } else if (count === 1) {
                        topPrintText.textContent = 'Print Invoice (1)';
                        topPrintBtn.style.boxShadow = '0 4px 12px rgba(37, 99, 235, 0.35)';
                    } else {
                        topPrintText.textContent = `Print Invoices (${count})`;
                        topPrintBtn.style.boxShadow = '0 4px 12px rgba(37, 99, 235, 0.35)';
                    }
                }

                if (topPdfText && topPdfBtn) {
                    if (count === 0) {
                        topPdfText.textContent = 'Save PDF';
                        topPdfBtn.style.boxShadow = 'none';
                    } else if (count === 1) {
                        topPdfText.textContent = 'Save PDF (1)';
                        topPdfBtn.style.boxShadow = '0 4px 12px rgba(15, 23, 42, 0.35)';
                    } else {
                        topPdfText.textContent = `Save PDF (${count})`;
                        topPdfBtn.style.boxShadow = '0 4px 12px rgba(15, 23, 42, 0.35)';
                    }
                }

                // Update select all checkbox state
                if (selectAllCb) {
                    if (rowCheckboxes.length > 0 && count === rowCheckboxes.length) {
                        selectAllCb.checked = true;
                        selectAllCb.indeterminate = false;
                    } else if (count > 0) {
                        selectAllCb.checked = false;
                        selectAllCb.indeterminate = true;
                    } else {
                        selectAllCb.checked = false;
                        selectAllCb.indeterminate = false;
                    }
                }

                // Update row highlighting
                document.querySelectorAll('.invoice-row').forEach(row => {
                    const cb = row.querySelector('.invoice-select-cb');
                    if (cb && cb.checked) {
                        row.classList.add('row-selected');
                    } else {
                        row.classList.remove('row-selected');
                    }
                });
            }

            // Select all toggle
            if (selectAllCb) {
                selectAllCb.addEventListener('change', function () {
                    const checked = this.checked;
                    rowCheckboxes.forEach(cb => {
                        cb.checked = checked;
                    });
                    updateSelectionState();
                });
            }

            // Individual checkbox click
            rowCheckboxes.forEach(cb => {
                cb.addEventListener('change', function (e) {
                    e.stopPropagation();
                    updateSelectionState();
                });
                cb.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            });

            // Row click selection
            document.querySelectorAll('.invoice-row').forEach(row => {
                row.addEventListener('click', function (e) {
                    if (e.target.closest('a, button, input, select, .btn-action, .modal-overlay')) {
                        return;
                    }
                    const cb = this.querySelector('.invoice-select-cb');
                    if (cb) {
                        cb.checked = !cb.checked;
                        updateSelectionState();
                    }
                });
            });

            function executeBatchAction(actionType) {
                const selected = getSelectedInvoices();
                if (selected.length === 0) {
                    showInvoiceToast('Please click and select an invoice first.', 'warning');
                    return;
                }

                const idList = selected.map(s => s.id).join(',');
                if (actionType === 'download') {
                    showInvoiceToast(`Opening ${selected.length} invoice(s) to Save/Download PDF...`, 'info');
                    const printUrl = `<?= asset('invoice-batch-print.php') ?>?ids=${encodeURIComponent(idList)}&print=1&download=1`;
                    window.open(printUrl, '_blank');
                } else {
                    showInvoiceToast(`Opening ${selected.length} invoice(s) for Direct Print...`, 'info');
                    const printUrl = `<?= asset('invoice-batch-print.php') ?>?ids=${encodeURIComponent(idList)}&print=1`;
                    window.open(printUrl, '_blank');
                }
                closeChoiceModal();
            }

            // Top Print Invoice Button click -> Direct 1-Click Print
            if (topPrintBtn) {
                topPrintBtn.addEventListener('click', function () {
                    const selected = getSelectedInvoices();
                    if (selected.length === 0) {
                        showInvoiceToast('Please click and select an invoice to print.', 'warning');
                        return;
                    }
                    if (selected.length > 2 && choiceModal) {
                        choiceCountText.textContent = `${selected.length} invoices`;
                        choiceModal.classList.add('open');
                    } else {
                        executeBatchAction('print');
                    }
                });
            }

            // Top Save PDF Button click -> Direct 1-Click PDF Save
            if (topPdfBtn) {
                topPdfBtn.addEventListener('click', function () {
                    const selected = getSelectedInvoices();
                    if (selected.length === 0) {
                        showInvoiceToast('Please click and select an invoice to save as PDF.', 'warning');
                        return;
                    }
                    executeBatchAction('download');
                });
            }

            // Modal Button 1: Direct Print
            if (choiceDirectPrintBtn) {
                choiceDirectPrintBtn.addEventListener('click', function () {
                    executeBatchAction('print');
                });
            }

            // Modal Button 2: Save as PDF
            if (choiceSavePdfBtn) {
                choiceSavePdfBtn.addEventListener('click', function () {
                    executeBatchAction('download');
                });
            }
        });
    </script>
</body>
</html>

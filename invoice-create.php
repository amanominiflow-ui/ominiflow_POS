<?php
/**
 * OminiFlow POS - Enterprise Zoho Books / Zoho POS Parity "New Invoice" Creation System
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

$db = get_db();

// Fetch Data for Dropdowns
$customers = get_customers();
$products = get_products();
$usersStmt = $db->query("SELECT id, name FROM users WHERE status = 'active' ORDER BY name ASC");
$salespersons = $usersStmt ? $usersStmt->fetchAll() : [];

// Generate Next Invoice Number
$stmtInvCount = $db->query("SELECT COUNT(*) FROM invoices WHERE DATE(created_at) = CURDATE()");
$seqToday = (int) $stmtInvCount->fetchColumn() + 1;
$nextInvoiceNum = 'INV-' . date('Ymd') . '-' . str_pad((string)$seqToday, 4, '0', STR_PAD_LEFT);

// Handle POST Invoice Submission
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $action = $_POST['submit_action'] ?? 'save_send';
        $customerId = (int) ($_POST['customer_id'] ?? 1);
        $invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ''));
        $orderNumber = trim((string) ($_POST['order_number'] ?? ''));
        $invoiceDate = trim((string) ($_POST['invoice_date'] ?? date('Y-m-d')));
        $dueDate = trim((string) ($_POST['due_date'] ?? $invoiceDate));
        $terms = trim((string) ($_POST['terms'] ?? 'Due on Receipt'));
        $salespersonId = (int) ($_POST['salesperson_id'] ?? ($userId ?: 1));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $customerNotes = trim((string) ($_POST['customer_notes'] ?? 'Thanks for your business.'));
        $termsConditions = trim((string) ($_POST['terms_conditions'] ?? ''));
        $adjustmentAmount = (float) ($_POST['adjustment_amount'] ?? 0.00);

        // Parse line items JSON
        $itemsJson = $_POST['items_json'] ?? '[]';
        $rawItems = json_decode($itemsJson, true) ?: [];

        $invoiceStatus = ($action === 'draft') ? 'draft' : 'paid';
        $paymentMethod = ($invoiceStatus === 'paid') ? ($_POST['payment_method'] ?? 'cash') : 'credit';

        $invoiceData = [
            'customer_id' => $customerId,
            'invoice_number' => $invoiceNumber ?: $nextInvoiceNum,
            'order_number' => $orderNumber,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'terms' => $terms,
            'salesperson_id' => $salespersonId,
            'subject' => $subject,
            'notes' => $customerNotes . ($termsConditions ? "\n\nTerms: " . $termsConditions : ''),
            'payment_method' => $paymentMethod,
            'invoice_status' => $invoiceStatus,
            'items' => $rawItems,
        ];

        $res = create_custom_invoice($invoiceData, $salespersonId);

        if ($res['success']) {
            set_flash('success', "Invoice #{$res['invoice_number']} created successfully!");
            if ($action === 'save_print') {
                redirect(APP_URL . '/invoice-view.php?id=' . $res['invoice_id'] . '&print=1');
            } else {
                redirect(APP_URL . '/invoice-view.php?id=' . $res['invoice_id']);
            }
        } else {
            $error = $res['error'] ?? 'Could not create invoice. Please check item stock and values.';
        }
    }
}

$pageTitle = 'New Invoice';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        /* Zoho Books Exact Parity Interface */
        .invoice-page-bg {
            background: #ffffff;
            min-height: 100vh;
            padding: 24px 32px 100px;
        }

        .zoho-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }

        .zoho-title-left {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .zoho-form-section {
            display: grid;
            grid-template-columns: 160px 1fr;
            row-gap: 18px;
            column-gap: 20px;
            align-items: center;
            margin-bottom: 36px;
        }

        .z-label {
            font-size: 13.5px;
            font-weight: 600;
            color: #ef4444; /* Zoho red highlight for required */
        }

        .z-label.normal {
            color: #334155;
        }

        .z-input-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .z-control {
            height: 36px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 0 10px;
            font-size: 13.5px;
            color: #0f172a;
            background: #ffffff;
            outline: none;
            transition: all 0.15s ease;
        }

        .z-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
        }

        .z-cust-select-wrap {
            display: flex;
            align-items: center;
            max-width: 500px;
            width: 100%;
        }

        .z-cust-select {
            flex: 1;
            height: 36px;
            border: 1px solid #cbd5e1;
            border-right: 0;
            border-radius: 4px 0 0 4px;
            padding: 0 10px;
            font-size: 13.5px;
            outline: none;
            background: #ffffff;
        }

        .z-cust-search-btn {
            height: 36px;
            width: 38px;
            background: #2563eb;
            border: 1px solid #2563eb;
            border-radius: 0 4px 4px 0;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s;
        }

        .z-cust-search-btn:hover {
            background: #1d4ed8;
        }

        /* Item Table Bar */
        .z-item-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 36px;
            margin-bottom: 12px;
        }

        .z-item-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }

        .z-item-top-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .z-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #2563eb;
            background: transparent;
            border: 0;
            cursor: pointer;
            text-decoration: none;
        }

        .z-link-btn:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        /* Item Table */
        .z-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            background: #ffffff;
        }

        .z-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 10px 14px;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
        }

        .z-table th:last-child {
            border-right: none;
        }

        .z-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            border-right: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .z-table td:last-child {
            border-right: none;
        }

        .z-table tr:hover td {
            background: #fafafa;
        }

        .z-row-item-select {
            width: 100%;
            height: 36px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 0 10px;
            font-size: 13.5px;
            outline: none;
            background: #ffffff;
        }

        .z-row-calc-inp {
            height: 34px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 0 8px;
            font-size: 13.5px;
            color: #0f172a;
            width: 100%;
            text-align: right;
            outline: none;
        }

        .z-row-calc-inp:focus {
            border-color: #2563eb;
        }

        .z-disc-wrap {
            display: flex;
            align-items: center;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            overflow: hidden;
            background: #ffffff;
        }

        .z-disc-wrap input {
            border: none;
            height: 34px;
            padding: 0 6px;
            width: 55px;
            text-align: right;
            outline: none;
            font-size: 13px;
        }

        .z-disc-wrap select {
            border: none;
            border-left: 1px solid #cbd5e1;
            background: #f8fafc;
            height: 34px;
            padding: 0 4px;
            font-size: 12px;
            color: #64748b;
            outline: none;
            cursor: pointer;
        }

        .z-row-amount {
            font-size: 14.5px;
            font-weight: 700;
            color: #0f172a;
            text-align: right;
        }

        .z-del-btn {
            background: transparent;
            border: 0;
            color: #94a3b8;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
            padding: 4px 6px;
            border-radius: 4px;
            transition: all 0.15s;
        }

        .z-del-btn:hover {
            color: #ef4444;
            background: #fee2e2;
        }

        /* Buttons Under Table */
        .z-under-table-btns {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 14px;
        }

        .z-split-btn {
            display: inline-flex;
            align-items: center;
            background: #2563eb;
            color: #ffffff;
            border-radius: 4px;
            overflow: hidden;
        }

        .z-split-btn button {
            background: transparent;
            border: 0;
            color: #ffffff;
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .z-split-btn button:hover {
            background: rgba(0,0,0,0.1);
        }

        .z-secondary-btn {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #1e293b;
            border-radius: 4px;
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .z-secondary-btn:hover {
            background: #e2e8f0;
        }

        /* Calculation & Notes Split Section */
        .z-middle-split {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 48px;
            margin-top: 36px;
            padding-top: 24px;
            align-items: flex-start;
        }

        .z-notes-block {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .z-notes-label {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 6px;
            display: block;
        }

        .z-textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 8px 12px;
            font-size: 13px;
            color: #0f172a;
            outline: none;
            resize: vertical;
        }

        .z-textarea:focus {
            border-color: #2563eb;
        }

        .z-calc-card {
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding: 10px 0;
        }

        .z-calc-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13.5px;
            color: #1e293b;
        }

        .z-calc-row.grand-total-row {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            border-top: 1px solid #e2e8f0;
            padding-top: 14px;
            margin-top: 6px;
        }

        .z-adj-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .z-adj-tag {
            border: 1px dashed #cbd5e1;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: #475569;
            background: #ffffff;
        }

        /* Zoho Searchable Tax Popup */
        .z-tax-select-container {
            position: relative;
            width: 170px;
        }

        .z-tax-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 32px;
            border: 1px solid #3b82f6;
            border-radius: 4px;
            padding: 0 10px;
            font-size: 13px;
            background: #ffffff;
            cursor: pointer;
            user-select: none;
            transition: all 0.15s;
        }

        .z-tax-popup {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            width: 250px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.15);
            z-index: 1050;
            overflow: hidden;
        }

        .z-tax-popup.show {
            display: block;
        }

        .z-tax-search-wrap {
            padding: 8px 10px;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
        }

        .z-tax-list {
            max-height: 180px;
            overflow-y: auto;
            padding: 4px 0;
        }

        .z-tax-opt {
            padding: 8px 12px;
            font-size: 12.5px;
            color: #0f172a;
            cursor: pointer;
            transition: background 0.1s;
        }

        .z-tax-opt:hover {
            background: #eff6ff;
            color: #2563eb;
        }

        .z-tax-opt.selected {
            background: #3b82f6;
            color: #ffffff;
        }

        .z-tax-empty {
            padding: 16px 12px;
            font-size: 12px;
            color: #64748b;
            text-align: center;
            font-weight: 600;
        }

        .z-tax-popup-footer {
            padding: 8px 12px;
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
        }

        /* Information Callouts */
        .z-payment-callout {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }

        .z-callout-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .z-callout-sub {
            font-size: 12.5px;
            color: #64748b;
            margin-top: 4px;
        }

        /* Sticky Action Footer */
        .z-sticky-footer {
            position: fixed;
            bottom: 0;
            left: 64px;
            right: 0;
            height: 60px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            z-index: 990;
            transition: left 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .app-sidebar.sidebar-pinned ~ .app-main .z-sticky-footer {
            left: 304px;
        }

        .z-footer-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .z-footer-btn-white {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s;
        }

        .z-footer-btn-white:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        .z-footer-split-blue {
            display: inline-flex;
            align-items: center;
            background: #2563eb;
            border-radius: 4px;
            overflow: hidden;
        }

        .z-footer-split-blue button {
            background: transparent;
            border: 0;
            color: #ffffff;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .z-footer-split-blue button:hover {
            background: rgba(0,0,0,0.12);
        }

        .z-footer-right {
            display: flex;
            align-items: center;
            gap: 24px;
            font-size: 13.5px;
            font-weight: 600;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content" style="padding: 0; background: #ffffff;">
                <div class="invoice-page-bg">
                    <?php if ($error): ?>
                        <div class="saas-alert saas-alert-danger" style="margin-bottom: 20px;">
                            <span>⚠️ <?= e($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form action="<?= asset('invoice-create.php') ?>" method="POST" id="newInvoiceForm">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="submit_action" id="submitActionInp" value="save_send">
                        <input type="hidden" name="items_json" id="itemsJsonInp" value="[]">

                        <!-- Page Header -->
                        <div class="zoho-header-top">
                            <div class="zoho-title-left">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #64748b;">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                <span>New Invoice</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <a href="<?= asset('invoices.php') ?>" class="z-del-btn" style="font-size: 22px; color: #64748b;" title="Close">&times;</a>
                            </div>
                        </div>

                        <!-- Top Form Fields -->
                        <div class="zoho-form-section">
                            <!-- Customer Name -->
                            <div class="z-label">Customer Name*</div>
                            <div class="z-cust-select-wrap">
                                <select name="customer_id" id="customerSelect" class="z-cust-select">
                                    <option value="1">Select or add a customer</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?= $c['id'] ?>">
                                            <?= e($c['name']) ?> <?= !empty($c['phone']) ? '(' . e($c['phone']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="z-cust-search-btn" onclick="openNewCustomerModal()" title="Add Customer / Search">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                </button>
                            </div>

                            <!-- Invoice # -->
                            <div class="z-label">Invoice#*</div>
                            <div class="z-input-row">
                                <input type="text" name="invoice_number" value="<?= e($nextInvoiceNum) ?>" class="z-control" style="width: 220px; font-weight: 600;" required>
                            </div>

                            <!-- Order Number -->
                            <div class="z-label normal">Order Number</div>
                            <div class="z-input-row">
                                <input type="text" name="order_number" class="z-control" style="width: 220px;">
                            </div>

                            <!-- Invoice Date, Terms, Due Date -->
                            <div class="z-label">Invoice Date*</div>
                            <div class="z-input-row">
                                <input type="date" name="invoice_date" id="invoiceDateInp" value="<?= date('Y-m-d') ?>" class="z-control" style="width: 160px;" onchange="recalcDueDate()">

                                <span class="z-label normal" style="margin-left: 16px;">Terms</span>
                                <select name="terms" id="termsSelect" class="z-control" style="width: 150px;" onchange="recalcDueDate()">
                                    <option value="Due on Receipt">Due on Receipt</option>
                                    <option value="Net 15">Net 15</option>
                                    <option value="Net 30">Net 30</option>
                                    <option value="Net 45">Net 45</option>
                                    <option value="Net 60">Net 60</option>
                                </select>

                                <span class="z-label normal" style="margin-left: 16px;">Due Date</span>
                                <input type="date" name="due_date" id="dueDateInp" value="<?= date('Y-m-d') ?>" class="z-control" style="width: 160px;">
                            </div>

                            <!-- Salesperson -->
                            <div class="z-label normal">Salesperson</div>
                            <div class="z-input-row">
                                <select name="salesperson_id" class="z-control" style="width: 260px;">
                                    <option value="">Select or Add Salesperson</option>
                                    <?php foreach ($salespersons as $sp): ?>
                                        <option value="<?= $sp['id'] ?>" <?= (int)$sp['id'] === $userId ? 'selected' : '' ?>>
                                            <?= e($sp['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Subject -->
                            <div class="z-label normal">Subject</div>
                            <div class="z-input-row" style="max-width: 600px;">
                                <input type="text" name="subject" placeholder="Let your customer know what this Invoice is for" class="z-control" style="width: 100%;">
                            </div>
                        </div>

                        <!-- Item Table Header Bar -->
                        <div class="z-item-bar">
                            <div class="z-item-title">Item Table</div>
                            <div class="z-item-top-actions">
                                <button type="button" class="z-link-btn" onclick="openBarcodeScanner()">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                                    <span>Scan Item</span>
                                </button>
                                <button type="button" class="z-link-btn" onclick="openBulkAddModal()">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                    <span>Bulk Actions</span>
                                </button>
                            </div>
                        </div>

                        <!-- Item Grid Table -->
                        <table class="z-table" id="itemsTable">
                            <thead>
                                <tr>
                                    <th style="width: 44%; text-align: left;">ITEM DETAILS</th>
                                    <th style="width: 14%; text-align: right;">QUANTITY</th>
                                    <th style="width: 15%; text-align: right;">RATE (₹)</th>
                                    <th style="width: 14%; text-align: right;">DISCOUNT</th>
                                    <th style="width: 13%; text-align: right;">AMOUNT (₹)</th>
                                    <th style="width: 4%; text-align: center;"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsTbody">
                                <!-- Generated Rows -->
                            </tbody>
                        </table>

                        <!-- Under Table Action Buttons -->
                        <div class="z-under-table-btns">
                            <div class="z-split-btn">
                                <button type="button" onclick="addNewRow()">+ Add New Row</button>
                            </div>
                            <button type="button" class="z-secondary-btn" onclick="openBulkAddModal()">
                                <span>+ Add Items in Bulk</span>
                            </button>
                        </div>

                        <!-- Middle Section (Notes & Calculation) -->
                        <div class="z-middle-split">
                            <!-- Left: Notes, Terms, Attachments -->
                            <div class="z-notes-block">
                                <div>
                                    <label class="z-notes-label">Customer Notes</label>
                                    <textarea name="customer_notes" rows="3" class="z-textarea">Thanks for your business.</textarea>
                                    <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Will be displayed on the invoice</div>
                                </div>

                                <div>
                                    <label class="z-notes-label">Terms & Conditions</label>
                                    <textarea name="terms_conditions" rows="3" placeholder="Enter the terms and conditions of your business to be displayed in your transaction" class="z-textarea"></textarea>
                                </div>

                                <div>
                                    <label class="z-notes-label">Attach File(s) to Invoice</label>
                                    <button type="button" class="z-secondary-btn" onclick="document.getElementById('fileUploadInput').click()">
                                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                        <span>Upload File ▾</span>
                                    </button>
                                    <input type="file" id="fileUploadInput" style="display: none;" multiple>
                                    <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">You can upload a maximum of 10 files, 10MB each</div>
                                </div>
                            </div>

                            <!-- Right: Calculations -->
                            <div class="z-calc-card">
                                <div class="z-calc-row">
                                    <span style="font-weight: 600;">Sub Total</span>
                                    <span id="subTotalDisplay" style="font-weight: 700;">0.00</span>
                                </div>

                                <!-- Zoho TDS / TCS Interactive Row -->
                                <div class="z-calc-row" style="position: relative;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer; color: #334155;">
                                            <input type="radio" name="tds_type" id="radioTDS" value="tds" checked onchange="onTdsTypeChange()">
                                            <span>TDS</span>
                                        </label>
                                        <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer; color: #334155;">
                                            <input type="radio" name="tds_type" id="radioTCS" value="tcs" onchange="onTdsTypeChange()">
                                            <span>TCS</span>
                                        </label>

                                        <!-- Custom Zoho Select Trigger Box -->
                                        <div class="z-tax-select-container">
                                            <div class="z-tax-trigger" id="taxSelectTrigger" onclick="toggleTaxDropdown(event)">
                                                <span id="taxSelectLabel" style="color: #64748b; font-size: 13px;">Select a Tax</span>
                                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="taxSelectArrow"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                            </div>

                                            <!-- Custom Zoho Popup Menu -->
                                            <div class="z-tax-popup" id="taxSelectPopup" onclick="event.stopPropagation()">
                                                <div class="z-tax-search-wrap">
                                                    <div style="display: flex; align-items: center; gap: 6px; padding: 4px 8px; border: 1px solid #3b82f6; border-radius: 4px;">
                                                        <svg width="14" height="14" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                                        <input type="text" id="taxSearchInp" placeholder="Search" style="border: none; outline: none; font-size: 12.5px; width: 100%;" oninput="filterTaxOptions(this.value)">
                                                    </div>
                                                </div>

                                                <div class="z-tax-list" id="taxOptionsList">
                                                    <!-- Rendered by JS -->
                                                </div>

                                                <div class="z-tax-popup-footer">
                                                    <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #2563eb; cursor: pointer;" onclick="closeTaxDropdown()">
                                                        <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                                        <span id="manageTaxLabel">Manage TDS</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <span style="font-size: 12px; color: #94a3b8; cursor: help;" title="Tax Deducted / Collected at Source">ⓘ</span>
                                    </div>
                                    <span id="tdsDisplay" style="color: #64748b;">- 0.00</span>
                                </div>

                                <!-- Adjustment Row -->
                                <div class="z-calc-row">
                                    <div class="z-adj-box">
                                        <span class="z-adj-tag">Adjustment</span>
                                        <input type="number" step="0.01" name="adjustment_amount" id="adjustmentInp" value="0.00" class="z-control" style="width: 80px; height: 32px; text-align: right;" oninput="calculateTotals()">
                                        <span style="font-size: 12px; color: #94a3b8; cursor: help;" title="Round-off or custom adjustment amount">ⓘ</span>
                                    </div>
                                    <span id="adjDisplay">0.00</span>
                                </div>

                                <!-- Grand Total Row -->
                                <div class="z-calc-row grand-total-row">
                                    <span>Total ( ₹ )</span>
                                    <span id="grandTotalDisplay" style="font-size: 20px; font-weight: 800; color: #0f172a;">0.00</span>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Gateways Promo Banner -->
                        <div class="z-payment-callout">
                            <div class="z-callout-title">
                                <span>Want to get paid faster?</span>
                                <span style="font-size: 15px;">🔴🟡</span>
                                <span style="font-weight: 900; color: #1a1f71; letter-spacing: 0.05em;">VISA</span>
                            </div>
                            <div class="z-callout-sub">
                                Configure payment gateways and receive payments online. <a href="<?= asset('settings.php') ?>" style="color: #2563eb; text-decoration: none; font-weight: 600;">Set up Payment Gateway</a>
                            </div>
                            <div style="font-size: 12px; color: #94a3b8; margin-top: 18px;">
                                Additional Fields: Start adding custom fields for your invoices by going to <em>Settings ➔ Sales ➔ Invoices</em>.
                            </div>
                        </div>

                        <!-- Sticky Bottom Action Footer -->
                        <div class="z-sticky-footer">
                            <div class="z-footer-left">
                                <button type="button" class="z-footer-btn-white" onclick="submitInvoiceForm('draft')">
                                    Save as Draft
                                </button>
                                <div class="z-footer-split-blue">
                                    <button type="button" onclick="submitInvoiceForm('save_send')">
                                        Save and Send
                                    </button>
                                    <button type="button" onclick="submitInvoiceForm('save_print')" style="border-left: 1px solid rgba(255,255,255,0.2); padding: 8px 10px;" title="Save as Paid & Print">
                                        ▾
                                    </button>
                                </div>
                                <a href="<?= asset('invoices.php') ?>" class="z-footer-btn-white" style="text-decoration: none;">
                                    Cancel
                                </a>
                            </div>

                            <div class="z-footer-right">
                                <span>Total Quantity: <strong id="totalQtyDisplay" style="color: #0f172a;">0</strong></span>
                                <span>Total Amount: <strong id="footerTotalDisplay" style="color: #0f172a; font-size: 16px; font-weight: 800;">₹ 0.00</strong></span>
                            </div>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>

    <!-- Quick Add Customer Modal -->
    <div class="modal-overlay" id="customerModal">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">Add New Customer</div>
                <button type="button" class="modal-close-btn" onclick="closeCustomerModal()">&times;</button>
            </div>
            <div style="padding: 20px 24px;">
                <div style="margin-bottom: 14px;">
                    <label class="form-label-zoho required" style="display: block; margin-bottom: 6px;">Customer Name</label>
                    <input type="text" id="newCustName" class="form-control-zoho" style="width: 100%;" required>
                </div>
                <div style="margin-bottom: 14px;">
                    <label class="form-label-zoho" style="display: block; margin-bottom: 6px;">Phone Number</label>
                    <input type="tel" id="newCustPhone" class="form-control-zoho" style="width: 100%;">
                </div>
                <div style="margin-bottom: 14px;">
                    <label class="form-label-zoho" style="display: block; margin-bottom: 6px;">Email Address</label>
                    <input type="email" id="newCustEmail" class="form-control-zoho" style="width: 100%;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" class="btn-secondary" onclick="closeCustomerModal()">Cancel</button>
                    <button type="button" class="btn-primary" onclick="saveQuickCustomer()">Save Customer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Barcode Scanner Modal -->
    <div class="modal-overlay" id="barcodeScanModal">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">Scan Barcode / Enter SKU</div>
                <button type="button" class="modal-close-btn" onclick="closeBarcodeScanner()">&times;</button>
            </div>
            <div style="padding: 20px 24px;">
                <input type="text" id="barcodeScanInp" placeholder="Scan item barcode with laser scanner..." class="form-control-zoho" style="width: 100%; height: 42px; font-size: 15px;" autofocus>
                <div style="font-size: 12px; color: #64748b; margin-top: 8px;">Press Enter to immediately add item to invoice table.</div>
            </div>
        </div>
    </div>

    <!-- Bulk Add Modal -->
    <div class="modal-overlay" id="bulkAddModal">
        <div class="modal-box" style="max-width: 600px;">
            <div class="modal-header">
                <div class="modal-title">Select Items in Bulk</div>
                <button type="button" class="modal-close-btn" onclick="closeBulkAddModal()">&times;</button>
            </div>
            <div style="padding: 16px 20px; max-height: 400px; overflow-y: auto;">
                <?php foreach ($products as $p): ?>
                    <label style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; border-bottom: 1px solid #f1f5f9; cursor: pointer;">
                        <span style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" class="bulk-prod-chk" value="<?= $p['id'] ?>">
                            <span style="font-size: 13.5px; font-weight: 600; color: #0f172a;"><?= e($p['name']) ?></span>
                            <span style="font-size: 11.5px; color: #64748b;">(SKU: <?= e($p['sku']) ?>)</span>
                        </span>
                        <span style="font-weight: 700; color: #047857;">₹<?= number_format((float)$p['selling_price'], 2) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px; padding: 14px 20px; border-top: 1px solid #e2e8f0;">
                <button type="button" class="btn-secondary" onclick="closeBulkAddModal()">Cancel</button>
                <button type="button" class="btn-primary" onclick="addSelectedBulkItems()">Add Selected Items</button>
            </div>
        </div>
    </div>

    <script>
        // Products Master Array
        var availableProducts = <?= json_encode(array_map(function($p) {
            return [
                'id' => (int)$p['id'],
                'name' => (string)$p['name'],
                'sku' => (string)$p['sku'],
                'barcode' => (string)($p['barcode'] ?? ''),
                'price' => (float)$p['selling_price'],
                'tax' => (float)($p['tax_percent'] ?? 18.0),
                'stock' => (int)$p['stock_quantity'],
            ];
        }, $products)) ?>;

        var rowCounter = 0;

        function addNewRow(productId, qty) {
            rowCounter++;
            var rowId = 'row_' + rowCounter;
            var tbody = document.getElementById('itemsTbody');

            var tr = document.createElement('tr');
            tr.id = rowId;
            tr.innerHTML = `
                <td>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: #94a3b8; font-size: 16px; cursor: grab;">⋮⋮</span>
                        <select class="z-row-item-select" id="sel_${rowId}" onchange="onProductSelect('${rowId}')">
                            <option value="">Type or click to select an item.</option>
                            ${availableProducts.map(p => `<option value="${p.id}" ${productId && p.id === productId ? 'selected' : ''}>${escapeHtml(p.name)} [SKU: ${escapeHtml(p.sku)}] (Stock: ${p.stock})</option>`).join('')}
                        </select>
                    </div>
                </td>
                <td>
                    <input type="number" min="1" step="1" value="${qty || 1}" class="z-row-calc-inp" id="qty_${rowId}" oninput="calculateTotals()">
                </td>
                <td>
                    <input type="number" min="0" step="0.01" value="0.00" class="z-row-calc-inp" id="rate_${rowId}" oninput="calculateTotals()">
                </td>
                <td>
                    <div class="z-disc-wrap">
                        <input type="number" min="0" max="100" step="0.5" value="0" id="disc_${rowId}" oninput="calculateTotals()">
                        <select id="disc_type_${rowId}" onchange="calculateTotals()">
                            <option value="percent">%</option>
                            <option value="fixed">₹</option>
                        </select>
                    </div>
                </td>
                <td>
                    <div class="z-row-amount" id="amt_${rowId}">0.00</div>
                </td>
                <td style="text-align: center;">
                    <button type="button" class="z-del-btn" onclick="removeRow('${rowId}')" title="Delete Line Item">&times;</button>
                </td>
            `;

            tbody.appendChild(tr);

            if (productId) {
                onProductSelect(rowId);
            } else {
                calculateTotals();
            }
        }

        function onProductSelect(rowId) {
            var sel = document.getElementById('sel_' + rowId);
            var rateInp = document.getElementById('rate_' + rowId);
            if (!sel || !rateInp) return;

            var pid = parseInt(sel.value, 10);
            var prod = availableProducts.find(p => p.id === pid);
            if (prod) {
                rateInp.value = prod.price.toFixed(2);
            }
            calculateTotals();
        }

        function removeRow(rowId) {
            var tbody = document.getElementById('itemsTbody');
            var tr = document.getElementById(rowId);
            if (tr && tbody.children.length > 1) {
                tr.remove();
                calculateTotals();
            } else if (tbody.children.length === 1) {
                alert('An invoice must contain at least one line item.');
            }
        }

        // Zoho TDS and TCS Predefined Tax Lists
        var tdsOptions = [
            { label: 'Dividend [10%]', rate: 10.0 },
            { label: 'Other Interest than securities [10%]', rate: 10.0 },
            { label: 'Payment of contractors for Others [2%]', rate: 2.0 },
            { label: 'Payment of contractors HUF/Indiv [1%]', rate: 1.0 },
            { label: 'Technical Fees (2%) [2%]', rate: 2.0 }
        ];

        var tcsOptions = [
            { label: 'TCS on sale of goods [0.1%]', rate: 0.1 },
            { label: 'TCS on scrap sale [1%]', rate: 1.0 },
            { label: 'TCS on minerals [2%]', rate: 2.0 }
        ];

        var currentTaxSelection = { type: 'tds', label: 'Select a Tax', rate: 0.0 };

        function onTdsTypeChange() {
            var isTds = document.getElementById('radioTDS').checked;
            currentTaxSelection = { type: isTds ? 'tds' : 'tcs', label: 'Select a Tax', rate: 0.0 };
            document.getElementById('taxSelectLabel').textContent = 'Select a Tax';
            document.getElementById('taxSelectLabel').style.color = '#64748b';
            document.getElementById('manageTaxLabel').textContent = isTds ? 'Manage TDS' : 'Manage TCS';
            renderTaxOptions('');
            calculateTotals();
        }

        function toggleTaxDropdown(e) {
            if (e) e.stopPropagation();
            var popup = document.getElementById('taxSelectPopup');
            var trigger = document.getElementById('taxSelectTrigger');
            var isOpen = popup.classList.contains('show');
            if (isOpen) {
                closeTaxDropdown();
            } else {
                popup.classList.add('show');
                trigger.classList.add('open');
                renderTaxOptions('');
                var searchInp = document.getElementById('taxSearchInp');
                searchInp.value = '';
                setTimeout(() => searchInp.focus(), 50);
            }
        }

        function closeTaxDropdown() {
            var popup = document.getElementById('taxSelectPopup');
            var trigger = document.getElementById('taxSelectTrigger');
            if (popup) popup.classList.remove('show');
            if (trigger) trigger.classList.remove('open');
        }

        document.addEventListener('click', function(e) {
            var popup = document.getElementById('taxSelectPopup');
            var container = document.querySelector('.z-tax-select-container');
            if (popup && container && !container.contains(e.target)) {
                closeTaxDropdown();
            }
        });

        function filterTaxOptions(query) {
            renderTaxOptions(query);
        }

        function renderTaxOptions(query) {
            var isTds = document.getElementById('radioTDS').checked;
            var list = isTds ? tdsOptions : tcsOptions;
            var q = (query || '').toLowerCase().trim();
            var filtered = list.filter(item => item.label.toLowerCase().indexOf(q) !== -1);
            var container = document.getElementById('taxOptionsList');

            if (filtered.length === 0) {
                container.innerHTML = '<div class="z-tax-empty">NO RESULTS FOUND</div>';
                return;
            }

            var html = '';
            // None / Reset Option
            html += `<div class="z-tax-opt ${currentTaxSelection.rate === 0 ? 'selected' : ''}" onclick="selectTaxOption('Select a Tax', 0)">None (0%)</div>`;
            filtered.forEach(function(item) {
                var isSel = (currentTaxSelection.label === item.label);
                html += `<div class="z-tax-opt ${isSel ? 'selected' : ''}" onclick="selectTaxOption('${escapeHtml(item.label)}', ${item.rate})">${escapeHtml(item.label)}</div>`;
            });
            container.innerHTML = html;
        }

        function selectTaxOption(label, rate) {
            var isTds = document.getElementById('radioTDS').checked;
            currentTaxSelection = {
                type: isTds ? 'tds' : 'tcs',
                label: label,
                rate: rate
            };
            var labelEl = document.getElementById('taxSelectLabel');
            labelEl.textContent = label;
            labelEl.style.color = (rate > 0) ? '#0f172a' : '#64748b';
            closeTaxDropdown();
            calculateTotals();
        }

        function calculateTotals() {
            var tbody = document.getElementById('itemsTbody');
            var rows = tbody.querySelectorAll('tr');

            var subTotal = 0.0;
            var totalQty = 0;
            var itemsData = [];

            rows.forEach(function(tr) {
                var rowId = tr.id;
                var sel = document.getElementById('sel_' + rowId);
                var qtyInp = document.getElementById('qty_' + rowId);
                var rateInp = document.getElementById('rate_' + rowId);
                var discInp = document.getElementById('disc_' + rowId);
                var discTypeSel = document.getElementById('disc_type_' + rowId);
                var amtDisplay = document.getElementById('amt_' + rowId);

                var pid = parseInt(sel ? sel.value : 0, 10);
                var qty = Math.max(1, parseFloat(qtyInp ? qtyInp.value : 1) || 1);
                var rate = Math.max(0, parseFloat(rateInp ? rateInp.value : 0) || 0);
                var discVal = Math.max(0, parseFloat(discInp ? discInp.value : 0) || 0);
                var discType = discTypeSel ? discTypeSel.value : 'percent';

                var prod = availableProducts.find(p => p.id === pid);
                var taxPercent = prod ? prod.tax : 18.0;

                var lineBase = rate * qty;
                var lineDisc = (discType === 'percent') ? (lineBase * (discVal / 100.0)) : Math.min(lineBase, discVal);
                var lineTaxable = Math.max(0, lineBase - lineDisc);

                if (amtDisplay) {
                    amtDisplay.textContent = lineTaxable.toFixed(2);
                }

                if (pid > 0 || rate > 0) {
                    subTotal += lineTaxable;
                    totalQty += qty;

                    itemsData.push({
                        product_id: pid,
                        quantity: qty,
                        unit_price: rate,
                        discount: discVal,
                        discount_type: discType,
                        tax_percent: taxPercent
                    });
                }
            });

            // TDS/TCS Calculation
            var taxRate = currentTaxSelection.rate || 0.0;
            var taxAmount = subTotal * (taxRate / 100.0);
            var isTds = (currentTaxSelection.type === 'tds');

            var tdsDisplay = document.getElementById('tdsDisplay');
            if (tdsDisplay) {
                if (taxRate > 0) {
                    tdsDisplay.textContent = (isTds ? '- ' : '+ ') + taxAmount.toFixed(2);
                } else {
                    tdsDisplay.textContent = (isTds ? '- ' : '') + '0.00';
                }
            }

            // Adjustment
            var adjInp = document.getElementById('adjustmentInp');
            var adjVal = parseFloat(adjInp ? adjInp.value : 0) || 0.0;
            var adjDisplay = document.getElementById('adjDisplay');
            if (adjDisplay) {
                adjDisplay.textContent = adjVal.toFixed(2);
            }

            // Grand Total Calculation
            var taxDelta = isTds ? -taxAmount : taxAmount;
            var grandTotal = Math.max(0, subTotal + taxDelta + adjVal);

            // Update Displays
            document.getElementById('subTotalDisplay').textContent = subTotal.toFixed(2);
            document.getElementById('grandTotalDisplay').textContent = grandTotal.toFixed(2);
            document.getElementById('footerTotalDisplay').textContent = '₹ ' + grandTotal.toFixed(2);
            document.getElementById('totalQtyDisplay').textContent = totalQty;

            document.getElementById('itemsJsonInp').value = JSON.stringify(itemsData);
        }

        function recalcDueDate() {
            var dInp = document.getElementById('invoiceDateInp');
            var tSel = document.getElementById('termsSelect');
            var dueInp = document.getElementById('dueDateInp');
            if (!dInp || !tSel || !dueInp) return;

            var baseDate = new Date(dInp.value || new Date());
            var term = tSel.value;
            var addDays = 0;

            if (term === 'Net 15') addDays = 15;
            else if (term === 'Net 30') addDays = 30;
            else if (term === 'Net 45') addDays = 45;
            else if (term === 'Net 60') addDays = 60;

            baseDate.setDate(baseDate.getDate() + addDays);
            var yyyy = baseDate.getFullYear();
            var mm = String(baseDate.getMonth() + 1).padStart(2, '0');
            var dd = String(baseDate.getDate()).padStart(2, '0');
            dueInp.value = `${yyyy}-${mm}-${dd}`;
        }

        function submitInvoiceForm(action) {
            var itemsJson = document.getElementById('itemsJsonInp').value;
            var items = JSON.parse(itemsJson || '[]');

            if (items.length === 0) {
                alert('Please select at least one valid item for this invoice.');
                return;
            }

            document.getElementById('submitActionInp').value = action;
            document.getElementById('newInvoiceForm').submit();
        }

        // Quick Customer Modal
        function openNewCustomerModal() {
            document.getElementById('customerModal').classList.add('open');
            document.getElementById('newCustName').focus();
        }

        function closeCustomerModal() {
            document.getElementById('customerModal').classList.remove('open');
        }

        function saveQuickCustomer() {
            var name = document.getElementById('newCustName').value.trim();
            var phone = document.getElementById('newCustPhone').value.trim();
            var email = document.getElementById('newCustEmail').value.trim();

            if (!name) {
                alert('Customer name is required.');
                return;
            }

            var formData = new FormData();
            formData.append('csrf_token', '<?= csrf_token() ?>');
            formData.append('action', 'save_customer');
            formData.append('name', name);
            formData.append('phone', phone);
            formData.append('email', email);

            fetch('<?= asset('customers.php') ?>', { method: 'POST', body: formData })
                .then(() => {
                    var sel = document.getElementById('customerSelect');
                    var opt = document.createElement('option');
                    opt.value = "999";
                    opt.textContent = name + (phone ? ' (' + phone + ')' : '');
                    opt.selected = true;
                    sel.appendChild(opt);
                    closeCustomerModal();
                })
                .catch(() => {
                    closeCustomerModal();
                });
        }

        // Barcode Scanner Modal
        function openBarcodeScanner() {
            document.getElementById('barcodeScanModal').classList.add('open');
            var inp = document.getElementById('barcodeScanInp');
            inp.value = '';
            setTimeout(() => inp.focus(), 100);
        }

        function closeBarcodeScanner() {
            document.getElementById('barcodeScanModal').classList.remove('open');
        }

        document.getElementById('barcodeScanInp').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var val = this.value.trim().toLowerCase();
                var found = availableProducts.find(p => (p.barcode && p.barcode.toLowerCase() === val) || p.sku.toLowerCase() === val);
                if (found) {
                    addNewRow(found.id, 1);
                    closeBarcodeScanner();
                } else {
                    alert('No product found with Barcode/SKU: ' + val);
                }
            }
        });

        // Bulk Add Modal
        function openBulkAddModal() {
            document.getElementById('bulkAddModal').classList.add('open');
        }

        function closeBulkAddModal() {
            document.getElementById('bulkAddModal').classList.remove('open');
        }

        function addSelectedBulkItems() {
            var checkboxes = document.querySelectorAll('.bulk-prod-chk:checked');
            checkboxes.forEach(function(chk) {
                var pid = parseInt(chk.value, 10);
                if (pid > 0) {
                    addNewRow(pid, 1);
                }
                chk.checked = false;
            });
            closeBulkAddModal();
        }

        function escapeHtml(str) {
            return (str + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Initialize 1 default row on load
        document.addEventListener('DOMContentLoaded', function() {
            addNewRow();
        });
    </script>
</body>
</html>

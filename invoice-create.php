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

// Fetch Business & Settings
$bid = current_business_id();
$storeSettings = get_store_settings($bid);
$businessState = !empty($storeSettings['state']) ? $storeSettings['state'] : 'West Bengal';

// Fetch Data for Dropdowns
$customers = get_customers();
$products = get_products();
$usersStmt = $db->query("SELECT id, name FROM users WHERE status = 'active' ORDER BY name ASC");
$salespersons = $usersStmt ? $usersStmt->fetchAll() : [];

// Generate Next Invoice Number
$nextInvoiceNum = generate_next_invoice_number($bid, $db);

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
        $paymentMethod = ($invoiceStatus === 'paid') ? ($_POST['payment_method'] ?? 'cod') : 'credit';

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
            gap: 16px;
            padding: 10px 0;
            max-width: 520px;
            margin-left: auto;
            width: 100%;
        }

        .z-calc-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13.5px;
            color: #1e293b;
            gap: 16px;
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
            border: 1px dashed #94a3b8;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12.5px;
            color: #334155;
            background: #f8fafc;
            font-weight: 500;
        }

        /* Zoho Searchable Tax Popup */
        .z-tax-select-container {
            position: relative;
            width: 240px;
            max-width: 100%;
        }

        .z-tax-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 34px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 0 10px;
            font-size: 12.5px;
            background: #ffffff;
            cursor: pointer;
            user-select: none;
            transition: all 0.15s;
            gap: 6px;
            box-sizing: border-box;
            white-space: nowrap;
            overflow: hidden;
        }

        .z-tax-trigger:hover,
        .z-tax-trigger.open {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.12);
        }

        #taxSelectLabel {
            display: inline-block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 195px;
            font-size: 12.5px;
            color: #0f172a;
            line-height: 1.2;
        }

        .z-tax-popup {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            width: 320px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.15);
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

        /* Zoho New Customer Modal Styles */
        .z-cust-modal-box {
            max-width: 820px;
            width: 95%;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        .z-cust-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
            background: #ffffff;
        }

        .z-cust-modal-title {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
        }

        .z-cust-modal-body {
            padding: 24px 28px;
            overflow-y: auto;
            flex: 1;
        }

        .z-cust-grid {
            display: grid;
            grid-template-columns: 150px 1fr;
            row-gap: 16px;
            column-gap: 20px;
            align-items: center;
        }

        .z-cust-tabs-bar {
            display: flex;
            align-items: center;
            gap: 28px;
            border-bottom: 1px solid #e2e8f0;
            margin-top: 28px;
            margin-bottom: 24px;
        }

        .z-cust-tab-btn {
            padding: 8px 4px 12px;
            font-size: 13.5px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            transition: all 0.15s;
        }

        .z-cust-tab-btn.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
        }

        .z-cust-tab-pane {
            display: none;
        }

        .z-cust-tab-pane.active {
            display: block;
        }

        .z-cust-modal-footer {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 28px;
            border-top: 1px solid #e2e8f0;
            background: #ffffff;
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
                                <select name="customer_id" id="customerSelect" class="z-cust-select" onchange="handleCustomerSelect(this.value)">
                                    <option value="">Select or add a customer</option>
                                    <option value="__add_new__" style="color: #2563eb; font-weight: 700; background: #eff6ff;">+ Add New Customer</option>
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

                            <!-- Location & Source of Supply -->
                            <div class="z-label normal">Location</div>
                            <div class="z-input-row" style="align-items: center; gap: 24px;">
                                <select name="location" class="z-control" style="width: 220px;">
                                    <option value="Head Office">Head Office</option>
                                </select>
                                <span style="font-size: 13px; color: #64748b;">Source of Supply: <strong style="color: #0f172a; font-weight: 600;"><?= e($businessState) ?></strong></span>
                            </div>

                            <!-- Invoice # -->
                            <div class="z-label">Invoice#*</div>
                            <div class="z-input-row" style="align-items: center; gap: 8px;">
                                <select name="invoice_series" class="z-control" style="width: 200px; background: #f8fafc; font-size: 13px;">
                                    <option value="default">Default Transaction Series</option>
                                </select>
                                <input type="text" name="invoice_number" value="<?= e($nextInvoiceNum) ?>" class="z-control" style="width: 160px; font-weight: 600;" required>
                                <a href="<?= asset('settings.php') ?>" title="Configure Transaction Series" style="color: #64748b; font-size: 16px; text-decoration: none; padding: 4px;">⚙</a>
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
                            <div class="z-label normal" style="display: flex; align-items: center; gap: 4px;">
                                <span>Subject</span>
                                <span style="font-size: 12px; color: #94a3b8; cursor: help;" title="Let your customer know what this Invoice is for">ⓘ</span>
                            </div>
                            <div class="z-input-row" style="max-width: 650px;">
                                <textarea name="subject" rows="2" placeholder="Let your customer know what this Invoice is for" class="z-control" style="width: 100%; height: 56px; padding: 8px 10px; resize: vertical;"></textarea>
                            </div>

                            <!-- Warehouse Location & Selection Type -->
                            <div class="z-label normal">Warehouse Location</div>
                            <div class="z-input-row" style="align-items: center; gap: 32px;">
                                <select name="warehouse_location" class="z-control" style="width: 220px;">
                                    <option value="Head Office">Head Office</option>
                                </select>

                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span class="z-label normal">Selection Type</span>
                                    <select name="selection_type" class="z-control" style="width: 180px;">
                                        <option value="multiple">Multiple Location</option>
                                        <option value="single">Single Location</option>
                                    </select>
                                    <span style="font-size: 12px; color: #94a3b8; cursor: help;" title="Multiple Location allows selecting items across multiple inventory locations">ⓘ</span>
                                </div>
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
                                    <th style="width: 38%; text-align: left;">ITEM DETAILS</th>
                                    <th style="width: 12%; text-align: right;">QUANTITY</th>
                                    <th style="width: 14%; text-align: right;">RATE (₹)</th>
                                    <th style="width: 14%; text-align: right;">DISCOUNT</th>
                                    <th style="width: 10%; text-align: left;">TAX ⓘ</th>
                                    <th style="width: 12%; text-align: right;">AMOUNT (₹)</th>
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

                                <!-- Round Off Row -->
                                <div class="z-calc-row">
                                    <span style="color: #475569;">Round Off</span>
                                    <span id="roundOffDisplay" style="color: #475569;">0.00</span>
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
                                <span>Select Payment Option(s) for this Invoice:</span>
                                <span style="display: inline-flex; align-items: center; gap: 6px; margin-left: 8px;">
                                    <svg width="32" height="18" viewBox="0 0 36 20" fill="none"><rect width="36" height="20" rx="3" fill="#1434CB"/><path d="M14.5 14L16.2 6.5H18.3L16.6 14H14.5ZM23.4 6.7C23.0 6.5 22.4 6.4 21.6 6.4C19.7 6.4 18.3 7.4 18.3 8.8C18.3 9.9 19.3 10.5 20.1 10.9C20.9 11.3 21.2 11.6 21.2 12.0C21.2 12.6 20.5 12.9 19.8 12.9C19.0 12.9 18.5 12.7 17.9 12.4L17.5 12.2L17.2 13.9C17.7 14.1 18.6 14.3 19.6 14.3C21.7 14.3 23.1 13.3 23.1 11.7C23.1 10.8 22.5 10.1 21.2 9.5C20.4 9.1 19.9 8.8 19.9 8.3C19.9 7.9 20.4 7.6 21.3 7.6C22.0 7.6 22.6 7.7 23.0 7.9L23.4 6.7ZM27.8 6.5H26.2C25.7 6.5 25.3 6.7 25.1 7.1L22.0 14H24.2L24.6 12.8H27.3L27.6 14H29.5L27.8 6.5ZM25.2 11.3L26.1 8.8L26.6 11.3H25.2ZM12.7 6.5L10.7 11.6L10.5 10.4C10.1 9.1 8.9 7.7 7.5 7.0L9.3 14H11.5L14.8 6.5H12.7Z" fill="white"/></svg>
                                    <svg width="26" height="18" viewBox="0 0 28 18" fill="none"><rect width="28" height="18" rx="3" fill="#222"/><circle cx="10" cy="9" r="6" fill="#EB001B"/><circle cx="18" cy="9" r="6" fill="#F79E1B" fill-opacity="0.8"/></svg>
                                </span>
                                <a href="<?= asset('settings.php') ?>" style="margin-left: auto; color: #2563eb; text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                    <span>⚙ Payment Gateway</span>
                                </a>
                            </div>
                            <div style="margin-top: 14px; display: flex; align-items: center; gap: 24px; flex-wrap: wrap; background: #f8fafc; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: #0f172a; cursor: pointer;">
                                    <input type="radio" name="payment_method" value="cod" checked style="accent-color: #2563eb;">
                                    <span style="font-weight: 600;">📦 Cash on Delivery (COD)</span>
                                </label>
                                <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: #0f172a; cursor: pointer;">
                                    <input type="radio" name="payment_method" value="razorpay" style="accent-color: #2563eb;">
                                    <span style="font-weight: 600;">💳 Razorpay (Online)</span>
                                </label>
                                <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: #0f172a; cursor: pointer;">
                                    <input type="radio" name="payment_method" value="cash" style="accent-color: #2563eb;">
                                    <span style="font-weight: 600;">💵 Cash</span>
                                </label>
                                <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: #0f172a; cursor: pointer;">
                                    <input type="radio" name="payment_method" value="upi" style="accent-color: #2563eb;">
                                    <span style="font-weight: 600;">📱 UPI / QR</span>
                                </label>
                                <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: #0f172a; cursor: pointer;">
                                    <input type="radio" name="payment_method" value="bank_transfer" style="accent-color: #2563eb;">
                                    <span style="font-weight: 600;">🏦 Bank Transfer</span>
                                </label>
                            </div>
                            <div style="font-size: 12px; color: #94a3b8; margin-top: 14px;">
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

    <!-- Zoho Exact Parity "New Customer" Modal -->
    <div class="modal-overlay" id="customerModal">
        <div class="z-cust-modal-box">
            <!-- Header -->
            <div class="z-cust-modal-header">
                <div class="z-cust-modal-title">New Customer</div>
                <button type="button" class="modal-close-btn" onclick="closeCustomerModal()" style="font-size: 24px; color: #ef4444; background: transparent; border: 0; cursor: pointer;">&times;</button>
            </div>

            <!-- Body -->
            <div class="z-cust-modal-body">
                <div class="z-cust-grid">
                    <!-- Customer Type -->
                    <div class="z-label normal" style="display: flex; align-items: center; gap: 4px;">
                        <span>Customer Type</span>
                        <span style="color: #94a3b8; font-size: 12px; cursor: help;" title="Select whether this is a business or an individual customer">ⓘ</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; color: #334155; cursor: pointer;">
                            <input type="radio" name="cust_type" id="custTypeBusiness" value="Business" onchange="onCustTypeChange()">
                            <span>Business</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; color: #334155; cursor: pointer;">
                            <input type="radio" name="cust_type" id="custTypeIndividual" value="Individual" checked onchange="onCustTypeChange()">
                            <span>Individual</span>
                        </label>
                    </div>

                    <!-- Primary Contact -->
                    <div class="z-label normal" style="display: flex; align-items: center; gap: 4px;">
                        <span>Primary Contact</span>
                        <span style="color: #94a3b8; font-size: 12px; cursor: help;" title="Primary contact person name">ⓘ</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <select id="newCustSalutation" class="z-control" style="width: 120px;">
                            <option value="">Salutation</option>
                            <option value="Mr.">Mr.</option>
                            <option value="Mrs.">Mrs.</option>
                            <option value="Ms.">Ms.</option>
                            <option value="Miss">Miss</option>
                            <option value="Dr.">Dr.</option>
                        </select>
                        <input type="text" id="newCustFirstName" placeholder="First Name" class="z-control" style="flex: 1;" oninput="autoSyncDisplayName()">
                        <input type="text" id="newCustLastName" placeholder="Last Name" class="z-control" style="flex: 1;" oninput="autoSyncDisplayName()">
                    </div>

                    <!-- Company Name -->
                    <div class="z-label normal">Company Name</div>
                    <div>
                        <input type="text" id="newCustCompany" placeholder="Company / Business Name" class="z-control" style="width: 100%; max-width: 480px;" oninput="autoSyncDisplayName()">
                    </div>

                    <!-- Display Name -->
                    <div class="z-label" style="display: flex; align-items: center; gap: 4px;">
                        <span>Display Name*</span>
                        <span style="color: #94a3b8; font-size: 12px; cursor: help;" title="Name displayed on transactions and invoices">ⓘ</span>
                    </div>
                    <div>
                        <input type="text" id="newCustDisplayName" placeholder="Select or type to add" class="z-control" style="width: 100%; max-width: 480px; font-weight: 600;" required>
                    </div>

                    <!-- Email Address -->
                    <div class="z-label normal" style="display: flex; align-items: center; gap: 4px;">
                        <span>Email Address</span>
                        <span style="color: #94a3b8; font-size: 12px; cursor: help;" title="Primary email address">ⓘ</span>
                    </div>
                    <div style="position: relative; max-width: 480px; width: 100%;">
                        <input type="email" id="newCustEmail" placeholder="customer@example.com" class="z-control" style="width: 100%; padding-left: 36px;">
                        <span style="position: absolute; left: 10px; top: 8px; color: #94a3b8; font-size: 14px;">✉</span>
                    </div>

                    <!-- Phone -->
                    <div class="z-label normal" style="display: flex; align-items: center; gap: 4px;">
                        <span>Phone</span>
                        <span style="color: #94a3b8; font-size: 12px; cursor: help;" title="Work phone and mobile number">ⓘ</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; border: 1px solid #cbd5e1; border-radius: 4px; overflow: hidden; background: #fff;">
                            <span style="padding: 0 8px; font-size: 12.5px; color: #64748b; background: #f8fafc; border-right: 1px solid #cbd5e1; line-height: 34px;">+91 ▾</span>
                            <input type="tel" id="newCustWorkPhone" placeholder="Work Phone" style="border: 0; outline: none; padding: 0 10px; height: 34px; font-size: 13px; width: 150px;">
                        </div>
                        <div style="display: flex; align-items: center; border: 1px solid #cbd5e1; border-radius: 4px; overflow: hidden; background: #fff;">
                            <span style="padding: 0 8px; font-size: 12.5px; color: #64748b; background: #f8fafc; border-right: 1px solid #cbd5e1; line-height: 34px;">+91 ▾</span>
                            <input type="tel" id="newCustPhone" placeholder="Mobile" style="border: 0; outline: none; padding: 0 10px; height: 34px; font-size: 13px; width: 150px;">
                        </div>
                    </div>
                </div>

                <!-- Tabs Header -->
                <div class="z-cust-tabs-bar">
                    <button type="button" class="z-cust-tab-btn active" id="tabBtnOther" onclick="switchCustTab('other')">Other Details</button>
                    <button type="button" class="z-cust-tab-btn" id="tabBtnAddress" onclick="switchCustTab('address')">Address</button>
                    <button type="button" class="z-cust-tab-btn" id="tabBtnCustom" onclick="switchCustTab('custom')">Custom Fields</button>
                    <button type="button" class="z-cust-tab-btn" id="tabBtnRemarks" onclick="switchCustTab('remarks')">Remarks</button>
                </div>

                <!-- Tab Pane: Other Details (Screenshot 2 Parity) -->
                <div class="z-cust-tab-pane active" id="tabPaneOther">
                    <div class="z-cust-grid">
                        <!-- GST Treatment -->
                        <div class="z-label">GST Treatment*</div>
                        <div>
                            <select id="newCustGstTreatment" class="z-control" style="width: 100%; max-width: 480px;">
                                <option value="">Select a GST treatment</option>
                                <option value="Registered Business - Regular">Registered Business - Regular</option>
                                <option value="Registered Business - Composition">Registered Business - Composition</option>
                                <option value="Unregistered Business">Unregistered Business</option>
                                <option value="Consumer" selected>Consumer</option>
                                <option value="Overseas">Overseas</option>
                                <option value="Special Economic Zone (SEZ)">Special Economic Zone (SEZ)</option>
                                <option value="Deemed Export">Deemed Export</option>
                            </select>
                        </div>

                        <!-- Place of Supply -->
                        <div class="z-label">Place of Supply*</div>
                        <div>
                            <select id="newCustPlaceOfSupply" class="z-control" style="width: 100%; max-width: 480px;">
                                <option value="[WB]- West Bengal" selected>[WB]- West Bengal</option>
                                <option value="[MH]- Maharashtra">[MH]- Maharashtra</option>
                                <option value="[DL]- Delhi">[DL]- Delhi</option>
                                <option value="[KA]- Karnataka">[KA]- Karnataka</option>
                                <option value="[UP]- Uttar Pradesh">[UP]- Uttar Pradesh</option>
                                <option value="[GJ]- Gujarat">[GJ]- Gujarat</option>
                                <option value="[TN]- Tamil Nadu">[TN]- Tamil Nadu</option>
                                <option value="[RJ]- Rajasthan">[RJ]- Rajasthan</option>
                                <option value="[BR]- Bihar">[BR]- Bihar</option>
                                <option value="[MP]- Madhya Pradesh">[MP]- Madhya Pradesh</option>
                                <option value="[AP]- Andhra Pradesh">[AP]- Andhra Pradesh</option>
                                <option value="[TS]- Telangana">[TS]- Telangana</option>
                                <option value="[KL]- Kerala">[KL]- Kerala</option>
                                <option value="[PB]- Punjab">[PB]- Punjab</option>
                                <option value="[HR]- Haryana">[HR]- Haryana</option>
                                <option value="[OR]- Odisha">[OR]- Odisha</option>
                                <option value="[AS]- Assam">[AS]- Assam</option>
                                <option value="[JH]- Jharkhand">[JH]- Jharkhand</option>
                                <option value="[UK]- Uttarakhand">[UK]- Uttarakhand</option>
                                <option value="[HP]- Himachal Pradesh">[HP]- Himachal Pradesh</option>
                                <option value="[JK]- Jammu and Kashmir">[JK]- Jammu and Kashmir</option>
                                <option value="[GA]- Goa">[GA]- Goa</option>
                                <option value="[CH]- Chandigarh">[CH]- Chandigarh</option>
                                <option value="[PY]- Puducherry">[PY]- Puducherry</option>
                            </select>
                        </div>

                        <!-- PAN -->
                        <div class="z-label normal" style="display: flex; align-items: center; gap: 4px;">
                            <span>PAN</span>
                            <span style="color: #94a3b8; font-size: 12px; cursor: help;" title="Permanent Account Number (10 digit PAN)">ⓘ</span>
                        </div>
                        <div>
                            <input type="text" id="newCustPan" placeholder="e.g. ABCDE1234F" class="z-control" style="width: 100%; max-width: 480px; text-transform: uppercase;" maxlength="10">
                        </div>

                        <!-- Tax Preference -->
                        <div class="z-label">Tax Preference*</div>
                        <div style="display: flex; align-items: center; gap: 20px;">
                            <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; color: #334155; cursor: pointer;">
                                <input type="radio" name="tax_preference" id="taxPrefTaxable" value="Taxable" checked>
                                <span>Taxable</span>
                            </label>
                            <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; color: #334155; cursor: pointer;">
                                <input type="radio" name="tax_preference" id="taxPrefExempt" value="Tax Exempt">
                                <span>Tax Exempt</span>
                            </label>
                        </div>

                        <!-- Currency -->
                        <div class="z-label normal">Currency</div>
                        <div>
                            <select id="newCustCurrency" class="z-control" style="width: 100%; max-width: 480px;">
                                <option value="INR" selected>INR- Indian Rupee</option>
                                <option value="USD">USD- US Dollar</option>
                                <option value="EUR">EUR- Euro</option>
                                <option value="GBP">GBP- British Pound</option>
                                <option value="AED">AED- UAE Dirham</option>
                            </select>
                        </div>

                        <!-- Payment Terms -->
                        <div class="z-label normal">Payment Terms</div>
                        <div>
                            <select id="newCustPaymentTerms" class="z-control" style="width: 100%; max-width: 480px;">
                                <option value="Due on Receipt" selected>Due on Receipt</option>
                                <option value="Net 15">Net 15</option>
                                <option value="Net 30">Net 30</option>
                                <option value="Net 45">Net 45</option>
                                <option value="Net 60">Net 60</option>
                            </select>
                        </div>

                        <!-- Documents -->
                        <div class="z-label normal">Documents</div>
                        <div>
                            <button type="button" class="z-secondary-btn" onclick="document.getElementById('custDocInput').click()">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                <span>Upload File ▾</span>
                            </button>
                            <input type="file" id="custDocInput" style="display: none;" multiple>
                            <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">You can upload a maximum of 10 files, 10MB each</div>
                        </div>

                        <!-- Add more details -->
                        <div></div>
                        <div>
                            <a href="javascript:void(0)" onclick="switchCustTab('address')" style="font-size: 13px; color: #2563eb; text-decoration: none; font-weight: 600;">Add more details</a>
                        </div>
                    </div>
                </div>

                <!-- Tab Pane: Address -->
                <div class="z-cust-tab-pane" id="tabPaneAddress">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 32px;">
                        <div>
                            <h4 style="font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 12px;">Billing Address</h4>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <input type="text" id="newCustAddress" placeholder="Street Address / Building" class="z-control" style="width: 100%;">
                                <input type="text" id="newCustCity" placeholder="City" class="z-control" style="width: 100%;">
                                <div style="display: flex; gap: 10px;">
                                    <input type="text" id="newCustState" placeholder="State" class="z-control" style="flex: 1;" value="West Bengal">
                                    <input type="text" id="newCustZip" placeholder="PIN Code" class="z-control" style="width: 110px;">
                                </div>
                            </div>
                        </div>
                        <div>
                            <h4 style="font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 12px;">Shipping Address</h4>
                            <div style="font-size: 12.5px; color: #64748b; margin-bottom: 8px;">
                                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                                    <input type="checkbox" id="copyBillingAddrChk" checked>
                                    <span>Same as Billing Address</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Pane: Custom Fields -->
                <div class="z-cust-tab-pane" id="tabPaneCustom">
                    <div style="padding: 16px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; font-size: 13px; color: #64748b; text-align: center;">
                        Add custom fields for your customers by navigating to <strong>Settings ➔ Preferences ➔ Customers</strong>.
                    </div>
                </div>

                <!-- Tab Pane: Remarks -->
                <div class="z-cust-tab-pane" id="tabPaneRemarks">
                    <div>
                        <label class="z-label normal" style="display: block; margin-bottom: 6px;">Remarks (For internal use)</label>
                        <textarea id="newCustRemarks" rows="4" placeholder="Enter remarks about this customer" class="z-textarea"></textarea>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="z-cust-modal-footer">
                <button type="button" id="saveCustBtn" class="btn-primary" style="background: #2563eb; color: #ffffff; border: 0; padding: 8px 24px; border-radius: 4px; font-size: 13.5px; font-weight: 600; cursor: pointer;" onclick="saveQuickCustomer()">Save</button>
                <button type="button" class="z-footer-btn-white" onclick="closeCustomerModal()">Cancel</button>
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
                        <span style="color: #94a3b8; font-size: 16px; cursor: grab;" title="Reorder row">⋮⋮</span>
                        <span style="color: #94a3b8; font-size: 15px; cursor: pointer; padding: 2px;" title="Item Image">📷</span>
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
                    <select class="z-control" id="tax_${rowId}" style="width: 100%; height: 34px; font-size: 12px; padding: 0 4px;" onchange="calculateTotals()">
                        <option value="18">GST 18%</option>
                        <option value="12">GST 12%</option>
                        <option value="5">GST 5%</option>
                        <option value="0">GST 0%</option>
                        <option value="28">GST 28%</option>
                    </select>
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
            var taxSel = document.getElementById('tax_' + rowId);
            if (!sel || !rateInp) return;

            var pid = parseInt(sel.value, 10);
            var prod = availableProducts.find(p => p.id === pid);
            if (prod) {
                rateInp.value = prod.price.toFixed(2);
                if (taxSel && prod.tax !== undefined) {
                    taxSel.value = String(Math.round(prod.tax));
                }
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
                var taxSel = document.getElementById('tax_' + rowId);
                var amtDisplay = document.getElementById('amt_' + rowId);

                var pid = parseInt(sel ? sel.value : 0, 10);
                var qty = Math.max(1, parseFloat(qtyInp ? qtyInp.value : 1) || 1);
                var rate = Math.max(0, parseFloat(rateInp ? rateInp.value : 0) || 0);
                var discVal = Math.max(0, parseFloat(discInp ? discInp.value : 0) || 0);
                var discType = discTypeSel ? discTypeSel.value : 'percent';
                var taxPercent = taxSel ? (parseFloat(taxSel.value) || 0.0) : 18.0;

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

            // Subtotal + Taxes + Adjustment
            var taxDelta = isTds ? -taxAmount : taxAmount;
            var rawTotal = Math.max(0, subTotal + taxDelta + adjVal);
            var grandTotal = Math.round(rawTotal * 100) / 100;
            var roundOff = 0.00;

            var roundOffDisplay = document.getElementById('roundOffDisplay');
            if (roundOffDisplay) {
                roundOffDisplay.textContent = roundOff.toFixed(2);
            }

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
        var previousCustomerVal = '';
        function handleCustomerSelect(val) {
            if (val === '__add_new__') {
                document.getElementById('customerSelect').value = previousCustomerVal;
                openNewCustomerModal();
            } else {
                previousCustomerVal = val;
            }
        }

        function openNewCustomerModal() {
            document.getElementById('customerModal').classList.add('open');
            switchCustTab('other');
            setTimeout(() => {
                var inp = document.getElementById('newCustFirstName');
                if (inp) inp.focus();
            }, 50);
        }

        function closeCustomerModal() {
            document.getElementById('customerModal').classList.remove('open');
        }

        function switchCustTab(tabName) {
            document.querySelectorAll('.z-cust-tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.z-cust-tab-pane').forEach(pane => pane.classList.remove('active'));

            if (tabName === 'other') {
                var b = document.getElementById('tabBtnOther'); if (b) b.classList.add('active');
                var p = document.getElementById('tabPaneOther'); if (p) p.classList.add('active');
            } else if (tabName === 'address') {
                var b = document.getElementById('tabBtnAddress'); if (b) b.classList.add('active');
                var p = document.getElementById('tabPaneAddress'); if (p) p.classList.add('active');
            } else if (tabName === 'custom') {
                var b = document.getElementById('tabBtnCustom'); if (b) b.classList.add('active');
                var p = document.getElementById('tabPaneCustom'); if (p) p.classList.add('active');
            } else if (tabName === 'remarks') {
                var b = document.getElementById('tabBtnRemarks'); if (b) b.classList.add('active');
                var p = document.getElementById('tabPaneRemarks'); if (p) p.classList.add('active');
            }
        }

        function onCustTypeChange() {
            var isBiz = document.getElementById('custTypeBusiness').checked;
            var compInp = document.getElementById('newCustCompany');
            if (compInp) {
                compInp.placeholder = isBiz ? 'Company / Business Name *' : 'Company Name';
            }
            autoSyncDisplayName();
        }

        function autoSyncDisplayName() {
            var isBiz = document.getElementById('custTypeBusiness').checked;
            var sal = document.getElementById('newCustSalutation').value.trim();
            var first = document.getElementById('newCustFirstName').value.trim();
            var last = document.getElementById('newCustLastName').value.trim();
            var comp = document.getElementById('newCustCompany').value.trim();
            var dispInp = document.getElementById('newCustDisplayName');

            if (isBiz && comp) {
                dispInp.value = comp;
            } else if (first || last) {
                var fullName = (sal ? sal + ' ' : '') + first + (last ? ' ' + last : '');
                dispInp.value = fullName.trim();
            } else if (comp) {
                dispInp.value = comp;
            }
        }

        function saveQuickCustomer() {
            var custType = document.querySelector('input[name="cust_type"]:checked') ? document.querySelector('input[name="cust_type"]:checked').value : 'Individual';
            var salutation = document.getElementById('newCustSalutation').value.trim();
            var firstName = document.getElementById('newCustFirstName').value.trim();
            var lastName = document.getElementById('newCustLastName').value.trim();
            var company = document.getElementById('newCustCompany').value.trim();
            var displayName = document.getElementById('newCustDisplayName').value.trim();
            var email = document.getElementById('newCustEmail').value.trim();
            var workPhone = document.getElementById('newCustWorkPhone').value.trim();
            var mobile = document.getElementById('newCustPhone').value.trim();

            var gstTreatment = document.getElementById('newCustGstTreatment').value;
            var placeOfSupply = document.getElementById('newCustPlaceOfSupply').value;
            var pan = document.getElementById('newCustPan').value.trim();
            var taxPref = document.querySelector('input[name="tax_preference"]:checked') ? document.querySelector('input[name="tax_preference"]:checked').value : 'Taxable';
            var currency = document.getElementById('newCustCurrency').value;
            var paymentTerms = document.getElementById('newCustPaymentTerms').value;

            var street = document.getElementById('newCustAddress').value.trim();
            var city = document.getElementById('newCustCity').value.trim();
            var state = document.getElementById('newCustState').value.trim();
            var zip = document.getElementById('newCustZip').value.trim();
            var fullAddr = [street, city, state, zip].filter(Boolean).join(', ');

            var remarks = document.getElementById('newCustRemarks').value.trim();

            if (!displayName) {
                alert('Display Name is required.');
                document.getElementById('newCustDisplayName').focus();
                return;
            }

            var saveBtn = document.getElementById('saveCustBtn');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';
            }

            var formData = new FormData();
            formData.append('csrf_token', '<?= csrf_token() ?>');
            formData.append('action', 'save_customer');
            formData.append('is_ajax', '1');
            formData.append('customer_type', custType);
            formData.append('salutation', salutation);
            formData.append('first_name', firstName);
            formData.append('last_name', lastName);
            formData.append('company_name', company);
            formData.append('display_name', displayName);
            formData.append('email', email);
            formData.append('work_phone', workPhone);
            formData.append('phone', mobile);
            formData.append('gst_treatment', gstTreatment);
            formData.append('place_of_supply', placeOfSupply);
            formData.append('pan', pan);
            formData.append('tax_preference', taxPref);
            formData.append('currency', currency);
            formData.append('payment_terms', paymentTerms);
            formData.append('address', fullAddr);
            formData.append('remarks', remarks);

            fetch('<?= asset('customers.php') ?>', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save';
                }
                if (res && res.success && res.id) {
                    var sel = document.getElementById('customerSelect');
                    var opt = document.createElement('option');
                    opt.value = res.id;
                    opt.textContent = res.name + (res.phone ? ' (' + res.phone + ')' : '');
                    opt.selected = true;
                    sel.appendChild(opt);
                    sel.value = res.id;
                    previousCustomerVal = res.id;

                    if (res.payment_terms) {
                        var termsSel = document.getElementById('termsSelect');
                        if (termsSel) {
                            termsSel.value = res.payment_terms;
                            recalcDueDate();
                        }
                    }

                    closeCustomerModal();
                } else {
                    alert((res && res.error) ? res.error : 'Could not save customer. Please check input.');
                }
            })
            .catch(function(err) {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save';
                }
                alert('Network error while saving customer.');
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

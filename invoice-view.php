<?php
/**
 * OminiFlow POS - Enterprise Zoho-Parity Tax Invoice Viewer & Print Engine
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

$invoiceId = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
$orderId = !empty($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

$invoice = null;
if ($invoiceId > 0) {
    $invoice = get_invoice_by_id($invoiceId);
} elseif ($orderId > 0) {
    $invoice = get_invoice_by_order_id($orderId);
}

if (!$invoice) {
    set_flash('error', 'Invoice not found.');
    redirect(APP_URL . '/invoices.php');
}

$store = $invoice['store'] ?? get_store_settings();
$items = $invoice['items'] ?? [];
$isCancelled = ($invoice['invoice_status'] === 'cancelled');
$autoPrint = isset($_GET['print']);
$pageTitle = 'Tax Invoice #' . $invoice['invoice_number'];

// Helper: Amount in Indian Words
function amount_in_words(float $num): string {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    
    $num = round($num, 2);
    $rupees = (int)floor($num);
    $paise = (int)round(($num - $rupees) * 100);

    if ($rupees === 0 && $paise === 0) return 'Rupees Zero Only';

    $fnConvertGroup = function(int $n) use ($ones, $tens): string {
        $res = '';
        if ($n >= 100) {
            $res .= $ones[(int)floor($n / 100)] . ' Hundred ';
            $n %= 100;
        }
        if ($n >= 20) {
            $res .= $tens[(int)floor($n / 10)] . ' ';
            $n %= 10;
        }
        if ($n > 0) {
            $res .= $ones[$n] . ' ';
        }
        return trim($res);
    };

    $parts = [];
    $crore = (int)floor($rupees / 10000000);
    $rupees %= 10000000;
    if ($crore > 0) $parts[] = $fnConvertGroup($crore) . ' Crore';

    $lakh = (int)floor($rupees / 100000);
    $rupees %= 100000;
    if ($lakh > 0) $parts[] = $fnConvertGroup($lakh) . ' Lakh';

    $thousand = (int)floor($rupees / 1000);
    $rupees %= 1000;
    if ($thousand > 0) $parts[] = $fnConvertGroup($thousand) . ' Thousand';

    if ($rupees > 0) {
        $parts[] = $fnConvertGroup($rupees);
    }

    $out = 'Rupees ' . implode(' ', $parts);
    if ($paise > 0) {
        $out .= ' and ' . $fnConvertGroup($paise) . ' Paise';
    }
    return trim($out) . ' Only';
}

$amountInWords = amount_in_words((float)$invoice['total_amount']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        /* Standalone Zoho-Style Tax Invoice Sheet CSS */
        .zoho-invoice-wrapper {
            max-width: 900px;
            margin: 0 auto;
            padding-bottom: 40px;
        }
        .zoho-actions-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            padding: 16px 24px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(15,23,42,0.06);
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .zoho-invoice-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 44px 48px;
            box-shadow: 0 4px 20px -2px rgba(15,23,42,0.08);
            color: #0f172a;
            position: relative;
            font-family: var(--saas-font);
        }
        .zoho-watermark {
            position: absolute;
            top: 40%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 76px;
            font-weight: 900;
            color: rgba(239, 68, 68, 0.12);
            letter-spacing: 0.25em;
            pointer-events: none;
            z-index: 10;
        }
        /* Top Header Split */
        .zoho-header-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 24px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 28px;
            margin-bottom: 28px;
        }
        .zoho-brand-area {
            display: flex;
            gap: 16px;
            align-items: flex-start;
        }
        .zoho-brand-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #4f46e5 0%, #312e81 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 26px;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(79,70,229,0.25);
        }
        .zoho-store-name {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }
        .zoho-store-meta {
            font-size: 12.5px;
            color: #64748b;
            line-height: 1.6;
            margin-top: 6px;
        }
        .zoho-meta-right {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        .zoho-doc-title {
            font-size: 28px;
            font-weight: 900;
            color: #4f46e5;
            letter-spacing: 0.05em;
            line-height: 1;
        }
        .zoho-inv-number {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            margin-top: 6px;
            font-family: ui-monospace, monospace;
        }
        .zoho-badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 8px;
        }
        .zoho-badge-paid {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .zoho-badge-cancelled {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        /* 2-Column Info Cards */
        .zoho-details-box {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 28px;
        }
        .zoho-box-col {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .zoho-box-heading {
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            margin-bottom: 6px;
        }
        .zoho-party-name {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }
        .zoho-party-text {
            font-size: 13px;
            color: #475569;
            line-height: 1.5;
        }

        /* Zoho Items Table */
        .zoho-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .zoho-table th {
            background: #0f172a;
            color: #ffffff;
            padding: 12px 14px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .zoho-table td {
            padding: 14px 14px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
            color: #1e293b;
            vertical-align: middle;
        }
        .zoho-table tbody tr:nth-child(even) td {
            background: #fbfcfe;
        }

        /* Summary & Tax Split Grid */
        .zoho-summary-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 24px;
            align-items: flex-start;
            margin-bottom: 28px;
        }
        .zoho-tax-summary-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 18px 20px;
        }
        .zoho-calc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .zoho-calc-table td {
            padding: 7px 0;
            color: #475569;
        }
        .zoho-calc-table td.amount-col {
            text-align: right;
            font-weight: 600;
            color: #0f172a;
        }
        .zoho-grand-total-row td {
            font-size: 17px;
            font-weight: 800;
            color: #4f46e5;
            border-top: 2px solid #0f172a;
            border-bottom: 2px solid #0f172a;
            padding: 12px 0;
        }

        /* Footer & Authorized Signatory */
        .zoho-invoice-footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            font-size: 12.5px;
            color: #64748b;
            line-height: 1.6;
        }
        .zoho-sign-block {
            text-align: center;
            width: 200px;
            padding-top: 10px;
            border-top: 1px solid #cbd5e1;
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }

        /* Print Media Styling */
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm;
            }
            .app-sidebar, .app-header, .zoho-actions-header, .spotlight-overlay, .no-print, button, .btn-secondary, .header-btn {
                display: none !important;
                visibility: hidden !important;
            }
            html, body {
                background: #ffffff !important;
                background-color: #ffffff !important;
                color: #000000 !important;
                display: block !important;
                width: 100% !important;
                min-width: 100% !important;
                height: auto !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .app-layout, .app-main, .dashboard-content {
                display: block !important;
                position: static !important;
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                min-height: auto !important;
                height: auto !important;
                overflow: visible !important;
                background: transparent !important;
            }
            .zoho-invoice-wrapper {
                display: block !important;
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .zoho-invoice-card {
                display: block !important;
                border: 0 !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                background: #ffffff !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .zoho-table th {
                background: #0f172a !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .zoho-details-box, .zoho-tax-summary-box {
                background: #f8fafc !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
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
                <div class="zoho-invoice-wrapper">
                    <!-- Top Action Bar -->
                    <div class="zoho-actions-header no-print">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <a href="<?= asset('invoices.php') ?>" class="btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                                <span>All Invoices</span>
                            </a>
                            <span class="zoho-badge-status <?= $isCancelled ? 'zoho-badge-cancelled' : 'zoho-badge-paid' ?>">
                                <?= $isCancelled ? 'CANCELLED' : 'PAID INVOICE' ?>
                            </span>
                        </div>

                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <!-- Print A4 Button -->
                            <button type="button" class="header-btn" onclick="window.print();" style="display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px;">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                <span>Print / Save PDF (A4)</span>
                            </button>

                            <!-- View Order Link -->
                            <a href="<?= asset('orders.php?search=' . urlencode($invoice['order_number'])) ?>" class="btn-secondary">
                                View Order
                            </a>

                            <!-- Cancel Button -->
                            <?php if (!$isCancelled): ?>
                                <button type="button" class="btn-secondary" id="openCancelInvBtn" style="color: #b91c1c; border-color: #fecaca;">
                                    Cancel Invoice
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Zoho POS Official Tax Invoice Document -->
                    <div class="zoho-invoice-card <?= $isCancelled ? 'zoho-watermark-wrap' : '' ?>">
                        <?php if ($isCancelled): ?>
                            <div class="zoho-watermark">CANCELLED</div>
                        <?php endif; ?>

                        <!-- Header Grid -->
                        <div class="zoho-header-grid">
                            <div class="zoho-brand-area">
                                <div class="zoho-brand-icon">⚡</div>
                                <div>
                                    <h1 class="zoho-store-name"><?= e($store['store_name']) ?></h1>
                                    <?php if (!empty($store['tagline'])): ?>
                                        <div style="font-size: 13px; font-weight: 600; color: var(--saas-primary); margin-top: 2px;"><?= e($store['tagline']) ?></div>
                                    <?php endif; ?>
                                    <div class="zoho-store-meta">
                                        <?= e($store['address'] ?? '') ?><br>
                                        Phone: <?= e($store['phone'] ?? 'N/A') ?> &bull; Email: <?= e($store['email'] ?? 'N/A') ?><br>
                                        <strong>GSTIN: <?= e($store['gstin'] ?? '29ABCDE1234F1Z5') ?></strong>
                                    </div>
                                </div>
                            </div>

                            <div class="zoho-meta-right">
                                <div class="zoho-doc-title">TAX INVOICE</div>
                                <div class="zoho-inv-number">#<?= e($invoice['invoice_number']) ?></div>
                                <span class="zoho-badge-status <?= $isCancelled ? 'zoho-badge-cancelled' : 'zoho-badge-paid' ?>">
                                    <?= $isCancelled ? 'CANCELLED' : 'PAID' ?>
                                </span>
                                <div style="font-size: 12.5px; color: #475569; margin-top: 10px; line-height: 1.5;">
                                    Invoice Date: <strong><?= date('d M Y, h:i A', strtotime($invoice['invoice_date'])) ?></strong><br>
                                    Order Ref: <strong><?= e($invoice['order_number']) ?></strong><br>
                                    Place of Supply: <strong>Karnataka (29)</strong>
                                </div>
                            </div>
                        </div>

                        <!-- Customer & Dispatch Info Boxes -->
                        <div class="zoho-details-box">
                            <div class="zoho-box-col">
                                <div class="zoho-box-heading">Billed To (Customer):</div>
                                <div class="zoho-party-name"><?= e($invoice['customer_name'] ?: 'Walk-in Customer') ?></div>
                                <div class="zoho-party-text">
                                    <?php if (!empty($invoice['customer_phone'])): ?>
                                        Phone: <?= e($invoice['customer_phone']) ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['customer_email'])): ?>
                                        Email: <?= e($invoice['customer_email']) ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['customer_address'])): ?>
                                        Address: <?= e($invoice['customer_address']) ?><br>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="zoho-box-col">
                                <div class="zoho-box-heading">Payment & Counter Settlement:</div>
                                <div class="zoho-party-text">
                                    Payment Method: <strong style="text-transform: uppercase; color: #0f172a;"><?= e($invoice['payment_method']) ?></strong><br>
                                    Payment Status: <strong style="color: #047857; text-transform: uppercase;"><?= e($invoice['payment_status']) ?></strong><br>
                                    POS Terminal: <strong>Register Counter #1</strong><br>
                                    Billed By: <strong><?= e($invoice['cashier_name'] ?? 'Counter Cashier') ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Itemized Tax Invoice Table -->
                        <table class="zoho-table">
                            <thead>
                                <tr>
                                    <th style="width: 5%; text-align: center;">#</th>
                                    <th style="width: 38%; text-align: left;">Item & Description</th>
                                    <th style="width: 14%; text-align: right;">Unit Price (₹)</th>
                                    <th style="width: 8%; text-align: center;">Qty</th>
                                    <th style="width: 10%; text-align: right;">GST Rate</th>
                                    <th style="width: 12%; text-align: right;">Tax Amt (₹)</th>
                                    <th style="width: 13%; text-align: right;">Total Amount (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sl = 1; foreach ($items as $it): ?>
                                    <?php
                                        $uPrice = (float) $it['unit_price'];
                                        $qty = (int) $it['quantity'];
                                        $taxRate = (float) $it['tax_percent'];
                                        $taxVal = (float) $it['tax_amount'];
                                        $lineTot = (float) $it['line_total'];
                                    ?>
                                    <tr>
                                        <td style="text-align: center; color: #64748b; font-weight: 600;"><?= $sl++ ?></td>
                                        <td>
                                            <div style="font-weight: 700; color: #0f172a; font-size: 13.5px;"><?= e($it['product_name']) ?></div>
                                            <div style="font-size: 11px; color: #64748b; font-family: monospace;">SKU: <?= e($it['product_sku']) ?></div>
                                        </td>
                                        <td style="text-align: right; font-weight: 600;">₹<?= number_format($uPrice, 2) ?></td>
                                        <td style="text-align: center; font-weight: 700; color: #0f172a;"><?= $qty ?></td>
                                        <td style="text-align: right; color: #475569;"><?= $taxRate ?>%</td>
                                        <td style="text-align: right; color: #475569;">₹<?= number_format($taxVal, 2) ?></td>
                                        <td style="text-align: right; font-weight: 800; color: #0f172a;">₹<?= number_format($lineTot, 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Summary & Tax Split Grid -->
                        <div class="zoho-summary-grid">
                            <!-- Left: GST Split & Amount in Words -->
                            <div class="zoho-tax-summary-box">
                                <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 8px; letter-spacing: 0.05em;">
                                    GST Tax Settlement:
                                </div>
                                <div style="font-size: 12.5px; color: #334155; line-height: 1.6;">
                                    CGST (Central Tax @ 50%): <strong>₹<?= number_format((float)$invoice['cgst_amount'], 2) ?></strong><br>
                                    SGST (State Tax @ 50%): <strong>₹<?= number_format((float)$invoice['sgst_amount'], 2) ?></strong><br>
                                    <?php if ($invoice['payment_method'] === 'cash'): ?>
                                        Cash Tendered: <strong>₹<?= number_format((float)$invoice['amount_paid'], 2) ?></strong> &bull; Change Returned: <strong>₹<?= number_format((float)$invoice['change_amount'], 2) ?></strong><br>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 12px; padding-top: 10px; border-top: 1px dashed #cbd5e1; font-size: 12px; color: #475569;">
                                    <strong>Total In Words:</strong><br>
                                    <em style="color: #0f172a; font-weight: 600;"><?= e($amountInWords) ?></em>
                                </div>
                            </div>

                            <!-- Right: Calculations Table -->
                            <div>
                                <table class="zoho-calc-table">
                                    <tr>
                                        <td>Subtotal:</td>
                                        <td class="amount-col">₹<?= number_format((float)$invoice['subtotal'], 2) ?></td>
                                    </tr>
                                    <?php if ((float)$invoice['discount_amount'] > 0): ?>
                                        <tr style="color: #b91c1c;">
                                            <td>Discount:</td>
                                            <td class="amount-col" style="color: #b91c1c;">− ₹<?= number_format((float)$invoice['discount_amount'], 2) ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td>Taxable Value:</td>
                                        <td class="amount-col">₹<?= number_format((float)$invoice['taxable_amount'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td>Total GST (Tax):</td>
                                        <td class="amount-col">₹<?= number_format((float)$invoice['tax_amount'], 2) ?></td>
                                    </tr>
                                    <tr class="zoho-grand-total-row">
                                        <td>GRAND TOTAL:</td>
                                        <td class="amount-col" style="color: #4f46e5;">₹<?= number_format((float)$invoice['total_amount'], 2) ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Footer & Signatures -->
                        <div class="zoho-invoice-footer">
                            <div>
                                <strong style="color: #0f172a;">Terms & Conditions:</strong><br>
                                1. Goods once sold can be exchanged within 7 days with original tax invoice.<br>
                                2. This is a system generated computer invoice and requires no physical signature.<br>
                                <em>Thank you for your business!</em>
                            </div>
                            <div class="zoho-sign-block">
                                Authorized Signatory<br>
                                <span style="font-size: 13px; font-weight: 800;"><?= e($store['store_name']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- CANCEL INVOICE MODAL -->
    <?php if (!$isCancelled): ?>
        <div class="modal-overlay" id="cancelInvoiceModal">
            <div class="modal-box" style="max-width: 460px;">
                <div class="modal-header">
                    <h3 class="modal-title" style="color: #b91c1c;">Cancel Invoice #<?= e($invoice['invoice_number']) ?></h3>
                    <button type="button" class="modal-close-btn" id="closeCancelModal">&times;</button>
                </div>
                <form method="POST" action="<?= asset('invoices.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel_invoice">
                    <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">

                    <div class="modal-body">
                        <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 12px; border-radius: var(--saas-radius-md); color: #991b1b; font-size: 13px; margin-bottom: 14px;">
                            <strong>Warning:</strong> Cancelling this invoice will mark it as cancelled, exclude it from active sales metrics, and <strong>automatically restore <?= count($items) ?> product(s) back into inventory</strong>.
                        </div>

                        <div class="form-group">
                            <label for="cancelReasonInput" class="form-label">Cancellation Reason <span style="color: #ef4444;">*</span></label>
                            <input type="text" id="cancelReasonInput" name="reason" required placeholder="e.g. Returned goods, Void transaction" class="form-control">
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" id="dismissCancelModal">Keep Invoice</button>
                        <button type="submit" class="btn-danger" style="padding: 9px 18px; border-radius: var(--saas-radius-md); font-weight: 700;">Confirm Cancellation</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            <?php if ($autoPrint): ?>
                setTimeout(() => window.print(), 300);
            <?php endif; ?>

            const cancelModal = document.getElementById('cancelInvoiceModal');
            const openCancelBtn = document.getElementById('openCancelInvBtn');
            const closeCancelBtn = document.getElementById('closeCancelModal');
            const dismissCancelBtn = document.getElementById('dismissCancelModal');

            if (openCancelBtn) openCancelBtn.addEventListener('click', () => cancelModal.classList.add('open'));
            if (closeCancelBtn) closeCancelBtn.addEventListener('click', () => cancelModal.classList.remove('open'));
            if (dismissCancelBtn) dismissCancelBtn.addEventListener('click', () => cancelModal.classList.remove('open'));
        });
    </script>
</body>
</html>

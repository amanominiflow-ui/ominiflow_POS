<?php
/**
 * OminiFlow POS - Enterprise Tax Invoice Viewer & Print Engine
 * Matches exact dynamic branding invoice pattern:
 * Logo | Dynamic Seller Info | INVOICE metadata | Issued to | Package | Clean Table | Bank Details | Terms & Policy
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/orders_db.php';
require_once __DIR__ . '/includes/storefront_db.php';

// Session initialization
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = current_user();
$isAdmin = ($user !== null);
$userId = $user ? (int) $user['id'] : null;

$invoiceId = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
$orderId = !empty($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$orderNum = !empty($_GET['order']) ? trim((string)$_GET['order']) : '';

$invoice = null;
if ($invoiceId > 0) {
    $invoice = get_invoice_by_id($invoiceId);
} elseif ($orderId > 0) {
    $invoice = get_invoice_by_order_id($orderId);
} elseif ($orderNum !== '') {
    $db = get_db();
    $stmtOrd = $db->prepare('SELECT id, business_id FROM orders WHERE order_number = :num LIMIT 1');
    $stmtOrd->execute(['num' => $orderNum]);
    $ordRow = $stmtOrd->fetch();
    if ($ordRow) {
        $invoice = get_invoice_by_order_id((int)$ordRow['id'], (int)$ordRow['business_id']);
    }
}

// Check access permissions (Admin or Storefront Customer)
if (!$invoice) {
    if ($isAdmin) {
        set_flash('error', 'Invoice not found.');
        redirect(APP_URL . '/invoices.php');
    } else {
        http_response_code(404);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;text-align:center;"><h2>Invoice Not Found</h2><p>We could not find the requested invoice.</p></body></html>';
        exit;
    }
}

$businessId = (int)($invoice['business_id'] ?? 1);
$store = get_store_settings($businessId);
$items = $invoice['items'] ?? [];
$isCancelled = ($invoice['invoice_status'] === 'cancelled');
$autoPrint = isset($_GET['print']);
$isPublicView = !$isAdmin || isset($_GET['standalone']);
$pageTitle = 'Invoice #' . $invoice['invoice_number'];

// Helper: Amount in Indian Words
function invoice_amount_in_words(float $num): string {
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

$amountInWords = invoice_amount_in_words((float)$invoice['total_amount']);

// Invoice formatted number (e.g. 064 or original number)
$rawInvNum = (string)$invoice['invoice_number'];
$invParts = explode('-', $rawInvNum);
$shortInvNum = count($invParts) > 1 ? end($invParts) : $rawInvNum;
if (is_numeric($shortInvNum)) {
    $invoiceDisplayNum = str_pad($shortInvNum, 3, '0', STR_PAD_LEFT);
} else {
    $invoiceDisplayNum = $rawInvNum;
}

// Formatted Date
$invoiceDateStr = date('d-m-Y', strtotime($invoice['invoice_date'] ?? $invoice['created_at'] ?? 'now'));

// Customer details
$custName = trim((string)($invoice['customer_name'] ?: 'Walk-in Customer'));
$custPhone = trim((string)($invoice['customer_phone'] ?? ''));
$custEmail = trim((string)($invoice['customer_email'] ?? ''));
$custAddress = trim((string)($invoice['customer_address'] ?? ''));

// Customer GST (if available)
$custGst = '';
if (!empty($invoice['customer_id'])) {
    $db = get_db();
    try {
        $stC = $db->prepare('SELECT * FROM customers WHERE id = :cid LIMIT 1');
        $stC->execute(['cid' => $invoice['customer_id']]);
        $cRow = $stC->fetch();
        if ($cRow && !empty($cRow['gstin'])) {
            $custGst = $cRow['gstin'];
        }
    } catch (Exception $e) {}
}

// Package Name / Order Type
$packageName = !empty($store['package_name']) ? $store['package_name'] : 'Monthly';
if (!empty($invoice['order_notes']) && stripos($invoice['order_notes'], 'package:') !== false) {
    if (preg_match('/package:\s*([^\n|]+)/i', $invoice['order_notes'], $m)) {
        $packageName = trim($m[1]);
    }
} elseif (!empty($invoice['payment_method']) && $invoice['payment_method'] === 'cod') {
    $packageName = 'Cash on Delivery (COD)';
}

// Calculate total tax percent
$subtotal = (float)$invoice['subtotal'];
$taxAmount = (float)$invoice['tax_amount'];
$grandTotal = (float)$invoice['total_amount'];
$taxRate = ($subtotal > 0 && $taxAmount > 0) ? round(($taxAmount / $subtotal) * 100) : 18;
if ($taxRate <= 0) $taxRate = 18;

// Store Logo resolution
$storeLogo = !empty($store['logo_path']) ? $store['logo_path'] : '';
$logoExists = ($storeLogo !== '' && (file_exists(__DIR__ . '/' . $storeLogo) || str_starts_with($storeLogo, 'http')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($store['store_name']) ?></title>
    <?php if (!$isPublicView): ?>
        <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <?php endif; ?>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: <?= $isPublicView ? '#f1f5f9' : '#f8fafc' ?>;
            color: #1e293b;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            padding: <?= $isPublicView ? '24px 12px' : '0' ?>;
        }

        /* Action Bar */
        .inv-action-bar {
            max-width: 820px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            padding: 14px 20px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            flex-wrap: wrap;
            gap: 10px;
        }
        .inv-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 13.5px;
            font-weight: 600;
            border-radius: 8px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }
        .inv-btn-primary {
            background: #0f172a;
            color: #ffffff;
        }
        .inv-btn-primary:hover {
            background: #1e293b;
        }
        .inv-btn-outline {
            background: #ffffff;
            color: #475569;
            border-color: #cbd5e1;
        }
        .inv-btn-outline:hover {
            background: #f8fafc;
            color: #0f172a;
        }
        .inv-btn-danger {
            background: #ffffff;
            color: #dc2626;
            border-color: #fecaca;
        }
        .inv-btn-danger:hover {
            background: #fef2f2;
        }

        /* Invoice Container */
        .inv-paper {
            max-width: 820px;
            margin: 0 auto;
            background: #ffffff;
            padding: 44px 48px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 4px 16px -2px rgba(15,23,42,0.05);
            position: relative;
        }

        /* Cancellation Watermark */
        .inv-watermark {
            position: absolute;
            top: 45%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 72px;
            font-weight: 900;
            color: rgba(239, 68, 68, 0.12);
            letter-spacing: 0.2em;
            pointer-events: none;
            z-index: 10;
        }

        /* Top Centered Logo */
        .inv-logo-header {
            text-align: center;
            margin-bottom: 24px;
            padding-bottom: 12px;
        }
        .inv-logo-img {
            max-height: 58px;
            max-width: 240px;
            object-fit: contain;
            display: inline-block;
        }
        .inv-logo-fallback {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
        }
        .inv-logo-icon {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 18px;
            font-weight: bold;
        }
        .inv-logo-tagline {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
            font-weight: 500;
        }

        /* Header Grid: Seller (Left) vs Document Info (Right) */
        .inv-header-row {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 20px;
            align-items: flex-start;
            margin-bottom: 24px;
        }
        .inv-seller-name {
            font-size: 19px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.01em;
            margin-bottom: 4px;
        }
        .inv-seller-meta {
            font-size: 12.5px;
            color: #334155;
            line-height: 1.5;
        }
        .inv-seller-gst {
            font-size: 12.5px;
            color: #0f172a;
            font-weight: 700;
            margin-top: 3px;
        }

        .inv-doc-info {
            text-align: right;
        }
        .inv-doc-title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .inv-meta-line {
            font-size: 13px;
            color: #1e293b;
            margin-top: 3px;
        }
        .inv-meta-label {
            font-weight: 700;
            color: #0f172a;
        }
        .inv-meta-val {
            font-weight: 600;
            color: #1e293b;
        }

        /* Middle Grid: Issued To (Left) vs Package (Right) */
        .inv-middle-row {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 20px;
            align-items: flex-end;
            margin-bottom: 20px;
            padding-top: 14px;
        }
        .inv-issued-title {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 3px;
        }
        .inv-cust-name {
            font-size: 14.5px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: -0.01em;
        }
        .inv-cust-address {
            font-size: 12.5px;
            color: #334155;
            line-height: 1.45;
            margin-top: 3px;
        }
        .inv-cust-gst {
            font-size: 12.5px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 3px;
        }
        .inv-package-block {
            text-align: right;
            font-size: 13px;
            color: #0f172a;
            padding-bottom: 4px;
        }

        /* Items Table */
        .inv-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #cbd5e1;
            margin-bottom: 0;
        }
        .inv-table th {
            background: #f8fafc;
            color: #1e293b;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
        }
        .inv-table td {
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            font-size: 13px;
            color: #1e293b;
            vertical-align: middle;
        }
        .inv-table td.desc-col {
            font-weight: 500;
            color: #0f172a;
        }

        /* Summary Calculations Box (Right Aligned under table) */
        .inv-summary-wrap {
            display: flex;
            justify-content: flex-end;
            margin-top: -1px;
        }
        .inv-summary-table {
            width: 270px;
            border-collapse: collapse;
            border: 1px solid #cbd5e1;
            border-top: none;
            background: #ffffff;
        }
        .inv-summary-table td {
            padding: 8px 14px;
            font-size: 13px;
            border-bottom: 1px solid #cbd5e1;
        }
        .inv-summary-table td.label-col {
            color: #475569;
            font-weight: 500;
            text-align: left;
            width: 50%;
        }
        .inv-summary-table td.val-col {
            color: #0f172a;
            font-weight: 600;
            text-align: right;
            width: 50%;
        }
        .inv-total-box {
            border: 2px solid #000000;
            padding: 8px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            margin: -1px -1px -1px -1px;
        }
        .inv-total-title {
            font-size: 15px;
            font-weight: 800;
            color: #000000;
        }
        .inv-total-amount {
            font-size: 15.5px;
            font-weight: 800;
            color: #000000;
        }

        /* Bank Details Section (Bottom Left) */
        .inv-bottom-section {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 20px;
            margin-top: 32px;
            padding-top: 10px;
        }
        .inv-bank-box {
            font-size: 12.5px;
            color: #334155;
            line-height: 1.6;
        }
        .inv-bank-heading {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .inv-bank-row {
            margin-bottom: 2px;
        }
        .inv-bank-row strong {
            color: #0f172a;
            font-weight: 700;
        }

        /* Terms & Privacy Policy */
        .inv-policy-box {
            margin-top: 28px;
            padding-top: 14px;
            border-top: 1px dashed #cbd5e1;
            font-size: 12px;
            color: #64748b;
            line-height: 1.55;
        }
        .inv-policy-title {
            font-weight: 700;
            color: #334155;
            margin-bottom: 4px;
        }

        /* Print Media Styling for Clean A4 */
        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm 10mm;
            }
            .no-print, .inv-action-bar, .app-sidebar, .app-header, button, a.inv-btn {
                display: none !important;
                visibility: hidden !important;
            }
            html, body {
                background: #ffffff !important;
                color: #000000 !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .app-layout, .app-main, .dashboard-content {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
                background: transparent !important;
                width: 100% !important;
            }
            .inv-paper {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .inv-table th {
                background: #f8fafc !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .inv-total-box {
                border: 2px solid #000000 !important;
            }
        }
    </style>
</head>
<body>

<?php if ($isAdmin && !$isPublicView): ?>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>
            <main class="dashboard-content" style="padding: 24px;">
<?php endif; ?>

    <!-- Action Bar (No-Print) -->
    <div class="inv-action-bar no-print">
        <div style="display: flex; align-items: center; gap: 10px;">
            <?php if ($isAdmin): ?>
                <a href="<?= asset('invoices.php') ?>" class="inv-btn inv-btn-outline">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>All Invoices</span>
                </a>
            <?php else: ?>
                <a href="javascript:history.back()" class="inv-btn inv-btn-outline">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Back</span>
                </a>
            <?php endif; ?>

            <span style="display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; background: <?= $isCancelled ? '#fef2f2' : '#ecfdf5' ?>; color: <?= $isCancelled ? '#b91c1c' : '#047857' ?>; border: 1px solid <?= $isCancelled ? '#fecaca' : '#a7f3d0' ?>;">
                <?= $isCancelled ? 'CANCELLED' : 'PAID INVOICE' ?>
            </span>
        </div>

        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <!-- Print Button -->
            <button type="button" class="inv-btn inv-btn-primary" onclick="window.print();">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                <span>Print / Save PDF</span>
            </button>

            <?php if ($isAdmin && !empty($invoice['order_number'])): ?>
                <a href="<?= asset('orders.php?search=' . urlencode($invoice['order_number'])) ?>" class="inv-btn inv-btn-outline">
                    View Order
                </a>
            <?php endif; ?>

            <?php if ($isAdmin && !$isCancelled): ?>
                <button type="button" class="inv-btn inv-btn-danger" id="openCancelInvBtn">
                    Cancel Invoice
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Official Tax Invoice Card -->
    <div class="inv-paper">
        <?php if ($isCancelled): ?>
            <div class="inv-watermark">CANCELLED</div>
        <?php endif; ?>

        <!-- Centered Logo -->
        <div class="inv-logo-header">
            <?php if ($logoExists): ?>
                <img src="<?= asset($storeLogo) ?>" alt="<?= e($store['store_name']) ?>" class="inv-logo-img">
            <?php else: ?>
                <div class="inv-logo-fallback">
                    <span class="inv-logo-icon">⚡</span>
                    <span><?= e($store['store_name']) ?></span>
                </div>
                <?php if (!empty($store['tagline'])): ?>
                    <div class="inv-logo-tagline"><?= e($store['tagline']) ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Header Grid: Seller (Left) vs Invoice Details (Right) -->
        <div class="inv-header-row">
            <div>
                <h1 class="inv-seller-name"><?= e($store['store_name']) ?></h1>
                <div class="inv-seller-meta">
                    <?= e($store['address']) ?>
                </div>
                <?php if (!empty($store['gstin'])): ?>
                    <div class="inv-seller-gst">GSTIN: <?= e($store['gstin']) ?></div>
                <?php endif; ?>
            </div>

            <div class="inv-doc-info">
                <div class="inv-doc-title">INVOICE</div>
                <div class="inv-meta-line">
                    <span class="inv-meta-label">Invoice No:</span>
                    <span class="inv-meta-val"><?= e($invoiceDisplayNum) ?></span>
                </div>
                <div class="inv-meta-line">
                    <span class="inv-meta-label">Date:</span>
                    <span class="inv-meta-val"><?= e($invoiceDateStr) ?></span>
                </div>
            </div>
        </div>

        <!-- Middle Grid: Customer (Left) vs Package (Right) -->
        <div class="inv-middle-row">
            <div>
                <div class="inv-issued-title">Issued to:</div>
                <div class="inv-cust-name"><?= e($custName) ?></div>
                <?php if ($custAddress !== ''): ?>
                    <div class="inv-cust-address"><?= nl2br(e($custAddress)) ?></div>
                <?php endif; ?>
                <?php if ($custPhone !== ''): ?>
                    <div class="inv-cust-address">Phone: <?= e($custPhone) ?><?= $custEmail ? ' &bull; ' . e($custEmail) : '' ?></div>
                <?php endif; ?>
                <?php if ($custGst !== ''): ?>
                    <div class="inv-cust-gst">GST: <?= e($custGst) ?></div>
                <?php endif; ?>
            </div>

            <div>
                <div class="inv-package-block">
                    <strong>Package:</strong> <?= e($packageName) ?>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <table class="inv-table">
            <thead>
                <tr>
                    <th style="width: 48%; text-align: left;">DESCRIPTION</th>
                    <th style="width: 15%; text-align: right;">UNIT PRICE</th>
                    <th style="width: 12%; text-align: center;">HSN/SAC</th>
                    <th style="width: 10%; text-align: center;">QTY</th>
                    <th style="width: 15%; text-align: right;">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #64748b; padding: 20px;">No items listed on this invoice.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $it): ?>
                        <?php
                            $uPrice = (float)$it['unit_price'];
                            $qty = (int)$it['quantity'];
                            $hsn = !empty($it['hsn_code']) ? $it['hsn_code'] : '9983';
                            $lineTot = (float)$it['line_total'];
                        ?>
                        <tr>
                            <td class="desc-col"><?= e($it['product_name']) ?></td>
                            <td style="text-align: right;"><?= number_format($uPrice, 2) ?></td>
                            <td style="text-align: center; color: #475569;"><?= e($hsn) ?></td>
                            <td style="text-align: center; font-weight: 600;"><?= $qty ?></td>
                            <td style="text-align: right; font-weight: 600;"><?= number_format($lineTot, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Calculations Summary Box (Right Aligned) -->
        <div class="inv-summary-wrap">
            <table class="inv-summary-table">
                <tr>
                    <td class="label-col">Subtotal</td>
                    <td class="val-col"><?= number_format($subtotal, 2) ?></td>
                </tr>
                <tr>
                    <td class="label-col">GST <?= $taxRate ?>%</td>
                    <td class="val-col"><?= number_format($taxAmount, 2) ?></td>
                </tr>
                <tr>
                    <td colspan="2" style="padding: 0;">
                        <div class="inv-total-box">
                            <span class="inv-total-title">Total</span>
                            <span class="inv-total-amount"><?= number_format($grandTotal, 2) ?></span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Bank Details (Bottom Left) -->
        <div class="inv-bottom-section">
            <div class="inv-bank-box">
                <div class="inv-bank-heading">Bank details</div>
                <div class="inv-bank-row">Bank Name: <strong><?= e($store['bank_name'] ?: 'HDFC Bank') ?></strong></div>
                <div class="inv-bank-row">Account Holder: <strong><?= e($store['account_holder'] ?: $store['store_name']) ?></strong></div>
                <div class="inv-bank-row">Account Number: <strong><?= e($store['account_number'] ?: '50200111653091') ?></strong></div>
                <div class="inv-bank-row">IFSC: <strong><?= e($store['bank_ifsc'] ?: 'HDFC0000887') ?></strong></div>
                <div class="inv-bank-row">Branch: <strong><?= e($store['bank_branch'] ?: $store['city']) ?></strong></div>
                <div class="inv-bank-row">Account Type: <strong><?= e($store['account_type'] ?: 'Current Account') ?></strong></div>
            </div>

            <div style="text-align: right; font-size: 12px; color: #64748b; display: flex; flex-direction: column; justify-content: flex-end;">
                <div><strong>Amount In Words:</strong></div>
                <div style="color: #0f172a; font-weight: 600; font-style: italic;"><?= e($amountInWords) ?></div>
            </div>
        </div>

        <!-- Dynamic Store Terms / Privacy Policy -->
        <?php if (!empty($store['terms_conditions']) || !empty($store['privacy_policy'])): ?>
            <div class="inv-policy-box">
                <div class="inv-policy-title">Terms & Conditions / Policy:</div>
                <div><?= nl2br(e($store['terms_conditions'] ?: $store['privacy_policy'])) ?></div>
            </div>
        <?php else: ?>
            <div class="inv-policy-box">
                <div class="inv-policy-title">Terms & Conditions:</div>
                <div>1. Goods once sold can be exchanged within 7 days with original invoice.<br>2. This is a computer generated invoice and requires no signature.</div>
            </div>
        <?php endif; ?>
    </div>

<?php if ($isAdmin && !$isPublicView): ?>
            </main>
        </div>
    </div>

    <!-- Cancel Invoice Modal (Admin) -->
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
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($autoPrint): ?>
            setTimeout(() => window.print(), 350);
        <?php endif; ?>

        const cancelModal = document.getElementById('cancelInvoiceModal');
        const openCancelBtn = document.getElementById('openCancelInvBtn');
        const closeCancelBtn = document.getElementById('closeCancelModal');
        const dismissCancelBtn = document.getElementById('dismissCancelModal');

        if (openCancelBtn && cancelModal) openCancelBtn.addEventListener('click', () => cancelModal.classList.add('open'));
        if (closeCancelBtn && cancelModal) closeCancelBtn.addEventListener('click', () => cancelModal.classList.remove('open'));
        if (dismissCancelBtn && cancelModal) dismissCancelBtn.addEventListener('click', () => cancelModal.classList.remove('open'));
    });
</script>
</body>
</html>   });
    </script>
</body>
</html>

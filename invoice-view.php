<?php
/**
 * OminiFlow POS - Enterprise Tax Invoice Viewer & Print Engine
 * Target Design: Dynamic Branded Emerald Card Layout
 * Features:
 * - 100% Dynamic Store Theme Colors
 * - Dynamic Logo & Brand Peacock Fallback
 * - Formatted Header (Order No, Date, Invoice No, Payment Mode)
 * - Customer Details (Name, Phone, Address, Pin Code)
 * - Dynamic Items Table (No., Product Name, Size, Colour, Qty, Price, Total)
 * - Financial Summary Box (Total Amount, Discount, Shipping, Grand Total)
 * - Footer: Vector Barcode, "Packed with love", Dynamic Store Name, Dynamic QR Code & WhatsApp Help
 * - Full Admin/POS Functionality Preserved (Print, Size switch, Cancel modal with stock restore)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/orders_db.php';
require_once __DIR__ . '/includes/storefront_db.php';
require_once __DIR__ . '/includes/barcode_helper.php';

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
$db = get_db();

if ($invoiceId > 0) {
    $stmtInv = $db->prepare('SELECT business_id FROM invoices WHERE id = :id LIMIT 1');
    $stmtInv->execute(['id' => $invoiceId]);
    $invBid = (int)$stmtInv->fetchColumn();
    $invoice = get_invoice_by_id($invoiceId, $invBid ?: null);
} elseif ($orderId > 0) {
    $stmtOrd = $db->prepare('SELECT id, business_id FROM orders WHERE id = :id LIMIT 1');
    $stmtOrd->execute(['id' => $orderId]);
    $ordRow = $stmtOrd->fetch();
    if ($ordRow) {
        $bid = (int)$ordRow['business_id'];
        $invoice = get_invoice_by_order_id($orderId, $bid);
        if (!$invoice) {
            $invData = generate_invoice_data_for_order($orderId, $bid);
            $invoice = $invData['invoice'] ?? null;
        }
    }
} elseif ($orderNum !== '') {
    $stmtOrd = $db->prepare('SELECT id, business_id FROM orders WHERE order_number = :num LIMIT 1');
    $stmtOrd->execute(['num' => $orderNum]);
    $ordRow = $stmtOrd->fetch();
    if ($ordRow) {
        $bid = (int)$ordRow['business_id'];
        $invoice = get_invoice_by_order_id((int)$ordRow['id'], $bid);
        if (!$invoice) {
            $invData = generate_invoice_data_for_order((int)$ordRow['id'], $bid);
            $invoice = $invData['invoice'] ?? null;
        }
    }
}

// Check access permissions
if (!$invoice) {
    if ($isAdmin) {
        set_flash('error', 'Invoice not found.');
        redirect(APP_URL . '/invoices.php');
    } else {
        http_response_code(404);
        echo '<!DOCTYPE html><html><head><title>Invoice Not Found</title></head><body style="font-family:sans-serif;padding:60px 20px;text-align:center;color:#334155;"><div style="font-size:48px;margin-bottom:16px;">📄</div><h2 style="font-size:22px;margin:0 0 8px;color:#0f172a;">Invoice Not Found</h2><p style="font-size:14px;color:#64748b;margin:0 0 24px;">We could not generate or locate the invoice for this order.</p><button onclick="window.close();" style="padding:10px 20px;background:#0f172a;color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer;">Close Window</button></body></html>';
        exit;
    }
}

$businessId = (int)($invoice['business_id'] ?? 1);
$store = get_store_settings($businessId);
$brand = function_exists('get_mobile_store_settings') ? get_mobile_store_settings($businessId) : [];
$items = $invoice['items'] ?? [];
$isCancelled = ($invoice['invoice_status'] === 'cancelled');
$autoPrint = isset($_GET['print']) || isset($_GET['download']);
$isPublicView = !$isAdmin || isset($_GET['standalone']);
$requestedSize = trim((string)($_GET['size'] ?? 'default'));
if (!in_array($requestedSize, ['default', '4x3'], true)) {
    $requestedSize = 'default';
}
$pageTitle = 'Invoice #' . $invoice['invoice_number'];

// 1. Dynamic Store Theme Colors
$storeThemeColor = !empty($store['primary_color']) ? trim((string)$store['primary_color']) :
                   (!empty($brand['header_color']) ? trim((string)$brand['header_color']) : '#0d5c52');

// Validate hex color pattern
if (!preg_match('/^#([a-f0-9]{3}){1,2}$/i', $storeThemeColor)) {
    $storeThemeColor = '#0d5c52';
}

// Helper: Convert hex to RGB array
if (!function_exists('hex_to_rgb')) {
    function hex_to_rgb(string $hex): array {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        ];
    }
}
$rgb = hex_to_rgb($storeThemeColor);
$storeThemeRgb = implode(',', $rgb);
$summaryBgColor = "rgba({$storeThemeRgb}, 0.08)";
$borderColor = $storeThemeColor;

// 2. Format Money Helpers
if (!function_exists('format_inv_money')) {
    function format_inv_money(float $num): string {
        if (fmod($num, 1.0) == 0.0) {
            return number_format($num, 0, '.', '');
        }
        return number_format($num, 2, '.', '');
    }
}

// 3. Dynamic Order No & Invoice No formatting
$rawOrderNum = trim((string)($invoice['order_number'] ?? ''));
if ($rawOrderNum === '') {
    $rawOrderNum = 'AC' . str_pad((string)($invoice['order_id'] ?? $invoice['id']), 8, '0', STR_PAD_LEFT);
}
$displayOrderNo = str_starts_with($rawOrderNum, '#') ? $rawOrderNum : ('#' . $rawOrderNum);

$rawInvNum = trim((string)$invoice['invoice_number']);
if (is_numeric($rawInvNum)) {
    $displayInvoiceNo = 'IN' . str_pad($rawInvNum, 8, '0', STR_PAD_LEFT);
} else {
    $displayInvoiceNo = $rawInvNum;
}

// 4. Formatted Order / Invoice Date
$invoiceDateTimestamp = strtotime((string)($invoice['invoice_date'] ?? $invoice['created_at'] ?? 'now'));
$invoiceDateStr = date('d-m-Y', $invoiceDateTimestamp);

// 5. Payment Mode Formatting
$rawPayMethod = strtolower(trim((string)($invoice['payment_method'] ?? 'cash')));
$paymentModeMap = [
    'cash' => 'Cash',
    'upi' => 'Online (UPI)',
    'online' => 'Online (Razorpay)',
    'razorpay' => 'Online (Razorpay)',
    'card' => 'Card',
    'credit_card' => 'Credit Card',
    'debit_card' => 'Debit Card',
    'cod' => 'Cash on Delivery (COD)',
    'split' => 'Split Payment',
    'credit' => 'Store Credit',
    'store_credit' => 'Store Credit'
];
$paymentModeStr = $paymentModeMap[$rawPayMethod] ?? ucwords(str_replace('_', ' ', $rawPayMethod));

// 6. Customer Details & Pin Code
$custName = trim((string)($invoice['customer_name'] ?: 'Walk-in Customer'));
$custPhone = trim((string)($invoice['customer_phone'] ?? ''));
$custAddress = trim((string)($invoice['customer_address'] ?? ''));
if ($custAddress === '' || $custAddress === 'In-Store Counter') {
    $custAddress = trim(($store['address'] ?? '') . ', ' . ($store['city'] ?? ''));
    if ($custAddress === ', ') $custAddress = 'Walk-in Store Customer';
}

$custPincode = '';
// Try extracting 6-digit Indian PIN code from address
if (preg_match('/\b([1-9][0-9]{5})\b/', $custAddress, $mPin)) {
    $custPincode = $mPin[1];
} elseif (!empty($store['pincode'])) {
    $custPincode = $store['pincode'];
} else {
    $custPincode = '700016';
}

// 7. Store Logo / Peacock Graphic
$candidateLogo = null;
$rawLogos = [
    $brand['logo_path'] ?? null,
    $store['logo_path'] ?? null,
];
$blockedLogos = [
    'assets/images/logo.jpg',
    'assets/images/logo-sm.jpg',
    'assets/images/logo-icon.png',
    'assets/images/logo.png',
    'assets/images/favicon.ico',
    'assets/images/apple-touch-icon.png',
];
foreach ($rawLogos as $l) {
    if ($l && is_string($l)) {
        $lTrim = trim(str_replace('\\', '/', $l));
        $lLtrim = ltrim($lTrim, '/');
        if (!in_array($lLtrim, $blockedLogos, true)) {
            if (file_exists(__DIR__ . '/' . $lLtrim) || str_starts_with($lTrim, 'http') || str_starts_with($lTrim, 'data:')) {
                $candidateLogo = $lLtrim;
                break;
            }
        }
    }
}
$storeLogo = $candidateLogo ?: '';
$logoExists = ($storeLogo !== '');
$storeDisplayName = !empty($store['store_name']) ? $store['store_name'] : (!empty($brand['display_name']) ? $brand['display_name'] : 'ASH COLLECTIVE');

// 8. Calculations
$subtotal = (float)$invoice['subtotal'];
$discountAmount = (float)($invoice['discount_amount'] ?? 0);
$taxAmount = (float)($invoice['tax_amount'] ?? 0);
$shippingFee = (float)($invoice['shipping_fee'] ?? 0);
$grandTotal = (float)$invoice['total_amount'];

// If subtotal is zero (e.g. legacy invoice), sum from items
if ($subtotal <= 0 && !empty($items)) {
    foreach ($items as $it) {
        $subtotal += (float)$it['line_total'];
    }
}
if ($grandTotal <= 0) {
    $grandTotal = $subtotal - $discountAmount + $shippingFee;
}

// 9. Variant Extraction Helper (Size & Colour)
if (!function_exists('extract_item_attributes')) {
    function extract_item_attributes(array $it, PDO $db): array {
        $size = '-';
        $colour = '-';
        $pName = trim((string)$it['product_name']);
        $pSku = trim((string)($it['product_sku'] ?? ''));

        // Check variant_id in database if available
        $variantId = (int)($it['variant_id'] ?? 0);
        if ($variantId > 0) {
            try {
                $stV = $db->prepare('SELECT variant_name, attribute_values FROM product_variants WHERE id = :vid LIMIT 1');
                $stV->execute(['vid' => $variantId]);
                $vRow = $stV->fetch();
                if ($vRow) {
                    $av = json_decode((string)$vRow['attribute_values'], true);
                    if (is_array($av)) {
                        foreach ($av as $k => $v) {
                            $kLow = strtolower((string)$k);
                            if (in_array($kLow, ['size', 'sizes', 'size / fits'], true)) {
                                $size = trim((string)$v);
                            }
                            if (in_array($kLow, ['color', 'colour', 'shade'], true)) {
                                $colour = trim((string)$v);
                            }
                        }
                    }
                    if ($size === '-' || $colour === '-') {
                        $vn = (string)$vRow['variant_name'];
                        if (str_contains($vn, '/')) {
                            $parts = explode('/', $vn);
                            if ($size === '-' && isset($parts[0])) $size = trim($parts[0]);
                            if ($colour === '-' && isset($parts[1])) $colour = trim($parts[1]);
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        // Fallback: Parse common sizes from product name / sku
        if ($size === '-') {
            if (preg_match('/\b(XXXL|XXL|2XL|3XL|4XL|XL|XS|S|M|L|Free Size|Regular)\b/i', $pName . ' ' . $pSku, $mSize)) {
                $size = strtoupper($mSize[1]);
            }
        }

        // Fallback: Parse common colours from product name / sku
        if ($colour === '-') {
            $colorsList = 'Pink|Green|Blue|Red|Black|White|Yellow|Orange|Purple|Navy|Grey|Gray|Maroon|Teal|Beige|Brown|Peach|Lavender|Olive|Mint|Cyan|Gold|Silver';
            if (preg_match('/\b(' . $colorsList . ')\b/i', $pName . ' ' . $pSku, $mCol)) {
                $colour = ucfirst(strtolower($mCol[1]));
            }
        }

        return ['size' => $size, 'colour' => $colour];
    }
}

// 10. Barcode SVG Generation
$barcodeSvg = generate_code128_svg($rawOrderNum, 36, 1.4, $storeThemeColor);

// 11. WhatsApp / Help Phone (Dynamically resolved from Store Preferences)
$carePhone = trim((string)($brand['customer_care_phone'] ?? ''));
$waPhone = trim((string)($brand['contact_whatsapp'] ?? ''));
$storePhone = trim((string)($store['phone'] ?? $brand['phone'] ?? ''));

$rawWhatsApp = '';
if ($carePhone !== '' && $carePhone !== '+91 98765 43210' && $carePhone !== '9876543210' && $carePhone !== '919876543210') {
    $rawWhatsApp = $carePhone;
} elseif ($waPhone !== '' && $waPhone !== '919876543210' && $waPhone !== '9876543210' && $waPhone !== '+91 98765 43210') {
    $rawWhatsApp = $waPhone;
} elseif ($carePhone !== '') {
    $rawWhatsApp = $carePhone;
} elseif ($waPhone !== '') {
    $rawWhatsApp = $waPhone;
} elseif ($storePhone !== '') {
    $rawWhatsApp = $storePhone;
} else {
    $rawWhatsApp = '9876543210';
}

if (!function_exists('format_invoice_whatsapp_phone')) {
    function format_invoice_whatsapp_phone(string $phone): string {
        $phone = trim($phone);
        if ($phone === '') {
            return '+91 98765 43210';
        }
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }
        if (str_starts_with($digits, '91') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10) {
            return '+91 ' . substr($digits, 0, 5) . ' ' . substr($digits, 5);
        }
        if (str_starts_with($phone, '+')) {
            return $phone;
        }
        return '+91 ' . $phone;
    }
}
$formattedWhatsAppPhone = format_invoice_whatsapp_phone($rawWhatsApp);

// 12. Dynamic Page Pagination & Item Chunking
$totalItemsCount = count($items);
$itemChunks = [];

if ($requestedSize === '4x3') {
    // 4x3 card: 2 items fit on single card with header and footer.
    // When > 2 items (e.g. 6 items), paginate 3 items per card so card never exceeds 3 inches!
    if ($totalItemsCount <= 2) {
        $itemChunks = [$items];
    } else {
        $itemChunks = array_chunk($items, 3);
    }
} else {
    // Default A4 size: Fits up to 8 items on single card, or 8 items per page on multi-page
    if ($totalItemsCount <= 8) {
        $itemChunks = [$items];
    } else {
        $itemChunks = array_chunk($items, 8);
    }
}

if (empty($itemChunks)) {
    $itemChunks = [[]];
}
$totalPages = count($itemChunks);

// Public invoice verification URL for QR code
$invoiceVerifyUrl = APP_URL . '/invoice-view.php?id=' . $invoice['id'] . '&standalone=1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($storeDisplayName) ?></title>
    
    <!-- Google Fonts for typography and handwriting script -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <?php if (!$isPublicView): ?>
        <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <?php endif; ?>

    <script src="<?= asset('assets/js/qrcode.min.js') ?>"></script>

    <style>
        :root {
            --inv-theme: <?= $storeThemeColor ?>;
            --inv-theme-rgb: <?= $storeThemeRgb ?>;
            --inv-theme-tint: <?= $summaryBgColor ?>;
            --inv-border: <?= $borderColor ?>;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: <?= $isPublicView ? '#f1f5f9' : '#f8fafc' ?>;
            color: #1e293b;
            line-height: 1.4;
            -webkit-font-smoothing: antialiased;
            padding: <?= $isPublicView ? '24px 12px' : '0' ?>;
        }

        /* Action Bar */
        .inv-action-bar {
            max-width: 860px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            padding: 14px 20px;
            border-radius: 12px;
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
            font-size: 13px;
            font-weight: 700;
            border-radius: 8px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }
        .inv-btn-primary {
            background: var(--inv-theme);
            color: #ffffff;
        }
        .inv-btn-primary:hover {
            opacity: 0.92;
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

        /* Main Invoice Card (Exact Match to Target Design) */
        .inv-card {
            max-width: 860px;
            margin: 0 auto;
            background: #ffffff;
            border: 2.5px solid var(--inv-theme);
            border-radius: 20px;
            padding: 32px 36px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
            position: relative;
            background-clip: padding-box;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .inv-card + .inv-card {
            margin-top: 28px;
        }
        body.size-4x3 .inv-card + .inv-card {
            margin-top: 16px;
        }

        /* Cancellation Watermark */
        .inv-watermark {
            position: absolute;
            top: 45%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 72px;
            font-weight: 900;
            color: rgba(239, 68, 68, 0.14);
            letter-spacing: 0.2em;
            pointer-events: none;
            z-index: 10;
        }

        /* TOP SECTION GRID: Brand (Left) | Invoice Meta (Middle) | Customer Details (Right) */
        .inv-top-grid {
            display: grid;
            grid-template-columns: 215px 1.15fr 1.25fr;
            gap: 22px;
            align-items: stretch;
            margin-bottom: 24px;
        }

        /* Left Column: Brand Logo */
        .inv-brand-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding-right: 12px;
        }
        .inv-brand-img {
            max-height: 98px;
            max-width: 200px;
            object-fit: contain;
            margin-bottom: 8px;
        }
        .inv-brand-peacock-icon {
            width: 98px;
            height: 98px;
            color: var(--inv-theme);
            margin-bottom: 8px;
        }
        .inv-brand-title {
            font-size: 16.5px;
            font-weight: 900;
            color: var(--inv-theme);
            letter-spacing: 0.14em;
            text-transform: uppercase;
            line-height: 1.25;
            text-align: center;
        }

        /* Middle Column: Invoice Title & Meta */
        .inv-meta-col {
            padding-left: 6px;
            padding-right: 14px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }
        .inv-main-heading {
            font-size: 26px;
            font-weight: 800;
            color: var(--inv-theme);
            letter-spacing: 0.05em;
            line-height: 1;
            margin-bottom: 5px;
        }
        .inv-thank-you {
            font-size: 10px;
            font-weight: 700;
            color: var(--inv-theme);
            letter-spacing: 0.04em;
            display: flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 12px;
            white-space: nowrap;
        }
        .inv-thank-you .heart-icon {
            color: var(--inv-theme);
            font-size: 11.5px;
        }
        .inv-meta-list, .inv-cust-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
        }
        .inv-row-kv {
            display: grid;
            grid-template-columns: 96px 12px 1fr;
            align-items: baseline;
            line-height: 1.45;
        }
        .inv-row-kv .lbl {
            font-weight: 600;
            color: #1e293b;
            white-space: nowrap;
        }
        .inv-row-kv .sep {
            font-weight: 600;
            color: #1e293b;
            text-align: center;
        }
        .inv-row-kv .val {
            font-weight: 600;
            color: #1e293b;
            word-break: break-word;
        }

        /* Right Column: Customer Details (With Elegant Vertical Divider) */
        .inv-cust-col {
            padding-left: 20px;
            border-left: 1.5px solid var(--inv-theme);
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }
        .inv-cust-heading {
            font-size: 15px;
            font-weight: 750;
            color: var(--inv-theme);
            margin-bottom: 12px;
            letter-spacing: -0.01em;
            line-height: 1.2;
        }
        .inv-cust-col .inv-row-kv {
            grid-template-columns: 72px 12px 1fr;
        }

        /* Page Badge & Continuation Styles */
        .inv-page-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 750;
            color: var(--inv-theme);
            background: var(--inv-theme-tint);
            border: 1px solid rgba(var(--inv-theme-rgb), 0.3);
            border-radius: 12px;
            padding: 2px 8px;
            letter-spacing: 0.04em;
        }
        .inv-continue-note {
            text-align: right;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--inv-theme);
            font-style: italic;
            padding: 12px 4px 6px;
        }
        .inv-mini-top-grid {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1.5px solid var(--inv-theme);
            padding-bottom: 12px;
            margin-bottom: 18px;
        }
        .inv-mini-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .inv-mini-brand-img {
            max-height: 48px;
            max-width: 100px;
            object-fit: contain;
        }
        .inv-mini-peacock {
            width: 44px;
            height: 44px;
            color: var(--inv-theme);
        }
        .inv-mini-title {
            font-size: 14px;
            font-weight: 900;
            color: var(--inv-theme);
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .inv-mini-meta {
            font-size: 11.5px;
            font-weight: 600;
            color: #334155;
            text-align: right;
            line-height: 1.45;
        }

        /* PRODUCTS TABLE */
        .inv-table-wrap {
            width: 100%;
            margin-bottom: 18px;
            border-radius: 8px 8px 0 0;
            overflow: hidden;
            border: 1.5px solid var(--inv-theme);
        }
        .inv-products-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .inv-products-table thead {
            background: var(--inv-theme);
            color: #ffffff;
        }
        .inv-products-table th {
            padding: 9px 10px;
            font-size: 12px;
            font-weight: 700;
            border-right: 1.5px solid rgba(255,255,255,0.25);
            text-align: center;
            letter-spacing: 0.02em;
        }
        .inv-products-table th:last-child {
            border-right: none;
        }
        .inv-products-table th.col-name {
            text-align: left;
            padding-left: 14px;
        }
        .inv-products-table tbody td {
            padding: 9px 10px;
            font-size: 12px;
            color: #1e293b;
            font-weight: 500;
            border-bottom: 1.5px solid var(--inv-theme);
            border-right: 1.5px solid var(--inv-theme);
            vertical-align: middle;
            text-align: center;
        }
        .inv-products-table tbody tr:last-child td {
            border-bottom: none;
        }
        .inv-products-table tbody td:last-child {
            border-right: none;
        }
        .inv-products-table tbody td.col-name {
            text-align: left;
            padding-left: 14px;
            font-weight: 600;
        }
        .inv-products-table tbody td.col-price,
        .inv-products-table tbody td.col-total {
            text-align: right;
            padding-right: 14px;
            font-weight: 600;
        }

        /* SUMMARY CALCULATION BOX (Right Aligned Below Table) */
        .inv-summary-container {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 22px;
        }
        .inv-summary-box {
            width: 290px;
            background: var(--inv-theme-tint);
            border-radius: 10px;
            padding: 12px 18px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .inv-summary-row {
            display: grid;
            grid-template-columns: 110px 14px 1fr;
            align-items: center;
            font-size: 12.5px;
            color: #1e293b;
            font-weight: 600;
        }
        .inv-summary-row.grand-total-row {
            margin-top: 4px;
            padding-top: 6px;
            border-top: 1.5px solid rgba(var(--inv-theme-rgb), 0.25);
            font-size: 14.5px;
            font-weight: 800;
            color: var(--inv-theme);
        }
        .inv-summary-row .s-label {
            text-align: left;
            white-space: nowrap;
        }
        .inv-summary-row .s-sep {
            text-align: center;
        }
        .inv-summary-row .s-val {
            text-align: right;
            font-weight: 700;
        }

        /* FOOTER SECTION: Barcode (Left) | Packed with Love (Center) | QR Code & Help (Right) */
        .inv-footer-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr 1.25fr;
            gap: 16px;
            align-items: center;
            padding-top: 6px;
        }

        /* Footer Left: Barcode */
        .inv-footer-barcode {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .inv-barcode-title {
            font-size: 11px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 3px;
            white-space: nowrap;
        }
        .inv-barcode-svg-wrap {
            max-width: 210px;
            margin-bottom: 2px;
        }
        .inv-barcode-svg-wrap svg {
            width: 100%;
            height: 46px;
        }

        /* Footer Center: Packed with love */
        .inv-footer-love {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            border-left: 1.5px solid var(--inv-theme);
            border-right: 1.5px solid var(--inv-theme);
            padding: 4px 12px;
            min-height: 64px;
        }
        .inv-love-script {
            font-family: 'Caveat', cursive;
            font-size: 21px;
            font-weight: 700;
            color: var(--inv-theme);
            line-height: 1;
            margin-bottom: 2px;
        }
        .inv-love-heart {
            color: var(--inv-theme);
            font-size: 12px;
            line-height: 1;
            margin-bottom: 3px;
        }
        .inv-love-brand {
            font-size: 11px;
            font-weight: 800;
            color: var(--inv-theme);
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        /* Footer Right: QR Code & WhatsApp Help */
        .inv-footer-help {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            padding-left: 6px;
        }
        .inv-qr-box {
            width: 68px;
            height: 68px;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .inv-qr-box canvas, .inv-qr-box img {
            width: 68px !important;
            height: 68px !important;
            display: block;
        }
        .inv-help-text {
            font-size: 11px;
            color: #1e293b;
            line-height: 1.35;
        }
        .inv-help-title {
            font-weight: 700;
            color: #1e293b;
            font-size: 11.5px;
        }
        .inv-help-sub {
            color: #475569;
            font-weight: 500;
        }
        .inv-help-phone {
            font-weight: 700;
            color: #1e293b;
            white-space: nowrap;
        }

        /* =========================================================================
           PAGE SIZE SPECIFIC STYLES (SCREEN PREVIEW & PRINT)
           ========================================================================= */

        /* --- 4x3 INCH COMPACT CARD (SCREEN PREVIEW) --- */
        body.size-4x3 .inv-card {
            max-width: 530px;
            padding: 16px 20px;
            border-radius: 14px;
        }
        body.size-4x3 .inv-top-grid {
            grid-template-columns: 125px 1fr 1.05fr;
            gap: 12px;
            margin-bottom: 12px;
        }
        body.size-4x3 .inv-brand-peacock-icon {
            width: 56px;
            height: 56px;
            margin-bottom: 4px;
        }
        body.size-4x3 .inv-brand-img {
            max-height: 56px;
            max-width: 115px;
            margin-bottom: 4px;
        }
        body.size-4x3 .inv-brand-title {
            font-size: 11px;
            font-weight: 900;
        }
        body.size-4x3 .inv-meta-col {
            padding-left: 0;
            padding-right: 8px;
        }
        body.size-4x3 .inv-main-heading {
            font-size: 19px;
            margin-bottom: 2px;
        }
        body.size-4x3 .inv-thank-you {
            font-size: 8px;
            margin-bottom: 6px;
        }
        body.size-4x3 .inv-meta-list, body.size-4x3 .inv-cust-list {
            font-size: 9.5px;
            gap: 2px;
        }
        body.size-4x3 .inv-row-kv {
            grid-template-columns: 72px 8px 1fr;
        }
        body.size-4x3 .inv-cust-col {
            padding-left: 10px;
            border-left: 1px solid var(--inv-theme);
        }
        body.size-4x3 .inv-cust-heading {
            font-size: 12px;
            margin-bottom: 6px;
        }
        body.size-4x3 .inv-cust-col .inv-row-kv {
            grid-template-columns: 56px 8px 1fr;
        }
        body.size-4x3 .inv-table-wrap {
            margin-bottom: 10px;
        }
        body.size-4x3 .inv-products-table th,
        body.size-4x3 .inv-products-table tbody td {
            padding: 5px 6px;
            font-size: 9.5px;
        }
        body.size-4x3 .inv-summary-container {
            margin-bottom: 12px;
        }
        body.size-4x3 .inv-summary-box {
            width: 210px;
            padding: 8px 12px;
            gap: 3px;
        }
        body.size-4x3 .inv-summary-row {
            font-size: 9.5px;
            grid-template-columns: 85px 10px 1fr;
        }
        body.size-4x3 .inv-summary-row.grand-total-row {
            font-size: 11.5px;
            padding-top: 3px;
            margin-top: 2px;
        }
        body.size-4x3 .inv-footer-grid {
            grid-template-columns: 1.15fr 0.9fr 1.25fr;
            gap: 8px;
            padding-top: 4px;
        }
        body.size-4x3 .inv-barcode-title {
            font-size: 8.5px;
            margin-bottom: 2px;
        }
        body.size-4x3 .inv-barcode-svg-wrap {
            max-width: 135px;
        }
        body.size-4x3 .inv-barcode-svg-wrap svg {
            height: 32px;
        }
        body.size-4x3 .inv-footer-love {
            padding: 2px 6px;
            min-height: 52px;
            border-width: 1px;
        }
        body.size-4x3 .inv-love-script {
            font-family: 'Caveat', cursive;
            font-size: 15px;
        }
        body.size-4x3 .inv-love-heart {
            font-size: 9px;
            margin-bottom: 1px;
        }
        body.size-4x3 .inv-love-brand {
            font-size: 8.5px;
        }
        body.size-4x3 .inv-footer-help {
            gap: 8px;
            padding-left: 0;
        }
        body.size-4x3 .inv-qr-box {
            width: 48px;
            height: 48px;
        }
        body.size-4x3 .inv-qr-box canvas, body.size-4x3 .inv-qr-box img {
            width: 48px !important;
            height: 48px !important;
        }
        body.size-4x3 .inv-help-text {
            font-size: 8.5px;
            line-height: 1.2;
        }
        body.size-4x3 .inv-help-title {
            font-size: 9px;
        }

        /* PRINT MEDIA STYLES FOR CRISP OUTPUT */
        @media print {
            .no-print, .inv-action-bar, .app-sidebar, .app-header, button, a.inv-btn,
            .modal-overlay, .spotlight-overlay {
                display: none !important;
                visibility: hidden !important;
                width: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
            }
            html, body {
                background: #ffffff !important;
                color: #000000 !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                overflow: visible !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .app-layout, .app-main, .dashboard-content {
                display: block !important;
                position: static !important;
                margin: 0 !important;
                padding: 0 !important;
                background: transparent !important;
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                min-height: 0 !important;
                height: auto !important;
                overflow: visible !important;
            }
            .inv-card {
                border: 2.5px solid var(--inv-theme) !important;
                border-radius: 18px !important;
                box-shadow: none !important;
                padding: 24px 28px !important;
                margin: 0 auto !important;
                max-width: 100% !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                page-break-after: always !important;
                break-after: page !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .inv-card:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .inv-products-table thead {
                background: var(--inv-theme) !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .inv-summary-box {
                background: var(--inv-theme-tint) !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            /*
             * 4x3 print: Chrome collapses CSS Grid `fr` columns and word-break
             * wraps customer text character-by-character. Use flex + fixed
             * widths so print matches the on-screen card, one page, no overflow.
             */
            html:has(body.size-4x3) {
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                height: auto !important;
                overflow: hidden !important;
            }
            body.size-4x3 {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                height: auto !important;
                overflow: hidden !important;
            }
            body.size-4x3 .app-layout,
            body.size-4x3 .app-main,
            body.size-4x3 .dashboard-content {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                height: auto !important;
                overflow: hidden !important;
            }
            body.size-4x3 .inv-card {
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                max-height: 3in !important;
                min-height: 0 !important;
                overflow: hidden !important;
                padding: 0.08in 0.1in !important;
                border-radius: 8px !important;
                margin: 0 !important;
                border: 1.75pt solid var(--inv-theme) !important;
                box-sizing: border-box !important;
                page: card4x3;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                page-break-after: always !important;
                break-after: page !important;
            }
            body.size-4x3 .inv-card:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            body.size-4x3 .inv-top-grid {
                display: flex !important;
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                align-items: flex-start !important;
                width: 100% !important;
                gap: 6px !important;
                margin-bottom: 6px !important;
                grid-template-columns: none !important;
            }
            body.size-4x3 .inv-brand-col {
                flex: 0 0 22% !important;
                width: 22% !important;
                min-width: 0 !important;
                max-width: 24% !important;
                padding-right: 4px !important;
                justify-content: flex-start !important;
                align-self: flex-start !important;
            }
            body.size-4x3 .inv-meta-col {
                flex: 1 1 38% !important;
                min-width: 0 !important;
                width: auto !important;
                padding-left: 0 !important;
                padding-right: 6px !important;
            }
            body.size-4x3 .inv-cust-col {
                flex: 1 1 40% !important;
                min-width: 0 !important;
                width: auto !important;
                padding-left: 8px !important;
                border-left: 1px solid var(--inv-theme) !important;
            }
            body.size-4x3 .inv-row-kv {
                display: flex !important;
                flex-wrap: nowrap !important;
                align-items: flex-start !important;
                gap: 3px !important;
                grid-template-columns: none !important;
            }
            body.size-4x3 .inv-row-kv .lbl {
                flex: 0 0 62px !important;
                width: 62px !important;
                white-space: nowrap !important;
            }
            body.size-4x3 .inv-row-kv .sep {
                flex: 0 0 6px !important;
                width: 6px !important;
            }
            body.size-4x3 .inv-row-kv .val {
                flex: 1 1 auto !important;
                min-width: 0 !important;
                word-break: normal !important;
                overflow-wrap: break-word !important;
                white-space: normal !important;
            }
            body.size-4x3 .inv-cust-col .inv-row-kv .lbl {
                flex-basis: 48px !important;
                width: 48px !important;
            }
            body.size-4x3 .inv-mini-top-grid {
                padding-bottom: 4px !important;
                margin-bottom: 6px !important;
            }
            body.size-4x3 .inv-mini-brand-img {
                max-height: 28px !important;
                max-width: 60px !important;
            }
            body.size-4x3 .inv-mini-peacock {
                width: 26px !important;
                height: 26px !important;
            }
            body.size-4x3 .inv-mini-title {
                font-size: 10px !important;
            }
            body.size-4x3 .inv-mini-meta {
                font-size: 8px !important;
            }
            body.size-4x3 .inv-continue-note {
                font-size: 8px !important;
                padding: 4px 2px 2px !important;
            }
            body.size-4x3 .inv-main-heading { font-size: 14px !important; margin-bottom: 1px !important; }
            body.size-4x3 .inv-thank-you {
                font-size: 6.5px !important;
                margin-bottom: 3px !important;
                white-space: normal !important;
                letter-spacing: 0.02em !important;
            }
            body.size-4x3 .inv-brand-peacock-icon { width: 36px !important; height: 36px !important; margin-bottom: 2px !important; }
            body.size-4x3 .inv-brand-img { max-height: 36px !important; max-width: 80px !important; }
            body.size-4x3 .inv-brand-title { font-size: 8px !important; }
            body.size-4x3 .inv-meta-list, body.size-4x3 .inv-cust-list { font-size: 7.5px !important; gap: 1px !important; }
            body.size-4x3 .inv-cust-heading { font-size: 9.5px !important; margin-bottom: 3px !important; }
            body.size-4x3 .inv-table-wrap { margin-bottom: 5px !important; border-width: 1px !important; overflow: hidden !important; }
            body.size-4x3 .inv-products-table { table-layout: fixed !important; width: 100% !important; }
            body.size-4x3 .inv-products-table th,
            body.size-4x3 .inv-products-table tbody td { padding: 2px 3px !important; font-size: 7.5px !important; border-width: 1px !important; }
            body.size-4x3 .inv-summary-container { margin-bottom: 5px !important; }
            body.size-4x3 .inv-summary-box { width: 1.55in !important; padding: 4px 6px !important; gap: 1px !important; border-radius: 5px !important; }
            body.size-4x3 .inv-summary-row {
                display: flex !important;
                font-size: 7.5px !important;
                grid-template-columns: none !important;
                gap: 4px !important;
            }
            body.size-4x3 .inv-summary-row .s-label { flex: 0 0 70px !important; }
            body.size-4x3 .inv-summary-row .s-sep { flex: 0 0 6px !important; }
            body.size-4x3 .inv-summary-row .s-val { flex: 1 1 auto !important; }
            body.size-4x3 .inv-summary-row.grand-total-row { font-size: 9px !important; padding-top: 2px !important; margin-top: 1px !important; }
            body.size-4x3 .inv-footer-grid {
                display: flex !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
                gap: 4px !important;
                padding-top: 2px !important;
                grid-template-columns: none !important;
            }
            body.size-4x3 .inv-footer-barcode { flex: 1 1 0 !important; min-width: 0 !important; }
            body.size-4x3 .inv-footer-love { flex: 0 0 auto !important; min-height: 42px !important; padding: 2px 6px !important; }
            body.size-4x3 .inv-footer-help { flex: 1 1 0 !important; min-width: 0 !important; gap: 6px !important; padding-left: 4px !important; }
            body.size-4x3 .inv-barcode-title { font-size: 7px !important; margin-bottom: 1px !important; }
            body.size-4x3 .inv-barcode-svg-wrap { max-width: 1.2in !important; }
            body.size-4x3 .inv-barcode-svg-wrap svg { height: 22px !important; }
            body.size-4x3 .inv-love-script { font-size: 12px !important; }
            body.size-4x3 .inv-love-heart { font-size: 7px !important; margin-bottom: 1px !important; }
            body.size-4x3 .inv-love-brand { font-size: 7px !important; }
            body.size-4x3 .inv-qr-box { width: 36px !important; height: 36px !important; }
            body.size-4x3 .inv-qr-box canvas, body.size-4x3 .inv-qr-box img { width: 36px !important; height: 36px !important; }
            body.size-4x3 .inv-help-text { font-size: 7px !important; line-height: 1.15 !important; }
            body.size-4x3 .inv-help-title { font-size: 7.5px !important; }
        }
    </style>

    <style id="dynamicPageSizeStyle">
        <?php if ($requestedSize === '4x3'): ?>
            @page { size: 4in 3in; margin: 0; }
            @page card4x3 { size: 4in 3in; margin: 0; }
            @media print { @page { size: 4in 3in; margin: 0; } }
        <?php else: ?>
            @page { size: A4 portrait; margin: 8mm; }
            @media print { @page { size: A4 portrait; margin: 8mm; } }
        <?php endif; ?>
    </style>
</head>
<body class="<?= $requestedSize !== 'default' ? 'size-' . $requestedSize : '' ?>">

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

        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <!-- Page Size Selector (Dynamic Screen & Print Switching) -->
            <div class="inv-size-selector-wrap" style="display: inline-flex; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 4px 10px;">
                <label for="invPageSizeSelect" style="font-size: 12px; font-weight: 700; color: #475569; margin: 0; white-space: nowrap; display: flex; align-items: center; gap: 4px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                    Page Size:
                </label>
                <select id="invPageSizeSelect" onchange="switchInvoiceSize(this.value)" style="font-size: 12.5px; font-weight: 700; color: #0f172a; border: none; background: transparent; cursor: pointer; outline: none;">
                    <option value="default" <?= $requestedSize === 'default' ? 'selected' : '' ?>>📄 Default (A4)</option>
                    <option value="4x3" <?= $requestedSize === '4x3' ? 'selected' : '' ?>>🏷️ 4x3 inch (Card)</option>
                </select>
            </div>

            <!-- Print Button -->
            <button type="button" class="inv-btn inv-btn-primary" onclick="printInvoiceCard();">
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

    <!-- MULTI-PAGE CHUNKED INVOICE CARDS -->
    <?php 
    $globalItemIdx = 1;
    foreach ($itemChunks as $pageIdx => $chunkItems): 
        $pageNumber = $pageIdx + 1;
        $isFirstPage = ($pageIdx === 0);
        $isLastPage = ($pageIdx === $totalPages - 1);
    ?>
    <div class="inv-card <?= !$isFirstPage ? 'inv-card-continue' : '' ?>">
        <?php if ($isCancelled): ?>
            <div class="inv-watermark">CANCELLED</div>
        <?php endif; ?>

        <?php if ($isFirstPage): ?>
            <!-- TOP SECTION: Brand (Left) | Invoice Details (Center) | Customer Details (Right) -->
            <div class="inv-top-grid">
                <!-- Left: Brand Logo / Peacock Vector -->
                <div class="inv-brand-col">
                    <?php if ($logoExists): ?>
                        <img src="<?= asset($storeLogo) ?>" alt="<?= e($storeDisplayName) ?>" class="inv-brand-img">
                    <?php else: ?>
                        <svg class="inv-brand-peacock-icon" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Stylized Peacock Plume -->
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M50 14C53.8 14 57.3 15.8 59.5 18.7C62.5 17.2 66.2 17.5 69 19.5C71.8 21.5 73.2 25 72.7 28.4C75.7 29.8 77.8 32.8 77.8 36.2C77.8 38.6 76.8 40.8 75 42.4C77.7 44.8 78.6 48.4 77.3 51.8C76.1 55.2 72.9 57.4 69.3 57.4C68.9 57.4 68.5 57.3 68.1 57.2C66.6 60.8 63.1 63.2 59 63.2C57.2 63.2 55.5 62.6 54.1 61.6C52.2 63.5 49.4 64.5 46.6 64.2C41.7 63.7 37.9 59.8 37.6 54.9C34.6 54.3 32.2 52.1 31.2 49.2C30.1 45.9 31.1 42.3 33.8 40C32.2 38.4 31.4 36.1 31.7 33.7C32.2 30.2 34.6 27.4 38 26.3C38 22.8 40.2 19.6 43.5 18.3C45.5 15.4 47.7 14 50 14Z" fill="currentColor"/>
                            <!-- Crown 3 dots with stalks -->
                            <circle cx="50" cy="9" r="2.2" fill="currentColor"/>
                            <circle cx="44" cy="11" r="1.8" fill="currentColor"/>
                            <circle cx="56" cy="11" r="1.8" fill="currentColor"/>
                            <line x1="50" y1="11" x2="50" y2="14" stroke="currentColor" stroke-width="1.2"/>
                            <line x1="44" y1="12.5" x2="47" y2="15.5" stroke="currentColor" stroke-width="1.2"/>
                            <line x1="56" y1="12.5" x2="53" y2="15.5" stroke="currentColor" stroke-width="1.2"/>
                            <!-- Plume outer accent dots -->
                            <circle cx="34" cy="21" r="1.4" fill="currentColor"/>
                            <circle cx="66" cy="21" r="1.4" fill="currentColor"/>
                            <circle cx="26" cy="31" r="1.4" fill="currentColor"/>
                            <circle cx="74" cy="31" r="1.4" fill="currentColor"/>
                            <circle cx="25" cy="44" r="1.4" fill="currentColor"/>
                            <circle cx="75" cy="44" r="1.4" fill="currentColor"/>
                            <!-- Peacock graceful curved neck & head cutout (white) -->
                            <path d="M48.5 28C48.5 25.2 50.8 23 53.6 23C54.8 23 55.9 23.4 56.7 24.2L58.9 24.6C59.5 24.6 59.8 25.2 59.4 25.6L57.4 27.2C57.6 27.8 57.8 28.4 57.8 29C57.8 31.8 55.4 33.8 53.4 35.8C51.4 37.8 50.2 40.2 50.2 43.4C50.2 47.4 53.4 50.6 57.4 50.6C59 50.6 60.2 50.2 61.4 49.4C59.8 51.8 56.6 53.4 53 53.4C47 53.4 42.2 48.6 42.2 42.6C42.2 37.4 45 33.4 47.4 30.6C48.2 29.8 48.5 29 48.5 28Z" fill="#ffffff"/>
                            <!-- Eye dot -->
                            <circle cx="54.5" cy="25.5" r="0.8" fill="currentColor"/>
                        </svg>
                    <?php endif; ?>
                    <div class="inv-brand-title"><?= e($storeDisplayName) ?></div>
                </div>

                <!-- Middle: Invoice Heading & Meta -->
                <div class="inv-meta-col">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <h1 class="inv-main-heading">INVOICE</h1>
                        <?php if ($totalPages > 1): ?>
                            <span class="inv-page-badge">Page <?= $pageNumber ?> of <?= $totalPages ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="inv-thank-you">
                        <span>THANK YOU FOR SHOPPING WITH US</span>
                        <span class="heart-icon">♥</span>
                    </div>

                    <div class="inv-meta-list">
                        <div class="inv-row-kv">
                            <span class="lbl">Order No</span>
                            <span class="sep">:</span>
                            <span class="val"><?= e($displayOrderNo) ?></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Order Date</span>
                            <span class="sep">:</span>
                            <span class="val"><?= e($invoiceDateStr) ?></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Invoice No</span>
                            <span class="sep">:</span>
                            <span class="val"><?= e($displayInvoiceNo) ?></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Payment Mode</span>
                            <span class="sep">:</span>
                            <span class="val"><?= e($paymentModeStr) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Right: Customer Details -->
                <div class="inv-cust-col">
                    <h3 class="inv-cust-heading">Customer Details</h3>
                    <div class="inv-cust-list">
                        <div class="inv-row-kv">
                            <span class="lbl">Name</span>
                            <span class="sep">:</span>
                            <span class="val"><?= e($custName) ?></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Phone</span>
                            <span class="sep">:</span>
                            <span class="val"><?= e($custPhone ?: 'N/A') ?></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Address</span>
                            <span class="sep">:</span>
                            <span class="val"><?= e($custAddress) ?></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Pin Code</span>
                            <span class="sep">:</span>
                            <span class="val"><?= e($custPincode) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- MINI TOP HEADER (Continuation Page) -->
            <div class="inv-mini-top-grid">
                <div class="inv-mini-brand">
                    <?php if ($logoExists): ?>
                        <img src="<?= asset($storeLogo) ?>" alt="<?= e($storeDisplayName) ?>" class="inv-mini-brand-img">
                    <?php else: ?>
                        <svg class="inv-mini-peacock" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M50 14C53.8 14 57.3 15.8 59.5 18.7C62.5 17.2 66.2 17.5 69 19.5C71.8 21.5 73.2 25 72.7 28.4C75.7 29.8 77.8 32.8 77.8 36.2C77.8 38.6 76.8 40.8 75 42.4C77.7 44.8 78.6 48.4 77.3 51.8C76.1 55.2 72.9 57.4 69.3 57.4C68.9 57.4 68.5 57.3 68.1 57.2C66.6 60.8 63.1 63.2 59 63.2C57.2 63.2 55.5 62.6 54.1 61.6C52.2 63.5 49.4 64.5 46.6 64.2C41.7 63.7 37.9 59.8 37.6 54.9C34.6 54.3 32.2 52.1 31.2 49.2C30.1 45.9 31.1 42.3 33.8 40C32.2 38.4 31.4 36.1 31.7 33.7C32.2 30.2 34.6 27.4 38 26.3C38 22.8 40.2 19.6 43.5 18.3C45.5 15.4 47.7 14 50 14Z" fill="currentColor"/>
                            <circle cx="50" cy="9" r="2.2" fill="currentColor"/>
                            <circle cx="44" cy="11" r="1.8" fill="currentColor"/>
                            <circle cx="56" cy="11" r="1.8" fill="currentColor"/>
                            <path d="M48.5 28C48.5 25.2 50.8 23 53.6 23C54.8 23 55.9 23.4 56.7 24.2L58.9 24.6C59.5 24.6 59.8 25.2 59.4 25.6L57.4 27.2C57.6 27.8 57.8 28.4 57.8 29C57.8 31.8 55.4 33.8 53.4 35.8C51.4 37.8 50.2 40.2 50.2 43.4C50.2 47.4 53.4 50.6 57.4 50.6C59 50.6 60.2 50.2 61.4 49.4C59.8 51.8 56.6 53.4 53 53.4C47 53.4 42.2 48.6 42.2 42.6C42.2 37.4 45 33.4 47.4 30.6C48.2 29.8 48.5 29 48.5 28Z" fill="#ffffff"/>
                        </svg>
                    <?php endif; ?>
                    <div>
                        <div class="inv-mini-title"><?= e($storeDisplayName) ?></div>
                        <div style="font-size: 11px; font-weight: 700; color: #64748b;">INVOICE #<?= e($displayInvoiceNo) ?> (Cont.)</div>
                    </div>
                </div>
                <div class="inv-mini-meta">
                    <div><strong>Customer:</strong> <?= e($custName) ?></div>
                    <div><strong>Date:</strong> <?= e($invoiceDateStr) ?></div>
                    <div style="margin-top: 2px;"><span class="inv-page-badge">Page <?= $pageNumber ?> of <?= $totalPages ?></span></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- PRODUCTS TABLE -->
        <div class="inv-table-wrap">
            <table class="inv-products-table">
                <thead>
                    <tr>
                        <th style="width: 7%;">No.</th>
                        <th class="col-name" style="width: 38%;">Product Name</th>
                        <th style="width: 11%;">Size</th>
                        <th style="width: 14%;">Colour</th>
                        <th style="width: 8%;">Qty</th>
                        <th style="width: 11%; text-align: right; padding-right: 16px;">Price (₹)</th>
                        <th style="width: 11%; text-align: right; padding-right: 16px;">Total (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($chunkItems)): ?>
                        <tr>
                            <td colspan="7" style="padding: 24px; text-align: center; color: #64748b;">No items listed on this page.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        foreach ($chunkItems as $it): 
                            $attrs = extract_item_attributes($it, $db);
                            $uPrice = (float)$it['unit_price'];
                            $qty = (int)$it['quantity'];
                            $lTotal = (float)$it['line_total'];
                        ?>
                            <tr>
                                <td><?= $globalItemIdx++ ?></td>
                                <td class="col-name"><?= e($it['product_name']) ?></td>
                                <td><?= e($attrs['size']) ?></td>
                                <td><?= e($attrs['colour']) ?></td>
                                <td style="font-weight: 600;"><?= $qty ?></td>
                                <td class="col-price"><?= format_inv_money($uPrice) ?></td>
                                <td class="col-total"><?= format_inv_money($lTotal) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!$isLastPage): ?>
            <!-- Continuation Note -->
            <div class="inv-continue-note">
                Continued on Page <?= $pageNumber + 1 ?> &rarr;
            </div>
        <?php else: ?>
            <!-- FINANCIAL SUMMARY BOX (Right Aligned Below Table) -->
            <div class="inv-summary-container">
                <div class="inv-summary-box">
                    <div class="inv-summary-row">
                        <span class="s-label">Total Amount</span>
                        <span class="s-sep">:</span>
                        <span class="s-val">₹ <?= format_inv_money($subtotal) ?></span>
                    </div>
                    <div class="inv-summary-row">
                        <span class="s-label">Discount</span>
                        <span class="s-sep">:</span>
                        <span class="s-val">₹ <?= format_inv_money($discountAmount) ?></span>
                    </div>
                    <div class="inv-summary-row">
                        <span class="s-label">Shipping</span>
                        <span class="s-sep">:</span>
                        <span class="s-val">₹ <?= format_inv_money($shippingFee) ?></span>
                    </div>
                    <div class="inv-summary-row grand-total-row">
                        <span class="s-label">Grand Total</span>
                        <span class="s-sep">:</span>
                        <span class="s-val">₹ <?= format_inv_money($grandTotal) ?></span>
                    </div>
                </div>
            </div>

            <!-- FOOTER SECTION: Barcode (Left) | Packed with love (Center) | QR Code & Help (Right) -->
            <div class="inv-footer-grid">
                <!-- Left: Order No Barcode -->
                <div class="inv-footer-barcode">
                    <div class="inv-barcode-title">Order No: <?= e($displayOrderNo) ?></div>
                    <div class="inv-barcode-svg-wrap">
                        <?= $barcodeSvg ?>
                    </div>
                </div>

                <!-- Center: Packed with love signature -->
                <div class="inv-footer-love">
                    <div class="inv-love-script">Packed with love</div>
                    <div class="inv-love-heart">♥</div>
                    <div class="inv-love-brand"><?= e($storeDisplayName) ?></div>
                </div>

                <!-- Right: Dynamic QR Code & WhatsApp Help Info -->
                <div class="inv-footer-help">
                    <div class="inv-qr-box invQrTarget" title="Scan to verify invoice">
                        <!-- QR Code dynamically injected via qrcode.min.js with clean fallback -->
                        <noscript>
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode($invoiceVerifyUrl) ?>" alt="QR Code" style="width:100%;height:100%;">
                        </noscript>
                    </div>
                    <div class="inv-help-text">
                        <div class="inv-help-title">Need help?</div>
                        <div class="inv-help-sub">WhatsApp us at</div>
                        <div class="inv-help-phone"><?= e($formattedWhatsAppPhone) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

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
    function renderQrCode(dim = 68) {
        const qrContainers = document.querySelectorAll('.invQrTarget');
        if (qrContainers && typeof QRCode !== 'undefined') {
            qrContainers.forEach(function(qrContainer) {
                qrContainer.innerHTML = '';
                new QRCode(qrContainer, {
                    text: '<?= addslashes($invoiceVerifyUrl) ?>',
                    width: dim,
                    height: dim,
                    colorDark: '<?= addslashes($storeThemeColor) ?>',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            });
        }
    }

    function printInvoiceCard() {
        const select = document.getElementById('invPageSizeSelect');
        const size = select ? select.value : 'default';
        const params = new URLSearchParams(window.location.search);
        const alreadyStandalone = params.get('standalone') === '1' || params.has('print');

        if (size === '4x3' && !alreadyStandalone) {
            const url = new URL(window.location.href);
            url.searchParams.set('size', '4x3');
            url.searchParams.set('standalone', '1');
            url.searchParams.set('print', '1');
            const popup = window.open(url.toString(), 'invoicePrint4x3', 'width=920,height=720');
            if (!popup) {
                window.print();
            }
            return;
        }
        window.print();
    }

    function switchInvoiceSize(size) {
        document.body.classList.remove('size-4x3');
        if (size === '4x3') {
            document.body.classList.add('size-4x3');
        }
        const select = document.getElementById('invPageSizeSelect');
        if (select) select.value = size;

        // Update URL query parameter without full reload
        const url = new URL(window.location.href);
        if (size === '4x3') {
            url.searchParams.set('size', '4x3');
        } else {
            url.searchParams.delete('size');
        }
        window.history.replaceState({}, '', url.toString());

        // Update dynamic page size CSS rule for printing
        let dynStyle = document.getElementById('dynamicPageSizeStyle');
        if (!dynStyle) {
            dynStyle = document.createElement('style');
            dynStyle.id = 'dynamicPageSizeStyle';
            document.head.appendChild(dynStyle);
        }
        if (size === '4x3') {
            dynStyle.innerHTML = '@page { size: 4in 3in; margin: 0; } @page card4x3 { size: 4in 3in; margin: 0; } @media print { @page { size: 4in 3in; margin: 0; } }';
            renderQrCode(44);
        } else {
            dynStyle.innerHTML = '@page { size: A4 portrait; margin: 8mm; } @media print { @page { size: A4 portrait; margin: 8mm; } }';
            renderQrCode(68);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const initialSize = '<?= $requestedSize ?>';
        renderQrCode(initialSize === '4x3' ? 44 : 68);

        <?php if ($autoPrint): ?>
            setTimeout(() => window.print(), <?= $requestedSize === '4x3' ? 500 : 350 ?>);
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
</html>

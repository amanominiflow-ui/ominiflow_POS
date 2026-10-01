<?php
/**
 * OminiFlow POS - Batch & Multi-Invoice Print Engine
 * Renders multiple selected retail tax invoices on consecutive pages with zero popup blockers.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/orders_db.php';
require_once __DIR__ . '/includes/storefront_db.php';
require_once __DIR__ . '/includes/barcode_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = current_user();
$isAdmin = ($user !== null);
$userId = $user ? (int) $user['id'] : null;

// Parse IDs from GET parameter (ids=1,2,3 or id=1)
$rawIds = trim((string)($_GET['ids'] ?? $_GET['id'] ?? ''));
$requestedIds = [];
if ($rawIds !== '') {
    $parts = explode(',', $rawIds);
    foreach ($parts as $p) {
        $cleanId = (int) trim($p);
        if ($cleanId > 0 && !in_array($cleanId, $requestedIds, true)) {
            $requestedIds[] = $cleanId;
        }
    }
}

if (empty($requestedIds)) {
    set_flash('error', 'No invoices were selected for printing.');
    redirect(APP_URL . '/invoices.php');
}

$db = get_db();
$invoicesData = [];

// Helper function to enrich items with GST if not defined
if (!function_exists('invoice_batch_enrich_items_gst')) {
    function invoice_batch_enrich_items_gst(array $items, PDO $db, int $businessId): array {
        if ($items === []) {
            return $items;
        }
        $productIds = [];
        foreach ($items as $it) {
            $pid = (int) ($it['product_id'] ?? 0);
            if ($pid > 0) {
                $productIds[$pid] = $pid;
            }
        }
        $productMeta = [];
        if ($productIds !== []) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $params = array_merge([$businessId], array_values($productIds));
            try {
                $st = $db->prepare("SELECT id, hsn_code, tax_percent FROM products WHERE business_id = ? AND id IN ({$placeholders})");
                $st->execute($params);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $productMeta[(int) $row['id']] = $row;
                }
            } catch (Throwable $e) {
                $productMeta = [];
            }
        }
        foreach ($items as $idx => $it) {
            $pid = (int) ($it['product_id'] ?? 0);
            if ($pid > 0 && isset($productMeta[$pid])) {
                $pm = $productMeta[$pid];
                if (trim((string) ($it['hsn_code'] ?? '')) === '' && trim((string) ($pm['hsn_code'] ?? '')) !== '') {
                    $items[$idx]['hsn_code'] = $pm['hsn_code'];
                }
                if ((float) ($it['tax_percent'] ?? 0) <= 0 && (float) ($pm['tax_percent'] ?? 0) > 0) {
                    $items[$idx]['tax_percent'] = (float) $pm['tax_percent'];
                }
            }
            $qty = max(1, (int) ($it['quantity'] ?? 1));
            $unit = (float) ($it['unit_price'] ?? 0);
            $lineTotal = (float) ($it['line_total'] ?? 0);
            $lineTax = (float) ($it['tax_amount'] ?? 0);
            $rate = (float) ($items[$idx]['tax_percent'] ?? 0);
            $base = round($unit * $qty, 2);
            if ($lineTax <= 0 && $rate > 0 && $base > 0) {
                if ($lineTotal > $base + 0.009) {
                    $lineTax = round($lineTotal - $base, 2);
                } else {
                    $lineTax = round($base * ($rate / 100), 2);
                    if ($lineTotal <= 0) {
                        $items[$idx]['line_total'] = round($base + $lineTax, 2);
                    }
                }
                $items[$idx]['tax_amount'] = $lineTax;
            } elseif ($lineTax <= 0 && $lineTotal > $base + 0.009 && $base > 0) {
                $items[$idx]['tax_amount'] = round($lineTotal - $base, 2);
                if ($rate <= 0) {
                    $items[$idx]['tax_percent'] = round((($lineTotal - $base) / $base) * 100, 2);
                }
            }
        }
        return $items;
    }
}

if (!function_exists('batch_extract_item_attributes')) {
    function batch_extract_item_attributes(array $it, PDO $db): array {
        $size = '-';
        $colour = '-';
        $pName = trim((string)($it['product_name'] ?? ''));
        $pSku = trim((string)($it['product_sku'] ?? ''));

        $storedSize = trim((string)($it['size'] ?? ''));
        $storedColour = trim((string)($it['colour'] ?? $it['color'] ?? ''));
        if ($storedSize !== '' && $storedSize !== '-') {
            $size = $storedSize;
        }
        if ($storedColour !== '' && $storedColour !== '-') {
            $colour = $storedColour;
        }

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
                            if ($size === '-' && in_array($kLow, ['size', 'sizes', 'size / fits'], true)) {
                                $size = trim((string)$v);
                            }
                            if ($colour === '-' && in_array($kLow, ['color', 'colour', 'shade'], true)) {
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

        if ($size === '-') {
            if (preg_match('/\b(XXXL|XXL|2XL|3XL|4XL|XL|XS|S|M|L|Free Size|Regular)\b/i', $pName . ' ' . $pSku, $mSize)) {
                $size = strtoupper($mSize[1]);
            }
        }
        if ($colour === '-') {
            $colorsList = 'Pink|Green|Blue|Red|Black|White|Yellow|Orange|Purple|Navy|Grey|Gray|Maroon|Teal|Beige|Brown|Peach|Lavender|Olive|Mint|Cyan|Gold|Silver';
            if (preg_match('/\b(' . $colorsList . ')\b/i', $pName . ' ' . $pSku, $mCol)) {
                $colour = ucfirst(strtolower($mCol[1]));
            }
        }
        return ['size' => $size, 'colour' => $colour];
    }
}

if (!function_exists('batch_format_phone')) {
    function batch_format_phone(string $phone): string {
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

$productHsnCache = [];

// Fetch and prepare data for all requested invoices
foreach ($requestedIds as $invId) {
    $stmtInv = $db->prepare('SELECT business_id FROM invoices WHERE id = :id LIMIT 1');
    $stmtInv->execute(['id' => $invId]);
    $invBid = (int)$stmtInv->fetchColumn();
    $invObj = get_invoice_by_id($invId, $invBid ?: null);
    if (!$invObj) {
        continue;
    }

    $bid = (int)($invObj['business_id'] ?? 1);
    $store = get_store_settings($bid);
    $brand = function_exists('get_mobile_store_settings') ? get_mobile_store_settings($bid) : [];
    $rawItems = invoice_batch_enrich_items_gst($invObj['items'] ?? [], $db, $bid);
    $invObj['items'] = $rawItems;

    // Theme color
    $themeColor = !empty($store['primary_color']) ? trim((string)$store['primary_color']) :
                  (!empty($brand['header_color']) ? trim((string)$brand['header_color']) : '#0d5c52');
    if (!preg_match('/^#([a-f0-9]{3}){1,2}$/i', $themeColor)) {
        $themeColor = '#0d5c52';
    }

    // Hex to RGB
    $hexClean = ltrim($themeColor, '#');
    if (strlen($hexClean) === 3) {
        $hexClean = $hexClean[0].$hexClean[0].$hexClean[1].$hexClean[1].$hexClean[2].$hexClean[2];
    }
    $rgbArr = [hexdec(substr($hexClean, 0, 2)), hexdec(substr($hexClean, 2, 2)), hexdec(substr($hexClean, 4, 2))];
    $themeRgb = implode(',', $rgbArr);

    // Formatted Order & Invoice numbers
    $rawOrderNum = trim((string)($invObj['order_number'] ?? ''));
    if ($rawOrderNum === '') {
        $rawOrderNum = 'AC' . str_pad((string)($invObj['order_id'] ?? $invObj['id']), 8, '0', STR_PAD_LEFT);
    }
    $displayOrderNo = str_starts_with($rawOrderNum, '#') ? $rawOrderNum : ('#' . $rawOrderNum);

    $rawInvNum = trim((string)$invObj['invoice_number']);
    $displayInvoiceNo = is_numeric($rawInvNum) ? ('IN' . str_pad($rawInvNum, 8, '0', STR_PAD_LEFT)) : $rawInvNum;

    $invDateTimestamp = strtotime((string)($invObj['invoice_date'] ?? $invObj['created_at'] ?? 'now'));
    $invDateStr = date('d-m-Y', $invDateTimestamp);

    // Payment mode
    $rawPayMethod = strtolower(trim((string)($invObj['payment_method'] ?? 'cash')));
    $payMap = [
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
    $paymentModeStr = $payMap[$rawPayMethod] ?? ucwords(str_replace('_', ' ', $rawPayMethod));

    // Customer info
    $custName = trim((string)($invObj['customer_name'] ?: 'Walk-in Customer'));
    $custPhone = trim((string)($invObj['customer_phone'] ?? ''));
    $custAddress = trim((string)($invObj['customer_address'] ?? ''));
    if ($custAddress === '' || $custAddress === 'In-Store Counter') {
        $custAddress = trim(($store['address'] ?? '') . ', ' . ($store['city'] ?? ''));
        if ($custAddress === ', ') $custAddress = 'Walk-in Store Customer';
    }
    $custPincode = '';
    if (preg_match('/\b([1-9][0-9]{5})\b/', $custAddress, $mPin)) {
        $custPincode = $mPin[1];
    } elseif (!empty($store['pincode'])) {
        $custPincode = trim((string)$store['pincode']);
    }

    // Store Display Name & Logo
    $storeDisplayName = !empty($store['store_name']) ? trim((string)$store['store_name']) :
                        (!empty($brand['display_name']) ? trim((string)$brand['display_name']) : 'OMINIFLOW STORE');

    $storeLogo = null;
    $possibleLogos = [
        $brand['logo_path'] ?? null,
        $store['logo_path'] ?? null,
    ];
    foreach ($possibleLogos as $l) {
        if ($l && is_string($l)) {
            $lTrim = trim(str_replace('\\', '/', $l));
            $lLtrim = ltrim($lTrim, '/');
            $fullPath = __DIR__ . '/' . $lLtrim;
            if (is_file($fullPath)) {
                $storeLogo = $lLtrim;
                break;
            }
        }
    }
    $logoExists = ($storeLogo !== null);

    // Financial calculations
    $subtotal = (float)($invObj['subtotal'] ?? 0);
    $discountAmount = (float)($invObj['discount_amount'] ?? 0);
    $taxAmount = (float)($invObj['tax_amount'] ?? 0);
    $shippingFee = (float)($invObj['shipping_fee'] ?? 0);
    $grandTotal = (float)($invObj['total_amount'] ?? 0);

    $itemsSum = 0.0;
    $lineTaxSum = 0.0;
    $lineExTaxSum = 0.0;
    foreach ($rawItems as $it) {
        $lt = (float)($it['line_total'] ?? 0);
        $tx = (float)($it['tax_amount'] ?? 0);
        $qty = max(1, (int)($it['quantity'] ?? 1));
        $unit = (float)($it['unit_price'] ?? 0);
        $itemsSum += $lt;
        $lineTaxSum += $tx;
        if ($tx > 0 && $lt >= $tx) {
            $lineExTaxSum += round($lt - $tx, 2);
        } elseif ($unit > 0) {
            $lineExTaxSum += round($unit * $qty, 2);
        } elseif ($lt > 0) {
            $lineExTaxSum += $lt;
        }
    }
    $lineTaxSum = round($lineTaxSum, 2);
    $lineExTaxSum = round($lineExTaxSum, 2);

    if ($subtotal <= 0 && $itemsSum > 0) {
        $subtotal = $itemsSum;
    }
    if ($taxAmount <= 0 && $lineTaxSum > 0) {
        $taxAmount = $lineTaxSum;
    }
    if ($taxAmount <= 0 && $grandTotal > 0) {
        $impliedTax = round($grandTotal - $shippingFee - ($subtotal - $discountAmount), 2);
        if ($impliedTax > 0.009) {
            $taxAmount = $impliedTax;
        }
    }
    if ($lineExTaxSum > 0 && $lineTaxSum > 0) {
        if (abs($subtotal - ($lineExTaxSum + $lineTaxSum)) < 0.06 || $subtotal > $lineExTaxSum + 0.009) {
            $subtotal = $lineExTaxSum;
        }
    } elseif ($lineExTaxSum > 0 && $subtotal <= 0) {
        $subtotal = $lineExTaxSum;
    }

    $grandTotal = max(0.00, round($subtotal - $discountAmount + $taxAmount + $shippingFee, 2));

    // GST Breakup
    $taxableAmount = (float)($invObj['taxable_amount'] ?? 0);
    $cgstAmount = (float)($invObj['cgst_amount'] ?? 0);
    $sgstAmount = (float)($invObj['sgst_amount'] ?? 0);
    $igstAmount = (float)($invObj['igst_amount'] ?? 0);
    if ($taxableAmount <= 0) {
        $taxableAmount = max(0.00, round($subtotal - $discountAmount, 2));
    }
    if ($taxAmount > 0 && ($cgstAmount + $sgstAmount + $igstAmount) <= 0) {
        if ($igstAmount <= 0) {
            $cgstAmount = round($taxAmount / 2, 2);
            $sgstAmount = round($taxAmount - $cgstAmount, 2);
        }
    }
    $showGstBreakup = ($taxAmount > 0 || $cgstAmount > 0 || $sgstAmount > 0 || $igstAmount > 0 || $lineTaxSum > 0);

    // GSTIN
    $sellerGstin = trim((string)($store['gstin'] ?? ($brand['footer_gst_no'] ?? '')));

    // Barcode SVG
    $barcodeSvg = generate_code128_svg($rawOrderNum, 36, 1.4, $themeColor);

    // WhatsApp phone
    $carePhone = trim((string)($brand['customer_care_phone'] ?? ''));
    $waPhone = trim((string)($brand['contact_whatsapp'] ?? ''));
    $storePhone = trim((string)($store['phone'] ?? $brand['phone'] ?? ''));
    $rawWhatsApp = $carePhone ?: ($waPhone ?: ($storePhone ?: '9876543210'));
    $formattedWhatsAppPhone = batch_format_phone($rawWhatsApp);

    // Verification URL
    $verifyUrl = APP_URL . '/invoice-view.php?id=' . $invObj['id'] . '&standalone=1';

    $isCancelled = ($invObj['invoice_status'] === 'cancelled');

    $invoicesData[] = [
        'invoice' => $invObj,
        'businessId' => $bid,
        'themeColor' => $themeColor,
        'themeRgb' => $themeRgb,
        'storeDisplayName' => $storeDisplayName,
        'logoExists' => $logoExists,
        'storeLogo' => $storeLogo,
        'displayOrderNo' => $displayOrderNo,
        'displayInvoiceNo' => $displayInvoiceNo,
        'invDateStr' => $invDateStr,
        'paymentModeStr' => $paymentModeStr,
        'custName' => $custName,
        'custPhone' => $custPhone,
        'custAddress' => $custAddress,
        'custPincode' => $custPincode,
        'items' => $rawItems,
        'subtotal' => $subtotal,
        'discountAmount' => $discountAmount,
        'taxAmount' => $taxAmount,
        'shippingFee' => $shippingFee,
        'grandTotal' => $grandTotal,
        'showGstBreakup' => $showGstBreakup,
        'taxableAmount' => $taxableAmount,
        'cgstAmount' => $cgstAmount,
        'sgstAmount' => $sgstAmount,
        'igstAmount' => $igstAmount,
        'sellerGstin' => $sellerGstin,
        'barcodeSvg' => $barcodeSvg,
        'formattedWhatsAppPhone' => $formattedWhatsAppPhone,
        'verifyUrl' => $verifyUrl,
        'isCancelled' => $isCancelled,
    ];
}

if (empty($invoicesData)) {
    set_flash('error', 'Selected invoices could not be loaded.');
    redirect(APP_URL . '/invoices.php');
}

$autoPrint = isset($_GET['print']);
$requestedSize = trim((string)($_GET['size'] ?? 'default'));
if (!in_array($requestedSize, ['default', '4x3'], true)) {
    $requestedSize = 'default';
}
$invPageSizeLabel = $requestedSize === '4x3' ? '4×3 in' : 'A4';
$totalInvoicesCount = count($invoicesData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Print (<?= $totalInvoicesCount ?> Invoices) — OminiFlow POS</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <script src="<?= asset('assets/js/qrcode.min.js') ?>"></script>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            line-height: 1.4;
            -webkit-font-smoothing: antialiased;
            padding: 20px 12px;
        }

        /* Top Sticky Action Bar */
        .batch-action-bar {
            max-width: 880px;
            margin: 0 auto 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            padding: 14px 22px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            flex-wrap: wrap;
            gap: 12px;
            position: sticky;
            top: 14px;
            z-index: 1000;
        }
        .batch-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            font-size: 13.5px;
            font-weight: 700;
            border-radius: 8px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }
        .batch-btn-primary {
            background: #2563eb;
            color: #ffffff;
        }
        .batch-btn-primary:hover {
            background: #1d4ed8;
        }
        .batch-btn-outline {
            background: #ffffff;
            color: #334155;
            border-color: #cbd5e1;
        }
        .batch-btn-outline:hover {
            background: #f8fafc;
            color: #0f172a;
        }

        /* Invoice Container & Spacing */
        .inv-batch-container {
            max-width: 880px;
            margin: 0 auto;
        }
        .inv-batch-card-wrapper {
            margin-bottom: 32px;
            position: relative;
        }

        /* Invoice Card */
        .inv-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 32px 36px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
            position: relative;
            background-clip: padding-box;
            page-break-inside: avoid;
            break-inside: avoid;
        }

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

        .inv-top-grid {
            display: grid;
            grid-template-columns: 215px 1.15fr 1.25fr;
            gap: 22px;
            align-items: stretch;
            margin-bottom: 24px;
        }
        .inv-brand-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding-right: 12px;
            min-width: 0;
            overflow: hidden;
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
            margin-bottom: 8px;
        }
        .inv-brand-title {
            font-size: 16.5px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            line-height: 1.25;
            text-align: center;
            width: 100%;
            overflow-wrap: anywhere;
        }

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
            letter-spacing: 0.05em;
            line-height: 1;
            margin-bottom: 5px;
        }
        .inv-thank-you {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.04em;
            display: flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 12px;
            white-space: nowrap;
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
        .inv-row-kv .lbl { font-weight: 600; color: #1e293b; white-space: nowrap; }
        .inv-row-kv .sep { font-weight: 600; color: #1e293b; text-align: center; }
        .inv-row-kv .val { font-weight: 600; color: #1e293b; word-break: break-word; }

        .inv-cust-col {
            padding-left: 20px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }
        .inv-cust-heading {
            font-size: 15px;
            font-weight: 750;
            margin-bottom: 12px;
            letter-spacing: -0.01em;
            line-height: 1.2;
        }
        .inv-cust-col .inv-row-kv {
            grid-template-columns: 72px 12px 1fr;
        }

        .inv-table-wrap {
            width: 100%;
            margin-bottom: 18px;
            border-radius: 8px 8px 0 0;
            overflow: hidden;
        }
        .inv-products-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .inv-products-table thead {
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
        .inv-products-table th:last-child { border-right: none; }
        .inv-products-table tbody td {
            padding: 9px 10px;
            font-size: 12px;
            color: #1e293b;
            text-align: center;
            vertical-align: middle;
            background: #ffffff;
        }
        .inv-products-table tbody tr:last-child td {
            border-bottom: none;
        }
        .inv-item-name {
            font-weight: 700;
            color: #0f172a;
            line-height: 1.3;
        }

        .inv-summary-container {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 22px;
        }
        .inv-summary-box {
            width: 320px;
            border-radius: 12px;
            padding: 12px 18px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .inv-summary-row {
            display: grid;
            grid-template-columns: 120px 14px 1fr;
            font-size: 12px;
            align-items: center;
        }
        .inv-summary-row .s-label { font-weight: 600; color: #334155; }
        .inv-summary-row .s-sep { font-weight: 600; color: #334155; text-align: center; }
        .inv-summary-row .s-val { font-weight: 700; color: #0f172a; text-align: right; }
        .inv-summary-row.grand-total-row {
            border-top: 1.5px solid rgba(0,0,0,0.15);
            padding-top: 8px;
            margin-top: 4px;
            font-size: 14.5px;
        }
        .inv-summary-row.grand-total-row .s-label,
        .inv-summary-row.grand-total-row .s-sep,
        .inv-summary-row.grand-total-row .s-val {
            font-weight: 850;
        }

        .inv-footer-grid {
            display: grid;
            grid-template-columns: 1.25fr 0.95fr 1.4fr;
            gap: 16px;
            align-items: center;
            border-top: 1.5px solid #cbd5e1;
            padding-top: 18px;
        }
        .inv-footer-barcode {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .inv-barcode-title {
            font-size: 11px;
            font-weight: 750;
            letter-spacing: 0.04em;
            margin-bottom: 4px;
            text-transform: uppercase;
        }
        .inv-barcode-svg-wrap { max-width: 210px; }
        .inv-barcode-svg-wrap svg { width: 100%; height: 42px; display: block; }

        .inv-footer-love {
            border-radius: 12px;
            padding: 8px 12px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 70px;
        }
        .inv-love-script {
            font-family: 'Caveat', cursive;
            font-size: 22px;
            font-weight: 700;
            line-height: 1;
        }
        .inv-love-heart {
            font-size: 13px;
            margin-bottom: 2px;
        }
        .inv-love-brand {
            font-size: 9.5px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .inv-footer-help {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-left: 10px;
        }
        .inv-qr-box {
            width: 68px;
            height: 68px;
            background: #ffffff;
            padding: 4px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .inv-help-text {
            font-size: 11px;
            color: #334155;
            line-height: 1.35;
        }
        .inv-help-title {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
            margin-bottom: 2px;
        }
        .inv-help-phone {
            font-weight: 800;
            font-size: 12.5px;
        }

        /* PRINT MEDIA STYLES */
        @media print {
            .no-print, .batch-action-bar, .app-sidebar, .app-header, button, a {
                display: none !important;
                visibility: hidden !important;
            }
            *, *::before, *::after {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            html, body {
                background: #ffffff !important;
                color: #000000 !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .inv-batch-container {
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .inv-batch-card-wrapper {
                page-break-after: always !important;
                break-after: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .inv-batch-card-wrapper:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .inv-card {
                box-shadow: none !important;
                border-radius: 16px !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }
    </style>

    <style id="batchPageSizeStyle">
        <?php if ($requestedSize === '4x3'): ?>
            @page { size: 4in 3in; margin: 0; }
            @media print { @page { size: 4in 3in; margin: 0; } }
        <?php else: ?>
            @page { size: A4 portrait; margin: 6mm 8mm; }
            @media print { @page { size: A4 portrait; margin: 6mm 8mm; } }
        <?php endif; ?>
    </style>
</head>
<body class="<?= $requestedSize !== 'default' ? 'size-' . $requestedSize : '' ?>">

    <!-- Top Sticky Action Bar -->
    <div class="batch-action-bar no-print">
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="<?= asset('invoices.php') ?>" class="batch-btn batch-btn-outline">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span>Back to Invoices</span>
            </a>
            <span style="font-weight: 700; font-size: 13.5px; color: #0f172a;">
                <?= $totalInvoicesCount ?> Invoice<?= $totalInvoicesCount > 1 ? 's' : '' ?> Loaded
            </span>
        </div>

        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <div style="display: inline-flex; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 4px 10px;">
                <label style="font-size: 12px; font-weight: 700; color: #475569; margin: 0;">Size:</label>
                <select onchange="switchBatchSize(this.value)" style="font-size: 12.5px; font-weight: 700; color: #0f172a; border: none; background: transparent; cursor: pointer;">
                    <option value="default" <?= $requestedSize === 'default' ? 'selected' : '' ?>>📄 Default (A4)</option>
                    <option value="4x3" <?= $requestedSize === '4x3' ? 'selected' : '' ?>>🏷️ 4x3 inch (Card)</option>
                </select>
            </div>

            <button type="button" class="batch-btn batch-btn-primary" onclick="window.print();">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                <span>Print All (<?= $totalInvoicesCount ?>)</span>
            </button>
        </div>
    </div>

    <!-- Multi-Invoice Cards Container -->
    <div class="inv-batch-container">
        <?php foreach ($invoicesData as $idx => $invItem): 
            $theme = $invItem['themeColor'];
            $themeRgb = $invItem['themeRgb'];
            $qrTargetId = 'batchQr_' . $idx;
        ?>
        <div class="inv-batch-card-wrapper">
            <div class="inv-card" style="border: 2.5px solid <?= $theme ?>;">
                <?php if ($invItem['isCancelled']): ?>
                    <div class="inv-watermark">CANCELLED</div>
                <?php endif; ?>

                <!-- TOP GRID: Brand | Meta | Customer -->
                <div class="inv-top-grid">
                    <!-- Brand Column -->
                    <div class="inv-brand-col">
                        <?php if ($invItem['logoExists']): ?>
                            <img src="<?= asset($invItem['storeLogo']) ?>" alt="<?= e($invItem['storeDisplayName']) ?>" class="inv-brand-img">
                        <?php else: ?>
                            <svg class="inv-brand-peacock-icon" style="color: <?= $theme ?>;" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M50 14C53.8 14 57.3 15.8 59.5 18.7C62.5 17.2 66.2 17.5 69 19.5C71.8 21.5 73.2 25 72.7 28.4C75.7 29.8 77.8 32.8 77.8 36.2C77.8 38.6 76.8 40.8 75 42.4C77.7 44.8 78.6 48.4 77.3 51.8C76.1 55.2 72.9 57.4 69.3 57.4C68.9 57.4 68.5 57.3 68.1 57.2C66.6 60.8 63.1 63.2 59 63.2C57.2 63.2 55.5 62.6 54.1 61.6C52.2 63.5 49.4 64.5 46.6 64.2C41.7 63.7 37.9 59.8 37.6 54.9C34.6 54.3 32.2 52.1 31.2 49.2C30.1 45.9 31.1 42.3 33.8 40C32.2 38.4 31.4 36.1 31.7 33.7C32.2 30.2 34.6 27.4 38 26.3C38 22.8 40.2 19.6 43.5 18.3C45.5 15.4 47.7 14 50 14Z" fill="currentColor"/>
                                <circle cx="50" cy="9" r="2.2" fill="currentColor"/>
                                <circle cx="44" cy="11" r="1.8" fill="currentColor"/>
                                <circle cx="56" cy="11" r="1.8" fill="currentColor"/>
                                <path d="M48.5 28C48.5 25.2 50.8 23 53.6 23C54.8 23 55.9 23.4 56.7 24.2L58.9 24.6C59.5 24.6 59.8 25.2 59.4 25.6L57.4 27.2C57.6 27.8 57.8 28.4 57.8 29C57.8 31.8 55.4 33.8 53.4 35.8C51.4 37.8 50.2 40.2 50.2 43.4C50.2 47.4 53.4 50.6 57.4 50.6C59 50.6 60.2 50.2 61.4 49.4C59.8 51.8 56.6 53.4 53 53.4C47 53.4 42.2 48.6 42.2 42.6C42.2 37.4 45 33.4 47.4 30.6C48.2 29.8 48.5 29 48.5 28Z" fill="#ffffff"/>
                            </svg>
                        <?php endif; ?>
                        <div class="inv-brand-title" style="color: <?= $theme ?>;"><?= e($invItem['storeDisplayName']) ?></div>
                    </div>

                    <!-- Meta Column -->
                    <div class="inv-meta-col">
                        <h1 class="inv-main-heading" style="color: <?= $theme ?>;">INVOICE</h1>
                        <div class="inv-thank-you" style="color: <?= $theme ?>;">
                            <span>THANK YOU FOR SHOPPING WITH US</span>
                            <span style="color: <?= $theme ?>;">♥</span>
                        </div>
                        <div class="inv-meta-list">
                            <div class="inv-row-kv"><span class="lbl">Order No</span><span class="sep">:</span><span class="val"><?= e($invItem['displayOrderNo']) ?></span></div>
                            <div class="inv-row-kv"><span class="lbl">Order Date</span><span class="sep">:</span><span class="val"><?= e($invItem['invDateStr']) ?></span></div>
                            <div class="inv-row-kv"><span class="lbl">Invoice No</span><span class="sep">:</span><span class="val"><?= e($invItem['displayInvoiceNo']) ?></span></div>
                            <div class="inv-row-kv"><span class="lbl">Payment Mode</span><span class="sep">:</span><span class="val"><?= e($invItem['paymentModeStr']) ?></span></div>
                            <?php if ($invItem['sellerGstin'] !== ''): ?>
                                <div class="inv-row-kv"><span class="lbl">GSTIN</span><span class="sep">:</span><span class="val"><?= e($invItem['sellerGstin']) ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Customer Column -->
                    <div class="inv-cust-col" style="border-left: 1.5px solid <?= $theme ?>;">
                        <h3 class="inv-cust-heading" style="color: <?= $theme ?>;">Customer Details</h3>
                        <div class="inv-cust-list">
                            <div class="inv-row-kv"><span class="lbl">Name</span><span class="sep">:</span><span class="val"><?= e($invItem['custName']) ?></span></div>
                            <div class="inv-row-kv"><span class="lbl">Phone</span><span class="sep">:</span><span class="val"><?= e($invItem['custPhone'] ?: 'N/A') ?></span></div>
                            <div class="inv-row-kv"><span class="lbl">Address</span><span class="sep">:</span><span class="val"><?= e($invItem['custAddress']) ?></span></div>
                            <div class="inv-row-kv"><span class="lbl">Pin Code</span><span class="sep">:</span><span class="val"><?= e($invItem['custPincode']) ?></span></div>
                        </div>
                    </div>
                </div>

                <!-- PRODUCTS TABLE -->
                <div class="inv-table-wrap" style="border: 1.5px solid <?= $theme ?>;">
                    <table class="inv-products-table">
                        <thead style="background: <?= $theme ?>;">
                            <tr>
                                <th style="width: 38px;">No.</th>
                                <th style="text-align: left; padding-left: 12px;">Product Name</th>
                                <th style="width: 72px;">HSN</th>
                                <th style="width: 62px;">Size</th>
                                <th style="width: 72px;">Colour</th>
                                <th style="width: 44px;">Qty</th>
                                <th style="width: 80px; text-align: right; padding-right: 12px;">Price</th>
                                <th style="width: 88px; text-align: right; padding-right: 14px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $itNum = 1;
                            foreach ($invItem['items'] as $it): 
                                $attrs = batch_extract_item_attributes($it, $db);
                                $hsn = strtoupper(trim((string)($it['hsn_code'] ?? '-')));
                                if ($hsn === '') $hsn = '-';
                                $qty = max(1, (int)($it['quantity'] ?? 1));
                                $unitPrice = (float)($it['unit_price'] ?? 0);
                                $lineTotal = (float)($it['line_total'] ?? 0);
                            ?>
                            <tr style="border-bottom: 1px solid rgba(<?= $themeRgb ?>, 0.15);">
                                <td><?= $itNum++ ?></td>
                                <td style="text-align: left; padding-left: 12px;">
                                    <div class="inv-item-name"><?= e($it['product_name'] ?? 'Item') ?></div>
                                </td>
                                <td><?= e($hsn) ?></td>
                                <td><?= e($attrs['size']) ?></td>
                                <td><?= e($attrs['colour']) ?></td>
                                <td><?= $qty ?></td>
                                <td style="text-align: right; padding-right: 12px;">₹<?= number_format($unitPrice, 2) ?></td>
                                <td style="text-align: right; padding-right: 14px; font-weight: 750;">₹<?= number_format($lineTotal, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- FINANCIAL SUMMARY BOX -->
                <div class="inv-summary-container">
                    <div class="inv-summary-box" style="background: rgba(<?= $themeRgb ?>, 0.08); border: 1.5px solid <?= $theme ?>;">
                        <div class="inv-summary-row"><span class="s-label">Total Amount</span><span class="s-sep">:</span><span class="s-val">₹<?= number_format($invItem['subtotal'], 2) ?></span></div>
                        <?php if ($invItem['discountAmount'] > 0): ?>
                            <div class="inv-summary-row"><span class="s-label" style="color: #b91c1c;">Discount</span><span class="s-sep">:</span><span class="s-val" style="color: #b91c1c;">- ₹<?= number_format($invItem['discountAmount'], 2) ?></span></div>
                        <?php endif; ?>
                        <?php if ($invItem['shippingFee'] > 0): ?>
                            <div class="inv-summary-row"><span class="s-label">Shipping</span><span class="s-sep">:</span><span class="s-val">₹<?= number_format($invItem['shippingFee'], 2) ?></span></div>
                        <?php endif; ?>
                        <?php if ($invItem['taxAmount'] > 0): ?>
                            <div class="inv-summary-row"><span class="s-label">Tax / GST</span><span class="s-sep">:</span><span class="s-val">₹<?= number_format($invItem['taxAmount'], 2) ?></span></div>
                        <?php endif; ?>
                        <div class="inv-summary-row grand-total-row" style="color: <?= $theme ?>;">
                            <span class="s-label">Grand Total</span><span class="s-sep">:</span><span class="s-val">₹<?= number_format($invItem['grandTotal'], 2) ?></span>
                        </div>
                    </div>
                </div>

                <!-- FOOTER: Barcode | Packed with love | WhatsApp & QR -->
                <div class="inv-footer-grid" style="border-top-color: <?= $theme ?>;">
                    <!-- Barcode -->
                    <div class="inv-footer-barcode">
                        <div class="inv-barcode-title" style="color: <?= $theme ?>;">Order Tracking Barcode</div>
                        <div class="inv-barcode-svg-wrap"><?= $invItem['barcodeSvg'] ?></div>
                    </div>

                    <!-- Packed with love -->
                    <div class="inv-footer-love" style="background: rgba(<?= $themeRgb ?>, 0.08); border: 1.5px solid <?= $theme ?>;">
                        <div class="inv-love-script" style="color: <?= $theme ?>;">Packed with love</div>
                        <div class="inv-love-heart" style="color: <?= $theme ?>;">♥</div>
                        <div class="inv-love-brand" style="color: <?= $theme ?>;"><?= e($invItem['storeDisplayName']) ?></div>
                    </div>

                    <!-- Help & QR Code -->
                    <div class="inv-footer-help">
                        <div class="inv-qr-box" id="<?= $qrTargetId ?>" data-verify-url="<?= e($invItem['verifyUrl']) ?>" data-theme="<?= e($theme) ?>"></div>
                        <div class="inv-help-text">
                            <div class="inv-help-title" style="color: <?= $theme ?>;">Need Help or Query?</div>
                            <div>Scan QR code or WhatsApp us:</div>
                            <div class="inv-help-phone" style="color: <?= $theme ?>;"><?= e($invItem['formattedWhatsAppPhone']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
        function renderAllQrCodes() {
            document.querySelectorAll('.inv-qr-box').forEach(box => {
                const url = box.getAttribute('data-verify-url');
                const theme = box.getAttribute('data-theme') || '#0d5c52';
                if (url && typeof QRCode !== 'undefined') {
                    box.innerHTML = '';
                    new QRCode(box, {
                        text: url,
                        width: 60,
                        height: 60,
                        colorDark: theme,
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.M
                    });
                }
            });
        }

        function switchBatchSize(size) {
            const url = new URL(window.location.href);
            if (size === '4x3') {
                url.searchParams.set('size', '4x3');
            } else {
                url.searchParams.delete('size');
            }
            window.location.href = url.toString();
        }

        document.addEventListener('DOMContentLoaded', function () {
            renderAllQrCodes();

            <?php if ($autoPrint): ?>
                setTimeout(() => {
                    window.print();
                }, 400);
            <?php endif; ?>
        });
    </script>
</body>
</html>

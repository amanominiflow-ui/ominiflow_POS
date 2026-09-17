<?php

declare(strict_types=1);

require_once __DIR__ . '/barcode_helper.php';

/**
 * WhatsApp / download PDFs must match invoice-view.php, not the old plain-text PDF.
 *
 * @return array<string, mixed>
 */
function prepare_branded_invoice_view(array $invoice, int $businessId, string $kind = 'order'): array {
    $store = $invoice['store'] ?? [];
    if (!is_array($store) || $store === []) {
        $store = function_exists('get_store_settings') ? get_store_settings($businessId) : [];
    }
    $brand = [];
    if (!function_exists('get_mobile_store_settings')) {
        $sf = __DIR__ . '/storefront_db.php';
        if (is_file($sf)) {
            require_once $sf;
        }
    }
    if (function_exists('get_mobile_store_settings')) {
        $brand = get_mobile_store_settings($businessId);
    }

    $theme = trim((string) ($store['primary_color'] ?? ''));
    if ($theme === '') {
        $theme = trim((string) ($brand['header_color'] ?? ''));
    }
    if (!preg_match('/^#([a-f0-9]{3}){1,2}$/i', $theme)) {
        $theme = '#0d5c52';
    }
    $rgb = invoice_branded_hex_rgb($theme);

    $storeName = trim((string) ($store['store_name'] ?? ''));
    if ($storeName === '') {
        $storeName = trim((string) ($brand['display_name'] ?? ''));
    }
    if ($storeName === '') {
        $storeName = trim((string) ($invoice['store']['business_name'] ?? APP_NAME));
    }

    $logoRel = invoice_branded_logo_relative($brand, $store);
    $logoFull = '';
    if ($logoRel !== '') {
        if (preg_match('#^(https?:)?//#i', $logoRel) || str_starts_with($logoRel, 'data:')) {
            $logoFull = $logoRel;
        } else {
            $try = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $logoRel), '/');
            if (is_file($try)) {
                $logoFull = $try;
            }
        }
    }

    $rawOrderNum = trim((string) ($invoice['order_number'] ?? ''));
    if ($rawOrderNum === '') {
        $rawOrderNum = 'AC' . str_pad((string) ($invoice['order_id'] ?? $invoice['id'] ?? 0), 8, '0', STR_PAD_LEFT);
    }
    $displayOrderNo = str_starts_with($rawOrderNum, '#') ? $rawOrderNum : ('#' . $rawOrderNum);

    $rawInvNum = trim((string) ($invoice['invoice_number'] ?? ''));
    if ($rawInvNum === '') {
        $rawInvNum = 'INV-' . (int) ($invoice['id'] ?? 0);
    }
    $displayInvoiceNo = is_numeric($rawInvNum) ? ('IN' . str_pad($rawInvNum, 8, '0', STR_PAD_LEFT)) : $rawInvNum;

    $invoiceDateTimestamp = strtotime((string) ($invoice['invoice_date'] ?? $invoice['created_at'] ?? 'now')) ?: time();
    $invoiceDateStr = date('d-m-Y', $invoiceDateTimestamp);

    $rawPayMethod = strtolower(trim((string) ($invoice['payment_method'] ?? $invoice['payment_mode'] ?? 'cash')));
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
        'store_credit' => 'Store Credit',
    ];
    if (str_contains($rawPayMethod, 'cash on delivery') || $rawPayMethod === 'cod') {
        $paymentModeStr = 'Cash on Delivery (COD)';
    } else {
        $paymentModeStr = $paymentModeMap[$rawPayMethod] ?? ucwords(str_replace('_', ' ', $rawPayMethod));
    }

    $custName = trim((string) ($invoice['customer_name'] ?? ''));
    if ($custName === '') {
        $custName = 'Walk-in Customer';
    }
    $custPhone = trim((string) ($invoice['customer_phone'] ?? ''));
    $custAddress = trim((string) ($invoice['customer_address'] ?? ''));
    if ($custAddress === '' || $custAddress === 'In-Store Counter') {
        $custAddress = trim((string) (($store['address'] ?? '') . ', ' . ($store['city'] ?? '')));
        if ($custAddress === ',' || $custAddress === '') {
            $custAddress = 'Walk-in Store Customer';
        }
    }
    $custPincode = trim((string) ($invoice['customer_pincode'] ?? ''));
    if ($custPincode === '' && preg_match('/\b([1-9][0-9]{5})\b/', $custAddress, $mPin)) {
        $custPincode = $mPin[1];
    }
    if ($custPincode === '') {
        $custPincode = trim((string) ($store['pincode'] ?? ''));
    }

    $items = [];
    $db = function_exists('get_db') ? get_db() : null;
    $rawItems = $invoice['items'] ?? [];
    if (!is_array($rawItems)) {
        $rawItems = [];
    }
    foreach ($rawItems as $it) {
        if (!is_array($it)) {
            continue;
        }
        $attrs = invoice_branded_item_attributes($it, $db);
        $items[] = [
            'name' => trim((string) ($it['product_name'] ?? $it['name'] ?? 'Item')),
            'size' => $attrs['size'],
            'colour' => $attrs['colour'],
            'qty' => max(1, (int) ($it['quantity'] ?? 1)),
            'price' => (float) ($it['unit_price'] ?? $it['price'] ?? 0),
            'total' => (float) ($it['line_total'] ?? 0),
        ];
    }

    $subtotal = (float) ($invoice['subtotal'] ?? 0);
    $discountAmount = (float) ($invoice['discount_amount'] ?? 0);
    $shippingFee = (float) ($invoice['shipping_fee'] ?? 0);
    $grandTotal = (float) ($invoice['total_amount'] ?? $invoice['grand_total'] ?? 0);
    if ($subtotal <= 0 && $items !== []) {
        foreach ($items as $it) {
            $subtotal += (float) $it['total'];
        }
    }
    if ($grandTotal <= 0) {
        $grandTotal = $subtotal - $discountAmount + $shippingFee;
    }

    $carePhone = trim((string) ($brand['customer_care_phone'] ?? ''));
    $waPhone = trim((string) ($brand['contact_whatsapp'] ?? ''));
    $storePhone = trim((string) ($store['phone'] ?? $brand['phone'] ?? ''));
    $rawWhatsApp = '';
    $dummy = ['+91 98765 43210', '9876543210', '919876543210'];
    if ($carePhone !== '' && !in_array($carePhone, $dummy, true)) {
        $rawWhatsApp = $carePhone;
    } elseif ($waPhone !== '' && !in_array($waPhone, $dummy, true)) {
        $rawWhatsApp = $waPhone;
    } elseif ($storePhone !== '') {
        $rawWhatsApp = $storePhone;
    } elseif ($carePhone !== '') {
        $rawWhatsApp = $carePhone;
    } else {
        $rawWhatsApp = $waPhone;
    }
    $helpPhone = invoice_branded_format_phone($rawWhatsApp);

    $invoiceId = (int) ($invoice['id'] ?? 0);
    if ($kind === 'offline') {
        $verifyPath = 'offline-billing.php?id=' . $invoiceId . '&standalone=1';
    } else {
        $verifyPath = 'invoice-view.php?id=' . $invoiceId . '&standalone=1';
    }
    if (function_exists('pos_public_url')) {
        $verifyUrl = pos_public_url($verifyPath);
    } else {
        $verifyUrl = rtrim((string) APP_URL, '/') . '/' . $verifyPath;
    }

    $cancelled = strtolower((string) ($invoice['invoice_status'] ?? '')) === 'cancelled';

    return [
        'theme' => $theme,
        'theme_rgb' => $rgb,
        'store_name' => $storeName,
        'logo_path' => $logoFull,
        'order_no' => $displayOrderNo,
        'order_raw' => $rawOrderNum,
        'order_date' => $invoiceDateStr,
        'invoice_no' => $displayInvoiceNo,
        'payment_mode' => $paymentModeStr,
        'customer_name' => $custName,
        'customer_phone' => $custPhone,
        'customer_address' => $custAddress,
        'customer_pincode' => $custPincode,
        'items' => $items,
        'subtotal' => $subtotal,
        'discount' => $discountAmount,
        'shipping' => $shippingFee,
        'grand_total' => $grandTotal,
        'help_phone' => $helpPhone,
        'verify_url' => $verifyUrl,
        'cancelled' => $cancelled,
        'kind' => $kind,
    ];
}

function build_branded_invoice_pdf(array $invoice, int $businessId, string $kind = 'order'): ?string {
    try {
        $view = prepare_branded_invoice_view($invoice, $businessId, $kind);
        $fromGd = invoice_branded_pdf_via_gd($view);
        if (is_string($fromGd) && strlen($fromGd) > 2000 && str_starts_with($fromGd, '%PDF')) {
            return $fromGd;
        }
        $fromChrome = invoice_branded_pdf_via_chrome($view);
        if (is_string($fromChrome) && strlen($fromChrome) > 2000 && str_starts_with($fromChrome, '%PDF')) {
            return $fromChrome;
        }
    } catch (Throwable $e) {
        error_log('Branded invoice PDF: ' . $e->getMessage());
    }
    return null;
}

function invoice_branded_upper(string $text): string {
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($text, 'UTF-8');
    }
    return strtoupper($text);
}

function invoice_branded_hex_rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[a-f0-9]{6}$/i', $hex)) {
        return [13, 92, 82];
    }
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function invoice_branded_money(float $num): string {
    if (fmod($num, 1.0) == 0.0) {
        return number_format($num, 0, '.', '');
    }
    return number_format($num, 2, '.', '');
}

function invoice_branded_format_phone(string $phone): string {
    $phone = trim($phone);
    if ($phone === '') {
        return '';
    }
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
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

function invoice_branded_logo_relative(array $brand, array $store): string {
    $blocked = [
        'assets/images/logo.jpg',
        'assets/images/logo-sm.jpg',
        'assets/images/logo-icon.png',
        'assets/images/logo.png',
        'assets/images/favicon.ico',
        'assets/images/apple-touch-icon.png',
    ];
    foreach ([$brand['logo_path'] ?? null, $store['logo_path'] ?? null] as $l) {
        if (!is_string($l) || trim($l) === '') {
            continue;
        }
        $lTrim = trim(str_replace('\\', '/', $l));
        $lLtrim = ltrim($lTrim, '/');
        if (in_array($lLtrim, $blocked, true)) {
            continue;
        }
        return $lLtrim;
    }
    return '';
}

/**
 * @return array{size: string, colour: string}
 */
function invoice_branded_item_attributes(array $it, $db = null): array {
    $size = trim((string) ($it['size'] ?? ''));
    $colour = trim((string) ($it['colour'] ?? $it['color'] ?? ''));
    if ($size === '') {
        $size = '-';
    }
    if ($colour === '') {
        $colour = '-';
    }
    $pName = trim((string) ($it['product_name'] ?? $it['name'] ?? ''));
    $pSku = trim((string) ($it['product_sku'] ?? ''));
    $variantId = (int) ($it['variant_id'] ?? 0);

    if ($db instanceof PDO && $variantId > 0 && ($size === '-' || $colour === '-')) {
        try {
            $stV = $db->prepare('SELECT variant_name, attribute_values FROM product_variants WHERE id = :vid LIMIT 1');
            $stV->execute(['vid' => $variantId]);
            $vRow = $stV->fetch();
            if (is_array($vRow)) {
                $av = json_decode((string) $vRow['attribute_values'], true);
                if (is_array($av)) {
                    foreach ($av as $k => $v) {
                        $kLow = strtolower((string) $k);
                        if ($size === '-' && in_array($kLow, ['size', 'sizes', 'size / fits'], true)) {
                            $size = trim((string) $v);
                        }
                        if ($colour === '-' && in_array($kLow, ['color', 'colour', 'shade'], true)) {
                            $colour = trim((string) $v);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // keep parsed fallbacks
        }
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

    return ['size' => $size !== '' ? $size : '-', 'colour' => $colour !== '' ? $colour : '-'];
}

function invoice_branded_http_get(string $url, int $timeout = 5): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'OminiFlowInvoice/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $body !== '' && $code >= 200 && $code < 300) {
            return $body;
        }
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'header' => "User-Agent: OminiFlowInvoice/1.0\r\n"],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) && $body !== '' ? $body : null;
}

function invoice_branded_qr_png(string $data): ?string {
    $data = trim($data);
    if ($data === '') {
        return null;
    }
    $urls = [
        'https://api.qrserver.com/v1/create-qr-code/?size=180x180&ecc=M&margin=0&color=0d5c52&data=' . rawurlencode($data),
        'https://quickchart.io/qr?format=png&size=180&margin=0&text=' . rawurlencode($data),
    ];
    foreach ($urls as $url) {
        $png = invoice_branded_http_get($url, 5);
        if (is_string($png) && strlen($png) > 80 && strncmp($png, "\x89PNG", 4) === 0) {
            return $png;
        }
        if (is_string($png) && strlen($png) > 80 && strncmp($png, "\xFF\xD8", 2) === 0) {
            return $png;
        }
    }
    return null;
}

function invoice_branded_file_data_uri(string $path): string {
    if (str_starts_with($path, 'data:')) {
        return $path;
    }
    if (preg_match('#^https?://#i', $path)) {
        $bin = invoice_branded_http_get($path, 5);
        if (!is_string($bin) || $bin === '') {
            return '';
        }
        $mime = 'image/jpeg';
        if (strncmp($bin, "\x89PNG", 4) === 0) {
            $mime = 'image/png';
        }
        return 'data:' . $mime . ';base64,' . base64_encode($bin);
    }
    if (!is_file($path)) {
        return '';
    }
    $mime = 'image/jpeg';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($path);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }
    $bin = @file_get_contents($path);
    if (!is_string($bin) || $bin === '') {
        return '';
    }
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

function invoice_branded_build_html(array $view): string {
    $theme = htmlspecialchars((string) $view['theme'], ENT_QUOTES, 'UTF-8');
    $rgb = $view['theme_rgb'];
    $tint = 'rgba(' . (int) $rgb[0] . ',' . (int) $rgb[1] . ',' . (int) $rgb[2] . ',0.08)';
    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $logoUri = '';
    if (!empty($view['logo_path'])) {
        $logoUri = invoice_branded_file_data_uri((string) $view['logo_path']);
    }
    $qrPng = invoice_branded_qr_png((string) $view['verify_url']);
    $qrUri = $qrPng ? ('data:image/png;base64,' . base64_encode($qrPng)) : '';
    $barcodeSvg = generate_code128_svg((string) $view['order_raw'], 36, 1.4, (string) $view['theme']);

    $rows = '';
    $idx = 1;
    foreach ($view['items'] as $it) {
        $rows .= '<tr>'
            . '<td>' . $idx++ . '</td>'
            . '<td class="col-name">' . $e($it['name']) . '</td>'
            . '<td>' . $e($it['size']) . '</td>'
            . '<td>' . $e($it['colour']) . '</td>'
            . '<td>' . (int) $it['qty'] . '</td>'
            . '<td class="col-price">' . $e(invoice_branded_money((float) $it['price'])) . '</td>'
            . '<td class="col-total">' . $e(invoice_branded_money((float) $it['total'])) . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="7" style="padding:24px;text-align:center;color:#64748b;">No items listed.</td></tr>';
    }

    $logoHtml = $logoUri !== ''
        ? '<img src="' . $e($logoUri) . '" alt="" class="inv-brand-img">'
        : '<div class="inv-logo-fallback">R</div>';
    $qrHtml = $qrUri !== ''
        ? '<img src="' . $e($qrUri) . '" alt="QR">'
        : '';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Invoice</title>
<style>
@page { size: A4; margin: 10mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #ffffff; color: #1e293b; font-family: "Segoe UI", "Plus Jakarta Sans", Arial, sans-serif; }
.inv-card { border: 2.5px solid ' . $theme . '; border-radius: 18px; padding: 28px 32px; }
.inv-top { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
.inv-top td { vertical-align: top; }
.brand { width: 200px; text-align: center; }
.inv-brand-img { max-height: 98px; max-width: 180px; }
.inv-logo-fallback { width: 86px; height: 86px; margin: 0 auto 8px; border-radius: 12px; background: ' . $theme . '; color: #fff; font-size: 36px; font-weight: 800; line-height: 86px; }
.inv-brand-title { font-size: 13px; font-weight: 800; color: ' . $theme . '; letter-spacing: 0.12em; text-transform: uppercase; margin-top: 8px; }
.meta { padding: 0 16px; }
.cust { padding-left: 18px; border-left: 1.5px solid ' . $theme . '; }
h1 { font-size: 28px; color: ' . $theme . '; letter-spacing: 0.04em; margin-bottom: 4px; }
.thanks { font-size: 10px; font-weight: 700; color: ' . $theme . '; margin-bottom: 12px; letter-spacing: 0.03em; }
.kv { font-size: 12.5px; font-weight: 600; margin: 3px 0; }
.kv .lbl { display: inline-block; width: 96px; }
.cust h3 { font-size: 15px; color: ' . $theme . '; margin-bottom: 10px; }
.cust .lbl { width: 72px; }
table.products { width: 100%; border-collapse: collapse; border: 1.5px solid ' . $theme . '; margin-bottom: 18px; }
table.products th { background: ' . $theme . '; color: #fff; font-size: 12px; padding: 9px 8px; border-right: 1px solid rgba(255,255,255,.25); }
table.products td { border: 1.5px solid ' . $theme . '; padding: 9px 8px; font-size: 12px; text-align: center; }
table.products td.col-name, table.products th.col-name { text-align: left; padding-left: 12px; }
table.products td.col-price, table.products td.col-total { text-align: right; padding-right: 12px; }
.summary-wrap { text-align: right; margin-bottom: 18px; }
.summary { display: inline-block; width: 280px; background: ' . $tint . '; border-radius: 10px; padding: 12px 16px; text-align: left; }
.srow { font-size: 13px; font-weight: 600; margin: 4px 0; }
.srow span.val { float: right; }
.grand { color: ' . $theme . '; font-size: 15px; font-weight: 800; border-top: 1.5px solid ' . $theme . '; padding-top: 6px; margin-top: 6px; }
.footer { width: 100%; border-collapse: collapse; }
.footer td { vertical-align: middle; }
.love { text-align: center; border-left: 1.5px solid ' . $theme . '; border-right: 1.5px solid ' . $theme . '; color: ' . $theme . '; }
.love .script { font-family: "Segoe Script", "Caveat", cursive; font-size: 22px; font-weight: 700; }
.love .brand { font-size: 11px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; }
.help { text-align: right; }
.help img { width: 68px; height: 68px; vertical-align: middle; margin-right: 8px; }
.help-text { display: inline-block; text-align: left; font-size: 11.5px; vertical-align: middle; }
.barcode-title { font-size: 11px; font-weight: 700; margin-bottom: 4px; }
.barcode svg { height: 46px; max-width: 210px; }
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
</style></head><body>
<div class="inv-card">
<table class="inv-top"><tr>
<td class="brand">' . $logoHtml . '<div class="inv-brand-title">' . $e($view['store_name']) . '</div></td>
<td class="meta">
  <h1>INVOICE</h1>
  <div class="thanks">THANK YOU FOR SHOPPING WITH US ♥</div>
  <div class="kv"><span class="lbl">Order No</span><span> : ' . $e($view['order_no']) . '</span></div>
  <div class="kv"><span class="lbl">Order Date</span><span> : ' . $e($view['order_date']) . '</span></div>
  <div class="kv"><span class="lbl">Invoice No</span><span> : ' . $e($view['invoice_no']) . '</span></div>
  <div class="kv"><span class="lbl">Payment Mode</span><span> : ' . $e($view['payment_mode']) . '</span></div>
</td>
<td class="cust">
  <h3>Customer Details</h3>
  <div class="kv"><span class="lbl">Name</span><span> : ' . $e($view['customer_name']) . '</span></div>
  <div class="kv"><span class="lbl">Phone</span><span> : ' . $e($view['customer_phone'] !== '' ? $view['customer_phone'] : 'N/A') . '</span></div>
  <div class="kv"><span class="lbl">Address</span><span> : ' . $e($view['customer_address']) . '</span></div>
  <div class="kv"><span class="lbl">Pin Code</span><span> : ' . $e($view['customer_pincode']) . '</span></div>
</td>
</tr></table>
<table class="products">
<thead><tr><th>No.</th><th class="col-name">Product Name</th><th>Size</th><th>Colour</th><th>Qty</th><th>Price (₹)</th><th>Total (₹)</th></tr></thead>
<tbody>' . $rows . '</tbody>
</table>
<div class="summary-wrap"><div class="summary">
  <div class="srow">Total Amount<span class="val">₹ ' . $e(invoice_branded_money((float) $view['subtotal'])) . '</span></div>
  <div class="srow">Discount<span class="val">₹ ' . $e(invoice_branded_money((float) $view['discount'])) . '</span></div>
  <div class="srow">Shipping<span class="val">₹ ' . $e(invoice_branded_money((float) $view['shipping'])) . '</span></div>
  <div class="srow grand">Grand Total<span class="val">₹ ' . $e(invoice_branded_money((float) $view['grand_total'])) . '</span></div>
</div></div>
<table class="footer"><tr>
<td width="34%"><div class="barcode-title">Order No: ' . $e($view['order_no']) . '</div><div class="barcode">' . $barcodeSvg . '</div></td>
<td width="32%" class="love"><div class="script">Packed with love</div><div>♥</div><div class="brand">' . $e($view['store_name']) . '</div></td>
<td width="34%" class="help">' . $qrHtml . '<div class="help-text"><div style="font-weight:700">Need help?</div><div>WhatsApp us at</div><div style="font-weight:700">' . $e($view['help_phone']) . '</div></div></td>
</tr></table>
</div></body></html>';
}

function invoice_find_chrome_binary(): ?string {
    $env = getenv('CHROME_BIN') ?: getenv('CHROME_PATH') ?: getenv('EDGE_PATH') ?: '';
    if (is_string($env) && $env !== '' && is_file($env)) {
        return $env;
    }
    $candidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
        '/usr/bin/microsoft-edge',
        '/snap/bin/chromium',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return null;
}

function invoice_path_to_file_url(string $path): string {
    $real = str_replace('\\', '/', realpath($path) ?: $path);
    if (preg_match('/^[A-Za-z]:/', $real)) {
        return 'file:///' . $real;
    }
    return 'file://' . $real;
}

function invoice_branded_pdf_via_chrome(array $view): ?string {
    $bin = invoice_find_chrome_binary();
    if ($bin === null || !function_exists('proc_open')) {
        return null;
    }
    $tmp = sys_get_temp_dir() . '/of-inv-' . bin2hex(random_bytes(6));
    if (!@mkdir($tmp, 0700) && !is_dir($tmp)) {
        return null;
    }
    $htmlPath = $tmp . '/invoice.html';
    $pdfPath = $tmp . '/invoice.pdf';
    $profile = $tmp . '/profile';
    @mkdir($profile, 0700);
    @file_put_contents($htmlPath, invoice_branded_build_html($view));
    if (!is_file($htmlPath)) {
        invoice_branded_rrmdir($tmp);
        return null;
    }

    $argSets = [
        [
            '--headless=new', '--disable-gpu', '--no-sandbox', '--hide-scrollbars', '--no-first-run',
            '--no-pdf-header-footer', '--disable-extensions', '--virtual-time-budget=4000',
            '--user-data-dir=' . $profile,
            '--print-to-pdf=' . $pdfPath,
            invoice_path_to_file_url($htmlPath),
        ],
    ];

    $bytes = null;
    foreach ($argSets as $args) {
        @unlink($pdfPath);
        $cmd = array_merge([$bin], $args);
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, $tmp, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            continue;
        }
        $start = time();
        while (true) {
            $st = proc_get_status($proc);
            if (empty($st['running'])) {
                break;
            }
            if ((time() - $start) > 8) {
                proc_terminate($proc);
                break;
            }
            usleep(150000);
        }
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($proc);
        if (is_file($pdfPath) && filesize($pdfPath) > 2000) {
            $got = file_get_contents($pdfPath);
            if (is_string($got) && str_starts_with($got, '%PDF')) {
                $bytes = $got;
                break;
            }
        }
    }

    invoice_branded_rrmdir($tmp);
    return $bytes;
}

function invoice_branded_rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            invoice_branded_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function invoice_branded_ttf(string $style = 'regular'): string {
    static $cache = [];
    if (isset($cache[$style])) {
        return $cache[$style];
    }
    $map = [
        'regular' => [
            'C:\\Windows\\Fonts\\segoeui.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ],
        'bold' => [
            'C:\\Windows\\Fonts\\segoeuib.ttf',
            'C:\\Windows\\Fonts\\arialbd.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ],
        'script' => [
            'C:\\Windows\\Fonts\\segoesc.ttf',
            'C:\\Windows\\Fonts\\segoeuiz.ttf',
            'C:\\Windows\\Fonts\\ariali.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Oblique.ttf',
        ],
    ];
    $found = '';
    foreach ($map[$style] ?? $map['regular'] as $font) {
        if (is_file($font)) {
            $found = $font;
            break;
        }
    }
    if ($found === '' && $style !== 'regular') {
        $found = invoice_branded_ttf('regular');
    }
    $cache[$style] = $found;
    return $found;
}

function invoice_gd_text_width(string $font, float $size, string $text): int {
    if ($font === '' || $text === '') {
        return (int) (strlen($text) * $size * 0.5);
    }
    $bb = @imagettfbbox($size, 0, $font, $text);
    if (!is_array($bb)) {
        return (int) (strlen($text) * $size * 0.5);
    }
    return (int) abs($bb[2] - $bb[0]);
}

function invoice_gd_wrap(string $font, float $size, string $text, int $maxW): array {
    $text = trim($text);
    if ($text === '') {
        return [''];
    }
    $words = preg_split('/\s+/', $text) ?: [$text];
    $lines = [];
    $cur = '';
    foreach ($words as $word) {
        $try = $cur === '' ? $word : ($cur . ' ' . $word);
        if (invoice_gd_text_width($font, $size, $try) <= $maxW || $cur === '') {
            $cur = $try;
        } else {
            $lines[] = $cur;
            $cur = $word;
        }
    }
    if ($cur !== '') {
        $lines[] = $cur;
    }
    return $lines;
}

function invoice_gd_text($im, float $size, int $x, int $y, $color, string $text, string $style = 'regular', string $align = 'left', int $maxW = 0): int {
    $font = invoice_branded_ttf($style);
    if ($maxW > 0 && $font !== '') {
        $text = invoice_gd_wrap($font, $size, $text, $maxW)[0] ?? $text;
    }
    if ($font === '') {
        $drawX = $x;
        if ($align === 'right') {
            $drawX = $x - (int) (strlen($text) * 6);
        }
        imagestring($im, 4, $drawX, $y - 12, $text, $color);
        return 16;
    }
    $w = invoice_gd_text_width($font, $size, $text);
    $drawX = $x;
    if ($align === 'right') {
        $drawX = $x - $w;
    } elseif ($align === 'center') {
        $drawX = $x - (int) ($w / 2);
    }
    imagettftext($im, $size, 0, $drawX, $y, $color, $font, $text);
    return (int) round($size + 8);
}

function invoice_gd_round_fill($im, int $x1, int $y1, int $x2, int $y2, int $r, $color): void {
    $r = max(1, min($r, (int) (($x2 - $x1) / 2), (int) (($y2 - $y1) / 2)));
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

function invoice_gd_load_image(string $path) {
    if (str_starts_with($path, 'data:')) {
        $raw = substr($path, (int) strpos($path, ',') + 1);
        $bin = base64_decode($raw, true);
        return is_string($bin) ? @imagecreatefromstring($bin) : false;
    }
    if (preg_match('#^https?://#i', $path)) {
        $bin = invoice_branded_http_get($path, 5);
        return is_string($bin) ? @imagecreatefromstring($bin) : false;
    }
    if (!is_file($path)) {
        return false;
    }
    $bin = @file_get_contents($path);
    return is_string($bin) ? @imagecreatefromstring($bin) : false;
}

function invoice_branded_pdf_via_gd(array $view): ?string {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
        return null;
    }
    $W = 1240;
    $pad = 72;
    $nameColW = 384;
    $nameFont = invoice_branded_ttf('bold');
    if ($nameFont === '') {
        $nameFont = invoice_branded_ttf('regular');
    }
    $items = $view['items'];
    if (!is_array($items) || $items === []) {
        $items = [['name' => 'No items listed', 'size' => '-', 'colour' => '-', 'qty' => 0, 'price' => 0, 'total' => 0]];
    }
    $itemLayouts = [];
    $tableBodyH = 0;
    foreach ($items as $it) {
        $nameLines = invoice_gd_wrap($nameFont, 12, (string) ($it['name'] ?? 'Item'), $nameColW);
        if ($nameLines === []) {
            $nameLines = [(string) ($it['name'] ?? 'Item')];
        }
        $h = max(52, 20 + (count($nameLines) * 18));
        $itemLayouts[] = ['item' => $it, 'nameLines' => $nameLines, 'h' => $h];
        $tableBodyH += $h;
    }
    $H = 72 + 270 + 42 + $tableBodyH + 168 + 118;
    $H = max(820, min(2200, $H));

    $im = imagecreatetruecolor($W, $H);
    if ($im === false) {
        return null;
    }
    imagealphablending($im, true);
    $rgb = $view['theme_rgb'];
    $bg = imagecolorallocate($im, 255, 255, 255);
    $white = imagecolorallocate($im, 255, 255, 255);
    $theme = imagecolorallocate($im, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
    $text = imagecolorallocate($im, 30, 41, 59);
    $muted = imagecolorallocate($im, 71, 85, 105);
    $tint = imagecolorallocatealpha($im, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2], 118);
    $line = $theme;
    imagefilledrectangle($im, 0, 0, $W, $H, $bg);

    $cardX1 = 36;
    $cardY1 = 36;
    $cardX2 = $W - 36;
    $cardY2 = $H - 36;
    invoice_gd_round_fill($im, $cardX1, $cardY1, $cardX2, $cardY2, 28, $theme);
    invoice_gd_round_fill($im, $cardX1 + 5, $cardY1 + 5, $cardX2 - 5, $cardY2 - 5, 24, $white);

    $y = 88;
    $col1 = $pad;
    $col2 = 300;
    $col3 = 760;

    if (!empty($view['logo_path'])) {
        $logo = invoice_gd_load_image((string) $view['logo_path']);
        if ($logo) {
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            $maxW = 200;
            $maxH = 110;
            $scale = min($maxW / max(1, $lw), $maxH / max(1, $lh));
            $dw = (int) round($lw * $scale);
            $dh = (int) round($lh * $scale);
            $dx = $col1 + (int) ((210 - $dw) / 2);
            imagecopyresampled($im, $logo, $dx, $y, 0, 0, $dw, $dh, $lw, $lh);
            imagedestroy($logo);
            $yLogoBottom = $y + $dh + 16;
        } else {
            $yLogoBottom = $y + 90;
        }
    } else {
        invoice_gd_round_fill($im, $col1 + 50, $y, $col1 + 150, $y + 100, 12, $theme);
        invoice_gd_text($im, 36, $col1 + 100, $y + 68, $white, 'R', 'bold', 'center');
        $yLogoBottom = $y + 116;
    }
    $storeLines = invoice_gd_wrap(invoice_branded_ttf('bold'), 13, invoice_branded_upper((string) $view['store_name']), 220);
    $sy = $yLogoBottom;
    foreach ($storeLines as $sl) {
        invoice_gd_text($im, 13, $col1 + 105, $sy, $theme, $sl, 'bold', 'center');
        $sy += 18;
    }

    invoice_gd_text($im, 30, $col2, $y + 28, $theme, 'INVOICE', 'bold');
    invoice_gd_text($im, 10, $col2, $y + 50, $theme, 'THANK YOU FOR SHOPPING WITH US  ♥', 'bold');
    $metaY = $y + 78;
    $meta = [
        ['Order No', (string) $view['order_no']],
        ['Order Date', (string) $view['order_date']],
        ['Invoice No', (string) $view['invoice_no']],
        ['Payment Mode', (string) $view['payment_mode']],
    ];
    foreach ($meta as $row) {
        invoice_gd_text($im, 12, $col2, $metaY, $text, $row[0], 'regular');
        invoice_gd_text($im, 12, $col2 + 108, $metaY, $text, ':', 'regular');
        invoice_gd_text($im, 12, $col2 + 122, $metaY, $text, $row[1], 'bold', 'left', 300);
        $metaY += 22;
    }

    imageline($im, $col3 - 18, $y, $col3 - 18, $y + 168, $line);
    invoice_gd_text($im, 16, $col3, $y + 22, $theme, 'Customer Details', 'bold');
    $custY = $y + 52;
    $cust = [
        ['Name', (string) $view['customer_name']],
        ['Phone', (string) ($view['customer_phone'] !== '' ? $view['customer_phone'] : 'N/A')],
        ['Address', (string) $view['customer_address']],
        ['Pin Code', (string) $view['customer_pincode']],
    ];
    $bold = invoice_branded_ttf('bold');
    $reg = invoice_branded_ttf('regular');
    foreach ($cust as $row) {
        invoice_gd_text($im, 12, $col3, $custY, $text, $row[0], 'regular');
        invoice_gd_text($im, 12, $col3 + 78, $custY, $text, ':', 'regular');
        $lines = invoice_gd_wrap($bold !== '' ? $bold : $reg, 12, $row[1], 340);
        foreach ($lines as $i => $ln) {
            invoice_gd_text($im, 12, $col3 + 92, $custY + ($i * 18), $text, $ln, 'bold');
        }
        $custY += max(22, count($lines) * 18 + 4);
    }

    $tableTop = max($metaY, $custY, $sy) + 28;
    $tableX1 = $pad;
    $tableX2 = $W - $pad;
    $cols = [
        ['No.', 70],
        ['Product Name', 400],
        ['Size', 110],
        ['Colour', 140],
        ['Qty', 80],
        ['Price (₹)', 148],
        ['Total (₹)', 148],
    ];
    $headerH = 42;
    imagefilledrectangle($im, $tableX1, $tableTop, $tableX2, $tableTop + $headerH, $theme);
    $cx = $tableX1;
    foreach ($cols as $i => $col) {
        $tx = $cx + 12;
        $align = 'left';
        $label = $col[0];
        if (in_array($label, ['Price (₹)', 'Total (₹)'], true)) {
            $tx = $cx + $col[1] - 12;
            $align = 'right';
        } elseif ($label !== 'Product Name') {
            $tx = $cx + (int) ($col[1] / 2);
            $align = 'center';
        }
        invoice_gd_text($im, 12, $tx, $tableTop + 28, $white, $label, 'bold', $align);
        if ($i < count($cols) - 1) {
            imageline($im, $cx + $col[1], $tableTop, $cx + $col[1], $tableTop + $headerH, $white);
        }
        $cx += $col[1];
    }

    $ry = $tableTop + $headerH;
    $idx = 1;
    foreach ($itemLayouts as $layout) {
        $it = $layout['item'];
        $thisH = (int) $layout['h'];
        imageline($im, $tableX1, $ry, $tableX2, $ry, $line);
        $vals = [
            (string) $idx++,
            '',
            (string) $it['size'],
            (string) $it['colour'],
            (string) ((int) ($it['qty'] ?? 0) > 0 ? $it['qty'] : '-'),
            invoice_branded_money((float) $it['price']),
            invoice_branded_money((float) $it['total']),
        ];
        $cx = $tableX1;
        $midY = $ry + (int) ($thisH / 2) + 5;
        foreach ($cols as $i => $col) {
            if ($i === 1) {
                $nameY = $ry + 22;
                foreach ($layout['nameLines'] as $ln) {
                    invoice_gd_text($im, 12, $cx + 12, $nameY, $text, $ln, 'bold', 'left', $col[1] - 16);
                    $nameY += 18;
                }
            } else {
                $align = ($i >= 5) ? 'right' : 'center';
                $tx = ($align === 'right') ? ($cx + $col[1] - 12) : ($cx + (int) ($col[1] / 2));
                $style = ($i >= 4) ? 'bold' : 'regular';
                invoice_gd_text($im, 12, $tx, $midY, $text, $vals[$i], $style, $align, $col[1] - 16);
            }
            if ($i < count($cols) - 1) {
                imageline($im, $cx + $col[1], $ry, $cx + $col[1], $ry + $thisH, $line);
            }
            $cx += $col[1];
        }
        $ry += $thisH;
    }
    imagerectangle($im, $tableX1, $tableTop, $tableX2, $ry, $line);

    $sumX1 = $tableX2 - 340;
    $sumY1 = $ry + 22;
    $sumX2 = $tableX2;
    $sumY2 = $sumY1 + 128;
    invoice_gd_round_fill($im, $sumX1, $sumY1, $sumX2, $sumY2, 12, $tint);
    $sumRows = [
        ['Total Amount', '₹ ' . invoice_branded_money((float) $view['subtotal']), false],
        ['Discount', '₹ ' . invoice_branded_money((float) $view['discount']), false],
        ['Shipping', '₹ ' . invoice_branded_money((float) $view['shipping']), false],
        ['Grand Total', '₹ ' . invoice_branded_money((float) $view['grand_total']), true],
    ];
    $sy = $sumY1 + 28;
    foreach ($sumRows as $sr) {
        $colr = $sr[2] ? $theme : $text;
        $style = $sr[2] ? 'bold' : 'regular';
        $sz = $sr[2] ? 15.0 : 13.0;
        if ($sr[2]) {
            imageline($im, $sumX1 + 16, $sy - 16, $sumX2 - 16, $sy - 16, $line);
        }
        invoice_gd_text($im, $sz, $sumX1 + 20, $sy, $colr, $sr[0], $style);
        invoice_gd_text($im, $sz, $sumX2 - 20, $sy, $colr, $sr[1], 'bold', 'right');
        $sy += 26;
    }

    $footY = $sumY2 + 36;
    invoice_gd_text($im, 11, $pad, $footY, $text, 'Order No: ' . (string) $view['order_no'], 'bold');
    $encoded = generate_code128_bar_modules((string) $view['order_raw']);
    $barX = $pad;
    $barY = $footY + 10;
    $moduleW = 1.15;
    $barH = 42;
    foreach ($encoded['modules'] as $mod) {
        $bw = (int) round($mod['w'] * $moduleW);
        if ($mod['bar']) {
            imagefilledrectangle($im, (int) $barX, $barY, (int) ($barX + $bw - 1), $barY + $barH, $theme);
        }
        $barX += $bw;
    }

    $midX = (int) ($W / 2);
    imageline($im, $midX - 150, $footY, $midX - 150, $footY + 78, $line);
    imageline($im, $midX + 150, $footY, $midX + 150, $footY + 78, $line);
    invoice_gd_text($im, 22, $midX, $footY + 28, $theme, 'Packed with love', 'script', 'center');
    invoice_gd_text($im, 12, $midX, $footY + 46, $theme, '♥', 'bold', 'center');
    invoice_gd_text($im, 11, $midX, $footY + 66, $theme, invoice_branded_upper((string) $view['store_name']), 'bold', 'center');

    $qrPng = invoice_branded_qr_png((string) $view['verify_url']);
    $qrX = $W - $pad - 280;
    if (is_string($qrPng)) {
        $qrIm = @imagecreatefromstring($qrPng);
        if ($qrIm) {
            imagecopyresampled($im, $qrIm, $qrX, $footY, 0, 0, 78, 78, imagesx($qrIm), imagesy($qrIm));
            imagedestroy($qrIm);
        }
    }
    invoice_gd_text($im, 12, $qrX + 92, $footY + 22, $text, 'Need help?', 'bold');
    invoice_gd_text($im, 11, $qrX + 92, $footY + 42, $muted, 'WhatsApp us at', 'regular');
    invoice_gd_text($im, 12, $qrX + 92, $footY + 62, $text, (string) $view['help_phone'], 'bold');

    if (!empty($view['cancelled'])) {
        $red = imagecolorallocatealpha($im, 185, 28, 28, 90);
        invoice_gd_text($im, 64, (int) ($W / 2), (int) ($H / 2), $red, 'CANCELLED', 'bold', 'center');
    }

    ob_start();
    imagejpeg($im, null, 90);
    $jpeg = ob_get_clean();
    imagedestroy($im);
    if (!is_string($jpeg) || $jpeg === '') {
        return null;
    }
    return invoice_jpeg_to_pdf($jpeg, $W, $H);
}

function invoice_jpeg_to_pdf(string $jpeg, int $imgW, int $imgH): string {
    $pageW = 595.0;
    $pageH = 842.0;
    $margin = 10.0;
    $maxW = $pageW - (2 * $margin);
    $maxH = $pageH - (2 * $margin);
    $scale = min($maxW / max(1, $imgW), $maxH / max(1, $imgH));
    $drawW = $imgW * $scale;
    $drawH = $imgH * $scale;
    $x = ($pageW - $drawW) / 2;
    $y = ($pageH - $drawH) / 2;
    $len = strlen($jpeg);
    $content = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im0 Do Q\n", $drawW, $drawH, $x, $y);
    $contentLen = strlen($content);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    $offsets[1] = strlen($pdf);
    $pdf .= "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n";
    $offsets[2] = strlen($pdf);
    $pdf .= "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n";
    $offsets[3] = strlen($pdf);
    $pdf .= "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageW} {$pageH}] /Contents 4 0 R /Resources << /XObject << /Im0 5 0 R >> >> >>endobj\n";
    $offsets[4] = strlen($pdf);
    $pdf .= "4 0 obj<< /Length {$contentLen} >>stream\n" . $content . "endstream endobj\n";
    $offsets[5] = strlen($pdf);
    $pdf .= "5 0 obj<< /Type /XObject /Subtype /Image /Width {$imgW} /Height {$imgH} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >>stream\n";
    $pdf .= $jpeg;
    $pdf .= "\nendstream endobj\n";
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
    return $pdf;
}

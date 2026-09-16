<?php
/**
 * OminiFlow POS — Offline Billing (COD Manifest)
 * Isolated from POS, invoices, stock, and India Post label generation.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/storefront_db.php';
require_once __DIR__ . '/includes/barcode_helper.php';
require_once __DIR__ . '/includes/offline_billing_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;
$businessId = current_business_id();

ensure_offline_billing_schema();

$store = get_store_settings($businessId);
$brand = function_exists('get_mobile_store_settings') ? get_mobile_store_settings($businessId) : [];

$storeThemeColor = !empty($store['primary_color']) ? trim((string) $store['primary_color']) :
    (!empty($brand['header_color']) ? trim((string) $brand['header_color']) : '#0d5c52');
if (!preg_match('/^#([a-f0-9]{3}){1,2}$/i', $storeThemeColor)) {
    $storeThemeColor = '#0d5c52';
}

if (!function_exists('ofb_hex_to_rgb')) {
    function ofb_hex_to_rgb(string $hex): array {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}

$rgb = ofb_hex_to_rgb($storeThemeColor);
$storeThemeRgb = implode(',', $rgb);
$summaryBgColor = "rgba({$storeThemeRgb}, 0.08)";

if (isset($_GET['ajax']) && $_GET['ajax'] === 'barcode') {
    $text = trim((string) ($_GET['text'] ?? 'ORDER'));
    $color = trim((string) ($_GET['color'] ?? $storeThemeColor));
    if (!preg_match('/^#([a-f0-9]{3}){1,2}$/i', $color)) {
        $color = $storeThemeColor;
    }
    header('Content-Type: image/svg+xml; charset=utf-8');
    echo generate_code128_svg($text !== '' ? $text : 'ORDER', 36, 1.4, $color);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_offline_bill')) {
    header('Content-Type: application/json; charset=utf-8');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Security session expired. Please refresh.']);
        exit;
    }
    $items = json_decode((string) ($_POST['items_json'] ?? '[]'), true);
    $payload = [
        'id' => (int) ($_POST['bill_id'] ?? 0),
        'invoice_number' => trim((string) ($_POST['invoice_number'] ?? '')),
        'order_number' => trim((string) ($_POST['order_number'] ?? '')),
        'invoice_date' => trim((string) ($_POST['invoice_date'] ?? '')),
        'payment_mode' => trim((string) ($_POST['payment_mode'] ?? 'Cash on Delivery (COD)')),
        'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
        'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
        'customer_address' => trim((string) ($_POST['customer_address'] ?? '')),
        'customer_pincode' => trim((string) ($_POST['customer_pincode'] ?? '')),
        'discount_amount' => (float) ($_POST['discount_amount'] ?? 0),
        'shipping_fee' => (float) ($_POST['shipping_fee'] ?? 0),
        'print_size' => trim((string) ($_POST['print_size'] ?? 'default')),
        'items' => is_array($items) ? $items : [],
    ];
    $res = save_offline_bill($payload, $userId, $businessId);
    echo json_encode($res);
    exit;
}

$requestedSize = trim((string) ($_GET['size'] ?? 'default'));
if (!in_array($requestedSize, ['default', '4x3'], true)) {
    $requestedSize = 'default';
}
$isStandalone = isset($_GET['standalone']);
$autoPrint = isset($_GET['print']);

$editBill = null;
$editId = (int) ($_GET['id'] ?? 0);
if ($editId > 0) {
    $editBill = get_offline_bill_by_id($editId, $businessId);
}
$startOnInvoice = $isStandalone || $autoPrint || ($editId > 0 && (string) ($_GET['step'] ?? '') !== 'products');

$nextInvoice = $editBill['invoice_number'] ?? generate_next_offline_invoice_number($businessId);
$nextOrder = $editBill['order_number'] ?? generate_next_offline_order_number($businessId);
if ($editBill) {
    $requestedSize = in_array((string) $editBill['print_size'], ['default', '4x3'], true)
        ? (string) $editBill['print_size']
        : $requestedSize;
}

$invoiceDateYmd = $editBill['invoice_date'] ?? date('Y-m-d');
$invoiceDateDisplay = date('d-m-Y', strtotime((string) $invoiceDateYmd) ?: time());

$candidateLogo = null;
$rawLogos = [$brand['logo_path'] ?? null, $store['logo_path'] ?? null];
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
$storeDisplayName = !empty($store['store_name']) ? (string) $store['store_name'] : (!empty($brand['display_name']) ? (string) $brand['display_name'] : 'STORE');

$carePhone = trim((string) ($brand['customer_care_phone'] ?? ''));
$waPhone = trim((string) ($brand['contact_whatsapp'] ?? ''));
$storePhone = trim((string) ($store['phone'] ?? $brand['phone'] ?? ''));
$rawWhatsApp = $carePhone !== '' ? $carePhone : ($waPhone !== '' ? $waPhone : ($storePhone !== '' ? $storePhone : '9876543210'));

if (!function_exists('format_ofb_whatsapp_phone')) {
    function format_ofb_whatsapp_phone(string $phone): string {
        $phone = trim($phone);
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
        return $phone !== '' ? $phone : '+91 98765 43210';
    }
}
$formattedWhatsAppPhone = format_ofb_whatsapp_phone($rawWhatsApp);

$barcodeSvg = generate_code128_svg($nextOrder, 36, 1.4, $storeThemeColor);

$defaultSizes = ['S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'Free Size'];
$catalog = [];
try {
    $products = get_products('', null, '', '', $businessId);
    foreach ($products as $p) {
        $sizes = [];
        $colours = [];
        $variants = [];
        try {
            $rawVars = function_exists('get_product_variants') ? get_product_variants((int) $p['id'], $businessId) : [];
        } catch (Throwable $e) {
            $rawVars = [];
        }
        foreach ($rawVars as $v) {
            $attrs = ofb_parse_variant_attrs($v);
            if ($attrs['size'] !== '') {
                $sizes[] = $attrs['size'];
            }
            if ($attrs['colour'] !== '') {
                $colours[] = $attrs['colour'];
            }
            $variants[] = [
                'id' => (int) $v['id'],
                'name' => (string) ($v['variant_name'] ?? ''),
                'price' => (float) (($v['selling_price'] ?? 0) ?: ($p['selling_price'] ?? 0)),
                'size' => $attrs['size'],
                'colour' => $attrs['colour'],
            ];
        }
        try {
            $attrRows = function_exists('get_product_attributes') ? get_product_attributes((int) $p['id'], $businessId) : [];
            foreach ($attrRows as $ar) {
                $an = strtolower((string) ($ar['attribute_name'] ?? ''));
                foreach (($ar['options'] ?? []) as $opt) {
                    $val = trim((string) ($opt['value'] ?? ''));
                    if ($val === '') {
                        continue;
                    }
                    if (str_contains($an, 'size')) {
                        $sizes[] = $val;
                    }
                    if (str_contains($an, 'color') || str_contains($an, 'colour') || str_contains($an, 'shade')) {
                        $colours[] = $val;
                    }
                }
            }
        } catch (Throwable $e) {
            // attributes optional
        }
        $img = trim((string) ($p['image_path'] ?? ''));
        $catalog[] = [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'sku' => (string) ($p['sku'] ?? ''),
            'price' => (float) ($p['selling_price'] ?? 0),
            'stock' => (int) ($p['stock_quantity'] ?? 0),
            'image' => $img !== '' ? asset($img) : '',
            'sizes' => array_values(array_unique(array_filter($sizes))),
            'colours' => array_values(array_unique(array_filter($colours))),
            'variants' => $variants,
        ];
    }
} catch (Throwable $e) {
    $catalog = [];
}

$recentBills = list_recent_offline_bills(25, $businessId);

$editItems = [];
if ($editBill) {
    foreach ($editBill['items'] as $it) {
        $editItems[] = [
            'product_id' => $it['product_id'] !== null ? (int) $it['product_id'] : 0,
            'product_name' => (string) $it['product_name'],
            'size' => (string) $it['size'],
            'colour' => (string) $it['colour'],
            'quantity' => (int) $it['quantity'],
            'unit_price' => (float) $it['unit_price'],
        ];
    }
}

$verifyUrl = APP_URL . '/offline-billing.php' . ($editId > 0 ? ('?id=' . $editId . '&standalone=1') : '');
$pageTitle = $editBill ? ('Offline Invoice #' . $editBill['invoice_number']) : 'Offline Billing';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if (!$isStandalone): ?>
        <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <?php endif; ?>
    <script src="<?= asset('assets/js/qrcode.min.js') ?>"></script>
    <style>
        :root {
            --inv-theme: <?= $storeThemeColor ?>;
            --inv-theme-rgb: <?= $storeThemeRgb ?>;
            --inv-theme-tint: <?= $summaryBgColor ?>;
            --inv-border: <?= $storeThemeColor ?>;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: <?= $isStandalone ? '#f1f5f9' : '#f8fafc' ?>;
            color: #1e293b;
            line-height: 1.4;
            margin: 0;
        }

        .ofb-page-wrap { padding: 16px 20px 40px; }
        .ofb-standalone-wrap { padding: 16px 12px 32px; }
        .ofb-page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .ofb-page-header h1 { font-size: 22px; font-weight: 800; margin: 0 0 4px; color: #0f172a; }
        .ofb-page-header p { margin: 0; color: #64748b; font-size: 13.5px; }
        .ofb-step { display: none; }
        .ofb-step.active { display: block; }
        .ofb-steps {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            margin-bottom: 16px; font-size: 13px; color: #64748b; font-weight: 700;
        }
        .ofb-step-pill {
            display: inline-flex; align-items: center;
            padding: 6px 12px; border-radius: 999px; background: #f1f5f9; color: #64748b;
            cursor: pointer; user-select: none;
        }
        .ofb-step-pill.on { background: var(--inv-theme); color: #fff; }
        .ofb-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 400px);
            gap: 20px;
            align-items: start;
        }
        .ofb-products-pane, .ofb-cart-pane {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .ofb-cart-pane { position: sticky; top: 12px; max-height: calc(100vh - 120px); }
        .ofb-cart-title { font-size: 15px; font-weight: 800; color: #0f172a; margin: 0 0 12px; }
        .ofb-cart-fields { display: grid; gap: 8px; margin-bottom: 12px; }
        .ofb-cart-fields input, .ofb-cart-fields textarea {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 10px;
            font-size: 13px; font-family: inherit; box-sizing: border-box;
        }
        .ofb-cart-list { overflow-y: auto; flex: 1; min-height: 80px; }
        .ofb-cart-row {
            border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; margin-bottom: 8px; background: #f8fafc;
        }
        .ofb-cart-row .nm { font-weight: 700; font-size: 13px; color: #0f172a; margin-bottom: 6px; }
        .ofb-cart-grid { display: grid; grid-template-columns: 1fr 1fr 64px 72px 28px; gap: 6px; align-items: center; }
        .ofb-cart-grid input, .ofb-cart-grid select {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 5px 6px; font-size: 12px;
        }
        .ofb-goto-inv {
            width: 100%; margin-top: 10px; background: var(--inv-theme); color: #fff; border: 0;
            border-radius: 10px; padding: 12px 16px; font-weight: 800; font-size: 14.5px; cursor: pointer;
        }
        .ofb-goto-inv:hover { opacity: 0.93; }
        .ofb-cart-del {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border: 0; border-radius: 50%;
            background: #fee2e2; color: #b91c1c; font-weight: 800; cursor: pointer; line-height: 1;
        }
        .ofb-cart-total { font-size: 16px; font-weight: 800; color: var(--inv-theme); text-align: right; margin-top: 8px; }
        .ofb-search {
            width: 100%;
            height: 40px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 0 12px;
            font-size: 13.5px;
            margin-bottom: 10px;
        }
        .ofb-search:focus { outline: none; border-color: var(--inv-theme); box-shadow: 0 0 0 3px rgba(var(--inv-theme-rgb), 0.15); }
        .ofb-product-list { overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 8px; min-height: 240px; max-height: 62vh; }
        .ofb-prod-card {
            display: grid;
            grid-template-columns: 48px 1fr auto;
            gap: 10px;
            align-items: center;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px;
            background: #f8fafc;
        }
        .ofb-prod-img {
            width: 48px; height: 48px; border-radius: 8px; object-fit: cover; background: #e2e8f0;
            display: flex; align-items: center; justify-content: center; font-size: 11px; color: #94a3b8; overflow: hidden;
        }
        .ofb-prod-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ofb-prod-name { font-size: 13px; font-weight: 700; color: #0f172a; line-height: 1.25; }
        .ofb-prod-meta { font-size: 11px; color: #64748b; margin-top: 2px; }
        .ofb-add-btn {
            background: var(--inv-theme); color: #fff; border: 0; border-radius: 7px;
            padding: 7px 12px; font-weight: 800; font-size: 12px; cursor: pointer; white-space: nowrap;
        }
        .ofb-add-btn:hover { opacity: 0.92; }
        .ofb-recent { margin-top: 12px; border-top: 1px dashed #cbd5e1; padding-top: 10px; max-height: 220px; overflow-y: auto; }
        .ofb-recent h3 { font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase; color: #64748b; margin: 0 0 8px; }
        .ofb-recent a {
            display: flex; justify-content: space-between; gap: 8px;
            text-decoration: none; color: #334155; font-size: 12.5px; padding: 6px 4px; border-radius: 6px;
        }
        .ofb-recent a:hover, .ofb-recent a.active { background: #ecfdf5; color: #0d5c52; }
        .ofb-empty { text-align: center; color: #94a3b8; font-size: 13px; padding: 24px 8px; }

        .ofb-invoice-pane { min-width: 0; }
        #ofbStepInvoice .inv-action-bar,
        #ofbStepInvoice .inv-card { max-width: min(960px, 100%); }
        .inv-action-bar {
            max-width: min(960px, 100%);
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            flex-wrap: wrap;
            gap: 10px;
        }
        .inv-btn {
            display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
            font-size: 13px; font-weight: 700; border-radius: 8px; text-decoration: none;
            cursor: pointer; border: 1px solid transparent; background: #fff; color: #334155;
        }
        .inv-btn-primary { background: var(--inv-theme); color: #ffffff; }
        .inv-btn-outline { border-color: #cbd5e1; color: #475569; }
        .inv-btn-outline:hover { background: #f8fafc; }
        .ofb-status { font-size: 12px; font-weight: 700; color: #047857; }

        .inv-card {
            width: 100%;
            max-width: min(960px, 100%);
            margin: 0 auto;
            background: #ffffff;
            border: 2.5px solid var(--inv-theme);
            border-radius: 20px;
            padding: clamp(16px, 3.2vw, 32px) clamp(14px, 3.6vw, 36px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
            position: relative;
            container-type: inline-size;
            container-name: ofbinv;
        }
        .inv-top-grid {
            display: grid;
            grid-template-columns: minmax(96px, 150px) minmax(240px, 1.45fr) minmax(180px, 1.2fr);
            gap: clamp(12px, 2.2vw, 22px);
            align-items: start;
            margin-bottom: clamp(14px, 2.4vw, 24px);
        }
        .inv-brand-col { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding-right: 8px; min-width: 0; max-width: 100%; overflow: hidden; }
        .inv-brand-img { max-height: clamp(56px, 8vw, 98px); max-width: min(160px, 100%); object-fit: contain; margin-bottom: 8px; }
        .inv-brand-title {
            display: block;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
            font-size: clamp(8.5px, 1.35vw, 15px);
            font-weight: 900;
            color: var(--inv-theme);
            letter-spacing: 0.04em;
            text-transform: uppercase;
            line-height: 1.2;
            overflow-wrap: anywhere;
            word-break: break-word;
            hyphens: auto;
        }
        .inv-meta-col { padding-left: 4px; padding-right: 8px; min-width: 0; }
        .inv-main-heading { font-size: clamp(20px, 2.6vw, 26px); font-weight: 800; color: var(--inv-theme); letter-spacing: 0.05em; line-height: 1; margin: 0 0 5px; }
        .inv-thank-you { font-size: clamp(8px, 1vw, 10px); font-weight: 700; color: var(--inv-theme); letter-spacing: 0.04em; display: flex; align-items: center; flex-wrap: wrap; gap: 4px; margin-bottom: 10px; }
        .inv-meta-list, .inv-cust-list { display: flex; flex-direction: column; gap: 4px; font-size: clamp(11px, 1.15vw, 12.5px); }
        .inv-row-kv {
            display: grid;
            grid-template-columns: max-content 8px minmax(0, 1fr);
            column-gap: 6px;
            align-items: start;
            line-height: 1.4;
        }
        .inv-row-kv .lbl, .inv-row-kv .sep, .inv-row-kv .val { font-weight: 600; color: #1e293b; }
        .inv-row-kv .lbl { white-space: nowrap; padding-top: 1px; }
        .inv-row-kv .sep { text-align: center; padding-top: 1px; }
        .inv-row-kv .val {
            min-width: 0;
            overflow-wrap: break-word;
            word-break: normal;
        }
        .inv-cust-col { padding-left: clamp(10px, 1.5vw, 20px); border-left: 1.5px solid var(--inv-theme); min-width: 0; }
        .inv-cust-heading { font-size: clamp(13px, 1.4vw, 15px); font-weight: 750; color: var(--inv-theme); margin: 0 0 10px; }
        .inv-cust-col .inv-row-kv { grid-template-columns: max-content 8px minmax(0, 1fr); }

        .inv-edit {
            width: 100%;
            max-width: 100%;
            border: 1px dashed rgba(var(--inv-theme-rgb), 0.28);
            background: transparent;
            font: inherit;
            font-weight: 600;
            color: inherit;
            padding: 1px 4px;
            border-radius: 4px;
            min-width: 0;
            box-sizing: border-box;
        }
        .inv-edit:focus { outline: none; border-color: var(--inv-theme); background: #fff; }
        textarea.inv-edit { resize: vertical; min-height: 36px; line-height: 1.35; overflow: hidden; }
        select.inv-edit { cursor: pointer; }
        .inv-edit-wrap {
            display: block;
            width: 100%;
            line-height: 1.4;
            min-height: 1.35em;
            overflow: visible;
            overflow-wrap: break-word;
            word-break: normal;
        }
        .inv-meta-col #fldOrderNo,
        .inv-meta-col #fldInvoiceNo,
        .inv-meta-col #fldOrderDate {
            white-space: nowrap;
            overflow-wrap: normal;
            word-break: keep-all;
        }
        .inv-meta-col #fldPayMode {
            white-space: normal;
            overflow-wrap: break-word;
            word-break: normal;
        }
        .inv-meta-col #fldOrderDate { min-width: 7.4em; }

        @container ofbinv (max-width: 720px) {
            .inv-top-grid {
                grid-template-columns: minmax(88px, 120px) minmax(0, 1.2fr) minmax(0, 1.15fr);
                gap: 12px;
            }
            .inv-meta-col #fldOrderNo,
            .inv-meta-col #fldInvoiceNo,
            .inv-meta-col #fldPayMode {
                white-space: normal;
                overflow-wrap: break-word;
                word-break: normal;
            }
        }
        @container ofbinv (max-width: 540px) {
            .inv-top-grid { grid-template-columns: 1fr; }
            .inv-brand-col {
                flex-direction: row; justify-content: flex-start; text-align: left;
                gap: 12px; padding-right: 0; padding-bottom: 10px;
                border-bottom: 1.5px solid var(--inv-theme);
            }
            .inv-meta-col { padding: 4px 0 0; }
            .inv-cust-col { border-left: none; padding-left: 0; border-top: 1.5px solid var(--inv-theme); padding-top: 12px; }
            .inv-footer-grid { grid-template-columns: 1fr; }
            .inv-footer-love { border-left: none; border-right: none; border-top: 1.5px solid var(--inv-theme); border-bottom: 1.5px solid var(--inv-theme); }
            .inv-footer-help { justify-content: flex-start; }
            .inv-products-table { min-width: 0; }
        }

        .inv-table-wrap { width: 100%; margin-bottom: 18px; border-radius: 8px 8px 0 0; overflow: auto; border: 1.5px solid var(--inv-theme); }
        .inv-products-table { width: 100%; border-collapse: collapse; min-width: 560px; }
        .inv-products-table thead { background: var(--inv-theme); color: #ffffff; }
        .inv-products-table th { padding: 9px 10px; font-size: 12px; font-weight: 700; border-right: 1.5px solid rgba(255,255,255,0.25); text-align: center; }
        .inv-products-table th.col-name { text-align: left; padding-left: 14px; }
        .inv-products-table tbody td {
            padding: 7px 8px; font-size: 12px; color: #1e293b; font-weight: 500;
            border-bottom: 1.5px solid var(--inv-theme); border-right: 1.5px solid var(--inv-theme);
            vertical-align: middle; text-align: center;
        }
        .inv-products-table tbody tr:last-child td { border-bottom: none; }
        .inv-products-table tbody td:last-child { border-right: none; }
        .inv-products-table tbody td.col-name { text-align: left; padding-left: 10px; }
        .inv-products-table tbody td.col-price, .inv-products-table tbody td.col-total { text-align: right; padding-right: 10px; font-weight: 600; }
        .ofb-idx-cell { position: relative; }
        .ofb-remove {
            display: none; position: absolute; top: 2px; right: 2px;
            width: 18px; height: 18px; border: 0; border-radius: 50%;
            background: #fee2e2; color: #b91c1c; font-weight: 800; cursor: pointer; line-height: 1;
        }
        tr:hover .ofb-remove { display: inline-flex; align-items: center; justify-content: center; }
        .ofb-add-row {
            margin: 0 0 16px;
            background: none; border: 1px dashed var(--inv-theme); color: var(--inv-theme);
            border-radius: 8px; padding: 7px 12px; font-weight: 700; font-size: 12.5px; cursor: pointer;
        }

        .inv-summary-container { display: flex; justify-content: flex-end; margin-bottom: 22px; }
        .inv-summary-box { width: 290px; background: var(--inv-theme-tint); border-radius: 10px; padding: 12px 18px; display: flex; flex-direction: column; gap: 6px; }
        .inv-summary-row { display: grid; grid-template-columns: 110px 14px 1fr; align-items: center; font-size: 12.5px; color: #1e293b; font-weight: 600; }
        .inv-summary-row.grand-total-row { margin-top: 4px; padding-top: 6px; border-top: 1.5px solid rgba(var(--inv-theme-rgb), 0.25); font-size: 14.5px; font-weight: 800; color: var(--inv-theme); }
        .inv-summary-row .s-val { text-align: right; font-weight: 700; }
        .inv-summary-row .inv-edit { text-align: right; }

        .inv-footer-grid { display: grid; grid-template-columns: 1.15fr 1fr 1.25fr; gap: 16px; align-items: center; padding-top: 6px; }
        .inv-barcode-title { font-size: 11px; font-weight: 700; color: #1e293b; margin-bottom: 3px; overflow-wrap: break-word; word-break: normal; max-width: 100%; }
        .inv-barcode-svg-wrap { max-width: 210px; }
        .inv-barcode-svg-wrap svg { width: 100%; height: 46px; }
        .inv-footer-love {
            display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
            border-left: 1.5px solid var(--inv-theme); border-right: 1.5px solid var(--inv-theme); padding: 4px 12px; min-height: 64px;
        }
        .inv-love-script { font-family: 'Caveat', cursive; font-size: 21px; font-weight: 700; color: var(--inv-theme); }
        .inv-love-heart { color: var(--inv-theme); font-size: 12px; }
        .inv-love-brand { font-size: 11px; font-weight: 800; color: var(--inv-theme); letter-spacing: 0.12em; text-transform: uppercase; }
        .inv-footer-help { display: flex; align-items: center; justify-content: flex-end; gap: 12px; }
        .inv-qr-box { width: 68px; height: 68px; background: #fff; flex-shrink: 0; }
        .inv-qr-box canvas, .inv-qr-box img { width: 68px !important; height: 68px !important; display: block; }
        .inv-help-text { font-size: 11px; color: #1e293b; line-height: 1.35; }
        .inv-help-title { font-weight: 700; font-size: 11.5px; }
        .inv-help-phone { font-weight: 700; }

        /* 4x3 screen preview — compact card with readable header alignment */
        body.size-4x3 #ofbStepInvoice .inv-card,
        body.size-4x3 .ofb-standalone-wrap .inv-card,
        body.size-4x3 .inv-card {
            width: 100%;
            max-width: 620px;
            height: auto;
            max-height: none;
            padding: 14px 16px 12px;
            border-radius: 14px;
            overflow: visible;
            display: block;
            box-sizing: border-box;
            container-type: normal;
        }
        body.size-4x3 .inv-top-grid {
            display: grid;
            grid-template-columns: 86px minmax(0, 1.4fr) minmax(0, 1.25fr);
            gap: 10px;
            margin-bottom: 10px;
            align-items: start;
        }
        body.size-4x3 .inv-brand-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            text-align: center;
            padding-right: 4px;
            width: auto;
            max-width: 100%;
            min-width: 0;
            overflow: hidden;
            border-bottom: none;
            gap: 0;
        }
        body.size-4x3 .inv-brand-img { max-height: 52px; max-width: 78px; margin-bottom: 4px; }
        body.size-4x3 .inv-brand-col svg { width: 52px; height: 52px; margin-bottom: 4px !important; }
        body.size-4x3 .inv-brand-title {
            font-size: clamp(7.5px, 1.6vw, 10px);
            font-weight: 900;
            letter-spacing: 0.03em;
            overflow-wrap: anywhere;
            word-break: break-word;
            max-width: 100%;
            width: 100%;
        }
        body.size-4x3 .inv-meta-col { padding: 0 6px 0 0; min-width: 0; }
        body.size-4x3 .inv-main-heading { font-size: 18px; margin: 0 0 2px; }
        body.size-4x3 .inv-thank-you {
            font-size: 7.5px;
            margin-bottom: 6px;
            flex-wrap: nowrap;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        body.size-4x3 .inv-meta-list, body.size-4x3 .inv-cust-list { font-size: 9.5px; gap: 3px; }
        body.size-4x3 .inv-row-kv {
            display: grid;
            grid-template-columns: 78px 8px minmax(0, 1fr);
            align-items: start;
            column-gap: 4px;
            gap: 0;
        }
        body.size-4x3 .inv-row-kv .lbl { width: auto; flex: none; white-space: nowrap; }
        body.size-4x3 .inv-row-kv .sep { width: auto; flex: none; }
        body.size-4x3 .inv-row-kv .val {
            min-width: 0;
            overflow-wrap: break-word;
            word-break: break-word;
            white-space: normal;
        }
        body.size-4x3 .inv-cust-col {
            padding-left: 10px;
            border-left: 1px solid var(--inv-theme);
            border-top: none;
            padding-top: 0;
            width: auto;
            max-width: none;
            min-width: 0;
        }
        body.size-4x3 .inv-cust-heading { font-size: 12px; margin-bottom: 6px; }
        body.size-4x3 .inv-cust-col .inv-row-kv { grid-template-columns: 52px 8px minmax(0, 1fr); }
        body.size-4x3 .inv-cust-col .inv-row-kv .lbl { width: auto; flex-basis: auto; }
        body.size-4x3 .inv-edit,
        body.size-4x3 .inv-edit-wrap {
            border-color: transparent;
            padding: 0;
            min-height: 0;
            line-height: 1.35;
        }
        body.size-4x3 .inv-edit:focus,
        body.size-4x3 .inv-edit-wrap:focus {
            border-color: var(--inv-theme);
            padding: 0 2px;
            background: #fff;
        }
        body.size-4x3 .inv-edit-wrap {
            display: inline;
            width: auto;
            max-width: 100%;
        }
        body.size-4x3 .inv-meta-col #fldOrderNo,
        body.size-4x3 .inv-meta-col #fldInvoiceNo,
        body.size-4x3 .inv-meta-col #fldPayMode,
        body.size-4x3 .inv-meta-col #fldOrderDate {
            white-space: normal;
            overflow-wrap: break-word;
            word-break: break-word;
            min-width: 0;
        }
        body.size-4x3 input.inv-edit { display: block; width: 100%; }
        body.size-4x3 textarea.inv-edit { min-height: 2.1em; max-height: 2.4em; overflow: hidden; display: block; width: 100%; }
        body.size-4x3 .inv-products-table input.inv-edit,
        body.size-4x3 .inv-products-table select.inv-edit {
            display: inline-block;
            width: 100%;
            min-width: 0;
            border-color: rgba(var(--inv-theme-rgb), 0.28);
            padding: 1px 3px;
        }
        body.size-4x3 .inv-products-table .ofb-qty { min-width: 32px; width: 100%; }
        body.size-4x3 .inv-products-table .ofb-price { min-width: 42px; }
        body.size-4x3 input[type=number].inv-edit { -moz-appearance: textfield; appearance: textfield; }
        body.size-4x3 input[type=number].inv-edit::-webkit-outer-spin-button,
        body.size-4x3 input[type=number].inv-edit::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        body.size-4x3 .inv-table-wrap {
            flex: none;
            margin-bottom: 8px;
            overflow: visible;
        }
        body.size-4x3 .inv-products-table {
            min-width: 0;
            table-layout: fixed;
            width: 100%;
        }
        body.size-4x3 .inv-products-table th,
        body.size-4x3 .inv-products-table tbody td { padding: 4px 5px; font-size: 9px; }
        body.size-4x3 .ofb-add-row { margin: 0 0 8px; padding: 4px 8px; font-size: 10.5px; }
        body.size-4x3 .inv-summary-container { margin-bottom: 8px; }
        body.size-4x3 .inv-summary-box { width: 200px; padding: 6px 10px; gap: 2px; }
        body.size-4x3 .inv-summary-row { font-size: 9px; grid-template-columns: 82px 8px 1fr; }
        body.size-4x3 .inv-summary-row.grand-total-row { font-size: 11px; margin-top: 2px; padding-top: 3px; }
        body.size-4x3 .inv-footer-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) auto minmax(0, 1.15fr);
            gap: 8px;
            padding-top: 4px;
            margin-top: 0;
            align-items: center;
        }
        body.size-4x3 .inv-footer-barcode { min-width: 0; }
        body.size-4x3 .inv-footer-love {
            min-height: 48px;
            padding: 2px 10px;
            border-width: 1px;
            border-top: none;
            border-bottom: none;
            border-left: 1px solid var(--inv-theme);
            border-right: 1px solid var(--inv-theme);
        }
        body.size-4x3 .inv-footer-help { justify-content: flex-end; gap: 8px; padding-left: 0; min-width: 0; }
        body.size-4x3 .inv-barcode-title { font-size: 8px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        body.size-4x3 .inv-barcode-svg-wrap { max-width: 140px; }
        body.size-4x3 .inv-barcode-svg-wrap svg { height: 30px; }
        body.size-4x3 .inv-love-script { font-size: 15px; }
        body.size-4x3 .inv-love-heart { font-size: 9px; margin-bottom: 1px; }
        body.size-4x3 .inv-love-brand { font-size: 8px; }
        body.size-4x3 .inv-qr-box { width: 44px; height: 44px; flex-shrink: 0; }
        body.size-4x3 .inv-qr-box canvas, body.size-4x3 .inv-qr-box img { width: 44px !important; height: 44px !important; }
        body.size-4x3 .inv-help-text { font-size: 8px; line-height: 1.2; }
        body.size-4x3 .inv-help-title { font-size: 8.5px; }
        body.size-4x3 .inv-help-phone { white-space: nowrap; }

        @media screen and (max-width: 1100px) {
            .ofb-workspace { grid-template-columns: 1fr; }
            .ofb-cart-pane { position: static; max-height: none; }
        }
        @media screen and (max-width: 980px) {
            body:not(.size-4x3) .inv-top-grid { grid-template-columns: minmax(90px, 130px) minmax(0, 1.35fr) minmax(0, 1.15fr); }
            .inv-action-bar { max-width: 100%; }
        }
        @media screen and (max-width: 760px) {
            body:not(.size-4x3) .inv-top-grid,
            body:not(.size-4x3) .inv-footer-grid { grid-template-columns: 1fr; }
            body:not(.size-4x3) .inv-cust-col { border-left: none; padding-left: 0; border-top: 1.5px solid var(--inv-theme); padding-top: 12px; }
            body:not(.size-4x3) .inv-footer-love { border-left: none; border-right: none; border-top: 1.5px solid var(--inv-theme); border-bottom: 1.5px solid var(--inv-theme); }
            body:not(.size-4x3) .inv-card { padding: 16px 12px; border-radius: 14px; }
            .ofb-page-wrap { padding: 12px; }
            body:not(.size-4x3) .inv-meta-col #fldOrderNo,
            body:not(.size-4x3) .inv-meta-col #fldInvoiceNo { white-space: normal; }
            body:not(.size-4x3) .inv-summary-box { width: 100%; max-width: 290px; }
            body:not(.size-4x3) .inv-products-table { min-width: 480px; }
        }
        @media screen and (max-width: 480px) {
            .inv-action-bar { padding: 10px; }
            body:not(.size-4x3) .inv-brand-col { flex-direction: row; justify-content: flex-start; text-align: left; gap: 10px; }
            body:not(.size-4x3) .inv-row-kv { grid-template-columns: minmax(78px, max-content) 6px minmax(0, 1fr); }
        }

        @media print {
            .no-print, .inv-action-bar, .app-sidebar, .app-header, .ofb-products-pane, .ofb-page-header,
            .ofb-add-row, .ofb-remove, .ofb-cart-del, .spotlight-overlay, button.ofb-add-row, #ofbStepProducts, .ofb-steps, .ofb-cart-pane {
                display: none !important;
                visibility: hidden !important;
                width: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            #ofbStepInvoice {
                display: block !important;
                visibility: visible !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            html, body {
                background: #ffffff !important; margin: 0 !important; padding: 0 !important;
                width: 100% !important; max-width: 100% !important; min-width: 0 !important;
                height: auto !important; min-height: 0 !important; overflow: visible !important;
                -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
            }
            .app-layout, .app-main, .dashboard-content, .ofb-page-wrap, .ofb-workspace, .ofb-invoice-pane,
            .ofb-standalone-wrap, #ofbStepInvoice {
                display: block !important; margin: 0 !important; padding: 0 !important;
                width: 100% !important; max-width: 100% !important; background: transparent !important;
                overflow: visible !important; position: static !important;
                min-height: 0 !important; height: auto !important;
            }
            .inv-edit, select.inv-edit, textarea.inv-edit, .inv-edit-wrap {
                border: none !important; background: transparent !important; padding: 0 !important;
                appearance: none; -webkit-appearance: none; box-shadow: none !important;
                overflow: visible !important; white-space: normal !important;
                word-break: normal !important; overflow-wrap: break-word !important;
                min-height: 0 !important; height: auto !important; resize: none !important;
            }
            body:not(.size-4x3) .inv-top-grid {
                grid-template-columns: minmax(110px, 150px) minmax(240px, 1.45fr) minmax(180px, 1.2fr) !important;
            }
            body:not(.size-4x3) .inv-meta-col #fldOrderNo,
            body:not(.size-4x3) .inv-meta-col #fldInvoiceNo,
            body:not(.size-4x3) .inv-meta-col #fldOrderDate {
                white-space: nowrap !important;
                overflow-wrap: normal !important;
                word-break: keep-all !important;
                overflow: visible !important;
            }
            .inv-card {
                border: 2.5px solid var(--inv-theme) !important; box-shadow: none !important;
                margin: 0 auto !important; max-width: 100% !important;
                -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
            }
            .inv-products-table thead { background: var(--inv-theme) !important; color: #fff !important; }
            .inv-summary-box { background: var(--inv-theme-tint) !important; }

            html:has(body.size-4x3) {
                width: 4in !important;
                height: 3in !important;
                max-width: 4in !important;
                max-height: 3in !important;
                overflow: hidden !important;
            }
            body.size-4x3 {
                width: 4in !important;
                max-width: 4in !important;
                height: 3in !important;
                max-height: 3in !important;
                min-height: 0 !important;
                overflow: hidden !important;
            }
            body.size-4x3 .app-layout,
            body.size-4x3 .app-main,
            body.size-4x3 .dashboard-content,
            body.size-4x3 .ofb-page-wrap,
            body.size-4x3 .ofb-workspace,
            body.size-4x3 .ofb-invoice-pane,
            body.size-4x3 #ofbStepInvoice,
            body.size-4x3 .ofb-standalone-wrap {
                width: 4in !important;
                max-width: 4in !important;
                height: 3in !important;
                max-height: 3in !important;
                overflow: hidden !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            body.size-4x3 .inv-card {
                width: 4in !important;
                max-width: 4in !important;
                height: 3in !important;
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
            }
            body.size-4x3 .inv-meta-col {
                flex: 1 1 38% !important;
                min-width: 0 !important;
                padding-left: 0 !important;
                padding-right: 6px !important;
            }
            body.size-4x3 .inv-cust-col {
                flex: 1 1 40% !important;
                min-width: 0 !important;
                padding-left: 8px !important;
                border-left: 1px solid var(--inv-theme) !important;
                border-top: none !important;
                padding-top: 0 !important;
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
            body.size-4x3 .inv-row-kv .sep { flex: 0 0 6px !important; width: 6px !important; }
            body.size-4x3 .inv-row-kv .val {
                flex: 1 1 auto !important;
                min-width: 0 !important;
                word-break: normal !important;
                overflow-wrap: break-word !important;
                white-space: normal !important;
            }
            body.size-4x3 .inv-edit-wrap,
            body.size-4x3 .inv-edit {
                white-space: normal !important;
                overflow-wrap: break-word !important;
                word-break: normal !important;
                overflow: hidden !important;
            }
            body.size-4x3 textarea.inv-edit {
                max-height: 2.2em !important;
                min-height: 0 !important;
                overflow: hidden !important;
            }
            body.size-4x3 .inv-cust-col .inv-row-kv .lbl { flex-basis: 48px !important; width: 48px !important; }
            body.size-4x3 .inv-main-heading { font-size: 14px !important; margin-bottom: 1px !important; }
            body.size-4x3 .inv-thank-you { font-size: 6.5px !important; margin-bottom: 3px !important; white-space: normal !important; }
            body.size-4x3 .inv-brand-img { max-height: 36px !important; max-width: 80px !important; }
            body.size-4x3 .inv-brand-title {
                font-size: 8px !important;
                letter-spacing: 0.02em !important;
                overflow-wrap: anywhere !important;
                word-break: break-word !important;
                max-width: 100% !important;
                width: 100% !important;
            }
            body.size-4x3 .inv-meta-list, body.size-4x3 .inv-cust-list { font-size: 7.5px !important; gap: 1px !important; }
            body.size-4x3 .inv-cust-heading { font-size: 9.5px !important; margin-bottom: 3px !important; }
            body.size-4x3 .inv-table-wrap { margin-bottom: 5px !important; border-width: 1px !important; overflow: hidden !important; }
            body.size-4x3 .inv-products-table { table-layout: fixed !important; width: 100% !important; min-width: 0 !important; }
            body.size-4x3 .inv-products-table th,
            body.size-4x3 .inv-products-table tbody td { padding: 2px 3px !important; font-size: 7.5px !important; border-width: 1px !important; }
            body.size-4x3 .inv-summary-container { margin-bottom: 5px !important; }
            body.size-4x3 .inv-summary-box { width: 1.55in !important; padding: 4px 6px !important; gap: 1px !important; border-radius: 5px !important; }
            body.size-4x3 .inv-summary-row { display: flex !important; font-size: 7.5px !important; grid-template-columns: none !important; gap: 4px !important; }
            body.size-4x3 .inv-summary-row .s-label { flex: 0 0 70px !important; }
            body.size-4x3 .inv-summary-row .s-sep { flex: 0 0 6px !important; }
            body.size-4x3 .inv-summary-row .s-val { flex: 1 1 auto !important; }
            body.size-4x3 .inv-summary-row.grand-total-row { font-size: 9px !important; padding-top: 2px !important; margin-top: 1px !important; }
            body.size-4x3 .inv-footer-grid {
                display: flex !important; flex-wrap: nowrap !important; align-items: center !important;
                gap: 4px !important; padding-top: 2px !important; grid-template-columns: none !important;
            }
            body.size-4x3 .inv-footer-barcode { flex: 1 1 0 !important; min-width: 0 !important; }
            body.size-4x3 .inv-footer-love { flex: 0 0 auto !important; min-height: 42px !important; padding: 2px 6px !important; border-top: none !important; border-bottom: none !important; }
            body.size-4x3 .inv-footer-help { flex: 1 1 0 !important; min-width: 0 !important; gap: 6px !important; padding-left: 4px !important; }
            body.size-4x3 .inv-barcode-title { font-size: 7px !important; margin-bottom: 1px !important; }
            body.size-4x3 .inv-barcode-svg-wrap { max-width: 1.2in !important; }
            body.size-4x3 .inv-barcode-svg-wrap svg { height: 22px !important; }
            body.size-4x3 .inv-love-script { font-size: 12px !important; }
            body.size-4x3 .inv-love-heart { font-size: 7px !important; }
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
<?php if (!$isStandalone): ?>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>
            <main class="dashboard-content ofb-page-wrap">
                <div class="ofb-page-header no-print">
                    <div>
                        <h1>Offline Billing</h1>
                        <p>Pehle products add karein, phir invoice check karke print karein.</p>
                    </div>
                    <a href="<?= asset('consignment-manifest.php') ?>" class="inv-btn inv-btn-outline">Back to COD Manifest</a>
                </div>
                <div class="ofb-steps no-print">
                    <span class="ofb-step-pill <?= $startOnInvoice ? '' : 'on' ?>" id="pillProducts">1. Add Products</span>
                    <span>→</span>
                    <span class="ofb-step-pill <?= $startOnInvoice ? 'on' : '' ?>" id="pillInvoice">2. Check Invoice</span>
                </div>

                <div id="ofbStepProducts" class="ofb-step no-print <?= $startOnInvoice ? '' : 'active' ?>">
                    <div class="ofb-workspace">
                    <aside class="ofb-products-pane">
                        <div class="ofb-cart-title">Products</div>
                        <input type="search" id="ofbProductSearch" class="ofb-search" placeholder="Search products by name or SKU..." autocomplete="off">
                        <div id="ofbProductList" class="ofb-product-list"></div>
                    </aside>
                    <aside class="ofb-cart-pane">
                        <div class="ofb-cart-title">Bill items</div>
                        <div class="ofb-cart-fields">
                            <input id="cartCustName" placeholder="Customer name" value="<?= e((string) ($editBill['customer_name'] ?? '')) ?>">
                            <input id="cartCustPhone" placeholder="Phone" value="<?= e((string) ($editBill['customer_phone'] ?? '')) ?>">
                            <textarea id="cartCustAddress" rows="2" placeholder="Address"><?= e((string) ($editBill['customer_address'] ?? '')) ?></textarea>
                            <input id="cartCustPin" placeholder="Pin code" value="<?= e((string) ($editBill['customer_pincode'] ?? '')) ?>">
                        </div>
                        <div id="ofbCartList" class="ofb-cart-list"></div>
                        <div class="ofb-cart-total" id="cartGrand">₹ 0</div>
                        <button type="button" class="ofb-goto-inv" id="ofbGoInvoiceBtn">Go to Invoice</button>
                        <div class="ofb-empty" style="padding:8px 0 0;">Add products, check the bill, then open invoice.</div>
                        <div class="ofb-recent">
                            <h3>Recent offline bills</h3>
                            <?php if (empty($recentBills)): ?>
                                <div class="ofb-empty" style="padding:8px;">No bills yet</div>
                            <?php else: ?>
                                <?php foreach ($recentBills as $rb): ?>
                                    <a href="<?= asset('offline-billing.php?id=' . (int) $rb['id'] . '&step=invoice') ?>" class="<?= $editId === (int) $rb['id'] ? 'active' : '' ?>">
                                        <span><?= e($rb['invoice_number']) ?><br><small><?= e($rb['customer_name'] ?: 'No name') ?></small></span>
                                        <strong>₹<?= number_format((float) $rb['grand_total'], 0) ?></strong>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </aside>
                    </div>
                </div>

                <div id="ofbStepInvoice" class="ofb-step <?= $startOnInvoice ? 'active' : '' ?>">
<?php else: ?>
    <div class="ofb-standalone-wrap">
<?php endif; ?>

        <?php if (!$autoPrint): ?>
        <div class="inv-action-bar no-print">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <?php if (!$isStandalone): ?>
                    <button type="button" class="inv-btn inv-btn-outline" id="ofbBackProductsBtn">← Add Products</button>
                    <a href="<?= asset('offline-billing.php') ?>" class="inv-btn inv-btn-outline">New Bill</a>
                <?php endif; ?>
                <span class="ofb-status" id="ofbStatus"><?= $editBill ? 'Saved · ' . e($editBill['invoice_number']) : 'Unsaved draft' ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <div style="display:inline-flex;align-items:center;gap:6px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:4px 10px;">
                    <label for="invPageSizeSelect" style="font-size:12px;font-weight:700;color:#475569;margin:0;">Page Size:</label>
                    <select id="invPageSizeSelect" onchange="switchInvoiceSize(this.value)" style="font-size:12.5px;font-weight:700;border:none;background:transparent;cursor:pointer;outline:none;">
                        <option value="default" <?= $requestedSize === 'default' ? 'selected' : '' ?>>Default (A4)</option>
                        <option value="4x3" <?= $requestedSize === '4x3' ? 'selected' : '' ?>>4x3 inch (Card)</option>
                    </select>
                </div>
                <button type="button" class="inv-btn inv-btn-outline" id="ofbSaveBtn">Save</button>
                <button type="button" class="inv-btn inv-btn-primary" id="ofbPrintBtn">Print / Save PDF</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="inv-card" id="ofbInvoiceCard">
            <div class="inv-top-grid">
                <div class="inv-brand-col">
                    <?php if ($logoExists): ?>
                        <img src="<?= asset($storeLogo) ?>" alt="<?= e($storeDisplayName) ?>" class="inv-brand-img">
                    <?php else: ?>
                        <svg width="98" height="98" viewBox="0 0 100 100" fill="none" style="color:var(--inv-theme);margin-bottom:8px;">
                            <circle cx="50" cy="42" r="28" stroke="currentColor" stroke-width="6"/>
                            <path d="M50 14v10M32 22l8 8M68 22l-8 8" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
                        </svg>
                    <?php endif; ?>
                    <div class="inv-brand-title"><?= e($storeDisplayName) ?></div>
                </div>
                <div class="inv-meta-col">
                    <h1 class="inv-main-heading">INVOICE</h1>
                    <div class="inv-thank-you"><span>THANK YOU FOR SHOPPING WITH US</span><span>♥</span></div>
                    <div class="inv-meta-list">
                        <div class="inv-row-kv">
                            <span class="lbl">Order No</span><span class="sep">:</span>
                            <span class="val"><span class="inv-edit inv-edit-wrap" id="fldOrderNo" contenteditable="true" role="textbox"><?= e($nextOrder) ?></span></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Order Date</span><span class="sep">:</span>
                            <span class="val"><input class="inv-edit" id="fldOrderDate" value="<?= e($invoiceDateDisplay) ?>"></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Invoice No</span><span class="sep">:</span>
                            <span class="val"><span class="inv-edit inv-edit-wrap" id="fldInvoiceNo" contenteditable="true" role="textbox"><?= e($nextInvoice) ?></span></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Payment Mode</span><span class="sep">:</span>
                            <span class="val"><span class="inv-edit inv-edit-wrap" id="fldPayMode" contenteditable="true" role="textbox"><?= e((string) ($editBill['payment_mode'] ?? 'Cash on Delivery (COD)')) ?></span></span>
                        </div>
                    </div>
                </div>
                <div class="inv-cust-col">
                    <h3 class="inv-cust-heading">Customer Details</h3>
                    <div class="inv-cust-list">
                        <div class="inv-row-kv">
                            <span class="lbl">Name</span><span class="sep">:</span>
                            <span class="val"><input class="inv-edit" id="fldCustName" placeholder="Customer name" value="<?= e((string) ($editBill['customer_name'] ?? '')) ?>"></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Phone</span><span class="sep">:</span>
                            <span class="val"><input class="inv-edit" id="fldCustPhone" placeholder="Phone" value="<?= e((string) ($editBill['customer_phone'] ?? '')) ?>"></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Address</span><span class="sep">:</span>
                            <span class="val"><textarea class="inv-edit" id="fldCustAddress" rows="2" placeholder="Address"><?= e((string) ($editBill['customer_address'] ?? '')) ?></textarea></span>
                        </div>
                        <div class="inv-row-kv">
                            <span class="lbl">Pin Code</span><span class="sep">:</span>
                            <span class="val"><input class="inv-edit" id="fldCustPin" placeholder="Pin code" value="<?= e((string) ($editBill['customer_pincode'] ?? '')) ?>"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="inv-table-wrap">
                <table class="inv-products-table">
                    <thead>
                        <tr>
                            <th style="width:7%;">No.</th>
                            <th class="col-name" style="width:32%;">Product Name</th>
                            <th style="width:12%;">Size</th>
                            <th style="width:14%;">Colour</th>
                            <th style="width:8%;">Qty</th>
                            <th style="width:13%;">Price (₹)</th>
                            <th style="width:14%;">Total (₹)</th>
                        </tr>
                    </thead>
                    <tbody id="ofbItemsBody"></tbody>
                </table>
            </div>
            <?php if (!$isStandalone): ?>
            <button type="button" class="ofb-add-row no-print" id="ofbBlankRowBtn">+ Add blank line</button>
            <?php endif; ?>

            <div class="inv-summary-container">
                <div class="inv-summary-box">
                    <div class="inv-summary-row">
                        <span class="s-label">Total Amount</span><span class="s-sep">:</span>
                        <span class="s-val" id="sumSubtotal">₹ 0</span>
                    </div>
                    <div class="inv-summary-row">
                        <span class="s-label">Discount</span><span class="s-sep">:</span>
                        <span class="s-val"><input class="inv-edit" id="fldDiscount" type="number" min="0" step="0.01" value="<?= e((string) ($editBill['discount_amount'] ?? '0')) ?>"></span>
                    </div>
                    <div class="inv-summary-row">
                        <span class="s-label">Shipping</span><span class="s-sep">:</span>
                        <span class="s-val"><input class="inv-edit" id="fldShipping" type="number" min="0" step="0.01" value="<?= e((string) ($editBill['shipping_fee'] ?? '0')) ?>"></span>
                    </div>
                    <div class="inv-summary-row grand-total-row">
                        <span class="s-label">Grand Total</span><span class="s-sep">:</span>
                        <span class="s-val" id="sumGrand">₹ 0</span>
                    </div>
                </div>
            </div>

            <div class="inv-footer-grid">
                <div class="inv-footer-barcode">
                    <div class="inv-barcode-title">Order No: <span id="ofbBarcodeLabel"><?= e($nextOrder) ?></span></div>
                    <div class="inv-barcode-svg-wrap" id="ofbBarcodeWrap"><?= $barcodeSvg ?></div>
                </div>
                <div class="inv-footer-love">
                    <div class="inv-love-script">Packed with love</div>
                    <div class="inv-love-heart">♥</div>
                    <div class="inv-love-brand"><?= e($storeDisplayName) ?></div>
                </div>
                <div class="inv-footer-help">
                    <div class="inv-qr-box" id="ofbQrBox"></div>
                    <div class="inv-help-text">
                        <div class="inv-help-title">Need help?</div>
                        <div class="inv-help-sub">WhatsApp us at</div>
                        <div class="inv-help-phone"><?= e($formattedWhatsAppPhone) ?></div>
                    </div>
                </div>
            </div>
        </div>

<?php if (!$isStandalone): ?>
                </div>
            </main>
        </div>
    </div>
<?php else: ?>
    </div>
<?php endif; ?>

<datalist id="ofbSizeList">
    <?php foreach ($defaultSizes as $sz): ?>
        <option value="<?= e($sz) ?>"></option>
    <?php endforeach; ?>
</datalist>

<script>
const OFB = {
    csrf: <?= json_encode(csrf_token()) ?>,
    billId: <?= (int) $editId ?>,
    catalog: <?= json_encode($catalog, JSON_UNESCAPED_UNICODE) ?>,
    items: <?= json_encode($editItems, JSON_UNESCAPED_UNICODE) ?>,
    theme: <?= json_encode($storeThemeColor) ?>,
    barcodeUrl: <?= json_encode(asset('offline-billing.php')) ?>,
    pageUrl: <?= json_encode(asset('offline-billing.php')) ?>,
    verifyBase: <?= json_encode(rtrim((string) APP_URL, '/') . '/offline-billing.php') ?>,
    defaultSizes: <?= json_encode($defaultSizes) ?>,
    barcodeTimer: null
};

function fieldText(id) {
    var el = document.getElementById(id);
    if (!el) return '';
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
        return String(el.value || '').trim();
    }
    return String(el.textContent || '').replace(/\s+/g, ' ').trim();
}

function setFieldText(id, text) {
    var el = document.getElementById(id);
    if (!el) return;
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
        el.value = text;
    } else {
        el.textContent = text;
    }
}

function money(n) {
    n = Number(n) || 0;
    return (Math.round(n * 100) % 100 === 0) ? String(Math.round(n)) : n.toFixed(2);
}

function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function sizeOptions(item) {
    var sizes = (item.sizes && item.sizes.length) ? item.sizes : OFB.defaultSizes.slice();
    var current = item.size || '';
    if (current && sizes.indexOf(current) === -1) sizes = [current].concat(sizes);
    return sizes;
}

function colourOptions(item) {
    var colours = (item.colours && item.colours.length) ? item.colours.slice() : [];
    var current = item.colour || '';
    if (current && colours.indexOf(current) === -1) colours.unshift(current);
    return colours;
}

function renderProducts(query) {
    var box = document.getElementById('ofbProductList');
    if (!box) return;
    var q = (query || '').toLowerCase().trim();
    var list = OFB.catalog.filter(function(p) {
        if (!q) return true;
        return (p.name || '').toLowerCase().indexOf(q) !== -1 || (p.sku || '').toLowerCase().indexOf(q) !== -1;
    });
    if (!list.length) {
        box.innerHTML = '<div class="ofb-empty">No products found</div>';
        return;
    }
    box.innerHTML = list.map(function(p) {
        var img = p.image
            ? '<img src="' + escapeHtml(p.image) + '" alt="">'
            : '<span>IMG</span>';
        return '<div class="ofb-prod-card">' +
            '<div class="ofb-prod-img">' + img + '</div>' +
            '<div><div class="ofb-prod-name">' + escapeHtml(p.name) + '</div>' +
            '<div class="ofb-prod-meta">' + escapeHtml(p.sku || '') + ' · ₹' + money(p.price) + ' · Stk ' + (p.stock || 0) + '</div></div>' +
            '<button type="button" class="ofb-add-btn" data-add="' + p.id + '">Add</button></div>';
    }).join('');
}

function addProduct(id) {
    var p = OFB.catalog.find(function(x) { return Number(x.id) === Number(id); });
    if (!p) return;
    var size = (p.sizes && p.sizes[0]) ? p.sizes[0] : '-';
    var colour = (p.colours && p.colours[0]) ? p.colours[0] : '-';
    var price = p.price;
    if (p.variants && p.variants.length) {
        var v = p.variants[0];
        if (v.size) size = v.size;
        if (v.colour) colour = v.colour;
        if (v.price) price = v.price;
    }
    OFB.items.push({
        product_id: p.id,
        product_name: p.name,
        size: size,
        colour: colour,
        quantity: 1,
        unit_price: price,
        sizes: p.sizes || [],
        colours: p.colours || []
    });
    renderItems();
    renderCart();
}

function addBlankRow() {
    OFB.items.push({
        product_id: 0,
        product_name: '',
        size: '-',
        colour: '-',
        quantity: 1,
        unit_price: 0,
        sizes: OFB.defaultSizes.slice(),
        colours: []
    });
    renderItems();
    renderCart();
}

function hydrateItemOptions(item) {
    if (item.sizes && item.colours) return item;
    var p = OFB.catalog.find(function(x) { return Number(x.id) === Number(item.product_id); });
    item.sizes = (p && p.sizes && p.sizes.length) ? p.sizes : OFB.defaultSizes.slice();
    item.colours = (p && p.colours) ? p.colours : [];
    return item;
}

function renderItems() {
    var tbody = document.getElementById('ofbItemsBody');
    if (!tbody) return;
    if (!OFB.items.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="padding:22px;color:#64748b;">Add products from the list. Size and colour can be set on each line.</td></tr>';
        recalc();
        return;
    }
    tbody.innerHTML = OFB.items.map(function(item, idx) {
        item = hydrateItemOptions(item);
        var sizes = sizeOptions(item);
        var colours = colourOptions(item);
        var sizeHtml = '<input class="inv-edit ofb-size" list="ofbSizeList" data-i="' + idx + '" value="' + escapeHtml(item.size || '-') + '">';
        if (sizes.length && item.sizes && item.sizes.length) {
            sizeHtml = '<select class="inv-edit ofb-size" data-i="' + idx + '">' +
                sizes.map(function(s) {
                    return '<option value="' + escapeHtml(s) + '"' + ((item.size === s) ? ' selected' : '') + '>' + escapeHtml(s) + '</option>';
                }).join('') +
                '<option value="__custom__">Custom…</option></select>';
        }
        var colourHtml = '<input class="inv-edit ofb-colour" data-i="' + idx + '" value="' + escapeHtml(item.colour || '-') + '" placeholder="-">';
        if (colours.length) {
            colourHtml = '<select class="inv-edit ofb-colour" data-i="' + idx + '">' +
                ['-'].concat(colours.filter(function(c){ return c !== '-'; })).map(function(c) {
                    return '<option value="' + escapeHtml(c) + '"' + ((item.colour === c) ? ' selected' : '') + '>' + escapeHtml(c) + '</option>';
                }).join('') +
                '<option value="__custom__">Custom…</option></select>';
        }
        var line = (Number(item.quantity) || 0) * (Number(item.unit_price) || 0);
        return '<tr>' +
            '<td class="ofb-idx-cell">' + (idx + 1) +
            '<button type="button" class="ofb-remove no-print" data-del="' + idx + '" title="Remove">×</button></td>' +
            '<td class="col-name"><input class="inv-edit ofb-name" data-i="' + idx + '" value="' + escapeHtml(item.product_name) + '"></td>' +
            '<td>' + sizeHtml + '</td>' +
            '<td>' + colourHtml + '</td>' +
            '<td><input class="inv-edit ofb-qty" data-i="' + idx + '" type="number" min="1" step="1" value="' + (item.quantity || 1) + '"></td>' +
            '<td class="col-price"><input class="inv-edit ofb-price" data-i="' + idx + '" type="number" min="0" step="0.01" value="' + (item.unit_price || 0) + '"></td>' +
            '<td class="col-total">' + money(line) + '</td>' +
            '</tr>';
    }).join('');
    recalc();
}

function renderCart() {
    var box = document.getElementById('ofbCartList');
    if (!box) return;
    if (!OFB.items.length) {
        box.innerHTML = '<div class="ofb-empty" style="padding:12px;">Add products from the left</div>';
        recalc();
        return;
    }
    box.innerHTML = OFB.items.map(function(item, idx) {
        item = hydrateItemOptions(item);
        var sizes = sizeOptions(item);
        var sizeHtml = '<input class="ofb-size" list="ofbSizeList" data-i="' + idx + '" value="' + escapeHtml(item.size || '-') + '">';
        if (item.sizes && item.sizes.length) {
            sizeHtml = '<select class="ofb-size" data-i="' + idx + '">' +
                sizes.map(function(s) {
                    return '<option value="' + escapeHtml(s) + '"' + ((item.size === s) ? ' selected' : '') + '>' + escapeHtml(s) + '</option>';
                }).join('') + '</select>';
        }
        var colours = colourOptions(item);
        var colourHtml = '<input class="ofb-colour" data-i="' + idx + '" value="' + escapeHtml(item.colour || '-') + '">';
        if (colours.length) {
            colourHtml = '<select class="ofb-colour" data-i="' + idx + '">' +
                ['-'].concat(colours.filter(function(c){ return c !== '-'; })).map(function(c) {
                    return '<option value="' + escapeHtml(c) + '"' + ((item.colour === c) ? ' selected' : '') + '>' + escapeHtml(c) + '</option>';
                }).join('') + '</select>';
        }
        return '<div class="ofb-cart-row">' +
            '<div class="nm">' + escapeHtml(item.product_name || 'Item') + '</div>' +
            '<div class="ofb-cart-grid">' +
            sizeHtml + colourHtml +
            '<input class="ofb-qty" data-i="' + idx + '" type="number" min="1" value="' + (item.quantity || 1) + '">' +
            '<input class="ofb-price" data-i="' + idx + '" type="number" min="0" step="0.01" value="' + (item.unit_price || 0) + '">' +
            '<button type="button" class="ofb-cart-del" data-del="' + idx + '" title="Remove">×</button>' +
            '</div></div>';
    }).join('');
    recalc();
}

function activeStepRoot() {
    return document.querySelector('.ofb-step.active') || document;
}

function readItemsFromDom() {
    var root = activeStepRoot();
    OFB.items.forEach(function(item, idx) {
        var name = root.querySelector('.ofb-name[data-i="' + idx + '"]');
        var size = root.querySelector('.ofb-size[data-i="' + idx + '"]');
        var colour = root.querySelector('.ofb-colour[data-i="' + idx + '"]');
        var qty = root.querySelector('.ofb-qty[data-i="' + idx + '"]');
        var price = root.querySelector('.ofb-price[data-i="' + idx + '"]');
        if (name) item.product_name = name.value;
        if (size && size.value !== '__custom__') item.size = size.value || '-';
        if (colour && colour.value !== '__custom__') item.colour = colour.value || '-';
        if (qty) item.quantity = Math.max(1, parseInt(qty.value, 10) || 1);
        if (price) item.unit_price = Math.max(0, parseFloat(price.value) || 0);
    });
}

function recalc() {
    var sub = 0;
    OFB.items.forEach(function(it) {
        sub += (Number(it.quantity) || 0) * (Number(it.unit_price) || 0);
    });
    var discEl = document.getElementById('fldDiscount');
    var shipEl = document.getElementById('fldShipping');
    var disc = discEl ? Math.max(0, parseFloat(discEl.value) || 0) : 0;
    var ship = shipEl ? Math.max(0, parseFloat(shipEl.value) || 0) : 0;
    var grand = Math.max(0, sub - disc + ship);
    var subEl = document.getElementById('sumSubtotal');
    var grandEl = document.getElementById('sumGrand');
    var cartEl = document.getElementById('cartGrand');
    if (subEl) subEl.textContent = '₹ ' + money(sub);
    if (grandEl) grandEl.textContent = '₹ ' + money(grand);
    if (cartEl) cartEl.textContent = '₹ ' + money(grand);
}

function showStep(name) {
    var p = document.getElementById('ofbStepProducts');
    var i = document.getElementById('ofbStepInvoice');
    if (p) p.classList.toggle('active', name === 'products');
    if (i) i.classList.toggle('active', name === 'invoice');
    var pp = document.getElementById('pillProducts');
    var pi = document.getElementById('pillInvoice');
    if (pp) pp.classList.toggle('on', name === 'products');
    if (pi) pi.classList.toggle('on', name === 'invoice');
    window.scrollTo(0, 0);
}

function syncCartToInvoice() {
    setFieldText('fldCustName', fieldText('cartCustName') || fieldText('fldCustName'));
    setFieldText('fldCustPhone', fieldText('cartCustPhone') || fieldText('fldCustPhone'));
    var addr = document.getElementById('fldCustAddress');
    var cartAddr = document.getElementById('cartCustAddress');
    if (addr && cartAddr) addr.value = cartAddr.value;
    setFieldText('fldCustPin', fieldText('cartCustPin') || fieldText('fldCustPin'));
}

function syncInvoiceToCart() {
    var map = [['cartCustName', 'fldCustName'], ['cartCustPhone', 'fldCustPhone'], ['cartCustPin', 'fldCustPin']];
    map.forEach(function(pair) {
        var dest = document.getElementById(pair[0]);
        if (dest) dest.value = fieldText(pair[1]);
    });
    var cartAddr = document.getElementById('cartCustAddress');
    var addr = document.getElementById('fldCustAddress');
    if (cartAddr && addr) cartAddr.value = addr.value;
}

function goToInvoice() {
    readItemsFromDom();
    if (!OFB.items.length) {
        alert('Pehle kam se kam ek product Add karein.');
        return;
    }
    syncCartToInvoice();
    showStep('invoice');
    renderItems();
    recalc();
    refreshBarcode();
    renderQrCode(document.body.classList.contains('size-4x3') ? 48 : 68);
    saveBill().catch(function() {});
}

function goToProducts() {
    readItemsFromDom();
    syncInvoiceToCart();
    showStep('products');
    renderCart();
    recalc();
    var url = new URL(window.location.href);
    url.searchParams.set('step', 'products');
    window.history.replaceState({}, '', url.toString());
}

function toYmd(dmy) {
    var s = String(dmy || '').trim();
    var m = s.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
    if (m) return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
    return <?= json_encode(date('Y-m-d')) ?>;
}

function collectPayload() {
    readItemsFromDom();
    return {
        action: 'save_offline_bill',
        csrf_token: OFB.csrf,
        bill_id: String(OFB.billId || 0),
        invoice_number: fieldText('fldInvoiceNo'),
        order_number: fieldText('fldOrderNo'),
        invoice_date: toYmd(fieldText('fldOrderDate')),
        payment_mode: fieldText('fldPayMode') || 'Cash on Delivery (COD)',
        customer_name: fieldText('fldCustName'),
        customer_phone: fieldText('fldCustPhone'),
        customer_address: fieldText('fldCustAddress'),
        customer_pincode: fieldText('fldCustPin'),
        discount_amount: (document.getElementById('fldDiscount') ? document.getElementById('fldDiscount').value : '0') || '0',
        shipping_fee: (document.getElementById('fldShipping') ? document.getElementById('fldShipping').value : '0') || '0',
        print_size: (document.getElementById('invPageSizeSelect') ? document.getElementById('invPageSizeSelect').value : 'default') || 'default',
        items_json: JSON.stringify(OFB.items.map(function(it) {
            return {
                product_id: it.product_id || 0,
                product_name: it.product_name,
                size: it.size || '-',
                colour: it.colour || '-',
                quantity: it.quantity,
                unit_price: it.unit_price
            };
        }))
    };
}

function saveBill() {
    var status = document.getElementById('ofbStatus');
    if (status) status.textContent = 'Saving…';
    return fetch(OFB.pageUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams(collectPayload())
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (!data.success) {
            if (status) status.textContent = data.error || 'Save failed';
            status && (status.style.color = '#b91c1c');
            throw new Error(data.error || 'Save failed');
        }
        OFB.billId = data.id;
        if (data.invoice_number) setFieldText('fldInvoiceNo', data.invoice_number);
        if (data.order_number) setFieldText('fldOrderNo', data.order_number);
        if (status) {
            status.style.color = '#047857';
            status.textContent = 'Saved · ' + data.invoice_number;
        }
        var url = new URL(window.location.href);
        url.searchParams.set('id', String(data.id));
        if (document.getElementById('ofbStepInvoice') && document.getElementById('ofbStepInvoice').classList.contains('active')) {
            url.searchParams.set('step', 'invoice');
        } else if (document.getElementById('ofbStepProducts') && document.getElementById('ofbStepProducts').classList.contains('active')) {
            url.searchParams.set('step', 'products');
        }
        window.history.replaceState({}, '', url.toString());
        refreshBarcode();
        renderQrCode(document.body.classList.contains('size-4x3') ? 48 : 68);
        return data;
    });
}

function refreshBarcode() {
    clearTimeout(OFB.barcodeTimer);
    OFB.barcodeTimer = setTimeout(function() {
        var orderNo = fieldText('fldOrderNo') || 'ORDER';
        var label = document.getElementById('ofbBarcodeLabel');
        if (label) label.textContent = orderNo;
        var wrap = document.getElementById('ofbBarcodeWrap');
        if (!wrap) return;
        fetch(OFB.barcodeUrl + '?ajax=barcode&text=' + encodeURIComponent(orderNo) + '&color=' + encodeURIComponent(OFB.theme))
            .then(function(r) { return r.text(); })
            .then(function(svg) { wrap.innerHTML = svg; })
            .catch(function() {});
    }, 250);
}

function renderQrCode(dim) {
    var box = document.getElementById('ofbQrBox');
    if (!box || typeof QRCode === 'undefined') return;
    box.innerHTML = '';
    var url = OFB.verifyBase;
    if (OFB.billId) url += '?id=' + OFB.billId + '&standalone=1';
    new QRCode(box, {
        text: url,
        width: dim,
        height: dim,
        colorDark: OFB.theme,
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
}

function switchInvoiceSize(size) {
    document.body.classList.remove('size-4x3');
    if (size === '4x3') document.body.classList.add('size-4x3');
    var url = new URL(window.location.href);
    if (size === '4x3') url.searchParams.set('size', '4x3');
    else url.searchParams.delete('size');
    window.history.replaceState({}, '', url.toString());
    var dyn = document.getElementById('dynamicPageSizeStyle');
    if (dyn) {
        dyn.innerHTML = size === '4x3'
            ? '@page { size: 4in 3in; margin: 0; } @page card4x3 { size: 4in 3in; margin: 0; } @media print { @page { size: 4in 3in; margin: 0; } }'
            : '@page { size: A4 portrait; margin: 8mm; } @media print { @page { size: A4 portrait; margin: 8mm; } }';
    }
    renderQrCode(size === '4x3' ? 48 : 68);
}

function printInvoice() {
    var sizeSel = document.getElementById('invPageSizeSelect');
    var size = sizeSel ? sizeSel.value : 'default';
    var go = function() {
        if (size === '4x3' && <?= $isStandalone ? 'false' : 'true' ?>) {
            var url = new URL(window.location.href);
            url.searchParams.set('id', String(OFB.billId));
            url.searchParams.set('size', '4x3');
            url.searchParams.set('standalone', '1');
            url.searchParams.set('print', '1');
            var popup = window.open(url.toString(), 'ofbPrint4x3', 'width=920,height=720');
            if (!popup) window.print();
            return;
        }
        window.print();
    };
    saveBill().then(go).catch(function() { window.print(); });
}

document.addEventListener('DOMContentLoaded', function() {
    renderProducts('');
    OFB.items.forEach(hydrateItemOptions);
    renderItems();
    renderCart();
    renderQrCode(<?= $requestedSize === '4x3' ? 48 : 68 ?>);

    var search = document.getElementById('ofbProductSearch');
    if (search) search.addEventListener('input', function() { renderProducts(this.value); });

    var list = document.getElementById('ofbProductList');
    if (list) list.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-add]');
        if (btn) addProduct(btn.getAttribute('data-add'));
    });

    var goInv = document.getElementById('ofbGoInvoiceBtn');
    if (goInv) goInv.addEventListener('click', goToInvoice);
    var backProd = document.getElementById('ofbBackProductsBtn');
    if (backProd) backProd.addEventListener('click', goToProducts);
    var pillP = document.getElementById('pillProducts');
    var pillI = document.getElementById('pillInvoice');
    if (pillP) pillP.addEventListener('click', goToProducts);
    if (pillI) pillI.addEventListener('click', goToInvoice);

    var cartList = document.getElementById('ofbCartList');
    if (cartList) {
        cartList.addEventListener('click', function(e) {
            var del = e.target.closest('[data-del]');
            if (!del) return;
            readItemsFromDom();
            OFB.items.splice(parseInt(del.getAttribute('data-del'), 10), 1);
            renderCart();
            renderItems();
        });
        cartList.addEventListener('change', function() { readItemsFromDom(); renderCart(); });
        cartList.addEventListener('input', function() { readItemsFromDom(); recalc(); });
    }

    var blank = document.getElementById('ofbBlankRowBtn');
    if (blank) blank.addEventListener('click', addBlankRow);

    var itemsBody = document.getElementById('ofbItemsBody');
    if (itemsBody) {
    itemsBody.addEventListener('click', function(e) {
        var del = e.target.closest('[data-del]');
        if (!del) return;
        OFB.items.splice(parseInt(del.getAttribute('data-del'), 10), 1);
        renderItems();
        renderCart();
    });

    itemsBody.addEventListener('change', function(e) {
        var el = e.target;
        var i = parseInt(el.getAttribute('data-i'), 10);
        if (isNaN(i) || !OFB.items[i]) return;
        if (el.value === '__custom__') {
            var typed = window.prompt('Enter custom value', el.classList.contains('ofb-size') ? (OFB.items[i].size || '') : (OFB.items[i].colour || ''));
            if (typed === null) { renderItems(); return; }
            if (el.classList.contains('ofb-size')) {
                OFB.items[i].size = typed || '-';
                OFB.items[i].sizes = [];
            } else {
                OFB.items[i].colour = typed || '-';
                OFB.items[i].colours = [];
            }
            renderItems();
            renderCart();
            return;
        }
        readItemsFromDom();
        renderItems();
    });
    itemsBody.addEventListener('input', function() {
        readItemsFromDom();
        recalc();
    });
    }

    var saveBtn = document.getElementById('ofbSaveBtn');
    var printBtn = document.getElementById('ofbPrintBtn');
    var disc = document.getElementById('fldDiscount');
    var ship = document.getElementById('fldShipping');
    if (disc) disc.addEventListener('input', recalc);
    if (ship) ship.addEventListener('input', recalc);
    document.querySelectorAll('.inv-edit-wrap').forEach(function(el) {
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
        el.addEventListener('paste', function(e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
            text = text.replace(/\s+/g, ' ').trim();
            document.execCommand('insertText', false, text);
        });
    });
    var orderEl = document.getElementById('fldOrderNo');
    if (orderEl) orderEl.addEventListener('input', refreshBarcode);
    if (saveBtn) saveBtn.addEventListener('click', function() { saveBill().catch(function(){}); });
    if (printBtn) printBtn.addEventListener('click', printInvoice);

    <?php if ($autoPrint): ?>
        setTimeout(function() { window.print(); }, <?= $requestedSize === '4x3' ? 500 : 350 ?>);
    <?php endif; ?>
});
</script>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/orders_db.php';

function invoice_pdf_signing_secret(): string {
    if (defined('OMINIFLOW_WA_TOKEN') && trim((string) OMINIFLOW_WA_TOKEN) !== '') {
        return (string) OMINIFLOW_WA_TOKEN;
    }
    return 'ominiflow-invoice-pdf-v1';
}

function invoice_pdf_access_token(int $invoiceId, int $businessId): string {
    return hash_hmac('sha256', $businessId . ':' . $invoiceId, invoice_pdf_signing_secret());
}

function invoice_pdf_public_token(int $invoiceId, int $businessId): string {
    return substr(invoice_pdf_access_token($invoiceId, $businessId), 0, 32);
}

function invoice_pdf_verify_token(int $invoiceId, int $businessId, string $token): bool {
    $token = strtolower(trim($token));
    if ($token === '') {
        return false;
    }
    $expected = strtolower(invoice_pdf_access_token($invoiceId, $businessId));
    if (hash_equals($expected, $token)) {
        return true;
    }
    $short = substr($expected, 0, 32);
    return strlen($token) >= 32 && hash_equals($short, substr($token, 0, 32));
}

function invoice_pdf_web_dir(): string {
    $dir = dirname(__DIR__) . '/wa-invoices';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $deny = $dir . '/index.html';
    if (!is_file($deny)) {
        @file_put_contents($deny, '');
    }
    return $dir;
}

function invoice_pdf_web_filename(int $invoiceId, int $businessId): string {
    return $invoiceId . '-' . $businessId . '-' . invoice_pdf_public_token($invoiceId, $businessId) . '.pdf';
}

function invoice_pdf_storage_dir(int $businessId): string {
    $base = dirname(__DIR__) . '/storage/invoices/' . $businessId;
    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }
    return $base;
}

function invoice_pdf_escape(string $text): string {
    $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function build_simple_invoice_pdf(array $invoice): string {
    $storeName = (string) ($invoice['store']['business_name'] ?? $invoice['store']['store_name'] ?? APP_NAME);
    $invNum = (string) ($invoice['invoice_number'] ?? '');
    $orderNum = (string) ($invoice['order_number'] ?? '');
    $date = !empty($invoice['invoice_date']) ? date('d M Y, h:i A', strtotime((string) $invoice['invoice_date'])) : date('d M Y, h:i A');
    $customer = (string) ($invoice['customer_name'] ?? 'Customer');
    $phone = (string) ($invoice['customer_phone'] ?? '');
    $payMethod = strtoupper((string) ($invoice['payment_method'] ?? ''));
    $payStatus = ucfirst((string) ($invoice['payment_status'] ?? 'paid'));

    $lines = [
        $storeName,
        'TAX INVOICE',
        'Invoice: ' . $invNum,
        'Order: ' . $orderNum,
        'Date: ' . $date,
        'Customer: ' . $customer,
    ];
    if ($phone !== '') {
        $lines[] = 'Phone: ' . $phone;
    }
    $lines[] = 'Payment: ' . $payMethod . ' (' . $payStatus . ')';
    $lines[] = '----------------------------------------';

    foreach (($invoice['items'] ?? []) as $item) {
        $name = substr((string) ($item['product_name'] ?? 'Item'), 0, 42);
        $qty = (int) ($item['quantity'] ?? 1);
        $total = number_format((float) ($item['line_total'] ?? 0), 2);
        $lines[] = $name;
        $lines[] = '  Qty ' . $qty . '  Line Rs.' . $total;
    }

    $lines[] = '----------------------------------------';
    $lines[] = 'Subtotal Rs.' . number_format((float) ($invoice['subtotal'] ?? 0), 2);
    $lines[] = 'Tax Rs.' . number_format((float) ($invoice['tax_amount'] ?? 0), 2);
    $lines[] = 'Grand Total Rs.' . number_format((float) ($invoice['total_amount'] ?? 0), 2);
    $lines[] = 'Thank you for shopping with us!';

    $y = 800;
    $stream = "BT /F1 10 Tf 14 TL\n";
    foreach ($lines as $line) {
        $stream .= '1 0 0 1 40 ' . $y . " Tm (" . invoice_pdf_escape($line) . ") Tj T*\n";
        $y -= 16;
        if ($y < 60) {
            break;
        }
    }
    $stream .= 'ET';
    $len = strlen($stream);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    $offsets[1] = strlen($pdf);
    $pdf .= "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n";
    $offsets[2] = strlen($pdf);
    $pdf .= "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n";
    $offsets[3] = strlen($pdf);
    $pdf .= "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>endobj\n";
    $offsets[4] = strlen($pdf);
    $pdf .= '4 0 obj<< /Length ' . $len . " >>stream\n" . $stream . "\nendstream endobj\n";
    $offsets[5] = strlen($pdf);
    $pdf .= "5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n";
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";

    return $pdf;
}

function ensure_invoice_pdf_file(int $invoiceId, int $businessId): ?string {
    $invoice = get_invoice_by_id($invoiceId, $businessId);
    if (!$invoice) {
        return null;
    }
    $dir = invoice_pdf_storage_dir($businessId);
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($invoice['invoice_number'] ?? ('inv-' . $invoiceId)));
    $path = $dir . '/' . $safeName . '.pdf';
    $pdf = build_simple_invoice_pdf($invoice);
    if (@file_put_contents($path, $pdf) === false) {
        return null;
    }
    @copy($path, invoice_pdf_web_dir() . '/' . invoice_pdf_web_filename($invoiceId, $businessId));
    return $path;
}

function offline_bill_pdf_access_token(int $billId, int $businessId): string {
    return hash_hmac('sha256', 'ofb:' . $businessId . ':' . $billId, invoice_pdf_signing_secret());
}

function offline_bill_pdf_verify_token(int $billId, int $businessId, string $token): bool {
    $token = strtolower(trim($token));
    if ($token === '') {
        return false;
    }
    $expected = strtolower(offline_bill_pdf_access_token($billId, $businessId));
    if (hash_equals($expected, $token)) {
        return true;
    }
    $short = substr($expected, 0, 32);
    return strlen($token) >= 32 && hash_equals($short, substr($token, 0, 32));
}

function offline_bill_pdf_web_filename(int $billId, int $businessId): string {
    return 'ofb-' . $billId . '-' . $businessId . '-' . substr(offline_bill_pdf_access_token($billId, $businessId), 0, 32) . '.pdf';
}

function offline_bill_pdf_public_url(int $billId, int $businessId): string {
    $path = 'wa-invoices/' . offline_bill_pdf_web_filename($billId, $businessId);
    if (function_exists('is_local_app_host') && !is_local_app_host()) {
        return pos_public_url($path);
    }
    return pos_webhook_public_url($path);
}

function ensure_offline_bill_pdf_file(int $billId, int $businessId): ?string {
    require_once __DIR__ . '/offline_billing_db.php';
    $bill = get_offline_bill_by_id($billId, $businessId);
    if (!$bill) {
        return null;
    }

    $storeName = defined('APP_NAME') ? (string) APP_NAME : 'Store';
    if (function_exists('get_mobile_store_settings')) {
        $brand = get_mobile_store_settings($businessId);
        $display = trim((string) ($brand['display_name'] ?? ''));
        if ($display !== '') {
            $storeName = $display;
        }
    }

    $invoice = [
        'invoice_number' => (string) ($bill['invoice_number'] ?? ''),
        'order_number' => (string) ($bill['order_number'] ?? ''),
        'invoice_date' => (string) ($bill['invoice_date'] ?? ''),
        'customer_name' => (string) ($bill['customer_name'] ?? 'Customer'),
        'customer_phone' => (string) ($bill['customer_phone'] ?? ''),
        'payment_method' => (string) ($bill['payment_mode'] ?? 'COD'),
        'payment_status' => 'unpaid',
        'items' => $bill['items'] ?? [],
        'subtotal' => (float) ($bill['subtotal'] ?? 0),
        'tax_amount' => 0.0,
        'total_amount' => (float) ($bill['grand_total'] ?? 0),
        'store' => ['business_name' => $storeName],
    ];

    $dir = invoice_pdf_storage_dir($businessId);
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($invoice['invoice_number'] ?? ('ofb-' . $billId)));
    $path = $dir . '/ofb-' . $safeName . '.pdf';
    $pdf = build_simple_invoice_pdf($invoice);
    if (@file_put_contents($path, $pdf) === false) {
        return null;
    }
    @copy($path, invoice_pdf_web_dir() . '/' . offline_bill_pdf_web_filename($billId, $businessId));
    return $path;
}

function invoice_pdf_public_url(int $invoiceId, int $businessId): string {
    $path = 'wa-invoices/' . invoice_pdf_web_filename($invoiceId, $businessId);
    if (function_exists('is_local_app_host') && !is_local_app_host()) {
        return pos_public_url($path);
    }
    return pos_webhook_public_url($path);
}

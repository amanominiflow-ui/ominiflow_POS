<?php

declare(strict_types=1);

require_once __DIR__ . '/storefront_db.php';
require_once __DIR__ . '/invoice_pdf.php';

function send_storefront_order_invoice_whatsapp(int $businessId, int $orderId, string $customerPhone, array $orderResult = []): array {
    $brand = get_mobile_store_settings($businessId);
    if (empty($brand['wa_auto_send_invoices'])) {
        return ['success' => false, 'skipped' => true, 'error' => 'Auto-send invoices is disabled.'];
    }

    $orderPayStatus = strtolower((string) ($orderResult['payment_status'] ?? ''));
    if (!in_array($orderPayStatus, ['paid', 'pending'], true)) {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Invoice WhatsApp is not sent for this payment state.',
        ];
    }

    if (!get_storefront_shopper($businessId)) {
        return ['success' => false, 'skipped' => true, 'error' => 'No logged-in customer session for WhatsApp delivery.'];
    }
    $shopper = function_exists('refresh_storefront_shopper')
        ? (refresh_storefront_shopper($businessId) ?? get_storefront_shopper($businessId))
        : get_storefront_shopper($businessId);
    if (!$shopper) {
        return ['success' => false, 'skipped' => true, 'error' => 'No logged-in customer session for WhatsApp delivery.'];
    }

    $targetPhone = trim((string) ($shopper['phone'] ?? ''));
    if ($targetPhone === '') {
        return ['success' => false, 'error' => 'Logged-in customer has no mobile number on file.'];
    }

    $invoiceId = (int) ($orderResult['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        $inv = get_invoice_by_order_id($orderId, $businessId);
        $invoiceId = (int) ($inv['id'] ?? 0);
    }
    if ($invoiceId <= 0) {
        return ['success' => false, 'error' => 'Invoice not found for WhatsApp delivery.'];
    }

    $waPhone = format_storefront_whatsapp_phone($targetPhone);
    if (strlen($waPhone) < 10) {
        return ['success' => false, 'error' => 'Customer WhatsApp number is missing or invalid.'];
    }

    $gateway = get_business_whatsapp_gateway($businessId);
    if (empty($gateway['configured'])) {
        return ['success' => false, 'error' => (string) ($gateway['error'] ?? 'WhatsApp is not connected for this store.')];
    }

    if (!ensure_invoice_pdf_file($invoiceId, $businessId)) {
        return ['success' => false, 'error' => 'Could not generate invoice PDF.'];
    }

    $invoice = get_invoice_by_id($invoiceId, $businessId);
    $invNum = (string) ($invoice['invoice_number'] ?? ('INV-' . $invoiceId));
    $orderNum = (string) ($orderResult['order_number'] ?? $invoice['order_number'] ?? '');
    $storeName = (string) ($brand['display_name'] ?? 'Store');
    $pdfUrl = invoice_pdf_public_url($invoiceId, $businessId);
    $caption = 'Thank you! Your order ' . ($orderNum !== '' ? $orderNum : '') . ' is confirmed. Tax invoice ' . $invNum . ' from ' . $storeName . '.';
    if ($orderPayStatus !== 'paid') {
        $caption .= ' Payment is due on delivery / at pickup.';
    }

    $isMeta = !empty($gateway['is_meta_graph']);
    $payloads = [];

    if ($isMeta) {
        $payloads[] = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $waPhone,
            'type' => 'document',
            'document' => [
                'link' => $pdfUrl,
                'filename' => $invNum . '.pdf',
                'caption' => $caption,
            ],
        ];
    } else {
        $payloads[] = [
            'token' => (string) $gateway['token'],
            'phone' => $waPhone,
            'message' => $caption . "\n" . $pdfUrl,
        ];
        $payloads[] = [
            'token' => (string) $gateway['token'],
            'phone' => $waPhone,
            'type' => 'document',
            'document_url' => $pdfUrl,
            'filename' => $invNum . '.pdf',
            'caption' => $caption,
        ];
    }

    $apiUrl = (string) $gateway['api_url'];
    $token = (string) $gateway['token'];
    $lastRaw = null;
    $httpCode = 0;

    foreach ($payloads as $payload) {
        $sendToken = $token !== '' ? $token : (string) ($payload['token'] ?? '');
        try {
            $posted = post_whatsapp_json($apiUrl, $sendToken, $payload);
            $lastRaw = $posted['raw'];
            $httpCode = (int) ($posted['http_code'] ?? 0);
            if (!empty($posted['success'])) {
                return [
                    'success' => true,
                    'phone' => $waPhone,
                    'invoice_id' => $invoiceId,
                    'pdf_url' => $pdfUrl,
                    'http_code' => $httpCode,
                ];
            }
        } catch (Throwable $e) {
            error_log('Invoice WhatsApp send error: ' . $e->getMessage());
        }
    }

    return [
        'success' => false,
        'error' => wa_gateway_error_message($lastRaw, $httpCode),
        'phone' => $waPhone,
        'pdf_url' => $pdfUrl,
    ];
}

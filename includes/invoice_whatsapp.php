<?php

declare(strict_types=1);

require_once __DIR__ . '/storefront_db.php';
require_once __DIR__ . '/invoice_pdf.php';

/**
 * @return list<array{url: string, payload: array<string, mixed>}>
 */
function build_invoice_whatsapp_send_attempts(
    array $gateway,
    string $waPhone,
    string $caption,
    string $pdfUrl,
    string $invNum,
    ?string $metaMediaId = null
): array {
    $token = (string) ($gateway['token'] ?? '');
    $companyId = (int) ($gateway['company_id'] ?? 0);
    $isMeta = !empty($gateway['is_meta_graph']);
    $attempts = [];

    if ($isMeta) {
        $metaBase = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $waPhone,
            'type' => 'document',
        ];
        if ($metaMediaId !== null && $metaMediaId !== '') {
            $attempts[] = [
                'url' => (string) $gateway['api_url'],
                'payload' => $metaBase + [
                    'document' => [
                        'id' => $metaMediaId,
                        'filename' => $invNum . '.pdf',
                        'caption' => $caption,
                    ],
                ],
            ];
        }
        $attempts[] = [
            'url' => (string) $gateway['api_url'],
            'payload' => $metaBase + [
                'document' => [
                    'link' => $pdfUrl,
                    'filename' => $invNum . '.pdf',
                    'caption' => $caption,
                ],
            ],
        ];
        $attempts[] = [
            'url' => (string) $gateway['api_url'],
            'payload' => [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $waPhone,
                'type' => 'text',
                'text' => [
                    'preview_url' => true,
                    'body' => $caption . "\n\nDownload invoice PDF:\n" . $pdfUrl,
                ],
            ],
        ];
        return $attempts;
    }

    $wpboxDoc = [
        'token' => $token,
        'phone' => $waPhone,
        'type' => 'document',
        'document_url' => $pdfUrl,
        'link' => $pdfUrl,
        'url' => $pdfUrl,
        'filename' => $invNum . '.pdf',
        'caption' => $caption,
        'message' => $caption,
    ];
    if ($companyId > 0) {
        $wpboxDoc['company_id'] = $companyId;
    }

    $wpboxText = [
        'token' => $token,
        'phone' => $waPhone,
        'message' => $caption . "\n\nDownload invoice PDF:\n" . $pdfUrl,
    ];
    if ($companyId > 0) {
        $wpboxText['company_id'] = $companyId;
    }

    $apiUrls = whatsapp_outbound_api_urls((string) ($gateway['api_url'] ?? ''));
    $apiUrls = array_values(array_filter(
        $apiUrls,
        static fn (string $url): bool => stripos($url, 'sendtemplate') === false
    ));
    if ($apiUrls === []) {
        $apiUrls = whatsapp_outbound_api_urls((string) ($gateway['api_url'] ?? ''));
    }
    foreach ($apiUrls as $url) {
        $attempts[] = ['url' => $url, 'payload' => $wpboxDoc];
        $attempts[] = ['url' => $url, 'payload' => $wpboxText];
    }

    return $attempts;
}

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

    $pdfPath = ensure_invoice_pdf_file($invoiceId, $businessId);
    if ($pdfPath === null) {
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

    $metaMediaId = null;
    if (!empty($gateway['is_meta_graph'])) {
        $phoneId = trim((string) ($gateway['phone_number_id'] ?? ''));
        $metaMediaId = upload_whatsapp_meta_document($phoneId, (string) $gateway['token'], $pdfPath);
    }

    $attempts = build_invoice_whatsapp_send_attempts(
        $gateway,
        $waPhone,
        $caption,
        $pdfUrl,
        $invNum,
        $metaMediaId
    );

    $token = (string) $gateway['token'];
    $lastRaw = null;
    $httpCode = 0;
    $lastError = 'WhatsApp invoice could not be delivered.';

    foreach ($attempts as $attempt) {
        $apiUrl = trim((string) ($attempt['url'] ?? ''));
        $payload = $attempt['payload'] ?? null;
        if ($apiUrl === '' || !is_array($payload)) {
            continue;
        }
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
            $lastError = wa_gateway_error_message($lastRaw, $httpCode);
        } catch (Throwable $e) {
            error_log('Invoice WhatsApp send error: ' . $e->getMessage());
            $lastError = $e->getMessage();
        }
    }

    error_log(sprintf(
        'Storefront invoice WhatsApp failed biz=%d order=%d phone=%s pdf=%s err=%s',
        $businessId,
        $orderId,
        $waPhone,
        $pdfUrl,
        $lastError
    ));

    return [
        'success' => false,
        'error' => $lastError,
        'phone' => $waPhone,
        'pdf_url' => $pdfUrl,
    ];
}

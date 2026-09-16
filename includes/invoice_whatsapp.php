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

function resolve_invoice_whatsapp_phone(string $customerPhone, array $orderResult = [], int $businessId = 0): string {
    $candidates = [
        trim($customerPhone),
        trim((string) ($orderResult['customer_phone'] ?? '')),
    ];
    if ($businessId > 0 && function_exists('get_storefront_shopper')) {
        $shopper = get_storefront_shopper($businessId);
        if (is_array($shopper)) {
            $candidates[] = trim((string) ($shopper['phone'] ?? ''));
        }
    }
    foreach ($candidates as $phone) {
        if ($phone !== '') {
            return $phone;
        }
    }
    return '';
}

/**
 * Send tax-invoice PDF to a customer WhatsApp number.
 * Used by POS checkout and (optionally) online storefront.
 * Never requires a logged-in storefront session.
 */
function send_order_invoice_whatsapp(int $businessId, int $orderId, string $customerPhone = '', array $orderResult = []): array {
    $brand = get_mobile_store_settings($businessId);
    if (empty($brand['wa_auto_send_invoices'])) {
        return ['success' => false, 'skipped' => true, 'error' => 'Auto-send invoices is disabled.'];
    }

    $orderPayStatus = strtolower(trim((string) ($orderResult['payment_status'] ?? '')));
    if ($orderPayStatus === '') {
        $orderPayStatus = 'paid';
    }
    if (!in_array($orderPayStatus, ['paid', 'pending'], true)) {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Invoice WhatsApp is not sent for this payment state.',
        ];
    }

    $targetPhone = resolve_invoice_whatsapp_phone($customerPhone, $orderResult, $businessId);
    if ($targetPhone === '') {
        return ['success' => false, 'skipped' => true, 'error' => 'Customer has no WhatsApp number on file.'];
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

function send_storefront_order_invoice_whatsapp(int $businessId, int $orderId, string $customerPhone, array $orderResult = []): array {
    return send_order_invoice_whatsapp($businessId, $orderId, $customerPhone, $orderResult);
}

/**
 * Attach WhatsApp invoice send result to a completed POS order payload.
 * Failures never undo the sale.
 *
 * @param array<string, mixed> $orderResult
 * @return array<string, mixed>
 */
function attach_pos_invoice_whatsapp(array $orderResult, int $businessId): array {
    if (empty($orderResult['success'])) {
        return $orderResult;
    }
    if (!empty($orderResult['synced_existing'])) {
        $orderResult['whatsapp_invoice'] = [
            'success' => false,
            'skipped' => true,
            'error' => 'Already synced; invoice was not re-sent.',
        ];
        return $orderResult;
    }

    try {
        $orderResult['whatsapp_invoice'] = send_order_invoice_whatsapp(
            $businessId,
            (int) ($orderResult['order_id'] ?? 0),
            (string) ($orderResult['customer_phone'] ?? ''),
            $orderResult
        );
    } catch (Throwable $e) {
        error_log('POS invoice WhatsApp: ' . $e->getMessage());
        $orderResult['whatsapp_invoice'] = [
            'success' => false,
            'error' => 'Could not send invoice on WhatsApp.',
        ];
    }

    return $orderResult;
}

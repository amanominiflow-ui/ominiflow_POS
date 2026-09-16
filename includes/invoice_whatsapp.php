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
        // Text first. Document-by-link makes Meta fetch the PDF and can hang
        // until PHP times out ("WhatsApp gateway did not respond").
        $attempts[] = [
            'url' => (string) $gateway['api_url'],
            'timeout' => 8,
            'payload' => [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $waPhone,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $caption . "\n\nDownload invoice PDF:\n" . $pdfUrl,
                ],
            ],
        ];
        if ($metaMediaId !== null && $metaMediaId !== '') {
            $attempts[] = [
                'url' => (string) $gateway['api_url'],
                'payload' => [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $waPhone,
                    'type' => 'document',
                    'document' => [
                        'id' => $metaMediaId,
                        'filename' => $invNum . '.pdf',
                        'caption' => $caption,
                    ],
                ],
            ];
        }
        if ($companyId > 0 && !str_starts_with($token, 'EAA')) {
            foreach (build_wpbox_invoice_send_attempts($token, $companyId, $waPhone, $caption, $pdfUrl, $invNum, (string) ($gateway['api_url'] ?? '')) as $wpboxAttempt) {
                $attempts[] = $wpboxAttempt;
            }
        }
        return $attempts;
    }

    return build_wpbox_invoice_send_attempts($token, $companyId, $waPhone, $caption, $pdfUrl, $invNum, (string) ($gateway['api_url'] ?? ''));
}

/**
 * @return list<array{url: string, payload: array<string, mixed>}>
 */
function build_wpbox_invoice_send_attempts(
    string $token,
    int $companyId,
    string $waPhone,
    string $caption,
    string $pdfUrl,
    string $invNum,
    string $apiUrl
): array {
    $sendMessageUrl = 'https://whatsapp.ominiflow.com/api/wpbox/sendmessage';
    foreach (whatsapp_outbound_api_urls($apiUrl) as $url) {
        $leaf = strtolower((string) basename((string) (parse_url($url, PHP_URL_PATH) ?? '')));
        if ($leaf === 'sendmessage') {
            $sendMessageUrl = $url;
            break;
        }
    }

    $textPayload = [
        'token' => $token,
        'phone' => $waPhone,
        'message' => $caption . "\n\nDownload invoice PDF:\n" . $pdfUrl,
    ];
    if ($companyId > 0) {
        $textPayload['company_id'] = $companyId;
    }

    // Text + link only. sendmedia makes WPBox fetch invoice-pdf.php while this
    // PHP request is still waiting, which deadlocks until curl times out.
    return [
        ['url' => $sendMessageUrl, 'payload' => $textPayload, 'timeout' => 8],
    ];
}

function resolve_invoice_whatsapp_phone(string $customerPhone, array $orderResult = [], int $businessId = 0): string {
    $candidates = [];
    if ($businessId > 0 && function_exists('get_storefront_shopper')) {
        $shopper = get_storefront_shopper($businessId);
        if (is_array($shopper)) {
            $candidates[] = trim((string) ($shopper['phone'] ?? ''));
        }
    }
    $candidates[] = trim($customerPhone);
    $candidates[] = trim((string) ($orderResult['customer_phone'] ?? ''));
    foreach ($candidates as $phone) {
        if ($phone !== '') {
            return $phone;
        }
    }
    return '';
}

function invoice_whatsapp_sent_flag_path(int $businessId, int $invoiceId): string {
    return invoice_pdf_storage_dir($businessId) . '/wa-sent-' . $invoiceId . '.flag';
}

function invoice_whatsapp_already_sent(int $businessId, int $invoiceId): bool {
    return $invoiceId > 0 && is_file(invoice_whatsapp_sent_flag_path($businessId, $invoiceId));
}

function mark_invoice_whatsapp_sent(int $businessId, int $invoiceId): void {
    if ($invoiceId <= 0) {
        return;
    }
    @file_put_contents(invoice_whatsapp_sent_flag_path($businessId, $invoiceId), date('c'));
}

function send_invoice_whatsapp_via_saved_curl(
    int $businessId,
    string $waPhone,
    string $pdfUrl,
    string $invNum,
    string $caption
): array {
    $brand = get_mobile_store_settings($businessId);
    $raw = trim((string) ($brand['wa_invoice_curl_payload'] ?? ''));
    if ($raw === '') {
        $raw = trim((string) ($brand['wa_invoice_curl_raw'] ?? ''));
    }
    if ($raw === '') {
        return [
            'success' => false,
            'error' => 'Paste the invoice PDF WhatsApp cURL in Settings → WhatsApp (separate from the OTP cURL).',
        ];
    }

    $parsed = json_decode($raw, true);
    $apiUrl = trim((string) ($brand['wa_invoice_api_url'] ?? ''));
    $fromRaw = parse_whatsapp_curl_command((string) ($brand['wa_invoice_curl_raw'] ?? $raw));
    if (!is_array($parsed)) {
        $parsed = is_array($fromRaw['payload'] ?? null) ? $fromRaw['payload'] : null;
    }
    if ($apiUrl === '') {
        $apiUrl = trim((string) ($fromRaw['wa_api_url'] ?? ''));
    }

    if (!is_array($parsed)) {
        return ['success' => false, 'error' => 'Invoice WhatsApp cURL could not be parsed. Paste a full cURL with JSON body.'];
    }
    if ($apiUrl === '') {
        return ['success' => false, 'error' => 'Invoice WhatsApp API URL is missing in the pasted cURL.'];
    }

    $token = trim((string) ($parsed['token'] ?? $parsed['access_token'] ?? ''));
    if ($token === '') {
        $token = trim((string) ($fromRaw['wa_token'] ?? ''));
    }
    $invoiceCompanyId = (int) ($parsed['company_id'] ?? $fromRaw['wa_company_id'] ?? 0);

    $isWpbox = stripos($apiUrl, '/api/wpbox') !== false || stripos($apiUrl, 'whatsapp.ominiflow.com') !== false;
    if ($token === '') {
        $otpToken = trim((string) ($brand['wa_token'] ?? ''));
        $otpUrl = trim((string) ($brand['wa_api_url'] ?? ''));
        $otpHost = (string) (parse_url($otpUrl, PHP_URL_HOST) ?? '');
        $invHost = (string) (parse_url($apiUrl, PHP_URL_HOST) ?? '');
        $sameHost = $otpHost !== '' && $invHost !== '' && strcasecmp($otpHost, $invHost) === 0;
        if ($otpToken !== '' && !str_starts_with($otpToken, 'EAA') && ($isWpbox || $sameHost)) {
            $token = $otpToken;
        }
    }
    if ($token === '') {
        return ['success' => false, 'error' => 'Invoice cURL has no token. Paste the full invoice/utility cURL from WPBox (the JSON must include "token").'];
    }

    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $invNum) . '.pdf';
    $payload = inject_invoice_into_wa_payload($parsed, $waPhone, $pdfUrl, $invNum, $filename, $caption);

    $invoiceTmpl = trim((string) ($brand['wa_invoice_template_name'] ?? ''));
    $invoiceLang = trim((string) ($brand['wa_invoice_template_lang'] ?? ''));
    if ($invoiceTmpl !== '') {
        $payload['template_name'] = $invoiceTmpl;
        if (isset($payload['template']) && is_array($payload['template'])) {
            $payload['template']['name'] = $invoiceTmpl;
        }
    }
    if ($invoiceLang !== '') {
        $payload['template_language'] = $invoiceLang;
        if (isset($payload['template']) && is_array($payload['template'])) {
            if (isset($payload['template']['language']) && is_array($payload['template']['language'])) {
                $payload['template']['language']['code'] = $invoiceLang;
            } else {
                $payload['template']['language'] = $invoiceLang;
            }
        }
    }

    if ($isWpbox || empty($payload['messaging_product'])) {
        $payload['token'] = $token;
        if ($invoiceCompanyId > 0) {
            $payload['company_id'] = $invoiceCompanyId;
        } else {
            unset($payload['company_id']);
        }
    }

    $looksLikeTemplate = !empty($payload['template_name'])
        || isset($payload['template'])
        || $invoiceTmpl !== '';
    if ($looksLikeTemplate && preg_match('#/api/wpbox/(sendmessage|sendmedia)(/|$)#i', $apiUrl)) {
        $apiUrl = whatsapp_template_api_url($apiUrl);
    }

    $posted = post_whatsapp_json($apiUrl, $token, $payload, 25);
    if (!empty($posted['success'])) {
        return [
            'success' => true,
            'phone' => $waPhone,
            'pdf_url' => $pdfUrl,
            'http_code' => (int) ($posted['http_code'] ?? 0),
        ];
    }

    $error = wa_gateway_error_message($posted['raw'] ?? null, (int) ($posted['http_code'] ?? 0));
    if (function_exists('wa_error_is_timeout') && wa_error_is_timeout($error)) {
        $error = 'WhatsApp timed out while sending the invoice cURL. Check the invoice template cURL in Settings → WhatsApp.';
    }

    return [
        'success' => false,
        'error' => $error,
        'phone' => $waPhone,
        'pdf_url' => $pdfUrl,
        'http_code' => (int) ($posted['http_code'] ?? 0),
        'response' => $posted['raw'] ?? null,
    ];
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
    if (!in_array($orderPayStatus, ['paid', 'pending', 'unpaid', 'partially_paid'], true)) {
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

    if (invoice_whatsapp_already_sent($businessId, $invoiceId)) {
        $waPhone = format_storefront_whatsapp_phone($targetPhone);
        return [
            'success' => true,
            'skipped' => true,
            'already_sent' => true,
            'phone' => $waPhone,
            'invoice_id' => $invoiceId,
        ];
    }
    mark_invoice_whatsapp_sent($businessId, $invoiceId);

    $waPhone = format_storefront_whatsapp_phone($targetPhone);
    if (strlen($waPhone) < 10) {
        return ['success' => false, 'error' => 'Customer WhatsApp number is missing or invalid.'];
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

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $viaCurl = send_invoice_whatsapp_via_saved_curl($businessId, $waPhone, $pdfUrl, $invNum, $caption);
    if (empty($viaCurl['success'])) {
        @unlink(invoice_whatsapp_sent_flag_path($businessId, $invoiceId));
    }
    $viaCurl['invoice_id'] = $invoiceId;
    $viaCurl['phone'] = $viaCurl['phone'] ?? $waPhone;
    $viaCurl['pdf_url'] = $viaCurl['pdf_url'] ?? $pdfUrl;
    return $viaCurl;
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

function invoice_whatsapp_ofb_flag_path(int $businessId, int $billId): string {
    return invoice_pdf_storage_dir($businessId) . '/wa-sent-ofb-' . $billId . '.flag';
}

function send_offline_bill_invoice_whatsapp(int $businessId, int $billId, string $customerPhone = '', array $billResult = []): array {
    $brand = get_mobile_store_settings($businessId);
    if (empty($brand['wa_auto_send_invoices'])) {
        return ['success' => false, 'skipped' => true, 'error' => 'Auto-send invoices is disabled.'];
    }

    $targetPhone = resolve_invoice_whatsapp_phone($customerPhone, $billResult, 0);
    if ($targetPhone === '') {
        return ['success' => false, 'skipped' => true, 'error' => 'Customer has no WhatsApp number on file.'];
    }

    $waPhone = format_storefront_whatsapp_phone($targetPhone);
    if (strlen($waPhone) < 10) {
        return ['success' => false, 'error' => 'Customer WhatsApp number is missing or invalid.'];
    }

    if ($billId > 0 && is_file(invoice_whatsapp_ofb_flag_path($businessId, $billId))) {
        return [
            'success' => true,
            'skipped' => true,
            'already_sent' => true,
            'phone' => $waPhone,
            'bill_id' => $billId,
        ];
    }

    $pdfPath = ensure_offline_bill_pdf_file($billId, $businessId);
    if ($pdfPath === null) {
        return ['success' => false, 'error' => 'Could not generate invoice PDF.'];
    }

    $invNum = (string) ($billResult['invoice_number'] ?? ('OFB-' . $billId));
    $orderNum = (string) ($billResult['order_number'] ?? '');
    $storeName = (string) ($brand['display_name'] ?? 'Store');
    $pdfUrl = offline_bill_pdf_public_url($billId, $businessId);
    $caption = 'Thank you for shopping with us at ' . $storeName . '. Tax invoice ' . $invNum;
    if ($orderNum !== '') {
        $caption .= ' (Order ' . $orderNum . ')';
    }
    $caption .= '.';

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $viaCurl = send_invoice_whatsapp_via_saved_curl($businessId, $waPhone, $pdfUrl, $invNum, $caption);
    if (!empty($viaCurl['success'])) {
        @file_put_contents(invoice_whatsapp_ofb_flag_path($businessId, $billId), date('c'));
    }
    $viaCurl['bill_id'] = $billId;
    $viaCurl['phone'] = $viaCurl['phone'] ?? $waPhone;
    $viaCurl['pdf_url'] = $viaCurl['pdf_url'] ?? $pdfUrl;
    return $viaCurl;
}

function attach_offline_bill_invoice_whatsapp(array $saveResult, int $businessId, string $customerPhone = ''): array {
    if (empty($saveResult['success'])) {
        return $saveResult;
    }
    try {
        $saveResult['whatsapp_invoice'] = send_offline_bill_invoice_whatsapp(
            $businessId,
            (int) ($saveResult['id'] ?? 0),
            $customerPhone !== '' ? $customerPhone : (string) ($saveResult['customer_phone'] ?? ''),
            $saveResult
        );
    } catch (Throwable $e) {
        error_log('Offline bill invoice WhatsApp: ' . $e->getMessage());
        $saveResult['whatsapp_invoice'] = [
            'success' => false,
            'error' => 'Could not send invoice on WhatsApp.',
        ];
    }
    return $saveResult;
}

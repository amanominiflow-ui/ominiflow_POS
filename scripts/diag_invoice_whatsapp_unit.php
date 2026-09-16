<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/app.php';

// Minimal stubs to load only helpers we need without DB
require_once $root . '/includes/helpers.php';

// Copy functions from storefront_db without full include
require_once $root . '/includes/storefront_db.php';

// storefront_db calls get_db on ensure - only call pure functions
$urls = whatsapp_outbound_api_urls('https://whatsapp.ominiflow.com/api/wpbox/sendtemplatemessage');
$ok = in_array('https://whatsapp.ominiflow.com/api/wpbox/sendmessage', $urls, true)
    && in_array('https://whatsapp.ominiflow.com/api/wpbox/sendmedia', $urls, true);
$noBadCase = true;
foreach ($urls as $u) {
    $leaf = basename((string) (parse_url($u, PHP_URL_PATH) ?? ''));
    if (in_array($leaf, ['SendMessage', 'sendMessage'], true)) {
        $noBadCase = false;
    }
}
echo 'whatsapp_outbound_api_urls: ' . ($ok ? 'PASS' : 'FAIL') . "\n";
echo 'no mixed-case SendMessage: ' . ($noBadCase ? 'PASS' : 'FAIL') . "\n";
foreach ($urls as $u) {
    echo "  {$u}\n";
}

require_once $root . '/includes/invoice_pdf.php';
$_SERVER['HTTP_HOST'] = 'pos.ominiflow.com';
$_SERVER['HTTPS'] = 'on';
$sampleUrl = invoice_pdf_public_url(99, 1);
echo 'invoice_pdf_public_url (prod host): ' . $sampleUrl . "\n";
$okUrl = str_starts_with($sampleUrl, 'https://pos.ominiflow.com/') && str_contains($sampleUrl, 'invoice-pdf.php');
echo 'PDF URL shape: ' . ($okUrl ? 'PASS' : 'FAIL') . "\n";

require_once $root . '/includes/invoice_whatsapp.php';
$attempts = build_invoice_whatsapp_send_attempts(
    [
        'token' => 'testtoken',
        'company_id' => 162,
        'api_url' => 'https://whatsapp.ominiflow.com/api/wpbox/sendtemplatemessage',
        'is_meta_graph' => false,
    ],
    '919876543210',
    'Test caption',
    'https://pos.ominiflow.com/invoice-pdf.php?id=1&b=1&t=x',
    'INV-001',
    null
);
$hasSendMessage = false;
$hasSendMedia = false;
$hasBadCase = false;
foreach ($attempts as $a) {
    $url = (string) $a['url'];
    $leaf = basename((string) (parse_url($url, PHP_URL_PATH) ?? ''));
    if (str_contains(strtolower($url), 'sendmessage')) {
        $hasSendMessage = true;
    }
    if (str_contains(strtolower($url), 'sendmedia')) {
        $hasSendMedia = true;
    }
    if (in_array($leaf, ['SendMessage', 'sendMessage'], true)) {
        $hasBadCase = true;
    }
}
echo 'build_invoice_whatsapp_send_attempts includes sendmessage: ' . ($hasSendMessage ? 'PASS' : 'FAIL') . "\n";
echo 'build_invoice_whatsapp_send_attempts skips sendmedia: ' . (!$hasSendMedia ? 'PASS' : 'FAIL') . "\n";
echo 'attempts avoid SendMessage: ' . (!$hasBadCase ? 'PASS' : 'FAIL') . "\n";
echo 'attempt count: ' . count($attempts) . "\n";

$fromArg = resolve_invoice_whatsapp_phone('9876543210', []);
$fromOrder = resolve_invoice_whatsapp_phone('', ['customer_phone' => '+91 90000 11111']);
$emptyPhone = resolve_invoice_whatsapp_phone('', []);
echo 'resolve phone from arg: ' . ($fromArg === '9876543210' ? 'PASS' : 'FAIL') . "\n";
echo 'resolve phone from order: ' . ($fromOrder === '+91 90000 11111' ? 'PASS' : 'FAIL') . "\n";
echo 'resolve phone empty: ' . ($emptyPhone === '' ? 'PASS' : 'FAIL') . "\n";

$injected = inject_invoice_into_wa_payload(
    [
        'phone' => '0000000000',
        'template_name' => 'invoice',
        'document_url' => 'https://example.com/sample.pdf',
        'filename' => 'sample.pdf',
        'template' => [
            'name' => 'invoice',
            'components' => [
                [
                    'type' => 'header',
                    'parameters' => [[
                        'type' => 'document',
                        'document' => ['link' => 'https://example.com/old.pdf', 'filename' => 'old.pdf'],
                    ]],
                ],
                [
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => 'INV-0000']],
                ],
            ],
        ],
    ],
    '919876543210',
    'https://pos.ominiflow.com/invoice-pdf.php?id=1',
    'INV-20260916-0018',
    'INV-20260916-0018.pdf',
    'Thank you invoice'
);
$injectOk = $injected['phone'] === '919876543210'
    && $injected['document_url'] === 'https://pos.ominiflow.com/invoice-pdf.php?id=1'
    && $injected['template']['components'][0]['parameters'][0]['document']['link'] === 'https://pos.ominiflow.com/invoice-pdf.php?id=1'
    && $injected['template']['components'][1]['parameters'][0]['text'] === 'INV-20260916-0018';
echo 'inject_invoice_into_wa_payload: ' . ($injectOk ? 'PASS' : 'FAIL') . "\n";

$wpbox = inject_invoice_into_wa_payload(
    [
        'phone' => '000',
        'template_name' => 'invoice_pdf',
        'file' => 'https://example.com/old.pdf',
        'params' => 'INV-0000,Customer',
        'header_params' => 'https://example.com/old.pdf',
    ],
    '919876543210',
    'https://pos.ominiflow.com/invoice-pdf.php?id=9',
    'INV-9',
    'INV-9.pdf',
    'Thanks'
);
$wpboxOk = $wpbox['phone'] === '919876543210'
    && $wpbox['file'] === 'https://pos.ominiflow.com/invoice-pdf.php?id=9'
    && $wpbox['header_params'] === 'https://pos.ominiflow.com/invoice-pdf.php?id=9'
    && str_starts_with($wpbox['params'], 'INV-9');
echo 'inject wpbox file/params: ' . ($wpboxOk ? 'PASS' : 'FAIL') . "\n";

$parsedCurl = parse_whatsapp_curl_command(
    "curl -X POST 'https://whatsapp.ominiflow.com/api/wpbox/sendtemplatemessage' -d '{\"phone\":\"000\",\"document_url\":\"https://pos.ominiflow.com/invoice-pdf.php?id=1\"}'"
);
$parseUrlOk = ($parsedCurl['wa_api_url'] ?? '') === 'https://whatsapp.ominiflow.com/api/wpbox/sendtemplatemessage'
    && is_array($parsedCurl['payload'] ?? null);
echo 'parse prefers sendtemplatemessage URL: ' . ($parseUrlOk ? 'PASS' : 'FAIL') . "\n";

$jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0In0.abc+def/ghi=';
$parsedJwt = parse_whatsapp_curl_command(
    'curl -X POST \'https://whatsapp.ominiflow.com/api/wpbox/sendtemplatemessage\' -d \'{"token":"' . $jwt . '","phone":"9191","template_name":"invoice"}\''
);
$jwtOk = ($parsedJwt['wa_token'] ?? '') === $jwt;
echo 'parse keeps full JSON token: ' . ($jwtOk ? 'PASS' : 'FAIL') . "\n";

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
$ok = count($urls) >= 3 && in_array('https://whatsapp.ominiflow.com/api/wpbox/sendmessage', $urls, true);
echo 'whatsapp_outbound_api_urls: ' . ($ok ? 'PASS' : 'FAIL') . "\n";
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
foreach ($attempts as $a) {
    if (str_contains((string) $a['url'], 'sendmessage')) {
        $hasSendMessage = true;
    }
}
echo 'build_invoice_whatsapp_send_attempts includes sendmessage: ' . ($hasSendMessage ? 'PASS' : 'FAIL') . "\n";
echo 'attempt count: ' . count($attempts) . "\n";

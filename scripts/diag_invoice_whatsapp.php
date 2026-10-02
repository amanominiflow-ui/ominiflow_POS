<?php

declare(strict_types=1);

/**
 * CLI diagnostic for storefront invoice WhatsApp (no messages sent unless --send-test).
 * Usage: php scripts/diag_invoice_whatsapp.php [business_id] [--send-test]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
chdir($root);

$_SERVER['HTTP_HOST'] = 'pos.ominiflow.com';
$_SERVER['HTTPS'] = 'on';

require_once $root . '/includes/db.php';
require_once $root . '/includes/storefront_db.php';
require_once $root . '/includes/invoice_pdf.php';
require_once $root . '/includes/invoice_whatsapp.php';
require_once $root . '/includes/orders_db.php';

$businessId = 0;
$sendTest = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--send-test') {
        $sendTest = true;
    } elseif (ctype_digit($arg)) {
        $businessId = (int) $arg;
    }
}

ensure_online_store_schema();

$db = get_db();

if ($businessId <= 0) {
    $row = $db->query("
        SELECT business_id FROM mobile_store_settings
        WHERE wa_token IS NOT NULL AND TRIM(wa_token) != ''
        ORDER BY business_id ASC LIMIT 1
    ")->fetch();
    $businessId = (int) ($row['business_id'] ?? 0);
    if ($businessId <= 0) {
        $row = $db->query('SELECT id FROM businesses ORDER BY id ASC LIMIT 1')->fetch();
        $businessId = (int) ($row['id'] ?? 0);
    }
}

echo "=== Invoice WhatsApp diagnostic ===\n";
echo "Business ID: {$businessId}\n\n";

if ($businessId <= 0) {
    echo "FAIL: No business found in database.\n";
    exit(1);
}

$brand = get_mobile_store_settings($businessId);
echo "Store: " . ($brand['display_name'] ?? 'n/a') . "\n";
echo "wa_auto_send_invoices: " . (!empty($brand['wa_auto_send_invoices']) ? 'ON' : 'OFF') . "\n";
$invCurl = trim((string) ($brand['wa_invoice_curl_raw'] ?? ''));
echo 'wa_invoice_curl_raw: ' . ($invCurl !== '' ? (strlen($invCurl) . ' chars') : 'MISSING — paste in Invoice PDF tab') . "\n";
echo 'wa_invoice_api_url: ' . (trim((string) ($brand['wa_invoice_api_url'] ?? '')) ?: '(empty)') . "\n";
echo 'wa_invoice_template_name: ' . (trim((string) ($brand['wa_invoice_template_name'] ?? '')) ?: '(empty)') . "\n";
echo "wa_company_id: " . (string) ($brand['wa_company_id'] ?? '') . "\n";
echo "wa_phone_number_id: " . (string) ($brand['wa_phone_number_id'] ?? '') . "\n";
$token = trim((string) ($brand['wa_token'] ?? ''));
echo 'wa_token: ' . ($token !== '' ? (substr($token, 0, 8) . '… (' . strlen($token) . ' chars)') : 'MISSING') . "\n";
echo 'wa_api_url: ' . (trim((string) ($brand['wa_api_url'] ?? '')) ?: '(empty)') . "\n\n";

$gateway = get_business_whatsapp_gateway($businessId);
echo 'Gateway configured: ' . (!empty($gateway['configured']) ? 'YES' : 'NO') . "\n";
if (empty($gateway['configured'])) {
    echo 'Gateway error: ' . ($gateway['error'] ?? '') . "\n";
}
echo 'is_meta_graph: ' . (!empty($gateway['is_meta_graph']) ? 'yes' : 'no') . "\n";
echo 'resolved api_url: ' . ($gateway['api_url'] ?? '') . "\n";

$outUrls = whatsapp_outbound_api_urls((string) ($gateway['api_url'] ?? ''));
echo "Outbound URLs to try (" . count($outUrls) . "):\n";
foreach ($outUrls as $u) {
    echo "  - {$u}\n";
}
echo "\n";

$invRow = $db->prepare("
    SELECT inv.id AS invoice_id, inv.order_id, o.order_number, o.payment_status, o.sales_channel
    FROM invoices inv
    INNER JOIN orders o ON o.id = inv.order_id AND o.business_id = inv.business_id
    WHERE inv.business_id = :bid
    ORDER BY inv.id DESC
    LIMIT 1
");
$invRow->execute(['bid' => $businessId]);
$latest = $invRow->fetch();

if (!$latest) {
    echo "WARN: No invoices for this business — PDF URL test skipped.\n";
} else {
    $invoiceId = (int) $latest['invoice_id'];
    $orderId = (int) $latest['order_id'];
    echo "Latest invoice: #{$invoiceId} order {$latest['order_number']} pay={$latest['payment_status']} channel={$latest['sales_channel']}\n";

    $pdfPath = ensure_invoice_pdf_file($invoiceId, $businessId);
    echo 'PDF file: ' . ($pdfPath && is_readable($pdfPath) ? $pdfPath . ' (' . filesize($pdfPath) . ' bytes)' : 'FAIL') . "\n";

    $pdfUrl = invoice_pdf_public_url($invoiceId, $businessId);
    echo "PDF public URL: {$pdfUrl}\n";

    $ch = curl_init($pdfUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    echo "PDF URL HTTP check (this machine): {$http} content-type={$ctype}\n";
    if ($http !== 200) {
        echo "WARN: PDF URL not reachable from here (deploy/path/firewall). Meta/WPBox need HTTPS 200 on live server.\n";
    }

    if (!empty($gateway['is_meta_graph']) && $pdfPath && !empty($gateway['phone_number_id']) && $token !== '') {
        echo "\nMeta media upload test (dry): would upload to phone_id " . $gateway['phone_number_id'] . "\n";
        if ($sendTest) {
            $mediaId = upload_whatsapp_meta_document((string) $gateway['phone_number_id'], $token, $pdfPath);
            echo 'Media upload result: ' . ($mediaId ?: 'FAILED') . "\n";
        }
    }

    if ($sendTest && !empty($gateway['configured'])) {
        echo "\n--send-test: calling send_order_invoice_whatsapp\n";
        $res = send_order_invoice_whatsapp($businessId, $orderId, '9876543210', [
            'invoice_id' => $invoiceId,
            'order_number' => (string) $latest['order_number'],
            'payment_status' => (string) $latest['payment_status'],
        ]);
        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

echo "\n=== Checks complete ===\n";

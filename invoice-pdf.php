<?php

declare(strict_types=1);

define('OMINIFLOW_SKIP_SESSION', true);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/invoice_pdf.php';

$invoiceId = (int) ($_GET['id'] ?? 0);
$businessId = (int) ($_GET['b'] ?? 0);
$token = (string) ($_GET['t'] ?? '');
$isOfflineBill = (string) ($_GET['ofb'] ?? '') === '1';

if ($invoiceId <= 0 || $businessId <= 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid or expired invoice link.';
    exit;
}

if ($isOfflineBill) {
    if (!offline_bill_pdf_verify_token($invoiceId, $businessId, $token)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid or expired invoice link.';
        exit;
    }
    $path = ensure_offline_bill_pdf_file($invoiceId, $businessId);
} else {
    if (!invoice_pdf_verify_token($invoiceId, $businessId, $token)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid or expired invoice link.';
        exit;
    }
    $path = ensure_invoice_pdf_file($invoiceId, $businessId);
}
if ($path === null || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invoice PDF not found.';
    exit;
}

$filename = basename($path);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;

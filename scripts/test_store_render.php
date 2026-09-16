<?php

declare(strict_types=1);

$_GET = [
    'slug' => 'rbhedruonlineselling',
    'buynow' => '1',
    'id' => '6',
    'page' => 'product',
];
$_SERVER['HTTP_HOST'] = 'pos.ominiflow.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';

error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    ob_start();
    require dirname(__DIR__) . '/store.php';
    $out = ob_get_clean();
    echo 'OK bytes=' . strlen($out) . "\n";
} catch (Throwable $e) {
    echo 'FATAL: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
}

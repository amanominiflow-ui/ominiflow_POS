<?php
/**
 * Application Configuration for OminiFlow POS
 */

declare(strict_types=1);

if (!defined('APP_NAME')) define('APP_NAME', 'OminiFlow POS');
if (!defined('APP_TAGLINE')) define('APP_TAGLINE', 'Point of Sale & Billing at SaaS scale');
if (!defined('APP_URL')) {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']) : '';
    $currentDir = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');
    if ($docRoot && str_starts_with($currentDir, $docRoot)) {
        $rel = substr($currentDir, strlen($docRoot));
        define('APP_URL', '/' . trim($rel, '/'));
    } else {
        define('APP_URL', '/ominiflow_POS-main');
    }
}
if (!defined('APP_ENV')) define('APP_ENV', 'development');
if (!defined('STORE_CNAME_TARGET')) define('STORE_CNAME_TARGET', 'stores.ominiflow.com');

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('ominiflow_pos_session');
    session_start();
}

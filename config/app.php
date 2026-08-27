<?php
/**
 * Application Configuration for OminiFlow POS
 */

declare(strict_types=1);

if (!defined('APP_NAME')) define('APP_NAME', 'OminiFlow POS');
if (!defined('APP_TAGLINE')) define('APP_TAGLINE', 'Smart Cloud POS Billing & Retail Store Management');
if (!defined('APP_URL')) {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']) : '';
    $currentDir = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');
    if ($docRoot && str_starts_with($currentDir, $docRoot)) {
        $rel = trim(substr($currentDir, strlen($docRoot)), '/\\');
        define('APP_URL', $rel !== '' ? ('/' . $rel) : '');
    } else {
        define('APP_URL', '/ominiflow_POS-main');
    }
}
if (!defined('APP_ENV')) define('APP_ENV', 'development');
if (!defined('STORE_CNAME_TARGET')) define('STORE_CNAME_TARGET', 'pos.ominiflow.com');
if (!defined('CLOUDWAYS_API_KEY')) define('CLOUDWAYS_API_KEY', 'cw_b5e60fe02e9afd0b389549ee73cecf2fbff80fc8f3fd888574ee77c6c7257c00');
if (!defined('CLOUDWAYS_SERVER_ID')) define('CLOUDWAYS_SERVER_ID', 1335001);
if (!defined('CLOUDWAYS_APP_ID')) define('CLOUDWAYS_APP_ID', 6628687);

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('ominiflow_pos_session');
    session_start();
}

<?php
/**
 * Application Configuration for OminiFlow POS
 */

declare(strict_types=1);

if (!defined('APP_NAME')) define('APP_NAME', 'OminiFlow POS');
if (!defined('APP_TAGLINE')) define('APP_TAGLINE', 'Point of Sale & Billing at SaaS scale');
if (!defined('APP_URL')) define('APP_URL', '/ominiflow-pos');
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

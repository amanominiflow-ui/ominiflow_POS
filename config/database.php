<?php
/**
 * Database Configuration for OminiFlow POS
 * Connects exclusively to the separate `ominiflow_pos` database.
 */

declare(strict_types=1);

if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', 3306);

// Auto-detect Cloudways production environment vs local development
$isLiveServer = (isset($_SERVER['DOCUMENT_ROOT']) && str_contains($_SERVER['DOCUMENT_ROOT'], 'cloudwaysapps'))
    || (isset($_SERVER['HTTP_HOST']) && (str_contains($_SERVER['HTTP_HOST'], 'ominiflow.com') || str_contains($_SERVER['HTTP_HOST'], 'rbhedruonlineselling.com')))
    || is_dir('/home/master/applications');

if ($isLiveServer) {
    if (!defined('DB_NAME')) define('DB_NAME', 'tgpryurzxb');
    if (!defined('DB_USER')) define('DB_USER', 'tgpryurzxb');
    if (!defined('DB_PASS')) define('DB_PASS', 'OfPos2026!xT7kQ9mW');
} else {
    if (!defined('DB_NAME')) define('DB_NAME', 'ominiflow_pos');
    if (!defined('DB_USER')) define('DB_USER', 'root');
    if (!defined('DB_PASS')) define('DB_PASS', '');
}

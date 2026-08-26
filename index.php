<?php
/**
 * OminiFlow POS - Main Entry Point
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/storefront_db.php';

ensure_online_store_schema();
$mappedStore = get_business_by_custom_domain(current_request_host(), true);
if ($mappedStore) {
    redirect(APP_URL . '/store.php');
}

if (is_authenticated()) {
    redirect(APP_URL . '/dashboard.php');
} else {
    redirect(APP_URL . '/login.php');
}

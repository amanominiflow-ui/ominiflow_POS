<?php
/**
 * Super Admin Authentication Guard for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/features.php';

function require_super_admin(): array {
    if (!is_authenticated()) {
        set_flash('error', 'Super Admin authentication required. Please sign in.');
        redirect(APP_URL . '/login.php');
    }

    $user = current_user();
    if (!$user || empty($user['is_super_admin'])) {
        set_flash('error', 'Access denied. You do not have Super Admin privileges.');
        redirect(APP_URL . '/login.php');
    }

    return $user;
}

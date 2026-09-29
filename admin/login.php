<?php
/**
 * Super Admin Login Router
 * Single unified login via standard OminiFlow POS login page (login.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (is_authenticated() && is_super_admin()) {
    redirect(APP_URL . '/admin/dashboard.php');
}

redirect(APP_URL . '/login.php');

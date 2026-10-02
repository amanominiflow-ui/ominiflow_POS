<?php
/**
 * Super Admin Logout Controller
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

// Unset any impersonation session too
unset($_SESSION['superadmin_impersonator']);
unset($_SESSION['is_super_admin']);

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

set_flash('success', 'You have been signed out successfully.');
redirect(APP_URL . '/login.php');


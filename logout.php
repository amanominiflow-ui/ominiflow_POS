<?php
/**
 * OminiFlow POS - Logout Handler
 * Intelligently redirects Super Admins to the Admin login, and Clients to the POS login.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$wasSuperAdmin = is_super_admin() || !empty($_SESSION['is_super_admin']) || !empty($_SESSION['superadmin_impersonator']);
$ref = $_SERVER['HTTP_REFERER'] ?? '';
$fromAdmin = str_contains($ref, '/admin/') || str_contains($ref, 'admin');

unset($_SESSION['superadmin_impersonator']);
unset($_SESSION['is_super_admin']);

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

set_flash('success', 'You have been signed out successfully.');
redirect(APP_URL . '/login.php');

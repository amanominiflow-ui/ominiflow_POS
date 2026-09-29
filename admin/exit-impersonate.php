<?php
/**
 * Exit Impersonation Controller
 * Restores the original Super Admin session and redirects to Admin Panel.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

if (empty($_SESSION['superadmin_impersonator'])) {
    // If not impersonating, just redirect to admin panel
    redirect(APP_URL . '/admin/dashboard.php');
}

$origAdmin = $_SESSION['superadmin_impersonator'];
$origAdminId = (int)($origAdmin['id'] ?? 0);

$db = get_db();
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id AND is_super_admin = 1 LIMIT 1');
$stmt->execute(['id' => $origAdminId]);
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$adminUser) {
    // Fallback if ID changed: find any super admin
    $stmtFallback = $db->query('SELECT * FROM users WHERE is_super_admin = 1 LIMIT 1');
    $adminUser = $stmtFallback->fetch(PDO::FETCH_ASSOC);
}

// Clear impersonator flag
unset($_SESSION['superadmin_impersonator']);

if ($adminUser) {
    $_SESSION['user_id'] = (int)$adminUser['id'];
    $_SESSION['business_id'] = (int)($adminUser['business_id'] ?? 1);
    $_SESSION['user_name'] = $adminUser['name'];
    $_SESSION['user_email'] = $adminUser['email'];
    $_SESSION['user_role'] = $adminUser['role'] ?? 'admin';
    $_SESSION['is_super_admin'] = true;
    set_flash('success', 'Returned to Super Admin Console.');
} else {
    logout_user();
    set_flash('error', 'Super Admin session expired. Please sign in again.');
    redirect(APP_URL . '/login.php');
}

redirect(APP_URL . '/admin/dashboard.php');

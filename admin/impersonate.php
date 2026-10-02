<?php
/**
 * 1-Click Client Impersonation Controller
 * Allows Super Admin to log in directly as any client user with one click.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/admin_db.php';

$adminUser = require_super_admin();

$targetUserId = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? 0));
$targetEmail = trim((string)($_GET['email'] ?? ($_POST['email'] ?? '')));
$targetBizId = (int)($_GET['business_id'] ?? ($_POST['business_id'] ?? 0));

$db = get_db();
$targetUser = null;

if ($targetUserId > 0) {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $targetUserId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($targetEmail !== '') {
    $stmt = $db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => strtolower($targetEmail)]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($targetBizId > 0) {
    // Pick the primary admin user of this business
    $stmt = $db->prepare('
        SELECT * FROM users 
        WHERE business_id = :bid 
        ORDER BY CASE WHEN role = "admin" THEN 0 ELSE 1 END, id ASC 
        LIMIT 1
    ');
    $stmt->execute(['bid' => $targetBizId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$targetUser) {
    set_flash('error', 'Client user account not found.');
    redirect(APP_URL . '/admin/clients.php');
}

// Ensure business exists
$stmtBiz = $db->prepare('SELECT * FROM businesses WHERE id = :bid LIMIT 1');
$stmtBiz->execute(['bid' => (int)($targetUser['business_id'] ?? 1)]);
$biz = $stmtBiz->fetch(PDO::FETCH_ASSOC);

// Save Super Admin context for exit button
$_SESSION['superadmin_impersonator'] = [
    'id' => (int)$adminUser['id'],
    'name' => (string)$adminUser['name'],
    'email' => (string)$adminUser['email'],
    'started_at' => time(),
    'return_url' => APP_URL . '/admin/clients.php',
];

// Switch session to target client
$_SESSION['user_id'] = (int)$targetUser['id'];
$_SESSION['business_id'] = (int)($targetUser['business_id'] ?? 1);
$_SESSION['user_name'] = $targetUser['name'];
$_SESSION['user_email'] = $targetUser['email'];
$_SESSION['user_role'] = $targetUser['role'] ?? 'admin';
$_SESSION['is_super_admin'] = false; // temporarily false while acting as client

// Clear cached user in auth.php
if (function_exists('current_user')) {
    // Current user static cache is keyed on $_SESSION['user_id']
}

set_flash('success', 'Logged in as ' . $targetUser['name'] . ' (' . ($biz['name'] ?? 'Store') . '). Click "Exit & Return" anytime to return to Super Admin.');
redirect(APP_URL . '/dashboard.php');

<?php
/**
 * Super Admin Settings & System Configuration
 * Allows changing admin password, profile details, and viewing system health.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/admin_db.php';

$adminUser = require_super_admin();
$pageTitle = 'Super Admin Settings';
$db = get_db();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // 1. Change Password
    if ($action === 'change_password') {
        $currentPw = (string)($_POST['current_password'] ?? '');
        $newPw = (string)($_POST['new_password'] ?? '');
        $confirmPw = (string)($_POST['confirm_password'] ?? '');

        $stmt = $db->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $adminUser['id']]);
        $currentHash = (string)$stmt->fetchColumn();

        if (!password_verify($currentPw, $currentHash)) {
            set_flash('error', 'Current password is incorrect. Please enter your existing password.');
        } elseif (strlen($newPw) < 6) {
            set_flash('error', 'New password must be at least 6 characters long.');
        } elseif ($newPw !== $confirmPw) {
            set_flash('error', 'New password and confirmation password do not match.');
        } else {
            $newHash = password_hash($newPw, PASSWORD_DEFAULT);
            $stmtUp = $db->prepare('UPDATE users SET password = :p, updated_at = NOW() WHERE id = :id');
            $stmtUp->execute(['p' => $newHash, 'id' => $adminUser['id']]);
            set_flash('success', 'Super Admin password updated successfully! Your new password is now active.');
        }
        redirect(APP_URL . '/admin/settings.php');
    }

    // 2. Update Profile Name
    if ($action === 'update_profile') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            set_flash('error', 'Administrator name cannot be empty.');
        } else {
            $stmtUp = $db->prepare('UPDATE users SET name = :n, updated_at = NOW() WHERE id = :id');
            $stmtUp->execute(['n' => $name, 'id' => $adminUser['id']]);
            $_SESSION['user_name'] = $name;
            set_flash('success', 'Profile name updated successfully.');
        }
        redirect(APP_URL . '/admin/settings.php');
    }

    // 3. Update Platform Settings
    if ($action === 'update_platform') {
        $storeName = trim((string)($_POST['store_name'] ?? 'OminiFlow Retail POS'));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $currency = trim((string)($_POST['currency_symbol'] ?? '₹'));

        try {
            $stmtSettings = $db->prepare('
                UPDATE store_settings 
                SET store_name = :sname, phone = :phone, currency_symbol = :curr, updated_at = NOW()
                WHERE id = 1
            ');
            $stmtSettings->execute([
                'sname' => $storeName,
                'phone' => $phone,
                'curr' => $currency ?: '₹',
            ]);
            set_flash('success', 'Platform settings saved successfully.');
        } catch (Throwable $e) {
            set_flash('error', 'Could not update settings: ' . $e->getMessage());
        }
        redirect(APP_URL . '/admin/settings.php');
    }
}

// Fetch current store_settings
$settings = null;
try {
    $settings = $db->query('SELECT * FROM store_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Diagnostic info
$totalClients = admin_count_clients();
$totalUsers = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalFeatures = (int)$db->query('SELECT COUNT(*) FROM system_features')->fetchColumn();
$dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
$dbVer = (string)$db->query('SELECT VERSION()')->fetchColumn();

require_once __DIR__ . '/includes/admin_header.php';
?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
    <!-- 1. CHANGE SUPER ADMIN PASSWORD -->
    <div class="admin-card" style="margin-bottom: 0;">
        <div class="admin-card-header">
            <div class="admin-card-title">
                <svg width="18" height="18" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Change Super Admin Password
            </div>
            <span class="status-badge active" style="font-size: 11px;">Secured</span>
        </div>
        <div class="admin-card-body">
            <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">
                Update your login credentials. Changes take effect immediately and are permanently preserved across migrations and seeds.
            </p>

            <form method="POST" action="<?= asset('admin/settings.php') ?>">
                <input type="hidden" name="action" value="change_password">

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                        Current Password
                    </label>
                    <input 
                        type="password" 
                        name="current_password" 
                        required 
                        placeholder="Enter current password"
                        style="width: 100%; padding: 9px 12px; border: 1px solid var(--adm-border); border-radius: 6px; font-size: 13.5px; outline: none; background: #ffffff;"
                    >
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                        New Password (min 6 characters)
                    </label>
                    <input 
                        type="password" 
                        name="new_password" 
                        required 
                        minlength="6"
                        placeholder="Enter new password"
                        style="width: 100%; padding: 9px 12px; border: 1px solid var(--adm-border); border-radius: 6px; font-size: 13.5px; outline: none; background: #ffffff;"
                    >
                </div>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                        Confirm New Password
                    </label>
                    <input 
                        type="password" 
                        name="confirm_password" 
                        required 
                        minlength="6"
                        placeholder="Re-type new password"
                        style="width: 100%; padding: 9px 12px; border: 1px solid var(--adm-border); border-radius: 6px; font-size: 13.5px; outline: none; background: #ffffff;"
                    >
                </div>

                <button type="submit" class="btn-adm btn-adm-primary" style="width: 100%; justify-content: center; padding: 10px;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Update Password
                </button>
            </form>
        </div>
    </div>

    <!-- 2. SUPER ADMIN PROFILE DETAILS -->
    <div class="admin-card" style="margin-bottom: 0;">
        <div class="admin-card-header">
            <div class="admin-card-title">
                <svg width="18" height="18" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Super Admin Profile
            </div>
            <span class="status-badge active" style="font-size: 11px;">Master Admin</span>
        </div>
        <div class="admin-card-body">
            <form method="POST" action="<?= asset('admin/settings.php') ?>" style="margin-bottom: 20px;">
                <input type="hidden" name="action" value="update_profile">

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                        Super Admin Email (Master Identity)
                    </label>
                    <input 
                        type="text" 
                        value="<?= htmlspecialchars((string)($adminUser['email'] ?? 'admin@example.com'), ENT_QUOTES, 'UTF-8') ?>" 
                        readonly 
                        style="width: 100%; padding: 9px 12px; border: 1px solid var(--adm-border); border-radius: 6px; font-size: 13.5px; background: #f8fafc; color: #64748b; cursor: not-allowed;"
                    >
                    <small style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">
                        * This email is configured in auth.php as the dedicated Super Administrator account.
                    </small>
                </div>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                        Display Name
                    </label>
                    <input 
                        type="text" 
                        name="name" 
                        value="<?= htmlspecialchars((string)($adminUser['name'] ?? 'Super Administrator'), ENT_QUOTES, 'UTF-8') ?>" 
                        required 
                        style="width: 100%; padding: 9px 12px; border: 1px solid var(--adm-border); border-radius: 6px; font-size: 13.5px; outline: none; background: #ffffff;"
                    >
                </div>

                <button type="submit" class="btn-adm btn-adm-secondary" style="width: 100%; justify-content: center; padding: 10px;">
                    Save Profile Changes
                </button>
            </form>

            <div style="padding: 12px 14px; background: #f8fafc; border: 1px solid var(--adm-border); border-radius: 8px;">
                <div style="font-size: 12px; font-weight: 700; color: #0f172a; margin-bottom: 4px;">Role &amp; Permissions</div>
                <div style="font-size: 12px; color: #64748b; line-height: 1.5;">
                    You have unrestricted global access across all client accounts, billing plans, feature catalogs, and 1-click terminal impersonation.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 3. PLATFORM DEFAULTS & SYSTEM ENVIRONMENT -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <!-- Platform Defaults -->
    <div class="admin-card" style="margin-bottom: 0;">
        <div class="admin-card-header">
            <div class="admin-card-title">
                <svg width="18" height="18" fill="none" stroke="#475569" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Platform Defaults
            </div>
        </div>
        <div class="admin-card-body">
            <form method="POST" action="<?= asset('admin/settings.php') ?>">
                <input type="hidden" name="action" value="update_platform">

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                        Platform Name
                    </label>
                    <input 
                        type="text" 
                        name="store_name" 
                        value="<?= htmlspecialchars((string)($settings['store_name'] ?? 'OminiFlow Retail POS'), ENT_QUOTES, 'UTF-8') ?>" 
                        required 
                        style="width: 100%; padding: 9px 12px; border: 1px solid var(--adm-border); border-radius: 6px; font-size: 13.5px; outline: none; background: #ffffff;"
                    >
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                        Currency Symbol
                    </label>
                    <input 
                        type="text" 
                        name="currency_symbol" 
                        value="<?= htmlspecialchars((string)($settings['currency_symbol'] ?? '₹'), ENT_QUOTES, 'UTF-8') ?>" 
                        required 
                        style="width: 100%; padding: 9px 12px; border: 1px solid var(--adm-border); border-radius: 6px; font-size: 13.5px; outline: none; background: #ffffff;"
                    >
                </div>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                        Support Contact Phone
                    </label>
                    <input 
                        type="text" 
                        name="phone" 
                        value="<?= htmlspecialchars((string)($settings['phone'] ?? '+91 98765 43210'), ENT_QUOTES, 'UTF-8') ?>" 
                        style="width: 100%; padding: 9px 12px; border: 1px solid var(--adm-border); border-radius: 6px; font-size: 13.5px; outline: none; background: #ffffff;"
                    >
                </div>

                <button type="submit" class="btn-adm btn-adm-secondary" style="width: 100%; justify-content: center; padding: 10px;">
                    Save Platform Settings
                </button>
            </form>
        </div>
    </div>

    <!-- System Health & Environment -->
    <div class="admin-card" style="margin-bottom: 0;">
        <div class="admin-card-header">
            <div class="admin-card-title">
                <svg width="18" height="18" fill="none" stroke="#166534" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                System Health &amp; Diagnostics
            </div>
            <span class="status-badge active" style="font-size: 11px;">Healthy</span>
        </div>
        <div class="admin-card-body" style="padding: 16px 20px;">
            <table class="adm-table" style="font-size: 12.5px;">
                <tbody>
                    <tr>
                        <td style="color: #64748b; font-weight: 500; width: 45%;">PHP Version</td>
                        <td style="font-weight: 700; color: #0f172a;"><?= PHP_VERSION ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b; font-weight: 500;">Database Engine</td>
                        <td style="font-weight: 700; color: #0f172a;">MySQL / MariaDB (<?= htmlspecialchars($dbVer) ?>)</td>
                    </tr>
                    <tr>
                        <td style="color: #64748b; font-weight: 500;">Active Database</td>
                        <td style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($dbName) ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b; font-weight: 500;">Total Client Stores</td>
                        <td style="font-weight: 700; color: #0f172a;"><?= number_format($totalClients) ?> Registered</td>
                    </tr>
                    <tr>
                        <td style="color: #64748b; font-weight: 500;">Total User Accounts</td>
                        <td style="font-weight: 700; color: #0f172a;"><?= number_format($totalUsers) ?> Accounts</td>
                    </tr>
                    <tr>
                        <td style="color: #64748b; font-weight: 500;">System Feature Flags</td>
                        <td style="font-weight: 700; color: #0f172a;"><?= number_format($totalFeatures) ?> Modular Flags</td>
                    </tr>
                    <tr>
                        <td style="color: #64748b; font-weight: 500;">Server Software</td>
                        <td style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Apache') ?></td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 16px; display: flex; gap: 10px;">
                <a href="<?= asset('admin/features.php') ?>" class="btn-adm btn-adm-secondary btn-adm-sm" style="flex: 1; justify-content: center;">
                    Feature Flags &rarr;
                </a>
                <a href="<?= asset('dashboard.php') ?>" class="btn-adm btn-adm-secondary btn-adm-sm" style="flex: 1; justify-content: center;" title="Open POS Billing Terminal">
                    Storefront POS &rarr;
                </a>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/admin_footer.php';

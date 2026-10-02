<?php
/**
 * Impersonation Notice Banner
 * Displayed when a Super Admin is logged in as a client.
 */

declare(strict_types=1);

if (!empty($_SESSION['superadmin_impersonator'])):
    $impersonator = $_SESSION['superadmin_impersonator'];
    $adminName = htmlspecialchars($impersonator['name'] ?? 'Super Admin', ENT_QUOTES, 'UTF-8');
    $clientUser = function_exists('current_user') ? current_user() : null;
    $clientEmail = htmlspecialchars($clientUser['email'] ?? ($_SESSION['user_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $clientName = htmlspecialchars($clientUser['name'] ?? ($_SESSION['user_name'] ?? 'Client'), ENT_QUOTES, 'UTF-8');
    $biz = function_exists('current_business') ? current_business() : null;
    $bizName = htmlspecialchars($biz['name'] ?? 'Store #' . ($_SESSION['business_id'] ?? 1), ENT_QUOTES, 'UTF-8');
    $exitUrl = (defined('APP_URL') ? APP_URL : '') . '/admin/exit-impersonate.php';
?>
<div id="omniflow-impersonation-bar" style="position: sticky; top: 0; z-index: 999999; background: linear-gradient(90deg, #7c2d12 0%, #9a3412 100%); color: #ffffff; padding: 10px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 13.5px; box-shadow: 0 4px 15px rgba(0,0,0,0.25); display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #ea580c;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <span style="background: #ea580c; color: #fff; font-size: 11px; font-weight: 800; text-transform: uppercase; padding: 3px 8px; border-radius: 4px; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 4px;">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            IMPERSONATION MODE
        </span>
        <span style="font-weight: 500;">
            Logged in as <strong><?= $clientName ?></strong> (<span style="color: #fed7aa;"><?= $clientEmail ?></span>) &bull; Store: <strong><?= $bizName ?></strong>
        </span>
        <span style="color: #fdba74; font-size: 12px; margin-left: 6px;">
            (Admin session: <?= $adminName ?>)
        </span>
    </div>
    <div style="display: flex; align-items: center; gap: 10px;">
        <a href="<?= $exitUrl ?>" style="background: #ffffff; color: #9a3412; font-weight: 700; font-size: 12.5px; text-decoration: none; padding: 6px 14px; border-radius: 6px; box-shadow: 0 2px 5px rgba(0,0,0,0.15); transition: all 0.15s ease; display: inline-flex; align-items: center; gap: 6px;" onmouseover="this.style.background='#ffedd5'" onmouseout="this.style.background='#ffffff'">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Exit &amp; Return to Super Admin
        </a>
    </div>
</div>
<?php endif; ?>

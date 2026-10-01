<?php
/**
 * Subscription Suspended / Inactive Notice Page for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/features.php';

if (!is_authenticated()) {
    redirect(APP_URL . '/login.php');
}

// If subscription is actually active, redirect to dashboard
if (is_business_subscription_active()) {
    redirect(APP_URL . '/dashboard.php');
}

$user = current_user();
$biz = current_business();
$subStatus = strtolower(trim((string)($biz['subscription_status'] ?? '')));
$trialEnded = $subStatus === 'inactive' && !empty($biz['subscription_expires_at']) && strtotime((string)$biz['subscription_expires_at']) < time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Suspended - OminiFlow POS</title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .suspended-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            max-width: 500px;
            width: 100%;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
        }
        .icon-wrap {
            width: 68px;
            height: 68px;
            background: #fee2e2;
            color: #ef4444;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/impersonate_banner.php'; ?>

    <div class="suspended-card">
        <div class="icon-wrap">
            <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </div>

        <h1 style="font-size: 22px; font-weight: 800; color: #0f172a;"><?= $trialEnded ? 'Free Trial Ended' : 'Store Subscription Suspended' ?></h1>
        <p style="font-size: 14px; color: #64748b; margin-top: 10px; line-height: 1.55;">
            <?php if ($trialEnded): ?>
                Your 7-day free trial for <strong><?= htmlspecialchars($biz['name'] ?? 'Your Store', ENT_QUOTES, 'UTF-8') ?></strong> has ended. Upgrade to a paid plan to continue using OminiFlow POS.
            <?php else: ?>
                The subscription for <strong><?= htmlspecialchars($biz['name'] ?? 'Your Store', ENT_QUOTES, 'UTF-8') ?></strong> has been suspended or is currently inactive.
            <?php endif; ?>
        </p>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin: 24px 0; text-align: left; font-size: 13px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                <span style="color: #64748b;">Account Email:</span>
                <strong style="color: #1e293b;"><?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                <span style="color: #64748b;">Store Status:</span>
                <span style="color: #b91c1c; font-weight: 700; text-transform: uppercase;">Disabled by Admin</span>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span style="color: #64748b;">Action Required:</span>
                <span style="color: #4338ca; font-weight: 600;">Contact Platform Support</span>
            </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php if ($trialEnded): ?>
            <a href="<?= asset('pricing.php') ?>" style="display: block; width: 100%; background: #2563eb; color: #fff; font-weight: 700; padding: 12px; border-radius: 8px; text-decoration: none; font-size: 14px;">
                View Plans &amp; Upgrade
            </a>
            <?php endif; ?>
            <a href="<?= asset('logout.php') ?>" style="display: block; width: 100%; background: #ef4444; color: #fff; font-weight: 700; padding: 12px; border-radius: 8px; text-decoration: none; font-size: 14px;">
                Sign Out
            </a>
            <a href="mailto:support@ominiflow.com" style="display: block; font-size: 13px; color: #64748b; text-decoration: underline;">
                Contact Support (support@ominiflow.com)
            </a>
        </div>
    </div>
</body>
</html>

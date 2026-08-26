<?php
/**
 * Premium pricing — ₹35,000 with 18% GST (GST amount is not shown).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/premium_db.php';

require_auth();

$user = current_user();
$bid = current_business_id();
$isPremium = is_premium_active($bid);
$pendingOrder = $isPremium ? null : get_pending_premium_order($bid);
$price = premium_price_breakdown();
$features = premium_features();
$pageTitle = 'Premium Plan';

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Premium Plan — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .pr-page { padding: 28px 32px 80px; background: #f8fafc; min-height: calc(100vh - 60px); }
        .pr-hero { max-width: 980px; margin: 0 auto 28px; }
        .pr-kicker { font-size: 12px; font-weight: 800; letter-spacing: .08em; color: #2563eb; text-transform: uppercase; margin-bottom: 8px; }
        .pr-hero h1 { font-size: 28px; font-weight: 800; color: #0f172a; margin: 0 0 8px; }
        .pr-hero p { font-size: 15px; color: #64748b; margin: 0; line-height: 1.5; }
        .pr-grid { display: grid; grid-template-columns: 380px 1fr; gap: 24px; max-width: 980px; margin: 0 auto; align-items: start; }
        @media (max-width: 900px) { .pr-grid { grid-template-columns: 1fr; } }
        .pr-card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 28px 26px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .06);
        }
        .pr-badge { display: inline-block; background: #dbeafe; color: #1d4ed8; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 999px; margin-bottom: 10px; }
        .pr-plan-name { font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 16px; }
        .pr-price-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; font-size: 14px; color: #334155; }
        .pr-price-row span:last-child { font-weight: 700; }
        .pr-muted { color: #64748b; font-weight: 600 !important; }
        .pr-total { border-top: 1px dashed #cbd5e1; margin-top: 8px; padding-top: 12px; font-size: 16px; font-weight: 800; color: #0f172a; }
        .pr-note { font-size: 12.5px; color: #64748b; margin: 12px 0 18px; line-height: 1.45; }
        .pr-buy {
            display: block; width: 100%; background: #2563eb; color: #fff; border: 0; border-radius: 10px; padding: 12px 18px;
            font-size: 15px; font-weight: 700; cursor: pointer; text-align: center; text-decoration: none; box-sizing: border-box;
        }
        .pr-buy:hover { background: #1d4ed8; color: #fff; }
        .pr-active { background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; border-radius: 10px; padding: 12px 14px; font-size: 13.5px; font-weight: 650; }
        .pr-pending { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 12px 14px; font-size: 13.5px; font-weight: 650; margin-bottom: 12px; }
        .pr-feat-head { font-size: 16px; font-weight: 800; color: #0f172a; margin: 0 0 16px; }
        .pr-feat-block { margin-bottom: 18px; }
        .pr-feat-block h3 { font-size: 13.5px; font-weight: 800; color: #1e293b; margin: 0 0 8px; }
        .pr-feat-block ul { margin: 0; padding: 0; list-style: none; }
        .pr-feat-block li {
            position: relative; padding: 5px 0 5px 22px; font-size: 13.5px; color: #334155; line-height: 1.4;
        }
        .pr-feat-block li::before {
            content: "✓"; position: absolute; left: 0; top: 5px; color: #2563eb; font-weight: 800; font-size: 13px;
        }
    </style>
</head>
<body>
<div class="app-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <div class="app-main">
        <?php require_once __DIR__ . '/includes/header.php'; ?>
        <main class="pr-page">
            <?php if ($flashSuccess): ?><div class="saas-alert saas-alert-success" style="max-width:980px;margin:0 auto 16px"><span><?= e($flashSuccess) ?></span></div><?php endif; ?>
            <?php if ($flashError): ?><div class="saas-alert saas-alert-danger" style="max-width:980px;margin:0 auto 16px"><span><?= e($flashError) ?></span></div><?php endif; ?>

            <div class="pr-hero">
                <div class="pr-kicker">Pricing</div>
                <h1>Unlock the full POS with Premium</h1>
                <p>Without Premium you can use Home only. Buy once to open billing, inventory, online store, branding, reports, and every other module.</p>
            </div>

            <div class="pr-grid">
                <aside class="pr-card">
                    <span class="pr-badge">PREMIUM</span>
                    <h2 class="pr-plan-name">OminiFlow Premium</h2>
                    <div class="pr-price-row pr-total"><span>Plan price</span><span>₹<?= number_format($price['total'], 0) ?></span></div>
                    <div class="pr-price-row"><span>GST <?= number_format($price['gst_rate'], 0) ?>% extra</span><span class="pr-muted"></span></div>
                    <p class="pr-note">GST 18% extra.</p>

                    <?php if ($isPremium): ?>
                        <div class="pr-active">Premium is active on this account. All modules are unlocked.</div>
                    <?php else: ?>
                        <?php if ($pendingOrder): ?>
                            <div class="pr-pending">Payment submitted. Premium stays locked until payment is confirmed.</div>
                        <?php endif; ?>
                        <a href="<?= asset('premium-checkout.php') ?>" class="pr-buy">
                            <?= $pendingOrder ? 'Update payment details' : 'Buy Premium — ₹' . number_format($price['total'], 0) ?>
                        </a>
                    <?php endif; ?>
                </aside>

                <section class="pr-card">
                    <h2 class="pr-feat-head">What’s included</h2>
                    <?php foreach ($features as $group): ?>
                        <div class="pr-feat-block">
                            <h3><?= e($group['title']) ?></h3>
                            <ul>
                                <?php foreach ($group['items'] as $item): ?>
                                    <li><?= e($item) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </section>
            </div>
        </main>
    </div>
</div>
</body>
</html>

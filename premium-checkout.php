<?php
/**
 * Premium checkout — payment details only. Does not activate Premium.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/premium_db.php';

require_auth();

$user = current_user();
$bid = current_business_id();
$isPremium = is_premium_active($bid);
$price = premium_price_breakdown();
$pendingOrder = $isPremium ? null : get_pending_premium_order($bid);
$pageTitle = 'Pay for Premium';

if ($isPremium) {
    set_flash('success', 'Premium is already active on this account.');
    redirect(asset('dashboard.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_premium_payment') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(asset('premium-checkout.php'));
    }
    $res = submit_premium_payment($bid, (int) ($user['id'] ?? 0), $_POST);
    if (!empty($res['success'])) {
        set_flash('success', 'Payment details submitted. Premium will stay locked until payment is confirmed.');
        redirect(asset('pricing.php'));
    }
    set_old_input($_POST);
    set_flash('error', $res['error'] ?? 'Could not submit payment details.');
    redirect(asset('premium-checkout.php'));
}

$flashError = get_flash('error');
$payerName = old('payer_name', (string) ($pendingOrder['payer_name'] ?? ($user['name'] ?? '')));
$payerPhone = old('payer_phone', (string) ($pendingOrder['payer_phone'] ?? ($user['phone'] ?? '')));
$payMethod = old('payment_method', (string) ($pendingOrder['payment_method'] ?? 'upi'));
$payRef = old('payment_ref', (string) ($pendingOrder['payment_ref'] ?? ''));
clear_old_input();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay for Premium — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .pr-page { padding: 28px 32px 80px; background: #f8fafc; min-height: calc(100vh - 60px); }
        .pr-wrap { max-width: 640px; margin: 0 auto; }
        .pr-kicker { font-size: 12px; font-weight: 800; letter-spacing: .08em; color: #2563eb; text-transform: uppercase; margin-bottom: 8px; }
        .pr-wrap h1 { font-size: 26px; font-weight: 800; color: #0f172a; margin: 0 0 8px; }
        .pr-lead { font-size: 15px; color: #64748b; margin: 0 0 22px; line-height: 1.5; }
        .pr-card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 28px 26px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .06);
        }
        .pr-price-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; font-size: 14px; color: #334155; }
        .pr-price-row span:last-child { font-weight: 700; }
        .pr-total { border-bottom: 1px dashed #cbd5e1; margin-bottom: 8px; padding-bottom: 12px; font-size: 16px; font-weight: 800; color: #0f172a; }
        .pr-note { font-size: 12.5px; color: #64748b; margin: 4px 0 18px; }
        .pr-label { display: block; font-size: 13px; font-weight: 700; color: #0f172a; margin: 0 0 6px; }
        .pr-input, .pr-select {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px 12px;
            font-size: 14px; color: #0f172a; box-sizing: border-box; background: #fff;
        }
        .pr-input:focus, .pr-select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
        .pr-field { margin-bottom: 14px; }
        .pr-methods { display: grid; gap: 8px; margin-bottom: 14px; }
        .pr-method {
            display: flex; align-items: center; gap: 10px; border: 1px solid #e2e8f0; border-radius: 10px;
            padding: 10px 12px; cursor: pointer; font-size: 14px; font-weight: 650; color: #1e293b;
        }
        .pr-method:has(input:checked) { border-color: #2563eb; background: #eff6ff; }
        .pr-help { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; font-size: 13px; color: #475569; line-height: 1.45; margin-bottom: 16px; }
        .pr-buy {
            width: 100%; background: #2563eb; color: #fff; border: 0; border-radius: 10px; padding: 12px 18px;
            font-size: 15px; font-weight: 700; cursor: pointer;
        }
        .pr-buy:hover { background: #1d4ed8; }
        .pr-back { display: inline-block; margin-top: 14px; font-size: 13px; font-weight: 650; color: #64748b; text-decoration: none; }
        .pr-back:hover { color: #2563eb; }
        .pr-warn { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="app-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <div class="app-main">
        <?php require_once __DIR__ . '/includes/header.php'; ?>
        <main class="pr-page">
            <div class="pr-wrap">
                <div class="pr-kicker">Checkout</div>
                <h1>Pay for OminiFlow Premium</h1>
                <p class="pr-lead">Pay ₹<?= number_format($price['total'], 0) ?>, then submit your transaction ID. Premium does not turn on from this page.</p>

                <?php if ($flashError): ?><div class="saas-alert saas-alert-danger" style="margin-bottom:16px"><span><?= e($flashError) ?></span></div><?php endif; ?>

                <div class="pr-card">
                    <div class="pr-price-row pr-total"><span>Plan price</span><span>₹<?= number_format($price['total'], 0) ?></span></div>
                    <div class="pr-price-row"><span>GST <?= number_format($price['gst_rate'], 0) ?>% extra</span><span></span></div>
                    <p class="pr-note">GST 18% extra.</p>

                    <div class="pr-warn">Clicking Buy or submitting this form will not activate Premium. Access opens only after payment is confirmed.</div>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="submit_premium_payment">

                        <div class="pr-field">
                            <label class="pr-label" for="payer_name">Name</label>
                            <input class="pr-input" id="payer_name" name="payer_name" value="<?= e($payerName) ?>" required>
                        </div>
                        <div class="pr-field">
                            <label class="pr-label" for="payer_phone">Phone</label>
                            <input class="pr-input" id="payer_phone" name="payer_phone" value="<?= e($payerPhone) ?>" required>
                        </div>

                        <div class="pr-label">Payment method</div>
                        <div class="pr-methods">
                            <label class="pr-method"><input type="radio" name="payment_method" value="upi" <?= $payMethod === 'upi' ? 'checked' : '' ?>> UPI</label>
                            <label class="pr-method"><input type="radio" name="payment_method" value="bank" <?= $payMethod === 'bank' ? 'checked' : '' ?>> Bank transfer</label>
                            <label class="pr-method"><input type="radio" name="payment_method" value="card" <?= $payMethod === 'card' ? 'checked' : '' ?>> Card</label>
                        </div>

                        <div class="pr-help">
                            Pay ₹<?= number_format($price['total'], 0) ?> and send the screenshot or UTR on WhatsApp
                            <strong>+91 9243747854</strong>. Then enter the transaction ID below.
                        </div>

                        <div class="pr-field">
                            <label class="pr-label" for="payment_ref">UTR / Transaction ID</label>
                            <input class="pr-input" id="payment_ref" name="payment_ref" value="<?= e($payRef) ?>" placeholder="e.g. 123456789012" required>
                        </div>

                        <button type="submit" class="pr-buy">Submit payment details</button>
                    </form>
                    <a class="pr-back" href="<?= asset('pricing.php') ?>">← Back to plan</a>
                </div>
            </div>
        </main>
    </div>
</div>
</body>
</html>

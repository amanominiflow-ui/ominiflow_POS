<?php
/**
 * OminiFlow POS - Customer Payments Integration Hub (Zoho POS Exact Parity)
 * Gateways: Razorpay, Paytm PG, Stripe, 2Checkout / Verifone, Pine Labs, PhonePe, and Worldline.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/payment_integrations_db.php';
require_once __DIR__ . '/includes/razorpay_oauth.php';
require_once __DIR__ . '/includes/storefront_db.php';

require_auth();

$pageTitle = 'Customer Payments';
$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle Gateway Form Submission (Save & Disconnect)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh the page.');
        redirect(APP_URL . '/payment-integrations.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'connect_razorpay_merchant') {
        $res = razorpay_connect_merchant_keys([
            'api_key' => $_POST['api_key'] ?? '',
            'api_secret' => $_POST['api_secret'] ?? '',
            'webhook_secret' => $_POST['webhook_secret'] ?? '',
            'enable_in_pos' => isset($_POST['enable_in_pos']),
            'enable_in_store' => isset($_POST['enable_in_store']),
        ]);
        if (!empty($res['success'])) {
            set_flash('success', 'Razorpay connected successfully! Complete the webhook steps to sync payments.');
            redirect(asset('payment-integrations.php') . '?razorpay_webhook=1');
        }
        set_flash('error', $res['error'] ?? 'Could not connect Razorpay.');
        redirect(asset('payment-integrations.php') . '?razorpay_connect=1');
    }

    if ($action === 'save_gateway') {
        $gatewayCode = trim($_POST['gateway_code'] ?? '');
        if ($gatewayCode === 'razorpay') {
            redirect(asset('payment-integrations.php') . '?razorpay_connect=1');
        }
        $apiKey = trim($_POST['api_key'] ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');
        $merchantId = trim($_POST['merchant_id'] ?? '');
        $webhookSecret = trim($_POST['webhook_secret'] ?? '');
        $terminalId = trim($_POST['terminal_id'] ?? '');
        $environment = trim($_POST['environment'] ?? 'test');
        $enableInPos = isset($_POST['enable_in_pos']) ? 1 : 0;
        $enableInStore = isset($_POST['enable_in_store']) ? 1 : 0;

        $saveData = [
            'gateway_code' => $gatewayCode,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'merchant_id' => $merchantId,
            'webhook_secret' => $webhookSecret,
            'terminal_id' => $terminalId,
            'environment' => $environment,
            'enable_in_pos' => $enableInPos,
            'enable_in_store' => $enableInStore,
            'status' => 'connected',
            'website_name' => trim($_POST['website_name'] ?? ''),
            'industry_type' => trim($_POST['industry_type'] ?? ''),
            'salt_index' => trim($_POST['salt_index'] ?? '1'),
            'terminal_ip' => trim($_POST['terminal_ip'] ?? ''),
            'device_serial' => trim($_POST['device_serial'] ?? ''),
        ];

        $res = save_payment_integration($saveData);
        if ($res['success']) {
            set_flash('success', ($res['gateway_name'] ?? 'Payment gateway') . ' configured and connected successfully!');
        } else {
            set_flash('error', 'Failed to save gateway settings: ' . ($res['error'] ?? 'Unknown error'));
        }
        redirect(APP_URL . '/payment-integrations.php');
    }

    if ($action === 'start_razorpay_oauth') {
        if (!razorpay_oauth_configured()) {
            set_flash('error', 'Razorpay Authorize is not configured on this server yet. Please ask your OminiFlow administrator to add Razorpay Partner OAuth (Client ID & Secret) — same as Zoho POS uses.');
            redirect(asset('payment-integrations.php'));
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['razorpay_oauth_state'] = $state;
        redirect(razorpay_oauth_authorize_url($state));
    }

    if ($action === 'disconnect_gateway') {
        $gatewayCode = trim($_POST['gateway_code'] ?? '');
        if ($gatewayCode) {
            disconnect_payment_integration($gatewayCode);
            set_flash('success', 'Payment gateway disconnected successfully.');
        }
        redirect(APP_URL . '/payment-integrations.php');
    }
}

// Fetch all payment gateways merged with DB configs
$gateways = get_payment_integrations();
$rzp = $gateways['razorpay'];
$rzpWebhookUrl = !empty($rzp['is_configured']) ? razorpay_webhook_url_for($rzp) : '';
$openRazorpayWebhook = !empty($_GET['razorpay_webhook']) && !empty($rzp['is_configured']);
$openRazorpayConnect = !empty($_GET['razorpay_connect']) && empty($rzp['is_configured']);
$isLocalHost = is_local_app_host();
$razorpayOAuthReady = razorpay_oauth_configured();
$rzpRec = $rzp['db_record'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .pay-page-container {
            background: #ffffff;
            min-height: calc(100vh - 60px);
            padding: 24px 36px 80px;
        }

        .pay-header {
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .pay-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.01em;
            margin: 0;
        }

        .pay-cards-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-width: 1100px;
        }

        .pay-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 22px 26px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .pay-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
        }

        .pay-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .pay-card-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pay-card-badge {
            font-size: 12.5px;
            color: #64748b;
            font-weight: 500;
            letter-spacing: 0.01em;
        }

        .pay-card-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ecfdf5;
            color: #047857;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
        }

        .pay-card-desc {
            font-size: 13.5px;
            color: #334155;
            line-height: 1.55;
            margin: 14px 0 18px;
            max-width: 980px;
        }

        .pay-card-actions {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .pay-btn-setup {
            background: #3b82f6;
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            padding: 7px 18px;
            border-radius: 5px;
            border: 0;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.12s ease;
        }

        .pay-btn-setup:hover {
            background: #2563eb;
        }

        .pay-btn-manage {
            background: #f1f5f9;
            color: #1e293b;
            border: 1px solid #cbd5e1;
            font-size: 13px;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.12s ease;
        }

        .pay-btn-manage:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .pay-link-learn {
            font-size: 13px;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.12s;
        }

        .pay-link-learn:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .pay-link-signup {
            display: block;
            margin-top: 14px;
            font-size: 12.5px;
            color: #2563eb;
            text-decoration: none;
            font-weight: 500;
        }

        .pay-link-signup:hover {
            text-decoration: underline;
        }

        .pay-section-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 8px 0 14px;
        }
        .pay-active-pill {
            display: inline-flex;
            align-items: center;
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.04em;
            padding: 3px 8px;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .pay-icon-btn {
            width: 36px;
            height: 36px;
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 6px;
            cursor: pointer;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .pay-icon-btn:hover { color: #ef4444; border-color: #fecaca; background: #fef2f2; }
        .pay-note-list { margin: 12px 0 0; padding-left: 18px; color: #334155; font-size: 13.5px; line-height: 1.7; }
        .pay-note-list a { color: #2563eb; text-decoration: none; font-weight: 600; }
        .pay-note-list a:hover { text-decoration: underline; }
        .webhook-steps { list-style: none; margin: 0; padding: 0; }
        .webhook-steps li {
            display: flex;
            gap: 12px;
            margin-bottom: 14px;
            font-size: 14px;
            color: #1e293b;
            line-height: 1.5;
        }
        .webhook-num {
            flex: 0 0 22px;
            height: 22px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: 1px;
        }
        .webhook-url {
            color: #db2777;
            font-size: 13px;
            word-break: break-all;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .webhook-modal-zoho .modal-box {
            max-width: 680px;
            border-radius: 4px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.18);
        }
        .webhook-modal-zoho .modal-header {
            padding: 18px 22px 14px;
            border-bottom: 1px solid #eef2f7;
        }
        .webhook-modal-zoho .modal-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.45;
            padding-right: 28px;
        }
        .webhook-modal-zoho .modal-body {
            padding: 18px 22px 10px;
        }
        .webhook-modal-zoho .modal-footer {
            padding: 14px 22px 18px;
            border-top: 0;
            background: #fff;
            justify-content: flex-end;
            gap: 16px;
        }
        .webhook-modal-zoho .webhook-footer-note {
            flex: 1;
            font-size: 13.5px;
            color: #334155;
            line-height: 1.55;
            margin: 0;
        }
        .webhook-modal-zoho .webhook-url-box {
            display: block;
            margin-top: 10px;
            padding: 10px 12px;
            background: #fdf2f8;
            border-radius: 2px;
            color: #db2777;
            font-size: 13px;
            line-height: 1.45;
            word-break: break-all;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .webhook-copy-btn {
            margin-top: 8px;
            font-size: 12px;
            font-weight: 600;
            color: #2563eb;
            background: none;
            border: 0;
            padding: 0;
            cursor: pointer;
            text-decoration: underline;
        }
        .webhook-local-hint {
            margin-top: 14px;
            padding: 10px 12px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 6px;
            font-size: 12.5px;
            color: #92400e;
            line-height: 1.5;
        }
        .pay-know-why {
            color: #2563eb;
            font-weight: 600;
            text-decoration: none;
            margin-left: 4px;
        }
        .pay-know-why:hover { text-decoration: underline; }

        /* Gateway Custom Brand Logos */
        .logo-razorpay {
            font-size: 20px;
            font-weight: 900;
            color: #0c2340;
            letter-spacing: -0.04em;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .logo-razorpay span {
            color: #3395ff;
        }

        .logo-paytm {
            font-size: 18px;
            font-weight: 800;
            color: #002e6e;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .logo-paytm .pg-tag {
            background: #00b9f5;
            color: #ffffff;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 3px;
            letter-spacing: 0.5px;
        }

        .logo-stripe {
            font-size: 22px;
            font-weight: 900;
            color: #635bff;
            letter-spacing: -0.05em;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .logo-verifone {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .logo-verifone .vf-dots {
            display: grid;
            grid-template-columns: repeat(3, 4px);
            gap: 3px;
        }
        .logo-verifone .vf-dots span {
            width: 4px;
            height: 4px;
            background: #0f172a;
            border-radius: 50%;
        }
        .logo-verifone .vf-text {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.1;
        }
        .logo-verifone .vf-text small {
            display: block;
            font-size: 10px;
            color: #64748b;
            font-weight: 500;
        }

        .logo-pinelabs {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .logo-pinelabs .pl-text {
            font-size: 18px;
            font-weight: 900;
            color: #005a36;
            letter-spacing: -0.03em;
        }
        .logo-pinelabs small {
            display: block;
            font-size: 9px;
            color: #64748b;
            font-weight: 600;
            letter-spacing: 0.2px;
        }

        .logo-phonepe {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .logo-phonepe .pe-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #5f259f;
            color: #ffffff;
            font-size: 16px;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logo-phonepe .pe-text {
            font-size: 17px;
            font-weight: 800;
            color: #5f259f;
            letter-spacing: -0.02em;
        }

        .logo-worldline {
            font-size: 18px;
            font-weight: 900;
            color: #008784;
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .logo-worldline .wl-wave {
            color: #7ececf;
            font-weight: normal;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(2px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 580px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalFadeUp 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalFadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            padding: 16px 22px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
        }

        .modal-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .modal-close-btn {
            background: transparent;
            border: 0;
            font-size: 22px;
            color: #64748b;
            cursor: pointer;
            line-height: 1;
            padding: 0;
        }

        .modal-close-btn:hover {
            color: #0f172a;
        }

        .modal-body {
            padding: 22px;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 14px 22px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <!-- Sidebar Component -->
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Wrapper -->
        <div class="app-main">
            <!-- Header Component -->
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="pay-page-container">
                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success" style="max-width: 1100px; margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger" style="max-width: 1100px; margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Page Header (Exact match with screenshot media_1788239090970.png) -->
                <div class="pay-header">
                    <h1 class="pay-title">Customer Payments</h1>
                </div>

                <!-- Gateways Cards List -->
                <div class="pay-cards-list">

                    <?php if (!empty($rzp['is_configured'])): ?>
                    <h2 class="pay-section-title">Connected Payment Gateways</h2>
                    <?php
                    $rzpExtra = $rzp['extra_config_data'] ?? [];
                    $rzpOAuthConnected = (($rzp['connect_mode'] ?? '') === 'oauth') || !empty($rzpExtra['access_token']);
                    ?>
                    <div class="pay-card">
                        <div class="pay-card-top">
                            <div class="pay-card-brand">
                                <div class="logo-razorpay">
                                    <span>Razorpay</span>
                                </div>
                                <span class="pay-active-pill">Active</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <a href="<?= e($rzp['learn_more_url']) ?>" target="_blank" class="pay-btn-manage" style="text-decoration:none;">Learn More</a>
                                <button type="button" class="pay-icon-btn" title="Disconnect Razorpay" onclick="disconnectGateway('razorpay')">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                        <?php
                        $rzpKeyId = trim((string) ($rzpRec['api_key'] ?? ''));
                        $rzpKeysInvalid = $rzpKeyId !== '' && !razorpay_is_valid_key_id($rzpKeyId);
                        ?>
                        <?php if ($rzpKeysInvalid): ?>
                        <div class="saas-alert saas-alert-danger" style="margin-top:14px;">
                            <span>Invalid Razorpay Key ID saved. Disconnect, then use <strong>Set Up Now</strong> with keys from Dashboard → Settings → API Keys.</span>
                        </div>
                        <?php endif; ?>
                        <p style="margin:12px 0 0;font-size:13.5px;color:#334155;">Online Transaction Fees
                            <a href="https://razorpay.com/pricing/" target="_blank" class="pay-link-learn" style="margin-left:8px;">View Razorpay's Transaction Fees</a>
                        </p>
                        <p style="margin:12px 0 6px;font-size:13.5px;font-weight:700;color:#0f172a;">Note:</p>
                        <ul class="pay-note-list">
                            <li><a href="javascript:void(0)" onclick="openWebhookModal()">Create a webhook</a> to sync payment details with OminiFlow POS. <a href="javascript:void(0)" class="pay-know-why" onclick="openWebhookModal()">Know Why ↗</a></li>
                            <li>Razorpay also supports autocharge, and can be used for recurring payments. However, you will need to <a href="mailto:support@ominiflow.com" class="pay-know-why">contact us</a> to enable this.</li>
                        </ul>
                    </div>
                    <h2 class="pay-section-title" style="margin-top:28px;">Set up Other Payment Gateways</h2>
                    <?php else: ?>
                    <?php $rzp = $gateways['razorpay']; ?>
                    <div class="pay-card">
                        <div class="pay-card-top">
                            <div class="pay-card-brand">
                                <div class="logo-razorpay">
                                    <span>Razorpay</span>
                                </div>
                            </div>
                        </div>
                        <p class="pay-card-desc"><?= e($rzp['description']) ?></p>
                        <div class="pay-card-actions">
                            <?php if ($razorpayOAuthReady): ?>
                            <form method="POST" action="<?= asset('payment-integrations.php') ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="start_razorpay_oauth">
                                <button type="submit" class="pay-btn-setup">Set Up Now</button>
                            </form>
                            <?php else: ?>
                            <button type="button" class="pay-btn-setup" onclick="openRazorpayConnectModal()">Set Up Now</button>
                            <?php endif; ?>
                            <a href="<?= e($rzp['learn_more_url']) ?>" target="_blank" class="pay-link-learn">Learn More</a>
                        </div>
                        <?php if (!$razorpayOAuthReady): ?>
                        <p style="margin-top:12px;font-size:12.5px;color:#64748b;line-height:1.5;">Use your keys from <a href="https://dashboard.razorpay.com/app/keys" target="_blank" rel="noopener">Razorpay Dashboard → API Keys</a>. Partner OAuth (Zoho-style login) activates automatically when enabled on OminiFlow servers.</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- 2. Paytm PG (*Supports In-Store Payments) -->
                    <?php $paytm = $gateways['paytm']; ?>
                    <div class="pay-card">
                        <div class="pay-card-top">
                            <div class="pay-card-brand">
                                <div class="logo-paytm">
                                    <span>Paytm</span>
                                    <span class="pg-tag">PG</span>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span class="pay-card-badge">*Supports In-Store Payments</span>
                                <?php if ($paytm['is_configured']): ?>
                                    <span class="pay-card-status-badge">
                                        <svg width="8" height="8" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4"/></svg>
                                        Active
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="pay-card-desc"><?= e($paytm['description']) ?></p>
                        <div class="pay-card-actions">
                            <?php if ($paytm['is_configured']): ?>
                                <button type="button" class="pay-btn-manage" onclick="openGatewayModal('paytm')">Manage Settings</button>
                            <?php else: ?>
                                <button type="button" class="pay-btn-setup" onclick="openGatewayModal('paytm')">Set Up Now</button>
                            <?php endif; ?>
                        </div>
                        <a href="<?= e($paytm['signup_url']) ?>" target="_blank" class="pay-link-signup"><?= e($paytm['signup_label']) ?></a>
                    </div>

                    <!-- 3. Stripe -->
                    <?php $stripe = $gateways['stripe']; ?>
                    <div class="pay-card">
                        <div class="pay-card-top">
                            <div class="pay-card-brand">
                                <div class="logo-stripe">stripe</div>
                            </div>
                            <?php if ($stripe['is_configured']): ?>
                                <span class="pay-card-status-badge">
                                    <svg width="8" height="8" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4"/></svg>
                                    Active (<?= strtoupper($stripe['environment']) ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="pay-card-desc"><?= e($stripe['description']) ?></p>
                        <div class="pay-card-actions">
                            <?php if ($stripe['is_configured']): ?>
                                <button type="button" class="pay-btn-manage" onclick="openGatewayModal('stripe')">Manage Settings</button>
                            <?php else: ?>
                                <button type="button" class="pay-btn-setup" onclick="openGatewayModal('stripe')">Set Up Now</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 4. 2Checkout / Verifone -->
                    <?php $verifone = $gateways['verifone']; ?>
                    <div class="pay-card">
                        <div class="pay-card-top">
                            <div class="pay-card-brand">
                                <div class="logo-verifone">
                                    <div class="vf-dots">
                                        <span></span><span></span><span></span>
                                        <span></span><span></span><span></span>
                                    </div>
                                    <div class="vf-text">
                                        <small>2Checkout is now</small>
                                        verifone
                                    </div>
                                </div>
                            </div>
                            <?php if ($verifone['is_configured']): ?>
                                <span class="pay-card-status-badge">
                                    <svg width="8" height="8" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4"/></svg>
                                    Active
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="pay-card-desc"><?= e($verifone['description']) ?></p>
                        <div class="pay-card-actions">
                            <?php if ($verifone['is_configured']): ?>
                                <button type="button" class="pay-btn-manage" onclick="openGatewayModal('verifone')">Manage Settings</button>
                            <?php else: ?>
                                <button type="button" class="pay-btn-setup" onclick="openGatewayModal('verifone')">Set Up Now</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 5. Pine Labs (*Supports In-Store Payments) -->
                    <?php $pinelabs = $gateways['pinelabs']; ?>
                    <div class="pay-card">
                        <div class="pay-card-top">
                            <div class="pay-card-brand">
                                <div class="logo-pinelabs">
                                    <div>
                                        <div class="pl-text">pine labs</div>
                                        <small>Our platform, your move.</small>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span class="pay-card-badge">*Supports In-Store Payments</span>
                                <?php if ($pinelabs['is_configured']): ?>
                                    <span class="pay-card-status-badge">
                                        <svg width="8" height="8" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4"/></svg>
                                        Active
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="pay-card-desc"><?= e($pinelabs['description']) ?></p>
                        <div class="pay-card-actions">
                            <?php if ($pinelabs['is_configured']): ?>
                                <button type="button" class="pay-btn-manage" onclick="openGatewayModal('pinelabs')">Manage Settings</button>
                            <?php else: ?>
                                <button type="button" class="pay-btn-setup" onclick="openGatewayModal('pinelabs')">Set Up Now</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 6. PhonePe (*Supports In-Store Payments) -->
                    <?php $phonepe = $gateways['phonepe']; ?>
                    <div class="pay-card">
                        <div class="pay-card-top">
                            <div class="pay-card-brand">
                                <div class="logo-phonepe">
                                    <div class="pe-icon">पे</div>
                                    <div class="pe-text">PhonePe</div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span class="pay-card-badge">*Supports In-Store Payments</span>
                                <?php if ($phonepe['is_configured']): ?>
                                    <span class="pay-card-status-badge">
                                        <svg width="8" height="8" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4"/></svg>
                                        Active
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="pay-card-desc"><?= e($phonepe['description']) ?></p>
                        <div class="pay-card-actions">
                            <?php if ($phonepe['is_configured']): ?>
                                <button type="button" class="pay-btn-manage" onclick="openGatewayModal('phonepe')">Manage Settings</button>
                            <?php else: ?>
                                <button type="button" class="pay-btn-setup" onclick="openGatewayModal('phonepe')">Set Up Now</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 7. Worldline (*Supports In-Store Payments) -->
                    <?php $worldline = $gateways['worldline']; ?>
                    <div class="pay-card">
                        <div class="pay-card-top">
                            <div class="pay-card-brand">
                                <div class="logo-worldline">
                                    <span>WORLDLINE</span>
                                    <span class="wl-wave">∿∿</span>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span class="pay-card-badge">*Supports In-Store Payments</span>
                                <?php if ($worldline['is_configured']): ?>
                                    <span class="pay-card-status-badge">
                                        <svg width="8" height="8" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4"/></svg>
                                        Active
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="pay-card-desc"><?= e($worldline['description']) ?></p>
                        <div class="pay-card-actions">
                            <?php if ($worldline['is_configured']): ?>
                                <button type="button" class="pay-btn-manage" onclick="openGatewayModal('worldline')">Manage Settings</button>
                            <?php else: ?>
                                <button type="button" class="pay-btn-setup" onclick="openGatewayModal('worldline')">Set Up Now</button>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <!-- Modals for Each Payment Gateway Configuration -->
    <?php foreach ($gateways as $code => $gw):
        if ($code === 'razorpay') {
            continue;
        }
        $rec = $gw['db_record'] ?? [];
        $extra = $rec['extra_config_data'] ?? ($gw['extra_config_data'] ?? []);
        $modalTitle = 'Configure ' . $gw['name'] . ' Integration';
    ?>
    <div class="modal-overlay" id="modal-gw-<?= $code ?>">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title"><?= e($modalTitle) ?></div>
                <button type="button" class="modal-close-btn" onclick="closeGatewayModal('<?= $code ?>')">&times;</button>
            </div>
            <form method="POST" action="<?= asset('payment-integrations.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_gateway">
                <input type="hidden" name="gateway_code" value="<?= e($code) ?>">

                <div class="modal-body">
                    <!-- Environment switcher for online gateways -->
                    <div style="margin-bottom: 16px;">
                        <label class="form-label" style="font-weight: 600; display: block; margin-bottom: 6px;">Mode / Environment</label>
                        <select name="environment" class="form-control" style="width: 100%;">
                            <option value="test" <?= ($gw['environment'] ?? 'test') === 'test' ? 'selected' : '' ?>>Sandbox / Test Mode</option>
                            <option value="live" <?= ($gw['environment'] ?? '') === 'live' ? 'selected' : '' ?>>Production / Live Mode</option>
                        </select>
                    </div>

                    <!-- Dynamic Fields based on Gateway Schema -->
                    <?php foreach ($gw['fields'] as $f): 
                        $fName = $f['name'];
                        $val = $rec[$fName] ?? ($extra[$fName] ?? '');
                    ?>
                    <div style="margin-bottom: 14px;">
                        <label class="form-label <?= !empty($f['required']) ? 'required' : '' ?>" style="display: block; margin-bottom: 6px; font-weight: 600;">
                            <?= e($f['label']) ?>
                        </label>
                        <input
                            type="<?= e($f['type']) ?>"
                            name="<?= e($f['name']) ?>"
                            value="<?= e((string)$val) ?>"
                            placeholder="<?= e($f['placeholder'] ?? '') ?>"
                            class="form-control"
                            style="width: 100%;"
                            <?= !empty($f['required']) ? 'required' : '' ?>
                        >
                    </div>
                    <?php endforeach; ?>

                    <!-- POS Enablement Toggle -->
                    <div style="margin-top: 18px; padding-top: 14px; border-top: 1px dashed #e2e8f0; display: flex; flex-direction: column; gap: 10px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #1e293b; cursor: pointer;">
                            <input type="checkbox" name="enable_in_pos" value="1" <?= !empty($gw['enable_in_pos']) ? 'checked' : '' ?>>
                            <span>Enable this payment option on POS Register checkout</span>
                        </label>

                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #1e293b; cursor: pointer;">
                            <input type="checkbox" name="enable_in_store" value="1" <?= !empty($gw['enable_in_store']) ? 'checked' : '' ?>>
                            <span>Enable for Online Store & Invoicing</span>
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <div>
                        <?php if ($gw['is_configured']): ?>
                            <button type="button" class="btn-secondary" style="color: #ef4444; border-color: #fca5a5;" onclick="disconnectGateway('<?= $code ?>')">Disconnect</button>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn-secondary" onclick="closeGatewayModal('<?= $code ?>')">Cancel</button>
                        <button type="submit" class="btn-primary">Save & Connect</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="modal-overlay webhook-modal-zoho" id="modal-razorpay-connect">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">Connect your Razorpay account to OminiFlow POS</div>
                <button type="button" class="modal-close-btn" onclick="closeRazorpayConnectModal()">&times;</button>
            </div>
            <form method="POST" action="<?= asset('payment-integrations.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="connect_razorpay_merchant">
                <div class="modal-body">
                    <ol class="webhook-steps" style="margin-bottom:18px;">
                        <li><span class="webhook-num">1</span><span>Log in to <a href="https://dashboard.razorpay.com" target="_blank" rel="noopener">dashboard.razorpay.com</a> with your Razorpay account.</span></li>
                        <li><span class="webhook-num">2</span><span>Go to <strong>Settings</strong> → <strong>API Keys</strong> (Test or Live mode).</span></li>
                        <li><span class="webhook-num">3</span><span>Copy your <strong>Key ID</strong> (<code>rzp_test_...</code> or <code>rzp_live_...</code>) and <strong>Key Secret</strong>, then paste below.</span></li>
                    </ol>
                    <div style="margin-bottom:14px;">
                        <label class="form-label required" style="display:block;margin-bottom:6px;font-weight:600;">Key ID</label>
                        <input type="text" name="api_key" class="form-control" style="width:100%;font-family:monospace;" placeholder="rzp_test_xxxxxxxx" required autocomplete="off" value="<?= e((string)($rzpRec['api_key'] ?? '')) ?>">
                    </div>
                    <div style="margin-bottom:14px;">
                        <label class="form-label required" style="display:block;margin-bottom:6px;font-weight:600;">Key Secret</label>
                        <input type="password" name="api_secret" class="form-control" style="width:100%;" placeholder="Enter Key Secret from Razorpay dashboard" required autocomplete="new-password">
                    </div>
                    <div style="margin-bottom:14px;">
                        <label class="form-label" style="display:block;margin-bottom:6px;font-weight:600;">Webhook Secret (optional)</label>
                        <input type="text" name="webhook_secret" class="form-control" style="width:100%;" placeholder="Paste after you create webhook in Razorpay (for signature verify)">
                    </div>
                    <div style="padding-top:12px;border-top:1px dashed #e2e8f0;display:flex;flex-direction:column;gap:10px;">
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#1e293b;cursor:pointer;">
                            <input type="checkbox" name="enable_in_pos" value="1" checked>
                            <span>Enable this payment option on POS Register checkout</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#1e293b;cursor:pointer;">
                            <input type="checkbox" name="enable_in_store" value="1" checked>
                            <span>Enable for Online Store & Invoicing</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn-secondary" onclick="closeRazorpayConnectModal()">Cancel</button>
                    <button type="submit" class="pay-btn-setup">Save & Connect</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay webhook-modal-zoho" id="modal-razorpay-webhook">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">Steps to create a webhook for syncing payment details with OminiFlow POS</div>
                <button type="button" class="modal-close-btn" onclick="closeWebhookModal()">&times;</button>
            </div>
            <div class="modal-body">
                <ol class="webhook-steps">
                    <li><span class="webhook-num">1</span><span>Log in to your Razorpay dashboard at <a href="https://dashboard.razorpay.com" target="_blank" rel="noopener">https://dashboard.razorpay.com</a></span></li>
                    <li><span class="webhook-num">2</span><span>Go to <strong>Settings</strong> and select <strong>Webhooks</strong> tab.</span></li>
                    <li><span class="webhook-num">3</span><span>Click <strong>Add New Webhook</strong>.</span></li>
                    <li><span class="webhook-num">4</span><span>Copy and paste the following URL in the <strong>Webhook URL</strong> field in the pop-up.
                        <span class="webhook-url-box" id="razorpayWebhookUrlText"><?= e($rzpWebhookUrl) ?></span>
                        <button type="button" class="webhook-copy-btn" onclick="copyRazorpayWebhookUrl()">Copy URL</button>
                    </span></li>
                    <li><span class="webhook-num">5</span><span>Check the <strong>Payment Events</strong> option and check <strong>payment.authorized</strong> and <strong>payment.captured</strong> under it.</span></li>
                    <li><span class="webhook-num">6</span><span>Click <strong>Create Webhook</strong>.</span></li>
                </ol>
                <?php if ($isLocalHost): ?>
                <div class="webhook-local-hint">
                    On localhost, Razorpay cannot reach your PC directly. Use this URL on production (<strong>pos.ominiflow.com</strong>) or expose local with a tunnel (e.g. ngrok) and paste that public URL in Razorpay instead.
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <p class="webhook-footer-note">Now, a webhook will be created and the payment details will be synced with OminiFlow POS. <a href="https://razorpay.com/docs/webhooks/" target="_blank" rel="noopener" class="pay-link-learn">Learn More</a></p>
                <button type="button" class="pay-btn-setup" style="min-width:88px;" onclick="closeWebhookModal()">Okay</button>
            </div>
        </div>
    </div>

    <!-- Hidden Disconnect Form -->
    <form id="disconnectForm" method="POST" action="<?= asset('payment-integrations.php') ?>" style="display: none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="disconnect_gateway">
        <input type="hidden" name="gateway_code" id="disconnectGatewayCode" value="">
    </form>

    <script>
        function openGatewayModal(code) {
            const modal = document.getElementById('modal-gw-' + code);
            if (modal) {
                modal.classList.add('open');
            }
        }

        function closeGatewayModal(code) {
            const modal = document.getElementById('modal-gw-' + code);
            if (modal) {
                modal.classList.remove('open');
            }
        }

        function disconnectGateway(code) {
            if (confirm('Are you sure you want to disconnect this payment integration?')) {
                document.getElementById('disconnectGatewayCode').value = code;
                document.getElementById('disconnectForm').submit();
            }
        }

        function openRazorpayConnectModal() {
            const modal = document.getElementById('modal-razorpay-connect');
            if (modal) modal.classList.add('open');
        }
        function closeRazorpayConnectModal() {
            const modal = document.getElementById('modal-razorpay-connect');
            if (modal) modal.classList.remove('open');
        }

        function openWebhookModal() {
            const modal = document.getElementById('modal-razorpay-webhook');
            if (modal) modal.classList.add('open');
        }
        function closeWebhookModal() {
            const modal = document.getElementById('modal-razorpay-webhook');
            if (modal) modal.classList.remove('open');
        }

        function copyRazorpayWebhookUrl() {
            const el = document.getElementById('razorpayWebhookUrlText');
            const text = el ? el.textContent.trim() : '';
            if (!text) return;
            navigator.clipboard.writeText(text).then(function () {
                alert('Webhook URL copied to clipboard.');
            }).catch(function () {
                window.prompt('Copy this webhook URL:', text);
            });
        }

        <?php if ($openRazorpayWebhook): ?>
        document.addEventListener('DOMContentLoaded', openWebhookModal);
        <?php endif; ?>
        <?php if ($openRazorpayConnect): ?>
        document.addEventListener('DOMContentLoaded', openRazorpayConnectModal);
        <?php endif; ?>

        // Close on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
            }
        });
    </script>
</body>
</html>

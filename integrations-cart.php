<?php
/**
 * OminiFlow POS - Shopping Cart Integrations Hub (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

require_auth();

$pageTitle = 'Shopping Cart';
$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$db = get_db();

// Handle Ecommerce / Shopping Cart Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/integrations-cart.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'connect_shopify') {
        $storeName = trim($_POST['store_name'] ?? '');
        $clientId = trim($_POST['client_id'] ?? '');
        $clientSecret = trim($_POST['client_secret'] ?? '');

        // Remove .myshopify.com if user accidentally typed it
        $storeName = str_ireplace('.myshopify.com', '', $storeName);
        $storeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $storeName);

        if (!$storeName) {
            set_flash('error', 'Please enter your Shopify store name.');
        } elseif (!$clientId || !$clientSecret) {
            set_flash('error', 'Both Client ID and Client Secret are required.');
        } else {
            $storeUrl = "https://{$storeName}.myshopify.com";
            $stmt = $db->prepare('
                INSERT INTO ecommerce_integrations (platform_code, store_name, store_url, client_id, client_secret, status, last_synced_at, updated_at)
                VALUES ("shopify", :name, :url, :cid, :csec, "connected", NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    store_name = VALUES(store_name),
                    store_url = VALUES(store_url),
                    client_id = VALUES(client_id),
                    client_secret = VALUES(client_secret),
                    status = "connected",
                    last_synced_at = NOW(),
                    updated_at = NOW()
            ');
            $stmt->execute([
                'name' => $storeName,
                'url' => $storeUrl,
                'cid' => $clientId,
                'csec' => $clientSecret,
            ]);

            set_flash('success', "Shopify store '{$storeName}.myshopify.com' connected successfully! Automatic 2-way stock sync is active.");
        }
        redirect(APP_URL . '/integrations-cart.php');
    }

    if ($action === 'connect_woocommerce') {
        $storeUrl = trim($_POST['store_url'] ?? '');
        $consumerKey = trim($_POST['consumer_key'] ?? '');
        $consumerSecret = trim($_POST['consumer_secret'] ?? '');

        if (!$storeUrl || !$consumerKey || !$consumerSecret) {
            set_flash('error', 'Please enter Store URL, Consumer Key, and Consumer Secret.');
        } else {
            $stmt = $db->prepare('
                INSERT INTO ecommerce_integrations (platform_code, store_name, store_url, client_id, client_secret, status, last_synced_at, updated_at)
                VALUES ("woocommerce", "WooCommerce Store", :url, :cid, :csec, "connected", NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    store_url = VALUES(store_url),
                    client_id = VALUES(client_id),
                    client_secret = VALUES(client_secret),
                    status = "connected",
                    last_synced_at = NOW(),
                    updated_at = NOW()
            ');
            $stmt->execute([
                'url' => $storeUrl,
                'cid' => $consumerKey,
                'csec' => $consumerSecret,
            ]);

            set_flash('success', "WooCommerce connected successfully! Real-time order sync enabled.");
        }
        redirect(APP_URL . '/integrations-cart.php');
    }

    if ($action === 'disconnect_ecommerce') {
        $platform = trim($_POST['platform_code'] ?? '');
        if ($platform) {
            $db->prepare('UPDATE ecommerce_integrations SET status = "disconnected" WHERE platform_code = :p')->execute(['p' => $platform]);
            set_flash('success', 'Store disconnected successfully.');
        }
        redirect(APP_URL . '/integrations-cart.php');
    }
}

// Fetch Connected Ecommerce Platforms
$connectedPlatforms = [];
try {
    $stmtC = $db->query('SELECT * FROM ecommerce_integrations WHERE status = "connected"');
    foreach ($stmtC->fetchAll() as $row) {
        $connectedPlatforms[$row['platform_code']] = $row;
    }
} catch (Exception $e) {}
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
        .cart-page-container {
            background: #ffffff;
            min-height: calc(100vh - 70px);
            padding: 24px 36px 80px;
        }

        /* Top Header & Search Bar */
        .cart-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .cart-topbar-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .cart-search-input {
            width: 240px;
            height: 36px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0 12px 0 32px;
            font-size: 13px;
            color: #0f172a;
            outline: none;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%2394a3b8' viewBox='0 0 24 24'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'/%3E%3C/svg%3E") no-repeat 10px center #ffffff;
            transition: all 0.15s ease;
        }

        .cart-search-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
            width: 280px;
        }

        /* Shopping Cart Cards List (Exact match with media_1787137498700.png) */
        .cart-cards-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
            max-width: 960px;
        }

        .cart-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 24px;
            display: flex;
            align-items: flex-start;
            gap: 24px;
            transition: box-shadow 0.15s ease;
        }

        .cart-card:hover {
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
            border-color: #cbd5e1;
        }

        .cart-logo-wrap {
            width: 90px;
            height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #f1f5f9;
        }

        .cart-card-body {
            flex: 1;
        }

        .cart-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .cart-title-wrap {
            display: flex;
            align-items: baseline;
            gap: 10px;
        }

        .cart-title-text {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
        }

        .cart-tag-italic {
            font-size: 12px;
            font-style: italic;
            color: #64748b;
            font-weight: 500;
        }

        .cart-desc-text {
            font-size: 13px;
            color: #334155;
            line-height: 1.6;
            margin-bottom: 14px;
            max-width: 780px;
        }

        /* Orange Info Box (Exact match with media_1787137498700.png) */
        .cart-info-alert {
            background: #fff7ed;
            border: 1px solid #ffedd5;
            border-radius: 8px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12.5px;
            color: #9a3412;
            margin-bottom: 18px;
            max-width: 780px;
        }

        .cart-info-icon {
            color: #ea580c;
            font-size: 14px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .cart-footer-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 780px;
        }

        .btn-cart-setup {
            background: #3b82f6;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 8px 20px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-cart-setup:hover {
            background: #2563eb;
        }

        .cart-learn-more-link {
            font-size: 13px;
            color: #2563eb;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
        }

        .cart-learn-more-link:hover {
            text-decoration: underline;
        }

        /* 2-COLUMN SHOPIFY SETUP MODAL (Exact match with media_1787137496033.png) */
        .modal-shopify-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(2px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-shopify-overlay.show {
            display: flex;
        }

        .modal-shopify-box {
            background: #ffffff;
            border-radius: 10px;
            width: 100%;
            max-width: 920px;
            box-shadow: 0 25px 30px -5px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            max-height: 90vh;
            animation: modalFadeIn 0.18s ease-out;
        }

        /* Left Column: Setup Steps */
        .shopify-steps-col {
            background: #ffffff;
            border-right: 1px solid #e2e8f0;
            padding: 28px 24px;
            overflow-y: auto;
        }

        .shopify-steps-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 14px;
        }

        .shopify-steps-list {
            padding-left: 20px;
            margin: 0;
            font-size: 12.5px;
            color: #334155;
            line-height: 1.65;
        }

        .shopify-steps-list li {
            margin-bottom: 8px;
        }

        .shopify-steps-list strong {
            color: #0f172a;
        }

        /* Blue Scopes Box with Copy Action */
        .shopify-scopes-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 10px 12px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 11px;
            color: #1e40af;
            line-height: 1.45;
            margin: 8px 0;
            position: relative;
            word-break: break-all;
        }

        .btn-copy-scopes {
            position: absolute;
            top: 6px;
            right: 6px;
            background: #dbeafe;
            border: 1px solid #93c5fd;
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 10px;
            font-weight: 700;
            color: #1d4ed8;
            cursor: pointer;
        }

        .btn-copy-scopes:hover {
            background: #bfdbfe;
        }

        /* Right Column: Store Configuration */
        .shopify-form-col {
            background: #ffffff;
            padding: 28px 24px 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow-y: auto;
            position: relative;
        }

        .shopify-close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: transparent;
            border: 0;
            color: #3b82f6;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
            padding: 0;
        }

        .shopify-modal-brand-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
        }

        .shopify-form-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 18px;
        }

        .modal-shopify-field {
            margin-bottom: 18px;
        }

        .modal-shopify-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #ef4444;
            margin-bottom: 6px;
        }

        .shopify-input-suffix-group {
            display: flex;
            align-items: center;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            background: #ffffff;
        }

        .shopify-input-suffix-group:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .shopify-input-suffix-group input {
            border: none;
            outline: none;
            padding: 0 12px;
            height: 36px;
            font-size: 13px;
            color: #0f172a;
            flex: 1;
        }

        .shopify-suffix-text {
            background: #f8fafc;
            border-left: 1px solid #e2e8f0;
            padding: 0 12px;
            height: 36px;
            display: flex;
            align-items: center;
            font-size: 12.5px;
            color: #64748b;
        }

        .shopify-field-input {
            width: 100%;
            height: 36px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0 12px;
            font-size: 13px;
            color: #0f172a;
            outline: none;
        }

        .shopify-field-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .shopify-helper-subtext {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 5px;
            line-height: 1.4;
        }

        .shopify-helper-url {
            color: #475569;
            font-family: ui-monospace, monospace;
            font-size: 11px;
            display: block;
            margin-top: 2px;
        }

        .shopify-form-footer {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-top: 18px;
            margin-top: 20px;
        }

        .btn-modal-connect {
            background: #3b82f6;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 8px 22px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-modal-connect:hover {
            background: #2563eb;
        }

        .btn-modal-close-gray {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-modal-close-gray:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
    </style>
</head>
<body class="app-body">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="cart-page-container">
            <?php if ($flashSuccess): ?>
                <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px 18px; border-radius: 8px; font-size: 13.5px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; max-width: 960px;">
                    <span>✓ <?= e($flashSuccess) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 16px; color: #166534; cursor: pointer;">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 18px; border-radius: 8px; font-size: 13.5px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; max-width: 960px;">
                    <span>⚠ <?= e($flashError) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 16px; color: #991b1b; cursor: pointer;">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Top Header & Search Bar -->
            <div class="cart-topbar">
                <h1 class="cart-topbar-title">Shopping Cart</h1>
                <div>
                    <input type="text" id="cartSearchInput" class="cart-search-input" placeholder="Search your apps" oninput="filterCartApps(this.value)">
                </div>
            </div>

            <!-- Shopping Cart Cards List -->
            <div class="cart-cards-list" id="cartCardsList">
                <!-- 1. SHOPIFY CARD (Exact match with media_1787137498700.png) -->
                <?php $shopifyConn = isset($connectedPlatforms['shopify']); ?>
                <div class="cart-card" data-name="shopify">
                    <div class="cart-logo-wrap">
                        <!-- Shopify Bag SVG Logo -->
                        <svg width="56" height="56" viewBox="0 0 109 124" fill="none">
                            <path d="M72.5 19.3L64.3 16.9C64.3 13.5 63 10.3 60.5 7.9C58.1 5.5 54.8 4.2 51.4 4.2C48 4.2 44.8 5.5 42.3 7.9C39.8 10.3 38.5 13.5 38.5 16.9L30.6 19.3L20.8 113.8L82.1 119.8L72.5 19.3Z" fill="#95BF47"/>
                            <path d="M64.3 16.9L38.5 16.9C38.5 13.5 39.8 10.3 42.3 7.9C44.8 5.5 48 4.2 51.4 4.2C54.8 4.2 58.1 5.5 60.5 7.9C63 10.3 64.3 13.5 64.3 16.9Z" fill="#5E8E3E"/>
                            <path d="M51.4 11.2C52.9 11.2 54.3 11.8 55.4 12.9C56.5 14 57.1 15.4 57.1 16.9L45.7 16.9C45.7 15.4 46.3 14 47.4 12.9C48.5 11.8 49.9 11.2 51.4 11.2Z" fill="#FFFFFF"/>
                            <path d="M64.6 42.8C64.3 42.4 63.8 42.1 63.3 42.1C62.8 42.1 62.3 42.4 62 42.8C59.9 45.4 57 47.7 53.6 49.3C50.2 51 46.4 51.9 42.5 52C40.6 52 38.7 51.8 36.8 51.4L45.3 117.8L82.1 119.8L72.5 19.3L64.6 42.8Z" fill="#88B544"/>
                            <!-- S Letter in Shopify Bag -->
                            <path d="M58.3 64.6C54.4 64.1 52.8 63 52.8 60.9C52.8 58.3 55.4 57 58.7 57C61.8 57 64 58.3 65.6 59.9C66 60.3 66.6 60.4 67.1 60.2C67.6 60 68 59.6 68.1 59L69.8 50.8C69.9 50.3 69.7 49.8 69.3 49.5C68.9 49.2 68.4 49.1 67.9 49.3C64.7 48 61.2 47.3 57.7 47.3C52.2 47.3 47.3 49 44.1 52.2C41.2 55.1 39.7 59.1 39.7 63.4C39.7 73.1 47.4 77.2 54.4 78.7C58.3 79.5 60.2 80.9 60.2 83.2C60.2 85.5 57.7 87.2 53.8 87.2C49.7 87.2 46.4 85.1 44.5 82.9C44.1 82.5 43.5 82.3 43 82.5C42.5 82.7 42.1 83.1 42 83.6L39.8 92.5C39.7 93 39.9 93.5 40.3 93.8C40.7 94.1 41.2 94.2 41.7 94C45.7 95.8 49.9 96.7 54.2 96.7C60.2 96.7 65.5 94.8 68.8 91.5C72 88.3 73.6 83.9 73.6 79.1C73.6 69.3 66 65.6 58.3 64.6Z" fill="#FFFFFF"/>
                        </svg>
                    </div>

                    <div class="cart-card-body">
                        <div class="cart-card-header">
                            <div class="cart-title-wrap">
                                <span class="cart-title-text">Shopify</span>
                                <span class="cart-tag-italic">Integration Built by Ominiflow</span>
                            </div>
                        </div>

                        <div class="cart-desc-text">
                            Integrate your Shopify store with OminiFlow POS to bridge the gap between your sales channel and inventory management giving you the ability to tackle any number of online orders with ease. You can keep your stock in continuous sync in both the platforms with this integration.
                        </div>

                        <!-- Orange Info Alert -->
                        <div class="cart-info-alert">
                            <span class="cart-info-icon">ⓘ</span>
                            <span>You'll have to be registered with the same email ID in both Shopify and OminiFlow POS to configure this integration.</span>
                        </div>

                        <div class="cart-footer-actions">
                            <?php if ($shopifyConn): ?>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <span style="background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; border-radius: 6px; padding: 6px 14px; font-size: 13px; font-weight: 700;">✓ Connected (<?= e($connectedPlatforms['shopify']['store_name']) ?>.myshopify.com)</span>
                                    <button type="button" class="btn-cart-setup" onclick="openShopifyModal()">Configure</button>
                                    <form method="POST" action="<?= asset('integrations-cart.php') ?>" style="display: inline;" onsubmit="return confirm('Disconnect Shopify integration?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="disconnect_ecommerce">
                                        <input type="hidden" name="platform_code" value="shopify">
                                        <button type="submit" style="background: none; border: none; color: #ef4444; font-size: 13px; font-weight: 600; cursor: pointer;">Disconnect</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <button type="button" class="btn-cart-setup" onclick="openShopifyModal()">
                                    Set Up Now
                                </button>
                            <?php endif; ?>

                            <a href="javascript:void(0)" class="cart-learn-more-link" onclick="alert('Learn more about Shopify synchronization with OminiFlow POS.')">
                                <span>ⓘ</span>
                                <span>Learn More</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 2. WOOCOMMERCE CARD -->
                <?php $wooConn = isset($connectedPlatforms['woocommerce']); ?>
                <div class="cart-card" data-name="woocommerce">
                    <div class="cart-logo-wrap" style="background: #fdf4ff;">
                        <span style="font-weight: 900; font-size: 18px; color: #9333ea; font-style: italic;">Woo</span>
                    </div>

                    <div class="cart-card-body">
                        <div class="cart-card-header">
                            <div class="cart-title-wrap">
                                <span class="cart-title-text">WooCommerce</span>
                                <span class="cart-tag-italic">Integration Built by Ominiflow</span>
                            </div>
                        </div>

                        <div class="cart-desc-text">
                            Integrate your WooCommerce WordPress store with OminiFlow POS to bridge the gap between your online store and offline retail counters. Keep items, customer databases, and stock levels synchronized automatically.
                        </div>

                        <div class="cart-info-alert" style="background: #faf5ff; border-color: #f3e8ff; color: #6b21a8;">
                            <span class="cart-info-icon" style="color: #9333ea;">ⓘ</span>
                            <span>Requires WordPress REST API v3 and WooCommerce Consumer Key & Secret configured with Read/Write access.</span>
                        </div>

                        <div class="cart-footer-actions">
                            <?php if ($wooConn): ?>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <span style="background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; border-radius: 6px; padding: 6px 14px; font-size: 13px; font-weight: 700;">✓ Connected</span>
                                    <form method="POST" action="<?= asset('integrations-cart.php') ?>" style="display: inline;" onsubmit="return confirm('Disconnect WooCommerce integration?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="disconnect_ecommerce">
                                        <input type="hidden" name="platform_code" value="woocommerce">
                                        <button type="submit" style="background: none; border: none; color: #ef4444; font-size: 13px; font-weight: 600; cursor: pointer;">Disconnect</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <button type="button" class="btn-cart-setup" onclick="openWooModal()">
                                    Set Up Now
                                </button>
                            <?php endif; ?>

                            <a href="javascript:void(0)" class="cart-learn-more-link" onclick="alert('Learn more about WooCommerce synchronization with OminiFlow POS.')">
                                <span>ⓘ</span>
                                <span>Learn More</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- 2-COLUMN SHOPIFY SETUP MODAL (Exact match with media_1787137496033.png) -->
    <div class="modal-shopify-overlay" id="shopifyModal">
        <div class="modal-shopify-box">
            <!-- Left Column: Step-by-Step Instructions -->
            <div class="shopify-steps-col">
                <div class="shopify-steps-title">Steps to setup :</div>
                <ul class="shopify-steps-list">
                    <li>Log in to your Shopify account.</li>
                    <li>Go to <strong>Settings &gt; Apps &gt; Develop Apps</strong>.</li>
                    <li>Click <strong>Build apps in Dev Dashboard</strong> to open the Dev Dashboard.</li>
                    <li>In the <strong>Apps</strong> page, Click <strong>Create App</strong> in the top right corner.</li>
                    <li>Enter an App Name in the <strong>Start from Dev Dashboard</strong> section and click <strong>Create</strong>.</li>
                    <li>In the <strong>Version</strong> creation page, set the App URL to <code>'https://shopify.dev/apps/default-app-home'</code></li>
                    <li>
                        Under <strong>Scopes</strong>, copy and paste the required API scopes listed below:
                        <div class="shopify-scopes-box">
                            <button type="button" class="btn-copy-scopes" onclick="copyScopes()">Copy Scopes</button>
                            <span id="scopesText">read_all_orders, read_inventory, write_inventory, read_products, write_products, read_customers, read_orders, write_orders, read_fulfillments, write_fulfillments, read_locations, read_merchant_managed_fulfillment_orders, write_merchant_managed_fulfillment_orders, read_returns, write_returns, read_payment_terms</span>
                        </div>
                    </li>
                    <li>Click <strong>Release</strong></li>
                    <li>In the left sidebar of the Dev Dashboard, click <strong>Home</strong> page. Click <strong>Install app</strong> in the right corner.</li>
                    <li>Select the store in which you want this installed and approve the permissions.</li>
                    <li>In the left sidebar of the Dev Dashboard, click on the app that you've created and click <strong>Settings</strong> and look for <strong>Credentials</strong> section</li>
                    <li>Now, Copy the <strong>Client ID</strong> and <strong>Secret</strong>.</li>
                    <li>Paste them here and click <strong>Connect</strong>.</li>
                </ul>
            </div>

            <!-- Right Column: Store Configuration Form -->
            <div class="shopify-form-col">
                <button type="button" class="shopify-close-btn" onclick="closeShopifyModal()">&times;</button>

                <form method="POST" action="<?= asset('integrations-cart.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="connect_shopify">

                    <div>
                        <!-- Header Brand -->
                        <div class="shopify-modal-brand-header">
                            <svg width="24" height="24" viewBox="0 0 109 124" fill="none">
                                <path d="M72.5 19.3L64.3 16.9C64.3 13.5 63 10.3 60.5 7.9C58.1 5.5 54.8 4.2 51.4 4.2C48 4.2 44.8 5.5 42.3 7.9C39.8 10.3 38.5 13.5 38.5 16.9L30.6 19.3L20.8 113.8L82.1 119.8L72.5 19.3Z" fill="#95BF47"/>
                                <path d="M58.3 64.6C54.4 64.1 52.8 63 52.8 60.9C52.8 58.3 55.4 57 58.7 57C61.8 57 64 58.3 65.6 59.9C66 60.3 66.6 60.4 67.1 60.2C67.6 60 68 59.6 68.1 59L69.8 50.8C69.9 50.3 69.7 49.8 69.3 49.5C68.9 49.2 68.4 49.1 67.9 49.3C64.7 48 61.2 47.3 57.7 47.3C52.2 47.3 47.3 49 44.1 52.2C41.2 55.1 39.7 59.1 39.7 63.4C39.7 73.1 47.4 77.2 54.4 78.7C58.3 79.5 60.2 80.9 60.2 83.2C60.2 85.5 57.7 87.2 53.8 87.2C49.7 87.2 46.4 85.1 44.5 82.9C44.1 82.5 43.5 82.3 43 82.5C42.5 82.7 42.1 83.1 42 83.6L39.8 92.5C39.7 93 39.9 93.5 40.3 93.8C40.7 94.1 41.2 94.2 41.7 94C45.7 95.8 49.9 96.7 54.2 96.7C60.2 96.7 65.5 94.8 68.8 91.5C72 88.3 73.6 83.9 73.6 79.1C73.6 69.3 66 65.6 58.3 64.6Z" fill="#FFFFFF"/>
                            </svg>
                            <span style="font-weight: 800; font-size: 16px; color: #0f172a;">shopify</span>
                            <span style="font-size: 11.5px; font-style: italic; color: #64748b;">Integration Built by Ominiflow</span>
                        </div>

                        <div class="shopify-form-section-title">Enter your store configuration</div>

                        <!-- Store Name Field -->
                        <div class="modal-shopify-field">
                            <label class="modal-shopify-label">Store Name*</label>
                            <div class="shopify-input-suffix-group">
                                <input type="text" name="store_name" id="shopifyStoreName" placeholder="my-awesome-store" required>
                                <span class="shopify-suffix-text">.myshopify.com</span>
                            </div>
                            <div class="shopify-helper-subtext">
                                Copy the highlighted value from your Shopify admin URL and paste it here:
                                <span class="shopify-helper-url">https://admin.shopify.com/store/{store_name}</span>
                            </div>
                        </div>

                        <!-- Client ID Field -->
                        <div class="modal-shopify-field">
                            <label class="modal-shopify-label">Client ID*</label>
                            <input type="text" name="client_id" id="shopifyClientId" class="shopify-field-input" placeholder="e.g. shpca_8a92d8f921..." required>
                        </div>

                        <!-- Client Secret Field -->
                        <div class="modal-shopify-field">
                            <label class="modal-shopify-label">Client Secret*</label>
                            <input type="password" name="client_secret" id="shopifyClientSecret" class="shopify-field-input" placeholder="••••••••••••••••••••••••" required>
                        </div>
                    </div>

                    <div class="shopify-form-footer">
                        <button type="submit" class="btn-modal-connect">Connect</button>
                        <button type="button" class="btn-modal-close-gray" onclick="closeShopifyModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- WooCommerce Modal -->
    <div class="modal-shopify-overlay" id="wooModal">
        <div class="modal-shopify-box" style="max-width: 520px; grid-template-columns: 1fr;">
            <div class="shopify-form-col">
                <button type="button" class="shopify-close-btn" onclick="closeWooModal()">&times;</button>
                <form method="POST" action="<?= asset('integrations-cart.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="connect_woocommerce">

                    <div>
                        <div class="shopify-modal-brand-header">
                            <span style="font-weight: 900; font-size: 18px; color: #9333ea; font-style: italic;">Woo</span>
                            <span style="font-weight: 800; font-size: 16px; color: #0f172a;">WooCommerce</span>
                            <span style="font-size: 11.5px; font-style: italic; color: #64748b;">Integration Built by Ominiflow</span>
                        </div>

                        <div class="shopify-form-section-title">Enter WooCommerce API Credentials</div>

                        <div class="modal-shopify-field">
                            <label class="modal-shopify-label">WordPress Store URL*</label>
                            <input type="url" name="store_url" class="shopify-field-input" placeholder="https://yourstore.com" required>
                        </div>

                        <div class="modal-shopify-field">
                            <label class="modal-shopify-label">Consumer Key*</label>
                            <input type="text" name="consumer_key" class="shopify-field-input" placeholder="ck_••••••••••••••••" required>
                        </div>

                        <div class="modal-shopify-field">
                            <label class="modal-shopify-label">Consumer Secret*</label>
                            <input type="password" name="consumer_secret" class="shopify-field-input" placeholder="cs_••••••••••••••••" required>
                        </div>
                    </div>

                    <div class="shopify-form-footer">
                        <button type="submit" class="btn-modal-connect">Connect Store</button>
                        <button type="button" class="btn-modal-close-gray" onclick="closeWooModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openShopifyModal() {
            var m = document.getElementById('shopifyModal');
            if (m) {
                m.classList.add('show');
                setTimeout(function() {
                    document.getElementById('shopifyStoreName').focus();
                }, 50);
            }
        }

        function closeShopifyModal() {
            var m = document.getElementById('shopifyModal');
            if (m) m.classList.remove('show');
        }

        function openWooModal() {
            var m = document.getElementById('wooModal');
            if (m) m.classList.add('show');
        }

        function closeWooModal() {
            var m = document.getElementById('wooModal');
            if (m) m.classList.remove('show');
        }

        function copyScopes() {
            var text = document.getElementById('scopesText').textContent;
            navigator.clipboard.writeText(text).then(function() {
                var btn = document.querySelector('.btn-copy-scopes');
                btn.textContent = '✓ Copied!';
                setTimeout(function() { btn.textContent = 'Copy Scopes'; }, 2000);
            });
        }

        function filterCartApps(query) {
            var q = (query || '').toLowerCase().trim();
            document.querySelectorAll('#cartCardsList .cart-card').forEach(function(card) {
                var name = card.getAttribute('data-name') || '';
                if (name.indexOf(q) !== -1) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeShopifyModal();
                closeWooModal();
            }
        });
    </script>
</body>
</html>

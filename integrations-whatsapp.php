<?php
/**
 * WhatsApp Business Integration Hub (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

require_auth();

$pageTitle = 'WhatsApp';

$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$db = get_db();

// Handle WhatsApp Integration Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_whatsapp_config') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/integrations-whatsapp.php');
    } else {
        $waPhone = trim($_POST['wa_phone'] ?? '');
        $waToken = trim($_POST['wa_token'] ?? '');
        $autoSend = isset($_POST['auto_send_invoices']) ? '1' : '0';

        set_flash('success', 'WhatsApp Business configuration connected successfully! Automated receipts are now active.');
        redirect(APP_URL . '/integrations-whatsapp.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> Integration — <?= APP_NAME ?></title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .wa-page-container {
            padding: 24px 36px 80px;
            background: #f8fafc;
            min-height: calc(100vh - 60px);
        }

        /* Top Header */
        .wa-top-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .wa-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .wa-learn-link {
            font-size: 13px;
            color: #d97706;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .wa-learn-link:hover {
            text-decoration: underline;
        }

        /* Top Banner Box */
        .wa-banner-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 22px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
        }

        .wa-banner-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .wa-icon-circle {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: #25d366;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.28);
        }

        .wa-banner-info h2 {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px;
        }

        .wa-banner-info p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
            max-width: 780px;
            line-height: 1.5;
        }

        .wa-btn-connect {
            background: #2563eb;
            color: #ffffff;
            font-size: 13.5px;
            font-weight: 600;
            padding: 8px 24px;
            border-radius: 6px;
            border: 0;
            cursor: pointer;
            transition: background 0.15s ease;
            text-decoration: none;
            white-space: nowrap;
        }

        .wa-btn-connect:hover {
            background: #1d4ed8;
        }

        /* Tabs Nav */
        .wa-nav-tabs {
            display: flex;
            gap: 28px;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 24px;
        }

        .wa-tab-link {
            padding: 0 0 12px 0;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            cursor: pointer;
            position: relative;
            background: transparent;
            border: 0;
        }

        .wa-tab-link.active {
            color: #2563eb;
        }

        .wa-tab-link.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 2px;
            background: #2563eb;
        }

        /* Tab Content Section */
        .wa-tab-pane {
            display: none;
        }

        .wa-tab-pane.active {
            display: block;
        }

        .wa-section-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .wa-section-desc {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 24px;
            line-height: 1.5;
        }

        /* 3 Value Cards */
        .wa-features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .wa-feat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px 22px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02);
        }

        .wa-feat-icon {
            width: 32px;
            height: 32px;
            color: #2563eb;
            margin-bottom: 4px;
        }

        .wa-feat-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        .wa-feat-text {
            font-size: 12.5px;
            color: #64748b;
            line-height: 1.5;
            margin: 0;
        }

        /* Communicate Seamlessly Banner */
        .wa-seamless-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 36px 24px;
            text-align: center;
            margin-bottom: 28px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02);
        }

        .wa-seamless-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .wa-seamless-desc {
            font-size: 13px;
            color: #64748b;
            max-width: 680px;
            margin: 0 auto 28px;
            line-height: 1.5;
        }

        .wa-diagram-flow {
            display: inline-flex;
            align-items: center;
            gap: 16px;
        }

        .wa-node-circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #25d366;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
        }

        .wa-node-pos {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2563eb;
            font-size: 10px;
            font-weight: 800;
        }

        .wa-dots-connector {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .wa-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #93c5fd;
        }

        /* Showcase Split View (Left Tabs + Right Mobile Simulator) */
        .wa-showcase-split {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            display: grid;
            grid-template-columns: 220px 1fr 340px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02);
            min-height: 400px;
        }

        .wa-showcase-nav {
            border-right: 1px solid #e2e8f0;
            padding: 16px 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .wa-showcase-tab-btn {
            padding: 12px 20px;
            font-size: 13.5px;
            font-weight: 600;
            color: #334155;
            background: transparent;
            border: 0;
            text-align: left;
            cursor: pointer;
            transition: all 0.12s ease;
            position: relative;
        }

        .wa-showcase-tab-btn:hover {
            background: #f8fafc;
            color: #2563eb;
        }

        .wa-showcase-tab-btn.active {
            background: #eff6ff;
            color: #2563eb;
            border-right: 3px solid #2563eb;
        }

        .wa-showcase-detail {
            padding: 32px 36px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .wa-showcase-detail h3 {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 12px;
        }

        .wa-showcase-detail p {
            font-size: 13.5px;
            color: #64748b;
            line-height: 1.6;
            margin: 0;
        }

        .wa-mobile-simulator {
            background: #f8fafc;
            border-left: 1px solid #e2e8f0;
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .wa-phone-frame {
            width: 260px;
            background: #0f172a;
            border-radius: 24px;
            padding: 10px;
            box-shadow: 0 16px 35px rgba(15, 23, 42, 0.25);
            border: 3px solid #334155;
        }

        .wa-phone-screen {
            background: #efeae2;
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 320px;
        }

        .wa-chat-header {
            background: #075e54;
            color: #ffffff;
            padding: 10px 12px;
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .wa-chat-body {
            padding: 12px 10px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            gap: 8px;
        }

        .wa-chat-bubble {
            background: #ffffff;
            border-radius: 8px 8px 8px 0;
            padding: 8px 10px;
            font-size: 11px;
            color: #111827;
            line-height: 1.4;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            max-width: 90%;
        }

        .wa-bubble-time {
            font-size: 8.5px;
            color: #9ca3af;
            text-align: right;
            margin-top: 4px;
        }

        @media (max-width: 1024px) {
            .wa-showcase-split {
                grid-template-columns: 1fr;
            }
            .wa-features-grid {
                grid-template-columns: 1fr;
            }
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

            <main class="wa-page-container">
                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success" style="margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Top Header Row -->
                <div class="wa-top-header">
                    <h1 class="wa-title">WhatsApp</h1>
                    <a href="https://business.whatsapp.com" target="_blank" class="wa-learn-link">
                        <span>💡</span>
                        <span>Learn more about WhatsApp Integrations</span>
                    </a>
                </div>

                <!-- Banner Card -->
                <div class="wa-banner-card">
                    <div class="wa-banner-left">
                        <div class="wa-icon-circle">
                            <svg width="30" height="30" fill="#ffffff" viewBox="0 0 24 24">
                                <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/>
                            </svg>
                        </div>
                        <div class="wa-banner-info">
                            <h2>WhatsApp Business</h2>
                            <p>Integrate with WhatsApp Business, an instant messaging platform with more than 2 billion users, and communicate with your customers about their orders, invoices, and payments.</p>
                        </div>
                    </div>

                    <button type="button" class="wa-btn-connect" onclick="openConnectModal()">Connect</button>
                </div>

                <!-- Tabs Navigation -->
                <div class="wa-nav-tabs">
                    <button type="button" class="wa-tab-link active" onclick="switchWaTab('why')">Why WhatsApp Business?</button>
                    <button type="button" class="wa-tab-link" onclick="switchWaTab('how')">How It Works</button>
                </div>

                <!-- TAB 1: Why WhatsApp Business? -->
                <div class="wa-tab-pane active" id="tab-why">
                    <div class="wa-section-title">Message instantly and build lasting relationships</div>
                    <div class="wa-section-desc">• Engage with your customers, build relationships, and accelerate sales by keeping your customers notified using a platform that has more than 2 billion users around the world.</div>

                    <!-- 3 Value Proposition Cards -->
                    <div class="wa-features-grid">
                        <div class="wa-feat-card">
                            <svg class="wa-feat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                            </svg>
                            <div class="wa-feat-title">Send Instant Messages</div>
                            <p class="wa-feat-text">Update your customers about their transactions with you using WhatsApp.</p>
                        </div>

                        <div class="wa-feat-card">
                            <svg class="wa-feat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                            </svg>
                            <div class="wa-feat-title">Templates</div>
                            <p class="wa-feat-text">Craft your own personalised message templates that will best reflect your brand.</p>
                        </div>

                        <div class="wa-feat-card">
                            <svg class="wa-feat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                            </svg>
                            <div class="wa-feat-title">Attach Documents</div>
                            <p class="wa-feat-text">Unlike SMS, you can attach important documents and send them to your customers along with the notifications.</p>
                        </div>
                    </div>

                    <!-- Communicate Seamlessly Banner -->
                    <div class="wa-seamless-box">
                        <div class="wa-seamless-title">Communicate seamlessly with your customers</div>
                        <p class="wa-seamless-desc">Explore the possibilities of what faster communication can do for your organisation. Integrate with WhatsApp and build long-lasting relationships with your customers.</p>

                        <div class="wa-diagram-flow">
                            <div class="wa-node-circle">
                                <svg width="26" height="26" fill="#ffffff" viewBox="0 0 24 24">
                                    <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/>
                                </svg>
                            </div>
                            <div class="wa-dots-connector">
                                <div class="wa-dot"></div>
                                <div class="wa-dot"></div>
                                <div class="wa-dot"></div>
                                <div class="wa-dot"></div>
                            </div>
                            <div class="wa-node-pos">
                                <span>POS</span>
                            </div>
                        </div>
                    </div>

                    <!-- Interactive Showcase Split -->
                    <div class="wa-showcase-split">
                        <!-- Left Navigation -->
                        <div class="wa-showcase-nav">
                            <button type="button" class="wa-showcase-tab-btn active" onclick="showcaseFeature('credit-notes', this)">Credit Notes</button>
                            <button type="button" class="wa-showcase-tab-btn" onclick="showcaseFeature('payment-receipts', this)">Payment Receipts</button>
                            <button type="button" class="wa-showcase-tab-btn" onclick="showcaseFeature('customers', this)">Customers</button>
                            <button type="button" class="wa-showcase-tab-btn" onclick="showcaseFeature('invoices', this)">Invoices & Bills</button>
                            <button type="button" class="wa-showcase-tab-btn" onclick="showcaseFeature('orders', this)">Order Notifications</button>
                        </div>

                        <!-- Center Detail -->
                        <div class="wa-showcase-detail" id="sc-detail">
                            <h3 id="sc-title">Send Credit Notes</h3>
                            <p id="sc-desc">• Share the credit note details with your customers and keep them informed on the amount you owe them. Also, you can choose to attach and send a PDF copy of the sales receipt along with the message.</p>
                        </div>

                        <!-- Right Live Mobile Mockup -->
                        <div class="wa-mobile-simulator">
                            <div class="wa-phone-frame">
                                <div class="wa-phone-screen">
                                    <div class="wa-chat-header">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                                        <span id="sc-chat-contact">OminiFlow Retail</span>
                                    </div>
                                    <div class="wa-chat-body">
                                        <div class="wa-chat-bubble" id="sc-chat-bubble">
                                            <div>Hi John,</div>
                                            <div style="margin-top: 4px;">We've issued a credit note for you against invoice <strong>INV-3251</strong>. Download the attachment to view details.</div>
                                            <div class="wa-bubble-time">10:42 AM ✓✓</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: How It Works -->
                <div class="wa-tab-pane" id="tab-how">
                    <div class="wa-section-title">How WhatsApp Business Integration Works</div>
                    <div class="wa-section-desc">Get connected with Meta Cloud API in 3 simple steps:</div>

                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 20px;">
                        <div class="wa-feat-card" style="padding: 24px;">
                            <div style="font-size: 24px; font-weight: 800; color: #2563eb; margin-bottom: 8px;">01</div>
                            <div class="wa-feat-title">Connect WhatsApp Phone Number</div>
                            <p class="wa-feat-text">Enter your WhatsApp Business registered number and access token in the Connect settings modal.</p>
                        </div>

                        <div class="wa-feat-card" style="padding: 24px;">
                            <div style="font-size: 24px; font-weight: 800; color: #2563eb; margin-bottom: 8px;">02</div>
                            <div class="wa-feat-title">Set Automated Trigger Rules</div>
                            <p class="wa-feat-text">Choose whether to dispatch thermal receipts, GST tax invoices, or payment confirmations automatically upon checkout.</p>
                        </div>

                        <div class="wa-feat-card" style="padding: 24px;">
                            <div style="font-size: 24px; font-weight: 800; color: #2563eb; margin-bottom: 8px;">03</div>
                            <div class="wa-feat-title">Instant Real-time Customer Delivery</div>
                            <p class="wa-feat-text">Customers immediately receive high-quality PDF tax invoices and order updates directly on their WhatsApp.</p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Connect WhatsApp Modal -->
    <div class="modal-overlay" id="connectModal">
        <div class="modal-box" style="max-width: 580px;">
            <div class="modal-header">
                <div class="modal-title" style="display: flex; align-items: center; gap: 8px;">
                    <span style="color: #25d366;">📱</span>
                    <span>Connect WhatsApp Business API</span>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeConnectModal()">&times;</button>
            </div>
            <form method="POST" action="<?= asset('integrations-whatsapp.php') ?>" style="padding: 24px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_whatsapp_config">

                <div style="margin-bottom: 16px;">
                    <label class="form-label required" style="display: block; margin-bottom: 6px;">WhatsApp Business Phone Number</label>
                    <input type="text" name="wa_phone" value="+91 9243747854" class="form-control" required style="width: 100%;" placeholder="+91 9243747854">
                    <span class="form-hint" style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">Include country code (+91 for India).</span>
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="display: block; margin-bottom: 6px;">Meta Cloud API Token (Optional)</label>
                    <input type="password" name="wa_token" value="EAABwzL123456789OminiFlowToken" class="form-control" style="width: 100%;" placeholder="Bearer EAAG...">
                </div>

                <div style="margin-bottom: 20px; background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13.5px; font-weight: 600; color: #0f172a;">
                        <input type="checkbox" name="auto_send_invoices" checked style="width: 16px; height: 16px;">
                        <span>Automatically WhatsApp Invoices on Checkout</span>
                    </label>
                    <div style="font-size: 12px; color: #64748b; margin-left: 26px; margin-top: 2px;">Sends PDF bill and summary directly to customer phone number.</div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeConnectModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Connect & Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchWaTab(tab) {
            document.querySelectorAll('.wa-nav-tabs .wa-tab-link').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.wa-tab-pane').forEach(pane => pane.classList.remove('active'));

            if (tab === 'why') {
                document.querySelectorAll('.wa-nav-tabs .wa-tab-link')[0].classList.add('active');
                document.getElementById('tab-why').classList.add('active');
            } else {
                document.querySelectorAll('.wa-nav-tabs .wa-tab-link')[1].classList.add('active');
                document.getElementById('tab-how').classList.add('active');
            }
        }

        const featureData = {
            'credit-notes': {
                title: 'Send Credit Notes',
                desc: '• Share the credit note details with your customers and keep them informed on the amount you owe them. Also, you can choose to attach and send a PDF copy of the sales receipt along with the message.',
                bubble: 'Hi John,\n\nWe\'ve issued a credit note for you against invoice INV-3251. Download the attachment to view details.'
            },
            'payment-receipts': {
                title: 'Instant Payment Receipts',
                desc: '• Acknowledge customer payments immediately with real-time digital receipts sent via WhatsApp upon checkout or balance settlements.',
                bubble: 'Dear Customer,\n\nPayment of ₹2,759.08 received for Invoice INV-2026-0819. Thank you for shopping with OminiFlow POS!'
            },
            'customers': {
                title: 'Customer Communication & Loyalty',
                desc: '• Welcome new customers, send personalized greetings, and keep your loyal customer base engaged with order milestone notifications.',
                bubble: 'Welcome to OminiFlow POS!\n\nYour account has been registered. You have earned 50 reward points on today\'s purchase.'
            },
            'invoices': {
                title: 'Send GST Tax Invoices & Thermal Slips',
                desc: '• Go paperless with high-speed digital delivery. Send GST-compliant A4 or 80mm thermal receipts with one click directly to the customer\'s WhatsApp.',
                bubble: 'Here is your official GST Tax Invoice INV-1004. Total: ₹1,250.00. Click here to download PDF copy.'
            },
            'orders': {
                title: 'Real-time Order Updates',
                desc: '• Notify customers about order processing, packaging, delivery dispatch, and readiness for pickup.',
                bubble: 'Great news! Your order ORD-5890 has been packed and is ready for pickup at Counter 1.'
            }
        };

        function showcaseFeature(featKey, btn) {
            document.querySelectorAll('.wa-showcase-tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const item = featureData[featKey];
            if (item) {
                document.getElementById('sc-title').innerText = item.title;
                document.getElementById('sc-desc').innerText = item.desc;
                document.getElementById('sc-chat-bubble').innerHTML = item.bubble.replace(/\n/g, '<br>') + '<div class="wa-bubble-time">Just now ✓✓</div>';
            }
        }

        function openConnectModal() {
            document.getElementById('connectModal').classList.add('open');
        }
        function closeConnectModal() {
            document.getElementById('connectModal').classList.remove('open');
        }
    </script>
</body>
</html>

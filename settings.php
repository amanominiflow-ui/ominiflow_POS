<?php
/**
 * OminiFlow POS - Enterprise "All Settings" Hub (Zoho POS / Zoho Books Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

require_auth();

$pageTitle = 'All Settings';

$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$db = get_db();

// Handle Settings Update (Modal & Forms)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/settings.php');
    } else {
        $storeName = trim($_POST['store_name'] ?? 'OminiFlow Retail POS');
        $tagline = trim($_POST['tagline'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $gstin = trim($_POST['gstin'] ?? '');
        $currency = trim($_POST['currency_symbol'] ?? '₹');

        $stmt = $db->prepare('
            UPDATE store_settings
            SET store_name = :sname, tagline = :tag, address = :addr, phone = :phone, email = :email, gstin = :gstin, currency_symbol = :curr, updated_at = NOW()
            WHERE id = 1
        ');
        $stmt->execute([
            'sname' => $storeName,
            'tag' => $tagline ?: null,
            'addr' => $address ?: null,
            'phone' => $phone ?: null,
            'email' => $email ?: null,
            'gstin' => $gstin ?: null,
            'curr' => $currency ?: '₹',
        ]);
        set_flash('success', 'Business profile & store settings saved successfully!');
        redirect(APP_URL . '/settings.php');
    }
}

$stmtS = $db->query('SELECT * FROM store_settings WHERE id = 1 LIMIT 1');
$settings = $stmtS->fetch() ?: [
    'store_name' => 'OminiFlow Retail POS',
    'tagline' => 'Official Retail Store & POS Terminal',
    'address' => 'Plot No. 42, Tech Park, Sector 5, Bangalore',
    'phone' => '+91 98765 43210',
    'email' => 'pos@ominiflow.com',
    'gstin' => '29ABCDE1234F1Z5',
    'currency_symbol' => '₹',
];
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
        /* Zoho All Settings Hub Styles */
        .settings-hub-bg {
            background: #f8fafc;
            min-height: calc(100vh - 60px);
            padding: 24px 36px 80px;
        }

        .settings-hub-title {
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 28px;
            letter-spacing: -0.01em;
        }

        .settings-section-container {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px 28px 28px;
            margin-bottom: 28px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
        }

        .settings-section-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 20px;
        }

        .settings-grid-5 {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            align-items: flex-start;
        }

        .settings-grid-top {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            align-items: flex-start;
            margin-bottom: 28px;
        }

        .settings-col-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 18px 20px;
            min-height: 220px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .settings-col-flat {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        /* Pill Badge Headers */
        .set-pill-header {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            width: fit-content;
        }

        .pill-green {
            background: #ecfdf5;
            color: #047857;
        }

        .pill-red {
            background: #fef2f2;
            color: #b91c1c;
        }

        .pill-orange {
            background: #fff7ed;
            color: #c2410c;
        }

        .pill-amber {
            background: #fefce8;
            color: #a16207;
        }

        .pill-blue {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .pill-teal {
            background: #f0fdfa;
            color: #0f766e;
        }

        .pill-purple {
            background: #faf5ff;
            color: #7e22ce;
        }

        /* Links List */
        .set-links-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .set-link {
            font-size: 13px;
            font-weight: 500;
            color: #334155;
            text-decoration: none;
            cursor: pointer;
            transition: color 0.12s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: transparent;
            border: 0;
            padding: 0;
            text-align: left;
            width: 100%;
        }

        .set-link:hover {
            color: #2563eb;
        }

        .set-link.highlight-sub {
            background: #eff6ff;
            color: #2563eb;
            padding: 6px 10px;
            border-radius: 6px;
            font-weight: 600;
        }

        .set-sub-card {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px dashed #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        @media (max-width: 1200px) {
            .settings-grid-5, .settings-grid-top {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .settings-grid-5, .settings-grid-top {
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

            <main class="settings-hub-bg">
                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success" style="max-width: 1200px; margin: 0 auto 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger" style="max-width: 1200px; margin: 0 auto 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Centered Page Title -->
                <div class="settings-hub-title">All Settings</div>

                <!-- SECTION 1: Top Core Settings (5 Columns Grid) -->
                <div class="settings-grid-top">
                    <!-- 1. Business Card -->
                    <div class="settings-col-card">
                        <div class="set-pill-header pill-green">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            <span>Business</span>
                        </div>
                        <ul class="set-links-list">
                            <li><a href="<?= asset('business-profile.php') ?>" class="set-link">Profile</a></li>
                            <li><a href="<?= asset('online-store.php') ?>" class="set-link">Online Store & Domain</a></li>
                            <li><a href="<?= asset('outlets.php') ?>" class="set-link">Locations</a></li>
                            <li><a href="<?= asset('import-export.php?tab=export') ?>" class="set-link">Data Backup</a></li>
                            <li>
                                <a href="javascript:void(0)" class="set-link highlight-sub" onclick="openSubscriptionModal()">
                                    <span>Subscription</span>
                                    <span>&rsaquo;</span>
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- 2. Users & Roles Card -->
                    <div class="settings-col-card">
                        <div class="set-pill-header pill-red">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <span>Users & Roles</span>
                        </div>
                        <ul class="set-links-list">
                            <li><a href="<?= asset('users.php') ?>" class="set-link">Users</a></li>
                            <li><a href="<?= asset('roles.php') ?>" class="set-link">Roles</a></li>
                        </ul>

                        <div class="set-sub-card">
                            <div class="set-pill-header pill-blue">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"/></svg>
                                <span>Taxes & Compliance</span>
                            </div>
                            <ul class="set-links-list">
                                <li><a href="<?= asset('taxes.php?tab=tax-rates') ?>" class="set-link">Taxes</a></li>
                                <li><a href="<?= asset('taxes.php?tab=gst-settings') ?>" class="set-link">GST Settings</a></li>
                            </ul>
                        </div>
                    </div>

                    <!-- 3. Customization & Notifications Card -->
                    <div class="settings-col-card">
                        <div class="set-pill-header pill-amber">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            <span>Customization</span>
                        </div>
                        <ul class="set-links-list">
                            <li><button type="button" class="set-link" onclick="openSeqModal()">Transaction Number Series</button></li>
                            <li><a href="<?= asset('invoices.php') ?>" class="set-link">PDF Templates</a></li>
                        </ul>

                        <div class="set-sub-card">
                            <div class="set-pill-header pill-blue">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                <span>Notifications</span>
                            </div>
                            <ul class="set-links-list">
                                <li><a href="mailto:support@ominiflow.com" class="set-link">Emails</a></li>
                                <li><a href="<?= asset('integrations-whatsapp.php') ?>" class="set-link">SMS & WhatsApp</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: Module Settings Container (5 Columns Grid) -->
                <div class="settings-section-container">
                    <div class="settings-section-title">Module Settings</div>

                    <div class="settings-grid-5">
                        <!-- 1. General -->
                        <div class="settings-col-flat">
                            <div class="set-pill-header pill-teal">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                <span>General</span>
                            </div>
                            <ul class="set-links-list">
                                <li><a href="<?= asset('customers.php') ?>" class="set-link">Customers and Vendors</a></li>
                                <li><a href="<?= asset('products.php') ?>" class="set-link">Items</a></li>
                            </ul>

                            <div class="set-sub-card" style="border: none; padding-top: 6px;">
                                <div class="set-pill-header pill-orange">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                    <span>Payment Integrations</span>
                                </div>
                                <ul class="set-links-list">
                                    <li><a href="<?= asset('settings.php') ?>" class="set-link">Customer Payments</a></li>
                                </ul>
                            </div>
                        </div>

                        <!-- 2. Inventory -->
                        <div class="settings-col-flat">
                            <div class="set-pill-header pill-red">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                <span>Inventory</span>
                            </div>
                            <ul class="set-links-list">
                                <li><a href="<?= asset('categories.php') ?>" class="set-link">Units of Measurement</a></li>
                                <li><a href="<?= asset('stock-count.php') ?>" class="set-link">Adjustments</a></li>
                            </ul>
                        </div>

                        <!-- 3. Sales -->
                        <div class="settings-col-flat">
                            <div class="set-pill-header pill-teal">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                <span>Sales</span>
                            </div>
                            <ul class="set-links-list">
                                <li><a href="<?= asset('orders.php') ?>" class="set-link">Orders</a></li>
                                <li><a href="<?= asset('invoices.php') ?>" class="set-link">Invoices</a></li>
                                <li><a href="<?= asset('invoices.php') ?>" class="set-link">Payments Received</a></li>
                                <li><a href="<?= asset('fulfillment.php') ?>" class="set-link">Packages</a></li>
                                <li><a href="<?= asset('fulfillment.php') ?>" class="set-link">Shipments</a></li>
                                <li><a href="<?= asset('returns.php') ?>" class="set-link">Returns</a></li>
                                <li><a href="<?= asset('returns.php') ?>" class="set-link">Credit Notes</a></li>
                                <li><a href="<?= asset('fulfillment.php') ?>" class="set-link">Delivery Challans</a></li>
                            </ul>
                        </div>

                        <!-- 4. Purchases -->
                        <div class="settings-col-flat">
                            <div class="set-pill-header pill-green">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                                <span>Purchases</span>
                            </div>
                            <ul class="set-links-list">
                                <li><a href="<?= asset('purchases.php') ?>" class="set-link">Purchase Orders</a></li>
                                <li><a href="<?= asset('purchases.php') ?>" class="set-link">Purchase Receive</a></li>
                                <li><a href="<?= asset('purchases.php') ?>" class="set-link">Bills</a></li>
                                <li><a href="<?= asset('purchases.php') ?>" class="set-link">Payments Made</a></li>
                                <li><a href="<?= asset('purchase-returns.php') ?>" class="set-link">Vendor Credits</a></li>
                            </ul>
                        </div>

                        <!-- 5. Custom Modules -->
                        <div class="settings-col-flat">
                            <div class="set-pill-header pill-blue">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                                <span>Custom Modules</span>
                            </div>
                            <ul class="set-links-list">
                                <li><a href="<?= asset('reports.php') ?>" class="set-link">Overview</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: Extension & Developer Data Container -->
                <div class="settings-section-container">
                    <div class="settings-section-title">Extension & Developer Data</div>

                    <div class="settings-grid-5">
                        <!-- 1. Integrations -->
                        <div class="settings-col-flat">
                            <div class="set-pill-header pill-green">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                <span>Integrations</span>
                            </div>
                            <ul class="set-links-list">
                                <li><a href="<?= asset('integrations-shipping.php') ?>" class="set-link">Shipping</a></li>
                                <li><a href="<?= asset('integrations-cart.php') ?>" class="set-link">Shopping Cart</a></li>
                                <li><a href="<?= asset('online-store.php') ?>" class="set-link">Online Store & Domain</a></li>
                                <li><a href="<?= asset('reports.php') ?>" class="set-link">Accounting</a></li>
                                <li><a href="https://wa.me/918925108639" target="_blank" class="set-link">SMS Integrations</a></li>
                                <li><a href="<?= asset('integrations-whatsapp.php') ?>" class="set-link">WhatsApp</a></li>
                            </ul>
                        </div>

                        <!-- 2. Developer Space -->
                        <div class="settings-col-flat">
                            <div class="set-pill-header pill-orange">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                                <span>Developer Space</span>
                            </div>
                            <ul class="set-links-list">
                                <li><a href="javascript:void(0)" class="set-link" onclick="alert('API REST Endpoints: active on /api/orders, /api/products')">Connections</a></li>
                                <li><a href="javascript:void(0)" class="set-link" onclick="alert('API Rate: Unlimited local transactions.')">API Usage</a></li>
                                <li><a href="javascript:void(0)" class="set-link" onclick="alert('Incoming Webhooks: active on /webhooks/pos-sync')">Incoming Webhooks</a></li>
                                <li><a href="<?= asset('dashboard.php') ?>" class="set-link">Web Tabs</a></li>
                                <li><a href="<?= asset('invoice-create.php') ?>" class="set-link">Web Forms</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- 1. Profile Modal -->
    <div class="modal-overlay" id="profileModal">
        <div class="modal-box" style="max-width: 650px;">
            <div class="modal-header">
                <div class="modal-title">Business Profile & Credentials</div>
                <button type="button" class="modal-close-btn" onclick="closeProfileModal()">&times;</button>
            </div>
            <form method="POST" action="<?= asset('settings.php') ?>" style="padding: 20px 24px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">

                <div style="margin-bottom: 14px;">
                    <label class="form-label required" style="display: block; margin-bottom: 6px;">Store / Business Name</label>
                    <input type="text" name="store_name" value="<?= e($settings['store_name']) ?>" class="form-control" required style="width: 100%;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div>
                        <label class="form-label" style="display: block; margin-bottom: 6px;">GSTIN</label>
                        <input type="text" name="gstin" value="<?= e($settings['gstin']) ?>" class="form-control" style="width: 100%; text-transform: uppercase;">
                    </div>
                    <div>
                        <label class="form-label" style="display: block; margin-bottom: 6px;">Currency</label>
                        <input type="text" name="currency_symbol" value="<?= e($settings['currency_symbol']) ?>" class="form-control" style="width: 100%;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div>
                        <label class="form-label" style="display: block; margin-bottom: 6px;">Phone</label>
                        <input type="text" name="phone" value="<?= e($settings['phone']) ?>" class="form-control" style="width: 100%;">
                    </div>
                    <div>
                        <label class="form-label" style="display: block; margin-bottom: 6px;">Email</label>
                        <input type="email" name="email" value="<?= e($settings['email']) ?>" class="form-control" style="width: 100%;">
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label class="form-label" style="display: block; margin-bottom: 6px;">Store Address</label>
                    <textarea name="address" rows="3" class="form-control" style="width: 100%; resize: vertical;"><?= e($settings['address']) ?></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeProfileModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. Taxes Modal -->
    <div class="modal-overlay" id="taxModal">
        <div class="modal-box" style="max-width: 550px;">
            <div class="modal-header">
                <div class="modal-title">GST Taxes & Direct Tax Slabs</div>
                <button type="button" class="modal-close-btn" onclick="closeTaxModal()">&times;</button>
            </div>
            <div style="padding: 20px 24px;">
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; padding: 10px 14px; background: #f8fafc; border-radius: 8px; font-size: 13.5px; font-weight: 600;">
                        <span>GST 0% (Exempted)</span>
                        <span style="color: #047857;">CGST 0% + SGST 0%</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 10px 14px; background: #f8fafc; border-radius: 8px; font-size: 13.5px; font-weight: 600;">
                        <span>GST 5% (Essential Goods)</span>
                        <span style="color: #047857;">CGST 2.5% + SGST 2.5%</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 10px 14px; background: #f8fafc; border-radius: 8px; font-size: 13.5px; font-weight: 600;">
                        <span>GST 12% (Standard S1)</span>
                        <span style="color: #047857;">CGST 6% + SGST 6%</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 10px 14px; background: #f8fafc; border-radius: 8px; font-size: 13.5px; font-weight: 600;">
                        <span>GST 18% (Retail Default)</span>
                        <span style="color: #2563eb;">CGST 9% + SGST 9%</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 10px 14px; background: #f8fafc; border-radius: 8px; font-size: 13.5px; font-weight: 600;">
                        <span>GST 28% (Luxury & Auto)</span>
                        <span style="color: #047857;">CGST 14% + SGST 14%</span>
                    </div>
                </div>
                <div style="margin-top: 20px; display: flex; justify-content: flex-end;">
                    <button type="button" class="btn-primary" onclick="closeTaxModal()">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Subscription Modal -->
    <div class="modal-overlay" id="subscriptionModal">
        <div class="modal-box" style="max-width: 500px;">
            <div class="modal-header">
                <div class="modal-title">OMINIFLOW PRO Plan</div>
                <button type="button" class="modal-close-btn" onclick="closeSubscriptionModal()">&times;</button>
            </div>
            <div style="padding: 24px; text-align: center;">
                <div style="display: inline-block; background: #10b981; color: #ffffff; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; margin-bottom: 12px;">ACTIVE PLAN</div>
                <h3 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 8px;">OMINIFLOW PRO Enterprise</h3>
                <p style="font-size: 13px; color: #64748b; margin-bottom: 20px;">Unlimited Transactions • Multi-Outlet POS • GST Auto-Ledger • Laser Barcode</p>
                <button type="button" class="btn-primary" onclick="closeSubscriptionModal()">Done</button>
            </div>
        </div>
    </div>

    <!-- 4. Series Modal -->
    <div class="modal-overlay" id="seqModal">
        <div class="modal-box" style="max-width: 500px;">
            <div class="modal-header">
                <div class="modal-title">Transaction Number Series</div>
                <button type="button" class="modal-close-btn" onclick="closeSeqModal()">&times;</button>
            </div>
            <div style="padding: 20px 24px;">
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; padding: 10px; background: #f8fafc; border-radius: 6px;">
                        <span>Invoices</span>
                        <strong>INV-YYYYMMDD-####</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 10px; background: #f8fafc; border-radius: 6px;">
                        <span>Orders</span>
                        <strong>ORD-YYYYMMDD-#####</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 10px; background: #f8fafc; border-radius: 6px;">
                        <span>Purchase Orders</span>
                        <strong>PO-YYYYMMDD-####</strong>
                    </div>
                </div>
                <div style="margin-top: 20px; display: flex; justify-content: flex-end;">
                    <button type="button" class="btn-primary" onclick="closeSeqModal()">Done</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openProfileModal() {
            document.getElementById('profileModal').classList.add('open');
        }
        function closeProfileModal() {
            document.getElementById('profileModal').classList.remove('open');
        }

        function openTaxModal() {
            document.getElementById('taxModal').classList.add('open');
        }
        function closeTaxModal() {
            document.getElementById('taxModal').classList.remove('open');
        }

        function openSubscriptionModal() {
            document.getElementById('subscriptionModal').classList.add('open');
        }
        function closeSubscriptionModal() {
            document.getElementById('subscriptionModal').classList.remove('open');
        }

        function openSeqModal() {
            document.getElementById('seqModal').classList.add('open');
        }
        function closeSeqModal() {
            document.getElementById('seqModal').classList.remove('open');
        }
    </script>
</body>
</html>

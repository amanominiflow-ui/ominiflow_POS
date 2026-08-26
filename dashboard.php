<?php
/**
 * OminiFlow POS - Dashboard & Getting Started Onboarding Hub
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/premium_db.php';
require_once __DIR__ . '/includes/products_db.php';
require_once __DIR__ . '/includes/orders_db.php';

require_auth();

$user = current_user();
$userName = $user ? $user['name'] : 'Admin';
$isPremium = is_premium_active();
$pendingPremium = $isPremium ? null : get_pending_premium_order(current_business_id());
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

$inventoryStats = get_inventory_stats();
$salesStats = get_sales_stats();
$recentMovements = get_inventory_movements(null, 5);

$activeTab = ($_GET['tab'] ?? '') === 'getting-started' ? 'getting-started' : 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home — <?= APP_NAME ?></title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        /* Top Zoho Navigation Tabs */
        .zoho-home-tabs {
            display: flex;
            align-items: center;
            gap: 28px;
            padding: 16px 28px 0;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
        }

        .zoho-tab-item {
            padding: 8px 4px 14px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: 0;
            border-bottom: 2.5px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .zoho-tab-item:hover {
            color: #0f172a;
        }

        .zoho-tab-item.active {
            color: #0f172a;
            font-weight: 700;
            border-bottom-color: #2563eb;
        }

        .main-tab-content {
            display: none;
        }

        .main-tab-content.active {
            display: block;
        }

        /* Getting Started Page Styles */
        .gs-container {
            background: #f8fafc;
            min-height: calc(100vh - 120px);
            padding: 32px 36px 60px;
        }

        .gs-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .gs-welcome-title {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            margin: 0 0 6px;
        }

        .gs-welcome-sub {
            font-size: 13.5px;
            color: #64748b;
            margin: 0;
        }

        .gs-progress-box {
            text-align: right;
        }

        .gs-progress-text {
            font-size: 12.5px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }

        .gs-progress-bars {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .gs-prog-seg {
            width: 18px;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
        }

        .gs-prog-seg.filled {
            background: #2563eb;
        }

        /* Getting Started Checklist Layout */
        .gs-main-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.04);
            display: grid;
            grid-template-columns: 280px 1fr;
            min-height: 420px;
            overflow: hidden;
            margin-bottom: 40px;
        }

        .gs-step-list {
            border-right: 1px solid #f1f5f9;
            background: #ffffff;
            padding: 16px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .gs-step-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            color: #334155;
            background: transparent;
            border: 0;
            width: 100%;
            text-align: left;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .gs-step-btn:hover {
            background: #f8fafc;
            color: #0f172a;
        }

        .gs-step-btn.active {
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 700;
        }

        .gs-step-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 1.5px solid #cbd5e1;
            font-size: 11.5px;
            font-weight: 700;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .gs-step-btn.active .gs-step-num {
            background: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
        }

        .gs-step-btn.completed .gs-step-num {
            background: #10b981;
            border-color: #10b981;
            color: #ffffff;
        }

        /* Step Detail Area */
        .gs-step-content-wrap {
            padding: 32px 36px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .gs-step-view {
            display: none;
        }

        .gs-step-view.active {
            display: block;
            animation: fadeIn 0.18s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .gs-content-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 20px;
        }

        .gs-action-box {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 24px 28px;
            background: #ffffff;
            margin-bottom: 20px;
            max-width: 620px;
        }

        .gs-action-desc {
            font-size: 13.5px;
            color: #475569;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .gs-primary-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #2563eb;
            color: #ffffff;
            padding: 9px 18px;
            border-radius: 6px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            border: 0;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .gs-primary-btn:hover {
            background: #1d4ed8;
        }

        .gs-tip-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 13px;
            color: #166534;
            max-width: 620px;
        }

        .gs-tip-box a {
            color: #15803d;
            font-weight: 700;
            text-decoration: underline;
        }

        .gs-illustration {
            position: absolute;
            right: 28px;
            bottom: 20px;
            width: 140px;
            opacity: 0.85;
            pointer-events: none;
        }

        /* 3 Column Bottom Links Section */
        .gs-footer-grid {
            display: grid;
            grid-template-columns: 1fr 1.2fr 1.3fr;
            gap: 36px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }

        .gs-footer-col h4 {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 16px;
        }

        .gs-link-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .gs-link-list a {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #475569;
            text-decoration: none;
            transition: color 0.15s;
        }

        .gs-link-list a:hover {
            color: #2563eb;
        }

        .gs-support-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            color: #475569;
            margin-bottom: 10px;
        }

        .gs-support-row a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .gs-subtext {
            font-size: 11.5px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .gs-expert-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px 24px;
        }

        .gs-expert-desc {
            font-size: 13px;
            color: #475569;
            line-height: 1.6;
            margin-bottom: 14px;
        }

        .gs-expert-link {
            font-size: 13px;
            font-weight: 700;
            color: #2563eb;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .gs-expert-link:hover {
            text-decoration: underline;
        }

        .gs-bottom-bar {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
            font-size: 12.5px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .gs-bottom-bar a {
            color: #2563eb;
            font-weight: 600;
            text-decoration: none;
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

            <!-- Zoho Top Navigation Bar (Dashboard | Getting Started) -->
            <div class="zoho-home-tabs">
                <button type="button" class="zoho-tab-item <?= $activeTab === 'dashboard' ? 'active' : '' ?>" id="tabBtnDashboard" onclick="switchHomeTab('dashboard')">
                    Dashboard
                </button>
                <button type="button" class="zoho-tab-item <?= $activeTab === 'getting-started' ? 'active' : '' ?>" id="tabBtnGettingStarted" onclick="switchHomeTab('getting-started')">
                    Getting Started
                </button>
            </div>

            <!-- Tab View 1: Main POS Dashboard Content -->
            <div id="viewDashboard" class="main-tab-content <?= $activeTab === 'dashboard' ? 'active' : '' ?>">
                <main class="dashboard-content">
                    <?php if ($flashSuccess): ?><div class="saas-alert saas-alert-success" style="margin-bottom:16px"><span><?= e($flashSuccess) ?></span></div><?php endif; ?>
                    <?php if ($flashError): ?><div class="saas-alert saas-alert-danger" style="margin-bottom:16px"><span><?= e($flashError) ?></span></div><?php endif; ?>

                    <?php if (!$isPremium): ?>
                    <section style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border-radius:14px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                        <div>
                            <div style="font-size:11px;font-weight:800;letter-spacing:.06em;opacity:.85;margin-bottom:4px;">PREMIUM PLAN</div>
                            <div style="font-size:18px;font-weight:800;margin-bottom:4px;">Unlock all POS modules</div>
                            <div style="font-size:13.5px;opacity:.92;"><?= $pendingPremium ? 'Payment submitted. Premium stays locked until payment is confirmed.' : '₹35,000 + 18% GST extra. Until you buy, Home stays available and other pages stay locked.' ?></div>
                        </div>
                        <a href="<?= asset('pricing.php') ?>" class="btn-hero-primary" style="background:#fff;color:#1d4ed8;flex-shrink:0;">View features &amp; buy</a>
                    </section>
                    <?php endif; ?>

                    <!-- Welcome Banner -->
                    <section class="welcome-hero">
                        <div class="welcome-text">
                            <h1>Welcome back, <?= e($userName) ?>!</h1>
                            <p>Your OminiFlow POS billing terminal is ready. Today: <strong>₹<?= number_format($salesStats['today_revenue'], 2) ?></strong> sales across <strong><?= $salesStats['today_orders'] ?></strong> orders.</p>
                        </div>
                        <div class="welcome-actions">
                            <a href="<?= asset('pos.php') ?>" class="btn-hero-primary">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                                <span>Open POS Register</span>
                            </a>
                        </div>
                    </section>

                    <!-- Metric / KPI Cards Grid (Sales, Orders, Products, Customers) -->
                    <section class="kpi-grid" aria-label="POS KPI Overview">
                        <!-- 1. Total Sales Card -->
                        <div class="kpi-card">
                            <div class="kpi-top">
                                <span class="kpi-label">Total Sales</span>
                                <div class="kpi-icon-wrap icon-sales" aria-hidden="true">
                                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="kpi-value">₹<?= number_format($salesStats['total_revenue'], 2) ?></div>
                            <div class="kpi-footer">
                                <span class="trend-badge trend-up">
                                    ₹<?= number_format($salesStats['today_revenue'], 2) ?> today
                                </span>
                            </div>
                        </div>

                        <!-- 2. Orders Card -->
                        <div class="kpi-card">
                            <div class="kpi-top">
                                <span class="kpi-label">Orders Today</span>
                                <div class="kpi-icon-wrap icon-orders" aria-hidden="true">
                                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="kpi-value"><?= $salesStats['today_orders'] ?></div>
                            <div class="kpi-footer">
                                <span class="trend-badge trend-neutral"><?= $salesStats['total_orders'] ?> Lifetime Orders</span>
                            </div>
                        </div>

                        <!-- 3. Products Card -->
                        <div class="kpi-card">
                            <div class="kpi-top">
                                <span class="kpi-label">Products</span>
                                <div class="kpi-icon-wrap icon-products" aria-hidden="true">
                                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="kpi-value"><?= $inventoryStats['total_products'] ?></div>
                            <div class="kpi-footer">
                                <?php if ($inventoryStats['low_stock_count'] > 0): ?>
                                    <span class="trend-badge trend-warn"><?= $inventoryStats['low_stock_count'] ?> low stock</span>
                                <?php else: ?>
                                    <span class="trend-badge trend-up">Stock healthy</span>
                                <?php endif; ?>
                                <span>• <?= number_format($inventoryStats['total_stock_units']) ?> units</span>
                            </div>
                        </div>

                        <!-- 4. Customers Card -->
                        <div class="kpi-card">
                            <div class="kpi-top">
                                <span class="kpi-label">Customers</span>
                                <div class="kpi-icon-wrap icon-customers" aria-hidden="true">
                                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="kpi-value"><?= $salesStats['total_customers'] ?></div>
                            <div class="kpi-footer">
                                <span class="trend-badge trend-neutral">In-Store CRM</span>
                            </div>
                        </div>
                    </section>

                    <!-- Quick Actions Navigation Bar -->
                    <section class="quick-actions-bar">
                        <div class="quick-actions-title">POS Quick Access</div>
                        <div class="quick-actions-list">
                            <a href="<?= asset('pos.php') ?>" class="quick-action-pill" style="background: var(--saas-primary-soft); color: var(--saas-primary); font-weight: 700;">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                                <span>Open POS Register</span>
                            </a>

                            <a href="<?= asset('orders.php') ?>" class="quick-action-pill">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                                </svg>
                                <span>Orders & Sales</span>
                            </a>

                            <a href="<?= asset('products.php') ?>" class="quick-action-pill">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                                <span>Products Catalog</span>
                            </a>

                            <a href="<?= asset('inventory.php') ?>" class="quick-action-pill">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                </svg>
                                <span>Inventory Management</span>
                            </a>
                        </div>
                    </section>

                    <!-- Recent Inventory Movements Audit Preview -->
                    <section class="section-card">
                        <div class="section-header">
                            <div>
                                <div class="section-heading">Recent Inventory Movements & Sales</div>
                                <div class="section-subheading">Latest stock deductions from POS checkout and warehouse movements</div>
                            </div>
                            <a href="<?= asset('inventory.php?tab=history') ?>" class="quick-action-pill" style="font-size: 12px; padding: 6px 12px;">
                                View Full Audit Log
                            </a>
                        </div>

                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>Product</th>
                                        <th>Movement Type</th>
                                        <th>Quantity Change</th>
                                        <th>Stock Level</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentMovements)): ?>
                                        <tr>
                                            <td colspan="6">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon">📋</div>
                                                    <div style="font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">No stock movements recorded yet</div>
                                                    <div>Sales made in POS Register will automatically log deductions here in real-time.</div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentMovements as $m): ?>
                                            <?php
                                                $change = (int) $m['quantity_change'];
                                                $type = $m['movement_type'];
                                                $typeLabel = ($type === 'out') ? 'Stock Out' : (($type === 'adjustment') ? 'Count Adjust' : 'Stock In');
                                                $typeBadge = ($type === 'out') ? 'movement-out' : (($type === 'adjustment') ? 'movement-adjust' : 'movement-in');
                                            ?>
                                            <tr>
                                                <td style="color: var(--saas-slate-500); font-size: 12.5px; white-space: nowrap;">
                                                    <?= date('M d, Y • h:i A', strtotime($m['created_at'])) ?>
                                                </td>
                                                <td>
                                                    <strong><?= e($m['product_name']) ?></strong>
                                                    <div style="font-size: 11px; font-family: monospace; color: var(--saas-slate-400);">SKU: <?= e($m['product_sku']) ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $typeBadge ?>"><?= e($typeLabel) ?></span>
                                                </td>
                                                <td>
                                                    <strong style="color: <?= $change >= 0 ? '#047857' : '#b91c1c' ?>;">
                                                        <?= $change > 0 ? '+' . $change : $change ?> units
                                                    </strong>
                                                </td>
                                                <td>
                                                    <?= (int)$m['quantity_before'] ?> → <strong><?= (int)$m['quantity_after'] ?></strong>
                                                </td>
                                                <td>
                                                    <span style="font-size: 13px; color: var(--saas-slate-600);"><?= e($m['reason']) ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </main>
            </div>

            <!-- Tab View 2: Zoho POS Getting Started Checklist Onboarding View -->
            <div id="viewGettingStarted" class="main-tab-content <?= $activeTab === 'getting-started' ? 'active' : '' ?>">
                <div class="gs-container">
                    <!-- Getting Started Header -->
                    <div class="gs-header">
                        <div>
                            <h2 class="gs-welcome-title">Welcome, <?= e($userName) ?>!</h2>
                            <p class="gs-welcome-sub">Follow our quick checklist to get started with OminiFlow POS</p>
                        </div>
                        <div class="gs-progress-box">
                            <div class="gs-progress-text">1/7 Steps Completed</div>
                            <div class="gs-progress-bars">
                                <div class="gs-prog-seg filled"></div>
                                <div class="gs-prog-seg"></div>
                                <div class="gs-prog-seg"></div>
                                <div class="gs-prog-seg"></div>
                                <div class="gs-prog-seg"></div>
                                <div class="gs-prog-seg"></div>
                                <div class="gs-prog-seg"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Interactive Checklist Card -->
                    <div class="gs-main-card">
                        <!-- Left Steps List -->
                        <div class="gs-step-list">
                            <button type="button" class="gs-step-btn active" onclick="showStep(1)">
                                <span class="gs-step-num" style="background: #2563eb; color: #fff; border-color: #2563eb;">✓</span>
                                <span>Create a Store</span>
                            </button>
                            <button type="button" class="gs-step-btn" onclick="showStep(2)">
                                <span class="gs-step-num">2</span>
                                <span>Configure tax settings</span>
                            </button>
                            <button type="button" class="gs-step-btn" onclick="showStep(3)">
                                <span class="gs-step-num">3</span>
                                <span>Build your inventory</span>
                            </button>
                            <button type="button" class="gs-step-btn" onclick="showStep(4)">
                                <span class="gs-step-num">4</span>
                                <span>Stock up your inventory</span>
                            </button>
                            <button type="button" class="gs-step-btn" onclick="showStep(5)">
                                <span class="gs-step-num">5</span>
                                <span>Manage store preferences</span>
                            </button>
                            <button type="button" class="gs-step-btn" onclick="showStep(6)">
                                <span class="gs-step-num">6</span>
                                <span>Setup POS register</span>
                            </button>
                            <button type="button" class="gs-step-btn" onclick="showStep(7)">
                                <span class="gs-step-num">7</span>
                                <span>Set up mobile store</span>
                            </button>
                        </div>

                        <!-- Right Detail View -->
                        <div class="gs-step-content-wrap">
                            <!-- Step 1 Content -->
                            <div class="gs-step-view active" id="stepView_1">
                                <div class="gs-content-title">Create a Store</div>
                                <div class="gs-action-box">
                                    <div class="gs-action-desc">
                                        Mention your business details like Name, Business Type, Logo, Time Zone, Currency, etc., to incorporate into your transactions.
                                    </div>
                                    <a href="<?= asset('business-profile.php') ?>" class="gs-primary-btn">
                                        Manage Business Profile
                                    </a>
                                </div>
                                <div class="gs-tip-box">
                                    <span>☼</span>
                                    <span>To add your organization's employees as new users, send them invites. <a href="<?= asset('registers.php') ?>">Invite Users</a></span>
                                </div>
                            </div>

                            <!-- Step 2 Content -->
                            <div class="gs-step-view" id="stepView_2">
                                <div class="gs-content-title">Configure tax settings</div>
                                <div class="gs-action-box">
                                    <div class="gs-action-desc">
                                        Set up default GST taxes (CGST, SGST, IGST), TDS, TCS rates, and tax rules for your store products.
                                    </div>
                                    <a href="<?= asset('taxes.php?tab=gst-settings') ?>" class="gs-primary-btn">
                                        Configure Tax Settings
                                    </a>
                                </div>
                                <div class="gs-tip-box">
                                    <span>☼</span>
                                    <span>Multi-tax slabs supported (5%, 12%, 18%, 28%) with HSN auto-mapping.</span>
                                </div>
                            </div>

                            <!-- Step 3 Content -->
                            <div class="gs-step-view" id="stepView_3">
                                <div class="gs-content-title">Build your inventory</div>
                                <div class="gs-action-box">
                                    <div class="gs-action-desc">
                                        Add items, categories, barcodes, variants, and product images to your retail inventory catalog.
                                    </div>
                                    <a href="<?= asset('products.php') ?>" class="gs-primary-btn">
                                        Add Products
                                    </a>
                                </div>
                                <div class="gs-tip-box">
                                    <span>☼</span>
                                    <span>You can also import items in bulk from CSV/Excel. <a href="<?= asset('import-export.php') ?>">Import Catalog</a></span>
                                </div>
                            </div>

                            <!-- Step 4 Content -->
                            <div class="gs-step-view" id="stepView_4">
                                <div class="gs-content-title">Stock up your inventory</div>
                                <div class="gs-action-box">
                                    <div class="gs-action-desc">
                                        Record initial stock balances, purchase orders from vendors, and track warehouse movements.
                                    </div>
                                    <a href="<?= asset('inventory.php') ?>" class="gs-primary-btn">
                                        Manage Stock Inflow
                                    </a>
                                </div>
                                <div class="gs-tip-box">
                                    <span>☼</span>
                                    <span>Set low-stock reorder thresholds to prevent stockouts.</span>
                                </div>
                            </div>

                            <!-- Step 5 Content -->
                            <div class="gs-step-view" id="stepView_5">
                                <div class="gs-content-title">Manage store preferences</div>
                                <div class="gs-action-box">
                                    <div class="gs-action-desc">
                                        Customize thermal receipt sizes (80mm/58mm), invoice formats, barcode layouts, and payment terms.
                                    </div>
                                    <a href="<?= asset('settings.php') ?>" class="gs-primary-btn">
                                        Store Preferences
                                    </a>
                                </div>
                                <div class="gs-tip-box">
                                    <span>☼</span>
                                    <span>Receipt headers, footers, and tax summaries can be customized anytime.</span>
                                </div>
                            </div>

                            <!-- Step 6 Content -->
                            <div class="gs-step-view" id="stepView_6">
                                <div class="gs-content-title">Setup POS register</div>
                                <div class="gs-action-box">
                                    <div class="gs-action-desc">
                                        Configure cash registers, barcode laser scanners, cash drawer, and opening shift balances.
                                    </div>
                                    <a href="<?= asset('pos.php') ?>" class="gs-primary-btn">
                                        Open POS Register
                                    </a>
                                </div>
                                <div class="gs-tip-box">
                                    <span>☼</span>
                                    <span>Shift drawer audits and cash reconciliation are tracked in <a href="<?= asset('registers.php') ?>">Registers</a>.</span>
                                </div>
                            </div>

                            <!-- Step 7 Content -->
                            <div class="gs-step-view" id="stepView_7">
                                <div class="gs-content-title">Set up mobile store</div>
                                <div class="gs-action-box">
                                    <div class="gs-action-desc">
                                        Connect tablets, handheld barcode terminals, and touch-screen POS for fast mobile floor checkout.
                                    </div>
                                    <a href="<?= asset('pos.php') ?>" class="gs-primary-btn">
                                        Launch Touch POS
                                    </a>
                                </div>
                                <div class="gs-tip-box">
                                    <span>☼</span>
                                    <span>Fully responsive on Android, iPad, and tablet touch screens.</span>
                                </div>
                            </div>

                            <!-- Decorative Retail Shop Illustration -->
                            <svg class="gs-illustration" viewBox="0 0 160 120" fill="none">
                                <rect x="30" y="45" width="100" height="65" rx="6" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="2"/>
                                <path d="M25 45L40 25H120L135 45H25Z" fill="#fef3c7" stroke="#f59e0b" stroke-width="2"/>
                                <path d="M40 25L48 45M64 25L68 45M88 25L88 45M112 25L108 45" stroke="#f59e0b" stroke-width="1.5"/>
                                <rect x="45" y="65" width="30" height="45" fill="#3b82f6" rx="2"/>
                                <rect x="88" y="65" width="30" height="30" fill="#e0f2fe" stroke="#38bdf8" stroke-width="1.5" rx="2"/>
                                <circle cx="130" cy="30" r="14" fill="#60a5fa" fill-opacity="0.2"/>
                                <path d="M125 30L129 34L136 26" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Bottom 3-Column Support & More Section -->
                    <div class="gs-footer-grid">
                        <!-- Column 1: More with OminiFlow POS -->
                        <div class="gs-footer-col">
                            <h4>More with OminiFlow POS</h4>
                            <ul class="gs-link-list">
                                <li>
                                    <a href="<?= asset('customers.php') ?>">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        <span>Customers CRM</span>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?= asset('registers.php') ?>">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <span>Manage Sessions & Shifts</span>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?= asset('promotions.php') ?>">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                        <span>Price List & Discounts</span>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?= asset('settings.php') ?>">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                                        <span>Invite Cashiers & Team</span>
                                    </a>
                                </li>
                            </ul>
                        </div>

                        <!-- Column 2: Need Help -->
                        <div class="gs-footer-col">
                            <h4>Need Help?</h4>
                            <div class="gs-support-row">
                                <span>✉</span>
                                <div>Mail us at <a href="mailto:info@ominiflow.com">info@ominiflow.com</a></div>
                            </div>
                            <div class="gs-support-row">
                                <span>📱</span>
                                <div>Whatsapp or call us <a href="https://wa.me/919243747854" target="_blank" style="font-weight: 700; color: #047857;">+91 9243747854</a></div>
                            </div>
                            <div class="gs-support-row">
                                <span>🕒</span>
                                <div>
                                    <div style="font-weight: 600; color: #1e293b;">Monday - Friday</div>
                                    <div class="gs-subtext" style="color: #475569;">10:00 AM - 7:00 PM IST</div>
                                    <div style="font-weight: 600; color: #1e293b; margin-top: 4px;">Saturday</div>
                                    <div class="gs-subtext" style="color: #475569;">11:00 AM - 5:00 PM IST</div>
                                    <div style="font-weight: 700; color: #ef4444; margin-top: 4px;">Sunday</div>
                                    <div class="gs-subtext" style="color: #ef4444; font-weight: 600;">Closed</div>
                                </div>
                            </div>
                        </div>

                        <!-- Column 3: Need Experts -->
                        <div class="gs-footer-col">
                            <h4>Need experts to jumpstart your business?</h4>
                            <div class="gs-expert-card">
                                <div class="gs-expert-desc">
                                    Our onboarding experts will assist you in setting up OminiFlow POS, including data import, Billing apps, user training, customization, and much more.
                                </div>
                                <a href="mailto:sales@ominiflow.com" class="gs-expert-link">
                                    <span>Get in touch</span>
                                    <span>→</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="gs-bottom-bar">
                        <span>Explore all our core features in one place.</span>
                        <a href="<?= asset('reports.php') ?>">Learn More</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        function switchHomeTab(tabName) {
            document.querySelectorAll('.zoho-tab-item').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.main-tab-content').forEach(c => c.classList.remove('active'));

            if (tabName === 'getting-started') {
                document.getElementById('tabBtnGettingStarted').classList.add('active');
                document.getElementById('viewGettingStarted').classList.add('active');
                history.pushState(null, '', '?tab=getting-started');
            } else {
                document.getElementById('tabBtnDashboard').classList.add('active');
                document.getElementById('viewDashboard').classList.add('active');
                history.pushState(null, '', '?tab=dashboard');
            }
        }

        function showStep(stepNum) {
            var buttons = document.querySelectorAll('.gs-step-btn');
            var views = document.querySelectorAll('.gs-step-view');

            buttons.forEach(function(btn, idx) {
                if (idx === (stepNum - 1)) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            views.forEach(function(v) {
                v.classList.remove('active');
            });

            var targetView = document.getElementById('stepView_' + stepNum);
            if (targetView) {
                targetView.classList.add('active');
            }
        }
    </script>
</body>
</html>

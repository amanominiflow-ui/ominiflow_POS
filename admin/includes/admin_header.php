<?php
/**
 * Super Admin Panel Header Component for OminiFlow POS
 * Exact Dual-Rail Sidebar & Clean Light SaaS Layout matching OminiFlow POS
 * Standard 72px Width with High-Contrast Highlights & Dedicated Admin Settings
 */

declare(strict_types=1);

$adminUser = $adminUser ?? (function_exists('current_user') ? current_user() : null);
$currentAdminPage = basename($_SERVER['PHP_SELF']);
$adminName = htmlspecialchars($adminUser['name'] ?? 'Super Administrator', ENT_QUOTES, 'UTF-8');
$adminEmail = htmlspecialchars($adminUser['email'] ?? 'admin@example.com', ENT_QUOTES, 'UTF-8');

$initials = '';
$fullName = trim((string)($adminUser['name'] ?? 'Super Admin'));
$nameParts = array_values(array_filter(preg_split('/\s+/', $fullName) ?: []));
if (!empty($nameParts)) {
    $initials = strtoupper(substr($nameParts[0], 0, 1));
    if (count($nameParts) > 1) {
        $initials .= strtoupper(substr($nameParts[count($nameParts) - 1], 0, 1));
    }
} else {
    $initials = 'AP';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Super Admin Console', ENT_QUOTES, 'UTF-8') ?> — OminiFlow POS</title>
    
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --adm-bg: #f8fafc;
            --adm-surface: #ffffff;
            --adm-border: #e2e8f0;
            --adm-border-subtle: #f1f5f9;
            --adm-primary: #0f172a;
            --adm-primary-hover: #1e293b;
            --adm-accent: #2563eb;
            --adm-accent-hover: #1d4ed8;
            --adm-accent-light: #eff6ff;
            --adm-text: #0f172a;
            --adm-text-secondary: #475569;
            --adm-text-muted: #64748b;
            --adm-rail-width: 72px;
            --adm-header-height: 76px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--adm-bg);
            color: var(--adm-text);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .adm-layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* ================= 1. LEFT SLIM ICON RAIL (ORIGINAL 72px WIDTH) ================= */
        .sidebar-rail {
            width: var(--adm-rail-width);
            background: #060a12;
            border-right: 1px solid #151f32;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 10px 0 0;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            max-height: 100vh;
            overflow: hidden;
            z-index: 1060;
            flex-shrink: 0;
            box-sizing: border-box;
        }

        .rail-main {
            flex: 1 1 auto;
            min-height: 0;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow-x: hidden;
            overflow-y: auto;
            padding-bottom: 8px;
            scrollbar-width: thin;
            scrollbar-color: #334155 transparent;
        }

        .rail-main::-webkit-scrollbar {
            width: 4px;
        }

        .rail-main::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }

        .rail-top-btn {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background: transparent;
            border: 0;
            transition: all 0.15s ease;
            margin-bottom: 14px;
        }

        .rail-top-btn:hover {
            color: #ffffff;
            background: rgba(255,255,255,0.08);
        }

        .rail-nav {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
            align-items: center;
        }

        .rail-tab-btn {
            width: 64px;
            height: 62px;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            background: transparent;
            border: 0;
            letter-spacing: 0.01em;
            line-height: 1.15;
            text-align: center;
        }

        .rail-tab-btn:hover {
            color: #ffffff !important;
            background: #151f32;
        }

        .rail-tab-btn:hover svg {
            color: #ffffff !important;
        }

        .rail-tab-btn.active {
            color: #ffffff !important;
            background: #1e293b;
            border-left: 3px solid #3b82f6;
            border-radius: 0 8px 8px 0;
        }

        .rail-tab-btn.active svg {
            color: #38bdf8 !important;
        }

        .rail-bottom {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: center;
            width: 100%;
            padding: 10px 0 calc(12px + env(safe-area-inset-bottom, 0px));
            border-top: 1px solid #151f32;
            background: #060a12;
            box-sizing: border-box;
        }

        .rail-gear-btn {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #e2e8f0;
            border: 1px solid #cbd5e1;
            color: #334155;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .rail-gear-btn:hover {
            background: #ffffff;
            color: #0f172a;
            border-color: #94a3b8;
        }

        .rail-gear-btn.active {
            background: #1e293b;
            border-color: #3b82f6;
            color: #38bdf8;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.4);
        }

        .rail-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid rgba(255, 255, 255, 0.35);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
            transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
            flex-shrink: 0;
        }

        .rail-avatar:hover {
            transform: scale(1.08);
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.4), 0 4px 14px rgba(37, 99, 235, 0.4);
        }

        /* Profile Dropdown Popup */
        .sidebar-profile-menu {
            display: none;
            position: fixed;
            left: 76px;
            bottom: 18px;
            width: 250px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.22);
            z-index: 9999;
            padding: 8px 0;
            animation: spFadeIn 0.15s ease;
        }

        @keyframes spFadeIn {
            from { opacity: 0; transform: translateX(-6px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .sidebar-profile-menu.show {
            display: block;
        }

        .sidebar-profile-header {
            padding: 10px 16px 8px;
            border-bottom: 1px solid #f1f5f9;
        }

        .sp-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #0f172a;
        }

        .sp-email {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 2px;
            word-break: break-all;
        }

        .sp-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 16px;
            font-size: 13px;
            font-weight: 500;
            color: #334155;
            text-decoration: none;
            transition: all 0.1s ease;
        }

        .sp-item:hover {
            background: #f8fafc;
            color: #2563eb;
        }

        .sp-item.logout {
            color: #ef4444;
        }

        .sp-item.logout:hover {
            background: #fef2f2;
            color: #dc2626;
        }

        /* ================= 2. MAIN APPLICATION CONTENT ================= */
        .app-main {
            margin-left: var(--adm-rail-width);
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: var(--adm-bg);
        }

        /* Top Header Component */
        .app-header {
            height: var(--adm-header-height);
            background: #ffffff;
            border-bottom: 1px solid var(--adm-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            position: sticky;
            top: 0;
            z-index: 900;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-brand-logo-link {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            transition: opacity 0.15s ease;
        }

        .header-brand-logo-link:hover {
            opacity: 0.9;
        }

        .header-brand-logo {
            height: 54px;
            width: auto;
            max-width: 260px;
            object-fit: contain;
            object-position: left center;
        }

        .header-title-divider {
            width: 1px;
            height: 24px;
            background: var(--adm-border);
        }

        .header-title-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.01em;
        }

        /* Center Search Bar with Keyboard Shortcut */
        .header-center {
            flex: 1;
            max-width: 460px;
            margin: 0 24px;
            position: relative;
        }

        .adm-search-wrap {
            position: relative;
            width: 100%;
        }

        .adm-search-input {
            width: 100%;
            background: #f8fafc;
            border: 1px solid var(--adm-border);
            border-radius: 8px;
            padding: 8px 36px 8px 34px;
            color: #0f172a;
            font-size: 13px;
            outline: none;
            transition: all 0.15s ease;
        }

        .adm-search-input:focus {
            background: #ffffff;
            border-color: #94a3b8;
            box-shadow: 0 0 0 2px rgba(15, 23, 42, 0.06);
        }

        .adm-search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }

        .adm-search-shortcut {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: #ffffff;
            color: #94a3b8;
            font-size: 11px;
            font-family: 'JetBrains Mono', monospace;
            padding: 2px 5px;
            border-radius: 4px;
            border: 1px solid var(--adm-border);
            pointer-events: none;
        }

        .admin-search-results {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #ffffff;
            color: #0f172a;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.12);
            border: 1px solid var(--adm-border);
            display: none;
            z-index: 1001;
            max-height: 400px;
            overflow-y: auto;
        }

        .admin-search-results.show {
            display: block;
        }

        .search-res-item {
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.12s;
        }

        .search-res-item:last-child {
            border-bottom: none;
        }

        .search-res-item:hover {
            background: #f8fafc;
        }

        /* Header Right Actions */
        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .adm-pos-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 13px;
            border: 1px solid var(--adm-border);
            border-radius: 8px;
            background: #ffffff;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .adm-pos-link:hover {
            background: #f8fafc;
            color: #0f172a;
            border-color: #cbd5e1;
        }

        /* ================= 3. TOP NAVIGATION TABS (ZOHO STYLE) ================= */
        .zoho-home-tabs {
            display: flex;
            align-items: center;
            gap: 28px;
            padding: 14px 32px 0;
            background: #ffffff;
            border-bottom: 1px solid var(--adm-border);
        }

        .zoho-tab-item {
            padding: 6px 4px 14px;
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

        /* ================= 4. CONTENT WRAPPER & CARDS ================= */
        .adm-content {
            padding: 24px 32px;
            flex: 1;
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
        }

        .admin-card {
            background: var(--adm-surface);
            border: 1px solid var(--adm-border);
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .admin-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--adm-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
        }

        .admin-card-title {
            font-size: 14.5px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-card-body {
            padding: 20px;
        }

        /* Buttons (Light / Minimal Clean) */
        .btn-adm {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            outline: none;
        }

        .btn-adm-primary {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
        }

        .btn-adm-primary:hover {
            background: #1e293b;
            border-color: #1e293b;
        }

        .btn-adm-secondary {
            background: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .btn-adm-secondary:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0f172a;
        }

        .btn-adm-login {
            background: #2563eb;
            color: #ffffff;
            border: 1px solid #2563eb;
        }

        .btn-adm-login:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .btn-adm-sm {
            padding: 5px 10px;
            font-size: 12px;
            border-radius: 5px;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
        }

        .status-badge.active {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .status-badge.suspended, .status-badge.inactive {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .status-badge.trial {
            background: #fefce8;
            color: #854d0e;
            border: 1px solid #fef08a;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        /* Tables */
        .adm-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .adm-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        .adm-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 12px 16px;
            border-bottom: 1px solid var(--adm-border);
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .adm-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--adm-border-subtle);
            color: #334155;
            vertical-align: middle;
        }

        .adm-table tbody tr:hover {
            background: #f8fafc;
        }

        /* Switches */
        .switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 20px;
            vertical-align: middle;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .2s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .2s;
            border-radius: 50%;
            box-shadow: 0 1px 2px rgba(0,0,0,0.15);
        }

        input:checked + .slider {
            background-color: #0f172a;
        }

        input:checked + .slider:before {
            transform: translateX(18px);
        }

        /* Alerts */
        .adm-alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13.5px;
        }

        .adm-alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .adm-alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Modals */
        .adm-modal-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(2px);
            z-index: 1050;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .adm-modal-backdrop.show {
            display: flex;
        }

        .adm-modal {
            background: #ffffff;
            border-radius: 10px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 15px 30px rgba(15, 23, 42, 0.15);
            border: 1px solid var(--adm-border);
            overflow: hidden;
            animation: modalPop 0.15s ease-out;
        }

        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.97); }
            to { opacity: 1; transform: scale(1); }
        }

        .adm-modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--adm-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .adm-modal-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }

        .adm-modal-body {
            padding: 20px;
            max-height: calc(85vh - 120px);
            overflow-y: auto;
        }

        .adm-modal-footer {
            padding: 14px 20px;
            background: #f8fafc;
            border-top: 1px solid var(--adm-border);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        @media (max-width: 900px) {
            .app-main {
                margin-left: 0;
            }
            .sidebar-rail {
                display: none;
            }
            .header-center {
                display: none;
            }
            .app-header, .zoho-home-tabs, .adm-content {
                padding-left: 16px;
                padding-right: 16px;
            }
        }
    </style>
</head>
<body>

<div class="adm-layout">
    <!-- ================= 1. LEFT SLIM ICON RAIL (ORIGINAL 72px WIDTH) ================= -->
    <aside class="sidebar-rail" id="appSidebarRail">
        <div class="rail-main">
            <!-- Pin Button at Top -->
            <button type="button" class="rail-top-btn" title="OminiFlow POS Admin Console">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 4v4l2 2v2h-5v8l-1 1-1-1v-8H6v-2l2-2V4a1 1 0 011-1h6a1 1 0 011 1z"/>
                </svg>
            </button>

            <!-- Navigation Tabs (Exact 64px x 62px Rail Buttons) -->
            <div class="rail-nav">
                <!-- Dashboard -->
                <a href="<?= asset('admin/dashboard.php') ?>" class="rail-tab-btn <?= in_array($currentAdminPage, ['dashboard.php', 'index.php'], true) ? 'active' : '' ?>" title="Admin Dashboard">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                    </svg>
                    <span>Dashboard</span>
                </a>

                <!-- Clients & Stores -->
                <a href="<?= asset('admin/clients.php') ?>" class="rail-tab-btn <?= in_array($currentAdminPage, ['clients.php', 'client-features.php'], true) ? 'active' : '' ?>" title="Clients &amp; Stores Directory">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <span>Clients</span>
                </a>

                <!-- Subscriptions -->
                <a href="<?= asset('admin/subscriptions.php') ?>" class="rail-tab-btn <?= $currentAdminPage === 'subscriptions.php' ? 'active' : '' ?>" title="Subscriptions &amp; Billing">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                    </svg>
                    <span>Plans</span>
                </a>

                <!-- Feature Catalog -->
                <a href="<?= asset('admin/features.php') ?>" class="rail-tab-btn <?= $currentAdminPage === 'features.php' ? 'active' : '' ?>" title="Feature Catalog">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/>
                    </svg>
                    <span>Features</span>
                </a>

                <!-- Settings Tab (Stays on Admin Panel) -->
                <a href="<?= asset('admin/settings.php') ?>" class="rail-tab-btn <?= $currentAdminPage === 'settings.php' ? 'active' : '' ?>" title="Super Admin Settings">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span>Settings</span>
                </a>

                <!-- Global Search Trigger -->
                <button type="button" class="rail-tab-btn" onclick="focusAdminSearch()" title="Spotlight Client Search (/)" style="margin-top: 4px;">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <span>Search</span>
                </button>
            </div>
        </div>

        <!-- Rail Bottom Section (Settings Square + Avatar Circle) -->
        <div class="rail-bottom">
            <!-- Gear Icon in Light Rounded Box -> Points to admin/settings.php (STAYS ON ADMIN) -->
            <a href="<?= asset('admin/settings.php') ?>" class="rail-gear-btn <?= $currentAdminPage === 'settings.php' ? 'active' : '' ?>" title="Super Admin Settings">
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>

            <!-- Blue Circular Avatar Trigger -->
            <button type="button" class="rail-avatar" id="adminProfileTrigger" onclick="toggleAdminProfileMenu(event)" title="<?= $adminName ?>">
                <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </aside>

    <!-- Profile Flyout Menu -->
    <div class="sidebar-profile-menu" id="adminProfileMenu">
        <div class="sidebar-profile-header">
            <div class="sp-name"><?= $adminName ?></div>
            <div class="sp-email"><?= $adminEmail ?></div>
            <div style="margin-top: 5px;">
                <span class="status-badge active" style="font-size: 10px; padding: 2px 7px;">Super Admin</span>
            </div>
        </div>
        <a href="<?= asset('admin/settings.php') ?>" class="sp-item">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span>Admin Settings</span>
        </a>
        <a href="<?= asset('admin/features.php') ?>" class="sp-item">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
            <span>Feature Catalog</span>
        </a>
        <a href="<?= asset('dashboard.php') ?>" class="sp-item" title="Open POS Billing Register">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            <span>Storefront POS</span>
        </a>
        <div style="border-top: 1px solid #f1f5f9; margin: 4px 0;"></div>
        <a href="<?= asset('admin/logout.php') ?>" class="sp-item logout">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            <span>Sign Out</span>
        </a>
    </div>

    <!-- ================= 2. MAIN APPLICATION CONTENT COLUMN ================= -->
    <div class="app-main">
        <!-- Top Clean White Header -->
        <header class="app-header">
            <div class="header-left">
                <a href="<?= asset('admin/dashboard.php') ?>" class="header-brand-logo-link" title="OminiFlow POS">
                    <img src="<?= asset('assets/images/ominiflow_logo.png') ?>" alt="OminiFlow" class="header-brand-logo">
                </a>
                <div class="header-title-divider"></div>
                <div class="header-title-wrap">
                    <h1 class="header-title">Super Admin</h1>
                </div>
            </div>

            <!-- Global Search in Top Header -->
            <div class="header-center">
                <div class="adm-search-wrap">
                    <span class="adm-search-icon">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input 
                        type="text" 
                        id="adminGlobalSearch" 
                        class="adm-search-input" 
                        placeholder="Search clients by email, store name..." 
                        autocomplete="off"
                    >
                    <span class="adm-search-shortcut">/</span>
                    <div id="adminSearchResults" class="admin-search-results"></div>
                </div>
            </div>

            <!-- Header Right Actions -->
            <div class="header-right">
                <a href="<?= asset('admin/settings.php') ?>" class="adm-pos-link" title="Super Admin Settings">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span>Settings</span>
                </a>

                <a href="<?= asset('dashboard.php') ?>" class="adm-pos-link" title="Open POS Storefront as Super Admin">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    <span>Storefront POS</span>
                </a>

                <a href="<?= asset('admin/logout.php') ?>" class="btn-adm btn-adm-secondary btn-adm-sm" style="font-size: 12.5px; padding: 6px 12px; border-radius: 8px;" title="Sign Out">
                    Sign Out
                </a>
            </div>
        </header>

        <!-- Top Zoho Navigation Tabs (Exact match with OminiFlow POS) -->
        <div class="zoho-home-tabs">
            <a href="<?= asset('admin/dashboard.php') ?>" class="zoho-tab-item <?= in_array($currentAdminPage, ['dashboard.php', 'index.php'], true) ? 'active' : '' ?>">
                Dashboard
            </a>
            <a href="<?= asset('admin/clients.php') ?>" class="zoho-tab-item <?= in_array($currentAdminPage, ['clients.php', 'client-features.php'], true) ? 'active' : '' ?>">
                Clients &amp; Stores
            </a>
            <a href="<?= asset('admin/subscriptions.php') ?>" class="zoho-tab-item <?= $currentAdminPage === 'subscriptions.php' ? 'active' : '' ?>">
                Subscriptions
            </a>
            <a href="<?= asset('admin/features.php') ?>" class="zoho-tab-item <?= $currentAdminPage === 'features.php' ? 'active' : '' ?>">
                Feature Catalog
            </a>
            <a href="<?= asset('admin/settings.php') ?>" class="zoho-tab-item <?= $currentAdminPage === 'settings.php' ? 'active' : '' ?>">
                Settings
            </a>
        </div>

        <!-- CONTENT BODY CONTAINER -->
        <main class="adm-content">
            <?php if ($flashSuccess = get_flash('success')): ?>
                <div class="adm-alert adm-alert-success">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <?php if ($flashError = get_flash('error')): ?>
                <div class="adm-alert adm-alert-danger">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

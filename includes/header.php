<?php
/**
 * Dashboard Header Component for OminiFlow POS
 */

declare(strict_types=1);
?>
<header class="app-header">
    <div class="header-left">
        <button class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle navigation">
            <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>
        <a href="<?= asset('dashboard.php') ?>" class="header-brand-logo-link" title="OminiFlow POS">
            <img src="<?= asset('assets/images/logo.jpg') ?>" alt="OminiFlow" class="header-brand-logo">
        </a>
        <div class="header-title-divider"></div>
        <div class="header-title-wrap">
            <h1 class="header-title"><?= e($pageTitle ?? 'POS Dashboard') ?></h1>
        </div>
    </div>

    <div class="header-right">
        <?php
        if (!function_exists('get_pos_low_stock_alerts')) {
            require_once __DIR__ . '/products_db.php';
        }
        $hdrLow = function_exists('get_pos_low_stock_alerts') ? get_pos_low_stock_alerts() : [];
        $hdrUnsold = function_exists('get_pos_unsold_products_alerts') ? get_pos_unsold_products_alerts(7) : [];
        $hdrAlertsCount = count($hdrLow) + count($hdrUnsold);
        ?>
        <button type="button" class="header-icon-btn pos-alerts-header-btn" id="openHeaderAlertsBtn" title="Daily Inventory & Sales Notices (<?= $hdrAlertsCount ?>)" style="position: relative; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s ease;">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: <?= $hdrAlertsCount > 0 ? '#ea580c' : '#64748b' ?>;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <?php if ($hdrAlertsCount > 0): ?>
                <span style="position: absolute; top: -5px; right: -5px; background: #ef4444; color: #fff; font-size: 10px; font-weight: 800; border-radius: 9999px; min-width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; padding: 0 4px; border: 2px solid #fff; line-height: 1;">
                    <?= $hdrAlertsCount ?>
                </span>
            <?php endif; ?>
        </button>
    </div>
</header>

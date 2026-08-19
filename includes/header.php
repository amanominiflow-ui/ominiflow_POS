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
        <!-- Clean header right side -->
    </div>
</header>

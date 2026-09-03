<?php
/**
 * Dual-Rail Dashboard Sidebar Component for OminiFlow POS (Zoho POS Architecture)
 */

declare(strict_types=1);

$user = current_user();
$business = current_business();
$userName = $user ? $user['name'] : 'Aman Prajapat';
$userEmail = $user ? $user['email'] : '';
$businessName = $business ? $business['name'] : ($user ? $user['name'] : 'OminiFlow POS');
$displayName = strtoupper($businessName);

$initials = '';
$fullName = trim((string)($user['name'] ?? $userName));
$nameParts = array_values(array_filter(preg_split('/\s+/', $fullName) ?: []));
if (!empty($nameParts)) {
    $initials = strtoupper(substr($nameParts[0], 0, 1));
    if (count($nameParts) > 1) {
        $initials .= strtoupper(substr($nameParts[count($nameParts) - 1], 0, 1));
    }
} else {
    $initials = 'RN';
}

$bizInitials = 'OF';
$bizParts = array_values(array_filter(preg_split('/\s+/', trim((string) $businessName)) ?: []));
if (!empty($bizParts)) {
    $bizInitials = strtoupper(substr($bizParts[0], 0, 1));
    if (count($bizParts) > 1) {
        $bizInitials .= strtoupper(substr($bizParts[count($bizParts) - 1], 0, 1));
    } else {
        $bizInitials = strtoupper(substr($bizParts[0], 0, min(2, strlen($bizParts[0]))));
    }
}

$profileLogoUrl = '';
try {
    $logoPath = '';
    $stLogo = get_db()->prepare('SELECT logo_path FROM business_profile WHERE business_id = :bid LIMIT 1');
    $stLogo->execute(['bid' => current_business_id()]);
    $logoPath = (string) ($stLogo->fetchColumn() ?: '');
    if ($logoPath === '') {
        $stLogo2 = get_db()->query('SELECT logo_path FROM business_profile WHERE id = 1 LIMIT 1');
        $logoPath = $stLogo2 ? (string) ($stLogo2->fetchColumn() ?: '') : '';
    }
    if ($logoPath !== '' && is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logoPath))) {
        $profileLogoUrl = asset($logoPath);
    }
} catch (Throwable $logoErr) {
    $profileLogoUrl = '';
}

$userIdLabel = function_exists('pos_public_user_id')
    ? pos_public_user_id((int) ($user['id'] ?? 0))
    : (string) (60077667000 + (int) ($user['id'] ?? 0));
$isPremiumPlan = function_exists('is_premium_active') && is_premium_active();

$currentPage = basename($_SERVER['PHP_SELF']);

// Active rail tab selection
$activeRailTab = 'business';
if (in_array($currentPage, ['pos.php', 'registers.php', 'payment-options.php', 'online-store.php'], true)) {
    $activeRailTab = 'channels';
} elseif ($currentPage === 'reports.php') {
    $activeRailTab = 'reports';
} elseif (in_array($currentPage, ['settings.php', 'payment-integrations.php', 'customer-payments.php', 'integrations-whatsapp.php', 'integrations-shipping.php', 'integrations-cart.php', 'taxes.php', 'business-profile.php', 'users.php', 'roles.php', 'role-create.php'], true)) {
    $activeRailTab = 'settings';
}

// Active sub-groups mapping for Business tab (Single Active Group at a time)
$inventoryPages = ['products.php', 'product-create.php', 'product-edit.php', 'categories.php', 'stock-count.php', 'inventory.php', 'outlets.php', 'transfers.php', 'barcode-print.php'];
$salesPages = ['orders.php', 'invoices.php', 'invoice-view.php', 'invoice-create.php', 'fulfillment.php', 'returns.php', 'consignment-manifest.php'];
$purchasesPages = ['vendors.php', 'purchases.php', 'purchase-receives.php', 'bills.php', 'payments-made.php', 'purchase-returns.php'];
$customersPages = ['customers.php', 'promotions.php'];
$documentsPages = ['import-export.php', 'settings.php'];

$isInventoryOpen = in_array($currentPage, $inventoryPages, true);
$isSalesOpen = !$isInventoryOpen && in_array($currentPage, $salesPages, true);
$isPurchasesOpen = !$isInventoryOpen && !$isSalesOpen && in_array($currentPage, $purchasesPages, true);
$isCustomersOpen = !$isInventoryOpen && !$isSalesOpen && !$isPurchasesOpen && in_array($currentPage, $customersPages, true);
$isDocumentsOpen = !$isInventoryOpen && !$isSalesOpen && !$isPurchasesOpen && !$isCustomersOpen && in_array($currentPage, $documentsPages, true);
?>
<style>
/* Zoho POS Full Profile Flyout Drawer */
.zoho-profile-drawer {
    display: none;
    position: fixed;
    left: 72px;
    top: 0;
    bottom: 0;
    width: 392px;
    background: #eef2f6 !important;
    border-right: 1px solid #e2e8f0;
    box-shadow: 12px 0 40px rgba(15, 23, 42, 0.22);
    z-index: 100000 !important;
    flex-direction: column;
    animation: zpdSlideIn 0.18s cubic-bezier(0.16, 1, 0.3, 1);
    font-family: inherit;
}

@keyframes zpdSlideIn {
    from { opacity: 0; transform: translateX(-12px); }
    to { opacity: 1; transform: translateX(0); }
}

.zoho-profile-drawer.show {
    display: flex !important;
}

.zpd-header {
    background: linear-gradient(180deg, #4b8dff 0%, #3b7cf6 55%, #3574ef 100%) !important;
    padding: 20px 20px 18px;
    color: #ffffff !important;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    flex-shrink: 0;
}

.zpd-avatar {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    background: #0f766e;
    color: #ffffff;
    font-size: 18px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18);
    border: 2px solid rgba(255, 255, 255, 0.35);
}

.zpd-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.zpd-header-meta {
    min-width: 0;
    padding-top: 1px;
}

.zpd-name {
    font-size: 16.5px;
    font-weight: 800;
    color: #ffffff !important;
    line-height: 1.2;
    letter-spacing: 0.01em;
}

.zpd-uid {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.88) !important;
    margin-top: 4px;
    font-weight: 500;
}

.zpd-email {
    font-size: 12.5px;
    color: rgba(255, 255, 255, 0.92) !important;
    margin-top: 2px;
    word-break: break-all;
}

.zpd-org-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255, 255, 255, 0.18);
    padding: 4px 10px 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    color: #ffffff !important;
    margin-top: 10px;
    cursor: pointer;
    max-width: 100%;
}

.zpd-org-badge span:nth-child(2) {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.zpd-org-badge svg {
    flex-shrink: 0;
    opacity: 0.95;
}

.zpd-body {
    flex: 1;
    overflow-y: auto;
    padding: 14px 14px 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.zpd-card {
    background: #ffffff !important;
    border: 0;
    border-radius: 8px;
    padding: 16px 18px 14px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}

.zpd-card-heading {
    font-size: 12.5px;
    font-weight: 800;
    color: #1e293b !important;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    margin-bottom: 12px;
    line-height: 1.35;
}

.zpd-sub-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.zpd-sub-text {
    font-size: 13.5px;
    color: #475569 !important;
    font-weight: 500;
    line-height: 1.4;
}

.zpd-btn-outline {
    display: inline-block;
    padding: 5px 14px;
    border: 1px solid #3b82f6;
    border-radius: 4px;
    font-size: 12.5px;
    font-weight: 600;
    color: #2563eb !important;
    text-decoration: none !important;
    background: #ffffff !important;
    flex-shrink: 0;
    transition: background 0.12s, color 0.12s;
}

.zpd-btn-outline:hover {
    background: #eff6ff !important;
    color: #1d4ed8 !important;
}

.zpd-list {
    display: flex;
    flex-direction: column;
    gap: 13px;
}

.zpd-link-row {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13.5px;
    color: #334155 !important;
    text-decoration: none !important;
    font-weight: 500;
    line-height: 1.3;
    transition: color 0.12s;
}

.zpd-link-row svg {
    flex-shrink: 0;
    color: #64748b;
}

.zpd-link-row:hover {
    color: #2563eb !important;
}

.zpd-link-row:hover svg {
    color: #2563eb;
}

.zpd-footer {
    min-height: 52px;
    background: #ffffff !important;
    border-top: 1px solid #e8edf3;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 20px;
    flex-shrink: 0;
}

.zpd-footer-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 13.5px;
    font-weight: 600;
    color: #2563eb !important;
    text-decoration: none !important;
}

.zpd-footer-link.logout {
    color: #ef4444 !important;
}

.zpd-footer-link:hover {
    opacity: 0.82;
}
</style>
<aside class="app-sidebar" id="appSidebar">
    <!-- 1. LEFTMOST SLIM ICON RAIL (64px) -->
    <div class="sidebar-rail">
        <div style="display: flex; flex-direction: column; align-items: center; width: 100%;">
            <!-- Top Pin / Toggle Button (Zoho Pin Icon) -->
            <button type="button" class="rail-top-btn" onclick="togglePinSidebar()" title="Pin / Unpin Navigation">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 4v4l2 2v2h-5v8l-1 1-1-1v-8H6v-2l2-2V4a1 1 0 011-1h6a1 1 0 011 1z"/>
                </svg>
            </button>

            <!-- Rail Navigation Tabs (Hover & Click Supported) -->
            <div class="rail-nav">
                <!-- Business Tab -->
                <button type="button" class="rail-tab-btn <?= $activeRailTab === 'business' ? 'active' : '' ?>" onmouseenter="onRailHover('business')" onclick="switchRailTab('business')" id="rail-btn-business" title="Business Modules">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <span>Business</span>
                </button>

                <!-- Sales Channels Tab -->
                <button type="button" class="rail-tab-btn <?= $activeRailTab === 'channels' ? 'active' : '' ?>" onmouseenter="onRailHover('channels')" onclick="switchRailTab('channels')" id="rail-btn-channels" title="Sales Channels & POS">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Sales<br>Channels</span>
                </button>

                <!-- Reports Tab -->
                <button type="button" class="rail-tab-btn <?= $activeRailTab === 'reports' ? 'active' : '' ?>" onmouseenter="onRailHover('reports')" onclick="switchRailTab('reports')" id="rail-btn-reports" title="Analytics & Reports">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span>Reports</span>
                </button>

                <!-- Quick Spotlight Search Button (Extra gap above) -->
                <button type="button" class="rail-tab-btn" onclick="openSpotlightSearch()" title="Global Spotlight Search (/)" style="margin-top: 16px;">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <span>Search</span>
                </button>
            </div>
        </div>

        <!-- Rail Bottom Utility Icons -->
        <div class="rail-bottom">
            <a href="<?= asset('settings.php') ?>" class="rail-icon-btn <?= $activeRailTab === 'settings' ? 'active' : '' ?>" onmouseenter="onRailHover('settings')" onclick="switchRailTab('settings')" id="rail-btn-settings" title="Settings">
                <svg width="25" height="25" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>

            <button type="button" class="rail-avatar" id="sidebarProfileTrigger" onclick="toggleSidebarProfileMenu(event)" title="<?= e($userName) ?>">
                <?= e($initials) ?>
            </button>
        </div>
    </div>

    <!-- 2. RIGHT MAIN MODULE DRAWER (240px) -->
    <div class="sidebar-drawer">
        <!-- Top App Brand -->
        <div class="sidebar-header">
            <a href="<?= asset('dashboard.php') ?>" class="sidebar-brand">
                <div class="zoho-pos-brand">
                    <svg width="22" height="22" fill="none" stroke="#ffffff" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="13" height="13" rx="3" stroke-width="2"/>
                        <path d="M8 21h10a3 3 0 003-3V8" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span class="zoho-pos-title">POS</span>
                </div>
            </a>
        </div>

        <!-- ================= TAB CONTENT 1: BUSINESS ================= -->
        <div class="drawer-tab-content <?= $activeRailTab === 'business' ? 'active' : '' ?>" id="drawer-tab-business">
            <!-- User / Organization Name Banner with Blue Underline -->
            <div class="sidebar-store-bar">
                <div class="store-badge-title"><?= e($displayName) ?></div>
            </div>

            <nav class="sidebar-nav">
                <!-- Home -->
                <a href="<?= asset('dashboard.php') ?>" class="nav-item <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                    </span>
                    <span>Home</span>
                </a>

                <!-- Inventory Group -->
                <div class="nav-group <?= $isInventoryOpen ? 'open' : '' ?>" id="grp-inventory">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-inventory')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                            </span>
                            <span>Inventory</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('products.php') ?>" class="submenu-link <?= in_array($currentPage, ['products.php', 'product-create.php', 'product-edit.php'], true) ? 'active' : '' ?>">Items</a>
                        <a href="<?= asset('categories.php') ?>" class="submenu-link <?= $currentPage === 'categories.php' ? 'active' : '' ?>">Categories</a>
                        <a href="<?= asset('stock-count.php') ?>" class="submenu-link <?= $currentPage === 'stock-count.php' ? 'active' : '' ?>">Adjustments</a>
                        <a href="<?= asset('inventory.php') ?>" class="submenu-link <?= $currentPage === 'inventory.php' ? 'active' : '' ?>">Stock Movements</a>
                        <a href="<?= asset('outlets.php') ?>" class="submenu-link <?= $currentPage === 'outlets.php' ? 'active' : '' ?>">Outlets</a>
                        <a href="<?= asset('transfers.php') ?>" class="submenu-link <?= $currentPage === 'transfers.php' ? 'active' : '' ?>">Transfers</a>
                        <a href="<?= asset('barcode-print.php') ?>" class="submenu-link <?= $currentPage === 'barcode-print.php' ? 'active' : '' ?>">Barcode Labels</a>
                    </div>
                </div>

                <!-- Sales Group -->
                <div class="nav-group <?= $isSalesOpen ? 'open' : '' ?>" id="grp-sales">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-sales')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </span>
                            <span>Sales</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('orders.php') ?>" class="submenu-link <?= $currentPage === 'orders.php' ? 'active' : '' ?>">Orders</a>
                        <a href="<?= asset('invoices.php') ?>" class="submenu-link <?= in_array($currentPage, ['invoices.php', 'invoice-view.php', 'invoice-create.php'], true) ? 'active' : '' ?>">Invoices</a>
                        <a href="<?= asset('fulfillment.php') ?>" class="submenu-link <?= $currentPage === 'fulfillment.php' ? 'active' : '' ?>">Shipments</a>
                        <a href="<?= asset('returns.php') ?>" class="submenu-link <?= $currentPage === 'returns.php' ? 'active' : '' ?>">Returns</a>
                        <a href="<?= asset('consignment-manifest.php') ?>" class="submenu-link <?= $currentPage === 'consignment-manifest.php' ? 'active' : '' ?>">Consignment &amp; COD Label Manifest</a>
                    </div>
                </div>

                <!-- Purchases Group -->
                <div class="nav-group <?= $isPurchasesOpen ? 'open' : '' ?>" id="grp-purchases">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-purchases')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                                </svg>
                            </span>
                            <span>Purchases</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('vendors.php') ?>" class="submenu-link <?= $currentPage === 'vendors.php' ? 'active' : '' ?>">Vendors</a>
                        <a href="<?= asset('purchases.php') ?>" class="submenu-link <?= $currentPage === 'purchases.php' ? 'active' : '' ?>">Purchase Orders</a>
                        <a href="<?= asset('purchase-receives.php') ?>" class="submenu-link <?= $currentPage === 'purchase-receives.php' ? 'active' : '' ?>">Purchase Receives</a>
                        <a href="<?= asset('bills.php') ?>" class="submenu-link <?= $currentPage === 'bills.php' ? 'active' : '' ?>">Bills</a>
                        <a href="<?= asset('payments-made.php') ?>" class="submenu-link <?= $currentPage === 'payments-made.php' ? 'active' : '' ?>">Payments Made</a>
                        <a href="<?= asset('purchase-returns.php') ?>" class="submenu-link <?= $currentPage === 'purchase-returns.php' ? 'active' : '' ?>">Vendor Credits</a>
                    </div>
                </div>

                <!-- Customers & Perks Group -->
                <div class="nav-group <?= $isCustomersOpen ? 'open' : '' ?>" id="grp-customers">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-customers')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                            </span>
                            <span>Customers & Perks</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('customers.php') ?>" class="submenu-link <?= $currentPage === 'customers.php' ? 'active' : '' ?>">Customers</a>
                        <a href="<?= asset('promotions.php') ?>" class="submenu-link <?= $currentPage === 'promotions.php' ? 'active' : '' ?>">Loyalty</a>
                    </div>
                </div>

                <!-- Documents & Tools -->
                <div class="nav-group <?= $isDocumentsOpen ? 'open' : '' ?>" id="grp-docs">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-docs')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                </svg>
                            </span>
                            <span>Documents</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('reports.php') ?>" class="submenu-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>">Reports</a>
                        <a href="<?= asset('import-export.php') ?>" class="submenu-link <?= $currentPage === 'import-export.php' ? 'active' : '' ?>">Import / Export</a>
                        <a href="<?= asset('settings.php') ?>" class="submenu-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>">Settings</a>
                    </div>
                </div>
            </nav>
        </div>

        <!-- ================= TAB CONTENT 2: SALES CHANNELS ================= -->
        <div class="drawer-tab-content <?= $activeRailTab === 'channels' ? 'active' : '' ?>" id="drawer-tab-channels">
            <div class="sidebar-store-bar">
                <div class="store-badge-title">Sales Channels</div>
            </div>

            <nav class="sidebar-nav">
                <div class="drawer-section-title">POINT OF SALE</div>

                <a href="<?= asset('pos.php') ?>" class="nav-item <?= $currentPage === 'pos.php' ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color: #38bdf8;">
                        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                        </svg>
                    </span>
                    <span>POS</span>
                </a>

                <a href="<?= asset('registers.php') ?>" class="nav-item <?= $currentPage === 'registers.php' ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                    </span>
                    <span>Registers</span>
                </a>

                <!-- Customization Group -->
                <div class="nav-group open" id="grp-pos-cust">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-pos-cust')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                                </svg>
                            </span>
                            <span>Customization</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('settings.php') ?>" class="submenu-link">Preferences</a>
                        <a href="<?= asset('payment-options.php') ?>" class="submenu-link <?= $currentPage === 'payment-options.php' ? 'active' : '' ?>">Payment Options</a>
                        <a href="<?= asset('barcode-print.php') ?>" class="submenu-link">Print Templates</a>
                    </div>
                </div>

                <div class="drawer-section-title" style="margin-top: 10px;">E-COMMERCE & ONLINE CHANNELS</div>

                <a href="<?= asset('integrations-cart.php') ?>" class="nav-item <?= $currentPage === 'integrations-cart.php' ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color: #95BF47;">
                        <svg width="17" height="17" viewBox="0 0 109 124" fill="currentColor">
                            <path d="M72.5 19.3L64.3 16.9C64.3 13.5 63 10.3 60.5 7.9C58.1 5.5 54.8 4.2 51.4 4.2C48 4.2 44.8 5.5 42.3 7.9C39.8 10.3 38.5 13.5 38.5 16.9L30.6 19.3L20.8 113.8L82.1 119.8L72.5 19.3Z"/>
                        </svg>
                    </span>
                    <span>Shopify / Online Store</span>
                </a>

                <div class="drawer-section-title" style="margin-top: 10px;">MOBILE STORE</div>

                <a href="<?= asset('online-store.php') ?>" class="nav-item <?= $currentPage === 'online-store.php' && (($_GET['tab'] ?? 'overview') === 'overview') ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    </span>
                    <span>Overview</span>
                </a>
                <a href="<?= asset('online-store.php?tab=preferences') ?>" class="nav-item <?= $currentPage === 'online-store.php' && (($_GET['tab'] ?? '') === 'preferences') ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                    </span>
                    <span>Preferences</span>
                </a>
                <a href="<?= asset('online-store.php?tab=domain') ?>" class="nav-item <?= $currentPage === 'online-store.php' && (($_GET['tab'] ?? '') === 'domain') ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                    </span>
                    <span>Custom Domain</span>
                </a>
                <a href="<?= asset('online-store.php?tab=customize') ?>" class="nav-item <?= $currentPage === 'online-store.php' && in_array(($_GET['tab'] ?? ''), ['customize', 'branding'], true) ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </span>
                    <span>Customize App</span>
                </a>

                <div class="drawer-section-title" style="margin-top: 10px;">MULTI-STORE / WAREHOUSES</div>

                <a href="<?= asset('outlets.php') ?>" class="nav-item <?= $currentPage === 'outlets.php' ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    </span>
                    <span>Outlets Overview</span>
                </a>

                <a href="<?= asset('transfers.php') ?>" class="nav-item <?= $currentPage === 'transfers.php' ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                    </span>
                    <span>Inter-Store Transfers</span>
                </a>
            </nav>
        </div>

        <!-- ================= TAB CONTENT 3: REPORTS (Zoho POS Exact Parity) ================= -->
        <div class="drawer-tab-content <?= $activeRailTab === 'reports' ? 'active' : '' ?>" id="drawer-tab-reports">
            <div class="sidebar-store-bar">
                <div class="store-badge-title" style="color: #ffffff; border-bottom: 2px solid #3b82f6; width: fit-content; padding-bottom: 3px; font-weight: 700;">Reports</div>
            </div>

            <div class="drawer-search-wrap">
                <input type="text" placeholder="Search reports" class="drawer-search-input" id="reportSearchInp" onkeyup="filterReportsNav(this.value)">
            </div>

            <nav class="sidebar-nav">
                <!-- Special Views -->
                <a href="<?= asset('reports.php?view=favorites') ?>" class="nav-item <?= ($_GET['view'] ?? '') === 'favorites' ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color: #eab308;">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                    </span>
                    <span>Favorites</span>
                </a>

                <a href="<?= asset('reports.php?view=shared') ?>" class="nav-item <?= ($_GET['view'] ?? '') === 'shared' ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                    </span>
                    <span>Shared Reports</span>
                </a>

                <a href="<?= asset('reports.php?view=my-reports') ?>" class="nav-item <?= ($_GET['view'] ?? '') === 'my-reports' ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </span>
                    <span>My Reports</span>
                </a>

                <a href="<?= asset('reports.php?view=scheduled') ?>" class="nav-item <?= ($_GET['view'] ?? '') === 'scheduled' ? 'active' : '' ?>">
                    <span class="nav-item-icon">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                    <span>Scheduled Reports</span>
                </a>

                <div class="drawer-section-title">DEFAULT REPORTS</div>

                <!-- 1. Sales Group -->
                <?php $currentType = $_GET['type'] ?? 'sales-summary'; ?>
                <div class="nav-group <?= in_array($currentType, ['sales-summary', 'item-sales', 'sales-by-outlet', 'sales-by-cashier', 'category-performance'], true) ? 'open' : '' ?>" id="grp-rep-sales">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-rep-sales')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            </span>
                            <span>Sales</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('reports.php?type=sales-summary') ?>" class="submenu-link <?= $currentType === 'sales-summary' ? 'active' : '' ?>">Sales Summary</a>
                        <a href="<?= asset('reports.php?type=item-sales') ?>" class="submenu-link <?= $currentType === 'item-sales' ? 'active' : '' ?>">Item Sales Report</a>
                        <a href="<?= asset('reports.php?type=sales-by-outlet') ?>" class="submenu-link <?= $currentType === 'sales-by-outlet' ? 'active' : '' ?>">Sales by Outlet</a>
                        <a href="<?= asset('reports.php?type=sales-by-cashier') ?>" class="submenu-link <?= $currentType === 'sales-by-cashier' ? 'active' : '' ?>">Sales by Cashier</a>
                        <a href="<?= asset('reports.php?type=category-performance') ?>" class="submenu-link <?= $currentType === 'category-performance' ? 'active' : '' ?>">Category Performance</a>
                    </div>
                </div>

                <!-- 2. Inventory Group -->
                <div class="nav-group <?= in_array($currentType, ['stock-summary', 'inventory-movements', 'low-stock-alert', 'expiry-batches'], true) ? 'open' : '' ?>" id="grp-rep-inv">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-rep-inv')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            </span>
                            <span>Inventory</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('reports.php?type=stock-summary') ?>" class="submenu-link <?= $currentType === 'stock-summary' ? 'active' : '' ?>">Stock Summary</a>
                        <a href="<?= asset('reports.php?type=inventory-movements') ?>" class="submenu-link <?= $currentType === 'inventory-movements' ? 'active' : '' ?>">Stock Movements</a>
                        <a href="<?= asset('reports.php?type=low-stock-alert') ?>" class="submenu-link <?= $currentType === 'low-stock-alert' ? 'active' : '' ?>">Low Stock Alerts</a>
                        <a href="<?= asset('reports.php?type=expiry-batches') ?>" class="submenu-link <?= $currentType === 'expiry-batches' ? 'active' : '' ?>">Product Expiry & Batches</a>
                    </div>
                </div>

                <!-- 3. Inventory Valuation Group -->
                <div class="nav-group <?= in_array($currentType, ['inventory-valuation', 'category-valuation'], true) ? 'open' : '' ?>" id="grp-rep-val">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-rep-val')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </span>
                            <span>Inventory Valuation</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('reports.php?type=inventory-valuation') ?>" class="submenu-link <?= $currentType === 'inventory-valuation' ? 'active' : '' ?>">Valuation Summary</a>
                        <a href="<?= asset('reports.php?type=category-valuation') ?>" class="submenu-link <?= $currentType === 'category-valuation' ? 'active' : '' ?>">Category-wise Valuation</a>
                    </div>
                </div>

                <!-- 4. Receivables Group -->
                <div class="nav-group <?= in_array($currentType, ['customer-balances', 'receivables'], true) ? 'open' : '' ?>" id="grp-rep-rec">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-rep-rec')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </span>
                            <span>Receivables</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('reports.php?type=customer-balances') ?>" class="submenu-link <?= $currentType === 'customer-balances' ? 'active' : '' ?>">Customer Balances</a>
                        <a href="<?= asset('reports.php?type=receivables') ?>" class="submenu-link <?= $currentType === 'receivables' ? 'active' : '' ?>">Outstanding Receivables</a>
                    </div>
                </div>

                <!-- 5. Payments Received Group (Highlighted in Zoho Screenshot) -->
                <div class="nav-group <?= in_array($currentType, ['payments-received', 'credit-notes', 'refunds'], true) ? 'open' : '' ?>" id="grp-rep-payrec">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-rep-payrec')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            </span>
                            <span>Payments Received</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('reports.php?type=payments-received') ?>" class="submenu-link <?= $currentType === 'payments-received' ? 'active' : '' ?>">Payments Received</a>
                        <a href="<?= asset('reports.php?type=credit-notes') ?>" class="submenu-link <?= $currentType === 'credit-notes' ? 'active' : '' ?>">Credit Note Details</a>
                        <a href="<?= asset('reports.php?type=refunds') ?>" class="submenu-link <?= $currentType === 'refunds' ? 'active' : '' ?>">Refund History</a>
                    </div>
                </div>

                <!-- 6. Payables Group -->
                <div class="nav-group <?= in_array($currentType, ['vendor-balances', 'payables'], true) ? 'open' : '' ?>" id="grp-rep-pay">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-rep-pay')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </span>
                            <span>Payables</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('reports.php?type=vendor-balances') ?>" class="submenu-link <?= $currentType === 'vendor-balances' ? 'active' : '' ?>">Vendor Balances</a>
                        <a href="<?= asset('reports.php?type=payables') ?>" class="submenu-link <?= $currentType === 'payables' ? 'active' : '' ?>">Outstanding Payables</a>
                    </div>
                </div>

                <!-- 7. Purchases Group -->
                <div class="nav-group <?= in_array($currentType, ['purchase-summary', 'purchases-by-vendor', 'purchase-returns'], true) ? 'open' : '' ?>" id="grp-rep-pur">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-rep-pur')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                            </span>
                            <span>Purchases</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('reports.php?type=purchase-summary') ?>" class="submenu-link <?= $currentType === 'purchase-summary' ? 'active' : '' ?>">Purchase Summary</a>
                        <a href="<?= asset('reports.php?type=purchases-by-vendor') ?>" class="submenu-link <?= $currentType === 'purchases-by-vendor' ? 'active' : '' ?>">Purchases by Vendor</a>
                        <a href="<?= asset('reports.php?type=purchase-returns') ?>" class="submenu-link <?= $currentType === 'purchase-returns' ? 'active' : '' ?>">Purchase Returns</a>
                    </div>
                </div>

                <!-- 8. Activity & Audit Group -->
                <div class="nav-group <?= in_array($currentType, ['register-shifts', 'cash-movements', 'gst-tax'], true) ? 'open' : '' ?>" id="grp-rep-act">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-rep-act')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </span>
                            <span>Activity</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('reports.php?type=register-shifts') ?>" class="submenu-link <?= $currentType === 'register-shifts' ? 'active' : '' ?>">Register Shifts</a>
                        <a href="<?= asset('reports.php?type=cash-movements') ?>" class="submenu-link <?= $currentType === 'cash-movements' ? 'active' : '' ?>">Cash Movements</a>
                        <a href="<?= asset('reports.php?type=gst-tax') ?>" class="submenu-link <?= $currentType === 'gst-tax' ? 'active' : '' ?>">GST Tax & GSTR-1</a>
                    </div>
                </div>
            </nav>
        </div>

        <!-- ================= TAB CONTENT 4: SETTINGS (Zoho POS Parity) ================= -->
        <div class="drawer-tab-content <?= $activeRailTab === 'settings' ? 'active' : '' ?>" id="drawer-tab-settings">
            <!-- Settings Header with Active Underline -->
            <div class="sidebar-store-bar">
                <div class="store-badge-title" style="color: #ffffff; border-bottom: 2px solid #3b82f6; width: fit-content; padding-bottom: 3px; font-weight: 700;">Settings</div>
            </div>

            <!-- Search bar -->
            <div class="drawer-search-wrap">
                <input type="text" placeholder="Search" class="drawer-search-input" id="settingsSearchInp" onkeyup="filterSettingsNav(this.value)">
            </div>

            <div style="padding: 0 10px 6px;">
                <a href="<?= asset('settings.php') ?>" class="nav-item <?= ($currentPage === 'settings.php' && empty($_GET['tab'])) ? 'active' : '' ?>" style="border-radius: 6px; background: #1e293b; color: #ffffff; font-weight: 700;">
                    <span class="nav-item-icon">
                        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </span>
                    <span>All Settings</span>
                </a>
            </div>

            <nav class="sidebar-nav">
                <!-- Section: BUSINESS SETTINGS -->
                <div class="drawer-section-title">BUSINESS SETTINGS</div>

                <!-- Business Group -->
                <div class="nav-group <?= in_array($currentPage, ['settings.php', 'business-profile.php', 'outlets.php'], true) ? 'open' : '' ?>" id="grp-set-business">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-business')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            </span>
                            <span>Business</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('business-profile.php') ?>" class="submenu-link <?= $currentPage === 'business-profile.php' ? 'active' : '' ?>">Organization Profile</a>
                        <a href="<?= asset('outlets.php') ?>" class="submenu-link <?= $currentPage === 'outlets.php' ? 'active' : '' ?>">Stores & Outlets</a>
                        <a href="<?= asset('settings.php') ?>" class="submenu-link">Currencies</a>
                    </div>
                </div>

                <!-- Users & Roles Group -->
                <div class="nav-group <?= in_array($currentPage, ['users.php', 'roles.php', 'role-create.php'], true) ? 'open' : '' ?>" id="grp-set-users">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-users')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            </span>
                            <span>Users & Roles</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('users.php') ?>" class="submenu-link <?= $currentPage === 'users.php' ? 'active' : '' ?>">Users</a>
                        <a href="<?= asset('roles.php') ?>" class="submenu-link <?= in_array($currentPage, ['roles.php', 'role-create.php'], true) ? 'active' : '' ?>">Roles</a>
                    </div>
                </div>

                <!-- Taxes & Compliance -->
                <div class="nav-group <?= in_array($currentPage, ['settings.php', 'taxes.php'], true) ? 'open' : '' ?>" id="grp-set-taxes">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-taxes')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"/></svg>
                            </span>
                            <span>Taxes & Compliance</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('taxes.php?tab=tax-rates') ?>" class="submenu-link <?= ($currentPage === 'taxes.php' && ($activeTab ?? '') === 'tax-rates') ? 'active' : '' ?>">Tax Rates</a>
                        <a href="<?= asset('taxes.php?tab=gst-settings') ?>" class="submenu-link <?= ($currentPage === 'taxes.php' && ($activeTab ?? '') === 'gst-settings') ? 'active' : '' ?>">GST Settings</a>
                    </div>
                </div>

                <!-- Customization -->
                <div class="nav-group" id="grp-set-custom">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-custom')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                            </span>
                            <span>Customization</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('settings.php') ?>" class="submenu-link">Transaction Number Series</a>
                        <a href="<?= asset('invoices.php') ?>" class="submenu-link">PDF Templates</a>
                    </div>
                </div>

                <!-- Notifications -->
                <div class="nav-group" id="grp-set-notif">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-notif')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            </span>
                            <span>Notifications</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('settings.php') ?>" class="submenu-link">Emails</a>
                        <a href="<?= asset('integrations-whatsapp.php') ?>" class="submenu-link">SMS & WhatsApp</a>
                    </div>
                </div>

                <!-- Section: MODULE SETTINGS -->
                <div class="drawer-section-title">MODULE SETTINGS</div>

                <!-- General -->
                <div class="nav-group" id="grp-set-gen">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-gen')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            </span>
                            <span>General</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('customers.php') ?>" class="submenu-link">Customers and Vendors</a>
                        <a href="<?= asset('products.php') ?>" class="submenu-link">Items</a>
                    </div>
                </div>

                <!-- Payment Integrations -->
                <div class="nav-group <?= in_array($currentPage, ['payment-integrations.php', 'customer-payments.php'], true) ? 'open' : '' ?>" id="grp-set-pay">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-pay')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            </span>
                            <span>Payment Integrations</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('payment-integrations.php') ?>" class="submenu-link <?= in_array($currentPage, ['payment-integrations.php', 'customer-payments.php'], true) ? 'active' : '' ?>">Customer Payments</a>
                    </div>
                </div>

                <!-- Inventory -->
                <div class="nav-group" id="grp-set-inv">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-inv')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            </span>
                            <span>Inventory</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('categories.php') ?>" class="submenu-link">Units of Measurement</a>
                        <a href="<?= asset('stock-count.php') ?>" class="submenu-link">Adjustments</a>
                    </div>
                </div>

                <!-- Sales -->
                <div class="nav-group" id="grp-set-sales">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-sales')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            </span>
                            <span>Sales</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('orders.php') ?>" class="submenu-link">Orders</a>
                        <a href="<?= asset('invoices.php') ?>" class="submenu-link">Invoices</a>
                        <a href="<?= asset('invoices.php') ?>" class="submenu-link">Payments Received</a>
                        <a href="<?= asset('fulfillment.php') ?>" class="submenu-link">Packages</a>
                        <a href="<?= asset('fulfillment.php') ?>" class="submenu-link">Shipments</a>
                        <a href="<?= asset('consignment-manifest.php') ?>" class="submenu-link">Consignment &amp; COD Label Manifest</a>
                        <a href="<?= asset('returns.php') ?>" class="submenu-link">Returns</a>
                        <a href="<?= asset('returns.php') ?>" class="submenu-link">Credit Notes</a>
                        <a href="<?= asset('fulfillment.php') ?>" class="submenu-link">Delivery Challans</a>
                    </div>
                </div>

                <!-- Purchases -->
                <div class="nav-group" id="grp-set-purch">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-purch')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                            </span>
                            <span>Purchases</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('purchases.php') ?>" class="submenu-link">Purchase Orders</a>
                        <a href="<?= asset('purchases.php') ?>" class="submenu-link">Bills</a>
                        <a href="<?= asset('purchases.php') ?>" class="submenu-link">Payments Made</a>
                        <a href="<?= asset('purchase-returns.php') ?>" class="submenu-link">Vendor Credits</a>
                    </div>
                </div>

                <!-- Custom Modules -->
                <div class="nav-group" id="grp-set-cmod">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-cmod')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                            </span>
                            <span>Custom Modules</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('reports.php') ?>" class="submenu-link">Overview</a>
                    </div>
                </div>

                <!-- Section: EXTENSION & DEVELOPER DATA -->
                <div class="drawer-section-title">EXTENSION & DEVELOPER DATA</div>

                <!-- Integrations -->
                <div class="nav-group <?= in_array($currentPage, ['integrations-whatsapp.php', 'integrations-shipping.php', 'integrations-cart.php', 'online-store.php'], true) ? 'open' : '' ?>" id="grp-set-integ">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-integ')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </span>
                            <span>Integrations</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('integrations-shipping.php') ?>" class="submenu-link <?= $currentPage === 'integrations-shipping.php' ? 'active' : '' ?>">Shipping</a>
                        <a href="<?= asset('integrations-cart.php') ?>" class="submenu-link <?= $currentPage === 'integrations-cart.php' ? 'active' : '' ?>">Shopping Cart</a>
                        <a href="<?= asset('online-store.php') ?>" class="submenu-link <?= $currentPage === 'online-store.php' ? 'active' : '' ?>">Mobile Store Overview</a>
                        <a href="<?= asset('reports.php') ?>" class="submenu-link">Accounting</a>
                        <a href="https://wa.me/919243747854" target="_blank" class="submenu-link">SMS Integrations</a>
                        <a href="<?= asset('integrations-whatsapp.php') ?>" class="submenu-link <?= $currentPage === 'integrations-whatsapp.php' ? 'active' : '' ?>">WhatsApp</a>
                    </div>
                </div>

                <!-- Developer Space -->
                <div class="nav-group" id="grp-set-dev">
                    <div class="nav-group-header" onclick="toggleSidebarGroup('grp-set-dev')">
                        <div class="nav-group-title">
                            <span class="nav-item-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                            </span>
                            <span>Developer Space</span>
                        </div>
                        <span class="nav-group-arrow">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                    <div class="nav-submenu">
                        <a href="<?= asset('settings.php') ?>" class="submenu-link">Connections</a>
                        <a href="<?= asset('settings.php') ?>" class="submenu-link">API Usage</a>
                        <a href="<?= asset('settings.php') ?>" class="submenu-link">Incoming Webhooks</a>
                        <a href="<?= asset('dashboard.php') ?>" class="submenu-link">Web Tabs</a>
                        <a href="<?= asset('invoice-create.php') ?>" class="submenu-link">Web Forms</a>
                    </div>
                </div>
            </nav>
        </div>

        <!-- Bottom Plan Status Widget -->
        <?php $isPremiumPlan = function_exists('is_premium_active') && is_premium_active(); ?>
        <div class="sidebar-plan-card">
            <div class="plan-card-title">
                <span><?= $isPremiumPlan ? 'OMINIFLOW PREMIUM' : 'FREE PLAN' ?></span>
                <?php if ($isPremiumPlan): ?>
                    <span style="background: #10b981; color: #fff; font-size: 8.5px; padding: 2px 5px; border-radius: 4px; font-weight: 800;">ACTIVE</span>
                <?php else: ?>
                    <span style="background: #f59e0b; color: #fff; font-size: 8.5px; padding: 2px 5px; border-radius: 4px; font-weight: 800;">LOCKED</span>
                <?php endif; ?>
            </div>
            <?php if (!$isPremiumPlan): ?>
                <a href="<?= asset('pricing.php') ?>" style="display:block;margin-top:8px;text-align:center;background:#2563eb;color:#fff;font-size:11px;font-weight:700;padding:6px 8px;border-radius:6px;text-decoration:none;">Upgrade to Premium</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================= FULL ZOHO POS USER PROFILE DRAWER ================= -->
    <div class="zoho-profile-drawer" id="sidebarProfileMenu" onclick="event.stopPropagation()">
        <div class="zpd-header">
            <div class="zpd-avatar">
                <?php if ($profileLogoUrl !== ''): ?>
                    <img src="<?= e($profileLogoUrl) ?>" alt="">
                <?php else: ?>
                    <?= e($bizInitials) ?>
                <?php endif; ?>
            </div>
            <div class="zpd-header-meta">
                <div class="zpd-name"><?= e($displayName) ?></div>
                <div class="zpd-uid">User ID: <?= e($userIdLabel) ?></div>
                <div class="zpd-email"><?= e($userEmail ?: 'info@ominiflow.com') ?></div>
                <div class="zpd-org-badge" onclick="window.location.href='<?= asset('business-profile.php') ?>'" title="Manage Business Profile">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    <span><?= e($businessName) ?></span>
                    <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.4" d="M6 9l6 6 6-6"/></svg>
                </div>
            </div>
        </div>

        <div class="zpd-body">
            <div class="zpd-card">
                <div class="zpd-card-heading">Subscription</div>
                <div class="zpd-sub-row">
                    <?php if ($isPremiumPlan): ?>
                        <div class="zpd-sub-text">You're currently on our Premium plan</div>
                        <a href="<?= asset('pricing.php') ?>" class="zpd-btn-outline">View plan</a>
                    <?php else: ?>
                        <div class="zpd-sub-text">You're currently on our Free plan</div>
                        <a href="<?= asset('pricing.php') ?>" class="zpd-btn-outline">Upgrade</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="zpd-card">
                <div class="zpd-card-heading">Get in touch</div>
                <div class="zpd-list">
                    <a href="mailto:info@ominiflow.com?subject=Question%20about%20OminiFlow%20POS" class="zpd-link-row">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.947L3 20l1.116-3.348A7.52 7.52 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        <span>Have questions? Ask away!</span>
                    </a>
                    <a href="mailto:info@ominiflow.com" class="zpd-link-row">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        <span>Email us at info@ominiflow.com</span>
                    </a>
                    <a href="mailto:info@ominiflow.com?subject=Feedback%20for%20OminiFlow%20POS" class="zpd-link-row">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>Share Feedback</span>
                    </a>
                </div>
            </div>

            <div class="zpd-card">
                <div class="zpd-card-heading">Explore more</div>
                <div class="zpd-list">
                    <a href="<?= asset('dashboard.php?tab=getting-started') ?>" class="zpd-link-row">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        <span>Resources</span>
                    </a>
                    <a href="<?= asset('dashboard.php?tab=getting-started') ?>" class="zpd-link-row">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                        <span>Whats New</span>
                    </a>
                    <a href="<?= asset('dashboard.php') ?>" class="zpd-link-row">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        <span>Webinars</span>
                    </a>
                    <a href="<?= asset('settings.php') ?>" class="zpd-link-row">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <span>User Community</span>
                    </a>
                    <a href="<?= asset('dashboard.php?tab=getting-started') ?>" class="zpd-link-row">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span>Online Training Course</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="zpd-footer">
            <a href="<?= asset('business-profile.php') ?>" class="zpd-footer-link">
                <span>My Account</span>
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            </a>
            <a href="<?= asset('logout.php') ?>" class="zpd-footer-link logout">
                <span>Sign Out</span>
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.36 6.64a9 9 0 11-12.73 0M12 2v10"/></svg>
            </a>
        </div>
    </div>
</aside>

<!-- ================= ZOHO SPOTLIGHT GLOBAL SEARCH MODAL ================= -->
<div class="spotlight-overlay" id="spotlightOverlay" onclick="if(event.target === this) closeSpotlightSearch();">
    <div class="spotlight-box">
        <!-- Input Row -->
        <div class="spotlight-input-row">
            <select class="spotlight-scope-select" id="spotlightScope" onchange="onSpotlightScopeChange()">
                <option value="all">🔍 All Modules</option>
                <option value="customers">👤 Customers</option>
                <option value="products">📦 Items / Products</option>
                <option value="invoices">🧾 Invoices</option>
                <option value="orders">🛒 Orders</option>
                <option value="vendors">🛍️ Vendors</option>
            </select>
            <div class="spotlight-divider"></div>
            <input type="text" class="spotlight-input" id="spotlightInput" placeholder="Search across all modules..." autocomplete="off">
            <button type="button" class="spotlight-esc-btn" onclick="closeSpotlightSearch()" title="Close (ESC)">
                <span style="font-size: 14px; font-weight: 800;">&times;</span>
                <span style="font-size: 8.5px; font-weight: 700; text-transform: uppercase;">esc</span>
            </button>
        </div>

        <!-- Dynamic Results List -->
        <div class="spotlight-results" id="spotlightResults">
            <div class="spotlight-empty">
                Type keywords to search products, customers, invoices, orders or suppliers...
            </div>
        </div>

        <!-- Spotlight Footer Hints -->
        <div class="spotlight-footer">
            <span><strong>ProTip:</strong> Press <kbd style="background:#e2e8f0; padding:2px 5px; border-radius:4px; font-size:10px;">/</kbd> or <kbd style="background:#e2e8f0; padding:2px 5px; border-radius:4px; font-size:10px;">Ctrl+K</kbd> anywhere to open search</span>
            <span>ESC to dismiss</span>
        </div>
    </div>
</div>

<script>
// SPOTLIGHT SEARCH ENGINE
var spotlightSearchTimer = null;

function openSpotlightSearch(defaultScope) {
    var overlay = document.getElementById('spotlightOverlay');
    var inp = document.getElementById('spotlightInput');
    var scopeSel = document.getElementById('spotlightScope');
    if (!overlay || !inp) return;

    if (defaultScope && scopeSel) {
        scopeSel.value = defaultScope;
        onSpotlightScopeChange();
    }

    overlay.classList.add('open');
    setTimeout(function() {
        inp.focus();
        inp.select();
    }, 50);
}

function closeSpotlightSearch() {
    var overlay = document.getElementById('spotlightOverlay');
    if (overlay) overlay.classList.remove('open');
}

function onSpotlightScopeChange() {
    var scopeSel = document.getElementById('spotlightScope');
    var inp = document.getElementById('spotlightInput');
    if (!scopeSel || !inp) return;

    var scope = scopeSel.value;
    var placeholders = {
        'all': 'Search across all modules...',
        'customers': 'Search in Customers (Name, Phone, Email)...',
        'products': 'Search in Products / Items (Name, SKU, Barcode)...',
        'invoices': 'Search in Tax Invoices (Invoice #)...',
        'orders': 'Search in Orders (Order #)...',
        'vendors': 'Search in Vendors (Name, Company, GSTIN)...'
    };
    inp.placeholder = placeholders[scope] || 'Search...';
    performSpotlightSearch(inp.value.trim());
}

function performSpotlightSearch(query) {
    var resultsBox = document.getElementById('spotlightResults');
    var scopeSel = document.getElementById('spotlightScope');
    if (!resultsBox) return;

    if (!query || query.length < 1) {
        resultsBox.innerHTML = '<div class="spotlight-empty">Type keywords to search products, customers, invoices, orders or suppliers...</div>';
        return;
    }

    resultsBox.innerHTML = '<div class="spotlight-empty" style="color: #3b82f6;">Searching...</div>';

    var scope = scopeSel ? scopeSel.value : 'all';
    fetch('<?= asset('api/global_search.php') ?>?q=' + encodeURIComponent(query) + '&scope=' + encodeURIComponent(scope))
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.results || Object.keys(data.results).length === 0) {
                resultsBox.innerHTML = '<div class="spotlight-empty">No results found for "<strong>' + escapeHtml(query) + '</strong>".</div>';
                return;
            }

            var html = '';
            for (var groupName in data.results) {
                var groupItems = data.results[groupName];
                html += '<div class="spotlight-group-title">' + escapeHtml(groupName) + ' (' + groupItems.length + ')</div>';
                groupItems.forEach(function(item) {
                    html += `
                        <a href="${item.url}" class="spotlight-item">
                            <div>
                                <div class="spotlight-item-title">${escapeHtml(item.title)}</div>
                                <div class="spotlight-item-sub">${escapeHtml(item.subtitle)}</div>
                            </div>
                            <span class="spotlight-item-badge">${escapeHtml(item.badge)}</span>
                        </a>
                    `;
                });
            }
            resultsBox.innerHTML = html;
        })
        .catch(err => {
            resultsBox.innerHTML = '<div class="spotlight-empty" style="color:#ef4444;">Search error. Please try again.</div>';
        });
}

function escapeHtml(str) {
    return (str + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Global Keydown Listeners for / and Ctrl+K
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSpotlightSearch();
    } else if ((e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') ||
               (e.key === 'k' && (e.ctrlKey || e.metaKey))) {
        e.preventDefault();
        openSpotlightSearch();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    var inp = document.getElementById('spotlightInput');
    if (inp) {
        inp.addEventListener('input', function() {
            clearTimeout(spotlightSearchTimer);
            var val = this.value.trim();
            spotlightSearchTimer = setTimeout(function() {
                performSpotlightSearch(val);
            }, 120);
        });
    }
});

// SIDEBAR INTERACTIONS
function onRailHover(tabId) {
    switchRailTab(tabId);
    var sb = document.getElementById('appSidebar');
    if (sb) sb.classList.add('sidebar-hover-open');
}

function switchRailTab(tabId) {
    document.querySelectorAll('.rail-tab-btn, .rail-icon-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.drawer-tab-content').forEach(d => d.classList.remove('active'));

    var btn = document.getElementById('rail-btn-' + tabId);
    var drawer = document.getElementById('drawer-tab-' + tabId);

    if (btn) btn.classList.add('active');
    if (drawer) drawer.classList.add('active');

    sessionStorage.setItem('active_rail_tab', tabId);
}

function togglePinSidebar() {
    var sb = document.getElementById('appSidebar');
    if (!sb) return;
    sb.classList.toggle('sidebar-pinned');
    var isPinned = sb.classList.contains('sidebar-pinned');
    localStorage.setItem('ominiflow_sidebar_pinned', isPinned ? '1' : '0');
}

// ACCORDION TOGGLE
function toggleSidebarGroup(groupId) {
    var group = document.getElementById(groupId);
    if (!group) return;

    var willOpen = !group.classList.contains('open');

    // Close other groups in the same drawer
    var parentNav = group.closest('.sidebar-nav');
    if (parentNav) {
        parentNav.querySelectorAll('.nav-group').forEach(function(otherGroup) {
            if (otherGroup !== group) {
                otherGroup.classList.remove('open');
                sessionStorage.setItem('sidebar_group_' + otherGroup.id, '0');
            }
        });
    }

    if (willOpen) {
        group.classList.add('open');
        sessionStorage.setItem('sidebar_group_' + groupId, '1');
    } else {
        group.classList.remove('open');
        sessionStorage.setItem('sidebar_group_' + groupId, '0');
    }
}

function filterReportsNav(query) {
    var q = (query || '').toLowerCase().trim();
    document.querySelectorAll('#drawer-tab-reports .nav-item').forEach(function(item) {
        var txt = item.textContent.toLowerCase();
        item.style.display = (txt.indexOf(q) !== -1) ? 'flex' : 'none';
    });
}

function filterSettingsNav(query) {
    var q = (query || '').toLowerCase().trim();
    document.querySelectorAll('#drawer-tab-settings .nav-group').forEach(function(group) {
        var txt = group.textContent.toLowerCase();
        group.style.display = (txt.indexOf(q) !== -1) ? '' : 'none';
        if (q && txt.indexOf(q) !== -1) {
            group.classList.add('open');
        }
    });
}

(function() {
    function initSidebar() {
        var sb = document.getElementById('appSidebar');
        if (!sb) return;

        // Restore pinned state
        var isPinned = localStorage.getItem('ominiflow_sidebar_pinned');
        if (isPinned === '1') {
            sb.classList.add('sidebar-pinned');
        } else {
            sb.classList.remove('sidebar-pinned');
        }

        // Close hover drawer when mouse leaves sidebar
        sb.addEventListener('mouseleave', function() {
            if (!sb.classList.contains('sidebar-pinned')) {
                sb.classList.remove('sidebar-hover-open');
            }
        });

        // Restore active rail tab
        var savedRail = sessionStorage.getItem('active_rail_tab');
        if (savedRail && ['business', 'channels', 'reports', 'settings'].indexOf(savedRail) !== -1) {
            var path = window.location.pathname;
            if (path.indexOf('pos.php') === -1 && path.indexOf('registers.php') === -1 && path.indexOf('reports.php') === -1 && path.indexOf('settings.php') === -1 && path.indexOf('integrations-whatsapp.php') === -1 && path.indexOf('taxes.php') === -1 && path.indexOf('business-profile.php') === -1 && path.indexOf('online-store.php') === -1) {
                switchRailTab(savedRail);
            }
        }

        // Ensure only the active page group is open by default
        var activeSubmenu = document.querySelector('.drawer-tab-content.active .submenu-link.active');
        if (activeSubmenu) {
            var activeParentGroup = activeSubmenu.closest('.nav-group');
            document.querySelectorAll('.drawer-tab-content.active .nav-group').forEach(function(g) {
                if (g === activeParentGroup) {
                    g.classList.add('open');
                    sessionStorage.setItem('sidebar_group_' + g.id, '1');
                } else {
                    g.classList.remove('open');
                    sessionStorage.setItem('sidebar_group_' + g.id, '0');
                }
            });
        }

        // Restore scroll
        var activeDrawer = document.querySelector('.drawer-tab-content.active .sidebar-nav');
        if (activeDrawer) {
            var savedPos = sessionStorage.getItem('sidebar_scroll_pos');
            if (savedPos !== null) activeDrawer.scrollTop = parseInt(savedPos, 10);

            var activeLink = activeDrawer.querySelector('.submenu-link.active, .nav-item.active');
            if (activeLink) {
                var rect = activeLink.getBoundingClientRect();
                var navRect = activeDrawer.getBoundingClientRect();
                if (rect.top < navRect.top || rect.bottom > navRect.bottom) {
                    activeLink.scrollIntoView({ block: 'nearest', behavior: 'instant' });
                }
            }
        }
    }

    var profileHoverTimer = null;

    function openProfileMenu() {
        if (profileHoverTimer) clearTimeout(profileHoverTimer);
        var menu = document.getElementById('sidebarProfileMenu');
        if (menu) menu.classList.add('show');
    }

    function closeProfileMenuWithDelay() {
        if (profileHoverTimer) clearTimeout(profileHoverTimer);
        profileHoverTimer = setTimeout(function() {
            var menu = document.getElementById('sidebarProfileMenu');
            if (menu) menu.classList.remove('show');
        }, 400);
    }

    window.toggleSidebarProfileMenu = function(e) {
        if (e) e.stopPropagation();
        var menu = document.getElementById('sidebarProfileMenu');
        if (menu) {
            menu.classList.toggle('show');
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        var trigger = document.getElementById('sidebarProfileTrigger');
        var menu = document.getElementById('sidebarProfileMenu');

        if (trigger) {
            trigger.addEventListener('mouseenter', openProfileMenu);
            trigger.addEventListener('mouseleave', closeProfileMenuWithDelay);
        }

        if (menu) {
            menu.addEventListener('mouseenter', openProfileMenu);
            menu.addEventListener('mouseleave', closeProfileMenuWithDelay);
        }
    });

    document.addEventListener('click', function(e) {
        var menu = document.getElementById('sidebarProfileMenu');
        var trigger = document.getElementById('sidebarProfileTrigger');
        if (menu && menu.classList.contains('show')) {
            if (!menu.contains(e.target) && !trigger.contains(e.target)) {
                menu.classList.remove('show');
            }
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
</script>

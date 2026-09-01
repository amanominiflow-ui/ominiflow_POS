<?php
/**
 * OminiFlow POS - Products Catalog
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/products_db.php';
require_once __DIR__ . '/includes/import_export_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

$search = trim((string) ($_GET['q'] ?? ''));
$categoryId = !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null;
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$stockFilter = trim((string) ($_GET['stock'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? ''));
$viewMode = trim((string) ($_GET['view'] ?? 'list'));
if (!in_array($viewMode, ['list', 'grid'], true)) {
    $viewMode = 'list';
}

function catalog_filter_url(array $overrides = []): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return asset('products.php' . ($params ? '?' . http_build_query($params) : ''));
}

$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle Product Deletion, Quick Stock Adjustment, and Direct CSV Import
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please try again.');
        redirect(APP_URL . '/products.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['product_id'] ?? 0);
        $result = delete_product($id);
        if ($result['success']) {
            set_flash('success', 'Product deleted successfully!');
        } else {
            set_flash('error', $result['error'] ?? 'Could not delete product.');
        }
        redirect(APP_URL . '/products.php');
    } elseif ($action === 'quick_adjust_stock') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $movementType = (string) ($_POST['movement_type'] ?? 'in');
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $reason = (string) ($_POST['reason'] ?? 'Manual stock adjustment from catalog');

        $result = adjust_stock($productId, $userId, $movementType, $quantity, $reason);
        if ($result['success']) {
            set_flash('success', 'Stock adjusted successfully! New balance: ' . $result['stock_after'] . ' units.');
        } else {
            $msg = implode(' ', $result['errors']);
            set_flash('error', $msg);
        }
        redirect(APP_URL . '/products.php');
    } elseif ($action === 'import_products') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            set_flash('error', 'Please select a valid CSV file to upload.');
        } else {
            $res = import_products_from_csv($_FILES['csv_file']['tmp_name'], $userId);
            if ($res['success']) {
                if ($res['imported_count'] > 0) {
                    $warnTxt = !empty($res['errors']) ? ' (' . count($res['errors']) . ' warning(s): ' . implode('; ', array_slice($res['errors'], 0, 2)) . ')' : '';
                    set_flash('success', "Import completed successfully: {$res['imported_count']} product(s) processed{$warnTxt}.");
                } elseif (!empty($res['errors'])) {
                    set_flash('error', 'CSV Import failed: ' . implode('; ', array_slice($res['errors'], 0, 3)));
                } else {
                    set_flash('error', 'No valid product rows found in uploaded CSV file.');
                }
            } else {
                set_flash('error', $res['error'] ?? 'Import failed.');
            }
        }
        redirect(APP_URL . '/products.php');
    }
}

$categories = get_categories();
$products = get_products($search, $categoryId, $statusFilter, $stockFilter, null, $sort);
$inventoryStats = get_inventory_stats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products Catalog - OminiFlow POS</title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>?v=<?= time() ?>">
    <style>
        /* Top Header Row alignment */
        .page-header-row {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            margin-bottom: 24px !important;
            gap: 16px !important;
            flex-wrap: wrap !important;
        }

        .catalog-toolbar-group {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            margin-left: auto !important;
        }

        .view-mode-toggle {
            display: inline-flex !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            background: #ffffff !important;
            overflow: hidden !important;
        }

        .view-mode-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 36px !important;
            height: 36px !important;
            border: none !important;
            background: transparent !important;
            color: #64748b !important;
            cursor: pointer !important;
            text-decoration: none !important;
            transition: all 0.15s ease !important;
        }

        .view-mode-btn:hover {
            background: #f1f5f9 !important;
            color: #1e293b !important;
        }

        .view-mode-btn.active {
            background: #eff6ff !important;
            color: #2563eb !important;
        }

        .view-mode-btn + .view-mode-btn {
            border-left: 1px solid #cbd5e1 !important;
        }

        .btn-more-dots {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 36px !important;
            height: 36px !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            background: #ffffff !important;
            color: #334155 !important;
            cursor: pointer !important;
            font-size: 16px !important;
            font-weight: 700 !important;
            transition: all 0.15s ease !important;
        }

        .btn-more-dots:hover {
            background: #f8fafc !important;
            border-color: #94a3b8 !important;
            color: #0f172a !important;
        }

        .catalog-more-dropdown-wrap {
            position: relative !important;
            display: inline-block !important;
        }

        .catalog-more-menu {
            display: none !important;
            position: absolute !important;
            right: 0 !important;
            top: calc(100% + 6px) !important;
            width: 230px !important;
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
            z-index: 9999 !important;
            padding: 6px 0 !important;
            animation: spFadeIn 0.15s ease !important;
        }

        .catalog-more-menu.show {
            display: block !important;
        }

        .catalog-menu-item {
            position: relative !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            padding: 9px 16px !important;
            font-size: 13px !important;
            font-weight: 500 !important;
            color: #334155 !important;
            text-decoration: none !important;
            cursor: pointer !important;
            transition: background 0.12s ease !important;
        }

        .catalog-menu-item:hover {
            background: #f8fafc !important;
            color: #0f172a !important;
        }

        .catalog-menu-item.highlight-blue {
            background: #2563eb !important;
            color: #ffffff !important;
            font-weight: 600 !important;
            border-radius: 6px 6px 0 0 !important;
            margin: -6px 0 4px 0 !important;
            padding: 10px 16px !important;
        }

        .catalog-menu-item.highlight-blue:hover {
            background: #1d4ed8 !important;
            color: #ffffff !important;
        }

        .catalog-menu-item-left {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
        }

        .catalog-menu-item-left svg {
            flex-shrink: 0 !important;
        }

        .catalog-menu-divider {
            height: 1px !important;
            background: #f1f5f9 !important;
            margin: 4px 0 !important;
        }

        /* Submenu flyout */
        .catalog-menu-item:hover > .catalog-submenu {
            display: block !important;
        }

        .catalog-submenu {
            display: none !important;
            position: absolute !important;
            right: 100% !important;
            top: 0 !important;
            width: 200px !important;
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
            z-index: 10000 !important;
            padding: 6px 0 !important;
            margin-right: 4px !important;
        }

        .catalog-submenu a {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            padding: 8px 14px !important;
            font-size: 12.5px !important;
            font-weight: 500 !important;
            color: #334155 !important;
            text-decoration: none !important;
            transition: background 0.12s ease !important;
        }

        .catalog-submenu a:hover {
            background: #f1f5f9 !important;
            color: #2563eb !important;
        }

        .catalog-submenu a.is-active {
            color: #2563eb !important;
            font-weight: 700 !important;
            background: #eff6ff !important;
        }

        /* Grid View Layout */
        .products-grid {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)) !important;
            gap: 20px !important;
            padding: 20px !important;
        }

        .product-card-grid {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 10px !important;
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column !important;
            transition: transform 0.15s ease, box-shadow 0.15s ease !important;
        }

        .product-card-grid:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.08) !important;
            border-color: #cbd5e1 !important;
        }

        .product-card-img-wrap {
            height: 160px !important;
            background: #f8fafc !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            position: relative !important;
            border-bottom: 1px solid #f1f5f9 !important;
        }

        .product-card-img-wrap img {
            max-height: 100% !important;
            max-width: 100% !important;
            object-fit: contain !important;
        }

        .product-card-body {
            padding: 14px !important;
            display: flex !important;
            flex-direction: column !important;
            flex: 1 !important;
        }

        .product-card-title {
            font-size: 14px !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            margin-bottom: 4px !important;
            line-height: 1.3 !important;
        }

        .product-card-sku {
            font-size: 11.5px !important;
            color: #64748b !important;
            margin-bottom: 10px !important;
        }

        .product-card-price-row {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            margin-top: auto !important;
            padding-top: 10px !important;
            border-top: 1px solid #f1f5f9 !important;
        }

        .product-card-price {
            font-size: 15px !important;
            font-weight: 800 !important;
            color: #0f172a !important;
        }

        .product-card-actions {
            display: flex !important;
            gap: 6px !important;
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <!-- Sidebar Component -->
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="app-main">
            <!-- Header Component -->
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <!-- Page Top Row -->
                <div class="page-header-row">
                    <div>
                        <h1 class="page-title">Products Catalog</h1>
                        <p class="page-subtitle">Manage retail items, pricing, SKU barcodes, and live inventory</p>
                    </div>
                    <div class="catalog-toolbar-group">
                        <a href="<?= asset('categories.php') ?>" class="btn-secondary" style="height:36px;padding:0 14px;">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            <span>Categories</span>
                        </a>

                        <!-- View Mode Switcher (List / Grid) -->
                        <div class="view-mode-toggle">
                            <a href="<?= e(catalog_filter_url(['view' => 'list'])) ?>" class="view-mode-btn <?= $viewMode === 'list' ? 'active' : '' ?>" title="List View">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            </a>
                            <a href="<?= e(catalog_filter_url(['view' => 'grid'])) ?>" class="view-mode-btn <?= $viewMode === 'grid' ? 'active' : '' ?>" title="Grid View">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            </a>
                        </div>

                        <!-- Add Product Button -->
                        <a href="<?= asset('product-create.php') ?>" class="header-btn" style="height:36px;padding:0 16px;">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>Add Product</span>
                        </a>

                        <!-- More Actions Dropdown (...) -->
                        <div class="catalog-more-dropdown-wrap">
                            <button type="button" class="btn-more-dots" id="catalogMoreBtn" title="More Options" aria-haspopup="true" aria-expanded="false">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                    <circle cx="5" cy="12" r="2.2"/>
                                    <circle cx="12" cy="12" r="2.2"/>
                                    <circle cx="19" cy="12" r="2.2"/>
                                </svg>
                            </button>
                            <div class="catalog-more-menu" id="catalogMoreMenu">
                                <!-- Sort by -->
                                <div class="catalog-menu-item highlight-blue">
                                    <div class="catalog-menu-item-left">
                                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                                        <span>Sort by</span>
                                    </div>
                                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    <div class="catalog-submenu">
                                        <a href="<?= e(catalog_filter_url(['sort' => 'name_asc'])) ?>" class="<?= $sort === 'name_asc' ? 'is-active' : '' ?>">Name (A to Z)</a>
                                        <a href="<?= e(catalog_filter_url(['sort' => 'name_desc'])) ?>" class="<?= $sort === 'name_desc' ? 'is-active' : '' ?>">Name (Z to A)</a>
                                        <a href="<?= e(catalog_filter_url(['sort' => 'price_asc'])) ?>" class="<?= $sort === 'price_asc' ? 'is-active' : '' ?>">Price (Low to High)</a>
                                        <a href="<?= e(catalog_filter_url(['sort' => 'price_desc'])) ?>" class="<?= $sort === 'price_desc' ? 'is-active' : '' ?>">Price (High to Low)</a>
                                        <a href="<?= e(catalog_filter_url(['sort' => 'stock_desc'])) ?>" class="<?= $sort === 'stock_desc' ? 'is-active' : '' ?>">Stock (High to Low)</a>
                                        <a href="<?= e(catalog_filter_url(['sort' => 'created_asc'])) ?>" class="<?= $sort === 'created_asc' ? 'is-active' : '' ?>">Oldest Added</a>
                                        <a href="<?= e(catalog_filter_url(['sort' => ''])) ?>" class="<?= empty($sort) ? 'is-active' : '' ?>">Newest Added</a>
                                    </div>
                                </div>

                                <!-- Import -->
                                <div class="catalog-menu-item">
                                    <div class="catalog-menu-item-left">
                                        <svg width="16" height="16" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        <span>Import</span>
                                    </div>
                                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    <div class="catalog-submenu">
                                        <a href="javascript:void(0)" onclick="openImportModal();">📥 Import Products (CSV)</a>
                                        <a href="<?= asset('import-export.php') ?>">📊 Import & Export Hub</a>
                                    </div>
                                </div>

                                <!-- Export -->
                                <div class="catalog-menu-item">
                                    <div class="catalog-menu-item-left">
                                        <svg width="16" height="16" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                        <span>Export</span>
                                    </div>
                                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    <div class="catalog-submenu">
                                        <a href="<?= asset('import-export.php?export=products') ?>">📤 Export Products (CSV)</a>
                                        <a href="<?= asset('import-export.php') ?>">📊 Export Hub</a>
                                    </div>
                                </div>

                                <div class="catalog-menu-divider"></div>

                                <!-- Preferences -->
                                <a href="<?= asset('settings.php') ?>" class="catalog-menu-item">
                                    <div class="catalog-menu-item-left">
                                        <svg width="16" height="16" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                                        <span>Preferences</span>
                                    </div>
                                </a>

                                <!-- Refresh List -->
                                <a href="<?= asset('products.php') ?>" class="catalog-menu-item">
                                    <div class="catalog-menu-item-left">
                                        <svg width="16" height="16" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        <span>Refresh List</span>
                                    </div>
                                </a>

                                <!-- Reset Column Width -->
                                <a href="javascript:void(0);" onclick="location.reload();" class="catalog-menu-item">
                                    <div class="catalog-menu-item-left">
                                        <svg width="16" height="16" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M1 4v6h6M23 20v-6h-6M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                                        <span>Reset Column Width</span>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Tab Navigation between Products, Categories, and Inventory -->
                <div class="tab-row">
                    <a href="<?= asset('products.php') ?>" class="tab-btn active">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <span>All Products (<?= count($products) ?>)</span>
                    </a>
                    <a href="<?= asset('categories.php') ?>" class="tab-btn">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        <span>Categories</span>
                    </a>
                    <a href="<?= asset('inventory.php') ?>" class="tab-btn">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                        </svg>
                        <span>Inventory & Stock Log (<?= $inventoryStats['total_stock_units'] ?> units)</span>
                    </a>
                </div>

                <!-- Filter Bar -->
                <div class="filter-card">
                    <form method="GET" action="<?= asset('products.php') ?>" class="filter-form">
                        <div class="search-input-wrap">
                            <span class="search-icon">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </span>
                            <input
                                type="text"
                                name="q"
                                value="<?= e($search) ?>"
                                placeholder="Search by name, SKU, or barcode..."
                                class="form-control with-icon"
                            >
                        </div>

                        <select name="category_id" class="form-control filter-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="stock" class="form-control filter-select">
                            <option value="">All Stock Levels</option>
                            <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                            <option value="low_stock" <?= $stockFilter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                            <option value="out_of_stock" <?= $stockFilter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                        </select>

                        <select name="status" class="form-control filter-select" style="min-width: 130px;">
                            <option value="">All Status</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>

                        <button type="submit" class="btn-filter-submit">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                            </svg>
                            <span>Filter</span>
                        </button>

                        <?php if ($search !== '' || $categoryId !== null || $statusFilter !== '' || $stockFilter !== ''): ?>
                            <a href="<?= asset('products.php') ?>" class="btn-filter-clear">Clear Filters</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Products Section (List View / Grid View) -->
                <div class="section-card">
                    <?php if (empty($products)): ?>
                        <div class="empty-state" style="padding: 48px 20px;">
                            <div class="empty-state-icon">📦</div>
                            <div style="font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">No products found</div>
                            <div>Add your first product to the catalog or refine your search filters.</div>
                            <div style="margin-top: 16px;">
                                <a href="<?= asset('product-create.php') ?>" class="header-btn" style="display: inline-flex;">+ Add New Product</a>
                            </div>
                        </div>
                    <?php elseif ($viewMode === 'grid'): ?>
                        <!-- Grid View Cards -->
                        <div class="products-grid">
                            <?php foreach ($products as $prod):
                                $stock = (int) $prod['stock_quantity'];
                                $threshold = (int) $prod['low_stock_threshold'];
                                $stockBadgeClass = 'badge-in-stock';
                                $stockLabel = $stock . ' in stock';
                                if ($stock <= 0) {
                                    $stockBadgeClass = 'badge-out-of-stock';
                                    $stockLabel = 'Out of stock';
                                } elseif ($stock <= $threshold) {
                                    $stockBadgeClass = 'badge-low-stock';
                                    $stockLabel = 'Low stock (' . $stock . ')';
                                }
                            ?>
                                <div class="product-card-grid">
                                    <div class="product-card-img-wrap">
                                        <?php if (!empty($prod['image_path'])): ?>
                                            <img src="<?= asset($prod['image_path']) ?>" alt="<?= e($prod['name']) ?>">
                                        <?php else: ?>
                                            <div style="font-size: 38px;">📦</div>
                                        <?php endif; ?>
                                        <span class="badge <?= $stockBadgeClass ?>" style="position: absolute; top: 10px; right: 10px;">
                                            <?= e($stockLabel) ?>
                                        </span>
                                    </div>
                                    <div class="product-card-body">
                                        <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                                            <span class="product-card-title"><?= e($prod['name']) ?></span>
                                            <?php if (($prod['product_type'] ?? 'simple') === 'variable'): ?>
                                                <span style="font-size:10px;font-weight:700;padding:1px 5px;border-radius:4px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;">Variants</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="product-card-sku">SKU: <?= e($prod['sku']) ?></div>

                                        <?php if (!empty($prod['category_name'])): ?>
                                            <div style="margin-bottom: 8px;">
                                                <span class="badge badge-info" style="font-size: 11px;"><?= e($prod['category_name']) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <div class="product-card-price-row">
                                            <div>
                                                <div style="font-size: 11px; color: #64748b;">Selling Price</div>
                                                <div class="product-card-price">₹<?= number_format((float)$prod['selling_price'], 2) ?></div>
                                            </div>
                                            <div class="product-card-actions">
                                                <button
                                                    type="button"
                                                    class="btn-action adjust open-adjust-modal-btn"
                                                    data-id="<?= $prod['id'] ?>"
                                                    data-name="<?= e($prod['name']) ?>"
                                                    data-sku="<?= e($prod['sku']) ?>"
                                                    data-stock="<?= (int)$prod['stock_quantity'] ?>"
                                                    title="Quick Stock Adjustment"
                                                >
                                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                                                </button>
                                                <a href="<?= asset('product-edit.php?id=' . $prod['id']) ?>" class="btn-action edit" title="Edit Product">
                                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <!-- Standard List View Table -->
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>SKU / Barcode</th>
                                        <th>Cost Price</th>
                                        <th>Selling Price</th>
                                        <th>Tax</th>
                                        <th>Stock Level</th>
                                        <th>Status</th>
                                        <th style="text-align: right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $prod):
                                        $stock = (int) $prod['stock_quantity'];
                                        $threshold = (int) $prod['low_stock_threshold'];
                                        $stockBadgeClass = 'badge-in-stock';
                                        $stockLabel = $stock . ' in stock';
                                        if ($stock <= 0) {
                                            $stockBadgeClass = 'badge-out-of-stock';
                                            $stockLabel = 'Out of stock (0)';
                                        } elseif ($stock <= $threshold) {
                                            $stockBadgeClass = 'badge-low-stock';
                                            $stockLabel = 'Low stock (' . $stock . ')';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="product-cell">
                                                    <?php if (!empty($prod['image_path'])): ?>
                                                        <img src="<?= asset($prod['image_path']) ?>" alt="<?= e($prod['name']) ?>" class="product-thumb">
                                                    <?php else: ?>
                                                        <div class="product-thumb-placeholder">📦</div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="product-title" style="display:flex;align-items:center;gap:6px;">
                                                            <span><?= e($prod['name']) ?></span>
                                                            <?php if (($prod['product_type'] ?? 'simple') === 'variable'): ?>
                                                                <span style="font-size:10.5px;font-weight:700;padding:1px 6px;border-radius:4px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;white-space:nowrap;">Variants</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="product-sku">SKU: <?= e($prod['sku']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($prod['category_name'])): ?>
                                                    <span class="badge badge-info"><?= e($prod['category_name']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: var(--saas-slate-400); font-size: 12px;">Uncategorized</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-family: monospace; font-size: 12px; color: var(--saas-navy-800);">
                                                    <?= e($prod['barcode'] ?: '—') ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span style="color: var(--saas-slate-500); font-size: 13px;">₹<?= number_format((float)$prod['cost_price'], 2) ?></span>
                                            </td>
                                            <td>
                                                <strong style="color: var(--saas-navy-950); font-size: 14px;">₹<?= number_format((float)$prod['selling_price'], 2) ?></strong>
                                            </td>
                                            <td>
                                                <span style="font-size: 12.5px; color: var(--saas-slate-600);"><?= number_format((float)$prod['tax_percent'], 1) ?>%</span>
                                            </td>
                                            <td>
                                                <span class="badge <?= $stockBadgeClass ?>">
                                                    <?= e($stockLabel) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($prod['status'] === 'active'): ?>
                                                    <span class="badge badge-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <div class="action-group" style="justify-content: flex-end;">
                                                    <button
                                                        type="button"
                                                        class="btn-action adjust open-adjust-modal-btn"
                                                        data-id="<?= $prod['id'] ?>"
                                                        data-name="<?= e($prod['name']) ?>"
                                                        data-sku="<?= e($prod['sku']) ?>"
                                                        data-stock="<?= (int)$prod['stock_quantity'] ?>"
                                                        title="Quick Stock Adjustment"
                                                    >
                                                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/>
                                                        </svg>
                                                    </button>

                                                    <a href="<?= asset('product-edit.php?id=' . $prod['id']) ?>" class="btn-action edit" title="Edit Product">
                                                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                        </svg>
                                                    </a>

                                                    <form method="POST" action="<?= asset('products.php') ?>" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete product \'<?= e(addslashes($prod['name'])) ?>\'?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                                        <button type="submit" class="btn-action delete" title="Delete Product">
                                                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Quick Stock Adjustment Modal -->
    <div class="modal-overlay" id="quickStockModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title">Adjust Product Stock</h3>
                <button type="button" class="modal-close-btn" id="closeQuickStockModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('products.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="quick_adjust_stock">
                <input type="hidden" name="product_id" id="modalStockProductId" value="">

                <div class="modal-body">
                    <div style="background: var(--saas-surface); padding: 12px 14px; border-radius: var(--saas-radius-md); border: 1px solid var(--saas-border-light); margin-bottom: 16px;">
                        <div style="font-weight: 700; font-size: 14px; color: var(--saas-navy-950);" id="modalStockProductName">Product Name</div>
                        <div style="font-size: 12px; color: var(--saas-slate-500); margin-top: 2px;">
                            SKU: <span id="modalStockProductSku" style="font-family: monospace; font-weight: 600;"></span> • Current Stock: <strong id="modalStockCurrentQty" style="color: var(--saas-primary);">0</strong> units
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="modalMovementType" class="form-label">Action Type <span style="color: #ef4444;">*</span></label>
                        <select id="modalMovementType" name="movement_type" class="form-control" required>
                            <option value="in">➕ Stock In (Restock / Purchase Receive)</option>
                            <option value="out">➖ Stock Out (Damage / Loss / Return)</option>
                            <option value="adjustment">🔄 Set Exact Physical Count</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="modalQuantity" class="form-label" id="modalQtyLabel">Quantity to Add <span style="color: #ef4444;">*</span></label>
                        <input type="number" id="modalQuantity" name="quantity" min="1" value="1" required class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="modalStockReason" class="form-label">Reason / Reference Note <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="modalStockReason" name="reason" required placeholder="e.g. New stock delivery, Store restock, Inventory audit" class="form-control">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelQuickStockModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0;">Save Movement</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick CSV Import Modal -->
    <div class="modal-overlay" id="importProductsModal">
        <div class="modal-box" style="max-width: 520px;">
            <div class="modal-header">
                <h3 class="modal-title" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span>Import Products (CSV)</span>
                </h3>
                <button type="button" class="modal-close-btn" onclick="closeImportModal();">&times;</button>
            </div>
            <form method="POST" action="<?= asset('products.php') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_products">

                <div class="modal-body">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                        <span style="font-size: 13px; color: var(--saas-slate-600);">Upload a CSV spreadsheet with your catalog items.</span>
                        <a href="<?= asset('import-export.php?export=sample_template') ?>" style="font-size: 12px; color: var(--saas-primary); font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                            <span>📥 Sample Template</span>
                        </a>
                    </div>
                    <p style="font-size: 12.5px; color: var(--saas-slate-600); margin-bottom: 14px; line-height: 1.4;">
                        Supported headers:<br>
                        <code style="background: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-size: 11.5px; display: inline-block; margin-top: 4px; color: #0f172a;">Name, SKU, Barcode, Category, Selling Price, Cost Price, Tax Percent, Stock</code>
                    </p>

                    <div style="border: 2px dashed #cbd5e1; border-radius: 8px; padding: 24px 16px; text-align: center; background: #f8fafc; margin-bottom: 10px;">
                        <svg width="34" height="34" fill="none" stroke="#94a3b8" viewBox="0 0 24 24" style="margin: 0 auto 8px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        <div style="font-weight: 600; font-size: 13px; color: #0f172a; margin-bottom: 4px;">Choose CSV file from your device</div>
                        <input type="file" name="csv_file" accept=".csv" required style="font-size: 12px; margin-top: 8px;">
                    </div>
                </div>

                <div class="modal-footer" style="justify-content: space-between;">
                    <a href="<?= asset('import-export.php?export=sample_template') ?>" style="font-size: 12.5px; color: var(--saas-primary); font-weight: 600; text-decoration: none;">
                        Download Sample CSV &darr;
                    </a>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-secondary" onclick="closeImportModal();">Cancel</button>
                        <button type="submit" class="header-btn" style="border: 0;">Upload & Import</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        // More Actions Dropdown Toggle
        const moreBtn = document.getElementById('catalogMoreBtn');
        const moreMenu = document.getElementById('catalogMoreMenu');
        if (moreBtn && moreMenu) {
            moreBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                moreMenu.classList.toggle('show');
            });
            document.addEventListener('click', function (e) {
                if (!moreMenu.contains(e.target) && e.target !== moreBtn) {
                    moreMenu.classList.remove('show');
                }
            });
        }

        // Import Modal Open/Close
        function openImportModal() {
            if (moreMenu) moreMenu.classList.remove('show');
            const m = document.getElementById('importProductsModal');
            if (m) m.classList.add('open');
        }
        function closeImportModal() {
            const m = document.getElementById('importProductsModal');
            if (m) m.classList.remove('open');
        }

        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('quickStockModal');
            const closeBtn = document.getElementById('closeQuickStockModal');
            const cancelBtn = document.getElementById('cancelQuickStockModal');
            const idInput = document.getElementById('modalStockProductId');
            const nameEl = document.getElementById('modalStockProductName');
            const skuEl = document.getElementById('modalStockProductSku');
            const currQtyEl = document.getElementById('modalStockCurrentQty');
            const typeSelect = document.getElementById('modalMovementType');
            const qtyInput = document.getElementById('modalQuantity');
            const qtyLabel = document.getElementById('modalQtyLabel');

            function updateQtyLabel() {
                const val = typeSelect.value;
                if (val === 'in') {
                    qtyLabel.innerHTML = 'Quantity to Add <span style="color: #ef4444;">*</span>';
                    qtyInput.min = '1';
                } else if (val === 'out') {
                    qtyLabel.innerHTML = 'Quantity to Remove <span style="color: #ef4444;">*</span>';
                    qtyInput.min = '1';
                } else {
                    qtyLabel.innerHTML = 'New Exact Total Stock <span style="color: #ef4444;">*</span>';
                    qtyInput.min = '0';
                }
            }

            typeSelect.addEventListener('change', updateQtyLabel);

            function openModal(prod) {
                idInput.value = prod.id;
                nameEl.textContent = prod.name;
                skuEl.textContent = prod.sku;
                currQtyEl.textContent = prod.stock;
                typeSelect.value = 'in';
                qtyInput.value = '1';
                updateQtyLabel();
                modal.classList.add('open');
                qtyInput.focus();
            }

            function closeModal() {
                modal.classList.remove('open');
            }

            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });

            const importModal = document.getElementById('importProductsModal');
            if (importModal) {
                importModal.addEventListener('click', function (e) {
                    if (e.target === importModal) closeImportModal();
                });
            }

            document.querySelectorAll('.open-adjust-modal-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const prod = {
                        id: this.getAttribute('data-id'),
                        name: this.getAttribute('data-name'),
                        sku: this.getAttribute('data-sku'),
                        stock: this.getAttribute('data-stock'),
                    };
                    openModal(prod);
                });
            });
        });
    </script>
</body>
</html>

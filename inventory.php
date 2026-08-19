<?php
/**
 * OminiFlow POS - Inventory & Stock Management
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/products_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

$activeTab = trim((string) ($_GET['tab'] ?? 'stock'));
$search = trim((string) ($_GET['q'] ?? ''));
$stockFilter = trim((string) ($_GET['stock'] ?? ''));

$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle Stock Adjustment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please try again.');
        redirect(APP_URL . '/inventory.php?tab=' . urlencode($activeTab));
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'adjust_stock') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $movementType = (string) ($_POST['movement_type'] ?? 'in');
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $reason = (string) ($_POST['reason'] ?? '');

        $result = adjust_stock($productId, $userId, $movementType, $quantity, $reason);

        if ($result['success']) {
            set_flash('success', 'Stock adjustment recorded successfully! New stock balance: ' . $result['stock_after'] . ' units.');
        } else {
            $msg = implode(' ', $result['errors']);
            set_flash('error', $msg);
        }
        redirect(APP_URL . '/inventory.php?tab=' . urlencode($activeTab));
    }
}

$inventoryStats = get_inventory_stats();
$products = get_products($search, null, '', $stockFilter);
$movements = get_inventory_movements(null, 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - OminiFlow POS</title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
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
                <!-- Top Breadcrumb/Title Row -->
                <div class="page-header-row">
                    <div>
                        <h1 class="page-title">Inventory & Stock Management</h1>
                        <p class="page-subtitle">Track live on-hand quantities, low-stock warnings, and audit logs</p>
                    </div>
                    <div class="page-actions">
                        <a href="<?= asset('product-create.php') ?>" class="btn-secondary">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>Add Product</span>
                        </a>
                        <a href="<?= asset('products.php') ?>" class="header-btn">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                            <span>Products Catalog</span>
                        </a>
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

                <!-- Inventory KPI Cards Grid -->
                <section class="kpi-grid" aria-label="Inventory Metrics Overview">
                    <!-- 1. Total Units in Stock -->
                    <div class="kpi-card">
                        <div class="kpi-top">
                            <span class="kpi-label">Total Stock Units</span>
                            <div class="kpi-icon-wrap icon-orders" aria-hidden="true">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value"><?= number_format($inventoryStats['total_stock_units']) ?></div>
                        <div class="kpi-footer">
                            <span>Across <?= $inventoryStats['total_products'] ?> catalog items</span>
                        </div>
                    </div>

                    <!-- 2. Low Stock Alerts -->
                    <div class="kpi-card">
                        <div class="kpi-top">
                            <span class="kpi-label">Low Stock Alerts</span>
                            <div class="kpi-icon-wrap icon-products" aria-hidden="true">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value" style="color: <?= $inventoryStats['low_stock_count'] > 0 ? '#b45309' : 'inherit' ?>;">
                            <?= $inventoryStats['low_stock_count'] ?>
                        </div>
                        <div class="kpi-footer">
                            <span class="trend-badge <?= $inventoryStats['low_stock_count'] > 0 ? 'trend-warn' : 'trend-neutral' ?>">
                                <?= $inventoryStats['low_stock_count'] > 0 ? 'Requires Restock' : 'Healthy Stock' ?>
                            </span>
                        </div>
                    </div>

                    <!-- 3. Out of Stock -->
                    <div class="kpi-card">
                        <div class="kpi-top">
                            <span class="kpi-label">Out of Stock</span>
                            <div class="kpi-icon-wrap icon-danger" aria-hidden="true">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value" style="color: <?= $inventoryStats['out_of_stock_count'] > 0 ? '#dc2626' : 'inherit' ?>;">
                            <?= $inventoryStats['out_of_stock_count'] ?>
                        </div>
                        <div class="kpi-footer">
                            <span class="trend-badge <?= $inventoryStats['out_of_stock_count'] > 0 ? 'trend-danger' : 'trend-neutral' ?>">
                                <?= $inventoryStats['out_of_stock_count'] > 0 ? 'Zero Inventory' : 'All In Stock' ?>
                            </span>
                        </div>
                    </div>

                    <!-- 4. Inventory Valuation -->
                    <div class="kpi-card">
                        <div class="kpi-top">
                            <span class="kpi-label">Inventory Valuation</span>
                            <div class="kpi-icon-wrap icon-sales" aria-hidden="true">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value">₹<?= number_format($inventoryStats['total_retail_value'], 2) ?></div>
                        <div class="kpi-footer">
                            <span>Cost value: ₹<?= number_format($inventoryStats['total_cost_value'], 2) ?></span>
                        </div>
                    </div>
                </section>

                <!-- Navigation Tabs (Current Stock vs Movement History) -->
                <div class="tab-row">
                    <a href="<?= asset('inventory.php?tab=stock') ?>" class="tab-btn <?= $activeTab === 'stock' ? 'active' : '' ?>">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <span>Current Stock Inventory</span>
                    </a>
                    <a href="<?= asset('inventory.php?tab=history') ?>" class="tab-btn <?= $activeTab === 'history' ? 'active' : '' ?>">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Stock Movement History Log (<?= count($movements) ?>)</span>
                    </a>
                </div>

                <?php if ($activeTab === 'history'): ?>
                    <!-- Movement History Log Table -->
                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <div class="section-heading">Stock Movement Audit Trail</div>
                                <div class="section-subheading">Complete chronological record of all stock ins, outs, and physical adjustments</div>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Product</th>
                                        <th>Movement Type</th>
                                        <th>Quantity Change</th>
                                        <th>Before → After</th>
                                        <th>Reason / Note</th>
                                        <th>Cashier / User</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($movements)): ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon">📋</div>
                                                    <div style="font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">No stock movements recorded yet</div>
                                                    <div>When stock is added, sold, or adjusted, records will appear here.</div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($movements as $m): ?>
                                            <?php
                                                $change = (int) $m['quantity_change'];
                                                $type = $m['movement_type'];
                                                $typeLabel = 'Stock In';
                                                $typeBadge = 'movement-in';

                                                if ($type === 'out') {
                                                    $typeLabel = 'Stock Out';
                                                    $typeBadge = 'movement-out';
                                                } elseif ($type === 'adjustment') {
                                                    $typeLabel = 'Count Adjustment';
                                                    $typeBadge = 'movement-adjust';
                                                }
                                            ?>
                                            <tr>
                                                <td style="color: var(--saas-slate-500); font-size: 12.5px; white-space: nowrap;">
                                                    <?= date('M d, Y • h:i A', strtotime($m['created_at'])) ?>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 700; color: var(--saas-navy-950);"><?= e($m['product_name']) ?></div>
                                                    <div style="font-size: 11.5px; font-family: monospace; color: var(--saas-slate-500);">SKU: <?= e($m['product_sku']) ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $typeBadge ?>">
                                                        <?= e($typeLabel) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong style="color: <?= $change >= 0 ? '#047857' : '#b91c1c' ?>; font-size: 14px;">
                                                        <?= $change > 0 ? '+' . $change : $change ?> units
                                                    </strong>
                                                </td>
                                                <td style="font-size: 13px; color: var(--saas-slate-600);">
                                                    <?= (int)$m['quantity_before'] ?> → <strong style="color: var(--saas-navy-950);"><?= (int)$m['quantity_after'] ?></strong>
                                                </td>
                                                <td>
                                                    <span style="font-size: 13px; color: var(--saas-navy-800);"><?= e($m['reason']) ?></span>
                                                </td>
                                                <td>
                                                    <span style="font-size: 12.5px; color: var(--saas-slate-500); font-weight: 600;">
                                                        <?= e($m['user_name'] ?: 'System') ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Current Stock Inventory Table -->
                    <div class="filter-card">
                        <form method="GET" action="<?= asset('inventory.php') ?>" class="filter-form">
                            <input type="hidden" name="tab" value="stock">

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
                                    placeholder="Search products by name or SKU..."
                                    class="form-control with-icon"
                                >
                            </div>

                            <select name="stock" class="form-control filter-select">
                                <option value="">All Stock Statuses</option>
                                <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>Healthy Stock (> Threshold)</option>
                                <option value="low_stock" <?= $stockFilter === 'low_stock' ? 'selected' : '' ?>>Low Stock Alert (<= Threshold)</option>
                                <option value="out_of_stock" <?= $stockFilter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock (0 units)</option>
                            </select>

                            <button type="submit" class="btn-filter-submit">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                                </svg>
                                <span>Filter</span>
                            </button>

                            <?php if ($search !== '' || $stockFilter !== ''): ?>
                                <a href="<?= asset('inventory.php') ?>" class="btn-filter-clear">Clear Filters</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="section-card">
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Cost Price</th>
                                        <th>Selling Price</th>
                                        <th>Current Stock</th>
                                        <th>Stock Status</th>
                                        <th>Inventory Valuation</th>
                                        <th style="text-align: right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($products)): ?>
                                        <tr>
                                            <td colspan="8">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon">📦</div>
                                                    <div style="font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">No items match this filter</div>
                                                    <div>Adjust your search criteria or add new items to the inventory.</div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($products as $prod): ?>
                                            <?php
                                                $stock = (int) $prod['stock_quantity'];
                                                $threshold = (int) $prod['low_stock_threshold'];
                                                $stockBadgeClass = 'badge-in-stock';
                                                $stockLabel = 'In Stock';

                                                if ($stock <= 0) {
                                                    $stockBadgeClass = 'badge-out-of-stock';
                                                    $stockLabel = 'Out of Stock';
                                                } elseif ($stock <= $threshold) {
                                                    $stockBadgeClass = 'badge-low-stock';
                                                    $stockLabel = 'Low Stock';
                                                }

                                                $itemValuation = $stock * (float)$prod['selling_price'];
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
                                                            <div class="product-title"><?= e($prod['name']) ?></div>
                                                            <div class="product-sku">SKU: <?= e($prod['sku']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info"><?= e($prod['category_name'] ?: 'General') ?></span>
                                                </td>
                                                <td>₹<?= number_format((float)$prod['cost_price'], 2) ?></td>
                                                <td><strong>₹<?= number_format((float)$prod['selling_price'], 2) ?></strong></td>
                                                <td>
                                                    <strong style="font-size: 15px; color: var(--saas-navy-950);"><?= $stock ?></strong> units
                                                    <div style="font-size: 11px; color: var(--saas-slate-400);">Alert at <?= $threshold ?> units</div>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $stockBadgeClass ?>">
                                                        <?= e($stockLabel) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span style="font-weight: 700; color: var(--saas-navy-950);">₹<?= number_format($itemValuation, 2) ?></span>
                                                </td>
                                                <td style="text-align: right;">
                                                    <button
                                                        type="button"
                                                        class="header-btn open-stock-adjust-btn"
                                                        data-id="<?= $prod['id'] ?>"
                                                        data-name="<?= e($prod['name']) ?>"
                                                        data-sku="<?= e($prod['sku']) ?>"
                                                        data-stock="<?= $stock ?>"
                                                        style="font-size: 12px; padding: 6px 12px; border: 0;"
                                                    >
                                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/>
                                                        </svg>
                                                        <span>Adjust Stock</span>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Stock Adjustment Modal -->
    <div class="modal-overlay" id="inventoryAdjustModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title">Inventory Stock Adjustment</h3>
                <button type="button" class="modal-close-btn" id="closeInventoryAdjustModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('inventory.php?tab=' . urlencode($activeTab)) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="product_id" id="adjustModalProductId" value="">

                <div class="modal-body">
                    <div style="background: var(--saas-surface); padding: 12px 14px; border-radius: var(--saas-radius-md); border: 1px solid var(--saas-border-light); margin-bottom: 16px;">
                        <div style="font-weight: 700; font-size: 14px; color: var(--saas-navy-950);" id="adjustModalProductName">Product Name</div>
                        <div style="font-size: 12px; color: var(--saas-slate-500); margin-top: 2px;">
                            SKU: <span id="adjustModalProductSku" style="font-family: monospace; font-weight: 600;"></span> • Current Stock: <strong id="adjustModalCurrentQty" style="color: var(--saas-primary);">0</strong> units
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="adjustMovementType" class="form-label">Movement Type <span style="color: #ef4444;">*</span></label>
                        <select id="adjustMovementType" name="movement_type" class="form-control" required>
                            <option value="in">➕ Stock In (Purchase / Restock / Shipment)</option>
                            <option value="out">➖ Stock Out (Damage / Spoilage / Return / Write-off)</option>
                            <option value="adjustment">🔄 Set Exact Physical Count (Stocktake Correction)</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="adjustQuantity" class="form-label" id="adjustQtyLabel">Quantity to Add <span style="color: #ef4444;">*</span></label>
                        <input type="number" id="adjustQuantity" name="quantity" min="1" value="1" required class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="adjustReason" class="form-label">Adjustment Reason / Reference Note <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="adjustReason" name="reason" required placeholder="e.g. Supplier Invoice #1024, Shelf Audit, Broken package" class="form-control">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelInventoryAdjustModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0;">Confirm & Log Movement</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('inventoryAdjustModal');
            const closeBtn = document.getElementById('closeInventoryAdjustModal');
            const cancelBtn = document.getElementById('cancelInventoryAdjustModal');
            const idInput = document.getElementById('adjustModalProductId');
            const nameEl = document.getElementById('adjustModalProductName');
            const skuEl = document.getElementById('adjustModalProductSku');
            const currQtyEl = document.getElementById('adjustModalCurrentQty');
            const typeSelect = document.getElementById('adjustMovementType');
            const qtyInput = document.getElementById('adjustQuantity');
            const qtyLabel = document.getElementById('adjustQtyLabel');

            function updateQtyLabel() {
                const val = typeSelect.value;
                if (val === 'in') {
                    qtyLabel.innerHTML = 'Quantity to Add <span style="color: #ef4444;">*</span>';
                    qtyInput.min = '1';
                } else if (val === 'out') {
                    qtyLabel.innerHTML = 'Quantity to Remove <span style="color: #ef4444;">*</span>';
                    qtyInput.min = '1';
                } else {
                    qtyLabel.innerHTML = 'New Exact Physical Count <span style="color: #ef4444;">*</span>';
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

            document.querySelectorAll('.open-stock-adjust-btn').forEach(btn => {
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

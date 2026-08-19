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

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

$search = trim((string) ($_GET['q'] ?? ''));
$categoryId = !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null;
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$stockFilter = trim((string) ($_GET['stock'] ?? ''));

$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle Product Deletion & Quick Stock Adjustment
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
    }
}

$categories = get_categories();
$products = get_products($search, $categoryId, $statusFilter, $stockFilter);
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
                <!-- Page Top Row -->
                <div class="page-header-row">
                    <div>
                        <h1 class="page-title">Products Catalog</h1>
                        <p class="page-subtitle">Manage retail items, pricing, SKU barcodes, and live inventory</p>
                    </div>
                    <div class="page-actions">
                        <a href="<?= asset('categories.php') ?>" class="btn-secondary">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            <span>Categories</span>
                        </a>
                        <a href="<?= asset('product-create.php') ?>" class="header-btn">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>Add Product</span>
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

                <!-- Products Table -->
                <div class="section-card">
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
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="9">
                                            <div class="empty-state">
                                                <div class="empty-state-icon">📦</div>
                                                <div style="font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">No products found</div>
                                                <div>Add your first product to the catalog or refine your search filters.</div>
                                                <div style="margin-top: 16px;">
                                                    <a href="<?= asset('product-create.php') ?>" class="header-btn" style="display: inline-flex;">+ Add New Product</a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $prod): ?>
                                        <?php
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
                                                        <div class="product-title"><?= e($prod['name']) ?></div>
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
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
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

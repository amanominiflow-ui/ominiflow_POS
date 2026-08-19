<?php
/**
 * OminiFlow POS - Edit Product
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/products_db.php';

require_auth();

$id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
$product = $id ? get_product_by_id($id) : null;

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect(APP_URL . '/products.php');
}

$categories = get_categories('', 'active');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Invalid session token. Please try again.';
    } else {
        $data = [
            'name' => $_POST['name'] ?? '',
            'sku' => $_POST['sku'] ?? '',
            'barcode' => $_POST['barcode'] ?? '',
            'category_id' => $_POST['category_id'] ?? null,
            'cost_price' => $_POST['cost_price'] ?? 0.00,
            'selling_price' => $_POST['selling_price'] ?? 0.00,
            'tax_percent' => $_POST['tax_percent'] ?? 0.00,
            'low_stock_threshold' => $_POST['low_stock_threshold'] ?? 5,
            'status' => $_POST['status'] ?? 'active',
        ];

        $file = $_FILES['image'] ?? null;

        $result = save_product($data, $file, $id);

        if ($result['success']) {
            set_flash('success', 'Product "' . e($data['name']) . '" updated successfully!');
            redirect(APP_URL . '/products.php');
        } else {
            $errors = $result['errors'];
            // Keep user input in $product array for form
            $product = array_merge($product, $data);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - OminiFlow POS</title>

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
                        <h1 class="page-title">Edit Product</h1>
                        <p class="page-subtitle">Modify details, price, barcode, or stock threshold for <strong><?= e($product['name']) ?></strong></p>
                    </div>
                    <div class="page-actions">
                        <a href="<?= asset('products.php') ?>" class="btn-secondary">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                            </svg>
                            <span>Back to Products</span>
                        </a>
                    </div>
                </div>

                <?php if (!empty($errors['general'])): ?>
                    <div class="saas-alert saas-alert-danger">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span><?= e($errors['general']) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Product Form Card -->
                <div class="form-card">
                    <form method="POST" action="<?= asset('product-edit.php?id=' . $id) ?>" enctype="multipart/form-data" novalidate>
                        <?= csrf_field() ?>

                        <div class="form-grid">
                            <!-- Product Name -->
                            <div class="form-group form-group-full">
                                <label for="name" class="form-label">Product Name <span style="color: #ef4444;">*</span></label>
                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    value="<?= e((string)$product['name']) ?>"
                                    required
                                    placeholder="e.g. Wireless Barcode Scanner"
                                    class="form-control <?= !empty($errors['name']) ? 'is-invalid' : '' ?>"
                                >
                                <?php if (!empty($errors['name'])): ?>
                                    <span style="color: #ef4444; font-size: 12px;"><?= e($errors['name']) ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- SKU -->
                            <div class="form-group">
                                <label for="sku" class="form-label">SKU (Stock Keeping Unit) <span style="color: #ef4444;">*</span></label>
                                <input
                                    type="text"
                                    id="sku"
                                    name="sku"
                                    value="<?= e((string)$product['sku']) ?>"
                                    required
                                    class="form-control <?= !empty($errors['sku']) ? 'is-invalid' : '' ?>"
                                    style="text-transform: uppercase;"
                                >
                                <span class="form-hint">Unique identifier for inventory tracking.</span>
                                <?php if (!empty($errors['sku'])): ?>
                                    <span style="color: #ef4444; font-size: 12px;"><?= e($errors['sku']) ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Barcode -->
                            <div class="form-group">
                                <label for="barcode" class="form-label">Barcode / UPC / EAN</label>
                                <input
                                    type="text"
                                    id="barcode"
                                    name="barcode"
                                    value="<?= e((string)$product['barcode']) ?>"
                                    placeholder="e.g. 8901234567890"
                                    class="form-control <?= !empty($errors['barcode']) ? 'is-invalid' : '' ?>"
                                >
                                <span class="form-hint">Scannable barcode for quick counter checkout.</span>
                                <?php if (!empty($errors['barcode'])): ?>
                                    <span style="color: #ef4444; font-size: 12px;"><?= e($errors['barcode']) ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Category -->
                            <div class="form-group">
                                <label for="category_id" class="form-label">Category</label>
                                <select id="category_id" name="category_id" class="form-control">
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= (string)$product['category_id'] === (string)$cat['id'] ? 'selected' : '' ?>>
                                            <?= e($cat['name']) ?> (<?= e($cat['code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Status -->
                            <div class="form-group">
                                <label for="status" class="form-label">Product Status</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="active" <?= $product['status'] === 'active' ? 'selected' : '' ?>>Active (Visible in POS Register)</option>
                                    <option value="inactive" <?= $product['status'] === 'inactive' ? 'selected' : '' ?>>Inactive (Archived)</option>
                                </select>
                            </div>

                            <!-- Cost Price -->
                            <div class="form-group">
                                <label for="cost_price" class="form-label">Cost Price (₹)</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="cost_price"
                                    name="cost_price"
                                    value="<?= e((string)$product['cost_price']) ?>"
                                    placeholder="0.00"
                                    class="form-control <?= !empty($errors['cost_price']) ? 'is-invalid' : '' ?>"
                                >
                            </div>

                            <!-- Selling Price -->
                            <div class="form-group">
                                <label for="selling_price" class="form-label">Selling Price (₹) <span style="color: #ef4444;">*</span></label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="selling_price"
                                    name="selling_price"
                                    value="<?= e((string)$product['selling_price']) ?>"
                                    required
                                    placeholder="0.00"
                                    class="form-control <?= !empty($errors['selling_price']) ? 'is-invalid' : '' ?>"
                                >
                                <?php if (!empty($errors['selling_price'])): ?>
                                    <span style="color: #ef4444; font-size: 12px;"><?= e($errors['selling_price']) ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Tax % -->
                            <div class="form-group">
                                <label for="tax_percent" class="form-label">Tax Rate (%)</label>
                                <input
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    max="100"
                                    id="tax_percent"
                                    name="tax_percent"
                                    value="<?= e((string)$product['tax_percent']) ?>"
                                    placeholder="0.0"
                                    class="form-control"
                                >
                            </div>

                            <!-- Low Stock Alert Threshold -->
                            <div class="form-group">
                                <label for="low_stock_threshold" class="form-label">Low-Stock Alert Threshold</label>
                                <input
                                    type="number"
                                    min="0"
                                    id="low_stock_threshold"
                                    name="low_stock_threshold"
                                    value="<?= e((string)$product['low_stock_threshold']) ?>"
                                    placeholder="5"
                                    class="form-control"
                                >
                            </div>

                            <!-- Product Image -->
                            <div class="form-group form-group-full">
                                <label for="image" class="form-label">Product Image</label>
                                <div style="display: flex; align-items: center; gap: 16px;">
                                    <?php if (!empty($product['image_path'])): ?>
                                        <img src="<?= asset($product['image_path']) ?>" alt="<?= e($product['name']) ?>" class="product-thumb" style="width: 56px; height: 56px;">
                                    <?php else: ?>
                                        <div class="product-thumb-placeholder" style="width: 56px; height: 56px;">📦</div>
                                    <?php endif; ?>
                                    <div style="flex: 1;">
                                        <input
                                            type="file"
                                            id="image"
                                            name="image"
                                            accept="image/jpeg,image/png,image/webp"
                                            class="form-control"
                                            style="padding: 6px;"
                                        >
                                        <span class="form-hint">Leave blank to keep existing image. Accepted: JPG, PNG, WebP (Max 5MB).</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Submit Actions -->
                        <div class="form-actions">
                            <button type="submit" class="header-btn" style="border: 0;">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <span>Update Product</span>
                            </button>
                            <a href="<?= asset('products.php') ?>" class="btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
</body>
</html>

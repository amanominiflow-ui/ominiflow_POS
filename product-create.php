<?php
/**
 * OminiFlow POS - New Item (Zoho-style product create)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/products_db.php';
require_once __DIR__ . '/includes/purchases_db.php';

require_auth();
ensure_product_item_schema();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;
$pageTitle = 'New Item';

$isEdit = false;
$product = [];
$categories = get_categories('', 'active');
$vendors = get_vendors();
$taxRates = get_tax_rates_list();
$brands = get_product_brand_names('brand');
$manufacturers = get_product_brand_names('manufacturer');
$productImages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Invalid session token. Please try again.';
    } else {
        set_old_input($_POST);
        $data = collect_product_form_data();
        $result = save_product($data, null, null, $userId);
        if ($result['success']) {
            clear_old_input();
            set_flash('success', 'Item "' . e($data['name']) . '" created successfully!');
            redirect(APP_URL . '/products.php');
        } else {
            $errors = $result['errors'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Item — <?= APP_NAME ?></title>
    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/item-form.css') ?>">
</head>
<body>
<div class="app-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <div class="app-main">
        <?php require_once __DIR__ . '/includes/header.php'; ?>
        <main class="dashboard-content item-page">
            <div class="page-header-row">
                <div>
                    <h1 class="page-title">New Item</h1>
                    <p class="page-subtitle">Add a goods or service item with sales, purchase, tax, and inventory details.</p>
                </div>
                <a href="<?= asset('products.php') ?>" class="btn-secondary">Back to Products</a>
            </div>
            <?php if (!empty($errors['general'])): ?>
                <div class="saas-alert saas-alert-danger"><span><?= e($errors['general']) ?></span></div>
            <?php endif; ?>
            <form method="POST" action="<?= asset('product-create.php') ?>" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <?php require __DIR__ . '/includes/product_form.php'; ?>
            </form>
        </main>
    </div>
</div>
<script src="<?= asset('assets/js/dashboard.js') ?>"></script>
</body>
</html>

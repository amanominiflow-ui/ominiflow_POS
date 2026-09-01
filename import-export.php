<?php
/**
 * Data Import & Export Hub (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/import_export_db.php';

require_auth();

$pageTitle = 'Import & Export Hub';

$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle Export
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    $entity = trim((string)$_GET['export']);
    if ($entity === 'sample_template' || $entity === 'sample_products') {
        export_sample_products_template();
    } elseif (in_array($entity, ['products', 'customers', 'orders', 'invoices'], true)) {
        export_data_to_csv($entity);
    }
    exit;
}

// Handle Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_products') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/import-export.php');
    } else {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            set_flash('error', 'Please select a valid CSV file to upload.');
        } else {
            $res = import_products_from_csv($_FILES['csv_file']['tmp_name'], (int)$user['id']);
            if ($res['success']) {
                if ($res['imported_count'] > 0) {
                    $warnTxt = !empty($res['errors']) ? ' (' . count($res['errors']) . ' warning(s): ' . implode('; ', array_slice($res['errors'], 0, 2)) . ')' : '';
                    set_flash('success', "Import completed successfully: {$res['imported_count']} product(s) added/updated{$warnTxt}.");
                } elseif (!empty($res['errors'])) {
                    set_flash('error', 'CSV Import failed: ' . implode('; ', array_slice($res['errors'], 0, 3)));
                } else {
                    set_flash('error', 'No valid product rows found in uploaded CSV file.');
                }
            } else {
                set_flash('error', $res['error'] ?? 'Import failed.');
            }
        }
        redirect(APP_URL . '/import-export.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <div class="page-header-row">
                    <div>
                        <h1 class="page-title">Data Import & Export Hub</h1>
                        <p class="page-subtitle">Batch import catalog data from spreadsheet CSV files and download real-time business exports.</p>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <!-- CSV Import Card -->
                    <div class="section-card">
                        <div class="section-header" style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h2 class="section-heading">Bulk Import Products (CSV)</h2>
                                <p class="section-subheading">Upload new products or update existing inventory</p>
                            </div>
                            <a href="<?= asset('import-export.php?export=sample_template') ?>" class="btn-secondary" style="font-size: 12px; font-weight: 600; padding: 6px 12px; text-decoration: none;">
                                📥 Sample Template
                            </a>
                        </div>
                        <div style="padding: 24px;">
                            <p style="font-size: 13px; color: var(--saas-slate-600); margin-bottom: 16px; line-height: 1.5;">
                                Upload a standard CSV file with headers:<br>
                                <code style="background: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-size: 11.5px; display: inline-block; margin-top: 4px; color: #0f172a;">Name, SKU, Barcode, Category, Selling Price, Cost Price, Tax Percent, Stock</code>
                            </p>

                            <form method="POST" action="<?= asset('import-export.php') ?>" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 16px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="import_products">

                                <div style="border: 2px dashed var(--saas-border); padding: 28px 20px; border-radius: var(--saas-radius-md); text-align: center; background: #f8fafc;">
                                    <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--saas-slate-400); margin-bottom: 8px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                                    <div style="font-size: 13px; font-weight: 600; color: var(--saas-navy-950); margin-bottom: 4px;">Select CSV file from computer</div>
                                    <input type="file" name="csv_file" accept=".csv" required style="font-size: 12px; margin-top: 6px;">
                                </div>

                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <button type="submit" class="header-btn" style="padding: 10px 20px; border: 0;">
                                        Upload & Process CSV Import
                                    </button>
                                    <a href="<?= asset('import-export.php?export=sample_template') ?>" style="font-size: 12.5px; color: var(--saas-primary); font-weight: 600; text-decoration: none;">
                                        Download Sample CSV &darr;
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- CSV Export Downloads Card -->
                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <h2 class="section-heading">Export Business Records (CSV)</h2>
                                <p class="section-subheading">Download real-time UTF-8 formatted spreadsheets</p>
                            </div>
                        </div>
                        <div style="padding: 24px;">
                            <p style="font-size: 13px; color: var(--saas-slate-600); margin-bottom: 16px; line-height: 1.5;">
                                Download clean spreadsheets for accounting, inventory audits, or external ERP systems.
                            </p>

                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <a href="<?= asset('import-export.php?export=products') ?>" class="btn-secondary" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 18px;">
                                    <span style="font-weight: 700; color: var(--saas-navy-950);">📦 Export Products Catalog</span>
                                    <span style="font-size: 12px; color: var(--saas-primary); font-weight: 700;">Download CSV &darr;</span>
                                </a>
                                <a href="<?= asset('import-export.php?export=customers') ?>" class="btn-secondary" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 18px;">
                                    <span style="font-weight: 700; color: var(--saas-navy-950);">👥 Export Customer CRM Data</span>
                                    <span style="font-size: 12px; color: var(--saas-primary); font-weight: 700;">Download CSV &darr;</span>
                                </a>
                                <a href="<?= asset('import-export.php?export=invoices') ?>" class="btn-secondary" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 18px;">
                                    <span style="font-weight: 700; color: var(--saas-navy-950);">📄 Export Tax Invoices (GST)</span>
                                    <span style="font-size: 12px; color: var(--saas-primary); font-weight: 700;">Download CSV &darr;</span>
                                </a>
                                <a href="<?= asset('import-export.php?export=orders') ?>" class="btn-secondary" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 18px;">
                                    <span style="font-weight: 700; color: var(--saas-navy-950);">🛒 Export Sales Orders</span>
                                    <span style="font-size: 12px; color: var(--saas-primary); font-weight: 700;">Download CSV &darr;</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</body>
</html>

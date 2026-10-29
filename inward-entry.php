<?php
declare(strict_types=1);

/**
 * Warehouse inward count: product + size + colour + quantity.
 * Received quantity is company stock at once. Ready stock is placed in Central only after QC, checking, and tagging.
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/purchases_db.php';
require_once __DIR__ . '/includes/outlets_db.php';
require_once __DIR__ . '/includes/inward_db.php';
require_once __DIR__ . '/includes/roles_db.php';
require_once __DIR__ . '/includes/products_db.php';

require_auth();

$pageTitle = 'Inward Entry';
$user = current_user();
$userId = $user ? (int) $user['id'] : null;
$canInwardCost = inward_entry_can_manage_cost($user);

if (isset($_GET['action']) && $_GET['action'] === 'download_matrix_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inward_matrix_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Supplier', 'Receipt Date', 'Warehouse', 'Supplier Reference', 'Design Code', 'Colour', 'Unit Cost', 'S', 'M', 'L', 'XL', 'XXL']);
    fputcsv($out, ['Ash Wholesale', date('Y-m-d'), 'Central Warehouse', 'BILL-2026-101', 'AC125', 'Maroon', '250.00', '4', '8', '10', '6', '2']);
    fputcsv($out, ['Ash Wholesale', date('Y-m-d'), 'Central Warehouse', 'BILL-2026-101', 'AC125', 'Black', '250.00', '5', '8', '12', '5', '0']);
    fputcsv($out, ['Ash Wholesale', date('Y-m-d'), 'Central Warehouse', 'BILL-2026-101', 'AC125', 'Bottle Green', '250.00', '3', '10', '15', '8', '4']);
    fputcsv($out, ['Ash Wholesale', date('Y-m-d'), 'Central Warehouse', 'BILL-2026-102', 'AC126', 'Pink', '280.00', '6', '10', '12', '8', '4']);
    fputcsv($out, ['Ash Wholesale', date('Y-m-d'), 'Central Warehouse', 'BILL-2026-102', 'AC126', 'Blue', '280.00', '5', '8', '10', '6', '3']);
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $isAjax = !empty($_POST['is_ajax']);
    inward_entry_assert_action($postAction, $isAjax);

    if ($postAction === 'save_matrix_inward') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash('error', 'Invalid session token.');
            redirect(APP_URL . '/inward-entry.php?tab=matrix');
        }
        $matrixPayload = json_decode((string)($_POST['matrix_json'] ?? '[]'), true);
        if (!is_array($matrixPayload)) {
            $matrixPayload = [];
        }
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $vendorName = trim((string)($_POST['vendor_name'] ?? ''));
        $supplierReference = trim((string)($_POST['supplier_reference'] ?? ''));
        $res = create_inward_matrix_entry(
            (int)($_POST['warehouse_id'] ?? 0),
            $matrixPayload,
            (string)($_POST['entry_date'] ?? ''),
            $supplierReference,
            (string)($_POST['notes'] ?? ''),
            $vendorId > 0 ? $vendorId : null,
            $vendorName,
            $userId
        );
        if ($res['success']) {
            set_flash('success', 'Matrix Inward ' . $res['entry_number'] . ' saved! ' . $res['total_qty'] . ' pieces across ' . $res['lines_count'] . ' size/colour combinations posted to company stock.');
            redirect(APP_URL . '/inward-entry.php?id=' . (int)$res['entry_id']);
        }
        set_flash('error', $res['error'] ?? 'Could not save the matrix inward count.');
        redirect(APP_URL . '/inward-entry.php?tab=matrix');
    }

    if ($postAction === 'import_matrix_csv') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash('error', 'Invalid session token.');
            redirect(APP_URL . '/inward-entry.php?tab=import');
        }
        $csvText = '';
        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $csvText = file_get_contents($_FILES['csv_file']['tmp_name']) ?: '';
        } else {
            $csvText = (string)($_POST['csv_text'] ?? '');
        }
        if (trim($csvText) === '') {
            set_flash('error', 'Please select a CSV file to upload or enter CSV text.');
            redirect(APP_URL . '/inward-entry.php?tab=import');
        }
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $res = import_inward_matrix_csv(
            $csvText,
            (int)($_POST['warehouse_id'] ?? 0),
            $vendorId > 0 ? $vendorId : null,
            (string)($_POST['entry_date'] ?? ''),
            trim((string)($_POST['supplier_reference'] ?? '')),
            $userId
        );
        if ($res['success']) {
            set_flash('success', 'Bulk CSV Inward ' . $res['entry_number'] . ' imported successfully! ' . $res['total_qty'] . ' total pieces inwarded.');
            redirect(APP_URL . '/inward-entry.php?id=' . (int)$res['entry_id']);
        }
        set_flash('error', $res['error'] ?? 'CSV Inward Import failed.');
        redirect(APP_URL . '/inward-entry.php?tab=import');
    }

    if ($postAction === 'upload_matrix_image') {
        header('Content-Type: application/json');
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid session token. Please refresh.']);
            exit;
        }

        if (empty($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            echo json_encode(['success' => false, 'error' => 'No image file uploaded.']);
            exit;
        }

        require_once __DIR__ . '/includes/products_db.php';
        $path = handle_variant_image_upload($_FILES['image']);
        if (!$path) {
            echo json_encode(['success' => false, 'error' => 'Failed to upload image. Supported formats: JPG, PNG, WEBP (Max 5MB).']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'image_path' => $path,
            'image_url' => asset($path),
        ]);
        exit;
    }

    if ($postAction === 'quick_create_product') {
        header('Content-Type: application/json');
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid session token. Please refresh.']);
            exit;
        }

        $pName = trim((string) ($_POST['name'] ?? ''));
        if ($pName === '') {
            echo json_encode(['success' => false, 'error' => 'Product name is required.']);
            exit;
        }

        $pSku = strtoupper(trim((string) ($_POST['sku'] ?? '')));
        $pBarcode = trim((string) ($_POST['barcode'] ?? ''));
        $pCost = (float) ($_POST['cost_price'] ?? 0.00);
        $pSelling = (float) ($_POST['selling_price'] ?? 0.00);
        $pCatId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;

        $saveRes = save_product([
            'name' => $pName,
            'sku' => $pSku,
            'barcode' => $pBarcode ?: ($pSku ?: null),
            'cost_price' => $pCost >= 0 ? $pCost : 0.00,
            'selling_price' => $pSelling >= 0 ? $pSelling : 0.00,
            'category_id' => $pCatId,
            'product_type' => 'simple',
            'track_inventory' => 1,
            'initial_stock' => 0,
            'status' => 'active',
        ], null, null, $userId, current_business_id());

        if (!empty($saveRes['success'])) {
            $createdProd = get_product_by_id((int) $saveRes['product_id'], current_business_id());
            echo json_encode([
                'success' => true,
                'product' => [
                    'id' => (int) $saveRes['product_id'],
                    'name' => $pName,
                    'sku' => (string) ($createdProd['sku'] ?? $pSku),
                    'variants' => [],
                ],
            ]);
        } else {
            $errs = $saveRes['errors'] ?? [];
            $errMsg = reset($errs) ?: 'Could not save product.';
            echo json_encode(['success' => false, 'error' => $errMsg]);
        }
        exit;
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/inward-entry.php');
    }

    if ($postAction === 'confirm_costs' && !$canInwardCost) {
        set_flash('error', 'Only users with inward cost permission can confirm costs.');
        redirect(APP_URL . '/inward-entry.php?id=' . (int) ($_POST['entry_id'] ?? 0));
    }

    if ($postAction === 'save_inward') {
        $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);
        if (!is_array($lines)) {
            $lines = [];
        }
        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
        $vendorName = trim((string) ($_POST['vendor_name'] ?? ''));
        $resolvedVendor = inward_resolve_vendor($vendorId > 0 ? $vendorId : null, $vendorName, current_business_id(), false);
        if (empty($resolvedVendor['success'])) {
            set_flash('error', $resolvedVendor['error'] ?? 'Could not save the supplier.');
            redirect(APP_URL . '/inward-entry.php');
        }
        $res = create_inward_entry(
            (int) ($_POST['warehouse_id'] ?? 0),
            $lines,
            (string) ($_POST['entry_date'] ?? ''),
            (string) ($_POST['notes'] ?? ''),
            (int) ($resolvedVendor['vendor_id'] ?? 0) > 0 ? (int) $resolvedVendor['vendor_id'] : null,
            $userId
        );
        if ($res['success']) {
            set_flash('success', 'Inward ' . $res['entry_number'] . ' saved. Quantity is company stock now. It is not ready stock in Central until QC, checking, and tagging is complete.');
            redirect(APP_URL . '/inward-entry.php?id=' . (int) $res['entry_id']);
        }
        set_flash('error', $res['error'] ?? 'Could not save the inward count.');
        redirect(APP_URL . '/inward-entry.php');
    }

    if (($_POST['action'] ?? '') === 'confirm_costs') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $prices = json_decode((string) ($_POST['prices_json'] ?? '[]'), true);
        if (!is_array($prices)) {
            $prices = [];
        }
        $res = confirm_inward_costs(
            $entryId,
            $prices,
            null,
            (int) ($_POST['vendor_id'] ?? 0) ?: null,
            $userId,
            trim((string) ($_POST['vendor_name'] ?? ''))
        );
        if ($res['success']) {
            set_flash('success', 'Costs confirmed. Bill ' . ($res['bill_number'] ?? '') . ' is in Purchases → Bills. Barcodes stay unreleased until you confirm the purchase. This count is still not sellable.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not confirm these costs.');
        }
        redirect(APP_URL . '/inward-entry.php?id=' . $entryId);
    }

    if (($_POST['action'] ?? '') === 'confirm_purchase') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $res = confirm_inward_purchase($entryId);
        if ($res['success']) {
            set_flash('success', 'Purchase confirmed. Barcodes are ready for the warehouse to print. Stock stays company stock until QC, checking, and tagging is complete.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not confirm this purchase.');
        }
        redirect(APP_URL . '/inward-entry.php?id=' . $entryId);
    }

    if (($_POST['action'] ?? '') === 'complete_qc') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $res = complete_inward_qc($entryId, null, $userId);
        if ($res['success']) {
            set_flash('success', 'QC, checking, and tagging is complete. Ready stock is in Central Warehouse only.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not finish QC, checking, and tagging.');
        }
        redirect(APP_URL . '/inward-entry.php?id=' . $entryId);
    }

    if (($_POST['action'] ?? '') === 'create_bill') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $res = create_inward_purchase_bill(
            $entryId,
            (int) ($_POST['vendor_id'] ?? 0) ?: null,
            $userId,
            null,
            trim((string) ($_POST['vendor_name'] ?? ''))
        );
        if ($res['success']) {
            set_flash('success', 'Bill ' . ($res['bill_number'] ?? '') . ' created. It is listed under Purchases → Bills.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not create the bill.');
        }
        redirect(APP_URL . '/inward-entry.php?id=' . $entryId);
    }
} else {
    require_permission('purchases.inward_entry.view');
}

$search = trim((string) ($_GET['search'] ?? ''));
$selectedId = (int) ($_GET['id'] ?? 0);
$selected = $selectedId > 0 ? get_inward_entry_by_id($selectedId) : null;
$entries = get_inward_entries($search);
$warehouses = get_warehouses(null, 'active');
$vendors = get_vendors();
$catalog = inward_count_catalog();
$categories = get_categories('', 'active');

$defaultWarehouseId = 0;
foreach ($warehouses as $warehouse) {
    $label = strtolower((string) $warehouse['name'] . ' ' . (string) ($warehouse['code'] ?? ''));
    if (str_contains($label, 'central')) {
        $defaultWarehouseId = (int) $warehouse['id'];
        break;
    }
}
if ($defaultWarehouseId === 0 && $warehouses !== []) {
    $defaultWarehouseId = (int) $warehouses[0]['id'];
}

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .inw-tag-box:focus-within {
            border-color: #6366f1 !important;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15) !important;
        }
        #lineTable th, #lineTable td {
            vertical-align: top !important;
        }
        #lineTable td.inw-qty-cell, #lineTable th.inw-qty-header {
            text-align: center !important;
        }
        .inw-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            font-size: 12px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            line-height: 1.3;
        }
        .inw-chip-del {
            background: none;
            border: none;
            color: #3b82f6;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
            padding: 0 2px;
        }
        .inw-chip-del:hover {
            color: #ef4444;
        }
        .inw-tab-nav {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e2e8f0;
            padding: 0 20px;
            margin-bottom: 20px;
            background: #f8fafc;
        }
        .inw-tab-btn {
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            padding: 12px 18px;
            font-size: 13.5px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: -2px;
        }
        .inw-tab-btn:hover {
            color: #1e293b;
        }
        .inw-tab-btn.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            background: #ffffff;
            border-radius: 6px 6px 0 0;
        }
        .matrix-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .matrix-table th {
            background: #f1f5f9;
            color: #334155;
            font-weight: 700;
            text-align: center;
            padding: 10px 8px;
            border: 1px solid #cbd5e1;
            white-space: nowrap;
        }
        .matrix-table td {
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
            text-align: center;
            background: #ffffff;
        }
        .matrix-cell-input {
            width: 55px;
            text-align: center;
            font-weight: 700;
            font-size: 14px;
            padding: 6px 4px;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            transition: border 0.15s;
        }
        .matrix-cell-input:focus {
            border-color: #2563eb;
            outline: none;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
            background: #eff6ff;
        }
        .matrix-total-cell {
            font-weight: 700;
            color: #0f172a;
            background: #f8fafc !important;
        }
        .preset-size-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .preset-size-pill:hover {
            background: #dbeafe;
            border-color: #93c5fd;
        }
        .matrix-img-cell {
            width: 58px;
            min-width: 58px;
            max-width: 58px;
            text-align: center !important;
            padding: 4px !important;
            vertical-align: middle !important;
        }
        .matrix-img-picker {
            position: relative;
            width: 38px;
            height: 38px;
            margin: 0 auto;
        }
        .matrix-img-label {
            display: block;
            width: 38px;
            height: 38px;
            cursor: pointer;
            border-radius: 6px;
            overflow: hidden;
            margin: 0;
        }
        .matrix-img-preview {
            width: 100%;
            height: 100%;
            border: 1.5px dashed #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: border-color .15s, background .15s;
            box-sizing: border-box;
            overflow: hidden;
        }
        .matrix-img-preview:hover {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        .matrix-img-preview.has-img {
            border: 1px solid #cbd5e1;
            background: #fff;
        }
        .matrix-img-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .matrix-img-del-btn {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            background: #ef4444;
            color: #fff;
            border: 1px solid #fff;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.25);
            z-index: 5;
            padding: 0;
        }
        .matrix-img-del-btn:hover {
            background: #dc2626;
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>
            <main class="dashboard-content">
                <div class="page-header-row">
                    <div>
                        <h1 class="page-title">Inward Entry</h1>
                        <p class="page-subtitle">Warehouse count by product, size, colour, and quantity. Saving adds company stock at once. Ready stock goes to Central Warehouse only after QC, checking, and tagging, with no pass or fail.</p>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="alert alert-success" style="margin-bottom: 16px;"><?= e($flashSuccess) ?></div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div class="alert alert-error" style="margin-bottom: 16px;"><?= e($flashError) ?></div>
                <?php endif; ?>

                <?php if ($selected): ?>
                    <div class="section-card" style="margin-bottom: 18px;">
                        <div class="section-header">
                            <div>
                                <h2 class="section-heading"><?= e($selected['entry_number']) ?></h2>
                                <p class="section-subheading">
                                    <?= e((string) $selected['warehouse_name']) ?>
                                    · <?= e(date('d M Y', strtotime((string) $selected['entry_date']))) ?>
                                    <?php if (!empty($selected['vendor_name'])): ?>
                                        · <?= e((string) $selected['vendor_name']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($selected['counted_by_name'])): ?>
                                        · Counted by <?= e((string) $selected['counted_by_name']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <a href="<?= asset('inward-entry.php') ?>" class="btn-secondary" style="padding: 8px 14px;">Back to list</a>
                        </div>
                        <?php
                        $costPending = (string) ($selected['status'] ?? '') !== 'confirmed';
                        $purchaseConfirmed = (string) ($selected['purchase_status'] ?? 'pending') === 'confirmed';
                        $qcDone = (string) ($selected['qc_status'] ?? 'pending') === 'done';
                        $inwMode = (string) ($selected['inward_mode'] ?? 'standard');
                        ?>
                        <div style="padding: 0 20px 16px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                            <?php if ($inwMode === 'matrix'): ?>
                                <span class="badge-info" style="background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe;">Matrix Inward</span>
                            <?php elseif ($inwMode === 'import'): ?>
                                <span class="badge-info" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;">CSV Import</span>
                            <?php else: ?>
                                <span class="badge-secondary">Line Entry</span>
                            <?php endif; ?>
                            <?php if (!empty($selected['supplier_reference'])): ?>
                                <span style="font-size:12px; font-weight:700; color:#334155; background:#f1f5f9; padding:3px 8px; border-radius:4px; border:1px solid #cbd5e1; font-family:monospace;">Ref: <?= e((string)$selected['supplier_reference']) ?></span>
                            <?php endif; ?>
                            <?php if ($costPending): ?>
                                <span class="badge-warning">Cost pending</span>
                            <?php else: ?>
                                <span class="badge-success">Costs confirmed</span>
                            <?php endif; ?>
                            <?php if ($purchaseConfirmed): ?>
                                <span class="badge-success">Purchase confirmed</span>
                                <span class="badge-info">Barcode ready</span>
                            <?php else: ?>
                                <span class="badge-warning">Barcode not released</span>
                            <?php endif; ?>
                            <?php if ($qcDone): ?>
                                <span class="badge-success">Ready in Central</span>
                            <?php else: ?>
                                <span class="badge-secondary">Company stock</span>
                                <span class="badge-warning">Not ready in Central</span>
                            <?php endif; ?>
                            <?php if (!empty($selected['bill_number'])): ?>
                                <a href="<?= asset('bills.php?id=' . (int) $selected['bill_id']) ?>" class="badge-info" style="text-decoration:underline;">Bill <?= e((string) $selected['bill_number']) ?></a>
                            <?php elseif (!$costPending): ?>
                                <span class="badge-warning">Bill not created</span>
                            <?php endif; ?>
                            <?php if (!empty($selected['notes'])): ?>
                                <p style="margin: 12px 0 0; color: #475569;"><?= e((string) $selected['notes']) ?></p>
                            <?php endif; ?>
                            <?php if ($costPending): ?>
                                <p style="margin: 12px 0 0; color: #475569;">Purchase cost and selling price stay blank until you confirm them. Confirming cost does not release a barcode for the warehouse to print.</p>
                                <?php if (!$canInwardCost): ?>
                                    <p style="margin: 8px 0 0; color: #9a3412; font-weight: 650;">Cost fields are restricted for your role. An owner or a user with <strong>Inward Entry — cost &amp; confirm</strong> permission must enter costs.</p>
                                <?php endif; ?>
                            <?php elseif (!$purchaseConfirmed): ?>
                                <p style="margin: 12px 0 0; color: #475569;">Costs are saved. Confirm the purchase to release a barcode for each line so the warehouse can print labels. This quantity is already company stock.</p>
                            <?php elseif (!$qcDone): ?>
                                <p style="margin: 12px 0 0; color: #475569;">Print the barcodes, then finish QC, checking, and tagging. There is no pass or fail. Ready stock is added to Central Warehouse only.</p>
                            <?php else: ?>
                                <p style="margin: 12px 0 0; color: #475569;">QC, checking, and tagging is complete. Ready stock is in Central Warehouse only.</p>
                            <?php endif; ?>
                        </div>
                        <?php if ($costPending && $canInwardCost): ?>
                        <form method="post" id="costForm" style="padding: 0 20px 8px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="confirm_costs">
                            <input type="hidden" name="entry_id" value="<?= (int) $selected['id'] ?>">
                            <input type="hidden" name="prices_json" id="pricesJson" value="[]">
                            <?php if (empty($selected['vendor_id'])): ?>
                                <div class="form-group" style="margin: 0 0 12px; max-width: 320px;">
                                    <label class="form-label" for="confirm_vendor_id">Supplier</label>
                                    <?php if ($vendors === []): ?>
                                        <input type="text" name="vendor_name" id="confirm_vendor_id" class="form-control" required maxlength="191" placeholder="Supplier name" style="min-height: 48px; font-size: 18px;">
                                        <p style="margin: 8px 0 0; color: #475569;">No suppliers are saved for this business yet. Type the name and it is saved with the bill.</p>
                                    <?php else: ?>
                                        <select name="vendor_id" id="confirm_vendor_id" class="form-control" required>
                                            <option value="">Choose supplier</option>
                                            <?php foreach ($vendors as $vendor): ?>
                                                <option value="<?= (int) $vendor['id'] ?>"><?= e((string) $vendor['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($costPending && $canInwardCost && !empty($selected['lines'])): ?>
                            <!-- ⚡ QUICK FILL ALL VARIANTS BAR -->
                            <div class="quick-fill-bar" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 18px;">⚡</span>
                                    <div>
                                        <div style="font-weight: 700; color: #0f172a; font-size: 13.5px;">Quick Fill All Variants</div>
                                        <div style="font-size: 12px; color: #64748b;">Enter purchase cost and selling price once to populate all lines instantly.</div>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <label style="font-size: 12px; font-weight: 600; color: #475569; margin: 0;">Cost (₹):</label>
                                        <input type="number" step="0.01" min="0" id="quickFillCost" class="form-control" placeholder="0.00" style="width: 105px; height: 38px; font-weight: 700; text-align: right;">
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <label style="font-size: 12px; font-weight: 600; color: #475569; margin: 0;">Selling (₹):</label>
                                        <input type="number" step="0.01" min="0" id="quickFillPrice" class="form-control" placeholder="0.00" style="width: 105px; height: 38px; font-weight: 700; text-align: right;">
                                    </div>
                                    <button type="button" class="btn-secondary" onclick="applyQuickFillCostPrice(false)" style="height: 38px; padding: 0 14px; font-weight: 700; background: #2563eb; color: #ffffff; border-color: #2563eb; cursor: pointer;">
                                        ⚡ Apply to All Variants
                                    </button>
                                    <button type="button" class="btn-secondary" onclick="applyQuickFillCostPrice(true)" style="height: 38px; padding: 0 12px; font-size: 12px; font-weight: 600; cursor: pointer;">
                                        Apply to Blank Only
                                    </button>
                                    <span id="quickFillNotice" style="font-size: 12.5px; color: #16a34a; font-weight: 700; display: none;"></span>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>SKU</th>
                                        <th>Size</th>
                                        <th>Colour</th>
                                        <th>Quantity</th>
                                        <th>Purchase cost</th>
                                        <th>Selling price</th>
                                        <th>Barcode</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($selected['lines'] as $line): ?>
                                        <tr data-line-id="<?= (int) $line['id'] ?>">
                                            <td><strong><?= e((string) $line['product_name']) ?></strong></td>
                                            <td><code style="font-size:12px; font-weight:700; color:#1d4ed8; background:#eff6ff; padding:2px 6px; border-radius:4px;"><?= e((string) ($line['sku'] ?: ($line['product_sku'] ?? ''))) ?></code></td>
                                            <td><?= e((string) $line['size']) ?></td>
                                            <td><?= e((string) $line['colour']) ?></td>
                                            <td><?= (int) $line['quantity'] ?></td>
                                            <?php if ($costPending && $canInwardCost): ?>
                                                <?php
                                                    $preCost = ($line['purchase_cost'] !== null && (float)$line['purchase_cost'] > 0) ? (string)(float)$line['purchase_cost'] : '';
                                                    $prePrice = ($line['selling_price'] !== null && (float)$line['selling_price'] > 0) ? (string)(float)$line['selling_price'] : '';
                                                ?>
                                                <td>
                                                    <div style="display:flex; align-items:center; gap:6px;">
                                                        <input type="text" inputmode="decimal" class="form-control inw-cost" autocomplete="off" placeholder="Blank" value="<?= e($preCost) ?>" style="min-height: 44px; font-size: 16px; font-weight:600; text-align:right;">
                                                        <button type="button" class="btn-secondary" title="Copy this cost to all rows below" onclick="copyCostDown(this)" style="padding: 6px 8px; font-size: 12px; cursor: pointer; color: #475569;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background=''">⬇</button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="display:flex; align-items:center; gap:6px;">
                                                        <input type="text" inputmode="decimal" class="form-control inw-price" autocomplete="off" placeholder="Blank" value="<?= e($prePrice) ?>" style="min-height: 44px; font-size: 16px; font-weight:600; text-align:right;">
                                                        <button type="button" class="btn-secondary" title="Copy this selling price to all rows below" onclick="copyPriceDown(this)" style="padding: 6px 8px; font-size: 12px; cursor: pointer; color: #475569;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background=''">⬇</button>
                                                    </div>
                                                </td>
                                            <?php elseif ($costPending): ?>
                                                <td colspan="2" style="color:#64748b; font-weight:700;">🔒 Restricted</td>
                                            <?php else: ?>
                                                <td><?= $line['purchase_cost'] === null ? '—' : '₹' . number_format((float) $line['purchase_cost'], 2) ?></td>
                                                <td><?= $line['selling_price'] === null ? '—' : '₹' . number_format((float) $line['selling_price'], 2) ?></td>
                                            <?php endif; ?>
                                            <td><?= $purchaseConfirmed && trim((string) ($line['barcode'] ?? '')) !== '' ? e((string) $line['barcode']) : 'Not released' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($selected['lines'])): ?>
                                        <tr><td colspan="8" style="text-align:center; padding: 24px; color:#64748b;">No lines on this count.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($costPending && $canInwardCost && !empty($selected['lines'])): ?>
                            <div style="padding: 14px 0 16px; display:flex; justify-content: flex-end;">
                                <button type="submit" class="header-btn" style="padding: 12px 18px; min-height: 48px;">Confirm costs</button>
                            </div>
                        <?php endif; ?>
                        <?php if ($costPending && $canInwardCost): ?>
                        </form>
                        <?php elseif (!$purchaseConfirmed && !empty($selected['lines'])): ?>
                        <form method="post" style="padding: 0 20px 16px; display:flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="confirm_purchase">
                            <input type="hidden" name="entry_id" value="<?= (int) $selected['id'] ?>">
                            <button type="submit" class="header-btn" style="padding: 12px 18px; min-height: 48px;">Confirm purchase</button>
                        </form>
                        <?php elseif ($purchaseConfirmed): ?>
                        <div style="padding: 0 20px 16px; display:flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap;">
                            <a href="<?= asset('barcode-print.php?inward=' . (int) $selected['id']) ?>" class="header-btn" style="padding: 12px 18px; min-height: 48px; text-decoration: none; display: inline-flex; align-items: center;">Print barcodes</a>
                            <?php if (!$qcDone): ?>
                            <form method="post" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="complete_qc">
                                <input type="hidden" name="entry_id" value="<?= (int) $selected['id'] ?>">
                                <button type="submit" class="header-btn" style="padding: 12px 18px; min-height: 48px;">QC, checking, and tagging complete</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!$costPending && empty($selected['bill_id'])): ?>
                        <form method="post" style="padding: 0 20px 16px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_bill">
                            <input type="hidden" name="entry_id" value="<?= (int) $selected['id'] ?>">
                            <div style="display:flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                                <?php if (empty($selected['vendor_id'])): ?>
                                    <div class="form-group" style="margin: 0; min-width: 220px;">
                                        <label class="form-label" for="bill_vendor_id">Supplier</label>
                                        <?php if ($vendors === []): ?>
                                            <input type="text" name="vendor_name" id="bill_vendor_id" class="form-control" required maxlength="191" placeholder="Supplier name">
                                            <p style="margin: 8px 0 0; color: #475569;">No suppliers are saved yet. Type the name, then create the bill. It will show here and under Purchases → Bills.</p>
                                        <?php else: ?>
                                            <select name="vendor_id" id="bill_vendor_id" class="form-control" required>
                                                <option value="">Choose supplier</option>
                                                <?php foreach ($vendors as $vendor): ?>
                                                    <option value="<?= (int) $vendor['id'] ?>"><?= e((string) $vendor['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <button type="submit" class="header-btn" style="padding: 10px 16px;">Create bill</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php $activeTab = trim((string)($_GET['tab'] ?? 'matrix')); ?>
                    <div class="section-card" style="margin-bottom: 18px;">
                        <div class="inw-tab-nav">
                            <button type="button" class="inw-tab-btn <?= $activeTab === 'matrix' ? 'active' : '' ?>" id="tabBtnMatrix" onclick="switchInwardTab('matrix')">
                                <span style="font-size:16px;">⊞</span> Size × Colour Matrix Entry
                            </button>
                            <button type="button" class="inw-tab-btn <?= $activeTab === 'lines' ? 'active' : '' ?>" id="tabBtnLines" onclick="switchInwardTab('lines')">
                                <span style="font-size:16px;">≡</span> Standard Line Entry
                            </button>
                            <button type="button" class="inw-tab-btn <?= $activeTab === 'import' ? 'active' : '' ?>" id="tabBtnImport" onclick="switchInwardTab('import')">
                                <span style="font-size:16px;">📥</span> Bulk CSV Import
                            </button>
                        </div>

                        <!-- 1. SIZE x COLOUR MATRIX PANE -->
                        <div id="tabPaneMatrix" style="<?= $activeTab === 'matrix' ? 'display:block;' : 'display:none;' ?>">
                            <div class="section-header" style="padding-top: 0;">
                                <div>
                                    <h2 class="section-heading">Size × Colour Matrix Inward</h2>
                                    <p class="section-subheading">Fast bulk entry for design products across all colour &amp; size variants. Auto-generates unique 3-tier SKUs and posts company stock.</p>
                                </div>
                            </div>
                            <form method="post" id="matrixInwardForm" style="padding: 0 20px 20px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_matrix_inward">
                                <input type="hidden" name="matrix_json" id="matrixJson" value="{}">
                                
                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 16px;">
                                    <div class="form-group">
                                        <label class="form-label" for="matrix_warehouse_id">Warehouse <span style="color:#ef4444;">*</span></label>
                                        <select name="warehouse_id" id="matrix_warehouse_id" class="form-control" required>
                                            <?php foreach ($warehouses as $warehouse): ?>
                                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $defaultWarehouseId ? 'selected' : '' ?>>
                                                    <?= e((string) $warehouse['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="matrix_vendor_id">Supplier</label>
                                        <?php if ($vendors === []): ?>
                                            <input type="text" name="vendor_name" id="matrix_vendor_id" class="form-control" maxlength="191" placeholder="Supplier name">
                                        <?php else: ?>
                                            <select name="vendor_id" id="matrix_vendor_id" class="form-control">
                                                <option value="">Not linked</option>
                                                <?php foreach ($vendors as $vendor): ?>
                                                    <option value="<?= (int) $vendor['id'] ?>"><?= e((string) $vendor['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="matrix_entry_date">Receipt Date <span style="color:#ef4444;">*</span></label>
                                        <input type="date" name="entry_date" id="matrix_entry_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="matrix_supplier_ref">Supplier Bill / Doc Ref (Optional)</label>
                                        <input type="text" name="supplier_reference" id="matrix_supplier_ref" class="form-control" placeholder="e.g. INV-1092 / DC-441">
                                    </div>
                                </div>

                                <!-- DYNAMIC MULTI-PRODUCT CONTAINER -->
                                <div id="matrixProductsContainer" style="display: flex; flex-direction: column; gap: 20px; margin-bottom: 16px;">
                                    <!-- Rendered dynamically by JavaScript -->
                                </div>

                                <!-- ADD ANOTHER PRODUCT BUTTON -->
                                <div style="margin: 16px 0 24px; display: flex; justify-content: flex-start;">
                                    <button type="button" onclick="addNewMatrixProductCard()" class="btn-secondary" style="border: 2px dashed #93c5fd; background: #eff6ff; color: #1d4ed8; font-weight: 700; padding: 12px 24px; border-radius: 8px; font-size: 13.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">
                                        <span style="font-size: 18px; font-weight: 800; line-height: 1;">+</span> Add Another Product to this Inward
                                    </button>
                                </div>

                                <!-- MATRIX SUMMARY & SUBMIT -->
                                <div style="display:flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-top: 16px; padding-top: 16px; border-top: 2px solid #e2e8f0;">
                                    <div style="font-size: 12.5px; color: #64748b;">
                                        <span style="font-weight: 600; color: #334155;">💡 Tip:</span> All variants and stock across all added products will be created and posted together in a single receipt.
                                    </div>
                                    <div style="display:flex; align-items: center; gap: 14px;">
                                        <div style="font-size: 14px; font-weight: 700; color: #1e293b; background: #f1f5f9; padding: 10px 18px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                            Total: <span id="matrixSummaryProductsCount" style="color:#0f172a;">1</span> Product(s) · <span id="matrixSummaryQty" style="color:#2563eb;">0</span> pcs · ₹<span id="matrixSummaryCost" style="color:#16a34a;">0.00</span>
                                        </div>
                                        <button type="submit" class="header-btn" style="padding: 11px 24px; font-weight:700; font-size: 15px;">Save Matrix Count</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- 2. STANDARD LINE ENTRY PANE (Preserved untouched) -->
                        <div id="tabPaneLines" style="<?= $activeTab === 'lines' ? 'display:block;' : 'display:none;' ?>">
                            <div class="section-header" style="padding-top: 0;">
                                <div>
                                    <h2 class="section-heading">Standard Line Inward Entry</h2>
                                    <p class="section-subheading">Counted by <?= e((string) ($user['name'] ?? 'you')) ?>. Add items line by line with tag-based sizes and colours.</p>
                                </div>
                            </div>
                            <form method="post" id="inwardForm" style="padding: 0 20px 20px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_inward">
                                <input type="hidden" name="lines_json" id="linesJson" value="[]">
                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 16px;">
                                    <div class="form-group">
                                        <label class="form-label" for="warehouse_id">Warehouse</label>
                                        <select name="warehouse_id" id="warehouse_id" class="form-control" required>
                                            <?php foreach ($warehouses as $warehouse): ?>
                                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $defaultWarehouseId ? 'selected' : '' ?>>
                                                    <?= e((string) $warehouse['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="vendor_id">Supplier</label>
                                        <?php if ($vendors === []): ?>
                                            <input type="text" name="vendor_name" id="vendor_id" class="form-control" maxlength="191" placeholder="Type a supplier name, or leave blank">
                                        <?php else: ?>
                                            <select name="vendor_id" id="vendor_id" class="form-control">
                                                <option value="">Not linked</option>
                                                <?php foreach ($vendors as $vendor): ?>
                                                    <option value="<?= (int) $vendor['id'] ?>"><?= e((string) $vendor['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="entry_date">Count date</label>
                                        <input type="date" name="entry_date" id="entry_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom: 16px;">
                                    <label class="form-label" for="notes">Notes</label>
                                    <input type="text" name="notes" id="notes" class="form-control" placeholder="Optional note for this count">
                                </div>

                                <div class="table-wrap">
                                    <table class="saas-table" id="lineTable" style="width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="min-width: 240px; text-align: left;">Product <button type="button" onclick="openQuickProductModal(null)" style="background: none; border: none; color: #2563eb; font-size: 11.5px; font-weight: 700; cursor: pointer; text-decoration: underline; margin-left: 6px;" title="Add New Product to Catalog">+ New Product</button></th>
                                                <th style="width: 200px; min-width: 160px; text-align: left;">Size</th>
                                                <th style="width: 200px; min-width: 160px; text-align: left;">Colour</th>
                                                <th style="width: 110px; min-width: 100px; text-align: center;" class="inw-qty-header">Quantity</th>
                                                <th style="width: 80px; min-width: 70px; text-align: center;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="lineBody"></tbody>
                                    </table>
                                </div>
                                <div style="display:flex; justify-content: space-between; gap: 12px; margin-top: 14px; flex-wrap: wrap;">
                                    <button type="button" class="btn-secondary" id="addLineBtn" style="padding: 8px 14px;">Add line</button>
                                    <button type="submit" class="header-btn" style="padding: 10px 18px;">Save count</button>
                                </div>
                            </form>
                        </div>

                        <!-- 3. BULK CSV IMPORT PANE -->
                        <div id="tabPaneImport" style="<?= $activeTab === 'import' ? 'display:block;' : 'display:none;' ?>">
                            <div class="section-header" style="padding-top: 0;">
                                <div>
                                    <h2 class="section-heading">Bulk Inward via Spreadsheet / CSV</h2>
                                    <p class="section-subheading">One row per design and colour, with size quantities in columns (S, M, L, XL, XXL). Imports goods-receipt transaction without overwriting on-hand balances.</p>
                                </div>
                                <a href="<?= asset('inward-entry.php?action=download_matrix_template') ?>" class="btn-secondary" style="padding: 8px 14px; display:inline-flex; align-items:center; gap:6px;">
                                    <span>📥</span> Download Sample CSV Template
                                </a>
                            </div>
                            <form method="post" enctype="multipart/form-data" id="csvImportForm" style="padding: 0 20px 20px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="import_matrix_csv">
                                
                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 16px;">
                                    <div class="form-group">
                                        <label class="form-label" for="import_warehouse_id">Target Warehouse <span style="color:#ef4444;">*</span></label>
                                        <select name="warehouse_id" id="import_warehouse_id" class="form-control" required>
                                            <?php foreach ($warehouses as $warehouse): ?>
                                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $defaultWarehouseId ? 'selected' : '' ?>>
                                                    <?= e((string) $warehouse['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="import_vendor_id">Supplier (Default if omitted in CSV)</label>
                                        <select name="vendor_id" id="import_vendor_id" class="form-control">
                                            <option value="">From CSV or none</option>
                                            <?php foreach ($vendors as $vendor): ?>
                                                <option value="<?= (int) $vendor['id'] ?>"><?= e((string) $vendor['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="import_entry_date">Receipt Date</label>
                                        <input type="date" name="entry_date" id="import_entry_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="import_supplier_ref">Supplier Document / Invoice Ref</label>
                                        <input type="text" name="supplier_reference" id="import_supplier_ref" class="form-control" placeholder="Blocks duplicate re-imports">
                                    </div>
                                </div>

                                <div class="form-group" style="margin-bottom: 16px;">
                                    <label class="form-label" style="font-weight:700;">Upload CSV File</label>
                                    <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv,text/csv">
                                    <p style="margin: 6px 0 0; font-size: 12px; color: #64748b;">
                                        Expected columns: <code>Design Code, Colour, Unit Cost, S, M, L, XL, XXL</code> (or include Supplier, Date, Warehouse).
                                    </p>
                                </div>

                                <div class="form-group" style="margin-bottom: 16px;">
                                    <label class="form-label" style="font-weight:600;">Or Paste CSV Content:</label>
                                    <textarea name="csv_text" id="csv_text" class="form-control" rows="5" placeholder="Supplier,Receipt Date,Warehouse,Supplier Reference,Design Code,Colour,Unit Cost,S,M,L,XL,XXL&#10;Ash Wholesale,<?= date('Y-m-d') ?>,Central Warehouse,BILL-101,AC125,Maroon,250,4,8,10,6,2&#10;Ash Wholesale,<?= date('Y-m-d') ?>,Central Warehouse,BILL-101,AC125,Black,250,5,8,12,5,0&#10;Ash Wholesale,<?= date('Y-m-d') ?>,Central Warehouse,BILL-101,AC125,Bottle Green,250,3,10,15,8,4"></textarea>
                                </div>

                                <div style="display:flex; justify-content: flex-end; gap: 10px; margin-top: 14px;">
                                    <button type="submit" class="header-btn" style="padding: 10px 22px; font-weight:700;">Post CSV Inward</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h2 class="section-heading">Saved counts</h2>
                            <p class="section-subheading">Received quantity is company stock. Ready stock appears in Central only after QC, checking, and tagging.</p>
                        </div>
                        <form method="get" style="display:flex; gap: 8px;">
                            <input type="text" name="search" value="<?= e($search) ?>" class="form-control" placeholder="Search entry, warehouse, supplier">
                            <button type="submit" class="header-btn" style="padding: 8px 14px;">Search</button>
                        </form>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Entry</th>
                                    <th>Date</th>
                                    <th>Warehouse</th>
                                    <th>Supplier</th>
                                    <th>Mode / Ref</th>
                                    <th>Lines</th>
                                    <th>Quantity</th>
                                    <th>Bill</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($entries === []): ?>
                                    <tr><td colspan="9" style="text-align:center; padding: 28px; color:#64748b;">No inward counts yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($entries as $entry): ?>
                                        <tr>
                                            <td><a href="<?= asset('inward-entry.php?id=' . (int) $entry['id']) ?>"><strong><?= e((string) $entry['entry_number']) ?></strong></a></td>
                                            <td><?= e(date('d M Y', strtotime((string) $entry['entry_date']))) ?></td>
                                            <td><?= e((string) ($entry['warehouse_name'] ?? '')) ?></td>
                                            <td><?= e((string) ($entry['vendor_name'] ?? '—')) ?></td>
                                            <td>
                                                <?php $mode = (string)($entry['inward_mode'] ?? 'standard'); ?>
                                                <?php if ($mode === 'matrix'): ?>
                                                    <span style="display:inline-block; font-size:11px; font-weight:700; padding:2px 6px; border-radius:3px; background:#e0e7ff; color:#3730a3;">Matrix</span>
                                                <?php elseif ($mode === 'import'): ?>
                                                    <span style="display:inline-block; font-size:11px; font-weight:700; padding:2px 6px; border-radius:3px; background:#fef3c7; color:#92400e;">CSV</span>
                                                <?php else: ?>
                                                    <span style="display:inline-block; font-size:11px; font-weight:600; padding:2px 6px; border-radius:3px; background:#f1f5f9; color:#475569;">Line</span>
                                                <?php endif; ?>
                                                <?php if (!empty($entry['supplier_reference'])): ?>
                                                    <div style="font-size:11px; color:#64748b; font-weight:600; font-family:monospace; margin-top:2px;"><?= e((string)$entry['supplier_reference']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= (int) $entry['line_count'] ?></td>
                                            <td><?= (int) $entry['total_qty'] ?></td>
                                            <td>
                                                <?php if (!empty($entry['bill_number'])): ?>
                                                    <a href="<?= asset('bills.php?id=' . (int) $entry['bill_id']) ?>" style="font-weight:700; text-decoration:underline;"><?= e((string) $entry['bill_number']) ?></a>
                                                <?php elseif ((string) ($entry['status'] ?? '') === 'confirmed'): ?>
                                                    <a href="<?= asset('inward-entry.php?id=' . (int) $entry['id']) ?>">Create bill</a>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((string) ($entry['status'] ?? '') === 'confirmed'): ?>
                                                    <span class="badge-success">Costs confirmed</span>
                                                <?php else: ?>
                                                    <span class="badge-warning">Cost pending</span>
                                                <?php endif; ?>
                                                <?php if ((string) ($entry['purchase_status'] ?? 'pending') === 'confirmed'): ?>
                                                    <a href="<?= asset('barcode-print.php?inward=' . (int) $entry['id']) ?>" class="badge-info" style="text-decoration:underline;">Barcode ready</a>
                                                <?php else: ?>
                                                    <span class="badge-warning">Barcode not released</span>
                                                <?php endif; ?>
                                                <?php if ((string) ($entry['qc_status'] ?? 'pending') === 'done'): ?>
                                                    <span class="badge-success">Ready in Central</span>
                                                <?php else: ?>
                                                    <span class="badge-secondary">Company stock</span>
                                                <?php endif; ?>
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
    <!-- Quick Add Product Modal -->
    <div id="quickProductModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(2px); z-index: 10000; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: 12px; width: 100%; max-width: 480px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); overflow: hidden;">
            <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <span style="color: #2563eb;">📦</span> Add New Product
                </h3>
                <button type="button" onclick="closeQuickProductModal()" style="background: none; border: none; font-size: 24px; color: #64748b; cursor: pointer; line-height: 1; padding: 0 4px;">&times;</button>
            </div>
            <form id="quickProductForm" style="padding: 20px;">
                <div id="quickProductError" style="display: none; padding: 10px 14px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 6px; color: #b91c1c; font-size: 13px; margin-bottom: 14px;"></div>
                
                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-weight: 700; font-size: 13px; margin-bottom: 4px;">Product Name <span style="color: #ef4444;">*</span></label>
                    <input type="text" id="qp_name" class="form-control" required placeholder="e.g. Cotton Round Neck T-Shirt" autofocus>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700; font-size: 13px; margin-bottom: 4px;">SKU (Optional)</label>
                        <input type="text" id="qp_sku" class="form-control" placeholder="Auto-generated if blank">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700; font-size: 13px; margin-bottom: 4px;">Barcode (Optional)</label>
                        <input type="text" id="qp_barcode" class="form-control" placeholder="Scan or type barcode">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-weight: 700; font-size: 13px; margin-bottom: 4px;">Category (Optional)</label>
                    <select id="qp_category_id" class="form-control">
                        <option value="">General Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= e((string)$cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700; font-size: 13px; margin-bottom: 4px;">Approx Cost (₹)</label>
                        <input type="number" step="0.01" min="0" id="qp_cost_price" class="form-control" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700; font-size: 13px; margin-bottom: 4px;">Selling Price (₹)</label>
                        <input type="number" step="0.01" min="0" id="qp_selling_price" class="form-control" placeholder="0.00">
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #f1f5f9; padding-top: 14px;">
                    <button type="button" class="btn-secondary" onclick="closeQuickProductModal()" style="padding: 8px 16px;">Cancel</button>
                    <button type="submit" id="qp_submit_btn" class="header-btn" style="padding: 8px 20px;">Save &amp; Select</button>
                </div>
            </form>
        </div>
    </div>

    <datalist id="matrixColourDatalist">
        <option value="Maroon">
        <option value="Black">
        <option value="Bottle Green">
        <option value="Navy Blue">
        <option value="Red">
        <option value="White">
        <option value="Mustard">
        <option value="Peach">
        <option value="Teal">
        <option value="Pink">
        <option value="Yellow">
        <option value="Olive">
        <option value="Grey">
        <option value="Orange">
        <option value="Lavender">
        <option value="Wine">
        <option value="Rust">
        <option value="Beige">
    </datalist>

    <script>
        const inwardCatalog = <?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const lineBody = document.getElementById('lineBody');
        const addLineBtn = document.getElementById('addLineBtn');
        const inwardForm = document.getElementById('inwardForm');

        function productById(id) {
            return inwardCatalog.find(function (product) { return product.id === id; }) || null;
        }

        function uniqueValues(variants, key) {
            const seen = [];
            variants.forEach(function (variant) {
                if (seen.indexOf(variant[key]) === -1) seen.push(variant[key]);
            });
            return seen;
        }

        function fillSelect(select, values, placeholder) {
            select.innerHTML = '';
            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = placeholder;
            select.appendChild(blank);
            values.forEach(function (value) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                select.appendChild(option);
            });
        }

        let activeRowForNewProduct = null;

        function populateProductSelect(productSelect, selectedId) {
            productSelect.innerHTML = '';
            
            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = 'Product';
            productSelect.appendChild(blank);

            const addNewOpt = document.createElement('option');
            addNewOpt.value = '__add_new__';
            addNewOpt.textContent = '+ Add New Product...';
            addNewOpt.style.color = '#2563eb';
            addNewOpt.style.fontWeight = '700';
            addNewOpt.style.backgroundColor = '#eff6ff';
            productSelect.appendChild(addNewOpt);

            inwardCatalog.forEach(function (product) {
                const option = document.createElement('option');
                option.value = String(product.id);
                option.textContent = product.sku ? product.name + ' (' + product.sku + ')' : product.name;
                productSelect.appendChild(option);
            });

            if (selectedId) {
                productSelect.value = String(selectedId);
            }
        }

        function createTagInput(cell, placeholder, isSize) {
            if (!cell) return null;
            cell.innerHTML = '';
            cell.className = isSize ? 'inw-size-cell' : 'inw-colour-cell';
            cell.style.verticalAlign = 'top';

            const box = document.createElement('div');
            box.className = 'inw-tag-box ' + (isSize ? 'inw-size-box' : 'inw-colour-box');
            box.style.cssText = 'display:flex; flex-wrap:wrap; align-items:center; gap:4px; padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; background:#ffffff; min-height:38px; cursor:text; box-sizing:border-box; width:100%;';

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'inw-tag-input ' + (isSize ? 'inw-size-input' : 'inw-colour-input');
            input.placeholder = placeholder;
            input.style.cssText = 'border:none; outline:none; font-size:13px; flex:1; min-width:60px; padding:2px 0; background:transparent;';
            box.appendChild(input);
            cell.appendChild(box);

            function addChip(val) {
                val = (val || '').trim();
                if (!val) return;
                const existing = Array.from(box.querySelectorAll('.inw-chip')).map(function (c) {
                    return c.getAttribute('data-val').toUpperCase();
                });
                if (existing.indexOf(val.toUpperCase()) !== -1) return;

                const chip = document.createElement('span');
                chip.className = 'inw-chip';
                chip.setAttribute('data-val', val);
                chip.style.cssText = 'display:inline-flex; align-items:center; gap:4px; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-size:12px; font-weight:700; padding:2px 7px; border-radius:4px; line-height:1.3;';
                chip.innerHTML = '<span>' + val + '</span><button type="button" class="inw-chip-del" style="background:none; border:none; color:#3b82f6; cursor:pointer; font-size:14px; line-height:1; padding:0 2px;">&times;</button>';
                
                chip.querySelector('.inw-chip-del').addEventListener('click', function (e) {
                    e.stopPropagation();
                    chip.remove();
                    checkSplitBtn();
                });

                box.insertBefore(chip, input);
                checkSplitBtn();
            }

            function checkSplitBtn() {
                if (!isSize) return;
                const row = cell.closest('tr');
                if (!row) return;
                const chips = box.querySelectorAll('.inw-chip');
                let splitBtn = cell.querySelector('.btn-split-row');
                if (chips.length > 1) {
                    if (!splitBtn) {
                        splitBtn = document.createElement('button');
                        splitBtn.type = 'button';
                        splitBtn.className = 'btn-split-row';
                        splitBtn.style.cssText = 'background:none; border:none; color:#2563eb; font-size:11px; font-weight:600; cursor:pointer; padding:3px 0; text-decoration:underline; display:block; margin-top:3px;';
                        splitBtn.textContent = '⚡ Split into ' + chips.length + ' rows';
                        splitBtn.addEventListener('click', function () {
                            splitRowBySizes(row);
                        });
                        cell.appendChild(splitBtn);
                    } else {
                        splitBtn.textContent = '⚡ Split into ' + chips.length + ' rows';
                    }
                } else if (splitBtn) {
                    splitBtn.remove();
                }
            }

            box.addEventListener('click', function (e) {
                if (e.target !== input && !e.target.closest('.inw-chip')) {
                    input.focus();
                }
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    e.stopPropagation();
                    const text = input.value;
                    input.value = '';
                    text.split(',').forEach(function (part) {
                        addChip(part);
                    });
                } else if (e.key === 'Backspace' && input.value === '') {
                    const chips = box.querySelectorAll('.inw-chip');
                    if (chips.length > 0) {
                        chips[chips.length - 1].remove();
                        checkSplitBtn();
                    }
                }
            });

            input.addEventListener('blur', function () {
                if (input.value.trim()) {
                    const text = input.value;
                    input.value = '';
                    text.split(',').forEach(function (part) {
                        addChip(part);
                    });
                }
            });

            function getValues() {
                const values = Array.from(box.querySelectorAll('.inw-chip')).map(function (c) {
                    return c.getAttribute('data-val');
                });
                if (input.value.trim()) {
                    input.value.trim().split(',').forEach(function (v) {
                        const t = v.trim();
                        if (t && values.indexOf(t) === -1) values.push(t);
                    });
                }
                return values;
            }

            box.getValues = getValues;
            box.addChip = addChip;
            cell.getValues = getValues;
            cell.addChip = addChip;
            return box;
        }

        function splitRowBySizes(row) {
            const sizeCell = row.querySelector('.inw-size-cell');
            const colourCell = row.querySelector('.inw-colour-cell');
            if (!sizeCell) return;

            const sizes = sizeCell.getValues ? sizeCell.getValues() : [];
            if (sizes.length <= 1) return;

            const colours = colourCell && colourCell.getValues ? colourCell.getValues() : [];
            const colourVal = colours.length > 0 ? colours[0] : '';
            const pid = row.querySelector('.inw-product').value;
            const qty = row.querySelector('.inw-qty').value || '1';

            // Keep first size on this row
            createTagInput(sizeCell, 'Type size (e.g. L) & Enter', true);
            sizeCell.addChip(sizes[0]);

            // Add new rows for remaining sizes
            for (let i = 1; i < sizes.length; i++) {
                const newRow = addLine();
                const sel = newRow.querySelector('.inw-product');
                if (sel) {
                    sel.value = pid;
                    sel.dataset.prevValue = pid;
                    syncLine(newRow);
                    const newSizeCell = newRow.querySelector('.inw-size-cell');
                    if (newSizeCell) {
                        createTagInput(newSizeCell, 'Type size (e.g. L) & Enter', true);
                        newSizeCell.addChip(sizes[i]);
                    }
                    const newColCell = newRow.querySelector('.inw-colour-cell');
                    if (newColCell && colourVal) {
                        newColCell.addChip(colourVal);
                    }
                    newRow.querySelector('.inw-qty').value = qty;
                }
            }
        }

        function syncLine(row) {
            const product = productById(parseInt(row.querySelector('.inw-product').value || '0', 10));
            const sizeCell = row.querySelector('.inw-size-cell');
            const colourCell = row.querySelector('.inw-colour-cell');
            const variants = product && product.variants ? product.variants : [];

            // Clean up any old fill-all button or split button
            const oldFillBtn = row.querySelector('.inw-fill-variants-btn');
            if (oldFillBtn) oldFillBtn.remove();
            const oldSplitBtn = row.querySelector('.btn-split-row');
            if (oldSplitBtn) oldSplitBtn.remove();

            if (!product || variants.length === 0) {
                // Interactive Tag Input for Size: user can type 'l' [Enter], 'm' [Enter], etc.!
                createTagInput(sizeCell, 'Type size (e.g. L) & Enter', true);
                createTagInput(colourCell, 'Colour (optional)', false);
                return;
            }

            const sizes = uniqueValues(variants, 'size');
            sizeCell.innerHTML = '<select class="form-control inw-size" style="width: 100%;"></select>';
            colourCell.innerHTML = '<select class="form-control inw-colour" style="width: 100%;"></select>';
            const sizeSelect = sizeCell.querySelector('.inw-size');
            const colourSelect = colourCell.querySelector('.inw-colour');
            fillSelect(sizeSelect, sizes, 'Size');
            fillSelect(colourSelect, [], 'Colour');
            sizeSelect.addEventListener('change', function () {
                const colours = variants.filter(function (variant) {
                    return variant.size === sizeSelect.value;
                }).map(function (variant) { return variant.colour; });
                fillSelect(colourSelect, uniqueValues(colours.map(function (colour) { return { colour: colour }; }), 'colour'), 'Colour');
                if (colourSelect.options.length === 2) {
                    colourSelect.selectedIndex = 1;
                }
            });
            if (sizes.length === 1) {
                sizeSelect.selectedIndex = 1;
                sizeSelect.dispatchEvent(new Event('change'));
            }

            if (variants.length > 1) {
                const fillBtn = document.createElement('button');
                fillBtn.type = 'button';
                fillBtn.className = 'inw-fill-variants-btn';
                fillBtn.style.cssText = 'background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-size:11px; font-weight:600; padding:2px 7px; border-radius:4px; cursor:pointer; margin-top:4px; display:inline-flex; align-items:center; gap:4px;';
                fillBtn.title = 'Add all ' + variants.length + ' size/colour combinations as separate rows';
                fillBtn.innerHTML = '⚡ Add all ' + variants.length + ' sizes/colours';
                fillBtn.addEventListener('click', function () {
                    sizeSelect.value = variants[0].size;
                    sizeSelect.dispatchEvent(new Event('change'));
                    colourSelect.value = variants[0].colour;

                    for (let vi = 1; vi < variants.length; vi++) {
                        const v = variants[vi];
                        const extraRow = addLine();
                        const sel = extraRow.querySelector('.inw-product');
                        if (sel) {
                            sel.value = String(product.id);
                            sel.dataset.prevValue = String(product.id);
                            syncLine(extraRow);
                            const eSz = extraRow.querySelector('.inw-size');
                            if (eSz) {
                                eSz.value = v.size;
                                eSz.dispatchEvent(new Event('change'));
                            }
                            const eCol = extraRow.querySelector('.inw-colour');
                            if (eCol) eCol.value = v.colour;
                        }
                    }
                });
                row.cells[0].appendChild(fillBtn);
            }
        }

        let activeMatrixCardForNewProduct = null;

        function openQuickProductModal(row, matrixCardId) {
            activeRowForNewProduct = row || null;
            activeMatrixCardForNewProduct = matrixCardId || null;
            const errDiv = document.getElementById('quickProductError');
            if (errDiv) {
                errDiv.style.display = 'none';
                errDiv.textContent = '';
            }
            const form = document.getElementById('quickProductForm');
            if (form) form.reset();

            const modal = document.getElementById('quickProductModal');
            if (modal) {
                modal.style.display = 'flex';
                setTimeout(function () {
                    const nameInput = document.getElementById('qp_name');
                    if (nameInput) nameInput.focus();
                }, 50);
            }
        }

        function closeQuickProductModal() {
            const modal = document.getElementById('quickProductModal');
            if (modal) modal.style.display = 'none';
            activeRowForNewProduct = null;
            activeMatrixCardForNewProduct = null;
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeQuickProductModal();
            }
        });

        const quickProductModal = document.getElementById('quickProductModal');
        if (quickProductModal) {
            quickProductModal.addEventListener('click', function (e) {
                if (e.target === quickProductModal) {
                    closeQuickProductModal();
                }
            });
        }

        function addLine() {
            const row = document.createElement('tr');
            row.innerHTML = ''
                + '<td style="vertical-align: top; padding: 10px 14px;"><select class="form-control inw-product" style="width: 100%;"></select></td>'
                + '<td class="inw-size-cell" style="vertical-align: top; padding: 10px 14px; width: 200px;"></td>'
                + '<td class="inw-colour-cell" style="vertical-align: top; padding: 10px 14px; width: 200px;"></td>'
                + '<td class="inw-qty-cell" style="vertical-align: top; padding: 10px 14px; width: 110px; text-align: center;">'
                + '    <input type="number" class="form-control inw-qty" min="1" step="1" value="1" style="width: 100%; text-align: center; font-weight: 600;">'
                + '</td>'
                + '<td style="vertical-align: top; padding: 10px 14px; width: 80px; text-align: center;">'
                + '    <button type="button" class="btn-secondary inw-remove" style="padding: 6px 10px; font-size: 12px;">Remove</button>'
                + '</td>';
            const productSelect = row.querySelector('.inw-product');
            populateProductSelect(productSelect);

            productSelect.addEventListener('change', function () {
                if (this.value === '__add_new__') {
                    this.value = this.dataset.prevValue || '';
                    openQuickProductModal(row);
                    return;
                }
                this.dataset.prevValue = this.value;
                syncLine(row);
            });

            // Default initialize size and colour cells with tag input
            createTagInput(row.querySelector('.inw-size-cell'), 'Type size (e.g. L) & Enter', true);
            createTagInput(row.querySelector('.inw-colour-cell'), 'Colour (optional)', false);

            lineBody.appendChild(row);
            row.querySelector('.inw-remove').addEventListener('click', function () {
                if (lineBody.children.length > 1) row.remove();
            });
            return row;
        }

        const quickProductForm = document.getElementById('quickProductForm');
        if (quickProductForm) {
            quickProductForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const errDiv = document.getElementById('quickProductError');
                const submitBtn = document.getElementById('qp_submit_btn');
                if (errDiv) {
                    errDiv.style.display = 'none';
                    errDiv.textContent = '';
                }
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Saving...';
                }

                const params = new URLSearchParams();
                params.append('csrf_token', '<?= e(csrf_token()) ?>');
                params.append('action', 'quick_create_product');
                params.append('is_ajax', '1');
                params.append('name', (document.getElementById('qp_name').value || '').trim());
                params.append('sku', (document.getElementById('qp_sku').value || '').trim());
                params.append('barcode', (document.getElementById('qp_barcode').value || '').trim());
                params.append('category_id', document.getElementById('qp_category_id').value || '');
                params.append('cost_price', document.getElementById('qp_cost_price').value || '0');
                params.append('selling_price', document.getElementById('qp_selling_price').value || '0');

                fetch('inward-entry.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: params.toString()
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Save & Select';
                    }
                    if (!data.success) {
                        if (errDiv) {
                            errDiv.textContent = data.error || 'Failed to create product.';
                            errDiv.style.display = 'block';
                        }
                        return;
                    }

                    const newProd = {
                        id: parseInt(data.product.id, 10),
                        name: data.product.name,
                        sku: data.product.sku || '',
                        variants: []
                    };
                    inwardCatalog.push(newProd);

                    // Re-populate all line product selectors preserving values
                    document.querySelectorAll('.inw-product').forEach(function (select) {
                        const cur = select.value;
                        populateProductSelect(select, cur);
                    });

                    // Select newly added product on active row or matrix select
                    const targetRow = activeRowForNewProduct || (lineBody ? lineBody.lastElementChild : null);
                    if (targetRow && activeRowForNewProduct) {
                        const sel = targetRow.querySelector('.inw-product');
                        if (sel) {
                            sel.value = String(newProd.id);
                            sel.dataset.prevValue = String(newProd.id);
                            syncLine(targetRow);
                        }
                    }

                    // Update all matrix card product dropdowns
                    document.querySelectorAll('.card-product-select').forEach(function (matrixProdSel) {
                        const opt = document.createElement('option');
                        opt.value = String(newProd.id);
                        opt.textContent = newProd.sku ? newProd.name + ' (' + newProd.sku + ')' : newProd.name;
                        matrixProdSel.appendChild(opt);
                    });

                    if (activeMatrixCardForNewProduct) {
                        const cardDiv = document.getElementById('matrixCard_' + activeMatrixCardForNewProduct);
                        if (cardDiv) {
                            const sel = cardDiv.querySelector('.card-product-select');
                            if (sel) {
                                sel.value = String(newProd.id);
                                onCardProductChange(activeMatrixCardForNewProduct, newProd.id);
                            }
                        }
                    }

                    closeQuickProductModal();
                })
                .catch(function (err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Save & Select';
                    }
                    if (errDiv) {
                        errDiv.textContent = 'Error: ' + err.message;
                        errDiv.style.display = 'block';
                    }
                });
            });
        }

        // Quick Fill & Copy Down handlers for Cost Confirmation Screen
        function applyQuickFillCostPrice(onlyBlank) {
            const costInp = document.getElementById('quickFillCost');
            const priceInp = document.getElementById('quickFillPrice');
            const costVal = costInp ? (costInp.value || '').trim() : '';
            const priceVal = priceInp ? (priceInp.value || '').trim() : '';

            if (!costVal && !priceVal) {
                alert('Please enter a Cost and/or Selling Price to apply.');
                return;
            }

            let count = 0;
            document.querySelectorAll('#costForm tr[data-line-id]').forEach(function (row) {
                const cInp = row.querySelector('.inw-cost');
                const pInp = row.querySelector('.inw-price');
                if (cInp && costVal) {
                    if (!onlyBlank || !cInp.value.trim()) {
                        cInp.value = costVal;
                    }
                }
                if (pInp && priceVal) {
                    if (!onlyBlank || !pInp.value.trim()) {
                        pInp.value = priceVal;
                    }
                }
                count++;
            });

            const notice = document.getElementById('quickFillNotice');
            if (notice) {
                notice.style.display = 'inline';
                notice.textContent = '✓ Applied to ' + count + ' variants!';
                setTimeout(function () { notice.style.display = 'none'; }, 3500);
            }
        }

        function copyCostDown(btn) {
            const tr = btn.closest('tr');
            if (!tr) return;
            const curVal = (tr.querySelector('.inw-cost').value || '').trim();
            if (!curVal) {
                alert('Please enter a purchase cost in this row first.');
                return;
            }
            let next = tr.nextElementSibling;
            let count = 0;
            while (next) {
                const inp = next.querySelector('.inw-cost');
                if (inp) {
                    inp.value = curVal;
                    count++;
                }
                next = next.nextElementSibling;
            }
        }

        function copyPriceDown(btn) {
            const tr = btn.closest('tr');
            if (!tr) return;
            const curVal = (tr.querySelector('.inw-price').value || '').trim();
            if (!curVal) {
                alert('Please enter a selling price in this row first.');
                return;
            }
            let next = tr.nextElementSibling;
            let count = 0;
            while (next) {
                const inp = next.querySelector('.inw-price');
                if (inp) {
                    inp.value = curVal;
                    count++;
                }
                next = next.nextElementSibling;
            }
        }

        const costForm = document.getElementById('costForm');
        if (costForm) {
            costForm.addEventListener('submit', function (event) {
                const prices = [];
                let missing = false;
                costForm.querySelectorAll('tr[data-line-id]').forEach(function (row) {
                    const purchase = (row.querySelector('.inw-cost').value || '').trim();
                    const selling = (row.querySelector('.inw-price').value || '').trim();
                    if (purchase === '' || selling === '') missing = true;
                    prices.push({
                        line_id: parseInt(row.getAttribute('data-line-id') || '0', 10),
                        purchase_cost: purchase,
                        selling_price: selling
                    });
                });
                if (missing) {
                    event.preventDefault();
                    alert('Enter purchase cost and selling price for every line. They stay blank until you confirm them.');
                    return;
                }
                document.getElementById('pricesJson').value = JSON.stringify(prices);
            });
        }

        if (addLineBtn && lineBody && inwardForm) {
            addLineBtn.addEventListener('click', addLine);
            addLine();
            inwardForm.addEventListener('submit', function (event) {
                const lines = [];
                lineBody.querySelectorAll('tr').forEach(function (row) {
                    const pid = parseInt(row.querySelector('.inw-product').value || '0', 10);
                    const qty = parseInt(row.querySelector('.inw-qty').value || '0', 10);

                    const sizeCell = row.querySelector('.inw-size-cell');
                    const colourCell = row.querySelector('.inw-colour-cell');
                    const sizeSelect = row.querySelector('.inw-size');
                    const colourSelect = row.querySelector('.inw-colour');

                    let sizes = [];
                    let colours = [];

                    if (sizeCell && typeof sizeCell.getValues === 'function') {
                        sizes = sizeCell.getValues();
                    } else if (sizeSelect) {
                        sizes = sizeSelect.value ? [sizeSelect.value] : [];
                    } else if (row.querySelector('.inw-size-box') && row.querySelector('.inw-size-box').getValues) {
                        sizes = row.querySelector('.inw-size-box').getValues();
                    } else if (row.querySelector('.inw-size')) {
                        const legacySize = (row.querySelector('.inw-size').value || '').trim();
                        sizes = legacySize ? legacySize.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];
                    }

                    if (colourCell && typeof colourCell.getValues === 'function') {
                        colours = colourCell.getValues();
                    } else if (colourSelect) {
                        colours = colourSelect.value ? [colourSelect.value] : [];
                    } else if (row.querySelector('.inw-colour-box') && row.querySelector('.inw-colour-box').getValues) {
                        colours = row.querySelector('.inw-colour-box').getValues();
                    } else if (row.querySelector('.inw-colour')) {
                        const legacyColour = (row.querySelector('.inw-colour').value || '').trim();
                        colours = legacyColour ? legacyColour.split(',').map(function (c) { return c.trim(); }).filter(Boolean) : [];
                    }

                    // Default colour to 'Standard' if not specified so user is never blocked
                    if (colours.length === 0) {
                        colours = ['Standard'];
                    }

                    sizes.forEach(function (sz) {
                        colours.forEach(function (col) {
                            lines.push({
                                product_id: pid,
                                size: sz,
                                colour: col,
                                quantity: qty
                            });
                        });
                    });
                });

                document.getElementById('linesJson').value = JSON.stringify(lines);
                const invalid = lines.some(function (line) {
                    return !line.product_id || !line.size || line.quantity < 1;
                });
                if (invalid) {
                    event.preventDefault();
                    alert('Each line needs a product, size, and quantity.');
                }
            });
        }

        // =========================================================================
        // SIZE × COLOUR MATRIX ENTRY LOGIC
        // =========================================================================

        function switchInwardTab(tab) {
            const tabs = ['matrix', 'lines', 'import'];
            tabs.forEach(function(t) {
                const pane = document.getElementById('tabPane' + t.charAt(0).toUpperCase() + t.slice(1));
                const btn = document.getElementById('tabBtn' + t.charAt(0).toUpperCase() + t.slice(1));
                if (pane) pane.style.display = (t === tab) ? 'block' : 'none';
                if (btn) {
                    if (t === tab) btn.classList.add('active');
                    else btn.classList.remove('active');
                }
            });
        }

        // -------------------------------------------------------------
        // MULTI-PRODUCT MATRIX INWARD ENTRY CONTROLLER
        // -------------------------------------------------------------
        let matrixProductCards = [];
        let matrixCardCounter = 0;

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        const appBaseUrl = <?= json_encode(rtrim(APP_URL, '/')) ?>;
        function assetUrl(path) {
            if (!path) return '';
            if (/^https?:\/\//i.test(path)) return path;
            return appBaseUrl + '/' + String(path).replace(/^\/+/, '');
        }

        function getStandardColorCodeJS(name) {
            if (!name) return '';
            const map = {
                'maroon': 'MRN', 'black': 'BLK', 'bottle green': 'BGR', 'bottlegreen': 'BGR',
                'red': 'RED', 'blue': 'BLU', 'navy': 'NVY', 'navy blue': 'NVY',
                'white': 'WHT', 'pink': 'PNK', 'yellow': 'YEL', 'green': 'GRN',
                'mustard': 'MSTD', 'peach': 'PCH', 'olive': 'OLV', 'teal': 'TEL',
                'grey': 'GRY', 'gray': 'GRY', 'orange': 'ORG', 'purple': 'PRP',
                'lavender': 'LAV', 'beige': 'BGE', 'brown': 'BRN', 'rust': 'RST',
                'wine': 'WIN', 'magenta': 'MAG', 'cyan': 'CYN'
            };
            const lower = name.toLowerCase().trim();
            if (map[lower]) return map[lower];
            const clean = lower.replace(/[^a-z0-9]/g, '');
            if (clean.length <= 4) return clean.toUpperCase();
            const consonants = clean.charAt(0) + clean.slice(1).replace(/[aeiou]/g, '');
            return (consonants.length >= 3 ? consonants.slice(0, 4) : clean.slice(0, 3)).toUpperCase();
        }

        function addNewMatrixProductCard(initialProdId) {
            const cardId = ++matrixCardCounter;
            const card = {
                id: cardId,
                productId: initialProdId ? parseInt(initialProdId, 10) : null,
                sizes: ['S', 'M', 'L', 'XL', 'XXL'],
                rowCounter: 0,
                rows: []
            };

            // Start with 1 default blank row
            card.rows.push({
                id: ++card.rowCounter,
                colour: '',
                colour_code: '',
                video_sku: '',
                image_path: null,
                cost_price: '',
                selling_price: '',
                quantities: {}
            });

            matrixProductCards.push(card);

            const container = document.getElementById('matrixProductsContainer');
            if (!container) return;

            const cardDiv = document.createElement('div');
            cardDiv.className = 'matrix-product-card';
            cardDiv.id = 'matrixCard_' + cardId;
            cardDiv.setAttribute('data-card-id', cardId);
            cardDiv.style.cssText = 'background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px; box-shadow: 0 1px 4px rgba(0,0,0,0.05); margin-bottom: 6px;';
            container.appendChild(cardDiv);

            renderCardStructure(card);
            updateCardHeadersAndButtons();
            recalculateAllMatrixTotals();
        }

        function updateCardHeadersAndButtons() {
            const cards = document.querySelectorAll('#matrixProductsContainer .matrix-product-card');
            cards.forEach(function(cardEl, idx) {
                const badge = cardEl.querySelector('.card-number-badge');
                if (badge) badge.textContent = 'Product #' + (idx + 1);
                const remBtn = cardEl.querySelector('.btn-remove-card');
                if (remBtn) {
                    remBtn.style.display = cards.length > 1 ? 'inline-block' : 'none';
                }
            });
            const pCountEl = document.getElementById('matrixSummaryProductsCount');
            if (pCountEl) pCountEl.textContent = String(cards.length);
        }

        function removeMatrixProductCard(cardId) {
            if (matrixProductCards.length <= 1) {
                alert('At least one product is required in the matrix inward.');
                return;
            }
            matrixProductCards = matrixProductCards.filter(function(c) { return c.id !== cardId; });
            const cardDiv = document.getElementById('matrixCard_' + cardId);
            if (cardDiv) cardDiv.remove();
            updateCardHeadersAndButtons();
            recalculateAllMatrixTotals();
        }

        function renderCardStructure(card) {
            const cardDiv = document.getElementById('matrixCard_' + card.id);
            if (!cardDiv) return;

            let optionsHtml = '<option value="">Choose design product...</option>';
            inwardCatalog.forEach(function(prod) {
                const isSel = card.productId === prod.id ? 'selected' : '';
                const skuStr = prod.sku ? ' (' + prod.sku + ')' : '';
                optionsHtml += '<option value="' + prod.id + '" ' + isSel + '>' + escapeHtml(prod.name) + escapeHtml(skuStr) + '</option>';
            });

            cardDiv.innerHTML =
                '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">' +
                    '<div style="display:flex; align-items:center; gap:10px;">' +
                        '<span class="card-number-badge" style="font-size:12.5px; font-weight:800; background:#2563eb; color:#ffffff; padding:3px 12px; border-radius:9999px;">Product #1</span>' +
                        '<div class="card-sku-box" id="cardSkuBox_' + card.id + '" style="display:none; align-items:center; gap:6px;">' +
                            '<span style="font-size:11.5px; color:#64748b; font-weight:600; text-transform:uppercase;">Design Code:</span>' +
                            '<span class="card-sku-badge" id="cardSkuBadge_' + card.id + '" style="font-weight:700; color:#1d4ed8; background:#eff6ff; padding:2px 8px; border-radius:4px; border:1px solid #bfdbfe; font-family:monospace; font-size:13px;"></span>' +
                        '</div>' +
                    '</div>' +
                    '<button type="button" class="btn-remove-card" onclick="removeMatrixProductCard(' + card.id + ')" style="background:transparent; border:1px solid #fca5a5; color:#dc2626; border-radius:6px; padding:4px 10px; font-size:12px; font-weight:600; cursor:pointer;" title="Remove this product from inward">&times; Remove Product</button>' +
                '</div>' +

                '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-bottom:14px;">' +
                    '<div style="display:flex; gap:10px; align-items:flex-end; margin-bottom:12px; flex-wrap:wrap;">' +
                        '<div style="flex:1; min-width:260px;">' +
                            '<label class="form-label" style="font-weight:700; margin-bottom:4px; font-size:13px;">Select Design / Product <span style="color:#ef4444;">*</span></label>' +
                            '<select class="form-control card-product-select" style="font-weight:600;" onchange="onCardProductChange(' + card.id + ', this.value)">' +
                                optionsHtml +
                            '</select>' +
                        '</div>' +
                        '<button type="button" onclick="openQuickProductModal(null, ' + card.id + ')" class="btn-secondary" style="padding:8px 12px; white-space:nowrap; font-size:12.5px; height:42px;">+ New Design Product</button>' +
                    '</div>' +

                    '<div>' +
                        '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px;">' +
                            '<label class="form-label" style="font-weight:700; margin-bottom:0; font-size:12px;">Size Columns:</label>' +
                            '<div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">' +
                                '<span style="font-size:11.5px; color:#64748b; font-weight:600;">Presets:</span>' +
                                '<button type="button" class="preset-size-pill" onclick="applyCardSizePreset(' + card.id + ', [\'S\',\'M\',\'L\',\'XL\',\'XXL\'])">S, M, L, XL, XXL</button>' +
                                '<button type="button" class="preset-size-pill" onclick="applyCardSizePreset(' + card.id + ', [\'XS\',\'S\',\'M\',\'L\',\'XL\',\'2XL\',\'3XL\'])">XS to 3XL</button>' +
                                '<button type="button" class="preset-size-pill" onclick="applyCardSizePreset(' + card.id + ', [\'28\',\'30\',\'32\',\'34\',\'36\',\'38\'])">Waist 28-38</button>' +
                                '<button type="button" class="preset-size-pill" onclick="applyCardSizePreset(' + card.id + ', [\'Free Size\'])">Free Size</button>' +
                            '</div>' +
                        '</div>' +
                        '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;" id="cardSizesChips_' + card.id + '"></div>' +
                        '<div style="display:flex; align-items:center; gap:8px; margin-top:6px; max-width:240px;">' +
                            '<input type="text" id="cardNewSizeInput_' + card.id + '" class="form-control" placeholder="Add size (e.g. 4XL)" style="padding:4px 8px; font-size:12px; height:32px;" onkeydown="if(event.key===\'Enter\'){event.preventDefault();addCardCustomSize(' + card.id + ');}">' +
                            '<button type="button" class="btn-secondary" onclick="addCardCustomSize(' + card.id + ')" style="padding:4px 10px; font-size:12px; height:32px; white-space:nowrap;">+ Add</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +

                '<div class="table-wrap" style="overflow-x:auto; margin-bottom:12px; border:1px solid #e2e8f0; border-radius:6px;">' +
                    '<table class="matrix-table" id="cardMatrixTable_' + card.id + '" style="margin:0; width:100%;">' +
                        '<thead id="cardHead_' + card.id + '"></thead>' +
                        '<tbody id="cardBody_' + card.id + '"></tbody>' +
                        '<tfoot id="cardFoot_' + card.id + '"></tfoot>' +
                    '</table>' +
                '</div>' +

                '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding-top:4px;">' +
                    '<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">' +
                        '<button type="button" class="btn-secondary" onclick="addCardColourRow(' + card.id + ')" style="padding:7px 14px; font-weight:600; font-size:12.5px;">+ Add Colour</button>' +
                        '<div style="display:inline-flex; align-items:center; gap:6px; background:#f1f5f9; padding:4px 10px; border-radius:6px; border:1px solid #e2e8f0;">' +
                            '<span style="font-size:11.5px; color:#475569; font-weight:600;">Fill all rows:</span>' +
                            '<input type="number" step="0.01" min="0" placeholder="Cost (₹)" id="cardBulkCost_' + card.id + '" class="form-control" style="width:85px; height:30px; font-size:12px; padding:2px 8px; text-align:right;">' +
                            '<input type="number" step="0.01" min="0" placeholder="Selling (₹)" id="cardBulkPrice_' + card.id + '" class="form-control" style="width:85px; height:30px; font-size:12px; padding:2px 8px; text-align:right;">' +
                            '<button type="button" class="btn-secondary" onclick="applyCardBulkCostPrice(' + card.id + ')" style="padding:2px 10px; font-size:11.5px; height:30px; font-weight:600;">Apply</button>' +
                        '</div>' +
                    '</div>' +
                    '<div style="font-size:13px; font-weight:700; color:#334155; background:#f8fafc; border:1px solid #cbd5e1; padding:6px 14px; border-radius:6px;">' +
                        'Product Subtotal: <span id="cardTotalQty_' + card.id + '" style="color:#2563eb;">0</span> pcs · ₹<span id="cardTotalCost_' + card.id + '" style="color:#16a34a;">0.00</span>' +
                    '</div>' +
                '</div>';

            renderCardSizeChips(card.id);
            renderCardTable(card.id);
            if (card.productId) {
                updateCardSkuDisplay(card);
            }
        }

        function updateCardSkuDisplay(card) {
            const box = document.getElementById('cardSkuBox_' + card.id);
            const badge = document.getElementById('cardSkuBadge_' + card.id);
            const prod = productById(card.productId);
            if (!prod) {
                if (box) box.style.display = 'none';
                return;
            }
            const parentSku = (prod.sku || ('PRD-' + prod.id)).toUpperCase();
            if (badge) badge.textContent = parentSku;
            if (box) box.style.display = 'inline-flex';
        }

        function onCardProductChange(cardId, productId) {
            const card = matrixProductCards.find(function(c) { return c.id === cardId; });
            if (!card) return;
            card.productId = productId ? parseInt(productId, 10) : null;
            const prod = productById(card.productId);

            updateCardSkuDisplay(card);

            if (prod && prod.variants && prod.variants.length > 0) {
                const prodSizes = uniqueValues(prod.variants, 'size').filter(Boolean);
                if (prodSizes.length > 0) {
                    card.sizes = prodSizes;
                }

                // If card currently only has 1 blank row, populate existing product variants/colours
                const hasOnlyBlank = (card.rows.length <= 1 && (!card.rows[0] || !card.rows[0].colour));
                if (hasOnlyBlank) {
                    const uniqueColours = [];
                    const colourVariantMap = {};
                    prod.variants.forEach(function(v) {
                        const col = (v.colour || '').trim();
                        if (col && uniqueColours.indexOf(col) === -1) {
                            uniqueColours.push(col);
                            colourVariantMap[col] = v;
                        }
                    });

                    if (uniqueColours.length > 0) {
                        card.rows = uniqueColours.map(function(col) {
                            const v = colourVariantMap[col];
                            return {
                                id: ++card.rowCounter,
                                colour: col,
                                colour_code: v.color_code || getStandardColorCodeJS(col),
                                video_sku: v.video_sku || (prod.sku ? prod.sku.toUpperCase() + '-' + (v.color_code || getStandardColorCodeJS(col)) : ''),
                                image_path: v.image_path || null,
                                cost_price: (v.cost_price !== null && v.cost_price !== undefined) ? String(v.cost_price) : (prod.cost_price ? String(prod.cost_price) : ''),
                                selling_price: (v.selling_price !== null && v.selling_price !== undefined) ? String(v.selling_price) : (prod.selling_price ? String(prod.selling_price) : ''),
                                quantities: {}
                            };
                        });
                    }
                }
            } else if (prod && card.rows.length === 1 && !card.rows[0].colour) {
                if (prod.cost_price) card.rows[0].cost_price = String(prod.cost_price);
                if (prod.selling_price) card.rows[0].selling_price = String(prod.selling_price);
                if (prod.image_path) card.rows[0].image_path = prod.image_path;
            }

            renderCardSizeChips(card.id);
            renderCardTable(card.id);
            recalculateAllMatrixTotals();
        }

        function renderCardSizeChips(cardId) {
            const card = matrixProductCards.find(function(c) { return c.id === cardId; });
            if (!card) return;
            const container = document.getElementById('cardSizesChips_' + cardId);
            if (!container) return;
            container.innerHTML = '';
            card.sizes.forEach(function(sz) {
                const chip = document.createElement('span');
                chip.style.cssText = 'display:inline-flex; align-items:center; gap:5px; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-size:12px; font-weight:700; padding:3px 9px; border-radius:14px;';
                chip.innerHTML = '<span>' + escapeHtml(sz) + '</span>' + 
                    (card.sizes.length > 1 ? '<button type="button" style="background:none; border:none; color:#3b82f6; cursor:pointer; font-size:14px; line-height:1; padding:0 2px;">&times;</button>' : '');
                const btn = chip.querySelector('button');
                if (btn) {
                    btn.addEventListener('click', function() {
                        removeCardSize(cardId, sz);
                    });
                }
                container.appendChild(chip);
            });
        }

        function applyCardSizePreset(cardId, preset) {
            const card = matrixProductCards.find(function(c) { return c.id === cardId; });
            if (!card || !Array.isArray(preset) || preset.length === 0) return;
            card.sizes = preset.slice();
            renderCardSizeChips(cardId);
            renderCardTable(cardId);
            recalculateAllMatrixTotals();
        }

        function addCardCustomSize(cardId) {
            const card = matrixProductCards.find(function(c) { return c.id === cardId; });
            if (!card) return;
            const inp = document.getElementById('cardNewSizeInput_' + cardId);
            if (!inp) return;
            const val = (inp.value || '').trim();
            if (!val) return;
            const exists = card.sizes.some(function(s) { return s.toUpperCase() === val.toUpperCase(); });
            if (!exists) {
                card.sizes.push(val);
                renderCardSizeChips(cardId);
                renderCardTable(cardId);
                recalculateAllMatrixTotals();
            }
            inp.value = '';
            inp.focus();
        }

        function removeCardSize(cardId, sz) {
            const card = matrixProductCards.find(function(c) { return c.id === cardId; });
            if (!card || card.sizes.length <= 1) return;
            card.sizes = card.sizes.filter(function(s) { return s !== sz; });
            renderCardSizeChips(cardId);
            renderCardTable(cardId);
            recalculateAllMatrixTotals();
        }

        function addCardColourRow(cardId, initialColour, initialVideoSku, initialCost, initialPrice, initialQtys, initialImg) {
            const card = matrixProductCards.find(function(c) { return c.id === cardId; });
            if (!card) return;
            const rowId = ++card.rowCounter;
            card.rows.push({
                id: rowId,
                colour: initialColour || '',
                colour_code: '',
                video_sku: initialVideoSku || '',
                image_path: initialImg || null,
                cost_price: initialCost !== undefined && initialCost !== null ? String(initialCost) : '',
                selling_price: initialPrice !== undefined && initialPrice !== null ? String(initialPrice) : '',
                quantities: Object.assign({}, initialQtys || {})
            });
            renderCardTable(cardId);
            recalculateAllMatrixTotals();
        }

        function removeCardColourRow(cardId, rowId) {
            const card = matrixProductCards.find(function(c) { return c.id === cardId; });
            if (!card) return;
            if (card.rows.length <= 1) {
                alert('At least one colour row is required for this product.');
                return;
            }
            card.rows = card.rows.filter(function(r) { return r.id !== rowId; });
            renderCardTable(cardId);
            recalculateAllMatrixTotals();
        }

        function applyCardBulkCostPrice(cardId) {
            const card = matrixProductCards.find(function(c) { return c.id === cardId; });
            if (!card) return;
            const costInp = document.getElementById('cardBulkCost_' + cardId);
            const priceInp = document.getElementById('cardBulkPrice_' + cardId);
            const costVal = costInp ? (costInp.value || '').trim() : '';
            const priceVal = priceInp ? (priceInp.value || '').trim() : '';

            if (!costVal && !priceVal) {
                alert('Please enter a Cost and/or Selling price to fill all rows.');
                return;
            }

            card.rows.forEach(function(r) {
                if (costVal) r.cost_price = costVal;
                if (priceVal) r.selling_price = priceVal;
            });

            renderCardTable(cardId);
            recalculateAllMatrixTotals();
        }

        function renderCardTable(cardId) {
            const card = matrixProductCards.find(function(c) { return c.id === cardId; });
            if (!card) return;

            const table = document.getElementById('cardMatrixTable_' + cardId);
            const head = document.getElementById('cardHead_' + cardId);
            const body = document.getElementById('cardBody_' + cardId);
            const foot = document.getElementById('cardFoot_' + cardId);
            if (!table || !head || !body || !foot) return;

            const prod = productById(card.productId);
            const parentSku = prod && prod.sku ? prod.sku.toUpperCase() : 'DESIGN';

            // 1. Header
            let hHtml = '<tr>' +
                '<th style="min-width:140px; text-align:left;">Colour Name</th>' +
                '<th class="matrix-img-cell">Image</th>' +
                '<th style="width:140px; text-align:left;">Video / OCR SKU</th>' +
                '<th style="width:105px; text-align:center;">Unit Cost (₹)</th>' +
                '<th style="width:105px; text-align:center;">Selling (₹)</th>';
            card.sizes.forEach(function(sz) {
                hHtml += '<th style="min-width:64px; text-align:center; background:#eff6ff; color:#1e40af;">' + escapeHtml(sz) + '</th>';
            });
            hHtml += '<th style="width:80px; text-align:center; background:#f1f5f9;">Total Pcs</th>' +
                     '<th style="width:40px; text-align:center;"></th>' +
                     '</tr>';
            head.innerHTML = hHtml;

            // 2. Body
            body.innerHTML = '';
            card.rows.forEach(function(row) {
                const tr = document.createElement('tr');
                tr.setAttribute('data-card-row-id', row.id);

                const hasImg = !!row.image_path;
                const imgPlaceholderSvg = '<svg width="18" height="18" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>';
                const previewInner = hasImg ? '<img src="' + escapeHtml(assetUrl(row.image_path)) + '" alt="Colour">' : imgPlaceholderSvg;
                const delImgBtnHtml = hasImg ? '<button type="button" class="matrix-img-del-btn" title="Remove image">&times;</button>' : '';

                let rHtml = '<td>' +
                    '<input type="text" class="form-control card-colour-inp" value="' + escapeHtml(row.colour || '') + '" placeholder="Type colour (e.g. Maroon)" style="font-weight:600;" list="matrixColourDatalist">' +
                    '</td>' +
                    '<td class="matrix-img-cell">' +
                        '<div class="matrix-img-picker">' +
                            '<label class="matrix-img-label" title="Upload variant image">' +
                                '<input type="file" class="matrix-file-input" accept="image/jpeg,image/png,image/webp,image/jpg" style="display:none;">' +
                                '<div class="matrix-img-preview' + (hasImg ? ' has-img' : '') + '">' +
                                    previewInner +
                                '</div>' +
                            '</label>' +
                            delImgBtnHtml +
                        '</div>' +
                    '</td>' +
                    '<td>' +
                    '<input type="text" class="form-control card-video-sku-inp" value="' + escapeHtml(row.video_sku || '') + '" placeholder="e.g. ' + (parentSku !== 'DESIGN' ? parentSku + '-MRN' : 'SKU-CODE') + '" style="font-family:monospace; font-weight:700; text-transform:uppercase; font-size:12.5px;">' +
                    '</td>' +
                    '<td><input type="number" step="0.01" min="0" class="form-control card-cost-inp" value="' + escapeHtml(row.cost_price || '') + '" placeholder="0.00" style="text-align:right;"></td>' +
                    '<td><input type="number" step="0.01" min="0" class="form-control card-price-inp" value="' + escapeHtml(row.selling_price || '') + '" placeholder="0.00" style="text-align:right;"></td>';

                card.sizes.forEach(function(sz) {
                    const qtyVal = row.quantities[sz] !== undefined && row.quantities[sz] !== null ? row.quantities[sz] : '';
                    rHtml += '<td style="text-align:center; padding: 4px;">' +
                        '<input type="number" min="0" step="1" class="form-control card-qty-inp" data-size="' + escapeHtml(sz) + '" value="' + qtyVal + '" placeholder="0" style="text-align:center; font-weight:700; font-size:14px; width:100%; min-width:55px;">' +
                        '</td>';
                });

                rHtml += '<td style="text-align:center; font-weight:800; font-size:14px; color:#1e293b;" class="card-row-total-pcs">0</td>' +
                         '<td style="text-align:center;"><button type="button" class="btn-secondary card-row-del-btn" style="padding:4px 8px; color:#dc2626; border:none; font-size:16px; font-weight:700; cursor:pointer;" title="Remove colour row">&times;</button></td>';

                tr.innerHTML = rHtml;

                const colInp = tr.querySelector('.card-colour-inp');
                const vSkuInp = tr.querySelector('.card-video-sku-inp');
                const costInp = tr.querySelector('.card-cost-inp');
                const priceInp = tr.querySelector('.card-price-inp');
                const delBtn = tr.querySelector('.card-row-del-btn');
                const fileInp = tr.querySelector('.matrix-file-input');
                const previewBox = tr.querySelector('.matrix-img-preview');
                const pickerBox = tr.querySelector('.matrix-img-picker');
                const delImgBtn = tr.querySelector('.matrix-img-del-btn');

                if (fileInp) {
                    fileInp.addEventListener('change', function() {
                        if (!fileInp.files || !fileInp.files[0]) return;
                        const file = fileInp.files[0];
                        previewBox.style.opacity = '0.5';

                        const fd = new FormData();
                        fd.append('action', 'upload_matrix_image');
                        fd.append('is_ajax', '1');
                        fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
                        fd.append('image', file);

                        fetch('inward-entry.php', {
                            method: 'POST',
                            body: fd
                        })
                        .then(function(res) { return res.json(); })
                        .then(function(data) {
                            previewBox.style.opacity = '1';
                            if (data.success && data.image_path) {
                                row.image_path = data.image_path;
                                previewBox.className = 'matrix-img-preview has-img';
                                previewBox.innerHTML = '<img src="' + escapeHtml(data.image_url) + '" alt="Colour">';
                                
                                let oldDel = pickerBox.querySelector('.matrix-img-del-btn');
                                if (!oldDel) {
                                    const newDel = document.createElement('button');
                                    newDel.type = 'button';
                                    newDel.className = 'matrix-img-del-btn';
                                    newDel.title = 'Remove image';
                                    newDel.innerHTML = '&times;';
                                    newDel.addEventListener('click', function(ev) {
                                        ev.stopPropagation();
                                        ev.preventDefault();
                                        row.image_path = null;
                                        previewBox.className = 'matrix-img-preview';
                                        previewBox.innerHTML = imgPlaceholderSvg;
                                        newDel.remove();
                                        fileInp.value = '';
                                    });
                                    pickerBox.appendChild(newDel);
                                }
                            } else {
                                alert(data.error || 'Image upload failed.');
                            }
                        })
                        .catch(function(err) {
                            previewBox.style.opacity = '1';
                            alert('Network error while uploading image.');
                        });
                    });
                }

                if (delImgBtn) {
                    delImgBtn.addEventListener('click', function(ev) {
                        ev.stopPropagation();
                        ev.preventDefault();
                        row.image_path = null;
                        previewBox.className = 'matrix-img-preview';
                        previewBox.innerHTML = imgPlaceholderSvg;
                        delImgBtn.remove();
                        if (fileInp) fileInp.value = '';
                    });
                }

                colInp.addEventListener('input', function() {
                    row.colour = colInp.value.trim();
                });

                vSkuInp.addEventListener('input', function() {
                    row.video_sku = vSkuInp.value.trim().toUpperCase();
                });

                costInp.addEventListener('input', function() {
                    row.cost_price = costInp.value;
                    recalculateAllMatrixTotals();
                });

                priceInp.addEventListener('input', function() {
                    row.selling_price = priceInp.value;
                });

                delBtn.addEventListener('click', function() {
                    removeCardColourRow(cardId, row.id);
                });

                tr.querySelectorAll('.card-qty-inp').forEach(function(qInp) {
                    qInp.addEventListener('input', function() {
                        const s = qInp.getAttribute('data-size');
                        const v = parseInt(qInp.value || '0', 10);
                        row.quantities[s] = isNaN(v) ? 0 : Math.max(0, v);
                        recalculateAllMatrixTotals();
                    });
                    qInp.addEventListener('focus', function() {
                        if (qInp.value === '0') qInp.value = '';
                    });
                });

                body.appendChild(tr);
            });

            // 3. Footer
            let fHtml = '<tr style="background:#f8fafc; font-weight:700;">' +
                '<td colspan="5" style="text-align:right; padding-right:12px; font-size:13px; color:#475569;">Size Column Totals:</td>';
            card.sizes.forEach(function(sz) {
                fHtml += '<td style="text-align:center; font-weight:800; color:#1e40af; font-size:13.5px;" id="cardColTotal_' + cardId + '_' + sz.replace(/[^a-zA-Z0-9]/g, '_') + '">0</td>';
            });
            fHtml += '<td style="text-align:center; font-weight:900; font-size:15px; color:#2563eb;" id="cardFootGrandTotal_' + cardId + '">0</td>' +
                     '<td></td>' +
                     '</tr>';
            foot.innerHTML = fHtml;
        }

        function recalculateAllMatrixTotals() {
            let grandTotalQty = 0;
            let grandTotalCost = 0.0;

            matrixProductCards.forEach(function(card) {
                let cardQty = 0;
                let cardCost = 0.0;
                const colTotals = {};
                card.sizes.forEach(function(s) { colTotals[s] = 0; });

                const cardDiv = document.getElementById('matrixCard_' + card.id);
                if (cardDiv) {
                    cardDiv.querySelectorAll('tbody tr[data-card-row-id]').forEach(function(tr) {
                        const rowId = parseInt(tr.getAttribute('data-card-row-id') || '0', 10);
                        const rowObj = card.rows.find(function(r) { return r.id === rowId; });
                        if (!rowObj) return;

                        let rowQty = 0;
                        tr.querySelectorAll('.card-qty-inp').forEach(function(qInp) {
                            const s = qInp.getAttribute('data-size');
                            const v = parseInt(qInp.value || '0', 10);
                            const val = isNaN(v) ? 0 : Math.max(0, v);
                            rowQty += val;
                            if (colTotals[s] !== undefined) colTotals[s] += val;
                        });

                        const rowTotalEl = tr.querySelector('.card-row-total-pcs');
                        if (rowTotalEl) rowTotalEl.textContent = String(rowQty);

                        const costVal = parseFloat(rowObj.cost_price || '0');
                        const unitCost = isNaN(costVal) ? 0 : costVal;
                        cardQty += rowQty;
                        cardCost += (rowQty * unitCost);
                    });

                    card.sizes.forEach(function(sz) {
                        const colEl = document.getElementById('cardColTotal_' + card.id + '_' + sz.replace(/[^a-zA-Z0-9]/g, '_'));
                        if (colEl) colEl.textContent = String(colTotals[sz] || 0);
                    });

                    const footEl = document.getElementById('cardFootGrandTotal_' + card.id);
                    if (footEl) footEl.textContent = String(cardQty);

                    const cardQtyEl = document.getElementById('cardTotalQty_' + card.id);
                    if (cardQtyEl) cardQtyEl.textContent = String(cardQty);

                    const cardCostEl = document.getElementById('cardTotalCost_' + card.id);
                    if (cardCostEl) cardCostEl.textContent = cardCost.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }

                grandTotalQty += cardQty;
                grandTotalCost += cardCost;
            });

            const sumQtyEl = document.getElementById('matrixSummaryQty');
            const sumCostEl = document.getElementById('matrixSummaryCost');

            if (sumQtyEl) sumQtyEl.textContent = String(grandTotalQty);
            if (sumCostEl) sumCostEl.textContent = grandTotalCost.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        const matrixInwardForm = document.getElementById('matrixInwardForm');
        if (matrixInwardForm) {
            matrixInwardForm.addEventListener('submit', function(e) {
                if (matrixProductCards.length === 0) {
                    e.preventDefault();
                    alert('Please add at least one product.');
                    return;
                }

                let grandQty = 0;
                const serializedProducts = [];

                for (let i = 0; i < matrixProductCards.length; i++) {
                    const card = matrixProductCards[i];
                    const cardNum = i + 1;

                    if (!card.productId) {
                        e.preventDefault();
                        alert('Please select a Design / Product for Product #' + cardNum + '.');
                        const cardDiv = document.getElementById('matrixCard_' + card.id);
                        if (cardDiv) {
                            const sel = cardDiv.querySelector('.card-product-select');
                            if (sel) sel.focus();
                        }
                        return;
                    }

                    if (card.rows.length === 0) {
                        e.preventDefault();
                        alert('Please add at least one colour row for Product #' + cardNum + '.');
                        return;
                    }

                    let cardQty = 0;
                    let missingColour = false;
                    const serializedRows = [];

                    card.rows.forEach(function(row) {
                        const colName = (row.colour || '').trim();
                        if (!colName) missingColour = true;

                        let rowQty = 0;
                        const qMap = {};
                        card.sizes.forEach(function(sz) {
                            const q = parseInt(row.quantities[sz] || '0', 10);
                            const val = isNaN(q) ? 0 : Math.max(0, q);
                            qMap[sz] = val;
                            rowQty += val;
                        });
                        cardQty += rowQty;

                        const costNum = row.cost_price !== '' && row.cost_price !== null && row.cost_price !== undefined ? parseFloat(row.cost_price) : null;
                        const priceNum = row.selling_price !== '' && row.selling_price !== null && row.selling_price !== undefined ? parseFloat(row.selling_price) : null;

                        serializedRows.push({
                            colour: colName,
                            colour_code: row.colour_code || getStandardColorCodeJS(colName),
                            video_sku: (row.video_sku || '').toUpperCase(),
                            image_path: row.image_path || null,
                            cost_price: isNaN(costNum) ? null : costNum,
                            unit_cost: isNaN(costNum) ? null : costNum,
                            selling_price: isNaN(priceNum) ? null : priceNum,
                            quantities: qMap
                        });
                    });

                    if (missingColour) {
                        e.preventDefault();
                        alert('Please specify the colour name for all rows in Product #' + cardNum + '.');
                        return;
                    }

                    if (cardQty <= 0) {
                        e.preventDefault();
                        alert('Please enter at least 1 piece for Product #' + cardNum + '.');
                        return;
                    }

                    grandQty += cardQty;
                    serializedProducts.push({
                        product_id: card.productId,
                        sizes: card.sizes,
                        rows: serializedRows
                    });
                }

                if (grandQty <= 0) {
                    e.preventDefault();
                    alert('Please enter at least 1 piece across the matrix products.');
                    return;
                }

                const payload = {
                    products: serializedProducts
                };

                document.getElementById('matrixJson').value = JSON.stringify(payload);
            });
        }

        // Initialize Matrix with 1 default blank product card
        if (document.getElementById('tabPaneMatrix')) {
            addNewMatrixProductCard();
        }
    </script>
</body>
</html>

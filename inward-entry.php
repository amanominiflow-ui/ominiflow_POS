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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $isAjax = !empty($_POST['is_ajax']);
    inward_entry_assert_action($postAction, $isAjax);

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
                        ?>
                        <div style="padding: 0 20px 16px;">
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
                                            <td><?= e((string) ($line['product_sku'] ?? '')) ?></td>
                                            <td><?= e((string) $line['size']) ?></td>
                                            <td><?= e((string) $line['colour']) ?></td>
                                            <td><?= (int) $line['quantity'] ?></td>
                                            <?php if ($costPending && $canInwardCost): ?>
                                                <td><input type="text" inputmode="decimal" class="form-control inw-cost" autocomplete="off" placeholder="Blank" style="min-height: 48px; font-size: 18px;"></td>
                                                <td><input type="text" inputmode="decimal" class="form-control inw-price" autocomplete="off" placeholder="Blank" style="min-height: 48px; font-size: 18px;"></td>
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
                    <div class="section-card" style="margin-bottom: 18px;">
                        <div class="section-header">
                            <div>
                                <h2 class="section-heading">New warehouse count</h2>
                                <p class="section-subheading">Counted by <?= e((string) ($user['name'] ?? 'you')) ?>. Saving adds company stock. It does not put ready stock in Central.</p>
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
                                    <th>Lines</th>
                                    <th>Quantity</th>
                                    <th>Bill</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($entries === []): ?>
                                    <tr><td colspan="8" style="text-align:center; padding: 28px; color:#64748b;">No inward counts yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($entries as $entry): ?>
                                        <tr>
                                            <td><a href="<?= asset('inward-entry.php?id=' . (int) $entry['id']) ?>"><strong><?= e((string) $entry['entry_number']) ?></strong></a></td>
                                            <td><?= e(date('d M Y', strtotime((string) $entry['entry_date']))) ?></td>
                                            <td><?= e((string) ($entry['warehouse_name'] ?? '')) ?></td>
                                            <td><?= e((string) ($entry['vendor_name'] ?? '—')) ?></td>
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

        function openQuickProductModal(row) {
            activeRowForNewProduct = row || null;
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

                    // Select newly added product on active row
                    const targetRow = activeRowForNewProduct || (lineBody ? lineBody.lastElementChild : null);
                    if (targetRow) {
                        const sel = targetRow.querySelector('.inw-product');
                        if (sel) {
                            sel.value = String(newProd.id);
                            sel.dataset.prevValue = String(newProd.id);
                            syncLine(targetRow);
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
    </script>
</body>
</html>

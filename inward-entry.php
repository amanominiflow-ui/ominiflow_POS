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

require_auth();

$pageTitle = 'Inward Entry';
$user = current_user();
$userId = $user ? (int) $user['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/inward-entry.php');
    }

    if (($_POST['action'] ?? '') === 'save_inward') {
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
}

$search = trim((string) ($_GET['search'] ?? ''));
$selectedId = (int) ($_GET['id'] ?? 0);
$selected = $selectedId > 0 ? get_inward_entry_by_id($selectedId) : null;
$entries = get_inward_entries($search);
$warehouses = get_warehouses(null, 'active');
$vendors = get_vendors();
$catalog = inward_count_catalog();

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
                            <?php elseif (!$purchaseConfirmed): ?>
                                <p style="margin: 12px 0 0; color: #475569;">Costs are saved. Confirm the purchase to release a barcode for each line so the warehouse can print labels. This quantity is already company stock.</p>
                            <?php elseif (!$qcDone): ?>
                                <p style="margin: 12px 0 0; color: #475569;">Print the barcodes, then finish QC, checking, and tagging. There is no pass or fail. Ready stock is added to Central Warehouse only.</p>
                            <?php else: ?>
                                <p style="margin: 12px 0 0; color: #475569;">QC, checking, and tagging is complete. Ready stock is in Central Warehouse only.</p>
                            <?php endif; ?>
                        </div>
                        <?php if ($costPending): ?>
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
                                            <?php if ($costPending): ?>
                                                <td><input type="text" inputmode="decimal" class="form-control inw-cost" autocomplete="off" placeholder="Blank" style="min-height: 48px; font-size: 18px;"></td>
                                                <td><input type="text" inputmode="decimal" class="form-control inw-price" autocomplete="off" placeholder="Blank" style="min-height: 48px; font-size: 18px;"></td>
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
                        <?php if ($costPending && !empty($selected['lines'])): ?>
                            <div style="padding: 14px 0 16px; display:flex; justify-content: flex-end;">
                                <button type="submit" class="header-btn" style="padding: 12px 18px; min-height: 48px;">Confirm costs</button>
                            </div>
                        <?php endif; ?>
                        <?php if ($costPending): ?>
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
                                <table class="saas-table" id="lineTable">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Size</th>
                                            <th>Colour</th>
                                            <th style="width: 110px;">Quantity</th>
                                            <th style="width: 70px;"></th>
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

        function syncLine(row) {
            const product = productById(parseInt(row.querySelector('.inw-product').value || '0', 10));
            const sizeCell = row.querySelector('.inw-size-cell');
            const colourCell = row.querySelector('.inw-colour-cell');
            const variants = product && product.variants ? product.variants : [];
            if (!product || variants.length === 0) {
                sizeCell.innerHTML = '<input type="text" class="form-control inw-size" maxlength="80" placeholder="Size">';
                colourCell.innerHTML = '<input type="text" class="form-control inw-colour" maxlength="80" placeholder="Colour">';
                return;
            }
            const sizes = uniqueValues(variants, 'size');
            sizeCell.innerHTML = '<select class="form-control inw-size"></select>';
            colourCell.innerHTML = '<select class="form-control inw-colour"></select>';
            const sizeSelect = sizeCell.querySelector('.inw-size');
            const colourSelect = colourCell.querySelector('.inw-colour');
            fillSelect(sizeSelect, sizes, 'Size');
            fillSelect(colourSelect, [], 'Colour');
            sizeSelect.addEventListener('change', function () {
                const colours = variants.filter(function (variant) {
                    return variant.size === sizeSelect.value;
                }).map(function (variant) { return variant.colour; });
                fillSelect(colourSelect, uniqueValues(colours.map(function (colour) { return { colour: colour }; }), 'colour'), 'Colour');
            });
        }

        function addLine() {
            const row = document.createElement('tr');
            row.innerHTML = ''
                + '<td><select class="form-control inw-product"><option value="">Product</option></select></td>'
                + '<td class="inw-size-cell"><input type="text" class="form-control inw-size" maxlength="80" placeholder="Size"></td>'
                + '<td class="inw-colour-cell"><input type="text" class="form-control inw-colour" maxlength="80" placeholder="Colour"></td>'
                + '<td><input type="number" class="form-control inw-qty" min="1" step="1" value="1"></td>'
                + '<td><button type="button" class="btn-secondary inw-remove" style="padding: 6px 10px;">Remove</button></td>';
            const productSelect = row.querySelector('.inw-product');
            inwardCatalog.forEach(function (product) {
                const option = document.createElement('option');
                option.value = String(product.id);
                option.textContent = product.sku ? product.name + ' (' + product.sku + ')' : product.name;
                productSelect.appendChild(option);
            });
            lineBody.appendChild(row);
            row.querySelector('.inw-product').addEventListener('change', function () { syncLine(row); });
            row.querySelector('.inw-remove').addEventListener('click', function () {
                if (lineBody.children.length > 1) row.remove();
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
                    lines.push({
                        product_id: parseInt(row.querySelector('.inw-product').value || '0', 10),
                        size: (row.querySelector('.inw-size').value || '').trim(),
                        colour: (row.querySelector('.inw-colour').value || '').trim(),
                        quantity: parseInt(row.querySelector('.inw-qty').value || '0', 10)
                    });
                });
                document.getElementById('linesJson').value = JSON.stringify(lines);
                const invalid = lines.some(function (line) {
                    return !line.product_id || !line.size || !line.colour || line.quantity < 1;
                });
                if (invalid) {
                    event.preventDefault();
                    alert('Each line needs a product, size, colour, and quantity.');
                }
            });
        }
    </script>
</body>
</html>

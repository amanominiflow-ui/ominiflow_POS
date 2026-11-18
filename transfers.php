<?php
/**
 * Stock Transfers Workflow Screen (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/outlets_db.php';
require_once __DIR__ . '/includes/products_db.php';
require_once __DIR__ . '/includes/orders_db.php';

require_auth();

$user = current_user();

if (isset($_GET['action']) && $_GET['action'] === 'get_transfer_details') {
    header('Content-Type: application/json');
    $trfId = (int)($_GET['id'] ?? 0);
    $trf = get_stock_transfer_by_id($trfId);
    if (!$trf) {
        echo json_encode(['success' => false, 'error' => 'Transfer not found']);
    } else {
        echo json_encode(['success' => true, 'transfer' => $trf]);
    }
    exit;
}

$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/transfers.php');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_transfer') {
            $src = (int)($_POST['source_warehouse_id'] ?? 0);
            $dst = (int)($_POST['dest_warehouse_id'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));

            $items = [];
            if (!empty($_POST['items']) && is_array($_POST['items'])) {
                foreach ($_POST['items'] as $it) {
                    $pid = (int)($it['product_id'] ?? 0);
                    $qty = (int)($it['quantity'] ?? 0);
                    $vid = !empty($it['variant_id']) ? (int)$it['variant_id'] : null;
                    if ($pid > 0 && $qty > 0) {
                        $items[] = [
                            'product_id' => $pid,
                            'variant_id' => $vid,
                            'quantity' => $qty,
                        ];
                    }
                }
            } elseif (!empty($_POST['product_id'])) {
                $pid = (int)$_POST['product_id'];
                $qty = max(1, (int)($_POST['quantity'] ?? 1));
                $vid = !empty($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
                if ($pid > 0) {
                    $items[] = [
                        'product_id' => $pid,
                        'variant_id' => $vid,
                        'quantity' => $qty,
                    ];
                }
            }

            if (empty($items)) {
                set_flash('error', 'Please add at least one product with quantity greater than zero.');
            } else {
                $res = create_stock_transfer($src, $dst, $items, $notes, (int)$user['id']);
                if ($res['success']) {
                    set_flash('success', "Stock Transfer #{$res['transfer_number']} created successfully with " . count($items) . " item line(s)!");
                } else {
                    set_flash('error', $res['error'] ?? 'Failed to create transfer.');
                }
            }
        } elseif ($action === 'approve_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = approve_stock_transfer($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Stock transfer approved.');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to approve transfer.');
            }
        } elseif ($action === 'pick_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = pick_stock_transfer($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Stock transfer marked as picked.');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to pick transfer.');
            }
        } elseif ($action === 'dispatch_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = dispatch_stock_transfer($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Stock transfer marked as dispatched.');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to dispatch transfer.');
            }
        } elseif ($action === 'ship_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = ship_stock_transfer_in_transit($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Stock is now in transit (source warehouse reduced; company total unchanged).');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to start in-transit.');
            }
        } elseif ($action === 'receive_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = receive_stock_transfer($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Transfer received at destination (warehouse moved; company total unchanged).');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to receive transfer.');
            }
        }
        redirect(APP_URL . '/transfers.php');
    }
}

$transfers = get_stock_transfers(100);
$warehouses = get_warehouses();
$products = get_products();
$variantsByProduct = [];
try {
    $vStmt = get_db()->prepare('
        SELECT id, product_id, variant_name, attribute_values, sku, barcode, stock_quantity
        FROM product_variants
        WHERE business_id = :bid AND status = "active"
        ORDER BY id ASC
    ');
    $vStmt->execute(['bid' => current_business_id()]);
    foreach ($vStmt->fetchAll() as $v) {
        $attrs = parse_variant_size_colour($v);
        $pid = (int) $v['product_id'];
        $variantsByProduct[$pid][] = [
            'id' => (int) $v['id'],
            'name' => (string) $v['variant_name'],
            'sku' => (string) $v['sku'],
            'barcode' => (string) ($v['barcode'] ?? ''),
            'stock' => (int) $v['stock_quantity'],
            'size' => $attrs['size'],
            'colour' => $attrs['colour'],
        ];
    }
} catch (Throwable $e) {
    $variantsByProduct = [];
}

$defaultSourceWarehouseId = !empty($warehouses[0]['id']) ? (int) $warehouses[0]['id'] : 0;
$defaultDestWarehouseId = !empty($warehouses[1]['id'])
    ? (int) $warehouses[1]['id']
    : (!empty($warehouses[0]['id']) && count($warehouses) > 1 ? (int) $warehouses[count($warehouses) - 1]['id'] : 0);
$canCreateTransfer = count($warehouses) >= 2;
$pageTitle = 'Warehouse Stock Transfers';
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
                        <h1 class="page-title">Warehouse Stock Transfers</h1>
                        <p class="page-subtitle">Requested &rarr; Approved &rarr; Picked &rarr; Dispatched &rarr; In Transit &rarr; Received. Destination receives with one button (no piece-by-piece scan). Company total stays the same; only warehouse rows move.</p>
                    </div>
                    <div>
                        <button type="button" onclick="document.getElementById('transferModal').style.display='flex'" class="header-btn">
                            + New Stock Transfer
                        </button>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success" style="margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger" style="margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title">Stock Transfers History</h2>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Transfer #</th>
                                    <th>Origin &rarr; Target</th>
                                    <th>Total Units</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transfers)): ?>
                                    <tr><td colspan="6" style="text-align: center; padding: 20px; color: #64748b;">No stock transfers found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($transfers as $trf): ?>
                                        <tr>
                                            <td>
                                                <button type="button" onclick="openTransferDetails(<?= (int)$trf['id'] ?>)" style="background: none; border: none; padding: 0; font-family: monospace; font-weight: 700; color: var(--saas-primary); cursor: pointer; text-decoration: underline;" title="View items in this transfer">
                                                    <?= e($trf['transfer_number']) ?>
                                                </button>
                                            </td>
                                            <td style="font-size: 12.5px;">
                                                <span style="font-weight: 700; color: var(--saas-navy-950);"><?= e($trf['source_warehouse_name']) ?></span>
                                                <span style="color: var(--saas-slate-400); margin: 0 4px;">&rarr;</span>
                                                <span style="font-weight: 700; color: var(--saas-navy-950);"><?= e($trf['dest_warehouse_name']) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-info"><?= (int)$trf['total_units'] ?> units</span>
                                                <span style="font-size: 11.5px; color: var(--saas-slate-500); margin-left: 4px;">(<?= (int)$trf['total_items'] ?> item<?= (int)$trf['total_items'] === 1 ? '' : 's' ?>)</span>
                                            </td>
                                            <td style="font-size: 12px; color: var(--saas-slate-500);"><?= date('d M Y, h:i A', strtotime($trf['created_at'])) ?></td>
                                            <td>
                                                <?php
                                                $badge = 'badge-secondary';
                                                $st = (string) $trf['status'];
                                                if ($st === 'approved') {
                                                    $badge = 'badge-info';
                                                } elseif ($st === 'picked') {
                                                    $badge = 'badge-info';
                                                } elseif ($st === 'dispatched') {
                                                    $badge = 'badge-warning';
                                                } elseif ($st === 'in_transit') {
                                                    $badge = 'badge-warning';
                                                } elseif ($st === 'received') {
                                                    $badge = 'badge-success';
                                                }
                                                ?>
                                                <span class="badge <?= $badge ?>">
                                                    <?= strtoupper(str_replace('_', ' ', $st)) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right; white-space: nowrap;">
                                                <button type="button" onclick="openTransferDetails(<?= (int)$trf['id'] ?>)" class="header-btn-secondary" style="padding: 4px 8px; font-size: 11.5px; margin-right: 4px;" title="View transfer item details">
                                                    Items
                                                </button>
                                                <?php
                                                $actionBtn = null;
                                                if (in_array($st, ['draft', 'requested'], true)) {
                                                    $actionBtn = ['approve_transfer', 'Approve', '#2563eb'];
                                                } elseif ($st === 'approved') {
                                                    $actionBtn = ['pick_transfer', 'Mark Picked', '#4f46e5'];
                                                } elseif ($st === 'picked') {
                                                    $actionBtn = ['dispatch_transfer', 'Dispatch', '#d97706'];
                                                } elseif ($st === 'dispatched') {
                                                    $actionBtn = ['ship_transfer', 'In Transit', '#b45309'];
                                                } elseif ($st === 'in_transit') {
                                                    $actionBtn = ['receive_transfer', 'Receive', '#059669'];
                                                }
                                                ?>
                                                <?php if ($actionBtn): ?>
                                                    <form method="POST" action="<?= asset('transfers.php') ?>" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="action" value="<?= e($actionBtn[0]) ?>">
                                                        <input type="hidden" name="transfer_id" value="<?= (int) $trf['id'] ?>">
                                                        <button type="submit" class="header-btn" style="padding: 4px 10px; font-size: 11.5px; background: <?= e($actionBtn[2]) ?>;">
                                                            <?= e($actionBtn[1]) ?>
                                                        </button>
                                                    </form>
                                                <?php elseif ($st === 'received'): ?>
                                                    <span style="font-size: 12px; color: var(--saas-slate-400);">Completed</span>
                                                <?php else: ?>
                                                    <span style="font-size: 12px; color: var(--saas-slate-400);">—</span>
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

    <!-- Create Transfer Modal Backdrop -->
    <div id="transferModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2000; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: var(--saas-radius-lg); width: 100%; max-width: 820px; max-height: 90vh; display: flex; flex-direction: column; padding: 24px; box-shadow: var(--saas-shadow-lg); overflow: hidden;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--saas-border-light); padding-bottom: 12px;">
                <div>
                    <h3 style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950); margin: 0;">Create Stock Transfer</h3>
                    <p style="font-size: 12px; color: #64748b; margin: 2px 0 0;">Move stock between warehouses with product variant (size & colour) selection.</p>
                </div>
                <button type="button" onclick="document.getElementById('transferModal').style.display='none'" style="background: none; border: none; font-size: 22px; cursor: pointer; color: var(--saas-slate-400); line-height: 1;">&times;</button>
            </div>
            <?php if (!$canCreateTransfer): ?>
                <p style="font-size: 13px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                    You need at least <strong>two warehouses</strong> to transfer stock. Add another warehouse under Outlets / Warehouses, then try again.
                </p>
            <?php endif; ?>
            <form id="createTransferForm" method="POST" action="<?= asset('transfers.php') ?>" style="display: flex; flex-direction: column; gap: 14px; overflow-y: auto; padding-right: 4px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="create_transfer">

                <!-- Warehouses Selection Grid -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Source Warehouse (Origin) *</label>
                        <select name="source_warehouse_id" id="transferSourceWh" required class="form-control" style="width: 100%;" <?= $canCreateTransfer ? '' : 'disabled' ?>>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $defaultSourceWarehouseId ? 'selected' : '' ?>><?= e($w['name']) ?> (<?= e($w['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Destination Warehouse (Target) *</label>
                        <select name="dest_warehouse_id" id="transferDestWh" required class="form-control" style="width: 100%;" <?= $canCreateTransfer ? '' : 'disabled' ?>>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $defaultDestWarehouseId ? 'selected' : '' ?>><?= e($w['name']) ?> (<?= e($w['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Transfer Items Section -->
                <div style="background: #f8fafc; border: 1px solid var(--saas-border-light); border-radius: var(--saas-radius-md); padding: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <span style="font-size: 13px; font-weight: 700; color: var(--saas-navy-950);">Transfer Items *</span>
                        <button type="button" id="btnAddTransferItem" class="header-btn" style="padding: 5px 12px; font-size: 12px; background: #2563eb;">
                            + Add Item
                        </button>
                    </div>
                    <div class="table-wrap" style="background: #ffffff; border: 1px solid var(--saas-border-light); border-radius: 6px; overflow: visible;">
                        <table class="saas-table" style="margin: 0; min-width: 600px;">
                            <thead>
                                <tr style="background: #f1f5f9;">
                                    <th style="width: 42%; font-size: 11.5px;">Product</th>
                                    <th style="width: 38%; font-size: 11.5px;">Size & Colour (Variant)</th>
                                    <th style="width: 14%; font-size: 11.5px;">Qty</th>
                                    <th style="width: 6%; text-align: center;"></th>
                                </tr>
                            </thead>
                            <tbody id="transferItemsBody">
                                <!-- Dynamic rows inserted by JS -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Items Summary -->
                    <div style="display: flex; justify-content: flex-end; gap: 18px; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-top: 10px;">
                        <span>Total Items: <span id="trfSummaryLines" style="color: var(--saas-primary);">0</span></span>
                        <span>Total Units: <span id="trfSummaryUnits" style="color: var(--saas-primary);">0</span></span>
                    </div>
                </div>

                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Transfer Notes / Reason (Optional)</label>
                    <input type="text" name="notes" class="form-control" placeholder="e.g. Branch replenishment, store stock rebalance" style="width: 100%;">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; border-top: 1px solid var(--saas-border-light); padding-top: 12px;">
                    <button type="button" onclick="document.getElementById('transferModal').style.display='none'" class="header-btn-secondary" style="padding: 8px 16px;">Cancel</button>
                    <button type="submit" id="btnSubmitTransfer" class="header-btn" style="padding: 8px 22px;" <?= $canCreateTransfer ? '' : 'disabled' ?>>Create Transfer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Transfer Details Modal Backdrop -->
    <div id="transferDetailsModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2010; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: var(--saas-radius-lg); width: 100%; max-width: 720px; max-height: 90vh; display: flex; flex-direction: column; padding: 24px; box-shadow: var(--saas-shadow-lg); overflow: hidden;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid var(--saas-border-light); padding-bottom: 12px;">
                <div>
                    <h3 id="detailsModalTitle" style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950); margin: 0;">Stock Transfer Details</h3>
                    <p id="detailsModalSub" style="font-size: 12px; color: #64748b; margin: 2px 0 0;"></p>
                </div>
                <button type="button" onclick="document.getElementById('transferDetailsModal').style.display='none'" style="background: none; border: none; font-size: 22px; cursor: pointer; color: var(--saas-slate-400); line-height: 1;">&times;</button>
            </div>
            <div id="detailsModalContent" style="overflow-y: auto; display: flex; flex-direction: column; gap: 12px;">
                <div style="display: flex; justify-content: center; padding: 30px; color: #64748b;">
                    Loading items...
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; margin-top: 12px; border-top: 1px solid var(--saas-border-light); padding-top: 12px;">
                <button type="button" onclick="document.getElementById('transferDetailsModal').style.display='none'" class="header-btn-secondary" style="padding: 8px 18px;">Close</button>
            </div>
        </div>
    </div>

    <script>
        const TRF_PRODUCTS = <?= json_encode(array_values(array_map(function($p) {
            return [
                'id' => (int) $p['id'],
                'name' => (string) $p['name'],
                'sku' => (string) $p['sku'],
                'stock' => (int) $p['stock_quantity'],
            ];
        }, $products))) ?>;

        const TRF_VARIANTS = <?= json_encode($variantsByProduct) ?>;

        (function () {
            const srcSel = document.getElementById('transferSourceWh');
            const dstSel = document.getElementById('transferDestWh');
            const form = document.getElementById('createTransferForm');
            const itemsBody = document.getElementById('transferItemsBody');
            const btnAdd = document.getElementById('btnAddTransferItem');
            const summaryLines = document.getElementById('trfSummaryLines');
            const summaryUnits = document.getElementById('trfSummaryUnits');

            function syncDestinationOptions() {
                if (!srcSel || !dstSel) return;
                const srcId = srcSel.value;
                let pickedValid = false;
                Array.from(dstSel.options).forEach(function (opt) {
                    const same = opt.value === srcId;
                    opt.disabled = same;
                    if (same && dstSel.value === srcId) {
                        return;
                    }
                    if (opt.value === dstSel.value && !same) {
                        pickedValid = true;
                    }
                });
                if (dstSel.value === srcId || !pickedValid) {
                    const fallback = Array.from(dstSel.options).find(function (o) { return o.value !== srcId && !o.disabled; });
                    if (fallback) {
                        dstSel.value = fallback.value;
                    }
                }
            }

            if (srcSel) srcSel.addEventListener('change', syncDestinationOptions);
            if (dstSel) {
                dstSel.addEventListener('change', function () {
                    if (dstSel.value === srcSel.value) {
                        alert('Destination warehouse must be different from source.');
                        syncDestinationOptions();
                    }
                });
            }
            syncDestinationOptions();

            function recalculateTotals() {
                let lines = 0;
                let units = 0;
                const rows = itemsBody.querySelectorAll('tr');
                rows.forEach(function (row) {
                    const pSel = row.querySelector('.trf-item-prod');
                    const qtyInp = row.querySelector('.trf-item-qty');
                    if (pSel && pSel.value) {
                        lines++;
                        const q = parseInt(qtyInp ? qtyInp.value : '0', 10) || 0;
                        units += Math.max(0, q);
                    }
                });
                if (summaryLines) summaryLines.textContent = lines;
                if (summaryUnits) summaryUnits.textContent = units;
            }

            function updateVariantOptions(row, pid, selectedVid) {
                const varSel = row.querySelector('.trf-item-var');
                if (!varSel) return;
                varSel.innerHTML = '';

                const variants = TRF_VARIANTS[pid] || [];
                if (variants.length === 0) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = 'Standard (No variant)';
                    varSel.appendChild(opt);
                } else {
                    const defaultOpt = document.createElement('option');
                    defaultOpt.value = '';
                    defaultOpt.textContent = '-- Choose Size & Colour --';
                    varSel.appendChild(defaultOpt);

                    variants.forEach(function (v) {
                        const opt = document.createElement('option');
                        opt.value = v.id;
                        let label = '';
                        if (v.colour && v.size) {
                            label = v.colour + ' / ' + v.size;
                        } else if (v.name) {
                            label = v.name;
                        } else {
                            label = 'Variant #' + v.id;
                        }
                        if (v.sku) label += ' (SKU: ' + v.sku + ')';
                        if (typeof v.stock !== 'undefined') label += ' [Stock: ' + v.stock + ']';
                        opt.textContent = label;
                        if (selectedVid && String(selectedVid) === String(v.id)) {
                            opt.selected = true;
                        }
                        varSel.appendChild(opt);
                    });
                }
            }

            function addTransferRow(defaultPid, defaultVid, defaultQty) {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="padding: 6px 8px;">
                        <select class="form-control trf-item-prod" required style="width: 100%; font-size: 12.5px;">
                            <option value="">-- Choose Product --</option>
                            ${TRF_PRODUCTS.map(p => `
                                <option value="${p.id}" ${defaultPid && String(defaultPid) === String(p.id) ? 'selected' : ''}>
                                    ${escapeHtml(p.name)} (SKU: ${escapeHtml(p.sku)} - Stock: ${p.stock})
                                </option>
                            `).join('')}
                        </select>
                    </td>
                    <td style="padding: 6px 8px;">
                        <select class="form-control trf-item-var" style="width: 100%; font-size: 12.5px;">
                            <option value="">-- Choose Product First --</option>
                        </select>
                    </td>
                    <td style="padding: 6px 8px;">
                        <input type="number" min="1" step="1" value="${defaultQty || 1}" required class="form-control trf-item-qty" style="width: 100%; font-size: 12.5px;">
                    </td>
                    <td style="padding: 6px 8px; text-align: center;">
                        <button type="button" class="btn-del-trf-row" style="background: none; border: none; font-size: 18px; color: #ef4444; cursor: pointer; line-height: 1;" title="Remove row">&times;</button>
                    </td>
                `;
                itemsBody.appendChild(tr);

                const pSel = tr.querySelector('.trf-item-prod');
                const qtyInp = tr.querySelector('.trf-item-qty');
                const delBtn = tr.querySelector('.btn-del-trf-row');

                pSel.addEventListener('change', function () {
                    updateVariantOptions(tr, pSel.value, null);
                    recalculateTotals();
                });

                qtyInp.addEventListener('input', recalculateTotals);

                delBtn.addEventListener('click', function () {
                    if (itemsBody.querySelectorAll('tr').length <= 1) {
                        // Reset line instead of removing sole row
                        pSel.value = '';
                        updateVariantOptions(tr, '', null);
                        qtyInp.value = 1;
                    } else {
                        tr.remove();
                    }
                    recalculateTotals();
                });

                if (defaultPid) {
                    updateVariantOptions(tr, defaultPid, defaultVid);
                } else if (TRF_PRODUCTS.length > 0 && itemsBody.querySelectorAll('tr').length === 1) {
                    pSel.value = TRF_PRODUCTS[0].id;
                    updateVariantOptions(tr, TRF_PRODUCTS[0].id, null);
                }

                recalculateTotals();
            }

            if (btnAdd) {
                btnAdd.addEventListener('click', function () {
                    addTransferRow();
                });
            }

            // Initialize with first row if empty
            if (itemsBody && itemsBody.querySelectorAll('tr').length === 0) {
                addTransferRow();
            }

            if (form) {
                form.addEventListener('submit', function (e) {
                    if (srcSel.value === dstSel.value) {
                        e.preventDefault();
                        alert('Source and destination warehouse cannot be identical. Please choose a different destination.');
                        return;
                    }

                    const rows = itemsBody.querySelectorAll('tr');
                    let validCount = 0;
                    rows.forEach(function (row, index) {
                        const pSel = row.querySelector('.trf-item-prod');
                        const vSel = row.querySelector('.trf-item-var');
                        const qtyInp = row.querySelector('.trf-item-qty');

                        if (pSel && pSel.value) {
                            pSel.name = `items[${validCount}][product_id]`;
                            if (vSel && vSel.value) {
                                vSel.name = `items[${validCount}][variant_id]`;
                            } else if (vSel) {
                                vSel.removeAttribute('name');
                            }
                            if (qtyInp) {
                                qtyInp.name = `items[${validCount}][quantity]`;
                            }
                            validCount++;
                        } else {
                            if (pSel) pSel.removeAttribute('name');
                            if (vSel) vSel.removeAttribute('name');
                            if (qtyInp) qtyInp.removeAttribute('name');
                        }
                    });

                    if (validCount === 0) {
                        e.preventDefault();
                        alert('Please select at least one product to transfer.');
                    }
                });
            }
        })();

        function escapeHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function openTransferDetails(transferId) {
            const modal = document.getElementById('transferDetailsModal');
            const title = document.getElementById('detailsModalTitle');
            const sub = document.getElementById('detailsModalSub');
            const content = document.getElementById('detailsModalContent');

            title.textContent = 'Stock Transfer Details';
            sub.textContent = 'Loading details for transfer #' + transferId + '...';
            content.innerHTML = '<div style="display: flex; justify-content: center; padding: 30px; color: #64748b;">Loading transfer items...</div>';
            modal.style.display = 'flex';

            fetch('transfers.php?action=get_transfer_details&id=' + encodeURIComponent(transferId))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success || !data.transfer) {
                        content.innerHTML = '<div class="saas-alert saas-alert-danger">' + escapeHtml(data.error || 'Failed to load transfer.') + '</div>';
                        return;
                    }
                    const t = data.transfer;
                    title.textContent = 'Transfer #' + t.transfer_number;
                    sub.innerHTML = `
                        <strong>${escapeHtml(t.source_warehouse_name)}</strong> &rarr; <strong>${escapeHtml(t.dest_warehouse_name)}</strong>
                        &bull; Status: <span style="text-transform: uppercase; font-weight: 700;">${escapeHtml(t.status)}</span>
                        &bull; Created: ${escapeHtml(t.created_at)}
                    `;

                    let notesHtml = '';
                    if (t.notes) {
                        notesHtml = `<div style="background: #f8fafc; border-left: 3px solid var(--saas-primary); padding: 8px 12px; font-size: 12.5px; margin-bottom: 6px;">
                            <strong>Notes:</strong> ${escapeHtml(t.notes)}
                        </div>`;
                    }

                    const items = t.items || [];
                    let itemsHtml = `
                        ${notesHtml}
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product Name</th>
                                        <th>SKU</th>
                                        <th>Size & Colour (Variant)</th>
                                        <th style="text-align: right;">Qty Requested</th>
                                        <th style="text-align: right;">Qty Received</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;

                    if (items.length === 0) {
                        itemsHtml += `<tr><td colspan="6" style="text-align:center; padding: 18px; color: #64748b;">No items recorded in this transfer.</td></tr>`;
                    } else {
                        items.forEach(function (it, idx) {
                            let varText = '—';
                            if (it.variant_name) {
                                varText = escapeHtml(it.variant_name);
                            } else if (it.variant_sku) {
                                varText = 'SKU: ' + escapeHtml(it.variant_sku);
                            }
                            itemsHtml += `
                                <tr>
                                    <td>${idx + 1}</td>
                                    <td><strong>${escapeHtml(it.product_name)}</strong></td>
                                    <td style="font-family: monospace; font-size: 12px;">${escapeHtml(it.variant_sku || it.product_sku)}</td>
                                    <td><span class="badge badge-info">${varText}</span></td>
                                    <td style="text-align: right; font-weight: 700;">${parseInt(it.quantity_requested, 10)}</td>
                                    <td style="text-align: right; font-weight: 700; color: ${parseInt(it.quantity_received, 10) > 0 ? '#059669' : '#64748b'};">${parseInt(it.quantity_received, 10)}</td>
                                </tr>
                            `;
                        });
                    }

                    itemsHtml += `
                                </tbody>
                            </table>
                        </div>
                    `;
                    content.innerHTML = itemsHtml;
                })
                .catch(function (err) {
                    content.innerHTML = '<div class="saas-alert saas-alert-danger">Network error while fetching transfer details.</div>';
                });
        }
    </script>
</body>
</html>

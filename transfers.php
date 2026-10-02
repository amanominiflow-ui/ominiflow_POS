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

require_auth();

$user = current_user();
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
            $pid = (int)($_POST['product_id'] ?? 0);
            $qty = max(1, (int)($_POST['quantity'] ?? 1));
            $notes = $_POST['notes'] ?? '';

            $res = create_stock_transfer($src, $dst, [['product_id' => $pid, 'quantity' => $qty]], $notes, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', "Stock Transfer #{$res['transfer_number']} created successfully!");
            } else {
                set_flash('error', $res['error'] ?? 'Failed to create transfer.');
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
                                            <td><strong style="font-family: monospace;"><?= e($trf['transfer_number']) ?></strong></td>
                                            <td style="font-size: 12.5px;">
                                                <span style="font-weight: 700; color: var(--saas-navy-950);"><?= e($trf['source_warehouse_name']) ?></span>
                                                <span style="color: var(--saas-slate-400); margin: 0 4px;">&rarr;</span>
                                                <span style="font-weight: 700; color: var(--saas-navy-950);"><?= e($trf['dest_warehouse_name']) ?></span>
                                            </td>
                                            <td><span class="badge badge-info"><?= (int)$trf['total_units'] ?> units</span></td>
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
                                            <td style="text-align: right;">
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
        <div style="background: #ffffff; border-radius: var(--saas-radius-lg); width: 100%; max-width: 480px; padding: 24px; box-shadow: var(--saas-shadow-lg);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950);">Create Stock Transfer</h3>
                <button type="button" onclick="document.getElementById('transferModal').style.display='none'" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--saas-slate-400);">&times;</button>
            </div>
            <?php if (!$canCreateTransfer): ?>
                <p style="font-size: 13px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                    You need at least <strong>two warehouses</strong> to transfer stock. Add another warehouse under Outlets / Warehouses, then try again.
                </p>
            <?php endif; ?>
            <form id="createTransferForm" method="POST" action="<?= asset('transfers.php') ?>" style="display: flex; flex-direction: column; gap: 12px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="create_transfer">
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
                    <p id="transferWhHint" style="font-size: 11.5px; color: #64748b; margin: 6px 0 0;">Destination must be different from source.</p>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Select Product *</label>
                    <select name="product_id" required class="form-control" style="width: 100%;">
                        <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (SKU: <?= e($p['sku']) ?> - Stock: <?= (int)$p['stock_quantity'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Quantity to Transfer *</label>
                    <input type="number" name="quantity" min="1" value="1" required class="form-control" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Transfer Notes / Reason</label>
                    <input type="text" name="notes" class="form-control" placeholder="e.g. Branch replenishment" style="width: 100%;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px;">
                    <button type="button" onclick="document.getElementById('transferModal').style.display='none'" class="header-btn-secondary" style="padding: 8px 16px;">Cancel</button>
                    <button type="submit" class="header-btn" style="padding: 8px 18px;" <?= $canCreateTransfer ? '' : 'disabled' ?>>Create Transfer</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        (function () {
            const srcSel = document.getElementById('transferSourceWh');
            const dstSel = document.getElementById('transferDestWh');
            const form = document.getElementById('createTransferForm');
            if (!srcSel || !dstSel) return;

            function syncDestinationOptions() {
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

            srcSel.addEventListener('change', syncDestinationOptions);
            dstSel.addEventListener('change', function () {
                if (dstSel.value === srcSel.value) {
                    alert('Destination warehouse must be different from source.');
                    syncDestinationOptions();
                }
            });
            syncDestinationOptions();

            if (form) {
                form.addEventListener('submit', function (e) {
                    if (srcSel.value === dstSel.value) {
                        e.preventDefault();
                        alert('Source and destination warehouse cannot be identical. Please choose a different destination.');
                    }
                });
            }
        })();
    </script>
</body>
</html>

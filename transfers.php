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
        } elseif ($action === 'dispatch_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = dispatch_stock_transfer($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Stock Transfer dispatched into In-Transit state.');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to dispatch transfer.');
            }
        } elseif ($action === 'receive_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = receive_stock_transfer($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Stock Transfer received and destination inventory updated!');
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
                        <p class="page-subtitle">Inter-warehouse inventory movements (Draft &rarr; In Transit &rarr; Received) with audit tracking.</p>
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
                                                if ($trf['status'] === 'in_transit') $badge = 'badge-warning';
                                                elseif ($trf['status'] === 'received') $badge = 'badge-success';
                                                ?>
                                                <span class="badge <?= $badge ?>">
                                                    <?= strtoupper(str_replace('_', ' ', $trf['status'])) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right;">
                                                <?php if (in_array($trf['status'], ['draft', 'requested'], true)): ?>
                                                    <form method="POST" action="<?= asset('transfers.php') ?>" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="action" value="dispatch_transfer">
                                                        <input type="hidden" name="transfer_id" value="<?= $trf['id'] ?>">
                                                        <button type="submit" class="header-btn" style="padding: 4px 10px; font-size: 11.5px; background: #d97706;">
                                                            Dispatch
                                                        </button>
                                                    </form>
                                                <?php elseif ($trf['status'] === 'in_transit'): ?>
                                                    <form method="POST" action="<?= asset('transfers.php') ?>" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="action" value="receive_transfer">
                                                        <input type="hidden" name="transfer_id" value="<?= $trf['id'] ?>">
                                                        <button type="submit" class="header-btn" style="padding: 4px 10px; font-size: 11.5px; background: #059669;">
                                                            Receive
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="font-size: 12px; color: var(--saas-slate-400);">Completed</span>
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
            <form method="POST" action="<?= asset('transfers.php') ?>" style="display: flex; flex-direction: column; gap: 12px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="create_transfer">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Source Warehouse (Origin) *</label>
                    <select name="source_warehouse_id" required class="form-control" style="width: 100%;">
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= e($w['name']) ?> (<?= e($w['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Destination Warehouse (Target) *</label>
                    <select name="dest_warehouse_id" required class="form-control" style="width: 100%;">
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= e($w['name']) ?> (<?= e($w['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
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
                    <button type="submit" class="header-btn" style="padding: 8px 18px;">Create Transfer</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

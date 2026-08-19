<?php
/**
 * Purchase Returns & Vendor Debit Notes Screen (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/purchases_db.php';
require_once __DIR__ . '/includes/purchase_returns_db.php';

require_auth();

$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/purchase-returns.php');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_return') {
            $poId = (int)($_POST['purchase_order_id'] ?? 0);
            $pid = (int)($_POST['product_id'] ?? 0);
            $qty = max(1, (int)($_POST['quantity'] ?? 1));
            $cost = (float)($_POST['unit_cost'] ?? 0);
            $mode = $_POST['refund_mode'] ?? 'vendor_credit';
            $reason = $_POST['reason'] ?? '';

            $res = create_purchase_return($poId, [['product_id' => $pid, 'quantity' => $qty, 'unit_cost' => $cost]], $mode, $reason, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', "Purchase Return #{$res['return_number']} processed successfully!");
            } else {
                set_flash('error', $res['error'] ?? 'Failed to process return.');
            }
        }
        redirect(APP_URL . '/purchase-returns.php');
    }
}

$returns = get_purchase_returns();
$purchaseOrders = get_purchase_orders('received');
$pageTitle = 'Purchase Returns & Debit Notes';
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
                        <h1 class="page-title">Purchase Returns & Vendor Debit Notes</h1>
                        <p class="page-subtitle">Return defective/excess inventory back to suppliers with automatic stock deduction and credit ledger.</p>
                    </div>
                    <div>
                        <button type="button" onclick="document.getElementById('returnModal').style.display='flex'" class="header-btn">
                            + New Purchase Return
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
                        <h2 class="section-title">Purchase Returns History</h2>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Debit Note #</th>
                                    <th>Purchase Order</th>
                                    <th>Supplier / Vendor</th>
                                    <th style="text-align: right;">Return Total</th>
                                    <th>Refund Settlement</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($returns)): ?>
                                    <tr><td colspan="6" style="text-align: center; padding: 20px; color: #64748b;">No purchase returns found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($returns as $ret): ?>
                                        <tr>
                                            <td><strong style="font-family: monospace; color: #b91c1c;"><?= e($ret['return_number']) ?></strong></td>
                                            <td><span style="font-family: monospace;"><?= e($ret['po_number'] ?? 'Direct PO') ?></span></td>
                                            <td><strong><?= e($ret['vendor_name']) ?></strong></td>
                                            <td style="text-align: right; font-weight: 700; color: #b91c1c;">₹<?= number_format((float)$ret['total_amount'], 2) ?></td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?= strtoupper(str_replace('_', ' ', $ret['refund_method'] ?? 'vendor_credit')) ?>
                                                </span>
                                            </td>
                                            <td style="font-size: 12px; color: var(--saas-slate-500);"><?= date('d M Y, h:i A', strtotime($ret['created_at'])) ?></td>
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

    <!-- Return Modal Backdrop -->
    <div id="returnModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2000; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: var(--saas-radius-lg); width: 100%; max-width: 480px; padding: 24px; box-shadow: var(--saas-shadow-lg);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950);">Process Purchase Return</h3>
                <button type="button" onclick="document.getElementById('returnModal').style.display='none'" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--saas-slate-400);">&times;</button>
            </div>
            <form method="POST" action="<?= asset('purchase-returns.php') ?>" style="display: flex; flex-direction: column; gap: 12px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="create_return">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Purchase Order *</label>
                    <select name="purchase_order_id" required class="form-control" style="width: 100%;">
                        <?php foreach ($purchaseOrders as $po): ?>
                            <option value="<?= $po['id'] ?>"><?= e($po['po_number']) ?> - <?= e($po['vendor_name']) ?> (₹<?= number_format((float)$po['total_amount'], 2) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Product ID *</label>
                    <input type="number" name="product_id" required class="form-control" placeholder="Product ID" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Return Quantity *</label>
                    <input type="number" name="quantity" min="1" value="1" required class="form-control" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Unit Cost (₹) *</label>
                    <input type="number" step="0.01" name="unit_cost" required class="form-control" placeholder="Cost per unit" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Refund Settlement</label>
                    <select name="refund_mode" class="form-control" style="width: 100%;">
                        <option value="vendor_credit">Vendor Credit Note</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="cash">Cash Refund</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Return Reason</label>
                    <input type="text" name="reason" class="form-control" placeholder="e.g. Damaged during shipment" style="width: 100%;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px;">
                    <button type="button" onclick="document.getElementById('returnModal').style.display='none'" class="header-btn-secondary" style="padding: 8px 16px;">Cancel</button>
                    <button type="submit" class="header-btn" style="padding: 8px 18px; background: #b91c1c;">Submit Return</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

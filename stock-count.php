<?php
/**
 * OminiFlow POS - Physical Inventory Stock Count & Audit Screen (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/stock_counts_db.php';

require_auth();

$pageTitle = 'Stock Count & Inventory Audit';

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/stock-count.php');
    }

    if ($action === 'start_count') {
        $notes = (string) ($_POST['notes'] ?? '');
        $res = start_stock_count((int)$userId, $notes);
        if ($res['success']) {
            set_flash('success', "Stock Count Audit #{$res['count_number']} started! All active product stocks have been snapshotted for counting.");
            redirect(APP_URL . '/stock-count.php?id=' . $res['count_id']);
        } else {
            set_flash('error', $res['error'] ?? 'Could not start stock count.');
            redirect(APP_URL . '/stock-count.php');
        }
    } elseif ($action === 'update_count_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $counted = (int) ($_POST['counted_qty'] ?? 0);
        $res = update_stock_count_item($itemId, $counted);
        header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    } elseif ($action === 'reconcile_count') {
        $countId = (int) ($_POST['count_id'] ?? 0);
        $res = reconcile_and_complete_stock_count($countId, (int)$userId);
        if ($res['success']) {
            set_flash('success', "Stock Audit reconciled & completed successfully ({$res['adjustments_made']} adjustments made to live inventory).");
        } else {
            set_flash('error', $res['error'] ?? 'Could not reconcile stock count.');
        }
        redirect(APP_URL . '/stock-count.php');
    }
}

$selectedId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$activeCount = null;
if ($selectedId) {
    $activeCount = get_stock_count_by_id($selectedId);
} else {
    // Check if there is an in-progress count
    $db = get_db();
    $stmt = $db->query('SELECT id FROM stock_counts WHERE status = "in_progress" ORDER BY id DESC LIMIT 1');
    $progId = $stmt->fetchColumn();
    if ($progId) {
        $activeCount = get_stock_count_by_id((int)$progId);
    }
}

$pastCounts = get_stock_counts(25);
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
                        <h1 class="page-title">Physical Stock Count & Audit</h1>
                        <p class="page-subtitle">Perform physical cycle counts, identify variances against system inventory, and approve batch stock adjustments.</p>
                    </div>
                    <div>
                        <?php if (!$activeCount || $activeCount['status'] !== 'in_progress'): ?>
                            <button type="button" class="header-btn" id="startCountBtn" style="padding: 10px 20px; display: inline-flex; align-items: center; gap: 8px;">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                <span>Start Physical Count</span>
                            </button>
                        <?php endif; ?>
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

                <!-- Active In-Progress Count Work Area -->
                <?php if ($activeCount && $activeCount['status'] === 'in_progress'): ?>
                    <div class="section-card" style="border: 2px solid #3b82f6; margin-bottom: 28px; box-shadow: 0 4px 15px rgba(59,130,246,0.12);">
                        <div class="section-header" style="background: #eff6ff; border-bottom: 1px solid #bfdbfe;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span class="badge badge-warning" style="background:#fef3c7; color:#92400e; font-size:11.5px; font-weight:800;">⏳ IN PROGRESS AUDIT</span>
                                    <h2 class="section-heading" style="color: #1e3a8a;">Audit #<?= e($activeCount['count_number']) ?></h2>
                                </div>
                                <div class="section-subheading" style="color: #475569; margin-top: 4px;">
                                    Started by <strong><?= e($activeCount['auditor_name']) ?></strong> on <?= date('M d, Y • h:i A', strtotime($activeCount['created_at'])) ?>
                                </div>
                            </div>
                            <form method="POST" action="<?= asset('stock-count.php') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reconcile_count">
                                <input type="hidden" name="count_id" value="<?= $activeCount['id'] ?>">
                                <button type="submit" class="header-btn" style="background: #047857; border: 0; padding: 9px 18px; display: inline-flex; align-items: center; gap: 8px;" onclick="return confirm('Reconcile and apply all variance adjustments to live inventory?');">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span>Approve & Reconcile Live Stock</span>
                                </button>
                            </form>
                        </div>

                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Product Name</th>
                                        <th>SKU</th>
                                        <th>System Stock (Expected)</th>
                                        <th style="width: 160px; text-align: center;">Counted Physical Stock</th>
                                        <th style="text-align: right;">Variance / Discrepancy</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeCount['items'] as $it): ?>
                                        <tr>
                                            <td><strong><?= e($it['product_name']) ?></strong></td>
                                            <td><span style="font-family: monospace; color: var(--saas-slate-600);"><?= e($it['product_sku']) ?></span></td>
                                            <td><strong><?= (int)$it['expected_qty'] ?> units</strong></td>
                                            <td style="text-align: center;">
                                                <input
                                                    type="number"
                                                    class="form-control count-item-input"
                                                    data-id="<?= $it['id'] ?>"
                                                    value="<?= (int)$it['counted_qty'] ?>"
                                                    style="width: 90px; margin: 0 auto; text-align: center; font-weight: 700;"
                                                >
                                            </td>
                                            <td style="text-align: right;">
                                                <span class="variance-badge badge <?= (int)$it['difference_qty'] === 0 ? 'badge-secondary' : ((int)$it['difference_qty'] > 0 ? 'badge-success' : 'badge-danger') ?>">
                                                    <?= ((int)$it['difference_qty'] > 0 ? '+' : '') . (int)$it['difference_qty'] ?> units
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Past Stock Count Audits Card -->
                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h2 class="section-heading">Stock Count Audit History</h2>
                            <p class="section-subheading">Historical reconciliation reports and variance logs</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Audit #</th>
                                    <th>Auditor</th>
                                    <th>Started</th>
                                    <th>Completed</th>
                                    <th>Items Checked</th>
                                    <th>Discrepancies Found</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pastCounts)): ?>
                                    <tr><td colspan="7" style="text-align: center; padding: 32px; color: #64748b;">No stock count audits performed yet. Click "+ Start Physical Count" to begin.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pastCounts as $pc): ?>
                                        <tr>
                                            <td><strong style="font-family: monospace; color: var(--saas-primary);"><?= e($pc['count_number']) ?></strong></td>
                                            <td><strong><?= e($pc['auditor_name']) ?></strong></td>
                                            <td style="font-size: 12.5px; color: var(--saas-slate-600);"><?= date('M d, Y • h:i A', strtotime($pc['created_at'])) ?></td>
                                            <td style="font-size: 12.5px; color: var(--saas-slate-600);"><?= $pc['completed_at'] ? date('M d, Y • h:i A', strtotime($pc['completed_at'])) : '—' ?></td>
                                            <td><span class="badge badge-info"><?= (int)$pc['total_items'] ?> items</span></td>
                                            <td>
                                                <span class="badge <?= (int)$pc['total_discrepancies'] > 0 ? 'badge-warning' : 'badge-success' ?>">
                                                    <?= (int)$pc['total_discrepancies'] ?> variances
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= $pc['status'] === 'completed' ? 'badge-success' : 'badge-warning' ?>">
                                                    <?= ucfirst($pc['status']) ?>
                                                </span>
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

    <!-- START STOCK COUNT MODAL -->
    <div class="modal-overlay" id="startCountModal">
        <div class="modal-box" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">Start Physical Stock Count</h3>
                <button type="button" class="modal-close-btn" id="closeCountModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('stock-count.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="start_count">
                <div class="modal-body">
                    <p style="font-size: 13px; color: var(--saas-slate-600); margin-bottom: 16px; line-height: 1.5;">
                        This will snapshot all current live inventory stock quantities so you can verify physical shelf quantities without stopping POS sales.
                    </p>
                    <div class="form-group">
                        <label class="form-label">Audit Memo / Notes</label>
                        <input type="text" name="notes" placeholder="e.g. End of Month Store Audit" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelCountModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0; padding: 9px 18px;">Begin Counting</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        const cModal = document.getElementById('startCountModal');
        const startCBtn = document.getElementById('startCountBtn');
        if (startCBtn) startCBtn.addEventListener('click', () => cModal.classList.add('open'));
        const closeCBtn = document.getElementById('closeCountModal');
        if (closeCBtn) closeCBtn.addEventListener('click', () => cModal.classList.remove('open'));
        const cancelCBtn = document.getElementById('cancelCountModal');
        if (cancelCBtn) cancelCBtn.addEventListener('click', () => cModal.classList.remove('open'));

        // Live Item Count Updates via AJAX
        document.querySelectorAll('.count-item-input').forEach(inp => {
            inp.addEventListener('change', function() {
                const itemId = this.getAttribute('data-id');
                const val = parseInt(this.value, 10) || 0;
                const row = this.closest('tr');

                const fd = new FormData();
                fd.append('csrf_token', '<?= csrf_token() ?>');
                fd.append('action', 'update_count_item');
                fd.append('item_id', itemId);
                fd.append('counted_qty', val);

                fetch('<?= asset('stock-count.php') ?>', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const badge = row.querySelector('.variance-badge');
                        const diff = data.difference;
                        badge.textContent = (diff > 0 ? '+' : '') + diff + ' units';
                        badge.className = 'variance-badge badge ' + (diff === 0 ? 'badge-secondary' : (diff > 0 ? 'badge-success' : 'badge-danger'));
                    }
                });
            });
        });
    </script>
</body>
</html>

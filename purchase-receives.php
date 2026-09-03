<?php
declare(strict_types=1);

/**
 * OminiFlow POS - Purchase Receives (Goods Receiving) Screen (Zoho POS Parity)
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/products_db.php';
require_once __DIR__ . '/includes/purchases_db.php';

require_auth();

$pageTitle = 'All Purchase Receives';

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

// Handle Goods Receiving
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/purchase-receives.php');
    }

    if ($action === 'receive_items') {
        $poId = (int) ($_POST['po_id'] ?? 0);
        $receiveDate = (string) ($_POST['receive_date'] ?? date('Y-m-d'));
        $locationName = (string) ($_POST['location_name'] ?? 'Head Office');
        $notes = (string) ($_POST['notes'] ?? '');
        $receivingJson = (string) ($_POST['receiving_json'] ?? '[]');
        $receivingList = json_decode($receivingJson, true) ?: [];

        $res = create_purchase_receive_log($poId, $receivingList, $receiveDate, $locationName, $notes, $userId);

        if ($res['success']) {
            set_flash('success', "Goods received successfully ({$res['total_received']} units)! Receive #{$res['receive_number']} logged and inventory stock updated.");
        } else {
            set_flash('error', $res['error'] ?? 'Could not log goods receiving.');
        }
        redirect(APP_URL . '/purchase-receives.php');
    }
}

$search = trim($_GET['search'] ?? '');
$receives = get_purchase_receives($search);
$purchaseOrders = get_purchase_orders('', '', 100);
$openPOs = array_filter($purchaseOrders, fn($po) => in_array($po['status'], ['ordered', 'partially_received'], true));
$locations = get_purchase_locations();

// Selected PO if passed in URL
$preSelectedPoId = (int)($_GET['po_id'] ?? 0);
$preSelectedPO = $preSelectedPoId > 0 ? get_purchase_order_by_id($preSelectedPoId) : null;

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
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.open, .modal-overlay.show {
            display: flex !important;
        }
        .modal-box {
            background: #ffffff;
            border-radius: 12px;
            width: 100%;
            max-width: 680px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
        }
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-title {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .modal-close-btn {
            background: none;
            border: none;
            font-size: 24px;
            line-height: 1;
            color: #64748b;
            cursor: pointer;
        }
        .modal-body {
            padding: 20px;
        }
        .modal-footer {
            padding: 14px 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: #f8fafc;
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
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
                        <h1 class="page-title">All Purchase Receives</h1>
                        <p class="page-subtitle">Track and log vendor shipments received at your warehouses and counters.</p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="header-btn" id="openReceiveModalBtn" style="padding: 10px 20px; display: inline-flex; align-items: center; gap: 8px;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                            <span>+ Receive Items</span>
                        </button>
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

                <?php if (empty($receives) && $search === ''): ?>
                    <!-- EMPTY STATE MATCHING SCREENSHOT 3 -->
                    <div class="section-card" style="padding: 60px 30px; text-align: center; margin-bottom: 24px; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <h2 style="font-size: 22px; font-weight: 700; color: #1e293b; margin-bottom: 8px;">Record Received Purchases Accurately</h2>
                        <p style="color: #64748b; font-size: 14.5px; margin-bottom: 24px;">Log items received from your vendors.</p>
                        <button type="button" class="header-btn" onclick="document.getElementById('receiveItemsModal').classList.add('open')" style="padding: 12px 28px; font-size: 14px; font-weight: 700; background: #3b82f6; border-color: #3b82f6;">
                            RECEIVE ITEMS
                        </button>
                    </div>
                <?php else: ?>
                    <!-- Filter Toolbar -->
                    <div class="filter-card" style="padding: 16px 20px; margin-bottom: 24px;">
                        <form method="GET" action="<?= asset('purchase-receives.php') ?>" style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 220px;">
                                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by Receive #, PO #, Vendor..." class="form-control">
                            </div>
                            <button type="submit" class="header-btn" style="padding: 8px 18px;">Search</button>
                            <?php if ($search !== ''): ?>
                                <a href="<?= asset('purchase-receives.php') ?>" class="btn-secondary" style="padding: 8px 14px;">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <h2 class="section-heading">All Received Shipments</h2>
                                <p class="section-subheading">Verified goods received and stock increments</p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Location</th>
                                        <th>Receive #</th>
                                        <th>PO #</th>
                                        <th>Vendor Name</th>
                                        <th>Items Received</th>
                                        <th>Received By</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($receives)): ?>
                                        <tr><td colspan="8" style="text-align: center; padding: 32px; color: #64748b;">No matching receives found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($receives as $rcv): ?>
                                            <tr>
                                                <td style="font-size: 13px; color: var(--saas-slate-600);"><?= date('d M Y', strtotime($rcv['receive_date'])) ?></td>
                                                <td><span class="badge badge-secondary"><?= e($rcv['location_name'] ?? 'Head Office') ?></span></td>
                                                <td><strong style="font-family: monospace; color: var(--saas-primary); font-size: 13.5px;"><?= e($rcv['receive_number']) ?></strong></td>
                                                <td><span style="font-family: monospace; color: #334155;"><?= e($rcv['po_number']) ?></span></td>
                                                <td><strong><?= e($rcv['vendor_name']) ?></strong></td>
                                                <td>
                                                    <span class="badge badge-success" style="background:#dcfce7; color:#15803d; font-weight:700;">
                                                        <?= (int)$rcv['total_received_qty'] ?> Units (<?= (int)$rcv['items_count'] ?> items)
                                                    </span>
                                                </td>
                                                <td style="font-size: 12.5px; color: #64748b;"><?= e($rcv['receiver_name'] ?: 'Admin') ?></td>
                                                <td style="font-size: 12.5px; color: #64748b;"><?= e($rcv['notes'] ?: '—') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- RECEIVE ITEMS MODAL -->
    <div class="modal-overlay" id="receiveItemsModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title">Record Received Items</h3>
                <button type="button" class="modal-close-btn" id="closeReceiveModal">&times;</button>
            </div>
            <form id="receiveItemsForm" method="POST" action="<?= asset('purchase-receives.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="receive_items">
                <input type="hidden" name="receiving_json" id="receivingJsonInput" value="[]">

                <div class="modal-body">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                        <div class="form-group">
                            <label class="form-label">Select Purchase Order <span style="color:#ef4444;">*</span></label>
                            <select name="po_id" id="poSelectDropdown" required class="form-control">
                                <option value="">-- Choose Purchase Order --</option>
                                <?php foreach ($openPOs as $opo): ?>
                                    <option value="<?= $opo['id'] ?>" <?= $preSelectedPoId === (int)$opo['id'] ? 'selected' : '' ?>>
                                        <?= e($opo['po_number']) ?> — <?= e($opo['vendor_name']) ?> (Ordered: <?= (int)$opo['total_ordered_qty'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Receive Date <span style="color:#ef4444;">*</span></label>
                            <input type="date" name="receive_date" value="<?= date('Y-m-d') ?>" required class="form-control">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                        <div class="form-group">
                            <label class="form-label">Receiving Location</label>
                            <select name="location_name" class="form-control">
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= e($loc) ?>"><?= e($loc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notes / Memo</label>
                            <input type="text" name="notes" placeholder="e.g. Received via BlueDart courier" class="form-control">
                        </div>
                    </div>

                    <div style="margin-top: 14px;">
                        <label class="form-label" style="font-weight: 700; margin-bottom: 8px; display: block;">Items in Purchase Order:</label>
                        <div id="poItemsLoading" style="display: none; padding: 16px; text-align: center; color: #64748b;">Loading order items...</div>
                        <div id="poItemsContainer">
                            <div style="padding: 16px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; text-align: center; color: #64748b; font-size: 13px;">
                                Please select a Purchase Order above to view and receive items.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelReceiveModal">Cancel</button>
                    <button type="submit" class="header-btn" id="submitReceiveBtn">Save Goods Receive</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const openPOsData = <?= json_encode(array_values(array_map(function($po) {
            $full = get_purchase_order_by_id((int)$po['id']);
            return $full ?: $po;
        }, $openPOs))) ?>;

        const receiveModal = document.getElementById('receiveItemsModal');
        const openReceiveBtn = document.getElementById('openReceiveModalBtn');
        const closeReceiveBtn = document.getElementById('closeReceiveModal');
        const cancelReceiveBtn = document.getElementById('cancelReceiveModal');
        const poSelect = document.getElementById('poSelectDropdown');
        const poItemsContainer = document.getElementById('poItemsContainer');

        if (openReceiveBtn) openReceiveBtn.addEventListener('click', () => receiveModal.classList.add('open'));
        if (closeReceiveBtn) closeReceiveBtn.addEventListener('click', () => receiveModal.classList.remove('open'));
        if (cancelReceiveBtn) cancelReceiveBtn.addEventListener('click', () => receiveModal.classList.remove('open'));

        poSelect.addEventListener('change', function() {
            renderPOItems(parseInt(this.value, 10));
        });

        <?php if ($preSelectedPoId > 0): ?>
            receiveModal.classList.add('open');
            renderPOItems(<?= $preSelectedPoId ?>);
        <?php endif; ?>

        function renderPOItems(poId) {
            if (!poId) {
                poItemsContainer.innerHTML = '<div style="padding: 16px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; text-align: center; color: #64748b; font-size: 13px;">Please select a Purchase Order above to view and receive items.</div>';
                return;
            }

            const po = openPOsData.find(p => parseInt(p.id, 10) === poId);
            if (!po || !po.items || po.items.length === 0) {
                poItemsContainer.innerHTML = '<div style="padding: 16px; color: #ef4444;">No items found in this Purchase Order.</div>';
                return;
            }

            let html = '<div style="display: flex; flex-direction: column; gap: 10px;">';
            po.items.forEach(it => {
                const ordered = parseInt(it.quantity_ordered, 10) || 0;
                const recvd = parseInt(it.quantity_received, 10) || 0;
                const remaining = Math.max(0, ordered - recvd);

                html += `
                    <div class="receive-item-row" data-poi-id="${it.id}" style="padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                        <div style="flex: 1;">
                            <div style="font-weight: 700; font-size: 13.5px; color: #0f172a;">${escapeHtml(it.product_name)}</div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                Ordered: <strong>${ordered}</strong> | Already Received: <strong>${recvd}</strong> | Remaining: <strong style="color:#2563eb;">${remaining}</strong>
                            </div>
                        </div>
                        <div style="width: 140px;">
                            <label style="font-size: 11px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">Qty to Receive</label>
                            <input type="number" min="0" max="${remaining}" value="${remaining}" class="form-control receive-qty-input" style="padding: 6px 10px; font-size: 13px;">
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            poItemsContainer.innerHTML = html;
        }

        function escapeHtml(str) {
            return (str + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        document.getElementById('receiveItemsForm').addEventListener('submit', function(e) {
            const items = [];
            document.querySelectorAll('.receive-item-row').forEach(row => {
                const poiId = parseInt(row.getAttribute('data-poi-id'), 10);
                const qty = parseInt(row.querySelector('.receive-qty-input').value, 10) || 0;
                if (qty > 0) {
                    items.push({
                        po_item_id: poiId,
                        quantity_to_receive: qty
                    });
                }
            });

            if (items.length === 0) {
                alert('Please enter at least 1 unit to receive.');
                e.preventDefault();
                return;
            }

            document.getElementById('receivingJsonInput').value = JSON.stringify(items);
        });
    </script>
</body>
</html>

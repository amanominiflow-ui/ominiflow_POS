<?php declare(strict_types=1);

/**
 * OminiFlow POS - Purchase Bills Screen (Zoho POS / Zoho Books Parity)
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/products_db.php';
require_once __DIR__ . '/includes/purchases_db.php';

require_auth();

$pageTitle = 'All Bills';

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

// Handle Bill creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/bills.php');
    }

    if ($action === 'create_bill') {
        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
        $billDate = (string) ($_POST['bill_date'] ?? date('Y-m-d'));
        $dueDate = (string) ($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
        $refNo = (string) ($_POST['reference_number'] ?? '');
        $locationName = (string) ($_POST['location_name'] ?? 'Head Office');
        $notes = (string) ($_POST['notes'] ?? '');
        $poId = !empty($_POST['purchase_order_id']) ? (int)$_POST['purchase_order_id'] : null;

        $itemsJson = (string) ($_POST['items_json'] ?? '[]');
        $items = json_decode($itemsJson, true) ?: [];

        $billData = [
            'vendor_id' => $vendorId,
            'purchase_order_id' => $poId,
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'reference_number' => $refNo,
            'location_name' => $locationName,
            'notes' => $notes,
            'items' => $items,
        ];

        $res = create_purchase_bill($billData, $userId);

        if ($res['success']) {
            set_flash('success', "Bill #{$res['bill_number']} created successfully! Total: ₹" . number_format($res['total_amount'], 2));
        } else {
            set_flash('error', $res['error'] ?? 'Could not create bill.');
        }
        redirect(APP_URL . '/bills.php');
    }
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$bills = get_purchase_bills($search, $status);
$vendors = get_vendors();
$products = get_products();
$locations = get_purchase_locations();
$purchaseOrders = get_purchase_orders('', '', 100);

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
            max-width: 720px;
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
                        <h1 class="page-title">All Bills</h1>
                        <p class="page-subtitle">Track supplier bills, outstanding payables, and record payments.</p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="header-btn" id="openCreateBillBtn" style="padding: 10px 20px; display: inline-flex; align-items: center; gap: 8px;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                            <span>+ New Bill</span>
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

                <!-- Filter Toolbar -->
                <div class="filter-card" style="padding: 16px 20px; margin-bottom: 24px;">
                    <form method="GET" action="<?= asset('bills.php') ?>" style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 220px;">
                            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by Bill #, Ref #, Vendor, PO #..." class="form-control">
                        </div>
                        <div style="width: 180px;">
                            <select name="status" class="form-control" onchange="this.form.submit()">
                                <option value="">All Statuses</option>
                                <option value="unpaid" <?= $status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                <option value="partially_paid" <?= $status === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                                <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                            </select>
                        </div>
                        <button type="submit" class="header-btn" style="padding: 8px 18px;">Filter</button>
                        <?php if ($search !== '' || $status !== ''): ?>
                            <a href="<?= asset('bills.php') ?>" class="btn-secondary" style="padding: 8px 14px;">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h2 class="section-heading">Vendor Bills</h2>
                            <p class="section-subheading">Invoices received from vendors and accounts payable</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>DATE</th>
                                    <th>LOCATION</th>
                                    <th>BILL#</th>
                                    <th>REFERENCE NUMBER</th>
                                    <th>VENDOR NAME</th>
                                    <th>STATUS</th>
                                    <th>DUE DATE</th>
                                    <th>AMOUNT</th>
                                    <th>BALANCE DUE</th>
                                    <th style="text-align: right;">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bills)): ?>
                                    <tr><td colspan="10" style="text-align: center; padding: 32px; color: #64748b;">No bills found. Click "+ New Bill" to record a vendor invoice.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($bills as $b): ?>
                                        <tr>
                                            <td style="font-size: 13px; color: var(--saas-slate-600);"><?= date('d M Y', strtotime($b['bill_date'])) ?></td>
                                            <td><span class="badge badge-secondary"><?= e($b['location_name'] ?? 'Head Office') ?></span></td>
                                            <td>
                                                <strong style="font-family: monospace; color: var(--saas-primary); font-size: 13.5px;"><?= e($b['bill_number']) ?></strong>
                                            </td>
                                            <td style="font-size: 12.5px; color: #64748b;"><?= e($b['reference_number'] ?: '—') ?></td>
                                            <td><strong><?= e($b['vendor_name']) ?></strong></td>
                                            <td>
                                                <?php if ($b['status'] === 'paid'): ?>
                                                    <span class="badge badge-success" style="background:#dcfce7; color:#15803d; font-weight:700;">PAID</span>
                                                <?php elseif ($b['status'] === 'partially_paid'): ?>
                                                    <span class="badge badge-warning" style="background:#fef3c7; color:#92400e; font-weight:700;">PARTIALLY PAID</span>
                                                <?php elseif ($b['status'] === 'overdue'): ?>
                                                    <span class="badge badge-danger" style="background:#fee2e2; color:#b91c1c; font-weight:700;">OVERDUE</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary" style="background:#f1f5f9; color:#475569; font-weight:700;">UNPAID</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size: 12.5px; color: var(--saas-slate-600);"><?= $b['due_date'] ? date('d M Y', strtotime($b['due_date'])) : '—' ?></td>
                                            <td><strong style="font-size: 13.5px; color: var(--saas-navy-950);">₹<?= number_format((float)$b['total_amount'], 2) ?></strong></td>
                                            <td>
                                                <strong style="font-size: 13.5px; color: <?= (float)$b['balance_due'] > 0 ? '#b91c1c' : '#15803d' ?>;">
                                                    ₹<?= number_format((float)$b['balance_due'], 2) ?>
                                                </strong>
                                            </td>
                                            <td style="text-align: right;">
                                                <div style="display: inline-flex; gap: 6px; align-items: center;">
                                                    <?php if ((float)$b['balance_due'] > 0): ?>
                                                        <a href="<?= asset('payments-made.php?bill_id=' . $b['id']) ?>" class="header-btn" style="padding: 4px 10px; font-size: 11.5px; font-weight: 700; text-decoration: none;">
                                                            💳 Pay
                                                        </a>
                                                    <?php else: ?>
                                                        <span style="color:#047857; font-weight:700; font-size:12px;">✓ Settled</span>
                                                    <?php endif; ?>
                                                </div>
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

    <!-- CREATE BILL MODAL -->
    <div class="modal-overlay" id="createBillModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title">Create Vendor Bill</h3>
                <button type="button" class="modal-close-btn" id="closeCreateBillModal">&times;</button>
            </div>
            <form id="createBillForm" method="POST" action="<?= asset('bills.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_bill">
                <input type="hidden" name="items_json" id="billItemsJson" value="[]">

                <div class="modal-body">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                        <div class="form-group">
                            <label class="form-label">Select Vendor <span style="color:#ef4444;">*</span></label>
                            <select name="vendor_id" required class="form-control">
                                <option value="">-- Choose Vendor --</option>
                                <?php foreach ($vendors as $ven): ?>
                                    <option value="<?= $ven['id'] ?>"><?= e($ven['name']) ?> (<?= e($ven['company_name'] ?: 'Wholesale') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Link Purchase Order (Optional)</label>
                            <select name="purchase_order_id" class="form-control">
                                <option value="">-- Standalone Bill --</option>
                                <?php foreach ($purchaseOrders as $po): ?>
                                    <option value="<?= $po['id'] ?>"><?= e($po['po_number']) ?> (<?= e($po['vendor_name']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                        <div class="form-group">
                            <label class="form-label">Bill Date <span style="color:#ef4444;">*</span></label>
                            <input type="date" name="bill_date" value="<?= date('Y-m-d') ?>" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Due Date <span style="color:#ef4444;">*</span></label>
                            <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required class="form-control">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                        <div class="form-group">
                            <label class="form-label">Vendor Reference / Invoice #</label>
                            <input type="text" name="reference_number" placeholder="e.g. INV-VENDOR-1049" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <select name="location_name" class="form-control">
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= e($loc) ?>"><?= e($loc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <label class="form-label" style="font-weight: 700; margin: 0;">Bill Items & Costs</label>
                            <button type="button" id="addBillLineBtn" class="btn-secondary" style="padding: 4px 10px; font-size: 12px; font-weight: 700;">+ Add Item</button>
                        </div>
                        <div id="billLinesContainer"></div>
                    </div>

                    <div class="form-group" style="margin-top: 16px;">
                        <label class="form-label">Notes / Remarks</label>
                        <textarea name="notes" rows="2" class="form-control" placeholder="Optional notes about this vendor bill..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelCreateBillModal">Cancel</button>
                    <button type="submit" class="header-btn">Save Vendor Bill</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const productsCatalog = <?= json_encode($products) ?>;
        const billModal = document.getElementById('createBillModal');
        const openBillBtn = document.getElementById('openCreateBillBtn');
        const closeBillBtn = document.getElementById('closeCreateBillModal');
        const cancelBillBtn = document.getElementById('cancelCreateBillModal');

        if (openBillBtn) openBillBtn.addEventListener('click', () => {
            billModal.classList.add('open');
            if (document.querySelectorAll('.bill-line-row').length === 0) addBillLine();
        });
        if (closeBillBtn) closeBillBtn.addEventListener('click', () => billModal.classList.remove('open'));
        if (cancelBillBtn) cancelBillBtn.addEventListener('click', () => billModal.classList.remove('open'));

        if (window.location.search.includes('action=new')) {
            billModal.classList.add('open');
            if (document.querySelectorAll('.bill-line-row').length === 0) addBillLine();
        }

        const billLinesContainer = document.getElementById('billLinesContainer');
        const addBillLineBtn = document.getElementById('addBillLineBtn');
        if (addBillLineBtn) addBillLineBtn.addEventListener('click', addBillLine);

        function addBillLine() {
            const div = document.createElement('div');
            div.className = 'bill-line-row';
            div.style.display = 'grid';
            div.style.gridTemplateColumns = '2fr 1fr 1fr auto';
            div.style.gap = '8px';
            div.style.marginBottom = '8px';
            div.style.alignItems = 'center';

            let optionsHtml = '<option value="0">-- Custom / Other Item --</option>';
            productsCatalog.forEach(p => {
                optionsHtml += `<option value="${p.id}" data-cost="${p.cost_price}" data-tax="${p.tax_percent}">${escapeHtml(p.name)} (₹${p.cost_price})</option>`;
            });

            div.innerHTML = `
                <select class="form-control bill-prod-select">${optionsHtml}</select>
                <input type="number" min="1" value="1" placeholder="Qty" class="form-control bill-qty-input">
                <input type="number" step="0.01" value="${productsCatalog[0] ? productsCatalog[0].cost_price : '100.00'}" placeholder="Unit Cost" class="form-control bill-cost-input">
                <button type="button" style="background:none; border:none; color:#ef4444; font-size:18px; cursor:pointer;" onclick="this.parentElement.remove();">&times;</button>
            `;

            div.querySelector('.bill-prod-select').addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                const cost = opt.getAttribute('data-cost');
                if (cost) div.querySelector('.bill-cost-input').value = cost;
            });

            billLinesContainer.appendChild(div);
        }

        function escapeHtml(str) {
            return (str + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        document.getElementById('createBillForm').addEventListener('submit', function(e) {
            const items = [];
            document.querySelectorAll('.bill-line-row').forEach(row => {
                const sel = row.querySelector('.bill-prod-select');
                const opt = sel.options[sel.selectedIndex];
                const prodId = parseInt(sel.value, 10);
                items.push({
                    product_id: prodId > 0 ? prodId : null,
                    product_name: opt.text.split(' (')[0],
                    product_sku: 'SKU-' + Math.floor(1000 + Math.random() * 9000),
                    quantity: parseInt(row.querySelector('.bill-qty-input').value, 10) || 1,
                    unit_cost: parseFloat(row.querySelector('.bill-cost-input').value) || 0,
                    tax_percent: parseFloat(opt.getAttribute('data-tax')) || 0,
                });
            });

            if (items.length === 0) {
                alert('Please add at least one item to the bill.');
                e.preventDefault();
                return;
            }

            document.getElementById('billItemsJson').value = JSON.stringify(items);
        });
    </script>
</body>
</html>

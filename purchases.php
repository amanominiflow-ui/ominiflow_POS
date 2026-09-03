<?php
/**
 * OminiFlow POS - Purchase Orders & Goods Receiving Screen (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/products_db.php';
require_once __DIR__ . '/includes/purchases_db.php';

require_auth();

$pageTitle = 'Purchase Orders & Stock Receiving';

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

// Handle PO creation & Goods Receiving
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid session token.']);
            exit;
        }
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/purchases.php');
    }

    if ($action === 'create_po') {
        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
        $expectedDate = (string) ($_POST['expected_delivery_date'] ?? '');
        $notes = (string) ($_POST['notes'] ?? '');
        $itemsJson = (string) ($_POST['items_json'] ?? '[]');
        $items = json_decode($itemsJson, true) ?: [];

        $res = create_purchase_order($vendorId, $items, $expectedDate, $notes, $userId);

        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }

        if ($res['success']) {
            set_flash('success', "Purchase Order #{$res['po_number']} created successfully! Total: ₹" . number_format($res['total_amount'], 2));
        } else {
            set_flash('error', $res['error'] ?? 'Could not create purchase order.');
        }
        redirect(APP_URL . '/purchases.php');
    } elseif ($action === 'receive_goods') {
        $poId = (int) ($_POST['po_id'] ?? 0);
        $receivingJson = (string) ($_POST['receiving_json'] ?? '[]');
        $receivingList = json_decode($receivingJson, true) ?: [];

        $res = receive_purchase_order_items($poId, $receivingList, $userId);

        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }

        if ($res['success']) {
            set_flash('success', "Goods received successfully ({$res['received_units']} units)! Inventory stock has been automatically increased.");
        } else {
            set_flash('error', $res['error'] ?? 'Could not receive goods.');
        }
        redirect(APP_URL . '/purchases.php');
    } elseif ($action === 'convert_to_bill') {
        $poId = (int) ($_POST['po_id'] ?? 0);
        $res = convert_po_to_bill($poId, date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), '', $userId);
        if ($res['success']) {
            set_flash('success', "Purchase Order converted to Bill #{$res['bill_number']} successfully! Total: ₹" . number_format($res['total_amount'], 2));
            redirect(APP_URL . '/bills.php');
        } else {
            set_flash('error', $res['error'] ?? 'Could not convert to bill.');
            redirect(APP_URL . '/purchases.php');
        }
    }
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$purchaseOrders = get_purchase_orders($search, $status);
$vendors = get_vendors();
$products = get_products();

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
                        <h1 class="page-title">All Purchase Orders</h1>
                        <p class="page-subtitle">Create procurement orders for vendors, receive goods, and convert to vendor bills.</p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <a href="<?= asset('purchase-receives.php') ?>" class="btn-secondary" style="padding: 10px 16px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                            <span>📦 In Transit Receives</span>
                        </a>
                        <button type="button" class="header-btn" id="openCreatePOBtn" style="padding: 10px 20px; display: inline-flex; align-items: center; gap: 8px;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                            <span>+ New Purchase Order</span>
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

                <?php if (empty($purchaseOrders) && $search === '' && $status === ''): ?>
                    <!-- LIFECYCLE BANNER MATCHING SCREENSHOT 2 -->
                    <div class="section-card" style="padding: 40px 30px; text-align: center; margin-bottom: 24px; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <h2 style="font-size: 20px; font-weight: 700; color: #1e293b; margin-bottom: 8px;">Start Managing Your Purchase Activities!</h2>
                        <p style="color: #64748b; font-size: 14px; max-width: 500px; margin: 0 auto 24px;">Create, customize, and send professional Purchase Orders to your vendors.</p>
                        <button type="button" class="header-btn" onclick="document.getElementById('createPOModal').classList.add('show')" style="padding: 12px 28px; font-size: 14px; font-weight: 700; background: #3b82f6; border-color: #3b82f6;">
                            CREATE NEW PURCHASE ORDER
                        </button>

                        <div style="margin-top: 40px; padding-top: 30px; border-top: 1px solid #f1f5f9;">
                            <h4 style="font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 20px;">Life cycle of a Purchase Order</h4>
                            <div style="display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap;">
                                <div style="border: 1px solid #93c5fd; background: #eff6ff; color: #1d4ed8; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                                    <span>📄 RAISE PURCHASE ORDER</span>
                                </div>
                                <span style="font-size: 11px; color: #94a3b8; font-weight: 600;">CONVERT TO OPEN &rarr;</span>
                                <div style="border: 1px solid #86efac; background: #f0fdf4; color: #15803d; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                                    <span>📦 RECEIVE GOODS</span>
                                </div>
                                <span style="font-size: 11px; color: #94a3b8; font-weight: 600;">CONVERT TO BILL &rarr;</span>
                                <div style="border: 1px solid #c4b5fd; background: #f5f3ff; color: #6d28d9; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                                    <span>🧾 CONVERT TO BILL</span>
                                </div>
                                <span style="font-size: 11px; color: #94a3b8; font-weight: 600;">RECORD PAYMENT &rarr;</span>
                                <div style="border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                                    <span>💳 RECORD PAYMENT</span>
                                </div>
                            </div>

                            <div style="text-align: left; max-width: 580px; margin: 30px auto 0; font-size: 13.5px; color: #475569; line-height: 1.8;">
                                <div style="font-weight: 700; margin-bottom: 8px; color: #1e293b;">In the Purchase Orders module, you can:</div>
                                <div style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 6px;">
                                    <span style="color: #3b82f6;">✓</span>
                                    <span>Create and send a purchase order to your vendors when you are in need of a product.</span>
                                </div>
                                <div style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 6px;">
                                    <span style="color: #3b82f6;">✓</span>
                                    <span>Convert the purchase order into a bill after you receive an invoice for your purchase.</span>
                                </div>
                                <div style="display: flex; align-items: flex-start; gap: 8px;">
                                    <span style="color: #3b82f6;">✓</span>
                                    <span>Receive items accurately with automatic inventory stock replenishment.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Filter & Search Toolbar -->
                <div class="filter-card" style="padding: 16px 20px; margin-bottom: 24px;">
                    <form method="GET" action="<?= asset('purchases.php') ?>" style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 220px;">
                            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by PO #, Vendor Name..." class="form-control">
                        </div>
                        <div style="width: 180px;">
                            <select name="status" class="form-control" onchange="this.form.submit()">
                                <option value="">All Statuses</option>
                                <option value="ordered" <?= $status === 'ordered' ? 'selected' : '' ?>>Ordered</option>
                                <option value="partially_received" <?= $status === 'partially_received' ? 'selected' : '' ?>>Partially Received</option>
                                <option value="received" <?= $status === 'received' ? 'selected' : '' ?>>Fully Received</option>
                                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        <button type="submit" class="header-btn" style="padding: 8px 18px;">Filter</button>
                        <?php if ($search !== '' || $status !== ''): ?>
                            <a href="<?= asset('purchases.php') ?>" class="btn-secondary" style="padding: 8px 14px;">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h2 class="section-heading">Purchase Orders</h2>
                            <p class="section-subheading">Tracking supplier deliveries, receiving progress, and vendor billing</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>PO #</th>
                                    <th>Vendor</th>
                                    <th>Order Date</th>
                                    <th>Expected Delivery</th>
                                    <th>Items Ordered</th>
                                    <th>Receiving Progress</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($purchaseOrders)): ?>
                                    <tr><td colspan="9" style="text-align: center; padding: 32px; color: #64748b;">No purchase orders found. Click "+ New Purchase Order" to create one.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($purchaseOrders as $po): ?>
                                        <tr>
                                            <td><strong style="font-family: monospace; color: var(--saas-primary); font-size: 13.5px;"><?= e($po['po_number']) ?></strong></td>
                                            <td><strong><?= e($po['vendor_name']) ?></strong></td>
                                            <td style="font-size: 12.5px; color: var(--saas-slate-600);"><?= date('M d, Y', strtotime($po['created_at'])) ?></td>
                                            <td style="font-size: 12.5px; color: var(--saas-slate-600);"><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></td>
                                            <td><span class="badge badge-info"><?= (int)$po['items_count'] ?> Products</span></td>
                                            <td>
                                                <div style="font-weight: 700; font-size: 12.5px; color: var(--saas-navy-950);">
                                                    <?= (int)$po['total_received_qty'] ?> / <?= (int)$po['total_ordered_qty'] ?> units
                                                </div>
                                            </td>
                                            <td><strong style="font-size: 13.5px; color: var(--saas-navy-950);">₹<?= number_format((float)$po['total_amount'], 2) ?></strong></td>
                                            <td>
                                                <?php if ($po['status'] === 'received'): ?>
                                                    <span class="badge badge-success">Received</span>
                                                <?php elseif ($po['status'] === 'partially_received'): ?>
                                                    <span class="badge badge-warning" style="background:#fef3c7; color:#92400e;">Partially Received</span>
                                                <?php else: ?>
                                                    <span class="badge badge-info"><?= ucfirst($po['status']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <div style="display: inline-flex; gap: 6px; align-items: center;">
                                                    <?php if ($po['status'] !== 'received'): ?>
                                                        <a href="<?= asset('purchase-receives.php?po_id=' . $po['id']) ?>" class="btn-secondary" style="padding: 4px 8px; font-size: 11.5px; font-weight: 700; text-decoration: none;" title="Receive items into inventory">
                                                            📦 Receive
                                                        </a>
                                                    <?php endif; ?>
                                                    <form method="POST" action="<?= asset('purchases.php') ?>" style="display: inline;" onsubmit="return confirm('Convert PO #<?= e($po['po_number']) ?> to a Vendor Bill?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="convert_to_bill">
                                                        <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                                                        <button type="submit" class="btn-secondary" style="padding: 4px 8px; font-size: 11.5px; font-weight: 700; color: #2563eb;" title="Convert to Vendor Bill">
                                                            🧾 Bill
                                                        </button>
                                                    </form>
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

    <!-- CREATE PURCHASE ORDER MODAL -->
    <div class="modal-overlay" id="createPOModal">
        <div class="modal-box" style="max-width: 680px;">
            <div class="modal-header">
                <h3 class="modal-title">Create Purchase Order</h3>
                <button type="button" class="modal-close-btn" id="closeCreatePOModal">&times;</button>
            </div>
            <form id="createPOForm" method="POST" action="<?= asset('purchases.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_po">
                <input type="hidden" name="items_json" id="poItemsJson" value="[]">

                <div class="modal-body">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                        <div class="form-group">
                            <label class="form-label">Select Vendor <span style="color:#ef4444;">*</span></label>
                            <select name="vendor_id" required class="form-control">
                                <?php foreach ($vendors as $ven): ?>
                                    <option value="<?= $ven['id'] ?>"><?= e($ven['name']) ?> (<?= e($ven['company_name'] ?: 'Wholesale') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Expected Delivery Date</label>
                            <input type="date" name="expected_delivery_date" class="form-control">
                        </div>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <label class="form-label" style="margin: 0;">Order Products & Quantities <span style="color:#ef4444;">*</span></label>
                            <button type="button" class="btn-secondary" id="addPOLineBtn" style="padding: 4px 12px; font-size: 12px;">+ Add Product</button>
                        </div>

                        <div id="poLinesContainer" style="max-height: 240px; overflow-y: auto; border: 1px solid var(--saas-border); border-radius: var(--saas-radius-md); padding: 10px; background: #fafafa;">
                            <!-- Lines inserted via JS -->
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Purchase Order Notes</label>
                        <input type="text" name="notes" placeholder="e.g. Deliver to main warehouse loading dock" class="form-control">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelCreatePOModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0; padding: 9px 18px;">Create & Place Purchase Order</button>
                </div>
            </form>
        </div>
    </div>

    <!-- GOODS RECEIVING MODAL -->
    <div class="modal-overlay" id="receiveModal">
        <div class="modal-box" style="max-width: 600px;">
            <div class="modal-header">
                <h3 class="modal-title">Receive Goods into Stock</h3>
                <button type="button" class="modal-close-btn" id="closeReceiveModal">&times;</button>
            </div>
            <form id="receiveForm" method="POST" action="<?= asset('purchases.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="receive_goods">
                <input type="hidden" name="po_id" id="receivePOId" value="">
                <input type="hidden" name="receiving_json" id="receivingJson" value="[]">

                <div class="modal-body">
                    <p style="font-size: 13px; color: var(--saas-slate-600); margin-bottom: 12px;">
                        Enter the physical quantities received at the counter/warehouse. Available inventory stock will automatically be increased.
                    </p>
                    <div id="receiveItemsList" style="border: 1px solid var(--saas-border); border-radius: var(--saas-radius-md); padding: 8px;">
                        <!-- Injected via JS -->
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelReceiveModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0; background: #047857;">Confirm Receiving & Update Stock</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        const productsCatalog = <?= json_encode($products) ?>;

        const poModal = document.getElementById('createPOModal');
        const openPOBtn = document.getElementById('openCreatePOBtn');
        if (openPOBtn) openPOBtn.addEventListener('click', () => {
            poModal.classList.add('open');
            if (document.querySelectorAll('.po-line-row').length === 0) addPOLine();
        });
        const closePOBtn = document.getElementById('closeCreatePOModal');
        if (closePOBtn) closePOBtn.addEventListener('click', () => poModal.classList.remove('open'));
        const cancelPOBtn = document.getElementById('cancelCreatePOModal');
        if (cancelPOBtn) cancelPOBtn.addEventListener('click', () => poModal.classList.remove('open'));

        if (window.location.search.includes('action=new')) {
            poModal.classList.add('open');
            if (document.querySelectorAll('.po-line-row').length === 0) addPOLine();
        }

        const poLinesContainer = document.getElementById('poLinesContainer');
        const addPOLineBtn = document.getElementById('addPOLineBtn');
        if (addPOLineBtn) addPOLineBtn.addEventListener('click', addPOLine);

        function addPOLine() {
            const div = document.createElement('div');
            div.className = 'po-line-row';
            div.style.display = 'grid';
            div.style.gridTemplateColumns = '2fr 1fr 1fr auto';
            div.style.gap = '8px';
            div.style.marginBottom = '8px';
            div.style.alignItems = 'center';

            let optionsHtml = '';
            productsCatalog.forEach(p => {
                optionsHtml += `<option value="${p.id}" data-cost="${p.cost_price}" data-tax="${p.tax_percent}">${escapeHtml(p.name)} (Cost: ₹${p.cost_price})</option>`;
            });

            div.innerHTML = `
                <select class="form-control po-prod-select">${optionsHtml}</select>
                <input type="number" min="1" value="10" placeholder="Qty" class="form-control po-qty-input">
                <input type="number" step="0.01" value="${productsCatalog[0] ? productsCatalog[0].cost_price : '0.00'}" placeholder="Unit Cost" class="form-control po-cost-input">
                <button type="button" style="background:none; border:none; color:#ef4444; font-size:18px; cursor:pointer;" onclick="this.parentElement.remove();">&times;</button>
            `;

            div.querySelector('.po-prod-select').addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                div.querySelector('.po-cost-input').value = opt.getAttribute('data-cost') || '0.00';
            });

            poLinesContainer.appendChild(div);
        }

        function escapeHtml(str) {
            return (str + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        document.getElementById('createPOForm').addEventListener('submit', function(e) {
            const items = [];
            document.querySelectorAll('.po-line-row').forEach(row => {
                const sel = row.querySelector('.po-prod-select');
                const opt = sel.options[sel.selectedIndex];
                items.push({
                    product_id: parseInt(sel.value, 10),
                    quantity: parseInt(row.querySelector('.po-qty-input').value, 10) || 1,
                    unit_cost: parseFloat(row.querySelector('.po-cost-input').value) || 0,
                    tax_percent: parseFloat(opt.getAttribute('data-tax')) || 0,
                });
            });
            document.getElementById('poItemsJson').value = JSON.stringify(items);
        });

        // Quick receive
        document.querySelectorAll('.receive-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const poId = this.getAttribute('data-id');
                const qty = prompt("Enter total units to receive for this PO:", "10");
                if (qty && parseInt(qty, 10) > 0) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '<?= asset('purchases.php') ?>';
                    form.innerHTML = `
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="receive_goods">
                        <input type="hidden" name="po_id" value="${poId}">
                        <input type="hidden" name="receiving_json" value='[{"po_item_id": 1, "quantity_to_receive": ${parseInt(qty, 10)}}]'>
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });
    </script>
</body>
</html>

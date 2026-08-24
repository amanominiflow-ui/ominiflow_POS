<?php
/**
 * OminiFlow POS - Payment Options & Tender Types (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/payment_options_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int)$user['id'] : null;

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid session token. Please reload.']);
        exit;
    }

    if ($action === 'save_tender_type') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $data = [
            'display_name' => $_POST['display_name'] ?? '',
            'processing_type' => $_POST['processing_type'] ?? 'Manual Entry',
            'payment_mode' => $_POST['payment_mode'] ?? 'Cash',
            'deposit_to' => $_POST['deposit_to'] ?? 'Petty Cash',
            'is_customer_required' => !empty($_POST['is_customer_required']) ? 1 : 0,
            'is_express_checkout' => !empty($_POST['is_express_checkout']) ? 1 : 0,
            'status' => $_POST['status'] ?? 'active',
        ];

        $res = save_payment_option($data, $id);
        header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $opt = get_payment_option_by_id($id);
        if ($opt) {
            $newStatus = $opt['status'] === 'active' ? 'inactive' : 'active';
            $res = save_payment_option(array_merge($opt, ['status' => $newStatus]), $id);
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Option not found.']);
        exit;
    }

    if ($action === 'reorder') {
        $orderedIds = json_decode($_POST['ordered_ids'] ?? '[]', true) ?: [];
        $ok = update_payment_options_order($orderedIds);
        header('Content-Type: application/json');
        echo json_encode(['success' => $ok]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $res = delete_payment_option($id);
        header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    }
}

$paymentOptions = get_payment_options();
$pageTitle = 'Payment Options';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Options — <?= APP_NAME ?></title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .pay-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 32px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
        }

        .pay-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.01em;
            margin: 0;
        }

        .pay-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-tender-secondary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 13.5px;
            font-weight: 600;
            color: #334155;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-tender-secondary:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        .btn-tender-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            font-size: 13.5px;
            font-weight: 600;
            color: #ffffff;
            background: #2563eb;
            border: 1px solid #2563eb;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-tender-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .pay-content {
            padding: 24px 32px 60px;
            background: #f8fafc;
            min-height: calc(100vh - 120px);
        }

        .tender-table-card {
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .tender-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .tender-table th {
            padding: 14px 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
        }

        .tender-table td {
            padding: 16px 20px;
            font-size: 13.5px;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .tender-table tbody tr:hover td {
            background: #f8fafc;
        }

        .tender-name-link {
            color: #2563eb;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: color 0.15s;
        }

        .tender-name-link:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            font-size: 13px;
            font-weight: 600;
        }

        .badge-status.active {
            color: #16a34a;
        }

        .badge-status.inactive {
            color: #dc2626;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .tender-modal {
            background: #ffffff;
            border-radius: 12px;
            width: 100%;
            max-width: 580px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            animation: modalFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96) translateY(-10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .modal-close {
            background: transparent;
            border: 0;
            font-size: 24px;
            color: #ef4444;
            cursor: pointer;
            line-height: 1;
            padding: 0;
        }

        .modal-body {
            padding: 24px;
            max-height: calc(85vh - 140px);
            overflow-y: auto;
        }

        .form-row {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 18px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }

        .form-label .req {
            color: #ef4444;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 10px 14px;
            font-size: 14px;
            color: #0f172a;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-sizing: border-box;
            outline: none;
            transition: all 0.15s ease;
        }

        .form-input:focus, .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .form-check-wrap {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        .form-check-wrap input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            cursor: pointer;
            accent-color: #2563eb;
        }

        .check-label-title {
            font-size: 13.5px;
            font-weight: 600;
            color: #1e293b;
        }

        .check-label-desc {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }

        .modal-foot {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 16px 24px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
        }

        .btn-modal-add {
            padding: 9px 24px;
            font-size: 14px;
            font-weight: 600;
            color: #ffffff;
            background: #3b82f6;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-modal-add:hover {
            background: #2563eb;
        }

        .btn-modal-cancel {
            padding: 9px 20px;
            font-size: 14px;
            font-weight: 600;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-modal-cancel:hover {
            background: #e2e8f0;
        }

        /* Order Reorder Item List */
        .reorder-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .reorder-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            cursor: grab;
        }

        .reorder-handle {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: #1e293b;
        }

        .reorder-btns {
            display: flex;
            gap: 4px;
        }

        .btn-reorder-move {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            color: #475569;
        }

        .btn-reorder-move:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <!-- Sidebar Component -->
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="app-main">
            <!-- Header Component -->
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <div class="pay-header">
                <h1 class="pay-title">Payment Options</h1>
                <div class="pay-actions">
                    <button type="button" class="btn-tender-secondary" onclick="openReorderModal()">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        <span>Change Order</span>
                    </button>
                    <button type="button" class="btn-tender-primary" onclick="openAddTenderModal()">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>Add Tender Type</span>
                    </button>
                </div>
            </div>

            <main class="pay-content">
                <div class="tender-table-card">
                    <table class="tender-table">
                        <thead>
                            <tr>
                                <th>Display Name</th>
                                <th>Processing Type</th>
                                <th>Payment Mode</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="tenderTableBody">
                            <?php if (empty($paymentOptions)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">
                                        No payment tender types configured. Click "+ Add Tender Type" to create one.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($paymentOptions as $opt): ?>
                                    <tr data-id="<?= (int)$opt['id'] ?>">
                                        <td>
                                            <a href="javascript:void(0)" class="tender-name-link" onclick="editTenderType(<?= htmlspecialchars(json_encode($opt), ENT_QUOTES, 'UTF-8') ?>)">
                                                <?= e($opt['display_name']) ?>
                                            </a>
                                            <?php if (!empty($opt['is_express_checkout'])): ?>
                                                <span style="display: inline-block; margin-left: 6px; padding: 2px 6px; font-size: 10px; font-weight: 700; color: #2563eb; background: #eff6ff; border-radius: 4px;">EXPRESS</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($opt['processing_type']) ?></td>
                                        <td><?= e($opt['payment_mode']) ?></td>
                                        <td>
                                            <span class="badge-status <?= $opt['status'] === 'active' ? 'active' : 'inactive' ?>">
                                                <?= $opt['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn-tender-secondary" style="padding: 4px 10px; font-size: 12px;" onclick="toggleTenderStatus(<?= (int)$opt['id'] ?>)">
                                                <?= $opt['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>

    <!-- ================= ADD / EDIT TENDER TYPE MODAL ================= -->
    <div class="modal-overlay" id="tenderModalOverlay">
        <div class="tender-modal" onclick="event.stopPropagation()">
            <div class="modal-head">
                <h3 class="modal-title" id="tenderModalTitle">Add Tender Type</h3>
                <button type="button" class="modal-close" onclick="closeTenderModal()">&times;</button>
            </div>
            <form id="tenderForm" onsubmit="submitTenderForm(event)">
                <input type="hidden" name="action" value="save_tender_type">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" id="tenderId" value="">

                <div class="modal-body">
                    <!-- 1. Processing Type -->
                    <div class="form-row">
                        <label class="form-label">Processing Type<span class="req">*</span></label>
                        <select name="processing_type" id="tenderProcessingType" class="form-select" required>
                            <option value="Manual Entry">Manual Entry</option>
                            <option value="Credit Sale">Credit Sale</option>
                            <option value="Loyalty Redemption">Loyalty Redemption</option>
                            <option value="Credit Note">Credit Note</option>
                            <option value="Integrated Card Terminal">Integrated Card Terminal</option>
                            <option value="Dynamic UPI QR">Dynamic UPI QR</option>
                        </select>
                    </div>

                    <!-- 2. Payment Mode -->
                    <div class="form-row">
                        <label class="form-label">Payment Mode<span class="req">*</span></label>
                        <select name="payment_mode" id="tenderPaymentMode" class="form-select" required>
                            <option value="Cash">Cash</option>
                            <option value="Card">Card</option>
                            <option value="UPI">UPI</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Bank Remittance">Bank Remittance</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Credit Sale">Credit Sale</option>
                            <option value="Loyalty Points">Loyalty Points</option>
                            <option value="Credit Note">Credit Note</option>
                            <option value="Google Pay">Google Pay</option>
                            <option value="PhonePe">PhonePe</option>
                            <option value="Paytm">Paytm</option>
                        </select>
                    </div>

                    <!-- 3. Display Name -->
                    <div class="form-row">
                        <label class="form-label">Display Name<span class="req">*</span></label>
                        <input type="text" name="display_name" id="tenderDisplayName" class="form-input" placeholder="e.g. Cash" required>
                    </div>

                    <!-- 4. Deposit To Account -->
                    <div class="form-row">
                        <label class="form-label">Deposit To<span class="req">*</span></label>
                        <select name="deposit_to" id="tenderDepositTo" class="form-select" required>
                            <optgroup label="Cash">
                                <option value="Petty Cash">Petty Cash</option>
                                <option value="Undeposited Funds">Undeposited Funds</option>
                            </optgroup>
                            <optgroup label="Other Current Liability">
                                <option value="Employee Reimbursements">Employee Reimbursements</option>
                            </optgroup>
                            <optgroup label="Bank Accounts">
                                <option value="Main Bank Account">Main Bank Account</option>
                                <option value="HDFC Current Account">HDFC Current Account</option>
                                <option value="ICICI Current Account">ICICI Current Account</option>
                                <option value="State Bank of India">State Bank of India</option>
                                <option value="Razorpay Escrow">Razorpay Escrow</option>
                            </optgroup>
                        </select>
                    </div>

                    <!-- 5. Is customer info required? -->
                    <div class="form-check-wrap">
                        <input type="checkbox" name="is_customer_required" id="tenderIsCustReq" value="1">
                        <div>
                            <div class="check-label-title">Is customer info required?</div>
                            <div class="check-label-desc">Prompt cashier to link or enter customer mobile number before completing sale.</div>
                        </div>
                    </div>

                    <!-- 6. Express Checkout -->
                    <div class="form-check-wrap" style="border-top: 0; padding-top: 6px;">
                        <input type="checkbox" name="is_express_checkout" id="tenderIsExpress" value="1">
                        <div>
                            <div class="check-label-title">Mark as Express Checkout</div>
                            <div class="check-label-desc">Complete the sale instantly with this payment method in 1-click on POS register.</div>
                        </div>
                    </div>

                    <!-- Status -->
                    <input type="hidden" name="status" id="tenderStatus" value="active">
                </div>

                <div class="modal-foot">
                    <button type="submit" class="btn-modal-add" id="tenderSubmitBtn">Add</button>
                    <button type="button" class="btn-modal-cancel" onclick="closeTenderModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================= CHANGE ORDER MODAL ================= -->
    <div class="modal-overlay" id="reorderModalOverlay">
        <div class="tender-modal" onclick="event.stopPropagation()">
            <div class="modal-head">
                <h3 class="modal-title">Change Tender Types Order</h3>
                <button type="button" class="modal-close" onclick="closeReorderModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size: 13px; color: #64748b; margin-top: 0; margin-bottom: 16px;">
                    Move tender types up or down to set the exact order they appear in the POS Terminal checkout screen.
                </p>
                <div class="reorder-list" id="reorderList">
                    <?php foreach ($paymentOptions as $opt): ?>
                        <div class="reorder-item" data-id="<?= (int)$opt['id'] ?>">
                            <div class="reorder-handle">
                                <span style="color: #94a3b8; cursor: grab;">?</span>
                                <span><?= e($opt['display_name']) ?></span>
                                <span style="font-size: 12px; color: #64748b;">(<?= e($opt['payment_mode']) ?>)</span>
                            </div>
                            <div class="reorder-btns">
                                <button type="button" class="btn-reorder-move" onclick="moveReorderItem(this, -1)">? Up</button>
                                <button type="button" class="btn-reorder-move" onclick="moveReorderItem(this, 1)">? Down</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-modal-add" onclick="saveReorderedList()">Save Order</button>
                <button type="button" class="btn-modal-cancel" onclick="closeReorderModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        function openAddTenderModal() {
            document.getElementById('tenderModalTitle').textContent = 'Add Tender Type';
            document.getElementById('tenderSubmitBtn').textContent = 'Add';
            document.getElementById('tenderId').value = '';
            document.getElementById('tenderDisplayName').value = '';
            document.getElementById('tenderProcessingType').value = 'Manual Entry';
            document.getElementById('tenderPaymentMode').value = 'Cash';
            document.getElementById('tenderDepositTo').value = 'Petty Cash';
            document.getElementById('tenderIsCustReq').checked = false;
            document.getElementById('tenderIsExpress').checked = false;
            document.getElementById('tenderStatus').value = 'active';
            document.getElementById('tenderModalOverlay').classList.add('active');
        }

        function editTenderType(opt) {
            document.getElementById('tenderModalTitle').textContent = 'Edit Tender Type';
            document.getElementById('tenderSubmitBtn').textContent = 'Save Changes';
            document.getElementById('tenderId').value = opt.id;
            document.getElementById('tenderDisplayName').value = opt.display_name;
            document.getElementById('tenderProcessingType').value = opt.processing_type;
            document.getElementById('tenderPaymentMode').value = opt.payment_mode;
            document.getElementById('tenderDepositTo').value = opt.deposit_to;
            document.getElementById('tenderIsCustReq').checked = opt.is_customer_required == 1;
            document.getElementById('tenderIsExpress').checked = opt.is_express_checkout == 1;
            document.getElementById('tenderStatus').value = opt.status;
            document.getElementById('tenderModalOverlay').classList.add('active');
        }

        function closeTenderModal() {
            document.getElementById('tenderModalOverlay').classList.remove('active');
        }

        function submitTenderForm(e) {
            e.preventDefault();
            const form = document.getElementById('tenderForm');
            const formData = new FormData(form);

            fetch('payment-options.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Failed to save tender type.');
                }
            })
            .catch(err => {
                alert('Connection error occurred.');
            });
        }

        function toggleTenderStatus(id) {
            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('csrf_token', '<?= csrf_token() ?>');
            formData.append('id', id);

            fetch('payment-options.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Failed to update status.');
                }
            });
        }

        function openReorderModal() {
            document.getElementById('reorderModalOverlay').classList.add('active');
        }

        function closeReorderModal() {
            document.getElementById('reorderModalOverlay').classList.remove('active');
        }

        function moveReorderItem(btn, direction) {
            const item = btn.closest('.reorder-item');
            if (direction === -1 && item.previousElementSibling) {
                item.parentNode.insertBefore(item, item.previousElementSibling);
            } else if (direction === 1 && item.nextElementSibling) {
                item.parentNode.insertBefore(item.nextElementSibling, item);
            }
        }

        function saveReorderedList() {
            const items = document.querySelectorAll('#reorderList .reorder-item');
            const ids = Array.from(items).map(el => parseInt(el.getAttribute('data-id'), 10));

            const formData = new FormData();
            formData.append('action', 'reorder');
            formData.append('csrf_token', '<?= csrf_token() ?>');
            formData.append('ordered_ids', JSON.stringify(ids));

            fetch('payment-options.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Failed to save reorder.');
                }
            });
        }

        // Close on overlay click
        document.getElementById('tenderModalOverlay').addEventListener('click', closeTenderModal);
        document.getElementById('reorderModalOverlay').addEventListener('click', closeReorderModal);
    </script>
</body>
</html>

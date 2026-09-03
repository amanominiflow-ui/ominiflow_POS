<?php
declare(strict_types=1);

/**
 * OminiFlow POS - Payments Made (Vendor Payments) Screen (Zoho POS / Zoho Books Parity)
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/purchases_db.php';

require_auth();

$pageTitle = 'All Payments';

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

// Handle Vendor Payment Recording
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/payments-made.php');
    }

    if ($action === 'record_payment') {
        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
        $billId = !empty($_POST['bill_id']) ? (int)$_POST['bill_id'] : null;
        $paymentDate = (string) ($_POST['payment_date'] ?? date('Y-m-d'));
        $locationName = (string) ($_POST['location_name'] ?? 'Head Office');
        $paymentMode = (string) ($_POST['payment_mode'] ?? 'Cash');
        $referenceNumber = (string) ($_POST['reference_number'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0.00);
        $notes = (string) ($_POST['notes'] ?? '');

        $payData = [
            'vendor_id' => $vendorId,
            'bill_id' => $billId,
            'payment_date' => $paymentDate,
            'location_name' => $locationName,
            'payment_mode' => $paymentMode,
            'reference_number' => $referenceNumber,
            'amount' => $amount,
            'notes' => $notes,
        ];

        $res = record_vendor_payment($payData, $userId);

        if ($res['success']) {
            set_flash('success', "Payment #{$res['payment_number']} of ₹" . number_format($res['amount'], 2) . " recorded successfully!");
        } else {
            set_flash('error', $res['error'] ?? 'Could not record payment.');
        }
        redirect(APP_URL . '/payments-made.php');
    }
}

$search = trim($_GET['search'] ?? '');
$payments = get_vendor_payments($search);
$vendors = get_vendors();
$bills = get_purchase_bills('', '', 100);
$unpaidBills = array_filter($bills, fn($b) => (float)$b['balance_due'] > 0);
$locations = get_purchase_locations();

// Preselected bill if passed
$preSelectedBillId = (int)($_GET['bill_id'] ?? 0);
$preSelectedBill = $preSelectedBillId > 0 ? get_purchase_bill_by_id($preSelectedBillId) : null;

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
            max-width: 620px;
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
                        <h1 class="page-title">All Payments</h1>
                        <p class="page-subtitle">Track payments made to vendors and suppliers across all payment modes.</p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="header-btn" id="openPaymentModalBtn" style="padding: 10px 20px; display: inline-flex; align-items: center; gap: 8px;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                            <span>+ New Payment</span>
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

                <!-- Search Toolbar -->
                <div class="filter-card" style="padding: 16px 20px; margin-bottom: 24px;">
                    <form method="GET" action="<?= asset('payments-made.php') ?>" style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 220px;">
                            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by Payment #, Vendor Name, Ref #, Bill #..." class="form-control">
                        </div>
                        <button type="submit" class="header-btn" style="padding: 8px 18px;">Search</button>
                        <?php if ($search !== ''): ?>
                            <a href="<?= asset('payments-made.php') ?>" class="btn-secondary" style="padding: 8px 14px;">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- PAYMENTS TABLE MATCHING SCREENSHOTS 1 & 5 -->
                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h2 class="section-heading">Payments Made</h2>
                            <p class="section-subheading">Vendor settlement history and disbursements</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>DATE</th>
                                    <th>LOCATION</th>
                                    <th>PAYMENT #</th>
                                    <th>REFERENCE#</th>
                                    <th>VENDOR NAME</th>
                                    <th>BILL#</th>
                                    <th>MODE</th>
                                    <th>STATUS</th>
                                    <th>AMOUNT</th>
                                    <th>UNUSED AMOUNT</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr><td colspan="10" style="text-align: center; padding: 32px; color: #64748b;">No payments found. Click "+ New Payment" to record a vendor disbursement.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $p): ?>
                                        <tr>
                                            <td style="font-size: 13px; color: var(--saas-slate-600);"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                                            <td><span class="badge badge-secondary"><?= e($p['location_name'] ?? 'Head Office') ?></span></td>
                                            <td>
                                                <strong style="font-family: monospace; color: var(--saas-primary); font-size: 13.5px;"><?= e($p['payment_number']) ?></strong>
                                            </td>
                                            <td style="font-size: 12.5px; color: #64748b;"><?= e($p['reference_number'] ?: '—') ?></td>
                                            <td><strong style="text-transform: uppercase;"><?= e($p['vendor_name']) ?></strong></td>
                                            <td>
                                                <?php if (!empty($p['bill_number'])): ?>
                                                    <span style="font-family: monospace; color: #2563eb; font-weight: 600;"><?= e($p['bill_number']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-info" style="font-weight: 600;"><?= e($p['payment_mode']) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-success" style="background: #dcfce7; color: #15803d; font-weight: 700;">PAID</span>
                                            </td>
                                            <td><strong style="font-size: 13.5px; color: var(--saas-navy-950);">₹<?= number_format((float)$p['amount'], 2) ?></strong></td>
                                            <td>
                                                <span style="font-size: 13px; color: #64748b;">₹<?= number_format((float)$p['unused_amount'], 2) ?></span>
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

    <!-- RECORD PAYMENT MODAL -->
    <div class="modal-overlay" id="recordPaymentModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title">Record Vendor Payment</h3>
                <button type="button" class="modal-close-btn" id="closePaymentModal">&times;</button>
            </div>
            <form id="recordPaymentForm" method="POST" action="<?= asset('payments-made.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="record_payment">

                <div class="modal-body">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                        <div class="form-group">
                            <label class="form-label">Select Vendor <span style="color:#ef4444;">*</span></label>
                            <select name="vendor_id" id="vendorSelectDropdown" required class="form-control">
                                <option value="">-- Choose Vendor --</option>
                                <?php foreach ($vendors as $ven): ?>
                                    <option value="<?= $ven['id'] ?>" <?= ($preSelectedBill && (int)$preSelectedBill['vendor_id'] === (int)$ven['id']) ? 'selected' : '' ?>>
                                        <?= e($ven['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Link to Bill (Optional)</label>
                            <select name="bill_id" id="billSelectDropdown" class="form-control">
                                <option value="">-- Advance / General Payment --</option>
                                <?php foreach ($unpaidBills as $ub): ?>
                                    <option value="<?= $ub['id'] ?>" data-vendor="<?= $ub['vendor_id'] ?>" data-balance="<?= $ub['balance_due'] ?>" <?= $preSelectedBillId === (int)$ub['id'] ? 'selected' : '' ?>>
                                        <?= e($ub['bill_number']) ?> — <?= e($ub['vendor_name']) ?> (Due: ₹<?= number_format((float)$ub['balance_due'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                        <div class="form-group">
                            <label class="form-label">Payment Date <span style="color:#ef4444;">*</span></label>
                            <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Payment Mode <span style="color:#ef4444;">*</span></label>
                            <select name="payment_mode" required class="form-control">
                                <option value="Cash">Cash</option>
                                <option value="UPI">UPI</option>
                                <option value="Netbanking">Netbanking</option>
                                <option value="Credit Card">Credit Card</option>
                                <option value="Debit Card">Debit Card</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                        <div class="form-group">
                            <label class="form-label">Payment Amount (₹) <span style="color:#ef4444;">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="amount" id="paymentAmountInput" value="<?= $preSelectedBill ? (float)$preSelectedBill['balance_due'] : '1000.00' ?>" required class="form-control">
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

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label">Reference # (UTR / Cheque / Txn ID)</label>
                        <input type="text" name="reference_number" placeholder="e.g. UPI-9382109472 / CHQ-10492" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes / Remarks</label>
                        <textarea name="notes" rows="2" class="form-control" placeholder="Optional notes..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelPaymentModal">Cancel</button>
                    <button type="submit" class="header-btn">Record Payment</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const payModal = document.getElementById('recordPaymentModal');
        const openPayBtn = document.getElementById('openPaymentModalBtn');
        const closePayBtn = document.getElementById('closePaymentModal');
        const cancelPayBtn = document.getElementById('cancelPaymentModal');
        const billSelect = document.getElementById('billSelectDropdown');
        const vendorSelect = document.getElementById('vendorSelectDropdown');
        const amountInput = document.getElementById('paymentAmountInput');

        if (openPayBtn) openPayBtn.addEventListener('click', () => payModal.classList.add('open'));
        if (closePayBtn) closePayBtn.addEventListener('click', () => payModal.classList.remove('open'));
        if (cancelPayBtn) cancelPayBtn.addEventListener('click', () => payModal.classList.remove('open'));

        if (window.location.search.includes('action=new') || <?= $preSelectedBillId > 0 ? 'true' : 'false' ?>) {
            payModal.classList.add('open');
        }

        billSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const venId = opt.getAttribute('data-vendor');
            const bal = opt.getAttribute('data-balance');
            if (venId) vendorSelect.value = venId;
            if (bal) amountInput.value = parseFloat(bal).toFixed(2);
        });
    </script>
</body>
</html>

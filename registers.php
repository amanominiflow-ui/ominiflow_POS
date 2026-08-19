<?php
/**
 * OminiFlow POS - Register & Cash Drawer Shift Management Screen
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/registers_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

// Handle Shift Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/registers.php');
    }

    if ($action === 'open_session') {
        $registerId = (int) ($_POST['register_id'] ?? 1);
        $openingCash = (float) ($_POST['opening_cash'] ?? 0.00);
        $res = open_register_session($registerId, (int)$userId, $openingCash);

        if ($res['success']) {
            set_flash('success', 'Register opened successfully! Opening Cash Float: ₹' . number_format($openingCash, 2));
        } else {
            set_flash('error', $res['error'] ?? 'Could not open register session.');
        }
        redirect(APP_URL . '/registers.php');
    } elseif ($action === 'close_session') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $actualCash = (float) ($_POST['closing_cash_actual'] ?? 0.00);
        $notes = (string) ($_POST['closing_notes'] ?? '');

        $res = close_register_session($sessionId, $actualCash, $notes, (int)$userId);
        if ($res['success']) {
            $diffText = ($res['difference'] >= 0) ? '+₹' . number_format($res['difference'], 2) : '-₹' . number_format(abs($res['difference']), 2);
            set_flash('success', 'Register closed successfully. Expected: ₹' . number_format($res['expected_cash'], 2) . ', Counted: ₹' . number_format($res['actual_cash'], 2) . ' (Variance: ' . $diffText . ')');
        } else {
            set_flash('error', $res['error'] ?? 'Could not close register session.');
        }
        redirect(APP_URL . '/registers.php');
    } elseif ($action === 'cash_movement') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0.00);
        $type = (string) ($_POST['type'] ?? 'cash_in');
        $reason = (string) ($_POST['reason'] ?? '');

        $res = record_cash_movement($sessionId, $amount, $type, $reason, (int)$userId);
        if ($res['success']) {
            set_flash('success', ($type === 'cash_in' ? 'Cash In' : 'Cash Out') . ' recorded: ₹' . number_format($amount, 2));
        } else {
            set_flash('error', $res['error'] ?? 'Could not record cash movement.');
        }
        redirect(APP_URL . '/registers.php');
    }
}

$activeSession = get_open_register_session($userId);
$registers = get_registers();
$pastSessions = get_register_sessions(25);

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register & Cash Management - OminiFlow POS</title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <div class="page-top-header">
                    <div>
                        <h1 class="page-title">Register & Cash Drawer Management</h1>
                        <p class="page-subtitle">Manage cash drawer shift sessions, float opening, shift sales, cash in/out, and Z-Report closing reconciliation.</p>
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

                <!-- Active Session Banner / Open Register Section -->
                <?php if ($activeSession): ?>
                    <div class="section-card" style="border: 2px solid #3b82f6; background: #f8fafc; margin-bottom: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="badge badge-success" style="font-size: 12px; padding: 4px 10px;">🟢 ACTIVE SHIFT SESSION</span>
                                    <strong style="color: var(--saas-navy-950); font-size: 16px;"><?= e($activeSession['register_name']) ?> (<?= e($activeSession['register_code']) ?>)</strong>
                                </div>
                                <div style="color: var(--saas-slate-500); font-size: 13px; margin-top: 6px;">
                                    Opened on <strong><?= date('M d, Y • h:i A', strtotime($activeSession['opened_at'])) ?></strong> by <strong><?= e($activeSession['cashier_name']) ?></strong>
                                </div>
                            </div>

                            <div style="display: flex; gap: 10px;">
                                <button type="button" class="btn-secondary" id="cashMoveBtn" style="padding: 8px 14px;">Cash In / Out</button>
                                <button type="button" class="header-btn" id="closeShiftBtn" style="background: #b91c1c; border-color: #b91c1c;">Close Register & Z-Report</button>
                            </div>
                        </div>

                        <!-- Live Session Metrics -->
                        <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-top: 20px;">
                            <div class="kpi-card" style="background: #fff;">
                                <div class="kpi-title">Opening Float</div>
                                <div class="kpi-value" style="font-size: 20px;">₹<?= number_format((float)$activeSession['opening_cash'], 2) ?></div>
                            </div>
                            <div class="kpi-card" style="background: #fff;">
                                <div class="kpi-title">Cash Sales</div>
                                <div class="kpi-value" style="font-size: 20px; color: #047857;">₹<?= number_format((float)$activeSession['total_cash_sales'], 2) ?></div>
                            </div>
                            <div class="kpi-card" style="background: #fff;">
                                <div class="kpi-title">UPI / Card Sales</div>
                                <div class="kpi-value" style="font-size: 20px; color: #1e3a8a;">₹<?= number_format((float)($activeSession['total_card_sales'] + $activeSession['total_upi_sales']), 2) ?></div>
                            </div>
                            <div class="kpi-card" style="background: #fff;">
                                <div class="kpi-title">Cash In / Out</div>
                                <div class="kpi-value" style="font-size: 20px; color: #d97706;">+₹<?= number_format((float)$activeSession['cash_in'], 2) ?> / -₹<?= number_format((float)$activeSession['cash_out'], 2) ?></div>
                            </div>
                            <div class="kpi-card" style="background: #eff6ff; border: 1px solid #bfdbfe;">
                                <div class="kpi-title" style="color: #1e40af;">Expected Drawer Cash</div>
                                <?php
                                    $expDrawer = (float)$activeSession['opening_cash'] + (float)$activeSession['total_cash_sales'] + (float)$activeSession['cash_in'] - (float)$activeSession['cash_out'] - (float)$activeSession['total_refunds'];
                                ?>
                                <div class="kpi-value" style="font-size: 22px; color: #1e3a8a;">₹<?= number_format($expDrawer, 2) ?></div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Open Register Action Card -->
                    <div class="section-card" style="background: #fff; margin-bottom: 24px; text-align: center; padding: 36px 20px;">
                        <div style="font-size: 42px; margin-bottom: 12px;">🏪</div>
                        <h2 style="font-size: 20px; font-weight: 800; color: var(--saas-navy-950); margin-bottom: 6px;">Register is Currently Closed</h2>
                        <p style="color: var(--saas-slate-500); max-width: 480px; margin: 0 auto 20px;">To start billing sales and tracking drawer cash, open a register shift session with your opening cash float.</p>
                        <button type="button" class="header-btn" id="openShiftModalBtn" style="padding: 10px 24px; font-size: 15px;">
                            <span>+ Open Register Shift</span>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Past Shift Sessions Table -->
                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title">Past Register Sessions & Shift History</h2>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Session #</th>
                                    <th>Register</th>
                                    <th>Cashier</th>
                                    <th>Opened</th>
                                    <th>Closed</th>
                                    <th>Opening Float</th>
                                    <th>Cash Sales</th>
                                    <th>Expected Cash</th>
                                    <th>Actual Counted</th>
                                    <th>Variance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pastSessions)): ?>
                                    <tr><td colspan="11" style="text-align: center; padding: 24px; color: #64748b;">No register shift sessions recorded.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pastSessions as $ps): ?>
                                        <tr>
                                            <td><strong style="font-family: monospace; color: var(--saas-primary);">#<?= $ps['id'] ?></strong></td>
                                            <td><?= e($ps['register_name']) ?></td>
                                            <td><strong><?= e($ps['cashier_name']) ?></strong></td>
                                            <td style="font-size: 12px;"><?= date('M d, h:i A', strtotime($ps['opened_at'])) ?></td>
                                            <td style="font-size: 12px;"><?= $ps['closed_at'] ? date('M d, h:i A', strtotime($ps['closed_at'])) : '<span style="color:#047857; font-weight:700;">Open Now</span>' ?></td>
                                            <td>₹<?= number_format((float)$ps['opening_cash'], 2) ?></td>
                                            <td>₹<?= number_format((float)$ps['total_cash_sales'], 2) ?></td>
                                            <td>₹<?= number_format((float)$ps['closing_cash_expected'], 2) ?></td>
                                            <td>₹<?= number_format((float)$ps['closing_cash_actual'], 2) ?></td>
                                            <td>
                                                <?php
                                                    $diff = (float)$ps['cash_difference'];
                                                    if ($diff === 0.00) echo '<span class="badge badge-success">Balanced (₹0)</span>';
                                                    elseif ($diff > 0) echo '<span class="badge badge-success">+₹'.number_format($diff, 2).'</span>';
                                                    else echo '<span class="badge badge-danger">-₹'.number_format(abs($diff), 2).'</span>';
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge <?= $ps['status'] === 'open' ? 'badge-success' : 'badge-secondary' ?>">
                                                    <?= ucfirst($ps['status']) ?>
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

    <!-- 1. OPEN SHIFT MODAL -->
    <div class="modal-overlay" id="openShiftModal">
        <div class="modal-box" style="max-width: 440px;">
            <div class="modal-header">
                <h3 class="modal-title">Open Register Shift</h3>
                <button type="button" class="modal-close-btn" id="closeOpenModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('registers.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="open_session">
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label">Select Register Counter</label>
                        <select name="register_id" class="form-control">
                            <?php foreach ($registers as $reg): ?>
                                <option value="<?= $reg['id'] ?>"><?= e($reg['name']) ?> (<?= e($reg['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Opening Cash Float Amount (₹)</label>
                        <input type="number" step="0.01" name="opening_cash" value="1000.00" required class="form-control">
                        <span style="font-size: 11.5px; color: var(--saas-slate-400);">Starting physical change placed in cash drawer.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelOpenModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0;">Confirm & Open Register</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. CLOSE SHIFT & Z-REPORT MODAL -->
    <?php if ($activeSession): ?>
    <div class="modal-overlay" id="closeShiftModal">
        <div class="modal-box" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">Close Register & Z-Report Reconciliation</h3>
                <button type="button" class="modal-close-btn" id="closeCloseModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('registers.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="close_session">
                <input type="hidden" name="session_id" value="<?= $activeSession['id'] ?>">
                <div class="modal-body">
                    <div style="background: #f8fafc; padding: 12px 14px; border-radius: var(--saas-radius-md); border: 1px solid var(--saas-border); margin-bottom: 14px; font-size: 13px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span>Opening Cash:</span> <strong>₹<?= number_format((float)$activeSession['opening_cash'], 2) ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span>Cash Sales:</span> <strong>+₹<?= number_format((float)$activeSession['total_cash_sales'], 2) ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span>Cash In / Out:</span> <strong>+₹<?= number_format((float)$activeSession['cash_in'], 2) ?> / -₹<?= number_format((float)$activeSession['cash_out'], 2) ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; border-top: 1px dashed var(--saas-border); padding-top: 6px; font-weight: 700; color: #1e3a8a;">
                            <span>Expected Cash in Drawer:</span> <span>₹<?= number_format($expDrawer, 2) ?></span>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label">Actual Physical Cash Counted (₹) <span style="color: #ef4444;">*</span></label>
                        <input type="number" step="0.01" name="closing_cash_actual" required placeholder="Enter total counted cash" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Closing Notes / Discrepancy Explanation</label>
                        <input type="text" name="closing_notes" placeholder="e.g. End of evening shift, cash balanced" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelCloseModal">Cancel</button>
                    <button type="submit" class="header-btn" style="background: #b91c1c; border-color: #b91c1c;">Close & Reconcile Shift</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 3. CASH IN / OUT MODAL -->
    <div class="modal-overlay" id="cashMoveModal">
        <div class="modal-box" style="max-width: 440px;">
            <div class="modal-header">
                <h3 class="modal-title">Record Cash In / Out Adjustment</h3>
                <button type="button" class="modal-close-btn" id="closeCashMoveModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('registers.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cash_movement">
                <input type="hidden" name="session_id" value="<?= $activeSession['id'] ?>">
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label">Movement Type</label>
                        <select name="type" class="form-control">
                            <option value="cash_in">Cash In (Add float / money to drawer)</option>
                            <option value="cash_out">Cash Out (Petty expense / bank deposit)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label">Amount (₹)</label>
                        <input type="number" step="0.01" name="amount" required class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reason / Memo</label>
                        <input type="text" name="reason" placeholder="e.g. Adding change coins, petty store supplies" required class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelCashMoveModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0;">Save Movement</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        const openShiftModal = document.getElementById('openShiftModal');
        const openShiftBtn = document.getElementById('openShiftModalBtn');
        if (openShiftBtn) openShiftBtn.addEventListener('click', () => openShiftModal.classList.add('open'));
        const closeOpenModal = document.getElementById('closeOpenModal');
        if (closeOpenModal) closeOpenModal.addEventListener('click', () => openShiftModal.classList.remove('open'));
        const cancelOpenModal = document.getElementById('cancelOpenModal');
        if (cancelOpenModal) cancelOpenModal.addEventListener('click', () => openShiftModal.classList.remove('open'));

        const closeShiftModal = document.getElementById('closeShiftModal');
        const closeShiftBtn = document.getElementById('closeShiftBtn');
        if (closeShiftBtn) closeShiftBtn.addEventListener('click', () => closeShiftModal.classList.add('open'));
        const closeCloseModal = document.getElementById('closeCloseModal');
        if (closeCloseModal) closeCloseModal.addEventListener('click', () => closeShiftModal.classList.remove('open'));
        const cancelCloseModal = document.getElementById('cancelCloseModal');
        if (cancelCloseModal) cancelCloseModal.addEventListener('click', () => closeShiftModal.classList.remove('open'));

        const cashMoveModal = document.getElementById('cashMoveModal');
        const cashMoveBtn = document.getElementById('cashMoveBtn');
        if (cashMoveBtn) cashMoveBtn.addEventListener('click', () => cashMoveModal.classList.add('open'));
        const closeCashMoveModal = document.getElementById('closeCashMoveModal');
        if (closeCashMoveModal) closeCashMoveModal.addEventListener('click', () => cashMoveModal.classList.remove('open'));
        const cancelCashMoveModal = document.getElementById('cancelCashMoveModal');
        if (cancelCashMoveModal) cancelCashMoveModal.addEventListener('click', () => cashMoveModal.classList.remove('open'));
    </script>
</body>
</html>

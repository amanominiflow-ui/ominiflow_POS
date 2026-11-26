<?php
/**
 * OminiFlow POS - Register & Cash Drawer Shift Management Screen
 * Complete denomination-wise cash counting for opening & closing sessions,
 * admin configuration, audit logs, and printable vouchers.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/registers_db.php';
require_once __DIR__ . '/includes/outlets_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;
$isAdmin = user_has_full_access($user) || !empty($user['is_super_admin']);

// AJAX Endpoint for Session Details & Denomination Breakdown
if (isset($_GET['ajax_session_details'])) {
    header('Content-Type: application/json');
    $sessId = (int) $_GET['ajax_session_details'];
    $sess = get_register_session_by_id($sessId);
    if (!$sess) {
        echo json_encode(['success' => false, 'error' => 'Session not found.']);
        exit;
    }
    $denoms = get_session_denominations($sessId);
    $audits = get_session_audit_logs($sessId);
    $activeDenoms = get_active_denominations();
    echo json_encode([
        'success' => true,
        'session' => $sess,
        'denominations' => $denoms,
        'audit_logs' => $audits,
        'active_denominations' => $activeDenoms,
        'is_admin' => $isAdmin,
    ]);
    exit;
}

// Handle Shift Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/registers.php');
    }

    if ($action === 'open_session') {
        $registerId = (int) ($_POST['register_id'] ?? 1);
        $outletId = (int) ($_POST['outlet_id'] ?? 0);
        $denominations = (array) ($_POST['denominations'] ?? []);
        $confirmCount = !empty($_POST['confirm_count']);
        $notes = (string) ($_POST['opening_notes'] ?? '');

        if (empty($denominations)) {
            set_flash('error', 'Denomination-wise cash counting is mandatory. Cashier must enter note and coin counts.');
            redirect(APP_URL . '/registers.php');
        }
        if (!$confirmCount) {
            set_flash('error', 'You must confirm the physical cash count before starting the session.');
            redirect(APP_URL . '/registers.php');
        }

        $res = open_register_session($registerId, (int)$userId, 0.00, null, $outletId, $denominations, $confirmCount, $notes);

        if ($res['success']) {
            set_flash('success', 'Register opened successfully! Opening Cash Float: ₹' . number_format($res['opening_cash'] ?? 0, 2));
        } else {
            set_flash('error', $res['error'] ?? 'Could not open register session.');
        }
        redirect(APP_URL . '/registers.php');
    } elseif ($action === 'close_session') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $denominations = (array) ($_POST['denominations'] ?? []);
        $confirmCount = !empty($_POST['confirm_count']);
        $discrepancyReason = (string) ($_POST['discrepancy_reason'] ?? '');
        $notes = (string) ($_POST['closing_notes'] ?? '');

        if (empty($denominations)) {
            set_flash('error', 'Denomination-wise cash counting is mandatory.');
            redirect(APP_URL . '/registers.php');
        }
        if (!$confirmCount) {
            set_flash('error', 'You must confirm the physical cash count before closing the session.');
            redirect(APP_URL . '/registers.php');
        }

        $res = close_register_session($sessionId, 0.00, $notes, (int)$userId, null, $denominations, $confirmCount, $discrepancyReason);
        if ($res['success']) {
            $diffText = ($res['difference'] >= 0) ? '+₹' . number_format($res['difference'], 2) : '-₹' . number_format(abs($res['difference']), 2);
            set_flash('success', 'Register closed successfully. Expected: ₹' . number_format($res['expected_cash'], 2) . ', Counted: ₹' . number_format($res['actual_cash'], 2) . ' (' . ($res['difference'] == 0 ? 'Balanced' : 'Variance: ' . $diffText) . ')');
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
    } elseif ($action === 'admin_edit_count') {
        if (!$isAdmin) {
            set_flash('error', 'Unauthorized: Only an Administrator or Store Owner can modify denomination records.');
            redirect(APP_URL . '/registers.php');
        }
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $entryType = (string) ($_POST['entry_type'] ?? 'opening');
        $denominations = (array) ($_POST['denominations'] ?? []);
        $reason = (string) ($_POST['audit_reason'] ?? '');

        $res = admin_update_session_denominations($sessionId, $entryType, $denominations, $reason, (int)$userId);
        if ($res['success']) {
            set_flash('success', 'Session cash denominations updated successfully. Audit log recorded.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not update denominations.');
        }
        redirect(APP_URL . '/registers.php');
    } elseif ($action === 'save_denomination') {
        if (!$isAdmin) {
            set_flash('error', 'Unauthorized: Only administrators can configure currency denominations.');
            redirect(APP_URL . '/registers.php');
        }
        $res = save_denomination($_POST);
        if ($res['success']) {
            set_flash('success', 'Currency denomination saved successfully.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not save denomination.');
        }
        redirect(APP_URL . '/registers.php');
    } elseif ($action === 'delete_denomination') {
        if (!$isAdmin) {
            set_flash('error', 'Unauthorized.');
            redirect(APP_URL . '/registers.php');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $res = delete_denomination($id);
        if ($res['success']) {
            set_flash('success', 'Denomination deleted.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not delete denomination.');
        }
        redirect(APP_URL . '/registers.php');
    } elseif ($action === 'toggle_denomination') {
        if (!$isAdmin) {
            set_flash('error', 'Unauthorized.');
            redirect(APP_URL . '/registers.php');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $isActive = !empty($_POST['is_active']);
        toggle_denomination_status($id, $isActive);
        set_flash('success', 'Denomination status updated.');
        redirect(APP_URL . '/registers.php');
    }
}

$activeSession = get_open_register_session($userId);
$registers = get_registers();
$registerOutlets = get_outlets('active');
$staffOutletId = user_pos_is_outlet_locked($user) ? (int) ($user['outlet_id'] ?? 0) : 0;
if ($staffOutletId > 0) {
    $registers = array_values(array_filter($registers, static function (array $reg) use ($staffOutletId): bool {
        $regOutlet = (int) ($reg['outlet_id'] ?? 0);
        return $regOutlet === 0 || $regOutlet === $staffOutletId;
    }));
}
$pastSessions = get_register_sessions(30);
$activeDenominations = get_active_denominations();
$allDenominations = $isAdmin ? get_all_denominations() : [];

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
    <style>
        /* Modal Overlay & Responsive Viewport Positioning (Never Clipped Off-Screen) */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 99999;
            overflow-y: auto !important;
            padding: 16px 12px !important;
            box-sizing: border-box;
        }
        .modal-overlay.open,
        .modal-overlay.show {
            display: flex !important;
            align-items: flex-start !important;
            justify-content: center !important;
        }
        .modal-box {
            background: #ffffff;
            border-radius: 16px;
            width: 100%;
            max-width: 620px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            border: 1px solid #e2e8f0;
            margin: 12px auto !important;
            position: relative;
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 24px);
        }
        .modal-box > form {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
        }
        .modal-header {
            flex-shrink: 0;
            padding: 14px 20px;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
            border-radius: 16px 16px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-body {
            flex: 1 1 auto;
            overflow-y: auto !important;
            padding: 14px 20px;
            min-height: 0;
        }
        .modal-footer {
            flex-shrink: 0;
            padding: 12px 20px;
            border-top: 1px solid #f1f5f9;
            background: #fafafa;
            border-radius: 0 0 16px 16px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Denomination Counting Screen Styles (2-Column Grid: Fits ALL on screen) */
        .denom-card-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 6px 10px;
            margin: 8px 0;
            max-height: none !important;
            overflow: visible !important;
        }
        @media (max-width: 520px) {
            .denom-card-list {
                grid-template-columns: 1fr;
            }
        }
        .denom-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 5px 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            transition: all 0.15s ease;
        }
        .denom-row:hover,
        .denom-row:focus-within {
            background: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.12);
        }
        .denom-info {
            display: flex;
            flex-direction: column;
            min-width: 52px;
        }
        .denom-label {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.1;
        }
        .denom-sub {
            font-size: 10px;
            color: #64748b;
            font-weight: 500;
            text-transform: capitalize;
        }
        .pill-count-input {
            border-radius: 9999px !important;
            border: 1.5px solid #cbd5e1 !important;
            padding: 3px 8px !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            text-align: center !important;
            width: 72px !important;
            height: 32px !important;
            background: #ffffff !important;
            color: #0f172a !important;
            outline: none !important;
            transition: all 0.18s ease !important;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.03) !important;
        }
        .pill-count-input:focus {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 2.5px rgba(37, 99, 235, 0.2) !important;
            background: #ffffff !important;
        }
        .denom-line-total {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            min-width: 65px;
            text-align: right;
        }
        .denom-summary-box {
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 10px;
            padding: 8px 14px;
            margin: 8px 0;
        }
        .denom-summary-box.closing-summary {
            background: #eff6ff;
            border-color: #93c5fd;
        }
        .denom-summary-main {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .denom-summary-title {
            font-size: 14px;
            font-weight: 700;
            color: #166534;
        }
        .denom-summary-box.closing-summary .denom-summary-title {
            color: #1e3a8a;
        }
        .denom-grand-total {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }
        .denom-grand-total.green { color: #15803d; }
        .denom-grand-total.navy { color: #1e3a8a; }
        .denom-footnote {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }
        .confirm-count-card {
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 10px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .confirm-count-card:hover {
            border-color: #94a3b8;
            background: #f1f5f9;
        }
        .confirm-count-card input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: #15803d;
            cursor: pointer;
            flex-shrink: 0;
            margin: 0;
        }
        .confirm-count-card span {
            font-size: 12px;
            font-weight: 600;
            color: #1e293b;
            line-height: 1.3;
        }
        .badge-pill-opening {
            background: #dcfce7;
            color: #15803d;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 9999px;
            display: inline-block;
        }
        .badge-pill-closing {
            background: #fee2e2;
            color: #b91c1c;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 9999px;
            display: inline-block;
        }
        .variance-card {
            padding: 8px 12px;
            border-radius: 8px;
            margin-top: 8px;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
        }
        .variance-card.balanced { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .variance-card.shortage { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .variance-card.excess { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .btn-action-icon {
            padding: 5px 9px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .btn-action-icon:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <div class="page-top-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
                    <div>
                        <h1 class="page-title">Register & Cash Drawer Management</h1>
                        <p class="page-subtitle">Mandatory denomination-wise cash counting for Opening & Closing shifts, drawer reconciliation, and audit reporting.</p>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <?php if ($activeSession): ?>
                            <a href="<?= asset('session-cash-print.php?id=' . (int)$activeSession['id'] . '&type=opening') ?>" target="_blank" class="btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                <span>Print Opening Slip</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <button type="button" class="btn-secondary" id="openManageDenomsBtn" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                                <span>⚙️ Manage Denominations</span>
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

                <!-- Active Session Banner / Open Register Section -->
                <?php if ($activeSession): ?>
                    <div class="section-card" style="border: 2px solid #3b82f6; background: #f8fafc; margin-bottom: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="badge badge-success" style="font-size: 12px; padding: 4px 10px;">🟢 ACTIVE SHIFT SESSION</span>
                                    <strong style="color: var(--saas-navy-950); font-size: 16px;"><?= e($activeSession['register_name']) ?> (<?= e($activeSession['register_code']) ?>)</strong>
                                    <?php if (!empty($activeSession['outlet_name'])): ?>
                                        <span style="color: #1e3a8a; font-weight: 700;">· <?= e($activeSession['outlet_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="color: var(--saas-slate-500); font-size: 13px; margin-top: 6px;">
                                    Opened on <strong><?= date('M d, Y • h:i A', strtotime($activeSession['opened_at'])) ?></strong> by <strong><?= e($activeSession['cashier_name']) ?></strong>
                                    <?php if (!empty($activeSession['opening_count_confirmed'])): ?>
                                        <span style="color: #15803d; font-weight: 600; margin-left: 8px;">✓ Physical Count Confirmed</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button type="button" class="btn-secondary" onclick="viewSessionDetails(<?= (int)$activeSession['id'] ?>)" style="padding: 8px 14px; font-weight: 600;">🔍 View Opening Count</button>
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
                        <p style="color: var(--saas-slate-500); max-width: 520px; margin: 0 auto 20px;">To start billing sales, open a shift session by completing physical denomination cash counting for your cash drawer float.</p>
                        <button type="button" class="header-btn" id="openShiftModalBtn" style="padding: 10px 24px; font-size: 15px;">
                            <span>+ Open Register Shift (Count Denominations)</span>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Past Shift Sessions Table -->
                <div class="section-card">
                    <div class="section-header" style="display: flex; justify-content: space-between; align-items: center;">
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
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pastSessions)): ?>
                                    <tr><td colspan="12" style="text-align: center; padding: 24px; color: #64748b;">No register shift sessions recorded.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pastSessions as $ps): ?>
                                        <tr>
                                            <td><strong style="font-family: monospace; color: var(--saas-primary);">#<?= $ps['id'] ?></strong></td>
                                            <td><?= e($ps['register_name']) ?><?php if (!empty($ps['outlet_name'])): ?> <span style="color:#64748b;">· <?= e($ps['outlet_name']) ?></span><?php endif; ?></td>
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
                                                    if ($ps['status'] === 'open') {
                                                        echo '<span style="color:#64748b; font-size:12px;">Active</span>';
                                                    } elseif ($diff === 0.00) {
                                                        echo '<span class="badge badge-success">Balanced (₹0)</span>';
                                                    } elseif ($diff > 0) {
                                                        echo '<span class="badge badge-success">+₹'.number_format($diff, 2).'</span>';
                                                    } else {
                                                        echo '<span class="badge badge-danger">-₹'.number_format(abs($diff), 2).'</span>';
                                                    }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge <?= $ps['status'] === 'open' ? 'badge-success' : 'badge-secondary' ?>">
                                                    <?= ucfirst($ps['status']) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right; white-space: nowrap;">
                                                <button type="button" class="btn-action-icon" title="View Denomination Breakdown" onclick="viewSessionDetails(<?= (int)$ps['id'] ?>)">
                                                    🔍 View
                                                </button>
                                                <a href="<?= asset('session-cash-print.php?id=' . (int)$ps['id'] . '&type=all') ?>" target="_blank" class="btn-action-icon" title="Print Cash Count Slip">
                                                    🖨️ Print
                                                </a>
                                                <?php if ($isAdmin): ?>
                                                    <button type="button" class="btn-action-icon" style="color: #b45309;" title="Admin Edit Denominations" onclick="openAdminEditModal(<?= (int)$ps['id'] ?>)">
                                                        ✏️ Edit
                                                    </button>
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

    <!-- 1. OPEN SHIFT MODAL -->
    <div class="modal-overlay" id="openShiftModal">
        <div class="modal-box" style="max-width: 620px;">
            <div class="modal-header">
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h3 class="modal-title" style="font-size: 18px; margin: 0;">Opening Cash Count</h3>
                        <span class="badge-pill-opening">Opening</span>
                    </div>
                    <p style="color: #64748b; font-size: 12px; margin: 2px 0 0 0;">Enter notes & coins count in cash drawer before opening shift.</p>
                </div>
                <button type="button" class="modal-close-btn" id="closeOpenModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('registers.php') ?>" id="openShiftForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="open_session">
                <div class="modal-body">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 11.5px; margin-bottom: 2px; font-weight: 600;">Register Counter</label>
                            <select name="register_id" class="form-control" style="font-size: 12.5px; height: 32px; padding: 4px 8px;">
                                <?php foreach ($registers as $reg): ?>
                                    <option value="<?= $reg['id'] ?>"><?= e($reg['name']) ?> (<?= e($reg['code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 11.5px; margin-bottom: 2px; font-weight: 600;">Store Outlet</label>
                            <?php if ($staffOutletId > 0): ?>
                                <input type="hidden" name="outlet_id" value="<?= $staffOutletId ?>">
                                <div class="form-control" style="background:#f8fafc; font-weight:700; font-size: 12.5px; height: 32px; padding: 5px 8px; line-height: 1.4;">
                                    <?php
                                        $staffStoreName = '';
                                        foreach ($registerOutlets as $outletRow) {
                                            if ((int) $outletRow['id'] === $staffOutletId) {
                                                $staffStoreName = (string) $outletRow['name'];
                                                break;
                                            }
                                        }
                                        echo e($staffStoreName !== '' ? $staffStoreName : 'Your store');
                                    ?>
                                </div>
                            <?php else: ?>
                                <select name="outlet_id" class="form-control" required style="font-size: 12.5px; height: 32px; padding: 4px 8px;">
                                    <option value="">Select store</option>
                                    <?php foreach ($registerOutlets as $outletRow): ?>
                                        <option value="<?= (int) $outletRow['id'] ?>"><?= e($outletRow['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Denomination Rows (2-Column Grid: ₹500 down to ₹1 all visible) -->
                    <div class="denom-card-list">
                        <?php foreach ($activeDenominations as $d): ?>
                            <div class="denom-row">
                                <div class="denom-info">
                                    <div class="denom-label"><?= e($d['label']) ?></div>
                                    <div class="denom-sub"><?= e($d['type'] ?: 'note') ?></div>
                                </div>
                                <div>
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        name="denominations[<?= $d['id'] ?>]"
                                        value="0"
                                        class="open-denom-input pill-count-input"
                                        data-val="<?= (float)$d['value'] ?>"
                                        oninput="calcOpeningTotal()"
                                    >
                                </div>
                                <div class="denom-line-total open-line-total">₹0</div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Live Calculation & Summary -->
                    <div class="denom-summary-box">
                        <div class="denom-summary-main">
                            <div class="denom-summary-title">Total Opening Cash</div>
                            <div class="denom-grand-total green" id="openGrandTotal">₹0</div>
                        </div>
                    </div>

                    <!-- Mandatory Physical Count Confirmation -->
                    <label class="confirm-count-card">
                        <input type="checkbox" name="confirm_count" value="1" id="openConfirmCheck" required>
                        <span>I confirm that I have physically counted the notes and coins in the drawer and verified the count above.</span>
                    </label>

                    <div class="form-group" style="margin-top: 8px; margin-bottom: 0;">
                        <input type="text" name="opening_notes" class="form-control" placeholder="Optional opening notes / memo..." style="font-size: 12px; height: 32px; padding: 4px 10px;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelOpenModal" style="padding: 6px 14px; font-size: 13px;">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0; padding: 7px 18px; font-size: 13px; font-weight: 700;">Confirm & Open Register</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. CLOSE SHIFT & Z-REPORT MODAL -->
    <?php if ($activeSession): ?>
    <div class="modal-overlay" id="closeShiftModal">
        <div class="modal-box" style="max-width: 620px;">
            <div class="modal-header">
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h3 class="modal-title" style="font-size: 18px; margin: 0;">Closing Cash Count</h3>
                        <span class="badge-pill-closing">Closing</span>
                    </div>
                    <p style="color: #64748b; font-size: 12px; margin: 2px 0 0 0;">Enter notes & coins counted in drawer to reconcile shift.</p>
                </div>
                <button type="button" class="modal-close-btn" id="closeCloseModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('registers.php') ?>" id="closeShiftForm" onsubmit="return validateClosingForm()">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="close_session">
                <input type="hidden" name="session_id" value="<?= $activeSession['id'] ?>">
                <div class="modal-body">
                    <!-- Drawer Metrics Summary (Compact 4-cell strip) -->
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; background: #f8fafc; padding: 8px 10px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 8px; font-size: 11.5px; text-align: center;">
                        <div>
                            <span style="color:#64748b; display:block; font-size: 10px;">Opening</span>
                            <strong>₹<?= number_format((float)$activeSession['opening_cash'], 2) ?></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; display:block; font-size: 10px;">Sales</span>
                            <strong style="color: #15803d;">+₹<?= number_format((float)$activeSession['total_cash_sales'], 2) ?></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; display:block; font-size: 10px;">In / Out</span>
                            <strong>+₹<?= number_format((float)$activeSession['cash_in'], 0) ?> / -₹<?= number_format((float)$activeSession['cash_out'], 0) ?></strong>
                        </div>
                        <div style="background: #eff6ff; border-radius: 6px; padding: 2px;">
                            <span style="color:#1e3a8a; display:block; font-size: 10px; font-weight: 700;">Expected</span>
                            <strong style="color: #1e3a8a; font-size: 12px;" id="closeExpectedCashVal" data-exp="<?= $expDrawer ?>">₹<?= number_format($expDrawer, 2) ?></strong>
                        </div>
                    </div>

                    <!-- Closing Denominations Count (2-Column Grid) -->
                    <div class="denom-card-list">
                        <?php foreach ($activeDenominations as $d): ?>
                            <div class="denom-row">
                                <div class="denom-info">
                                    <div class="denom-label"><?= e($d['label']) ?></div>
                                    <div class="denom-sub"><?= e($d['type'] ?: 'note') ?></div>
                                </div>
                                <div>
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        name="denominations[<?= $d['id'] ?>]"
                                        value="0"
                                        class="close-denom-input pill-count-input"
                                        data-val="<?= (float)$d['value'] ?>"
                                        oninput="calcClosingTotal()"
                                    >
                                </div>
                                <div class="denom-line-total close-line-total">₹0</div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Calculated Total Closing Cash Counted -->
                    <div class="denom-summary-box closing-summary">
                        <div class="denom-summary-main">
                            <div class="denom-summary-title">Total Closing Cash Counted</div>
                            <div class="denom-grand-total navy" id="closeGrandTotal">₹0</div>
                        </div>
                    </div>

                    <!-- Variance & Discrepancy Alert -->
                    <div id="closeVarianceBox" class="variance-card balanced">
                        Cash Drawer Balanced (Variance: ₹0.00)
                    </div>

                    <!-- Mandatory Discrepancy Reason if difference != 0 -->
                    <div id="discrepancyGroup" style="display: none; margin-top: 8px;">
                        <label class="form-label" style="font-weight: 700; color: #b91c1c; font-size: 11.5px; margin-bottom: 2px;">
                            Discrepancy Explanation / Reason <span style="color:#ef4444;">*</span>
                        </label>
                        <textarea
                            name="discrepancy_reason"
                            id="closeDiscrepancyReason"
                            rows="2"
                            class="form-control"
                            style="font-size: 12px; padding: 6px 8px;"
                            placeholder="Mandatory: Explain reason for cash shortage or excess..."
                        ></textarea>
                    </div>

                    <!-- Mandatory Physical Count Confirmation -->
                    <label class="confirm-count-card">
                        <input type="checkbox" name="confirm_count" value="1" id="closeConfirmCheck" required>
                        <span>I confirm that I have physically counted all notes and coins in the drawer and verified this closing count.</span>
                    </label>

                    <div class="form-group" style="margin-top: 8px; margin-bottom: 0;">
                        <input type="text" name="closing_notes" class="form-control" placeholder="Optional closing shift notes..." style="font-size: 12px; height: 32px; padding: 4px 10px;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelCloseModal" style="padding: 6px 14px; font-size: 13px;">Cancel</button>
                    <button type="submit" class="header-btn" style="background: #b91c1c; border-color: #b91c1c; padding: 7px 18px; font-size: 13px; font-weight: 700;">Confirm & Close Shift</button>
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

    <!-- 4. VIEW SESSION DENOMINATIONS MODAL (Cashier & Admin) -->
    <div class="modal-overlay" id="viewCountModal">
        <div class="modal-box" style="max-width: 600px;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" id="viewModalTitle">Session Cash Count Breakdown</h3>
                    <p style="color: #64748b; font-size: 13px; margin-top: 3px;" id="viewModalSubtitle">Detailed breakdown of opening and closing cash counts.</p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeViewCountModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody" style="max-height: 480px; overflow-y: auto;">
                <div style="text-align: center; padding: 30px; color: #64748b;">Loading session details...</div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: space-between;">
                <div>
                    <a href="#" id="viewModalPrintBtn" target="_blank" class="btn-action-icon" style="padding: 8px 16px; font-size: 13px;">
                        🖨️ Print Cash Count Slip
                    </a>
                </div>
                <button type="button" class="btn-secondary" onclick="closeViewCountModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- 5. ADMIN EDIT DENOMINATIONS MODAL (Requirement 10: Admin Approval & Audit Log) -->
    <?php if ($isAdmin): ?>
    <div class="modal-overlay" id="adminEditModal">
        <div class="modal-box" style="max-width: 620px;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" style="color: #b45309; font-size: 18px; margin: 0;">✏️ Admin Cash Count Revision</h3>
                    <p style="color: #64748b; font-size: 12px; margin: 2px 0 0 0;">Modify recorded denominations with mandatory audit trail explanation.</p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeAdminEditModal()">&times;</button>
            </div>
            <form method="POST" action="<?= asset('registers.php') ?>" id="adminEditForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="admin_edit_count">
                <input type="hidden" name="session_id" id="adminEditSessionId" value="0">
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label">Count Entry to Revise</label>
                        <select name="entry_type" id="adminEditEntryType" class="form-control" onchange="renderAdminEditInputs()">
                            <option value="opening">Opening Cash Float</option>
                            <option value="closing">Closing Cash Count</option>
                        </select>
                    </div>

                    <div class="denom-card-list" id="adminEditDenomList">
                        <!-- Loaded dynamically -->
                    </div>

                    <div class="denom-summary-box">
                        <div class="denom-summary-main">
                            <div class="denom-summary-title">Revised Total Cash</div>
                            <div class="denom-grand-total green" id="adminEditGrandTotal">₹0</div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 16px;">
                        <label class="form-label" style="font-weight: 700; color: #b45309;">
                            Audit Reason / Correction Note <span style="color:#ef4444;">*</span>
                        </label>
                        <textarea
                            name="audit_reason"
                            rows="2"
                            required
                            class="form-control"
                            placeholder="Mandatory: Specify why this count is being modified (e.g. Cashier miscounted ₹50 notes, approved by Store Manager)..."
                        ></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeAdminEditModal()">Cancel</button>
                    <button type="submit" class="header-btn" style="background: #b45309; border-color: #b45309;">Save Revision & Log Audit</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 6. ADMIN MANAGE DENOMINATIONS MODAL (Configurable by Admin) -->
    <div class="modal-overlay" id="manageDenomsModal">
        <div class="modal-box" style="max-width: 620px;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Manage Currency Denominations</h3>
                    <p style="color: #64748b; font-size: 13px; margin-top: 3px;">Configure available notes and coins for physical cash counting.</p>
                </div>
                <button type="button" class="modal-close-btn" id="closeManageDenomsModal">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 500px; overflow-y: auto;">
                <!-- Add New Denomination -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                    <strong style="font-size: 13.5px; color: #1e293b; display: block; margin-bottom: 8px;">+ Add New Currency Denomination</strong>
                    <form method="POST" action="<?= asset('registers.php') ?>" style="display: grid; grid-template-columns: 2fr 2fr 2fr 1fr auto; gap: 8px; align-items: end;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_denomination">
                        <div>
                            <label class="form-label" style="font-size: 11px;">Label</label>
                            <input type="text" name="label" placeholder="e.g. ₹2000" required class="form-control" style="font-size: 12px; padding: 6px 10px;">
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 11px;">Value (₹)</label>
                            <input type="number" step="0.01" name="value" placeholder="2000" required class="form-control" style="font-size: 12px; padding: 6px 10px;">
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 11px;">Type</label>
                            <select name="type" class="form-control" style="font-size: 12px; padding: 6px 8px;">
                                <option value="note">Note</option>
                                <option value="coin">Coin</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 11px;">Sort</label>
                            <input type="number" name="sort_order" value="10" class="form-control" style="font-size: 12px; padding: 6px 8px;">
                        </div>
                        <div>
                            <button type="submit" class="btn-primary" style="padding: 7px 14px; font-size: 12px; height: 34px;">Add</button>
                        </div>
                    </form>
                </div>

                <!-- Existing Denominations Table -->
                <table class="saas-table" style="font-size: 13px;">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Value</th>
                            <th>Type</th>
                            <th>Sort</th>
                            <th>Status</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allDenominations as $ad): ?>
                            <tr>
                                <td><strong><?= e($ad['label']) ?></strong></td>
                                <td>₹<?= number_format((float)$ad['value'], 2) ?></td>
                                <td><span style="text-transform: capitalize; font-size: 12px;"><?= e($ad['type']) ?></span></td>
                                <td><?= (int)$ad['sort_order'] ?></td>
                                <td>
                                    <form method="POST" action="<?= asset('registers.php') ?>" style="display: inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_denomination">
                                        <input type="hidden" name="id" value="<?= $ad['id'] ?>">
                                        <input type="hidden" name="is_active" value="<?= $ad['is_active'] ? '0' : '1' ?>">
                                        <button type="submit" class="badge <?= $ad['is_active'] ? 'badge-success' : 'badge-secondary' ?>" style="border: 0; cursor: pointer;">
                                            <?= $ad['is_active'] ? 'Active' : 'Disabled' ?>
                                        </button>
                                    </form>
                                </td>
                                <td style="text-align: right;">
                                    <form method="POST" action="<?= asset('registers.php') ?>" style="display: inline;" onsubmit="return confirm('Delete this denomination?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_denomination">
                                        <input type="hidden" name="id" value="<?= $ad['id'] ?>">
                                        <button type="submit" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 12px; font-weight: 600;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelManageDenomsModal">Close</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        // Currency formatting helper
        function formatINR(val) {
            return '₹' + Number(val).toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }

        // Live calculation for Opening Session modal
        function calcOpeningTotal() {
            let total = 0;
            const rows = document.querySelectorAll('#openShiftModal .denom-row');
            rows.forEach(row => {
                const input = row.querySelector('.open-denom-input');
                const lineTotalEl = row.querySelector('.open-line-total');
                if (input && lineTotalEl) {
                    const unitVal = parseFloat(input.dataset.val) || 0;
                    const qty = parseInt(input.value) || 0;
                    const lineTotal = unitVal * Math.max(0, qty);
                    lineTotalEl.textContent = formatINR(lineTotal);
                    total += lineTotal;
                }
            });
            const grandTotalEl = document.getElementById('openGrandTotal');
            if (grandTotalEl) {
                grandTotalEl.textContent = formatINR(total);
            }
        }

        // Live calculation for Closing Session modal
        function calcClosingTotal() {
            let total = 0;
            const rows = document.querySelectorAll('#closeShiftModal .denom-row');
            rows.forEach(row => {
                const input = row.querySelector('.close-denom-input');
                const lineTotalEl = row.querySelector('.close-line-total');
                if (input && lineTotalEl) {
                    const unitVal = parseFloat(input.dataset.val) || 0;
                    const qty = parseInt(input.value) || 0;
                    const lineTotal = unitVal * Math.max(0, qty);
                    lineTotalEl.textContent = formatINR(lineTotal);
                    total += lineTotal;
                }
            });

            const grandTotalEl = document.getElementById('closeGrandTotal');
            if (grandTotalEl) {
                grandTotalEl.textContent = formatINR(total);
            }

            const expEl = document.getElementById('closeExpectedCashVal');
            const expVal = expEl ? parseFloat(expEl.dataset.exp) || 0 : 0;
            const diff = Math.round((total - expVal) * 100) / 100;

            const varBox = document.getElementById('closeVarianceBox');
            const discrepancyGroup = document.getElementById('discrepancyGroup');
            const reasonInput = document.getElementById('closeDiscrepancyReason');

            if (varBox) {
                if (Math.abs(diff) < 0.01) {
                    varBox.className = 'variance-card balanced';
                    varBox.textContent = '✓ Cash Drawer Balanced (Variance: ₹0.00)';
                    if (discrepancyGroup) discrepancyGroup.style.display = 'none';
                    if (reasonInput) reasonInput.removeAttribute('required');
                } else if (diff < 0) {
                    varBox.className = 'variance-card shortage';
                    varBox.textContent = '⚠️ Cash Shortage: -' + formatINR(Math.abs(diff)) + ' (Counted is less than Expected)';
                    if (discrepancyGroup) discrepancyGroup.style.display = 'block';
                    if (reasonInput) reasonInput.setAttribute('required', 'required');
                } else {
                    varBox.className = 'variance-card excess';
                    varBox.textContent = 'ℹ️ Cash Excess: +' + formatINR(diff) + ' (Counted exceeds Expected)';
                    if (discrepancyGroup) discrepancyGroup.style.display = 'block';
                    if (reasonInput) reasonInput.setAttribute('required', 'required');
                }
            }
        }

        // Validate Closing Form on Submit
        function validateClosingForm() {
            const expEl = document.getElementById('closeExpectedCashVal');
            const expVal = expEl ? parseFloat(expEl.dataset.exp) || 0 : 0;

            let total = 0;
            document.querySelectorAll('#closeShiftModal .close-denom-input').forEach(input => {
                const unitVal = parseFloat(input.dataset.val) || 0;
                const qty = parseInt(input.value) || 0;
                total += unitVal * Math.max(0, qty);
            });

            const diff = Math.round((total - expVal) * 100) / 100;
            if (Math.abs(diff) >= 0.01) {
                const reasonInput = document.getElementById('closeDiscrepancyReason');
                if (!reasonInput || !reasonInput.value.trim()) {
                    alert('A cash difference of ' + (diff < 0 ? '-' : '+') + formatINR(Math.abs(diff)) + ' was detected. You must enter a reason explaining this discrepancy before closing.');
                    if (reasonInput) reasonInput.focus();
                    return false;
                }
            }

            const confirmCheck = document.getElementById('closeConfirmCheck');
            if (confirmCheck && !confirmCheck.checked) {
                alert('Please check the confirmation box verifying that you physically counted the cash drawer.');
                confirmCheck.focus();
                return false;
            }
            return true;
        }

        // Modal triggers
        const openShiftModal = document.getElementById('openShiftModal');
        const openShiftBtn = document.getElementById('openShiftModalBtn');
        if (openShiftBtn) openShiftBtn.addEventListener('click', () => {
            calcOpeningTotal();
            openShiftModal.classList.add('open');
        });
        const closeOpenModal = document.getElementById('closeOpenModal');
        if (closeOpenModal) closeOpenModal.addEventListener('click', () => openShiftModal.classList.remove('open'));
        const cancelOpenModal = document.getElementById('cancelOpenModal');
        if (cancelOpenModal) cancelOpenModal.addEventListener('click', () => openShiftModal.classList.remove('open'));

        const closeShiftModal = document.getElementById('closeShiftModal');
        const closeShiftBtn = document.getElementById('closeShiftBtn');
        if (closeShiftBtn) closeShiftBtn.addEventListener('click', () => {
            calcClosingTotal();
            closeShiftModal.classList.add('open');
        });
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

        // Manage Denominations Modal
        const manageDenomsModal = document.getElementById('manageDenomsModal');
        const openManageDenomsBtn = document.getElementById('openManageDenomsBtn');
        if (openManageDenomsBtn) openManageDenomsBtn.addEventListener('click', () => manageDenomsModal.classList.add('open'));
        const closeManageDenomsModal = document.getElementById('closeManageDenomsModal');
        if (closeManageDenomsModal) closeManageDenomsModal.addEventListener('click', () => manageDenomsModal.classList.remove('open'));
        const cancelManageDenomsModal = document.getElementById('cancelManageDenomsModal');
        if (cancelManageDenomsModal) cancelManageDenomsModal.addEventListener('click', () => manageDenomsModal.classList.remove('open'));

        // View Session Details & Denomination Breakdown Modal
        let activeLoadedSessionData = null;
        function viewSessionDetails(sessionId) {
            const modal = document.getElementById('viewCountModal');
            const body = document.getElementById('viewModalBody');
            const title = document.getElementById('viewModalTitle');
            const printBtn = document.getElementById('viewModalPrintBtn');

            title.textContent = 'Session #' + sessionId + ' Cash Count Breakdown';
            printBtn.href = 'session-cash-print.php?id=' + sessionId + '&type=all';
            body.innerHTML = '<div style="text-align: center; padding: 30px; color: #64748b;">Loading session details...</div>';
            modal.classList.add('open');

            fetch('registers.php?ajax_session_details=' + sessionId)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        body.innerHTML = '<div style="color: #ef4444; padding: 20px;">' + (data.error || 'Failed to load details.') + '</div>';
                        return;
                    }
                    activeLoadedSessionData = data;
                    renderViewCountModal(data);
                })
                .catch(err => {
                    body.innerHTML = '<div style="color: #ef4444; padding: 20px;">Error connecting to server.</div>';
                });
        }

        function closeViewCountModal() {
            document.getElementById('viewCountModal').classList.remove('open');
        }

        function renderViewCountModal(data) {
            const sess = data.session;
            const denoms = data.denominations;
            const audits = data.audit_logs || [];
            const body = document.getElementById('viewModalBody');

            let html = `
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 13px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px;">
                        <div><span style="color:#64748b;">Register:</span> <strong>${escapeHtml(sess.register_name)}</strong></div>
                        <div><span style="color:#64748b;">Cashier:</span> <strong>${escapeHtml(sess.cashier_name)}</strong></div>
                        <div><span style="color:#64748b;">Opened:</span> <strong>${sess.opened_at}</strong></div>
                        <div><span style="color:#64748b;">Closed:</span> <strong>${sess.closed_at ? sess.closed_at : '<span style="color:#047857;">Active</span>'}</strong></div>
                    </div>
                </div>
            `;

            // Opening Denominations Table
            html += `
                <div style="display: flex; justify-content: space-between; align-items: center; margin: 12px 0 6px 0;">
                    <strong style="font-size: 14px; color: #1e293b;">1. Opening Cash Float Breakdown</strong>
                    <span style="font-weight: 800; color: #15803d; font-size: 15px;">${formatINR(sess.opening_cash)}</span>
                </div>
            `;
            if (denoms.opening && denoms.opening.length > 0) {
                html += `
                    <table class="saas-table" style="font-size: 12.5px; margin-bottom: 10px;">
                        <thead>
                            <tr>
                                <th>Denomination</th>
                                <th style="text-align: center;">Notes / Coins Count</th>
                                <th style="text-align: right;">Total Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                denoms.opening.forEach(item => {
                    if (parseInt(item.quantity) > 0 || denoms.opening.length <= 9) {
                        html += `
                            <tr>
                                <td><strong>${escapeHtml(item.label)}</strong> <span style="font-size: 11px; color: #64748b;">(${escapeHtml(item.type)})</span></td>
                                <td style="text-align: center;">${item.quantity}</td>
                                <td style="text-align: right; font-weight: 600;">${formatINR(item.total_amount)}</td>
                            </tr>
                        `;
                    }
                });
                html += `
                        <tr style="font-weight: 800; background: #f8fafc;">
                            <td colspan="2">TOTAL OPENING FLOAT</td>
                            <td style="text-align: right; color: #15803d;">${formatINR(sess.opening_cash)}</td>
                        </tr>
                    </tbody>
                </table>
                `;
            } else {
                html += `<div style="color: #64748b; font-size: 12.5px; margin-bottom: 10px;">Total Opening Float: <strong>${formatINR(sess.opening_cash)}</strong> (Legacy count)</div>`;
            }

            if (sess.opening_count_confirmed == 1) {
                html += `<div style="font-size: 12px; color: #15803d; font-weight: 600; margin-bottom: 14px;">✓ Physical Opening Count Confirmed by Cashier</div>`;
            }

            // Closing Denominations Table
            if (sess.status === 'closed' || (denoms.closing && denoms.closing.length > 0)) {
                html += `
                    <div style="display: flex; justify-content: space-between; align-items: center; margin: 16px 0 6px 0; border-top: 1px dashed #cbd5e1; padding-top: 12px;">
                        <strong style="font-size: 14px; color: #1e293b;">2. Closing Cash Count Breakdown</strong>
                        <span style="font-weight: 800; color: #1e3a8a; font-size: 15px;">${formatINR(sess.closing_cash_actual)}</span>
                    </div>
                `;
                if (denoms.closing && denoms.closing.length > 0) {
                    html += `
                        <table class="saas-table" style="font-size: 12.5px; margin-bottom: 10px;">
                            <thead>
                                <tr>
                                    <th>Denomination</th>
                                    <th style="text-align: center;">Notes / Coins Count</th>
                                    <th style="text-align: right;">Total Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    denoms.closing.forEach(item => {
                        if (parseInt(item.quantity) > 0 || denoms.closing.length <= 9) {
                            html += `
                                <tr>
                                    <td><strong>${escapeHtml(item.label)}</strong> <span style="font-size: 11px; color: #64748b;">(${escapeHtml(item.type)})</span></td>
                                    <td style="text-align: center;">${item.quantity}</td>
                                    <td style="text-align: right; font-weight: 600;">${formatINR(item.total_amount)}</td>
                                </tr>
                            `;
                        }
                    });
                    html += `
                            <tr style="font-weight: 800; background: #f8fafc;">
                                <td colspan="2">TOTAL COUNTED CLOSING CASH</td>
                                <td style="text-align: right; color: #1e3a8a;">${formatINR(sess.closing_cash_actual)}</td>
                            </tr>
                        </tbody>
                    </table>
                    `;
                }

                // Reconciliation Box
                const diff = parseFloat(sess.cash_difference) || 0;
                let varClass = 'balanced';
                let varMsg = '✓ Balanced (Expected matches Counted: ₹0.00)';
                if (diff < 0) {
                    varClass = 'shortage';
                    varMsg = '⚠️ Shortage: -' + formatINR(Math.abs(diff)) + ' (Counted is less than Expected ' + formatINR(sess.closing_cash_expected) + ')';
                } else if (diff > 0) {
                    varClass = 'excess';
                    varMsg = 'ℹ️ Excess: +' + formatINR(diff) + ' (Counted exceeds Expected ' + formatINR(sess.closing_cash_expected) + ')';
                }

                html += `<div class="variance-card ${varClass}" style="margin: 8px 0;">${varMsg}</div>`;

                if (sess.discrepancy_reason) {
                    html += `
                        <div style="background: #fff; border-left: 3px solid #ef4444; padding: 8px 12px; margin-top: 6px; font-size: 12.5px;">
                            <strong>Discrepancy Explanation:</strong><br>${escapeHtml(sess.discrepancy_reason)}
                        </div>
                    `;
                }
                if (sess.closing_count_confirmed == 1) {
                    html += `<div style="font-size: 12px; color: #15803d; font-weight: 600; margin-top: 6px;">✓ Physical Closing Count Confirmed by Cashier</div>`;
                }
            }

            // Audit Logs
            if (audits.length > 0) {
                html += `
                    <div style="margin-top: 16px; border-top: 1px solid #e2e8f0; padding-top: 10px;">
                        <strong style="font-size: 13px; color: #b45309;">Admin Audit Revisions:</strong>
                        <div style="margin-top: 6px; font-size: 12px; color: #475569;">
                `;
                audits.forEach(a => {
                    html += `
                        <div style="padding: 6px 0; border-bottom: 1px solid #f1f5f9;">
                            <strong>${escapeHtml(a.admin_name || 'Admin')}</strong> revised ${escapeHtml(a.entry_type)} count on ${a.created_at}:<br>
                            <em>Reason: "${escapeHtml(a.reason)}"</em>
                        </div>
                    `;
                });
                html += `</div></div>`;
            }

            body.innerHTML = html;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }

        // Admin Edit Denominations Modal
        function openAdminEditModal(sessionId) {
            document.getElementById('adminEditSessionId').value = sessionId;
            const modal = document.getElementById('adminEditModal');
            modal.classList.add('open');

            fetch('registers.php?ajax_session_details=' + sessionId)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        activeLoadedSessionData = data;
                        renderAdminEditInputs();
                    }
                });
        }

        function closeAdminEditModal() {
            document.getElementById('adminEditModal').classList.remove('open');
        }

        function renderAdminEditInputs() {
            if (!activeLoadedSessionData) return;
            const entryType = document.getElementById('adminEditEntryType').value;
            const container = document.getElementById('adminEditDenomList');
            const activeDenoms = activeLoadedSessionData.active_denominations || [];
            const recorded = activeLoadedSessionData.denominations[entryType] || [];

            const qtyMap = {};
            recorded.forEach(item => {
                qtyMap[item.denomination_id || item.denomination_value] = parseInt(item.quantity) || 0;
            });

            let html = '';
            activeDenoms.forEach(d => {
                const currentQty = qtyMap[d.id] !== undefined ? qtyMap[d.id] : (qtyMap[d.value] || 0);
                html += `
                    <div class="denom-row">
                        <div class="denom-info">
                            <div class="denom-label">${escapeHtml(d.label)}</div>
                            <div class="denom-sub">${escapeHtml(d.type || 'note')}</div>
                        </div>
                        <div>
                            <input
                                type="number"
                                min="0"
                                step="1"
                                name="denominations[${d.id}]"
                                value="${currentQty}"
                                class="admin-denom-input pill-count-input"
                                data-val="${parseFloat(d.value) || 0}"
                                oninput="calcAdminEditTotal()"
                            >
                        </div>
                        <div class="denom-line-total admin-line-total">₹0</div>
                    </div>
                `;
            });
            container.innerHTML = html;
            calcAdminEditTotal();
        }

        function calcAdminEditTotal() {
            let total = 0;
            const rows = document.querySelectorAll('#adminEditModal .denom-row');
            rows.forEach(row => {
                const input = row.querySelector('.admin-denom-input');
                const lineTotalEl = row.querySelector('.admin-line-total');
                if (input && lineTotalEl) {
                    const unitVal = parseFloat(input.dataset.val) || 0;
                    const qty = parseInt(input.value) || 0;
                    const lineTotal = unitVal * Math.max(0, qty);
                    lineTotalEl.textContent = formatINR(lineTotal);
                    total += lineTotal;
                }
            });
            const grandTotalEl = document.getElementById('adminEditGrandTotal');
            if (grandTotalEl) {
                grandTotalEl.textContent = formatINR(total);
            }
        }
    </script>
</body>
</html>

<?php
/**
 * OminiFlow POS - Printable Cash Count Report (Opening / Closing Sessions)
 * Supports 80mm Thermal Receipt Printers and Standard A4/Letter Paper.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/registers_db.php';
require_once __DIR__ . '/includes/business_profile_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

$sessionId = (int) ($_GET['id'] ?? 0);
if ($sessionId <= 0) {
    die('Invalid session ID.');
}

$session = get_register_session_by_id($sessionId);
if (!$session) {
    die('Register session not found.');
}

$reportType = (string) ($_GET['type'] ?? 'all'); // 'opening', 'closing', or 'all'
if (!in_array($reportType, ['opening', 'closing', 'all'], true)) {
    $reportType = 'all';
}

$denominations = get_session_denominations($sessionId);
$auditLogs = get_session_audit_logs($sessionId);
$bizProfile = get_business_profile();
$bizName = $bizProfile['business_name'] ?? 'OminiFlow Store';
$bizAddress = $bizProfile['address'] ?? '';
$bizPhone = $bizProfile['phone'] ?? '';
$bizGstin = $bizProfile['gstin'] ?? '';

$openingItems = $denominations['opening'] ?? [];
$closingItems = $denominations['closing'] ?? [];

$openingTotal = (float) $session['opening_cash'];
$cashSales = (float) $session['total_cash_sales'];
$cashIn = (float) $session['cash_in'];
$cashOut = (float) $session['cash_out'];
$refunds = (float) $session['total_refunds'];
$expectedCash = (float) $session['closing_cash_expected'];
if ($expectedCash <= 0 && $session['status'] === 'open') {
    $expectedCash = $openingTotal + $cashSales + $cashIn - $cashOut - $refunds;
}
$actualCash = (float) $session['closing_cash_actual'];
$difference = (float) $session['cash_difference'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Count Slip - Session #<?= (int)$session['id'] ?> - <?= e($bizName) ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, monospace;
            background: #f1f5f9;
            color: #0f172a;
            padding: 24px 12px;
            font-size: 13px;
            line-height: 1.45;
        }
        .print-container {
            max-width: 420px;
            margin: 0 auto;
            background: #fff;
            padding: 24px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        .no-print-bar {
            max-width: 420px;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .btn-print {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-print:hover { background: #1d4ed8; }
        .btn-close {
            background: #fff;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 13px;
        }
        .btn-close:hover { background: #f8fafc; }
        .biz-header {
            text-align: center;
            padding-bottom: 12px;
            border-bottom: 1.5px dashed #cbd5e1;
            margin-bottom: 12px;
        }
        .biz-title {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 3px;
        }
        .biz-sub {
            font-size: 12px;
            color: #64748b;
        }
        .report-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-opening { background: #dcfce7; color: #15803d; }
        .badge-closing { background: #fee2e2; color: #b91c1c; }
        .badge-all { background: #eff6ff; color: #1d4ed8; }
        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 12px;
            font-size: 12px;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .meta-label { color: #64748b; font-weight: 500; }
        .meta-val { font-weight: 700; color: #0f172a; }
        .section-heading {
            font-size: 13px;
            font-weight: 800;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 14px 0 8px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .denom-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
            margin-bottom: 10px;
        }
        .denom-table th {
            text-align: left;
            padding: 6px 4px;
            border-bottom: 1px solid #cbd5e1;
            color: #475569;
            font-size: 11px;
            text-transform: uppercase;
        }
        .denom-table td {
            padding: 5px 4px;
            border-bottom: 1px solid #f1f5f9;
        }
        .denom-table .num { text-align: right; }
        .denom-table .center { text-align: center; }
        .total-row {
            font-weight: 800;
            font-size: 14px;
            border-top: 1.5px solid #0f172a !important;
            border-bottom: 1.5px solid #0f172a !important;
        }
        .reconcile-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            margin: 12px 0;
            font-size: 12.5px;
        }
        .reconcile-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }
        .reconcile-row:last-child { margin-bottom: 0; }
        .diff-box {
            padding: 8px 10px;
            border-radius: 6px;
            margin-top: 8px;
            font-weight: 700;
            text-align: center;
            font-size: 13px;
        }
        .diff-balanced { background: #dcfce7; color: #166534; }
        .diff-shortage { background: #fee2e2; color: #991b1b; }
        .diff-excess { background: #fef3c7; color: #92400e; }
        .reason-box {
            background: #fff;
            border-left: 3px solid #ef4444;
            padding: 8px 10px;
            margin-top: 8px;
            font-size: 12px;
            color: #334155;
        }
        .confirmation-badge {
            font-size: 11px;
            color: #166534;
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 6px;
            font-weight: 600;
        }
        .signatures {
            margin-top: 30px;
            padding-top: 14px;
            border-top: 1px dashed #cbd5e1;
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #475569;
        }
        .sig-block {
            text-align: center;
            width: 45%;
        }
        .sig-line {
            border-bottom: 1px solid #94a3b8;
            height: 35px;
            margin-bottom: 6px;
        }
        .audit-trail {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 11px;
            color: #64748b;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .no-print-bar { display: none !important; }
            .print-container {
                box-shadow: none;
                border: none;
                padding: 4px 6px;
                max-width: 100%;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="no-print-bar">
        <button type="button" class="btn-print" onclick="window.print()">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Print Slip
        </button>
        <button type="button" class="btn-close" onclick="window.close()">Close Window</button>
    </div>

    <div class="print-container">
        <!-- Business Header -->
        <div class="biz-header">
            <div class="biz-title"><?= e($bizName) ?></div>
            <?php if (!empty($session['outlet_name'])): ?>
                <div style="font-weight: 700; color: #1e3a8a; font-size: 13px;"><?= e($session['outlet_name']) ?></div>
            <?php endif; ?>
            <?php if (!empty($bizAddress)): ?>
                <div class="biz-sub"><?= e($bizAddress) ?></div>
            <?php endif; ?>
            <?php if (!empty($bizPhone)): ?>
                <div class="biz-sub">Tel: <?= e($bizPhone) ?><?php if (!empty($bizGstin)): ?> · GSTIN: <?= e($bizGstin) ?><?php endif; ?></div>
            <?php endif; ?>
            <div>
                <?php if ($reportType === 'opening'): ?>
                    <span class="report-badge badge-opening">Cash Drawer Opening Slip</span>
                <?php elseif ($reportType === 'closing'): ?>
                    <span class="report-badge badge-closing">Shift Closing & Z-Report</span>
                <?php else: ?>
                    <span class="report-badge badge-all">Register Shift Cash Count Slip</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Shift Metadata -->
        <div class="meta-grid">
            <div><span class="meta-label">Session ID:</span> <span class="meta-val">#SESS-<?= (int)$session['id'] ?></span></div>
            <div><span class="meta-label">Register:</span> <span class="meta-val"><?= e($session['register_name']) ?></span></div>
            <div><span class="meta-label">Cashier:</span> <span class="meta-val"><?= e($session['cashier_name']) ?></span></div>
            <div><span class="meta-label">Status:</span> <span class="meta-val"><?= strtoupper(e($session['status'])) ?></span></div>
            <div style="grid-column: span 2;"><span class="meta-label">Opened:</span> <span class="meta-val"><?= date('d M Y, h:i A', strtotime($session['opened_at'])) ?></span></div>
            <?php if ($session['closed_at']): ?>
                <div style="grid-column: span 2;"><span class="meta-label">Closed:</span> <span class="meta-val"><?= date('d M Y, h:i A', strtotime($session['closed_at'])) ?></span></div>
            <?php endif; ?>
        </div>

        <!-- 1. OPENING CASH BREAKDOWN -->
        <?php if ($reportType === 'opening' || $reportType === 'all'): ?>
            <div class="section-heading">
                <span>Opening Cash Count (Float)</span>
                <span style="color: #15803d;">₹<?= number_format($openingTotal, 2) ?></span>
            </div>
            <?php if (!empty($openingItems)): ?>
                <table class="denom-table">
                    <thead>
                        <tr>
                            <th>Denomination</th>
                            <th class="center">Count</th>
                            <th class="num">Amount (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($openingItems as $item): ?>
                            <?php if ((int)$item['quantity'] > 0 || count($openingItems) <= 10): ?>
                            <tr>
                                <td><strong><?= e($item['label']) ?></strong> <span style="font-size: 11px; color: #64748b;">(<?= e($item['type']) ?>)</span></td>
                                <td class="center"><?= (int)$item['quantity'] ?></td>
                                <td class="num">₹<?= number_format((float)$item['total_amount'], 2) ?></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="2">TOTAL OPENING FLOAT</td>
                            <td class="num">₹<?= number_format($openingTotal, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="padding: 6px 0 10px 0; color: #64748b; font-size: 12px;">Opening Float: <strong>₹<?= number_format($openingTotal, 2) ?></strong></div>
            <?php endif; ?>

            <?php if (!empty($session['opening_count_confirmed'])): ?>
                <div class="confirmation-badge">
                    <span>✓ Physical opening cash count verified by cashier</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- 2. SHIFT SALES & RECONCILIATION SUMMARY (For Closing or All) -->
        <?php if ($reportType === 'closing' || ($reportType === 'all' && $session['status'] === 'closed')): ?>
            <div class="section-heading" style="margin-top: 18px;">
                <span>Shift Sales & Reconciliation</span>
            </div>
            <div class="reconcile-box">
                <div class="reconcile-row">
                    <span>Opening Cash Float:</span>
                    <strong>₹<?= number_format($openingTotal, 2) ?></strong>
                </div>
                <div class="reconcile-row">
                    <span>Cash Sales:</span>
                    <strong style="color: #047857;">+₹<?= number_format($cashSales, 2) ?></strong>
                </div>
                <div class="reconcile-row">
                    <span>UPI / Card Sales:</span>
                    <span>₹<?= number_format((float)$session['total_card_sales'] + (float)$session['total_upi_sales'], 2) ?></span>
                </div>
                <div class="reconcile-row">
                    <span>Cash In / Out:</span>
                    <span>+₹<?= number_format($cashIn, 2) ?> / -₹<?= number_format($cashOut, 2) ?></span>
                </div>
                <?php if ($refunds > 0): ?>
                <div class="reconcile-row">
                    <span>Cash Refunds:</span>
                    <span style="color: #b91c1c;">-₹<?= number_format($refunds, 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="reconcile-row" style="border-top: 1px dashed #cbd5e1; padding-top: 5px; margin-top: 5px; font-weight: 800; color: #1e3a8a;">
                    <span>Expected Drawer Cash:</span>
                    <span>₹<?= number_format($expectedCash, 2) ?></span>
                </div>
            </div>

            <!-- CLOSING CASH BREAKDOWN -->
            <div class="section-heading">
                <span>Closing Cash Count</span>
                <span style="color: #1e3a8a;">₹<?= number_format($actualCash, 2) ?></span>
            </div>
            <?php if (!empty($closingItems)): ?>
                <table class="denom-table">
                    <thead>
                        <tr>
                            <th>Denomination</th>
                            <th class="center">Count</th>
                            <th class="num">Amount (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($closingItems as $item): ?>
                            <?php if ((int)$item['quantity'] > 0 || count($closingItems) <= 10): ?>
                            <tr>
                                <td><strong><?= e($item['label']) ?></strong> <span style="font-size: 11px; color: #64748b;">(<?= e($item['type']) ?>)</span></td>
                                <td class="center"><?= (int)$item['quantity'] ?></td>
                                <td class="num">₹<?= number_format((float)$item['total_amount'], 2) ?></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="2">TOTAL COUNTED CASH</td>
                            <td class="num">₹<?= number_format($actualCash, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="padding: 6px 0 10px 0; color: #64748b; font-size: 12px;">Counted Closing Cash: <strong>₹<?= number_format($actualCash, 2) ?></strong></div>
            <?php endif; ?>

            <?php if (!empty($session['closing_count_confirmed'])): ?>
                <div class="confirmation-badge">
                    <span>✓ Physical closing cash count verified by cashier</span>
                </div>
            <?php endif; ?>

            <!-- DIFFERENCE & VARIANCE -->
            <?php if (abs($difference) < 0.01): ?>
                <div class="diff-box diff-balanced">
                    ✓ Balanced: Counted Cash matches Expected Cash (₹0.00)
                </div>
            <?php elseif ($difference < 0): ?>
                <div class="diff-box diff-shortage">
                    ⚠️ Shortage: -₹<?= number_format(abs($difference), 2) ?> (Counted is less than Expected)
                </div>
            <?php else: ?>
                <div class="diff-box diff-excess">
                    ℹ️ Excess: +₹<?= number_format($difference, 2) ?> (Counted exceeds Expected)
                </div>
            <?php endif; ?>

            <?php if (!empty($session['discrepancy_reason'])): ?>
                <div class="reason-box">
                    <strong>Discrepancy Explanation:</strong><br>
                    <?= nl2br(e($session['discrepancy_reason'])) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($session['closing_notes'])): ?>
                <div style="font-size: 12px; color: #64748b; margin-top: 6px;">
                    <strong>Closing Notes:</strong> <?= e($session['closing_notes']) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Audit Trail (If revised by admin) -->
        <?php if (!empty($auditLogs)): ?>
            <div class="audit-trail">
                <strong style="color: #475569;">Admin Adjustments Audit Log:</strong>
                <?php foreach ($auditLogs as $log): ?>
                    <div style="margin-top: 4px;">
                        • <?= date('d M Y, h:i A', strtotime($log['created_at'])) ?> by <?= e($log['admin_name'] ?? 'Admin') ?> (<?= ucfirst($log['entry_type']) ?> count): <?= e($log['reason']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Signatures Section -->
        <div class="signatures">
            <div class="sig-block">
                <div class="sig-line"></div>
                <div>Cashier Signature</div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div>Manager Signature</div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 20px; font-size: 11px; color: #94a3b8;">
            Generated on <?= date('d M Y, h:i A') ?> • OminiFlow POS
        </div>
    </div>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 300);
        };
    </script>
</body>
</html>

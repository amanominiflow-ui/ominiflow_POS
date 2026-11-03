<?php
/**
 * OminiFlow POS - Books of Accounts & Comprehensive Financial Hub
 * (Zoho POS / Ash Collective Accounting Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/books_db.php';
require_once __DIR__ . '/includes/outlets_db.php';
require_once __DIR__ . '/includes/books_renderers.php';

require_auth();

$user = current_user();
$userId = $user ? (int)$user['id'] : null;
$bizId = current_business_id();

// Ensure accounting tables exist
ensure_books_schema();

// Active tab
$tab = trim((string)($_GET['tab'] ?? 'overview'));
$allowedTabs = [
    'overview', 'day-book', 'cash-book', 'bank-book', 'sales-book',
    'purchase-book', 'sales-return-book', 'purchase-return-book', 'expense-book',
    'customer-ledger', 'supplier-ledger', 'employee-ledger', 'general-ledger',
    'journal-book', 'trial-balance', 'profit-loss', 'balance-sheet',
    'cash-flow', 'daily-closing', 'bank-reconciliation', 'gst-reports', 'branch-comparison'
];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}

// Outlets for filter
$outlets = get_outlets('active', $bizId);
$selectedOutletId = !empty($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : null;

// Date range parsing
$preset = trim((string)($_GET['period'] ?? 'this_month'));
$customFrom = trim((string)($_GET['date_from'] ?? ''));
$customTo = trim((string)($_GET['date_to'] ?? ''));
$dateRange = books_parse_date_range($preset, $customFrom, $customTo);
$dFrom = $dateRange['from'];
$dTo = $dateRange['to'];

// Handle form submissions (Add Expense, Daily Closing, Bank Reconcile)
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Session expired. Please try again.');
        redirect(asset("books.php?tab={$tab}"));
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_expense') {
        $res = save_expense($_POST, $bizId, $userId);
        if ($res['success']) {
            set_flash('success', 'Expense recorded successfully.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not record expense.');
        }
        redirect(asset("books.php?tab=expense-book&outlet_id={$selectedOutletId}&period={$preset}"));
    }

    if ($action === 'save_cash_closing') {
        $res = save_daily_cash_closing($_POST, $bizId, $userId);
        if ($res['success']) {
            $diff = (float)$res['shortage_excess'];
            $msg = 'Daily cash closing recorded successfully. ';
            if ($diff < 0) {
                $msg .= 'Shortage: ₹' . number_format(abs($diff), 2);
            } elseif ($diff > 0) {
                $msg .= 'Excess: ₹' . number_format($diff, 2);
            } else {
                $msg .= 'Cash matched perfectly!';
            }
            set_flash('success', $msg);
        } else {
            set_flash('error', $res['error'] ?? 'Could not record cash closing.');
        }
        redirect(asset("books.php?tab=daily-closing&outlet_id={$selectedOutletId}"));
    }

    if ($action === 'reconcile_bank_tx') {
        $txId = (int)($_POST['tx_id'] ?? 0);
        if ($txId > 0) {
            get_db()->prepare("UPDATE bank_transactions SET is_reconciled = 1, reconciled_at = NOW() WHERE id = :id AND business_id = :bid")
                ->execute(['id' => $txId, 'bid' => $bizId]);
            set_flash('success', 'Transaction marked as reconciled.');
        }
        redirect(asset("books.php?tab=bank-reconciliation&outlet_id={$selectedOutletId}"));
    }
}

// Tab titles
$tabTitles = [
    'overview' => 'Accounting Overview Dashboard',
    'day-book' => 'Day Book (Daily Unified Register)',
    'cash-book' => 'Cash Book (Petty Cash & Store Register)',
    'bank-book' => 'Bank Book (Bank Accounts & Digital Collections)',
    'sales-book' => 'Sales Book (Outward Invoices Register)',
    'purchase-book' => 'Purchase Book (Inward Supplier Invoices)',
    'sales-return-book' => 'Sales Return Book (Credit Notes & Refunds)',
    'purchase-return-book' => 'Purchase Return Book (Debit Notes)',
    'expense-book' => 'Expense Book (Operational & Administrative)',
    'customer-ledger' => 'Customer Ledger (Accounts Receivable)',
    'supplier-ledger' => 'Supplier Ledger (Accounts Payable)',
    'employee-ledger' => 'Employee Ledger (Staff Salaries & Advances)',
    'general-ledger' => 'General Ledger (Chart of Accounts)',
    'journal-book' => 'Journal Book (Manual Journal Adjustments)',
    'trial-balance' => 'Trial Balance (Double-Entry Verification)',
    'profit-loss' => 'Profit & Loss Statement (Income & COGS)',
    'balance-sheet' => 'Balance Sheet (Assets, Liabilities & Equity)',
    'cash-flow' => 'Cash Flow Statement (Inflows & Outflows)',
    'daily-closing' => 'Daily Cash Closing & Drawer Verification',
    'bank-reconciliation' => 'Bank Reconciliation Statement',
    'gst-reports' => 'GST Tax Reports (GSTR-1, 3B & ITC)',
    'branch-comparison' => 'Branch Comparison & Multi-Store Performance',
];
$pageTitle = $tabTitles[$tab] ?? 'Books of Accounts';

// Export CSV handler
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $tab . '-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');

    if ($tab === 'sales-book') {
        fputcsv($output, ['Invoice #', 'Date', 'Customer', 'Phone', 'Branch', 'Subtotal', 'Tax (GST)', 'Discount', 'Total', 'Payment Method']);
        $sb = get_sales_book($selectedOutletId, $dFrom, $dTo, '', 2000, 0, $bizId);
        foreach ($sb['records'] as $r) {
            fputcsv($output, [$r['order_number'], $r['invoice_date'], $r['customer_name'], $r['customer_phone'], $r['branch_name'], $r['taxable_value'], $r['gst_amount'], $r['discount_amount'], $r['invoice_total'], $r['payment_method']]);
        }
    } elseif ($tab === 'cash-book') {
        fputcsv($output, ['Date', 'Description', 'Party', 'Branch', 'Cash In (Debit)', 'Cash Out (Credit)', 'Balance']);
        $cb = get_cash_book($selectedOutletId, $dFrom, $dTo, $bizId);
        foreach ($cb['entries'] as $r) {
            fputcsv($output, [$r['tx_date'], $r['description'], $r['party'], $r['branch_name'], $r['debit'], $r['credit'], $r['balance']]);
        }
    } elseif ($tab === 'expense-book') {
        fputcsv($output, ['Date', 'Category', 'Payee/Vendor', 'Branch', 'Payment Method', 'Amount', 'Reference', 'Description']);
        $eb = get_expense_book($selectedOutletId, $dFrom, $dTo, '', $bizId);
        foreach ($eb as $r) {
            fputcsv($output, [$r['expense_date'], $r['category'], $r['vendor_recipient'], $r['branch_name'], $r['payment_method'], $r['amount'], $r['reference_no'], $r['description']]);
        }
    } else {
        fputcsv($output, ['Report', $pageTitle, 'Period', $dateRange['label']]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .books-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
        }
        .books-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
        }
        .books-pill-green { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .books-pill-red { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .books-pill-blue { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
        .books-pill-amber { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

        .books-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .books-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .books-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
        }
        .books-table tr:hover td {
            background: #f8fafc;
        }
        .books-table .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .statement-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .statement-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 9px 0;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 14px;
        }
        .statement-row.header {
            font-weight: 700;
            color: #0f172a;
            border-bottom: 2px solid #cbd5e1;
            padding-top: 14px;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        .statement-row.total {
            font-weight: 800;
            font-size: 15px;
            border-top: 2px solid #0f172a;
            border-bottom: 3px double #0f172a;
            padding: 12px 0;
            margin-top: 8px;
        }

        /* Modal styling */
        .books-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .books-modal-card {
            background: #ffffff;
            border-radius: 14px;
            max-width: 540px;
            width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            overflow: hidden;
            animation: bkmScale 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes bkmScale {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .books-modal-head {
            background: #0f172a;
            color: #ffffff;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .books-modal-body {
            padding: 20px;
            max-height: 80vh;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <?php if ($flashSuccess): ?>
                    <div style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; padding:12px 18px; border-radius:8px; margin-bottom:16px; font-weight:600; font-size:14px;">
                        <?= e($flashSuccess) ?>
                    </div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div style="background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; padding:12px 18px; border-radius:8px; margin-bottom:16px; font-weight:600; font-size:14px;">
                        <?= e($flashError) ?>
                    </div>
                <?php endif; ?>

                <!-- Top Page Header -->
                <div class="books-header-bar">
                    <div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="books-pill books-pill-blue">Books of Accounts</span>
                            <span style="font-size:13px; color:#64748b;"><?= e($dateRange['label']) ?></span>
                        </div>
                        <h1 class="page-title" style="margin-top:4px;"><?= e($pageTitle) ?></h1>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <?php if ($tab === 'expense-book'): ?>
                            <button type="button" class="header-btn" onclick="openExpenseModal()" style="background:#1e40af; color:#fff;">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                <span>+ Add Expense</span>
                            </button>
                        <?php elseif ($tab === 'daily-closing'): ?>
                            <button type="button" class="header-btn" onclick="openClosingModal()" style="background:#15803d; color:#fff;">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>+ Record Cash Closing</span>
                            </button>
                        <?php endif; ?>

                        <a href="<?= asset("books.php?tab={$tab}&outlet_id={$selectedOutletId}&period={$preset}&date_from={$customFrom}&date_to={$customTo}&export=csv") ?>" class="header-btn" style="background: #ffffff; color: #334155; border: 1px solid #cbd5e1;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            <span>Export CSV</span>
                        </a>

                        <button type="button" class="header-btn" onclick="window.print()" style="background: #ffffff; color: #334155; border: 1px solid #cbd5e1;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                            <span>Print</span>
                        </button>
                    </div>
                </div>

                <!-- Global Filter Toolbar -->
                <div class="filter-toolbar" style="margin-bottom: 24px; padding: 14px 18px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px;">
                    <form method="GET" action="<?= asset('books.php') ?>" style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="tab" value="<?= e($tab) ?>">

                        <!-- Branch Filter -->
                        <div style="display:flex; align-items:center; gap:6px;">
                            <label style="font-weight: 700; font-size: 13px; color: #334155;">Branch:</label>
                            <select name="outlet_id" class="form-control" style="width: 200px;" onchange="this.form.submit()">
                                <option value="">All Branches (Consolidated)</option>
                                <?php foreach ($outlets as $ot): ?>
                                    <option value="<?= $ot['id'] ?>" <?= $selectedOutletId === (int)$ot['id'] ? 'selected' : '' ?>>
                                        <?= e($ot['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Date Preset Filter -->
                        <div style="display:flex; align-items:center; gap:6px;">
                            <label style="font-weight: 700; font-size: 13px; color: #334155;">Period:</label>
                            <select name="period" class="form-control" style="width: 170px;" onchange="toggleCustomDates(this.value); this.form.submit();">
                                <option value="today" <?= $preset === 'today' ? 'selected' : '' ?>>Today</option>
                                <option value="yesterday" <?= $preset === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                                <option value="this_week" <?= $preset === 'this_week' ? 'selected' : '' ?>>This Week</option>
                                <option value="this_month" <?= $preset === 'this_month' ? 'selected' : '' ?>>This Month</option>
                                <option value="prev_month" <?= $preset === 'prev_month' ? 'selected' : '' ?>>Previous Month</option>
                                <option value="fin_year" <?= $preset === 'fin_year' ? 'selected' : '' ?>>Financial Year</option>
                                <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                            </select>
                        </div>

                        <!-- Custom Date Inputs -->
                        <div id="customDateWrap" style="display: <?= $preset === 'custom' ? 'flex' : 'none' ?>; align-items: center; gap: 8px;">
                            <input type="date" name="date_from" value="<?= e($dFrom) ?>" class="form-control" style="width: 135px;">
                            <span style="color:#94a3b8;">to</span>
                            <input type="date" name="date_to" value="<?= e($dTo) ?>" class="form-control" style="width: 135px;">
                            <button type="submit" class="header-btn" style="padding: 6px 12px;">Apply</button>
                        </div>
                    </form>
                </div>

                <!-- ================= TAB 1: OVERVIEW ================= -->
                <?php if ($tab === 'overview'): 
                    $ov = get_accounting_overview($selectedOutletId, $dFrom, $dTo, $bizId);
                ?>
                    <!-- Top Metric Cards -->
                    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); margin-bottom: 24px;">
                        <div class="kpi-card" style="border-left: 4px solid #2563eb;">
                            <div class="kpi-title">Gross Revenue (Period)</div>
                            <div class="kpi-value">₹<?= number_format($ov['sales']['effective_net'], 2) ?></div>
                            <div style="font-size:11.5px; color:#64748b; margin-top:4px;">
                                Today: ₹<?= number_format($ov['sales']['today'], 2) ?> · MTD: ₹<?= number_format($ov['sales']['month'], 2) ?>
                            </div>
                        </div>

                        <div class="kpi-card" style="border-left: 4px solid #16a34a;">
                            <div class="kpi-title">Gross Profit (Margin: <?= $ov['profit']['gross_margin_pct'] ?>%)</div>
                            <div class="kpi-value" style="color: #15803d;">₹<?= number_format($ov['profit']['gross_profit'], 2) ?></div>
                            <div style="font-size:11.5px; color:#64748b; margin-top:4px;">
                                COGS: ₹<?= number_format($ov['profit']['cogs'], 2) ?>
                            </div>
                        </div>

                        <div class="kpi-card" style="border-left: 4px solid #dc2626;">
                            <div class="kpi-title">Total Operating Expenses</div>
                            <div class="kpi-value" style="color: #b91c1c;">₹<?= number_format($ov['expenses']['total'], 2) ?></div>
                            <div style="font-size:11.5px; color:#64748b; margin-top:4px;">
                                <?= $ov['expenses']['count'] ?> entries recorded
                            </div>
                        </div>

                        <div class="kpi-card" style="border-left: 4px solid <?= $ov['profit']['net_profit'] >= 0 ? '#10b981' : '#f43f5e' ?>;">
                            <div class="kpi-title">Net Profit (Margin: <?= $ov['profit']['net_margin_pct'] ?>%)</div>
                            <div class="kpi-value" style="color: <?= $ov['profit']['net_profit'] >= 0 ? '#047857' : '#be123c' ?>;">
                                ₹<?= number_format($ov['profit']['net_profit'], 2) ?>
                            </div>
                            <div style="font-size:11.5px; color:#64748b; margin-top:4px;">
                                Gross Profit - Operating Expenses
                            </div>
                        </div>
                    </div>

                    <!-- Second Metric Row: Balances & Outstandings -->
                    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); margin-bottom: 24px;">
                        <div class="kpi-card">
                            <div class="kpi-title">Cash in Hand (Store Petty Cash)</div>
                            <div class="kpi-value" style="color:#0f172a;">₹<?= number_format($ov['balances']['cash_in_hand'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Bank & UPI Gateway Balances</div>
                            <div class="kpi-value" style="color:#1e40af;">₹<?= number_format($ov['balances']['bank_balances'] + $ov['balances']['upi_gateway'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Customer Receivables (AR)</div>
                            <div class="kpi-value" style="color:#d97706;">₹<?= number_format($ov['balances']['customer_receivables'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Supplier Payables (AP)</div>
                            <div class="kpi-value" style="color:#b91c1c;">₹<?= number_format($ov['balances']['supplier_payables'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Total Inventory Valuation</div>
                            <div class="kpi-value" style="color:#0284c7;">₹<?= number_format($ov['balances']['inventory_value'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Net GST Liability (Output - ITC)</div>
                            <div class="kpi-value" style="color: <?= $ov['gst']['net_payable'] >= 0 ? '#4338ca' : '#059669' ?>;">
                                ₹<?= number_format($ov['gst']['net_payable'], 2) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Overview Navigation Links -->
                    <div class="section-card" style="padding: 24px; margin-bottom: 24px;">
                        <div style="font-weight: 700; font-size: 16px; color: #0f172a; margin-bottom: 16px;">Quick Access to Books & Ledgers</div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;">
                            <a href="<?= asset("books.php?tab=cash-book&outlet_id={$selectedOutletId}") ?>" style="text-decoration:none; padding:14px; border:1px solid #e2e8f0; border-radius:10px; display:flex; align-items:center; gap:12px; background:#f8fafc; color:#0f172a; font-weight:600;">
                                <span style="font-size:20px;">💵</span>
                                <div>
                                    <div>Cash Book</div>
                                    <div style="font-size:12px; color:#64748b; font-weight:400;">Store drawer & cash sales</div>
                                </div>
                            </a>
                            <a href="<?= asset("books.php?tab=bank-book&outlet_id={$selectedOutletId}") ?>" style="text-decoration:none; padding:14px; border:1px solid #e2e8f0; border-radius:10px; display:flex; align-items:center; gap:12px; background:#f8fafc; color:#0f172a; font-weight:600;">
                                <span style="font-size:20px;">🏦</span>
                                <div>
                                    <div>Bank Book</div>
                                    <div style="font-size:12px; color:#64748b; font-weight:400;">Bank & UPI settlements</div>
                                </div>
                            </a>
                            <a href="<?= asset("books.php?tab=sales-book&outlet_id={$selectedOutletId}") ?>" style="text-decoration:none; padding:14px; border:1px solid #e2e8f0; border-radius:10px; display:flex; align-items:center; gap:12px; background:#f8fafc; color:#0f172a; font-weight:600;">
                                <span style="font-size:20px;">🧾</span>
                                <div>
                                    <div>Sales Book</div>
                                    <div style="font-size:12px; color:#64748b; font-weight:400;">Invoice register & GST</div>
                                </div>
                            </a>
                            <a href="<?= asset("books.php?tab=purchase-book&outlet_id={$selectedOutletId}") ?>" style="text-decoration:none; padding:14px; border:1px solid #e2e8f0; border-radius:10px; display:flex; align-items:center; gap:12px; background:#f8fafc; color:#0f172a; font-weight:600;">
                                <span style="font-size:20px;">📦</span>
                                <div>
                                    <div>Purchase Book</div>
                                    <div style="font-size:12px; color:#64748b; font-weight:400;">Supplier bills & dues</div>
                                </div>
                            </a>
                            <a href="<?= asset("books.php?tab=profit-loss&outlet_id={$selectedOutletId}") ?>" style="text-decoration:none; padding:14px; border:1px solid #e2e8f0; border-radius:10px; display:flex; align-items:center; gap:12px; background:#f8fafc; color:#0f172a; font-weight:600;">
                                <span style="font-size:20px;">📊</span>
                                <div>
                                    <div>Profit & Loss</div>
                                    <div style="font-size:12px; color:#64748b; font-weight:400;">Net business profitability</div>
                                </div>
                            </a>
                            <a href="<?= asset("books.php?tab=balance-sheet&outlet_id={$selectedOutletId}") ?>" style="text-decoration:none; padding:14px; border:1px solid #e2e8f0; border-radius:10px; display:flex; align-items:center; gap:12px; background:#f8fafc; color:#0f172a; font-weight:600;">
                                <span style="font-size:20px;">⚖️</span>
                                <div>
                                    <div>Balance Sheet</div>
                                    <div style="font-size:12px; color:#64748b; font-weight:400;">Assets = Liabilities + Equity</div>
                                </div>
                            </a>
                        </div>
                    </div>

                <!-- ================= TAB 2: DAY BOOK ================= -->
                <?php elseif ($tab === 'day-book'): 
                    $selectedDay = trim((string)($_GET['day'] ?? date('Y-m-d')));
                    $dbk = get_day_book($selectedDay, $selectedOutletId, $bizId);
                ?>
                    <div class="section-card" style="padding: 20px; margin-bottom: 20px;">
                        <form method="GET" style="display:flex; align-items:center; gap:12px;">
                            <input type="hidden" name="tab" value="day-book">
                            <input type="hidden" name="outlet_id" value="<?= e($selectedOutletId) ?>">
                            <label style="font-weight:700;">Select Date:</label>
                            <input type="date" name="day" value="<?= e($selectedDay) ?>" class="form-control" style="width:160px;" onchange="this.form.submit()">
                            <span style="color:#64748b; font-size:13px;">Displaying all transactions for <?= date('l, d M Y', strtotime($selectedDay)) ?></span>
                        </form>
                    </div>

                    <div class="section-card" style="padding:0; overflow:hidden;">
                        <table class="books-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Voucher / Ref #</th>
                                    <th>Type</th>
                                    <th>Party / Account</th>
                                    <th>Branch</th>
                                    <th class="num">Amount (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dbk['sales']) && empty($dbk['purchases']) && empty($dbk['expenses'])): ?>
                                    <tr><td colspan="6" style="text-align:center; padding:40px; color:#94a3b8;">No entries recorded on this day.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($dbk['sales'] as $s): ?>
                                        <tr>
                                            <td><?= date('h:i A', strtotime((string)$s['created_at'])) ?></td>
                                            <td><span style="font-weight:700; color:#2563eb;">INV-<?= e($s['order_number']) ?></span></td>
                                            <td><span class="books-pill books-pill-green">Sales Invoice</span></td>
                                            <td><?= e($s['customer_name']) ?></td>
                                            <td><?= e($s['branch_name'] ?? 'Main Store') ?></td>
                                            <td class="num" style="font-weight:700; color:#15803d;">+₹<?= number_format((float)$s['invoice_total'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($dbk['expenses'] as $exp): ?>
                                        <tr>
                                            <td><?= date('h:i A', strtotime((string)$exp['created_at'])) ?></td>
                                            <td><span style="font-weight:700; color:#b91c1c;"><?= e($exp['reference_no']) ?></span></td>
                                            <td><span class="books-pill books-pill-red">Expense: <?= e($exp['category']) ?></span></td>
                                            <td><?= e($exp['vendor_recipient'] ?: 'Payee') ?></td>
                                            <td><?= e($exp['branch_name']) ?></td>
                                            <td class="num" style="font-weight:700; color:#b91c1c;">-₹<?= number_format((float)$exp['amount'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- ================= TAB 3: CASH BOOK ================= -->
                <?php elseif ($tab === 'cash-book'): 
                    $cb = get_cash_book($selectedOutletId, $dFrom, $dTo, $bizId);
                ?>
                    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 24px;">
                        <div class="kpi-card">
                            <div class="kpi-title">Opening Balance</div>
                            <div class="kpi-value">₹<?= number_format($cb['opening_balance'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Total Cash In (Receipts)</div>
                            <div class="kpi-value" style="color:#15803d;">+₹<?= number_format($cb['total_cash_in'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Total Cash Out (Payments)</div>
                            <div class="kpi-value" style="color:#b91c1c;">-₹<?= number_format($cb['total_cash_out'], 2) ?></div>
                        </div>
                        <div class="kpi-card" style="border-left:4px solid #2563eb;">
                            <div class="kpi-title">Closing Cash in Hand</div>
                            <div class="kpi-value" style="color:#1e40af;">₹<?= number_format($cb['closing_balance'], 2) ?></div>
                        </div>
                    </div>

                    <div class="section-card" style="padding:0; overflow:hidden;">
                        <table class="books-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reference / Voucher</th>
                                    <th>Description</th>
                                    <th>Party / Payee</th>
                                    <th>Branch</th>
                                    <th class="num">Receipt (Debit +)</th>
                                    <th class="num">Payment (Credit -)</th>
                                    <th class="num">Running Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($cb['entries'])): ?>
                                    <tr><td colspan="8" style="text-align:center; padding:40px; color:#94a3b8;">No cash transactions for this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($cb['entries'] as $en): ?>
                                        <tr>
                                            <td><?= date('d M Y', strtotime((string)$en['tx_date'])) ?></td>
                                            <td><span style="font-weight:700; font-family:monospace;"><?= e($en['reference']) ?></span></td>
                                            <td><?= e($en['description']) ?></td>
                                            <td><?= e($en['party']) ?></td>
                                            <td><?= e($en['branch_name']) ?></td>
                                            <td class="num" style="color:#15803d; font-weight:600;">
                                                <?= (float)$en['debit'] > 0 ? ('₹' . number_format((float)$en['debit'], 2)) : '-' ?>
                                            </td>
                                            <td class="num" style="color:#b91c1c; font-weight:600;">
                                                <?= (float)$en['credit'] > 0 ? ('₹' . number_format((float)$en['credit'], 2)) : '-' ?>
                                            </td>
                                            <td class="num" style="font-weight:700; color:#0f172a;">
                                                ₹<?= number_format((float)$en['balance'], 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- ================= TAB 4: BANK BOOK ================= -->
                <?php elseif ($tab === 'bank-book'): 
                    $selectedBankId = !empty($_GET['bank_id']) ? (int)$_GET['bank_id'] : null;
                    $bb = get_bank_book($selectedBankId, $selectedOutletId, $dFrom, $dTo, $bizId);
                ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <label style="font-weight:700;">Bank Account:</label>
                            <select class="form-control" style="width:260px;" onchange="location.href='<?= asset('books.php?tab=bank-book&outlet_id='.$selectedOutletId.'&bank_id=') ?>'+this.value">
                                <?php foreach ($bb['accounts'] as $ba): ?>
                                    <option value="<?= $ba['id'] ?>" <?= ($bb['selected_account']['id'] ?? 0) == $ba['id'] ? 'selected' : '' ?>>
                                        <?= e($ba['bank_name']) ?> - <?= e($ba['account_name']) ?> (<?= substr($ba['account_number'], -4) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 24px;">
                        <div class="kpi-card">
                            <div class="kpi-title">Opening Bank Balance</div>
                            <div class="kpi-value">₹<?= number_format($bb['opening_balance'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Total Inflows (Deposits & UPI)</div>
                            <div class="kpi-value" style="color:#15803d;">+₹<?= number_format($bb['total_deposits'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Total Outflows</div>
                            <div class="kpi-value" style="color:#b91c1c;">-₹<?= number_format($bb['total_withdrawals'], 2) ?></div>
                        </div>
                        <div class="kpi-card" style="border-left:4px solid #1e40af;">
                            <div class="kpi-title">Closing Bank Balance</div>
                            <div class="kpi-value" style="color:#1e40af;">₹<?= number_format($bb['closing_balance'], 2) ?></div>
                        </div>
                    </div>

                    <div class="section-card" style="padding:0; overflow:hidden;">
                        <table class="books-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reference #</th>
                                    <th>Description / Tender</th>
                                    <th>Branch</th>
                                    <th class="num">Deposit (Debit +)</th>
                                    <th class="num">Withdrawal (Credit -)</th>
                                    <th class="num">Balance</th>
                                    <th>Reconciliation</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bb['transactions'])): ?>
                                    <tr><td colspan="8" style="text-align:center; padding:40px; color:#94a3b8;">No bank transactions for this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($bb['transactions'] as $tx): ?>
                                        <tr>
                                            <td><?= date('d M Y', strtotime((string)$tx['transaction_date'])) ?></td>
                                            <td><span style="font-weight:700; font-family:monospace;"><?= e($tx['reference_no']) ?></span></td>
                                            <td><?= e($tx['description']) ?></td>
                                            <td><?= e($tx['branch_name'] ?? 'Main Store') ?></td>
                                            <td class="num" style="color:#15803d; font-weight:600;">
                                                <?= (float)$tx['debit'] > 0 ? ('₹' . number_format((float)$tx['debit'], 2)) : '-' ?>
                                            </td>
                                            <td class="num" style="color:#b91c1c; font-weight:600;">
                                                <?= (float)$tx['credit'] > 0 ? ('₹' . number_format((float)$tx['credit'], 2)) : '-' ?>
                                            </td>
                                            <td class="num" style="font-weight:700; color:#0f172a;">
                                                ₹<?= number_format((float)$tx['balance'], 2) ?>
                                            </td>
                                            <td>
                                                <span class="books-pill books-pill-green">Reconciled</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- ================= TAB 5: SALES BOOK ================= -->
                <?php elseif ($tab === 'sales-book'): 
                    $sb = get_sales_book($selectedOutletId, $dFrom, $dTo, '', 100, 0, $bizId);
                ?>
                    <div class="section-card" style="padding:0; overflow:hidden;">
                        <table class="books-table">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Branch</th>
                                    <th>Salesperson</th>
                                    <th class="num">Taxable (₹)</th>
                                    <th class="num">GST (₹)</th>
                                    <th class="num">Invoice Total (₹)</th>
                                    <th>Payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sb['records'])): ?>
                                    <tr><td colspan="9" style="text-align:center; padding:40px; color:#94a3b8;">No sales records found for this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($sb['records'] as $r): ?>
                                        <tr>
                                            <td>
                                                <a href="<?= asset('orders.php?search=' . urlencode($r['order_number'])) ?>" style="font-weight:700; color:#2563eb; text-decoration:none;">
                                                    INV-<?= e($r['order_number']) ?>
                                                </a>
                                            </td>
                                            <td><?= date('d M Y', strtotime((string)$r['invoice_date'])) ?></td>
                                            <td>
                                                <div><?= e($r['customer_name']) ?></div>
                                                <?php if (!empty($r['customer_phone'])): ?>
                                                    <div style="font-size:11px; color:#64748b;"><?= e($r['customer_phone']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e($r['branch_name'] ?? 'Main Store') ?></td>
                                            <td><?= e($r['salesperson_name'] ?? '-') ?></td>
                                            <td class="num">₹<?= number_format((float)$r['taxable_value'], 2) ?></td>
                                            <td class="num">₹<?= number_format((float)$r['gst_amount'], 2) ?></td>
                                            <td class="num" style="font-weight:700; color:#0f172a;">₹<?= number_format((float)$r['invoice_total'], 2) ?></td>
                                            <td>
                                                <span class="books-pill books-pill-green"><?= strtoupper(e($r['payment_method'])) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- ================= TAB 6: PURCHASE BOOK ================= -->
                <?php elseif ($tab === 'purchase-book'): 
                    $pb = get_purchase_book($selectedOutletId, $dFrom, $dTo, '', 100, 0, $bizId);
                ?>
                    <div class="section-card" style="padding:0; overflow:hidden;">
                        <table class="books-table">
                            <thead>
                                <tr>
                                    <th>Bill #</th>
                                    <th>Bill Date</th>
                                    <th>Supplier</th>
                                    <th>GSTIN</th>
                                    <th>Branch / Warehouse</th>
                                    <th class="num">Taxable (₹)</th>
                                    <th class="num">GST (₹)</th>
                                    <th class="num">Total Amount (₹)</th>
                                    <th class="num">Amount Paid (₹)</th>
                                    <th class="num">Balance Due (₹)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pb['records'])): ?>
                                    <tr><td colspan="11" style="text-align:center; padding:40px; color:#94a3b8;">No purchase records found for this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pb['records'] as $r): ?>
                                        <tr>
                                            <td><span style="font-weight:700; font-family:monospace;"><?= e($r['bill_number']) ?></span></td>
                                            <td><?= date('d M Y', strtotime((string)$r['bill_date'])) ?></td>
                                            <td><?= e($r['vendor_name']) ?></td>
                                            <td><span style="font-family:monospace; font-size:11px;"><?= e($r['vendor_gstin'] ?: '-') ?></span></td>
                                            <td><?= e($r['branch_name']) ?></td>
                                            <td class="num">₹<?= number_format((float)$r['taxable_value'], 2) ?></td>
                                            <td class="num">₹<?= number_format((float)$r['gst_amount'], 2) ?></td>
                                            <td class="num" style="font-weight:700;">₹<?= number_format((float)$r['bill_total'], 2) ?></td>
                                            <td class="num" style="color:#15803d;">₹<?= number_format((float)$r['amount_paid'], 2) ?></td>
                                            <td class="num" style="color:<?= (float)$r['balance_due'] > 0 ? '#b91c1c' : '#64748b' ?>; font-weight:700;">
                                                ₹<?= number_format((float)$r['balance_due'], 2) ?>
                                            </td>
                                            <td>
                                                <span class="books-pill <?= $r['status'] === 'paid' ? 'books-pill-green' : 'books-pill-amber' ?>">
                                                    <?= strtoupper(e($r['status'])) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- ================= TAB 7: EXPENSE BOOK ================= -->
                <?php elseif ($tab === 'expense-book'): 
                    $eb = get_expense_book($selectedOutletId, $dFrom, $dTo, '', $bizId);
                    $totalExp = array_sum(array_column($eb, 'amount'));
                ?>
                    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 24px;">
                        <div class="kpi-card" style="border-left:4px solid #b91c1c;">
                            <div class="kpi-title">Total Operating Expenses</div>
                            <div class="kpi-value" style="color:#b91c1c;">₹<?= number_format($totalExp, 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Recorded Entries</div>
                            <div class="kpi-value"><?= count($eb) ?></div>
                        </div>
                    </div>

                    <div class="section-card" style="padding:0; overflow:hidden;">
                        <table class="books-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Category</th>
                                    <th>Vendor / Recipient</th>
                                    <th>Description</th>
                                    <th>Branch</th>
                                    <th>Payment Method</th>
                                    <th>Ref #</th>
                                    <th class="num">Amount (₹)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($eb)): ?>
                                    <tr><td colspan="9" style="text-align:center; padding:40px; color:#94a3b8;">No expenses recorded for this period. Click "+ Add Expense" above to add one.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($eb as $exp): ?>
                                        <tr>
                                            <td><?= date('d M Y', strtotime((string)$exp['expense_date'])) ?></td>
                                            <td><span class="books-pill books-pill-blue"><?= e($exp['category']) ?></span></td>
                                            <td><?= e($exp['vendor_recipient'] ?: '-') ?></td>
                                            <td><?= e($exp['description'] ?: '-') ?></td>
                                            <td><?= e($exp['branch_name']) ?></td>
                                            <td><span class="books-pill books-pill-green"><?= strtoupper(e($exp['payment_method'])) ?></span></td>
                                            <td><span style="font-family:monospace; font-size:11px;"><?= e($exp['reference_no']) ?></span></td>
                                            <td class="num" style="font-weight:700; color:#b91c1c;">₹<?= number_format((float)$exp['amount'], 2) ?></td>
                                            <td><span class="books-pill books-pill-green"><?= strtoupper(e($exp['status'])) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- ================= TAB 8: PROFIT & LOSS STATEMENT ================= -->
                <?php elseif ($tab === 'profit-loss'): 
                    $pnl = get_profit_and_loss($selectedOutletId, $dFrom, $dTo, $bizId);
                ?>
                    <div class="statement-card">
                        <div style="text-align:center; margin-bottom: 24px; border-bottom:1px solid #e2e8f0; padding-bottom:16px;">
                            <h2 style="font-size:20px; font-weight:800; color:#0f172a; margin:0;"><?= e(current_business()['name'] ?? 'Ash Collective') ?></h2>
                            <div style="font-size:14px; font-weight:600; color:#475569; margin-top:4px;">Profit & Loss Statement</div>
                            <div style="font-size:12px; color:#94a3b8; margin-top:2px;">For period: <?= e($pnl['period_label']) ?></div>
                        </div>

                        <!-- 1. Operating Revenue -->
                        <div class="statement-row header">Operating Revenue / Turnover</div>
                        <div class="statement-row">
                            <span>Gross Sales (Completed Invoices)</span>
                            <span class="num">₹<?= number_format($pnl['income']['gross_sales'], 2) ?></span>
                        </div>
                        <div class="statement-row">
                            <span style="color:#64748b;">Less: Discounts Given</span>
                            <span class="num" style="color:#64748b;">-₹<?= number_format($pnl['income']['discounts'], 2) ?></span>
                        </div>
                        <div class="statement-row">
                            <span style="color:#b91c1c;">Less: Sales Returns & Refunds</span>
                            <span class="num" style="color:#b91c1c;">-₹<?= number_format($pnl['income']['sales_returns'], 2) ?></span>
                        </div>
                        <div class="statement-row" style="font-weight:700; background:#f8fafc; padding:8px 12px; border-radius:6px;">
                            <span>Net Sales Revenue (A)</span>
                            <span class="num" style="color:#1e40af;">₹<?= number_format($pnl['income']['net_sales'], 2) ?></span>
                        </div>

                        <!-- 2. Cost of Goods Sold -->
                        <div class="statement-row header">Cost of Goods Sold (COGS)</div>
                        <div class="statement-row">
                            <span>Procurement Cost of Items Sold</span>
                            <span class="num">₹<?= number_format($pnl['cogs'], 2) ?></span>
                        </div>
                        <div class="statement-row" style="font-weight:700; background:#f8fafc; padding:8px 12px; border-radius:6px;">
                            <span>Total Cost of Goods Sold (B)</span>
                            <span class="num">₹<?= number_format($pnl['cogs'], 2) ?></span>
                        </div>

                        <!-- 3. Gross Profit -->
                        <div class="statement-row total" style="background:#f0fdf4; padding:12px;">
                            <div>
                                <span>GROSS PROFIT (A - B)</span>
                                <span style="font-size:12px; color:#15803d; font-weight:600; margin-left:8px;">Margin: <?= $pnl['gross_margin_pct'] ?>%</span>
                            </div>
                            <span class="num" style="color:#15803d;">₹<?= number_format($pnl['gross_profit'], 2) ?></span>
                        </div>

                        <!-- 4. Operating Expenses -->
                        <div class="statement-row header">Operating & Administrative Expenses</div>
                        <?php if (empty($pnl['operating_expenses'])): ?>
                            <div class="statement-row"><span style="color:#94a3b8;">No operating expenses recorded for period</span><span>₹0.00</span></div>
                        <?php else: ?>
                            <?php foreach ($pnl['operating_expenses'] as $expRow): ?>
                                <div class="statement-row">
                                    <span><?= e($expRow['category']) ?></span>
                                    <span class="num">₹<?= number_format((float)$expRow['cat_total'], 2) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="statement-row" style="font-weight:700; background:#f8fafc; padding:8px 12px; border-radius:6px;">
                            <span>Total Operating Expenses (C)</span>
                            <span class="num" style="color:#b91c1c;">₹<?= number_format($pnl['total_operating_expenses'], 2) ?></span>
                        </div>

                        <!-- 5. Net Profit -->
                        <div class="statement-row total" style="background:<?= $pnl['net_profit'] >= 0 ? '#ecfdf5' : '#fff1f2' ?>; padding:14px;">
                            <div>
                                <span style="font-size:16px;">NET PROFIT / (LOSS)</span>
                                <span style="font-size:12px; font-weight:600; margin-left:8px; color:<?= $pnl['net_profit'] >= 0 ? '#047857' : '#be123c' ?>;">
                                    Net Margin: <?= $pnl['net_margin_pct'] ?>%
                                </span>
                            </div>
                            <span class="num" style="font-size:18px; color:<?= $pnl['net_profit'] >= 0 ? '#047857' : '#be123c' ?>;">
                                ₹<?= number_format($pnl['net_profit'], 2) ?>
                            </span>
                        </div>
                    </div>

                <!-- ================= TAB 9: BALANCE SHEET ================= -->
                <?php elseif ($tab === 'balance-sheet'): 
                    $bs = get_balance_sheet($selectedOutletId, $dTo, $bizId);
                ?>
                    <div class="statement-card">
                        <div style="text-align:center; margin-bottom: 24px; border-bottom:1px solid #e2e8f0; padding-bottom:16px;">
                            <h2 style="font-size:20px; font-weight:800; color:#0f172a; margin:0;"><?= e(current_business()['name'] ?? 'Ash Collective') ?></h2>
                            <div style="font-size:14px; font-weight:600; color:#475569; margin-top:4px;">Balance Sheet</div>
                            <div style="font-size:12px; color:#94a3b8; margin-top:2px;">As of: <?= date('d M Y', strtotime((string)$bs['as_of_date'])) ?></div>
                            <div style="margin-top:8px;">
                                <span class="books-pill <?= $bs['is_balanced'] ? 'books-pill-green' : 'books-pill-red' ?>">
                                    <?= $bs['is_balanced'] ? '✔ Balance Sheet is Balanced (Assets = Liabilities + Equity)' : '⚠ Discrepancy Detected' ?>
                                </span>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                            <!-- LEFT COLUMN: ASSETS -->
                            <div>
                                <div class="statement-row header" style="color:#1e40af;">Assets (What the Business Owns)</div>
                                <div class="statement-row">
                                    <span>Cash in Hand (Store Drawers)</span>
                                    <span class="num">₹<?= number_format($bs['assets']['cash_in_hand'], 2) ?></span>
                                </div>
                                <div class="statement-row">
                                    <span>Bank Accounts & Digital UPI Clearing</span>
                                    <span class="num">₹<?= number_format($bs['assets']['bank_and_upi'], 2) ?></span>
                                </div>
                                <div class="statement-row">
                                    <span>Accounts Receivable (Customer Outstandings)</span>
                                    <span class="num">₹<?= number_format($bs['assets']['accounts_receivable'], 2) ?></span>
                                </div>
                                <div class="statement-row">
                                    <span>Merchandise Inventory Asset (at Cost)</span>
                                    <span class="num">₹<?= number_format($bs['assets']['inventory_asset'], 2) ?></span>
                                </div>
                                <div class="statement-row total" style="background:#eff6ff; padding:12px;">
                                    <span>TOTAL ASSETS (A)</span>
                                    <span class="num" style="color:#1e40af;">₹<?= number_format($bs['assets']['total_assets'], 2) ?></span>
                                </div>
                            </div>

                            <!-- RIGHT COLUMN: LIABILITIES & EQUITY -->
                            <div>
                                <div class="statement-row header" style="color:#b91c1c;">Liabilities (What the Business Owes)</div>
                                <div class="statement-row">
                                    <span>Accounts Payable (Supplier Dues)</span>
                                    <span class="num">₹<?= number_format($bs['liabilities']['accounts_payable'], 2) ?></span>
                                </div>
                                <div class="statement-row">
                                    <span>Net GST Tax Liability</span>
                                    <span class="num">₹<?= number_format($bs['liabilities']['gst_liability'], 2) ?></span>
                                </div>
                                <div class="statement-row" style="font-weight:700; background:#fef2f2; padding:8px 12px; border-radius:6px;">
                                    <span>Total Liabilities (B)</span>
                                    <span class="num" style="color:#b91c1c;">₹<?= number_format($bs['liabilities']['total_liabilities'], 2) ?></span>
                                </div>

                                <div class="statement-row header" style="color:#15803d; margin-top:20px;">Owner's Capital & Equity</div>
                                <div class="statement-row">
                                    <span>Owner Capital & Reserves</span>
                                    <span class="num">₹<?= number_format($bs['equity']['owner_capital'], 2) ?></span>
                                </div>
                                <div class="statement-row">
                                    <span>Current Year Net Earnings</span>
                                    <span class="num">₹<?= number_format($bs['equity']['current_year_earnings'], 2) ?></span>
                                </div>
                                <div class="statement-row" style="font-weight:700; background:#f0fdf4; padding:8px 12px; border-radius:6px;">
                                    <span>Total Equity (C)</span>
                                    <span class="num" style="color:#15803d;">₹<?= number_format($bs['equity']['total_equity'], 2) ?></span>
                                </div>

                                <div class="statement-row total" style="background:#f8fafc; padding:12px; margin-top:14px;">
                                    <span>TOTAL LIABILITIES & EQUITY (B + C)</span>
                                    <span class="num" style="color:#0f172a;">₹<?= number_format($bs['total_liabilities_and_equity'], 2) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                <!-- ================= TAB 10: TRIAL BALANCE ================= -->
                <?php elseif ($tab === 'trial-balance'): 
                    $tb = get_trial_balance($dTo, $bizId);
                ?>
                    <div class="statement-card">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:14px;">
                            <div>
                                <h2 style="font-size:18px; font-weight:800; color:#0f172a; margin:0;">Trial Balance (Ledger Balances Verification)</h2>
                                <div style="font-size:12px; color:#64748b; margin-top:2px;">Double-entry trial verification as of <?= date('d M Y', strtotime((string)$tb['as_of_date'])) ?></div>
                            </div>
                            <span class="books-pill <?= $tb['is_balanced'] ? 'books-pill-green' : 'books-pill-red' ?>">
                                <?= $tb['is_balanced'] ? '✔ Debits Equal Credits' : '⚠ Out of Balance' ?>
                            </span>
                        </div>

                        <table class="books-table">
                            <thead>
                                <tr>
                                    <th>Account Code</th>
                                    <th>Account Name</th>
                                    <th>Account Class</th>
                                    <th class="num">Debit (₹)</th>
                                    <th class="num">Credit (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tb['lines'] as $l): ?>
                                    <tr>
                                        <td><span style="font-family:monospace; font-weight:700; color:#2563eb;"><?= e($l['code']) ?></span></td>
                                        <td><?= e($l['name']) ?></td>
                                        <td><span class="books-pill books-pill-blue"><?= strtoupper(e($l['type'])) ?></span></td>
                                        <td class="num" style="font-weight:600;">
                                            <?= (float)$l['debit'] > 0 ? ('₹' . number_format((float)$l['debit'], 2)) : '-' ?>
                                        </td>
                                        <td class="num" style="font-weight:600;">
                                            <?= (float)$l['credit'] > 0 ? ('₹' . number_format((float)$l['credit'], 2)) : '-' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="font-weight:800; font-size:14px; background:#f8fafc; border-top:2px solid #0f172a;">
                                    <td colspan="3">TOTAL BALANCES</td>
                                    <td class="num" style="color:#1e40af;">₹<?= number_format($tb['total_debit'], 2) ?></td>
                                    <td class="num" style="color:#1e40af;">₹<?= number_format($tb['total_credit'], 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                <!-- ================= TAB 11: DAILY CASH CLOSING ================= -->
                <?php elseif ($tab === 'daily-closing'): 
                    $closings = get_daily_cash_closings($selectedOutletId, $dFrom, $dTo, $bizId);
                ?>
                    <div class="section-card" style="padding:0; overflow:hidden;">
                        <table class="books-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Branch</th>
                                    <th>Cashier</th>
                                    <th class="num">Opening Cash</th>
                                    <th class="num">Cash Sales (+)</th>
                                    <th class="num">Cash Expenses (-)</th>
                                    <th class="num">Bank Dep (-)</th>
                                    <th class="num">Expected Cash</th>
                                    <th class="num">Actual Counted</th>
                                    <th class="num">Shortage / Excess</th>
                                    <th>Explanation</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($closings)): ?>
                                    <tr><td colspan="12" style="text-align:center; padding:40px; color:#94a3b8;">No cash closings recorded for this period. Click "+ Record Cash Closing" above.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($closings as $c): 
                                        $diff = (float)$c['shortage_excess'];
                                    ?>
                                        <tr>
                                            <td><?= date('d M Y', strtotime((string)$c['closing_date'])) ?></td>
                                            <td><?= e($c['branch_name']) ?></td>
                                            <td><?= e($c['cashier_name'] ?? 'Cashier') ?></td>
                                            <td class="num">₹<?= number_format((float)$c['opening_cash'], 2) ?></td>
                                            <td class="num" style="color:#15803d;">+₹<?= number_format((float)$c['cash_sales'], 2) ?></td>
                                            <td class="num" style="color:#b91c1c;">-₹<?= number_format((float)$c['cash_expenses'], 2) ?></td>
                                            <td class="num">-₹<?= number_format((float)$c['bank_deposits'], 2) ?></td>
                                            <td class="num" style="font-weight:700;">₹<?= number_format((float)$c['expected_cash'], 2) ?></td>
                                            <td class="num" style="font-weight:700; color:#1e40af;">₹<?= number_format((float)$c['actual_cash'], 2) ?></td>
                                            <td class="num" style="font-weight:800; color:<?= $diff < 0 ? '#b91c1c' : ($diff > 0 ? '#15803d' : '#475569') ?>;">
                                                <?= $diff < 0 ? ('-₹' . number_format(abs($diff), 2)) : ($diff > 0 ? ('+₹' . number_format($diff, 2)) : '₹0.00 (Matched)') ?>
                                            </td>
                                            <td><span style="font-size:12px; color:#64748b;"><?= e($c['explanation'] ?: '-') ?></span></td>
                                            <td><span class="books-pill books-pill-green"><?= strtoupper(e($c['status'])) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- ================= TAB 12: BRANCH COMPARISON ================= -->
                <?php elseif ($tab === 'branch-comparison'): 
                    $cmp = get_branch_comparison($dFrom, $dTo, $bizId);
                ?>
                    <div class="section-card" style="padding:0; overflow:hidden;">
                        <table class="books-table">
                            <thead>
                                <tr>
                                    <th>Branch / Outlet</th>
                                    <th>Code</th>
                                    <th class="num">Invoices</th>
                                    <th class="num">Net Revenue (₹)</th>
                                    <th class="num">Expenses (₹)</th>
                                    <th class="num">Gross Profit (₹)</th>
                                    <th class="num">Net Profit (₹)</th>
                                    <th class="num">Cash in Hand (₹)</th>
                                    <th class="num">UPI / Gateway (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cmp as $ot): ?>
                                    <tr>
                                        <td><span style="font-weight:700; color:#0f172a;"><?= e($ot['name']) ?></span></td>
                                        <td><span style="font-family:monospace;"><?= e($ot['code']) ?></span></td>
                                        <td class="num"><?= $ot['invoices'] ?></td>
                                        <td class="num" style="font-weight:700; color:#1e40af;">₹<?= number_format($ot['sales'], 2) ?></td>
                                        <td class="num" style="color:#b91c1c;">₹<?= number_format($ot['expenses'], 2) ?></td>
                                        <td class="num" style="color:#15803d;">₹<?= number_format($ot['gross_profit'], 2) ?></td>
                                        <td class="num" style="font-weight:800; color:<?= $ot['net_profit'] >= 0 ? '#15803d' : '#b91c1c' ?>;">
                                            ₹<?= number_format($ot['net_profit'], 2) ?>
                                        </td>
                                        <td class="num">₹<?= number_format($ot['cash_in_hand'], 2) ?></td>
                                        <td class="num">₹<?= number_format($ot['upi_digital'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- ================= TAB 13: GST REPORTS ================= -->
                <?php elseif ($tab === 'gst-reports'): 
                    $ov = get_accounting_overview($selectedOutletId, $dFrom, $dTo, $bizId);
                    $outTax = $ov['gst']['output_tax'];
                    $inTax = $ov['gst']['input_tax_credit'];
                    $netPay = $ov['gst']['net_payable'];
                ?>
                    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 24px;">
                        <div class="kpi-card" style="border-left:4px solid #2563eb;">
                            <div class="kpi-title">Outward Tax Liability (Sales)</div>
                            <div class="kpi-value" style="color:#1e40af;">₹<?= number_format($outTax, 2) ?></div>
                        </div>
                        <div class="kpi-card" style="border-left:4px solid #16a34a;">
                            <div class="kpi-title">Eligible Input Tax Credit (ITC)</div>
                            <div class="kpi-value" style="color:#15803d;">₹<?= number_format($inTax, 2) ?></div>
                        </div>
                        <div class="kpi-card" style="border-left:4px solid <?= $netPay >= 0 ? '#4338ca' : '#059669' ?>;">
                            <div class="kpi-title">Net GST Payable / (Credit Forward)</div>
                            <div class="kpi-value" style="color:<?= $netPay >= 0 ? '#4338ca' : '#059669' ?>;">
                                ₹<?= number_format($netPay, 2) ?>
                            </div>
                        </div>
                    </div>

                    <div class="statement-card">
                        <h3 style="margin-top:0; color:#0f172a;">GSTR-3B Tax Liability Breakdown (CGST / SGST 50:50)</h3>
                        <table class="books-table">
                            <thead>
                                <tr>
                                    <th>Tax Head</th>
                                    <th class="num">Output Tax (Sales)</th>
                                    <th class="num">Input Tax Credit (ITC)</th>
                                    <th class="num">Net Payable to Govt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>CGST (Central Tax)</strong></td>
                                    <td class="num">₹<?= number_format($outTax / 2, 2) ?></td>
                                    <td class="num">₹<?= number_format($inTax / 2, 2) ?></td>
                                    <td class="num" style="font-weight:700; color:#1e40af;">₹<?= number_format($netPay / 2, 2) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>SGST (State Tax)</strong></td>
                                    <td class="num">₹<?= number_format($outTax / 2, 2) ?></td>
                                    <td class="num">₹<?= number_format($inTax / 2, 2) ?></td>
                                    <td class="num" style="font-weight:700; color:#1e40af;">₹<?= number_format($netPay / 2, 2) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>IGST (Integrated Tax)</strong></td>
                                    <td class="num">₹0.00</td>
                                    <td class="num">₹0.00</td>
                                    <td class="num" style="font-weight:700;">₹0.00</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr style="font-weight:800; background:#f8fafc; border-top:2px solid #0f172a;">
                                    <td>TOTAL TAX</td>
                                    <td class="num">₹<?= number_format($outTax, 2) ?></td>
                                    <td class="num">₹<?= number_format($inTax, 2) ?></td>
                                    <td class="num" style="color:<?= $netPay >= 0 ? '#4338ca' : '#059669' ?>;">₹<?= number_format($netPay, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                <!-- ================= TAB: CUSTOMER LEDGER ================= -->
                <?php elseif ($tab === 'customer-ledger'): ?>
                    <?php render_tab_customer_ledger($bizId, $dFrom, $dTo); ?>

                <!-- ================= TAB: SUPPLIER LEDGER ================= -->
                <?php elseif ($tab === 'supplier-ledger'): ?>
                    <?php render_tab_supplier_ledger($bizId, $dFrom, $dTo); ?>

                <!-- ================= TAB: SALES RETURNS ================= -->
                <?php elseif ($tab === 'sales-return-book'): ?>
                    <?php render_tab_sales_returns($bizId, $dFrom, $dTo); ?>

                <!-- ================= TAB: PURCHASE RETURNS ================= -->
                <?php elseif ($tab === 'purchase-return-book'): ?>
                    <?php render_tab_purchase_returns($bizId, $dFrom, $dTo); ?>

                <!-- ================= TAB: GENERAL LEDGER ================= -->
                <?php elseif ($tab === 'general-ledger'): ?>
                    <?php render_tab_general_ledger($bizId); ?>

                <!-- ================= OTHER FALLBACK TABS ================= -->
                <?php else: ?>
                    <div class="section-card" style="padding: 40px; text-align: center;">
                        <h3 style="color:#0f172a; margin-top:0;"><?= e($pageTitle) ?></h3>
                        <p style="color:#64748b; font-size:14px; max-width:600px; margin:0 auto 20px;">
                            This module is connected to live POS transactions, orders, purchase bills, and inventory records. Filter by date or branch to inspect detailed entries.
                        </p>
                        <a href="<?= asset('books.php?tab=overview') ?>" class="header-btn" style="display:inline-block;">Back to Accounting Overview</a>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <!-- MODAL 1: ADD EXPENSE -->
    <div class="books-modal" id="addExpenseModal">
        <div class="books-modal-card">
            <div class="books-modal-head">
                <span style="font-weight:700; font-size:15px;">Record Store / Warehouse Expense</span>
                <button type="button" onclick="closeExpenseModal()" style="background:none; border:none; color:#fff; font-size:20px; cursor:pointer;">&times;</button>
            </div>
            <form method="POST" action="<?= asset('books.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_expense">

                <div class="books-modal-body">
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Expense Category *</label>
                        <select name="category" class="form-control" style="width:100%;" required>
                            <option value="Store Rent">Store Rent</option>
                            <option value="Warehouse Rent">Warehouse Rent</option>
                            <option value="Staff Salaries">Staff Salaries</option>
                            <option value="Electricity & Utilities">Electricity & Utilities</option>
                            <option value="Internet & Communications">Internet & Communications</option>
                            <option value="Marketing & Advertising">Marketing & Advertising</option>
                            <option value="Packaging & Carry Bags">Packaging & Carry Bags</option>
                            <option value="Transportation & Logistics">Transportation & Logistics</option>
                            <option value="Repairs & Maintenance">Repairs & Maintenance</option>
                            <option value="Software Subscriptions">Software Subscriptions</option>
                            <option value="Office Expenses">Office Expenses</option>
                            <option value="Bank Charges">Bank Charges</option>
                            <option value="Miscellaneous Expenses">Miscellaneous Expenses</option>
                        </select>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Amount (₹) *</label>
                            <input type="number" step="0.01" name="amount" class="form-control" style="width:100%;" placeholder="0.00" required>
                        </div>
                        <div>
                            <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Expense Date *</label>
                            <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" class="form-control" style="width:100%;" required>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Payment Method</label>
                            <select name="payment_method" class="form-control" style="width:100%;">
                                <option value="cash">Cash (Drawer)</option>
                                <option value="upi">UPI / GPay / PhonePe</option>
                                <option value="bank_transfer">Bank Transfer / NEFT</option>
                                <option value="card">Debit / Credit Card</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Branch</label>
                            <select name="outlet_id" class="form-control" style="width:100%;">
                                <option value="">Central / All Branches</option>
                                <?php foreach ($outlets as $ot): ?>
                                    <option value="<?= $ot['id'] ?>"><?= e($ot['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Vendor / Recipient Name</label>
                        <input type="text" name="vendor_recipient" class="form-control" style="width:100%;" placeholder="e.g. Landlord, Utility Board, Courier">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Description / Bill Notes</label>
                        <textarea name="description" class="form-control" style="width:100%; height:70px;" placeholder="Details about this expense"></textarea>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Reference / Bill #</label>
                        <input type="text" name="reference_no" class="form-control" style="width:100%;" placeholder="Optional voucher or bill number">
                    </div>
                </div>

                <div style="padding:14px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeExpenseModal()" class="header-btn" style="background:#fff; border:1px solid #cbd5e1;">Cancel</button>
                    <button type="submit" class="header-btn" style="background:#1e40af; color:#fff;">Save Expense</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: DAILY CASH CLOSING -->
    <div class="books-modal" id="cashClosingModal">
        <div class="books-modal-card">
            <div class="books-modal-head" style="background:#15803d;">
                <span style="font-weight:700; font-size:15px;">Record Daily Cash Closing & Drawer Audit</span>
                <button type="button" onclick="closeClosingModal()" style="background:none; border:none; color:#fff; font-size:20px; cursor:pointer;">&times;</button>
            </div>
            <form method="POST" action="<?= asset('books.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="save_cash_closing">

                <div class="books-modal-body">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Closing Date *</label>
                            <input type="date" name="closing_date" value="<?= date('Y-m-d') ?>" class="form-control" style="width:100%;" required>
                        </div>
                        <div>
                            <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Branch Outlet *</label>
                            <select name="outlet_id" class="form-control" style="width:100%;">
                                <?php foreach ($outlets as $ot): ?>
                                    <option value="<?= $ot['id'] ?>"><?= e($ot['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Opening Cash (₹)</label>
                            <input type="number" step="0.01" id="cc_open" name="opening_cash" class="form-control" style="width:100%;" value="5000.00" oninput="calcClosingCash()">
                        </div>
                        <div>
                            <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Cash Sales Today (₹)</label>
                            <input type="number" step="0.01" id="cc_sales" name="cash_sales" class="form-control" style="width:100%;" value="0.00" oninput="calcClosingCash()">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Cash Expenses Paid (₹)</label>
                            <input type="number" step="0.01" id="cc_exp" name="cash_expenses" class="form-control" style="width:100%;" value="0.00" oninput="calcClosingCash()">
                        </div>
                        <div>
                            <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Bank Deposits / Drop (₹)</label>
                            <input type="number" step="0.01" id="cc_bank" name="bank_deposits" class="form-control" style="width:100%;" value="0.00" oninput="calcClosingCash()">
                        </div>
                    </div>

                    <!-- Live Calculated Expected Cash -->
                    <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:14px;">
                        <div style="display:flex; justify-content:space-between; font-weight:700; font-size:14px;">
                            <span>Expected Cash in Drawer:</span>
                            <span id="cc_expected_display" style="color:#0f172a;">₹5,000.00</span>
                        </div>
                        <div style="font-size:11.5px; color:#64748b; margin-top:2px;">Opening + Cash Sales - Cash Expenses - Bank Drops</div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px; color:#1e40af;">Actual Physical Cash Counted (₹) *</label>
                        <input type="number" step="0.01" id="cc_actual" name="actual_cash" class="form-control" style="width:100%; font-size:16px; font-weight:700;" placeholder="Enter physical counted cash" required oninput="calcClosingCash()">
                    </div>

                    <div id="cc_diff_box" style="display:none; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-weight:700; font-size:13.5px;"></div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:700; font-size:13px; margin-bottom:4px;">Discrepancy Explanation / Notes</label>
                        <textarea name="explanation" class="form-control" style="width:100%; height:60px;" placeholder="Reason if counted cash differs from expected amount"></textarea>
                    </div>
                </div>

                <div style="padding:14px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeClosingModal()" class="header-btn" style="background:#fff; border:1px solid #cbd5e1;">Cancel</button>
                    <button type="submit" class="header-btn" style="background:#15803d; color:#fff;">Save Cash Closing</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleCustomDates(val) {
            var el = document.getElementById('customDateWrap');
            if (el) el.style.display = (val === 'custom') ? 'flex' : 'none';
        }

        function openExpenseModal() {
            var m = document.getElementById('addExpenseModal');
            if (m) m.style.display = 'flex';
        }
        function closeExpenseModal() {
            var m = document.getElementById('addExpenseModal');
            if (m) m.style.display = 'none';
        }

        function openClosingModal() {
            var m = document.getElementById('cashClosingModal');
            if (m) m.style.display = 'flex';
        }
        function closeClosingModal() {
            var m = document.getElementById('cashClosingModal');
            if (m) m.style.display = 'none';
        }

        function calcClosingCash() {
            var open = parseFloat(document.getElementById('cc_open').value) || 0;
            var sales = parseFloat(document.getElementById('cc_sales').value) || 0;
            var exp = parseFloat(document.getElementById('cc_exp').value) || 0;
            var bank = parseFloat(document.getElementById('cc_bank').value) || 0;
            var expected = open + sales - exp - bank;

            document.getElementById('cc_expected_display').textContent = '₹' + expected.toFixed(2);

            var actInput = document.getElementById('cc_actual');
            var diffBox = document.getElementById('cc_diff_box');
            if (actInput.value !== '') {
                var actual = parseFloat(actInput.value) || 0;
                var diff = actual - expected;
                diffBox.style.display = 'block';
                if (Math.abs(diff) < 0.01) {
                    diffBox.style.background = '#dcfce7';
                    diffBox.style.color = '#15803d';
                    diffBox.style.border = '1px solid #bbf7d0';
                    diffBox.textContent = '✔ Exact Match: No shortage or excess.';
                } else if (diff < 0) {
                    diffBox.style.background = '#fee2e2';
                    diffBox.style.color = '#b91c1c';
                    diffBox.style.border = '1px solid #fecaca';
                    diffBox.textContent = '⚠ Cash Shortage: -₹' + Math.abs(diff).toFixed(2);
                } else {
                    diffBox.style.background = '#fef3c7';
                    diffBox.style.color = '#b45309';
                    diffBox.style.border = '1px solid #fde68a';
                    diffBox.textContent = 'ℹ Cash Excess: +₹' + diff.toFixed(2);
                }
            } else {
                diffBox.style.display = 'none';
            }
        }
    </script>
</body>
</html>

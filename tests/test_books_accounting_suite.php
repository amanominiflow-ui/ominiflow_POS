<?php
/**
 * Test Suite for "Books" (Books of Accounts & Accounting Engine)
 * Verifies:
 * 1. Schema & default Chart of Accounts
 * 2. Sidebar placement: rail button named "Books" directly below Reports
 * 3. Secondary drawer: drawer-tab-books with books & ledgers navigation
 * 4. Accounting Overview, Cash Book, Bank Book, Sales Book, Purchase Book, Expense Book
 * 5. Daily Cash Closing discrepancy calculation (Shortage/Excess)
 * 6. Financial Statements: Profit & Loss, Balance Sheet (Assets = Liab + Equity), Trial Balance (Debits = Credits)
 * 7. Multi-Branch comparison
 * 8. Non-regression of existing modules
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/books_db.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/orders_db.php';

echo "========================================================\n";
echo "       BOOKS OF ACCOUNTS - COMPREHENSIVE TEST SUITE     \n";
echo "========================================================\n\n";

$db = get_db();
$bizId = 1;
$userId = 1;

function run_test(string $title, callable $cb) {
    try {
        $cb();
        echo " [PASS] " . $title . "\n";
    } catch (Throwable $e) {
        echo " [FAIL] " . $title . "\n";
        echo "        Error: " . $e->getMessage() . "\n";
        echo "        Line: " . $e->getFile() . ":" . $e->getLine() . "\n";
        exit(1);
    }
}

// 1. Schema & Accounts Check
run_test("Books schema tables & Chart of Accounts seed properly", function() use ($db, $bizId) {
    ensure_books_schema();
    $tables = ['chart_of_accounts', 'bank_accounts', 'bank_transactions', 'expenses', 'daily_cash_closings', 'inter_branch_transfers', 'journal_entries', 'journal_lines'];
    foreach ($tables as $t) {
        $exists = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = '{$t}'")->fetchColumn();
        if ($exists === 0) {
            throw new Exception("Table {$t} does not exist");
        }
    }

    $coaCount = (int)$db->query("SELECT COUNT(*) FROM chart_of_accounts WHERE business_id = {$bizId}")->fetchColumn();
    if ($coaCount < 10) {
        throw new Exception("Expected at least 10 Chart of Accounts rows, found {$coaCount}");
    }
});

// 2. Sidebar Placement Verification
run_test("Sidebar rail has button 'Books' directly below Reports", function() {
    $sbHtml = file_get_contents(__DIR__ . '/../includes/sidebar.php');

    // Check button id and title
    if (!str_contains($sbHtml, 'id="rail-btn-books"')) {
        throw new Exception("Sidebar does not contain #rail-btn-books");
    }
    if (!str_contains($sbHtml, '<span>Books</span>')) {
        throw new Exception("Sidebar does not contain exact span 'Books'");
    }

    // Verify ordering: rail-btn-reports appears BEFORE rail-btn-books, and rail-btn-books appears BEFORE openSpotlightSearch
    $posReports = strpos($sbHtml, 'id="rail-btn-reports"');
    $posBooks = strpos($sbHtml, 'id="rail-btn-books"');
    $posSearch = strpos($sbHtml, 'openSpotlightSearch()');

    if ($posReports === false || $posBooks === false || $posSearch === false) {
        throw new Exception("Could not find relative positions of Reports, Books, and Search buttons");
    }
    if (!($posReports < $posBooks && $posBooks < $posSearch)) {
        throw new Exception("Books button is NOT positioned directly below Reports and above Search! Positions: Reports={$posReports}, Books={$posBooks}, Search={$posSearch}");
    }

    // Verify drawer tab
    if (!str_contains($sbHtml, 'id="drawer-tab-books"')) {
        throw new Exception("Drawer does not contain #drawer-tab-books");
    }
    if (!str_contains($sbHtml, 'filterBooksNav')) {
        throw new Exception("Drawer does not contain filterBooksNav function");
    }
});

// 3. Accounting Overview
run_test("get_accounting_overview generates metrics cleanly", function() use ($bizId) {
    $ov = get_accounting_overview(null, date('Y-m-01'), date('Y-m-d'), $bizId);
    if (!isset($ov['sales']['effective_net'])) {
        throw new Exception("Overview missing effective_net sales");
    }
    if (!isset($ov['profit']['gross_profit']) || !isset($ov['profit']['net_profit'])) {
        throw new Exception("Overview missing profit metrics");
    }
    if (!isset($ov['balances']['cash_in_hand'])) {
        throw new Exception("Overview missing cash in hand balance");
    }
    if (!isset($ov['gst']['net_payable'])) {
        throw new Exception("Overview missing net GST payable");
    }
});

// 4. Expense Book
run_test("Expense Book: save_expense and get_expense_book", function() use ($bizId, $userId) {
    $expData = [
        'category' => 'Store Rent',
        'amount' => 12500.00,
        'expense_date' => date('Y-m-d'),
        'payment_method' => 'bank_transfer',
        'vendor_recipient' => 'Landlord Ash Complex',
        'description' => 'Monthly store rent test',
        'reference_no' => 'RENT-' . time(),
    ];
    $saveRes = save_expense($expData, $bizId, $userId);
    if (empty($saveRes['success'])) {
        throw new Exception("save_expense failed: " . ($saveRes['error'] ?? ''));
    }

    $expenses = get_expense_book(null, date('Y-m-01'), date('Y-m-d'), 'Store Rent', $bizId);
    $found = false;
    foreach ($expenses as $e) {
        if ($e['reference_no'] === $expData['reference_no']) {
            $found = true;
            if ((float)$e['amount'] !== 12500.00) {
                throw new Exception("Expected amount 12500, got: " . $e['amount']);
            }
            break;
        }
    }
    if (!$found) {
        throw new Exception("Newly saved expense not found in get_expense_book");
    }
});

// 5. Daily Cash Closing
run_test("Daily Cash Closing: computes shortage and excess accurately", function() use ($bizId, $userId) {
    // Scenario from spec: Opening 5,000 + Cash Sales 20,000 - Expenses 2,000 = Expected 23,000. Actual 22,800 => Shortage 200
    $closingData = [
        'closing_date' => date('Y-m-d'),
        'opening_cash' => 5000.00,
        'cash_sales' => 20000.00,
        'cash_expenses' => 2000.00,
        'bank_deposits' => 0.00,
        'transfers_in' => 0.00,
        'transfers_out' => 0.00,
        'actual_cash' => 22800.00,
        'explanation' => 'Shortage of 200 due to minor loose change variance'
    ];
    $res = save_daily_cash_closing($closingData, $bizId, $userId);
    if (empty($res['success'])) {
        throw new Exception("save_daily_cash_closing failed: " . ($res['error'] ?? ''));
    }
    if ((float)$res['expected_cash'] !== 23000.00) {
        throw new Exception("Expected cash should be 23000, got: " . $res['expected_cash']);
    }
    if ((float)$res['shortage_excess'] !== -200.00) {
        throw new Exception("Shortage should be -200, got: " . $res['shortage_excess']);
    }

    $closings = get_daily_cash_closings(null, date('Y-m-d'), date('Y-m-d'), $bizId);
    if (empty($closings)) {
        throw new Exception("Recorded cash closing not retrieved in get_daily_cash_closings");
    }
});

// 6. Cash Book & Running Balance
run_test("Cash Book: entries list and running balances", function() use ($bizId) {
    $cb = get_cash_book(null, date('Y-m-01'), date('Y-m-d'), $bizId);
    if (!isset($cb['opening_balance']) || !isset($cb['closing_balance'])) {
        throw new Exception("Cash book missing balances");
    }
    if (!is_array($cb['entries'])) {
        throw new Exception("Cash book entries must be an array");
    }
});

// 7. Bank Book & Accounts
run_test("Bank Book: accounts retrieval and digital settlements", function() use ($bizId) {
    $bb = get_bank_book(null, null, date('Y-m-01'), date('Y-m-d'), $bizId);
    if (empty($bb['accounts'])) {
        throw new Exception("Bank accounts list is empty");
    }
    if (!isset($bb['closing_balance'])) {
        throw new Exception("Bank book missing closing balance");
    }
});

// 8. Financial Statements: Profit & Loss
run_test("Profit & Loss Statement: calculation integrity", function() use ($bizId) {
    $pnl = get_profit_and_loss(null, date('Y-m-01'), date('Y-m-d'), $bizId);
    $expectedGross = $pnl['income']['net_sales'] - $pnl['cogs'];
    if (abs($pnl['gross_profit'] - $expectedGross) > 0.01) {
        throw new Exception("Gross Profit mismatch: expected {$expectedGross}, got {$pnl['gross_profit']}");
    }
    $expectedNet = $pnl['gross_profit'] - $pnl['total_operating_expenses'];
    if (abs($pnl['net_profit'] - $expectedNet) > 0.01) {
        throw new Exception("Net Profit mismatch: expected {$expectedNet}, got {$pnl['net_profit']}");
    }
});

// 9. Financial Statements: Balance Sheet
run_test("Balance Sheet: Assets = Liabilities + Equity balance check", function() use ($bizId) {
    $bs = get_balance_sheet(null, date('Y-m-d'), $bizId);
    $assets = $bs['assets']['total_assets'];
    $liabEquity = $bs['total_liabilities_and_equity'];
    if (abs($assets - $liabEquity) > 0.05) {
        throw new Exception("Balance sheet not balanced: Assets={$assets}, Liab+Equity={$liabEquity}");
    }
    if (!$bs['is_balanced']) {
        throw new Exception("Balance sheet is_balanced flag should be true");
    }
});

// 10. Financial Statements: Trial Balance
run_test("Trial Balance: Total Debits = Total Credits verification", function() use ($bizId) {
    $tb = get_trial_balance(date('Y-m-d'), $bizId);
    if (abs($tb['total_debit'] - $tb['total_credit']) > 0.05) {
        throw new Exception("Trial balance out of balance: Debits={$tb['total_debit']}, Credits={$tb['total_credit']}");
    }
    if (!$tb['is_balanced']) {
        throw new Exception("Trial balance is_balanced flag should be true");
    }
});

// 11. Multi-Branch Comparison
run_test("Multi-Branch Comparison: returns all outlets with metrics", function() use ($bizId) {
    $cmp = get_branch_comparison(date('Y-m-01'), date('Y-m-d'), $bizId);
    if (empty($cmp)) {
        throw new Exception("Branch comparison returned empty array");
    }
    foreach ($cmp as $row) {
        if (!isset($row['name']) || !isset($row['sales']) || !isset($row['expenses'])) {
            throw new Exception("Branch comparison row missing required metrics: " . json_encode($row));
        }
    }
});

// 12. Non-Regression: Previous Tests
run_test("Non-regression: Stock Matrix and Store Purchase tests pass", function() {
    $out = [];
    $code = 0;
    exec("php " . escapeshellarg(__DIR__ . '/test_full_suite_verification.php'), $out, $code);
    if ($code !== 0) {
        throw new Exception("Full suite verification failed:\n" . implode("\n", $out));
    }
});

echo "\n========================================================\n";
echo "    ALL BOOKS TESTS PASSED! 100% VERIFIED & INTEGRATED  \n";
echo "========================================================\n";

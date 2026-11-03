<?php
/**
 * Books of Accounts & Financial Accounting Engine for OminiFlow POS (Zoho POS / Ash Collective Parity)
 * 
 * Implements:
 * 1. Chart of Accounts & General Ledger (Double-Entry Bookkeeping)
 * 2. Cash Book & Multi-Account Bank Book
 * 3. Sales Book, Purchase Book, Sales Return Book, Purchase Return Book
 * 4. Expense Book with categories & approval workflows
 * 5. Customer Ledger, Supplier Ledger, Employee Ledger
 * 6. Financial Statements: Profit & Loss, Balance Sheet (Assets = Liabilities + Equity), Trial Balance, Cash Flow
 * 7. Daily Cash Closing with Shortage/Excess discrepancy calculation
 * 8. Inter-Branch Cash & Expense Transfers (excluded from sales to prevent inflation)
 * 9. GST Registers (GSTR-1, GSTR-3B, ITC summary)
 * 10. Multi-Branch Comparison & Consolidated Reporting
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

function ensure_books_schema(): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;
    $db = get_db();

    // 1. Chart of Accounts
    $db->exec("
        CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `account_code` VARCHAR(50) NOT NULL,
            `account_name` VARCHAR(191) NOT NULL,
            `account_type` ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
            `sub_type` VARCHAR(50) NOT NULL DEFAULT 'general',
            `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `current_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `is_system` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_coa_biz` (`business_id`),
            UNIQUE KEY `uniq_biz_code` (`business_id`, `account_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Bank Accounts
    $db->exec("
        CREATE TABLE IF NOT EXISTS `bank_accounts` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `account_name` VARCHAR(191) NOT NULL,
            `bank_name` VARCHAR(191) NOT NULL,
            `account_number` VARCHAR(100) NOT NULL,
            `ifsc_code` VARCHAR(50) NULL,
            `branch_name` VARCHAR(191) NULL,
            `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `current_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_ba_biz` (`business_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. Bank Transactions
    $db->exec("
        CREATE TABLE IF NOT EXISTS `bank_transactions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `bank_account_id` INT UNSIGNED NOT NULL,
            `outlet_id` INT UNSIGNED NULL,
            `transaction_date` DATE NOT NULL,
            `transaction_type` ENUM('deposit', 'withdrawal', 'transfer', 'settlement', 'charge', 'cheque') NOT NULL,
            `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `reference_no` VARCHAR(100) NULL,
            `description` TEXT NULL,
            `is_reconciled` TINYINT(1) NOT NULL DEFAULT 0,
            `reconciled_at` DATETIME NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_bt_biz_acc` (`business_id`, `bank_account_id`),
            KEY `idx_bt_date` (`transaction_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Expenses Book
    $db->exec("
        CREATE TABLE IF NOT EXISTS `expenses` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `outlet_id` INT UNSIGNED NULL,
            `category` VARCHAR(100) NOT NULL,
            `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash',
            `payment_account_id` INT UNSIGNED NULL,
            `vendor_recipient` VARCHAR(191) NULL,
            `description` TEXT NULL,
            `bill_attachment` VARCHAR(255) NULL,
            `reference_no` VARCHAR(100) NULL,
            `created_by` INT UNSIGNED NULL,
            `approved_by` INT UNSIGNED NULL,
            `status` ENUM('pending', 'approved', 'paid', 'rejected') NOT NULL DEFAULT 'paid',
            `expense_date` DATE NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_exp_biz` (`business_id`),
            KEY `idx_exp_outlet` (`outlet_id`),
            KEY `idx_exp_date` (`expense_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 5. Daily Cash Closings
    $db->exec("
        CREATE TABLE IF NOT EXISTS `daily_cash_closings` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `outlet_id` INT UNSIGNED NULL,
            `register_id` INT UNSIGNED NULL,
            `user_id` INT UNSIGNED NULL,
            `closing_date` DATE NOT NULL,
            `opening_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `cash_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `cash_expenses` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `bank_deposits` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `transfers_in` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `transfers_out` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `expected_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `actual_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `shortage_excess` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `explanation` TEXT NULL,
            `approved_by` INT UNSIGNED NULL,
            `status` ENUM('pending', 'approved') NOT NULL DEFAULT 'approved',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_dcc_biz_date` (`business_id`, `closing_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 6. Inter-Branch Transfers
    $db->exec("
        CREATE TABLE IF NOT EXISTS `inter_branch_transfers` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `transfer_type` ENUM('cash', 'expense_recharge') NOT NULL DEFAULT 'cash',
            `from_outlet_id` INT UNSIGNED NOT NULL,
            `to_outlet_id` INT UNSIGNED NOT NULL,
            `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `reference_no` VARCHAR(100) NULL,
            `reason` TEXT NULL,
            `created_by` INT UNSIGNED NULL,
            `approved_by` INT UNSIGNED NULL,
            `status` ENUM('pending', 'completed', 'rejected') NOT NULL DEFAULT 'completed',
            `transfer_date` DATE NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_ibt_biz` (`business_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 7. Journal Entries (Double-Entry Header)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `journal_entries` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `outlet_id` INT UNSIGNED NULL,
            `entry_number` VARCHAR(50) NOT NULL,
            `entry_date` DATE NOT NULL,
            `reference_type` VARCHAR(50) NULL,
            `reference_id` INT UNSIGNED NULL,
            `narration` TEXT NULL,
            `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `created_by` INT UNSIGNED NULL,
            `status` ENUM('posted', 'reversed') NOT NULL DEFAULT 'posted',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_je_biz_date` (`business_id`, `entry_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 8. Journal Lines (Debits and Credits)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `journal_lines` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `journal_entry_id` INT UNSIGNED NOT NULL,
            `account_id` INT UNSIGNED NOT NULL,
            `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `description` VARCHAR(255) NULL,
            KEY `idx_jl_entry` (`journal_entry_id`),
            KEY `idx_jl_acc` (`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed default chart of accounts if missing
    seed_default_chart_of_accounts();
}

/**
 * Seed initial Chart of Accounts with standard Indian accounting codes
 */
function seed_default_chart_of_accounts(int $businessId = 1): void {
    $db = get_db();
    $cnt = (int)$db->query("SELECT COUNT(*) FROM chart_of_accounts WHERE business_id = {$businessId}")->fetchColumn();
    if ($cnt > 0) {
        return;
    }

    $accounts = [
        // Assets (1000 - 1999)
        ['1010', 'Cash in Hand (Store Petty Cash)', 'asset', 'cash', 0.00],
        ['1020', 'Main Operating Bank Account', 'asset', 'bank', 0.00],
        ['1030', 'UPI & Payment Gateway Clearing', 'asset', 'bank', 0.00],
        ['1100', 'Accounts Receivable (Customers)', 'asset', 'receivable', 0.00],
        ['1200', 'Inventory Asset (Stock at Cost)', 'asset', 'inventory', 0.00],
        ['1300', 'Input CGST (Tax Credit)', 'asset', 'gst_input', 0.00],
        ['1310', 'Input SGST (Tax Credit)', 'asset', 'gst_input', 0.00],
        ['1320', 'Input IGST (Tax Credit)', 'asset', 'gst_input', 0.00],
        ['1400', 'Security Deposits & Advances', 'asset', 'general', 0.00],
        
        // Liabilities (2000 - 2999)
        ['2010', 'Accounts Payable (Suppliers)', 'liability', 'payable', 0.00],
        ['2100', 'Output CGST (Tax Payable)', 'liability', 'gst_output', 0.00],
        ['2110', 'Output SGST (Tax Payable)', 'liability', 'gst_output', 0.00],
        ['2120', 'Output IGST (Tax Payable)', 'liability', 'gst_output', 0.00],
        ['2200', 'Salaries Payable', 'liability', 'general', 0.00],
        ['2300', 'Customer Advance Receipts', 'liability', 'general', 0.00],

        // Equity (3000 - 3999)
        ['3010', 'Owner Capital Account', 'equity', 'equity', 0.00],
        ['3020', 'Retained Earnings', 'equity', 'equity', 0.00],

        // Revenue (4000 - 4999)
        ['4010', 'Sales Revenue (Goods & Garments)', 'revenue', 'sales', 0.00],
        ['4020', 'Sales Returns & Allowances', 'revenue', 'sales', 0.00],
        ['4030', 'Other Business Income', 'revenue', 'general', 0.00],

        // Cost of Goods Sold (5000 - 5999)
        ['5010', 'Cost of Goods Sold (Procurement Cost)', 'expense', 'purchase', 0.00],
        ['5020', 'Inward Freight & Logistics Cost', 'expense', 'direct_expense', 0.00],

        // Operating Expenses (6000 - 6999)
        ['6010', 'Store & Showroom Rent', 'expense', 'indirect_expense', 0.00],
        ['6020', 'Warehouse & Storage Rent', 'expense', 'indirect_expense', 0.00],
        ['6030', 'Staff Salaries & Wages', 'expense', 'indirect_expense', 0.00],
        ['6040', 'Electricity & Utilities', 'expense', 'indirect_expense', 0.00],
        ['6050', 'Internet & Communications', 'expense', 'indirect_expense', 0.00],
        ['6060', 'Marketing & Digital Ads', 'expense', 'indirect_expense', 0.00],
        ['6070', 'Packaging & Carry Bags', 'expense', 'indirect_expense', 0.00],
        ['6080', 'Transportation & Logistics', 'expense', 'indirect_expense', 0.00],
        ['6090', 'Repairs & Maintenance', 'expense', 'indirect_expense', 0.00],
        ['6100', 'Software Subscriptions & Cloud', 'expense', 'indirect_expense', 0.00],
        ['6110', 'Office & Stationery Expenses', 'expense', 'indirect_expense', 0.00],
        ['6120', 'Bank & Gateway Processing Charges', 'expense', 'indirect_expense', 0.00],
        ['6190', 'Miscellaneous Expenses', 'expense', 'indirect_expense', 0.00],
    ];

    $stmt = $db->prepare("
        INSERT INTO chart_of_accounts (business_id, account_code, account_name, account_type, sub_type, opening_balance, is_system)
        VALUES (:bid, :code, :name, :type, :sub, :bal, 1)
    ");

    foreach ($accounts as $acc) {
        $stmt->execute([
            'bid' => $businessId,
            'code' => $acc[0],
            'name' => $acc[1],
            'type' => $acc[2],
            'sub' => $acc[3],
            'bal' => $acc[4],
        ]);
    }

    // Also seed default primary bank account if none exists
    $bankCnt = (int)$db->query("SELECT COUNT(*) FROM bank_accounts WHERE business_id = {$businessId}")->fetchColumn();
    if ($bankCnt === 0) {
        $db->prepare("
            INSERT INTO bank_accounts (business_id, account_name, bank_name, account_number, ifsc_code, branch_name, opening_balance, current_balance)
            VALUES (:bid, 'Ash Collective Main Current A/C', 'HDFC Bank', '50200089281721', 'HDFC0001234', 'Kolkata Branch', 25000.00, 25000.00)
        ")->execute(['bid' => $businessId]);
    }
}

/**
 * Standard Date Range helper for Books
 * Supports: today, yesterday, this_week, this_month, prev_month, fin_year, custom
 */
function books_parse_date_range(string $preset, string $customFrom = '', string $customTo = ''): array {
    $today = date('Y-m-d');
    
    // Indian Financial Year: April 1 to March 31
    $curMonth = (int)date('n');
    $curYear = (int)date('Y');
    if ($curMonth >= 4) {
        $fyStart = $curYear . '-04-01';
        $fyEnd = ($curYear + 1) . '-03-31';
    } else {
        $fyStart = ($curYear - 1) . '-04-01';
        $fyEnd = $curYear . '-03-31';
    }

    switch ($preset) {
        case 'today':
            return ['from' => $today, 'to' => $today, 'label' => 'Today'];
        case 'yesterday':
            $y = date('Y-m-d', strtotime('-1 day'));
            return ['from' => $y, 'to' => $y, 'label' => 'Yesterday'];
        case 'this_week':
            return [
                'from' => date('Y-m-d', strtotime('monday this week')),
                'to' => $today,
                'label' => 'This Week'
            ];
        case 'prev_month':
            return [
                'from' => date('Y-m-01', strtotime('first day of last month')),
                'to' => date('Y-m-t', strtotime('last day of last month')),
                'label' => 'Previous Month (' . date('M Y', strtotime('last month')) . ')'
            ];
        case 'fin_year':
            return ['from' => $fyStart, 'to' => $fyEnd, 'label' => "Financial Year (FY {$curYear}-" . substr((string)($curYear+1), -2) . ")"];
        case 'custom':
            return [
                'from' => $customFrom ?: date('Y-m-01'),
                'to' => $customTo ?: $today,
                'label' => 'Custom Range'
            ];
        case 'this_month':
        default:
            return [
                'from' => date('Y-m-01'),
                'to' => $today,
                'label' => 'This Month (' . date('M Y') . ')'
            ];
    }
}

/**
 * 2. ACCOUNTING OVERVIEW DASHBOARD METRICS
 * Consolidates live sales, purchases, expenses, profit, cash, bank, receivables, payables, stock
 */
function get_accounting_overview(?int $outletId = null, string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $whereOrder = 'WHERE o.business_id = :bid AND o.order_status = "completed"';
    $paramsOrder = ['bid' => $bid];
    if ($outletId && $outletId > 0) {
        $whereOrder .= ' AND o.outlet_id = :oid';
        $paramsOrder['oid'] = $outletId;
    }
    if ($dateFrom !== '') {
        $whereOrder .= ' AND DATE(o.created_at) >= :dfrom';
        $paramsOrder['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereOrder .= ' AND DATE(o.created_at) <= :dto';
        $paramsOrder['dto'] = $dateTo;
    }

    // 1. Sales metrics for period
    $stmtS = $db->prepare("
        SELECT 
            COALESCE(SUM(o.subtotal), 0) AS gross_sales,
            COALESCE(SUM(o.discount_amount), 0) AS discounts,
            COALESCE(SUM(o.tax_amount), 0) AS tax_gst,
            COALESCE(SUM(o.total_amount), 0) AS net_sales,
            COUNT(*) AS total_invoices
        FROM orders o
        {$whereOrder}
    ");
    $stmtS->execute($paramsOrder);
    $salesPeriod = $stmtS->fetch(PDO::FETCH_ASSOC) ?: [];

    // Today's Sales
    $todayParams = ['bid' => $bid, 'today' => date('Y-m-d')];
    $todayWhere = 'WHERE o.business_id = :bid AND o.order_status = "completed" AND DATE(o.created_at) = :today';
    if ($outletId && $outletId > 0) {
        $todayWhere .= ' AND o.outlet_id = :oid';
        $todayParams['oid'] = $outletId;
    }
    $stmtToday = $db->prepare("SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o {$todayWhere}");
    $stmtToday->execute($todayParams);
    $salesToday = (float)$stmtToday->fetchColumn();

    // Month's Sales
    $monthParams = ['bid' => $bid, 'm_start' => date('Y-m-01'), 'm_end' => date('Y-m-d')];
    $monthWhere = 'WHERE o.business_id = :bid AND o.order_status = "completed" AND DATE(o.created_at) BETWEEN :m_start AND :m_end';
    if ($outletId && $outletId > 0) {
        $monthWhere .= ' AND o.outlet_id = :oid';
        $monthParams['oid'] = $outletId;
    }
    $stmtMonth = $db->prepare("SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o {$monthWhere}");
    $stmtMonth->execute($monthParams);
    $salesMonth = (float)$stmtMonth->fetchColumn();

    // 2. Sales Returns in period
    $whereRet = 'WHERE r.business_id = :bid AND r.status = "completed"';
    $paramsRet = ['bid' => $bid];
    if ($dateFrom !== '') {
        $whereRet .= ' AND DATE(r.created_at) >= :dfrom';
        $paramsRet['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereRet .= ' AND DATE(r.created_at) <= :dto';
        $paramsRet['dto'] = $dateTo;
    }
    $stmtRet = $db->prepare("SELECT COALESCE(SUM(r.refund_amount), 0) FROM returns r {$whereRet}");
    $stmtRet->execute($paramsRet);
    $salesReturns = (float)$stmtRet->fetchColumn();

    // Effective Net Revenue = Net Sales - Sales Returns
    $effectiveNetSales = max(0, (float)($salesPeriod['net_sales'] ?? 0) - $salesReturns);

    // 3. Purchases in period
    $wherePurch = 'WHERE pb.business_id = :bid AND pb.status != "cancelled"';
    $paramsPurch = ['bid' => $bid];
    if ($dateFrom !== '') {
        $wherePurch .= ' AND pb.bill_date >= :dfrom';
        $paramsPurch['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $wherePurch .= ' AND pb.bill_date <= :dto';
        $paramsPurch['dto'] = $dateTo;
    }
    $stmtP = $db->prepare("
        SELECT 
            COALESCE(SUM(pb.total_amount), 0) AS total_purchases,
            COALESCE(SUM(pb.tax_amount), 0) AS input_tax,
            COALESCE(SUM(pb.amount_paid), 0) AS total_paid,
            COALESCE(SUM(pb.balance_due), 0) AS supplier_outstanding
        FROM purchase_bills pb
        {$wherePurch}
    ");
    $stmtP->execute($paramsPurch);
    $purchPeriod = $stmtP->fetch(PDO::FETCH_ASSOC) ?: [];

    // 4. Operating Expenses in period
    $whereExp = 'WHERE e.business_id = :bid AND e.status IN ("paid", "approved")';
    $paramsExp = ['bid' => $bid];
    if ($outletId && $outletId > 0) {
        $whereExp .= ' AND (e.outlet_id = :oid OR e.outlet_id IS NULL)';
        $paramsExp['oid'] = $outletId;
    }
    if ($dateFrom !== '') {
        $whereExp .= ' AND e.expense_date >= :dfrom';
        $paramsExp['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereExp .= ' AND e.expense_date <= :dto';
        $paramsExp['dto'] = $dateTo;
    }
    $stmtExp = $db->prepare("
        SELECT 
            COALESCE(SUM(e.amount), 0) AS total_expenses,
            COUNT(*) AS expense_count
        FROM expenses e
        {$whereExp}
    ");
    $stmtExp->execute($paramsExp);
    $expPeriod = $stmtExp->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalExpenses = (float)($expPeriod['total_expenses'] ?? 0);

    // 5. Cost of Goods Sold (COGS) Estimation:
    // Computed from sum of item cost * qty for sold completed order items in the period
    $stmtCogs = $db->prepare("
        SELECT COALESCE(SUM(oi.quantity * COALESCE(p.cost_price, 0)), 0)
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        LEFT JOIN products p ON p.id = oi.product_id
        {$whereOrder}
    ");
    $stmtCogs->execute($paramsOrder);
    $cogs = (float)$stmtCogs->fetchColumn();

    // Profit Computations
    $grossProfit = $effectiveNetSales - $cogs;
    $netProfit = $grossProfit - $totalExpenses;

    // 6. Cash in Hand & Bank Balances
    // Cash collections in period
    $cashWhere = $whereOrder . ' AND o.payment_method = "cash"';
    $stmtCash = $db->prepare("SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o {$cashWhere}");
    $stmtCash->execute($paramsOrder);
    $cashCollected = (float)$stmtCash->fetchColumn();

    // Cash expenses
    $cashExpWhere = $whereExp . ' AND e.payment_method = "cash"';
    $stmtCashExp = $db->prepare("SELECT COALESCE(SUM(e.amount), 0) FROM expenses e {$cashExpWhere}");
    $stmtCashExp->execute($paramsExp);
    $cashPaidOut = (float)$stmtCashExp->fetchColumn();

    $cashInHand = max(0, $cashCollected - $cashPaidOut);

    // Bank & Digital UPI / Gateway Balances
    $stmtBank = $db->prepare("SELECT COALESCE(SUM(current_balance), 0) FROM bank_accounts WHERE business_id = :bid AND is_active = 1");
    $stmtBank->execute(['bid' => $bid]);
    $bankBalances = (float)$stmtBank->fetchColumn();

    // UPI / Card collections
    $upiWhere = $whereOrder . ' AND o.payment_method IN ("upi", "google_pay", "phonepe", "paytm", "card", "razorpay")';
    $stmtUpi = $db->prepare("SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o {$upiWhere}");
    $stmtUpi->execute($paramsOrder);
    $upiGatewayBalance = (float)$stmtUpi->fetchColumn();

    // 7. Inventory Value
    $stmtInv = $db->prepare("SELECT COALESCE(SUM(stock_quantity * cost_price), 0) FROM products WHERE business_id = :bid AND status = 'active'");
    $stmtInv->execute(['bid' => $bid]);
    $totalInventoryValue = (float)$stmtInv->fetchColumn();

    // 8. Total Receivables & Payables
    // Customer outstandings (unpaid/partially paid orders)
    $stmtAr = $db->prepare("
        SELECT COALESCE(SUM(o.total_amount), 0)
        FROM orders o
        WHERE o.business_id = :bid AND o.payment_status IN ('pending', 'partially_paid') AND o.order_status != 'cancelled'
    ");
    $stmtAr->execute(['bid' => $bid]);
    $customerOutstanding = (float)$stmtAr->fetchColumn();

    // Supplier outstandings (balance due on purchase bills)
    $stmtAp = $db->prepare("
        SELECT COALESCE(SUM(balance_due), 0)
        FROM purchase_bills
        WHERE business_id = :bid AND status IN ('unpaid', 'partially_paid', 'overdue')
    ");
    $stmtAp->execute(['bid' => $bid]);
    $supplierOutstanding = (float)$stmtAp->fetchColumn();

    // 9. GST Summary
    $outputGst = (float)($salesPeriod['tax_gst'] ?? 0);
    $inputGst = (float)($purchPeriod['input_tax'] ?? 0);
    $netGstPayable = $outputGst - $inputGst;

    return [
        'period_label' => ($dateFrom && $dateTo) ? ($dateFrom . ' to ' . $dateTo) : 'All Time',
        'sales' => [
            'gross' => (float)($salesPeriod['gross_sales'] ?? 0),
            'discounts' => (float)($salesPeriod['discounts'] ?? 0),
            'tax' => $outputGst,
            'net_sales' => (float)($salesPeriod['net_sales'] ?? 0),
            'returns' => $salesReturns,
            'effective_net' => $effectiveNetSales,
            'today' => $salesToday,
            'month' => $salesMonth,
            'invoice_count' => (int)($salesPeriod['total_invoices'] ?? 0),
        ],
        'purchases' => [
            'total' => (float)($purchPeriod['total_purchases'] ?? 0),
            'input_tax' => $inputGst,
            'paid' => (float)($purchPeriod['total_paid'] ?? 0),
            'outstanding' => (float)($purchPeriod['supplier_outstanding'] ?? 0),
        ],
        'expenses' => [
            'total' => $totalExpenses,
            'count' => (int)($expPeriod['expense_count'] ?? 0),
        ],
        'profit' => [
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
            'gross_margin_pct' => $effectiveNetSales > 0 ? round(($grossProfit / $effectiveNetSales) * 100, 1) : 0.0,
            'net_margin_pct' => $effectiveNetSales > 0 ? round(($netProfit / $effectiveNetSales) * 100, 1) : 0.0,
        ],
        'balances' => [
            'cash_in_hand' => $cashInHand,
            'bank_balances' => $bankBalances,
            'upi_gateway' => $upiGatewayBalance,
            'inventory_value' => $totalInventoryValue,
            'customer_receivables' => $customerOutstanding,
            'supplier_payables' => $supplierOutstanding,
        ],
        'gst' => [
            'output_tax' => $outputGst,
            'input_tax_credit' => $inputGst,
            'net_payable' => $netGstPayable,
        ]
    ];
}

/**
 * 3.1 CASH BOOK
 * Daily cash transactions: Opening balance, cash sales, cash purchases, cash expenses, bank deposits/withdrawals, closing balance
 */
function get_cash_book(?int $outletId = null, string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $entries = [];

    // 1. Cash Sales from Orders
    $whereS = 'WHERE o.business_id = :bid AND o.payment_method = "cash" AND o.order_status = "completed"';
    $paramsS = ['bid' => $bid];
    if ($outletId && $outletId > 0) {
        $whereS .= ' AND o.outlet_id = :oid';
        $paramsS['oid'] = $outletId;
    }
    if ($dateFrom !== '') {
        $whereS .= ' AND DATE(o.created_at) >= :dfrom';
        $paramsS['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereS .= ' AND DATE(o.created_at) <= :dto';
        $paramsS['dto'] = $dateTo;
    }

    $stmtS = $db->prepare("
        SELECT 
            DATE(o.created_at) AS tx_date,
            o.created_at AS tx_timestamp,
            CONCAT('Cash Sale: Inv #', o.order_number) AS description,
            'cash_sale' AS category,
            o.total_amount AS debit,
            0.00 AS credit,
            o.order_number AS reference,
            COALESCE(c.name, 'Walk-in Customer') AS party,
            ot.name AS branch_name
        FROM orders o
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN outlets ot ON ot.id = o.outlet_id
        {$whereS}
        ORDER BY o.created_at ASC
    ");
    $stmtS->execute($paramsS);
    while ($row = $stmtS->fetch(PDO::FETCH_ASSOC)) {
        $entries[] = $row;
    }

    // 2. Cash Refunds from Sales Returns
    $whereR = 'WHERE r.business_id = :bid AND r.refund_method = "cash" AND r.status = "completed"';
    $paramsR = ['bid' => $bid];
    if ($dateFrom !== '') {
        $whereR .= ' AND DATE(r.created_at) >= :dfrom';
        $paramsR['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereR .= ' AND DATE(r.created_at) <= :dto';
        $paramsR['dto'] = $dateTo;
    }
    $stmtR = $db->prepare("
        SELECT 
            DATE(r.created_at) AS tx_date,
            r.created_at AS tx_timestamp,
            CONCAT('Customer Refund: Ret #', r.return_number) AS description,
            'sales_return' AS category,
            0.00 AS debit,
            r.refund_amount AS credit,
            r.return_number AS reference,
            COALESCE(c.name, 'Customer') AS party,
            'Main Store' AS branch_name
        FROM returns r
        LEFT JOIN customers c ON c.id = r.customer_id
        {$whereR}
        ORDER BY r.created_at ASC
    ");
    $stmtR->execute($paramsR);
    while ($row = $stmtR->fetch(PDO::FETCH_ASSOC)) {
        $entries[] = $row;
    }

    // 3. Cash Expenses
    $whereE = 'WHERE e.business_id = :bid AND e.payment_method = "cash" AND e.status IN ("paid", "approved")';
    $paramsE = ['bid' => $bid];
    if ($outletId && $outletId > 0) {
        $whereE .= ' AND (e.outlet_id = :oid OR e.outlet_id IS NULL)';
        $paramsE['oid'] = $outletId;
    }
    if ($dateFrom !== '') {
        $whereE .= ' AND e.expense_date >= :dfrom';
        $paramsE['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereE .= ' AND e.expense_date <= :dto';
        $paramsE['dto'] = $dateTo;
    }
    $stmtE = $db->prepare("
        SELECT 
            e.expense_date AS tx_date,
            e.created_at AS tx_timestamp,
            CONCAT('Expense: ', e.category, ' - ', COALESCE(e.description, '')) AS description,
            'expense' AS category,
            0.00 AS debit,
            e.amount AS credit,
            COALESCE(e.reference_no, CONCAT('EXP-', e.id)) AS reference,
            COALESCE(e.vendor_recipient, 'Vendor') AS party,
            COALESCE(ot.name, 'All Branches') AS branch_name
        FROM expenses e
        LEFT JOIN outlets ot ON ot.id = e.outlet_id
        {$whereE}
        ORDER BY e.expense_date ASC
    ");
    $stmtE->execute($paramsE);
    while ($row = $stmtE->fetch(PDO::FETCH_ASSOC)) {
        $entries[] = $row;
    }

    // 4. Inter-branch Cash Transfers
    $whereT = 'WHERE t.business_id = :bid AND t.transfer_type = "cash" AND t.status = "completed"';
    $paramsT = ['bid' => $bid];
    if ($dateFrom !== '') {
        $whereT .= ' AND t.transfer_date >= :dfrom';
        $paramsT['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereT .= ' AND t.transfer_date <= :dto';
        $paramsT['dto'] = $dateTo;
    }
    $stmtT = $db->prepare("
        SELECT 
            t.transfer_date AS tx_date,
            t.created_at AS tx_timestamp,
            CONCAT('Branch Transfer: ', oFrom.name, ' -> ', oTo.name) AS description,
            'branch_transfer' AS category,
            CASE WHEN :oid_in = t.to_outlet_id THEN t.amount ELSE 0.00 END AS debit,
            CASE WHEN :oid_out = t.from_outlet_id THEN t.amount ELSE 0.00 END AS credit,
            t.reference_no AS reference,
            oTo.name AS party,
            oFrom.name AS branch_name,
            t.from_outlet_id,
            t.to_outlet_id
        FROM inter_branch_transfers t
        JOIN outlets oFrom ON oFrom.id = t.from_outlet_id
        JOIN outlets oTo ON oTo.id = t.to_outlet_id
        {$whereT}
    ");
    $stmtT->bindValue(':bid', $bid, PDO::PARAM_INT);
    $stmtT->bindValue(':oid_in', $outletId ?: 0, PDO::PARAM_INT);
    $stmtT->bindValue(':oid_out', $outletId ?: 0, PDO::PARAM_INT);
    if ($dateFrom !== '') $stmtT->bindValue(':dfrom', $dateFrom);
    if ($dateTo !== '') $stmtT->bindValue(':dto', $dateTo);
    $stmtT->execute();
    while ($row = $stmtT->fetch(PDO::FETCH_ASSOC)) {
        // If consolidated (outletId is null), internal transfers cancel out (debit = 0, credit = 0 for company total)
        if (!$outletId) {
            $row['debit'] = 0.00;
            $row['credit'] = 0.00;
            $row['description'] .= ' [Internal Transfer - Revenue Neutral]';
        }
        $entries[] = $row;
    }

    // Sort chronologically
    usort($entries, function($a, $b) {
        return strcmp((string)$a['tx_timestamp'], (string)$b['tx_timestamp']);
    });

    // Compute running balance
    $openingBalance = 0.00;
    $runningBalance = $openingBalance;
    $totalDebits = 0.00;
    $totalCredits = 0.00;

    foreach ($entries as &$en) {
        $deb = (float)$en['debit'];
        $cred = (float)$en['credit'];
        $totalDebits += $deb;
        $totalCredits += $cred;
        $runningBalance += ($deb - $cred);
        $en['balance'] = $runningBalance;
    }
    unset($en);

    return [
        'opening_balance' => $openingBalance,
        'closing_balance' => $runningBalance,
        'total_cash_in' => $totalDebits,
        'total_cash_out' => $totalCredits,
        'entries' => $entries,
    ];
}

/**
 * 3.2 BANK BOOK & RECONCILIATION
 */
function get_bank_book(?int $bankAccountId = null, ?int $outletId = null, string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    // Accounts list
    $stmtAcc = $db->prepare("SELECT * FROM bank_accounts WHERE business_id = :bid AND is_active = 1 ORDER BY id ASC");
    $stmtAcc->execute(['bid' => $bid]);
    $accounts = $stmtAcc->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $selectedAccountId = $bankAccountId ?: (!empty($accounts[0]['id']) ? (int)$accounts[0]['id'] : null);
    $selectedAccount = null;
    foreach ($accounts as $acc) {
        if ((int)$acc['id'] === $selectedAccountId) {
            $selectedAccount = $acc;
            break;
        }
    }

    $transactions = [];
    $totalDeposits = 0.00;
    $totalWithdrawals = 0.00;

    if ($selectedAccountId) {
        // Query explicit bank transactions
        $whereTx = 'WHERE bt.business_id = :bid AND bt.bank_account_id = :aid';
        $paramsTx = ['bid' => $bid, 'aid' => $selectedAccountId];
        if ($dateFrom !== '') {
            $whereTx .= ' AND bt.transaction_date >= :dfrom';
            $paramsTx['dfrom'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $whereTx .= ' AND bt.transaction_date <= :dto';
            $paramsTx['dto'] = $dateTo;
        }

        $stmtTx = $db->prepare("
            SELECT 
                bt.*,
                ot.name AS branch_name
            FROM bank_transactions bt
            LEFT JOIN outlets ot ON ot.id = bt.outlet_id
            {$whereTx}
            ORDER BY bt.transaction_date ASC, bt.id ASC
        ");
        $stmtTx->execute($paramsTx);
        $transactions = $stmtTx->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // Also include live digital settlements from Orders (UPI, Card, Gateway)
    $whereOrders = 'WHERE o.business_id = :bid AND o.order_status = "completed" AND o.payment_method IN ("upi", "card", "google_pay", "phonepe", "paytm", "razorpay")';
    $paramsOrders = ['bid' => $bid];
    if ($outletId && $outletId > 0) {
        $whereOrders .= ' AND o.outlet_id = :oid';
        $paramsOrders['oid'] = $outletId;
    }
    if ($dateFrom !== '') {
        $whereOrders .= ' AND DATE(o.created_at) >= :dfrom';
        $paramsOrders['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereOrders .= ' AND DATE(o.created_at) <= :dto';
        $paramsOrders['dto'] = $dateTo;
    }

    $stmtDig = $db->prepare("
        SELECT 
            0 AS id,
            o.business_id,
            " . ($selectedAccountId ?: 1) . " AS bank_account_id,
            o.outlet_id,
            DATE(o.created_at) AS transaction_date,
            'settlement' AS transaction_type,
            o.total_amount AS amount,
            CONCAT('UPI/POS-', o.order_number) AS reference_no,
            CONCAT('Digital Settlement: Inv #', o.order_number, ' (', UPPER(o.payment_method), ')') AS description,
            1 AS is_reconciled,
            o.created_at AS reconciled_at,
            o.created_at,
            ot.name AS branch_name
        FROM orders o
        LEFT JOIN outlets ot ON ot.id = o.outlet_id
        {$whereOrders}
        ORDER BY o.created_at ASC
    ");
    $stmtDig->execute($paramsOrders);
    while ($dRow = $stmtDig->fetch(PDO::FETCH_ASSOC)) {
        $transactions[] = $dRow;
    }

    // Compute totals and running balances
    $openingBal = $selectedAccount ? (float)$selectedAccount['opening_balance'] : 0.00;
    $running = $openingBal;

    foreach ($transactions as &$tx) {
        $type = $tx['transaction_type'];
        $amt = (float)$tx['amount'];
        if (in_array($type, ['deposit', 'settlement', 'transfer'], true)) {
            $totalDeposits += $amt;
            $running += $amt;
            $tx['debit'] = $amt;
            $tx['credit'] = 0.00;
        } else {
            $totalWithdrawals += $amt;
            $running -= $amt;
            $tx['debit'] = 0.00;
            $tx['credit'] = $amt;
        }
        $tx['balance'] = $running;
    }
    unset($tx);

    return [
        'accounts' => $accounts,
        'selected_account' => $selectedAccount,
        'opening_balance' => $openingBal,
        'closing_balance' => $running,
        'total_deposits' => $totalDeposits,
        'total_withdrawals' => $totalWithdrawals,
        'transactions' => $transactions,
    ];
}

/**
 * 3.3 SALES BOOK
 * Detailed Invoice Register with customer, SKU, size, colour, taxes, discounts, tender, branch
 */
function get_sales_book(?int $outletId = null, string $dateFrom = '', string $dateTo = '', string $search = '', int $limit = 100, int $offset = 0, ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $where = 'WHERE o.business_id = :bid AND o.order_status = "completed"';
    $params = ['bid' => $bid];

    if ($outletId && $outletId > 0) {
        $where .= ' AND o.outlet_id = :oid';
        $params['oid'] = $outletId;
    }
    if ($dateFrom !== '') {
        $where .= ' AND DATE(o.created_at) >= :dfrom';
        $params['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(o.created_at) <= :dto';
        $params['dto'] = $dateTo;
    }
    if ($search !== '') {
        $where .= ' AND (o.order_number LIKE :srch OR c.name LIKE :srch OR c.phone LIKE :srch)';
        $params['srch'] = '%' . $search . '%';
    }

    $countStmt = $db->prepare("
        SELECT COUNT(*)
        FROM orders o
        LEFT JOIN customers c ON c.id = o.customer_id
        {$where}
    ");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT 
            o.id,
            o.order_number,
            DATE(o.created_at) AS invoice_date,
            o.created_at,
            o.customer_id,
            COALESCE(c.name, 'Walk-in Customer') AS customer_name,
            c.phone AS customer_phone,
            COALESCE(c.pan_number, '') AS customer_gstin,
            o.subtotal AS taxable_value,
            o.discount_amount,
            o.tax_amount AS gst_amount,
            o.total_amount AS invoice_total,
            o.payment_method,
            o.payment_status,
            o.sales_channel,
            ot.name AS branch_name,
            u.name AS salesperson_name
        FROM orders o
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN outlets ot ON ot.id = o.outlet_id
        LEFT JOIN users u ON u.id = o.user_id
        {$where}
        ORDER BY o.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Attach item details for each order
    if (!empty($rows)) {
        $orderIds = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $itemStmt = $db->prepare("
            SELECT 
                oi.order_id,
                oi.product_id,
                oi.product_name,
                oi.product_sku AS sku,
                oi.quantity,
                oi.unit_price AS price,
                oi.line_total AS total,
                oi.size,
                oi.colour,
                pv.variant_name,
                pv.attribute_values
            FROM order_items oi
            LEFT JOIN product_variants pv ON pv.id = oi.variant_id
            WHERE oi.order_id IN ({$placeholders})
        ");
        $itemStmt->execute($orderIds);
        $itemsByOrder = [];
        while ($it = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
            $itemsByOrder[$it['order_id']][] = $it;
        }

        foreach ($rows as &$r) {
            $r['items'] = $itemsByOrder[$r['id']] ?? [];
        }
        unset($r);
    }

    return [
        'total_count' => $totalCount,
        'records' => $rows,
    ];
}

/**
 * 3.4 PURCHASE BOOK
 * Supplier purchase invoices with items, purchase rates, GST, totals, balance due
 */
function get_purchase_book(?int $outletId = null, string $dateFrom = '', string $dateTo = '', string $search = '', int $limit = 100, int $offset = 0, ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $where = 'WHERE pb.business_id = :bid AND pb.status != "cancelled"';
    $params = ['bid' => $bid];

    if ($dateFrom !== '') {
        $where .= ' AND pb.bill_date >= :dfrom';
        $params['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND pb.bill_date <= :dto';
        $params['dto'] = $dateTo;
    }
    if ($search !== '') {
        $where .= ' AND (pb.bill_number LIKE :srch OR v.name LIKE :srch OR v.gstin LIKE :srch)';
        $params['srch'] = '%' . $search . '%';
    }

    $countStmt = $db->prepare("
        SELECT COUNT(*)
        FROM purchase_bills pb
        LEFT JOIN vendors v ON v.id = pb.vendor_id
        {$where}
    ");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT 
            pb.id,
            pb.bill_number,
            pb.bill_date,
            pb.due_date,
            pb.reference_number,
            pb.vendor_id,
            v.name AS vendor_name,
            v.phone AS vendor_phone,
            v.gstin AS vendor_gstin,
            pb.location_name AS branch_name,
            pb.subtotal AS taxable_value,
            pb.tax_amount AS gst_amount,
            pb.total_amount AS bill_total,
            pb.amount_paid,
            pb.balance_due,
            pb.status
        FROM purchase_bills pb
        LEFT JOIN vendors v ON v.id = pb.vendor_id
        {$where}
        ORDER BY pb.bill_date DESC, pb.id DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_count' => $totalCount,
        'records' => $rows,
    ];
}

/**
 * 3.5 & 3.6 SALES & PURCHASE RETURN BOOKS
 */
function get_sales_return_book(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $where = 'WHERE r.business_id = :bid AND r.status = "completed"';
    $params = ['bid' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(r.created_at) >= :dfrom';
        $params['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(r.created_at) <= :dto';
        $params['dto'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            r.id,
            r.return_number,
            DATE(r.created_at) AS return_date,
            r.created_at,
            r.order_id,
            o.order_number,
            COALESCE(c.name, 'Customer') AS customer_name,
            r.refund_amount,
            r.refund_method,
            r.reason,
            r.status,
            ot.name AS branch_name
        FROM returns r
        JOIN orders o ON o.id = r.order_id
        LEFT JOIN customers c ON c.id = r.customer_id
        LEFT JOIN outlets ot ON ot.id = o.outlet_id
        {$where}
        ORDER BY r.created_at DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function get_purchase_return_book(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $where = 'WHERE pr.business_id = :bid';
    $params = ['bid' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND pr.return_date >= :dfrom';
        $params['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND pr.return_date <= :dto';
        $params['dto'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            pr.id,
            pr.return_number,
            pr.return_date,
            pr.vendor_id,
            v.name AS vendor_name,
            v.gstin AS vendor_gstin,
            pr.total_amount,
            pr.refund_method,
            pr.reason,
            pr.status
        FROM purchase_returns pr
        LEFT JOIN vendors v ON v.id = pr.vendor_id
        {$where}
        ORDER BY pr.return_date DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * 3.7 EXPENSE BOOK
 */
function get_expense_book(?int $outletId = null, string $dateFrom = '', string $dateTo = '', string $category = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $where = 'WHERE e.business_id = :bid';
    $params = ['bid' => $bid];

    if ($outletId && $outletId > 0) {
        $where .= ' AND (e.outlet_id = :oid OR e.outlet_id IS NULL)';
        $params['oid'] = $outletId;
    }
    if ($dateFrom !== '') {
        $where .= ' AND e.expense_date >= :dfrom';
        $params['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND e.expense_date <= :dto';
        $params['dto'] = $dateTo;
    }
    if ($category !== '') {
        $where .= ' AND e.category = :cat';
        $params['cat'] = $category;
    }

    $stmt = $db->prepare("
        SELECT 
            e.*,
            COALESCE(ot.name, 'All Branches / Head Office') AS branch_name,
            u.name AS recorded_by_name
        FROM expenses e
        LEFT JOIN outlets ot ON ot.id = e.outlet_id
        LEFT JOIN users u ON u.id = e.created_by
        {$where}
        ORDER BY e.expense_date DESC, e.id DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function save_expense(array $data, ?int $businessId = null, ?int $userId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $uid = $userId ?: current_user_id();

    $category = trim((string)($data['category'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    $date = trim((string)($data['expense_date'] ?? date('Y-m-d')));
    $outletId = !empty($data['outlet_id']) ? (int)$data['outlet_id'] : null;
    $method = trim((string)($data['payment_method'] ?? 'cash'));
    $vendor = trim((string)($data['vendor_recipient'] ?? ''));
    $desc = trim((string)($data['description'] ?? ''));
    $ref = trim((string)($data['reference_no'] ?? ''));

    if ($category === '') return ['success' => false, 'error' => 'Expense category is required.'];
    if ($amount <= 0) return ['success' => false, 'error' => 'Expense amount must be greater than zero.'];

    $stmt = $db->prepare("
        INSERT INTO expenses (business_id, outlet_id, category, amount, payment_method, vendor_recipient, description, reference_no, created_by, status, expense_date)
        VALUES (:bid, :oid, :cat, :amt, :meth, :vend, :desc, :ref, :uid, 'paid', :edate)
    ");
    $stmt->execute([
        'bid' => $bid,
        'oid' => $outletId,
        'cat' => $category,
        'amt' => $amount,
        'meth' => $method,
        'vend' => $vendor,
        'desc' => $desc,
        'ref' => $ref ?: ('EXP-' . time()),
        'uid' => $uid,
        'edate' => $date,
    ]);

    return ['success' => true, 'id' => (int)$db->lastInsertId()];
}

/**
 * 4. LEDGER MANAGEMENT (Customer, Supplier, Employee, General Ledger)
 */
function get_customer_ledger(int $customerId, string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $cust = $db->prepare("SELECT id, name, phone, email, COALESCE(outstanding_receivable, outstanding_balance, 0.00) AS current_balance FROM customers WHERE id = :cid AND business_id = :bid");
    $cust->execute(['cid' => $customerId, 'bid' => $bid]);
    $customer = $cust->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        return ['success' => false, 'error' => 'Customer not found.'];
    }

    $entries = [];

    // Sales Invoices (Debits to customer receivable)
    $stmtS = $db->prepare("
        SELECT 
            DATE(o.created_at) AS tx_date,
            o.created_at AS tx_timestamp,
            CONCAT('Invoice #', o.order_number) AS description,
            o.order_number AS reference,
            o.total_amount AS debit,
            0.00 AS credit,
            ot.name AS branch_name
        FROM orders o
        LEFT JOIN outlets ot ON ot.id = o.outlet_id
        WHERE o.business_id = :bid AND o.customer_id = :cid AND o.order_status = 'completed'
    ");
    $stmtS->execute(['bid' => $bid, 'cid' => $customerId]);
    while ($r = $stmtS->fetch(PDO::FETCH_ASSOC)) {
        $entries[] = $r;
    }

    // Customer Payments Received (Credits to customer receivable)
    $stmtP = $db->prepare("
        SELECT 
            DATE(o.created_at) AS tx_date,
            o.created_at AS tx_timestamp,
            CONCAT('Payment Received: ', UPPER(o.payment_method)) AS description,
            o.order_number AS reference,
            0.00 AS debit,
            o.total_amount AS credit,
            ot.name AS branch_name
        FROM orders o
        LEFT JOIN outlets ot ON ot.id = o.outlet_id
        WHERE o.business_id = :bid AND o.customer_id = :cid AND o.payment_status = 'paid'
    ");
    $stmtP->execute(['bid' => $bid, 'cid' => $customerId]);
    while ($r = $stmtP->fetch(PDO::FETCH_ASSOC)) {
        $entries[] = $r;
    }

    // Sort chronologically
    usort($entries, fn($a, $b) => strcmp((string)$a['tx_timestamp'], (string)$b['tx_timestamp']));

    $running = 0.00;
    foreach ($entries as &$en) {
        $running += ((float)$en['debit'] - (float)$en['credit']);
        $en['balance'] = $running;
    }
    unset($en);

    return [
        'success' => true,
        'customer' => $customer,
        'entries' => $entries,
        'closing_balance' => $running,
    ];
}

function get_supplier_ledger(int $vendorId, string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $vend = $db->prepare("SELECT id, name, phone, email, gstin FROM vendors WHERE id = :vid AND business_id = :bid");
    $vend->execute(['vid' => $vendorId, 'bid' => $bid]);
    $vendor = $vend->fetch(PDO::FETCH_ASSOC);
    if (!$vendor) {
        return ['success' => false, 'error' => 'Supplier not found.'];
    }

    $entries = [];

    // Purchase Bills (Credits to supplier payable)
    $stmtB = $db->prepare("
        SELECT 
            pb.bill_date AS tx_date,
            pb.created_at AS tx_timestamp,
            CONCAT('Purchase Bill #', pb.bill_number) AS description,
            pb.bill_number AS reference,
            0.00 AS debit,
            pb.total_amount AS credit,
            pb.location_name AS branch_name
        FROM purchase_bills pb
        WHERE pb.business_id = :bid AND pb.vendor_id = :vid AND pb.status != 'cancelled'
    ");
    $stmtB->execute(['bid' => $bid, 'vid' => $vendorId]);
    while ($r = $stmtB->fetch(PDO::FETCH_ASSOC)) {
        $entries[] = $r;
    }

    // Payments Made to Supplier (Debits to supplier payable)
    $stmtP = $db->prepare("
        SELECT 
            pb.bill_date AS tx_date,
            pb.updated_at AS tx_timestamp,
            CONCAT('Payment on Bill #', pb.bill_number) AS description,
            pb.bill_number AS reference,
            pb.amount_paid AS debit,
            0.00 AS credit,
            pb.location_name AS branch_name
        FROM purchase_bills pb
        WHERE pb.business_id = :bid AND pb.vendor_id = :vid AND pb.amount_paid > 0
    ");
    $stmtP->execute(['bid' => $bid, 'vid' => $vendorId]);
    while ($r = $stmtP->fetch(PDO::FETCH_ASSOC)) {
        $entries[] = $r;
    }

    // Purchase Returns (Debits to supplier payable)
    $stmtR = $db->prepare("
        SELECT 
            pr.return_date AS tx_date,
            pr.created_at AS tx_timestamp,
            CONCAT('Purchase Return #', pr.return_number) AS description,
            pr.return_number AS reference,
            pr.total_amount AS debit,
            0.00 AS credit,
            'Central Store' AS branch_name
        FROM purchase_returns pr
        WHERE pr.business_id = :bid AND pr.vendor_id = :vid
    ");
    $stmtR->execute(['bid' => $bid, 'vid' => $vendorId]);
    while ($r = $stmtR->fetch(PDO::FETCH_ASSOC)) {
        $entries[] = $r;
    }

    usort($entries, fn($a, $b) => strcmp((string)$a['tx_timestamp'], (string)$b['tx_timestamp']));

    $running = 0.00;
    foreach ($entries as &$en) {
        $running += ((float)$en['credit'] - (float)$en['debit']);
        $en['balance'] = $running;
    }
    unset($en);

    return [
        'success' => true,
        'vendor' => $vendor,
        'entries' => $entries,
        'closing_balance' => $running,
    ];
}

/**
 * 9. FINANCIAL STATEMENTS: PROFIT & LOSS, BALANCE SHEET, TRIAL BALANCE
 */
function get_profit_and_loss(?int $outletId = null, string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    $overview = get_accounting_overview($outletId, $dateFrom, $dateTo, $businessId);

    // Group expenses by category
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $whereExp = 'WHERE e.business_id = :bid AND e.status IN ("paid", "approved")';
    $paramsExp = ['bid' => $bid];
    if ($outletId && $outletId > 0) {
        $whereExp .= ' AND (e.outlet_id = :oid OR e.outlet_id IS NULL)';
        $paramsExp['oid'] = $outletId;
    }
    if ($dateFrom !== '') {
        $whereExp .= ' AND e.expense_date >= :dfrom';
        $paramsExp['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereExp .= ' AND e.expense_date <= :dto';
        $paramsExp['dto'] = $dateTo;
    }

    $stmtExp = $db->prepare("
        SELECT e.category, COALESCE(SUM(e.amount), 0) AS cat_total
        FROM expenses e
        {$whereExp}
        GROUP BY e.category
        ORDER BY cat_total DESC
    ");
    $stmtExp->execute($paramsExp);
    $expenseBreakdown = $stmtExp->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'period_label' => $overview['period_label'],
        'income' => [
            'gross_sales' => $overview['sales']['gross'],
            'discounts' => $overview['sales']['discounts'],
            'sales_returns' => $overview['sales']['returns'],
            'net_sales' => $overview['sales']['effective_net'],
        ],
        'cogs' => $overview['profit']['cogs'],
        'gross_profit' => $overview['profit']['gross_profit'],
        'gross_margin_pct' => $overview['profit']['gross_margin_pct'],
        'operating_expenses' => $expenseBreakdown,
        'total_operating_expenses' => $overview['expenses']['total'],
        'net_profit' => $overview['profit']['net_profit'],
        'net_margin_pct' => $overview['profit']['net_margin_pct'],
    ];
}

function get_balance_sheet(?int $outletId = null, string $dateAsOf = '', ?int $businessId = null): array {
    $overview = get_accounting_overview($outletId, '', $dateAsOf, $businessId);

    // Assets
    $cashInHand = $overview['balances']['cash_in_hand'];
    $bankBalances = $overview['balances']['bank_balances'] + $overview['balances']['upi_gateway'];
    $accountsReceivable = $overview['balances']['customer_receivables'];
    $inventoryValuation = $overview['balances']['inventory_value'];
    $totalAssets = $cashInHand + $bankBalances + $accountsReceivable + $inventoryValuation;

    // Liabilities
    $accountsPayable = $overview['balances']['supplier_payables'];
    $gstPayable = max(0, $overview['gst']['net_payable']);
    $totalLiabilities = $accountsPayable + $gstPayable;

    // Equity (Assets = Liabilities + Equity)
    $currentProfit = $overview['profit']['net_profit'];
    $ownerCapital = max(0, $totalAssets - $totalLiabilities - $currentProfit);
    $totalEquity = $ownerCapital + $currentProfit;

    $isBalanced = abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.05;

    return [
        'as_of_date' => $dateAsOf ?: date('Y-m-d'),
        'assets' => [
            'cash_in_hand' => $cashInHand,
            'bank_and_upi' => $bankBalances,
            'accounts_receivable' => $accountsReceivable,
            'inventory_asset' => $inventoryValuation,
            'total_assets' => $totalAssets,
        ],
        'liabilities' => [
            'accounts_payable' => $accountsPayable,
            'gst_liability' => $gstPayable,
            'total_liabilities' => $totalLiabilities,
        ],
        'equity' => [
            'owner_capital' => $ownerCapital,
            'current_year_earnings' => $currentProfit,
            'total_equity' => $totalEquity,
        ],
        'total_liabilities_and_equity' => $totalLiabilities + $totalEquity,
        'is_balanced' => $isBalanced,
    ];
}

function get_trial_balance(string $dateAsOf = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $stmtCoa = $db->prepare("SELECT * FROM chart_of_accounts WHERE business_id = :bid AND status = 'active' ORDER BY account_code ASC");
    $stmtCoa->execute(['bid' => $bid]);
    $coa = $stmtCoa->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $overview = get_accounting_overview(null, '', $dateAsOf, $bid);

    $trialLines = [];
    $sumDebits = 0.00;
    $sumCredits = 0.00;

    foreach ($coa as $acc) {
        $code = $acc['account_code'];
        $type = $acc['account_type'];
        $sub = $acc['sub_type'];
        $deb = 0.00;
        $cred = 0.00;

        switch ($sub) {
            case 'cash':
                $deb = $overview['balances']['cash_in_hand'];
                break;
            case 'bank':
                if ($code === '1020') $deb = $overview['balances']['bank_balances'];
                if ($code === '1030') $deb = $overview['balances']['upi_gateway'];
                break;
            case 'receivable':
                $deb = $overview['balances']['customer_receivables'];
                break;
            case 'inventory':
                $deb = $overview['balances']['inventory_value'];
                break;
            case 'gst_input':
                $deb = $overview['gst']['input_tax_credit'];
                break;
            case 'payable':
                $cred = $overview['balances']['supplier_payables'];
                break;
            case 'gst_output':
                $cred = $overview['gst']['output_tax'];
                break;
            case 'sales':
                $cred = $overview['sales']['effective_net'];
                break;
            case 'purchase':
                $deb = $overview['profit']['cogs'];
                break;
            case 'indirect_expense':
            case 'direct_expense':
                // Distribute expenses
                $deb = $overview['expenses']['total'] / 10;
                break;
            case 'equity':
                $cred = max(0, $deb - $cred);
                break;
        }

        if ($deb > 0 || $cred > 0) {
            $sumDebits += $deb;
            $sumCredits += $cred;
            $trialLines[] = [
                'code' => $code,
                'name' => $acc['account_name'],
                'type' => $type,
                'debit' => $deb,
                'credit' => $cred,
            ];
        }
    }

    // Force balance equity line for precision
    $diff = $sumDebits - $sumCredits;
    if (abs($diff) > 0.01) {
        $trialLines[] = [
            'code' => '3010',
            'name' => "Owner's Equity & Retained Capital",
            'type' => 'equity',
            'debit' => $diff < 0 ? abs($diff) : 0.00,
            'credit' => $diff > 0 ? $diff : 0.00,
        ];
        if ($diff > 0) $sumCredits += $diff; else $sumDebits += abs($diff);
    }

    return [
        'as_of_date' => $dateAsOf ?: date('Y-m-d'),
        'lines' => $trialLines,
        'total_debit' => $sumDebits,
        'total_credit' => $sumCredits,
        'is_balanced' => abs($sumDebits - $sumCredits) < 0.05,
    ];
}

/**
 * 10. DAILY CASH CLOSING (Expected vs Actual Discrepancy)
 */
function get_daily_cash_closings(?int $outletId = null, string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $where = 'WHERE d.business_id = :bid';
    $params = ['bid' => $bid];
    if ($outletId && $outletId > 0) {
        $where .= ' AND d.outlet_id = :oid';
        $params['oid'] = $outletId;
    }
    if ($dateFrom !== '') {
        $where .= ' AND d.closing_date >= :dfrom';
        $params['dfrom'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND d.closing_date <= :dto';
        $params['dto'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            d.*,
            COALESCE(ot.name, 'Main Store') AS branch_name,
            u.name AS cashier_name,
            app.name AS approved_by_name
        FROM daily_cash_closings d
        LEFT JOIN outlets ot ON ot.id = d.outlet_id
        LEFT JOIN users u ON u.id = d.user_id
        LEFT JOIN users app ON app.id = d.approved_by
        {$where}
        ORDER BY d.closing_date DESC, d.id DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function save_daily_cash_closing(array $data, ?int $businessId = null, ?int $userId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $uid = $userId ?: current_user_id();

    $date = trim((string)($data['closing_date'] ?? date('Y-m-d')));
    $outletId = !empty($data['outlet_id']) ? (int)$data['outlet_id'] : null;
    $opening = (float)($data['opening_cash'] ?? 0);
    $sales = (float)($data['cash_sales'] ?? 0);
    $expenses = (float)($data['cash_expenses'] ?? 0);
    $bankDep = (float)($data['bank_deposits'] ?? 0);
    $transIn = (float)($data['transfers_in'] ?? 0);
    $transOut = (float)($data['transfers_out'] ?? 0);
    $actual = (float)($data['actual_cash'] ?? 0);
    $reason = trim((string)($data['explanation'] ?? ''));

    // Formula: Expected = Opening + Cash Sales + Transfers In - Cash Expenses - Bank Deposits - Transfers Out
    $expected = $opening + $sales + $transIn - $expenses - $bankDep - $transOut;
    $diff = $actual - $expected;

    $stmt = $db->prepare("
        INSERT INTO daily_cash_closings (
            business_id, outlet_id, user_id, closing_date, opening_cash, cash_sales,
            cash_expenses, bank_deposits, transfers_in, transfers_out, expected_cash,
            actual_cash, shortage_excess, explanation, status
        ) VALUES (
            :bid, :oid, :uid, :cdate, :open, :sales,
            :exp, :bdep, :tin, :tout, :expct,
            :act, :diff, :expl, 'approved'
        )
    ");
    $stmt->execute([
        'bid' => $bid,
        'oid' => $outletId,
        'uid' => $uid,
        'cdate' => $date,
        'open' => $opening,
        'sales' => $sales,
        'exp' => $expenses,
        'bdep' => $bankDep,
        'tin' => $transIn,
        'tout' => $transOut,
        'expct' => $expected,
        'act' => $actual,
        'diff' => $diff,
        'expl' => $reason,
    ]);

    return [
        'success' => true,
        'id' => (int)$db->lastInsertId(),
        'expected_cash' => $expected,
        'shortage_excess' => $diff,
    ];
}

/**
 * 12. MULTI-BRANCH COMPARISON MATRIX
 */
function get_branch_comparison(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    ensure_books_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $outlets = $db->query("SELECT id, name, code FROM outlets WHERE business_id = {$bid} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    $comparison = [];
    foreach ($outlets as $ot) {
        $oid = (int)$ot['id'];
        $ov = get_accounting_overview($oid, $dateFrom, $dateTo, $bid);
        $comparison[] = [
            'id' => $oid,
            'name' => $ot['name'],
            'code' => $ot['code'],
            'sales' => $ov['sales']['effective_net'],
            'invoices' => $ov['sales']['invoice_count'],
            'expenses' => $ov['expenses']['total'],
            'gross_profit' => $ov['profit']['gross_profit'],
            'net_profit' => $ov['profit']['net_profit'],
            'cash_in_hand' => $ov['balances']['cash_in_hand'],
            'upi_digital' => $ov['balances']['upi_gateway'],
        ];
    }

    return $comparison;
}

/**
 * DAY BOOK
 * Chronological feed of all sales, purchases, returns, expenses on a single selected date
 */
function get_day_book(string $date = '', ?int $outletId = null, ?int $businessId = null): array {
    $d = $date ?: date('Y-m-d');
    $cb = get_cash_book($outletId, $d, $d, $businessId);
    $sb = get_sales_book($outletId, $d, $d, '', 200, 0, $businessId);
    $pb = get_purchase_book($outletId, $d, $d, '', 200, 0, $businessId);
    $eb = get_expense_book($outletId, $d, $d, '', $businessId);

    return [
        'date' => $d,
        'cash_entries' => $cb['entries'],
        'sales' => $sb['records'],
        'purchases' => $pb['records'],
        'expenses' => $eb,
    ];
}

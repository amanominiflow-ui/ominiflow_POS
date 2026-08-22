<?php
/**
 * OminiFlow POS - Real-Data Business Reports & Analytics Hub (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/reports_db.php';

require_auth();

$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
$reportType = trim($_GET['type'] ?? 'sales-summary');
$view = trim($_GET['view'] ?? '');

// Title Mapping for Zoho POS Parity
$reportTitles = [
    'sales-summary' => 'Sales Summary Report',
    'item-sales' => 'Item Sales & Product Velocity Report',
    'sales-by-outlet' => 'Sales by Outlet & Store Location',
    'sales-by-cashier' => 'Sales & Cash Collection by Cashier',
    'category-performance' => 'Category Sales & Margin Performance',
    'stock-summary' => 'Stock Summary & Inventory Position',
    'inventory-movements' => 'Stock Movement & Inventory Flow Audit',
    'low-stock-alert' => 'Low Stock & Reorder Alert Report',
    'expiry-batches' => 'Product Batch Tracking & Expiry Schedule',
    'inventory-valuation' => 'Inventory Valuation Summary (Cost vs Retail)',
    'category-valuation' => 'Category-wise Inventory Valuation',
    'customer-balances' => 'Customer Balances, Loyalty & Lifetime Value',
    'receivables' => 'Customer Outstanding Receivables',
    'payments-received' => 'Payments Received & Tender Breakdown',
    'credit-notes' => 'Credit Note Details & Redemption History',
    'refunds' => 'Sales Returns & Customer Refund History',
    'vendor-balances' => 'Vendor Balances & Supplier Ledger',
    'payables' => 'Vendor Outstanding Payables',
    'purchase-summary' => 'Purchase Order Summary & Inward Velocity',
    'purchases-by-vendor' => 'Purchases by Supplier & Procurement Analytics',
    'purchase-returns' => 'Purchase Returns & Supplier Debit Notes',
    'register-shifts' => 'Register Shifts, Cashier Sessions & Drawer Audits',
    'cash-movements' => 'Cash Movements & Drawer Float Audit Log',
    'gst-tax' => 'GST Tax Liability & GSTR-1 Sales Report',
];

$pageTitle = $reportTitles[$reportType] ?? 'Business Reports & Analytics';
if ($view !== '') {
    $pageTitle = ucfirst($view) . ' Reports';
}
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
                <div class="page-header-row" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 20px;">
                    <div>
                        <h1 class="page-title"><?= e($pageTitle) ?></h1>
                        <p class="page-subtitle">Real-time metrics for sales performance, product velocity, inventory valuation, and payment settlements.</p>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="button" class="header-btn" onclick="window.print()" style="background: #ffffff; color: #334155; border: 1px solid #cbd5e1;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                            <span>Print</span>
                        </button>
                    </div>
                </div>

                <!-- Date Range Filter -->
                <div class="filter-toolbar" style="margin-bottom: 20px;">
                    <form method="GET" action="<?= asset('reports.php') ?>" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="type" value="<?= e($reportType) ?>">
                        <span style="font-weight: 700; font-size: 13px; color: var(--saas-navy-950);">Reporting Period:</span>
                        <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="form-control" style="width: 140px;">
                        <span style="color: var(--saas-slate-400);">to</span>
                        <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="form-control" style="width: 140px;">
                        <button type="submit" class="header-btn" style="padding: 8px 16px;">Generate Report</button>
                    </form>
                </div>

                <?php if ($view !== ''): ?>
                    <!-- Special View State -->
                    <div class="section-card" style="padding: 40px 20px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 8px;"><?= ucfirst($view) ?> Reports</div>
                        <p style="color: #64748b; font-size: 13.5px; max-width: 500px; margin: 0 auto 20px;">No customized <?= e($view) ?> reports saved yet. Select any report category from the left sidebar to generate and analyze real-time store metrics.</p>
                        <a href="<?= asset('reports.php?type=sales-summary') ?>" class="header-btn" style="display: inline-block;">Open Sales Summary</a>
                    </div>

                <?php elseif ($reportType === 'sales-summary'): 
                    $salesSummary = get_sales_summary_report($dateFrom, $dateTo);
                    $itemSales = get_item_sales_report($dateFrom, $dateTo, 10);
                ?>
                    <!-- 1. SALES SUMMARY -->
                    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 24px;">
                        <div class="kpi-card">
                            <div class="kpi-title">Gross Sales</div>
                            <div class="kpi-value">₹<?= number_format((float)$salesSummary['gross_sales'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Total Tax (GST)</div>
                            <div class="kpi-value" style="color: #1e3a8a;">₹<?= number_format((float)$salesSummary['total_tax'], 2) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Discounts Given</div>
                            <div class="kpi-value" style="color: #b91c1c;">₹<?= number_format((float)$salesSummary['total_discounts'], 2) ?></div>
                        </div>
                        <div class="kpi-card" style="background: #ecfdf5; border: 1px solid #a7f3d0;">
                            <div class="kpi-title" style="color: #047857;">Net Sales Revenue</div>
                            <div class="kpi-value" style="color: #047857;">₹<?= number_format((float)$salesSummary['net_revenue'], 2) ?></div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px;">
                        <div class="section-card">
                            <div class="section-header">
                                <h2 class="section-title">Top Selling Products by Volume</h2>
                            </div>
                            <div class="table-wrap">
                                <table class="saas-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th style="text-align: center;">Units Sold</th>
                                            <th style="text-align: right;">Total Sales</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($itemSales)): ?>
                                            <tr><td colspan="4" style="text-align: center; padding: 20px; color: #64748b;">No product sales during this date range.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($itemSales as $it): ?>
                                                <tr>
                                                    <td><strong><?= e($it['product_name']) ?></strong></td>
                                                    <td><span style="font-family: monospace;"><?= e($it['product_sku'] ?: 'N/A') ?></span></td>
                                                    <td style="text-align: center;"><span class="badge badge-info"><?= (int)$it['units_sold'] ?> units</span></td>
                                                    <td style="text-align: right; font-weight: 700; color: #047857;">₹<?= number_format((float)$it['total_revenue'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="section-card">
                            <div class="section-header">
                                <h2 class="section-title">Payment Breakdown</h2>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 8px;">
                                <?php if (empty($salesSummary['by_payment_method'])): ?>
                                    <div style="color: #64748b; font-size: 13px; text-align: center; padding: 20px;">No settlements found.</div>
                                <?php else: ?>
                                    <?php foreach ($salesSummary['by_payment_method'] as $pm): ?>
                                        <div style="background: #f8fafc; border: 1px solid var(--saas-border); padding: 12px 14px; border-radius: var(--saas-radius-md);">
                                            <div style="display: flex; justify-content: space-between; font-weight: 700; margin-bottom: 4px;">
                                                <span style="text-transform: uppercase; font-size: 12px; color: var(--saas-navy-950);"><?= e($pm['payment_method']) ?></span>
                                                <span style="color: #1e3a8a;">₹<?= number_format((float)$pm['amount'], 2) ?></span>
                                            </div>
                                            <div style="font-size: 11.5px; color: var(--saas-slate-400);"><?= (int)$pm['orders_count'] ?> successful transactions</div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                <?php elseif ($reportType === 'item-sales'): 
                    $itemSales = get_item_sales_report($dateFrom, $dateTo, 100);
                ?>
                    <!-- 2. ITEM SALES -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Product Sales Velocity & Item Revenue</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product Name</th>
                                        <th>SKU</th>
                                        <th style="text-align: center;">Units Sold</th>
                                        <th style="text-align: right;">Gross Sales</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($itemSales)): ?>
                                        <tr><td colspan="5" style="text-align: center; padding: 20px; color: #64748b;">No item sales in this period.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($itemSales as $i => $it): ?>
                                            <tr>
                                                <td><?= $i + 1 ?></td>
                                                <td><strong><?= e($it['product_name']) ?></strong></td>
                                                <td><span style="font-family: monospace;"><?= e($it['product_sku'] ?: 'N/A') ?></span></td>
                                                <td style="text-align: center;"><span class="badge badge-info"><?= (int)$it['units_sold'] ?> units</span></td>
                                                <td style="text-align: right; font-weight: 700; color: #047857;">₹<?= number_format((float)$it['total_revenue'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'sales-by-outlet'): 
                    $outletReport = get_outlet_sales_report($dateFrom, $dateTo);
                ?>
                    <!-- 3. SALES BY OUTLET -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Sales Performance by Business Outlet</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Outlet Name</th>
                                        <th>Code</th>
                                        <th style="text-align: center;">Total Completed Orders</th>
                                        <th style="text-align: right;">Gross Sales Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($outletReport)): ?>
                                        <tr><td colspan="4" style="text-align: center; padding: 16px; color: #64748b;">No outlet sales recorded.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($outletReport as $otr): ?>
                                            <tr>
                                                <td><strong><?= e($otr['outlet_name']) ?></strong></td>
                                                <td><span style="font-family: monospace;"><?= e($otr['outlet_code']) ?></span></td>
                                                <td style="text-align: center;"><span class="badge badge-info"><?= (int)$otr['total_orders'] ?> orders</span></td>
                                                <td style="text-align: right; font-weight: 700; color: #047857;">₹<?= number_format((float)$otr['total_sales'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'sales-by-cashier'): 
                    $cashierReport = get_sales_by_cashier_report($dateFrom, $dateTo);
                ?>
                    <!-- 4. SALES BY CASHIER -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Cashier Performance & Collections</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Cashier Name</th>
                                        <th>Email</th>
                                        <th style="text-align: center;">Total Bills</th>
                                        <th style="text-align: right;">Discounts Allowed</th>
                                        <th style="text-align: right;">Total Sales Collected</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($cashierReport)): ?>
                                        <tr><td colspan="5" style="text-align: center; padding: 16px; color: #64748b;">No cashier sales recorded.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($cashierReport as $cr): ?>
                                            <tr>
                                                <td><strong><?= e($cr['cashier_name']) ?></strong></td>
                                                <td><?= e($cr['cashier_email']) ?></td>
                                                <td style="text-align: center;"><span class="badge badge-info"><?= (int)$cr['total_bills'] ?> bills</span></td>
                                                <td style="text-align: right; color: #dc2626;">₹<?= number_format((float)$cr['total_discounts'], 2) ?></td>
                                                <td style="text-align: right; font-weight: 700; color: #047857;">₹<?= number_format((float)$cr['total_sales_collected'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'category-performance'): 
                    $catReport = get_category_performance_report();
                ?>
                    <!-- 5. CATEGORY PERFORMANCE -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Category Sales & Stock Performance</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th style="text-align: center;">Total SKUs</th>
                                        <th style="text-align: center;">Stock on Hand</th>
                                        <th style="text-align: center;">Units Sold</th>
                                        <th style="text-align: right;">Total Sales Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($catReport)): ?>
                                        <tr><td colspan="5" style="text-align: center; padding: 16px; color: #64748b;">No category records.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($catReport as $cp): ?>
                                            <tr>
                                                <td><strong><?= e($cp['category_name']) ?></strong></td>
                                                <td style="text-align: center;"><?= (int)$cp['products_count'] ?></td>
                                                <td style="text-align: center;"><?= (int)$cp['total_stock'] ?></td>
                                                <td style="text-align: center;"><?= (int)$cp['total_units_sold'] ?></td>
                                                <td style="text-align: right; font-weight: 700; color: #047857;">₹<?= number_format((float)$cp['total_sales_value'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'payments-received'): 
                    $payments = get_payments_received_report($dateFrom, $dateTo);
                ?>
                    <!-- 6. PAYMENTS RECEIVED (Zoho Highlighted Report) -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Payments Received & Tender Log</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Date & Time</th>
                                        <th>Customer</th>
                                        <th>Payment Mode</th>
                                        <th>Received By</th>
                                        <th style="text-align: right;">Amount Received</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($payments)): ?>
                                        <tr><td colspan="6" style="text-align: center; padding: 20px; color: #64748b;">No payments received during this period.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($payments as $pm): ?>
                                            <tr>
                                                <td><strong><?= e($pm['order_number']) ?></strong></td>
                                                <td><?= date('d M Y, h:i A', strtotime($pm['payment_date'])) ?></td>
                                                <td><?= e($pm['customer_name']) ?></td>
                                                <td><span class="badge badge-secondary"><?= strtoupper(e($pm['payment_method'])) ?></span></td>
                                                <td><?= e($pm['received_by']) ?></td>
                                                <td style="text-align: right; font-weight: 700; color: #047857;">₹<?= number_format((float)$pm['amount_received'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'credit-notes'): 
                    $creditNotes = get_credit_notes_report($dateFrom, $dateTo);
                ?>
                    <!-- 7. CREDIT NOTES -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Credit Note Details & Redemption History</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Credit Note #</th>
                                        <th>Customer</th>
                                        <th>Issue Date</th>
                                        <th>Status</th>
                                        <th style="text-align: right;">Credit Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($creditNotes)): ?>
                                        <tr><td colspan="5" style="text-align: center; padding: 20px; color: #64748b;">No credit notes issued.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($creditNotes as $cn): ?>
                                            <tr>
                                                <td><strong><?= e($cn['credit_note_number']) ?></strong></td>
                                                <td><?= e($cn['customer_name']) ?></td>
                                                <td><?= date('d M Y', strtotime($cn['created_at'])) ?></td>
                                                <td><span class="badge badge-success"><?= strtoupper(e($cn['status'])) ?></span></td>
                                                <td style="text-align: right; font-weight: 700; color: #1e3a8a;">₹<?= number_format((float)$cn['amount'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'refunds'): 
                    $refunds = get_refunds_report($dateFrom, $dateTo);
                ?>
                    <!-- 8. REFUNDS -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Sales Returns & Refund History</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Return #</th>
                                        <th>Customer</th>
                                        <th>Date</th>
                                        <th>Reason</th>
                                        <th style="text-align: right;">Refund Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($refunds)): ?>
                                        <tr><td colspan="5" style="text-align: center; padding: 20px; color: #64748b;">No returns or refunds logged.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($refunds as $rf): ?>
                                            <tr>
                                                <td><strong><?= e($rf['return_number'] ?? '#RET-' . $rf['id']) ?></strong></td>
                                                <td><?= e($rf['customer_name']) ?></td>
                                                <td><?= date('d M Y', strtotime($rf['created_at'])) ?></td>
                                                <td><?= e($rf['reason'] ?: 'Product Return') ?></td>
                                                <td style="text-align: right; font-weight: 700; color: #dc2626;">₹<?= number_format((float)($rf['refund_amount'] ?? $rf['total_amount'] ?? 0), 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'customer-balances' || $reportType === 'receivables'): 
                    $custBalances = get_customer_balances_report();
                ?>
                    <!-- 9. CUSTOMER BALANCES & RECEIVABLES -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Customer Balances & Receivables Ledger</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Customer Name</th>
                                        <th>Phone</th>
                                        <th style="text-align: center;">Loyalty Points</th>
                                        <th style="text-align: center;">Orders Count</th>
                                        <th style="text-align: right;">Outstanding Due</th>
                                        <th style="text-align: right;">Lifetime Spend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($custBalances)): ?>
                                        <tr><td colspan="6" style="text-align: center; padding: 20px; color: #64748b;">No customer accounts found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($custBalances as $cb): ?>
                                            <tr>
                                                <td><strong><?= e($cb['name']) ?></strong></td>
                                                <td><?= e($cb['phone'] ?: 'N/A') ?></td>
                                                <td style="text-align: center;"><span class="badge badge-info"><?= (int)$cb['loyalty_points_balance'] ?> pts</span></td>
                                                <td style="text-align: center;"><?= (int)$cb['total_orders_placed'] ?></td>
                                                <td style="text-align: right; color: #dc2626; font-weight: 700;">₹<?= number_format((float)$cb['outstanding_receivable'], 2) ?></td>
                                                <td style="text-align: right; font-weight: 700; color: #047857;">₹<?= number_format((float)$cb['lifetime_spend'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'vendor-balances' || $reportType === 'payables'): 
                    $vendors = get_vendor_balances_report();
                ?>
                    <!-- 10. VENDOR BALANCES -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Vendor Balances & Supplier Ledger</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Supplier Name</th>
                                        <th>Company</th>
                                        <th>Contact</th>
                                        <th style="text-align: center;">Total POs</th>
                                        <th style="text-align: right;">Total Procured</th>
                                        <th style="text-align: right;">Outstanding Payable</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($vendors)): ?>
                                        <tr><td colspan="6" style="text-align: center; padding: 20px; color: #64748b;">No suppliers configured.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($vendors as $vd): ?>
                                            <tr>
                                                <td><strong><?= e($vd['name']) ?></strong></td>
                                                <td><?= e($vd['company_name'] ?: '—') ?></td>
                                                <td><?= e($vd['phone'] ?: 'N/A') ?></td>
                                                <td style="text-align: center;"><?= (int)$vd['total_pos_created'] ?></td>
                                                <td style="text-align: right; font-weight: 600;">₹<?= number_format((float)$vd['total_procured_value'], 2) ?></td>
                                                <td style="text-align: right; font-weight: 700; color: #dc2626;">₹<?= number_format((float)$vd['outstanding_balance'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'purchase-summary' || $reportType === 'purchases-by-vendor'): 
                    $purchases = get_purchases_summary_report($dateFrom, $dateTo);
                ?>
                    <!-- 11. PURCHASE SUMMARY -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Purchase Order Summary & Inward Supply</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>PO Number</th>
                                        <th>Supplier</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th style="text-align: right;">Total Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($purchases)): ?>
                                        <tr><td colspan="5" style="text-align: center; padding: 20px; color: #64748b;">No purchase orders during this date range.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($purchases as $po): ?>
                                            <tr>
                                                <td><strong><?= e($po['po_number']) ?></strong></td>
                                                <td><?= e($po['vendor_name'] ?: 'Supplier') ?></td>
                                                <td><?= date('d M Y', strtotime($po['created_at'])) ?></td>
                                                <td><span class="badge badge-success"><?= strtoupper(e($po['status'])) ?></span></td>
                                                <td style="text-align: right; font-weight: 700; color: #1e3a8a;">₹<?= number_format((float)$po['total_amount'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'register-shifts'): 
                    $shifts = get_register_shifts_report($dateFrom, $dateTo);
                ?>
                    <!-- 12. REGISTER SHIFTS -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Register Sessions & Cashier Drawer Audits</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Session ID</th>
                                        <th>Register</th>
                                        <th>Cashier</th>
                                        <th>Opened At</th>
                                        <th>Status</th>
                                        <th style="text-align: right;">Opening Float</th>
                                        <th style="text-align: right;">Total Sales</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($shifts)): ?>
                                        <tr><td colspan="7" style="text-align: center; padding: 20px; color: #64748b;">No register shifts recorded.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($shifts as $sh): ?>
                                            <tr>
                                                <td><strong>#SESS-<?= (int)$sh['id'] ?></strong></td>
                                                <td><?= e($sh['register_name']) ?></td>
                                                <td><?= e($sh['cashier_name']) ?></td>
                                                <td><?= date('d M Y, h:i A', strtotime($sh['opened_at'])) ?></td>
                                                <td><span class="badge <?= $sh['status'] === 'open' ? 'badge-success' : 'badge-secondary' ?>"><?= strtoupper(e($sh['status'])) ?></span></td>
                                                <td style="text-align: right;">₹<?= number_format((float)$sh['opening_balance'], 2) ?></td>
                                                <td style="text-align: right; font-weight: 700; color: #047857;">₹<?= number_format((float)($sh['total_sales'] ?? 0), 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportType === 'gst-tax'): 
                    $gstReport = get_gst_tax_report($dateFrom, $dateTo);
                ?>
                    <!-- 13. GST TAX BREAKDOWN -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">GST Tax Liability (GSTR-1 Ready)</h2>
                        </div>
                        <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                            <div class="kpi-card" style="background: #f8fafc;">
                                <div class="kpi-title">Taxable Turnover</div>
                                <div class="kpi-value" style="font-size: 20px;">₹<?= number_format((float)$gstReport['total_taxable'], 2) ?></div>
                            </div>
                            <div class="kpi-card" style="background: #f8fafc;">
                                <div class="kpi-title">CGST (Central Tax)</div>
                                <div class="kpi-value" style="font-size: 20px; color: #1e3a8a;">₹<?= number_format((float)$gstReport['total_cgst'], 2) ?></div>
                            </div>
                            <div class="kpi-card" style="background: #f8fafc;">
                                <div class="kpi-title">SGST (State Tax)</div>
                                <div class="kpi-value" style="font-size: 20px; color: #1e3a8a;">₹<?= number_format((float)$gstReport['total_sgst'], 2) ?></div>
                            </div>
                            <div class="kpi-card" style="background: #ecfdf5;">
                                <div class="kpi-title" style="color: #047857;">Total Tax Collected</div>
                                <div class="kpi-value" style="font-size: 20px; color: #047857;">₹<?= number_format((float)$gstReport['total_tax_collected'], 2) ?></div>
                            </div>
                        </div>
                    </div>

                <?php else: 
                    $invValuation = get_inventory_valuation_report();
                ?>
                    <!-- 14. INVENTORY VALUATION -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Inventory Stock Valuation & Asset Summary</h2>
                        </div>
                        <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                            <div class="kpi-card" style="background: #f8fafc;">
                                <div class="kpi-title">Total Active SKUs</div>
                                <div class="kpi-value" style="font-size: 20px;"><?= (int)$invValuation['total_products'] ?></div>
                            </div>
                            <div class="kpi-card" style="background: #f8fafc;">
                                <div class="kpi-title">Total Physical Units</div>
                                <div class="kpi-value" style="font-size: 20px;"><?= (int)$invValuation['total_units_in_stock'] ?></div>
                            </div>
                            <div class="kpi-card" style="background: #f8fafc;">
                                <div class="kpi-title">Cost Value (Asset Cost)</div>
                                <div class="kpi-value" style="font-size: 20px; color: #b91c1c;">₹<?= number_format((float)$invValuation['total_cost_value'], 2) ?></div>
                            </div>
                            <div class="kpi-card" style="background: #f8fafc;">
                                <div class="kpi-title">Retail Value (Potential)</div>
                                <div class="kpi-value" style="font-size: 20px; color: #047857;">₹<?= number_format((float)$invValuation['total_retail_value'], 2) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>

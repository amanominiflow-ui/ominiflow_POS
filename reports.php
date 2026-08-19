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

$salesSummary = get_sales_summary_report($dateFrom, $dateTo);
$itemSales = get_item_sales_report($dateFrom, $dateTo, 15);
$invValuation = get_inventory_valuation_report();
$catPerformance = get_category_performance_report();
$pageTitle = 'Business Reports & Analytics';
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
                        <h1 class="page-title">Business Reports & Analytics</h1>
                        <p class="page-subtitle">Real-time metrics for sales performance, product velocity, inventory valuation, and payment settlements.</p>
                    </div>
                </div>

                <!-- Date Range Filter -->
                <div class="filter-toolbar" style="margin-bottom: 20px;">
                    <form method="GET" action="<?= asset('reports.php') ?>" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <span style="font-weight: 700; font-size: 13px; color: var(--saas-navy-950);">Reporting Period:</span>
                        <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="form-control" style="width: 140px;">
                        <span style="color: var(--saas-slate-400);">to</span>
                        <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="form-control" style="width: 140px;">
                        <button type="submit" class="header-btn" style="padding: 8px 16px;">Generate Report</button>
                    </form>
                </div>

                <!-- Sales & Revenue KPI Overview -->
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
                    <!-- Top Selling Items -->
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
                                                <td><span style="font-family: monospace;"><?= e($it['product_sku']) ?></span></td>
                                                <td style="text-align: center;"><span class="badge badge-info"><?= (int)$it['units_sold'] ?> units</span></td>
                                                <td style="text-align: right; font-weight: 700;">₹<?= number_format((float)$it['total_revenue'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Payment Method Breakdown -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Payment Settlement Breakdown</h2>
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

                <!-- GST Tax Breakdown (GSTR-1 Summary) -->
                <?php $gstReport = get_gst_tax_report($dateFrom, $dateTo); ?>
                <?php $outletReport = get_outlet_sales_report($dateFrom, $dateTo); ?>
                <div class="section-card" style="margin-bottom: 24px;">
                    <div class="section-header">
                        <h2 class="section-title">GST Tax Breakdown (GSTR-1 Ready)</h2>
                    </div>
                    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 16px;">
                        <div class="kpi-card" style="background: #f8fafc;">
                            <div class="kpi-title">Taxable Value</div>
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

                <!-- Sales by Outlet -->
                <div class="section-card" style="margin-bottom: 24px;">
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

                <!-- Inventory Valuation Section -->
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
                            <div class="kpi-title">Total Physical Stock Units</div>
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
            </main>
        </div>
    </div>
</body>
</html>

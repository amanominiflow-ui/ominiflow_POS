<?php
/**
 * OminiFlow POS - Orders & Sales History
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/orders_db.php';

require_auth();

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$highlightId = !empty($_GET['highlight']) ? (int) $_GET['highlight'] : null;

$orders = get_orders($search, $statusFilter, $dateFrom, $dateTo);
$salesStats = get_sales_stats();

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders & Sales History - OminiFlow POS</title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="app-layout">
        <!-- Sidebar Component -->
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="app-main">
            <!-- Header Component -->
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <!-- Page Top Row -->
                <div class="page-header-row">
                    <div>
                        <h1 class="page-title">Orders & Sales History</h1>
                        <p class="page-subtitle">Review POS transactions, itemized sales receipts, and cashier order logs</p>
                    </div>
                    <div class="page-actions">
                        <a href="<?= asset('pos.php') ?>" class="header-btn">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>Open POS Register</span>
                        </a>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Sales KPI Metrics Grid -->
                <section class="kpi-grid" aria-label="Sales Metrics">
                    <!-- Total Revenue -->
                    <div class="kpi-card">
                        <div class="kpi-top">
                            <span class="kpi-label">Total Sales Volume</span>
                            <div class="kpi-icon-wrap icon-sales" aria-hidden="true">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value">₹<?= number_format($salesStats['total_revenue'], 2) ?></div>
                        <div class="kpi-footer">
                            <span class="trend-badge trend-up">Lifetime Volume</span>
                        </div>
                    </div>

                    <!-- Today's Sales -->
                    <div class="kpi-card">
                        <div class="kpi-top">
                            <span class="kpi-label">Today's Sales</span>
                            <div class="kpi-icon-wrap icon-orders" aria-hidden="true">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value">₹<?= number_format($salesStats['today_revenue'], 2) ?></div>
                        <div class="kpi-footer">
                            <span class="trend-badge trend-neutral"><?= $salesStats['today_orders'] ?> Orders Today</span>
                        </div>
                    </div>

                    <!-- Total Orders Count -->
                    <div class="kpi-card">
                        <div class="kpi-top">
                            <span class="kpi-label">Completed Orders</span>
                            <div class="kpi-icon-wrap icon-products" aria-hidden="true">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value"><?= number_format($salesStats['total_orders']) ?></div>
                        <div class="kpi-footer">
                            <span>Across all cashiers</span>
                        </div>
                    </div>

                    <!-- Customers Count -->
                    <div class="kpi-card">
                        <div class="kpi-top">
                            <span class="kpi-label">Active Customers</span>
                            <div class="kpi-icon-wrap icon-customers" aria-hidden="true">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value"><?= number_format($salesStats['total_customers']) ?></div>
                        <div class="kpi-footer">
                            <span class="trend-badge trend-neutral">In-Store CRM</span>
                        </div>
                    </div>
                </section>

                <!-- Filter Bar -->
                <div class="filter-card">
                    <form method="GET" action="<?= asset('orders.php') ?>" class="filter-form">
                        <div class="search-input-wrap">
                            <span class="search-icon">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </span>
                            <input
                                type="text"
                                name="q"
                                value="<?= e($search) ?>"
                                placeholder="Search by Order #, Customer name, or phone..."
                                class="form-control with-icon"
                            >
                        </div>

                        <select name="status" class="form-control filter-select">
                            <option value="">All Statuses</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>

                        <input
                            type="date"
                            name="date_from"
                            value="<?= e($dateFrom) ?>"
                            class="form-control filter-select"
                            title="From Date"
                        >

                        <input
                            type="date"
                            name="date_to"
                            value="<?= e($dateTo) ?>"
                            class="form-control filter-select"
                            title="To Date"
                        >

                        <button type="submit" class="btn-filter-submit">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                            </svg>
                            <span>Filter</span>
                        </button>

                        <?php if ($search !== '' || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                            <a href="<?= asset('orders.php') ?>" class="btn-filter-clear">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Orders Table -->
                <div class="section-card">
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Invoice #</th>
                                    <th>Date & Time</th>
                                    <th>Customer</th>
                                    <th>Cashier</th>
                                    <th>Items</th>
                                    <th>Payment</th>
                                    <th>Total (₹)</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="10">
                                            <div class="empty-state">
                                                <div class="empty-state-icon">🧾</div>
                                                <div style="font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">No orders found</div>
                                                <div>Place your first sale in the POS Register or adjust your search filters.</div>
                                                <div style="margin-top: 14px;">
                                                    <a href="<?= asset('pos.php') ?>" class="header-btn" style="display: inline-flex;">Open POS Register</a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $ord): ?>
                                        <tr style="<?= ($highlightId && (int)$ord['id'] === $highlightId) ? 'background: #eff6ff;' : '' ?>">
                                            <td>
                                                <strong style="font-family: monospace; color: var(--saas-primary); font-size: 13.5px;"><?= e($ord['order_number']) ?></strong>
                                            </td>
                                            <td>
                                                <?php if (!empty($ord['invoice_id'])): ?>
                                                    <a href="<?= asset('invoice-view.php?id=' . $ord['invoice_id']) ?>" style="font-weight: 700; color: #1e3a8a; text-decoration: none; font-size: 12.5px;">
                                                        <?= e($ord['invoice_number']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8; font-size: 12px;">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color: var(--saas-slate-500); font-size: 12.5px; white-space: nowrap;">
                                                <?= date('M d, Y • h:i A', strtotime($ord['created_at'])) ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 700; color: var(--saas-navy-950);"><?= e($ord['customer_name'] ?: 'Walk-in Customer') ?></div>
                                                <?php if (!empty($ord['customer_phone'])): ?>
                                                    <div style="font-size: 11px; color: var(--saas-slate-400);"><?= e($ord['customer_phone']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span style="font-size: 12.5px; color: var(--saas-slate-600); font-weight: 600;">
                                                    <?= e($ord['cashier_name'] ?: 'Cashier') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-info"><?= (int)$ord['items_count'] ?> items</span>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary" style="text-transform: uppercase; font-size: 11px;">
                                                    <?= e($ord['payment_method']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong style="color: var(--saas-navy-950); font-size: 14.5px;">₹<?= number_format((float)$ord['total_amount'], 2) ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($ord['order_status'] === 'completed'): ?>
                                                    <span class="badge badge-success">Completed</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger"><?= e(ucfirst($ord['order_status'])) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <div style="display: inline-flex; align-items: center; gap: 4px;">
                                                    <button
                                                        type="button"
                                                        class="btn-action edit open-receipt-btn"
                                                        data-id="<?= $ord['id'] ?>"
                                                        title="View Receipt"
                                                    >
                                                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                        </svg>
                                                    </button>
                                                    <?php if ($ord['order_status'] === 'completed'): ?>
                                                        <a
                                                            href="<?= asset('returns.php?search=' . urlencode($ord['order_number'])) ?>"
                                                            class="btn-action delete"
                                                            style="color: #b91c1c;"
                                                            title="Process Return / Refund"
                                                        >
                                                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H4m0 0l3-3m-3 3l3 3m5 4v1a3 3 0 003 3h6a3 3 0 003-3V7a3 3 0 00-3-3h-6a3 3 0 00-3 3v1"/>
                                                            </svg>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
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

    <!-- Receipt Details Modal -->
    <div class="modal-overlay" id="receiptModal">
        <div class="modal-box" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">Sales Receipt Details</h3>
                <button type="button" class="modal-close-btn" id="closeReceiptModal">&times;</button>
            </div>
            <div class="modal-body" id="receiptModalBody">
                <div style="text-align: center; padding: 20px;">Loading receipt...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelReceiptModal">Close</button>
            </div>
        </div>
    </div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('receiptModal');
            const closeBtn = document.getElementById('closeReceiptModal');
            const cancelBtn = document.getElementById('cancelReceiptModal');
            const modalBody = document.getElementById('receiptModalBody');

            function closeModal() {
                modal.classList.remove('open');
            }
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

            document.querySelectorAll('.open-receipt-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const orderId = this.getAttribute('data-id');
                    modalBody.innerHTML = '<div style="text-align:center; padding: 20px;">Loading receipt details...</div>';
                    modal.classList.add('open');

                    fetch('<?= asset('orders.php?fetch_receipt=1&id=') ?>' + orderId)
                        .then(r => r.text())
                        .then(html => {
                            modalBody.innerHTML = html;
                        })
                        .catch(err => {
                            modalBody.innerHTML = '<div style="color:red; text-align:center;">Failed to load receipt.</div>';
                        });
                });
            });

            <?php if ($highlightId): ?>
                // Automatically open receipt modal for newly placed order!
                const highlightBtn = document.querySelector('.open-receipt-btn[data-id="<?= $highlightId ?>"]');
                if (highlightBtn) {
                    highlightBtn.click();
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>
<?php
// Handle AJAX Receipt Partial Rendering
if (!empty($_GET['fetch_receipt']) && !empty($_GET['id'])) {
    $receiptOrder = get_order_by_id((int)$_GET['id']);
    if (!$receiptOrder) {
        echo '<div style="color:red; text-align:center;">Order not found.</div>';
        exit;
    }
    ?>
    <div class="receipt-paper">
        <div style="text-align: center; border-bottom: 1px dashed #cbd5e1; padding-bottom: 12px; margin-bottom: 12px;">
            <div style="font-size: 16px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">OMINIFLOW POS</div>
            <div style="font-size: 11px; color: #64748b; margin-top: 2px;">Official Sales Receipt</div>
            <div style="font-size: 12px; font-weight: 700; margin-top: 6px;"><?= e($receiptOrder['order_number']) ?></div>
            <div style="font-size: 11px; color: #64748b;"><?= date('M d, Y • h:i:s A', strtotime($receiptOrder['created_at'])) ?></div>
        </div>

        <div style="font-size: 12px; margin-bottom: 12px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 8px;">
            <div>Customer: <strong><?= e($receiptOrder['customer_name'] ?: 'Walk-in') ?></strong></div>
            <?php if (!empty($receiptOrder['customer_phone'])): ?>
                <div>Phone: <?= e($receiptOrder['customer_phone']) ?></div>
            <?php endif; ?>
            <div>Cashier: <?= e($receiptOrder['cashier_name'] ?: 'Cashier') ?></div>
            <div>Payment: <span style="text-transform: uppercase; font-weight: 700;"><?= e($receiptOrder['payment_method']) ?></span></div>
        </div>

        <table style="width: 100%; font-size: 12px; border-collapse: collapse; margin-bottom: 12px;">
            <thead>
                <tr style="border-bottom: 1px solid #94a3b8; text-align: left;">
                    <th style="padding: 4px 0;">Item</th>
                    <th style="padding: 4px 0; text-align: center;">Qty</th>
                    <th style="padding: 4px 0; text-align: right;">Price</th>
                    <th style="padding: 4px 0; text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($receiptOrder['items'] as $it): ?>
                    <tr style="border-bottom: 1px dotted #e2e8f0;">
                        <td style="padding: 6px 0;">
                            <div style="font-weight: 600;"><?= e($it['product_name']) ?></div>
                            <div style="font-size: 10px; color: #64748b;">SKU: <?= e($it['product_sku']) ?></div>
                        </td>
                        <td style="padding: 6px 0; text-align: center;"><?= (int)$it['quantity'] ?></td>
                        <td style="padding: 6px 0; text-align: right;">₹<?= number_format((float)$it['unit_price'], 2) ?></td>
                        <td style="padding: 6px 0; text-align: right; font-weight: 700;">₹<?= number_format((float)$it['line_total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="font-size: 12px; border-top: 1px dashed #cbd5e1; padding-top: 8px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 3px;">
                <span>Subtotal:</span>
                <span>₹<?= number_format((float)$receiptOrder['subtotal'], 2) ?></span>
            </div>
            <?php if ((float)$receiptOrder['discount_amount'] > 0): ?>
                <div style="display: flex; justify-content: space-between; margin-bottom: 3px; color: #b91c1c;">
                    <span>Discount:</span>
                    <span>− ₹<?= number_format((float)$receiptOrder['discount_amount'], 2) ?></span>
                </div>
            <?php endif; ?>
            <div style="display: flex; justify-content: space-between; margin-bottom: 3px;">
                <span>Tax:</span>
                <span>₹<?= number_format((float)$receiptOrder['tax_amount'], 2) ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 15px; font-weight: 800; margin-top: 6px; border-top: 1px solid #111827; padding-top: 6px;">
                <span>GRAND TOTAL:</span>
                <span>₹<?= number_format((float)$receiptOrder['total_amount'], 2) ?></span>
            </div>
        </div>

        <div style="text-align: center; margin-top: 14px; font-size: 11px; color: #64748b;">
            Thank you for shopping with us!
        </div>
    </div>
    <?php
    exit;
}
?>

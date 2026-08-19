<?php
/**
 * Omnichannel Order Fulfillment & Dispatch Workflow (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/orders_db.php';
require_once __DIR__ . '/includes/db.php';

require_auth();

$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$db = get_db();

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_fulfillment') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/fulfillment.php');
    } else {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $status = $_POST['fulfillment_status'] ?? 'pending';

        $validStatuses = ['pending', 'packed', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled'];
        if (in_array($status, $validStatuses, true)) {
            $stmt = $db->prepare('UPDATE orders SET fulfillment_status = :st, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['st' => $status, 'id' => $orderId]);
            set_flash('success', "Order #{$orderId} fulfillment status updated to " . strtoupper(str_replace('_', ' ', $status)));
        } else {
            set_flash('error', 'Invalid status specified.');
        }
        redirect(APP_URL . '/fulfillment.php');
    }
}

// Fetch orders with fulfillment status
$stmt = $db->query('
    SELECT o.id, o.order_number, o.total_amount, o.fulfillment_status, o.created_at,
           c.name AS customer_name, c.phone AS customer_phone
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    ORDER BY o.id DESC
    LIMIT 50
');
$orders = $stmt->fetchAll();
$pageTitle = 'Order Fulfillment & Dispatch';
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
                        <h1 class="page-title">Order Fulfillment & Dispatch</h1>
                        <p class="page-subtitle">Track and progress omnichannel orders (Pending &rarr; Packed &rarr; Ready for Pickup &rarr; Shipped &rarr; Delivered).</p>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success" style="margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger" style="margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title">Active Orders Fulfillment Pipeline</h2>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Order Date</th>
                                    <th style="text-align: right;">Total Amount</th>
                                    <th>Current Status</th>
                                    <th style="text-align: right;">Update State</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr><td colspan="6" style="text-align: center; padding: 20px; color: #64748b;">No orders found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $ord): ?>
                                        <tr>
                                            <td><strong style="font-family: monospace;"><?= e($ord['order_number']) ?></strong></td>
                                            <td>
                                                <div style="font-weight: 700; color: var(--saas-navy-950);"><?= e($ord['customer_name'] ?: 'Walk-in Customer') ?></div>
                                                <div style="font-size: 11px; color: var(--saas-slate-400);"><?= e($ord['customer_phone'] ?: '') ?></div>
                                            </td>
                                            <td style="font-size: 12px; color: var(--saas-slate-500);"><?= date('d M Y, h:i A', strtotime($ord['created_at'])) ?></td>
                                            <td style="text-align: right; font-weight: 700; color: #047857;">₹<?= number_format((float)$ord['total_amount'], 2) ?></td>
                                            <td>
                                                <?php
                                                $fst = $ord['fulfillment_status'] ?: 'pending';
                                                $badge = 'badge-secondary';
                                                if ($fst === 'packed') $badge = 'badge-warning';
                                                elseif ($fst === 'shipped') $badge = 'badge-info';
                                                elseif ($fst === 'delivered') $badge = 'badge-success';
                                                ?>
                                                <span class="badge <?= $badge ?>">
                                                    <?= strtoupper(str_replace('_', ' ', $fst)) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right;">
                                                <form method="POST" action="<?= asset('fulfillment.php') ?>" style="display: inline-flex; gap: 6px; align-items: center;">
                                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                    <input type="hidden" name="action" value="update_fulfillment">
                                                    <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                                    <select name="fulfillment_status" class="form-control" style="font-size: 11.5px; padding: 4px 8px; width: auto;">
                                                        <option value="pending" <?= $fst === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                        <option value="packed" <?= $fst === 'packed' ? 'selected' : '' ?>>Packed</option>
                                                        <option value="ready_for_pickup" <?= $fst === 'ready_for_pickup' ? 'selected' : '' ?>>Ready for Pickup</option>
                                                        <option value="shipped" <?= $fst === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                                                        <option value="delivered" <?= $fst === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                                    </select>
                                                    <button type="submit" class="header-btn" style="padding: 4px 10px; font-size: 11.5px;">Update</button>
                                                </form>
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
</body>
</html>

<?php
/**
 * Promotions, Coupons & Loyalty Program Management (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/promotions_db.php';

require_auth();

$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/promotions.php');
    } else {
        $action = $_POST['action'] ?? '';
        $db = get_db();

        if ($action === 'create_promo') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['promo_type'] ?? 'percentage';
            $val = (float)($_POST['discount_value'] ?? 0);
            $buy = (int)($_POST['buy_qty'] ?? 0);
            $get = (int)($_POST['get_qty'] ?? 0);
            $min = (float)($_POST['min_order_amount'] ?? 0);

            if ($name === '') {
                set_flash('error', 'Promotion name is required.');
            } else {
                $stmt = $db->prepare('
                    INSERT INTO promotions (name, promo_type, discount_value, buy_qty, get_qty, min_order_amount, status, created_at, updated_at)
                    VALUES (:name, :type, :val, :buy, :get, :min, "active", NOW(), NOW())
                ');
                $stmt->execute(['name' => $name, 'type' => $type, 'val' => $val, 'buy' => $buy, 'get' => $get, 'min' => $min]);
                set_flash('success', "Promotion '{$name}' created successfully!");
            }
        } elseif ($action === 'create_coupon') {
            $code = strtoupper(trim($_POST['code'] ?? ''));
            $type = $_POST['discount_type'] ?? 'fixed';
            $val = (float)($_POST['discount_value'] ?? 0);
            $min = (float)($_POST['min_order_amount'] ?? 0);
            $limit = (int)($_POST['usage_limit'] ?? 100);

            if ($code === '') {
                set_flash('error', 'Coupon code is required.');
            } else {
                try {
                    $stmt = $db->prepare('
                        INSERT INTO coupons (code, discount_type, discount_value, min_order_amount, usage_limit, status, created_at, updated_at)
                        VALUES (:code, :type, :val, :min, :limit, "active", NOW(), NOW())
                    ');
                    $stmt->execute(['code' => $code, 'type' => $type, 'val' => $val, 'min' => $min, 'limit' => $limit]);
                    set_flash('success', "Coupon '{$code}' created successfully!");
                } catch (PDOException $e) {
                    set_flash('error', "Error: Coupon code '{$code}' already exists.");
                }
            }
        }
        redirect(APP_URL . '/promotions.php');
    }
}

$promotions = get_promotions();
$coupons = get_coupons();
$customerGroups = get_customer_groups();
$pageTitle = 'Promotions, Coupons & Loyalty';
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
                        <h1 class="page-title">Promotions, Coupons & Loyalty</h1>
                        <p class="page-subtitle">Configure discount rules, promotional coupon codes, customer tiers and loyalty rewards.</p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" onclick="document.getElementById('promoModal').style.display='flex'" class="header-btn">
                            + New Promotion
                        </button>
                        <button type="button" onclick="document.getElementById('couponModal').style.display='flex'" class="header-btn-secondary">
                            + New Coupon Code
                        </button>
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

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                    <!-- Active Promotions -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Active Store Promotions</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Promo Name</th>
                                        <th>Type</th>
                                        <th>Discount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($promotions)): ?>
                                        <tr><td colspan="4" style="text-align: center; padding: 20px; color: #64748b;">No promotions configured.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($promotions as $p): ?>
                                            <tr>
                                                <td><strong><?= e($p['name']) ?></strong></td>
                                                <td><span style="font-family: monospace; font-size: 11px;"><?= strtoupper(str_replace('_', ' ', $p['promo_type'])) ?></span></td>
                                                <td style="color: #047857; font-weight: 700;">
                                                    <?= $p['promo_type'] === 'percentage' ? $p['discount_value'] . '%' : ($p['promo_type'] === 'buy_x_get_y' ? "Buy {$p['buy_qty']} Get {$p['get_qty']}" : '₹' . $p['discount_value']) ?>
                                                </td>
                                                <td><span class="badge badge-success">Active</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Coupons -->
                    <div class="section-card">
                        <div class="section-header">
                            <h2 class="section-title">Coupon Codes</h2>
                        </div>
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Discount</th>
                                        <th>Usage</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($coupons)): ?>
                                        <tr><td colspan="4" style="text-align: center; padding: 20px; color: #64748b;">No coupons active.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($coupons as $c): ?>
                                            <tr>
                                                <td><strong style="font-family: monospace; color: var(--saas-primary);"><?= e($c['code']) ?></strong></td>
                                                <td style="font-weight: 700;">
                                                    <?= $c['discount_type'] === 'percent' ? $c['discount_value'] . '%' : '₹' . $c['discount_value'] ?>
                                                </td>
                                                <td style="font-size: 12px; color: var(--saas-slate-500);"><?= $c['usage_count'] ?> / <?= $c['usage_limit'] ?></td>
                                                <td><span class="badge badge-success"><?= e($c['status']) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Customer Groups -->
                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title">Customer Groups & Loyalty Tiers</h2>
                    </div>
                    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-top: 12px;">
                        <?php foreach ($customerGroups as $cg): ?>
                            <div class="kpi-card" style="background: #f8fafc; border: 1px solid var(--saas-border);">
                                <div style="font-size: 15px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;"><?= e($cg['name']) ?></div>
                                <div style="font-size: 11px; font-family: monospace; color: var(--saas-slate-400); margin-bottom: 8px;">Code: <?= e($cg['code']) ?></div>
                                <div style="font-size: 12px; color: var(--saas-slate-600); border-top: 1px solid var(--saas-border-light); padding-top: 8px;">
                                    <div><strong>Default Discount:</strong> <?= (float)$cg['discount_percent'] ?>%</div>
                                    <div><strong>Credit Limit:</strong> ₹<?= number_format((float)$cg['credit_limit'], 2) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Promo Modal Backdrop -->
    <div id="promoModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2000; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: var(--saas-radius-lg); width: 100%; max-width: 480px; padding: 24px; box-shadow: var(--saas-shadow-lg);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950);">Create Promotion</h3>
                <button type="button" onclick="document.getElementById('promoModal').style.display='none'" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--saas-slate-400);">&times;</button>
            </div>
            <form method="POST" action="<?= asset('promotions.php') ?>" style="display: flex; flex-direction: column; gap: 12px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="create_promo">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Promotion Name *</label>
                    <input type="text" name="name" required class="form-control" placeholder="e.g. Festival 10% Off" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Promotion Type</label>
                    <select name="promo_type" class="form-control" style="width: 100%;">
                        <option value="percentage">Percentage Discount (%)</option>
                        <option value="fixed_amount">Fixed Amount Discount (₹)</option>
                        <option value="buy_x_get_y">Buy X Get Y Free</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Discount Value</label>
                    <input type="number" step="0.01" name="discount_value" class="form-control" placeholder="e.g. 10" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Min Order Amount (₹)</label>
                    <input type="number" step="0.01" name="min_order_amount" class="form-control" placeholder="0.00" style="width: 100%;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px;">
                    <button type="button" onclick="document.getElementById('promoModal').style.display='none'" class="header-btn-secondary" style="padding: 8px 16px;">Cancel</button>
                    <button type="submit" class="header-btn" style="padding: 8px 18px;">Create Promo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Coupon Modal Backdrop -->
    <div id="couponModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2000; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: var(--saas-radius-lg); width: 100%; max-width: 480px; padding: 24px; box-shadow: var(--saas-shadow-lg);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950);">Create Coupon Code</h3>
                <button type="button" onclick="document.getElementById('couponModal').style.display='none'" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--saas-slate-400);">&times;</button>
            </div>
            <form method="POST" action="<?= asset('promotions.php') ?>" style="display: flex; flex-direction: column; gap: 12px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="create_coupon">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Coupon Code *</label>
                    <input type="text" name="code" required class="form-control" placeholder="e.g. SAVE100" style="width: 100%; text-transform: uppercase;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Discount Type</label>
                    <select name="discount_type" class="form-control" style="width: 100%;">
                        <option value="fixed">Fixed Amount (₹)</option>
                        <option value="percent">Percentage (%)</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Discount Value *</label>
                    <input type="number" step="0.01" name="discount_value" required class="form-control" placeholder="100.00" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Min Order Amount (₹)</label>
                    <input type="number" step="0.01" name="min_order_amount" class="form-control" placeholder="500.00" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Max Usage Limit</label>
                    <input type="number" name="usage_limit" value="100" class="form-control" style="width: 100%;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px;">
                    <button type="button" onclick="document.getElementById('couponModal').style.display='none'" class="header-btn-secondary" style="padding: 8px 16px;">Cancel</button>
                    <button type="submit" class="header-btn" style="padding: 8px 18px;">Save Coupon</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

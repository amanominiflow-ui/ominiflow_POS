<?php
/**
 * Multi-Outlet & Warehouse Management Screen (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/outlets_db.php';

require_auth();

$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle Outlet creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_outlet') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/outlets.php');
    } else {
        $id = !empty($_POST['outlet_id']) ? (int)$_POST['outlet_id'] : null;
        $res = save_outlet([
            'name' => $_POST['name'] ?? '',
            'code' => $_POST['code'] ?? '',
            'address' => $_POST['address'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'gstin' => $_POST['gstin'] ?? '',
            'status' => $_POST['status'] ?? 'active',
        ], $id);

        if ($res['success']) {
            set_flash('success', 'Outlet saved successfully!');
        } else {
            set_flash('error', $res['error'] ?? 'Failed to save outlet.');
        }
        redirect(APP_URL . '/outlets.php');
    }
}

$outlets = get_outlets();
$warehouses = get_warehouses();
$pageTitle = 'Multi-Outlet & Warehouses';
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
                        <h1 class="page-title">Multi-Outlet & Warehouses</h1>
                        <p class="page-subtitle">Manage retail branches, regional store outlets, and central inventory warehouses.</p>
                    </div>
                    <div>
                        <button type="button" onclick="document.getElementById('outletModal').style.display='flex'" class="header-btn">
                            + Add New Outlet
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

                <!-- Outlets Grid Cards -->
                <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-bottom: 24px;">
                    <?php foreach ($outlets as $ot): ?>
                        <div class="kpi-card" style="background: #ffffff; border: 1px solid var(--saas-border); display: flex; flex-direction: column; gap: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span class="badge <?= $ot['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= strtoupper($ot['status']) ?>
                                </span>
                                <span style="font-family: monospace; font-size: 11px; color: var(--saas-slate-500);"><?= e($ot['code']) ?></span>
                            </div>
                            <div style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950); margin-top: 4px;"><?= e($ot['name']) ?></div>
                            <div style="font-size: 12px; color: var(--saas-slate-600);"><?= e($ot['address'] ?: 'No address specified') ?></div>
                            <div style="border-top: 1px solid var(--saas-border-light); padding-top: 8px; margin-top: 6px; font-size: 11.5px; color: var(--saas-slate-500);">
                                <div><strong>Phone:</strong> <?= e($ot['phone'] ?: 'N/A') ?></div>
                                <div><strong>GSTIN:</strong> <?= e($ot['gstin'] ?: 'N/A') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Warehouses Section -->
                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title">Inventory Warehouses</h2>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Warehouse Name</th>
                                    <th>Code</th>
                                    <th>Linked Outlet</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($warehouses)): ?>
                                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #64748b;">No warehouses found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($warehouses as $wh): ?>
                                        <tr>
                                            <td><strong><?= e($wh['name']) ?></strong></td>
                                            <td><span style="font-family: monospace;"><?= e($wh['code']) ?></span></td>
                                            <td><?= e($wh['outlet_name'] ?? 'General') ?></td>
                                            <td style="color: var(--saas-slate-500);"><?= e($wh['location'] ?: 'Floor 1') ?></td>
                                            <td>
                                                <span class="badge <?= $wh['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                                    <?= e($wh['status']) ?>
                                                </span>
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

    <!-- Add Outlet Modal Backdrop -->
    <div id="outletModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2000; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: var(--saas-radius-lg); width: 100%; max-width: 480px; padding: 24px; box-shadow: var(--saas-shadow-lg);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950);">Create New Business Outlet</h3>
                <button type="button" onclick="document.getElementById('outletModal').style.display='none'" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--saas-slate-400);">&times;</button>
            </div>
            <form method="POST" action="<?= asset('outlets.php') ?>" style="display: flex; flex-direction: column; gap: 12px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="save_outlet">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Outlet Name *</label>
                    <input type="text" name="name" required class="form-control" placeholder="e.g. South Bangalore Branch" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Outlet Code</label>
                    <input type="text" name="code" class="form-control" placeholder="e.g. OUT-BLR-02" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">GSTIN</label>
                    <input type="text" name="gstin" class="form-control" placeholder="e.g. 29ABCDE1234F1Z5" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Phone</label>
                    <input type="text" name="phone" class="form-control" placeholder="+91 98765 00000" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Address</label>
                    <textarea name="address" rows="2" class="form-control" placeholder="Full physical address" style="width: 100%;"></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px;">
                    <button type="button" onclick="document.getElementById('outletModal').style.display='none'" class="header-btn-secondary" style="padding: 8px 16px;">Cancel</button>
                    <button type="submit" class="header-btn" style="padding: 8px 18px;">Save Outlet</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

<?php
/**
 * OminiFlow POS - Vendor Management Screen (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/purchases_db.php';

require_auth();

$pageTitle = 'Vendors & Suppliers Directory';

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/vendors.php');
    }

    $id = !empty($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : null;
    $res = save_vendor($_POST, $id);
    if ($res['success']) {
        set_flash('success', $id ? 'Vendor updated successfully!' : 'Vendor added successfully!');
    } else {
        set_flash('error', $res['error'] ?? 'Could not save vendor.');
    }
    redirect(APP_URL . '/vendors.php');
}

$search = trim($_GET['search'] ?? '');
$vendors = get_vendors($search);

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
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
                        <h1 class="page-title">Vendors & Suppliers</h1>
                        <p class="page-subtitle">Manage wholesale suppliers, distributor contacts, GSTIN tax credentials, and purchase orders.</p>
                    </div>
                    <div>
                        <button type="button" class="header-btn" id="openAddVendorBtn" style="padding: 10px 20px; display: inline-flex; align-items: center; gap: 8px;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                            <span>Add New Vendor</span>
                        </button>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Search & Filter Card -->
                <div class="filter-card" style="padding: 16px 20px; margin-bottom: 24px;">
                    <form method="GET" action="<?= asset('vendors.php') ?>" style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 240px;">
                            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by vendor name, company, GSTIN, phone..." class="form-control">
                        </div>
                        <button type="submit" class="header-btn" style="padding: 8px 18px;">Search Vendors</button>
                        <?php if ($search !== ''): ?>
                            <a href="<?= asset('vendors.php') ?>" class="btn-secondary" style="padding: 8px 14px;">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h2 class="section-heading">All Vendors & Suppliers (<?= count($vendors) ?>)</h2>
                            <p class="section-subheading">Active wholesale distributors and supply partners</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Vendor Name</th>
                                    <th>Company / Business</th>
                                    <th>Contact Details</th>
                                    <th>GSTIN</th>
                                    <th>Payment Terms</th>
                                    <th>Total POs</th>
                                    <th>Procurement Volume</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($vendors)): ?>
                                    <tr><td colspan="8" style="text-align: center; padding: 32px; color: #64748b;">No vendors found. Click "+ Add New Vendor" to add your first supplier.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($vendors as $v): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 700; color: var(--saas-navy-950); font-size: 13.5px;"><?= e($v['name']) ?></div>
                                            </td>
                                            <td><strong><?= e($v['company_name'] ?: '—') ?></strong></td>
                                            <td>
                                                <div style="font-size: 12.5px; color: var(--saas-navy-900);"><?= e($v['phone'] ?: '—') ?></div>
                                                <div style="font-size: 11px; color: var(--saas-slate-400);"><?= e($v['email'] ?: '') ?></div>
                                            </td>
                                            <td><span style="font-family: monospace; font-size: 12px; font-weight: 600; color: var(--saas-slate-600);"><?= e($v['gstin'] ?: '—') ?></span></td>
                                            <td><span class="badge badge-secondary"><?= e($v['payment_terms']) ?></span></td>
                                            <td><span class="badge badge-info"><?= (int)$v['total_orders'] ?> POs</span></td>
                                            <td><strong style="font-size: 13.5px; color: var(--saas-navy-950);">₹<?= number_format((float)$v['total_purchased'], 2) ?></strong></td>
                                            <td><span class="badge badge-success"><?= ucfirst($v['status']) ?></span></td>
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

    <!-- ADD VENDOR MODAL -->
    <div class="modal-overlay" id="vendorModal">
        <div class="modal-box" style="max-width: 540px;">
            <div class="modal-header">
                <h3 class="modal-title">Add Supplier / Vendor</h3>
                <button type="button" class="modal-close-btn" id="closeVendorModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('vendors.php') ?>">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label">Vendor / Contact Person Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="name" required placeholder="e.g. Apex Wholesale Supplies" class="form-control">
                    </div>
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label">Company / Legal Entity</label>
                        <input type="text" name="company_name" placeholder="e.g. Apex Logistics Ltd" class="form-control">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" placeholder="+91 99000 11223" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" placeholder="vendor@example.com" class="form-control">
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                        <div class="form-group">
                            <label class="form-label">GSTIN</label>
                            <input type="text" name="gstin" placeholder="29AAACA1234B1Z5" class="form-control" style="text-transform: uppercase;">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Payment Terms</label>
                            <select name="payment_terms" class="form-control">
                                <option value="Due on Receipt">Due on Receipt</option>
                                <option value="Net 15">Net 15 Days</option>
                                <option value="Net 30" selected>Net 30 Days</option>
                                <option value="Net 60">Net 60 Days</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address / Warehouse Location</label>
                        <input type="text" name="address" placeholder="e.g. Sector 4, Industrial Area, Bangalore" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelVendorModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0; padding: 9px 20px;">Save Vendor</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        const vModal = document.getElementById('vendorModal');
        const openVBtn = document.getElementById('openAddVendorBtn');
        if (openVBtn) openVBtn.addEventListener('click', () => vModal.classList.add('open'));
        const closeVBtn = document.getElementById('closeVendorModal');
        if (closeVBtn) closeVBtn.addEventListener('click', () => vModal.classList.remove('open'));
        const cancelVBtn = document.getElementById('cancelVendorModal');
        if (cancelVBtn) cancelVBtn.addEventListener('click', () => vModal.classList.remove('open'));
    </script>
</body>
</html>

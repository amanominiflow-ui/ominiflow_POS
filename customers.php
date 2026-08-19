<?php
/**
 * OminiFlow POS - Customer CRM & Receivables Ledger Screen (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';

require_auth();

$db = get_db();

// Handle Customer Creation & Credit Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token.');
        redirect(APP_URL . '/customers.php');
    }

    if ($action === 'save_customer') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '') {
            set_flash('error', 'Customer name is required.');
        } else {
            $stmt = $db->prepare('
                INSERT INTO customers (name, phone, email, address, created_at, updated_at)
                VALUES (:name, :phone, :email, :address, NOW(), NOW())
            ');
            $stmt->execute([
                'name' => $name,
                'phone' => $phone ?: null,
                'email' => $email ?: null,
                'address' => $address ?: null,
            ]);
            set_flash('success', "Customer '{$name}' created successfully!");
        }
        redirect(APP_URL . '/customers.php');
    }
}

$search = trim($_GET['search'] ?? '');
$sql = '
    SELECT c.*,
           (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.order_status = "completed") AS total_orders,
           (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.customer_id = c.id AND o.order_status = "completed") AS total_spent
    FROM customers c
    WHERE 1=1
';
$params = [];
if ($search !== '') {
    $sql .= ' AND (c.name LIKE :s1 OR c.phone LIKE :s2 OR c.email LIKE :s3)';
    $params['s1'] = "%{$search}%";
    $params['s2'] = "%{$search}%";
    $params['s3'] = "%{$search}%";
}
$sql .= ' ORDER BY c.id ASC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer CRM & Receivables - OminiFlow POS</title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <div class="page-top-header">
                    <div>
                        <h1 class="page-title">Customer CRM & Profiles</h1>
                        <p class="page-subtitle">Manage customer profiles, purchase histories, and lifetime value tracking.</p>
                    </div>
                    <div class="page-top-actions">
                        <button type="button" class="header-btn" id="openAddCustomerBtn">
                            <span>+ Add New Customer</span>
                        </button>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span><?= e($flashSuccess) ?></span></div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span><?= e($flashError) ?></span></div>
                <?php endif; ?>

                <div class="section-card">
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Customer Name</th>
                                    <th>Phone Number</th>
                                    <th>Email Address</th>
                                    <th>Address</th>
                                    <th>Total Purchases</th>
                                    <th>Lifetime Value (₹)</th>
                                    <th>Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($customers)): ?>
                                    <tr><td colspan="7" style="text-align: center; padding: 24px; color: #64748b;">No customers found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($customers as $c): ?>
                                        <tr>
                                            <td><strong><?= e($c['name']) ?></strong></td>
                                            <td><?= e($c['phone'] ?: '—') ?></td>
                                            <td><?= e($c['email'] ?: '—') ?></td>
                                            <td><?= e($c['address'] ?: '—') ?></td>
                                            <td><span class="badge badge-info"><?= (int)$c['total_orders'] ?> orders</span></td>
                                            <td><strong style="color: #047857; font-size: 14px;">₹<?= number_format((float)$c['total_spent'], 2) ?></strong></td>
                                            <td style="font-size: 12px; color: var(--saas-slate-500);"><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
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

    <!-- ADD CUSTOMER MODAL -->
    <div class="modal-overlay" id="addCustomerModal">
        <div class="modal-box" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">Add Customer Profile</h3>
                <button type="button" class="modal-close-btn" id="closeCustModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('customers.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_customer">
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Full Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="name" required placeholder="e.g. Ramesh Kumar" class="form-control">
                    </div>
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" placeholder="+91 98765 43210" class="form-control">
                    </div>
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" placeholder="customer@example.com" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" placeholder="e.g. Indiranagar, Bangalore" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelCustModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0;">Save Customer</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        const cModal = document.getElementById('addCustomerModal');
        const openCBtn = document.getElementById('openAddCustomerBtn');
        if (openCBtn) openCBtn.addEventListener('click', () => cModal.classList.add('open'));
        const closeCBtn = document.getElementById('closeCustModal');
        if (closeCBtn) closeCBtn.addEventListener('click', () => cModal.classList.remove('open'));
        const cancelCBtn = document.getElementById('cancelCustModal');
        if (cancelCBtn) cancelCBtn.addEventListener('click', () => cModal.classList.remove('open'));
    </script>
</body>
</html>

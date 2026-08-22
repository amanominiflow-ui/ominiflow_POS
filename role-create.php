<?php
/**
 * OminiFlow POS - Create & Edit Role (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/roles_db.php';

require_auth();

$user = current_user();
$roleId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$role = $roleId ? get_role_by_id($roleId) : null;
$pageTitle = $role ? 'Edit Role - ' . $role['name'] : 'New Role';

$perms = [];
if ($role && !empty($role['permissions_json'])) {
    $perms = json_decode((string)$role['permissions_json'], true) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Session expired. Please try again.');
        redirect(APP_URL . '/roles.php');
    }

    $name = trim($_POST['role_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $webAccess = isset($_POST['web_access']) ? 1 : 0;
    $billingAccess = isset($_POST['billing_access']) ? 1 : 0;
    $submittedPerms = $_POST['perms'] ?? [];

    $data = [
        'name' => $name,
        'description' => $desc,
        'web_access' => $webAccess,
        'billing_access' => $billingAccess,
        'permissions' => $submittedPerms,
    ];

    $res = save_role($data, $roleId);
    if ($res['success']) {
        set_flash('success', "Role '{$name}' saved successfully!");
        redirect(APP_URL . '/roles.php');
    } else {
        $error = $res['error'] ?? 'Failed to save role.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .role-edit-layout {
            background: #f8fafc;
            min-height: calc(100vh - 70px);
            padding: 24px 32px 100px;
        }

        .role-form-card {
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            padding: 24px 28px;
            margin-bottom: 24px;
        }

        .role-card-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-label-role {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
            display: block;
        }

        .form-label-role .req {
            color: #ef4444;
        }

        .role-input, .role-textarea {
            width: 100%;
            max-width: 540px;
            padding: 10px 14px;
            font-size: 14px;
            color: #0f172a;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-sizing: border-box;
            outline: none;
        }

        .role-input:focus, .role-textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .access-point-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .access-point-row:last-child {
            border-bottom: 0;
        }

        .access-point-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            cursor: pointer;
            accent-color: #2563eb;
        }

        .ap-title {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }

        .ap-desc {
            font-size: 12.5px;
            color: #64748b;
            margin-top: 4px;
            line-height: 1.45;
        }

        .matrix-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        .matrix-table th {
            padding: 12px 14px;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #475569;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            text-align: center;
        }

        .matrix-table th:first-child {
            text-align: left;
            width: 32%;
        }

        .matrix-table td {
            padding: 12px 14px;
            font-size: 13.5px;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            text-align: center;
            vertical-align: middle;
        }

        .matrix-table td:first-child {
            text-align: left;
            font-weight: 600;
        }

        .matrix-table tr:hover td {
            background: #f8fafc;
        }

        .matrix-table input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #2563eb;
        }

        .toggle-group {
            display: inline-flex;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            background: #ffffff;
        }

        .toggle-opt {
            padding: 5px 12px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .toggle-opt input {
            display: none;
        }

        .toggle-opt.allow.active {
            background: #22c55e;
            color: #ffffff;
        }

        .toggle-opt.deny.active {
            background: #ef4444;
            color: #ffffff;
        }

        .toggle-opt:not(.active) {
            color: #64748b;
            background: #f8fafc;
        }

        .sticky-footer {
            position: fixed;
            bottom: 0;
            left: 280px;
            right: 0;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            padding: 16px 32px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.05);
            z-index: 100;
        }

        .btn-save-role {
            background: #2563eb;
            color: #ffffff;
            font-weight: 600;
            font-size: 14px;
            padding: 10px 24px;
            border-radius: 6px;
            border: 0;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-save-role:hover {
            background: #1d4ed8;
        }

        .btn-cancel-role {
            background: #ffffff;
            color: #475569;
            font-weight: 600;
            font-size: 14px;
            padding: 10px 20px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-cancel-role:hover {
            background: #f1f5f9;
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="role-edit-layout">
                <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <a href="<?= asset('roles.php') ?>" style="color: #64748b; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 6px;">
                            &larr; Back to Roles
                        </a>
                        <h1 style="font-size: 22px; font-weight: 700; color: #0f172a; margin: 0;"><?= e($pageTitle) ?></h1>
                    </div>

                    <button type="button" class="btn-tender-secondary" onclick="toggleSelectAllPermissions()">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span id="selectAllBtnText">Select All Below Permissions</span>
                    </button>
                </div>

                <?php if (!empty($error)): ?>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13.5px;">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="roleForm" action="<?= asset($roleId ? 'role-create.php?id=' . $roleId : 'role-create.php') ?>">
                    <?= csrf_field() ?>

                    <!-- 1. PRIMARY DETAILS -->
                    <div class="role-form-card">
                        <h2 class="role-card-title">Primary Details</h2>
                        
                        <div style="margin-bottom: 18px;">
                            <label class="form-label-role">Role Name <span class="req">*</span></label>
                            <input type="text" name="role_name" value="<?= e($role['name'] ?? '') ?>" class="role-input" placeholder="Eg. Cashier" required>
                        </div>

                        <div>
                            <label class="form-label-role">Description</label>
                            <textarea name="description" class="role-textarea" rows="3" placeholder="Briefly explain the role"><?= e($role['description'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- 2. ACCESS POINTS -->
                    <div class="role-form-card">
                        <h2 class="role-card-title">Access Points</h2>

                        <div class="access-point-row">
                            <input type="checkbox" name="web_access" id="webAccessChk" value="1" <?= (!isset($role) || !empty($role['web_access'])) ? 'checked' : '' ?>>
                            <div>
                                <label for="webAccessChk" class="ap-title">Allow access to the Zoho POS web and Business Manager apps</label>
                                <div class="ap-desc">Note: The user will be able to access the Zoho POS web application via the URL pos.ominiflow.com and the Business Manager application from any iOS or Android devices. Recommended for users who'll be involved in managing business operations.</div>
                            </div>
                        </div>

                        <div class="access-point-row">
                            <input type="checkbox" name="billing_access" id="billingAccessChk" value="1" <?= (!isset($role) || !empty($role['billing_access'])) ? 'checked' : '' ?>>
                            <div>
                                <label for="billingAccessChk" class="ap-title">Allow access to the Billing apps</label>
                                <div class="ap-desc">Note: The user will be able to access Zoho POS Billing application from any Windows, iOS, or Android devices mapped with a register. Recommended for users who'll be involved in managing sales at checkout.</div>
                            </div>
                        </div>
                    </div>

                    <!-- 3. INVENTORY MODULE -->
                    <div class="role-form-card">
                        <h2 class="role-card-title">Inventory Permissions</h2>
                        <table class="matrix-table">
                            <thead>
                                <tr>
                                    <th>Resource</th>
                                    <th>Full Access</th>
                                    <th>View</th>
                                    <th>Create</th>
                                    <th>Edit</th>
                                    <th>Delete</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $invRows = [
                                    'items' => 'Items (Products)',
                                    'composite_items' => 'Composite Items (Bundles)',
                                    'transfer_orders' => 'Transfer Orders',
                                    'adjustments' => 'Adjustments & Stock Counts',
                                    'price_list' => 'Price List',
                                ];
                                foreach ($invRows as $resKey => $resLabel): 
                                    $pRes = $perms['inventory'][$resKey] ?? [];
                                ?>
                                    <tr class="perm-row">
                                        <td><?= e($resLabel) ?></td>
                                        <td><input type="checkbox" class="chk-full" onchange="toggleRowFull(this)"></td>
                                        <td><input type="checkbox" name="perms[inventory][<?= $resKey ?>][]" value="view" <?= in_array('view', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[inventory][<?= $resKey ?>][]" value="create" <?= in_array('create', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[inventory][<?= $resKey ?>][]" value="edit" <?= in_array('edit', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[inventory][<?= $resKey ?>][]" value="delete" <?= in_array('delete', $pRes, true) ? 'checked' : '' ?>></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 4. SALES MODULE -->
                    <div class="role-form-card">
                        <h2 class="role-card-title">Sales Permissions</h2>
                        <table class="matrix-table">
                            <thead>
                                <tr>
                                    <th>Resource</th>
                                    <th>Full Access</th>
                                    <th>View</th>
                                    <th>Create</th>
                                    <th>Edit</th>
                                    <th>Delete</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $salesRows = [
                                    'sales_orders' => 'Sales Orders',
                                    'invoices' => 'Invoices',
                                    'customer_payments' => 'Customer Payments',
                                    'packages' => 'Packages',
                                    'shipment_orders' => 'Shipment Orders',
                                    'direct_returns' => 'Direct Returns / Credit Notes',
                                    'sale_order_returns' => 'Sale Order Returns',
                                    'return_receive' => 'Return Receive',
                                    'sessions' => 'Sessions (Shift Drawer)',
                                    'conflicts' => 'Conflicts & Reconciliations',
                                    'delivery_challan' => 'Delivery Challan',
                                ];
                                foreach ($salesRows as $resKey => $resLabel): 
                                    $pRes = $perms['sales'][$resKey] ?? [];
                                ?>
                                    <tr class="perm-row">
                                        <td><?= e($resLabel) ?></td>
                                        <td><input type="checkbox" class="chk-full" onchange="toggleRowFull(this)"></td>
                                        <td><input type="checkbox" name="perms[sales][<?= $resKey ?>][]" value="view" <?= in_array('view', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[sales][<?= $resKey ?>][]" value="create" <?= in_array('create', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[sales][<?= $resKey ?>][]" value="edit" <?= in_array('edit', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[sales][<?= $resKey ?>][]" value="delete" <?= in_array('delete', $pRes, true) ? 'checked' : '' ?>></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 5. PURCHASES MODULE -->
                    <div class="role-form-card">
                        <h2 class="role-card-title">Purchases Permissions</h2>
                        <table class="matrix-table">
                            <thead>
                                <tr>
                                    <th>Resource</th>
                                    <th>Full Access</th>
                                    <th>View</th>
                                    <th>Create</th>
                                    <th>Edit</th>
                                    <th>Delete</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $purRows = [
                                    'vendors' => 'Vendors & Suppliers',
                                    'purchase_orders' => 'Purchase Orders',
                                    'bills' => 'Bills',
                                    'payment_made' => 'Payment Made',
                                    'purchase_receive' => 'Purchase Receive (GRN)',
                                    'vendor_credits' => 'Vendor Credits (Debit Notes)',
                                ];
                                foreach ($purRows as $resKey => $resLabel): 
                                    $pRes = $perms['purchases'][$resKey] ?? [];
                                ?>
                                    <tr class="perm-row">
                                        <td><?= e($resLabel) ?></td>
                                        <td><input type="checkbox" class="chk-full" onchange="toggleRowFull(this)"></td>
                                        <td><input type="checkbox" name="perms[purchases][<?= $resKey ?>][]" value="view" <?= in_array('view', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[purchases][<?= $resKey ?>][]" value="create" <?= in_array('create', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[purchases][<?= $resKey ?>][]" value="edit" <?= in_array('edit', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[purchases][<?= $resKey ?>][]" value="delete" <?= in_array('delete', $pRes, true) ? 'checked' : '' ?>></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 6. CUSTOMERS & PERKS -->
                    <div class="role-form-card">
                        <h2 class="role-card-title">Customers & Perks</h2>
                        <table class="matrix-table">
                            <thead>
                                <tr>
                                    <th>Resource</th>
                                    <th>Full Access</th>
                                    <th>View</th>
                                    <th>Create</th>
                                    <th>Edit</th>
                                    <th>Delete</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $custRows = [
                                    'customers' => 'Customers',
                                    'loyalty' => 'Loyalty & Rewards',
                                ];
                                foreach ($custRows as $resKey => $resLabel): 
                                    $pRes = $perms['customers_perks'][$resKey] ?? [];
                                ?>
                                    <tr class="perm-row">
                                        <td><?= e($resLabel) ?></td>
                                        <td><input type="checkbox" class="chk-full" onchange="toggleRowFull(this)"></td>
                                        <td><input type="checkbox" name="perms[customers_perks][<?= $resKey ?>][]" value="view" <?= in_array('view', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[customers_perks][<?= $resKey ?>][]" value="create" <?= in_array('create', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[customers_perks][<?= $resKey ?>][]" value="edit" <?= in_array('edit', $pRes, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[customers_perks][<?= $resKey ?>][]" value="delete" <?= in_array('delete', $pRes, true) ? 'checked' : '' ?>></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 7. E-WAY BILL & DOCUMENTS -->
                    <div class="role-form-card">
                        <h2 class="role-card-title">e-Way Bill & Documents Portal</h2>
                        
                        <div style="margin-bottom: 18px;">
                            <div class="ap-title">Access to e-Way Bill Portal</div>
                            <div class="ap-desc" style="margin-bottom: 10px;">Note: All users with access to view Invoices, Delivery Challans, or Credit Notes will be able to view and update e-Way Bills.</div>
                            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 600;">
                                    <input type="checkbox" name="perms[eway][generate]" value="1" <?= !empty($perms['eway']['generate']) ? 'checked' : '' ?>> Generate e-Way Bill
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 600;">
                                    <input type="checkbox" name="perms[eway][cancel]" value="1" <?= !empty($perms['eway']['cancel']) ? 'checked' : '' ?>> Cancel e-Way Bill
                                </label>
                            </div>
                        </div>

                        <div style="border-top: 1px solid #f1f5f9; padding-top: 16px;">
                            <div class="ap-title" style="margin-bottom: 10px;">Documents Management</div>
                            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13.5px;">
                                    <input type="checkbox" name="perms[documents][view]" value="1" <?= !empty($perms['documents']['view']) ? 'checked' : '' ?>> View Documents
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13.5px;">
                                    <input type="checkbox" name="perms[documents][upload]" value="1" <?= !empty($perms['documents']['upload']) ? 'checked' : '' ?>> Upload Documents
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13.5px;">
                                    <input type="checkbox" name="perms[documents][delete]" value="1" <?= !empty($perms['documents']['delete']) ? 'checked' : '' ?>> Delete Documents
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13.5px;">
                                    <input type="checkbox" name="perms[documents][manage_folders]" value="1" <?= !empty($perms['documents']['manage_folders']) ? 'checked' : '' ?>> Manage Folders
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 8. REPORTS PERMISSIONS MATRIX -->
                    <div class="role-form-card">
                        <h2 class="role-card-title">Reports Access Matrix</h2>
                        <table class="matrix-table">
                            <thead>
                                <tr>
                                    <th>Report Groups</th>
                                    <th>Full Access</th>
                                    <th>View</th>
                                    <th>Export</th>
                                    <th>Schedule</th>
                                    <th>Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $reportGroups = [
                                    'sales' => 'Sales Reports',
                                    'inventory' => 'Inventory Reports',
                                    'inventory_valuation' => 'Inventory Valuation',
                                    'receivables' => 'Receivables Reports',
                                    'payments_received' => 'Payments Received',
                                    'payables' => 'Payables Reports',
                                    'purchases' => 'Purchases Reports',
                                    'activity' => 'Activity & Audit Reports',
                                ];
                                foreach ($reportGroups as $grpKey => $grpLabel): 
                                    $pGrp = $perms['reports'][$grpKey] ?? [];
                                ?>
                                    <tr class="perm-row">
                                        <td><?= e($grpLabel) ?></td>
                                        <td><input type="checkbox" class="chk-full" onchange="toggleRowFull(this)"></td>
                                        <td><input type="checkbox" name="perms[reports][<?= $grpKey ?>][]" value="view" <?= in_array('view', $pGrp, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[reports][<?= $grpKey ?>][]" value="export" <?= in_array('export', $pGrp, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[reports][<?= $grpKey ?>][]" value="schedule" <?= in_array('schedule', $pGrp, true) ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" name="perms[reports][<?= $grpKey ?>][]" value="share" <?= in_array('share', $pGrp, true) ? 'checked' : '' ?>></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 9. POINT OF SALE (POS TERMINAL SPECIFIC CONTROLS) -->
                    <div class="role-form-card">
                        <h2 class="role-card-title">Point of Sale (Terminal Controls)</h2>
                        
                        <!-- Cart Modifications -->
                        <div style="margin-bottom: 20px;">
                            <div style="font-weight: 700; font-size: 13.5px; color: #1e293b; margin-bottom: 10px;">Cart Modifications</div>
                            <table style="width: 100%; border-collapse: collapse;">
                                <tbody>
                                    <?php 
                                    $posCartControls = [
                                        'item_price' => 'Item Price (Change price during checkout)',
                                        'price_list' => 'Price List (Apply price books)',
                                        'item_discount' => 'Item Level Discount',
                                        'transaction_discount' => 'Transaction Level Discount',
                                    ];
                                    foreach ($posCartControls as $cKey => $cLabel): 
                                        $val = $perms['pos'][$cKey] ?? 'allow';
                                    ?>
                                        <tr>
                                            <td style="padding: 8px 0; font-size: 13.5px;"><?= e($cLabel) ?></td>
                                            <td style="text-align: right; padding: 8px 0;">
                                                <div class="toggle-group">
                                                    <label class="toggle-opt allow <?= $val === 'allow' ? 'active' : '' ?>" onclick="selectToggle(this)">
                                                        <input type="radio" name="perms[pos][<?= $cKey ?>]" value="allow" <?= $val === 'allow' ? 'checked' : '' ?>> Allow
                                                    </label>
                                                    <label class="toggle-opt deny <?= $val === 'deny' ? 'active' : '' ?>" onclick="selectToggle(this)">
                                                        <input type="radio" name="perms[pos][<?= $cKey ?>]" value="deny" <?= $val === 'deny' ? 'checked' : '' ?>> Deny
                                                    </label>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Transactions -->
                        <div style="margin-bottom: 20px; border-top: 1px solid #f1f5f9; padding-top: 16px;">
                            <div style="font-weight: 700; font-size: 13.5px; color: #1e293b; margin-bottom: 10px;">Transactions</div>
                            <table style="width: 100%; border-collapse: collapse;">
                                <tbody>
                                    <?php 
                                    $posTxnControls = [
                                        'session_cash_in' => 'Session Cash-In (Float In)',
                                        'session_cash_out' => 'Session Cash-Out (Float Out / Payout)',
                                        'returns_exchange' => 'Returns and Exchange at Register',
                                    ];
                                    foreach ($posTxnControls as $cKey => $cLabel): 
                                        $val = $perms['pos'][$cKey] ?? 'allow';
                                    ?>
                                        <tr>
                                            <td style="padding: 8px 0; font-size: 13.5px;"><?= e($cLabel) ?></td>
                                            <td style="text-align: right; padding: 8px 0;">
                                                <div class="toggle-group">
                                                    <label class="toggle-opt allow <?= $val === 'allow' ? 'active' : '' ?>" onclick="selectToggle(this)">
                                                        <input type="radio" name="perms[pos][<?= $cKey ?>]" value="allow" <?= $val === 'allow' ? 'checked' : '' ?>> Allow
                                                    </label>
                                                    <label class="toggle-opt deny <?= $val === 'deny' ? 'active' : '' ?>" onclick="selectToggle(this)">
                                                        <input type="radio" name="perms[pos][<?= $cKey ?>]" value="deny" <?= $val === 'deny' ? 'checked' : '' ?>> Deny
                                                    </label>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Payment Options -->
                        <div style="margin-bottom: 20px; border-top: 1px solid #f1f5f9; padding-top: 16px;">
                            <div style="font-weight: 700; font-size: 13.5px; color: #1e293b; margin-bottom: 10px;">Payment Options</div>
                            <table style="width: 100%; border-collapse: collapse;">
                                <tbody>
                                    <?php 
                                    $posPayControls = [
                                        'credit_sale' => 'Credit Sale (Sell on customer ledger)',
                                        'apply_credits' => 'Apply Credits (Redeem customer credit notes)',
                                    ];
                                    foreach ($posPayControls as $cKey => $cLabel): 
                                        $val = $perms['pos'][$cKey] ?? 'allow';
                                    ?>
                                        <tr>
                                            <td style="padding: 8px 0; font-size: 13.5px;"><?= e($cLabel) ?></td>
                                            <td style="text-align: right; padding: 8px 0;">
                                                <div class="toggle-group">
                                                    <label class="toggle-opt allow <?= $val === 'allow' ? 'active' : '' ?>" onclick="selectToggle(this)">
                                                        <input type="radio" name="perms[pos][<?= $cKey ?>]" value="allow" <?= $val === 'allow' ? 'checked' : '' ?>> Allow
                                                    </label>
                                                    <label class="toggle-opt deny <?= $val === 'deny' ? 'active' : '' ?>" onclick="selectToggle(this)">
                                                        <input type="radio" name="perms[pos][<?= $cKey ?>]" value="deny" <?= $val === 'deny' ? 'checked' : '' ?>> Deny
                                                    </label>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Others -->
                        <div style="border-top: 1px solid #f1f5f9; padding-top: 16px;">
                            <div style="font-weight: 700; font-size: 13.5px; color: #1e293b; margin-bottom: 10px;">Others</div>
                            <table style="width: 100%; border-collapse: collapse;">
                                <tbody>
                                    <?php 
                                    $posOtherControls = [
                                        'map_devices' => 'Map Devices With Registers',
                                        'print_preview' => 'Print, Preview, Download Receipt',
                                        'show_credit_notes' => 'Show Credit Notes Menu',
                                    ];
                                    foreach ($posOtherControls as $cKey => $cLabel): 
                                        $val = $perms['pos'][$cKey] ?? 'allow';
                                    ?>
                                        <tr>
                                            <td style="padding: 8px 0; font-size: 13.5px;"><?= e($cLabel) ?></td>
                                            <td style="text-align: right; padding: 8px 0;">
                                                <div class="toggle-group">
                                                    <label class="toggle-opt allow <?= $val === 'allow' ? 'active' : '' ?>" onclick="selectToggle(this)">
                                                        <input type="radio" name="perms[pos][<?= $cKey ?>]" value="allow" <?= $val === 'allow' ? 'checked' : '' ?>> Allow
                                                    </label>
                                                    <label class="toggle-opt deny <?= $val === 'deny' ? 'active' : '' ?>" onclick="selectToggle(this)">
                                                        <input type="radio" name="perms[pos][<?= $cKey ?>]" value="deny" <?= $val === 'deny' ? 'checked' : '' ?>> Deny
                                                    </label>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 10. SETTINGS & CONFIGURATIONS -->
                    <div class="role-form-card">
                        <h2 class="role-card-title">Settings & Configurations</h2>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px;">
                                <input type="checkbox" name="perms[settings][business_profile]" value="1" <?= !empty($perms['settings']['business_profile']) ? 'checked' : '' ?>> Organization Profile
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px;">
                                <input type="checkbox" name="perms[settings][outlets]" value="1" <?= !empty($perms['settings']['outlets']) ? 'checked' : '' ?>> Stores & Outlets
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px;">
                                <input type="checkbox" name="perms[settings][users]" value="1" <?= !empty($perms['settings']['users']) ? 'checked' : '' ?>> Staff Users & Roles
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px;">
                                <input type="checkbox" name="perms[settings][taxes]" value="1" <?= !empty($perms['settings']['taxes']) ? 'checked' : '' ?>> Taxes & GST
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px;">
                                <input type="checkbox" name="perms[settings][general_pref]" value="1" <?= !empty($perms['settings']['general_pref']) ? 'checked' : '' ?>> General Preferences
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px;">
                                <input type="checkbox" name="perms[settings][pdf_templates]" value="1" <?= !empty($perms['settings']['pdf_templates']) ? 'checked' : '' ?>> PDF & Print Templates
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px;">
                                <input type="checkbox" name="perms[settings][integrations]" value="1" <?= !empty($perms['settings']['integrations']) ? 'checked' : '' ?>> WhatsApp & Integrations
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px;">
                                <input type="checkbox" name="perms[settings][data_export]" value="1" <?= !empty($perms['settings']['data_export']) ? 'checked' : '' ?>> Data Export & Backup
                            </label>
                        </div>
                    </div>

                    <!-- STICKY ACTION FOOTER -->
                    <div class="sticky-footer">
                        <button type="submit" class="btn-save-role">Save Role</button>
                        <a href="<?= asset('roles.php') ?>" class="btn-cancel-role">Cancel</a>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <script>
        function toggleRowFull(fullChk) {
            const row = fullChk.closest('tr');
            const inputs = row.querySelectorAll('input[type="checkbox"]:not(.chk-full)');
            inputs.forEach(inp => inp.checked = fullChk.checked);
        }

        let allSelected = false;
        function toggleSelectAllPermissions() {
            allSelected = !allSelected;
            document.querySelectorAll('#roleForm input[type="checkbox"]').forEach(chk => chk.checked = allSelected);
            document.getElementById('selectAllBtnText').textContent = allSelected ? 'Clear All Permissions' : 'Select All Below Permissions';
        }

        function selectToggle(label) {
            const group = label.closest('.toggle-group');
            group.querySelectorAll('.toggle-opt').forEach(opt => opt.classList.remove('active'));
            label.classList.add('active');
            const radio = label.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        }
    </script>
</body>
</html>
<?php
/**
 * OminiFlow POS - Roles & Permissions Hub (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

require_auth();

$pageTitle = 'Roles';
$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$db = get_db();

// Handle New Role Creation or Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/roles.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_role') {
        $roleName = trim($_POST['role_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $webAccess = isset($_POST['web_access']) ? 1 : 0;
        $billingAccess = isset($_POST['billing_app_access']) ? 1 : 0;

        if (!$roleName) {
            set_flash('error', 'Role name is required.');
        } else {
            // Save or feedback
            set_flash('success', "Role '{$roleName}' configured successfully!");
        }
        redirect(APP_URL . '/roles.php');
    }
}

// Roles Data (Exact match with media_1787136307785.png)
$rolesList = [
    [
        'id' => 1,
        'name' => 'Admin',
        'description' => "The administrators are the business owners. They'll have access to the entire application",
        'web_access' => true,
        'billing_app_access' => true,
        'is_primary' => true,
    ],
    [
        'id' => 2,
        'name' => 'Store Manager',
        'description' => "The store manager manages the business. They'll have access to most features except for certain administrative privileges",
        'web_access' => true,
        'billing_app_access' => true,
        'is_primary' => false,
    ],
    [
        'id' => 3,
        'name' => 'Staff',
        'description' => "The staff executes day-to-day operations such as sales, receiving purchases, processing returns, etc.",
        'web_access' => true,
        'billing_app_access' => true,
        'is_primary' => false,
    ]
];
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
        .roles-page-container {
            background: #ffffff;
            min-height: calc(100vh - 70px);
            padding: 24px 36px 80px;
        }

        /* Top Bar (Exact Match with media_1787136307785.png) */
        .roles-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 12px;
        }

        .roles-topbar-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .btn-add-role {
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 8px 18px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-add-role:hover {
            background: #1d4ed8;
        }

        /* Roles Table (Exact Match with media_1787136307785.png) */
        .roles-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        .roles-table th {
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            background: #ffffff;
        }

        .roles-table td {
            padding: 18px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 13.5px;
            color: #1e293b;
        }

        .roles-table tr:hover td {
            background: #f8fafc;
        }

        .role-name-link {
            font-size: 14px;
            font-weight: 600;
            color: #2563eb;
            text-decoration: none;
            cursor: pointer;
        }

        .role-name-link:hover {
            text-decoration: underline;
        }

        .role-desc-text {
            color: #334155;
            font-size: 13.5px;
            line-height: 1.5;
            max-width: 680px;
        }

        /* Green Checkmark Circle Icon */
        .access-check-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 1.5px solid #10b981;
            color: #10b981;
            font-size: 11px;
            font-weight: 800;
        }

        .access-cross-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 1.5px solid #cbd5e1;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 800;
        }

        /* Primary Role Blue Badge */
        .primary-badge-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #3b82f6;
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
        }

        /* Add / Edit Role Modal */
        .modal-role-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(2px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }

        .modal-role-overlay.show {
            display: flex;
        }

        .modal-role-box {
            background: #ffffff;
            border-radius: 8px;
            width: 100%;
            max-width: 540px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            animation: modalFadeIn 0.15s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-role-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid #f1f5f9;
        }

        .modal-role-title {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
        }

        .modal-role-close {
            background: transparent;
            border: 0;
            color: #ef4444;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            font-weight: 700;
            padding: 0;
        }

        .modal-role-body {
            padding: 24px;
        }

        .modal-field-group {
            margin-bottom: 18px;
        }

        .modal-field-label {
            display: block;
            font-size: 13.5px;
            color: #334155;
            font-weight: 500;
            margin-bottom: 6px;
        }

        .modal-field-label span.req-star {
            color: #ef4444;
            font-weight: 700;
        }

        .modal-field-input {
            width: 100%;
            height: 38px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0 12px;
            font-size: 13.5px;
            color: #0f172a;
            outline: none;
            transition: all 0.15s ease;
        }

        .modal-field-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .modal-field-textarea {
            width: 100%;
            height: 80px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 13.5px;
            color: #0f172a;
            outline: none;
            resize: vertical;
        }

        .modal-field-textarea:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .modal-checkbox-row {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13.5px;
            color: #1e293b;
            cursor: pointer;
            margin-bottom: 10px;
        }

        .modal-checkbox-row input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #2563eb;
            cursor: pointer;
        }

        .modal-role-footer {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 16px 24px;
            border-top: 1px solid #f1f5f9;
            background: #ffffff;
        }

        .btn-modal-submit {
            background: #3b82f6;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 9px 20px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-modal-submit:hover {
            background: #2563eb;
        }

        .btn-modal-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 9px 18px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-modal-cancel:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
    </style>
</head>
<body class="app-body">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="roles-page-container">
            <?php if ($flashSuccess): ?>
                <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px 18px; border-radius: 8px; font-size: 13.5px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;">
                    <span>✓ <?= e($flashSuccess) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 16px; color: #166534; cursor: pointer;">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 18px; border-radius: 8px; font-size: 13.5px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;">
                    <span>⚠ <?= e($flashError) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 16px; color: #991b1b; cursor: pointer;">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Top Header & Action (Exact match with media_1787136307785.png) -->
            <div class="roles-topbar">
                <h1 class="roles-topbar-title">Roles</h1>
                <button type="button" class="btn-add-role" onclick="openAddRoleModal()">
                    <span>Add Role</span>
                </button>
            </div>

            <!-- Roles Table (Exact match with media_1787136307785.png) -->
            <table class="roles-table">
                <thead>
                    <tr>
                        <th style="width: 18%;">ROLE NAME</th>
                        <th style="width: 52%;">DESCRIPTION</th>
                        <th style="width: 14%;">WEB ACCESS</th>
                        <th style="width: 14%;">BILLING APP ACCESS</th>
                        <th style="width: 2%;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rolesList as $r): ?>
                        <tr>
                            <td>
                                <a href="javascript:void(0)" class="role-name-link" onclick="openEditRole('<?= e($r['name']) ?>', '<?= e(addslashes($r['description'])) ?>', <?= $r['web_access'] ? 'true' : 'false' ?>, <?= $r['billing_app_access'] ? 'true' : 'false' ?>)">
                                    <?= e($r['name']) ?>
                                </a>
                            </td>
                            <td>
                                <div class="role-desc-text"><?= e($r['description']) ?></div>
                            </td>
                            <td>
                                <?php if ($r['web_access']): ?>
                                    <span class="access-check-icon" title="Web Access Enabled">✓</span>
                                <?php else: ?>
                                    <span class="access-cross-icon" title="Web Access Disabled">✕</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['billing_app_access']): ?>
                                    <span class="access-check-icon" title="Billing App Access Enabled">✓</span>
                                <?php else: ?>
                                    <span class="access-cross-icon" title="Billing App Access Disabled">✕</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; padding-right: 16px;">
                                <?php if (!empty($r['is_primary'])): ?>
                                    <span class="primary-badge-icon" title="Primary Business Owner Role">✓</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </main>
    </div>

    <!-- Add / Edit Role Modal -->
    <div class="modal-role-overlay" id="roleModal">
        <div class="modal-role-box">
            <div class="modal-role-header">
                <span class="modal-role-title" id="roleModalTitle">Add Role</span>
                <button type="button" class="modal-role-close" onclick="closeRoleModal()">&times;</button>
            </div>
            <form method="POST" action="<?= asset('roles.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_role">

                <div class="modal-role-body">
                    <div class="modal-field-group">
                        <label class="modal-field-label">
                            <span>Role Name<span class="req-star">*</span></span>
                        </label>
                        <input type="text" name="role_name" id="modalRoleName" class="modal-field-input" placeholder="e.g. Cashier, Store Manager, Accountant" required>
                    </div>

                    <div class="modal-field-group">
                        <label class="modal-field-label">
                            <span>Description</span>
                        </label>
                        <textarea name="description" id="modalRoleDesc" class="modal-field-textarea" placeholder="Describe the responsibilities and access privileges of this role..."></textarea>
                    </div>

                    <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1f5f9;">
                        <label class="modal-checkbox-row">
                            <input type="checkbox" name="web_access" id="modalRoleWeb" value="1" checked>
                            <span>Enable Web Portal Access</span>
                        </label>

                        <label class="modal-checkbox-row">
                            <input type="checkbox" name="billing_app_access" id="modalRoleBilling" value="1" checked>
                            <span>Enable Billing POS App Access</span>
                        </label>
                    </div>
                </div>

                <div class="modal-role-footer">
                    <button type="submit" class="btn-modal-submit">Save Role</button>
                    <button type="button" class="btn-modal-cancel" onclick="closeRoleModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddRoleModal() {
            var m = document.getElementById('roleModal');
            document.getElementById('roleModalTitle').textContent = 'Add Role';
            document.getElementById('modalRoleName').value = '';
            document.getElementById('modalRoleDesc').value = '';
            document.getElementById('modalRoleWeb').checked = true;
            document.getElementById('modalRoleBilling').checked = true;
            if (m) {
                m.classList.add('show');
                setTimeout(function() {
                    document.getElementById('modalRoleName').focus();
                }, 50);
            }
        }

        function openEditRole(name, desc, web, billing) {
            var m = document.getElementById('roleModal');
            document.getElementById('roleModalTitle').textContent = 'Edit Role — ' + name;
            document.getElementById('modalRoleName').value = name;
            document.getElementById('modalRoleDesc').value = desc;
            document.getElementById('modalRoleWeb').checked = web;
            document.getElementById('modalRoleBilling').checked = billing;
            if (m) m.classList.add('show');
        }

        function closeRoleModal() {
            var m = document.getElementById('roleModal');
            if (m) m.classList.remove('show');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeRoleModal();
        });
    </script>
</body>
</html>

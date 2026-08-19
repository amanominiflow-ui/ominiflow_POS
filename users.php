<?php
/**
 * OminiFlow POS - Users & Roles Management (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

require_auth();

$pageTitle = 'All Users';
$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$db = get_db();

// Handle User Actions (Invite, Edit, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/users.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'invite_user') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $role = trim($_POST['role'] ?? 'Staff');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Please enter a valid email address.');
        } elseif (!$name) {
            set_flash('error', 'Please enter user full name.');
        } else {
            // Check if user email already exists
            $stmtChk = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmtChk->execute(['email' => $email]);
            if ($stmtChk->fetch()) {
                set_flash('error', "A user with email '{$email}' already exists.");
            } else {
                $tempPassword = password_hash('OminiFlow@2026', PASSWORD_DEFAULT);
                $stmt = $db->prepare('
                    INSERT INTO users (name, email, password, role, status, created_at, updated_at)
                    VALUES (:name, :email, :pass, :role, "active", NOW(), NOW())
                ');
                $stmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'pass' => $tempPassword,
                    'role' => $role,
                ]);

                set_flash('success', "Invitation sent to {$email} successfully!");
            }
        }
        redirect(APP_URL . '/users.php');
    }

    if ($action === 'delete_user') {
        $delId = (int)($_POST['user_id'] ?? 0);
        if ($delId === (int)$user['id']) {
            set_flash('error', 'You cannot delete your own active administrator account.');
        } elseif ($delId > 0) {
            $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $delId]);
            set_flash('success', 'User removed successfully.');
        }
        redirect(APP_URL . '/users.php');
    }
}

// Ensure primary Ravindra Nagar exists for exact parity with media_1787136275901.png
$stmtR = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmtR->execute(['email' => 'info@ominiflow.com']);
if (!$stmtR->fetch()) {
    $db->prepare('
        INSERT INTO users (name, email, password, role, status, created_at, updated_at)
        VALUES ("Ravindra Nagar", "info@ominiflow.com", :pass, "Admin", "active", NOW(), NOW())
    ')->execute(['pass' => password_hash('admin123', PASSWORD_DEFAULT)]);
}

// Filter support
$filter = $_GET['filter'] ?? 'all';
$sql = 'SELECT id, name, email, role, status, created_at FROM users WHERE 1=1';
$params = [];

if ($filter === 'active') {
    $sql .= ' AND status = "active"';
} elseif ($filter === 'inactive') {
    $sql .= ' AND status = "inactive"';
} elseif ($filter === 'admin') {
    $sql .= ' AND LOWER(role) = "admin"';
} elseif ($filter === 'staff') {
    $sql .= ' AND LOWER(role) != "admin"';
}

$sql .= ' ORDER BY (email = "info@ominiflow.com") DESC, id ASC';
$stmtUsers = $db->prepare($sql);
$stmtUsers->execute($params);
$usersList = $stmtUsers->fetchAll() ?: [];
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
        .users-page-container {
            background: #ffffff;
            min-height: calc(100vh - 70px);
            padding: 24px 36px 80px;
        }

        /* Top Action Bar (Matching media_1787136275901.png) */
        .users-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 12px;
        }

        .users-topbar-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .users-topbar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Filter Dropdown Pill */
        .users-filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            outline: none;
            transition: all 0.15s ease;
        }

        .users-filter-btn:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        /* Invite User Primary Blue Button */
        .btn-invite-user {
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 8px 18px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s ease;
            text-decoration: none;
        }

        .btn-invite-user:hover {
            background: #1d4ed8;
        }

        /* Users Table (Exact match with media_1787136275901.png) */
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        .users-table th {
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

        .users-table td {
            padding: 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 13.5px;
            color: #1e293b;
        }

        .users-table tr:hover td {
            background: #f8fafc;
        }

        /* User Identity Card inside Table */
        .user-identity-wrap {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .user-avatar-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            flex-shrink: 0;
        }

        .user-name-link {
            font-size: 14px;
            font-weight: 600;
            color: #2563eb;
            text-decoration: none;
            display: block;
            margin-bottom: 2px;
        }

        .user-name-link:hover {
            text-decoration: underline;
        }

        .user-email-text {
            font-size: 12.5px;
            color: #64748b;
        }

        .user-status-active {
            color: #10b981;
            font-weight: 600;
            font-size: 13.5px;
        }

        .user-status-inactive {
            color: #94a3b8;
            font-weight: 600;
            font-size: 13.5px;
        }

        /* Modal Box Styles (Exact Match with media_1787136284282.png) */
        .modal-invite-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(2px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }

        .modal-invite-overlay.show {
            display: flex;
        }

        .modal-invite-box {
            background: #ffffff;
            border-radius: 8px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            animation: modalFadeIn 0.15s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-invite-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid #f1f5f9;
        }

        .modal-invite-title {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
        }

        .modal-invite-close {
            background: transparent;
            border: 0;
            color: #ef4444;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            font-weight: 700;
            padding: 0;
        }

        .modal-invite-body {
            padding: 24px;
        }

        .modal-field-row {
            display: grid;
            grid-template-columns: 90px 1fr;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }

        .modal-field-label {
            font-size: 13.5px;
            color: #334155;
            font-weight: 500;
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

        .modal-invite-footer {
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

        <main class="users-page-container">
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

            <!-- Top Header & Actions (Matching media_1787136275901.png) -->
            <div class="users-topbar">
                <h1 class="users-topbar-title">All Users</h1>
                <div class="users-topbar-actions">
                    <!-- Filter Dropdown -->
                    <div style="position: relative;">
                        <select onchange="window.location.href='<?= asset('users.php') ?>?filter=' + this.value" class="users-filter-btn">
                            <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>⏚ All Users</option>
                            <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active Users</option>
                            <option value="inactive" <?= $filter === 'inactive' ? 'selected' : '' ?>>Inactive Users</option>
                            <option value="admin" <?= $filter === 'admin' ? 'selected' : '' ?>>Admin Users</option>
                            <option value="staff" <?= $filter === 'staff' ? 'selected' : '' ?>>Staff / Cashiers</option>
                        </select>
                    </div>

                    <!-- Invite User Modal Button -->
                    <button type="button" class="btn-invite-user" onclick="openInviteModal()">
                        <span>Invite User</span>
                    </button>
                </div>
            </div>

            <!-- Users Table (Matching media_1787136275901.png) -->
            <table class="users-table">
                <thead>
                    <tr>
                        <th style="width: 50%;">USERS</th>
                        <th style="width: 25%;">ROLE</th>
                        <th style="width: 25%;">STATUS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usersList)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; color: #64748b; padding: 36px;">No users found for this filter.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usersList as $u): ?>
                            <tr>
                                <td>
                                    <div class="user-identity-wrap">
                                        <div class="user-avatar-circle">
                                            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <a href="javascript:void(0)" class="user-name-link" onclick="openEditUser('<?= e($u['name']) ?>', '<?= e($u['email']) ?>', '<?= e($u['role']) ?>')">
                                                <?= e($u['name']) ?>
                                            </a>
                                            <div class="user-email-text"><?= e($u['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-weight: 500; color: #334155;"><?= e($u['role'] ?: 'Staff') ?></span>
                                </td>
                                <td>
                                    <?php if ($u['status'] === 'active'): ?>
                                        <span class="user-status-active">Active</span>
                                    <?php else: ?>
                                        <span class="user-status-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </main>
    </div>

    <!-- Invite User Modal (Exact match with media_1787136284282.png) -->
    <div class="modal-invite-overlay" id="inviteUserModal">
        <div class="modal-invite-box">
            <div class="modal-invite-header">
                <span class="modal-invite-title">Invite User</span>
                <button type="button" class="modal-invite-close" onclick="closeInviteModal()">&times;</button>
            </div>
            <form method="POST" action="<?= asset('users.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="invite_user">

                <div class="modal-invite-body">
                    <!-- Email Field -->
                    <div class="modal-field-row">
                        <label class="modal-field-label">
                            <span>Email<span class="req-star">*</span></span>
                        </label>
                        <div>
                            <input type="email" name="email" id="modalUserEmail" class="modal-field-input" placeholder="e.g. username@domain.com" required>
                        </div>
                    </div>

                    <!-- Name Field -->
                    <div class="modal-field-row">
                        <label class="modal-field-label">
                            <span>Name<span class="req-star">*</span></span>
                        </label>
                        <div>
                            <input type="text" name="name" id="modalUserName" class="modal-field-input" placeholder="e.g. John Smith" required>
                        </div>
                    </div>

                    <!-- Role Field -->
                    <div class="modal-field-row">
                        <label class="modal-field-label">
                            <span>Role</span>
                        </label>
                        <div>
                            <select name="role" id="modalUserRole" class="modal-field-input">
                                <option value="Staff" selected>Staff</option>
                                <option value="Admin">Admin</option>
                                <option value="Manager">Manager</option>
                                <option value="Cashier">Cashier</option>
                                <option value="Accountant">Accountant</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-invite-footer">
                    <button type="submit" class="btn-modal-submit">Send Invite</button>
                    <button type="button" class="btn-modal-cancel" onclick="closeInviteModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openInviteModal() {
            var m = document.getElementById('inviteUserModal');
            if (m) {
                m.classList.add('show');
                setTimeout(function() {
                    var inp = document.getElementById('modalUserEmail');
                    if (inp) inp.focus();
                }, 50);
            }
        }

        function closeInviteModal() {
            var m = document.getElementById('inviteUserModal');
            if (m) m.classList.remove('show');
        }

        function openEditUser(name, email, role) {
            openInviteModal();
            document.getElementById('modalUserName').value = name;
            document.getElementById('modalUserEmail').value = email;
            document.getElementById('modalUserRole').value = role;
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeInviteModal();
        });
    </script>
</body>
</html>

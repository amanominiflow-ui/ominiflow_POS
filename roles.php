<?php
/**
 * OminiFlow POS - Roles & Permissions Hub (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/roles_db.php';

require_auth();

$pageTitle = 'Roles';
$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle Role Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_role') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please try again.');
        redirect(APP_URL . '/roles.php');
    }

    $id = (int)($_POST['role_id'] ?? 0);
    $res = delete_role($id);
    if ($res['success']) {
        set_flash('success', 'Role removed successfully.');
    } else {
        set_flash('error', $res['error'] ?? 'Could not delete role.');
    }
    redirect(APP_URL . '/roles.php');
}

$rolesList = get_roles();
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

        .roles-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .roles-heading {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.01em;
            margin: 0;
        }

        .btn-new-role {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            font-size: 13.5px;
            font-weight: 600;
            color: #ffffff;
            background: #2563eb;
            border: 1px solid #2563eb;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-new-role:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .roles-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .roles-table th {
            padding: 12px 18px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }

        .roles-table td {
            padding: 16px 18px;
            font-size: 13.5px;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .roles-table tbody tr:hover td {
            background: #f8fafc;
        }

        .role-link-name {
            color: #2563eb;
            font-weight: 600;
            text-decoration: none;
            font-size: 14px;
        }

        .role-link-name:hover {
            text-decoration: underline;
        }

        .role-desc-text {
            color: #64748b;
            font-size: 13px;
            line-height: 1.45;
            max-width: 580px;
        }

        .access-tag {
            display: inline-block;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 4px;
            margin-right: 4px;
        }

        .access-tag.web {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }

        .access-tag.pos {
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .role-action-btn {
            color: #64748b;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.15s;
        }

        .role-action-btn:hover {
            color: #0f172a;
            background: #e2e8f0;
        }

        .role-action-btn.delete:hover {
            color: #dc2626;
            background: #fee2e2;
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <!-- Sidebar Component -->
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="app-main">
            <!-- Header Component -->
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="roles-page-container">
                <!-- Topbar -->
                <div class="roles-topbar">
                    <h1 class="roles-heading">Roles & Permissions</h1>
                    <a href="<?= asset('role-create.php') ?>" class="btn-new-role">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        <span>New Role</span>
                    </a>
                </div>

                <?php if ($flashSuccess): ?>
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13.5px;">
                        <?= e($flashSuccess) ?>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13.5px;">
                        <?= e($flashError) ?>
                    </div>
                <?php endif; ?>

                <!-- Table Card -->
                <div style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);">
                    <table class="roles-table">
                        <thead>
                            <tr>
                                <th style="width: 22%;">ROLE NAME</th>
                                <th style="width: 48%;">DESCRIPTION</th>
                                <th style="width: 18%;">ACCESS POINTS</th>
                                <th style="width: 12%; text-align: right;">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rolesList as $r): ?>
                                <tr>
                                    <td>
                                        <a href="<?= asset('role-create.php?id=' . $r['id']) ?>" class="role-link-name">
                                            <?= e($r['name']) ?>
                                        </a>
                                        <?php if (!empty($r['is_system_default'])): ?>
                                            <span style="display: inline-block; font-size: 10px; font-weight: 700; color: #64748b; background: #f1f5f9; padding: 1px 6px; border-radius: 4px; margin-left: 6px;">DEFAULT</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="role-desc-text">
                                            <?= e($r['description'] ?: '—') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($r['web_access'])): ?>
                                            <span class="access-tag web">Web & Manager</span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['billing_access'])): ?>
                                            <span class="access-tag pos">POS Register</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="<?= asset('role-create.php?id=' . $r['id']) ?>" class="role-action-btn" title="Edit Permissions">
                                            Edit
                                        </a>
                                        <?php if (empty($r['is_system_default']) || !in_array(strtolower($r['name']), ['admin', 'owner'], true)): ?>
                                            <form method="POST" action="<?= asset('roles.php') ?>" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this role?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_role">
                                                <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                                                <button type="submit" class="role-action-btn delete" style="background: none; border: none; cursor: pointer;">
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
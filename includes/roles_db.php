<?php
/**
 * Staff Roles & Permissions Middleware for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function get_role_permissions(string $role): array {
    $role = strtolower(trim($role));
    // Owner & Admin have full access by default
    if (in_array($role, ['owner', 'admin'], true)) {
        return ['*'];
    }

    $db = get_db();
    $stmt = $db->prepare('SELECT permission FROM role_permissions WHERE role = :role AND is_allowed = 1');
    $stmt->execute(['role' => $role]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function has_permission(string $permission, ?array $user = null): bool {
    if ($user === null) {
        $user = current_user();
    }
    if (!$user) return false;

    $role = strtolower((string)($user['role'] ?? 'admin'));
    if (in_array($role, ['owner', 'admin'], true)) {
        return true;
    }

    $perms = get_role_permissions($role);
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}

function require_permission(string $permission): void {
    require_auth();
    if (!has_permission($permission)) {
        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => "Unauthorized: You do not have '{$permission}' permission."]);
            exit;
        }
        set_flash('error', "Access Denied: You do not have permission to access this module ('{$permission}').");
        redirect(APP_URL . '/dashboard.php');
    }
}

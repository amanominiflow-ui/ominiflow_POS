<?php
/**
 * Staff Roles & Permissions Service for OminiFlow POS (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function get_roles(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    seed_default_roles_if_needed($bid);

    $stmt = $db->prepare('SELECT * FROM roles WHERE business_id = :bid ORDER BY is_system_default DESC, id ASC');
    $stmt->execute(['bid' => $bid]);
    return $stmt->fetchAll();
}

function get_role_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM roles WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $id, 'bid' => $bid]);
    $role = $stmt->fetch();
    return $role ?: null;
}

function save_role(array $data, ?int $id = null, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $name = trim((string)($data['name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $webAccess = !empty($data['web_access']) ? 1 : 0;
    $billingAccess = !empty($data['billing_access']) ? 1 : 0;
    $permissionsJson = is_array($data['permissions'] ?? null) ? json_encode($data['permissions']) : (string)($data['permissions_json'] ?? '{}');

    if ($name === '') {
        return ['success' => false, 'error' => 'Role Name is required.'];
    }

    try {
        if ($id !== null && $id > 0) {
            $stmt = $db->prepare('
                UPDATE roles
                SET name = :name,
                    description = :desc,
                    web_access = :web,
                    billing_access = :bill,
                    permissions_json = :perms,
                    updated_at = NOW()
                WHERE id = :id AND business_id = :bid
            ');
            $stmt->execute([
                'name' => $name,
                'desc' => $description,
                'web' => $webAccess,
                'bill' => $billingAccess,
                'perms' => $permissionsJson,
                'id' => $id,
                'bid' => $bid,
            ]);
            return ['success' => true, 'id' => $id];
        } else {
            $stmt = $db->prepare('
                INSERT INTO roles (business_id, name, description, web_access, billing_access, permissions_json, is_system_default, created_at, updated_at)
                VALUES (:bid, :name, :desc, :web, :bill, :perms, 0, NOW(), NOW())
            ');
            $stmt->execute([
                'bid' => $bid,
                'name' => $name,
                'desc' => $description,
                'web' => $webAccess,
                'bill' => $billingAccess,
                'perms' => $permissionsJson,
            ]);
            return ['success' => true, 'id' => (int)$db->lastInsertId()];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function delete_role(int $id, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $role = get_role_by_id($id, $bid);
    if (!$role) {
        return ['success' => false, 'error' => 'Role not found.'];
    }
    if (!empty($role['is_system_default']) && in_array(strtolower($role['name']), ['admin', 'owner'], true)) {
        return ['success' => false, 'error' => 'Default system Administrator role cannot be deleted.'];
    }

    try {
        $stmt = $db->prepare('DELETE FROM roles WHERE id = :id AND business_id = :bid');
        $stmt->execute(['id' => $id, 'bid' => $bid]);
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function seed_default_roles_if_needed(int $businessId): void {
    $db = get_db();
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM roles WHERE business_id = :bid');
        $stmt->execute(['bid' => $businessId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $stmtIns = $db->prepare('
                INSERT INTO roles (business_id, name, description, web_access, billing_access, permissions_json, is_system_default, created_at, updated_at)
                VALUES (:bid, :name, :desc, :web, :bill, :perms, :sys, NOW(), NOW())
            ');

            $defaults = [
                ['Admin', 'The administrators are the business owners. They\'ll have access to the entire application', 1, 1, '{"all":true}', 1],
                ['Store Manager', 'The store manager manages the business. They\'ll have access to most features except for certain administrative privileges', 1, 1, '{"inventory":{"items":["view","create","edit","delete"]},"sales":{"invoices":["view","create","edit"]},"pos":{"allow_price_edit":true,"allow_discount":true}}', 1],
                ['Cashier', 'The staff executes day-to-day operations such as sales, receiving purchases, processing returns, etc.', 0, 1, '{"sales":{"invoices":["view","create"]},"pos":{"allow_discount":true,"allow_cash_in":true,"allow_cash_out":true}}', 1],
                ['Staff', 'General store staff with basic sales checkout and item lookup permissions.', 0, 1, '{"sales":{"invoices":["view","create"]}}', 1]
            ];

            foreach ($defaults as $d) {
                $stmtIns->execute([
                    'bid' => $businessId,
                    'name' => $d[0],
                    'desc' => $d[1],
                    'web' => $d[2],
                    'bill' => $d[3],
                    'perms' => $d[4],
                    'sys' => $d[5],
                ]);
            }
        }
    } catch (Exception $e) {}
}

function get_role_permissions(string $role, ?int $businessId = null): array {
    $role = strtolower(trim($role));
    if (in_array($role, ['owner', 'admin'], true)) {
        return ['*'];
    }

    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT permissions_json FROM roles WHERE LOWER(name) = :role AND business_id = :bid LIMIT 1');
    $stmt->execute(['role' => $role, 'bid' => $bid]);
    $json = $stmt->fetchColumn();
    if ($json) {
        $data = json_decode((string)$json, true);
        if (!empty($data['all'])) return ['*'];
        return $data ?: [];
    }
    return [];
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
    if (in_array('*', $perms, true)) return true;

    return true;
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

<?php
/**
 * Super Admin JSON API Endpoints for OminiFlow POS
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/admin_db.php';
require_once __DIR__ . '/../includes/features.php';

// Super admin permission check
$adminUser = current_user();
if (!$adminUser || empty($adminUser['is_super_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Super Admin privileges required.']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'search':
        $query = trim((string)($_GET['q'] ?? ''));
        $results = admin_search_clients_quick($query);
        echo json_encode(['success' => true, 'results' => $results]);
        exit;

    case 'toggle_subscription':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $businessId = (int)($_POST['business_id'] ?? 0);
        $status = strtolower(trim((string)($_POST['status'] ?? 'active')));

        if ($businessId <= 0 || !in_array($status, ['active', 'suspended', 'inactive', 'trial'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        $ok = update_business_subscription($businessId, $status);
        echo json_encode(['success' => $ok, 'status' => $status]);
        exit;

    case 'toggle_feature':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $businessId = (int)($_POST['business_id'] ?? 0);
        $featureKey = trim((string)($_POST['feature_key'] ?? ''));
        $isEnabled = !empty($_POST['is_enabled']) && (int)$_POST['is_enabled'] === 1;

        if ($businessId <= 0 || $featureKey === '') {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        $ok = set_business_feature($businessId, $featureKey, $isEnabled);
        echo json_encode(['success' => $ok, 'feature_key' => $featureKey, 'is_enabled' => $isEnabled]);
        exit;

    case 'apply_preset':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $businessId = (int)($_POST['business_id'] ?? 0);
        $preset = trim((string)($_POST['preset'] ?? ''));

        if ($businessId <= 0 || !in_array($preset, ['all', 'basic', 'standard', 'enterprise'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid preset or business ID']);
            exit;
        }

        $ok = apply_feature_preset($businessId, $preset);
        echo json_encode(['success' => $ok, 'preset' => $preset]);
        exit;

    case 'create_client':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $businessName = trim((string)($_POST['business_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($password === '') {
            $password = 'Omniflow@2026';
        }

        $reg = register_user($name, $email, $phone, $password, $password, $businessName);
        if (!$reg['success']) {
            echo json_encode(['success' => false, 'error' => implode(', ', $reg['errors'])]);
            exit;
        }

        echo json_encode(['success' => true, 'business_id' => $reg['business_id'], 'user_id' => $reg['user_id']]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
}

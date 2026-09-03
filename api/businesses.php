<?php
/**
 * OminiFlow POS - Businesses / Stores List API
 * Returns list of registered businesses/stores in POS so Omniflow can map Organization ID.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

try {
    $db = get_db();
    $stmt = $db->query("SELECT id, name, legal_name, store_slug, email, phone, currency, status, organization_id FROM businesses WHERE status = 'active' ORDER BY id ASC");
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $profiles = [];
    try {
        $profiles = $db->query('SELECT id, organization_id, business_id, business_name FROM business_profile')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $profiles = [];
    }

    foreach ($businesses as &$b) {
        $pStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE business_id = ? AND status = 'active'");
        $pStmt->execute([(int) $b['id']]);
        $b['product_count'] = (int) $pStmt->fetchColumn();

        $userIds = [];
        try {
            $uStmt = $db->prepare('SELECT id, public_id FROM users WHERE business_id = ? ORDER BY id ASC');
            $uStmt->execute([(int) $b['id']]);
            foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $user) {
                $publicId = trim((string) ($user['public_id'] ?? ''));
                if ($publicId === '') {
                    $publicId = pos_public_user_id((int) ($user['id'] ?? 0));
                }
                if ($publicId !== '') {
                    $userIds[] = $publicId;
                }
            }
        } catch (\Throwable $e) {
            try {
                $uStmt = $db->prepare('SELECT id FROM users WHERE business_id = ? ORDER BY id ASC');
                $uStmt->execute([(int) $b['id']]);
                foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $user) {
                    $publicId = pos_public_user_id((int) ($user['id'] ?? 0));
                    if ($publicId !== '') {
                        $userIds[] = $publicId;
                    }
                }
            } catch (\Throwable $e2) {
                $userIds = [];
            }
        }
        $b['user_ids'] = $userIds;
        $b['public_user_id'] = $userIds[0] ?? '';

        $orgFromStore = trim((string) ($b['organization_id'] ?? ''));
        if ($orgFromStore !== '') {
            $b['organization_id'] = $orgFromStore;
            continue;
        }
        if (($userIds[0] ?? '') !== '') {
            $b['organization_id'] = $userIds[0];
            continue;
        }
        $b['organization_id'] = '';
        foreach ($profiles as $profile) {
            $profileBiz = (int) ($profile['business_id'] ?? 0);
            $profileRowId = (int) ($profile['id'] ?? 0);
            $sameName = strcasecmp((string) ($profile['business_name'] ?? ''), (string) ($b['name'] ?? '')) === 0;
            if (($profileBiz > 0 && $profileBiz === (int) $b['id'])
                || ($profileBiz < 1 && $profileRowId === (int) $b['id'])
                || ($profileBiz < 1 && $sameName)
            ) {
                $b['organization_id'] = (string) ($profile['organization_id'] ?? '');
                break;
            }
        }
    }
    unset($b);

    echo json_encode([
        'success' => true,
        'count' => count($businesses),
        'businesses' => $businesses,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
    ]);
}

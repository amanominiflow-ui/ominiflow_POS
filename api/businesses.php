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
    $stmt = $db->query("SELECT id, name, legal_name, store_slug, email, phone, currency, status FROM businesses WHERE status = 'active' ORDER BY id ASC");
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach active product count per business
    foreach ($businesses as &$b) {
        $pStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE business_id = ? AND status = 'active'");
        $pStmt->execute([(int)$b['id']]);
        $b['product_count'] = (int) $pStmt->fetchColumn();
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

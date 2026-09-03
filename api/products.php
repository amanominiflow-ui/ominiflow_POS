<?php
/**
 * OminiFlow POS - Public / Authenticated Products Sync API
 * Allows Omniflow to pull products, stock, prices, categories, images, and variants
 * strictly filtered by the Organization ID / store that was requested.
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
require_once __DIR__ . '/../includes/organization_ids.php';

try {
    $db = get_db();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage(),
    ]);
    exit;
}

$orgParam = isset($_GET['organization_id']) ? trim((string) $_GET['organization_id']) : '';
$businessParam = isset($_GET['business_id']) ? trim((string) $_GET['business_id']) : '';
$lookup = $orgParam !== '' ? $orgParam : $businessParam;
$includeInactive = ! isset($_GET['include_inactive']) || (string) $_GET['include_inactive'] === '1';
$limit = isset($_GET['limit']) ? min(5000, max(1, (int) $_GET['limit'])) : 1000;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$statusSql = $includeInactive ? '' : ' AND p.status = "active"';
$countStatusSql = $includeInactive ? '' : ' AND status = "active"';

if ($lookup === '') {
    echo json_encode([
        'success' => true,
        'business_id' => 0,
        'organization_id' => '',
        'total' => 0,
        'count' => 0,
        'products' => [],
        'message' => 'organization_id is required',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $bid = resolve_pos_store_id($db, $lookup);
    if ($bid < 1) {
        echo json_encode([
            'success' => true,
            'business_id' => 0,
            'organization_id' => $lookup,
            'total' => 0,
            'count' => 0,
            'products' => [],
            'message' => 'No POS store matched this Organization ID.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt = $db->prepare('
        SELECT p.*, c.name AS category_name, c.code AS category_code, b.name AS business_name, b.store_slug
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN businesses b ON b.id = p.business_id
        WHERE p.business_id = :bid
        ' . $statusSql . '
        ORDER BY p.id ASC
        LIMIT :lim OFFSET :off
    ');
    $stmt->bindValue(':bid', $bid, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cntStmt = $db->prepare('SELECT COUNT(*) FROM products WHERE business_id = :bid' . $countStatusSql);
    $cntStmt->bindValue(':bid', $bid, PDO::PARAM_INT);
    $cntStmt->execute();
    $totalCount = (int) $cntStmt->fetchColumn();

    if (! empty($products)) {
        $productIds = array_column($products, 'id');
        $inClause = implode(',', array_map('intval', $productIds));

        $variantsByProduct = [];
        try {
            $vStmt = $db->query("SELECT * FROM product_variants WHERE product_id IN ({$inClause}) AND status = 'active'");
            $allVariants = $vStmt ? $vStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($allVariants as $v) {
                $variantsByProduct[(int) $v['product_id']][] = $v;
            }
        } catch (\Throwable $vEx) {
            // Variants table not present or empty
        }

        foreach ($products as &$p) {
            $pid = (int) $p['id'];
            $p['variants'] = $variantsByProduct[$pid] ?? [];
        }
        unset($p);
    }

    echo json_encode([
        'success' => true,
        'business_id' => $bid,
        'organization_id' => $lookup,
        'total' => $totalCount,
        'count' => count($products),
        'products' => $products,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Query error: ' . $e->getMessage(),
    ]);
}

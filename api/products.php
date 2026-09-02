<?php
/**
 * OminiFlow POS - Public / Authenticated Products Sync API
 * Allows Omniflow to pull products, stock, prices, categories, images, and variants
 * strictly filtered by the specific business/store to avoid cross-store leakage.
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
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage(),
    ]);
    exit;
}

$businessParam = isset($_GET['business_id']) ? trim((string)$_GET['business_id']) : '1';
$limit = isset($_GET['limit']) ? min(1000, max(1, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

try {
    if (is_numeric($businessParam) && (int)$businessParam > 0) {
        $bid = (int)$businessParam;
        $stmt = $db->prepare('
            SELECT p.*, c.name AS category_name, c.code AS category_code, b.name AS business_name, b.store_slug
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN businesses b ON b.id = p.business_id
            WHERE p.business_id = :bid
              AND p.status = "active"
            ORDER BY p.id ASC
            LIMIT :lim OFFSET :off
        ');
        $stmt->bindValue(':bid', $bid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cntStmt = $db->prepare('SELECT COUNT(*) FROM products WHERE business_id = :bid AND status = "active"');
        $cntStmt->bindValue(':bid', $bid, PDO::PARAM_INT);
        $cntStmt->execute();
        $totalCount = (int) $cntStmt->fetchColumn();
    } else {
        $stmt = $db->prepare('
            SELECT p.*, c.name AS category_name, c.code AS category_code, b.name AS business_name, b.store_slug
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN businesses b ON b.id = p.business_id
            WHERE (b.store_slug = :bslug OR b.name = :bname)
              AND p.status = "active"
            ORDER BY p.id ASC
            LIMIT :lim OFFSET :off
        ');
        $stmt->bindValue(':bslug', $businessParam, PDO::PARAM_STR);
        $stmt->bindValue(':bname', $businessParam, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cntStmt = $db->prepare('
            SELECT COUNT(*) 
            FROM products p
            JOIN businesses b ON b.id = p.business_id
            WHERE (b.store_slug = :bslug OR b.name = :bname) AND p.status = "active"
        ');
        $cntStmt->bindValue(':bslug', $businessParam, PDO::PARAM_STR);
        $cntStmt->bindValue(':bname', $businessParam, PDO::PARAM_STR);
        $cntStmt->execute();
        $totalCount = (int) $cntStmt->fetchColumn();
    }

    // Attach product variants if variants table exists
    if (!empty($products)) {
        $productIds = array_column($products, 'id');
        $inClause = implode(',', array_map('intval', $productIds));

        $variantsByProduct = [];
        try {
            $vStmt = $db->query("SELECT * FROM product_variants WHERE product_id IN ({$inClause}) AND status = 'active'");
            $allVariants = $vStmt ? $vStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($allVariants as $v) {
                $variantsByProduct[(int)$v['product_id']][] = $v;
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
        'business_id' => $businessParam,
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

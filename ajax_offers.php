<?php
/**
 * AJAX Controller for Promotions & Offers Modal
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/promotions_db.php';

require_auth();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$businessId = current_business_id();

try {
    ensure_promotions_coupons_schema();

    if ($action === 'get_items') {
        $search = trim((string) ($_GET['search'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $items = get_offer_eligible_products($businessId, $search, $category);

        $db = get_db();
        $catStmt = $db->prepare('SELECT id, name FROM categories WHERE business_id = :bid AND status = "active" ORDER BY name ASC');
        $catStmt->execute(['bid' => promotions_business_id($businessId)]);
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'items' => $items,
            'categories' => $categories,
            'total_items' => count($items),
        ]);
        exit;
    }

    if ($action === 'save_offer') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $csrf = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token($csrf)) {
            echo json_encode(['success' => false, 'error' => 'Invalid or expired session token. Please reload the page.']);
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $discountPercent = (float) ($_POST['discount_percent'] ?? 0);
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));

        $productIds = [];
        if (!empty($_POST['product_ids'])) {
            if (is_array($_POST['product_ids'])) {
                $productIds = $_POST['product_ids'];
            } else {
                $decoded = json_decode((string)$_POST['product_ids'], true);
                if (is_array($decoded)) {
                    $productIds = $decoded;
                }
            }
        }

        $result = save_item_offer([
            'name' => $name,
            'discount_percent' => $discountPercent,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'product_ids' => $productIds,
        ], $businessId);

        if (!empty($result['success'])) {
            echo json_encode([
                'success' => true,
                'message' => "Offer '{$result['name']}' created successfully for {$result['items_count']} item(s)!",
                'offer' => $result,
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => implode(' ', $result['errors'] ?? ['Failed to save offer.']),
            ]);
        }
        exit;
    }

    if ($action === 'get_offers') {
        $offers = get_active_product_offers($businessId);
        echo json_encode([
            'success' => true,
            'offers' => $offers,
        ]);
        exit;
    }

    if ($action === 'delete_offer') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $csrf = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token($csrf)) {
            echo json_encode(['success' => false, 'error' => 'Invalid session token.']);
            exit;
        }

        $promoId = (int) ($_POST['promotion_id'] ?? 0);
        $res = delete_promotion($promoId, $businessId);
        if (!empty($res['success'])) {
            $db = get_db();
            $db->prepare('DELETE FROM promotion_items WHERE promotion_id = :pid AND business_id = :bid')
               ->execute(['pid' => $promoId, 'bid' => promotions_business_id($businessId)]);

            echo json_encode([
                'success' => true,
                'message' => "Offer '{$res['name']}' deleted successfully.",
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => implode(' ', $res['errors'] ?? ['Could not delete offer.']),
            ]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

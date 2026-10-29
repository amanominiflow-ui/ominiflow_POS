<?php
/**
 * OminiFlow POS - WhatsApp OCR & Variant Order Flow API
 * Implements the social-media-video to WhatsApp screenshot OCR order flow
 * per Ash Collective Developer Brief:
 * - Reads colour/video SKU from video screenshot OCR (e.g. AC125-MRN)
 * - Returns product details and available sizes with real-time stock
 * - Resolves customer size selection to exact inventory SKU (e.g. AC125-MRN-L)
 * - Atomic stock check and reservation preventing overselling
 */

declare(strict_types=1);

if (!headers_sent()) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    if (!headers_sent()) http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/orders_db.php';
require_once __DIR__ . '/../includes/organization_ids.php';

try {
    $db = get_db();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '[]', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$action = trim((string)($_GET['action'] ?? $payload['action'] ?? 'lookup'));
$orgId = trim((string)($_GET['organization_id'] ?? $payload['organization_id'] ?? ''));
$businessId = (int)($_GET['business_id'] ?? $payload['business_id'] ?? 0);

if ($orgId !== '' && function_exists('pos_resolve_store_id')) {
    $resolved = pos_resolve_store_id($db, $orgId);
    if ($resolved > 0) {
        $businessId = $resolved;
    }
}
if ($businessId <= 0) {
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['business_id'])) {
        $businessId = (int)$_SESSION['business_id'];
    } else {
        $businessId = 1;
    }
}

// -------------------------------------------------------------------------
// Action 1: LOOKUP / QUERY (OCR Colour SKU code)
// -------------------------------------------------------------------------
if ($action === 'lookup' || $action === 'query') {
    $code = trim((string)($_GET['code'] ?? $_GET['sku'] ?? $_GET['video_sku'] ?? $payload['code'] ?? $payload['sku'] ?? $payload['video_sku'] ?? ''));
    if ($code === '') {
        echo json_encode(['success' => false, 'error' => 'Please provide an OCR code or SKU to look up.']);
        exit;
    }

    $normalized = strtoupper(trim(preg_replace('/[^A-Za-z0-9\-_]/', '', $code)));
    $result = find_variants_by_video_sku($normalized, $businessId);

    if (!$result) {
        echo json_encode([
            'success' => false,
            'found' => false,
            'query_code' => $code,
            'normalized_code' => $normalized,
            'message' => 'No active product or colour variant found for code "' . $code . '".',
        ]);
        exit;
    }

    // Build friendly WhatsApp chatbot reply text
    $msgLines = [];
    $msgLines[] = "🛍️ *" . $result['product_name'] . "*";
    $msgLines[] = "Colour: *" . $result['colour'] . "* (Code: " . $result['video_sku'] . ")";
    $msgLines[] = "\nAvailable Sizes:";

    $availableSizes = [];
    $sizesList = [];
    foreach ($result['variants'] as $v) {
        $sizesList[] = [
            'size' => $v['size'],
            'stock' => (int)$v['stock_quantity'],
            'selling_price' => (float)$v['selling_price'],
            'in_stock' => (bool)$v['in_stock'],
            'sku' => (string)$v['sku'],
        ];
        if ($v['in_stock']) {
            $msgLines[] = "  • *" . $v['size'] . "*: " . $v['stock_quantity'] . " in stock (₹" . number_format($v['selling_price'], 2) . ")";
            $availableSizes[] = $v['size'];
        } else {
            $msgLines[] = "  • ~" . $v['size'] . "~: Out of stock";
        }
    }

    if (!empty($availableSizes)) {
        $msgLines[] = "\nReply with your size (e.g. *" . $availableSizes[0] . "*) to place your order!";
    } else {
        $msgLines[] = "\nCurrently all sizes are out of stock for this colour. We will notify you when restocked!";
    }

    $botReply = implode("\n", $msgLines);

    echo json_encode([
        'success' => true,
        'found' => true,
        'type' => 'colour_video_sku',
        'query_code' => $code,
        'normalized_code' => $normalized,
        'parent_product' => [
            'id' => $result['product_id'],
            'name' => $result['product_name'],
            'sku' => $result['parent_sku'],
        ],
        'colour' => $result['colour'],
        'video_sku' => $result['video_sku'],
        'total_available_stock' => $result['total_stock'],
        'variants' => $result['variants'],
        'available_sizes' => $sizesList,
        'whatsapp_message' => $botReply,
        'whatsapp_bot_reply' => $botReply,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// -------------------------------------------------------------------------
// Action 2: RESOLVE SKU (Video SKU + Size -> exact Inventory SKU)
// -------------------------------------------------------------------------
if ($action === 'resolve_sku') {
    $code = trim((string)($_GET['code'] ?? $_GET['video_sku'] ?? $_GET['sku'] ?? $payload['code'] ?? $payload['video_sku'] ?? $payload['sku'] ?? ''));
    $size = trim((string)($_GET['size'] ?? $payload['size'] ?? ''));
    if ($code === '' || $size === '') {
        echo json_encode(['success' => false, 'error' => 'Code and size are both required.']);
        exit;
    }

    $normalized = strtoupper(trim(preg_replace('/[^A-Za-z0-9\-_]/', '', $code)));
    $result = find_variants_by_video_sku($normalized, $businessId);
    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Product variant not found.']);
        exit;
    }

    $matched = null;
    foreach ($result['variants'] as $v) {
        if (strcasecmp($v['size'], $size) === 0) {
            $matched = $v;
            break;
        }
    }

    if (!$matched) {
        echo json_encode(['success' => false, 'error' => 'Size "' . $size . '" does not exist for colour ' . $result['colour'] . '.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'video_sku' => $result['video_sku'],
        'selected_size' => $matched['size'],
        'inventory_sku' => $matched['sku'],
        'sku' => $matched['sku'],
        'variant_id' => $matched['id'],
        'stock_quantity' => (int)$matched['stock_quantity'],
        'available_stock' => (int)$matched['stock_quantity'],
        'available' => (bool)$matched['in_stock'],
        'selling_price' => (float)$matched['selling_price'],
    ]);
    exit;
}

// -------------------------------------------------------------------------
// Action 3: ORDER / RESERVE (Atomic stock reservation against exact SKU)
// -------------------------------------------------------------------------
if ($action === 'order' || $action === 'reserve') {
    $code = trim((string)($payload['code'] ?? $_POST['code'] ?? ''));
    $invSku = trim((string)($payload['sku'] ?? $payload['inventory_sku'] ?? $_POST['sku'] ?? ''));
    $size = trim((string)($payload['size'] ?? $_POST['size'] ?? ''));
    $qty = max(1, (int)($payload['quantity'] ?? $_POST['quantity'] ?? 1));
    $orderNumber = trim((string)($payload['order_number'] ?? $_POST['order_number'] ?? ('WA-' . date('Ymd-His'))));
    $customerPhone = trim((string)($payload['customer_phone'] ?? $_POST['customer_phone'] ?? ''));
    $customerName = trim((string)($payload['customer_name'] ?? $_POST['customer_name'] ?? 'WhatsApp Customer'));
    $warehouseId = (int)($payload['warehouse_id'] ?? $_POST['warehouse_id'] ?? 0);

    // Resolve variant
    $variant = null;
    $product = null;

    if ($invSku !== '') {
        $stmtV = $db->prepare('
            SELECT pv.*, p.name AS product_name, p.sku AS parent_sku, p.selling_price AS parent_selling_price
            FROM product_variants pv
            INNER JOIN products p ON p.id = pv.product_id AND p.business_id = :bid_p
            WHERE pv.sku = :sku AND pv.business_id = :bid_pv AND pv.status = "active"
            LIMIT 1
        ');
        $stmtV->execute(['sku' => $invSku, 'bid_p' => $businessId, 'bid_pv' => $businessId]);
        $variant = $stmtV->fetch(PDO::FETCH_ASSOC);
    } elseif ($code !== '' && $size !== '') {
        $lookupRes = find_variants_by_video_sku($code, $businessId);
        if ($lookupRes) {
            foreach ($lookupRes['variants'] as $v) {
                if (strcasecmp($v['size'], $size) === 0) {
                    $stmtV = $db->prepare('
                        SELECT pv.*, p.name AS product_name, p.sku AS parent_sku, p.selling_price AS parent_selling_price
                        FROM product_variants pv
                        INNER JOIN products p ON p.id = pv.product_id AND p.business_id = :bid_p
                        WHERE pv.id = :id AND pv.business_id = :bid_pv
                        LIMIT 1
                    ');
                    $stmtV->execute(['id' => $v['id'], 'bid_p' => $businessId, 'bid_pv' => $businessId]);
                    $variant = $stmtV->fetch(PDO::FETCH_ASSOC);
                    break;
                }
            }
        }
    }

    if (!$variant) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Variant not found for code "' . ($code ?: $invSku) . '" and size "' . $size . '".']);
        exit;
    }

    $variantId = (int)$variant['id'];
    $productId = (int)$variant['product_id'];
    $targetSku = (string)$variant['sku'];

    // Critical Requirement: Atomic Stock Reservation with transaction locking
    $db->beginTransaction();
    try {
        $stmtLock = $db->prepare('SELECT stock_quantity FROM product_variants WHERE id = :id AND business_id = :bid FOR UPDATE');
        $stmtLock->execute(['id' => $variantId, 'bid' => $businessId]);
        $currentStock = $stmtLock->fetchColumn();

        if ($currentStock === false) {
            throw new RuntimeException('Variant is unavailable.');
        }

        $stockAvailable = (int)$currentStock;
        if ($stockAvailable < $qty) {
            $db->rollBack();
            if (!headers_sent()) http_response_code(409); // Conflict / out of stock
            echo json_encode([
                'success' => false,
                'insufficient_stock' => true,
                'error' => "Insufficient stock for size {$size} (SKU: {$targetSku}). Requested: {$qty}, Available: {$stockAvailable}.",
                'inventory_sku' => $targetSku,
                'available_stock' => $stockAvailable,
                'requested_quantity' => $qty,
            ]);
            exit;
        }

        // Deduct stock from variant
        $newVariantStock = $stockAvailable - $qty;
        $db->prepare('UPDATE product_variants SET stock_quantity = :stk, updated_at = NOW() WHERE id = :id AND business_id = :bid')
            ->execute(['stk' => $newVariantStock, 'id' => $variantId, 'bid' => $businessId]);

        // Deduct stock from parent product
        $stmtParentLock = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :bid FOR UPDATE');
        $stmtParentLock->execute(['id' => $productId, 'bid' => $businessId]);
        $parentStock = (int)$stmtParentLock->fetchColumn();
        $newParentStock = max(0, $parentStock - $qty);
        $db->prepare('UPDATE products SET stock_quantity = :stk, updated_at = NOW() WHERE id = :id AND business_id = :bid')
            ->execute(['stk' => $newParentStock, 'id' => $productId, 'bid' => $businessId]);

        // If warehouse specified, deduct location stock
        if ($warehouseId > 0) {
            $stmtWvs = $db->prepare('SELECT stock_quantity FROM warehouse_variant_stock WHERE warehouse_id = :wid AND variant_id = :vid FOR UPDATE');
            $stmtWvs->execute(['wid' => $warehouseId, 'vid' => $variantId]);
            $whStock = $stmtWvs->fetchColumn();
            if ($whStock !== false) {
                $newWhStock = max(0, (int)$whStock - $qty);
                $db->prepare('UPDATE warehouse_variant_stock SET stock_quantity = :stk, updated_at = NOW() WHERE warehouse_id = :wid AND variant_id = :vid')
                    ->execute(['stk' => $newWhStock, 'wid' => $warehouseId, 'vid' => $variantId]);
            }
        }

        // Log movement
        $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change,
                quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :bid, :pid, NULL, "out", :change,
                :before, :after, :reason, NOW()
            )
        ')->execute([
            'bid' => $businessId,
            'pid' => $productId,
            'change' => -$qty,
            'before' => $parentStock,
            'after' => $newParentStock,
            'reason' => "WhatsApp OCR Order {$orderNumber} - Reserved SKU {$targetSku} (Qty: {$qty})",
        ]);

        $db->commit();

        echo json_encode([
            'success' => true,
            'order_number' => $orderNumber,
            'inventory_sku' => $targetSku,
            'sku' => $targetSku,
            'variant_id' => $variantId,
            'product_name' => $variant['product_name'],
            'quantity_reserved' => $qty,
            'remaining_stock' => $newVariantStock,
            'stock_after' => $newVariantStock,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'unit_price' => (float)($variant['selling_price'] > 0 ? $variant['selling_price'] : ($variant['parent_selling_price'] ?? 0)),
            'message' => "Order {$orderNumber} confirmed against inventory SKU {$targetSku}. {$qty} piece(s) reserved.",
        ]);
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to reserve order: ' . $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------------
// Action 4: CANCEL / RELEASE (Release reserved stock back to variant)
// -------------------------------------------------------------------------
if ($action === 'cancel' || $action === 'release') {
    $invSku = trim((string)($payload['sku'] ?? $payload['inventory_sku'] ?? $_POST['sku'] ?? ''));
    $qty = max(1, (int)($payload['quantity'] ?? $_POST['quantity'] ?? 1));
    $orderNumber = trim((string)($payload['order_number'] ?? $_POST['order_number'] ?? ''));

    if ($invSku === '') {
        echo json_encode(['success' => false, 'error' => 'Inventory SKU is required to cancel reservation.']);
        exit;
    }

    $stmtV = $db->prepare('SELECT id, product_id, stock_quantity FROM product_variants WHERE sku = :sku AND business_id = :bid LIMIT 1');
    $stmtV->execute(['sku' => $invSku, 'bid' => $businessId]);
    $variant = $stmtV->fetch(PDO::FETCH_ASSOC);

    if (!$variant) {
        echo json_encode(['success' => false, 'error' => 'Variant not found for SKU ' . $invSku]);
        exit;
    }

    $variantId = (int)$variant['id'];
    $productId = (int)$variant['product_id'];

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + :qty, updated_at = NOW() WHERE id = :id AND business_id = :bid')
            ->execute(['qty' => $qty, 'id' => $variantId, 'bid' => $businessId]);

        $db->prepare('UPDATE products SET stock_quantity = stock_quantity + :qty, updated_at = NOW() WHERE id = :id AND business_id = :bid')
            ->execute(['qty' => $qty, 'id' => $productId, 'bid' => $businessId]);

        // Log movement
        $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change,
                quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :bid, :pid, NULL, "in", :change,
                0, :qty, :reason, NOW()
            )
        ')->execute([
            'bid' => $businessId,
            'pid' => $productId,
            'change' => $qty,
            'qty' => $qty,
            'reason' => "WhatsApp Order Cancellation {$orderNumber} - Released SKU {$invSku} (Qty: {$qty})",
        ]);

        $db->commit();

        $restoredStock = (int)$variant['stock_quantity'] + $qty;
        echo json_encode([
            'success' => true,
            'inventory_sku' => $invSku,
            'sku' => $invSku,
            'quantity_released' => $qty,
            'stock_after' => $restoredStock,
            'message' => "Order {$orderNumber} cancelled. {$qty} piece(s) released back to stock for SKU {$invSku}.",
        ]);
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'error' => 'Failed to release reservation: ' . $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------------------
// Action 5: LOCATION STOCK (Multi-location stock breakdown for SKU)
// -------------------------------------------------------------------------
if ($action === 'locations' || $action === 'location_stock') {
    $invSku = trim((string)($_GET['sku'] ?? $payload['sku'] ?? ''));
    if ($invSku === '') {
        echo json_encode(['success' => false, 'error' => 'SKU is required.']);
        exit;
    }

    $stmtV = $db->prepare('SELECT id, product_id, variant_name, sku, stock_quantity FROM product_variants WHERE sku = :sku AND business_id = :bid LIMIT 1');
    $stmtV->execute(['sku' => $invSku, 'bid' => $businessId]);
    $variant = $stmtV->fetch(PDO::FETCH_ASSOC);

    if (!$variant) {
        echo json_encode(['success' => false, 'error' => 'Variant not found.']);
        exit;
    }

    $variantId = (int)$variant['id'];
    $stmtWh = $db->prepare('
        SELECT w.id AS warehouse_id, w.name AS warehouse_name, w.code AS warehouse_code,
               COALESCE(wvs.stock_quantity, 0) AS stock_quantity
        FROM warehouses w
        LEFT JOIN warehouse_variant_stock wvs ON wvs.warehouse_id = w.id AND wvs.variant_id = :vid
        WHERE w.business_id = :bid AND w.status = "active"
        ORDER BY w.id ASC
    ');
    $stmtWh->execute(['vid' => $variantId, 'bid' => $businessId]);
    $locations = $stmtWh->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'inventory_sku' => $invSku,
        'variant_id' => $variantId,
        'company_stock' => (int)$variant['stock_quantity'],
        'locations' => $locations,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);

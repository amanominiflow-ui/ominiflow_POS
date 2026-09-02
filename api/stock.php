<?php
/**
 * OminiFlow POS - Stock decrement API
 * Used by Omniflow WhatsApp orders to reduce POS inventory after a chatbot purchase.
 * Additive endpoint — does not change POS checkout or product-sync behaviour.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
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

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '[]', true);
if (! is_array($payload)) {
    $payload = $_POST;
}

$productId = (int) ($payload['product_id'] ?? 0);
$variantId = (int) ($payload['variant_id'] ?? 0);
$sku = trim((string) ($payload['sku'] ?? ''));
$quantity = max(1, (int) ($payload['quantity'] ?? 0));
$reason = trim((string) ($payload['reason'] ?? 'WhatsApp order'));
$businessId = (int) ($payload['business_id'] ?? 1);

if ($quantity < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Quantity must be at least 1']);
    exit;
}

try {
    if ($productId < 1 && $sku !== '') {
        $lookup = $db->prepare('SELECT id FROM products WHERE sku = :sku OR barcode = :barcode LIMIT 1');
        $lookup->execute(['sku' => $sku, 'barcode' => $sku]);
        $found = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            $productId = (int) $found['id'];
        }
    }

    if ($productId < 1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }

    $db->beginTransaction();

    $stmt = $db->prepare('SELECT id, stock_quantity, business_id FROM products WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (! $product) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }

    $oldStock = (int) ($product['stock_quantity'] ?? 0);
    $newStock = max(0, $oldStock - $quantity);
    $bizId = (int) ($product['business_id'] ?? $businessId);

    $update = $db->prepare('UPDATE products SET stock_quantity = :stock, updated_at = NOW() WHERE id = :id');
    $update->execute(['stock' => $newStock, 'id' => $productId]);

    if ($variantId > 0) {
        try {
            $vStmt = $db->prepare('SELECT id, stock_quantity FROM product_variants WHERE id = :id AND product_id = :pid LIMIT 1');
            $vStmt->execute(['id' => $variantId, 'pid' => $productId]);
            $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
            if ($variant) {
                $vNew = max(0, (int) ($variant['stock_quantity'] ?? 0) - $quantity);
                $vUpd = $db->prepare('UPDATE product_variants SET stock_quantity = :stock, updated_at = NOW() WHERE id = :id');
                $vUpd->execute(['stock' => $vNew, 'id' => $variantId]);
            }
        } catch (\Throwable $vEx) {
            // Variants table may not exist — parent product stock is still updated.
        }
    }

    $db->commit();

    try {
        $mov = $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :biz_id, :product_id, NULL, "out", :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
            )
        ');
        $mov->execute([
            'biz_id' => $bizId > 0 ? $bizId : 1,
            'product_id' => $productId,
            'quantity_change' => -$quantity,
            'quantity_before' => $oldStock,
            'quantity_after' => $newStock,
            'reason' => $reason !== '' ? $reason : 'WhatsApp order',
        ]);
    } catch (\Throwable $mEx) {
        try {
            $mov = $db->prepare('
                INSERT INTO inventory_movements (
                    product_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
                ) VALUES (
                    :product_id, "out", :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
                )
            ');
            $mov->execute([
                'product_id' => $productId,
                'quantity_change' => -$quantity,
                'quantity_before' => $oldStock,
                'quantity_after' => $newStock,
                'reason' => $reason !== '' ? $reason : 'WhatsApp order',
            ]);
        } catch (\Throwable $mEx2) {
            // Movement log is optional; stock update is the source of truth.
        }
    }

    echo json_encode([
        'success' => true,
        'product_id' => $productId,
        'quantity_before' => $oldStock,
        'quantity_after' => $newStock,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    try {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    } catch (\Throwable $rollbackEx) {
        // ignore
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Stock decrement failed: ' . $e->getMessage(),
    ]);
}

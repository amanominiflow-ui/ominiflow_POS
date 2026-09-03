<?php
/**
 * OminiFlow POS - WhatsApp order bill API
 * Creates an order + invoice for the connected Organization ID / store.
 * Does not default to store 1.
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

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '[]', true);
if (! is_array($payload)) {
    $payload = $_POST;
}

$orderNumber = trim((string) ($payload['order_number'] ?? ''));
$orgId = trim((string) ($payload['organization_id'] ?? ''));
$businessId = (int) ($payload['business_id'] ?? 0);
$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
$deductStock = ! empty($payload['deduct_stock']);

if ($orderNumber === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing order number']);
    exit;
}

if ($items === []) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No billable line items']);
    exit;
}

if ($orgId !== '') {
    $resolved = pos_resolve_store_id($db, $orgId);
    if ($resolved > 0) {
        $businessId = $resolved;
    }
}

if ($businessId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Organization ID / store was not found']);
    exit;
}

try {
    $existing = $db->prepare('SELECT id FROM orders WHERE order_number = ? AND business_id = ? LIMIT 1');
    $existing->execute([$orderNumber, $businessId]);
    $existingOrder = $existing->fetch(PDO::FETCH_ASSOC);
    if ($existingOrder) {
        $posOrderId = (int) $existingOrder['id'];
        $inv = $db->prepare('SELECT id, invoice_number FROM invoices WHERE order_id = ? AND business_id = ? LIMIT 1');
        $inv->execute([$posOrderId, $businessId]);
        $invoice = $inv->fetch(PDO::FETCH_ASSOC) ?: [];
        echo json_encode([
            'success' => true,
            'skipped' => true,
            'reason' => 'already_billed',
            'pos_order_id' => $posOrderId,
            'pos_invoice_id' => (int) ($invoice['id'] ?? 0),
            'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $name = trim((string) ($payload['customer_name'] ?? 'WhatsApp Customer'));
    if ($name === '') {
        $name = 'WhatsApp Customer';
    }
    $phone = preg_replace('/\D+/', '', (string) ($payload['customer_phone'] ?? '')) ?? '';
    $address = trim((string) ($payload['customer_address'] ?? ''));

    $customerId = null;
    if ($phone !== '') {
        $cStmt = $db->prepare('SELECT id FROM customers WHERE phone = ? AND business_id = ? LIMIT 1');
        $cStmt->execute([$phone, $businessId]);
        $found = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            $customerId = (int) $found['id'];
        }
    }
    if ($customerId === null) {
        try {
            $insC = $db->prepare('INSERT INTO customers (business_id, name, phone, address, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
            $insC->execute([$businessId, $name, $phone !== '' ? $phone : null, $address !== '' ? $address : null]);
            $customerId = (int) $db->lastInsertId();
        } catch (\Throwable $e) {
            $walk = $db->prepare('SELECT id FROM customers WHERE business_id = ? ORDER BY id ASC LIMIT 1');
            $walk->execute([$businessId]);
            $customerId = (int) ($walk->fetchColumn() ?: 0) ?: null;
        }
    }

    $subtotal = (float) ($payload['subtotal'] ?? 0);
    $taxAmount = (float) ($payload['tax_amount'] ?? 0);
    $discountAmount = (float) ($payload['discount_amount'] ?? 0);
    $totalAmount = (float) ($payload['total_amount'] ?? ($subtotal + $taxAmount - $discountAmount));
    $paymentMethod = strtolower(trim((string) ($payload['payment_method'] ?? 'cash')));
    if (in_array($paymentMethod, ['cod', 'cash'], true)) {
        $paymentMethod = 'cash';
    } elseif (! in_array($paymentMethod, ['upi', 'card', 'credit'], true)) {
        $paymentMethod = 'upi';
    }
    $waPaid = strtolower((string) ($payload['payment_status'] ?? '')) === 'paid';
    $paymentStatus = $waPaid ? 'paid' : 'pending';
    $notes = (string) ($payload['notes'] ?? 'WhatsApp order');

    $db->beginTransaction();

    $stmtOrder = $db->prepare('
        INSERT INTO orders (
            business_id, order_number, outlet_id, customer_id, user_id, subtotal, discount_amount, discount_type,
            tax_amount, total_amount, payment_method, payment_status, order_status, fulfillment_status,
            notes, created_at, updated_at
        ) VALUES (
            :biz_id, :order_number, NULL, :customer_id, NULL, :subtotal, :discount_amount, "fixed",
            :tax_amount, :total_amount, :payment_method, :payment_status, "completed", "delivered",
            :notes, NOW(), NOW()
        )
    ');
    $stmtOrder->execute([
        'biz_id' => $businessId,
        'order_number' => $orderNumber,
        'customer_id' => $customerId,
        'subtotal' => $subtotal,
        'discount_amount' => $discountAmount,
        'tax_amount' => $taxAmount,
        'total_amount' => $totalAmount,
        'payment_method' => $paymentMethod,
        'payment_status' => $paymentStatus,
        'notes' => $notes,
    ]);
    $posOrderId = (int) $db->lastInsertId();

    try {
        $db->prepare('UPDATE orders SET sales_channel = ? WHERE id = ?')->execute(['whatsapp', $posOrderId]);
    } catch (\Throwable $e) {
        // optional column
    }

    $stmtItem = $db->prepare('
        INSERT INTO order_items (
            order_id, product_id, variant_id, product_name, product_sku, hsn_code, unit_price,
            quantity, tax_percent, tax_amount, discount_amount, line_total, created_at
        ) VALUES (
            :order_id, :product_id, :variant_id, :product_name, :product_sku, :hsn_code, :unit_price,
            :quantity, :tax_percent, :tax_amount, :discount_amount, :line_total, NOW()
        )
    ');

    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }
        $qty = max(1, (int) ($item['quantity'] ?? 1));
        $posProductId = (int) ($item['pos_product_id'] ?? 0);
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $lineTax = (float) ($item['tax_amount'] ?? 0);
        $lineTotal = (float) ($item['line_total'] ?? (($unitPrice * $qty) + $lineTax));

        $stmtItem->execute([
            'order_id' => $posOrderId,
            'product_id' => $posProductId > 0 ? $posProductId : null,
            'variant_id' => (int) ($item['pos_variant_id'] ?? 0) ?: null,
            'product_name' => (string) ($item['name'] ?? 'Item'),
            'product_sku' => (string) ($item['sku'] ?? ''),
            'hsn_code' => (string) ($item['hsn_code'] ?? '') ?: null,
            'unit_price' => $unitPrice,
            'quantity' => $qty,
            'tax_percent' => (float) ($item['tax_percent'] ?? 0),
            'tax_amount' => $lineTax,
            'discount_amount' => 0,
            'line_total' => $lineTotal,
        ]);

        if ($deductStock && $posProductId > 0) {
            $pStmt = $db->prepare('SELECT id, stock_quantity FROM products WHERE id = :id AND business_id = :bid LIMIT 1 FOR UPDATE');
            $pStmt->execute(['id' => $posProductId, 'bid' => $businessId]);
            $prod = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($prod) {
                $oldStock = (int) ($prod['stock_quantity'] ?? 0);
                $newStock = max(0, $oldStock - $qty);
                $db->prepare('UPDATE products SET stock_quantity = :stock, updated_at = NOW() WHERE id = :id AND business_id = :bid')
                    ->execute(['stock' => $newStock, 'id' => $posProductId, 'bid' => $businessId]);
                try {
                    $db->prepare('
                        INSERT INTO inventory_movements (
                            business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
                        ) VALUES (
                            :biz_id, :product_id, NULL, "out", :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
                        )
                    ')->execute([
                        'biz_id' => $businessId,
                        'product_id' => $posProductId,
                        'quantity_change' => -$qty,
                        'quantity_before' => $oldStock,
                        'quantity_after' => $newStock,
                        'reason' => $notes !== '' ? $notes : 'WhatsApp order',
                    ]);
                } catch (\Throwable $mEx) {
                    // movement log optional
                }
            }
        }
    }

    require_once __DIR__ . '/../includes/orders_db.php';
    $invoiceNumber = generate_next_invoice_number($businessId, $db);
    $taxable = max(0, $subtotal - $discountAmount);
    $cgst = round($taxAmount / 2, 2);
    $sgst = round($taxAmount - $cgst, 2);
    $invoicePaymentStatus = $waPaid ? 'paid' : 'unpaid';
    $invoiceStatus = $waPaid ? 'paid' : 'draft';

    $stmtInvoice = $db->prepare('
        INSERT INTO invoices (
            business_id, invoice_number, order_id, customer_id, user_id, invoice_date, subtotal,
            discount_amount, discount_type, taxable_amount, cgst_amount, sgst_amount, igst_amount,
            tax_amount, total_amount, amount_paid, change_amount, payment_method, payment_status,
            invoice_status, notes, created_at, updated_at
        ) VALUES (
            :biz_id, :invoice_number, :order_id, :customer_id, NULL, NOW(), :subtotal,
            :discount_amount, "fixed", :taxable_amount, :cgst_amount, :sgst_amount, 0,
            :tax_amount, :total_amount, :amount_paid, 0, :payment_method, :payment_status,
            :invoice_status, :notes, NOW(), NOW()
        )
    ');
    $stmtInvoice->execute([
        'biz_id' => $businessId,
        'invoice_number' => $invoiceNumber,
        'order_id' => $posOrderId,
        'customer_id' => $customerId,
        'subtotal' => $subtotal,
        'discount_amount' => $discountAmount,
        'taxable_amount' => $taxable,
        'cgst_amount' => $cgst,
        'sgst_amount' => $sgst,
        'tax_amount' => $taxAmount,
        'total_amount' => $totalAmount,
        'amount_paid' => $waPaid ? $totalAmount : 0,
        'payment_method' => $paymentMethod,
        'payment_status' => $invoicePaymentStatus,
        'invoice_status' => $invoiceStatus,
        'notes' => $notes,
    ]);
    $invoiceId = (int) $db->lastInsertId();

    $db->commit();

    echo json_encode([
        'success' => true,
        'pos_order_id' => $posOrderId,
        'pos_invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'stock_deducted' => $deductStock,
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
        'message' => 'Bill create failed: ' . $e->getMessage(),
    ]);
}

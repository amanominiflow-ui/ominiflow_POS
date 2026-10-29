<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/inward_db.php';

$db = get_db();
ensure_product_item_schema();
ensure_inward_schema();

$userStmt = $db->query("SELECT * FROM users WHERE role IN ('owner', 'admin') LIMIT 1");
$adminUser = $userStmt->fetch(PDO::FETCH_ASSOC);
$businessId = (int) ($adminUser['business_id'] ?? 1);
$userId = (int) $adminUser['id'];
$_SESSION['user_id'] = $userId;
$_SESSION['business_id'] = $businessId;

$whStmt = $db->prepare("SELECT id FROM warehouses WHERE business_id = :bid AND status = 'active' LIMIT 1");
$whStmt->execute(['bid' => $businessId]);
$warehouseId = (int)$whStmt->fetchColumn();

$vStmt = $db->prepare("SELECT id, name FROM vendors WHERE business_id = :bid AND status = 'active' LIMIT 1");
$vStmt->execute(['bid' => $businessId]);
$vendor = $vStmt->fetch(PDO::FETCH_ASSOC);
$vendorId = (int)$vendor['id'];
$vendorName = (string)$vendor['name'];

echo "=== TEST: MULTI-PRODUCT MATRIX INWARD ENTRY ===\n";

// 1. Create two test design products
$skuA = 'MULTI-A-' . rand(100, 999);
$skuB = 'MULTI-B-' . rand(100, 999);

$db->prepare("INSERT INTO products (business_id, name, sku, product_type, cost_price, selling_price, track_inventory, stock_quantity, status, created_at, updated_at) VALUES (:bid, 'Multi Product A', :sku, 'variable', 300, 700, 1, 0, 'active', NOW(), NOW())")
    ->execute(['bid' => $businessId, 'sku' => $skuA]);
$prodAId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO products (business_id, name, sku, product_type, cost_price, selling_price, track_inventory, stock_quantity, status, created_at, updated_at) VALUES (:bid, 'Multi Product B', :sku, 'variable', 450, 950, 1, 0, 'active', NOW(), NOW())")
    ->execute(['bid' => $businessId, 'sku' => $skuB]);
$prodBId = (int)$db->lastInsertId();

// 2. Prepare Multi-Product Matrix Payload
$multiPayload = [
    'products' => [
        [
            'product_id' => $prodAId,
            'sizes' => ['S', 'M', 'L'],
            'rows' => [
                [
                    'colour' => 'Crimson Red',
                    'colour_code' => 'RED',
                    'video_sku' => $skuA . '-RED',
                    'cost_price' => 300.00,
                    'selling_price' => 700.00,
                    'quantities' => ['S' => 2, 'M' => 3, 'L' => 5] // 10 pcs
                ],
                [
                    'colour' => 'Navy Blue',
                    'colour_code' => 'NAV',
                    'video_sku' => $skuA . '-NAV',
                    'cost_price' => 300.00,
                    'selling_price' => 700.00,
                    'quantities' => ['S' => 1, 'M' => 4, 'L' => 3] // 8 pcs
                ]
            ]
        ],
        [
            'product_id' => $prodBId,
            'sizes' => ['Free Size'],
            'rows' => [
                [
                    'colour' => 'Emerald Green',
                    'colour_code' => 'GRN',
                    'video_sku' => $skuB . '-GRN',
                    'cost_price' => 450.00,
                    'selling_price' => 950.00,
                    'quantities' => ['Free Size' => 12] // 12 pcs
                ]
            ]
        ]
    ]
];

$ref = 'REF-MULTI-' . rand(1000, 9999);
$res = create_inward_matrix_entry(
    $warehouseId,
    $multiPayload,
    date('Y-m-d'),
    $ref,
    'Multi-product inward test',
    $vendorId,
    $vendorName,
    $userId,
    $businessId
);

if (empty($res['success'])) {
    echo "FAILED: " . ($res['error'] ?? 'unknown error') . "\n";
    exit(1);
}

echo "Created Inward Entry #{$res['entry_number']} (ID: {$res['entry_id']})\n";
echo "Total Qty: {$res['total_qty']} (Expected: 30)\n";
echo "Products Count: {$res['products_count']} (Expected: 2)\n";
echo "Lines Count: {$res['lines_count']} (Expected: 7)\n";
echo "Auto Confirmed: " . ($res['auto_confirmed'] ? 'YES' : 'NO') . "\n";

if ((int)$res['total_qty'] !== 30) {
    echo "FAIL: Expected total_qty 30, got {$res['total_qty']}\n";
    exit(1);
}

if ((int)$res['products_count'] !== 2) {
    echo "FAIL: Expected products_count 2, got {$res['products_count']}\n";
    exit(1);
}

// Verify inward entry status and lines
$entry = get_inward_entry_by_id((int)$res['entry_id'], $businessId);
if (!$entry) {
    echo "FAIL: Could not fetch entry\n";
    exit(1);
}

if ($entry['status'] !== 'confirmed') {
    echo "FAIL: Expected entry status 'confirmed' since all costs were provided with vendor, got '{$entry['status']}'\n";
    exit(1);
}

// Verify company stock
$stA = (int)$db->query("SELECT stock_quantity FROM products WHERE id = {$prodAId}")->fetchColumn();
$stB = (int)$db->query("SELECT stock_quantity FROM products WHERE id = {$prodBId}")->fetchColumn();
if ($stA !== 18) {
    echo "FAIL: Expected Product A stock 18, got {$stA}\n";
    exit(1);
}
if ($stB !== 12) {
    echo "FAIL: Expected Product B stock 12, got {$stB}\n";
    exit(1);
}

echo "SUCCESS: Multi-product matrix inward verified perfectly!\n";

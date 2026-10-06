<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/products_db.php';
require_once __DIR__ . '/../includes/storefront_db.php';

echo "=== Comprehensive Store SKU & ID Tests ===\n";

// 1. URL Generation Tests
$biz = [
    'id' => 1,
    'store_slug' => 'ash',
    'custom_domain' => 'www.ashcollective.in',
    'domain_status' => 'active',
];

$prodWithSku = ['id' => 197, 'name' => 'Kurti 2P031', 'sku' => '2P031'];
$prodNoSku = ['id' => 197, 'name' => 'Kurti Without SKU', 'sku' => ''];
$prodNullSku = ['id' => 197, 'name' => 'Kurti Null SKU', 'sku' => null];

$urlSku = public_store_product_url($biz, $prodWithSku);
echo "1. Product with SKU: " . $urlSku . "\n";
assert(str_contains($urlSku, 'sku=2P031'), "Should contain sku=2P031");

$urlNoSku = public_store_product_url($biz, $prodNoSku);
echo "2. Product with empty SKU: " . $urlNoSku . "\n";
assert(str_contains($urlNoSku, 'id=197'), "Should contain id=197");

$urlNullSku = public_store_product_url($biz, $prodNullSku);
echo "3. Product with null SKU: " . $urlNullSku . "\n";
assert(str_contains($urlNullSku, 'id=197'), "Should contain id=197");

// 2. Simulated Request Resolution Logic Test
function simulate_product_resolution(array $getParams, callable $skuLookup, callable $idLookup): ?array {
    $rawId = trim((string) ($getParams['id'] ?? ''));
    $targetSku = trim((string) ($getParams['sku'] ?? ''));
    $targetId = is_numeric($rawId) ? (int) $rawId : 0;
    if ($targetSku === '' && !is_numeric($rawId) && $rawId !== '' && !str_starts_with($rawId, '{{')) {
        $targetSku = $rawId;
    }
    $product = null;
    // 1. If SKU is given, search by SKU first
    if ($targetSku !== '') {
        $product = $skuLookup($targetSku);
    }
    // 2. If not found and target ID is valid, search by ID
    if (!$product && $targetId > 0) {
        $product = $idLookup($targetId);
    }
    // 3. Fallback: if rawId was numeric, it might also be a numeric SKU
    if (!$product && $rawId !== '') {
        $product = $skuLookup($rawId);
    }
    return $product;
}

$mockDb = [
    'by_id' => [
        197 => ['id' => 197, 'name' => 'Kurti 2P031', 'sku' => '2P031'],
        200 => ['id' => 200, 'name' => 'Plain Kurti', 'sku' => ''],
    ],
    'by_sku' => [
        '2P031' => ['id' => 197, 'name' => 'Kurti 2P031', 'sku' => '2P031'],
        'NUM123' => ['id' => 205, 'name' => 'Numeric SKU Product', 'sku' => 'NUM123'],
        '197' => ['id' => 300, 'name' => 'Product having 197 as SKU', 'sku' => '197'],
    ]
];

$skuLookup = fn($sku) => $mockDb['by_sku'][$sku] ?? null;
$idLookup = fn($id) => $mockDb['by_id'][$id] ?? null;

// Case A: Access by sku=2P031
$resA = simulate_product_resolution(['sku' => '2P031', 'page' => 'product'], $skuLookup, $idLookup);
echo "4. Resolution with ?sku=2P031 -> " . ($resA ? $resA['name'] : 'NULL') . "\n";
assert($resA !== null && $resA['id'] === 197, "Should resolve by SKU");

// Case B: Access by id=197 (legacy ID link)
$resB = simulate_product_resolution(['id' => '197', 'page' => 'product'], $skuLookup, $idLookup);
echo "5. Resolution with ?id=197 -> " . ($resB ? $resB['name'] : 'NULL') . "\n";
assert($resB !== null && $resB['id'] === 197, "Should resolve by ID");

// Case C: Access by id=2P031 (SKU in id param)
$resC = simulate_product_resolution(['id' => '2P031', 'page' => 'product'], $skuLookup, $idLookup);
echo "6. Resolution with ?id=2P031 -> " . ($resC ? $resC['name'] : 'NULL') . "\n";
assert($resC !== null && $resC['id'] === 197, "Should resolve SKU passed in id param");

// Case D: Product without SKU accessed by ID
$resD = simulate_product_resolution(['id' => '200', 'page' => 'product'], $skuLookup, $idLookup);
echo "7. Resolution with ?id=200 (no SKU) -> " . ($resD ? $resD['name'] : 'NULL') . "\n";
assert($resD !== null && $resD['id'] === 200, "Should resolve product without SKU");

echo "\nAll comprehensive tests passed successfully!\n";

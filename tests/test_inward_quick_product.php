<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/inward_db.php';
require_once dirname(__DIR__) . '/includes/products_db.php';

echo "=== TESTING QUICK CREATE PRODUCT & INWARD ENTRY SELECTOR ===\n";

// 1. Setup session and auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = get_db();
$userStmt = $db->query("SELECT * FROM users WHERE role IN ('owner', 'admin') LIMIT 1");
$adminUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$adminUser) {
    echo "FAILED: No admin user found.\n";
    exit(1);
}

$_SESSION['user_id'] = (int) $adminUser['id'];
$_SESSION['business_id'] = (int) ($adminUser['business_id'] ?? 1);
$currentBid = (int) $_SESSION['business_id'];

echo "[OK] Authenticated as user {$adminUser['id']} (Business: {$currentBid})\n";

// 2. Test inward_count_catalog before creation
$initialCatalog = inward_count_catalog($currentBid);
$initialCount = count($initialCatalog);
echo "[OK] Initial catalog items for business {$currentBid}: {$initialCount}\n";

// 3. Test quick product creation logic
$uniqueCode = substr(uniqid(), -6);
$testName = "Inward Test Item " . $uniqueCode;
$testSku = strtoupper("INW-" . $uniqueCode);
$testCost = 150.00;
$testPrice = 299.00;

$saveRes = save_product([
    'name' => $testName,
    'sku' => $testSku,
    'barcode' => $testSku,
    'cost_price' => $testCost,
    'selling_price' => $testPrice,
    'track_inventory' => 1,
    'initial_stock' => 0,
    'status' => 'active',
], null, null, (int)$adminUser['id'], $currentBid);

if (empty($saveRes['success']) || empty($saveRes['product_id'])) {
    echo "FAILED: save_product failed: " . json_encode($saveRes) . "\n";
    exit(1);
}

$newProdId = (int) $saveRes['product_id'];
echo "[OK] Created test product ID {$newProdId} with SKU {$testSku}\n";

// 4. Verify catalog now contains this product
$updatedCatalog = inward_count_catalog($currentBid);
$found = false;
foreach ($updatedCatalog as $prod) {
    if ((int)$prod['id'] === $newProdId) {
        $found = true;
        if ($prod['name'] !== $testName || $prod['sku'] !== $testSku) {
            echo "FAILED: Catalog data mismatch: " . json_encode($prod) . "\n";
            exit(1);
        }
        break;
    }
}

if (!$found) {
    echo "FAILED: New product not found in inward_count_catalog.\n";
    exit(1);
}
echo "[OK] Inward catalog contains new product with correct name & SKU\n";

// 5. Test multi-tenant isolation: check another business ID
$stmtOther = $db->prepare("SELECT id FROM businesses WHERE id != :bid LIMIT 1");
$stmtOther->execute([':bid' => $currentBid]);
$otherBid = $stmtOther->fetchColumn();

if ($otherBid) {
    $otherCatalog = inward_count_catalog((int)$otherBid);
    foreach ($otherCatalog as $p) {
        if ((int)$p['id'] === $newProdId) {
            echo "FAILED: Multi-tenant breach! Product {$newProdId} appeared in business {$otherBid}\n";
            exit(1);
        }
    }
    echo "[OK] Multi-tenant isolation verified: Product not present in business {$otherBid}\n";
}

// 6. Test HTML output of inward-entry.php to ensure selector option and modal are rendered
ob_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/inward-entry.php';
$html = ob_get_clean();

if (strpos($html, '__add_new__') === false) {
    echo "FAILED: '__add_new__' selector option logic missing from inward-entry.php\n";
    exit(1);
}
echo "[OK] '__add_new__' selector option logic confirmed in inward-entry.php\n";

if (strpos($html, 'id="quickProductModal"') === false) {
    echo "FAILED: 'quickProductModal' modal markup missing from inward-entry.php\n";
    exit(1);
}
echo "[OK] 'quickProductModal' modal markup confirmed in inward-entry.php\n";

if (strpos($html, '+ Add New Product...') === false) {
    echo "FAILED: '+ Add New Product...' dropdown text missing from inward-entry.php\n";
    exit(1);
}
echo "[OK] '+ Add New Product...' option text confirmed\n";

// Clean up test product
$db->prepare("DELETE FROM products WHERE id = :id AND business_id = :bid")->execute([':id' => $newProdId, ':bid' => $currentBid]);
echo "[OK] Cleaned up test product.\n";

echo "\n>>> ALL QUICK CREATE SELECTOR TESTS PASSED! <<<\n";

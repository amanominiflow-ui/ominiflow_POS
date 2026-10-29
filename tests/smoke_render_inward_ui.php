<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$db = get_db();
$adminUser = $db->query("SELECT * FROM users WHERE role IN ('owner', 'admin') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adminUser) {
    echo "No admin user found\n";
    exit(1);
}

$_SESSION['user_id'] = (int)$adminUser['id'];
$_SESSION['business_id'] = (int)($adminUser['business_id'] ?? 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/inward-entry.php';
$_SERVER['HTTP_HOST'] = 'localhost';

// Find a cost_pending entry or create a test one to check cost pending UI
$pStmt = $db->query("SELECT id FROM inward_entries WHERE status = 'cost_pending' AND business_id = " . (int)$_SESSION['business_id'] . " LIMIT 1");
$pendingId = $pStmt->fetchColumn();
if (!$pendingId) {
    // Insert a dummy cost_pending entry for UI testing
    $db->prepare("INSERT INTO inward_entries (business_id, entry_number, warehouse_id, entry_date, status, purchase_status, is_sellable, created_at, updated_at) VALUES (:bid, 'INW-TEST-PENDING', 1, CURDATE(), 'cost_pending', 'pending', 0, NOW(), NOW())")
        ->execute(['bid' => (int)$_SESSION['business_id']]);
    $pendingId = $db->lastInsertId();
    $db->prepare("INSERT INTO inward_lines (inward_entry_id, product_id, size, colour, quantity, cost_status, created_at) VALUES (:eid, 1, 'M', 'Red', 5, 'pending', NOW())")
        ->execute(['eid' => $pendingId]);
}
$_GET = [];
ob_start();
require __DIR__ . '/../inward-entry.php';
$htmlCreation = ob_get_clean();

$checksCreation = [
    'matrixProductsContainer' => 'Container for multi-product cards',
    'Add Another Product to this Inward' => 'Button to add another product',
    'matrixSummaryProductsCount' => 'Products counter in summary',
    'addNewMatrixProductCard' => 'JS function for dynamic product cards',
];

$allPassed = true;
echo "--- Testing Matrix Tab Creation UI ---\n";
foreach ($checksCreation as $pattern => $desc) {
    if (strpos($htmlCreation, $pattern) !== false) {
        echo "[PASS] Found: '{$pattern}' ({$desc})\n";
    } else {
        echo "[FAIL] Missing: '{$pattern}' ({$desc})\n";
        $allPassed = false;
    }
}

echo "\n--- Testing Cost Pending Confirmation UI ---\n";
$_GET['id'] = (string)$pendingId;
ob_start();
require __DIR__ . '/../inward-entry.php';
$htmlCostPending = ob_get_clean();

$checksCost = [
    'Quick Fill All Variants' => 'Toolbar for quick filling variant costs',
    'quickFillCost' => 'Default cost input',
    'quickFillPrice' => 'Default price input',
    'applyQuickFillCostPrice' => 'JS function to apply quick fill',
    'copyCostDown' => 'Copy cost down button',
    'copyPriceDown' => 'Copy price down button',
];

foreach ($checksCost as $pattern => $desc) {
    if (strpos($htmlCostPending, $pattern) !== false) {
        echo "[PASS] Found: '{$pattern}' ({$desc})\n";
    } else {
        echo "[FAIL] Missing: '{$pattern}' ({$desc})\n";
        $allPassed = false;
    }
}

// Clean up dummy entry
$db->prepare("DELETE FROM inward_entries WHERE id = :id")->execute(['id' => $pendingId]);

if ($allPassed) {
    echo "\n>>> ALL UI CHECKS PASSED SUCCESSFULLY <<<\n";
} else {
    echo "\n>>> SOME CHECKS FAILED <<<\n";
    exit(1);
}

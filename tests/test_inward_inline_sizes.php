<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/inward_db.php';
require_once dirname(__DIR__) . '/includes/products_db.php';

echo "=== TESTING INLINE MULTIPLE SIZES IN INWARD ENTRY ===\n";

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

// 1. Verify HTML in inward-entry.php
ob_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/inward-entry.php';
$html = ob_get_clean();

// Ensure modal does NOT have the complex variant section
if (strpos($html, 'id="qp_quick_sizes"') !== false) {
    echo "FAILED: 'qp_quick_sizes' still present in modal markup.\n";
    exit(1);
}
echo "[OK] Clean Quick Add Product modal verified (sizes/colours block removed from modal)\n";

// Ensure inline tag input for sizes exists
$checks = [
    'createTagInput' => 'Tag input generator function',
    'inw-size-box' => 'Size tag box class',
    'inw-chip' => 'Tag chip styling and class',
    'splitRowBySizes' => 'Row split function',
    'lines.push' => 'Line expansion logic',
];

foreach ($checks as $needle => $label) {
    if (strpos($html, $needle) === false) {
        echo "FAILED: Missing '{$needle}' ({$label}) in inward-entry.php\n";
        exit(1);
    }
    echo "[OK] Found {$label} in inward-entry.php\n";
}

// 2. Test saving inward count with multiple sizes for a single product (like demo with L, M, S)
$demoProd = $db->query("SELECT id FROM products WHERE business_id = {$currentBid} AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$demoProd) {
    $res = save_product(['name' => 'Demo Inline Item', 'sku' => 'INLINE-' . substr(uniqid(), -4)], null, null, (int)$adminUser['id'], $currentBid);
    $demoId = (int)$res['product_id'];
} else {
    $demoId = (int)$demoProd['id'];
}

$whId = (int) $db->query("SELECT id FROM warehouses WHERE business_id = {$currentBid} AND status = 'active' LIMIT 1")->fetchColumn();
if ($whId <= 0) {
    $db->prepare("INSERT INTO warehouses (business_id, name, code, is_default, status) VALUES ({$currentBid}, 'Inline Test WH', 'ITW', 1, 'active')")->execute();
    $whId = (int) $db->lastInsertId();
}

// Simulate user entering L, M, S for that product with empty colour
$rawEnteredSizes = ['l', 'm', 's'];
$expandedLines = [];
foreach ($rawEnteredSizes as $sz) {
    $expandedLines[] = [
        'product_id' => $demoId,
        'size' => $sz,
        'colour' => '', // empty colour from user
        'quantity' => 2,
    ];
}

$inwardRes = create_inward_entry($whId, $expandedLines, date('Y-m-d'), 'Inline multiple sizes test', null, (int)$adminUser['id'], $currentBid);
if (empty($inwardRes['success']) || empty($inwardRes['entry_id'])) {
    echo "FAILED: create_inward_entry failed: " . json_encode($inwardRes) . "\n";
    exit(1);
}

$entryId = (int) $inwardRes['entry_id'];
echo "[OK] Created inward entry #{$inwardRes['entry_number']} with 3 sizes and empty colour\n";

// Verify saved lines in database
$saved = $db->query("SELECT * FROM inward_lines WHERE inward_entry_id = {$entryId} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
if (count($saved) !== 3) {
    echo "FAILED: Expected 3 lines, found " . count($saved) . "\n";
    exit(1);
}

foreach ($saved as $idx => $line) {
    $expectedSize = $rawEnteredSizes[$idx];
    if (strcasecmp($line['size'], $expectedSize) !== 0) {
        echo "FAILED: Expected size {$expectedSize}, got {$line['size']}\n";
        exit(1);
    }
    if ($line['colour'] !== 'Standard') {
        echo "FAILED: Expected fallback colour 'Standard', got {$line['colour']}\n";
        exit(1);
    }
    if ((int)$line['quantity'] !== 2) {
        echo "FAILED: Expected quantity 2, got {$line['quantity']}\n";
        exit(1);
    }
}
echo "[OK] All 3 sizes saved with fallback colour 'Standard' and quantity 2\n";

// Clean up test inward entry
$db->prepare("DELETE FROM inward_lines WHERE inward_entry_id = :eid")->execute([':eid' => $entryId]);
$db->prepare("DELETE FROM inward_entries WHERE id = :eid")->execute([':eid' => $entryId]);
echo "[OK] Cleaned up test inward entry.\n";

echo "\n>>> ALL INLINE MULTIPLE SIZES TESTS PASSED PERFECTLY! <<<\n";

<?php
/**
 * Multi-Tenant Isolation Test for Purchase Returns
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/purchases_db.php';
require_once __DIR__ . '/../includes/purchase_returns_db.php';

echo "=== TESTING MULTI-TENANT ISOLATION ===\n";

// Business 2 returns
$biz2Returns = get_purchase_returns([], 50, 0, 2);
echo "Business 2 Returns count: " . count($biz2Returns) . "\n";
assert(count($biz2Returns) >= 2, "Business 2 should have at least 2 returns");

// Business 1 returns
$biz1Returns = get_purchase_returns([], 50, 0, 1);
echo "Business 1 Returns count: " . count($biz1Returns) . "\n";

// Ensure no business 2 return appears in business 1
foreach ($biz1Returns as $b1r) {
    assert((int)$b1r['business_id'] === 1, "Data leakage! Business 1 query returned business " . $b1r['business_id']);
}
foreach ($biz2Returns as $b2r) {
    assert((int)$b2r['business_id'] === 2, "Data leakage! Business 2 query returned business " . $b2r['business_id']);
}

echo "=== MULTI-TENANT ISOLATION VERIFIED 100% ===\n";

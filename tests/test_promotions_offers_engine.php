<?php
/**
 * Test Suite: Promotions & Offers Engine Verification
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/promotions_db.php';

echo "=== Starting Promotions & Offers Engine Tests ===\n";

ensure_promotions_coupons_schema();
$db = get_db();
$bid = 1;

// 1. Verify Schema
echo "[Test 1] Verifying schema...\n";
$stmt = $db->query("SHOW COLUMNS FROM `promotions` LIKE 'starts_at'");
assert(!empty($stmt->fetch()), "promotions.starts_at column must exist");
$stmt = $db->query("SHOW COLUMNS FROM `promotions` LIKE 'ends_at'");
assert(!empty($stmt->fetch()), "promotions.ends_at column must exist");
$stmt = $db->query("SHOW COLUMNS FROM `promotions` LIKE 'target_type'");
assert(!empty($stmt->fetch()), "promotions.target_type column must exist");

$stmt = $db->query("SHOW TABLES LIKE 'promotion_items'");
assert(!empty($stmt->fetch()), "promotion_items table must exist");
echo "  -> Schema verification PASS\n";

// 2. Fetch sample active products
echo "[Test 2] Checking eligible products...\n";
$eligibleProds = get_offer_eligible_products($bid);
assert(!empty($eligibleProds), "Should have active products in catalog");
$prod1 = $eligibleProds[0];
$prod2 = $eligibleProds[1] ?? $eligibleProds[0];
echo "  -> Found products: #{$prod1['id']} ({$prod1['name']}) and #{$prod2['id']} ({$prod2['name']})\n";

// 3. Create Offer A (20% off on prod1 and prod2)
echo "[Test 3] Creating Offer A (20% off)...\n";
$today = date('Y-m-d');
$nextWeek = date('Y-m-d', strtotime('+7 days'));

$resA = save_item_offer([
    'name' => 'Diwali Mega Sale',
    'discount_percent' => 20,
    'start_date' => $today,
    'end_date' => $nextWeek,
    'product_ids' => [$prod1['id'], $prod2['id']],
], $bid);

assert(!empty($resA['success']), "Offer A should create successfully");
$promoAId = (int)$resA['promotion_id'];
echo "  -> Created offer A ID: {$promoAId}\n";

// Verify promotion_items table
$itemCheck = $db->query("SELECT * FROM promotion_items WHERE promotion_id = {$promoAId}")->fetchAll(PDO::FETCH_ASSOC);
assert(count($itemCheck) >= 1, "promotion_items should contain entries for offer A");
echo "  -> Items attached to Offer A: " . count($itemCheck) . "\n";

// 4. Test "One item, one active offer" rule replacement
echo "[Test 4] Testing One item, one active offer replacement...\n";
$resB = save_item_offer([
    'name' => 'Flash Clearance',
    'discount_percent' => 35,
    'start_date' => $today,
    'end_date' => $nextWeek,
    'product_ids' => [$prod1['id']], // Prod1 replaced into Offer B!
], $bid);

assert(!empty($resB['success']), "Offer B should create successfully");
$promoBId = (int)$resB['promotion_id'];

// Check prod1 in promotion_items
$prod1Items = $db->query("SELECT * FROM promotion_items WHERE business_id = {$bid} AND product_id = {$prod1['id']}")->fetchAll(PDO::FETCH_ASSOC);
assert(count($prod1Items) === 1, "Product 1 must have exactly ONE active offer entry");
assert((int)$prod1Items[0]['promotion_id'] === $promoBId, "Product 1 must be attached to the new Offer B");
assert((float)$prod1Items[0]['discount_percent'] === 35.00, "Product 1 discount percent must be 35%");
echo "  -> Pass: Product 1 was superseded cleanly into Offer B\n";

// 5. Test POS automatic calculation for cart
echo "[Test 5] Testing POS automatic calculation on cart items...\n";
$cart = [
    [
        'product_id' => $prod1['id'],
        'sku' => $prod1['item_code'],
        'price' => (float)$prod1['selling_price'],
        'quantity' => 2,
    ]
];
$subtotal = (float)$prod1['selling_price'] * 2;
$calc = calculate_promotions_for_cart($cart, $subtotal, $bid, 0); // promotionId = 0 (cashier manual = none)

$expectedPerUnit = round((float)$prod1['selling_price'] * 0.35, 2);
$expectedTotal = round($expectedPerUnit * 2, 2);

echo "  -> Subtotal: {$subtotal}, Auto Calculated Discount: {$calc['total_discount']} (Expected: {$expectedTotal})\n";
assert(abs($calc['total_discount'] - $expectedTotal) < 0.05, "Discount calculated must match expected 35% savings");
assert(!empty($calc['applied_promotions']), "applied_promotions must not be empty");
echo "  -> Applied promo: {$calc['applied_promotions'][0]['name']}\n";

// 6. Test out-of-window date offer does not apply
echo "[Test 6] Testing date scheduling boundary...\n";
$expiredStart = date('Y-m-d', strtotime('-10 days'));
$expiredEnd = date('Y-m-d', strtotime('-2 days'));

$resExpired = save_item_offer([
    'name' => 'Old Expired Offer',
    'discount_percent' => 50,
    'start_date' => $expiredStart,
    'end_date' => $expiredEnd,
    'product_ids' => [$prod1['id']],
], $bid);

$calcExpired = calculate_promotions_for_cart($cart, $subtotal, $bid, 0);
assert($calcExpired['total_discount'] === 0.00, "Expired offer must NOT apply to cart");
echo "  -> Pass: Expired offer was ignored as expected\n";

// Clean up test promotions
$db->exec("DELETE FROM promotion_items WHERE promotion_id IN ({$promoAId}, {$promoBId}, {$resExpired['promotion_id']})");
$db->exec("DELETE FROM promotions WHERE id IN ({$promoAId}, {$promoBId}, {$resExpired['promotion_id']})");
echo "  -> Test data cleaned up\n";

echo "=== All Promotions & Offers Engine Tests Passed Successfully! ===\n";

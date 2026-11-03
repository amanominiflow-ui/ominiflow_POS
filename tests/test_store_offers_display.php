<?php
/**
 * Test: Storefront Active Offers Display & Cart Integration
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/promotions_db.php';
require_once __DIR__ . '/../includes/storefront_db.php';

echo "=== Testing Storefront Offers Display & Integration ===\n";

$bid = 1;
ensure_promotions_coupons_schema();
$db = get_db();

// 1. Get a product to test with stock > 0
$stmt = $db->query("SELECT * FROM products WHERE business_id = {$bid} AND status = 'active' AND selling_price > 0 AND stock_quantity > 0 LIMIT 1");
$prod = $stmt->fetch(PDO::FETCH_ASSOC);
assert(!empty($prod), "Must have an active product with selling price > 0");
$pid = (int) $prod['id'];
$origSellingPrice = (float) $prod['selling_price'];
echo "Selected product: #{$pid} '{$prod['name']}' with selling price: ₹{$origSellingPrice}\n";

// 2. Before offer: storefront_parse_product_display_info should have no offer
$infoBefore = storefront_parse_product_display_info($prod, $bid);
echo "Before offer: selling_price = {$infoBefore['selling_price']}, has_offer = " . ($infoBefore['has_offer'] ? 'true' : 'false') . "\n";
assert(empty($infoBefore['has_offer']), "Before offer, product must not have has_offer");

// 3. Create a 25% active offer on this product
$today = date('Y-m-d');
$nextWeek = date('Y-m-d', strtotime('+7 days'));
$offerRes = save_item_offer([
    'name' => 'Storefront Festive Offer',
    'discount_percent' => 25,
    'start_date' => $today,
    'end_date' => $nextWeek,
    'product_ids' => [$pid],
], $bid);
assert(!empty($offerRes['success']), "Offer must save successfully");
$promoId = (int) $offerRes['promotion_id'];
echo "Created active offer ID: {$promoId} (25% off)\n";

// 4. Test storefront_parse_product_display_info with active offer
$infoWithOffer = storefront_parse_product_display_info($prod, $bid);
echo "With offer: selling_price = {$infoWithOffer['selling_price']}, mrp = {$infoWithOffer['mrp']}, discount_percent = {$infoWithOffer['discount_percent']}%, has_offer = " . ($infoWithOffer['has_offer'] ? 'true' : 'false') . "\n";

$expectedDiscounted = max(0.00, round($origSellingPrice * 0.75, 2));
assert(!empty($infoWithOffer['has_offer']), "Product must have has_offer = true on store");
assert($infoWithOffer['offer_name'] === 'Storefront Festive Offer', "offer_name must match");
assert($infoWithOffer['discount_percent'] === 25, "discount_percent must be 25%");
assert(abs($infoWithOffer['selling_price'] - $expectedDiscounted) < 0.05, "Storefront selling_price must be discounted by 25%");

// 5. Test storefront_resolve_sale_line for Store Add to Cart
$line = storefront_resolve_sale_line($bid, $pid, 0);
assert(!empty($line['success']), "Sale line must resolve successfully");
echo "Storefront cart resolved unit price = {$line['unit_price']} (Expected: {$expectedDiscounted})\n";
assert(abs($line['unit_price'] - $expectedDiscounted) < 0.05, "Store cart unit_price must reflect the 25% offer discount");

// 6. Test offers_modal.php contains closeModal() on save success
$modalContent = file_get_contents(__DIR__ . '/../includes/offers_modal.php');
assert(strpos($modalContent, 'closeModal()') !== false, "offers_modal.php must call closeModal() on save success");
echo "offers_modal.php verified: contains closeModal() on save\n";

// Clean up test offer
$db->exec("DELETE FROM promotion_items WHERE promotion_id = {$promoId}");
$db->exec("DELETE FROM promotions WHERE id = {$promoId}");

// Verify after deletion
$infoAfter = storefront_parse_product_display_info($prod, $bid);
assert(empty($infoAfter['has_offer']), "After offer cleanup, product must not have has_offer");
assert(abs($infoAfter['selling_price'] - $origSellingPrice) < 0.05, "After offer cleanup, selling_price must return to original");

echo "=== All Storefront Offers Tests Passed 100%! ===\n";

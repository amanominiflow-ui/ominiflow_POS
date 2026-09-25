<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/purchase_entry_db.php';

$failures = 0;
function ok(string $msg): void { echo "[OK] {$msg}\n"; }
function fail(string $msg): void { global $failures; $failures++; echo "[FAIL] {$msg}\n"; }

try {
    ensure_purchase_entry_schema();
    ok('schema');
} catch (Throwable $e) {
    fail('schema ' . $e->getMessage());
}

$hidden = purchase_entry_strip_cost([
    'id' => 1,
    'cost_total' => '350.00',
    'lines' => [['sku' => 'KRT-0001', 'cost_price' => '350.00', 'selling_price' => '799.00']],
], false);
if (array_key_exists('cost_price', $hidden['lines'][0]) && $hidden['lines'][0]['cost_price'] === null && !isset($hidden['cost_total'])) {
    ok('cost stripped for non-owner');
} else {
    fail('cost was still visible');
}

$visible = purchase_entry_strip_cost([
    'lines' => [['cost_price' => '350.00']],
], true);
if (($visible['lines'][0]['cost_price'] ?? '') === '350.00') {
    ok('owner still receives cost');
} else {
    fail('owner cost missing');
}

$bad = purchase_entry_validate_lines([[
    'category_id' => 0,
    'colour' => 'Green',
    'size' => 'M',
    'selling_price' => 799,
    'quantity' => 2,
]], 1, false);
if (empty($bad['success'])) {
    ok('missing category rejected');
} else {
    fail('missing category was accepted');
}

$db = get_db();
$catId = (int) $db->query('SELECT id FROM categories ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($catId > 0) {
    $qty = purchase_entry_validate_lines([[
        'category_id' => $catId,
        'colour' => 'Green',
        'size' => 'M',
        'selling_price' => 10,
        'quantity' => 0,
    ]], (int) $db->query('SELECT business_id FROM categories WHERE id = ' . $catId)->fetchColumn(), false);
    if (empty($qty['success']) && str_contains(strtolower((string) ($qty['error'] ?? '')), 'quantity')) {
        ok('zero quantity rejected');
    } else {
        fail('zero quantity: ' . ($qty['error'] ?? 'accepted'));
    }
} else {
    ok('no category row, quantity check skipped');
}

echo $failures === 0 ? "\nAll smoke checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);

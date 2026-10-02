<?php
declare(strict_types=1);

/**
 * Regression check for parse_variant_size_colour / resolve display helpers.
 * Run: php tests/smoke_variant_size_colour.php
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/orders_db.php';

$failures = 0;

function assert_eq(string $label, mixed $got, mixed $expected): void {
    global $failures;
    if ($got !== $expected) {
        $failures++;
        echo "[FAIL] {$label}: expected " . json_encode($expected) . ', got ' . json_encode($got) . "\n";
        return;
    }
    echo "[OK] {$label}\n";
}

$cases = [
    [
        'label' => 'size-only variant name',
        'in' => ['variant_name' => 'M', 'attribute_values' => ''],
        'out' => ['size' => 'M', 'colour' => ''],
    ],
    [
        'label' => 'JSON Size key',
        'in' => ['variant_name' => 'L', 'attribute_values' => '{"Size":"L"}'],
        'out' => ['size' => 'L', 'colour' => ''],
    ],
    [
        'label' => 'colour then size in variant name',
        'in' => ['variant_name' => 'Green / M', 'attribute_values' => ''],
        'out' => ['size' => 'M', 'colour' => 'Green'],
    ],
    [
        'label' => 'Color and Size JSON',
        'in' => ['variant_name' => 'Green / M', 'attribute_values' => '{"Color":"Green","Size":"M"}'],
        'out' => ['size' => 'M', 'colour' => 'Green'],
    ],
    [
        'label' => 'single colour value',
        'in' => ['variant_name' => 'Navy', 'attribute_values' => '{"Color":"Navy"}'],
        'out' => ['size' => '', 'colour' => 'Navy'],
    ],
];

foreach ($cases as $case) {
    $got = parse_variant_size_colour($case['in']);
    assert_eq($case['label'] . ' size', $got['size'], $case['out']['size']);
    assert_eq($case['label'] . ' colour', $got['colour'], $case['out']['colour']);
}

// Stored line values must win over variant (no DB).
$line = [
    'size' => 'XL',
    'colour' => 'Red',
    'variant_id' => 0,
    'product_name' => 'SKU-TEST',
    'product_sku' => 'SKU-TEST',
];
$resolved = resolve_order_item_size_colour($line, null);
assert_eq('stored size preserved', $resolved['size'], 'XL');
assert_eq('stored colour preserved', $resolved['colour'], 'Red');

exit($failures > 0 ? 1 : 0);

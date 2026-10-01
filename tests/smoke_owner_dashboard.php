<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/owner_dashboard_db.php';

$failures = 0;
function ok(string $m): void { echo "[OK] {$m}\n"; }
function fail(string $m): void { global $failures; $failures++; echo "[FAIL] {$m}\n"; }

$bid = (int) get_db()->query('SELECT id FROM businesses ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($bid < 1) {
    $bid = 1;
}

try {
    $snap = owner_dashboard_snapshot($bid);
    ok('snapshot runs');
} catch (Throwable $e) {
    fail('snapshot: ' . $e->getMessage());
    $snap = owner_dashboard_empty_snapshot($bid);
}

$required = ['meta', 'sales', 'channels', 'outlets', 'fulfillment', 'payments', 'registers', 'inventory', 'purchase', 'transfers', 'alerts'];
foreach ($required as $key) {
    if (array_key_exists($key, $snap)) {
        ok("key {$key}");
    } else {
        fail("missing key {$key}");
    }
}

if (!empty($snap['meta']['business_name'])) {
    ok('business_name from settings');
} else {
    fail('business_name empty');
}

if (isset($snap['sales']['today']['revenue']) && is_numeric($snap['sales']['today']['revenue'])) {
    ok('today revenue numeric');
} else {
    fail('today revenue invalid');
}

$empty = owner_dashboard_empty_snapshot($bid);
if (($empty['sales']['today']['orders'] ?? -1) === 0) {
    ok('empty snapshot');
} else {
    fail('empty snapshot shape');
}

echo $failures === 0 ? "\nAll owner dashboard smoke checks passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);

<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/purchase_entry_db.php';

ensure_purchase_entry_schema();
$res = purchase_entry_repair_prefixed_product_names();
echo 'Fixed: ' . (int) ($res['fixed'] ?? 0) . ', skipped: ' . (int) ($res['skipped'] ?? 0) . PHP_EOL;
exit(0);

<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/purchase_entry_db.php';
ensure_purchase_entry_schema();
$db = get_db();
echo "purchase_entries: " . $db->query('SELECT COUNT(*) FROM purchase_entries')->fetchColumn() . "\n";
echo "purchase_entry_lines: " . $db->query('SELECT COUNT(*) FROM purchase_entry_lines')->fetchColumn() . "\n";
$rows = $db->query('SELECT id, business_id, entry_number, status, vendor_id FROM purchase_entries ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/owner_dashboard_db.php';

$db = get_db();
echo "Users:\n";
foreach ($db->query('SELECT id, email, role, status FROM users ORDER BY id ASC LIMIT 15') as $r) {
    echo "  #{$r['id']} {$r['email']} role=" . json_encode($r['role']) . " status={$r['status']}\n";
}
echo "\nRoles table:\n";
try {
    foreach ($db->query('SELECT id, name FROM roles ORDER BY id ASC LIMIT 15') as $r) {
        echo "  role id {$r['id']} name=" . json_encode($r['name']) . "\n";
    }
} catch (Throwable $e) {
    echo "  (no roles table)\n";
}
echo "\nowner_dashboard_db.php exists: " . (is_file(__DIR__ . '/../includes/owner_dashboard_db.php') ? 'yes' : 'no') . "\n";
echo "dashboard has Owner Control: " . (str_contains((string) file_get_contents(__DIR__ . '/../dashboard.php'), 'Owner Control') ? 'yes' : 'no') . "\n";

if (isset($argv[1]) && $argv[1] === '--fix-roles') {
    $fixed = $db->exec("UPDATE users SET role = 'admin' WHERE TRIM(COALESCE(role, '')) = ''");
    echo "\nFixed blank roles to admin: {$fixed} row(s)\n";
}

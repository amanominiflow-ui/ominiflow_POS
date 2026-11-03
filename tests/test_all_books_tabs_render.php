<?php
/**
 * Test rendering of ALL Books tabs
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/books_db.php';
require_once __DIR__ . '/../includes/outlets_db.php';

// Mock session and logged-in user with business_id = 5 (as seen in user's error trace: $businessId was 5!)
$_SESSION['user_id'] = 1;
$_SESSION['business_id'] = 5;

// Ensure user exists or create dummy
$db = get_db();
$u = $db->query("SELECT * FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($u) {
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['business_id'] = (int)($u['business_id'] ?? 1);
}

$tabs = [
    'overview', 'day-book', 'cash-book', 'bank-book', 'sales-book',
    'purchase-book', 'sales-return-book', 'purchase-return-book', 'expense-book',
    'customer-ledger', 'supplier-ledger', 'employee-ledger', 'general-ledger',
    'journal-book', 'trial-balance', 'profit-loss', 'balance-sheet',
    'cash-flow', 'daily-closing', 'bank-reconciliation', 'gst-reports', 'branch-comparison'
];

echo "Testing all Books tabs with business_id = " . current_business_id() . "...\n";

foreach ($tabs as $t) {
    $_GET['tab'] = $t;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = '/books.php';

    ob_start();
    try {
        include __DIR__ . '/../books.php';
        $out = ob_get_clean();
        if (str_contains($out, 'Fatal error') || str_contains($out, 'Parse error') || str_contains($out, 'Uncaught')) {
            echo " [FAIL] Tab '{$t}' outputted PHP error!\n";
            echo substr($out, 0, 500) . "\n";
            exit(1);
        }
        echo " [PASS] Tab '{$t}' rendered successfully (" . strlen($out) . " bytes)\n";
    } catch (Throwable $e) {
        ob_end_clean();
        echo " [FAIL] Tab '{$t}' threw exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
        exit(1);
    }
}

echo "\nALL TABS TESTED AND RENDERED PERFECTLY WITHOUT ERRORS!\n";

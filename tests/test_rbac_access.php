<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

$adminUser = ['id' => 1, 'role' => 'admin', 'outlet_id' => null];
$staffUser = ['id' => 2, 'role' => 'Staff', 'outlet_id' => 5];
$cashierUser = ['id' => 3, 'role' => 'cashier', 'outlet_id' => 5];
$managerUser = ['id' => 4, 'role' => 'Store Manager', 'outlet_id' => 5];
$accountantUser = ['id' => 5, 'role' => 'Accountant', 'outlet_id' => null];

$failures = 0;
function test_check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo " [PASS] {$msg}\n";
    } else {
        echo " [FAIL] {$msg}\n";
        $failures++;
    }
}

echo "=== RBAC VALIDATION TEST SUITE ===\n";
test_check(user_has_full_access($adminUser) === true, "Admin has full access");
test_check(user_can_access_settings($adminUser) === true, "Admin can access settings");

test_check(user_has_full_access($staffUser) === false, "Staff does not have full access");
test_check(user_can_access_settings($staffUser) === false, "Staff cannot access settings/users");
test_check(user_can_access_purchasing($staffUser) === false, "Staff cannot access purchases");
test_check(user_can_access_books($staffUser) === false, "Staff cannot access books of accounts");
test_check(user_can_access_reports($staffUser) === false, "Staff cannot access reports");
test_check(user_pos_is_outlet_locked($staffUser) === true, "Staff outlet is locked to assigned store");

test_check(user_can_access_settings($cashierUser) === false, "Cashier cannot access settings");
test_check(user_can_access_books($cashierUser) === false, "Cashier cannot access books");

test_check(user_can_access_purchasing($managerUser) === true, "Manager can access purchasing");
test_check(user_can_access_reports($managerUser) === true, "Manager can access reports");
test_check(user_can_access_settings($managerUser) === false, "Manager cannot access settings/users");

test_check(user_can_access_books($accountantUser) === true, "Accountant can access books");
test_check(user_can_access_reports($accountantUser) === true, "Accountant can access reports");
test_check(user_can_access_settings($accountantUser) === false, "Accountant cannot access settings/users");

echo "\nResult: " . ($failures === 0 ? "ALL RBAC TESTS PASSED SUCCESSFULLY!" : "FAILED!") . "\n";
exit($failures === 0 ? 0 : 1);

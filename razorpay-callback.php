<?php
/**
 * Razorpay OAuth callback (Zoho POS parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/razorpay_oauth.php';

require_auth();

$error = trim((string) ($_GET['error'] ?? ''));
$code = trim((string) ($_GET['code'] ?? ''));
$state = trim((string) ($_GET['state'] ?? ''));

if ($error !== '') {
    set_flash('error', 'Razorpay authorization was cancelled or denied.');
    redirect(asset('payment-integrations.php'));
}

$expected = (string) ($_SESSION['razorpay_oauth_state'] ?? '');
unset($_SESSION['razorpay_oauth_state']);

if ($code === '' || $state === '' || $expected === '' || !hash_equals($expected, $state)) {
    set_flash('error', 'Invalid Razorpay OAuth session. Please try Set Up Now again.');
    redirect(asset('payment-integrations.php'));
}

$ex = razorpay_exchange_authorization_code($code);
if (empty($ex['success'])) {
    set_flash('error', 'Could not connect Razorpay: ' . ($ex['error'] ?? 'token exchange failed'));
    redirect(asset('payment-integrations.php'));
}

$existing = get_payment_integration_by_code('razorpay');
$prevExtra = razorpay_integration_extra($existing);
$res = save_razorpay_oauth_connection($ex['data'], null, $prevExtra);
if (empty($res['success'])) {
    set_flash('error', 'Razorpay authorized but saving failed: ' . ($res['error'] ?? 'unknown error'));
    redirect(asset('payment-integrations.php'));
}

set_flash('success', 'Razorpay connected successfully.');
redirect(asset('payment-integrations.php') . '?razorpay_webhook=1');

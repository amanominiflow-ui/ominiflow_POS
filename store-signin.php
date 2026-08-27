<?php
/**
 * Customer store sign-in / create account with Mobile Phone OTP verification.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/storefront_db.php';

ensure_online_store_schema();

$slugParam = trim((string) ($_GET['slug'] ?? ''));
$storeBiz = resolve_store_business_from_request($slugParam !== '' ? $slugParam : null);
if (!$storeBiz) {
    http_response_code(404);
    echo 'Store not found.';
    exit;
}

$bid = (int) $storeBiz['id'];
$brand = get_mobile_store_settings($bid);
$storeName = (string) ($brand['display_name'] ?? $storeBiz['name'] ?? 'Store');
$headerColor = (string) ($brand['header_color'] ?? '#0f4c3a');
$homeUrl = public_store_url($storeBiz, 'home');

// Track redirect return target (e.g. checkout)
$returnKey = 'sf_auth_return_' . $bid;
if (!empty($_GET['return'])) {
    $_SESSION[$returnKey] = (string) $_GET['return'];
}
$returnTarget = (string) ($_SESSION[$returnKey] ?? 'home');
$returnParams = $returnTarget === 'checkout' ? ['return' => 'checkout'] : [];

$signinUrl = public_store_signin_url($storeBiz, $returnParams);
$signupUrl = public_store_signin_url($storeBiz, array_merge(['mode' => 'signup'], $returnParams));
$verifyOtpUrl = public_store_signin_url($storeBiz, array_merge(['mode' => 'verify_otp'], $returnParams));
$forgotUrl = public_store_signin_url($storeBiz, array_merge(['mode' => 'forgot'], $returnParams));

if (get_storefront_shopper($bid)) {
    $redirectUrl = $returnTarget === 'checkout'
        ? public_store_url($storeBiz, 'checkout')
        : public_store_url($storeBiz, 'home', ['account' => '1']);
    unset($_SESSION[$returnKey]);
    redirect($redirectUrl);
}

$mode = (string) ($_GET['mode'] ?? 'signin');
if (!in_array($mode, ['signin', 'signup', 'verify_otp', 'forgot'], true)) {
    $mode = 'signin';
}

$otpKey = 'sf_reg_otp_' . $bid;
$errors = [];
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'login') {
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            set_old_input(['identifier' => $identifier]);

            $res = login_storefront_shopper($bid, $identifier, $password);
            if (!empty($res['success'])) {
                clear_old_input();

                // Process pending cart item if present
                $pendingKey = 'sf_pending_cart_' . $bid;
                $pending = $_SESSION[$pendingKey] ?? null;
                if ($pending && !empty($pending['product_id'])) {
                    add_to_storefront_cart($bid, (int) $pending['product_id'], (int) ($pending['qty'] ?? 1));
                    unset($_SESSION[$pendingKey]);
                }

                $dest = $returnTarget === 'checkout'
                    ? public_store_url($storeBiz, 'checkout')
                    : public_store_url($storeBiz, 'home', ['account' => '1']);
                unset($_SESSION[$returnKey]);
                set_flash('success', 'Welcome back to ' . $storeName . '!');
                redirect($dest);
            }
            $errors['general'] = $res['error'] ?? 'Could not sign in.';
        }

        if ($action === 'register') {
            $name = storefront_clean_person_name((string) ($_POST['name'] ?? ''));
            $phone = clean_customer_phone((string) ($_POST['phone'] ?? ''));
            $rawPhone = trim((string) ($_POST['phone'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');

            set_old_input($_POST);

            if ($name === '') {
                $errors['general'] = 'Please enter your full name.';
            } elseif (strlen($phone) < 10) {
                $errors['general'] = 'Please enter a valid 10-digit mobile number.';
            } elseif (strlen($password) < 6) {
                $errors['general'] = 'Password must be at least 6 characters.';
            } else {
                $existing = find_store_customer_by_phone($bid, $phone);
                if ($existing && !empty($existing['password'])) {
                    $errors['general'] = 'An account with this mobile number already exists. Please sign in.';
                } else {
                    $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $_SESSION[$otpKey] = [
                        'otp' => $otp,
                        'expires' => time() + 600,
                        'phone' => $phone,
                        'raw_phone' => $rawPhone,
                        'name' => $name,
                        'data' => [
                            'name' => $name,
                            'phone' => $phone,
                            'email' => $email,
                            'password' => $password,
                        ],
                    ];
                    send_storefront_otp_sms($phone, $otp, $storeName);
                    if ($email !== '') {
                        send_storefront_otp_email($email, $name, $otp, $storeName);
                    }
                    set_flash('success', 'A 6-digit verification code has been sent to +91 ' . substr($phone, -10) . '.');
                    redirect($verifyOtpUrl);
                }
            }
        }

        if ($action === 'verify_otp') {
            $regData = $_SESSION[$otpKey] ?? null;
            if (!$regData || empty($regData['otp'])) {
                set_flash('error', 'Session expired. Please enter your registration details again.');
                redirect($signupUrl);
            } elseif (time() > ($regData['expires'] ?? 0)) {
                unset($_SESSION[$otpKey]);
                set_flash('error', 'Verification code expired. Please try registering again.');
                redirect($signupUrl);
            } else {
                $enteredOtp = trim((string) ($_POST['otp'] ?? ''));
                if ($enteredOtp === '' || $enteredOtp !== (string) $regData['otp']) {
                    $errors['general'] = 'Invalid 6-digit code. Please enter the correct code.';
                    $mode = 'verify_otp';
                } else {
                    $res = register_storefront_shopper($bid, $regData['data']);
                    if (!empty($res['success'])) {
                        unset($_SESSION[$otpKey]);
                        clear_old_input();

                        // Process pending cart item if present
                        $pendingKey = 'sf_pending_cart_' . $bid;
                        $pending = $_SESSION[$pendingKey] ?? null;
                        if ($pending && !empty($pending['product_id'])) {
                            add_to_storefront_cart($bid, (int) $pending['product_id'], (int) ($pending['qty'] ?? 1));
                            unset($_SESSION[$pendingKey]);
                        }

                        $dest = $returnTarget === 'checkout'
                            ? public_store_url($storeBiz, 'checkout')
                            : public_store_url($storeBiz, 'home', ['account' => '1']);
                        unset($_SESSION[$returnKey]);
                        set_flash('success', 'Account verified and created successfully! Welcome to ' . $storeName);
                        redirect($dest);
                    }
                    $errors['general'] = $res['error'] ?? 'Could not create account.';
                    $mode = 'verify_otp';
                }
            }
        }

        if ($action === 'resend_otp') {
            $regData = $_SESSION[$otpKey] ?? null;
            if ($regData && !empty($regData['phone'])) {
                $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $_SESSION[$otpKey]['otp'] = $otp;
                $_SESSION[$otpKey]['expires'] = time() + 600;
                send_storefront_otp_sms($regData['phone'], $otp, $storeName);
                if (!empty($regData['data']['email'])) {
                    send_storefront_otp_email($regData['data']['email'], (string)($regData['name'] ?? 'Customer'), $otp, $storeName);
                }
                set_flash('success', 'A new 6-digit code has been sent to +91 ' . substr($regData['phone'], -10) . '.');
                redirect($verifyOtpUrl);
            } else {
                set_flash('error', 'Session expired. Please fill in your registration details again.');
                redirect($signupUrl);
            }
        }

        if ($action === 'cancel_otp') {
            unset($_SESSION[$otpKey]);
            redirect($signupUrl);
        }

        if ($action === 'reset_password') {
            set_old_input($_POST);
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $cust = find_store_customer_by_identifier($bid, $identifier);
            if (!$cust) {
                $errors['general'] = 'No account found for this mobile number / email.';
            } else {
                $email = (string) ($cust['email'] ?? '');
                $res = reset_storefront_shopper_password(
                    $bid,
                    $email !== '' ? $email : 'dummy@customer.com',
                    (string) ($_POST['password'] ?? ''),
                    (string) ($_POST['password_confirmation'] ?? '')
                );
                if (!empty($res['success'])) {
                    $hash = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
                    get_db()->prepare('UPDATE customers SET password = :p WHERE id = :id AND business_id = :bid')
                        ->execute(['p' => $hash, 'id' => (int) $cust['id'], 'bid' => $bid]);
                    clear_old_input();
                    set_flash('success', 'Password updated successfully. Sign in with your new password.');
                    redirect($signinUrl);
                }
                $errors['general'] = $res['error'] ?? 'Could not reset password.';
            }
        }
    }
}

$pageHeading = 'Sign In';
$pageSub = 'to access your account & complete checkout';
if ($mode === 'signup') {
    $pageHeading = 'Create Account';
    $pageSub = 'sign up to shop at ' . $storeName;
}
if ($mode === 'verify_otp') {
    $regData = $_SESSION[$otpKey] ?? null;
    $phoneDisplay = !empty($regData['phone']) ? '+91 ' . substr($regData['phone'], -10) : 'your mobile number';
    $pageHeading = 'Verify Mobile Number';
    $pageSub = 'Enter the 6-digit code sent to ' . $phoneDisplay;
}
if ($mode === 'forgot') {
    $pageHeading = 'Forgot Password';
    $pageSub = 'set a new password for your account';
}

$favicon = get_storefront_dynamic_favicon_url($brand, $storeName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageHeading) ?> — <?= e($storeName) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e($favicon) ?>">
    <link rel="alternate icon" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="apple-touch-icon" href="<?= e($favicon) ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/storefront.css') ?>?v=22">
    <style>
        :root { --ms-header: <?= e($headerColor) ?>; }
        .sf-pw-wrap {
            position: relative;
            width: 100%;
            margin-bottom: 12px;
        }
        .sf-pw-wrap .sf-auth-input {
            margin-bottom: 0 !important;
            padding-right: 44px !important;
        }
        .sf-pw-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            padding: 6px;
            cursor: pointer;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            z-index: 2;
            transition: color 0.15s;
        }
        .sf-pw-toggle:hover {
            color: #0f172a;
        }
        .sf-pw-toggle svg {
            width: 20px;
            height: 20px;
            display: block;
        }
    </style>
</head>
<body class="sf-auth-body">
    <div class="sf-auth-card">
        <h1 class="sf-auth-title"><?= e($pageHeading) ?></h1>
        <p class="sf-auth-sub"><?= e($pageSub) ?></p>

        <?php if ($flashSuccess): ?><div class="ms-alert ms-ok" style="margin-bottom:14px"><?= e($flashSuccess) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="ms-alert ms-err" style="margin-bottom:14px"><?= e($flashError) ?></div><?php endif; ?>
        <?php if (!empty($errors['general'])): ?><div class="ms-alert ms-err" style="margin-bottom:14px"><?= e($errors['general']) ?></div><?php endif; ?>

        <?php if ($mode === 'signup'): ?>
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <input class="sf-auth-input" type="text" name="name" required placeholder="Full Name" value="<?= e(old('name')) ?>" autocomplete="name" autofocus>
                <input class="sf-auth-input" type="tel" name="phone" required placeholder="10-digit Mobile Number" value="<?= e(old('phone')) ?>" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" autocomplete="tel">
                <input class="sf-auth-input" type="email" name="email" placeholder="Email address (optional)" value="<?= e(old('email')) ?>" autocomplete="email">
                
                <div class="sf-pw-wrap">
                    <input class="sf-auth-input js-pw-input" type="password" name="password" required placeholder="Password (min 6 characters)" autocomplete="new-password">
                    <button type="button" class="sf-pw-toggle js-pw-toggle" aria-label="Toggle password visibility" title="Show password">
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                <button class="sf-auth-btn" type="submit">Create Account & Send OTP</button>
            </form>
            <p class="sf-auth-foot">Already have an account? <a href="<?= e($signinUrl) ?>">Sign In</a></p>

        <?php elseif ($mode === 'verify_otp'): ?>
            <?php $regData = $_SESSION[$otpKey] ?? null; ?>
            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_otp">
                <p class="sf-otp-info">We've sent a 6-digit verification code to <strong>+91 <?= e(substr($regData['phone'] ?? '', -10)) ?></strong>.</p>
                <?php if (!empty($regData['otp'])): ?>
                    <div style="background: #f0fdf4; border: 1px solid #86efac; color: #166534; border-radius: 8px; padding: 12px 16px; font-size: 13.5px; margin-bottom: 18px; text-align: center;">
                        <span style="display:block; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:#15803d; font-weight:600; margin-bottom:4px;">Verification Code</span>
                        <strong style="font-size: 24px; letter-spacing: 6px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: #14532d;"><?= e((string)$regData['otp']) ?></strong>
                    </div>
                <?php endif; ?>
                <input class="sf-auth-otp-input" type="text" name="otp" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="••••••" autofocus autocomplete="one-time-code">
                <button class="sf-auth-btn" type="submit">Verify & Proceed</button>
            </form>

            <div class="sf-otp-actions">
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend_otp">
                    <button type="submit" class="sf-resend-btn">Resend OTP</button>
                </form>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel_otp">
                    <button type="submit" class="sf-resend-btn" style="color:#64748b;">Change Number</button>
                </form>
            </div>

        <?php elseif ($mode === 'forgot'): ?>
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input class="sf-auth-input" type="text" name="identifier" required placeholder="Mobile number or email" value="<?= e(old('identifier')) ?>" autocomplete="username" autofocus>
                
                <div class="sf-pw-wrap">
                    <input class="sf-auth-input js-pw-input" type="password" name="password" required placeholder="New password" autocomplete="new-password">
                    <button type="button" class="sf-pw-toggle js-pw-toggle" aria-label="Toggle password visibility">
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                <div class="sf-pw-wrap">
                    <input class="sf-auth-input js-pw-input" type="password" name="password_confirmation" required placeholder="Confirm new password" autocomplete="new-password">
                    <button type="button" class="sf-pw-toggle js-pw-toggle" aria-label="Toggle password visibility">
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                <button class="sf-auth-btn" type="submit">Update Password</button>
            </form>
            <p class="sf-auth-foot"><a href="<?= e($signinUrl) ?>">Back to Sign In</a></p>

        <?php else: ?>
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <input class="sf-auth-input" type="text" name="identifier" required placeholder="Mobile number or email" value="<?= e(old('identifier')) ?>" autocomplete="username" autofocus>
                
                <div class="sf-pw-wrap">
                    <input class="sf-auth-input js-pw-input" type="password" name="password" required placeholder="Password" autocomplete="current-password">
                    <button type="button" class="sf-pw-toggle js-pw-toggle" aria-label="Toggle password visibility" title="Show password">
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                <button class="sf-auth-btn" type="submit">Sign In</button>
            </form>
            <a class="sf-auth-forgot" href="<?= e($forgotUrl) ?>">Forgot Password?</a>
            <p class="sf-auth-foot">Don't have an account? <a href="<?= e($signupUrl) ?>">Create Account</a></p>
        <?php endif; ?>
    </div>
    <a class="sf-auth-store" href="<?= e($homeUrl) ?>">← Back to <?= e($storeName) ?></a>

    <script>
    document.querySelectorAll('.js-pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrap = btn.closest('.sf-pw-wrap');
            if (!wrap) return;
            var input = wrap.querySelector('.js-pw-input');
            if (!input) return;
            var isPw = input.type === 'password';
            input.type = isPw ? 'text' : 'password';
            var openIcon = btn.querySelector('.eye-open');
            var closedIcon = btn.querySelector('.eye-closed');
            if (openIcon && closedIcon) {
                openIcon.style.display = isPw ? 'none' : 'block';
                closedIcon.style.display = isPw ? 'block' : 'none';
            }
            btn.title = isPw ? 'Hide password' : 'Show password';
        });
    });
    </script>
</body>
</html>

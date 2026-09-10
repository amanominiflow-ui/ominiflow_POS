<?php
/**
 * Customer store sign-in / create account with WhatsApp Mobile Number OTP verification.
 * Dual-support: Fast WhatsApp OTP Authentication (Primary) + Email/Password (Secondary).
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

// Track redirect return target (e.g. checkout or buynow)
$returnKey = 'sf_auth_return_' . $bid;
if (!empty($_GET['return'])) {
    $_SESSION[$returnKey] = (string) $_GET['return'];
}
$returnTarget = (string) ($_SESSION[$returnKey] ?? 'home');
$returnParams = in_array($returnTarget, ['checkout', 'buynow'], true) ? ['return' => $returnTarget] : [];

$signinUrl = public_store_signin_url($storeBiz, $returnParams);
$signupUrl = public_store_signin_url($storeBiz, array_merge(['mode' => 'signup'], $returnParams));
$forgotUrl = public_store_signin_url($storeBiz, array_merge(['mode' => 'forgot'], $returnParams));

$sfSigninDest = static function (array $storeBiz, string $returnTarget): string {
    if ($returnTarget === 'checkout') {
        return public_store_url($storeBiz, 'checkout');
    }
    if ($returnTarget === 'buynow') {
        return public_store_url($storeBiz, 'home', ['buynow' => '1']);
    }
    return public_store_url($storeBiz, 'home', ['account' => '1']);
};

if (get_storefront_shopper($bid)) {
    $redirectUrl = $sfSigninDest($storeBiz, $returnTarget);
    unset($_SESSION[$returnKey]);
    redirect($redirectUrl);
}

$mode = (string) ($_GET['mode'] ?? 'signup');
$allowedModes = ['signin', 'signup', 'verify_otp', 'email_signin', 'email_signup', 'forgot'];
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'signup';
}

$errors = [];
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Active OTP session data
$otpSession = $_SESSION['sf_wa_otp_data'] ?? null;
$activePhone = (string) ($_GET['phone'] ?? ($otpSession['phone'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        // 1. Send WhatsApp OTP
        if ($action === 'send_whatsapp_otp') {
            $name = storefront_clean_person_name((string) ($_POST['name'] ?? ''));
            $rawPhone = trim((string) ($_POST['phone'] ?? ''));
            $authMode = (string) ($_POST['auth_mode'] ?? 'signup');

            set_old_input($_POST);

            $waPhone = format_storefront_whatsapp_phone($rawPhone);
            $cleanDigits = clean_customer_phone($rawPhone);

            if ($authMode === 'signup' && $name === '') {
                $errors['general'] = 'Please enter your full name.';
            } elseif (strlen($cleanDigits) < 10) {
                $errors['general'] = 'Please enter a valid 10-digit WhatsApp mobile number.';
            } else {
                // Generate 6-digit OTP
                $otp = sprintf('%06d', mt_rand(100000, 999999));

                $_SESSION['sf_wa_otp_data'] = [
                    'phone' => $waPhone,
                    'raw_phone' => $rawPhone,
                    'name' => $name,
                    'otp' => $otp,
                    'expires_at' => time() + 600, // 10 minutes
                    'attempts' => 0,
                    'auth_mode' => $authMode,
                    'created_at' => time(),
                ];

                // Dispatch WhatsApp Message via OminiFlow / WPBox WhatsApp API
                $res = send_storefront_otp_whatsapp($waPhone, $otp, $storeName);

                // Also trigger SMS fallback if SMS gateways are defined
                send_storefront_otp_sms($rawPhone, $otp, $storeName);

                clear_old_input();
                set_flash('success', 'Verification code sent to WhatsApp (+' . $waPhone . ').');
                
                $verifyParams = array_merge(['mode' => 'verify_otp', 'phone' => $waPhone], $returnParams);
                redirect(public_store_signin_url($storeBiz, $verifyParams));
            }
        }

        // 2. Verify WhatsApp OTP & Login/Create Account
        if ($action === 'verify_whatsapp_otp') {
            $enteredOtp = trim((string) ($_POST['otp'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? $activePhone));
            $name = trim((string) ($_POST['name'] ?? ($otpSession['name'] ?? '')));

            $res = verify_storefront_whatsapp_otp($bid, $phone, $enteredOtp, $name);

            if (!empty($res['success'])) {
                // Process pending cart item if present
                $pendingKey = 'sf_pending_cart_' . $bid;
                $pending = $_SESSION[$pendingKey] ?? null;
                if ($pending && !empty($pending['product_id'])) {
                    add_to_storefront_cart($bid, (int) $pending['product_id'], (int) ($pending['qty'] ?? 1));
                    unset($_SESSION[$pendingKey]);
                }

                $savedLoc = get_storefront_delivery_location($bid);
                if (!empty($savedLoc['formatted'])) {
                    persist_storefront_shopper_address($bid, $savedLoc);
                } else {
                    restore_storefront_delivery_location($bid);
                }

                $dest = $sfSigninDest($storeBiz, $returnTarget);
                unset($_SESSION[$returnKey]);
                set_flash('success', !empty($res['is_new']) ? 'Account created successfully! Welcome to ' . $storeName : 'Welcome back to ' . $storeName . '!');
                redirect($dest);
            }

            $errors['general'] = $res['error'] ?? 'Could not verify OTP.';
            $mode = 'verify_otp';
        }

        // 3. Resend WhatsApp OTP
        if ($action === 'resend_whatsapp_otp') {
            if (!$otpSession || empty($otpSession['phone'])) {
                $errors['general'] = 'Session expired. Please enter your mobile number again.';
                $mode = 'signup';
            } else {
                $newOtp = sprintf('%06d', mt_rand(100000, 999999));
                $_SESSION['sf_wa_otp_data']['otp'] = $newOtp;
                $_SESSION['sf_wa_otp_data']['expires_at'] = time() + 600;
                $_SESSION['sf_wa_otp_data']['attempts'] = 0;
                $_SESSION['sf_wa_otp_data']['created_at'] = time();

                send_storefront_otp_whatsapp((string) $otpSession['phone'], $newOtp, $storeName);
                send_storefront_otp_sms((string) ($otpSession['raw_phone'] ?? $otpSession['phone']), $newOtp, $storeName);

                set_flash('success', 'A fresh OTP code was sent to your WhatsApp.');
                $verifyParams = array_merge(['mode' => 'verify_otp', 'phone' => (string) $otpSession['phone']], $returnParams);
                redirect(public_store_signin_url($storeBiz, $verifyParams));
            }
        }

        // 4. Email/Password Login
        if ($action === 'login') {
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            set_old_input(['identifier' => $identifier]);

            $res = login_storefront_shopper($bid, $identifier, $password);
            if (!empty($res['success'])) {
                clear_old_input();

                $pendingKey = 'sf_pending_cart_' . $bid;
                $pending = $_SESSION[$pendingKey] ?? null;
                if ($pending && !empty($pending['product_id'])) {
                    add_to_storefront_cart($bid, (int) $pending['product_id'], (int) ($pending['qty'] ?? 1));
                    unset($_SESSION[$pendingKey]);
                }

                $savedLoc = get_storefront_delivery_location($bid);
                if (!empty($savedLoc['formatted'])) {
                    persist_storefront_shopper_address($bid, $savedLoc);
                } else {
                    restore_storefront_delivery_location($bid);
                }

                $dest = $sfSigninDest($storeBiz, $returnTarget);
                unset($_SESSION[$returnKey]);
                set_flash('success', 'Welcome back to ' . $storeName . '!');
                redirect($dest);
            }
            $errors['general'] = $res['error'] ?? 'Could not sign in.';
        }

        // 5. Email/Password Register
        if ($action === 'register') {
            $name = storefront_clean_person_name((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');

            set_old_input($_POST);

            if ($name === '') {
                $errors['general'] = 'Please enter your full name.';
            } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['general'] = 'Please enter a valid email address.';
            } elseif (strlen($password) < 6) {
                $errors['general'] = 'Password must be at least 6 characters.';
            } else {
                $res = register_storefront_shopper($bid, [
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]);
                if (!empty($res['success'])) {
                    clear_old_input();

                    $pendingKey = 'sf_pending_cart_' . $bid;
                    $pending = $_SESSION[$pendingKey] ?? null;
                    if ($pending && !empty($pending['product_id'])) {
                        add_to_storefront_cart($bid, (int) $pending['product_id'], (int) ($pending['qty'] ?? 1));
                        unset($_SESSION[$pendingKey]);
                    }

                    $savedLoc = get_storefront_delivery_location($bid);
                    if (!empty($savedLoc['formatted'])) {
                        persist_storefront_shopper_address($bid, $savedLoc);
                    } else {
                        restore_storefront_delivery_location($bid);
                    }

                    $dest = $sfSigninDest($storeBiz, $returnTarget);
                    unset($_SESSION[$returnKey]);
                    set_flash('success', 'Account created successfully! Welcome to ' . $storeName);
                    redirect($dest);
                }
                $errors['general'] = $res['error'] ?? 'Could not create account.';
            }
        }

        // 6. Reset Password
        if ($action === 'reset_password') {
            set_old_input($_POST);
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $cust = find_store_customer_by_identifier($bid, $identifier);
            if (!$cust) {
                $errors['general'] = 'No account found for this email address.';
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

// Page Titles & Headings
$pageHeading = 'Sign In';
$pageSub = 'to access your account & complete checkout';

if ($mode === 'signup') {
    $pageHeading = 'Create Account';
    $pageSub = 'sign up with WhatsApp to shop at ' . $storeName;
} elseif ($mode === 'verify_otp') {
    $pageHeading = 'Verify OTP';
    $pageSub = 'enter the 6-digit code sent to your WhatsApp';
} elseif ($mode === 'email_signup') {
    $pageHeading = 'Create Account';
    $pageSub = 'sign up with email at ' . $storeName;
} elseif ($mode === 'email_signin') {
    $pageHeading = 'Email Sign In';
    $pageSub = 'sign in with your email & password';
} elseif ($mode === 'forgot') {
    $pageHeading = 'Forgot Password';
    $pageSub = 'set a new password for your account';
}

$favicon = get_storefront_dynamic_favicon_url($brand, $storeName);
$dispPhone = $activePhone !== '' ? ('+' . $activePhone) : '';
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
    <link rel="stylesheet" href="<?= asset('assets/css/storefront.css') ?>?v=24">
    <style>
        :root { --ms-header: <?= e($headerColor) ?>; }
        
        .sf-auth-card {
            max-width: 440px;
        }

        .sf-phone-input-wrap {
            display: flex;
            align-items: center;
            background: #dbeafe;
            border-radius: 6px;
            margin-bottom: 12px;
            overflow: hidden;
            border: 2px solid transparent;
            transition: border-color 0.15s ease, background 0.15s ease;
        }
        .sf-phone-input-wrap:focus-within {
            border-color: #2563eb;
            background: #ffffff;
        }
        .sf-phone-prefix {
            padding: 12px 12px 12px 14px;
            font-size: 14.5px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(37, 99, 235, 0.08);
            border-right: 1px solid #bfdbfe;
            user-select: none;
        }
        .sf-phone-input {
            flex: 1;
            border: 0;
            background: transparent;
            padding: 14px 14px;
            font: inherit;
            font-size: 15.5px;
            font-weight: 600;
            color: #0f172a;
            outline: none;
            width: 100%;
        }

        .sf-wa-btn {
            background: #25d366 !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.28);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.15s ease, transform 0.1s ease;
        }
        .sf-wa-btn:hover {
            background: #20bd5a !important;
        }
        .sf-wa-btn:active {
            transform: scale(0.99);
        }

        .sf-wa-icon {
            width: 20px;
            height: 20px;
            fill: currentColor;
            flex-shrink: 0;
        }

        .sf-otp-box-wrap {
            margin: 18px 0;
            text-align: center;
        }
        .sf-otp-box-wrap .sf-auth-otp-input {
            letter-spacing: 12px;
            font-size: 30px;
            font-weight: 800;
            text-align: center;
            padding: 14px;
            background: #ffffff;
            border: 2px solid #94a3b8;
            border-radius: 10px;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .sf-otp-box-wrap .sf-auth-otp-input:focus {
            border-color: #25d366;
            box-shadow: 0 0 0 4px rgba(37, 211, 102, 0.2);
        }

        .sf-phone-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            margin: 4px 0 16px;
        }
        .sf-phone-edit {
            color: #2563eb;
            text-decoration: underline;
            font-size: 12px;
            margin-left: 4px;
            cursor: pointer;
        }

        .sf-resend-timer {
            font-size: 13px;
            color: #64748b;
            margin-top: 14px;
            text-align: center;
        }
        .sf-resend-link {
            color: #2563eb;
            font-weight: 700;
            text-decoration: underline;
            cursor: pointer;
            background: none;
            border: 0;
            padding: 0;
            font-size: 13.5px;
        }

        .sf-alt-divider {
            display: flex;
            align-items: center;
            margin: 20px 0 16px;
            color: #94a3b8;
            font-size: 12.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sf-alt-divider::before, .sf-alt-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        .sf-alt-divider span {
            padding: 0 12px;
        }

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
        .sf-pw-toggle:hover { color: #0f172a; }
        .sf-pw-toggle svg { width: 20px; height: 20px; display: block; }
    </style>
</head>
<body class="sf-auth-body">
    <div class="sf-auth-card">
        <h1 class="sf-auth-title"><?= e($pageHeading) ?></h1>
        <p class="sf-auth-sub"><?= e($pageSub) ?></p>

        <?php if ($flashSuccess): ?><div class="ms-alert ms-ok" style="margin-bottom:14px"><?= e($flashSuccess) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="ms-alert ms-err" style="margin-bottom:14px"><?= e($flashError) ?></div><?php endif; ?>
        <?php if (!empty($errors['general'])): ?><div class="ms-alert ms-err" style="margin-bottom:14px"><?= e($errors['general']) ?></div><?php endif; ?>

        <?php if ($mode === 'verify_otp'): ?>
            <!-- =========================================================
                 OTP VERIFICATION SCREEN
                 ========================================================= -->
            <div style="text-align: center;">
                <div class="sf-phone-badge">
                    <svg style="width:16px;height:16px;fill:#25d366" viewBox="0 0 24 24"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2M12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15C10.56 20.15 9.11 19.76 7.85 19L7.55 18.83L4.43 19.65L5.26 16.61L5.06 16.29C4.24 15 3.8 13.47 3.8 11.91C3.81 7.37 7.5 3.67 12.05 3.67Z"/></svg>
                    <span><?= e($dispPhone !== '' ? $dispPhone : 'WhatsApp Number') ?></span>
                    <a href="<?= e($signupUrl) ?>" class="sf-phone-edit">Edit</a>
                </div>
            </div>

            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_whatsapp_otp">
                <input type="hidden" name="phone" value="<?= e($activePhone) ?>">

                <div class="sf-otp-box-wrap">
                    <input class="sf-auth-otp-input" type="text" inputmode="numeric" name="otp" required maxlength="6" pattern="[0-9]{6}" placeholder="------" autocomplete="one-time-code" autofocus>
                    <p style="font-size:12.5px;color:#64748b;margin:0;">Enter the 6-digit code received on WhatsApp</p>
                </div>

                <button class="sf-auth-btn sf-wa-btn" type="submit">
                    Verify & Continue
                </button>
            </form>

            <div class="sf-resend-timer" id="resendWrap">
                <span id="timerText">Resend OTP in <strong id="secondsLeft">60</strong>s</span>
                <form method="post" id="resendForm" style="display:none;margin-top:8px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend_whatsapp_otp">
                    <button type="submit" class="sf-resend-link">Resend Code via WhatsApp</button>
                </form>
            </div>

        <?php elseif ($mode === 'signup'): ?>
            <!-- =========================================================
                 CREATE ACCOUNT WITH WHATSAPP (PRIMARY FLOW)
                 ========================================================= -->
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_whatsapp_otp">
                <input type="hidden" name="auth_mode" value="signup">

                <input class="sf-auth-input" type="text" name="name" required placeholder="Full Name" value="<?= e(old('name')) ?>" autocomplete="name" autofocus>
                
                <div class="sf-phone-input-wrap">
                    <div class="sf-phone-prefix">
                        <span>🇮🇳</span>
                        <span>+91</span>
                    </div>
                    <input class="sf-phone-input" type="tel" name="phone" required placeholder="WhatsApp Mobile Number" value="<?= e(old('phone')) ?>" autocomplete="tel" maxlength="15">
                </div>

                <button class="sf-auth-btn sf-wa-btn" type="submit">
                    <svg class="sf-wa-icon" viewBox="0 0 24 24"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2M12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15C10.56 20.15 9.11 19.76 7.85 19L7.55 18.83L4.43 19.65L5.26 16.61L5.06 16.29C4.24 15 3.8 13.47 3.8 11.91C3.81 7.37 7.5 3.67 12.05 3.67Z"/></svg>
                    Send WhatsApp OTP
                </button>
            </form>

            <div class="sf-alt-divider"><span>OR</span></div>

            <p class="sf-auth-foot" style="margin-top:0;">
                <a href="<?= e(public_store_signin_url($storeBiz, array_merge(['mode' => 'email_signup'], $returnParams))) ?>">Sign up with Email & Password</a>
            </p>

            <p class="sf-auth-foot">Already have an account? <a href="<?= e($signinUrl) ?>">Sign In</a></p>

        <?php elseif ($mode === 'signin'): ?>
            <!-- =========================================================
                 SIGN IN WITH WHATSAPP (PRIMARY FLOW)
                 ========================================================= -->
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_whatsapp_otp">
                <input type="hidden" name="auth_mode" value="signin">

                <div class="sf-phone-input-wrap">
                    <div class="sf-phone-prefix">
                        <span>🇮🇳</span>
                        <span>+91</span>
                    </div>
                    <input class="sf-phone-input" type="tel" name="phone" required placeholder="WhatsApp Mobile Number" value="<?= e(old('phone')) ?>" autocomplete="tel" maxlength="15" autofocus>
                </div>

                <button class="sf-auth-btn sf-wa-btn" type="submit">
                    <svg class="sf-wa-icon" viewBox="0 0 24 24"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2M12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15C10.56 20.15 9.11 19.76 7.85 19L7.55 18.83L4.43 19.65L5.26 16.61L5.06 16.29C4.24 15 3.8 13.47 3.8 11.91C3.81 7.37 7.5 3.67 12.05 3.67Z"/></svg>
                    Send Login OTP
                </button>
            </form>

            <div class="sf-alt-divider"><span>OR</span></div>

            <p class="sf-auth-foot" style="margin-top:0;">
                <a href="<?= e(public_store_signin_url($storeBiz, array_merge(['mode' => 'email_signin'], $returnParams))) ?>">Sign in with Email & Password</a>
            </p>

            <p class="sf-auth-foot">Don't have an account? <a href="<?= e($signupUrl) ?>">Create Account</a></p>

        <?php elseif ($mode === 'email_signup'): ?>
            <!-- =========================================================
                 EMAIL SIGNUP (FALLBACK / ALTERNATIVE)
                 ========================================================= -->
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <input class="sf-auth-input" type="text" name="name" required placeholder="Full Name" value="<?= e(old('name')) ?>" autocomplete="name" autofocus>
                <input class="sf-auth-input" type="email" name="email" required placeholder="Email Address" value="<?= e(old('email')) ?>" autocomplete="email">
                
                <div class="sf-pw-wrap">
                    <input class="sf-auth-input js-pw-input" type="password" name="password" required placeholder="Password (min 6 characters)" autocomplete="new-password">
                    <button type="button" class="sf-pw-toggle js-pw-toggle" aria-label="Toggle password visibility" title="Show password">
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                <button class="sf-auth-btn" type="submit">Create Account</button>
            </form>
            
            <p class="sf-auth-foot"><a href="<?= e($signupUrl) ?>">← Back to WhatsApp Sign Up</a></p>
            <p class="sf-auth-foot">Already have an account? <a href="<?= e($signinUrl) ?>">Sign In</a></p>

        <?php elseif ($mode === 'email_signin'): ?>
            <!-- =========================================================
                 EMAIL SIGN IN (FALLBACK / ALTERNATIVE)
                 ========================================================= -->
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <input class="sf-auth-input" type="email" name="identifier" required placeholder="Email Address" value="<?= e(old('identifier')) ?>" autocomplete="email" autofocus>
                
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
            <p class="sf-auth-foot"><a href="<?= e($signinUrl) ?>">← Back to WhatsApp Sign In</a></p>
            <p class="sf-auth-foot">Don't have an account? <a href="<?= e($signupUrl) ?>">Create Account</a></p>

        <?php elseif ($mode === 'forgot'): ?>
            <!-- =========================================================
                 FORGOT PASSWORD
                 ========================================================= -->
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input class="sf-auth-input" type="email" name="identifier" required placeholder="Email Address" value="<?= e(old('identifier')) ?>" autocomplete="email" autofocus>
                
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
        <?php endif; ?>
    </div>
    <a class="sf-auth-store" href="<?= e($homeUrl) ?>">← Back to <?= e($storeName) ?></a>

    <script>
    // Password visibility toggle
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

    // 60-second countdown timer on OTP verification screen
    (function () {
        var timerText = document.getElementById('timerText');
        var resendForm = document.getElementById('resendForm');
        var secondsEl = document.getElementById('secondsLeft');
        if (!secondsEl || !timerText || !resendForm) return;

        var timeLeft = 60;
        var interval = setInterval(function () {
            timeLeft--;
            if (timeLeft <= 0) {
                clearInterval(interval);
                timerText.style.display = 'none';
                resendForm.style.display = 'block';
            } else {
                secondsEl.textContent = timeLeft;
            }
        }, 1000);
    })();
    </script>
</body>
</html>

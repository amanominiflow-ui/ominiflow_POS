<?php
/**
 * Customer store sign-in / create account with Email OTP verification (not POS admin login).
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
$signinUrl = public_store_signin_url($storeBiz);
$signupUrl = public_store_signin_url($storeBiz, ['mode' => 'signup']);
$verifyOtpUrl = public_store_signin_url($storeBiz, ['mode' => 'verify_otp']);
$forgotUrl = public_store_signin_url($storeBiz, ['mode' => 'forgot']);

if (get_storefront_shopper($bid)) {
    redirect(public_store_url($storeBiz, 'home', ['account' => '1']));
}

$mode = (string) ($_GET['mode'] ?? 'signin');
if (!in_array($mode, ['signin', 'signup', 'verify_otp', 'forgot'], true)) {
    $mode = 'signin';
}

$emailKey = 'sf_auth_email_' . $bid;
$otpKey = 'sf_reg_otp_' . $bid;
$errors = [];
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$stepEmail = strtolower(trim((string) ($_SESSION[$emailKey] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'next_email') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            set_old_input(['email' => $email]);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            } else {
                $_SESSION[$emailKey] = $email;
                redirect($signinUrl);
            }
        }

        if ($action === 'back_email') {
            unset($_SESSION[$emailKey]);
            redirect($signinUrl);
        }

        if ($action === 'login') {
            $email = strtolower(trim((string) ($_POST['email'] ?? $stepEmail)));
            $password = (string) ($_POST['password'] ?? '');
            $res = login_storefront_shopper($bid, $email, $password);
            if (!empty($res['success'])) {
                unset($_SESSION[$emailKey]);
                clear_old_input();
                redirect(public_store_url($storeBiz, 'home', ['account' => '1']));
            }
            $errors['general'] = $res['error'] ?? 'Could not sign in.';
            $_SESSION[$emailKey] = $email;
        }

        if ($action === 'register') {
            $name = storefront_clean_person_name((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            set_old_input($_POST);

            if ($name === '') {
                $errors['general'] = 'Full name is required.';
            } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['general'] = 'Enter a valid email address.';
            } elseif (strlen($password) < 6) {
                $errors['general'] = 'Password must be at least 6 characters.';
            } else {
                $existing = find_store_customer_by_email($bid, $email);
                if ($existing && !empty($existing['password'])) {
                    $errors['general'] = 'An account already exists for this email. Please sign in.';
                } else {
                    $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $_SESSION[$otpKey] = [
                        'otp' => $otp,
                        'expires' => time() + 600,
                        'email' => $email,
                        'name' => $name,
                        'data' => [
                            'name' => $name,
                            'email' => $email,
                            'phone' => $phone,
                            'password' => $password,
                        ],
                    ];
                    send_storefront_otp_email($email, $name, $otp, $storeName);
                    set_flash('success', 'A 6-digit verification code has been sent to ' . $email . '.');
                    redirect($verifyOtpUrl);
                }
            }
        }

        if ($action === 'verify_otp') {
            $regData = $_SESSION[$otpKey] ?? null;
            if (!$regData || empty($regData['otp'])) {
                set_flash('error', 'Session expired. Please enter your details again.');
                redirect($signupUrl);
            } elseif (time() > ($regData['expires'] ?? 0)) {
                unset($_SESSION[$otpKey]);
                set_flash('error', 'OTP has expired. Please register again.');
                redirect($signupUrl);
            } else {
                $enteredOtp = trim((string) ($_POST['otp'] ?? ''));
                if ($enteredOtp === '' || $enteredOtp !== (string) $regData['otp']) {
                    $errors['general'] = 'Invalid verification code. Please check your email and try again.';
                    $mode = 'verify_otp';
                } else {
                    $res = register_storefront_shopper($bid, $regData['data']);
                    if (!empty($res['success'])) {
                        unset($_SESSION[$otpKey]);
                        unset($_SESSION[$emailKey]);
                        clear_old_input();
                        set_flash('success', 'Email verified and account created successfully! Welcome to ' . $storeName);
                        redirect(public_store_url($storeBiz, 'home', ['account' => '1']));
                    }
                    $errors['general'] = $res['error'] ?? 'Could not create account.';
                    $mode = 'verify_otp';
                }
            }
        }

        if ($action === 'resend_otp') {
            $regData = $_SESSION[$otpKey] ?? null;
            if ($regData && !empty($regData['email'])) {
                $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $_SESSION[$otpKey]['otp'] = $otp;
                $_SESSION[$otpKey]['expires'] = time() + 600;
                send_storefront_otp_email($regData['email'], (string)($regData['name'] ?? 'Customer'), $otp, $storeName);
                set_flash('success', 'A new verification code has been sent to ' . $regData['email'] . '.');
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
            $res = reset_storefront_shopper_password(
                $bid,
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirmation'] ?? '')
            );
            if (!empty($res['success'])) {
                clear_old_input();
                set_flash('success', 'Password updated. Sign in with your new password.');
                unset($_SESSION[$emailKey]);
                redirect($signinUrl);
            }
            $errors['general'] = $res['error'] ?? 'Could not reset password.';
        }
    }
}

$showPasswordStep = $mode === 'signin' && $stepEmail !== '';
$pageHeading = 'Sign in';
$pageSub = 'to access your account';
if ($mode === 'signup') {
    $pageHeading = 'Create Account';
    $pageSub = 'to shop at ' . $storeName;
}
if ($mode === 'verify_otp') {
    $regData = $_SESSION[$otpKey] ?? null;
    $regEmail = (string) ($regData['email'] ?? '');
    $pageHeading = 'Verify Email';
    $pageSub = $regEmail ? 'Enter the 6-digit code sent to ' . $regEmail : 'Enter the 6-digit verification code';
}
if ($mode === 'forgot') {
    $pageHeading = 'Forgot Password';
    $pageSub = 'set a new password for your account';
}

$favicon = '';
if (!empty($brand['favicon_path'])) {
    $favicon = asset((string) $brand['favicon_path']);
} elseif (!empty($brand['logo_path']) && !is_platform_logo((string) $brand['logo_path'])) {
    $favicon = asset((string) $brand['logo_path']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageHeading) ?> — <?= e($storeName) ?></title>
    <?php if ($favicon): ?>
        <link rel="icon" href="<?= e($favicon) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= asset('assets/css/storefront.css') ?>?v=10">
    <style>
        :root { --ms-header: <?= e($headerColor) ?>; }
    </style>
</head>
<body class="sf-auth-body">
    <div class="sf-auth-card">
        <h1 class="sf-auth-title"><?= e($pageHeading) ?></h1>
        <p class="sf-auth-sub"><?= e($pageSub) ?></p>

        <?php if ($flashSuccess): ?><div class="ms-alert ms-ok" style="margin-bottom:14px"><?= e($flashSuccess) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="ms-alert ms-err" style="margin-bottom:14px"><?= e($flashError) ?></div><?php endif; ?>
        <?php if (!empty($errors['general'])): ?><div class="ms-alert ms-err" style="margin-bottom:14px"><?= e($errors['general']) ?></div><?php endif; ?>
        <?php if (!empty($errors['email'])): ?><div class="ms-alert ms-err" style="margin-bottom:14px"><?= e($errors['email']) ?></div><?php endif; ?>

        <?php if ($mode === 'signup'): ?>
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <input class="sf-auth-input" type="text" name="name" required placeholder="Full name" value="<?= e(old('name')) ?>" autocomplete="name">
                <input class="sf-auth-input" type="email" name="email" required placeholder="Email address" value="<?= e(old('email', $stepEmail)) ?>" autocomplete="email">
                <input class="sf-auth-input" type="tel" name="phone" placeholder="Phone (optional)" value="<?= e(old('phone')) ?>" autocomplete="tel">
                <input class="sf-auth-input" type="password" name="password" required placeholder="Password (min 6 characters)" autocomplete="new-password">
                <button class="sf-auth-btn" type="submit">Create Account</button>
            </form>
            <p class="sf-auth-foot">Already have an account? <a href="<?= e($signinUrl) ?>">Sign in</a></p>

        <?php elseif ($mode === 'verify_otp'): ?>
            <?php $regData = $_SESSION[$otpKey] ?? null; ?>
            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_otp">
                <p class="sf-otp-info">We've sent a 6-digit verification code to <strong><?= e($regData['email'] ?? 'your email') ?></strong>.</p>
                <input class="sf-auth-otp-input" type="text" name="otp" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="••••••" autofocus autocomplete="one-time-code">
                <button class="sf-auth-btn" type="submit">Verify & Activate Account</button>
            </form>

            <div class="sf-otp-actions">
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend_otp">
                    <button type="submit" class="sf-resend-btn">Resend Code</button>
                </form>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel_otp">
                    <button type="submit" class="sf-resend-btn" style="color:#64748b;">Change details</button>
                </form>
            </div>

        <?php elseif ($mode === 'forgot'): ?>
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input class="sf-auth-input" type="email" name="email" required placeholder="Email address" value="<?= e(old('email', $stepEmail)) ?>" autocomplete="email">
                <input class="sf-auth-input" type="password" name="password" required placeholder="New password" autocomplete="new-password">
                <input class="sf-auth-input" type="password" name="password_confirmation" required placeholder="Confirm new password" autocomplete="new-password">
                <button class="sf-auth-btn" type="submit">Update Password</button>
            </form>
            <p class="sf-auth-foot"><a href="<?= e($signinUrl) ?>">Back to Sign in</a></p>

        <?php elseif ($showPasswordStep): ?>
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="email" value="<?= e($stepEmail) ?>">
                <input class="sf-auth-input" type="email" value="<?= e($stepEmail) ?>" disabled>
                <input class="sf-auth-input" type="password" name="password" required placeholder="Password" autofocus autocomplete="current-password">
                <button class="sf-auth-btn" type="submit">Sign in</button>
            </form>
            <form method="post" style="margin-top:10px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="back_email">
                <button type="submit" class="sf-auth-back">Use a different email</button>
            </form>
            <a class="sf-auth-forgot" href="<?= e($forgotUrl) ?>">Forgot Password?</a>
            <p class="sf-auth-foot">Don't have an account? <a href="<?= e($signupUrl) ?>">Create Account</a></p>

        <?php else: ?>
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="next_email">
                <input class="sf-auth-input" type="email" name="email" required placeholder="Email address" value="<?= e(old('email')) ?>" autofocus autocomplete="email">
                <button class="sf-auth-btn" type="submit">Next</button>
            </form>
            <a class="sf-auth-forgot" href="<?= e($forgotUrl) ?>">Forgot Password?</a>
            <p class="sf-auth-foot">Don't have an account? <a href="<?= e($signupUrl) ?>">Create Account</a></p>
        <?php endif; ?>
    </div>
    <a class="sf-auth-store" href="<?= e($homeUrl) ?>">← Back to <?= e($storeName) ?></a>
</body>
</html>

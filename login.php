<?php
/**
 * OminiFlow POS - User Login
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';

require_guest();

$errors = [];
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Invalid or expired session token. Please try again.';
    } else {
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);

        set_old_input($_POST);

        $result = login_user($email, $password, $remember);

        if ($result['success']) {
            clear_old_input();
            redirect(APP_URL . '/dashboard.php');
        } else {
            $errors = $result['errors'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Cloud POS Billing & Retail Store Management Software — OminiFlow POS</title>
    <meta name="description" content="All-in-one cloud POS billing software for retail stores. Superfast barcode billing, multi-counter checkout, real-time inventory tracking, and GST invoicing at SaaS scale.">
    <meta name="keywords" content="POS billing software, retail point of sale, cloud POS system, barcode billing software, multi-counter retail POS, inventory management POS">
    
    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/auth.css') ?>">
</head>
<body>
    <div class="of-auth-shell">
        <div class="of-auth-grid">
            <!-- Left Brand Column -->
            <section class="of-auth-brand" aria-label="Product Showcase">
                <div>
                    <div class="of-brand-top">
                        <a href="<?= asset('login.php') ?>" class="of-brand-logo">
                            <img src="<?= asset('assets/images/logo.jpg') ?>" alt="OminiFlow">
                        </a>
                        <div class="of-meta-partner-logo">
                            <img src="https://www.ominiflow.com/assets/images/common/Meta_Business_Partners_inline_lockup_negative_primary_RGB.png" alt="Meta Business Partner">
                        </div>
                    </div>

                    <h1 class="of-title">Smart Cloud POS Billing & Retail Store Management at SaaS Scale.</h1>
                    <p class="of-subtitle">Supercharge counter billing, rapid barcode checkout, real-time inventory tracking, and GST receipt printing — built for modern retail businesses.</p>

                    <div class="of-kpi-grid">
                        <div class="of-kpi">
                            <div class="of-kpi-ico" aria-hidden="true">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V5zm2 0v12h12V5H6zm2 2h4m-4 3h8m-8 3h4" />
                                </svg>
                            </div>
                            <h4>5,000+</h4>
                            <p>Active Businesses</p>
                        </div>
                        <div class="of-kpi">
                            <div class="of-kpi-ico" aria-hidden="true">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <h4>99.95%</h4>
                            <p>Platform Uptime</p>
                        </div>
                        <div class="of-kpi">
                            <div class="of-kpi-ico" aria-hidden="true">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 6v6h4.5m4.5-4.5a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <h4>24/7</h4>
                            <p>Priority Support</p>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="of-feature-grid">
                        <div class="of-feature">
                            <div class="of-feature-ico" aria-hidden="true">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10.34 3.65L13.4 1.1a.75.75 0 011.2.5v20.8a.75.75 0 01-1.2.5L10.34 20.35A9.97 9.97 0 006 19H3.75A2.25 2.25 0 012 16.75v-3.5A2.25 2.25 0 013.75 10H6a9.97 9.97 0 003.34-.65z" />
                                </svg>
                            </div>
                            <div>
                                <strong>Instant Barcode Checkout</strong>
                                <span>Fast cashier register with thermal printing and barcode scanning support.</span>
                            </div>
                        </div>
                        <div class="of-feature">
                            <div class="of-feature-ico" aria-hidden="true">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.25-2.25h.008v.008H13.5v-.008zM2.25 4.5h19.5a.75.75 0 01.75.75V9a.75.75 0 01-.75.75H2.25A.75.75 0 011.5 9V5.25a.75.75 0 01.75-.75z" />
                                </svg>
                            </div>
                            <div>
                                <strong>Multi-Store Sync</strong>
                                <span>Coordinate inventory, prices, and staff across physical stores and digital channels.</span>
                            </div>
                        </div>
                    </div>

                    <div class="of-quote">
                        <p>"OminiFlow POS cut down our customer billing wait times by more than 50% from day one."</p>
                        <small>Vishal Kumer, Retail Operations</small>
                    </div>
                </div>
            </section>

            <!-- Right Form Column -->
            <section class="of-auth-form-wrap">
                <div class="of-auth-card">
                    <div class="of-mobile-logo">
                        <img src="<?= asset('assets/images/logo.jpg') ?>" alt="OminiFlow">
                    </div>

                    <div class="of-form-head">
                        <div class="of-form-badge" aria-hidden="true">
                            <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 9h10.5a.75.75 0 00.75-.75V10.5a.75.75 0 00-.75-.75H6a.75.75 0 00-.75.75v7.5c0 .414.336.75.75.75z" />
                            </svg>
                        </div>
                        <div>
                            <p class="of-auth-overline">Welcome back</p>
                            <h2>Sign In</h2>
                            <p class="of-auth-desc">Access your POS terminal to continue.</p>
                        </div>
                    </div>

                    <?php if ($flashSuccess): ?>
                        <div class="of-alert">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span><?= e($flashSuccess) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($flashError): ?>
                        <div class="of-alert of-alert-danger">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span><?= e($flashError) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($errors['general'])): ?>
                        <div class="of-alert of-alert-danger">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span><?= e($errors['general']) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= asset('login.php') ?>" novalidate>
                        <?= csrf_field() ?>

                        <div class="of-form-group">
                            <label for="email">Email Address</label>
                            <div class="of-input-wrap">
                                <span class="of-input-icon">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.91l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.91V6.75" />
                                    </svg>
                                </span>
                                <input
                                    id="email"
                                    name="email"
                                    type="email"
                                    value="<?= e(old('email')) ?>"
                                    required
                                    autofocus
                                    autocomplete="email"
                                    placeholder="you@example.com"
                                    class="of-input <?= !empty($errors['email']) ? 'is-invalid' : '' ?>"
                                >
                            </div>
                            <?php if (!empty($errors['email'])): ?>
                                <p class="of-error"><?= e($errors['email']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="of-form-group">
                            <label for="password">Password</label>
                            <div class="of-input-wrap">
                                <span class="of-input-icon">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75M6 9h12a.75.75 0 01.75.75v7.5A2.25 2.25 0 0116.5 19.5h-9A2.25 2.25 0 015.25 17.25V9.75A.75.75 0 016 9z" />
                                    </svg>
                                </span>
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    required
                                    autocomplete="current-password"
                                    placeholder="••••••••"
                                    class="of-input <?= !empty($errors['password']) ? 'is-invalid' : '' ?>"
                                    style="padding-right: 48px;"
                                >
                                <button type="button" class="of-password-toggle" data-target="password" aria-label="Show password">
                                    <span class="of-pw-show" aria-hidden="true">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                    </span>
                                    <span class="of-pw-hide" aria-hidden="true" style="display: none;">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 01-4.293 5.274M6.228 6.228L3 3m0 0l3.228 3.228m0 0A10.5 10.5 0 0012 4.5c1.5 0 2.9.3 4.1.7l2.1-2.1M21 21l-2.1-2.1" />
                                        </svg>
                                    </span>
                                </button>
                            </div>
                            <?php if (!empty($errors['password'])): ?>
                                <p class="of-error"><?= e($errors['password']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="of-meta-row">
                            <label for="remember_me" class="of-remember">
                                <input id="remember_me" name="remember" type="checkbox">
                                <span>Remember me</span>
                            </label>

                            <a href="#forgot-password" class="of-forgot" onclick="alert('Please contact your administrator to reset your POS credentials.'); return false;">
                                Forgot password?
                            </a>
                        </div>

                        <button type="submit" class="of-submit">
                            <span>Sign In</span>
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                            </svg>
                        </button>
                    </form>

                    <p class="of-signup">
                        Don't have an account?
                        <a href="<?= asset('signup.php') ?>">Create account</a>
                    </p>
                </div>
            </section>
        </div>
    </div>

    <script src="<?= asset('assets/js/auth.js') ?>"></script>
</body>
</html>

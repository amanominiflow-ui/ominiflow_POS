<?php
/**
 * OminiFlow POS - User Registration
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';

require_guest();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Invalid or expired session token. Please try again.';
    } else {
        $businessName = (string) ($_POST['business_name'] ?? '');
        $name = (string) ($_POST['name'] ?? '');
        $email = (string) ($_POST['email'] ?? '');
        $phone = (string) ($_POST['phone'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['password_confirmation'] ?? '');

        set_old_input($_POST);

        $result = register_user($name, $email, $phone, $password, $confirmPassword, $businessName);

        if ($result['success']) {
            clear_old_input();
            set_flash('success', 'Account created successfully! Please log in to access your POS terminal.');
            redirect(APP_URL . '/login.php');
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
    <title>Create POS Account — Smart Cloud POS Billing Software — OminiFlow POS</title>
    <meta name="description" content="Register your retail store on OminiFlow POS. Start fast barcode billing, multi-store inventory management, and customer GST invoicing in minutes.">
    <meta name="keywords" content="POS billing software signup, create POS account, retail billing software, point of sale register, cloud POS India">
    
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
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                            </div>
                            <div>
                                <strong>Lightning Fast POS</strong>
                                <span>Quick barcode scanning, rapid checkout, and instant digital receipt dispatch.</span>
                            </div>
                        </div>
                        <div class="of-feature">
                            <div class="of-feature-ico" aria-hidden="true">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                            </div>
                            <div>
                                <strong>Real-time Security</strong>
                                <span>Isolated database records, prepared query transactions, and session authentication.</span>
                            </div>
                        </div>
                    </div>

                    <div class="of-quote">
                        <p>"OminiFlow streamlined our checkout flow and kept our multi-store sales perfectly in sync."</p>
                        <small>Retail Operations Lead, OminiFlow Commerce</small>
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
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                        </div>
                        <div>
                            <p class="of-auth-overline">Get started with POS</p>
                            <h2>Create Account</h2>
                            <p class="of-auth-desc">Set up your POS credentials to get started.</p>
                        </div>
                    </div>

                    <?php if (!empty($errors['general'])): ?>
                        <div class="of-alert of-alert-danger">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span><?= e($errors['general']) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= asset('signup.php') ?>" novalidate>
                        <?= csrf_field() ?>

                        <div class="of-form-group">
                            <label for="business_name">Store / Business Name</label>
                            <div class="of-input-wrap">
                                <span class="of-input-icon">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.25A2.25 2.25 0 010 18.75V5.25A2.25 2.25 0 012.25 3h13.5A2.25 2.25 0 0118 5.25v13.5A2.25 2.25 0 0115.75 21H13.5z" />
                                    </svg>
                                </span>
                                <input
                                    id="business_name"
                                    name="business_name"
                                    type="text"
                                    value="<?= e(old('business_name')) ?>"
                                    autofocus
                                    placeholder="e.g. My Fashion Store"
                                    class="of-input <?= !empty($errors['business_name']) ? 'is-invalid' : '' ?>"
                                >
                            </div>
                            <?php if (!empty($errors['business_name'])): ?>
                                <p class="of-error"><?= e($errors['business_name']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="of-form-group">
                            <label for="name">Full Name</label>
                            <div class="of-input-wrap">
                                <span class="of-input-icon">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a7.5 7.5 0 1115 0v.188c0 1.1-.9 2-2.012 2H6.512a2.01 2.01 0 01-2.012-2V20.25z" />
                                    </svg>
                                </span>
                                <input
                                    id="name"
                                    name="name"
                                    type="text"
                                    value="<?= e(old('name')) ?>"
                                    required
                                    autofocus
                                    autocomplete="name"
                                    placeholder="John Doe"
                                    class="of-input <?= !empty($errors['name']) ? 'is-invalid' : '' ?>"
                                >
                            </div>
                            <?php if (!empty($errors['name'])): ?>
                                <p class="of-error"><?= e($errors['name']) ?></p>
                            <?php endif; ?>
                        </div>

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
                            <label for="phone">Phone Number</label>
                            <div class="of-input-wrap">
                                <span class="of-input-icon">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                                    </svg>
                                </span>
                                <input
                                    id="phone"
                                    name="phone"
                                    type="tel"
                                    value="<?= e(old('phone')) ?>"
                                    required
                                    autocomplete="tel"
                                    placeholder="+91 98765 43210"
                                    class="of-input <?= !empty($errors['phone']) ? 'is-invalid' : '' ?>"
                                >
                            </div>
                            <?php if (!empty($errors['phone'])): ?>
                                <p class="of-error"><?= e($errors['phone']) ?></p>
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
                                    autocomplete="new-password"
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

                        <div class="of-form-group">
                            <label for="password_confirmation">Confirm Password</label>
                            <div class="of-input-wrap">
                                <span class="of-input-icon">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </span>
                                <input
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    required
                                    autocomplete="new-password"
                                    placeholder="••••••••"
                                    class="of-input <?= !empty($errors['password_confirmation']) ? 'is-invalid' : '' ?>"
                                    style="padding-right: 48px;"
                                >
                                <button type="button" class="of-password-toggle" data-target="password_confirmation" aria-label="Show password">
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
                            <?php if (!empty($errors['password_confirmation'])): ?>
                                <p class="of-error"><?= e($errors['password_confirmation']) ?></p>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="of-submit">
                            <span>Create Account</span>
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                            </svg>
                        </button>
                    </form>

                    <p class="of-signin">
                        Already have an account?
                        <a href="<?= asset('login.php') ?>">Sign in</a>
                    </p>
                </div>
            </section>
        </div>
    </div>

    <script src="<?= asset('assets/js/auth.js') ?>"></script>
</body>
</html>

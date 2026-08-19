<?php
/**
 * OminiFlow POS - Logout Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

logout_user();

set_flash('success', 'You have been signed out successfully.');
redirect(APP_URL . '/login.php');

<?php
/**
 * Global helper functions for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function e(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function set_flash(string $type, string $message): void {
    $_SESSION['flash'][$type] = $message;
}

function get_flash(string $type): ?string {
    if (isset($_SESSION['flash'][$type])) {
        $msg = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $msg;
    }
    return null;
}

function has_flash(string $type): bool {
    return !empty($_SESSION['flash'][$type]);
}

function set_old_input(array $input): void {
    // Exclude sensitive fields
    unset($input['password'], $input['password_confirmation'], $input['csrf_token']);
    $_SESSION['old_input'] = $input;
}

function old(string $key, string $default = ''): string {
    if (isset($_SESSION['old_input'][$key])) {
        $val = (string) $_SESSION['old_input'][$key];
        return $val;
    }
    return $default;
}

function clear_old_input(): void {
    unset($_SESSION['old_input']);
}

function redirect(string $path): void {
    if (str_starts_with($path, '//') && !preg_match('#^//[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}(/|$)#', $path)) {
        $path = '/' . ltrim($path, '/');
    }
    header('Location: ' . $path);
    exit;
}

function asset(string $path): string {
    $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
    return ($base !== '' ? $base : '') . '/' . ltrim($path, '/');
}

function pos_public_base_url(): string {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return 'https://pos.ominiflow.com';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
        || str_contains(strtolower($host), 'ominiflow.com');
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host . rtrim((string) APP_URL, '/');
}

function pos_public_url(string $path = ''): string {
    return rtrim(pos_public_base_url(), '/') . '/' . ltrim($path, '/');
}

/**
 * Public URL for inbound webhooks (Razorpay, etc.). Uses production domain so
 * merchants always paste a URL Razorpay can reach — not localhost.
 */
function pos_webhook_public_url(string $path = ''): string {
    $host = defined('STORE_CNAME_TARGET') && trim((string) STORE_CNAME_TARGET) !== ''
        ? trim((string) STORE_CNAME_TARGET)
        : 'pos.ominiflow.com';
    $base = 'https://' . $host;
    if (defined('WEBHOOK_PUBLIC_APP_PATH')) {
        $base .= rtrim((string) WEBHOOK_PUBLIC_APP_PATH, '/');
    } elseif (function_exists('is_local_app_host') && !is_local_app_host()) {
        $base .= rtrim((string) APP_URL, '/');
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

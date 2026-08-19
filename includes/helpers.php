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
    header('Location: ' . $path);
    exit;
}

function asset(string $path): string {
    return APP_URL . '/' . ltrim($path, '/');
}

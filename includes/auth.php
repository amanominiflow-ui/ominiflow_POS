<?php
/**
 * Authentication management for OminiFlow POS
 * Handles user registration, password hashing, session management, and validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function is_authenticated(): bool {
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array {
    if (!is_authenticated()) {
        return null;
    }

    static $cachedUser = null;
    if ($cachedUser !== null && $cachedUser['id'] === $_SESSION['user_id']) {
        return $cachedUser;
    }

    $db = get_db();
    $stmt = $db->prepare('SELECT id, name, email, phone, status, created_at FROM users WHERE id = :id AND status = :status LIMIT 1');
    $stmt->execute([
        'id' => $_SESSION['user_id'],
        'status' => 'active',
    ]);

    $user = $stmt->fetch();
    if (!$user) {
        logout_user();
        return null;
    }

    $cachedUser = $user;
    return $user;
}

function require_auth(): void {
    if (!is_authenticated()) {
        set_flash('error', 'Please sign in to access the POS Dashboard.');
        redirect(APP_URL . '/login.php');
    }
}

function require_guest(): void {
    if (is_authenticated()) {
        redirect(APP_URL . '/dashboard.php');
    }
}

function find_user_by_email(string $email): ?array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => strtolower(trim($email))]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function register_user(string $name, string $email, string $phone, string $password, string $confirmPassword): array {
    $errors = [];
    $name = trim($name);
    $email = strtolower(trim($email));
    $phone = trim($phone);

    // Validation
    if ($name === '') {
        $errors['name'] = 'Full name is required.';
    } elseif (mb_strlen($name) < 2) {
        $errors['name'] = 'Name must be at least 2 characters.';
    }

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } elseif (find_user_by_email($email) !== null) {
        $errors['email'] = 'An account with this email already exists.';
    }

    if ($phone === '') {
        $errors['phone'] = 'Phone number is required.';
    } elseif (!preg_match('/^[0-9+\s()-]{7,20}$/', $phone)) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    }

    if ($confirmPassword === '') {
        $errors['password_confirmation'] = 'Please confirm your password.';
    } elseif ($password !== $confirmPassword) {
        $errors['password_confirmation'] = 'Password confirmation does not match.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    // Secure password hashing
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    try {
        $db = get_db();
        $stmt = $db->prepare('
            INSERT INTO users (name, email, phone, password, status, created_at)
            VALUES (:name, :email, :phone, :password, :status, NOW())
        ');
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $hashedPassword,
            'status' => 'active',
        ]);

        $userId = (int) $db->lastInsertId();

        return ['success' => true, 'errors' => [], 'user_id' => $userId];
    } catch (PDOException $e) {
        return ['success' => false, 'errors' => ['general' => 'Registration failed: ' . $e->getMessage()]];
    }
}

function login_user(string $email, string $password, bool $remember = false): array {
    $errors = [];
    $email = strtolower(trim($email));

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    $user = find_user_by_email($email);
    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'errors' => ['general' => 'These credentials do not match our records.']];
    }

    if ($user['status'] !== 'active') {
        return ['success' => false, 'errors' => ['general' => 'Your account is deactivated. Please contact support.']];
    }

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];

    return ['success' => true, 'errors' => []];
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

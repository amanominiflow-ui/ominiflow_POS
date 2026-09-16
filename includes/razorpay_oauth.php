<?php
/**
 * Razorpay OAuth + Payments (Zoho POS parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/payment_integrations_db.php';

function razorpay_is_valid_key_id(string $keyId): bool {
    $keyId = trim($keyId);
    return preg_match('/^rzp_(test|live)_[A-Za-z0-9]+$/', $keyId) === 1;
}

/**
 * Verify merchant API keys against Razorpay (Basic auth).
 */
function razorpay_validate_merchant_keys(string $keyId, string $keySecret): array {
    $keyId = trim($keyId);
    $keySecret = trim($keySecret);
    if (!razorpay_is_valid_key_id($keyId)) {
        return [
            'success' => false,
            'error' => 'Key ID must start with rzp_test_ or rzp_live_ (Razorpay Dashboard → Settings → API Keys).',
        ];
    }
    if ($keySecret === '' || strlen($keySecret) < 8) {
        return ['success' => false, 'error' => 'Please enter your Razorpay Key Secret from the dashboard.'];
    }
    $ch = curl_init('https://api.razorpay.com/v1/orders?count=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) {
        return ['success' => true];
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $msg = is_array($decoded) ? (string) ($decoded['error']['description'] ?? $decoded['error']['code'] ?? '') : '';
    if ($msg === '') {
        $msg = $code === 401 ? 'Invalid Key ID or Key Secret.' : 'Could not verify keys with Razorpay (HTTP ' . $code . ').';
    }
    return ['success' => false, 'error' => $msg];
}

/**
 * Connect merchant's own Razorpay account (until Partner OAuth is enabled platform-wide).
 */
function razorpay_connect_merchant_keys(array $input, ?int $businessId = null): array {
    $keyId = trim((string) ($input['api_key'] ?? ''));
    $keySecret = trim((string) ($input['api_secret'] ?? ''));
    $webhookSecret = trim((string) ($input['webhook_secret'] ?? ''));
    $enableInPos = !empty($input['enable_in_pos']);
    $enableInStore = !empty($input['enable_in_store']);

    $check = razorpay_validate_merchant_keys($keyId, $keySecret);
    if (empty($check['success'])) {
        return $check;
    }

    $env = str_starts_with($keyId, 'rzp_live_') ? 'live' : 'test';
    $existing = get_payment_integration_by_code('razorpay', $businessId);
    $extra = razorpay_integration_extra($existing);
    if (empty($extra['webhook_token'])) {
        $extra['webhook_token'] = bin2hex(random_bytes(16));
    }
    $extra['connect_mode'] = 'keys';

    $res = save_payment_integration([
        'gateway_code' => 'razorpay',
        'api_key' => $keyId,
        'api_secret' => $keySecret,
        'webhook_secret' => $webhookSecret ?: null,
        'environment' => $env,
        'enable_in_pos' => $enableInPos ? 1 : 0,
        'enable_in_store' => $enableInStore ? 1 : 0,
        'status' => 'connected',
        'extra_config_override' => $extra,
    ], $businessId);

    if (empty($res['success'])) {
        return ['success' => false, 'error' => $res['error'] ?? 'Could not save Razorpay settings.'];
    }
    return ['success' => true, 'gateway_name' => 'Razorpay'];
}

function razorpay_oauth_configured(): bool {
    return defined('RAZORPAY_OAUTH_CLIENT_ID')
        && trim((string) RAZORPAY_OAUTH_CLIENT_ID) !== ''
        && defined('RAZORPAY_OAUTH_CLIENT_SECRET')
        && trim((string) RAZORPAY_OAUTH_CLIENT_SECRET) !== '';
}

function razorpay_oauth_redirect_uri(): string {
    if (defined('RAZORPAY_OAUTH_REDIRECT_URI') && trim((string) RAZORPAY_OAUTH_REDIRECT_URI) !== '') {
        $uri = (string) RAZORPAY_OAUTH_REDIRECT_URI;
        if (!str_contains($uri, '://localhost') || (isset($_SERVER['HTTP_HOST']) && str_contains((string) $_SERVER['HTTP_HOST'], 'localhost'))) {
            return $uri;
        }
    }
    return pos_public_url('razorpay-callback.php');
}

function razorpay_oauth_authorize_url(string $state): string {
    $params = [
        'response_type' => 'code',
        'client_id' => (string) RAZORPAY_OAUTH_CLIENT_ID,
        'redirect_uri' => razorpay_oauth_redirect_uri(),
        'scope' => 'read_write',
        'state' => $state,
    ];
    return 'https://auth.razorpay.com/authorize?' . http_build_query($params);
}

function razorpay_http_json(string $url, string $method = 'GET', array $body = [], array $headers = []): array {
    $ch = curl_init($url);
    $httpHeaders = array_merge(['Accept: application/json'], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $httpHeaders,
    ];
    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $isForm = false;
        foreach ($httpHeaders as $h) {
            if (stripos($h, 'application/x-www-form-urlencoded') !== false) {
                $isForm = true;
                break;
            }
        }
        $opts[CURLOPT_POSTFIELDS] = $isForm ? http_build_query($body) : json_encode($body);
        if (!$isForm) {
            $httpHeaders[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $httpHeaders;
        }
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'ok' => $code >= 200 && $code < 300,
        'http_code' => $code,
        'error' => $err,
        'data' => is_array($decoded) ? $decoded : [],
        'raw' => (string) $raw,
    ];
}

function razorpay_exchange_authorization_code(string $code): array {
    $res = razorpay_http_json('https://auth.razorpay.com/token', 'POST', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => (string) RAZORPAY_OAUTH_CLIENT_ID,
        'client_secret' => (string) RAZORPAY_OAUTH_CLIENT_SECRET,
        'redirect_uri' => razorpay_oauth_redirect_uri(),
    ], ['Content-Type: application/x-www-form-urlencoded']);

    if (!$res['ok'] || empty($res['data']['access_token'])) {
        $msg = (string) ($res['data']['error_description'] ?? $res['data']['error'] ?? $res['error'] ?? 'Token exchange failed');
        return ['success' => false, 'error' => $msg, 'data' => $res['data']];
    }
    return ['success' => true, 'data' => $res['data']];
}

function razorpay_refresh_access_token(string $refreshToken): array {
    $res = razorpay_http_json('https://auth.razorpay.com/token', 'POST', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => (string) RAZORPAY_OAUTH_CLIENT_ID,
        'client_secret' => (string) RAZORPAY_OAUTH_CLIENT_SECRET,
    ], ['Content-Type: application/x-www-form-urlencoded']);

    if (!$res['ok'] || empty($res['data']['access_token'])) {
        return ['success' => false, 'error' => (string) ($res['data']['error_description'] ?? 'Refresh failed'), 'data' => $res['data']];
    }
    return ['success' => true, 'data' => $res['data']];
}

function razorpay_integration_extra(?array $integration): array {
    if (!$integration) {
        return [];
    }
    $extra = $integration['extra_config_data'] ?? [];
    if (!is_array($extra) && !empty($integration['db_record']['extra_config_data'])) {
        $extra = $integration['db_record']['extra_config_data'];
    }
    return is_array($extra) ? $extra : [];
}

function razorpay_ensure_access_token(?int $businessId = null): ?string {
    $gw = get_payment_integration_by_code('razorpay', $businessId);
    if (!$gw || empty($gw['is_configured'])) {
        return null;
    }
    $rec = $gw['db_record'] ?? [];
    $extra = razorpay_integration_extra($gw);
    $access = trim((string) ($extra['access_token'] ?? ''));
    $refresh = trim((string) ($extra['refresh_token'] ?? ''));
    $expires = (int) ($extra['token_expires_at'] ?? 0);

    if ($access !== '' && ($expires === 0 || $expires > time() + 60)) {
        return $access;
    }
    if ($refresh !== '' && razorpay_oauth_configured()) {
        $ref = razorpay_refresh_access_token($refresh);
        if (!empty($ref['success'])) {
            save_razorpay_oauth_connection($ref['data'], $businessId, $extra);
            return (string) ($ref['data']['access_token'] ?? $access);
        }
    }
    $key = trim((string) ($rec['api_key'] ?? ''));
    $secret = trim((string) ($rec['api_secret'] ?? ''));
    if ($key !== '' && $secret !== '') {
        return 'basic:' . $key . ':' . $secret;
    }
    return $access !== '' ? $access : null;
}

function razorpay_api(string $path, string $method = 'GET', array $body = [], ?int $businessId = null): array {
    $token = razorpay_ensure_access_token($businessId);
    if ($token === null || $token === '') {
        return ['ok' => false, 'http_code' => 0, 'data' => [], 'error' => 'Razorpay is not connected.'];
    }
    $headers = [];
    if (str_starts_with($token, 'basic:')) {
        $parts = explode(':', $token, 3);
        $headers[] = 'Authorization: Basic ' . base64_encode(($parts[1] ?? '') . ':' . ($parts[2] ?? ''));
    } else {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    return razorpay_http_json('https://api.razorpay.com/v1/' . ltrim($path, '/'), $method, $body, $headers);
}

function razorpay_create_order(int $amountPaise, string $receipt, array $notes = [], ?int $businessId = null): array {
    $payload = [
        'amount' => $amountPaise,
        'currency' => 'INR',
        'receipt' => $receipt,
        'payment_capture' => 1,
        'notes' => $notes,
    ];
    $res = razorpay_api('orders', 'POST', $payload, $businessId);
    if (!$res['ok'] || empty($res['data']['id'])) {
        $msg = (string) ($res['data']['error']['description'] ?? $res['error'] ?? 'Could not create Razorpay order');
        return ['success' => false, 'error' => $msg];
    }
    return ['success' => true, 'order' => $res['data']];
}

function razorpay_checkout_key(?int $businessId = null): string {
    $gw = get_payment_integration_by_code('razorpay', $businessId);
    $extra = razorpay_integration_extra($gw);
    $public = trim((string) ($extra['public_token'] ?? ''));
    if ($public !== '') {
        return $public;
    }
    return trim((string) (($gw['db_record']['api_key'] ?? '')));
}

function razorpay_verify_checkout(string $orderId, string $paymentId, string $signature, ?int $businessId = null): bool {
    $gw = get_payment_integration_by_code('razorpay', $businessId);
    $rec = $gw['db_record'] ?? [];
    $secret = trim((string) ($rec['api_secret'] ?? ''));
    if ($secret !== '' && $signature !== '') {
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    if (razorpay_oauth_configured() && $signature !== '' && defined('RAZORPAY_OAUTH_CLIENT_SECRET')) {
        $expectedOauth = hash_hmac('sha256', $orderId . '|' . $paymentId, (string) RAZORPAY_OAUTH_CLIENT_SECRET);
        if (hash_equals($expectedOauth, $signature)) {
            return true;
        }
    }
    $pay = razorpay_api('payments/' . rawurlencode($paymentId), 'GET', [], $businessId);
    if (empty($pay['ok'])) {
        return false;
    }
    $status = (string) ($pay['data']['status'] ?? '');
    $payOrder = (string) ($pay['data']['order_id'] ?? '');
    return in_array($status, ['authorized', 'captured'], true) && ($payOrder === '' || $payOrder === $orderId);
}

/**
 * Ensure connected Razorpay has a stable webhook token (persist if missing).
 */
function razorpay_ensure_webhook_token(?int $businessId = null): string {
    $gw = get_payment_integration_by_code('razorpay', $businessId);
    if (!$gw || empty($gw['is_configured'])) {
        return '';
    }
    $extra = razorpay_integration_extra($gw);
    $token = trim((string) ($extra['webhook_token'] ?? ''));
    if ($token !== '') {
        return $token;
    }
    $token = bin2hex(random_bytes(16));
    $extra['webhook_token'] = $token;
    if (empty($extra['connect_mode'])) {
        $extra['connect_mode'] = !empty($extra['access_token']) ? 'oauth' : 'keys';
    }
    $rec = $gw['db_record'] ?? [];
    save_payment_integration([
        'gateway_code' => 'razorpay',
        'api_key' => (string) ($rec['api_key'] ?? ''),
        'api_secret' => (string) ($rec['api_secret'] ?? ''),
        'merchant_id' => (string) ($rec['merchant_id'] ?? ''),
        'webhook_secret' => (string) ($rec['webhook_secret'] ?? ''),
        'status' => $gw['status'] ?? 'connected',
        'environment' => $gw['environment'] ?? 'test',
        'enable_in_pos' => $gw['enable_in_pos'] ?? 1,
        'enable_in_store' => $gw['enable_in_store'] ?? 1,
        'extra_config_override' => $extra,
    ], $businessId);
    return $token;
}

function razorpay_webhook_url_for(?array $integration): string {
    $token = razorpay_ensure_webhook_token();
    if ($token === '') {
        $extra = razorpay_integration_extra($integration);
        $token = trim((string) ($extra['webhook_token'] ?? ''));
    }
    if ($token === '') {
        return '';
    }
    return pos_public_url('webhooks/razorpay/' . $token);
}

function save_razorpay_oauth_connection(array $tokenData, ?int $businessId = null, array $existingExtra = []): array {
    $public = trim((string) ($tokenData['public_token'] ?? $existingExtra['public_token'] ?? ''));
    $env = str_starts_with($public, 'rzp_live') ? 'live' : 'test';
    $webhookToken = trim((string) ($existingExtra['webhook_token'] ?? ''));
    if ($webhookToken === '') {
        $webhookToken = bin2hex(random_bytes(16));
    }
    $expiresIn = (int) ($tokenData['expires_in'] ?? 7776000);
    $extra = array_merge($existingExtra, [
        'connect_mode' => 'oauth',
        'access_token' => (string) ($tokenData['access_token'] ?? $existingExtra['access_token'] ?? ''),
        'refresh_token' => (string) ($tokenData['refresh_token'] ?? $existingExtra['refresh_token'] ?? ''),
        'public_token' => $public,
        'razorpay_account_id' => (string) ($tokenData['razorpay_account_id'] ?? $tokenData['razorpay_id'] ?? $existingExtra['razorpay_account_id'] ?? ''),
        'token_expires_at' => time() + max(60, $expiresIn),
        'webhook_token' => $webhookToken,
        'oauth_email' => (string) ($tokenData['email'] ?? $existingExtra['oauth_email'] ?? ''),
    ]);

    $save = [
        'gateway_code' => 'razorpay',
        'api_key' => $public,
        'api_secret' => '',
        'merchant_id' => (string) ($extra['razorpay_account_id'] ?? ''),
        'environment' => $env,
        'enable_in_pos' => 1,
        'enable_in_store' => 1,
        'status' => 'connected',
        'extra_config_override' => $extra,
    ];
    return save_payment_integration($save, $businessId);
}

function find_razorpay_by_webhook_token(string $token): ?array {
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $db = get_db();
    ensure_payment_integrations_table($db);
    $stmt = $db->query("SELECT * FROM payment_integrations WHERE gateway_code = 'razorpay'");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        $extra = [];
        if (!empty($row['extra_config'])) {
            $extra = json_decode((string) $row['extra_config'], true) ?: [];
        }
        if (!empty($extra['webhook_token']) && hash_equals((string) $extra['webhook_token'], $token)) {
            $row['extra_config_data'] = $extra;
            return $row;
        }
    }
    return null;
}

function razorpay_apply_webhook_payment(array $payload, array $integration): void {
    $entity = $payload['payload']['payment']['entity'] ?? [];
    if (!is_array($entity)) {
        return;
    }
    $paymentId = (string) ($entity['id'] ?? '');
    $orderId = (string) ($entity['order_id'] ?? '');
    $status = (string) ($entity['status'] ?? '');
    if ($paymentId === '') {
        return;
    }
    $bid = (int) ($integration['business_id'] ?? 0);
    $db = get_db();
    $payStatus = in_array($status, ['captured', 'authorized'], true) ? 'paid' : 'pending';
    $likeParts = array_filter([$paymentId, $orderId]);
    foreach ($likeParts as $needle) {
        $stmt = $db->prepare('SELECT id, notes FROM orders WHERE business_id = :bid AND notes LIKE :q ORDER BY id DESC LIMIT 5');
        $stmt->execute(['bid' => $bid, 'q' => '%' . $needle . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
            $db->prepare('UPDATE orders SET payment_status = :ps, updated_at = NOW() WHERE id = :id AND business_id = :bid')
                ->execute(['ps' => $payStatus, 'id' => (int) $order['id'], 'bid' => $bid]);
            try {
                $db->prepare('UPDATE invoices SET payment_status = :ps, updated_at = NOW() WHERE order_id = :oid AND business_id = :bid')
                    ->execute(['ps' => $payStatus, 'oid' => (int) $order['id'], 'bid' => $bid]);
            } catch (Throwable $e) {
                // invoices.payment_status may not exist on older schemas
            }
        }
    }
}

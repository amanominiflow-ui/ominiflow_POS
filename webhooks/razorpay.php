<?php
/**
 * Razorpay webhook receiver (Zoho POS parity)
 * URL: /webhooks/razorpay/{token}
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/razorpay_oauth.php';

header('Content-Type: application/json');

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '' && !empty($_SERVER['PATH_INFO'])) {
    $token = trim((string) $_SERVER['PATH_INFO'], '/');
}
if ($token === '') {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (preg_match('#/webhooks/razorpay/([A-Za-z0-9]+)#', $uri, $m)) {
        $token = $m[1];
    }
}

if ($token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing webhook token']);
    exit;
}

$integration = find_razorpay_by_webhook_token($token);
if (!$integration || !in_array((string) ($integration['status'] ?? ''), ['connected', 'active'], true)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Unknown webhook']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$secret = trim((string) ($integration['webhook_secret'] ?? ''));
$sig = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
if ($secret !== '' && $sig !== '') {
    $expected = hash_hmac('sha256', $raw, $secret);
    if (!hash_equals($expected, $sig)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid signature']);
        exit;
    }
}

$event = (string) ($payload['event'] ?? '');
if (in_array($event, ['payment.authorized', 'payment.captured'], true)) {
    razorpay_apply_webhook_payment($payload, $integration);
}

echo json_encode(['success' => true]);

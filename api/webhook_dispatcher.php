<?php
/**
 * OminiFlow POS Webhook Dispatcher Helper
 * Sends event payloads to Omniflow webhook endpoint.
 */

declare(strict_types=1);

function ominiflow_pos_dispatch_webhook(string $webhookUrl, string $eventKey, array $data): bool
{
    if (empty($webhookUrl)) {
        return false;
    }

    $payload = array_merge($data, [
        'event_key' => $eventKey,
        'timestamp' => time(),
    ]);

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);

    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status >= 200 && $status < 300;
}

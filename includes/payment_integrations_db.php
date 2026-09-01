<?php
/**
 * Customer Payment Integrations Database Service (Zoho POS / Books Parity)
 * Supports Razorpay, Paytm PG, Stripe, 2Checkout / Verifone, Pine Labs, PhonePe, and Worldline.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Ensure payment_integrations table exists (fail-safe runtime check)
 */
function ensure_payment_integrations_table(PDO $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `payment_integrations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `business_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `gateway_code` VARCHAR(50) NOT NULL,
                `gateway_name` VARCHAR(100) NOT NULL,
                `api_key` VARCHAR(255) NULL,
                `api_secret` VARCHAR(255) NULL,
                `merchant_id` VARCHAR(100) NULL,
                `webhook_secret` VARCHAR(255) NULL,
                `terminal_id` VARCHAR(100) NULL,
                `environment` ENUM('test', 'live') NOT NULL DEFAULT 'test',
                `enable_in_pos` TINYINT(1) NOT NULL DEFAULT 1,
                `enable_in_store` TINYINT(1) NOT NULL DEFAULT 1,
                `status` ENUM('active', 'inactive', 'connected', 'disconnected') NOT NULL DEFAULT 'disconnected',
                `extra_config` JSON NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_business_gateway` (`business_id`, `gateway_code`),
                INDEX `idx_payment_integ_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $checked = true;
    } catch (Throwable $e) {
        // Log or ignore if already exists
    }
}

/**
 * Master Gateway Catalog matching user screenshots
 */
function get_master_payment_gateways(): array {
    return [
        'razorpay' => [
            'code' => 'razorpay',
            'name' => 'Razorpay',
            'in_store' => false,
            'learn_more_url' => 'https://razorpay.com/docs/payments/payment-gateway/',
            'signup_url' => 'https://razorpay.com/signup',
            'description' => "Razorpay is a payments platform supporting both domestic and international payments. Enjoy the industry's best success rates & 100+ payment options to grow your business. Also, empower your customers with various EMI options.",
            'logo_type' => 'razorpay',
            'fields' => [
                ['name' => 'api_key', 'label' => 'Key ID', 'type' => 'text', 'placeholder' => 'rzp_test_...', 'required' => true],
                ['name' => 'api_secret', 'label' => 'Key Secret', 'type' => 'password', 'placeholder' => 'Enter Razorpay Secret Key', 'required' => true],
                ['name' => 'webhook_secret', 'label' => 'Webhook Secret (Optional)', 'type' => 'text', 'placeholder' => 'Enter webhook secret for auto-capture'],
            ]
        ],
        'paytm' => [
            'code' => 'paytm',
            'name' => 'Paytm PG',
            'in_store' => true,
            'learn_more_url' => 'https://business.paytm.com/payment-gateway',
            'signup_url' => 'https://business.paytm.com/signup',
            'signup_label' => 'New to Paytm? Sign up Now ›',
            'description' => "Paytm Payment Gateway enables you to accept payments easily through payment modes such as UPI, debit & credit cards, net banking, Paytm wallet, and more.",
            'logo_type' => 'paytm',
            'fields' => [
                ['name' => 'merchant_id', 'label' => 'Merchant ID (MID)', 'type' => 'text', 'placeholder' => 'e.g. YOUR_MID_HERE', 'required' => true],
                ['name' => 'api_key', 'label' => 'Merchant Key', 'type' => 'password', 'placeholder' => 'Enter Merchant Encryption Key', 'required' => true],
                ['name' => 'website_name', 'label' => 'Website Name', 'type' => 'text', 'placeholder' => 'DEFAULT / WEBSTAGING', 'required' => false],
                ['name' => 'industry_type', 'label' => 'Industry Type ID', 'type' => 'text', 'placeholder' => 'Retail', 'required' => false],
            ]
        ],
        'stripe' => [
            'code' => 'stripe',
            'name' => 'Stripe',
            'in_store' => false,
            'learn_more_url' => 'https://stripe.com/docs/payments',
            'signup_url' => 'https://dashboard.stripe.com/register',
            'description' => "Stripe is an online payment processing platform that allows you to receive one-time payments securely from customers. It also manages all your payments and makes reconciliation a breeze. You can set it up in no time and get paid faster.",
            'logo_type' => 'stripe',
            'fields' => [
                ['name' => 'api_key', 'label' => 'Publishable Key', 'type' => 'text', 'placeholder' => 'pk_test_...', 'required' => true],
                ['name' => 'api_secret', 'label' => 'Secret Key', 'type' => 'password', 'placeholder' => 'sk_test_...', 'required' => true],
                ['name' => 'webhook_secret', 'label' => 'Signing Secret (whsec_...)', 'type' => 'text', 'placeholder' => 'whsec_...'],
            ]
        ],
        'verifone' => [
            'code' => 'verifone',
            'name' => '2Checkout (Verifone)',
            'in_store' => false,
            'learn_more_url' => 'https://www.2checkout.com/documentation/',
            'signup_url' => 'https://www.2checkout.com/',
            'description' => "2Checkout enables businesses to accept mobile and online payments from buyers worldwide. It is ideal for businesses that sell products internationally.",
            'logo_type' => 'verifone',
            'fields' => [
                ['name' => 'merchant_id', 'label' => 'Merchant Code / Account ID', 'type' => 'text', 'placeholder' => 'e.g. 250111222', 'required' => true],
                ['name' => 'api_key', 'label' => 'Publishable API Key', 'type' => 'text', 'placeholder' => 'Enter 2Checkout Publishable Key', 'required' => true],
                ['name' => 'api_secret', 'label' => 'Secret Key / INS Secret Word', 'type' => 'password', 'placeholder' => 'Enter 2Checkout Secret Key', 'required' => true],
            ]
        ],
        'pinelabs' => [
            'code' => 'pinelabs',
            'name' => 'Pine Labs',
            'in_store' => true,
            'learn_more_url' => 'https://www.pinelabs.com/',
            'signup_url' => 'https://www.pinelabs.com/contact-us',
            'description' => "From contactless payments and debit /credit cards to UPI, mobile wallets, and reward points, you can now accept over 100 payment methods at your store, quickly and securely.",
            'logo_type' => 'pinelabs',
            'fields' => [
                ['name' => 'merchant_id', 'label' => 'Merchant ID (MID)', 'type' => 'text', 'placeholder' => 'e.g. PL_9088712', 'required' => true],
                ['name' => 'api_key', 'label' => 'Client ID / Security Key', 'type' => 'password', 'placeholder' => 'Enter Pine Labs Security Key', 'required' => true],
                ['name' => 'terminal_id', 'label' => 'EDC POS Terminal ID (TID)', 'type' => 'text', 'placeholder' => 'e.g. TID_001928', 'required' => true],
                ['name' => 'terminal_ip', 'label' => 'Terminal IP / Plutus Host (Optional)', 'type' => 'text', 'placeholder' => '192.168.1.100:8080'],
            ]
        ],
        'phonepe' => [
            'code' => 'phonepe',
            'name' => 'PhonePe',
            'in_store' => true,
            'learn_more_url' => 'https://www.phonepe.com/business-solutions/payment-gateway/',
            'signup_url' => 'https://business.phonepe.com/',
            'description' => "PhonePe is a leading digital payments platform in India that enables businesses to accept payments through UPI, built-in digital wallet, debit and credit cards.",
            'logo_type' => 'phonepe',
            'fields' => [
                ['name' => 'merchant_id', 'label' => 'PhonePe Merchant ID', 'type' => 'text', 'placeholder' => 'e.g. M230616048322067', 'required' => true],
                ['name' => 'api_secret', 'label' => 'Salt Key', 'type' => 'password', 'placeholder' => 'Enter PhonePe Salt Key', 'required' => true],
                ['name' => 'salt_index', 'label' => 'Salt Index', 'type' => 'text', 'placeholder' => '1', 'required' => true],
                ['name' => 'terminal_id', 'label' => 'Store / Terminal ID (Optional)', 'type' => 'text', 'placeholder' => 'STORE_01'],
            ]
        ],
        'worldline' => [
            'code' => 'worldline',
            'name' => 'Worldline',
            'in_store' => true,
            'learn_more_url' => 'https://worldline.com/',
            'signup_url' => 'https://worldline.com/en-in/home.html',
            'description' => "To ensure automatic payment capture using the Worldline EDC POS device, configure gateway credentials and then add available terminals for the respective gateway merchant details in OmniFlow POS.",
            'logo_type' => 'worldline',
            'fields' => [
                ['name' => 'merchant_id', 'label' => 'Worldline Merchant Code', 'type' => 'text', 'placeholder' => 'e.g. WL_MERCHANT_902', 'required' => true],
                ['name' => 'api_key', 'label' => 'API Key / Passcode', 'type' => 'password', 'placeholder' => 'Enter Worldline API Key', 'required' => true],
                ['name' => 'terminal_id', 'label' => 'EDC Terminal ID (TID)', 'type' => 'text', 'placeholder' => 'e.g. WL_TID_8820', 'required' => true],
                ['name' => 'device_serial', 'label' => 'Device Serial / IP (Optional)', 'type' => 'text', 'placeholder' => 'POS-EDC-01'],
            ]
        ],
    ];
}

/**
 * Fetch all configured payment integrations for a business
 */
function get_payment_integrations(?int $businessId = null): array {
    $db = get_db();
    ensure_payment_integrations_table($db);
    $bid = $businessId ?: current_business_id();

    $master = get_master_payment_gateways();

    try {
        $stmt = $db->prepare('SELECT * FROM payment_integrations WHERE business_id = :bid');
        $stmt->execute(['bid' => $bid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $configured = [];
        foreach ($rows as $row) {
            if (!empty($row['extra_config'])) {
                $row['extra_config_data'] = json_decode($row['extra_config'], true) ?: [];
            } else {
                $row['extra_config_data'] = [];
            }
            $configured[$row['gateway_code']] = $row;
        }

        // Merge with master catalog
        $result = [];
        foreach ($master as $code => $meta) {
            $config = $configured[$code] ?? null;
            $result[$code] = array_merge($meta, [
                'is_configured' => ($config !== null && in_array($config['status'], ['connected', 'active'], true)),
                'status' => $config['status'] ?? 'disconnected',
                'environment' => $config['environment'] ?? 'test',
                'enable_in_pos' => isset($config['enable_in_pos']) ? (int)$config['enable_in_pos'] : 1,
                'enable_in_store' => isset($config['enable_in_store']) ? (int)$config['enable_in_store'] : 1,
                'db_record' => $config,
            ]);
        }
        return $result;
    } catch (Throwable $e) {
        // Fallback to master
        $result = [];
        foreach ($master as $code => $meta) {
            $result[$code] = array_merge($meta, [
                'is_configured' => false,
                'status' => 'disconnected',
                'environment' => 'test',
                'enable_in_pos' => 1,
                'enable_in_store' => 1,
                'db_record' => null,
            ]);
        }
        return $result;
    }
}

/**
 * Fetch single gateway integration by code
 */
function get_payment_integration_by_code(string $code, ?int $businessId = null): ?array {
    $integrations = get_payment_integrations($businessId);
    return $integrations[$code] ?? null;
}

/**
 * Save or Update Payment Gateway Integration
 */
function save_payment_integration(array $data, ?int $businessId = null): array {
    $db = get_db();
    ensure_payment_integrations_table($db);
    $bid = $businessId ?: current_business_id();

    $gatewayCode = trim((string)($data['gateway_code'] ?? ''));
    $master = get_master_payment_gateways();

    if (!isset($master[$gatewayCode])) {
        return ['success' => false, 'error' => 'Invalid payment gateway code.'];
    }

    $meta = $master[$gatewayCode];
    $gatewayName = $meta['name'];
    $apiKey = trim((string)($data['api_key'] ?? ''));
    $apiSecret = trim((string)($data['api_secret'] ?? ''));
    $merchantId = trim((string)($data['merchant_id'] ?? ''));
    $webhookSecret = trim((string)($data['webhook_secret'] ?? ''));
    $terminalId = trim((string)($data['terminal_id'] ?? ''));
    $environment = in_array($data['environment'] ?? 'test', ['test', 'live'], true) ? $data['environment'] : 'test';
    $enableInPos = !empty($data['enable_in_pos']) ? 1 : 0;
    $enableInStore = !empty($data['enable_in_store']) ? 1 : 0;
    $status = (!empty($data['status']) && in_array($data['status'], ['connected', 'active', 'disconnected', 'inactive'], true))
        ? $data['status']
        : 'connected';

    // Extra fields JSON
    $extraFields = ['website_name', 'industry_type', 'salt_index', 'terminal_ip', 'device_serial'];
    $extraData = [];
    foreach ($extraFields as $ef) {
        if (isset($data[$ef])) {
            $extraData[$ef] = trim((string)$data[$ef]);
        }
    }
    $extraJson = !empty($extraData) ? json_encode($extraData) : null;

    try {
        $stmt = $db->prepare("
            INSERT INTO payment_integrations (
                business_id, gateway_code, gateway_name, api_key, api_secret,
                merchant_id, webhook_secret, terminal_id, environment,
                enable_in_pos, enable_in_store, status, extra_config, updated_at
            ) VALUES (
                :bid, :code, :name, :key, :secret,
                :mid, :whsec, :tid, :env,
                :in_pos, :in_store, :status, :extra, NOW()
            )
            ON DUPLICATE KEY UPDATE
                gateway_name = VALUES(gateway_name),
                api_key = VALUES(api_key),
                api_secret = VALUES(api_secret),
                merchant_id = VALUES(merchant_id),
                webhook_secret = VALUES(webhook_secret),
                terminal_id = VALUES(terminal_id),
                environment = VALUES(environment),
                enable_in_pos = VALUES(enable_in_pos),
                enable_in_store = VALUES(enable_in_store),
                status = VALUES(status),
                extra_config = VALUES(extra_config),
                updated_at = NOW()
        ");

        $stmt->execute([
            'bid' => $bid,
            'code' => $gatewayCode,
            'name' => $gatewayName,
            'key' => $apiKey ?: null,
            'secret' => $apiSecret ?: null,
            'mid' => $merchantId ?: null,
            'whsec' => $webhookSecret ?: null,
            'tid' => $terminalId ?: null,
            'env' => $environment,
            'in_pos' => $enableInPos,
            'in_store' => $enableInStore,
            'status' => $status,
            'extra' => $extraJson,
        ]);

        return ['success' => true, 'gateway_name' => $gatewayName];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Disconnect or toggle payment gateway status
 */
function disconnect_payment_integration(string $gatewayCode, ?int $businessId = null): bool {
    $db = get_db();
    ensure_payment_integrations_table($db);
    $bid = $businessId ?: current_business_id();

    try {
        $stmt = $db->prepare('UPDATE payment_integrations SET status = "disconnected", updated_at = NOW() WHERE business_id = :bid AND gateway_code = :code');
        $stmt->execute(['bid' => $bid, 'code' => $gatewayCode]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get active payment gateways enabled for in-store POS checkouts
 */
function get_active_pos_payment_gateways(?int $businessId = null): array {
    $integrations = get_payment_integrations($businessId);
    $active = [];
    foreach ($integrations as $code => $g) {
        if ($g['is_configured'] && !empty($g['enable_in_pos'])) {
            $active[$code] = $g;
        }
    }
    return $active;
}

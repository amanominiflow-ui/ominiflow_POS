<?php
/**
 * OminiFlow POS - Enterprise Customer Management & Zoho Schema
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function ensure_customer_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = get_db();
    $cols = [
        'customer_type' => "VARCHAR(20) DEFAULT 'Individual'",
        'salutation' => "VARCHAR(20) NULL",
        'first_name' => "VARCHAR(100) NULL",
        'last_name' => "VARCHAR(100) NULL",
        'company_name' => "VARCHAR(150) NULL",
        'work_phone' => "VARCHAR(50) NULL",
        'gst_treatment' => "VARCHAR(80) NULL",
        'place_of_supply' => "VARCHAR(100) NULL",
        'pan_number' => "VARCHAR(30) NULL",
        'tax_preference' => "VARCHAR(30) DEFAULT 'Taxable'",
        'currency' => "VARCHAR(10) DEFAULT 'INR'",
        'payment_terms' => "VARCHAR(50) DEFAULT 'Due on Receipt'",
        'remarks' => "TEXT NULL"
    ];

    foreach ($cols as $col => $def) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM customers LIKE '{$col}'");
            if (!$stmt->fetch()) {
                $db->exec("ALTER TABLE `customers` ADD `{$col}` {$def}");
            }
        } catch (Throwable $e) {
            // Schema alter best-effort
        }
    }
}

function create_enterprise_customer(array $data, ?int $businessId = null): array {
    ensure_customer_schema();
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $customerType = trim((string)($data['customer_type'] ?? 'Individual'));
    $salutation = trim((string)($data['salutation'] ?? ''));
    $firstName = trim((string)($data['first_name'] ?? ''));
    $lastName = trim((string)($data['last_name'] ?? ''));
    $companyName = trim((string)($data['company_name'] ?? ''));
    $displayName = trim((string)($data['display_name'] ?? ($data['name'] ?? '')));
    $email = trim((string)($data['email'] ?? ''));
    $workPhone = trim((string)($data['work_phone'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ($data['mobile'] ?? '')));
    $gstTreatment = trim((string)($data['gst_treatment'] ?? ''));
    $placeOfSupply = trim((string)($data['place_of_supply'] ?? ''));
    $pan = strtoupper(trim((string)($data['pan'] ?? ($data['pan_number'] ?? ''))));
    $taxPreference = trim((string)($data['tax_preference'] ?? 'Taxable'));
    $currency = trim((string)($data['currency'] ?? 'INR'));
    $paymentTerms = trim((string)($data['payment_terms'] ?? 'Due on Receipt'));
    $address = trim((string)($data['address'] ?? ''));
    $remarks = trim((string)($data['remarks'] ?? ''));

    // Fallback displayName if blank
    if ($displayName === '') {
        if ($firstName !== '' || $lastName !== '') {
            $displayName = trim($firstName . ' ' . $lastName);
        } elseif ($companyName !== '') {
            $displayName = $companyName;
        }
    }

    if ($displayName === '') {
        return ['success' => false, 'error' => 'Customer Display Name is required.'];
    }

    try {
        $stmt = $db->prepare('
            INSERT INTO customers (
                business_id, name, customer_type, salutation, first_name, last_name,
                company_name, phone, work_phone, email, gst_treatment, place_of_supply,
                pan_number, tax_preference, currency, payment_terms, address, remarks,
                created_at, updated_at
            ) VALUES (
                :bid, :name, :customer_type, :salutation, :first_name, :last_name,
                :company_name, :phone, :work_phone, :email, :gst_treatment, :place_of_supply,
                :pan_number, :tax_preference, :currency, :payment_terms, :address, :remarks,
                NOW(), NOW()
            )
        ');

        $stmt->execute([
            'bid' => $bid,
            'name' => $displayName,
            'customer_type' => $customerType,
            'salutation' => $salutation ?: null,
            'first_name' => $firstName ?: null,
            'last_name' => $lastName ?: null,
            'company_name' => $companyName ?: null,
            'phone' => $phone ?: ($workPhone ?: null),
            'work_phone' => $workPhone ?: null,
            'email' => $email ?: null,
            'gst_treatment' => $gstTreatment ?: null,
            'place_of_supply' => $placeOfSupply ?: null,
            'pan_number' => $pan ?: null,
            'tax_preference' => $taxPreference ?: 'Taxable',
            'currency' => $currency ?: 'INR',
            'payment_terms' => $paymentTerms ?: 'Due on Receipt',
            'address' => $address ?: null,
            'remarks' => $remarks ?: null,
        ]);

        $newId = (int)$db->lastInsertId();

        return [
            'success' => true,
            'id' => $newId,
            'name' => $displayName,
            'phone' => $phone ?: $workPhone,
            'email' => $email,
            'place_of_supply' => $placeOfSupply,
            'payment_terms' => $paymentTerms,
            'message' => "Customer '{$displayName}' added successfully."
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

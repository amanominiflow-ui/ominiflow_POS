<?php
/**
 * POS Orders, Invoices, Customers, and Checkout Database Services for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/products_db.php';

function ensure_orders_invoices_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = get_db();

    $tablesAndCols = [
        'invoices' => ['invoice_number', 'uk_business_invoice_number'],
        'orders' => ['order_number', 'uk_business_order_number'],
        'payments' => ['payment_number', 'uk_business_payment_number'],
        'returns' => ['return_number', 'uk_business_return_number'],
        'credit_notes' => ['credit_note_number', 'uk_business_credit_note_number'],
        'purchase_orders' => ['po_number', 'uk_business_po_number'],
        'vendor_payments' => ['payment_number', 'uk_business_vpay_number'],
        'stock_transfers' => ['transfer_number', 'uk_business_trf_number'],
        'stock_counts' => ['count_number', 'uk_business_stk_number'],
    ];

    foreach ($tablesAndCols as $tbl => $info) {
        [$col, $ukName] = $info;
        try {
            $indexes = $db->query("SHOW INDEX FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($indexes as $idx) {
                if ($idx['Key_name'] !== 'PRIMARY' && $idx['Column_name'] === $col && (int)$idx['Non_unique'] === 0) {
                    $kName = $idx['Key_name'];
                    if ($kName !== $ukName) {
                        try {
                            $db->exec("ALTER TABLE `{$tbl}` DROP INDEX `{$kName}`");
                        } catch (Exception $e) {}
                    }
                }
            }
        } catch (Exception $e) {}
    }
}

function generate_unique_reference(string $table, string $column, string $prefix, ?PDO $db = null): string {
    ensure_orders_invoices_schema();
    $db = $db ?: get_db();
    $fullPrefix = $prefix . date('Ymd') . '-';

    try {
        $stmt = $db->prepare("
            SELECT `{$column}` FROM `{$table}` 
            WHERE `{$column}` LIKE :prefix 
            ORDER BY id DESC LIMIT 100
        ");
        $stmt->execute(['prefix' => $fullPrefix . '%']);
        $recent = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $maxSeq = 0;
        foreach ($recent as $val) {
            $parts = explode('-', (string) $val);
            $lastSeq = (int) end($parts);
            if ($lastSeq > $maxSeq) {
                $maxSeq = $lastSeq;
            }
        }
        $seq = max(1, $maxSeq + 1);

        $chkStmt = $db->prepare("SELECT id FROM `{$table}` WHERE `{$column}` = :val LIMIT 1");
        do {
            $candidate = $fullPrefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $chkStmt->execute(['val' => $candidate]);
            $exists = (int) $chkStmt->fetchColumn();
            if ($exists > 0) {
                $seq++;
            }
        } while ($exists > 0);

        return $candidate;
    } catch (Exception $e) {
        return $fullPrefix . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    }
}

function generate_next_invoice_number(int $businessId, ?PDO $db = null): string {
    return generate_unique_reference('invoices', 'invoice_number', 'INV-', $db);
}

function generate_next_payment_number(int $businessId, ?PDO $db = null): string {
    return generate_unique_reference('payments', 'payment_number', 'PAY-', $db);
}

function generate_next_return_number(int $businessId, ?PDO $db = null): string {
    return generate_unique_reference('returns', 'return_number', 'RET-', $db);
}

function generate_next_order_number(int $businessId, ?PDO $db = null): string {
    ensure_orders_invoices_schema();
    $db = $db ?: get_db();
    $chkStmt = $db->prepare("SELECT id FROM orders WHERE order_number = :num LIMIT 1");
    do {
        $orderNum = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $chkStmt->execute(['num' => $orderNum]);
        $exists = (int) $chkStmt->fetchColumn();
    } while ($exists > 0);
    return $orderNum;
}

/* =========================================================================
   1. STORE BUSINESS SETTINGS
   ========================================================================= */

function get_store_settings(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    
    $settings = null;
    $biz = null;
    $bizProfile = null;
    $gst = null;
    $mob = null;

    try {
        $stmt = $db->prepare('SELECT * FROM store_settings WHERE business_id = :bid LIMIT 1');
        $stmt->execute(['bid' => $bid]);
        $settings = $stmt->fetch() ?: null;
    } catch (PDOException $e) {}

    try {
        $stmtBiz = $db->prepare('SELECT * FROM businesses WHERE id = :id LIMIT 1');
        $stmtBiz->execute(['id' => $bid]);
        $biz = $stmtBiz->fetch() ?: null;
    } catch (PDOException $e) {}

    try {
        $stmtProf = $db->prepare('SELECT * FROM business_profile WHERE business_id = :bid OR id = :id LIMIT 1');
        $stmtProf->execute(['bid' => $bid, 'id' => $bid]);
        $bizProfile = $stmtProf->fetch() ?: null;
    } catch (PDOException $e) {}

    try {
        $stmtGST = $db->prepare('SELECT * FROM gst_settings WHERE business_id = :bid OR id = 1 LIMIT 1');
        $stmtGST->execute(['bid' => $bid]);
        $gst = $stmtGST->fetch() ?: null;
    } catch (PDOException $e) {}

    try {
        $stmtMob = $db->prepare('SELECT * FROM mobile_store_settings WHERE business_id = :bid LIMIT 1');
        $stmtMob->execute(['bid' => $bid]);
        $mob = $stmtMob->fetch() ?: null;
    } catch (PDOException $e) {}

    $storeName = !empty($settings['store_name']) ? $settings['store_name'] :
        (!empty($bizProfile['business_name']) ? $bizProfile['business_name'] :
        (!empty($mob['display_name']) ? $mob['display_name'] :
        (!empty($biz['name']) ? $biz['name'] : 'Ominiflow Enterprises')));

    $legalName = !empty($settings['legal_name']) ? $settings['legal_name'] :
        (!empty($biz['legal_name']) ? $biz['legal_name'] :
        (!empty($gst['business_legal_name']) ? $gst['business_legal_name'] : $storeName));

    $tagline = !empty($settings['tagline']) ? $settings['tagline'] :
        (!empty($mob['banner_subtitle']) ? $mob['banner_subtitle'] : 'easy, smarter, endless');

    $logoPath = !empty($settings['logo_path']) ? $settings['logo_path'] :
        (!empty($bizProfile['logo_path']) ? $bizProfile['logo_path'] :
        (!empty($mob['logo_path']) ? $mob['logo_path'] : 'assets/images/logo.jpg'));

    $city = !empty($settings['city']) ? $settings['city'] :
        (!empty($bizProfile['city']) ? $bizProfile['city'] :
        (!empty($biz['city']) ? $biz['city'] : 'DEWAS'));

    $state = !empty($settings['state']) ? $settings['state'] :
        (!empty($bizProfile['state']) ? $bizProfile['state'] :
        (!empty($biz['state']) ? $biz['state'] : 'Madhya Pradesh'));

    $pincode = !empty($settings['pincode']) ? $settings['pincode'] :
        (!empty($bizProfile['zip_code']) ? $bizProfile['zip_code'] :
        (!empty($biz['pincode']) ? $biz['pincode'] : '455001'));

    $addrLines = [];
    if (!empty($settings['address'])) {
        $addrLines[] = $settings['address'];
    } elseif (!empty($bizProfile['address_line1']) || !empty($bizProfile['address_line2'])) {
        if (!empty($bizProfile['address_line1'])) $addrLines[] = $bizProfile['address_line1'];
        if (!empty($bizProfile['address_line2'])) $addrLines[] = $bizProfile['address_line2'];
    } elseif (!empty($biz['address'])) {
        $addrLines[] = $biz['address'];
    } else {
        $addrLines[] = '18, TARANI COLONY';
    }
    $addrBase = implode(', ', array_filter($addrLines));
    $fullAddress = $addrBase;
    if ($city && !str_contains($fullAddress, $city)) {
        $fullAddress .= ', ' . $city;
    }
    if ($pincode && !str_contains($fullAddress, $pincode)) {
        $fullAddress .= ' (' . $pincode . ')';
    }

    $phone = !empty($settings['phone']) ? $settings['phone'] :
        (!empty($bizProfile['phone']) ? (($bizProfile['phone_code'] ?? '+91') . ' ' . $bizProfile['phone']) :
        (!empty($biz['phone']) ? $biz['phone'] : '+91 9755332357'));

    $email = !empty($settings['email']) ? $settings['email'] :
        (!empty($bizProfile['email']) ? $bizProfile['email'] :
        (!empty($biz['email']) ? $biz['email'] : 'info@ominiflow.com'));

    $gstin = !empty($settings['gstin']) ? $settings['gstin'] :
        (!empty($gst['gstin']) ? $gst['gstin'] :
        (!empty($biz['tax_id']) ? $biz['tax_id'] : '23BMKPN8756M1ZA'));

    $panNumber = !empty($settings['pan_number']) ? $settings['pan_number'] :
        (strlen($gstin) >= 15 ? substr($gstin, 2, 10) : '');

    $bankName = !empty($settings['bank_name']) ? $settings['bank_name'] :
        (!empty($bizProfile['bank_name']) ? $bizProfile['bank_name'] : 'HDFC Bank');

    $accHolder = !empty($settings['account_holder']) ? $settings['account_holder'] :
        (!empty($bizProfile['account_holder']) ? $bizProfile['account_holder'] : $storeName);

    $accNum = !empty($settings['account_number']) ? $settings['account_number'] :
        (!empty($bizProfile['account_number']) ? $bizProfile['account_number'] : '50200111653091');

    $ifsc = !empty($settings['bank_ifsc']) ? $settings['bank_ifsc'] :
        (!empty($bizProfile['bank_ifsc']) ? $bizProfile['bank_ifsc'] : 'HDFC0000887');

    $branch = !empty($settings['bank_branch']) ? $settings['bank_branch'] :
        (!empty($bizProfile['bank_branch']) ? $bizProfile['bank_branch'] : $city);

    $accType = !empty($settings['account_type']) ? $settings['account_type'] :
        (!empty($bizProfile['account_type']) ? $bizProfile['account_type'] : 'Current Account');

    $upiId = !empty($settings['upi_id']) ? $settings['upi_id'] :
        (!empty($bizProfile['upi_id']) ? $bizProfile['upi_id'] : '');

    $terms = !empty($settings['terms_conditions']) ? $settings['terms_conditions'] :
        (!empty($bizProfile['terms_conditions']) ? $bizProfile['terms_conditions'] : '');

    $privacy = !empty($settings['privacy_policy']) ? $settings['privacy_policy'] :
        (!empty($bizProfile['privacy_policy']) ? $bizProfile['privacy_policy'] :
        (!empty($mob['privacy_policy']) ? $mob['privacy_policy'] : ''));

    $packageName = !empty($settings['package_name']) ? $settings['package_name'] :
        (!empty($bizProfile['package_name']) ? $bizProfile['package_name'] : 'Monthly');

    $currencySymbol = !empty($settings['currency_symbol']) ? $settings['currency_symbol'] :
        (!empty($biz['currency_symbol']) ? $biz['currency_symbol'] : '₹');

    $taxType = !empty($settings['tax_type']) ? $settings['tax_type'] : 'GST';

    return [
        'id' => (int)($settings['id'] ?? $bid),
        'business_id' => $bid,
        'store_name' => $storeName,
        'legal_name' => $legalName,
        'tagline' => $tagline,
        'logo_path' => $logoPath,
        'address' => $fullAddress,
        'raw_address' => $addrBase,
        'city' => $city,
        'state' => $state,
        'pincode' => $pincode,
        'phone' => $phone,
        'email' => $email,
        'gstin' => $gstin,
        'pan_number' => $panNumber,
        'bank_name' => $bankName,
        'account_holder' => $accHolder,
        'account_number' => $accNum,
        'bank_ifsc' => $ifsc,
        'bank_branch' => $branch,
        'account_type' => $accType,
        'upi_id' => $upiId,
        'terms_conditions' => $terms,
        'privacy_policy' => $privacy,
        'package_name' => $packageName,
        'currency_symbol' => $currencySymbol,
        'tax_type' => $taxType,
    ];
}

function update_store_settings(array $data, ?int $businessId = null): bool {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    
    $stmtCheck = $db->prepare('SELECT id FROM store_settings WHERE business_id = :bid LIMIT 1');
    $stmtCheck->execute(['bid' => $bid]);
    $existing = $stmtCheck->fetch();

    $storeName = trim((string)($data['store_name'] ?? 'My POS Store'));
    $legalName = trim((string)($data['legal_name'] ?? $storeName));
    $tagline = trim((string)($data['tagline'] ?? ''));
    $logoPath = trim((string)($data['logo_path'] ?? ''));
    $address = trim((string)($data['address'] ?? ''));
    $city = trim((string)($data['city'] ?? ''));
    $state = trim((string)($data['state'] ?? ''));
    $pincode = trim((string)($data['pincode'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $gstin = trim((string)($data['gstin'] ?? ''));
    $panNumber = trim((string)($data['pan_number'] ?? ''));
    $bankName = trim((string)($data['bank_name'] ?? 'HDFC Bank'));
    $accHolder = trim((string)($data['account_holder'] ?? $storeName));
    $accNum = trim((string)($data['account_number'] ?? ''));
    $ifsc = trim((string)($data['bank_ifsc'] ?? ''));
    $branch = trim((string)($data['bank_branch'] ?? ''));
    $accType = trim((string)($data['account_type'] ?? 'Current Account'));
    $upiId = trim((string)($data['upi_id'] ?? ''));
    $terms = trim((string)($data['terms_conditions'] ?? ''));
    $privacy = trim((string)($data['privacy_policy'] ?? ''));
    $packageName = trim((string)($data['package_name'] ?? 'Monthly'));
    $currency = trim((string)($data['currency_symbol'] ?? '₹'));

    if ($existing) {
        $stmt = $db->prepare('
            UPDATE store_settings
            SET store_name = :store_name, legal_name = :legal_name, tagline = :tagline,
                ' . ($logoPath !== '' ? 'logo_path = :logo_path,' : '') . '
                address = :address, city = :city, state = :state, pincode = :pincode,
                phone = :phone, email = :email, gstin = :gstin, pan_number = :pan_number,
                bank_name = :bank_name, account_holder = :account_holder, account_number = :account_number,
                bank_ifsc = :bank_ifsc, bank_branch = :bank_branch, account_type = :account_type,
                upi_id = :upi_id, terms_conditions = :terms_conditions, privacy_policy = :privacy_policy,
                package_name = :package_name, currency_symbol = :currency_symbol, updated_at = NOW()
            WHERE business_id = :bid
        ');
        $params = [
            'store_name' => $storeName,
            'legal_name' => $legalName,
            'tagline' => $tagline,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'pincode' => $pincode,
            'phone' => $phone,
            'email' => $email,
            'gstin' => $gstin,
            'pan_number' => $panNumber,
            'bank_name' => $bankName,
            'account_holder' => $accHolder,
            'account_number' => $accNum,
            'bank_ifsc' => $ifsc,
            'bank_branch' => $branch,
            'account_type' => $accType,
            'upi_id' => $upiId,
            'terms_conditions' => $terms,
            'privacy_policy' => $privacy,
            'package_name' => $packageName,
            'currency_symbol' => $currency,
            'bid' => $bid,
        ];
        if ($logoPath !== '') $params['logo_path'] = $logoPath;
        return $stmt->execute($params);
    } else {
        $stmt = $db->prepare('
            INSERT INTO store_settings (
                business_id, store_name, legal_name, tagline, logo_path, address, city, state, pincode,
                phone, email, gstin, pan_number, bank_name, account_holder, account_number,
                bank_ifsc, bank_branch, account_type, upi_id, terms_conditions, privacy_policy,
                package_name, currency_symbol, created_at, updated_at
            ) VALUES (
                :bid, :store_name, :legal_name, :tagline, :logo_path, :address, :city, :state, :pincode,
                :phone, :email, :gstin, :pan_number, :bank_name, :account_holder, :account_number,
                :bank_ifsc, :bank_branch, :account_type, :upi_id, :terms_conditions, :privacy_policy,
                :package_name, :currency_symbol, NOW(), NOW()
            )
        ');
        return $stmt->execute([
            'bid' => $bid,
            'store_name' => $storeName,
            'legal_name' => $legalName,
            'tagline' => $tagline,
            'logo_path' => $logoPath ?: 'assets/images/logo.jpg',
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'pincode' => $pincode,
            'phone' => $phone,
            'email' => $email,
            'gstin' => $gstin,
            'pan_number' => $panNumber,
            'bank_name' => $bankName,
            'account_holder' => $accHolder,
            'account_number' => $accNum,
            'bank_ifsc' => $ifsc,
            'bank_branch' => $branch,
            'account_type' => $accType,
            'upi_id' => $upiId,
            'terms_conditions' => $terms,
            'privacy_policy' => $privacy,
            'package_name' => $packageName,
            'currency_symbol' => $currency,
        ]);
    }
}

/* =========================================================================
   2. CUSTOMER OPERATIONS
   ========================================================================= */

function get_customers(string $search = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = 'SELECT * FROM customers WHERE business_id = :bid';
    $params = ['bid' => $bid];

    if ($search !== '') {
        $sql .= ' AND (name LIKE :search1 OR phone LIKE :search2 OR email LIKE :search3)';
        $params['search1'] = '%' . $search . '%';
        $params['search2'] = '%' . $search . '%';
        $params['search3'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY id ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_customer_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $id, 'bid' => $bid]);
    $cust = $stmt->fetch();
    return $cust ?: null;
}

function save_customer(array $data, ?int $businessId = null): array {
    $errors = [];
    $bid = $businessId ?: current_business_id();
    $name = trim((string) ($data['name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $address = trim((string) ($data['address'] ?? ''));

    if ($name === '') {
        $errors['name'] = 'Customer name is required.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email address.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    $db = get_db();
    try {
        $stmt = $db->prepare('
            INSERT INTO customers (business_id, name, phone, email, address, created_at, updated_at)
            VALUES (:biz_id, :name, :phone, :email, :address, NOW(), NOW())
        ');
        $stmt->execute([
            'biz_id' => $bid,
            'name' => $name,
            'phone' => $phone ?: null,
            'email' => $email ?: null,
            'address' => $address ?: null,
        ]);
        $customerId = (int) $db->lastInsertId();

        return ['success' => true, 'errors' => [], 'customer_id' => $customerId];
    } catch (PDOException $e) {
        return ['success' => false, 'errors' => ['general' => 'Database error: ' . $e->getMessage()]];
    }
}

/* =========================================================================
   3. ATOMIC POS ORDER CHECKOUT, INVOICE GENERATION & INVENTORY DEDUCTION
   ========================================================================= */

function process_pos_order(
    array $cartItems,
    ?int $customerId,
    ?int $userId,
    float $discountVal = 0.00,
    string $discountType = 'fixed',
    string $paymentMethod = 'cash',
    string $notes = '',
    float $amountTendered = 0.00,
    ?int $outletId = 1,
    ?string $clientOrderUuid = null,
    ?int $couponId = null,
    ?string $couponCode = null,
    int $loyaltyPointsUsed = 0,
    float $loyaltyDiscountAmount = 0.00,
    ?int $priceListId = null,
    ?int $businessId = null,
    string $salesChannel = 'pos',
    string $fulfillmentStatus = 'delivered',
    ?string $overridePaymentStatus = null
): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    if (empty($cartItems)) {
        return ['success' => false, 'errors' => ['cart' => 'Cannot checkout an empty cart. Please add items.']];
    }

    // Idempotency check for offline POS synchronization
    if ($clientOrderUuid !== null && trim($clientOrderUuid) !== '') {
        $stmtCheckUuid = $db->prepare('
            SELECT o.id AS order_id, o.order_number, i.id AS invoice_id, i.invoice_number, o.total_amount
            FROM orders o
            LEFT JOIN invoices i ON i.order_id = o.id
            WHERE o.client_order_uuid = :uuid AND o.business_id = :bid
            LIMIT 1
        ');
        $stmtCheckUuid->execute(['uuid' => trim($clientOrderUuid), 'bid' => $bid]);
        $existingOrder = $stmtCheckUuid->fetch();
        if ($existingOrder) {
            return [
                'success' => true,
                'order_id' => (int)$existingOrder['order_id'],
                'order_number' => $existingOrder['order_number'],
                'invoice_id' => (int)$existingOrder['invoice_id'],
                'invoice_number' => $existingOrder['invoice_number'],
                'total_amount' => (float)$existingOrder['total_amount'],
                'change_amount' => 0.00,
                'synced_existing' => true,
            ];
        }
    }

    try {
        $db->beginTransaction();

        $subtotal = 0.00;
        $totalTax = 0.00;
        $processedItems = [];

        // 1. Validate each item against live database records with FOR UPDATE lock
        foreach ($cartItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $variantId = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
            $qty = max(1, (int) ($item['quantity'] ?? 1));

            if ($productId <= 0) {
                throw new Exception('Invalid product ID in cart.');
            }

            $stmtProd = $db->prepare('
                SELECT id, name, sku, barcode, cost_price, selling_price, tax_percent, stock_quantity, status, product_type, hsn_code
                FROM products
                WHERE id = :id AND business_id = :bid
                FOR UPDATE
            ');
            $stmtProd->execute(['id' => $productId, 'bid' => $bid]);
            $product = $stmtProd->fetch();

            if (!$product) {
                throw new Exception('Product with ID #' . $productId . ' does not exist.');
            }

            if ($product['status'] !== 'active') {
                throw new Exception('Product "' . $product['name'] . '" is inactive and cannot be sold.');
            }

            $currentStock = (int) $product['stock_quantity'];
            $isComposite = ($product['product_type'] === 'composite');

            // If simple or variable product, validate stock
            if (!$isComposite && $currentStock < $qty) {
                throw new Exception(sprintf(
                    'Insufficient stock for "%s" (SKU: %s). Available: %d units, Requested: %d units.',
                    $product['name'],
                    $product['sku'],
                    $currentStock,
                    $qty
                ));
            }

            // Price list check
            $unitPrice = (float) $product['selling_price'];
            if (!empty($item['price']) && (float)$item['price'] > 0) {
                $unitPrice = (float)$item['price'];
            }

            $taxPercent = (float) $product['tax_percent'];
            $itemSubtotal = $unitPrice * $qty;
            $itemTax = $itemSubtotal * ($taxPercent / 100.0);
            $itemTotal = $itemSubtotal + $itemTax;

            $subtotal += $itemSubtotal;
            $totalTax += $itemTax;

            $processedItems[] = [
                'product_id' => $product['id'],
                'variant_id' => $variantId,
                'product_name' => $product['name'],
                'product_sku' => $product['sku'],
                'product_barcode' => $product['barcode'] ?? '',
                'hsn_code' => $product['hsn_code'] ?? '',
                'product_type' => $product['product_type'],
                'unit_price' => $unitPrice,
                'quantity' => $qty,
                'tax_percent' => $taxPercent,
                'tax_amount' => $itemTax,
                'discount_amount' => 0.00,
                'line_total' => $itemTotal,
                'stock_before' => $currentStock,
                'stock_after' => max(0, $currentStock - $qty),
            ];
        }

        // 2. Calculate Discounts & Final Total
        $discountAmount = 0.00;
        if ($discountType === 'percent') {
            $percent = max(0.0, min(100.0, $discountVal));
            $discountAmount = $subtotal * ($percent / 100.0);
        } else {
            $discountAmount = max(0.0, min($subtotal, $discountVal));
        }

        // Apply loyalty discount if any
        if ($loyaltyDiscountAmount > 0) {
            $discountAmount += min($subtotal - $discountAmount, $loyaltyDiscountAmount);
        }

        $taxableAmount = max(0.00, $subtotal - $discountAmount);
        $grandTotal = max(0.00, round(($taxableAmount + $totalTax), 2));

        // 3. Validate Cash Tendered
        $changeAmount = 0.00;
        if ($paymentMethod === 'cash') {
            if ($amountTendered > 0 && $amountTendered < $grandTotal) {
                throw new Exception(sprintf(
                    'Amount received (₹%.2f) is less than the payable total (₹%.2f).',
                    $amountTendered,
                    $grandTotal
                ));
            }
            $tendered = ($amountTendered > 0) ? $amountTendered : $grandTotal;
            $changeAmount = max(0.00, $tendered - $grandTotal);
        } else {
            $tendered = $grandTotal;
        }

        // Validate User ID against foreign key
        $validUserId = null;
        if ($userId !== null && (int)$userId > 0) {
            $stmtU = $db->prepare('SELECT id FROM users WHERE id = :uid LIMIT 1');
            $stmtU->execute(['uid' => (int)$userId]);
            if ($stmtU->fetchColumn()) {
                $validUserId = (int)$userId;
            }
        }

        // 4. Generate Order Number
        $orderNumber = generate_next_order_number($bid, $db);

        // 5. Insert Order Record
        $stmtOrder = $db->prepare('
            INSERT INTO orders (
                business_id, order_number, outlet_id, customer_id, user_id, subtotal, discount_amount, discount_type,
                price_list_id, coupon_id, coupon_code, loyalty_points_used, loyalty_discount_amount,
                tax_amount, total_amount, payment_method, payment_status, order_status, fulfillment_status,
                client_order_uuid, notes, created_at, updated_at
            ) VALUES (
                :biz_id, :order_number, :outlet_id, :customer_id, :user_id, :subtotal, :discount_amount, :discount_type,
                :price_list_id, :coupon_id, :coupon_code, :loyalty_points_used, :loyalty_discount_amount,
                :tax_amount, :total_amount, :payment_method, :payment_status, :order_status, :fulfillment_status,
                :client_order_uuid, :notes, NOW(), NOW()
            )
        ');
        $allowedFulfillment = ['pending', 'confirmed', 'packed', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled', 'returned'];
        $fulfillmentStatus = in_array($fulfillmentStatus, $allowedFulfillment, true) ? $fulfillmentStatus : 'delivered';
        $stmtOrder->execute([
            'biz_id' => $bid,
            'order_number' => $orderNumber,
            'outlet_id' => $outletId ?: 1,
            'customer_id' => $customerId ?: 1, // Default to Walk-in customer
            'user_id' => $validUserId,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'discount_type' => in_array($discountType, ['fixed', 'percent'], true) ? $discountType : 'fixed',
            'price_list_id' => $priceListId ?: null,
            'coupon_id' => $couponId ?: null,
            'coupon_code' => $couponCode ?: null,
            'loyalty_points_used' => $loyaltyPointsUsed,
            'loyalty_discount_amount' => $loyaltyDiscountAmount,
            'tax_amount' => $totalTax,
            'total_amount' => $grandTotal,
            'payment_method' => $paymentMethod ?: 'cash',
            'payment_status' => $overridePaymentStatus ?: 'paid',
            'order_status' => 'completed',
            'fulfillment_status' => $fulfillmentStatus,
            'client_order_uuid' => $clientOrderUuid ?: null,
            'notes' => $notes ?: null,
        ]);
        $orderId = (int) $db->lastInsertId();

        if ($salesChannel !== 'pos') {
            try {
                $db->prepare('UPDATE orders SET sales_channel = :ch WHERE id = :id AND business_id = :bid')
                    ->execute(['ch' => $salesChannel, 'id' => $orderId, 'bid' => $bid]);
            } catch (PDOException $eCh) {
                // sales_channel column is added by online-store schema; POS checkout still succeeds
            }
        }

        // 6. Insert Order Items & Deduct Stock Atomically
        $stmtItem = $db->prepare('
            INSERT INTO order_items (
                order_id, product_id, variant_id, product_name, product_sku, hsn_code, unit_price,
                quantity, tax_percent, tax_amount, discount_amount, line_total, created_at
            ) VALUES (
                :order_id, :product_id, :variant_id, :product_name, :product_sku, :hsn_code, :unit_price,
                :quantity, :tax_percent, :tax_amount, :discount_amount, :line_total, NOW()
            )
        ');

        $stmtStockDec = $db->prepare('
            UPDATE products
            SET stock_quantity = stock_quantity - :qty, updated_at = NOW()
            WHERE id = :id AND business_id = :biz_id
        ');

        $stmtMoveLog = $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :biz_id, :product_id, :user_id, :movement_type, :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
            )
        ');

        foreach ($processedItems as $pItem) {
            // Save item
            $stmtItem->execute([
                'order_id' => $orderId,
                'product_id' => $pItem['product_id'],
                'variant_id' => $pItem['variant_id'] ?: null,
                'product_name' => $pItem['product_name'],
                'product_sku' => $pItem['product_sku'],
                'hsn_code' => $pItem['hsn_code'] ?: null,
                'unit_price' => $pItem['unit_price'],
                'quantity' => $pItem['quantity'],
                'tax_percent' => $pItem['tax_percent'],
                'tax_amount' => $pItem['tax_amount'],
                'discount_amount' => $pItem['discount_amount'],
                'line_total' => $pItem['line_total'],
            ]);

            // If Composite Product: deduct component items
            if ($pItem['product_type'] === 'composite') {
                $stmtComp = $db->prepare('SELECT component_product_id, quantity FROM composite_product_items WHERE parent_product_id = :pid');
                $stmtComp->execute(['pid' => $pItem['product_id']]);
                $comps = $stmtComp->fetchAll();
                foreach ($comps as $comp) {
                    $compPid = (int)$comp['component_product_id'];
                    $compDeduct = (int)$comp['quantity'] * $pItem['quantity'];

                    $stmtCompCur = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :biz_id FOR UPDATE');
                    $stmtCompCur->execute(['id' => $compPid, 'biz_id' => $bid]);
                    $beforeCompStock = (int)$stmtCompCur->fetchColumn();

                    $stmtStockDec->execute(['qty' => $compDeduct, 'id' => $compPid, 'biz_id' => $bid]);
                    $stmtMoveLog->execute([
                        'biz_id' => $bid,
                        'product_id' => $compPid,
                        'user_id' => $validUserId,
                        'movement_type' => 'out',
                        'quantity_change' => -$compDeduct,
                        'quantity_before' => $beforeCompStock,
                        'quantity_after' => $beforeCompStock - $compDeduct,
                        'reason' => "POS Bundle Sale '{$pItem['product_name']}' (Order #{$orderNumber})",
                    ]);
                }
            } else {
                // Deduct simple or variable product stock
                $stmtStockDec->execute([
                    'qty' => $pItem['quantity'],
                    'id' => $pItem['product_id'],
                    'biz_id' => $bid,
                ]);

                // Log inventory movement
                $stmtMoveLog->execute([
                    'biz_id' => $bid,
                    'product_id' => $pItem['product_id'],
                    'user_id' => $validUserId,
                    'movement_type' => 'out',
                    'quantity_change' => -$pItem['quantity'],
                    'quantity_before' => $pItem['stock_before'],
                    'quantity_after' => $pItem['stock_after'],
                    'reason' => 'POS Sale Order #' . $orderNumber,
                ]);
            }
        }

        // 7. Generate Invoice via bill_generate_pos inside same atomic transaction
        $cgstAmount = round($totalTax / 2, 2);
        $sgstAmount = round($totalTax - $cgstAmount, 2);
        $igstAmount = 0.00;

        // Generate sequential invoice number for this business
        $invoiceNumber = generate_next_invoice_number($bid, $db);

        $stmtInvoice = $db->prepare('
            INSERT INTO invoices (
                business_id, invoice_number, order_id, customer_id, user_id, invoice_date, subtotal,
                discount_amount, discount_type, taxable_amount, cgst_amount, sgst_amount, igst_amount,
                tax_amount, total_amount, amount_paid, change_amount, payment_method, payment_status,
                invoice_status, notes, created_at, updated_at
            ) VALUES (
                :biz_id, :invoice_number, :order_id, :customer_id, :user_id, NOW(), :subtotal,
                :discount_amount, :discount_type, :taxable_amount, :cgst_amount, :sgst_amount, :igst_amount,
                :tax_amount, :total_amount, :amount_paid, :change_amount, :payment_method, :payment_status,
                :invoice_status, :notes, NOW(), NOW()
            )
        ');
        $stmtInvoice->execute([
            'biz_id' => $bid,
            'invoice_number' => $invoiceNumber,
            'order_id' => $orderId,
            'customer_id' => $customerId ?: 1,
            'user_id' => $validUserId,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'discount_type' => $discountType,
            'taxable_amount' => $taxableAmount,
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'igst_amount' => $igstAmount,
            'tax_amount' => $totalTax,
            'total_amount' => $grandTotal,
            'amount_paid' => $tendered,
            'change_amount' => $changeAmount,
            'payment_method' => $paymentMethod ?: 'cash',
            'payment_status' => 'paid',
            'invoice_status' => 'paid',
            'notes' => $notes ?: null,
        ]);
        $invoiceId = (int) $db->lastInsertId();

        // 8. Record in Centralized Payments table
        $paymentNumber = generate_next_payment_number($bid, $db);

        // Check for active open register session
        $activeSessionId = null;
        if ($validUserId !== null) {
            $stmtSession = $db->prepare('SELECT id FROM register_sessions WHERE user_id = :uid AND business_id = :bid AND status = "open" ORDER BY id DESC LIMIT 1');
            $stmtSession->execute(['uid' => $validUserId, 'bid' => $bid]);
            $activeSessionId = $stmtSession->fetchColumn() ?: null;
        }

        $stmtPay = $db->prepare('
            INSERT INTO payments (
                business_id, payment_number, order_id, invoice_id, customer_id, user_id, session_id,
                payment_type, payment_method, amount, status, created_at
            ) VALUES (
                :biz_id, :pay_num, :order_id, :inv_id, :cust_id, :user_id, :session_id,
                "sale", :method, :amount, "paid", NOW()
            )
        ');
        $stmtPay->execute([
            'biz_id' => $bid,
            'pay_num' => $paymentNumber,
            'order_id' => $orderId,
            'inv_id' => $invoiceId,
            'cust_id' => $customerId ?: 1,
            'user_id' => $validUserId,
            'session_id' => $activeSessionId,
            'method' => $paymentMethod ?: 'cash',
            'amount' => $grandTotal,
        ]);

        if ($activeSessionId) {
            $col = 'total_cash_sales';
            if ($paymentMethod === 'card') $col = 'total_card_sales';
            elseif ($paymentMethod === 'upi') $col = 'total_upi_sales';
            $db->exec("UPDATE register_sessions SET {$col} = {$col} + {$grandTotal} WHERE id = {$activeSessionId}");
        }

        // Customer details
        $custStmt = $db->prepare('SELECT name, phone, email, address FROM customers WHERE id = :id LIMIT 1');
        $custStmt->execute(['id' => $customerId ?: 1]);
        $custData = $custStmt->fetch();
        $customerName = $custData ? $custData['name'] : 'Walk-in Customer';
        $customerPhone = $custData ? ($custData['phone'] ?? '') : '';

        // Cashier name
        $userStmt = $db->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
        $userStmt->execute(['id' => $userId]);
        $userData = $userStmt->fetch();
        $cashierName = $userData ? $userData['name'] : 'Cashier';

        $db->commit();

        return [
            'success' => true,
            'errors' => [],
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'subtotal' => $subtotal,
            'taxable_amount' => $taxableAmount,
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'tax_amount' => $totalTax,
            'discount_amount' => $discountAmount,
            'discount_type' => $discountType,
            'total_amount' => $grandTotal,
            'amount_paid' => $tendered,
            'change_amount' => $changeAmount,
            'items_count' => count($processedItems),
            'items' => $processedItems,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'cashier_name' => $cashierName,
            'payment_method' => $paymentMethod,
            'payment_status' => 'paid',
            'invoice_status' => 'paid',
            'created_at' => date('Y-m-d H:i:s'),
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'errors' => ['checkout' => $e->getMessage()]];
    }
}

/* =========================================================================
   4. BILL_GENERATE_POS SERVICE
   ========================================================================= */

/**
 * Generates or retrieves complete formatted invoice data for POS orders.
 * Reusable for display, printing, PDF export, and external billing endpoints.
 */
function bill_generate_pos(int $orderId, array $options = []): array {
    $db = get_db();
    $order = get_order_by_id($orderId);
    if (!$order) {
        return ['success' => false, 'error' => 'Order #' . $orderId . ' not found.'];
    }

    $store = get_store_settings();

    // Check if invoice already exists for this order
    $stmtInv = $db->prepare('SELECT * FROM invoices WHERE order_id = :order_id LIMIT 1');
    $stmtInv->execute(['order_id' => $orderId]);
    $invoice = $stmtInv->fetch();

    if (!$invoice) {
        // Create new invoice for order
        $subtotal = (float) $order['subtotal'];
        $discountAmount = (float) $order['discount_amount'];
        $taxAmount = (float) $order['tax_amount'];
        $taxableAmount = max(0.00, $subtotal - $discountAmount);
        $totalAmount = (float) $order['total_amount'];

        $cgstAmount = round($taxAmount / 2, 2);
        $sgstAmount = round($taxAmount - $cgstAmount, 2);
        $igstAmount = 0.00;

        $invoiceBizId = (int)($order['business_id'] ?? 1);
        $invoiceNumber = generate_next_invoice_number($invoiceBizId, $db);

        $stmtInsert = $db->prepare('
            INSERT INTO invoices (
                business_id, invoice_number, order_id, customer_id, user_id, invoice_date, subtotal,
                discount_amount, discount_type, taxable_amount, cgst_amount, sgst_amount, igst_amount,
                tax_amount, total_amount, amount_paid, change_amount, payment_method, payment_status,
                invoice_status, notes, created_at, updated_at
            ) VALUES (
                :biz_id, :invoice_number, :order_id, :customer_id, :user_id, NOW(), :subtotal,
                :discount_amount, :discount_type, :taxable_amount, :cgst_amount, :sgst_amount, :igst_amount,
                :tax_amount, :total_amount, :amount_paid, :change_amount, :payment_method, :payment_status,
                :invoice_status, :notes, NOW(), NOW()
            )
        ');
        $stmtInsert->execute([
            'biz_id' => $invoiceBizId,
            'invoice_number' => $invoiceNumber,
            'order_id' => $orderId,
            'customer_id' => $order['customer_id'],
            'user_id' => $order['user_id'],
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'discount_type' => $order['discount_type'] ?? 'fixed',
            'taxable_amount' => $taxableAmount,
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'igst_amount' => $igstAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'amount_paid' => $totalAmount,
            'change_amount' => 0.00,
            'payment_method' => $order['payment_method'] ?? 'cash',
            'payment_status' => $order['payment_status'] ?? 'paid',
            'invoice_status' => ($order['order_status'] === 'cancelled') ? 'cancelled' : 'paid',
            'notes' => $order['notes'] ?? null,
        ]);
        $invoiceId = (int) $db->lastInsertId();

        $stmtInv->execute(['order_id' => $orderId]);
        $invoice = $stmtInv->fetch();
    }

    return [
        'success' => true,
        'invoice' => $invoice,
        'order' => $order,
        'store' => $store,
        'items' => $order['items'] ?? [],
    ];
}

/* =========================================================================
   5. INVOICES LISTING & DETAILS
   ========================================================================= */

function get_invoices(string $search = '', string $status = '', string $dateFrom = '', string $dateTo = '', int $limit = 50, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT inv.*, o.order_number, c.name AS customer_name, c.phone AS customer_phone, u.name AS cashier_name,
               (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = inv.order_id) AS items_count
        FROM invoices inv
        LEFT JOIN orders o ON o.id = inv.order_id AND o.business_id = :bid_o
        LEFT JOIN customers c ON c.id = inv.customer_id AND c.business_id = :bid_c
        LEFT JOIN users u ON u.id = inv.user_id
        WHERE inv.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_o' => $bid,
        'bid_c' => $bid,
    ];

    if ($search !== '') {
        $sql .= ' AND (inv.invoice_number LIKE :search1 OR o.order_number LIKE :search2 OR c.name LIKE :search3 OR c.phone LIKE :search4)';
        $params['search1'] = '%' . $search . '%';
        $params['search2'] = '%' . $search . '%';
        $params['search3'] = '%' . $search . '%';
        $params['search4'] = '%' . $search . '%';
    }

    if ($status !== '' && in_array($status, ['paid', 'draft', 'cancelled', 'refunded'], true)) {
        $sql .= ' AND inv.invoice_status = :status';
        $params['status'] = $status;
    }

    if ($dateFrom !== '') {
        $sql .= ' AND DATE(inv.invoice_date) >= :date_from';
        $params['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $sql .= ' AND DATE(inv.invoice_date) <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $sql .= ' ORDER BY inv.id DESC LIMIT ' . max(1, $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_invoice_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT inv.*, o.order_number, o.notes AS order_notes, c.name AS customer_name, c.phone AS customer_phone,
               c.email AS customer_email, c.address AS customer_address, u.name AS cashier_name
        FROM invoices inv
        LEFT JOIN orders o ON o.id = inv.order_id AND o.business_id = :bid_o
        LEFT JOIN customers c ON c.id = inv.customer_id AND c.business_id = :bid_c
        LEFT JOIN users u ON u.id = inv.user_id
        WHERE inv.id = :id AND inv.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid, 'bid_o' => $bid, 'bid_c' => $bid]);
    $invoice = $stmt->fetch();

    if (!$invoice) return null;

    $stmtItems = $db->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC');
    $stmtItems->execute(['order_id' => $invoice['order_id']]);
    $invoice['items'] = $stmtItems->fetchAll();
    $invoice['store'] = get_store_settings($bid);

    return $invoice;
}

function get_invoice_by_order_id(int $orderId, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT id FROM invoices WHERE order_id = :order_id AND business_id = :bid LIMIT 1');
    $stmt->execute(['order_id' => $orderId, 'bid' => $bid]);
    $id = $stmt->fetchColumn();
    return $id ? get_invoice_by_id((int)$id, $bid) : null;
}

/* =========================================================================
   6. SAFE INVOICE CANCELLATION & INVENTORY STOCK REVERSAL
   ========================================================================= */

function cancel_invoice(int $invoiceId, ?int $userId, string $reason = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    try {
        $db->beginTransaction();

        $invoice = get_invoice_by_id($invoiceId, $bid);
        if (!$invoice) {
            throw new Exception('Invoice #' . $invoiceId . ' not found.');
        }

        if ($invoice['invoice_status'] === 'cancelled') {
            throw new Exception('Invoice #' . $invoice['invoice_number'] . ' is already cancelled.');
        }

        $orderId = (int) $invoice['order_id'];
        $cleanReason = trim($reason) ?: 'POS Invoice Cancellation';

        // 1. Mark invoice as cancelled
        $stmtInv = $db->prepare('UPDATE invoices SET invoice_status = "cancelled", updated_at = NOW() WHERE id = :id AND business_id = :bid');
        $stmtInv->execute(['id' => $invoiceId, 'bid' => $bid]);

        // 2. Mark linked order as cancelled
        $stmtOrd = $db->prepare('UPDATE orders SET order_status = "cancelled", updated_at = NOW() WHERE id = :id AND business_id = :bid');
        $stmtOrd->execute(['id' => $orderId, 'bid' => $bid]);

        // 3. Restore inventory stock & record reversal movement for each item
        $stmtStockInc = $db->prepare('
            UPDATE products
            SET stock_quantity = stock_quantity + :qty, updated_at = NOW()
            WHERE id = :id AND business_id = :bid
        ');

        $stmtMoveLog = $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :biz_id, :product_id, :user_id, "in", :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
            )
        ');

        foreach ($invoice['items'] as $item) {
            $prodId = (int) $item['product_id'];
            $qty = (int) $item['quantity'];

            if ($prodId > 0) {
                // Get current stock
                $stmtCur = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :bid FOR UPDATE');
                $stmtCur->execute(['id' => $prodId, 'bid' => $bid]);
                $currStock = (int) $stmtCur->fetchColumn();

                // Increment stock
                $stmtStockInc->execute(['qty' => $qty, 'id' => $prodId, 'bid' => $bid]);

                // Record inventory movement reversal
                $stmtMoveLog->execute([
                    'biz_id' => $bid,
                    'product_id' => $prodId,
                    'user_id' => $userId,
                    'quantity_change' => $qty,
                    'quantity_before' => $currStock,
                    'quantity_after' => $currStock + $qty,
                    'reason' => 'Stock Reversal: Cancelled Invoice #' . $invoice['invoice_number'] . ' (' . $cleanReason . ')',
                ]);
            }
        }

        $db->commit();

        return ['success' => true, 'invoice_number' => $invoice['invoice_number']];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/* =========================================================================
   7. HELD SALES (HOLD & RESUME)
   ========================================================================= */

function save_held_sale(string $referenceNote, ?int $customerId, ?int $userId, array $cartItems, float $subtotal, float $totalAmount, ?int $businessId = null): array {
    if (empty($cartItems)) {
        return ['success' => false, 'error' => 'Cannot hold an empty cart.'];
    }

    $bid = $businessId ?: current_business_id();
    $ref = trim($referenceNote);
    if ($ref === '') {
        $ref = 'Hold #' . date('h:i A');
    }

    $db = get_db();
    try {
        $stmt = $db->prepare('
            INSERT INTO held_sales (business_id, reference_note, customer_id, user_id, cart_json, subtotal, total_amount, created_at)
            VALUES (:biz_id, :reference_note, :customer_id, :user_id, :cart_json, :subtotal, :total_amount, NOW())
        ');
        $stmt->execute([
            'biz_id' => $bid,
            'reference_note' => $ref,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'cart_json' => json_encode($cartItems),
            'subtotal' => $subtotal,
            'total_amount' => $totalAmount,
        ]);
        return ['success' => true, 'held_id' => (int) $db->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => 'Could not hold sale: ' . $e->getMessage()];
    }
}

function get_held_sales(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT h.*, c.name AS customer_name, u.name AS user_name
        FROM held_sales h
        LEFT JOIN customers c ON c.id = h.customer_id AND c.business_id = :bid_c
        LEFT JOIN users u ON u.id = h.user_id
        WHERE h.business_id = :bid
        ORDER BY h.id DESC
    ');
    $stmt->execute(['bid' => $bid, 'bid_c' => $bid]);
    return $stmt->fetchAll();
}

function get_held_sale_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('SELECT * FROM held_sales WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute(['id' => $id, 'bid' => $bid]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function delete_held_sale(int $id, ?int $businessId = null): bool {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('DELETE FROM held_sales WHERE id = :id AND business_id = :bid');
    return $stmt->execute(['id' => $id, 'bid' => $bid]);
}

/* =========================================================================
   8. ORDERS LISTING & SALES STATS
   ========================================================================= */

function get_orders(string $search = '', string $status = '', string $dateFrom = '', string $dateTo = '', int $limit = 50, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT o.*, inv.id AS invoice_id, inv.invoice_number, c.name AS customer_name, c.phone AS customer_phone, u.name AS cashier_name,
               (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS items_count
        FROM orders o
        LEFT JOIN invoices inv ON inv.order_id = o.id AND inv.business_id = :bid_inv
        LEFT JOIN customers c ON c.id = o.customer_id AND c.business_id = :bid_c
        LEFT JOIN users u ON u.id = o.user_id
        WHERE o.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_inv' => $bid,
        'bid_c' => $bid,
    ];

    if ($search !== '') {
        $sql .= ' AND (o.order_number LIKE :search1 OR inv.invoice_number LIKE :search2 OR c.name LIKE :search3 OR c.phone LIKE :search4)';
        $params['search1'] = '%' . $search . '%';
        $params['search2'] = '%' . $search . '%';
        $params['search3'] = '%' . $search . '%';
        $params['search4'] = '%' . $search . '%';
    }

    if ($status !== '' && in_array($status, ['completed', 'hold', 'cancelled'], true)) {
        $sql .= ' AND o.order_status = :status';
        $params['status'] = $status;
    }

    if ($dateFrom !== '') {
        $sql .= ' AND DATE(o.created_at) >= :date_from';
        $params['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $sql .= ' AND DATE(o.created_at) <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $sql .= ' ORDER BY o.id DESC LIMIT ' . max(1, $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_order_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT o.*, inv.id AS invoice_id, inv.invoice_number, c.name AS customer_name, c.phone AS customer_phone,
               c.email AS customer_email, c.address AS customer_address, u.name AS cashier_name
        FROM orders o
        LEFT JOIN invoices inv ON inv.order_id = o.id AND inv.business_id = :bid_inv
        LEFT JOIN customers c ON c.id = o.customer_id AND c.business_id = :bid_c
        LEFT JOIN users u ON u.id = o.user_id
        WHERE o.id = :id AND o.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid, 'bid_inv' => $bid, 'bid_c' => $bid]);
    $order = $stmt->fetch();

    if (!$order) return null;

    $stmtItems = $db->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC');
    $stmtItems->execute(['order_id' => $id]);
    $order['items'] = $stmtItems->fetchAll();

    return $order;
}

function get_sales_stats(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    // Active/completed sales stats (exclude cancelled) scoped to current business
    $stmtAll = $db->prepare('
        SELECT 
            COALESCE(SUM(total_amount), 0) AS total_revenue,
            COUNT(*) AS total_orders,
            COUNT(DISTINCT customer_id) AS total_customers
        FROM orders
        WHERE order_status = "completed" AND business_id = :bid
    ');
    $stmtAll->execute(['bid' => $bid]);
    $all = $stmtAll->fetch();

    // Today's active stats scoped to current business
    $stmtToday = $db->prepare('
        SELECT 
            COALESCE(SUM(total_amount), 0) AS today_revenue,
            COUNT(*) AS today_orders
        FROM orders
        WHERE order_status = "completed" AND DATE(created_at) = CURDATE() AND business_id = :bid
    ');
    $stmtToday->execute(['bid' => $bid]);
    $today = $stmtToday->fetch();

    // Invoice counts scoped to current business
    $stmtInvoices = $db->prepare('
        SELECT 
            COUNT(*) AS total_invoices,
            SUM(CASE WHEN invoice_status = "paid" THEN 1 ELSE 0 END) AS paid_invoices,
            SUM(CASE WHEN invoice_status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_invoices
        FROM invoices
        WHERE business_id = :bid
    ');
    $stmtInvoices->execute(['bid' => $bid]);
    $invStats = $stmtInvoices->fetch();

    // Return stats scoped to current business
    $stmtReturns = $db->prepare('
        SELECT 
            COUNT(*) AS total_returns,
            COALESCE(SUM(refund_amount), 0) AS total_refunded
        FROM returns
        WHERE status = "completed" AND business_id = :bid
    ');
    $stmtReturns->execute(['bid' => $bid]);
    $retStats = $stmtReturns->fetch();

    return [
        'total_revenue' => (float) ($all['total_revenue'] ?? 0.00),
        'total_orders' => (int) ($all['total_orders'] ?? 0),
        'total_customers' => (int) ($all['total_customers'] ?? 0),
        'today_revenue' => (float) ($today['today_revenue'] ?? 0.00),
        'today_orders' => (int) ($today['today_orders'] ?? 0),
        'total_invoices' => (int) ($invStats['total_invoices'] ?? 0),
        'paid_invoices' => (int) ($invStats['paid_invoices'] ?? 0),
        'cancelled_invoices' => (int) ($invStats['cancelled_invoices'] ?? 0),
        'total_returns' => (int) ($retStats['total_returns'] ?? 0),
        'total_refunded' => (float) ($retStats['total_refunded'] ?? 0.00),
    ];
}

/* =========================================================================
   9. RETURNS & REFUNDS SERVICES
   ========================================================================= */

/**
 * Retrieves order items and calculates the remaining returnable quantity for each.
 */
function get_returnable_order_items(int $orderId): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT oi.*, 
               COALESCE((
                   SELECT SUM(ri.quantity) 
                   FROM return_items ri 
                   JOIN returns r ON r.id = ri.return_id 
                   WHERE ri.order_item_id = oi.id AND r.status = "completed"
               ), 0) AS returned_qty
        FROM order_items oi
        WHERE oi.order_id = :order_id
        ORDER BY oi.id ASC
    ');
    $stmt->execute(['order_id' => $orderId]);
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        $purchasedQty = (int) $item['quantity'];
        $returnedQty = (int) $item['returned_qty'];
        $item['returnable_quantity'] = max(0, $purchasedQty - $returnedQty);
        $item['unit_tax'] = (float) $item['tax_amount'] / max(1, $purchasedQty);
        $item['effective_unit_price'] = (float) $item['line_total'] / max(1, $purchasedQty);
    }
    unset($item);

    return $items;
}

/**
 * Processes an itemized return, calculates refund, restores product inventory,
 * and records inventory movements atomically.
 */
function process_pos_return(
    int $orderId,
    array $returnItems, // Array of ['order_item_id' => int, 'quantity' => int]
    string $refundMethod = 'cash',
    string $reason = 'Customer Return',
    string $notes = '',
    ?int $userId = null,
    ?int $businessId = null
): array {
    if (empty($returnItems)) {
        return ['success' => false, 'error' => 'No items selected for return.'];
    }

    $db = get_db();
    $bid = $businessId ?: current_business_id();

    try {
        $db->beginTransaction();

        $order = get_order_by_id($orderId, $bid);
        if (!$order) {
            throw new Exception('Order #' . $orderId . ' does not exist.');
        }

        if ($order['order_status'] === 'cancelled') {
            throw new Exception('Cannot process returns on a cancelled order.');
        }

        $returnableItems = get_returnable_order_items($orderId);
        $itemLookup = [];
        foreach ($returnableItems as $rit) {
            $itemLookup[(int)$rit['id']] = $rit;
        }

        $totalRefund = 0.00;
        $processedReturns = [];

        // Validate quantities and calculate line refunds
        foreach ($returnItems as $req) {
            $orderItemId = (int) ($req['order_item_id'] ?? 0);
            $qty = (int) ($req['quantity'] ?? 0);

            if ($qty <= 0) continue;

            if (!isset($itemLookup[$orderItemId])) {
                throw new Exception('Invalid order item ID: ' . $orderItemId);
            }

            $orderItem = $itemLookup[$orderItemId];
            $availableToReturn = (int) $orderItem['returnable_quantity'];

            if ($qty > $availableToReturn) {
                throw new Exception(sprintf(
                    'Cannot return %d units of "%s". Maximum returnable is %d units.',
                    $qty,
                    $orderItem['product_name'],
                    $availableToReturn
                ));
            }

            $effectiveUnitPrice = (float) $orderItem['effective_unit_price'];
            $lineRefund = round($effectiveUnitPrice * $qty, 2);
            $totalRefund += $lineRefund;

            $processedReturns[] = [
                'order_item_id' => $orderItemId,
                'product_id' => (int) $orderItem['product_id'],
                'product_name' => $orderItem['product_name'],
                'product_sku' => $orderItem['product_sku'],
                'unit_price' => (float) $orderItem['unit_price'],
                'quantity' => $qty,
                'refund_amount' => $lineRefund,
            ];
        }

        if (empty($processedReturns)) {
            throw new Exception('Please select at least 1 unit to return.');
        }

        // Generate Return Number
        $returnNumber = generate_next_return_number($bid, $db);

        // Find linked invoice ID
        $stmtInv = $db->prepare('SELECT id FROM invoices WHERE order_id = :order_id AND business_id = :bid LIMIT 1');
        $stmtInv->execute(['order_id' => $orderId, 'bid' => $bid]);
        $invoiceId = $stmtInv->fetchColumn() ?: null;

        // Insert into `returns`
        $stmtRet = $db->prepare('
            INSERT INTO returns (
                business_id, return_number, order_id, invoice_id, customer_id, user_id,
                refund_amount, refund_method, reason, notes, status, created_at, updated_at
            ) VALUES (
                :biz_id, :return_number, :order_id, :invoice_id, :customer_id, :user_id,
                :refund_amount, :refund_method, :reason, :notes, "completed", NOW(), NOW()
            )
        ');
        $stmtRet->execute([
            'biz_id' => $bid,
            'return_number' => $returnNumber,
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'customer_id' => $order['customer_id'],
            'user_id' => $userId,
            'refund_amount' => $totalRefund,
            'refund_method' => $refundMethod ?: 'cash',
            'reason' => trim($reason) ?: 'Customer Return',
            'notes' => trim($notes) ?: null,
        ]);
        $returnId = (int) $db->lastInsertId();

        // Insert return items & restore stock
        $stmtRetItem = $db->prepare('
            INSERT INTO return_items (
                return_id, order_item_id, product_id, product_name, product_sku,
                unit_price, quantity, refund_amount, created_at
            ) VALUES (
                :return_id, :order_item_id, :product_id, :product_name, :product_sku,
                :unit_price, :quantity, :refund_amount, NOW()
            )
        ');

        $stmtStockInc = $db->prepare('
            UPDATE products
            SET stock_quantity = stock_quantity + :qty, updated_at = NOW()
            WHERE id = :id AND business_id = :bid
        ');

        $stmtMoveLog = $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :biz_id, :product_id, :user_id, "in", :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
            )
        ');

        foreach ($processedReturns as $pRet) {
            $stmtRetItem->execute([
                'return_id' => $returnId,
                'order_item_id' => $pRet['order_item_id'],
                'product_id' => $pRet['product_id'],
                'product_name' => $pRet['product_name'],
                'product_sku' => $pRet['product_sku'],
                'unit_price' => $pRet['unit_price'],
                'quantity' => $pRet['quantity'],
                'refund_amount' => $pRet['refund_amount'],
            ]);

            if ($pRet['product_id'] > 0) {
                // Get current stock
                $stmtCur = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :bid FOR UPDATE');
                $stmtCur->execute(['id' => $pRet['product_id'], 'bid' => $bid]);
                $currStock = (int) $stmtCur->fetchColumn();

                // Increment stock
                $stmtStockInc->execute([
                    'qty' => $pRet['quantity'],
                    'id' => $pRet['product_id'],
                    'bid' => $bid,
                ]);

                // Record inventory movement
                $stmtMoveLog->execute([
                    'biz_id' => $bid,
                    'product_id' => $pRet['product_id'],
                    'user_id' => $userId,
                    'quantity_change' => $pRet['quantity'],
                    'quantity_before' => $currStock,
                    'quantity_after' => $currStock + $pRet['quantity'],
                    'reason' => 'Customer Return #' . $returnNumber . ' (Order #' . $order['order_number'] . ')',
                ]);
            }
        }

        $db->commit();

        return [
            'success' => true,
            'return_id' => $returnId,
            'return_number' => $returnNumber,
            'refund_amount' => $totalRefund,
            'order_number' => $order['order_number'],
            'items_count' => count($processedReturns),
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function get_returns(string $search = '', string $dateFrom = '', string $dateTo = '', int $limit = 50, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $sql = '
        SELECT r.*, o.order_number, inv.invoice_number, c.name AS customer_name, c.phone AS customer_phone,
               u.name AS cashier_name,
               (SELECT COUNT(*) FROM return_items ri WHERE ri.return_id = r.id) AS items_count
        FROM returns r
        JOIN orders o ON o.id = r.order_id AND o.business_id = :bid_o
        LEFT JOIN invoices inv ON inv.id = r.invoice_id AND inv.business_id = :bid_inv
        LEFT JOIN customers c ON c.id = r.customer_id AND c.business_id = :bid_c
        LEFT JOIN users u ON u.id = r.user_id
        WHERE r.business_id = :bid
    ';
    $params = [
        'bid' => $bid,
        'bid_o' => $bid,
        'bid_inv' => $bid,
        'bid_c' => $bid,
    ];

    if ($search !== '') {
        $sql .= ' AND (r.return_number LIKE :search1 OR o.order_number LIKE :search2 OR c.name LIKE :search3 OR c.phone LIKE :search4)';
        $params['search1'] = '%' . $search . '%';
        $params['search2'] = '%' . $search . '%';
        $params['search3'] = '%' . $search . '%';
        $params['search4'] = '%' . $search . '%';
    }

    if ($dateFrom !== '') {
        $sql .= ' AND DATE(r.created_at) >= :date_from';
        $params['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $sql .= ' AND DATE(r.created_at) <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $sql .= ' ORDER BY r.id DESC LIMIT ' . max(1, $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_return_by_id(int $id, ?int $businessId = null): ?array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT r.*, o.order_number, inv.invoice_number, c.name AS customer_name, c.phone AS customer_phone,
               c.email AS customer_email, u.name AS cashier_name
        FROM returns r
        JOIN orders o ON o.id = r.order_id AND o.business_id = :bid_o
        LEFT JOIN invoices inv ON inv.id = r.invoice_id AND inv.business_id = :bid_inv
        LEFT JOIN customers c ON c.id = r.customer_id AND c.business_id = :bid_c
        LEFT JOIN users u ON u.id = r.user_id
        WHERE r.id = :id AND r.business_id = :bid
        LIMIT 1
    ');
    $stmt->execute(['id' => $id, 'bid' => $bid, 'bid_o' => $bid, 'bid_inv' => $bid, 'bid_c' => $bid]);
    $ret = $stmt->fetch();

    if (!$ret) return null;

    $stmtItems = $db->prepare('SELECT * FROM return_items WHERE return_id = :return_id ORDER BY id ASC');
    $stmtItems->execute(['return_id' => $id]);
    $ret['items'] = $stmtItems->fetchAll();

    return $ret;
}

/* =========================================================================
   10. DIRECT CUSTOM INVOICE CREATION SERVICE (ZOHO BOOKS PARITY)
   ========================================================================= */

/**
 * Creates an itemized custom Tax Invoice directly from invoice-create screen.
 * Handles customer linking, order generation, tax calculation, atomic inventory deductions,
 * and ledger auditing.
 */
function create_custom_invoice(array $data, ?int $userId, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : 1;
    $invoiceDate = !empty($data['invoice_date']) ? $data['invoice_date'] : date('Y-m-d');
    $paymentMethod = !empty($data['payment_method']) ? (string)$data['payment_method'] : 'cash';
    $invoiceStatus = !empty($data['invoice_status']) && in_array($data['invoice_status'], ['paid', 'draft', 'cancelled'], true) ? $data['invoice_status'] : 'paid';
    $paymentStatus = ($invoiceStatus === 'paid') ? 'paid' : 'unpaid';
    $notes = trim((string)($data['notes'] ?? ''));
    $orderNumberRef = trim((string)($data['order_number'] ?? ''));
    $subject = trim((string)($data['subject'] ?? ''));
    $items = $data['items'] ?? [];

    if (empty($items)) {
        return ['success' => false, 'error' => 'Please add at least one line item to the invoice.'];
    }

    try {
        $db->beginTransaction();

        $subtotal = 0.00;
        $totalTax = 0.00;
        $totalDiscount = 0.00;
        $processedItems = [];

        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $qty = max(1, (int)($item['quantity'] ?? 1));
            $rate = max(0.0, (float)($item['unit_price'] ?? 0));
            $discountVal = max(0.0, (float)($item['discount'] ?? 0));
            $discountType = ($item['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
            $taxPercent = isset($item['tax_percent']) ? max(0.0, (float)$item['tax_percent']) : 18.0;

            if ($productId <= 0) {
                continue; // Skip blank line rows
            }

            $stmtProd = $db->prepare('SELECT id, name, sku, barcode, cost_price, selling_price, tax_percent, stock_quantity, status, hsn_code FROM products WHERE id = :id AND business_id = :bid FOR UPDATE');
            $stmtProd->execute(['id' => $productId, 'bid' => $bid]);
            $prod = $stmtProd->fetch();

            if (!$prod) {
                throw new Exception("Product ID #{$productId} not found.");
            }

            $prodName = $prod['name'];
            $prodSku = $prod['sku'];
            $hsnCode = $prod['hsn_code'] ?? '';
            if ($rate <= 0) {
                $rate = (float)$prod['selling_price'];
            }

            $lineBase = $rate * $qty;
            $lineDiscount = ($discountType === 'percent') ? ($lineBase * ($discountVal / 100.0)) : min($lineBase, $discountVal);
            $lineTaxable = max(0.0, $lineBase - $lineDiscount);
            $lineTax = $lineTaxable * ($taxPercent / 100.0);
            $lineTotal = $lineTaxable + $lineTax;

            $subtotal += $lineBase;
            $totalDiscount += $lineDiscount;
            $totalTax += $lineTax;

            $processedItems[] = [
                'product_id' => $productId,
                'variant_id' => null,
                'product_name' => $prodName,
                'product_sku' => $prodSku,
                'hsn_code' => $hsnCode,
                'unit_price' => $rate,
                'quantity' => $qty,
                'tax_percent' => $taxPercent,
                'tax_amount' => $lineTax,
                'discount_amount' => $lineDiscount,
                'line_total' => $lineTotal,
                'stock_before' => (int)$prod['stock_quantity'],
                'stock_after' => max(0, (int)$prod['stock_quantity'] - $qty),
            ];
        }

        if (empty($processedItems)) {
            throw new Exception('Please select at least one valid product from your inventory.');
        }

        $taxableAmount = max(0.0, $subtotal - $totalDiscount);
        $grandTotal = max(0.0, round($taxableAmount + $totalTax, 2));
        $cgstAmount = round($totalTax / 2, 2);
        $sgstAmount = round($totalTax - $cgstAmount, 2);
        $igstAmount = 0.00;

        // Order generation
        $orderNumber = $orderNumberRef ?: ('ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)));

        $stmtOrder = $db->prepare('
            INSERT INTO orders (
                business_id, order_number, outlet_id, customer_id, user_id, subtotal, discount_amount, discount_type,
                tax_amount, total_amount, payment_method, payment_status, order_status, fulfillment_status,
                notes, created_at, updated_at
            ) VALUES (
                :biz_id, :order_number, 1, :customer_id, :user_id, :subtotal, :discount_amount, "fixed",
                :tax_amount, :total_amount, :payment_method, :payment_status, "completed", "delivered",
                :notes, NOW(), NOW()
            )
        ');
        $stmtOrder->execute([
            'biz_id' => $bid,
            'order_number' => $orderNumber,
            'customer_id' => $customerId,
            'user_id' => $userId ?: 1,
            'subtotal' => $subtotal,
            'discount_amount' => $totalDiscount,
            'tax_amount' => $totalTax,
            'total_amount' => $grandTotal,
            'payment_method' => $paymentMethod,
            'payment_status' => ($invoiceStatus === 'paid') ? 'paid' : 'pending',
            'notes' => $subject ? ($subject . ($notes ? "\n" . $notes : '')) : $notes,
        ]);
        $orderId = (int)$db->lastInsertId();

        // Insert order items & Deduct Stock
        $stmtItem = $db->prepare('
            INSERT INTO order_items (
                order_id, product_id, variant_id, product_name, product_sku, hsn_code, unit_price,
                quantity, tax_percent, tax_amount, discount_amount, line_total, created_at
            ) VALUES (
                :order_id, :product_id, :variant_id, :product_name, :product_sku, :hsn_code, :unit_price,
                :quantity, :tax_percent, :tax_amount, :discount_amount, :line_total, NOW()
            )
        ');

        $stmtStockDec = $db->prepare('UPDATE products SET stock_quantity = stock_quantity - :qty, updated_at = NOW() WHERE id = :id AND business_id = :bid');
        $stmtMoveLog = $db->prepare('
            INSERT INTO inventory_movements (
                business_id, product_id, user_id, movement_type, quantity_change, quantity_before, quantity_after, reason, created_at
            ) VALUES (
                :biz_id, :product_id, :user_id, "out", :quantity_change, :quantity_before, :quantity_after, :reason, NOW()
            )
        ');

        foreach ($processedItems as $pItem) {
            $stmtItem->execute([
                'order_id' => $orderId,
                'product_id' => $pItem['product_id'],
                'variant_id' => $pItem['variant_id'],
                'product_name' => $pItem['product_name'],
                'product_sku' => $pItem['product_sku'],
                'hsn_code' => $pItem['hsn_code'],
                'unit_price' => $pItem['unit_price'],
                'quantity' => $pItem['quantity'],
                'tax_percent' => $pItem['tax_percent'],
                'tax_amount' => $pItem['tax_amount'],
                'discount_amount' => $pItem['discount_amount'],
                'line_total' => $pItem['line_total'],
            ]);

            // If not draft, deduct stock
            if ($invoiceStatus !== 'draft') {
                $stmtStockDec->execute([
                    'qty' => $pItem['quantity'],
                    'id' => $pItem['product_id'],
                    'bid' => $bid,
                ]);
                $stmtMoveLog->execute([
                    'biz_id' => $bid,
                    'product_id' => $pItem['product_id'],
                    'user_id' => $userId ?: 1,
                    'quantity_change' => -$pItem['quantity'],
                    'quantity_before' => $pItem['stock_before'],
                    'quantity_after' => $pItem['stock_after'],
                    'reason' => "Custom Invoice #{$orderNumber}",
                ]);
            }
        }

        // Auto-generate invoice number if not specified
        $invNum = trim((string)($data['invoice_number'] ?? ''));
        if ($invNum === '') {
            $invNum = generate_next_invoice_number($bid, $db);
        }

        $amountPaid = ($invoiceStatus === 'paid') ? $grandTotal : (float)($data['amount_paid'] ?? 0.0);

        $stmtInsert = $db->prepare('
            INSERT INTO invoices (
                business_id, invoice_number, order_id, customer_id, user_id, invoice_date, subtotal,
                discount_amount, discount_type, taxable_amount, cgst_amount, sgst_amount, igst_amount,
                tax_amount, total_amount, amount_paid, change_amount, payment_method, payment_status,
                invoice_status, notes, created_at, updated_at
            ) VALUES (
                :biz_id, :invoice_number, :order_id, :customer_id, :user_id, :invoice_date, :subtotal,
                :discount_amount, "fixed", :taxable_amount, :cgst_amount, :sgst_amount, :igst_amount,
                :tax_amount, :total_amount, :amount_paid, 0.00, :payment_method, :payment_status,
                :invoice_status, :notes, NOW(), NOW()
            )
        ');
        $stmtInsert->execute([
            'biz_id' => $bid,
            'invoice_number' => $invNum,
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'user_id' => $userId ?: 1,
            'invoice_date' => $invoiceDate . ' ' . date('H:i:s'),
            'subtotal' => $subtotal,
            'discount_amount' => $totalDiscount,
            'taxable_amount' => $taxableAmount,
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'igst_amount' => $igstAmount,
            'tax_amount' => $totalTax,
            'total_amount' => $grandTotal,
            'amount_paid' => $amountPaid,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'invoice_status' => $invoiceStatus,
            'notes' => $subject ? ($subject . ($notes ? "\n" . $notes : '')) : $notes,
        ]);
        $invoiceId = (int)$db->lastInsertId();

        $db->commit();

        return [
            'success' => true,
            'invoice_id' => $invoiceId,
            'invoice_number' => $invNum,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total_amount' => $grandTotal,
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}



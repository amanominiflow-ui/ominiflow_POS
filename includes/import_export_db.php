<?php
/**
 * Safe CSV Import & Export Engine for OminiFlow POS (Zoho POS Parity)
 * Supports dynamic header mapping, update on existing SKU, UTF-8 BOM export, and sample templates.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/products_db.php';

/**
 * Universal CSV Export Dispatcher
 */
function export_data_to_csv(string $entityType, ?int $businessId = null, array $filters = []): void {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $timestamp = date('Ymd_His');
    $filename = "{$entityType}_export_{$timestamp}.csv";

    // Clean output buffer to avoid corrupted CSV
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    if (!$output) {
        exit('Failed to open output stream.');
    }

    // Write UTF-8 BOM for Microsoft Excel / CSV Viewers compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($entityType === 'products') {
        fputcsv($output, ['ID', 'Name', 'SKU', 'Barcode', 'Category', 'Cost Price', 'Selling Price', 'Tax Percent', 'Stock Quantity', 'Status', 'Product Type']);
        
        $sql = '
            SELECT p.id, p.name, p.sku, COALESCE(p.barcode, "") as barcode,
                   COALESCE(c.name, "") as category_name,
                   p.cost_price, p.selling_price, p.tax_percent, p.stock_quantity, p.status, p.product_type
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.business_id = :bid
            ORDER BY p.id ASC
        ';
        $stmt = $db->prepare($sql);
        $stmt->execute(['bid' => $bid]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['name'],
                $row['sku'],
                $row['barcode'],
                $row['category_name'],
                number_format((float)$row['cost_price'], 2, '.', ''),
                number_format((float)$row['selling_price'], 2, '.', ''),
                number_format((float)$row['tax_percent'], 2, '.', ''),
                $row['stock_quantity'],
                ucfirst($row['status']),
                ucfirst($row['product_type'])
            ]);
        }
    } elseif ($entityType === 'customers') {
        fputcsv($output, ['ID', 'Name', 'Phone', 'Email', 'Address', 'Loyalty Points', 'Credit Limit', 'Outstanding Balance']);
        
        $stmt = $db->prepare('
            SELECT id, name, phone, COALESCE(email, "") as email, COALESCE(address, "") as address,
                   COALESCE(loyalty_points_balance, 0) as loyalty_points_balance,
                   COALESCE(credit_limit, 0) as credit_limit,
                   COALESCE(outstanding_receivable, 0) as outstanding_receivable
            FROM customers
            WHERE business_id = :bid
            ORDER BY id ASC
        ');
        $stmt->execute(['bid' => $bid]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['name'],
                $row['phone'],
                $row['email'],
                $row['address'],
                $row['loyalty_points_balance'],
                number_format((float)$row['credit_limit'], 2, '.', ''),
                number_format((float)$row['outstanding_receivable'], 2, '.', '')
            ]);
        }
    } elseif ($entityType === 'orders') {
        fputcsv($output, ['Order Number', 'Date', 'Customer Name', 'Subtotal', 'Discount', 'Tax', 'Grand Total', 'Payment Method', 'Payment Status', 'Order Status']);
        
        $stmt = $db->prepare('
            SELECT o.order_number, o.created_at, COALESCE(c.name, "Walk-in") as customer_name,
               o.subtotal, o.discount_amount, o.tax_amount, o.total_amount,
               o.payment_method, o.payment_status, o.order_status
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            WHERE o.business_id = :bid
            ORDER BY o.id DESC
        ');
        $stmt->execute(['bid' => $bid]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['order_number'],
                date('Y-m-d H:i:s', strtotime($row['created_at'])),
                $row['customer_name'],
                number_format((float)$row['subtotal'], 2, '.', ''),
                number_format((float)$row['discount_amount'], 2, '.', ''),
                number_format((float)$row['tax_amount'], 2, '.', ''),
                number_format((float)$row['total_amount'], 2, '.', ''),
                strtoupper($row['payment_method']),
                ucfirst($row['payment_status']),
                ucfirst($row['order_status'])
            ]);
        }
    } elseif ($entityType === 'invoices') {
        fputcsv($output, [
            'Invoice Number',
            'Date & Time',
            'Order Number',
            'Customer Name',
            'Customer Phone',
            'Items Count',
            'Subtotal',
            'Discount Amount',
            'Taxable Amount',
            'CGST',
            'SGST',
            'Total Tax',
            'Grand Total',
            'Amount Paid',
            'Payment Method',
            'Payment Status',
            'Invoice Status',
            'Notes'
        ]);

        $where = ['i.business_id = :bid'];
        $params = ['bid' => $bid];

        if (!empty($filters['search'])) {
            $s = '%' . trim((string)$filters['search']) . '%';
            $where[] = '(i.invoice_number LIKE :s1 OR COALESCE(o.order_number, "") LIKE :s2 OR COALESCE(c.name, "") LIKE :s3 OR COALESCE(c.phone, "") LIKE :s4)';
            $params['s1'] = $s;
            $params['s2'] = $s;
            $params['s3'] = $s;
            $params['s4'] = $s;
        }

        if (!empty($filters['status'])) {
            $st = trim((string)$filters['status']);
            if ($st === 'paid') {
                $where[] = "(i.invoice_status = 'paid' OR i.payment_status = 'paid')";
            } elseif ($st === 'unpaid') {
                $where[] = "(i.invoice_status != 'cancelled' AND (i.invoice_status IN ('draft', 'unpaid') OR i.payment_status IN ('unpaid', 'pending')))";
            } elseif ($st === 'cancelled') {
                $where[] = "i.invoice_status = 'cancelled'";
            }
        }

        if (!empty($filters['date_from'])) {
            $where[] = "DATE(i.invoice_date) >= :dfrom";
            $params['dfrom'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "DATE(i.invoice_date) <= :dto";
            $params['dto'] = $filters['date_to'];
        }

        $whereSql = implode(' AND ', $where);
        
        $stmt = $db->prepare("
            SELECT i.id, i.invoice_number, i.invoice_date,
                   COALESCE(o.order_number, '') as order_number,
                   COALESCE(c.name, 'Walk-in Customer') as customer_name,
                   COALESCE(c.phone, '') as customer_phone,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = i.order_id) as items_count,
                   i.subtotal, i.discount_amount, i.taxable_amount, i.cgst_amount, i.sgst_amount,
                   i.tax_amount, i.total_amount, i.amount_paid, i.payment_method, i.payment_status,
                   i.invoice_status, COALESCE(i.notes, '') as notes
            FROM invoices i
            LEFT JOIN orders o ON i.order_id = o.id
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE {$whereSql}
            ORDER BY i.id DESC
        ");
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['invoice_number'],
                date('Y-m-d H:i:s', strtotime($row['invoice_date'])),
                $row['order_number'],
                $row['customer_name'],
                $row['customer_phone'],
                (int)$row['items_count'],
                number_format((float)$row['subtotal'], 2, '.', ''),
                number_format((float)$row['discount_amount'], 2, '.', ''),
                number_format((float)$row['taxable_amount'], 2, '.', ''),
                number_format((float)$row['cgst_amount'], 2, '.', ''),
                number_format((float)$row['sgst_amount'], 2, '.', ''),
                number_format((float)$row['tax_amount'], 2, '.', ''),
                number_format((float)$row['total_amount'], 2, '.', ''),
                number_format((float)$row['amount_paid'], 2, '.', ''),
                strtoupper($row['payment_method']),
                ucfirst($row['payment_status']),
                ucfirst($row['invoice_status']),
                $row['notes']
            ]);
        }
    }

    fclose($output);
    exit;
}

/**
 * Named Export Wrappers
 */
function export_products_csv(?int $businessId = null): void {
    export_data_to_csv('products', $businessId);
}

function export_customers_csv(?int $businessId = null): void {
    export_data_to_csv('customers', $businessId);
}

function export_orders_csv(?int $businessId = null): void {
    export_data_to_csv('orders', $businessId);
}

function export_invoices_csv(?int $businessId = null, array $filters = []): void {
    export_data_to_csv('invoices', $businessId, $filters);
}

/**
 * Download Sample Product CSV Template
 */
function export_sample_products_template(): void {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = "sample_products_template.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Headers
    fputcsv($output, ['Name', 'SKU', 'Barcode', 'Category', 'Selling Price', 'Cost Price', 'Tax Percent', 'Stock', 'Status']);

    // Example sample rows
    fputcsv($output, ['Cotton Polo T-Shirt', 'TSHIRT-001', '8901234567890', 'Apparel', '799.00', '350.00', '5.00', '50', 'active']);
    fputcsv($output, ['Wireless Optical Mouse', 'TECH-MOU-01', '8901234567891', 'Electronics', '499.00', '220.00', '18.00', '35', 'active']);
    fputcsv($output, ['Organic Green Tea 250g', 'GROC-TEA-01', '8901234567892', 'Groceries', '250.00', '140.00', '0.00', '100', 'active']);

    fclose($output);
    exit;
}

/**
 * Download Sample Invoices CSV Template
 */
function export_sample_invoices_template(): void {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = "sample_invoices_template.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Headers
    fputcsv($output, [
        'Invoice Number',
        'Invoice Date',
        'Customer Name',
        'Customer Phone',
        'Customer Email',
        'Product SKU',
        'Product Name',
        'Quantity',
        'Unit Price',
        'Discount Amount',
        'Tax Percent',
        'Payment Method',
        'Invoice Status',
        'Notes'
    ]);

    // Example sample rows (one multi-item invoice and one single-item invoice)
    fputcsv($output, [
        'INV-2026-0001',
        date('Y-m-d 10:30:00'),
        'Rahul Sharma',
        '9876543210',
        'rahul@example.com',
        'TSHIRT-001',
        'Cotton Polo T-Shirt',
        '2',
        '799.00',
        '0.00',
        '5.00',
        'cash',
        'paid',
        'Counter POS Sale'
    ]);
    fputcsv($output, [
        'INV-2026-0001',
        date('Y-m-d 10:30:00'),
        'Rahul Sharma',
        '9876543210',
        'rahul@example.com',
        'TECH-MOU-01',
        'Wireless Optical Mouse',
        '1',
        '499.00',
        '50.00',
        '18.00',
        'cash',
        'paid',
        'Counter POS Sale'
    ]);
    fputcsv($output, [
        'INV-2026-0002',
        date('Y-m-d 11:15:00'),
        'Walk-in Customer',
        '9123456789',
        '',
        'GROC-TEA-01',
        'Organic Green Tea 250g',
        '3',
        '250.00',
        '0.00',
        '0.00',
        'upi',
        'paid',
        'Express Checkout'
    ]);
    fputcsv($output, [
        'INV-2026-0003',
        date('Y-m-d 12:00:00'),
        'Priya Patel',
        '9988776655',
        'priya@example.com',
        'TSHIRT-001',
        'Cotton Polo T-Shirt',
        '1',
        '799.00',
        '100.00',
        '5.00',
        'card',
        'paid',
        'Store Promo Discount'
    ]);

    fclose($output);
    exit;
}

/**
 * Smart CSV Import with Dynamic Header Detection, Auto-Delimiter Detection, and Auto-Update on Existing SKU
 */
function import_products_from_csv(string $csvFilePath, ?int $userId = null, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
        return ['success' => false, 'error' => 'Uploaded CSV file could not be read.'];
    }

    $rawContent = file_get_contents($csvFilePath);
    if ($rawContent === false || trim($rawContent) === '') {
        return ['success' => false, 'error' => 'Uploaded CSV file is empty.'];
    }

    // Strip UTF-8 BOM if present
    $rawContent = preg_replace('/^\xEF\xBB\xBF/', '', $rawContent);

    // Auto-detect delimiter by checking first non-empty line
    $lines = preg_split('/\r\n|\r|\n/', trim($rawContent));
    if (empty($lines)) {
        return ['success' => false, 'error' => 'No readable lines found in CSV file.'];
    }

    $firstLine = $lines[0];
    $delimiters = [',', ';', "\t", '|'];
    $delimiter = ',';
    $maxCount = 0;
    foreach ($delimiters as $d) {
        $count = substr_count($firstLine, $d);
        if ($count > $maxCount) {
            $maxCount = $count;
            $delimiter = $d;
        }
    }

    // Open file stream
    $handle = fopen($csvFilePath, 'r');
    if (!$handle) {
        return ['success' => false, 'error' => 'Cannot open CSV file stream.'];
    }

    // Read header row
    $rawHeader = fgetcsv($handle, 0, $delimiter);
    if (!$rawHeader || empty(array_filter($rawHeader, fn($v) => trim((string)$v) !== ''))) {
        fclose($handle);
        return ['success' => false, 'error' => 'Empty CSV header row or unreadable format.'];
    }

    // Clean BOM / non-printable characters from first header column
    if (isset($rawHeader[0])) {
        $rawHeader[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', (string)$rawHeader[0]);
    }

    // Build header column map
    $colMap = [];
    foreach ($rawHeader as $index => $colName) {
        $norm = strtolower(trim((string)$colName));
        $norm = preg_replace('/[^a-z0-9_]/', '', str_replace([' ', '-'], '_', $norm));

        if (in_array($norm, ['name', 'product_name', 'item_name', 'title', 'product', 'item'], true)) {
            $colMap['name'] = $index;
        } elseif (in_array($norm, ['sku', 'product_sku', 'item_code', 'code', 'item_sku', 'item_no'], true)) {
            $colMap['sku'] = $index;
        } elseif (in_array($norm, ['barcode', 'upc', 'ean', 'isbn', 'barcode_number', 'barcode_no'], true)) {
            $colMap['barcode'] = $index;
        } elseif (in_array($norm, ['category', 'category_name', 'cat_name', 'group'], true)) {
            $colMap['category'] = $index;
        } elseif (in_array($norm, ['selling_price', 'price', 'mrp', 'retail_price', 'rate', 'unit_price', 'sale_price'], true)) {
            $colMap['selling_price'] = $index;
        } elseif (in_array($norm, ['cost_price', 'cost', 'purchase_price', 'buy_price', 'buying_price'], true)) {
            $colMap['cost_price'] = $index;
        } elseif (in_array($norm, ['tax_percent', 'tax', 'gst', 'tax_rate', 'vat', 'tax_pct'], true)) {
            $colMap['tax_percent'] = $index;
        } elseif (in_array($norm, ['stock_quantity', 'stock', 'quantity', 'qty', 'initial_stock', 'opening_stock'], true)) {
            $colMap['stock'] = $index;
        } elseif (in_array($norm, ['status', 'state', 'active'], true)) {
            $colMap['status'] = $index;
        } elseif (in_array($norm, ['id', 'product_id', 'item_id'], true)) {
            $colMap['id'] = $index;
        }
    }

    // Fallback if header keywords not matched
    if (!isset($colMap['name'])) {
        $colMap = [
            'name' => 0,
            'sku' => 1,
            'selling_price' => 2,
            'cost_price' => 3,
            'tax_percent' => 4,
            'stock' => 5,
        ];
    }

    // Cache existing categories for fast lookups
    $catMap = [];
    try {
        $stmtCats = $db->prepare('SELECT id, LOWER(TRIM(name)) as cat_name FROM categories WHERE business_id = :bid');
        $stmtCats->execute(['bid' => $bid]);
        while ($c = $stmtCats->fetch()) {
            $catMap[$c['cat_name']] = (int)$c['id'];
        }
    } catch (Exception $e) {}

    $imported = 0;
    $errors = [];
    $rowNum = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNum++;
        if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
            continue;
        }

        $name = isset($colMap['name'], $row[$colMap['name']]) ? trim((string)$row[$colMap['name']]) : '';
        if ($name === '') {
            $errors[] = "Row {$rowNum}: Product Name is required.";
            continue;
        }

        $sku = isset($colMap['sku'], $row[$colMap['sku']]) ? strtoupper(trim((string)$row[$colMap['sku']])) : '';
        $barcode = isset($colMap['barcode'], $row[$colMap['barcode']]) ? trim((string)$row[$colMap['barcode']]) : null;
        $sellingPrice = isset($colMap['selling_price'], $row[$colMap['selling_price']]) ? (float)preg_replace('/[^0-9.]/', '', (string)$row[$colMap['selling_price']]) : 0.00;
        $costPrice = isset($colMap['cost_price'], $row[$colMap['cost_price']]) ? (float)preg_replace('/[^0-9.]/', '', (string)$row[$colMap['cost_price']]) : 0.00;
        $taxPercent = isset($colMap['tax_percent'], $row[$colMap['tax_percent']]) ? (float)preg_replace('/[^0-9.]/', '', (string)$row[$colMap['tax_percent']]) : 0.00;
        $stock = isset($colMap['stock'], $row[$colMap['stock']]) ? max(0, (int)preg_replace('/[^0-9]/', '', (string)$row[$colMap['stock']])) : 0;
        $status = 'active';
        if (isset($colMap['status'], $row[$colMap['status']])) {
            $stLower = strtolower(trim((string)$row[$colMap['status']]));
            if (in_array($stLower, ['inactive', 'draft', 'disabled', '0', 'false', 'no'], true)) {
                $status = 'inactive';
            }
        }

        // Category matching / auto-creation
        $categoryId = null;
        if (isset($colMap['category'], $row[$colMap['category']])) {
            $catName = trim((string)$row[$colMap['category']]);
            if ($catName !== '') {
                $catKey = strtolower($catName);
                if (isset($catMap[$catKey])) {
                    $categoryId = $catMap[$catKey];
                } else {
                    // Auto create category
                    try {
                        $catCode = 'CAT-' . strtoupper(substr(uniqid(), -5));
                        $stmtInsCat = $db->prepare('INSERT INTO categories (business_id, name, code, status, created_at, updated_at) VALUES (:bid, :name, :code, "active", NOW(), NOW())');
                        $stmtInsCat->execute(['bid' => $bid, 'name' => $catName, 'code' => $catCode]);
                        $newCatId = (int)$db->lastInsertId();
                        $catMap[$catKey] = $newCatId;
                        $categoryId = $newCatId;
                    } catch (Exception $e) {}
                }
            }
        }

        // Check if existing product by ID or SKU to perform update instead of failing
        $existingId = null;
        if (isset($colMap['id'], $row[$colMap['id']]) && (int)$row[$colMap['id']] > 0) {
            $checkId = (int)$row[$colMap['id']];
            $stmtCheck = $db->prepare('SELECT id FROM products WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmtCheck->execute(['id' => $checkId, 'bid' => $bid]);
            if ($stmtCheck->fetchColumn()) {
                $existingId = $checkId;
            }
        }

        if ($existingId === null && $sku !== '') {
            $stmtSku = $db->prepare('SELECT id FROM products WHERE sku = :sku AND business_id = :bid LIMIT 1');
            $stmtSku->execute(['sku' => $sku, 'bid' => $bid]);
            $foundId = $stmtSku->fetchColumn();
            if ($foundId) {
                $existingId = (int)$foundId;
            }
        }

        if ($sku === '' && $existingId === null) {
            $sku = 'SKU-' . strtoupper(substr(uniqid(), -6));
        }

        $prodData = [
            'name' => $name,
            'sku' => $sku,
            'barcode' => $barcode ?: null,
            'category_id' => $categoryId,
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'tax_percent' => $taxPercent,
            'initial_stock' => $stock,
            'status' => $status,
            'product_type' => 'simple',
            'item_kind' => 'goods',
        ];

        $res = save_product($prodData, null, $existingId, $userId, $bid);

        if ($res['success']) {
            $imported++;
        } else {
            $errList = is_array($res['errors']) ? implode(', ', $res['errors']) : 'Save failed';
            $errors[] = "Row {$rowNum} ('{$name}'): {$errList}";
        }
    }

    fclose($handle);
    return ['success' => true, 'imported_count' => $imported, 'errors' => $errors];
}

/**
 * Smart CSV Import for Invoices & Billing Records
 * Supports single-line and multi-line invoices, auto-creation of customers, product matching, and inventory sync.
 */
function import_invoices_from_csv(string $csvFilePath, ?int $userId = null, ?int $businessId = null, array $options = []): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $uid = $userId ?: (current_user()['id'] ?? 1);

    if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
        return ['success' => false, 'error' => 'Uploaded CSV file could not be read.'];
    }

    $rawContent = file_get_contents($csvFilePath);
    if ($rawContent === false || trim($rawContent) === '') {
        return ['success' => false, 'error' => 'Uploaded CSV file is empty.'];
    }

    // Strip UTF-8 BOM if present
    $rawContent = preg_replace('/^\xEF\xBB\xBF/', '', $rawContent);

    // Auto-detect delimiter by checking first non-empty line
    $lines = preg_split('/\r\n|\r|\n/', trim($rawContent));
    if (empty($lines)) {
        return ['success' => false, 'error' => 'No readable lines found in CSV file.'];
    }

    $firstLine = $lines[0];
    $delimiters = [',', ';', "\t", '|'];
    $delimiter = ',';
    $maxCount = 0;
    foreach ($delimiters as $d) {
        $count = substr_count($firstLine, $d);
        if ($count > $maxCount) {
            $maxCount = $count;
            $delimiter = $d;
        }
    }

    // Open file stream
    $handle = fopen($csvFilePath, 'r');
    if (!$handle) {
        return ['success' => false, 'error' => 'Cannot open CSV file stream.'];
    }

    // Read header row
    $rawHeader = fgetcsv($handle, 0, $delimiter);
    if (!$rawHeader || empty(array_filter($rawHeader, fn($v) => trim((string)$v) !== ''))) {
        fclose($handle);
        return ['success' => false, 'error' => 'Empty CSV header row or unreadable format.'];
    }

    // Clean BOM / non-printable characters from first header column
    if (isset($rawHeader[0])) {
        $rawHeader[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', (string)$rawHeader[0]);
    }

    // Build header column map
    $colMap = [];
    foreach ($rawHeader as $index => $colName) {
        $norm = strtolower(trim((string)$colName));
        $norm = preg_replace('/[^a-z0-9_]/', '', str_replace([' ', '-', '#', '.'], '_', $norm));

        if (in_array($norm, ['invoice_number', 'invoice_no', 'inv_number', 'inv_no', 'invoice', 'bill_number', 'bill_no', 'invoicenum'], true)) {
            $colMap['invoice_number'] = $index;
        } elseif (in_array($norm, ['invoice_date', 'date', 'bill_date', 'created_at', 'inv_date', 'datetime', 'time'], true)) {
            $colMap['invoice_date'] = $index;
        } elseif (in_array($norm, ['customer_name', 'customer', 'client', 'party_name', 'buyer', 'client_name', 'party', 'customername'], true)) {
            $colMap['customer_name'] = $index;
        } elseif (in_array($norm, ['customer_phone', 'phone', 'mobile', 'contact', 'customer_mobile', 'phone_number', 'mobile_no', 'phoneno'], true)) {
            $colMap['customer_phone'] = $index;
        } elseif (in_array($norm, ['customer_email', 'email', 'mail', 'email_address'], true)) {
            $colMap['customer_email'] = $index;
        } elseif (in_array($norm, ['customer_address', 'address', 'billing_address', 'shipping_address', 'city'], true)) {
            $colMap['customer_address'] = $index;
        } elseif (in_array($norm, ['product_name', 'item_name', 'product', 'item', 'description', 'title', 'item_description', 'productname'], true)) {
            $colMap['product_name'] = $index;
        } elseif (in_array($norm, ['product_sku', 'sku', 'item_code', 'code', 'item_sku', 'barcode', 'sku_code'], true)) {
            $colMap['product_sku'] = $index;
        } elseif (in_array($norm, ['quantity', 'qty', 'count', 'units', 'pieces', 'qnty'], true)) {
            $colMap['quantity'] = $index;
        } elseif (in_array($norm, ['unit_price', 'price', 'rate', 'selling_price', 'mrp', 'item_price', 'cost', 'unitprice'], true)) {
            $colMap['unit_price'] = $index;
        } elseif (in_array($norm, ['discount_amount', 'discount', 'disc', 'discount_val', 'disc_amt'], true)) {
            $colMap['discount_amount'] = $index;
        } elseif (in_array($norm, ['tax_percent', 'tax', 'tax_rate', 'gst', 'gst_rate', 'vat', 'tax_pct', 'taxpercent'], true)) {
            $colMap['tax_percent'] = $index;
        } elseif (in_array($norm, ['payment_method', 'payment_mode', 'mode', 'payment_type', 'pay_method'], true)) {
            $colMap['payment_method'] = $index;
        } elseif (in_array($norm, ['payment_status', 'pay_status'], true)) {
            $colMap['payment_status'] = $index;
        } elseif (in_array($norm, ['invoice_status', 'status', 'state'], true)) {
            $colMap['invoice_status'] = $index;
        } elseif (in_array($norm, ['notes', 'remarks', 'note', 'comment', 'description_notes'], true)) {
            $colMap['notes'] = $index;
        } elseif (in_array($norm, ['subtotal', 'sub_total', 'taxable_amount'], true)) {
            $colMap['subtotal'] = $index;
        } elseif (in_array($norm, ['total_amount', 'total', 'grand_total', 'net_total', 'amount'], true)) {
            $colMap['total_amount'] = $index;
        }
    }

    // Read all raw rows and group by invoice
    $invoiceGroups = [];
    $rowNum = 1;
    $autoInvSeq = 1;
    $lastExplicitInvNum = '';

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNum++;
        if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
            continue;
        }

        $invNum = isset($colMap['invoice_number'], $row[$colMap['invoice_number']]) ? trim((string)$row[$colMap['invoice_number']]) : '';
        $custName = isset($colMap['customer_name'], $row[$colMap['customer_name']]) ? trim((string)$row[$colMap['customer_name']]) : '';
        $custPhone = isset($colMap['customer_phone'], $row[$colMap['customer_phone']]) ? trim((string)$row[$colMap['customer_phone']]) : '';
        $custEmail = isset($colMap['customer_email'], $row[$colMap['customer_email']]) ? trim((string)$row[$colMap['customer_email']]) : '';
        $custAddress = isset($colMap['customer_address'], $row[$colMap['customer_address']]) ? trim((string)$row[$colMap['customer_address']]) : '';
        $invDate = isset($colMap['invoice_date'], $row[$colMap['invoice_date']]) ? trim((string)$row[$colMap['invoice_date']]) : '';
        $prodName = isset($colMap['product_name'], $row[$colMap['product_name']]) ? trim((string)$row[$colMap['product_name']]) : '';
        $prodSku = isset($colMap['product_sku'], $row[$colMap['product_sku']]) ? trim((string)$row[$colMap['product_sku']]) : '';
        $qtyRaw = isset($colMap['quantity'], $row[$colMap['quantity']]) ? preg_replace('/[^0-9.]/', '', (string)$row[$colMap['quantity']]) : '1';
        $qty = max(1, (int)$qtyRaw);
        $unitPriceRaw = isset($colMap['unit_price'], $row[$colMap['unit_price']]) ? preg_replace('/[^0-9.]/', '', (string)$row[$colMap['unit_price']]) : '';
        $unitPrice = ($unitPriceRaw !== '') ? (float)$unitPriceRaw : null;
        $discRaw = isset($colMap['discount_amount'], $row[$colMap['discount_amount']]) ? preg_replace('/[^0-9.]/', '', (string)$row[$colMap['discount_amount']]) : '0';
        $discountAmount = (float)$discRaw;
        $taxRaw = isset($colMap['tax_percent'], $row[$colMap['tax_percent']]) ? preg_replace('/[^0-9.]/', '', (string)$row[$colMap['tax_percent']]) : '';
        $taxPercent = ($taxRaw !== '') ? (float)$taxRaw : null;
        $payMethod = isset($colMap['payment_method'], $row[$colMap['payment_method']]) ? strtolower(trim((string)$row[$colMap['payment_method']])) : 'cash';
        $payStatus = isset($colMap['payment_status'], $row[$colMap['payment_status']]) ? strtolower(trim((string)$row[$colMap['payment_status']])) : '';
        $invStatus = isset($colMap['invoice_status'], $row[$colMap['invoice_status']]) ? strtolower(trim((string)$row[$colMap['invoice_status']])) : 'paid';
        $notes = isset($colMap['notes'], $row[$colMap['notes']]) ? trim((string)$row[$colMap['notes']]) : '';
        $totalRaw = isset($colMap['total_amount'], $row[$colMap['total_amount']]) ? (float)preg_replace('/[^0-9.]/', '', (string)$row[$colMap['total_amount']]) : null;

        // Group key
        if ($invNum !== '') {
            $groupKey = 'NUM_' . $invNum;
            $lastExplicitInvNum = $invNum;
        } elseif ($custName !== '' || $custPhone !== '' || $invDate !== '') {
            $groupKey = 'ROW_' . $rowNum;
        } elseif ($lastExplicitInvNum !== '') {
            $groupKey = 'NUM_' . $lastExplicitInvNum;
        } else {
            $groupKey = 'ROW_' . $rowNum;
        }

        if (!isset($invoiceGroups[$groupKey])) {
            $invoiceGroups[$groupKey] = [
                'invoice_number' => $invNum,
                'invoice_date' => $invDate,
                'customer_name' => $custName,
                'customer_phone' => $custPhone,
                'customer_email' => $custEmail,
                'customer_address' => $custAddress,
                'payment_method' => $payMethod,
                'payment_status' => $payStatus,
                'invoice_status' => $invStatus,
                'notes' => $notes,
                'total_amount_override' => $totalRaw,
                'items' => [],
                'first_row_num' => $rowNum,
            ];
        } else {
            if (empty($invoiceGroups[$groupKey]['customer_name']) && $custName !== '') {
                $invoiceGroups[$groupKey]['customer_name'] = $custName;
            }
            if (empty($invoiceGroups[$groupKey]['customer_phone']) && $custPhone !== '') {
                $invoiceGroups[$groupKey]['customer_phone'] = $custPhone;
            }
            if (empty($invoiceGroups[$groupKey]['invoice_date']) && $invDate !== '') {
                $invoiceGroups[$groupKey]['invoice_date'] = $invDate;
            }
        }

        $invoiceGroups[$groupKey]['items'][] = [
            'row_num' => $rowNum,
            'product_name' => $prodName,
            'product_sku' => $prodSku,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'discount_amount' => $discountAmount,
            'tax_percent' => $taxPercent,
            'total_amount' => $totalRaw,
        ];
    }

    fclose($handle);

    if (empty($invoiceGroups)) {
        return ['success' => false, 'error' => 'No valid data rows found in uploaded CSV file.'];
    }

    $importedCount = 0;
    $errors = [];
    $customerCache = [];
    $productCache = [];

    foreach ($invoiceGroups as $groupKey => $group) {
        try {
            $db->beginTransaction();

            $custName = trim((string)$group['customer_name']);
            $custPhone = trim((string)$group['customer_phone']);
            $custEmail = trim((string)$group['customer_email']);
            $custAddress = trim((string)$group['customer_address']);

            // Resolve Customer
            $customerId = 1;
            if ($custPhone !== '' || $custName !== '') {
                $cacheKey = $custPhone ?: strtolower($custName);
                if (isset($customerCache[$cacheKey])) {
                    $customerId = $customerCache[$cacheKey];
                } else {
                    if ($custPhone !== '') {
                        $stmtCust = $db->prepare('SELECT id FROM customers WHERE phone = :phone AND business_id = :bid LIMIT 1');
                        $stmtCust->execute(['phone' => $custPhone, 'bid' => $bid]);
                        $foundId = $stmtCust->fetchColumn();
                        if ($foundId) {
                            $customerId = (int)$foundId;
                        }
                    }
                    if ($customerId === 1 && $custName !== '' && strtolower($custName) !== 'walk-in' && strtolower($custName) !== 'walk-in customer') {
                        $stmtCustName = $db->prepare('SELECT id FROM customers WHERE LOWER(name) = :name AND business_id = :bid LIMIT 1');
                        $stmtCustName->execute(['name' => strtolower($custName), 'bid' => $bid]);
                        $foundId = $stmtCustName->fetchColumn();
                        if ($foundId) {
                            $customerId = (int)$foundId;
                        }
                    }

                    if ($customerId === 1 && $custName !== '' && strtolower($custName) !== 'walk-in' && strtolower($custName) !== 'walk-in customer') {
                        $stmtInsCust = $db->prepare('
                            INSERT INTO customers (business_id, name, phone, email, address, created_at, updated_at)
                            VALUES (:bid, :name, :phone, :email, :address, NOW(), NOW())
                        ');
                        $stmtInsCust->execute([
                            'bid' => $bid,
                            'name' => $custName,
                            'phone' => $custPhone ?: null,
                            'email' => $custEmail ?: null,
                            'address' => $custAddress ?: null,
                        ]);
                        $customerId = (int)$db->lastInsertId();
                    }
                    $customerCache[$cacheKey] = $customerId;
                }
            }

            // Parse Date
            $rawDate = trim((string)$group['invoice_date']);
            $invoiceDateTime = date('Y-m-d H:i:s');
            if ($rawDate !== '') {
                $ts = strtotime($rawDate);
                if ($ts !== false && $ts > 0) {
                    $invoiceDateTime = (strlen($rawDate) <= 10) ? date('Y-m-d 12:00:00', $ts) : date('Y-m-d H:i:s', $ts);
                }
            }

            // Normalize Status & Payment Method
            $invStatus = strtolower(trim((string)$group['invoice_status']));
            if (!in_array($invStatus, ['paid', 'draft', 'cancelled', 'unpaid'], true)) {
                $invStatus = 'paid';
            }
            $payMethod = strtolower(trim((string)$group['payment_method']));
            if (!in_array($payMethod, ['cash', 'card', 'upi', 'bank_transfer', 'cheque', 'credit', 'other'], true)) {
                $payMethod = 'cash';
            }
            $payStatus = strtolower(trim((string)$group['payment_status']));
            if ($payStatus === '') {
                $payStatus = ($invStatus === 'paid') ? 'paid' : 'unpaid';
            }

            // Process Line Items
            $subtotal = 0.00;
            $totalTax = 0.00;
            $totalDiscount = 0.00;
            $processedItems = [];

            foreach ($group['items'] as $item) {
                $pName = trim((string)$item['product_name']);
                $pSku = trim((string)$item['product_sku']);
                $qty = max(1, (int)$item['quantity']);
                $disc = max(0.0, (float)$item['discount_amount']);
                $rate = $item['unit_price'];
                $taxPct = $item['tax_percent'];

                $prod = null;
                $prodLookupKey = $pSku ?: strtolower($pName);
                if ($prodLookupKey !== '' && isset($productCache[$prodLookupKey])) {
                    $prod = $productCache[$prodLookupKey];
                } else {
                    if ($pSku !== '') {
                        $stmtProd = $db->prepare('SELECT id, name, sku, barcode, cost_price, selling_price, tax_percent, stock_quantity, status, hsn_code FROM products WHERE (sku = :sku OR barcode = :sku2) AND business_id = :bid LIMIT 1');
                        $stmtProd->execute(['sku' => $pSku, 'sku2' => $pSku, 'bid' => $bid]);
                        $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);
                    }
                    if (!$prod && $pName !== '') {
                        $stmtProd = $db->prepare('SELECT id, name, sku, barcode, cost_price, selling_price, tax_percent, stock_quantity, status, hsn_code FROM products WHERE LOWER(name) = :name AND business_id = :bid LIMIT 1');
                        $stmtProd->execute(['name' => strtolower($pName), 'bid' => $bid]);
                        $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);
                    }
                    if ($prod && $prodLookupKey !== '') {
                        $productCache[$prodLookupKey] = $prod;
                    }
                }

                $prodId = $prod ? (int)$prod['id'] : null;
                $prodName = $pName ?: ($prod ? $prod['name'] : 'Item ' . (count($processedItems) + 1));
                $prodSku = $pSku ?: ($prod ? $prod['sku'] : ('SKU-' . strtoupper(substr(uniqid(), -6))));
                $hsnCode = $prod['hsn_code'] ?? '';

                if ($rate === null) {
                    $rate = $prod ? (float)$prod['selling_price'] : ($item['total_amount'] !== null ? max(0.0, (float)$item['total_amount'] / $qty) : 0.00);
                }
                if ($taxPct === null) {
                    $taxPct = $prod ? (float)$prod['tax_percent'] : 0.00;
                }

                $lineBase = $rate * $qty;
                $lineDiscount = min($lineBase, $disc);
                $lineTaxable = max(0.0, $lineBase - $lineDiscount);
                $lineTax = $lineTaxable * ($taxPct / 100.0);
                $lineTotal = $lineTaxable + $lineTax;

                $subtotal += $lineBase;
                $totalDiscount += $lineDiscount;
                $totalTax += $lineTax;

                $processedItems[] = [
                    'product_id' => $prodId,
                    'product_name' => $prodName,
                    'product_sku' => $prodSku,
                    'hsn_code' => $hsnCode,
                    'unit_price' => $rate,
                    'quantity' => $qty,
                    'tax_percent' => $taxPct,
                    'tax_amount' => $lineTax,
                    'discount_amount' => $lineDiscount,
                    'line_total' => $lineTotal,
                    'stock_before' => $prod ? (int)$prod['stock_quantity'] : 0,
                    'stock_after' => $prod ? max(0, (int)$prod['stock_quantity'] - $qty) : 0,
                ];
            }

            if (empty($processedItems)) {
                $singleTotal = $group['total_amount_override'] !== null ? (float)$group['total_amount_override'] : 0.00;
                $processedItems[] = [
                    'product_id' => null,
                    'product_name' => 'General Invoiced Item',
                    'product_sku' => 'GEN-001',
                    'hsn_code' => '',
                    'unit_price' => $singleTotal,
                    'quantity' => 1,
                    'tax_percent' => 0.0,
                    'tax_amount' => 0.0,
                    'discount_amount' => 0.0,
                    'line_total' => $singleTotal,
                    'stock_before' => 0,
                    'stock_after' => 0,
                ];
                $subtotal = $singleTotal;
                $totalDiscount = 0.00;
                $totalTax = 0.00;
            }

            $taxableAmount = max(0.0, $subtotal - $totalDiscount);
            $grandTotal = max(0.0, round($taxableAmount + $totalTax, 2));
            $cgstAmount = round($totalTax / 2, 2);
            $sgstAmount = round($totalTax - $cgstAmount, 2);
            $igstAmount = 0.00;
            $amountPaid = ($invStatus === 'paid') ? $grandTotal : 0.00;

            // Invoice Number resolution
            $invNum = trim((string)$group['invoice_number']);
            if ($invNum === '') {
                $invNum = generate_next_invoice_number($bid, $db);
            } else {
                $stmtCheckInv = $db->prepare('SELECT id FROM invoices WHERE invoice_number = :num AND business_id = :bid LIMIT 1');
                $stmtCheckInv->execute(['num' => $invNum, 'bid' => $bid]);
                if ($stmtCheckInv->fetchColumn()) {
                    $invNum = $invNum . '-IMP' . $autoInvSeq++;
                }
            }

            // Create Order
            $orderNumber = 'ORD-' . date('Ymd', strtotime($invoiceDateTime)) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $stmtOrder = $db->prepare('
                INSERT INTO orders (
                    business_id, order_number, customer_id, user_id, subtotal, discount_amount, discount_type,
                    tax_amount, total_amount, payment_method, payment_status, order_status, fulfillment_status,
                    notes, created_at, updated_at
                ) VALUES (
                    :biz_id, :order_number, :customer_id, :user_id, :subtotal, :discount_amount, "fixed",
                    :tax_amount, :total_amount, :payment_method, :payment_status, "completed", "delivered",
                    :notes, :created_at, :updated_at
                )
            ');
            $stmtOrder->execute([
                'biz_id' => $bid,
                'order_number' => $orderNumber,
                'customer_id' => $customerId,
                'user_id' => $uid,
                'subtotal' => $subtotal,
                'discount_amount' => $totalDiscount,
                'tax_amount' => $totalTax,
                'total_amount' => $grandTotal,
                'payment_method' => $payMethod,
                'payment_status' => ($invStatus === 'paid') ? 'paid' : 'pending',
                'notes' => $group['notes'] ?: 'Imported via CSV',
                'created_at' => $invoiceDateTime,
                'updated_at' => $invoiceDateTime,
            ]);
            $orderId = (int)$db->lastInsertId();

            // Insert Order Items & adjust stock if active
            $stmtItem = $db->prepare('
                INSERT INTO order_items (
                    order_id, product_id, product_name, product_sku, hsn_code, unit_price,
                    quantity, tax_percent, tax_amount, discount_amount, line_total, created_at
                ) VALUES (
                    :order_id, :product_id, :product_name, :product_sku, :hsn_code, :unit_price,
                    :quantity, :tax_percent, :tax_amount, :discount_amount, :line_total, :created_at
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
                    'product_name' => $pItem['product_name'],
                    'product_sku' => $pItem['product_sku'],
                    'hsn_code' => $pItem['hsn_code'],
                    'unit_price' => $pItem['unit_price'],
                    'quantity' => $pItem['quantity'],
                    'tax_percent' => $pItem['tax_percent'],
                    'tax_amount' => $pItem['tax_amount'],
                    'discount_amount' => $pItem['discount_amount'],
                    'line_total' => $pItem['line_total'],
                    'created_at' => $invoiceDateTime,
                ]);

                if ($pItem['product_id'] && $invStatus === 'paid') {
                    $stmtStockDec->execute([
                        'qty' => $pItem['quantity'],
                        'id' => $pItem['product_id'],
                        'bid' => $bid,
                    ]);
                    $stmtMoveLog->execute([
                        'biz_id' => $bid,
                        'product_id' => $pItem['product_id'],
                        'user_id' => $uid,
                        'quantity_change' => -$pItem['quantity'],
                        'quantity_before' => $pItem['stock_before'],
                        'quantity_after' => $pItem['stock_after'],
                        'reason' => "Imported Invoice #{$invNum}",
                    ]);
                }
            }

            // Insert Invoice
            $stmtInv = $db->prepare('
                INSERT INTO invoices (
                    business_id, invoice_number, order_id, customer_id, user_id, invoice_date, subtotal,
                    discount_amount, discount_type, taxable_amount, cgst_amount, sgst_amount, igst_amount,
                    tax_amount, total_amount, amount_paid, change_amount, payment_method, payment_status,
                    invoice_status, notes, created_at, updated_at
                ) VALUES (
                    :biz_id, :invoice_number, :order_id, :customer_id, :user_id, :invoice_date, :subtotal,
                    :discount_amount, "fixed", :taxable_amount, :cgst_amount, :sgst_amount, :igst_amount,
                    :tax_amount, :total_amount, :amount_paid, 0.00, :payment_method, :payment_status,
                    :invoice_status, :notes, :created_at, :updated_at
                )
            ');
            $stmtInv->execute([
                'biz_id' => $bid,
                'invoice_number' => $invNum,
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'user_id' => $uid,
                'invoice_date' => $invoiceDateTime,
                'subtotal' => $subtotal,
                'discount_amount' => $totalDiscount,
                'taxable_amount' => $taxableAmount,
                'cgst_amount' => $cgstAmount,
                'sgst_amount' => $sgstAmount,
                'igst_amount' => $igstAmount,
                'tax_amount' => $totalTax,
                'total_amount' => $grandTotal,
                'amount_paid' => $amountPaid,
                'payment_method' => $payMethod,
                'payment_status' => $payStatus,
                'invoice_status' => $invStatus,
                'notes' => $group['notes'] ?: null,
                'created_at' => $invoiceDateTime,
                'updated_at' => $invoiceDateTime,
            ]);

            $db->commit();
            $importedCount++;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = "Row {$group['first_row_num']} (Invoice '{$group['invoice_number']}'): " . $e->getMessage();
        }
    }

    return [
        'success' => true,
        'imported_count' => $importedCount,
        'errors' => $errors,
    ];
}


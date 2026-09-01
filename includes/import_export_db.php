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
function export_data_to_csv(string $entityType, ?int $businessId = null): void {
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
        fputcsv($output, ['Invoice Number', 'Date', 'Customer Name', 'Subtotal', 'Taxable Amount', 'CGST', 'SGST', 'Total Amount', 'Payment Method', 'Status']);
        
        $stmt = $db->prepare('
            SELECT i.invoice_number, i.invoice_date, COALESCE(c.name, "Walk-in") as customer_name,
                   i.subtotal, i.taxable_amount, i.cgst_amount, i.sgst_amount, i.total_amount,
                   i.payment_method, i.invoice_status
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.business_id = :bid
            ORDER BY i.id DESC
        ');
        $stmt->execute(['bid' => $bid]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['invoice_number'],
                $row['invoice_date'],
                $row['customer_name'],
                number_format((float)$row['subtotal'], 2, '.', ''),
                number_format((float)$row['taxable_amount'], 2, '.', ''),
                number_format((float)$row['cgst_amount'], 2, '.', ''),
                number_format((float)$row['sgst_amount'], 2, '.', ''),
                number_format((float)$row['total_amount'], 2, '.', ''),
                strtoupper($row['payment_method']),
                ucfirst($row['invoice_status'])
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

function export_invoices_csv(?int $businessId = null): void {
    export_data_to_csv('invoices', $businessId);
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


<?php
/**
 * Safe CSV Import & Export Engine for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/products_db.php';

function export_data_to_csv(string $entityType, ?int $businessId = null): void {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $filename = "{$entityType}_export_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');

    if ($entityType === 'products') {
        fputcsv($output, ['ID', 'Name', 'SKU', 'Barcode', 'Cost Price', 'Selling Price', 'Tax Percent', 'Stock Quantity', 'Status']);
        $stmt = $db->prepare('SELECT id, name, sku, barcode, cost_price, selling_price, tax_percent, stock_quantity, status FROM products WHERE business_id = :bid ORDER BY id ASC');
        $stmt->execute(['bid' => $bid]);
        while ($row = $stmt->fetch()) {
            fputcsv($output, $row);
        }
    } elseif ($entityType === 'customers') {
        fputcsv($output, ['ID', 'Name', 'Phone', 'Email', 'Address', 'Loyalty Points', 'Credit Limit']);
        $stmt = $db->prepare('SELECT id, name, phone, email, address, loyalty_points_balance, credit_limit FROM customers WHERE business_id = :bid ORDER BY id ASC');
        $stmt->execute(['bid' => $bid]);
        while ($row = $stmt->fetch()) {
            fputcsv($output, $row);
        }
    } elseif ($entityType === 'orders') {
        fputcsv($output, ['Order Number', 'Date', 'Subtotal', 'Discount', 'Tax', 'Grand Total', 'Payment Method', 'Order Status']);
        $stmt = $db->prepare('SELECT order_number, created_at, subtotal, discount_amount, tax_amount, total_amount, payment_method, order_status FROM orders WHERE business_id = :bid ORDER BY id DESC');
        $stmt->execute(['bid' => $bid]);
        while ($row = $stmt->fetch()) {
            fputcsv($output, $row);
        }
    } elseif ($entityType === 'invoices') {
        fputcsv($output, ['Invoice Number', 'Date', 'Subtotal', 'Taxable', 'CGST', 'SGST', 'Total Amount', 'Payment Method', 'Status']);
        $stmt = $db->prepare('SELECT invoice_number, invoice_date, subtotal, taxable_amount, cgst_amount, sgst_amount, total_amount, payment_method, invoice_status FROM invoices WHERE business_id = :bid ORDER BY id DESC');
        $stmt->execute(['bid' => $bid]);
        while ($row = $stmt->fetch()) {
            fputcsv($output, $row);
        }
    }

    fclose($output);
    exit;
}

function import_products_from_csv(string $csvFilePath, ?int $userId = null): array {
    if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
        return ['success' => false, 'error' => 'Uploaded CSV file could not be read.'];
    }

    $handle = fopen($csvFilePath, 'r');
    if (!$handle) return ['success' => false, 'error' => 'Cannot open CSV file.'];

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'error' => 'Empty CSV file.'];
    }

    $imported = 0;
    $errors = [];
    $rowNum = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;
        if (empty(array_filter($row))) continue;

        $name = trim($row[0] ?? '');
        $sku = trim($row[1] ?? '');
        $sellingPrice = (float)($row[2] ?? 0);
        $costPrice = (float)($row[3] ?? 0);
        $tax = (float)($row[4] ?? 0);
        $stock = (int)($row[5] ?? 0);

        if ($name === '') {
            $errors[] = "Row {$rowNum}: Product name is required.";
            continue;
        }

        $res = save_product([
            'name' => $name,
            'sku' => $sku ?: 'SKU-' . strtoupper(substr(uniqid(), -6)),
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'tax_percent' => $tax,
            'initial_stock' => $stock,
            'status' => 'active',
        ], null, null, $userId);

        if ($res['success']) {
            $imported++;
        } else {
            $errors[] = "Row {$rowNum} ('{$name}'): " . implode(', ', $res['errors']);
        }
    }

    fclose($handle);
    return ['success' => true, 'imported_count' => $imported, 'errors' => $errors];
}

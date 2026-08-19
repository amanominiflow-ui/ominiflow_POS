<?php
/**
 * OminiFlow POS - Global Spotlight Search API (Zoho POS Parity)
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$query = trim((string)($_GET['q'] ?? ''));
$scope = trim((string)($_GET['scope'] ?? 'all'));

if ($query === '' || strlen($query) < 1) {
    echo json_encode([
        'success' => true,
        'query' => $query,
        'results' => []
    ]);
    exit;
}

$db = get_db();
$results = [];
$searchPattern = '%' . $query . '%';

// 1. SEARCH PRODUCTS / ITEMS
if ($scope === 'all' || $scope === 'products' || $scope === 'items') {
    $stmt = $db->prepare('
        SELECT id, name, sku, barcode, selling_price, stock_quantity
        FROM products
        WHERE status = "active" AND (name LIKE ? OR sku LIKE ? OR barcode LIKE ?)
        LIMIT 6
    ');
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($items)) {
        $results['Products & Items'] = array_map(function ($p) {
            return [
                'type' => 'product',
                'id' => (int) $p['id'],
                'title' => $p['name'],
                'subtitle' => 'SKU: ' . $p['sku'] . ' • Stock: ' . (int)$p['stock_quantity'] . ' units',
                'badge' => '₹' . number_format((float)$p['selling_price'], 2),
                'url' => APP_URL . '/products.php?search=' . urlencode($p['sku'])
            ];
        }, $items);
    }
}

// 2. SEARCH CUSTOMERS
if ($scope === 'all' || $scope === 'customers') {
    $stmt = $db->prepare('
        SELECT id, name, phone, email
        FROM customers
        WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?
        LIMIT 6
    ');
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
    $custs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($custs)) {
        $results['Customers'] = array_map(function ($c) {
            return [
                'type' => 'customer',
                'id' => (int) $c['id'],
                'title' => $c['name'],
                'subtitle' => ($c['phone'] ?: 'No phone') . ($c['email'] ? ' • ' . $c['email'] : ''),
                'badge' => 'Customer',
                'url' => APP_URL . '/customers.php?search=' . urlencode($c['name'])
            ];
        }, $custs);
    }
}

// 3. SEARCH INVOICES
if ($scope === 'all' || $scope === 'invoices') {
    $stmt = $db->prepare('
        SELECT id, invoice_number, total_amount, invoice_status, invoice_date
        FROM invoices
        WHERE invoice_number LIKE ?
        ORDER BY id DESC
        LIMIT 6
    ');
    $stmt->execute([$searchPattern]);
    $invs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($invs)) {
        $results['Tax Invoices'] = array_map(function ($inv) {
            return [
                'type' => 'invoice',
                'id' => (int) $inv['id'],
                'title' => 'Invoice #' . $inv['invoice_number'],
                'subtitle' => date('d M Y, h:i A', strtotime($inv['invoice_date'])),
                'badge' => '₹' . number_format((float)$inv['total_amount'], 2),
                'url' => APP_URL . '/invoice-view.php?id=' . $inv['id']
            ];
        }, $invs);
    }
}

// 4. SEARCH ORDERS
if ($scope === 'all' || $scope === 'orders') {
    $stmt = $db->prepare('
        SELECT id, order_number, total_amount, payment_status, created_at
        FROM orders
        WHERE order_number LIKE ?
        ORDER BY id DESC
        LIMIT 6
    ');
    $stmt->execute([$searchPattern]);
    $ords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($ords)) {
        $results['POS Orders'] = array_map(function ($o) {
            return [
                'type' => 'order',
                'id' => (int) $o['id'],
                'title' => 'Order #' . $o['order_number'],
                'subtitle' => date('d M Y • h:i A', strtotime($o['created_at'])) . ' • ' . ucfirst($o['payment_status']),
                'badge' => '₹' . number_format((float)$o['total_amount'], 2),
                'url' => APP_URL . '/orders.php?search=' . urlencode($o['order_number'])
            ];
        }, $ords);
    }
}

// 5. SEARCH VENDORS
if ($scope === 'all' || $scope === 'vendors') {
    $stmt = $db->prepare('
        SELECT id, name, company_name, phone, gstin
        FROM vendors
        WHERE name LIKE ? OR company_name LIKE ? OR phone LIKE ? OR gstin LIKE ?
        LIMIT 6
    ');
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
    $vens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($vens)) {
        $results['Vendors & Suppliers'] = array_map(function ($v) {
            return [
                'type' => 'vendor',
                'id' => (int) $v['id'],
                'title' => $v['name'],
                'subtitle' => ($v['company_name'] ?: 'Wholesale') . ($v['phone'] ? ' • ' . $v['phone'] : ''),
                'badge' => $v['gstin'] ?: 'Vendor',
                'url' => APP_URL . '/vendors.php?search=' . urlencode($v['name'])
            ];
        }, $vens);
    }
}

echo json_encode([
    'success' => true,
    'query' => $query,
    'scope' => $scope,
    'results' => $results
]);

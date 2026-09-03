<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/products_db.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kind = (($_POST['kind'] ?? 'brand') === 'manufacturer') ? 'manufacturer' : 'brand';
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Name cannot be empty']);
        exit;
    }
    remember_product_brand($kind, $name);
    echo json_encode(['success' => true, 'name' => $name, 'kind' => $kind]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid method']);


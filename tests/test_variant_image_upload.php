<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/products_db.php';

echo "Testing handle_variant_image_upload with file array...\n";

// 1. Test with missing file / error code
$emptyFile = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
$res1 = handle_variant_image_upload($emptyFile);
if ($res1 !== null) {
    echo "[FAIL] Expected null for empty file, got " . var_export($res1, true) . "\n";
    exit(1);
}

// 2. Test with real dummy file
$tmpPath = sys_get_temp_dir() . '/test_img_' . uniqid() . '.png';
file_put_contents($tmpPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

$validFile = [
    'name' => 'test_preview.png',
    'type' => 'image/png',
    'tmp_name' => $tmpPath,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tmpPath),
];

try {
    $res2 = handle_variant_image_upload($validFile);
    echo "[PASS] handle_variant_image_upload executed without TypeError! Returned: " . var_export($res2, true) . "\n";
} catch (TypeError $e) {
    echo "[FAIL] TypeError thrown: " . $e->getMessage() . "\n";
    exit(1);
}

if (file_exists($tmpPath)) {
    @unlink($tmpPath);
}

echo "All handle_variant_image_upload checks passed!\n";

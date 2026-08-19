<?php
/**
 * Barcode & Price Tag Print Generator (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/products_db.php';
require_once __DIR__ . '/includes/barcode_helper.php';

require_auth();

$pageTitle = 'Barcode & Price Label Printing';

$products = get_products();
// Auto-select the first product if none specified in URL
$defaultPid = !empty($products[0]['id']) ? (int)$products[0]['id'] : 0;
$selectedPid = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int)$_GET['product_id'] : $defaultPid;
$copies = isset($_GET['copies']) ? max(1, min(100, (int)$_GET['copies'])) : 12;
$labelSize = isset($_GET['label_size']) ? (string)$_GET['label_size'] : 'standard';
$showStore = isset($_GET['show_store']) ? (bool)$_GET['show_store'] : true;
$showPrice = isset($_GET['show_price']) ? (bool)$_GET['show_price'] : true;
$showSku = isset($_GET['show_sku']) ? (bool)$_GET['show_sku'] : true;

$targetProduct = null;
if ($selectedPid > 0) {
    $targetProduct = get_product_by_id($selectedPid);
}
if (!$targetProduct && !empty($products[0])) {
    $targetProduct = $products[0];
    $selectedPid = (int)$targetProduct['id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .barcode-card-item {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 12px 10px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .barcode-store-name {
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #475569;
            margin-bottom: 2px;
        }
        .barcode-prod-name {
            font-size: 12.5px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.3;
            max-width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 4px;
        }
        .barcode-price-tag {
            font-size: 16px;
            font-weight: 800;
            color: #047857;
            margin-bottom: 4px;
        }
        .barcode-svg-container {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 2px 0;
            overflow: hidden;
        }
        .barcode-sku-sub {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 9.5px;
            color: #64748b;
            font-weight: 600;
            margin-top: 2px;
        }

        /* Size presets */
        .size-compact .barcode-card-item {
            padding: 8px 6px;
        }
        .size-compact .barcode-prod-name {
            font-size: 11px;
        }
        .size-compact .barcode-price-tag {
            font-size: 13px;
        }

        .size-large .barcode-card-item {
            padding: 18px 14px;
        }
        .size-large .barcode-prod-name {
            font-size: 14.5px;
        }
        .size-large .barcode-price-tag {
            font-size: 19px;
        }

        @media print {
            @page {
                size: auto;
                margin: 5mm;
            }
            .app-sidebar, .app-header, .page-top-header, .filter-card, .spotlight-overlay, .no-print, .header-left, .header-right, button {
                display: none !important;
                visibility: hidden !important;
            }
            html, body {
                background: #ffffff !important;
                background-color: #ffffff !important;
                color: #000000 !important;
                display: block !important;
                width: 100% !important;
                min-width: 100% !important;
                height: auto !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .app-layout, .app-main, .dashboard-content {
                display: block !important;
                position: static !important;
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                min-height: auto !important;
                height: auto !important;
                overflow: visible !important;
                background: transparent !important;
            }
            .print-grid {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 5mm !important;
            }
            .size-compact .print-grid {
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 3mm !important;
            }
            .size-large .print-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 6mm !important;
            }
            .barcode-card-item {
                border: 1px dashed #94a3b8 !important;
                box-shadow: none !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
                background: #ffffff !important;
            }
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <!-- Page Top Header -->
                <div class="page-top-header no-print" style="margin-bottom: 20px;">
                    <div>
                        <h1 class="page-title">Barcode & Price Label Printing</h1>
                        <p class="page-subtitle">Generate high-density scannable Code128 vector barcodes and retail price labels for laser and thermal printers.</p>
                    </div>
                    <?php if ($targetProduct): ?>
                        <div style="display: flex; gap: 10px;">
                            <button type="button" onclick="window.print()" class="header-btn" style="padding: 10px 20px; font-size: 13.5px; display: inline-flex; align-items: center; gap: 8px;">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                <span>Print <?= $copies ?> Label<?= $copies > 1 ? 's' : '' ?> Now</span>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Product Selector & Print Configuration Card -->
                <div class="filter-card no-print" style="padding: 24px; margin-bottom: 24px;">
                    <form method="GET" action="<?= asset('barcode-print.php') ?>" id="barcodeConfigForm" style="display: flex; flex-direction: column; gap: 18px;">
                        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; align-items: flex-end;">
                            <!-- Product Dropdown -->
                            <div>
                                <label style="display: block; font-size: 12.5px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 6px;">
                                    Select Product to Print *
                                </label>
                                <select name="product_id" required class="form-control" onchange="document.getElementById('barcodeConfigForm').submit()" style="width: 100%; cursor: pointer;">
                                    <?php if (empty($products)): ?>
                                        <option value="">-- No products available --</option>
                                    <?php else: ?>
                                        <?php foreach ($products as $p): ?>
                                            <option value="<?= $p['id'] ?>" <?= $selectedPid === (int)$p['id'] ? 'selected' : '' ?>>
                                                <?= e($p['name']) ?> — [SKU: <?= e($p['sku']) ?>] — ₹<?= number_format((float)$p['selling_price'], 2) ?> (Stock: <?= (int)$p['stock_quantity'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <!-- Label Copies -->
                            <div>
                                <label style="display: block; font-size: 12.5px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 6px;">
                                    Number of Copies
                                </label>
                                <input type="number" name="copies" min="1" max="100" value="<?= $copies ?>" class="form-control" style="width: 100%;">
                            </div>

                            <!-- Label Size Preset -->
                            <div>
                                <label style="display: block; font-size: 12.5px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 6px;">
                                    Label Template Size
                                </label>
                                <select name="label_size" class="form-control" onchange="document.getElementById('barcodeConfigForm').submit()" style="width: 100%; cursor: pointer;">
                                    <option value="standard" <?= $labelSize === 'standard' ? 'selected' : '' ?>>Standard (50 x 30 mm)</option>
                                    <option value="compact" <?= $labelSize === 'compact' ? 'selected' : '' ?>>Compact (40 x 20 mm)</option>
                                    <option value="large" <?= $labelSize === 'large' ? 'selected' : '' ?>>Price & Shelf Tag (60 x 40 mm)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Display Options & Submit Button -->
                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--saas-border-light); padding-top: 14px; flex-wrap: wrap; gap: 12px;">
                            <div style="display: flex; gap: 20px; align-items: center;">
                                <span style="font-size: 12px; font-weight: 700; color: var(--saas-slate-600);">Label Content:</span>
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--saas-navy-950); cursor: pointer;">
                                    <input type="checkbox" name="show_store" value="1" <?= $showStore ? 'checked' : '' ?> onchange="document.getElementById('barcodeConfigForm').submit()">
                                    <span>Store Name</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--saas-navy-950); cursor: pointer;">
                                    <input type="checkbox" name="show_price" value="1" <?= $showPrice ? 'checked' : '' ?> onchange="document.getElementById('barcodeConfigForm').submit()">
                                    <span>Selling Price</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--saas-navy-950); cursor: pointer;">
                                    <input type="checkbox" name="show_sku" value="1" <?= $showSku ? 'checked' : '' ?> onchange="document.getElementById('barcodeConfigForm').submit()">
                                    <span>SKU & Barcode Text</span>
                                </label>
                            </div>

                            <div style="display: flex; gap: 10px;">
                                <button type="submit" class="header-btn" style="padding: 9px 22px;">
                                    Apply & Generate Preview
                                </button>
                                <?php if ($targetProduct): ?>
                                    <button type="button" onclick="window.print()" class="header-btn-secondary" style="padding: 9px 18px;">
                                        🖨️ Print Sheet
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Barcode Sheet Preview -->
                <?php if ($targetProduct): ?>
                    <?php
                    $barcodeValue = trim((string)($targetProduct['barcode'] ?: $targetProduct['sku']));
                    if ($barcodeValue === '') {
                        $barcodeValue = 'SKU-' . str_pad((string)$targetProduct['id'], 6, '0', STR_PAD_LEFT);
                    }
                    $barcodeSvg = generate_code128_svg($barcodeValue, $labelSize === 'compact' ? 28 : ($labelSize === 'large' ? 44 : 36), $labelSize === 'compact' ? 1.2 : 1.5);
                    ?>
                    <div class="size-<?= e($labelSize) ?>">
                        <!-- Live Summary Banner -->
                        <div class="no-print" style="background: #f8fafc; border: 1px solid var(--saas-border); border-radius: var(--saas-radius-md); padding: 14px 18px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                            <div style="display: flex; align-items: center; gap: 14px;">
                                <div style="width: 42px; height: 42px; border-radius: 8px; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 18px;">
                                    🏷️
                                </div>
                                <div>
                                    <div style="font-size: 15px; font-weight: 700; color: var(--saas-navy-950);"><?= e($targetProduct['name']) ?></div>
                                    <div style="font-size: 12px; color: var(--saas-slate-500); font-family: monospace;">SKU: <?= e($targetProduct['sku']) ?> &bull; Barcode: <?= e($barcodeValue) ?> &bull; Current Stock: <?= (int)$targetProduct['stock_quantity'] ?> units</div>
                                </div>
                            </div>
                            <div style="font-size: 13px; font-weight: 700; color: var(--saas-navy-950);">
                                Generating <span style="color: var(--saas-primary);"><?= $copies ?></span> Label<?= $copies > 1 ? 's' : '' ?>
                            </div>
                        </div>

                        <!-- Labels Printable Grid -->
                        <div class="print-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 14px;">
                            <?php for ($i = 0; $i < $copies; $i++): ?>
                                <div class="barcode-card-item">
                                    <?php if ($showStore): ?>
                                        <div class="barcode-store-name"><?= APP_NAME ?> RETAIL</div>
                                    <?php endif; ?>

                                    <div class="barcode-prod-name" title="<?= e($targetProduct['name']) ?>">
                                        <?= e($targetProduct['name']) ?>
                                    </div>

                                    <?php if ($showPrice): ?>
                                        <div class="barcode-price-tag">
                                            ₹<?= number_format((float)$targetProduct['selling_price'], 2) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="barcode-svg-container">
                                        <?= $barcodeSvg ?>
                                    </div>

                                    <?php if ($showSku): ?>
                                        <div class="barcode-sku-sub">
                                            SKU: <?= e($targetProduct['sku']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="section-card" style="text-align: center; padding: 48px 24px; color: #64748b;">
                        <div style="font-size: 36px; margin-bottom: 12px;">🏷️</div>
                        <h3 style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 6px;">No Product Selected</h3>
                        <p style="font-size: 13.5px; max-width: 480px; margin: 0 auto 18px;">Please select a product from the dropdown above and choose the number of label copies to preview and print barcode stickers.</p>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>

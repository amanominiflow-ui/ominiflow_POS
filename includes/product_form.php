<?php
/**
 * Shared Zoho-style New Item / Edit Item form.
 * Expects: $isEdit, $product, $categories, $vendors, $taxRates, $brands, $manufacturers, $productImages, $errors
 */

declare(strict_types=1);

if (!function_exists('product_form_val')) {
    function product_form_val(string $key, string $default = ''): string {
        global $product;
        if (isset($_SESSION['old_input'][$key]) || $_SERVER['REQUEST_METHOD'] === 'POST') {
            return old($key, $default);
        }
        if (is_array($product ?? null) && isset($product[$key]) && $product[$key] !== null && $product[$key] !== '') {
            return (string) $product[$key];
        }
        return $default;
    }
}

$isEdit = !empty($isEdit);
$product = is_array($product ?? null) ? $product : [];
$errors = $errors ?? [];
$units = ['pcs', 'box', 'dz', 'kg', 'g', 'mg', 'lb', 'ml', 'l', 'm', 'cm', 'ft', 'in', 'sqft', 'sqm'];
$salesAccounts = ['Sales', 'Other Charges', 'Shipping Charge', 'Discount'];
$purchaseAccounts = ['Cost of Goods Sold', 'Inventory Asset', 'Freight', 'Purchase'];
$inventoryAccounts = ['Inventory Asset', 'Finished Goods', 'Raw Materials', 'Stock in Hand'];
$gstRates = array_values(array_filter($taxRates ?? [], static fn($t) => in_array(($t['type'] ?? ''), ['gst', 'exempt'], true)));
$igstRates = array_values(array_filter($taxRates ?? [], static fn($t) => ($t['type'] ?? '') === 'igst'));
if (!$gstRates) {
    $gstRates = $taxRates ?? [];
}
if (!$igstRates) {
    $igstRates = $taxRates ?? [];
}

$itemKind = product_form_val('item_kind', 'goods');
$itemType = product_form_val('product_type') === 'variable' ? 'variants' : product_form_val('item_type', 'single');
$taxPref = product_form_val('tax_preference', 'taxable');
$salesOn = $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['sales_enabled']) : ((int) ($product['sales_enabled'] ?? 1) === 1);
$purchaseOn = $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['purchase_enabled']) : ((int) ($product['purchase_enabled'] ?? 1) === 1);
$trackOn = $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['track_inventory']) : ((int) ($product['track_inventory'] ?? 1) === 1);
if ($itemKind === 'service') {
    $trackOn = false;
}
$returnable = $_SERVER['REQUEST_METHOD'] === 'POST' ? (($_POST['returnable'] ?? '1') === '1') : ((int) ($product['returnable'] ?? 1) === 1);

$identifiers = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiers = array_values(array_filter(array_map('trim', (array) ($_POST['identifiers'] ?? []))));
} elseif (!empty($product['extra_identifiers'])) {
    $decoded = json_decode((string) $product['extra_identifiers'], true);
    $identifiers = is_array($decoded) ? $decoded : [];
}
if (!$identifiers && !empty($product['barcode'])) {
    $identifiers = [(string) $product['barcode']];
}

$frontImg = $product['image_path'] ?? '';
$rearImg = $product['rear_image_path'] ?? '';
$otherImgs = [];
foreach ($productImages ?? [] as $im) {
    if (($im['kind'] ?? '') === 'front') {
        $frontImg = $im['path'];
    } elseif (($im['kind'] ?? '') === 'rear') {
        $rearImg = $im['path'];
    } else {
        $otherImgs[] = $im;
    }
}

$defaultIntra = product_form_val('intra_tax_rate_id');
$defaultInter = product_form_val('inter_tax_rate_id');
if ($defaultIntra === '' && $gstRates) {
    foreach ($gstRates as $g) {
        if ((float) $g['rate'] == 5.0) { $defaultIntra = (string) $g['id']; break; }
    }
    if ($defaultIntra === '') {
        $defaultIntra = (string) $gstRates[0]['id'];
    }
}
if ($defaultInter === '' && $igstRates) {
    foreach ($igstRates as $g) {
        if ((float) $g['rate'] == 5.0) { $defaultInter = (string) $g['id']; break; }
    }
    if ($defaultInter === '') {
        $defaultInter = (string) $igstRates[0]['id'];
    }
}

// Load variant attributes for edit mode or POST re-fill
$variantAttrs = [];
$existingVariants = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attrNames = (array) ($_POST['attr_name'] ?? []);
    $attrOpts = (array) ($_POST['attr_options'] ?? []);
    foreach ($attrNames as $ai => $an) {
        $an = trim((string) $an);
        if ($an === '') continue;
        $opts = $attrOpts[$ai] ?? '';
        if (is_string($opts)) {
            $opts = array_values(array_filter(array_map('trim', explode(',', $opts))));
        } else {
            $opts = array_values(array_filter(array_map('trim', (array) $opts)));
        }
        if (!empty($opts)) {
            $variantAttrs[] = ['name' => $an, 'options' => $opts];
        }
    }
} elseif ($isEdit && !empty($product['id']) && ($product['product_type'] ?? 'simple') === 'variable') {
    $variantAttrs = function_exists('get_product_attributes') ? get_product_attributes((int) $product['id']) : [];
    // Normalize format
    $normalized = [];
    foreach ($variantAttrs as $va) {
        $opts = [];
        foreach (($va['options'] ?? []) as $o) {
            $opts[] = is_array($o) ? ($o['value'] ?? '') : (string) $o;
        }
        $normalized[] = ['name' => $va['attribute_name'] ?? ($va['name'] ?? ''), 'options' => $opts];
    }
    $variantAttrs = $normalized;
    $existingVariants = function_exists('get_product_variants') ? get_product_variants((int) $product['id']) : [];
}
$variantAttrNames = ['Color', 'Size', 'Material', 'Style', 'Title', 'Pattern', 'Weight'];
?>
<style>
/* Single Item vs Contains Variants */
.item-toggles { display: flex !important; border: 1px solid #cbd5e1 !important; border-radius: 6px !important; overflow: hidden !important; width: fit-content !important; margin-bottom: 16px !important; background: #fff !important; }
.item-tog { padding: 8px 18px !important; border: none !important; background: transparent !important; font-size: 13px !important; font-weight: 600 !important; color: #475569 !important; cursor: pointer !important; transition: all 0.15s ease !important; }
.item-tog.on { background: #2563eb !important; color: #ffffff !important; }
.item-tog + .item-tog { border-left: 1px solid #cbd5e1 !important; }

.variants-only { display: none; }
.item-type-variants .variants-only { display: block !important; }
.item-type-variants .variants-only.item-2 { display: grid !important; }
.item-type-variants .single-only { display: none !important; }

.variations-sec {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 8px !important;
    padding: 16px 20px !important;
    margin-top: 14px !important;
    margin-bottom: 20px !important;
}
.variation-attr-row {
    display: grid !important;
    grid-template-columns: 200px 1fr 36px !important;
    gap: 16px !important;
    align-items: flex-start !important;
    margin-bottom: 12px !important;
}
.tag-input-wrap {
    display: flex !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    gap: 6px !important;
    min-height: 40px !important;
    padding: 4px 8px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    background: #ffffff !important;
    cursor: text !important;
}
.tag-input-wrap:focus-within {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59,130,246,.15) !important;
}
.tag-chip {
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    background: #e0e7ff !important;
    color: #3730a3 !important;
    border-radius: 4px !important;
    padding: 3px 8px !important;
    font-size: 12.5px !important;
    font-weight: 600 !important;
}
.tag-chip .tag-remove {
    cursor: pointer !important;
    font-size: 14px !important;
    color: #6366f1 !important;
    line-height: 1 !important;
    padding: 0 2px !important;
    border: none !important;
    background: none !important;
}
.tag-chip .tag-remove:hover { color: #dc2626 !important; }
.tag-input-field {
    border: none !important;
    outline: none !important;
    font: inherit !important;
    font-size: 13px !important;
    flex: 1 !important;
    min-width: 80px !important;
    padding: 4px !important;
    background: transparent !important;
}
.attr-remove-btn {
    width: 36px !important;
    height: 40px !important;
    background: none !important;
    border: none !important;
    color: #94a3b8 !important;
    font-size: 20px !important;
    cursor: pointer !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin-top: 24px !important;
}
.attr-remove-btn:hover { color: #ef4444 !important; }
.add-attr-link {
    background: none !important;
    border: 0 !important;
    color: #2563eb !important;
    font-weight: 600 !important;
    font-size: 13px !important;
    cursor: pointer !important;
    padding: 0 !important;
    margin-top: 10px !important;
    display: inline-block !important;
}
.add-attr-link:hover { color: #1d4ed8 !important; }
.variant-table-wrap { margin-top: 18px !important; overflow-x: auto !important; }
.variant-table { width: 100% !important; border-collapse: collapse !important; font-size: 13px !important; }
.variant-table th { background: #f1f5f9 !important; color: #475569 !important; font-weight: 700 !important; text-align: left !important; padding: 9px 12px !important; border: 1px solid #e2e8f0 !important; }
.variant-table td { padding: 8px 12px !important; border: 1px solid #e2e8f0 !important; background: #fff !important; }
.variant-table td input { width: 100% !important; border: 1px solid #cbd5e1 !important; border-radius: 5px !important; padding: 6px 10px !important; font: inherit !important; font-size: 13px !important; }
</style>

<div class="item-sheet <?= $itemType === 'variants' ? 'item-type-variants' : '' ?>" id="itemSheet">
    <div class="item-top">
        <div>
            <div class="item-row">
                <label class="item-label req" for="name">Name*</label>
                <input class="item-input <?= !empty($errors['name']) ? 'is-invalid' : '' ?>" type="text" id="name" name="name" value="<?= e(product_form_val('name')) ?>" required>
                <?php if (!empty($errors['name'])): ?><div class="item-err"><?= e($errors['name']) ?></div><?php endif; ?>
            </div>
            <div class="item-row">
                <label class="item-label">Type</label>
                <div class="item-radio">
                    <label><input type="radio" name="item_kind" value="goods" <?= $itemKind !== 'service' ? 'checked' : '' ?>> Goods</label>
                    <label><input type="radio" name="item_kind" value="service" <?= $itemKind === 'service' ? 'checked' : '' ?>> Service</label>
                </div>
            </div>
            <div class="item-2">
                <div class="item-row">
                    <label class="item-label" for="category_id">Category</label>
                    <select class="item-select" id="category_id" name="category_id">
                        <option value="">Select a category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= product_form_val('category_id') === (string) $cat['id'] ? 'selected' : '' ?>><?= e((string) $cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="item-row">
                    <label class="item-label" for="brand">Brand</label>
                    <input class="item-input" list="brand-list" id="brand" name="brand" value="<?= e(product_form_val('brand')) ?>" placeholder="Select or Add Brand">
                    <datalist id="brand-list">
                        <?php foreach ($brands as $b): ?><option value="<?= e($b) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
            </div>
            <div class="item-2">
                <div class="item-row">
                    <label class="item-label" for="manufacturer">Manufacturer</label>
                    <input class="item-input" list="mfr-list" id="manufacturer" name="manufacturer" value="<?= e(product_form_val('manufacturer')) ?>" placeholder="Select or Add Manufacturer">
                    <datalist id="mfr-list">
                        <?php foreach ($manufacturers as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="item-row">
                    <label class="item-label" for="hsn_code">HSN Code</label>
                    <div class="item-hsn">
                        <input class="item-input" type="text" id="hsn_code" name="hsn_code" value="<?= e(product_form_val('hsn_code')) ?>">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" stroke-width="2"/><path stroke-width="2" d="M20 20l-3-3"/></svg>
                    </div>
                </div>
            </div>
            <div class="item-row">
                <label class="item-label req" for="tax_preference">Tax Preference*</label>
                <select class="item-select" id="tax_preference" name="tax_preference" style="max-width:280px">
                    <option value="taxable" <?= $taxPref !== 'non_taxable' ? 'selected' : '' ?>>Taxable</option>
                    <option value="non_taxable" <?= $taxPref === 'non_taxable' ? 'selected' : '' ?>>Non-Taxable</option>
                </select>
            </div>
        </div>

        <div class="item-uploads">
            <div class="item-pair">
                <div class="item-drop" id="frontDrop">
                    <?php if ($frontImg): ?><img src="<?= asset($frontImg) ?>" alt=""><?php else: ?><span class="item-drop-btn">Upload Front Image</span><?php endif; ?>
                    <input type="file" name="image_front" accept="image/jpeg,image/png,image/webp">
                </div>
                <div class="item-drop" id="rearDrop">
                    <?php if ($rearImg): ?><img src="<?= asset($rearImg) ?>" alt=""><?php else: ?><span class="item-drop-btn">Upload Rear Image</span><?php endif; ?>
                    <input type="file" name="image_rear" accept="image/jpeg,image/png,image/webp">
                </div>
            </div>
            <div class="item-drop tall" id="otherDrop">
                <svg width="28" height="28" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M7 16a4 4 0 01-.88-7.9A5 5 0 1115.9 6h.1a5 5 0 011 9.9M12 12v9m0-9l-3 3m3-3l3 3"/></svg>
                <div><strong>Drag &amp; Drop Images.</strong> You can add up to 15 images including front, rear and other images, each not exceeding 5 MB.</div>
                <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
            </div>
            <?php if ($otherImgs): ?>
                <div class="item-thumbs">
                    <?php foreach ($otherImgs as $im): ?>
                        <div class="item-thumb">
                            <img src="<?= asset($im['path']) ?>" alt="">
                            <label><input type="checkbox" name="remove_image_ids[]" value="<?= (int) $im['id'] ?>"> Remove</label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="item-sec">
        <div class="item-toggles">
            <button type="button" class="item-tog <?= $itemType !== 'variants' ? 'on' : '' ?>" data-item-type="single">Single Item</button>
            <button type="button" class="item-tog <?= $itemType === 'variants' ? 'on' : '' ?>" data-item-type="variants">Contains Variants</button>
        </div>
        <input type="hidden" name="item_type" id="item_type" value="<?= e($itemType === 'variants' ? 'variants' : 'single') ?>">
        <div class="item-2">
            <div class="item-row">
                <label class="item-label req" for="unit">Unit*</label>
                <select class="item-select" id="unit" name="unit">
                    <?php foreach ($units as $u): ?>
                        <option value="<?= e($u) ?>" <?= product_form_val('unit', 'pcs') === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="item-row single-only">
                <label class="item-label" for="sku">SKU</label>
                <input class="item-input" type="text" id="sku" name="sku" value="<?= e(product_form_val('sku')) ?>" style="text-transform:uppercase">
                <?php if (!empty($errors['sku'])): ?><div class="item-err"><?= e($errors['sku']) ?></div><?php endif; ?>
            </div>
        </div>
        <div id="identWrap" class="single-only">
            <?php foreach ($identifiers as $ident): ?>
                <div class="item-ident">
                    <input class="item-input" type="text" name="identifiers[]" value="<?= e((string) $ident) ?>" placeholder="UPC / EAN / ISBN">
                    <button type="button" class="ident-remove" aria-label="Remove">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="item-link single-only" id="addIdent">+ Add Identifier</button>

        <!-- Variations Section (visible only in "Contains Variants" mode) -->
        <div class="variations-sec variants-only" id="variationsSec" style="<?= $itemType !== 'variants' ? 'display:none;' : '' ?>">
            <div class="item-sec-title">Variations</div>
            <div class="variation-attrs-wrap" id="attrsWrap">
                <?php if (!empty($variantAttrs)): ?>
                    <?php foreach ($variantAttrs as $ai => $attr): ?>
                    <div class="variation-attr-row" data-attr-index="<?= $ai ?>">
                        <div>
                            <label class="item-label req">Attribute*</label>
                            <select class="item-select attr-name-select" name="attr_name[<?= $ai ?>]">
                                <option value="">Select attribute</option>
                                <?php foreach ($variantAttrNames as $van): ?>
                                    <option value="<?= e($van) ?>" <?= $attr['name'] === $van ? 'selected' : '' ?>><?= e($van) ?></option>
                                <?php endforeach; ?>
                                <?php if (!in_array($attr['name'], $variantAttrNames, true) && $attr['name'] !== ''): ?>
                                    <option value="<?= e($attr['name']) ?>" selected><?= e($attr['name']) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="item-label req">Options*</label>
                            <input type="hidden" name="attr_options[<?= $ai ?>]" class="attr-options-hidden" value="<?= e(implode(',', $attr['options'])) ?>">
                            <div class="tag-input-wrap" data-attr="<?= $ai ?>">
                                <?php foreach ($attr['options'] as $opt): ?>
                                    <span class="tag-chip"><?= e($opt) ?><button type="button" class="tag-remove">&times;</button></span>
                                <?php endforeach; ?>
                                <input type="text" class="tag-input-field" placeholder="Type and press Enter">
                            </div>
                        </div>
                        <button type="button" class="attr-remove-btn" title="Remove attribute">&times;</button>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="variation-attr-row" data-attr-index="0">
                        <div>
                            <label class="item-label req">Attribute*</label>
                            <select class="item-select attr-name-select" name="attr_name[0]">
                                <option value="">eg: color</option>
                                <?php foreach ($variantAttrNames as $van): ?>
                                    <option value="<?= e($van) ?>"><?= e($van) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="item-label req">Options*</label>
                            <input type="hidden" name="attr_options[0]" class="attr-options-hidden" value="">
                            <div class="tag-input-wrap" data-attr="0">
                                <input type="text" class="tag-input-field" placeholder="Type and press Enter">
                            </div>
                        </div>
                        <button type="button" class="attr-remove-btn" title="Remove attribute">&times;</button>
                    </div>
                <?php endif; ?>
            </div>
            <button type="button" class="add-attr-link" id="addAttrBtn">⊕ Add more attributes</button>

            <div class="variant-table-wrap" id="variantTableWrap" style="<?= empty($existingVariants) ? 'display:none;' : '' ?>">
                <div style="font-weight:700;font-size:13.5px;color:#1e293b;margin:16px 0 10px;">Variant Combinations & Pricing</div>
                <table class="variant-table">
                    <thead>
                        <tr>
                            <th>Variant</th>
                            <th>SKU</th>
                            <th>Selling Price (₹)</th>
                            <th>Cost Price (₹)</th>
                            <th>Stock</th>
                        </tr>
                    </thead>
                    <tbody id="variantTableBody">
                        <?php foreach ($existingVariants as $vi => $ev): ?>
                        <tr data-combo="<?= e((string)($ev['attribute_values'] ?? '')) ?>">
                            <td class="variant-name-cell"><?= e((string) $ev['variant_name']) ?></td>
                            <td><input type="text" name="variant_sku[<?= $vi ?>]" value="<?= e((string) $ev['sku']) ?>" style="text-transform:uppercase"></td>
                            <td><input type="number" step="0.01" min="0" name="variant_selling_price[<?= $vi ?>]" value="<?= e((string) $ev['selling_price']) ?>" placeholder="0.00"></td>
                            <td><input type="number" step="0.01" min="0" name="variant_cost_price[<?= $vi ?>]" value="<?= e((string) $ev['cost_price']) ?>" placeholder="0.00"></td>
                            <td><input type="number" min="0" name="variant_stock[<?= $vi ?>]" value="<?= e((string) ($ev['stock_quantity'] ?? 0)) ?>" placeholder="0"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <div class="item-sec">
        <div class="item-sec-title">Item Description</div>
        <textarea class="item-textarea" name="description" placeholder=""><?= e(product_form_val('description')) ?></textarea>
    </div>

    <div class="item-sec">
        <label class="item-check-title"><input type="checkbox" name="sales_enabled" value="1" <?= $salesOn ? 'checked' : '' ?> data-toggle="#salesBlock"> Sales Information</label>
        <div id="salesBlock" class="<?= $salesOn ? '' : 'item-hidden' ?>">
            <div class="item-2 single-only">
                <div class="item-row">
                    <label class="item-label req">Selling Price*</label>
                    <div class="item-prefix"><span>INR</span><input class="item-input" type="number" step="0.01" min="0" name="selling_price" value="<?= e(product_form_val('selling_price', '0.00')) ?>"></div>
                    <?php if (!empty($errors['selling_price'])): ?><div class="item-err"><?= e($errors['selling_price']) ?></div><?php endif; ?>
                </div>
                <div class="item-row">
                    <label class="item-label">MRP</label>
                    <input class="item-input" type="number" step="0.01" min="0" name="mrp" value="<?= e(product_form_val('mrp')) ?>">
                </div>
            </div>
            <div class="item-2">
                <div class="item-row">
                    <label class="item-label req">Account*</label>
                    <select class="item-select" name="sales_account">
                        <?php foreach ($salesAccounts as $a): ?>
                            <option <?= product_form_val('sales_account', 'Sales') === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="item-row">
                    <label class="item-label">Description</label>
                    <textarea class="item-textarea" name="sales_description"><?= e(product_form_val('sales_description')) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="item-sec">
        <label class="item-check-title"><input type="checkbox" name="purchase_enabled" value="1" <?= $purchaseOn ? 'checked' : '' ?> data-toggle="#purchaseBlock"> Purchase Information</label>
        <div id="purchaseBlock" class="<?= $purchaseOn ? '' : 'item-hidden' ?>">
            <div class="item-2">
                <div class="item-row single-only">
                    <label class="item-label req">Cost Price*</label>
                    <div class="item-prefix"><span>INR</span><input class="item-input" type="number" step="0.01" min="0" name="cost_price" value="<?= e(product_form_val('cost_price', '0.00')) ?>"></div>
                </div>
                <div class="item-row">
                    <label class="item-label req">Account*</label>
                    <select class="item-select" name="purchase_account">
                        <?php foreach ($purchaseAccounts as $a): ?>
                            <option <?= product_form_val('purchase_account', 'Cost of Goods Sold') === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="item-2">
                <div class="item-row">
                    <label class="item-label">Description</label>
                    <textarea class="item-textarea" name="purchase_description"><?= e(product_form_val('purchase_description')) ?></textarea>
                </div>
                <div class="item-row">
                    <label class="item-label">Preferred Vendor</label>
                    <select class="item-select" name="preferred_vendor_id">
                        <option value="">Select a vendor</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int) $v['id'] ?>" <?= product_form_val('preferred_vendor_id') === (string) $v['id'] ? 'selected' : '' ?>><?= e((string) $v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="item-sec" id="taxRatesBlock">
        <div class="item-sec-title">Default Tax Rates</div>
        <div class="item-2">
            <div class="item-row">
                <label class="item-label">Intra State Tax Rate</label>
                <select class="item-select" name="intra_tax_rate_id">
                    <?php foreach ($gstRates as $tr): ?>
                        <option value="<?= (int) $tr['id'] ?>" <?= $defaultIntra === (string) $tr['id'] ? 'selected' : '' ?>><?= e((string) $tr['name']) ?> (<?= e((string) $tr['rate']) ?>%)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="item-row">
                <label class="item-label">Inter State Tax Rate</label>
                <select class="item-select" name="inter_tax_rate_id">
                    <?php foreach ($igstRates as $tr): ?>
                        <option value="<?= (int) $tr['id'] ?>" <?= $defaultInter === (string) $tr['id'] ? 'selected' : '' ?>><?= e((string) $tr['name']) ?> (<?= e((string) $tr['rate']) ?>%)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="item-sec" id="inventorySec">
        <label class="item-check-title"><input type="checkbox" name="track_inventory" value="1" <?= $trackOn ? 'checked' : '' ?> data-toggle="#inventoryBlock"> Track Inventory for this item</label>
        <p class="item-hint">You cannot enable/disable inventory tracking once you've created transactions for this item.</p>
        <div id="inventoryBlock" class="<?= $trackOn ? '' : 'item-hidden' ?>">
            <div class="item-2">
                <div class="item-row">
                    <label class="item-label req">Inventory Account*</label>
                    <select class="item-select" name="inventory_account">
                        <option value="">Select an account</option>
                        <?php foreach ($inventoryAccounts as $a): ?>
                            <option <?= product_form_val('inventory_account') === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="item-row">
                    <label class="item-label req">Inventory Valuation Method*</label>
                    <select class="item-select" name="inventory_valuation">
                        <option value="fifo" <?= product_form_val('inventory_valuation', 'fifo') === 'fifo' ? 'selected' : '' ?>>FIFO (First In, First Out)</option>
                        <option value="wac" <?= product_form_val('inventory_valuation') === 'wac' ? 'selected' : '' ?>>Weighted Average</option>
                    </select>
                </div>
            </div>
            <div class="item-2">
                <div class="item-row">
                    <label class="item-label">Reorder Point</label>
                    <input class="item-input" type="number" min="0" name="reorder_point" value="<?= e(product_form_val('reorder_point', product_form_val('low_stock_threshold', '5'))) ?>">
                </div>
                <?php if (!$isEdit): ?>
                <div class="item-row single-only">
                    <label class="item-label">Opening Stock</label>
                    <input class="item-input" type="number" min="0" name="initial_stock" value="<?= e(product_form_val('initial_stock', '0')) ?>">
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="item-sec">
        <div class="item-sec-title">Cancellation and Returns</div>
        <label class="item-label">Returnable Item</label>
        <div class="item-radio">
            <label><input type="radio" name="returnable" value="1" <?= $returnable ? 'checked' : '' ?>> Yes</label>
            <label><input type="radio" name="returnable" value="0" <?= !$returnable ? 'checked' : '' ?>> No</label>
        </div>
    </div>

    <div class="item-sec">
        <div class="item-sec-title">Fulfilment Details</div>
        <div class="item-2">
            <div class="item-row">
                <label class="item-label">Dimensions</label>
                <div class="item-dims">
                    <input class="item-input" type="number" step="0.01" min="0" name="dim_length" value="<?= e(product_form_val('dim_length')) ?>" placeholder="L">
                    <span>×</span>
                    <input class="item-input" type="number" step="0.01" min="0" name="dim_width" value="<?= e(product_form_val('dim_width')) ?>" placeholder="W">
                    <span>×</span>
                    <input class="item-input" type="number" step="0.01" min="0" name="dim_height" value="<?= e(product_form_val('dim_height')) ?>" placeholder="H">
                    <select class="item-select" name="dim_unit">
                        <?php foreach (['cm', 'mm', 'in', 'm', 'ft'] as $u): ?>
                            <option <?= product_form_val('dim_unit', 'cm') === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="item-field-hint">(Length × Width × Height)</div>
            </div>
            <div class="item-row">
                <label class="item-label">Weight</label>
                <div class="item-weight">
                    <input class="item-input" type="number" step="0.001" min="0" name="weight" value="<?= e(product_form_val('weight')) ?>">
                    <select class="item-select" name="weight_unit">
                        <?php foreach (['kg', 'g', 'lb', 'oz'] as $u): ?>
                            <option <?= product_form_val('weight_unit', 'kg') === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="item-card" style="margin-top:16px;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;background:#f8fafc;">
        <div style="font-weight:700;font-size:14px;color:#0f172a;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            <span>Online Storefront Highlights</span>
        </div>
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;user-select:none;">
            <input type="checkbox" name="is_trending" value="1" <?= !empty(product_form_val('is_trending')) ? 'checked' : '' ?> style="width:18px;height:18px;margin-top:2px;accent-color:#083d30;cursor:pointer;">
            <div>
                <div style="font-weight:600;font-size:13.5px;color:#0f172a;">Show in "Top Trending Items" section</div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">Feature this product in the highlighted Top Trending section on your online store home page.</div>
            </div>
        </label>
    </div>

    <div class="item-row" style="max-width:280px;margin-top:12px">
        <label class="item-label">Status</label>
        <select class="item-select" name="status">
            <option value="active" <?= product_form_val('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= product_form_val('status') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>

    <div class="item-actions">
        <button class="item-save" type="submit">Save</button>
        <a class="item-cancel" href="<?= asset('products.php') ?>">Cancel</a>
    </div>
</div>

<script>
(function () {
    var sheet = document.getElementById('itemSheet');
    var attrNames = <?= json_encode($variantAttrNames) ?>;

    function preview(input, box) {
        input.addEventListener('change', function () {
            var f = this.files && this.files[0];
            if (!f) return;
            var url = URL.createObjectURL(f);
            var img = box.querySelector('img');
            if (!img) { img = document.createElement('img'); box.prepend(img); }
            img.src = url;
            var btn = box.querySelector('.item-drop-btn');
            if (btn) btn.style.display = 'none';
        });
    }
    document.querySelectorAll('.item-drop').forEach(function (box) {
        var input = box.querySelector('input[type=file]');
        if (input) preview(input, box);
    });
    document.querySelectorAll('[data-toggle]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var el = document.querySelector(this.getAttribute('data-toggle'));
            if (el) el.classList.toggle('item-hidden', !this.checked);
        });
    });

    // Item type toggle (Single Item / Contains Variants)
    function setItemType(type) {
        var isVar = (type === 'variants');
        document.querySelectorAll('.item-tog').forEach(function (b) {
            b.classList.toggle('on', b.getAttribute('data-item-type') === type);
        });
        var typeInput = document.getElementById('item_type');
        if (typeInput) typeInput.value = isVar ? 'variants' : 'single';
        if (sheet) {
            sheet.classList.toggle('item-type-variants', isVar);
        }
        document.querySelectorAll('.variants-only').forEach(function (el) {
            el.style.display = isVar ? '' : 'none';
        });
        document.querySelectorAll('.single-only').forEach(function (el) {
            el.style.display = isVar ? 'none' : '';
        });
        if (isVar) {
            rebuildVariantMatrix();
        }
    }

    document.querySelectorAll('.item-tog').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setItemType(this.getAttribute('data-item-type'));
        });
    });

    // Initialize toggle state immediately
    var initialType = document.getElementById('item_type') ? document.getElementById('item_type').value : 'single';
    setItemType(initialType || 'single');

    document.querySelectorAll('input[name=item_kind]').forEach(function (r) {
        r.addEventListener('change', function () {
            var service = this.value === 'service';
            var inv = document.getElementById('inventorySec');
            if (inv) inv.classList.toggle('item-hidden', service);
            if (service) {
                var cb = document.querySelector('input[name=track_inventory]');
                if (cb) { cb.checked = false; document.getElementById('inventoryBlock').classList.add('item-hidden'); }
            }
        });
    });

    // Identifiers
    var addIdentBtn = document.getElementById('addIdent');
    if (addIdentBtn) {
        addIdentBtn.addEventListener('click', function () {
            var wrap = document.getElementById('identWrap');
            var row = document.createElement('div');
            row.className = 'item-ident';
            row.innerHTML = '<input class="item-input" type="text" name="identifiers[]" placeholder="UPC / EAN / ISBN"><button type="button" class="ident-remove" aria-label="Remove">&times;</button>';
            wrap.appendChild(row);
        });
    }
    var identWrap = document.getElementById('identWrap');
    if (identWrap) {
        identWrap.addEventListener('click', function (e) {
            if (e.target.classList.contains('ident-remove')) e.target.parentElement.remove();
        });
    }

    // === VARIANT ATTRIBUTES & LIVE COMBINATIONS ===

    // Rebuild variant matrix combinations
    function rebuildVariantMatrix() {
        var tableWrap = document.getElementById('variantTableWrap');
        var tbody = document.getElementById('variantTableBody');
        if (!tableWrap || !tbody) return;

        // Collect attributes & options from DOM
        var attrList = [];
        document.querySelectorAll('.variation-attr-row').forEach(function (row) {
            var nameSel = row.querySelector('.attr-name-select');
            var attrName = nameSel ? nameSel.value.trim() : '';
            var chips = row.querySelectorAll('.tag-chip');
            var opts = [];
            chips.forEach(function (chip) {
                var txt = chip.firstChild.textContent.trim();
                if (txt) opts.push(txt);
            });
            if (attrName && opts.length > 0) {
                attrList.push({ name: attrName, options: opts });
            }
        });

        if (attrList.length === 0) {
            tableWrap.style.display = 'none';
            return;
        }

        // Generate combinations
        var combos = [{}];
        attrList.forEach(function (attr) {
            var nextCombos = [];
            combos.forEach(function (c) {
                attr.options.forEach(function (opt) {
                    var newC = Object.assign({}, c);
                    newC[attr.name] = opt;
                    nextCombos.push(newC);
                });
            });
            combos = nextCombos;
        });

        if (combos.length === 0) {
            tableWrap.style.display = 'none';
            return;
        }

        tableWrap.style.display = 'block';

        // Remember existing inputs in table by combo JSON key
        var existingData = {};
        tbody.querySelectorAll('tr').forEach(function (tr) {
            var key = tr.getAttribute('data-combo');
            if (key) {
                var skuInp = tr.querySelector('input[name^="variant_sku"]');
                var spInp = tr.querySelector('input[name^="variant_selling_price"]');
                var cpInp = tr.querySelector('input[name^="variant_cost_price"]');
                var stInp = tr.querySelector('input[name^="variant_stock"]');
                existingData[key] = {
                    sku: skuInp ? skuInp.value : '',
                    selling_price: spInp ? spInp.value : '',
                    cost_price: cpInp ? cpInp.value : '',
                    stock: stInp ? stInp.value : ''
                };
            }
        });

        tbody.innerHTML = '';
        var parentSku = (document.getElementById('sku') ? document.getElementById('sku').value.trim() : '') || 'SKU';

        combos.forEach(function (combo, i) {
            var comboKey = JSON.stringify(combo);
            var varName = Object.values(combo).join(' / ');
            var prev = existingData[comboKey] || {};

            var skuVal = prev.sku !== undefined ? prev.sku : '';
            var spVal = prev.selling_price !== undefined ? prev.selling_price : '';
            var cpVal = prev.cost_price !== undefined ? prev.cost_price : '';
            var stVal = prev.stock !== undefined ? prev.stock : '';

            var tr = document.createElement('tr');
            tr.setAttribute('data-combo', comboKey);
            tr.innerHTML =
                '<td class="variant-name-cell">' + varName + '</td>' +
                '<td><input type="text" name="variant_sku[' + i + ']" value="' + skuVal + '" placeholder="' + parentSku + '-V' + (i+1) + '" style="text-transform:uppercase"></td>' +
                '<td><input type="number" step="0.01" min="0" name="variant_selling_price[' + i + ']" value="' + spVal + '" placeholder="0.00"></td>' +
                '<td><input type="number" step="0.01" min="0" name="variant_cost_price[' + i + ']" value="' + cpVal + '" placeholder="0.00"></td>' +
                '<td><input type="number" min="0" name="variant_stock[' + i + ']" value="' + stVal + '" placeholder="0"></td>';
            tbody.appendChild(tr);
        });
    }

    // Sync tag chips to the hidden input
    function syncTags(tagWrap) {
        var hidden = tagWrap.parentElement.querySelector('.attr-options-hidden');
        if (!hidden) return;
        var tags = [];
        tagWrap.querySelectorAll('.tag-chip').forEach(function (chip) {
            var text = chip.firstChild.textContent.trim();
            if (text) tags.push(text);
        });
        hidden.value = tags.join(',');
        rebuildVariantMatrix();
    }

    // Add a tag chip
    function addTag(tagWrap, value) {
        value = value.trim();
        if (!value) return;
        // Check duplicate
        var existing = [];
        tagWrap.querySelectorAll('.tag-chip').forEach(function (chip) {
            existing.push(chip.firstChild.textContent.trim().toLowerCase());
        });
        if (existing.indexOf(value.toLowerCase()) >= 0) return;

        var chip = document.createElement('span');
        chip.className = 'tag-chip';
        chip.textContent = value;
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'tag-remove';
        removeBtn.innerHTML = '&times;';
        removeBtn.addEventListener('click', function () {
            chip.remove();
            syncTags(tagWrap);
        });
        chip.appendChild(removeBtn);

        var input = tagWrap.querySelector('.tag-input-field');
        tagWrap.insertBefore(chip, input);
        syncTags(tagWrap);
    }

    // Setup tag input behavior for a wrap
    function setupTagInput(tagWrap) {
        var input = tagWrap.querySelector('.tag-input-field');
        if (!input) return;

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addTag(tagWrap, this.value);
                this.value = '';
            }
            if (e.key === 'Backspace' && this.value === '') {
                var chips = tagWrap.querySelectorAll('.tag-chip');
                if (chips.length > 0) {
                    chips[chips.length - 1].remove();
                    syncTags(tagWrap);
                }
            }
        });

        input.addEventListener('blur', function () {
            if (this.value.trim()) {
                addTag(tagWrap, this.value);
                this.value = '';
            }
        });

        // Handle click on existing remove buttons (server-rendered)
        tagWrap.querySelectorAll('.tag-remove').forEach(function (btn) {
            btn.addEventListener('click', function () {
                this.parentElement.remove();
                syncTags(tagWrap);
            });
        });

        // Click on wrap focuses input
        tagWrap.addEventListener('click', function () {
            input.focus();
        });
    }

    // Initialize existing tag inputs
    document.querySelectorAll('.tag-input-wrap').forEach(setupTagInput);

    // Rebuild matrix when attribute name changes
    var attrsWrapEl = document.getElementById('attrsWrap');
    if (attrsWrapEl) {
        attrsWrapEl.addEventListener('change', function (e) {
            if (e.target.classList.contains('attr-name-select')) {
                rebuildVariantMatrix();
            }
        });
    }

    // Get next attribute index
    function getNextAttrIndex() {
        var rows = document.querySelectorAll('.variation-attr-row');
        var max = -1;
        rows.forEach(function (r) {
            var idx = parseInt(r.getAttribute('data-attr-index'), 10);
            if (idx > max) max = idx;
        });
        return max + 1;
    }

    // Build attribute name dropdown options HTML
    function buildAttrOptions() {
        var html = '<option value="">Select attribute</option>';
        attrNames.forEach(function (name) {
            html += '<option value="' + name + '">' + name + '</option>';
        });
        return html;
    }

    // Add more attributes
    var addAttrBtn = document.getElementById('addAttrBtn');
    if (addAttrBtn) {
        addAttrBtn.addEventListener('click', function () {
            var idx = getNextAttrIndex();
            var row = document.createElement('div');
            row.className = 'variation-attr-row';
            row.setAttribute('data-attr-index', idx);
            row.innerHTML =
                '<div>' +
                    '<label class="item-label req">Attribute*</label>' +
                    '<select class="item-select attr-name-select" name="attr_name[' + idx + ']">' +
                        buildAttrOptions() +
                    '</select>' +
                '</div>' +
                '<div>' +
                    '<label class="item-label req">Options*</label>' +
                    '<input type="hidden" name="attr_options[' + idx + ']" class="attr-options-hidden" value="">' +
                    '<div class="tag-input-wrap" data-attr="' + idx + '">' +
                        '<input type="text" class="tag-input-field" placeholder="Type and press Enter">' +
                    '</div>' +
                '</div>' +
                '<button type="button" class="attr-remove-btn" title="Remove attribute">&times;</button>';
            document.getElementById('attrsWrap').appendChild(row);
            setupTagInput(row.querySelector('.tag-input-wrap'));
        });
    }

    // Remove attribute row (event delegation)
    var attrsWrap = document.getElementById('attrsWrap');
    if (attrsWrap) {
        attrsWrap.addEventListener('click', function (e) {
            if (e.target.classList.contains('attr-remove-btn')) {
                var rows = document.querySelectorAll('.variation-attr-row');
                if (rows.length > 1) {
                    e.target.closest('.variation-attr-row').remove();
                    rebuildVariantMatrix();
                }
            }
        });
    }
})();
</script>

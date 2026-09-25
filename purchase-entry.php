<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/purchase_entry_db.php';
require_once __DIR__ . '/includes/barcode_helper.php';
require_once __DIR__ . '/includes/roles_db.php';

require_auth();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;
$canCost = purchase_entry_can_view_cost($user);

function purchase_entry_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = !empty($_POST['is_ajax']);
    $postAction = (string) ($_POST['action'] ?? '');
    $permEntryId = (int) ($_POST['entry_id'] ?? 0);
    purchase_entry_assert_action($postAction, $permEntryId, $ajax);
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if ($ajax) {
            purchase_entry_json(['success' => false, 'error' => 'Invalid session token. Please refresh.'], 403);
        }
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/purchase-entry.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_option') {
        $res = purchase_entry_add_option(
            (string) ($_POST['kind'] ?? ''),
            (string) ($_POST['name'] ?? ''),
            (int) ($_POST['category_id'] ?? 0)
        );
        purchase_entry_json($res, !empty($res['success']) ? 200 : 422);
    }

    if ($action === 'save_draft') {
        $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);
        if (!is_array($lines)) {
            $lines = [];
        }
        if (!$canCost) {
            foreach ($lines as $i => $line) {
                unset($lines[$i]['cost_price']);
            }
        }
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $res = save_purchase_entry([
            'vendor_id' => (int) ($_POST['vendor_id'] ?? 0),
            'vendor_name' => (string) ($_POST['vendor_name'] ?? ''),
            'supplier_invoice_no' => (string) ($_POST['supplier_invoice_no'] ?? ''),
            'purchase_date' => (string) ($_POST['purchase_date'] ?? ''),
            'received_date' => (string) ($_POST['received_date'] ?? ''),
            'warehouse_id' => (int) ($_POST['warehouse_id'] ?? 0),
            'notes' => (string) ($_POST['notes'] ?? ''),
        ], $lines, $entryId > 0 ? $entryId : null, $userId);
        purchase_entry_json($res, !empty($res['success']) ? 200 : 422);
    }

    if ($action === 'finalize') {
        $costs = json_decode((string) ($_POST['costs_json'] ?? '[]'), true);
        if (!is_array($costs)) {
            $costs = [];
        }
        $res = finalize_purchase_entry((int) ($_POST['entry_id'] ?? 0), $costs, $userId);
        purchase_entry_json($res, !empty($res['success']) ? 200 : 422);
    }

    if ($action === 'labels') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $res = purchase_entry_labels($entryId);
        if (!empty($res['success'])) {
            purchase_entry_mark_status($entryId, 'barcode_generated', $userId);
            foreach ($res['labels'] as $i => $label) {
                $res['labels'][$i]['svg'] = generate_code128_svg((string) $label['barcode'], 46, 1.4);
            }
        }
        purchase_entry_json($res, !empty($res['success']) ? 200 : 422);
    }

    if ($action === 'mark_printed') {
        $res = purchase_entry_mark_status((int) ($_POST['entry_id'] ?? 0), 'completed', $userId);
        purchase_entry_json($res, !empty($res['success']) ? 200 : 422);
    }

    purchase_entry_json(['success' => false, 'error' => 'Unknown action.'], 400);
} else {
    require_permission('purchases.purchase_entry.view');
}

ensure_purchase_entry_schema();

$filter = (string) ($_GET['status'] ?? '');
$entries = get_purchase_entries($filter);
$openId = (int) ($_GET['id'] ?? 0);
$openEntry = $openId > 0 ? get_purchase_entry_by_id($openId, null, $canCost) : null;
$catalog = purchase_entry_catalog();
$pageTitle = 'Purchase Entry';
$today = date('Y-m-d');

function pe_status_badge(string $status): array {
    return match (strtolower($status)) {
        'draft', 'pending_cost' => ['badge-warning', 'Pending cost'],
        'finalized' => ['badge-info', 'Finalized'],
        'barcode_generated' => ['badge-secondary', 'Barcode ready'],
        'completed' => ['badge-success', 'Completed'],
        default => ['badge-secondary', ucfirst(str_replace('_', ' ', $status))],
    };
}

$tabFilters = [
    '' => 'All',
    'pending_cost' => 'Pending cost',
    'finalized' => 'Finalized',
    'completed' => 'Completed',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .pe-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .pe-tab { padding: 8px 14px; border-radius: 999px; font-size: 13px; font-weight: 650; border: 1px solid #e2e8f0; background: #fff; color: #475569; text-decoration: none; }
        .pe-tab.active { background: var(--saas-primary, #2563eb); border-color: transparent; color: #fff; }
        .pe-num { font-family: ui-monospace, Consolas, monospace; font-weight: 700; color: var(--saas-navy-950, #0f172a); }
        .pe-screen { position: fixed; inset: 0; z-index: 4000; background: #f1f5f9; display: none; flex-direction: column; }
        .pe-screen.open { display: flex; }
        .pe-top { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 12px 20px 0; box-shadow: 0 1px 3px rgba(15,23,42,.06); }
        .pe-top-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 12px; }
        .pe-top-meta { font-size: 11px; font-weight: 800; letter-spacing: .08em; color: #64748b; text-transform: uppercase; }
        .pe-title-row { display: flex; align-items: center; gap: 10px; margin-top: 4px; flex-wrap: wrap; }
        .pe-title-row .pe-num { font-size: 20px; }
        .pe-wh-chip { font-size: 12px; font-weight: 650; color: #334155; background: #f1f5f9; border: 1px solid #e2e8f0; padding: 4px 10px; border-radius: 999px; }
        .pe-steps { display: flex; gap: 6px; flex-wrap: wrap; padding: 0 0 12px; }
        .pe-step { font-size: 11px; font-weight: 700; padding: 5px 10px; border-radius: 6px; background: #f8fafc; color: #94a3b8; border: 1px solid #e2e8f0; }
        .pe-step.done { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .pe-step.active { background: #eff6ff; color: #1d4ed8; border-color: #93c5fd; }
        .pe-body { flex: 1; overflow: auto; padding: 16px 20px 24px; }
        .pe-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
        .pe-card-title { font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 12px; }
        .pe-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px 14px; }
        .pe-field label { display: block; font-size: 11.5px; font-weight: 700; margin-bottom: 5px; color: #334155; }
        .pe-field label .req { color: #dc2626; }
        .pe-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
        .pe-table { width: 100%; border-collapse: collapse; min-width: <?= $canCost ? '1080' : '980' ?>px; }
        .pe-table th { position: sticky; top: 0; z-index: 2; background: #f8fafc; font-size: 10.5px; letter-spacing: .05em; text-transform: uppercase; color: #64748b; padding: 10px 8px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .pe-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .pe-table tr:hover td { background: #fafbfc; }
        .pe-table .form-control { padding: 7px 8px; font-size: 13px; min-height: 36px; }
        .pe-table .sku { font-family: ui-monospace, monospace; font-size: 12px; }
        .pe-money { display: flex; align-items: center; gap: 4px; }
        .pe-money span { font-size: 12px; font-weight: 700; color: #64748b; }
        .pe-lock { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: #64748b; font-weight: 700; padding: 6px 0; }
        .pe-margin { font-size: 12px; font-weight: 700; color: #047857; white-space: nowrap; }
        .pe-readonly .pe-table input, .pe-readonly .pe-table select, .pe-readonly .pe-grid input, .pe-readonly .pe-grid select { pointer-events: none; background: #f8fafc; color: #64748b; }
        .pe-readonly .remove { display: none; }
        .pe-bottom { background: #fff; border-top: 1px solid #e2e8f0; padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 -2px 8px rgba(15,23,42,.05); }
        .pe-totals { font-size: 13px; color: #475569; font-weight: 600; }
        .pe-totals strong { color: #0f172a; }
        .pe-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .pe-btn-loading { opacity: .65; pointer-events: none; }
        .pe-label-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: 12px; }
        .pe-label-card { border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; padding: 10px; text-align: center; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
        .pe-label-card:has(.lb-check:checked) { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,.15); }
        .pe-label-store { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #64748b; }
        .pe-label-meta { font-size: 12px; color: #334155; margin: 2px 0; }
        .pe-label-price { font-size: 14px; font-weight: 800; color: var(--saas-primary, #2563eb); margin: 4px 0; }
        .pe-label-copies { font-size: 11px; color: #64748b; margin-top: 4px; }
        .pe-hint { font-size: 12px; color: #64748b; margin-top: 6px; }
        @media (max-width: 960px) { .pe-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 640px) { .pe-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="app-layout">
    <?php require __DIR__ . '/includes/sidebar.php'; ?>
    <div class="app-main">
        <?php require __DIR__ . '/includes/header.php'; ?>
        <main class="dashboard-content">
            <div class="page-header-row">
                <div>
                    <h1 class="page-title">Purchase Entry</h1>
                    <p class="page-subtitle">Fast warehouse receiving: draft items, owner adds cost, then barcode and stock.</p>
                </div>
                <button type="button" class="header-btn" id="openNew">+ Create New Purchase</button>
            </div>
            <?php if ($flash = get_flash('success')): ?>
                <div class="saas-alert saas-alert-success" style="margin-bottom:16px;"><?= e($flash) ?></div>
            <?php endif; ?>
            <?php if ($flash = get_flash('error')): ?>
                <div class="saas-alert saas-alert-danger" style="margin-bottom:16px;"><?= e($flash) ?></div>
            <?php endif; ?>
            <?php if (empty($catalog['vendors'])): ?>
                <div class="saas-alert saas-alert-danger" style="margin-bottom:16px;">
                    No supplier found. Add one under <a href="<?= asset('vendors.php') ?>">Purchases → Vendors</a>, then create a purchase entry.
                </div>
            <?php endif; ?>
            <?php if (empty($catalog['categories'])): ?>
                <div class="saas-alert saas-alert-danger" style="margin-bottom:16px;">
                    No product category found. Create categories under <a href="<?= asset('categories.php') ?>">Inventory → Categories</a> before adding purchase lines.
                </div>
            <?php endif; ?>
            <?php if (empty($catalog['warehouses'])): ?>
                <div class="saas-alert saas-alert-danger" style="margin-bottom:16px;">
                    No warehouse found. Set up an outlet/warehouse under <a href="<?= asset('outlets.php') ?>">Inventory → Outlets</a>.
                </div>
            <?php endif; ?>
            <div class="pe-tabs">
                <?php foreach ($tabFilters as $key => $label): ?>
                    <a href="<?= asset('purchase-entry.php' . ($key !== '' ? '?status=' . urlencode($key) : '')) ?>" class="pe-tab <?= $filter === $key ? 'active' : '' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="section-card">
                <div class="section-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h2 class="section-title">Purchase list</h2>
                        <span style="font-size:13px; color:#64748b;"><?= count($entries) ?> record(s) — items appear here only after you click <strong>Save draft</strong> in the form.</span>
                    </div>
                    <button type="button" class="header-btn" id="openNewList">+ Create New Purchase</button>
                </div>
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead>
                        <tr>
                            <th>Purchase #</th>
                            <th>Supplier</th>
                            <th>Invoice</th>
                            <th>Received</th>
                            <th>Items</th>
                            <th>Qty</th>
                            <th>Status</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($entries === []): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:32px 16px; color:#64748b;">
                                    <div style="font-weight:700; color:#334155; margin-bottom:8px;">No purchases in this view</div>
                                    <div style="font-size:13px; margin-bottom:14px;">Open the form, add lines, then press <strong>Save draft</strong>. Adding rows alone does not save to the list.</div>
                                    <button type="button" class="header-btn" id="openNewEmpty">+ Create New Purchase</button>
                                </td>
                            </tr>
                        <?php else: foreach ($entries as $row):
                            [$badgeClass, $badgeLabel] = pe_status_badge((string) $row['status']);
                        ?>
                            <tr>
                                <td><span class="pe-num"><?= e($row['entry_number']) ?></span></td>
                                <td><?= e($row['vendor_name']) ?></td>
                                <td style="font-size:13px; color:#64748b;"><?= e($row['supplier_invoice_no'] ?? '—') ?></td>
                                <td style="font-size:13px;"><?= !empty($row['received_date']) ? e(date('d M Y', strtotime((string) $row['received_date']))) : '—' ?></td>
                                <td><?= (int) $row['item_count'] ?></td>
                                <td><span class="badge badge-info"><?= (int) $row['total_qty'] ?> pcs</span></td>
                                <td><span class="badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span></td>
                                <td style="text-align:right;">
                                    <a class="header-btn" style="padding:6px 12px; font-size:12px;" href="<?= asset('purchase-entry.php?id=' . (int) $row['id']) ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="pe-screen <?= $openEntry ? 'open' : '' ?>" id="peScreen">
    <div class="pe-top">
        <div class="pe-top-row">
            <div>
                <div class="pe-top-meta">Purchase entry</div>
                <div class="pe-title-row">
                    <span class="pe-num" id="peNumber"><?= e($openEntry['entry_number'] ?? 'New purchase') ?></span>
                    <span class="badge badge-warning" id="peStatusBadge">Pending cost</span>
                    <span class="pe-wh-chip" id="peWhChip">Warehouse</span>
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <span class="pe-hint" style="margin:0;">Ctrl+Enter adds a row</span>
                <button type="button" class="header-btn-secondary" id="closePe">← Back to list</button>
            </div>
        </div>
        <div class="pe-steps" id="peSteps" aria-hidden="true">
            <span class="pe-step" data-step="draft">Draft</span>
            <span class="pe-step" data-step="pending_cost">Pending cost</span>
            <span class="pe-step" data-step="finalized">Finalized</span>
            <span class="pe-step" data-step="barcode_generated">Barcode</span>
            <span class="pe-step" data-step="completed">Completed</span>
        </div>
    </div>
    <div class="pe-body" id="peBody">
        <div id="peError" class="saas-alert saas-alert-danger" style="display:none; margin-bottom:12px;"></div>
        <div class="pe-card">
            <div class="pe-card-title">Supplier &amp; receipt details</div>
            <div class="pe-grid">
                <div class="pe-field" style="grid-column: span 2;">
                    <label>Supplier <span class="req">*</span></label>
                    <input type="search" id="vendorFilter" class="form-control" placeholder="Search or type supplier name…" style="margin-bottom:6px;" autocomplete="off">
                    <select id="vendorId" class="form-control" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($catalog['vendors'] as $v): ?>
                            <option value="<?= (int) $v['id'] ?>" data-phone="<?= e($v['phone'] ?? '') ?>" <?= (int) ($openEntry['vendor_id'] ?? 0) === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pe-field">
                    <label>Supplier contact</label>
                    <input id="vendorPhone" class="form-control" readonly placeholder="Auto-filled">
                </div>
                <div class="pe-field">
                    <label>Supplier invoice <span class="req">*</span> (for finalize)</label>
                    <input id="invoiceNo" class="form-control" value="<?= e($openEntry['supplier_invoice_no'] ?? '') ?>" placeholder="e.g. INV-8842">
                </div>
                <div class="pe-field">
                    <label>Warehouse <span class="req">*</span></label>
                    <select id="warehouseId" class="form-control"></select>
                </div>
                <div class="pe-field">
                    <label>Purchase date</label>
                    <input type="date" id="purchaseDate" class="form-control" value="<?= e($openEntry['purchase_date'] ?? $today) ?>">
                </div>
                <div class="pe-field">
                    <label>Received date</label>
                    <input type="date" id="receivedDate" class="form-control" value="<?= e($openEntry['received_date'] ?? $today) ?>">
                </div>
                <div class="pe-field" style="grid-column: span 2;">
                    <label>Notes</label>
                    <input id="notes" class="form-control" value="<?= e($openEntry['notes'] ?? '') ?>" placeholder="Optional reference for warehouse">
                </div>
            </div>
        </div>
        <div class="pe-card" style="padding-bottom:10px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:8px; flex-wrap:wrap;">
                <div class="pe-card-title" style="margin:0;">Product lines</div>
                <button type="button" class="header-btn" id="addItem" style="padding:8px 14px;">+ Add item</button>
            </div>
            <div class="pe-table-wrap">
                <table class="pe-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Category</th>
                        <th>SKU</th>
                        <th>Colour</th>
                        <th>Sub-category</th>
                        <th>Size</th>
                        <th>Selling</th>
                        <th>Cost</th>
                        <?php if ($canCost): ?><th>Margin</th><?php endif; ?>
                        <th>Qty</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody id="lineBody"></tbody>
                </table>
            </div>
        </div>
        <div id="labelPreview" class="pe-card" style="display:none;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                <div class="pe-card-title" style="margin:0;">Barcode labels</div>
                <div class="pe-actions">
                    <button type="button" class="header-btn" id="printAll">Print all</button>
                    <button type="button" class="header-btn-secondary" id="printSelected">Print selected</button>
                </div>
            </div>
            <div class="pe-label-grid" id="labelsGrid"></div>
        </div>
    </div>
    <div class="pe-bottom">
        <div class="pe-totals" id="peTotals">0 items · 0 pcs · <strong>₹0.00</strong> selling</div>
        <div class="pe-actions">
            <button type="button" class="header-btn-secondary" id="saveDraft">Save draft</button>
            <?php if ($canCost): ?>
                <button type="button" class="header-btn" id="finalizeBtn">Finalize purchase</button>
            <?php endif; ?>
            <button type="button" class="header-btn-secondary" id="barcodeBtn" style="display:none;">Generate barcode</button>
        </div>
    </div>
</div>

<script>
const PE = <?= json_encode([
    'csrf' => generate_csrf_token(),
    'canCost' => $canCost,
    'catalog' => $catalog,
    'entry' => $openEntry,
    'apiUrl' => asset('purchase-entry.php'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

const screen = document.getElementById('peScreen');
const peBody = document.getElementById('peBody');
const lineBody = document.getElementById('lineBody');
let entryId = PE.entry ? Number(PE.entry.id) : 0;
let status = PE.entry ? PE.entry.status : 'pending_cost';
let labelCache = null;

const STATUS_META = {
    draft: { badge: 'badge-warning', label: 'Pending cost' },
    pending_cost: { badge: 'badge-warning', label: 'Pending cost' },
    finalized: { badge: 'badge-info', label: 'Finalized' },
    barcode_generated: { badge: 'badge-secondary', label: 'Barcode ready' },
    completed: { badge: 'badge-success', label: 'Completed' },
};
const STEP_ORDER = ['draft', 'pending_cost', 'finalized', 'barcode_generated', 'completed'];

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function showError(msg) {
    const box = document.getElementById('peError');
    box.style.display = msg ? 'block' : 'none';
    box.textContent = msg || '';
    if (msg) box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function fillSelect(sel, rows, placeholder) {
    const current = sel.value;
    sel.innerHTML = '<option value="">' + esc(placeholder) + '</option>' + rows.map(r => '<option value="' + esc(r.value) + '">' + esc(r.label) + '</option>').join('');
    if (current) sel.value = current;
}
function isLocked() {
    return ['finalized', 'barcode_generated', 'completed'].includes(status);
}
function filterVendorSelect() {
    const q = document.getElementById('vendorFilter').value.toLowerCase().trim();
    const sel = document.getElementById('vendorId');
    [...sel.options].forEach((opt, i) => {
        if (i === 0) return;
        const show = q === '' || opt.text.toLowerCase().includes(q);
        opt.hidden = !show;
        opt.disabled = !show;
    });
}
function supplierInputReady() {
    const sel = document.getElementById('vendorId');
    if (sel.value) return true;
    const typed = document.getElementById('vendorFilter').value.trim();
    if (!typed) return false;
    const exact = [...sel.options].find((opt, i) => i > 0 && !opt.disabled && opt.text.trim().toLowerCase() === typed.toLowerCase());
    if (exact) {
        sel.value = exact.value;
        syncPhone();
        return true;
    }
    return true;
}
function syncPhone() {
    const sel = document.getElementById('vendorId');
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('vendorPhone').value = opt ? (opt.getAttribute('data-phone') || '') : '';
}
function updateWhChip() {
    const wid = document.getElementById('warehouseId').value;
    const w = PE.catalog.warehouses.find(x => String(x.id) === String(wid));
    document.getElementById('peWhChip').textContent = w ? w.name : 'Warehouse';
}
function renderSteps() {
    const idx = Math.max(0, STEP_ORDER.indexOf(status === 'draft' ? 'pending_cost' : status));
    document.querySelectorAll('.pe-step').forEach(el => {
        const step = el.getAttribute('data-step');
        const si = STEP_ORDER.indexOf(step);
        el.classList.remove('done', 'active');
        if (si < idx) el.classList.add('done');
        if (si === idx) el.classList.add('active');
    });
    const meta = STATUS_META[status] || STATUS_META.pending_cost;
    const badge = document.getElementById('peStatusBadge');
    badge.className = 'badge ' + meta.badge;
    badge.textContent = meta.label;
}
function renderHeader() {
    fillSelect(document.getElementById('warehouseId'), PE.catalog.warehouses.map(w => ({value: w.id, label: w.name})), 'Select warehouse');
    if (PE.entry) {
        document.getElementById('warehouseId').value = PE.entry.warehouse_id;
        document.getElementById('vendorId').value = PE.entry.vendor_id || '';
        const vSel = document.getElementById('vendorId');
        const vOpt = vSel.options[vSel.selectedIndex];
        document.getElementById('vendorFilter').value = (vOpt && vSel.value) ? vOpt.text.trim() : '';
    } else {
        const central = PE.catalog.warehouses.find(w => /central/i.test(w.name) || w.code === 'WH-CENTRAL');
        if (central) document.getElementById('warehouseId').value = central.id;
    }
    syncPhone();
    updateWhChip();
    renderSteps();
    const locked = isLocked();
    peBody.classList.toggle('pe-readonly', locked);
    document.getElementById('saveDraft').style.display = locked ? 'none' : '';
    const fin = document.getElementById('finalizeBtn');
    if (fin) fin.style.display = (!PE.canCost || locked || !entryId) ? 'none' : '';
    document.getElementById('barcodeBtn').style.display = locked ? '' : 'none';
    document.getElementById('addItem').style.display = locked ? 'none' : '';
    updateTotals();
}
function sizesFor(categoryId) {
    return PE.catalog.sizes.filter(s => !s.category_id || String(s.category_id) === '0' || String(s.category_id) === String(categoryId));
}
function rowMargin(tr) {
    if (!PE.canCost) return;
    const cell = tr.querySelector('.margin-cell');
    if (!cell) return;
    const sell = parseFloat(tr.querySelector('.sell').value) || 0;
    const cost = parseFloat(tr.querySelector('.cost')?.value) || 0;
    const m = sell - cost;
    cell.textContent = '₹' + m.toFixed(2);
    cell.style.color = m >= 0 ? '#047857' : '#b91c1c';
}
function bindRowEvents(tr) {
    tr.querySelectorAll('.sell, .cost, .qty').forEach(el => el.addEventListener('input', () => { rowMargin(tr); updateTotals(); }));
    tr.querySelector('.remove').addEventListener('click', () => { tr.remove(); renumber(); updateTotals(); });
}
function addRow(data) {
    data = data || {};
    const tr = document.createElement('tr');
    const costCell = PE.canCost
        ? '<div class="pe-money"><span>₹</span><input class="form-control cost" type="number" min="0" step="0.01" value="' + esc(data.cost_price ?? '') + '"></div>'
        : '<span class="pe-lock" title="Only owner can view cost">🔒 Restricted</span>';
    const marginCol = PE.canCost ? '<td class="margin-cell pe-margin">—</td>' : '';
    tr.innerHTML = `
        <td class="line-no pe-num"></td>
        <td><select class="form-control cat"></select></td>
        <td><input class="form-control sku" type="text" autocomplete="off" spellcheck="false" placeholder="SKU" value="${esc(data.sku || '')}" title="Enter SKU"></td>
        <td><select class="form-control colour"></select></td>
        <td><select class="form-control sub"></select></td>
        <td><select class="form-control size"></select></td>
        <td><div class="pe-money"><span>₹</span><input class="form-control sell" type="number" min="0" step="0.01" value="${esc(data.selling_price ?? '')}"></div></td>
        <td>${costCell}</td>
        ${marginCol}
        <td><input class="form-control qty" type="number" min="1" step="1" value="${esc(data.quantity || '')}"></td>
        <td><button type="button" class="header-btn-secondary remove" style="padding:5px 10px; font-size:12px;">Remove</button></td>`;
    lineBody.appendChild(tr);
    const cat = tr.querySelector('.cat');
    fillSelect(cat, PE.catalog.categories.map(c => ({value: c.id, label: c.name})), 'Category');
    fillSelect(tr.querySelector('.colour'), PE.catalog.colours.map(c => ({value: c, label: c})), 'Colour');
    if (data.category_id) cat.value = data.category_id;
    onCategory(tr);
    if (data.subcategory_id) tr.querySelector('.sub').value = data.subcategory_id;
    if (data.colour) tr.querySelector('.colour').value = data.colour;
    if (data.size_label) tr.querySelector('.size').value = data.size_label;
    cat.addEventListener('change', () => onCategory(tr));
    const skuIn = tr.querySelector('.sku');
    skuIn.addEventListener('blur', () => { skuIn.value = skuIn.value.trim().toUpperCase(); });
    bindRowEvents(tr);
    rowMargin(tr);
    renumber();
    updateTotals();
}
function onCategory(tr) {
    const id = tr.querySelector('.cat').value;
    const cat = PE.catalog.categories.find(c => String(c.id) === String(id));
    const subs = cat ? cat.subcategories : [];
    fillSelect(tr.querySelector('.sub'), subs.map(s => ({value: s.id, label: s.name})), subs.length ? 'Sub-category' : 'None');
    fillSelect(tr.querySelector('.size'), sizesFor(id).map(s => ({value: s.name, label: s.name})), 'Size');
}
function renumber() {
    [...lineBody.rows].forEach((tr, i) => tr.querySelector('.line-no').textContent = String(i + 1).padStart(3, '0'));
}
function updateTotals() {
    let items = lineBody.rows.length;
    let pcs = 0;
    let selling = 0;
    [...lineBody.rows].forEach(tr => {
        const q = parseInt(tr.querySelector('.qty').value, 10) || 0;
        const sp = parseFloat(tr.querySelector('.sell').value) || 0;
        pcs += q;
        selling += sp * q;
    });
    document.getElementById('peTotals').innerHTML = items + ' item' + (items === 1 ? '' : 's') + ' · <strong>' + pcs + '</strong> pcs · <strong>₹' + selling.toFixed(2) + '</strong> selling';
}
function collectLines() {
    return [...lineBody.rows].map(tr => {
        const line = {
            category_id: tr.querySelector('.cat').value,
            subcategory_id: tr.querySelector('.sub').value,
            sku: tr.querySelector('.sku').value,
            colour: tr.querySelector('.colour').value,
            size: tr.querySelector('.size').value,
            selling_price: tr.querySelector('.sell').value,
            quantity: tr.querySelector('.qty').value
        };
        if (PE.canCost && tr.querySelector('.cost')) line.cost_price = tr.querySelector('.cost').value;
        return line;
    });
}
function headerBody() {
    const body = new FormData();
    body.append('csrf_token', PE.csrf);
    body.append('is_ajax', '1');
    const vendorSel = document.getElementById('vendorId');
    const vendorId = vendorSel.value;
    const vendorTyped = document.getElementById('vendorFilter').value.trim();
    body.append('vendor_id', vendorId);
    if (!vendorId && vendorTyped) body.append('vendor_name', vendorTyped);
    body.append('supplier_invoice_no', document.getElementById('invoiceNo').value);
    body.append('purchase_date', document.getElementById('purchaseDate').value);
    body.append('received_date', document.getElementById('receivedDate').value);
    body.append('warehouse_id', document.getElementById('warehouseId').value);
    body.append('notes', document.getElementById('notes').value);
    if (entryId) body.append('entry_id', String(entryId));
    return body;
}
function setBtnLoading(btn, loading, label) {
    if (!btn) return;
    if (loading) {
        btn.dataset.orig = btn.textContent;
        btn.textContent = label || 'Please wait…';
        btn.classList.add('pe-btn-loading');
    } else {
        btn.textContent = btn.dataset.orig || btn.textContent;
        btn.classList.remove('pe-btn-loading');
    }
}
function renderLabels(data) {
    labelCache = data;
    const box = document.getElementById('labelPreview');
    const grid = document.getElementById('labelsGrid');
    box.style.display = 'block';
    grid.innerHTML = data.labels.map((lb, i) => `
        <label class="pe-label-card">
            <input type="checkbox" class="lb-check" data-i="${i}" checked style="margin-bottom:6px;">
            <div class="pe-label-store">${esc(lb.store)}</div>
            <div class="pe-label-meta">SKU: ${esc(lb.sku)}</div>
            <div class="pe-label-meta">${esc(lb.colour)} / ${esc(lb.size)}</div>
            <div class="pe-label-price">₹${esc(lb.price)}</div>
            <div>${lb.svg}</div>
            <div class="pe-label-copies">${lb.copies} label(s)</div>
        </label>`).join('');
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function printLabels(onlyChecked) {
    if (!labelCache) return;
    const chosen = [...document.querySelectorAll('.lb-check')].filter(el => !onlyChecked || el.checked).map(el => labelCache.labels[Number(el.dataset.i)]);
    const win = window.open('', 'pe-print');
    const html = chosen.map(lb => Array.from({length: lb.copies}, () =>
        '<div style="width:48mm;border:1px solid #ccc;padding:6px;text-align:center;page-break-inside:avoid;margin:4px;display:inline-block;">' +
        '<div style="font-size:10px;font-weight:800;">' + esc(lb.store) + '</div>' +
        '<div>SKU: ' + esc(lb.sku) + '</div><div>' + esc(lb.colour) + ' / ' + esc(lb.size) + '</div>' +
        '<div style="font-weight:800;">₹' + esc(lb.price) + '</div>' + lb.svg + '</div>'
    ).join('')).join('');
    win.document.write('<html><head><title>Barcodes</title></head><body style="font-family:sans-serif;">' + html + '<script>window.print()<\/script></body></html>');
    win.document.close();
    const done = new FormData();
    done.append('csrf_token', PE.csrf);
    done.append('is_ajax', '1');
    done.append('action', 'mark_printed');
    done.append('entry_id', String(entryId));
    fetch(PE.apiUrl, {method:'POST', body: done});
}

function openNewPurchaseForm() {
    if (!PE.catalog.categories.length) {
        alert('Add at least one category under Inventory → Categories first.');
        return;
    }
    entryId = 0; status = 'pending_cost'; PE.entry = null; labelCache = null;
    document.getElementById('peNumber').textContent = 'New purchase';
    document.getElementById('invoiceNo').value = '';
    document.getElementById('notes').value = '';
    document.getElementById('vendorFilter').value = '';
    document.getElementById('vendorId').value = '';
    document.getElementById('labelPreview').style.display = 'none';
    lineBody.innerHTML = '';
    showError('');
    filterVendorSelect();
    addRow();
    renderHeader();
    screen.classList.add('open');
}
document.getElementById('openNew').addEventListener('click', openNewPurchaseForm);
document.getElementById('openNewList').addEventListener('click', openNewPurchaseForm);
const openNewEmpty = document.getElementById('openNewEmpty');
if (openNewEmpty) openNewEmpty.addEventListener('click', openNewPurchaseForm);
document.getElementById('closePe').addEventListener('click', () => {
    screen.classList.remove('open');
    window.location.href = PE.apiUrl;
});
document.getElementById('addItem').addEventListener('click', () => addRow());
document.getElementById('vendorFilter').addEventListener('input', filterVendorSelect);
document.getElementById('vendorId').addEventListener('change', syncPhone);
document.getElementById('warehouseId').addEventListener('change', updateWhChip);
document.addEventListener('keydown', e => {
    if (!screen.classList.contains('open') || isLocked()) return;
    if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); addRow(); }
});
document.getElementById('saveDraft').addEventListener('click', () => {
    if (!supplierInputReady() || !document.getElementById('vendorFilter').value.trim()) {
        showError('Please select or enter a supplier name.');
        return;
    }
    const btn = document.getElementById('saveDraft');
    setBtnLoading(btn, true, 'Saving…');
    const body = headerBody();
    body.append('action', 'save_draft');
    body.append('lines_json', JSON.stringify(collectLines()));
    fetch(PE.apiUrl, {method:'POST', body})
        .then(async r => {
            let data = {};
            try { data = await r.json(); } catch (e) {}
            setBtnLoading(btn, false);
            if (!r.ok || !data.success) {
                showError(data.error || 'Could not save. Check supplier, category, colour, size, selling price, and quantity.');
                return;
            }
            window.location.href = PE.apiUrl + '?id=' + data.entry_id;
        })
        .catch(() => { setBtnLoading(btn, false); showError('Network error. Try again.'); });
});
const fin = document.getElementById('finalizeBtn');
if (fin) fin.addEventListener('click', () => {
    if (!entryId) { showError('Save the draft before finalizing.'); return; }
    if (!confirm('Finalize this purchase and add stock to the warehouse?')) return;
    setBtnLoading(fin, true, 'Finalizing…');
    const costs = [...lineBody.rows].map((tr, i) => ({
        line_no: i + 1,
        cost_price: tr.querySelector('.cost') ? tr.querySelector('.cost').value : ''
    }));
    const body = new FormData();
    body.append('csrf_token', PE.csrf);
    body.append('is_ajax', '1');
    body.append('action', 'finalize');
    body.append('entry_id', String(entryId));
    body.append('costs_json', JSON.stringify(costs));
    fetch(PE.apiUrl, {method:'POST', body})
        .then(r => r.json())
        .then(data => {
            setBtnLoading(fin, false);
            if (!data.success) { showError(data.error || 'Could not finalize.'); return; }
            window.location.href = PE.apiUrl + '?id=' + entryId;
        })
        .catch(() => { setBtnLoading(fin, false); showError('Network error. Try again.'); });
});
document.getElementById('barcodeBtn').addEventListener('click', () => {
    const btn = document.getElementById('barcodeBtn');
    setBtnLoading(btn, true, 'Loading…');
    const body = new FormData();
    body.append('csrf_token', PE.csrf);
    body.append('is_ajax', '1');
    body.append('action', 'labels');
    body.append('entry_id', String(entryId));
    fetch(PE.apiUrl, {method:'POST', body})
        .then(r => r.json())
        .then(data => {
            setBtnLoading(btn, false);
            if (!data.success) { showError(data.error || 'No barcode yet.'); return; }
            renderLabels(data);
        })
        .catch(() => { setBtnLoading(btn, false); showError('Network error.'); });
});
document.getElementById('printAll').addEventListener('click', () => printLabels(false));
document.getElementById('printSelected').addEventListener('click', () => printLabels(true));

renderHeader();
if (PE.entry && Array.isArray(PE.entry.lines) && PE.entry.lines.length) {
    PE.entry.lines.forEach(addRow);
    renderHeader();
} else if (screen.classList.contains('open')) {
    addRow();
}
</script>
</body>
</html>

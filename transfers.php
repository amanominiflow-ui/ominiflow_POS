<?php
/**
 * Stock Transfers Workflow Screen (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/outlets_db.php';
require_once __DIR__ . '/includes/products_db.php';
require_once __DIR__ . '/includes/orders_db.php';

require_auth();

$user = current_user();

if (isset($_GET['action']) && $_GET['action'] === 'get_transfer_details') {
    header('Content-Type: application/json');
    $trfId = (int)($_GET['id'] ?? 0);
    $trf = get_stock_transfer_by_id($trfId);
    if (!$trf) {
        echo json_encode(['success' => false, 'error' => 'Transfer not found']);
    } else {
        echo json_encode(['success' => true, 'transfer' => $trf]);
    }
    exit;
}

$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/transfers.php');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_transfer') {
            $src = (int)($_POST['source_warehouse_id'] ?? 0);
            $dst = (int)($_POST['dest_warehouse_id'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));

            $items = [];
            if (!empty($_POST['items']) && is_array($_POST['items'])) {
                foreach ($_POST['items'] as $it) {
                    $pid = (int)($it['product_id'] ?? 0);
                    $qty = (int)($it['quantity'] ?? 0);
                    $vid = !empty($it['variant_id']) ? (int)$it['variant_id'] : null;
                    if ($pid > 0 && $qty > 0) {
                        $items[] = [
                            'product_id' => $pid,
                            'variant_id' => $vid,
                            'quantity' => $qty,
                        ];
                    }
                }
            } elseif (!empty($_POST['product_id'])) {
                $pid = (int)$_POST['product_id'];
                $qty = max(1, (int)($_POST['quantity'] ?? 1));
                $vid = !empty($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
                if ($pid > 0) {
                    $items[] = [
                        'product_id' => $pid,
                        'variant_id' => $vid,
                        'quantity' => $qty,
                    ];
                }
            }

            if (empty($items)) {
                set_flash('error', 'Please add at least one product with quantity greater than zero.');
            } else {
                $res = create_stock_transfer($src, $dst, $items, $notes, (int)$user['id']);
                if ($res['success']) {
                    set_flash('success', "Stock Transfer #{$res['transfer_number']} created successfully with " . count($items) . " item line(s)!");
                } else {
                    set_flash('error', $res['error'] ?? 'Failed to create transfer.');
                }
            }
        } elseif ($action === 'approve_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = approve_stock_transfer($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Stock transfer approved.');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to approve transfer.');
            }
        } elseif ($action === 'pick_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = pick_stock_transfer($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Stock transfer marked as picked.');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to pick transfer.');
            }
        } elseif ($action === 'dispatch_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = dispatch_stock_transfer($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Stock transfer marked as dispatched.');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to dispatch transfer.');
            }
        } elseif ($action === 'ship_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = ship_stock_transfer_in_transit($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Stock is now in transit (source warehouse reduced; company total unchanged).');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to start in-transit.');
            }
        } elseif ($action === 'receive_transfer') {
            $tid = (int)($_POST['transfer_id'] ?? 0);
            $res = receive_stock_transfer($tid, (int)$user['id']);
            if ($res['success']) {
                set_flash('success', 'Transfer received at destination (warehouse moved; company total unchanged).');
            } else {
                set_flash('error', $res['error'] ?? 'Failed to receive transfer.');
            }
        }
        redirect(APP_URL . '/transfers.php');
    }
}

$transfers = get_stock_transfers(100);
$warehouses = get_warehouses();
$products = get_products();
$variantsByProduct = [];
try {
    $vStmt = get_db()->prepare('
        SELECT id, product_id, variant_name, attribute_values, sku, barcode, stock_quantity
        FROM product_variants
        WHERE business_id = :bid AND status = "active"
        ORDER BY id ASC
    ');
    $vStmt->execute(['bid' => current_business_id()]);
    foreach ($vStmt->fetchAll() as $v) {
        $attrs = parse_variant_size_colour($v);
        $pid = (int) $v['product_id'];
        $variantsByProduct[$pid][] = [
            'id' => (int) $v['id'],
            'name' => (string) $v['variant_name'],
            'sku' => (string) $v['sku'],
            'barcode' => (string) ($v['barcode'] ?? ''),
            'stock' => (int) $v['stock_quantity'],
            'size' => $attrs['size'],
            'colour' => $attrs['colour'],
        ];
    }
} catch (Throwable $e) {
    $variantsByProduct = [];
}

$transferableItems = [];
foreach ($products as $p) {
    $pid = (int) $p['id'];
    $vars = $variantsByProduct[$pid] ?? [];
    if (!empty($vars)) {
        foreach ($vars as $v) {
            $variantLabel = '';
            if (!empty($v['size']) && !empty($v['colour'])) {
                $variantLabel = $v['colour'] . ' / ' . $v['size'];
            } elseif (!empty($v['name'])) {
                $variantLabel = $v['name'];
            } else {
                $variantLabel = 'Variant #' . $v['id'];
            }
            $transferableItems[] = [
                'key' => $pid . '_' . $v['id'],
                'product_id' => $pid,
                'variant_id' => (int) $v['id'],
                'product_name' => (string) $p['name'],
                'variant_name' => $variantLabel,
                'display_name' => $p['name'] . ' (' . $variantLabel . ')',
                'sku' => (string) ($v['sku'] !== '' ? $v['sku'] : $p['sku']),
                'stock' => (int) ($v['stock'] ?? $p['stock_quantity']),
            ];
        }
    } else {
        $transferableItems[] = [
            'key' => $pid . '_0',
            'product_id' => $pid,
            'variant_id' => null,
            'product_name' => (string) $p['name'],
            'variant_name' => '',
            'display_name' => (string) $p['name'],
            'sku' => (string) $p['sku'],
            'stock' => (int) $p['stock_quantity'],
        ];
    }
}

$defaultSourceWarehouseId = !empty($warehouses[0]['id']) ? (int) $warehouses[0]['id'] : 0;
$defaultDestWarehouseId = !empty($warehouses[1]['id'])
    ? (int) $warehouses[1]['id']
    : (!empty($warehouses[0]['id']) && count($warehouses) > 1 ? (int) $warehouses[count($warehouses) - 1]['id'] : 0);
$canCreateTransfer = count($warehouses) >= 2;
$pageTitle = 'Warehouse Stock Transfers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="app-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <div class="page-header-row">
                    <div>
                        <h1 class="page-title">Warehouse Stock Transfers</h1>
                        <p class="page-subtitle">Requested &rarr; Approved &rarr; Picked &rarr; Dispatched &rarr; In Transit &rarr; Received. Destination receives with one button (no piece-by-piece scan). Company total stays the same; only warehouse rows move.</p>
                    </div>
                    <div>
                        <button type="button" onclick="document.getElementById('transferModal').style.display='flex'" class="header-btn">
                            + New Stock Transfer
                        </button>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success" style="margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger" style="margin-bottom: 20px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title">Stock Transfers History</h2>
                    </div>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Transfer #</th>
                                    <th>Origin &rarr; Target</th>
                                    <th>Total Units</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transfers)): ?>
                                    <tr><td colspan="6" style="text-align: center; padding: 20px; color: #64748b;">No stock transfers found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($transfers as $trf): ?>
                                        <tr>
                                            <td>
                                                <button type="button" onclick="openTransferDetails(<?= (int)$trf['id'] ?>)" style="background: none; border: none; padding: 0; font-family: monospace; font-weight: 700; color: var(--saas-primary); cursor: pointer; text-decoration: underline;" title="View items in this transfer">
                                                    <?= e($trf['transfer_number']) ?>
                                                </button>
                                            </td>
                                            <td style="font-size: 12.5px;">
                                                <span style="font-weight: 700; color: var(--saas-navy-950);"><?= e($trf['source_warehouse_name']) ?></span>
                                                <span style="color: var(--saas-slate-400); margin: 0 4px;">&rarr;</span>
                                                <span style="font-weight: 700; color: var(--saas-navy-950);"><?= e($trf['dest_warehouse_name']) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-info"><?= (int)$trf['total_units'] ?> units</span>
                                                <span style="font-size: 11.5px; color: var(--saas-slate-500); margin-left: 4px;">(<?= (int)$trf['total_items'] ?> item<?= (int)$trf['total_items'] === 1 ? '' : 's' ?>)</span>
                                            </td>
                                            <td style="font-size: 12px; color: var(--saas-slate-500);"><?= date('d M Y, h:i A', strtotime($trf['created_at'])) ?></td>
                                            <td>
                                                <?php
                                                $badge = 'badge-secondary';
                                                $st = (string) $trf['status'];
                                                if ($st === 'approved') {
                                                    $badge = 'badge-info';
                                                } elseif ($st === 'picked') {
                                                    $badge = 'badge-info';
                                                } elseif ($st === 'dispatched') {
                                                    $badge = 'badge-warning';
                                                } elseif ($st === 'in_transit') {
                                                    $badge = 'badge-warning';
                                                } elseif ($st === 'received') {
                                                    $badge = 'badge-success';
                                                }
                                                ?>
                                                <span class="badge <?= $badge ?>">
                                                    <?= strtoupper(str_replace('_', ' ', $st)) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right; white-space: nowrap;">
                                                <button type="button" onclick="openTransferDetails(<?= (int)$trf['id'] ?>)" class="header-btn-secondary" style="padding: 4px 8px; font-size: 11.5px; margin-right: 4px;" title="View transfer item details">
                                                    Items
                                                </button>
                                                <?php
                                                $actionBtn = null;
                                                if (in_array($st, ['draft', 'requested'], true)) {
                                                    $actionBtn = ['approve_transfer', 'Approve', '#2563eb'];
                                                } elseif ($st === 'approved') {
                                                    $actionBtn = ['pick_transfer', 'Mark Picked', '#4f46e5'];
                                                } elseif ($st === 'picked') {
                                                    $actionBtn = ['dispatch_transfer', 'Dispatch', '#d97706'];
                                                } elseif ($st === 'dispatched') {
                                                    $actionBtn = ['ship_transfer', 'In Transit', '#b45309'];
                                                } elseif ($st === 'in_transit') {
                                                    $actionBtn = ['receive_transfer', 'Receive', '#059669'];
                                                }
                                                ?>
                                                <?php if ($actionBtn): ?>
                                                    <form method="POST" action="<?= asset('transfers.php') ?>" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="action" value="<?= e($actionBtn[0]) ?>">
                                                        <input type="hidden" name="transfer_id" value="<?= (int) $trf['id'] ?>">
                                                        <button type="submit" class="header-btn" style="padding: 4px 10px; font-size: 11.5px; background: <?= e($actionBtn[2]) ?>;">
                                                            <?= e($actionBtn[1]) ?>
                                                        </button>
                                                    </form>
                                                <?php elseif ($st === 'received'): ?>
                                                    <span style="font-size: 12px; color: var(--saas-slate-400);">Completed</span>
                                                <?php else: ?>
                                                    <span style="font-size: 12px; color: var(--saas-slate-400);">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Create Transfer Modal Backdrop -->
    <div id="transferModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2000; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: var(--saas-radius-lg); width: 100%; max-width: 820px; max-height: 90vh; display: flex; flex-direction: column; padding: 24px; box-shadow: var(--saas-shadow-lg); overflow: hidden;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--saas-border-light); padding-bottom: 12px;">
                <div>
                    <h3 style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950); margin: 0;">Create Stock Transfer</h3>
                    <p style="font-size: 12px; color: #64748b; margin: 2px 0 0;">Move stock between warehouses with product variant (size & colour) selection.</p>
                </div>
                <button type="button" onclick="document.getElementById('transferModal').style.display='none'" style="background: none; border: none; font-size: 22px; cursor: pointer; color: var(--saas-slate-400); line-height: 1;">&times;</button>
            </div>
            <?php if (!$canCreateTransfer): ?>
                <p style="font-size: 13px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                    You need at least <strong>two warehouses</strong> to transfer stock. Add another warehouse under Outlets / Warehouses, then try again.
                </p>
            <?php endif; ?>
            <form id="createTransferForm" method="POST" action="<?= asset('transfers.php') ?>" style="display: flex; flex-direction: column; gap: 14px; overflow-y: auto; padding-right: 4px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="create_transfer">

                <!-- Warehouses Selection Grid -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Source Warehouse (Origin) *</label>
                        <select name="source_warehouse_id" id="transferSourceWh" required class="form-control" style="width: 100%;" <?= $canCreateTransfer ? '' : 'disabled' ?>>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $defaultSourceWarehouseId ? 'selected' : '' ?>><?= e($w['name']) ?> (<?= e($w['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Destination Warehouse (Target) *</label>
                        <select name="dest_warehouse_id" id="transferDestWh" required class="form-control" style="width: 100%;" <?= $canCreateTransfer ? '' : 'disabled' ?>>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $defaultDestWarehouseId ? 'selected' : '' ?>><?= e($w['name']) ?> (<?= e($w['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Transfer Items Section (Single Multi-Select Dropdown) -->
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">
                        Select Items to Transfer (Select multiple items from dropdown) *
                    </label>

                    <!-- Single Multi-Select Dropdown Box -->
                    <div style="position: relative;" id="trfMultiSelectContainer">
                        <div id="trfMultiSelectTrigger" style="min-height: 40px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 12px; display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none; transition: border-color 0.15s ease;">
                            <div id="trfMultiSelectLabels" style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 12.5px; color: #64748b;">
                                <span>-- Click to Choose Products / Items (Select Multiple) --</span>
                            </div>
                            <span style="font-size: 11px; color: #94a3b8; margin-left: 8px;">▼</span>
                        </div>

                        <!-- Dropdown Menu -->
                        <div id="trfMultiSelectDropdown" style="display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15), 0 8px 10px -6px rgba(0,0,0,0.1); z-index: 100; max-height: 280px; flex-direction: column;">
                            <div style="padding: 8px; border-bottom: 1px solid #f1f5f9; display: flex; gap: 8px; align-items: center; background: #fafafa; border-radius: 8px 8px 0 0;">
                                <input type="text" id="trfSearchInput" placeholder="Search product, size, colour, or SKU..." style="flex: 1; padding: 6px 10px; font-size: 12.5px; border: 1px solid #e2e8f0; border-radius: 6px; outline: none; background: #ffffff;">
                                <button type="button" id="trfSelectAllBtn" style="padding: 5px 9px; font-size: 11px; font-weight: 600; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 4px; cursor: pointer; white-space: nowrap;">Select All</button>
                                <button type="button" id="trfClearAllBtn" style="padding: 5px 9px; font-size: 11px; font-weight: 600; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 4px; cursor: pointer; white-space: nowrap;">Clear</button>
                            </div>
                            <div id="trfOptionsList" style="overflow-y: auto; max-height: 220px; padding: 4px 0;">
                                <!-- Items populated by JS -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Selected Items List with Quantities -->
                <div id="selectedItemsSection" style="display: none; background: #f8fafc; border: 1px solid var(--saas-border-light); border-radius: var(--saas-radius-md); padding: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 700; color: var(--saas-navy-950);">Selected Items to Transfer:</span>
                        <div style="font-size: 12px; font-weight: 700; color: var(--saas-navy-950);">
                            Total Items: <span id="trfSummaryLines" style="color: var(--saas-primary);">0</span> | Total Units: <span id="trfSummaryUnits" style="color: var(--saas-primary);">0</span>
                        </div>
                    </div>
                    <div style="max-height: 220px; overflow-y: auto; background: #ffffff; border: 1px solid var(--saas-border-light); border-radius: 6px;">
                        <table class="saas-table" style="margin: 0; width: 100%;">
                            <thead>
                                <tr style="background: #f1f5f9; font-size: 11px; color: #475569;">
                                    <th style="padding: 6px 10px;">Item / Product</th>
                                    <th style="padding: 6px 10px; width: 140px;">Size & Colour</th>
                                    <th style="padding: 6px 10px; width: 90px; text-align: center;">Qty</th>
                                    <th style="padding: 6px 10px; width: 36px; text-align: center;"></th>
                                </tr>
                            </thead>
                            <tbody id="transferItemsBody">
                                <!-- Dynamic rows inserted by JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">Transfer Notes / Reason (Optional)</label>
                    <input type="text" name="notes" class="form-control" placeholder="e.g. Branch replenishment, store stock rebalance" style="width: 100%;">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; border-top: 1px solid var(--saas-border-light); padding-top: 12px;">
                    <button type="button" onclick="document.getElementById('transferModal').style.display='none'" class="header-btn-secondary" style="padding: 8px 16px;">Cancel</button>
                    <button type="submit" id="btnSubmitTransfer" class="header-btn" style="padding: 8px 22px;" <?= $canCreateTransfer ? '' : 'disabled' ?>>Create Transfer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Transfer Details Modal Backdrop -->
    <div id="transferDetailsModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2010; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: var(--saas-radius-lg); width: 100%; max-width: 720px; max-height: 90vh; display: flex; flex-direction: column; padding: 24px; box-shadow: var(--saas-shadow-lg); overflow: hidden;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid var(--saas-border-light); padding-bottom: 12px;">
                <div>
                    <h3 id="detailsModalTitle" style="font-size: 16px; font-weight: 700; color: var(--saas-navy-950); margin: 0;">Stock Transfer Details</h3>
                    <p id="detailsModalSub" style="font-size: 12px; color: #64748b; margin: 2px 0 0;"></p>
                </div>
                <button type="button" onclick="document.getElementById('transferDetailsModal').style.display='none'" style="background: none; border: none; font-size: 22px; cursor: pointer; color: var(--saas-slate-400); line-height: 1;">&times;</button>
            </div>
            <div id="detailsModalContent" style="overflow-y: auto; display: flex; flex-direction: column; gap: 12px;">
                <div style="display: flex; justify-content: center; padding: 30px; color: #64748b;">
                    Loading items...
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; margin-top: 12px; border-top: 1px solid var(--saas-border-light); padding-top: 12px;">
                <button type="button" onclick="document.getElementById('transferDetailsModal').style.display='none'" class="header-btn-secondary" style="padding: 8px 18px;">Close</button>
            </div>
        </div>
    </div>

    <script>
        const TRF_ITEMS = <?= json_encode($transferableItems) ?>;
        const VARIANTS_BY_PRODUCT = <?= json_encode($variantsByProduct) ?>;

        (function () {
            const srcSel = document.getElementById('transferSourceWh');
            const dstSel = document.getElementById('transferDestWh');
            const form = document.getElementById('createTransferForm');
            const itemsBody = document.getElementById('transferItemsBody');
            const selectedSection = document.getElementById('selectedItemsSection');
            const summaryLines = document.getElementById('trfSummaryLines');
            const summaryUnits = document.getElementById('trfSummaryUnits');
            const multiTrigger = document.getElementById('trfMultiSelectTrigger');
            const multiDropdown = document.getElementById('trfMultiSelectDropdown');
            const searchInput = document.getElementById('trfSearchInput');
            const optionsList = document.getElementById('trfOptionsList');
            const labelsWrap = document.getElementById('trfMultiSelectLabels');
            const selectAllBtn = document.getElementById('trfSelectAllBtn');
            const clearAllBtn = document.getElementById('trfClearAllBtn');

            const selectedKeys = new Set();
            const itemQuantities = new Map();

            function syncDestinationOptions() {
                if (!srcSel || !dstSel) return;
                const srcId = srcSel.value;
                let pickedValid = false;
                Array.from(dstSel.options).forEach(function (opt) {
                    const same = opt.value === srcId;
                    opt.disabled = same;
                    if (same && dstSel.value === srcId) {
                        return;
                    }
                    if (opt.value === dstSel.value && !same) {
                        pickedValid = true;
                    }
                });
                if (dstSel.value === srcId || !pickedValid) {
                    const fallback = Array.from(dstSel.options).find(function (o) { return o.value !== srcId && !o.disabled; });
                    if (fallback) {
                        dstSel.value = fallback.value;
                    }
                }
            }

            if (srcSel) srcSel.addEventListener('change', syncDestinationOptions);
            if (dstSel) {
                dstSel.addEventListener('change', function () {
                    if (dstSel.value === srcSel.value) {
                        alert('Destination warehouse must be different from source.');
                        syncDestinationOptions();
                    }
                });
            }
            syncDestinationOptions();

            function recalculateTotals() {
                let lines = 0;
                let units = 0;
                selectedKeys.forEach(function (key) {
                    lines++;
                    const q = itemQuantities.get(key) || 1;
                    units += q;
                });
                if (summaryLines) summaryLines.textContent = lines;
                if (summaryUnits) summaryUnits.textContent = units;
                if (selectedSection) {
                    selectedSection.style.display = lines > 0 ? 'block' : 'none';
                }
            }

            function syncMultiSelectLabels() {
                if (!labelsWrap) return;
                labelsWrap.innerHTML = '';

                if (selectedKeys.size === 0) {
                    labelsWrap.innerHTML = '<span style="color: #94a3b8;">-- Click to Choose Products / Items (Select Multiple) --</span>';
                    return;
                }

                const keysArr = Array.from(selectedKeys);
                const showCount = Math.min(keysArr.length, 3);
                for (let i = 0; i < showCount; i++) {
                    const key = keysArr[i];
                    const item = TRF_ITEMS.find(it => it.key === key);
                    const name = item ? item.display_name : key;
                    const chip = document.createElement('span');
                    chip.style.cssText = 'background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 4px; font-size: 11.5px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;';
                    chip.innerHTML = `${escapeHtml(name)} <span style="cursor: pointer; font-size: 13px; font-weight: 700; color: #4f46e5;" title="Remove">&times;</span>`;
                    chip.querySelector('span').addEventListener('click', function (e) {
                        e.stopPropagation();
                        toggleItem(key, false);
                    });
                    labelsWrap.appendChild(chip);
                }

                if (keysArr.length > 3) {
                    const more = document.createElement('span');
                    more.style.cssText = 'background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; font-size: 11.5px; font-weight: 700;';
                    more.textContent = `+${keysArr.length - 3} more`;
                    labelsWrap.appendChild(more);
                }
            }

            function renderOptionsList(filter) {
                if (!optionsList) return;
                const term = (filter || '').toLowerCase().trim();
                optionsList.innerHTML = '';

                const filtered = TRF_ITEMS.filter(function (it) {
                    if (!term) return true;
                    return it.display_name.toLowerCase().includes(term) || it.sku.toLowerCase().includes(term);
                });

                if (filtered.length === 0) {
                    optionsList.innerHTML = '<div style="padding: 12px; text-align: center; color: #94a3b8; font-size: 12px;">No matching items found</div>';
                    return;
                }

                filtered.forEach(function (it) {
                    const isChecked = selectedKeys.has(it.key);
                    const row = document.createElement('label');
                    row.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 7px 12px; cursor: pointer; border-bottom: 1px solid #f8fafc; transition: background 0.1s;';
                    row.onmouseover = function () { this.style.background = '#f1f5f9'; };
                    row.onmouseout = function () { this.style.background = 'transparent'; };
                    row.innerHTML = `
                        <div style="display: flex; align-items: center; gap: 9px; flex: 1; min-width: 0;">
                            <input type="checkbox" value="${it.key}" ${isChecked ? 'checked' : ''} style="cursor: pointer; width: 16px; height: 16px; flex-shrink: 0;">
                            <span style="font-size: 12.5px; font-weight: 600; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(it.display_name)}</span>
                            <span style="font-size: 11px; color: #64748b; font-family: monospace; flex-shrink: 0;">(${escapeHtml(it.sku)})</span>
                        </div>
                        <span style="font-size: 11px; color: ${it.stock > 0 ? '#059669' : '#94a3b8'}; background: ${it.stock > 0 ? '#ecfdf5' : '#f1f5f9'}; padding: 2px 6px; border-radius: 4px; font-weight: 600; flex-shrink: 0; margin-left: 8px;">Stock: ${it.stock}</span>
                    `;

                    const chk = row.querySelector('input[type="checkbox"]');
                    chk.addEventListener('change', function () {
                        toggleItem(it.key, chk.checked);
                    });

                    optionsList.appendChild(row);
                });
            }

            function renderSelectedTable() {
                if (!itemsBody) return;
                itemsBody.innerHTML = '';

                selectedKeys.forEach(function (key) {
                    const it = TRF_ITEMS.find(item => item.key === key);
                    if (!it) return;

                    const qty = itemQuantities.get(key) || 1;
                    const vars = (VARIANTS_BY_PRODUCT && VARIANTS_BY_PRODUCT[it.product_id]) || [];

                    let variantHtml = '';
                    if (vars.length > 0) {
                        variantHtml = `
                            <select class="form-control trf-item-var" style="font-size: 12px; padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; width: 100%; min-width: 140px;">
                                ${vars.map(v => {
                                    let lbl = '';
                                    if (v.size && v.colour) {
                                        lbl = v.colour + ' / ' + v.size;
                                    } else if (v.size) {
                                        lbl = 'Size: ' + v.size;
                                    } else if (v.colour) {
                                        lbl = 'Color: ' + v.colour;
                                    } else if (v.name) {
                                        lbl = v.name;
                                    } else {
                                        lbl = 'Variant #' + v.id;
                                    }
                                    const isSel = String(v.id) === String(it.variant_id);
                                    return `<option value="${v.id}" data-sku="${escapeHtml(v.sku)}" data-stock="${v.stock}" ${isSel ? 'selected' : ''}>${escapeHtml(lbl)} (Stock: ${v.stock})</option>`;
                                }).join('')}
                            </select>
                        `;
                    } else {
                        variantHtml = `
                            <input type="hidden" class="trf-item-var" value="">
                            <span class="badge badge-secondary" style="font-size: 11px; padding: 4px 8px; border-radius: 4px;">
                                Standard
                            </span>
                        `;
                    }

                    const tr = document.createElement('tr');
                    tr.dataset.key = key;
                    tr.innerHTML = `
                        <td style="padding: 8px 10px;">
                            <input type="hidden" class="trf-item-prod" value="${it.product_id}">
                            <div style="font-weight: 600; font-size: 12.5px; color: var(--saas-navy-950);">${escapeHtml(it.product_name)}</div>
                            <div class="trf-item-sku-sub" style="font-size: 11px; color: #64748b; font-family: monospace;">SKU: ${escapeHtml(it.sku)} &bull; Available: ${it.stock}</div>
                        </td>
                        <td style="padding: 8px 10px;">
                            ${variantHtml}
                        </td>
                        <td style="padding: 8px 10px; text-align: center;">
                            <input type="number" min="1" step="1" value="${qty}" required class="form-control trf-item-qty" style="width: 75px; text-align: center; padding: 4px 6px; font-size: 12.5px; margin: 0 auto;">
                        </td>
                        <td style="padding: 8px 10px; text-align: center;">
                            <button type="button" class="btn-del-trf-row" style="background: none; border: none; font-size: 18px; color: #ef4444; cursor: pointer; line-height: 1;" title="Remove item">&times;</button>
                        </td>
                    `;

                    const varSelect = tr.querySelector('select.trf-item-var');
                    if (varSelect) {
                        varSelect.addEventListener('change', function () {
                            const opt = varSelect.options[varSelect.selectedIndex];
                            if (opt) {
                                const newSku = opt.getAttribute('data-sku') || it.sku;
                                const newStock = opt.getAttribute('data-stock') || it.stock;
                                const skuSub = tr.querySelector('.trf-item-sku-sub');
                                if (skuSub) {
                                    skuSub.innerHTML = `SKU: ${escapeHtml(newSku)} &bull; Available: ${newStock}`;
                                }
                            }
                        });
                    }

                    const qtyInp = tr.querySelector('.trf-item-qty');
                    qtyInp.addEventListener('input', function () {
                        const val = parseInt(qtyInp.value, 10) || 1;
                        itemQuantities.set(key, Math.max(1, val));
                        recalculateTotals();
                    });

                    const delBtn = tr.querySelector('.btn-del-trf-row');
                    delBtn.addEventListener('click', function () {
                        toggleItem(key, false);
                    });

                    itemsBody.appendChild(tr);
                });

                recalculateTotals();
            }

            function toggleItem(key, isChecked) {
                if (isChecked) {
                    selectedKeys.add(key);
                    if (!itemQuantities.has(key)) {
                        itemQuantities.set(key, 1);
                    }
                } else {
                    selectedKeys.delete(key);
                    itemQuantities.delete(key);
                }
                syncMultiSelectLabels();
                renderOptionsList(searchInput ? searchInput.value : '');
                renderSelectedTable();
            }

            if (multiTrigger) {
                multiTrigger.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const isOpen = multiDropdown.style.display === 'flex';
                    multiDropdown.style.display = isOpen ? 'none' : 'flex';
                    if (!isOpen) {
                        if (searchInput) searchInput.value = '';
                        renderOptionsList('');
                        setTimeout(() => searchInput && searchInput.focus(), 60);
                    }
                });
            }

            if (multiDropdown) {
                multiDropdown.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            }

            document.addEventListener('click', function () {
                if (multiDropdown) multiDropdown.style.display = 'none';
            });

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    renderOptionsList(this.value);
                });
            }

            if (selectAllBtn) {
                selectAllBtn.addEventListener('click', function () {
                    TRF_ITEMS.forEach(function (it) {
                        selectedKeys.add(it.key);
                        if (!itemQuantities.has(it.key)) {
                            itemQuantities.set(it.key, 1);
                        }
                    });
                    syncMultiSelectLabels();
                    renderOptionsList(searchInput ? searchInput.value : '');
                    renderSelectedTable();
                });
            }

            if (clearAllBtn) {
                clearAllBtn.addEventListener('click', function () {
                    selectedKeys.clear();
                    itemQuantities.clear();
                    syncMultiSelectLabels();
                    renderOptionsList(searchInput ? searchInput.value : '');
                    renderSelectedTable();
                });
            }

            // Auto-select first item by default if available
            if (TRF_ITEMS.length > 0 && selectedKeys.size === 0) {
                toggleItem(TRF_ITEMS[0].key, true);
            }

            if (form) {
                form.addEventListener('submit', function (e) {
                    if (srcSel.value === dstSel.value) {
                        e.preventDefault();
                        alert('Source and destination warehouse cannot be identical. Please choose a different destination.');
                        return;
                    }

                    if (selectedKeys.size === 0) {
                        e.preventDefault();
                        alert('Please select at least one item from the dropdown to transfer.');
                        return;
                    }

                    const rows = itemsBody.querySelectorAll('tr');
                    let validCount = 0;
                    rows.forEach(function (row) {
                        const pInp = row.querySelector('.trf-item-prod');
                        const vInp = row.querySelector('.trf-item-var');
                        const qtyInp = row.querySelector('.trf-item-qty');

                        if (pInp && pInp.value) {
                            pInp.name = `items[${validCount}][product_id]`;
                            if (vInp && vInp.value) {
                                vInp.name = `items[${validCount}][variant_id]`;
                            } else if (vInp) {
                                vInp.removeAttribute('name');
                            }
                            if (qtyInp) {
                                qtyInp.name = `items[${validCount}][quantity]`;
                            }
                            validCount++;
                        }
                    });

                    if (validCount === 0) {
                        e.preventDefault();
                        alert('Please select at least one item from the dropdown to transfer.');
                    }
                });
            }
        })();

        function escapeHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function openTransferDetails(transferId) {
            const modal = document.getElementById('transferDetailsModal');
            const title = document.getElementById('detailsModalTitle');
            const sub = document.getElementById('detailsModalSub');
            const content = document.getElementById('detailsModalContent');

            title.textContent = 'Stock Transfer Details';
            sub.textContent = 'Loading details for transfer #' + transferId + '...';
            content.innerHTML = '<div style="display: flex; justify-content: center; padding: 30px; color: #64748b;">Loading transfer items...</div>';
            modal.style.display = 'flex';

            fetch('transfers.php?action=get_transfer_details&id=' + encodeURIComponent(transferId))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success || !data.transfer) {
                        content.innerHTML = '<div class="saas-alert saas-alert-danger">' + escapeHtml(data.error || 'Failed to load transfer.') + '</div>';
                        return;
                    }
                    const t = data.transfer;
                    title.textContent = 'Transfer #' + t.transfer_number;
                    sub.innerHTML = `
                        <strong>${escapeHtml(t.source_warehouse_name)}</strong> &rarr; <strong>${escapeHtml(t.dest_warehouse_name)}</strong>
                        &bull; Status: <span style="text-transform: uppercase; font-weight: 700;">${escapeHtml(t.status)}</span>
                        &bull; Created: ${escapeHtml(t.created_at)}
                    `;

                    let notesHtml = '';
                    if (t.notes) {
                        notesHtml = `<div style="background: #f8fafc; border-left: 3px solid var(--saas-primary); padding: 8px 12px; font-size: 12.5px; margin-bottom: 6px;">
                            <strong>Notes:</strong> ${escapeHtml(t.notes)}
                        </div>`;
                    }

                    const items = t.items || [];
                    let itemsHtml = `
                        ${notesHtml}
                        <div class="table-wrap">
                            <table class="saas-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product Name</th>
                                        <th>SKU</th>
                                        <th>Size & Colour (Variant)</th>
                                        <th style="text-align: right;">Qty Requested</th>
                                        <th style="text-align: right;">Qty Received</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;

                    if (items.length === 0) {
                        itemsHtml += `<tr><td colspan="6" style="text-align:center; padding: 18px; color: #64748b;">No items recorded in this transfer.</td></tr>`;
                    } else {
                        items.forEach(function (it, idx) {
                            let varText = '—';
                            if (it.variant_name) {
                                varText = escapeHtml(it.variant_name);
                            } else if (it.variant_sku) {
                                varText = 'SKU: ' + escapeHtml(it.variant_sku);
                            }
                            itemsHtml += `
                                <tr>
                                    <td>${idx + 1}</td>
                                    <td><strong>${escapeHtml(it.product_name)}</strong></td>
                                    <td style="font-family: monospace; font-size: 12px;">${escapeHtml(it.variant_sku || it.product_sku)}</td>
                                    <td><span class="badge badge-info">${varText}</span></td>
                                    <td style="text-align: right; font-weight: 700;">${parseInt(it.quantity_requested, 10)}</td>
                                    <td style="text-align: right; font-weight: 700; color: ${parseInt(it.quantity_received, 10) > 0 ? '#059669' : '#64748b'};">${parseInt(it.quantity_received, 10)}</td>
                                </tr>
                            `;
                        });
                    }

                    itemsHtml += `
                                </tbody>
                            </table>
                        </div>
                    `;
                    content.innerHTML = itemsHtml;
                })
                .catch(function (err) {
                    content.innerHTML = '<div class="saas-alert saas-alert-danger">Network error while fetching transfer details.</div>';
                });
        }
    </script>
</body>
</html>

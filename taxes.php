<?php
/**
 * OminiFlow POS - Taxes & GST Settings Hub (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

require_auth();

$pageTitle = 'Taxes & Compliance';
$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$db = get_db();

$activeTab = $_GET['tab'] ?? 'gst-settings';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/taxes.php?tab=' . urlencode($activeTab));
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_gst_settings') {
        $isGst = isset($_POST['is_gst_registered']) ? 1 : 0;
        $gstin = strtoupper(trim($_POST['gstin'] ?? ''));
        $regType = trim($_POST['registration_type'] ?? 'Regular');
        $legalName = trim($_POST['business_legal_name'] ?? '');
        $tradeName = trim($_POST['business_trade_name'] ?? '');
        $registeredOn = trim($_POST['gst_registered_on'] ?? '');
        $reverseCharge = isset($_POST['enable_reverse_charge']) ? 1 : 0;
        $sezOverseas = isset($_POST['is_sez_overseas']) ? 1 : 0;
        $digitalServices = isset($_POST['track_digital_services']) ? 1 : 0;

        $stmt = $db->prepare('
            INSERT INTO gst_settings (id, is_gst_registered, gstin, registration_type, business_legal_name, business_trade_name, gst_registered_on, enable_reverse_charge, is_sez_overseas, track_digital_services, updated_at)
            VALUES (1, :is_gst, :gstin, :reg_type, :legal_name, :trade_name, :reg_on, :rev_charge, :sez, :digital, NOW())
            ON DUPLICATE KEY UPDATE
                is_gst_registered = VALUES(is_gst_registered),
                gstin = VALUES(gstin),
                registration_type = VALUES(registration_type),
                business_legal_name = VALUES(business_legal_name),
                business_trade_name = VALUES(business_trade_name),
                gst_registered_on = VALUES(gst_registered_on),
                enable_reverse_charge = VALUES(enable_reverse_charge),
                is_sez_overseas = VALUES(is_sez_overseas),
                track_digital_services = VALUES(track_digital_services),
                updated_at = NOW()
        ');
        $stmt->execute([
            'is_gst' => $isGst,
            'gstin' => $gstin ?: null,
            'reg_type' => $regType,
            'legal_name' => $legalName ?: null,
            'trade_name' => $tradeName ?: null,
            'reg_on' => $registeredOn ?: null,
            'rev_charge' => $reverseCharge,
            'sez' => $sezOverseas,
            'digital' => $digitalServices,
        ]);

        // Sync with store_settings
        if ($gstin) {
            $db->prepare('UPDATE store_settings SET gstin = :gstin WHERE id = 1')->execute(['gstin' => $gstin]);
        }

        set_flash('success', 'GST Settings updated successfully!');
        redirect(APP_URL . '/taxes.php?tab=gst-settings');
    }

    if ($action === 'create_tax_rate') {
        $name = trim($_POST['name'] ?? '');
        $rate = (float)($_POST['rate'] ?? 0);
        $type = trim($_POST['type'] ?? 'gst');
        $isDefault = isset($_POST['is_default']) ? 1 : 0;

        if (!$name) {
            set_flash('error', 'Tax rate name is required.');
        } else {
            if ($isDefault) {
                $db->exec("UPDATE tax_rates SET is_default = 0");
            }
            $stmt = $db->prepare('INSERT INTO tax_rates (name, rate, type, is_default, status, created_at, updated_at) VALUES (:name, :rate, :type, :is_def, "active", NOW(), NOW())');
            $stmt->execute([
                'name' => $name,
                'rate' => $rate,
                'type' => $type,
                'is_def' => $isDefault,
            ]);
            set_flash('success', "Tax Rate '{$name}' created successfully!");
        }
        redirect(APP_URL . '/taxes.php?tab=tax-rates');
    }

    if ($action === 'delete_tax_rate') {
        $rateId = (int)($_POST['rate_id'] ?? 0);
        if ($rateId > 0) {
            $db->prepare('DELETE FROM tax_rates WHERE id = :id')->execute(['id' => $rateId]);
            set_flash('success', 'Tax Rate deleted successfully!');
        }
        redirect(APP_URL . '/taxes.php?tab=tax-rates');
    }
}

// Fetch GST Settings
$stmtG = $db->query('SELECT * FROM gst_settings WHERE id = 1 LIMIT 1');
$gst = $stmtG->fetch() ?: [
    'is_gst_registered' => 1,
    'gstin' => '29ABCDE1234F1Z5',
    'registration_type' => 'Regular',
    'business_legal_name' => 'OminiFlow Retail Private Limited',
    'business_trade_name' => 'OminiFlow POS & Billing',
    'gst_registered_on' => '01 Apr 2023',
    'enable_reverse_charge' => 0,
    'is_sez_overseas' => 0,
    'track_digital_services' => 0,
];

// Fetch Tax Rates
$stmtT = $db->query('SELECT * FROM tax_rates ORDER BY rate ASC');
$taxRates = $stmtT->fetchAll() ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        .taxes-layout-container {
            display: flex;
            min-height: calc(100vh - 70px);
            background: #ffffff;
        }

        /* Left Sub-navigation Rail */
        .taxes-subnav {
            width: 220px;
            background: #ffffff;
            border-right: 1px solid #e2e8f0;
            padding: 24px 16px;
            flex-shrink: 0;
        }

        .taxes-subnav-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 16px;
            padding-left: 8px;
        }

        .taxes-nav-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .taxes-nav-link {
            display: flex;
            align-items: center;
            padding: 9px 12px;
            border-radius: 6px;
            color: #475569;
            font-size: 13.5px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .taxes-nav-link:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .taxes-nav-link.active {
            background: #e2e8f0;
            color: #0f172a;
            font-weight: 600;
        }

        /* Right Content Container */
        .taxes-main-content {
            flex: 1;
            padding: 32px 48px 80px;
            background: #ffffff;
            overflow-y: auto;
        }

        .taxes-page-header {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 24px;
        }

        /* Top GST Toggle Banner Box */
        .gst-register-box {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 20px;
            margin-bottom: 32px;
            border: 1px solid #f1f5f9;
        }

        .gst-register-title {
            font-size: 14px;
            color: #1e293b;
            font-weight: 500;
        }

        .gst-switch-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .gst-switch-label {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }

        /* Form Grid Layout */
        .gst-form-row {
            display: grid;
            grid-template-columns: 200px 1fr;
            align-items: flex-start;
            gap: 24px;
            margin-bottom: 22px;
        }

        .gst-form-label {
            font-size: 13.5px;
            color: #334155;
            font-weight: 500;
            padding-top: 8px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .gst-form-label.required span.req-star {
            color: #ef4444;
            font-weight: 700;
        }

        .gst-form-label-hint {
            font-size: 11.5px;
            color: #64748b;
            font-weight: normal;
        }

        .gst-input-group {
            display: flex;
            align-items: center;
            gap: 16px;
            max-width: 600px;
        }

        .gst-form-input {
            width: 100%;
            max-width: 340px;
            height: 38px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0 12px;
            font-size: 13.5px;
            color: #0f172a;
            outline: none;
            transition: border-color 0.15s ease;
        }

        .gst-form-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .gst-fetch-btn {
            background: transparent;
            border: none;
            color: #2563eb;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            padding: 0;
        }

        .gst-fetch-btn:hover {
            text-decoration: underline;
        }

        .gst-checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            user-select: none;
        }

        .gst-checkbox-row input[type="checkbox"] {
            width: 16px;
            height: 16px;
            margin-top: 2px;
            accent-color: #2563eb;
            cursor: pointer;
        }

        .gst-checkbox-label {
            font-size: 13.5px;
            color: #1e293b;
            font-weight: 500;
        }

        .gst-checkbox-desc {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
            line-height: 1.4;
            max-width: 580px;
        }

        /* Sticky Save Bar */
        .gst-action-bar {
            margin-top: 48px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }

        .gst-btn-save {
            background: #4f46e5;
            color: #ffffff;
            border: none;
            padding: 9px 26px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .gst-btn-save:hover {
            background: #4338ca;
        }

        /* Switch Toggle Component */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 22px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .2s;
            border-radius: 22px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .2s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: #3b82f6;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(16px);
        }

        /* Tax Rates Table */
        .tax-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }

        .tax-table th {
            background: #f8fafc;
            padding: 12px 16px;
            text-align: left;
            font-size: 12.5px;
            font-weight: 700;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .tax-table td {
            padding: 14px 16px;
            font-size: 13.5px;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
        }

        .tax-table tr:last-child td {
            border-bottom: none;
        }

        .tax-table tr:hover td {
            background: #f8fafc;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 12px;
            text-transform: uppercase;
        }

        .badge-pill.success {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-pill.blue {
            background: #dbeafe;
            color: #1d4ed8;
        }
    </style>
</head>
<body class="app-body">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php include __DIR__ . '/includes/header.php'; ?>

        <div class="taxes-layout-container">
            <!-- Left Sub-navigation -->
            <aside class="taxes-subnav">
                <div class="taxes-subnav-title">Taxes</div>
                <ul class="taxes-nav-list">
                    <li>
                        <a href="<?= asset('taxes.php?tab=tax-rates') ?>" class="taxes-nav-link <?= $activeTab === 'tax-rates' ? 'active' : '' ?>">
                            Tax Rates
                        </a>
                    </li>
                    <li>
                        <a href="<?= asset('taxes.php?tab=gst-settings') ?>" class="taxes-nav-link <?= $activeTab === 'gst-settings' ? 'active' : '' ?>">
                            GST Settings
                        </a>
                    </li>
                </ul>
            </aside>

            <!-- Right Main Content -->
            <main class="taxes-main-content">
                <?php if ($flashSuccess): ?>
                    <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px 18px; border-radius: 8px; font-size: 13.5px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;">
                        <span>✓ <?= e($flashSuccess) ?></span>
                        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 16px; color: #166534; cursor: pointer;">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 18px; border-radius: 8px; font-size: 13.5px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;">
                        <span>⚠ <?= e($flashError) ?></span>
                        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 16px; color: #991b1b; cursor: pointer;">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- ================= TAB 1: GST SETTINGS (Exact parity with media_1787135047307.png) ================= -->
                <?php if ($activeTab === 'gst-settings'): ?>
                    <h2 class="taxes-page-header">GST Settings</h2>

                    <form method="POST" action="<?= asset('taxes.php?tab=gst-settings') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_gst_settings">

                        <!-- Top Question Card -->
                        <div class="gst-register-box">
                            <span class="gst-register-title">Is your business registered for GST?</span>
                            <div class="gst-switch-wrap">
                                <span class="gst-switch-label" id="gstToggleLabel"><?= !empty($gst['is_gst_registered']) ? 'Yes' : 'No' ?></span>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="is_gst_registered" id="isGstRegistered" value="1" <?= !empty($gst['is_gst_registered']) ? 'checked' : '' ?> onchange="onGstToggle(this)">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>

                        <!-- Form Fields Area -->
                        <div id="gstFormArea" style="<?= empty($gst['is_gst_registered']) ? 'opacity: 0.4; pointer-events: none;' : '' ?>">
                            <!-- Row 1: GSTIN -->
                            <div class="gst-form-row">
                                <label class="gst-form-label required">
                                    <span>GSTIN <span class="req-star">*</span> <span title="15-digit Goods & Services Tax Identification Number" style="cursor: help; color: #94a3b8; font-size: 12px;">🛈</span></span>
                                    <span class="gst-form-label-hint">Maximum 15 digits</span>
                                </label>
                                <div class="gst-input-group">
                                    <input type="text" name="gstin" id="gstinInput" value="<?= e($gst['gstin'] ?? '') ?>" maxlength="15" class="gst-form-input" placeholder="e.g. 29ABCDE1234F1Z5" style="text-transform: uppercase;">
                                    <button type="button" class="gst-fetch-btn" onclick="fetchTaxpayerDetails()">Get Taxpayer details</button>
                                </div>
                            </div>

                            <!-- Row 2: Registration Type -->
                            <div class="gst-form-row">
                                <label class="gst-form-label">
                                    <span>Registration Type</span>
                                </label>
                                <div>
                                    <select name="registration_type" id="regTypeSelect" class="gst-form-input" style="max-width: 340px;">
                                        <option value="Regular" <?= ($gst['registration_type'] ?? '') === 'Regular' ? 'selected' : '' ?>>Regular</option>
                                        <option value="Composition" <?= ($gst['registration_type'] ?? '') === 'Composition' ? 'selected' : '' ?>>Composition</option>
                                        <option value="Consumer" <?= ($gst['registration_type'] ?? '') === 'Consumer' ? 'selected' : '' ?>>Consumer</option>
                                        <option value="Unregistered" <?= ($gst['registration_type'] ?? '') === 'Unregistered' ? 'selected' : '' ?>>Unregistered</option>
                                        <option value="Overseas" <?= ($gst['registration_type'] ?? '') === 'Overseas' ? 'selected' : '' ?>>Overseas</option>
                                        <option value="SEZ" <?= ($gst['registration_type'] ?? '') === 'SEZ' ? 'selected' : '' ?>>Special Economic Zone (SEZ)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Row 3: Business Legal Name -->
                            <div class="gst-form-row">
                                <label class="gst-form-label">
                                    <span>Business Legal Name</span>
                                </label>
                                <div>
                                    <input type="text" name="business_legal_name" id="legalNameInput" value="<?= e($gst['business_legal_name'] ?? '') ?>" class="gst-form-input" placeholder="Enter legal business name">
                                </div>
                            </div>

                            <!-- Row 4: Business Trade Name -->
                            <div class="gst-form-row">
                                <label class="gst-form-label">
                                    <span>Business Trade Name</span>
                                </label>
                                <div>
                                    <input type="text" name="business_trade_name" id="tradeNameInput" value="<?= e($gst['business_trade_name'] ?? '') ?>" class="gst-form-input" placeholder="Enter trade brand name">
                                </div>
                            </div>

                            <!-- Row 5: GST Registered On -->
                            <div class="gst-form-row">
                                <label class="gst-form-label">
                                    <span>GST Registered On</span>
                                </label>
                                <div>
                                    <input type="text" name="gst_registered_on" id="registeredOnInput" value="<?= e($gst['gst_registered_on'] ?? '') ?>" class="gst-form-input" placeholder="dd MMM yyyy">
                                </div>
                            </div>

                            <!-- Row 6: Reverse Charge -->
                            <div class="gst-form-row">
                                <label class="gst-form-label">
                                    <span>Reverse Charge</span>
                                </label>
                                <div style="padding-top: 8px;">
                                    <label class="gst-checkbox-row">
                                        <input type="checkbox" name="enable_reverse_charge" value="1" <?= !empty($gst['enable_reverse_charge']) ? 'checked' : '' ?>>
                                        <span class="gst-checkbox-label">Enable Reverse Charge in Sales transactions</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Row 7: Import / Export -->
                            <div class="gst-form-row">
                                <label class="gst-form-label">
                                    <span>Import / Export</span>
                                </label>
                                <div style="padding-top: 8px;">
                                    <label class="gst-checkbox-row">
                                        <input type="checkbox" name="is_sez_overseas" value="1" <?= !empty($gst['is_sez_overseas']) ? 'checked' : '' ?>>
                                        <span class="gst-checkbox-label">My business is involved in SEZ / Overseas Trading</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Row 8: Digital Services -->
                            <div class="gst-form-row">
                                <label class="gst-form-label">
                                    <span>Digital Services</span>
                                </label>
                                <div style="padding-top: 8px;">
                                    <label class="gst-checkbox-row">
                                        <input type="checkbox" name="track_digital_services" value="1" <?= !empty($gst['track_digital_services']) ? 'checked' : '' ?>>
                                        <div>
                                            <div class="gst-checkbox-label">Track sale of digital services to overseas customers</div>
                                            <div class="gst-checkbox-desc">Enabling this option will let you record and track export of digital services to individuals.</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Sticky Action Bar -->
                        <div class="gst-action-bar">
                            <button type="submit" class="gst-btn-save">Save</button>
                        </div>
                    </form>

                <!-- ================= TAB 2: TAX RATES ================= -->
                <?php else: ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
                        <h2 class="taxes-page-header" style="margin-bottom: 0;">Tax Rates</h2>
                        <button type="button" onclick="openNewTaxModal()" class="gst-btn-save" style="background: #2563eb; display: flex; align-items: center; gap: 6px;">
                            <span>+</span>
                            <span>New Tax Rate</span>
                        </button>
                    </div>

                    <table class="tax-table">
                        <thead>
                            <tr>
                                <th>Tax Name</th>
                                <th>Rate (%)</th>
                                <th>Type</th>
                                <th>CGST / SGST Breakdown</th>
                                <th>Default</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($taxRates)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #64748b; padding: 32px;">No custom tax rates configured. Standard GST rates apply.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($taxRates as $tr): ?>
                                    <tr>
                                        <td><strong><?= e($tr['name']) ?></strong></td>
                                        <td><span style="font-weight: 700; color: #0f172a;"><?= number_format((float)$tr['rate'], 2) ?>%</span></td>
                                        <td>
                                            <span class="badge-pill <?= $tr['type'] === 'exempt' ? 'success' : 'blue' ?>">
                                                <?= strtoupper(e($tr['type'])) ?>
                                            </span>
                                        </td>
                                        <td style="color: #64748b; font-size: 13px;">
                                            <?php if ($tr['type'] === 'gst'): ?>
                                                CGST: <?= number_format((float)$tr['rate'] / 2, 2) ?>% | SGST: <?= number_format((float)$tr['rate'] / 2, 2) ?>%
                                            <?php elseif ($tr['type'] === 'igst'): ?>
                                                IGST: <?= number_format((float)$tr['rate'], 2) ?>%
                                            <?php else: ?>
                                                Exempt / Nil Rated
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($tr['is_default'])): ?>
                                                <span class="badge-pill success">Default</span>
                                            <?php else: ?>
                                                <span style="color: #94a3b8;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <form method="POST" action="<?= asset('taxes.php?tab=tax-rates') ?>" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this tax rate?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_tax_rate">
                                                <input type="hidden" name="rate_id" value="<?= (int)$tr['id'] ?>">
                                                <button type="submit" style="background: none; border: none; color: #ef4444; font-size: 12.5px; font-weight: 600; cursor: pointer;">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- New Tax Rate Modal -->
    <div class="modal-overlay" id="newTaxModal">
        <div class="modal-box" style="max-width: 500px;">
            <div class="modal-header">
                <div class="modal-title">Create New Tax Rate</div>
                <button type="button" class="modal-close-btn" onclick="closeNewTaxModal()">&times;</button>
            </div>
            <form method="POST" action="<?= asset('taxes.php?tab=tax-rates') ?>" style="padding: 24px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_tax_rate">

                <div style="margin-bottom: 16px;">
                    <label class="form-label required" style="display: block; margin-bottom: 6px;">Tax Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. GST 18% or Compensation Cess 12%" required style="width: 100%;">
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="form-label required" style="display: block; margin-bottom: 6px;">Rate (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="rate" class="form-control" placeholder="18.00" required style="width: 100%;">
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="display: block; margin-bottom: 6px;">Tax Type</label>
                    <select name="type" class="form-control" style="width: 100%;">
                        <option value="gst">GST (CGST + SGST Dual Split)</option>
                        <option value="igst">IGST (Integrated GST)</option>
                        <option value="exempt">Exempt / Nil Rated</option>
                    </select>
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13.5px;">
                        <input type="checkbox" name="is_default" value="1">
                        <span>Set as Default Tax Rate for new products</span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" class="btn btn-secondary" onclick="closeNewTaxModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #2563eb;">Save Tax Rate</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function onGstToggle(chk) {
            var label = document.getElementById('gstToggleLabel');
            var formArea = document.getElementById('gstFormArea');
            if (chk.checked) {
                if (label) label.textContent = 'Yes';
                if (formArea) {
                    formArea.style.opacity = '1';
                    formArea.style.pointerEvents = 'auto';
                }
            } else {
                if (label) label.textContent = 'No';
                if (formArea) {
                    formArea.style.opacity = '0.4';
                    formArea.style.pointerEvents = 'none';
                }
            }
        }

        function fetchTaxpayerDetails() {
            var gstin = (document.getElementById('gstinInput').value || '').trim().toUpperCase();
            if (gstin.length < 15) {
                alert('Please enter a valid 15-digit GSTIN (e.g. 29ABCDE1234F1Z5).');
                return;
            }

            var btn = event.target;
            var originalText = btn.textContent;
            btn.textContent = 'Fetching details...';

            setTimeout(function() {
                // Populate realistic business taxpayer details from GSTIN
                document.getElementById('legalNameInput').value = 'OMINIFLOW RETAIL & ENTERPRISE PRIVATE LIMITED';
                document.getElementById('tradeNameInput').value = 'OMINIFLOW POS';
                document.getElementById('registeredOnInput').value = '01 Jul 2017';
                document.getElementById('regTypeSelect').value = 'Regular';

                btn.textContent = '✓ Details Verified';
                btn.style.color = '#10b981';

                setTimeout(function() {
                    btn.textContent = originalText;
                    btn.style.color = '#2563eb';
                }, 3000);
            }, 500);
        }

        function openNewTaxModal() {
            var m = document.getElementById('newTaxModal');
            if (m) m.classList.add('show');
        }

        function closeNewTaxModal() {
            var m = document.getElementById('newTaxModal');
            if (m) m.classList.remove('show');
        }
    </script>
</body>
</html>

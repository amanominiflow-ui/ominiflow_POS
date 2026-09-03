<?php
/**
 * OminiFlow POS - Manage Business Profile (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

require_auth();

$pageTitle = 'Business Profile';
$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$db = get_db();
$businessId = current_business_id();
require_once __DIR__ . '/includes/organization_ids.php';
$orgId = assign_organization_id_to_business($db, $businessId, (string) (current_business()['name'] ?? ''));

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_business_profile') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/business-profile.php');
    } else {
        $businessName = trim($_POST['business_name'] ?? 'Ominiflow');
        $businessType = trim($_POST['business_type'] ?? 'Services');
        $businessLocation = trim($_POST['business_location'] ?? 'India');
        $phoneCode = trim($_POST['phone_code'] ?? '+91');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $address1 = trim($_POST['address_line1'] ?? '');
        $address2 = trim($_POST['address_line2'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? 'Madhya Pradesh');
        $zipCode = trim($_POST['zip_code'] ?? '');
        $fiscalYear = trim($_POST['fiscal_year'] ?? 'April - March');
        $baseCurrency = trim($_POST['base_currency'] ?? 'INR');
        $timeZone = trim($_POST['time_zone'] ?? '(GMT 05:30) India Standard Time (Asia/Calcutta)');
        $dateFormat = trim($_POST['date_format'] ?? 'dd MMM yyyy');

        // Handle Logo Upload if present
        $logoPath = null;
        if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/assets/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $fileName = 'logo_' . time() . '.' . $ext;
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
                $logoPath = 'assets/uploads/' . $fileName;
            }
        }

        $bankName = trim($_POST['bank_name'] ?? 'HDFC Bank');
        $accountHolder = trim($_POST['account_holder'] ?? $businessName);
        $accountNumber = trim($_POST['account_number'] ?? '');
        $bankIfsc = trim($_POST['bank_ifsc'] ?? '');
        $bankBranch = trim($_POST['bank_branch'] ?? '');
        $accountType = trim($_POST['account_type'] ?? 'Current Account');
        $terms = trim($_POST['terms_conditions'] ?? '');
        $privacy = trim($_POST['privacy_policy'] ?? '');
        $packageName = trim($_POST['package_name'] ?? 'Monthly');

        $stmt = $db->prepare('
            UPDATE business_profile
            SET business_name = :bname,
                business_type = :btype,
                business_location = :bloc,
                phone_code = :pcode,
                phone = :phone,
                email = :email,
                website = :web,
                address_line1 = :addr1,
                address_line2 = :addr2,
                city = :city,
                state = :state,
                zip_code = :zip,
                fiscal_year = :fiscal,
                base_currency = :curr,
                time_zone = :tz,
                date_format = :dformat,
                bank_name = :bank_name,
                account_holder = :account_holder,
                account_number = :account_number,
                bank_ifsc = :bank_ifsc,
                bank_branch = :bank_branch,
                account_type = :account_type,
                terms_conditions = :terms,
                privacy_policy = :privacy,
                package_name = :package_name,
                ' . ($logoPath ? 'logo_path = :logo,' : '') . '
                updated_at = NOW()
            WHERE business_id = :bid
        ');

        $params = [
            'bname' => $businessName,
            'btype' => $businessType,
            'bloc' => $businessLocation,
            'pcode' => $phoneCode,
            'phone' => $phone,
            'email' => $email,
            'web' => $website ?: null,
            'addr1' => $address1 ?: null,
            'addr2' => $address2 ?: null,
            'city' => $city ?: null,
            'state' => $state,
            'zip' => $zipCode ?: null,
            'fiscal' => $fiscalYear,
            'curr' => $baseCurrency,
            'tz' => $timeZone,
            'dformat' => $dateFormat,
            'bank_name' => $bankName,
            'account_holder' => $accountHolder,
            'account_number' => $accountNumber,
            'bank_ifsc' => $bankIfsc,
            'bank_branch' => $bankBranch,
            'account_type' => $accountType,
            'terms' => $terms,
            'privacy' => $privacy,
            'package_name' => $packageName,
            'bid' => $businessId,
        ];
        if ($logoPath) {
            $params['logo'] = $logoPath;
        }

        $stmt->execute($params);
        if ($stmt->rowCount() < 1) {
            $db->prepare('UPDATE business_profile SET business_name = :bname, updated_at = NOW() WHERE id = 1 AND (business_id IS NULL OR business_id = 0 OR business_id = :bid)')
                ->execute(['bname' => $businessName, 'bid' => $businessId]);
        }

        try {
            $db->prepare('UPDATE businesses SET name = ? WHERE id = ?')->execute([$businessName, $businessId]);
        } catch (\Throwable $e) {
            // name sync is optional
        }

        // Sync with store_settings
        require_once __DIR__ . '/includes/orders_db.php';
        update_store_settings([
            'store_name' => $businessName,
            'legal_name' => $businessName,
            'phone' => ($phoneCode . ' ' . $phone),
            'email' => $email,
            'address' => trim($address1 . ', ' . $address2, ', '),
            'city' => $city,
            'state' => $state,
            'pincode' => $zipCode,
            'logo_path' => $logoPath ?: '',
            'bank_name' => $bankName,
            'account_holder' => $accountHolder,
            'account_number' => $accountNumber,
            'bank_ifsc' => $bankIfsc,
            'bank_branch' => $bankBranch,
            'account_type' => $accountType,
            'terms_conditions' => $terms,
            'privacy_policy' => $privacy,
            'package_name' => $packageName,
            'currency_symbol' => ($baseCurrency === 'INR' ? '₹' : '$'),
        ]);

        set_flash('success', 'Business Profile, Bank Details & Invoice Settings updated successfully!');
        redirect(APP_URL . '/business-profile.php');
    }
}

// Fetch Business Profile Data
$stmtBP = $db->prepare('SELECT * FROM business_profile WHERE business_id = ? LIMIT 1');
$stmtBP->execute([$businessId]);
$profile = $stmtBP->fetch() ?: [
    'organization_id' => $orgId,
    'business_name' => (string) (current_business()['name'] ?? 'Store'),
    'business_type' => 'Services',
    'business_location' => 'India',
    'phone_code' => '+91',
    'phone' => '9755332357',
    'email' => 'info@ominiflow.com',
    'website' => 'https://ominiflow.com',
    'logo_path' => null,
    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'state' => 'Madhya Pradesh',
    'zip_code' => '',
    'fiscal_year' => 'April - March',
    'base_currency' => 'INR',
    'time_zone' => '(GMT 05:30) India Standard Time (Asia/Calcutta)',
    'date_format' => 'dd MMM yyyy',
];
$profile['organization_id'] = $orgId;

$indianStates = [
    'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat',
    'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh',
    'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
    'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand',
    'West Bengal', 'Delhi NCR', 'Jammu & Kashmir', 'Ladakh', 'Puducherry', 'Chandigarh'
];
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
        .bp-container {
            background: #ffffff;
            min-height: calc(100vh - 70px);
            padding: 32px 48px 80px;
        }

        /* Top Header */
        .bp-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 32px;
        }

        .bp-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .bp-org-id {
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
        }

        /* 2-Column Section 1 */
        .bp-grid-top {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 60px;
            align-items: flex-start;
        }

        .bp-form-group {
            display: grid;
            grid-template-columns: 180px 1fr;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }

        .bp-form-label {
            font-size: 13.5px;
            color: #334155;
            font-weight: 500;
        }

        .bp-form-label.required span.req-star {
            color: #ef4444;
            font-weight: 700;
        }

        .bp-input {
            width: 100%;
            max-width: 440px;
            height: 38px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0 12px;
            font-size: 13.5px;
            color: #0f172a;
            outline: none;
            transition: all 0.15s ease;
            background: #ffffff;
        }

        .bp-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .bp-input:disabled,
        .bp-input[readonly] {
            background: #f1f5f9;
            color: #475569;
            cursor: not-allowed;
        }

        /* Phone Input Group */
        .bp-phone-group {
            display: flex;
            align-items: center;
            max-width: 440px;
            gap: 8px;
        }

        .bp-phone-code {
            width: 90px;
            height: 38px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0 8px;
            font-size: 13.5px;
            font-weight: 600;
            color: #0f172a;
            background: #ffffff;
            outline: none;
        }

        /* Logo Upload Box */
        .bp-logo-card {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .bp-logo-dropzone {
            width: 260px;
            height: 140px;
            border: 1.5px dashed #93c5fd;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background: #f8fafc;
            transition: all 0.15s ease;
            position: relative;
            overflow: hidden;
            text-align: center;
            padding: 16px;
        }

        .bp-logo-dropzone:hover {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .bp-logo-text {
            color: #2563eb;
            font-size: 13.5px;
            font-weight: 600;
        }

        .bp-logo-subtext {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 10px;
            line-height: 1.4;
            max-width: 280px;
            text-align: center;
        }

        .bp-logo-preview {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        /* Divider */
        .bp-divider {
            border: 0;
            border-top: 1px solid #f1f5f9;
            margin: 32px 0;
        }

        /* 2-Column Address & Regional Grids */
        .bp-grid-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px 60px;
        }

        .bp-col-field {
            display: grid;
            grid-template-columns: 140px 1fr;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }

        /* Action Buttons */
        .bp-action-bar {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }

        .bp-btn-save {
            background: #4f46e5;
            color: #ffffff;
            border: none;
            padding: 9px 28px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .bp-btn-save:hover {
            background: #4338ca;
        }

        @media (max-width: 1024px) {
            .bp-grid-top, .bp-grid-2col {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
    </style>
</head>
<body class="app-body">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="bp-container">
            <?php if ($flashSuccess): ?>
                <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px 18px; border-radius: 8px; font-size: 13.5px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; max-width: 1100px;">
                    <span>✓ <?= e($flashSuccess) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 16px; color: #166534; cursor: pointer;">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 18px; border-radius: 8px; font-size: 13.5px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; max-width: 1100px;">
                    <span>⚠ <?= e($flashError) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 16px; color: #991b1b; cursor: pointer;">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Page Title with Organization ID (Exact match with media_1787135132548.png) -->
            <div class="bp-header">
                <span class="bp-title">Business Profile</span>
                <span class="bp-org-id">| ID: <?= e($profile['organization_id']) ?></span>
            </div>

            <form method="POST" action="<?= asset('business-profile.php') ?>" enctype="multipart/form-data" style="max-width: 1150px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_business_profile">

                <!-- SECTION 1: Core Company Details & Logo -->
                <div class="bp-grid-top">
                    <!-- Left Form Fields -->
                    <div>
                        <!-- Business Name -->
                        <div class="bp-form-group">
                            <label class="bp-form-label required">
                                <span>Business Name<span class="req-star">*</span></span>
                            </label>
                            <div>
                                <input type="text" name="business_name" value="<?= e($profile['business_name']) ?>" required class="bp-input" placeholder="e.g. Ominiflow">
                            </div>
                        </div>

                        <!-- Business Type -->
                        <div class="bp-form-group">
                            <label class="bp-form-label required">
                                <span>Business Type<span class="req-star">*</span></span>
                            </label>
                            <div>
                                <select name="business_type" class="bp-input">
                                    <option value="Services" <?= $profile['business_type'] === 'Services' ? 'selected' : '' ?>>Services</option>
                                    <option value="Retail" <?= $profile['business_type'] === 'Retail' ? 'selected' : '' ?>>Retail</option>
                                    <option value="Wholesale & Distribution" <?= $profile['business_type'] === 'Wholesale & Distribution' ? 'selected' : '' ?>>Wholesale & Distribution</option>
                                    <option value="Manufacturing" <?= $profile['business_type'] === 'Manufacturing' ? 'selected' : '' ?>>Manufacturing</option>
                                    <option value="Food & Beverage" <?= $profile['business_type'] === 'Food & Beverage' ? 'selected' : '' ?>>Food & Beverage</option>
                                    <option value="E-Commerce / Direct to Consumer" <?= $profile['business_type'] === 'E-Commerce / Direct to Consumer' ? 'selected' : '' ?>>E-Commerce / Direct to Consumer</option>
                                    <option value="Other" <?= $profile['business_type'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <!-- Business Location -->
                        <div class="bp-form-group">
                            <label class="bp-form-label required">
                                <span>Business Location<span class="req-star">*</span></span>
                            </label>
                            <div>
                                <select name="business_location" class="bp-input">
                                    <option value="India" selected>India</option>
                                    <option value="United States">United States</option>
                                    <option value="United Kingdom">United Kingdom</option>
                                    <option value="United Arab Emirates">United Arab Emirates</option>
                                    <option value="Singapore">Singapore</option>
                                </select>
                            </div>
                        </div>

                        <!-- Phone -->
                        <div class="bp-form-group">
                            <label class="bp-form-label required">
                                <span>Phone<span class="req-star">*</span></span>
                            </label>
                            <div class="bp-phone-group">
                                <select name="phone_code" class="bp-phone-code">
                                    <option value="+91" <?= $profile['phone_code'] === '+91' ? 'selected' : '' ?>>+91 ∨</option>
                                    <option value="+1" <?= $profile['phone_code'] === '+1' ? 'selected' : '' ?>>+1 ∨</option>
                                    <option value="+44" <?= $profile['phone_code'] === '+44' ? 'selected' : '' ?>>+44 ∨</option>
                                    <option value="+971" <?= $profile['phone_code'] === '+971' ? 'selected' : '' ?>>+971 ∨</option>
                                </select>
                                <input type="text" name="phone" value="<?= e($profile['phone']) ?>" required class="bp-input" style="flex: 1;" placeholder="9755332357">
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="bp-form-group">
                            <label class="bp-form-label">
                                <span>Email</span>
                            </label>
                            <div>
                                <input type="email" name="email" value="<?= e($profile['email']) ?>" class="bp-input" placeholder="info@ominiflow.com">
                            </div>
                        </div>

                        <!-- Website -->
                        <div class="bp-form-group">
                            <label class="bp-form-label">
                                <span>Website</span>
                            </label>
                            <div>
                                <input type="text" name="website" value="<?= e($profile['website'] ?? '') ?>" class="bp-input" placeholder="e.g. zylker.com">
                            </div>
                        </div>
                    </div>

                    <!-- Right Logo Box -->
                    <div class="bp-logo-card">
                        <label for="logoFileInput" class="bp-logo-dropzone" id="logoDropzone">
                            <?php if (!empty($profile['logo_path']) && file_exists(__DIR__ . '/' . $profile['logo_path'])): ?>
                                <img src="<?= asset($profile['logo_path']) ?>" alt="Logo" class="bp-logo-preview" id="logoPreviewImg">
                            <?php else: ?>
                                <span class="bp-logo-text" id="logoPlaceholderText">Upload your logo</span>
                                <img src="" alt="Preview" class="bp-logo-preview" id="logoPreviewImg" style="display: none;">
                            <?php endif; ?>
                            <input type="file" name="logo" id="logoFileInput" accept="image/*" style="display: none;" onchange="previewLogo(this)">
                        </label>
                        <div class="bp-logo-subtext">
                            <div>This logo will appear on transactions and email notifications.</div>
                            <div style="color: #94a3b8; font-size: 11px; margin-top: 4px;">Preferred Image Size: 240px x 60px @ 72 DPI Maximum size of 1MB.</div>
                        </div>
                    </div>
                </div>

                <hr class="bp-divider">

                <!-- SECTION 2: Business Address -->
                <div class="bp-grid-2col">
                    <!-- Left Address Fields -->
                    <div>
                        <!-- Address Line 1 -->
                        <div class="bp-col-field">
                            <label class="bp-form-label">Address Line 1</label>
                            <input type="text" name="address_line1" value="<?= e($profile['address_line1'] ?? '') ?>" class="bp-input" placeholder="Door No/Building Name/Floor">
                        </div>

                        <!-- Address Line 2 -->
                        <div class="bp-col-field">
                            <label class="bp-form-label">Address Line 2</label>
                            <input type="text" name="address_line2" value="<?= e($profile['address_line2'] ?? '') ?>" class="bp-input" placeholder="Street/Area/Landmark">
                        </div>

                        <!-- City -->
                        <div class="bp-col-field">
                            <label class="bp-form-label">City</label>
                            <input type="text" name="city" value="<?= e($profile['city'] ?? '') ?>" class="bp-input" placeholder="Enter name of the town/city">
                        </div>
                    </div>

                    <!-- Right Address Fields -->
                    <div>
                        <!-- State -->
                        <div class="bp-col-field">
                            <label class="bp-form-label">State</label>
                            <select name="state" class="bp-input">
                                <?php foreach ($indianStates as $st): ?>
                                    <option value="<?= e($st) ?>" <?= ($profile['state'] ?? '') === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- ZIP / Postal Code -->
                        <div class="bp-col-field">
                            <label class="bp-form-label">ZIP/Postal Code</label>
                            <input type="text" name="zip_code" value="<?= e($profile['zip_code'] ?? '') ?>" class="bp-input" placeholder="Enter zip code">
                        </div>
                    </div>
                </div>

                <hr class="bp-divider">

                <!-- SECTION 3: Regional & Currency Settings -->
                <div class="bp-grid-2col">
                    <!-- Left: Fiscal Year & Timezone -->
                    <div>
                        <!-- Fiscal Year -->
                        <div class="bp-col-field">
                            <label class="bp-form-label">Fiscal Year</label>
                            <select name="fiscal_year" class="bp-input">
                                <option value="April - March" selected>April - March</option>
                                <option value="January - December">January - December</option>
                                <option value="July - June">July - June</option>
                                <option value="October - September">October - September</option>
                            </select>
                        </div>

                        <!-- Time Zone -->
                        <div class="bp-col-field">
                            <label class="bp-form-label">Time Zone</label>
                            <select name="time_zone" class="bp-input">
                                <option value="(GMT 05:30) India Standard Time (Asia/Calcutta)" selected>(GMT 05:30) India Standard Time (Asia/Calcutta)</option>
                                <option value="(GMT 00:00) UTC (Universal Time Coordinated)">(GMT 00:00) UTC</option>
                                <option value="(GMT -05:00) Eastern Standard Time (US & Canada)">(GMT -05:00) EST</option>
                                <option value="(GMT +04:00) Gulf Standard Time (Dubai)">(GMT +04:00) GST</option>
                                <option value="(GMT +08:00) Singapore Standard Time">(GMT +08:00) SGT</option>
                            </select>
                        </div>
                    </div>

                    <!-- Right: Base Currency & Date Format -->
                    <div>
                        <!-- Base Currency -->
                        <div class="bp-col-field">
                            <label class="bp-form-label">Base Currency</label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <select name="base_currency" class="bp-input" style="flex: 1;">
                                    <option value="INR" selected>INR</option>
                                    <option value="USD">USD ($)</option>
                                    <option value="EUR">EUR (€)</option>
                                    <option value="GBP">GBP (£)</option>
                                    <option value="AED">AED (د.إ)</option>
                                </select>
                                <a href="<?= asset('settings.php') ?>" style="color: #3b82f6; text-decoration: none; font-size: 16px;" title="Currency Settings">⚙</a>
                            </div>
                        </div>

                        <!-- Date Format -->
                        <div class="bp-col-field">
                            <label class="bp-form-label">Date Format</label>
                            <select name="date_format" class="bp-input">
                                <option value="dd MMM yyyy" selected>dd MMM yyyy [ 19 Aug 2026 ]</option>
                                <option value="dd/MM/yyyy">dd/MM/yyyy [ 19/08/2026 ]</option>
                                <option value="yyyy-MM-dd">yyyy-MM-dd [ 2026-08-19 ]</option>
                                <option value="MM/dd/yyyy">MM/dd/yyyy [ 08/19/2026 ]</option>
                            </select>
                        </div>
                    </div>
                </div>

                <hr class="bp-divider">

                <!-- SECTION 4: Bank Details (For Invoice) -->
                <div style="margin-bottom: 24px;">
                    <div style="font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">Bank Details (Printed on Invoices)</div>
                    <div style="font-size: 13px; color: #64748b; margin-bottom: 16px;">These bank details appear dynamically on all generated invoices for this business.</div>
                    
                    <div class="bp-grid-2col">
                        <div>
                            <div class="bp-col-field">
                                <label class="bp-form-label">Bank Name</label>
                                <input type="text" name="bank_name" value="<?= e($profile['bank_name'] ?? 'HDFC Bank') ?>" class="bp-input" placeholder="e.g. HDFC Bank">
                            </div>
                            <div class="bp-col-field">
                                <label class="bp-form-label">Account Number</label>
                                <input type="text" name="account_number" value="<?= e($profile['account_number'] ?? '50200111653091') ?>" class="bp-input" placeholder="Bank Account Number">
                            </div>
                            <div class="bp-col-field">
                                <label class="bp-form-label">Bank Branch</label>
                                <input type="text" name="bank_branch" value="<?= e($profile['bank_branch'] ?? 'DEWAS') ?>" class="bp-input" placeholder="e.g. DEWAS Branch">
                            </div>
                        </div>

                        <div>
                            <div class="bp-col-field">
                                <label class="bp-form-label">Account Holder Name</label>
                                <input type="text" name="account_holder" value="<?= e($profile['account_holder'] ?? $profile['business_name']) ?>" class="bp-input" placeholder="e.g. Ominiflow Enterprises">
                            </div>
                            <div class="bp-col-field">
                                <label class="bp-form-label">IFSC Code</label>
                                <input type="text" name="bank_ifsc" value="<?= e($profile['bank_ifsc'] ?? 'HDFC0000887') ?>" class="bp-input" placeholder="e.g. HDFC0000887" style="text-transform: uppercase;">
                            </div>
                            <div class="bp-col-field">
                                <label class="bp-form-label">Account Type</label>
                                <input type="text" name="account_type" value="<?= e($profile['account_type'] ?? 'Current Account') ?>" class="bp-input" placeholder="e.g. Current Account">
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="bp-divider">

                <!-- SECTION 5: Invoice Terms & Privacy Policy -->
                <div style="margin-bottom: 24px;">
                    <div style="font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">Invoice Terms & Privacy Policy</div>
                    <div style="font-size: 13px; color: #64748b; margin-bottom: 16px;">Customize the package name and policy notes printed at the footer of your invoices.</div>

                    <div class="bp-grid-2col">
                        <div>
                            <div class="bp-col-field">
                                <label class="bp-form-label">Invoice Package / Service Type</label>
                                <input type="text" name="package_name" value="<?= e($profile['package_name'] ?? 'Monthly') ?>" class="bp-input" placeholder="e.g. Monthly, Retail Sale, Online Order">
                            </div>
                            <div class="bp-col-field">
                                <label class="bp-form-label">Custom Privacy Policy</label>
                                <textarea name="privacy_policy" rows="3" class="bp-input" style="height: auto; resize: vertical;" placeholder="Enter custom privacy policy"><?= e($profile['privacy_policy'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div>
                            <div class="bp-col-field">
                                <label class="bp-form-label">Terms & Conditions</label>
                                <textarea name="terms_conditions" rows="4" class="bp-input" style="height: auto; resize: vertical;" placeholder="Enter invoice terms & conditions (e.g. Goods once sold...)"><?= e($profile['terms_conditions'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sticky Action Save Bar -->
                <div class="bp-action-bar">
                    <button type="submit" class="bp-btn-save">Save</button>
                </div>
            </form>
        </main>
    </div>

    <script>
        function previewLogo(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var img = document.getElementById('logoPreviewImg');
                    var txt = document.getElementById('logoPlaceholderText');
                    if (img) {
                        img.src = e.target.result;
                        img.style.display = 'block';
                    }
                    if (txt) {
                        txt.style.display = 'none';
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>
</html>

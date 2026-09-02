<?php
/**
 * OminiFlow POS - Consignment & COD Label Manifest
 * Official India Post Speed Post Domestic & COD Manifest Generation
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/barcode_helper.php';

require_auth();

$user = current_user();
$db = get_db();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Ensure consignment_manifests table exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `consignment_manifests` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `tracking_number` VARCHAR(100) NOT NULL,
            `product_name` VARCHAR(255) NULL,
            `service_label` VARCHAR(100) NOT NULL DEFAULT 'SPEED POST PARCEL DOMESTIC',
            `order_type` ENUM('Cash on Delivery', 'Prepaid') NOT NULL DEFAULT 'Cash on Delivery',
            `cod_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `sender_name` VARCHAR(255) NULL,
            `sender_owner` VARCHAR(255) NULL,
            `sender_address1` VARCHAR(255) NULL,
            `sender_address2` VARCHAR(255) NULL,
            `sender_state` VARCHAR(100) NULL,
            `sender_pincode` VARCHAR(20) NULL,
            `sender_mobile` VARCHAR(50) NULL,
            `receiver_name` VARCHAR(255) NOT NULL,
            `receiver_company` VARCHAR(255) NULL,
            `receiver_address1` VARCHAR(255) NULL,
            `receiver_address2` VARCHAR(255) NULL,
            `receiver_city` VARCHAR(100) NULL,
            `receiver_pincode` VARCHAR(20) NULL,
            `receiver_state` VARCHAR(100) NULL,
            `receiver_mobile` VARCHAR(50) NULL,
            `thank_you_message` VARCHAR(255) NULL,
            `footer_line` VARCHAR(255) NULL,
            `print_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Manifested',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_consignment_tracking` (`tracking_number`),
            INDEX `idx_consignment_date` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (\Throwable $t) {
    // Graceful fallback
}

// Handle AJAX Save or Form Submit for Manifest Label
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_label') {
        $trackingNumber = strtoupper(trim((string)($_POST['tracking_number'] ?? '')));
        if ($trackingNumber === '') {
            $trackingNumber = 'EY' . mt_rand(100000000, 999999999) . 'IN';
        }

        $productName = trim((string)($_POST['product_name'] ?? ''));
        $serviceLabel = trim((string)($_POST['service_label'] ?? 'SPEED POST PARCEL DOMESTIC'));
        $orderType = ($_POST['order_type'] ?? 'Cash on Delivery') === 'Prepaid' ? 'Prepaid' : 'Cash on Delivery';
        $codAmount = $orderType === 'Cash on Delivery' ? (float)($_POST['cod_amount'] ?? 0) : 0.0;

        $senderName = trim((string)($_POST['business_name'] ?? 'Mr. RAMESHBHAI CHAUDHARI'));
        $senderOwner = trim((string)($_POST['owner_name'] ?? ''));
        $senderAddr1 = trim((string)($_POST['address_line1'] ?? ''));
        $senderAddr2 = trim((string)($_POST['address_line2'] ?? ''));
        $senderState = trim((string)($_POST['state'] ?? ''));
        $senderPin = trim((string)($_POST['pincode'] ?? ''));
        $senderMobile = trim((string)($_POST['mobile'] ?? ''));
        $thankYou = trim((string)($_POST['thank_you_message'] ?? 'AAPKA BAHUT BAHUT SHUKRIYA!'));
        $footerLine = trim((string)($_POST['footer_line'] ?? ''));

        $receiverName = trim((string)($_POST['receiver_name'] ?? 'Mr. MOHAMAD HEDARBHAI'));
        $receiverCompany = trim((string)($_POST['receiver_company'] ?? ''));
        $receiverAddr1 = trim((string)($_POST['receiver_address1'] ?? ''));
        $receiverAddr2 = trim((string)($_POST['receiver_address2'] ?? ''));
        $receiverCity = trim((string)($_POST['receiver_city'] ?? ''));
        $receiverPin = trim((string)($_POST['receiver_pincode'] ?? ''));
        $receiverState = trim((string)($_POST['receiver_state'] ?? ''));
        $receiverMobile = trim((string)($_POST['receiver_mobile'] ?? ''));

        try {
            $stmt = $db->prepare("
                INSERT INTO consignment_manifests (
                    tracking_number, product_name, service_label, order_type, cod_amount,
                    sender_name, sender_owner, sender_address1, sender_address2, sender_state, sender_pincode, sender_mobile,
                    receiver_name, receiver_company, receiver_address1, receiver_address2, receiver_city, receiver_pincode, receiver_state, receiver_mobile,
                    thank_you_message, footer_line, status, created_at
                ) VALUES (
                    :track, :prod, :srv, :ord_type, :cod,
                    :s_name, :s_owner, :s_a1, :s_a2, :s_st, :s_pin, :s_mob,
                    :r_name, :r_comp, :r_a1, :r_a2, :r_city, :r_pin, :r_st, :r_mob,
                    :ty, :fl, 'Manifested', NOW()
                )
            ");
            $stmt->execute([
                'track' => $trackingNumber,
                'prod' => $productName,
                'srv' => $serviceLabel,
                'ord_type' => $orderType,
                'cod' => $codAmount,
                's_name' => $senderName,
                's_owner' => $senderOwner,
                's_a1' => $senderAddr1,
                's_a2' => $senderAddr2,
                's_st' => $senderState,
                's_pin' => $senderPin,
                's_mob' => $senderMobile,
                'r_name' => $receiverName,
                'r_comp' => $receiverCompany,
                'r_a1' => $receiverAddr1,
                'r_a2' => $receiverAddr2,
                'r_city' => $receiverCity,
                'r_pin' => $receiverPin,
                'r_st' => $receiverState,
                'r_mob' => $receiverMobile,
                'ty' => $thankYou,
                'fl' => $footerLine,
            ]);

            $savedId = (int)$db->lastInsertId();

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'id' => $savedId, 'tracking_number' => $trackingNumber]);
                exit;
            }

            set_flash('success', "Consignment Label #{$trackingNumber} logged to today's manifest!");
            redirect(APP_URL . '/consignment-manifest.php');
        } catch (\Throwable $e) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            set_flash('error', 'Could not save consignment: ' . $e->getMessage());
            redirect(APP_URL . '/consignment-manifest.php');
        }
    }

    if ($action === 'delete_manifest') {
        $id = (int)($_POST['manifest_id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM consignment_manifests WHERE id = :id");
            $stmt->execute(['id' => $id]);
            set_flash('success', 'Manifest entry removed successfully.');
        }
        redirect(APP_URL . '/consignment-manifest.php');
    }
}

// Fetch Business Profile defaults
$stmtBP = $db->query('SELECT * FROM business_profile WHERE id = 1 LIMIT 1');
$businessProfile = $stmtBP ? $stmtBP->fetch() : null;

// Initial client business details (exact parity with India Post Speed Post receipt)
$clientDefaultName = 'Mr. RAMESHBHAI CHAUDHARI';
$clientDefaultBizName = 'R BHEDRU ONLINE SELLING';
$clientDefaultAddress1 = 'PEPRAI';
$clientDefaultAddress2 = 'LAKHANI';
$clientDefaultTaluka = 'LAKHANI';
$clientDefaultDistrict = 'BANAS KANTHA';
$clientDefaultState = 'Gujarat';
$clientDefaultPincode = '385581';
$clientDefaultMobile = '9558572952';
$clientDefaultBookingOffice = 'LAKHANI S.O (385581)';
$clientDefaultGst = '24AAALH0747F1ZI';
$clientDefaultCustomerId = '1000060678';
$clientDefaultThankYou = 'AAPKA BAHUT BAHUT SHUKRIYA!';
$clientDefaultFooterLine = 'Customer Care: ' . $clientDefaultMobile;

$defaultBusinessName = $clientDefaultBizName;
$defaultSenderName = $clientDefaultName;
$defaultMobile = $clientDefaultMobile;

// Calculate initials for top-left badge
$words = explode(' ', trim($defaultBusinessName));
$initials = '';
foreach ($words as $w) {
    if (!empty($w)) {
        $initials .= strtoupper($w[0]);
        if (strlen($initials) >= 2) break;
    }
}
if (!$initials) $initials = 'RB';

// Fetch Today's Stats and Manifest Records
$todayStats = ['total_labels' => 0, 'total_cod' => 0];
$todayManifests = [];

try {
    $statQuery = $db->query("
        SELECT COUNT(*) AS total_labels, COALESCE(SUM(cod_amount), 0) AS total_cod
        FROM consignment_manifests
        WHERE DATE(created_at) = CURDATE()
    ");
    if ($statQuery) {
        $todayStats = $statQuery->fetch();
    }

    $listQuery = $db->query("
        SELECT * FROM consignment_manifests
        WHERE DATE(created_at) = CURDATE()
        ORDER BY id DESC
    ");
    if ($listQuery) {
        $todayManifests = $listQuery->fetchAll();
    }
} catch (\Throwable $t) {
    // Graceful fallback
}

// Fetch Recent Orders & Customers for 1-Click Dynamic Autofill
$recentOrders = [];
try {
    $stmtOrders = $db->query("
        SELECT o.id, o.order_number, o.total_amount, o.payment_method, o.created_at,
               COALESCE(c.name, 'Walk-in Customer') as customer_name,
               COALESCE(c.phone, '') as customer_phone,
               COALESCE(c.address, '') as customer_address
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        ORDER BY o.id DESC LIMIT 50
    ");
    if ($stmtOrders) {
        $recentOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $t) {}

$pageTitle = 'Consignment & COD Label Manifest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($defaultBusinessName) ?></title>
    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <script src="<?= asset('assets/js/qrcode.min.js') ?>"></script>

    <style>
        .manifest-container {
            padding: 16px 20px 40px;
            max-width: 1440px;
            margin: 0 auto;
        }

        .manifest-top-ribbon {
            background: #1b2533;
            color: #ffffff;
            border-radius: 10px;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .manifest-brand-info {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .manifest-brand-avatar {
            width: 44px;
            height: 44px;
            background: #d97706;
            color: #ffffff;
            font-weight: 800;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .manifest-brand-title {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin: 0;
            color: #ffffff;
            text-transform: uppercase;
        }

        .manifest-brand-sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .manifest-top-stats {
            display: flex;
            gap: 12px;
        }

        .stat-badge-box {
            background: #253346;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 8px 18px;
            min-width: 105px;
            text-align: center;
        }

        .stat-badge-val {
            font-size: 18px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.1;
        }

        .stat-badge-lbl {
            font-size: 10px;
            color: #94a3b8;
            font-weight: 700;
            letter-spacing: 0.6px;
            margin-top: 3px;
        }

        .manifest-grid {
            display: grid;
            grid-template-columns: 460px 1fr;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1100px) {
            .manifest-grid {
                grid-template-columns: 1fr;
            }
        }

        .m-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 20px;
            overflow: hidden;
            transition: box-shadow 0.2s;
        }

        .m-card:hover {
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
        }

        .m-card-header {
            padding: 14px 18px;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
        }

        .m-card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .m-badge-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #f59e0b;
        }

        .m-card-body {
            padding: 18px;
        }

        .m-form-group {
            margin-bottom: 12px;
        }

        .m-form-label {
            display: block;
            font-size: 10.5px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .m-form-input, .m-form-select {
            width: 100%;
            height: 38px;
            padding: 6px 12px;
            font-size: 13px;
            color: #1e293b;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            outline: none;
            transition: all 0.2s;
        }

        .m-form-input:focus, .m-form-select:focus {
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }

        .m-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .sub-section-title {
            font-size: 11px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 16px 0 10px;
            padding-bottom: 5px;
            border-bottom: 1px dashed #cbd5e1;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-m-clear {
            height: 40px;
            padding: 0 18px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #475569;
            font-weight: 600;
            font-size: 13px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-m-clear:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .btn-m-submit {
            height: 40px;
            padding: 0 20px;
            background: #0f172a;
            border: 1px solid #0f172a;
            color: #ffffff;
            font-weight: 700;
            font-size: 13px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-m-submit:hover {
            background: #1e293b;
        }

        .btn-m-print-main {
            background: #d97706;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            border: none;
            border-radius: 8px;
            padding: 14px 36px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
            transition: all 0.2s;
            width: 100%;
            max-width: 340px;
        }

        .btn-m-print-main:hover {
            background: #b45309;
            box-shadow: 0 6px 16px rgba(217, 119, 6, 0.4);
            transform: translateY(-1px);
        }

        .preview-pane {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .preview-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            margin-bottom: 20px;
            width: 100%;
            flex-wrap: wrap;
        }

        .preview-controls label {
            font-size: 12px;
            font-weight: 800;
            color: #475569;
            text-transform: uppercase;
        }

        /* ==========================================================================
           EXACT INDIA POST SPEED POST PARCEL DOMESTIC LABEL TEMPLATE (Large Screen Display)
           ========================================================================== */
        .indiapost-label-card {
            background: #ffffff;
            width: 100%;
            max-width: 680px;
            border: 2.5px solid #000000;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            position: relative;
            box-sizing: border-box;
            padding: 16px 20px;
            font-family: Arial, Helvetica, sans-serif;
            color: #000000;
            background-color: #fff;
            transition: all 0.2s ease;
        }

        /* Top Grid: QR Code (Left), Barcode & Heading (Center), India Post Logo (Right) */
        .ip-top-grid {
            display: grid;
            grid-template-columns: 130px 1fr 115px;
            gap: 12px;
            align-items: center;
            margin-bottom: 12px;
        }

        .ip-qr-box {
            width: 125px;
            height: 125px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .ip-qr-box img, .ip-qr-box canvas {
            width: 100% !important;
            height: auto !important;
            display: block;
        }

        .ip-barcode-center {
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .ip-service-title {
            font-size: 15px;
            font-weight: 900;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-bottom: 3px;
            line-height: 1.25;
        }

        .ip-cod-tag {
            font-size: 14px;
            font-weight: 900;
            margin-bottom: 5px;
        }

        .ip-barcode-svg-wrap {
            width: 100%;
            max-width: 340px;
        }

        .ip-barcode-svg {
            width: 100%;
            height: 64px;
            display: block;
            margin: 0 auto;
        }

        .ip-logo-box {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: flex-start;
        }

        .ip-logo-text-hi {
            font-size: 13px;
            font-weight: 900;
            color: #000000;
            line-height: 1.1;
        }

        .ip-logo-wing {
            width: 88px;
            height: 50px;
            background: #000000;
            margin: 3px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 2px;
            position: relative;
            overflow: hidden;
        }

        .ip-logo-text-en {
            font-size: 12px;
            font-weight: 800;
            color: #000000;
            line-height: 1.1;
        }

        /* Delivery Office & Pincode Box (Unbold Regular) */
        .ip-dely-box {
            border: 2.5px solid #000000;
            padding: 8px 14px;
            font-size: 17.5px;
            font-weight: 400;
            margin-bottom: 10px;
            box-sizing: border-box;
            background: #ffffff;
        }

        /* Booking Details Box (Unbold Regular) */
        .ip-booking-box {
            border: 2.5px solid #000000;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 400;
            line-height: 1.6;
            margin-bottom: 10px;
            box-sizing: border-box;
            background: #ffffff;
        }

        /* Sender / Receiver Table */
        .ip-parties-table {
            width: 100%;
            border: 2.5px solid #000000;
            border-collapse: collapse;
            margin-bottom: 12px;
            box-sizing: border-box;
            background: #ffffff;
        }

        .ip-parties-table th {
            border-bottom: 2.5px solid #000000;
            border-right: 2.5px solid #000000;
            padding: 8px 10px;
            font-size: 16px;
            font-weight: 900;
            text-align: center;
            background: #ffffff;
        }

        .ip-parties-table th:last-child {
            border-right: none;
        }

        /* Sender / Receiver Cell Data (Unbold Regular) */
        .ip-parties-table td {
            width: 50%;
            vertical-align: top;
            padding: 10px 14px;
            font-size: 13.5px;
            font-weight: 400;
            line-height: 1.6;
            border-right: 2.5px solid #000000;
        }

        .ip-parties-table td:last-child {
            border-right: none;
        }

        /* Footer Notes */
        .ip-footer-notice {
            font-size: 11.5px;
            font-style: italic;
            text-align: center;
            line-height: 1.5;
            color: #000000;
            margin-top: 6px;
        }

        .ip-footer-notice div {
            margin-bottom: 3px;
        }

        .manifest-table-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-top: 28px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .manifest-table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .manifest-table-title {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ==========================================================================
           PRINT MEDIA QUERIES (Full Sheet & Thermal Exact Sizing)
           ========================================================================== */
        @media print {
            @page {
                margin: 4mm !important;
            }
            body, html {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .app-sidebar,
            .app-main > header,
            .manifest-top-ribbon,
            .manifest-column-left,
            .preview-controls,
            .btn-m-print-main,
            .manifest-table-card,
            .saas-alert,
            nav {
                display: none !important;
            }
            .app-main {
                margin: 0 !important;
                padding: 0 !important;
            }
            .manifest-container {
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
            }
            .manifest-grid {
                display: block !important;
            }
            .preview-pane {
                background: transparent !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            /* A4 / DEFAULT FULL PAGE PRINT (Exact replica of Image, prominent and sharp) */
            .indiapost-label-card,
            .indiapost-label-card.size-a4 {
                width: 185mm !important;
                min-height: 255mm !important;
                border: 3.5px solid #000000 !important;
                box-shadow: none !important;
                margin: 3mm auto !important;
                padding: 16px 18px !important;
                page-break-after: always;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                box-sizing: border-box !important;
            }

            .indiapost-label-card .ip-top-grid,
            .indiapost-label-card.size-a4 .ip-top-grid {
                grid-template-columns: 140px 1fr 120px !important;
                margin-bottom: 12px !important;
            }
            .indiapost-label-card .ip-qr-box,
            .indiapost-label-card.size-a4 .ip-qr-box {
                width: 135px !important;
                height: 135px !important;
            }
            .indiapost-label-card .ip-service-title,
            .indiapost-label-card.size-a4 .ip-service-title {
                font-size: 16px !important;
                margin-bottom: 4px !important;
            }
            .indiapost-label-card .ip-cod-tag,
            .indiapost-label-card.size-a4 .ip-cod-tag {
                font-size: 15px !important;
                margin-bottom: 6px !important;
            }
            .indiapost-label-card .ip-barcode-svg-wrap,
            .indiapost-label-card.size-a4 .ip-barcode-svg-wrap {
                max-width: 380px !important;
            }
            .indiapost-label-card .ip-barcode-svg,
            .indiapost-label-card.size-a4 .ip-barcode-svg {
                height: 80px !important;
            }
            .indiapost-label-card .ip-barcode-svg text,
            .indiapost-label-card.size-a4 .ip-barcode-svg text {
                font-size: 18px !important;
            }
            .indiapost-label-card .ip-logo-wing,
            .indiapost-label-card.size-a4 .ip-logo-wing {
                width: 96px !important;
                height: 54px !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .indiapost-label-card.size-4x6 .ip-logo-wing {
                width: 68px !important;
                height: 38px !important;
            }
            .indiapost-label-card .ip-logo-text-hi,
            .indiapost-label-card.size-a4 .ip-logo-text-hi {
                font-size: 13px !important;
            }
            .indiapost-label-card .ip-logo-text-en,
            .indiapost-label-card.size-a4 .ip-logo-text-en {
                font-size: 12px !important;
            }

            .indiapost-label-card .ip-dely-box,
            .indiapost-label-card.size-a4 .ip-dely-box {
                border: 3px solid #000000 !important;
                font-size: 20px !important;
                font-weight: 400 !important;
                padding: 10px 14px !important;
                margin-bottom: 10px !important;
            }

            .indiapost-label-card .ip-booking-box,
            .indiapost-label-card.size-a4 .ip-booking-box {
                border: 3px solid #000000 !important;
                font-size: 14.5px !important;
                font-weight: 400 !important;
                line-height: 1.5 !important;
                padding: 10px 14px !important;
                margin-bottom: 10px !important;
            }

            .indiapost-label-card .ip-parties-table,
            .indiapost-label-card.size-a4 .ip-parties-table {
                border: 3px solid #000000 !important;
                margin-bottom: 12px !important;
            }
            .indiapost-label-card .ip-parties-table th,
            .indiapost-label-card.size-a4 .ip-parties-table th {
                border-bottom: 3px solid #000000 !important;
                border-right: 3px solid #000000 !important;
                font-size: 18px !important;
                padding: 8px !important;
            }
            .indiapost-label-card .ip-parties-table td,
            .indiapost-label-card.size-a4 .ip-parties-table td {
                border-right: 3px solid #000000 !important;
                font-size: 15.5px !important;
                font-weight: 400 !important;
                line-height: 1.55 !important;
                padding: 12px 14px !important;
            }

            .indiapost-label-card .ip-footer-notice,
            .indiapost-label-card.size-a4 .ip-footer-notice {
                font-size: 12.5px !important;
                line-height: 1.5 !important;
                margin-top: 8px !important;
            }

            /* 4x6 INCH THERMAL PRINT (100x150mm sticker roll) */
            .indiapost-label-card.size-4x6 {
                width: 98mm !important;
                min-height: 146mm !important;
                border: 2px solid #000000 !important;
                margin: 0 auto !important;
                padding: 8px !important;
            }
            .indiapost-label-card.size-4x6 .ip-top-grid {
                grid-template-columns: 75px 1fr 65px !important;
                margin-bottom: 4px !important;
            }
            .indiapost-label-card.size-4x6 .ip-qr-box { width: 70px !important; height: 70px !important; }
            .indiapost-label-card.size-4x6 .ip-service-title { font-size: 10px !important; }
            .indiapost-label-card.size-4x6 .ip-cod-tag { font-size: 9.5px !important; }
            .indiapost-label-card.size-4x6 .ip-barcode-svg { height: 42px !important; }
            .indiapost-label-card.size-4x6 .ip-dely-box { font-size: 11.5px !important; font-weight: 400 !important; padding: 4px 6px !important; }
            .indiapost-label-card.size-4x6 .ip-booking-box { font-size: 9px !important; font-weight: 400 !important; line-height: 1.3 !important; padding: 4px 6px !important; }
            .indiapost-label-card.size-4x6 .ip-parties-table th { font-size: 11px !important; padding: 4px !important; }
            .indiapost-label-card.size-4x6 .ip-parties-table td { font-size: 9.5px !important; font-weight: 400 !important; line-height: 1.35 !important; padding: 6px 8px !important; }
            .indiapost-label-card.size-4x6 .ip-footer-notice { font-size: 8px !important; line-height: 1.25 !important; }
        }
    </style>
</head>
<body>
    <div class="app-layout">
        <!-- Sidebar Navigation -->
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="app-main">
            <!-- Top Header Bar -->
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <div class="manifest-container">

                    <!-- Flash Messages -->
                    <?php if ($flashSuccess): ?>
                        <div class="saas-alert saas-alert-success" style="margin-bottom: 16px;">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span><?= e($flashSuccess) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($flashError): ?>
                        <div class="saas-alert saas-alert-danger" style="margin-bottom: 16px;">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span><?= e($flashError) ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Top Brand Ribbon -->
                    <div class="manifest-top-ribbon">
                        <div class="manifest-brand-info">
                            <div class="manifest-brand-avatar" id="topAvatar"><?= e($initials) ?></div>
                            <div>
                                <h1 class="manifest-brand-title" id="topBrandTitle"><?= e($defaultBusinessName) ?></h1>
                                <div class="manifest-brand-sub">Consignment &amp; COD Label Manifest • India Post Official Format</div>
                            </div>
                        </div>

                        <div class="manifest-top-stats">
                            <div class="stat-badge-box">
                                <div class="stat-badge-val" id="topLabelsCount"><?= (int)$todayStats['total_labels'] ?></div>
                                <div class="stat-badge-lbl">LABELS TODAY</div>
                            </div>
                            <div class="stat-badge-box">
                                <div class="stat-badge-val" id="topCodCount">₹<?= number_format((float)$todayStats['total_cod'], 0) ?></div>
                                <div class="stat-badge-lbl">COD TODAY</div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Workspace Two Columns -->
                    <div class="manifest-grid">

                        <!-- Left Column: Form Controls -->
                        <div class="manifest-column-left">

                            <!-- Card 1: SENDER / SENDER DETAILS -->
                            <div class="m-card">
                                <div class="m-card-header" onclick="toggleCard('businessDetailsBody')">
                                    <div class="m-card-title">
                                        <span class="m-badge-dot"></span>
                                        <span>SENDER &amp; BOOKING OFFICE DETAILS</span>
                                    </div>
                                    <svg width="16" height="16" fill="none" stroke="#64748b" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                                <div class="m-card-body" id="businessDetailsBody">
                                    <div class="m-form-group">
                                        <label class="m-form-label">SENDER NAME / OWNER</label>
                                        <input type="text" id="senderName" class="m-form-input" value="Mr. RAMESHBHAI CHAUDHARI" placeholder="Mr. RAMESHBHAI CHAUDHARI">
                                    </div>

                                    <div class="m-form-group">
                                        <label class="m-form-label">BUSINESS / FIRM NAME</label>
                                        <input type="text" id="bizName" class="m-form-input" value="R BHEDRU ONLINE SELLING" placeholder="R BHEDRU ONLINE SELLING">
                                    </div>

                                    <div class="m-form-group">
                                        <label class="m-form-label">SENDER MOBILE</label>
                                        <input type="text" id="bizMobile" class="m-form-input" value="9558572952" placeholder="9558572952">
                                    </div>

                                    <div class="m-form-row m-form-group">
                                        <div>
                                            <label class="m-form-label">VILLAGE / STREET</label>
                                            <input type="text" id="bizAddr1" class="m-form-input" value="PEPRAI" placeholder="PEPRAI">
                                        </div>
                                        <div>
                                            <label class="m-form-label">TALUKA / AREA</label>
                                            <input type="text" id="bizAddr2" class="m-form-input" value="LAKHANI" placeholder="LAKHANI">
                                        </div>
                                    </div>

                                    <div class="m-form-row m-form-group">
                                        <div>
                                            <label class="m-form-label">DISTRICT</label>
                                            <input type="text" id="bizDistrict" class="m-form-input" value="BANAS KANTHA" placeholder="BANAS KANTHA">
                                        </div>
                                        <div>
                                            <label class="m-form-label">STATE &amp; PINCODE</label>
                                            <input type="text" id="bizStatePin" class="m-form-input" value="Gujarat-385581" placeholder="Gujarat-385581">
                                        </div>
                                    </div>

                                    <div class="m-form-group">
                                        <label class="m-form-label">BOOKING OFFICE</label>
                                        <input type="text" id="bookingOffice" class="m-form-input" value="LAKHANI S.O (385581)" placeholder="LAKHANI S.O (385581)">
                                    </div>

                                    <div class="m-form-row m-form-group">
                                        <div>
                                            <label class="m-form-label">GST NO.</label>
                                            <input type="text" id="bookingGst" class="m-form-input" value="24AAALH0747F1ZI" placeholder="24AAALH0747F1ZI">
                                        </div>
                                        <div>
                                            <label class="m-form-label">CONTRACT CUSTOMER ID</label>
                                            <input type="text" id="bookingCustomerId" class="m-form-input" value="1000060678" placeholder="1000060678">
                                        </div>
                                    </div>

                                    <button type="button" onclick="saveBusinessDefaults()" style="font-size: 11px; background: none; border: 1px solid #cbd5e1; border-radius: 4px; padding: 5px 12px; color: #475569; cursor: pointer; font-weight: 600;">
                                        💾 Remember My Sender Details
                                    </button>
                                </div>
                            </div>

                            <!-- Card 2: NEW LABEL / PARCEL & RECEIVER -->
                            <div class="m-card">
                                <div class="m-card-header" onclick="toggleCard('newLabelBody')">
                                    <div class="m-card-title">
                                        <svg width="16" height="16" fill="none" stroke="#d97706" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        <span>PARCEL &amp; RECEIVER (DYNAMIC FOR ANY CUSTOMER)</span>
                                    </div>
                                    <svg width="16" height="16" fill="none" stroke="#64748b" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                                <div class="m-card-body" id="newLabelBody">
                                    
                                    <!-- Dynamic Order / Customer Autofill Quick Bar -->
                                    <div style="background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                            <label class="m-form-label" style="margin-bottom: 0; color: #0f172a; font-size: 11px;">
                                                ⚡ 1-CLICK SELECT CUSTOMER / ORDER
                                            </label>
                                            <div style="display: flex; gap: 8px;">
                                                <button type="button" onclick="loadSampleCustomer()" style="font-size: 10.5px; color: #475569; background: none; border: none; cursor: pointer; text-decoration: underline;">Sample</button>
                                                <button type="button" onclick="prepareNewCustomer()" style="font-size: 11px; color: #d97706; background: none; border: none; cursor: pointer; font-weight: 800;">➕ Next / New Customer</button>
                                            </div>
                                        </div>
                                        <select id="quickOrderSelect" class="m-form-select" onchange="autofillFromOrder(this.value)" style="font-weight: 700; font-size: 12.5px; border-color: #f59e0b;">
                                            <option value="">-- Choose From Recent Sales Orders (or enter custom below) --</option>
                                            <?php foreach ($recentOrders as $ro): ?>
                                                <?php
                                                    $dispName = $ro['customer_name'] ?: 'Customer #' . $ro['id'];
                                                    $dispTotal = '₹' . number_format((float)$ro['total_amount'], 2);
                                                    $jsonData = htmlspecialchars(json_encode([
                                                        'name' => $ro['customer_name'],
                                                        'phone' => $ro['customer_phone'],
                                                        'address' => $ro['customer_address'],
                                                        'amount' => (float)$ro['total_amount'],
                                                        'order_no' => $ro['order_number']
                                                    ]), ENT_QUOTES, 'UTF-8');
                                                ?>
                                                <option value='<?= $jsonData ?>'><?= e($dispName) ?> • <?= e($ro['order_number']) ?> (<?= $dispTotal ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="m-form-group">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                            <label class="m-form-label" style="margin-bottom: 0;">SPEED POST TRACKING NO.</label>
                                            <button type="button" onclick="generateRandomTracking()" style="font-size: 10px; color: #d97706; background: none; border: none; cursor: pointer; text-decoration: underline; font-weight: 700;">Generate Sample</button>
                                        </div>
                                        <input type="text" id="trackNo" class="m-form-input" value="EY360986535IN" placeholder="e.g. EY360986535IN" style="font-family: monospace; font-weight: 800; letter-spacing: 1px; font-size: 14px;">
                                    </div>

                                    <div class="m-form-row m-form-group">
                                        <div>
                                            <label class="m-form-label">SERVICE TYPE</label>
                                            <input type="text" id="srvLabel" class="m-form-input" value="SPEED POST PARCEL DOMESTIC" placeholder="SPEED POST PARCEL DOMESTIC">
                                        </div>
                                        <div>
                                            <label class="m-form-label">PAYMENT TYPE</label>
                                            <select id="ordType" class="m-form-select" onchange="toggleCodAmount()">
                                                <option value="Cash on Delivery" selected>Cash on Delivery (COD)</option>
                                                <option value="Prepaid">Prepaid DropOff</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="m-form-row m-form-group">
                                        <div id="codAmountGroup">
                                            <label class="m-form-label">COD AMOUNT (₹)</label>
                                            <input type="number" id="codAmount" class="m-form-input" value="999" placeholder="999" min="0" step="1">
                                        </div>
                                        <div>
                                            <label class="m-form-label">WEIGHT (GMS)</label>
                                            <input type="text" id="parcelWeight" class="m-form-input" value="500" placeholder="500">
                                        </div>
                                    </div>

                                    <!-- Receiver Customer Details (Fully Dynamic) -->
                                    <div class="sub-section-title">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        <span>RECEIVER DETAILS (DYNAMIC FOR EACH PARCEL)</span>
                                    </div>

                                    <div class="m-form-group">
                                        <label class="m-form-label">RECEIVER / CUSTOMER FULL NAME *</label>
                                        <input type="text" id="custName" class="m-form-input" value="Mr. MOHAMAD HEDARBHAI" placeholder="Enter customer name">
                                    </div>

                                    <div class="m-form-group">
                                        <label class="m-form-label">RECEIVER MOBILE NUMBER *</label>
                                        <input type="text" id="custMobile" class="m-form-input" value="7567122001" placeholder="10-digit mobile number">
                                    </div>

                                    <div class="m-form-group">
                                        <label class="m-form-label">ADDRESS LINE 1 (HOUSE / BUILDING / ROAD)</label>
                                        <input type="text" id="custAddr1" class="m-form-input" value="AMENA KHATU HOSPITAL" placeholder="Flat, house no, building, road">
                                    </div>

                                    <div class="m-form-group">
                                        <label class="m-form-label">ADDRESS LINE 2 (AREA / LANDMARK)</label>
                                        <input type="text" id="custAddr2" class="m-form-input" value="NI BAJU MA, JUHAPURA" placeholder="Colony, street, landmark">
                                    </div>

                                    <div class="m-form-row m-form-group">
                                        <div>
                                            <label class="m-form-label">DELIVERY S.O / POST OFFICE</label>
                                            <input type="text" id="custDelySO" class="m-form-input" value="Juhapura SO" placeholder="e.g. Juhapura SO">
                                        </div>
                                        <div>
                                            <label class="m-form-label">PINCODE *</label>
                                            <input type="text" id="custPin" class="m-form-input" value="380055" placeholder="6-digit pincode">
                                        </div>
                                    </div>

                                    <div class="m-form-row m-form-group">
                                        <div>
                                            <label class="m-form-label">CITY / DISTRICT</label>
                                            <input type="text" id="custCity" class="m-form-input" value="AHMADABAD" placeholder="City / District">
                                        </div>
                                        <div>
                                            <label class="m-form-label">STATE</label>
                                            <input type="text" id="custState" class="m-form-input" value="Gujarat" placeholder="State">
                                        </div>
                                    </div>

                                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                                        <button type="button" class="btn-m-clear" onclick="prepareNewCustomer()">➕ Next Customer</button>
                                        <button type="button" class="btn-m-submit" style="flex: 1; justify-content: center;" onclick="saveAndPrint()">
                                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                            <span>Print Official Label</span>
                                        </button>
                                    </div>

                                </div>
                            </div>

                        </div>

                        <!-- Right Column: Live Exact India Post Label Preview -->
                        <div class="preview-pane">

                            <!-- Controls -->
                            <div class="preview-controls">
                                <label for="printSize">PRINT SIZE:</label>
                                <select id="printSize" class="m-form-select" style="width: auto; min-width: 250px; font-weight: 700; color: #0f172a;" onchange="adjustPrintSize(this.value)">
                                    <option value="a4" selected>A4 Full Page / PDF (Large &amp; Clear)</option>
                                    <option value="4x6">4 × 6 in (Thermal Sticker Printer)</option>
                                    <option value="half">A4 Half Page (2 Per Sheet)</option>
                                    <option value="thermal">Thermal 3 × 2 in (Small Label)</option>
                                </select>
                            </div>

                            <!-- Big Orange Print Button -->
                            <div style="margin-bottom: 24px; width: 100%; display: flex; justify-content: center;">
                                <button type="button" class="btn-m-print-main" onclick="saveAndPrint()">
                                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                    <span>Print Official Label</span>
                                </button>
                            </div>

                            <!-- EXACT INDIA POST OFFICIAL LABEL PREVIEW CARD -->
                            <div class="indiapost-label-card" id="printableLabelCard">

                                <!-- Top Grid: QR Code | Barcode & Service Title | India Post Logo -->
                                <div class="ip-top-grid">
                                    
                                    <!-- QR Code Left -->
                                    <div class="ip-qr-box" id="qrContainer">
                                        <!-- Dynamically generated via qrcode.js -->
                                    </div>

                                    <!-- Barcode Center -->
                                    <div class="ip-barcode-center">
                                        <div class="ip-service-title" id="lblPreviewService">SPEED POST PARCEL DOMESTIC</div>
                                        <div class="ip-cod-tag" id="lblPreviewCod">COD:999 DropOff</div>
                                        <div class="ip-barcode-svg-wrap" id="barcodeContainer">
                                            <!-- SVG barcode drawn here -->
                                        </div>
                                    </div>

                                    <!-- India Post Logo Right -->
                                    <div class="ip-logo-box">
                                        <div class="ip-logo-text-hi">भारतीय डाक</div>
                                        <div class="ip-logo-wing">
                                            <img src="<?= asset('assets/images/india-post-wing-exact.png') ?>" alt="India Post" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                                        </div>
                                        <div class="ip-logo-text-en">India Post</div>
                                    </div>

                                </div>

                                <!-- Delivery Office & Pincode Box -->
                                <div class="ip-dely-box" id="lblPreviewDelyOffice">
                                    Dely Office &amp; Pincode: Juhapura SO(380055)
                                </div>

                                <!-- Booking Office & Tariff Box (Unbold Regular) -->
                                <div class="ip-booking-box">
                                    <div>Booking Office: <span id="lblPreviewBookingOffice">LAKHANI S.O (385581)</span></div>
                                    <div>CounterNo. 0, <span id="lblPreviewTimestamp"><?= date('d-m-Y H:i:s') ?></span></div>
                                    <div>GSTNo.<span id="lblPreviewGst">24AAALH0747F1ZI</span> BkgRefID: <span id="lblPreviewBkgRef">1666012713052605627</span></div>
                                    <div>ChargedWeight(gms):<span id="lblPreviewWeight">500</span> Phy.Wt(gms):<span id="lblPreviewPhyWeight">500</span> Vol.Wt(gms):280(L:14 B:10 H:10)</div>
                                    <div>AmountPaid:60.00(Base Tariff:50.00 + Tax:10.00) (CGST:5.00 SGST:5.00)</div>
                                    <div>ModeofPayment: CONTRACT Customer ID: <span id="lblPreviewCustId">1000060678</span></div>
                                </div>

                                <!-- Sender & Receiver Table (Unbold Regular Data) -->
                                <table class="ip-parties-table">
                                    <thead>
                                        <tr>
                                            <th>Sender</th>
                                            <th>Receiver</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td id="lblPreviewSenderCol">
                                                <div id="lblPreviewSenderName">Mr. RAMESHBHAI CHAUDHARI</div>
                                                <div id="lblPreviewSenderMob">Mobile No.9558572952</div>
                                                <div id="lblPreviewSenderA1">PEPRAI</div>
                                                <div id="lblPreviewSenderA2">LAKHANI</div>
                                                <div id="lblPreviewSenderTaluka">LAKHANI</div>
                                                <div id="lblPreviewSenderDist">BANAS KANTHA</div>
                                                <div id="lblPreviewSenderStatePin">Gujarat-385581</div>
                                            </td>
                                            <td id="lblPreviewReceiverCol">
                                                <div id="lblPreviewRecName">Mr. MOHAMAD HEDARBHAI</div>
                                                <div id="lblPreviewRecMob">Mobile No.7567122001</div>
                                                <div id="lblPreviewRecA1">AMENA KHATU HOSPITAL</div>
                                                <div id="lblPreviewRecA2">NI BAJU MA, JUHAPURA</div>
                                                <div>&nbsp;</div>
                                                <div id="lblPreviewRecCity">AHMADABAD</div>
                                                <div id="lblPreviewRecStatePin">Gujarat-380055</div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>

                                <!-- Official Footer Notice -->
                                <div class="ip-footer-notice">
                                    <div>Track on <em>www.indiapost.gov.in</em> OR Dial 18002666868 : IVR NO : <span id="lblPreviewIvr">6989360986535</span></div>
                                    <div>In case of any complaint, please visit <em>https://crm.indiapost.gov.in/customer</em></div>
                                    <div>Go Green!!! Opt for eReceipts, ePOD</div>
                                    <div>This is system generated document, no manual signature required</div>
                                    <div id="lblPreviewPrintTime"><?= date('d-m-Y H:i:s') ?></div>
                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- Manifest Log Table for Courier Handover -->
                    <div class="manifest-table-card">
                        <div class="manifest-table-header">
                            <div class="manifest-table-title">
                                <svg width="20" height="20" fill="none" stroke="#d97706" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                                <span>Today's Consignment Manifest (<?= date('d M Y') ?>)</span>
                            </div>
                            <button type="button" class="btn-m-clear" onclick="window.print()" style="font-weight: 700; color: #0f172a;">
                                📄 Print Dispatch Handover
                            </button>
                        </div>

                        <div class="table-wrap" style="overflow-x: auto;">
                            <table class="saas-table" style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr>
                                        <th style="padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase;">#</th>
                                        <th style="padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase;">Tracking No</th>
                                        <th style="padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase;">Customer Name</th>
                                        <th style="padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase;">Destination</th>
                                        <th style="padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase;">Service</th>
                                        <th style="padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase;">Type</th>
                                        <th style="padding: 10px; text-align: right; font-size: 11px; text-transform: uppercase;">COD (₹)</th>
                                        <th style="padding: 10px; text-align: center; font-size: 11px; text-transform: uppercase;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($todayManifests)): ?>
                                        <tr>
                                            <td colspan="8" style="text-align: center; padding: 24px; color: #94a3b8; font-size: 13px;">
                                                No consignments generated yet today. Fill in the details above and click <strong>Print Official Label</strong> to log to today's manifest.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($todayManifests as $idx => $m): ?>
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 10px; font-size: 12px; color: #64748b;"><?= $idx + 1 ?></td>
                                                <td style="padding: 10px; font-family: monospace; font-weight: 700; color: #0f172a;"><?= e($m['tracking_number']) ?></td>
                                                <td style="padding: 10px; font-size: 13px; font-weight: 600;"><?= e($m['receiver_name']) ?></td>
                                                <td style="padding: 10px; font-size: 12px; color: #475569;">
                                                    <?= e($m['receiver_city']) ?>, <?= e($m['receiver_pincode']) ?>
                                                </td>
                                                <td style="padding: 10px; font-size: 11px; font-weight: 700; color: #d97706;"><?= e($m['service_label']) ?></td>
                                                <td style="padding: 10px; font-size: 12px;">
                                                    <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; background: <?= $m['order_type'] === 'Cash on Delivery' ? '#fef3c7; color: #92400e;' : '#e0f2fe; color: #0369a1;' ?>">
                                                        <?= e($m['order_type']) ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 10px; text-align: right; font-weight: 800; font-size: 13px;">
                                                    <?= $m['order_type'] === 'Cash on Delivery' ? '₹' . number_format((float)$m['cod_amount'], 2) : '—' ?>
                                                </td>
                                                <td style="padding: 10px; text-align: center; display: flex; gap: 8px; justify-content: center; align-items: center;">
                                                    <button type="button" class="btn-m-clear" style="height: 28px; padding: 0 10px; font-size: 11px; font-weight: 700; color: #d97706; border-color: #fcd34d; background: #fffbeb;" onclick='loadManifestRow(<?= htmlspecialchars(json_encode($m), ENT_QUOTES, "UTF-8") ?>)' title="Load into Label">
                                                        👁 Load
                                                    </button>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this manifest entry?');">
                                                        <input type="hidden" name="action" value="delete_manifest">
                                                        <input type="hidden" name="manifest_id" value="<?= (int)$m['id'] ?>">
                                                        <button type="submit" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 12px;" title="Delete">🗑</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <!-- Real-Time Code128 Vector SVG & QRCode Generator Engine -->
    <script>
        // High Performance Pure JavaScript Code128 Barcode Vector SVG Generator
        function generateCode128Svg(text, barHeight = 58, barWidth = 2.0) {
            text = (text || 'EY360986535IN').trim();
            const patterns = [
                '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
                '221312', '231212', '112232', '122132', '112322', '122231', '113222', '123122', '123221', '223211',
                '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112',
                '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311',
                '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121',
                '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311',
                '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221',
                '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112',
                '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211',
                '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311',
                '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112'
            ];

            const startB = 104;
            const stop = 106;
            let values = [startB];
            let checksum = startB;

            for (let i = 0; i < text.length; i++) {
                let ascii = text.charCodeAt(i);
                let val = (ascii >= 32 && ascii <= 126) ? (ascii - 32) : 0;
                values.push(val);
                checksum += (val * (i + 1));
            }

            values.push(checksum % 103);
            values.push(stop);

            let patternStr = '';
            for (let v of values) {
                patternStr += patterns[v] || '211214';
            }
            patternStr += '2'; // termination

            let totalModules = 0;
            for (let char of patternStr) totalModules += parseInt(char, 10);

            const quietZone = 6;
            const svgWidth = (totalModules + (quietZone * 2)) * barWidth;
            const svgHeight = barHeight + 20;

            let rects = '';
            let currentX = quietZone * barWidth;
            let isBar = true;

            for (let char of patternStr) {
                let w = parseInt(char, 10) * barWidth;
                if (isBar) {
                    rects += `<rect x="${currentX.toFixed(2)}" y="0" width="${w.toFixed(2)}" height="${barHeight}" fill="#000000"/>`;
                }
                currentX += w;
                isBar = !isBar;
            }

            return `
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${svgWidth} ${svgHeight}" class="ip-barcode-svg" preserveAspectRatio="xMidYMid meet">
                    <rect width="100%" height="100%" fill="transparent"/>
                    ${rects}
                    <text x="${(svgWidth / 2).toFixed(2)}" y="${barHeight + 16}" font-family="Arial, Helvetica, sans-serif" font-size="16" font-weight="900" fill="#000000" text-anchor="middle" letter-spacing="2">${text}</text>
                </svg>
            `;
        }

        // Generate 2D QR Code matching India Post QR
        let qrCodeObj = null;
        function updateQrCode(text) {
            const container = document.getElementById('qrContainer');
            if (!container) return;
            container.innerHTML = '';
            if (typeof QRCode !== 'undefined') {
                qrCodeObj = new QRCode(container, {
                    text: text || 'EY360986535IN',
                    width: 125,
                    height: 125,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        }

        // Live Real-Time Updating Elements
        function updateLiveLabel() {
            // Sender details
            const sName = document.getElementById('senderName').value || 'Mr. RAMESHBHAI CHAUDHARI';
            const bName = document.getElementById('bizName').value || 'R BHEDRU ONLINE SELLING';
            const sMobile = document.getElementById('bizMobile').value || '9558572952';
            const sAddr1 = document.getElementById('bizAddr1').value || 'PEPRAI';
            const sAddr2 = document.getElementById('bizAddr2').value || 'LAKHANI';
            const sDist = document.getElementById('bizDistrict').value || 'BANAS KANTHA';
            const sStatePin = document.getElementById('bizStatePin').value || 'Gujarat-385581';
            const bOffice = document.getElementById('bookingOffice').value || 'LAKHANI S.O (385581)';
            const bGst = document.getElementById('bookingGst').value || '24AAALH0747F1ZI';
            const bCustId = document.getElementById('bookingCustomerId').value || '1000060678';

            // Ribbon header initials
            document.getElementById('topBrandTitle').textContent = bName;
            const words = bName.trim().split(' ');
            let init = '';
            for (let w of words) {
                if (w) { init += w[0].toUpperCase(); if (init.length >= 2) break; }
            }
            document.getElementById('topAvatar').textContent = init || 'RB';

            // Sync Sender on Label
            document.getElementById('lblPreviewSenderName').textContent = sName;
            document.getElementById('lblPreviewSenderMob').textContent = 'Mobile No.' + sMobile;
            document.getElementById('lblPreviewSenderA1').textContent = sAddr1;
            document.getElementById('lblPreviewSenderA2').textContent = sAddr2;
            document.getElementById('lblPreviewSenderTaluka').textContent = sAddr2;
            document.getElementById('lblPreviewSenderDist').textContent = sDist;
            document.getElementById('lblPreviewSenderStatePin').textContent = sStatePin;
            document.getElementById('lblPreviewBookingOffice').textContent = bOffice;
            document.getElementById('lblPreviewGst').textContent = bGst;
            document.getElementById('lblPreviewCustId').textContent = bCustId;

            // Tracking & Shipment
            const trackNo = (document.getElementById('trackNo').value || 'EY360986535IN').trim().toUpperCase();
            const srvLabel = (document.getElementById('srvLabel').value || 'SPEED POST PARCEL DOMESTIC').toUpperCase();
            const ordType = document.getElementById('ordType').value;
            const codAmount = document.getElementById('codAmount').value || '999';
            const weight = document.getElementById('parcelWeight').value || '500';

            document.getElementById('lblPreviewService').textContent = srvLabel;
            if (ordType === 'Cash on Delivery') {
                document.getElementById('lblPreviewCod').textContent = `COD:${codAmount} DropOff`;
            } else {
                document.getElementById('lblPreviewCod').textContent = `PREPAID DropOff`;
            }
            document.getElementById('lblPreviewWeight').textContent = weight;
            document.getElementById('lblPreviewPhyWeight').textContent = weight;

            // Receiver details
            const rName = document.getElementById('custName').value || 'Mr. MOHAMAD HEDARBHAI';
            const rMobile = document.getElementById('custMobile').value || '7567122001';
            const rAddr1 = document.getElementById('custAddr1').value || 'AMENA KHATU HOSPITAL';
            const rAddr2 = document.getElementById('custAddr2').value || 'NI BAJU MA, JUHAPURA';
            const rDelySO = document.getElementById('custDelySO').value || 'Juhapura SO';
            const rCity = document.getElementById('custCity').value || 'AHMADABAD';
            const rPin = document.getElementById('custPin').value || '380055';
            const rState = document.getElementById('custState').value || 'Gujarat';

            document.getElementById('lblPreviewDelyOffice').textContent = `Dely Office & Pincode:${rDelySO}(${rPin})`;
            document.getElementById('lblPreviewRecName').textContent = rName;
            document.getElementById('lblPreviewRecMob').textContent = 'Mobile No.' + rMobile;
            document.getElementById('lblPreviewRecA1').textContent = rAddr1;
            document.getElementById('lblPreviewRecA2').textContent = rAddr2;
            document.getElementById('lblPreviewRecCity').textContent = rCity;
            document.getElementById('lblPreviewRecStatePin').textContent = `${rState}-${rPin}`;

            // IVR number from tracking
            const numOnly = trackNo.replace(/\D/g, '');
            document.getElementById('lblPreviewIvr').textContent = '6989' + (numOnly || '360986535');

            // Draw Barcode & QR Code
            document.getElementById('barcodeContainer').innerHTML = generateCode128Svg(trackNo);
            updateQrCode(trackNo);
        }

        // Toggle COD Amount field based on Order Type
        function toggleCodAmount() {
            const ordType = document.getElementById('ordType').value;
            const codGrp = document.getElementById('codAmountGroup');
            if (ordType === 'Prepaid') {
                codGrp.style.opacity = '0.5';
                document.getElementById('codAmount').disabled = true;
            } else {
                codGrp.style.opacity = '1';
                document.getElementById('codAmount').disabled = false;
            }
            updateLiveLabel();
        }

        // Generate Random Speed Post Tracking Number
        function generateRandomTracking() {
            const random9 = Math.floor(100000000 + Math.random() * 900000000);
            document.getElementById('trackNo').value = `EY${random9}IN`;
            updateLiveLabel();
        }

        // Clear Customer Form
        function clearCustomerForm() {
            document.getElementById('custName').value = '';
            document.getElementById('custMobile').value = '';
            document.getElementById('custAddr1').value = '';
            document.getElementById('custAddr2').value = '';
            document.getElementById('custDelySO').value = '';
            document.getElementById('custCity').value = '';
            document.getElementById('custPin').value = '';
            document.getElementById('custState').value = '';
            generateRandomTracking();
        }

        function toggleCard(bodyId) {
            const el = document.getElementById(bodyId);
            el.style.display = (el.style.display === 'none') ? 'block' : 'none';
        }

        // Remember manual sender details in localStorage
        function saveBusinessDefaults() {
            const bizData = {
                senderName: document.getElementById('senderName').value,
                bizName: document.getElementById('bizName').value,
                mobile: document.getElementById('bizMobile').value,
                addr1: document.getElementById('bizAddr1').value,
                addr2: document.getElementById('bizAddr2').value,
                district: document.getElementById('bizDistrict').value,
                statePin: document.getElementById('bizStatePin').value,
                bookingOffice: document.getElementById('bookingOffice').value,
                bookingGst: document.getElementById('bookingGst').value,
                bookingCustomerId: document.getElementById('bookingCustomerId').value
            };
            localStorage.setItem('rbhedru_indiapost_sender', JSON.stringify(bizData));
            alert('Sender details remembered successfully! They will load automatically for all future sessions.');
        }

        function loadSavedBusinessDefaults() {
            const saved = localStorage.getItem('rbhedru_indiapost_sender');
            if (saved) {
                try {
                    const data = JSON.parse(saved);
                    if (data.senderName) document.getElementById('senderName').value = data.senderName;
                    if (data.bizName) document.getElementById('bizName').value = data.bizName;
                    if (data.mobile) document.getElementById('bizMobile').value = data.mobile;
                    if (data.addr1) document.getElementById('bizAddr1').value = data.addr1;
                    if (data.addr2) document.getElementById('bizAddr2').value = data.addr2;
                    if (data.district) document.getElementById('bizDistrict').value = data.district;
                    if (data.statePin) document.getElementById('bizStatePin').value = data.statePin;
                    if (data.bookingOffice) document.getElementById('bookingOffice').value = data.bookingOffice;
                    if (data.bookingGst) document.getElementById('bookingGst').value = data.bookingGst;
                    if (data.bookingCustomerId) document.getElementById('bookingCustomerId').value = data.bookingCustomerId;
                } catch (e) {}
            }
        }

        // Adjust Print Size CSS & Dynamic @page media rule
        function adjustPrintSize(size) {
            const card = document.getElementById('printableLabelCard');
            if (!card) return;

            card.classList.remove('size-a4', 'size-4x6', 'size-half', 'size-thermal');
            card.classList.add('size-' + size);

            let pageRule = '';
            if (size === '4x6') {
                pageRule = '@page { size: 100mm 150mm; margin: 2mm !important; }';
                card.style.maxWidth = '580px';
                card.style.width = '100%';
            } else if (size === 'half') {
                pageRule = '@page { size: A4 portrait; margin: 6mm !important; }';
                card.style.maxWidth = '680px';
                card.style.width = '100%';
            } else if (size === 'thermal') {
                pageRule = '@page { size: 75mm 50mm; margin: 2mm !important; }';
                card.style.maxWidth = '460px';
                card.style.width = '100%';
            } else { // 'a4' default
                pageRule = '@page { size: A4 portrait; margin: 4mm !important; }';
                card.style.maxWidth = '680px';
                card.style.width = '100%';
            }

            let dynStyle = document.getElementById('dynamicPageStyle');
            if (!dynStyle) {
                dynStyle = document.createElement('style');
                dynStyle.id = 'dynamicPageStyle';
                document.head.appendChild(dynStyle);
            }
            dynStyle.textContent = pageRule;
        }

        // Autofill Customer Details from selected Sales Order
        function autofillFromOrder(jsonStr) {
            if (!jsonStr) return;
            try {
                const data = JSON.parse(jsonStr);
                if (data.name) document.getElementById('custName').value = data.name;
                if (data.phone) document.getElementById('custMobile').value = data.phone;
                if (data.address) {
                    document.getElementById('custAddr1').value = data.address;
                }
                if (data.amount) {
                    document.getElementById('codAmount').value = Math.round(data.amount);
                    document.getElementById('ordType').value = 'Cash on Delivery';
                    toggleCodAmount();
                }

                // Fresh tracking number for each new order
                generateRandomTracking();
                updateLiveLabel();
            } catch (e) {
                console.error('Error autofilling order:', e);
            }
        }

        // Prepare form for Next / New Customer (Full reset with fresh tracking number)
        function prepareNewCustomer() {
            document.getElementById('quickOrderSelect').value = '';
            document.getElementById('custName').value = '';
            document.getElementById('custMobile').value = '';
            document.getElementById('custAddr1').value = '';
            document.getElementById('custAddr2').value = '';
            document.getElementById('custDelySO').value = '';
            document.getElementById('custCity').value = '';
            document.getElementById('custPin').value = '';
            document.getElementById('custState').value = 'Gujarat';
            document.getElementById('codAmount').value = '0';
            
            generateRandomTracking();
            updateLiveLabel();

            // Focus name input for fast typing
            const custNameInput = document.getElementById('custName');
            if (custNameInput) {
                custNameInput.focus();
            }
        }

        // Load Sample Customer (for preview / testing)
        function loadSampleCustomer() {
            document.getElementById('custName').value = 'Mr. MOHAMAD HEDARBHAI';
            document.getElementById('custMobile').value = '7567122001';
            document.getElementById('custAddr1').value = 'AMENA KHATU HOSPITAL';
            document.getElementById('custAddr2').value = 'NI BAJU MA, JUHAPURA';
            document.getElementById('custDelySO').value = 'Juhapura SO';
            document.getElementById('custCity').value = 'AHMADABAD';
            document.getElementById('custPin').value = '380055';
            document.getElementById('custState').value = 'Gujarat';
            document.getElementById('codAmount').value = '999';
            document.getElementById('trackNo').value = 'EY360986535IN';
            updateLiveLabel();
        }

        // Load a previously saved manifest row into live preview & form
        function loadManifestRow(row) {
            if (!row) return;
            document.getElementById('trackNo').value = row.tracking_number || '';
            document.getElementById('srvLabel').value = row.service_label || 'SPEED POST PARCEL DOMESTIC';
            document.getElementById('ordType').value = row.order_type || 'Cash on Delivery';
            document.getElementById('codAmount').value = Math.round(row.cod_amount || 0);

            document.getElementById('custName').value = row.receiver_name || '';
            document.getElementById('custMobile').value = row.receiver_mobile || '';
            document.getElementById('custAddr1').value = row.receiver_address1 || '';
            document.getElementById('custAddr2').value = row.receiver_address2 || '';
            document.getElementById('custCity').value = row.receiver_city || '';
            document.getElementById('custPin').value = row.receiver_pincode || '';
            document.getElementById('custState').value = row.receiver_state || 'Gujarat';
            document.getElementById('custDelySO').value = (row.receiver_city ? row.receiver_city + ' SO' : 'Delivery SO');

            toggleCodAmount();
            updateLiveLabel();

            // Smooth scroll up to preview
            const card = document.getElementById('printableLabelCard');
            if (card) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // Save into manifest and trigger Print
        function saveAndPrint() {
            const currentSize = document.getElementById('printSize') ? document.getElementById('printSize').value : 'a4';
            adjustPrintSize(currentSize);

            const curTrackNo = document.getElementById('trackNo').value;
            const curCustName = document.getElementById('custName').value || 'Customer';

            const formData = new FormData();
            formData.append('action', 'save_label');
            formData.append('tracking_number', curTrackNo);
            formData.append('product_name', 'Parcel');
            formData.append('service_label', document.getElementById('srvLabel').value);
            formData.append('order_type', document.getElementById('ordType').value);
            formData.append('cod_amount', document.getElementById('codAmount').value);

            formData.append('business_name', document.getElementById('senderName').value);
            formData.append('owner_name', document.getElementById('bizName').value);
            formData.append('address_line1', document.getElementById('bizAddr1').value);
            formData.append('address_line2', document.getElementById('bizAddr2').value);
            formData.append('state', document.getElementById('bizStatePin').value);
            formData.append('pincode', '385581');
            formData.append('mobile', document.getElementById('bizMobile').value);

            formData.append('receiver_name', curCustName);
            formData.append('receiver_company', '');
            formData.append('receiver_address1', document.getElementById('custAddr1').value);
            formData.append('receiver_address2', document.getElementById('custAddr2').value);
            formData.append('receiver_city', document.getElementById('custCity').value);
            formData.append('receiver_pincode', document.getElementById('custPin').value);
            formData.append('receiver_state', document.getElementById('custState').value);
            formData.append('receiver_mobile', document.getElementById('custMobile').value);

            fetch('consignment-manifest.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                let curCount = parseInt(document.getElementById('topLabelsCount').textContent || '0', 10);
                document.getElementById('topLabelsCount').textContent = curCount + 1;

                if (document.getElementById('ordType').value === 'Cash on Delivery') {
                    let addCod = parseFloat(document.getElementById('codAmount').value || '0');
                    let curCod = parseFloat(document.getElementById('topCodCount').textContent.replace('₹', '').replace(/,/g, '') || '0');
                    document.getElementById('topCodCount').textContent = '₹' + Math.round(curCod + addCod).toLocaleString('en-IN');
                }

                // Trigger print dialog
                setTimeout(() => {
                    window.print();
                    // After print dialog closes, generate a fresh tracking number for next parcel
                    generateRandomTracking();
                }, 100);
            })
            .catch(() => {
                setTimeout(() => {
                    window.print();
                    generateRandomTracking();
                }, 100);
            });
        }

        // Attach input listeners for live updates
        document.addEventListener('DOMContentLoaded', () => {
            loadSavedBusinessDefaults();

            const inputs = document.querySelectorAll('.m-form-input, .m-form-select');
            inputs.forEach(inp => {
                inp.addEventListener('input', updateLiveLabel);
                inp.addEventListener('change', updateLiveLabel);
            });

            const printSizeEl = document.getElementById('printSize');
            if (printSizeEl) {
                adjustPrintSize(printSizeEl.value);
            }
            updateLiveLabel();
        });
    </script>
</body>
</html>

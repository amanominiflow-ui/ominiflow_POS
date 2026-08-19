<?php
/**
 * OminiFlow POS - Shipping Integrations Hub (Zoho POS Exact Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

require_auth();

$pageTitle = 'Shipping Integrations';
$user = current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$db = get_db();

// Handle Shipping Provider Configuration Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(APP_URL . '/integrations-shipping.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_shipping_provider') {
        $providerCode = trim($_POST['provider_code'] ?? '');
        $providerName = trim($_POST['provider_name'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');
        $accountId = trim($_POST['account_id'] ?? '');

        if (!$apiKey) {
            set_flash('error', 'API Key is required to connect ' . htmlspecialchars($providerName) . '.');
        } else {
            $stmt = $db->prepare('
                INSERT INTO shipping_integrations (provider_code, provider_name, api_key, api_secret, account_id, status, updated_at)
                VALUES (:pcode, :pname, :key, :secret, :acc, "connected", NOW())
                ON DUPLICATE KEY UPDATE
                    api_key = VALUES(api_key),
                    api_secret = VALUES(api_secret),
                    account_id = VALUES(account_id),
                    status = "connected",
                    updated_at = NOW()
            ');
            $stmt->execute([
                'pcode' => $providerCode,
                'pname' => $providerName,
                'key' => $apiKey,
                'secret' => $apiSecret ?: null,
                'acc' => $accountId ?: null,
            ]);

            set_flash('success', "{$providerName} connected successfully! Live shipping rate calculations and label generation active.");
        }
        redirect(APP_URL . '/integrations-shipping.php');
    }

    if ($action === 'disconnect_shipping_provider') {
        $providerCode = trim($_POST['provider_code'] ?? '');
        if ($providerCode) {
            $db->prepare('UPDATE shipping_integrations SET status = "disconnected" WHERE provider_code = :pcode')->execute(['pcode' => $providerCode]);
            set_flash('success', 'Shipping carrier disconnected.');
        }
        redirect(APP_URL . '/integrations-shipping.php');
    }
}

// Fetch Connected Shipping Integrations
$connected = [];
try {
    $stmtConn = $db->query('SELECT * FROM shipping_integrations WHERE status = "connected"');
    foreach ($stmtConn->fetchAll() as $row) {
        $connected[$row['provider_code']] = $row;
    }
} catch (Exception $e) {}

// Master Carrier Directory (Exact match with media_1787137120804.png, media_1787137135048.png, media_1787137143488.png)
$shippingCarriers = [
    [
        'code' => 'aftership',
        'name' => 'AfterShip',
        'tag' => 'Integration Built by Ominiflow',
        'learn_more' => true,
        'logo_text' => '📦',
        'logo_bg' => '#ff9900',
        'desc' => "AfterShip connects with OminiFlow POS to automate the tracking process for manual shipments and keeps you as well as your customer apprised on the journey of the shipment.",
        'btn_text' => 'Connect my AfterShip account',
    ],
    [
        'code' => 'delhivery',
        'name' => 'Delhivery',
        'tag' => 'Integration Built by Ominiflow',
        'learn_more' => false,
        'logo_custom' => '<span style="font-weight:900; font-size:16px; letter-spacing:-0.05em; color:#0f172a;">DELHIVERY</span>',
        'desc' => "Integrate with Delhivery - India's leading logistics provider. With a robust transportation network, they ensure seamless and efficient logistics experience for your business.",
        'btn_text' => 'Set Up Now',
    ],
    [
        'code' => 'xpressbees',
        'name' => 'XpressBees',
        'tag' => 'Integration Built by Ominiflow',
        'learn_more' => false,
        'logo_custom' => '<span style="font-weight:900; font-size:14px; color:#ea580c; letter-spacing:-0.03em;">❯❯❯<span style="color:#0f172a;">XPRESSBEES</span></span>',
        'desc' => "Integrate with XpressBees - a trusted shipment provider delivering to over 20,000 pincodes across India.",
        'btn_text' => 'Set Up Now',
    ],
    [
        'code' => 'shiprocket',
        'name' => 'Shiprocket',
        'tag' => 'Integration Built by Ominiflow',
        'learn_more' => false,
        'logo_custom' => '<span style="font-weight:900; font-size:17px; color:#7c3aed;">▷ <span style="color:#1e1b4b;">Shiprocket</span></span>',
        'desc' => "Shiprocket is one of the leading e-commerce logistics and shipment software solutions. They have everything from express shipping, tracking, marketing and checkout fulfilment.",
        'btn_text' => 'Set Up Now',
    ],
    [
        'code' => 'ups',
        'name' => 'UPS',
        'tag' => 'Integration Built by Ominiflow',
        'learn_more' => true,
        'logo_custom' => '<div style="background:#351c15; color:#ffb500; font-weight:900; font-size:15px; padding:6px 12px; border-radius:6px; letter-spacing:1px;">ups</div>',
        'desc' => "Integrate with UPS - one of the largest logistics services that ships to over 200 countries worldwide.",
        'btn_text' => 'Set Up Now',
    ],
    [
        'code' => 'usps',
        'name' => 'USPS',
        'tag' => 'Integration Built by Ominiflow & Powered by Pitney Bowes',
        'sub_hint' => '(Supported only for domestic shipments within the United States)',
        'learn_more' => true,
        'logo_custom' => '<span style="font-weight:900; font-size:15px; color:#004b87; font-style:italic;">🦅 USPS.COM</span>',
        'desc' => "Integrate with USPS - one of the most trusted shipping partner in the United States.",
        'btn_text' => 'Set Up Now',
    ],
    [
        'code' => 'easyship',
        'name' => 'Easyship',
        'tag' => 'Integration Built by Ominiflow',
        'learn_more' => false,
        'logo_custom' => '<span style="font-weight:800; font-size:16px; color:#0f172a;">easyship</span>',
        'desc' => "Integrate with Easyship - an end to end shipping platform with over 550 shipping solutions at discounted rates. You can also link your own rates and have your fulfilment process streamlined.",
        'btn_text' => 'Set Up Now',
    ],
    [
        'code' => 'envia',
        'name' => 'Envia',
        'tag' => 'Integration Built by Ominiflow',
        'learn_more' => false,
        'logo_custom' => '<span style="font-weight:800; font-size:15px; color:#0284c7;">🛒 envia.com <span style="font-size:10px; color:#64748b; font-weight:normal; display:block;">logistics</span></span>',
        'desc' => "Integrate with Envia - a reliable shipping network that offers an exceptional customer service, real-time tracking, and convenient pick-up scheduling, ensuring a seamless shipping experience.",
        'btn_text' => 'Set Up Now',
    ],
    [
        'code' => 'easypost',
        'name' => 'EasyPost',
        'tag' => 'Integration Built by Ominiflow & Powered by EasyPost',
        'learn_more' => false,
        'logo_custom' => '<span style="font-weight:900; font-size:18px; color:#0052cc;">easypost.</span>',
        'desc' => "EasyPost is a shipping solution that helps you connect with 100+ global shipping carriers easily. With EasyPost and OminiFlow POS integration, you can validate an address, create shipping labels, fetch shipping rates, and track the shipments once they're shipped.",
        'btn_text' => 'Connect EasyPost Account',
    ]
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
        .ship-page-container {
            background: #ffffff;
            min-height: calc(100vh - 70px);
            padding: 24px 36px 80px;
        }

        /* Top Search & Header Bar (Exact match with media_1787137120804.png) */
        .ship-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .ship-topbar-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .ship-search-input {
            width: 240px;
            height: 36px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0 12px 0 32px;
            font-size: 13px;
            color: #0f172a;
            outline: none;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%2394a3b8' viewBox='0 0 24 24'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'/%3E%3C/svg%3E") no-repeat 10px center #ffffff;
            transition: all 0.15s ease;
        }

        .ship-search-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
            width: 280px;
        }

        /* Cards List (Exact match with media_1787137120804.png) */
        .ship-cards-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-width: 900px;
        }

        .ship-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px 24px;
            display: flex;
            align-items: flex-start;
            gap: 24px;
            transition: box-shadow 0.15s ease;
        }

        .ship-card:hover {
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
            border-color: #cbd5e1;
        }

        .ship-logo-wrap {
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #f1f5f9;
        }

        .ship-card-body {
            flex: 1;
        }

        .ship-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .ship-carrier-title-wrap {
            display: flex;
            align-items: baseline;
            gap: 10px;
            flex-wrap: wrap;
        }

        .ship-carrier-name {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }

        .ship-tag-italic {
            font-size: 12px;
            font-style: italic;
            color: #64748b;
            font-weight: 500;
        }

        .ship-learn-more {
            font-size: 12.5px;
            color: #64748b;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .ship-learn-more:hover {
            color: #2563eb;
            text-decoration: underline;
        }

        .ship-carrier-desc {
            font-size: 13px;
            color: #334155;
            line-height: 1.55;
            margin-bottom: 16px;
            max-width: 680px;
        }

        .ship-sub-hint {
            font-size: 12px;
            color: #64748b;
            margin-top: -10px;
            margin-bottom: 10px;
        }

        /* Action Buttons */
        .btn-ship-action {
            background: #3b82f6;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 7px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-ship-action:hover {
            background: #2563eb;
        }

        .btn-ship-connected {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
            border-radius: 6px;
            padding: 6px 14px;
            font-size: 12.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Modal Dialog (Exact match with media_1787137153745.png) */
        .modal-ship-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(2px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }

        .modal-ship-overlay.show {
            display: flex;
        }

        .modal-ship-box {
            background: #ffffff;
            border-radius: 8px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            animation: modalFadeIn 0.15s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-ship-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
        }

        .modal-ship-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }

        .modal-ship-close {
            background: transparent;
            border: 0;
            color: #ef4444;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            font-weight: 700;
            padding: 0;
        }

        .modal-ship-body {
            padding: 24px 20px;
        }

        .modal-ship-field {
            display: grid;
            grid-template-columns: 80px 1fr;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .modal-ship-label {
            font-size: 13.5px;
            color: #ef4444;
            font-weight: 600;
        }

        .modal-ship-input {
            width: 100%;
            height: 38px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0 12px;
            font-size: 13.5px;
            color: #0f172a;
            outline: none;
            transition: all 0.15s ease;
        }

        .modal-ship-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .modal-ship-footer {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 20px;
            border-top: 1px solid #f1f5f9;
            background: #ffffff;
        }

        .btn-modal-save {
            background: #3b82f6;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 8px 20px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-modal-save:hover {
            background: #2563eb;
        }

        .btn-modal-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-modal-cancel:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
    </style>
</head>
<body class="app-body">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="ship-page-container">
            <?php if ($flashSuccess): ?>
                <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px 18px; border-radius: 8px; font-size: 13.5px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; max-width: 900px;">
                    <span>✓ <?= e($flashSuccess) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 16px; color: #166534; cursor: pointer;">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 18px; border-radius: 8px; font-size: 13.5px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; max-width: 900px;">
                    <span>⚠ <?= e($flashError) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 16px; color: #991b1b; cursor: pointer;">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Top Header & Search (Exact match with media_1787137120804.png) -->
            <div class="ship-topbar">
                <h1 class="ship-topbar-title">Shipping</h1>
                <div>
                    <input type="text" id="shipSearchInput" class="ship-search-input" placeholder="Search your apps" oninput="filterShippingApps(this.value)">
                </div>
            </div>

            <!-- Shipping Integrations Cards List -->
            <div class="ship-cards-list" id="shipCardsList">
                <?php foreach ($shippingCarriers as $c): ?>
                    <?php $isConn = isset($connected[$c['code']]); ?>
                    <div class="ship-card" data-name="<?= strtolower(e($c['name'])) ?>" data-desc="<?= strtolower(e($c['desc'])) ?>">
                        <!-- Logo Box -->
                        <div class="ship-logo-wrap">
                            <?php if (!empty($c['logo_custom'])): ?>
                                <?= $c['logo_custom'] ?>
                            <?php else: ?>
                                <div style="width: 48px; height: 48px; border-radius: 50%; background: <?= $c['logo_bg'] ?>; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 22px;">
                                    <?= $c['logo_text'] ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Body -->
                        <div class="ship-card-body">
                            <div class="ship-card-header">
                                <div class="ship-carrier-title-wrap">
                                    <span class="ship-carrier-name"><?= e($c['name']) ?></span>
                                    <span class="ship-tag-italic"><?= e($c['tag']) ?></span>
                                </div>
                                <?php if (!empty($c['learn_more'])): ?>
                                    <a href="javascript:void(0)" class="ship-learn-more" onclick="alert('Learn more about <?= e($c['name']) ?> integration with OminiFlow POS.')">
                                        <span>ⓘ</span>
                                        <span>Learn More</span>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($c['sub_hint'])): ?>
                                <div class="ship-sub-hint"><?= e($c['sub_hint']) ?></div>
                            <?php endif; ?>

                            <div class="ship-carrier-desc">
                                <?= e($c['desc']) ?>
                            </div>

                            <!-- Action / Status -->
                            <div>
                                <?php if ($isConn): ?>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <span class="btn-ship-connected">✓ Connected</span>
                                        <button type="button" onclick="openConnectModal('<?= e($c['code']) ?>', '<?= e($c['name']) ?>', '<?= e($connected[$c['code']]['api_key'] ?? '') ?>')" style="background: none; border: none; color: #2563eb; font-size: 13px; font-weight: 600; cursor: pointer;">Configure</button>
                                        <form method="POST" action="<?= asset('integrations-shipping.php') ?>" style="display: inline;" onsubmit="return confirm('Disconnect this shipping integration?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="disconnect_shipping_provider">
                                            <input type="hidden" name="provider_code" value="<?= e($c['code']) ?>">
                                            <button type="submit" style="background: none; border: none; color: #ef4444; font-size: 13px; font-weight: 600; cursor: pointer;">Disconnect</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <button type="button" class="btn-ship-action" onclick="openConnectModal('<?= e($c['code']) ?>', '<?= e($c['name']) ?>')">
                                        <?= e($c['btn_text']) ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>

    <!-- Connect / Setup Modal (Exact match with media_1787137153745.png) -->
    <div class="modal-ship-overlay" id="connectModal">
        <div class="modal-ship-box">
            <div class="modal-ship-header">
                <span class="modal-ship-title" id="modalProviderTitle">Delhivery</span>
                <button type="button" class="modal-ship-close" onclick="closeConnectModal()">&times;</button>
            </div>
            <form method="POST" action="<?= asset('integrations-shipping.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_shipping_provider">
                <input type="hidden" name="provider_code" id="modalProviderCode" value="delhivery">
                <input type="hidden" name="provider_name" id="modalProviderName" value="Delhivery">

                <div class="modal-ship-body">
                    <!-- API Key Field -->
                    <div class="modal-ship-field">
                        <label class="modal-ship-label">API Key*</label>
                        <div>
                            <input type="text" name="api_key" id="modalApiKey" class="modal-ship-input" placeholder="Enter API Key" required>
                        </div>
                    </div>
                </div>

                <div class="modal-ship-footer">
                    <button type="submit" class="btn-modal-save">Save</button>
                    <button type="button" class="btn-modal-cancel" onclick="closeConnectModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openConnectModal(code, name, existingKey) {
            var m = document.getElementById('connectModal');
            document.getElementById('modalProviderTitle').textContent = name;
            document.getElementById('modalProviderCode').value = code;
            document.getElementById('modalProviderName').value = name;
            document.getElementById('modalApiKey').value = existingKey || '';
            if (m) {
                m.classList.add('show');
                setTimeout(function() {
                    document.getElementById('modalApiKey').focus();
                }, 50);
            }
        }

        function closeConnectModal() {
            var m = document.getElementById('connectModal');
            if (m) m.classList.remove('show');
        }

        function filterShippingApps(query) {
            var q = (query || '').toLowerCase().trim();
            document.querySelectorAll('#shipCardsList .ship-card').forEach(function(card) {
                var name = card.getAttribute('data-name') || '';
                var desc = card.getAttribute('data-desc') || '';
                if (name.indexOf(q) !== -1 || desc.indexOf(q) !== -1) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeConnectModal();
        });
    </script>
</body>
</html>

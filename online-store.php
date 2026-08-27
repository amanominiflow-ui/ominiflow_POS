<?php
/**
 * Mobile Store — Overview, Preferences, Custom Domain, Customize App (Zoho POS parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/storefront_db.php';

require_auth();
ensure_online_store_schema();

$user = current_user();
$bid = current_business_id();
$tab = trim((string) ($_GET['tab'] ?? 'overview'));
if (!in_array($tab, ['overview', 'preferences', 'domain', 'customize', 'branding'], true)) {
    $tab = 'overview';
}

$pageTitles = [
    'overview' => 'Mobile Store',
    'preferences' => 'Store Preferences',
    'domain' => 'Custom Domain',
    'customize' => 'Home Layout',
    'branding' => 'Branding',
];
$pageTitle = $pageTitles[$tab];

function os_tab_url(string $tab): string {
    return APP_URL . '/online-store.php' . ($tab === 'overview' ? '' : ('?tab=' . $tab));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please refresh.');
        redirect(os_tab_url($tab));
    }

    $action = (string) ($_POST['action'] ?? '');
    $backTab = (string) ($_POST['tab'] ?? $tab);
    if (!in_array($backTab, ['overview', 'preferences', 'domain', 'customize', 'branding'], true)) {
        $backTab = 'overview';
    }

    if ($action === 'save_slug') {
        $res = save_business_store_slug($bid, (string) ($_POST['store_slug'] ?? ''));
        if (!empty($res['success'])) {
            set_store_published($bid, !empty($_POST['store_published']));
            set_flash('success', 'Store URL saved.');
        } else {
            set_flash('error', $res['error'] ?? 'Could not save store URL.');
        }
        redirect(os_tab_url($backTab));
    }

    if ($action === 'toggle_status') {
        $currentBiz = get_business_store($bid);
        $currentStatus = (int) ($currentBiz['store_published'] ?? 1) === 1;
        set_store_published($bid, !$currentStatus);
        set_flash('success', !$currentStatus ? 'Store is now Open.' : 'Store is now Closed.');
        redirect(os_tab_url('overview'));
    }

    if ($action === 'save_branding') {
        $saveData = [
            'display_name' => $_POST['display_name'] ?? '',
            'header_color' => $_POST['header_color'] ?? '',
            'header_text_color' => $_POST['header_text_color'] ?? '',
            'accent_color' => $_POST['accent_color'] ?? '',
            'button_text_color' => $_POST['button_text_color'] ?? '',
            'show_logo_header' => !empty($_POST['show_logo_header']),
            'show_name_with_logo' => !empty($_POST['show_name_with_logo']),
            'font_size' => $_POST['font_size'] ?? 'medium',
            'remove_logo' => !empty($_POST['remove_logo']),
            'remove_favicon' => !empty($_POST['remove_favicon']),
        ];
        $res = save_mobile_store_settings($bid, $saveData, $_FILES);
        if (empty($res['success'])) {
            set_flash('error', $res['error'] ?? 'Could not save branding.');
        } else {
            set_flash('success', 'Branding saved. Open the website to see it.');
        }
        redirect(os_tab_url('branding'));
    }

    if ($action === 'publish_layout' && !array_key_exists('display_name', $_POST)) {
        publish_mobile_store($bid);
        set_flash('success', 'Website published with your branding.');
        redirect(os_tab_url($backTab));
    }

    if ($action === 'save_preferences' || $action === 'save_customize' || $action === 'publish_layout') {
        $saveData = [
            'display_name' => $_POST['display_name'] ?? '',
            'header_color' => $_POST['header_color'] ?? '',
            'accent_color' => $_POST['accent_color'] ?? '',
            'banner_title' => $_POST['banner_title'] ?? '',
            'banner_subtitle' => $_POST['banner_subtitle'] ?? '',
            'search_placeholder' => $_POST['search_placeholder'] ?? '',
            'show_location' => !empty($_POST['show_location']),
            'show_banner' => !empty($_POST['show_banner']),
            'show_categories' => !empty($_POST['show_categories']),
            'show_items' => !empty($_POST['show_items']),
            'remove_logo' => !empty($_POST['remove_logo']),
            'remove_banner' => !empty($_POST['remove_banner']),
        ];

        if ($action === 'save_preferences') {
            $saveData['hide_out_of_stock'] = !empty($_POST['hide_out_of_stock']);
            $saveData['allow_custom_quantity'] = !empty($_POST['allow_custom_quantity']);
            $saveData['display_stock_count'] = !empty($_POST['display_stock_count']);
            $saveData['display_low_stock_below_10'] = !empty($_POST['display_low_stock_below_10']);
            $saveData['hide_product_price'] = !empty($_POST['hide_product_price']);
            $saveData['show_image_disclaimer'] = !empty($_POST['show_image_disclaimer']);
            $saveData['enable_billing_address'] = !empty($_POST['enable_billing_address']);
            $saveData['enable_delivery'] = !empty($_POST['enable_delivery']);
            $saveData['min_delivery_order_value'] = isset($_POST['min_delivery_order_value']) ? (float)$_POST['min_delivery_order_value'] : 50.00;
            $saveData['enable_pickup'] = !empty($_POST['enable_pickup']);
            $saveData['customer_care_phone'] = $_POST['customer_care_phone'] ?? '';
            $saveData['customer_care_email'] = $_POST['customer_care_email'] ?? '';
        }

        if ($action === 'save_customize' || $action === 'publish_layout') {
            if (isset($_POST['category_section_name'])) $saveData['category_section_name'] = $_POST['category_section_name'];
            if (isset($_POST['category_bg_color'])) $saveData['category_bg_color'] = $_POST['category_bg_color'];
            if (isset($_POST['category_text_color'])) $saveData['category_text_color'] = $_POST['category_text_color'];
            if (isset($_POST['category_shape'])) $saveData['category_shape'] = $_POST['category_shape'];
            if (isset($_POST['category_columns'])) $saveData['category_columns'] = (int)$_POST['category_columns'];
            if (isset($_POST['category_rows'])) $saveData['category_rows'] = (int)$_POST['category_rows'];
            if (isset($_POST['banner_section_name'])) $saveData['banner_section_name'] = $_POST['banner_section_name'];
            $saveData['show_banner_section_name'] = !empty($_POST['show_banner_section_name']);
            if (isset($_POST['item_section_name'])) $saveData['item_section_name'] = $_POST['item_section_name'];
            if (isset($_POST['section_order'])) $saveData['section_order'] = $_POST['section_order'];
            if (isset($_POST['category_mode'])) {
                $saveData['category_mode'] = $_POST['category_mode'];
                $saveData['selected_category_ids'] = $_POST['selected_category_ids'] ?? [];
            }
        }

        $res = save_mobile_store_settings($bid, $saveData, $_FILES);
        if (empty($res['success'])) {
            set_flash('error', $res['error'] ?? 'Could not save.');
            redirect(os_tab_url($backTab));
        }
        if ($action === 'publish_layout') {
            publish_mobile_store($bid);
            set_flash('success', 'Website published with your branding.');
        } else {
            if ($action === 'save_preferences') {
                set_store_published($bid, !empty($_POST['store_published']));
            }
            set_flash('success', 'Store customization saved. Open the website to see it.');
        }
        redirect(os_tab_url($backTab));
    }

    if ($action === 'add_domain') {
        $res = add_custom_domain($bid, (string) ($_POST['domain'] ?? ''));
        set_flash(!empty($res['success']) ? 'success' : 'error', !empty($res['success']) ? 'Domain added. Complete DNS then click Verify.' : ($res['error'] ?? 'Could not add domain.'));
        redirect(os_tab_url('domain'));
    }
    if ($action === 'verify_domain' || $action === 'activate_local') {
        $res = verify_custom_domain($bid, (int) ($_POST['domain_id'] ?? 0), $action === 'activate_local');
        set_flash(!empty($res['success']) ? 'success' : 'error', $res['message'] ?? $res['error'] ?? 'Verification failed.');
        redirect(os_tab_url('domain'));
    }
    if ($action === 'disable_domain') {
        set_custom_domain_status($bid, (int) ($_POST['domain_id'] ?? 0), 'disabled');
        set_flash('success', 'Custom domain disabled.');
        redirect(os_tab_url('domain'));
    }
    if ($action === 'enable_domain') {
        set_custom_domain_status($bid, (int) ($_POST['domain_id'] ?? 0), 'pending');
        set_flash('success', 'Domain re-enabled. Verify DNS again.');
        redirect(os_tab_url('domain'));
    }
    if ($action === 'delete_domain') {
        delete_custom_domain($bid, (int) ($_POST['domain_id'] ?? 0));
        set_flash('success', 'Custom domain removed.');
        redirect(os_tab_url('domain'));
    }

    redirect(os_tab_url($backTab));
}

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$business = get_business_store($bid);
$brand = get_mobile_store_settings($bid);
$domains = get_store_custom_domains($bid);
$localUrl = $business ? public_store_local_url($business) : app_absolute_url('store.php');
$slug = (string) ($business['store_slug'] ?? '');
$published = (int) ($business['store_published'] ?? 1) === 1;
$cnameTarget = (string) STORE_CNAME_TARGET;
$isDev = defined('APP_ENV') && APP_ENV === 'development';
$verifiedDomain = null;
foreach ($domains as $d) {
    if (($d['status'] ?? '') === 'verified') {
        $verifiedDomain = $d['domain'];
        break;
    }
}
$publicLabel = $verifiedDomain ? ('https://' . $verifiedDomain) : $localUrl;
$qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($localUrl);

// Business location for promotional poster footer
$db = get_db();
$bizProfile = null;
try {
    $p = $db->prepare('SELECT * FROM business_profile WHERE business_id = :bid LIMIT 1');
    $p->execute(['bid' => $bid]);
    $bizProfile = $p->fetch(PDO::FETCH_ASSOC);
    if (!$bizProfile) {
        $p = $db->query('SELECT * FROM business_profile WHERE id = 1 LIMIT 1');
        $bizProfile = $p ? $p->fetch(PDO::FETCH_ASSOC) : null;
    }
} catch (Throwable $e) {
    $bizProfile = null;
}
$storeState = trim((string)($bizProfile['state'] ?? ''));
$storeCity = trim((string)($bizProfile['city'] ?? ''));
$storeCountry = trim((string)($bizProfile['business_location'] ?? ''));
$locationDisplay = $storeState ?: ($storeCity ?: ($storeCountry ?: 'West Bengal'));
$storePosterName = strtoupper((string)(!empty($brand['display_name']) ? $brand['display_name'] : ($business['name'] ?? 'ASH COLLECTIVE')));

if (in_array($tab, ['customize', 'branding'], true)) {
    $builderCategories = get_categories('', 'active', $bid);
    $builderProducts = get_products('', null, 'active', '', $bid);
    require __DIR__ . '/includes/store_studio.php';
    exit;
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
        .os-page { padding: 20px 28px 80px; background: #f8fafc; min-height: calc(100vh - 60px); }
        .os-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px 22px; }
        .os-help { color: #64748b; font-size: 13px; line-height: 1.5; }
        .os-label { display: block; font-size: 13px; font-weight: 700; margin: 12px 0 6px; }
        .os-input { width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; font: inherit; }
        .os-url { display: flex; gap: 8px; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; }
        .os-url input { flex: 1; border: 0; background: transparent; font: inherit; font-weight: 600; color: #1d4ed8; }
        .os-badge { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; }
        .os-open { background: #dcfce7; color: #166534; }
        .os-closed { background: #fee2e2; color: #991b1b; }
        .os-grid { display: grid; grid-template-columns: 1.05fr 360px; gap: 22px; align-items: start; }
        .os-phone {
            width: 320px; height: 640px; border: 10px solid #0f172a; border-radius: 36px;
            overflow: hidden; background: #fff; box-shadow: 0 20px 50px rgba(15,23,42,.18); margin: 0 auto;
        }
        .os-phone iframe { width: 100%; height: 100%; border: 0; }
        .os-dns { background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: 14px 16px; font-family: ui-monospace, Menlo, monospace; font-size: 12.5px; line-height: 1.7; }
        .os-check { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; margin: 8px 0; }
        .os-logo-preview { width: 64px; height: 64px; border-radius: 12px; object-fit: cover; background: #0f4c3a; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; }
        .os-domain-row { border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 10px; }
        @media (max-width: 1100px) { .os-grid { grid-template-columns: 1fr; } }

        /* Mobile Store Preferences Styling */
        .pref-container {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 32px 36px;
            max-width: 900px;
        }
        .pref-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 24px;
        }
        .pref-section {
            margin-bottom: 28px;
        }
        .pref-sec-heading {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 14px;
        }
        .pref-check-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            cursor: pointer;
            user-select: none;
            font-size: 13.5px;
            color: #334155;
            font-weight: 500;
        }
        .pref-check-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            border: 1.5px solid #94a3b8;
            accent-color: #2563eb;
            cursor: pointer;
        }
        .pref-sub-row {
            margin-left: 26px;
            margin-top: 8px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13.5px;
            color: #334155;
        }
        .pref-curr-box {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 6px 0 0 6px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }
        .pref-min-input {
            border: 1px solid #cbd5e1;
            border-left: 0;
            border-radius: 0 6px 6px 0;
            padding: 6px 10px;
            font-size: 13px;
            width: 75px;
            outline: none;
        }
        .pref-help-sub {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 16px;
            line-height: 1.4;
        }
        .pref-form-grid {
            display: grid;
            grid-template-columns: 220px 1fr;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
            max-width: 680px;
        }
        .pref-field-label {
            font-size: 13.5px;
            color: #334155;
            font-weight: 500;
        }
        .pref-field-input {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 9px 12px;
            font: inherit;
            font-size: 13px;
            color: #0f172a;
            width: 100%;
            outline: none;
        }
        .pref-field-input::placeholder {
            color: #94a3b8;
        }
        .pref-save-btn {
            background: #2563eb;
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            padding: 9px 24px;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .pref-save-btn:hover {
            background: #1d4ed8;
        }

        /* Custom Domain Landing & Stepper Styles */
        .cd-landing-wrap {
            min-height: 480px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }
        .cd-landing-card {
            text-align: center;
            max-width: 580px;
            margin: 0 auto;
        }
        .cd-art-box {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
        }
        .cd-landing-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
        }
        .cd-landing-text {
            font-size: 13.5px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 26px;
        }
        .cd-add-btn {
            background: #3b82f6;
            color: #ffffff;
            border: 0;
            border-radius: 6px;
            padding: 11px 24px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.3px;
            cursor: pointer;
            transition: background 0.15s, transform 0.1s;
        }
        .cd-add-btn:hover {
            background: #2563eb;
        }

        /* Stepper Wizard */
        .cd-stepper-wrap {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 36px 40px;
            max-width: 800px;
        }
        .cd-step-item {
            display: flex;
            gap: 16px;
            position: relative;
        }
        .cd-step-left {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 32px;
            flex-shrink: 0;
        }
        .cd-step-num {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid #cbd5e1;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            background: #ffffff;
            z-index: 2;
            transition: all 0.2s;
        }
        .cd-num-active {
            border-color: #3b82f6;
            color: #3b82f6;
        }
        .cd-step-item.cd-step-done .cd-step-num {
            border-color: #3b82f6;
            background: #3b82f6;
            color: #ffffff;
        }
        .cd-step-line {
            width: 2px;
            flex: 1;
            background: #e2e8f0;
            margin: 6px 0;
            min-height: 40px;
        }
        .cd-step-item:last-child .cd-step-line {
            display: none;
        }
        .cd-step-content {
            flex: 1;
            padding-bottom: 28px;
        }
        .cd-step-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 28px;
            margin-bottom: 12px;
        }
        .cd-step-title {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
        }
        .cd-step-item.cd-step-active .cd-step-title {
            color: #2563eb;
            font-weight: 700;
        }
        .cd-step-desc {
            font-size: 13.5px;
            color: #475569;
            margin-bottom: 14px;
        }
        .cd-input-row {
            display: flex;
            align-items: center;
            max-width: 480px;
            margin-bottom: 14px;
        }
        .cd-prefix-box {
            background: #f1f5f9;
            border: 1.5px solid #cbd5e1;
            border-right: 0;
            border-radius: 6px 0 0 6px;
            padding: 9px 12px;
            font-size: 13.5px;
            font-weight: 600;
            color: #64748b;
        }
        .cd-text-input {
            border: 1.5px solid #cbd5e1;
            border-radius: 0 6px 6px 0;
            padding: 9px 14px;
            font-size: 13.5px;
            font-weight: 500;
            color: #0f172a;
            flex: 1;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .cd-text-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .cd-save-btn {
            background: #3b82f6;
            color: #ffffff;
            border: 0;
            border-radius: 6px;
            padding: 9px 22px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        .cd-save-btn:hover {
            background: #2563eb;
        }
        .cd-cancel-btn {
            background: transparent;
            color: #ef4444;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .cd-cancel-btn:hover {
            background: #fef2f2;
            border-color: #f87171;
        }
        .cd-edit-link {
            background: transparent;
            border: 0;
            color: #3b82f6;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
        }
        .cd-edit-link:hover {
            text-decoration: underline;
        }
        .cd-dns-table {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 16px;
            max-width: 600px;
        }
        .cd-dns-row {
            display: grid;
            grid-template-columns: 90px 140px 1fr;
            padding: 10px 14px;
            align-items: center;
            font-size: 13px;
            border-bottom: 1px solid #f1f5f9;
        }
        .cd-dns-row:last-child {
            border-bottom: 0;
        }
        .cd-dns-header {
            background: #f8fafc;
            font-weight: 700;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .cd-type-badge {
            background: #e0f2fe;
            color: #0369a1;
            padding: 3px 7px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 11px;
        }
        .cd-dns-val code {
            font-family: ui-monospace, monospace;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
            color: #0f172a;
        }
        .cd-dns-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 14px;
        }
        .cd-ssl-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 16px 20px;
            max-width: 600px;
        }
        .cd-ssl-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            color: #15803d;
            font-size: 14.5px;
        }

        /* Zoho Visual Builder / Home Layout Studio */
        .vb-workspace {
            display: flex;
            flex-direction: column;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            min-height: calc(100vh - 120px);
        }
        .vb-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 24px;
            border-bottom: 1px solid #e2e8f0;
            background: #ffffff;
        }
        .vb-title {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .vb-subtitle {
            font-size: 11.5px;
            color: #94a3b8;
            margin-top: 2px;
        }
        .vb-top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .vb-view-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #475569;
            font-weight: 600;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 6px;
            transition: background 0.15s;
        }
        .vb-view-link:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .vb-publish-btn {
            background: #2563eb;
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            padding: 7px 16px;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
        }
        .vb-publish-btn:hover {
            background: #1d4ed8;
        }

        /* Canvas & Drawer Body Grid */
        .vb-body-grid {
            display: grid;
            grid-template-columns: 1fr 380px;
            flex: 1;
            min-height: 650px;
            background-color: #f8fafc;
            background-image: radial-gradient(#cbd5e1 1.2px, transparent 1.2px);
            background-size: 20px 20px;
            position: relative;
        }
        .vb-canvas-area {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 36px 20px;
            overflow-y: auto;
        }
        .vb-phone-frame {
            width: 340px;
            position: relative;
            margin: 0 80px 0 0;
        }
        .vb-phone-inner {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 28px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.08);
            overflow: hidden;
            position: relative;
        }
        .vb-phone-notch {
            height: 18px;
            background: #0f4c3a;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .vb-phone-notch-bar {
            width: 50px;
            height: 4px;
            background: rgba(255,255,255,0.3);
            border-radius: 2px;
        }
        .vb-phone-header {
            background: #0f4c3a;
            padding: 8px 14px 12px;
            color: #ffffff;
        }
        .vb-phone-search {
            background: #ffffff;
            border-radius: 6px;
            padding: 6px 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: #64748b;
        }

        /* Section Blocks inside Canvas */
        .vb-section-block {
            position: relative;
            padding: 10px 12px;
            border: 2px solid transparent;
            transition: border-color 0.15s;
            cursor: pointer;
        }
        .vb-section-block:hover,
        .vb-section-block.vb-selected {
            border-color: #2563eb;
        }
        .vb-section-pill {
            position: absolute;
            top: -2px;
            left: -2px;
            background: #2563eb;
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 0 0 6px 0;
            text-transform: capitalize;
            display: none;
            z-index: 10;
        }
        .vb-section-block:hover .vb-section-pill,
        .vb-section-block.vb-selected .vb-section-pill {
            display: block;
        }
        .vb-plus-btn {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 20px;
            background: #2563eb;
            color: #ffffff;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            z-index: 12;
            box-shadow: 0 2px 6px rgba(37,99,235,0.4);
        }
        .vb-plus-top { top: -10px; }
        .vb-plus-bot { bottom: -10px; }
        .vb-section-block.vb-selected .vb-plus-btn {
            display: flex;
        }

        /* Floating Context Menu */
        .vb-floating-menu {
            position: absolute;
            top: 10px;
            right: -155px;
            background: #1e293b;
            border-radius: 8px;
            padding: 6px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            z-index: 50;
            display: none;
            flex-direction: column;
            gap: 2px;
            width: 140px;
        }
        .vb-section-block.vb-selected .vb-floating-menu {
            display: flex;
        }
        .vb-menu-btn {
            background: transparent;
            border: 0;
            color: #cbd5e1;
            font-size: 12px;
            font-weight: 500;
            padding: 6px 10px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            text-align: left;
            transition: all 0.15s;
            width: 100%;
        }
        .vb-menu-btn:hover {
            background: #334155;
            color: #ffffff;
        }
        .vb-menu-btn.vb-btn-delete {
            color: #f87171;
        }
        .vb-menu-btn.vb-btn-delete:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        /* Right Drawer */
        .vb-drawer {
            background: #ffffff;
            border-left: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .vb-drawer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
        }
        .vb-drawer-title {
            font-size: 14.5px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .vb-drawer-close {
            background: transparent;
            border: 0;
            font-size: 16px;
            color: #64748b;
            cursor: pointer;
        }
        .vb-drawer-body {
            padding: 20px;
            flex: 1;
            overflow-y: auto;
        }
        .vb-drawer-footer {
            padding: 14px 20px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            gap: 10px;
            background: #ffffff;
        }
        .vb-shape-grid,
        .vb-size-grid {
            display: flex;
            gap: 10px;
            margin-top: 6px;
            margin-bottom: 16px;
        }
        .vb-shape-box,
        .vb-size-box {
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
            position: relative;
            background: #ffffff;
            transition: all 0.15s;
        }
        .vb-shape-box.selected,
        .vb-size-box.selected {
            border-color: #2563eb;
            background: #eff6ff;
        }
        .vb-check-icon {
            position: absolute;
            top: -6px;
            right: -6px;
            width: 16px;
            height: 16px;
            background: #2563eb;
            color: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
        }

        /* Store Details Overview Card */
        .os-store-details-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px 28px;
            position: relative;
            overflow: hidden;
            min-height: 250px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .os-card-menu-wrap {
            position: relative;
        }
        .os-card-menu-btn {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #64748b;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s;
        }
        .os-card-menu-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #cbd5e1;
        }
        .os-card-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.12);
            min-width: 180px;
            padding: 6px;
            z-index: 50;
        }
        .os-card-dropdown.show {
            display: block;
        }
        .os-card-menu-item {
            display: block;
            width: 100%;
            padding: 8px 12px;
            font-size: 13.5px;
            color: #334155;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.15s;
            box-sizing: border-box;
            font-weight: 500;
        }
        .os-card-menu-item:hover {
            background: #f8fafc;
            color: #0f172a;
        }
        .os-details-table {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .os-detail-row {
            display: flex;
            align-items: center;
            font-size: 14px;
        }
        .os-detail-label {
            width: 140px;
            color: #64748b;
            font-weight: 500;
            flex-shrink: 0;
        }
        .os-detail-value {
            color: #0f172a;
            font-size: 14px;
        }
        .os-detail-bold {
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .os-icon-btn {
            background: transparent;
            border: 0;
            color: #64748b;
            cursor: pointer;
            padding: 2px 4px;
            display: inline-flex;
            align-items: center;
            border-radius: 4px;
            transition: color 0.15s;
        }
        .os-icon-btn:hover {
            color: #0f172a;
        }
        .os-status-pill {
            display: inline-flex;
            align-items: center;
            padding: 2.5px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.3;
        }
        .os-pill-open {
            background: #16a34a;
            color: #ffffff;
        }
        .os-pill-closed {
            background: #dc2626;
            color: #ffffff;
        }
        .os-qr-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #2563eb;
            font-weight: 600;
            font-size: 13.5px;
            text-decoration: none;
            cursor: pointer;
            transition: opacity 0.15s;
        }
        .os-qr-link:hover {
            opacity: 0.85;
        }
        .os-globe-watermark {
            position: absolute;
            right: -20px;
            bottom: -30px;
            width: 175px;
            height: 175px;
            pointer-events: none;
            z-index: 1;
        }

        /* QR Poster Modal Styling */
        .qr-modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 99999;
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
            animation: qrFadeIn 0.2s ease-out;
        }
        @keyframes qrFadeIn { from { opacity: 0; } to { opacity: 1; } }

        .qr-modal-container {
            background: #ffffff;
            border-radius: 14px;
            width: 100%;
            max-width: 840px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            overflow: hidden;
            animation: qrSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes qrSlideUp { from { transform: translateY(16px) scale(0.98); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }

        .qr-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 24px 14px;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
        }
        .qr-modal-title {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            line-height: 1.3;
        }
        .qr-modal-subtitle {
            font-size: 13px;
            color: #64748b;
            margin: 3px 0 0;
            line-height: 1.4;
        }
        .qr-modal-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .qr-split-btn {
            display: inline-flex;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .qr-download-btn {
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
            padding: 8px 14px !important;
            font-size: 13.5px !important;
            font-weight: 600 !important;
            cursor: pointer;
        }
        .qr-dropdown-toggle {
            border-top-left-radius: 0 !important;
            border-bottom-left-radius: 0 !important;
            border-left: 1px solid rgba(255,255,255,0.3) !important;
            padding: 8px 9px !important;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .qr-download-dropdown-wrap {
            position: relative;
        }
        .qr-dropdown-menu {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 12px 28px rgba(0,0,0,0.15);
            min-width: 220px;
            padding: 6px;
            z-index: 100;
        }
        .qr-dropdown-menu.show {
            display: block;
        }
        .qr-dropdown-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 8px 12px;
            background: transparent;
            border: 0;
            border-radius: 6px;
            font-size: 13px;
            color: #1e293b;
            text-align: left;
            cursor: pointer;
            transition: background 0.15s;
        }
        .qr-dropdown-item:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .qr-modal-close {
            background: transparent;
            border: 0;
            color: #64748b;
            font-size: 20px;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s;
        }
        .qr-modal-close:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .qr-modal-body {
            padding: 20px 24px 24px;
            background: #f8fafc;
            display: flex;
            justify-content: center;
            overflow-x: auto;
        }

        /* Poster Card */
        .qr-poster-card {
            width: 780px;
            max-width: 100%;
            height: 450px;
            background: #083d30;
            border-radius: 12px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(8, 61, 48, 0.35);
            padding: 30px 36px 18px;
            box-sizing: border-box;
            user-select: none;
            flex-shrink: 0;
        }
        .qr-poster-brand-name {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 21px;
            font-weight: 900;
            letter-spacing: 0.6px;
            color: #ffffff;
            text-transform: uppercase;
            margin: 0;
            position: relative;
            z-index: 2;
            line-height: 1.2;
        }
        .qr-poster-headline-wrap {
            position: relative;
            z-index: 2;
            margin-top: 50px;
            max-width: 320px;
        }
        .qr-poster-headline {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 32px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.15;
            margin: 0;
            letter-spacing: -0.2px;
        }
        .qr-poster-subheadline {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.2;
            margin-top: 6px;
        }
        .qr-poster-svg-art {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
        }

        /* Smartphone Mockup on Poster */
        .qr-poster-phone {
            position: absolute;
            top: 24px;
            right: 32px;
            width: 215px;
            background: #ffffff;
            border-radius: 26px;
            border: 3.5px solid #111827;
            box-shadow: 0 16px 36px rgba(0,0,0,0.35);
            padding: 12px 12px 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 3;
            box-sizing: border-box;
        }
        .qr-poster-phone-notch {
            width: 36px;
            height: 4px;
            background: #111827;
            border-radius: 999px;
            margin-bottom: 10px;
        }
        .qr-poster-code-box {
            width: 172px;
            height: 172px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border-radius: 6px;
            overflow: hidden;
        }
        .qr-poster-code-box canvas,
        .qr-poster-code-box img {
            width: 100% !important;
            height: 100% !important;
            display: block;
        }
        .qr-poster-phone-text {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 13px;
            font-weight: 800;
            color: #111827;
            text-align: center;
            line-height: 1.25;
            margin-top: 10px;
        }

        /* Poster Footer */
        .qr-poster-footer {
            position: absolute;
            left: 36px;
            right: 36px;
            bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 2;
            color: rgba(255, 255, 255, 0.85);
            font-size: 11.5px;
            font-weight: 600;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .qr-poster-location {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .qr-poster-branding {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .qr-pos-badge {
            border: 1.2px solid rgba(255,255,255,0.7);
            padding: 0 3px;
            border-radius: 2px;
            font-size: 9.5px;
            font-weight: 800;
            line-height: 1.3;
        }

        @media print {
            body * { visibility: hidden !important; }
            #qrPosterModal, #posterCardToCapture, #posterCardToCapture * { visibility: visible !important; }
            #qrPosterModal {
                position: fixed !important; left: 0 !important; top: 0 !important;
                width: 100vw !important; height: 100vh !important;
                background: #ffffff !important; display: flex !important;
                align-items: center !important; justify-content: center !important;
                padding: 0 !important; margin: 0 !important; z-index: 999999 !important;
            }
            .qr-modal-container { box-shadow: none !important; border: 0 !important; width: 100% !important; max-width: 100% !important; padding: 0 !important; background: transparent !important; }
            .qr-modal-header { display: none !important; }
            .qr-modal-body { padding: 0 !important; background: transparent !important; }
            #posterCardToCapture {
                width: 800px !important; height: 460px !important; margin: 0 auto !important;
                box-shadow: none !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>
<div class="app-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <div class="app-main">
        <?php require_once __DIR__ . '/includes/header.php'; ?>
        <main class="os-page">
            <?php if ($flashSuccess): ?><div class="saas-alert saas-alert-success" style="margin-bottom:14px"><span><?= e($flashSuccess) ?></span></div><?php endif; ?>
            <?php if ($flashError): ?><div class="saas-alert saas-alert-danger" style="margin-bottom:14px"><span><?= e($flashError) ?></span></div><?php endif; ?>

            <?php if ($tab === 'overview'): ?>
                <div class="page-header-row" style="margin-bottom:18px">
                    <div>
                        <h1 class="page-title">Mobile Store</h1>
                        <p class="page-subtitle">Your mobile store can be accessed with the below URL. It will be exclusive to your business. Your customers can access the URL to browse the products on sale and place orders.</p>
                    </div>
                    <a class="btn-primary" href="<?= e($localUrl) ?>" target="_blank" rel="noopener">View Store</a>
                </div>
                <div class="os-grid">
                    <div class="os-store-details-card">
                        <!-- Top Row: Card Title + Kebab Menu -->
                        <div style="display:flex;justify-content:space-between;align-items:center;position:relative;z-index:3;">
                            <h3 style="font-size:16px;font-weight:700;color:#1e293b;margin:0">Store Details</h3>
                            <div class="os-card-menu-wrap">
                                <button type="button" class="os-card-menu-btn" onclick="toggleCardMenu(event)" aria-label="Store actions">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
                                </button>
                                <div id="osCardDropdown" class="os-card-dropdown">
                                    <a href="<?= e(os_tab_url('preferences')) ?>" class="os-card-menu-item">Edit Store Details</a>
                                    <a href="<?= e(os_tab_url('domain')) ?>" class="os-card-menu-item">Customize Domain</a>
                                    <form method="post" style="margin:0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="tab" value="overview">
                                        <button type="submit" class="os-card-menu-item" style="width:100%;text-align:left;background:none;border:none;cursor:pointer;font:inherit">
                                            <?= $published ? 'Close Store' : 'Open Store' ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Info Grid -->
                        <div class="os-details-table" style="margin:22px 0 26px;position:relative;z-index:2;">
                            <div class="os-detail-row">
                                <span class="os-detail-label">Display Name:</span>
                                <span class="os-detail-value os-detail-bold"><?= e($brand['display_name']) ?></span>
                            </div>
                            <div class="os-detail-row">
                                <span class="os-detail-label">Store URL:</span>
                                <span class="os-detail-value" style="display:inline-flex;align-items:center;gap:8px;">
                                    <a href="<?= e($publicLabel) ?>" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:none;font-weight:500"><?= e($publicLabel) ?></a>
                                    <button type="button" class="os-icon-btn" onclick="navigator.clipboard.writeText('<?= e($publicLabel) ?>');this.innerHTML='<svg width=\'15\' height=\'15\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'#16a34a\' stroke-width=\'2.5\'><polyline points=\'20 6 9 17 4 12\'/></svg>';setTimeout(()=>{this.innerHTML='<svg width=\'15\' height=\'15\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\'><rect x=\'9\' y=\'9\' width=\'13\' height=\'13\' rx=\'2\' ry=\'2\'/><path d=\'M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1\'/></svg>'}, 1500);" title="Copy URL">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    </button>
                                </span>
                            </div>
                            <div class="os-detail-row">
                                <span class="os-detail-label">Status:</span>
                                <span class="os-detail-value">
                                    <span class="os-status-pill <?= $published ? 'os-pill-open' : 'os-pill-closed' ?>"><?= $published ? 'Open' : 'Closed' ?></span>
                                </span>
                            </div>
                        </div>

                        <!-- Bottom Link -->
                        <div style="position:relative;z-index:2;">
                            <a href="#get-qr" onclick="openQrPosterModal(event)" class="os-qr-link">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M7 17h.01"/><path d="M17 17h.01"/><path d="M7 7h.01"/><path d="M17 7h.01"/></svg>
                                <span>Get QR Code</span>
                            </a>
                        </div>

                        <!-- Globe Watermark in Bottom Right -->
                        <div class="os-globe-watermark">
                            <svg viewBox="0 0 200 200" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="130" cy="130" r="75" stroke="#3b82f6" stroke-width="2.5" opacity="0.12"/>
                                <ellipse cx="130" cy="130" rx="42" ry="75" stroke="#3b82f6" stroke-width="2" opacity="0.12"/>
                                <ellipse cx="130" cy="130" rx="16" ry="75" stroke="#3b82f6" stroke-width="1.8" opacity="0.12"/>
                                <line x1="55" y1="130" x2="205" y2="130" stroke="#3b82f6" stroke-width="2" opacity="0.12"/>
                                <line x1="70" y1="95" x2="190" y2="95" stroke="#3b82f6" stroke-width="1.8" opacity="0.12"/>
                                <line x1="70" y1="165" x2="190" y2="165" stroke="#3b82f6" stroke-width="1.8" opacity="0.12"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <div class="os-phone"><iframe src="<?= e($localUrl) ?>" title="Store preview"></iframe></div>
                        <a class="btn-primary" href="<?= e(os_tab_url('customize')) ?>" style="display:block;text-align:center;margin:16px auto 0;max-width:320px">Customize App</a>
                    </div>
                </div>

            <?php elseif ($tab === 'preferences'): ?>
                <div class="pref-container">
                    <h1 class="pref-title">Mobile Store Preferences</h1>

                    <form method="post" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_preferences">
                        <input type="hidden" name="tab" value="preferences">
                        <input type="hidden" name="display_name" value="<?= e($brand['display_name']) ?>">
                        <input type="hidden" name="header_color" value="<?= e($brand['header_color']) ?>">
                        <input type="hidden" name="accent_color" value="<?= e($brand['accent_color']) ?>">
                        <input type="hidden" name="show_banner" value="<?= $brand['show_banner'] ? '1' : '' ?>">
                        <input type="hidden" name="show_categories" value="<?= $brand['show_categories'] ? '1' : '' ?>">
                        <input type="hidden" name="show_items" value="<?= $brand['show_items'] ? '1' : '' ?>">
                        <input type="hidden" name="show_location" value="<?= $brand['show_location'] ? '1' : '' ?>">
                        <input type="hidden" name="banner_title" value="<?= e($brand['banner_title']) ?>">
                        <input type="hidden" name="banner_subtitle" value="<?= e($brand['banner_subtitle']) ?>">
                        <input type="hidden" name="search_placeholder" value="<?= e($brand['search_placeholder']) ?>">

                        <!-- Items Section -->
                        <div class="pref-section">
                            <div class="pref-sec-heading">Items</div>
                            <label class="pref-check-item">
                                <input type="checkbox" name="hide_out_of_stock" value="1" <?= !empty($brand['hide_out_of_stock']) ? 'checked' : '' ?>>
                                <span>Hide out of stock items</span>
                            </label>
                            <label class="pref-check-item">
                                <input type="checkbox" name="allow_custom_quantity" value="1" <?= !empty($brand['allow_custom_quantity']) ? 'checked' : '' ?>>
                                <span>Allow customers to enter the item quantity</span>
                            </label>
                            <label class="pref-check-item">
                                <input type="checkbox" name="display_stock_count" value="1" <?= !empty($brand['display_stock_count']) ? 'checked' : '' ?>>
                                <span>Display available stock count</span>
                            </label>
                            <label class="pref-check-item">
                                <input type="checkbox" name="display_low_stock_below_10" value="1" <?= !empty($brand['display_low_stock_below_10']) ? 'checked' : '' ?>>
                                <span>Display stock count when the available quantity falls below 10</span>
                            </label>
                            <label class="pref-check-item">
                                <input type="checkbox" name="hide_product_price" value="1" <?= !empty($brand['hide_product_price']) ? 'checked' : '' ?>>
                                <span>Hide product price details</span>
                            </label>
                            <label class="pref-check-item">
                                <input type="checkbox" name="show_image_disclaimer" value="1" <?= !empty($brand['show_image_disclaimer']) ? 'checked' : '' ?>>
                                <span>Show product image disclaimer content</span>
                            </label>
                        </div>

                        <!-- Orders Section -->
                        <div class="pref-section">
                            <div class="pref-sec-heading">Orders</div>
                            <label class="pref-check-item">
                                <input type="checkbox" name="enable_billing_address" value="1" <?= !empty($brand['enable_billing_address']) ? 'checked' : '' ?>>
                                <span>Enable billing address for orders</span>
                            </label>
                        </div>

                        <!-- Fulfilment Section -->
                        <div class="pref-section">
                            <div class="pref-sec-heading">Fulfilment</div>
                            <label class="pref-check-item">
                                <input type="checkbox" name="enable_delivery" value="1" <?= !empty($brand['enable_delivery']) ? 'checked' : '' ?>>
                                <span>Enable delivery</span>
                            </label>
                            <div class="pref-sub-row">
                                <span>Minimum order value for delivery</span>
                                <div style="display:inline-flex;align-items:center;">
                                    <span class="pref-curr-box"><?= e($currencySymbol ?? 'INR') ?></span>
                                    <input class="pref-min-input" type="number" step="1" name="min_delivery_order_value" value="<?= (int)($brand['min_delivery_order_value'] ?? 50) ?>">
                                </div>
                            </div>
                            <label class="pref-check-item">
                                <input type="checkbox" name="enable_pickup" value="1" <?= !empty($brand['enable_pickup']) ? 'checked' : '' ?>>
                                <span>Enable pickup</span>
                            </label>
                        </div>

                        <!-- Customer Care Details Section -->
                        <div class="pref-section">
                            <div class="pref-sec-heading" style="margin-bottom:4px">Customer Care Details</div>
                            <div class="pref-help-sub">This information will be shown on your store's customer care section and in QR code poster</div>

                            <div class="pref-form-grid">
                                <label class="pref-field-label">Customer care contact number</label>
                                <input class="pref-field-input" type="text" name="customer_care_phone" value="<?= e($brand['customer_care_phone'] ?? '') ?>" placeholder="Enter your customer care number">
                            </div>

                            <div class="pref-form-grid">
                                <label class="pref-field-label">Customer Care Email Id</label>
                                <input class="pref-field-input" type="email" name="customer_care_email" value="<?= e($brand['customer_care_email'] ?? '') ?>" placeholder="Enter your customer care email id">
                            </div>
                        </div>

                        <button class="pref-save-btn" type="submit">Save</button>
                    </form>
                </div>

            <?php elseif ($tab === 'domain'):
                $currentDomain = !empty($domains[0]) ? $domains[0] : null;
                $hasDomain = !empty($currentDomain);
                $initialStep = 1;
                if ($hasDomain) {
                    $initialStep = ($currentDomain['status'] === 'verified') ? 3 : 2;
                }
                if (isset($_GET['step'])) {
                    $initialStep = (int) $_GET['step'];
                }
                $showLanding = !$hasDomain && empty($_GET['setup']);
                ?>
                <div class="page-header-row" style="margin-bottom:18px">
                    <div>
                        <h1 class="page-title">Custom Domain</h1>
                    </div>
                </div>

                <!-- State 1: Landing Empty State (when no custom domain is added yet) -->
                <div class="cd-landing-wrap" id="cdLandingView" style="<?= $showLanding ? '' : 'display:none;' ?>">
                    <div class="cd-landing-card">
                        <div class="cd-art-box">
                            <svg width="220" height="130" viewBox="0 0 220 130" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <!-- Sparkles -->
                                <path d="M35 30 L38 23 L45 20 L38 17 L35 10 L32 17 L25 20 L32 23 Z" fill="#fbbf24"/>
                                <path d="M52 42 L54 37 L59 35 L54 33 L52 28 L50 33 L45 35 L50 37 Z" fill="#fbbf24"/>
                                <circle cx="190" cy="30" r="3" fill="#fbbf24"/>
                                <circle cx="205" cy="45" r="3.5" fill="#fbbf24"/>

                                <!-- Browser mockup -->
                                <rect x="38" y="24" width="144" height="92" rx="8" fill="#dbeafe" stroke="#93c5fd" stroke-width="1.5"/>
                                <rect x="38" y="24" width="144" height="24" rx="8" fill="#eff6ff"/>
                                <circle cx="48" cy="36" r="2.5" fill="#93c5fd"/>
                                <circle cx="56" cy="36" r="2.5" fill="#93c5fd"/>
                                <circle cx="64" cy="36" r="2.5" fill="#93c5fd"/>

                                <!-- URL address bar in browser -->
                                <rect x="35" y="44" width="150" height="26" rx="4" fill="#86efac" stroke="#4ade80" stroke-width="1"/>
                                <text x="44" y="61" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="11.5" font-weight="700" fill="#065f46">https://ww</text>

                                <!-- Pencil editing -->
                                <g transform="translate(126, 52) rotate(-45)">
                                    <rect x="0" y="0" width="11" height="32" rx="2" fill="#818cf8"/>
                                    <path d="M0 32 L5.5 41 L11 32 Z" fill="#c7d2fe"/>
                                    <path d="M3.5 37 L5.5 41 L7.5 37 Z" fill="#1e1b4b"/>
                                    <rect x="0" y="0" width="11" height="5" fill="#f43f5e"/>
                                    <rect x="0" y="5" width="11" height="3" fill="#cbd5e1"/>
                                </g>
                            </svg>
                        </div>

                        <h2 class="cd-landing-title">Custom Domain</h2>
                        <p class="cd-landing-text">This feature lets customers access your store via a custom domain, such as https://shop.zylker.com, instead of the default URL. For details, see our help document on custom domain mapping.</p>

                        <button type="button" class="cd-add-btn" onclick="showDomainStepper()">ADD CUSTOM DOMAIN</button>
                    </div>
                </div>

                <!-- State 2: 3-Step Stepper Wizard -->
                <div class="cd-stepper-wrap" id="cdStepperView" style="<?= $showLanding ? 'display:none;' : '' ?>">
                    <div class="cd-stepper-container">
                        <!-- Step 1: Add Domain -->
                        <div class="cd-step-item <?= $initialStep === 1 ? 'cd-step-active' : ($initialStep > 1 ? 'cd-step-done' : '') ?>" id="stepItem1">
                            <div class="cd-step-left">
                                <div class="cd-step-num <?= $initialStep >= 1 ? 'cd-num-active' : '' ?>">1</div>
                                <div class="cd-step-line"></div>
                            </div>
                            <div class="cd-step-content">
                                <div class="cd-step-head">
                                    <span class="cd-step-title">Add Domain</span>
                                    <?php if ($hasDomain && $initialStep > 1): ?>
                                        <button type="button" class="cd-edit-link" onclick="setWizardStep(1)">Edit</button>
                                    <?php endif; ?>
                                </div>

                                <div class="cd-step-body" id="stepBody1" style="<?= $initialStep === 1 ? '' : 'display:none;' ?>">
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="add_domain">
                                        <div class="cd-input-row">
                                            <span class="cd-prefix-box">https://</span>
                                            <input class="cd-text-input" type="text" name="domain" value="<?= e($currentDomain['domain'] ?? '') ?>" placeholder="subdomain.domain.com" required autocomplete="off">
                                        </div>
                                        <button type="submit" class="cd-save-btn">Save & Next</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: DNS Verification -->
                        <div class="cd-step-item <?= $initialStep === 2 ? 'cd-step-active' : ($initialStep > 2 ? 'cd-step-done' : '') ?>" id="stepItem2">
                            <div class="cd-step-left">
                                <div class="cd-step-num <?= $initialStep >= 2 ? 'cd-num-active' : '' ?>">2</div>
                                <div class="cd-step-line"></div>
                            </div>
                            <div class="cd-step-content">
                                <div class="cd-step-head">
                                    <span class="cd-step-title">DNS Verification</span>
                                </div>

                                <div class="cd-step-body" id="stepBody2" style="<?= $initialStep === 2 ? '' : 'display:none;' ?>">
                                    <?php if ($currentDomain): ?>
                                        <?php
                                        $dName = (string) $currentDomain['domain'];
                                        $dParts = explode('.', $dName);
                                        $hostVal = count($dParts) > 2 ? $dParts[0] : 'www';
                                        ?>
                                        <p class="cd-step-desc">Add the following <strong>CNAME</strong> record in your domain registrar's DNS settings (GoDaddy, Cloudflare, Namecheap, etc.):</p>
                                        <div class="cd-dns-table">
                                            <div class="cd-dns-row cd-dns-header">
                                                <div>Type</div>
                                                <div>Host / Name</div>
                                                <div>Points to / Value</div>
                                            </div>
                                            <div class="cd-dns-row">
                                                <div><span class="cd-type-badge">CNAME</span></div>
                                                <div class="cd-dns-val"><code><?= e($hostVal) ?></code></div>
                                                <div class="cd-dns-val"><code><?= e($cnameTarget) ?></code></div>
                                            </div>
                                        </div>
                                        <?php if (count($dParts) <= 2): ?>
                                            <p style="font-size:12.5px;color:#64748b;margin:8px 0 14px;">💡 <em>Note: For root domains, use <strong>www</strong> as the Host in your DNS registrar.</em></p>
                                        <?php endif; ?>

                                        <div class="cd-dns-actions">
                                            <form method="post" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="verify_domain">
                                                <input type="hidden" name="domain_id" value="<?= (int) $currentDomain['id'] ?>">
                                                <button type="submit" class="cd-save-btn">Verify DNS</button>
                                            </form>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this domain?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_domain">
                                                <input type="hidden" name="domain_id" value="<?= (int) $currentDomain['id'] ?>">
                                                <button type="submit" class="cd-cancel-btn">Delete</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <p class="cd-step-desc" style="color: #94a3b8; margin: 0;">Save domain in Step 1 to generate DNS configuration.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: SSL Installation -->
                        <div class="cd-step-item <?= $initialStep === 3 ? 'cd-step-active cd-step-done' : '' ?>" id="stepItem3">
                            <div class="cd-step-left">
                                <div class="cd-step-num <?= $initialStep === 3 ? 'cd-num-active' : '' ?>">3</div>
                            </div>
                            <div class="cd-step-content">
                                <div class="cd-step-head">
                                    <span class="cd-step-title">SSL Installation</span>
                                </div>

                                <div class="cd-step-body" id="stepBody3" style="<?= $initialStep === 3 ? '' : 'display:none;' ?>">
                                    <?php if ($currentDomain && $currentDomain['status'] === 'verified'): ?>
                                        <div class="cd-ssl-success">
                                            <div class="cd-ssl-badge">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                                <span>SSL Certificate Active & Verified</span>
                                            </div>
                                            <p style="font-size: 13.5px; color: #475569; margin: 8px 0 16px;">Your custom domain <strong>https://<?= e($currentDomain['domain']) ?></strong> is active and secured.</p>
                                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                                <a href="http://<?= e($currentDomain['domain']) ?>" target="_blank" class="cd-save-btn" style="text-decoration:none;display:inline-block">Open Store</a>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Remove custom domain?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_domain">
                                                    <input type="hidden" name="domain_id" value="<?= (int) $currentDomain['id'] ?>">
                                                    <button type="submit" class="cd-cancel-btn">Remove Domain</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <p class="cd-step-desc" style="color: #94a3b8; margin: 0;">SSL certificate will be provisioned automatically once DNS verification is complete.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    function showDomainStepper() {
                        const landing = document.getElementById('cdLandingView');
                        const stepper = document.getElementById('cdStepperView');
                        if (landing) landing.style.display = 'none';
                        if (stepper) stepper.style.display = 'block';
                        setWizardStep(1);
                    }

                    function setWizardStep(step) {
                        for (let i = 1; i <= 3; i++) {
                            const body = document.getElementById('stepBody' + i);
                            const item = document.getElementById('stepItem' + i);
                            if (body) body.style.display = (i === step) ? 'block' : 'none';
                            if (item) {
                                if (i === step) {
                                    item.classList.add('cd-step-active');
                                } else {
                                    item.classList.remove('cd-step-active');
                                }
                            }
                        }
                    }
                </script>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modal: Download your poster and QR Code -->
<div id="qrPosterModal" class="qr-modal-overlay" style="display:none;" onclick="if(event.target===this)closeQrPosterModal()">
    <div class="qr-modal-container">
        <!-- Modal Header -->
        <div class="qr-modal-header">
            <div>
                <h2 class="qr-modal-title">Download your poster and QR Code</h2>
                <p class="qr-modal-subtitle">Promote your store by printing and sharing this poster and QR code with your customers.</p>
            </div>
            <div class="qr-modal-actions">
                <div class="qr-download-dropdown-wrap">
                    <div class="qr-split-btn">
                        <button type="button" class="btn-primary qr-download-btn" onclick="downloadPoster('png')">Download Poster</button>
                        <button type="button" class="btn-primary qr-dropdown-toggle" onclick="toggleDownloadDropdown(event)" aria-label="More download options">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
                        </button>
                    </div>
                    <div id="qrDownloadMenu" class="qr-dropdown-menu">
                        <button type="button" class="qr-dropdown-item" onclick="downloadPoster('png')">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            Download as PNG (High Quality)
                        </button>
                        <button type="button" class="qr-dropdown-item" onclick="downloadPoster('jpeg')">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            Download as JPG
                        </button>
                        <button type="button" class="qr-dropdown-item" onclick="printPoster()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                            Print Poster
                        </button>
                    </div>
                </div>
                <button type="button" class="qr-modal-close" onclick="closeQrPosterModal()" aria-label="Close modal">✕</button>
            </div>
        </div>

        <!-- Modal Body: Poster Preview -->
        <div class="qr-modal-body">
            <div id="posterCardToCapture" class="qr-poster-card">
                <!-- Top Left: Store Display Name -->
                <div class="qr-poster-brand-name"><?= e($storePosterName) ?></div>

                <!-- Left Headline Text -->
                <div class="qr-poster-headline-wrap">
                    <h1 class="qr-poster-headline">We're now online!</h1>
                    <div class="qr-poster-subheadline">Place your order.</div>
                </div>

                <!-- Center Scene SVG (Lamps + Cozy Armchair + Relaxed Person with Tablet + Curled Cat + Rays) -->
                <svg class="qr-poster-svg-art" viewBox="0 0 780 450" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <linearGradient id="qrLampGlow" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#fffbeb" stop-opacity="0.32"/>
                            <stop offset="100%" stop-color="#fef08a" stop-opacity="0"/>
                        </linearGradient>
                    </defs>

                    <!-- Ceiling Pendant Lamp 1 (Left) -->
                    <line x1="330" y1="0" x2="330" y2="78" stroke="#94a3b8" stroke-width="1.8" />
                    <polygon points="316,78 344,78 350,94 310,94" fill="#ffffff" />
                    <polygon points="312,94 348,94 380,195 280,195" fill="url(#qrLampGlow)" />

                    <!-- Ceiling Pendant Lamp 2 (Right) -->
                    <line x1="395" y1="0" x2="395" y2="52" stroke="#94a3b8" stroke-width="1.8" />
                    <polygon points="384,52 406,52 411,66 379,66" fill="#ffffff" />
                    <polygon points="381,66 409,66 435,155 355,155" fill="url(#qrLampGlow)" />

                    <!-- Top-right corner rays / burst -->
                    <g stroke="#ffffff" stroke-opacity="0.4" stroke-width="2.2" stroke-linecap="round">
                        <line x1="675" y1="36" x2="690" y2="24" />
                        <line x1="690" y1="48" x2="711" y2="44" />
                        <line x1="695" y1="64" x2="717" y2="72" />
                    </g>

                    <!-- Armchair Shadow -->
                    <ellipse cx="380" cy="380" rx="82" ry="12" fill="#031e17" opacity="0.55" />
                    
                    <!-- Armchair Body (Soft Light Grey) -->
                    <path d="M 310 260 C 310 205, 442 205, 442 260 L 442 332 C 442 354, 310 354, 310 332 Z" fill="#e2e8f0" />
                    <!-- Armchair Side Armrests -->
                    <path d="M 302 270 C 296 238, 330 232, 336 265 L 336 338 C 330 354, 302 348, 302 327 Z" fill="#cbd5e1" />
                    <path d="M 416 270 C 410 238, 444 232, 450 265 L 450 338 C 444 354, 416 348, 416 327 Z" fill="#cbd5e1" />
                    <!-- Main Seat Cushion -->
                    <path d="M 320 316 C 320 292, 432 292, 432 316 L 432 348 C 432 358, 320 358, 320 348 Z" fill="#f8fafc" />
                    <!-- Armchair Modern Wood Legs -->
                    <line x1="326" y1="354" x2="316" y2="384" stroke="#1e293b" stroke-width="4.5" stroke-linecap="round" />
                    <line x1="426" y1="354" x2="436" y2="384" stroke="#1e293b" stroke-width="4.5" stroke-linecap="round" />

                    <!-- Person Hair Bun -->
                    <circle cx="366" cy="200" r="15" fill="#1e293b" />
                    <circle cx="358" cy="190" r="9" fill="#1e293b" />
                    <!-- Person Face -->
                    <circle cx="368" cy="210" r="12" fill="#fed7aa" />
                    <!-- Glasses & Ear -->
                    <circle cx="372" cy="209" r="4.8" fill="none" stroke="#1e293b" stroke-width="1.3" />
                    <line x1="367.2" y1="209" x2="364" y2="209" stroke="#1e293b" stroke-width="1.3" />
                    <circle cx="357" cy="211" r="2.8" fill="#fed7aa" />
                    <!-- Yellow Top (#f59e0b) -->
                    <path d="M 352 222 C 342 234, 336 268, 341 298 L 391 298 C 401 272, 396 238, 384 222 Z" fill="#f59e0b" />
                    <!-- Arm holding tablet -->
                    <path d="M 368 238 C 374 254, 391 264, 406 258" fill="none" stroke="#fed7aa" stroke-width="7" stroke-linecap="round" />
                    <path d="M 342 244 C 342 260, 368 276, 394 270" fill="none" stroke="#f59e0b" stroke-width="7" stroke-linecap="round" />
                    <!-- Tablet Screen -->
                    <rect x="396" y="244" width="22" height="30" rx="3.5" fill="#0f172a" stroke="#475569" stroke-width="1" transform="rotate(-15 396 244)" />

                    <!-- Stretched Legs in Navy Pants (#1e293b) -->
                    <path d="M 366 292 C 381 308, 441 322, 486 322 C 511 322, 541 338, 556 354 L 546 366 C 531 354, 496 342, 471 338 C 431 338, 371 318, 356 296 Z" fill="#1e293b" />
                    <!-- Yellow Sneakers with white sole -->
                    <ellipse cx="561" cy="358" rx="14" ry="7.5" fill="#f59e0b" transform="rotate(22 561 358)" />
                    <path d="M 551 363 L 573 363 L 571 367 L 549 367 Z" fill="#ffffff" />
                    <ellipse cx="576" cy="369" rx="14" ry="7.5" fill="#f59e0b" transform="rotate(12 576 369)" />
                    <path d="M 566 373 L 588 373 L 586 377 L 564 377 Z" fill="#ffffff" />

                    <!-- Cute sleeping golden cat -->
                    <ellipse cx="588" cy="376" rx="24" ry="16" fill="#f59e0b" />
                    <circle cx="573" cy="372" r="10" fill="#f59e0b" />
                    <polygon points="566,364 571,356 575,365" fill="#d97706" />
                    <polygon points="574,364 579,358 582,366" fill="#d97706" />
                    <path d="M 568 372 Q 570 370 572 372" stroke="#78350f" stroke-width="1.2" fill="none" />
                    <path d="M 574 372 Q 576 370 578 372" stroke="#78350f" stroke-width="1.2" fill="none" />
                    <path d="M 583 362 Q 588 366 583 371" stroke="#d97706" stroke-width="2" fill="none" />
                    <path d="M 593 364 Q 598 369 593 375" stroke="#d97706" stroke-width="2" fill="none" />
                    <path d="M 608 376 C 618 371, 618 386, 601 388" fill="none" stroke="#f59e0b" stroke-width="5" stroke-linecap="round" />
                </svg>

                <!-- Right Side: Smartphone Frame with Dynamic QR Code -->
                <div class="qr-poster-phone">
                    <div class="qr-poster-phone-notch"></div>
                    <div id="posterQrWrapper" class="qr-poster-code-box">
                        <div id="localQrCanvas"></div>
                    </div>
                    <div class="qr-poster-phone-text">
                        Scan this QR Code<br>to shop anytime,<br>anywhere!
                    </div>
                </div>

                <!-- Poster Bottom Bar / Footer -->
                <div class="qr-poster-footer">
                    <div class="qr-poster-location">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span><?= e($locationDisplay) ?></span>
                    </div>
                    <div class="qr-poster-branding">
                        <span>Powered by</span>
                        <span class="qr-pos-badge">POS</span>
                        <strong>POS</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('assets/js/qrcode.min.js') ?>"></script>
<script src="<?= asset('assets/js/html2canvas.min.js') ?>"></script>
<script>
let qrPosterInitialized = false;
const storePublicUrl = <?= json_encode($localUrl) ?>;
const storeSlugName = <?= json_encode($slug ?: 'store') ?>;

function initPosterQr() {
    if (qrPosterInitialized) return;
    const qrContainer = document.getElementById('localQrCanvas');
    if (!qrContainer) return;
    qrContainer.innerHTML = '';
    
    // Generate QR with qrcode.js (renders to local canvas with no CORS restrictions)
    if (typeof QRCode !== 'undefined') {
        new QRCode(qrContainer, {
            text: storePublicUrl,
            width: 172,
            height: 172,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H
        });
        qrPosterInitialized = true;
    } else {
        qrContainer.innerHTML = '<img src="' + <?= json_encode($qrSrc) ?> + '" style="width:100%;height:100%;object-fit:contain" alt="QR Code" crossorigin="anonymous">';
        qrPosterInitialized = true;
    }
}

function openQrPosterModal(e) {
    if (e && e.preventDefault) e.preventDefault();
    const modal = document.getElementById('qrPosterModal');
    if (!modal) return;
    initPosterQr();
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeQrPosterModal() {
    const modal = document.getElementById('qrPosterModal');
    if (!modal) return;
    modal.style.display = 'none';
    document.body.style.overflow = '';
    const menu = document.getElementById('qrDownloadMenu');
    if (menu) menu.classList.remove('show');
}

function toggleDownloadDropdown(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    const menu = document.getElementById('qrDownloadMenu');
    if (menu) menu.classList.toggle('show');
}

function toggleCardMenu(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    const drop = document.getElementById('osCardDropdown');
    if (drop) drop.classList.toggle('show');
}

document.addEventListener('click', function(e) {
    const menu = document.getElementById('qrDownloadMenu');
    if (menu && !menu.contains(e.target) && !e.target.closest('.qr-dropdown-toggle')) {
        menu.classList.remove('show');
    }
    const cardDrop = document.getElementById('osCardDropdown');
    if (cardDrop && !cardDrop.contains(e.target) && !e.target.closest('.os-card-menu-btn')) {
        cardDrop.classList.remove('show');
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeQrPosterModal();
        const cardDrop = document.getElementById('osCardDropdown');
        if (cardDrop) cardDrop.classList.remove('show');
    }
});

function downloadPoster(format) {
    format = format || 'png';
    const menu = document.getElementById('qrDownloadMenu');
    if (menu) menu.classList.remove('show');

    const posterElement = document.getElementById('posterCardToCapture');
    if (!posterElement) return;

    const btn = document.querySelector('.qr-download-btn');
    const originalText = btn ? btn.textContent : '';
    if (btn) {
        btn.textContent = 'Generating...';
        btn.disabled = true;
    }

    if (typeof html2canvas === 'undefined') {
        alert('Canvas exporter not available. You can use Print Poster option.');
        if (btn) {
            btn.textContent = originalText;
            btn.disabled = false;
        }
        return;
    }

    html2canvas(posterElement, {
        scale: 3, // 3x scale for ultra crisp, high-resolution export
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#083d30',
        logging: false
    }).then(function(canvas) {
        const mimeType = format === 'jpeg' ? 'image/jpeg' : 'image/png';
        const fileExt = format === 'jpeg' ? 'jpg' : 'png';
        const dataUrl = canvas.toDataURL(mimeType, 0.95);
        
        const link = document.createElement('a');
        link.download = 'store-poster-' + (storeSlugName || 'qr') + '.' + fileExt;
        link.href = dataUrl;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        if (btn) {
            btn.textContent = originalText;
            btn.disabled = false;
        }
    }).catch(function(err) {
        console.error('Error generating poster image:', err);
        alert('Could not download image. You can use the Print Poster option.');
        if (btn) {
            btn.textContent = originalText;
            btn.disabled = false;
        }
    });
}

function printPoster() {
    const menu = document.getElementById('qrDownloadMenu');
    if (menu) menu.classList.remove('show');
    window.print();
}
</script>
</body>
</html>

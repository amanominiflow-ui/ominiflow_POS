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
if (!in_array($tab, ['overview', 'preferences', 'domain', 'customize'], true)) {
    $tab = 'overview';
}

$pageTitles = [
    'overview' => 'Mobile Store',
    'preferences' => 'Store Preferences',
    'domain' => 'Custom Domain',
    'customize' => 'Home Layout',
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
    if (!in_array($backTab, ['overview', 'preferences', 'domain', 'customize'], true)) {
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

    if ($action === 'save_preferences' || $action === 'save_customize' || $action === 'publish_layout') {
        $res = save_mobile_store_settings($bid, [
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
        ], $_FILES);
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
        .os-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        @media (max-width: 1100px) { .os-grid { grid-template-columns: 1fr; } }
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
                        <p class="page-subtitle">This website is exclusive to your business. Customers browse items and place orders with your branding — not the platform logo.</p>
                    </div>
                    <a class="btn-primary" href="<?= e($localUrl) ?>" target="_blank" rel="noopener">View Store</a>
                </div>
                <div class="os-grid">
                    <div class="os-card">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
                            <div>
                                <div class="os-label" style="margin-top:0">Display Name</div>
                                <div style="font-size:18px;font-weight:800"><?= e($brand['display_name']) ?></div>
                                <div class="os-label">Store URL</div>
                                <div class="os-url">
                                    <input id="ovUrl" type="text" value="<?= e($publicLabel) ?>" readonly>
                                    <button type="button" class="btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById('ovUrl').value)">Copy</button>
                                </div>
                                <div style="margin-top:12px">
                                    Status
                                    <span class="os-badge <?= $published ? 'os-open' : 'os-closed' ?>"><?= $published ? 'Open' : 'Closed' ?></span>
                                </div>
                                <a href="<?= e($qrSrc) ?>" target="_blank" class="os-help" style="display:inline-block;margin-top:14px;color:#2563eb;font-weight:700">Get QR Code</a>
                            </div>
                            <div class="os-logo-preview">
                                <?php if ($brand['logo_path']): ?>
                                    <img src="<?= asset($brand['logo_path']) ?>" alt="" style="width:64px;height:64px;border-radius:12px;object-fit:cover">
                                <?php else: ?>
                                    <?= e($brand['initials']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="os-phone"><iframe src="<?= e($localUrl) ?>" title="Store preview"></iframe></div>
                        <a class="btn-primary" href="<?= e(os_tab_url('customize')) ?>" style="display:block;text-align:center;margin:16px auto 0;max-width:320px">Customize App</a>
                    </div>
                </div>

            <?php elseif ($tab === 'preferences'): ?>
                <div class="page-header-row" style="margin-bottom:18px">
                    <div>
                        <h1 class="page-title">Preferences</h1>
                        <p class="page-subtitle">Your store name, logo, and colors. The OminiFlow logo is never shown on the customer website.</p>
                    </div>
                </div>
                <form class="os-card" method="post" enctype="multipart/form-data" style="max-width:720px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_preferences">
                    <input type="hidden" name="tab" value="preferences">
                    <input type="hidden" name="show_banner" value="<?= $brand['show_banner'] ? '1' : '' ?>">
                    <input type="hidden" name="show_categories" value="<?= $brand['show_categories'] ? '1' : '' ?>">
                    <input type="hidden" name="show_items" value="<?= $brand['show_items'] ? '1' : '' ?>">
                    <input type="hidden" name="banner_title" value="<?= e($brand['banner_title']) ?>">
                    <input type="hidden" name="banner_subtitle" value="<?= e($brand['banner_subtitle']) ?>">
                    <input type="hidden" name="search_placeholder" value="<?= e($brand['search_placeholder']) ?>">

                    <label class="os-label">Display name</label>
                    <input class="os-input" type="text" name="display_name" value="<?= e($brand['display_name']) ?>" required>

                    <label class="os-label">Store logo (your brand only)</label>
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
                        <div class="os-logo-preview">
                            <?php if ($brand['logo_path']): ?>
                                <img src="<?= asset($brand['logo_path']) ?>" alt="" style="width:64px;height:64px;border-radius:12px;object-fit:cover">
                            <?php else: ?>
                                <?= e($brand['initials']) ?>
                            <?php endif; ?>
                        </div>
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                    </div>
                    <?php if ($brand['logo_path']): ?>
                        <label class="os-check"><input type="checkbox" name="remove_logo" value="1"> Remove logo and use initials</label>
                    <?php endif; ?>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div>
                            <label class="os-label">Header color</label>
                            <input class="os-input" type="color" name="header_color" value="<?= e($brand['header_color']) ?>">
                        </div>
                        <div>
                            <label class="os-label">Button color</label>
                            <input class="os-input" type="color" name="accent_color" value="<?= e($brand['accent_color']) ?>">
                        </div>
                    </div>

                    <label class="os-check"><input type="checkbox" name="show_location" value="1" <?= $brand['show_location'] ? 'checked' : '' ?>> Show “Set delivery location”</label>
                    <label class="os-check"><input type="checkbox" name="store_published" value="1" <?= $published ? 'checked' : '' ?>> Store is Open</label>
                    <p class="os-help">Closed stores show “coming soon” to customers. POS billing still works.</p>
                    <button class="btn-primary" type="submit" style="margin-top:16px">Save preferences</button>
                </form>
                <form method="post" style="max-width:720px;margin-top:12px" class="os-card">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_slug">
                    <input type="hidden" name="tab" value="preferences">
                    <label class="os-label">Store slug</label>
                    <input class="os-input" type="text" name="store_slug" value="<?= e($slug) ?>" required>
                    <label class="os-check"><input type="checkbox" name="store_published" value="1" <?= $published ? 'checked' : '' ?>> Published / Open</label>
                    <button class="btn-secondary" type="submit" style="margin-top:10px">Save URL</button>
                </form>

            <?php elseif ($tab === 'customize'): ?>
                <div class="page-header-row" style="margin-bottom:18px">
                    <div>
                        <h1 class="page-title">Home Layout</h1>
                        <p class="page-subtitle">Turn sections on or off, edit the banner, then Publish. Preview updates on the right.</p>
                    </div>
                    <a class="btn-secondary" href="<?= e($localUrl) ?>" target="_blank">View Store</a>
                </div>
                <div class="os-grid">
                    <form class="os-card" method="post" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="tab" value="customize">
                        <input type="hidden" name="display_name" value="<?= e($brand['display_name']) ?>">
                        <input type="hidden" name="header_color" value="<?= e($brand['header_color']) ?>">
                        <input type="hidden" name="accent_color" value="<?= e($brand['accent_color']) ?>">

                        <label class="os-check"><input type="checkbox" name="show_location" value="1" <?= $brand['show_location'] ? 'checked' : '' ?>> Delivery location bar</label>
                        <label class="os-check"><input type="checkbox" name="show_banner" value="1" <?= $brand['show_banner'] ? 'checked' : '' ?>> Promo banner</label>
                        <label class="os-check"><input type="checkbox" name="show_categories" value="1" <?= $brand['show_categories'] ? 'checked' : '' ?>> All Categories</label>
                        <label class="os-check"><input type="checkbox" name="show_items" value="1" <?= $brand['show_items'] ? 'checked' : '' ?>> All Items</label>

                        <label class="os-label">Search placeholder</label>
                        <input class="os-input" type="text" name="search_placeholder" value="<?= e($brand['search_placeholder']) ?>">
                        <label class="os-label">Banner title</label>
                        <input class="os-input" type="text" name="banner_title" value="<?= e($brand['banner_title']) ?>">
                        <label class="os-label">Banner subtitle</label>
                        <input class="os-input" type="text" name="banner_subtitle" value="<?= e($brand['banner_subtitle']) ?>">
                        <label class="os-label">Banner image</label>
                        <input type="file" name="banner" accept="image/png,image/jpeg,image/webp">
                        <?php if ($brand['banner_image']): ?>
                            <label class="os-check"><input type="checkbox" name="remove_banner" value="1"> Remove banner image</label>
                        <?php endif; ?>

                        <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
                            <button class="btn-secondary" type="submit" name="action" value="save_customize">Save draft</button>
                            <button class="btn-primary" type="submit" name="action" value="publish_layout">Publish</button>
                        </div>
                    </form>
                    <div class="os-phone"><iframe src="<?= e($localUrl) ?>" title="Layout preview"></iframe></div>
                </div>

            <?php elseif ($tab === 'domain'): ?>
                <div class="page-header-row" style="margin-bottom:18px">
                    <div>
                        <h1 class="page-title">Custom Domain</h1>
                        <p class="page-subtitle">Map shop.yourbrand.com so customers open your website on your domain.</p>
                    </div>
                </div>
                <div class="os-card" style="margin-bottom:16px">
                    <div class="os-label" style="margin-top:0">Default store URL</div>
                    <div class="os-url"><input type="text" value="<?= e($localUrl) ?>" readonly></div>
                </div>
                <div class="os-card" style="margin-bottom:16px">
                    <p class="os-help">Add a CNAME at your registrar, or for XAMPP add a hosts line, then Verify.</p>
                    <div class="os-dns">
CNAME (production)<br>
Host: shop<br>
Points to: <?= e($cnameTarget) ?><br><br>
Local hosts file<br>
127.0.0.1 shop.yourbrand.test
                    </div>
                </div>
                <div class="os-card">
                    <form method="post" style="display:flex;gap:10px;max-width:640px;margin-bottom:16px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_domain">
                        <input class="os-input" type="text" name="domain" placeholder="shop.yourbrand.com" required>
                        <button class="btn-primary" type="submit">Add domain</button>
                    </form>
                    <?php if (!$domains): ?>
                        <p class="os-help" style="margin:0">No custom domain yet.</p>
                    <?php else: foreach ($domains as $d):
                        $st = (string) $d['status'];
                        ?>
                        <div class="os-domain-row">
                            <strong><?= e((string) $d['domain']) ?></strong>
                            <span class="os-badge <?= $st === 'verified' ? 'os-open' : 'os-closed' ?>"><?= e($st) ?></span>
                            <div class="os-actions">
                                <?php if ($st !== 'verified'): ?>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="verify_domain"><input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>"><button class="btn-primary" type="submit">Verify DNS</button></form>
                                    <?php if ($isDev): ?>
                                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="activate_local"><input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>"><button class="btn-secondary" type="submit">Activate locally</button></form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="disable_domain"><input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>"><button class="btn-secondary" type="submit">Disable</button></form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Remove this domain?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_domain"><input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>"><button class="btn-secondary" type="submit">Delete</button></form>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>
</body>
</html>

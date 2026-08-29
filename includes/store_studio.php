<?php
/**
 * Full-page Home Layout / Branding studio (Zoho POS parity).
 * Rendered without the POS sidebar.
 */
declare(strict_types=1);

$studioMode = ($tab === 'branding') ? 'branding' : 'page';
$headerColor = (string) ($brand['header_color'] ?? '#0f4c3a');
$headerText = (string) ($brand['header_text_color'] ?? '#ffffff');
$accentColor = (string) ($brand['accent_color'] ?? '#2563eb');
$buttonText = (string) ($brand['button_text_color'] ?? '#ffffff');
$fontSize = (string) ($brand['font_size'] ?? 'medium');
$displayName = (string) ($brand['display_name'] ?? 'My Store');
$logoPath = !empty($brand['logo_path']) ? asset((string) $brand['logo_path']) : '';
$faviconPath = !empty($brand['favicon_path']) ? asset((string) $brand['favicon_path']) : ($logoPath ?: '');
$showLogo = !empty($brand['show_logo_header']);
$showName = !empty($brand['show_name_with_logo']);
$catMode = (string) ($brand['category_mode'] ?? 'all');
$selectedCatIds = $brand['selected_category_ids'] ?? [];
$sectionOrder = storefront_home_sections($brand);
$visibleCats = storefront_visible_categories($builderCategories, $brand);
if (!$visibleCats) {
    $visibleCats = array_slice($builderCategories, 0, 4);
}
$previewCats = array_slice($visibleCats ?: $builderCategories, 0, max(2, ((int) ($brand['category_columns'] ?? 2)) * ((int) ($brand['category_rows'] ?? 2))));
$previewProducts = array_slice($builderProducts, 0, 2);
$publishedLabel = !empty($brand['published_at'])
    ? ('Published on ' . date('d M Y h:i A', strtotime((string) $brand['published_at'])))
    : ('Drafted on ' . date('d M Y H:i:s'));
$currency = (string) ($business['currency_symbol'] ?? $currency ?? '₹');
$catJson = [];
foreach ($builderCategories as $c) {
    $catJson[] = [
        'id' => (int) $c['id'],
        'name' => (string) $c['name'],
        'image' => !empty($c['image_path']) ? asset((string) $c['image_path']) : '',
    ];
}
if (!$catJson) {
    $catJson = [
        ['id' => 0, 'name' => 'MENS', 'image' => ''],
        ['id' => 0, 'name' => 'WOMEN', 'image' => ''],
        ['id' => 0, 'name' => 'GORGEOUS 3PCS', 'image' => ''],
        ['id' => 0, 'name' => 'TWO-PIECE SET', 'image' => ''],
    ];
}

function studio_cat_placeholder(): string {
    return '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $studioMode === 'branding' ? 'Branding' : 'Home Layout' ?> — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; overflow: hidden; font-family: Inter, "Segoe UI", sans-serif; background: #f1f5f9; }
        .st-app { display: grid; grid-template-columns: 72px 1fr; height: 100vh; }

        .st-rail { background: #0f172a; display: flex; flex-direction: column; align-items: center; padding: 14px 0 12px; }
        .st-rail-nav { display: flex; flex-direction: column; gap: 10px; width: 100%; align-items: center; }
        .st-rail-item {
            width: 52px; min-height: 56px; border-radius: 10px; color: #94a3b8; text-decoration: none;
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
            font-size: 10px; font-weight: 600; letter-spacing: 0.2px;
        }
        .st-rail-item:hover { background: #1e293b; color: #e2e8f0; }
        .st-rail-item.active { background: #2563eb; color: #ffffff; }
        .st-rail-exit { margin-top: auto; }

        .st-main { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
        .st-top {
            height: 64px; background: #fff; border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; justify-content: space-between; padding: 0 22px; flex-shrink: 0;
        }
        .st-title { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0; }
        .st-sub { font-size: 12px; color: #94a3b8; margin-top: 2px; }
        .st-top-actions { display: flex; align-items: center; gap: 12px; }
        .st-view {
            display: inline-flex; align-items: center; gap: 6px; color: #475569; font-size: 13.5px;
            font-weight: 600; text-decoration: none; padding: 7px 10px; border-radius: 6px;
        }
        .st-view:hover { background: #f1f5f9; color: #0f172a; }
        .st-publish {
            background: #2563eb; color: #fff; border: 0; border-radius: 6px; padding: 8px 16px;
            font-size: 13.5px; font-weight: 650; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        }
        .st-publish:hover { background: #1d4ed8; }

        .st-stage { flex: 1; display: flex; min-height: 0; }
        .st-canvas {
            flex: 1; overflow: auto; padding: 40px 200px 56px;
            background-color: #f1f5f9;
            background-image: radial-gradient(#cbd5e1 1.15px, transparent 1.15px);
            background-size: 18px 18px;
            display: flex; justify-content: center; align-items: flex-start;
        }
        .st-phone-wrap {
            width: 340px;
            position: relative;
            margin: 0 auto;
            flex-shrink: 0;
            overflow: visible;
        }
        .st-phone {
            background: #fff; border: 1px solid #cbd5e1; border-radius: 28px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12); overflow: visible;
            --st-header: <?= e($headerColor) ?>;
            --st-header-text: <?= e($headerText) ?>;
            --st-accent: <?= e($accentColor) ?>;
            --st-btn-text: <?= e($buttonText) ?>;
        }
        .st-phone.font-small { font-size: 12px; }
        .st-phone.font-medium { font-size: 13px; }
        .st-phone.font-large { font-size: 15px; }
        .st-notch { height: 16px; background: var(--st-header); display: flex; align-items: center; justify-content: center; border-radius: 28px 28px 0 0; }
        .st-notch span { width: 48px; height: 4px; background: rgba(255,255,255,.28); border-radius: 4px; }
        .st-ph-head { background: var(--st-header); color: var(--st-header-text); padding: 8px 14px 12px; }
        .st-ph-brand { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
        .st-ph-brand-left { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .st-ph-logo { width: 26px; height: 26px; border-radius: 6px; overflow: hidden; background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .st-ph-logo img { width: 100%; height: 100%; object-fit: cover; }
        .st-ph-name { font-weight: 800; letter-spacing: .4px; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 1.05em; }
        .st-ph-avatar { width: 26px; height: 26px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,.45); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .st-ph-loc { font-size: .82em; opacity: .88; display: flex; align-items: center; gap: 3px; margin-bottom: 8px; }
        .st-ph-search { background: #fff; border-radius: 8px; padding: 8px 10px; display: flex; align-items: center; gap: 7px; color: #64748b; font-size: .9em; overflow: hidden; height: 34px; box-sizing: border-box; }
        .st-ph-search-content { display: flex; align-items: center; white-space: nowrap; overflow: hidden; flex: 1; height: 20px; line-height: 20px; }
        .st-ph-sp-prefix { color: #94a3b8; font-weight: 500; }
        .st-ph-sp-track { display: inline-block; position: relative; height: 20px; overflow: hidden; margin-left: 2px; flex: 1; }
        .st-ph-sp-word { display: block; height: 20px; line-height: 20px; font-weight: 600; color: #475569; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transform: translate3d(0,0,0); backface-visibility: hidden; will-change: transform, opacity; }
        .st-ph-sp-word.st-slide-in { animation: msSearchSlideIn 0.3s cubic-bezier(0.22, 1, 0.36, 1) forwards; }
        .st-ph-sp-word.st-slide-out { animation: msSearchSlideOut 0.26s cubic-bezier(0.22, 1, 0.36, 1) forwards; }
        @keyframes msSearchSlideIn { 0% { transform: translate3d(0, 80%, 0); opacity: 0; } 100% { transform: translate3d(0, 0, 0); opacity: 1; } }
        @keyframes msSearchSlideOut { 0% { transform: translate3d(0, 0, 0); opacity: 1; } 100% { transform: translate3d(0, -80%, 0); opacity: 0; } }

        .st-sec { position: relative; padding: 12px; border: 2px dashed transparent; cursor: pointer; }
        .st-sec:hover, .st-sec.selected { border-color: #2563eb; }
        .st-pill {
            position: absolute; top: -2px; left: -2px; background: #2563eb; color: #fff;
            font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 0 0 6px 0; display: none; z-index: 6;
        }
        .st-sec:hover .st-pill, .st-sec.selected .st-pill { display: block; }
        .st-plus {
            position: absolute; left: 50%; transform: translateX(-50%); width: 20px; height: 20px; border-radius: 50%;
            background: #2563eb; color: #fff; display: none; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 700; z-index: 7; box-shadow: 0 2px 6px rgba(37,99,235,.4);
        }
        .st-plus.top { top: -10px; } .st-plus.bot { bottom: -10px; }
        .st-sec.selected .st-plus { display: flex; }

        .st-float {
            position: fixed; width: 168px; background: #1e293b; color: #fff;
            border-radius: 10px; padding: 6px 0; box-shadow: 0 12px 28px rgba(0,0,0,.28);
            display: none; z-index: 90;
        }
        .st-float.open { display: block; }
        .st-float button {
            width: 100%; background: none; border: 0; color: #f8fafc; font: inherit; font-size: 13px;
            display: flex; align-items: center; gap: 10px; padding: 9px 14px; cursor: pointer; text-align: left;
        }
        .st-float button:hover { background: #334155; }
        .st-float button.danger { color: #fecaca; }
        .st-float button.danger:hover { background: rgba(239,68,68,.18); }
        .st-float hr { border: 0; border-top: 1px solid #334155; margin: 4px 0; }

        .st-h { font-weight: 700; color: #0f172a; margin: 0 0 8px; font-size: 1.05em; }
        .st-cat-grid { display: grid; gap: 8px; }
        .st-cat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 6px; text-align: center; }
        .st-cat-img { height: 52px; background: #f8fafc; border-radius: 6px; display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 5px; }
        .st-cat-img img { width: 100%; height: 100%; object-fit: cover; }
        .st-cat-name { font-size: .78em; font-weight: 800; text-transform: uppercase; color: #0f172a; }

        .st-banner { border-radius: 8px; min-height: 108px; padding: 14px 16px; color: #fff; position: relative; overflow: hidden;
            background: linear-gradient(135deg, #5b21b6, #7c3aed 55%, #6d28d9); }
        .st-banner h4 { margin: 0; font-size: 1.15em; line-height: 1.2; font-weight: 800; }
        .st-banner p { margin: 4px 0 0; font-size: .85em; opacity: .92; }
        .st-dots { display: flex; justify-content: center; gap: 5px; margin-top: 7px; }
        .st-dots i { width: 6px; height: 6px; border-radius: 50%; background: #cbd5e1; display: block; cursor: pointer; transition: all 0.2s ease; }
        .st-dots i.on { background: #2563eb; width: 14px; border-radius: 999px; }

        .st-item-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .st-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; }
        .st-item-img { height: 78px; background: #f8fafc; border-radius: 6px; overflow: hidden; display: flex; align-items: center; justify-content: center; margin-bottom: 6px; }
        .st-item-img img { width: 100%; height: 100%; object-fit: cover; }
        .st-item-name { font-size: .82em; font-weight: 700; color: #0f172a; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .st-item-meta { font-size: .75em; color: #64748b; margin: 2px 0 6px; }
        .st-item-foot { display: flex; align-items: center; justify-content: space-between; }
        .st-item-price { font-size: .85em; font-weight: 800; }
        .st-add { background: var(--st-accent); color: var(--st-btn-text); font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 4px; }

        .st-panel {
            width: 400px; background: #fff; border-left: 1px solid #e2e8f0; display: none;
            flex-direction: column; flex-shrink: 0; min-height: 0;
        }
        .st-panel.open { display: flex; }
        .st-panel-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #f1f5f9; flex-shrink: 0; }
        .st-panel-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: #0f172a; }
        .st-x { background: none; border: 0; font-size: 18px; color: #64748b; cursor: pointer; line-height: 1; }
        .st-panel-body { padding: 18px 20px; overflow-y: auto; flex: 1; }
        .st-panel-foot { padding: 14px 20px; border-top: 1px solid #f1f5f9; display: flex; gap: 10px; background: #fff; flex-shrink: 0; }
        .st-save { background: #2563eb; color: #fff; border: 0; border-radius: 6px; padding: 9px 22px; font-weight: 650; cursor: pointer; font-size: 13.5px; }
        .st-save:hover { background: #1d4ed8; }
        .st-cancel { background: #fff; color: #334155; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 18px; font-weight: 600; cursor: pointer; font-size: 13.5px; }

        .st-label { display: block; font-size: 13px; font-weight: 650; color: #334155; margin: 0 0 6px; }
        .st-req { color: #ef4444; }
        .st-input, .st-hex {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 11px; font: inherit; font-size: 13.5px; color: #0f172a;
        }
        .st-check { display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #334155; margin: 10px 0 16px; cursor: pointer; }
        .st-hint { font-size: 12px; color: #64748b; margin: 6px 0 18px; }
        .st-sec-title { font-size: 13.5px; font-weight: 700; color: #0f172a; margin: 18px 0 10px; }
        .st-img-row { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
        .st-img-box { width: 72px; height: 48px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; overflow: hidden; display: flex; align-items: center; justify-content: center; }
        .st-img-box.sq { width: 48px; height: 48px; }
        .st-img-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .st-link { background: none; border: 0; color: #2563eb; font-weight: 650; font-size: 13px; cursor: pointer; padding: 0; }
        .st-color-group { margin-bottom: 16px; }
        .st-color-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .st-color-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; }
        .st-color-card span { display: block; font-size: 11px; color: #64748b; margin-bottom: 6px; }
        .st-color-row { display: flex; align-items: center; gap: 8px; }
        .st-color-row input[type=color] { width: 28px; height: 28px; border: 0; padding: 0; background: none; cursor: pointer; }
        .st-hex { flex: 1; text-transform: uppercase; font-size: 12.5px; font-weight: 700; padding: 6px 8px; }
        .st-fonts { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
        .st-font {
            border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 10px 6px; text-align: center; cursor: pointer;
            position: relative; background: #fff;
        }
        .st-font.on { border-color: #2563eb; background: #eff6ff; }
        .st-font b { display: block; font-size: 20px; color: #0f172a; }
        .st-font small { display: block; font-size: 10.5px; color: #64748b; margin-top: 4px; line-height: 1.25; }
        .st-font .tick {
            position: absolute; top: -7px; right: -7px; width: 16px; height: 16px; background: #2563eb; color: #fff;
            border-radius: 50%; font-size: 10px; display: none; align-items: center; justify-content: center; font-weight: 800;
        }
        .st-font.on .tick { display: flex; }

        .st-radios { display: flex; gap: 18px; margin: 8px 0 14px; }
        .st-radios label { display: flex; align-items: center; gap: 6px; font-size: 13.5px; color: #334155; cursor: pointer; }
        .st-info { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px 14px; font-size: 12.5px; color: #0369a1; margin-bottom: 12px; }
        .st-tabs { display: flex; align-items: center; gap: 16px; border-bottom: 1px solid #e2e8f0; margin-bottom: 12px; }
        .st-tab { background: none; border: 0; padding: 8px 0; font-size: 13px; font-weight: 650; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; }
        .st-tab.on { color: #2563eb; border-bottom-color: #2563eb; }
        .st-search { margin-left: auto; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px; font-size: 12.5px; width: 150px; }
        .st-cat-list { display: flex; flex-direction: column; }
        .st-cat-row { display: flex; align-items: center; gap: 10px; padding: 8px 2px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; }
        .st-cat-row img, .st-cat-thumb { width: 28px; height: 28px; border-radius: 4px; object-fit: cover; background: #f1f5f9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .st-hidden { display: none !important; }

        .st-flash { position: fixed; top: 16px; left: 50%; transform: translateX(-50%); z-index: 80; padding: 10px 16px; border-radius: 8px; font-size: 13.5px; font-weight: 600; box-shadow: 0 8px 24px rgba(0,0,0,.12); }
        .st-flash.ok { background: #dcfce7; color: #166534; }
        .st-flash.err { background: #fee2e2; color: #991b1b; }

        .st-modal-bg { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: none; align-items: center; justify-content: center; z-index: 90; }
        .st-modal-bg.open { display: flex; }
        .st-modal { background: #fff; border-radius: 12px; width: 380px; padding: 20px; box-shadow: 0 20px 50px rgba(0,0,0,.2); }
        .st-modal h4 { margin: 0 0 12px; }
        .st-ord { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 8px; font-weight: 650; }
        .st-ord-btns { display: flex; gap: 6px; }
        .st-ord-btns button { width: 28px; height: 28px; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; cursor: pointer; }

        .st-shape-grid, .st-size-grid { display: flex; gap: 10px; margin: 6px 0 14px; }
        .st-shape, .st-size {
            border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; cursor: pointer; position: relative; background: #fff;
        }
        .st-shape.on, .st-size.on { border-color: #2563eb; background: #eff6ff; }
        .st-shape .tick, .st-size .tick {
            position: absolute; top: -6px; right: -6px; width: 16px; height: 16px; background: #2563eb; color: #fff;
            border-radius: 50%; font-size: 10px; display: none; align-items: center; justify-content: center; font-weight: 800;
        }
        .st-shape.on .tick, .st-size.on .tick { display: flex; }
        .st-add-pop {
            position: absolute; left: 50%; transform: translateX(-50%); background: #fff; border: 1px solid #e2e8f0;
            border-radius: 8px; box-shadow: 0 8px 20px rgba(0,0,0,.12); padding: 6px; z-index: 30; display: none; min-width: 150px;
        }
        .st-add-pop.open { display: block; }
        .st-add-pop button { display: block; width: 100%; text-align: left; background: none; border: 0; padding: 8px 10px; font-size: 13px; cursor: pointer; border-radius: 5px; }
        .st-add-pop button:hover { background: #f1f5f9; }
        body.mode-branding .st-sec { cursor: default; border-color: transparent !important; }
        body.mode-branding .st-pill,
        body.mode-branding .st-plus,
        body.mode-branding .st-float { display: none !important; }
    </style>
</head>
<body class="mode-<?= e($studioMode) ?>">
<?php if ($flashSuccess): ?><div class="st-flash ok" id="stFlash"><?= e($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="st-flash err" id="stFlash"><?= e($flashError) ?></div><?php endif; ?>

<div class="st-app">
    <aside class="st-rail">
        <nav class="st-rail-nav">
            <a class="st-rail-item <?= $studioMode === 'page' ? 'active' : '' ?>" href="<?= e(os_tab_url('customize')) ?>">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 5a1 1 0 011-1h6v16H5a1 1 0 01-1-1V5z"/><path d="M14 4h5a1 1 0 011 1v6h-6V4z"/><path d="M14 13h6v6a1 1 0 01-1 1h-5v-7z"/></svg>
                <span>Page</span>
            </a>
            <a class="st-rail-item <?= $studioMode === 'branding' ? 'active' : '' ?>" href="<?= e(os_tab_url('branding')) ?>">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 3l1.8 4.5L18.5 9 13.8 10.7 12 15.4 10.2 10.7 5.5 9l4.7-1.5L12 3z"/><path d="M18 14l.9 2.2L21 17l-2.1.8L18 20l-.9-2.2L15 17l2.1-.8L18 14z"/></svg>
                <span>Branding</span>
            </a>
        </nav>
        <a class="st-rail-item st-rail-exit" href="<?= e(os_tab_url('overview')) ?>">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Exit</span>
        </a>
    </aside>

    <div class="st-main">
        <div class="st-top">
            <div>
                <h1 class="st-title"><?= $studioMode === 'branding' ? 'Branding' : 'Home Layout' ?></h1>
                <div class="st-sub"><?= e($publishedLabel) ?></div>
            </div>
            <div class="st-top-actions">
                <a class="st-view" href="<?= e($localUrl) ?>" target="_blank" rel="noopener">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    View Store
                </a>
                <form method="post" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="publish_layout">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <button type="submit" class="st-publish">
                        Publish
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
                    </button>
                </form>
            </div>
        </div>

        <div class="st-stage">
            <div class="st-canvas" id="stCanvas" onclick="onCanvasBg(event)">
                <div class="st-phone-wrap">
                    <div class="st-phone font-<?= e($fontSize) ?>" id="stPhone">
                        <div class="st-notch"><span></span></div>
                        <div class="st-ph-head" id="stPhHead" onclick="openHeaderEdit(event)" style="cursor:pointer;" title="Click to edit Header & Business Name">
                            <div class="st-ph-brand">
                                <div class="st-ph-brand-left">
                                    <div class="st-ph-logo" id="stPhLogo" style="<?= $showLogo ? '' : 'display:none' ?>">
                                        <?php if ($logoPath): ?>
                                            <img src="<?= e($logoPath) ?>" alt="" id="stPhLogoImg">
                                        <?php else: ?>
                                            <svg id="stPhLogoSvg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="st-ph-name" id="stPhName" style="<?= $showName ? '' : 'display:none' ?>"><?= e($displayName) ?></div>
                                </div>
                                <div class="st-ph-avatar">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
                                </div>
                            </div>
                            <?php if (!empty($brand['show_location'])): ?>
                                <div class="st-ph-loc">Set Delivery Location
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
                                </div>
                            <?php endif; ?>
                            <div class="st-ph-search">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <div class="st-ph-search-content">
                                    <span class="st-ph-sp-prefix">Search by </span>
                                    <span class="st-ph-sp-track"><span class="st-ph-sp-word" id="stPhSearchWord">"<?= e($builderCategories[0]['name'] ?? 'WOMEN') ?>"</span></span>
                                </div>
                            </div>
                        </div>

                        <div id="stSections">
                            <?php foreach ($sectionOrder as $secKey): ?>
                                <?php if ($secKey === 'category'): ?>
                                    <div class="st-sec" id="secCategory" data-sec="category" onclick="selectSec(event,'category')" <?= empty($brand['show_categories']) ? 'style="display:none"' : '' ?>>
                                        <div class="st-pill">Category</div>
                                        <div class="st-plus top" onclick="event.stopPropagation(); openAddPop(this)">+</div>
                                        <div class="st-plus bot" onclick="event.stopPropagation(); openAddPop(this)">+</div>
                                        <div class="st-h" id="canvasCatTitle"><?= e($brand['category_section_name'] ?: 'All Categories') ?></div>
                                        <div class="st-cat-grid" id="canvasCatGrid" style="grid-template-columns: repeat(<?= (int) ($brand['category_columns'] ?: 2) ?>, 1fr);">
                                            <?php
                                            $renderCats = $previewCats ?: [['name' => 'MENS'], ['name' => 'WOMEN'], ['name' => 'GORGEOUS 3PCS'], ['name' => 'TWO-PIECE SET']];
                                            foreach (array_slice($renderCats, 0, 4) as $sc): ?>
                                                <div class="st-cat-card">
                                                    <div class="st-cat-img">
                                                        <?php if (!empty($sc['image_path'])): ?>
                                                            <img src="<?= asset((string) $sc['image_path']) ?>" alt="">
                                                        <?php else: ?>
                                                            <?= studio_cat_placeholder() ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="st-cat-name"><?= e(strtoupper((string) ($sc['name'] ?? ''))) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php elseif ($secKey === 'banner'): ?>
                                    <div class="st-sec" id="secBanner" data-sec="banner" onclick="selectSec(event,'banner')" <?= empty($brand['show_banner']) ? 'style="display:none"' : '' ?>>
                                        <div class="st-pill">Banner</div>
                                        <div class="st-plus top" onclick="event.stopPropagation(); openAddPop(this)">+</div>
                                        <div class="st-plus bot" onclick="event.stopPropagation(); openAddPop(this)">+</div>
                                        <div class="st-h" id="canvasBannerName" style="<?= !empty($brand['show_banner_section_name']) ? '' : 'display:none' ?>"><?= e($brand['banner_section_name'] ?: 'Banners') ?></div>
                                        <div class="st-banner" id="canvasBannerCard" style="background: linear-gradient(135deg, #5b21b6, #7c3aed 55%, #6d28d9); transition: all 0.25s ease;">
                                            <!-- Banner 1 Slide -->
                                            <div id="canvasSlide1" class="st-banner-slide">
                                                <h4 id="canvasBannerTitle"><?= e($brand['banner_title'] ?: "We're online now!") ?></h4>
                                                <p id="canvasBannerSub"><?= e($brand['banner_subtitle'] ?: 'Stay at home and shop online.') ?></p>
                                                <svg viewBox="0 0 90 70" width="90" height="70" style="position:absolute;right:6px;bottom:4px;" fill="none">
                                                    <rect x="18" y="28" width="28" height="22" rx="2" fill="#fbbf24" transform="rotate(-12 18 28)"/>
                                                    <path d="M40 18 L68 10 L80 28 L52 36 Z" fill="#f59e0b"/>
                                                    <path d="M40 18 L52 36 L52 52 L40 34 Z" fill="#d97706"/>
                                                    <path d="M20 22 C8 14 6 28 16 32" stroke="#fff" stroke-width="2" fill="none"/>
                                                </svg>
                                            </div>
                                            <!-- Banner 2 Slide -->
                                            <div id="canvasSlide2" class="st-banner-slide" style="display:none;">
                                                <div id="canvasBanner2Tag" style="font-size:0.75em;opacity:0.9;"><?= e($brand['banner_2_tag'] ?? 'Best deal,') ?></div>
                                                <h4 id="canvasBanner2Title"><?= e($brand['banner_2_title'] ?? 'Start Shopping') ?></h4>
                                                <p id="canvasBanner2Sub"><?= e($brand['banner_2_subtitle'] ?? 'and discover the best deals!') ?></p>
                                                <svg viewBox="0 0 90 70" width="90" height="70" style="position:absolute;right:6px;bottom:4px;" fill="none">
                                                    <circle cx="60" cy="20" r="10" fill="#ffffff" opacity="0.95"/>
                                                    <path d="M50 35 C50 20, 68 20, 68 35" fill="none" stroke="#fef3c7" stroke-width="2"/>
                                                    <path d="M44 35 L74 35 L70 62 L48 62 Z" fill="#e09f67"/>
                                                </svg>
                                            </div>
                                            <!-- Banner 3 Slide -->
                                            <div id="canvasSlide3" class="st-banner-slide" style="display:none;">
                                                <div id="canvasBanner3Tag" style="font-size:0.75em;opacity:0.9;"><?= e($brand['banner_3_tag'] ?? 'Order') ?></div>
                                                <h4 id="canvasBanner3Title" style="font-style:italic;"><?= e($brand['banner_3_title'] ?? 'with Ease') ?></h4>
                                                <p id="canvasBanner3Sub" style="margin-top:4px;font-weight:600;"><?= e($brand['banner_3_subtitle'] ?? 'with Speed') ?></p>
                                                <svg viewBox="0 0 90 70" width="90" height="70" style="position:absolute;right:6px;bottom:4px;" fill="none">
                                                    <circle cx="65" cy="22" r="3" fill="#ffffff"/>
                                                    <path d="M60 16 C57 16, 53 19, 53 23 C53 29, 60 35, 60 35 C60 35, 67 29, 67 23 C67 19, 63 16, 60 16 Z" fill="#ef4444"/>
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="st-dots">
                                            <i class="on" id="stDot1" onclick="event.stopPropagation(); switchBannerTab(1)"></i>
                                            <i id="stDot2" onclick="event.stopPropagation(); switchBannerTab(2)"></i>
                                            <i id="stDot3" onclick="event.stopPropagation(); switchBannerTab(3)"></i>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="st-sec" id="secItem" data-sec="item" onclick="selectSec(event,'item')" <?= empty($brand['show_items']) ? 'style="display:none"' : '' ?>>
                                        <div class="st-pill">Item</div>
                                        <div class="st-plus top" onclick="event.stopPropagation(); openAddPop(this)">+</div>
                                        <div class="st-plus bot" onclick="event.stopPropagation(); openAddPop(this)">+</div>
                                        <div class="st-h" id="canvasItemTitle"><?= e($brand['item_section_name'] ?: 'All Items') ?></div>
                                        <div class="st-item-grid">
                                            <?php if ($previewProducts): foreach ($previewProducts as $sp):
                                                $sImg = !empty($sp['image_path']) ? asset((string) $sp['image_path']) : ''; ?>
                                                <div class="st-item">
                                                    <div class="st-item-img"><?php if ($sImg): ?><img src="<?= e($sImg) ?>" alt=""><?php else: ?><?= studio_cat_placeholder() ?><?php endif; ?></div>
                                                    <div class="st-item-name"><?= e((string) $sp['name']) ?></div>
                                                    <div class="st-item-meta">In stock</div>
                                                    <div class="st-item-foot">
                                                        <span class="st-item-price"><?= e($currency) . number_format((float) $sp['selling_price'], 2) ?></span>
                                                        <span class="st-add">Add</span>
                                                    </div>
                                                </div>
                                            <?php endforeach; else: ?>
                                                <div class="st-item">
                                                    <div class="st-item-img"><?= studio_cat_placeholder() ?></div>
                                                    <div class="st-item-name">SAMPLE ITEM</div>
                                                    <div class="st-item-foot"><span class="st-item-price"><?= e($currency) ?>499.00</span><span class="st-add">Add</span></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if ($studioMode === 'page'): ?>
                    <div class="st-float" id="stFloatMenu" onclick="event.stopPropagation()">
                        <?php require __DIR__ . '/store_studio_menu.php'; ?>
                    </div>
                    <?php endif; ?>
                    <div class="st-add-pop" id="stAddPop">
                        <button type="button" onclick="restoreSec('category')">Category</button>
                        <button type="button" onclick="restoreSec('banner')">Banner</button>
                        <button type="button" onclick="restoreSec('item')">Item</button>
                    </div>
                </div>
            </div>

            <!-- Branding panel -->
            <aside class="st-panel <?= $studioMode === 'branding' ? 'open' : '' ?>" id="panelBranding">
                <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;height:100%;min-height:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_branding">
                    <input type="hidden" name="tab" value="branding">
                    <input type="hidden" name="font_size" id="inputFontSize" value="<?= e($fontSize) ?>">
                    <div class="st-panel-head"><h3>Branding</h3></div>
                    <div class="st-panel-body">
                        <div class="st-sec-title" style="margin-top:0;">Business Details</div>
                        <label class="st-label">Business / Store Display Name <span class="st-req">*</span></label>
                        <input class="st-input" type="text" name="display_name" id="brandingDisplayName" value="<?= e($displayName) ?>" placeholder="e.g. ASH COLLECTIVE" oninput="updateCanvasDisplayName(this.value)" style="margin-bottom:18px;">

                        <div class="st-sec-title">Header Logo</div>
                        <label class="st-check">
                            <input type="checkbox" name="show_logo_header" value="1" <?= $showLogo ? 'checked' : '' ?> onchange="previewLogoName()">
                            <span>Show logo on the header</span>
                        </label>
                        <div class="st-img-row">
                            <div class="st-img-box" id="logoPreviewBox">
                                <?php if ($logoPath): ?><img src="<?= e($logoPath) ?>" alt="" id="logoPreviewImg"><?php else: ?><span style="font-size:11px;color:#94a3b8">Logo</span><?php endif; ?>
                            </div>
                            <div>
                                <button type="button" class="st-link" onclick="document.getElementById('logoFile').click()">Change Image</button>
                                <input type="file" name="logo" id="logoFile" accept="image/*" hidden onchange="previewFile(this,'logoPreviewBox')">
                            </div>
                        </div>
                        <div class="st-hint">Preferred image size: 230 x 50 pixels, @ 72 DPI. Maximum size of 5MB.</div>

                        <label class="st-check">
                            <input type="checkbox" name="show_name_with_logo" value="1" <?= $showName ? 'checked' : '' ?> onchange="previewLogoName()">
                            <span>Show display name along with logo</span>
                        </label>

                        <div class="st-sec-title">Web Store Favicon</div>
                        <div class="st-img-row">
                            <div class="st-img-box sq" id="favPreviewBox">
                                <?php if ($faviconPath): ?><img src="<?= e($faviconPath) ?>" alt=""><?php else: ?><span style="font-size:11px;color:#94a3b8">ICO</span><?php endif; ?>
                            </div>
                            <button type="button" class="st-link" onclick="document.getElementById('favFile').click()">Change Image</button>
                            <input type="file" name="favicon" id="favFile" accept="image/png,image/jpeg,image/webp,image/x-icon,.ico" hidden onchange="previewFile(this,'favPreviewBox')">
                        </div>
                        <div class="st-hint">Preferred Image Size: 48 x 48 pixels</div>

                        <div class="st-sec-title">Theme Color</div>
                        <div class="st-color-group">
                            <div class="st-label">Header Color</div>
                            <div class="st-color-grid">
                                <div class="st-color-card">
                                    <span>Background Color</span>
                                    <div class="st-color-row">
                                        <input type="color" id="hdrBgPick" value="<?= e($headerColor) ?>" oninput="syncColor('hdrBg', this.value, 'header')">
                                        <input class="st-hex" name="header_color" id="hdrBg" value="<?= e(strtoupper($headerColor)) ?>" oninput="syncColor('hdrBg', this.value, 'header')">
                                    </div>
                                </div>
                                <div class="st-color-card">
                                    <span>Text Color</span>
                                    <div class="st-color-row">
                                        <input type="color" id="hdrTxPick" value="<?= e($headerText) ?>" oninput="syncColor('hdrTx', this.value, 'headerText')">
                                        <input class="st-hex" name="header_text_color" id="hdrTx" value="<?= e(strtoupper($headerText)) ?>" oninput="syncColor('hdrTx', this.value, 'headerText')">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="st-color-group">
                            <div class="st-label">Buttons &amp; Links Color</div>
                            <div class="st-color-grid">
                                <div class="st-color-card">
                                    <span>Background Color</span>
                                    <div class="st-color-row">
                                        <input type="color" id="btnBgPick" value="<?= e($accentColor) ?>" oninput="syncColor('btnBg', this.value, 'accent')">
                                        <input class="st-hex" name="accent_color" id="btnBg" value="<?= e(strtoupper($accentColor)) ?>" oninput="syncColor('btnBg', this.value, 'accent')">
                                    </div>
                                </div>
                                <div class="st-color-card">
                                    <span>Text Color</span>
                                    <div class="st-color-row">
                                        <input type="color" id="btnTxPick" value="<?= e($buttonText) ?>" oninput="syncColor('btnTx', this.value, 'btnText')">
                                        <input class="st-hex" name="button_text_color" id="btnTx" value="<?= e(strtoupper($buttonText)) ?>" oninput="syncColor('btnTx', this.value, 'btnText')">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="st-sec-title">Font Size</div>
                        <div class="st-fonts">
                            <?php foreach (['small' => 'Small', 'medium' => 'Medium (Recommended)', 'large' => 'Large'] as $key => $label): ?>
                                <div class="st-font <?= $fontSize === $key ? 'on' : '' ?>" onclick="setFontSize('<?= $key ?>', this)">
                                    <b>Aa</b>
                                    <small><?= e($label) ?></small>
                                    <span class="tick">✓</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="st-panel-foot">
                        <button type="submit" class="st-save">Save</button>
                    </div>
                </form>
            </aside>

            <!-- Layout edit panel -->
            <aside class="st-panel" id="panelLayout">
                <form method="post" id="layoutForm" style="display:flex;flex-direction:column;height:100%;min-height:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_customize">
                    <input type="hidden" name="tab" value="customize">
                    <input type="hidden" name="display_name" value="<?= e($displayName) ?>">
                    <input type="hidden" name="section_order" id="inputSectionOrder" value="<?= e(implode(',', $sectionOrder)) ?>">
                    <input type="hidden" name="show_banner" id="flagBanner" value="<?= !empty($brand['show_banner']) ? '1' : '' ?>">
                    <input type="hidden" name="show_categories" id="flagCategory" value="<?= !empty($brand['show_categories']) ? '1' : '' ?>">
                    <input type="hidden" name="show_items" id="flagItem" value="<?= !empty($brand['show_items']) ? '1' : '' ?>">
                    <input type="hidden" name="show_location" value="<?= !empty($brand['show_location']) ? '1' : '' ?>">
                    <input type="hidden" name="category_shape" id="inputCatShape" value="<?= e($brand['category_shape'] ?: 'rectangle') ?>">
                    <input type="hidden" name="category_columns" id="inputCatColumns" value="<?= (int) ($brand['category_columns'] ?: 2) ?>">
                    <input type="hidden" name="category_mode" id="inputCatMode" value="<?= e($catMode) ?>">

                    <div class="st-panel-head">
                        <h3 id="layoutHeading">Edit Category Component</h3>
                        <button type="button" class="st-x" onclick="closeLayoutPanel()">✕</button>
                    </div>
                    <div class="st-panel-body">
                        <div id="editCategory">
                            <label class="st-label">Section Name <span class="st-req">*</span></label>
                            <input class="st-input" type="text" name="category_section_name" id="inputCatSectionName" value="<?= e($brand['category_section_name'] ?: 'All Categories') ?>" oninput="document.getElementById('canvasCatTitle').textContent=this.value">

                            <div class="st-sec-title" style="margin-top:16px">Suggested Categories</div>
                            <div class="st-radios">
                                <label><input type="radio" name="suggested_cats" value="all" <?= $catMode !== 'custom' ? 'checked' : '' ?> onchange="setCatMode('all')"> All Categories</label>
                                <label><input type="radio" name="suggested_cats" value="custom" <?= $catMode === 'custom' ? 'checked' : '' ?> onchange="setCatMode('custom')"> Custom</label>
                            </div>
                            <div class="st-info" id="catAllInfo" style="<?= $catMode === 'custom' ? 'display:none' : '' ?>">All categories from the inventory will be listed in this section</div>
                            <div id="catCustomWrap" class="<?= $catMode === 'custom' ? '' : 'st-hidden' ?>">
                                <div class="st-tabs">
                                    <button type="button" class="st-tab on" id="tabAllCats" onclick="switchCatTab('all')">All Categories</button>
                                    <button type="button" class="st-tab" id="tabSelCats" onclick="switchCatTab('sel')">Selected</button>
                                    <input class="st-search" type="search" placeholder="Search Categories" oninput="filterCats(this.value)">
                                </div>
                                <div class="st-cat-list" id="catList"></div>
                            </div>
                        </div>

                        <div id="themeCategory" class="st-hidden">
                            <label class="st-label">Section Name <span class="st-req">*</span></label>
                            <input class="st-input" type="text" value="<?= e($brand['category_section_name'] ?: 'All Categories') ?>" readonly>
                            <div class="st-sec-title">Section Theme</div>
                            <div class="st-color-grid" style="margin-bottom:14px">
                                <div class="st-color-card">
                                    <span>Background</span>
                                    <div class="st-color-row">
                                        <input type="color" name="category_bg_color" value="<?= e($brand['category_bg_color'] ?: '#ffffff') ?>">
                                        <span style="font-size:12px;font-weight:700"><?= e($brand['category_bg_color'] ?: '#ffffff') ?></span>
                                    </div>
                                </div>
                                <div class="st-color-card">
                                    <span>Text Color</span>
                                    <div class="st-color-row">
                                        <input type="color" name="category_text_color" value="<?= e($brand['category_text_color'] ?: '#000000') ?>">
                                        <span style="font-size:12px;font-weight:700"><?= e($brand['category_text_color'] ?: '#000000') ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="st-label">Shape</div>
                            <div class="st-shape-grid">
                                <div class="st-shape <?= ($brand['category_shape'] ?? '') === 'square' ? 'on' : '' ?>" onclick="setShape('square', this)">
                                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                    <span class="tick">✓</span>
                                </div>
                                <div class="st-shape <?= ($brand['category_shape'] ?? '') !== 'square' ? 'on' : '' ?>" onclick="setShape('rectangle', this)">
                                    <svg width="48" height="34" viewBox="0 0 32 24" fill="none" stroke="#60a5fa" stroke-width="1.5"><rect x="2" y="3" width="28" height="18" rx="3"/><circle cx="7.5" cy="8.5" r="1.5"/><polyline points="30 15 22 10 5 21"/></svg>
                                    <span class="tick">✓</span>
                                </div>
                            </div>
                            <div class="st-label">Sizes</div>
                            <div class="st-size-grid">
                                <div class="st-size <?= (int) $brand['category_columns'] === 4 ? 'on' : '' ?>" onclick="setColumns(4, this)">
                                    <svg width="34" height="24" viewBox="0 0 24 16" fill="#e2e8f0"><rect width="4" height="6" rx="1"/><rect x="6" width="4" height="6" rx="1"/><rect x="12" width="4" height="6" rx="1"/><rect x="18" width="4" height="6" rx="1"/><rect y="8" width="4" height="6" rx="1"/><rect x="6" y="8" width="4" height="6" rx="1"/><rect x="12" y="8" width="4" height="6" rx="1"/><rect x="18" y="8" width="4" height="6" rx="1"/></svg>
                                    <span class="tick">✓</span>
                                </div>
                                <div class="st-size <?= (int) $brand['category_columns'] !== 4 ? 'on' : '' ?>" onclick="setColumns(2, this)">
                                    <svg width="34" height="24" viewBox="0 0 24 16" fill="#3b82f6"><rect width="9" height="6" rx="1"/><rect x="12" width="9" height="6" rx="1"/><rect y="8" width="9" height="6" rx="1"/><rect x="12" y="8" width="9" height="6" rx="1"/></svg>
                                    <span class="tick">✓</span>
                                </div>
                            </div>
                            <label class="st-label">No of Rows</label>
                            <select name="category_rows" class="st-input">
                                <?php for ($r = 1; $r <= 4; $r++): ?>
                                    <option value="<?= $r ?>" <?= (int) $brand['category_rows'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div id="editBanner" class="st-hidden">
                            <label class="st-label">Section Name <span class="st-req">*</span></label>
                            <input class="st-input" type="text" name="banner_section_name" value="<?= e($brand['banner_section_name'] ?: 'Banners') ?>" oninput="document.getElementById('canvasBannerName').textContent=this.value">
                            <label class="st-check">
                                <input type="checkbox" name="show_banner_section_name" value="1" <?= !empty($brand['show_banner_section_name']) ? 'checked' : '' ?> onchange="document.getElementById('canvasBannerName').style.display=this.checked?'block':'none'">
                                <span>Show section name</span>
                            </label>

                            <div class="st-sec-title" style="margin-top:16px">Customize Banners (3 Slides)</div>
                            <div class="st-tabs" style="margin-bottom:14px">
                                <button type="button" class="st-tab on" id="tabBanner1" onclick="switchBannerTab(1)">Banner 1</button>
                                <button type="button" class="st-tab" id="tabBanner2" onclick="switchBannerTab(2)">Banner 2</button>
                                <button type="button" class="st-tab" id="tabBanner3" onclick="switchBannerTab(3)">Banner 3</button>
                            </div>

                            <!-- Banner 1 -->
                            <div id="bannerGroup1">
                                <label class="st-label">Banner 1 Title</label>
                                <input class="st-input" type="text" name="banner_title" value="<?= e($brand['banner_title']) ?>" placeholder="e.g. We're online now!" oninput="updateBannerCanvas(1)" style="margin-bottom:12px">
                                <label class="st-label">Banner 1 Subtitle</label>
                                <input class="st-input" type="text" name="banner_subtitle" value="<?= e($brand['banner_subtitle']) ?>" placeholder="e.g. Stay at home and shop online." oninput="updateBannerCanvas(1)">
                            </div>

                            <!-- Banner 2 -->
                            <div id="bannerGroup2" class="st-hidden">
                                <label class="st-label">Banner 2 Tag</label>
                                <input class="st-input" type="text" name="banner_2_tag" value="<?= e($brand['banner_2_tag'] ?? 'Best deal,') ?>" placeholder="e.g. Best deal," oninput="updateBannerCanvas(2)" style="margin-bottom:12px">
                                <label class="st-label">Banner 2 Title</label>
                                <input class="st-input" type="text" name="banner_2_title" value="<?= e($brand['banner_2_title'] ?? 'Start Shopping') ?>" placeholder="e.g. Start Shopping" oninput="updateBannerCanvas(2)" style="margin-bottom:12px">
                                <label class="st-label">Banner 2 Subtitle</label>
                                <input class="st-input" type="text" name="banner_2_subtitle" value="<?= e($brand['banner_2_subtitle'] ?? 'and discover the best deals!') ?>" placeholder="e.g. and discover the best deals!" oninput="updateBannerCanvas(2)">
                            </div>

                            <!-- Banner 3 -->
                            <div id="bannerGroup3" class="st-hidden">
                                <label class="st-label">Banner 3 Tag / Prefix</label>
                                <input class="st-input" type="text" name="banner_3_tag" value="<?= e($brand['banner_3_tag'] ?? 'Order') ?>" placeholder="e.g. Order" oninput="updateBannerCanvas(3)" style="margin-bottom:12px">
                                <label class="st-label">Banner 3 Title</label>
                                <input class="st-input" type="text" name="banner_3_title" value="<?= e($brand['banner_3_title'] ?? 'with Ease') ?>" placeholder="e.g. with Ease" oninput="updateBannerCanvas(3)" style="margin-bottom:12px">
                                <label class="st-label">Banner 3 Subtitle / Action</label>
                                <input class="st-input" type="text" name="banner_3_subtitle" value="<?= e($brand['banner_3_subtitle'] ?? 'with Speed') ?>" placeholder="e.g. with Speed" oninput="updateBannerCanvas(3)">
                            </div>
                        </div>

                        <div id="editItem" class="st-hidden">
                            <label class="st-label">Section Name <span class="st-req">*</span></label>
                            <input class="st-input" type="text" name="item_section_name" value="<?= e($brand['item_section_name'] ?: 'All Items') ?>" oninput="document.getElementById('canvasItemTitle').textContent=this.value">
                            <div class="st-sec-title" style="margin-top:16px">Suggested Items</div>
                            <div class="st-radios">
                                <label><input type="radio" name="suggested_items" value="all" checked> All Items</label>
                                <label><input type="radio" name="suggested_items" value="custom"> Custom</label>
                            </div>
                            <div class="st-info">All Items from the inventory will be listed in this section</div>
                        </div>

                        <div id="editHeader" class="st-hidden">
                            <label class="st-label">Business / Store Display Name <span class="st-req">*</span></label>
                            <input class="st-input" type="text" name="display_name" id="layoutDisplayName" value="<?= e($displayName) ?>" placeholder="e.g. ASH COLLECTIVE" oninput="updateCanvasDisplayName(this.value)" style="margin-bottom:16px;">

                            <div class="st-sec-title">Header Elements</div>
                            <label class="st-check" style="margin-bottom:10px">
                                <input type="checkbox" name="show_name_with_logo" id="layoutShowName" value="1" <?= $showName ? 'checked' : '' ?> onchange="previewLogoName(this)">
                                <span>Show business name on header</span>
                            </label>
                            <label class="st-check" style="margin-bottom:12px">
                                <input type="checkbox" name="show_logo_header" id="layoutShowLogo" value="1" <?= $showLogo ? 'checked' : '' ?> onchange="previewLogoName(this)">
                                <span>Show logo on header</span>
                            </label>
                            <label class="st-check" style="margin-bottom:18px">
                                <input type="checkbox" name="show_location" value="1" <?= !empty($brand['show_location']) ? 'checked' : '' ?>>
                                <span>Show delivery location selector</span>
                            </label>

                            <div class="st-hint" style="margin-top:10px">You can upload logo and customize theme colors in the <strong>Branding</strong> tab on the left.</div>
                        </div>
                    </div>
                    <div class="st-panel-foot">
                        <button type="submit" class="st-save">Save</button>
                        <button type="button" class="st-cancel" onclick="closeLayoutPanel()">Cancel</button>
                    </div>
                </form>
            </aside>
        </div>
    </div>
</div>

<div class="st-modal-bg" id="rearrangeModal">
    <div class="st-modal">
        <h4>Rearrange sections</h4>
        <div id="ordList"></div>
        <div style="display:flex;gap:8px;margin-top:14px;justify-content:flex-end">
            <button type="button" class="st-cancel" onclick="document.getElementById('rearrangeModal').classList.remove('open')">Cancel</button>
            <button type="button" class="st-save" onclick="applyRearrange()">Apply</button>
        </div>
    </div>
</div>

<script>
const STUDIO_MODE = <?= json_encode($studioMode) ?>;
const STUDIO_CATS = <?= json_encode($catJson, JSON_UNESCAPED_UNICODE) ?>;
const SELECTED_IDS = <?= json_encode(array_values(array_map('intval', $selectedCatIds))) ?>;
let catTab = 'all';
let selectedIds = new Set(SELECTED_IDS.filter(Boolean));

(function renderCatList() {
    const box = document.getElementById('catList');
    if (!box) return;
    box.innerHTML = STUDIO_CATS.map(c => {
        const checked = selectedIds.has(c.id) ? 'checked' : '';
        const thumb = c.image
            ? `<img src="${c.image}" alt="">`
            : `<span class="st-cat-thumb"><?= studio_cat_placeholder() ?></span>`;
        return `<label class="st-cat-row" data-name="${(c.name || '').toLowerCase()}" data-id="${c.id}">
            <input type="checkbox" name="selected_category_ids[]" value="${c.id}" ${checked} onchange="onCatCheck(${c.id}, this.checked)">
            ${thumb}
            <span>${c.name}</span>
        </label>`;
    }).join('') || '<div class="st-hint">No categories yet. Add them in Inventory.</div>';
    filterCats(document.querySelector('.st-search')?.value || '');
})();

function selectSec(ev, key) {
    if (STUDIO_MODE === 'branding') return;
    if (ev) ev.stopPropagation();
    document.querySelectorAll('.st-sec').forEach(s => s.classList.remove('selected'));
    const el = document.getElementById('sec' + key.charAt(0).toUpperCase() + key.slice(1));
    if (el && el.style.display !== 'none') el.classList.add('selected');
    placeFloatMenu();
}
function placeFloatMenu() {
    const menu = document.getElementById('stFloatMenu');
    const sec = document.querySelector('.st-sec.selected');
    if (!menu) return;
    if (STUDIO_MODE === 'branding' || !sec) {
        menu.classList.remove('open');
        return;
    }
    const r = sec.getBoundingClientRect();
    menu.classList.add('open');
    menu.style.top = Math.max(12, r.top + 8) + 'px';
    menu.style.left = (r.right + 10) + 'px';
}
function hideFloatMenu() {
    const menu = document.getElementById('stFloatMenu');
    if (menu) menu.classList.remove('open');
}
function openDrawer(kind) {
    if (STUDIO_MODE === 'branding') return;
    const panel = document.getElementById('panelLayout');
    panel.classList.add('open');
    ['editCategory','themeCategory','editBanner','editItem','editHeader'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.add('st-hidden');
    });
    const map = {
        'edit-category': ['editCategory', 'Edit Category Component'],
        'theme-category': ['themeCategory', 'Category Properties'],
        'edit-banner': ['editBanner', 'Edit Banner Component'],
        'theme-banner': ['editBanner', 'Edit Banner Component'],
        'edit-item': ['editItem', 'Edit Item Component'],
        'theme-item': ['editItem', 'Edit Item Component'],
        'edit-header': ['editHeader', 'Edit Header Component']
    };
    const cfg = map[kind] || map['edit-category'];
    const target = document.getElementById(cfg[0]);
    if (target) target.classList.remove('st-hidden');
    document.getElementById('layoutHeading').textContent = cfg[1];
    requestAnimationFrame(placeFloatMenu);
}
function openHeaderEdit(ev) {
    if (ev) ev.stopPropagation();
    if (STUDIO_MODE === 'branding') return;
    document.querySelectorAll('.st-sec').forEach(s => s.classList.remove('selected'));
    hideFloatMenu();
    openDrawer('edit-header');
}
function updateCanvasDisplayName(val) {
    val = val || '';
    const phName = document.getElementById('stPhName');
    if (phName) phName.textContent = val.toUpperCase();
    const b1 = document.getElementById('brandingDisplayName');
    if (b1 && b1.value !== val) b1.value = val;
    const b2 = document.getElementById('layoutDisplayName');
    if (b2 && b2.value !== val) b2.value = val;
}
function menuAction(kind) {
    const selected = document.querySelector('.st-sec.selected');
    const key = selected ? selected.dataset.sec : 'category';
    if (kind === 'edit') {
        openDrawer(key === 'category' ? 'edit-category' : (key === 'banner' ? 'edit-banner' : 'edit-item'));
    } else if (kind === 'theme') {
        openDrawer(key === 'category' ? 'theme-category' : (key === 'banner' ? 'theme-banner' : 'theme-item'));
    } else if (kind === 'move') {
        moveSec(key, 'down');
    } else if (kind === 'rearrange') {
        openRearrange();
    } else if (kind === 'delete') {
        toggleSec(key, false);
    }
}
function switchBannerTab(idx) {
    [1, 2, 3].forEach(i => {
        const tab = document.getElementById('tabBanner' + i);
        const group = document.getElementById('bannerGroup' + i);
        const slide = document.getElementById('canvasSlide' + i);
        const dot = document.getElementById('stDot' + i);
        if (tab) tab.classList.toggle('on', i === idx);
        if (group) group.classList.toggle('st-hidden', i !== idx);
        if (slide) slide.style.display = (i === idx) ? 'block' : 'none';
        if (dot) dot.classList.toggle('on', i === idx);
    });

    const card = document.getElementById('canvasBannerCard');
    if (card) {
        if (idx === 1) {
            card.style.background = 'linear-gradient(135deg, #5b21b6, #7c3aed 55%, #6d28d9)';
        } else if (idx === 2) {
            card.style.background = 'linear-gradient(135deg, #1e60d5 0%, #2563eb 100%)';
        } else if (idx === 3) {
            card.style.background = 'linear-gradient(135deg, #582098 0%, #7c3aed 100%)';
        }
    }
}

function updateBannerCanvas(idx) {
    if (idx === 1) {
        const t = document.querySelector('input[name="banner_title"]')?.value || "We're online now!";
        const s = document.querySelector('input[name="banner_subtitle"]')?.value || 'Stay at home and shop online.';
        const elT = document.getElementById('canvasBannerTitle');
        const elS = document.getElementById('canvasBannerSub');
        if (elT) elT.textContent = t;
        if (elS) elS.textContent = s;
    } else if (idx === 2) {
        const tag = document.querySelector('input[name="banner_2_tag"]')?.value || 'Best deal,';
        const t = document.querySelector('input[name="banner_2_title"]')?.value || 'Start Shopping';
        const s = document.querySelector('input[name="banner_2_subtitle"]')?.value || 'and discover the best deals!';
        const elTag = document.getElementById('canvasBanner2Tag');
        const elT = document.getElementById('canvasBanner2Title');
        const elS = document.getElementById('canvasBanner2Sub');
        if (elTag) elTag.textContent = tag;
        if (elT) elT.textContent = t;
        if (elS) elS.textContent = s;
    } else if (idx === 3) {
        const tag = document.querySelector('input[name="banner_3_tag"]')?.value || 'Order';
        const t = document.querySelector('input[name="banner_3_title"]')?.value || 'with Ease';
        const s = document.querySelector('input[name="banner_3_subtitle"]')?.value || 'with Speed';
        const elTag = document.getElementById('canvasBanner3Tag');
        const elT = document.getElementById('canvasBanner3Title');
        const elS = document.getElementById('canvasBanner3Sub');
        if (elTag) elTag.textContent = tag;
        if (elT) elT.textContent = t;
        if (elS) elS.textContent = s;
    }
}

function closeLayoutPanel() {
    document.getElementById('panelLayout').classList.remove('open');
    requestAnimationFrame(placeFloatMenu);
}
function onCanvasBg(ev) {
    if (ev.target.closest('.st-sec') || ev.target.closest('.st-float') || ev.target.closest('.st-add-pop')) return;
    document.querySelectorAll('.st-sec').forEach(s => s.classList.remove('selected'));
    hideFloatMenu();
    document.getElementById('stAddPop').classList.remove('open');
}
function toggleSec(key, show) {
    const el = document.getElementById('sec' + key.charAt(0).toUpperCase() + key.slice(1));
    const flag = document.getElementById('flag' + key.charAt(0).toUpperCase() + key.slice(1));
    if (el) el.style.display = show ? '' : 'none';
    if (flag) flag.value = show ? '1' : '';
    if (el) el.classList.remove('selected');
    hideFloatMenu();
}
function restoreSec(key) {
    toggleSec(key, true);
    document.getElementById('stAddPop').classList.remove('open');
}
function openAddPop(btn) {
    const pop = document.getElementById('stAddPop');
    const rect = btn.getBoundingClientRect();
    const wrap = document.querySelector('.st-phone-wrap').getBoundingClientRect();
    pop.style.top = (rect.bottom - wrap.top + 8) + 'px';
    pop.classList.toggle('open');
}
function moveSec(key, dir) {
    const wrap = document.getElementById('stSections');
    const el = document.getElementById('sec' + key.charAt(0).toUpperCase() + key.slice(1));
    if (!wrap || !el) return;
    if (dir === 'down' && el.nextElementSibling) wrap.insertBefore(el.nextElementSibling, el);
    else if (dir === 'up' && el.previousElementSibling) wrap.insertBefore(el, el.previousElementSibling);
    syncOrder();
    placeFloatMenu();
}
function syncOrder() {
    const keys = [...document.querySelectorAll('#stSections .st-sec')].map(s => s.dataset.sec);
    document.getElementById('inputSectionOrder').value = keys.join(',');
}
function openRearrange() {
    const keys = [...document.querySelectorAll('#stSections .st-sec')].map(s => s.dataset.sec);
    const labels = { category: 'All Categories', banner: 'Banners', item: 'All Items' };
    window._ord = keys.slice();
    drawOrd();
    document.getElementById('rearrangeModal').classList.add('open');
    function drawOrd() {
        document.getElementById('ordList').innerHTML = window._ord.map((k, i) =>
            `<div class="st-ord"><span>${labels[k] || k}</span><div class="st-ord-btns">
                <button type="button" onclick="ordMove(${i},-1)">↑</button>
                <button type="button" onclick="ordMove(${i},1)">↓</button>
            </div></div>`
        ).join('');
    }
    window.drawOrd = drawOrd;
}
function ordMove(i, d) {
    const j = i + d;
    if (j < 0 || j >= window._ord.length) return;
    const t = window._ord[i]; window._ord[i] = window._ord[j]; window._ord[j] = t;
    window.drawOrd();
}
function applyRearrange() {
    const wrap = document.getElementById('stSections');
    window._ord.forEach(k => {
        const el = document.getElementById('sec' + k.charAt(0).toUpperCase() + k.slice(1));
        if (el) wrap.appendChild(el);
    });
    syncOrder();
    document.getElementById('rearrangeModal').classList.remove('open');
    placeFloatMenu();
}
function setCatMode(mode) {
    document.getElementById('inputCatMode').value = mode;
    document.getElementById('catAllInfo').style.display = mode === 'custom' ? 'none' : '';
    document.getElementById('catCustomWrap').classList.toggle('st-hidden', mode !== 'custom');
    refreshCatPreview();
}
function switchCatTab(tab) {
    catTab = tab;
    document.getElementById('tabAllCats').classList.toggle('on', tab === 'all');
    document.getElementById('tabSelCats').classList.toggle('on', tab === 'sel');
    filterCats(document.querySelector('.st-search')?.value || '');
}
function filterCats(q) {
    q = (q || '').toLowerCase();
    document.querySelectorAll('#catList .st-cat-row').forEach(row => {
        const match = (row.dataset.name || '').includes(q);
        const selected = selectedIds.has(parseInt(row.dataset.id, 10));
        row.style.display = (match && (catTab === 'all' || selected)) ? 'flex' : 'none';
    });
}
function onCatCheck(id, on) {
    if (on) selectedIds.add(id); else selectedIds.delete(id);
    refreshCatPreview();
    if (catTab === 'sel') filterCats(document.querySelector('.st-search')?.value || '');
}
function refreshCatPreview() {
    const mode = document.getElementById('inputCatMode').value;
    const cols = document.getElementById('inputCatColumns').value || 2;
    const grid = document.getElementById('canvasCatGrid');
    let list = STUDIO_CATS;
    if (mode === 'custom' && selectedIds.size) list = STUDIO_CATS.filter(c => selectedIds.has(c.id));
    grid.style.gridTemplateColumns = 'repeat(' + cols + ', 1fr)';
    grid.innerHTML = list.slice(0, 8).map(c => {
        const img = c.image ? `<img src="${c.image}" alt="">` : `<?= studio_cat_placeholder() ?>`;
        return `<div class="st-cat-card"><div class="st-cat-img">${img}</div><div class="st-cat-name">${(c.name || '').toUpperCase()}</div></div>`;
    }).join('');
}
function setShape(shape, el) {
    document.getElementById('inputCatShape').value = shape;
    document.querySelectorAll('.st-shape').forEach(b => b.classList.remove('on'));
    el.classList.add('on');
}
function setColumns(n, el) {
    document.getElementById('inputCatColumns').value = n;
    document.querySelectorAll('.st-size').forEach(b => b.classList.remove('on'));
    el.classList.add('on');
    refreshCatPreview();
}
function syncColor(id, val, which) {
    let hex = String(val || '').trim();
    if (hex && hex[0] !== '#') hex = '#' + hex;
    if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return;
    document.getElementById(id).value = hex.toUpperCase();
    const pick = document.getElementById(id + 'Pick');
    if (pick) pick.value = hex;
    const phone = document.getElementById('stPhone');
    if (which === 'header') phone.style.setProperty('--st-header', hex);
    if (which === 'headerText') phone.style.setProperty('--st-header-text', hex);
    if (which === 'accent') phone.style.setProperty('--st-accent', hex);
    if (which === 'btnText') phone.style.setProperty('--st-btn-text', hex);
}
function setFontSize(size, el) {
    document.getElementById('inputFontSize').value = size;
    document.querySelectorAll('.st-font').forEach(b => b.classList.remove('on'));
    el.classList.add('on');
    const phone = document.getElementById('stPhone');
    phone.classList.remove('font-small', 'font-medium', 'font-large');
    phone.classList.add('font-' + size);
}
function previewLogoName() {
    const showLogo = document.querySelector('[name=show_logo_header]').checked;
    const showName = document.querySelector('[name=show_name_with_logo]').checked;
    document.getElementById('stPhLogo').style.display = showLogo ? 'flex' : 'none';
    document.getElementById('stPhName').style.display = showName ? 'block' : 'none';
}
function previewFile(input, boxId) {
    const file = input.files && input.files[0];
    if (!file) return;
    const url = URL.createObjectURL(file);
    document.getElementById(boxId).innerHTML = '<img src="' + url + '" alt="">';
    if (boxId === 'logoPreviewBox') {
        document.getElementById('stPhLogo').style.display = 'flex';
        document.getElementById('stPhLogo').innerHTML = '<img src="' + url + '" alt="">';
    }
}
setTimeout(() => { const f = document.getElementById('stFlash'); if (f) f.remove(); }, 4200);

if (STUDIO_MODE === 'page') {
    const first = [...document.querySelectorAll('#stSections .st-sec')].find(s => s.style.display !== 'none');
    if (first) selectSec(null, first.dataset.sec);
    const canvas = document.getElementById('stCanvas');
    if (canvas) canvas.addEventListener('scroll', placeFloatMenu);
    window.addEventListener('resize', placeFloatMenu);
}

// Phone preview search whole word slide autoplay effect
(function () {
    const wordEl = document.getElementById('stPhSearchWord');
    if (!wordEl) return;

    let cats = <?= json_encode(array_values(array_filter(array_map(static fn($c) => trim((string)($c['name'] ?? '')), $builderCategories ?? []))), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    if (!cats || cats.length === 0) {
        cats = ['WOMEN', 'MENS', 'ELECTRONIC', 'GROCERY'];
    }

    let catIndex = 0;
    let timer = null;

    function switchWord() {
        wordEl.classList.remove('st-slide-in');
        wordEl.classList.add('st-slide-out');

        setTimeout(function () {
            catIndex = (catIndex + 1) % cats.length;
            wordEl.textContent = '"' + cats[catIndex] + '"';

            wordEl.classList.remove('st-slide-out');
            wordEl.classList.add('st-slide-in');

            timer = setTimeout(switchWord, 1700);
        }, 260);
    }

    timer = setTimeout(switchWord, 1700);
})();
</script>
</body>
</html>

<?php
/**
 * Dynamic Feature Entitlement Matrix per Client
 * Enables/disables specific features and modules for an individual business.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/admin_db.php';
require_once __DIR__ . '/../includes/features.php';

$adminUser = require_super_admin();

$businessId = (int)($_GET['id'] ?? 0);
if ($businessId <= 0) {
    set_flash('error', 'Select a client to manage features.');
    redirect(APP_URL . '/admin/clients.php');
}

$client = admin_get_client_by_id($businessId);
if (!$client) {
    set_flash('error', 'Client store not found.');
    redirect(APP_URL . '/admin/clients.php');
}

$pageTitle = 'Features Matrix: ' . $client['name'];

// Handle bulk form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_features') {
    $submittedFeatures = $_POST['features'] ?? [];
    $allFeatures = get_all_system_features();
    $featuresMap = [];
    foreach ($allFeatures as $f) {
        $k = $f['feature_key'];
        $featuresMap[$k] = !empty($submittedFeatures[$k]);
    }
    save_business_features($businessId, $featuresMap);
    set_flash('success', 'Features updated successfully for ' . $client['name']);
    redirect(APP_URL . '/admin/client-features.php?id=' . $businessId);
}

// Fetch features map
$featuresList = get_business_features_map($businessId);

// Group by category
$categories = [];
foreach ($featuresList as $feat) {
    $cat = $feat['category'] ?? 'General';
    $categories[$cat][] = $feat;
}

$enabledCount = 0;
foreach ($featuresList as $f) {
    if (!empty($f['is_enabled'])) {
        $enabledCount++;
    }
}

require_once __DIR__ . '/includes/admin_header.php';
?>

<!-- Client Header Overview Bar -->
<div class="admin-card">
    <div style="padding: 20px 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <h2 style="font-size: 18px; font-weight: 700; color: #0f172a;">
                    <?= htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8') ?>
                </h2>
                <span class="status-badge <?= strtolower($client['subscription_status'] ?? 'active') ?>">
                    <span class="status-dot"></span>
                    <?= ucfirst($client['subscription_status'] ?? 'Active') ?>
                </span>
                <span style="background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 2px 8px; border-radius: 4px;">
                    Plan: <?= htmlspecialchars($client['subscription_plan'] ?? 'Pro', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                Owner: <strong><?= htmlspecialchars($client['owner_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></strong>
                &bull; Email: <span style="color: #0f172a; font-weight: 500;"><?= htmlspecialchars($client['owner_email'] ?? $client['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                &bull; Currently Allowed: <strong style="color: #0f172a;"><?= $enabledCount ?></strong> of <?= count($featuresList) ?> features
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="<?= asset('admin/clients.php') ?>" class="btn-adm btn-adm-secondary">
                &larr; Back to Clients
            </a>
            <a href="<?= asset('admin/impersonate.php?business_id=' . $businessId) ?>" class="btn-adm btn-adm-login">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                1-Click Login As Client
            </a>
        </div>
    </div>
</div>

<!-- 1-Click Feature Presets Banner -->
<div class="admin-card">
    <div style="padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; background: #fafafa;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-weight: 600; font-size: 13px; color: #475569;">Quick Feature Presets:</span>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="button" onclick="applyPreset('all')" class="btn-adm btn-adm-secondary btn-adm-sm" style="background: #fff; font-weight: 700;">
                ✨ Enable All Features
            </button>
            <button type="button" onclick="applyPreset('enterprise')" class="btn-adm btn-adm-secondary btn-adm-sm" style="background: #fff; font-weight: 600;">
                🚀 Full Enterprise
            </button>
            <button type="button" onclick="applyPreset('standard')" class="btn-adm btn-adm-secondary btn-adm-sm" style="background: #fff; font-weight: 600;">
                🏬 Standard Retail
            </button>
            <button type="button" onclick="applyPreset('basic')" class="btn-adm btn-adm-secondary btn-adm-sm" style="background: #fff; font-weight: 600;">
                🛒 Basic POS Only
            </button>
        </div>
    </div>
</div>

<!-- Feature Toggles Matrix Form -->
<form method="POST" action="<?= asset('admin/client-features.php?id=' . $businessId) ?>">
    <input type="hidden" name="action" value="save_features">

    <div style="display: flex; flex-direction: column; gap: 20px;">
        <?php foreach ($categories as $categoryName => $feats): ?>
            <div class="admin-card" style="margin-bottom: 0;">
                <div class="admin-card-header" style="background: #f8fafc;">
                    <div class="admin-card-title" style="font-size: 14px;">
                        <span style="width: 8px; height: 8px; border-radius: 50%; background: #4f46e5; display: inline-block;"></span>
                        <?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <span style="font-size: 12px; color: #64748b;">
                        <?= count($feats) ?> modules
                    </span>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; padding: 20px;">
                    <?php foreach ($feats as $f): ?>
                        <?php
                        $fKey = $f['feature_key'];
                        $isEnabled = !empty($f['is_enabled']);
                        ?>
                        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.15s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.03);" onmouseover="this.style.borderColor='#cbd5e1'" onmouseout="this.style.borderColor='#e2e8f0'">
                            <div>
                                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 8px;">
                                    <h4 style="font-size: 14px; font-weight: 700; color: #0f172a; margin: 0;">
                                        <?= htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </h4>
                                    <!-- Toggle Switch -->
                                    <label class="switch" title="Toggle feature on/off">
                                        <input 
                                            type="checkbox" 
                                            name="features[<?= $fKey ?>]" 
                                            value="1" 
                                            <?= $isEnabled ? 'checked' : '' ?>
                                            id="feat-chk-<?= $fKey ?>"
                                            onchange="toggleClientFeature(this, <?= $businessId ?>, '<?= $fKey ?>')"
                                        >
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <div style="font-size: 11px; font-family: 'JetBrains Mono', monospace; color: #6366f1; margin-bottom: 8px;">
                                    <?= htmlspecialchars($fKey, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <p style="font-size: 12px; color: #64748b; line-height: 1.45; margin: 0;">
                                    <?= htmlspecialchars($f['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>

                            <div style="margin-top: 12px; pt: 8px; border-top: 1px dashed #f1f5f9; display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 11px; color: <?= $isEnabled ? '#16a34a' : '#94a3b8' ?>; font-weight: 600;">
                                    Status: <?= $isEnabled ? 'Enabled' : 'Disabled' ?>
                                </span>
                                <?php if (!empty($f['is_customized'])): ?>
                                    <span style="font-size: 10px; background: #fef3c7; color: #92400e; padding: 2px 6px; border-radius: 4px; font-weight: 600;">
                                        Customized
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Floating Sticky Save Bar -->
    <div style="position: sticky; bottom: 20px; background: #0f172a; color: #ffffff; padding: 14px 24px; border-radius: 12px; margin-top: 24px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 100;">
        <div style="font-size: 13.5px; font-weight: 500;">
            Features toggled above take effect instantly in real-time, or you can click Save All.
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="<?= asset('admin/clients.php') ?>" class="btn-adm btn-adm-secondary">
                Done
            </a>
            <button type="submit" class="btn-adm btn-adm-primary" style="background: #10b981;">
                Save All Changes
            </button>
        </div>
    </div>
</form>

<script>
    function applyPreset(preset) {
        if (!confirm('Apply the "' + preset.toUpperCase() + '" feature preset to this client?')) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'apply_preset');
        formData.append('business_id', '<?= $businessId ?>');
        formData.append('preset', preset);

        fetch('<?= asset("admin/api.php") ?>', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAdmToast('Preset "' + preset + '" applied successfully!');
                setTimeout(() => window.location.reload(), 600);
            } else {
                showAdmToast(data.error || 'Failed to apply preset', 'error');
            }
        })
        .catch(() => {
            showAdmToast('Network error applying preset', 'error');
        });
    }
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>

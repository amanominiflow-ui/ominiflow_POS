<?php
/**
 * Master Feature Catalog Management for OminiFlow POS
 * Lists all modular system features and allows adding dynamic custom features.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/admin_db.php';
require_once __DIR__ . '/../includes/features.php';

$adminUser = require_super_admin();
$pageTitle = 'Master Feature Catalog';

$db = get_db();

// Handle adding a new dynamic feature
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_feature') {
    $fKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($_POST['feature_key'] ?? ''))));
    $fName = trim((string)($_POST['name'] ?? ''));
    $fCat = trim((string)($_POST['category'] ?? 'General'));
    $fDesc = trim((string)($_POST['description'] ?? ''));
    $fDefault = !empty($_POST['default_enabled']) ? 1 : 0;

    if ($fKey !== '' && $fName !== '') {
        try {
            $stmt = $db->prepare('
                INSERT INTO system_features (feature_key, name, category, description, default_enabled, sort_order)
                VALUES (:k, :name, :cat, :desc, :def, 99)
                ON DUPLICATE KEY UPDATE name = :name2, category = :cat2, description = :desc2, default_enabled = :def2
            ');
            $stmt->execute([
                'k' => $fKey,
                'name' => $fName,
                'cat' => $fCat,
                'desc' => $fDesc,
                'def' => $fDefault,
                'name2' => $fName,
                'cat2' => $fCat,
                'desc2' => $fDesc,
                'def2' => $fDefault,
            ]);
            set_flash('success', "Feature '{$fName}' saved to catalog.");
        } catch (PDOException $e) {
            set_flash('error', 'Could not save feature: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'Feature key and name are required.');
    }
    redirect(APP_URL . '/admin/features.php');
}

$features = get_all_system_features();
$totalClients = (int)$db->query('SELECT COUNT(*) FROM businesses')->fetchColumn();

// Compute active clients count per feature
$clientUsage = [];
try {
    $stmtUsage = $db->query('
        SELECT feature_key, COUNT(*) as enabled_count 
        FROM business_features 
        WHERE is_enabled = 1 
        GROUP BY feature_key
    ');
    while ($row = $stmtUsage->fetch(PDO::FETCH_ASSOC)) {
        $clientUsage[$row['feature_key']] = (int)$row['enabled_count'];
    }
} catch (PDOException $e) {}

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <div class="admin-card-title">
                <svg width="20" height="20" fill="none" stroke="#4f46e5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
                Master System Features Catalog
            </div>
            <p style="font-size: 12.5px; color: #64748b; margin-top: 3px;">
                Define which modules exist in OminiFlow POS. You can grant or restrict any of these features per client in the Client Directory.
            </p>
        </div>
        <button type="button" onclick="openAddFeatureModal()" class="btn-adm btn-adm-primary">
            + Define New Feature
        </button>
    </div>

    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead>
                <tr>
                    <th>Feature Name &amp; Key</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Default for New Clients</th>
                    <th>Client Entitlement</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($features as $f): ?>
                    <?php
                    $fKey = $f['feature_key'];
                    $usage = $clientUsage[$fKey] ?? ((int)$f['default_enabled'] === 1 ? $totalClients : 0);
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight: 700; color: #0f172a; font-size: 13.5px;">
                                <?= htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <span style="font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #334155; background: #f1f5f9; border: 1px solid #e2e8f0; padding: 2px 6px; border-radius: 4px;">
                                <?= htmlspecialchars($fKey, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td>
                            <span style="background: #f1f5f9; color: #475569; font-size: 11.5px; font-weight: 600; padding: 3px 8px; border-radius: 4px;">
                                <?= htmlspecialchars($f['category'] ?? 'General', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td style="max-width: 380px; color: #64748b; font-size: 12.5px; line-height: 1.45;">
                            <?= htmlspecialchars($f['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <?php if (!empty($f['default_enabled'])): ?>
                                <span class="status-badge active">
                                    <span class="status-dot"></span> Enabled by Default
                                </span>
                            <?php else: ?>
                                <span class="status-badge suspended">
                                    <span class="status-dot"></span> Locked by Default
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-size: 12.5px; font-weight: 700; color: #0f172a;">
                                <?= $usage ?> / <?= $totalClients ?> Stores
                            </div>
                            <div style="font-size: 11px; color: #64748b;">
                                <?= $totalClients > 0 ? round(($usage / $totalClients) * 100) : 0 ?>% of clients
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL: ADD DYNAMIC FEATURE -->
<div id="addFeatureModal" class="adm-modal-backdrop">
    <div class="adm-modal">
        <div class="adm-modal-header">
            <h3 class="adm-modal-title">Define New System Feature</h3>
            <button type="button" onclick="closeAddFeatureModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">&times;</button>
        </div>
        <form method="POST" action="<?= asset('admin/features.php') ?>">
            <input type="hidden" name="action" value="add_feature">
            <div class="adm-modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Feature Display Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Kitchen Display System (KDS)" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                </div>
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Unique Feature Key * (lowercase, letters and underscores)</label>
                    <input type="text" name="feature_key" required placeholder="e.g. kds_screen" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; font-family: 'JetBrains Mono', monospace;">
                </div>
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Category</label>
                    <select name="category" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                        <option value="Core POS">Core POS</option>
                        <option value="Catalog & Stock">Catalog &amp; Stock</option>
                        <option value="Sales & Billing">Sales &amp; Billing</option>
                        <option value="Purchases & Vendors">Purchases &amp; Vendors</option>
                        <option value="Sales Channels">Sales Channels</option>
                        <option value="Operations">Operations</option>
                        <option value="Analytics">Analytics</option>
                        <option value="Marketing & CRM">Marketing &amp; CRM</option>
                        <option value="Integrations">Integrations</option>
                        <option value="Advanced">Advanced</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Description</label>
                    <textarea name="description" rows="3" placeholder="Explain what module or capability this feature unlocks..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;"></textarea>
                </div>
                <div>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #334155; cursor: pointer;">
                        <input type="checkbox" name="default_enabled" value="1" checked>
                        Enable by default for new client accounts
                    </label>
                </div>
            </div>
            <div class="adm-modal-footer">
                <button type="button" onclick="closeAddFeatureModal()" class="btn-adm btn-adm-secondary">Cancel</button>
                <button type="submit" class="btn-adm btn-adm-primary">Add Feature</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddFeatureModal() {
        document.getElementById('addFeatureModal').classList.add('show');
    }
    function closeAddFeatureModal() {
        document.getElementById('addFeatureModal').classList.remove('show');
    }
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>

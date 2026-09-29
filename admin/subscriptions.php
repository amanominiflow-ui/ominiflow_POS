<?php
/**
 * Super Admin - Subscriptions Management
 * Control, enable, disable, and extend client subscriptions.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/admin_db.php';

$adminUser = require_super_admin();
$pageTitle = 'Subscription Management';

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterPlan = trim((string)($_GET['plan'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

// Fetch clients matching
$clients = admin_get_clients($search, $filterStatus, $filterPlan, 100, 0);

// Compute quick subscription stats
$db = get_db();
$activeCount = (int)$db->query("SELECT COUNT(*) FROM businesses WHERE subscription_status = 'active'")->fetchColumn();
$suspendedCount = (int)$db->query("SELECT COUNT(*) FROM businesses WHERE subscription_status IN ('suspended', 'inactive')")->fetchColumn();
$trialCount = (int)$db->query("SELECT COUNT(*) FROM businesses WHERE subscription_status = 'trial'")->fetchColumn();
$proCount = (int)$db->query("SELECT COUNT(*) FROM businesses WHERE subscription_plan = 'pro'")->fetchColumn();

require_once __DIR__ . '/includes/admin_header.php';
?>

<!-- Subscription Metric Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px;">
    <div class="admin-card" style="margin-bottom: 0; padding: 18px 20px;">
        <div style="font-size: 11.5px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Active Subscriptions</div>
        <div style="font-size: 24px; font-weight: 800; color: #0f172a; margin-top: 4px;"><?= number_format($activeCount) ?></div>
        <div style="font-size: 12px; color: #166534; font-weight: 500; margin-top: 4px;">Full POS Access Granted</div>
    </div>
    <div class="admin-card" style="margin-bottom: 0; padding: 18px 20px;">
        <div style="font-size: 11.5px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Suspended / Disabled</div>
        <div style="font-size: 24px; font-weight: 800; color: #0f172a; margin-top: 4px;"><?= number_format($suspendedCount) ?></div>
        <div style="font-size: 12px; color: #991b1b; font-weight: 500; margin-top: 4px;">Access Temporarily Blocked</div>
    </div>
    <div class="admin-card" style="margin-bottom: 0; padding: 18px 20px;">
        <div style="font-size: 11.5px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Trials Active</div>
        <div style="font-size: 24px; font-weight: 800; color: #0f172a; margin-top: 4px;"><?= number_format($trialCount) ?></div>
        <div style="font-size: 12px; color: #64748b; font-weight: 500; margin-top: 4px;">Evaluation Accounts</div>
    </div>
    <div class="admin-card" style="margin-bottom: 0; padding: 18px 20px;">
        <div style="font-size: 11.5px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Pro / Premium Tier</div>
        <div style="font-size: 24px; font-weight: 800; color: #0f172a; margin-top: 4px;"><?= number_format($proCount) ?></div>
        <div style="font-size: 12px; color: #64748b; font-weight: 500; margin-top: 4px;">Standard Subscriptions</div>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div class="admin-card-title">
            <svg width="20" height="20" fill="none" stroke="#4f46e5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Client Subscriptions &amp; Billing Access
        </div>
    </div>

    <!-- Filter Bar -->
    <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--adm-border);">
        <form method="GET" action="<?= asset('admin/subscriptions.php') ?>" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="flex: 1; min-width: 240px;">
                <input 
                    type="text" 
                    name="q" 
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" 
                    placeholder="Search by client or email..." 
                    style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;"
                >
            </div>
            <div>
                <select name="status" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; background: #fff;">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="suspended" <?= $filterStatus === 'suspended' ? 'selected' : '' ?>>Suspended Only</option>
                    <option value="trial" <?= $filterStatus === 'trial' ? 'selected' : '' ?>>Trial Only</option>
                </select>
            </div>
            <button type="submit" class="btn-adm btn-adm-secondary">
                Filter
            </button>
            <?php if ($search !== '' || $filterStatus !== ''): ?>
                <a href="<?= asset('admin/subscriptions.php') ?>" class="btn-adm" style="color: #64748b; background: transparent; text-decoration: none;">
                    Clear
                </a>
            <?php endif; ?>
        </form>
    </div>

    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead>
                <tr>
                    <th>Store / Client</th>
                    <th>Current Status</th>
                    <th>Instant Enable / Disable</th>
                    <th>Plan Tier</th>
                    <th>Validity / Expiration</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">
                            No client subscriptions found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($clients as $c): ?>
                        <?php
                        $bid = (int)$c['id'];
                        $subStatus = strtolower($c['subscription_status'] ?? 'active');
                        $isSubActive = $subStatus === 'active';
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; color: #0f172a; font-size: 14px;">
                                    <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div style="font-size: 12px; color: #64748b;">
                                    <?= htmlspecialchars($c['owner_email'] ?? $c['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>
                            <td>
                                <span id="sub-badge-<?= $bid ?>" class="status-badge <?= $subStatus ?>">
                                    <span class="status-dot"></span>
                                    <?= ucfirst($subStatus) ?>
                                </span>
                            </td>
                            <td>
                                <!-- Quick Dynamic Subscription Toggle -->
                                <label class="switch" title="Turn subscription ON (Active) or OFF (Suspended)">
                                    <input 
                                        type="checkbox" 
                                        <?= $isSubActive ? 'checked' : '' ?> 
                                        onchange="toggleClientSubscription(this, <?= $bid ?>)"
                                    >
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <span style="background: #f1f5f9; color: #334155; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 4px; border: 1px solid #e2e8f0;">
                                    <?= htmlspecialchars($c['subscription_plan'] ?? 'Pro', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($c['subscription_expires_at'])): ?>
                                    <div style="font-size: 12.5px; font-weight: 600; color: #1e293b;">
                                        <?= date('d M Y, h:i A', strtotime($c['subscription_expires_at'])) ?>
                                    </div>
                                    <?php
                                    $diff = strtotime($c['subscription_expires_at']) - time();
                                    $days = (int)ceil($diff / 86400);
                                    ?>
                                    <div style="font-size: 11px; color: <?= $days > 0 ? '#16a34a' : '#ef4444' ?>; font-weight: 600;">
                                        <?= $days > 0 ? "Expires in {$days} days" : 'Expired' ?>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: #16a34a; font-weight: 600;">Lifetime / No Expiry</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; align-items: center; gap: 8px;">
                                    <button type="button" onclick="openEditSubModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)" class="btn-adm btn-adm-secondary btn-adm-sm">
                                        Edit Details
                                    </button>
                                    <a href="<?= asset('admin/impersonate.php?business_id=' . $bid) ?>" class="btn-adm btn-adm-login btn-adm-sm">
                                        Login As Client
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Re-use Edit Sub Modal from clients.php -->
<div id="editSubModal" class="adm-modal-backdrop">
    <div class="adm-modal">
        <div class="adm-modal-header">
            <h3 class="adm-modal-title" id="editSubModalTitle">Edit Subscription</h3>
            <button type="button" onclick="closeEditSubModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">&times;</button>
        </div>
        <form method="POST" action="<?= asset('admin/clients.php') ?>">
            <input type="hidden" name="action" value="update_client_sub">
            <input type="hidden" name="business_id" id="editSubBizId">
            <div class="adm-modal-body" style="display: flex; flex-direction: column; gap: 16px;">
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Subscription Status</label>
                    <select name="subscription_status" id="editSubStatus" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                        <option value="active">Active (Access Allowed)</option>
                        <option value="suspended">Suspended (Access Disabled)</option>
                        <option value="inactive">Inactive</option>
                        <option value="trial">Trial</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Plan Tier</label>
                    <select name="subscription_plan" id="editSubPlan" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                        <option value="free">Free Trial</option>
                        <option value="basic">Basic Retail</option>
                        <option value="standard">Standard Store</option>
                        <option value="pro">Pro Merchant</option>
                        <option value="enterprise">Full Enterprise</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Expiry Date (Leave empty for Lifetime)</label>
                    <input type="datetime-local" name="subscription_expires_at" id="editSubExpires" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                </div>
            </div>
            <div class="adm-modal-footer">
                <button type="button" onclick="closeEditSubModal()" class="btn-adm btn-adm-secondary">Cancel</button>
                <button type="submit" class="btn-adm btn-adm-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditSubModal(client) {
        document.getElementById('editSubBizId').value = client.id;
        document.getElementById('editSubModalTitle').innerText = 'Edit Subscription: ' + client.name;
        document.getElementById('editSubStatus').value = client.subscription_status || 'active';
        document.getElementById('editSubPlan').value = client.subscription_plan || 'pro';
        
        if (client.subscription_expires_at) {
            const d = new Date(client.subscription_expires_at);
            const formatted = d.toISOString().slice(0, 16);
            document.getElementById('editSubExpires').value = formatted;
        } else {
            document.getElementById('editSubExpires').value = '';
        }

        document.getElementById('editSubModal').classList.add('show');
    }

    function closeEditSubModal() {
        document.getElementById('editSubModal').classList.remove('show');
    }
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>

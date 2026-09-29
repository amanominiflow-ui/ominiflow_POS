<?php
/**
 * Super Admin - Complete Clients Directory & Store Management
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/admin_db.php';

$adminUser = require_super_admin();
$pageTitle = 'Client Directory & Stores';

$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$plan = trim((string)($_GET['plan'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$totalCount = admin_count_clients($search, $status, $plan);
$totalPages = max(1, (int)ceil($totalCount / $limit));
$clients = admin_get_clients($search, $status, $plan, $limit, $offset);

// Handle manual subscription update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_client_sub') {
    $bid = (int)($_POST['business_id'] ?? 0);
    $subStatus = trim((string)($_POST['subscription_status'] ?? 'active'));
    $subPlan = trim((string)($_POST['subscription_plan'] ?? 'pro'));
    $subExpires = trim((string)($_POST['subscription_expires_at'] ?? ''));

    if ($bid > 0) {
        update_business_subscription($bid, $subStatus, $subPlan, $subExpires !== '' ? $subExpires : null);
        set_flash('success', 'Subscription updated successfully.');
        $returnTo = trim((string)($_POST['return_to'] ?? ''));
        $allowedReturns = ['admin/dashboard.php', 'admin/subscriptions.php', 'admin/clients.php'];
        if (in_array($returnTo, $allowedReturns, true)) {
            redirect(APP_URL . '/' . $returnTo);
        }
        redirect(APP_URL . '/admin/clients.php?' . http_build_query($_GET));
    }
}

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="admin-card">
    <div class="admin-card-header">
        <div class="admin-card-title">
            <svg width="20" height="20" fill="none" stroke="#4f46e5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            Clients Directory (<?= number_format($totalCount) ?> Stores)
        </div>
        <div>
            <button type="button" onclick="openNewClientModal()" class="btn-adm btn-adm-primary">
                + Add Client Store
            </button>
        </div>
    </div>

    <!-- Filters Bar -->
    <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--adm-border);">
        <form method="GET" action="<?= asset('admin/clients.php') ?>" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="flex: 1; min-width: 260px; position: relative;">
                <input 
                    type="text" 
                    name="q" 
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" 
                    placeholder="Search by store name, owner email, phone, or Org ID..." 
                    class="form-control"
                    style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;"
                >
            </div>
            <div>
                <select name="status" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; background: #fff;">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active Subscriptions</option>
                    <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    <option value="trial" <?= $status === 'trial' ? 'selected' : '' ?>>Trial</option>
                </select>
            </div>
            <div>
                <select name="plan" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; background: #fff;">
                    <option value="">All Plans</option>
                    <option value="basic" <?= $plan === 'basic' ? 'selected' : '' ?>>Basic</option>
                    <option value="standard" <?= $plan === 'standard' ? 'selected' : '' ?>>Standard</option>
                    <option value="pro" <?= $plan === 'pro' ? 'selected' : '' ?>>Pro</option>
                    <option value="enterprise" <?= $plan === 'enterprise' ? 'selected' : '' ?>>Enterprise</option>
                </select>
            </div>
            <button type="submit" class="btn-adm btn-adm-secondary">
                Filter
            </button>
            <?php if ($search !== '' || $status !== '' || $plan !== ''): ?>
                <a href="<?= asset('admin/clients.php') ?>" class="btn-adm" style="color: #64748b; background: transparent; text-decoration: none;">
                    Clear Filters
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Clients Table -->
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead>
                <tr>
                    <th>Store / Business Info</th>
                    <th>Primary Owner &amp; Contact</th>
                    <th>Subscription</th>
                    <th>Toggle</th>
                    <th>Plan &amp; Expiry</th>
                    <th>Features Matrix</th>
                    <th style="text-align: right;">1-Click Login &amp; Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 48px; color: #64748b;">
                            No clients found matching your filter criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($clients as $client): ?>
                        <?php
                        $bid = (int)$client['id'];
                        $userId = (int)($client['primary_user_id'] ?? 0);
                        $subStatus = strtolower($client['subscription_status'] ?? 'active');
                        $isSubActive = $subStatus === 'active';
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; color: #0f172a; font-size: 14px;">
                                    <?= htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div style="font-size: 11.5px; color: #64748b; margin-top: 3px;">
                                    Org ID: <span style="font-family: 'JetBrains Mono', monospace; color: #4338ca; font-weight: 600;"><?= htmlspecialchars($client['organization_id'] ?? 'ORG-' . $bid, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">
                                    Joined: <?= date('d M Y', strtotime($client['created_at'])) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #1e293b;">
                                    <?= htmlspecialchars($client['owner_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div style="font-size: 12px; color: #4f46e5; font-weight: 500;">
                                    <?= htmlspecialchars($client['owner_email'] ?? $client['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php if (!empty($client['phone'])): ?>
                                    <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                        <?= htmlspecialchars($client['phone'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span id="sub-badge-<?= $bid ?>" class="status-badge <?= $subStatus ?>">
                                    <span class="status-dot"></span>
                                    <?= ucfirst($subStatus) ?>
                                </span>
                            </td>
                            <td>
                                <!-- Quick Dynamic Subscription Toggle -->
                                <label class="switch" title="Toggle Subscription (Active / Suspended)">
                                    <input 
                                        type="checkbox" 
                                        <?= $isSubActive ? 'checked' : '' ?> 
                                        onchange="toggleClientSubscription(this, <?= $bid ?>)"
                                    >
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <div style="font-weight: 700; font-size: 12px; color: #334155; text-transform: uppercase;">
                                    <?= htmlspecialchars($client['subscription_plan'] ?? 'Pro', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php if (!empty($client['subscription_expires_at'])): ?>
                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                    Expires: <?= date('d M Y', strtotime($client['subscription_expires_at'])) ?>
                                </div>
                                <?php endif; ?>
                                <button type="button" onclick="openEditSubModal(<?= htmlspecialchars(json_encode($client), ENT_QUOTES, 'UTF-8') ?>)" style="background: none; border: none; font-size: 11px; color: #4f46e5; cursor: pointer; text-decoration: underline; padding: 0; margin-top: 3px;">
                                    Edit Plan &rarr;
                                </button>
                            </td>
                            <td>
                                <a href="<?= asset('admin/client-features.php?id=' . $bid) ?>" style="text-decoration: none;" title="Configure features for this client">
                                    <div style="display: inline-flex; align-items: center; gap: 6px; background: #eef2ff; color: #4338ca; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; border: 1px solid #c7d2fe;">
                                        <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                                        <?= (int)$client['enabled_features_count'] ?> / <?= (int)$client['total_features_count'] ?> Allowed
                                    </div>
                                </a>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; align-items: center; gap: 8px;">
                                    <a href="<?= asset('admin/client-features.php?id=' . $bid) ?>" class="btn-adm btn-adm-secondary btn-adm-sm" title="Configure Feature Access">
                                        Features
                                    </a>
                                    <a href="<?= asset('admin/impersonate.php?business_id=' . $bid) ?>" class="btn-adm btn-adm-login btn-adm-sm" title="Login directly as client with 1 click">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                                        Login as Client
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div style="padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--adm-border); background: #ffffff;">
            <div style="font-size: 13px; color: #64748b;">
                Showing <strong><?= $offset + 1 ?></strong> to <strong><?= min($totalCount, $offset + $limit) ?></strong> of <strong><?= $totalCount ?></strong> clients
            </div>
            <div style="display: flex; gap: 6px;">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="<?= asset('admin/clients.php?' . http_build_query(array_merge($_GET, ['page' => $p]))) ?>" class="btn-adm btn-adm-sm <?= $p === $page ? 'btn-adm-primary' : 'btn-adm-secondary' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL: EDIT SUBSCRIPTION -->
<div id="editSubModal" class="adm-modal-backdrop">
    <div class="adm-modal">
        <div class="adm-modal-header">
            <h3 class="adm-modal-title" id="editSubModalTitle">Edit Client Subscription</h3>
            <button type="button" onclick="closeEditSubModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">&times;</button>
        </div>
        <form method="POST" action="<?= asset('admin/clients.php') ?>">
            <input type="hidden" name="action" value="update_client_sub">
            <input type="hidden" name="business_id" id="editSubBizId">
            <div class="adm-modal-body" style="display: flex; flex-direction: column; gap: 16px;">
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Subscription Status</label>
                    <select name="subscription_status" id="editSubStatus" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                        <option value="active">Active (Full Access)</option>
                        <option value="suspended">Suspended (Access Blocked)</option>
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
